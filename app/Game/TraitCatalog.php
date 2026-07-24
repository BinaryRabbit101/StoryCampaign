<?php

namespace App\Game;

/**
 * The point-buy trait catalog: the "I can't put it into words" path through
 * character creation. Gifts (positive traits) cost points; burdens
 * (negative traits) refund them; a build must at least break even against
 * the starting allowance. The ENGINE prices everything — Claude only ever
 * writes prose around a finished sheet, never touches the numbers.
 *
 * Every gift maps onto the shared capability vocabulary and every burden
 * onto the existing constraint vocabulary (or a real mechanical debit like
 * reduced health), so a point-bought character is indistinguishable from
 * an interviewed one once play begins.
 */
class TraitCatalog
{
    public static function startingPoints(): int
    {
        return (int) config('game.creation_points', 3);
    }

    /**
     * Gifts. `group` marks mutually exclusive tiers of the same thing.
     *
     * @return array<string, array{label: string, description: string, cost: int, group?: string, grants: list<array>}>
     */
    public static function positives(): array
    {
        return [
            'climber' => ['label' => 'Climber', 'description' => 'Walls, rigging, and rooftops are all just floors at an angle.', 'cost' => 2,
                'grants' => [['capability' => 'climb']]],
            'swinger' => ['label' => 'Swing-limbed', 'description' => 'Ropes, chains, and beams carry you as fast as roads.', 'cost' => 2,
                'grants' => [['capability' => 'swing']]],
            'leaper' => ['label' => 'Leaper', 'description' => 'Short gaps are no gaps at all.', 'cost' => 1, 'group' => 'leap',
                'grants' => [['capability' => 'leap', 'magnitude' => 1]]],
            'bounding-leaper' => ['label' => 'Bounding leaper', 'description' => 'Far gaps close under a single committed spring.', 'cost' => 3, 'group' => 'leap',
                'grants' => [['capability' => 'leap', 'magnitude' => 2]]],
            'long-reach' => ['label' => 'Long reach', 'description' => 'Arms, tail, or line — you touch what others must walk to.', 'cost' => 2, 'group' => 'reach',
                'grants' => [['capability' => 'reach', 'magnitude' => 8]]],
            'prehensile-grip' => ['label' => 'Prehensile grip', 'description' => 'A reaching limb that grips like a hand, far past arm\'s length.', 'cost' => 4, 'group' => 'reach',
                'grants' => [['capability' => 'reach', 'magnitude' => 12]]],
            'strong-back' => ['label' => 'Strong back', 'description' => 'What two dockhands lift together, you lift alone.', 'cost' => 2, 'group' => 'lift',
                'grants' => [['capability' => 'lift', 'magnitude' => 120]]],
            'titan-strength' => ['label' => 'Titan strength', 'description' => 'Chains, gates, and grown men move when you decide they move.', 'cost' => 4, 'group' => 'lift',
                'grants' => [['capability' => 'lift', 'magnitude' => 220]]],
            'steady-shoulders' => ['label' => 'Steady shoulders', 'description' => 'You can carry another and still move like yourself.', 'cost' => 2,
                'grants' => [['capability' => 'carry_extra', 'magnitude' => 1]]],
            'slight-frame' => ['label' => 'Slight frame', 'description' => 'Gaps meant to stop anyone were not measured against you.', 'cost' => 3, 'group' => 'frame',
                'grants' => [['capability' => 'squeeze', 'grade' => 'small']]],
            'compact-build' => ['label' => 'Compact build', 'description' => 'Most tight ways will take you, barely.', 'cost' => 2, 'group' => 'frame',
                'grants' => [['capability' => 'squeeze', 'grade' => 'medium']]],
            'swimmer' => ['label' => 'Swimmer', 'description' => 'Deep water is a road, not a wall.', 'cost' => 1,
                'grants' => [['capability' => 'swim']]],
            'glider' => ['label' => 'Glider', 'description' => 'Height is an ally: spread, catch the air, and cross.', 'cost' => 2,
                'grants' => [['capability' => 'glide']]],
            'shadow-blend' => ['label' => 'Shadow-blend', 'description' => 'Cover takes you in and keeps the secret.', 'cost' => 2,
                'grants' => [['capability' => 'conceal']]],
            'fearsome-presence' => ['label' => 'Fearsome presence', 'description' => 'Ordinary trouble breaks and runs before you swing.', 'cost' => 3,
                'grants' => [['capability' => 'intimidate', 'scope' => ['vs' => 'regular']]]],
            'silver-tongue' => ['label' => 'Silver tongue', 'description' => 'People find themselves agreeing with you.', 'cost' => 2,
                'grants' => [['capability' => 'persuade']]],
            'honeyed-lies' => ['label' => 'Honeyed lies', 'description' => 'The untrue sounds truer from your mouth.', 'cost' => 2,
                'grants' => [['capability' => 'deceive']]],
            'calming-voice' => ['label' => 'Calming voice', 'description' => 'Panic and fury go quiet when you speak.', 'cost' => 2,
                'grants' => [['capability' => 'calm']]],
            'commanding-voice' => ['label' => 'Commanding voice', 'description' => 'Companions move sharper on your word.', 'cost' => 2,
                'grants' => [['capability' => 'command']]],
            'keen-scout' => ['label' => 'Keen scout', 'description' => 'Hidden ways and overlooked cover show themselves to you.', 'cost' => 2,
                'grants' => [['capability' => 'scout']]],
            'born-tracker' => ['label' => 'Born tracker', 'description' => 'A warm trail is as good as a signed confession.', 'cost' => 2,
                'grants' => [['capability' => 'track']]],
            'sixth-sense' => ['label' => 'Sixth sense', 'description' => 'Ambushes itch at the back of your neck before they spring.', 'cost' => 3,
                'grants' => [['capability' => 'detect']]],
            'grappler' => ['label' => 'Grappler', 'description' => 'What you seize, you keep.', 'cost' => 2,
                'grants' => [['capability' => 'restrain'], ['capability' => 'grapple']]],
            'breaker' => ['label' => 'Breaker', 'description' => 'Locked, barred, and boarded are temporary conditions.', 'cost' => 2,
                'grants' => [['capability' => 'break']]],
            'time-slow' => ['label' => 'The slowed world', 'description' => 'Now and then, the world thickens and waits for you. Spends from a charge pool.', 'cost' => 5,
                'grants' => [['capability' => 'time_slow']]],
            'quickened-blood' => ['label' => 'Quickened blood', 'description' => 'In bursts, you move before anyone can answer. Spends from a charge pool.', 'cost' => 3,
                'grants' => [['capability' => 'haste']]],
        ];
    }

    /**
     * Burdens. Refunds points; maps to the existing constraint vocabulary
     * or a real mechanical debit (health, squeeze grade).
     *
     * @return array<string, array{label: string, description: string, refund: int, group?: string, constraint?: array, grants?: list<array>, health?: int}>
     */
    public static function negatives(): array
    {
        return [
            'frail' => ['label' => 'Frail', 'description' => 'Wounds find you easily and stay long. (−2 health)', 'refund' => 2, 'group' => 'health',
                'health' => -2],
            'thin-blooded' => ['label' => 'Thin-blooded', 'description' => 'You have less to spare than most. (−1 health)', 'refund' => 1, 'group' => 'health',
                'health' => -1],
            'massive-frame' => ['label' => 'Massive frame', 'description' => 'Doors, alleys, and crawlspaces were built for smaller lives.', 'refund' => 2, 'group' => 'frame',
                'grants' => [['capability' => 'squeeze', 'grade' => 'large']]],
            'ponderous' => ['label' => 'Ponderous', 'description' => 'Nothing about you moves quickly, and everyone can tell.', 'refund' => 2,
                'constraint' => ['name' => 'ponderous', 'params' => ['pace' => 'slow']]],
            'unwieldy-limbs' => ['label' => 'Unwieldy limbs', 'description' => 'Your own reach tangles you in close quarters.', 'refund' => 1,
                'constraint' => ['name' => 'unwieldy', 'params' => ['in' => 'close quarters']]],
            'conspicuous' => ['label' => 'Conspicuous', 'description' => 'You are remembered everywhere and missed nowhere.', 'refund' => 2,
                'constraint' => ['name' => 'stealth_penalty', 'params' => ['reason' => 'unmistakable']]],
            'craven-streak' => ['label' => 'Craven streak', 'description' => 'When blood shows, some part of you is already leaving.', 'refund' => 1,
                'constraint' => ['name' => 'craven', 'params' => ['trigger' => 'first blood']]],
            'debt-shadowed' => ['label' => 'Debt-shadowed', 'description' => 'Someone, somewhere, is owed — and knows your face.', 'refund' => 1,
                'constraint' => ['name' => 'hunted', 'params' => ['by' => 'a creditor']]],
        ];
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::positives() + self::negatives();
    }

    /**
     * Points remaining after a selection: starting allowance − gift costs
     * + burden refunds. Negative means the build is overspent.
     *
     * @param  list<string>  $keys
     */
    public static function balance(array $keys): int
    {
        $all = self::all();
        $points = self::startingPoints();

        foreach ($keys as $key) {
            $points += ($all[$key]['refund'] ?? 0) - ($all[$key]['cost'] ?? 0);
        }

        return $points;
    }

    /**
     * Why a selection is invalid, or null when it is buildable: every key
     * known, no duplicates, no two traits from the same exclusive group,
     * at least one gift, and the balance at or above zero — unless the
     * player overrides the scales and steps in owing.
     *
     * @param  list<string>  $keys
     */
    public static function rejectionReason(array $keys, bool $allowOverspend = false): ?string
    {
        $all = self::all();
        $positives = self::positives();

        if ($keys !== array_unique($keys)) {
            return 'The same trait cannot be taken twice.';
        }

        $groups = [];
        foreach ($keys as $key) {
            $entry = $all[$key] ?? null;
            if ($entry === null) {
                return 'An unknown trait was offered — only the catalog builds.';
            }
            $group = $entry['group'] ?? null;
            if ($group !== null && in_array($group, $groups, true)) {
                return 'Two traits of the same kind cannot both be carried.';
            }
            $groups[] = $group;
        }

        if (array_intersect($keys, array_keys($positives)) === []) {
            return 'A character needs at least one gift.';
        }

        if (! $allowOverspend && self::balance($keys) < 0) {
            return 'The build is overspent — take fewer gifts or carry more burdens.';
        }

        return null;
    }

    /**
     * The mark left on anyone who steps into the world owing: a recorded
     * constraint carrying the shortfall. Narrative weight today; a hook
     * the world (and its evolution) is free to collect on.
     */
    public static function debtConstraint(int $shortfall): array
    {
        return [
            'name' => 'debt_to_the_world',
            'params' => ['shortfall' => $shortfall],
            'coupled_capability' => null,
        ];
    }

    /**
     * The price of one capability entry — the same scale the catalog's
     * gifts are built on, applicable to ANY sheet (so interview-translated
     * characters pay the same coin as point-bought ones). A large squeeze
     * grade prices negative: a big body is a burden, whoever wrote it.
     */
    public static function capabilityCost(array $entry): int
    {
        $magnitude = (int) ($entry['magnitude'] ?? 0);

        return match ($entry['capability'] ?? '') {
            'swim', 'quiet_move', 'grapple', 'pull', 'throw', 'descend', 'delay', 'ready' => 1,
            'leap' => $magnitude >= 2 ? 3 : 1,
            'reach' => $magnitude > 8 ? 4 : 2,
            'lift' => $magnitude > 120 ? 4 : 2,
            'squeeze' => match ($entry['grade'] ?? 'medium') {
                'small' => 3,
                'large' => -2,
                default => 2,
            },
            'intimidate', 'detect' => 3,
            'haste' => 3,
            'time_slow' => 5,
            default => 2,
        };
    }

    /** What a real constraint pays back toward the budget. */
    public static function constraintRefund(array $constraint): int
    {
        return match ($constraint['name'] ?? '') {
            'ponderous', 'stealth_penalty' => 2,
            default => 1,
        };
    }

    /**
     * Points remaining for an arbitrary sheet (interview-translated or
     * otherwise): allowance − capability costs + constraint refunds.
     * Negative means the world refuses the bargain.
     *
     * @param  list<array>  $capabilities
     * @param  list<array>  $constraints
     */
    public static function sheetBalance(array $capabilities, array $constraints): int
    {
        $points = self::startingPoints();

        foreach ($capabilities as $entry) {
            $points -= self::capabilityCost($entry);
        }
        foreach ($constraints as $constraint) {
            $points += self::constraintRefund($constraint);
        }

        return $points;
    }

    /** A compact price list for the interviewer's prompt. */
    public static function priceSheetForPrompt(): string
    {
        return 'Costs: time_slow 5; reach>8 or lift>120 4; intimidate, detect, haste, leap(2+), squeeze(small) 3; '
            .'most capabilities 2; swim, grapple, pull, throw, quiet_move, descend, delay, ready, leap(1) 1; '
            .'squeeze(large) −2 (a big body pays back). '
            .'Refunds: ponderous, stealth_penalty 2; any other real constraint 1.';
    }

    /**
     * Compile a valid selection into the engine's own terms: capability
     * rows, constraint rows, a health delta, and the chosen labels for the
     * prose that will be written around them.
     *
     * @param  list<string>  $keys
     * @return array{capabilities: list<array>, constraints: list<array>, health: int, gifts: list<string>, burdens: list<string>}
     */
    public static function compile(array $keys): array
    {
        $all = self::all();
        $positives = self::positives();
        $build = ['capabilities' => [], 'constraints' => [], 'health' => 0, 'gifts' => [], 'burdens' => []];

        foreach ($keys as $key) {
            $entry = $all[$key];

            foreach ($entry['grants'] ?? [] as $grant) {
                $build['capabilities'][] = [
                    'capability' => $grant['capability'],
                    'magnitude' => $grant['magnitude'] ?? null,
                    'grade' => $grant['grade'] ?? null,
                    'scope' => $grant['scope'] ?? null,
                ];
            }

            if (isset($entry['constraint'])) {
                $build['constraints'][] = $entry['constraint'] + ['coupled_capability' => null];
            }

            $build['health'] += $entry['health'] ?? 0;
            $build[isset($positives[$key]) ? 'gifts' : 'burdens'][] = $entry['label'];
        }

        return $build;
    }
}
