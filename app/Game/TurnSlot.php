<?php

namespace App\Game;

/**
 * A submission stages up to three beats: pre sets up main, post responds
 * to it. Only main is required. Slots resolve in order as a conditional
 * chain with legality-driven abort.
 */
enum TurnSlot: string
{
    case Pre = 'pre';
    case Main = 'main';
    case Post = 'post';

    public function required(): bool
    {
        return $this === self::Main;
    }
}
