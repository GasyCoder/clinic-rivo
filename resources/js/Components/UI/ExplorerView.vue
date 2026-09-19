<script setup>
import { onMounted, ref } from 'vue';
import { LayoutGrid, List } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { cn } from '@/lib/cn';

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
// The page is server-rendered: the server cannot see `localStorage`, so the
// first render is always the default view, and the remembered one is applied
// once the browser has the page. Reading it while rendering made the client
// differ from the HTML it hydrates — the same fault as the sidebar order.
const view = ref(props.defaultView === 'list' ? 'list' : 'grid');
onMounted(() => {
    view.value = read() === 'list' ? 'list' : 'grid';
});
const OPTIONS = [
    { value: 'grid', icon: LayoutGrid, label: 'Grandes icônes' },
    { value: 'list', icon: List, label: 'Liste' },
];
const setView = (value) => {
    view.value = value;
    try { localStorage.setItem(key, value); } catch { /* private window */ }
};
</script>

<template>
    <section :class="cn('overflow-hidden bg-muted/30', framed && 'rounded-xl border border-border shadow-sm')">
        <div class="flex flex-wrap items-center gap-3 border-b border-border bg-card px-4 py-3">
            <div class="inline-flex rounded-lg bg-muted p-1" role="group" aria-label="Affichage">
                <button
                    v-for="option in OPTIONS"
                    :key="option.value"
                    type="button"
                    :title="option.label"
                    :aria-pressed="view === option.value"
                    :class="cn('inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-xs font-bold transition-colors', view === option.value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                    @click="setView(option.value)"
                >
                    <component :is="option.icon" class="h-4 w-4" /><span class="hidden sm:inline">{{ option.label }}</span>
                </button>
            </div>
            <span class="text-sm tabular-nums text-muted-foreground">{{ count }} {{ countLabel }}{{ count > 1 ? 's' : '' }}</span>
            <div v-if="$slots.toolbar" class="flex min-w-0 flex-1 flex-wrap items-center justify-end gap-2"><slot name="toolbar" /></div>
        </div>
        <slot name="above" />

        <template v-if="count">
            <div v-if="view === 'grid'" class="grid grid-cols-2 gap-1 p-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                <slot name="grid" />
            </div>
            <div v-else class="overflow-x-auto bg-card">
                <slot name="list" />
            </div>
        </template>
        <div v-else class="bg-card">
            <EmptyState :icon="emptyIcon" :title="emptyTitle" :description="emptyDescription" />
        </div>
        <slot name="footer" />
    </section>
</template>
