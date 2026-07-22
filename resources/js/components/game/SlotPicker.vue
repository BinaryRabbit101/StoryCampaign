<script setup lang="ts">
import type { ActionCard, SlotChoice } from '@/types/game';

const props = defineProps<{
    title: string;
    hint: string;
    cards: ActionCard[];
    optional: boolean;
    modelValue: SlotChoice | null;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: SlotChoice | null): void }>();

function pick(card: ActionCard) {
    if (props.modelValue?.card_id === card.id) {
        if (props.optional) emit('update:modelValue', null);
        return;
    }
    const modifiers: Record<string, string> = {};
    for (const modifier of card.modifiers) {
        modifiers[modifier.key] = modifier.options[0]?.value ?? '';
    }
    emit('update:modelValue', { card_id: card.id, modifiers });
}

function setModifier(key: string, value: string) {
    if (!props.modelValue) return;
    emit('update:modelValue', {
        ...props.modelValue,
        modifiers: { ...props.modelValue.modifiers, [key]: value },
    });
}

function riskClass(risk: string): string {
    switch (risk) {
        case 'degraded':
            return 'border-amber-500/60';
        case 'risky':
            return 'border-orange-400/40';
        default:
            return 'border-sidebar-border/70 dark:border-sidebar-border';
    }
}

function riskLabel(risk: string): string | null {
    switch (risk) {
        case 'degraded':
            return 'a stretch — risky';
        case 'risky':
            return 'risky';
        default:
            return null;
    }
}

const selected = (card: ActionCard) => props.modelValue?.card_id === card.id;
</script>

<template>
    <section>
        <div class="mb-2 flex items-baseline justify-between">
            <h3 class="text-sm font-semibold tracking-wide uppercase">{{ title }}</h3>
            <span class="text-xs text-muted-foreground">{{ optional ? 'optional' : 'required' }} · {{ hint }}</span>
        </div>

        <p v-if="!cards.length" class="text-sm text-muted-foreground italic">Nothing offers itself for this beat.</p>

        <div class="grid gap-2 sm:grid-cols-2">
            <div
                v-for="card in cards"
                :key="card.id"
                class="cursor-pointer rounded-xl border p-3 transition"
                :class="[riskClass(card.risk), selected(card) ? 'bg-accent ring-2 ring-primary' : 'hover:bg-accent/50']"
                @click="pick(card)"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-sm font-medium">{{ card.label }}</span>
                    <span v-if="riskLabel(card.risk)" class="shrink-0 text-xs text-amber-600 dark:text-amber-400">
                        {{ riskLabel(card.risk) }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">{{ card.description }}</p>

                <div v-if="card.cost.length" class="mt-1 text-xs text-violet-600 dark:text-violet-400">
                    costs {{ card.cost.map((c) => `${c.amount} ${c.meter.replace('_', ' ')}`).join(', ') }}
                </div>

                <!-- Modifier sub-choices reveal on selection -->
                <div v-if="selected(card) && card.modifiers.length" class="mt-3 space-y-2 border-t border-sidebar-border/50 pt-2">
                    <div v-for="modifier in card.modifiers" :key="modifier.key">
                        <div class="mb-1 text-xs font-medium text-muted-foreground">{{ modifier.label }}</div>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="option in modifier.options"
                                :key="option.value"
                                type="button"
                                class="rounded-full border px-2.5 py-1 text-xs transition"
                                :class="
                                    modelValue?.modifiers[modifier.key] === option.value
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-input hover:bg-accent'
                                "
                                @click.stop="setModifier(modifier.key, option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
