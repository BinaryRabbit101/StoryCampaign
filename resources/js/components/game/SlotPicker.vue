<script setup lang="ts">
import { computed } from 'vue';
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

/**
 * A collapsed row is named after its verb, and a bare verb reads as a stub
 * beside the full-sentence labels around it. The families that always collapse
 * — every visible thing in the scene wears one — say what they are instead.
 */
const ROW_LABELS: Record<string, string> = {
    improvise: 'Improvise with something here',
    inspect: 'Look closer at something',
    speak: 'Speak with someone',
};

function humanizeVerb(verb: string): string {
    const name = verb.replace(/_/g, ' ');

    return ROW_LABELS[verb] ?? name.charAt(0).toUpperCase() + name.slice(1);
}

const rowSelection = (row: Row) =>
    row.cards.find((c) => c.id === props.modelValue?.card_id) ?? null;

// The card a row is currently "about": the chosen one, else its first.
const rowCard = (row: Row) => rowSelection(row) ?? row.cards[0];

function choiceFor(card: ActionCard, note = ''): SlotChoice {
    const modifiers: Record<string, string> = {};

    for (const modifier of card.modifiers) {
        modifiers[modifier.key] = modifier.options[0]?.value ?? '';
    }

    return { card_id: card.id, modifiers, note };
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

    // Words already typed survive a change of target inside the same row:
    // the note was about the beat, not about which chip it points at.
    emit('update:modelValue', choiceFor(card, props.modelValue?.note ?? ''));
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

function setNote(note: string) {
    if (!props.modelValue) {
        return;
    }

    emit('update:modelValue', { ...props.modelValue, note });
}

/**
 * What a nudge is FOR differs between swinging a blade and asking a favor,
 * and an empty box teaches neither. The prompt follows the verb.
 */
const NOTE_HINTS: Record<string, string> = {
    improvise: 'What exactly do you try, and with what?',
    strike: 'Where do you aim — and what do you say as you swing?',
    interrupt: 'How do you get inside it?',
    speak: 'What do you actually say?',
    persuade: 'What argument do you reach for?',
    deceive: 'What is the lie?',
    calm: 'How do you steady them?',
    intimidate: 'What do they see in you?',
    recruit: 'How do you ask?',
    restrain: 'How do you take hold?',
    inspect: 'What are you hoping to find?',
    examine: 'What are you hoping to find?',
    scout: 'What are you listening for?',
    hide: 'How do you make yourself small?',
    flee: 'How do you go — quiet, or fast?',
    cross: 'How do you commit to it?',
    ascend: 'How do you go up?',
    break: 'How do you go at it?',
    venture: 'What do you leave behind, and how?',
    wait: 'What are you waiting for?',
    bandage: 'How badly is it hurting?',
    catch_breath: 'What is going through your head?',
    reposition: 'Where do you put yourself?',
};

function notePlaceholder(card: ActionCard): string {
    return NOTE_HINTS[card.verb] ?? 'How do you want this to unfold?';
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
        >
            <h3 class="text-sm font-semibold tracking-wide uppercase">
                {{ title }}
            </h3>
            <span class="text-xs text-muted-foreground"
                >{{ optional ? 'optional' : 'required' }} · {{ hint }}</span
            >
        </div>

        <p v-if="!cards.length" class="text-sm text-muted-foreground italic">
            Nothing offers itself for this beat.
        </p>

        <template v-else>
            <!-- One steady column: every card shows what it is, what it
                 risks, and what it costs before anything is tapped, and
                 selecting never reflows the list. -->
            <div class="space-y-1.5">
                <div
                    v-for="(row, index) in rows"
                    :key="row.key"
                    class="sc-rise cursor-pointer rounded-lg border px-3 py-2 transition-all duration-200 active:scale-[0.99]"
                    :class="
                        rowSelection(row)
                            ? 'border-violet-500 bg-violet-500/10 shadow-md ring-1 shadow-violet-500/10 ring-violet-500'
                            : 'border-sidebar-border/70 hover:-translate-y-0.5 hover:bg-accent/50 hover:shadow-md hover:shadow-violet-500/5 dark:border-sidebar-border'
                    "
                    :style="{ animationDelay: `${Math.min(index, 8) * 45}ms` }"
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
                            class="rounded-full border px-2.5 py-1 text-xs transition active:scale-95"
                            :class="
                                card.id === modelValue?.card_id
                                    ? 'border-violet-600 bg-violet-600 text-white'
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
                        v-if="
                            rowSelection(row) &&
                            rowSelection(row)!.modifiers.length
                        "
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
                                    class="rounded-full border px-2.5 py-1 text-xs transition active:scale-95"
                                    :class="
                                        modelValue?.modifiers[modifier.key] ===
                                        option.value
                                            ? 'border-violet-600 bg-violet-600 text-white'
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

                    <!-- The nudge: how the player wants this one beat to
                         unfold. It reaches the narrator as voice and never
                         touches the engine's dice. -->
                    <div
                        v-if="rowSelection(row)"
                        class="mt-2 border-t border-sidebar-border/50 pt-2"
                        @click.stop
                    >
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                        >
                            In your own words
                            <span class="font-normal"
                                >— colors the telling, changes nothing</span
                            >
                        </label>
                        <textarea
                            :value="modelValue?.note ?? ''"
                            rows="2"
                            maxlength="280"
                            :placeholder="notePlaceholder(rowCard(row))"
                            class="w-full resize-y rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus:border-violet-500/70 focus:outline-none"
                            @input="
                                setNote(
                                    ($event.target as HTMLTextAreaElement)
                                        .value,
                                )
                            "
                        />
                    </div>
                </div>
            </div>
        </template>
    </section>
</template>
