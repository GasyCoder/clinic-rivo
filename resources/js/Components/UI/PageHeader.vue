<script setup>
import { computed } from 'vue';
import { lucideIcon } from '@/lib/icons';
import { cn } from '@/lib/cn';

const props = defineProps({
    eyebrow: String,
    title: String,
    description: String,
    // Un nom (table `lib/icons.js`) ou directement le composant lucide :
    // un nom absent de la table retombait sur une icône de bac.
    icon: { type: [String, Object, Function], default: 'users' },
    tone: { type: String, default: 'primary' },
    compact: { type: Boolean, default: false },
});

const glyph = computed(() => (typeof props.icon === 'string' ? lucideIcon(props.icon) : props.icon));

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
    <header :class="cn('flex flex-col lg:flex-row lg:items-center lg:justify-between', compact ? 'gap-2.5' : 'gap-4')">
        <div :class="cn('flex min-w-0 items-start', compact ? 'gap-3' : 'gap-3.5')">
            <span :class="[compact ? 'grid h-9 w-9 shrink-0 place-items-center rounded-lg' : 'mt-0.5 grid h-11 w-11 shrink-0 place-items-center rounded-xl', tones[tone] ?? tones.primary]">
                <component :is="glyph" :class="compact ? 'h-4 w-4' : 'h-5 w-5'" />
            </span>
            <div class="min-w-0">
                <p v-if="eyebrow" :class="cn('font-bold uppercase tracking-[0.16em] text-muted-foreground', compact ? 'text-[10px]' : 'text-[11px]')">{{ eyebrow }}</p>
                <h1 :class="cn('font-heading font-bold tracking-tight text-foreground', compact ? 'mt-0.5 text-xl sm:text-2xl' : 'mt-1 text-2xl sm:text-3xl')">{{ title }}</h1>
                <p v-if="description" :class="cn('max-w-3xl text-sm text-muted-foreground', compact ? 'mt-0.5 leading-5' : 'mt-1.5 leading-6')">{{ description }}</p>
            </div>
        </div>
        <div v-if="$slots.actions" :class="cn('flex shrink-0 flex-wrap gap-2 lg:ps-0', compact ? 'ps-12' : 'ps-[3.6rem]')">
            <slot name="actions" />
        </div>
    </header>
</template>
