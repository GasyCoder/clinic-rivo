<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { lucideIcon } from '@/lib/icons';
import { cn } from '@/lib/cn';

const props = defineProps({
    href: { type: String, required: true },
    title: { type: String, required: true },
    subtitle: String,
    meta: String,
    count: [Number, String],
    icon: { type: String, default: 'folder-fill' },
    tone: { type: String, default: 'amber' },
    muted: { type: Boolean, default: false },
});

const glyph = computed(() => lucideIcon(props.icon));

const TONES = {
    amber: 'fill-amber-200 text-amber-500 dark:fill-amber-500/20',
    sky: 'fill-sky-200 text-sky-500 dark:fill-sky-500/20',
    emerald: 'fill-emerald-200 text-emerald-600 dark:fill-emerald-500/20',
    violet: 'fill-violet-200 text-violet-500 dark:fill-violet-500/20',
    slate: 'text-muted-foreground',
    primary: 'fill-primary/20 text-primary',
};
</script>

<template>
    <Link
        :href="href"
        :class="cn(
            'group flex flex-col items-center rounded-xl border border-transparent px-3 py-5 text-center transition-colors',
            'hover:border-border hover:bg-accent/50 focus-visible:border-primary focus-visible:outline-none',
            muted && 'opacity-60',
        )"
    >
        <span class="relative">
            <component :is="glyph" :class="cn('h-14 w-14 transition-transform group-hover:scale-105', TONES[tone] ?? TONES.amber)" />
            <span v-if="count" class="absolute -end-2 -top-1 grid h-6 min-w-6 place-items-center rounded-full bg-primary px-1.5 text-xs font-bold text-primary-foreground">{{ count }}</span>
        </span>
        <span class="mt-3 line-clamp-2 text-sm font-semibold text-foreground">{{ title }}</span>
        <span v-if="subtitle" class="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{{ subtitle }}</span>
        <span v-if="meta" class="mt-0.5 text-[11px] text-muted-foreground">{{ meta }}</span>
    </Link>
</template>
