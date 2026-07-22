<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { nextTick, onMounted, ref, watch } from 'vue';

interface Message {
    id: number;
    role: 'player' | 'narrator';
    body: string;
}

const props = defineProps<{
    campaign: { id: number; name: string; status: string };
    messages: Message[];
}>();

const body = ref('');
const sending = ref(false);
const scroller = ref<HTMLElement | null>(null);

function send() {
    if (!body.value.trim() || sending.value) return;
    sending.value = true;
    router.post(
        `/campaigns/${props.campaign.id}/interview`,
        { body: body.value },
        {
            onSuccess: () => (body.value = ''),
            onFinish: () => (sending.value = false),
        },
    );
}

async function scrollDown() {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight });
}

onMounted(scrollDown);
watch(() => props.messages.length, scrollDown);
</script>

<template>
    <Head :title="`${campaign.name} — Interview`" />

    <div class="mx-auto flex h-full w-full max-w-2xl flex-1 flex-col gap-4 p-4">
        <p class="text-center text-xs tracking-widest text-muted-foreground uppercase">Before the world takes shape</p>

        <div ref="scroller" class="flex-1 space-y-4 overflow-y-auto rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div
                v-for="message in messages"
                :key="message.id"
                :class="message.role === 'player' ? 'ml-8 text-right' : 'mr-8'"
            >
                <div
                    class="inline-block rounded-xl px-4 py-2 text-sm whitespace-pre-wrap"
                    :class="message.role === 'player' ? 'bg-primary text-primary-foreground' : 'bg-muted italic'"
                >
                    {{ message.body }}
                </div>
            </div>
            <p v-if="sending" class="mr-8 text-sm text-muted-foreground italic">The narrator considers…</p>
        </div>

        <form class="flex gap-2" @submit.prevent="send">
            <textarea
                v-model="body"
                rows="3"
                maxlength="2000"
                placeholder="Describe yourself — form, temperament, gifts, and their price…"
                class="flex-1 resize-none rounded-md border border-input bg-background px-3 py-2 text-sm"
                @keydown.enter.exact.prevent="send"
            />
            <button
                type="submit"
                :disabled="sending || !body.trim()"
                class="self-end rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
            >
                Speak
            </button>
        </form>
    </div>
</template>
