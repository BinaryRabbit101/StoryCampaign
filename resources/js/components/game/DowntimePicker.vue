<script setup lang="ts">
/**
 * The wait, offered as a choice.
 *
 * A quiet one-liner on the resolved-turn screen: the turn is already open and
 * nothing here delays it, so a player who ignores this entirely gets exactly
 * the game they had before — tempo comes back and nothing else does.
 *
 * Every option prints its own terms. That is the same rule the cards live by:
 * a benefit the player cannot price is a benefit they cannot choose between,
 * and this one is paid out hours later where nobody can check the arithmetic.
 */
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { DowntimeOffer } from '@/types/game';

const props = defineProps<{
    campaignId: number;
    turnId: number;
    downtime: DowntimeOffer;
}>();

const sending = ref(false);

/** What the player wants said at the fire. Colour for the chapter, never a number. */
const note = ref('');

const chosen = computed(
    () =>
        props.downtime.offer.find((s) => s.id === props.downtime.stance) ??
        null,
);

/** The one stance that takes words, if it is on offer at all this turn. */
const spoken = computed(
    () => props.downtime.offer.find((s) => s.note) ?? null,
);

function choose(stance: string) {
    // Once only: the wait is spoken for the moment it is answered, and the
    // engine's clock starts at that instant rather than at the reload.
    if (props.downtime.stance || sending.value) {
        return;
    }

    sending.value = true;
    router.post(
        `/play/${props.campaignId}/downtime`,
        {
            turn_id: props.turnId,
            stance,
            note: stance === spoken.value?.id ? note.value : null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['turn'],
            onFinish: () => (sending.value = false),
        },
    );
}
</script>

<template>
    <div
        class="rounded-xl border border-sidebar-border/70 bg-background/60 p-3 text-left backdrop-blur-sm"
    >
        <p
            class="text-[0.65rem] tracking-[0.15em] text-muted-foreground uppercase"
        >
            The next stretch of road
        </p>

        <template v-if="chosen">
            <p class="mt-1 text-sm font-medium">{{ chosen.label }}.</p>
            <p class="mt-0.5 text-xs text-muted-foreground">
                {{ chosen.terms }} It counts from now, and only if you are
                actually away a while.
            </p>
        </template>

        <template v-else>
            <p class="mt-1 text-sm">How do you spend it?</p>
            <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
                <button
                    v-for="stance in downtime.offer"
                    :key="stance.id"
                    type="button"
                    :disabled="sending"
                    class="rounded-lg border border-sidebar-border/60 bg-background/50 p-2 text-left transition hover:border-violet-500/50 hover:bg-background/80 disabled:opacity-50"
                    @click="choose(stance.id)"
                >
                    <span class="block text-sm font-medium">{{
                        stance.label
                    }}</span>
                    <span
                        class="mt-0.5 block text-[11px] text-muted-foreground"
                    >
                        {{ stance.terms }}
                    </span>
                </button>
            </div>
            <textarea
                v-if="spoken"
                v-model="note"
                rows="2"
                maxlength="280"
                :placeholder="spoken.note"
                class="mt-2 w-full rounded-lg border border-sidebar-border/60 bg-background/50 p-2 text-xs"
            />
            <p class="mt-1.5 text-[11px] text-muted-foreground">
                Or say nothing — the wait passes on its own, as it always has.
            </p>
        </template>
    </div>
</template>
