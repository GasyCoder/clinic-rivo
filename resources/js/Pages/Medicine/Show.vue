<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
import ClinicalTreatmentList from '@/Components/Clinical/ClinicalTreatmentList.vue';
import ClinicalReportedInformation from '@/Components/Clinical/ClinicalReportedInformation.vue';
import ClinicalDiagnosisEntry from '@/Components/Clinical/ClinicalDiagnosisEntry.vue';
import PrescriptionLineEditor from '@/Components/Clinical/PrescriptionLineEditor.vue';
import CareSummaryReadOnly from '@/Components/Surgery/CareSummaryReadOnly.vue';
import ClinicalPatientHeader from '@/Components/Clinical/ClinicalPatientHeader.vue';
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';
import ClinicalRichTextEditor from '@/Components/Clinical/ClinicalRichTextEditor.vue';
import { useFormDraft } from '@/composables/useFormDraft';
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
const stickyStack = ref(null);
const stickyStackOffset = ref(APP_HEADER_HEIGHT + 16);
let stackObserver = null;

onMounted(() => {
    if (!stickyStack.value || typeof ResizeObserver === 'undefined') return;

    const measure = () => {
        stickyStackOffset.value = APP_HEADER_HEIGHT + Math.round(stickyStack.value.offsetHeight) + 12;
    };

    measure();
    stackObserver = new ResizeObserver(measure);
    stackObserver.observe(stickyStack.value);
});

onBeforeUnmount(() => {
    stackObserver?.disconnect();
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
const isClosed = computed(() => Boolean(props.medical_discharge));
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
    { key: 'dossier', label: 'Dossier du passage', navLabel: 'Dossier', hint: 'Contexte', icon: 'file-text' },
    { key: 'consultation', label: 'Interrogatoire', hint: 'Histoire clinique', icon: 'chat' },
    { key: 'examen', label: 'Examen clinique', navLabel: 'Examen', hint: 'Constatations', icon: 'plus-medi' },
    { key: 'paraclinique', label: 'Examens paracliniques', navLabel: 'Paraclinique', hint: 'Selon indication', icon: 'activity' },
    { key: 'ordonnance', label: 'Prescription', navLabel: 'Prescription', hint: 'Traitement', icon: 'file-check' },
    { key: 'cloture', label: 'Décision & clôture', navLabel: 'Décision & clôture', hint: 'Conclure le passage', icon: 'check-circle' },
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

/** What the server says still stands between here and a closed consultation. */
const closureBlockers = computed(() => props.consultation?.closure_blockers ?? []);
const consultationIsClosed = computed(() => props.consultation?.status === 'COMPLETED');
const completeConsultationForm = useForm({});
const completeConsultation = () => completeConsultationForm.post(
    `/medicine/orientations/${props.orientation.uuid}/complete`,
    { preserveScroll: true },
);

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
const selectClass = 'block h-9 w-full appearance-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000';
const textareaClass = 'block w-full resize-y rounded border border-gray-200 bg-white px-3 py-2 text-sm leading-5 text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000';

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
    // Tri-state, never pre-selected: null until the doctor answers.
    // « Le diagnostic peut-il être posé maintenant ? » — null tant que
    // le médecin n'a pas répondu ; « Non » laisse l'étape ouverte.
    diagnosis_ready: props.consultation?.clinical_examination?.diagnosis_ready ?? null,
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

const decideComplementaryExams = (value) => {
    if (!props.capabilities.can_update_consultation || value === complementaryExamsForm.required) return;

    // Answering "Non" withdraws what was already ordered, so it is confirmed
    // first — never silently. A request carrying a result is refused server-side.
    if (value === false && paraclinicalRequests.value.length > 0) {
        const confirmed = window.confirm(
            `Des examens complémentaires ont déjà été demandés (${paraclinicalRequests.value.length}). `
            + 'Passer à « Aucun examen nécessaire » annulera les demandes non réalisées. Continuer ?',
        );

        if (!confirmed) return;

        complementaryExamsForm.withdraw_confirmed = true;
    }

    complementaryExamsForm.required = value;
    complementaryExamsForm.post(
        `/medicine/orientations/${props.orientation.uuid}/complementary-exams`,
        { preserveScroll: true },
    );
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

const NEUTRAL_TONE = 'text-slate-700 dark:text-white';
const toneFor = (assessment) => {
    if (assessment?.tone === 'danger') return 'text-red-600 dark:text-red-300';
    if (assessment && assessment.tone !== 'success') return 'text-amber-600 dark:text-amber-300';

    return NEUTRAL_TONE;
};
const dotFor = (assessment) => (assessment?.tone === 'danger' ? 'bg-red-500' : 'bg-amber-500');

const vigilanceVitals = computed(() => {
    const record = props.care_record ?? {};
    const bloodPressure = careRecordBloodPressure.value;
    const allergyNames = (props.allergies ?? []).map((allergy) => allergy.substance);

    return [
        {
            label: 'TA',
            value: bloodPressure || 'N/R',
            unit: bloodPressure ? 'mmHg' : null,
            tone: bloodPressure ? toneFor(record.blood_pressure_assessment) : 'text-slate-400',
            alert: bloodPressure ? record.blood_pressure_assessment?.tone !== 'success'
                ? record.blood_pressure_assessment?.label : null : null,
            dotClass: dotFor(record.blood_pressure_assessment),
            title: record.blood_pressure_assessment?.message,
        },
        {
            label: 'FC',
            value: record.heart_rate ?? 'N/R',
            unit: record.heart_rate ? 'bpm' : null,
            tone: record.heart_rate ? toneFor(record.heart_rate_assessment) : 'text-slate-400',
            alert: record.heart_rate && record.heart_rate_assessment?.tone !== 'success'
                ? record.heart_rate_assessment?.label : null,
            dotClass: dotFor(record.heart_rate_assessment),
            title: record.heart_rate_assessment?.message,
        },
        {
            label: 'SpO₂',
            value: record.spo2 ?? 'N/R',
            unit: record.spo2 !== null && record.spo2 !== undefined ? '%' : null,
            tone: record.spo2 !== null && record.spo2 !== undefined ? toneFor(record.spo2_assessment) : 'text-slate-400',
            alert: record.spo2_assessment && record.spo2_assessment.tone !== 'success'
                ? record.spo2_assessment.label : null,
            dotClass: dotFor(record.spo2_assessment),
            title: record.spo2_assessment?.message,
        },
        {
            label: 'T°',
            value: trimDecimals(record.temperature_celsius) ?? 'N/R',
            unit: record.temperature_celsius ? '°C' : null,
            tone: record.temperature_celsius ? toneFor(record.temperature_assessment) : 'text-slate-400',
            alert: record.temperature_celsius && record.temperature_assessment?.tone !== 'success'
                ? record.temperature_assessment?.label : null,
            dotClass: dotFor(record.temperature_assessment),
            title: record.temperature_assessment?.message,
        },
        {
            label: 'Allergies',
            value: allergyNames.length ? allergyNames.join(', ') : 'Aucune enregistrée',
            unit: null,
            tone: allergyNames.length ? 'text-red-600 dark:text-red-300' : 'text-slate-400',
            alert: null,
            dotClass: 'bg-slate-300',
            title: allergyNames.join(', ') || null,
        },
    ].filter((vital) => vital.value !== 'N/R');
});

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
 * queue the same act twice. The server refuses it too; the list says so
 * before the doctor tries.
 */
const pendingCareOrderCodes = computed(() => new Set((props.consultation?.care_orders ?? [])
    .filter((order) => order.status === 'PENDING')
    .flatMap((order) => order.items)
    .filter((item) => !item.not_performed_at && Number(item.remaining_quantity) > 0)
    .map((item) => item.code)));
const isCareOrderItemPending = (item) => pendingCareOrderCodes.value.has(item.code);

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

// Les demandes d'orientation (chirurgie, hospitalisation, référence,
// service) vivent maintenant dans ClinicalOrientationCard, au plus près
// du moment où la conduite à tenir est décidée (ADR-084). L'écran ne
// porte plus ni leurs champs ni leurs formulaires.

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
/** Met à jour un champ d'une ligne en préparation, sans muter en place. */
const updatePrescriptionLine = (index, { field, value }) => {
    prescriptionForm.lines = prescriptionForm.lines.map((line, position) => (
        position === index ? { ...line, [field]: value } : line
    ));
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

    <div class="w-full space-y-3" :style="{ '--clinical-stack-top': `${stickyStackOffset}px` }">
        <!-- Identité, constantes et parcours restent sous les yeux pendant
             tout le défilement : le médecin ne doit jamais remonter pour
             vérifier de quel patient il s'agit ni où il en est.

             `top-16` dégage le header applicatif fixe (64px). Le fond est
             opaque et repris du `body`, sinon le contenu défilerait au
             travers. Collant à partir de 1024px seulement : sur un écran
             étroit ce bloc mangerait la moitié de la hauteur utile. -->
        <div
            ref="stickyStack"
            class="space-y-3 bg-gray-50 dark:bg-gray-1000 lg:sticky lg:top-16 lg:z-30 lg:pb-3 lg:pt-1"
        >
        <ClinicalPatientHeader :patient="patient" :episode="episode" :reason="orientation.reason" back-href="/medicine" back-label="File Médecine">
            <template #actions>
                        <!-- Always red: requalifying a visit as an emergency is a
                             grave decision, and its button must read as such whatever
                             the surrounding design. -->
                        <Button v-if="capabilities.can_mark_emergency" size="sm" variant="danger" :disabled="emergencyForm.processing" @click="markEpisodeEmergency"><Icon class="text-sm" name="alert-circle" /><span class="ms-1.5 hidden sm:inline">Classer en urgence</span></Button>
                        <Button type="button" size="sm" :variant="showClinicalContext ? 'secondary' : 'white-outline'" :aria-expanded="showClinicalContext" aria-controls="medicine-clinical-context" @click="showClinicalContext = !showClinicalContext">
                            <Icon class="text-sm" name="file-docs" /><span class="ms-1.5 hidden sm:inline">{{ showClinicalContext ? 'Masquer le contexte' : 'Contexte clinique' }}</span>
                        </Button>
                        <Button :as="Link" :href="`/patients/${patient.uuid}`" size="sm" variant="white-outline"><Icon class="text-sm" name="user" /><span class="ms-1.5 hidden sm:inline">Dossier patient</span></Button>
            </template>
            <!-- One thin line instead of two bands. Naissance, sexe, téléphone,
                 adressé par and pris en charge were all repeating what the header
                 or the Dossier step already says; they now live once, on the
                 Dossier step. What stays is the consultation's state, the vitals
                 worth a glance, and the two actions. -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-200 px-4 py-2.5 dark:border-gray-900">
                <span :class="['inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold', isClosed ? 'text-emerald-600 dark:text-emerald-300' : 'text-primary-600 dark:text-primary-300']">
                    <span :class="['h-1.5 w-1.5 rounded-full', isClosed ? 'bg-emerald-500' : 'bg-primary-500']" />
                    {{ isClosed ? medical_discharge.type_label : 'En consultation' }}
                </span>

                <!-- Abnormal values are marked by the value's own colour and a
                     single dot — no background, no icon, no repeated label. The
                     full wording stays in the tooltip and in the Soins panel. -->
                <dl class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                    <div v-for="vital in vigilanceVitals" :key="vital.label" class="flex items-baseline gap-1.5">
                        <dt class="text-slate-400">{{ vital.label }}</dt>
                        <dd :class="['font-semibold', vital.tone]" :title="vital.alert ? `${vital.alert} — ${vital.title}` : vital.title">
                            {{ vital.value }}<span v-if="vital.unit" class="ms-0.5 font-normal opacity-60">{{ vital.unit }}</span>
                            <span v-if="vital.alert" class="ms-1 inline-block h-1.5 w-1.5 rounded-full align-middle" :class="vital.dotClass" />
                        </dd>
                    </div>
                </dl>
            </div>
            <div v-if="draft.restored.value || draft.savedAt.value" class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 bg-slate-50 px-4 py-2 text-xs dark:border-gray-900 dark:bg-gray-1000/50">
                <p class="flex items-center gap-2 text-slate-500 dark:text-slate-300">
                    <Icon name="save" class="shrink-0 text-sm text-primary-600" />
                    <span><strong class="font-semibold text-slate-700 dark:text-white">{{ draft.restored.value ? 'Brouillon restauré' : 'Brouillon sauvegardé' }}</strong> · non versé au dossier<span v-if="draft.saving.value"> · enregistrement…</span><span v-else-if="draft.savedAt.value"> · {{ formatDateTime(draft.savedAt.value) }}</span></span>
                </p>
                <Button class="shrink-0" type="button" size="sm" variant="white-outline" @click="discardDraft">
                    <Icon class="text-sm" name="cross" /><span class="ms-1.5">Effacer le brouillon</span>
                </Button>
            </div>
        </ClinicalPatientHeader>

        <MedicineWorkflowNav
            :steps="wizardSteps"
            :current-key="current_step"
            :orientation-uuid="orientation.uuid"
        />
        </div>

        <main class="space-y-3">
                <Card v-if="cardIsOpen('dossier')" class="overflow-clip border-s-4 border-s-primary-500 shadow-sm">
                    <!-- Pas d'identité ici : le nom, le numéro, le sexe et l'âge
                         sont portés une seule fois par l'en-tête de la page. -->
                    <div class="flex items-center gap-3 border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"><Icon class="text-base" name="file-text" /></span>
                        <div class="min-w-0">
                            <h2 class="text-sm font-bold text-slate-700 dark:text-white">Dossier du passage</h2>
                            <p class="mt-0.5 text-xs text-slate-400">L’essentiel avant de commencer la consultation.</p>
                        </div>
                    </div>
                    <div class="p-5">
                        <div class="grid gap-3 lg:grid-cols-2">
                        <section :class="['rounded-md border border-gray-200 bg-gray-50/50 p-4 dark:border-gray-900 dark:bg-gray-1000/30', !(care_record?.transmission_reason || care_record?.diagnostic_note) ? 'lg:col-span-2' : '']">
                            <p class="mb-2 flex items-center gap-2 text-[10px] font-bold uppercase tracking-wide text-slate-400"><Icon class="text-sm" name="clipboard" />Besoin exprimé à l’accueil</p>
                            <div v-if="episode.designations.length" class="flex flex-wrap gap-1.5">
                                <span v-for="designation in episode.designations" :key="designation.uuid" class="inline-flex items-center gap-1.5 rounded border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs font-semibold text-slate-700 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-200">
                                    {{ designation.description }}
                                    <span v-if="Number(designation.quantity) > 1" class="text-slate-400">× {{ designation.quantity }}</span>
                                </span>
                            </div>
                            <p v-else class="text-sm text-slate-500">Motif à préciser pendant la consultation.</p>
                        </section>

                        <section v-if="care_record?.transmission_reason || care_record?.diagnostic_note" class="rounded-md border border-primary-200 bg-primary-50/40 p-4 dark:border-primary-900 dark:bg-primary-950/15">
                            <p class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-primary-700 dark:text-primary-300">
                                <Icon name="send" class="text-xs" />Transmission des Soins
                            </p>
                            <p class="mt-1.5 text-sm leading-5 text-slate-700 dark:text-white">{{ care_record.transmission_reason || care_record.diagnostic_note }}</p>
                        </section>
                        </div>

                        <!-- Toujours visible, jamais derrière un repli : c'est
                             exactement ce que le médecin doit voir avant de
                             commencer — masquer l'état civil et les
                             coordonnées par défaut a déjà été rejeté une
                             fois sur cette même page. -->
                        <div class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-900">
                            <p class="flex items-center gap-2 px-2 py-2 text-sm font-semibold text-slate-600 dark:text-slate-300"><Icon class="text-slate-400" name="user" />Informations administratives et coordonnées</p>
                            <div class="grid gap-3 pt-1 lg:grid-cols-3">
                                <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900">
                                    <h3 class="mb-3 text-[10px] font-bold uppercase tracking-wide text-slate-400">Informations administratives</h3>
                                    <dl class="grid gap-x-5 gap-y-2 sm:grid-cols-2 lg:grid-cols-1 2xl:grid-cols-2">
                                        <div v-for="field in civilIdentityFields" :key="field.label" class="min-w-0 border-b border-gray-100 pb-1.5 dark:border-gray-900">
                                            <dt class="text-[10px] text-slate-400">{{ field.label }}</dt>
                                            <dd :class="['truncate text-sm font-semibold text-slate-700 dark:text-white', field.mono ? 'font-mono' : '']" :title="field.value"><span v-if="field.value">{{ field.value }}</span><span v-else class="font-normal text-slate-300 dark:text-slate-600">Non renseigné</span></dd>
                                        </div>
                                    </dl>
                                </section>
                                <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900">
                                    <h3 class="mb-3 text-[10px] font-bold uppercase tracking-wide text-slate-400">Coordonnées</h3>
                                    <dl class="space-y-3">
                                        <div v-for="field in contactFields" :key="field.label" class="min-w-0">
                                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ field.label }}</dt>
                                            <dd class="mt-0.5 truncate text-sm font-semibold text-slate-700 dark:text-white" :title="field.value"><a v-if="field.href && field.value" :href="field.href" class="hover:text-primary-600 dark:hover:text-primary-400">{{ field.value }}</a><span v-else-if="field.value">{{ field.value }}</span><span v-else class="font-normal text-slate-300 dark:text-slate-600">Non renseigné</span></dd>
                                        </div>
                                    </dl>
                                </section>
                                <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900">
                                    <h3 class="mb-3 text-[10px] font-bold uppercase tracking-wide text-slate-400">Traçabilité</h3>
                                    <dl class="space-y-3">
                                        <div v-for="field in visitFields" :key="field.label" class="min-w-0">
                                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ field.label }}</dt>
                                            <dd class="mt-0.5 truncate text-sm font-semibold text-slate-700 dark:text-white" :title="field.value">{{ field.value || '—' }}</dd>
                                        </div>
                                    </dl>
                                </section>
                            </div>
                        </div>
                    </div>
                    <ConsultationStepBar
                        :orientation-uuid="orientation.uuid"
                        step-key="dossier"
                        :state="stepState('dossier')"
                        :next="nextStep ? { key: nextStep.key, label: nextStep.label } : null"
                        :can-edit="capabilities.can_resolve_step"
                    >
                        <template #note>
                            <p class="flex items-center justify-center gap-2 text-xs text-slate-500"><Icon class="text-primary-600" name="eye" />La synthèse Soins reste consultable depuis « Contexte clinique ».</p>
                        </template>
                    </ConsultationStepBar>
                </Card>

                <Card v-if="cardIsOpen('consultation')" class="w-full overflow-clip border-s-4 border-s-primary-500 shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"><Icon class="text-xl" name="chat" /></span>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-primary-600 dark:text-primary-300">Étape 2 · Entretien médical</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-800 dark:text-white">Interrogatoire</h2>
                                <p class="mt-0.5 text-xs text-slate-400">Documentez ce que rapporte le patient avant tout examen physique.</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs">
                            <span v-if="episode.designations.length" class="flex flex-wrap items-center gap-1.5">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Demande</span>
                                <span v-for="designation in episode.designations" :key="designation.uuid" class="rounded border border-amber-200 bg-amber-50 px-2 py-1 font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{{ designation.description }}</span>
                            </span>
                            <span class="text-slate-400">Dr {{ consultation.doctor }}</span>
                        </div>
                    </div>

                    <form @submit.prevent="saveInterview">
                        <!-- 70/30 dès 1024px. En dessous : motif, histoire,
                             traitements, informations nouvelles, empilés. -->
                        <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,2.33fr)_minmax(18rem,1fr)]">
                            <fieldset class="min-w-0 space-y-4">
                                <legend class="sr-only">Interrogatoire du patient</legend>

                                <!-- Court et exploitable : ce champ alimente
                                     l'historique, les résumés et la recherche.
                                     Il ne reprend jamais le type de demande,
                                     déjà affiché dans l'en-tête. -->
                                <div>
                                    <label for="chief_complaint" class="block text-sm font-bold text-slate-700 dark:text-white">Motif principal de consultation <span class="text-red-500">*</span></label>
                                    <p class="mt-1 text-[11px] leading-4 text-slate-400">La raison réelle de la venue, en quelques mots.</p>
                                    <Input id="chief_complaint" v-model="interviewForm.chief_complaint" class="mt-2" :disabled="!capabilities.can_update_consultation" maxlength="255" placeholder="Ex. douleur abdominale persistante" />
                                    <FormError class="mt-1" :message="interviewForm.errors.chief_complaint" />
                                </div>

                                <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-900">
                                    <h3 class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Histoire de la maladie actuelle</h3>

                                    <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:gap-x-10">
                                        <div class="min-w-0 sm:w-56">
                                            <label for="symptom_onset" class="block text-xs font-bold text-slate-700 dark:text-white">Début / durée</label>
                                            <Input id="symptom_onset" v-model="interviewForm.symptom_onset" class="mt-2" :disabled="!capabilities.can_update_consultation" maxlength="150" placeholder="Ex. depuis 3 jours" />
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

                                    <div class="mt-4">
                                        <label for="reason" class="block text-xs font-bold text-slate-700 dark:text-white">Histoire clinique <span class="text-red-500">*</span></label>
                                        <p class="mt-1 text-[11px] leading-4 text-slate-400">Décrivez le début, l’évolution des symptômes, les symptômes associés, les facteurs aggravants ou soulageants et les informations rapportées par le patient.</p>
                                        <div class="mt-2">
                                            <ClinicalRichTextEditor id="reason" v-model="interviewForm.reason" :disabled="!capabilities.can_update_consultation" :max-length="3000" min-height-class="min-h-56" placeholder="Récit clinique du patient…" />
                                            <FormError class="mt-1" :message="interviewForm.errors.reason" />
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <label for="additional_notes" class="block text-xs font-bold text-slate-700 dark:text-white">Notes complémentaires <span class="font-normal text-slate-400">· facultatif</span></label>
                                        <textarea id="additional_notes" v-model="interviewForm.additional_notes" rows="2" maxlength="2000" :disabled="!capabilities.can_update_consultation" :class="[textareaClass, 'mt-2']" placeholder="Éléments de contexte utiles à la relecture…" />
                                        <FormError :message="interviewForm.errors.additional_notes" />
                                    </div>
                                </section>
                            </fieldset>

                            <div class="min-w-0 space-y-4">
                                <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-900" aria-labelledby="current-treatments-title">
                                    <h3 id="current-treatments-title" class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Traitements actuels</h3>
                                    <p class="mt-1 text-[11px] leading-4 text-slate-400">Déclarés par le patient, sans action sur le stock.</p>

                                    <!-- Ce que le dossier sait déjà, montré
                                         avant toute saisie : on ne redemande
                                         jamais de ressaisir un traitement
                                         connu. -->
                                    <div v-if="habitualTreatments.length" class="mt-3 rounded-md border border-gray-200 bg-gray-50/60 p-2.5 dark:border-gray-800 dark:bg-gray-1000/40">
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Traitements connus du dossier</p>
                                        <ul class="mt-1.5 space-y-1">
                                            <li v-for="treatment in habitualTreatments" :key="treatment.uuid" class="text-[11px] leading-4 text-slate-600 dark:text-slate-300">
                                                <strong class="font-semibold text-slate-700 dark:text-white">{{ treatment.medication_name }}</strong>
                                                <template v-if="treatment.dosage || treatment.frequency"> · {{ [treatment.dosage, treatment.frequency].filter(Boolean).join(' · ') }}</template>
                                            </li>
                                        </ul>

                                        <div class="mt-2.5 border-t border-gray-200 pt-2.5 dark:border-gray-800">
                                            <p class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">Le patient signale-t-il un changement ?</p>
                                            <ClinicalSegmentedChoice
                                                class="mt-1.5"
                                                name="known_treatment_change"
                                                :model-value="interviewForm.known_treatment_change"
                                                :options="TREATMENT_CHANGE_OPTIONS"
                                                :disabled="!capabilities.can_update_consultation"
                                                @update:model-value="setKnownTreatmentChange"
                                            />
                                            <div v-if="interviewForm.known_treatment_change === 'YES'" class="mt-2">
                                                <label for="known_treatment_change_notes" class="mb-1 block text-[11px] font-semibold text-slate-600 dark:text-slate-300">Modification rapportée <span class="text-red-500">*</span></label>
                                                <textarea id="known_treatment_change_notes" v-model="interviewForm.known_treatment_change_notes" rows="3" maxlength="2000" :disabled="!capabilities.can_update_consultation" :class="textareaClass" placeholder="Ex. le patient indique avoir arrêté l’Amlodipine depuis 2 semaines…" />
                                                <FormError :message="interviewForm.errors.known_treatment_change_notes" />
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center gap-2.5">
                                        <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">Traitements en cours ?</span>
                                        <span class="inline-flex rounded border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950" role="radiogroup" aria-label="Le patient a-t-il des traitements en cours ?">
                                            <button type="button" role="radio" :aria-checked="hasCurrentTreatments" :disabled="!capabilities.can_update_consultation" :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', hasCurrentTreatments ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']" @click="setHasCurrentTreatments(true)">Oui</button>
                                            <button type="button" role="radio" :aria-checked="!hasCurrentTreatments" :disabled="!capabilities.can_update_consultation" :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', !hasCurrentTreatments ? 'bg-gray-200 text-slate-700 dark:bg-gray-800 dark:text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']" @click="setHasCurrentTreatments(false)">Non</button>
                                        </span>
                                    </div>

                                    <div v-if="hasCurrentTreatments" class="mt-3">
                                        <ClinicalTreatmentList
                                            :treatments="interviewForm.current_treatments"
                                            :disabled="!capabilities.can_update_consultation"
                                            :error-for="treatmentError"
                                            @add="addCurrentTreatment"
                                            @remove="removeCurrentTreatment"
                                            @update="updateCurrentTreatment"
                                        />
                                    </div>
                                    <p v-else class="mt-3 text-[11px] leading-4 text-slate-400">
                                        Aucun traitement déclaré par le patient pour cette consultation.
                                    </p>
                                </section>

                                <ClinicalReportedInformation
                                    :allergies="interviewForm.reported_allergies"
                                    :antecedents="interviewForm.reported_antecedents"
                                    :habitual-treatments="interviewForm.reported_habitual_treatments"
                                    :disabled="!capabilities.can_update_consultation"
                                    :can-promote="capabilities.can_manage_medical_history"
                                    :errors="interviewForm.errors"
                                    @add="addReportedInformation"
                                    @remove="removeReportedInformation"
                                    @update="updateReportedInformation"
                                />
                            </div>
                        </div>



                        <ConsultationStepBar
                            :orientation-uuid="orientation.uuid"
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
                <Card v-if="cardIsOpen('examen')" class="w-full overflow-clip border-s-4 border-s-primary-500 shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"><Icon class="text-xl" name="plus-medi" /></span>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-primary-600 dark:text-primary-300">Étape 3 · Examen du médecin</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-800 dark:text-white">Examen clinique</h2>
                                <p class="mt-0.5 text-xs text-slate-400">Consignez les constatations observées par le médecin, distinctes du récit du patient.</p>
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
                                <!-- La conclusion de la rencontre, posée ici
                                     quand elle peut l'être. « Pas maintenant »
                                     laisse l'étape Diagnostic ouverte : un
                                     médecin qui attend des résultats ne doit
                                     jamais être poussé à conclure avant. -->
                                <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-900 sm:p-5">
                                    <h3 class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Diagnostic</h3>

                                    <div class="mt-2 flex flex-wrap items-center gap-2.5">
                                        <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">Le diagnostic peut-il être posé maintenant ?</span>
                                        <span class="inline-flex rounded border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950" role="radiogroup" aria-label="Le diagnostic peut-il être posé maintenant ?">
                                            <button type="button" role="radio" :aria-checked="clinicalExamForm.diagnosis_ready === false" :disabled="!capabilities.can_update_consultation" :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', clinicalExamForm.diagnosis_ready === false ? 'bg-gray-200 text-slate-700 dark:bg-gray-800 dark:text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']" @click="clinicalExamForm.diagnosis_ready = false">Pas maintenant</button>
                                            <button type="button" role="radio" :aria-checked="clinicalExamForm.diagnosis_ready === true" :disabled="!capabilities.can_update_consultation" :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', clinicalExamForm.diagnosis_ready === true ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']" @click="clinicalExamForm.diagnosis_ready = true">Oui</button>
                                        </span>
                                    </div>

                                    <p v-if="clinicalExamForm.diagnosis_ready === null" class="mt-3 text-[11px] leading-4 text-slate-400">
                                        Répondez pour poursuivre : « Oui » conclut ici, « Pas maintenant » garde l’étape Diagnostic ouverte.
                                    </p>

                                    <p v-else-if="clinicalExamForm.diagnosis_ready === false" class="mt-3 flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                                        <Icon name="clock" class="text-xs" />Diagnostic différé. L’étape Diagnostic reste ouverte pour y revenir.
                                    </p>

                                    <template v-else>
                                        <ul v-if="activeDiagnoses.length" class="mt-3 space-y-1.5">
                                            <li v-for="diagnosis in activeDiagnoses" :key="diagnosis.id" class="flex items-start justify-between gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 dark:border-gray-800 dark:bg-gray-950">
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-xs font-bold text-slate-700 dark:text-white">{{ diagnosis.description }}</span>
                                                    <span class="mt-0.5 block truncate text-[10px] text-slate-400">
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
                                                    <button v-if="diagnosis.can_edit" type="button" class="flex size-7 items-center justify-center rounded border border-gray-200 text-slate-400 transition-colors hover:border-primary-300 hover:text-primary-600 dark:border-gray-800" :title="`Modifier « ${diagnosis.description} »`" :aria-label="`Modifier le diagnostic ${diagnosis.description}`" @click="startDiagnosisEdit(diagnosis)"><Icon name="edit" class="text-sm" /></button>
                                                    <button v-if="diagnosis.can_cancel" type="button" class="flex size-7 items-center justify-center rounded border border-gray-200 text-slate-400 transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600 dark:border-gray-800 dark:hover:border-red-900 dark:hover:bg-red-950/30" :title="`Annuler « ${diagnosis.description} »`" :aria-label="`Annuler le diagnostic ${diagnosis.description}`" :disabled="diagnosisCancellationForm.processing" @click="openDiagnosisCancellation(diagnosis)"><Icon name="trash" class="text-sm" /></button>
                                                </span>
                                            </li>
                                        </ul>

                                        <div class="mt-3">
                                            <ClinicalDiagnosisEntry
                                                :orientation-uuid="orientation.uuid"
                                                return-step="examen"
                                                :disabled="!capabilities.can_create_diagnosis"
                                                compact
                                            />
                                        </div>

                                        <p class="mt-2 text-[11px] leading-4 text-slate-400">
                                            Seul l’auteur d’une saisie peut la corriger ou l’annuler ; un diagnostic enregistré n’est jamais réécrit, et l’historique complet reste dans « Contexte clinique ».
                                        </p>
                                    </template>

                                    <FormError class="mt-1" :message="clinicalExamForm.errors.diagnosis_ready" />
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
                                <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-900">
                                    <label for="clinical_exam" class="block text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Notes cliniques complémentaires</label>
                                    <p class="mt-1 text-[11px] leading-4 text-slate-400">Consignez ici les constatations qui ne sont pas couvertes par les rubriques précédentes. Facultatif.</p>
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

                <Card v-if="cardIsOpen('paraclinique')" class="w-full overflow-clip border-s-4 border-s-primary-500 shadow-sm">
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

                    <!-- La question qui gouverne l'étape est posée au début de
                         l'étape qu'elle gouverne. « Non » la marque non
                         nécessaire et mène au diagnostic, sans faire traverser
                         un écran vide. -->
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">Des examens complémentaires sont-ils nécessaires ?</span>
                            <span class="inline-flex rounded border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950" role="radiogroup" aria-label="Des examens complémentaires sont-ils nécessaires ?">
                                <button type="button" role="radio" :aria-checked="complementaryExamsForm.required === false" :disabled="!capabilities.can_update_consultation || complementaryExamsForm.processing" :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', complementaryExamsForm.required === false ? 'bg-gray-200 text-slate-700 dark:bg-gray-800 dark:text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']" @click="decideComplementaryExams(false)">Non</button>
                                <button type="button" role="radio" :aria-checked="complementaryExamsForm.required === true" :disabled="!capabilities.can_update_consultation || complementaryExamsForm.processing" :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', complementaryExamsForm.required === true ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']" @click="decideComplementaryExams(true)">Oui</button>
                            </span>
                        </div>
                        <p v-if="complementaryExamsForm.required === null" class="mt-2 text-[11px] leading-4 text-slate-400">
                            Répondez pour poursuivre : « Non » déclare l’étape non nécessaire et passe au diagnostic.
                        </p>
                        <FormError class="mt-1" :message="complementaryExamsForm.errors.required" />
                    </div>

                    <div v-if="complementaryExamsForm.required !== false" class="border-b border-gray-200 bg-gray-50/60 px-5 py-2.5 dark:border-gray-900 dark:bg-gray-1000/20">
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



                    <ConsultationStepBar
                        :orientation-uuid="orientation.uuid"
                        step-key="paraclinique"
                        :state="stepState('paraclinique')"
                        :previous="{ key: 'examen', label: 'Examen clinique' }"
                        :next="{ key: 'ordonnance', label: 'Prescription' }"
                        :can-edit="capabilities.can_resolve_step"
                        :local-blocker="hasPendingParaclinicalSelection ? 'Enregistrez les examens sélectionnés avant de valider cette étape.' : null"
                    >
                        <template #note>
                            <p v-if="hasPendingParaclinicalSelection" class="text-center text-xs font-semibold text-amber-700 dark:text-amber-300">Enregistrez les examens sélectionnés avant de poursuivre.</p>
                            <p v-else-if="hasExistingParaclinicalRequest" class="text-center text-xs text-slate-400">Les demandes enregistrées restent suivies dans le dossier du patient.</p>
                            <p v-else class="text-center text-xs text-slate-400">Étape facultative · prescrivez un examen uniquement lorsqu’il est indiqué.</p>
                        </template>
                        <template #actions>
                            <Button v-if="paracliniqueTab === 'lab' && labRequestForm.items.length" type="submit" form="lab-request-form" size="rg" :disabled="labRequestForm.processing || imagingRequestForm.processing">
                                <Icon class="me-2 text-lg" name="activity" />{{ imagingRequestForm.items.length ? 'Envoyer puis finaliser l’imagerie' : 'Envoyer au Laboratoire' }}
                            </Button>
                            <Button v-else-if="paracliniqueTab === 'imaging' && imagingRequestForm.items.length" type="submit" form="imaging-request-form" size="rg" :disabled="imagingRequestForm.processing || labRequestForm.processing">
                                <Icon class="me-2 text-lg" name="activity" />{{ labRequestForm.items.length ? 'Envoyer puis finaliser les analyses' : 'Envoyer la demande' }}
                            </Button>
                            <Button v-else-if="labRequestForm.items.length" type="button" size="rg" variant="white-outline" @click="paracliniqueTab = 'lab'">Finaliser les analyses ({{ labRequestForm.items.length }})</Button>
                            <Button v-else-if="imagingRequestForm.items.length" type="button" size="rg" variant="white-outline" @click="paracliniqueTab = 'imaging'">Finaliser l’imagerie ({{ imagingRequestForm.items.length }})</Button>
                        </template>
                    </ConsultationStepBar>
                </Card>

                <!-- §24 — la prescription ne décide plus de la suite : si une
                     orientation existe déjà, elle est rappelée ici ; sinon un
                     simple bouton mène à la carte qui la porte. Les six
                     grosses cartes ont quitté cette étape. -->
                <Card v-if="cardIsOpen('ordonnance')" class="overflow-hidden border-s-4 border-s-primary-500 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"><Icon name="share" /></span>
                            <div class="min-w-0">
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Orientation actuelle</p>
                                <p v-if="activeOrientation" class="mt-0.5 flex flex-wrap items-center gap-2 text-sm font-bold text-slate-700 dark:text-white">
                                    {{ activeOrientation.type_label }}
                                    <span
                                        :class="[
                                            'rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                                            activeOrientation.status === 'SUBMITTED'
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200'
                                                : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
                                        ]"
                                    >{{ activeOrientation.status_label }}</span>
                                </p>
                                <p v-else class="mt-0.5 text-sm text-slate-500 dark:text-slate-300">Pas encore déterminée — elle peut l’être ici ou après la prescription.</p>
                            </div>
                        </div>
                        <Button
                            :as="Link"
                            :href="`/medicine/orientations/${orientation.uuid}/examen`"
                            type="button"
                            size="sm"
                            variant="white-outline"
                        >
                            <Icon class="me-1.5 text-sm" name="edit" />{{ activeOrientation ? 'Modifier' : 'Définir la suite de la prise en charge' }}
                        </Button>
                    </div>
                </Card>

                <div v-if="cardIsOpen('ordonnance') && capabilities.can_view_care_orders" class="grid grid-cols-2 gap-1 rounded-lg border border-gray-200 bg-gray-100 p-1 shadow-sm dark:border-gray-800 dark:bg-gray-900" role="tablist" aria-label="Type de prescription">
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

                <Card v-if="cardIsOpen('ordonnance') && capabilities.can_view_care_orders && prescriptionTab === 'care'" class="w-full overflow-hidden border-s-4 border-s-primary-500 shadow-sm">
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
                                <button v-for="item in filteredCareOrderCatalog" :key="item.uuid" type="button" :disabled="isCareOrderItemSelected(item) || isCareOrderItemPending(item)" class="flex w-full items-center justify-between gap-4 px-3 py-2.5 text-start transition-colors hover:bg-gray-50 disabled:cursor-default disabled:bg-gray-50/70 dark:hover:bg-gray-1000 dark:disabled:bg-gray-1000/60" @click="addCareOrderItem(item)">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-slate-700 dark:text-white">{{ item.name }}</span>
                                        <span v-if="item.code" class="mt-0.5 block font-mono text-[10px] text-slate-400">{{ item.code }}</span>
                                    </span>
                                    <span v-if="isCareOrderItemPending(item)" class="inline-flex shrink-0 items-center gap-1 text-[11px] font-semibold text-amber-700 dark:text-amber-300"><Icon name="clock" />Déjà en attente aux Soins</span>
                                    <span v-else-if="isCareOrderItemSelected(item)" class="inline-flex shrink-0 items-center gap-1 text-[11px] font-semibold text-primary-700 dark:text-primary-300"><Icon name="check" />Ajouté</span>
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

                <Card v-if="cardIsOpen('ordonnance') && prescriptionTab === 'medicines'" class="w-full overflow-hidden border-s-4 border-s-primary-500 shadow-sm">
                    <!-- Repliée par défaut : ce qui est déjà prescrit se résume en
                         une ligne, et l'écran laisse la place à la nouvelle
                         ordonnance qui se prépare en dessous. -->
                    <div :class="['flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between', prescriptionListOpen ? 'border-b border-gray-200 dark:border-gray-900' : '']">
                        <button
                            type="button"
                            class="flex min-w-0 items-center gap-2.5 text-start"
                            :aria-expanded="prescriptionListOpen"
                            @click="prescriptionListOpen = !prescriptionListOpen"
                        >
                            <Icon class="shrink-0 text-sm text-slate-400" :name="prescriptionListOpen ? 'chevron-up' : 'chevron-down'" />
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-slate-700 dark:text-white">Prescription — Médicaments</span>
                                <span class="mt-0.5 block truncate text-xs text-slate-400">
                                    <template v-if="prescribedLineCount">{{ prescribedLineCount }} médicament{{ prescribedLineCount > 1 ? 's' : '' }} prescrit{{ prescribedLineCount > 1 ? 's' : '' }} · </template>Le stock disponible exclut les lots périmés et les quantités déjà réservées.
                                </span>
                            </span>
                        </button>
                        <span v-if="capabilities.can_view_pharmacy_availability" class="inline-flex w-fit items-center gap-1.5 rounded border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs font-semibold text-slate-500 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Stock Pharmacie en lecture seule
                        </span>
                    </div>
                    <div v-if="consultation.prescriptions.length && prescriptionListOpen" class="overflow-x-auto">
                        <table class="w-full min-w-[980px] text-sm">
                            <thead class="border-b border-gray-200 bg-gray-50/70 dark:border-gray-900 dark:bg-gray-1000/40">
                                <tr class="text-left text-[10px] uppercase tracking-wide text-slate-400">
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
                                        <td class="px-4 py-3 text-slate-500">
                                            <span v-if="line.route_label">{{ line.route_label }}</span>
                                            <span v-else class="text-slate-300 dark:text-slate-600" title="La voie n’a pas été précisée sur cette ligne">Non précisée</span>
                                        </td>
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
                    <p v-else-if="!consultation.prescriptions.length" class="px-5 py-6 text-sm text-slate-400">Aucune ordonnance active.</p>

                    <form v-if="capabilities.can_create_prescription" id="medicine-prescription-form" class="border-t border-gray-200 bg-gray-50/50 p-5 dark:border-gray-900 dark:bg-gray-1000/30" @submit.prevent="addPrescription">
                        <div class="mb-4">
                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">Nouvelle ordonnance</h3>
                            <p class="mt-1 text-xs text-slate-400">Sélectionnez uniquement un médicament réellement disponible. La validation réserve la quantité ; la délivrance reste à la Pharmacie.</p>
                        </div>

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
                            </template>
                            <template #end>
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

                                        <PrescriptionLineEditor
                                            :line="line"
                                            :routes="options.administration_routes"
                                            :quantity-unit="line.manual ? 'unité(s)' : (medicineForLine(line)?.unit ?? 'unité(s)')"
                                            :error-for="(field) => prescriptionForm.errors[`lines.${index}.${field}`]"
                                            @update="updatePrescriptionLine(index, $event)"
                                        />
                                        <p v-if="!line.manual" class="mt-1 text-[11px] text-slate-400">Disponible en Pharmacie : {{ medicineForLine(line)?.available_quantity }} {{ medicineForLine(line)?.unit }}</p>
                                        <p v-else class="mt-1 text-[11px] text-slate-400">Sans lien de stock — non suivi par la Pharmacie.</p>
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
                            </template>
                        </ResizableSplit>

                        <div class="mt-4 flex items-start gap-2 border-t border-gray-200 pt-4 text-xs text-slate-400 dark:border-gray-900">
                            <Icon class="mt-0.5 shrink-0 text-base text-primary-600" name="shield-check" />
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
                <Card v-if="cardIsOpen('cloture')" class="w-full overflow-hidden border-s-4 border-s-primary-500 shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Décision & clôture</h2>
                        <p class="mt-1 text-xs text-slate-400">La clôture ne réalise aucun encaissement et ne ferme pas le passage administratif.</p>
                    </div>

                    <div class="p-5">
                        <!-- ADR-089 — un seul endroit pour conclure le passage :
                             diagnostic, conduite à tenir et vérification, de haut
                             en bas, sans jamais renvoyer le médecin ailleurs. -->
                        <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">1 · Diagnostic</h3>
                                <span v-if="activeDiagnoses.length" class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300"><Icon class="text-sm" name="check-circle" />{{ activeDiagnoses.length }} enregistré{{ activeDiagnoses.length > 1 ? 's' : '' }}</span>
                            </div>
                            <ul v-if="activeDiagnoses.length" class="mt-3 space-y-1.5">
                                <li v-for="diagnosis in activeDiagnoses" :key="diagnosis.id" class="rounded-md border border-gray-200 bg-white px-3 py-2 dark:border-gray-800 dark:bg-gray-950">
                                    <span class="block truncate text-xs font-bold text-slate-700 dark:text-white">{{ diagnosis.description }}</span>
                                    <span class="mt-0.5 block truncate text-[10px] text-slate-400">{{ diagnosis.recorded_by }} · {{ formatDateTime(diagnosis.recorded_at) }}</span>
                                </li>
                            </ul>
                            <p v-else class="mt-1 text-[11px] leading-4 text-slate-400">Aucun diagnostic encore posé : consignez la conclusion clinique de ce passage.</p>
                            <div class="mt-3">
                                <ClinicalDiagnosisEntry
                                    :orientation-uuid="orientation.uuid"
                                    return-step="cloture"
                                    :disabled="!capabilities.can_create_diagnosis"
                                    compact
                                />
                            </div>
                        </section>

                        <section class="mt-4">
                            <h3 class="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">2 · Conduite à tenir</h3>
                            <ClinicalOrientationCard
                                :orientation-uuid="orientation.uuid"
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
                                        :disabled="!capabilities.can_discharge"
                                        :cancellable="false"
                                        @submit="submitDischarge"
                                    />
                                    <p v-else class="text-[11px] text-emerald-700 dark:text-emerald-300">Sortie médicale déjà prononcée — son détail figure ci-dessous.</p>
                                </template>
                            </ClinicalOrientationCard>
                        </section>

                        <h3 class="mb-2 mt-4 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">3 · Vérification</h3>
                        <!-- Ce qui manque est dit ici, avec le chemin pour y
                             revenir — jamais un bouton grisé sans explication. -->
                        <div v-if="closureBlockers.length" class="mt-3 rounded-md border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-900 dark:bg-amber-950/20">
                            <p class="mb-1.5 flex items-center gap-2 text-xs font-bold text-amber-800 dark:text-amber-200">
                                <Icon class="text-sm" name="alert-circle" />À compléter avant la clôture
                            </p>
                            <ul class="space-y-1 ps-6 text-[11px] text-amber-800 dark:text-amber-200">
                                <li v-for="(blocker, index) in closureBlockers" :key="index" class="list-disc">{{ blocker }}</li>
                            </ul>
                        </div>

                        <p v-else class="mt-3 flex items-center gap-2 text-[11px] text-emerald-700 dark:text-emerald-300">
                            <Icon class="text-sm" name="check-circle" />Tout est en place : la consultation peut être clôturée.
                        </p>
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

                </Card>

                <Card v-if="current_step === 'ordonnance'" class="sticky bottom-3 z-20 overflow-hidden border-gray-300 shadow-lg dark:border-gray-800">
                    <ConsultationStepBar
                        :floating="false"
                        class="border-t-0 bg-white dark:bg-gray-950"
                        :orientation-uuid="orientation.uuid"
                        :step-key="current_step"
                        :state="currentStepState"
                        :previous="previousStep ? { key: previousStep.key, label: previousStep.label } : null"
                        :next="nextStep ? { key: nextStep.key, label: nextStep.label } : null"
                        :can-edit="capabilities.can_resolve_step"
                        :local-blocker="pendingPrescriptionSelection"
                    >
                        <template v-if="current_step === 'ordonnance'" #note>
                            <p v-if="prescriptionForm.lines.length" class="text-center text-xs font-semibold text-slate-500 dark:text-slate-300">{{ prescriptionForm.lines.length }} médicament(s) à valider avant de poursuivre</p>
                            <p v-else-if="careOrderForm.items.length" class="text-center text-xs font-semibold text-slate-500 dark:text-slate-300">{{ careOrderForm.items.length }} acte(s) à transmettre aux Soins</p>
                            <p v-else class="text-center text-xs text-slate-400">Prescription selon indication médicale.</p>
                        </template>
                        <template v-if="current_step === 'ordonnance'" #actions>
                            <Button v-if="prescriptionTab === 'medicines' && prescriptionForm.lines.length" type="submit" form="medicine-prescription-form" size="rg" :disabled="prescriptionForm.processing || !prescriptionStockIsValid"><Icon class="me-2 text-lg" name="file-text" />{{ prescriptionForm.processing ? 'Validation en cours…' : 'Valider et réserver' }}</Button>
                            <Button v-else-if="prescriptionForm.lines.length" type="button" size="rg" variant="white-outline" @click="prescriptionTab = 'medicines'">Finaliser la prescription ({{ prescriptionForm.lines.length }})</Button>
                            <Button v-else-if="prescriptionTab === 'care' && careOrderForm.items.length" type="submit" form="care-order-form" size="rg" :disabled="careOrderForm.processing"><Icon class="me-2 text-lg" name="activity" />{{ careOrderForm.processing ? 'Transmission en cours…' : 'Transmettre aux Soins' }}</Button>
                            <Button v-else-if="careOrderForm.items.length" type="button" size="rg" variant="white-outline" @click="prescriptionTab = 'care'">Finaliser la demande Soins ({{ careOrderForm.items.length }})</Button>
                        </template>
                    </ConsultationStepBar>
                </Card>

                <!-- La clôture est un acte à part : elle verrouille la
                     consultation en lecture seule (ADR-010) et exige que
                     chaque étape concernée soit résolue — validée ou
                     déclarée non nécessaire. -->
                <Card v-else-if="current_step === 'cloture'" class="sticky bottom-3 z-20 overflow-hidden border-gray-300 shadow-lg dark:border-gray-800">
                    <div class="flex flex-col gap-3 bg-white px-4 py-3 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between">
                        <Button v-if="previousStep" :as="Link" :href="stepUrl(previousStep.key)" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />{{ previousStep.label }}</Button>
                        <Button v-else :as="Link" href="/medicine" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Retour à la file</Button>

                        <div class="min-w-0 flex-1 text-center">
                            <FormError :message="completeConsultationForm.errors.consultation" />
                            <p v-if="consultationIsClosed" class="flex items-center justify-center gap-1.5 text-xs font-semibold text-emerald-600 dark:text-emerald-300">
                                <Icon name="check-circle" class="text-sm" />Consultation clôturée<template v-if="consultation.completed_by"> par {{ consultation.completed_by }}</template>
                            </p>
                            <ul v-else-if="closureBlockers.length" class="space-y-0.5 text-xs text-slate-400">
                                <li v-for="blocker in closureBlockers" :key="blocker">{{ blocker }}</li>
                            </ul>
                            <p v-else class="text-xs text-slate-400">Toutes les étapes sont résolues : la consultation peut être clôturée.</p>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <Button v-for="prescription in consultation.prescriptions" :key="prescription.uuid" :as="Link" :href="`/medicine/orientations/${orientation.uuid}/prescriptions/${prescription.uuid}/print`" target="_blank" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="printer" />Imprimer l’ordonnance</Button>
                            <Button
                                v-if="capabilities.can_complete_consultation"
                                type="button"
                                size="rg"
                                variant="success"
                                :disabled="closureBlockers.length > 0 || completeConsultationForm.processing"
                                @click="completeConsultation"
                            >
                                <Icon class="me-2 text-lg" name="check-circle" />{{ completeConsultationForm.processing ? 'Clôture…' : 'Clôturer la consultation' }}
                            </Button>
                            <Button v-else :as="Link" href="/medicine" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="list" />Retour à la file</Button>
                        </div>
                    </div>
                </Card>
        </main>

        <!-- Les informations secondaires ne redimensionnent jamais la zone de
             consultation. Elles s'ouvrent dans une fenêtre dédiée et fermable
             au clavier, en conservant les vigilances dans l'en-tête principal. -->
        <Dialog :open="showClinicalContext" as="div" class="relative z-[1300]" @close="showClinicalContext = false">
            <div class="fixed inset-0 bg-slate-950/55 backdrop-blur-[1px]" aria-hidden="true"></div>
            <div class="fixed inset-0 overflow-y-auto p-3 sm:p-5">
                <div class="flex min-h-full items-center justify-center">
                    <DialogPanel v-if="showClinicalContext" id="medicine-clinical-context" class="flex max-h-[calc(100vh-1.5rem)] w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-gray-50 shadow-2xl dark:border-gray-800 dark:bg-gray-1000 sm:max-h-[calc(100vh-3rem)]">
                        <header class="flex shrink-0 items-center justify-between gap-4 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-950">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"><Icon class="text-base" name="file-docs" /></span>
                                <div class="min-w-0">
                                    <DialogTitle class="font-heading text-base font-bold text-slate-700 dark:text-white">Contexte clinique</DialogTitle>
                                    <p class="mt-0.5 truncate text-xs text-slate-400">{{ patient.first_name }} {{ patient.last_name }} · {{ patient.patient_number }} · Passage {{ episode.episode_number }}</p>
                                </div>
                            </div>
                            <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-200 bg-white text-slate-400 transition-colors hover:bg-gray-100 hover:text-slate-700 dark:border-gray-800 dark:bg-gray-950 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer le contexte clinique" @click="showClinicalContext = false"><Icon class="text-xl" name="cross" /></button>
                        </header>

                        <aside class="min-h-0 flex-1 space-y-3 overflow-y-auto p-3" style="scrollbar-gutter: stable;" aria-label="Informations cliniques complémentaires">
                            <CareSummaryReadOnly v-if="care_record" :care-summary="care_record" default-open compact />
                            <section v-else class="rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                                <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2.5 dark:border-gray-900"><Icon name="activity" class="text-primary-600" /><h2 class="text-xs font-bold text-slate-700 dark:text-white">Transmission des Soins</h2></div>
                                <p class="px-3 py-3 text-xs leading-5 text-slate-400">{{ orientation.source_module === 'CARE' ? 'Le patient est passé par les Soins, mais aucune fiche n’a été enregistrée pour ce passage.' : 'Orientation directe : aucune transmission des Soins pour ce passage.' }}</p>
                            </section>

                            <!-- Le fil diagnostique complet, en lecture seule.
                                 Un diagnostic annulé reste visible avec son
                                 auteur et sa date : la trace n'est jamais un
                                 trou dans le dossier (ADR-035). -->
                            <section v-if="allDiagnoses.length" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                                <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2.5 dark:border-gray-900">
                                    <div class="flex items-center gap-2"><Icon name="clipboad-check" class="text-primary-600" /><h2 class="text-xs font-bold text-slate-700 dark:text-white">Fil diagnostique</h2></div>
                                    <span class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Cette consultation</span>
                                </div>
                                <ol class="divide-y divide-gray-100 dark:divide-gray-900">
                                    <li v-for="diagnosis in allDiagnoses" :key="`ctx-${diagnosis.id}`" class="px-3 py-2.5">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span :class="['rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide', diagnosis.type === 'FINAL' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300']">{{ diagnosis.type === 'FINAL' ? 'Diagnostic final' : 'Hypothèse' }}</span>
                                            <span v-if="diagnosis.cancelled" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500 dark:bg-gray-900 dark:text-slate-400">Annulé</span>
                                            <span v-if="diagnosis.code" class="font-mono text-[10px] text-slate-400">{{ diagnosis.code }}</span>
                                        </div>
                                        <p :class="['mt-1 text-xs leading-5', diagnosis.cancelled ? 'text-slate-400 line-through' : 'font-semibold text-slate-700 dark:text-white']">{{ diagnosis.description }}</p>
                                        <p class="mt-0.5 text-[10px] text-slate-400">{{ diagnosis.recorded_by }} · {{ formatDateTime(diagnosis.recorded_at) }}</p>
                                        <p v-if="diagnosis.cancelled" class="mt-0.5 text-[10px] text-slate-400">Annulé par {{ diagnosis.cancelled_by }} · {{ formatDateTime(diagnosis.cancelled_at) }}</p>
                                    </li>
                                </ol>
                            </section>

                            <!-- L'examen déjà consigné, relisible depuis n'importe
                                 quelle étape suivante sans rouvrir la grille. -->
                            <section v-if="current_step !== 'examen'" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                                <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2.5 dark:border-gray-900">
                                    <div class="flex items-center gap-2"><Icon name="plus-medi" class="text-primary-600" /><h2 class="text-xs font-bold text-slate-700 dark:text-white">Examen clinique du médecin</h2></div>
                                    <span class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Cette consultation</span>
                                </div>
                                <div class="px-3 py-3">
                                    <ClinicalExaminationSummary :examination="consultation?.clinical_examination" show-unexamined />
                                    <ClinicalRichTextDisplay v-if="consultation?.clinical_exam" class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-900" :html="consultation.clinical_exam" />
                                </div>
                            </section>

                            <div class="grid gap-3 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
                                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                                    <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2.5 dark:border-gray-900">
                                        <div class="flex items-center gap-2"><Icon name="user" class="text-slate-400" /><h2 class="text-xs font-bold text-slate-700 dark:text-white">Profil médical permanent</h2></div>
                                        <span class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Dossier patient</span>
                                    </div>
                                    <dl class="divide-y divide-gray-100 dark:divide-gray-900">
                                        <div class="grid gap-2 px-3 py-2.5 sm:grid-cols-[9rem_1fr]">
                                            <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Allergies</dt>
                                            <dd><div v-if="allergies.length" class="flex flex-wrap gap-1.5"><span v-for="allergy in allergies" :key="allergy.uuid" class="rounded border border-red-100 bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 dark:border-red-950 dark:bg-red-950/20 dark:text-red-300">{{ allergy.substance }}<template v-if="allergy.reaction"> · {{ allergy.reaction }}</template></span></div><span v-else class="text-xs text-slate-400">Aucune allergie enregistrée</span></dd>
                                        </div>
                                        <div class="grid gap-2 px-3 py-2.5 sm:grid-cols-[9rem_1fr]">
                                            <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Antécédents personnels</dt>
                                            <dd><ul v-if="personalAntecedents.length" class="space-y-1"><li v-for="antecedent in personalAntecedents" :key="antecedent.uuid" class="text-xs leading-5 text-slate-600 dark:text-slate-300">{{ antecedent.description }}</li></ul><span v-else class="text-xs text-slate-400">Aucun enregistré</span></dd>
                                        </div>
                                        <div class="grid gap-2 px-3 py-2.5 sm:grid-cols-[9rem_1fr]">
                                            <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Antécédents familiaux</dt>
                                            <dd><ul v-if="familial_antecedents?.length" class="space-y-1"><li v-for="antecedent in familial_antecedents" :key="antecedent.uuid" class="text-xs leading-5 text-slate-600 dark:text-slate-300">{{ antecedent.description }}</li></ul><span v-else class="text-xs text-slate-400">Aucun enregistré</span></dd>
                                        </div>
                                        <div v-if="hasEmergencyContact" class="grid gap-2 px-3 py-2.5 sm:grid-cols-[9rem_1fr]">
                                            <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Contact du passage</dt>
                                            <dd class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600 dark:text-slate-300"><strong class="text-slate-700 dark:text-white">{{ episode.emergency_contact.name || 'Nom non renseigné' }}</strong><span v-if="episode.emergency_contact.relationship" class="text-slate-400">{{ episode.emergency_contact.relationship }}</span><a v-if="episode.emergency_contact.phone" :href="`tel:${episode.emergency_contact.phone}`" class="text-primary-600 hover:underline dark:text-primary-300">{{ episode.emergency_contact.phone }}</a><span v-if="episode.emergency_contact.email">{{ episode.emergency_contact.email }}</span></dd>
                                        </div>
                                    </dl>
                                    <details v-if="capabilities.can_manage_medical_history" class="group border-t border-gray-200 dark:border-gray-900">
                                        <summary class="flex cursor-pointer list-none items-center justify-between px-3 py-2.5 text-xs font-semibold text-primary-700 hover:bg-gray-50 dark:text-primary-300 dark:hover:bg-gray-1000/40"><span class="flex items-center gap-1.5"><Icon name="plus" />Ajouter un antécédent</span><Icon name="chevron-down" class="transition-transform group-open:rotate-180" /></summary>
                                        <form class="grid gap-2 border-t border-gray-100 bg-gray-50/50 p-3 dark:border-gray-900 dark:bg-gray-1000/20" @submit.prevent="submitAntecedent">
                                            <textarea v-model="antecedentForm.description" rows="2" placeholder="Description clinique…" :class="textareaClass" />
                                            <FormError :message="antecedentForm.errors.description || antecedentForm.errors.type" />
                                            <div class="flex flex-wrap items-center justify-between gap-2"><span class="inline-flex rounded border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950"><button type="button" :class="['rounded px-2.5 py-1 text-[11px] font-semibold', antecedentForm.type === 'PERSONAL' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400']" @click="antecedentForm.type = 'PERSONAL'">Personnel</button><button type="button" :class="['rounded px-2.5 py-1 text-[11px] font-semibold', antecedentForm.type === 'FAMILIAL' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400']" @click="antecedentForm.type = 'FAMILIAL'">Familial</button></span><Button type="submit" size="sm" :disabled="antecedentForm.processing || !antecedentForm.description.trim()">Ajouter</Button></div>
                                        </form>
                                    </details>
                                </section>

                                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                                    <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2.5 dark:border-gray-900"><div class="flex items-center gap-2"><Icon name="history" class="text-slate-400" /><h2 class="text-xs font-bold text-slate-700 dark:text-white">Consultations précédentes</h2></div><span class="rounded bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-slate-500 dark:bg-gray-900">{{ previous_consultations?.length ?? 0 }}</span></div>
                                    <ul v-if="previous_consultations?.length" class="max-h-64 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-900">
                                        <li v-for="previous in previous_consultations" :key="previous.id" class="px-3 py-2.5">
                                            <p class="text-[11px] font-semibold text-slate-700 dark:text-white">{{ formatDateTime(previous.consulted_at) }} · Dr {{ previous.doctor }}</p>
                                            <ClinicalRichTextDisplay class="mt-1 line-clamp-2 text-xs leading-4 text-slate-500 dark:text-slate-300" :html="previous.reason" />
                                            <p v-if="previous.decision_label" class="mt-1 text-[10px] text-slate-400">{{ previous.decision_label }}</p>
                                        </li>
                                    </ul>
                                    <p v-else class="px-3 py-4 text-center text-xs text-slate-400">Aucune consultation antérieure.</p>
                                </section>
                            </div>
                        </aside>
                        <footer class="flex shrink-0 justify-end border-t border-gray-200 bg-white px-4 py-2.5 dark:border-gray-800 dark:bg-gray-950">
                            <Button type="button" size="sm" variant="white-outline" @click="showClinicalContext = false"><Icon class="me-1.5 text-sm" name="cross" />Fermer</Button>
                        </footer>
                    </DialogPanel>
                </div>
            </div>
        </Dialog>

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
