<script setup>
import { computed, useAttrs } from 'vue';
import { Check, Minus } from 'lucide-vue-next';
import { CheckboxIndicator, CheckboxRoot } from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

/**
 * `modelValue` accepte aussi `'indeterminate'` : une case qui résume un groupe
 * — « tout le module », « toute la fonctionnalité » — dit alors « une partie
 * seulement » (ADR-178). Un clic depuis cet état coche tout ; la valeur émise
 * reste toujours un booléen, si bien qu'aucun écran existant ne change.
 */
const props = defineProps({ modelValue: { type: [Boolean, String], default: false } });
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();
const model = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value === true),
});
const componentClass = computed(() => cn(
    'peer grid h-4 w-4 shrink-0 place-items-center rounded border border-input bg-card text-primary-foreground shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary',
    attrs.class,
));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    return rest;
});
</script>

<template>
    <CheckboxRoot v-model="model" :class="componentClass" v-bind="forwardedAttrs">
        <CheckboxIndicator class="grid place-items-center">
            <Minus v-if="modelValue === 'indeterminate'" class="h-3.5 w-3.5" :stroke-width="3" />
            <Check v-else class="h-3.5 w-3.5" :stroke-width="3" />
        </CheckboxIndicator>
    </CheckboxRoot>
</template>
