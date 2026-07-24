<script setup lang="ts">
// Purely decorative: drifting glows and slow-rising motes behind the page.
// Pages place it inside a `relative isolate` root so it paints above the app
// background but beneath every piece of content, and it never takes clicks.

// Deterministic spread — the same sky every visit, no hydration flicker.
const MOTES = Array.from({ length: 14 }, (_, i) => ({
    left: `${((i * 61) % 97) + 2}%`,
    size: 2 + (i % 3),
    delay: `${(i * 1.7) % 12}s`,
    duration: `${14 + ((i * 3) % 10)}s`,
    opacity: 0.2 + (i % 4) * 0.08,
}));
</script>

<template>
    <div
        aria-hidden="true"
        class="pointer-events-none fixed inset-0 -z-10 overflow-hidden"
    >
        <div
            class="sc-drift absolute -top-32 -left-24 h-96 w-96 rounded-full bg-violet-500/10 blur-3xl"
        />
        <div
            class="sc-drift absolute -right-24 -bottom-32 h-[28rem] w-[28rem] rounded-full bg-emerald-500/5 blur-3xl"
            style="animation-delay: -8s; animation-duration: 27s"
        />
        <div
            class="sc-drift absolute top-1/3 left-1/2 h-72 w-72 rounded-full bg-amber-500/5 blur-3xl"
            style="animation-delay: -14s; animation-duration: 33s"
        />
        <span
            v-for="(mote, i) in MOTES"
            :key="i"
            class="sc-mote absolute -bottom-2 rounded-full bg-violet-400"
            :style="{
                left: mote.left,
                width: `${mote.size}px`,
                height: `${mote.size}px`,
                animationDelay: mote.delay,
                animationDuration: mote.duration,
                '--mote-opacity': mote.opacity,
            }"
        />
    </div>
</template>
