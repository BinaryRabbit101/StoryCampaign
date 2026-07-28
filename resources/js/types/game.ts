export interface CardModifierOption {
    value: string;
    label: string;
}

export interface CardModifier {
    key: string;
    label: string;
    options: CardModifierOption[];
}

export interface CardCost {
    meter: string;
    amount: number;
}

/** One line of the odds ledger: a reason, and what it is worth. */
export interface OddsPart {
    label: string;
    amount: number;
}

/**
 * What a card promises before it is chosen. A turn commits the instant it is
 * submitted, so the difficulty has to be readable up front — and it comes
 * from the same engine ledger the dice are later measured against.
 */
export interface CardForecast {
    /** False for beats that simply happen: setting down, steadying, waiting. */
    rolls: boolean;
    difficulty: number;
    band: string;
    parts: OddsPart[];
    /** The difficulty at each stance, so the chips can price themselves. */
    stances: Record<string, number>;
    /** Bonuses already standing — high ground held before this turn began. */
    bonus: number;
    bonus_parts: OddsPart[];
    /** What choosing this card buys the beats that follow it. */
    grant: {
        condition: string;
        label: string;
        amount: number;
        verbs: string[] | null;
        slot: string | null;
        /** Bought outright, or only if the beat lands. */
        certain: boolean;
    } | null;
}

/**
 * The deal a bargain card is: a named edge on the arithmetic traded for a named
 * consequence in the world, both quoted before the commit and the consequence
 * paid whether the roll lands or not. Never the "better" card — the plain
 * sibling stands beside it, and both lines carry the same weight on screen.
 */
export interface CardBargain {
    key: string;
    /** What it buys, in the same words the odds ledger itemizes it under. */
    edge_label: string;
    /** What it costs, applied by the engine the instant the beat resolves. */
    complication_label: string;
}

export interface ActionCard {
    id: string;
    slot: 'pre' | 'main' | 'post' | 'companion';
    verb: string;
    label: string;
    description: string;
    target: { type: string; id: number | null; name: string } | null;
    capability: string | null;
    risk: 'safe' | 'risky' | 'degraded';
    cost: CardCost[];
    modifiers: CardModifier[];
    composed: boolean;
    /** Null on ordinary cards; present only on the bargained twin of one. */
    bargain: CardBargain | null;
    forecast: CardForecast;
}

export interface SlotChoice {
    card_id: string;
    modifiers: Record<string, string>;
    /** The player's own words for this one beat: narration color, never mechanics. */
    note: string;
}

/** One companion's own request slot: their cards, chosen independently. */
export interface CompanionCards {
    id: number;
    name: string;
    cards: ActionCard[];
}

export interface TurnCards {
    pre: ActionCard[];
    main: ActionCard[];
    post: ActionCard[];
    companions?: CompanionCards[];
}

/**
 * One way to spend the idle stretch ahead. The terms are the whole point:
 * the payout is priced on the option before it is picked, the same way a
 * card prints its difficulty before it is committed to.
 */
export interface DowntimeStance {
    id: string;
    label: string;
    terms: string;
    /**
     * Present only on the stances that take words — today, the campfire. The
     * line is colour for the chapter and never touches a number, exactly like
     * a per-beat note.
     */
    note?: string;
}

/** The wait ahead: what is offered, and what has already been chosen for it. */
export interface DowntimeOffer {
    offer: DowntimeStance[];
    /** Chosen once and only once — null until the player says. */
    stance: string | null;
}

export interface HealthMeter {
    current: number;
    max: number;
}

export interface CharacterMeters {
    health: HealthMeter;
    tempo: Record<string, { current: number; max: number }>;
}

/** A natural 20 or a natural 1, read off the die face before any modifier. */
export type Crit = 'success' | 'failure' | null;

export interface ChapterEvent {
    id: string;
    icon: string;
    label: string;
    slot: string | null;
    verb: string | null;
    degree: string | null;
    skipped: boolean;
    facts: string[];
    note: string | null;
    crit: Crit;
    roll: {
        roll: number;
        total: number;
        difficulty: number;
        crit: Crit;
        difficulty_parts: OddsPart[];
        bonus_parts: OddsPart[];
    } | null;
}

/**
 * One die on the dice table, derived from a resolved turn. Every number here
 * was cast by the engine before the screen existed — the table replays them,
 * it never produces them.
 */
export interface RollRow {
    id: string;
    side: 'player' | 'ally' | 'foe';
    actor: string;
    action: string;
    verb: string;
    icon: string;
    difficulty: number;
    /** Easy / Medium / Hard / Brutal, banded from the difficulty. */
    band: string;
    roll: number;
    modifier: number;
    total: number;
    degree: string;
    crit: Crit;
    outcome: string | null;
    /** Why the difficulty was what it was, and where the modifier came from. */
    difficulty_parts: OddsPart[];
    bonus_parts: OddsPart[];
}

export interface RollTable {
    turn_id: number;
    turn_number: number;
    rows: RollRow[];
}

/**
 * A person or a piece of ground the chapter can name. The page finds these
 * names inside the prose and makes each one tappable, so scenery and things
 * that can be acted on stop looking alike.
 */
export interface ChapterEntity {
    key: string;
    kind: 'actor' | 'feature';
    /**
     * What the anchor is, for colour: a dotted underline on its own is too
     * quiet to spot mid-paragraph, so each nature wears its own hue.
     */
    tone: 'foe' | 'ally' | 'person' | 'ground';
    icon: string;
    name: string;
    title: string;
    /**
     * Every form the prose might use for this thing — the full name plus the
     * short ones narration actually writes ("the crates" for "Stacked Cargo
     * Crates"). The engine filters out words too ordinary, or too contested,
     * to belong to anything.
     */
    aliases: string[];
    lines: string[];
}

/**
 * The state of play as groups. Shown beside every chapter, never as one
 * run-on paragraph — and an empty board is a real reading of a quiet place.
 */
export interface SituationGroup {
    key: string;
    title: string;
    tone: 'neutral' | 'foe' | 'ally' | 'person' | 'ground' | 'self';
    items: string[];
}

/**
 * A keepsake a notable moment left behind. Memory, not equipment: it grants
 * nothing, costs nothing, and cannot be spent — it is here to be read, and to
 * be bound into the closing pages of the book.
 */
export interface Memento {
    name: string;
    line: string;
    /** The chapter that tells it; null until that chapter has been written. */
    chapter: number | null;
}

/** Something physically in the character's hands. */
export interface CarriedThing {
    name: string;
    feature_id: number | null;
    hands: number;
}

/** One exchange in the evolution conversation. */
export interface GrowthMessage {
    id: number;
    role: 'player' | 'narrator';
    body: string;
    /** Whether the sheet actually changed — the engine's verdict, not prose. */
    granted: boolean | null;
    changes:
        { kind: 'gift' | 'burden'; label: string; detail: string }[] | null;
    suggestions: string[] | null;
}

export interface CharacterItem {
    name: string;
    description: string;
    power: number;
    grants:
        | {
              capability: string;
              magnitude: number | null;
              grade?: string | null;
          }[]
        | null;
    equipped: boolean;
    charges: number | null;
}
