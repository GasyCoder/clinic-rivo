<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';

/**
 * One record shown as a large tile, in the visual language of the supplier
 * folders (FolderCard): big coloured icon, short title, two quiet lines.
 */
defineProps({
    href: { type: String, default: null },
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    meta: { type: String, default: null },
    highlight: { type: String, default: null },
    badge: { type: String, default: null },
    icon: { type: String, default: 'file-text' },
    tone: { type: String, default: 'primary' },
    muted: { type: Boolean, default: false },
    selectable: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'open']);

const ICON_TONES = {
    primary: 'bg-primary-50 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300',
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
    rose: 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    violet: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300',
    slate: 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400',
};
const BADGE_TONES = {
    primary: 'bg-primary-600', emerald: 'bg-emerald-500', amber: 'bg-amber-500', rose: 'bg-rose-500', sky: 'bg-sky-500', violet: 'bg-violet-500', slate: 'bg-slate-400',
};
</script>

<template>
    <div
        :class="[
            'group relative flex flex-col items-center rounded-xl border px-3 pb-4 pt-5 text-center transition',
            selected ? 'border-primary-400 bg-white shadow-sm dark:border-primary-700 dark:bg-gray-950' : 'border-transparent hover:border-gray-200 hover:bg-white hover:shadow-sm dark:hover:border-gray-800 dark:hover:bg-gray-950',
            muted && 'opacity-60',
        ]"
    >
        <input
            v-if="selectable"
            type="checkbox"
            :checked="selected"
            :aria-label="`Sélectionner ${title}`"
            :class="['absolute start-2.5 top-2.5 h-4 w-4 rounded border-gray-300 text-primary-600 transition', selected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus:opacity-100']"
            @change="emit('toggle')"
        >
        <component :is="href ? Link : 'button'" :href="href ?? undefined" :type="href ? undefined : 'button'" class="flex w-full flex-col items-center focus-visible:outline-none" @click="!href && emit('open')">
            <span :class="['relative flex h-16 w-16 items-center justify-center rounded-2xl text-3xl transition group-hover:scale-105', ICON_TONES[tone] ?? ICON_TONES.primary]">
                <Icon :name="icon" />
                <span v-if="badge" :class="['absolute -bottom-1.5 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full px-2 text-[10px] font-bold leading-5 text-white ring-2 ring-white dark:ring-gray-950', BADGE_TONES[tone] ?? BADGE_TONES.primary]">{{ badge }}</span>
            </span>
            <span class="mt-4 line-clamp-2 text-sm font-semibold text-slate-800 dark:text-white">{{ title }}</span>
            <span v-if="subtitle" class="mt-0.5 line-clamp-1 text-xs text-slate-500">{{ subtitle }}</span>
            <span v-if="highlight" class="mt-1.5 text-sm font-bold tabular-nums text-slate-700 dark:text-slate-200">{{ highlight }}</span>
            <span v-if="meta" class="mt-0.5 line-clamp-1 text-[11px] text-slate-400">{{ meta }}</span>
        </component>
        <div v-if="$slots.actions" class="mt-3 flex flex-wrap justify-center gap-1.5 opacity-100 transition lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100">
            <slot name="actions" />
        </div>
    </div>
</template>
