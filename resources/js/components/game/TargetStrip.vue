<script setup lang="ts">
import type { TargetOption } from '@/lib/odds';

/**
 * WHAT: the second step of the sentence.
 *
 * One chip per thing the chosen verb can be aimed at, each carrying its own
 * difficulty — the strip the old row-based picker already had, lifted out so
 * the board can use it in the act and the riders alike. Every chip stands for
 * a card the engine offered; picking one never composes anything.
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
                {{ option.name
                }}<template v-if="option.difficulty !== null">
                    · {{ option.difficulty }}</template
                >
            </button>
        </div>
    </div>
</template>
