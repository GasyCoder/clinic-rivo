<script setup>
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';
import { computed, ref } from 'vue';
import { Activity, ChevronDown, CircleAlert, Eye } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';

const props = defineProps({
    careSummary: { type: Object, required: true },
    defaultOpen: { type: Boolean, default: false },
    /**
     * Set when the panel sits in a narrow column. Tailwind 3 has no
     * container queries here, so the caller states its context explicitly
     * rather than the component guessing from the viewport — which is what
     * truncated every label to "FRÉQ. CARDIAQ" in a 400px sidebar.
     */
    dense: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});

const open = ref(props.defaultOpen);

/** "175.00" reads as a measurement precision nobody took: 175. */
const trimDecimals = (value) => {
    if (value === null || value === undefined || value === '') return null;

    const numeric = Number(value);

    return Number.isFinite(numeric) ? String(numeric) : String(value);
};

const allergies = computed(() => (props.careSummary.allergy_snapshot ?? [])
    .map((allergy) => typeof allergy === 'string' ? allergy : allergy?.substance)
    .filter(Boolean));

/**
 * Only what was actually measured, each carrying the screening assessment
 * already computed server-side by CareRecordReadModel (ADR-038 to ADR-041).
 * Médecine must see an abnormal value exactly as Soins does — showing
 * "170/120 mmHg" as plain text hid a red flag from the doctor.
 */
const vitals = computed(() => {
    const summary = props.careSummary;
    const systolic = summary.blood_pressure_systolic;
    const diastolic = summary.blood_pressure_diastolic;
    const rows = [];

    if (systolic && diastolic) {
        rows.push({
            label: 'Tension',
            value: `${systolic}/${diastolic}`,
            unit: 'mmHg',
            assessment: summary.blood_pressure_assessment,
        });
    }
    if (summary.heart_rate) {
        rows.push({ label: 'Fréq. cardiaque', value: summary.heart_rate, unit: 'bpm', assessment: summary.heart_rate_assessment });
    }
    if (summary.spo2 !== null && summary.spo2 !== undefined) {
        rows.push({ label: 'SpO₂', value: summary.spo2, unit: '%', assessment: summary.spo2_assessment });
    }
    if (summary.temperature_celsius) {
        rows.push({ label: 'Température', value: trimDecimals(summary.temperature_celsius), unit: '°C', assessment: summary.temperature_assessment });
    }
    if (summary.bmi) {
        rows.push({ label: 'IMC', value: trimDecimals(summary.bmi), unit: null, assessment: summary.bmi_assessment });
    }
    if (summary.weight_kg) {
        rows.push({ label: 'Poids', value: trimDecimals(summary.weight_kg), unit: 'kg', assessment: null });
    }
    if (summary.height_cm) {
        rows.push({ label: 'Taille', value: trimDecimals(summary.height_cm), unit: 'cm', assessment: null });
    }
    if (summary.blood_group) {
        rows.push({ label: 'Groupe sanguin', value: summary.blood_group, unit: null, assessment: null });
    }
    if (summary.known_diabetes !== null && summary.known_diabetes !== undefined) {
        rows.push({
            label: 'Diabète connu',
            value: summary.known_diabetes ? 'Oui' : 'Non',
            unit: null,
            assessment: null,
            note: summary.known_diabetes ? summary.diabetes_note : null,
        });
    }

    return rows;
});

/** Named plainly rather than as a wall of dashes. */
const notMeasured = computed(() => {
    const summary = props.careSummary;
    const missing = [];

    if (!summary.blood_pressure_systolic || !summary.blood_pressure_diastolic) missing.push('tension');
    if (!summary.heart_rate) missing.push('fréquence cardiaque');
    if (summary.spo2 === null || summary.spo2 === undefined) missing.push('SpO₂');
    if (!summary.temperature_celsius) missing.push('température');
    if (!summary.weight_kg) missing.push('poids');
    if (!summary.height_cm) missing.push('taille');
    if (!summary.blood_group) missing.push('groupe sanguin');

    return missing;
});

/** Abnormal values, surfaced above the grid so they cannot be scrolled past. */
const vitalAlerts = computed(() => vitals.value
    .filter((vital) => vital.assessment && vital.assessment.tone !== 'success')
    .map((vital) => ({
        tone: vital.assessment.tone === 'danger' ? 'danger' : 'warning',
        text: `${vital.label} ${vital.value}${vital.unit ? ` ${vital.unit}` : ''} — ${vital.assessment.label}`,
        message: vital.assessment.message,
    })));

const vitalCellClasses = (assessment) => {
    if (assessment?.tone === 'danger') {
        return 'border-red-200 bg-red-50/70 dark:border-red-900 dark:bg-red-950/20';
    }
    if (assessment && assessment.tone !== 'success') {
        return 'border-amber-200 bg-amber-50/70 dark:border-amber-900 dark:bg-amber-950/20';
    }

    return 'border-border bg-card';
};
const vitalValueClasses = (assessment) => {
    if (assessment?.tone === 'danger') return 'text-red-700 dark:text-red-300';
    if (assessment && assessment.tone !== 'success') return 'text-amber-800 dark:text-amber-200';

    return 'text-foreground';
};
</script>

<template>
    <!-- No grid-span baked in: a caller that isn't a 12-column grid (the
         Médecine "Contexte clinique" modal is a plain flex column) would
         either ignore an unmatched span or, worse, need an `!important`
         override to fight it — exactly what silently squeezed this panel's
         height there. The caller states its own grid context via `class`. -->
    <section :class="['overflow-hidden rounded-lg border border-border bg-card', compact ? '' : 'shadow-sm']">
        <button
            type="button"
            :class="['flex w-full items-center text-start transition-colors hover:bg-muted/35', compact ? 'gap-2.5 px-3 py-2.5' : 'gap-3 px-4 py-3 sm:px-5']"
            :aria-expanded="open"
            aria-controls="care-summary-content"
            @click="open = !open"
        >
            <span :class="['flex shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary', compact ? 'size-7 text-sm' : 'size-9']"><Activity class="h-4 w-4" aria-hidden="true" /></span>
            <span class="min-w-0 flex-1">
                <span class="flex flex-wrap items-center gap-2">
                    <strong :class="[compact ? 'text-xs' : 'text-sm', 'text-foreground']">Synthèse transmise par les Soins</strong>
                    <span class="inline-flex items-center gap-1 rounded border border-border bg-muted/35 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-muted-foreground"><Eye class="h-4 w-4" aria-hidden="true" /> Lecture seule</span>
                </span>
                <span v-if="!compact" class="mt-0.5 block text-xs leading-5 text-muted-foreground">Constantes, allergies, actes et transmission sont repris du même passage ; aucune ressaisie ici.</span>
            </span>
            <span class="hidden text-xs font-semibold text-primary sm:block">{{ open ? 'Replier' : 'Consulter' }}</span>
            <ChevronDown :class="['h-4 w-4 text-muted-foreground transition-transform', open ? 'rotate-180' : '']" aria-hidden="true" />
        </button>

        <div v-show="open" id="care-summary-content" :class="['border-t border-border bg-muted/35', compact ? 'p-3' : 'p-4 sm:p-5']">
            <div v-if="careSummary.can_view_vitals">
                <ul v-if="vitalAlerts.length" :class="[compact ? 'mb-2 space-y-1' : 'mb-3 space-y-1.5']">
                    <li
                        v-for="alert in vitalAlerts"
                        :key="alert.text"
                        :class="['flex items-start gap-2 rounded border-s-2 pe-2.5 ps-2.5 text-[11px] leading-4', compact ? 'py-1' : 'py-1.5', alert.tone === 'danger'
                            ? 'border-s-red-500 bg-red-50/70 text-red-800 dark:bg-red-950/20 dark:text-red-300'
                            : 'border-s-amber-500 bg-amber-50/70 text-amber-900 dark:bg-amber-950/20 dark:text-amber-200']"
                        :title="alert.message"
                    >
                        <CircleAlert class="h-4 w-4" aria-hidden="true" />
                        <span><strong class="font-bold">{{ alert.text }}</strong> — à recontrôler et interpréter avec le contexte clinique.</span>
                    </li>
                </ul>

                <!-- A container-aware grid: this panel also lives in a
                     narrow sidebar, where nine fixed columns truncated every
                     label to "TENSIO ARTÉRIE". -->
                <!-- Tailwind 3 has no container queries here, and this panel
                     is full width in Chirurgie but a ~500px sidebar in
                     Médecine: three columns is the widest that keeps every
                     label readable in both. -->
                <div v-if="vitals.length" :class="['grid gap-2', dense ? 'grid-cols-2' : (compact ? 'grid-cols-2 sm:grid-cols-4 xl:grid-cols-6' : 'grid-cols-2 sm:grid-cols-3 2xl:grid-cols-4')]">
                    <div v-for="vital in vitals" :key="vital.label" :class="['rounded border', compact ? 'px-2.5 py-1.5' : 'px-3 py-2', vitalCellClasses(vital.assessment)]">
                        <span class="block truncate text-[10px] font-bold uppercase tracking-wide text-muted-foreground" :title="vital.label">{{ vital.label }}</span>
                        <strong :class="['mt-0.5 block leading-5', compact ? 'text-xs' : 'text-sm', vitalValueClasses(vital.assessment)]">
                            {{ vital.value }}<span v-if="vital.unit" class="ms-0.5 text-[11px] font-medium opacity-70">{{ vital.unit }}</span>
                        </strong>
                        <span v-if="vital.note" class="mt-0.5 block truncate text-[10px] text-muted-foreground" :title="vital.note">{{ vital.note }}</span>
                    </div>
                </div>
                <p v-else class="rounded border border-dashed border-border px-3 py-3 text-center text-[11px] text-muted-foreground">
                    Aucune constante relevée pendant ce passage.
                </p>

                <p v-if="vitals.length && notMeasured.length" class="mt-2 text-[10px] leading-4 text-muted-foreground">
                    Non relevé : {{ notMeasured.join(', ') }}.
                </p>
            </div>

            <div :class="['grid gap-3', compact ? 'mt-3 md:grid-cols-3' : 'mt-4', dense ? '' : (compact ? '' : 'gap-4 lg:grid-cols-3')]">
                <!-- Red only when there is actually an allergy: tinting
                     "aucune allergie signalée" in red cried wolf. -->
                <div v-if="careSummary.can_view_allergies" :class="['rounded border', compact ? 'p-2.5' : 'p-3', allergies.length
                    ? 'border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/15'
                    : 'border-border']">
                    <h3 :class="['flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wide', allergies.length ? 'text-red-600 dark:text-red-300' : 'text-muted-foreground']">
                        <CircleAlert v-if="allergies.length" class="h-3.5 w-3.5" aria-hidden="true" />Allergies / vigilance
                    </h3>
                    <p v-if="allergies.length" :class="[compact ? 'mt-1 text-xs' : 'mt-2 text-sm', 'font-semibold text-red-700 dark:text-red-300']">{{ allergies.join(' · ') }}</p>
                    <p v-else :class="[compact ? 'mt-1 text-xs' : 'mt-2 text-sm', 'text-muted-foreground']">Aucune allergie signalée pendant ce passage.</p>
                    <p v-if="careSummary.allergy_note" class="mt-1 text-xs text-muted-foreground">{{ careSummary.allergy_note }}</p>
                </div>
                <div :class="['rounded border border-border', compact ? 'p-2.5' : 'p-3']">
                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Transmission Soins</h3>
                    <ClinicalRichTextDisplay v-if="careSummary.transmission_reason_html || careSummary.diagnostic_note_html" :html="careSummary.transmission_reason_html || careSummary.diagnostic_note_html" :class="[compact ? 'mt-1 text-xs leading-4' : 'mt-2 text-sm', 'text-muted-foreground']" />
                    <p v-else :class="[compact ? 'mt-1 text-xs leading-4' : 'mt-2 text-sm', 'text-muted-foreground']">Aucune transmission renseignée.</p>
                </div>
                <div :class="['rounded border border-border', compact ? 'p-2.5' : 'p-3']">
                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Traçabilité</h3>
                    <p :class="[compact ? 'mt-1 text-xs' : 'mt-2 text-sm', 'text-muted-foreground']">{{ careSummary.updated_by || careSummary.created_by || '—' }}</p>
                    <p :class="[compact ? 'mt-0.5 text-[10px]' : 'mt-1 text-xs', 'text-muted-foreground']">Mise à jour : {{ formatDateTime(careSummary.updated_at) || '—' }}</p>
                </div>
            </div>

            <div v-if="careSummary.procedures?.length || !compact" :class="['overflow-hidden rounded border border-border', compact ? 'mt-3' : 'mt-4']">
                <div :class="['flex items-center justify-between border-b border-border', compact ? 'px-3 py-2' : 'px-4 py-2.5']">
                    <h3 class="text-xs font-bold text-foreground">Actes réalisés aux Soins</h3>
                    <span class="rounded bg-muted px-2 py-1 text-[10px] font-bold text-muted-foreground">{{ careSummary.procedures?.length ?? 0 }}</span>
                </div>
                <div v-if="careSummary.procedures?.length" class="overflow-x-auto">
                    <table :class="['w-full border-collapse', dense ? 'min-w-[420px]' : (compact ? 'min-w-[580px]' : 'min-w-[680px]')]">
                        <thead class="bg-muted/35"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Acte</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Qté</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Observation</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Réalisation</th></tr></thead>
                        <tbody class="divide-y divide-border"><tr v-for="procedure in careSummary.procedures" :key="procedure.uuid"><td class="px-4 py-3"><strong class="block text-xs text-foreground">{{ procedure.name }}</strong><small class="font-mono text-[10px] text-muted-foreground">{{ procedure.code }}</small></td><td class="px-4 py-3 text-xs text-muted-foreground">{{ procedure.quantity }}</td><td class="px-4 py-3 text-xs text-muted-foreground">{{ procedure.notes || '—' }}</td><td class="px-4 py-3 text-end text-xs text-muted-foreground">{{ procedure.performed_by || '—' }}<small class="mt-0.5 block text-[10px] text-muted-foreground">{{ formatDateTime(procedure.performed_at) }}</small></td></tr></tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-5 text-center text-xs text-muted-foreground">Aucun acte réalisé n’est enregistré dans la fiche Soins.</p>
            </div>
            <p v-else class="mt-2 text-[10px] text-muted-foreground">Aucun acte réalisé aux Soins pour ce passage.</p>
        </div>
    </section>
</template>
