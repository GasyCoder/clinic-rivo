<script setup>
import { computed } from 'vue';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps({
    modelValue: [String, Number],
    icon: String,
    size: String,
});

defineEmits(['update:modelValue']);

const compClass = computed(() => ({
    // ring, not outline: CSS outline never follows border-radius, so on a
    // rounded input it renders as a mismatched square box around a rounded
    // field — ring uses box-shadow, which clips to the border-radius.
    'block w-full box-border text-slate-700 dark:text-white placeholder-slate-300 bg-white dark:bg-gray-950 border border-gray-200 dark:border-gray-800 outline-none focus:border-primary-500 focus:dark:border-primary-600 focus:ring-2 focus:ring-primary-200 focus:dark:ring-primary-950 focus:ring-offset-0 aria-[invalid=true]:border-red-400 aria-[invalid=true]:focus:border-red-500 aria-[invalid=true]:focus:ring-red-100 aria-[invalid=true]:focus:dark:ring-red-950 disabled:bg-slate-50 disabled:dark:bg-slate-950 disabled:cursor-not-allowed transition-all focus:z-10': true,
    'text-sm leading-4.5 py-1.5 h-9 rounded': !props.size,
    'text-base leading-4.5 px-4 py-2.5 h-11 rounded-md': props.size === 'lg',
    'text-xs leading-5 px-4 py-1 h-8 rounded-sm': props.size === 'sm',
    'px-4': !props.icon,
    'ps-10 pe-4': props.icon === 'start',
    'pe-10 ps-4': props.icon === 'end',
}));
</script>

<template>
    <input
        :class="compClass"
        :value="modelValue"
        v-bind="$attrs"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
