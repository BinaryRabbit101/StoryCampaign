<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();

// The app's own mark, not Laravel's. This component shipped as the stock
// Laravel logo in every one of these apps, which is why each sign-in screen was
// indistinguishable from the next — you could not tell which app was asking for
// your password until you read the heading.
//
// Bound rather than written inline as src="/apple-touch-icon.png": a literal
// root-absolute src in the template is rewritten by Vite's asset plugin, which
// then tries to resolve it at build time and fails, because this file is served
// out of Laravel's public/ and Vite knows nothing about it.
const logoUrl = '/apple-touch-icon.png';
</script>

<template>
    <!-- Callers pass SVG-era classes (fill-current, text-white). They are inert
         on an <img> and harmless; the size-* classes they also pass are what
         actually matter here and still apply. -->
    <img
        :src="logoUrl"
        alt=""
        :class="className"
        v-bind="$attrs"
        class="rounded-[22%] object-contain"
    />
</template>
