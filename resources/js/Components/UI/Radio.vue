<script setup>
import { computed } from 'vue';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps({
    id: String,
    modelValue: [String, Number, Boolean],
    value: [String, Number, Boolean],
    size: String,
});

defineEmits(['update:modelValue']);

const inputCompClass = computed(() => ({
    'peer bg-white dark:bg-gray-950 checked:bg-primary-600 checked:dark:bg-primary-600 checked:hover:bg-primary-600 checked:hover:dark:bg-primary-600 checked:focus:bg-primary-600 checked:focus:dark:bg-primary-600 focus:border-primary-600 focus:dark:border-primary-600 outline-none focus:outline-offset-0 focus:outline-primary-200 focus:dark:outline-primary-950 focus:ring-0 focus:ring-offset-0 disabled:bg-slate-50 disabled:dark:bg-slate-900 disabled:checked:bg-primary-400 disabled:checked:dark:bg-primary-400 rounded-full border-2 border-gray-300 dark:border-gray-900 cursor-pointer disabled:cursor-not-allowed transition-all duration-300': true,
    'h-6 w-6': !props.size,
    'h-4 w-4': props.size === 'sm',
    'h-8 w-8': props.size === 'lg',
}));

const labelCompClass = computed(() => ({
    'text-slate-600 dark:text-slate-400 peer-disabled:text-slate-400 peer-disabled:dark:text-slate-700 cursor-pointer inline-block': true,
    'text-sm leading-5 pt-0.5 ps-3': !props.size,
    'text-xs leading-4 ps-2': props.size === 'sm',
    'text-base leading-6 pt-1 ps-4': props.size === 'lg',
}));
</script>

<template>
    <div class="inline-flex">
        <input
            type="radio"
            :id="id"
            :class="inputCompClass"
            :checked="modelValue === value"
            v-bind="$attrs"
            @change="$emit('update:modelValue', value)"
        />
        <label v-if="$slots.default" :class="labelCompClass" :for="id"><slot /></label>
    </div>
</template>
