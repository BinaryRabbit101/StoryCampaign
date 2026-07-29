<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { ActionCard, VerbFamily } from '@/types/game';

/**
 * The board: nine words, every scene, in the same order.
 *
 * This is a pure LENS over the cards the composer already offered. It lights a
 * word when at least one card sits under it and never otherwise; every
 * selection terminates in an offered card id, so "never resolve a card the
 * engine didn't offer" holds by construction rather than by care.
 *
 * Unlit words are drawn dim and are not pickable. That is deliberate: "nothing
 * here to TAKE" is information about the ground, and a stable row is the whole
 * point — a board that reshuffles itself every scene teaches nobody anything.
 * A dim word is grammar, not a dead choice; it prices nothing because it
 * cannot be chosen.
 *
 * Membership is the engine's (App\Game\Verb::family) and arrives on the card.
 * The only thing decided here is the ORDER the nine are drawn in, which is a
 * matter of where the eye should land first and belongs to the page.
 */
const props = defineProps<{
    /** The main-slot cards, exactly as the engine offered them. */
    cards: ActionCard[];
    selectedId: string | null;
}>();

const emit = defineEmits<{
    (e: 'pick', card: ActionCard): void;
    /** Stepping into another word puts the beat already chosen down. */
    (e: 'clear'): void;
}>();

const ORDER: { key: VerbFamily; word: string }[] = [
    { key: 'look', word: 'Look' },
    { key: 'go', word: 'Go' },
    { key: 'take', word: 'Take' },
    { key: 'fight', word: 'Fight' },
    { key: 'speak', word: 'Speak' },
    { key: 'hide', word: 'Hide' },
    { key: 'tend', word: 'Tend' },
    { key: 'wait', word: 'Wait' },
    { key: 'do', word: 'Do' },
];

interface VerbGroup {
    verb: string;
    label: string;
    cards: ActionCard[];
}

interface BoardWord {
    key: string;
    word: string;
    verbs: VerbGroup[];
    count: number;
}

const board = computed<BoardWord[]>(() => {
    const byFamily = new Map<string, Map<string, ActionCard[]>>();

    for (const card of props.cards) {
        const family =
            byFamily.get(card.family) ?? new Map<string, ActionCard[]>();
        family.set(card.verb, [...(family.get(card.verb) ?? []), card]);
        byFamily.set(card.family, family);
    }

    // The nine in their fixed order, then anything the engine files under a
    // word this page has never heard of — never dropped, only appended.
    const keys = [
        ...ORDER.map((o) => o.key as string),
        ...[...byFamily.keys()].filter(
            (k) => !ORDER.some((o) => (o.key as string) === k),
        ),
    ];

    return keys.map((key) => {
        const verbs = [...(byFamily.get(key)?.entries() ?? [])].map(
            ([verb, cards]) => ({
                verb,
                // One card under a verb already says the whole sentence; more
                // than one and the verb has to name itself.
                label:
                    cards.length === 1 ? cards[0].label : cards[0].verb_label,
                cards,
            }),
        );

        return {
            key,
            word: ORDER.find((o) => (o.key as string) === key)?.word ?? key,
            verbs,
            count: verbs.reduce((sum, v) => sum + v.cards.length, 0),
        };
    });
});

const chosen = computed(
    () => props.cards.find((c) => c.id === props.selectedId) ?? null,
);

/** Which word's drawer is unfolded. One at a time — the row is the point. */
const openWord = ref<string | null>(null);

// A word chosen elsewhere (a target switch, a reload) opens its own drawer, so
// the player can always see the verb their card is standing under.
watch(chosen, (card) => {
    if (
        card &&
        (board.value.find((w) => w.key === card.family)?.verbs.length ?? 0) > 1
    ) {
        openWord.value = card.family;
    }
});

/**
 * Whether a word reads as chosen.
 *
 * A word carrying several verbs used to answer a tap by opening its drawer and
 * lighting nothing — so the press produced a list somewhere below and no mark on
 * the thing that had just been pressed, which reads as a tap that missed. A word
 * whose drawer is open is a word the player is inside; it lights.
 *
 * Exactly one word is ever lit, which is the whole reason `tapWord` puts the
 * previous beat down when it steps into a different word. Two lit words meant
 * "Speak with Mara" in the header and LOOK glowing beside SPEAK, and no way to
 * tell which one the turn was about to resolve.
 */
const lit = (word: BoardWord) =>
    props.selectedId !== null && chosen.value?.family === word.key
        ? true
        : openWord.value === word.key;

/** The honest version first: a deal is never what a tap lands on by default. */
const firstOf = (cards: ActionCard[]) =>
    cards.find((c) => c.bargain === null) ?? cards[0];

function tapWord(word: BoardWord) {
    if (word.count === 0) {
        return;
    }

    // One verb under the word is the common case: the tap IS the choice, and
    // it replaces whatever was chosen before all by itself.
    if (word.verbs.length === 1) {
        openWord.value = null;
        emit('pick', firstOf(word.verbs[0].cards));

        return;
    }

    const opening = openWord.value !== word.key;
    openWord.value = opening ? word.key : null;

    // Opening a DIFFERENT word puts the beat already chosen down. Browsing
    // Look while Speak stayed selected lit two words at once and left the
    // header describing a beat the player had visibly moved on from — and the
    // reading that resolves is always the header's, which is exactly the kind
    // of thing a turn that commits on submit must never be ambiguous about.
    //
    // Collapsing a word's own drawer is not stepping away, so a player who
    // opens their own word and closes it again keeps their beat.
    if (opening && chosen.value !== null && chosen.value.family !== word.key) {
        emit('clear');
    }
}

function tapVerb(group: VerbGroup) {
    emit('pick', firstOf(group.cards));
}
</script>

<template>
    <div>
        <div class="grid grid-cols-3 gap-1.5 sm:grid-cols-9">
            <button
                v-for="word in board"
                :key="word.key"
                type="button"
                :disabled="word.count === 0"
                :aria-pressed="lit(word)"
                class="flex flex-col items-center gap-0.5 rounded-lg border px-1 py-2 text-[11px] font-semibold tracking-widest uppercase transition-all duration-200 active:scale-95"
                :class="
                    word.count === 0
                        ? 'cursor-default border-dashed border-sidebar-border/50 text-muted-foreground/40'
                        : lit(word)
                          ? 'border-violet-500 bg-violet-500/10 text-violet-700 ring-1 ring-violet-500 dark:text-violet-300'
                          : 'border-sidebar-border/70 hover:-translate-y-0.5 hover:bg-accent/60 dark:border-sidebar-border'
                "
                :title="
                    word.count === 0
                        ? `Nothing here to ${word.word.toLowerCase()}`
                        : undefined
                "
                @click="tapWord(word)"
            >
                <span>{{ word.word }}</span>
                <span
                    class="text-[9px] font-normal tabular-nums"
                    :class="word.count === 0 ? 'opacity-0' : 'opacity-60'"
                    aria-hidden="true"
                    >{{ word.count || '·' }}</span
                >
            </button>
        </div>

        <!-- The second level: the engine's own verbs under the chosen word.
             Collapsed away entirely when a word opens onto a single verb,
             which is the common case. -->
        <Transition name="unfold">
            <div
                v-if="openWord"
                class="mt-2 flex flex-wrap gap-1 rounded-lg border border-sidebar-border/70 p-2 dark:border-sidebar-border"
            >
                <button
                    v-for="group in board.find((w) => w.key === openWord)
                        ?.verbs ?? []"
                    :key="group.verb"
                    type="button"
                    class="rounded-full border px-2.5 py-1 text-xs transition active:scale-95"
                    :class="
                        chosen?.verb === group.verb
                            ? 'border-violet-600 bg-violet-600 text-white'
                            : 'border-input hover:bg-accent'
                    "
                    @click="tapVerb(group)"
                >
                    {{ group.label
                    }}<span
                        v-if="group.cards.length > 1"
                        class="ml-1 opacity-70"
                        >· {{ group.cards.length }}</span
                    >
                </button>
            </div>
        </Transition>
    </div>
</template>
