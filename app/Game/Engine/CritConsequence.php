<?php

namespace App\Game\Engine;

use App\Game\Hands;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Character;
use App\Models\Scene;
use App\Models\SceneFeature;

/**
 * What a natural 20 or a natural 1 does to the world.
 *
 * A crit that only changes an adjective is not a crit. Telling the narrator
 * "make this vivid" over a beat where nothing extraordinary happened produces
 * purple prose about an ordinary swing; the way to get a chapter that turns on
 * one moment is to hand the narrator a moment that actually turned. So every
 * crit here leaves something behind that outlives the beat: ground torn open
 * that is still there next turn, a weapon out of the character's hands and
 * lying somewhere they have to go and get, a whole room that saw them.
 *
 * The engine still decides WHETHER, and it stays scrupulously setting-neutral
 * doing it: it says the ground is torn open, never that it is a fire-filled
 * crevasse. Which of those a torn floor turns out to be belongs to the land
 * the campaign was forged in, and so it belongs to Claude.
 */
class CritConsequence
{
    /** Verbs the character throws their weight behind. */
    private const FORCE = ['strike', 'interrupt', 'hurl', 'break', 'lift'];

    private const MOVEMENT = ['ascend', 'cross', 'flee', 'ride', 'track', 'venture'];

    private const SOCIAL = ['intimidate', 'persuade', 'deceive', 'calm', 'speak', 'recruit'];

    private const STEALTH = ['hide', 'scout', 'detect'];

    private const HOLD = ['restrain', 'haul'];

    /**
     * Everything a crit costs or buys beyond the degree it forces, as facts
     * the narrator is handed. The degree itself was already settled by the
     * die face before this is called.
     *
     * @return list<string>
     */
    public static function apply(
        string $crit,
        string $verb,
        array $card,
        Character $character,
        Scene $scene,
        array &$conditions,
    ): array {
        return $crit === BeatOutcome::CRIT_SUCCESS
            ? self::triumph($verb, $card, $character, $scene, $conditions)
            : self::disaster($verb, $card, $character, $scene, $conditions);
    }

    /** @return list<string> */
    private static function triumph(string $verb, array $card, Character $character, Scene $scene, array &$conditions): array
    {
        $target = $card['target']['name'] ?? 'the moment';
        $facts = ["CRITICAL SUCCESS: {$character->name} did this better than anyone could have expected, and the scene will carry the mark of it."];

        // Whatever else it did, the follow-through leaves them set.
        $conditions['readied'] = true;

        if (in_array($verb, self::FORCE, true)) {
            $torn = self::tearGround($scene);
            $facts[] = $torn === null
                ? "The force of it went clean through {$target} and kept going — the ground beneath is wrecked where it landed."
                : "The force of it went clean through {$target} and into the ground beneath: it is torn wide open where the blow struck, and {$torn->name} will still be there long after this fight is done. Whatever lies under this ground is open to the air now.";

            return $facts;
        }

        if (in_array($verb, self::MOVEMENT, true)) {
            $conditions['elevated'] = true;
            $scene->update(['state' => array_merge($scene->state ?? [], ['elevated' => true])]);
            $facts[] = 'They came out of it moving faster than they went in, and finished somewhere that commands the whole of this place — above it, ahead of it, looking down on everyone still catching up.';

            return $facts;
        }

        if (in_array($verb, self::SOCIAL, true)) {
            // It carries past the one they aimed it at.
            $others = $scene->actors()->where('status', 'active')->where('kind', 'enemy')
                ->when(($card['target']['id'] ?? null) !== null, fn ($q) => $q->whereKeyNot($card['target']['id']))
                ->get();
            foreach ($others as $other) {
                $other->update(['tags' => array_merge($other->tags ?? [], ['shaken' => true])]);
            }
            $facts[] = $others->isEmpty()
                ? 'It landed so completely that there is nothing left to argue with.'
                : 'It carried far past the one they aimed it at — everyone else here heard it too, and none of them are steady now.';

            return $facts;
        }

        if (in_array($verb, self::STEALTH, true)) {
            // Everything the scene was keeping back comes to light at once.
            $found = [];
            foreach ($scene->features()->get() as $feature) {
                if ($feature->state['hidden'] ?? false) {
                    $feature->update(['state' => array_merge($feature->state ?? [], ['hidden' => false])]);
                    $found[] = $feature->name;
                }
            }
            foreach ($scene->actors()->where('status', 'active')->get() as $actor) {
                if ($actor->tags['lurking'] ?? false) {
                    $tags = $actor->tags;
                    unset($tags['lurking'], $tags['lurking_since']);
                    $actor->update(['tags' => $tags]);
                    $found[] = $actor->name;
                }
            }
            $facts[] = $found === []
                ? 'They read this place down to its bones — there is nothing here it has not shown them.'
                : 'Everything this place was holding back came apart at once: '.implode(', ', $found).' — all of it plain to them now, all of it in the open.';

            return $facts;
        }

        if (in_array($verb, self::HOLD, true)) {
            $actor = Actor::find($card['target']['id'] ?? 0);
            if ($actor !== null) {
                // A perfect hold is not one they wriggle out of next turn.
                $actor->update(['tags' => array_merge($actor->tags ?? [], ['pinned' => true])]);
                $facts[] = "The hold on {$actor->name} closed absolutely — no leverage, no angle, nothing left to fight with. They are not getting out of it on their own.";
            }

            return $facts;
        }

        $facts[] = 'It went perfectly, and left them standing better than they started.';

        return $facts;
    }

    /** @return list<string> */
    private static function disaster(string $verb, array $card, Character $character, Scene $scene, array &$conditions): array
    {
        $target = $card['target']['name'] ?? 'the moment';
        $facts = ["CRITICAL FAILURE: it came apart in {$character->name}'s hands, and it cost them something they will feel for the rest of this."];

        // The fumble spends whatever edge they had banked.
        $lost = [];
        foreach (['elevated' => 'the high ground', 'concealed' => 'their cover', 'readied' => 'their set stance'] as $flag => $label) {
            if ($conditions[$flag]) {
                $conditions[$flag] = false;
                $lost[] = $label;
            }
        }
        if ($lost !== []) {
            // The height is scene state, not just a condition flag — losing it
            // has to be true of the ground, or the next turn still cards them
            // as though they were above the fight.
            if (in_array('the high ground', $lost, true)) {
                $scene->update(['state' => array_merge($scene->state ?? [], ['elevated' => false])]);
            }
            $facts[] = 'In the scramble they lost '.implode(' and ', $lost).'.';
        }

        if (in_array($verb, self::FORCE, true) || in_array($verb, self::HOLD, true)) {
            Meters::damage($character, 1);
            $facts[] = "The whole force of the attempt on {$target} turned back on them (1 damage).";

            // Scene matter goes first, and it simply goes: a crate juggled
            // through a fumble this bad hits the floor. Unlike a weapon it
            // leaves nothing to go back for — it is ground again, wherever
            // it came down.
            $spilled = Hands::releaseAll($character);
            if ($spilled !== []) {
                $names = implode(' and ', array_column($spilled, 'name'));
                $facts[] = "Everything they were carrying went down with it — {$names} hit the ground and stayed there.";
            }

            // The signature fumble: the thing in their hands is not in their
            // hands any more, and it took its powers with it.
            $dropped = self::dropWeapon($character, $scene);
            if ($dropped !== null) {
                $facts[] = "Worse: {$dropped} was torn out of their grip and went somewhere they cannot reach from here. They are without it until they go and get it back — and everything it was giving them is gone with it.";
            }

            return $facts;
        }

        if (in_array($verb, self::MOVEMENT, true)) {
            Meters::damage($character, 1);
            $facts[] = 'They lost the ground entirely and came down hard, badly placed and out of position (1 damage).';

            return $facts;
        }

        if (in_array($verb, self::STEALTH, true)) {
            // Not merely a failure to hide: a failure that announced itself.
            $seen = $scene->actors()->where('status', 'active')->where('kind', 'enemy')->get();
            $conditions['concealed'] = false;
            foreach ($seen as $enemy) {
                $enemy->update(['tags' => array_merge($enemy->tags ?? [], ['angle' => true])]);
            }
            $facts[] = $seen->isEmpty()
                ? 'They gave themselves away completely — to an empty room, this time.'
                : 'They gave themselves away in the worst possible way: every one of them knows exactly where they are now, and all of them have the better position for it.';

            return $facts;
        }

        if (in_array($verb, self::SOCIAL, true)) {
            $actor = Actor::find($card['target']['id'] ?? 0);
            if ($actor !== null) {
                $actor->update(['tags' => array_merge($actor->tags ?? [], ['disposition' => 'hostile'])]);
                $facts[] = "The words did not merely fail — they landed wrong, and made an enemy of {$actor->name} where there might not have been one.";
            } else {
                $facts[] = 'The words landed wrong and made things worse than saying nothing would have.';
            }

            return $facts;
        }

        $facts[] = "Whatever they were reaching for with {$target}, they ended up further from it than when they started.";

        return $facts;
    }

    /**
     * Ground torn open by a critical blow. It is a real feature from here on:
     * it survives the turn, shows up on later cards, and gives anyone the
     * sense to use it somewhere to put a body.
     */
    private static function tearGround(Scene $scene): ?SceneFeature
    {
        // One scene does not need three of these.
        $existing = $scene->features()->where('feature_type', 'crit_breach')->first();
        if ($existing !== null) {
            return null;
        }

        return SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'the ground torn open',
            'feature_type' => 'crit_breach',
            // Neutral affordances only: what a hole in the ground offers is
            // the same everywhere. What KIND of hole it is belongs to the land.
            'affordances' => ['hideable' => true, 'max_size' => 'medium', 'breakable' => 3],
            'state' => [],
            'source' => 'crit',
        ]);
    }

    /**
     * The weapon leaves their hands. Unequipping is not cosmetic — an item's
     * granted capabilities go with it, so the cards genuinely narrow until
     * they go and pick it up, and the loss is felt in the form rather than
     * only described in the prose.
     */
    private static function dropWeapon(Character $character, Scene $scene): ?string
    {
        $equipped = $character->items()->wherePivot('equipped', true)->get();
        if ($equipped->isEmpty()) {
            return null;
        }

        // Prefer something that was actually doing work for them.
        $item = $equipped->first(fn ($i) => ! empty($i->grants)) ?? $equipped->first();

        $character->items()->updateExistingPivot($item->id, ['equipped' => false]);
        $character->load('items');

        SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => "{$item->name}, where it fell",
            'feature_type' => 'dropped_item',
            'affordances' => ['dropped_item' => ['id' => $item->id, 'name' => $item->name]],
            'state' => [],
            'source' => 'crit',
        ]);

        return $item->name;
    }
}
