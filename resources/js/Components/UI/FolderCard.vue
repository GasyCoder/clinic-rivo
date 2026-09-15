<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';

defineProps({
    href: { type: String, required: true },
    title: { type: String, required: true },
    subtitle: String,
    meta: String,
    count: [Number, String],
    icon: { type: String, default: 'folder-fill' },
    tone: { type: String, default: 'amber' },
    muted: { type: Boolean, default: false },
});

const TONES = {
    amber: 'text-amber-400',
    sky: 'text-sky-400',
    emerald: 'text-emerald-500',
    violet: 'text-violet-400',
    slate: 'text-slate-400',
    primary: 'text-primary-500',
};
</script>

<template>
    <Link
        :href="href"
        :class="[
            'group flex flex-col items-center rounded-xl border border-transparent px-3 py-5 text-center transition',
            'hover:border-gray-200 hover:bg-white hover:shadow-sm focus-visible:border-primary-400 focus-visible:outline-none dark:hover:border-gray-800 dark:hover:bg-gray-950',
            muted && 'opacity-60',
        ]"
    >
        <span class="relative">
            <Icon :name="icon" :class="['text-6xl leading-none transition group-hover:scale-105', TONES[tone] ?? TONES.amber]" />
            <span v-if="count" class="absolute -end-2 -top-1 min-w-[1.5rem] rounded-full bg-primary-600 px-1.5 text-xs font-bold leading-6 text-white">{{ count }}</span>
        </span>
        <span class="mt-3 line-clamp-2 text-sm font-semibold text-slate-800 dark:text-white">{{ title }}</span>
        <span v-if="subtitle" class="mt-0.5 line-clamp-1 text-xs text-slate-500">{{ subtitle }}</span>
        <span v-if="meta" class="mt-0.5 text-[11px] text-slate-400">{{ meta }}</span>
    </Link>
</template>
