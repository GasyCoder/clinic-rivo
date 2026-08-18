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
    'block w-full box-border text-slate-700 dark:text-white placeholder-slate-300 bg-white dark:bg-gray-950 border border-gray-200 dark:border-gray-800 outline-none focus:border-primary-500 focus:dark:border-primary-600 focus:outline-offset-0 focus:outline-primary-200 focus:dark:outline-primary-950 disabled:bg-slate-50 disabled:dark:bg-slate-950 disabled:cursor-not-allowed transition-all focus:z-10': true,
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
