<?php

namespace App\Game\Engine;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded roller — a turn re-resolved with the same seed produces the same
 * outcomes, which keeps resolution auditable and retryable.
 */
class Dice
{
    private Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    public function d20(): int
    {
        return $this->randomizer->getInt(1, 20);
    }

    public function between(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    public function chance(float $probability): bool
    {
        return $this->randomizer->getInt(1, 1000) <= (int) round($probability * 1000);
    }
}
