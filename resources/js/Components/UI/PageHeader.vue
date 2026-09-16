<script setup>
import { computed } from 'vue';
import { lucideIcon } from '@/lib/icons';

const props = defineProps({
    eyebrow: String,
    title: String,
    description: String,
    icon: { type: String, default: 'users' },
    tone: { type: String, default: 'primary' },
});

const glyph = computed(() => lucideIcon(props.icon));

const tones = {
    primary: 'bg-primary/10 text-primary',
    sky: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    emerald: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    violet: 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
    slate: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex min-w-0 items-start gap-3.5">
            <span :class="['mt-0.5 grid h-11 w-11 shrink-0 place-items-center rounded-xl', tones[tone] ?? tones.primary]">
                <component :is="glyph" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <p v-if="eyebrow" class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">{{ eyebrow }}</p>
                <h1 class="mt-1 font-heading text-2xl font-bold tracking-tight text-foreground sm:text-3xl">{{ title }}</h1>
                <p v-if="description" class="mt-1.5 max-w-3xl text-sm leading-6 text-muted-foreground">{{ description }}</p>
            </div>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap gap-2 ps-[3.6rem] lg:ps-0">
            <slot name="actions" />
        </div>
    </header>
</template>
