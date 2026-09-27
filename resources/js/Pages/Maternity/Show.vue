<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    ArrowRight,
    Baby,
    CalendarClock,
    Check,
    ClipboardList,
    DoorOpen,
    FlaskConical,
    Heart,
    HeartPulse,
    LayoutDashboard,
    Lock,
    MessageSquareText,
    Pill,
    Save,
    Scissors,
    Send,
    Stethoscope,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClinicalPatientHeader from '@/Components/Clinical/ClinicalPatientHeader.vue';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import MaternityActsPanel from '@/Components/Maternity/MaternityActsPanel.vue';
import MaternityCompletionDialog from '@/Components/Maternity/MaternityCompletionDialog.vue';
import MaternityEncounterChooser from '@/Components/Maternity/MaternityEncounterChooser.vue';
import MaternityEncounterHeader from '@/Components/Maternity/MaternityEncounterHeader.vue';
import MaternityParaclinicalPanel from '@/Components/Maternity/MaternityParaclinicalPanel.vue';
import MaternityPrescriptionPanel from '@/Components/Maternity/MaternityPrescriptionPanel.vue';
import MaternityWorkflowStepper from '@/Components/Maternity/MaternityWorkflowStepper.vue';
import PregnancyDatingDialog from '@/Components/Maternity/PregnancyDatingDialog.vue';
import PregnancyHistorySheet from '@/Components/Maternity/PregnancyHistorySheet.vue';
import DeliveryAdmissionStep from '@/Components/Maternity/Steps/DeliveryAdmissionStep.vue';
import DeliveryBirthStep from '@/Components/Maternity/Steps/DeliveryBirthStep.vue';
import DeliveryLaborStep from '@/Components/Maternity/Steps/DeliveryLaborStep.vue';
import DeliveryMonitoringStep from '@/Components/Maternity/Steps/DeliveryMonitoringStep.vue';
import DeliveryNewbornStep from '@/Components/Maternity/Steps/DeliveryNewbornStep.vue';
import DeliveryTransmissionStep from '@/Components/Maternity/Steps/DeliveryTransmissionStep.vue';
import PrenatalAppointmentStep from '@/Components/Maternity/Steps/PrenatalAppointmentStep.vue';
import PrenatalExaminationStep from '@/Components/Maternity/Steps/PrenatalExaminationStep.vue';
import PrenatalInterviewStep from '@/Components/Maternity/Steps/PrenatalInterviewStep.vue';
import PrenatalOverviewStep from '@/Components/Maternity/Steps/PrenatalOverviewStep.vue';
import PrenatalSummaryStep from '@/Components/Maternity/Steps/PrenatalSummaryStep.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormError from '@/Components/UI/FormError.vue';
import { useAutosave } from '@/composables/useAutosave';
import { useFormDraft } from '@/composables/useFormDraft';
import { formatDateTime } from '@/utilities/date';
import { continuesSinglePregnancy, neighbours, stepFilled, stepFromHash, stepsFor } from '@/utilities/maternityWorkflow';

defineOptions({ layout: AppLayout });

/**
 * ADR-204 — le dossier Maternité d'un passage, en deux parcours liés à la
 * même grossesse : la consultation prénatale et l'accouchement.
 *
 * Cette page n'orchestre que le parcours : le choix du type, l'étape en cours,
 * l'enregistrement automatique et la finalisation. Chaque étape est son propre
 * composant (`Components/Maternity/Steps`), et le serveur reste seul juge de
 * ce qui s'enregistre.
 *
 *   enregistrer ≠ terminer   l'enregistrement automatique écrit le dossier ;
 *                            seule la finalisation le clôt
 *   « Suivant »              enregistre ce qui reste, puis passe à l'étape
 *                            suivante — jamais un tunnel : le stepper ouvre
 *                            n'importe quelle étape
 */
const props = defineProps({
    orientation: Object,
    record: Object,
    procedureCatalog: Array,
    capabilities: Object,
    /** Le matériel que ce compte peut déclarer pour cette prise en charge (ADR-142). */
    consumableCatalog: { type: Array, default: () => [] },
    /** Les demandes de matériel déjà transmises à la Pharmacie pour cette prise en charge. */
    consumableRequests: { type: Array, default: () => [] },
    /** Projection partagée des constantes du passage (ADR-054), lecture seule. */
    careRecord: { type: Object, default: null },
    careRecordUrl: { type: String, default: null },
    allergies: { type: Array, default: () => [] },
    /** Ce que la Réception a demandé à la Maternité, avec ce qui est déjà enregistré (ADR-136). */
    plannedProcedures: { type: Array, default: () => [] },
    actProfile: { type: Object, default: () => ({ sections: [], expected_newborns: null }) },
    /** Les repères rappelés sous les champs : servis par le serveur, jamais recopiés ici (ADR-137). */
    maternityReference: { type: Object, required: true },
    /** La saisie non enregistrée de ce compte — panier d'actes et césarienne (ADR-136). */
    recordDraft: { type: Object, default: null },
    /** ADR-144 — les bébés de ce dossier qui ont déjà leur dossier patient, par identité de fiche. */
    newbornPatients: { type: Object, default: () => ({}) },
    /** ADR-145 — les dossiers des bébés (état, liens, création), la même projection que le détail du passage. */
    babies: { type: Object, default: null },
    pregnancySelectionRequired: { type: Boolean, default: false },
    activePregnancies: { type: Array, default: () => [] },
    pregnancy: { type: Object, default: null },
    pregnancyHistory: { type: Array, default: () => [] },
    previousPregnancies: { type: Array, default: () => [] },
    prenatalComparison: { type: Object, default: null },
    /** ADR-204 — le parcours choisi (ou déduit pour un dossier d'avant ce choix). */
    encounter: { type: Object, required: true },
    encounterOptions: { type: Array, default: () => [] },
    encounterFields: { type: Object, required: true },
    labRequests: { type: Array, default: null },
    imagingRequests: { type: Array, default: null },
    paraclinicalOptions: { type: Object, default: () => ({}) },
    /** ADR-205 — les ordonnances de ce dossier ; `null` : le compte ne les lit pas. */
    prescriptions: { type: Array, default: null },
    prescriptionOptions: { type: Object, default: () => ({ medicines: [], routes: [] }) },
    paraclinicalHistory: { type: Object, default: null },
    prenatalAdvice: { type: Object, default: null },
    appointment: { type: Object, default: null },
    upcomingAppointments: { type: Array, default: () => [] },
});

const patient = computed(() => props.orientation.episode.patient);
const episode = computed(() => props.orientation.episode);
const readOnly = computed(() => ! props.capabilities.can_edit);
const inProgress = computed(() => props.orientation.status === 'IN_PROGRESS');
const workflowType = computed(() => props.encounter.effective ?? null);
const isDelivery = computed(() => workflowType.value === 'DELIVERY');

/** Cinq au maximum : `UpdateMaternityRecordRequest` refuse au-delà. */
const MAX_NEWBORNS = 5;
/**
 * Les soins du bébé se notent **par nouveau-né** (ADR-139) : avec des jumeaux,
 * l'un peut être sous photothérapie et pas l'autre.
 */
const blankNewborn = () => ({ first_name: '', last_name: '', sex: '', birth_weight_g: '', condition: '', apgar: '', care_notes: '' });
const savedPrenatal = props.record?.prenatal_data ?? {};
const savedAppointment = savedPrenatal.next_appointment ?? {};

const form = useForm({
    pregnancy_choice: '',
    pregnancy_uuid: '',
    obstetric_context: props.record?.obstetric_context ?? '',
    pregnancy_data: {
        gravidity: props.record?.pregnancy_data?.gravidity ?? props.pregnancy?.gravidity ?? '',
        parity: props.record?.pregnancy_data?.parity ?? props.pregnancy?.parity ?? '',
        last_menstrual_period: props.record?.pregnancy_data?.last_menstrual_period ?? props.pregnancy?.last_menstrual_period ?? '',
        estimated_due_date: props.record?.pregnancy_data?.estimated_due_date ?? props.pregnancy?.estimated_due_date ?? '',
        risk_factors: props.record?.pregnancy_data?.risk_factors ?? props.pregnancy?.risk_factors ?? '',
    },
    prenatal_data: {
        gestational_age_weeks: props.record?.gestational_age_weeks ?? savedPrenatal.gestational_age_weeks ?? props.pregnancy?.gestational_age_weeks ?? '',
        gestational_age_days: props.record?.gestational_age_days ?? savedPrenatal.gestational_age_days ?? props.pregnancy?.gestational_age_days ?? '',
        visit_reason: savedPrenatal.visit_reason ?? '',
        visit_reason_details: savedPrenatal.visit_reason_details ?? '',
        reported_since_last: savedPrenatal.reported_since_last ?? [],
        interval_notes: savedPrenatal.interval_notes ?? '',
        fundal_height_cm: savedPrenatal.fundal_height_cm ?? '',
        fetal_heart_rate: savedPrenatal.fetal_heart_rate ?? '',
        fetal_movements: savedPrenatal.fetal_movements ?? '',
        contractions: savedPrenatal.contractions ?? '',
        presentation: savedPrenatal.presentation ?? '',
        notes: savedPrenatal.notes ?? '',
        clinical_summary: savedPrenatal.clinical_summary ?? '',
        watch_points: savedPrenatal.watch_points ?? '',
        plan: savedPrenatal.plan ?? '',
        next_appointment: {
            enabled: Boolean(savedAppointment.enabled),
            scheduled_at: savedAppointment.scheduled_at ?? '',
            reason: savedAppointment.reason ?? '',
            notes: savedAppointment.notes ?? '',
        },
    },
    labor_data: {
        started_at: props.record?.labor_data?.started_at ?? '',
        membranes_status: props.record?.labor_data?.membranes_status ?? 'UNKNOWN',
        cervical_dilation_cm: props.record?.labor_data?.cervical_dilation_cm ?? '',
        contractions: props.record?.labor_data?.contractions ?? '',
        surveillance_notes: props.record?.labor_data?.surveillance_notes ?? '',
    },
    delivery_data: {
        occurred_at: props.record?.delivery_data?.occurred_at ?? '',
        mode: props.record?.delivery_data?.mode ?? '',
        placenta_status: props.record?.delivery_data?.placenta_status ?? '',
        complications: props.record?.delivery_data?.complications ?? '',
    },
    newborn_data: {
        // Un nouveau-né enregistré avant les soins par bébé n'a pas ce champ : on le lui donne vide.
        newborns: props.record?.newborn_data?.newborns?.length
            ? props.record.newborn_data.newborns.map((newborn) => ({ ...blankNewborn(), ...newborn }))
            // Un accouchement gémellaire annonce deux enfants : deux fiches
            // vides s'ouvrent, jamais deux fiches remplies (ADR-136).
            : Array.from({ length: Math.min(props.actProfile.expected_newborns ?? 1, MAX_NEWBORNS) }, () => blankNewborn()),
    },
    maternal_care_notes: props.record?.maternal_care_notes ?? '',
    baby_care_notes: props.record?.baby_care_notes ?? '',
    observations: props.record?.observations ?? '',
    transmission_notes: props.record?.transmission_notes ?? '',
});

// Une note « Soins bébé » d'avant la saisie par nouveau-né reste visible tant qu'elle existe.
const legacyBabyCare = Boolean(String(props.record?.baby_care_notes ?? '').trim());

// Le panier d'actes (ADR-138) et le matériel qui part avec lui (ADR-142),
// tenus ici pour vivre dans le brouillon ; le panneau les affiche.
const basketForm = useForm({ lines: [], consumables: [], consumable_notes: '' });
const cesareanForm = useForm({ type: 'SIMPLE', indication: '' });
const completeForm = useForm({ medicine_note: '' });
const datingForm = useForm({
    dating_method: props.pregnancy?.dating_method ?? 'LMP',
    last_menstrual_period: props.pregnancy?.last_menstrual_period ?? '',
    estimated_due_date: props.pregnancy?.estimated_due_date ?? '',
    reason: '',
});

// ── La grossesse ─────────────────────────────────────────────────────────
// Le rattachement reste explicite (ADR-201). Tant qu'il manque, rien ne part :
// le serveur exigerait ce choix, et un enregistrement automatique ne choisit pas.
const pregnancyLinked = computed(() => Boolean(props.record?.pregnancy_id));
const pregnancyChosen = computed(() => pregnancyLinked.value
    || form.pregnancy_choice === 'CREATE'
    || (form.pregnancy_choice === 'CONTINUE' && form.pregnancy_uuid !== ''));

const choosePregnancy = (uuid) => {
    form.pregnancy_choice = 'CONTINUE';
    form.pregnancy_uuid = uuid;
    const selected = props.activePregnancies.find((pregnancy) => pregnancy.uuid === uuid);
    if (selected) {
        form.pregnancy_data.gravidity = selected.gravidity ?? '';
        form.pregnancy_data.parity = selected.parity ?? '';
        form.pregnancy_data.last_menstrual_period = selected.last_menstrual_period ?? '';
        form.pregnancy_data.estimated_due_date = selected.estimated_due_date ?? '';
        form.pregnancy_data.risk_factors = selected.risk_factors ?? '';
    }
    // Le choix est une décision : il part tout de suite, sans attendre une frappe.
    autosave.flush();
};
/** Une seule grossesse active : l'en-tête la montre et la continue, sans seconde carte (ADR-204). */
const continuesFromHeader = computed(() => continuesSinglePregnancy(props.pregnancySelectionRequired, props.activePregnancies, props.pregnancy));
const chooseNewPregnancy = () => {
    form.pregnancy_choice = 'CREATE';
    form.pregnancy_uuid = '';
    form.pregnancy_data = { gravidity: '', parity: '', last_menstrual_period: '', estimated_due_date: '', risk_factors: '' };
};

const correctingDating = ref(false);
const openDatingCorrection = () => {
    datingForm.clearErrors();
    datingForm.dating_method = props.pregnancy?.dating_method ?? 'LMP';
    datingForm.last_menstrual_period = props.pregnancy?.last_menstrual_period ?? '';
    datingForm.estimated_due_date = props.pregnancy?.estimated_due_date ?? '';
    datingForm.reason = '';
    correctingDating.value = true;
};
const saveDating = () => datingForm.put(
    `/maternity/orientations/${props.orientation.uuid}/pregnancy/dating`,
    { preserveScroll: true, onSuccess: () => { correctingDating.value = false; } },
);

// ── Enregistrement automatique ───────────────────────────────────────────
/**
 * Un bloc que le compte n'a pas le droit d'écrire ne part pas.
 *
 * `UpdateMaternityRecordRequest` les déclare `prohibited` sans la permission
 * correspondante : les envoyer ferait refuser tout l'enregistrement. Le
 * serveur garde sa règle ; ceci n'envoie simplement plus ce qu'il refuse.
 */
const payloadFor = (data) => {
    const payload = { ...data };

    if (! props.capabilities.can_prenatal) {
        delete payload.pregnancy_data;
        delete payload.prenatal_data;
    }
    if (! props.capabilities.can_labor) delete payload.labor_data;
    if (! props.capabilities.can_delivery) delete payload.delivery_data;
    if (! props.capabilities.can_newborn) {
        delete payload.newborn_data;
        delete payload.baby_care_notes;
    }
    if (pregnancyLinked.value) {
        delete payload.pregnancy_choice;
        delete payload.pregnancy_uuid;
    }

    return payload;
};

/** Ce qui ne change jamais d'un enregistrement à l'autre : inutile de le relire. */
const STATIC_PROPS = ['procedureCatalog', 'consumableCatalog', 'maternityReference', 'encounterOptions', 'encounterFields', 'paraclinicalOptions', 'previousPregnancies', 'recordDraft'];
const send = (options) => form
    .transform(payloadFor)
    .put(`/maternity/orientations/${props.orientation.uuid}/record`, { ...options, except: STATIC_PROPS });

const autosave = useAutosave(form, send, {
    enabled: () => Boolean(props.capabilities.can_edit && props.record && pregnancyChosen.value),
});

// Le serveur donne à un bébé son identité (`uuid`) au moment où un patient en
// dépend : l'écran la reprend, jamais par-dessus une saisie en cours.
watch(() => props.record?.newborn_data?.newborns, (saved) => {
    if (! saved || form.isDirty) return;

    saved.forEach((entry, index) => {
        const mine = form.newborn_data.newborns[index];

        if (! mine) return;
        if (entry.uuid && ! mine.uuid) mine.uuid = entry.uuid;
        // Le sexe choisi à la création du dossier patient est écrit dans la fiche.
        if (entry.sex && mine.sex !== entry.sex) mine.sex = entry.sex;
    });
    form.defaults();
}, { deep: true });

const addNewborn = () => {
    if (form.newborn_data.newborns.length >= MAX_NEWBORNS) return;
    form.newborn_data.newborns.push(blankNewborn());
};

// ── Brouillon : panier d'actes et césarienne ─────────────────────────────
// Le dossier s'enregistre de lui-même ; ce qui n'est pas encore un acte (le
// panier) ou une décision (la césarienne) survit à une actualisation, côté
// serveur et propre à ce compte (ADR-073, ADR-136).
const catalogByUuid = computed(() => Object.fromEntries((props.procedureCatalog ?? []).map((item) => [item.uuid, item])));
const consumableByUuid = computed(() => Object.fromEntries((props.consumableCatalog ?? []).map((item) => [item.medicine_uuid, item])));
const draft = useFormDraft({
    endpoint: `/maternity/orientations/${props.orientation.uuid}/draft`,
    initial: props.recordDraft,
    enabled: Boolean(props.capabilities.can_edit),
    forms: { basket: basketForm, cesarean: cesareanForm },
});
// Un panier restauré peut porter un acte ou un produit devenu inéligible : il ne partirait jamais.
basketForm.lines = basketForm.lines.filter((line) => catalogByUuid.value[line.catalog_item_uuid]);
basketForm.consumables = (basketForm.consumables ?? []).filter((line) => consumableByUuid.value[line.medicine_uuid]);
const draftShown = computed(() => (draft.restored.value || draft.savedAt.value) && (basketForm.lines.length > 0 || basketForm.consumables.length > 0 || cesareanForm.indication));

const discardDraft = () => {
    // Suspendre avant la requête : sinon le minuteur en attente recréerait le brouillon qu'on efface.
    draft.suspend();
    router.delete(`/maternity/orientations/${props.orientation.uuid}/draft`, {
        preserveScroll: true,
        onSuccess: () => {
            basketForm.lines = [];
            basketForm.consumables = [];
            basketForm.consumable_notes = '';
            cesareanForm.reset();
            draft.markSaved();
        },
        onFinish: () => draft.resume(),
    });
};

// ── Le parcours ──────────────────────────────────────────────────────────
const STEP_ICONS = {
    overview: LayoutDashboard,
    interview: MessageSquareText,
    examination: Stethoscope,
    paraclinical: FlaskConical,
    prescription: Pill,
    summary: ClipboardList,
    appointment: CalendarClock,
    admission: DoorOpen,
    labor: Activity,
    monitoring: HeartPulse,
    birth: Heart,
    newborn: Baby,
    transmission: Send,
};
const activeRequests = (requests) => (requests ?? []).filter((request) => request.status !== 'CANCELLED');
const steps = computed(() => stepsFor(workflowType.value).map((step) => ({
    ...step,
    icon: STEP_ICONS[step.key],
    filled: stepFilled(step.key, form, {
        pregnancyLinked: pregnancyLinked.value,
        procedures: props.record?.procedures?.length ?? 0,
        labCount: activeRequests(props.labRequests).length,
        imagingCount: activeRequests(props.imagingRequests).length,
        prescriptionCount: (props.prescriptions ?? []).filter((prescription) => prescription.status !== 'CANCELLED').length,
    }),
})));

// La première étape au rendu serveur ; l'adresse (`#examen`) n'est lue qu'une
// fois la page dans le navigateur — le serveur ne la connaît pas.
const currentStep = ref(stepsFor(workflowType.value)[0]?.key ?? null);
const around = computed(() => neighbours(steps.value, currentStep.value));
const current = computed(() => steps.value.find((step) => step.key === currentStep.value) ?? null);
const workflowTop = ref(null);
const historyOpen = ref(false);

const goTo = async (key, { scroll = true } = {}) => {
    const step = steps.value.find((candidate) => candidate.key === key);
    if (! step) return;

    currentStep.value = key;
    if (typeof window !== 'undefined') {
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}#${step.hash}`);
    }
    if (scroll) {
        await nextTick();
        workflowTop.value?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    }
};

// Un lien vers une autre étape de la même page (`#ordonnance`) ne recharge rien : on le suit.
const followHash = () => {
    if (window.location.hash) goTo(stepFromHash(steps.value, window.location.hash), { scroll: false });
};
onMounted(() => {
    followHash();
    window.addEventListener('hashchange', followHash);
});
onBeforeUnmount(() => window.removeEventListener('hashchange', followHash));
// Un changement de parcours ramène à sa première étape.
watch(workflowType, (type) => { currentStep.value = stepsFor(type)[0]?.key ?? null; });

/** « Précédent » : aucune perte — les étapes partagent le même dossier, qui continue de s'enregistrer. */
const previous = () => around.value.previous && goTo(around.value.previous.key);

/** « Suivant » : enregistre d'abord ce qui reste ; un refus garde l'étape et montre l'erreur. */
const advancing = ref(false);
const next = () => {
    if (! around.value.next || advancing.value) return;
    advancing.value = true;
    autosave.flush(
        () => { advancing.value = false; goTo(around.value.next.key); },
        () => { advancing.value = false; },
    );
};
const nextBlocked = computed(() => Boolean(props.capabilities.can_edit && props.record && ! pregnancyChosen.value));

// ── Choix du parcours ────────────────────────────────────────────────────
const startingEncounter = ref(null);
const changingEncounter = ref(false);
const encounterError = ref('');
const startEncounter = (type) => {
    if (startingEncounter.value) return;

    const proceed = () => {
        startingEncounter.value = type;
        encounterError.value = '';
        router.post(`/maternity/orientations/${props.orientation.uuid}/parcours`, { encounter_type: type }, {
            preserveScroll: true,
            onSuccess: () => { changingEncounter.value = false; },
            onError: (errors) => { encounterError.value = Object.values(errors)[0] ?? ''; },
            onFinish: () => { startingEncounter.value = null; },
        });
    };

    // Changer de parcours en cours de saisie : ce qui est tapé part d'abord.
    if (props.record) autosave.flush(proceed);
    else proceed();
};
const canChangeEncounter = computed(() => Boolean(props.capabilities.can_start_encounter && props.record && ! props.encounter.finalized));

// ── Césarienne ───────────────────────────────────────────────────────────
// Décider la césarienne crée une demande Chirurgie sur ce même passage
// (ADR-067) : un geste confirmé, jamais un champ du dossier.
const confirmingCesarean = ref(false);
const CESAREAN_TYPES = { SIMPLE: 'Simple', TWIN: 'Gémellaire' };
const requestCesarean = () => cesareanForm.post(
    `/maternity/orientations/${props.orientation.uuid}/cesarean`,
    {
        preserveScroll: true,
        onSuccess: () => { cesareanForm.reset('indication'); confirmingCesarean.value = false; },
    },
);

// ── Finalisation ─────────────────────────────────────────────────────────
const confirmingComplete = ref(false);
const outcome = ref('end');
const openCompletion = () => {
    completeForm.clearErrors();
    autosave.flush(() => { confirmingComplete.value = true; }, () => { confirmingComplete.value = true; });
};
const completeCare = () => completeForm
    .transform((data) => ({
        orient_to_medicine: outcome.value === 'medicine',
        // La note n'a de sens que pour le médecin : sans orientation, elle ne part pas.
        medicine_note: outcome.value === 'medicine' ? (data.medicine_note?.trim() || null) : null,
    }))
    .post(`/maternity/orientations/${props.orientation.uuid}/complete`, {
        preserveScroll: true,
        onSuccess: () => { confirmingComplete.value = false; },
    });

const examItems = computed(() => [...activeRequests(props.labRequests), ...activeRequests(props.imagingRequests)].flatMap((request) => request.items ?? []));
const pendingExams = computed(() => examItems.value.filter((item) => ! item.resulted_at).length);
const newbornCount = computed(() => form.newborn_data.newborns.filter((newborn) => Object.entries(newborn).some(([key, value]) => key !== 'uuid' && value !== '' && value !== null)).length);

const patientName = computed(() => `${patient.value.first_name ?? ''} ${patient.value.last_name ?? ''}`.trim());
const firstError = (bag) => Object.values(bag ?? {})[0];
const defaultAppointmentReason = computed(() => props.encounterFields.default_appointment_reason?.[0]?.value ?? 'Suivi prénatal');
/** Le droit d'écrire un bloc : sans lui, l'étape se lit. */
const lockedWithout = (capability) => readOnly.value || ! props.capabilities[capability];
</script>

<template>
    <Head title="Dossier Maternité" />

    <div class="w-full space-y-4">
        <ClinicalPatientHeader
            :patient="patient"
            :episode="episode"
            :reason="orientation.reason"
            back-href="/maternity"
            back-label="Passages Maternité"
        />

        <!-- ADR-204 — une prise en charge commence par son parcours : choisi, jamais deviné. -->
        <template v-if="! workflowType">
            <MaternityEncounterChooser
                v-if="capabilities.can_start_encounter"
                :options="encounterOptions"
                :suggested="encounter.suggested"
                :processing="startingEncounter"
                @choose="startEncounter"
            />
            <Card v-else class="flex items-start gap-3 p-4">
                <Lock class="mt-0.5 h-4.5 w-4.5 shrink-0 text-muted-foreground" />
                <p class="text-sm text-muted-foreground">
                    Aucun dossier Maternité n’a été commencé pour ce passage<template v-if="! inProgress"> et la prise en charge n’est plus en cours</template>.
                </p>
            </Card>
            <FormError v-if="encounterError">{{ encounterError }}</FormError>
        </template>

        <template v-else>
            <MaternityEncounterHeader
                :encounter="encounter"
                :pregnancy="pregnancy"
                :paraclinical="paraclinicalHistory"
                :upcoming-appointments="upcomingAppointments"
                :can-change-encounter="canChangeEncounter"
                :can-correct-dating="Boolean(capabilities.can_correct_dating)"
                :continuable="continuesFromHeader"
                :continued="form.pregnancy_choice === 'CONTINUE' && form.pregnancy_uuid === pregnancy?.uuid"
                :disabled="readOnly"
                @open-history="historyOpen = true"
                @change-encounter="changingEncounter = true"
                @continue="choosePregnancy"
                @correct-dating="openDatingCorrection"
            />

            <!-- Lecture seule : dit pourquoi, avant qu'une saisie n'aille nulle part. -->
            <Card v-if="readOnly" class="flex items-start gap-3 border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20">
                <Lock class="mt-0.5 h-4.5 w-4.5 shrink-0 text-amber-600 dark:text-amber-300" />
                <div>
                    <p class="text-sm font-bold text-amber-900 dark:text-amber-100">Dossier en lecture seule</p>
                    <p class="mt-0.5 text-xs leading-5 text-amber-800 dark:text-amber-200">
                        <template v-if="encounter.finalized">{{ encounter.label }} terminé{{ isDelivery ? '' : 'e' }}<template v-if="encounter.completed_at"> le {{ formatDateTime(encounter.completed_at) }}</template> : le dossier n’est plus modifiable.</template>
                        <template v-else-if="! inProgress">La prise en charge Maternité de ce passage est {{ ({ COMPLETED: 'terminée', CANCELLED: 'annulée', PENDING: 'en attente' })[orientation.status] ?? orientation.status_label.toLowerCase() }} : le dossier n’est plus modifiable.</template>
                        <template v-else>Votre compte ne dispose pas du droit de modifier ce dossier.</template>
                    </p>
                </div>
            </Card>

            <!-- Rien ne s'enregistre tant que la grossesse n'est pas choisie : on le dit partout. -->
            <Card v-if="nextBlocked" class="flex flex-wrap items-center justify-between gap-3 border-sky-200 bg-sky-50/60 px-4 py-3 dark:border-sky-900 dark:bg-sky-950/20">
                <p class="text-xs leading-5 text-sky-900 dark:text-sky-100">
                    <strong>Choisissez la grossesse</strong> — continuer celle en cours ou en créer une. Rien ne s’enregistre avant ce choix.
                </p>
                <Button v-if="currentStep !== steps[0]?.key" type="button" size="sm" variant="outline" @click="goTo(steps[0].key)">Choisir maintenant</Button>
            </Card>

            <!-- Panier d'actes ou césarienne en cours de saisie : il survit à une actualisation (ADR-136). -->
            <Card v-if="draftShown" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-xs">
                <p class="flex items-center gap-2 text-muted-foreground">
                    <Save class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span><strong class="font-semibold text-foreground">Saisie en cours restaurée</strong> · panier d’actes ou césarienne, pas encore enregistrés</span>
                </p>
                <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-xs" @click="discardDraft">Effacer le brouillon</Button>
            </Card>

            <div ref="workflowTop" class="scroll-mt-4">
                <MaternityWorkflowStepper
                    :steps="steps"
                    :current="currentStep"
                    :label="isDelivery ? 'Étapes de l’accouchement' : 'Étapes de la consultation prénatale'"
                    @select="goTo"
                />
            </div>

            <Card class="overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3 sm:px-5">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-foreground">
                        <component :is="current?.icon" v-if="current?.icon" class="h-4 w-4 text-muted-foreground" aria-hidden="true" />{{ current?.label }}
                    </h2>
                    <p class="text-xs text-muted-foreground">Passage {{ episode.episode_number }}</p>
                </header>

                <div class="min-w-0 p-4 sm:p-5">
                    <!-- ── Consultation prénatale ── -->
                    <PrenatalOverviewStep
                        v-if="currentStep === 'overview'"
                        :form="form"
                        :pregnancy="pregnancy"
                        :active-pregnancies="activePregnancies"
                        :selection-required="pregnancySelectionRequired"
                        :capabilities="capabilities"
                        :read-only="readOnly"
                        :reference="maternityReference"
                        :comparison="prenatalComparison"
                        @continue="choosePregnancy"
                        @create="chooseNewPregnancy"
                    />
                    <PrenatalInterviewStep v-else-if="currentStep === 'interview'" :form="form" :options="encounterFields" :read-only="lockedWithout('can_prenatal')" />
                    <PrenatalExaminationStep
                        v-else-if="currentStep === 'examination'"
                        :form="form"
                        :options="encounterFields"
                        :reference="maternityReference"
                        :pregnancy="pregnancy"
                        :care-record="careRecord"
                        :care-record-url="careRecordUrl"
                        :allergies="allergies"
                        :read-only="lockedWithout('can_prenatal')"
                    />
                    <MaternityParaclinicalPanel
                        v-else-if="currentStep === 'paraclinical'"
                        :orientation-uuid="orientation.uuid"
                        :lab-requests="labRequests"
                        :imaging-requests="imagingRequests"
                        :options="paraclinicalOptions"
                        :capabilities="capabilities"
                        :history="paraclinicalHistory"
                        :advice="prenatalAdvice"
                        :show-recommendations="true"
                        :patient-label="patientName"
                        :active="! readOnly"
                    />
                    <!-- ADR-205 — la sage-femme prescrit, comme le médecin : même ordonnance, même Pharmacie. -->
                    <MaternityPrescriptionPanel
                        v-else-if="currentStep === 'prescription'"
                        :orientation-uuid="orientation.uuid"
                        :prescriptions="prescriptions"
                        :options="prescriptionOptions"
                        :capabilities="capabilities"
                        :patient="patient"
                        :care-record="careRecord"
                        :allergies="allergies"
                        :active="! readOnly"
                    />
                    <PrenatalSummaryStep
                        v-else-if="currentStep === 'summary'"
                        :form="form"
                        :read-only="lockedWithout('can_prenatal')"
                        :lab-requests="labRequests"
                        :imaging-requests="imagingRequests"
                    >
                        <template #acts>
                            <MaternityActsPanel
                                :orientation="orientation"
                                :record="record"
                                :procedure-catalog="procedureCatalog"
                                :consumable-catalog="consumableCatalog"
                                :consumable-requests="consumableRequests"
                                :planned-procedures="plannedProcedures"
                                :capabilities="capabilities"
                                :basket-form="basketForm"
                            />
                        </template>
                    </PrenatalSummaryStep>
                    <PrenatalAppointmentStep
                        v-else-if="currentStep === 'appointment'"
                        :form="form"
                        :read-only="lockedWithout('can_prenatal')"
                        :default-reason="defaultAppointmentReason"
                        :appointment="appointment"
                    />

                    <!-- ── Accouchement ── -->
                    <DeliveryAdmissionStep
                        v-else-if="currentStep === 'admission'"
                        :form="form"
                        :pregnancy="pregnancy"
                        :active-pregnancies="activePregnancies"
                        :selection-required="pregnancySelectionRequired"
                        :capabilities="capabilities"
                        :read-only="readOnly"
                        :reference="maternityReference"
                        :care-record="careRecord"
                        :care-record-url="careRecordUrl"
                        :allergies="allergies"
                        @continue="choosePregnancy"
                        @create="chooseNewPregnancy"
                    />
                    <DeliveryLaborStep v-else-if="currentStep === 'labor'" :form="form" :reference="maternityReference" :read-only="lockedWithout('can_labor')" />
                    <div v-else-if="currentStep === 'monitoring'" class="space-y-6">
                        <DeliveryMonitoringStep :form="form" :read-only="lockedWithout('can_labor')" :care-record="careRecord" :care-record-url="careRecordUrl" :allergies="allergies" />
                        <section class="space-y-3" aria-labelledby="delivery-exams-title">
                            <h3 id="delivery-exams-title" class="flex items-center gap-2 text-sm font-bold text-foreground"><FlaskConical class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Examens pendant le travail</h3>
                            <MaternityParaclinicalPanel
                                :orientation-uuid="orientation.uuid"
                                :lab-requests="labRequests"
                                :imaging-requests="imagingRequests"
                                :options="paraclinicalOptions"
                                :capabilities="capabilities"
                                :history="paraclinicalHistory"
                                :patient-label="patientName"
                                :active="! readOnly"
                            />
                        </section>
                    </div>
                    <DeliveryBirthStep
                        v-else-if="currentStep === 'birth'"
                        :form="form"
                        :cesarean-form="cesareanForm"
                        :can-request-cesarean="Boolean(capabilities.can_delivery && inProgress && ! encounter.finalized)"
                        :read-only="lockedWithout('can_delivery')"
                        @request-cesarean="confirmingCesarean = true"
                    />
                    <DeliveryNewbornStep
                        v-else-if="currentStep === 'newborn'"
                        :form="form"
                        :reference="maternityReference"
                        :patient="patient"
                        :babies="babies"
                        :newborn-patients="newbornPatients"
                        :expected-newborns="actProfile.expected_newborns"
                        :max-newborns="MAX_NEWBORNS"
                        :read-only="lockedWithout('can_newborn')"
                        @add-newborn="addNewborn"
                    />
                    <!-- ADR-205 — la sage-femme prescrit, comme le médecin : même ordonnance, même Pharmacie. -->
                    <MaternityPrescriptionPanel
                        v-else-if="currentStep === 'prescription'"
                        :orientation-uuid="orientation.uuid"
                        :prescriptions="prescriptions"
                        :options="prescriptionOptions"
                        :capabilities="capabilities"
                        :patient="patient"
                        :care-record="careRecord"
                        :allergies="allergies"
                        :active="! readOnly"
                    />
                    <DeliveryTransmissionStep
                        v-else-if="currentStep === 'transmission'"
                        :form="form"
                        :read-only="readOnly"
                        :legacy-baby-care="legacyBabyCare"
                    >
                        <template #acts>
                            <MaternityActsPanel
                                :orientation="orientation"
                                :record="record"
                                :procedure-catalog="procedureCatalog"
                                :consumable-catalog="consumableCatalog"
                                :consumable-requests="consumableRequests"
                                :planned-procedures="plannedProcedures"
                                :capabilities="capabilities"
                                :basket-form="basketForm"
                            />
                        </template>
                    </DeliveryTransmissionStep>

                    <FormError v-if="form.errors.maternity_record" class="mt-4">{{ form.errors.maternity_record }}</FormError>
                </div>
            </Card>
        </template>

        <!-- Précédent · état de l'enregistrement · Suivant, toujours à portée de main. -->
        <div
            v-if="workflowType"
            class="sticky bottom-0 z-20 -mx-1 rounded-xl border border-border bg-card/95 px-3 py-2.5 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] backdrop-blur supports-[backdrop-filter]:bg-card/85 sm:px-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Button type="button" variant="outline" size="sm" :disabled="! around.previous" @click="previous">
                    <ArrowLeft class="h-4 w-4" />Précédent
                </Button>

                <ClinicalSaveStatus
                    v-if="capabilities.can_edit && record"
                    class="order-last w-full justify-center sm:order-none sm:w-auto"
                    :saving="autosave.saving.value"
                    :saved-at="autosave.savedAt.value"
                    :dirty="form.isDirty && pregnancyChosen"
                    :failed="autosave.failed.value"
                    retryable
                    @retry="autosave.retry()"
                />

                <div class="flex items-center gap-2">
                    <Button v-if="around.next" type="button" size="sm" :disabled="advancing || (nextBlocked && currentStep === steps[0]?.key)" @click="next">
                        {{ advancing ? 'Enregistrement…' : 'Suivant' }}<ArrowRight class="h-4 w-4" />
                    </Button>
                    <Button
                        v-else-if="capabilities.can_complete && record && ! encounter.finalized"
                        type="button"
                        size="sm"
                        variant="success"
                        :disabled="autosave.saving.value"
                        @click="openCompletion"
                    >
                        <Check class="h-4 w-4" />{{ encounter.completion_label }}
                    </Button>
                </div>
            </div>
        </div>

        <PregnancyHistorySheet
            v-model:open="historyOpen"
            :pregnancy="pregnancy"
            :history="pregnancyHistory"
            :previous-pregnancies="previousPregnancies"
            :comparison="prenatalComparison"
            :paraclinical-history="paraclinicalHistory"
        />

        <PregnancyDatingDialog v-model:open="correctingDating" :form="datingForm" @save="saveDating" />

        <Dialog
            :open="changingEncounter"
            title="Changer de parcours ?"
            description="Rien n’est effacé : seules les étapes proposées changent. Ce qui est déjà saisi reste dans le dossier du passage."
            size="lg"
            @update:open="changingEncounter = $event"
        >
            <MaternityEncounterChooser
                :options="encounterOptions"
                :suggested="encounter.suggested"
                :current="encounter.type"
                :processing="startingEncounter"
                title="Parcours de cette prise en charge"
                description="La consultation prénatale et l’accouchement restent liés à la même grossesse."
                @choose="startEncounter"
            />
            <FormError v-if="encounterError" class="mt-2">{{ encounterError }}</FormError>
        </Dialog>

        <Dialog
            :open="confirmingCesarean"
            title="Transmettre la césarienne à Chirurgie ?"
            description="Une demande est créée sur ce même passage. La programmation et l’intervention restent sous le contrôle du bloc opératoire ; Maternité n’enregistre aucun acte chirurgical."
            @update:open="confirmingCesarean = $event"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/35 dark:text-amber-300"><Scissors class="h-5 w-5" /></span>
            </template>

            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Patiente</dt><dd class="font-semibold text-foreground">{{ patientName }} · {{ episode.episode_number }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Type</dt><dd class="font-semibold text-foreground">{{ CESAREAN_TYPES[cesareanForm.type] }}</dd></div>
                <div class="flex flex-col gap-1"><dt class="text-muted-foreground">Indication</dt><dd class="rounded-md bg-muted px-3 py-2 text-foreground">{{ cesareanForm.indication }}</dd></div>
            </dl>
            <FormError v-if="firstError(cesareanForm.errors)" class="mt-2">{{ firstError(cesareanForm.errors) }}</FormError>

            <template #footer>
                <Button type="button" variant="outline" :disabled="cesareanForm.processing" @click="confirmingCesarean = false">Annuler</Button>
                <Button type="button" variant="warning" :disabled="cesareanForm.processing" @click="requestCesarean">
                    <Scissors class="h-4 w-4" />{{ cesareanForm.processing ? 'Transmission…' : 'Transmettre à Chirurgie' }}
                </Button>
            </template>
        </Dialog>

        <MaternityCompletionDialog
            v-model:open="confirmingComplete"
            v-model:outcome="outcome"
            :type="workflowType"
            :completion-label="encounter.completion_label"
            :patient-name="patientName"
            :episode-number="episode.episode_number"
            :dirty="form.isDirty && pregnancyChosen"
            :saving="autosave.saving.value"
            :pregnancy-linked="pregnancyLinked"
            :complete-form="completeForm"
            :appointment="form.prenatal_data.next_appointment"
            :delivered-at="form.delivery_data.occurred_at"
            :newborn-count="newbornCount"
            :exam-count="examItems.length"
            :pending-exam-count="pendingExams"
            @save-now="autosave.flush()"
            @confirm="completeCare"
        />
    </div>
</template>
