<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface CampaignRow {
    id: number;
    name: string;
    status: string;
    title: string | null;
    character: string | null;
    started_at: string | null;
    ended_at: string | null;
}

defineProps<{
    campaigns: CampaignRow[];
    characters: { id: number; name: string; from: string }[];
    zones: { id: number; name: string }[];
}>();

const newName = ref('');
const characterId = ref<number | ''>('');
const premise = ref('');
const tone = ref('');
const zoneId = ref<number | ''>('');
const creating = ref(false);

function create() {
    if (!newName.value.trim()) return;
    creating.value = true;
    router.post(
        '/campaigns',
        {
            name: newName.value,
            character_id: characterId.value === '' ? null : characterId.value,
            premise: premise.value.trim() || null,
            tone: tone.value.trim() || null,
            starting_zone_id: zoneId.value === '' ? null : zoneId.value,
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

    <div class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 p-4">
        <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
            <h2 class="mb-1 text-lg font-semibold">Begin a new tale</h2>
            <p class="mb-4 text-sm text-muted-foreground">
                Only the name is required. Everything else colors the telling — the interview, every chapter, the book's close —
                and never changes what the engine allows.
            </p>
            <form class="flex flex-col gap-3" @submit.prevent="create">
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">The campaign's name</label>
                    <input
                        v-model="newName"
                        type="text"
                        maxlength="80"
                        placeholder="The Drowned Harbor Job…"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </div>

                <div v-if="characters.length">
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">
                        Who steps in <span class="font-normal">(a returning hero skips the interview)</span>
                    </label>
                    <select v-model="characterId" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                        <option value="">A new soul — begin with the interview</option>
                        <option v-for="c in characters" :key="c.id" :value="c.id">Return as {{ c.name }} (from “{{ c.from }}”)</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">
                        Premise or end goal <span class="font-normal">(optional — you decide when it's met)</span>
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
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">
                        Tone of the telling <span class="font-normal">(optional)</span>
                    </label>
                    <input
                        v-model="tone"
                        type="text"
                        maxlength="120"
                        placeholder="Rain-soaked, gothic, quietly hopeful…"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </div>

                <div v-if="zones.length > 1">
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">
                        Where it begins <span class="font-normal">(optional)</span>
                    </label>
                    <select v-model="zoneId" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                        <option value="">Let the world decide</option>
                        <option v-for="z in zones" :key="z.id" :value="z.id">Begin in {{ z.name }}</option>
                    </select>
                </div>

                <button
                    type="submit"
                    :disabled="creating || !newName.trim()"
                    class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-50"
                >
                    {{ creating ? 'Opening…' : 'Begin the tale' }}
                </button>
            </form>
        </div>

        <div v-if="campaigns.length" class="flex flex-col gap-3">
            <div
                v-for="campaign in campaigns"
                :key="campaign.id"
                role="button"
                tabindex="0"
                class="cursor-pointer rounded-xl border border-sidebar-border/70 p-4 text-left transition hover:bg-accent dark:border-sidebar-border"
                @click="router.visit(`/campaigns/${campaign.id}`)"
                @keydown.enter="router.visit(`/campaigns/${campaign.id}`)"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="font-semibold">{{ campaign.title ?? campaign.name }}</span>
                    <span class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                        {{ statusLabel(campaign.status) }}
                        <button
                            type="button"
                            class="rounded px-1 text-muted-foreground/60 transition hover:text-red-500"
                            :title="`Delete “${campaign.title ?? campaign.name}”`"
                            @click.stop="condemned = campaign"
                        >
                            ✕
                        </button>
                    </span>
                </div>
                <div class="mt-1 text-sm text-muted-foreground">
                    <span v-if="campaign.character">{{ campaign.character }} · </span>
                    <span v-if="campaign.started_at">{{ campaign.started_at }}</span>
                    <span v-if="campaign.ended_at"> — {{ campaign.ended_at }}</span>
                </div>
            </div>
        </div>

        <p v-else class="text-center text-sm text-muted-foreground">No campaigns yet. Every book starts with a first page.</p>

        <!-- Delete confirmation -->
        <div v-if="condemned" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="condemned = null">
            <div class="w-full max-w-md rounded-xl border border-sidebar-border bg-background p-6">
                <h3 class="mb-2 font-semibold">Delete “{{ condemned.title ?? condemned.name }}”?</h3>
                <p class="mb-4 text-sm text-muted-foreground">
                    This burns the whole book — every chapter, the character, the story so far. It cannot be undone. If you only
                    want to stop playing, end the tale instead and keep the book.
                </p>
                <div class="flex flex-col gap-2">
                    <button
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="deleting"
                        @click="destroy"
                    >
                        {{ deleting ? 'Burning…' : 'Delete it forever' }}
                    </button>
                    <button class="px-4 py-2 text-sm text-muted-foreground" @click="condemned = null">Keep it</button>
                </div>
            </div>
        </div>
    </div>
</template>
