<script setup>
import { computed } from 'vue';
import { Building2 } from 'lucide-vue-next';
import Select from '@/Components/Shadcn/Select.vue';
import { cn } from '@/lib/cn';
import { categoryIcon } from '@/utilities/tariffCategoryIcons';

/**
 * La navigation de « Tarifs & mutuelles » : une catégorie de désignations à la
 * fois, puis les mutuelles. Chaque catégorie est un espace à part — on n'y voit
 * jamais les désignations d'une autre. Sur un écran étroit, la même liste
 * devient un choix déroulant.
 *
 * `modelValue` : la clé de la catégorie ouverte, ou `ORGANIZATIONS`.
 */
const props = defineProps({
    categories: { type: Array, required: true },
    modelValue: { type: String, default: '' },
    /** Nombre de mutuelles actives ; `null` masque l'entrée (droit manquant). */
    organizationsCount: { type: Number, default: null },
});
const emit = defineEmits(['update:modelValue']);

const ORGANIZATIONS = 'ORGANIZATIONS';

const selectOptions = computed(() => [
    {
        label: 'Désignations',
        items: props.categories.map((category) => ({ value: category.key, label: `${category.label} · ${category.active}` })),
    },
    ...(props.organizationsCount === null ? [] : [{
        label: 'Organismes',
        items: [{ value: ORGANIZATIONS, label: `Mutuelles · ${props.organizationsCount}` }],
    }]),
]);

const itemClass = (active) => cn(
    'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
    active ? 'bg-muted font-medium text-foreground' : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
);
</script>

<template>
    <nav aria-label="Catégories du référentiel">
        <Select
            class="w-full lg:hidden"
            :model-value="modelValue"
            :options="selectOptions"
            aria-label="Catégorie affichée"
            @update:model-value="(value) => emit('update:modelValue', value)"
        />

        <div class="hidden space-y-5 lg:block">
            <div>
                <p class="px-2.5 pb-1.5 text-xs font-medium text-muted-foreground">Désignations</p>
                <ul class="space-y-0.5">
                    <li v-for="category in categories" :key="category.key">
                        <button
                            type="button"
                            :class="itemClass(modelValue === category.key)"
                            :aria-current="modelValue === category.key ? 'page' : undefined"
                            @click="emit('update:modelValue', category.key)"
                        >
                            <component :is="categoryIcon(category.key)" class="h-4 w-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate text-start">{{ category.label }}</span>
                            <span class="text-xs tabular-nums text-muted-foreground">{{ category.active }}</span>
                        </button>
                    </li>
                </ul>
            </div>

            <div v-if="organizationsCount !== null">
                <p class="px-2.5 pb-1.5 text-xs font-medium text-muted-foreground">Organismes</p>
                <button
                    type="button"
                    :class="itemClass(modelValue === ORGANIZATIONS)"
                    :aria-current="modelValue === ORGANIZATIONS ? 'page' : undefined"
                    @click="emit('update:modelValue', ORGANIZATIONS)"
                >
                    <Building2 class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 flex-1 truncate text-start">Mutuelles</span>
                    <span class="text-xs tabular-nums text-muted-foreground">{{ organizationsCount }}</span>
                </button>
            </div>
        </div>
    </nav>
</template>
