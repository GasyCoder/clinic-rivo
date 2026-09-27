<script setup>
import PregnancyLinkSection from '@/Components/Maternity/PregnancyLinkSection.vue';
import PrenatalComparisonCard from '@/Components/Maternity/PrenatalComparisonCard.vue';
import Card from '@/Components/Shadcn/Card.vue';

/**
 * ADR-204 — étape 1 : la grossesse, et ce qui a changé depuis la dernière
 * consultation. La comparaison est servie par le serveur ; aucune ancienne
 * valeur n'est recopiée dans les champs du jour.
 *
 * Les repères de la grossesse, le prochain rendez-vous et l'historique complet
 * vivent dans l'en-tête du parcours : les répéter ici les affichait deux fois.
 */
defineProps({
    form: { type: Object, required: true },
    pregnancy: { type: Object, default: null },
    activePregnancies: { type: Array, default: () => [] },
    selectionRequired: { type: Boolean, default: false },
    capabilities: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
    reference: { type: Object, required: true },
    comparison: { type: Object, default: null },
});
defineEmits(['continue', 'create']);
</script>

<template>
    <div class="space-y-4">
        <PregnancyLinkSection
            :form="form"
            :pregnancy="pregnancy"
            :active-pregnancies="activePregnancies"
            :selection-required="selectionRequired"
            :can-prenatal="Boolean(capabilities.can_prenatal)"
            :read-only="readOnly"
            :reference="reference"
            @continue="$emit('continue', $event)"
            @create="$emit('create')"
        />

        <PrenatalComparisonCard v-if="comparison" :comparison="comparison" />
        <Card v-else-if="pregnancy && ! selectionRequired" class="px-4 py-3 text-xs text-muted-foreground">
            Première consultation de cette grossesse : aucune comparaison à afficher.
        </Card>
    </div>
</template>
