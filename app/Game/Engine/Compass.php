<?php

namespace App\Game\Engine;

/**
 * The four headings, and nothing else about them.
 *
 * The compass is engine vocabulary in exactly the way the air and the hour
 * are: abstract words that must work on an ash steppe and aboard a derelict
 * station alike. The engine says "north"; whether the land's people steer by
 * a star, a current, or a corridor number is the narrator's translation
 * problem. A heading never changes any number anywhere — it exists so the
 * player can hold a map in their head, and later in their hand.
 */
class Compass
{
    public const DIRECTIONS = ['north', 'east', 'south', 'west'];

    public static function opposite(string $direction): string
    {
        return match ($direction) {
            'north' => 'south',
            'south' => 'north',
            'east' => 'west',
            'west' => 'east',
            default => $direction,
        };
    }

    /**
     * The heading as a map step. North is up: +y, drawn upward.
     *
     * @return array{0:int,1:int}
     */
    public static function offset(string $direction): array
    {
        return match ($direction) {
            'north' => [0, 1],
            'south' => [0, -1],
            'east' => [1, 0],
            'west' => [-1, 0],
            default => [0, 0],
        };
    }
}
