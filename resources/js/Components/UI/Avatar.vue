<script setup>
import { computed } from 'vue';
import Icon from '@/Components/UI/Icon.vue';

const props = defineProps({
    rounded: Boolean,
    size: String,
    variant: String,
    icon: String,
    img: String,
    text: String,
});

const compClass = computed(() => ({
    'relative flex-shrink-0 flex items-center justify-center rounded-full font-medium': true,
    'rounded-md': !props.rounded,
    'rounded-full': props.rounded,
    'text-[9px] h-6 w-6': props.size === 'xs',
    'text-xs h-7 w-7': props.size === 'mb',
    'text-xs h-8 w-8': props.size === 'sm',
    'text-sm h-10 w-10': props.size === 'rg',
    'text-sm h-12 w-12': props.size === 'lg',
    'text-xl h-16 w-16': props.size === 'xl',
    'text-white bg-primary-600': props.variant === 'primary',
    'bg-primary-100 dark:bg-primary-950 text-primary-600': props.variant === 'primary-pale',
    'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300': props.variant === 'danger-pale',
    'text-white bg-slate-600': props.variant === 'slate',
    'bg-slate-100 dark:bg-slate-950 text-slate-600': props.variant === 'slate-pale',
}));
</script>

<template>
    <div :class="compClass">
        <span v-if="text">{{ text }}</span>
        <img
            v-else-if="img"
            :class="{ 'rounded-full': rounded, 'rounded-md': !rounded }"
            :src="img"
            alt=""
        />
        <Icon v-else-if="icon" :name="icon" />
        <slot v-else />
    </div>
</template>
