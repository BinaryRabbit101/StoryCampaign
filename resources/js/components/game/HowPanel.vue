<script setup lang="ts">
import { computed } from 'vue';
import OddsLedger from '@/components/game/OddsLedger.vue';
import {
    costLabel,
    difficultyAt,
    endeavorLine,
    threadLine,
    riskChipClass,
    riskLabel,
    signed,
    stanceDelta,
} from '@/lib/odds';
import type { CarriedBonus } from '@/lib/odds';
import type { ActionCard, SlotChoice } from '@/types/game';

/**
 * HOW: the last step of the sentence.
 *
 * One panel for the beat now chosen — the body it is spent through when more
 * than one serves the same verb on the same thing (swing up, or climb up:
 * these used to be two separate rows, and they are one verb with two manners),
 * the stance, the shape of the blow, the deal if one is on the table, and the
 * player's own words. The whole itemized forecast lives here, open: this is
 * the last screen before the commit, and the commit is final.
 */
const props = defineProps<{
    /** The card currently chosen. */
    card: ActionCard;
    /** Every card sharing this verb and this target — the manners and the deal. */
    variants: ActionCard[];
    choice: SlotChoice;
    carried?: CarriedBonus[];
}>();

const emit = defineEmits<{
    (e: 'pick-card', card: ActionCard): void;
    (e: 'update:choice', choice: SlotChoice): void;
}>();

const stance = computed(() => props.choice.modifiers.approach ?? 'balanced');

/** The bodies that serve this verb here — one chip each, when there are two. */
const manners = computed(() => {
    const seen = new Map<string, ActionCard>();

    for (const card of props.variants) {
        if (card.bargain !== null) {
            continue;
        }

        const key = card.capability ?? '-';

        if (!seen.has(key)) {
            seen.set(key, card);
        }
    }

    return [...seen.values()];
});

const mannerLabel = (card: ActionCard) =>
    card.capability
        ? card.capability.charAt(0).toUpperCase() +
          card.capability.slice(1).replace(/_/g, ' ')
        : card.label;

const isManner = (card: ActionCard) =>
    (card.capability ?? '-') === (props.card.capability ?? '-');

/** The honest version of this beat, and the deal standing beside it. */
const plain = computed(
    () => props.variants.find((c) => c.bargain === null && isManner(c)) ?? null,
);
const deal = computed(
    () => props.variants.find((c) => c.bargain !== null && isManner(c)) ?? null,
);

const dc = (card: ActionCard) =>
    card.forecast.rolls
        ? difficultyAt(
              card,
              card.id === props.card.id ? stance.value : 'balanced',
          )
        : null;

function setModifier(key: string, value: string) {
    emit('update:choice', {
        ...props.choice,
        modifiers: { ...props.choice.modifiers, [key]: value },
    });
}

function setNote(note: string) {
    emit('update:choice', { ...props.choice, note });
}

/**
 * What a nudge is FOR differs between swinging a blade and asking a favor,
 * and an empty box teaches neither. The prompt follows the verb; anything the
 * engine adds tomorrow simply gets the general question.
 */
const NOTE_HINTS: Record<string, string> = {
    improvise: 'What exactly do you try, and with what?',
    strike: 'Where do you aim — and what do you say as you swing?',
    interrupt: 'How do you get inside it?',
    speak: 'What do you actually say?',
    persuade: 'What argument do you reach for?',
    deceive: 'What is the lie?',
    calm: 'How do you steady them?',
    intimidate: 'What do they see in you?',
    recruit: 'How do you ask?',
    restrain: 'How do you take hold?',
    inspect: 'What are you hoping to find?',
    examine: 'What are you hoping to find?',
    scout: 'What are you listening for?',
    hide: 'How do you make yourself small?',
    flee: 'How do you go — quiet, or fast?',
    cross: 'How do you commit to it?',
    ascend: 'How do you go up?',
    break: 'How do you go at it?',
    venture: 'What do you leave behind, and how?',
    wait: 'What are you waiting for?',
    bandage: 'How badly is it hurting?',
    catch_breath: 'What is going through your head?',
    reposition: 'Where do you put yourself?',
    lift: 'How do you get under it?',
    drop: 'How do you set it down?',
    hurl: 'Where do you aim it?',
};

const placeholder = computed(
    () => NOTE_HINTS[props.card.verb] ?? 'How do you want this to unfold?',
);
</script>

<template>
    <div
        class="sc-rise rounded-lg border border-violet-500/50 bg-violet-500/5 p-3"
    >
        <p class="text-sm font-medium">{{ card.label }}</p>
        <p class="mt-0.5 text-xs text-muted-foreground">
            {{ card.description }}
        </p>

        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
            <span
                class="rounded-full px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase"
                :class="riskChipClass(card.risk)"
                >{{ riskLabel(card.risk) }}</span
            >
            <span
                v-if="costLabel(card)"
                class="text-xs text-violet-600 dark:text-violet-400"
                >{{ costLabel(card) }}</span
            >
            <span
                v-if="card.forecast.grant"
                class="rounded-full bg-sky-500/10 px-2 py-0.5 text-[10px] text-sky-700 dark:text-sky-300"
                >{{ signed(card.forecast.grant.amount) }} after<template
                    v-if="!card.forecast.grant.certain"
                >
                    — if it lands</template
                ></span
            >
            <!-- What this beat moves toward, quoted the same way a grant is:
                 off the engine's forecast, before the commit. -->
            <span
                v-if="card.forecast.endeavor"
                class="rounded-full bg-teal-500/10 px-2 py-0.5 text-[10px] text-teal-700 dark:text-teal-300"
                >{{ endeavorLine(card) }}</span
            >
            <!-- And whose small story it would help along — the same promise,
                 worn by somebody else's hope instead of the player's own. -->
            <span
                v-if="card.forecast.thread"
                class="rounded-full bg-violet-500/10 px-2 py-0.5 text-[10px] text-violet-700 dark:text-violet-300"
                >{{ threadLine(card) }}</span
            >
        </div>

        <!-- The manner: which body this verb is spent through. Two ways up the
             same wall are one verb and two answers, not two sentences. -->
        <div v-if="manners.length > 1" class="mt-2.5">
            <p
                class="mb-1 text-[10px] tracking-widest text-muted-foreground uppercase"
            >
                How
            </p>
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="option in manners"
                    :key="option.id"
                    type="button"
                    class="rounded-full border px-2.5 py-1 text-xs transition active:scale-95"
                    :class="
                        isManner(option)
                            ? 'border-violet-600 bg-violet-600 text-white'
                            : 'border-input hover:bg-accent'
                    "
                    @click="$emit('pick-card', option)"
                >
                    {{ mannerLabel(option)
                    }}<template v-if="dc(option) !== null">
                        · {{ dc(option) }}</template
                    >
                </button>
            </div>
        </div>

        <!-- The deal, beside the honest version of the same beat and weighted
             exactly the same. Same border, same size, same colour: the price
             sits under the gain, and neither one is ever the recommendation. -->
        <div v-if="deal && plain" class="mt-2.5">
            <p
                class="mb-1 text-[10px] tracking-widest text-muted-foreground uppercase"
            >
                Two ways to take it
            </p>
            <div class="space-y-1.5">
                <button
                    v-for="option in [plain, deal]"
                    :key="option!.id"
                    type="button"
                    class="w-full rounded-md border px-2.5 py-1.5 text-left text-xs transition"
                    :class="
                        option!.id === card.id
                            ? 'border-violet-500 ring-1 ring-violet-500'
                            : 'border-sidebar-border/70 hover:bg-accent/50 dark:border-sidebar-border'
                    "
                    @click="$emit('pick-card', option!)"
                >
                    <span class="flex items-baseline justify-between gap-2">
                        <span>{{ option!.label }}</span>
                        <span
                            v-if="dc(option!) !== null"
                            class="shrink-0 tabular-nums"
                            >DC {{ dc(option!) }}</span
                        >
                    </span>
                    <dl
                        v-if="option!.bargain"
                        class="mt-1 grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-[11px]"
                    >
                        <dt
                            class="tracking-wide text-muted-foreground uppercase"
                        >
                            Gain
                        </dt>
                        <dd>{{ option!.bargain.edge_label }}</dd>
                        <dt
                            class="tracking-wide text-muted-foreground uppercase"
                        >
                            Cost
                        </dt>
                        <dd>{{ option!.bargain.complication_label }}</dd>
                    </dl>
                    <p
                        v-if="option!.bargain"
                        class="mt-1 text-[10px] text-muted-foreground"
                    >
                        The cost is paid whether the beat lands or not.
                    </p>
                </button>
            </div>
        </div>

        <div class="mt-2.5 border-t border-sidebar-border/50 pt-2">
            <OddsLedger :card="card" :stance="stance" :carried="carried" />
        </div>

        <div
            v-if="card.modifiers.length"
            class="mt-2 space-y-2 border-t border-sidebar-border/50 pt-2"
        >
            <div v-for="modifier in card.modifiers" :key="modifier.key">
                <div class="mb-1 text-xs font-medium text-muted-foreground">
                    {{ modifier.label }}
                </div>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="option in modifier.options"
                        :key="option.value"
                        type="button"
                        class="rounded-full border px-2.5 py-1 text-xs transition active:scale-95"
                        :class="
                            choice.modifiers[modifier.key] === option.value
                                ? 'border-violet-600 bg-violet-600 text-white'
                                : 'border-input hover:bg-accent'
                        "
                        @click="setModifier(modifier.key, option.value)"
                    >
                        {{
                            option.label
                        }}<!-- A stance that moves the difficulty says by how
                             much, on the chip that moves it. -->
                        <span
                            v-if="
                                modifier.key === 'approach' &&
                                stanceDelta(card, option.value)
                            "
                            class="ml-1 tabular-nums opacity-80"
                            >({{
                                signed(stanceDelta(card, option.value)!)
                            }}
                            DC)</span
                        >
                    </button>
                </div>
            </div>
        </div>

        <!-- The nudge: how the player wants this one beat to unfold. It
             reaches the narrator as voice and never touches the dice. -->
        <div class="mt-2 border-t border-sidebar-border/50 pt-2">
            <label class="mb-1 block text-xs font-medium text-muted-foreground">
                In your own words
                <span class="font-normal"
                    >— colors the telling, changes nothing</span
                >
            </label>
            <textarea
                :value="choice.note"
                rows="2"
                maxlength="280"
                :placeholder="placeholder"
                class="w-full resize-y rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus:border-violet-500/70 focus:outline-none"
                @input="setNote(($event.target as HTMLTextAreaElement).value)"
            />
        </div>
    </div>
</template>
