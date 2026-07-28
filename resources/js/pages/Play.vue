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
import SlotPicker from '@/components/game/SlotPicker.vue';
import { enablePush } from '@/lib/push';
import type {
    ActionCard,
    CarriedThing,
    ChapterEntity,
    ChapterEvent,
    CharacterItem,
    CharacterMeters,
    GrowthMessage,
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
    /** The evolution conversation: what was asked, and how the world answered. */
    growth: GrowthMessage[];
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

const pre = ref<SlotChoice | null>(null);
const main = ref<SlotChoice | null>(null);
const post = ref<SlotChoice | null>(null);
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

// The detail card opens where the tapped anchor sits, not at a fixed spot
// below the prose: the chapter article is the positioning context, and the
// panel's top tracks the clicked element within it.
const chapterEl = ref<HTMLElement | null>(null);
const detailTop = ref(0);

const detailEl = ref<HTMLElement | null>(null);

function openDetail(next: Detail, e: MouseEvent) {
    if (detail.value?.key === next.key) {
        detail.value = null;
        return;
    }
    detail.value = next;
    const anchor = e.currentTarget as HTMLElement | null;
    if (anchor && chapterEl.value) {
        const article = chapterEl.value.getBoundingClientRect();
        detailTop.value =
            anchor.getBoundingClientRect().bottom - article.top + 6;
    }
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

/** Always signed, +0 included — see the roll block in the detail card. */
const signed = (amount: number) =>
    `${amount > 0 ? '+' : '−'}${Math.abs(amount)}`;

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

// The chapter body split around both kinds of anchor. Unknown [[eN]] tokens
// vanish silently; the stored body itself is never rewritten.
const bodySegments = computed<Segment[]>(() => {
    const body = props.latestChapter?.body ?? '';
    const matcher = entityMatcher.value;
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

        if (!matcher || !part) {
            segments.push({ text: part, event: null, entity: null });
            continue;
        }

        let cursor = 0;
        matcher.pattern.lastIndex = 0;
        for (const hit of part.matchAll(matcher.pattern)) {
            const entity = matcher.byName.get(hit[1].toLowerCase());
            if (!entity || hit.index === undefined) continue;
            if (hit.index > cursor) {
                segments.push({
                    text: part.slice(cursor, hit.index),
                    event: null,
                    entity: null,
                });
            }
            segments.push({ text: hit[0], event: null, entity });
            cursor = hit.index + hit[0].length;
        }
        if (cursor < part.length) {
            segments.push({
                text: part.slice(cursor),
                event: null,
                entity: null,
            });
        }
    }

    return segments;
});

// Events the narrator failed to anchor (or pre-feature chapters): still shown,
// as a row of moments under the chapter, so no data is ever lost.
const unanchoredEvents = computed(() => {
    const body = props.latestChapter?.body ?? '';
    return (props.latestChapter?.events ?? []).filter(
        (e) => !body.includes(`[[${e.id}]]`),
    );
});

/**
 * What the set-up beats already chosen will hand to the beats after them.
 *
 * The engine states this on each card ("readied — +2 to whatever comes next")
 * so the page never has to re-derive the rules; it only adds up the cards the
 * player has actually picked. Passed down so the act's difficulty is quoted
 * against the plan being assembled rather than against an empty one.
 */
const setupGrants = computed(() => {
    const chosen: ActionCard[] = [];

    if (pre.value && props.turn?.cards) {
        const card = props.turn.cards.pre.find(
            (c) => c.id === pre.value!.card_id,
        );
        if (card) {
            chosen.push(card);
        }
    }

    for (const companion of props.turn?.cards?.companions ?? []) {
        const choice = companionChoices.value[companion.id];

        if (!choice) {
            continue;
        }

        const card = companion.cards.find((c) => c.id === choice.card_id);

        if (card) {
            chosen.push(card);
        }
    }

    return chosen
        .map((card) => card.forecast.grant)
        .filter((grant): grant is NonNullable<typeof grant> => grant !== null)
        .map((grant) => ({
            label: grant.label,
            amount: grant.amount,
            certain: grant.certain,
            verbs: grant.verbs,
            slot: grant.slot,
        }));
});

// Staged, visible resource commitment: the running cost of the whole chain.
const runningCost = computed(() => {
    const totals: Record<string, number> = {};
    for (const [choice, slot] of [
        [pre.value, 'pre'],
        [main.value, 'main'],
        [post.value, 'post'],
    ] as const) {
        if (!choice || !props.turn?.cards) continue;
        const card = props.turn.cards[slot].find(
            (c) => c.id === choice.card_id,
        );
        for (const cost of card?.cost ?? []) {
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
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
    document.removeEventListener('pointerdown', dismissDetail);
    document.removeEventListener('keydown', dismissOnEscape);
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
    if (!main.value || submitting.value) return;
    submitting.value = true;
    router.post(
        `/play/${props.campaign.id}`,
        {
            pre: pre.value,
            main: main.value,
            post: post.value,
            companions: companionChoices.value,
        },
        {
            onSuccess: () => {
                pre.value = main.value = post.value = null;
                companionChoices.value = {};
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

        <!-- Latest chapter — hidden while the world turns. Once a choice is
             committed the page must not re-show the PREVIOUS chapter: until
             the new one is written, the only honest thing on screen is that
             it is being written. -->
        <article
            v-if="latestChapter && !waiting"
            ref="chapterEl"
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

            <!-- One detail card for both kinds of anchor: what the engine
                 resolved, or what a named person or piece of ground is. -->
            <Transition name="pop">
                <div
                    v-if="detail"
                    :key="detail.key"
                    ref="detailEl"
                    data-detail
                    class="absolute right-3 left-3 z-10 rounded-lg border border-violet-500/40 bg-popover p-3 text-sm shadow-lg shadow-violet-500/10"
                    :style="{ top: `${detailTop}px` }"
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
                                v-for="part in detail.roll.difficulty_parts"
                                :key="`d-${part.label}`"
                                class="flex justify-between gap-3"
                            >
                                <span>{{ part.label }}</span>
                                <span class="tabular-nums">{{
                                    part.amount
                                }}</span>
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
        </article>

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
                <p
                    class="mb-2 text-xs tracking-widest text-muted-foreground uppercase"
                >
                    The situation
                </p>
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
                                    <span>{{ item }}</span>
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

            <!-- Each beat is its own fold. Only the required one starts
                 open: three fully expanded lists made the player scroll a
                 page and a half to reach the single choice they had to make,
                 and length is what turned a set of options into a chore. -->
            <form
                v-else-if="turn.cards"
                class="space-y-2"
                @submit.prevent="submit"
            >
                <SlotPicker
                    v-model="pre"
                    title="Set up"
                    hint="positioning & tempo before the act"
                    :cards="turn.cards.pre"
                    :optional="true"
                />

                <!-- Each companion carries their own beat: a request costs
                     none of the player's three slots, and the companion's
                     own roll decides how it goes. -->
                <SlotPicker
                    v-for="companion in turn.cards.companions ?? []"
                    :key="companion.id"
                    :model-value="companionChoices[companion.id] ?? null"
                    :title="companion.name"
                    hint="a request, not an order — they answer for it"
                    :cards="companion.cards"
                    :optional="true"
                    @update:model-value="
                        (choice) => (companionChoices[companion.id] = choice)
                    "
                />

                <SlotPicker
                    v-model="main"
                    title="The act"
                    hint="the beat that matters"
                    :cards="turn.cards.main"
                    :optional="false"
                    :open-by-default="true"
                    :carried-bonus="setupGrants"
                />
                <SlotPicker
                    v-model="post"
                    title="Afterward"
                    hint="if the moment allows it"
                    :cards="turn.cards.post"
                    :optional="true"
                    :carried-bonus="setupGrants"
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
                    <span v-else class="text-xs text-muted-foreground"
                        >No charges spent.</span
                    >
                    <button
                        type="submit"
                        :disabled="!main || submitting"
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
