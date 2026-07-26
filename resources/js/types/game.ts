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
