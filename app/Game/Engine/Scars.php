<?php

namespace App\Game\Engine;

use App\Game\ScarCatalog;
use App\Models\Actor;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\CharacterConstraint;
use App\Models\Scene;
use App\Models\Turn;
use App\Services\CapabilityClamp;
use Illuminate\Support\Collection;

/**
 * Going down marks you instead of erasing you.
 *
 * Health reaching zero used to be the highest-stakes moment in the game and a
 * mechanical dead end: `Meters::damage` wrote `status = 'downed'` and nothing
 * followed. Permadeath fights the keepsake-book identity — the book is the
 * point, and a book that ends because a die came up wrong is a worse book — and
 * a consequence-free faint guts the stakes entirely. A scar is the third
 * option: you can lose something REAL and keep playing.
 *
 * The split is the same one that runs the rest of the engine. This class
 * decides WHETHER the character falls, WHICH scar they take, and WHERE they
 * wake; Claude is handed the finished facts in plain words and writes the
 * waking. It never chooses or invents a scar.
 *
 * Three things this deliberately does not do:
 *  - it does not refund points (a scar is the price of falling, not a currency);
 *  - it does not touch companions (a downed companion is the existing 'downed'
 *    actor status and stays there — scars are the player character's only);
 *  - it does not put a group on the SituationBoard. The board is for the scene;
 *    a permanent mark belongs on the sheet and in the odds parts, where the
 *    player meets it at the moment it is charging them something.
 */
class Scars
{
    /** Where the waking happens when nobody was left to move them. */
    public const LEFT_WHERE_THEY_FELL = 'left_where_they_fell';

    /** Where the waking happens when a companion was still standing. */
    public const DRAGGED_CLEAR = 'dragged_clear';

    /** The scar rows on a sheet, oldest first. @return list<CharacterConstraint> */
    public static function carried(Character $character): array
    {
        return $character->constraints()
            ->where('source', ScarCatalog::SOURCE)
            ->orderBy('id')->get()
            ->filter(fn (CharacterConstraint $c) => ScarCatalog::isScar($c->name))
            ->values()->all();
    }

    /**
     * Every constraint name the odds ladder should price this body against.
     * Read by the composer and the resolver alike, and handed to Odds as one
     * more live condition — so the card's forecast and the die it is measured
     * against are looking at the same body.
     *
     * Deliberately blind to `source`. A burden prices by its NAME: a limp
     * carried in from creation and a limp taken on the floor of a warehouse
     * cost exactly the same, because how you came by a thing is a story fact
     * and story facts never move numbers.
     *
     * @return list<string>
     */
    public static function names(Character $character): array
    {
        return $character->constraints()->orderBy('id')->pluck('name')
            ->filter(fn (string $name) => ScarCatalog::isScar($name))
            ->values()->all();
    }

    public static function count(Character $character): int
    {
        return count(self::carried($character));
    }

    /**
     * The scars as the screen and the book say them: the catalog's own label
     * and description, plus the chapter this one happened in so the book can
     * cite it.
     *
     * @return list<array{key:string,label:string,description:string,fact:string,chapter_id:?int}>
     */
    public static function marks(Character $character): array
    {
        $marks = [];

        foreach (self::carried($character) as $constraint) {
            $entry = ScarCatalog::get($constraint->name);
            if ($entry === null) {
                continue;
            }
            $marks[] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'description' => $entry['description'],
                'fact' => $entry['fact'],
                'chapter_id' => $constraint->params['chapter_id'] ?? null,
            ];
        }

        return $marks;
    }

    /** The scars as one plain line per mark, for a prompt that needs them listed. */
    public static function promptList(?Character $character): string
    {
        if ($character === null) {
            return '';
        }

        return collect(self::marks($character))
            ->map(fn (array $m) => "- {$m['label']}: {$m['fact']}")
            ->join("\n");
    }

    /**
     * How this fall happened, in the closed vocabulary the scar table is keyed
     * to. Read entirely from what the resolver already knows.
     *
     * The order is the resolution's own: the player's beats run first and the
     * scene answers afterwards, and the scene does not answer at all once the
     * character is off their feet. So a fall with an enemy blow that drew blood
     * in it was finished by that blow, and one without was finished by whatever
     * the player's last beat cost them.
     *
     * @param  list<BeatOutcome>  $outcomes
     * @param  list<array>  $reactionRolls
     */
    public static function contextFor(array $outcomes, array $reactionRolls): string
    {
        $finishing = null;
        foreach ($reactionRolls as $roll) {
            if (($roll['kind'] ?? null) === 'enemy' && str_contains((string) ($roll['outcome'] ?? ''), 'Drew blood')) {
                $finishing = $roll;
            }
        }

        if ($finishing !== null) {
            $unseen = collect($finishing['bonus_parts'] ?? [])
                ->contains(fn (array $part) => ($part['label'] ?? '') === 'Out of nowhere');

            return match (true) {
                $unseen => 'ambushed',
                ($finishing['label'] ?? null) === 'The heavy blow' => 'crushed',
                default => 'struck_down',
            };
        }

        $last = collect($outcomes)
            ->reject(fn (BeatOutcome $o) => $o->skipped)
            ->last();

        return match ($last?->verb) {
            'cross', 'flee', 'venture', 'ride', 'ascend' => 'fall',
            'restrain', 'haul', 'hurl', 'grapple', 'break', 'lift' => 'overwhelmed',
            default => 'struck_down',
        };
    }

    /**
     * The fall, resolved.
     *
     * Rolls the scar, appends it through the ordinary constraint path, re-runs
     * the capability clamp so the sheet's own re-coupling still holds, half-heals
     * them off the floor, and moves them to safe adjacent ground — because the
     * waking is a recovery BEAT, not a skipped scene. On the fall past the cap
     * none of that happens: the tale is over, and the caller closes the book.
     *
     * @param  list<BeatOutcome>  $outcomes
     * @param  list<array>  $reactionRolls
     * @return array{scene:?Scene, record:array}
     */
    public static function takeFall(
        Character $character,
        Scene $scene,
        Turn $turn,
        Dice $dice,
        array $outcomes,
        array $reactionRolls,
        SceneDresser $dresser,
    ): array {
        $context = self::contextFor($outcomes, $reactionRolls);
        // What the roll must not repeat is every priced burden on the sheet;
        // what counts toward the cap is only what the FALLS put there.
        $carried = self::names($character);
        $fellAt = $scene->title;

        // The fall past the cap. Two scars is where the stakes stay real
        // without the third fall becoming inevitable-and-miserable under the
        // weight of the first two — so it is not a third burden, it is the end.
        if (self::count($character) >= (int) config('game.scars.max_before_end', 2)) {
            return ['scene' => null, 'record' => [
                'final' => true,
                'context' => $context,
                'scar' => null,
                'outcome' => null,
                'fell_at' => $fellAt,
                'woke_at' => null,
                'facts' => [
                    "They went down at {$fellAt}, carrying everything the tale had already taken out of them.",
                    'This time they did not come round.',
                ],
            ]];
        }

        $scar = ScarCatalog::roll($context, $carried, $dice);

        // Nothing left to take. The table is larger than the cap, so this is
        // unreachable by arithmetic — but a fall must never silently do nothing.
        if ($scar === null) {
            return ['scene' => null, 'record' => [
                'final' => true,
                'context' => $context,
                'scar' => null,
                'outcome' => null,
                'fell_at' => $fellAt,
                'woke_at' => null,
                'facts' => ["They went down at {$fellAt} and did not come round."],
            ]];
        }

        self::append($character, $scar, $turn, $scene);
        self::recouple($character);

        // Never respawn-at-full. The fall has to still be there in the body
        // when they open their eyes, or the whole beat costs nothing.
        $meters = $character->meters;
        $fraction = (float) config('game.scars.wake_health_fraction', 0.5);
        $meters['health']['current'] = max(1, (int) round($meters['health']['max'] * $fraction));
        $character->forceFill(['meters' => $meters, 'status' => 'alive'])->save();

        [$woke, $outcome, $outcomeFact] = self::wake($character, $scene, $dice, $dresser);

        return ['scene' => $woke, 'record' => [
            'final' => false,
            'context' => $context,
            'scar' => ['key' => $scar['key'], 'label' => $scar['label'], 'fact' => $scar['fact']],
            'outcome' => $outcome,
            'fell_at' => $fellAt,
            'woke_at' => $woke->title,
            'facts' => [
                "They went down at {$fellAt} and did not get up.",
                $outcomeFact,
                "They came round at {$woke->title}, hurt and on their feet.",
                "What it cost them does not heal: {$scar['fact']}",
            ],
        ]];
    }

    /**
     * The scar, written onto the sheet through the path every other burden
     * uses — a constraint row, with a provenance stamp so the book can cite the
     * chapter it happened in. No refund is applied anywhere: that is the whole
     * difference between a burden bought at creation and one taken in play.
     */
    private static function append(Character $character, array $scar, Turn $turn, Scene $scene): void
    {
        $character->constraints()->create([
            'name' => $scar['key'],
            'params' => array_merge($scar['params'] ?? [], [
                'scar' => $scar['key'],
                'turn_id' => $turn->id,
                'chapter_id' => Chapter::where('campaign_id', $scene->campaign_id)->max('id'),
                'taken_at' => $scene->title,
            ]),
            'coupled_capability' => null,
            'source' => ScarCatalog::SOURCE,
        ]);
    }

    /**
     * Re-run the clamp over the sheet as it now stands. A scar changes nothing
     * about a magnitude, so in the ordinary case this finds the couplings that
     * are already there and writes nothing — which is exactly the point: the
     * sheet leaves a fall having been checked against the same bounds every
     * other change to it is checked against.
     */
    private static function recouple(Character $character): void
    {
        $proposed = $character->capabilities()->get()
            ->map(fn ($c) => $c->only(['capability', 'magnitude', 'grade', 'scope']))
            ->all();

        foreach (app(CapabilityClamp::class)->clamp($proposed)['constraints'] as $constraint) {
            $character->constraints()->firstOrCreate(
                ['name' => $constraint['name']],
                $constraint + ['source' => 'growth'],
            );
        }
    }

    /**
     * Safe adjacent ground, and how they got there.
     *
     * Two fixed outcomes, engine-picked — the enemies took what they wanted and
     * moved on, or a companion who was still standing dragged them clear. The
     * ground itself is dressed thin and carries nobody: waking into a second
     * fight is not a recovery beat, it is the same fight with the player two
     * scars down.
     *
     * @return array{0:Scene, 1:string, 2:string}
     */
    private static function wake(Character $character, Scene $scene, Dice $dice, SceneDresser $dresser): array
    {
        $companions = $scene->actors()->where('kind', 'companion')->where('status', 'active')->get();
        $outcome = $companions->isNotEmpty() ? self::DRAGGED_CLEAR : self::LEFT_WHERE_THEY_FELL;

        // Whoever was on their feet decides how the waking is told; whoever is
        // on the floor comes along regardless. A downed companion left behind
        // in the scene they fell in would simply never be heard of again, which
        // is the one thing losing somebody must never be quiet about — the
        // exit roll that decides their fate happens at the NEXT transition.
        $carried = $scene->actors()->where('kind', 'companion')
            ->whereIn('status', ['active', 'downed'])->get();

        $locale = $dresser->locale($scene->zone, $dice, exclude: $scene->title);

        // Adjacent ground on the map too: one step off where they fell, in
        // whatever direction the dice say they were carried or crawled.
        $direction = Compass::DIRECTIONS[$dice->between(0, 3)];
        [$dx, $dy] = Compass::offset($direction);

        $woke = Scene::create([
            'campaign_id' => $scene->campaign_id,
            'zone_id' => $scene->zone_id,
            'title' => $locale['title'],
            'description' => $locale['description'],
            'status' => 'active',
            'state' => ['dressed' => true],
            'from_scene_id' => $scene->id,
            'from_direction' => $direction,
            'grid_x' => $scene->grid_x + $dx,
            'grid_y' => $scene->grid_y + $dy,
        ]);

        $scene->update(['status' => 'past']);

        // Thin, and empty of people. The alarm clock and the wandering-threat
        // roll are how company shows up again — not the moment they wake.
        $dresser->instantiateFeatures($woke, $dice, 1, 2, $character);

        foreach ($carried as $companion) {
            $companion->update(['scene_id' => $woke->id]);
        }

        $dresser->rollAmbient($woke, $dice);
        $dresser->mintExits($woke, $scene->zone, $dice);

        $fact = $outcome === self::DRAGGED_CLEAR
            ? self::helperName($companions).' got them out of it and away, and stayed with them until they woke.'
            : 'Whatever put them down took what it came for and moved on, and left them lying where they fell.';

        return [$woke, $outcome, $fact];
    }

    /** @param  Collection<int, Actor>  $companions */
    private static function helperName($companions): string
    {
        return $companions->count() === 1
            ? $companions->first()->name
            : $companions->pluck('name')->join(' and ');
    }

    /**
     * The narrator's block for a fall. Plain facts, in order: where they went
     * down, what happened to them while they were out, where they woke, and
     * what the fall permanently cost — with the standing rule that the mark is
     * theirs for the rest of the tale.
     *
     * Empty string when nobody fell, so an ordinary chapter carries no
     * instructions about a body that is fine.
     */
    public static function narratorBlock(Turn $turn): string
    {
        $fall = $turn->resolution['fall'] ?? null;
        if ($fall === null) {
            return '';
        }

        $facts = collect($fall['facts'] ?? [])->map(fn (string $f) => "- {$f}")->join("\n");

        if ($fall['final'] ?? false) {
            return <<<FALL

            ## The end of this tale (fixed fact — this is what the chapter is about)
            {$facts}

            The character does not get up from this one. Write the chapter as the last thing that happened to them: end it where they lie, close and quiet, without a rescue arriving and without promising one. Do not name it as an ending or address the reader — just stop where the tale stops.

            FALL;
        }

        return <<<FALL

        ## The fall and the waking (fixed facts — this is what the chapter is about)
        {$facts}

        Write the going-down and the coming-round both. The stretch in between is theirs to fill: they were not conscious for it, so tell it from the edges — what was the last thing, and what was the first thing after. The mark it left is permanent and real, not a wound that gets better; later chapters are free to name it whenever it costs them something. Do not invent what else was taken, done, or decided while they were out.

        FALL;
    }

    /**
     * What the body already carries, for the chapters after the one it happened
     * in. Held short and told not to dwell: a mark that gets a paragraph every
     * chapter stops being a scar and becomes the subject.
     */
    public static function marksBlock(?Character $character): string
    {
        $listed = self::promptList($character);

        if ($listed === '') {
            return '';
        }

        return <<<MARKS

        ## What this body carries out of earlier chapters (fixed facts)
        {$listed}
        These are permanent and already known to the reader. Name one only where it changes what the character can do in this chapter — never as description for its own sake, and never more than once.

        MARKS;
    }
}
