<script setup lang="ts">
import { computed } from 'vue';
import { bandClass, bonusTotal, difficultyAt, signed } from '@/lib/odds';
import type { CarriedBonus } from '@/lib/odds';
import type { ActionCard } from '@/types/game';

/**
 * The two numbers that decide the beat, before the commit.
 *
 * A turn commits the instant it is submitted — there is no going back once the
 * difficulty turns out to have been 18 — so what the dice will be measured
 * against is printed first, and it is the SAME number: the forecast comes off
 * the engine's own ledger. The board may rearrange how a card is chosen; it may
 * never hide a figure the flat list showed, `+0` included.
 *
 * What it no longer prints is the ITEMIZATION — the per-part list, the restated
 * total, the d20 explainer. That was always a reading aid rather than the fact
 * itself ("the TOTAL is what must never be hidden"), and eight lines of arith-
 * metic under every single card is a paragraph the player learns to scroll past,
 * which is worse than not offering it: it buries the two numbers that matter in
 * a wall that looks like it matters equally. The parts are still on the card and
 * still on every resolved roll, so the dice table can show the working after the
 * fact, where it reads as an explanation instead of a form.
 */
const props = defineProps<{
    card: ActionCard;
    /** The stance currently chosen for this card. */
    stance: string;
    /** What set-up beats already chosen will hand to this one. */
    carried?: CarriedBonus[];
}>();

const difficulty = computed(() => difficultyAt(props.card, props.stance));
const modifier = computed(() => bonusTotal(props.card, props.carried ?? []));
</script>

<template>
    <div class="flex items-center justify-between gap-3 text-[11px]">
        <span
            class="font-semibold tabular-nums"
            :class="
                bandClass(card.forecast.rolls ? card.forecast.band : 'Certain')
            "
        >
            <template v-if="card.forecast.rolls">
                DC {{ difficulty }} · {{ card.forecast.band }}
            </template>
            <template v-else>No roll — this simply happens</template>
        </span>

        <!-- The modifier is always shown, zero included: a number that appears
             only when it is non-zero leaves the player unable to tell "nothing
             helped" from "something is missing". -->
        <span
            v-if="card.forecast.rolls"
            class="tabular-nums"
            :class="
                modifier > 0
                    ? 'text-emerald-700 dark:text-emerald-400'
                    : 'text-muted-foreground'
            "
            >{{ signed(modifier) }} to your roll</span
        >
    </div>
</template>
