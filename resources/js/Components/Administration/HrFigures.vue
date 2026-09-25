<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from 'lucide-vue-next';
import { lucideIcon } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { HR_SITE_BASE, mapHrPath } from '@/utilities/hrPath';
import { HR_FIGURE_TONES as TONES, HR_FIGURES, isVisibleFigure } from '@/utilities/hrFigures';

/**
 * ADR-066 — the HR figures, identical wherever they appear: the site overview,
 * the HR space and the central portal. Same order, same words, same colours.
 * Each tile may open its list: the clinic HR space, or on the portal the HR
 * space of the chosen site (ADR-182).
 * A null figure means the account may not see it, and the tile is hidden.
 *
 * One compact block in two groups — what waits for a decision, then the day's
 * headcount — rather than seven large cards on two uneven rows.
 */
const props = defineProps({
    summary: { type: Object, required: true },
    // true at the clinic: each tile links to the list behind the figure.
    linkable: { type: Boolean, default: false },
    // 'todo' = only what waits for a decision; 'all' adds the headcount group.
    show: { type: String, default: 'all' },
    // ADR-182 — where the lists live: the clinic HR space, or a site's HR
    // space on the portal (`/super-admin/sites/A/rh`).
    base: { type: String, default: HR_SITE_BASE },
});

const target = (href) => mapHrPath(href, props.base);

const figures = (group) => HR_FIGURES
    .filter((item) => item.group === group && isVisibleFigure(props.summary, item.key))
    .map((item) => ({
        ...item,
        value: Number(props.summary[item.key] ?? 0),
        hint: item.key === 'active_employees' && props.summary.inactive_employees !== undefined
            ? `${props.summary.inactive_employees} inactif · ${props.summary.archived_employees} archivé`
            : null,
    }));

const groups = computed(() => [
    { key: 'todo', label: 'À traiter', items: figures('todo') },
    ...(props.show === 'all' ? [{ key: 'headcount', label: 'Effectif du jour', items: figures('headcount') }] : []),
].filter((group) => group.items.length));

const pending = (item) => item.group === 'todo' && item.value > 0;
</script>

<template>
    <div
        v-if="groups.length"
        :class="cn(
            'grid overflow-hidden rounded-xl border border-border bg-card shadow-sm',
            groups.length > 1 && 'lg:grid-cols-[3fr_4fr] lg:divide-x lg:divide-border max-lg:divide-y max-lg:divide-border',
        )"
    >
        <section v-for="group in groups" :key="group.key" :aria-label="group.label" class="p-2">
            <p class="px-2 pb-1 pt-1 text-[10px] font-bold uppercase tracking-[0.16em] text-muted-foreground">{{ group.label }}</p>
            <ul :class="cn('grid grid-cols-2 gap-1', group.key === 'todo' ? 'sm:grid-cols-3' : 'sm:grid-cols-4')">
                <li v-for="item in group.items" :key="item.key">
                    <component
                        :is="linkable ? Link : 'div'"
                        :href="linkable ? target(item.href) : undefined"
                        :title="item.label"
                        :aria-label="`${item.label} : ${item.value}`"
                        :class="cn(
                            'group relative flex h-full flex-col gap-1.5 rounded-lg px-2.5 py-2',
                            pending(item) && 'bg-amber-50/70 dark:bg-amber-950/25',
                            linkable && 'transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        )"
                    >
                        <span class="flex items-center gap-2">
                            <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-md', TONES[item.tone]]">
                                <component :is="lucideIcon(item.icon)" class="h-3.5 w-3.5" />
                            </span>
                            <span
                                :class="cn(
                                    'text-xl font-bold leading-none tabular-nums',
                                    pending(item) ? 'text-amber-700 dark:text-amber-300' : item.value ? 'text-foreground' : 'text-muted-foreground/50',
                                )"
                            >{{ item.value }}</span>
                        </span>
                        <span class="text-xs font-medium leading-snug text-muted-foreground">{{ item.tile }}</span>
                        <span v-if="item.hint" class="-mt-1 text-[11px] leading-tight text-muted-foreground/80">{{ item.hint }}</span>
                        <ArrowUpRight
                            v-if="linkable"
                            class="absolute end-2 top-2.5 h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                            aria-hidden="true"
                        />
                    </component>
                </li>
            </ul>
        </section>
    </div>
</template>
