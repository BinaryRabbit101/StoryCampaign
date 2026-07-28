<?php

namespace App\Game\Engine;

use App\Game\Hands;
use App\Models\Actor;
use App\Models\Character;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use Closure;
use Illuminate\Support\Collection;

/**
 * The world moves when you don't.
 *
 * A player who believes the fiction's own telegraph — the crew with a bad
 * feeling, the door nobody has opened yet — waits, and nothing happens. They
 * wait again, and nothing happens again. That was literally true: `wait` writes
 * nothing, the alarm clock only runs while somebody is swinging, and the one
 * thing that could reach a passive out-of-combat turn was a one-in-twenty
 * wandering draw. A mutual standoff is the deadest choice in the game, because
 * it punishes reading the scene correctly.
 *
 * Pressure makes ceding the initiative mean something. A stall counter on the
 * scene fills while the turn casts no die, the player stays put, and nobody is
 * fighting; at the threshold the world SPENDS the initiative it was handed.
 * Waiting stops being the null action and becomes the "your move" action.
 *
 * Two disciplines hold it in shape:
 *  - it is COMBAT-SILENT. The alarm clock owns escalation while a fight is on;
 *    a second escalator on the same fight is difficulty creep in a costume.
 *  - it buys no new economy. Every beat below routes through machinery that
 *    already existed — the forced wandering draw, the engine's own reveal of
 *    what is hidden, Grudges::maybeReturn with every clamp intact, a feature's
 *    destroyed state, an actor's status. Nothing here mints a resource, and
 *    Claude is handed finished facts and writes the scene acting on its own.
 *
 * And it is never an ambush: the situation board carries a line from the first
 * tick, and the wait card says outright when one more wait would break the
 * stillness. Being able to read that is what makes the second wait a choice.
 *
 * This is NOT the endeavor-clock system. A clock is a promise the PLAYER made,
 * ticking toward a deadline; pressure is the world's impatience with stillness.
 * When clocks arrive the two coexist — do not fold one into the other.
 */
class Pressure
{
    /** Somebody walks in, in the open. The point is that it is SEEN to happen. */
    public const ARRIVAL = 'arrival';

    /** The ground gives up what it was keeping, or the watcher stops watching. */
    public const REVEAL = 'reveal';

    /** An old score picks this moment to pick the moment. */
    public const GRUDGE = 'grudge';

    /** The accident: something here comes apart, and it may catch somebody. */
    public const MISHAP = 'mishap';

    /** @var list<string> */
    public const KEYS = [self::ARRIVAL, self::REVEAL, self::GRUDGE, self::MISHAP];

    /** What the counter stands at. A scene from before pressure existed reads as nothing. */
    public static function of(?Scene $scene): int
    {
        return max(0, (int) ($scene?->state['stall'] ?? 0));
    }

    /**
     * The counter, moved, and written back to the scene.
     *
     * A quiet turn adds; anything else — a die cast, a step taken, a fight in
     * progress — puts it back to nothing, because the world's patience is with
     * STILLNESS and nothing else. It is clamped at the threshold so a pool that
     * had nothing to offer cannot leave a debt that fires twice later.
     *
     * @param  bool  $quiet  No die cast, nobody moved, nothing standing in the open.
     * @param  bool  $waited  The main beat was `wait` — an explicit invitation,
     *                        and it out-paces idle poking on purpose.
     */
    public static function tick(Scene $scene, bool $quiet, bool $waited): int
    {
        $stall = $quiet
            ? min(self::threshold(), self::of($scene) + self::weight($waited))
            : 0;

        self::write($scene, $stall);

        return $stall;
    }

    /** Has the stillness gone as far as it goes? */
    public static function armed(int $stall): bool
    {
        return $stall >= self::threshold();
    }

    /** Spent: the counter goes back to nothing, and only once a beat actually landed. */
    public static function spend(Scene $scene): void
    {
        self::write($scene, 0);
    }

    /**
     * One beat, drawn from the closed table and executed through machinery that
     * already existed.
     *
     * The pool is filtered by applicability BEFORE it is weighted. A beat that
     * could not cost or change anything here is not a beat, it is a blank — the
     * free-lunch rule inverted — and a blank is how a player learns that waiting
     * was a lie after all. When nothing qualifies the caller is told so (null)
     * and the counter holds at the threshold: the world waits for a turn where
     * it has something real to spend, and never invents one. Inventing ground is
     * the forge's job.
     *
     * @param  Character  $character  Read for exactly one thing: what is in their
     *                                hands, so a mishap cannot reach in and wreck
     *                                it. Nothing here ever touches their body.
     * @param  Closure():?Actor  $arrive  The resolver's own wandering-threat draw,
     *                                    forced. Handed in rather than copied —
     *                                    a second spawn path is a second set of
     *                                    template rules to drift apart.
     * @return array{key:string,facts:list<string>,arrival:?Actor}|null
     */
    public static function fire(Scene $scene, Character $character, Turn $turn, Dice $dice, Closure $arrive): ?array
    {
        $pool = [];
        foreach (self::weights() as $key => $weight) {
            if (self::applies($key, $scene, $character)) {
                $pool[$key] = $weight;
            }
        }

        if ($pool === []) {
            return null;
        }

        $key = self::pick($pool, $dice);

        $beat = match ($key) {
            self::ARRIVAL => self::arrival($arrive),
            self::REVEAL => self::reveal($scene, $dice),
            self::GRUDGE => self::grudge($scene, $turn, $dice),
            default => self::mishap($scene, $character, $dice),
        };

        // A beat that ran its own clamps and came back with nothing did not
        // happen. Hold the counter rather than reporting an event that isn't one.
        return $beat === null ? null : ['key' => $key, ...$beat];
    }

    /**
     * The build, said once.
     *
     * While the counter is filling but has not filled, the chapter gets a single
     * plain sentence, so a still page reads as pressure rather than as nothing
     * happening — and so the player has been told, before they commit again,
     * that this place is not going to stay quiet.
     */
    public static function omen(int $stall): ?string
    {
        return ($stall > 0 && ! self::armed($stall))
            ? 'Nothing moved here for a while, and the quiet stopped feeling like quiet.'
            : null;
    }

    /** The board's line, from the very first tick — the same shape as the alarm's. */
    public static function line(int $stall): ?string
    {
        return $stall > 0 ? 'The stillness here is wearing thin.' : null;
    }

    /**
     * Would one more wait break it?
     *
     * The wait card asks this so its description can say so in plain words.
     * Turns commit on submit, and a world beat the player could not see coming
     * is an ambush rather than a consequence of their own choice.
     */
    public static function waitWouldBreak(?Scene $scene): bool
    {
        return self::of($scene) + self::weight(waited: true) >= self::threshold();
    }

    /** Is this beat something that could honestly land here, tonight? */
    private static function applies(string $key, Scene $scene, Character $character): bool
    {
        return match ($key) {
            self::ARRIVAL => Actor::whereNull('scene_id')
                ->where('zone_id', $scene->zone_id)
                ->where('status', 'active')->exists(),
            self::REVEAL => self::hidden($scene) !== null || self::lurker($scene) !== null,
            // One old score per scene, the same clamp a transition return obeys,
            // and only scores the return machinery would already consider.
            self::GRUDGE => $scene->campaign !== null
                && ! $scene->actors()->get()->contains(fn (Actor $a) => isset($a->tags['grudge_id']))
                && Grudges::candidates($scene->campaign)->isNotEmpty(),
            self::MISHAP => self::breakables($scene, $character) !== [],
            default => false,
        };
    }

    /**
     * Somebody walks in, and is seen doing it. Never lurking: the whole point of
     * this beat is that the stillness visibly ends, and a lurker ends nothing
     * the player is allowed to know about yet.
     *
     * @param  Closure():?Actor  $arrive
     * @return array{facts:list<string>,arrival:?Actor}|null
     */
    private static function arrival(Closure $arrive): ?array
    {
        $newcomer = $arrive();

        if ($newcomer === null) {
            return null;
        }

        return [
            'facts' => ["The stillness ended from outside it: {$newcomer->name} came into this place in the open, making no attempt at quiet."],
            'arrival' => $newcomer,
        ];
    }

    /**
     * The engine exposing what it was withholding — the sanctioned reveal path,
     * an event rather than a condition, which is why the ambient rule about
     * never revealing anything is untouched by it.
     *
     * A lurker who stands up on their own gets an intent and NOT the ambush tag:
     * the drop belongs to a spring somebody actually sprang.
     *
     * @return array{facts:list<string>,arrival:?Actor}|null
     */
    private static function reveal(Scene $scene, Dice $dice): ?array
    {
        $hidden = self::hidden($scene);
        $lurker = self::lurker($scene);

        if ($lurker !== null && ($hidden === null || $dice->chance(0.5))) {
            $tags = $lurker->tags ?? [];
            unset($tags['lurking'], $tags['lurking_since']);
            $tags['intent'] = 'press';
            $lurker->update(['tags' => $tags]);

            return [
                'facts' => ["Waiting stopped paying: {$lurker->name} gave up the hiding place and stepped out where they could be seen."],
                'arrival' => null,
            ];
        }

        if ($hidden === null) {
            return null;
        }

        $hidden->update(['state' => array_merge($hidden->state ?? [], ['hidden' => false])]);

        return [
            'facts' => ["Standing still long enough paid: this place gave up {$hidden->name}, which had been here the whole time."],
            'arrival' => null,
        ];
    }

    /**
     * An old score picks its moment. Pressure adds a DOORWAY, not an economy:
     * every clamp the return already had still decides — the heat odds, the
     * chapter floor, one per scene — and a grudge that is not ready does not
     * come back because somebody stood still.
     *
     * @return array{facts:list<string>,arrival:?Actor}|null
     */
    private static function grudge(Scene $scene, Turn $turn, Dice $dice): ?array
    {
        $campaign = $scene->campaign;

        if ($campaign === null) {
            return null;
        }

        $returned = Grudges::maybeReturn($scene, $campaign, $dice, $turn);

        if ($returned === null) {
            return null;
        }

        // A wary score comes back from hiding, and hidden is hidden from the
        // narrator too. The line says the air changed and names nothing.
        if ($returned->tags['lurking'] ?? false) {
            return [
                'facts' => ['The quiet went on, and somewhere in it the quiet stopped being empty.'],
                'arrival' => null,
            ];
        }

        return [
            'facts' => ["The quiet was not empty: {$returned->name} had been out there, and picked this moment to be seen."],
            'arrival' => $returned,
        ];
    }

    /**
     * The accident. One piece of the ground goes, and a seeded coin decides
     * whether it caught anybody standing near it.
     *
     * Never the player's body — an arrival gets its swing through the ordinary
     * reaction next turn, and that is threat enough — and never a companion,
     * whose harm belongs to the bond and is decided there. What it leaves is
     * next turn's aid material.
     *
     * @return array{facts:list<string>,arrival:?Actor}|null
     */
    private static function mishap(Scene $scene, Character $character, Dice $dice): ?array
    {
        $breakables = self::breakables($scene, $character);

        if ($breakables === []) {
            return null;
        }

        $feature = $breakables[$dice->between(0, count($breakables) - 1)];
        $feature->update(['state' => array_merge($feature->state ?? [], ['destroyed' => true])]);

        $facts = ["Nobody laid a hand on {$feature->name}. It went anyway, and what is left of it is no use to anyone."];

        $bystanders = self::bystanders($scene);
        if ($bystanders->isNotEmpty() && $dice->chance(0.5)) {
            $caught = $bystanders[$dice->between(0, $bystanders->count() - 1)];
            $stats = $caught->stats;
            $stats['health']['current'] = max(0, (int) ($stats['health']['current'] ?? 1) - 1);
            $caught->update(['stats' => $stats]);

            if ($stats['health']['current'] === 0) {
                $caught->update(['status' => 'downed']);
                $facts[] = "{$caught->name} was under it when it went, and has not got up.";
            } else {
                $facts[] = "{$caught->name} was near enough to catch the worst of it, and is hurt.";
            }
        }

        return ['facts' => $facts, 'arrival' => null];
    }

    /**
     * Ground a mishap may honestly wreck: in plain sight, still whole, not part
     * of the opening the player themselves set, and not in their hands.
     *
     * @return list<SceneFeature>
     */
    private static function breakables(Scene $scene, Character $character): array
    {
        return $scene->visibleFeatures()
            ->reject(fn (SceneFeature $f) => $f->source === 'stage')
            ->reject(fn (SceneFeature $f) => Hands::isHolding($character, $f->id))
            ->values()->all();
    }

    /** Whoever is standing about who is neither fighting nor walking with them. @return Collection<int, Actor> */
    private static function bystanders(Scene $scene)
    {
        return $scene->visibleActors()
            ->reject(fn (Actor $a) => in_array($a->kind, ['enemy', 'companion'], true))
            ->values();
    }

    /** The scene's next hidden thing, read the same way scout and examine read it. */
    private static function hidden(Scene $scene): ?SceneFeature
    {
        return $scene->features()->get()->first(
            fn (SceneFeature $f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
        );
    }

    private static function lurker(Scene $scene): ?Actor
    {
        return $scene->actors()->where('status', 'active')->get()
            ->first(fn (Actor $a) => $a->tags['lurking'] ?? false);
    }

    /** @param  array<string,int>  $pool */
    private static function pick(array $pool, Dice $dice): string
    {
        $roll = $dice->between(1, array_sum($pool));

        foreach ($pool as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return (string) array_key_first($pool);
    }

    /** The table's own order, so the weighted draw is the same on every machine. @return array<string,int> */
    private static function weights(): array
    {
        $weights = [];

        foreach (self::KEYS as $key) {
            $weight = (int) config("game.pressure.beats.{$key}", 0);
            if ($weight > 0) {
                $weights[$key] = $weight;
            }
        }

        return $weights;
    }

    private static function weight(bool $waited): int
    {
        return max(0, $waited
            ? (int) config('game.pressure.wait_weight', 2)
            : (int) config('game.pressure.quiet_weight', 1));
    }

    private static function threshold(): int
    {
        return max(1, (int) config('game.pressure.threshold', 3));
    }

    private static function write(Scene $scene, int $stall): void
    {
        $scene->update(['state' => array_merge($scene->state ?? [], ['stall' => $stall])]);
    }
}
