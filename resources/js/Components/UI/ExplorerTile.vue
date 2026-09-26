<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import { lucideIcon } from '@/lib/icons';
import { cn } from '@/lib/cn';

/**
 * One record shown as a large tile, in the visual language of the supplier
 * folders (FolderCard): big coloured icon, short title, two quiet lines.
 */
const props = defineProps({
    href: { type: String, default: null },
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    meta: { type: String, default: null },
    highlight: { type: String, default: null },
    badge: { type: String, default: null },
    icon: { type: String, default: 'file-text' },
    /** Une image (la photo d'un employé) : elle remplace l'icône, en plus grand. */
    image: { type: String, default: null },
    tone: { type: String, default: 'primary' },
    muted: { type: Boolean, default: false },
    selectable: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'open']);

const glyph = computed(() => lucideIcon(props.icon));

const ICON_TONES = {
    primary: 'bg-primary/10 text-primary',
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
    rose: 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    violet: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300',
    slate: 'bg-muted text-muted-foreground',
};
const BADGE_TONES = {
    primary: 'bg-primary', emerald: 'bg-emerald-500', amber: 'bg-amber-500', rose: 'bg-rose-500', sky: 'bg-sky-500', violet: 'bg-violet-500', slate: 'bg-muted-foreground',
};
</script>

<template>
    <div
        :class="cn(
            'group relative flex flex-col items-center rounded-xl border px-3 pb-4 pt-5 text-center transition-colors',
            selected ? 'border-primary bg-card shadow-sm' : 'border-transparent hover:border-border hover:bg-accent/50',
            muted && 'opacity-60',
        )"
    >
        <Checkbox
            v-if="selectable"
            :model-value="selected"
            :aria-label="`Sélectionner ${title}`"
            :class="cn('absolute start-2.5 top-2.5 transition-opacity', selected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus:opacity-100')"
            @update:model-value="emit('toggle')"
        />
        <component :is="href ? Link : 'button'" :href="href ?? undefined" :type="href ? undefined : 'button'" class="flex w-full flex-col items-center focus-visible:outline-none" @click="!href && emit('open')">
            <span :class="cn('relative grid place-items-center rounded-2xl transition-transform group-hover:scale-105', image ? 'h-20 w-20 bg-muted ring-1 ring-border' : ['h-16 w-16', ICON_TONES[tone] ?? ICON_TONES.primary])">
                <img v-if="image" :src="image" :alt="`Photo de ${title}`" class="h-full w-full rounded-2xl object-cover" loading="lazy">
                <component :is="glyph" v-else class="h-7 w-7" />
                <span v-if="badge" :class="cn('absolute -bottom-1.5 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full px-2 text-[10px] font-bold leading-5 text-white ring-2 ring-card', BADGE_TONES[tone] ?? BADGE_TONES.primary)">{{ badge }}</span>
            </span>
            <span class="mt-4 line-clamp-2 text-sm font-semibold text-foreground">{{ title }}</span>
            <span v-if="subtitle" class="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{{ subtitle }}</span>
            <span v-if="highlight" class="mt-1.5 text-sm font-bold tabular-nums text-foreground">{{ highlight }}</span>
            <span v-if="meta" class="mt-0.5 line-clamp-1 text-[11px] text-muted-foreground">{{ meta }}</span>
        </component>
        <div v-if="$slots.actions" class="mt-3 flex flex-wrap justify-center gap-1.5 opacity-100 transition-opacity lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100">
            <slot name="actions" />
        </div>
    </div>
</template>
