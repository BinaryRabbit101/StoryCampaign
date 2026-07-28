<?php

namespace App\Game\Engine;

use App\Game\Meters;
use App\Models\Actor;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Turn;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The idle wait, spent.
 *
 * This is an idle game whose idle time used to do exactly one silent thing —
 * tempo regen. Closing the app should be a move: when a turn resolves, the
 * engine offers a small closed set of stances for the stretch ahead, and the
 * chosen one pays out from the REAL elapsed minutes when the player returns.
 *
 * Everything here is engine-side and closed. The stances are a fixed list
 * composed by the engine, submitted by id and validated like a card; Claude
 * never adjudicates one, and the only thing narration ever sees is a single
 * plain sentence about how the wait passed — no numbers, no stance names.
 *
 * Optional, defaulting to none: a player who submits and closes the app gets
 * exactly the old behavior. Nothing here may gate or delay resolution — the
 * pick happens AFTER a turn resolves, on the resolved-turn screen, and is
 * read back at the top of the next resolution.
 */
class Downtime
{
    /** Sleep: the only passive health recovery in the game, and it costs vigilance. */
    public const REST = 'rest';

    /** Vigilance: no rest, but nothing slips into the scene unseen. */
    public const WATCH = 'watch';

    /** Gear: no rest, no watch — they come back set and waiting. */
    public const TEND = 'tend';

    /** Ground: no rest, no watch — they come back knowing one hidden thing. */
    public const WALK = 'walk';

    /**
     * The offer written onto a turn as it opens.
     *
     * `walk` is filtered out when the scene hides nothing: an option that can
     * do nothing is a dead choice, and dead choices are how a list of four
     * quietly becomes a list of three the player has to learn by being burned.
     *
     * @return array{offer:list<array{id:string,label:string,terms:string}>,stance:null,chosen_at:null,applied:false,payout:null}
     */
    public static function offer(Scene $scene): array
    {
        $stances = [
            [
                'id' => self::REST,
                'label' => 'Sleep it off',
                'terms' => 'One health back for every hour away, up to eight. Nothing else: whatever walks in while you sleep walks in unannounced.',
            ],
            [
                'id' => self::WATCH,
                'label' => 'Keep watch',
                'terms' => 'No rest at all — but anything that moves into this place while you are gone is standing in plain sight when you get back.',
            ],
            [
                'id' => self::TEND,
                'label' => 'Tend your gear',
                'terms' => 'No rest, no watch. You come back set and waiting: +2 on every roll of your next beats.',
            ],
        ];

        if (self::hiddenFeature($scene) !== null) {
            $stances[] = [
                'id' => self::WALK,
                'label' => 'Walk the ground',
                'terms' => 'No rest, no watch. You come back knowing one thing this place was keeping to itself.',
            ];
        }

        return [
            'offer' => $stances,
            'stance' => null,
            'chosen_at' => null,
            'applied' => false,
            'payout' => null,
        ];
    }

    /** The stance ids actually offered on this turn — the closed set a pick is checked against. */
    public static function offeredStances(?Turn $turn): array
    {
        return array_column($turn?->downtime['offer'] ?? [], 'id');
    }

    /**
     * Record the player's pick onto the open turn. The clock starts here: the
     * payout is measured from this instant, never from when the turn opened,
     * so a player who reads the chapter twice before choosing is not paid for
     * the reading.
     */
    public static function choose(Turn $turn, string $stance, ?CarbonInterface $now = null): void
    {
        $turn->update(['downtime' => array_merge($turn->downtime ?? [], [
            'stance' => $stance,
            'chosen_at' => ($now ?? now())->toIso8601String(),
            'applied' => false,
            'payout' => null,
        ])]);
    }

    /**
     * Pay the wait out, at the top of the resolution that follows it.
     *
     * Elapsed minutes are clamped both ways before anything is granted, and
     * the turn is stamped `applied` whichever way it lands, so one wait can
     * never be spent twice.
     *
     * @param  array<string,mixed>  $conditions  The live condition set for this
     *                                           resolution. Tending gear grants
     *                                           `readied` through it — the same
     *                                           condition the `ready` beat grants,
     *                                           priced once in Odds::CONDITIONS.
     * @return array{fact:?string,watchful:bool}
     */
    public static function apply(Turn $turn, Character $character, array &$conditions, ?CarbonInterface $now = null): array
    {
        $downtime = $turn->downtime ?? [];
        $stance = $downtime['stance'] ?? null;

        if ($stance === null || ($downtime['applied'] ?? false) || ($downtime['chosen_at'] ?? null) === null) {
            return ['fact' => null, 'watchful' => false];
        }

        $now ??= now();
        $elapsed = max(0, (int) floor(Carbon::parse($downtime['chosen_at'])->diffInMinutes($now)));
        $minutes = min($elapsed, (int) config('game.downtime.cap_minutes'));

        $payout = ['stance' => $stance, 'elapsed_minutes' => $elapsed, 'counted_minutes' => $minutes, 'granted' => false];
        $fact = null;
        $watchful = false;

        // Under the floor nothing accrues. A wait that short was not a wait,
        // and paying for it would make re-submitting in a loop the strongest
        // move in the game.
        if ($minutes >= (int) config('game.downtime.floor_minutes')) {
            $payout['granted'] = true;

            switch ($stance) {
                case self::REST:
                    $healed = self::rest($character, $minutes);
                    $payout['healed'] = $healed;
                    $fact = $healed > 0
                        ? 'They spent the wait asleep, and woke steadier than they lay down.'
                        : 'They spent the wait dozing, and woke no worse for it.';
                    break;

                case self::WATCH:
                    $watchful = true;
                    $payout['watching'] = true;
                    $fact = 'They spent the wait watching the dark, and nothing crossed it unseen.';
                    break;

                case self::TEND:
                    // The existing condition, not a parallel buff: a second
                    // "+2 when set" living outside the ledger is how a card's
                    // printed odds start drifting from the dice.
                    $conditions['readied'] = true;
                    $payout['readied'] = true;
                    $fact = 'They spent the wait working over what they carry, and came back to it settled.';
                    break;

                case self::WALK:
                    $found = self::walk($turn);
                    $payout['revealed'] = $found;
                    $fact = $found === null
                        ? 'They spent the wait walking the ground, and it kept whatever it still had.'
                        : "They spent the wait walking the ground, and it gave up {$found}.";
                    break;
            }
        }

        $turn->update(['downtime' => array_merge($downtime, ['applied' => true, 'payout' => $payout])]);

        return ['fact' => $fact, 'watchful' => $watchful];
    }

    /**
     * Keeping watch, cashed in: anything that entered the scene during THIS
     * resolution enters in the open instead of from hiding. Only the entry
     * path — a lurker already standing in the scene when the wait began kept
     * its hiding place, and watching from inside the same room does not undo
     * an ambush that was already laid.
     *
     * @param  list<int>  $lurkingBefore  Actor ids already lurking when the turn began.
     */
    public static function revealNewArrivals(array $lurkingBefore, Scene ...$scenes): void
    {
        foreach ($scenes as $scene) {
            $arrivals = $scene->actors()->where('status', 'active')->get()
                ->filter(fn (Actor $a) => ($a->tags['lurking'] ?? false) && ! in_array($a->id, $lurkingBefore, true));

            foreach ($arrivals as $arrival) {
                $tags = $arrival->tags;
                unset($tags['lurking'], $tags['lurking_since']);
                $arrival->update(['tags' => $tags]);
            }
        }
    }

    /** Who is already hiding here, so a watch can tell an arrival from a fixture. @return list<int> */
    public static function lurkingIds(Scene $scene): array
    {
        return $scene->actors()->get()
            ->filter(fn (Actor $a) => $a->tags['lurking'] ?? false)
            ->pluck('id')->all();
    }

    /** The widget's one-line flavor for a wait already chosen. */
    public static function flavor(string $stance): ?string
    {
        return match ($stance) {
            self::REST => 'Sleeping it off.',
            self::WATCH => 'Keeping watch.',
            self::TEND => 'Tending their gear.',
            self::WALK => 'Walking the ground.',
            default => null,
        };
    }

    /**
     * Sleep. The one passive health recovery in the game, opted into by
     * trading away the other three stances — and it never lifts anyone off
     * the floor: a character who went down stays down.
     */
    private static function rest(Character $character, int $minutes): int
    {
        if ($character->status !== 'alive') {
            return 0;
        }

        $heal = (int) floor($minutes / 60 * (float) config('game.downtime.rest_heal_per_hour'));
        if ($heal < 1) {
            return 0;
        }

        $before = (int) $character->meters['health']['current'];
        Meters::heal($character, $heal);

        return (int) $character->meters['health']['current'] - $before;
    }

    /** Walking the ground: one hidden thing, surfaced. Returns its name, or null. */
    private static function walk(Turn $turn): ?string
    {
        $scene = $turn->scene()->first();
        $hidden = $scene === null ? null : self::hiddenFeature($scene);

        if ($hidden === null) {
            return null;
        }

        $hidden->update(['state' => array_merge($hidden->state ?? [], ['hidden' => false])]);

        return $hidden->name;
    }

    /** The scene's next hidden thing, read the same way scout and examine read it. */
    private static function hiddenFeature(Scene $scene)
    {
        return $scene->features()->get()->first(
            fn ($f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
        );
    }
}
