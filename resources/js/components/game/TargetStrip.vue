<script setup lang="ts">
import type { TargetOption } from '@/lib/odds';

/**
 * WHAT: the second step of the sentence.
 *
 * One chip per thing the chosen verb can be aimed at. Every chip stands for a
 * card the engine offered; picking one never composes anything.
 *
 * The chips used to print their own DC, which put the same number on screen a
 * dozen times over — six things to aim at, all reading "· 13", because on most
 * ground the target is not what makes a beat hard. A figure repeated until it
 * is wallpaper stops being read at all. The DC lives one tap away in the panel
 * for the beat actually chosen, before the commit, where it is about something.
 * The risk colouring stays here: THAT genuinely differs per target.
 */
defineProps<{
    label?: string;
    options: TargetOption[];
    selectedKey: string | null;
}>();

defineEmits<{ (e: 'pick', key: string): void }>();
</script>

<template>
    <div v-if="options.length > 1">
        <p
            v-if="label"
            class="mb-1 text-[10px] tracking-widest text-muted-foreground uppercase"
        >
            {{ label }}
        </p>
        <div class="flex flex-wrap gap-1">
            <button
                v-for="option in options"
                :key="option.key"
                type="button"
                class="rounded-full border px-2.5 py-1 text-xs transition active:scale-95"
                :class="
                    option.key === selectedKey
                        ? 'border-violet-600 bg-violet-600 text-white'
                        : option.risk !== 'safe'
                          ? 'border-amber-500/60 text-amber-700 hover:bg-accent dark:text-amber-400'
                          : 'border-input hover:bg-accent'
                "
                @click.stop="$emit('pick', option.key)"
            >
                {{ option.name }}
            </button>
        </div>
    </div>
</template>
