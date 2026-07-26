<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AmbientBackdrop from '@/components/game/AmbientBackdrop.vue';

interface CampaignRow {
    id: number;
    name: string;
    status: string;
    title: string | null;
    character: string | null;
    started_at: string | null;
    ended_at: string | null;
}

interface AspectOption {
    key: string;
    label: string;
    brief: string;
}

interface LandOption {
    key: string;
    label: string;
    land: string;
    genres: string[];
}

const props = defineProps<{
    campaigns: CampaignRow[];
    characters: { id: number; name: string; from: string }[];
    genres: AspectOption[];
    drives: AspectOption[];
    techLevels: AspectOption[];
    lands: LandOption[];
}>();

const newName = ref('');
const characterId = ref<number | ''>('');
const premise = ref('');
const opening = ref('');
const tone = ref('');
const creating = ref(false);

// The composer is a tall form, and most visits are here to continue a tale,
// not start one — so it folds to its header whenever campaigns already
// exist. v-show, not v-if: folding never loses what was typed.
const composerOpen = ref(props.campaigns.length === 0);

// Each story axis is a picked option OR the player's own words. Empty means
// the engine decides — the default, and the reason two tales are never the
// same. OTHER switches the control to a free-text box.
const OTHER = '__other';

const genre = ref('');
const genreText = ref('');
const drive = ref('');
const driveText = ref('');
const tech = ref('');
const techText = ref('');

function aspectValue(picked: string, typed: string): string | null {
    if (picked === OTHER) return typed.trim() || null;
    return picked || null;
}

function briefFor(options: AspectOption[], picked: string): string | null {
    return options.find((o) => o.key === picked)?.brief ?? null;
}

const genreBrief = computed(() => briefFor(props.genres, genre.value));
const driveBrief = computed(() => briefFor(props.drives, drive.value));
const techBrief = computed(() => briefFor(props.techLevels, tech.value));

// Where it's set. Same shape as the axes — leave it alone and the engine
// rolls a land (the usual path, and the reason two tales open in unrelated
// country); name one from the catalog; or describe somewhere of your own.
const land = ref('');
const landText = ref('');

// Only lands that can honestly wear the picked genre, so the menu never
// offers chalk downs to a starfaring tale. A typed genre constrains nothing —
// the catalog has never heard of it, and neither has the roll.
const landChoices = computed(() => {
    const picked = genre.value;
    if (!picked || picked === OTHER) return props.lands;
    const fitting = props.lands.filter((l) => l.genres.includes(picked));
    return fitting.length ? fitting : props.lands;
});

const landBrief = computed(
    () => props.lands.find((l) => l.key === land.value)?.land ?? null,
);

// A genre change can strand the picked land outside its own pool; drop back
// to the roll rather than silently sending a land the genre cannot wear.
watch(landChoices, (choices) => {
    if (
        land.value &&
        land.value !== OTHER &&
        !choices.some((l) => l.key === land.value)
    ) {
        land.value = '';
    }
});

function create() {
    if (!newName.value.trim()) return;
    creating.value = true;
    router.post(
        '/campaigns',
        {
            name: newName.value,
            character_id: characterId.value === '' ? null : characterId.value,
            premise: premise.value.trim() || null,
            opening: opening.value.trim() || null,
            tone: tone.value.trim() || null,
            // A named land is a catalog key; own words go to `setting` and
            // leave the engine free to roll the kit underneath them.
            world_flavor: land.value === OTHER ? null : land.value || null,
            setting:
                land.value === OTHER ? landText.value.trim() || null : null,
            genre: aspectValue(genre.value, genreText.value),
            drive: aspectValue(drive.value, driveText.value),
            tech_level: aspectValue(tech.value, techText.value),
        },
        { onFinish: () => (creating.value = false) },
    );
}

// Deleting burns the whole book — it asks first, and never rides on the
// row's own click-through.
const condemned = ref<CampaignRow | null>(null);
const deleting = ref(false);

function destroy() {
    if (!condemned.value || deleting.value) return;
    deleting.value = true;
    router.delete(`/campaigns/${condemned.value.id}`, {
        onSuccess: () => (condemned.value = null),
        onFinish: () => (deleting.value = false),
    });
}

function statusLabel(status: string): string {
    switch (status) {
        case 'interview':
            return 'Being born…';
        case 'active':
            return 'The story continues';
        case 'completed':
            return 'A finished book';
        default:
            return status;
    }
}
</script>

<template>
    <Head title="Campaigns" />

    <div
        class="relative isolate mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 p-4"
    >
        <AmbientBackdrop />

        <div
            class="sc-rise rounded-xl border border-sidebar-border/70 bg-background/60 p-5 backdrop-blur-sm dark:border-sidebar-border"
        >
            <button
                type="button"
                class="flex w-full items-center justify-between gap-2 text-left"
                :aria-expanded="composerOpen"
                @click="composerOpen = !composerOpen"
            >
                <h2 class="text-lg font-semibold">Begin a new tale</h2>
                <svg
                    class="h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200"
                    :class="composerOpen ? 'rotate-180' : ''"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </button>
            <p
                v-show="composerOpen"
                class="mt-1 mb-4 text-sm text-muted-foreground"
            >
                Only the name is required. Everything else colors the telling —
                the interview, every chapter, the book's close — and never
                changes what the engine allows.
            </p>
            <form
                v-show="composerOpen"
                class="flex flex-col gap-3"
                @submit.prevent="create"
            >
                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                        >The campaign's name</label
                    >
                    <input
                        v-model="newName"
                        type="text"
                        maxlength="80"
                        placeholder="The Drowned Harbor Job…"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </div>

                <div v-if="characters.length">
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                    >
                        Who steps in
                        <span class="font-normal"
                            >(a returning hero skips the interview)</span
                        >
                    </label>
                    <select
                        v-model="characterId"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option value="">
                            A new soul — begin with the interview
                        </option>
                        <option
                            v-for="c in characters"
                            :key="c.id"
                            :value="c.id"
                        >
                            Return as {{ c.name }} (from “{{ c.from }}”)
                        </option>
                    </select>
                </div>

                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                    >
                        Premise or end goal
                        <span class="font-normal"
                            >(optional — you decide when it's met)</span
                        >
                    </label>
                    <input
                        v-model="premise"
                        type="text"
                        maxlength="500"
                        placeholder="Find my sister, whatever it costs…"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </div>

                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                    >
                        How it opens
                        <span class="font-normal"
                            >(optional — the moment the first scene finds
                            you)</span
                        >
                    </label>
                    <textarea
                        v-model="opening"
                        rows="2"
                        maxlength="500"
                        placeholder="Waking in a locked room with no memory of the night before…"
                        class="w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </div>

                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                    >
                        Tone of the telling
                        <span class="font-normal">(optional)</span>
                    </label>
                    <input
                        v-model="tone"
                        type="text"
                        maxlength="120"
                        placeholder="Rain-soaked, gothic, quietly hopeful…"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                        >
                            Genre of the world
                            <span class="font-normal">(optional)</span>
                        </label>
                        <select
                            v-model="genre"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Surprise me</option>
                            <option
                                v-for="g in genres"
                                :key="g.key"
                                :value="g.key"
                            >
                                {{ g.label }}
                            </option>
                            <option :value="OTHER">Something else…</option>
                        </select>
                        <input
                            v-if="genre === OTHER"
                            v-model="genreText"
                            type="text"
                            :maxlength="120"
                            placeholder="Steampunk noir, weird west, deep-sea…"
                            class="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                        <p
                            v-else-if="genreBrief"
                            class="mt-1.5 text-xs text-muted-foreground"
                        >
                            {{ genreBrief }}
                        </p>
                    </div>

                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                        >
                            What drives the tale
                            <span class="font-normal">(optional)</span>
                        </label>
                        <select
                            v-model="drive"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Let it find its own shape</option>
                            <option
                                v-for="d in drives"
                                :key="d.key"
                                :value="d.key"
                            >
                                {{ d.label }}
                            </option>
                            <option :value="OTHER">Something else…</option>
                        </select>
                        <input
                            v-if="drive === OTHER"
                            v-model="driveText"
                            type="text"
                            :maxlength="120"
                            placeholder="Clear my name, find who did it, get paid…"
                            class="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                        <p
                            v-else-if="driveBrief"
                            class="mt-1.5 text-xs text-muted-foreground"
                        >
                            {{ driveBrief }}
                        </p>
                    </div>
                </div>

                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                    >
                        Where it's set
                        <span class="font-normal"
                            >(optional — leave it and the world rolls its
                            own)</span
                        >
                    </label>
                    <select
                        v-model="land"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Somewhere I haven't been yet</option>
                        <option
                            v-for="l in landChoices"
                            :key="l.key"
                            :value="l.key"
                        >
                            {{ l.label }}
                        </option>
                        <option :value="OTHER">Somewhere of my own…</option>
                    </select>
                    <input
                        v-if="land === OTHER"
                        v-model="landText"
                        type="text"
                        :maxlength="200"
                        placeholder="A city built inside a stopped clock…"
                        class="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <p
                        v-else-if="landBrief"
                        class="mt-1.5 text-xs text-muted-foreground"
                    >
                        {{ landBrief }}
                    </p>
                </div>

                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                    >
                        Magic &amp; machinery
                        <span class="font-normal"
                            >(optional — colors the world, never your
                            abilities)</span
                        >
                    </label>
                    <select
                        v-model="tech"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Surprise me</option>
                        <option
                            v-for="t in techLevels"
                            :key="t.key"
                            :value="t.key"
                        >
                            {{ t.label }}
                        </option>
                        <option :value="OTHER">Something else…</option>
                    </select>
                    <input
                        v-if="tech === OTHER"
                        v-model="techText"
                        type="text"
                        :maxlength="120"
                        placeholder="Bio-tech, dream logic, old gods only…"
                        class="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <p
                        v-else-if="techBrief"
                        class="mt-1.5 text-xs text-muted-foreground"
                    >
                        {{ techBrief }}
                    </p>
                </div>

                <p class="text-xs text-muted-foreground italic">
                    A world will be forged for this tale — its own land, its own
                    people — from whatever you set here, and grown outward as
                    you walk it. Anything you leave alone, the world decides.
                    None of it changes the rules of play.
                </p>

                <button
                    type="submit"
                    :disabled="creating || !newName.trim()"
                    class="rounded-md bg-gradient-to-br from-violet-600 to-violet-800 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-violet-900/20 transition hover:from-violet-500 hover:to-violet-700 hover:shadow-violet-700/30 active:scale-[0.98] disabled:opacity-50"
                >
                    {{ creating ? 'Opening…' : 'Begin the tale' }}
                </button>
            </form>
        </div>

        <div v-if="campaigns.length" class="flex flex-col gap-3">
            <div
                v-for="(campaign, index) in campaigns"
                :key="campaign.id"
                role="button"
                tabindex="0"
                class="sc-rise cursor-pointer rounded-xl border border-sidebar-border/70 bg-background/60 p-4 text-left backdrop-blur-sm transition-all duration-200 hover:-translate-y-0.5 hover:bg-accent hover:shadow-lg hover:shadow-violet-500/10 active:translate-y-0 active:scale-[0.99] dark:border-sidebar-border"
                :style="{
                    animationDelay: `${100 + Math.min(index, 8) * 60}ms`,
                }"
                @click="router.visit(`/campaigns/${campaign.id}`)"
                @keydown.enter="router.visit(`/campaigns/${campaign.id}`)"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="font-semibold">{{
                        campaign.title ?? campaign.name
                    }}</span>
                    <span
                        class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground"
                    >
                        <span
                            v-if="campaign.status === 'active'"
                            class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"
                            aria-hidden="true"
                        />
                        {{ statusLabel(campaign.status) }}
                        <button
                            type="button"
                            class="rounded-md border border-red-500/40 px-2.5 py-1 text-xs font-medium text-red-600 transition hover:border-red-500 hover:bg-red-500/10 active:scale-95 dark:text-red-400"
                            :title="`Delete “${campaign.title ?? campaign.name}”`"
                            @click.stop="condemned = campaign"
                        >
                            Delete
                        </button>
                    </span>
                </div>
                <div class="mt-1 text-sm text-muted-foreground">
                    <span v-if="campaign.character"
                        >{{ campaign.character }} ·
                    </span>
                    <span v-if="campaign.started_at">{{
                        campaign.started_at
                    }}</span>
                    <span v-if="campaign.ended_at">
                        — {{ campaign.ended_at }}</span
                    >
                </div>
            </div>
        </div>

        <div
            v-else
            class="sc-rise py-8 text-center"
            style="animation-delay: 120ms"
        >
            <p
                class="sc-twinkle text-violet-500/60 select-none"
                aria-hidden="true"
            >
                ✦
            </p>
            <p class="mt-2 text-sm text-muted-foreground">
                No campaigns yet. Every book starts with a first page.
            </p>
        </div>

        <!-- Delete confirmation -->
        <Transition name="fade">
            <div
                v-if="condemned"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
                @click.self="condemned = null"
            >
                <div
                    class="sc-rise w-full max-w-md rounded-xl border border-sidebar-border bg-background p-6"
                >
                    <h3 class="mb-2 font-semibold">
                        Delete “{{ condemned.title ?? condemned.name }}”?
                    </h3>
                    <p class="mb-4 text-sm text-muted-foreground">
                        This burns the whole book — every chapter, the
                        character, the story so far. It cannot be undone. If you
                        only want to stop playing, end the tale instead and keep
                        the book.
                    </p>
                    <div class="flex flex-col gap-2">
                        <button
                            class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="deleting"
                            @click="destroy"
                        >
                            {{ deleting ? 'Burning…' : 'Delete it forever' }}
                        </button>
                        <button
                            class="px-4 py-2 text-sm text-muted-foreground"
                            @click="condemned = null"
                        >
                            Keep it
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>
