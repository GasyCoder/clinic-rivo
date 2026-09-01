<script setup>
import Icon from '@/Components/UI/Icon.vue';

defineProps({
    label: String,
    value: [String, Number],
    hint: String,
    icon: { type: String, default: 'activity' },
    tone: { type: String, default: 'slate' },
    active: Boolean,
});

const tones = {
    primary: 'bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300',
    sky: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    emerald: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    violet: 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
    slate: 'bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300',
};
</script>

<template>
    <component
        :is="$attrs.href ? 'a' : 'article'"
        :class="[
            'group flex min-h-28 items-start justify-between gap-4 rounded-xl border bg-white p-4 shadow-sm transition dark:bg-gray-950',
            active
                ? 'border-primary-300 ring-2 ring-primary-100 dark:border-primary-800 dark:ring-primary-950'
                : 'border-gray-200 hover:border-gray-300 hover:shadow-md dark:border-gray-900 dark:hover:border-gray-800',
        ]"
    >
        <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">{{ label }}</p>
            <p class="mt-2 font-heading text-2xl font-bold text-slate-800 dark:text-white">{{ value }}</p>
            <p v-if="hint" class="mt-1 truncate text-xs text-slate-400">{{ hint }}</p>
        </div>
        <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-lg', tones[tone] ?? tones.slate]">
            <Icon :name="icon" />
        </span>
    </component>
</template>
