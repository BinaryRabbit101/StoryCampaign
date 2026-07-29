<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    bandClass,
    bonusesFor,
    bonusTotal,
    costClass,
    difficultyAt,
    reasonsFor,
    signed,
} from '@/lib/odds';
import type { CarriedBonus } from '@/lib/odds';
import type { ActionCard } from '@/types/game';

/**
 * The maths, itemized, in one place.
 *
 * A turn commits the instant it is submitted — there is no going back once the
 * difficulty turns out to have been 18 — so every number the dice will be
 * measured against is printed before the commit, and it is the SAME number:
 * the forecast comes off the engine's own ledger, per stance. The board may
 * rearrange how a card is chosen; it may never hide a figure the flat list
 * showed, `+0` included.
 */
const props = defineProps<{
    card: ActionCard;
    /** The stance currently chosen for this card. */
    stance: string;
    /** What set-up beats already chosen will hand to this one. */
    carried?: CarriedBonus[];
    /** Riders fold their reasoning away; the act's is open on the page. */
    collapsible?: boolean;
}>();

const open = ref(!props.collapsible);

const difficulty = computed(() => difficultyAt(props.card, props.stance));
const bonuses = computed(() => bonusesFor(props.card, props.carried ?? []));
const modifier = computed(() => bonusTotal(props.card, props.carried ?? []));

// The reasons, without the constant every roll in the game shares. The total
// below is still the whole figure the die is measured against.
const reasons = computed(() => reasonsFor(props.card.forecast.parts));
</script>

<template>
    <div class="text-[11px]">
        <div class="flex items-center justify-between gap-3">
            <span
                class="font-semibold tabular-nums"
                :class="
                    bandClass(
                        card.forecast.rolls ? card.forecast.band : 'Certain',
                    )
                "
            >
                <template v-if="card.forecast.rolls">
                    DC {{ difficulty }} · {{ card.forecast.band }}
                </template>
                <template v-else>No roll — this simply happens</template>
            </span>
            <span class="flex items-center gap-2">
                <!-- The modifier is always shown, zero included: a number that
                     appears only when it is non-zero leaves the player unable
                     to tell "nothing helped" from "something is missing". -->
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
                <button
                    v-if="collapsible && card.forecast.rolls"
                    type="button"
                    class="text-muted-foreground underline underline-offset-2 hover:text-foreground"
                    @click.stop="open = !open"
                >
                    {{ open ? 'hide the maths' : 'why?' }}
                </button>
            </span>
        </div>

        <div
            v-if="open && card.forecast.rolls"
            class="mt-1.5 space-y-0.5 rounded-md bg-muted/60 p-2"
            @click.stop
        >
            <p
                v-for="part in reasons"
                :key="`d-${part.label}`"
                class="flex justify-between gap-3"
            >
                <span class="text-muted-foreground">{{ part.label }}</span>
                <span class="tabular-nums" :class="costClass(part.amount)">{{
                    signed(part.amount)
                }}</span>
            </p>
            <p v-if="!reasons.length" class="text-muted-foreground">
                Nothing here makes this harder or easier than usual.
            </p>
            <p
                class="flex justify-between gap-3 border-t border-sidebar-border/50 pt-0.5 font-medium"
            >
                <span>What you must beat</span>
                <span class="tabular-nums">{{ difficulty }}</span>
            </p>
            <p
                v-for="bonus in bonuses"
                :key="`b-${bonus.label}`"
                class="flex justify-between gap-3 text-emerald-700 dark:text-emerald-400"
            >
                <span
                    >{{ bonus.label
                    }}<template v-if="!bonus.certain">
                        (if it lands)</template
                    ></span
                >
                <span class="tabular-nums">{{ signed(bonus.amount) }}</span>
            </p>
            <p class="pt-0.5 text-muted-foreground">
                You roll a d20 {{ signed(modifier) }}. Beat it by 5 for a strong
                result; miss by 4 or less and it still half works.
            </p>
        </div>
    </div>
</template>
