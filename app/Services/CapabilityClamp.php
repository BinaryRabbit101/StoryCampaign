<?php

namespace App\Services;

use App\Game\Capability;

/**
 * The engine-side limiter for character creation and growth. Claude
 * translates the player's narrative into a proposed loadout; nothing it
 * proposes escapes these clamps (nobody writes themselves reach(1000)),
 * and strong magnitudes drag coupled constraints with them.
 */
class CapabilityClamp
{
    /**
     * @param  list<array{capability:string,magnitude?:int|null,grade?:string|null,scope?:array|null}>  $proposed
     * @return array{capabilities: list<array>, constraints: list<array>}
     */
    public function clamp(array $proposed): array
    {
        $bounds = config('game.bounds.capability_magnitudes');
        $recoupling = config('game.bounds.recoupling');

        $capabilities = [];
        $constraints = [];

        foreach ($proposed as $entry) {
            $capability = Capability::tryFrom($entry['capability'] ?? '');
            if ($capability === null) {
                continue; // not in the vocabulary — silently dropped
            }

            $magnitude = $entry['magnitude'] ?? null;
            if ($capability->parameterized() && $magnitude !== null && isset($bounds[$capability->value])) {
                $magnitude = max($bounds[$capability->value]['min'], min($bounds[$capability->value]['max'], (int) $magnitude));
            }

            $capabilities[] = [
                'capability' => $capability->value,
                'magnitude' => $magnitude,
                'grade' => $entry['grade'] ?? null,
                'scope' => $entry['scope'] ?? null,
            ];

            // Open thread #2, decided: growth past a threshold re-couples a
            // liability, so late-game characters never become universal keys.
            $rule = $recoupling[$capability->value] ?? null;
            if ($rule !== null && $magnitude !== null && $magnitude >= $rule['at']) {
                $constraints[] = [
                    'name' => $rule['constraint'],
                    'params' => ['from' => $capability->value, 'at' => $magnitude],
                    'coupled_capability' => $capability->value,
                ];
            }
        }

        return ['capabilities' => $capabilities, 'constraints' => $constraints];
    }
}
