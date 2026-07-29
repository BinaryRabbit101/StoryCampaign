export interface CardModifierOption {
    value: string;
    label: string;
    /**
     * What choosing this option actually trades, in the engine's own words.
     * A difficulty delta on its own reads as nonsense on half the verbs in the
     * game ("creeping away is easier than running?"), because the delta is only
     * one side of the deal — the other side is which results stay on the table.
     * The engine states both; the page never works either out.
     */
    terms?: string;
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
    /**
     * The endeavor this beat moves, when one is open and names this verb.
     * Quoted off the clock's own row — the same list the engine's tick reads
     * — so the promise on the card is the one the beat actually pays.
     */
    endeavor: string | null;
    /**
     * Whose small story this beat would help along, once discovered. Quoted
     * off the thread's own row like the endeavor above — with the one
     * difference that a roll-free beat may carry it.
     */
    thread: string | null;
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

/**
 * The nine words the verb board is built from. Presentation only — the engine
 * never resolves a family, and no card is ever composed from one. The list is
 * `App\Game\VerbFamily`; the page never decides membership, it only draws it.
 */
export type VerbFamily =
    | 'look'
    | 'go'
    | 'take'
    | 'fight'
    | 'speak'
    | 'hide'
    | 'tend'
    | 'wait'
    | 'do';

export interface ActionCard {
    id: string;
    slot: 'pre' | 'main' | 'post' | 'companion';
    verb: string;
    /**
     * Which board word this card sits under, decided by the engine's catalog.
     * The client never re-derives it: a second copy of the vocabulary living
     * here is how a verb added last week ends up filed under nothing.
     */
    family: VerbFamily;
    /** The verb's own short name, for the board's second level. */
    verb_label: string;
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
    /**
     * News from elsewhere the character picked up this turn — off the road,
     * from somebody they were talking to, or during the wait. Null on nearly
     * every turn. Quiet by design: there is nothing here to answer.
     */
    heard: string | null;
    /**
     * A line out of one of this player's own finished tales, surfacing because
     * this moment rhymed with the moment that preserved it. Rarer still than
     * the news above, and silent forever on a player's first tale. Quotation,
     * never invention — and nothing here to answer either.
     */
    remembered: string | null;
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

/** One heading of the returning-player recap, and the lines under it. */
export interface RecapSection {
    key: string;
    title: string;
    lines: string[];
}

/**
 * Previously, on this tale. Compiled server-side from strings the engine
 * already wrote — never generated, and null unless the player has actually
 * been away. Dismissal is per open turn and lives in localStorage, so the
 * server re-offers it on its own after the next long absence.
 */
export interface RecapPanel {
    turn_id: number;
    sections: RecapSection[];
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

/**
 * The multi-turn goal the player committed a beat to. Segments, not a
 * percentage: the whole point is that the player can count what is left and
 * price the next beat against it.
 */
export interface Endeavor {
    name: string;
    filled: number;
    segments: number;
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
