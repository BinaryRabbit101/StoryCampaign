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

defineProps<{ campaigns: CampaignRow[] }>();

const newName = ref('');
const creating = ref(false);

function create() {
    if (!newName.value.trim()) return;
    creating.value = true;
    router.post('/campaigns', { name: newName.value }, { onFinish: () => (creating.value = false) });
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
                A campaign starts with an interview: describe who you are, and the world will take shape around you.
            </p>
            <form class="flex gap-2" @submit.prevent="create">
                <input
                    v-model="newName"
                    type="text"
                    maxlength="80"
                    placeholder="Name this campaign…"
                    class="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
                <button
                    type="submit"
                    :disabled="creating || !newName.trim()"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
                >
                    Begin
                </button>
            </form>
        </div>

        <div v-if="campaigns.length" class="flex flex-col gap-3">
            <button
                v-for="campaign in campaigns"
                :key="campaign.id"
                class="rounded-xl border border-sidebar-border/70 p-4 text-left transition hover:bg-accent dark:border-sidebar-border"
                @click="router.visit(`/campaigns/${campaign.id}`)"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="font-semibold">{{ campaign.title ?? campaign.name }}</span>
                    <span class="shrink-0 text-xs text-muted-foreground">{{ statusLabel(campaign.status) }}</span>
                </div>
                <div class="mt-1 text-sm text-muted-foreground">
                    <span v-if="campaign.character">{{ campaign.character }} · </span>
                    <span v-if="campaign.started_at">{{ campaign.started_at }}</span>
                    <span v-if="campaign.ended_at"> — {{ campaign.ended_at }}</span>
                </div>
            </button>
        </div>

        <p v-else class="text-center text-sm text-muted-foreground">No campaigns yet. Every book starts with a first page.</p>
    </div>
</template>
