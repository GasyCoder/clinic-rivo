<script setup>
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui';

/**
 * Un choix court parmi deux ou trois (shadcn/ui, comme des onglets) : chaque
 * option se voit, un clic la choisit, les flèches du clavier passent de l'une
 * à l'autre. Pour une liste plus longue, `Select`.
 */
defineProps({
    modelValue: { type: String, default: '' },
    /** `[{ value, label, icon?, hint? }]` */
    options: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    ariaLabel: { type: String, default: '' },
    id: { type: String, default: undefined },
});
const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <RadioGroupRoot
        :id="id"
        :model-value="modelValue"
        :disabled="disabled"
        orientation="horizontal"
        :aria-label="ariaLabel || undefined"
        class="inline-flex max-w-full flex-wrap gap-1 rounded-lg border border-border bg-muted/40 p-1"
        @update:model-value="(value) => emit('update:modelValue', value)"
    >
        <RadioGroupItem
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            :disabled="option.disabled"
            :title="option.hint || undefined"
            class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-background data-[state=checked]:text-foreground data-[state=checked]:shadow-sm"
        >
            <component :is="option.icon" v-if="option.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />{{ option.label }}
        </RadioGroupItem>
    </RadioGroupRoot>
</template>
