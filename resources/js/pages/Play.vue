<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    computed,
    defineAsyncComponent,
    onMounted,
    onUnmounted,
    ref,
} from 'vue';
import AmbientBackdrop from '@/components/game/AmbientBackdrop.vue';
import BeatPicker from '@/components/game/BeatPicker.vue';
import RecapPanel from '@/components/game/RecapPanel.vue';
import RiderList from '@/components/game/RiderList.vue';
import { costClass, reasonsFor, signed } from '@/lib/odds';
import { enablePush } from '@/lib/push';
import type {
    ActionCard,
    CarriedThing,
    ChapterEntity,
    ChapterEvent,
    CharacterItem,
    CharacterMeters,
    Endeavor,
    GrowthMessage,
    Memento,
    RecapPanel as RecapPanelData,
    RollTable,
    SituationGroup,
    SlotChoice,
    TurnCards,
} from '@/types/game';

const props = defineProps<{
    campaign: { id: number; name: string; status: string };
    character: {
        name: string;
        description: string;
        status: string;
        meters: CharacterMeters;
        /** Scene matter physically in their hands — not the items they own. */
        carrying: CarriedThing[];
        hands_free: number;
        capabilities: {
            capability: string;
            magnitude: number | null;
            grade: string | null;
            scope: Record<string, string> | null;
            source: string | null;
        }[];
        constraints: {
            name: string;
            params: Record<string, unknown> | null;
            coupled_capability: string | null;
        }[];
        /**
         * Permanent marks taken in play, not chosen. Empty until the first
         * fall — a body with nothing to show gets no line about it.
         */
        scars: {
            key: string;
            label: string;
            description: string;
            fact: string;
            chapter_id: number | null;
        }[];
        items: CharacterItem[];
    };
    turn: {
        id: number;
        number: number;
        status: string;
        situation: string;
        /** The same facts as `situation`, grouped. Null on pre-board turns. */
        board: SituationGroup[] | null;
        cards: TurnCards | null;
    } | null;
    /**
     * The multi-turn goal the player took on, if they took one on. Null the
     * rest of the time — one endeavor at a time, and most of the time none.
     */
    endeavor: Endeavor | null;
    /**
     * The shelf. What notable moments left behind, oldest first — read-only
     * in the strictest sense: there is nothing here to equip, spend, or use.
     */
    mementos: Memento[];
    /** The evolution conversation: what was asked, and how the world answered. */
    growth: GrowthMessage[];
    /**
     * Previously, on this tale. Null unless the player has genuinely been
     * away — and informational even then: it sits above the form, closes on a
     * word, and gates nothing.
     */
    recap: RecapPanelData | null;
    /**
     * A turn is resolved but Claude has not written its chapter yet. Turns
     * resolve inline now, so this is the only wait left in the game.
     */
    narrating: boolean;
    /**
     * That same wait, gone on too long to still be a wait. The chapter is not
     * being written — the narrator is failing and the sweep is retrying it
     * every minute. The page says so, and gives the story back.
     */
    narrationStalled: boolean;
    latestChapter: {
        number: number;
        kind: string;
        intent_line: string | null;
        body: string;
        events: ChapterEvent[];
        entities: ChapterEntity[];
    } | null;
    /**
     * The dice a resolved turn cast, when the player has not watched them
     * fall yet. It stands between them and the chapter exactly once — the
     * engine and the narrator ran on their own schedule regardless.
     */
    rollTable: RollTable | null;
}>();

// The 3D renderer is most of a megabyte and is wanted on maybe one page load
// in three. It arrives with the table, not with the play page.
const DiceTable = defineAsyncComponent(
    () => import('@/components/game/DiceTable.vue'),
);

/**
 * The turn being assembled: three beats, keyed by the slot they resolve in.
 *
 * One record rather than three refs, because the three picks are now the same
 * offer read three times and the page walks them as a list.
 */
const plan = ref<Record<'pre' | 'main' | 'post', SlotChoice | null>>({
    pre: null,
    main: null,
    post: null,
});
// One independent request per companion, keyed by companion id — their own
// beat, never a claim on the player's three slots.
const companionChoices = ref<Record<number, SlotChoice | null>>({});
const submitting = ref(false);
const showSheet = ref(false);
const showGrowth = ref(false);
const showEnd = ref(false);
const growthText = ref('');

// Two different waits, and only the second one still exists in normal play.
// `locked` is a turn caught mid-resolution — milliseconds, or a crashed
// request the sweep is about to recover. `narrating` is Claude writing the
// chapter the player's dice have already decided.
const locked = computed(
    () => props.turn !== null && props.turn.status !== 'awaiting_player',
);
const waiting = computed(() => locked.value || props.narrating);

// A stalled narration is NOT a wait. Hiding the chapter is only honest while
// a newer one is genuinely coming; once it has stopped coming, hiding is just
// withholding the story the player already owns. So the chapter comes back,
// with the failure stated above it.
const stalled = computed(() => props.narrationStalled && !locked.value);

// The board stands beside every chapter, not instead of one.
//
// It used to hide whenever a real chapter was on screen, on the theory that
// the prose already ends inside the current moment. It does — but the reader
// who most needs to check what is standing where is the one mid-fight with a
// chapter in hand, and making them reconstruct the cast from a paragraph is
// the opposite of help. Empty groups are simply absent, so a quiet place
// shows a short board rather than a list of reassurances nobody asked for.
const board = computed<SituationGroup[]>(() => props.turn?.board ?? []);

// Turns opened before the board existed still carry the prose they were
// written with. Nothing is lost on an old save; it just reads as one line.
const legacySituation = computed(() =>
    board.value.length === 0 ? (props.turn?.situation ?? '') : '',
);

const TONE_DOTS: Record<SituationGroup['tone'], string> = {
    foe: 'bg-red-500',
    ally: 'bg-amber-500',
    person: 'bg-teal-500',
    ground: 'bg-[#8a5a33]',
    self: 'bg-violet-500',
    neutral: 'bg-muted-foreground/50',
};

// ---- Anchors in the prose ----
//
// Two kinds, one detail card. [[eN]] tokens the narrator placed become
// tappable icons for what the engine resolved; the names of the people and
// the ground the scene holds become tappable words, so a reader can tell at
// a glance which nouns in the chapter are things they can act on.

const ICONS: Record<string, string> = {
    attack: '⚔️',
    injury: '🩸',
    heal: '✚',
    highground: '⛰️',
    loot: '🪙',
    stealth: '🌘',
    parley: '💬',
    force: '💥',
    move: '🏃',
    tempo: '⏳',
    gambit: '✨',
    threat: '⚠️',
    defense: '🛡️',
    ally: '🤝',
    study: '🔍',
    skipped: '⊘',
    beat: '✦',
    enemy: '🗡️',
    person: '🧍',
    ground: '🧱',
};

const icon = (name: string) => ICONS[name] ?? ICONS.beat;

// A dotted underline alone was too quiet to notice inside a paragraph of
// serif prose, so every anchored noun also carries a slight colour for what
// it is: a foe reads red, a companion gold, a stranger cool, and the ground
// brown. Kept low-saturation on purpose — this has to read as ink the story
// was written in, not as a set of links pasted over it. Brown is an arbitrary
// value because Tailwind has no brown; the light and dark pairs are picked to
// hold contrast against parchment and night both.
const TONES: Record<ChapterEntity['tone'], { text: string; line: string }> = {
    foe: {
        text: 'text-red-800 dark:text-red-400',
        line: 'decoration-red-700/60 dark:decoration-red-400/60',
    },
    ally: {
        text: 'text-amber-700 dark:text-amber-300',
        line: 'decoration-amber-600/60 dark:decoration-amber-300/60',
    },
    person: {
        text: 'text-teal-800 dark:text-teal-300',
        line: 'decoration-teal-700/60 dark:decoration-teal-300/60',
    },
    ground: {
        text: 'text-[#8a5a33] dark:text-[#c9a276]',
        line: 'decoration-[#8a5a33]/60 dark:decoration-[#c9a276]/60',
    },
};

const tone = (entity: ChapterEntity) => TONES[entity.tone] ?? TONES.ground;

/** Whatever the tapped anchor has to say, in one shape. */
interface Detail {
    key: string;
    icon: string;
    title: string;
    titleClass: string;
    badge: string | null;
    lines: string[];
    note: string | null;
    roll: ChapterEvent['roll'];
}

const detail = ref<Detail | null>(null);

// The card opens beside whatever was tapped, wherever that was.
//
// It used to be positioned inside the chapter article, which was fine while the
// chapter was the only thing carrying anchors. The situation board carries them
// now too, and an anchor there would have opened its card several hundred pixels
// up the page inside a different element. So the panel is anchored to the
// viewport instead, off the clicked element's own rectangle, and it flips above
// the anchor when there is no room below.
const detailPos = ref({ top: 0, left: 0, flip: false });

const detailEl = ref<HTMLElement | null>(null);

function openDetail(next: Detail, e: MouseEvent) {
    if (detail.value?.key === next.key) {
        detail.value = null;
        return;
    }
    detail.value = next;

    const anchor = e.currentTarget as HTMLElement | null;
    if (!anchor) return;

    const rect = anchor.getBoundingClientRect();
    const width = Math.min(384, window.innerWidth - 24);
    const flip = rect.bottom > window.innerHeight - 240;

    detailPos.value = {
        top: flip ? rect.top - 6 : rect.bottom + 6,
        left: Math.min(
            Math.max(12, rect.left - 12),
            Math.max(12, window.innerWidth - width - 12),
        ),
        flip,
    };
}

// Anywhere outside dismisses it. The card sits over the prose the reader is
// trying to read, so hunting for the ✕ is the wrong amount of work — but the
// anchors have to be exempt, or their own toggle would fight this: pointerdown
// closes the card a beat before the anchor's click would reopen it, and
// tapping the open anchor a second time would never close anything.
function dismissDetail(e: Event) {
    if (!detail.value) return;
    const target = e.target as HTMLElement | null;
    if (target?.closest('[data-anchor]') || detailEl.value?.contains(target)) {
        return;
    }
    detail.value = null;
}

function dismissOnEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') detail.value = null;
}

// The card is pinned to the viewport and its anchor is not, so a scroll would
// leave the two pointing at different things. Closing is the honest answer.
function dismissOnScroll() {
    detail.value = null;
}

const degreeClass = (event: ChapterEvent) =>
    event.skipped
        ? 'text-muted-foreground'
        : event.degree === 'failure'
          ? 'text-red-600 dark:text-red-400'
          : event.degree === 'partial'
            ? 'text-amber-600 dark:text-amber-400'
            : 'text-emerald-600 dark:text-emerald-400';

const eventDetail = (event: ChapterEvent): Detail => ({
    key: `event:${event.id}`,
    icon: icon(event.icon),
    title: event.label,
    titleClass: degreeClass(event),
    badge: event.slot,
    lines: event.facts,
    note: event.note,
    roll: event.roll,
});

const entityDetail = (entity: ChapterEntity): Detail => ({
    key: `entity:${entity.key}`,
    icon: icon(entity.icon),
    title: entity.name,
    titleClass: tone(entity).text,
    badge: entity.title,
    lines: entity.lines,
    note: null,
    roll: null,
});

function openEvent(event: ChapterEvent, e: MouseEvent) {
    openDetail(eventDetail(event), e);
}

function openEntity(entity: ChapterEntity, e: MouseEvent) {
    openDetail(entityDetail(entity), e);
}

const eventsById = computed(
    () => new Map((props.latestChapter?.events ?? []).map((e) => [e.id, e])),
);

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// One alternation over every form the engine says these things answer to,
// longest first so a long name always wins over a shorter one nested inside
// it. The engine decides which short forms are safe to claim; the page only
// looks for them.
const entityMatcher = computed(() => {
    const variants: { pattern: string; entity: ChapterEntity }[] = [];
    for (const entity of props.latestChapter?.entities ?? []) {
        for (const pattern of entity.aliases) {
            if (pattern.trim()) variants.push({ pattern, entity });
        }
    }
    if (!variants.length) return null;

    variants.sort((a, b) => b.pattern.length - a.pattern.length);

    return {
        pattern: new RegExp(
            `\\b(${variants.map((v) => escapeRegExp(v.pattern)).join('|')})\\b`,
            'gi',
        ),
        byName: new Map(
            variants.map((v) => [v.pattern.toLowerCase(), v.entity]),
        ),
    };
});

interface Segment {
    text: string;
    event: ChapterEvent | null;
    entity: ChapterEntity | null;
}

/**
 * One run of text, split around every name the engine says can be touched.
 *
 * Used by the chapter and by the situation board both: the board names the same
 * people and the same ground the prose does, and a name that is tappable in a
 * paragraph and dead in a list two inches below it teaches the player that the
 * anchors are decoration.
 */
function entitySegments(text: string): Segment[] {
    const matcher = entityMatcher.value;

    if (!matcher || !text) {
        return [{ text, event: null, entity: null }];
    }

    const segments: Segment[] = [];
    let cursor = 0;
    matcher.pattern.lastIndex = 0;

    for (const hit of text.matchAll(matcher.pattern)) {
        const entity = matcher.byName.get(hit[1].toLowerCase());
        if (!entity || hit.index === undefined) continue;
        if (hit.index > cursor) {
            segments.push({
                text: text.slice(cursor, hit.index),
                event: null,
                entity: null,
            });
        }
        segments.push({ text: hit[0], event: null, entity });
        cursor = hit.index + hit[0].length;
    }

    if (cursor < text.length) {
        segments.push({ text: text.slice(cursor), event: null, entity: null });
    }

    return segments;
}

// The chapter body split around both kinds of anchor. Unknown [[eN]] tokens
// vanish silently; the stored body itself is never rewritten.
const bodySegments = computed<Segment[]>(() => {
    const body = props.latestChapter?.body ?? '';
    const segments: Segment[] = [];

    for (const part of body.split(/(\[\[e\d+\]\])/)) {
        const token = part.match(/^\[\[(e\d+)\]\]$/);
        if (token) {
            segments.push({
                text: '',
                event: eventsById.value.get(token[1]) ?? null,
                entity: null,
            });
            continue;
        }

        segments.push(...entitySegments(part));
    }

    return segments;
});

/**
 * Every moment of record from the last chapter, in one row.
 *
 * The same icons the prose carries, gathered where the form is. They are read
 * for the same reason the board is — to price what to do next — and reaching
 * them meant scrolling back up through a page of serif prose to hunt for a
 * glyph mid-sentence.
 */
const chapterMoments = computed(() => props.latestChapter?.events ?? []);

// Events the narrator failed to anchor (or pre-feature chapters): still shown,
// as a row of moments under the chapter, so no data is ever lost.
const unanchoredEvents = computed(() => {
    const body = props.latestChapter?.body ?? '';
    return (props.latestChapter?.events ?? []).filter(
        (e) => !body.includes(`[[${e.id}]]`),
    );
});

// ---- The turn: three beats, one list ----
//
// The engine offers the SAME cards for all three positions now, so the three
// picks are three readings of one list rather than an act flanked by two short
// piles of leftovers. Order still means everything — a set-up beat is only worth
// anything ahead of the thing it sets up — so the picks are numbered and resolve
// in that order, and each one is priced against what the ones ahead of it grant.
//
// Nothing here composes anything. Every tap terminates in a card id the engine
// offered FOR THAT SLOT, and the payload leaving this page is the same shape it
// always was: {pre?, main, post?, companions} of ids, modifiers, and notes.

const STEPS = [
    {
        slot: 'pre' as const,
        step: 1,
        title: 'First',
        hint: 'before the act',
        required: false,
    },
    {
        slot: 'main' as const,
        step: 2,
        title: 'The act',
        hint: 'the beat this turn turns on',
        required: true,
    },
    {
        slot: 'post' as const,
        step: 3,
        title: 'Then',
        hint: 'if the moment still allows it',
        required: false,
    },
];

/** One pick open at a time; the act is what the form opens on. */
const openStep = ref<'pre' | 'main' | 'post' | null>('main');

function toggleStep(slot: 'pre' | 'main' | 'post') {
    openStep.value = openStep.value === slot ? null : slot;
}

const cardsIn = (slot: 'pre' | 'main' | 'post'): ActionCard[] =>
    props.turn?.cards?.[slot] ?? [];

const cardFor = (slot: 'pre' | 'main' | 'post'): ActionCard | null => {
    const choice = plan.value[slot];

    return choice === null
        ? null
        : (cardsIn(slot).find((c) => c.id === choice.card_id) ?? null);
};

const mainCard = computed<ActionCard | null>(() => cardFor('main'));

/**
 * The grants a set of chosen cards hands to whatever resolves after them.
 *
 * The engine states each one on the card that buys it ("readied — +2 to whatever
 * comes next"), so the page never re-derives the rules; it only adds up what the
 * player has actually picked.
 */
function grantsOf(cards: ActionCard[]) {
    return cards
        .map((card) => card.forecast.grant)
        .filter((grant): grant is NonNullable<typeof grant> => grant !== null)
        .map((grant) => ({
            label: grant.label,
            amount: grant.amount,
            certain: grant.certain,
            verbs: grant.verbs,
            slot: grant.slot,
        }));
}

/** The companion requests standing on this turn, as cards. */
const companionCards = computed<ActionCard[]>(() => {
    const chosen: ActionCard[] = [];

    for (const companion of props.turn?.cards?.companions ?? []) {
        const choice = companionChoices.value[companion.id];
        if (!choice) continue;
        const card = companion.cards.find((c) => c.id === choice.card_id);
        if (card) chosen.push(card);
    }

    return chosen;
});

/**
 * What is already promised to a given pick by the time it resolves.
 *
 * Resolution order is pre → companions → main → post, and the quoting follows it
 * exactly: nothing is ahead of the first beat, and a companion's flank cannot
 * help the beat the player already spent before asking for it.
 */
function carriedFor(slot: 'pre' | 'main' | 'post' | 'companion') {
    const preCard = cardFor('pre');
    const ahead: ActionCard[] = preCard ? [preCard] : [];

    if (slot === 'pre') {
        return grantsOf([]);
    }

    if (slot === 'companion') {
        return grantsOf(ahead);
    }

    ahead.push(...companionCards.value);

    if (slot === 'post') {
        const act = cardFor('main');
        if (act) ahead.push(act);
    }

    return grantsOf(ahead);
}

/** What a companion's request is priced against: the set-up beat ahead of it. */
const companionCarried = computed(() => carriedFor('companion'));

// Staged, visible resource commitment: the running cost of the whole chain.
const runningCost = computed(() => {
    const totals: Record<string, number> = {};

    for (const step of STEPS) {
        for (const cost of cardFor(step.slot)?.cost ?? []) {
            totals[cost.meter] = (totals[cost.meter] ?? 0) + cost.amount;
        }
    }

    return Object.entries(totals);
});

let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    // The only wait left is Claude writing, and it is short enough to poll
    // properly rather than sample: every couple of seconds until the chapter
    // lands, then nothing.
    // Keep polling through a stall too, at a slower beat: the chapter is
    // still coming the moment the narrator recovers, and the player should
    // not have to reload to find that out.
    timer = setInterval(() => {
        if (waiting.value || stalled.value)
            router.reload({
                only: [
                    'turn',
                    'latestChapter',
                    'character',
                    'narrating',
                    'narrationStalled',
                ],
            });
    }, 2500);
    void enablePush();
    document.addEventListener('pointerdown', dismissDetail);
    document.addEventListener('keydown', dismissOnEscape);
    window.addEventListener('scroll', dismissOnScroll, { passive: true });
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
    document.removeEventListener('pointerdown', dismissDetail);
    document.removeEventListener('keydown', dismissOnEscape);
    window.removeEventListener('scroll', dismissOnScroll);
});

// The table is shown once. Stamping it server-side means the same dice never
// fall twice — not on a reload, and not on the other device.
const clearingRolls = ref(false);

function rollsSeen() {
    if (!props.rollTable || clearingRolls.value) return;
    clearingRolls.value = true;
    router.post(
        `/play/${props.campaign.id}/rolls-seen`,
        { turn_id: props.rollTable.turn_id },
        {
            preserveScroll: true,
            onFinish: () => (clearingRolls.value = false),
        },
    );
}

function submit() {
    if (!plan.value.main || submitting.value) return;
    submitting.value = true;
    router.post(
        `/play/${props.campaign.id}`,
        {
            pre: plan.value.pre,
            main: plan.value.main,
            post: plan.value.post,
            companions: companionChoices.value,
        },
        {
            onSuccess: () => {
                plan.value = { pre: null, main: null, post: null };
                companionChoices.value = {};
                openStep.value = 'main';
            },
            onFinish: () => (submitting.value = false),
        },
    );
}

const asking = ref(false);

/**
 * Ask the world to change you.
 *
 * The panel deliberately stays OPEN after the answer lands. Closing it was
 * the whole bug: the request went off, the world wrote a reply, and the
 * player was returned to a page that looked exactly as it had before —
 * no answer, no verdict, no way to tell the ask from a no-op.
 */
function requestGrowth() {
    if (!growthText.value.trim() || asking.value) return;
    asking.value = true;
    router.post(
        `/campaigns/${props.campaign.id}/grow`,
        { body: growthText.value },
        {
            preserveScroll: true,
            onSuccess: () => (growthText.value = ''),
            onFinish: () => (asking.value = false),
        },
    );
}

/** The world's own suggestions for what to ask next, from its last answer. */
const growthSuggestions = computed(() => {
    const last = props.growth[props.growth.length - 1];

    return last?.role === 'narrator' ? (last.suggestions ?? []) : [];
});

function endCampaign(coda: boolean) {
    router.post(`/campaigns/${props.campaign.id}/end`, { coda });
}

function capabilityLabel(c: {
    capability: string;
    magnitude: number | null;
    grade: string | null;
}): string {
    const name = c.capability.replace('_', ' ');
    if (c.magnitude !== null) return `${name}(${c.magnitude})`;
    if (c.grade) return `${name}(${c.grade})`;
    return name;
}

const healthPct = computed(
    () =>
        (props.character.meters.health.current /
            props.character.meters.health.max) *
        100,
);
</script>

<template>
    <Head :title="campaign.name" />

    <!-- The beat between choosing and reading: what the engine already rolled,
         shown before the chapter that was written from it. -->
    <DiceTable
        v-if="rollTable"
        :key="rollTable.turn_id"
        :turn-number="rollTable.turn_number"
        :rows="rollTable.rows"
        :heard="rollTable.heard"
        :remembered="rollTable.remembered"
        @continue="rollsSeen"
    />

    <div
        class="relative isolate mx-auto flex w-full max-w-2xl flex-1 flex-col gap-5 p-4 pb-16"
    >
        <AmbientBackdrop />

        <!-- Character strip -->
        <div
            class="sc-rise rounded-xl border border-sidebar-border/70 bg-background/60 p-4 backdrop-blur-sm dark:border-sidebar-border"
        >
            <div class="flex items-baseline justify-between gap-2">
                <button
                    class="font-semibold hover:underline"
                    @click="showSheet = !showSheet"
                >
                    {{ character.name }}
                </button>
                <div
                    class="flex items-center gap-3 text-xs text-muted-foreground"
                >
                    <!-- What the tale has permanently taken. Plain, and only
                         once there is something to say: a scar cost a whole
                         fall and charges the odds forever after, so it does
                         not belong buried in a panel nobody opens. -->
                    <span
                        v-if="character.scars.length"
                        class="text-red-600 dark:text-red-400"
                        :title="character.scars.map((s) => s.label).join(', ')"
                    >
                        {{ character.scars.length }}
                        {{ character.scars.length === 1 ? 'scar' : 'scars' }}
                    </span>
                    <span
                        v-for="(pool, name) in character.meters.tempo"
                        :key="name"
                        class="text-violet-600 dark:text-violet-400"
                    >
                        {{ String(name).replace('_', ' ') }}
                        {{ pool.current }}/{{ pool.max }}
                    </span>
                    <span
                        >❤ {{ character.meters.health.current }}/{{
                            character.meters.health.max
                        }}</span
                    >
                </div>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full transition-all duration-700"
                    :class="
                        healthPct <= 25
                            ? 'animate-pulse bg-red-500'
                            : 'bg-emerald-500'
                    "
                    :style="{ width: `${healthPct}%` }"
                />
            </div>

            <!-- The endeavor, beside the meters because it is read the same
                 way: a standing figure the next commit has to be priced
                 against. Segments rather than a bar — the player needs to
                 count what is left, not estimate it. -->
            <div
                v-if="endeavor"
                class="mt-2 flex items-center gap-2 text-xs text-muted-foreground"
            >
                <span class="flex shrink-0 gap-0.5" aria-hidden="true">
                    <span
                        v-for="i in endeavor.segments"
                        :key="i"
                        class="h-1.5 w-3 rounded-sm transition-colors duration-500"
                        :class="
                            i <= endeavor.filled
                                ? 'bg-teal-500'
                                : 'bg-muted-foreground/25'
                        "
                    />
                </span>
                <span class="truncate"
                    >{{ endeavor.name }} — {{ endeavor.filled }} of
                    {{ endeavor.segments }}</span
                >
            </div>

            <Transition name="unfold">
                <div
                    v-if="showSheet"
                    class="mt-3 space-y-3 border-t border-sidebar-border/50 pt-3 text-sm"
                >
                    <p class="text-muted-foreground italic">
                        {{ character.description }}
                    </p>

                    <div>
                        <p
                            class="mb-1 text-[10px] tracking-widest text-muted-foreground uppercase"
                        >
                            Abilities
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="c in character.capabilities"
                                :key="c.capability"
                                class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-400"
                                :title="
                                    c.source ? `source: ${c.source}` : undefined
                                "
                            >
                                {{ capabilityLabel(c) }}
                            </span>
                        </div>
                    </div>

                    <div v-if="character.constraints.length">
                        <p
                            class="mb-1 text-[10px] tracking-widest text-muted-foreground uppercase"
                        >
                            Burdens
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="c in character.constraints"
                                :key="c.name"
                                class="rounded-full bg-red-500/10 px-2 py-0.5 text-xs text-red-700 dark:text-red-400"
                            >
                                {{ c.name.replace('_', ' ') }}
                            </span>
                        </div>
                    </div>

                    <!-- The burdens above are what they chose. These are what
                         the tale took: named in full, with what each one now
                         costs, because nobody picked them off a list. -->
                    <div v-if="character.scars.length">
                        <p
                            class="mb-1 text-[10px] tracking-widest text-muted-foreground uppercase"
                        >
                            Scars
                        </p>
                        <div class="space-y-1">
                            <div
                                v-for="s in character.scars"
                                :key="s.key"
                                class="rounded-md border border-red-500/30 bg-red-500/5 p-2 text-xs"
                            >
                                <p
                                    class="font-medium text-red-700 dark:text-red-400"
                                >
                                    {{ s.label }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{ s.description }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- What is in their hands right now. Separate from the
                         items below on purpose: those are owned and travel
                         with them, this is scene matter they can put down —
                         and it is the thing that decides whether the next
                         card comes one-armed. -->
                    <div v-if="character.carrying.length">
                        <p
                            class="mb-1 text-[10px] tracking-widest text-muted-foreground uppercase"
                        >
                            In hand
                        </p>
                        <div class="flex flex-wrap items-center gap-1">
                            <span
                                v-for="thing in character.carrying"
                                :key="thing.name"
                                class="rounded-full bg-sky-500/10 px-2 py-0.5 text-xs text-sky-700 dark:text-sky-300"
                            >
                                {{ thing.name
                                }}<span class="opacity-70">
                                    ·
                                    {{
                                        thing.hands === 2
                                            ? 'both hands'
                                            : 'one hand'
                                    }}</span
                                >
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{
                                    character.hands_free === 0
                                        ? 'no hand free'
                                        : `${character.hands_free} hand${character.hands_free === 1 ? '' : 's'} free`
                                }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <p
                            class="mb-1 text-[10px] tracking-widest text-muted-foreground uppercase"
                        >
                            Carried
                        </p>
                        <p
                            v-if="!character.items.length"
                            class="text-xs text-muted-foreground italic"
                        >
                            Nothing of note — yet.
                        </p>
                        <div v-else class="space-y-1.5">
                            <div
                                v-for="item in character.items"
                                :key="item.name"
                                class="rounded-md border border-sidebar-border/50 p-2 text-xs"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span class="font-medium">{{
                                        item.name
                                    }}</span>
                                    <span class="flex items-center gap-1.5">
                                        <span
                                            v-if="item.charges !== null"
                                            class="text-muted-foreground"
                                            >{{ item.charges }} charges</span
                                        >
                                        <span
                                            v-if="item.equipped"
                                            class="rounded-full bg-violet-500/10 px-2 py-0.5 text-violet-600 dark:text-violet-400"
                                            >equipped</span
                                        >
                                    </span>
                                </div>
                                <p
                                    v-if="item.description"
                                    class="mt-0.5 text-muted-foreground"
                                >
                                    {{ item.description }}
                                </p>
                                <div
                                    v-if="item.grants?.length"
                                    class="mt-1 flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="g in item.grants"
                                        :key="g.capability"
                                        class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-emerald-700 dark:text-emerald-400"
                                    >
                                        {{ g.capability.replace('_', ' ')
                                        }}<template v-if="g.magnitude !== null"
                                            >({{ g.magnitude }})</template
                                        >
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 pt-1 text-xs">
                        <button
                            class="text-muted-foreground underline"
                            @click="showGrowth = !showGrowth"
                        >
                            ask to evolve
                        </button>
                        <button
                            class="text-muted-foreground underline"
                            @click="
                                router.visit(`/campaigns/${campaign.id}/book`)
                            "
                        >
                            read the book so far
                        </button>
                        <button
                            class="text-red-500 underline"
                            @click="showEnd = true"
                        >
                            end this tale
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- Asking to evolve is a conversation, not a one-way form.
                 It used to be a lone text box: the request went off, the
                 world wrote an answer, and the answer was shown to nobody —
                 so there was no way to tell a granted ask from a refused one
                 from one that never arrived. The transcript stays on screen,
                 and the engine's own verdict sits under each reply. -->
            <Transition name="unfold">
                <div
                    v-if="showGrowth"
                    class="mt-3 space-y-3 border-t border-sidebar-border/50 pt-3"
                >
                    <p class="text-xs text-muted-foreground">
                        Tell the world how you want to change. It answers in its
                        own voice, and it may say no — a smaller version of the
                        same ask often lands.
                    </p>

                    <div
                        v-if="growth.length"
                        class="max-h-72 space-y-2 overflow-y-auto pr-1"
                    >
                        <div
                            v-for="message in growth"
                            :key="message.id"
                            class="rounded-lg px-3 py-2 text-sm"
                            :class="
                                message.role === 'player'
                                    ? 'ml-6 bg-muted/70'
                                    : 'mr-6 border border-sidebar-border/60'
                            "
                        >
                            <p
                                class="mb-0.5 text-[10px] tracking-widest text-muted-foreground uppercase"
                            >
                                {{
                                    message.role === 'player'
                                        ? 'You'
                                        : 'The world'
                                }}
                            </p>
                            <p class="whitespace-pre-wrap">
                                {{ message.body }}
                            </p>

                            <!-- The verdict, from the sheet rather than the
                                 prose. The world speaks in-world on purpose
                                 and will not quote numbers; the player still
                                 needs to know whether anything moved. -->
                            <div
                                v-if="
                                    message.role === 'narrator' &&
                                    message.granted !== null
                                "
                                class="mt-2 border-t border-sidebar-border/50 pt-1.5"
                            >
                                <p
                                    v-if="!message.granted"
                                    class="text-xs text-amber-700 dark:text-amber-400"
                                >
                                    Nothing on your sheet changed.
                                </p>
                                <template v-else>
                                    <p
                                        class="text-xs font-medium text-emerald-700 dark:text-emerald-400"
                                    >
                                        Your sheet changed:
                                    </p>
                                    <ul class="mt-0.5 space-y-0.5">
                                        <li
                                            v-for="change in message.changes ??
                                            []"
                                            :key="`${change.kind}-${change.label}`"
                                            class="text-xs"
                                            :class="
                                                change.kind === 'gift'
                                                    ? 'text-emerald-700 dark:text-emerald-400'
                                                    : 'text-red-700 dark:text-red-400'
                                            "
                                        >
                                            {{
                                                change.kind === 'gift'
                                                    ? '＋'
                                                    : '−'
                                            }}
                                            {{ change.label }}
                                            <span class="text-muted-foreground"
                                                >— {{ change.detail }}</span
                                            >
                                        </li>
                                    </ul>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="growthSuggestions.length"
                        class="flex flex-wrap gap-1"
                    >
                        <button
                            v-for="suggestion in growthSuggestions"
                            :key="suggestion"
                            type="button"
                            class="rounded-full border border-input px-2.5 py-1 text-left text-xs transition hover:bg-accent active:scale-95"
                            @click="growthText = suggestion"
                        >
                            {{ suggestion }}
                        </button>
                    </div>

                    <form class="flex gap-2" @submit.prevent="requestGrowth">
                        <input
                            v-model="growthText"
                            maxlength="2000"
                            :disabled="asking"
                            placeholder="Describe how you want to change — the world will answer…"
                            class="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm disabled:opacity-60"
                        />
                        <button
                            type="submit"
                            :disabled="asking || !growthText.trim()"
                            class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground transition active:scale-95 disabled:opacity-50"
                        >
                            {{ asking ? 'Asking…' : 'Ask' }}
                        </button>
                    </form>
                </div>
            </Transition>
        </div>

        <!-- The shelf.
             Objects, not an achievement list: each one is a thing the tale
             left behind, said in the tale's own words, with the chapter it
             came out of. Nothing here is equipment and nothing here can be
             spent — it exists to be read now and bound into the book later.
             An empty shelf draws nothing at all. -->
        <div
            v-if="mementos.length"
            class="sc-rise rounded-xl border border-sidebar-border/70 bg-background/60 p-4 backdrop-blur-sm dark:border-sidebar-border"
            style="animation-delay: 40ms"
        >
            <p
                class="mb-2 text-[10px] tracking-widest text-muted-foreground uppercase"
            >
                What you are carrying home
            </p>
            <ul class="space-y-2">
                <li
                    v-for="(keepsake, i) in mementos"
                    :key="`${keepsake.name}-${i}`"
                >
                    <p class="font-serif text-sm">{{ keepsake.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        <span class="italic">{{ keepsake.line }}</span>
                        <a
                            v-if="keepsake.chapter !== null"
                            :href="`/campaigns/${campaign.id}/book#chapter-${keepsake.chapter}`"
                            class="ml-1 underline decoration-dotted underline-offset-4 hover:text-foreground"
                        >
                            — chapter {{ keepsake.chapter }}
                        </a>
                    </p>
                </li>
            </ul>
        </div>

        <!-- Latest chapter — hidden while the world turns. Once a choice is
             committed the page must not re-show the PREVIOUS chapter: until
             the new one is written, the only honest thing on screen is that
             it is being written. -->
        <article
            v-if="latestChapter && !waiting"
            :key="`${latestChapter.kind}-${latestChapter.number}`"
            class="sc-rise relative rounded-xl border border-sidebar-border/70 bg-background/60 p-5 backdrop-blur-sm dark:border-sidebar-border"
            style="animation-delay: 80ms"
        >
            <p
                class="mb-1 text-xs tracking-widest text-muted-foreground uppercase"
            >
                {{
                    latestChapter.kind === 'prologue'
                        ? 'Prologue'
                        : latestChapter.kind === 'chronicle'
                          ? 'The world shifted'
                          : `Chapter ${latestChapter.number}`
                }}
            </p>
            <p
                v-if="latestChapter.intent_line"
                class="mb-2 text-sm text-muted-foreground italic"
            >
                {{ latestChapter.intent_line }}
            </p>
            <div
                class="sc-ink sc-dropcap space-y-3 font-serif leading-relaxed whitespace-pre-wrap"
                style="animation-delay: 200ms"
            >
                <template v-for="(seg, i) in bodySegments" :key="i"
                    ><button
                        v-if="seg.event"
                        type="button"
                        class="mx-0.5 inline-flex h-5 w-5 -translate-y-px items-center justify-center rounded-full align-middle text-[11px] leading-none not-italic transition-transform hover:scale-125"
                        :class="
                            detail?.key === `event:${seg.event.id}`
                                ? 'bg-violet-500/25 ring-1 ring-violet-500'
                                : 'bg-muted'
                        "
                        :title="seg.event.label"
                        data-anchor
                        @click="openEvent(seg.event, $event)"
                    >
                        {{ icon(seg.event.icon) }}</button
                    ><button
                        v-else-if="seg.entity"
                        type="button"
                        class="inline cursor-pointer text-left underline decoration-dotted decoration-2 underline-offset-4 transition-colors hover:decoration-solid"
                        :class="
                            detail?.key === `entity:${seg.entity.key}`
                                ? 'text-violet-600 decoration-violet-500 dark:text-violet-400 dark:decoration-violet-400'
                                : [tone(seg.entity).text, tone(seg.entity).line]
                        "
                        :title="`${seg.entity.name} — tap for detail`"
                        data-anchor
                        @click="openEntity(seg.entity, $event)"
                    >
                        {{ seg.text }}</button
                    ><span v-else>{{ seg.text }}</span></template
                >
            </div>

            <p
                class="sc-twinkle mt-6 text-center text-xs tracking-[0.6em] text-violet-500/60 select-none"
                aria-hidden="true"
            >
                ✦ ✦ ✦
            </p>

            <div
                v-if="unanchoredEvents.length"
                class="mt-4 flex flex-wrap items-center gap-1.5 border-t border-sidebar-border/50 pt-3"
            >
                <span
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                    >Moments of record</span
                >
                <button
                    v-for="event in unanchoredEvents"
                    :key="event.id"
                    type="button"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs transition-transform hover:scale-110"
                    :class="
                        detail?.key === `event:${event.id}`
                            ? 'bg-violet-500/25 ring-1 ring-violet-500'
                            : 'bg-muted'
                    "
                    :title="event.label"
                    data-anchor
                    @click="openEvent(event, $event)"
                >
                    {{ icon(event.icon) }}
                </button>
            </div>
        </article>

        <!-- One detail card for every anchor on the page: a moment the engine
             resolved, or a named person or piece of ground. It lives out here
             rather than inside the chapter because the board carries the same
             anchors now, and it is pinned to the viewport off the rectangle of
             whatever was tapped. -->
        <Teleport to="body">
            <Transition name="pop">
                <div
                    v-if="detail"
                    :key="detail.key"
                    ref="detailEl"
                    data-detail
                    class="fixed z-50 w-[min(24rem,calc(100vw-1.5rem))] rounded-lg border border-violet-500/40 bg-popover p-3 text-sm shadow-lg shadow-violet-500/10"
                    :style="{
                        top: `${detailPos.top}px`,
                        left: `${detailPos.left}px`,
                        transform: detailPos.flip
                            ? 'translateY(-100%)'
                            : undefined,
                    }"
                >
                    <div class="flex items-start justify-between gap-2">
                        <p class="font-medium">
                            {{ detail.icon }}
                            <span :class="detail.titleClass">{{
                                detail.title
                            }}</span>
                            <span
                                v-if="detail.badge"
                                class="ml-1 rounded-full bg-muted px-2 py-0.5 text-[10px] tracking-wide text-muted-foreground uppercase"
                                >{{ detail.badge }}</span
                            >
                        </p>
                        <button
                            class="text-xs text-muted-foreground hover:text-foreground"
                            @click="detail = null"
                        >
                            ✕
                        </button>
                    </div>
                    <ul class="mt-1.5 space-y-0.5 text-muted-foreground">
                        <li v-for="line in detail.lines" :key="line">
                            {{ line }}
                        </li>
                    </ul>
                    <p
                        v-if="detail.note"
                        class="mt-1.5 text-xs text-violet-600 italic dark:text-violet-400"
                    >
                        Your words: “{{ detail.note }}”
                    </p>
                    <template v-if="detail.roll">
                        <!-- The whole sum, +0 included: a modifier that only
                             shows itself when non-zero leaves the reader
                             unable to tell "nothing helped" from "something
                             is broken". -->
                        <p
                            class="mt-1.5 font-mono text-xs text-muted-foreground"
                        >
                            d20 {{ detail.roll.roll }}
                            {{ signed(detail.roll.total - detail.roll.roll) }} =
                            <span class="font-semibold text-foreground">{{
                                detail.roll.total
                            }}</span>
                            vs DC {{ detail.roll.difficulty }}
                            <span
                                v-if="detail.roll.crit"
                                class="font-sans font-bold"
                                :class="
                                    detail.roll.crit === 'success'
                                        ? 'text-amber-600 dark:text-amber-300'
                                        : 'text-rose-600 dark:text-rose-400'
                                "
                            >
                                ·
                                {{
                                    detail.roll.crit === 'success'
                                        ? '★ NAT 20'
                                        : '☠ NAT 1'
                                }}
                            </span>
                        </p>
                        <ul
                            v-if="
                                detail.roll.difficulty_parts.length ||
                                detail.roll.bonus_parts.length
                            "
                            class="mt-1 space-y-0.5 text-[11px] text-muted-foreground"
                        >
                            <li
                                v-for="part in reasonsFor(
                                    detail.roll.difficulty_parts,
                                )"
                                :key="`d-${part.label}`"
                                class="flex justify-between gap-3"
                            >
                                <span>{{ part.label }}</span>
                                <span
                                    class="tabular-nums"
                                    :class="costClass(part.amount)"
                                    >{{ signed(part.amount) }}</span
                                >
                            </li>
                            <li
                                v-for="part in detail.roll.bonus_parts"
                                :key="`b-${part.label}`"
                                class="flex justify-between gap-3 text-emerald-700 dark:text-emerald-400"
                            >
                                <span>{{ part.label }}</span>
                                <span class="tabular-nums">{{
                                    signed(part.amount)
                                }}</span>
                            </li>
                        </ul>
                    </template>
                </div>
            </Transition>
        </Teleport>

        <!-- Situation + form / lock -->
        <div
            v-if="turn"
            class="sc-rise rounded-xl border border-sidebar-border/70 bg-background/60 p-5 backdrop-blur-sm dark:border-sidebar-border"
            style="animation-delay: 160ms"
        >
            <!-- The board, beside every chapter rather than instead of one.
                 Grouped, so the eye can find the one line it came for; and
                 short when the ground is quiet, because an empty room is a
                 real answer and does not need padding out. -->
            <template v-if="!waiting">
                <div class="mb-2 flex items-baseline justify-between gap-2">
                    <p
                        class="text-xs tracking-widest text-muted-foreground uppercase"
                    >
                        The situation
                    </p>

                    <!-- The last chapter's moments, gathered where the form is.
                         The same icons the prose carries and the same detail
                         card behind them — reaching one used to mean scrolling
                         back up through a page of serif prose hunting for a
                         glyph mid-sentence, and this is read for exactly the
                         same reason the board is. -->
                    <div
                        v-if="chapterMoments.length"
                        class="flex flex-wrap justify-end gap-1"
                    >
                        <button
                            v-for="event in chapterMoments"
                            :key="`board-${event.id}`"
                            type="button"
                            class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs transition-transform hover:scale-110"
                            :class="
                                detail?.key === `event:${event.id}`
                                    ? 'bg-violet-500/25 ring-1 ring-violet-500'
                                    : 'bg-muted'
                            "
                            :title="event.label"
                            data-anchor
                            @click="openEvent(event, $event)"
                        >
                            {{ icon(event.icon) }}
                        </button>
                    </div>
                </div>
                <dl v-if="board.length" class="mb-4 space-y-2">
                    <div v-for="group in board" :key="group.key">
                        <dt
                            class="text-[10px] tracking-widest text-muted-foreground uppercase"
                        >
                            {{ group.title }}
                        </dt>
                        <dd class="mt-0.5">
                            <ul class="space-y-0.5">
                                <li
                                    v-for="item in group.items"
                                    :key="item"
                                    class="flex gap-2 text-sm"
                                >
                                    <span
                                        class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
                                        :class="TONE_DOTS[group.tone]"
                                        aria-hidden="true"
                                    />
                                    <!-- The same names, tappable in the same
                                         way they are in the chapter. A noun the
                                         reader can touch in a paragraph and not
                                         in the list two inches below it teaches
                                         them the anchors are decoration. -->
                                    <span
                                        ><template
                                            v-for="(seg, i) in entitySegments(
                                                item,
                                            )"
                                            :key="i"
                                            ><button
                                                v-if="seg.entity"
                                                type="button"
                                                class="cursor-pointer text-left underline decoration-dotted decoration-2 underline-offset-4 transition-colors hover:decoration-solid"
                                                :class="
                                                    detail?.key ===
                                                    `entity:${seg.entity.key}`
                                                        ? 'text-violet-600 decoration-violet-500 dark:text-violet-400 dark:decoration-violet-400'
                                                        : [
                                                              tone(seg.entity)
                                                                  .text,
                                                              tone(seg.entity)
                                                                  .line,
                                                          ]
                                                "
                                                :title="`${seg.entity.name} — tap for detail`"
                                                data-anchor
                                                @click="
                                                    openEntity(
                                                        seg.entity,
                                                        $event,
                                                    )
                                                "
                                            >
                                                {{ seg.text }}</button
                                            ><template v-else>{{
                                                seg.text
                                            }}</template></template
                                        ></span
                                    >
                                </li>
                            </ul>
                        </dd>
                    </div>
                </dl>
                <p v-else-if="legacySituation" class="mb-4 text-sm">
                    {{ legacySituation }}
                </p>
                <p v-else class="mb-4 text-sm text-muted-foreground italic">
                    Nothing but the ground and you.
                </p>
            </template>

            <!-- The narrator has stopped answering. Say it plainly, above a
                 story that still works: the dice fell, the world moved, and
                 only the writing-up is missing. -->
            <div
                v-if="stalled"
                class="mb-4 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm"
            >
                <p class="font-medium text-foreground">
                    The narrator has gone quiet.
                </p>
                <p class="mt-1 text-muted-foreground">
                    Your turn resolved and nothing was lost — the chapter for it
                    just hasn't been written yet. The world keeps trying, and it
                    will appear here on its own once the narrator answers again.
                </p>
            </div>

            <!-- The thread regained. Above the wait and above the form, and
                 in front of neither: it is four lines the engine already
                 wrote, for a player who has been gone long enough to have
                 lost the shape of them. -->
            <RecapPanel
                v-if="recap && !rollTable"
                :key="recap.turn_id"
                class="mb-4"
                :recap="recap"
            />

            <div
                v-if="waiting"
                class="sc-breathe rounded-xl border border-violet-500/25 bg-muted/50 p-6 text-center text-sm text-muted-foreground"
            >
                <div
                    class="mb-3 flex items-end justify-center gap-1.5"
                    aria-hidden="true"
                >
                    <span
                        class="sc-drop h-2 w-2 rounded-full bg-violet-500/80"
                    />
                    <span
                        class="sc-drop h-2 w-2 rounded-full bg-violet-500/80"
                        style="animation-delay: 0.2s"
                    />
                    <span
                        class="sc-drop h-2 w-2 rounded-full bg-violet-500/80"
                        style="animation-delay: 0.4s"
                    />
                </div>
                <p class="font-medium text-foreground">
                    <template v-if="locked"
                        >Your choice is made. The dice are falling.</template
                    >
                    <template v-else
                        >The dice have fallen. The chapter is being
                        written…</template
                    >
                </p>
                <p class="mt-1">
                    <template v-if="locked"
                        >This takes a moment, no longer.</template
                    >
                    <template v-else
                        >It arrives on this page the moment it is
                        finished.</template
                    >
                </p>
            </div>

            <!-- One panel, three numbered beats, one list behind all of them.
                 Every pick offers everything this ground offers; the numbers are
                 the order they resolve in, and only the middle one is required. -->
            <form
                v-else-if="turn.cards"
                class="space-y-4"
                @submit.prevent="submit"
            >
                <div class="space-y-2">
                    <p
                        class="text-[10px] tracking-widest text-muted-foreground uppercase"
                    >
                        What you do
                        <span class="tracking-normal normal-case"
                            >— up to three, in this order</span
                        >
                    </p>

                    <BeatPicker
                        v-for="step in STEPS"
                        :key="step.slot"
                        v-model="plan[step.slot]"
                        :step="step.step"
                        :title="step.title"
                        :hint="step.hint"
                        :required="step.required"
                        :cards="turn.cards[step.slot]"
                        :carried="carriedFor(step.slot)"
                        :open="openStep === step.slot"
                        @toggle="toggleStep(step.slot)"
                    />
                </div>

                <!-- Each companion carries their own beat: a request costs
                     none of the player's three slots, it is never an order,
                     and the companion's own roll decides how it goes. Parallel
                     to the sentence rather than part of it. -->
                <RiderList
                    v-for="companion in turn.cards.companions ?? []"
                    :key="companion.id"
                    :model-value="companionChoices[companion.id] ?? null"
                    :title="companion.name"
                    hint="a request, not an order — they answer for it"
                    :cards="companion.cards"
                    :act-verb="mainCard?.verb ?? null"
                    :carried="companionCarried"
                    @update:model-value="
                        (choice) => (companionChoices[companion.id] = choice)
                    "
                />

                <div class="flex items-center justify-between gap-3 pt-3">
                    <span
                        v-if="runningCost.length"
                        class="text-xs text-violet-600 dark:text-violet-400"
                    >
                        This plan spends:
                        {{
                            runningCost
                                .map(
                                    ([meter, amount]) =>
                                        `${amount} ${meter.replace('_', ' ')}`,
                                )
                                .join(', ')
                        }}
                    </span>
                    <!-- The tempo pools the gifts draw on. "No charges spent"
                         meant nothing to anyone who had not gone looking for
                         what a charge was. -->
                    <span v-else class="text-xs text-muted-foreground"
                        >This plan costs you nothing to attempt.</span
                    >
                    <button
                        type="submit"
                        :disabled="!plan.main || submitting"
                        class="rounded-md bg-gradient-to-br from-violet-600 to-violet-800 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-violet-900/20 transition hover:from-violet-500 hover:to-violet-700 hover:shadow-violet-700/30 active:scale-[0.98] disabled:opacity-50"
                    >
                        {{ submitting ? 'Committing…' : 'Commit to it' }}
                    </button>
                </div>
            </form>
        </div>

        <!-- End-campaign dialog -->
        <Transition name="fade">
            <div
                v-if="showEnd"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
                @click.self="showEnd = false"
            >
                <div
                    class="sc-rise w-full max-w-md rounded-xl border border-sidebar-border bg-background p-6"
                >
                    <h3 class="mb-2 font-semibold">End this tale?</h3>
                    <p class="mb-4 text-sm text-muted-foreground">
                        Your chapters will be bound into a book. You can let the
                        narrator write a brief closing coda, or leave the story
                        exactly where it lies.
                    </p>
                    <div class="flex flex-col gap-2">
                        <button
                            class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground"
                            @click="endCampaign(true)"
                        >
                            End with a closing chapter
                        </button>
                        <button
                            class="rounded-md border border-input px-4 py-2 text-sm"
                            @click="endCampaign(false)"
                        >
                            Leave it where it lies
                        </button>
                        <button
                            class="px-4 py-2 text-sm text-muted-foreground"
                            @click="showEnd = false"
                        >
                            Keep playing
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>
