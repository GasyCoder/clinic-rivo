<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/cn';

/**
 * Le rail des catégories de permissions.
 *
 * Une quarantaine de catégories réparties sur sept domaines : sans repères,
 * la liste se lit ligne à ligne et on ne sait jamais où l'on en est. Trois
 * choses la rendent parcourable — une bande grise qui tient le domaine en
 * tête pendant le défilement, une ligne par catégorie séparée de la
 * suivante, et un compteur aligné à droite qui se lit en colonne.
 *
 * Le même rail sert la configuration d'un compte et le socle d'un rôle : la
 * navigation ne doit pas changer d'allure selon ce qu'on règle.
 */
const props = defineProps({
    /**
     * `[{ key, label, categories: [{ key, label, count, marked, hidden, title }] }]`
     * — le parent décide ce que compte `count` (droits visibles, accordés
     * sur total…) et ce que signale `marked` ; le rail ne fait que l'afficher.
     */
    groups: { type: Array, default: () => [] },
    modelValue: { type: String, default: '' },
    markedTitle: { type: String, default: '' },
    class: { type: String, default: '' },
});

defineEmits(['update:modelValue']);

const visibleGroups = computed(() => props.groups
    .map((group) => ({ ...group, categories: group.categories.filter((category) => ! category.hidden) }))
    .filter((group) => group.categories.length));
</script>

<template>
    <div :class="cn('overflow-hidden rounded-lg border border-border bg-card', props.class)">
        <div class="max-h-[22rem] overflow-y-auto lg:max-h-[26rem]">
            <template v-for="group in visibleGroups" :key="group.key">
                <p class="sticky top-0 z-10 border-b border-t border-border bg-muted px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground first:border-t-0">
                    {{ group.label }}
                </p>
                <button
                    v-for="category in group.categories"
                    :key="category.key"
                    type="button"
                    :title="category.title"
                    :class="cn(
                        'relative flex w-full items-center gap-2 border-b border-border/50 px-3 py-2 text-start text-xs leading-4 transition-colors',
                        modelValue === category.key
                            ? 'bg-accent font-semibold text-foreground'
                            : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                    )"
                    :aria-current="modelValue === category.key ? 'page' : undefined"
                    @click="$emit('update:modelValue', category.key)"
                >
                    <!-- Même repère d'état actif que le menu latéral de
                         l'application : un rail, pas une couleur de texte
                         seule, qui se voit du coin de l'œil en défilant. -->
                    <span v-if="modelValue === category.key" class="absolute inset-y-1 start-0 w-0.5 rounded-e-full bg-primary" />
                    <span class="min-w-0 flex-1 truncate">{{ category.label }}</span>
                    <span v-if="category.marked" class="h-1.5 w-1.5 shrink-0 rounded-full bg-primary" :title="markedTitle" />
                    <span :class="cn(
                        'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                        modelValue === category.key ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground',
                    )">{{ category.count }}</span>
                </button>
            </template>

            <p v-if="visibleGroups.length === 0" class="px-3 py-6 text-center text-xs text-muted-foreground">
                Aucune catégorie ne correspond.
            </p>
        </div>

        <div v-if="$slots.footer" class="border-t border-border bg-muted/40 p-2">
            <slot name="footer" />
        </div>
    </div>
</template>
