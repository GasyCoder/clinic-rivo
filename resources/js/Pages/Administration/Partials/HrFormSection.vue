<script setup>
import Icon from '@/Components/UI/Icon.vue';

defineProps({
    number: [Number, String],
    title: String,
    description: String,
    icon: { type: String, default: 'edit' },
    tone: { type: String, default: 'primary' },
    optional: Boolean,
});

const tones = {
    primary: 'bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300',
    sky: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    violet: 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    emerald: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    slate: 'bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300',
};
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
        <header class="flex items-start gap-3 border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40">
            <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', tones[tone] ?? tones.primary]">
                <span v-if="number" class="text-sm font-black">{{ number }}</span>
                <Icon v-else :name="icon" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2"><h2 class="text-sm font-bold text-slate-800 dark:text-white">{{ title }}</h2><span v-if="optional" class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:bg-gray-900">Facultatif</span></div>
                <p v-if="description" class="mt-1 text-xs leading-5 text-slate-500">{{ description }}</p>
            </div>
        </header>
        <div class="p-5 sm:p-6"><slot /></div>
    </section>
</template>
