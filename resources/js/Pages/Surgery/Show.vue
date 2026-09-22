<script setup>
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleDot,
    ClipboardList,
    ExternalLink,
    FileText,
    Info,
    Lock,
    LogOut,
    Pencil,
    Plus,
    ShieldCheck,
    Sparkles,
    Stethoscope,
    UserRound,
    Users,
    X,
} from 'lucide-vue-next';
import CareSummaryReadOnly from '@/Components/Surgery/CareSummaryReadOnly.vue';
import ConduiteAnesthesique from '@/Components/Surgery/ConduiteAnesthesique.vue';
import ConsultationPreAnesthesique from '@/Components/Surgery/ConsultationPreAnesthesique.vue';
import EnTeteDossierChirurgical from '@/Components/Surgery/EnTeteDossierChirurgical.vue';
import EntreeBloc from '@/Components/Surgery/EntreeBloc.vue';
import HospitalStayBanner from '@/Components/Surgery/HospitalStayBanner.vue';
import ExamenParaclinique from '@/Components/Surgery/ExamenParaclinique.vue';
import SortieBloc from '@/Components/Surgery/SortieBloc.vue';
import SurgerySection from '@/Components/Surgery/SurgerySection.vue';
import SurgicalConsumables from '@/Components/Surgery/SurgicalConsumables.vue';
import SurgeonScheduler from '@/Components/Surgery/SurgeonScheduler.vue';
import SurgicalRequestCard from '@/Components/Surgery/SurgicalRequestCard.vue';
import ResizableSplit from '@/Components/UI/ResizableSplit.vue';
import ValidationPreoperatoire from '@/Components/Surgery/ValidationPreoperatoire.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, toDatetimeLocalInput } from '@/utilities/date';
import {
    anesthesiaNextAction,
    anesthesiaSteps,
    openingStep,
    statusRank,
    surgeryNextAction,
    surgerySteps,
} from '@/utilities/surgicalWorkflow';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workspace: { type: String, default: 'surgery' },
    surgicalRequest: Object,
    careSummary: { type: Object, default: null },
    /** ADR-160 — le séjour actif du patient, s'il est hospitalisé. */
    hospitalStay: { type: Object, default: null },
    users: Array,
    teamFunctions: Array,
    procedures: { type: Array, default: () => [] },
    anesthesiaItems: { type: Array, default: () => [] },
    /** ADR-169 — matériel du bloc relié au stock de la Pharmacie. */
    consumableRequests: { type: Array, default: () => [] },
    consumableCatalog: { type: Array, default: () => [] },
    consumableSuggestions: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const page = usePage();
const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const isAnesthesiaWorkspace = computed(() => props.workspace === 'anesthesia');
const status = computed(() => props.surgicalRequest.status);
const cancelled = computed(() => status.value === 'CANCELLED');
/** Une demande annulée reste lisible ; plus rien ne s'y enregistre depuis l'écran. */
const writable = computed(() => !cancelled.value);

// --- Parcours : les étapes et la prochaine action viennent du statut réel
// (utilities/surgicalWorkflow), jamais d'un compteur propre à l'écran. ---
const tabs = computed(() => (isAnesthesiaWorkspace.value
    ? anesthesiaSteps(props.surgicalRequest.anesthesia_record)
    : surgerySteps(props.surgicalRequest)));
const next = computed(() => (isAnesthesiaWorkspace.value
    ? anesthesiaNextAction(props.surgicalRequest.anesthesia_record)
    : surgeryNextAction(props.surgicalRequest)));
const activeTab = ref(openingStep(tabs.value, next.value, cancelled.value));
const completedTabs = computed(() => tabs.value.filter((tab) => tab.complete).length);
const currentTabIndex = computed(() => Math.max(0, tabs.value.findIndex((tab) => tab.id === activeTab.value)));
const currentTab = computed(() => tabs.value[currentTabIndex.value]);
const previousTab = computed(() => tabs.value[currentTabIndex.value - 1] ?? null);
const nextTab = computed(() => tabs.value[currentTabIndex.value + 1] ?? null);

const stepState = (tab) => {
    if (cancelled.value) return tab.complete ? 'complete' : 'cancelled';
    if (tab.complete) return 'complete';
    if (tab.id === next.value.step && next.value.key) return 'current';
    if (tab.waiting) return 'waiting';

    return 'open';
};
const STEP_STATE_LABELS = { complete: 'Terminée', current: 'À faire', waiting: 'En attente', open: '', cancelled: '' };

const workspaceMeta = computed(() => (isAnesthesiaWorkspace.value
    ? { label: 'Anesthésie', returnUrl: '/anesthesia', returnLabel: 'Dossiers anesthésie' }
    : { label: 'Chirurgie', returnUrl: '/surgery', returnLabel: 'Dossiers chirurgie' }));

// La prochaine action : qui peut la faire. Le serveur revérifie toujours ; l'écran
// dit seulement à qui s'adresser plutôt que d'afficher un bouton qui refuserait.
const nextAllowed = computed(() => Boolean(next.value.key) && (!next.value.permission || can(next.value.permission)));
const nextDetail = computed(() => {
    if (next.value.key && next.value.permission && !can(next.value.permission)) {
        return `À faire par un compte disposant du droit « ${next.value.permission} ».`;
    }

    return next.value.detail;
});
const NEXT_TONES = {
    primary: 'bg-primary/10 text-primary',
    warning: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    success: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    muted: 'bg-muted text-muted-foreground',
};
const nextIcon = computed(() => ({ success: CheckCircle2, muted: Info }[next.value.tone] ?? Sparkles));

const ACTION_TARGETS = {
    schedule: 'surgery-schedule',
    preoperative: 'surgery-preoperative',
    start: 'surgery-intervention',
    end: 'surgery-intervention',
    'block-exit': 'surgery-block-exit',
    report: 'surgery-report',
    'validate-report': 'surgery-report',
    discharge: 'surgery-discharge',
    consultation: 'anesthesia-consultation',
    assessment: 'anesthesia-paraclinical',
    peroperative: 'anesthesia-peroperative',
};

const selectStep = (id) => { activeTab.value = id; };

// Sur un écran étroit le parcours défile horizontalement : l'étape ouverte
// reste visible. Navigateur uniquement — rien n'est lu pendant le rendu serveur.
const stepNav = ref(null);
const revealActiveStep = () => {
    const nav = stepNav.value;
    const button = nav?.querySelector('[aria-selected="true"]');
    if (!nav || !button || nav.scrollWidth <= nav.clientWidth) return;
    nav.scrollLeft = button.offsetLeft - (nav.clientWidth - button.offsetWidth) / 2;
};
onMounted(revealActiveStep);
watch(activeTab, () => nextTick(revealActiveStep));

const runNextAction = async () => {
    const action = next.value;
    if (!action.key) return;

    activeTab.value = action.step;
    if (action.key === 'schedule') showScheduleForm.value = true;
    if (action.key === 'end') openInterventionEdit();

    await nextTick();
    document.getElementById(ACTION_TARGETS[action.key])?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

// --- Référentiels du formulaire ---
const TEAM_FUNCTION_LABELS = {
    SURGEON: 'Chirurgien',
    ANESTHETIST: 'Anesthésiste',
    OR_NURSE: 'Infirmier de bloc',
    PARAMEDICAL: 'Paramédical',
};
// ADR-168 — un chirurgien entre dans l'équipe par la programmation (profil
// Chirurgien, planning RH), jamais par le formulaire libre de l'équipe.
const teamFunctionOptions = computed(() => props.teamFunctions
    .filter((item) => item.value !== 'SURGEON')
    .map((item) => ({ value: item.value, label: item.label ?? TEAM_FUNCTION_LABELS[item.value] ?? item.value })));
/** Les chirurgiens aides : les membres « Chirurgien » de l'équipe (ADR-168). */
const assistantSurgeons = computed(() => (props.surgicalRequest.team_members ?? []).filter((member) => member.function === 'SURGEON'));
/** L'opérateur se choisit parmi les chirurgiens programmés, principal en tête. */
const operatorOptions = computed(() => [
    ...(props.surgicalRequest.surgeon ? [{ value: String(props.surgicalRequest.surgeon.id), label: `${props.surgicalRequest.surgeon.name} — principal` }] : []),
    ...assistantSurgeons.value
        .filter((member) => member.user && member.user.id !== props.surgicalRequest.surgeon?.id)
        .map((member) => ({ value: String(member.user.id), label: `${member.user.name} — aide` })),
]);
const anesthesiaAssessment = computed(() => props.surgicalRequest.anesthesia_record?.paraclinical_data ?? {});
const surgeryAuthorization = computed(() => {
    if (anesthesiaAssessment.value.surgery_authorized === true) return { label: 'Chirurgie autorisée', variant: 'success' };
    if (anesthesiaAssessment.value.surgery_authorized === false) return { label: 'Chirurgie non autorisée', variant: 'destructive' };

    return { label: 'Décision non renseignée', variant: 'warning' };
});

// `datetime-local` pré-rempli à l'heure locale : la valeur sérialisée est en UTC.
const toDatetimeLocal = toDatetimeLocalInput;

// --- Demande : acte, notes (surgery.update) — SurgicalRequestCard ---

// --- Programmation (surgery.schedule) — also used to CORRECT a scheduling
// mistake (wrong surgeon/date) as long as the intervention hasn't started;
// see SurgicalRequest::schedule()'s own doc comment for why the same
// permission covers both. ---
const canEditSchedule = computed(() => ['PENDING', 'SCHEDULED', 'PREOPERATIVE_VALIDATED'].includes(status.value) && can('surgery.schedule'));
const isAlreadyScheduled = computed(() => Boolean(props.surgicalRequest.surgeon));
const showScheduleForm = ref(false);

// --- Ordre de l'étape Dossier (ADR-048, ADR-168) ---
// La salle et les consignes suivent la programmation : avant, rien à préparer.
const showPreparationBlock = computed(() => isAlreadyScheduled.value
    || Boolean(props.surgicalRequest.operating_room || props.surgicalRequest.preparation_notes));
// L'équipe se compose après la programmation ; une équipe déjà saisie reste visible.
const teamState = computed(() => {
    if (!isAlreadyScheduled.value && !props.surgicalRequest.team_members?.length) return 'waiting';

    return (props.surgicalRequest.team_members?.length ?? 0) > 0 ? 'done' : 'todo';
});
// L'équipe compte le chirurgien principal et les membres ajoutés : la liste montre les deux.
const teamCount = computed(() => (props.surgicalRequest.surgeon ? 1 : 0) + (props.surgicalRequest.team_members?.length ?? 0));
// Préparation : la synthèse anesthésie est « faite » une fois l'évaluation validée.
// Entrée au bloc : après le feu vert (ADR-048).
const blockEntryState = computed(() => {
    if (props.surgicalRequest.block_entry) return 'done';

    return statusRank(status.value) >= statusRank('PREOPERATIVE_VALIDATED') ? 'todo' : 'waiting';
});
const anesthesiaReady = computed(() => Boolean(props.surgicalRequest.anesthesia_record?.assessment_validated_at));

// --- Salle et préparation du bloc (surgery.preparation.update) ---
const showPreparationForm = ref(false);
const preparationForm = useForm({
    operating_room: props.surgicalRequest.operating_room ?? '',
    preparation_notes: props.surgicalRequest.preparation_notes ?? '',
});
const submitPreparation = () => preparationForm.post(`${base.value}/preparation`, {
    preserveScroll: true,
    onSuccess: () => { showPreparationForm.value = false; },
});

// --- Équipe de bloc (surgery.update) ---
const showTeamForm = ref(false);
const teamForm = useForm({ user_id: '', function: teamFunctionOptions.value[0]?.value ?? '' });
// ADR-168 — une fonction du bloc est tenue par un profil métier : l'anesthésiste
// par le profil Anesthésiste, l'infirmier de bloc par le sien… Le serveur envoie
// la correspondance ; seuls ces comptes sont proposés, sans ceux déjà inscrits.
const selectedTeamFunction = computed(() => props.teamFunctions.find((item) => item.value === teamForm.function) ?? null);
const teamUserOptions = computed(() => {
    const profile = selectedTeamFunction.value?.profile;
    if (!profile) return [];
    const taken = new Set((props.surgicalRequest.team_members ?? [])
        .filter((member) => member.function === teamForm.function)
        .map((member) => member.user_id ?? member.user?.id));

    return props.users
        .filter((user) => user.professional_profile?.code === profile && !taken.has(user.id))
        .map((user) => ({ value: String(user.id), label: user.name }));
});
const teamCandidatesEmpty = computed(() => (selectedTeamFunction.value
    ? `Aucun compte disponible au profil ${selectedTeamFunction.value.label} : le Super Administrateur attribue ce profil métier dans Utilisateurs.`
    : 'Choisissez d’abord la fonction.'));
watch(() => teamForm.function, () => { teamForm.user_id = ''; teamForm.clearErrors('user_id'); });
const submitTeam = () => teamForm.post(`${base.value}/team`, {
    preserveScroll: true,
    onSuccess: () => { showTeamForm.value = false; teamForm.reset(); },
});
const removingMemberId = ref(null);
const removeMember = (member) => {
    removingMemberId.value = member.id;
    router.delete(`${base.value}/team/${member.id}`, {
        preserveScroll: true,
        onFinish: () => { removingMemberId.value = null; },
    });
};

// --- Intervention (surgery.intervention.create / update) ---
// Le serveur n'accepte le démarrage qu'après le feu vert, et la correction
// qu'« Au bloc » : après la validation du compte rendu, l'intervention est close.
const intervention = computed(() => props.surgicalRequest.intervention ?? null);
const canStartIntervention = computed(() => status.value === 'PREOPERATIVE_VALIDATED');
const canEditIntervention = computed(() => status.value === 'IN_PROGRESS' && can('surgery.intervention.update'));
const interventionCreateForm = useForm({
    performed_by: props.surgicalRequest.surgeon?.id ? String(props.surgicalRequest.surgeon.id) : '',
    started_at: '',
    notes: '',
});
const submitInterventionCreate = () => interventionCreateForm.post(`${base.value}/intervention`, { preserveScroll: true });

const editingIntervention = ref(false);
const interventionUpdateForm = useForm({
    ended_at: toDatetimeLocal(props.surgicalRequest.intervention?.ended_at),
    procedure_summary: props.surgicalRequest.intervention?.procedure_summary ?? '',
    notes: props.surgicalRequest.intervention?.notes ?? '',
});
const openInterventionEdit = () => {
    if (!canEditIntervention.value) return;
    interventionUpdateForm.ended_at = toDatetimeLocal(intervention.value?.ended_at);
    interventionUpdateForm.procedure_summary = intervention.value?.procedure_summary ?? '';
    interventionUpdateForm.notes = intervention.value?.notes ?? '';
    interventionUpdateForm.clearErrors();
    editingIntervention.value = true;
};
const submitInterventionUpdate = () => {
    interventionUpdateForm.transform((data) => ({ ...data, _method: 'put' })).post(
        `${base.value}/intervention/${intervention.value.id}`,
        { preserveScroll: true, onSuccess: () => { editingIntervention.value = false; } },
    );
};

// --- Consommables : SurgicalConsumables (ADR-169) — stock Pharmacie, file de la
// Pharmacie, facturation à la Caisse. Chirurgie n'encaisse rien (ADR-016). ---

// --- Complications (surgery.complications.create) ---
const showComplicationForm = ref(false);
const complicationForm = useForm({ description: '' });
const submitComplication = () => complicationForm.post(`${base.value}/complications`, {
    preserveScroll: true,
    onSuccess: () => { complicationForm.reset(); showComplicationForm.value = false; },
});

// --- Soins péri/postopératoires (surgery.care.create / surgery.postoperative_care.create) ---
const perioperativeNotes = computed(() => (props.surgicalRequest.care_notes ?? []).filter((note) => note.phase === 'PERIOPERATIVE'));
const postoperativeNotes = computed(() => (props.surgicalRequest.care_notes ?? []).filter((note) => note.phase === 'POSTOPERATIVE'));
const showPerioCareForm = ref(false);
const perioCareForm = useForm({ note: '' });
const submitPerioCare = () => perioCareForm.post(`${base.value}/care-notes/perioperative`, {
    preserveScroll: true,
    onSuccess: () => { perioCareForm.reset(); showPerioCareForm.value = false; },
});

const showPostCareForm = ref(false);
const postCareForm = useForm({ note: '' });
const submitPostCare = () => postCareForm.post(`${base.value}/care-notes/postoperative`, {
    preserveScroll: true,
    onSuccess: () => { postCareForm.reset(); showPostCareForm.value = false; },
});

// --- Compte rendu (surgery.report.create / update / validate) ---
// Il se rédige une fois l'intervention démarrée, et sa validation clôt
// l'intervention : l'heure de fin doit donc être consignée avant, car
// l'intervention ne se corrige plus ensuite.
const report = computed(() => props.surgicalRequest.report ?? null);
const reportOpen = computed(() => statusRank(status.value) >= 3);
const reportValidationBlocker = computed(() => {
    if (status.value !== 'IN_PROGRESS') return 'La validation se fait une fois l’intervention démarrée.';
    if (!intervention.value?.ended_at) return 'Renseignez d’abord l’heure de fin de l’intervention : après la validation, elle ne se modifie plus.';

    return null;
});
const reportCreateForm = useForm({ content: '' });
const submitReportCreate = () => reportCreateForm.post(`${base.value}/report`, { preserveScroll: true });

const editingReport = ref(false);
const reportUpdateForm = useForm({ content: props.surgicalRequest.report?.content ?? '' });
const openReportEdit = () => {
    reportUpdateForm.content = report.value?.content ?? '';
    reportUpdateForm.clearErrors();
    editingReport.value = true;
};
const submitReportUpdate = () => {
    reportUpdateForm.transform((data) => ({ ...data, _method: 'put' })).post(
        `${base.value}/report/${report.value.id}`,
        { preserveScroll: true, onSuccess: () => { editingReport.value = false; } },
    );
};
const validatingReport = ref(false);
const validateReport = () => {
    validatingReport.value = true;
    router.post(`${base.value}/report/${report.value.id}/validate`, {}, {
        preserveScroll: true,
        onFinish: () => { validatingReport.value = false; },
    });
};

// --- Sortie de Chirurgie (surgery.discharge.create) ---
const dischargeForm = useForm({ notes: '' });
const submitDischarge = () => dischargeForm.post(`${base.value}/discharge`, { preserveScroll: true });

const serverError = (key) => page.props.errors?.[key] ?? null;
</script>

<template>
    <Head :title="surgicalRequest.procedure_name" />

    <div class="mx-auto w-full max-w-[1600px] space-y-4 pb-6">
        <EnTeteDossierChirurgical :surgical-request="surgicalRequest" :workspace="workspace" :show-notes="isAnesthesiaWorkspace">
            <template #status>
                <!-- ADR-160 — le patient vient de son lit et y remonte : dit à côté du statut. -->
                <HospitalStayBanner v-if="hospitalStay" inline :stay="hospitalStay" :cancelled="cancelled" />
            </template>
            <template #actions>
                <Button :as="Link" :href="workspaceMeta.returnUrl" variant="outline" size="sm"><ArrowLeft class="h-4 w-4" />{{ workspaceMeta.returnLabel }}</Button>
            </template>
        </EnTeteDossierChirurgical>

        <!-- Parcours : l'étape à faire est marquée, celles qui attendent disent pourquoi.
             Carte compacte : une fine barre de progression en haut, le compteur à
             droite des étapes — pas de ligne d'en-tête qui allonge la page. -->
        <Card class="relative overflow-hidden print:hidden">
            <div class="h-1 bg-muted" aria-hidden="true">
                <div class="h-full bg-emerald-500 transition-all" :style="{ width: `${(completedTabs / tabs.length) * 100}%` }" />
            </div>
            <div class="flex items-center gap-2 p-1.5">
                <p class="sr-only">Parcours {{ workspaceMeta.label }} — {{ completedTabs }}/{{ tabs.length }} étapes terminées</p>
                <nav ref="stepNav" class="relative min-w-0 flex-1 overflow-x-auto" :aria-label="`Étapes ${workspaceMeta.label}`">
                    <ol :class="['grid gap-1', tabs.length > 3 ? 'min-w-[720px] grid-cols-5' : 'min-w-[480px] grid-cols-3', 'lg:min-w-0']" role="tablist">
                        <li v-for="(tab, index) in tabs" :key="tab.id" class="min-w-0">
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="activeTab === tab.id"
                                :aria-current="activeTab === tab.id ? 'step' : undefined"
                                :title="tab.waiting ?? tab.description"
                                :class="[
                                    'group flex min-h-[42px] w-full items-center gap-2 rounded-md border px-2.5 py-1.5 text-start outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring/30',
                                    activeTab === tab.id
                                        ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                        : stepState(tab) === 'current'
                                            ? 'border-primary/40 bg-primary/5 hover:bg-primary/10'
                                            : 'border-transparent hover:bg-accent',
                                ]"
                                @click="selectStep(tab.id)"
                            >
                                <span
                                    :class="[
                                        'grid h-6 w-6 shrink-0 place-items-center rounded-full border text-[11px] font-bold',
                                        activeTab === tab.id
                                            ? 'border-primary-foreground/35 bg-primary-foreground/10 text-primary-foreground'
                                            : {
                                                complete: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
                                                current: 'border-primary bg-card text-primary',
                                                waiting: 'border-dashed border-border bg-muted/40 text-muted-foreground',
                                                cancelled: 'border-dashed border-border bg-muted/40 text-muted-foreground',
                                                open: 'border-border bg-card text-muted-foreground',
                                            }[stepState(tab)],
                                    ]"
                                >
                                    <Check v-if="stepState(tab) === 'complete'" class="h-3.5 w-3.5" aria-hidden="true" />
                                    <Lock v-else-if="['waiting', 'cancelled'].includes(stepState(tab))" class="h-3 w-3" aria-hidden="true" />
                                    <CircleDot v-else-if="stepState(tab) === 'current'" class="h-3.5 w-3.5" aria-hidden="true" />
                                    <span v-else>{{ index + 1 }}</span>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span :class="['block truncate text-xs font-bold leading-4', activeTab === tab.id ? 'text-primary-foreground' : 'text-foreground']">{{ tab.label }}</span>
                                    <span :class="['block truncate text-[10px] leading-[14px]', activeTab === tab.id ? 'text-primary-foreground/75' : 'text-muted-foreground']">
                                        <span
                                            v-if="STEP_STATE_LABELS[stepState(tab)]"
                                            :class="[
                                                'font-semibold',
                                                activeTab === tab.id ? 'text-primary-foreground' : { complete: 'text-emerald-700 dark:text-emerald-300', current: 'text-primary', waiting: 'text-muted-foreground' }[stepState(tab)],
                                            ]"
                                        >{{ STEP_STATE_LABELS[stepState(tab)] }} · </span>{{ tab.description }}
                                    </span>
                                </span>
                                <ChevronRight :class="['hidden h-3.5 w-3.5 shrink-0 xl:block', activeTab === tab.id ? 'text-primary-foreground/70' : 'text-muted-foreground']" aria-hidden="true" />
                            </button>
                        </li>
                    </ol>
                </nav>
                <span
                    class="hidden shrink-0 rounded-md bg-muted px-2 py-1 text-[11px] font-semibold tabular-nums text-muted-foreground sm:inline-block"
                    :title="`${completedTabs}/${tabs.length} étapes terminées`"
                    aria-hidden="true"
                >{{ completedTabs }}/{{ tabs.length }}</span>
            </div>
        </Card>

        <!-- Deux panneaux dans l'espace Chirurgie, séparés par une barre que l'on
             glisse : le geste de l'étape à gauche, le contexte du dossier — le même
             à chaque étape — à droite. Largeur retenue sur le poste, jamais envoyée
             au serveur ; double-clic sur la barre pour revenir au réglage par défaut.
             Sur un écran étroit, le contexte passe sous le geste : l'action reste en
             premier. L'espace Anesthésie garde un seul panneau. -->
        <ResizableSplit
            :single="isAnesthesiaWorkspace"
            storage-key="rivo:surgery:context-split"
            :default-ratio="0.7"
            :min-ratio="0.5"
            :max-ratio="0.75"
            start-label="panneau de l’étape en cours"
            end-label="panneau Contexte du dossier"
        >
        <template #start>
        <main class="min-w-0 space-y-4">
            <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
                <p
                    v-if="currentTab.waiting && !cancelled"
                    class="flex items-start gap-2 rounded-lg border border-dashed border-border bg-muted/30 px-4 py-2.5 text-xs text-muted-foreground xl:col-span-12"
                >
                    <Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span><strong class="font-semibold text-foreground">{{ currentTab.label }} — en attente.</strong> {{ currentTab.waiting }}</span>
                </p>

                <CareSummaryReadOnly
                    v-if="careSummary && isAnesthesiaWorkspace && activeTab === 'consultation'"
                    class="xl:col-span-12"
                    :care-summary="careSummary"
                />

                <!-- ================= Anesthésie ================= -->
                <div v-if="isAnesthesiaWorkspace && activeTab === 'consultation'" id="anesthesia-consultation" class="scroll-mt-24 xl:col-span-12">
                    <ConsultationPreAnesthesique
                        :surgical-request="surgicalRequest"
                        :care-summary="careSummary"
                        :can-create="can('anesthesia.create')"
                        :can-update="can('anesthesia.update')"
                    />
                </div>

                <div v-if="isAnesthesiaWorkspace && activeTab === 'paraclinical'" id="anesthesia-paraclinical" class="scroll-mt-24 xl:col-span-12">
                    <ExamenParaclinique
                        :surgical-request="surgicalRequest"
                        :care-summary="careSummary"
                        :can-create="can('anesthesia.create')"
                        :can-update="can('anesthesia.update')"
                        :can-validate="can('anesthesia.validate')"
                    />
                </div>

                <div v-if="isAnesthesiaWorkspace && activeTab === 'peroperative'" id="anesthesia-peroperative" class="scroll-mt-24 xl:col-span-12">
                    <ConduiteAnesthesique
                        :surgical-request="surgicalRequest"
                        :reference-items="anesthesiaItems"
                        :can-create="can('anesthesia.create')"
                        :can-update="can('anesthesia.update')"
                        :can-validate="can('anesthesia.validate')"
                    />
                </div>

                <!-- ================= Étape 1 · Dossier =================
                     Programmer, puis composer l'équipe — ses chirurgiens viennent de
                     la programmation. La demande reçue se lit dans la colonne latérale. -->
                <template v-if="!isAnesthesiaWorkspace && activeTab === 'case'">

                    <!-- Programmation : le geste qui fait avancer le dossier. -->
                    <SurgerySection
                        id="surgery-schedule"
                        class="xl:col-span-12"
                        :state="isAlreadyScheduled ? 'done' : 'todo'"
                        :icon="CalendarDays"
                        title="Programmation"
                        description="Date, chirurgiens, puis salle et consignes de préparation."
                    >
                        <template #actions>
                            <Button v-if="writable && isAlreadyScheduled && canEditSchedule && !showScheduleForm" size="sm" variant="white-outline" type="button" @click="showScheduleForm = true"><Pencil class="h-3.5 w-3.5" />Corriger</Button>
                        </template>

                        <SurgeonScheduler v-if="showScheduleForm && canEditSchedule" :surgical-request="surgicalRequest" @close="showScheduleForm = false" />
                        <div v-else-if="!isAlreadyScheduled" class="flex flex-wrap items-center gap-3 rounded-lg border border-dashed border-border px-4 py-3">
                            <p class="me-auto text-sm text-muted-foreground">Aucun chirurgien ni date encore fixés.</p>
                            <Button v-if="writable && canEditSchedule" size="sm" type="button" @click="showScheduleForm = true"><CalendarDays class="h-4 w-4" />Programmer l’intervention</Button>
                            <p v-else-if="writable" class="text-xs text-muted-foreground">À faire par un compte disposant du droit « surgery.schedule ».</p>
                        </div>
                        <dl v-else class="grid gap-3 text-sm sm:grid-cols-3">
                            <div><dt class="text-xs text-muted-foreground">Programmée le</dt><dd class="font-medium text-foreground">{{ formatDateTime(surgicalRequest.scheduled_at) ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-muted-foreground">Chirurgien principal</dt><dd class="font-medium text-foreground">{{ surgicalRequest.surgeon?.name ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-muted-foreground">Chirurgiens aides</dt><dd :class="assistantSurgeons.length ? 'text-foreground' : 'text-muted-foreground'">{{ assistantSurgeons.map((member) => member.user?.name).filter(Boolean).join(', ') || 'Aucun' }}</dd></div>
                        </dl>

                        <!-- La salle et les consignes suivent la programmation : avant, elles n'ont rien à préparer. -->
                        <div v-if="showPreparationBlock" class="mt-4 border-t border-border pt-4">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <h3 class="text-xs font-semibold text-foreground">Salle et préparation du bloc</h3>
                                <Button v-if="writable && can('surgery.preparation.update') && !showPreparationForm" size="xs" variant="white-outline" type="button" @click="showPreparationForm = true"><Pencil class="h-3 w-3" />{{ surgicalRequest.operating_room ? 'Modifier' : 'Affecter la salle' }}</Button>
                            </div>
                            <form v-if="showPreparationForm" class="space-y-3" @submit.prevent="submitPreparation">
                                <FormField label="Salle / bloc" :error="preparationForm.errors.operating_room"><Input id="operating_room" v-model="preparationForm.operating_room" placeholder="Ex. Bloc 1" /></FormField>
                                <FormField label="Consignes de préparation" :error="preparationForm.errors.preparation_notes"><Textarea id="preparation_notes" v-model="preparationForm.preparation_notes" rows="2" placeholder="Matériel, consignes…" /></FormField>
                                <div class="flex justify-end gap-2">
                                    <Button size="sm" variant="white-outline" type="button" @click="showPreparationForm = false">Annuler</Button>
                                    <Button size="sm" type="submit" :disabled="preparationForm.processing">Enregistrer</Button>
                                </div>
                            </form>
                            <dl v-else class="grid gap-3 text-sm sm:grid-cols-2">
                                <div><dt class="text-xs text-muted-foreground">Salle</dt><dd :class="surgicalRequest.operating_room ? 'text-foreground' : 'text-muted-foreground'">{{ surgicalRequest.operating_room ?? 'Non affectée' }}</dd></div>
                                <div><dt class="text-xs text-muted-foreground">Consignes</dt><dd class="whitespace-pre-line text-foreground">{{ surgicalRequest.preparation_notes || '—' }}</dd></div>
                            </dl>
                        </div>
                    </SurgerySection>


                    <!-- Équipe de bloc : sous la programmation, dont viennent ses chirurgiens. -->
                    <SurgerySection
                        class="xl:col-span-12"
                        :state="teamState"
                        waiting-label="Après la programmation"
                        :peekable="false"
                        :icon="Users"
                        title="Équipe de bloc"
                        description="Qui opère, anesthésie et assiste."
                    >
                        <template #waiting>
                            Les chirurgiens viennent de la programmation, juste au-dessus ; l’anesthésiste, l’infirmier de bloc et le paramédical s’ajoutent ensuite ici.
                        </template>
                        <template v-if="teamState !== 'waiting'" #badge>
                            <Badge variant="secondary" :title="`${teamCount} personne${teamCount > 1 ? 's' : ''} dans l’équipe`">{{ teamCount }}</Badge>
                        </template>
                        <template v-if="teamState !== 'waiting'" #actions>
                            <Button v-if="writable && can('surgery.update') && !showTeamForm" size="sm" variant="white-outline" type="button" @click="showTeamForm = true"><Plus class="h-3.5 w-3.5" />Ajouter</Button>
                        </template>

                        <template v-if="teamState !== 'waiting'">
                            <!-- La fonction d'abord, puis les seuls comptes qui ont le profil de cette
                                 fonction (ADR-168) ; le serveur revérifie. -->
                            <form v-if="showTeamForm" class="mb-3 grid gap-3 rounded-lg border border-border bg-muted/20 p-3 sm:grid-cols-2" @submit.prevent="submitTeam">
                                <FormField label="Fonction au bloc" required :error="teamForm.errors.function">
                                    <Select id="team_function" v-model="teamForm.function" :options="teamFunctionOptions" :icon="Stethoscope" placeholder="Choisir la fonction" class="w-full" required />
                                </FormField>
                                <FormField label="Personne" required :error="teamForm.errors.user_id">
                                    <Select
                                        v-if="teamUserOptions.length"
                                        id="team_user"
                                        v-model="teamForm.user_id"
                                        :options="teamUserOptions"
                                        :icon="UserRound"
                                        placeholder="Choisir la personne"
                                        class="w-full"
                                        required
                                    />
                                    <p v-else class="flex min-h-10 items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                                        <Info class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ teamCandidatesEmpty }}
                                    </p>
                                </FormField>
                                <p class="text-xs text-muted-foreground sm:col-span-2">Seuls les comptes au profil métier de la fonction sont proposés. Les chirurgiens — principal et aides — se choisissent avec la programmation, juste au-dessus.</p>
                                <div class="flex justify-end gap-2 sm:col-span-2">
                                    <Button size="sm" variant="white-outline" type="button" @click="showTeamForm = false"><X class="h-3.5 w-3.5" />Annuler</Button>
                                    <Button size="sm" type="submit" :disabled="teamForm.processing || !teamForm.user_id"><Plus class="h-3.5 w-3.5" />Ajouter</Button>
                                </div>
                            </form>
                            <ul class="divide-y divide-border rounded-lg border border-border">
                                <li v-if="surgicalRequest.surgeon" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                                    <span class="flex min-w-0 items-center gap-2 font-medium text-foreground"><Stethoscope class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" /><span class="truncate">{{ surgicalRequest.surgeon.name }}</span></span>
                                    <Badge variant="default">Principal</Badge>
                                </li>
                                <li v-for="member in surgicalRequest.team_members ?? []" :key="member.id" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                                    <span class="flex min-w-0 items-center gap-2 text-foreground"><Stethoscope class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" /><span class="truncate">{{ member.user?.name }}</span></span>
                                    <span class="flex items-center gap-2">
                                        <Badge variant="outline">{{ member.function === 'SURGEON' ? 'Aide' : (TEAM_FUNCTION_LABELS[member.function] ?? member.function) }}</Badge>
                                        <Button
                                            v-if="writable && can('surgery.update')"
                                            size="icon-xs"
                                            variant="ghost"
                                            type="button"
                                            :disabled="removingMemberId === member.id"
                                            :aria-label="`Retirer ${member.user?.name}`"
                                            title="Retirer (erreur d’assignation)"
                                            @click="removeMember(member)"
                                        ><X class="h-3.5 w-3.5" /></Button>
                                    </span>
                                </li>
                            </ul>
                            <p v-if="!surgicalRequest.team_members?.length" class="mt-2 text-xs text-muted-foreground">Ajoutez l’anesthésiste, l’infirmier de bloc et le paramédical qui participeront à l’intervention.</p>
                        </template>
                    </SurgerySection>
                </template>

                <!-- ================= Étape 2 · Préparation =================
                     Le feu vert, puis l'entrée au bloc. Ce qu'on relit avant — les
                     Soins, l'anesthésie — reste sous les yeux dans la colonne latérale. -->
                <template v-if="!isAnesthesiaWorkspace && activeTab === 'preparation'">

                    <ValidationPreoperatoire
                        :order="1"
                        :surgical-request="surgicalRequest"
                        :can-update="writable && can('surgery.update')"
                        :can-validate="can('surgery.preoperative.validate')"
                    />
                    <div id="surgery-block-entry" class="scroll-mt-24 xl:col-span-12">
                        <EntreeBloc
                            :surgical-request="surgicalRequest"
                            :can-edit="writable && can('surgery.preparation.update')"
                            :order="2"
                            :state="blockEntryState"
                        />
                    </div>
                </template>

                <!-- ================= Étape 3 · Intervention ================= -->
                <template v-if="!isAnesthesiaWorkspace && activeTab === 'intervention'">
                    <SurgerySection
                        id="surgery-intervention"
                        class="xl:col-span-12"
                        :icon="Activity"
                        title="Intervention"
                        description="Le démarrage fait passer le dossier « Au bloc » ; la fin se consigne avant le compte rendu."
                    >
                        <template #badge>
                            <Badge v-if="intervention && !intervention.ended_at" variant="default">En cours</Badge>
                            <Badge v-else-if="intervention?.ended_at" variant="success">Terminée</Badge>
                        </template>
                        <template #actions>
                            <Button v-if="intervention && canEditIntervention && !editingIntervention" size="sm" variant="white-outline" type="button" @click="openInterventionEdit">
                                <Pencil class="h-3.5 w-3.5" />{{ intervention.ended_at ? 'Corriger' : 'Renseigner la fin' }}
                            </Button>
                        </template>

                        <template v-if="!intervention">
                            <form v-if="writable && can('surgery.intervention.create')" class="space-y-3" @submit.prevent="submitInterventionCreate">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <FormField label="Opérateur" :error="interventionCreateForm.errors.performed_by">
                                        <Select id="performed_by" v-model="interventionCreateForm.performed_by" :options="operatorOptions" placeholder="Chirurgien programmé par défaut" class="w-full" />
                                    </FormField>
                                    <FormField label="Heure de début" hint="Maintenant si laissée vide" :error="interventionCreateForm.errors.started_at">
                                        <DateTimePicker id="started_at" v-model="interventionCreateForm.started_at" />
                                    </FormField>
                                </div>
                                <FormField label="Notes de début d’intervention" :error="interventionCreateForm.errors.notes"><Textarea id="intervention_start_notes" v-model="interventionCreateForm.notes" rows="2" /></FormField>
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <p v-if="!canStartIntervention" class="me-auto flex items-center gap-1.5 text-xs text-muted-foreground"><Info class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Le feu vert préopératoire doit d’abord être confirmé (étape Préparation).</p>
                                    <Button type="submit" :disabled="interventionCreateForm.processing || !canStartIntervention"><Activity class="h-4 w-4" />Démarrer l’intervention</Button>
                                </div>
                            </form>
                            <p v-else class="text-sm text-muted-foreground">L’intervention n’a pas encore démarré.</p>
                        </template>

                        <template v-else>
                            <form v-if="editingIntervention" class="space-y-3" @submit.prevent="submitInterventionUpdate">
                                <FormField label="Heure de fin" :error="interventionUpdateForm.errors.ended_at"><DateTimePicker id="ended_at" v-model="interventionUpdateForm.ended_at" /></FormField>
                                <FormField label="Résumé de l’acte réalisé" :error="interventionUpdateForm.errors.procedure_summary"><Textarea id="procedure_summary" v-model="interventionUpdateForm.procedure_summary" rows="3" /></FormField>
                                <FormField label="Notes complémentaires" :error="interventionUpdateForm.errors.notes"><Textarea id="intervention_notes" v-model="interventionUpdateForm.notes" rows="2" /></FormField>
                                <p v-if="serverError('intervention')" class="text-xs text-destructive">{{ serverError('intervention') }}</p>
                                <div class="flex justify-end gap-2">
                                    <Button size="sm" variant="white-outline" type="button" @click="editingIntervention = false">Annuler</Button>
                                    <Button size="sm" type="submit" :disabled="interventionUpdateForm.processing">Enregistrer</Button>
                                </div>
                            </form>
                            <dl v-else class="grid gap-3 text-sm sm:grid-cols-3">
                                <div><dt class="text-xs text-muted-foreground">Opérateur</dt><dd class="font-medium text-foreground">{{ intervention.performed_by?.name ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-muted-foreground">Début</dt><dd class="text-foreground">{{ formatDateTime(intervention.started_at) ?? '—' }}</dd></div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">Fin</dt>
                                    <dd v-if="intervention.ended_at" class="text-foreground">{{ formatDateTime(intervention.ended_at) }}</dd>
                                    <dd v-else class="font-medium text-amber-700 dark:text-amber-300">À renseigner</dd>
                                </div>
                                <div class="sm:col-span-3"><dt class="text-xs text-muted-foreground">Résumé de l’acte</dt><dd class="whitespace-pre-line text-foreground">{{ intervention.procedure_summary || '—' }}</dd></div>
                                <div v-if="intervention.notes" class="sm:col-span-3"><dt class="text-xs text-muted-foreground">Notes</dt><dd class="whitespace-pre-line text-foreground">{{ intervention.notes }}</dd></div>
                            </dl>
                            <p v-if="!editingIntervention && statusRank(status) > 3" class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground"><Lock class="h-3.5 w-3.5" aria-hidden="true" />Intervention close par la validation du compte rendu : elle ne se modifie plus.</p>
                        </template>
                    </SurgerySection>

                    <SurgicalConsumables
                        class="xl:col-span-12"
                        :surgical-request="surgicalRequest"
                        :requests="consumableRequests"
                        :catalog="consumableCatalog"
                        :suggestions="consumableSuggestions"
                        :can-declare="writable && can('surgery.consumables.create')"
                    />
                </template>

                <!-- ================= Étape 4 · Sortie du bloc ================= -->
                <div v-if="!isAnesthesiaWorkspace && activeTab === 'block-exit'" id="surgery-block-exit" class="scroll-mt-24 xl:col-span-12">
                    <SortieBloc
                        :surgical-request="surgicalRequest"
                        :can-edit="writable && can('surgery.intervention.update')"
                        :can-record-postoperative="writable && can('surgery.postoperative_care.create')"
                    />
                </div>

                <!-- ================= Étape 5 · Suivi & clôture ================= -->
                <template v-if="!isAnesthesiaWorkspace && activeTab === 'followup'">
                    <SurgerySection
                        id="surgery-report"
                        class="xl:col-span-12"
                        :icon="ClipboardList"
                        title="Compte rendu opératoire"
                        description="Sa validation clôt l’intervention : il ne se modifie plus ensuite."
                    >
                        <template #badge>
                            <Badge v-if="report?.validated_at" variant="success"><Lock class="h-3 w-3" />Validé</Badge>
                            <Badge v-else-if="report" variant="warning">À valider</Badge>
                        </template>
                        <template #actions>
                            <Button v-if="writable && report && !report.validated_at && can('surgery.report.update') && !editingReport" size="sm" variant="white-outline" type="button" @click="openReportEdit"><Pencil class="h-3.5 w-3.5" />Modifier</Button>
                        </template>

                        <template v-if="!report">
                            <form v-if="writable && reportOpen && can('surgery.report.create')" class="space-y-3" @submit.prevent="submitReportCreate">
                                <FormField label="Compte rendu" required :error="reportCreateForm.errors.content">
                                    <Textarea id="report_content" v-model="reportCreateForm.content" rows="6" placeholder="Indication, voie d’abord, constatations, geste réalisé, fermeture…" required />
                                </FormField>
                                <div class="flex justify-end"><Button size="sm" type="submit" :disabled="reportCreateForm.processing"><FileText class="h-4 w-4" />Enregistrer le compte rendu</Button></div>
                            </form>
                            <p v-else-if="!reportOpen && !cancelled" class="flex items-center gap-2 rounded-lg border border-dashed border-border px-4 py-3 text-sm text-muted-foreground"><Lock class="h-4 w-4 shrink-0" aria-hidden="true" />Le compte rendu se rédige une fois l’intervention démarrée.</p>
                            <p v-else class="text-sm text-muted-foreground">Aucun compte rendu.</p>
                        </template>

                        <template v-else>
                            <form v-if="editingReport" class="space-y-3" @submit.prevent="submitReportUpdate">
                                <FormField label="Compte rendu" required :error="reportUpdateForm.errors.content"><Textarea id="report_edit_content" v-model="reportUpdateForm.content" rows="6" required /></FormField>
                                <div class="flex justify-end gap-2">
                                    <Button size="sm" variant="white-outline" type="button" @click="editingReport = false">Annuler</Button>
                                    <Button size="sm" type="submit" :disabled="reportUpdateForm.processing">Enregistrer</Button>
                                </div>
                            </form>
                            <template v-else>
                                <p class="whitespace-pre-line rounded-lg border border-border bg-muted/20 p-3 text-sm text-foreground">{{ report.content }}</p>
                                <p class="mt-2 text-xs text-muted-foreground">
                                    Rédigé par {{ report.author?.name ?? '—' }}
                                    <template v-if="report.validated_at"> · <span class="font-semibold text-emerald-700 dark:text-emerald-300">validé par {{ report.validator?.name ?? '—' }} le {{ formatDateTime(report.validated_at) }}</span></template>
                                </p>
                            </template>
                        </template>

                        <template v-if="report && !report.validated_at && !editingReport && writable && can('surgery.report.validate')" #footer>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <p v-if="reportValidationBlocker" class="me-auto flex items-center gap-1.5"><Info class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ reportValidationBlocker }}</p>
                                <p v-else class="me-auto">Relisez le compte rendu : sa validation clôt l’intervention.</p>
                                <Button size="sm" variant="success" type="button" :disabled="validatingReport || Boolean(reportValidationBlocker)" @click="validateReport"><CheckCircle2 class="h-4 w-4" />Valider le compte rendu</Button>
                            </div>
                            <p v-if="serverError('report')" class="mt-2 text-destructive">{{ serverError('report') }}</p>
                        </template>
                    </SurgerySection>

                    <SurgerySection
                        class="xl:col-span-12"
                        :icon="Stethoscope"
                        title="Suivi péri- et postopératoire"
                        description="Notes propres au passage au bloc ; la fiche des Soins n’est pas modifiée."
                    >
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <div class="mb-2 flex items-center justify-between">
                                    <h3 class="text-xs font-semibold text-foreground">Peropératoire</h3>
                                    <Button v-if="writable && can('surgery.care.create') && !showPerioCareForm" size="xs" variant="white-outline" type="button" aria-label="Ajouter une note peropératoire" @click="showPerioCareForm = true"><Plus class="h-3 w-3" />Note</Button>
                                </div>
                                <form v-if="showPerioCareForm" class="mb-3 space-y-2" @submit.prevent="submitPerioCare">
                                    <Textarea v-model="perioCareForm.note" rows="2" aria-label="Observation peropératoire" placeholder="Observation peropératoire" required />
                                    <div class="flex justify-end gap-2">
                                        <Button size="xs" variant="white-outline" type="button" @click="showPerioCareForm = false">Annuler</Button>
                                        <Button size="xs" type="submit" :disabled="perioCareForm.processing">Ajouter</Button>
                                    </div>
                                </form>
                                <ul v-if="perioperativeNotes.length" class="space-y-2 text-sm">
                                    <li v-for="note in perioperativeNotes" :key="note.id" class="border-s-2 border-border ps-3 text-foreground">
                                        {{ note.note }}<span class="block text-xs text-muted-foreground">{{ formatDateTime(note.recorded_at) }}</span>
                                    </li>
                                </ul>
                                <p v-else class="text-sm text-muted-foreground">Aucune note peropératoire.</p>
                            </div>
                            <div>
                                <div class="mb-2 flex items-center justify-between">
                                    <h3 class="text-xs font-semibold text-foreground">Postopératoire</h3>
                                    <Button v-if="writable && can('surgery.postoperative_care.create') && !showPostCareForm" size="xs" variant="white-outline" type="button" aria-label="Ajouter une note postopératoire" @click="showPostCareForm = true"><Plus class="h-3 w-3" />Note</Button>
                                </div>
                                <form v-if="showPostCareForm" class="mb-3 space-y-2" @submit.prevent="submitPostCare">
                                    <Textarea v-model="postCareForm.note" rows="2" aria-label="Observation postopératoire" placeholder="Observation postopératoire" required />
                                    <div class="flex justify-end gap-2">
                                        <Button size="xs" variant="white-outline" type="button" @click="showPostCareForm = false">Annuler</Button>
                                        <Button size="xs" type="submit" :disabled="postCareForm.processing">Ajouter</Button>
                                    </div>
                                </form>
                                <ul v-if="postoperativeNotes.length" class="space-y-2 text-sm">
                                    <li v-for="note in postoperativeNotes" :key="note.id" class="border-s-2 border-border ps-3 text-foreground">
                                        {{ note.note }}<span class="block text-xs text-muted-foreground">{{ formatDateTime(note.recorded_at) }}</span>
                                    </li>
                                </ul>
                                <p v-else class="text-sm text-muted-foreground">Aucune note postopératoire.</p>
                            </div>
                        </div>
                    </SurgerySection>

                    <SurgerySection
                        class="xl:col-span-12"
                        tone="danger"
                        :icon="AlertTriangle"
                        title="Complications"
                        description="Historique non modifiable : une correction s’ajoute en nouvelle entrée."
                    >
                        <template #badge>
                            <Badge :variant="surgicalRequest.complications?.length ? 'destructive' : 'secondary'">{{ surgicalRequest.complications?.length ?? 0 }}</Badge>
                        </template>
                        <template #actions>
                            <Button v-if="writable && can('surgery.complications.create') && !showComplicationForm" size="sm" variant="white-outline" type="button" @click="showComplicationForm = true"><Plus class="h-3.5 w-3.5" />Signaler</Button>
                        </template>

                        <form v-if="showComplicationForm" class="mb-4 space-y-2" @submit.prevent="submitComplication">
                            <FormField label="Complication" required :error="complicationForm.errors.description"><Textarea id="complication" v-model="complicationForm.description" rows="2" required /></FormField>
                            <div class="flex justify-end gap-2">
                                <Button size="sm" variant="white-outline" type="button" @click="showComplicationForm = false">Annuler</Button>
                                <Button size="sm" type="submit" :disabled="complicationForm.processing">Enregistrer</Button>
                            </div>
                        </form>
                        <ul v-if="surgicalRequest.complications?.length" class="space-y-2 text-sm">
                            <li v-for="item in surgicalRequest.complications" :key="item.id" class="rounded-lg border border-red-100 bg-red-50/50 px-3 py-2 text-red-800 dark:border-red-950 dark:bg-red-950/20 dark:text-red-300">
                                {{ item.description }}
                                <span class="mt-1 block text-xs opacity-75">{{ item.reported_by?.name }} · {{ formatDateTime(item.reported_at) }}</span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">Aucune complication signalée.</p>
                    </SurgerySection>

                    <SurgerySection
                        id="surgery-discharge"
                        class="xl:col-span-12"
                        :icon="LogOut"
                        title="Sortie de Chirurgie"
                        description="Consignes remises à la sortie ; le dossier est ensuite clôturé."
                    >
                        <template #badge>
                            <Badge v-if="status === 'DISCHARGED'" variant="success">Enregistrée</Badge>
                        </template>

                        <form v-if="status === 'COMPLETED' && can('surgery.discharge.create')" class="space-y-3" @submit.prevent="submitDischarge">
                            <p v-if="!surgicalRequest.block_exit" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                La sortie du bloc n’est pas renseignée : après la sortie de Chirurgie, elle ne pourra plus l’être.
                            </p>
                            <FormField label="Consignes de sortie" :error="dischargeForm.errors.notes"><Textarea id="discharge_notes" v-model="dischargeForm.notes" rows="3" /></FormField>
                            <Button class="w-full" type="submit" :disabled="dischargeForm.processing"><CheckCircle2 class="h-4 w-4" />Enregistrer la sortie</Button>
                        </form>
                        <dl v-else-if="status === 'DISCHARGED'" class="space-y-3 text-sm">
                            <div><dt class="text-xs text-muted-foreground">Enregistrée par</dt><dd class="text-foreground">{{ surgicalRequest.discharged_by?.name ?? '—' }} · {{ formatDateTime(surgicalRequest.discharged_at) }}</dd></div>
                            <div><dt class="text-xs text-muted-foreground">Consignes</dt><dd class="whitespace-pre-line text-foreground">{{ surgicalRequest.discharge_notes || '—' }}</dd></div>
                        </dl>
                        <p v-else-if="status === 'COMPLETED'" class="text-sm text-muted-foreground">À faire par un compte disposant du droit « surgery.discharge.create ».</p>
                        <p v-else-if="!cancelled" class="flex items-start gap-2 text-sm text-muted-foreground"><Lock class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />La sortie s’enregistre une fois le compte rendu validé.</p>
                        <p v-else class="text-sm text-muted-foreground">Demande annulée.</p>
                    </SurgerySection>
                </template>
            </div>

            <!-- La prochaine action du workflow reste sous la main, où qu'on soit dans la page. -->
            <Card class="sticky bottom-3 z-20 bg-card/95 backdrop-blur print:hidden">
                <div class="flex flex-wrap items-center gap-3 px-3 py-2.5 sm:flex-nowrap sm:px-4">
                    <div class="flex shrink-0 items-center gap-1">
                        <Button size="icon-xs" variant="ghost" type="button" :disabled="!previousTab" :aria-label="previousTab ? `Étape précédente : ${previousTab.label}` : 'Première étape'" :title="previousTab?.label" @click="previousTab && selectStep(previousTab.id)"><ChevronLeft class="h-4 w-4" /></Button>
                        <span class="min-w-[2.5rem] text-center text-xs font-semibold tabular-nums text-muted-foreground">{{ currentTabIndex + 1 }}/{{ tabs.length }}</span>
                        <Button size="icon-xs" variant="ghost" type="button" :disabled="!nextTab" :aria-label="nextTab ? `Étape suivante : ${nextTab.label}` : 'Dernière étape'" :title="nextTab?.label" @click="nextTab && selectStep(nextTab.id)"><ChevronRight class="h-4 w-4" /></Button>
                    </div>
                    <span :class="['hidden h-9 w-9 shrink-0 place-items-center rounded-lg sm:grid', NEXT_TONES[next.tone] ?? NEXT_TONES.muted]" aria-hidden="true"><component :is="nextIcon" class="h-4 w-4" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{{ next.key ? 'Prochaine étape' : 'État du dossier' }}</p>
                        <p class="truncate text-sm font-semibold text-foreground">{{ next.title }}</p>
                        <p class="hidden truncate text-xs text-muted-foreground sm:block" :title="nextDetail">{{ nextDetail }}</p>
                    </div>
                    <Button v-if="nextAllowed" class="w-full shrink-0 sm:w-auto" type="button" @click="runNextAction">{{ next.cta }}<ArrowRight class="h-4 w-4" /></Button>
                </div>
            </Card>
        </main>
        </template>

        <template #end>
        <!-- Contexte du dossier : ce qu'on relit sans quitter l'étape en cours. -->
        <aside v-if="!isAnesthesiaWorkspace" class="min-w-0 space-y-4 print:hidden" aria-label="Contexte du dossier">
                <!-- Synthèse anesthésie : relue avant le feu vert, seulement avec anesthesia.view. -->
                <SurgerySection
                    v-if="can('anesthesia.view')"
                    compact
                    :peekable="Boolean(surgicalRequest.anesthesia_record)"
                    body-class="px-4 py-3"
                    tone="violet"
                    :state="anesthesiaReady ? 'done' : 'waiting'"
                    waiting-label="Évaluation à valider"
                    :icon="ShieldCheck"
                    title="Synthèse anesthésie"
                    description="La décision de l’anesthésiste, relue avant de confirmer le feu vert."
                >
                    <template #waiting>
                        {{ surgicalRequest.anesthesia_record ? 'Évaluation en brouillon : l’anesthésiste ne l’a pas encore validée.' : 'Aucune évaluation anesthésique enregistrée pour l’instant.' }}
                    </template>
                    <template #badge>
                        <Badge v-if="surgicalRequest.anesthesia_record" :variant="surgeryAuthorization.variant">{{ surgeryAuthorization.label }}</Badge>
                    </template>
                    <template #actions>
                        <Button :as="Link" :href="`/anesthesia/${surgicalRequest.uuid}`" size="xs" variant="white-outline"><ExternalLink class="h-3 w-3" />Ouvrir</Button>
                    </template>
                    <dl v-if="surgicalRequest.anesthesia_record" class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Anesthésiste</dt><dd class="font-medium text-foreground">{{ surgicalRequest.anesthesia_record.anesthetist?.name ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Classe ASA</dt><dd class="text-foreground">{{ anesthesiaAssessment.asa_class || '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs text-muted-foreground">Plan anesthésique</dt><dd class="text-foreground">{{ anesthesiaAssessment.anesthesia_plan || '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs text-muted-foreground">Évaluation</dt><dd class="text-foreground">{{ surgicalRequest.anesthesia_record.assessment_validated_at ? `Validée le ${formatDateTime(surgicalRequest.anesthesia_record.assessment_validated_at)}` : 'Brouillon — pas encore validée' }}</dd></div>
                    </dl>
                    <p v-else class="text-sm text-muted-foreground">Aucune évaluation anesthésique enregistrée.</p>
                </SurgerySection>

                <!-- La demande reçue : le point de départ, relisible à chaque étape. -->
                <SurgicalRequestCard :surgical-request="surgicalRequest" :procedures="procedures" :can-edit="writable && can('surgery.update')" />

            <CareSummaryReadOnly v-if="careSummary" dense compact :care-summary="careSummary" />
        </aside>
        </template>
        </ResizableSplit>
    </div>
</template>
