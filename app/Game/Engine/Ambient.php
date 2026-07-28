<?php

namespace App\Game\Engine;

use App\Models\Scene;

/**
 * The air a scene stands in.
 *
 * Two visits to the same dressed locale used to differ only by which features
 * the draw happened to pull and who was standing there. Ambient is the cheapest
 * remaining lever on that: one condition rolled per dressed scene that re-ranks
 * cards the player already knows — concealment, throws, climbs, and trails all
 * change value under different air — without adding a single new choice.
 *
 * The vocabulary is ABSTRACT on purpose. The engine has to work on the ash
 * steppe, in the canopy town, and aboard the derelict station, so it never says
 * "rain": it says the air is violent, and the narrator decides whether that is
 * a squall off the water, a dust front, or a pressure deck venting. Same
 * discipline as the cold-forge kits — mechanics identical everywhere, only the
 * fiction moves.
 *
 * Nothing here reads the campaign's genre, drive, tech level, or land. The roll
 * is uniform; a station's "squall" being venting atmosphere is exactly the
 * point.
 *
 * The MECHANICAL half of this lives in Odds::AMBIENT — one ladder, two readers,
 * so a card's forecast and the die it is measured against can never disagree.
 * This class owns the roll, the scene's memory of it, and the words.
 */
class Ambient
{
    /** The baseline. Most scenes are this: seasoning that is always on is not seasoning. */
    public const CLEAR = 'clear';

    /** Light is low — night, dead lamps, emergency lighting, a shuttered sky. */
    public const GLOOM = 'gloom';

    /** The air is obscured — fog, dust, steam, smoke. */
    public const HAZE = 'haze';

    /** The air is violent — wind, rain, venting pressure. */
    public const SQUALL = 'squall';

    /** @var list<string> */
    public const KEYS = [self::CLEAR, self::GLOOM, self::HAZE, self::SQUALL];

    /**
     * The situation-board line. Phrased as a fact about the place and nothing
     * else: no weather word the land might not own, and no hint of the odds it
     * moves — the cards carry those, itemized, where the player can price them.
     */
    private const BOARD = [
        self::GLOOM => 'Light is low here.',
        self::HAZE => 'The air will not let you see far.',
        self::SQUALL => 'The air is moving hard against you.',
    ];

    /** The one fact the narrator is handed, to be rendered in the land's own idiom. */
    private const NARRATOR = [
        self::GLOOM => 'There is little light in this place.',
        self::HAZE => 'The air here is thick with something, and it swallows distance.',
        self::SQUALL => 'The air here is violent and moving, and it pushes at everything in it.',
    ];

    /**
     * One weighted roll, from the scene's own seeded dice. Weights are a config
     * knob (`game.ambient.weights`) rather than a constant so the world can be
     * tuned toward or away from drama without touching the ladder.
     */
    public static function roll(Dice $dice): string
    {
        $weights = [];
        foreach ((array) config('game.ambient.weights', []) as $key => $weight) {
            if (in_array($key, self::KEYS, true) && (int) $weight > 0) {
                $weights[$key] = (int) $weight;
            }
        }

        $total = array_sum($weights);
        if ($total <= 0) {
            return self::CLEAR;
        }

        $roll = $dice->between(1, $total);
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return self::CLEAR;
    }

    /**
     * What the air is here. A scene dressed before ambient existed — or one
     * that was never dressed at all — reads as clear, so every legacy campaign
     * keeps playing exactly the numbers it was playing yesterday.
     */
    public static function of(?Scene $scene): string
    {
        $state = $scene?->state ?? [];
        $key = $state['ambient'] ?? null;

        return in_array($key, self::KEYS, true) ? $key : self::CLEAR;
    }

    /** The board line, or null when the air says nothing (the empty group is then absent). */
    public static function line(string $key): ?string
    {
        return self::BOARD[$key] ?? null;
    }

    /** The narrator's fixed fact, or null when there is nothing to tell. */
    public static function fact(string $key): ?string
    {
        return self::NARRATOR[$key] ?? null;
    }
}
