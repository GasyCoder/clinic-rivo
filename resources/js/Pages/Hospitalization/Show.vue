<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Select from '@/Components/Shadcn/Select.vue';
import FormError from '@/Components/UI/FormError.vue';
import VitalSignsStrip from '@/Components/Clinical/VitalSignsStrip.vue';
import StayNotes from '@/Components/Hospitalization/StayNotes.vue';
import StayPrescriptions from '@/Components/Hospitalization/StayPrescriptions.vue';
import StayExams from '@/Components/Hospitalization/StayExams.vue';
import StayCareOrders from '@/Components/Hospitalization/StayCareOrders.vue';
import StayExit from '@/Components/Hospitalization/StayExit.vue';
import StayOpenConsultations from '@/Components/Hospitalization/StayOpenConsultations.vue';
import StayDiagnosisAdd from '@/Components/Hospitalization/StayDiagnosisAdd.vue';
import StayExitContext from '@/Components/Hospitalization/StayExitContext.vue';
import BedPicker from '@/Components/Hospitalization/BedPicker.vue';
import {
    ArrowLeft,
    BedDouble,
    Check,
    DoorOpen,
    FolderOpen,
    Pencil,
    Plus,
    Printer,
    Scissors,
    Search,
    ArrowRightLeft,
    Activity,
    Ambulance,
    Stethoscope,
    Undo2,
    Utensils,
    X,
    LayoutDashboard,
    Lock,
    NotebookPen,
    Pill,
    FlaskConical,
    HandHeart,
} from 'lucide-vue-next';
import { formatDate, formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';

defineOptions({ layout: AppLayout });

/**
 * ADR-113 / ADR-162 — le séjour d'un patient hospitalisé, et son poste de travail.
 *
 * Tout ce qui concerne le patient au lit se fait ici : note du jour,
 * ordonnances, examens, soins, surveillance, régime, bloc, transfert et
 * sortie. Aucune consultation n'est rouverte ; chaque geste passe par
 * l'action serveur qui porte déjà sa règle. Ce que le dossier sait déjà —
 * constantes, allergies, diagnostics, motif — n'est jamais ressaisi.
 */
const props = defineProps({
    stay: { type: Object, required: true },
    capabilities: { type: Object, required: true },
    /** ADR-147 — ceux du passage et ceux du séjour, déjà consignés. */
    diagnoses: { type: Array, default: () => [] },
    /** ADR-163 — les consultations du passage encore ouvertes, et ce qui manque pour les clôturer. */
    openConsultations: { type: Array, default: () => [] },
    /** ADR-160 — ce que le séjour a envoyé au bloc, et où en est chaque demande. */
    surgeries: { type: Array, default: () => [] },
    /** ADR-160 — le référentiel des interventions, servi seulement à qui peut transférer. */
    surgeryProcedures: { type: Array, default: () => [] },
    /** ADR-161 — où le patient a été, à quel niveau de soins, depuis quand. */
    movements: { type: Array, default: () => [] },
    /** ADR-161 — la surveillance répétée, repères de la fiche Soins compris. */
    vitalReadings: { type: Array, default: () => [] },
    careLevels: { type: Array, default: () => [] },
    /** ADR-164 — le site a configuré ses lits : on en choisit un libre. */
    bedsConfigured: { type: Boolean, default: false },
    freeBeds: { type: Array, default: () => [] },
    // ADR-162 — le poste de travail du séjour. `null` : section non servie,
    // faute du droit qui possède la donnée (jamais servie vide).
    careRecord: { type: Object, default: null },
    notes: { type: Array, default: null },
    prescriptions: { type: Array, default: null },
    /** ADR-163 — l'ordonnance proposée pour les diagnostics du passage (ADR-111). */
    prescriptionSuggestions: { type: Object, default: null },
    labRequests: { type: Array, default: null },
    imagingRequests: { type: Array, default: null },
    careOrders: { type: Array, default: null },
    referral: { type: Object, default: null },
    orderOptions: { type: Object, default: () => ({}) },
    orderCapabilities: { type: Object, default: () => ({}) },
});

// ── Onglets (ADR-162) ───────────────────────────────────────────────────────
// Un onglet par geste du médecin au lit. L'onglet ouvert voyage dans l'adresse
// (#examens) pour qu'un enregistrement ramène au même endroit ; il n'est lu
// qu'une fois la page montée — jamais pendant le rendu serveur.
const TABS = computed(() => [
    { key: 'apercu', label: 'Vue d’ensemble', icon: LayoutDashboard, show: true },
    { key: 'notes', label: 'Notes du jour', icon: NotebookPen, show: props.notes !== null || props.orderCapabilities.can_write_note },
    { key: 'ordonnances', label: 'Ordonnances', icon: Pill, show: props.prescriptions !== null || props.orderCapabilities.can_prescribe, count: activePrescriptions.value.length },
    { key: 'examens', label: 'Examens', icon: FlaskConical, show: props.labRequests !== null || props.imagingRequests !== null, count: pendingExams.value },
    { key: 'soins', label: 'Soins', icon: HandHeart, show: props.careOrders !== null || props.orderCapabilities.can_request_care },
    { key: 'surveillance', label: 'Surveillance', icon: Activity, show: props.capabilities.can_view_vitals, count: props.vitalReadings.length },
    { key: 'regime', label: 'Régime', icon: Utensils, show: true },
    { key: 'bloc', label: 'Bloc', icon: Scissors, show: props.surgeries.length > 0 || props.capabilities.can_request_surgery },
    { key: 'sortie', label: 'Sortie', icon: DoorOpen, show: true },
].filter((tab) => tab.show));
const activeTab = ref('apercu');
const selectTab = (key) => {
    activeTab.value = key;
    try { window.history.replaceState(window.history.state, '', `#${key}`); } catch { /* adresse inchangée */ }
};
onMounted(() => {
    const key = window.location.hash.replace('#', '');
    if (TABS.value.some((tab) => tab.key === key)) activeTab.value = key;
});

const activePrescriptions = computed(() => (props.prescriptions ?? []).filter((prescription) => prescription.status === 'ACTIVE'));
const pendingExams = computed(() => [...(props.labRequests ?? []), ...(props.imagingRequests ?? [])]
    .flatMap((request) => (request.status === 'CANCELLED' ? [] : request.items))
    .filter((item) => !item.resulted_at).length);
/** Le traitement de sortie se coche parmi ce que le séjour prescrit déjà. */
const activePrescriptionLines = computed(() => activePrescriptions.value
    .flatMap((prescription) => prescription.lines)
    .map((line) => [line.name, line.posology].filter(Boolean).join(' — ')));
const careBloodPressure = computed(() => (props.careRecord?.blood_pressure_systolic && props.careRecord?.blood_pressure_diastolic
    ? `${props.careRecord.blood_pressure_systolic}/${props.careRecord.blood_pressure_diastolic}`
    : null));
const latestReading = computed(() => props.vitalReadings[0] ?? null);
/** ADR-128 — ce que la relecture d'ordonnance sait du patient. */
const patientSafety = computed(() => ({
    age: props.stay.patient.age ?? null,
    weightKg: props.careRecord?.weight_kg ? Number(props.careRecord.weight_kg) : null,
    allergies: props.stay.allergies ?? [],
}));
const patientLabel = computed(() => `${props.stay.patient.name} · ${props.stay.episode.episode_number}`);

const isActive = computed(() => props.stay.status === 'ACTIVE');

// ADR-160 — un passage au bloc qui n'a ni fini ni été annulé retient encore le patient.
const openSurgeries = computed(() => props.surgeries
    .filter((surgery) => !['COMPLETED', 'DISCHARGED', 'CANCELLED'].includes(surgery.status)).length);

// La colonne de repères de la Sortie mène à l'onglet qui porte ce qui reste
// à relire ; les consultations ouvertes sont déjà en tête de cet onglet.
const exitNavigate = (key) => {
    if (key === 'consultations') {
        document.getElementById('stay-open-consultations')?.scrollIntoView({ behavior: 'smooth', block: 'start' });

        return;
    }

    selectTab(key);
};

/** Les quatre colonnes « Régime » de la feuille papier, dans son ordre. */
const MEALS = [
    { key: 'tea_bread', label: 'Thé / Pain' },
    { key: 'sosoa_brochette', label: 'Sosoa / Brochette' },
    { key: 'yogurt', label: 'Yaourt' },
    { key: 'puree', label: 'Purée' },
];

const pad = (value) => String(value).padStart(2, '0');
const today = () => {
    const now = new Date();

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};
const nowTime = () => {
    const now = new Date();

    return `${pad(now.getHours())}:${pad(now.getMinutes())}`;
};

const emptyEntry = () => ({
    served_on: today(),
    served_time: nowTime(),
    tea_bread: '',
    sosoa_brochette: '',
    yogurt: '',
    puree: '',
    observation: '',
});

// ── Ajouter une ligne ───────────────────────────────────────────────────────
const newEntry = useForm(emptyEntry());
const hasContent = (form) => [...MEALS.map((meal) => meal.key), 'observation']
    .some((key) => String(form[key] ?? '').trim() !== '');
const addEntry = () => newEntry.post(`/hospitalisation/${props.stay.uuid}/regime`, {
    preserveScroll: true,
    onSuccess: () => {
        newEntry.defaults(emptyEntry());
        newEntry.reset();
    },
});

// ── Corriger une ligne ──────────────────────────────────────────────────────
// Corrigée, jamais supprimée : l'audit garde l'ancienne valeur.
const editingUuid = ref(null);
const editEntry = useForm(emptyEntry());
const startEdit = (entry) => {
    editEntry.clearErrors();
    Object.keys(emptyEntry()).forEach((key) => { editEntry[key] = entry[key] ?? ''; });
    editingUuid.value = entry.uuid;
};
const saveEdit = () => editEntry.put(`/hospitalisation/${props.stay.uuid}/regime/${editingUuid.value}`, {
    preserveScroll: true,
    onSuccess: () => { editingUuid.value = null; },
});

// ── Service et chambre / lit ────────────────────────────────────────────────
const editingRoom = ref(false);
const roomForm = useForm({ service: props.stay.service ?? '', room_bed: props.stay.room_bed ?? '' });
const saveRoom = () => roomForm.put(`/hospitalisation/${props.stay.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { editingRoom.value = false; },
});

// ── Mutation interne (ADR-161) ──────────────────────────────────────────────
// Changer de service, de lit ou de niveau de soins ferme l'emplacement en
// cours et en ouvre un autre : rien ne s'écrase. Le crayon ci-dessus corrige
// l'emplacement actuel (une faute, la chambre à compléter) sans rien déplacer.
const moveOpen = ref(false);
const moveForm = useForm({ service: '', room_bed: '', care_level: 'STANDARD', reason: '' });
const openMove = () => {
    moveForm.clearErrors();
    moveForm.service = props.stay.service ?? '';
    moveForm.room_bed = props.stay.room_bed ?? '';
    moveForm.care_level = props.stay.care_level ?? 'STANDARD';
    moveForm.reason = '';
    moveOpen.value = true;
};
const submitMove = () => moveForm.post(`/hospitalisation/${props.stay.uuid}/mouvements`, {
    preserveScroll: true,
    onSuccess: () => { moveOpen.value = false; },
});
// ── Lit du référentiel (ADR-164) ────────────────────────────────────────────
// « Attribuer » ou « Corriger » installe le patient sans mutation (ADR-161) ;
// « Changer de lit » ferme l'emplacement et en ouvre un autre. Le service et
// le niveau de soins suivent le lit choisi ; le serveur refuse un lit occupé.
const bedDialog = ref(null); // null | 'assign' | 'move'
const bedForm = useForm({ hospital_bed_uuid: '', reason: '' });
const openBedDialog = (mode) => {
    bedForm.reset();
    bedForm.clearErrors();
    bedDialog.value = mode;
};
const chosenBed = computed(() => {
    for (const service of props.freeBeds) {
        for (const room of service.rooms) {
            const bed = room.beds.find((candidate) => candidate.uuid === bedForm.hospital_bed_uuid);
            if (bed) return { service, room, bed };
        }
    }

    return null;
});
const submitBed = () => {
    const done = { preserveScroll: true, onSuccess: () => { bedDialog.value = null; } };

    if (bedDialog.value === 'move') {
        bedForm.transform((data) => ({ hospital_bed_uuid: data.hospital_bed_uuid, reason: data.reason }))
            .post(`/hospitalisation/${props.stay.uuid}/mouvements`, done);
    } else {
        bedForm.transform((data) => ({ hospital_bed_uuid: data.hospital_bed_uuid }))
            .put(`/hospitalisation/${props.stay.uuid}`, done);
    }
};
const needsBed = computed(() => props.bedsConfigured && props.stay.status === 'ACTIVE' && !props.stay.bed_uuid);

const CARE_LEVEL_VARIANT = { STANDARD: 'outline', CONTINUOUS: 'warning', INTENSIVE: 'destructive' };
const careLevelVariant = (level) => CARE_LEVEL_VARIANT[level] ?? 'outline';
const pastMovements = computed(() => props.movements.filter((movement) => !movement.is_current).slice().reverse());

// ── Surveillance (ADR-161) ──────────────────────────────────────────────────
// Un relevé par passage de l'infirmier ou du médecin, jamais écrasé. Les
// repères sont ceux de la fiche Soins, calculés par le serveur selon l'âge.
const VITAL_FIELDS = ['blood_pressure_systolic', 'blood_pressure_diastolic', 'heart_rate', 'spo2', 'temperature_celsius'];
const emptyReading = () => ({ measured_at: '', blood_pressure_systolic: '', blood_pressure_diastolic: '', heart_rate: '', spo2: '', temperature_celsius: '', notes: '' });
const readingForm = useForm(emptyReading());
const readingHasValue = computed(() => VITAL_FIELDS.some((field) => String(readingForm[field] ?? '').trim() !== ''));
const submitReading = () => readingForm.post(`/hospitalisation/${props.stay.uuid}/surveillance`, {
    preserveScroll: true,
    onSuccess: () => readingForm.reset(),
});
const correctingReading = ref(null);
const correctionForm = useForm(emptyReading());
const toInputDateTime = (value) => {
    if (!value) return '';
    const date = new Date(value);

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};
const openCorrection = (reading) => {
    correctionForm.clearErrors();
    correctionForm.measured_at = toInputDateTime(reading.measured_at);
    VITAL_FIELDS.forEach((field) => { correctionForm[field] = reading[field] ?? ''; });
    correctionForm.notes = reading.notes ?? '';
    correctingReading.value = reading;
};
const submitCorrection = () => correctionForm.put(`/hospitalisation/${props.stay.uuid}/surveillance/${correctingReading.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { correctingReading.value = null; },
});
const alertVariant = (tone) => (tone === 'danger' ? 'destructive' : 'warning');
const bloodPressure = (reading) => (reading.blood_pressure_systolic && reading.blood_pressure_diastolic
    ? `${reading.blood_pressure_systolic}/${reading.blood_pressure_diastolic}`
    : '—');

// ── Demande d'hospitalisation ───────────────────────────────────────────────
// Elle part en un clic depuis la consultation, reprise du dossier ; c'est ici
// que le médecin la complète (ADR-113, amendement).
const PRIORITIES = [
    { value: 'LOW', label: 'Faible' },
    { value: 'NORMAL', label: 'Normale' },
    { value: 'URGENT', label: 'Urgente' },
];
const priorityLabel = (value) => PRIORITIES.find((option) => option.value === value)?.label ?? '—';
const editingRequest = ref(false);
const requestForm = useForm({
    reason: props.stay.request.reason ?? '',
    admission_diagnosis: props.stay.request.admission_diagnosis ?? '',
    clinical_summary: props.stay.request.clinical_summary ?? '',
    planned_treatment: props.stay.request.planned_treatment ?? '',
    priority: props.stay.request.priority ?? 'NORMAL',
    instructions: props.stay.request.instructions ?? '',
});
const saveRequest = () => requestForm.put(`/hospitalisation/${props.stay.uuid}/demande`, {
    preserveScroll: true,
    onSuccess: () => { editingRequest.value = false; },
});
const requestIncomplete = computed(() => !props.stay.request.reason || !props.stay.request.admission_diagnosis);

// ── Transfert au bloc (ADR-160) ─────────────────────────────────────────────
// Le patient descend au bloc et garde son lit : la demande naît du séjour, le
// séjour n'est ni terminé ni annulé. L'intervention se choisit, jamais ne se
// devine (ADR-114) ; le serveur revérifie tout.
const surgeryOpen = ref(false);
const procedureQuery = ref('');
const surgeryForm = useForm({ catalog_item_uuid: '', indication: '', priority: 'NORMAL', notes: '' });

const SURGERY_STATUS = {
    PENDING: { label: 'À programmer', variant: 'warning' },
    SCHEDULED: { label: 'Programmée', variant: 'secondary' },
    PREOPERATIVE_VALIDATED: { label: 'Bilan préop. validé', variant: 'secondary' },
    IN_PROGRESS: { label: 'Au bloc', variant: 'warning' },
    COMPLETED: { label: 'Opéré', variant: 'success' },
    DISCHARGED: { label: 'Sorti du bloc', variant: 'outline' },
    CANCELLED: { label: 'Annulée', variant: 'outline' },
};
const surgeryStatus = (status) => SURGERY_STATUS[status] ?? { label: status, variant: 'outline' };

const fold = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const filteredProcedures = computed(() => {
    const query = fold(procedureQuery.value.trim());

    return query === ''
        ? props.surgeryProcedures
        : props.surgeryProcedures.filter((procedure) => fold(procedure.name).includes(query) || fold(procedure.code).includes(query));
});
const selectedProcedure = computed(() => props.surgeryProcedures.find((procedure) => procedure.uuid === surgeryForm.catalog_item_uuid) ?? null);

const openSurgery = () => {
    surgeryForm.reset();
    surgeryForm.clearErrors();
    procedureQuery.value = '';
    surgeryOpen.value = true;
};
const submitSurgery = () => surgeryForm.post(`/hospitalisation/${props.stay.uuid}/bloc`, {
    preserveScroll: true,
    onSuccess: () => { surgeryOpen.value = false; },
});

// ── Annuler un transfert au bloc (ADR-163) ──────────────────────────────────
// Tant que le bloc ne l'a pas programmé. Le patient n'a jamais quitté son lit :
// il n'y a rien à « faire revenir », seulement une demande à retirer.
const cancellingSurgery = ref(null);
const surgeryCancelForm = useForm({ reason: '' });
const openSurgeryCancel = (surgery) => {
    surgeryCancelForm.reset();
    surgeryCancelForm.clearErrors();
    cancellingSurgery.value = surgery;
};
const submitSurgeryCancel = () => surgeryCancelForm.post(`/hospitalisation/${props.stay.uuid}/bloc/${cancellingSurgery.value.uuid}/annuler`, {
    preserveScroll: true,
    onSuccess: () => { cancellingSurgery.value = null; },
});

// ── Diagnostic du séjour (ADR-147) ──────────────────────────────────────────
const smokerLabel = computed(() => (props.stay.smoker === null ? 'Non renseigné' : (props.stay.smoker ? 'Oui' : 'Non')));
const allergyLabel = computed(() => (props.stay.allergies.length ? props.stay.allergies.join(', ') : 'Aucune allergie connue au dossier'));
</script>

<template>
    <Head :title="`Hospitalisation — ${stay.patient.name}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <BedDouble class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="font-heading text-lg font-bold text-foreground">{{ stay.patient.name }}</h1>
                            <Badge :variant="isActive ? 'default' : 'outline'">{{ stay.status_label }}</Badge>
                        </div>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ stay.patient.patient_number }} · Passage {{ stay.episode.episode_number }}<template v-if="stay.patient.age !== null"> · {{ stay.patient.age }} ans</template>
                            · Entré le {{ formatDateTime(stay.admitted_at) }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button :as="Link" href="/hospitalisation" size="sm" variant="white-outline"><ArrowLeft class="h-4 w-4" />Hospitalisation</Button>
                    <Button :as="Link" :href="stay.episode.url" size="sm" variant="white-outline"><FolderOpen class="h-4 w-4" />Passage</Button>
                    <Button :as="Link" :href="`/hospitalisation/${stay.uuid}/regime/impression`" size="sm" variant="white-outline"><Printer class="h-4 w-4" />Imprimer la fiche</Button>
                </div>
            </div>
        </Card>

        <!-- ADR-162 — un onglet par geste du médecin au lit. -->
        <nav class="flex gap-1 overflow-x-auto rounded-lg border border-border bg-card p-1" aria-label="Sections du séjour">
            <button
                v-for="tab in TABS"
                :key="tab.key"
                type="button"
                :aria-current="activeTab === tab.key ? 'page' : undefined"
                :class="['flex shrink-0 items-center gap-1.5 rounded-md px-3 py-2 text-xs font-semibold transition-colors', activeTab === tab.key ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-foreground']"
                @click="selectTab(tab.key)"
            >
                <component :is="tab.icon" class="h-4 w-4" />{{ tab.label }}
                <span v-if="tab.count" :class="['rounded-full px-1.5 text-[10px] tabular-nums', activeTab === tab.key ? 'bg-primary-foreground/20' : 'bg-muted']">{{ tab.count }}</span>
            </button>
        </nav>

        <!-- Vue d'ensemble : ce que le dossier sait déjà, jamais ressaisi. -->
        <template v-if="activeTab === 'apercu'">
            <StayOpenConsultations :stay-uuid="stay.uuid" :consultations="openConsultations" :stay-ended="!isActive" />
            <VitalSignsStrip
                v-if="careRecord"
                :care-record="careRecord"
                :blood-pressure="careBloodPressure"
                :allergies="stay.allergies"
            />
            <Card v-if="latestReading" class="p-4">
                <p class="text-xs text-muted-foreground">Dernier relevé de surveillance · <span class="font-semibold text-foreground">{{ formatDateTime(latestReading.measured_at) }}</span></p>
                <p class="mt-1 text-sm text-foreground">
                    TA {{ bloodPressure(latestReading) }} · FC {{ latestReading.heart_rate ?? '—' }} · SpO₂ {{ latestReading.spo2 ?? '—' }} · T° {{ latestReading.temperature_celsius ?? '—' }}
                </p>
                <Button type="button" size="xs" variant="ghost" class="mt-1 px-0" @click="selectTab('surveillance')">Voir la surveillance</Button>
            </Card>
            <div class="grid gap-5 lg:grid-cols-2">
                <Card class="p-5">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-foreground">Séjour</h2>
                        <Button v-if="capabilities.can_update_stay && bedsConfigured && stay.bed_uuid" type="button" size="icon-xs" variant="ghost" class="text-muted-foreground" title="Corriger le lit (erreur de saisie)" aria-label="Corriger le lit actuel" @click="openBedDialog('assign')"><Pencil class="h-3.5 w-3.5" /></Button>
                        <Button v-else-if="capabilities.can_update_stay && !bedsConfigured && !editingRoom" type="button" size="icon-xs" variant="ghost" class="text-muted-foreground" title="Corriger l’emplacement actuel" aria-label="Corriger le service et la chambre actuels" @click="editingRoom = true"><Pencil class="h-3.5 w-3.5" /></Button>
                    </div>
                    <!-- ADR-164 — un patient au lit sans lit attribué se voit, et s'installe d'un geste. -->
                    <div v-if="needsBed" class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100" role="status">
                        <p class="flex items-center gap-2 font-semibold"><BedDouble class="h-4 w-4" aria-hidden="true" />Lit à attribuer</p>
                        <p class="mt-0.5 text-xs">Le patient est admis mais n’occupe encore aucun lit du site<template v-if="stay.room_bed"> (noté à la main : {{ stay.room_bed }})</template>.</p>
                        <Button v-if="capabilities.can_update_stay" type="button" size="sm" class="mt-2" @click="openBedDialog('assign')"><BedDouble class="h-4 w-4" />Attribuer un lit</Button>
                    </div>
                    <dl v-if="!editingRoom || bedsConfigured" class="mt-3 space-y-2.5 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Service</dt><dd class="text-foreground">{{ stay.service || 'Non précisé' }}</dd></div>
                        <div v-if="!needsBed"><dt class="text-xs text-muted-foreground">Chambre / lit</dt><dd class="text-foreground">{{ stay.room_bed || 'Non renseigné' }}</dd></div>
                        <div v-if="stay.care_level_label"><dt class="text-xs text-muted-foreground">Niveau de soins</dt><dd><Badge :variant="careLevelVariant(stay.care_level)">{{ stay.care_level_label }}</Badge></dd></div>
                        <div><dt class="text-xs text-muted-foreground">Entrée</dt><dd class="text-foreground">{{ formatDateTime(stay.admitted_at) }}<span v-if="stay.request.requested_by" class="block text-xs text-muted-foreground">Demandée par {{ doctorName(stay.request.requested_by) }}</span></dd></div>
                    </dl>
                    <form v-if="editingRoom && !bedsConfigured" class="mt-3 space-y-3" @submit.prevent="saveRoom">
                        <FormField label="Service" :error="roomForm.errors.service">
                            <Input v-model="roomForm.service" placeholder="Ex. : Médecine interne" maxlength="150" />
                        </FormField>
                        <FormField label="Chambre / lit" :error="roomForm.errors.room_bed">
                            <Input v-model="roomForm.room_bed" placeholder="Ex. : Chambre 3, lit B" maxlength="100" />
                        </FormField>
                        <div class="flex justify-end gap-2">
                            <Button type="button" size="sm" variant="ghost" @click="editingRoom = false; roomForm.reset()">Annuler</Button>
                            <Button type="submit" size="sm" :disabled="roomForm.processing"><Check class="h-4 w-4" />Enregistrer</Button>
                        </div>
                    </form>
                    <!-- ADR-164 — sans lit configuré, la saisie libre continue ; l'écran dit pourquoi, et où les lits se créent. -->
                    <p v-if="!bedsConfigured && isActive && !editingRoom" class="mt-3 rounded-md bg-muted/60 px-3 py-2 text-xs text-muted-foreground">
                        Les lits de ce site ne sont pas encore configurés : la chambre se note à la main. Ils se créent depuis le portail
                        Super Administration (« Services, chambres et lits ») ; « Attribuer un lit » apparaîtra alors ici.
                    </p>
                    <Button v-if="capabilities.can_move && bedsConfigured && stay.bed_uuid" type="button" size="sm" variant="white-outline" class="mt-4 w-full" @click="openBedDialog('move')">
                        <ArrowRightLeft class="h-4 w-4" />Changer de lit
                    </Button>
                    <Button v-else-if="capabilities.can_move && !bedsConfigured && !editingRoom" type="button" size="sm" variant="white-outline" class="mt-4 w-full" @click="openMove">
                        <ArrowRightLeft class="h-4 w-4" />Changer de service / lit
                    </Button>
                    <!-- ADR-161 — les emplacements précédents, jamais écrasés. -->
                    <div v-if="pastMovements.length" class="mt-4 border-t border-border pt-3">
                        <p class="text-xs font-semibold text-muted-foreground">Emplacements précédents</p>
                        <ol class="mt-2 space-y-2">
                            <li v-for="movement in pastMovements" :key="movement.uuid" class="text-xs">
                                <p class="font-medium text-foreground">
                                    {{ movement.service || 'Service non précisé' }}<template v-if="movement.room_bed"> · {{ movement.room_bed }}</template>
                                </p>
                                <p class="text-muted-foreground">
                                    {{ movement.care_level_label }} · {{ formatDateTime(movement.started_at) }} → {{ formatDateTime(movement.ended_at) }}
                                </p>
                            </li>
                        </ol>
                    </div>
                </Card>
                <Card class="p-5">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-foreground">Demande d’hospitalisation</h2>
                        <Button v-if="capabilities.can_edit_request && !editingRequest" type="button" size="sm" variant="outline" @click="editingRequest = true"><Pencil class="h-3.5 w-3.5" />{{ requestIncomplete ? 'Compléter' : 'Modifier' }}</Button>
                    </div>
                    <p v-if="requestIncomplete && !editingRequest" class="mt-2 text-xs text-amber-700 dark:text-amber-300">Motif ou diagnostic d’entrée encore à préciser.</p>
                    <dl v-if="!editingRequest" class="mt-3 space-y-2.5 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Motif</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.reason || '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Diagnostic d’entrée</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.admission_diagnosis || '—' }}</dd></div>
                        <div v-if="stay.request.clinical_summary"><dt class="text-xs text-muted-foreground">Résumé clinique et examens</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.clinical_summary }}</dd></div>
                        <div v-if="stay.request.planned_treatment"><dt class="text-xs text-muted-foreground">Traitement prévu</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.planned_treatment }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Priorité</dt><dd class="text-foreground">{{ priorityLabel(stay.request.priority) }}</dd></div>
                        <div v-if="stay.request.instructions"><dt class="text-xs text-muted-foreground">Consignes</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.instructions }}</dd></div>
                    </dl>
                    <form v-else class="mt-3 space-y-3" @submit.prevent="saveRequest">
                        <FormField label="Motif d’hospitalisation" :error="requestForm.errors.reason">
                            <Textarea v-model="requestForm.reason" :rows="2" maxlength="3000" />
                        </FormField>
                        <FormField label="Diagnostic d’entrée" :error="requestForm.errors.admission_diagnosis">
                            <Textarea v-model="requestForm.admission_diagnosis" :rows="2" maxlength="3000" />
                        </FormField>
                        <FormField label="Résumé clinique et examens" :error="requestForm.errors.clinical_summary">
                            <Textarea v-model="requestForm.clinical_summary" :rows="5" maxlength="5000" />
                        </FormField>
                        <FormField label="Traitement prévu" :error="requestForm.errors.planned_treatment">
                            <Textarea v-model="requestForm.planned_treatment" :rows="3" maxlength="3000" />
                        </FormField>
                        <FormField label="Priorité" :error="requestForm.errors.priority">
                            <Select v-model="requestForm.priority" :options="PRIORITIES" aria-label="Priorité" />
                        </FormField>
                        <FormField label="Consignes au service" :error="requestForm.errors.instructions">
                            <Textarea v-model="requestForm.instructions" :rows="2" maxlength="3000" />
                        </FormField>
                        <FormError :message="requestForm.errors.hospitalization_request" />
                        <div class="flex justify-end gap-2">
                            <Button type="button" size="sm" variant="ghost" @click="editingRequest = false; requestForm.reset()">Annuler</Button>
                            <Button type="submit" size="sm" :disabled="requestForm.processing"><Check class="h-4 w-4" />Enregistrer</Button>
                        </div>
                    </form>
                </Card>
            </div>
        <!-- ADR-147 — ce que le séjour a conclu, et ce que le dossier avait
             déjà consigné. La sortie n'est plus ici (ADR-156) : ces
             diagnostics restent la trace clinique du séjour lui-même. -->
        <Card v-if="diagnoses.length || capabilities.can_add_diagnosis" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground">
                <Stethoscope class="h-4 w-4 text-muted-foreground" />Diagnostics
            </h2>
            <ul v-if="diagnoses.length" class="mt-3 space-y-2">
                <li
                    v-for="diagnosis in diagnoses"
                    :key="diagnosis.id"
                    class="flex flex-wrap items-baseline justify-between gap-2 rounded-md border border-border bg-muted/20 px-3 py-2"
                >
                    <span class="text-sm text-foreground">{{ diagnosis.description }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ diagnosis.recorded_by }}<span v-if="diagnosis.recorded_at"> · {{ formatDateTime(diagnosis.recorded_at) }}</span>
                    </span>
                </li>
            </ul>
            <p v-else class="mt-3 text-xs text-muted-foreground">Aucun diagnostic consigné pour ce passage.</p>
            <StayDiagnosisAdd v-if="capabilities.can_add_diagnosis" :stay-uuid="stay.uuid" class="mt-3" />
        </Card>
        <!-- ADR-161 — un transfert se termine au départ du patient, sans
             sortie médicale : le séjour dit où il est parti, et quand. -->
        <Card v-if="!stay.discharge && stay.end_reason === 'TRANSFER' && stay.transfer" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Ambulance class="h-4 w-4 text-muted-foreground" />Transféré</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs text-muted-foreground">Vers</dt><dd class="text-foreground">{{ stay.transfer.facility || 'Établissement non précisé' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Départ</dt><dd class="text-foreground">{{ formatDateTime(stay.transfer.departed_at) }}<span v-if="stay.discharged_by" class="block text-xs text-muted-foreground">Constaté par {{ stay.discharged_by }}</span></dd></div>
            </dl>
        </Card>
        <Card v-if="stay.discharge" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><DoorOpen class="h-4 w-4 text-muted-foreground" />Sortie</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs text-muted-foreground">Type</dt><dd class="text-foreground">{{ stay.discharge.type_label }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Date</dt><dd class="text-foreground">{{ formatDateTime(stay.discharged_at) }}<span v-if="stay.discharged_by" class="block text-xs text-muted-foreground">Prononcée par {{ doctorName(stay.discharged_by) }}</span></dd></div>
                <div v-if="stay.discharge.final_diagnosis"><dt class="text-xs text-muted-foreground">Diagnostic final</dt><dd class="whitespace-pre-line text-foreground">{{ stay.discharge.final_diagnosis }}</dd></div>
                <div v-if="stay.discharge.patient_condition"><dt class="text-xs text-muted-foreground">État du patient</dt><dd class="text-foreground">{{ stay.discharge.patient_condition }}</dd></div>
            </dl>
        </Card>
        </template>

        <StayNotes v-else-if="activeTab === 'notes'" :stay-uuid="stay.uuid" :notes="notes" :can-write="orderCapabilities.can_write_note" />

        <StayPrescriptions
            v-else-if="activeTab === 'ordonnances'"
            :stay-uuid="stay.uuid"
            :prescriptions="prescriptions"
            :medicines="orderOptions.medicines ?? []"
            :routes="orderOptions.administration_routes ?? []"
            :can-prescribe="orderCapabilities.can_prescribe"
            :patient="patientSafety"
            :suggestions="prescriptionSuggestions"
        />

        <StayExams
            v-else-if="activeTab === 'examens'"
            :stay-uuid="stay.uuid"
            :lab-requests="labRequests"
            :imaging-requests="imagingRequests"
            :lab-catalog="orderOptions.lab_catalog ?? []"
            :imaging-catalog="orderOptions.imaging_catalog ?? []"
            :can-request-lab="orderCapabilities.can_request_lab"
            :can-request-imaging="orderCapabilities.can_request_imaging"
            :orientation-uuid="orderOptions.stay_orientation_uuid ?? ''"
            :templates="orderOptions.imaging_report_templates ?? []"
            :template-rights="orderOptions.imaging_report_template_rights ?? {}"
            :patient-label="patientLabel"
        />

        <StayCareOrders
            v-else-if="activeTab === 'soins'"
            :stay-uuid="stay.uuid"
            :care-orders="careOrders"
            :catalog="orderOptions.care_order_catalog ?? []"
            :can-request="orderCapabilities.can_request_care"
        />

        <template v-else-if="activeTab === 'surveillance'">
        <!-- ADR-161 — la surveillance répétée. La fiche Soins reste le relevé de
             triage ; chaque passage au lit ajoute une ligne, jamais écrasée. -->
        <Card v-if="capabilities.can_view_vitals" class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Activity class="h-4 w-4 text-muted-foreground" />Surveillance</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Chaque relevé est daté et signé. Les repères sont ceux de la fiche Soins, lus selon l’âge — une aide, jamais un diagnostic.</p>
                </div>
            </div>

            <!-- ADR-161 — sans le droit d'ajouter, l'écran dit qui relève et ce
                 qui manque : un cadre vide sans un mot se lit « inutile ici ». -->
            <p v-if="capabilities.vitals_record_block" class="mt-3 flex items-start gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                <Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>{{ capabilities.vitals_record_block }}</span>
            </p>

            <form v-else class="mt-4 grid gap-3 rounded-lg border border-border bg-muted/30 p-3 sm:grid-cols-3 lg:grid-cols-7" @submit.prevent="submitReading">
                <FormField label="TA systolique" hint="mmHg" :error="readingForm.errors.blood_pressure_systolic">
                    <Input v-model="readingForm.blood_pressure_systolic" type="number" inputmode="numeric" placeholder="120" />
                </FormField>
                <FormField label="TA diastolique" hint="mmHg" :error="readingForm.errors.blood_pressure_diastolic">
                    <Input v-model="readingForm.blood_pressure_diastolic" type="number" inputmode="numeric" placeholder="80" />
                </FormField>
                <FormField label="FC" hint="btt/mn" :error="readingForm.errors.heart_rate">
                    <Input v-model="readingForm.heart_rate" type="number" inputmode="numeric" />
                </FormField>
                <FormField label="SpO₂" hint="%" :error="readingForm.errors.spo2">
                    <Input v-model="readingForm.spo2" type="number" inputmode="numeric" />
                </FormField>
                <FormField label="Température" hint="°C" :error="readingForm.errors.temperature_celsius">
                    <Input v-model="readingForm.temperature_celsius" type="number" step="0.1" inputmode="decimal" />
                </FormField>
                <FormField label="Mesuré le" hint="(maintenant si vide)" :error="readingForm.errors.measured_at">
                    <Input v-model="readingForm.measured_at" type="datetime-local" />
                </FormField>
                <div class="flex items-end">
                    <Button type="submit" size="sm" class="w-full" :disabled="readingForm.processing || !readingHasValue"><Plus class="h-4 w-4" />Ajouter</Button>
                </div>
                <FormField label="Observation" class="sm:col-span-3 lg:col-span-7" :error="readingForm.errors.notes">
                    <Input v-model="readingForm.notes" maxlength="1000" placeholder="Facultatif" />
                </FormField>
            </form>

            <div v-if="vitalReadings.length" class="mt-4 overflow-x-auto rounded-lg border border-border">
                <table class="w-full min-w-[720px] text-sm">
                    <caption class="sr-only">Relevés de surveillance du séjour</caption>
                    <thead class="bg-muted/40 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-start">Mesuré le</th>
                            <th class="px-3 py-2 text-start">TA</th>
                            <th class="px-3 py-2 text-start">FC</th>
                            <th class="px-3 py-2 text-start">SpO₂</th>
                            <th class="px-3 py-2 text-start">T°</th>
                            <th class="px-3 py-2 text-start">Repères</th>
                            <th class="px-3 py-2 text-start">Par</th>
                            <th class="px-3 py-2 text-end"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="reading in vitalReadings" :key="reading.uuid" class="align-top">
                            <td class="px-3 py-2 whitespace-nowrap text-foreground">{{ formatDateTime(reading.measured_at) }}</td>
                            <td class="px-3 py-2">{{ bloodPressure(reading) }}</td>
                            <td class="px-3 py-2">{{ reading.heart_rate ?? '—' }}</td>
                            <td class="px-3 py-2">{{ reading.spo2 != null ? reading.spo2 + ' %' : '—' }}</td>
                            <td class="px-3 py-2">{{ reading.temperature_celsius != null ? reading.temperature_celsius + ' °C' : '—' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <Badge v-for="alert in reading.alerts" :key="alert.label" :variant="alertVariant(alert.tone)" :title="alert.message ?? alert.label">{{ alert.label }}</Badge>
                                </div>
                                <p v-if="reading.notes" class="mt-1 text-xs text-muted-foreground">{{ reading.notes }}</p>
                            </td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">
                                {{ reading.measured_by }}
                                <span v-if="reading.updated_by" class="block">corrigé par {{ reading.updated_by }}</span>
                            </td>
                            <td class="px-3 py-2 text-end">
                                <Button v-if="capabilities.can_correct_vitals" type="button" size="icon-xs" variant="ghost" class="text-muted-foreground" title="Corriger ce relevé" aria-label="Corriger ce relevé" @click="openCorrection(reading)"><Pencil class="h-3.5 w-3.5" /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="mt-4 rounded-md border border-dashed border-border bg-muted/30 px-3 py-6 text-center text-xs text-muted-foreground">
                Aucun relevé pendant ce séjour. Le relevé d’arrivée reste dans la fiche Soins du passage.
            </p>
        </Card>
        </template>

        <template v-else-if="activeTab === 'regime'">
            <Card class="min-w-0 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border px-5 py-3.5">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><Utensils class="h-4 w-4" /></span>
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-foreground">Fiche de régime</h2>
                        <p class="text-xs text-muted-foreground">Repas donnés jour par jour, en texte libre. Aucun montant.</p>
                    </div>
                </div>

                <!-- En-tête de la feuille, repris du dossier : rien n'est ressaisi. -->
                <dl class="grid gap-px border-b border-border bg-border text-xs sm:grid-cols-2">
                    <div class="bg-card px-5 py-2.5"><dt class="text-muted-foreground">N° de dossier</dt><dd class="mt-0.5 font-medium text-foreground">{{ stay.patient.patient_number }}</dd></div>
                    <div class="bg-card px-5 py-2.5"><dt class="text-muted-foreground">Tabac</dt><dd class="mt-0.5 font-medium text-foreground">{{ smokerLabel }}</dd></div>
                    <div class="bg-card px-5 py-2.5"><dt class="text-muted-foreground">Allergie</dt><dd :class="['mt-0.5 font-medium', stay.allergies.length ? 'text-destructive' : 'text-foreground']">{{ allergyLabel }}</dd></div>
                    <div class="bg-card px-5 py-2.5"><dt class="text-muted-foreground">Motif d’hospitalisation</dt><dd class="mt-0.5 font-medium text-foreground">{{ stay.request.reason || '—' }}</dd></div>
                </dl>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] border-collapse text-sm">
                        <caption class="sr-only">Fiche de régime</caption>
                        <thead>
                            <tr class="border-b border-border bg-muted/40 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                <th scope="col" class="w-32 px-3 py-2.5 text-start">Jour</th>
                                <th scope="col" class="w-24 px-3 py-2.5 text-start">Heure</th>
                                <th v-for="meal in MEALS" :key="meal.key" scope="col" class="px-3 py-2.5 text-start">{{ meal.label }}</th>
                                <th scope="col" class="px-3 py-2.5 text-start">Observation</th>
                                <th v-if="capabilities.can_record_diet" scope="col" class="w-20 px-3 py-2.5"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template v-for="entry in stay.diet_entries" :key="entry.uuid">
                                <tr v-if="editingUuid === entry.uuid" class="bg-accent/40 align-top">
                                    <td class="px-2 py-2"><Input v-model="editEntry.served_on" type="date" aria-label="Jour" /></td>
                                    <td class="px-2 py-2"><Input v-model="editEntry.served_time" type="time" aria-label="Heure" /></td>
                                    <td v-for="meal in MEALS" :key="meal.key" class="px-2 py-2"><Input v-model="editEntry[meal.key]" :aria-label="meal.label" maxlength="255" /></td>
                                    <td class="px-2 py-2"><Input v-model="editEntry.observation" aria-label="Observation" maxlength="2000" /></td>
                                    <td class="px-2 py-2">
                                        <div class="flex justify-end gap-1">
                                            <Button type="button" size="icon-xs" :disabled="editEntry.processing || !hasContent(editEntry)" title="Enregistrer" aria-label="Enregistrer la correction" @click="saveEdit"><Check class="h-3.5 w-3.5" /></Button>
                                            <Button type="button" size="icon-xs" variant="ghost" title="Annuler" aria-label="Annuler la correction" @click="editingUuid = null"><X class="h-3.5 w-3.5" /></Button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-else class="align-top">
                                    <td class="px-3 py-2.5 text-foreground">{{ formatDate(entry.served_on) }}</td>
                                    <td class="px-3 py-2.5 tabular-nums text-foreground">{{ entry.served_time }}</td>
                                    <td v-for="meal in MEALS" :key="meal.key" class="px-3 py-2.5 text-foreground">{{ entry[meal.key] || '—' }}</td>
                                    <td class="px-3 py-2.5 text-muted-foreground">
                                        {{ entry.observation || '—' }}
                                        <span class="mt-0.5 block text-[10px]">{{ entry.recorded_by }}<template v-if="entry.updated_by"> · corrigé par {{ entry.updated_by }}</template></span>
                                    </td>
                                    <td v-if="capabilities.can_record_diet" class="px-3 py-2 text-end">
                                        <Button type="button" size="icon-xs" variant="ghost" class="text-muted-foreground" title="Corriger" :aria-label="`Corriger la ligne du ${formatDate(entry.served_on)} à ${entry.served_time}`" @click="startEdit(entry)"><Pencil class="h-3.5 w-3.5" /></Button>
                                    </td>
                                </tr>
                            </template>

                            <tr v-if="!stay.diet_entries.length && !capabilities.can_add_diet">
                                <td :colspan="capabilities.can_record_diet ? 8 : 7" class="px-4 py-10 text-center text-sm text-muted-foreground">Aucune ligne dans la fiche de régime.</td>
                            </tr>

                            <!-- La ligne d'ajout est la dernière ligne de la grille,
                                 comme on remplit la feuille papier. -->
                            <tr v-if="capabilities.can_add_diet" class="bg-muted/30 align-top">
                                <td class="px-2 py-2"><Input v-model="newEntry.served_on" type="date" aria-label="Jour" /></td>
                                <td class="px-2 py-2"><Input v-model="newEntry.served_time" type="time" aria-label="Heure" /></td>
                                <td v-for="meal in MEALS" :key="meal.key" class="px-2 py-2"><Input v-model="newEntry[meal.key]" :placeholder="meal.label" :aria-label="meal.label" maxlength="255" /></td>
                                <td class="px-2 py-2"><Input v-model="newEntry.observation" placeholder="Observation" aria-label="Observation" maxlength="2000" /></td>
                                <td class="px-2 py-2 text-end">
                                    <Button type="button" size="sm" :disabled="newEntry.processing || !hasContent(newEntry)" @click="addEntry"><Plus class="h-4 w-4" />Ajouter</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="Object.keys(newEntry.errors).length || Object.keys(editEntry.errors).length" class="border-t border-border px-5 py-2.5">
                    <FormError v-for="(message, key) in { ...newEntry.errors, ...editEntry.errors }" :key="key" :message="message" />
                </div>
            </Card>
        </template>

        <template v-else-if="activeTab === 'bloc'">
        <!-- ADR-160 — le patient au lit descend au bloc et garde son lit. La
             demande naît ici ; le bloc la programme, et elle se suit ici. -->
        <Card v-if="surgeries.length || capabilities.can_request_surgery" class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Scissors class="h-4 w-4 text-muted-foreground" />Bloc opératoire</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Le patient descend au bloc et <strong class="font-semibold text-foreground">garde son lit</strong> : ses constantes, allergies et actes des Soins suivent le dossier.</p>
                </div>
                <Button v-if="capabilities.can_request_surgery" type="button" size="sm" class="shrink-0" @click="openSurgery">
                    <Scissors class="h-4 w-4" />Transférer au bloc
                </Button>
            </div>
            <ul v-if="surgeries.length" class="mt-4 space-y-2">
                <li
                    v-for="surgery in surgeries"
                    :key="surgery.uuid"
                    :class="['rounded-md border border-border bg-card px-3 py-2.5 text-xs', surgery.status === 'CANCELLED' ? 'opacity-70' : '']"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <Badge :variant="surgeryStatus(surgery.status).variant">{{ surgeryStatus(surgery.status).label }}</Badge>
                        <Link v-if="surgery.url" :href="surgery.url" :class="['font-semibold text-foreground hover:underline', surgery.status === 'CANCELLED' ? 'line-through' : '']">{{ surgery.procedure_name }}</Link>
                        <span v-else :class="['font-semibold text-foreground', surgery.status === 'CANCELLED' ? 'line-through' : '']">{{ surgery.procedure_name }}</span>
                        <span v-if="surgery.origin_label" class="text-muted-foreground">· {{ surgery.origin_label }}</span>
                        <span v-if="surgery.surgeon" class="text-muted-foreground">· {{ doctorName(surgery.surgeon) }}</span>
                        <span class="ms-auto flex items-center gap-2 text-muted-foreground">
                            {{ surgery.scheduled_at ? 'Programmée le ' + formatDateTime(surgery.scheduled_at) : 'Demandée le ' + formatDateTime(surgery.created_at) }}
                            <Button v-if="surgery.can_cancel" type="button" size="xs" variant="outline" class="text-destructive" @click="openSurgeryCancel(surgery)">
                                <Undo2 class="h-3.5 w-3.5" aria-hidden="true" />Annuler
                            </Button>
                        </span>
                    </div>
                    <p v-if="surgery.status === 'CANCELLED' && surgery.cancelled_at" class="mt-1.5 text-muted-foreground">
                        Annulée<template v-if="surgery.cancelled_by"> par {{ surgery.cancelled_by }}</template> le {{ formatDateTime(surgery.cancelled_at) }}<template v-if="surgery.cancellation_reason"> — {{ surgery.cancellation_reason }}</template>
                    </p>
                    <p v-else-if="['SCHEDULED', 'PREOPERATIVE_VALIDATED'].includes(surgery.status)" class="mt-1.5 text-muted-foreground">
                        Le bloc l’a programmée : elle lui appartient désormais. Pour y renoncer, voyez avec l’équipe du bloc.
                    </p>
                </li>
            </ul>
            <p v-else class="mt-4 rounded-md border border-dashed border-border bg-muted/30 px-3 py-6 text-center text-xs text-muted-foreground">
                Aucun passage au bloc pour ce séjour.
            </p>
        </Card>
        </template>

        <template v-else-if="activeTab === 'sortie'">
        <StayOpenConsultations id="stay-open-consultations" :stay-uuid="stay.uuid" :consultations="openConsultations" :stay-ended="!isActive" />
        <!-- ADR-161 — un transfert se termine au départ du patient, sans
             sortie médicale : le séjour dit où il est parti, et quand. -->
        <Card v-if="!stay.discharge && stay.end_reason === 'TRANSFER' && stay.transfer" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Ambulance class="h-4 w-4 text-muted-foreground" />Transféré</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs text-muted-foreground">Vers</dt><dd class="text-foreground">{{ stay.transfer.facility || 'Établissement non précisé' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Départ</dt><dd class="text-foreground">{{ formatDateTime(stay.transfer.departed_at) }}<span v-if="stay.discharged_by" class="block text-xs text-muted-foreground">Constaté par {{ stay.discharged_by }}</span></dd></div>
            </dl>
        </Card>
        <Card v-if="stay.discharge" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><DoorOpen class="h-4 w-4 text-muted-foreground" />Sortie</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs text-muted-foreground">Type</dt><dd class="text-foreground">{{ stay.discharge.type_label }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Date</dt><dd class="text-foreground">{{ formatDateTime(stay.discharged_at) }}<span v-if="stay.discharged_by" class="block text-xs text-muted-foreground">Prononcée par {{ doctorName(stay.discharged_by) }}</span></dd></div>
                <div v-if="stay.discharge.final_diagnosis"><dt class="text-xs text-muted-foreground">Diagnostic final</dt><dd class="whitespace-pre-line text-foreground">{{ stay.discharge.final_diagnosis }}</dd></div>
                <div v-if="stay.discharge.patient_condition"><dt class="text-xs text-muted-foreground">État du patient</dt><dd class="text-foreground">{{ stay.discharge.patient_condition }}</dd></div>
            </dl>
        </Card>
            <!-- ADR-162 — le formulaire garde sa largeur (ADR-132) ; les repères
                 du séjour se lisent à côté, sans quitter l'étape. -->
            <div v-if="stay.status === 'ACTIVE'" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <StayExit
                    class="min-w-0"
                    :stay-uuid="stay.uuid"
                    :types="orderOptions.discharge_types ?? []"
                    :diagnoses="diagnoses.map((diagnosis) => ({ id: diagnosis.id, description: diagnosis.description }))"
                    :prescription-lines="activePrescriptionLines"
                    :can-discharge="capabilities.can_discharge"
                    :can-add-diagnosis="capabilities.can_add_diagnosis"
                    :can-request-transfer="orderCapabilities.can_request_transfer"
                    :transfer-destinations="orderOptions.transfer_destinations ?? []"
                    :referral="referral"
                />
                <StayExitContext
                    class="xl:sticky xl:top-4 xl:self-start"
                    :stay="stay"
                    :readings="capabilities.can_view_vitals ? vitalReadings : null"
                    :active-prescriptions="prescriptions === null ? null : activePrescriptions.length"
                    :pending-exams="labRequests === null && imagingRequests === null ? null : pendingExams"
                    :open-surgeries="openSurgeries"
                    :open-consultations="openConsultations.length"
                    @navigate="exitNavigate"
                />
            </div>
        </template>

        <!-- ADR-164 — la mutation en saisie libre n'existe que sans lit configuré. -->
        <Dialog v-if="!bedsConfigured" v-model:open="moveOpen" title="Changer de service / lit" description="L’emplacement actuel est fermé, un nouveau s’ouvre : le séjour continue et l’historique est conservé." size="lg">
            <form id="stay-move" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitMove">
                <FormField label="Service" :error="moveForm.errors.service">
                    <Input v-model="moveForm.service" placeholder="Ex. : Réanimation" maxlength="150" />
                </FormField>
                <FormField label="Chambre / lit" :error="moveForm.errors.room_bed">
                    <Input v-model="moveForm.room_bed" placeholder="Ex. : Box 2" maxlength="100" />
                </FormField>
                <FormField as="div" label="Niveau de soins" required class="sm:col-span-2" :error="moveForm.errors.care_level">
                    <Select v-model="moveForm.care_level" :options="careLevels" aria-label="Niveau de soins" />
                </FormField>
                <FormField label="Motif" class="sm:col-span-2" :error="moveForm.errors.reason">
                    <Textarea v-model="moveForm.reason" rows="2" placeholder="Ex. : aggravation, choc septique" />
                </FormField>
            </form>
            <template #footer>
                <Button type="button" variant="white-outline" :disabled="moveForm.processing" @click="moveOpen = false">Annuler</Button>
                <Button type="submit" form="stay-move" :disabled="moveForm.processing"><ArrowRightLeft class="h-4 w-4" />Déplacer le patient</Button>
            </template>
        </Dialog>

        <Dialog
            :open="bedDialog !== null"
            :title="bedDialog === 'move' ? 'Changer de lit' : (stay.bed_uuid ? 'Corriger le lit' : 'Attribuer un lit')"
            :description="bedDialog === 'move'
                ? 'L’emplacement actuel est fermé, un nouveau s’ouvre : le séjour continue et l’historique est conservé. Le lit quitté redevient libre.'
                : (stay.bed_uuid ? 'Pour une erreur de saisie : l’emplacement actuel est corrigé, sans mouvement. L’ancienne valeur reste à l’audit.' : 'Le patient est installé dans ce lit depuis son admission ; le service et le niveau de soins suivent le lit.')"
            size="lg"
            @update:open="(value) => { if (!value) bedDialog = null; }"
        >
            <form id="stay-bed" class="space-y-4" @submit.prevent="submitBed">
                <p v-if="stay.bed_uuid" class="text-sm text-muted-foreground">Actuellement : <span class="font-medium text-foreground">{{ stay.service }} · {{ stay.room_bed }}</span></p>
                <BedPicker v-model="bedForm.hospital_bed_uuid" :services="freeBeds" />
                <p v-if="chosenBed" class="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-foreground">
                    <Check class="me-1 inline h-4 w-4 text-primary" aria-hidden="true" />{{ chosenBed.service.name }} · {{ chosenBed.room.name }} · {{ chosenBed.bed.label }}
                    <span class="text-muted-foreground"> — {{ chosenBed.service.care_level_label }}</span>
                </p>
                <FormField v-if="bedDialog === 'move'" label="Motif" :error="bedForm.errors.reason">
                    <Textarea v-model="bedForm.reason" rows="2" placeholder="Ex. : aggravation, rapprochement du poste de soins" />
                </FormField>
                <FormError :message="bedForm.errors.hospital_bed_uuid ?? bedForm.errors.service ?? bedForm.errors.room_bed" />
            </form>
            <template #footer>
                <Button type="button" variant="white-outline" :disabled="bedForm.processing" @click="bedDialog = null">Annuler</Button>
                <Button type="submit" form="stay-bed" :disabled="bedForm.processing || !bedForm.hospital_bed_uuid">
                    <template v-if="bedDialog === 'move'"><ArrowRightLeft class="h-4 w-4" />Déplacer le patient</template>
                    <template v-else><BedDouble class="h-4 w-4" />Installer dans ce lit</template>
                </Button>
            </template>
        </Dialog>

        <Dialog :open="correctingReading !== null" title="Corriger un relevé" description="La valeur remplacée reste à l’audit, avec l’auteur de la correction." size="lg" @update:open="(value) => { if (!value) correctingReading = null; }">
            <form id="reading-correction" class="grid gap-3 sm:grid-cols-3" @submit.prevent="submitCorrection">
                <FormField label="TA systolique" :error="correctionForm.errors.blood_pressure_systolic"><Input v-model="correctionForm.blood_pressure_systolic" type="number" /></FormField>
                <FormField label="TA diastolique" :error="correctionForm.errors.blood_pressure_diastolic"><Input v-model="correctionForm.blood_pressure_diastolic" type="number" /></FormField>
                <FormField label="FC" :error="correctionForm.errors.heart_rate"><Input v-model="correctionForm.heart_rate" type="number" /></FormField>
                <FormField label="SpO₂" :error="correctionForm.errors.spo2"><Input v-model="correctionForm.spo2" type="number" /></FormField>
                <FormField label="Température" :error="correctionForm.errors.temperature_celsius"><Input v-model="correctionForm.temperature_celsius" type="number" step="0.1" /></FormField>
                <FormField label="Mesuré le" :error="correctionForm.errors.measured_at"><Input v-model="correctionForm.measured_at" type="datetime-local" /></FormField>
                <FormField label="Observation" class="sm:col-span-3" :error="correctionForm.errors.notes"><Input v-model="correctionForm.notes" maxlength="1000" /></FormField>
            </form>
            <template #footer>
                <Button type="button" variant="white-outline" :disabled="correctionForm.processing" @click="correctingReading = null">Annuler</Button>
                <Button type="submit" form="reading-correction" :disabled="correctionForm.processing"><Check class="h-4 w-4" />Enregistrer la correction</Button>
            </template>
        </Dialog>

        <!-- ADR-163 — annuler un transfert au bloc : la demande reste en base,
             annulée, avec son auteur et son motif ; le séjour continue. -->
        <Dialog
            :open="cancellingSurgery !== null"
            title="Annuler le transfert au bloc"
            description="Le bloc ne l’a pas encore programmé : la demande est retirée, sans être effacée. Le patient reste dans son lit."
            size="md"
            :dismissible="false"
            @update:open="(value) => { if (!value) cancellingSurgery = null; }"
        >
            <form id="surgery-cancel" class="space-y-3" @submit.prevent="submitSurgeryCancel">
                <p class="text-sm text-foreground">Intervention : <strong class="font-semibold">{{ cancellingSurgery?.procedure_name }}</strong></p>
                <p v-if="cancellingSurgery?.origin === 'MEDICINE'" class="rounded-md bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                    Demandée depuis une consultation : sa conduite à tenir « Chirurgie » est annulée avec elle.
                </p>
                <FormField label="Motif (facultatif)" :error="surgeryCancelForm.errors.reason">
                    <Textarea v-model="surgeryCancelForm.reason" :rows="2" maxlength="500" placeholder="Ex. : amélioration clinique, demandé par erreur" />
                </FormField>
                <FormError :message="surgeryCancelForm.errors.surgical_request" />
            </form>
            <template #footer>
                <Button type="button" variant="white-outline" :disabled="surgeryCancelForm.processing" @click="cancellingSurgery = null">Garder la demande</Button>
                <Button type="submit" form="surgery-cancel" variant="destructive" :disabled="surgeryCancelForm.processing"><Undo2 class="h-4 w-4" />Annuler le transfert</Button>
            </template>
        </Dialog>

        <!-- Transférer au bloc est un acte signé (ADR-106) : l'intervention est
             nommée, la responsabilité aussi, et la fenêtre ne se ferme pas au
             clic extérieur. -->
        <Dialog
            v-model:open="surgeryOpen"
            title="Transférer au bloc opératoire"
            description="La demande part au bloc, qui la programme. Le séjour continue : le patient garde son lit."
            size="lg"
            :dismissible="false"
        >
            <form id="surgery-transfer" class="space-y-4" @submit.prevent="submitSurgery">
                <FormField as="div" label="Intervention envisagée" required :error="surgeryForm.errors.catalog_item_uuid">
                    <IconInput v-model="procedureQuery" :icon="Search" type="search" placeholder="Chercher une intervention" aria-label="Chercher une intervention" autocomplete="off" />
                    <div class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-border" role="radiogroup" aria-label="Interventions">
                        <button
                            v-for="procedure in filteredProcedures"
                            :key="procedure.uuid"
                            type="button"
                            role="radio"
                            :aria-checked="surgeryForm.catalog_item_uuid === procedure.uuid"
                            :class="[
                                'flex w-full items-center justify-between gap-3 border-b border-border px-3 py-2 text-start text-sm last:border-b-0',
                                surgeryForm.catalog_item_uuid === procedure.uuid ? 'bg-primary/10 font-semibold text-primary' : 'text-foreground hover:bg-accent',
                            ]"
                            @click="surgeryForm.catalog_item_uuid = procedure.uuid"
                        >
                            <span class="min-w-0 truncate">{{ procedure.name }}</span>
                            <Check v-if="surgeryForm.catalog_item_uuid === procedure.uuid" class="h-4 w-4 shrink-0" />
                        </button>
                        <p v-if="!filteredProcedures.length" class="px-3 py-4 text-center text-xs text-muted-foreground">Aucune intervention ne correspond.</p>
                    </div>
                </FormField>
                <div class="grid gap-4 sm:grid-cols-[1fr_12rem]">
                    <FormField label="Indication" :error="surgeryForm.errors.indication">
                        <Textarea v-model="surgeryForm.indication" rows="2" placeholder="Ce qui justifie le passage au bloc" />
                    </FormField>
                    <FormField label="Priorité" required :error="surgeryForm.errors.priority">
                        <Select v-model="surgeryForm.priority" :options="PRIORITIES" aria-label="Priorité" />
                    </FormField>
                </div>
                <FormField label="Note pour l’équipe du bloc" :error="surgeryForm.errors.notes">
                    <Textarea v-model="surgeryForm.notes" rows="2" />
                </FormField>
                <p class="rounded-md bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                    Repris du séjour, sans ressaisie : service, chambre et diagnostic d’entrée.
                    Demandé sous la responsabilité de <strong class="font-semibold text-foreground">{{ $page.props.auth.user.name }}</strong>.
                </p>
            </form>
            <template #footer>
                <Button type="button" variant="white-outline" :disabled="surgeryForm.processing" @click="surgeryOpen = false">Annuler</Button>
                <Button type="submit" form="surgery-transfer" :disabled="surgeryForm.processing || !selectedProcedure">
                    <Scissors class="h-4 w-4" />Transférer au bloc
                </Button>
            </template>
        </Dialog>
    </div>
</template>
