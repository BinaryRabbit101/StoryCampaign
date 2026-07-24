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
    canInsist: boolean;
    messages: Message[];
    catalog: {
        points: number;
        positives: TraitOption[];
        negatives: TraitOption[];
    };
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

// ---- The point-buy path: gifts cost, burdens refund, break even to be born. ----

const showBuilder = ref(false);
const selected = ref<string[]>([]);
const buildName = ref('');
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
            name: buildName.value.trim() || null,
            traits: selected.value,
            // Stepping in overspent is allowed — but it is a named choice,
            // and the shortfall is recorded as a debt to the world.
            override: balance.value < 0,
        },
        { onFinish: () => (building.value = false) },
    );
}

const insisting = ref(false);

function insist() {
    if (insisting.value) return;
    insisting.value = true;
    router.post(
        `/campaigns/${props.campaign.id}/interview/insist`,
        {},
        { onFinish: () => (insisting.value = false) },
    );
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

        <div v-if="showBuilder && !sending" class="sc-rise space-y-3">
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
                        :class="balance < 0 ? 'text-red-500' : 'text-violet-400'"
                    >
                        Balance: {{ balance }}
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
                                ? 'border-violet-500 bg-violet-600/30 text-foreground'
                                : 'border-input bg-background/60 text-muted-foreground hover:border-violet-500/50'
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
                                ? 'border-amber-500 bg-amber-600/25 text-foreground'
                                : 'border-input bg-background/60 text-muted-foreground hover:border-amber-500/50'
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

                <div class="flex gap-2">
                    <input
                        v-model="buildName"
                        type="text"
                        maxlength="40"
                        placeholder="A name (optional)"
                        class="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
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
                    Overspent — set a gift down, take up another burden, or
                    step in regardless and carry the shortfall as a debt the
                    world remembers.
                </p>
            </div>
        </div>

        <div
            v-if="suggestions.length && !sending && !showBuilder"
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

        <button
            v-if="canInsist && !sending && !showBuilder"
            type="button"
            :disabled="insisting"
            class="sc-rise self-start rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-1.5 text-left text-xs text-amber-500 italic transition hover:border-amber-500/70 active:scale-[0.98] disabled:opacity-50"
            @click="insist"
        >
            {{
                insisting
                    ? 'Stepping through…'
                    : 'Step through regardless — unbalanced, and owing the world the difference →'
            }}
        </button>

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

        <form v-if="!showBuilder" class="flex gap-2" @submit.prevent="send">
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
