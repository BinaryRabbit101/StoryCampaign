<script setup lang="ts">
/**
 * The dice table — the beat between choosing and reading.
 *
 * Every number on this screen was rolled by the engine, in a transaction,
 * before this component ever mounted. Nothing here is a roll; it is a replay,
 * and that is deliberate: the table can be watched on a phone or a desktop,
 * left half-finished and come back to, and the outcome is the same either way.
 *
 * The scene's dice fall on their own. The player's have to be picked up.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { DiceStage, supportsWebGL } from '@/lib/dice3d';
import type { RollRow } from '@/types/game';

const props = defineProps<{
    turnNumber: number;
    rows: RollRow[];
    /**
     * Something heard about somewhere else — off the road, from whoever they
     * were talking to, or during the wait. Quiet on purpose: it is news, not a
     * result, and there is nothing on this screen to do about it.
     */
    heard?: string | null;
    /**
     * Something remembered out of one of this player's own finished tales,
     * quoted because this moment rhymed with the moment that preserved it.
     * Quieter still than the news above: it is memory, it grants nothing, and
     * the player is meant to come across it rather than be told about it.
     */
    remembered?: string | null;
}>();

const emit = defineEmits<{ continue: [] }>();

const canvas = ref<HTMLCanvasElement | null>(null);
const dieSlots = ref<Record<string, HTMLElement | null>>({});
const revealed = ref<Set<string>>(new Set());
const rolling = ref<Set<string>>(new Set());
const webgl = supportsWebGL();

let stage: DiceStage | null = null;
const timers: ReturnType<typeof setTimeout>[] = [];

const ICONS: Record<string, string> = {
    attack: '⚔️',
    injury: '🩸',
    heal: '✚',
    highground: '⛰️',
    loot: '🪙',
    stealth: '🌘',
    parley: '💬',
    force: '💥',
    move: '🏃',
    tempo: '⏳',
    gambit: '✨',
    threat: '⚠️',
    defense: '🛡️',
    ally: '🤝',
    study: '🔍',
    enemy: '🗡️',
    beat: '✦',
};

const mine = computed(() => props.rows.filter((r) => r.side === 'player'));
const theirs = computed(() => props.rows.filter((r) => r.side !== 'player'));

/** The table clears only once every die on it has been read. */
const allSettled = computed(() =>
    props.rows.every((row) => revealed.value.has(row.id)),
);
const waitingOnPlayer = computed(() =>
    mine.value.some((row) => !revealed.value.has(row.id)),
);

function reveal(id: string) {
    revealed.value = new Set(revealed.value).add(id);
    rolling.value.delete(id);
    rolling.value = new Set(rolling.value);
}

function cast(row: RollRow) {
    if (revealed.value.has(row.id) || rolling.value.has(row.id)) return;

    if (!stage) {
        reveal(row.id);
        return;
    }

    rolling.value = new Set(rolling.value).add(row.id);
    stage.roll(row.id, row.roll, row.crit, () => reveal(row.id));
}

/** The impatience valve, same as the rest of the game has. */
function castAll() {
    mine.value.forEach((row, i) => {
        timers.push(setTimeout(() => cast(row), i * 220));
    });
}

function done() {
    if (!allSettled.value) return;
    emit('continue');
}

onMounted(async () => {
    await nextTick();

    if (webgl && canvas.value) {
        stage = new DiceStage(canvas.value);
        props.rows.forEach((row, i) => {
            const el = dieSlots.value[row.id];
            if (el) stage!.add(row.id, el, row.side, row.roll * 37 + i * 11);
        });
    }

    // The scene acts first, and it does not wait to be asked. Without WebGL
    // there is nothing to tumble, so cast() simply turns the number over —
    // the player still picks their own dice up either way.
    theirs.value.forEach((row, i) => {
        timers.push(
            setTimeout(() => cast(row), webgl ? 350 + i * 480 : 250 + i * 300),
        );
    });
});

onBeforeUnmount(() => {
    timers.forEach(clearTimeout);
    stage?.dispose();
    stage = null;
});

function setSlot(id: string, el: unknown) {
    dieSlots.value[id] = (el as HTMLElement | null) ?? null;
}

const bandClass = (band: string) =>
    ({
        Easy: 'text-emerald-700 dark:text-emerald-400',
        Medium: 'text-amber-700 dark:text-amber-400',
        Hard: 'text-orange-700 dark:text-orange-400',
    })[band] ?? 'text-rose-700 dark:text-rose-400';

const degreeLabel = (row: RollRow) => {
    if (row.crit === 'success') return 'Critical success';
    if (row.crit === 'failure') return 'Critical failure';

    return (
        {
            strong: 'Strong success',
            success: 'Success',
            partial: 'Partial',
            failure: 'Failure',
        }[row.degree] ?? row.degree
    );
};

const degreeClass = (row: RollRow) => {
    if (row.crit === 'success') return 'text-amber-600 dark:text-amber-300';
    if (row.crit === 'failure') return 'text-rose-600 dark:text-rose-400';

    return ['strong', 'success'].includes(row.degree)
        ? 'text-emerald-700 dark:text-emerald-400'
        : row.degree === 'partial'
          ? 'text-amber-700 dark:text-amber-400'
          : 'text-muted-foreground';
};

const ringClass = (row: RollRow) => {
    if (!revealed.value.has(row.id)) return 'border-sidebar-border/70';
    if (row.crit === 'success')
        return 'border-amber-400/80 shadow-[0_0_28px_-6px] shadow-amber-400/70';
    if (row.crit === 'failure')
        return 'border-rose-500/70 shadow-[0_0_28px_-6px] shadow-rose-500/60';

    return 'border-sidebar-border/70';
};

const sideLabel = (row: RollRow) => (row.side === 'player' ? 'You' : row.actor);

// ---- The arithmetic, spelled out ----
//
// "2 vs 18" is not a result, it is a riddle. A player who cannot see whether
// anything was added to their roll cannot tell a hard fight from a bug, and
// the modifier used to hide itself entirely whenever it happened to be zero —
// which is exactly the case where its absence is the thing worth saying. So
// the sum is always written in full, and the reasons behind both numbers are
// one tap away.

const signed = (amount: number) =>
    `${amount > 0 ? '+' : '−'}${Math.abs(amount)}`;

const expanded = ref<Set<string>>(new Set());

function toggleWhy(id: string) {
    const next = new Set(expanded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expanded.value = next;
}

const hasReasons = (row: RollRow) =>
    row.difficulty_parts.length > 0 || row.bonus_parts.length > 0;
</script>

<template>
    <div
        class="fixed inset-0 z-50 overflow-y-auto bg-background/95 backdrop-blur-md"
    >
        <!-- One renderer for the whole grid: each die is drawn into the
             scissor rectangle of its own card, so the table can scroll. It
             sits above the cards — a card's own background is translucent,
             and a die behind it would be a smudge. Each die only ever paints
             inside the empty slot its card leaves for it. -->
        <canvas
            v-if="webgl"
            ref="canvas"
            class="pointer-events-none fixed inset-0 z-30 h-full w-full"
        />

        <div class="relative z-20 mx-auto w-full max-w-2xl p-4 pb-28">
            <header class="sc-rise mb-5 text-center">
                <p
                    class="text-[0.7rem] tracking-[0.2em] text-muted-foreground uppercase"
                >
                    Chapter {{ turnNumber }} · before the ink
                </p>
                <h1 class="mt-1 text-2xl font-semibold">The dice fall</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    <template v-if="waitingOnPlayer">
                        The scene has thrown. Pick up your own.
                    </template>
                    <template v-else-if="!allSettled">
                        The scene is throwing…
                    </template>
                    <template v-else>
                        That is what happened. Now it gets written.
                    </template>
                </p>
            </header>

            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="row in rows"
                    :key="row.id"
                    class="sc-rise relative flex flex-col rounded-xl border bg-background/70 p-3 backdrop-blur-sm transition-colors"
                    :class="[
                        ringClass(row),
                        row.side === 'player' &&
                        !revealed.has(row.id) &&
                        !rolling.has(row.id)
                            ? 'sc-waiting cursor-pointer text-primary hover:bg-background/90'
                            : '',
                    ]"
                    @click="row.side === 'player' ? cast(row) : undefined"
                >
                    <!-- Who and what -->
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p
                                class="text-[0.65rem] tracking-[0.15em] uppercase"
                                :class="
                                    row.side === 'foe'
                                        ? 'text-rose-700 dark:text-rose-400'
                                        : row.side === 'ally'
                                          ? 'text-emerald-700 dark:text-emerald-400'
                                          : 'text-muted-foreground'
                                "
                            >
                                {{ sideLabel(row) }}
                            </p>
                            <p class="truncate text-sm font-medium">
                                <span aria-hidden="true">{{
                                    ICONS[row.icon] ?? ICONS.beat
                                }}</span>
                                {{ row.action }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p
                                class="text-[0.6rem] tracking-[0.15em] text-muted-foreground uppercase"
                            >
                                DC {{ row.difficulty }}
                            </p>
                            <p
                                class="text-xs font-semibold"
                                :class="bandClass(row.band)"
                            >
                                {{ row.band }}
                            </p>
                        </div>
                    </div>

                    <!-- The die's own patch of the card. The 3D canvas scissors
                         into exactly this box, so it must stay empty when
                         there is a die to draw; the numeral is what a reader
                         without WebGL gets instead. -->
                    <div
                        :ref="(el) => setSlot(row.id, el)"
                        class="relative my-2 flex h-24 items-center justify-center"
                    >
                        <span
                            v-if="!webgl"
                            class="text-3xl font-bold tabular-nums"
                            :class="
                                revealed.has(row.id)
                                    ? ''
                                    : 'text-muted-foreground/40'
                            "
                            aria-hidden="true"
                        >
                            <template v-if="revealed.has(row.id)">{{
                                row.roll
                            }}</template>
                            <template v-else>·</template>
                        </span>
                        <span class="sr-only">
                            {{ sideLabel(row) }}:
                            {{
                                revealed.has(row.id)
                                    ? `rolled ${row.roll}`
                                    : 'not yet rolled'
                            }}
                        </span>
                    </div>

                    <p
                        v-if="
                            row.side === 'player' &&
                            !revealed.has(row.id) &&
                            !rolling.has(row.id)
                        "
                        class="text-center text-xs font-medium"
                    >
                        Tap to roll
                    </p>

                    <!-- The arithmetic, once there is any to show -->
                    <Transition name="unfold">
                        <div v-if="revealed.has(row.id)" class="text-center">
                            <p
                                v-if="row.crit"
                                class="sc-flare text-xs font-bold tracking-[0.15em] uppercase"
                                :class="degreeClass(row)"
                            >
                                {{
                                    row.crit === 'success'
                                        ? '★ Natural 20 ★'
                                        : '☠ Natural 1 ☠'
                                }}
                            </p>
                            <!-- Always the whole sum, including a +0. A
                                 modifier that vanishes when it happens to be
                                 zero is the one case where its absence is
                                 worth stating out loud. -->
                            <p
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                d20 {{ row.roll }} {{ signed(row.modifier) }} =
                                <span class="font-semibold text-foreground">{{
                                    row.total
                                }}</span>
                                vs DC {{ row.difficulty }}
                            </p>
                            <p
                                class="text-sm font-semibold"
                                :class="degreeClass(row)"
                            >
                                {{ degreeLabel(row) }}
                            </p>
                            <p
                                v-if="row.outcome"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ row.outcome }}
                            </p>

                            <button
                                v-if="hasReasons(row)"
                                type="button"
                                class="mt-1.5 text-[10px] text-muted-foreground underline underline-offset-2 hover:text-foreground"
                                @click.stop="toggleWhy(row.id)"
                            >
                                {{
                                    expanded.has(row.id)
                                        ? 'hide the maths'
                                        : 'where these numbers came from'
                                }}
                            </button>

                            <!-- The same ledger the card printed before the
                                 commit, read back afterwards. -->
                            <div
                                v-if="expanded.has(row.id)"
                                class="mt-1.5 space-y-0.5 rounded-md bg-muted/60 p-2 text-left text-[11px]"
                            >
                                <p
                                    v-for="part in row.difficulty_parts"
                                    :key="`d-${part.label}`"
                                    class="flex justify-between gap-3"
                                >
                                    <span class="text-muted-foreground">{{
                                        part.label
                                    }}</span>
                                    <span class="tabular-nums">{{
                                        part.amount
                                    }}</span>
                                </p>
                                <p
                                    class="flex justify-between gap-3 border-t border-sidebar-border/50 pt-0.5 font-medium"
                                >
                                    <span>Had to beat</span>
                                    <span class="tabular-nums">{{
                                        row.difficulty
                                    }}</span>
                                </p>
                                <p
                                    v-for="part in row.bonus_parts"
                                    :key="`b-${part.label}`"
                                    class="flex justify-between gap-3 text-emerald-700 dark:text-emerald-400"
                                >
                                    <span>{{ part.label }}</span>
                                    <span class="tabular-nums">{{
                                        signed(part.amount)
                                    }}</span>
                                </p>
                                <p
                                    v-if="!row.bonus_parts.length"
                                    class="text-muted-foreground"
                                >
                                    Nothing was added to this roll.
                                </p>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>

            <!-- Word from elsewhere, at the same weight as the wait below it:
                 no badge, no colour, nothing to answer. The world has been
                 moving while they were busy, and this is the first time the
                 character rather than the reader gets to hear about it. -->
            <p
                v-if="heard"
                class="mt-5 text-center text-sm text-muted-foreground italic"
            >
                {{ heard }}
            </p>

            <!-- And a line out of a book of theirs that is already closed,
                 at exactly the weight of the news above it. No badge, no
                 colour, nothing to answer — the shelf leaning in once, and
                 then leaving them to it. -->
            <p
                v-if="remembered"
                class="mt-5 text-center text-sm text-muted-foreground italic"
            >
                {{ remembered }}
            </p>

            <!-- The wait ahead, offered where the player already is. The
                 table is the one screen they sit on between turns, so the
                 choice about the stretch after it belongs here — quiet,
                 optional, and never in the way of reading the chapter. -->
            <div class="mt-5">
                <slot name="downtime" />
            </div>

            <div class="mt-6 flex flex-col items-center gap-3">
                <button
                    v-if="waitingOnPlayer && mine.length > 1"
                    class="text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                    @click="castAll"
                >
                    Throw them all
                </button>

                <Transition name="pop">
                    <button
                        v-if="allSettled"
                        class="sc-breathe rounded-lg bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground"
                        @click="done"
                    >
                        Read the chapter →
                    </button>
                </Transition>
            </div>
        </div>

        <!-- Click anywhere once the table is read. -->
        <button
            v-if="allSettled"
            class="fixed inset-0 z-0 cursor-pointer"
            aria-label="Continue to the chapter"
            @click="done"
        />
    </div>
</template>
