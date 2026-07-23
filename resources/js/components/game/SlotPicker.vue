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

function rowRisk(row: Row): string {
    const risk = rowSelection(row)?.risk ?? row.cards[0].risk;

    return row.cards.every((c) => c.risk === risk) || rowSelection(row)
        ? risk
        : 'safe';
}

function riskClass(risk: string): string {
    switch (risk) {
        case 'degraded':
            return 'border-amber-500/60';
        case 'risky':
            return 'border-orange-400/40';
        default:
            return 'border-sidebar-border/70 dark:border-sidebar-border';
    }
}

function riskLabel(risk: string): string | null {
    switch (risk) {
        case 'degraded':
            return 'a stretch — risky';
        case 'risky':
            return 'risky';
        default:
            return null;
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

            <div class="grid gap-1.5 sm:grid-cols-2">
                <div
                    v-for="row in rows"
                    :key="row.key"
                    class="cursor-pointer rounded-lg border px-3 py-2 transition"
                    :class="[
                        riskClass(rowRisk(row)),
                        rowSelection(row)
                            ? 'bg-accent ring-2 ring-primary sm:col-span-2'
                            : 'hover:bg-accent/50',
                    ]"
                    @click="tapRow(row)"
                >
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-medium">{{ row.label }}</span>
                        <span class="shrink-0 text-xs text-muted-foreground">
                            <span
                                v-if="riskLabel(rowRisk(row))"
                                class="text-amber-600 dark:text-amber-400"
                                >{{ riskLabel(rowRisk(row)) }}</span
                            >
                            <span
                                v-else-if="
                                    !rowSelection(row) && row.cards.length > 1
                                "
                                >{{ row.cards.length }} targets</span
                            >
                        </span>
                    </div>

                    <!-- Detail reveals only for the chosen row -->
                    <template v-if="rowSelection(row)">
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ rowSelection(row)!.description }}
                        </p>

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
                            v-if="costLabel(rowSelection(row)!)"
                            class="mt-1 text-xs text-violet-600 dark:text-violet-400"
                        >
                            costs {{ costLabel(rowSelection(row)!) }}
                        </div>

                        <div
                            v-if="rowSelection(row)!.modifiers.length"
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
                                            modelValue?.modifiers[
                                                modifier.key
                                            ] === option.value
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-input hover:bg-accent'
                                        "
                                        @click.stop="
                                            setModifier(
                                                modifier.key,
                                                option.value,
                                            )
                                        "
                                    >
                                        {{ option.label }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </section>
</template>
