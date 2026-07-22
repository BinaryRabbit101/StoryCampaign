<?php

namespace App\Game;

enum CapabilityGroup: string
{
    case Traversal = 'traversal';
    case Manipulation = 'manipulation';
    case Stealth = 'stealth';
    case Social = 'social';
    case Tempo = 'tempo';

    /**
     * Slot scoping, enforced by capability type: pre = traversal/tempo/setup
     * only; post = recovery/consolidation only; the consequential roll lives
     * in main. Prevents "three main actions" in one turn.
     */
    public function allowedIn(TurnSlot $slot): bool
    {
        return match ($slot) {
            TurnSlot::Pre => in_array($this, [self::Traversal, self::Tempo, self::Stealth], true),
            TurnSlot::Main => true,
            TurnSlot::Post => false, // post uses recovery verbs, not capability cards
        };
    }
}
