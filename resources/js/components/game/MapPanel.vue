<script setup lang="ts">
/**
 * The trail map — the country this tale has actually walked.
 *
 * Drawn, never promised: every node is a scene the campaign has stood in,
 * every road a transition that really happened, and the dashed stubs off the
 * current ground are its unwalked ways out. Departure is irreversible in this
 * game, so the map is a record rather than a navigation tool — its whole job
 * is that the player always knows where they are, how they got there, and
 * which headings are still open.
 */
import { computed } from 'vue';
import type { PlaceMapNode, PlaceExit } from '@/types/game';

const props = defineProps<{
    zoneName: string;
    sceneTitle: string;
    map: PlaceMapNode[];
    exits: PlaceExit[];
}>();

const CELL = 72;

const nodes = computed(() => props.map);
const byId = computed(
    () => new Map(props.map.map((n) => [n.id, n] as const)),
);
const current = computed(() => props.map.find((n) => n.current) ?? null);

/** Grid → SVG. North is +y in the engine, up on the screen. */
const bounds = computed(() => {
    const xs = nodes.value.map((n) => n.x);
    const ys = nodes.value.map((n) => n.y);
    // Leave one cell of margin so exit stubs never clip.
    return {
        minX: Math.min(...xs, 0) - 1,
        maxX: Math.max(...xs, 0) + 1,
        minY: Math.min(...ys, 0) - 1,
        maxY: Math.max(...ys, 0) + 1,
    };
});

const width = computed(
    () => (bounds.value.maxX - bounds.value.minX + 1) * CELL,
);
const height = computed(
    () => (bounds.value.maxY - bounds.value.minY + 1) * CELL,
);

function px(x: number): number {
    return (x - bounds.value.minX + 0.5) * CELL;
}
function py(y: number): number {
    return (bounds.value.maxY - y + 0.5) * CELL;
}

const roads = computed(() =>
    nodes.value
        .filter((n) => n.from !== null && byId.value.has(n.from))
        .map((n) => {
            const from = byId.value.get(n.from!)!;
            return {
                id: `road-${n.id}`,
                x1: px(from.x),
                y1: py(from.y),
                x2: px(n.x),
                y2: py(n.y),
            };
        }),
);

const OFFSETS: Record<string, [number, number]> = {
    north: [0, 1],
    south: [0, -1],
    east: [1, 0],
    west: [-1, 0],
};

/** Unwalked ways out of the current ground, as dashed stubs with headings. */
const stubs = computed(() => {
    if (!current.value) return [];
    return props.exits.map((exit) => {
        const [dx, dy] = OFFSETS[exit.direction] ?? [0, 0];
        const x1 = px(current.value!.x);
        const y1 = py(current.value!.y);
        return {
            id: `stub-${exit.direction}`,
            direction: exit.direction,
            label: exit.label,
            x1,
            y1,
            x2: x1 + dx * CELL * 0.62,
            y2: y1 - dy * CELL * 0.62,
            lx: x1 + dx * CELL * 0.86,
            ly: y1 - dy * CELL * 0.86,
        };
    });
});

const short = (title: string) =>
    title.length > 18 ? `${title.slice(0, 17)}…` : title;
</script>

<template>
    <section
        class="rounded-xl border border-sidebar-border/70 bg-card p-4"
        aria-label="Map of the ground walked so far"
    >
        <header class="mb-2 flex items-baseline justify-between gap-2">
            <h2
                class="text-[0.7rem] tracking-[0.2em] text-muted-foreground uppercase"
            >
                The country walked
            </h2>
            <p class="truncate text-xs text-muted-foreground">
                {{ zoneName }}
            </p>
        </header>

        <p class="mb-3 text-sm">
            You stand in
            <span class="font-medium">{{ sceneTitle }}</span
            ><template v-if="exits.length">
                — open ways:
                <span class="text-muted-foreground">
                    {{
                        exits
                            .map((e) => `${e.direction} toward ${e.label}`)
                            .join(', ')
                    }}
                </span></template
            >.
        </p>

        <div class="overflow-x-auto">
            <svg
                :viewBox="`0 0 ${width} ${height}`"
                :style="{ maxHeight: '260px' }"
                class="mx-auto block h-auto w-full max-w-md"
                role="img"
                aria-label="Scenes visited, drawn as a trail"
            >
                <!-- Roads actually walked -->
                <line
                    v-for="road in roads"
                    :key="road.id"
                    :x1="road.x1"
                    :y1="road.y1"
                    :x2="road.x2"
                    :y2="road.y2"
                    class="stroke-sidebar-border"
                    stroke-width="2"
                />

                <!-- Unwalked ways out of the current ground -->
                <g v-for="stub in stubs" :key="stub.id">
                    <line
                        :x1="stub.x1"
                        :y1="stub.y1"
                        :x2="stub.x2"
                        :y2="stub.y2"
                        class="stroke-primary/50"
                        stroke-width="2"
                        stroke-dasharray="4 4"
                    />
                    <text
                        :x="stub.lx"
                        :y="stub.ly"
                        text-anchor="middle"
                        dominant-baseline="middle"
                        class="fill-muted-foreground text-[9px] uppercase"
                    >
                        {{ stub.direction[0] }}
                    </text>
                </g>

                <!-- The ground itself -->
                <g v-for="node in nodes" :key="node.id">
                    <circle
                        :cx="px(node.x)"
                        :cy="py(node.y)"
                        :r="node.current ? 9 : 6"
                        :class="
                            node.current
                                ? 'fill-primary'
                                : 'fill-muted stroke-sidebar-border'
                        "
                        stroke-width="1.5"
                    />
                    <text
                        :x="px(node.x)"
                        :y="py(node.y) + (node.current ? 21 : 17)"
                        text-anchor="middle"
                        class="text-[9px]"
                        :class="
                            node.current
                                ? 'fill-foreground font-medium'
                                : 'fill-muted-foreground'
                        "
                    >
                        {{ short(node.title) }}
                    </text>
                </g>
            </svg>
        </div>
    </section>
</template>
