<script setup>
import { computed, useSlots } from 'vue';
import { cn } from '@/lib/cn';

/**
 * Une section de travail clinique — un titre, un aide-mémoire, du contenu.
 *
 * Volontairement **pas** une carte. L'écran empilait jusqu'à trois cadres
 * imbriqués (Card > section bordée > bloc bordé), ce qui coûtait trois
 * bordures et trois paddings pour délimiter une seule idée. Ici la
 * séparation vient du titre, de l'espacement et d'un filet, pas d'un
 * nouveau cadre.
 *
 * `variant="framed"` reste disponible pour le rare cas où un bloc doit
 * réellement se détacher de son voisin — un encadré d'avertissement, par
 * exemple — mais ce n'est pas le défaut.
 */
const props = defineProps({
    title: { type: String, default: '' },
    /** Aide-mémoire court, sous le titre. Reste secondaire. */
    hint: { type: String, default: '' },
    /** Composant lucide, jamais un nom : une icône retirée casse au build. */
    icon: { type: [Object, Function], default: null },
    /** `true` marque le champ requis dans le titre. */
    required: { type: Boolean, default: false },
    variant: { type: String, default: 'plain' },
    /** Niveau de titre réel, pour que la hiérarchie du document reste juste. */
    as: { type: String, default: 'h3' },
    contentClass: { type: String, default: '' },
});

const slots = useSlots();

const wrapperClass = computed(() => cn(
    props.variant === 'framed'
        ? 'rounded-lg border border-border bg-muted/20 p-4'
        : '',
));
</script>

<template>
    <section :class="wrapperClass">
        <div v-if="title || slots.actions" class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <component :is="as" class="flex items-center gap-1.5 text-sm font-semibold text-foreground">
                    <component :is="icon" v-if="icon" class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span>{{ title }}</span>
                    <span v-if="required" class="text-destructive" aria-hidden="true">*</span>
                    <span v-if="required" class="sr-only">(obligatoire)</span>
                </component>
                <p v-if="hint" class="mt-1 text-xs leading-5 text-muted-foreground">{{ hint }}</p>
            </div>
            <div v-if="slots.actions" class="shrink-0"><slot name="actions" /></div>
        </div>

        <div :class="cn(title || slots.actions ? 'mt-3' : '', contentClass)">
            <slot />
        </div>
    </section>
</template>
