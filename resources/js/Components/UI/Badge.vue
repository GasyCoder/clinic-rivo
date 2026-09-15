<script setup>
defineProps({
    tone: { type: String, default: 'neutral' },
    dot: { type: Boolean, default: false },
});

const TONES = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300',
    warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    danger: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300',
    info: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-300',
    primary: 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/30 dark:text-primary-300',
    neutral: 'border-gray-200 bg-gray-50 text-slate-600 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-300',
};

const DOTS = {
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-red-500',
    info: 'bg-sky-500',
    primary: 'bg-primary-500',
    neutral: 'bg-slate-400',
};
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-semibold', TONES[tone] ?? TONES.neutral]">
        <span v-if="dot" :class="['h-1.5 w-1.5 rounded-full', DOTS[tone] ?? DOTS.neutral]" />
        <slot />
    </span>
</template>
