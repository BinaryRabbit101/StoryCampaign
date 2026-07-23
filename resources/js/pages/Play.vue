<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import SlotPicker from '@/components/game/SlotPicker.vue';
import { enablePush } from '@/lib/push';
import type { ActionCard, CharacterMeters, SlotChoice } from '@/types/game';

const props = defineProps<{
    campaign: { id: number; name: string; status: string };
    character: {
        name: string;
        description: string;
        status: string;
        meters: CharacterMeters;
        capabilities: { capability: string; magnitude: number | null; grade: string | null; scope: Record<string, string> | null }[];
        constraints: { name: string; params: Record<string, unknown> | null; coupled_capability: string | null }[];
    };
    turn: {
        id: number;
        number: number;
        status: string;
        situation: string;
        cards: { pre: ActionCard[]; main: ActionCard[]; post: ActionCard[] } | null;
        resolves_at: string | null;
    } | null;
    latestChapter: { number: number; kind: string; intent_line: string | null; body: string } | null;
}>();

const pre = ref<SlotChoice | null>(null);
const main = ref<SlotChoice | null>(null);
const post = ref<SlotChoice | null>(null);
const intentText = ref('');
const submitting = ref(false);
const showSheet = ref(false);
const showGrowth = ref(false);
const showEnd = ref(false);
const growthText = ref('');

const locked = computed(() => props.turn !== null && props.turn.status !== 'awaiting_player');

// Staged, visible resource commitment: the running cost of the whole chain.
const runningCost = computed(() => {
    const totals: Record<string, number> = {};
    for (const [choice, slot] of [
        [pre.value, 'pre'],
        [main.value, 'main'],
        [post.value, 'post'],
    ] as const) {
        if (!choice || !props.turn?.cards) continue;
        const card = props.turn.cards[slot].find((c) => c.id === choice.card_id);
        for (const cost of card?.cost ?? []) {
            totals[cost.meter] = (totals[cost.meter] ?? 0) + cost.amount;
        }
    }
    return Object.entries(totals);
});

const countdown = ref('');
let timer: ReturnType<typeof setInterval> | null = null;

function tick() {
    if (!props.turn?.resolves_at) {
        countdown.value = '';
        return;
    }
    const remaining = new Date(props.turn.resolves_at).getTime() - Date.now();
    if (remaining <= 0) {
        countdown.value = 'any moment now';
        return;
    }
    const minutes = Math.floor(remaining / 60000);
    const seconds = Math.floor((remaining % 60000) / 1000);
    countdown.value = `${minutes}:${String(seconds).padStart(2, '0')}`;
}

onMounted(() => {
    tick();
    timer = setInterval(() => {
        tick();
        // While locked, poll for the resolved chapter.
        if (locked.value && Math.random() < 0.2) router.reload({ only: ['turn', 'latestChapter', 'character'] });
    }, 5000);
    void enablePush();
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const resolvingNow = ref(false);

// The impatience valve: skip the rest of the idle wait. The engine still
// resolves only the committed submission — this changes when, never what.
function resolveNow() {
    if (resolvingNow.value) return;
    resolvingNow.value = true;
    router.post(
        `/play/${props.campaign.id}/resolve-now`,
        {},
        { onFinish: () => (resolvingNow.value = false) },
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
            intent_text: intentText.value || null,
        },
        {
            onSuccess: () => {
                pre.value = main.value = post.value = null;
                intentText.value = '';
            },
            onFinish: () => (submitting.value = false),
        },
    );
}

function requestGrowth() {
    if (!growthText.value.trim()) return;
    router.post(
        `/campaigns/${props.campaign.id}/grow`,
        { body: growthText.value },
        { onSuccess: () => ((growthText.value = ''), (showGrowth.value = false)) },
    );
}

function endCampaign(coda: boolean) {
    router.post(`/campaigns/${props.campaign.id}/end`, { coda });
}

function capabilityLabel(c: { capability: string; magnitude: number | null; grade: string | null }): string {
    const name = c.capability.replace('_', ' ');
    if (c.magnitude !== null) return `${name}(${c.magnitude})`;
    if (c.grade) return `${name}(${c.grade})`;
    return name;
}

const healthPct = computed(() => (props.character.meters.health.current / props.character.meters.health.max) * 100);
</script>

<template>
    <Head :title="campaign.name" />

    <div class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-5 p-4 pb-16">
        <!-- Character strip -->
        <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div class="flex items-baseline justify-between gap-2">
                <button class="font-semibold hover:underline" @click="showSheet = !showSheet">{{ character.name }}</button>
                <div class="flex items-center gap-3 text-xs text-muted-foreground">
                    <span
                        v-for="(pool, name) in character.meters.tempo"
                        :key="name"
                        class="text-violet-600 dark:text-violet-400"
                    >
                        {{ String(name).replace('_', ' ') }} {{ pool.current }}/{{ pool.max }}
                    </span>
                    <span>❤ {{ character.meters.health.current }}/{{ character.meters.health.max }}</span>
                </div>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full transition-all"
                    :class="healthPct <= 25 ? 'bg-red-500' : 'bg-emerald-500'"
                    :style="{ width: `${healthPct}%` }"
                />
            </div>

            <div v-if="showSheet" class="mt-3 space-y-2 border-t border-sidebar-border/50 pt-3 text-sm">
                <p class="text-muted-foreground italic">{{ character.description }}</p>
                <div class="flex flex-wrap gap-1">
                    <span
                        v-for="c in character.capabilities"
                        :key="c.capability"
                        class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-400"
                    >
                        {{ capabilityLabel(c) }}
                    </span>
                    <span
                        v-for="c in character.constraints"
                        :key="c.name"
                        class="rounded-full bg-red-500/10 px-2 py-0.5 text-xs text-red-700 dark:text-red-400"
                    >
                        {{ c.name.replace('_', ' ') }}
                    </span>
                </div>
                <div class="flex gap-3 pt-1 text-xs">
                    <button class="text-muted-foreground underline" @click="showGrowth = !showGrowth">ask to grow</button>
                    <button class="text-muted-foreground underline" @click="router.visit(`/campaigns/${campaign.id}/book`)">read the book so far</button>
                    <button class="text-red-500 underline" @click="showEnd = true">end this tale</button>
                </div>
            </div>

            <form v-if="showGrowth" class="mt-3 flex gap-2" @submit.prevent="requestGrowth">
                <input
                    v-model="growthText"
                    maxlength="2000"
                    placeholder="Describe how you want to grow — the world will answer…"
                    class="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
                <button type="submit" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground">Ask</button>
            </form>
        </div>

        <!-- Latest chapter -->
        <article v-if="latestChapter" class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
            <p class="mb-1 text-xs tracking-widest text-muted-foreground uppercase">
                {{ latestChapter.kind === 'prologue' ? 'Prologue' : latestChapter.kind === 'chronicle' ? 'The world shifted' : `Chapter ${latestChapter.number}` }}
            </p>
            <p v-if="latestChapter.intent_line" class="mb-2 text-sm text-muted-foreground italic">{{ latestChapter.intent_line }}</p>
            <div class="space-y-3 font-serif leading-relaxed whitespace-pre-wrap">{{ latestChapter.body }}</div>
        </article>

        <!-- Situation + form / lock -->
        <div v-if="turn" class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
            <p class="mb-1 text-xs tracking-widest text-muted-foreground uppercase">The situation</p>
            <p class="mb-4 text-sm">{{ turn.situation }}</p>

            <div v-if="locked" class="rounded-lg bg-muted p-4 text-center text-sm text-muted-foreground">
                <p class="font-medium">Your choice is made. The world is turning.</p>
                <p v-if="resolvingNow" class="mt-1">The chapter is being written…</p>
                <p v-else-if="countdown" class="mt-1">The next chapter arrives in <span class="font-mono">{{ countdown }}</span></p>
                <p v-else class="mt-1">The next chapter is being written…</p>
                <button
                    v-if="turn.status === 'locked'"
                    :disabled="resolvingNow"
                    class="mt-3 rounded-md border border-input px-4 py-2 text-sm font-medium text-foreground disabled:opacity-50"
                    @click="resolveNow"
                >
                    {{ resolvingNow ? 'Turning the page…' : "Don't make me wait — turn the page now" }}
                </button>
            </div>

            <form v-else-if="turn.cards" class="space-y-6" @submit.prevent="submit">
                <SlotPicker
                    v-model="pre"
                    title="Set up"
                    hint="positioning & tempo before the act"
                    :cards="turn.cards.pre"
                    :optional="true"
                />
                <SlotPicker
                    v-model="main"
                    title="The act"
                    hint="the beat that matters"
                    :cards="turn.cards.main"
                    :optional="false"
                />
                <SlotPicker
                    v-model="post"
                    title="Afterward"
                    hint="if the moment allows it"
                    :cards="turn.cards.post"
                    :optional="true"
                />

                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">
                        In your own words <span class="font-normal">(colors the telling; changes nothing)</span>
                    </label>
                    <input
                        v-model="intentText"
                        maxlength="280"
                        placeholder="I whisper a prayer as I swing…"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </div>

                <div class="flex items-center justify-between gap-3">
                    <span v-if="runningCost.length" class="text-xs text-violet-600 dark:text-violet-400">
                        This plan spends: {{ runningCost.map(([meter, amount]) => `${amount} ${meter.replace('_', ' ')}`).join(', ') }}
                    </span>
                    <span v-else class="text-xs text-muted-foreground">No charges spent.</span>
                    <button
                        type="submit"
                        :disabled="!main || submitting"
                        class="rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-50"
                    >
                        {{ submitting ? 'Committing…' : 'Commit to it' }}
                    </button>
                </div>
            </form>
        </div>

        <!-- End-campaign dialog -->
        <div v-if="showEnd" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showEnd = false">
            <div class="w-full max-w-md rounded-xl border border-sidebar-border bg-background p-6">
                <h3 class="mb-2 font-semibold">End this tale?</h3>
                <p class="mb-4 text-sm text-muted-foreground">
                    Your chapters will be bound into a book. You can let the narrator write a brief closing coda, or leave the
                    story exactly where it lies.
                </p>
                <div class="flex flex-col gap-2">
                    <button class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" @click="endCampaign(true)">
                        End with a closing chapter
                    </button>
                    <button class="rounded-md border border-input px-4 py-2 text-sm" @click="endCampaign(false)">
                        Leave it where it lies
                    </button>
                    <button class="px-4 py-2 text-sm text-muted-foreground" @click="showEnd = false">Keep playing</button>
                </div>
            </div>
        </div>
    </div>
</template>
