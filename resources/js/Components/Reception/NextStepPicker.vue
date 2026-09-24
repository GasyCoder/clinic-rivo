<script setup>
import { computed } from 'vue';
import { Compass, Info } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import { cn } from '@/lib/cn';
import { nextStepIcon, toggleNextStep } from '@/utilities/nextSteps';

/**
 * ADR-177 — « Prochaine étape suggérée », facultative.
 *
 * La Réception peut indiquer où le patient pourrait commencer. Ce n'est qu'une
 * indication : aucune case cochée est une réponse valide, plusieurs cases aussi,
 * et aucune ne cache le passage à un autre service ni ne crée d'orientation.
 * C'est pourquoi l'écran ne dit jamais « obligatoire » et n'affiche aucune
 * erreur quand rien n'est coché.
 *
 * Les choix viennent du serveur (`nextStepOptions`) : le composant ne recopie
 * aucune liste.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    /** `[{ value, label }]`, servi par `ReceptionNextStep::options()`. */
    options: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    /** Une grille serrée, pour une colonne latérale. */
    compact: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const selected = computed(() => new Set(props.modelValue ?? []));
const count = computed(() => selected.value.size);

const toggle = (value, checked) => {
    if (props.disabled) return;

    emit('update:modelValue', toggleNextStep(props.modelValue, value, checked, props.options));
};
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-border bg-card" aria-label="Prochaine étape suggérée">
        <header class="flex flex-wrap items-center gap-2 border-b border-border bg-muted/30 px-4 py-3">
            <Compass class="h-4 w-4 text-primary" aria-hidden="true" />
            <h3 class="text-sm font-bold text-foreground">Prochaine étape suggérée</h3>
            <Badge variant="outline">Facultatif</Badge>
        </header>

        <div class="space-y-3 p-4">
            <p class="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>Cette information est indicative. Elle n’empêche pas les autres services autorisés de voir le passage.</span>
            </p>

            <div :class="cn('grid gap-2', compact ? 'grid-cols-2' : 'sm:grid-cols-2 xl:grid-cols-3')" role="group" aria-label="Services suggérés">
                <label
                    v-for="option in options"
                    :key="option.value"
                    :class="cn(
                        'flex items-center gap-2.5 rounded-md border px-3 py-2.5 text-sm transition-colors',
                        selected.has(option.value) ? 'border-primary/50 bg-primary/5' : 'border-border hover:bg-accent',
                        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                    )"
                >
                    <Checkbox
                        :model-value="selected.has(option.value)"
                        :disabled="disabled"
                        :aria-label="`Suggérer ${option.label}`"
                        @update:model-value="(checked) => toggle(option.value, checked)"
                    />
                    <component :is="nextStepIcon(option.value)" class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span class="min-w-0 truncate font-medium text-foreground">{{ option.label }}</span>
                </label>
            </div>

            <p class="text-xs text-muted-foreground" aria-live="polite">
                <template v-if="count">{{ count }} suggestion{{ count > 1 ? 's' : '' }} — le passage reste visible de tous les services autorisés.</template>
                <template v-else>Aucune suggestion : c’est un état normal, le passage reste visible de tous les services autorisés.</template>
            </p>
        </div>
    </section>
</template>
