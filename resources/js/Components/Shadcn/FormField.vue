<script setup>
import { computed } from 'vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';

/**
 * Un champ de formulaire : libellé, contrôle, message d'erreur.
 *
 * La **hauteur de la ligne de libellé est fixe**. Sans cela, un champ dont
 * le libellé porte une commande — le sélecteur « Date / Âge » — avait une
 * ligne plus haute que ses voisins, si bien que son contrôle démarrait plus
 * bas et que la rangée entière paraissait décalée. Ce n'était pas un défaut
 * de hauteur des champs, qui font tous `h-11`, mais de ce qui les précède.
 *
 * `as="div"` sert les contrôles composés — un groupe de boutons radio, une
 * paire type + numéro : un `<label>` qui les enveloppe cocherait le premier
 * au moindre clic dans la zone.
 */
const props = defineProps({
    label: { type: String, required: true },
    /** Rendu `<label>` par défaut : l'association au contrôle est implicite. */
    as: { type: String, default: 'label' },
    required: { type: Boolean, default: false },
    /** Précision discrète à la suite du libellé, ex. « (facultatif) ». */
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    class: { type: String, default: '' },
});

const rootClass = computed(() => cn('min-w-0', props.class));
</script>

<template>
    <component :is="as" :class="rootClass">
        <span class="mb-1.5 flex h-6 items-center justify-between gap-3">
            <span class="truncate text-sm font-medium text-foreground">
                <!-- Les espaces sont posés en marge, jamais dans le texte :
                     Vue condense l'espace entre deux éléments, ce qui
                     collait « Pièce d'identité » à « (facultatif) ». -->
                {{ label }}<span v-if="required" class="ms-0.5 text-destructive">*</span><span v-if="hint" class="ms-1 font-normal text-muted-foreground">{{ hint }}</span>
            </span>
            <slot name="action" />
        </span>

        <slot />

        <FormError v-if="error" class="mt-1">{{ error }}</FormError>
    </component>
</template>
