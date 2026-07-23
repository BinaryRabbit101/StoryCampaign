<?php

namespace App\Game;

/**
 * A submission stages up to three beats: pre sets up main, post responds
 * to it. Only main is required. Slots resolve in order as a conditional
 * chain with legality-driven abort.
 *
 * Companion is a parallel slot, not part of the player's chain: each
 * companion may be handed one request per turn, resolved between pre and
 * main so support (block, flank) shapes the act it supports. Companion
 * requests never consume the player's own three slots.
 */
enum TurnSlot: string
{
    case Pre = 'pre';
    case Main = 'main';
    case Post = 'post';
    case Companion = 'companion';

    public function required(): bool
    {
        return $this === self::Main;
    }

    /** The player's own chain, in resolution order. */
    public static function playerSlots(): array
    {
        return [self::Pre, self::Main, self::Post];
    }
}
