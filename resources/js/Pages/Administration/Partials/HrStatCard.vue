<script setup>
import { computed } from 'vue';
import { lucideIcon } from '@/lib/icons';
import { cn } from '@/lib/cn';

/*
 * Un chiffre RH en carte. Quand la carte est un filtre (enveloppée dans un
 * lien), `active` dit qu'elle est celle qu'on regarde.
 */
const props = defineProps({
    label: String,
    value: [String, Number],
    hint: String,
    // Un composant lucide, ou un nom de la table `lib/icons.js`.
    icon: { type: [String, Object, Function], default: 'activity' },
    tone: { type: String, default: 'slate' },
    active: Boolean,
});

const TONES = {
    primary: 'bg-primary/10 text-primary',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
    violet: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300',
    rose: 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
    slate: 'bg-muted text-muted-foreground',
};
const glyph = computed(() => (typeof props.icon === 'string' ? lucideIcon(props.icon) : props.icon));
</script>

<template>
    <article
        :class="cn(
            'flex min-h-24 items-start justify-between gap-4 rounded-xl border bg-card p-4 shadow-sm transition',
            active ? 'border-primary ring-1 ring-ring/25' : 'border-border hover:border-primary/30 hover:shadow-md',
        )"
    >
        <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ label }}</p>
            <p class="mt-1.5 text-2xl font-bold tabular-nums text-foreground">{{ value }}</p>
            <p v-if="hint" class="mt-0.5 truncate text-xs text-muted-foreground">{{ hint }}</p>
        </div>
        <span :class="cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', TONES[tone] ?? TONES.slate)">
            <component :is="glyph" class="h-4 w-4" aria-hidden="true" />
        </span>
    </article>
</template>
