<script setup>
import { History } from 'lucide-vue-next';
import PregnancyHistory from '@/Components/Maternity/PregnancyHistory.vue';
import PregnancyParaclinicalHistory from '@/Components/Maternity/PregnancyParaclinicalHistory.vue';
import PregnancySummaryCard from '@/Components/Maternity/PregnancySummaryCard.vue';
import Sheet from '@/Components/Shadcn/Sheet.vue';

/**
 * ADR-204 — tout le suivi de la grossesse, ouvert à côté du parcours en cours.
 *
 * Pendant un accouchement comme pendant une consultation, la sage-femme relit
 * les consultations précédentes, la comparaison et les examens sans quitter
 * l'étape où elle se trouve : un panneau latéral, jamais une autre page.
 */
defineProps({
    open: { type: Boolean, default: false },
    pregnancy: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    previousPregnancies: { type: Array, default: () => [] },
    comparison: { type: Object, default: null },
    paraclinicalHistory: { type: Object, default: null },
});
defineEmits(['update:open']);
</script>

<template>
    <Sheet
        :open="open"
        title="Suivi de la grossesse"
        description="Consultations, comparaison et examens de cette grossesse — en lecture seule."
        @update:open="$emit('update:open', $event)"
    >
        <template #icon>
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300"><History class="h-4.5 w-4.5" /></span>
        </template>
        <div class="space-y-5">
            <PregnancySummaryCard v-if="pregnancy" :pregnancy="pregnancy" />
            <PregnancyHistory :history="history" :previous-pregnancies="previousPregnancies" :comparison="comparison" />
            <section aria-labelledby="pregnancy-paraclinical-title" class="space-y-2">
                <h3 id="pregnancy-paraclinical-title" class="text-sm font-bold text-foreground">Suivi paraclinique</h3>
                <PregnancyParaclinicalHistory :history="paraclinicalHistory" />
            </section>
        </div>
    </Sheet>
</template>
