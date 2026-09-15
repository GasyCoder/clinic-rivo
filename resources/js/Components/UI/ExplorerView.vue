<script setup>
import { ref } from 'vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';

/**
 * A list shown like a file explorer: tiles (« Grandes icônes ») or a table
 * (« Liste »), the choice remembered per browser. Same frame as the supplier
 * folders page, so every list of the module looks and behaves alike.
 */
const props = defineProps({
    storageKey: { type: String, required: true },
    count: { type: Number, default: 0 },
    countLabel: { type: String, default: 'élément' },
    emptyIcon: { type: String, default: 'folder' },
    emptyTitle: { type: String, default: 'Rien à afficher' },
    emptyDescription: { type: String, default: null },
    defaultView: { type: String, default: 'grid' },
    // false when the list already sits inside a card.
    framed: { type: Boolean, default: true },
});

const key = `rivo.view.${props.storageKey}`;
const read = () => {
    try { return localStorage.getItem(key) ?? props.defaultView; } catch { return props.defaultView; }
};
const view = ref(read() === 'list' ? 'list' : 'grid');
const setView = (value) => {
    view.value = value;
    try { localStorage.setItem(key, value); } catch { /* private window */ }
};
</script>

<template>
    <section :class="['overflow-hidden bg-gray-50/60 dark:bg-gray-1000/40', framed && 'rounded-xl border border-gray-200 shadow-sm dark:border-gray-900']">
        <div class="flex flex-wrap items-center gap-3 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-900 dark:bg-gray-950">
            <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-800 dark:bg-gray-900" role="group" aria-label="Affichage">
                <button
                    v-for="option in [{ value: 'grid', icon: 'grid-alt', label: 'Grandes icônes' }, { value: 'list', icon: 'list', label: 'Liste' }]"
                    :key="option.value"
                    type="button"
                    :title="option.label"
                    :aria-pressed="view === option.value"
                    :class="['inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition', view === option.value ? 'bg-white text-slate-800 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-white']"
                    @click="setView(option.value)"
                >
                    <Icon :name="option.icon" /><span class="hidden sm:inline">{{ option.label }}</span>
                </button>
            </div>
            <span class="text-sm text-slate-500">{{ count }} {{ countLabel }}{{ count > 1 ? 's' : '' }}</span>
            <div v-if="$slots.toolbar" class="flex min-w-0 flex-1 flex-wrap items-center justify-end gap-2"><slot name="toolbar" /></div>
        </div>
        <slot name="above" />

        <template v-if="count">
            <div v-if="view === 'grid'" class="grid grid-cols-2 gap-1 p-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                <slot name="grid" />
            </div>
            <div v-else class="overflow-x-auto bg-white dark:bg-gray-950">
                <slot name="list" />
            </div>
        </template>
        <div v-else class="bg-white dark:bg-gray-950">
            <EmptyState :icon="emptyIcon" :title="emptyTitle" :description="emptyDescription" />
        </div>
        <slot name="footer" />
    </section>
</template>
