<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClinicalDocumentPrint from '@/Components/Medicine/ClinicalDocumentPrint.vue';
import { formatDate, formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * L'acte de constatation de décès (ADR-107).
 *
 * Il atteste ce que le médecin a constaté : l'identité du patient, le moment,
 * le lieu et les causes. Il ne prétend rien de plus — ce n'est pas l'acte
 * d'état civil, qui relève de la commune et dont le CDC ne définit ni le
 * numéro, ni le déclarant, ni l'officier. Rien de tout cela n'est inventé ici.
 */
const props = defineProps({
    episode: Object,
    patient: Object,
    record: Object,
});

const identity = computed(() => [
    { label: 'Nom et prénoms', value: `${props.patient.last_name ?? ''} ${props.patient.first_name ?? ''}`.trim() },
    { label: 'Numéro patient', value: props.patient.patient_number },
    { label: 'Sexe', value: props.patient.sex },
    {
        label: 'Né(e) le',
        value: props.patient.birth_date
            ? `${formatDate(props.patient.birth_date)}${props.patient.birth_place ? ` à ${props.patient.birth_place}` : ''}`
            : null,
    },
].filter((line) => Boolean(line.value)));

const constatation = computed(() => [
    { label: 'Date et heure du décès', value: formatDateTime(props.record.death_occurred_at) },
    { label: 'Lieu du décès', value: props.record.death_place },
    { label: 'Causes constatées', value: props.record.death_causes },
    { label: 'Observations', value: props.record.observations },
].filter((line) => Boolean(line.value)));
</script>

<template>
    <ClinicalDocumentPrint
        :title="`Acte de constatation de décès ${episode.episode_number}`"
        document-title="Acte de constatation de décès"
        back-href="/deces"
        back-label="Retour au registre des décès"
        :episode="episode"
        :patient="patient"
        :author="record.constated_by"
        :issued-at="record.constated_at"
    >
        <div v-for="line in identity" :key="line.label" class="rx-block">
            <p class="rx-block-label">{{ line.label }}</p>
            <p class="rx-block-value">{{ line.value }}</p>
        </div>

        <div v-for="line in constatation" :key="line.label" class="rx-block">
            <p class="rx-block-label">{{ line.label }}</p>
            <p class="rx-block-value">{{ line.value }}</p>
        </div>

        <div class="rx-block">
            <p class="rx-block-label">Constaté par</p>
            <p class="rx-block-value">{{ record.constated_by }} — le {{ formatDateTime(record.constated_at) }}</p>
        </div>
    </ClinicalDocumentPrint>
</template>
