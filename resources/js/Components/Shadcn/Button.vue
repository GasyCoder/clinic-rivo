<script setup>
import { computed, useAttrs } from 'vue';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    as: { type: [String, Object, Function], default: 'button' },
    variant: { type: String, default: 'default' },
    size: { type: String, default: 'default' },
    icon: { type: Boolean, default: false },
});

const attrs = useAttrs();

const buttonVariants = cva(
    'inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90',
                primary: 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90',
                destructive: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90',
                danger: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90',
                'danger-outline': 'border border-red-200 bg-card text-red-600 shadow-sm hover:bg-red-50 hover:text-red-700 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40',
                outline: 'border border-border bg-card text-foreground shadow-sm hover:bg-accent hover:text-accent-foreground',
                'white-outline': 'border border-border bg-card text-foreground shadow-sm hover:bg-accent hover:text-accent-foreground',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                success: 'bg-emerald-600 text-white shadow-sm hover:bg-emerald-700',
                warning: 'bg-amber-500 text-white shadow-sm hover:bg-amber-600',
                // L'état « ouvert » d'une bascule jaune : même famille de
                // couleur, poids visuel moindre, pour que l'action reste
                // repérable sans crier deux fois plus fort une fois active.
                'warning-outline': 'border border-amber-300 bg-amber-50 text-amber-700 shadow-sm hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60',
                ghost: 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-[var(--control-h)] px-4 py-2',
                xs: 'h-7 rounded-md px-2.5 text-xs',
                sm: 'h-[var(--control-h-sm)] rounded-md px-3',
                rg: 'h-[var(--control-h)] px-4 py-2',
                lg: 'h-[var(--control-h-lg)] px-5',
                'icon-xs': 'h-7 w-7 rounded-md p-0',
                icon: 'h-[var(--control-h-sm)] w-[var(--control-h-sm)] rounded-md',
                'icon-lg': 'h-[var(--control-h-lg)] w-[var(--control-h-lg)] rounded-lg p-0',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

const componentClass = computed(() => cn(buttonVariants({
    variant: props.variant,
    size: props.icon
        ? (props.size === 'xs' ? 'icon-xs' : props.size === 'lg' ? 'icon-lg' : 'icon')
        : props.size,
}), attrs.class));

const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    return rest;
});
</script>

<template>
    <component :is="as" :class="componentClass" v-bind="forwardedAttrs">
        <slot />
    </component>
</template>
