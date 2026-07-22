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
