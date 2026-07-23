<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ActionCard, SlotChoice } from '@/types/game';

const props = defineProps<{
    title: string;
    hint: string;
    cards: ActionCard[];
    optional: boolean;
    modelValue: SlotChoice | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: SlotChoice | null): void;
}>();

// Optional slots start folded; the act is always open.
const expanded = ref(!props.optional);

/**
 * Presentation-only grouping: same-verb cards that differ only by target
 * collapse into one row with target chips. Every submission still references
 * an engine-offered card id.
 */
interface Row {
    key: string;
    label: string;
    cards: ActionCard[];
}

const rows = computed<Row[]>(() => {
    const grouped = new Map<string, ActionCard[]>();

    for (const card of props.cards) {
        const key = card.target
            ? `verb:${card.verb}:${card.capability ?? ''}`
            : `card:${card.id}`;
        grouped.set(key, [...(grouped.get(key) ?? []), card]);
    }

    return [...grouped.entries()].map(([key, cards]) => ({
        key,
        label: cards.length > 1 ? humanizeVerb(cards[0].verb) : cards[0].label,
        cards,
    }));
});

function humanizeVerb(verb: string): string {
    const name = verb.replace(/_/g, ' ');

    return name.charAt(0).toUpperCase() + name.slice(1);
}

const selectedCard = computed<ActionCard | null>(
    () => props.cards.find((c) => c.id === props.modelValue?.card_id) ?? null,
);

const rowSelection = (row: Row) =>
    row.cards.find((c) => c.id === props.modelValue?.card_id) ?? null;

// The card a row is currently "about": the chosen one, else its first.
const rowCard = (row: Row) => rowSelection(row) ?? row.cards[0];

function choiceFor(card: ActionCard): SlotChoice {
    const modifiers: Record<string, string> = {};

    for (const modifier of card.modifiers) {
        modifiers[modifier.key] = modifier.options[0]?.value ?? '';
    }

    return { card_id: card.id, modifiers };
}

function tapRow(row: Row) {
    if (rowSelection(row)) {
        if (props.optional) {
            emit('update:modelValue', null);
        }

        return;
    }

    emit('update:modelValue', choiceFor(row.cards[0]));
}

function pickTarget(card: ActionCard) {
    if (card.id === props.modelValue?.card_id) {
        return;
    }

    emit('update:modelValue', choiceFor(card));
}

function setModifier(key: string, value: string) {
    if (!props.modelValue) {
        return;
    }

    emit('update:modelValue', {
        ...props.modelValue,
        modifiers: { ...props.modelValue.modifiers, [key]: value },
    });
}

function riskChipClass(risk: string): string {
    switch (risk) {
        case 'degraded':
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-400';
        case 'risky':
            return 'bg-orange-500/15 text-orange-700 dark:text-orange-400';
        default:
            return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
    }
}

function riskLabel(risk: string): string {
    switch (risk) {
        case 'degraded':
            return 'a stretch';
        case 'risky':
            return 'risky';
        default:
            return 'safe';
    }
}

function costLabel(card: ActionCard): string | null {
    if (!card.cost.length) {
        return null;
    }

    return card.cost
        .map((c) => `${c.amount} ${c.meter.replace('_', ' ')}`)
        .join(', ');
}
</script>

<template>
    <section>
        <div
            class="mb-2 flex w-full items-baseline justify-between gap-2 text-left"
            :class="optional ? 'cursor-pointer select-none' : ''"
            :role="optional ? 'button' : undefined"
            @click="optional && (expanded = !expanded)"
        >
            <h3 class="text-sm font-semibold tracking-wide uppercase">
                <span
                    v-if="optional"
                    class="mr-1 inline-block text-xs text-muted-foreground transition-transform"
                    :class="expanded ? 'rotate-90' : ''"
                    >▸</span
                >
                {{ title }}
            </h3>
            <span
                v-if="!expanded && selectedCard"
                class="truncate text-xs text-foreground"
                >{{ selectedCard.label }}</span
            >
            <span v-else-if="!expanded" class="text-xs text-muted-foreground"
                >optional ·
                {{
                    cards.length
                        ? `${cards.length} options`
                        : 'nothing offers itself'
                }}</span
            >
            <span v-else class="text-xs text-muted-foreground"
                >{{ optional ? 'optional' : 'required' }} · {{ hint }}</span
            >
        </div>

        <template v-if="expanded">
            <p
                v-if="!cards.length"
                class="text-sm text-muted-foreground italic"
            >
                Nothing offers itself for this beat.
            </p>

            <!-- One steady column: every card shows what it is, what it
                 risks, and what it costs before anything is tapped, and
                 selecting never reflows the list. -->
            <div class="space-y-1.5">
                <div
                    v-for="row in rows"
                    :key="row.key"
                    class="cursor-pointer rounded-lg border px-3 py-2 transition"
                    :class="
                        rowSelection(row)
                            ? 'border-primary bg-accent ring-1 ring-primary'
                            : 'border-sidebar-border/70 hover:bg-accent/50 dark:border-sidebar-border'
                    "
                    @click="tapRow(row)"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium">{{ row.label }}</span>
                        <span class="flex shrink-0 items-center gap-1.5">
                            <span
                                v-if="costLabel(rowCard(row))"
                                class="text-xs text-violet-600 dark:text-violet-400"
                                >{{ costLabel(rowCard(row)) }}</span
                            >
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase"
                                :class="riskChipClass(rowCard(row).risk)"
                                >{{ riskLabel(rowCard(row).risk) }}</span
                            >
                            <span
                                v-if="rowSelection(row) && optional"
                                class="text-xs text-muted-foreground"
                                title="Tap again to clear"
                                >✕</span
                            >
                        </span>
                    </div>

                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ rowCard(row).description }}
                    </p>

                    <!-- Targets are visible up front; tapping one selects it. -->
                    <div
                        v-if="row.cards.length > 1"
                        class="mt-2 flex flex-wrap gap-1"
                    >
                        <button
                            v-for="card in row.cards"
                            :key="card.id"
                            type="button"
                            class="rounded-full border px-2.5 py-1 text-xs transition"
                            :class="
                                card.id === modelValue?.card_id
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : card.risk !== 'safe'
                                      ? 'border-amber-500/60 text-amber-700 hover:bg-accent dark:text-amber-400'
                                      : 'border-input hover:bg-accent'
                            "
                            @click.stop="pickTarget(card)"
                        >
                            {{ card.target?.name ?? card.label }}
                        </button>
                    </div>

                    <div
                        v-if="rowSelection(row) && rowSelection(row)!.modifiers.length"
                        class="mt-2 space-y-2 border-t border-sidebar-border/50 pt-2"
                    >
                        <div
                            v-for="modifier in rowSelection(row)!.modifiers"
                            :key="modifier.key"
                        >
                            <div
                                class="mb-1 text-xs font-medium text-muted-foreground"
                            >
                                {{ modifier.label }}
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <button
                                    v-for="option in modifier.options"
                                    :key="option.value"
                                    type="button"
                                    class="rounded-full border px-2.5 py-1 text-xs transition"
                                    :class="
                                        modelValue?.modifiers[modifier.key] ===
                                        option.value
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-input hover:bg-accent'
                                    "
                                    @click.stop="
                                        setModifier(modifier.key, option.value)
                                    "
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </section>
</template>
