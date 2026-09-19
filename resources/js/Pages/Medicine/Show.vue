<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    Activity,
    ArrowLeft,
    BookMarked,
    Check,
    ChevronDown,
    ChevronUp,
    CircleAlert,
    CircleCheck,
    CirclePlus,
    Clipboard,
    ClipboardCheck,
    Clock,
    Eye,
    FileSearch,
    FileStack,
    FileText,
    History,
    Hourglass,
    Info,
    List,
    Lock,
    MessageSquare,
    Pencil,
    PenLine,
    Pill,
    Plus,
    Printer,
    RotateCcw,
    Save,
    HeartPulse,
    ScanLine,
    Search,
    Send,
    Stethoscope,
    Share2,
    ShieldCheck,
    Trash2,
    User,
    X,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ShadcnDialog from '@/Components/Shadcn/Dialog.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import MedicineWorkflowNav from '@/Components/Medicine/MedicineWorkflowNav.vue';
import ConsultationStepBar from '@/Components/Medicine/ConsultationStepBar.vue';
import ClinicalGeneralState from '@/Components/Clinical/ClinicalGeneralState.vue';
import ClinicalSystemsAccordion from '@/Components/Clinical/ClinicalSystemsAccordion.vue';
import ClinicalOrientationCard from '@/Components/Clinical/ClinicalOrientationCard.vue';
import ClinicalDischargeForm from '@/Components/Clinical/ClinicalDischargeForm.vue';
import ResizableSplit from '@/Components/UI/ResizableSplit.vue';
import SortableSections from '@/Components/UI/SortableSections.vue';
import ClinicalExaminationSummary from '@/Components/Clinical/ClinicalExaminationSummary.vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import ClinicalDiagnosisEntry from '@/Components/Clinical/ClinicalDiagnosisEntry.vue';
import ClinicalDiagnosisList from '@/Components/Clinical/ClinicalDiagnosisList.vue';
import ClinicalDiagnosisSuggestions from '@/Components/Clinical/ClinicalDiagnosisSuggestions.vue';
import ClinicalPrescriptionSuggestions from '@/Components/Clinical/ClinicalPrescriptionSuggestions.vue';
import PrescriptionLineEditor from '@/Components/Clinical/PrescriptionLineEditor.vue';
import CareSummaryReadOnly from '@/Components/Surgery/CareSummaryReadOnly.vue';
import ClinicalVitalsCorrection from '@/Components/Clinical/ClinicalVitalsCorrection.vue';
import ClinicalPatientHeader from '@/Components/Clinical/ClinicalPatientHeader.vue';
import ClinicalSection from '@/Components/Clinical/ClinicalSection.vue';
import ClinicalCondensedHeader from '@/Components/Clinical/ClinicalCondensedHeader.vue';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import VitalSignsStrip from '@/Components/Clinical/VitalSignsStrip.vue';
import PatientContextPanel from '@/Components/Clinical/PatientContextPanel.vue';
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';
import ClinicalRichTextEditor from '@/Components/Clinical/ClinicalRichTextEditor.vue';
import ImagingReportDialog from '@/Components/Clinical/ImagingReportDialog.vue';
import { useFormDraft } from '@/composables/useFormDraft';
import { editorFieldsFor, isUndosedForm } from '@/utilities/posology';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

const page = usePage();

const props = defineProps({
    orientation: Object,
    care_record: Object,
    allergies: Array,
    antecedents: Array,
    familial_antecedents: { type: Array, default: () => [] },
    habitual_treatments: { type: Array, default: () => [] },
    consultation: Object,
    consultationDraft: { type: Object, default: null },
    patient_profile: { type: Object, default: () => ({}) },
    previous_consultations: { type: Array, default: () => [] },
    medical_discharge: Object,
    // La conduite à tenir : ce qui est décidé, ce qui est transmis,
    // et de quoi préremplir les demandes (ADR-084).
    consultation_orientation: Object,
    options: Object,
    capabilities: Object,
    pending_reasons: { type: Array, default: () => [] },
    // ADR-111 — ce que les protocoles de la clinique proposent. Calculé par
    // le serveur à chaque affichage, jamais enregistré tant que le médecin
    // ne retient rien.
    clinical_suggestions: { type: Object, default: null },
    current_step: String,
});

const episode = computed(() => props.orientation.episode);
const patient = computed(() => episode.value.patient);
const isEmergency = computed(() => episode.value.priority === 'EMERGENCY');
const emergencyForm = useForm({});
const showEmergencyConfirm = ref(false);
const showClinicalContext = ref(false);

/**
 * The sticky header's real height, measured rather than assumed.
 *
 * Its height genuinely varies: the draft banner appears and disappears, the
 * emergency badge wraps on narrow screens, designations can span two lines.
 * A hard-coded offset would leave the examination's notes column sliding
 * under the header the moment any of that changed, so the column reads this
 * value through the `--clinical-stack-top` custom property instead.
 *
 * 64px is the fixed application header; 12px keeps a small gap under it.
 */
const APP_HEADER_HEIGHT = 64;
/** Hauteur présumée du bandeau réduit avant sa première mesure. */
const CONDENSED_BAR_FALLBACK = 56;
const fullHeader = ref(null);
const pinnedHeaderOffset = ref(APP_HEADER_HEIGHT + 16);

/**
 * L'en-tête complet défile normalement ; un bandeau réduit s'épingle.
 *
 * Deux versions ont échoué avant celle-ci, pour la même raison de fond :
 * elles repliaient un en-tête **resté dans le flux**.
 *
 *   1. Replier retirait ~310 px au-dessus de la position de lecture, donc
 *      tout le contenu sautait vers le haut — le défilement « dépassait ».
 *      Sur une page courte, le document devenait même plus court que la
 *      fenêtre : le navigateur ramenait le défilement en haut, l'en-tête se
 *      dépliait, et la page vibrait sans pouvoir défiler.
 *   2. Interdire le repli sur les pages courtes supprimait la vibration
 *      mais aussi l'épinglage : sur l'étape Dossier, plus rien ne restait
 *      en haut.
 *
 * Le bandeau réduit est donc désormais une **surcouche de hauteur nulle** :
 * son conteneur est `sticky h-0`, l'enfant déborde par-dessus le contenu.
 * Afficher ou masquer ce bandeau ne retire pas un pixel au document — ni
 * saut, ni oscillation, ni garde-fou à régler — et l'en-tête complet
 * redevient un bloc ordinaire qui défile.
 *
 * La bascule s'appuie sur `IntersectionObserver` plutôt que sur un écouteur
 * `scroll` : le navigateur fait le travail hors du fil principal.
 *
 * En dessous de 1024 px, rien ne s'épingle : la surcouche mangerait une
 * part trop grande d'un écran étroit.
 */
const headerSentinel = ref(null);
const condensedBar = ref(null);
const headerCondensed = ref(false);
const stickyEnabled = ref(false);
const condensedBarHeight = ref(0);
const headerIsFull = computed(() => !headerCondensed.value);
/** La surcouche n'existe que repliée : sinon elle doublerait l'en-tête complet. */
const showCondensedBar = computed(() => stickyEnabled.value && !headerIsFull.value);

let sentinelObserver = null;
let stackObserver = null;
let barObserver = null;
let stickyQuery = null;
let stickyQueryHandler = null;

/**
 * Remonter à l'en-tête complet.
 *
 * Il n'est plus « déplié » sur place : il vit en haut du document et la
 * surcouche ne fait que le remplacer pendant le défilement. Le seul geste
 * qui a du sens est donc d'y retourner — la sentinelle redevient visible et
 * le bandeau s'efface de lui-même.
 */
const scrollToHeader = () => {
    if (typeof window === 'undefined') return;

    window.scrollTo({ top: 0, behavior: 'smooth' });
};

onMounted(() => {
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
        stickyQuery = window.matchMedia('(min-width: 1024px)');
        stickyQueryHandler = () => {
            stickyEnabled.value = stickyQuery.matches;

            if (!stickyQuery.matches) headerCondensed.value = false;
        };

        stickyQueryHandler();
        stickyQuery.addEventListener('change', stickyQueryHandler);
    }

    if (headerSentinel.value && typeof IntersectionObserver !== 'undefined') {
        sentinelObserver = new IntersectionObserver(
            ([entry]) => { headerCondensed.value = !entry.isIntersecting; },
            { rootMargin: `-${APP_HEADER_HEIGHT}px 0px 0px 0px`, threshold: 0 },
        );
        sentinelObserver.observe(headerSentinel.value);
    }

    if (typeof ResizeObserver === 'undefined') return;

    // La colonne de notes de l'Examen se cale sous ce qui est réellement
    // épinglé — c'est-à-dire le bandeau réduit, plus l'en-tête applicatif.
    const measure = () => {
        const bar = condensedBar.value ? Math.round(condensedBar.value.offsetHeight) : 0;

        if (bar > 0) condensedBarHeight.value = bar;

        pinnedHeaderOffset.value = APP_HEADER_HEIGHT + (condensedBarHeight.value || CONDENSED_BAR_FALLBACK) + 12;
    };

    measure();
    stackObserver = new ResizeObserver(measure);
    if (fullHeader.value) stackObserver.observe(fullHeader.value);
    barObserver = new ResizeObserver(measure);

    watch(condensedBar, (element) => {
        barObserver.disconnect();

        if (element) barObserver.observe(element);

        measure();
    }, { flush: 'post' });
});

onBeforeUnmount(() => {
    sentinelObserver?.disconnect();
    stackObserver?.disconnect();
    barObserver?.disconnect();
    if (stickyQueryHandler) stickyQuery?.removeEventListener?.('change', stickyQueryHandler);
    stackObserver = null;
});
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

const antecedentForm = useForm({ description: '', type: 'PERSONAL' });
const submitAntecedent = () => antecedentForm.post(`/patients/${patient.value.uuid}/antecedents`, {
    preserveScroll: true,
    onSuccess: () => antecedentForm.reset(),
});
const hasDischarge = computed(() => Boolean(props.medical_discharge));
/**
 * Ce pour quoi le patient est venu, tel que la Réception l'a enregistré.
 * Plusieurs prestations sont possibles sur un même passage ; on les joint
 * plutôt que d'en élire une arbitrairement.
 */
const requestedServiceLabel = computed(() => (episode.value.designations ?? [])
    .map((designation) => designation.description)
    .filter(Boolean)
    .join(' · ') || null);
const richTextToPlainText = (value) => {
    if (!value || typeof document === 'undefined') return value ?? '';
    const container = document.createElement('div');
    container.innerHTML = value;

    return (container.textContent ?? '').trim();
};
const interviewRecorded = computed(() => Boolean(richTextToPlainText(props.consultation?.reason)));
const clinicalExamRecorded = computed(() => Boolean(richTextToPlainText(props.consultation?.clinical_exam)));
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
/**
 * The wizard's shape lives here (labels, icons, order); its *state* comes
 * exclusively from the server. Deriving "done" in the browser from whatever
 * data happened to be present could not tell a step the doctor declared
 * unnecessary from one simply left blank, and showed Prescription as
 * finished whenever any prescription existed — with no diagnosis recorded.
 *
 * Diagnostic n'y figure plus : la conclusion se prend dans l'Examen clinique
 * (ADR-081). La clôture continue pourtant d'exiger un diagnostic — la
 * garantie a changé de support, pas disparu.
 */
const WIZARD_LAYOUT = [
    { key: 'dossier', label: 'Dossier du passage', navLabel: 'Dossier', hint: 'Contexte', icon: FileText },
    { key: 'consultation', label: 'Interrogatoire', hint: 'Histoire clinique', icon: MessageSquare },
    { key: 'examen', label: 'Examen clinique', navLabel: 'Examen', hint: 'Constatations', icon: Stethoscope },
    { key: 'paraclinique', label: 'Examens paracliniques', navLabel: 'Paraclinique', hint: 'Selon indication', icon: Activity },
    { key: 'ordonnance', label: 'Prescription', navLabel: 'Prescription', hint: 'Traitement', icon: Pill },
    { key: 'cloture', label: 'Décision & clôture', navLabel: 'Décision & clôture', hint: 'Conclure le passage', icon: CircleCheck },
];
const serverSteps = computed(() => props.consultation?.steps ?? {});
const stepState = (key) => serverSteps.value[key] ?? {
    status: 'NOT_STARTED', relevant: true, skippable: false, resolved: false, blocker: null, note: null,
};
const wizardSteps = computed(() => WIZARD_LAYOUT.map((step, index) => ({
    ...step,
    index,
    ...stepState(step.key),
})));
const currentStepIndex = computed(() => Math.max(0, wizardSteps.value.findIndex((step) => step.key === props.current_step)));
const currentStepState = computed(() => stepState(props.current_step));
const relevantWizardSteps = computed(() => wizardSteps.value.filter((step) => step.relevant !== false));
const currentStepLabel = computed(() => wizardSteps.value[currentStepIndex.value]?.navLabel
    ?? wizardSteps.value[currentStepIndex.value]?.label
    ?? null);
const currentStepPosition = computed(() => {
    const index = relevantWizardSteps.value.findIndex((step) => step.key === props.current_step);

    return index === -1 ? null : index + 1;
});

/** What the server says still stands between here and a closed consultation. */
const toast = useToastStore();

const closureBlockers = computed(() => props.consultation?.closure_blockers ?? []);

/**
 * Rouvrir une consultation clôturée (ADR-096).
 *
 * Le cas réel : un ECG demandé le matin, la consultation clôturée, le
 * résultat qui arrive l'après-midi. Sans cela, le compte rendu existe et la
 * conclusion du dossier l'ignore pour toujours.
 *
 * Le motif est obligatoire : revenir sur un dossier conclu est exceptionnel,
 * et l'audit doit dire pourquoi. Le serveur le revérifie.
 */
const reopenOpen = ref(false);
const reopenForm = useForm({ reason: '' });

const submitReopen = () => reopenForm.post(`/medicine/orientations/${props.orientation.uuid}/reopen`, {
    preserveScroll: true,
    onSuccess: () => {
        reopenOpen.value = false;
        reopenForm.reset();
    },
});

/**
 * « Le diagnostic peut-il être posé maintenant ? » (ADR-095).
 *
 * Trois états, jamais deux : `null` tant que le médecin n'a pas répondu —
 * aucun bouton pré-sélectionné, parce qu'une absence de réponse n'est pas
 * un report.
 *
 * Son endpoint est distinct de l'enregistrement de l'examen clinique :
 * répondre à une question ne doit jamais réécrire l'état général, la
 * conscience ou les appareils examinés.
 */
const diagnosisReady = computed(() => props.consultation?.clinical_examination?.diagnosis_ready ?? null);

/**
 * Les trois temps de la clôture, parcourus un par un.
 *
 * Les trois étaient empilés sur un même écran : le médecin devait faire
 * défiler pour savoir où il en était, et la vérification — ce qui manque
 * encore — se lisait tout en bas, après deux blocs déjà traités.
 *
 * Avancer n'est jamais bloqué. Un diagnostic peut légitimement être différé
 * (ADR-095) ou ne pas être dû du tout (ADR-094) : c'est la clôture
 * elle-même qui refuse, avec son motif, à l'étape 3. Un bouton « Suivant »
 * grisé n'expliquerait rien.
 *
 * `done` ne fait que refléter ce que le serveur dit déjà — jamais une règle
 * recalculée ici.
 */
const closureSubStep = ref(1);

const closureSections = computed(() => [
    {
        step: 1,
        label: 'Diagnostic',
        done: activeDiagnoses.value.length > 0 || !requiresFinalDiagnosis.value,
    },
    {
        step: 2,
        label: 'Conduite à tenir',
        done: activeOrientation.value?.status === 'SUBMITTED',
    },
    {
        step: 3,
        label: 'Vérification',
        done: closureBlockers.value.length === 0,
    },
]);
const diagnosisTimingForm = useForm({ ready: false });

/**
 * « Oui » cliqué alors qu'aucun diagnostic n'est enregistré n'est pas une
 * réponse : c'est l'intention d'en poser un. Le serveur refuse cette réponse
 * à juste titre (ADR-095 — une intention n'est pas un diagnostic), mais son
 * message renvoie à « ci-dessous », or « Pas maintenant » garde justement la
 * saisie repliée : le médecin lisait une consigne désignant un champ
 * invisible. La saisie s'ouvre donc, au lieu d'envoyer une réponse dont on
 * sait qu'elle sera rejetée.
 */
const diagnosisEntryOpen = ref(false);

const decideDiagnosisTiming = (ready) => {
    if (ready && ! activeDiagnoses.value.length) {
        diagnosisTimingForm.clearErrors();
        diagnosisEntryOpen.value = true;

        return;
    }

    diagnosisTimingForm.ready = ready;
    diagnosisTimingForm.post(`/medicine/orientations/${props.orientation.uuid}/diagnostic-timing`, {
        preserveScroll: true,
        onSuccess: () => { diagnosisEntryOpen.value = ready; },
    });
};

/**
 * Un passage venu seulement pour un ECG, une échographie ou une analyse ne
 * doit pas de diagnostic final (ADR-094). Le serveur tranche ; l'écran ne
 * fait que refléter sa réponse, et retombe sur « exigé » si elle manque —
 * c'est le comportement de toute vraie consultation.
 */
const requiresFinalDiagnosis = computed(() => props.consultation?.requires_final_diagnosis !== false);
const consultationIsClosed = computed(() => props.consultation?.status === 'COMPLETED');
const completeConsultationForm = useForm({});
const showCriticalVitalConfirm = ref(false);

const submitCompleteConsultation = () => {
    showCriticalVitalConfirm.value = false;
    showAwaitingResultConfirm.value = false;
    completeConsultationForm.post(
        `/medicine/orientations/${props.orientation.uuid}/complete`,
        { preserveScroll: true },
    );
};

/**
 * Clôturer sur une constante critique demande une confirmation.
 *
 * Ce n'est volontairement **pas** une règle serveur : le CDC n'interdit
 * nulle part de terminer une consultation sur une SpO₂ basse, et il existe
 * de bonnes raisons de le faire — le patient part en chirurgie, la valeur a
 * déjà été prise en compte, ou la mesure est simplement fausse. Inventer un
 * blocage ici fabriquerait une règle métier que personne n'a validée.
 *
 * Ce que le système peut légitimement faire, c'est refuser de laisser
 * passer la chose en silence : il nomme la constante et demande une
 * confirmation explicite. Les vrais obstacles de clôture (`closureBlockers`)
 * restent, eux, vérifiés côté serveur.
 */
const showAwaitingResultConfirm = ref(false);

const completeConsultation = () => {
    if (hasCriticalVital.value) {
        showCriticalVitalConfirm.value = true;

        return;
    }

    // Clôturer alors qu'un résultat est attendu reste possible — le médecin
    // peut avoir conclu sans lui — mais jamais en silence.
    if (awaitingResults.value.length) {
        showAwaitingResultConfirm.value = true;

        return;
    }

    submitCompleteConsultation();
};

/**
 * A patient who came only for an ECG, an ultrasound or a lab test has no
 * interrogation and no clinical examination to record: the doctor reads the
 * requested exam and concludes. The rule is decided once server-side
 * (`ConsultationWorkflow::isRelevant()`); the card stays reachable — it is a
 * shortcut, never a lock, and an encounter that turns into a real
 * consultation can still be documented.
 */
const cardIsSkipped = (key) => stepState(key).relevant === false
    && props.current_step !== key;

/**
 * The patient's civil identity. A file that only carries a phone number is
 * not a file: marital status, children, profession and identity document
 * were all recorded at Réception and none of them reached the doctor.
 */
const civilIdentityFields = computed(() => {
    const profile = props.patient_profile ?? {};
    const birth = profile.birth_date
        ? `${formatDate(profile.birth_date)}${profile.birth_place ? ` · ${profile.birth_place}` : ''}`
        : (profile.declared_age !== null && profile.declared_age !== undefined
            ? `${profile.declared_age} ans (âge déclaré)`
            : null);
    const family = [
        profile.marital_status,
        (profile.children_count ?? null) !== null ? `${profile.children_count} enfant(s)` : null,
    ].filter(Boolean).join(' · ');

    return [
        { label: 'Naissance', value: birth },
        { label: 'Situation familiale', value: family },
        { label: 'Profession', value: profile.profession },
        { label: 'Pièce d’identité', value: profile.identity_document, mono: true },
    ];
});

const contactFields = computed(() => {
    const profile = props.patient_profile ?? {};

    return [
        { label: 'Téléphone', value: profile.phone, icon: 'call', href: profile.phone ? `tel:${profile.phone}` : null },
        { label: 'Email', value: profile.email, icon: 'mail', href: profile.email ? `mailto:${profile.email}` : null },
        { label: 'Adresse', value: profile.address, icon: 'map-pin', href: null },
    ];
});

/** How the patient reached this consultation — the visit, not the person. */
const visitFields = computed(() => [
    { label: 'Adressé par', value: props.orientation.source_label, icon: 'arrow-right', href: null },
    {
        label: 'Pris en charge',
        value: [formatDateTime(props.orientation.accepted_at), props.orientation.accepted_by].filter(Boolean).join(' · '),
        icon: 'clock',
        href: null,
    },
]);

/** The card the doctor is working in — exactly one at a time. */
const cardIsOpen = (key) => props.current_step === key;

// Navigation honours the skip rule: an ECG-only patient goes from the file
// straight to the requested exam, without a hollow interrogation in between.
/**
 * The last step that actually concerns this patient.
 *
 * Skips both what is irrelevant (an ECG-only encounter has no interrogation)
 * and what the doctor declared unnecessary — sending them back to a step
 * labelled « Non nécessaire » is a dead end they have to escape from.
 */
const stepIsBehindUs = (key) => cardIsSkipped(key) || stepState(key).status === 'SKIPPED';
const previousStep = computed(() => {
    for (let index = currentStepIndex.value - 1; index >= 0; index -= 1) {
        if (!stepIsBehindUs(wizardSteps.value[index].key)) return wizardSteps.value[index];
    }

    return null;
});
const nextStep = computed(() => {
    for (let index = currentStepIndex.value + 1; index < wizardSteps.value.length; index += 1) {
        if (!stepIsBehindUs(wizardSteps.value[index].key)) return wizardSteps.value[index];
    }

    return null;
});
const stepUrl = (step) => `/medicine/orientations/${props.orientation.uuid}/${step}`;
const selectClass = 'block h-9 w-full appearance-none rounded border border-border bg-card px-3 pe-9 text-sm text-foreground outline-none focus-visible:border-primary/60 focus-visible:ring-2 focus-visible:ring-ring/25 disabled:bg-muted/35';
const textareaClass = 'block w-full resize-y rounded border border-border bg-card px-3 py-2 text-sm leading-5 text-foreground outline-none focus-visible:border-primary/60 focus-visible:ring-2 focus-visible:ring-ring/25 disabled:bg-muted/35';

const toLocalDateTimeInput = (value = new Date()) => {
    const date = value instanceof Date ? value : new Date(value);
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
};

/**
 * The interview is semi-structured: a short exploitable chief complaint, a
 * few useful structured fields, and the narrative — instead of everything
 * melted into one block of prose.
 *
 * It carries nothing that belongs elsewhere: no vital sign (Soins), no
 * physical finding (Examen clinique), no diagnosis, no prescription.
 */
const interviewForm = useForm({
    chief_complaint: props.consultation?.chief_complaint ?? '',
    symptom_onset: props.consultation?.symptom_onset ?? '',
    evolution: props.consultation?.evolution ?? null,
    reason: props.consultation?.reason ?? '',
    additional_notes: props.consultation?.additional_notes ?? '',
    known_treatment_change: props.consultation?.known_treatment_change ?? null,
    known_treatment_change_notes: props.consultation?.known_treatment_change_notes ?? '',
    // "TRAITEMENTS ACTUELS" of the paper DOSSIER MÉDICAL: what the patient
    // already takes. Declarative, line by line — never a prescription.
    current_treatments: (props.consultation?.current_treatments ?? []).map((treatment) => ({
        medication_name: treatment.medication_name ?? '',
        dosage: treatment.dosage ?? '',
        frequency: treatment.frequency ?? '',
        duration: treatment.duration ?? '',
        notes: treatment.notes ?? '',
    })),
    // Snapshots of what the patient revealed today. They stay on the
    // consultation whatever happens to the permanent record afterwards.
    reported_allergies: (props.consultation?.reported_allergies ?? []).map((item) => ({
        substance: item.substance ?? '',
        reaction: item.reaction ?? '',
        promote_to_patient_record: Boolean(item.promote_to_patient_record),
    })),
    reported_antecedents: (props.consultation?.reported_antecedents ?? []).map((item) => ({
        description: item.description ?? '',
        type: item.type ?? 'PERSONAL',
        promote_to_patient_record: Boolean(item.promote_to_patient_record),
    })),
    reported_habitual_treatments: (props.consultation?.reported_habitual_treatments ?? []).map((item) => ({
        medication_name: item.medication_name ?? '',
        dosage: item.dosage ?? '',
        frequency: item.frequency ?? '',
        duration: item.duration ?? '',
        notes: item.notes ?? '',
        promote_to_patient_record: Boolean(item.promote_to_patient_record),
    })),
});

const EVOLUTION_OPTIONS = [
    { value: 'IMPROVING', label: 'Amélioration', tone: 'positive' },
    { value: 'STABLE', label: 'Stable' },
    { value: 'WORSENING', label: 'Aggravation', tone: 'warning' },
    { value: 'FLUCTUATING', label: 'Fluctuante' },
];
const TREATMENT_CHANGE_OPTIONS = [
    { value: 'NO', label: 'Non' },
    { value: 'YES', label: 'Oui', tone: 'warning' },
];

/** The patient's known habitual treatments, read-only from the record. */
const habitualTreatments = computed(() => props.habitual_treatments ?? []);

const setKnownTreatmentChange = (value) => {
    interviewForm.known_treatment_change = value;

    // A description only means something for "Oui"; one typed before the
    // doctor settled on "Non" would contradict the answer stored beside it.
    if (value !== 'YES') {
        interviewForm.known_treatment_change_notes = '';
    }
};

const updateCurrentTreatment = ({ index, field, value }) => {
    interviewForm.current_treatments = interviewForm.current_treatments.map((treatment, position) => (
        position === index ? { ...treatment, [field]: value } : treatment
    ));
};

const REPORTED_BLANKS = {
    allergies: () => ({ substance: '', reaction: '', promote_to_patient_record: false }),
    antecedents: () => ({ description: '', type: 'PERSONAL', promote_to_patient_record: false }),
    habitualTreatments: () => ({
        medication_name: '', dosage: '', frequency: '', duration: '', notes: '', promote_to_patient_record: false,
    }),
};
const REPORTED_KEYS = {
    allergies: 'reported_allergies',
    antecedents: 'reported_antecedents',
    habitualTreatments: 'reported_habitual_treatments',
};

const addReportedInformation = (group) => {
    const key = REPORTED_KEYS[group];
    interviewForm[key] = [...interviewForm[key], REPORTED_BLANKS[group]()];
};
const removeReportedInformation = ({ group, index }) => {
    const key = REPORTED_KEYS[group];
    interviewForm[key] = interviewForm[key].filter((_, position) => position !== index);
};
const updateReportedInformation = ({ group, index, field, value }) => {
    const key = REPORTED_KEYS[group];
    interviewForm[key] = interviewForm[key].map((item, position) => (
        position === index ? { ...item, [field]: value } : item
    ));
};
/**
 * The clinical examination is hybrid: structured general condition and
 * per-system statuses, plus optional free notes.
 *
 * It holds no vital sign. Blood pressure, heart rate, SpO2, temperature,
 * weight, height and BMI were measured once by Soins and are read from
 * "Contexte clinique" — re-entering them here would create a second version
 * of a measurement nobody took twice.
 */
const clinicalExamForm = useForm({
    clinical_exam: props.consultation?.clinical_exam ?? '',
    general_condition: props.consultation?.clinical_examination?.general_condition ?? null,
    consciousness_status: props.consultation?.clinical_examination?.consciousness_status ?? null,
    consciousness_details: props.consultation?.clinical_examination?.consciousness_details ?? '',
    general_observation: props.consultation?.clinical_examination?.general_observation ?? '',
    // The server always sends all nine systems, unexamined ones included, so
    // the grid never has to invent a status for a missing row.
    systems: (props.consultation?.clinical_examination?.systems ?? []).map((system) => ({
        system_code: system.system_code,
        label: system.label,
        hint: system.hint,
        status: system.status,
        findings: system.findings ?? '',
    })),
});

const updateExamSystem = ({ system_code: code, ...patch }) => {
    clinicalExamForm.systems = clinicalExamForm.systems.map((system) => (
        system.system_code === code ? { ...system, ...patch } : system
    ));
};

/**
 * Answering "Non" to "Examen par appareil réalisé ?" puts every system back
 * to NOT_EXAMINED — which is what the answer means, and what the record will
 * show. It never means NORMAL. `restore` brings back what was typed before,
 * so an accidental click does not cost the doctor their findings.
 */
/**
 * The requests already sent for this consultation, shown as a reminder in the
 * examination. Labels and statuses only — results are read at the Paraclinique
 * step, which is the module that owns them.
 */
const paraclinicalRequests = computed(() => [
    ...(props.consultation?.lab_requests ?? []).map((request) => ({
        uuid: request.uuid,
        label: 'Analyses de laboratoire',
        summary: (request.items ?? []).map((item) => item.name).join(', ') || 'Aucune analyse',
        status: request.status,
        status_label: request.status_label ?? request.status,
    })),
    ...(props.consultation?.imaging_requests ?? []).map((request) => ({
        uuid: request.uuid,
        label: 'Imagerie (ECG / échographie)',
        summary: (request.items ?? []).map((item) => item.name).join(', ') || 'Aucun examen',
        status: request.status,
        status_label: request.status_label ?? request.status,
    })),
]);

/**
 * The decision now lives on the Paraclinique step: it is the step it governs,
 * and asking it there keeps the examination screen to what the doctor
 * observes. Its own endpoint records the answer and resolves the step in one
 * transaction.
 */
const complementaryExamsForm = useForm({
    required: props.consultation?.clinical_examination?.complementary_exams_required ?? null,
    withdraw_confirmed: false,
});

/**
 * Répondre « Non » retire ce qui a déjà été demandé : on le confirme, jamais
 * en silence.
 *
 * La confirmation passait par `window.confirm()` — une fenêtre du navigateur
 * qui ne peut nommer ni les examens concernés ni ce qui leur arrive, et qui
 * ignore le thème de l'application. Elle nomme désormais chaque demande.
 *
 * Ce n'est pas la protection : le serveur exige `withdraw_confirmed` et
 * refuse de lui-même toute demande portant déjà un résultat.
 */
const withdrawConfirmOpen = ref(false);

const submitComplementaryExams = (value) => {
    complementaryExamsForm.required = value;
    complementaryExamsForm.post(
        `/medicine/orientations/${props.orientation.uuid}/complementary-exams`,
        { preserveScroll: true, onSuccess: () => { withdrawConfirmOpen.value = false; } },
    );
};

const decideComplementaryExams = (value) => {
    if (!props.capabilities.can_update_consultation || value === complementaryExamsForm.required) return;

    if (value === false && paraclinicalRequests.value.length > 0) {
        withdrawConfirmOpen.value = true;

        return;
    }

    complementaryExamsForm.withdraw_confirmed = false;
    submitComplementaryExams(value);
};

const confirmWithdrawAndDecide = () => {
    complementaryExamsForm.withdraw_confirmed = true;
    submitComplementaryExams(false);
};

const resetExamSystems = ({ restore }) => {
    clinicalExamForm.systems = restore ?? clinicalExamForm.systems.map((system) => ({
        ...system,
        status: 'NOT_EXAMINED',
        findings: '',
    }));
};

/**
 * Server errors arrive keyed by list index (`systems.3.findings`); the
 * accordion addresses its rows by system code. Translated here so a
 * reordering of the payload can never attach an error to the wrong organ.
 */
const systemFindingErrors = computed(() => {
    const mapped = {};

    clinicalExamForm.systems.forEach((system, index) => {
        const message = clinicalExamForm.errors[`systems.${index}.findings`];
        if (message) mapped[system.system_code] = message;
    });

    return mapped;
});

/**
 * What makes the step validatable. Deliberately not "the notes are filled":
 * a doctor who recorded the general condition and went through the systems
 * has documented the examination without writing prose. An entirely blank
 * record still counts as nothing.
 */
/**
 * L'ordre recommandé de l'examen — celui que suit un médecin qui déroule sa
 * consultation. Chacun peut le réarranger pour son poste ; l'identifiant de
 * chaque section, lui, ne bouge jamais : c'est la clé qui permet à Vue de
 * déplacer les blocs sans les reconstruire, et à une préférence enregistrée
 * de rester lisible quand l'écran évolue.
 */
const EXAM_SECTIONS = [
    { id: 'GENERAL_STATE', label: 'État général' },
    { id: 'SYSTEM_EXAM', label: 'Examen par appareil' },
    { id: 'DIAGNOSIS', label: 'Diagnostic' },
];

const clinicalExamDocumented = computed(() => Boolean(clinicalExamForm.general_condition)
    || Boolean(clinicalExamForm.consciousness_status)
    || clinicalExamForm.systems.some((system) => system.status !== 'NOT_EXAMINED')
    || richTextToPlainText(clinicalExamForm.clinical_exam).length > 0);

/** An anomaly without its findings cannot be validated (mirrors the server). */
const clinicalExamHasBlankAnomaly = computed(() => clinicalExamForm.systems
    .some((system) => system.status === 'ABNORMAL' && system.findings.trim() === ''));
/** The sidebar list is the patient's own history; family history is above. */
const personalAntecedents = computed(
    () => (props.antecedents ?? []).filter((antecedent) => antecedent.type !== 'FAMILIAL'),
);

const addCurrentTreatment = () => {
    interviewForm.current_treatments = [
        ...interviewForm.current_treatments,
        { medication_name: '', dosage: '', notes: '' },
    ];
};
const removeCurrentTreatment = (index) => {
    interviewForm.current_treatments = interviewForm.current_treatments
        .filter((_, position) => position !== index);
};

/**
 * Most patients declare none: the block stayed open and empty by default,
 * taking space for a "no" that had to be read one line at a time. A
 * yes/no gate collapses it until there is something to actually declare —
 * already-declared treatments (restored from a draft or a previous save)
 * still open it immediately, they are never hidden by this toggle.
 */
const hasCurrentTreatments = ref(interviewForm.current_treatments.length > 0);
const setHasCurrentTreatments = (value) => {
    hasCurrentTreatments.value = value;

    if (value && interviewForm.current_treatments.length === 0) {
        addCurrentTreatment();
    } else if (!value) {
        interviewForm.current_treatments = [];
    }
};
const treatmentError = (index, field) => interviewForm.errors[`current_treatments.${index}.${field}`];
const interviewFilled = computed(() => interviewForm.chief_complaint.trim().length > 0
    && richTextToPlainText(interviewForm.reason).length > 0);

/**
 * `complete` carries the doctor's intent, and only that: "Enregistrer" keeps
 * the work without claiming the step is finished, "Enregistrer et continuer"
 * validates it and moves on. The server decides both the step status and the
 * redirection from this flag — never from the fact that content was written.
 */
const saveInterview = (complete = true) => interviewForm
    .transform((data) => ({ ...data, complete }))
    .put(
        `/medicine/orientations/${props.orientation.uuid}/interrogatoire`,
        {
            preserveScroll: true,
            onSuccess: () => {
                draft.markSaved();
            },
        },
    );

const saveClinicalExam = (complete = true) => clinicalExamForm
    .transform((data) => ({
        ...data,
        complete,
        // `label` and `hint` are display data the server already owns; only
        // the doctor's three fields travel. NOT_EXAMINED rows are sent so a
        // system the doctor un-examines is really cleared server-side.
        systems: data.systems.map((system) => ({
            system_code: system.system_code,
            status: system.status,
            findings: system.findings,
        })),
    }))
    .put(
        `/medicine/orientations/${props.orientation.uuid}/examen-clinique`,
        {
            preserveScroll: true,
            onSuccess: () => {
                draft.markSaved();
            },
        },
    );

/** "35.00 °C" claims a precision nobody measured. */
const trimDecimals = (value) => {
    if (value === null || value === undefined || value === '') return null;

    const numeric = Number(value);

    return Number.isFinite(numeric) ? String(numeric) : String(value);
};

/**
 * Les constantes hors bornes, pour la confirmation de clôture.
 *
 * L'affichage, lui, appartient à `VitalSignsStrip`, qui porte la valeur et
 * son interprétation dans la même pastille. Ce calcul ne sert plus qu'à
 * savoir s'il faut demander confirmation avant de clôturer sur une valeur
 * critique.
 *
 * Les messages et la gravité viennent du serveur (ADR-039 à ADR-041) :
 * aucun seuil n'est recalculé ici, et ce n'est jamais un diagnostic.
 */
const vitalAlerts = computed(() => {
    const record = props.care_record ?? {};

    return [
        ['Tension artérielle', 'TA', careRecordBloodPressure.value, 'mmHg', record.blood_pressure_assessment],
        ['Fréquence cardiaque', 'FC', record.heart_rate, 'bpm', record.heart_rate_assessment],
        ['Saturation en oxygène', 'SpO₂', record.spo2, '%', record.spo2_assessment],
        ['Température', 'T°', trimDecimals(record.temperature_celsius), '°C', record.temperature_assessment],
    ]
        .filter(([, , value, , assessment]) => value !== null && value !== undefined && value !== ''
            && assessment && assessment.tone !== 'success')
        .map(([label, short, value, unit, assessment]) => ({
            label,
            // Le résumé d'une ligne utilise l'abréviation ; le détail
            // déplié garde le nom complet, plus sûr à la relecture.
            short,
            reading: `${value} ${unit}`,
            severity: assessment.tone === 'danger' ? 'danger' : 'warning',
            title: assessment.label,
            message: assessment.message,
        }))
        // Le plus grave d'abord : c'est ce qui doit être lu en premier.
        .sort((a, b) => (a.severity === b.severity ? 0 : a.severity === 'danger' ? -1 : 1));
});

const hasCriticalVital = computed(() => vitalAlerts.value.some((alert) => alert.severity === 'danger'));

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
/**
 * Acts already waiting at Soins for this consultation: asking again would
 * queue the same act twice. The server refuses it too (CreateCareOrderAction);
 * the screen must never let such a line reach « Actes demandés ».
 *
 * Keyed by the catalog UUID — the key the server checks — with the code as a
 * fallback for payloads that predate it.
 */
const pendingCareOrderItems = computed(() => (props.consultation?.care_orders ?? [])
    .filter((order) => order.status === 'PENDING')
    .flatMap((order) => order.items)
    .filter((item) => !item.cancelled_at && !item.not_performed_at && Number(item.remaining_quantity) > 0));
const isCareOrderItemPending = (item) => pendingCareOrderItems.value.some((pending) => (
    (pending.catalog_item_uuid && pending.catalog_item_uuid === (item.catalog_item_uuid ?? item.uuid))
    || (pending.code && pending.code === item.code)
));
/**
 * A line can enter the selection without passing through the search list:
 * the server-side draft (ADR-073) restores whatever was saved, including an
 * act transmitted since. Such a line is withdrawn and the doctor is told
 * which one and why — never kept, never dropped silently.
 */
const careOrderDuplicateNotice = ref('');
watch(
    () => [careOrderForm.items.length, pendingCareOrderItems.value.length, careOrderForm.items.map((line) => line.catalog_item_uuid).join()],
    () => {
        const duplicates = careOrderForm.items.filter((line) => isCareOrderItemPending(line));
        if (!duplicates.length) return;

        careOrderForm.items = careOrderForm.items.filter((line) => !duplicates.includes(line));
        careOrderDuplicateNotice.value = `${duplicates.map((line) => line.name).join(', ')} retiré${duplicates.length > 1 ? 's' : ''} de la demande : déjà en attente aux Soins.`;
    },
    { immediate: true },
);

const isCareOrderItemSelected = (item) => careOrderForm.items.some((line) => line.catalog_item_uuid === item.uuid);
const addCareOrderItem = (item) => {
    if (isCareOrderItemSelected(item) || isCareOrderItemPending(item)) return;

    careOrderForm.items.push({ catalog_item_uuid: item.uuid, code: item.code, name: item.name, quantity: 1 });
    careOrderDuplicateNotice.value = '';
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
/**
 * Transmettre aux Soins est un acte signé (ADR-106) : l'orientation vers
 * Soins est créée et les actes partent à l'équipe. Le bouton comme la
 * touche Entrée ouvrent la confirmation ; seule elle envoie.
 */
const showCareOrderConfirmation = ref(false);
const openCareOrderConfirmation = () => {
    if (!careOrderForm.items.length || careOrderForm.processing) return;
    if (careOrderForm.items.some((line) => isCareOrderItemPending(line))) return;

    showCareOrderConfirmation.value = true;
};
const closeCareOrderConfirmation = () => {
    if (careOrderForm.processing) return;
    showCareOrderConfirmation.value = false;
};
const confirmCareOrder = () => {
    showCareOrderConfirmation.value = false;
    submitCareOrder();
};
/** Demandes encore à l'œuvre aux Soins ; le reste se replie sous « Historique ». */
const activeCareOrders = computed(() => (props.consultation?.care_orders ?? [])
    .filter((order) => !['COMPLETED', 'CANCELLED'].includes(order.status)));
const pastCareOrders = computed(() => (props.consultation?.care_orders ?? [])
    .filter((order) => ['COMPLETED', 'CANCELLED'].includes(order.status)));
const careOrderHistoryOpen = ref(false);
const careOrderStatusLabel = (status) => ({ PENDING: 'En attente', IN_PROGRESS: 'En cours', COMPLETED: 'Terminé', CANCELLED: 'Retirée' }[status] ?? status);

/**
 * Retirer un acte demandé, tant que Soins n'a pas pris le patient
 * (CancelCareOrderItemAction). Aucun motif à saisir : le serveur enregistre
 * l'auteur, la date et un motif fixe. Rien n'est effacé : la ligne reste,
 * barrée. Le serveur revérifie tout.
 */
const withdrawingCareOrderItem = ref(null);
const careOrderWithdrawalForm = useForm({});
const openCareOrderWithdrawal = (item) => {
    careOrderWithdrawalForm.clearErrors();
    withdrawingCareOrderItem.value = item;
};
const closeCareOrderWithdrawal = () => {
    if (careOrderWithdrawalForm.processing) return;
    withdrawingCareOrderItem.value = null;
};
const confirmCareOrderWithdrawal = () => {
    const item = withdrawingCareOrderItem.value;
    if (!item) return;

    careOrderWithdrawalForm.post(
        `/medicine/orientations/${props.orientation.uuid}/care-order-items/${item.uuid}/cancel`,
        { preserveScroll: true, onSuccess: () => { withdrawingCareOrderItem.value = null; } },
    );
};

const labCatalog = computed(() => props.options?.lab_catalog ?? []);
const labSearch = ref('');
const filteredLabCatalog = computed(() => {
    const query = labSearch.value.trim().toLocaleLowerCase('fr');

    return labCatalog.value.filter((item) => `${item.name} ${item.code ?? ''}`.toLocaleLowerCase('fr').includes(query));
});
const labRequestForm = useForm({ items: [], notes: '', continue_to_diagnosis: true });
const isLabItemSelected = (item) => labRequestForm.items.some((line) => line.catalog_item_uuid === item.uuid);
const isAlreadyRequestedLab = (item) => alreadyRequestedLabUuids.value.has(item.uuid);
const addLabItem = (item) => {
    // Ni deux fois dans la sélection, ni un examen déjà parti au service.
    if (isLabItemSelected(item) || isAlreadyRequestedLab(item)) return;

    labRequestForm.items.push({ catalog_item_uuid: item.uuid, code: item.code, name: item.name });
    labSearch.value = '';
};
const removeLabItem = (line) => {
    const index = labRequestForm.items.indexOf(line);
    if (index >= 0) labRequestForm.items.splice(index, 1);
};
/**
 * Transmettre une demande d'examen est un acte signé, pas un enregistrement :
 * l'ordre part au service concerné et chaque examen rejoint le compte du
 * patient (ADR-105), sans retour possible une fois un résultat saisi.
 *
 * La fenêtre **nomme les examens** plutôt que d'en donner le nombre :
 * confirmer « 2 examens » sans les voir ne serait pas une signature
 * consciente. Le propriétaire a demandé le 2026-09-17 de ne pas y énoncer
 * les conséquences elles-mêmes — elles restent vraies, elles ne sont plus
 * répétées à chaque envoi.
 */
const pendingRequestKind = ref(null);
const openRequestConfirmation = (kind) => { pendingRequestKind.value = kind; };
const closeRequestConfirmation = () => {
    if (labRequestForm.processing || imagingRequestForm.processing) return;
    pendingRequestKind.value = null;
};
const confirmedRequestItems = computed(() => (pendingRequestKind.value === 'imaging'
    ? imagingRequestForm.items
    : labRequestForm.items));
const confirmRequest = () => {
    const kind = pendingRequestKind.value;
    pendingRequestKind.value = null;

    if (kind === 'imaging') submitImagingRequest();
    else if (kind === 'lab') submitLabRequest();
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
    IN_PROGRESS: 'bg-primary/10 text-primary',
    COMPLETED: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
    CANCELLED: 'bg-muted text-muted-foreground line-through',
}[status] ?? 'bg-muted text-muted-foreground'];

/**
 * Retirer une demande précise.
 *
 * Seule l'annulation en bloc existait (répondre « Non » en tête d'étape).
 * Le serveur refuse le retrait d'une demande qui porte déjà un résultat :
 * le service a fait le travail, et l'effacer de la file nierait un acte
 * réalisé.
 */
const withdrawalForm = useForm({ kind: 'lab', uuid: null, reason: '' });
const requestToWithdraw = ref(null);

const askWithdrawal = (kind, request) => {
    requestToWithdraw.value = { kind, request };
    withdrawalForm.clearErrors();
    withdrawalForm.kind = kind;
    withdrawalForm.uuid = request.uuid;
    withdrawalForm.reason = '';
};
const closeWithdrawal = () => { requestToWithdraw.value = null; };
const confirmWithdrawal = () => withdrawalForm.post(
    `/medicine/orientations/${props.orientation.uuid}/paraclinical-requests/cancel`,
    { preserveScroll: true, onSuccess: closeWithdrawal },
);

/**
 * ADR-106 — l'onglet ouvert. `lab` d'un côté, une famille d'imagerie de
 * l'autre : `CARDIOLOGY`, `ULTRASOUND`, ou `UNCLASSIFIED` pour les examens
 * dont personne n'a encore réglé la famille au catalogue.
 */
const paracliniqueTab = ref('lab');
const isImagingTab = computed(() => paracliniqueTab.value !== 'lab');

/** Les examens du catalogue d'imagerie, rangés par famille. */
const imagingByModality = (modality) => (props.options?.imaging_catalog ?? [])
    .filter((item) => (item.modality ?? 'UNCLASSIFIED') === modality);

const paraclinicalTabs = computed(() => {
    const requested = props.consultation.imaging_requests ?? [];
    // Une demande déjà transmise garde la famille de l'examen qu'elle porte :
    // le compteur d'un onglet ne montre donc que ce qui le concerne.
    const requestedIn = (modality) => requested.filter((request) => (request.items ?? [])
        .some((item) => (imagingModalityOf(item.catalog_item_uuid) ?? 'UNCLASSIFIED') === modality)).length;

    const tabs = [
        {
            value: 'lab',
            label: 'Laboratoire',
            icon: Activity,
            count: (props.consultation.lab_requests ?? []).length,
        },
        {
            value: 'CARDIOLOGY',
            label: 'ECG',
            icon: HeartPulse,
            count: requestedIn('CARDIOLOGY'),
        },
        {
            value: 'ULTRASOUND',
            label: 'Échographie',
            icon: ScanLine,
            count: requestedIn('ULTRASOUND'),
        },
    ];

    // N'apparaît que s'il existe réellement un examen non classé, au
    // catalogue ou déjà demandé.
    if (imagingByModality('UNCLASSIFIED').length || requestedIn('UNCLASSIFIED')) {
        tabs.push({
            value: 'UNCLASSIFIED',
            label: 'Non classés',
            icon: CircleAlert,
            count: requestedIn('UNCLASSIFIED'),
        });
    }

    return tabs;
});

const imagingModalityOf = (catalogItemUuid) => (props.options?.imaging_catalog ?? [])
    .find((item) => item.uuid === catalogItemUuid)?.modality ?? null;
const imagingCatalog = computed(() => props.options?.imaging_catalog ?? []);
const imagingSearch = ref('');
const filteredImagingCatalog = computed(() => {
    const query = imagingSearch.value.trim().toLocaleLowerCase('fr');

    return imagingCatalog.value
        // ADR-106 — l'onglet ouvert décide de la famille proposée : chercher
        // « écho » dans l'onglet ECG ne doit rien remonter, sinon la
        // séparation n'en serait plus une.
        .filter((item) => (item.modality ?? 'UNCLASSIFIED') === paracliniqueTab.value)
        .filter((item) => `${item.name} ${item.code ?? ''}`.toLocaleLowerCase('fr').includes(query));
});
/**
 * Les examens déjà demandés et toujours en attente d'un résultat.
 *
 * Le serveur les refuse (`ParaclinicalRequestGuard`), mais le sélecteur les
 * proposait quand même : on pouvait donc préparer une demande impossible à
 * envoyer, et ne l'apprendre qu'au clic. D'où l'impression de voir deux
 * fois le même examen, côte à côte, sans comprendre pourquoi.
 *
 * Les noms sont comparés par UUID de prestation, jamais par libellé.
 */
const activeRequestedCatalogUuids = (requests) => new Set(
    (requests ?? [])
        .filter((request) => request.status === 'REQUESTED' || request.status === 'IN_PROGRESS')
        .flatMap((request) => (request.items ?? [])
            .filter((item) => !item.resulted_at)
            .map((item) => item.catalog_item_uuid)),
);
const alreadyRequestedLabUuids = computed(() => activeRequestedCatalogUuids(props.consultation?.lab_requests));
const alreadyRequestedImagingUuids = computed(() => activeRequestedCatalogUuids(props.consultation?.imaging_requests));

/**
 * ADR-109 — le besoin de l'arrivée est déjà connu.
 *
 * Mme R. est venue pour une échographie obstétricale : la Réception l'a
 * planifiée et facturée. Faire chercher le même examen dans le catalogue,
 * c'est demander de ressaisir ce que le dossier porte déjà — exactement ce
 * que l'ADR-084 refuse pour les formulaires d'orientation.
 *
 * Le serveur décide ce qui reste à transmettre : une ligne disparaît dès
 * qu'une demande active la porte. L'écran ne déduit rien d'un libellé.
 */
const plannedParaclinical = computed(() => props.consultation?.planned_paraclinical ?? []);
const plannedLab = computed(() => plannedParaclinical.value.filter((line) => line.module === 'LABORATORY'));
const plannedImaging = computed(() => plannedParaclinical.value.filter((line) => line.module === 'IMAGING'));

const imagingRequestForm = useForm({ items: [], notes: '', continue_to_diagnosis: true });
const isImagingItemSelected = (item) => imagingRequestForm.items.some((line) => line.catalog_item_uuid === item.uuid);
const isAlreadyRequestedImaging = (item) => alreadyRequestedImagingUuids.value.has(item.uuid);
const addImagingItem = (item) => {
    // Ni deux fois dans la sélection, ni un examen déjà parti au service.
    if (isImagingItemSelected(item) || isAlreadyRequestedImaging(item)) return;

    imagingRequestForm.items.push({ catalog_item_uuid: item.uuid, code: item.code, name: item.name });
    imagingSearch.value = '';
};
/**
 * Le besoin connu entre dans la demande en préparation, une fois, au
 * montage. Il reste retirable : un médecin peut décider que l'examen
 * demandé à l'accueil n'est finalement pas celui qu'il faut.
 *
 * `watch` avec `immediate` plutôt qu'`onMounted` : la liste arrive du
 * serveur, et une visite Inertia qui la met à jour doit être reprise.
 * `once` par item : on ne réinjecte jamais une ligne que le médecin a
 * retirée à la main.
 */
const preselected = new Set();

watch([plannedLab, plannedImaging], () => {
    for (const line of plannedLab.value) {
        if (preselected.has(line.catalog_item_uuid)) continue;
        preselected.add(line.catalog_item_uuid);
        addLabItem({ uuid: line.catalog_item_uuid, code: line.code, name: line.name });
    }

    for (const line of plannedImaging.value) {
        if (preselected.has(line.catalog_item_uuid)) continue;
        preselected.add(line.catalog_item_uuid);
        addImagingItem({ uuid: line.catalog_item_uuid, code: line.code, name: line.name });
    }
}, { immediate: true });

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
/**
 * Les examens demandés dont le résultat n'est pas revenu.
 *
 * Dérivé, jamais stocké : une demande sans `cancelled_at` dont au moins une
 * ligne n'a pas de `resulted_at`. Inventer une colonne « en attente de
 * résultat » aurait créé un second état à tenir synchrone avec les faits
 * qui le produisent déjà.
 *
 * Ce n'est pas un blocage. L'ADR-076 pose qu'aucun examen n'est exigé pour
 * clore une consultation, et beaucoup de rencontres se concluent sans
 * attendre un résultat. Ce qui est en jeu ici est que le médecin ne clôture
 * pas *sans le savoir*.
 */
const awaitingResults = computed(() => [
    ...(props.consultation?.lab_requests ?? []),
    ...(props.consultation?.imaging_requests ?? []),
]
    .filter((request) => request.status === 'REQUESTED' || request.status === 'IN_PROGRESS')
    .flatMap((request) => (request.items ?? [])
        .filter((item) => !item.resulted_at)
        .map((item) => item.name)));

const hasPendingParaclinicalSelection = computed(() => (
    labRequestForm.items.length > 0 || imagingRequestForm.items.length > 0
));
/**
 * Le compte rendu d'imagerie se saisit dans `ImagingReportDialog`, la même
 * fenêtre que « Demandes d'examens » — feuilles de la clinique comprises
 * (ADR-108). Il y avait ici un second éditeur, réduit et sans feuilles : deux
 * outils pour la même colonne.
 */
const reportingImagingItem = ref(null);

// Les demandes d'orientation (chirurgie, hospitalisation, référence,
// service) vivent maintenant dans ClinicalOrientationCard, au plus près
// du moment où la conduite à tenir est décidée (ADR-084). L'écran ne
// porte plus ni leurs champs ni leurs formulaires.

const careOrderStatusBadgeClass = (status) => ['rounded px-2 py-0.5 text-[10px] font-bold uppercase', {
    PENDING: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    IN_PROGRESS: 'bg-primary/10 text-primary',
    COMPLETED: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
}[status] ?? 'bg-muted text-muted-foreground'];

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
    // `_dose_*` et `_duration_*` servent seulement à composer les chaînes
    // lisibles envoyées dans `dosage` et `duration` ; ils ne sont jamais
    // transmis au serveur.
    _dose_amount: '',
    _dose_unit: 'mg',
    _duration_amount: '',
    _duration_unit: 'jours',
    dosage: '',
    route: null,
    frequency: '',
    duration: '',
    instructions: '',
});
const emptyManualPrescriptionLine = () => ({
    _key: `manual-${++prescriptionLineSequence}`,
    manual: true,
    _dose_amount: '',
    _dose_unit: 'mg',
    _duration_amount: '',
    _duration_unit: 'jours',
    route: null,
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
/**
 * ADR-111 — une ligne proposée (protocole ou pratique de la clinique)
 * rejoint la préparation préremplie, et **rien de plus** : le médecin la
 * règle comme n'importe quelle ligne, puis valide et signe (ADR-106). Elle
 * garde son origine, que le serveur revérifie avant de l'enregistrer.
 */
const addSuggestedPrescriptionLine = (line, group) => {
    const medicine = medicines.value.find((item) => item.uuid === line.medicine_uuid);

    if (!medicine?.available || selectedMedicineUuids.value.has(line.medicine_uuid)) return;

    prescriptionForm.lines.push({
        ...emptyPrescriptionLine(medicine),
        dosage: line.dosage ?? '',
        route: line.route ?? null,
        frequency: line.frequency ?? '',
        duration: line.duration ?? '',
        instructions: line.instructions ?? '',
        quantity: line.quantity ?? 1,
        ...editorFieldsFor(line),
        suggestion_source: group.source,
        suggestion_protocol_uuid: group.protocol_uuid ?? null,
        suggestion_label: group.source === 'PROTOCOL' ? group.name : 'Pratique de la clinique',
    });
};
const suggestions = computed(() => props.clinical_suggestions);
/** Met à jour un champ d'une ligne en préparation, sans muter en place. */
const updatePrescriptionLine = (index, { field, value }) => {
    prescriptionForm.lines = prescriptionForm.lines.map((line, position) => (
        position === index ? { ...line, [field]: value } : line
    ));
};

const removePrescriptionLine = (index) => {
    prescriptionForm.lines.splice(index, 1);
};
/**
 * ADR-110 — la dose n'est exigée que d'un produit qui se dose. Le serveur
 * l'acceptait déjà absente pour une compresse, mais cette garde la réclamait
 * encore : le bouton « Valider » restait grisé sans dire pourquoi.
 */
const hasPosology = (line) => {
    const dosed = line.manual || !isUndosedForm(medicineForLine(line)?.form);

    return (!dosed || String(line.dosage ?? '').trim().length > 0)
        && String(line.frequency ?? '').trim().length > 0;
};
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

/**
 * Lines still only drafted in the browser. Validating the step while they sit
 * unsent would silently discard a prescription the doctor believes recorded.
 */
const pendingPrescriptionSelection = computed(() => {
    if (props.current_step !== 'ordonnance') return null;
    if (prescriptionForm.lines.length) return 'Validez ou retirez les médicaments sélectionnés avant de valider cette étape.';
    if (careOrderForm.items.length) return 'Transmettez ou retirez les actes sélectionnés avant de valider cette étape.';

    return null;
});
/**
 * Valider une ordonnance est un acte signé : la validation **réserve les
 * lots en FEFO** (ADR-036) et verrouille la quantité pour ce patient.
 *
 * La fenêtre reprend le format retenu pour les demandes d'examen : elle
 * nomme chaque ligne avec sa posologie composée — celle que la Pharmacie
 * et le patient liront — et engage le médecin par son nom. Confirmer
 * « 2 médicaments » sans relire les doses ne serait pas une signature.
 */
const showPrescriptionConfirmation = ref(false);
const openPrescriptionConfirmation = () => {
    if (!prescriptionForm.lines.length || !prescriptionStockIsValid.value) return;

    showPrescriptionConfirmation.value = true;
};
const closePrescriptionConfirmation = () => {
    if (prescriptionForm.processing) return;
    showPrescriptionConfirmation.value = false;
};
const confirmPrescription = () => {
    showPrescriptionConfirmation.value = false;
    addPrescription();
};
/** La même composition que l'éditeur de ligne : une seule façon de la lire. */
const prescriptionLinePosology = (line) => [
    line.dosage,
    (props.options?.administration_routes ?? []).find((route) => route.value === line.route)?.short_label,
    line.frequency,
    line.duration,
].filter(Boolean).join(' · ');

const addPrescription = () => prescriptionForm
    .transform((data) => ({
        continue_to_decision: data.continue_to_decision,
        lines: data.lines.map((line) => (line.manual
            ? {
                manual: true,
                medication_name: line.medication_name,
                quantity: line.quantity,
                dosage: line.dosage,
                route: line.route,
                frequency: line.frequency,
                duration: line.duration,
                instructions: line.instructions,
            }
            : {
                manual: false,
                medicine_uuid: line.medicine_uuid,
                quantity: line.quantity,
                dosage: line.dosage,
                route: line.route,
                frequency: line.frequency,
                duration: line.duration,
                instructions: line.instructions,
                // ADR-111 — d'où venait la proposition ; le serveur vérifie
                // que ce protocole prescrit bien ce médicament.
                suggestion_source: line.suggestion_source ?? null,
                suggestion_protocol_uuid: line.suggestion_protocol_uuid ?? null,
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
/**
 * Every diagnosis of this consultation, cancelled ones included.
 *
 * `timelineEntries` deliberately omits the retained final diagnosis and the
 * cancelled entries, because the card it served displayed those elsewhere.
 * The clinical context needs the whole trace: ADR-035 requires a cancelled
 * diagnosis to stay visible with its author and its date — never a gap.
 */
const allDiagnoses = computed(() => props.consultation?.diagnoses ?? []);

const timelineEntries = computed(() => [...finalDiagnoses.value, ...associatedHypotheses.value]
    .filter((diagnosis) => diagnosis.id !== latestFinalDiagnosisEntry.value?.id));
const activePrescriptionSummary = computed(() => (props.consultation?.prescriptions ?? [])
    .flatMap((prescription) => prescription.lines)
    // `posology` est composée côté serveur, voie comprise : le résumé de
    // sortie ne doit pas non plus livrer des nombres sans unité.
    .map((line) => [line.medication_name, line.posology].filter(Boolean).join(' — '))
    .join('\n'));

/** The same lines, one per entry, for the discharge form's checkboxes. */
const activePrescriptionLines = computed(() => activePrescriptionSummary.value.split('\n').filter(Boolean));

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

// ── Saisie en cours ───────────────────────────────────────────────────────
// Everything typed across the wizard survives a reload: only an explicit
// "Annuler la saisie", or a real save, discards it (ADR-073). Server-side
// and scoped to this account — a shared workstation must never hand one
// doctor's unvalidated entry to another.
const draft = useFormDraft({
    endpoint: `/medicine/orientations/${props.orientation.uuid}/draft`,
    initial: props.consultationDraft,
    enabled: Boolean(props.capabilities?.can_update_consultation),
    forms: {
        interview: interviewForm,
        clinical_exam: clinicalExamForm,
        diagnosis: diagnosisForm,
        prescription: prescriptionForm,
        care_order: careOrderForm,
        lab_request: labRequestForm,
        imaging_request: imagingRequestForm,
        discharge: dischargeForm,
    },
});

/**
 * Purge de la sélection devenue impossible à envoyer.
 *
 * Un brouillon peut porter un examen qui, depuis, a été réellement transmis
 * — soit parce qu'il a été envoyé depuis un autre onglet, soit parce que la
 * sélection date d'avant le garde d'unicité. Le serveur refuse désormais un
 * doublon actif, si bien que cette ligne restait affichée sous « Demande en
 * préparation » en face de la demande transmise, sans pouvoir jamais partir.
 *
 * On retire donc la sélection, jamais la demande transmise : celle-ci est le
 * fait clinique, elle porte la saisie du compte rendu et son retrait.
 */
const dropAlreadyRequestedSelection = () => {
    labRequestForm.items = labRequestForm.items
        .filter((line) => !alreadyRequestedLabUuids.value.has(line.catalog_item_uuid));
    imagingRequestForm.items = imagingRequestForm.items
        .filter((line) => !alreadyRequestedImagingUuids.value.has(line.catalog_item_uuid));
};

// À l'ouverture — après restauration du brouillon — puis à chaque fois qu'une
// demande part réellement au service.
dropAlreadyRequestedSelection();
watch(
    () => [props.consultation?.lab_requests, props.consultation?.imaging_requests],
    dropAlreadyRequestedSelection,
    { deep: true },
);

const discardDraft = () => {
    draft.suspend();
    router.delete(`/medicine/orientations/${props.orientation.uuid}/draft`, {
        onFinish: () => draft.resume(),
    });
};
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
// Le préremplissage des demandes vient désormais du serveur
// (`consultation_orientation.prefill`) : composé une seule fois, pour
// toutes les destinations, et identique à ce qui part sur le document
// imprimé (§17).

const prescriptionTab = ref('medicines');
const prescriptionListOpen = ref(false);
const prescribedLineCount = computed(() => (props.consultation?.prescriptions ?? [])
    .reduce((total, prescription) => total + (prescription.lines?.length ?? 0), 0));
/**
 * La conduite à tenir telle que le serveur la connaît. L'écran ne la déduit
 * jamais d'une donnée présente : une demande transmise et une destination
 * simplement choisie sont deux états différents (ADR-084).
 */
const activeOrientation = computed(() => props.consultation_orientation?.active ?? null);
const orientationIsSubmitted = computed(() => activeOrientation.value?.status === 'SUBMITTED');

/**
 * Où la conduite à tenir se décide pour CE patient.
 *
 * Un passage venu pour une seule analyse ou une échographie n'a ni
 * interrogatoire ni examen clinique (ADR-076) : l'y renvoyer pour qu'il
 * déclare sa décision n'aurait aucun sens. Le lien mène alors à la
 * Paraclinique, c'est-à-dire devant le résultat qu'il vient lire.
 */
// ADR-089 — la conduite à tenir ne se décide plus qu'à « Décision & clôture ».
const orientationStep = computed(() => 'cloture');



// « Sortie médicale » garde son formulaire dans l'écran : c'est le seul
// des six qui produit un acte médical, pas une demande à un service.
// La carte d'orientation le monte dans son emplacement dédié.

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

    <div class="w-full space-y-3" :style="{ '--clinical-stack-top': `${pinnedHeaderOffset}px` }">
        <!-- Sentinelle de défilement, hors de tout bloc épinglé : sa
             position ne bouge jamais, quoi que fasse l'en-tête. -->
        <div ref="headerSentinel" class="h-px" aria-hidden="true" />

        <!-- Surcouche épinglée, de hauteur nulle.
             `h-0` est ce qui rend l'ensemble stable : le bandeau déborde
             par-dessus le contenu au lieu de l'occuper, donc l'afficher ou
             le masquer ne change pas d'un pixel la hauteur du document.
             Sans cela, replier faisait sauter la page. -->
        <div class="pointer-events-none sticky top-16 z-40 hidden h-0 lg:block">
            <Transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="-translate-y-2 opacity-0"
                leave-active-class="transition duration-100 ease-in"
                leave-to-class="-translate-y-2 opacity-0"
            >
                <div v-if="showCondensedBar" ref="condensedBar" class="pointer-events-auto">
                    <ClinicalCondensedHeader
                        :patient="patient"
                        :episode="episode"
                        :alerts="vitalAlerts"
                        :step-label="currentStepLabel"
                        :step-position="currentStepPosition"
                        :step-total="relevantWizardSteps.length"
                        @expand="scrollToHeader"
                    >
                        <template #actions>
                            <Button
                                type="button"
                                size="sm"
                                :variant="showClinicalContext ? 'warning-outline' : 'warning'"
                                @click="showClinicalContext = !showClinicalContext"
                            >
                                <FileStack class="h-4 w-4" aria-hidden="true" />
                                <span class="ms-1.5 hidden xl:inline">Contexte clinique</span>
                            </Button>
                        </template>
                    </ClinicalCondensedHeader>
                </div>
            </Transition>
        </div>

        <!-- L'en-tête complet est un bloc ordinaire : il défile et
             disparaît, le bandeau réduit prend le relais. -->
        <div ref="fullHeader" class="space-y-3">
        <!-- Sans identité : elle est portée une seule fois, par la carte
             « Dossier du passage ». Ce bandeau ne garde que l'état de la
             consultation, les constantes et les actions. -->
        <!-- QUI et POURQUOI. L'identité n'est énoncée qu'ici : la carte
             « Dossier du passage » ne la répète plus. -->
        <ClinicalPatientHeader
            :patient="patient"
            :episode="episode"
            :reason="orientation.reason"
            :consultation-type="requestedServiceLabel"
            :doctor="consultation?.doctor"
            :started-at="episode.started_at"
        >
            <template #actions>
                <Button :as="Link" :href="`/patients/${patient.uuid}`" size="sm" variant="white-outline">
                    <User class="me-1.5 h-4 w-4" aria-hidden="true" /><span class="hidden sm:inline">Dossier patient</span>
                </Button>
                <!-- Jaune, et jamais discret : c'est par là que passent les
                     constantes, les allergies et les actes déjà réalisés. -->
                <Button
                    type="button"
                    size="sm"
                    :variant="showClinicalContext ? 'warning-outline' : 'warning'"
                    :aria-expanded="showClinicalContext"
                    aria-controls="medicine-clinical-context"
                    @click="showClinicalContext = !showClinicalContext"
                >
                    <FileStack class="me-1.5 h-4 w-4" aria-hidden="true" /><span class="hidden sm:inline">Contexte clinique</span>
                </Button>
                <Button :as="Link" href="/medicine" size="sm" variant="white-outline">
                    <ArrowLeft class="me-1.5 h-4 w-4" aria-hidden="true" /><span class="hidden sm:inline">File Médecine</span>
                </Button>
                <!-- Toujours rouge et en dernier, détaché des actions
                     ordinaires : requalifier un passage en urgence est une
                     décision grave, son bouton doit se lire comme telle. -->
                <Button
                    v-if="capabilities.can_mark_emergency"
                    class="ms-1"
                    size="sm"
                    variant="danger"
                    :disabled="emergencyForm.processing"
                    @click="markEpisodeEmergency"
                >
                    <CircleAlert class="me-1.5 h-4 w-4" aria-hidden="true" /><span class="hidden sm:inline">Classer en urgence</span>
                </Button>
            </template>

            <!-- Affiché seulement quand un brouillon existe : c'est la seule
                 information que l'en-tête ne peut pas déduire, et le seul
                 endroit d'où l'annuler. Le statut d'enregistrement courant,
                 lui, vit dans la barre d'action basse. -->
            <div
                v-if="draft.restored.value || draft.savedAt.value"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border bg-muted/30 px-4 py-2 text-xs"
            >
                <p class="flex items-center gap-2 text-muted-foreground">
                    <Save class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span>
                        <strong class="font-semibold text-foreground">{{ draft.restored.value ? 'Brouillon restauré' : 'Brouillon enregistré' }}</strong>
                        · non versé au dossier
                        <template v-if="hasDischarge"> · {{ medical_discharge.type_label }} déjà enregistrée</template>
                    </span>
                </p>
                <button
                    type="button"
                    class="shrink-0 rounded px-2 py-1 font-semibold text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                    @click="discardDraft"
                >
                    Effacer le brouillon
                </button>
            </div>
        </ClinicalPatientHeader>

        <!-- RISQUE : d'abord les valeurs, puis ce qu'elles impliquent. -->
        <VitalSignsStrip
            :care-record="care_record"
            :blood-pressure="careRecordBloodPressure"
            :allergies="allergies ?? []"
            :recorded-at="care_record?.updated_at ?? care_record?.created_at"
        />

            <!-- Une consultation clôturée est en lecture seule. Ce bandeau
                 existe sur **toutes** les étapes, et non sur la seule Clôture :
                 « Ouvrir la consultation » depuis « Demandes d'examens » mène
                 à la Paraclinique, où le médecin se retrouvait devant des
                 champs verrouillés sans une seule commande pour en sortir. -->
            <div
                v-if="consultationIsClosed"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50/70 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/20"
            >
                <p class="flex items-start gap-2 text-xs text-amber-900 dark:text-amber-200">
                    <Lock class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>
                        <strong class="font-bold">Consultation clôturée</strong><template v-if="consultation.completed_by"> par {{ consultation.completed_by }}</template><template v-if="consultation.completed_at"> le {{ formatDateTime(consultation.completed_at) }}</template> — le dossier est en lecture seule.
                        <template v-if="capabilities.reopen_blocker"> {{ capabilities.reopen_blocker }}</template>
                        <template v-else> Rouvrez-la pour compléter ce passage, puis clôturez de nouveau.</template>
                    </span>
                </p>

                <Button
                    v-if="capabilities.can_reopen_consultation"
                    type="button"
                    size="sm"
                    variant="warning-outline"
                    class="shrink-0"
                    @click="reopenOpen = true"
                >
                    <RotateCcw class="h-4 w-4" />Rouvrir la consultation
                </Button>
            </div>

            <MedicineWorkflowNav
                :steps="wizardSteps"
                :current-key="current_step"
                :orientation-uuid="orientation.uuid"
            />
        </div>

        <main class="space-y-3">

                <Card v-if="cardIsOpen('dossier')" class="overflow-clip border-s-4 border-s-primary shadow-sm">
                    <!-- Pas d'identité ici : elle est énoncée une seule
                         fois, par l'en-tête clinique de la page. La répéter
                         obligeait à lire deux fois le même nom sur le même
                         écran. -->
                    <div class="flex items-center gap-3 border-b border-border px-5 py-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary" aria-hidden="true"><FileText class="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-primary">Étape 1 · Contexte</p>
                            <h2 class="text-sm font-bold text-foreground">Dossier du passage</h2>
                        </div>
                    </div>
                    <div class="p-5">
                        <div class="grid gap-3 lg:grid-cols-2">
                        <section :class="['rounded-md border border-border bg-muted/35 p-4', !(care_record?.transmission_reason_html || care_record?.diagnostic_note_html) ? 'lg:col-span-2' : '']">
                            <p class="mb-2 flex items-center gap-2 text-[10px] font-bold uppercase tracking-wide text-muted-foreground"><Clipboard class="h-4 w-4" />Besoin exprimé à l’accueil</p>
                            <div v-if="episode.designations.length" class="flex flex-wrap gap-1.5">
                                <span v-for="designation in episode.designations" :key="designation.uuid" class="inline-flex items-center gap-1.5 rounded border border-border bg-muted/35 px-2.5 py-1.5 text-xs font-semibold text-foreground">
                                    {{ designation.description }}
                                    <span v-if="Number(designation.quantity) > 1" class="text-muted-foreground">× {{ designation.quantity }}</span>
                                </span>
                            </div>
                            <p v-else class="text-sm text-muted-foreground">Motif à préciser pendant la consultation.</p>
                        </section>

                        <section v-if="care_record?.transmission_reason_html || care_record?.diagnostic_note_html" class="rounded-md border border-primary/30 bg-primary/5 p-4">
                            <p class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-primary">
                                <Send class="h-4 w-4" />Transmission des Soins
                            </p>
                            <ClinicalRichTextDisplay class="mt-1.5 text-sm leading-5 text-foreground" :html="care_record.transmission_reason_html || care_record.diagnostic_note_html" />
                        </section>
                        </div>

                        <!-- Toujours visible, jamais derrière un repli : c'est
                             exactement ce que le médecin doit voir avant de
                             commencer — masquer l'état civil et les
                             coordonnées par défaut a déjà été rejeté une
                             fois sur cette même page. -->
                        <div class="mt-4 border-t border-border pt-3">
                            <p class="flex items-center gap-2 px-2 py-2 text-sm font-semibold text-muted-foreground"><User class="h-4 w-4 text-muted-foreground" />Informations administratives et coordonnées</p>
                            <div class="grid gap-3 pt-1 lg:grid-cols-3">
                                <section class="rounded-md border border-border p-4">
                                    <h3 class="mb-3 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Informations administratives</h3>
                                    <dl class="grid gap-x-5 gap-y-2 sm:grid-cols-2 lg:grid-cols-1 2xl:grid-cols-2">
                                        <div v-for="field in civilIdentityFields" :key="field.label" class="min-w-0 border-b border-border pb-1.5">
                                            <dt class="text-[10px] text-muted-foreground">{{ field.label }}</dt>
                                            <dd :class="['truncate text-sm font-semibold text-foreground', field.mono ? 'font-mono' : '']" :title="field.value"><span v-if="field.value">{{ field.value }}</span><span v-else class="font-normal text-muted-foreground">Non renseigné</span></dd>
                                        </div>
                                    </dl>
                                </section>
                                <section class="rounded-md border border-border p-4">
                                    <h3 class="mb-3 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Coordonnées</h3>
                                    <dl class="space-y-3">
                                        <div v-for="field in contactFields" :key="field.label" class="min-w-0">
                                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ field.label }}</dt>
                                            <dd class="mt-0.5 truncate text-sm font-semibold text-foreground" :title="field.value"><a v-if="field.href && field.value" :href="field.href" class="hover:text-primary">{{ field.value }}</a><span v-else-if="field.value">{{ field.value }}</span><span v-else class="font-normal text-muted-foreground">Non renseigné</span></dd>
                                        </div>
                                    </dl>
                                </section>
                                <section class="rounded-md border border-border p-4">
                                    <h3 class="mb-3 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Traçabilité</h3>
                                    <dl class="space-y-3">
                                        <div v-for="field in visitFields" :key="field.label" class="min-w-0">
                                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ field.label }}</dt>
                                            <dd class="mt-0.5 truncate text-sm font-semibold text-foreground" :title="field.value">{{ field.value || '—' }}</dd>
                                        </div>
                                    </dl>
                                </section>
                            </div>
                        </div>
                    </div>
                    <ConsultationStepBar
                        :orientation-uuid="orientation.uuid"
                        :save-state="{ saving: draft.saving.value, savedAt: draft.savedAt.value }"
                        step-key="dossier"
                        :state="stepState('dossier')"
                        :next="nextStep ? { key: nextStep.key, label: nextStep.label } : null"
                        :can-edit="capabilities.can_resolve_step"
                    >
                        <template #note>
                            <p class="flex items-center justify-center gap-2 text-xs text-muted-foreground"><Eye class="h-4 w-4 text-primary" />La synthèse Soins reste consultable depuis « Contexte clinique ».</p>
                        </template>
                    </ConsultationStepBar>
                </Card>

                <Card v-if="cardIsOpen('consultation')" class="w-full overflow-clip shadow-sm">
                    <!-- QUOI SAISIR. L'en-tête situe l'étape et rappelle la
                         demande ; le reste de l'écran est du formulaire. -->
                    <div class="flex flex-col gap-3 border-b border-border px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><MessageSquare class="h-4 w-4" /></span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-primary">Étape 2 · Entretien médical</p>
                                <h2 class="mt-0.5 text-base font-bold text-foreground">Interrogatoire</h2>
                                <p class="mt-0.5 text-sm text-muted-foreground">Documentez ce que rapporte le patient avant tout examen physique.</p>
                            </div>
                        </div>
                        <div v-if="episode.designations.length" class="flex shrink-0 flex-wrap items-center gap-1.5">
                            <span class="text-xs font-medium text-muted-foreground">Demande</span>
                            <span
                                v-for="designation in episode.designations"
                                :key="designation.uuid"
                                class="rounded border border-border bg-muted/40 px-2 py-1 text-xs font-medium text-foreground"
                            >{{ designation.description }}</span>
                        </div>
                    </div>

                    <form @submit.prevent="saveInterview">
                        <!-- 70/30 à partir de 1024 px. En dessous, le contexte
                             patient passe sous le formulaire plutôt que de
                             comprimer la saisie. -->
                        <div class="grid gap-6 p-5 lg:grid-cols-[minmax(0,1fr)_22rem] xl:gap-8">
                            <fieldset class="min-w-0 space-y-6">
                                <legend class="sr-only">Interrogatoire du patient</legend>

                                <ClinicalSection
                                    title="Motif principal de consultation"
                                    hint="La raison réelle de la venue, en quelques mots. Jamais le type de prestation demandée."
                                    required
                                >
                                    <Input
                                        id="chief_complaint"
                                        v-model="interviewForm.chief_complaint"
                                        :disabled="!capabilities.can_update_consultation"
                                        maxlength="255"
                                        placeholder="Ex. douleur abdominale persistante"
                                        aria-describedby="chief-complaint-error"
                                    />
                                    <FormError id="chief-complaint-error" class="mt-1" :message="interviewForm.errors.chief_complaint" />
                                </ClinicalSection>

                                <div class="border-t border-border pt-6">
                                    <ClinicalSection title="Histoire de la maladie actuelle">
                                        <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:gap-x-10">
                                            <div class="min-w-0 sm:w-56">
                                                <label for="symptom_onset" class="mb-1.5 block text-sm font-medium text-foreground">Début / durée</label>
                                                <Input
                                                    id="symptom_onset"
                                                    v-model="interviewForm.symptom_onset"
                                                    :disabled="!capabilities.can_update_consultation"
                                                    maxlength="150"
                                                    placeholder="Ex. depuis 3 jours"
                                                />
                                                <FormError :message="interviewForm.errors.symptom_onset" />
                                            </div>

                                            <ClinicalSegmentedChoice
                                                name="evolution"
                                                label="Évolution"
                                                :model-value="interviewForm.evolution"
                                                :options="EVOLUTION_OPTIONS"
                                                :disabled="!capabilities.can_update_consultation"
                                                @update:model-value="interviewForm.evolution = $event"
                                            />
                                        </div>

                                        <div class="mt-5">
                                            <label for="reason" class="mb-1 block text-sm font-medium text-foreground">
                                                Histoire clinique <span class="text-destructive" aria-hidden="true">*</span>
                                            </label>
                                            <p class="mb-2 text-xs leading-5 text-muted-foreground">
                                                Début et évolution des symptômes, symptômes associés, facteurs aggravants ou soulageants, informations rapportées par le patient.
                                            </p>
                                            <ClinicalRichTextEditor
                                                id="reason"
                                                v-model="interviewForm.reason"
                                                :disabled="!capabilities.can_update_consultation"
                                                :max-length="3000"
                                                min-height-class="min-h-56"
                                                placeholder="Récit clinique du patient…"
                                            />
                                            <FormError class="mt-1" :message="interviewForm.errors.reason" />
                                        </div>
                                    </ClinicalSection>
                                </div>

                                <!-- Importance visuelle volontairement moindre :
                                     ce champ complète le récit, il ne le porte pas. -->
                                <div class="border-t border-border pt-6">
                                    <label for="additional_notes" class="mb-1 block text-sm font-medium text-muted-foreground">
                                        Notes complémentaires <span class="font-normal">· facultatif</span>
                                    </label>
                                    <textarea
                                        id="additional_notes"
                                        v-model="interviewForm.additional_notes"
                                        rows="2"
                                        maxlength="2000"
                                        :disabled="!capabilities.can_update_consultation"
                                        :class="[textareaClass, 'resize-y']"
                                        placeholder="Éléments de contexte utiles à la relecture…"
                                    />
                                    <FormError :message="interviewForm.errors.additional_notes" />
                                </div>
                            </fieldset>

                            <!-- QUEL CONTEXTE -->
                            <div class="min-w-0 lg:border-s lg:border-border lg:ps-6 xl:ps-8">
                                <PatientContextPanel
                                    :allergies="allergies ?? []"
                                    :antecedents="antecedents ?? []"
                                    :familial-antecedents="familial_antecedents"
                                    :habitual-treatments="habitualTreatments"
                                    :has-current-treatments="hasCurrentTreatments"
                                    :current-treatments="interviewForm.current_treatments"
                                    :known-treatment-change="interviewForm.known_treatment_change"
                                    :known-treatment-change-notes="interviewForm.known_treatment_change_notes"
                                    :reported-allergies="interviewForm.reported_allergies"
                                    :reported-antecedents="interviewForm.reported_antecedents"
                                    :reported-habitual-treatments="interviewForm.reported_habitual_treatments"
                                    :treatment-change-options="TREATMENT_CHANGE_OPTIONS"
                                    :errors="interviewForm.errors"
                                    :treatment-error="treatmentError"
                                    :disabled="!capabilities.can_update_consultation"
                                    :can-promote="capabilities.can_manage_medical_history"
                                    :textarea-class="textareaClass"
                                    @update:has-current-treatments="setHasCurrentTreatments"
                                    @update:known-treatment-change="setKnownTreatmentChange"
                                    @update:known-treatment-change-notes="interviewForm.known_treatment_change_notes = $event"
                                    @add-treatment="addCurrentTreatment"
                                    @remove-treatment="removeCurrentTreatment"
                                    @update-treatment="updateCurrentTreatment"
                                    @add-reported="addReportedInformation"
                                    @remove-reported="removeReportedInformation"
                                    @update-reported="updateReportedInformation"
                                />
                            </div>
                        </div>

                        <ConsultationStepBar
                            :orientation-uuid="orientation.uuid"
                            :save-state="{ saving: draft.saving.value, savedAt: draft.savedAt.value }"
                            step-key="consultation"
                            :state="stepState('consultation')"
                            :previous="{ key: 'dossier', label: 'Dossier' }"
                            :next="{ key: 'examen', label: 'Examen clinique' }"
                            has-form
                            :dirty="interviewForm.isDirty"
                            :can-continue="interviewFilled"
                            :processing="interviewForm.processing"
                            :can-edit="capabilities.can_update_consultation"
                            :error="interviewForm.errors.consultation"
                            @save="saveInterview(false)"
                            @save-continue="saveInterview(true)"
                        />
                    </form>
                </Card>

                <!-- `overflow-clip` et non `overflow-hidden` : les deux
                     rognent les angles arrondis, mais `hidden` crée un
                     conteneur de défilement qui rendrait inopérant le
                     `sticky` de la colonne de notes ci-dessous. -->
                <Card v-if="cardIsOpen('examen')" class="w-full overflow-clip border-s-4 border-s-primary shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><CirclePlus class="h-4 w-4" /></span>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-primary">Étape 3 · Examen du médecin</p>
                                <h2 class="mt-0.5 text-base font-bold text-foreground">Examen clinique</h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">Consignez les constatations observées par le médecin, distinctes du récit du patient.</p>
                            </div>
                        </div>
                    </div>

                    <form @submit.prevent="saveClinicalExam">
                        <!-- La séparation n'est plus imposée : le médecin la
                             déplace selon qu'il examine ou qu'il rédige, et
                             son choix est retrouvé à la visite suivante. En
                             dessous de la largeur utile, une seule colonne
                             dans l'ordre de la source : état général,
                             appareils, notes. -->
                        <ResizableSplit
                            class="p-5"
                            storage-key="rivo:medicine:exam-split"
                            :default-ratio="0.7"
                            :min-ratio="0.45"
                            :max-ratio="0.75"
                            start-label="panneau Examen clinique"
                            end-label="panneau Notes cliniques"
                        >
                            <template #start>
                        <!-- Chaque médecin ne lit pas un patient dans le même ordre.
                             L'ordre reste une préférence d'affichage : il ne change ni
                             ce qui est enregistré, ni ce que la clôture exige (§6, §25). -->
                        <SortableSections
                            storage-key="rivo:medicine:exam-sections"
                            :sections="EXAM_SECTIONS"
                            :enabled="capabilities.can_update_consultation"
                        >
                            <template #GENERAL_STATE>
                                <ClinicalGeneralState
                                    v-model:general-condition="clinicalExamForm.general_condition"
                                    v-model:consciousness-status="clinicalExamForm.consciousness_status"
                                    v-model:consciousness-details="clinicalExamForm.consciousness_details"
                                    v-model:general-observation="clinicalExamForm.general_observation"
                                    :disabled="!capabilities.can_update_consultation"
                                    :errors="clinicalExamForm.errors"
                                />

                            </template>

                            <template #SYSTEM_EXAM>
                                <ClinicalSystemsAccordion
                                    :systems="clinicalExamForm.systems"
                                    :disabled="!capabilities.can_update_consultation"
                                    :errors="systemFindingErrors"
                                    @update="updateExamSystem"
                                    @reset="resetExamSystems"
                                />


                            </template>

                            <template #DIAGNOSIS>
                                <!-- Le diagnostic peut être conclu ici quand
                                     le médecin le peut déjà (ADR-080). La
                                     question « peut-il être posé maintenant ? »
                                     a rejoint « Décision & clôture », la seule
                                     étape que tout patient atteint (ADR-095) ;
                                     la saisie, elle, reste disponible ici. -->
                                <section class="rounded-lg border border-border p-4 sm:p-5">
                                    <h3 class="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Diagnostic</h3>

                                    <ul v-if="activeDiagnoses.length" class="mt-3 space-y-1.5">
                                            <li v-for="diagnosis in activeDiagnoses" :key="diagnosis.id" class="flex items-start justify-between gap-2 rounded-md border border-border bg-card px-3 py-2">
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-xs font-bold text-foreground">{{ diagnosis.description }}</span>
                                                    <span class="mt-0.5 block truncate text-[10px] text-muted-foreground">
                                                        <template v-if="diagnosis.code"><span class="font-mono">{{ diagnosis.code }}</span> · </template>{{ diagnosis.recorded_by }} · {{ formatDateTime(diagnosis.recorded_at) }}
                                                    </span>
                                                </span>
                                                <span class="flex shrink-0 items-center gap-1.5">
                                                    <!-- Seules les hypothèses portent un badge : les
                                                         masquer ferait lire une hypothèse ancienne comme
                                                         un diagnostic confirmé. -->
                                                    <span v-if="diagnosis.type === 'HYPOTHESIS'" class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">Hypothèse</span>
                                                    <!-- Corriger et annuler restent réservés à l'auteur de
                                                         la saisie : le serveur le revérifie (ADR-035), et
                                                         une annulation laisse une trace, jamais un trou. -->
                                                    <button v-if="diagnosis.can_edit" type="button" class="flex size-7 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-primary/50 hover:text-primary" :title="`Modifier « ${diagnosis.description} »`" :aria-label="`Modifier le diagnostic ${diagnosis.description}`" @click="startDiagnosisEdit(diagnosis)"><Pencil class="h-4 w-4" /></button>
                                                    <button v-if="diagnosis.can_cancel" type="button" class="flex size-7 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600 dark:hover:border-red-900 dark:hover:bg-red-950/30" :title="`Annuler « ${diagnosis.description} »`" :aria-label="`Annuler le diagnostic ${diagnosis.description}`" :disabled="diagnosisCancellationForm.processing" @click="openDiagnosisCancellation(diagnosis)"><Trash2 class="h-4 w-4" /></button>
                                                </span>
                                            </li>
                                        </ul>

                                        <ClinicalDiagnosisSuggestions
                                            v-if="suggestions && capabilities.can_create_diagnosis"
                                            class="mt-3"
                                            :suggestions="suggestions.diagnoses"
                                            :orientation-uuid="orientation.uuid"
                                            return-step="examen"
                                            :protocol-count="suggestions.protocol_count"
                                            :practice-cases="suggestions.practice_cases"
                                            :practice-min-cases="suggestions.practice_min_cases"
                                            :can-manage-protocols="suggestions.can_manage_protocols"
                                        />

                                        <div class="mt-3">
                                            <ClinicalDiagnosisEntry
                                                :orientation-uuid="orientation.uuid"
                                                return-step="examen"
                                                :disabled="!capabilities.can_create_diagnosis"
                                                compact
                                            />
                                        </div>

                                    <p class="mt-2 text-[11px] leading-4 text-muted-foreground">
                                        Seul l’auteur d’une saisie peut la corriger ou l’annuler ; un diagnostic enregistré n’est jamais réécrit, et l’historique complet reste dans « Contexte clinique ».
                                    </p>
                                </section>
                            </template>


                        </SortableSections>
                            </template>

                            <template #end>
                            <!-- `self-start` est indispensable : un item de
                                 grille s'étire par défaut à la hauteur de la
                                 rangée, et un bloc déjà aussi haut que son
                                 rail n'a jamais rien à coller. `top-20`
                                 dégage le header fixe de 64px. -->
                            <aside class="min-w-0 lg:sticky lg:top-[var(--clinical-stack-top,5rem)] lg:self-start">
                                <!-- Conservées, jamais supprimées : ce que la
                                     grille ne peut pas porter doit rester
                                     consignable. Mais elles ne sont plus le
                                     seul domicile de l'examen. -->
                                <section class="rounded-lg border border-border p-4">
                                    <label for="clinical_exam" class="block text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Notes cliniques complémentaires</label>
                                    <p class="mt-1 text-[11px] leading-4 text-muted-foreground">Consignez ici les constatations qui ne sont pas couvertes par les rubriques précédentes. Facultatif.</p>
                                    <div class="mt-3">
                                        <!-- Assez haute pour écrire, bornée pour
                                             ne pas dépasser le rail du sticky :
                                             au-delà, l'éditeur défile seul. -->
                                        <ClinicalRichTextEditor id="clinical_exam" v-model="clinicalExamForm.clinical_exam" :disabled="!capabilities.can_update_consultation" :max-length="10000" min-height-class="min-h-52 lg:min-h-96 lg:max-h-[26rem] lg:overflow-y-auto" placeholder="Constatations complémentaires, éléments de contexte utiles à la relecture…" />
                                        <FormError class="mt-1" :message="clinicalExamForm.errors.clinical_exam" />
                                    </div>
                                </section>
                            </aside>
                            </template>
                        </ResizableSplit>
                        <ConsultationStepBar
                            :orientation-uuid="orientation.uuid"
                            :save-state="{ saving: draft.saving.value, savedAt: draft.savedAt.value }"
                            step-key="examen"
                            :state="stepState('examen')"
                            :previous="{ key: 'consultation', label: 'Interrogatoire' }"
                            :next="{ key: 'paraclinique', label: 'Paraclinique' }"
                            has-form
                            :dirty="clinicalExamForm.isDirty"
                            :can-continue="clinicalExamDocumented && !clinicalExamHasBlankAnomaly"
                            :local-blocker="clinicalExamHasBlankAnomaly ? 'Veuillez renseigner les constatations de l’anomalie.' : null"
                            :processing="clinicalExamForm.processing"
                            :can-edit="capabilities.can_update_consultation"
                            :error="clinicalExamForm.errors.consultation"
                            @save="saveClinicalExam(false)"
                            @save-continue="saveClinicalExam(true)"
                        />
                    </form>
                </Card>

                <Card v-if="cardIsOpen('paraclinique')" class="w-full overflow-clip border-s-4 border-s-primary shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><Activity class="h-4 w-4" /></span>
                            <div>
                                <h2 class="text-sm font-bold text-foreground">Examens paracliniques</h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">Prescrivez les examens utiles et transmettez une indication claire au service concerné.</p>
                            </div>
                        </div>
                        <span class="self-start rounded-md border border-border bg-muted/35 px-2.5 py-1 text-[11px] font-semibold text-muted-foreground">Étape facultative</span>
                    </div>

                    <!-- La question qui gouverne l'étape est posée au début de
                         l'étape qu'elle gouverne. « Non » la marque non
                         nécessaire et mène au diagnostic, sans faire traverser
                         un écran vide. -->
                    <div class="border-b border-border px-5 py-4">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <span class="text-xs font-semibold text-muted-foreground">Des examens complémentaires sont-ils nécessaires ?</span>
                            <span class="inline-flex rounded border border-border bg-card p-0.5" role="radiogroup" aria-label="Des examens complémentaires sont-ils nécessaires ?">
                                <button type="button" role="radio" :aria-checked="complementaryExamsForm.required === false" :disabled="!capabilities.can_update_consultation || complementaryExamsForm.processing" :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', complementaryExamsForm.required === false ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/35']" @click="decideComplementaryExams(false)">Non</button>
                                <button type="button" role="radio" :aria-checked="complementaryExamsForm.required === true" :disabled="!capabilities.can_update_consultation || complementaryExamsForm.processing" :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', complementaryExamsForm.required === true ? 'bg-primary text-white' : 'text-muted-foreground hover:bg-muted/35']" @click="decideComplementaryExams(true)">Oui</button>
                            </span>
                        </div>
                        <!-- La suite est nommée par le serveur (`nextStep`),
                             jamais écrite en dur : ce texte annonçait encore
                             « passe au diagnostic », une étape qui n'existe
                             plus depuis l'ADR-081. -->
                        <p v-if="complementaryExamsForm.required === null" class="mt-2 text-[11px] leading-4 text-muted-foreground">
                            Répondez pour poursuivre : « Non » déclare l’étape non nécessaire<template v-if="nextStep"> et passe à {{ nextStep.label.toLocaleLowerCase('fr') }}</template>.
                        </p>
                        <FormError class="mt-1" :message="complementaryExamsForm.errors.required" />
                    </div>

                    <div v-if="complementaryExamsForm.required !== false" class="border-b border-border bg-muted/35 px-5 py-2.5">
                        <!-- ADR-106 — ECG et Échographie sont deux familles
                             distinctes, réglées au catalogue. Le groupe
                             « Non classés » n'apparaît que s'il contient
                             réellement un examen : ranger au hasard un
                             examen dont personne n'a dit la famille le
                             ferait disparaître d'un onglet sans le dire. -->
                        <div :class="['grid max-w-2xl gap-1 rounded-lg border border-border bg-muted p-1', paraclinicalTabs.length > 3 ? 'grid-cols-4' : 'grid-cols-3']" role="tablist" aria-label="Type d’examen paraclinique">
                            <button
                                v-for="tab in paraclinicalTabs"
                                :key="tab.value"
                                type="button"
                                role="tab"
                                :aria-selected="paracliniqueTab === tab.value"
                                :class="['flex h-10 items-center justify-center gap-2 rounded-md px-3 text-sm font-semibold transition-all', paracliniqueTab === tab.value ? 'bg-card text-primary shadow-sm ring-1 ring-border' : 'text-muted-foreground hover:bg-accent hover:text-foreground']"
                                @click="paracliniqueTab = tab.value"
                            >
                                <component :is="tab.icon" class="h-4 w-4" />
                                <span class="truncate">{{ tab.label }}</span>
                                <!-- Les demandes réellement transmises, et elles
                                     seules. Additionner la sélection en cours
                                     annonçait « 2 examens » quand un seul était
                                     parti au service. Ce qui reste à envoyer se
                                     lit dans le bloc « Demande en préparation ». -->
                                <span v-if="tab.count" class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-bold tabular-nums text-primary">{{ tab.count }}</span>
                            </button>
                        </div>
                    </div>

                    <template v-if="paracliniqueTab === 'lab'">
                        <div v-if="consultation.lab_requests?.length" class="space-y-3 border-b border-border bg-muted/35 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Demandes transmises</p>
                                <span class="text-xs text-muted-foreground">{{ consultation.lab_requests.length }} demande(s)</span>
                            </div>
                            <div v-for="request in consultation.lab_requests" :key="request.uuid" class="rounded-lg border border-border bg-card p-3.5 shadow-sm">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-foreground">{{ formatDateTime(request.requested_at) }} — Dr {{ request.requested_by }}</p>
                                    <div class="flex items-center gap-2">
                                        <span :class="labRequestStatusBadgeClass(request.status)">{{ labRequestStatusLabel(request.status) }}</span>
                                        <!-- Retirable tant qu'aucun résultat n'est arrivé ;
                                             le serveur revérifie. -->
                                        <Button
                                            v-if="capabilities.can_update_consultation && request.status === 'REQUESTED'"
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            @click="askWithdrawal('lab', request)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="ms-1.5">Retirer</span>
                                        </Button>
                                    </div>
                                </div>
                                <ul class="mt-2 space-y-1.5">
                                    <li v-for="item in request.items" :key="item.uuid" class="text-xs">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-semibold text-foreground">{{ item.name }}</span>
                                            <span v-if="item.resulted_at" class="font-semibold text-emerald-600 dark:text-emerald-300">{{ item.result_value }}</span>
                                            <!-- Une icône suffit à l'écran ; le sens reste dit au
                                                 survol et au lecteur d'écran. -->
                                            <span v-else class="inline-flex shrink-0 items-center text-amber-600 dark:text-amber-400" title="En attente de résultat">
                                                <Hourglass class="h-4 w-4" aria-hidden="true" />
                                                <span class="sr-only">En attente de résultat</span>
                                            </span>
                                        </div>
                                        <p v-if="item.result_notes" class="mt-0.5 text-[11px] text-muted-foreground">{{ item.result_notes }}</p>
                                    </li>
                                </ul>
                                <p v-if="request.notes" class="mt-1.5 text-[11px] text-muted-foreground">{{ request.notes }}</p>
                            </div>
                        </div>
                        <form v-if="capabilities.can_create_lab_request" id="lab-request-form" class="p-5" @submit.prevent="openRequestConfirmation('lab')">

                            <!-- ADR-109 — le besoin de l'arrivée, déjà porté dans la
                                 demande en préparation. Le dire évite de croire à une
                                 sélection faite par erreur, et de le facturer deux fois
                                 en le cherchant à nouveau au catalogue. -->
                            <p v-if="plannedLab.length" class="mb-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <ClipboardCheck class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                                <span class="font-semibold text-foreground">{{ plannedLab.map((line) => line.name).join(' · ') }}</span>
                                — demandé à l’arrivée<template v-if="plannedLab.some((line) => line.already_billed)">, déjà facturé</template>.
                            </p>
                            <div class="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
                                <div class="min-w-0 space-y-4">
                                    <div>
                                        <div class="mb-1.5 flex items-center justify-between gap-3">
                                            <label class="block text-sm font-semibold text-foreground">Ajouter une analyse</label>
                                            <span v-if="labRequestForm.items.length" class="text-xs font-semibold text-primary">{{ labRequestForm.items.length }} sélectionnée(s)</span>
                                        </div>
                                <IconInput v-model="labSearch" :icon="Search" placeholder="NFS, CRP, glycémie…" />
                                        <div v-if="labSearch.trim() && filteredLabCatalog.length" class="mt-2 max-h-52 divide-y divide-border overflow-y-auto rounded-lg border border-border bg-card shadow-lg">
                                    <!-- Un examen déjà demandé et en attente n'est pas
                                         proposable : le serveur le refuserait, et l'offrir
                                         menait à préparer une demande impossible à envoyer. -->
                                    <button
                                        v-for="item in filteredLabCatalog"
                                        :key="item.uuid"
                                        type="button"
                                        :disabled="isAlreadyRequestedLab(item)"
                                        :class="['flex w-full items-center justify-between gap-4 px-3.5 py-2.5 text-start transition-colors', isAlreadyRequestedLab(item) ? 'cursor-not-allowed bg-muted/40' : 'hover:bg-accent']"
                                        @click="addLabItem(item)"
                                    >
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-foreground">{{ item.name }}</span>
                                            <span v-if="isAlreadyRequestedLab(item)" class="mt-0.5 block text-xs font-medium text-amber-600 dark:text-amber-400">Déjà demandé · en attente de résultat</span>
                                            <span v-else-if="item.code" class="mt-0.5 block text-xs text-muted-foreground">{{ item.code }}</span>
                                        </span>
                                        <span :class="['flex size-7 shrink-0 items-center justify-center rounded-md border', isAlreadyRequestedLab(item) ? 'border-border text-muted-foreground' : isLabItemSelected(item) ? 'border-primary/30 bg-primary/10 text-primary' : 'border-border text-muted-foreground']">
                                            <component :is="isAlreadyRequestedLab(item) ? Clock : isLabItemSelected(item) ? Check : Plus" class="h-4 w-4" aria-hidden="true" />
                                        </span>
                                    </button>
                                </div>
                                <p v-else-if="labSearch.trim()" class="mt-2 text-xs text-muted-foreground">Aucune analyse ne correspond.</p>
                                <FormError :message="labRequestForm.errors.items" />
                                    </div>

                                    <div v-if="labRequestForm.items.length" class="overflow-hidden rounded-lg border border-border">
                                        <div class="flex items-center justify-between border-b border-border bg-muted/35 px-3.5 py-2.5">
                                            <p class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Prescription en préparation</p>
                                            <span class="rounded-full bg-card px-2 py-0.5 text-[10px] font-bold tabular-nums text-muted-foreground ring-1 ring-border">{{ labRequestForm.items.length }}</span>
                                        </div>
                                        <div class="divide-y divide-border">
                                            <div v-for="line in labRequestForm.items" :key="line.catalog_item_uuid" class="flex items-center gap-3 px-3.5 py-3">
                                                <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"><Activity class="h-4 w-4" /></span>
                                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-foreground">{{ line.name }}</span><span v-if="line.code" class="mt-0.5 block text-[11px] text-muted-foreground">{{ line.code }}</span></span>
                                                <button type="button" class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border text-muted-foreground transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600 dark:hover:border-red-900 dark:hover:bg-red-950/30" :aria-label="`Retirer ${line.name}`" @click="removeLabItem(line)"><Trash2 class="h-4 w-4" /></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-lg border border-border bg-muted/35 p-4">
                                    <div class="flex items-start gap-2.5">
                                        <FileText class="h-4 w-4 mt-0.5 shrink-0  text-primary" />
                                        <div class="min-w-0 flex-1">
                                            <label for="lab_notes" class="block text-sm font-semibold text-foreground">Indication clinique</label>
                                            <p class="mt-0.5 text-xs leading-5 text-muted-foreground">Ajoutez le contexte nécessaire à l’interprétation et à la réalisation des analyses.</p>
                                        </div>
                                    </div>
                                    <textarea id="lab_notes" v-model="labRequestForm.notes" rows="5" :class="[textareaClass, 'mt-3 bg-card']" placeholder="Contexte clinique, symptômes, traitement en cours…" />
                                    <FormError :message="labRequestForm.errors.notes" />
                                </div>
                            </div>
                        </form>
                    </template>

                    <template v-else>
                        <div v-if="consultation.imaging_requests?.length" class="space-y-3 border-b border-border bg-muted/35 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Demandes transmises</p>
                                <span class="text-xs text-muted-foreground">{{ consultation.imaging_requests.length }} demande(s)</span>
                            </div>
                            <div v-for="request in consultation.imaging_requests" :key="request.uuid" class="rounded-lg border border-border bg-card p-3.5 shadow-sm">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-foreground">{{ formatDateTime(request.requested_at) }} — Dr {{ request.requested_by }}</p>
                                    <div class="flex items-center gap-2">
                                        <span :class="labRequestStatusBadgeClass(request.status)">{{ labRequestStatusLabel(request.status) }}</span>
                                        <!-- Retirable tant qu'aucun résultat n'est arrivé ;
                                             le serveur revérifie. -->
                                        <Button
                                            v-if="capabilities.can_update_consultation && request.status === 'REQUESTED'"
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            @click="askWithdrawal('imaging', request)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="ms-1.5">Retirer</span>
                                        </Button>
                                    </div>
                                </div>
                                <ul class="mt-2 space-y-2">
                                    <li v-for="item in request.items" :key="item.uuid" class="text-xs">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-semibold text-foreground">{{ item.name }}</span>
                                            <!-- Une icône suffit : le badge de la demande dit
                                                 déjà « Résultats disponibles ». Le sens reste
                                                 au survol et au lecteur d'écran. -->
                                            <span v-if="item.resulted_at" class="inline-flex shrink-0 items-center text-emerald-600 dark:text-emerald-400" title="Compte rendu enregistré">
                                                <CircleCheck class="h-4 w-4" aria-hidden="true" />
                                                <span class="sr-only">Compte rendu enregistré</span>
                                            </span>
                                            <Button
                                                v-else-if="capabilities.can_record_imaging_result"
                                                type="button"
                                                size="xs"
                                                variant="white-outline"
                                                @click="reportingImagingItem = { uuid: item.uuid, exam: item.name }"
                                            >
                                                <PenLine class="h-3.5 w-3.5" aria-hidden="true" />Saisir le compte rendu
                                            </Button>
                                            <span v-else class="inline-flex shrink-0 items-center text-amber-600 dark:text-amber-400" title="En attente de compte rendu">
                                                <Hourglass class="h-4 w-4" aria-hidden="true" />
                                                <span class="sr-only">En attente de compte rendu</span>
                                            </span>
                                        </div>
                                        <!-- Un compte rendu enregistré se lit, il ne se
                                             ressaisit pas : le serveur refuserait un second
                                             compte rendu. Le formulaire était rattaché par
                                             `v-else-if` à la ligne des *notes* : sans notes,
                                             un éditeur vide s'affichait sous un compte rendu
                                             déjà enregistré. -->
                                        <template v-if="item.resulted_at">
                                            <ClinicalRichTextDisplay
                                                class="mt-0.5 line-clamp-3 text-[11px] leading-4 text-muted-foreground"
                                                :html="item.result_value"
                                            />
                                            <p v-if="item.result_notes" class="mt-0.5 text-[11px] italic text-muted-foreground">{{ item.result_notes }}</p>
                                        </template>
                                    </li>
                                </ul>
                                <p v-if="request.notes" class="mt-1.5 text-[11px] text-muted-foreground">{{ request.notes }}</p>
                            </div>
                        </div>
                        <form v-if="capabilities.can_create_imaging_request" id="imaging-request-form" class="p-5" @submit.prevent="openRequestConfirmation('imaging')">

                            <!-- ADR-109 — le besoin de l'arrivée, déjà porté dans la
                                 demande en préparation. Le dire évite de croire à une
                                 sélection faite par erreur, et de le facturer deux fois
                                 en le cherchant à nouveau au catalogue. -->
                            <p v-if="plannedImaging.length" class="mb-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <ClipboardCheck class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                                <span class="font-semibold text-foreground">{{ plannedImaging.map((line) => line.name).join(' · ') }}</span>
                                — demandé à l’arrivée<template v-if="plannedImaging.some((line) => line.already_billed)">, déjà facturé</template>.
                            </p>
                            <div class="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
                                <div class="min-w-0 space-y-4">
                                    <div>
                                        <div class="mb-1.5 flex items-center justify-between gap-3">
                                            <label class="block text-sm font-semibold text-foreground">Ajouter {{ paracliniqueTab === 'CARDIOLOGY' ? 'un examen cardiologique' : paracliniqueTab === 'ULTRASOUND' ? 'une échographie' : 'un examen' }}</label>
                                            <span v-if="imagingRequestForm.items.length" class="text-xs font-semibold text-primary">{{ imagingRequestForm.items.length }} sélectionné(s)</span>
                                        </div>
                                        <IconInput v-model="imagingSearch" :icon="Search" :placeholder="paracliniqueTab === 'CARDIOLOGY' ? 'ECG, ECG d’effort, Holter…' : paracliniqueTab === 'ULTRASOUND' ? 'Échographie abdominale, Doppler…' : 'Rechercher un examen…'" />
                                        <div v-if="imagingSearch.trim() && filteredImagingCatalog.length" class="mt-2 max-h-52 divide-y divide-border overflow-y-auto rounded-lg border border-border bg-card shadow-lg">
                                            <!-- Un examen déjà demandé et en attente n'est pas
                                                 proposable : le serveur le refuserait, et l'offrir
                                                 menait à préparer une demande impossible à envoyer. -->
                                            <button
                                                v-for="item in filteredImagingCatalog"
                                                :key="item.uuid"
                                                type="button"
                                                :disabled="isAlreadyRequestedImaging(item)"
                                                :class="['flex w-full items-center justify-between gap-4 px-3.5 py-2.5 text-start transition-colors', isAlreadyRequestedImaging(item) ? 'cursor-not-allowed bg-muted/40' : 'hover:bg-accent']"
                                                @click="addImagingItem(item)"
                                            >
                                                <span class="min-w-0">
                                                    <span class="block truncate text-sm font-semibold text-foreground">{{ item.name }}</span>
                                                    <span v-if="isAlreadyRequestedImaging(item)" class="mt-0.5 block text-xs font-medium text-amber-600 dark:text-amber-400">Déjà demandé · en attente de résultat</span>
                                                    <span v-else-if="item.code" class="mt-0.5 block text-xs text-muted-foreground">{{ item.code }}</span>
                                                </span>
                                                <span :class="['flex size-7 shrink-0 items-center justify-center rounded-md border', isAlreadyRequestedImaging(item) ? 'border-border text-muted-foreground' : isImagingItemSelected(item) ? 'border-primary/30 bg-primary/10 text-primary' : 'border-border text-muted-foreground']">
                                                    <component :is="isAlreadyRequestedImaging(item) ? Clock : isImagingItemSelected(item) ? Check : Plus" class="h-4 w-4" aria-hidden="true" />
                                                </span>
                                            </button>
                                        </div>
                                        <p v-else-if="imagingSearch.trim()" class="mt-2 text-xs text-muted-foreground">Aucun examen ne correspond.</p>
                                        <p v-else-if="!imagingCatalog.length" class="mt-2 text-xs text-muted-foreground">Aucun examen ECG/échographie n’est encore configuré au référentiel.</p>
                                        <FormError :message="imagingRequestForm.errors.items" />
                                    </div>

                                    <div v-if="imagingRequestForm.items.length" class="overflow-hidden rounded-lg border border-border">
                                        <div class="flex items-center justify-between border-b border-border bg-muted/35 px-3.5 py-2.5">
                                            <p class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Demande en préparation</p>
                                            <span class="rounded-full bg-card px-2 py-0.5 text-[10px] font-bold tabular-nums text-muted-foreground ring-1 ring-border">{{ imagingRequestForm.items.length }}</span>
                                        </div>
                                        <div class="divide-y divide-border">
                                            <div v-for="line in imagingRequestForm.items" :key="line.catalog_item_uuid" class="flex items-center gap-3 px-3.5 py-3">
                                                <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"><ScanLine class="h-4 w-4" /></span>
                                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-foreground">{{ line.name }}</span><span v-if="line.code" class="mt-0.5 block text-[11px] text-muted-foreground">{{ line.code }}</span></span>
                                                <button type="button" class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border text-muted-foreground transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600 dark:hover:border-red-900 dark:hover:bg-red-950/30" :aria-label="`Retirer ${line.name}`" @click="removeImagingItem(line)"><Trash2 class="h-4 w-4" /></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-lg border border-border bg-muted/35 p-4">
                                    <div class="flex items-start gap-2.5">
                                        <FileText class="h-4 w-4 mt-0.5 shrink-0  text-primary" />
                                        <div class="min-w-0 flex-1">
                                            <label for="imaging_notes" class="block text-sm font-semibold text-foreground">Indication clinique</label>
                                            <p class="mt-0.5 text-xs leading-5 text-muted-foreground">Précisez le motif, la région concernée et les éléments cliniques utiles à l’examen.</p>
                                        </div>
                                    </div>
                                    <textarea id="imaging_notes" v-model="imagingRequestForm.notes" rows="5" :class="[textareaClass, 'mt-3 bg-card']" placeholder="Motif de l’examen, symptômes, région à explorer…" />
                                    <FormError :message="imagingRequestForm.errors.notes" />
                                </div>
                            </div>
                        </form>
                    </template>



                    <ConsultationStepBar
                        :orientation-uuid="orientation.uuid"
                        :save-state="{ saving: draft.saving.value, savedAt: draft.savedAt.value }"
                        step-key="paraclinique"
                        :state="stepState('paraclinique')"
                        :previous="{ key: 'examen', label: 'Examen clinique' }"
                        :next="{ key: 'ordonnance', label: 'Prescription' }"
                        :can-edit="capabilities.can_resolve_step"
                        :local-blocker="hasPendingParaclinicalSelection ? 'Enregistrez les examens sélectionnés avant de valider cette étape.' : null"
                        :allow-skip="false"
                    >
                        <template #note>
                            <p v-if="hasPendingParaclinicalSelection" class="text-center text-xs font-semibold text-amber-700 dark:text-amber-300">Enregistrez les examens sélectionnés avant de poursuivre.</p>
                            <p v-else-if="hasExistingParaclinicalRequest" class="text-center text-xs text-muted-foreground">Les demandes enregistrées restent suivies dans le dossier du patient.</p>
                            <p v-else class="text-center text-xs text-muted-foreground">Étape facultative · prescrivez un examen uniquement lorsqu’il est indiqué.</p>
                        </template>
                        <template #actions>
                            <Button v-if="paracliniqueTab === 'lab' && labRequestForm.items.length" type="button" size="rg" :disabled="labRequestForm.processing || imagingRequestForm.processing" @click="openRequestConfirmation('lab')">
                                <Activity class="h-4 w-4 me-2" />{{ imagingRequestForm.items.length ? 'Envoyer puis finaliser l’imagerie' : 'Envoyer au Laboratoire' }}
                            </Button>
                            <Button v-else-if="isImagingTab && imagingRequestForm.items.length" type="button" size="rg" :disabled="imagingRequestForm.processing || labRequestForm.processing" @click="openRequestConfirmation('imaging')">
                                <Activity class="h-4 w-4 me-2" />{{ labRequestForm.items.length ? 'Envoyer puis finaliser les analyses' : 'Envoyer la demande' }}
                            </Button>
                            <Button v-else-if="labRequestForm.items.length" type="button" size="rg" variant="white-outline" @click="paracliniqueTab = 'lab'">Finaliser les analyses ({{ labRequestForm.items.length }})</Button>
                            <Button v-else-if="imagingRequestForm.items.length" type="button" size="rg" variant="white-outline" @click="paracliniqueTab = 'imaging'">Finaliser l’imagerie ({{ imagingRequestForm.items.length }})</Button>
                        </template>
                    </ConsultationStepBar>
                </Card>

                <!-- Onglets au format shadcn (TabsList) : compacts, à la taille
                     de leur contenu. Chaque onglet garde sa couleur — primaire
                     pour Médicaments, émeraude pour Soins — sur l'icône, le
                     texte et le compteur ; l'onglet ouvert se détache sur fond
                     carte. -->
                <div v-if="cardIsOpen('ordonnance') && capabilities.can_view_care_orders" class="inline-flex h-9 items-center gap-1 rounded-lg bg-muted p-1" role="tablist" aria-label="Type de prescription">
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="prescriptionTab === 'medicines'"
                        :class="['inline-flex h-7 items-center gap-1.5 rounded-md px-3 text-sm font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                                 prescriptionTab === 'medicines' ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground hover:text-primary']"
                        @click="prescriptionTab = 'medicines'"
                    >
                        <Pill class="h-3.5 w-3.5 text-primary" />
                        Médicaments
                        <span v-if="prescriptionForm.lines.length" class="rounded-full bg-primary/10 px-1.5 text-[10px] font-semibold tabular-nums text-primary">{{ prescriptionForm.lines.length }}</span>
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="prescriptionTab === 'care'"
                        :class="['inline-flex h-7 items-center gap-1.5 rounded-md px-3 text-sm font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40',
                                 prescriptionTab === 'care' ? 'bg-card text-emerald-700 shadow-sm dark:text-emerald-300' : 'text-muted-foreground hover:text-emerald-700 dark:hover:text-emerald-300']"
                        @click="prescriptionTab = 'care'"
                    >
                        <Activity class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                        Soins
                        <span v-if="careOrderForm.items.length" class="rounded-full bg-emerald-100 px-1.5 text-[10px] font-semibold tabular-nums text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">{{ careOrderForm.items.length }}</span>
                    </button>
                </div>

                <!-- Deux colonnes : à gauche ce que le médecin prépare, à droite
                     ce qui est déjà parti. Les demandes terminées ou retirées se
                     replient sous « Historique » pour ne pas repousser le
                     formulaire hors de l'écran. -->
                <Card v-if="cardIsOpen('ordonnance') && capabilities.can_view_care_orders && prescriptionTab === 'care'" class="w-full overflow-hidden shadow-sm">
                    <div class="flex items-center justify-between gap-4 border-b border-border px-5 py-3.5">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><Activity class="h-4 w-4" /></span>
                            <div class="min-w-0">
                                <h2 class="text-sm font-semibold text-foreground">Prescription de soins</h2>
                                <p class="truncate text-xs text-muted-foreground">Actes à transmettre à l’équipe Soins pour ce passage.</p>
                            </div>
                        </div>
                        <Badge v-if="careOrderForm.items.length" variant="outline" class="shrink-0">{{ careOrderForm.items.length }} à transmettre</Badge>
                    </div>

                    <div class="grid lg:grid-cols-[minmax(0,1fr)_20rem]">
                        <form v-if="capabilities.can_create_care_order" id="care-order-form" class="min-w-0 space-y-4 p-5" @submit.prevent="openCareOrderConfirmation">
                            <div>
                                <IconInput v-model="careOrderSearch" :icon="Search" placeholder="Ajouter un acte : injection, perfusion, pansement…" aria-label="Ajouter un acte de soins" />
                                <div v-if="careOrderSearch.trim() && filteredCareOrderCatalog.length" class="mt-1.5 max-h-52 divide-y divide-border overflow-y-auto rounded-lg border border-border bg-popover shadow-md">
                                    <button v-for="item in filteredCareOrderCatalog" :key="item.uuid" type="button" :disabled="isCareOrderItemSelected(item) || isCareOrderItemPending(item)" class="flex w-full items-center justify-between gap-4 px-3 py-2 text-start transition-colors hover:bg-accent disabled:cursor-default disabled:opacity-70 disabled:hover:bg-transparent" @click="addCareOrderItem(item)">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-foreground">{{ item.name }}</span>
                                            <span v-if="item.code" class="block font-mono text-[10px] text-muted-foreground">{{ item.code }}</span>
                                        </span>
                                        <span v-if="isCareOrderItemPending(item)" class="inline-flex shrink-0 items-center gap-1 text-[11px] font-medium text-amber-700 dark:text-amber-300"><Clock class="h-3.5 w-3.5" />Déjà en attente aux Soins</span>
                                        <span v-else-if="isCareOrderItemSelected(item)" class="inline-flex shrink-0 items-center gap-1 text-[11px] font-medium text-primary"><Check class="h-3.5 w-3.5" />Ajouté</span>
                                        <Plus v-else class="h-4 w-4 shrink-0 text-primary" />
                                    </button>
                                </div>
                                <p v-else-if="careOrderSearch.trim()" class="mt-1.5 text-xs text-muted-foreground">Aucun acte prescriptible ne correspond.</p>
                                <FormError :message="careOrderForm.errors.items" />
                                <p v-if="careOrderDuplicateNotice" class="mt-1.5 flex items-start gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-300" role="status"><Clock class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ careOrderDuplicateNotice }}</p>
                            </div>

                            <ul v-if="careOrderForm.items.length" class="divide-y divide-border rounded-lg border border-border">
                                <li v-for="(line, index) in careOrderForm.items" :key="line.catalog_item_uuid" class="flex items-center gap-3 px-3 py-2">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-foreground">{{ line.name }}</span>
                                        <span v-if="line.code" class="block font-mono text-[10px] text-muted-foreground">{{ line.code }}</span>
                                        <FormError :message="careOrderForm.errors[`items.${index}.quantity`]" />
                                    </span>
                                    <Input :id="`care-order-quantity-${index}`" v-model="line.quantity" type="number" min="1" step="1" inputmode="numeric" class="w-20 text-center" :aria-label="`Quantité de ${line.name}`" />
                                    <Button type="button" size="icon" variant="ghost" class="text-muted-foreground hover:text-destructive" :title="`Retirer ${line.name}`" :aria-label="`Retirer ${line.name}`" @click="removeCareOrderItem(line)"><Trash2 class="h-4 w-4" /></Button>
                                </li>
                            </ul>
                            <p v-else class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">Recherchez un acte pour le préparer.</p>

                            <FormField label="Instructions pour l’équipe Soins" hint="facultatif" :error="careOrderForm.errors.instructions">
                                <Textarea id="care_order_instructions" v-model="careOrderForm.instructions" rows="2" placeholder="Voie, fréquence, précautions…" />
                            </FormField>

                            <fieldset>
                                <legend class="mb-1.5 text-xs font-semibold text-foreground">Après les soins</legend>
                                <div class="grid grid-cols-2 gap-1 rounded-lg border border-border bg-muted p-1" role="radiogroup">
                                    <button type="button" role="radio" :aria-checked="careOrderForm.requires_return_to_medicine === true" :class="['flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors', careOrderForm.requires_return_to_medicine === true ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground']" title="Le patient revient au médecin après les soins." @click="careOrderForm.requires_return_to_medicine = true"><ArrowLeft class="h-3.5 w-3.5" />Retour en Médecine</button>
                                    <button type="button" role="radio" :aria-checked="careOrderForm.requires_return_to_medicine === false" :class="['flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors', careOrderForm.requires_return_to_medicine === false ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground']" title="Les Soins terminent le parcours clinique prévu." @click="careOrderForm.requires_return_to_medicine = false"><CircleCheck class="h-3.5 w-3.5" />Fin aux Soins</button>
                                </div>
                                <FormError :message="careOrderForm.errors.requires_return_to_medicine" />
                            </fieldset>
                        </form>
                        <p v-else class="p-5 text-xs text-muted-foreground">Vous ne pouvez pas demander de soins depuis cette consultation.</p>

                        <aside class="border-t border-border bg-muted/30 p-4 lg:border-s lg:border-t-0" aria-label="Demandes transmises aux Soins">
                            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Demandes transmises</p>
                            <p v-if="!activeCareOrders.length" class="text-xs text-muted-foreground">Aucune demande en attente.</p>
                            <ul class="space-y-2">
                                <li v-for="order in activeCareOrders" :key="order.uuid" class="rounded-lg border border-border bg-card p-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-[11px] text-muted-foreground">{{ formatDateTime(order.ordered_at) }}</span>
                                        <span :class="careOrderStatusBadgeClass(order.status)">{{ careOrderStatusLabel(order.status) }}</span>
                                    </div>
                                    <ul class="mt-1.5 space-y-1">
                                        <li v-for="item in order.items" :key="item.uuid" class="flex items-center gap-2 text-xs">
                                            <span :class="['min-w-0 flex-1 truncate', item.cancelled_at ? 'text-muted-foreground line-through' : 'font-medium text-foreground']" :title="item.cancelled_at ? `Retiré par ${item.cancelled_by} le ${formatDateTime(item.cancelled_at)}` : item.name">{{ item.name }}</span>
                                            <span v-if="item.cancelled_at" class="shrink-0 text-[11px] text-muted-foreground">Retiré</span>
                                            <span v-else-if="item.not_performed_at" class="shrink-0 text-[11px] font-medium text-destructive">Non réalisé</span>
                                            <CircleCheck v-else-if="Number(item.remaining_quantity) <= 0" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-300" aria-label="Réalisé" />
                                            <span v-else class="shrink-0 text-[11px] tabular-nums text-muted-foreground">{{ Number(item.realized_quantity) }}/{{ Number(item.quantity) }}</span>
                                            <Button v-if="item.can_cancel" type="button" size="icon-xs" variant="ghost" class="shrink-0 text-muted-foreground hover:text-destructive" :title="`Retirer ${item.name}`" :aria-label="`Retirer ${item.name}`" @click="openCareOrderWithdrawal(item)"><Trash2 class="h-3.5 w-3.5" /></Button>
                                        </li>
                                    </ul>
                                    <p class="mt-1.5 text-[10px] text-muted-foreground">{{ order.requires_return_to_medicine ? 'Retour en Médecine' : 'Fin aux Soins' }}<template v-if="order.instructions"> · {{ order.instructions }}</template></p>
                                </li>
                            </ul>

                            <template v-if="pastCareOrders.length">
                                <button type="button" class="mt-3 flex w-full items-center justify-between rounded-md px-1 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground" :aria-expanded="careOrderHistoryOpen" @click="careOrderHistoryOpen = !careOrderHistoryOpen">
                                    <span class="flex items-center gap-1.5"><History class="h-3.5 w-3.5" />Historique ({{ pastCareOrders.length }})</span>
                                    <component :is="careOrderHistoryOpen ? ChevronUp : ChevronDown" class="h-3.5 w-3.5" />
                                </button>
                                <ul v-if="careOrderHistoryOpen" class="mt-1 space-y-1.5">
                                    <li v-for="order in pastCareOrders" :key="order.uuid" class="rounded-md border border-border bg-card px-2.5 py-2 text-xs">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-[11px] text-muted-foreground">{{ formatDateTime(order.ordered_at) }}</span>
                                            <span :class="careOrderStatusBadgeClass(order.status)">{{ careOrderStatusLabel(order.status) }}</span>
                                        </div>
                                        <p v-for="item in order.items" :key="item.uuid" :class="['mt-1 truncate', item.cancelled_at ? 'text-muted-foreground line-through' : 'text-foreground']" :title="item.cancelled_at ? `Retiré par ${item.cancelled_by} le ${formatDateTime(item.cancelled_at)}` : ''">{{ item.name }}<span v-if="item.not_performed_at" class="ms-1 text-destructive">· non réalisé</span></p>
                                    </li>
                                </ul>
                            </template>
                        </aside>
                    </div>
                </Card>

                <Card v-if="cardIsOpen('ordonnance') && prescriptionTab === 'medicines'" class="w-full overflow-hidden border-s-4 border-s-primary shadow-sm">
                    <!-- Repliée par défaut : ce qui est déjà prescrit se résume en
                         une ligne, et l'écran laisse la place à la nouvelle
                         ordonnance qui se prépare en dessous. -->
                    <div :class="['flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between', prescriptionListOpen ? 'border-b border-border' : '']">
                        <button
                            type="button"
                            class="flex min-w-0 items-center gap-2.5 text-start"
                            :aria-expanded="prescriptionListOpen"
                            @click="prescriptionListOpen = !prescriptionListOpen"
                        >
                            <component :is="prescriptionListOpen ? ChevronUp : ChevronDown" class="h-4 w-4 shrink-0 text-muted-foreground" />
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-foreground">Prescription — Médicaments</span>
                                <span class="mt-0.5 block truncate text-xs text-muted-foreground">
                                    <template v-if="prescribedLineCount">{{ prescribedLineCount }} médicament{{ prescribedLineCount > 1 ? 's' : '' }} prescrit{{ prescribedLineCount > 1 ? 's' : '' }} · </template>Le stock disponible exclut les lots périmés et les quantités déjà réservées.
                                </span>
                            </span>
                        </button>
                        <span v-if="capabilities.can_view_pharmacy_availability" class="inline-flex w-fit items-center gap-1.5 rounded border border-border bg-muted/35 px-2.5 py-1.5 text-xs font-semibold text-muted-foreground">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Stock Pharmacie en lecture seule
                        </span>
                    </div>
                    <div v-if="consultation.prescriptions.length && prescriptionListOpen" class="overflow-x-auto">
                        <table class="w-full min-w-[980px] text-sm">
                            <thead class="border-b border-border bg-muted/35">
                                <tr class="text-left text-[10px] uppercase tracking-wide text-muted-foreground">
                                    <th class="px-5 py-2.5 font-semibold">Médicament</th>
                                    <th class="px-4 py-2.5 font-semibold">Quantité</th>
                                    <th class="px-4 py-2.5 font-semibold">Dose</th>
                                    <th class="px-4 py-2.5 font-semibold">Voie</th>
                                    <th class="px-4 py-2.5 font-semibold">Fréquence</th>
                                    <th class="px-4 py-2.5 font-semibold">Durée</th>
                                    <th class="px-4 py-2.5 font-semibold">Enregistrée</th>
                                    <th class="px-5 py-2.5 text-end font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <template v-for="prescription in consultation.prescriptions" :key="prescription.uuid">
                                    <tr v-for="(line, lineIndex) in prescription.lines" :key="line.id" class="align-top hover:bg-muted/35">
                                        <td class="px-5 py-3">
                                            <span class="flex flex-wrap items-center gap-1.5">
                                                <span class="font-semibold text-foreground">{{ line.medication_name }}</span>
                                                <Badge v-if="line.is_manual_entry && line.catalog_review_status === 'PENDING'" tone="warning" class="px-2 py-0.5 text-[10px]"><CircleAlert class="h-3 w-3" aria-hidden="true" />Hors référentiel</Badge>
                                                <Badge v-else-if="line.is_manual_entry" tone="success" class="px-2 py-0.5 text-[10px]"><Check class="h-3 w-3" aria-hidden="true" />Traité par la Pharmacie</Badge>
                                            </span>
                                            <span v-if="line.instructions" class="mt-0.5 block text-xs text-muted-foreground">{{ line.instructions }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ line.quantity }} {{ line.unit || 'unité(s)' }}</td>
                                        <td class="px-4 py-3 text-muted-foreground">{{ line.dosage || '—' }}</td>
                                        <td class="px-4 py-3 text-muted-foreground">
                                            <span v-if="line.route_label">{{ line.route_label }}</span>
                                            <span v-else class="text-muted-foreground" title="La voie n’a pas été précisée sur cette ligne">Non précisée</span>
                                        </td>
                                        <td class="px-4 py-3 text-muted-foreground">{{ line.frequency || '—' }}</td>
                                        <td class="px-4 py-3 text-muted-foreground">{{ line.duration || '—' }}</td>
                                        <td v-if="lineIndex === 0" :rowspan="prescription.lines.length" class="whitespace-nowrap px-4 py-3 text-xs text-muted-foreground">{{ formatDateTime(prescription.prescribed_at) }}</td>
                                        <td v-if="lineIndex === 0" :rowspan="prescription.lines.length" class="px-5 py-3">
                                            <div class="flex justify-end gap-1.5">
                                                <Button :as="Link" :href="`/medicine/orientations/${orientation.uuid}/prescriptions/${prescription.uuid}/print`" target="_blank" size="sm" icon variant="white-outline" title="Imprimer l’ordonnance" aria-label="Imprimer l’ordonnance"><Printer class="h-4 w-4" /></Button>
                                                <Button v-if="prescription.can_edit" type="button" size="sm" icon variant="white-outline" title="Modifier l’ordonnance" aria-label="Modifier l’ordonnance" @click="startPrescriptionEdit(prescription)"><Pencil class="h-4 w-4" /></Button>
                                                <Button v-if="prescription.can_remove" type="button" size="sm" icon variant="danger-outline" title="Retirer l’ordonnance" aria-label="Retirer l’ordonnance" @click="openPrescriptionRemoval(prescription)"><Trash2 class="h-4 w-4" /></Button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="editingPrescriptionUuid === prescription.uuid" class="bg-muted/35">
                                        <td colspan="8" class="px-5 py-4">
                                            <form class="space-y-3" @submit.prevent="updatePrescription(prescription.uuid)">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <div><p class="text-xs font-bold text-foreground">Modifier l’ordonnance</p><p class="mt-0.5 text-[11px] text-muted-foreground">Le médicament reste inchangé ; quantité et posologie peuvent être corrigées.</p></div>
                                                    <Button type="button" icon size="sm" variant="ghost" aria-label="Fermer la modification" @click="closePrescriptionEdit"><X class="h-4 w-4" aria-hidden="true" /></Button>
                                                </div>
                                                <div v-for="(editLine, editIndex) in prescriptionEditForm.lines" :key="editLine.id" class="grid gap-3 rounded border border-border bg-card p-3 md:grid-cols-12">
                                                    <FormField v-if="editLine.is_manual_entry" class="md:col-span-3" label="Médicament" hint="· hors référentiel" :for="`edit-name-${editLine.id}`"><Input :id="`edit-name-${editLine.id}`" v-model="editLine.medication_name" /></FormField>
                                                    <div v-else class="md:col-span-3"><p class="text-xs font-semibold text-foreground">{{ editLine.medication_name }}</p><p class="mt-1 text-[11px] text-muted-foreground">Stock mobilisable : {{ editLine.available_quantity }} {{ editLine.unit || 'unité(s)' }}</p></div>
                                                    <FormField class="md:col-span-2" label="Quantité" required :error="prescriptionEditForm.errors[`lines.${editIndex}.quantity`]"><Input :id="`edit-quantity-${editLine.id}`" v-model.number="editLine.quantity" type="number" min="1" :max="editLine.is_manual_entry ? undefined : editLine.available_quantity" :disabled="!editLine.stock_linked && !editLine.is_manual_entry" /></FormField>
                                                    <FormField class="md:col-span-2" label="Dose" required :error="prescriptionEditForm.errors[`lines.${editIndex}.dosage`]"><Input :id="`edit-dosage-${editLine.id}`" v-model="editLine.dosage" placeholder="Ex. 500 mg" /></FormField>
                                                    <FormField class="md:col-span-2" label="Fréquence" required :error="prescriptionEditForm.errors[`lines.${editIndex}.frequency`]"><Input :id="`edit-frequency-${editLine.id}`" v-model="editLine.frequency" placeholder="Ex. 3×/jour" /></FormField>
                                                    <FormField class="md:col-span-3" label="Durée" hint="· facultatif"><Input :id="`edit-duration-${editLine.id}`" v-model="editLine.duration" placeholder="Ex. 7 jours" /></FormField>
                                                    <FormField class="md:col-span-12" label="Instructions" hint="· facultatif"><Input :id="`edit-instructions-${editLine.id}`" v-model="editLine.instructions" placeholder="Voie, moment de prise ou précaution" /></FormField>
                                                </div>
                                                <FormError :message="prescriptionEditForm.errors.lines || prescriptionEditForm.errors.prescription" />
                                                <div class="flex justify-end gap-2"><Button type="button" size="rg" variant="white-outline" :disabled="prescriptionEditForm.processing" @click="closePrescriptionEdit"><X class="h-4 w-4 me-1.5" />Fermer</Button><Button type="submit" size="rg" :disabled="prescriptionEditForm.processing || !prescriptionEditStockIsValid"><Save class="h-4 w-4 me-1.5" />Enregistrer</Button></div>
                                            </form>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p v-else-if="!consultation.prescriptions.length" class="px-5 py-6 text-sm text-muted-foreground">Aucune ordonnance active.</p>

                    <form v-if="capabilities.can_create_prescription" id="medicine-prescription-form" class="border-t border-border bg-muted/35 p-5" @submit.prevent="openPrescriptionConfirmation">
                        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-sm font-bold text-foreground">Nouvelle ordonnance</h3>
                                <p class="mt-1 text-xs leading-5 text-muted-foreground">Sélectionnez uniquement un médicament réellement disponible. La validation réserve la quantité ; la délivrance reste à la Pharmacie.</p>
                            </div>
                            <Badge v-if="prescriptionForm.lines.length" tone="primary" class="shrink-0">
                                <Pill class="h-3.5 w-3.5" aria-hidden="true" />{{ prescriptionForm.lines.length }} ligne{{ prescriptionForm.lines.length > 1 ? 's' : '' }}
                            </Badge>
                        </div>

                        <ClinicalPrescriptionSuggestions
                            v-if="suggestions"
                            class="mb-4"
                            :prescription="suggestions.prescription"
                            :selected-uuids="selectedMedicineUuids"
                            :protocol-count="suggestions.protocol_count"
                            :practice-cases="suggestions.practice_cases"
                            :practice-min-cases="suggestions.practice_min_cases"
                            :has-diagnosis="activeDiagnoses.length > 0"
                            :can-manage-protocols="suggestions.can_manage_protocols"
                            :routes="options.administration_routes"
                            @add="addSuggestedPrescriptionLine"
                        />

                        <!-- La séparation se déplace selon le moment : large catalogue pour
                             chercher, large ordonnance pour régler les posologies. Le choix
                             est retrouvé à la visite suivante ; en dessous de la largeur utile,
                             les deux panneaux s'empilent. -->
                        <ResizableSplit
                            storage-key="rivo:medicine:prescription-split"
                            :default-ratio="0.45"
                            :min-ratio="0.3"
                            :max-ratio="0.65"
                            start-label="panneau Médicaments disponibles"
                            end-label="panneau Prescription en préparation"
                        >
                            <template #start>
                            <section class="flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm" aria-labelledby="medicine-catalog-title">
                                <div class="border-b border-border p-3">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <label id="medicine-catalog-title" for="medicine-search" class="block text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Médicaments disponibles</label>
                                        <!-- ADR-037 — la ligne manuelle n'est pas un recours
                                             caché : elle est proposée là où le médecin
                                             constate que le produit manque. -->
                                        <Button type="button" size="xs" variant="ghost" class="shrink-0 text-primary hover:text-primary" @click="addManualPrescriptionLine">
                                            <Plus class="h-3.5 w-3.5" aria-hidden="true" />Médicament introuvable ?
                                        </Button>
                                    </div>
                                    <IconInput id="medicine-search" v-model="medicineSearch" :icon="Search" placeholder="Nom, DCI, dosage ou code…" autocomplete="off" />
                                </div>

                                <div v-if="filteredMedicines.length" class="max-h-[430px] flex-1 divide-y divide-border overflow-y-auto">
                                    <button
                                        v-for="medicine in filteredMedicines"
                                        :key="medicine.uuid"
                                        type="button"
                                        :disabled="!medicine.available || selectedMedicineUuids.has(medicine.uuid)"
                                        class="flex w-full items-center gap-3 px-4 py-3 text-start transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none disabled:cursor-not-allowed disabled:bg-muted/40 disabled:hover:bg-muted/40"
                                        @click="addPrescriptionMedicine(medicine)"
                                    >
                                        <!-- Trois états, jamais deux : disponible, déjà
                                             retenu, épuisé. Confondus, le médecin relisait
                                             « épuisé » sur un produit qu'il venait lui-même
                                             d'ajouter. -->
                                        <span
                                            v-if="selectedMedicineUuids.has(medicine.uuid)"
                                            class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-600 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300"
                                        ><Check class="h-4 w-4" aria-hidden="true" /></span>
                                        <span
                                            v-else-if="medicine.available"
                                            class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-border bg-card text-primary"
                                        ><Plus class="h-4 w-4" aria-hidden="true" /></span>
                                        <span
                                            v-else
                                            class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-destructive/20 bg-destructive/10 text-destructive"
                                        ><X class="h-4 w-4" aria-hidden="true" /></span>

                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <strong class="truncate text-sm font-semibold text-foreground">{{ medicine.name }}</strong>
                                                <span v-if="medicine.strength" class="text-xs text-muted-foreground">{{ medicine.strength }}</span>
                                            </span>
                                            <span class="mt-0.5 block truncate text-xs text-muted-foreground">{{ medicine.generic_name || medicine.form_label }} · {{ medicine.code }}</span>
                                        </span>

                                        <span class="flex shrink-0 flex-col items-end gap-1">
                                            <strong :class="['text-sm tabular-nums', medicine.available ? 'text-foreground' : 'text-destructive']">{{ medicine.available_quantity }} {{ medicine.unit }}</strong>
                                            <Badge v-if="selectedMedicineUuids.has(medicine.uuid)" tone="success" class="px-2 py-0.5 text-[10px]">Dans l’ordonnance</Badge>
                                            <Badge v-else-if="!medicine.available" tone="danger" class="px-2 py-0.5 text-[10px]">Épuisé</Badge>
                                            <Badge v-else-if="medicine.expiring_soon && medicine.nearest_expiration" tone="warning" class="px-2 py-0.5 text-[10px]">Péremption {{ formatDate(medicine.nearest_expiration) }}</Badge>
                                            <span v-else-if="medicine.nearest_expiration" class="text-[11px] text-muted-foreground">Péremption {{ formatDate(medicine.nearest_expiration) }}</span>
                                        </span>
                                    </button>
                                </div>
                                <div v-else class="flex flex-1 flex-col items-center justify-center px-6 py-12 text-center">
                                    <span class="grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><Search class="h-5 w-5" aria-hidden="true" /></span>
                                    <p class="mt-3 text-sm font-semibold text-foreground">Aucun médicament trouvé</p>
                                    <p class="mt-1 max-w-xs text-xs leading-5 text-muted-foreground">Vérifiez la recherche ou le stock Pharmacie. Un produit absent du référentiel s’ajoute avec « Médicament introuvable ? ».</p>
                                </div>
                            </section>
                            </template>
                            <template #end>
                            <section class="flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm" aria-labelledby="prescription-selection-title">
                                <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                                    <div class="min-w-0">
                                        <h4 id="prescription-selection-title" class="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Prescription en préparation</h4>
                                        <p class="mt-0.5 text-xs text-muted-foreground">{{ prescriptionForm.lines.length }} médicament{{ prescriptionForm.lines.length > 1 ? 's' : '' }}</p>
                                    </div>
                                    <FileText class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                </div>

                                <div v-if="prescriptionForm.lines.length" class="max-h-[560px] flex-1 space-y-3 overflow-y-auto p-3">
                                    <article v-for="(line, index) in prescriptionForm.lines" :key="line._key" class="rounded-xl border border-border bg-card p-3 shadow-sm transition-shadow focus-within:shadow-md">
                                        <header class="mb-3 flex items-start justify-between gap-3">
                                            <div v-if="line.manual" class="min-w-0 flex-1">
                                                <label :for="`manual-name-${index}`" class="mb-1.5 flex h-6 items-center gap-1.5 text-sm font-medium text-foreground">
                                                    <Badge tone="warning" class="px-2 py-0.5 text-[10px]"><CircleAlert class="h-3 w-3" aria-hidden="true" />Hors référentiel</Badge>
                                                </label>
                                                <Input :id="`manual-name-${index}`" v-model="line.medication_name" placeholder="Nom du médicament" />
                                            </div>
                                            <div v-else class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-bold text-foreground">{{ medicineForLine(line)?.name }}</p>
                                                <p class="mt-0.5 truncate text-xs text-muted-foreground">{{ medicineForLine(line)?.form_label }}<template v-if="medicineForLine(line)?.strength"> · {{ medicineForLine(line)?.strength }}</template></p>
                                                <!-- L'origine reste visible jusqu'à la validation : le
                                                     médecin sait ce qu'il relit, et l'ajuste en
                                                     connaissance de cause. -->
                                                <Badge v-if="line.suggestion_source" tone="info" class="mt-1.5 px-2 py-0.5 text-[10px]">
                                                    <BookMarked class="h-3 w-3" aria-hidden="true" />Proposé · {{ line.suggestion_label }}
                                                </Badge>
                                            </div>
                                            <Button
                                                type="button"
                                                icon
                                                variant="ghost"
                                                size="sm"
                                                class="shrink-0 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                                :title="`Retirer ${line.manual ? (line.medication_name || 'cette ligne') : (medicineForLine(line)?.name ?? 'cette ligne')}`"
                                                @click="removePrescriptionLine(index)"
                                            >
                                                <Trash2 class="h-4 w-4" aria-hidden="true" />
                                                <span class="sr-only">Retirer de l’ordonnance</span>
                                            </Button>
                                        </header>

                                        <PrescriptionLineEditor
                                            :line="line"
                                            :routes="options.administration_routes"
                                            :quantity-unit="line.manual ? 'unité(s)' : (medicineForLine(line)?.unit ?? 'unité(s)')"
                                            :medicine-form="line.manual ? null : medicineForLine(line)?.form"
                                            :error-for="(field) => prescriptionForm.errors[`lines.${index}.${field}`]"
                                            @update="updatePrescriptionLine(index, $event)"
                                        />

                                        <!-- Ce que la Pharmacie tient encore, et ce que la
                                             ligne engage : lu ici, jamais saisi (ADR-036). -->
                                        <p v-if="!line.manual" class="mt-2.5 text-[11px] text-muted-foreground">Disponible en Pharmacie : <span class="font-semibold text-foreground tabular-nums">{{ medicineForLine(line)?.available_quantity }} {{ medicineForLine(line)?.unit }}</span></p>
                                        <p v-else class="mt-2.5 text-[11px] text-muted-foreground">Sans lien de stock — non suivi par la Pharmacie.</p>

                                        <p v-if="line.manual" class="mt-2 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2 text-[11px] leading-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                                            <Info class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />Sera transmis pour validation avant d’entrer en stock Pharmacie. Aucun prix n’est demandé ici.
                                        </p>
                                        <p v-else-if="Number(line.quantity) > medicineForLine(line)?.available_quantity" class="mt-2 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 px-2.5 py-2 text-[11px] font-semibold leading-4 text-destructive">
                                            <Info class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />Quantité supérieure au stock disponible. L’ordonnance sera bloquée.
                                        </p>
                                        <FormError :message="prescriptionForm.errors[`lines.${index}.medicine_uuid`] || prescriptionForm.errors[`lines.${index}.medication_name`]" />
                                    </article>
                                </div>
                                <div v-else class="flex min-h-48 flex-1 flex-col items-center justify-center px-6 py-12 text-center">
                                    <span class="grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><FileText class="h-5 w-5" aria-hidden="true" /></span>
                                    <p class="mt-3 text-sm font-semibold text-foreground">Ordonnance vide</p>
                                    <p class="mt-1 max-w-xs text-xs leading-5 text-muted-foreground">Choisissez un médicament dans la liste. Les produits épuisés ne peuvent pas être ajoutés.</p>
                                </div>
                            </section>
                            </template>
                        </ResizableSplit>

                        <div class="mt-4 flex items-start gap-2 border-t border-border pt-4 text-xs leading-5 text-muted-foreground">
                            <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                            <p>La validation contrôle à nouveau le stock et réserve les quantités disponibles. La délivrance reste à la Pharmacie.</p>
                        </div>
                        <FormError :message="prescriptionForm.errors.lines" />
                    </form>
                    <div v-else-if="!capabilities.can_view_pharmacy_availability" class="border-t border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">Votre compte ne dispose pas du droit de consulter la disponibilité Pharmacie. Aucune ordonnance ne peut être créée depuis cet écran.</div>
                </Card>

                <!-- §18 — la Clôture ne demande plus « quelle est la
                     décision ? » : elle vérifie ce qui a été fait, signale ce
                     qui manque, et valide. La conduite à tenir a déjà été
                     décidée là où le médecin la connaissait. -->
                <!-- §18 / §19 — la Clôture vérifie et valide. Elle ne
                     redemande rien : l'orientation, le diagnostic et les
                     demandes ont été saisis là où ils se décidaient, et les
                     réafficher en toutes lettres ne ferait que recopier
                     l'écran précédent. Ce qu'elle montre, c'est ce qui reste
                     à faire — et comment y retourner. -->
                <Card v-if="cardIsOpen('cloture')" class="w-full overflow-hidden border-s-4 border-s-primary shadow-sm">
                    <!-- Un fait dérivé, pas un statut inventé, et surtout
                         **pas un blocage** : un résultat attendu n'empêche
                         jamais de clôturer (ADR-105) — le médecin peut avoir
                         conclu sans lui. En ambre, au-dessus de l'étape de
                         clôture, ce bandeau se lisait pourtant « vous ne
                         pouvez pas conclure » : c'est ce que le propriétaire
                         a constaté le 2026-09-17. Il est donc neutre, et il
                         dit ce qu'il est — une information. -->
                    <div v-if="awaitingResults.length" class="flex flex-wrap items-start gap-3 border-b border-border bg-muted/40 px-5 py-3">
                        <Clock class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-foreground">
                                {{ awaitingResults.length }} résultat{{ awaitingResults.length > 1 ? 's' : '' }} encore attendu{{ awaitingResults.length > 1 ? 's' : '' }} — cela n’empêche pas de clôturer
                            </p>
                            <p class="mt-0.5 text-xs leading-5 text-muted-foreground">
                                {{ awaitingResults.join(' · ') }}. Vous pouvez conclure maintenant, ou attendre : la consultation se retrouve depuis « Demandes d’examens » dès qu’un résultat arrive.
                            </p>
                        </div>
                        <Button :as="Link" href="/medicine/demandes-examens" size="sm" variant="white-outline">
                            <FileSearch class="me-1.5 h-4 w-4" aria-hidden="true" />Suivre les demandes
                        </Button>
                    </div>
                    <div class="border-b border-border px-5 py-4">
                        <h2 class="text-sm font-bold text-foreground">Décision & clôture</h2>
                        <p class="mt-1 text-xs text-muted-foreground">La clôture ne réalise aucun encaissement et ne ferme pas le passage administratif.</p>
                    </div>

                    <div class="p-5">
                        <!-- ADR-089 — un seul endroit pour conclure le passage :
                             diagnostic, conduite à tenir et vérification. Les
                             trois se parcourent un par un plutôt qu'empilés,
                             mais restent atteignables d'un clic : revenir en
                             arrière ne doit jamais coûter un défilement. -->
                        <nav class="mb-4 flex flex-wrap items-center gap-1.5" aria-label="Étapes de la clôture">
                            <button
                                v-for="section in closureSections"
                                :key="section.step"
                                type="button"
                                :aria-current="closureSubStep === section.step ? 'step' : undefined"
                                :class="['inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                                    closureSubStep === section.step
                                        ? 'border-primary/30 bg-primary/10 text-primary'
                                        : 'border-border bg-card text-muted-foreground hover:bg-accent hover:text-foreground']"
                                @click="closureSubStep = section.step"
                            >
                                <CircleCheck v-if="section.done" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                                <span v-else class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-current text-[9px] tabular-nums" aria-hidden="true">{{ section.step }}</span>
                                {{ section.label }}
                            </button>
                        </nav>

                        <section v-show="closureSubStep === 1" class="rounded-lg border border-border p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">1 · Diagnostic</h3>
                                <span v-if="activeDiagnoses.length" class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300"><CircleCheck class="h-4 w-4" />{{ activeDiagnoses.length }} enregistré{{ activeDiagnoses.length > 1 ? 's' : '' }}</span>
                            </div>
                            <!-- ADR-081 — correction et retrait vivent dans la
                                 carte Diagnostic, que l'ADR-089 a déplacée
                                 ici. Les endpoints existaient, l'écran ne les
                                 appelait plus : une faute de frappe restait
                                 dans le dossier sans rien pour la rectifier. -->
                            <ClinicalDiagnosisList
                                v-if="activeDiagnoses.length"
                                class="mt-3"
                                :orientation-uuid="orientation.uuid"
                                :diagnoses="activeDiagnoses"
                                return-step="cloture"
                            />
                            <p v-else class="mt-1 text-[11px] leading-4 text-muted-foreground">Aucun diagnostic encore posé : consignez la conclusion clinique de ce passage.</p>

                            <ClinicalDiagnosisSuggestions
                                v-if="suggestions && capabilities.can_create_diagnosis"
                                class="mt-3"
                                :suggestions="suggestions.diagnoses"
                                :orientation-uuid="orientation.uuid"
                                return-step="cloture"
                                :protocol-count="suggestions.protocol_count"
                                :practice-cases="suggestions.practice_cases"
                                :practice-min-cases="suggestions.practice_min_cases"
                                :can-manage-protocols="suggestions.can_manage_protocols"
                            />

                            <!-- ADR-095 — la question est posée ici, la seule
                                 étape que tout patient atteint : un passage
                                 venu pour une écho n'a pas d'examen clinique
                                 où elle aurait pu l'être (ADR-076).
                                 « Pas maintenant » ne clôture rien : elle dit
                                 pourquoi la consultation reste ouverte. -->
                            <div class="mt-3 flex flex-wrap items-center gap-2.5 border-t border-border pt-3">
                                <span class="text-xs font-semibold text-muted-foreground">Le diagnostic peut-il être posé maintenant ?</span>
                                <span class="inline-flex rounded border border-border bg-card p-0.5" role="radiogroup" aria-label="Le diagnostic peut-il être posé maintenant ?">
                                    <button
                                        type="button"
                                        role="radio"
                                        :aria-checked="diagnosisReady === false"
                                        :disabled="!capabilities.can_update_consultation || diagnosisTimingForm.processing"
                                        :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', diagnosisReady === false ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/35']"
                                        @click="decideDiagnosisTiming(false)"
                                    >Pas maintenant</button>
                                    <button
                                        type="button"
                                        role="radio"
                                        :aria-checked="diagnosisReady === true"
                                        :disabled="!capabilities.can_update_consultation || diagnosisTimingForm.processing"
                                        :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', diagnosisReady === true ? 'bg-primary text-white' : 'text-muted-foreground hover:bg-muted/35']"
                                        @click="decideDiagnosisTiming(true)"
                                    >Oui</button>
                                </span>
                            </div>

                            <p v-if="diagnosisReady === false && ! diagnosisEntryOpen" class="mt-2 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                <Clock class="h-4 w-4 shrink-0" />Diagnostic différé : la consultation reste ouverte, vous pourrez le poser en revenant sur ce passage.
                            </p>
                            <p v-else-if="diagnosisEntryOpen && ! activeDiagnoses.length" class="mt-2 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                <CirclePlus class="h-4 w-4 shrink-0" />Enregistrez la conclusion ci-dessous : la réponse « Oui » sera prise en compte une fois le diagnostic consigné.
                            </p>
                            <FormError class="mt-1" :message="diagnosisTimingForm.errors.ready" />

                            <!-- « Pas maintenant » veut dire pas maintenant :
                                 laisser le champ ouvert contredirait la réponse
                                 que le médecin vient de donner. Il reste à un
                                 clic — « Oui » le rouvre. -->
                            <div v-if="diagnosisReady !== false || diagnosisEntryOpen" class="mt-3">
                                <ClinicalDiagnosisEntry
                                    :orientation-uuid="orientation.uuid"
                                    return-step="cloture"
                                    :disabled="!capabilities.can_create_diagnosis"
                                    compact
                                />
                            </div>
                        </section>

                        <section v-show="closureSubStep === 2">
                            <h3 class="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">2 · Conduite à tenir</h3>
                            <ClinicalOrientationCard
                                :orientation-uuid="orientation.uuid"
                                :draft="draft"
                                :state="consultation_orientation ?? {}"
                                :types="options.orientation_types ?? []"
                                :priorities="options.clinical_priorities ?? []"
                                :surgery-catalog="options.surgery_catalog ?? []"
                                :transfer-destinations="otherSiteOptions"
                                :is-emergency="isEmergency"
                                return-step="cloture"
                                :disabled="!capabilities.can_update_consultation"
                            >
                                <template #discharge>
                                    <ClinicalDischargeForm
                                        v-if="!medical_discharge"
                                        :form="dischargeForm"
                                        :types="options.discharge_types ?? []"
                                        :diagnoses="activeDiagnoses"
                                        :prescription-lines="activePrescriptionLines"
                                        :site-options="otherSiteOptions"
                                        :requires-diagnosis="requiresFinalDiagnosis"
                                        :disabled="!capabilities.can_discharge"
                                        :cancellable="false"
                                        @submit="submitDischarge"
                                    />
                                    <p v-else class="text-[11px] text-emerald-700 dark:text-emerald-300">Sortie médicale déjà prononcée — son détail figure ci-dessous.</p>
                                </template>
                            </ClinicalOrientationCard>
                        </section>

                        <section v-show="closureSubStep === 3">
                        <h3 class="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">3 · Vérification</h3>
                        <!-- Ce qui manque est dit ici, avec le chemin pour y
                             revenir — jamais un bouton grisé sans explication. -->
                        <div v-if="closureBlockers.length" class="mt-3 rounded-md border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-900 dark:bg-amber-950/20">
                            <p class="mb-1.5 flex items-center gap-2 text-xs font-bold text-amber-800 dark:text-amber-200">
                                <CircleAlert class="h-4 w-4" />À compléter avant la clôture
                            </p>
                            <ul class="space-y-1 ps-6 text-[11px] text-amber-800 dark:text-amber-200">
                                <li v-for="(blocker, index) in closureBlockers" :key="index" class="list-disc">
                                    {{ blocker.message }}
                                    <!-- ADR-084 : « ce qui manque encore *et
                                         le chemin pour y retourner* ». Sans
                                         ce lien, l'écran énonçait l'obstacle
                                         et laissait le médecin le chercher. -->
                                    <Link
                                        v-if="blocker.step"
                                        :href="stepUrl(blocker.step)"
                                        class="ms-1 font-bold underline underline-offset-2 hover:no-underline"
                                    >Y aller</Link>
                                    <!-- « Déjà sur place » ne disait pas où :
                                         le diagnostic est à la sous-étape 1, la
                                         conduite à tenir à la 2, et on lit cette
                                         liste depuis la 3. -->
                                    <button
                                        v-else-if="blocker.closure_section"
                                        type="button"
                                        class="ms-1 font-bold underline underline-offset-2 hover:no-underline"
                                        @click="closureSubStep = blocker.closure_section"
                                    >Y aller</button>
                                </li>
                            </ul>
                        </div>

                        <p v-else class="mt-3 flex items-center gap-2 text-[11px] text-emerald-700 dark:text-emerald-300">
                            <CircleCheck class="h-4 w-4" />Tout est en place : la consultation peut être clôturée.
                        </p>
                        </section>

                    </div>

                    <div v-if="medical_discharge" class="p-5">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded border border-border bg-muted/35 px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><CircleCheck class="h-4 w-4" /></span>
                                <div>
                                    <span class="rounded bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">{{ medical_discharge.type_label }}</span>
                                    <p class="mt-1.5 text-xs text-muted-foreground">Décidée le {{ formatDateTime(medical_discharge.discharged_at) }} par {{ medical_discharge.created_by }}</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-300"><CircleAlert class="h-4 w-4" />Passage administratif toujours ouvert</span>
                        </div>

                        <div class="overflow-x-auto rounded border border-border">
                            <table class="w-full min-w-[520px] border-collapse text-sm">
                                <caption class="sr-only">Détails de la sortie médicale</caption>
                                <tbody class="divide-y divide-border">
                                    <tr>
                                        <th scope="row" class="w-48 shrink-0 bg-muted/35 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:w-56">Diagnostic final</th>
                                        <td class="px-4 py-3 align-top font-semibold text-foreground">{{ medical_discharge.final_diagnosis }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="w-48 shrink-0 bg-muted/35 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:w-56">État à la sortie</th>
                                        <td class="px-4 py-3 align-top text-muted-foreground">{{ medical_discharge.patient_condition }}</td>
                                    </tr>
                                    <tr v-if="medical_discharge.discharge_prescription">
                                        <th scope="row" class="w-48 shrink-0 bg-muted/35 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:w-56">Traitement de sortie</th>
                                        <td class="px-4 py-3 align-top text-muted-foreground">
                                            <ul v-if="splitLines(medical_discharge.discharge_prescription).length > 1" class="list-disc space-y-1 ps-4">
                                                <li v-for="(line, index) in splitLines(medical_discharge.discharge_prescription)" :key="index">{{ line }}</li>
                                            </ul>
                                            <template v-else>{{ medical_discharge.discharge_prescription }}</template>
                                        </td>
                                    </tr>
                                    <tr v-if="medical_discharge.recommendations">
                                        <th scope="row" class="w-48 shrink-0 bg-muted/35 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:w-56">Recommandations</th>
                                        <td class="px-4 py-3 align-top text-muted-foreground">
                                            <ul v-if="splitLines(medical_discharge.recommendations).length > 1" class="list-disc space-y-1 ps-4">
                                                <li v-for="(line, index) in splitLines(medical_discharge.recommendations)" :key="index">{{ line }}</li>
                                            </ul>
                                            <template v-else>{{ medical_discharge.recommendations }}</template>
                                        </td>
                                    </tr>
                                    <tr v-if="medical_discharge.follow_up_at">
                                        <th scope="row" class="w-48 shrink-0 bg-muted/35 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:w-56">Rendez-vous</th>
                                        <td class="px-4 py-3 align-top text-muted-foreground">{{ formatDateTime(medical_discharge.follow_up_at) }}</td>
                                    </tr>
                                    <tr v-if="medical_discharge.transfer_destination">
                                        <th scope="row" class="w-48 shrink-0 bg-muted/35 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:w-56">Destination</th>
                                        <td class="px-4 py-3 align-top text-muted-foreground">{{ medical_discharge.transfer_destination }}</td>
                                    </tr>
                                    <tr v-if="medical_discharge.type === 'DECEASED'">
                                        <th scope="row" class="w-48 shrink-0 bg-muted/35 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:w-56">Décès</th>
                                        <td class="px-4 py-3 align-top text-muted-foreground">{{ formatDateTime(medical_discharge.death_occurred_at) }}<template v-if="medical_discharge.death_place"> · {{ medical_discharge.death_place }}</template></td>
                                    </tr>
                                    <tr v-if="medical_discharge.death_causes">
                                        <th scope="row" class="w-48 shrink-0 bg-muted/35 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:w-56">Causes constatées</th>
                                        <td class="px-4 py-3 align-top text-muted-foreground">{{ medical_discharge.death_causes }}</td>
                                    </tr>
                                    <tr v-if="medical_discharge.observations">
                                        <th scope="row" class="w-48 shrink-0 bg-muted/35 px-4 py-3 text-start align-top text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:w-56">Observations</th>
                                        <td class="px-4 py-3 align-top text-muted-foreground">{{ medical_discharge.observations }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="consultation.prescriptions.length" class="mt-5">
                            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">Ordonnances à remettre au patient</h3>
                            <ul class="divide-y divide-border rounded border border-border">
                                <li v-for="prescription in consultation.prescriptions" :key="prescription.uuid" class="px-4 py-3">
                                    <p class="truncate text-sm font-semibold text-foreground">{{ prescription.lines.map((line) => line.medication_name).join(', ') }}</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">Patient {{ patient.patient_number }} · Passage {{ episode.episode_number }} · {{ formatDateTime(prescription.prescribed_at) }}</p>
                                </li>
                            </ul>
                            <p class="mt-2 text-xs text-muted-foreground">Utilisez « Imprimer l’ordonnance » en bas de page pour l’impression.</p>
                        </div>
                    </div>

                </Card>

                <Card v-if="current_step === 'ordonnance'" class="sticky bottom-3 z-20 overflow-hidden border-border shadow-lg">
                    <ConsultationStepBar
                        :save-state="{ saving: draft.saving.value, savedAt: draft.savedAt.value }"
                        :floating="false"
                        class="border-t-0 bg-card"
                        :orientation-uuid="orientation.uuid"
                        :step-key="current_step"
                        :state="currentStepState"
                        :previous="previousStep ? { key: previousStep.key, label: previousStep.label } : null"
                        :next="nextStep ? { key: nextStep.key, label: nextStep.label } : null"
                        :can-edit="capabilities.can_resolve_step"
                        :local-blocker="pendingPrescriptionSelection"
                        @blocked="toast.warning($event)"
                    >
                        <!-- Aucune consigne inline : ce qui bloque se dit au
                             moment où l'on clique, dans un toast. -->
                        <template v-if="current_step === 'ordonnance'" #note>
                            <p class="text-center text-xs text-muted-foreground">Prescription selon indication médicale.</p>
                        </template>
                        <template v-if="current_step === 'ordonnance'" #actions>
                            <Button v-if="prescriptionTab === 'medicines' && prescriptionForm.lines.length" type="button" size="rg" :disabled="prescriptionForm.processing || !prescriptionStockIsValid" @click="openPrescriptionConfirmation"><FileText class="mx-auto h-4 w-4 me-2" />{{ prescriptionForm.processing ? 'Validation en cours…' : 'Valider et réserver' }}</Button>
                            <Button v-else-if="prescriptionForm.lines.length" type="button" size="rg" variant="white-outline" @click="prescriptionTab = 'medicines'">Finaliser la prescription ({{ prescriptionForm.lines.length }})</Button>
                            <Button v-else-if="prescriptionTab === 'care' && careOrderForm.items.length" type="button" size="rg" :disabled="careOrderForm.processing" @click="openCareOrderConfirmation"><Activity class="h-4 w-4 me-2" />{{ careOrderForm.processing ? 'Transmission en cours…' : 'Transmettre aux Soins' }}</Button>
                            <Button v-else-if="careOrderForm.items.length" type="button" size="rg" variant="white-outline" @click="prescriptionTab = 'care'">Finaliser la demande Soins ({{ careOrderForm.items.length }})</Button>
                        </template>
                    </ConsultationStepBar>
                </Card>

                <!-- La clôture est un acte à part : elle verrouille la
                     consultation en lecture seule (ADR-010) et exige que
                     chaque étape concernée soit résolue — validée ou
                     déclarée non nécessaire. -->
                <Card v-else-if="current_step === 'cloture'" class="sticky bottom-3 z-20 overflow-hidden border-border shadow-lg">
                    <div class="flex flex-col gap-3 bg-card px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <Button v-if="previousStep" :as="Link" :href="stepUrl(previousStep.key)" size="rg" variant="white-outline"><ArrowLeft class="h-4 w-4 me-2" />{{ previousStep.label }}</Button>
                        <Button v-else :as="Link" href="/medicine" size="rg" variant="white-outline"><ArrowLeft class="h-4 w-4 me-2" />Retour à la file</Button>

                        <div class="min-w-0 flex-1 text-center">
                            <FormError :message="completeConsultationForm.errors.consultation" />
                            <p v-if="consultationIsClosed" class="flex items-center justify-center gap-1.5 text-xs font-semibold text-emerald-600 dark:text-emerald-300">
                                <CircleCheck class="mx-auto h-4 w-4" />Consultation clôturée<template v-if="consultation.completed_by"> par {{ consultation.completed_by }}</template>
                            </p>
                            <ul v-else-if="closureBlockers.length" class="space-y-0.5 text-xs text-muted-foreground">
                                <li v-for="(blocker, index) in closureBlockers" :key="index">
                                    {{ blocker.message }}
                                    <Link
                                        v-if="blocker.step"
                                        :href="stepUrl(blocker.step)"
                                        class="ms-1 font-bold text-primary underline underline-offset-2 hover:no-underline"
                                    >Y aller</Link>
                                </li>
                            </ul>
                            <p v-else class="text-xs text-muted-foreground">Toutes les étapes sont résolues : la consultation peut être clôturée.</p>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <Button v-for="prescription in consultation.prescriptions" :key="prescription.uuid" :as="Link" :href="`/medicine/orientations/${orientation.uuid}/prescriptions/${prescription.uuid}/print`" target="_blank" size="rg" variant="white-outline"><Printer class="h-4 w-4 me-2" />Imprimer l’ordonnance</Button>

                            <!-- ADR-096 — un résultat arrivé après la clôture
                                 doit pouvoir être consigné. Rouvrir ne défait
                                 que la clôture : aucune donnée clinique n'est
                                 supprimée, et le motif part à l'audit. -->
                            <Button
                                v-if="capabilities.can_reopen_consultation"
                                type="button"
                                size="rg"
                                variant="warning-outline"
                                @click="reopenOpen = true"
                            >
                                <RotateCcw class="h-4 w-4 me-2" />Rouvrir la consultation
                            </Button>
                            <Button
                                v-if="capabilities.can_complete_consultation"
                                type="button"
                                size="rg"
                                variant="success"
                                :disabled="closureBlockers.length > 0 || completeConsultationForm.processing"
                                @click="completeConsultation"
                            >
                                <CircleCheck class="h-4 w-4 me-2" />{{ completeConsultationForm.processing ? 'Clôture…' : 'Clôturer la consultation' }}
                            </Button>
                            <Button v-else :as="Link" href="/medicine" size="rg" variant="white-outline"><List class="h-4 w-4 me-2" />Retour à la file</Button>
                        </div>
                    </div>
                </Card>
        </main>

        <!-- Signer une ordonnance. Même format que la demande d'examen :
             ce qui est signé, nommé, et par qui. -->
        <!-- ADR-108 — la saisie d'un compte rendu, la même qu'à « Demandes
         d'examens ». -->
    <ImagingReportDialog
        :item="reportingImagingItem"
        :orientation-uuid="orientation.uuid"
        :subtitle="`${patient.first_name} ${patient.last_name} · Passage ${episode.episode_number}`"
        :templates="options.imaging_report_templates ?? []"
        @close="reportingImagingItem = null"
        @saved="reportingImagingItem = null"
    />

    <ShadcnDialog
            :open="showPrescriptionConfirmation"
            title="Confirmer l’ordonnance"
            :description="`${patient.first_name} ${patient.last_name} · ${patient.patient_number} · Passage ${episode.episode_number}`"
            :dismissible="false"
            close-label="Annuler l’ordonnance"
            @update:open="closePrescriptionConfirmation"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                    <Pill class="h-5 w-5" />
                </span>
            </template>

            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {{ prescriptionForm.lines.length }} médicament{{ prescriptionForm.lines.length > 1 ? 's' : '' }} à prescrire
            </p>
            <ul class="mt-2 divide-y divide-border overflow-hidden rounded-lg border border-border">
                <li v-for="line in prescriptionForm.lines" :key="line._key" class="px-3 py-2.5">
                    <p class="text-sm font-semibold text-foreground">
                        <!-- Une ligne catalogue ne porte que son UUID : le nom
                             se relit dans la liste, par le même helper que
                             l'éditeur — jamais recopié dans la ligne, où il
                             finirait par diverger du référentiel. -->
                        {{ line.manual ? (line.medication_name || 'Médicament sans nom') : medicineForLine(line)?.name }}
                        <!-- Une ligne manuelle ne réserve aucun lot (ADR-037) :
                             le dire ici évite de croire le stock engagé. -->
                        <span v-if="line.manual" class="ms-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-950 dark:text-amber-200">Hors référentiel</span>
                    </p>
                    <p v-if="prescriptionLinePosology(line)" class="mt-0.5 text-xs text-muted-foreground">{{ prescriptionLinePosology(line) }}</p>
                    <p v-if="line.quantity" class="mt-0.5 text-[11px] text-muted-foreground">Quantité : {{ line.quantity }}</p>
                </li>
            </ul>

            <p class="mt-3 text-xs leading-5 text-muted-foreground">
                Vous prescrivez sous votre responsabilité, en tant que <strong class="font-semibold text-foreground">{{ $page.props.auth.user.name }}</strong>.
                L’ordonnance reste annulable avec un motif tant qu’aucune délivrance n’a commencé.
            </p>

            <template #footer>
                <Button type="button" variant="outline" :disabled="prescriptionForm.processing" @click="closePrescriptionConfirmation">
                    Revenir à l’ordonnance
                </Button>
                <Button type="button" :disabled="prescriptionForm.processing" @click="confirmPrescription">
                    <FileText class="h-4 w-4" />Je confirme et valide
                </Button>
            </template>
        </ShadcnDialog>

        <!-- Signer une demande d'examen. Non fermable au clic extérieur :
             c'est un acte engageant, pas une fenêtre qu'on parcourt. La
             liste nomme chaque examen — confirmer « 2 examens » sans les
             voir ne serait pas une signature consciente. -->
        <ShadcnDialog
            :open="pendingRequestKind !== null"
            :title="pendingRequestKind === 'imaging' ? 'Confirmer la demande d’imagerie' : 'Confirmer la demande d’analyses'"
            :description="`${patient.first_name} ${patient.last_name} · ${patient.patient_number} · Passage ${episode.episode_number}`"
            :dismissible="false"
            close-label="Annuler la demande"
            @update:open="closeRequestConfirmation"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                    <Activity class="h-5 w-5" />
                </span>
            </template>

            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {{ confirmedRequestItems.length }} examen{{ confirmedRequestItems.length > 1 ? 's' : '' }} à transmettre
            </p>
            <ul class="mt-2 divide-y divide-border overflow-hidden rounded-lg border border-border">
                <li v-for="item in confirmedRequestItems" :key="item.catalog_item_uuid" class="flex items-start gap-2 px-3 py-2.5">
                    <Activity class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-foreground">{{ item.name }}</span>
                        <span v-if="item.code" class="font-mono text-[11px] text-muted-foreground">{{ item.code }}</span>
                    </span>
                </li>
            </ul>

            <p class="mt-3 text-xs leading-5 text-muted-foreground">
                Vous transmettez cette demande sous votre responsabilité, en tant que <strong class="font-semibold text-foreground">{{ $page.props.auth.user.name }}</strong>.
                Un retrait reste possible tant qu’aucun résultat n’a été saisi.
            </p>

            <template #footer>
                <Button type="button" variant="outline" :disabled="labRequestForm.processing || imagingRequestForm.processing" @click="closeRequestConfirmation">
                    Revenir à la sélection
                </Button>
                <Button type="button" :disabled="labRequestForm.processing || imagingRequestForm.processing" @click="confirmRequest">
                    <Send class="h-4 w-4" />Je confirme et transmets
                </Button>
            </template>
        </ShadcnDialog>

        <!-- Signer une demande de soins. Non fermable au clic extérieur :
             chaque acte est nommé avec sa quantité, et le parcours choisi
             (retour en Médecine ou fin aux Soins) est relu avant l'envoi. -->
        <ShadcnDialog
            :open="showCareOrderConfirmation"
            title="Confirmer la transmission aux Soins"
            :description="`${patient.first_name} ${patient.last_name} · ${patient.patient_number} · Passage ${episode.episode_number}`"
            :dismissible="false"
            close-label="Annuler la transmission"
            @update:open="closeCareOrderConfirmation"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                    <Activity class="h-5 w-5" />
                </span>
            </template>

            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {{ careOrderForm.items.length }} acte{{ careOrderForm.items.length > 1 ? 's' : '' }} à transmettre
            </p>
            <ul class="mt-2 divide-y divide-border overflow-hidden rounded-lg border border-border">
                <li v-for="line in careOrderForm.items" :key="line.catalog_item_uuid" class="flex items-start justify-between gap-3 px-3 py-2.5">
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-foreground">{{ line.name }}</span>
                        <span v-if="line.code" class="font-mono text-[11px] text-muted-foreground">{{ line.code }}</span>
                    </span>
                    <span class="shrink-0 text-xs text-muted-foreground">× {{ line.quantity }}</span>
                </li>
            </ul>

            <p class="mt-3 text-xs text-muted-foreground">
                Après les soins :
                <strong class="font-semibold text-foreground">{{ careOrderForm.requires_return_to_medicine ? 'retour en Médecine' : 'fin du parcours aux Soins' }}</strong>
            </p>
            <p v-if="careOrderForm.instructions.trim()" class="mt-1 whitespace-pre-line text-xs text-muted-foreground">Instructions : {{ careOrderForm.instructions.trim() }}</p>

            <p class="mt-3 text-xs leading-5 text-muted-foreground">
                Vous transmettez cette demande sous votre responsabilité, en tant que <strong class="font-semibold text-foreground">{{ $page.props.auth.user.name }}</strong>.
            </p>

            <template #footer>
                <Button type="button" variant="outline" :disabled="careOrderForm.processing" @click="closeCareOrderConfirmation">
                    Revenir à la demande
                </Button>
                <Button type="button" :disabled="careOrderForm.processing" @click="confirmCareOrder">
                    <Send class="h-4 w-4" />Je confirme et transmets aux Soins
                </Button>
            </template>
        </ShadcnDialog>

        <!-- Retirer un acte demandé aux Soins : une confirmation, sans motif
             à saisir. Non fermable au clic extérieur. -->
        <ShadcnDialog
            :open="withdrawingCareOrderItem !== null"
            title="Retirer cet acte ?"
            :description="withdrawingCareOrderItem ? withdrawingCareOrderItem.name : ''"
            :dismissible="false"
            close-label="Garder l’acte"
            @update:open="closeCareOrderWithdrawal"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-destructive/10 text-destructive">
                    <Trash2 class="h-5 w-5" />
                </span>
            </template>

            <p class="text-sm text-muted-foreground">
                L’acte ne sera plus demandé aux Soins. Il reste visible, barré, avec votre nom et l’heure du retrait.
            </p>
            <FormError :message="careOrderWithdrawalForm.errors.care_order_item" />

            <template #footer>
                <Button type="button" variant="outline" :disabled="careOrderWithdrawalForm.processing" @click="closeCareOrderWithdrawal">
                    Garder l’acte
                </Button>
                <Button type="button" variant="danger" :disabled="careOrderWithdrawalForm.processing" @click="confirmCareOrderWithdrawal">
                    <Trash2 class="h-4 w-4" />Retirer
                </Button>
            </template>
        </ShadcnDialog>

        <!-- Les informations secondaires ne redimensionnent jamais la zone de
             consultation. Elles s'ouvrent dans une fenêtre dédiée et fermable
             au clavier, en conservant les vigilances dans l'en-tête principal. -->
        <ShadcnDialog
            :open="showClinicalContext"
            size="wide"
            title="Contexte clinique"
            :description="`${patient.first_name} ${patient.last_name} · ${patient.patient_number} · Passage ${episode.episode_number}`"
            close-label="Fermer le contexte clinique"
            body-class="max-h-[min(72vh,52rem)] overflow-y-auto bg-muted/20 p-4"
            @update:open="value => { showClinicalContext = value; }"
        >
            <template #icon>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                    <FileStack class="h-5 w-5" />
                </span>
            </template>

            <div id="medicine-clinical-context" class="space-y-3" aria-label="Informations cliniques complémentaires">
                <!-- La correction se trouve là où sont lues les
                     constantes : à côté d'elles, pas dans un
                     écran séparé qu'il faudrait aller chercher. -->
                <ClinicalVitalsCorrection
                    v-if="care_record?.can_correct_vitals"
                    :care-record="care_record"
                    :orientation-uuid="orientation.uuid"
                />
                <CareSummaryReadOnly v-if="care_record" :care-summary="care_record" default-open compact />
                <section v-else class="rounded-lg border border-border bg-card">
                    <div class="flex items-center gap-2 border-b border-border px-3 py-2.5"><Activity class="h-4 w-4" /><h2 class="text-xs font-bold text-foreground">Transmission des Soins</h2></div>
                    <p class="px-3 py-3 text-xs leading-5 text-muted-foreground">{{ orientation.source_module === 'CARE' ? 'Le patient est passé par les Soins, mais aucune fiche n’a été enregistrée pour ce passage.' : 'Orientation directe : aucune transmission des Soins pour ce passage.' }}</p>
                </section>

                <!-- Le fil diagnostique complet, en lecture seule.
                     Un diagnostic annulé reste visible avec son
                     auteur et sa date : la trace n'est jamais un
                     trou dans le dossier (ADR-035). -->
                <section v-if="allDiagnoses.length" class="overflow-hidden rounded-lg border border-border bg-card">
                    <div class="flex items-center justify-between border-b border-border px-3 py-2.5">
                        <div class="flex items-center gap-2"><ClipboardCheck class="h-4 w-4" /><h2 class="text-xs font-bold text-foreground">Fil diagnostique</h2></div>
                        <span class="text-[9px] font-bold uppercase tracking-wide text-muted-foreground">Cette consultation</span>
                    </div>
                    <ol class="divide-y divide-border">
                        <li v-for="diagnosis in allDiagnoses" :key="`ctx-${diagnosis.id}`" class="px-3 py-2.5">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span :class="['rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide', diagnosis.type === 'FINAL' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300']">{{ diagnosis.type === 'FINAL' ? 'Diagnostic final' : 'Hypothèse' }}</span>
                                <span v-if="diagnosis.cancelled" class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Annulé</span>
                                <span v-if="diagnosis.code" class="font-mono text-[10px] text-muted-foreground">{{ diagnosis.code }}</span>
                            </div>
                            <p :class="['mt-1 text-xs leading-5', diagnosis.cancelled ? 'text-muted-foreground line-through' : 'font-semibold text-foreground']">{{ diagnosis.description }}</p>
                            <p class="mt-0.5 text-[10px] text-muted-foreground">{{ diagnosis.recorded_by }} · {{ formatDateTime(diagnosis.recorded_at) }}</p>
                            <p v-if="diagnosis.cancelled" class="mt-0.5 text-[10px] text-muted-foreground">Annulé par {{ diagnosis.cancelled_by }} · {{ formatDateTime(diagnosis.cancelled_at) }}</p>
                        </li>
                    </ol>
                </section>

                <!-- L'examen déjà consigné, relisible depuis n'importe
                     quelle étape suivante sans rouvrir la grille. -->
                <section v-if="current_step !== 'examen'" class="overflow-hidden rounded-lg border border-border bg-card">
                    <div class="flex items-center justify-between border-b border-border px-3 py-2.5">
                        <div class="flex items-center gap-2"><CirclePlus class="h-4 w-4" /><h2 class="text-xs font-bold text-foreground">Examen clinique du médecin</h2></div>
                        <span class="text-[9px] font-bold uppercase tracking-wide text-muted-foreground">Cette consultation</span>
                    </div>
                    <div class="px-3 py-3">
                        <ClinicalExaminationSummary :examination="consultation?.clinical_examination" show-unexamined />
                        <ClinicalRichTextDisplay v-if="consultation?.clinical_exam" class="mt-3 border-t border-border pt-3" :html="consultation.clinical_exam" />
                    </div>
                </section>

                <div class="grid gap-3 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
                    <section class="overflow-hidden rounded-lg border border-border bg-card">
                        <div class="flex items-center justify-between border-b border-border px-3 py-2.5">
                            <div class="flex items-center gap-2"><User class="h-4 w-4" /><h2 class="text-xs font-bold text-foreground">Profil médical permanent</h2></div>
                            <span class="text-[9px] font-bold uppercase tracking-wide text-muted-foreground">Dossier patient</span>
                        </div>
                        <dl class="divide-y divide-border">
                            <div class="grid gap-2 px-3 py-2.5 sm:grid-cols-[9rem_1fr]">
                                <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Allergies</dt>
                                <dd><div v-if="allergies.length" class="flex flex-wrap gap-1.5"><span v-for="allergy in allergies" :key="allergy.uuid" class="rounded border border-red-100 bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 dark:border-red-950 dark:bg-red-950/20 dark:text-red-300">{{ allergy.substance }}<template v-if="allergy.reaction"> · {{ allergy.reaction }}</template></span></div><span v-else class="text-xs text-muted-foreground">Aucune allergie enregistrée</span></dd>
                            </div>
                            <div class="grid gap-2 px-3 py-2.5 sm:grid-cols-[9rem_1fr]">
                                <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Antécédents personnels</dt>
                                <dd><ul v-if="personalAntecedents.length" class="space-y-1"><li v-for="antecedent in personalAntecedents" :key="antecedent.uuid" class="text-xs leading-5 text-muted-foreground">{{ antecedent.description }}</li></ul><span v-else class="text-xs text-muted-foreground">Aucun enregistré</span></dd>
                            </div>
                            <div class="grid gap-2 px-3 py-2.5 sm:grid-cols-[9rem_1fr]">
                                <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Antécédents familiaux</dt>
                                <dd><ul v-if="familial_antecedents?.length" class="space-y-1"><li v-for="antecedent in familial_antecedents" :key="antecedent.uuid" class="text-xs leading-5 text-muted-foreground">{{ antecedent.description }}</li></ul><span v-else class="text-xs text-muted-foreground">Aucun enregistré</span></dd>
                            </div>
                            <div v-if="hasEmergencyContact" class="grid gap-2 px-3 py-2.5 sm:grid-cols-[9rem_1fr]">
                                <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Contact du passage</dt>
                                <dd class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"><strong class="text-foreground">{{ episode.emergency_contact.name || 'Nom non renseigné' }}</strong><span v-if="episode.emergency_contact.relationship" class="text-muted-foreground">{{ episode.emergency_contact.relationship }}</span><a v-if="episode.emergency_contact.phone" :href="`tel:${episode.emergency_contact.phone}`" class="text-primary hover:underline">{{ episode.emergency_contact.phone }}</a><span v-if="episode.emergency_contact.email">{{ episode.emergency_contact.email }}</span></dd>
                            </div>
                        </dl>
                        <details v-if="capabilities.can_manage_medical_history" class="group border-t border-border">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-3 py-2.5 text-xs font-semibold text-primary hover:bg-muted/35"><span class="flex items-center gap-1.5"><Plus class="h-4 w-4" />Ajouter un antécédent</span><ChevronDown class="h-4 w-4" /></summary>
                            <form class="grid gap-2 border-t border-border bg-muted/35 p-3" @submit.prevent="submitAntecedent">
                                <textarea v-model="antecedentForm.description" rows="2" placeholder="Description clinique…" :class="textareaClass" />
                                <FormError :message="antecedentForm.errors.description || antecedentForm.errors.type" />
                                <div class="flex flex-wrap items-center justify-between gap-2"><span class="inline-flex rounded border border-border bg-card p-0.5"><button type="button" :class="['rounded px-2.5 py-1 text-[11px] font-semibold', antecedentForm.type === 'PERSONAL' ? 'bg-muted text-foreground' : 'text-muted-foreground']" @click="antecedentForm.type = 'PERSONAL'">Personnel</button><button type="button" :class="['rounded px-2.5 py-1 text-[11px] font-semibold', antecedentForm.type === 'FAMILIAL' ? 'bg-muted text-foreground' : 'text-muted-foreground']" @click="antecedentForm.type = 'FAMILIAL'">Familial</button></span><Button type="submit" size="sm" :disabled="antecedentForm.processing || !antecedentForm.description.trim()">Ajouter</Button></div>
                            </form>
                        </details>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-border bg-card">
                        <div class="flex items-center justify-between border-b border-border px-3 py-2.5"><div class="flex items-center gap-2"><History class="h-4 w-4" /><h2 class="text-xs font-bold text-foreground">Consultations précédentes</h2></div><span class="rounded bg-muted px-2 py-0.5 text-[10px] font-bold text-muted-foreground">{{ previous_consultations?.length ?? 0 }}</span></div>
                        <ul v-if="previous_consultations?.length" class="max-h-64 divide-y divide-border overflow-y-auto">
                            <li v-for="previous in previous_consultations" :key="previous.id" class="px-3 py-2.5">
                                <p class="text-[11px] font-semibold text-foreground">{{ formatDateTime(previous.consulted_at) }} · Dr {{ previous.doctor }}</p>
                                <ClinicalRichTextDisplay class="mt-1 line-clamp-2 text-xs leading-4 text-muted-foreground" :html="previous.reason" />
                                <p v-if="previous.decision_label" class="mt-1 text-[10px] text-muted-foreground">{{ previous.decision_label }}</p>
                            </li>
                        </ul>
                        <p v-else class="px-3 py-4 text-center text-xs text-muted-foreground">Aucune consultation antérieure.</p>
                    </section>
                </div>
            </div>

            <template #footer>
                <Button type="button" size="rg" variant="white-outline" @click="showClinicalContext = false">Fermer</Button>
            </template>
        </ShadcnDialog>

        <ShadcnDialog
            :open="showEmergencyConfirm"
            title="Classer ce passage en urgence ?"
            :description="`Passage ${episode.episode_number}`"
            close-label="Fermer la confirmation"
            @update:open="value => { if (!value) closeEmergencyConfirm(); }"
        >
            <template #icon>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/40 dark:text-red-300" aria-hidden="true">
                    <Activity class="h-5 w-5" />
                </span>
            </template>

            <p class="text-sm leading-6 text-muted-foreground">
                Le passage devient visible immédiatement aux Soins et en Médecine. Le patient et ses autres passages ne changent pas.
            </p>
            <FormError class="mt-3" :message="emergencyForm.errors.episode" />

            <template #footer>
                <Button type="button" size="rg" variant="white-outline" :disabled="emergencyForm.processing" @click="closeEmergencyConfirm">Annuler</Button>
                <Button type="button" size="rg" variant="danger" :disabled="emergencyForm.processing" @click="confirmMarkEmergency">
                    <Activity class="me-2 h-4 w-4" aria-hidden="true" />{{ emergencyForm.processing ? 'Classement…' : 'Confirmer l’urgence' }}
                </Button>
            </template>
        </ShadcnDialog>

        <ShadcnDialog
            :open="Boolean(diagnosisToCancel)"
            title="Annuler ce diagnostic ?"
            description="Cette action retire la version active sans supprimer son historique médical."
            close-label="Fermer la confirmation"
            @update:open="value => { if (!value) closeDiagnosisCancellation(); }"
        >
            <template #icon>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/40 dark:text-red-300" aria-hidden="true">
                    <Trash2 class="h-5 w-5" />
                </span>
            </template>

            <!-- Les actions restent dans le <form> : les sortir dans le slot
                 pied les couperait du `submit`, et le bouton ne validerait
                 plus rien. -->
            <form v-if="diagnosisToCancel" @submit.prevent="cancelDiagnosis">
                <div class="space-y-4">
                    <div class="rounded-lg border border-border bg-muted/35 px-4 py-3">
                        <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ diagnosisToCancel.type_label }}</span>
                        <p class="mt-1 break-words text-sm font-semibold leading-6 text-foreground">{{ diagnosisToCancel.description }}</p>
                    </div>
                    <div class="flex items-start gap-2.5 text-xs leading-5 text-muted-foreground">
                        <Info class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" aria-hidden="true" />
                        <p>La saisie restera consultable dans l’historique des rectifications avec sa trace d’annulation.</p>
                    </div>
                    <FormError :message="diagnosisCancellationForm.errors.diagnosis_id" />
                </div>
                <div class="-mx-6 -mb-5 mt-5 flex flex-col-reverse gap-2 border-t border-border bg-muted/35 px-6 py-4 sm:flex-row sm:justify-end">
                    <Button type="button" size="rg" variant="white-outline" :disabled="diagnosisCancellationForm.processing" @click="closeDiagnosisCancellation">Conserver</Button>
                    <Button type="submit" size="rg" variant="danger" :disabled="diagnosisCancellationForm.processing">
                        <Trash2 class="me-2 h-4 w-4" aria-hidden="true" />{{ diagnosisCancellationForm.processing ? 'Annulation…' : 'Confirmer l’annulation' }}
                    </Button>
                </div>
            </form>
        </ShadcnDialog>

        <ShadcnDialog
            :open="Boolean(prescriptionToRemove)"
            title="Retirer cette ordonnance ?"
            description="Elle disparaîtra de la liste active et les quantités réservées seront libérées."
            close-label="Fermer la confirmation"
            @update:open="value => { if (!value) closePrescriptionRemoval(); }"
        >
            <template #icon>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/40 dark:text-red-300" aria-hidden="true">
                    <Trash2 class="h-5 w-5" />
                </span>
            </template>

            <form v-if="prescriptionToRemove" @submit.prevent="removePrescription">
                <div class="space-y-3">
                    <div class="rounded-lg border border-border bg-muted/35 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Médicaments concernés</p>
                        <ul class="mt-2 space-y-1.5">
                            <li v-for="line in prescriptionToRemove.lines" :key="line.id" class="flex items-center justify-between gap-4 text-sm">
                                <span class="font-semibold text-foreground">{{ line.medication_name }}</span>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ line.quantity }} {{ line.unit || 'unité(s)' }}</span>
                            </li>
                        </ul>
                    </div>
                    <FormError :message="prescriptionRemovalForm.errors.reason" />
                </div>
                <div class="-mx-6 -mb-5 mt-5 flex flex-col-reverse gap-2 border-t border-border bg-muted/35 px-6 py-4 sm:flex-row sm:justify-end">
                    <Button type="button" size="rg" variant="white-outline" :disabled="prescriptionRemovalForm.processing" @click="closePrescriptionRemoval">Conserver</Button>
                    <Button type="submit" size="rg" variant="danger" :disabled="prescriptionRemovalForm.processing">
                        <Trash2 class="me-2 h-4 w-4" aria-hidden="true" />{{ prescriptionRemovalForm.processing ? 'Retrait…' : 'Retirer' }}
                    </Button>
                </div>
            </form>
        </ShadcnDialog>

        <ShadcnDialog
            :open="Boolean(requestToWithdraw)"
            title="Retirer cette demande ?"
            description="Elle quitte la file du service mais reste consultable dans le dossier, avec sa trace de retrait."
            close-label="Fermer la confirmation"
            @update:open="value => { if (!value) closeWithdrawal(); }"
        >
            <template #icon>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/40 dark:text-red-300" aria-hidden="true">
                    <Trash2 class="h-5 w-5" />
                </span>
            </template>

            <form v-if="requestToWithdraw" @submit.prevent="confirmWithdrawal">
                <div class="space-y-3">
                    <div class="rounded-lg border border-border bg-muted/35 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Examens concernés</p>
                        <ul class="mt-2 space-y-1">
                            <li v-for="item in requestToWithdraw.request.items" :key="item.uuid" class="text-sm font-semibold text-foreground">
                                {{ item.name }}
                            </li>
                        </ul>
                    </div>
                    <div>
                        <label for="withdraw-reason" class="mb-1 block text-sm font-medium text-muted-foreground">
                            Motif <span class="font-normal">· facultatif</span>
                        </label>
                        <Input id="withdraw-reason" v-model="withdrawalForm.reason" maxlength="500" placeholder="Ex. finalement sans indication" />
                        <FormError :message="withdrawalForm.errors.reason" />
                    </div>
                    <FormError :message="withdrawalForm.errors.request" />
                </div>
                <div class="-mx-6 -mb-5 mt-5 flex flex-col-reverse gap-2 border-t border-border bg-muted/35 px-6 py-4 sm:flex-row sm:justify-end">
                    <Button type="button" size="rg" variant="white-outline" :disabled="withdrawalForm.processing" @click="closeWithdrawal">Conserver</Button>
                    <Button type="submit" size="rg" variant="danger" :disabled="withdrawalForm.processing">
                        <Trash2 class="me-2 h-4 w-4" aria-hidden="true" />{{ withdrawalForm.processing ? 'Retrait…' : 'Retirer la demande' }}
                    </Button>
                </div>
            </form>
        </ShadcnDialog>

        <!-- Confirmation, pas blocage : le médecin peut avoir une raison
             parfaitement valable de clôturer sur une constante basse. Ce
             que le système refuse, c'est de le laisser passer sans l'avoir
             nommé. -->
        <ShadcnDialog
            :open="showAwaitingResultConfirm"
            title="Clôturer sans attendre le résultat ?"
            description="Un examen demandé pour ce passage n’a pas encore rendu son résultat."
            close-label="Fermer la confirmation"
            @update:open="value => { showAwaitingResultConfirm = value; }"
        >
            <template #icon>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300" aria-hidden="true">
                    <Clock class="h-5 w-5" />
                </span>
            </template>

            <ul class="space-y-2">
                <li v-for="exam in awaitingResults" :key="exam" class="rounded-md border border-amber-200 bg-amber-50/60 px-3 py-2 text-sm font-semibold text-foreground dark:border-amber-900/60 dark:bg-amber-950/20">
                    {{ exam }}
                </li>
            </ul>
            <p class="mt-3 text-xs leading-5 text-muted-foreground">
                Clôturer reste possible : vous avez peut-être conclu sans ce résultat. Sinon, laissez la consultation ouverte — elle vous attendra, et « Demandes d’examens » vous signalera le résultat dès qu’il arrive.
            </p>

            <template #footer>
                <Button type="button" size="rg" variant="white-outline" @click="showAwaitingResultConfirm = false">
                    Laisser ouverte
                </Button>
                <Button type="button" size="rg" variant="warning" :disabled="completeConsultationForm.processing" @click="submitCompleteConsultation">
                    <CircleCheck class="me-2 h-4 w-4" aria-hidden="true" />Clôturer malgré tout
                </Button>
            </template>
        </ShadcnDialog>

        <ShadcnDialog
            v-model:open="showCriticalVitalConfirm"
            title="Constante critique non résolue"
            description="Cette consultation va être clôturée alors qu’une constante reste hors des bornes attendues."
        >
            <template #icon>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/40 dark:text-red-300">
                    <CircleAlert class="h-5 w-5" />
                </span>
            </template>

            <ul class="space-y-2">
                <li v-for="alert in vitalAlerts.filter((a) => a.severity === 'danger')" :key="alert.label" class="rounded-md border border-red-200 bg-red-50/60 px-3 py-2 text-xs leading-5 dark:border-red-900 dark:bg-red-950/20">
                    <span class="font-semibold text-foreground">{{ alert.label }}</span>
                    <span class="mx-1.5 font-bold tabular-nums text-red-600 dark:text-red-300">{{ alert.reading }}</span>
                    <span class="font-semibold text-muted-foreground">— {{ alert.title }}</span>
                    <span class="block text-muted-foreground">{{ alert.message }}</span>
                </li>
            </ul>
            <p class="mt-3 text-xs leading-5 text-muted-foreground">
                Clôturer reste possible : une valeur peut avoir déjà été prise en compte, ou provenir d’une erreur de saisie.
                <template v-if="care_record?.can_correct_vitals">
                    Si la mesure est fausse, corrigez-la depuis « Contexte clinique » avant de clôturer.
                </template>
            </p>

            <template #footer>
                <Button type="button" size="rg" variant="white-outline" @click="showCriticalVitalConfirm = false">
                    Revenir au dossier
                </Button>
                <Button type="button" size="rg" variant="danger" :disabled="completeConsultationForm.processing" @click="submitCompleteConsultation">
                    <CircleCheck class="me-2 h-4 w-4" />Clôturer malgré tout
                </Button>
            </template>
        </ShadcnDialog>

        <!-- Le retrait des demandes déjà transmises. Elle nomme chaque
             examen : une fenêtre qui annonce « (1) » sans dire lequel ne
             permet pas de décider. -->
        <ShadcnDialog
            v-model:open="withdrawConfirmOpen"
            size="lg"
            title="Retirer les demandes déjà transmises ?"
            description="Répondre « Aucun examen nécessaire » annule les demandes non réalisées."
        >
            <template #icon><CircleAlert class="h-5 w-5 text-amber-600 dark:text-amber-400" /></template>

            <div class="space-y-4">
                <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
                    <li v-for="request in paraclinicalRequests" :key="request.uuid" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
                        <span class="min-w-0">
                            <span class="block text-xs font-medium text-muted-foreground">{{ request.label }}</span>
                            <span class="block truncate text-sm font-bold text-foreground">{{ request.summary }}</span>
                        </span>
                        <span class="shrink-0 rounded-md border border-border bg-muted px-2 py-1 text-[11px] font-bold text-muted-foreground">{{ request.status_label }}</span>
                    </li>
                </ul>

                <p class="text-xs leading-5 text-muted-foreground">
                    Les demandes retirées ne sont pas supprimées : elles gardent leur auteur et leur date, et restent lisibles dans le dossier.
                    <strong class="font-semibold text-foreground">Une demande portant déjà un résultat n’est jamais retirée</strong> — le serveur refuse alors l’opération et vous le dit.
                </p>

                <FormError :message="complementaryExamsForm.errors.required" />
            </div>

            <template #footer>
                <Button type="button" variant="white-outline" size="sm" @click="withdrawConfirmOpen = false">Annuler</Button>
                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    :disabled="complementaryExamsForm.processing"
                    @click="confirmWithdrawAndDecide"
                >
                    <Trash2 class="h-4 w-4" />Retirer et continuer
                </Button>
            </template>
        </ShadcnDialog>

        <!-- Rouvrir une consultation clôturée (ADR-096). Une fenêtre où l'on
             écrit : un clic à côté ne doit pas la fermer. -->
        <ShadcnDialog
            v-model:open="reopenOpen"
            title="Rouvrir la consultation ?"
            description="La clôture est défaite ; aucune donnée déjà enregistrée n’est supprimée."
            :dismissible="false"
        >
            <template #icon><RotateCcw class="h-5 w-5" /></template>

            <form class="space-y-4" @submit.prevent="submitReopen">
                <p class="text-sm text-foreground">
                    Le passage revient en prise en charge et quitte la file « Sorties &amp; règlements » de la Réception. Les diagnostics, l’ordonnance et une sortie médicale déjà prononcée restent en place.
                </p>

                <div>
                    <label for="reopen_reason" class="mb-1.5 block text-xs font-bold text-foreground">
                        Motif <span class="text-destructive">*</span>
                    </label>
                    <Input
                        id="reopen_reason"
                        v-model="reopenForm.reason"
                        placeholder="Ex. : résultat de l’ECG reçu après la clôture."
                        autocomplete="off"
                    />
                    <FormError class="mt-1" :message="reopenForm.errors.reason" />
                </div>

                <p class="text-[11px] leading-4 text-muted-foreground">
                    Ce motif est conservé dans l’audit du dossier, avec votre nom et la date.
                </p>
            </form>

            <template #footer>
                <Button type="button" variant="white-outline" size="sm" @click="reopenOpen = false">Annuler</Button>
                <Button type="button" size="sm" :disabled="reopenForm.processing" @click="submitReopen">
                    <RotateCcw class="h-4 w-4" />Rouvrir
                </Button>
            </template>
        </ShadcnDialog>
    </div>
</template>
