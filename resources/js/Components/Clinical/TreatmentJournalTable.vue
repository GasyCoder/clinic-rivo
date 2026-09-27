<script setup>
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';
import { formatDateTime } from '@/utilities/date';

/**
 * La grille « date et heure · description · visa » du journal de traitement
 * (ADR-116), partagée par la feuille d'un passage et par le document qui
 * réunit tous les journaux d'un patient (ADR-118) : deux écrans ne doivent
 * jamais dessiner la même feuille de deux façons.
 *
 * Les couleurs restent écrites en dur : elles décrivent du papier imprimé,
 * jamais un thème d'interface.
 */
defineProps({
    rows: { type: Array, required: true },
    emptyLabel: { type: String, default: 'Aucune ligne enregistrée pour ce passage.' },
});

const sourceBadgeClass = (source) => (source === 'MANUAL' ? 'tjs-source-manual' : 'tjs-source-auto');
</script>

<template>
    <table class="ps-table tjs-grid">
        <thead>
            <tr>
                <th class="tjs-col-date">DATE ET HEURE</th>
                <th>DESCRIPTION</th>
                <th class="tjs-col-visa">VISA PERSONNEL MÉDICAL</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="row in rows" :key="row.key">
                <td>{{ formatDateTime(row.occurred_at) }}</td>
                <td>
                    <span :class="['tjs-source', sourceBadgeClass(row.source)]">{{ row.source_label }}</span>
                    <ClinicalRichTextDisplay v-if="row.description_html" :html="row.description_html" />
                    <span v-else>{{ row.description }}</span>
                </td>
                <td>{{ row.visa }}</td>
            </tr>
            <tr v-if="rows.length === 0">
                <td colspan="3" class="tjs-empty">{{ emptyLabel }}</td>
            </tr>
        </tbody>
    </table>
</template>

<style>
.tjs-grid th {
    background: #bdd7ee;
    font-weight: 700;
    text-align: center;
}

.tjs-col-date {
    width: 16%;
}

.tjs-col-visa {
    width: 18%;
}

.tjs-source {
    display: inline-block;
    margin-bottom: 3px;
    border-radius: 3px;
    padding: 1px 6px;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
}

.tjs-source-manual {
    background: #fef3c7;
    color: #92400e;
}

.tjs-source-auto {
    background: #f1f5f9;
    color: #475569;
}

.tjs-empty {
    text-align: center;
    color: #777;
    padding: 16px;
}

/* Une ligne du journal ne se coupe pas entre deux pages. */
.tjs-grid tr {
    break-inside: avoid;
}
</style>
