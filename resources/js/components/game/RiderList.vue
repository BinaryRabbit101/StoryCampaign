<script setup lang="ts">
import { computed, ref } from 'vue';
import OddsLedger from '@/components/game/OddsLedger.vue';
import TargetStrip from '@/components/game/TargetStrip.vue';
import {
    costLabel,
    difficultyAt,
    endeavorLine,
    grantLine,
    riskLabel,
    signed,
    stanceDelta,
    targetKey,
} from '@/lib/odds';
import type { CarriedBonus, TargetOption } from '@/lib/odds';
import type { ActionCard, SlotChoice } from '@/types/game';

/**
 * The beats that hang off the act.
 *
 * A set-up beat's whole value is what it hands the act — so it is presented AS
 * that: one line quoting the delta the engine will actually apply ("Steady
 * yourself — +2 to this act"), not a third form to scroll past. Two parallel
 * pickers presented as equal decisions is why nobody ever opened them; they
 * were subordinate choices wearing the costume of peers.
 *
 * The composer's own gates still decide what exists here — bandage only when
 * hurt, loot only when there is a body. This end only decides what leads: the
 * riders that bear on the chosen act stand first, and the rest fold away
 * within reach. Nothing the engine offered is ever removed.
 */
const props = defineProps<{
    title: string;
    hint?: string;
    cards: ActionCard[];
    modelValue: SlotChoice | null;
    /** The act these riders hang off, for quoting what each one buys it. */
    actVerb: string | null;
    carried?: CarriedBonus[];
    /** Set-up beats lead with what they grant; consequences are already gated. */
    foldOthers?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', choice: SlotChoice | null): void;
}>();

interface Row {
    key: string;
    label: string;
    cards: ActionCard[];
    line: string | null;
}

const rows = computed<Row[]>(() => {
    const grouped = new Map<string, ActionCard[]>();

    for (const card of props.cards) {
        // A deal is its own row, never a target chip on its plain sibling: it
        // is a different beat at a different price, and folding the two
        // together would hide the price behind something that reads as a name.
        const key = card.target
            ? `${card.verb}:${card.capability ?? ''}:${card.bargain?.key ?? ''}`
            : `card:${card.id}`;
        grouped.set(key, [...(grouped.get(key) ?? []), card]);
    }

    return [...grouped.entries()].map(([key, cards]) => ({
        key,
        label: cards.length > 1 ? cards[0].verb_label : cards[0].label,
        cards,
        // What this rider is FOR, in one line: the bonus it hands the act,
        // or — failing that — the endeavor it moves. Both come off the
        // engine's forecast; neither is worked out here.
        line: grantLine(cards[0], props.actVerb) ?? endeavorLine(cards[0]),
    }));
});

const leading = computed(() =>
    props.foldOthers ? rows.value.filter((r) => r.line !== null) : rows.value,
);
const folded = computed(() =>
    props.foldOthers ? rows.value.filter((r) => r.line === null) : [],
);

const showFolded = ref(false);

const selectedIn = (row: Row) =>
    row.cards.find((c) => c.id === props.modelValue?.card_id) ?? null;
const shownCard = (row: Row) => selectedIn(row) ?? row.cards[0];

const stanceOf = (card: ActionCard) =>
    card.id === props.modelValue?.card_id
        ? (props.modelValue.modifiers.approach ?? 'balanced')
        : 'balanced';

const dc = (card: ActionCard) =>
    card.forecast.rolls ? difficultyAt(card, stanceOf(card)) : null;

function choiceFor(card: ActionCard, note = ''): SlotChoice {
    const modifiers: Record<string, string> = {};

    for (const modifier of card.modifiers) {
        modifiers[modifier.key] = modifier.options[0]?.value ?? '';
    }

    return { card_id: card.id, modifiers, note };
}

function tapRow(row: Row) {
    // Every one of these is optional, so tapping the chosen one clears it.
    emit('update:modelValue', selectedIn(row) ? null : choiceFor(row.cards[0]));
}

const targetsOf = (row: Row): TargetOption[] =>
    row.cards.map((card) => ({
        key: targetKey(card),
        name: card.target?.name ?? card.label,
        difficulty: dc(card),
        risk: card.risk,
    }));

function pickTarget(row: Row, key: string) {
    const card = row.cards.find((c) => targetKey(c) === key);

    if (card && card.id !== props.modelValue?.card_id) {
        // Words already typed survive a change of target: the note was about
        // the beat, not about which chip it points at.
        emit(
            'update:modelValue',
            choiceFor(card, props.modelValue?.note ?? ''),
        );
    }
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
    if (props.modelValue) {
        emit('update:modelValue', { ...props.modelValue, note });
    }
}
</script>

<template>
    <section v-if="rows.length">
        <p class="mb-1 flex items-baseline gap-2">
            <span class="text-xs font-semibold tracking-wide uppercase">{{
                title
            }}</span>
            <span
                v-if="hint"
                class="text-[10px] tracking-wide text-muted-foreground"
                >{{ hint }}</span
            >
        </p>

        <div class="space-y-1">
            <div
                v-for="row in [...leading, ...(showFolded ? folded : [])]"
                :key="row.key"
                class="cursor-pointer rounded-lg border px-2.5 py-1.5 transition-all duration-200 active:scale-[0.99]"
                :class="
                    selectedIn(row)
                        ? 'border-violet-500 bg-violet-500/10 ring-1 ring-violet-500'
                        : 'border-sidebar-border/70 hover:bg-accent/50 dark:border-sidebar-border'
                "
                @click="tapRow(row)"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs">
                        {{ row.label }}
                        <span
                            v-if="row.line"
                            class="text-emerald-700 dark:text-emerald-400"
                            >— {{ row.line }}</span
                        >
                    </span>
                    <span
                        class="shrink-0 text-[10px] text-muted-foreground tabular-nums"
                    >
                        <template v-if="dc(shownCard(row)) !== null"
                            >DC {{ dc(shownCard(row)) }}</template
                        >
                        <template v-else>no roll</template>
                        <template v-if="shownCard(row).risk !== 'safe'">
                            · {{ riskLabel(shownCard(row).risk) }}</template
                        >
                        <template v-if="costLabel(shownCard(row))">
                            · {{ costLabel(shownCard(row)) }}</template
                        >
                    </span>
                </div>

                <!-- The deal, both halves, at equal weight. -->
                <dl
                    v-if="shownCard(row).bargain"
                    class="mt-1 grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-[11px]"
                >
                    <dt class="tracking-wide text-muted-foreground uppercase">
                        Gain
                    </dt>
                    <dd>{{ shownCard(row).bargain!.edge_label }}</dd>
                    <dt class="tracking-wide text-muted-foreground uppercase">
                        Cost
                    </dt>
                    <dd>{{ shownCard(row).bargain!.complication_label }}</dd>
                </dl>

                <template v-if="selectedIn(row)">
                    <p class="mt-1 text-[11px] text-muted-foreground">
                        {{ selectedIn(row)!.description }}
                    </p>

                    <div class="mt-1.5" @click.stop>
                        <TargetStrip
                            :options="targetsOf(row)"
                            :selected-key="targetKey(selectedIn(row)!)"
                            @pick="(key) => pickTarget(row, key)"
                        />
                    </div>

                    <div
                        v-if="selectedIn(row)!.modifiers.length"
                        class="mt-1.5 space-y-1.5"
                        @click.stop
                    >
                        <div
                            v-for="modifier in selectedIn(row)!.modifiers"
                            :key="modifier.key"
                        >
                            <div
                                class="mb-1 text-[10px] tracking-widest text-muted-foreground uppercase"
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
                                    @click="
                                        setModifier(modifier.key, option.value)
                                    "
                                >
                                    {{ option.label
                                    }}<span
                                        v-if="
                                            modifier.key === 'approach' &&
                                            stanceDelta(
                                                selectedIn(row)!,
                                                option.value,
                                            )
                                        "
                                        class="ml-1 tabular-nums opacity-80"
                                        >({{
                                            signed(
                                                stanceDelta(
                                                    selectedIn(row)!,
                                                    option.value,
                                                )!,
                                            )
                                        }}
                                        DC)</span
                                    >
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-1.5" @click.stop>
                        <OddsLedger
                            :card="selectedIn(row)!"
                            :stance="stanceOf(selectedIn(row)!)"
                            :carried="carried"
                            collapsible
                        />
                    </div>

                    <textarea
                        :value="modelValue?.note ?? ''"
                        rows="2"
                        maxlength="280"
                        placeholder="In your own words — colors the telling, changes nothing"
                        class="mt-1.5 w-full resize-y rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus:border-violet-500/70 focus:outline-none"
                        @click.stop
                        @input="
                            setNote(
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                    />
                </template>
            </div>
        </div>

        <button
            v-if="folded.length"
            type="button"
            class="mt-1 text-[11px] text-muted-foreground underline underline-offset-2 hover:text-foreground"
            @click="showFolded = !showFolded"
        >
            {{
                showFolded
                    ? 'fewer'
                    : `${folded.length} more, none of which change this act`
            }}
        </button>
    </section>
</template>
