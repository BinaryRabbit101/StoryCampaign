<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AmbientBackdrop from '@/components/game/AmbientBackdrop.vue';
import { enablePush } from '@/lib/push';
import type { Suggestion } from '@/types/game';

interface Message {
    id: number;
    role: 'player' | 'narrator';
    body: string;
    suggestions: Suggestion[] | null;
}

/** The sheet as it currently stands, priced in the engine's own coin. */
interface Draft {
    name: string | null;
    description: string | null;
    points: number;
    balance: number;
    gifts: { label: string; cost: number }[];
    burdens: { label: string; refund: number }[];
    ready: boolean;
}

interface TraitOption {
    key: string;
    label: string;
    description: string;
    cost?: number;
    refund?: number;
    group: string | null;
}

const props = defineProps<{
    campaign: { id: number; name: string; status: string };
    draft: Draft | null;
    messages: Message[];
    names: string[];
    catalog: {
        points: number;
        positives: TraitOption[];
        negatives: TraitOption[];
    };
}>();

// ---- The name is the player's alone: a dedicated field, never part of the
// bargain. It opens pre-filled with a suggested name and the die cycles
// through more; whatever stands here when the tale begins is the name,
// outranking anything the narrator drafted. ----

const heroName = ref(props.names[0] ?? '');
let nameIndex = 0;

function rerollName() {
    if (!props.names.length) return;
    nameIndex = (nameIndex + 1) % props.names.length;
    heroName.value = props.names[nameIndex];
}

const body = ref('');
const sending = ref(false);
const scroller = ref<HTMLElement | null>(null);
const speakBox = ref<HTMLTextAreaElement | null>(null);

// A hand for the stuck: the narrator's latest question may carry example
// answers. Tapping one adds it to the textarea — the player can take up
// several, and reshape the whole of it before speaking.
const suggestions = computed(() => {
    const last = props.messages[props.messages.length - 1];
    return last?.role === 'narrator' && last.suggestions?.length
        ? last.suggestions
        : [];
});

/** Whether the tints mean anything on this row — legacy rows carry no kinds. */
const kinded = computed(() =>
    suggestions.value.some((s) => s.kind !== 'neutral'),
);

function adopted(suggestion: Suggestion): boolean {
    return body.value.includes(suggestion.text);
}

// Answers accumulate rather than replace: a character is usually more than
// one of these. Tapping an adopted one takes it back, so a mistap costs a
// tap instead of hand-editing.
function adopt(suggestion: Suggestion) {
    const current = body.value.trim();

    body.value = adopted(suggestion)
        ? current
              .replace(suggestion.text, '')
              .replace(/\s{2,}/g, ' ')
              .trim()
        : current === ''
          ? suggestion.text
          : `${current} ${suggestion.text}`;

    speakBox.value?.focus();
}

/**
 * What an answer would do to the sheet: green gains, red costs. A `both` chip
 * is green with a red edge, because that is literally what it is — a gift with
 * a price on it.
 *
 * The shades are the ones the balance figure already speaks in on this page
 * (emerald for a bargain that holds, red for a shortfall), so the chips and the
 * number are not two different greens arguing.
 *
 * Deliberately the low-opacity borders rather than the solid red the overspent
 * figure is written in: a row of chips is an invitation, and naming a price is
 * a legitimate answer rather than a mistake being flagged. Colour is never the
 * only carrier either — red/green is the common colour blindness, so the `both`
 * chip has a structural edge and every chip carries its kind in text for a
 * screen reader.
 */
const KIND_STYLES: Record<Suggestion['kind'], string> = {
    gift: 'border-emerald-500/40 bg-emerald-500/10 hover:border-emerald-500/70',
    price: 'border-red-500/40 bg-red-500/10 hover:border-red-500/70',
    both: 'border-emerald-500/40 border-l-2 border-l-red-500/70 bg-emerald-500/10 hover:border-emerald-500/70',
    neutral: 'border-input bg-background/60 hover:border-violet-500/50',
};

const KIND_SELECTED: Record<Suggestion['kind'], string> = {
    gift: 'border-emerald-500 bg-emerald-600/30',
    price: 'border-red-500 bg-red-600/25',
    both: 'border-emerald-500 border-l-2 border-l-red-500 bg-emerald-600/30',
    neutral: 'border-violet-500 bg-violet-600/30',
};

/** Colour is never the only carrier — screen readers get the same fact. */
const KIND_LABELS: Record<Suggestion['kind'], string> = {
    gift: 'a gift',
    price: 'a price',
    both: 'a gift and its price',
    neutral: '',
};

// ---- The point-buy path: gifts cost, burdens refund, break even to be born. ----

const showBuilder = ref(false);
const selected = ref<string[]>([]);
const building = ref(false);

const allTraits = computed(() => [
    ...props.catalog.positives,
    ...props.catalog.negatives,
]);

const balance = computed(() =>
    allTraits.value.reduce(
        (points, trait) =>
            selected.value.includes(trait.key)
                ? points + (trait.refund ?? 0) - (trait.cost ?? 0)
                : points,
        props.catalog.points,
    ),
);

const hasGift = computed(() =>
    props.catalog.positives.some((t) => selected.value.includes(t.key)),
);

// A trait is blocked when another selected trait occupies its exclusive
// group (e.g. two frame sizes at once).
function blocked(trait: TraitOption): boolean {
    if (!trait.group || selected.value.includes(trait.key)) return false;
    return allTraits.value.some(
        (other) =>
            other.key !== trait.key &&
            other.group === trait.group &&
            selected.value.includes(other.key),
    );
}

/**
 * The balance, as a signed number with a colour.
 *
 * It used to read "Balance: 0 / 3", which is two numbers and a slash for one
 * fact: whether the bargain currently holds. Nobody reads a fraction as "you
 * have spent everything and it balances" — so what is on screen is the one
 * figure that matters, signed, in the colour of what it means. Points left to
 * spend, an even bargain, or a shortfall the world will not accept.
 */
const signed = (points: number) =>
    `${points > 0 ? '+' : points < 0 ? '−' : ''}${Math.abs(points)}`;

const balanceClass = (points: number) =>
    points < 0
        ? 'text-red-500'
        : points > 0
          ? 'text-violet-400'
          : 'text-emerald-500';

const balanceWord = (points: number) =>
    points < 0
        ? 'overspent'
        : points > 0
          ? `${points === 1 ? 'point' : 'points'} still to spend`
          : 'an even bargain';

function toggle(trait: TraitOption) {
    if (blocked(trait)) return;
    selected.value = selected.value.includes(trait.key)
        ? selected.value.filter((k) => k !== trait.key)
        : [...selected.value, trait.key];
}

function confirmBuild() {
    if (building.value || !hasGift.value) return;
    building.value = true;
    router.post(
        `/campaigns/${props.campaign.id}/interview/build`,
        {
            name: heroName.value.trim() || null,
            traits: selected.value,
            // Stepping in overspent is allowed — but it is a named choice,
            // and the shortfall is recorded as a debt to the world.
            override: balance.value < 0,
        },
        { onFinish: () => (building.value = false) },
    );
}

// ---- Begin: the player's decision, never the narrator's ----

const beginning = ref(false);

function begin(owing = false) {
    if (beginning.value || !props.draft) return;
    beginning.value = true;
    router.post(
        `/campaigns/${props.campaign.id}/interview/begin`,
        { owing, name: heroName.value.trim() || null },
        { onFinish: () => (beginning.value = false) },
    );
}

// ---- The watchdog ----
//
// Beginning runs three Claude calls back to back and can outlast a phone
// falling asleep, a dropped connection, or a player's patience. When that
// happens the server finishes anyway — and the page used to sit on "the
// narrator considers…" forever while the story was already waiting.
//
// This must NOT be an Inertia visit: an Inertia request cancels the one in
// flight, which is precisely the request being watched. Plain fetch, no side
// effects, and it only runs while something is actually pending.

let watchdog: ReturnType<typeof setInterval> | null = null;

/**
 * A birth already underway, watched by a page that did not start it.
 *
 * The server only renders this page for a campaign still interviewing or one
 * mid-birth — live, first turn open, prologue not yet written. So an 'active'
 * status here means the world is being made right now, and the page must keep
 * waiting exactly as if it had pressed Begin itself: a reload used to leave
 * the watchdog asleep and the player looking at a finished-looking interview.
 */
const birthing = computed(() => props.campaign.status === 'active');

const pending = computed(
    () => sending.value || beginning.value || building.value || birthing.value,
);

async function checkAhead() {
    if (!pending.value) return;

    try {
        const res = await fetch(
            `/campaigns/${props.campaign.id}/interview/status`,
            { headers: { Accept: 'application/json' } },
        );
        if (!res.ok) return;
        const state = await res.json();

        // The tale opened without us, prologue and all. Go there — a hard
        // navigation, because whatever request we were waiting on is no
        // longer worth resuming.
        if (state.status === 'active' && state.ready) {
            window.location.href = state.play_url;
            return;
        }

        // Live but not ready: the world is open and the prologue is still
        // being written. There is nowhere to go yet and nothing to pick up —
        // and a reload here would be an Inertia visit that cancels the very
        // request writing it. Wait.
        if (state.status === 'active') return;

        // The narrator answered but the reply never reached us (the request
        // died on the way back). Pick it up rather than sitting on a spinner.
        if (state.messages > props.messages.length) {
            router.reload({ only: ['messages', 'draft'] });
        }
    } catch {
        // Offline or mid-navigation: the next tick tries again.
    }
}

// A narrator who could not answer says so here. Nothing was written to the
// transcript, so the player's words go back in the box and Speak retries.
const speakError = ref('');

/**
 * The words in flight: emptied from the box on the tap, and held here until
 * the transcript has them.
 *
 * The box used to keep the sentence until the round trip finished, which is a
 * Claude call long enough to earn a push notification — so the player sat
 * looking at words they had already sent, with nothing on screen saying they
 * had gone. Clearing alone would have been worse: the interview does not write
 * a player's message until the narrator has answered it (so a failed call
 * leaves the words in their hands rather than stranded in the transcript), and
 * an empty box plus an empty transcript would mean the sentence had vanished
 * entirely for fifteen seconds. So it moves rather than disappears — out of the
 * box, into the conversation as its own pending line, and back into the box if
 * the narrator never answers.
 */
const spoken = ref('');

function send() {
    // `pending`, not `sending`: the Speak button is already disabled while
    // anything is in flight, but Ctrl+Enter is not — and the server refuses
    // a word spoken to a campaign that has already begun.
    if (!body.value.trim() || pending.value) return;

    const words = body.value;
    sending.value = true;
    speakError.value = '';
    spoken.value = words;
    body.value = '';

    router.post(
        `/campaigns/${props.campaign.id}/interview`,
        { body: words },
        {
            // The real message is in the transcript now; the echo has served.
            onSuccess: () => (spoken.value = ''),
            onError: (errors) => {
                // Nothing was written, so the words belong back where the
                // player can edit and send them again.
                body.value = words;
                spoken.value = '';
                speakError.value =
                    errors.body ?? 'The words did not carry. Speak them again.';
            },
            onFinish: () => (sending.value = false),
        },
    );
}

async function scrollDown() {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight });
}

onMounted(() => {
    scrollDown();
    // The narrator can be slow enough that the player puts the phone down.
    // Subscribing here means the push that says otherwise can actually land.
    void enablePush();
    watchdog = setInterval(checkAhead, 5000);
});
onUnmounted(() => {
    if (watchdog) clearInterval(watchdog);
});
watch(() => props.messages.length, scrollDown);
watch(spoken, scrollDown);

// The watchdog can pick the answer up behind the request that asked for it
// (see checkAhead). However the transcript catches up, the echo is done the
// moment the real words are in it — or the player would read their sentence
// twice.
watch(
    () => props.messages.length,
    () => (spoken.value = ''),
);
</script>

<template>
    <Head :title="`${campaign.name} — Interview`" />

    <div
        class="relative isolate mx-auto flex h-full w-full max-w-2xl flex-1 flex-col gap-4 p-4"
    >
        <AmbientBackdrop />

        <p
            class="sc-rise text-center text-xs tracking-widest text-muted-foreground uppercase"
        >
            Before the world takes shape
        </p>

        <!-- The name is the player's alone — a field, not a negotiation.
             Pre-filled with a suggestion; the die offers another. -->
        <div
            class="sc-rise flex items-center gap-2 rounded-xl border border-sidebar-border/70 bg-background/60 px-4 py-2.5 backdrop-blur-sm dark:border-sidebar-border"
        >
            <label
                for="hero-name"
                class="shrink-0 text-xs tracking-widest text-muted-foreground uppercase"
            >
                Your name
            </label>
            <input
                id="hero-name"
                v-model="heroName"
                type="text"
                maxlength="40"
                placeholder="Who steps in…"
                class="min-w-0 flex-1 border-0 bg-transparent text-sm font-medium focus:outline-none"
            />
            <button
                type="button"
                title="Suggest another name"
                aria-label="Suggest another name"
                class="shrink-0 rounded-md border border-input px-2 py-1 text-sm text-muted-foreground transition hover:border-violet-500/60 hover:text-foreground active:scale-[0.95]"
                @click="rerollName"
            >
                🎲
            </button>
        </div>

        <!-- The bargain, always on the table. The engine prices the sheet the
             narrator is drafting and shows the running total, so the balance
             is never a verdict delivered at the end. -->
        <div
            v-if="draft && !showBuilder"
            class="sc-rise rounded-xl border bg-background/60 px-4 py-3 backdrop-blur-sm"
            :class="
                draft.balance < 0
                    ? 'border-amber-500/50'
                    : 'border-sidebar-border/70 dark:border-sidebar-border'
            "
        >
            <div class="flex items-baseline justify-between gap-3">
                <p class="truncate text-sm font-medium">
                    {{ heroName.trim() || draft.name || 'Still taking shape' }}
                </p>
                <p
                    class="shrink-0 text-sm font-semibold tabular-nums"
                    :class="balanceClass(draft.balance)"
                >
                    {{ signed(draft.balance) }}
                    <span class="text-xs font-normal text-muted-foreground">{{
                        balanceWord(draft.balance)
                    }}</span>
                </p>
            </div>

            <div
                v-if="draft.gifts.length || draft.burdens.length"
                class="mt-2 flex flex-wrap gap-1.5"
            >
                <!-- The same chips the character sheet shows in play, in the
                     same colours: what the player builds here is what they
                     will read there. -->
                <span
                    v-for="gift in draft.gifts"
                    :key="`g-${gift.label}`"
                    class="rounded-lg bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-400"
                >
                    {{ gift.label }}
                    <span class="opacity-70">−{{ gift.cost }}</span>
                </span>
                <span
                    v-for="burden in draft.burdens"
                    :key="`b-${burden.label}`"
                    class="rounded-lg bg-red-500/10 px-2 py-0.5 text-xs text-red-700 dark:text-red-400"
                >
                    {{ burden.label }}
                    <span class="opacity-70">+{{ burden.refund }}</span>
                </span>
            </div>

            <p
                v-if="draft.balance < 0"
                class="mt-2 text-xs text-amber-500 italic"
            >
                Overspent by {{ -draft.balance }}. Name a real price, set a gift
                down, or step through owing.
            </p>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    :disabled="beginning || !draft.ready"
                    class="rounded-md bg-gradient-to-br from-violet-600 to-violet-800 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-violet-900/20 transition hover:from-violet-500 hover:to-violet-700 active:scale-[0.98] disabled:opacity-40"
                    @click="begin(false)"
                >
                    {{ beginning ? 'Stepping into the world…' : 'Begin →' }}
                </button>
                <button
                    v-if="draft.balance < 0"
                    type="button"
                    :disabled="beginning"
                    class="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-500 italic transition hover:border-amber-500/70 active:scale-[0.98] disabled:opacity-50"
                    @click="begin(true)"
                >
                    Begin anyway — owing the difference
                </button>
                <p class="text-xs text-muted-foreground italic">
                    Or keep talking below — nothing is fixed until you press
                    Begin.
                </p>
            </div>
        </div>

        <div
            ref="scroller"
            class="sc-rise flex-1 space-y-4 overflow-y-auto rounded-xl border border-sidebar-border/70 bg-background/60 p-4 backdrop-blur-sm dark:border-sidebar-border"
            style="animation-delay: 80ms"
        >
            <div
                v-for="message in messages"
                :key="message.id"
                class="sc-rise"
                :class="
                    message.role === 'player' ? 'ml-8 flex justify-end' : 'mr-8'
                "
            >
                <div
                    class="inline-block rounded-xl px-4 py-2 text-left text-sm whitespace-pre-wrap"
                    :class="
                        message.role === 'player'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted italic'
                    "
                >
                    {{ message.body }}
                </div>
            </div>
            <!-- Said, not yet written down. Styled as the player's own line
                 because that is what it is, dimmed because the narrator has
                 not answered it yet. -->
            <div v-if="spoken" class="sc-rise ml-8 flex justify-end">
                <div
                    class="inline-block rounded-xl bg-primary/60 px-4 py-2 text-left text-sm whitespace-pre-wrap text-primary-foreground"
                >
                    {{ spoken }}
                </div>
            </div>

            <p
                v-if="pending"
                class="sc-rise mr-8 flex items-center gap-2 text-sm text-muted-foreground italic"
            >
                <span class="flex items-end gap-1" aria-hidden="true">
                    <span
                        class="sc-drop h-1.5 w-1.5 rounded-full bg-violet-500/70"
                    />
                    <span
                        class="sc-drop h-1.5 w-1.5 rounded-full bg-violet-500/70"
                        style="animation-delay: 0.2s"
                    />
                    <span
                        class="sc-drop h-1.5 w-1.5 rounded-full bg-violet-500/70"
                        style="animation-delay: 0.4s"
                    />
                </span>
                <span v-if="beginning || building || birthing">
                    The world is making room for you — this one takes a moment.
                    You can close this; you will be told when it is ready.
                </span>
                <span v-else>The narrator considers…</span>
            </p>
        </div>

        <div v-if="showBuilder && !pending" class="sc-rise space-y-3">
            <div
                class="rounded-xl border border-sidebar-border/70 bg-background/60 p-4 backdrop-blur-sm dark:border-sidebar-border"
            >
                <div class="mb-3 flex items-baseline justify-between">
                    <p
                        class="text-xs tracking-widest text-muted-foreground uppercase"
                    >
                        Shape yourself from the old patterns
                    </p>
                    <p
                        class="text-sm font-semibold tabular-nums"
                        :class="balanceClass(balance)"
                    >
                        {{ signed(balance) }}
                        <span
                            class="text-xs font-normal text-muted-foreground"
                            >{{ balanceWord(balance) }}</span
                        >
                    </p>
                </div>

                <p class="mb-1 text-xs font-medium text-muted-foreground">
                    Gifts <span class="font-normal">(cost points)</span>
                </p>
                <div class="mb-3 flex flex-wrap gap-1.5">
                    <button
                        v-for="trait in catalog.positives"
                        :key="trait.key"
                        type="button"
                        :disabled="blocked(trait)"
                        :title="trait.description"
                        class="rounded-lg border px-2.5 py-1 text-xs transition active:scale-[0.98] disabled:opacity-30"
                        :class="
                            selected.includes(trait.key)
                                ? 'border-emerald-500 bg-emerald-600/30 text-foreground'
                                : 'border-input bg-background/60 text-muted-foreground hover:border-emerald-500/50'
                        "
                        @click="toggle(trait)"
                    >
                        {{ trait.label }}
                        <span class="opacity-70">−{{ trait.cost }}</span>
                    </button>
                </div>

                <p class="mb-1 text-xs font-medium text-muted-foreground">
                    Burdens <span class="font-normal">(refund points)</span>
                </p>
                <div class="mb-3 flex flex-wrap gap-1.5">
                    <button
                        v-for="trait in catalog.negatives"
                        :key="trait.key"
                        type="button"
                        :disabled="blocked(trait)"
                        :title="trait.description"
                        class="rounded-lg border px-2.5 py-1 text-xs transition active:scale-[0.98] disabled:opacity-30"
                        :class="
                            selected.includes(trait.key)
                                ? 'border-red-500 bg-red-600/25 text-foreground'
                                : 'border-input bg-background/60 text-muted-foreground hover:border-red-500/50'
                        "
                        @click="toggle(trait)"
                    >
                        {{ trait.label }}
                        <span class="opacity-70">+{{ trait.refund }}</span>
                    </button>
                </div>

                <p
                    v-if="selected.length"
                    class="mb-3 text-xs text-muted-foreground italic"
                >
                    {{
                        allTraits
                            .filter((t) => selected.includes(t.key))
                            .map((t) => t.description)
                            .join(' ')
                    }}
                </p>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        :disabled="building || !hasGift"
                        class="rounded-md bg-gradient-to-br px-4 py-2 text-sm font-medium text-white shadow-lg transition active:scale-[0.98] disabled:opacity-50"
                        :class="
                            balance < 0
                                ? 'from-amber-600 to-amber-800 shadow-amber-900/20 hover:from-amber-500 hover:to-amber-700'
                                : 'from-violet-600 to-violet-800 shadow-violet-900/20 hover:from-violet-500 hover:to-violet-700'
                        "
                        @click="confirmBuild"
                    >
                        {{
                            building
                                ? 'Being born…'
                                : balance < 0
                                  ? 'Step in anyway — owing'
                                  : 'Step into the world'
                        }}
                    </button>
                </div>
                <p
                    v-if="balance < 0"
                    class="mt-2 text-xs text-amber-500 italic"
                >
                    Overspent — set a gift down, take up another burden, or step
                    in regardless and carry the shortfall as a debt the world
                    remembers.
                </p>
            </div>
        </div>

        <div
            v-if="suggestions.length && !pending && !showBuilder"
            class="sc-rise space-y-1.5"
        >
            <!-- The palette is the ledger's own — violet buys, amber pays —
                 but the ledger only appears once something has been drafted,
                 and these chips are on screen before that. One short line, and
                 only while there is actually a contrast to read. -->
            <p v-if="kinded" class="text-[10px] text-muted-foreground">
                Green reaches for something; red names its price.
            </p>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="suggestion in suggestions"
                    :key="suggestion.text"
                    type="button"
                    class="max-w-full rounded-lg border px-3 py-1.5 text-left text-xs italic transition active:scale-[0.98]"
                    :class="
                        adopted(suggestion)
                            ? `${KIND_SELECTED[suggestion.kind]} text-foreground`
                            : `${KIND_STYLES[suggestion.kind]} text-muted-foreground hover:text-foreground`
                    "
                    @click="adopt(suggestion)"
                >
                    {{ suggestion.text }}
                    <span v-if="KIND_LABELS[suggestion.kind]" class="sr-only">
                        — {{ KIND_LABELS[suggestion.kind] }}
                    </span>
                </button>
            </div>
        </div>

        <button
            type="button"
            class="sc-rise self-start text-xs text-violet-400/90 italic transition hover:text-violet-300"
            @click="showBuilder = !showBuilder"
        >
            {{
                showBuilder
                    ? '← Back to speaking freely'
                    : "Can't find the words? Shape yourself from the old patterns →"
            }}
        </button>

        <p
            v-if="speakError && !showBuilder"
            class="sc-rise text-xs text-amber-500 italic"
        >
            {{ speakError }}
        </p>

        <form v-if="!showBuilder" class="flex gap-2" @submit.prevent="send">
            <textarea
                ref="speakBox"
                v-model="body"
                rows="3"
                maxlength="2000"
                placeholder="Describe yourself — form, temperament, gifts, and their price…"
                class="flex-1 resize-none rounded-md border border-input bg-background px-3 py-2 text-sm"
                @keydown.ctrl.enter.prevent="send"
                @keydown.meta.enter.prevent="send"
            />
            <button
                type="submit"
                :disabled="pending || !body.trim()"
                class="self-end rounded-md bg-gradient-to-br from-violet-600 to-violet-800 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-violet-900/20 transition hover:from-violet-500 hover:to-violet-700 active:scale-[0.98] disabled:opacity-50"
            >
                Speak
            </button>
        </form>
    </div>
</template>
