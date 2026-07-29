<script setup lang="ts">
import { computed } from 'vue';
import HowPanel from '@/components/game/HowPanel.vue';
import TargetStrip from '@/components/game/TargetStrip.vue';
import VerbBoard from '@/components/game/VerbBoard.vue';
import { targetKey } from '@/lib/odds';
import type { TargetOption } from '@/lib/odds';
import type { CarriedBonus } from '@/lib/odds';
import type { ActionCard, SlotChoice } from '@/types/game';

/**
 * One of the player's three picks, whole.
 *
 * The sentence has not changed — a word off the board, the thing it is aimed at,
 * the manner of it — only how many times it is offered. Every pick draws on the
 * same list the engine composed, because position no longer decides what may
 * stand in it: "First…" and "Afterward…" used to be two separate short lists of
 * whatever the composer happened to file there, and two of the player's three
 * beats were leftovers they learned to skip.
 *
 * This composes nothing. Every tap terminates in an id the engine offered for
 * this slot, which is what keeps "never resolve a card the engine didn't offer"
 * true by construction rather than by care.
 */
const props = defineProps<{
    /** The number the panel shows: 1 before, 2 the act, 3 after. */
    step: number;
    title: string;
    hint: string;
    /** The main act is the only one the turn cannot go without. */
    required?: boolean;
    /** This slot's cards, exactly as the engine offered them. */
    cards: ActionCard[];
    modelValue: SlotChoice | null;
    /** What the beats already chosen ahead of this one hand to it. */
    carried?: CarriedBonus[];
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', choice: SlotChoice | null): void;
    (e: 'toggle'): void;
}>();

const card = computed<ActionCard | null>(
    () => props.cards.find((c) => c.id === props.modelValue?.card_id) ?? null,
);

/** Every card standing under the verb currently chosen. */
const verbCards = computed(() =>
    card.value === null
        ? []
        : props.cards.filter((c) => c.verb === card.value!.verb),
);

const chosenTargetKey = computed(() =>
    card.value === null ? null : targetKey(card.value),
);

/** WHAT: one chip per thing this verb can be aimed at. */
const targets = computed<TargetOption[]>(() => {
    const grouped = new Map<string, ActionCard[]>();

    for (const option of verbCards.value) {
        const key = targetKey(option);
        grouped.set(key, [...(grouped.get(key) ?? []), option]);
    }

    return [...grouped.entries()].map(([key, options]) => {
        const shown =
            options.find((o) => o.id === props.modelValue?.card_id) ??
            options[0];

        return {
            key,
            name: shown.target?.name ?? shown.label,
            risk: shown.risk,
        };
    });
});

/** HOW: the manners and the deal available on the target now chosen. */
const variants = computed(() =>
    verbCards.value.filter((c) => targetKey(c) === chosenTargetKey.value),
);

function choiceFor(option: ActionCard, note = ''): SlotChoice {
    const modifiers: Record<string, string> = {};

    for (const modifier of option.modifiers) {
        modifiers[modifier.key] = modifier.options[0]?.value ?? '';
    }

    return { card_id: option.id, modifiers, note };
}

/** A new verb is a new beat, so the words written for the old one go with it. */
function pickVerb(option: ActionCard) {
    emit('update:modelValue', choiceFor(option));
}

/** A new target or manner is the same beat re-aimed — the note survives it. */
function pickVariant(option: ActionCard) {
    if (option.id !== props.modelValue?.card_id) {
        emit(
            'update:modelValue',
            choiceFor(option, props.modelValue?.note ?? ''),
        );
    }
}

function pickTarget(key: string) {
    const options = verbCards.value.filter((c) => targetKey(c) === key);
    // The honest version first: a deal is never what a tap lands on by default.
    const plain = options.find((c) => c.bargain === null) ?? options[0];

    if (plain) {
        pickVariant(plain);
    }
}
</script>

<template>
    <div
        class="rounded-lg border transition-colors"
        :class="
            card
                ? 'border-violet-500/60 bg-violet-500/5'
                : 'border-sidebar-border/70 dark:border-sidebar-border'
        "
    >
        <!-- The row itself: what this pick currently holds, and nothing else.
             Collapsed, three of these read as a plan; expanded, one of them is
             the whole form. -->
        <div class="flex items-baseline gap-2 px-2.5 py-2">
            <button
                type="button"
                class="flex min-w-0 flex-1 items-baseline gap-2 text-left"
                :aria-expanded="open"
                @click="emit('toggle')"
            >
                <span
                    class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold tabular-nums"
                    :class="
                        card
                            ? 'bg-violet-600 text-white'
                            : 'bg-muted text-muted-foreground'
                    "
                    >{{ step }}</span
                >
                <span class="min-w-0 flex-1">
                    <span
                        class="block text-[10px] tracking-widest text-muted-foreground uppercase"
                    >
                        {{ title }}
                        <span class="tracking-normal normal-case"
                            >— {{ hint }}</span
                        >
                    </span>
                    <span class="block truncate text-sm">
                        <template v-if="card">{{ card.label }}</template>
                        <span v-else-if="required" class="text-muted-foreground"
                            >Choose what you do</span
                        >
                        <span v-else class="text-muted-foreground italic"
                            >nothing — this is optional</span
                        >
                    </span>
                </span>
                <span class="shrink-0 text-xs text-muted-foreground">{{
                    open ? '▾' : '▸'
                }}</span>
            </button>

            <!-- Its own control, beside the row rather than inside it: every
                 pick is a choice the player can take back, and the optional two
                 must never become sticky just because they were tapped once. -->
            <button
                v-if="card"
                type="button"
                class="shrink-0 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                @click="emit('update:modelValue', null)"
            >
                clear
            </button>
        </div>

        <Transition name="unfold">
            <div
                v-if="open"
                class="space-y-2 border-t border-sidebar-border/50 p-2.5"
            >
                <VerbBoard
                    :cards="cards"
                    :selected-id="modelValue?.card_id ?? null"
                    @pick="pickVerb"
                    @clear="emit('update:modelValue', null)"
                />

                <TargetStrip
                    label="On what"
                    :options="targets"
                    :selected-key="chosenTargetKey"
                    @pick="pickTarget"
                />

                <HowPanel
                    v-if="card && modelValue"
                    :card="card"
                    :variants="variants"
                    :choice="modelValue"
                    :carried="carried"
                    @pick-card="pickVariant"
                    @update:choice="emit('update:modelValue', $event)"
                />
                <p v-else class="text-xs text-muted-foreground italic">
                    Pick a word to begin. Dim words are things this ground
                    offers no way to do.
                </p>
            </div>
        </Transition>
    </div>
</template>
