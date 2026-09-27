<script setup>
import { computed, useAttrs } from 'vue';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: [String, Number],
    size: { type: String, default: 'default' },
});
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();

const componentClass = computed(() => cn(
    'flex h-[var(--control-h)] w-full rounded-lg border border-input bg-card px-3 py-2 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50',
    props.size === 'sm' && 'h-[var(--control-h-sm)] px-2.5',
    props.size === 'lg' && 'h-[var(--control-h-lg)] px-4',
    attrs.class,
));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    return rest;
});
</script>

<template>
    <input
        :class="componentClass"
        :value="modelValue"
        v-bind="forwardedAttrs"
        @input="emit('update:modelValue', $event.target.value)"
    />
</template>
