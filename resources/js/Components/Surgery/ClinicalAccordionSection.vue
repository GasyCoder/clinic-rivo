<script setup>
import { computed } from 'vue';
import Icon from '@/Components/UI/Icon.vue';

const props = defineProps({
    open: Boolean,
    step: { type: [String, Number], required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: String, default: 'file-text' },
    complete: Boolean,
    tone: { type: String, default: 'primary' },
});

defineEmits(['toggle']);

const openSectionClass = computed(() => props.tone === 'violet'
    ? 'border-violet-200 bg-white shadow-sm dark:border-violet-900 dark:bg-gray-950'
    : 'border-primary-200 bg-white shadow-sm dark:border-primary-900 dark:bg-gray-950');
const activeStepClass = computed(() => props.tone === 'violet'
    ? 'bg-violet-600 text-white'
    : 'bg-primary-600 text-white');
</script>

<template>
    <section :class="['overflow-hidden rounded-md border transition-colors', open ? openSectionClass : 'border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950']">
        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-start sm:px-5" :aria-expanded="open" @click="$emit('toggle')">
            <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-sm font-bold', open ? activeStepClass : complete ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300']">
                <Icon v-if="complete" name="check" />
                <span v-else>{{ step }}</span>
            </span>
            <span class="min-w-0 flex-1">
                <strong class="block text-sm text-slate-700 dark:text-white">{{ title }}</strong>
                <span v-if="description" class="mt-0.5 block text-xs leading-5 text-slate-400">{{ description }}</span>
            </span>
            <Icon :class="['text-slate-400 transition-transform', open ? 'rotate-180' : '']" name="chevron-down" />
        </button>
        <div v-show="open" class="border-t border-gray-100 p-4 dark:border-gray-900 sm:p-5"><slot /></div>
    </section>
</template>
