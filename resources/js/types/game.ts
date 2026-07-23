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
    slot: 'pre' | 'main' | 'post';
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
}

export interface HealthMeter {
    current: number;
    max: number;
}

export interface CharacterMeters {
    health: HealthMeter;
    tempo: Record<string, { current: number; max: number }>;
}

export interface ChapterEvent {
    id: string;
    icon: string;
    label: string;
    slot: string | null;
    verb: string | null;
    degree: string | null;
    skipped: boolean;
    facts: string[];
    roll: { roll: number; total: number; difficulty: number } | null;
}

export interface CharacterItem {
    name: string;
    description: string;
    power: number;
    grants: { capability: string; magnitude: number | null; grade?: string | null }[] | null;
    equipped: boolean;
    charges: number | null;
}
