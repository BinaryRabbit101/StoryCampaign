<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { ActionCard, SlotChoice } from '@/types/game';

const props = defineProps<{
    title: string;
    hint: string;
    cards: ActionCard[];
    optional: boolean;
    modelValue: SlotChoice | null;
    /**
     * Sections open by default. The act does; the optional beats around it
     * do not — three fully expanded lists of a dozen rows each turned the
     * form into a page of scrolling before the player reached the one choice
     * they had to make.
     */
    openByDefault?: boolean;
    /**
     * What the beats chosen ahead of this one have already bought: engine
     * labels and amounts, handed down so a difficulty on this row can be
     * quoted against the plan actually being assembled rather than against
     * an empty one.
     */
    carriedBonus?: {
        label: string;
        amount: number;
        certain: boolean;
        verbs: string[] | null;
        slot: string | null;
    }[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: SlotChoice | null): void;
}>();

const open = ref(props.openByDefault ?? false);

// A section holding the current choice must never be closed out of sight —
// a chosen card the player cannot see is a choice they will forget making.
watch(
    () => props.modelValue,
    (choice) => {
        if (choice) {
            open.value = true;
        }
    },
);

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
 * Families, so a long list reads as a handful of decisions instead of twenty
 * peers. Purely presentational — the engine has no idea these exist, and a
 * verb it invents tomorrow simply lands in "Everything else" rather than
 * disappearing.
 */
const FAMILIES: { key: string; label: string; verbs: string[] }[] = [
    {
        key: 'fight',
        label: 'Fight',
        verbs: [
            'strike',
            'interrupt',
            'restrain',
            'hurl',
            'break',
            'brace',
            'shield',
            'haul',
        ],
    },
    {
        key: 'move',
        label: 'Move',
        verbs: [
            'flee',
            'cross',
            'ascend',
            'ride',
            'reposition',
            'track',
            'venture',
            'hide',
            'lift',
            'drop',
        ],
    },
    {
        key: 'talk',
        label: 'Talk',
        verbs: [
            'speak',
            'persuade',
            'deceive',
            'calm',
            'intimidate',
            'recruit',
            'command',
            'bargain',
        ],
    },
    {
        key: 'look',
        label: 'Look and listen',
        verbs: ['examine', 'inspect', 'scout', 'detect', 'wait'],
    },
    {
        key: 'tempo',
        label: 'Buy yourself time',
        verbs: [
            'time_slow',
            'haste',
            'ready',
            'catch_breath',
            'bandage',
            'recover',
            'loot',
        ],
    },
];

const familyOf = (verb: string) =>
    FAMILIES.find((f) => f.verbs.includes(verb))?.key ?? 'other';

interface Family {
    key: string;
    label: string;
    rows: Row[];
}

/**
 * Below this many rows a section is short enough to read at a glance, and
 * splitting it into families would add structure to something that never
 * needed any.
 */
const GROUPING_THRESHOLD = 6;

const families = computed<Family[]>(() => {
    if (rows.value.length <= GROUPING_THRESHOLD) {
        return [{ key: 'all', label: '', rows: rows.value }];
    }

    const order = [...FAMILIES.map((f) => f.key), 'other'];
    const labels = new Map([
        ...FAMILIES.map((f) => [f.key, f.label] as const),
        ['other', 'Everything else'] as const,
    ]);

    return order
        .map((key) => ({
            key,
            label: labels.get(key) ?? '',
            rows: rows.value.filter((r) => familyOf(r.cards[0].verb) === key),
        }))
        .filter((f) => f.rows.length > 0);
});

const grouped = computed(() => families.value.length > 1);

// Which family is unfolded. One at a time: the point is to shorten the page,
// and every family open at once is the old wall with headings on it.
const openFamily = ref<string | null>(null);

const familyIsOpen = (family: Family) =>
    !grouped.value ||
    openFamily.value === family.key ||
    family.rows.some((r) => rowSelection(r) !== null);

function toggleFamily(family: Family) {
    openFamily.value = openFamily.value === family.key ? null : family.key;
}

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

const chosenCard = computed(
    () => props.cards.find((c) => c.id === props.modelValue?.card_id) ?? null,
);

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

// ---- The odds, on the card ----
//
// A turn commits the moment it is submitted; there is no going back to
// re-pick once the difficulty turns out to have been 18. So every number the
// engine will roll against is printed here first, and it is the SAME number:
// the forecast comes from the engine's own ledger, per stance.

const stanceOf = (card: ActionCard) =>
    props.modelValue?.card_id === card.id
        ? (props.modelValue.modifiers.approach ?? 'balanced')
        : 'balanced';

/** What this card must beat, at the stance currently chosen for it. */
const difficultyOf = (card: ActionCard) =>
    card.forecast.stances[stanceOf(card)] ?? card.forecast.difficulty;

/** Bonuses standing now, plus whatever the earlier beats of this plan buy. */
function bonusesFor(card: ActionCard) {
    const own = card.forecast.bonus_parts.map((p) => ({ ...p, certain: true }));
    const inherited = (props.carriedBonus ?? []).filter(
        (b) =>
            !own.some((o) => o.label === b.label) &&
            (b.verbs === null || b.verbs.includes(card.verb)) &&
            (b.slot === null || b.slot === card.slot),
    );

    return [...own, ...inherited];
}

const bonusTotal = (card: ActionCard) =>
    bonusesFor(card).reduce((sum, b) => sum + b.amount, 0);

const bandClass = (band: string) =>
    ({
        Easy: 'text-emerald-700 dark:text-emerald-400',
        Medium: 'text-amber-700 dark:text-amber-400',
        Hard: 'text-orange-700 dark:text-orange-400',
        Certain: 'text-sky-700 dark:text-sky-400',
    })[band] ?? 'text-rose-700 dark:text-rose-400';

const stanceDelta = (card: ActionCard, value: string) => {
    const at = card.forecast.stances[value];
    const base = card.forecast.stances.balanced;

    return at === undefined || base === undefined ? null : at - base;
};

/** Which rows have their reasoning unfolded. */
const showWhy = ref<Set<string>>(new Set());

function toggleWhy(key: string) {
    const next = new Set(showWhy.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    showWhy.value = next;
}

const signed = (amount: number) =>
    `${amount > 0 ? '+' : '−'}${Math.abs(amount)}`;

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
    lift: 'How do you get under it?',
    drop: 'How do you set it down?',
    hurl: 'Where do you aim it?',
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
    <section
        class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        :class="modelValue ? 'border-violet-500/50' : ''"
    >
        <!-- The section header is the fold. Closed, it still says everything
             a player needs to decide whether to open it: what the beat is
             for, whether it is required, and what they already chose. -->
        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left transition-colors hover:bg-accent/40"
            @click="open = !open"
        >
            <span class="min-w-0">
                <span class="flex items-baseline gap-2">
                    <span class="text-sm font-semibold tracking-wide uppercase">
                        {{ title }}
                    </span>
                    <span
                        class="text-[10px] tracking-wide text-muted-foreground uppercase"
                    >
                        {{ optional ? 'optional' : 'required' }}
                    </span>
                </span>
                <span
                    class="mt-0.5 block truncate text-xs"
                    :class="
                        chosenCard
                            ? 'text-violet-600 dark:text-violet-400'
                            : 'text-muted-foreground'
                    "
                >
                    <template v-if="chosenCard">
                        {{ chosenCard.label
                        }}<template v-if="chosenCard.forecast.rolls">
                            · DC {{ difficultyOf(chosenCard) }}</template
                        >
                    </template>
                    <template v-else>{{ hint }}</template>
                </span>
            </span>
            <span
                class="flex shrink-0 items-center gap-2 text-muted-foreground"
            >
                <span v-if="!open" class="text-[10px] tabular-nums">{{
                    rows.length
                }}</span>
                <span
                    class="text-xs transition-transform duration-200"
                    :class="open ? 'rotate-90' : ''"
                    aria-hidden="true"
                    >▶</span
                >
            </span>
        </button>

        <Transition name="unfold">
            <div v-if="open" class="border-t border-sidebar-border/50 p-3">
                <p
                    v-if="!cards.length"
                    class="text-sm text-muted-foreground italic"
                >
                    Nothing offers itself for this beat.
                </p>

                <div v-else class="space-y-2">
                    <div v-for="family in families" :key="family.key">
                        <button
                            v-if="grouped"
                            type="button"
                            class="mb-1 flex w-full items-center justify-between gap-2 rounded-md px-1 py-1 text-left text-[11px] tracking-widest text-muted-foreground uppercase transition-colors hover:text-foreground"
                            @click="toggleFamily(family)"
                        >
                            <span>{{ family.label }}</span>
                            <span class="flex items-center gap-1.5">
                                <span class="tabular-nums">{{
                                    family.rows.length
                                }}</span>
                                <span
                                    class="transition-transform duration-200"
                                    :class="
                                        familyIsOpen(family) ? 'rotate-90' : ''
                                    "
                                    aria-hidden="true"
                                    >▸</span
                                >
                            </span>
                        </button>

                        <!-- One steady column: every card shows what it is,
                             what it risks, what it costs, and what it must
                             beat before anything is tapped. -->
                        <div v-if="familyIsOpen(family)" class="space-y-1.5">
                            <div
                                v-for="(row, index) in family.rows"
                                :key="row.key"
                                class="sc-rise cursor-pointer rounded-lg border px-3 py-2 transition-all duration-200 active:scale-[0.99]"
                                :class="
                                    rowSelection(row)
                                        ? 'border-violet-500 bg-violet-500/10 shadow-md ring-1 shadow-violet-500/10 ring-violet-500'
                                        : 'border-sidebar-border/70 hover:-translate-y-0.5 hover:bg-accent/50 hover:shadow-md hover:shadow-violet-500/5 dark:border-sidebar-border'
                                "
                                :style="{
                                    animationDelay: `${Math.min(index, 8) * 45}ms`,
                                }"
                                @click="tapRow(row)"
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <span class="text-sm font-medium">{{
                                        row.label
                                    }}</span>
                                    <span
                                        class="flex shrink-0 flex-col items-end gap-0.5"
                                    >
                                        <!-- The number the dice will be
                                             measured against, before the
                                             commit. Never a surprise. -->
                                        <span
                                            class="text-xs font-semibold tabular-nums"
                                            :class="
                                                bandClass(
                                                    rowCard(row).forecast.rolls
                                                        ? rowCard(row).forecast
                                                              .band
                                                        : 'Certain',
                                                )
                                            "
                                        >
                                            <template
                                                v-if="
                                                    rowCard(row).forecast.rolls
                                                "
                                                >DC
                                                {{
                                                    difficultyOf(rowCard(row))
                                                }}</template
                                            >
                                            <template v-else>no roll</template>
                                        </span>
                                        <span
                                            v-if="bonusTotal(rowCard(row))"
                                            class="text-[10px] text-emerald-700 tabular-nums dark:text-emerald-400"
                                            >{{
                                                signed(bonusTotal(rowCard(row)))
                                            }}
                                            to your roll</span
                                        >
                                    </span>
                                </div>

                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ rowCard(row).description }}
                                </p>

                                <div
                                    class="mt-1.5 flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase"
                                        :class="
                                            riskChipClass(rowCard(row).risk)
                                        "
                                        >{{
                                            riskLabel(rowCard(row).risk)
                                        }}</span
                                    >
                                    <span
                                        v-if="costLabel(rowCard(row))"
                                        class="text-xs text-violet-600 dark:text-violet-400"
                                        >{{ costLabel(rowCard(row)) }}</span
                                    >
                                    <!-- What this beat buys the ones after
                                         it, in the same units the difficulty
                                         is quoted in. -->
                                    <span
                                        v-if="rowCard(row).forecast.grant"
                                        class="rounded-full bg-sky-500/10 px-2 py-0.5 text-[10px] text-sky-700 dark:text-sky-300"
                                        >{{
                                            signed(
                                                rowCard(row).forecast.grant!
                                                    .amount,
                                            )
                                        }}
                                        after<template
                                            v-if="
                                                !rowCard(row).forecast.grant!
                                                    .certain
                                            "
                                        >
                                            — if it lands</template
                                        ></span
                                    >
                                    <button
                                        v-if="rowCard(row).forecast.rolls"
                                        type="button"
                                        class="ml-auto text-[10px] text-muted-foreground underline underline-offset-2 hover:text-foreground"
                                        @click.stop="toggleWhy(row.key)"
                                    >
                                        {{
                                            showWhy.has(row.key)
                                                ? 'hide the maths'
                                                : 'why?'
                                        }}
                                    </button>
                                    <span
                                        v-if="rowSelection(row) && optional"
                                        class="text-xs text-muted-foreground"
                                        title="Tap again to clear"
                                        >✕</span
                                    >
                                </div>

                                <!-- The ledger, itemized. Same lines the dice
                                     table shows afterwards, so a player can
                                     learn the game from either end. -->
                                <div
                                    v-if="
                                        showWhy.has(row.key) &&
                                        rowCard(row).forecast.rolls
                                    "
                                    class="mt-2 space-y-0.5 rounded-md bg-muted/60 p-2 text-[11px]"
                                    @click.stop
                                >
                                    <p
                                        v-for="part in rowCard(row).forecast
                                            .parts"
                                        :key="`d-${part.label}`"
                                        class="flex justify-between gap-3"
                                    >
                                        <span class="text-muted-foreground">{{
                                            part.label
                                        }}</span>
                                        <span class="tabular-nums">{{
                                            part.amount >= 0 &&
                                            part.label !== 'Base difficulty'
                                                ? signed(part.amount)
                                                : part.amount
                                        }}</span>
                                    </p>
                                    <p
                                        class="flex justify-between gap-3 border-t border-sidebar-border/50 pt-0.5 font-medium"
                                    >
                                        <span>What you must beat</span>
                                        <span class="tabular-nums"
                                            >{{ difficultyOf(rowCard(row)) }}
                                        </span>
                                    </p>
                                    <p
                                        v-for="bonus in bonusesFor(
                                            rowCard(row),
                                        )"
                                        :key="`b-${bonus.label}`"
                                        class="flex justify-between gap-3 text-emerald-700 dark:text-emerald-400"
                                    >
                                        <span
                                            >{{ bonus.label
                                            }}<template v-if="!bonus.certain">
                                                (if it lands)</template
                                            ></span
                                        >
                                        <span class="tabular-nums">{{
                                            signed(bonus.amount)
                                        }}</span>
                                    </p>
                                    <p class="pt-0.5 text-muted-foreground">
                                        You roll a d20{{
                                            bonusTotal(rowCard(row))
                                                ? ` ${signed(
                                                      bonusTotal(rowCard(row)),
                                                  )
                                                      .replace('+', '+ ')
                                                      .replace('−', '− ')}`
                                                : ''
                                        }}. Beat it by 5 for a strong result;
                                        miss by 4 or less and it still half
                                        works.
                                    </p>
                                </div>

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
                                        {{ card.target?.name ?? card.label
                                        }}<template v-if="card.forecast.rolls">
                                            · {{ difficultyOf(card) }}</template
                                        >
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
                                        v-for="modifier in rowSelection(row)!
                                            .modifiers"
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
                                                    modelValue?.modifiers[
                                                        modifier.key
                                                    ] === option.value
                                                        ? 'border-violet-600 bg-violet-600 text-white'
                                                        : 'border-input hover:bg-accent'
                                                "
                                                @click.stop="
                                                    setModifier(
                                                        modifier.key,
                                                        option.value,
                                                    )
                                                "
                                            >
                                                {{
                                                    option.label
                                                }}<!-- A stance that moves the
                                                     difficulty says by how
                                                     much, on the chip that
                                                     moves it. -->
                                                <span
                                                    v-if="
                                                        modifier.key ===
                                                            'approach' &&
                                                        stanceDelta(
                                                            rowSelection(row)!,
                                                            option.value,
                                                        )
                                                    "
                                                    class="ml-1 tabular-nums opacity-80"
                                                    >({{
                                                        signed(
                                                            stanceDelta(
                                                                rowSelection(
                                                                    row,
                                                                )!,
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

                                <!-- The nudge: how the player wants this one
                                     beat to unfold. It reaches the narrator as
                                     voice and never touches the engine's dice. -->
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
                                            >— colors the telling, changes
                                            nothing</span
                                        >
                                    </label>
                                    <textarea
                                        :value="modelValue?.note ?? ''"
                                        rows="2"
                                        maxlength="280"
                                        :placeholder="
                                            notePlaceholder(rowCard(row))
                                        "
                                        class="w-full resize-y rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus:border-violet-500/70 focus:outline-none"
                                        @input="
                                            setNote(
                                                (
                                                    $event.target as HTMLTextAreaElement
                                                ).value,
                                            )
                                        "
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </section>
</template>
