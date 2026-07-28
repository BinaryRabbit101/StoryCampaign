<?php

namespace App\Game;

use App\Models\Character;

/**
 * What is physically in the character's hands.
 *
 * Lifting a thing used to mean shoving it aside and forgetting it — the beat
 * resolved, the fact was written, and the world went back to how it was. But
 * a player who heaves a crate up expects to be HOLDING a crate, and a held
 * crate is a real position: it opens throwing it and closes climbing with it,
 * and it has to be put down before either hand is free again.
 *
 * Deliberately separate from `items`. Items are owned — they follow the
 * character between tales and grant capabilities. This is scene matter, taken
 * up and set down, and it grants nothing but the fact of holding it.
 *
 * Two hands is the default body. Nothing here is a capability check: lifting
 * already asked whether they COULD, and this only tracks that they now are.
 */
class Hands
{
    public const CAPACITY = 2;

    /** Anything this heavy needs both hands; lighter things ride in one. */
    private const TWO_HANDED_WEIGHT = 60;

    /** Verbs that want a hand free. Holding a crate is not a free hand. */
    private const NEEDS_HANDS = ['strike', 'climb', 'ascend', 'break', 'restrain', 'haul', 'lift', 'cross'];

    /** @return list<array{name:string,feature_id:?int,hands:int}> */
    public static function held(Character $character): array
    {
        return array_values($character->carrying ?? []);
    }

    public static function used(Character $character): int
    {
        return array_sum(array_column(self::held($character), 'hands'));
    }

    public static function free(Character $character): int
    {
        return max(0, self::CAPACITY - self::used($character));
    }

    /** How many hands a thing of this weight occupies. */
    public static function handsFor(?int $weight): int
    {
        return ($weight ?? 0) >= self::TWO_HANDED_WEIGHT ? 2 : 1;
    }

    /**
     * Take it up. Refuses silently when there is no hand for it — the card
     * that offered this was composed against free hands, but the chain can
     * fill them between composing and resolving.
     */
    public static function take(Character $character, string $name, ?int $featureId, int $hands): bool
    {
        if (self::free($character) < $hands) {
            return false;
        }

        $carrying = self::held($character);
        $carrying[] = ['name' => $name, 'feature_id' => $featureId, 'hands' => $hands];
        $character->update(['carrying' => $carrying]);

        return true;
    }

    /** Put it down (or throw it, or lose it). Returns what left their hands. */
    public static function release(Character $character, ?int $featureId): ?array
    {
        $carrying = self::held($character);
        foreach ($carrying as $i => $entry) {
            if (($entry['feature_id'] ?? null) !== $featureId) {
                continue;
            }
            unset($carrying[$i]);
            $character->update(['carrying' => array_values($carrying)]);

            return $entry;
        }

        return null;
    }

    /** Everything falls: a fumble hard enough empties both hands at once. */
    public static function releaseAll(Character $character): array
    {
        $held = self::held($character);
        if ($held !== []) {
            $character->update(['carrying' => []]);
        }

        return $held;
    }

    public static function isHolding(Character $character, ?int $featureId): bool
    {
        return $featureId !== null
            && in_array($featureId, array_column(self::held($character), 'feature_id'), true);
    }

    /**
     * Does this verb want a hand it does not have? Full hands never forbid an
     * action — that would turn a held crate into a locked screen. They make it
     * harder, which is the tradeoff the player took on when they picked it up.
     */
    public static function encumbers(Character $character, string $verb): bool
    {
        return in_array($verb, self::NEEDS_HANDS, true) && self::free($character) < 1;
    }

    /** The phrase the situation board and the character strip both read from. */
    public static function summary(Character $character): ?string
    {
        $names = array_column(self::held($character), 'name');

        return $names === [] ? null : implode(', ', $names);
    }
}
