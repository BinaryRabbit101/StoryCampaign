import type { ActionCard, OddsPart } from '@/types/game';

/**
 * Reading the engine's ledger — never keeping a second copy of it.
 *
 * Every number here comes off the card's own forecast, which the engine wrote
 * from the same Odds table the dice will later be measured against. Nothing in
 * this file computes a difficulty, a bonus, or a band: it selects, sums, and
 * words what the engine already said, so the board can never quote a DC the
 * dice will not honor.
 */

/** Always signed, `+0` included. Hiding a zero is when its absence matters. */
export const signed = (amount: number) =>
    `${amount >= 0 ? '+' : '−'}${Math.abs(amount)}`;

/** What a set-up beat already chosen will hand to the beats after it. */
export interface CarriedBonus {
    label: string;
    amount: number;
    certain: boolean;
    verbs: string[] | null;
    slot: string | null;
}

/**
 * The reasons a difficulty is what it is, minus the one that is never a reason.
 *
 * Every roll in the game starts from the same base, so printing it as a line
 * item taught nobody anything — it was a constant taking up the top of the list
 * the player was trying to read the interesting part of. The TOTAL still stands
 * (in the ledger, on the card, and on the dice table), so nothing is hidden;
 * what is gone is the row that never varied.
 */
export const reasonsFor = (parts: OddsPart[]): OddsPart[] =>
    parts.filter((part) => part.label !== 'Base difficulty');

/**
 * Colour for one line of the difficulty ledger, by how much it costs.
 *
 * Difficulty parts run the wrong way round from bonuses: a positive number is
 * the world making this harder. Nothing (amber) reads differently from a lot
 * (red), which is the point — a wall of identically-coloured numbers is a wall.
 * A negative part is the world helping, and it reads like a bonus does.
 */
export function costClass(amount: number): string {
    if (amount < 0) {
        return 'text-emerald-700 dark:text-emerald-400';
    }
    if (amount === 0) {
        return 'text-muted-foreground';
    }

    return amount >= 3
        ? 'text-red-600 dark:text-red-400'
        : 'text-orange-600 dark:text-orange-400';
}

/** What this card must beat, at a given stance. */
export function difficultyAt(card: ActionCard, stance: string): number {
    return card.forecast.stances[stance] ?? card.forecast.difficulty;
}

/** What a stance chip costs or saves against the balanced reading. */
export function stanceDelta(card: ActionCard, stance: string): number | null {
    const at = card.forecast.stances[stance];
    const base = card.forecast.stances.balanced;

    return at === undefined || base === undefined ? null : at - base;
}

/** Bonuses standing now, plus whatever the earlier beats of this plan buy. */
export function bonusesFor(
    card: ActionCard,
    carried: CarriedBonus[] = [],
): (OddsPart & { certain: boolean })[] {
    const own = card.forecast.bonus_parts.map((p) => ({ ...p, certain: true }));
    const inherited = carried.filter(
        (b) =>
            !own.some((o) => o.label === b.label) &&
            (b.verbs === null || b.verbs.includes(card.verb)) &&
            (b.slot === null || b.slot === card.slot),
    );

    return [...own, ...inherited];
}

export function bonusTotal(
    card: ActionCard,
    carried: CarriedBonus[] = [],
): number {
    return bonusesFor(card, carried).reduce((sum, b) => sum + b.amount, 0);
}

export const bandClass = (band: string) =>
    ({
        Easy: 'text-emerald-700 dark:text-emerald-400',
        Medium: 'text-amber-700 dark:text-amber-400',
        Hard: 'text-orange-700 dark:text-orange-400',
        Certain: 'text-sky-700 dark:text-sky-400',
    })[band] ?? 'text-rose-700 dark:text-rose-400';

export function riskChipClass(risk: string): string {
    switch (risk) {
        case 'degraded':
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-400';
        case 'risky':
            return 'bg-orange-500/15 text-orange-700 dark:text-orange-400';
        default:
            return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
    }
}

export function riskLabel(risk: string): string {
    switch (risk) {
        case 'degraded':
            return 'a stretch';
        case 'risky':
            return 'risky';
        default:
            return 'safe';
    }
}

export function costLabel(card: ActionCard): string | null {
    if (!card.cost.length) {
        return null;
    }

    return card.cost
        .map((c) => `${c.amount} ${c.meter.replace('_', ' ')}`)
        .join(', ');
}

/**
 * Whether a set-up beat's grant could pay anything this turn, and in what
 * words. Null when this beat buys nothing the arithmetic can see — the line
 * the rider list uses to tell a real offer from noise.
 */
export function grantLine(
    card: ActionCard,
    actVerb: string | null,
): string | null {
    const grant = card.forecast.grant;

    if (grant === null) {
        return null;
    }

    const maybe = grant.certain ? '' : ' — if it lands';

    if (grant.slot === 'companion') {
        return `${signed(grant.amount)} to what you ask of them${maybe}`;
    }

    if (grant.verbs === null) {
        return `${signed(grant.amount)} to what comes next${maybe}`;
    }

    return actVerb !== null && grant.verbs.includes(actVerb)
        ? `${signed(grant.amount)} to this act${maybe}`
        : null;
}

/**
 * What this beat moves toward, when an endeavor is open and names its verb.
 * Null otherwise. Same class of promise as a grant, and read the same way —
 * off the engine's own forecast, never re-derived here.
 */
export function endeavorLine(card: ActionCard): string | null {
    return card.forecast.endeavor === null
        ? null
        : `advances ${card.forecast.endeavor}`;
}

/**
 * Whose small story this beat would help along, once the want is discovered.
 * Same class of promise as the endeavor line, same source — the engine's own
 * forecast, never re-derived here.
 */
export function threadLine(card: ActionCard): string | null {
    return card.forecast.thread === null
        ? null
        : `helps ${card.forecast.thread}`;
}

/** One target's identity, for collapsing same-verb cards into a chip strip. */
export const targetKey = (card: ActionCard) =>
    card.target
        ? `${card.target.type}:${card.target.id ?? card.target.name}`
        : 'none';

/** One chip on the WHAT strip: a thing the chosen verb can be aimed at. */
export interface TargetOption {
    key: string;
    name: string;
    /** Null when this beat casts no die. */
    difficulty: number | null;
    risk: ActionCard['risk'];
}
