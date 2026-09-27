<script setup>
import { computed, useAttrs } from 'vue';
import { RadioGroupRoot } from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

/** Un choix parmi plusieurs (shadcn/ui) : flèches du clavier, un seul choix, lu comme un groupe. */
const props = defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();
const model = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});
const componentClass = computed(() => cn('grid gap-2', attrs.class));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <RadioGroupRoot v-model="model" :class="componentClass" v-bind="forwardedAttrs"><slot /></RadioGroupRoot>
</template>
