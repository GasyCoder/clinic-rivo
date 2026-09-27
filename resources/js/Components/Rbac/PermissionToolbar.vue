<script setup>
import { ChevronsDownUp, Search, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { cn } from '@/lib/cn';

/**
 * Chercher et filtrer, pour le socle d'un rôle comme pour les exceptions d'un
 * compte (ADR-178).
 *
 * La recherche traverse tout le catalogue — libellé, code, fonctionnalité,
 * verbe — et déplie d'elle-même les modules qui contiennent un résultat :
 * c'est précisément quand on ne sait pas où vit un droit qu'on le tape.
 *
 * Aucun bouton pour tout ouvrir : un seul module s'ouvre à la fois.
 * « Tout replier » n'apparaît que lorsqu'un module est ouvert.
 */
const props = defineProps({
    search: { type: String, default: '' },
    filter: { type: String, default: 'all' },
    /** `[{ value, label, count, tone? }]` — les compteurs suivent la recherche en cours. */
    filters: { type: Array, default: () => [] },
    /** Au moins un module est ouvert : « Tout replier » a un sens. */
    anyExpanded: { type: Boolean, default: false },
    /** Le nombre de permissions affichées, quand une recherche ou un filtre est actif. */
    shown: { type: Number, default: null },
    placeholder: { type: String, default: 'Rechercher une permission : libellé, code, fonctionnalité…' },
});

const emit = defineEmits(['update:search', 'update:filter', 'collapse-all']);

const clear = () => {
    emit('update:search', '');
    emit('update:filter', 'all');
};
</script>

<template>
    <div class="space-y-2.5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative min-w-0 flex-1">
                <IconInput
                    :model-value="search"
                    :icon="Search"
                    type="search"
                    class="pe-9 [&::-webkit-search-cancel-button]:appearance-none"
                    :placeholder="placeholder"
                    autocomplete="off"
                    aria-label="Rechercher une permission"
                    @update:model-value="emit('update:search', $event)"
                    @keydown.esc="emit('update:search', '')"
                />
                <Button
                    v-if="search"
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    class="absolute end-1.5 top-1/2 z-20 -translate-y-1/2"
                    aria-label="Effacer la recherche"
                    @click="emit('update:search', '')"
                >
                    <X class="h-3.5 w-3.5" />
                </Button>
            </div>
            <Button v-if="anyExpanded" type="button" variant="outline" size="sm" class="shrink-0" @click="emit('collapse-all')">
                <ChevronsDownUp class="h-4 w-4" />
                Tout replier
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filtrer les permissions">
            <button
                v-for="option in filters"
                :key="option.value"
                type="button"
                :class="cn(
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    filter === option.value
                        ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                        : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
                )"
                :aria-pressed="filter === option.value"
                @click="emit('update:filter', option.value)"
            >
                <span
                    v-if="option.tone"
                    :class="cn('h-2 w-2 rounded-full', {
                        'bg-primary': option.tone === 'primary',
                        'bg-amber-500': option.tone === 'warning',
                        'bg-emerald-500': option.tone === 'success',
                        'bg-destructive': option.tone === 'danger',
                        'bg-muted-foreground/40': option.tone === 'muted',
                    }, filter === option.value ? 'ring-2 ring-primary-foreground/70' : '')"
                    aria-hidden="true"
                />
                {{ option.label }}
                <span :class="cn('tabular-nums', filter === option.value ? 'text-primary-foreground/80' : 'font-normal')">{{ option.count }}</span>
            </button>

            <p v-if="shown !== null" class="ms-auto flex items-center gap-2 text-xs text-muted-foreground">
                <span class="tabular-nums"><strong class="text-foreground">{{ shown }}</strong> permission{{ shown > 1 ? 's' : '' }} affichée{{ shown > 1 ? 's' : '' }}</span>
                <button type="button" class="font-semibold text-primary hover:underline" @click="clear">Tout afficher</button>
            </p>
        </div>
    </div>
</template>
