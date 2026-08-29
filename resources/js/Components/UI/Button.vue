<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: String,
    size: String,
    as: [String, Object, Function],
    block: Boolean,
    pill: Boolean,
    icon: Boolean,
});

const compClass = computed(() => ({
    'relative inline-flex items-center text-center align-middle font-heading border transition-all duration-300 whitespace-nowrap': true,
    'border-primary-600 text-white bg-primary-600 hover:bg-primary-700 hover:border-primary-700 active:bg-primary-800 disabled:opacity-60 disabled:pointer-events-none': props.variant === 'primary' || !props.variant,
    'border-slate-700 text-white bg-slate-700 hover:bg-slate-800 hover:border-slate-800 active:bg-slate-900 disabled:opacity-60 disabled:pointer-events-none': props.variant === 'secondary',
    'border-red-600 text-white bg-red-600 hover:bg-red-700 hover:border-red-700 active:bg-red-800 disabled:opacity-60 disabled:pointer-events-none': props.variant === 'danger',
    'border-red-200 text-red-600 bg-white hover:bg-red-600 hover:border-red-600 hover:text-white active:bg-red-700 dark:border-red-900 dark:bg-gray-950 dark:text-red-300 disabled:opacity-60 disabled:pointer-events-none': props.variant === 'danger-outline',
    'border-emerald-600 text-white bg-emerald-600 hover:bg-emerald-700 hover:border-emerald-700 active:bg-emerald-800 disabled:opacity-60 disabled:pointer-events-none': props.variant === 'success',
    'border-amber-500 text-white bg-amber-500 hover:bg-amber-600 hover:border-amber-600 active:bg-amber-700 disabled:opacity-60 disabled:pointer-events-none': props.variant === 'warning',
    'border-gray-300 dark:border-gray-900 text-slate-600 dark:text-slate-200 bg-white dark:bg-gray-900 hover:bg-slate-600 hover:dark:bg-gray-800 hover:text-white hover:border-slate-600 hover:dark:border-gray-800 active:bg-slate-700 active:text-white disabled:opacity-60 disabled:pointer-events-none': props.variant === 'white-outline',
    'text-xxs font-bold leading-5 px-2 tracking-wide': props.size === 'xs' && !props.icon,
    'text-xs font-bold leading-5 px-3 py-1 tracking-wide': props.size === 'sm' && !props.icon,
    'text-sm font-bold leading-4.5 px-5 py-2 tracking-wide': props.size === 'rg' && !props.icon,
    'text-base font-bold leading-4.5 px-6 py-3 tracking-wide': props.size === 'lg' && !props.icon,
    'text-lg font-bold leading-5 px-8 py-4 tracking-wide': props.size === 'xl' && !props.icon,
    'h-5.5 w-5.5 justify-center': props.size === 'xs' && props.icon,
    'h-7.5 w-7.5 justify-center': props.size === 'sm' && props.icon,
    'h-9 w-9 justify-center': props.size === 'rg' && props.icon,
    'h-11 w-11 justify-center': props.size === 'lg' && props.icon,
    'w-full justify-center': props.block,
    rounded: !props.pill,
    'rounded-sm': !props.pill && (props.size === 'sm' || props.size === 'xs'),
    'rounded-md': !props.pill && (props.size === 'lg' || props.size === 'xl'),
    'rounded-full': props.pill,
}));
</script>

<template>
    <component :is="as || 'button'" :class="compClass">
        <slot />
    </component>
</template>
