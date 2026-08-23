<script setup>
import { computed } from 'vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps({
    id: String,
    modelValue: [String, Number, Boolean],
    value: [String, Number, Boolean],
    size: String,
    icon: String,
    nocontrol: Boolean,
});

defineEmits(['update:modelValue']);

const inputCompClass = computed(() => ({
    'peer absolute': true,
    'start-4 top-1/2 -translate-y-1/2 bg-white dark:bg-gray-950 checked:bg-primary-600 checked:dark:bg-primary-600 checked:hover:bg-primary-600 checked:hover:dark:bg-primary-600 checked:focus:bg-primary-600 checked:focus:dark:bg-primary-600 focus:border-primary-600 focus:dark:border-primary-600 outline-none focus:outline-offset-0 focus:outline-primary-200 focus:dark:outline-primary-950 focus:ring-0 focus:ring-offset-0 disabled:bg-slate-50 disabled:dark:bg-slate-900 disabled:checked:bg-primary-400 disabled:checked:dark:bg-primary-400 rounded-full border-2 border-gray-300 dark:border-gray-900 cursor-pointer disabled:cursor-not-allowed transition-all duration-300': !props.nocontrol,
    'h-0 w-0 opacity-0': props.nocontrol,
    'h-4 w-4': !props.size && !props.nocontrol,
    'h-6 w-6': props.size === 'rg' && !props.nocontrol,
    'h-8 w-8': props.size === 'lg' && !props.nocontrol,
}));

const labelCompClass = computed(() => ({
    'text-slate-600 dark:text-slate-400 peer-disabled:text-slate-400 peer-disabled:dark:text-slate-700 text-sm leading-5 border-gray-200 dark:border-gray-800 cursor-pointer inline-block rounded-[inherit]': true,
    'ps-10 pe-4 py-2.5 border': !props.nocontrol,
    'border bg-white px-3 py-1.5 hover:border-slate-300 hover:text-slate-700 peer-checked:border-primary-500 peer-checked:bg-primary-50 peer-checked:font-semibold peer-checked:text-primary-700 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-200 peer-disabled:cursor-not-allowed dark:bg-gray-950 dark:hover:border-gray-700 dark:hover:text-slate-200 dark:peer-checked:border-primary-600 dark:peer-checked:bg-primary-950/30 dark:peer-checked:text-primary-300 dark:peer-focus-visible:ring-primary-950 transition-all duration-200': props.nocontrol,
    'flex items-center gap-3': props.icon,
}));
</script>

<template>
    <div class="relative inline-flex rounded">
        <input
            type="radio"
            :id="id"
            :class="inputCompClass"
            :checked="modelValue === value"
            v-bind="$attrs"
            @change="$emit('update:modelValue', value)"
        />
        <label :class="labelCompClass" :for="id">
            <Icon v-if="icon" class="text-lg" :name="icon" />
            <slot />
        </label>
    </div>
</template>
