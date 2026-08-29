<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    episode: { type: Object, required: true },
    billing: { type: Object, default: null },
    capabilities: { type: Object, default: () => ({}) },
});

const statusLabels = { OPEN: 'Ouvert', CLOSED: 'Clos', CANCELLED: 'Annulé' };
const statusBadgeClass = {
    OPEN: 'border-gray-200 text-slate-600 dark:border-gray-800 dark:text-slate-300',
    CLOSED: 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400',
    CANCELLED: 'border-red-200 text-red-600 dark:border-red-900 dark:text-red-300',
};
const administrativeStatusLabels = {
    PENDING_ORIENTATION: 'En attente aux Soins',
    ORIENTED: 'Orienté',
    IN_CARE: 'En cours de soins',
    PENDING_SETTLEMENT: 'En attente de règlement',
    DISCHARGED: 'Sorti',
};
const orientationStatusBadgeClass = {
    PENDING: 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400',
    IN_PROGRESS: 'border-primary-200 text-primary-700 dark:border-primary-900 dark:text-primary-300',
    COMPLETED: 'border-emerald-200 text-emerald-700 dark:border-emerald-900 dark:text-emerald-300',
    CANCELLED: 'border-red-200 text-red-600 dark:border-red-900 dark:text-red-300',
};
const orientationStatusIcon = {
    PENDING: 'clock',
    IN_PROGRESS: 'activity',
    COMPLETED: 'check-circle',
    CANCELLED: 'cross',
};
const orientationIconBgClass = {
    PENDING: 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400',
    IN_PROGRESS: 'bg-primary-50 text-primary-600 dark:bg-primary-950/30 dark:text-primary-300',
    COMPLETED: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300',
    CANCELLED: 'bg-red-50 text-red-600 dark:bg-red-950/20 dark:text-red-300',
};
const diagnosisTypeLabels = { HYPOTHESIS: 'Hypothèse', FINAL: 'Diagnostic final' };
const prescriptionStatusLabels = { ACTIVE: 'Active', CANCELLED: 'Annulée' };
const prescriptionStatusBadgeClass = {
    ACTIVE: 'border-primary-200 text-primary-700 dark:border-primary-900 dark:text-primary-300',
    CANCELLED: 'border-red-200 text-red-600 dark:border-red-900 dark:text-red-300',
};
const billableItemStatusLabels = { PENDING: 'En attente', INVOICED: 'Facturée', CANCELLED: 'Annulée' };
const invoiceStatusLabels = {
    DRAFT: 'Brouillon',
    VALIDATED: 'À payer',
    PARTIALLY_PAID: 'Paiement partiel',
    PAID: 'Payée',
    COVERED: 'Prise en charge',
    CANCELLED: 'Annulée',
};
const invoiceStatusBadgeClass = {
    DRAFT: 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400',
    VALIDATED: 'border-amber-200 text-amber-700 dark:border-amber-900 dark:text-amber-300',
    PARTIALLY_PAID: 'border-orange-200 text-orange-700 dark:border-orange-900 dark:text-orange-300',
    PAID: 'border-green-200 text-green-700 dark:border-green-900 dark:text-green-300',
    COVERED: 'border-emerald-200 text-emerald-700 dark:border-emerald-900 dark:text-emerald-300',
    CANCELLED: 'border-red-200 text-red-600 dark:border-red-900 dark:text-red-300',
};
const moduleLabels = { RECEPTION: 'Réception', CARE: 'Soins', MEDICINE: 'Médecine', LABORATORY: 'Laboratoire', SURGERY: 'Chirurgie', PHARMACY: 'Pharmacie' };

const emergencyContact = computed(() => {
    const episode = props.episode;
    if (!episode.emergency_contact_name && !episode.emergency_contact_phone
        && !episode.emergency_contact_relationship && !episode.emergency_contact_email) return null;

    return episode;
});
const bloodPressure = computed(() => {
    const record = props.episode.care_record;
    if (!record?.blood_pressure_systolic || !record?.blood_pressure_diastolic) return null;

    return `${record.blood_pressure_systolic}/${record.blood_pressure_diastolic} mmHg`;
});
const vitalsRows = computed(() => {
    const record = props.episode.care_record;
    if (!record) return [];

    const rows = [];
    if (record.blood_group) rows.push({ label: 'Groupe sanguin', value: record.blood_group });
    if (bloodPressure.value) rows.push({ label: 'Tension', value: bloodPressure.value });
    if (record.heart_rate !== null && record.heart_rate !== undefined) rows.push({ label: 'FC', value: `${record.heart_rate} bpm` });
    if (record.spo2 !== null && record.spo2 !== undefined) rows.push({ label: 'SpO2', value: `${record.spo2} %` });
    if (record.temperature_celsius) rows.push({ label: 'Température', value: `${record.temperature_celsius} °C` });
    if (record.known_diabetes !== null && record.known_diabetes !== undefined) rows.push({ label: 'Diabète connu', value: record.known_diabetes ? 'Oui' : 'Non' });
    if (record.height_cm) rows.push({ label: 'Taille', value: `${record.height_cm} cm` });
    if (record.weight_kg) rows.push({ label: 'Poids', value: `${record.weight_kg} kg` });
    if (record.bmi) rows.push({ label: 'IMC', value: record.bmi });
    if (record.smoker !== null && record.smoker !== undefined) rows.push({ label: 'Tabac', value: record.smoker ? 'Oui' : 'Non' });

    return rows;
});
</script>

<template>
    <Head :title="`Passage ${episode.episode_number}`" />

    <div class="mx-auto w-full max-w-[1480px] space-y-4">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-start gap-3.5">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"><Icon class="text-xl" name="calendar" /></span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Détail du passage</span>
                        <span :class="['rounded border px-2 py-0.5 text-[11px] font-medium', statusBadgeClass[episode.status] ?? 'border-gray-200 text-slate-600']">{{ statusLabels[episode.status] ?? episode.status }}</span>
                        <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase text-red-600 dark:text-red-300"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Urgence</span>
                    </div>
                    <h1 class="mt-1 truncate font-mono font-heading text-2xl font-bold text-slate-800 dark:text-white">{{ episode.episode_number }}</h1>
                    <p class="mt-0.5 truncate text-xs text-slate-400">
                        <Link :href="`/patients/${episode.patient.uuid}`" class="font-semibold text-primary-600 hover:underline">{{ formatPatientName(episode.patient) }}</Link>
                        <span class="mx-1.5">·</span>{{ episode.patient.patient_number }}
                        <span class="mx-1.5">·</span>Démarré le {{ formatDateTime(episode.started_at) }}
                        <template v-if="episode.ended_at"><span class="mx-1.5">·</span>Clos le {{ formatDateTime(episode.ended_at) }}</template>
                    </p>
                </div>
            </div>
            <Button :as="Link" :href="`/patients/${episode.patient.uuid}?section=episodes`" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Retour au dossier</span></Button>
        </header>

        <section v-if="emergencyContact" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex items-start gap-3 px-5 py-4">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"><Icon name="users" /></span>
                <div class="min-w-0">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Personne à contacter pour ce passage</h2>
                    <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-300">
                        <span v-if="emergencyContact.emergency_contact_name" class="font-semibold text-slate-700 dark:text-white">{{ emergencyContact.emergency_contact_name }}</span>
                        <span v-if="emergencyContact.emergency_contact_relationship">{{ emergencyContact.emergency_contact_relationship }}</span>
                        <span v-if="emergencyContact.emergency_contact_phone"><Icon class="me-1 text-slate-400" name="call" />{{ emergencyContact.emergency_contact_phone }}</span>
                        <span v-if="emergencyContact.emergency_contact_email"><Icon class="me-1 text-slate-400" name="mail" />{{ emergencyContact.emergency_contact_email }}</span>
                    </p>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950 xl:order-2 xl:col-span-1">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Parcours clinique</h2><p class="mt-0.5 text-xs text-slate-400">Orientations créées pour ce passage.</p></div>
            <ul v-if="episode.orientations.length" class="divide-y divide-gray-100 dark:divide-gray-900">
                <li v-for="orientation in episode.orientations" :key="orientation.uuid" class="flex items-start gap-3 px-5 py-3.5">
                    <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full', orientationIconBgClass[orientation.status] ?? 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400']"><Icon class="text-sm" :name="orientationStatusIcon[orientation.status] ?? 'clock'" /></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-bold text-slate-700 dark:text-white">{{ orientation.destination_module_label }}</span>
                            <span :class="['rounded border px-1.5 py-0.5 text-[10px] font-medium', orientationStatusBadgeClass[orientation.status] ?? 'border-gray-200 text-slate-500']">{{ orientation.status_label }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-400">
                            Orienté le {{ formatDateTime(orientation.oriented_at) }}
                            <template v-if="orientation.accepted_at"> · Pris en charge le {{ formatDateTime(orientation.accepted_at) }}</template>
                            <template v-if="orientation.completed_at"> · Terminé le {{ formatDateTime(orientation.completed_at) }}</template>
                        </p>
                    </div>
                </li>
            </ul>
            <p v-else class="px-5 py-6 text-center text-sm text-slate-400">Aucune orientation enregistrée pour ce passage.</p>
        </section>

        <div class="space-y-4 xl:order-1 xl:col-span-2">
        <section v-if="capabilities.can_view_care && episode.care_record" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="user-check" /></span>
                <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Fiche de Soins</h2><p class="mt-0.5 text-xs text-slate-400">Constantes, allergies et actes réalisés pendant ce passage.</p></div>
            </div>
            <dl v-if="vitalsRows.length" class="grid grid-cols-2 gap-x-4 gap-y-3 border-b border-gray-100 px-5 py-4 text-xs sm:grid-cols-3 lg:grid-cols-5 dark:border-gray-900">
                <div v-for="row in vitalsRows" :key="row.label"><dt class="text-slate-400">{{ row.label }}</dt><dd class="mt-0.5 font-semibold text-slate-700 dark:text-slate-200">{{ row.value }}</dd></div>
            </dl>
            <div v-if="episode.care_record.allergy_snapshot?.length" class="flex flex-wrap items-center gap-1.5 border-b border-gray-100 px-5 py-3 text-xs dark:border-gray-900">
                <span class="font-medium text-slate-400">Allergies signalées :</span>
                <span v-for="allergy in episode.care_record.allergy_snapshot" :key="allergy.uuid ?? allergy.substance" class="rounded border border-red-200 bg-red-50 px-1.5 py-0.5 font-semibold text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200">{{ allergy.substance }}</span>
            </div>
            <ul v-if="episode.care_record.procedures.length" class="divide-y divide-gray-100 px-5 dark:divide-gray-900">
                <li v-for="procedure in episode.care_record.procedures" :key="procedure.uuid" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 py-2.5 text-sm">
                    <span class="text-slate-600 dark:text-slate-300"><span class="font-semibold text-slate-700 dark:text-white">{{ procedure.name }}</span> · {{ procedure.quantity }} · {{ procedure.performer ?? '—' }}<span v-if="procedure.notes" class="ms-1 text-xs text-slate-400">({{ procedure.notes }})</span></span>
                    <span class="text-xs text-slate-400">{{ formatDateTime(procedure.performed_at) }}</span>
                </li>
            </ul>
            <p v-else-if="episode.care_record.no_procedure_reason" class="px-5 py-3 text-xs text-slate-500 dark:text-slate-300"><span class="font-semibold">Aucun acte réalisé :</span> {{ episode.care_record.no_procedure_reason }}</p>
            <p v-if="episode.care_record.transmission_reason" class="border-t border-gray-100 px-5 py-3 text-xs leading-5 text-slate-500 dark:border-gray-900 dark:text-slate-300"><span class="font-semibold text-slate-600 dark:text-slate-200">Transmis à Médecine :</span> {{ episode.care_record.transmission_reason }}</p>
        </section>

        <template v-if="capabilities.can_view_medical_record && episode.consultations.length">
        <section v-for="consultation in episode.consultations" :key="consultation.id" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="clipboard" /></span>
                    <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Consultation Médecine</h2><p class="mt-0.5 text-xs text-slate-400">Par {{ consultation.doctor ?? '—' }} · {{ formatDateTime(consultation.consulted_at) }}</p></div>
                </div>
                <span v-if="consultation.decision_label" class="rounded border border-primary-200 px-2 py-0.5 text-[11px] font-medium text-primary-700 dark:border-primary-900 dark:text-primary-300">{{ consultation.decision_label }}</span>
            </div>
            <div class="space-y-3 px-5 py-4 text-sm">
                <p v-if="consultation.reason"><span class="font-semibold text-slate-600 dark:text-slate-200">Motif :</span> <span class="text-slate-600 dark:text-slate-300">{{ consultation.reason }}</span></p>
                <p v-if="consultation.clinical_exam"><span class="font-semibold text-slate-600 dark:text-slate-200">Examen clinique :</span> <span class="text-slate-600 dark:text-slate-300">{{ consultation.clinical_exam }}</span></p>
                <p v-if="consultation.decision_notes"><span class="font-semibold text-slate-600 dark:text-slate-200">Observations :</span> <span class="text-slate-600 dark:text-slate-300">{{ consultation.decision_notes }}</span></p>
            </div>
            <div v-if="capabilities.can_view_diagnoses && consultation.diagnoses.length" class="border-t border-gray-100 px-5 py-4 dark:border-gray-900">
                <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Diagnostics</h3>
                <ul class="mt-2 space-y-2 text-sm">
                    <li v-for="diagnosis in consultation.diagnoses" :key="diagnosis.id" :class="diagnosis.cancelled ? 'opacity-50' : ''">
                        <span class="rounded border border-gray-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:border-gray-800">{{ diagnosisTypeLabels[diagnosis.type] ?? diagnosis.type }}</span>
                        <span :class="['ms-2', diagnosis.cancelled ? 'text-slate-400 line-through' : 'text-slate-700 dark:text-slate-200']">{{ diagnosis.description }}</span>
                        <span class="ms-2 text-xs text-slate-400">{{ diagnosis.recorded_by }} · {{ formatDateTime(diagnosis.created_at) }}</span>
                        <span v-if="diagnosis.cancelled" class="ms-2 text-xs font-medium text-red-500">Annulé par {{ diagnosis.cancelled_by }} le {{ formatDateTime(diagnosis.cancelled_at) }}</span>
                    </li>
                </ul>
            </div>
            <div v-if="capabilities.can_view_prescriptions && consultation.prescriptions.length" class="border-t border-gray-100 px-5 py-4 dark:border-gray-900">
                <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Prescriptions</h3>
                <div v-for="prescription in consultation.prescriptions" :key="prescription.uuid" class="mt-2 rounded border border-gray-200 dark:border-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-900">
                        <span class="text-xs text-slate-400">{{ prescription.prescribed_by }} · {{ formatDateTime(prescription.prescribed_at) }}</span>
                        <span :class="['rounded border px-1.5 py-0.5 text-[10px] font-medium', prescriptionStatusBadgeClass[prescription.status] ?? '']">{{ prescriptionStatusLabels[prescription.status] ?? prescription.status }}</span>
                    </div>
                    <ul class="divide-y divide-gray-100 px-3 dark:divide-gray-900">
                        <li v-for="line in prescription.lines" :key="line.id" class="py-2 text-sm">
                            <span class="font-semibold text-slate-700 dark:text-white">{{ line.medication_name }}</span>
                            <span class="ms-1 text-xs text-slate-400">{{ [line.dosage, line.frequency, line.duration].filter(Boolean).join(' · ') }}</span>
                            <p v-if="line.instructions" class="mt-0.5 text-xs text-slate-400">{{ line.instructions }}</p>
                        </li>
                    </ul>
                    <p v-if="prescription.cancel_reason" class="border-t border-gray-100 px-3 py-2 text-xs text-red-500 dark:border-gray-900">Annulée : {{ prescription.cancel_reason }}</p>
                </div>
            </div>
        </section>
        </template>

        <section v-if="capabilities.can_view_medical_record && episode.medical_discharge" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-lg text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"><Icon name="check-circle" /></span>
                <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Sortie médicale</h2><p class="mt-0.5 text-xs text-slate-400">{{ episode.medical_discharge.type_label }} · {{ formatDateTime(episode.medical_discharge.discharged_at) }}</p></div>
            </div>
            <dl class="space-y-2.5 px-5 py-4 text-sm">
                <div v-if="episode.medical_discharge.final_diagnosis"><dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Diagnostic final</dt><dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ episode.medical_discharge.final_diagnosis }}</dd></div>
                <div v-if="episode.medical_discharge.patient_condition"><dt class="text-xs font-medium uppercase tracking-wide text-slate-400">État du patient</dt><dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ episode.medical_discharge.patient_condition }}</dd></div>
                <div v-if="episode.medical_discharge.recommendations"><dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Recommandations</dt><dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ episode.medical_discharge.recommendations }}</dd></div>
                <div v-if="episode.medical_discharge.transfer_destination"><dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Destination du transfert</dt><dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ episode.medical_discharge.transfer_destination }}</dd></div>
                <div v-if="episode.medical_discharge.follow_up_at"><dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Rendez-vous de suivi</dt><dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ formatDateTime(episode.medical_discharge.follow_up_at) }}</dd></div>
                <div v-if="episode.medical_discharge.observations"><dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Observations</dt><dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ episode.medical_discharge.observations }}</dd></div>
                <template v-if="episode.medical_discharge.type === 'DECEASED'">
                    <div v-if="episode.medical_discharge.death_occurred_at"><dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Décès survenu le</dt><dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ formatDateTime(episode.medical_discharge.death_occurred_at) }}</dd></div>
                    <div v-if="episode.medical_discharge.death_place"><dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Lieu</dt><dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ episode.medical_discharge.death_place }}</dd></div>
                    <div v-if="episode.medical_discharge.death_causes"><dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Causes</dt><dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ episode.medical_discharge.death_causes }}</dd></div>
                </template>
            </dl>
        </section>

        <section v-if="capabilities.can_view_billing && billing" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"><Icon class="text-lg" name="wallet" /></span>
                    <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Facturation de ce passage</h2><p class="mt-0.5 text-xs text-slate-400">Lecture seule — l'encaissement se fait depuis le compte patient.</p></div>
                </div>
                <Button :as="Link" :href="`/patients/${episode.patient.uuid}?section=billing`" size="sm" variant="white-outline">Voir la facturation<Icon class="ms-2" name="arrow-right" /></Button>
            </div>
            <div v-if="billing.items.length" class="overflow-x-auto">
                <table class="w-full min-w-[640px] border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-900">
                            <th class="px-5 py-2.5 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">Désignation</th>
                            <th class="px-5 py-2.5 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">Service</th>
                            <th class="px-5 py-2.5 text-center text-[11px] font-bold uppercase tracking-wide text-slate-400">Qté × Tarif</th>
                            <th class="px-5 py-2.5 text-end text-[11px] font-bold uppercase tracking-wide text-slate-400">Montant</th>
                            <th class="px-5 py-2.5 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">Facture</th>
                            <th class="px-5 py-2.5 text-end text-[11px] font-bold uppercase tracking-wide text-slate-400">État</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="item in billing.items" :key="item.uuid">
                            <td class="px-5 py-3 text-sm font-semibold text-slate-700 dark:text-white">{{ item.description }}</td>
                            <td class="px-5 py-3 text-sm text-slate-500 dark:text-slate-400">{{ moduleLabels[item.source_module] ?? item.source_module }}</td>
                            <td class="px-5 py-3 text-center text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ item.quantity }} × {{ formatMoney(item.unit_price) }}</td>
                            <td class="px-5 py-3 text-end text-sm font-bold tabular-nums text-slate-700 dark:text-white">{{ formatMoney(item.patient_amount) }}</td>
                            <td class="px-5 py-3 text-sm font-mono text-slate-600 dark:text-slate-300">{{ item.invoice?.invoice_number ?? '—' }}</td>
                            <td class="px-5 py-3 text-end">
                                <span v-if="item.invoice" :class="['rounded border px-1.5 py-0.5 text-[10px] font-medium', invoiceStatusBadgeClass[item.invoice.status] ?? '']">{{ invoiceStatusLabels[item.invoice.status] ?? item.invoice.status }}</span>
                                <span v-else class="rounded border border-gray-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:border-gray-800">{{ billableItemStatusLabels[item.status] ?? item.status }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-1 border-t border-gray-200 px-5 py-3 text-xs dark:border-gray-900">
                    <span class="text-slate-400">Montant total <strong class="ms-1 font-bold text-slate-700 dark:text-white">{{ formatMoney(billing.total_amount) }}</strong></span>
                    <span class="text-slate-400">Déjà versé <strong class="ms-1 font-bold text-slate-700 dark:text-white">{{ formatMoney(billing.paid_amount) }}</strong></span>
                    <span class="text-slate-400">Solde dû <strong :class="['ms-1 font-bold', billing.balance_amount > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-white']">{{ formatMoney(billing.balance_amount) }}</strong></span>
                </div>
            </div>
            <p v-else class="px-5 py-4 text-sm text-slate-400">Aucune prestation facturable pour ce passage.</p>
        </section>
        </div>
        </div>
    </div>
</template>
