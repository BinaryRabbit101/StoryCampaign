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
        // Answering someone who has already asked. Both halves of the offer
        // pair are roll-free on purpose: the decision was theirs to make and
        // the other party has already made theirs, so there is nothing left for
        // a die to adjudicate. A "failed" welcome would be the engine telling
        // the player their yes did not take.
        'companion_welcome', 'companion_dismiss',
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
     * What an old wound costs, and to what.
     *
     * A scar is a burden the character acquired mid-tale (App\Game\ScarCatalog),
     * carried on the sheet as an ordinary constraint row. It reaches the
     * arithmetic only through here, itemized under a plain label — "The old
     * wound in your knee" — because a cost the player cannot see teaches them
     * nothing, and a permanent one they cannot see is the worst kind: it is
     * still charging them ten chapters after they have forgotten it exists.
     *
     * The key is the constraint's NAME, not its source. A scar prices exactly
     * as the same burden would have priced had it been taken at creation —
     * there is no second, harsher ladder for injuries, because "how you came by
     * it" is a story fact and story facts never move numbers.
     *
     * One-sided on purpose, unlike AMBIENT: a scar is a price paid for
     * surviving, not weather. It stays inside the ±4 spread of CONDITIONS
     * (nothing here exceeds 2) so a marked body is never worse off than
     * everything it built.
     *
     * A rule matches on the card's VERB or on the capability it is spent
     * through — a bad knee is a bad knee whether the leap is written as a
     * `cross` or an `ascend`. Verbs that cast no die (Odds::QUIET) are absent:
     * a table entry the dice can never honor is a price nobody pays.
     */
    private const SCARS = [
        'marked_limp' => [
            'label' => 'The old wound in your knee',
            'amount' => 2,
            'verbs' => ['ascend', 'cross', 'flee', 'ride'],
            'capabilities' => ['climb', 'leap', 'swing', 'glide'],
        ],
        'guarded_side' => [
            'label' => 'The side that never healed straight',
            'amount' => 2,
            'verbs' => ['restrain', 'haul', 'hurl'],
            'capabilities' => ['restrain', 'carry_extra'],
        ],
        'dimmed_eye' => [
            'label' => 'The eye that never came back',
            'amount' => 2,
            'verbs' => ['detect', 'scout', 'track'],
            'capabilities' => ['scout', 'detect', 'track'],
        ],
        'unsteady_hands' => [
            'label' => 'The tremor in your hands',
            'amount' => 2,
            'verbs' => ['lift', 'break', 'recover'],
            'capabilities' => ['lift', 'break'],
        ],
        'ruined_voice' => [
            'label' => 'The voice you were left with',
            'amount' => 2,
            'verbs' => ['persuade', 'deceive', 'calm', 'intimidate', 'speak', 'recruit'],
            'capabilities' => ['persuade', 'deceive', 'calm', 'intimidate'],
        ],
        'lingering_flinch' => [
            'label' => 'The flinch you did not have before',
            'amount' => 2,
            'verbs' => ['strike', 'interrupt', 'improvise'],
            'capabilities' => [],
        ],
    ];

    /**
     * What a bargain buys, and on which side of the arithmetic.
     *
     * A bargain card is the plain card with a named complication attached and a
     * named edge granted — "wrench it open, and the district hears you." The
     * COMPLICATION is the world's business and lives in Bargains; the EDGE is
     * arithmetic, so it lives here with every other number, itemized like the
     * rest. A second copy of these amounts sitting in the bargain table is
     * exactly how a card would start advertising an edge the dice never gave it.
     *
     * Two sides, because the two kinds of deal do not feel alike. A `difficulty`
     * edge of -4 moves the card exactly one band down the BANDS ladder above:
     * Hard becomes Medium, Medium becomes Easy. A `bonus` edge rides on the
     * roll instead, where the player reads it as their own nerve rather than as
     * easier ground.
     *
     * Magnitudes stay inside the CONDITIONS spread: a bargain is a trade, never
     * a shortcut past everything the player built.
     */
    private const BARGAINS = [
        'loud' => ['side' => 'difficulty', 'amount' => -4, 'label' => 'You stop being careful about the noise'],
        'two_hands' => ['side' => 'difficulty', 'amount' => -4, 'label' => 'Both hands on it, and nothing else'],
        'reckless' => ['side' => 'bonus', 'amount' => 2, 'label' => 'Nothing covered, nothing held back'],
        'provoking' => ['side' => 'bonus', 'amount' => 2, 'label' => 'You give them something they have to answer'],
        'burning' => ['side' => 'bonus', 'amount' => 3, 'label' => 'Everything the gift has, spent at once'],
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

        // What the body carries out of everywhere it has already been. Same
        // table for the card's forecast and for the die, so a scar can never
        // quietly cost two points the card did not quote.
        foreach (self::scarParts($conditions['scars'] ?? [], $card['verb'], $card['capability'] ?? null, $card['slot'] ?? null) as $part) {
            $parts[] = $part;
        }

        // The deal, if this card is one. The complication was printed on the
        // card beside this line; the edge is the half the arithmetic owes back.
        $edge = self::bargainPart($card['bargain']['key'] ?? null, 'difficulty');
        if ($edge !== null) {
            $parts[] = $edge;
        }

        return self::ledger($parts);
    }

    /**
     * What a bargain is worth on one side of the arithmetic, itemized, or null
     * when this key trades on the other side (or is no key at all).
     *
     * Public because the composer prints the very label the resolver will add
     * up. One table, three readers, no drift.
     *
     * @return Part|null
     */
    public static function bargainPart(?string $key, string $side): ?array
    {
        $rule = self::BARGAINS[$key] ?? null;

        if ($rule === null || $rule['side'] !== $side) {
            return null;
        }

        return ['label' => $rule['label'], 'amount' => $rule['amount']];
    }

    /** The player-facing wording for a bargain's edge, quoted on the card before the commit. */
    public static function bargainLabel(?string $key): ?string
    {
        return self::BARGAINS[$key]['label'] ?? null;
    }

    /** @return list<string> */
    public static function bargainKeys(): array
    {
        return array_keys(self::BARGAINS);
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
     * What the scars this body carries cost a given card. Public because it is
     * the one place the scar table is read; nothing else may keep a copy.
     *
     * The player's own marks never price a COMPANION's attempt — the companion
     * is rolling their own body, and charging them for the player's bad knee
     * would be a number nobody could account for.
     *
     * @param  list<string>  $scars  Constraint names carried on the sheet.
     * @return list<Part>
     */
    public static function scarParts(array $scars, string $verb, ?string $capability = null, ?string $slot = null): array
    {
        if ($slot === 'companion') {
            return [];
        }

        $parts = [];

        foreach ($scars as $scar) {
            $rule = self::SCARS[$scar] ?? null;
            if ($rule === null) {
                continue;
            }

            $matched = in_array($verb, $rule['verbs'], true)
                || ($capability !== null && in_array($capability, $rule['capabilities'], true));

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
    public static function bonus(array $conditions, string $verb, string $slot, ?string $bargain = null): array
    {
        $parts = [];

        $edge = self::bargainPart($bargain, 'bonus');
        if ($edge !== null) {
            $parts[] = $edge;
        }

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
