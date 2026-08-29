<script setup>
import { computed, ref } from 'vue';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';
import RadioButton from '@/Components/UI/RadioButton.vue';
import CareSummaryReadOnly from '@/Components/Surgery/CareSummaryReadOnly.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    orientation: Object,
    care_record: Object,
    allergies: Array,
    antecedents: Array,
    consultation: Object,
    medical_discharge: Object,
    options: Object,
    capabilities: Object,
    current_step: String,
});

const episode = computed(() => props.orientation.episode);
const patient = computed(() => episode.value.patient);
const isEmergency = computed(() => episode.value.priority === 'EMERGENCY');
const careRecordBloodPressure = computed(() => {
    const systolic = props.care_record?.blood_pressure_systolic;
    const diastolic = props.care_record?.blood_pressure_diastolic;
    return systolic && diastolic ? `${systolic}/${diastolic}` : null;
});

const antecedentForm = useForm({ description: '' });
const submitAntecedent = () => antecedentForm.post(`/patients/${patient.value.uuid}/antecedents`, {
    preserveScroll: true,
    onSuccess: () => antecedentForm.reset(),
});
const isClosed = computed(() => Boolean(props.medical_discharge));
const consultationRecorded = computed(() => Boolean(
    props.consultation?.reason?.trim()
    || props.consultation?.clinical_exam?.trim()
    || props.consultation?.decision,
));
const diagnosisRecorded = computed(() => (props.consultation?.diagnoses ?? [])
    .some((diagnosis) => !diagnosis.cancelled));
const diagnosisHistoryOpen = ref(false);
const activeDiagnoses = computed(() => (props.consultation?.diagnoses ?? [])
    .filter((diagnosis) => !diagnosis.cancelled));
const archivedDiagnoses = computed(() => (props.consultation?.diagnoses ?? [])
    .filter((diagnosis) => diagnosis.cancelled));
const visibleDiagnoses = computed(() => diagnosisHistoryOpen.value
    ? [...activeDiagnoses.value, ...archivedDiagnoses.value]
    : activeDiagnoses.value);
const prescriptionRecorded = computed(() => (props.consultation?.prescriptions ?? []).length > 0);
const wizardSteps = computed(() => [
    { key: 'dossier', label: 'Dossier', hint: 'Contexte', complete: true },
    { key: 'consultation', label: 'Consultation', hint: 'Examen', complete: consultationRecorded.value },
    { key: 'diagnostic', label: 'Diagnostic', hint: 'Conclusion', complete: diagnosisRecorded.value },
    { key: 'ordonnance', label: 'Ordonnance', hint: 'Facultatif', complete: prescriptionRecorded.value },
    { key: 'decision', label: 'Décision', hint: 'Sortie', complete: isClosed.value },
]);
const currentStepIndex = computed(() => Math.max(0, wizardSteps.value.findIndex((step) => step.key === props.current_step)));
const previousStep = computed(() => wizardSteps.value[currentStepIndex.value - 1] ?? null);
const nextStep = computed(() => wizardSteps.value[currentStepIndex.value + 1] ?? null);
const stepUrl = (step) => `/medicine/orientations/${props.orientation.uuid}/${step}`;
const selectClass = 'block h-9 w-full appearance-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000';
const textareaClass = 'block w-full resize-y rounded border border-gray-200 bg-white px-3 py-2 text-sm leading-5 text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000';

const toLocalDateTimeInput = (value = new Date()) => {
    const date = value instanceof Date ? value : new Date(value);
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
};

const consultationForm = useForm({
    reason: props.consultation?.reason ?? '',
    clinical_exam: props.consultation?.clinical_exam ?? '',
    decision: props.consultation?.decision ?? '',
    decision_notes: props.consultation?.decision_notes ?? '',
});

const saveConsultation = () => consultationForm.put(
    `/medicine/orientations/${props.orientation.uuid}/consultation`,
    {
        preserveScroll: true,
        onSuccess: () => router.visit(stepUrl('diagnostic')),
    },
);

const diagnosisForm = useForm({ type: 'HYPOTHESIS', description: '' });
const addDiagnosis = () => diagnosisForm.post(
    `/medicine/orientations/${props.orientation.uuid}/diagnoses`,
    { preserveScroll: true, onSuccess: () => diagnosisForm.reset('description') },
);

const diagnosisCancellationForm = useForm({ diagnosis_id: null });
const diagnosisToCancel = ref(null);
const openDiagnosisCancellation = (diagnosis) => {
    diagnosisCancellationForm.clearErrors();
    diagnosisToCancel.value = diagnosis;
};
const closeDiagnosisCancellation = () => {
    if (diagnosisCancellationForm.processing) return;

    diagnosisToCancel.value = null;
    diagnosisCancellationForm.clearErrors();
    diagnosisCancellationForm.reset();
};
const cancelDiagnosis = () => {
    if (!diagnosisToCancel.value) return;

    diagnosisCancellationForm.diagnosis_id = diagnosisToCancel.value.id;
    diagnosisCancellationForm.post(
        `/medicine/orientations/${props.orientation.uuid}/diagnoses/cancel`,
        {
            preserveScroll: true,
            onSuccess: () => {
                diagnosisToCancel.value = null;
            },
            onFinish: () => diagnosisCancellationForm.reset(),
        },
    );
};

const editingDiagnosisId = ref(null);
const diagnosisEditForm = useForm({ diagnosis_id: null, type: 'HYPOTHESIS', description: '' });
const startDiagnosisEdit = (diagnosis) => {
    editingDiagnosisId.value = diagnosis.id;
    diagnosisEditForm.clearErrors();
    diagnosisEditForm.diagnosis_id = diagnosis.id;
    diagnosisEditForm.type = diagnosis.type;
    diagnosisEditForm.description = diagnosis.description;
};
const closeDiagnosisEdit = () => {
    editingDiagnosisId.value = null;
    diagnosisEditForm.reset();
};
const updateDiagnosis = () => diagnosisEditForm.put(
    `/medicine/orientations/${props.orientation.uuid}/diagnoses`,
    { preserveScroll: true, onSuccess: closeDiagnosisEdit },
);

const medicineSearch = ref('');
const medicines = computed(() => props.options?.medicines ?? []);
const prescriptionForm = useForm({ lines: [] });
const selectedMedicineUuids = computed(() => new Set(
    prescriptionForm.lines.map((line) => line.medicine_uuid),
));
const filteredMedicines = computed(() => {
    const query = medicineSearch.value.trim().toLocaleLowerCase('fr');

    if (!query) return medicines.value;

    return medicines.value.filter((medicine) => [
        medicine.name,
        medicine.generic_name,
        medicine.code,
        medicine.strength,
        medicine.form_label,
    ].filter(Boolean).some((value) => value.toLocaleLowerCase('fr').includes(query)));
});
let prescriptionLineSequence = 0;
const emptyPrescriptionLine = (medicine) => ({
    _key: `catalog-${++prescriptionLineSequence}`,
    manual: false,
    medicine_uuid: medicine.uuid,
    quantity: 1,
    dosage: '',
    frequency: '',
    duration: '',
    instructions: '',
});
const emptyManualPrescriptionLine = () => ({
    _key: `manual-${++prescriptionLineSequence}`,
    manual: true,
    medication_name: '',
    quantity: 1,
    dosage: '',
    frequency: '',
    duration: '',
    instructions: '',
});
const medicineForLine = (line) => medicines.value.find((medicine) => medicine.uuid === line.medicine_uuid);
const addPrescriptionMedicine = (medicine) => {
    if (!medicine.available || selectedMedicineUuids.value.has(medicine.uuid)) return;

    prescriptionForm.lines.push(emptyPrescriptionLine(medicine));
};
const addManualPrescriptionLine = () => {
    prescriptionForm.lines.push(emptyManualPrescriptionLine());
};
const removePrescriptionLine = (index) => {
    prescriptionForm.lines.splice(index, 1);
};
const hasPosology = (line) => line.dosage.trim().length > 0 && line.frequency.trim().length > 0;
const prescriptionStockIsValid = computed(() => prescriptionForm.lines.length > 0
    && prescriptionForm.lines.every((line) => {
        if (!hasPosology(line)) return false;

        if (line.manual) {
            return line.medication_name.trim().length > 0 && Number(line.quantity) >= 1;
        }

        const medicine = medicineForLine(line);

        return medicine?.available && Number(line.quantity) >= 1
            && Number(line.quantity) <= medicine.available_quantity;
    }));
const addPrescription = () => prescriptionForm
    .transform((data) => ({
        lines: data.lines.map((line) => (line.manual
            ? {
                manual: true,
                medication_name: line.medication_name,
                quantity: line.quantity,
                dosage: line.dosage,
                frequency: line.frequency,
                duration: line.duration,
                instructions: line.instructions,
            }
            : {
                manual: false,
                medicine_uuid: line.medicine_uuid,
                quantity: line.quantity,
                dosage: line.dosage,
                frequency: line.frequency,
                duration: line.duration,
                instructions: line.instructions,
            })),
    }))
    .post(
        `/medicine/orientations/${props.orientation.uuid}/prescriptions`,
        {
            preserveScroll: true,
            onSuccess: () => {
                prescriptionForm.reset();
                prescriptionForm.lines = [];
            },
        },
    );

const editingPrescriptionUuid = ref(null);
const prescriptionEditForm = useForm({ lines: [] });
const startPrescriptionEdit = (prescription) => {
    editingPrescriptionUuid.value = prescription.uuid;
    prescriptionEditForm.clearErrors();
    prescriptionEditForm.lines = prescription.lines.map((line) => {
        const medicine = medicines.value.find((item) => item.uuid === line.medicine_uuid);

        return {
            id: line.id,
            medication_name: line.medication_name,
            unit: line.unit,
            is_manual_entry: Boolean(line.is_manual_entry),
            catalog_review_status: line.catalog_review_status,
            stock_linked: Boolean(medicine),
            available_quantity: medicine
                ? medicine.available_quantity + Number(line.quantity)
                : Number(line.quantity),
            quantity: Number(line.quantity),
            dosage: line.dosage ?? '',
            frequency: line.frequency ?? '',
            duration: line.duration ?? '',
            instructions: line.instructions ?? '',
        };
    });
};
const closePrescriptionEdit = () => {
    editingPrescriptionUuid.value = null;
    prescriptionEditForm.clearErrors();
    prescriptionEditForm.reset();
};
const prescriptionEditStockIsValid = computed(() => prescriptionEditForm.lines.length > 0
    && prescriptionEditForm.lines.every((line) => Number(line.quantity) >= 1
        && hasPosology(line)
        && (line.is_manual_entry || Number(line.quantity) <= line.available_quantity)
        && (!line.is_manual_entry || line.medication_name.trim().length > 0)));
const updatePrescription = (uuid) => prescriptionEditForm
    .transform((data) => ({
        lines: data.lines.map((line) => ({
            id: line.id,
            quantity: line.quantity,
            medication_name: line.is_manual_entry ? line.medication_name : undefined,
            dosage: line.dosage,
            frequency: line.frequency,
            duration: line.duration,
            instructions: line.instructions,
        })),
    }))
    .put(
        `/medicine/orientations/${props.orientation.uuid}/prescriptions/${uuid}`,
        { preserveScroll: true, onSuccess: closePrescriptionEdit },
    );

const prescriptionToRemove = ref(null);
const prescriptionRemovalForm = useForm({
    reason: 'Ordonnance retirée depuis le dossier Médecine.',
});
const openPrescriptionRemoval = (prescription) => {
    prescriptionToRemove.value = prescription;
    prescriptionRemovalForm.clearErrors();
};
const closePrescriptionRemoval = () => {
    if (prescriptionRemovalForm.processing) return;

    prescriptionToRemove.value = null;
    prescriptionRemovalForm.clearErrors();
};
const removePrescription = () => {
    if (!prescriptionToRemove.value) return;

    prescriptionRemovalForm.post(
        `/medicine/orientations/${props.orientation.uuid}/prescriptions/${prescriptionToRemove.value.uuid}/cancel`,
        {
            preserveScroll: true,
            onSuccess: () => {
                prescriptionToRemove.value = null;
            },
        },
    );
};

const latestFinalDiagnosis = computed(() => [...(props.consultation?.diagnoses ?? [])]
    .reverse()
    .find((diagnosis) => diagnosis.type === 'FINAL' && !diagnosis.cancelled)?.description ?? '');
const activePrescriptionSummary = computed(() => (props.consultation?.prescriptions ?? [])
    .flatMap((prescription) => prescription.lines)
    .map((line) => [line.medication_name, line.dosage, line.frequency, line.duration].filter(Boolean).join(' — '))
    .join('\n'));

const dischargeForm = useForm({
    type: 'NORMAL',
    final_diagnosis: latestFinalDiagnosis.value,
    patient_condition: '',
    discharge_prescription: activePrescriptionSummary.value,
    recommendations: '',
    follow_up_at: '',
    observations: '',
    transfer_destination: '',
    death_occurred_at: '',
    death_place: '',
    death_causes: '',
    discharged_at: toLocalDateTimeInput(),
});
const showDischargeForm = ref(props.current_step === 'decision' && !props.medical_discharge);
const submitDischarge = () => dischargeForm.post(
    `/medicine/orientations/${props.orientation.uuid}/discharge`,
    { preserveScroll: true },
);

const splitLines = (value) => (value ?? '').split('\n').map((line) => line.trim()).filter(Boolean);

const patientAge = computed(() => patient.value.age !== null && patient.value.age !== undefined
    ? `${patient.value.age} ans${patient.value.birth_date_is_approximate ? ' (déclaré)' : ''}`
    : 'N/R');
const birthLabel = computed(() => patient.value.birth_date && !patient.value.birth_date_is_approximate
    ? formatDate(patient.value.birth_date)
    : patientAge.value);
const hasEmergencyContact = computed(() => Object.values(episode.value.emergency_contact ?? {}).some(Boolean));
</script>

<template>
    <Head :title="`Consultation ${episode.episode_number}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <Card class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <Avatar rounded size="md" variant="slate-pale" :text="formatPatientInitials(patient)" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="truncate font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ formatPatientName(patient) }}</h1>
                            <span v-if="isEmergency" class="inline-flex items-center gap-1 rounded border border-red-200 bg-red-50 px-2 py-1 text-xs font-bold text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"><span class="h-1.5 w-1.5 rounded-full bg-red-500" />Urgence</span>
                            <span v-else class="inline-flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-2 py-1 text-xs font-semibold text-slate-500 dark:border-gray-800 dark:bg-gray-1000"><span class="h-1.5 w-1.5 rounded-full bg-slate-300" />Normal</span>
                            <span :class="['inline-flex items-center gap-1 rounded border px-2 py-1 text-xs font-semibold', isClosed ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' : 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/30 dark:text-primary-300']"><span :class="['h-1.5 w-1.5 rounded-full', isClosed ? 'bg-emerald-500' : 'bg-primary-500']" />{{ isClosed ? medical_discharge.type_label : 'En consultation' }}</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                            <span>PASSAGE <strong class="font-mono text-slate-600 dark:text-slate-300">{{ episode.episode_number }}</strong></span>
                            <span>PATIENT <strong class="font-mono text-slate-600 dark:text-slate-300">{{ patient.patient_number }}</strong></span>
                            <span>Pris en charge {{ formatDateTime(orientation.accepted_at) }}<template v-if="orientation.accepted_by"> par {{ orientation.accepted_by }}</template></span>
                        </div>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <Button :as="Link" href="/medicine" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Retour à la file</Button>
                    <Button :as="Link" :href="`/patients/${patient.uuid}`" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="user" />Dossier patient</Button>
                </div>
            </div>

            <div class="grid border-t border-gray-200 bg-gray-50/50 dark:border-gray-900 dark:bg-gray-1000/30 sm:grid-cols-2 lg:grid-cols-4">
                <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-900 sm:border-e lg:border-b-0"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Naissance / âge</p><p class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ birthLabel }}</p></div>
                <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-900 lg:border-b-0 lg:border-e"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Sexe</p><p class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ patient.sex_label }}</p></div>
                <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-900 sm:border-e sm:border-b-0"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Téléphone</p><p class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ patient.phone || 'N/R' }}</p></div>
                <div class="px-5 py-3"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Origine du parcours</p><p class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ orientation.source_label }}</p></div>
            </div>
        </Card>

        <Card class="overflow-hidden shadow-sm">
            <nav aria-label="Étapes de la consultation" class="overflow-x-auto">
                <ol class="flex min-w-[760px] items-stretch divide-x divide-gray-200 dark:divide-gray-900">
                    <li v-for="(step, index) in wizardSteps" :key="step.key" class="min-w-0 flex-1">
                        <Link
                            :href="stepUrl(step.key)"
                            :aria-current="current_step === step.key ? 'step' : undefined"
                            :class="[
                                'flex min-h-16 items-center gap-3 px-4 py-3 transition-colors',
                                current_step === step.key
                                    ? 'bg-primary-50/70 text-primary-700 dark:bg-primary-950/20 dark:text-primary-300'
                                    : 'bg-white text-slate-500 hover:bg-gray-50 dark:bg-gray-950 dark:text-slate-300 dark:hover:bg-gray-1000',
                            ]"
                        >
                            <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-xs font-bold', current_step === step.key ? 'border-primary-500 bg-primary-500 text-white' : step.complete ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' : 'border-gray-200 bg-white text-slate-400 dark:border-gray-800 dark:bg-gray-950']">
                                <Icon v-if="step.complete" name="check" />
                                <span v-else>{{ index + 1 }}</span>
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-bold">{{ step.label }}</span>
                                <span class="mt-0.5 block truncate text-[11px] font-normal text-slate-400">{{ step.hint }}</span>
                            </span>
                        </Link>
                    </li>
                </ol>
            </nav>
        </Card>

        <Card v-if="['consultation', 'diagnostic', 'decision'].includes(current_step)" class="w-full overflow-hidden shadow-sm">
            <div class="grid divide-y divide-gray-200 dark:divide-gray-900 sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
                <div class="flex items-center gap-3 px-4 py-3">
                    <span :class="['h-2 w-2 shrink-0 rounded-full', isEmergency ? 'bg-red-500' : 'bg-slate-300']" />
                    <div><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Priorité</p><p :class="['mt-0.5 text-sm font-bold', isEmergency ? 'text-red-600 dark:text-red-300' : 'text-slate-700 dark:text-white']">{{ isEmergency ? 'Urgence' : 'Normale' }}</p></div>
                </div>
                <div class="px-4 py-3"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Tension artérielle</p><p class="mt-0.5 text-sm font-bold text-slate-700 dark:text-white">{{ careRecordBloodPressure || 'N/R' }}<span v-if="careRecordBloodPressure" class="ms-1 text-xs font-normal text-slate-400">mmHg</span></p></div>
                <div class="px-4 py-3"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Température</p><p class="mt-0.5 text-sm font-bold text-slate-700 dark:text-white">{{ care_record?.temperature_celsius ? `${care_record.temperature_celsius} °C` : 'N/R' }}</p></div>
                <div class="px-4 py-3"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Allergies connues</p><p :class="['mt-0.5 truncate text-sm font-bold', allergies.length ? 'text-red-600 dark:text-red-300' : 'text-slate-700 dark:text-white']">{{ allergies.length ? allergies.map((allergy) => allergy.substance).join(', ') : 'Aucune enregistrée' }}</p></div>
            </div>
        </Card>

        <div v-if="current_step === 'dossier' && episode.designations.length" class="flex flex-wrap items-center gap-2 rounded border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-900 dark:bg-gray-950">
            <span class="me-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Demandes</span>
            <span v-for="designation in episode.designations" :key="designation.uuid" class="rounded border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-200">{{ designation.description }}</span>
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-12">
            <main :class="['space-y-4', current_step === 'dossier' ? 'xl:col-span-8' : 'xl:col-span-12']">
                <Card v-if="current_step === 'dossier'" class="overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="file-text" /></span>
                            <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Contexte de la consultation</h2><p class="mt-1 text-xs text-slate-400">Vérifiez le parcours et les informations utiles avant l’examen médical.</p></div>
                        </div>
                    </div>
                    <div class="grid gap-px bg-gray-200 dark:bg-gray-900 sm:grid-cols-2">
                        <div class="bg-white p-5 dark:bg-gray-950">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Besoin exprimé</p>
                            <div v-if="episode.designations.length" class="mt-3 flex flex-wrap gap-2"><span v-for="designation in episode.designations" :key="designation.uuid" class="rounded border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs font-semibold text-slate-700 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-200">{{ designation.description }}</span></div>
                            <p v-else class="mt-2 text-sm text-slate-500">Motif à préciser pendant la consultation.</p>
                        </div>
                        <div class="bg-white p-5 dark:bg-gray-950">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Parcours</p>
                            <dl class="mt-3 space-y-2 text-sm"><div class="flex items-center justify-between gap-4"><dt class="text-slate-400">Origine</dt><dd class="font-semibold text-slate-700 dark:text-white">{{ orientation.source_label }}</dd></div><div class="flex items-center justify-between gap-4"><dt class="text-slate-400">Priorité</dt><dd :class="['font-semibold', isEmergency ? 'text-red-600 dark:text-red-300' : 'text-slate-700 dark:text-white']">{{ isEmergency ? 'Urgence' : 'Normale' }}</dd></div><div class="flex items-center justify-between gap-4"><dt class="text-slate-400">Médecin</dt><dd class="font-semibold text-slate-700 dark:text-white">{{ consultation.doctor }}</dd></div></dl>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 border-t border-gray-200 bg-gray-50/50 px-5 py-3 dark:border-gray-900 dark:bg-gray-1000/30 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-slate-400">Les données Soins et les repères permanents restent en lecture seule.</p>
                        <Button :as="Link" :href="stepUrl('consultation')" size="rg">Consultation<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                    </div>
                </Card>

                <Card v-if="current_step === 'consultation'" class="w-full overflow-hidden shadow-sm">
                    <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Consultation médicale</h2><p class="mt-1 text-xs text-slate-400">Motif, examen clinique et conduite retenue pour ce passage.</p></div>
                        <span class="text-xs text-slate-400">Dr {{ consultation.doctor }}</span>
                    </div>
                    <form class="space-y-4 p-5" @submit.prevent="saveConsultation">
                        <div>
                            <label for="reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif de consultation *</label>
                            <textarea id="reason" v-model="consultationForm.reason" rows="2" :disabled="!capabilities.can_update_consultation" :class="textareaClass" placeholder="Symptômes, demande ou raison clinique" />
                            <FormError :message="consultationForm.errors.reason" />
                        </div>
                        <div>
                            <label for="clinical_exam" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Examen clinique</label>
                            <textarea id="clinical_exam" v-model="consultationForm.clinical_exam" rows="5" :disabled="!capabilities.can_update_consultation" :class="textareaClass" placeholder="Constatations cliniques utiles, signes positifs et négatifs" />
                            <FormError :message="consultationForm.errors.clinical_exam" />
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="decision" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Conduite envisagée</label>
                                <span class="relative block"><select id="decision" v-model="consultationForm.decision" :disabled="!capabilities.can_update_consultation" :class="selectClass"><option value="">À définir</option><option v-for="option in options.decisions" :key="option.value" :value="option.value">{{ option.label }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span>
                                <FormError :message="consultationForm.errors.decision" />
                            </div>
                            <div>
                                <label for="decision_notes" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Précision</label>
                                <Input id="decision_notes" v-model="consultationForm.decision_notes" :disabled="!capabilities.can_update_consultation" placeholder="Service, examen, surveillance…" />
                                <FormError :message="consultationForm.errors.decision_notes" />
                            </div>
                        </div>
                        <div class="flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                            <Button :as="Link" :href="stepUrl('dossier')" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Dossier</Button>
                            <div class="flex flex-col items-stretch gap-2 sm:items-end">
                                <p v-if="capabilities.can_update_consultation" class="text-xs text-slate-400">La consultation sera enregistrée avant l’ouverture du diagnostic.</p>
                                <Button v-if="capabilities.can_update_consultation" type="submit" size="rg" :disabled="consultationForm.processing"><Icon class="me-2 text-lg" name="check" />Enregistrer et continuer<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                                <Button v-else :as="Link" :href="stepUrl('diagnostic')" size="rg">Diagnostic<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                            </div>
                        </div>
                        <FormError :message="consultationForm.errors.consultation" />
                    </form>
                </Card>

                <Card v-if="current_step === 'diagnostic'" class="w-full overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Diagnostic</h2><p class="mt-1 text-xs text-slate-400">Seules les versions actives sont affichées. Les rectifications restent accessibles dans l’historique.</p></div>
                    <div v-if="visibleDiagnoses.length" class="overflow-x-auto">
                        <table class="w-full min-w-[760px] table-fixed text-sm">
                            <caption class="sr-only">Hypothèses et diagnostics enregistrés pendant cette consultation</caption>
                            <thead class="border-b border-gray-200 bg-gray-50/80 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:border-gray-900 dark:bg-gray-1000/40">
                                <tr>
                                    <th class="w-56 px-5 py-2.5">Type</th>
                                    <th class="px-5 py-2.5">Diagnostic</th>
                                    <th class="w-60 px-5 py-2.5">Statut</th>
                                    <th class="w-28 px-5 py-2.5 text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                                <template v-for="diagnosis in visibleDiagnoses" :key="diagnosis.id">
                                    <tr :class="diagnosis.cancelled ? 'bg-gray-50/70 dark:bg-gray-1000/30' : 'bg-white dark:bg-gray-950'">
                                        <td class="px-5 py-3 align-top">
                                            <span :class="['inline-flex rounded px-2 py-1 text-[10px] font-bold uppercase tracking-wide', diagnosis.cancelled ? 'bg-gray-200 text-slate-500 dark:bg-gray-800 dark:text-slate-300' : diagnosis.type === 'FINAL' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300']">{{ diagnosis.type_label }}</span>
                                        </td>
                                        <td class="px-5 py-3 align-top">
                                            <p :class="['font-semibold leading-6', diagnosis.cancelled ? 'text-slate-400 line-through' : 'text-slate-700 dark:text-white']">{{ diagnosis.description }}</p>
                                        </td>
                                        <td class="px-5 py-3 align-top">
                                            <span v-if="!diagnosis.cancelled" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Actif</span>
                                            <div v-else class="text-xs text-slate-500">
                                                <span class="font-semibold text-slate-600 dark:text-slate-300">{{ diagnosis.correction ? 'Rectifié' : 'Annulé' }}</span>
                                                <span class="mt-1 block">{{ diagnosis.cancelled_by }} · {{ formatDateTime(diagnosis.cancelled_at) }}</span>
                                            </div>
                                        </td>
                                        <td class="px-5 py-3 align-top">
                                            <div v-if="diagnosis.can_edit || diagnosis.can_cancel" class="flex justify-end gap-1.5">
                                                <Button v-if="diagnosis.can_edit" type="button" size="sm" icon variant="white-outline" title="Modifier ce diagnostic" :aria-label="`Modifier le diagnostic ${diagnosis.description}`" @click="startDiagnosisEdit(diagnosis)"><Icon class="text-base" name="edit" /></Button>
                                                <Button v-if="diagnosis.can_cancel" type="button" size="sm" icon variant="danger-outline" title="Annuler ce diagnostic" :aria-label="`Annuler le diagnostic ${diagnosis.description}`" :disabled="diagnosisCancellationForm.processing" @click="openDiagnosisCancellation(diagnosis)"><Icon class="text-base" name="trash" /></Button>
                                            </div>
                                            <span v-else class="block text-end text-slate-300">—</span>
                                        </td>
                                    </tr>
                                    <tr v-if="editingDiagnosisId === diagnosis.id" class="bg-gray-50 dark:bg-gray-1000/50">
                                        <td colspan="4" class="px-5 py-3">
                                            <form @submit.prevent="updateDiagnosis">
                                                <div class="grid gap-2 sm:grid-cols-[auto_minmax(0,1fr)_auto_auto]">
                                                    <fieldset class="flex h-9 items-center gap-1" aria-label="Type du diagnostic rectifié">
                                                        <RadioButton v-for="option in options.diagnosis_types" :id="`diagnosis-edit-type-${diagnosis.id}-${option.value}`" :key="option.value" v-model="diagnosisEditForm.type" :value="option.value" :name="`diagnosis-edit-type-${diagnosis.id}`" nocontrol>{{ option.label }}</RadioButton>
                                                    </fieldset>
                                                    <Input v-model="diagnosisEditForm.description" />
                                                    <Button type="submit" size="rg" class="!h-9 !px-4 !py-0" :disabled="diagnosisEditForm.processing"><Icon class="me-1.5 text-base" name="save" />Enregistrer</Button>
                                                    <Button type="button" size="rg" class="!h-9 !px-4 !py-0" variant="white-outline" @click="closeDiagnosisEdit"><Icon class="me-1.5 text-base" name="cross" />Fermer</Button>
                                                </div>
                                                <FormError :message="diagnosisEditForm.errors.description || diagnosisEditForm.errors.type || diagnosisEditForm.errors.diagnosis_id" />
                                            </form>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="px-5 py-6 text-sm text-slate-400">Aucune hypothèse ni diagnostic actif.</p>
                    <button v-if="archivedDiagnoses.length" type="button" class="flex w-full items-center justify-between gap-3 border-t border-gray-200 bg-gray-50/60 px-5 py-3 text-left transition-colors hover:bg-gray-100 dark:border-gray-900 dark:bg-gray-1000/30 dark:hover:bg-gray-1000/60" :aria-expanded="diagnosisHistoryOpen" @click="diagnosisHistoryOpen = !diagnosisHistoryOpen">
                        <span class="flex min-w-0 items-center gap-2.5">
                            <Icon class="shrink-0 text-base text-slate-400" name="history" />
                            <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ diagnosisHistoryOpen ? 'Masquer' : 'Afficher' }} l’historique des rectifications</span>
                            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-bold text-slate-500 dark:bg-gray-800 dark:text-slate-300">{{ archivedDiagnoses.length }}</span>
                        </span>
                        <Icon :class="['shrink-0 text-base text-slate-400 transition-transform', diagnosisHistoryOpen ? 'rotate-180' : '']" name="chevron-down" />
                    </button>
                    <FormError class="px-5" :message="diagnosisCancellationForm.errors.diagnosis_id" />
                    <form v-if="capabilities.can_create_diagnosis" class="border-t border-gray-200 bg-gray-50/50 p-5 dark:border-gray-900 dark:bg-gray-1000/30" @submit.prevent="addDiagnosis">
                        <div class="grid gap-3 sm:grid-cols-[auto_minmax(0,1fr)_auto]">
                            <fieldset class="flex h-9 items-center gap-1" aria-label="Type du diagnostic">
                                <RadioButton v-for="option in options.diagnosis_types" :id="`diagnosis-type-${option.value}`" :key="option.value" v-model="diagnosisForm.type" :value="option.value" name="diagnosis-type" nocontrol>{{ option.label }}</RadioButton>
                            </fieldset>
                            <Input v-model="diagnosisForm.description" placeholder="Saisissez le diagnostic" />
                            <Button type="submit" size="rg" class="!h-9 !px-4 !py-0" :disabled="diagnosisForm.processing"><Icon class="me-1.5 text-base" name="plus" />Ajouter</Button>
                        </div>
                        <FormError :message="diagnosisForm.errors.description || diagnosisForm.errors.type" />
                    </form>
                </Card>

                <Card v-if="current_step === 'ordonnance'" class="w-full overflow-hidden shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-sm font-bold text-slate-700 dark:text-white">Ordonnances</h2>
                            <p class="mt-1 text-xs text-slate-400">Le stock disponible exclut les lots périmés et les quantités déjà réservées.</p>
                        </div>
                        <span v-if="capabilities.can_view_pharmacy_availability" class="inline-flex w-fit items-center gap-1.5 rounded border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs font-semibold text-slate-500 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Stock Pharmacie en lecture seule
                        </span>
                    </div>
                    <div v-if="consultation.prescriptions.length" class="overflow-x-auto">
                        <table class="w-full min-w-[980px] text-sm">
                            <thead class="border-b border-gray-200 bg-gray-50/70 dark:border-gray-900 dark:bg-gray-1000/40">
                                <tr class="text-left text-[10px] uppercase tracking-wide text-slate-400">
                                    <th class="px-5 py-2.5 font-semibold">Médicament</th>
                                    <th class="px-4 py-2.5 font-semibold">Quantité</th>
                                    <th class="px-4 py-2.5 font-semibold">Dose</th>
                                    <th class="px-4 py-2.5 font-semibold">Fréquence</th>
                                    <th class="px-4 py-2.5 font-semibold">Durée</th>
                                    <th class="px-4 py-2.5 font-semibold">Enregistrée</th>
                                    <th class="px-5 py-2.5 text-end font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                                <template v-for="prescription in consultation.prescriptions" :key="prescription.uuid">
                                    <tr v-for="(line, lineIndex) in prescription.lines" :key="line.id" class="align-top hover:bg-gray-50/60 dark:hover:bg-gray-1000/30">
                                        <td class="px-5 py-3">
                                            <span class="flex flex-wrap items-center gap-1.5">
                                                <span class="font-semibold text-slate-700 dark:text-white">{{ line.medication_name }}</span>
                                                <span v-if="line.is_manual_entry && line.catalog_review_status === 'PENDING'" class="inline-flex items-center gap-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300"><Icon class="text-xs" name="alert-circle" />Hors référentiel</span>
                                                <span v-else-if="line.is_manual_entry" class="inline-flex items-center gap-1 rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300"><Icon class="text-xs" name="check" />Traité par la Pharmacie</span>
                                            </span>
                                            <span v-if="line.instructions" class="mt-0.5 block text-xs text-slate-400">{{ line.instructions }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">{{ line.quantity }} {{ line.unit || 'unité(s)' }}</td>
                                        <td class="px-4 py-3 text-slate-500">{{ line.dosage || '—' }}</td>
                                        <td class="px-4 py-3 text-slate-500">{{ line.frequency || '—' }}</td>
                                        <td class="px-4 py-3 text-slate-500">{{ line.duration || '—' }}</td>
                                        <td v-if="lineIndex === 0" :rowspan="prescription.lines.length" class="whitespace-nowrap px-4 py-3 text-xs text-slate-400">{{ formatDateTime(prescription.prescribed_at) }}</td>
                                        <td v-if="lineIndex === 0" :rowspan="prescription.lines.length" class="px-5 py-3">
                                            <div class="flex justify-end gap-1.5">
                                                <Button :as="Link" :href="`/medicine/orientations/${orientation.uuid}/prescriptions/${prescription.uuid}/print`" target="_blank" size="sm" icon variant="white-outline" title="Imprimer l’ordonnance" aria-label="Imprimer l’ordonnance"><Icon class="text-base" name="printer" /></Button>
                                                <Button v-if="prescription.can_edit" type="button" size="sm" icon variant="white-outline" title="Modifier l’ordonnance" aria-label="Modifier l’ordonnance" @click="startPrescriptionEdit(prescription)"><Icon class="text-base" name="edit" /></Button>
                                                <Button v-if="prescription.can_remove" type="button" size="sm" icon variant="danger-outline" title="Retirer l’ordonnance" aria-label="Retirer l’ordonnance" @click="openPrescriptionRemoval(prescription)"><Icon class="text-base" name="trash" /></Button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="editingPrescriptionUuid === prescription.uuid" class="bg-gray-50/80 dark:bg-gray-1000/40">
                                        <td colspan="7" class="px-5 py-4">
                                            <form class="space-y-3" @submit.prevent="updatePrescription(prescription.uuid)">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <div><p class="text-xs font-bold text-slate-700 dark:text-white">Modifier l’ordonnance</p><p class="mt-0.5 text-[11px] text-slate-400">Le médicament reste inchangé ; quantité et posologie peuvent être corrigées.</p></div>
                                                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded text-slate-400 hover:bg-gray-200 hover:text-slate-700 dark:hover:bg-gray-800 dark:hover:text-white" aria-label="Fermer la modification" @click="closePrescriptionEdit"><Icon class="text-lg" name="cross" /></button>
                                                </div>
                                                <div v-for="(editLine, editIndex) in prescriptionEditForm.lines" :key="editLine.id" class="grid gap-3 rounded border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-950 md:grid-cols-12">
                                                    <div v-if="editLine.is_manual_entry" class="md:col-span-3"><label :for="`edit-name-${editLine.id}`" class="mb-1 block text-xs font-medium text-slate-500">Médicament (hors référentiel)</label><Input :id="`edit-name-${editLine.id}`" v-model="editLine.medication_name" /></div>
                                                    <div v-else class="md:col-span-3"><p class="text-xs font-semibold text-slate-700 dark:text-white">{{ editLine.medication_name }}</p><p class="mt-1 text-[11px] text-slate-400">Stock mobilisable : {{ editLine.available_quantity }} {{ editLine.unit || 'unité(s)' }}</p></div>
                                                    <div class="md:col-span-2"><label :for="`edit-quantity-${editLine.id}`" class="mb-1 block text-xs font-medium text-slate-500">Quantité</label><Input :id="`edit-quantity-${editLine.id}`" v-model.number="editLine.quantity" type="number" min="1" :max="editLine.is_manual_entry ? undefined : editLine.available_quantity" :disabled="!editLine.stock_linked && !editLine.is_manual_entry" /></div>
                                                    <div class="md:col-span-2"><label :for="`edit-dosage-${editLine.id}`" class="mb-1 block text-xs font-medium text-slate-500">Dose *</label><Input :id="`edit-dosage-${editLine.id}`" v-model="editLine.dosage" placeholder="Ex. 500 mg" /></div>
                                                    <div class="md:col-span-2"><label :for="`edit-frequency-${editLine.id}`" class="mb-1 block text-xs font-medium text-slate-500">Fréquence *</label><Input :id="`edit-frequency-${editLine.id}`" v-model="editLine.frequency" placeholder="Ex. 3×/jour" /></div>
                                                    <div class="md:col-span-3"><label :for="`edit-duration-${editLine.id}`" class="mb-1 block text-xs font-medium text-slate-500">Durée</label><Input :id="`edit-duration-${editLine.id}`" v-model="editLine.duration" /></div>
                                                    <div class="md:col-span-12"><label :for="`edit-instructions-${editLine.id}`" class="mb-1 block text-xs font-medium text-slate-500">Instructions</label><Input :id="`edit-instructions-${editLine.id}`" v-model="editLine.instructions" placeholder="Voie, moment de prise ou précaution" /></div>
                                                    <FormError class="md:col-span-12" :message="prescriptionEditForm.errors[`lines.${editIndex}.quantity`] || prescriptionEditForm.errors[`lines.${editIndex}.dosage`] || prescriptionEditForm.errors[`lines.${editIndex}.frequency`]" />
                                                </div>
                                                <FormError :message="prescriptionEditForm.errors.lines || prescriptionEditForm.errors.prescription" />
                                                <div class="flex justify-end gap-2"><Button type="button" size="rg" variant="white-outline" :disabled="prescriptionEditForm.processing" @click="closePrescriptionEdit"><Icon class="me-1.5 text-base" name="cross" />Fermer</Button><Button type="submit" size="rg" :disabled="prescriptionEditForm.processing || !prescriptionEditStockIsValid"><Icon class="me-1.5 text-base" name="save" />Enregistrer</Button></div>
                                            </form>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="px-5 py-6 text-sm text-slate-400">Aucune ordonnance active.</p>

                    <form v-if="capabilities.can_create_prescription" class="border-t border-gray-200 bg-gray-50/50 p-5 dark:border-gray-900 dark:bg-gray-1000/30" @submit.prevent="addPrescription">
                        <div class="mb-4">
                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">Nouvelle ordonnance</h3>
                            <p class="mt-1 text-xs text-slate-400">Sélectionnez uniquement un médicament réellement disponible. La validation réserve la quantité ; la délivrance reste à la Pharmacie.</p>
                        </div>

                        <div class="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(520px,1.1fr)]">
                            <section class="overflow-hidden rounded border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950" aria-labelledby="medicine-catalog-title">
                                <div class="border-b border-gray-200 p-3 dark:border-gray-800">
                                    <div class="mb-1.5 flex items-center justify-between gap-2">
                                        <label id="medicine-catalog-title" for="medicine-search" class="block text-xs font-bold text-slate-600 dark:text-slate-300">Médicaments disponibles</label>
                                        <button type="button" class="shrink-0 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400" @click="addManualPrescriptionLine"><Icon class="me-1 text-sm" name="plus" />Médicament introuvable ?</button>
                                    </div>
                                    <div class="relative">
                                        <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-base text-slate-400" name="search" />
                                        <Input id="medicine-search" v-model="medicineSearch" class="ps-9" placeholder="Nom, DCI, dosage ou code…" autocomplete="off" />
                                    </div>
                                </div>

                                <div v-if="filteredMedicines.length" class="max-h-[430px] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-900">
                                    <button
                                        v-for="medicine in filteredMedicines"
                                        :key="medicine.uuid"
                                        type="button"
                                        :disabled="!medicine.available || selectedMedicineUuids.has(medicine.uuid)"
                                        class="flex w-full items-center gap-3 px-4 py-3 text-start transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:bg-gray-50/70 dark:hover:bg-gray-1000 dark:disabled:bg-gray-1000/40"
                                        @click="addPrescriptionMedicine(medicine)"
                                    >
                                        <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded border text-base', medicine.available ? 'border-gray-200 bg-white text-primary-600 dark:border-gray-800 dark:bg-gray-950' : 'border-red-100 bg-red-50 text-red-500 dark:border-red-950 dark:bg-red-950/20']"><Icon :name="medicine.available ? 'plus' : 'cross'" /></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <strong class="truncate text-sm text-slate-700 dark:text-white">{{ medicine.name }}</strong>
                                                <span v-if="medicine.strength" class="text-xs text-slate-400">{{ medicine.strength }}</span>
                                            </span>
                                            <span class="mt-0.5 block truncate text-xs text-slate-400">{{ medicine.generic_name || medicine.form_label }} · {{ medicine.code }}</span>
                                        </span>
                                        <span class="shrink-0 text-end">
                                            <strong :class="['block text-sm', medicine.available ? 'text-slate-700 dark:text-white' : 'text-red-600 dark:text-red-300']">{{ medicine.available_quantity }} {{ medicine.unit }}</strong>
                                            <span v-if="!medicine.available" class="text-[11px] font-semibold text-red-600 dark:text-red-300">Épuisé</span>
                                            <span v-else-if="medicine.nearest_expiration" :class="['text-[11px]', medicine.expiring_soon ? 'font-semibold text-amber-700 dark:text-amber-300' : 'text-slate-400']">Péremption {{ formatDate(medicine.nearest_expiration) }}</span>
                                        </span>
                                    </button>
                                </div>
                                <div v-else class="px-4 py-8 text-center">
                                    <Icon class="text-2xl text-slate-300" name="search" />
                                    <p class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-300">Aucun médicament trouvé</p>
                                    <p class="mt-1 text-xs text-slate-400">Vérifiez la recherche ou le stock Pharmacie.</p>
                                </div>
                            </section>

                            <section class="overflow-hidden rounded border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950" aria-labelledby="prescription-selection-title">
                                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                                    <div>
                                        <h4 id="prescription-selection-title" class="text-xs font-bold text-slate-600 dark:text-slate-300">Prescription en préparation</h4>
                                        <p class="mt-0.5 text-[11px] text-slate-400">{{ prescriptionForm.lines.length }} médicament(s)</p>
                                    </div>
                                    <Icon class="text-xl text-slate-400" name="file-text" />
                                </div>

                                <div v-if="prescriptionForm.lines.length" class="max-h-[560px] space-y-3 overflow-y-auto p-3">
                                    <div v-for="(line, index) in prescriptionForm.lines" :key="line._key" class="rounded border border-gray-200 p-3 dark:border-gray-800">
                                        <div class="mb-3 flex items-start justify-between gap-3">
                                            <div v-if="line.manual" class="min-w-0 flex-1">
                                                <label :for="`manual-name-${index}`" class="mb-1 flex items-center gap-1.5 text-[11px] font-semibold text-amber-700 dark:text-amber-300"><Icon class="text-xs" name="alert-circle" />Hors référentiel Pharmacie</label>
                                                <Input :id="`manual-name-${index}`" v-model="line.medication_name" placeholder="Nom du médicament" />
                                            </div>
                                            <div v-else class="min-w-0">
                                                <p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ medicineForLine(line)?.name }}</p>
                                                <p class="mt-0.5 text-xs text-slate-400">{{ medicineForLine(line)?.form_label }}<template v-if="medicineForLine(line)?.strength"> · {{ medicineForLine(line)?.strength }}</template></p>
                                            </div>
                                            <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded border border-red-100 text-red-600 hover:bg-red-50 dark:border-red-950 dark:text-red-300 dark:hover:bg-red-950/20" title="Retirer" @click="removePrescriptionLine(index)"><Icon name="trash" /></button>
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-12">
                                            <div class="sm:col-span-3">
                                                <label :for="`quantity-${index}`" class="mb-1 block text-xs font-medium text-slate-500">Quantité *</label>
                                                <Input :id="`quantity-${index}`" v-model.number="line.quantity" type="number" min="1" :max="line.manual ? undefined : medicineForLine(line)?.available_quantity" />
                                                <p v-if="!line.manual" class="mt-1 text-[11px] text-slate-400">Disponible : {{ medicineForLine(line)?.available_quantity }} {{ medicineForLine(line)?.unit }}</p>
                                                <p v-else class="mt-1 text-[11px] text-slate-400">Sans lien de stock</p>
                                            </div>
                                            <div class="sm:col-span-3"><label :for="`dosage-${index}`" class="mb-1 block text-xs font-medium text-slate-500">Dose *</label><Input :id="`dosage-${index}`" v-model="line.dosage" placeholder="Ex. 500 mg" /></div>
                                            <div class="sm:col-span-3"><label :for="`frequency-${index}`" class="mb-1 block text-xs font-medium text-slate-500">Fréquence *</label><Input :id="`frequency-${index}`" v-model="line.frequency" placeholder="Ex. 3×/jour" /></div>
                                            <div class="sm:col-span-3"><label :for="`duration-${index}`" class="mb-1 block text-xs font-medium text-slate-500">Durée</label><Input :id="`duration-${index}`" v-model="line.duration" placeholder="Ex. 5 jours" /></div>
                                            <div class="sm:col-span-12"><label :for="`instructions-${index}`" class="mb-1 block text-xs font-medium text-slate-500">Instructions</label><Input :id="`instructions-${index}`" v-model="line.instructions" placeholder="Voie, moment de prise ou précaution utile" /></div>
                                        </div>
                                        <p v-if="line.manual" class="mt-2 flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300"><Icon name="info" />Sera transmis pour validation avant d’entrer en stock Pharmacie. Aucun prix n’est demandé ici.</p>
                                        <p v-else-if="Number(line.quantity) > medicineForLine(line)?.available_quantity" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-red-600 dark:text-red-300"><Icon name="info" />Quantité supérieure au stock disponible. L’ordonnance sera bloquée.</p>
                                        <FormError :message="prescriptionForm.errors[`lines.${index}.medicine_uuid`] || prescriptionForm.errors[`lines.${index}.medication_name`] || prescriptionForm.errors[`lines.${index}.quantity`] || prescriptionForm.errors[`lines.${index}.dosage`] || prescriptionForm.errors[`lines.${index}.frequency`]" />
                                    </div>
                                </div>
                                <div v-else class="flex min-h-48 flex-col items-center justify-center px-6 py-8 text-center">
                                    <Icon class="text-3xl text-slate-300" name="file-text" />
                                    <p class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-300">Ordonnance vide</p>
                                    <p class="mt-1 max-w-xs text-xs text-slate-400">Choisissez un médicament dans la liste. Les produits épuisés ne peuvent pas être ajoutés.</p>
                                </div>
                            </section>
                        </div>

                        <div class="mt-4 flex flex-col gap-2 border-t border-gray-200 pt-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs text-slate-400">Le contrôle final est refait par le serveur pour éviter toute réservation concurrente.</p>
                            <Button type="submit" size="rg" :disabled="prescriptionForm.processing || !prescriptionStockIsValid"><Icon class="me-2 text-lg" name="file-text" />Valider et réserver</Button>
                        </div>
                        <FormError :message="prescriptionForm.errors.lines" />
                    </form>
                    <div v-else-if="!capabilities.can_view_pharmacy_availability" class="border-t border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">Votre compte ne dispose pas du droit de consulter la disponibilité Pharmacie. Aucune ordonnance ne peut être créée depuis cet écran.</div>
                </Card>

                <Card v-if="current_step === 'decision'" class="w-full overflow-hidden shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Conclusion de la prise en charge</h2><p class="mt-1 text-xs text-slate-400">La décision médicale ne réalise aucun encaissement et ne ferme pas le passage administratif.</p></div>
                        <Button v-if="capabilities.can_discharge && !showDischargeForm" type="button" size="rg" @click="showDischargeForm = true"><Icon class="me-2 text-lg" name="check-circle" />Prononcer la sortie médicale</Button>
                    </div>

                    <div v-if="medical_discharge" class="p-5">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded border border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/30">
                            <div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><Icon class="text-lg" name="check-circle" /></span>
                                <div>
                                    <span class="rounded bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">{{ medical_discharge.type_label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-400">Décidée le {{ formatDateTime(medical_discharge.discharged_at) }} par {{ medical_discharge.created_by }}</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-300"><Icon class="text-sm" name="alert-circle" />Passage administratif toujours ouvert</span>
                        </div>

                        <div class="overflow-x-auto rounded border border-gray-200 dark:border-gray-800">
                            <table class="w-full min-w-[520px] border-collapse text-sm">
                                <caption class="sr-only">Détails de la sortie médicale</caption>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                                    <tr>
                                        <th scope="row" class="w-48 shrink-0 bg-gray-50/70 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40 sm:w-56">Diagnostic final</th>
                                        <td class="px-4 py-3 align-top font-semibold text-slate-700 dark:text-white">{{ medical_discharge.final_diagnosis }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="w-48 shrink-0 bg-gray-50/70 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40 sm:w-56">État à la sortie</th>
                                        <td class="px-4 py-3 align-top text-slate-600 dark:text-slate-300">{{ medical_discharge.patient_condition }}</td>
                                    </tr>
                                    <tr v-if="medical_discharge.discharge_prescription">
                                        <th scope="row" class="w-48 shrink-0 bg-gray-50/70 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40 sm:w-56">Traitement de sortie</th>
                                        <td class="px-4 py-3 align-top text-slate-600 dark:text-slate-300">
                                            <ul v-if="splitLines(medical_discharge.discharge_prescription).length > 1" class="list-disc space-y-1 ps-4">
                                                <li v-for="(line, index) in splitLines(medical_discharge.discharge_prescription)" :key="index">{{ line }}</li>
                                            </ul>
                                            <template v-else>{{ medical_discharge.discharge_prescription }}</template>
                                        </td>
                                    </tr>
                                    <tr v-if="medical_discharge.recommendations">
                                        <th scope="row" class="w-48 shrink-0 bg-gray-50/70 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40 sm:w-56">Recommandations</th>
                                        <td class="px-4 py-3 align-top text-slate-600 dark:text-slate-300">
                                            <ul v-if="splitLines(medical_discharge.recommendations).length > 1" class="list-disc space-y-1 ps-4">
                                                <li v-for="(line, index) in splitLines(medical_discharge.recommendations)" :key="index">{{ line }}</li>
                                            </ul>
                                            <template v-else>{{ medical_discharge.recommendations }}</template>
                                        </td>
                                    </tr>
                                    <tr v-if="medical_discharge.follow_up_at">
                                        <th scope="row" class="w-48 shrink-0 bg-gray-50/70 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40 sm:w-56">Rendez-vous</th>
                                        <td class="px-4 py-3 align-top text-slate-600 dark:text-slate-300">{{ formatDateTime(medical_discharge.follow_up_at) }}</td>
                                    </tr>
                                    <tr v-if="medical_discharge.transfer_destination">
                                        <th scope="row" class="w-48 shrink-0 bg-gray-50/70 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40 sm:w-56">Destination</th>
                                        <td class="px-4 py-3 align-top text-slate-600 dark:text-slate-300">{{ medical_discharge.transfer_destination }}</td>
                                    </tr>
                                    <tr v-if="medical_discharge.type === 'DECEASED'">
                                        <th scope="row" class="w-48 shrink-0 bg-gray-50/70 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40 sm:w-56">Décès</th>
                                        <td class="px-4 py-3 align-top text-slate-600 dark:text-slate-300">{{ formatDateTime(medical_discharge.death_occurred_at) }}<template v-if="medical_discharge.death_place"> · {{ medical_discharge.death_place }}</template></td>
                                    </tr>
                                    <tr v-if="medical_discharge.death_causes">
                                        <th scope="row" class="w-48 shrink-0 bg-gray-50/70 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40 sm:w-56">Causes constatées</th>
                                        <td class="px-4 py-3 align-top text-slate-600 dark:text-slate-300">{{ medical_discharge.death_causes }}</td>
                                    </tr>
                                    <tr v-if="medical_discharge.observations">
                                        <th scope="row" class="w-48 shrink-0 bg-gray-50/70 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40 sm:w-56">Observations</th>
                                        <td class="px-4 py-3 align-top text-slate-600 dark:text-slate-300">{{ medical_discharge.observations }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="consultation.prescriptions.length" class="mt-5">
                            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Ordonnances à remettre au patient</h3>
                            <ul class="divide-y divide-gray-200 rounded border border-gray-200 dark:divide-gray-900 dark:border-gray-800">
                                <li v-for="prescription in consultation.prescriptions" :key="prescription.uuid" class="px-4 py-3">
                                    <p class="truncate text-sm font-semibold text-slate-700 dark:text-white">{{ prescription.lines.map((line) => line.medication_name).join(', ') }}</p>
                                    <p class="mt-0.5 text-xs text-slate-400">Patient {{ patient.patient_number }} · Passage {{ episode.episode_number }} · {{ formatDateTime(prescription.prescribed_at) }}</p>
                                </li>
                            </ul>
                            <p class="mt-2 text-xs text-slate-400">Utilisez « Imprimer l’ordonnance » en bas de page pour l’impression.</p>
                        </div>
                    </div>

                    <form v-else-if="showDischargeForm" class="space-y-4 p-5" @submit.prevent="submitDischarge">
                        <div class="rounded border border-amber-200 bg-amber-50/60 px-4 py-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200"><strong>Action médicale définitive.</strong> Elle termine cette orientation Médecine, mais la Réception / Caisse conserve la responsabilité de la sortie administrative.</div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div><label for="discharge_type" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Type de sortie *</label><span class="relative block"><select id="discharge_type" v-model="dischargeForm.type" :class="selectClass"><option v-for="option in options.discharge_types" :key="option.value" :value="option.value">{{ option.label }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span><FormError :message="dischargeForm.errors.type" /></div>
                            <div><label for="discharged_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date et heure *</label><IconInput id="discharged_at" v-model="dischargeForm.discharged_at" icon="calendar" type="datetime-local" /><FormError :message="dischargeForm.errors.discharged_at" /></div>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div><label for="final_diagnosis" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Diagnostic final *</label><textarea id="final_diagnosis" v-model="dischargeForm.final_diagnosis" rows="3" :class="textareaClass" /><FormError :message="dischargeForm.errors.final_diagnosis" /></div>
                            <div><label for="patient_condition" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">État du patient *</label><textarea id="patient_condition" v-model="dischargeForm.patient_condition" rows="3" :class="textareaClass" placeholder="Stable, amélioré, état clinique au départ…" /><FormError :message="dischargeForm.errors.patient_condition" /></div>
                        </div>
                        <div v-if="dischargeForm.type === 'TRANSFER'"><label for="transfer_destination" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Établissement / service destinataire *</label><Input id="transfer_destination" v-model="dischargeForm.transfer_destination" placeholder="Destination du transfert ou de la référence" /><FormError :message="dischargeForm.errors.transfer_destination" /></div>
                        <div v-if="dischargeForm.type === 'DECEASED'" class="grid gap-4 rounded border border-red-100 bg-red-50/30 p-4 dark:border-red-950 dark:bg-red-950/10 md:grid-cols-2">
                            <div><label for="death_occurred_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date et heure du décès *</label><IconInput id="death_occurred_at" v-model="dischargeForm.death_occurred_at" icon="calendar" type="datetime-local" /><FormError :message="dischargeForm.errors.death_occurred_at" /></div>
                            <div><label for="death_place" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Lieu du décès *</label><Input id="death_place" v-model="dischargeForm.death_place" /><FormError :message="dischargeForm.errors.death_place" /></div>
                            <div class="md:col-span-2"><label for="death_causes" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Causes constatées du décès *</label><textarea id="death_causes" v-model="dischargeForm.death_causes" rows="3" :class="textareaClass" /><FormError :message="dischargeForm.errors.death_causes" /></div>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2"><div><label for="discharge_prescription" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Traitement de sortie</label><textarea id="discharge_prescription" v-model="dischargeForm.discharge_prescription" rows="4" :class="textareaClass" /></div><div><label for="recommendations" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Recommandations</label><textarea id="recommendations" v-model="dischargeForm.recommendations" rows="4" :class="textareaClass" /></div></div>
                        <div class="grid gap-4 md:grid-cols-2"><div><label for="follow_up_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Rendez-vous éventuel</label><IconInput id="follow_up_at" v-model="dischargeForm.follow_up_at" icon="calendar" type="datetime-local" /><FormError :message="dischargeForm.errors.follow_up_at" /></div><div><label for="discharge_observations" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Observations</label><Input id="discharge_observations" v-model="dischargeForm.observations" /></div></div>
                        <FormError :message="dischargeForm.errors.medical_discharge" />
                        <div class="flex justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-900"><Button type="button" size="rg" variant="white-outline" @click="showDischargeForm = false">Annuler</Button><Button type="submit" size="rg" :disabled="dischargeForm.processing"><Icon class="me-2 text-lg" name="check" />Confirmer la sortie médicale</Button></div>
                    </form>
                    <p v-else class="px-5 py-5 text-sm text-slate-400">La consultation reste en cours. Enregistrez le diagnostic et les éléments utiles avant la décision finale.</p>
                </Card>
            </main>

            <aside v-if="current_step === 'dossier'" class="space-y-4 xl:sticky xl:top-4 xl:col-span-4">
                <CareSummaryReadOnly v-if="care_record" :care-summary="care_record" />
                <Card v-else class="overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Transmission des Soins</h2><p class="mt-1 text-xs text-slate-400">Données relevées pendant ce passage.</p></div>
                    <p class="px-4 py-5 text-sm text-slate-400">Aucune fiche Soins disponible pour ce passage direct.</p>
                </Card>

                <Card class="overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Repères médicaux permanents</h2><p class="mt-1 text-xs text-slate-400">À interpréter avec le contexte de la consultation.</p></div>
                    <div class="space-y-4 p-4">
                        <div><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Allergies</p><div v-if="allergies.length" class="mt-2 flex flex-wrap gap-2"><span v-for="allergy in allergies" :key="allergy.uuid" class="rounded border border-red-100 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 dark:border-red-950 dark:bg-red-950/20 dark:text-red-300">{{ allergy.substance }}<template v-if="allergy.reaction"> · {{ allergy.reaction }}</template></span></div><p v-else class="mt-1 text-sm text-slate-400">Aucune allergie enregistrée.</p></div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Antécédents</p>
                            <ul v-if="antecedents.length" class="mt-2 space-y-2"><li v-for="antecedent in antecedents" :key="antecedent.uuid" class="flex gap-2 text-sm text-slate-600 dark:text-slate-300"><span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-400" /><span>{{ antecedent.description }}</span></li></ul>
                            <p v-else class="mt-1 text-sm text-slate-400">Aucun antécédent enregistré.</p>
                            <form v-if="capabilities.can_manage_medical_history" class="mt-3 space-y-2 border-t border-gray-200 pt-3 dark:border-gray-900" @submit.prevent="submitAntecedent">
                                <textarea v-model="antecedentForm.description" rows="2" placeholder="Ajouter un antécédent au dossier permanent…" :class="textareaClass" />
                                <FormError :message="antecedentForm.errors.description" />
                                <div class="flex justify-end"><Button type="submit" size="sm" variant="white-outline" :disabled="antecedentForm.processing || !antecedentForm.description.trim()"><Icon class="me-1.5 text-sm" name="plus" />Ajouter l’antécédent</Button></div>
                            </form>
                        </div>
                    </div>
                </Card>

                <Card v-if="hasEmergencyContact" class="overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Contact de ce passage</h2></div>
                    <div class="p-4"><p class="text-sm font-semibold text-slate-700 dark:text-white">{{ episode.emergency_contact.name || 'Nom non renseigné' }}</p><p v-if="episode.emergency_contact.relationship" class="mt-1 text-xs text-slate-400">{{ episode.emergency_contact.relationship }}</p><p v-if="episode.emergency_contact.phone" class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ episode.emergency_contact.phone }}</p><p v-if="episode.emergency_contact.email" class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ episode.emergency_contact.email }}</p></div>
                </Card>
            </aside>
        </div>

        <Card v-if="!['dossier', 'consultation'].includes(current_step)" class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <Button v-if="previousStep" :as="Link" :href="stepUrl(previousStep.key)" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />{{ previousStep.label }}</Button>
                    <Button v-else :as="Link" href="/medicine" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Retour à la file</Button>
                </div>
                <div v-if="current_step === 'decision' && consultation.prescriptions.length" class="flex flex-wrap items-center justify-center gap-2">
                    <Button v-for="prescription in consultation.prescriptions" :key="prescription.uuid" :as="Link" :href="`/medicine/orientations/${orientation.uuid}/prescriptions/${prescription.uuid}/print`" target="_blank" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="printer" />Imprimer l’ordonnance<template v-if="consultation.prescriptions.length > 1"> ({{ prescription.lines.map((line) => line.medication_name).join(', ') }})</template></Button>
                </div>
                <p v-else class="text-center text-xs text-slate-400">Étape {{ currentStepIndex + 1 }} sur {{ wizardSteps.length }}<span v-if="current_step === 'ordonnance'"> · ordonnance facultative</span></p>
                <div class="flex justify-end">
                    <Button v-if="nextStep" :as="Link" :href="stepUrl(nextStep.key)" size="rg">{{ nextStep.label }}<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                    <Button v-else :as="Link" href="/medicine" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="list" />Retour à la file</Button>
                </div>
            </div>
        </Card>

        <Dialog :open="Boolean(diagnosisToCancel)" as="div" class="relative z-[1300]" @close="closeDiagnosisCancellation">
            <div class="fixed inset-0 bg-slate-950/55 backdrop-blur-[1px]" aria-hidden="true"></div>
            <div class="fixed inset-0 overflow-y-auto p-4">
                <div class="flex min-h-full items-center justify-center">
                    <DialogPanel v-if="diagnosisToCancel" class="w-full max-w-md overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-950">
                        <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-300"><Icon class="text-xl" name="trash" /></span>
                                <div class="min-w-0">
                                    <DialogTitle class="font-heading text-base font-bold text-slate-700 dark:text-white">Annuler ce diagnostic ?</DialogTitle>
                                    <p class="mt-1 text-xs leading-5 text-slate-400">Cette action retire la version active sans supprimer son historique médical.</p>
                                </div>
                            </div>
                            <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 transition-colors hover:bg-gray-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-50 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer la confirmation" :disabled="diagnosisCancellationForm.processing" @click="closeDiagnosisCancellation"><Icon class="text-xl" name="cross" /></button>
                        </header>

                        <form @submit.prevent="cancelDiagnosis">
                            <div class="space-y-4 px-5 py-4">
                                <div class="rounded border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/40">
                                    <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ diagnosisToCancel.type_label }}</span>
                                    <p class="mt-1 break-words text-sm font-semibold leading-6 text-slate-700 dark:text-white">{{ diagnosisToCancel.description }}</p>
                                </div>
                                <div class="flex items-start gap-2.5 text-xs leading-5 text-slate-500 dark:text-slate-300">
                                    <Icon class="mt-0.5 shrink-0 text-base text-amber-500" name="info" />
                                    <p>La saisie restera consultable dans l’historique des rectifications avec sa trace d’annulation.</p>
                                </div>
                                <FormError :message="diagnosisCancellationForm.errors.diagnosis_id" />
                            </div>
                            <footer class="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-50/60 px-5 py-4 dark:border-gray-800 dark:bg-gray-1000/30 sm:flex-row sm:justify-end">
                                <Button type="button" size="rg" variant="white-outline" :disabled="diagnosisCancellationForm.processing" @click="closeDiagnosisCancellation">Conserver</Button>
                                <Button type="submit" size="rg" variant="danger" :disabled="diagnosisCancellationForm.processing"><Icon class="me-2 text-base" name="trash" />{{ diagnosisCancellationForm.processing ? 'Annulation…' : 'Confirmer l’annulation' }}</Button>
                            </footer>
                        </form>
                    </DialogPanel>
                </div>
            </div>
        </Dialog>

        <Dialog :open="Boolean(prescriptionToRemove)" as="div" class="relative z-[1300]" @close="closePrescriptionRemoval">
            <div class="fixed inset-0 bg-slate-950/55 backdrop-blur-[1px]" aria-hidden="true"></div>
            <div class="fixed inset-0 overflow-y-auto p-4">
                <div class="flex min-h-full items-center justify-center">
                    <DialogPanel v-if="prescriptionToRemove" class="w-full max-w-md overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-950">
                        <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-300"><Icon class="text-xl" name="trash" /></span>
                                <div class="min-w-0">
                                    <DialogTitle class="font-heading text-base font-bold text-slate-700 dark:text-white">Retirer cette ordonnance ?</DialogTitle>
                                    <p class="mt-1 text-xs leading-5 text-slate-400">Elle disparaîtra de la liste active et les quantités réservées seront libérées.</p>
                                </div>
                            </div>
                            <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 transition-colors hover:bg-gray-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-50 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer la confirmation" :disabled="prescriptionRemovalForm.processing" @click="closePrescriptionRemoval"><Icon class="text-xl" name="cross" /></button>
                        </header>

                        <form @submit.prevent="removePrescription">
                            <div class="space-y-3 px-5 py-4">
                                <div class="rounded border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/40">
                                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Médicaments concernés</p>
                                    <ul class="mt-2 space-y-1.5">
                                        <li v-for="line in prescriptionToRemove.lines" :key="line.id" class="flex items-center justify-between gap-4 text-sm"><span class="font-semibold text-slate-700 dark:text-white">{{ line.medication_name }}</span><span class="shrink-0 text-xs text-slate-400">{{ line.quantity }} {{ line.unit || 'unité(s)' }}</span></li>
                                    </ul>
                                </div>
                                <FormError :message="prescriptionRemovalForm.errors.reason" />
                            </div>
                            <footer class="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-50/60 px-5 py-4 dark:border-gray-800 dark:bg-gray-1000/30 sm:flex-row sm:justify-end">
                                <Button type="button" size="rg" variant="white-outline" :disabled="prescriptionRemovalForm.processing" @click="closePrescriptionRemoval">Conserver</Button>
                                <Button type="submit" size="rg" variant="danger" :disabled="prescriptionRemovalForm.processing"><Icon class="me-2 text-base" name="trash" />{{ prescriptionRemovalForm.processing ? 'Retrait…' : 'Retirer' }}</Button>
                            </footer>
                        </form>
                    </DialogPanel>
                </div>
            </div>
        </Dialog>
    </div>
</template>
