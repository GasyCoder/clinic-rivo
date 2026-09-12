<script setup>
import { computed, ref } from 'vue';
import Icon from '@/Components/UI/Icon.vue';
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

    return 'border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950';
};
const vitalValueClasses = (assessment) => {
    if (assessment?.tone === 'danger') return 'text-red-700 dark:text-red-300';
    if (assessment && assessment.tone !== 'success') return 'text-amber-800 dark:text-amber-200';

    return 'text-slate-700 dark:text-white';
};
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-blue-200 bg-blue-50/50 shadow-sm dark:border-blue-950 dark:bg-blue-950/15 xl:col-span-12">
        <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-start sm:px-5"
            :aria-expanded="open"
            aria-controls="care-summary-content"
            @click="open = !open"
        >
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300"><Icon name="activity" /></span>
            <span class="min-w-0 flex-1">
                <span class="flex flex-wrap items-center gap-2">
                    <strong class="text-sm text-slate-700 dark:text-white">Informations déjà saisies aux Soins</strong>
                    <span class="inline-flex items-center gap-1 rounded bg-white px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-blue-700 shadow-sm dark:bg-gray-950 dark:text-blue-300"><Icon name="eye" /> Lecture seule</span>
                </span>
                <span class="mt-0.5 block text-xs leading-5 text-slate-500">Constantes, allergies, actes et transmission sont repris du même passage ; aucune ressaisie ici.</span>
            </span>
            <span class="hidden text-xs font-semibold text-blue-700 sm:block dark:text-blue-300">{{ open ? 'Masquer' : 'Consulter' }}</span>
            <Icon :class="['text-slate-400 transition-transform', open ? 'rotate-180' : '']" name="chevron-down" />
        </button>

        <div v-show="open" id="care-summary-content" class="border-t border-blue-100 bg-white/80 p-4 dark:border-blue-950 dark:bg-gray-950/50 sm:p-5">
            <div v-if="careSummary.can_view_vitals">
                <ul v-if="vitalAlerts.length" class="mb-3 space-y-1.5">
                    <li
                        v-for="alert in vitalAlerts"
                        :key="alert.text"
                        :class="['flex items-start gap-2 rounded border-s-2 py-1.5 pe-2.5 ps-2.5 text-[11px] leading-4', alert.tone === 'danger'
                            ? 'border-s-red-500 bg-red-50/70 text-red-800 dark:bg-red-950/20 dark:text-red-300'
                            : 'border-s-amber-500 bg-amber-50/70 text-amber-900 dark:bg-amber-950/20 dark:text-amber-200']"
                        :title="alert.message"
                    >
                        <Icon name="alert-circle" class="mt-px shrink-0 text-xs" />
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
                <div v-if="vitals.length" :class="['grid gap-2', dense ? 'grid-cols-2' : 'grid-cols-2 sm:grid-cols-3 2xl:grid-cols-4']">
                    <div v-for="vital in vitals" :key="vital.label" :class="['rounded border px-3 py-2', vitalCellClasses(vital.assessment)]">
                        <span class="block truncate text-[10px] font-bold uppercase tracking-wide text-slate-400" :title="vital.label">{{ vital.label }}</span>
                        <strong :class="['mt-0.5 block text-sm leading-5', vitalValueClasses(vital.assessment)]">
                            {{ vital.value }}<span v-if="vital.unit" class="ms-0.5 text-[11px] font-medium opacity-70">{{ vital.unit }}</span>
                        </strong>
                        <span v-if="vital.note" class="mt-0.5 block truncate text-[10px] text-slate-400" :title="vital.note">{{ vital.note }}</span>
                    </div>
                </div>
                <p v-else class="rounded border border-dashed border-gray-200 px-3 py-3 text-center text-[11px] text-slate-400 dark:border-gray-800">
                    Aucune constante relevée pendant ce passage.
                </p>

                <p v-if="vitals.length && notMeasured.length" class="mt-2 text-[10px] leading-4 text-slate-400">
                    Non relevé : {{ notMeasured.join(', ') }}.
                </p>
            </div>

            <div :class="['mt-4 grid gap-3', dense ? '' : 'gap-4 lg:grid-cols-3']">
                <!-- Red only when there is actually an allergy: tinting
                     "aucune allergie signalée" in red cried wolf. -->
                <div v-if="careSummary.can_view_allergies" :class="['rounded border p-3', allergies.length
                    ? 'border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/15'
                    : 'border-gray-200 dark:border-gray-900']">
                    <h3 :class="['flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wide', allergies.length ? 'text-red-600 dark:text-red-300' : 'text-slate-400']">
                        <Icon v-if="allergies.length" name="alert-circle" class="text-xs" />Allergies / vigilance
                    </h3>
                    <p v-if="allergies.length" class="mt-2 text-sm font-semibold text-red-700 dark:text-red-300">{{ allergies.join(' · ') }}</p>
                    <p v-else class="mt-2 text-sm text-slate-500 dark:text-slate-400">Aucune allergie signalée pendant ce passage.</p>
                    <p v-if="careSummary.allergy_note" class="mt-1 text-xs text-slate-400">{{ careSummary.allergy_note }}</p>
                </div>
                <div class="rounded border border-gray-200 p-3 dark:border-gray-900">
                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Transmission Soins</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ careSummary.transmission_reason || careSummary.diagnostic_note || 'Aucune transmission renseignée.' }}</p>
                </div>
                <div class="rounded border border-gray-200 p-3 dark:border-gray-900">
                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Traçabilité</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ careSummary.updated_by || careSummary.created_by || '—' }}</p>
                    <p class="mt-1 text-xs text-slate-400">Mise à jour : {{ formatDateTime(careSummary.updated_at) || '—' }}</p>
                </div>
            </div>

            <div class="mt-4 overflow-hidden rounded border border-gray-200 dark:border-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2.5 dark:border-gray-900">
                    <h3 class="text-xs font-bold text-slate-700 dark:text-white">Actes réalisés aux Soins</h3>
                    <span class="rounded bg-gray-100 px-2 py-1 text-[10px] font-bold text-slate-500 dark:bg-gray-900">{{ careSummary.procedures?.length ?? 0 }}</span>
                </div>
                <div v-if="careSummary.procedures?.length" class="overflow-x-auto">
                    <table :class="['w-full border-collapse', dense ? 'min-w-[420px]' : 'min-w-[680px]']">
                        <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Acte</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Qté</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Observation</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Réalisation</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-900"><tr v-for="procedure in careSummary.procedures" :key="procedure.uuid"><td class="px-4 py-3"><strong class="block text-xs text-slate-700 dark:text-white">{{ procedure.name }}</strong><small class="font-mono text-[10px] text-slate-400">{{ procedure.code }}</small></td><td class="px-4 py-3 text-xs text-slate-500">{{ procedure.quantity }}</td><td class="px-4 py-3 text-xs text-slate-500">{{ procedure.notes || '—' }}</td><td class="px-4 py-3 text-end text-xs text-slate-500">{{ procedure.performed_by || '—' }}<small class="mt-0.5 block text-[10px] text-slate-400">{{ formatDateTime(procedure.performed_at) }}</small></td></tr></tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-5 text-center text-xs text-slate-400">Aucun acte réalisé n’est enregistré dans la fiche Soins.</p>
            </div>
        </div>
    </section>
</template>
