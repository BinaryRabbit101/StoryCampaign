<script setup lang="ts">
/**
 * Previously, on this tale.
 *
 * Quiet on purpose, in the keepsake register: no badge, no animation, nothing
 * asking to be answered. The player back after a night away finds it sitting
 * above the form, reads four lines or none, and gets on with the turn.
 *
 * Every line here was written server-side by the engine and is only being
 * repeated. The panel adds headings and nothing else.
 *
 * Dismissal is per turn and client-side: closing it stores this turn's id, so
 * it stays closed on this device for this turn and the server offers a fresh
 * one on its own the next time somebody is away long enough to want it.
 */
import { computed, onMounted, ref } from 'vue';
import type { RecapPanel } from '@/types/game';

const props = defineProps<{ recap: RecapPanel }>();

const key = computed(() => `sc-recap-dismissed:${props.recap.turn_id}`);
const dismissed = ref(true);

onMounted(() => {
    try {
        dismissed.value = localStorage.getItem(key.value) !== null;
    } catch {
        // A browser that refuses storage still gets the panel; it simply
        // cannot remember that it was closed. Better than never showing it.
        dismissed.value = false;
    }
});

function dismiss() {
    dismissed.value = true;

    try {
        localStorage.setItem(key.value, '1');
    } catch {
        // Nothing to recover from — the panel is already gone for this view.
    }
}
</script>

<template>
    <section
        v-if="!dismissed"
        class="rounded-xl border border-sidebar-border/70 bg-background/60 p-4 text-left backdrop-blur-sm"
        aria-label="Previously, on this tale"
    >
        <div class="flex items-baseline justify-between gap-3">
            <p
                class="text-[0.65rem] tracking-[0.15em] text-muted-foreground uppercase"
            >
                Previously
            </p>
            <button
                type="button"
                class="text-[11px] text-muted-foreground hover:text-foreground"
                @click="dismiss"
            >
                Close
            </button>
        </div>

        <div class="mt-2 grid gap-3">
            <div v-for="section in recap.sections" :key="section.key">
                <p class="text-xs font-medium text-foreground/80">
                    {{ section.title }}
                </p>
                <ul class="mt-1 grid gap-1">
                    <li
                        v-for="(line, index) in section.lines"
                        :key="index"
                        class="text-[13px] leading-snug text-muted-foreground"
                    >
                        {{ line }}
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
