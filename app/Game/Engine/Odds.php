<?php

namespace App\Game\Engine;

use App\Models\Actor;

/**
 * The odds ledger: every number that stands between a die and an outcome,
 * itemized.
 *
 * This exists because a card the player cannot price is a card they cannot
 * choose. Turns commit the moment they are submitted — there is no going back
 * to re-pick once the difficulty turns out to have been 18 — so the difficulty
 * and every bonus must be readable BEFORE the commit, and the same arithmetic
 * must then be what the die is actually measured against.
 *
 * So the resolver does not compute difficulty any more; it asks here. The
 * composer asks here too, for the forecast it prints on the card. One ladder,
 * two readers: a card can never promise a DC the dice will not honor.
 *
 * Both halves come back itemized (`parts`), because "+4" teaches nothing and
 * "+4 — the world slowed around you" teaches the player how the game works.
 *
 * @phpstan-type Part array{label:string,amount:int}
 * @phpstan-type Ledger array{value:int,parts:list<Part>}
 */
class Odds
{
    /** Every roll starts here. Everything else is a reason to move off it. */
    public const BASE = 10;

    /**
     * The three bands a difficulty reads in. The spread runs roughly 8–22: a
     * card taken cautiously against an open target sits at the bottom, a
     * degraded reach at a guarded enemy after an earlier failure at the top.
     */
    private const BANDS = [
        9 => 'Easy',
        13 => 'Medium',
        17 => 'Hard',
    ];

    /**
     * Verbs that never roll. They cost what they cost and then they simply
     * happen — the set-up beats, the quiet ones, the ones whose whole point
     * is to move the odds of something else.
     */
    private const QUIET = [
        'time_slow', 'haste', 'ready', 'examine', 'inspect', 'wait',
        'catch_breath', 'reposition', 'shield', 'brace', 'command', 'drop',
        'bargain',
    ];

    /**
     * What each live condition is worth, and to what.
     *
     * `verbs` null means it helps whatever comes next; a list means it only
     * helps those. `slot` narrows it further (a companion's orders help the
     * companion, not the player). Read by both the bonus tally below and the
     * set-up forecast a card prints, so a card promising "+2 to your strike"
     * is quoting the very table the resolver will add up.
     */
    private const CONDITIONS = [
        'time_slowed' => ['label' => 'Time slowed around you', 'amount' => 4, 'verbs' => null, 'slot' => null],
        'concealed' => ['label' => 'Unseen', 'amount' => 3, 'verbs' => ['strike', 'restrain', 'haul'], 'slot' => null],
        'hastened' => ['label' => 'Moving ahead of the world', 'amount' => 2, 'verbs' => null, 'slot' => null],
        'readied' => ['label' => 'Set and waiting', 'amount' => 2, 'verbs' => null, 'slot' => null],
        'elevated' => ['label' => 'High ground', 'amount' => 2, 'verbs' => ['strike'], 'slot' => null],
        'flanked' => ['label' => 'Flanked for you', 'amount' => 2, 'verbs' => ['strike'], 'slot' => null],
        'commanded' => ['label' => 'Working to your call', 'amount' => 2, 'verbs' => null, 'slot' => 'companion'],
    ];

    /**
     * What the air is worth, and to what.
     *
     * The scene's ambient (Ambient::of) is rolled once when the ground is
     * dressed and then stands for that scene's life. It reaches the arithmetic
     * only through here, itemized like everything else — a hidden two points
     * because it happened to be dark is exactly the surprise the whole ledger
     * exists to prevent.
     *
     * Every entry is two-sided at the ambient level: each non-clear air helps
     * something and hinders something. A single-sided ambient is difficulty
     * creep wearing a costume — the world would simply get harder on a roll the
     * player did not make.
     *
     * A rule matches on the card's VERB or on the capability it is spent
     * through, never both twice: `ascend` covers a climb, but a leap taken as a
     * `cross` is the same body in the same wind and must be priced the same.
     *
     * Magnitudes stay inside the ±4 spread of CONDITIONS above (nothing here
     * exceeds 2), so the weather can never outweigh something the player built.
     *
     * Note on the quiet verbs: `examine` and `inspect` are in QUIET and never
     * roll, so gloom's cost to reading the ground lands on the perception verbs
     * that DO roll — `detect` and `scout`. Listing verbs that cast no die would
     * be a table entry the dice can never honor.
     */
    private const AMBIENT = [
        // The baseline. No parts, no board line, nothing to price.
        'clear' => [],

        'gloom' => [
            ['label' => 'Little light to be seen by', 'amount' => -2, 'verbs' => ['hide'], 'capabilities' => ['conceal', 'quiet_move']],
            ['label' => 'Too little light to read the ground by', 'amount' => 2, 'verbs' => ['detect', 'scout']],
            ['label' => 'A throw into the dark', 'amount' => 1, 'verbs' => ['hurl']],
        ],

        'haze' => [
            ['label' => 'The air itself covers you', 'amount' => -2, 'verbs' => ['hide'], 'capabilities' => ['conceal']],
            ['label' => 'Thick air to break away into', 'amount' => -1, 'verbs' => ['flee']],
            ['label' => 'The air is thick — a throw must guess', 'amount' => 2, 'verbs' => ['hurl']],
            ['label' => 'Nothing carries far through this air', 'amount' => 2, 'verbs' => ['detect', 'scout']],
        ],

        'squall' => [
            ['label' => 'A trail holds in ground like this', 'amount' => -2, 'verbs' => ['track']],
            ['label' => 'Nothing off the ground is steady', 'amount' => 2, 'verbs' => ['ascend', 'ride'], 'capabilities' => ['climb', 'swing', 'leap', 'glide']],
            ['label' => 'The air throws off anything thrown', 'amount' => 2, 'verbs' => ['hurl']],
        ],
    ];

    /**
     * Which condition a quiet set-up beat leaves behind. This is the forecast
     * side of the same table: it lets a card say what it BUYS before it is
     * chosen, in the exact terms the roll will later be paid in.
     */
    private const GRANTS = [
        'time_slow' => 'time_slowed',
        'haste' => 'hastened',
        'ready' => 'readied',
        'hide' => 'concealed',
        'ascend' => 'elevated',
        'command' => 'commanded',
        'companion_flank' => 'flanked',
    ];

    public static function rolls(string $verb): bool
    {
        return ! in_array($verb, self::QUIET, true);
    }

    public static function band(int $difficulty): string
    {
        foreach (self::BANDS as $ceiling => $label) {
            if ($difficulty <= $ceiling) {
                return $label;
            }
        }

        return 'Brutal';
    }

    /**
     * What this card must beat, and why.
     *
     * @param  array{risk?:string,verb:string,capability?:?string,target?:?array}  $card
     * @return Ledger
     */
    public static function difficulty(array $card, string $approach, array $conditions = []): array
    {
        $parts = [['label' => 'Base difficulty', 'amount' => self::BASE]];

        $risk = $card['risk'] ?? 'safe';
        $riskStep = match ($risk) {
            'degraded' => 5,
            'risky' => 3,
            default => 0,
        };
        if ($riskStep !== 0) {
            $parts[] = [
                'label' => $risk === 'degraded' ? 'Past what you can comfortably do' : 'A real risk',
                'amount' => $riskStep,
            ];
        }

        $stance = match ($approach) {
            'cautious' => -2,
            'bold' => 2,
            default => 0,
        };
        if ($stance !== 0) {
            $parts[] = [
                'label' => $approach === 'cautious' ? 'Taken carefully' : 'Taken boldly',
                'amount' => $stance,
            ];
        }

        if ($conditions['prior_failure'] ?? false) {
            $parts[] = ['label' => 'An earlier beat went wrong', 'amount' => 2];
        }

        // The telegraphed intent is real: a guard is genuinely harder to
        // breach, a windup genuinely leaves the enemy open. The card already
        // told the player which — the dice honor the same fact, so the
        // forecast quotes it too.
        $intent = self::intentOf($card);
        if ($intent === 'guard') {
            $parts[] = ['label' => ($card['target']['name'] ?? 'They').' is behind a tight guard', 'amount' => 3];
        } elseif ($intent === 'windup') {
            $parts[] = ['label' => ($card['target']['name'] ?? 'They').' is wound up and open', 'amount' => -2];
        }

        // The air the scene stands in. Same table for the card's forecast and
        // for the die — the ambient is read off the live conditions both halves
        // are handed, so a card can never quote a DC the weather then changes.
        foreach (self::ambientParts($conditions['ambient'] ?? null, $card['verb'], $card['capability'] ?? null) as $part) {
            $parts[] = $part;
        }

        return self::ledger($parts);
    }

    /**
     * What this scene's air costs (or spares) a given card. Public because it
     * is the one place the ambient table is read; nothing else may keep a copy.
     *
     * @return list<Part>
     */
    public static function ambientParts(?string $ambient, string $verb, ?string $capability = null): array
    {
        $parts = [];

        foreach (self::AMBIENT[$ambient] ?? [] as $rule) {
            $matched = in_array($verb, $rule['verbs'] ?? [], true)
                || ($capability !== null && in_array($capability, $rule['capabilities'] ?? [], true));

            if ($matched) {
                $parts[] = ['label' => $rule['label'], 'amount' => $rule['amount']];
            }
        }

        return $parts;
    }

    /**
     * What the roll carries with it, and where each point came from.
     *
     * @return Ledger
     */
    public static function bonus(array $conditions, string $verb, string $slot): array
    {
        $parts = [];

        foreach (self::CONDITIONS as $key => $rule) {
            if (! ($conditions[$key] ?? false)) {
                continue;
            }
            if ($rule['verbs'] !== null && ! in_array($verb, $rule['verbs'], true)) {
                continue;
            }
            if ($rule['slot'] !== null && $slot !== $rule['slot']) {
                continue;
            }
            $parts[] = ['label' => $rule['label'], 'amount' => $rule['amount']];
        }

        return self::ledger($parts);
    }

    /**
     * What a set-up beat leaves behind for the beats after it: the label the
     * card prints, what it is worth, and which verbs can spend it. Null when
     * this verb buys nothing the arithmetic can see.
     *
     * `certain` separates the two kinds honestly. Spending a time-slow charge
     * buys its +4 outright; climbing for the high ground buys its +2 only if
     * the climb lands, and a forecast that quietly promised the second as
     * though it were the first would be lying on the card.
     *
     * @return array{condition:string,label:string,amount:int,verbs:?list<string>,slot:?string,certain:bool}|null
     */
    public static function grant(string $verb): ?array
    {
        $condition = self::GRANTS[$verb] ?? null;
        if ($condition === null) {
            return null;
        }

        $rule = self::CONDITIONS[$condition];

        return [
            'condition' => $condition,
            'label' => $rule['label'],
            'amount' => $rule['amount'],
            'verbs' => $rule['verbs'],
            'slot' => $rule['slot'],
            'certain' => ! self::rolls($verb),
        ];
    }

    /**
     * The enemy's telegraph, when the card points at one. Read from the live
     * actor rather than the card, because the card was composed a beat before
     * the intent was rolled.
     */
    private static function intentOf(array $card): ?string
    {
        if (! in_array($card['verb'], ['strike', 'interrupt', 'restrain'], true)) {
            return null;
        }

        $id = $card['target']['id'] ?? null;

        return $id === null ? null : (Actor::find($id)?->tags['intent'] ?? null);
    }

    /**
     * @param  list<Part>  $parts
     * @return Ledger
     */
    private static function ledger(array $parts): array
    {
        return [
            'value' => array_sum(array_column($parts, 'amount')),
            'parts' => array_values($parts),
        ];
    }
}
