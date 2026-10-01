<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import Popover from '@/Components/Shadcn/Popover.vue';
import { cn } from '@/lib/cn';

/**
 * Un choix dans une longue liste, avec une recherche (shadcn/ui « combobox ») :
 * quelques centaines de prestations ne se parcourent pas dans un menu.
 *
 *   options   `[{ value, label, description? }]`
 *   filter    `(option, query) => boolean` ; par défaut, le libellé et la
 *             description contiennent tous les mots tapés (accents ignorés)
 *
 * Clavier : les flèches parcourent, Entrée choisit, Échap referme.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    options: { type: Array, required: true },
    placeholder: { type: String, default: 'Choisir' },
    searchPlaceholder: { type: String, default: 'Rechercher…' },
    emptyText: { type: String, default: 'Aucun résultat.' },
    filter: { type: Function, default: null },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    id: { type: String, default: undefined },
    /** Au-delà, la liste demande de préciser la recherche. */
    limit: { type: Number, default: 60 },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const query = ref('');
const active = ref(0);
const searchInput = ref(null);
const list = ref(null);

const fold = (value) => String(value ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
const defaultFilter = (option, text) => {
    const haystack = fold(`${option.label} ${option.description ?? ''}`);

    return fold(text).split(/\s+/).filter(Boolean).every((word) => haystack.includes(word));
};

const selected = computed(() => props.options.find((option) => option.value === props.modelValue) ?? null);
const matches = computed(() => {
    const text = query.value.trim();
    if (! text) return props.options;

    return props.options.filter((option) => (props.filter ?? defaultFilter)(option, text));
});
const shown = computed(() => matches.value.slice(0, props.limit));

watch(open, async (isOpen) => {
    if (! isOpen) return;
    query.value = '';
    active.value = Math.max(0, shown.value.findIndex((option) => option.value === props.modelValue));
    await nextTick();
    searchInput.value?.focus();
    scrollActive();
});
watch(query, () => { active.value = 0; });

const scrollActive = () => nextTick(() => list.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }));

const choose = (option) => {
    if (! option || option.disabled) return;
    emit('update:modelValue', option.value);
    open.value = false;
};

const onKeydown = (event) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        active.value = Math.min(Math.max(0, active.value + step), Math.max(0, shown.value.length - 1));
        scrollActive();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        choose(shown.value[active.value]);
    }
};
</script>

<template>
    <Popover v-model:open="open" align="start" width-class="w-[min(36rem,calc(100vw-2rem))]">
        <template #trigger>
            <button
                :id="id"
                type="button"
                role="combobox"
                :aria-expanded="open"
                :aria-invalid="invalid || undefined"
                :disabled="disabled"
                :class="cn(
                    'flex h-[var(--control-h,2.75rem)] w-full items-center gap-2 rounded-md border border-input bg-card px-3 text-start text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-60',
                    invalid && 'border-destructive',
                )"
            >
                <span v-if="selected" class="min-w-0 flex-1 truncate">
                    <span class="font-medium text-foreground">{{ selected.label }}</span>
                    <span v-if="selected.description" class="ms-2 text-muted-foreground">{{ selected.description }}</span>
                </span>
                <span v-else class="min-w-0 flex-1 truncate text-muted-foreground">{{ placeholder }}</span>
                <ChevronsUpDown class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            </button>
        </template>

        <div class="flex items-center gap-2 border-b border-border px-3">
            <Search class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <input
                ref="searchInput"
                v-model="query"
                type="search"
                :placeholder="searchPlaceholder"
                class="h-10 w-full border-0 bg-transparent p-0 text-sm text-foreground outline-none placeholder:text-muted-foreground focus:ring-0"
                :aria-label="searchPlaceholder"
                @keydown="onKeydown"
            >
        </div>
        <ul ref="list" role="listbox" class="max-h-72 overflow-y-auto p-1">
            <li
                v-for="(option, index) in shown"
                :key="option.value"
                role="option"
                :aria-selected="option.value === modelValue"
                :data-active="index === active"
                :class="cn(
                    'flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-sm',
                    index === active ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/60',
                    option.disabled && 'cursor-not-allowed opacity-50',
                )"
                @mouseenter="active = index"
                @click="choose(option)"
            >
                <Check :class="cn('h-4 w-4 shrink-0 text-primary', option.value === modelValue ? 'opacity-100' : 'opacity-0')" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate font-medium">{{ option.label }}</span>
                <span v-if="option.description" class="shrink-0 truncate text-xs text-muted-foreground">{{ option.description }}</span>
            </li>
            <li v-if="! shown.length" class="px-3 py-6 text-center text-sm text-muted-foreground">{{ emptyText }}</li>
            <li v-else-if="matches.length > shown.length" class="px-3 py-2 text-center text-xs text-muted-foreground">
                {{ matches.length - shown.length }} autres — précisez la recherche.
            </li>
        </ul>
    </Popover>
</template>
