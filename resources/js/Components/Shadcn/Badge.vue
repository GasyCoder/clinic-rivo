<script setup>
import { computed, useAttrs } from 'vue';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const props = defineProps({ variant: { type: String, default: 'default' } });
const attrs = useAttrs();
const variants = cva(
    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground',
                secondary: 'border-transparent bg-secondary text-secondary-foreground',
                outline: 'border-border bg-card text-muted-foreground',
                destructive: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
                warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
                success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
            },
        },
        defaultVariants: { variant: 'default' },
    },
);
const componentClass = computed(() => cn(variants({ variant: props.variant }), attrs.class));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    return rest;
});
</script>

<template>
    <span :class="componentClass" v-bind="forwardedAttrs"><slot /></span>
</template>
