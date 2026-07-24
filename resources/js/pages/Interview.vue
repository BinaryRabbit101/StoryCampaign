<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AmbientBackdrop from '@/components/game/AmbientBackdrop.vue';

interface Message {
    id: number;
    role: 'player' | 'narrator';
    body: string;
    suggestions: string[] | null;
}

const props = defineProps<{
    campaign: { id: number; name: string; status: string };
    messages: Message[];
}>();

const body = ref('');
const sending = ref(false);
const scroller = ref<HTMLElement | null>(null);
const speakBox = ref<HTMLTextAreaElement | null>(null);

// A hand for the stuck: the narrator's latest question may carry example
// answers. Tapping one fills the textarea — the player can still reshape
// it before speaking.
const suggestions = computed(() => {
    const last = props.messages[props.messages.length - 1];
    return last?.role === 'narrator' && last.suggestions?.length
        ? last.suggestions
        : [];
});

function adopt(suggestion: string) {
    body.value = suggestion;
    speakBox.value?.focus();
}

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

    <div
        class="relative isolate mx-auto flex h-full w-full max-w-2xl flex-1 flex-col gap-4 p-4"
    >
        <AmbientBackdrop />

        <p
            class="sc-rise text-center text-xs tracking-widest text-muted-foreground uppercase"
        >
            Before the world takes shape
        </p>

        <div
            ref="scroller"
            class="sc-rise flex-1 space-y-4 overflow-y-auto rounded-xl border border-sidebar-border/70 bg-background/60 p-4 backdrop-blur-sm dark:border-sidebar-border"
            style="animation-delay: 80ms"
        >
            <div
                v-for="message in messages"
                :key="message.id"
                class="sc-rise"
                :class="message.role === 'player' ? 'ml-8 text-right' : 'mr-8'"
            >
                <div
                    class="inline-block rounded-xl px-4 py-2 text-sm whitespace-pre-wrap"
                    :class="
                        message.role === 'player'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted italic'
                    "
                >
                    {{ message.body }}
                </div>
            </div>
            <p
                v-if="sending"
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
                The narrator considers…
            </p>
        </div>

        <div
            v-if="suggestions.length && !sending"
            class="sc-rise flex flex-wrap gap-2"
        >
            <button
                v-for="suggestion in suggestions"
                :key="suggestion"
                type="button"
                class="max-w-full rounded-lg border border-violet-500/30 bg-violet-500/10 px-3 py-1.5 text-left text-xs text-muted-foreground italic transition hover:border-violet-500/60 hover:text-foreground active:scale-[0.98]"
                @click="adopt(suggestion)"
            >
                {{ suggestion }}
            </button>
        </div>

        <form class="flex gap-2" @submit.prevent="send">
            <textarea
                ref="speakBox"
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
                class="self-end rounded-md bg-gradient-to-br from-violet-600 to-violet-800 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-violet-900/20 transition hover:from-violet-500 hover:to-violet-700 active:scale-[0.98] disabled:opacity-50"
            >
                Speak
            </button>
        </form>
    </div>
</template>
