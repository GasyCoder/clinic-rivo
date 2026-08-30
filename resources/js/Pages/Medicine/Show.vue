<script setup>
import { computed, ref, watch } from 'vue';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';
import RadioButton from '@/Components/UI/RadioButton.vue';
import CareSummaryReadOnly from '@/Components/Surgery/CareSummaryReadOnly.vue';
import ClinicalPatientHeader from '@/Components/Clinical/ClinicalPatientHeader.vue';
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';
import ClinicalRichTextEditor from '@/Components/Clinical/ClinicalRichTextEditor.vue';
import { formatDate, formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

const page = usePage();

const props = defineProps({
    orientation: Object,
    care_record: Object,
    allergies: Array,
    antecedents: Array,
    consultation: Object,
    previous_consultations: { type: Array, default: () => [] },
    medical_discharge: Object,
    options: Object,
    capabilities: Object,
    pending_reasons: { type: Array, default: () => [] },
    current_step: String,
});

const episode = computed(() => props.orientation.episode);
const patient = computed(() => episode.value.patient);
const isEmergency = computed(() => episode.value.priority === 'EMERGENCY');
const emergencyForm = useForm({});
const showEmergencyConfirm = ref(false);
const markEpisodeEmergency = () => {
    if (emergencyForm.processing) return;

    showEmergencyConfirm.value = true;
};
const closeEmergencyConfirm = () => {
    if (emergencyForm.processing) return;

    showEmergencyConfirm.value = false;
};
const confirmMarkEmergency = () => {
    emergencyForm.post(`/medicine/orientations/${props.orientation.uuid}/urgence`, {
        preserveScroll: true,
        onSuccess: () => { showEmergencyConfirm.value = false; },
    });
};
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
const richTextToPlainText = (value) => {
    if (!value || typeof document === 'undefined') return value ?? '';
    const container = document.createElement('div');
    container.innerHTML = value;

    return (container.textContent ?? '').trim();
};
const consultationRecorded = computed(() => Boolean(
    richTextToPlainText(props.consultation?.reason)
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
const finalDiagnoses = computed(() => activeDiagnoses.value.filter((diagnosis) => diagnosis.type === 'FINAL'));
const associatedHypotheses = computed(() => activeDiagnoses.value.filter((diagnosis) => diagnosis.type === 'HYPOTHESIS'));
const prescriptionRecorded = computed(() => (props.consultation?.prescriptions ?? []).length > 0);
const paracliniqueRecorded = computed(() => (props.consultation?.lab_requests?.length ?? 0) > 0
    || (props.consultation?.imaging_requests?.length ?? 0) > 0);
const wizardSteps = computed(() => [
    { key: 'dossier', label: 'Dossier', hint: 'Contexte', complete: true },
    { key: 'consultation', label: 'Consultation', hint: 'Interrogatoire', complete: consultationRecorded.value },
    { key: 'examen', label: 'Examen clinique', hint: 'Examen', complete: Boolean(richTextToPlainText(props.consultation?.clinical_exam)) },
    { key: 'paraclinique', label: 'Paraclinique', hint: 'Facultatif', complete: paracliniqueRecorded.value },
    { key: 'diagnostic', label: 'Diagnostic', hint: 'Conclusion', complete: diagnosisRecorded.value },
    { key: 'ordonnance', label: 'Prescription', hint: 'Facultatif', complete: prescriptionRecorded.value },
    { key: 'decision', label: 'Décision', hint: 'Orientation', complete: isClosed.value },
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

const saveConsultation = (nextStepKey = 'diagnostic') => consultationForm.put(
    `/medicine/orientations/${props.orientation.uuid}/consultation`,
    {
        preserveScroll: true,
        onSuccess: () => router.visit(stepUrl(nextStepKey)),
    },
);

const careOrderCatalog = computed(() => props.options?.care_order_catalog ?? []);
const careOrderSearch = ref('');
const filteredCareOrderCatalog = computed(() => {
    const query = careOrderSearch.value.trim().toLocaleLowerCase('fr');

    return careOrderCatalog.value.filter((item) => `${item.name} ${item.code ?? ''}`.toLocaleLowerCase('fr').includes(query));
});
const careOrderForm = useForm({
    items: [],
    instructions: '',
    requires_return_to_medicine: true,
});
const isCareOrderItemSelected = (item) => careOrderForm.items.some((line) => line.catalog_item_uuid === item.uuid);
const addCareOrderItem = (item) => {
    if (isCareOrderItemSelected(item)) return;

    careOrderForm.items.push({ catalog_item_uuid: item.uuid, code: item.code, name: item.name, quantity: 1 });
    careOrderSearch.value = '';
};
const removeCareOrderItem = (line) => {
    const index = careOrderForm.items.indexOf(line);
    if (index >= 0) careOrderForm.items.splice(index, 1);
};
const submitCareOrder = () => careOrderForm.post(
    `/medicine/orientations/${props.orientation.uuid}/care-orders`,
    { preserveScroll: true, onSuccess: () => { careOrderForm.reset(); careOrderSearch.value = ''; } },
);
const careOrderStatusLabel = (status) => ({ PENDING: 'En attente', IN_PROGRESS: 'En cours', COMPLETED: 'Terminé' }[status] ?? status);

const labCatalog = computed(() => props.options?.lab_catalog ?? []);
const labSearch = ref('');
const filteredLabCatalog = computed(() => {
    const query = labSearch.value.trim().toLocaleLowerCase('fr');

    return labCatalog.value.filter((item) => `${item.name} ${item.code ?? ''}`.toLocaleLowerCase('fr').includes(query));
});
const labRequestForm = useForm({ items: [], notes: '', continue_to_diagnosis: true });
const isLabItemSelected = (item) => labRequestForm.items.some((line) => line.catalog_item_uuid === item.uuid);
const addLabItem = (item) => {
    if (isLabItemSelected(item)) return;

    labRequestForm.items.push({ catalog_item_uuid: item.uuid, code: item.code, name: item.name });
    labSearch.value = '';
};
const removeLabItem = (line) => {
    const index = labRequestForm.items.indexOf(line);
    if (index >= 0) labRequestForm.items.splice(index, 1);
};
const submitLabRequest = () => {
    const imagingStillPending = imagingRequestForm.items.length > 0;
    labRequestForm.continue_to_diagnosis = !imagingStillPending;
    labRequestForm.post(
        `/medicine/orientations/${props.orientation.uuid}/lab-requests`,
        {
            preserveScroll: true,
            onSuccess: () => {
                labRequestForm.reset();
                if (imagingStillPending) paracliniqueTab.value = 'imaging';
            },
        },
    );
};
const labRequestStatusLabel = (status) => ({ REQUESTED: 'Demandé', IN_PROGRESS: 'En cours', COMPLETED: 'Résultats disponibles' }[status] ?? status);
const labRequestStatusBadgeClass = (status) => ['rounded px-2 py-0.5 text-[10px] font-bold uppercase', {
    REQUESTED: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    IN_PROGRESS: 'bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-200',
    COMPLETED: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
}[status] ?? 'bg-gray-100 text-slate-600 dark:bg-gray-900'];

const paracliniqueTab = ref('lab');
const imagingCatalog = computed(() => props.options?.imaging_catalog ?? []);
const imagingSearch = ref('');
const filteredImagingCatalog = computed(() => {
    const query = imagingSearch.value.trim().toLocaleLowerCase('fr');

    return imagingCatalog.value.filter((item) => `${item.name} ${item.code ?? ''}`.toLocaleLowerCase('fr').includes(query));
});
const imagingRequestForm = useForm({ items: [], notes: '', continue_to_diagnosis: true });
const isImagingItemSelected = (item) => imagingRequestForm.items.some((line) => line.catalog_item_uuid === item.uuid);
const addImagingItem = (item) => {
    if (isImagingItemSelected(item)) return;

    imagingRequestForm.items.push({ catalog_item_uuid: item.uuid, code: item.code, name: item.name });
    imagingSearch.value = '';
};
const removeImagingItem = (line) => {
    const index = imagingRequestForm.items.indexOf(line);
    if (index >= 0) imagingRequestForm.items.splice(index, 1);
};
const submitImagingRequest = () => {
    const labStillPending = labRequestForm.items.length > 0;
    imagingRequestForm.continue_to_diagnosis = !labStillPending;
    imagingRequestForm.post(
        `/medicine/orientations/${props.orientation.uuid}/imaging-requests`,
        {
            preserveScroll: true,
            onSuccess: () => {
                imagingRequestForm.reset();
                if (labStillPending) paracliniqueTab.value = 'lab';
            },
        },
    );
};
const hasExistingParaclinicalRequest = computed(() => (
    (props.consultation?.lab_requests?.length ?? 0) > 0
    || (props.consultation?.imaging_requests?.length ?? 0) > 0
));
const hasPendingParaclinicalSelection = computed(() => (
    labRequestForm.items.length > 0 || imagingRequestForm.items.length > 0
));
const imagingResultForms = {};
const imagingResultForm = (itemUuid) => {
    if (!imagingResultForms[itemUuid]) {
        imagingResultForms[itemUuid] = useForm({ result_value: '', result_notes: '' });
    }
    return imagingResultForms[itemUuid];
};
const submitImagingResult = (item) => imagingResultForm(item.uuid).post(
    `/medicine/orientations/${props.orientation.uuid}/imaging-requests/${item.uuid}/result`,
    { preserveScroll: true },
);

const referralFieldConfig = {
    MATERNITY: [
        { key: 'motif', label: 'Motif', placeholder: 'Motif de la demande', multiline: true },
        { key: 'indication', label: 'Indication', placeholder: 'Indication clinique', multiline: true },
        { key: 'terme', label: 'Terme (SA)', placeholder: 'Semaines d’aménorrhée si connu' },
        { key: 'priorite', label: 'Priorité' },
        { key: 'observations', label: 'Observations', placeholder: 'Contexte utile pour la Maternité', multiline: true },
    ],
    HOSPITALIZATION: [
        { key: 'motif', label: 'Motif', placeholder: 'Motif de l’hospitalisation', multiline: true },
        { key: 'diagnostic', label: 'Diagnostic d’entrée', placeholder: 'Diagnostic motivant l’admission', multiline: true },
        { key: 'service', label: 'Service souhaité', placeholder: 'Service d’accueil envisagé' },
        { key: 'priorite', label: 'Priorité' },
        { key: 'instructions', label: 'Instructions', placeholder: 'Consignes utiles au service', multiline: true },
    ],
    TRANSFER: [
        { key: 'destination_etablissement', label: 'Destination / établissement', placeholder: 'Établissement ou service destinataire' },
        { key: 'motif', label: 'Motif', placeholder: 'Motif du transfert', multiline: true },
        { key: 'diagnostic', label: 'Diagnostic', placeholder: 'Diagnostic actuel', multiline: true },
        { key: 'etat_clinique', label: 'État clinique', placeholder: 'État du patient au transfert', multiline: true },
        { key: 'priorite', label: 'Priorité' },
        { key: 'recommandations', label: 'Recommandations', placeholder: 'Recommandations pour l’équipe destinataire', multiline: true },
        { key: 'observations', label: 'Observations', placeholder: 'Autres observations utiles', multiline: true },
    ],
    PEDIATRICS: [
        { key: 'motif', label: 'Motif', placeholder: 'Motif de l’avis pédiatrique', multiline: true },
        { key: 'indication', label: 'Indication', placeholder: 'Indication clinique', multiline: true },
        { key: 'priorite', label: 'Priorité' },
        { key: 'observations', label: 'Observations', placeholder: 'Contexte utile', multiline: true },
    ],
};
const referralFieldsFor = (destination) => referralFieldConfig[destination] ?? [];
const referralPriorityDefault = () => (isEmergency.value ? 'Urgente' : 'Normale');
const referralFieldValues = ref({});
const referralComposedReason = computed(() => {
    if (!decisionChoice.value) return '';

    return referralFieldsFor(decisionChoice.value)
        .map((field) => [field.label, (referralFieldValues.value[field.key] || '').trim()])
        .filter(([, value]) => value !== '')
        .map(([label, value]) => `${label} : ${value}`)
        .join('\n');
});
const transferReferralReady = computed(() => decisionChoice.value !== 'TRANSFER' || Boolean(
    referralFieldValues.value.destination_etablissement?.trim()
    && referralFieldValues.value.motif?.trim(),
));
const referralForm = useForm({ destination: '', reason: '' });
const submitReferral = (destination) => {
    referralForm.destination = destination;
    referralForm.reason = referralComposedReason.value;
    referralForm.post(`/medicine/orientations/${props.orientation.uuid}/referrals`, {
        preserveScroll: true,
        onSuccess: () => {
            referralForm.reset();
            referralFieldValues.value = {};
            referralTransferDestinationChoice.value = '';
            referralTransferDestinationOther.value = '';
            decisionChoice.value = null;
        },
    });
};

const surgicalReferralForm = useForm({ catalog_item_uuid: '', diagnostic: '', indication: '', priority: 'NORMAL', notes: '' });
const submitSurgicalReferral = () => surgicalReferralForm.post(`/medicine/orientations/${props.orientation.uuid}/surgical-referrals`, {
    preserveScroll: true,
    onSuccess: () => { surgicalReferralForm.reset(); decisionChoice.value = null; },
});

const careOrderStatusBadgeClass = (status) => ['rounded px-2 py-0.5 text-[10px] font-bold uppercase', {
    PENDING: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    IN_PROGRESS: 'bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-200',
    COMPLETED: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
}[status] ?? 'bg-gray-100 text-slate-600 dark:bg-gray-900'];

const diagnosisForm = useForm({
    type: 'HYPOTHESIS',
    diagnostic_catalog_uuid: null,
    description: '',
    manual_code: '',
    notes: '',
});
const diagnosisManualMode = ref(false);
const diagnosisSearch = ref('');
const diagnosisResults = ref([]);
const diagnosisSearchLoading = ref(false);
const diagnosisSearchError = ref('');
let diagnosisSearchTimer = null;
let diagnosisSearchController = null;

watch(diagnosisSearch, (value) => {
    clearTimeout(diagnosisSearchTimer);
    diagnosisSearchController?.abort();
    diagnosisSearchError.value = '';

    const query = value.trim();
    if (query.length < 2) {
        diagnosisResults.value = [];
        diagnosisSearchLoading.value = false;
        return;
    }

    diagnosisSearchTimer = setTimeout(async () => {
        diagnosisSearchController = new AbortController();
        diagnosisSearchLoading.value = true;
        try {
            const response = await fetch(`/diagnostic-catalog/search?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
                signal: diagnosisSearchController.signal,
            });
            if (!response.ok) throw new Error('Recherche indisponible');
            diagnosisResults.value = (await response.json()).data ?? [];
        } catch (error) {
            if (error.name !== 'AbortError') diagnosisSearchError.value = 'La recherche est momentanément indisponible.';
        } finally {
            diagnosisSearchLoading.value = false;
        }
    }, 250);
});

const resetDiagnosisEntry = () => {
    diagnosisForm.reset('diagnostic_catalog_uuid', 'description', 'manual_code', 'notes');
    diagnosisSearch.value = '';
    diagnosisResults.value = [];
};
const addCatalogDiagnosis = (diagnostic) => {
    diagnosisForm.diagnostic_catalog_uuid = diagnostic.uuid;
    diagnosisForm.description = '';
    diagnosisForm.manual_code = '';
    diagnosisForm.post(`/medicine/orientations/${props.orientation.uuid}/diagnoses`, {
        preserveScroll: true,
        onSuccess: resetDiagnosisEntry,
    });
};
const addManualDiagnosis = () => {
    diagnosisForm.diagnostic_catalog_uuid = null;
    diagnosisForm.post(`/medicine/orientations/${props.orientation.uuid}/diagnoses`, {
        preserveScroll: true,
        onSuccess: () => {
            resetDiagnosisEntry();
            diagnosisManualMode.value = false;
        },
    });
};

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
const prescriptionForm = useForm({ lines: [], continue_to_decision: true });
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
        continue_to_decision: data.continue_to_decision,
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

const latestFinalDiagnosisEntry = computed(() => [...(props.consultation?.diagnoses ?? [])]
    .reverse()
    .find((diagnosis) => diagnosis.type === 'FINAL' && !diagnosis.cancelled) ?? null);
const latestFinalDiagnosis = computed(() => latestFinalDiagnosisEntry.value?.description ?? '');
const timelineEntries = computed(() => [...finalDiagnoses.value, ...associatedHypotheses.value]
    .filter((diagnosis) => diagnosis.id !== latestFinalDiagnosisEntry.value?.id));
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
const dischargeTypeIcon = (value) => ({
    NORMAL: 'check-circle-fill',
    TRANSFER: 'share',
    AT_PATIENT_REQUEST: 'signout',
    MEDICAL_DECISION_REFUSAL: 'cross-circle',
    DECEASED: 'alert-fill',
}[value] ?? 'circle');
// Card labels stay short so five options never wrap; the full legal wording
// (option.label) is still what gets saved and shown everywhere else.
const dischargeTypeShortLabel = (option) => ({
    NORMAL: 'Normale',
    TRANSFER: 'Transfert',
    AT_PATIENT_REQUEST: 'Demande du patient',
    MEDICAL_DECISION_REFUSAL: 'Refus médical',
    DECEASED: 'Décès',
}[option.value] ?? option.label);

// Built server-side from the configured clinic directory and filtered against
// the active deployment, so a transfer can never accidentally target itself.
// The local fallback only protects an already-open Inertia page whose props
// predate this field; the next full visit always uses the server directory.
const knownTransferDestinations = [
    { code: 'M', name: 'Mampikony', destination: 'Clinique Saint Georges — Mampikony' },
    { code: 'A', name: 'Ambondromamy', destination: 'Clinique Saint Georges — Ambondromamy' },
    { code: 'B', name: 'Boriziny', destination: 'Clinique Saint Georges — Boriziny' },
];
const otherSiteOptions = computed(() => {
    const configured = props.options?.transfer_destinations;
    if (Array.isArray(configured) && configured.length) return configured;

    const activeCode = String(page.props.site?.code ?? '').toUpperCase();
    return knownTransferDestinations.filter((site) => site.code !== activeCode);
});
const transferDestinationChoice = ref('');
const transferDestinationOther = ref('');
watch([transferDestinationChoice, transferDestinationOther], () => {
    dischargeForm.transfer_destination = transferDestinationChoice.value === 'OTHER'
        ? transferDestinationOther.value
        : transferDestinationChoice.value;
});

const referralTransferDestinationChoice = ref('');
const referralTransferDestinationOther = ref('');
watch([referralTransferDestinationChoice, referralTransferDestinationOther], () => {
    if (decisionChoice.value !== 'TRANSFER') return;

    referralFieldValues.value.destination_etablissement = referralTransferDestinationChoice.value === 'OTHER'
        ? referralTransferDestinationOther.value
        : referralTransferDestinationChoice.value;
});

const truncateReferralPrefill = (value, maxLength) => String(value ?? '').trim().slice(0, maxLength);
const currentDiagnosisSummary = computed(() => {
    if (latestFinalDiagnosis.value) return latestFinalDiagnosis.value;

    return associatedHypotheses.value.map((diagnosis) => diagnosis.description).filter(Boolean).join(' ; ');
});
const currentClinicalStateSummary = computed(() => {
    const clinicalExam = richTextToPlainText(props.consultation?.clinical_exam);
    const vitals = [
        careRecordBloodPressure.value ? `TA ${careRecordBloodPressure.value} mmHg` : null,
        props.care_record?.heart_rate ? `FC ${props.care_record.heart_rate} bpm` : null,
        props.care_record?.spo2 ? `SpO₂ ${props.care_record.spo2} %` : null,
        props.care_record?.temperature_celsius ? `T° ${props.care_record.temperature_celsius} °C` : null,
    ].filter(Boolean).join(' · ');

    return [clinicalExam, vitals ? `Constantes : ${vitals}` : null].filter(Boolean).join('\n');
});
const transferReferralDefaults = () => ({
    destination_etablissement: '',
    motif: truncateReferralPrefill(richTextToPlainText(props.consultation?.reason), 450),
    diagnostic: truncateReferralPrefill(currentDiagnosisSummary.value, 450),
    etat_clinique: truncateReferralPrefill(currentClinicalStateSummary.value, 700),
    priorite: referralPriorityDefault(),
    recommandations: truncateReferralPrefill(props.consultation?.decision_notes, 350),
    observations: '',
});

const showDischargeForm = ref(false);
const prescriptionTab = ref('medicines');
const decisionChoice = ref(null);
const decisionDeferred = ref(false);
const decisionCards = [
    { key: 'DISCHARGE', label: 'Sortie médicale', icon: 'check-circle', capability: 'can_discharge' },
    { key: 'HOSPITALIZATION', label: 'Hospitalisation', icon: 'building', capability: 'can_request_hospitalization' },
    { key: 'MATERNITY', label: 'Maternité', icon: 'heart', capability: 'can_request_maternity' },
    { key: 'SURGERY', label: 'Chirurgie', icon: 'plus-circle', capability: 'can_request_surgery' },
    { key: 'PEDIATRICS', label: 'Pédiatrie', icon: 'users', capability: 'can_request_pediatrics' },
    { key: 'TRANSFER', label: 'Référence / Transfert', icon: 'share', capability: 'can_request_transfer' },
];
const chooseDecision = (key) => {
    decisionDeferred.value = false;
    if (key === 'DISCHARGE') {
        showDischargeForm.value = true;
        decisionChoice.value = null;
        return;
    }
    if (key === 'CONTINUE') {
        decisionDeferred.value = true;
        decisionChoice.value = null;
        return;
    }
    referralForm.clearErrors();
    referralTransferDestinationChoice.value = '';
    referralTransferDestinationOther.value = '';
    referralFieldValues.value = key === 'TRANSFER'
        ? transferReferralDefaults()
        : (referralFieldsFor(key).some((field) => field.key === 'priorite') ? { priorite: referralPriorityDefault() } : {});
    decisionChoice.value = key;
};
const closeDecisionChoice = () => {
    referralForm.clearErrors();
    referralFieldValues.value = {};
    referralTransferDestinationChoice.value = '';
    referralTransferDestinationOther.value = '';
    decisionChoice.value = null;
    showDischargeForm.value = false;
};
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
// SIMPLE_TREATMENT/MEDICATION_PRESCRIPTION/NURSING_CARE/DISCHARGE/EXTERNAL_TRANSFER are
// backed by real screens here (ordonnance, ordre de soins, décision). The rest
// (Laboratoire, Hospitalisation, Chirurgie, Maternité, Pédiatrie) stay data-only.
const connectedDecisions = ['SIMPLE_TREATMENT', 'MEDICATION_PRESCRIPTION', 'NURSING_CARE', 'DISCHARGE', 'EXTERNAL_TRANSFER'];
</script>

<template>
    <Head :title="`Consultation ${episode.episode_number}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <ClinicalPatientHeader :patient="patient" :episode="episode" :reason="orientation.reason" back-href="/medicine" back-label="File Médecine" />

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-400">
                <span :class="['inline-flex items-center gap-1 rounded border px-2 py-1 font-semibold', isClosed ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' : 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/30 dark:text-primary-300']"><span :class="['h-1.5 w-1.5 rounded-full', isClosed ? 'bg-emerald-500' : 'bg-primary-500']" />{{ isClosed ? medical_discharge.type_label : 'En consultation' }}</span>
                <span>Naissance/âge {{ birthLabel }} · Sexe {{ patient.sex_label }} · Tél. {{ patient.phone || 'N/R' }} · Origine {{ orientation.source_label }}</span>
                <span>Pris en charge {{ formatDateTime(orientation.accepted_at) }}<template v-if="orientation.accepted_by"> par {{ orientation.accepted_by }}</template></span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="capabilities.can_mark_emergency" size="sm" variant="danger" :disabled="emergencyForm.processing" @click="markEpisodeEmergency"><Icon class="text-sm" name="activity" /><span class="ms-1.5">Classer ce passage en urgence</span></Button>
                <Button :as="Link" :href="`/patients/${patient.uuid}`" size="sm" variant="white-outline"><Icon class="text-sm" name="user" /><span class="ms-1.5">Dossier patient</span></Button>
            </div>
        </div>

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
                    <div class="flex flex-col gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900 lg:flex-row lg:items-start lg:justify-between">
                        <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Consultation — Interrogatoire</h2><p class="mt-1 text-xs text-slate-400">Motif, histoire clinique et observations rapportées par le patient.</p></div>
                        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start lg:justify-end">
                            <div class="min-w-0 sm:max-w-xl sm:text-end">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Demande initiale <span class="ms-1 rounded bg-gray-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500 dark:bg-gray-900 dark:text-slate-300">Lecture seule</span></p>
                                <div v-if="episode.designations.length" class="mt-1.5 flex flex-wrap gap-1.5 sm:justify-end"><span v-for="designation in episode.designations" :key="designation.uuid" class="rounded border border-gray-200 bg-gray-50 px-2 py-1 text-xs font-semibold text-slate-700 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-200">{{ designation.description }}</span></div>
                                <p v-else class="mt-1 text-xs text-slate-500">Aucune prestation précisée à l’arrivée.</p>
                            </div>
                            <div class="shrink-0 border-t border-gray-200 pt-2 text-xs text-slate-400 dark:border-gray-800 sm:border-s sm:border-t-0 sm:ps-4 sm:pt-0">Dr {{ consultation.doctor }}</div>
                        </div>
                    </div>
                    <form class="space-y-4 p-5" @submit.prevent="saveConsultation('examen')">
                        <div>
                            <label for="reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif / interrogatoire *</label>
                            <ClinicalRichTextEditor id="reason" v-model="consultationForm.reason" :disabled="!capabilities.can_update_consultation" placeholder="Symptômes, histoire de la maladie, observations rapportées par le patient" />
                            <FormError :message="consultationForm.errors.reason" />
                        </div>
                        <div class="flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                            <Button :as="Link" :href="stepUrl('dossier')" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Dossier</Button>
                            <div class="flex flex-col items-stretch gap-2 sm:items-end">
                                <Button v-if="capabilities.can_update_consultation" type="submit" size="rg" :disabled="consultationForm.processing"><Icon class="me-2 text-lg" name="check" />Enregistrer et continuer<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                                <Button v-else :as="Link" :href="stepUrl('examen')" size="rg">Examen clinique<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                            </div>
                        </div>
                        <FormError :message="consultationForm.errors.consultation" />
                    </form>
                </Card>

                <Card v-if="current_step === 'examen'" class="w-full overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Examen clinique</h2><p class="mt-1 text-xs text-slate-400">Constantes relevées aux Soins, puis constatations de l’examen médical.</p></div>
                    <div v-if="care_record" class="border-b border-gray-200 p-5 dark:border-gray-900"><CareSummaryReadOnly :care-summary="care_record" default-open /></div>
                    <form class="space-y-4 p-5" @submit.prevent="saveConsultation('paraclinique')">
                        <div>
                            <label for="clinical_exam" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Constatations de l’examen</label>
                            <ClinicalRichTextEditor id="clinical_exam" v-model="consultationForm.clinical_exam" :disabled="!capabilities.can_update_consultation" :max-length="10000" placeholder="Constatations cliniques utiles, signes positifs et négatifs" />
                            <FormError :message="consultationForm.errors.clinical_exam" />
                        </div>
                        <div class="flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                            <Button :as="Link" :href="stepUrl('consultation')" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Consultation</Button>
                            <div class="flex flex-col items-stretch gap-2 sm:items-end">
                                <Button v-if="capabilities.can_update_consultation" type="submit" size="rg" :disabled="consultationForm.processing"><Icon class="me-2 text-lg" name="check" />Enregistrer et continuer<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                                <Button v-else :as="Link" :href="stepUrl('paraclinique')" size="rg">Paraclinique<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                            </div>
                        </div>
                    </form>
                </Card>

                <Card v-if="current_step === 'paraclinique'" class="w-full overflow-hidden shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"><Icon class="text-lg" name="activity" /></span>
                            <div>
                                <h2 class="text-sm font-bold text-slate-700 dark:text-white">Examens paracliniques</h2>
                                <p class="mt-0.5 text-xs text-slate-400">Prescrivez les examens utiles et transmettez une indication claire au service concerné.</p>
                            </div>
                        </div>
                        <span class="self-start rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1 text-[11px] font-semibold text-slate-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300">Étape facultative</span>
                    </div>

                    <div class="border-b border-gray-200 bg-gray-50/60 px-5 py-2.5 dark:border-gray-900 dark:bg-gray-1000/20">
                        <div class="grid max-w-xl grid-cols-2 gap-1 rounded-lg border border-gray-200 bg-gray-100 p-1 dark:border-gray-800 dark:bg-gray-900" role="tablist" aria-label="Type d’examen paraclinique">
                            <button type="button" role="tab" :aria-selected="paracliniqueTab === 'lab'" :class="['flex h-10 items-center justify-center gap-2 rounded-md px-3 text-sm font-semibold transition-all', paracliniqueTab === 'lab' ? 'bg-white text-primary-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-950 dark:text-primary-300 dark:ring-gray-800' : 'text-slate-500 hover:bg-white/70 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-gray-950/60 dark:hover:text-slate-200']" @click="paracliniqueTab = 'lab'">
                                <Icon class="text-base" name="activity" />
                                <span>Laboratoire</span>
                                <span v-if="consultation.lab_requests?.length || labRequestForm.items.length" class="rounded-full bg-primary-50 px-2 py-0.5 text-[10px] font-bold tabular-nums text-primary-700 dark:bg-primary-950/50 dark:text-primary-300">{{ (consultation.lab_requests?.length ?? 0) + labRequestForm.items.length }}</span>
                            </button>
                            <button type="button" role="tab" :aria-selected="paracliniqueTab === 'imaging'" :class="['flex h-10 items-center justify-center gap-2 rounded-md px-3 text-sm font-semibold transition-all', paracliniqueTab === 'imaging' ? 'bg-white text-primary-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-950 dark:text-primary-300 dark:ring-gray-800' : 'text-slate-500 hover:bg-white/70 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-gray-950/60 dark:hover:text-slate-200']" @click="paracliniqueTab = 'imaging'">
                                <Icon class="text-base" name="scan" />
                                <span>ECG / Échographie</span>
                                <span v-if="consultation.imaging_requests?.length || imagingRequestForm.items.length" class="rounded-full bg-primary-50 px-2 py-0.5 text-[10px] font-bold tabular-nums text-primary-700 dark:bg-primary-950/50 dark:text-primary-300">{{ (consultation.imaging_requests?.length ?? 0) + imagingRequestForm.items.length }}</span>
                            </button>
                        </div>
                    </div>

                    <template v-if="paracliniqueTab === 'lab'">
                        <div v-if="consultation.lab_requests?.length" class="space-y-3 border-b border-gray-200 bg-gray-50/30 p-5 dark:border-gray-900 dark:bg-gray-1000/10">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Demandes transmises</p>
                                <span class="text-xs text-slate-400">{{ consultation.lab_requests.length }} demande(s)</span>
                            </div>
                            <div v-for="request in consultation.lab_requests" :key="request.uuid" class="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-slate-700 dark:text-white">{{ formatDateTime(request.requested_at) }} — Dr {{ request.requested_by }}</p>
                                    <span :class="labRequestStatusBadgeClass(request.status)">{{ labRequestStatusLabel(request.status) }}</span>
                                </div>
                                <ul class="mt-2 space-y-1.5">
                                    <li v-for="item in request.items" :key="item.uuid" class="text-xs">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-semibold text-slate-700 dark:text-white">{{ item.name }}</span>
                                            <span v-if="item.resulted_at" class="font-semibold text-emerald-600 dark:text-emerald-300">{{ item.result_value }}</span>
                                            <span v-else class="text-slate-400">En attente de résultat</span>
                                        </div>
                                        <p v-if="item.result_notes" class="mt-0.5 text-[11px] text-slate-400">{{ item.result_notes }}</p>
                                    </li>
                                </ul>
                                <p v-if="request.notes" class="mt-1.5 text-[11px] text-slate-400">{{ request.notes }}</p>
                            </div>
                        </div>
                        <form v-if="capabilities.can_create_lab_request" id="lab-request-form" class="p-5" @submit.prevent="submitLabRequest">
                            <div class="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
                                <div class="min-w-0 space-y-4">
                                    <div>
                                        <div class="mb-1.5 flex items-center justify-between gap-3">
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-white">Ajouter une analyse</label>
                                            <span v-if="labRequestForm.items.length" class="text-xs font-semibold text-primary-700 dark:text-primary-300">{{ labRequestForm.items.length }} sélectionnée(s)</span>
                                        </div>
                                <IconInput v-model="labSearch" icon="search" placeholder="NFS, CRP, glycémie…" />
                                        <div v-if="labSearch.trim() && filteredLabCatalog.length" class="mt-2 max-h-52 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:divide-gray-900 dark:border-gray-800 dark:bg-gray-950">
                                    <button v-for="item in filteredLabCatalog" :key="item.uuid" type="button" class="flex w-full items-center justify-between gap-4 px-3.5 py-2.5 text-start transition-colors hover:bg-primary-50/60 dark:hover:bg-primary-950/20" @click="addLabItem(item)">
                                                <span class="min-w-0"><span class="block truncate text-sm font-semibold text-slate-700 dark:text-white">{{ item.name }}</span><span v-if="item.code" class="mt-0.5 block text-[11px] text-slate-400">{{ item.code }}</span></span>
                                                <span :class="['flex size-7 shrink-0 items-center justify-center rounded-md border', isLabItemSelected(item) ? 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/50 dark:text-primary-300' : 'border-gray-200 text-slate-400 dark:border-gray-800']"><Icon :name="isLabItemSelected(item) ? 'check' : 'plus'" /></span>
                                    </button>
                                </div>
                                <p v-else-if="labSearch.trim()" class="mt-2 text-xs text-slate-400">Aucune analyse ne correspond.</p>
                                <FormError :message="labRequestForm.errors.items" />
                                    </div>

                                    <div v-if="labRequestForm.items.length" class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                                        <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-3.5 py-2.5 dark:border-gray-800 dark:bg-gray-1000/30">
                                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Prescription en préparation</p>
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold tabular-nums text-slate-500 ring-1 ring-gray-200 dark:bg-gray-950 dark:ring-gray-800">{{ labRequestForm.items.length }}</span>
                                        </div>
                                        <div class="divide-y divide-gray-100 dark:divide-gray-900">
                                            <div v-for="line in labRequestForm.items" :key="line.catalog_item_uuid" class="flex items-center gap-3 px-3.5 py-3">
                                                <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"><Icon name="activity" /></span>
                                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-700 dark:text-white">{{ line.name }}</span><span v-if="line.code" class="mt-0.5 block text-[11px] text-slate-400">{{ line.code }}</span></span>
                                                <button type="button" class="flex size-8 shrink-0 items-center justify-center rounded-md border border-gray-200 text-slate-400 transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600 dark:border-gray-800 dark:hover:border-red-900 dark:hover:bg-red-950/30" :aria-label="`Retirer ${line.name}`" @click="removeLabItem(line)"><Icon name="trash" /></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-lg border border-gray-200 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-1000/20">
                                    <div class="flex items-start gap-2.5">
                                        <Icon class="mt-0.5 shrink-0 text-base text-primary-600 dark:text-primary-300" name="file-text" />
                                        <div class="min-w-0 flex-1">
                                            <label for="lab_notes" class="block text-sm font-semibold text-slate-700 dark:text-white">Indication clinique</label>
                                            <p class="mt-0.5 text-xs leading-5 text-slate-400">Ajoutez le contexte nécessaire à l’interprétation et à la réalisation des analyses.</p>
                                        </div>
                                    </div>
                                    <textarea id="lab_notes" v-model="labRequestForm.notes" rows="5" :class="[textareaClass, 'mt-3 bg-white dark:bg-gray-950']" placeholder="Contexte clinique, symptômes, traitement en cours…" />
                                    <FormError :message="labRequestForm.errors.notes" />
                                </div>
                            </div>
                        </form>
                    </template>

                    <template v-else>
                        <div v-if="consultation.imaging_requests?.length" class="space-y-3 border-b border-gray-200 bg-gray-50/30 p-5 dark:border-gray-900 dark:bg-gray-1000/10">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Demandes transmises</p>
                                <span class="text-xs text-slate-400">{{ consultation.imaging_requests.length }} demande(s)</span>
                            </div>
                            <div v-for="request in consultation.imaging_requests" :key="request.uuid" class="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-slate-700 dark:text-white">{{ formatDateTime(request.requested_at) }} — Dr {{ request.requested_by }}</p>
                                    <span :class="labRequestStatusBadgeClass(request.status)">{{ labRequestStatusLabel(request.status) }}</span>
                                </div>
                                <ul class="mt-2 space-y-2">
                                    <li v-for="item in request.items" :key="item.uuid" class="text-xs">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-semibold text-slate-700 dark:text-white">{{ item.name }}</span>
                                            <span v-if="item.resulted_at" class="font-semibold text-emerald-600 dark:text-emerald-300">Compte rendu disponible</span>
                                            <span v-else class="text-slate-400">En attente</span>
                                        </div>
                                        <p v-if="item.resulted_at" class="mt-0.5 text-[11px] text-slate-500">{{ item.result_value }}<template v-if="item.result_notes"> — {{ item.result_notes }}</template></p>
                                        <form v-else-if="capabilities.can_record_imaging_result" class="mt-2 flex flex-col gap-2 rounded-lg border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-1000/30" @submit.prevent="submitImagingResult(item)">
                                            <textarea v-model="imagingResultForm(item.uuid).result_value" rows="2" :class="textareaClass" placeholder="Compte rendu (résultat)" />
                                            <FormError :message="imagingResultForm(item.uuid).errors.result_value" />
                                            <div class="flex justify-end"><Button type="submit" size="sm" :disabled="imagingResultForm(item.uuid).processing || !imagingResultForm(item.uuid).result_value.trim()">Enregistrer le compte rendu</Button></div>
                                        </form>
                                    </li>
                                </ul>
                                <p v-if="request.notes" class="mt-1.5 text-[11px] text-slate-400">{{ request.notes }}</p>
                            </div>
                        </div>
                        <form v-if="capabilities.can_create_imaging_request" id="imaging-request-form" class="p-5" @submit.prevent="submitImagingRequest">
                            <div class="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
                                <div class="min-w-0 space-y-4">
                                    <div>
                                        <div class="mb-1.5 flex items-center justify-between gap-3">
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-white">Ajouter un examen</label>
                                            <span v-if="imagingRequestForm.items.length" class="text-xs font-semibold text-primary-700 dark:text-primary-300">{{ imagingRequestForm.items.length }} sélectionné(s)</span>
                                        </div>
                                        <IconInput v-model="imagingSearch" icon="search" placeholder="ECG, échographie abdominale…" />
                                        <div v-if="imagingSearch.trim() && filteredImagingCatalog.length" class="mt-2 max-h-52 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:divide-gray-900 dark:border-gray-800 dark:bg-gray-950">
                                            <button v-for="item in filteredImagingCatalog" :key="item.uuid" type="button" class="flex w-full items-center justify-between gap-4 px-3.5 py-2.5 text-start transition-colors hover:bg-primary-50/60 dark:hover:bg-primary-950/20" @click="addImagingItem(item)">
                                                <span class="min-w-0"><span class="block truncate text-sm font-semibold text-slate-700 dark:text-white">{{ item.name }}</span><span v-if="item.code" class="mt-0.5 block text-[11px] text-slate-400">{{ item.code }}</span></span>
                                                <span :class="['flex size-7 shrink-0 items-center justify-center rounded-md border', isImagingItemSelected(item) ? 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/50 dark:text-primary-300' : 'border-gray-200 text-slate-400 dark:border-gray-800']"><Icon :name="isImagingItemSelected(item) ? 'check' : 'plus'" /></span>
                                            </button>
                                        </div>
                                        <p v-else-if="imagingSearch.trim()" class="mt-2 text-xs text-slate-400">Aucun examen ne correspond.</p>
                                        <p v-else-if="!imagingCatalog.length" class="mt-2 text-xs text-slate-400">Aucun examen ECG/échographie n’est encore configuré au référentiel.</p>
                                        <FormError :message="imagingRequestForm.errors.items" />
                                    </div>

                                    <div v-if="imagingRequestForm.items.length" class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                                        <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-3.5 py-2.5 dark:border-gray-800 dark:bg-gray-1000/30">
                                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Demande en préparation</p>
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold tabular-nums text-slate-500 ring-1 ring-gray-200 dark:bg-gray-950 dark:ring-gray-800">{{ imagingRequestForm.items.length }}</span>
                                        </div>
                                        <div class="divide-y divide-gray-100 dark:divide-gray-900">
                                            <div v-for="line in imagingRequestForm.items" :key="line.catalog_item_uuid" class="flex items-center gap-3 px-3.5 py-3">
                                                <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"><Icon name="scan" /></span>
                                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-700 dark:text-white">{{ line.name }}</span><span v-if="line.code" class="mt-0.5 block text-[11px] text-slate-400">{{ line.code }}</span></span>
                                                <button type="button" class="flex size-8 shrink-0 items-center justify-center rounded-md border border-gray-200 text-slate-400 transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600 dark:border-gray-800 dark:hover:border-red-900 dark:hover:bg-red-950/30" :aria-label="`Retirer ${line.name}`" @click="removeImagingItem(line)"><Icon name="trash" /></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-lg border border-gray-200 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-1000/20">
                                    <div class="flex items-start gap-2.5">
                                        <Icon class="mt-0.5 shrink-0 text-base text-primary-600 dark:text-primary-300" name="file-text" />
                                        <div class="min-w-0 flex-1">
                                            <label for="imaging_notes" class="block text-sm font-semibold text-slate-700 dark:text-white">Indication clinique</label>
                                            <p class="mt-0.5 text-xs leading-5 text-slate-400">Précisez le motif, la région concernée et les éléments cliniques utiles à l’examen.</p>
                                        </div>
                                    </div>
                                    <textarea id="imaging_notes" v-model="imagingRequestForm.notes" rows="5" :class="[textareaClass, 'mt-3 bg-white dark:bg-gray-950']" placeholder="Motif de l’examen, symptômes, région à explorer…" />
                                    <FormError :message="imagingRequestForm.errors.notes" />
                                </div>
                            </div>
                        </form>
                    </template>

                    <div class="flex flex-col gap-3 border-t border-gray-200 bg-gray-50/40 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/20 sm:flex-row sm:items-center sm:justify-between">
                        <Button :as="Link" :href="stepUrl('examen')" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Examen clinique</Button>
                        <p v-if="hasPendingParaclinicalSelection" class="text-center text-xs font-semibold text-amber-700 dark:text-amber-300">Enregistrez les examens sélectionnés avant de poursuivre.</p>
                        <p v-else-if="hasExistingParaclinicalRequest" class="text-center text-xs text-slate-400">Les demandes enregistrées restent suivies dans le dossier du patient.</p>
                        <p v-else class="text-center text-xs text-slate-400">Étape facultative · prescrivez un examen uniquement lorsqu’il est indiqué.</p>
                        <div class="flex justify-end">
                            <Button v-if="paracliniqueTab === 'lab' && labRequestForm.items.length" type="submit" form="lab-request-form" size="rg" :disabled="labRequestForm.processing || imagingRequestForm.processing">
                                <Icon class="me-2 text-lg" name="activity" />{{ imagingRequestForm.items.length ? 'Envoyer puis finaliser l’imagerie' : 'Envoyer au Laboratoire et continuer' }}<Icon class="ms-2 text-lg" name="arrow-right" />
                            </Button>
                            <Button v-else-if="paracliniqueTab === 'imaging' && imagingRequestForm.items.length" type="submit" form="imaging-request-form" size="rg" :disabled="imagingRequestForm.processing || labRequestForm.processing">
                                <Icon class="me-2 text-lg" name="activity" />{{ labRequestForm.items.length ? 'Envoyer puis finaliser les analyses' : 'Envoyer la demande et continuer' }}<Icon class="ms-2 text-lg" name="arrow-right" />
                            </Button>
                            <Button v-else-if="labRequestForm.items.length" type="button" size="rg" variant="white-outline" @click="paracliniqueTab = 'lab'">Finaliser les analyses ({{ labRequestForm.items.length }})<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                            <Button v-else-if="imagingRequestForm.items.length" type="button" size="rg" variant="white-outline" @click="paracliniqueTab = 'imaging'">Finaliser l’imagerie ({{ imagingRequestForm.items.length }})<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                            <Button v-else :as="Link" :href="stepUrl('diagnostic')" size="rg">Continuer vers le diagnostic<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                        </div>
                    </div>
                </Card>

                <div v-if="current_step === 'ordonnance' && capabilities.can_view_care_orders" class="grid grid-cols-2 gap-1 rounded-lg border border-gray-200 bg-gray-100 p-1 shadow-sm dark:border-gray-800 dark:bg-gray-900" role="tablist" aria-label="Type de prescription">
                    <button type="button" role="tab" :aria-selected="prescriptionTab === 'medicines'" :class="['flex h-11 items-center justify-center gap-2 rounded-md px-4 text-sm font-semibold transition-all', prescriptionTab === 'medicines' ? 'bg-white text-primary-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-950 dark:text-primary-300 dark:ring-gray-800' : 'text-slate-500 hover:bg-white/70 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-gray-950/60 dark:hover:text-slate-200']" @click="prescriptionTab = 'medicines'">
                        <Icon class="text-base" name="capsule" />
                        <span>Médicaments</span>
                        <span v-if="prescriptionForm.lines.length" class="rounded-full bg-primary-50 px-2 py-0.5 text-[10px] font-bold tabular-nums text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">{{ prescriptionForm.lines.length }}</span>
                    </button>
                    <button type="button" role="tab" :aria-selected="prescriptionTab === 'care'" :class="['flex h-11 items-center justify-center gap-2 rounded-md px-4 text-sm font-semibold transition-all', prescriptionTab === 'care' ? 'bg-white text-primary-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-950 dark:text-primary-300 dark:ring-gray-800' : 'text-slate-500 hover:bg-white/70 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-gray-950/60 dark:hover:text-slate-200']" @click="prescriptionTab = 'care'">
                        <Icon class="text-base" name="activity" />
                        <span>Soins</span>
                        <span v-if="careOrderForm.items.length" class="rounded-full bg-primary-50 px-2 py-0.5 text-[10px] font-bold tabular-nums text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">{{ careOrderForm.items.length }}</span>
                    </button>
                </div>

                <Card v-if="current_step === 'ordonnance' && capabilities.can_view_care_orders && prescriptionTab === 'care'" class="w-full overflow-hidden shadow-sm">
                    <div class="flex items-start justify-between gap-4 border-b border-gray-200 bg-gray-50/50 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/20">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300"><Icon class="text-lg" name="activity" /></span>
                            <div>
                                <h2 class="text-sm font-bold text-slate-700 dark:text-white">Prescription de soins</h2>
                                <p class="mt-1 text-xs text-slate-400">Sélectionnez les actes à transmettre à l’équipe Soins pour ce passage.</p>
                            </div>
                        </div>
                        <span v-if="careOrderForm.items.length" class="shrink-0 rounded-full border border-primary-100 bg-white px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-primary-700 dark:border-primary-900 dark:bg-gray-950 dark:text-primary-300">{{ careOrderForm.items.length }} acte(s)</span>
                    </div>

                    <div v-if="consultation.care_orders?.length" class="space-y-2 border-b border-gray-200 p-5 dark:border-gray-900">
                        <div v-for="order in consultation.care_orders" :key="order.uuid" class="rounded border border-gray-200 p-3 dark:border-gray-800">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs font-semibold text-slate-700 dark:text-white">{{ formatDateTime(order.ordered_at) }} — Dr {{ order.requested_by }}</p>
                                <span :class="careOrderStatusBadgeClass(order.status)">{{ careOrderStatusLabel(order.status) }}</span>
                            </div>
                            <ul class="mt-2 space-y-1.5">
                                <li v-for="item in order.items" :key="item.uuid" class="flex items-center justify-between gap-3 text-xs">
                                    <span class="font-semibold text-slate-700 dark:text-white">{{ item.name }}</span>
                                    <span v-if="item.not_performed_at" class="font-semibold text-red-600 dark:text-red-300">Non réalisé</span>
                                    <span v-else-if="Number(item.remaining_quantity) <= 0" class="inline-flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-300"><Icon name="check-circle" />Réalisé</span>
                                    <span v-else class="text-slate-400">Demandé {{ item.quantity }} · Réalisé {{ item.realized_quantity }} · Reste {{ item.remaining_quantity }}</span>
                                </li>
                            </ul>
                            <p v-if="order.instructions" class="mt-1.5 text-[11px] text-slate-400">{{ order.instructions }}</p>
                            <p class="mt-1.5 text-[11px] font-semibold text-slate-500 dark:text-slate-300">Retour Médecine : {{ order.requires_return_to_medicine ? 'Oui' : 'Non' }}</p>
                        </div>
                    </div>

                    <form v-if="capabilities.can_create_care_order" id="care-order-form" class="space-y-5 p-5" @submit.prevent="submitCareOrder">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Ajouter un acte de soins</label>
                            <IconInput v-model="careOrderSearch" icon="search" placeholder="Injection, perfusion, pansement…" />
                            <div v-if="careOrderSearch.trim() && filteredCareOrderCatalog.length" class="mt-2 max-h-52 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg dark:divide-gray-900 dark:border-gray-800 dark:bg-gray-950">
                                <button v-for="item in filteredCareOrderCatalog" :key="item.uuid" type="button" :disabled="isCareOrderItemSelected(item)" class="flex w-full items-center justify-between gap-4 px-3 py-2.5 text-start transition-colors hover:bg-gray-50 disabled:cursor-default disabled:bg-gray-50/70 dark:hover:bg-gray-1000 dark:disabled:bg-gray-1000/60" @click="addCareOrderItem(item)">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-slate-700 dark:text-white">{{ item.name }}</span>
                                        <span v-if="item.code" class="mt-0.5 block font-mono text-[10px] text-slate-400">{{ item.code }}</span>
                                    </span>
                                    <span v-if="isCareOrderItemSelected(item)" class="inline-flex shrink-0 items-center gap-1 text-[11px] font-semibold text-primary-700 dark:text-primary-300"><Icon name="check" />Ajouté</span>
                                    <span v-else class="flex h-7 w-7 shrink-0 items-center justify-center rounded border border-gray-200 text-primary-600 dark:border-gray-800 dark:text-primary-300"><Icon name="plus" /></span>
                                </button>
                            </div>
                            <p v-else-if="careOrderSearch.trim()" class="mt-2 text-xs text-slate-400">Aucun acte prescriptible ne correspond.</p>
                            <FormError :message="careOrderForm.errors.items" />
                        </div>
                        <div v-if="careOrderForm.items.length" class="overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                            <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-gray-800 dark:bg-gray-1000/30">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Actes demandés</p>
                                <span class="text-xs text-slate-400">Quantité ajustable</span>
                            </div>
                            <div v-for="(line, index) in careOrderForm.items" :key="line.catalog_item_uuid" class="grid grid-cols-[minmax(0,1fr)_6.5rem_2.25rem] items-end gap-3 border-b border-gray-100 px-4 py-3 last:border-b-0 dark:border-gray-900">
                                <div class="flex min-w-0 items-center gap-3 self-center">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300"><Icon name="activity" /></span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-slate-700 dark:text-white">{{ line.name }}</span>
                                        <span v-if="line.code" class="mt-0.5 block truncate font-mono text-[10px] text-slate-400">{{ line.code }}</span>
                                    </span>
                                </div>
                                <div>
                                    <label :for="`care-order-quantity-${index}`" class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-400">Quantité</label>
                                    <Input :id="`care-order-quantity-${index}`" v-model="line.quantity" type="number" min="1" step="1" inputmode="numeric" aria-label="Quantité" />
                                    <FormError :message="careOrderForm.errors[`items.${index}.quantity`]" />
                                </div>
                                <Button type="button" size="rg" icon variant="danger-outline" title="Retirer cet acte" :aria-label="`Retirer ${line.name}`" @click="removeCareOrderItem(line)"><Icon name="trash" /></Button>
                            </div>
                        </div>
                        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(22rem,0.8fr)]">
                            <div>
                                <label for="care_order_instructions" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Instructions pour l’équipe Soins</label>
                                <textarea id="care_order_instructions" v-model="careOrderForm.instructions" rows="5" :class="textareaClass" placeholder="Précisez la voie, la fréquence, les précautions ou toute consigne utile…" />
                                <FormError :message="careOrderForm.errors.instructions" />
                            </div>
                            <fieldset>
                                <legend class="mb-1.5 text-sm font-semibold text-slate-700 dark:text-white">Parcours après réalisation</legend>
                                <div class="grid gap-2">
                                    <label :class="['flex cursor-pointer items-start gap-3 rounded-md border p-3 transition-colors', careOrderForm.requires_return_to_medicine === true ? 'border-primary-500 bg-primary-50/50 ring-1 ring-primary-100 dark:bg-primary-950/15 dark:ring-primary-900' : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950 dark:hover:border-gray-700']">
                                        <input class="sr-only" type="radio" :checked="careOrderForm.requires_return_to_medicine === true" @change="careOrderForm.requires_return_to_medicine = true">
                                        <span :class="['mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full', careOrderForm.requires_return_to_medicine === true ? 'bg-primary-600 text-white' : 'bg-gray-100 text-slate-400 dark:bg-gray-900']"><Icon name="arrow-left" /></span>
                                        <span><span class="block text-sm font-semibold text-slate-700 dark:text-white">Retour en Médecine</span><span class="mt-0.5 block text-xs leading-5 text-slate-400">Le patient revient au médecin après les soins.</span></span>
                                    </label>
                                    <label :class="['flex cursor-pointer items-start gap-3 rounded-md border p-3 transition-colors', careOrderForm.requires_return_to_medicine === false ? 'border-primary-500 bg-primary-50/50 ring-1 ring-primary-100 dark:bg-primary-950/15 dark:ring-primary-900' : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950 dark:hover:border-gray-700']">
                                        <input class="sr-only" type="radio" :checked="careOrderForm.requires_return_to_medicine === false" @change="careOrderForm.requires_return_to_medicine = false">
                                        <span :class="['mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full', careOrderForm.requires_return_to_medicine === false ? 'bg-primary-600 text-white' : 'bg-gray-100 text-slate-400 dark:bg-gray-900']"><Icon name="check-circle" /></span>
                                        <span><span class="block text-sm font-semibold text-slate-700 dark:text-white">Fin du parcours prévue</span><span class="mt-0.5 block text-xs leading-5 text-slate-400">Les Soins terminent le parcours clinique prévu.</span></span>
                                    </label>
                                </div>
                                <FormError :message="careOrderForm.errors.requires_return_to_medicine" />
                            </fieldset>
                        </div>
                        <div class="flex items-center gap-2 border-t border-gray-200 pt-4 text-xs text-slate-400 dark:border-gray-900">
                            <Icon class="text-base text-primary-600 dark:text-primary-300" name="info" />
                            <span>L’envoi crée l’orientation vers Soins et transmet les actes ainsi que vos instructions.</span>
                        </div>
                    </form>
                </Card>

                <Card v-if="current_step === 'diagnostic'" class="w-full overflow-hidden shadow-sm">
                    <div class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="clipboard" /></span>
                        <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Diagnostic</h2><p class="mt-1 text-xs text-slate-400">Seules les versions actives sont affichées. Les rectifications restent accessibles dans l’historique.</p></div>
                    </div>

                    <div :class="['border-b border-gray-200 px-5 py-5 dark:border-gray-900', latestFinalDiagnosisEntry ? 'bg-emerald-50/50 dark:bg-emerald-950/10' : 'bg-gray-50/60 dark:bg-gray-1000/20']">
                        <template v-if="latestFinalDiagnosisEntry">
                            <p class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400"><Icon class="text-xs" name="check-circle" />Diagnostic retenu</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                <p class="text-base font-bold leading-6 text-slate-800 dark:text-white">{{ latestFinalDiagnosisEntry.description }}</p>
                                <span class="rounded border border-gray-200 bg-white px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-500 dark:border-gray-800 dark:bg-gray-950">{{ latestFinalDiagnosisEntry.source_label }}</span>
                                <span v-if="latestFinalDiagnosisEntry.code" class="font-mono text-[11px] font-semibold text-slate-400">{{ latestFinalDiagnosisEntry.code }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ latestFinalDiagnosisEntry.recorded_by }} · {{ formatDateTime(latestFinalDiagnosisEntry.recorded_at) }}</p>
                                <div v-if="latestFinalDiagnosisEntry.can_edit || latestFinalDiagnosisEntry.can_cancel" class="flex shrink-0 gap-1.5">
                                    <Button v-if="latestFinalDiagnosisEntry.can_edit" type="button" size="sm" icon variant="white-outline" title="Modifier ce diagnostic" :aria-label="`Modifier le diagnostic ${latestFinalDiagnosisEntry.description}`" @click="startDiagnosisEdit(latestFinalDiagnosisEntry)"><Icon class="text-base" name="edit" /></Button>
                                    <Button v-if="latestFinalDiagnosisEntry.can_cancel" type="button" size="sm" icon variant="danger-outline" title="Annuler ce diagnostic" :aria-label="`Annuler le diagnostic ${latestFinalDiagnosisEntry.description}`" :disabled="diagnosisCancellationForm.processing" @click="openDiagnosisCancellation(latestFinalDiagnosisEntry)"><Icon class="text-base" name="trash" /></Button>
                                </div>
                            </div>
                        </template>
                        <template v-else>
                            <div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-slate-400 shadow-sm dark:bg-gray-950"><Icon class="text-base" name="search" /></span>
                                <div>
                                    <p class="text-sm font-bold text-slate-600 dark:text-slate-300">Évaluation en cours</p>
                                    <p class="text-xs text-slate-400">{{ associatedHypotheses.length ? `${associatedHypotheses.length} hypothèse(s) posée(s) — aucun diagnostic final retenu.` : 'Aucune hypothèse ni diagnostic final enregistré pour l’instant.' }}</p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="p-5">
                        <p class="mb-3 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Fil diagnostique</p>

                        <ol v-if="timelineEntries.length" class="relative space-y-4 border-s-2 border-gray-100 ps-5 dark:border-gray-900">
                            <li v-for="diagnosis in timelineEntries" :key="diagnosis.id" class="relative">
                                <span :class="['absolute -start-[25px] top-1 h-2.5 w-2.5 rounded-full ring-4 ring-white dark:ring-gray-950', diagnosis.type === 'FINAL' ? 'bg-emerald-500' : 'bg-amber-500']" />
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span :class="['inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide', diagnosis.type === 'FINAL' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300']">{{ diagnosis.type === 'FINAL' ? 'Diagnostic final' : 'Hypothèse' }}</span>
                                            <span class="rounded border border-gray-200 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-400 dark:border-gray-800">{{ diagnosis.source_label }}</span>
                                            <span v-if="diagnosis.code" class="font-mono text-[10px] font-semibold text-slate-400">{{ diagnosis.code }}</span>
                                        </div>
                                        <p class="mt-1 text-sm font-semibold leading-5 text-slate-700 dark:text-white">{{ diagnosis.description }}</p>
                                        <p v-if="diagnosis.notes" class="mt-1 text-xs leading-5 text-slate-500">{{ diagnosis.notes }}</p>
                                        <p class="mt-0.5 text-[11px] text-slate-400">{{ diagnosis.recorded_by }} · {{ formatDateTime(diagnosis.recorded_at) }}</p>
                                    </div>
                                    <div v-if="diagnosis.can_edit || diagnosis.can_cancel" class="flex shrink-0 gap-1.5">
                                        <Button v-if="diagnosis.can_edit" type="button" size="sm" icon variant="white-outline" title="Modifier ce diagnostic" :aria-label="`Modifier le diagnostic ${diagnosis.description}`" @click="startDiagnosisEdit(diagnosis)"><Icon class="text-base" name="edit" /></Button>
                                        <Button v-if="diagnosis.can_cancel" type="button" size="sm" icon variant="danger-outline" title="Annuler ce diagnostic" :aria-label="`Annuler le diagnostic ${diagnosis.description}`" :disabled="diagnosisCancellationForm.processing" @click="openDiagnosisCancellation(diagnosis)"><Icon class="text-base" name="trash" /></Button>
                                    </div>
                                </div>
                                <form v-if="editingDiagnosisId === diagnosis.id" class="mt-2 rounded-md border border-gray-200 bg-gray-50/70 p-3 dark:border-gray-800 dark:bg-gray-1000/40" @submit.prevent="updateDiagnosis">
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
                            </li>
                        </ol>
                        <p v-else-if="!finalDiagnoses.length" class="text-xs text-slate-400">Aucune hypothèse enregistrée pour l’instant.</p>
                        <p v-else class="text-xs text-slate-400">Aucune autre entrée active dans le fil diagnostique.</p>

                        <div v-if="archivedDiagnoses.length" class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-900">
                            <button type="button" class="flex w-full items-center justify-between gap-3 text-left" :aria-expanded="diagnosisHistoryOpen" @click="diagnosisHistoryOpen = !diagnosisHistoryOpen">
                                <span class="flex min-w-0 items-center gap-2.5">
                                    <Icon class="shrink-0 text-base text-slate-400" name="history" />
                                    <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ diagnosisHistoryOpen ? 'Masquer' : 'Afficher' }} les versions rectifiées</span>
                                    <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-bold text-slate-500 dark:bg-gray-800 dark:text-slate-300">{{ archivedDiagnoses.length }}</span>
                                </span>
                                <Icon :class="['shrink-0 text-base text-slate-400 transition-transform', diagnosisHistoryOpen ? 'rotate-180' : '']" name="chevron-down" />
                            </button>
                            <ol v-if="diagnosisHistoryOpen" class="relative mt-3 space-y-3 border-s-2 border-dashed border-gray-200 ps-5 dark:border-gray-800">
                                <li v-for="diagnosis in archivedDiagnoses" :key="diagnosis.id" class="relative">
                                    <span class="absolute -start-[21px] top-1 h-2 w-2 rounded-full bg-gray-300 ring-4 ring-white dark:bg-gray-700 dark:ring-gray-950" />
                                    <div class="flex flex-wrap items-center gap-1.5"><span class="inline-flex rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500 dark:bg-gray-800 dark:text-slate-300">{{ diagnosis.type_label }}</span><span class="rounded border border-gray-200 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-400 dark:border-gray-800">{{ diagnosis.source_label }}</span><span v-if="diagnosis.code" class="font-mono text-[10px] text-slate-400">{{ diagnosis.code }}</span></div>
                                    <p class="mt-1 text-sm font-medium leading-5 text-slate-400 line-through">{{ diagnosis.description }}</p>
                                    <p class="mt-0.5 text-[11px] text-slate-400">{{ diagnosis.correction ? 'Rectifié' : 'Annulé' }} par {{ diagnosis.cancelled_by }} · {{ formatDateTime(diagnosis.cancelled_at) }}</p>
                                </li>
                            </ol>
                        </div>
                        <FormError class="mt-3" :message="diagnosisCancellationForm.errors.diagnosis_id" />
                    </div>

                    <section v-if="capabilities.can_create_diagnosis" class="border-t border-gray-200 bg-gray-50/50 px-5 py-5 dark:border-gray-900 dark:bg-gray-1000/30">
                        <div class="mx-auto max-w-4xl space-y-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div><h3 class="text-xs font-bold uppercase tracking-wide text-slate-700 dark:text-white">Ajouter un diagnostic</h3><p class="mt-1 text-xs text-slate-400">Sélectionnez le type avant de rechercher dans le référentiel.</p></div>
                                <fieldset class="inline-flex self-start rounded-md border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-gray-950" aria-label="Type du diagnostic">
                                    <label v-for="option in options.diagnosis_types" :key="option.value" :class="['cursor-pointer rounded px-3 py-2 text-xs font-bold transition-colors', diagnosisForm.type === option.value ? 'bg-slate-700 text-white shadow-sm dark:bg-slate-200 dark:text-slate-900' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400']">
                                        <input v-model="diagnosisForm.type" class="sr-only" type="radio" name="diagnosis-type" :value="option.value">
                                        {{ option.value === 'FINAL' ? 'Diagnostic final' : 'Hypothèse diagnostique' }}
                                    </label>
                                </fieldset>
                            </div>

                            <template v-if="!diagnosisManualMode">
                                <div>
                                    <label for="diagnosis_catalog_search" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Recherche dans le catalogue</label>
                                    <IconInput id="diagnosis_catalog_search" v-model="diagnosisSearch" icon="search" autocomplete="off" placeholder="Rechercher par diagnostic ou code…" />
                                    <p v-if="diagnosisSearch.trim().length === 1" class="mt-1.5 text-xs text-slate-400">Saisissez au moins 2 caractères.</p>
                                    <p v-if="diagnosisSearchLoading" class="mt-1.5 text-xs text-slate-400">Recherche en cours…</p>
                                    <p v-if="diagnosisSearchError" class="mt-1.5 text-xs text-red-500">{{ diagnosisSearchError }}</p>
                                </div>

                                <div v-if="diagnosisResults.length" class="overflow-hidden rounded-md border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
                                    <button v-for="diagnostic in diagnosisResults" :key="diagnostic.uuid" type="button" class="flex w-full items-center gap-3 border-b border-gray-100 px-4 py-3 text-left transition-colors last:border-0 hover:bg-gray-50 dark:border-gray-900 dark:hover:bg-gray-900" :disabled="diagnosisForm.processing" @click="addCatalogDiagnosis(diagnostic)">
                                        <span class="w-20 shrink-0 font-mono text-xs font-bold text-slate-400">{{ diagnostic.code || '—' }}</span>
                                        <span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-slate-700 dark:text-white">{{ diagnostic.name }}</span><span v-if="diagnostic.category" class="mt-0.5 block text-[11px] text-slate-400">{{ diagnostic.category }}</span></span>
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded border border-gray-200 text-primary-600 dark:border-gray-800"><Icon name="plus" /></span>
                                    </button>
                                </div>
                                <p v-else-if="diagnosisSearch.trim().length >= 2 && !diagnosisSearchLoading && !diagnosisSearchError" class="rounded-md border border-dashed border-gray-300 px-4 py-5 text-center text-xs text-slate-400 dark:border-gray-800">Aucun diagnostic actif ne correspond à cette recherche.</p>

                                <div class="flex items-center gap-3"><span class="h-px flex-1 bg-gray-200 dark:bg-gray-800"></span><span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Diagnostic introuvable ?</span><span class="h-px flex-1 bg-gray-200 dark:bg-gray-800"></span></div>
                                <Button type="button" size="rg" variant="white-outline" @click="diagnosisManualMode = true"><Icon class="me-2 text-base" name="edit" />Saisie manuelle</Button>
                            </template>

                            <form v-else class="rounded-md border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-950" @submit.prevent="addManualDiagnosis">
                                <div class="mb-4 flex items-start justify-between gap-3"><div><p class="text-sm font-bold text-slate-700 dark:text-white">Diagnostic manuel</p><p class="mt-1 text-xs text-slate-400">Cette saisie reste dans ce dossier et n’alimente pas automatiquement le catalogue.</p></div><button type="button" class="text-xs font-semibold text-slate-500 hover:text-slate-700" @click="diagnosisManualMode = false; diagnosisForm.clearErrors()">Revenir au catalogue</button></div>
                                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                    <div><label for="manual_diagnosis_name" class="mb-1.5 block text-xs font-semibold text-slate-600">Libellé *</label><Input id="manual_diagnosis_name" v-model="diagnosisForm.description" required placeholder="Diagnostic ou hypothèse clinique" /></div>
                                    <div><label for="manual_diagnosis_code" class="mb-1.5 block text-xs font-semibold text-slate-600">Code facultatif</label><Input id="manual_diagnosis_code" v-model="diagnosisForm.manual_code" placeholder="Code local" /></div>
                                    <div class="sm:col-span-2"><label for="manual_diagnosis_notes" class="mb-1.5 block text-xs font-semibold text-slate-600">Notes</label><textarea id="manual_diagnosis_notes" v-model="diagnosisForm.notes" rows="2" :class="textareaClass" placeholder="Précisions cliniques facultatives" /></div>
                                </div>
                                <div class="mt-4 flex justify-end"><Button type="submit" size="rg" :disabled="diagnosisForm.processing"><Icon class="me-2 text-base" name="plus" />Enregistrer le diagnostic</Button></div>
                            </form>
                            <FormError :message="diagnosisForm.errors.diagnostic_catalog_uuid || diagnosisForm.errors.description || diagnosisForm.errors.manual_code || diagnosisForm.errors.notes || diagnosisForm.errors.type" />
                        </div>
                    </section>
                </Card>

                <Card v-if="current_step === 'ordonnance' && prescriptionTab === 'medicines'" class="w-full overflow-hidden shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-sm font-bold text-slate-700 dark:text-white">Prescription — Médicaments</h2>
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

                    <form v-if="capabilities.can_create_prescription" id="medicine-prescription-form" class="border-t border-gray-200 bg-gray-50/50 p-5 dark:border-gray-900 dark:bg-gray-1000/30" @submit.prevent="addPrescription">
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

                        <div class="mt-4 flex items-start gap-2 border-t border-gray-200 pt-4 text-xs text-slate-400 dark:border-gray-900">
                            <Icon class="mt-0.5 shrink-0 text-base text-primary-600" name="shield-check" />
                            <p>La validation contrôle à nouveau le stock, réserve les quantités disponibles puis ouvre l’étape Décision.</p>
                        </div>
                        <FormError :message="prescriptionForm.errors.lines" />
                    </form>
                    <div v-else-if="!capabilities.can_view_pharmacy_availability" class="border-t border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">Votre compte ne dispose pas du droit de consulter la disponibilité Pharmacie. Aucune ordonnance ne peut être créée depuis cet écran.</div>
                </Card>

                <Card v-if="current_step === 'decision'" class="w-full overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Quelle est la suite de la prise en charge ?</h2>
                        <p class="mt-1 text-xs text-slate-400">La décision médicale ne réalise aucun encaissement et ne ferme pas le passage administratif.</p>
                    </div>

                    <div v-if="!medical_discharge && !showDischargeForm && !decisionChoice" class="p-5">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <button
                                v-for="card in decisionCards.filter((c) => capabilities[c.capability])"
                                :key="card.key"
                                type="button"
                                class="flex items-center gap-3 rounded border border-gray-200 bg-white px-4 py-3 text-start transition-colors hover:border-primary-300 hover:bg-primary-50/40 dark:border-gray-800 dark:bg-gray-950 dark:hover:border-primary-800 dark:hover:bg-primary-950/10"
                                @click="chooseDecision(card.key)"
                            >
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" :name="card.icon" /></span>
                                <span class="text-sm font-semibold text-slate-700 dark:text-white">{{ card.label }}</span>
                            </button>
                            <button v-if="capabilities.can_defer_decision" type="button" class="flex items-center gap-3 rounded border border-dashed border-gray-300 px-4 py-3 text-start text-slate-500 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-1000" @click="chooseDecision('CONTINUE')">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="arrow-right" /></span>
                                <span class="text-sm font-semibold">Continuer la prise en charge<span class="mt-0.5 block text-xs font-normal text-slate-400">{{ pending_reasons.join(' · ') }}</span></span>
                            </button>
                        </div>

                        <p v-if="!capabilities.can_defer_decision" class="mt-4 text-xs text-slate-400">Aucun élément clinique en attente (analyse, imagerie ou soins) ne justifie de différer la décision : choisissez une conduite ci-dessus.</p>
                        <p v-if="decisionDeferred" class="mt-4 text-xs text-slate-400">Aucune décision n’a été enregistrée. Revenez sur cette étape lorsque la prise en charge sera prête à conclure.</p>

                        <div v-if="consultation.decision" class="mt-4 flex flex-wrap items-center gap-2 rounded border border-gray-200 bg-gray-50/60 px-3 py-2 text-xs dark:border-gray-800 dark:bg-gray-1000/30">
                            <span class="font-semibold text-slate-600 dark:text-slate-300">Conduite enregistrée : {{ consultation.decision_label }}</span>
                            <span v-if="!connectedDecisions.includes(consultation.decision)" class="inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-200"><Icon class="text-xs" name="alert-circle" />Workflow non connecté — donnée seule</span>
                        </div>

                        <div v-if="consultation.surgical_requests?.length || consultation.referrals?.length" class="mt-4 space-y-2">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Demandes déjà transmises</p>
                            <div v-for="request in consultation.surgical_requests" :key="`surg-${request.uuid}`" class="rounded border border-gray-200 px-3 py-2 text-xs dark:border-gray-800">
                                <span class="font-semibold text-slate-700 dark:text-white">Chirurgie — {{ request.procedure_name }}</span>
                                <span class="ms-2 text-slate-400">{{ request.status }}</span>
                            </div>
                            <div v-for="referral in consultation.referrals" :key="referral.uuid" class="rounded border border-gray-200 px-3 py-2 text-xs dark:border-gray-800">
                                <span class="font-semibold text-slate-700 dark:text-white">{{ referral.destination_label }}</span>
                                <span class="ms-2 text-slate-400">{{ referral.status_label }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="decisionChoice && decisionChoice !== 'SURGERY'" class="space-y-5 p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"><Icon class="text-lg" :name="decisionCards.find((c) => c.key === decisionChoice)?.icon" /></span>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-700 dark:text-white">{{ decisionCards.find((c) => c.key === decisionChoice)?.label }}</h3>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-400">Cette demande crée une orientation vers le service destinataire sans fermer le passage administratif.</p>
                                </div>
                            </div>
                            <button type="button" class="self-start rounded-md px-2.5 py-1.5 text-xs font-semibold text-slate-500 transition-colors hover:bg-gray-100 hover:text-slate-700 dark:hover:bg-gray-900 dark:hover:text-white" @click="closeDecisionChoice"><Icon class="me-1 text-sm" name="cross" />Annuler</button>
                        </div>

                        <template v-if="decisionChoice === 'TRANSFER'">
                            <div class="flex items-start gap-3 rounded-lg border border-primary-100 bg-primary-50/50 px-4 py-3 dark:border-primary-900 dark:bg-primary-950/20">
                                <Icon class="mt-0.5 shrink-0 text-base text-primary-700 dark:text-primary-300" name="info" />
                                <div>
                                    <p class="text-xs font-bold text-primary-800 dark:text-primary-200">Données reprises du dossier clinique</p>
                                    <p class="mt-0.5 text-xs leading-5 text-primary-700/80 dark:text-primary-300/80">Le motif, le diagnostic, l’état clinique et la priorité sont préremplis avec les informations disponibles. Le médecin doit les relire et peut les corriger avant transmission.</p>
                                </div>
                            </div>

                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                                <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="block text-sm font-semibold text-slate-700 dark:text-white">Destination / établissement *</p>
                                        <p class="mt-0.5 text-xs text-slate-400">Le site actuellement utilisé n’est pas proposé comme destination.</p>
                                    </div>
                                    <span class="self-start rounded-md bg-gray-100 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-500 dark:bg-gray-900 dark:text-slate-300">Site actif : {{ page.props.site?.name }}</span>
                                </div>
                                <div id="referral_destination_etablissement" class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Destination du transfert">
                                    <label
                                        v-for="site in otherSiteOptions"
                                        :key="site.code"
                                        :class="['flex cursor-pointer items-center gap-3 rounded-lg border px-3.5 py-3 transition-all', referralTransferDestinationChoice === site.destination ? 'border-primary-500 bg-primary-50/60 text-primary-800 ring-2 ring-primary-100 dark:border-primary-700 dark:bg-primary-950/30 dark:text-primary-200 dark:ring-primary-950' : 'border-gray-200 bg-white text-slate-600 hover:border-primary-300 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 dark:hover:border-primary-800 dark:hover:bg-gray-1000']"
                                    >
                                        <input v-model="referralTransferDestinationChoice" type="radio" name="referral_destination" :value="site.destination" class="sr-only" />
                                        <span :class="['flex size-9 shrink-0 items-center justify-center rounded-md', referralTransferDestinationChoice === site.destination ? 'bg-primary-600 text-white' : 'bg-gray-100 text-slate-400 dark:bg-gray-900 dark:text-slate-300']"><Icon class="text-lg" name="building" /></span>
                                        <span class="min-w-0"><span class="block truncate text-sm font-bold">{{ site.name }}</span><span class="mt-0.5 block truncate text-[11px] text-slate-400">Clinique Saint Georges</span></span>
                                        <Icon v-if="referralTransferDestinationChoice === site.destination" class="ms-auto shrink-0 text-primary-600 dark:text-primary-300" name="check-circle-fill" />
                                    </label>
                                    <label :class="['flex cursor-pointer items-center gap-3 rounded-lg border px-3.5 py-3 transition-all', referralTransferDestinationChoice === 'OTHER' ? 'border-primary-500 bg-primary-50/60 text-primary-800 ring-2 ring-primary-100 dark:border-primary-700 dark:bg-primary-950/30 dark:text-primary-200 dark:ring-primary-950' : 'border-gray-200 bg-white text-slate-600 hover:border-primary-300 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 dark:hover:border-primary-800 dark:hover:bg-gray-1000']">
                                        <input v-model="referralTransferDestinationChoice" type="radio" name="referral_destination" value="OTHER" class="sr-only" />
                                        <span :class="['flex size-9 shrink-0 items-center justify-center rounded-md', referralTransferDestinationChoice === 'OTHER' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-slate-400 dark:bg-gray-900 dark:text-slate-300']"><Icon class="text-lg" name="edit" /></span>
                                        <span class="min-w-0"><span class="block truncate text-sm font-bold">Autre</span><span class="mt-0.5 block truncate text-[11px] text-slate-400">Établissement ou service</span></span>
                                        <Icon v-if="referralTransferDestinationChoice === 'OTHER'" class="ms-auto shrink-0 text-primary-600 dark:text-primary-300" name="check-circle-fill" />
                                    </label>
                                </div>
                                <div v-if="referralTransferDestinationChoice === 'OTHER'" class="mt-3 rounded-lg border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-1000/20">
                                    <label for="referral_destination_other" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Précisez la destination *</label>
                                    <Input id="referral_destination_other" v-model="referralTransferDestinationOther" maxlength="255" placeholder="Nom de l’établissement ou du service destinataire" />
                                </div>
                            </div>

                            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                                <section>
                                    <div class="flex items-center gap-2 border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/30">
                                        <span class="flex size-7 items-center justify-center rounded-md bg-white text-primary-600 ring-1 ring-gray-200 dark:bg-gray-950 dark:text-primary-300 dark:ring-gray-800"><Icon name="file-text" /></span>
                                        <div><h4 class="text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-200">Justification médicale</h4><p class="mt-0.5 text-[11px] text-slate-400">Pourquoi le transfert est nécessaire et sur quel diagnostic il repose.</p></div>
                                    </div>
                                    <div class="grid gap-4 p-4 lg:grid-cols-2">
                                        <div>
                                            <label for="referral_motif" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Motif du transfert *</label>
                                            <textarea id="referral_motif" v-model="referralFieldValues.motif" rows="3" maxlength="450" :class="textareaClass" placeholder="Motif médical justifiant le transfert" />
                                        </div>
                                        <div>
                                            <label for="referral_diagnostic" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Diagnostic</label>
                                            <textarea id="referral_diagnostic" v-model="referralFieldValues.diagnostic" rows="3" maxlength="450" :class="textareaClass" placeholder="Diagnostic actuel" />
                                        </div>
                                    </div>
                                </section>

                                <section class="border-t border-gray-200 dark:border-gray-800">
                                    <div class="flex items-center gap-2 border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/30">
                                        <span class="flex size-7 items-center justify-center rounded-md bg-white text-primary-600 ring-1 ring-gray-200 dark:bg-gray-950 dark:text-primary-300 dark:ring-gray-800"><Icon name="activity" /></span>
                                        <div><h4 class="text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-200">État et niveau de priorité</h4><p class="mt-0.5 text-[11px] text-slate-400">Situation clinique au départ et délai attendu de prise en charge.</p></div>
                                    </div>
                                    <div class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(17rem,0.5fr)]">
                                        <div>
                                            <label for="referral_etat_clinique" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">État clinique au transfert</label>
                                            <textarea id="referral_etat_clinique" v-model="referralFieldValues.etat_clinique" rows="4" maxlength="700" :class="textareaClass" placeholder="État clinique, examen et constantes utiles" />
                                        </div>
                                        <fieldset>
                                            <legend class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Priorité</legend>
                                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-1" role="radiogroup" aria-label="Priorité du transfert">
                                                <label :class="['flex cursor-pointer items-center gap-3 rounded-lg border px-3.5 py-3 transition-all', referralFieldValues.priorite === 'Normale' ? 'border-primary-500 bg-primary-50/60 text-primary-800 ring-2 ring-primary-100 dark:border-primary-700 dark:bg-primary-950/30 dark:text-primary-200 dark:ring-primary-950' : 'border-gray-200 bg-white text-slate-600 hover:border-primary-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 dark:hover:border-primary-800']">
                                                    <input v-model="referralFieldValues.priorite" type="radio" name="referral_priority" value="Normale" class="size-4 border-gray-300 text-primary-600 focus:ring-primary-200 dark:border-gray-700 dark:bg-gray-950 dark:focus:ring-primary-950" />
                                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon name="check-circle" /></span>
                                                    <span><span class="block text-sm font-bold">Normale</span><span class="mt-0.5 block text-[11px] text-slate-400">Transfert programmé</span></span>
                                                </label>
                                                <label :class="['flex cursor-pointer items-center gap-3 rounded-lg border px-3.5 py-3 transition-all', referralFieldValues.priorite === 'Urgente' ? 'border-red-400 bg-red-50/70 text-red-800 ring-2 ring-red-100 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200 dark:ring-red-950' : 'border-gray-200 bg-white text-slate-600 hover:border-red-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 dark:hover:border-red-900']">
                                                    <input v-model="referralFieldValues.priorite" type="radio" name="referral_priority" value="Urgente" class="size-4 border-gray-300 text-red-600 focus:ring-red-200 dark:border-gray-700 dark:bg-gray-950 dark:focus:ring-red-950" />
                                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-300"><Icon name="alert-fill" /></span>
                                                    <span><span class="block text-sm font-bold">Urgente</span><span class="mt-0.5 block text-[11px] text-slate-400">Prise en charge immédiate</span></span>
                                                </label>
                                            </div>
                                        </fieldset>
                                    </div>
                                </section>

                                <section class="border-t border-gray-200 dark:border-gray-800">
                                    <div class="flex items-center gap-2 border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/30">
                                        <span class="flex size-7 items-center justify-center rounded-md bg-white text-primary-600 ring-1 ring-gray-200 dark:bg-gray-950 dark:text-primary-300 dark:ring-gray-800"><Icon name="send" /></span>
                                        <div><h4 class="text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-200">Consignes destinataires</h4><p class="mt-0.5 text-[11px] text-slate-400">Informations utiles à la continuité de la prise en charge.</p></div>
                                    </div>
                                    <div class="grid gap-4 p-4 lg:grid-cols-2">
                                        <div>
                                            <label for="referral_recommandations" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Recommandations</label>
                                            <textarea id="referral_recommandations" v-model="referralFieldValues.recommandations" rows="3" maxlength="350" :class="textareaClass" placeholder="Consignes pour l’équipe destinataire" />
                                        </div>
                                        <div>
                                            <label for="referral_observations" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Observations complémentaires</label>
                                            <textarea id="referral_observations" v-model="referralFieldValues.observations" rows="3" maxlength="350" :class="textareaClass" placeholder="Autres informations utiles au transfert" />
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </template>

                        <div v-else class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                            <div class="grid gap-4 p-4 sm:grid-cols-2">
                                <template v-for="field in referralFieldsFor(decisionChoice)" :key="field.key">
                                    <fieldset v-if="field.key === 'priorite'">
                                        <legend class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Priorité</legend>
                                        <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Priorité de la demande">
                                            <label :class="['flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 transition-all', referralFieldValues.priorite === 'Normale' ? 'border-primary-500 bg-primary-50/60 text-primary-800 ring-2 ring-primary-100 dark:border-primary-700 dark:bg-primary-950/30 dark:text-primary-200 dark:ring-primary-950' : 'border-gray-200 bg-white text-slate-600 hover:border-primary-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 dark:hover:border-primary-800']">
                                                <input v-model="referralFieldValues.priorite" type="radio" name="referral_priority_other" value="Normale" class="size-4 border-gray-300 text-primary-600 focus:ring-primary-200 dark:border-gray-700 dark:bg-gray-950 dark:focus:ring-primary-950" />
                                                <span class="text-sm font-bold">Normale</span>
                                            </label>
                                            <label :class="['flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 transition-all', referralFieldValues.priorite === 'Urgente' ? 'border-red-400 bg-red-50/70 text-red-800 ring-2 ring-red-100 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200 dark:ring-red-950' : 'border-gray-200 bg-white text-slate-600 hover:border-red-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 dark:hover:border-red-900']">
                                                <input v-model="referralFieldValues.priorite" type="radio" name="referral_priority_other" value="Urgente" class="size-4 border-gray-300 text-red-600 focus:ring-red-200 dark:border-gray-700 dark:bg-gray-950 dark:focus:ring-red-950" />
                                                <span class="text-sm font-bold">Urgente</span>
                                            </label>
                                        </div>
                                    </fieldset>
                                    <div v-else :class="field.multiline ? 'sm:col-span-2' : ''">
                                        <label :for="`referral_${field.key}`" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">{{ field.label }}<span v-if="field.key === 'motif'"> *</span></label>
                                        <textarea v-if="field.multiline" :id="`referral_${field.key}`" v-model="referralFieldValues[field.key]" rows="3" maxlength="450" :class="textareaClass" :placeholder="field.placeholder" />
                                        <Input v-else :id="`referral_${field.key}`" v-model="referralFieldValues[field.key]" :placeholder="field.placeholder" />
                                    </div>
                                </template>
                            </div>
                        </div>
                        <FormError :message="referralForm.errors.reason" />
                        <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                            <p v-if="decisionChoice === 'TRANSFER'" class="text-xs text-slate-400"><Icon class="me-1 text-sm" name="shield-check" />La transmission conserve une copie structurée dans le dossier du passage.</p>
                            <span v-else />
                            <Button type="button" size="rg" :disabled="referralForm.processing || !referralComposedReason.trim() || !transferReferralReady" @click="submitReferral(decisionChoice)"><Icon class="me-2 text-lg" name="share" />{{ referralForm.processing ? 'Transmission…' : 'Transmettre la demande' }}</Button>
                        </div>
                    </div>

                    <form v-else-if="decisionChoice === 'SURGERY'" class="space-y-4 p-5" @submit.prevent="submitSurgicalReferral">
                        <div class="flex items-center justify-between"><h3 class="text-sm font-bold text-slate-700 dark:text-white">Demande de chirurgie</h3><button type="button" class="text-xs text-slate-400 hover:text-slate-600" @click="closeDecisionChoice">Annuler</button></div>
                        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                            <div class="grid gap-4 p-4">
                                <div>
                                    <label for="surgery_catalog" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Intervention envisagée *</label>
                                    <span class="relative block"><select id="surgery_catalog" v-model="surgicalReferralForm.catalog_item_uuid" :class="selectClass"><option value="">Sélectionner…</option><option v-for="item in options.surgery_catalog" :key="item.uuid" :value="item.uuid">{{ item.name }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span>
                                    <FormError :message="surgicalReferralForm.errors.catalog_item_uuid" />
                                </div>
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label for="surgery_diagnostic" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Diagnostic *</label>
                                        <textarea id="surgery_diagnostic" v-model="surgicalReferralForm.diagnostic" rows="3" :class="textareaClass" />
                                        <FormError :message="surgicalReferralForm.errors.diagnostic" />
                                    </div>
                                    <div>
                                        <label for="surgery_indication" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Indication chirurgicale</label>
                                        <textarea id="surgery_indication" v-model="surgicalReferralForm.indication" rows="3" :class="textareaClass" />
                                        <FormError :message="surgicalReferralForm.errors.indication" />
                                    </div>
                                </div>
                                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(14rem,0.6fr)]">
                                    <div>
                                        <label for="surgery_notes" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Notes</label>
                                        <Input id="surgery_notes" v-model="surgicalReferralForm.notes" />
                                    </div>
                                    <fieldset>
                                        <legend class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-white">Priorité *</legend>
                                        <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Priorité de la chirurgie">
                                            <label :class="['flex cursor-pointer flex-col items-center gap-1 rounded-lg border px-2 py-2.5 text-center transition-all', surgicalReferralForm.priority === 'LOW' ? 'border-slate-400 bg-slate-100 text-slate-800 ring-2 ring-slate-200 dark:border-slate-600 dark:bg-gray-900 dark:text-slate-200 dark:ring-gray-800' : 'border-gray-200 bg-white text-slate-600 hover:border-slate-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300']">
                                                <input v-model="surgicalReferralForm.priority" type="radio" name="surgery_priority" value="LOW" class="size-4 border-gray-300 text-slate-600 focus:ring-slate-200 dark:border-gray-700 dark:bg-gray-950" />
                                                <span class="text-xs font-bold">Basse</span>
                                            </label>
                                            <label :class="['flex cursor-pointer flex-col items-center gap-1 rounded-lg border px-2 py-2.5 text-center transition-all', surgicalReferralForm.priority === 'NORMAL' ? 'border-primary-500 bg-primary-50/60 text-primary-800 ring-2 ring-primary-100 dark:border-primary-700 dark:bg-primary-950/30 dark:text-primary-200 dark:ring-primary-950' : 'border-gray-200 bg-white text-slate-600 hover:border-primary-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300']">
                                                <input v-model="surgicalReferralForm.priority" type="radio" name="surgery_priority" value="NORMAL" class="size-4 border-gray-300 text-primary-600 focus:ring-primary-200 dark:border-gray-700 dark:bg-gray-950" />
                                                <span class="text-xs font-bold">Normale</span>
                                            </label>
                                            <label :class="['flex cursor-pointer flex-col items-center gap-1 rounded-lg border px-2 py-2.5 text-center transition-all', surgicalReferralForm.priority === 'URGENT' ? 'border-red-400 bg-red-50/70 text-red-800 ring-2 ring-red-100 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200 dark:ring-red-950' : 'border-gray-200 bg-white text-slate-600 hover:border-red-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300']">
                                                <input v-model="surgicalReferralForm.priority" type="radio" name="surgery_priority" value="URGENT" class="size-4 border-gray-300 text-red-600 focus:ring-red-200 dark:border-gray-700 dark:bg-gray-950" />
                                                <span class="text-xs font-bold">Urgente</span>
                                            </label>
                                        </div>
                                        <FormError :message="surgicalReferralForm.errors.priority" />
                                    </fieldset>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end border-t border-gray-200 pt-4 dark:border-gray-900">
                            <Button type="submit" size="rg" :disabled="surgicalReferralForm.processing"><Icon class="me-2 text-lg" name="check" />Transmettre la demande</Button>
                        </div>
                    </form>

                    <div v-else-if="medical_discharge" class="p-5">
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

                    <form v-else-if="showDischargeForm" class="space-y-5 p-5" @submit.prevent="submitDischarge">
                        <div class="flex items-start gap-2.5 rounded-md border border-amber-200 bg-amber-50/60 px-4 py-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                            <Icon class="mt-0.5 shrink-0 text-base" name="alert-circle" />
                            <p><strong>Action médicale définitive.</strong> Elle termine cette orientation Médecine, mais la Réception / Caisse conserve la responsabilité de la sortie administrative.</p>
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700 dark:text-white">Type de sortie *</label>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="option in options.discharge_types"
                                    :key="option.value"
                                    :title="option.label"
                                    :class="['inline-flex w-auto shrink-0 cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors',
                                        dischargeForm.type === option.value
                                            ? (option.value === 'DECEASED'
                                                ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950/20 dark:text-red-300'
                                                : 'border-primary-500 bg-primary-50/60 text-primary-700 dark:border-primary-700 dark:bg-primary-950/20 dark:text-primary-300')
                                            : 'border-gray-200 text-slate-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:border-gray-700 dark:hover:bg-gray-1000']"
                                >
                                    <input v-model="dischargeForm.type" type="radio" name="discharge_type" :value="option.value" class="sr-only" />
                                    <Icon class="shrink-0 text-lg" :name="dischargeTypeIcon(option.value)" />
                                    <span class="truncate whitespace-nowrap font-semibold leading-tight">{{ dischargeTypeShortLabel(option) }}</span>
                                </label>
                            </div>
                            <FormError class="mt-1.5" :message="dischargeForm.errors.type" />
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div :class="dischargeForm.type === 'TRANSFER' ? '' : 'md:col-span-2 md:max-w-xs'"><label for="discharged_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date et heure *</label><IconInput id="discharged_at" v-model="dischargeForm.discharged_at" icon="calendar" type="datetime-local" /><FormError :message="dischargeForm.errors.discharged_at" /></div>
                            <div v-if="dischargeForm.type === 'TRANSFER'">
                                <label for="transfer_destination" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Établissement / service destinataire *</label>
                                <span class="relative block"><select id="transfer_destination" v-model="transferDestinationChoice" :class="selectClass">
                                    <option value="">Choisir…</option>
                                    <option v-for="site in otherSiteOptions" :key="site.code" :value="site.destination">{{ site.destination }}</option>
                                    <option value="OTHER">Autre</option>
                                </select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span>
                                <Input v-if="transferDestinationChoice === 'OTHER'" v-model="transferDestinationOther" class="mt-2" placeholder="Précisez l’établissement ou le service" />
                                <FormError :message="dischargeForm.errors.transfer_destination" />
                            </div>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div><label for="final_diagnosis" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Diagnostic final *</label><textarea id="final_diagnosis" v-model="dischargeForm.final_diagnosis" rows="3" :class="textareaClass" /><FormError :message="dischargeForm.errors.final_diagnosis" /></div>
                            <div><label for="patient_condition" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">État du patient *</label><textarea id="patient_condition" v-model="dischargeForm.patient_condition" rows="3" :class="textareaClass" placeholder="Stable, amélioré, état clinique au départ…" /><FormError :message="dischargeForm.errors.patient_condition" /></div>
                        </div>
                        <div v-if="dischargeForm.type === 'DECEASED'" class="grid gap-4 rounded-md border border-red-100 bg-red-50/30 p-4 dark:border-red-950 dark:bg-red-950/10 md:grid-cols-2">
                            <div><label for="death_occurred_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date et heure du décès *</label><IconInput id="death_occurred_at" v-model="dischargeForm.death_occurred_at" icon="calendar" type="datetime-local" /><FormError :message="dischargeForm.errors.death_occurred_at" /></div>
                            <div><label for="death_place" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Lieu du décès *</label><Input id="death_place" v-model="dischargeForm.death_place" /><FormError :message="dischargeForm.errors.death_place" /></div>
                            <div class="md:col-span-2"><label for="death_causes" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Causes constatées du décès *</label><textarea id="death_causes" v-model="dischargeForm.death_causes" rows="3" :class="textareaClass" /><FormError :message="dischargeForm.errors.death_causes" /></div>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2"><div><label for="discharge_prescription" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Traitement de sortie</label><textarea id="discharge_prescription" v-model="dischargeForm.discharge_prescription" rows="4" :class="textareaClass" /></div><div><label for="recommendations" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Recommandations</label><textarea id="recommendations" v-model="dischargeForm.recommendations" rows="4" :class="textareaClass" /></div></div>
                        <div class="grid gap-4 md:grid-cols-2"><div><label for="follow_up_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Rendez-vous éventuel</label><IconInput id="follow_up_at" v-model="dischargeForm.follow_up_at" icon="calendar" type="datetime-local" /><FormError :message="dischargeForm.errors.follow_up_at" /></div><div><label for="discharge_observations" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Observations</label><Input id="discharge_observations" v-model="dischargeForm.observations" /></div></div>
                        <FormError :message="dischargeForm.errors.medical_discharge" />
                        <div class="flex justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-900"><Button type="button" size="rg" variant="white-outline" @click="showDischargeForm = false">Annuler</Button><Button type="submit" size="rg" :disabled="dischargeForm.processing"><Icon class="me-2 text-lg" name="check" />Confirmer la sortie médicale</Button></div>
                    </form>
                </Card>
            </main>

            <aside v-if="current_step === 'dossier'" class="space-y-4 xl:sticky xl:top-4 xl:col-span-4">
                <CareSummaryReadOnly v-if="care_record" :care-summary="care_record" />
                <Card v-else class="overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Transmission des Soins</h2><p class="mt-1 text-xs text-slate-400">Données relevées pendant ce passage.</p></div>
                    <p class="px-4 py-5 text-sm text-slate-400">{{ orientation.source_module === 'CARE' ? 'Le patient est passé par les Soins, mais aucune fiche n’a été enregistrée pour ce passage.' : 'Ce patient n’est pas passé par les Soins pour ce passage (orientation directe).' }}</p>
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

                <Card v-if="previous_consultations?.length" class="overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Consultations précédentes</h2><p class="mt-1 text-xs text-slate-400">Autres orientations Médecine de ce même passage.</p></div>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-900">
                        <li v-for="previous in previous_consultations" :key="previous.id" class="p-4">
                            <p class="text-xs font-semibold text-slate-700 dark:text-white">{{ formatDateTime(previous.consulted_at) }} — Dr {{ previous.doctor }}</p>
                            <ClinicalRichTextDisplay class="mt-1 line-clamp-2 text-sm text-slate-600 dark:text-slate-300" :html="previous.reason" />
                            <p v-if="previous.decision_label" class="mt-0.5 text-xs text-slate-400">{{ previous.decision_label }}</p>
                        </li>
                    </ul>
                </Card>

                <Card v-if="hasEmergencyContact" class="overflow-hidden shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Contact de ce passage</h2></div>
                    <div class="p-4"><p class="text-sm font-semibold text-slate-700 dark:text-white">{{ episode.emergency_contact.name || 'Nom non renseigné' }}</p><p v-if="episode.emergency_contact.relationship" class="mt-1 text-xs text-slate-400">{{ episode.emergency_contact.relationship }}</p><p v-if="episode.emergency_contact.phone" class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ episode.emergency_contact.phone }}</p><p v-if="episode.emergency_contact.email" class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ episode.emergency_contact.email }}</p></div>
                </Card>
            </aside>
        </div>

        <Card v-if="!['dossier', 'consultation', 'examen', 'paraclinique'].includes(current_step)" class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <Button v-if="previousStep" :as="Link" :href="stepUrl(previousStep.key)" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />{{ previousStep.label }}</Button>
                    <Button v-else :as="Link" href="/medicine" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Retour à la file</Button>
                </div>
                <div v-if="current_step === 'decision' && consultation.prescriptions.length" class="flex flex-wrap items-center justify-center gap-2">
                    <Button v-for="prescription in consultation.prescriptions" :key="prescription.uuid" :as="Link" :href="`/medicine/orientations/${orientation.uuid}/prescriptions/${prescription.uuid}/print`" target="_blank" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="printer" />Imprimer l’ordonnance<template v-if="consultation.prescriptions.length > 1"> ({{ prescription.lines.map((line) => line.medication_name).join(', ') }})</template></Button>
                </div>
                <p v-else-if="current_step === 'ordonnance' && prescriptionForm.lines.length" class="text-center text-xs font-semibold text-slate-500 dark:text-slate-300">{{ prescriptionForm.lines.length }} médicament(s) à valider avant de poursuivre</p>
                <p v-else-if="current_step === 'ordonnance' && careOrderForm.items.length" class="text-center text-xs font-semibold text-slate-500 dark:text-slate-300">{{ careOrderForm.items.length }} acte(s) à transmettre aux Soins</p>
                <p v-else class="text-center text-xs text-slate-400">Étape {{ currentStepIndex + 1 }} sur {{ wizardSteps.length }}<span v-if="current_step === 'ordonnance'"> · prescription selon indication médicale</span></p>
                <div class="flex justify-end">
                    <Button v-if="current_step === 'ordonnance' && prescriptionTab === 'medicines' && prescriptionForm.lines.length" type="submit" form="medicine-prescription-form" size="rg" :disabled="prescriptionForm.processing || !prescriptionStockIsValid"><Icon class="me-2 text-lg" name="file-text" />{{ prescriptionForm.processing ? 'Validation en cours…' : 'Valider, réserver et continuer' }}<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                    <Button v-else-if="current_step === 'ordonnance' && prescriptionForm.lines.length" type="button" size="rg" variant="white-outline" @click="prescriptionTab = 'medicines'">Finaliser la prescription ({{ prescriptionForm.lines.length }})<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                    <Button v-else-if="current_step === 'ordonnance' && prescriptionTab === 'care' && careOrderForm.items.length" type="submit" form="care-order-form" size="rg" :disabled="careOrderForm.processing"><Icon class="me-2 text-lg" name="activity" />{{ careOrderForm.processing ? 'Transmission en cours…' : 'Transmettre aux Soins' }}<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                    <Button v-else-if="current_step === 'ordonnance' && careOrderForm.items.length" type="button" size="rg" variant="white-outline" @click="prescriptionTab = 'care'">Finaliser la demande Soins ({{ careOrderForm.items.length }})<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                    <Button v-else-if="nextStep" :as="Link" :href="stepUrl(nextStep.key)" size="rg">{{ current_step === 'ordonnance' ? 'Continuer vers la décision' : nextStep.label }}<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                    <Button v-else :as="Link" href="/medicine" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="list" />Retour à la file</Button>
                </div>
            </div>
        </Card>

        <Dialog :open="showEmergencyConfirm" as="div" class="relative z-[1300]" @close="closeEmergencyConfirm">
            <div class="fixed inset-0 bg-slate-950/55 backdrop-blur-[1px]" aria-hidden="true"></div>
            <div class="fixed inset-0 overflow-y-auto p-4">
                <div class="flex min-h-full items-center justify-center">
                    <DialogPanel v-if="showEmergencyConfirm" class="w-full max-w-md overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-950">
                        <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-300"><Icon class="text-xl" name="activity" /></span>
                                <div class="min-w-0">
                                    <DialogTitle class="font-heading text-base font-bold text-slate-700 dark:text-white">Classer ce passage en urgence ?</DialogTitle>
                                    <p class="mt-1 text-xs leading-5 text-slate-400">Episode {{ episode.episode_number }}</p>
                                </div>
                            </div>
                            <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 transition-colors hover:bg-gray-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-50 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer la confirmation" :disabled="emergencyForm.processing" @click="closeEmergencyConfirm"><Icon class="text-xl" name="cross" /></button>
                        </header>
                        <div class="px-5 py-4">
                            <FormError :message="emergencyForm.errors.episode" />
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-50/60 px-5 py-4 dark:border-gray-800 dark:bg-gray-1000/30 sm:flex-row sm:justify-end">
                            <Button type="button" size="rg" variant="white-outline" :disabled="emergencyForm.processing" @click="closeEmergencyConfirm">Annuler</Button>
                            <Button type="button" size="rg" variant="danger" :disabled="emergencyForm.processing" @click="confirmMarkEmergency"><Icon class="me-2 text-base" name="activity" />{{ emergencyForm.processing ? 'Classement…' : 'Confirmer l’urgence' }}</Button>
                        </footer>
                    </DialogPanel>
                </div>
            </div>
        </Dialog>

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
