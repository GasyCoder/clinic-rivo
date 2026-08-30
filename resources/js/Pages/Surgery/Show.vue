<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import CareSummaryReadOnly from '@/Components/Surgery/CareSummaryReadOnly.vue';
import ConduiteAnesthesique from '@/Components/Surgery/ConduiteAnesthesique.vue';
import ConsultationPreAnesthesique from '@/Components/Surgery/ConsultationPreAnesthesique.vue';
import EnTeteDossierChirurgical from '@/Components/Surgery/EnTeteDossierChirurgical.vue';
import EntreeBloc from '@/Components/Surgery/EntreeBloc.vue';
import ExamenParaclinique from '@/Components/Surgery/ExamenParaclinique.vue';
import SortieBloc from '@/Components/Surgery/SortieBloc.vue';
import ValidationPreoperatoire from '@/Components/Surgery/ValidationPreoperatoire.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workspace: { type: String, default: 'surgery' },
    surgicalRequest: Object,
    careSummary: { type: Object, default: null },
    users: Array,
    teamFunctions: Array,
    procedures: { type: Array, default: () => [] },
    anesthesiaItems: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const isAnesthesiaWorkspace = computed(() => props.workspace === 'anesthesia');
const hasClinicalData = (value) => {
    if (Array.isArray(value)) return value.some(hasClinicalData);
    if (value && typeof value === 'object') return Object.values(value).some(hasClinicalData);
    return value !== null && value !== undefined && value !== '';
};
const defaultSurgeryStep = ['COMPLETED', 'DISCHARGED'].includes(props.surgicalRequest.status)
    ? 'followup'
    : props.surgicalRequest.status === 'IN_PROGRESS'
        ? 'intervention'
        : props.surgicalRequest.status === 'PREOPERATIVE_VALIDATED'
            ? 'preparation'
            : 'case';
const activeTab = ref(isAnesthesiaWorkspace.value
    ? (props.surgicalRequest.anesthesia_record?.assessment_validated_at ? 'peroperative' : 'consultation')
    : defaultSurgeryStep);
const anesthesiaTabs = computed(() => [
    { id: 'consultation', shortLabel: 'Consultation', icon: 'user-check', owner: 'Étape 1', description: 'Antécédents et examen clinique', complete: hasClinicalData(props.surgicalRequest.anesthesia_record?.consultation_data) },
    { id: 'paraclinical', shortLabel: 'Paraclinique', icon: 'activity', owner: 'Étape 2', description: 'Résultats, scores et décision', complete: hasClinicalData(props.surgicalRequest.anesthesia_record?.paraclinical_data) },
    { id: 'peroperative', shortLabel: 'Conduite anesthésique', icon: 'shield-check', owner: 'Étape 3', description: 'Produits utilisés et observations', complete: Boolean(props.surgicalRequest.anesthesia_record?.validated_at) },
]);
const surgeryTabs = computed(() => [
    { id: 'case', shortLabel: 'Dossier', icon: 'file-text', owner: 'Étape 1', description: 'Demande, programmation et équipe', complete: Boolean(props.surgicalRequest.surgeon && props.surgicalRequest.scheduled_at) },
    { id: 'preparation', shortLabel: 'Préparation', icon: 'signin', owner: 'Étape 2', description: 'Contrôles et entrée au bloc', complete: Boolean(props.surgicalRequest.preoperative_validated_at && props.surgicalRequest.block_entry) },
    { id: 'intervention', shortLabel: 'Intervention', icon: 'activity', owner: 'Étape 3', description: 'Acte opératoire et consommables', complete: Boolean(props.surgicalRequest.intervention?.ended_at) },
    { id: 'block-exit', shortLabel: 'Sortie du bloc', icon: 'signout', owner: 'Étape 4', description: 'Bilan et surveillance postopératoire', complete: Boolean(props.surgicalRequest.block_exit) },
    { id: 'followup', shortLabel: 'Suivi & clôture', icon: 'check-circle', owner: 'Étape 5', description: 'Complications, compte rendu et sortie', complete: Boolean(props.surgicalRequest.report?.validated_at) },
]);
const tabs = computed(() => isAnesthesiaWorkspace.value ? anesthesiaTabs.value : surgeryTabs.value);
const currentTabIndex = computed(() => tabs.value.findIndex((tab) => tab.id === activeTab.value));
const currentTab = computed(() => tabs.value[currentTabIndex.value] ?? tabs.value[0]);
const previousTab = computed(() => tabs.value[currentTabIndex.value - 1] ?? null);
const nextTab = computed(() => tabs.value[currentTabIndex.value + 1] ?? null);
const surgeonUsers = computed(() => props.users.filter((user) => user.role?.code === 'SURGERY'));
const anesthesiaAssessment = computed(() => props.surgicalRequest.anesthesia_record?.paraclinical_data ?? {});
const surgeryAuthorizationLabel = computed(() => {
    if (anesthesiaAssessment.value.surgery_authorized === true) return 'Autorisée';
    if (anesthesiaAssessment.value.surgery_authorized === false) return 'Non autorisée';
    return 'Décision non renseignée';
});
const workspaceMeta = computed(() => isAnesthesiaWorkspace.value
    ? { label: 'Anesthésie', tone: 'violet', returnUrl: '/anesthesia', returnLabel: 'Dossiers anesthésie' }
    : { label: 'Chirurgie', tone: 'emerald', returnUrl: '/surgery', returnLabel: 'Dossiers chirurgie' });

const TEAM_FUNCTION_LABELS = {
    SURGEON: 'Chirurgien',
    ANESTHETIST: 'Anesthésiste',
    OR_NURSE: 'Infirmier de bloc',
    PARAMEDICAL: 'Paramédical',
};

// `datetime-local` inputs need "YYYY-MM-DDTHH:mm" — used to pre-fill a
// correction form with the value already saved server-side.
const toDatetimeLocal = (value) => (value ? value.slice(0, 16).replace(' ', 'T') : '');

// --- En-tête : acte, notes (surgery.update) ---
const showEditForm = ref(false);
const editForm = useForm({
    catalog_item_uuid: props.surgicalRequest.catalog_item?.uuid ?? '',
    procedure_details: props.surgicalRequest.procedure_details ?? '',
    notes: props.surgicalRequest.notes ?? '',
});
const selectedEditProcedure = computed(() => props.procedures.find((procedure) => procedure.uuid === editForm.catalog_item_uuid) ?? null);
const editUsesOtherProcedure = computed(() => selectedEditProcedure.value?.code === 'SURG-OTHER');
const submitEdit = () => editForm
    .transform((data) => ({ ...data, procedure_details: editUsesOtherProcedure.value ? data.procedure_details : null }))
    .put(base.value, {
        preserveScroll: true,
        onSuccess: () => { showEditForm.value = false; },
    });

// --- Programmation (surgery.schedule) — also used to CORRECT a scheduling
// mistake (wrong surgeon/date) as long as the intervention hasn't started;
// see SurgicalRequest::schedule()'s own doc comment for why the same
// permission covers both. ---
const canEditSchedule = computed(() => ['PENDING', 'SCHEDULED', 'PREOPERATIVE_VALIDATED'].includes(props.surgicalRequest.status));
const isAlreadyScheduled = computed(() => Boolean(props.surgicalRequest.surgeon));
const showScheduleForm = ref(false);
const scheduleForm = useForm({
    surgeon_id: props.surgicalRequest.surgeon?.id ?? '',
    scheduled_at: toDatetimeLocal(props.surgicalRequest.scheduled_at),
});
const submitSchedule = () => scheduleForm.post(`${base.value}/schedule`, {
    preserveScroll: true,
    onSuccess: () => { showScheduleForm.value = false; },
});

// --- Préparation du bloc (surgery.preparation.update) ---
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
const teamForm = useForm({ user_id: '', function: props.teamFunctions[0]?.value ?? '' });
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
const showInterventionForm = ref(!props.surgicalRequest.intervention);
const interventionCreateForm = useForm({ performed_by: props.surgicalRequest.surgeon?.id ?? '', started_at: '', notes: '' });
const submitInterventionCreate = () => interventionCreateForm.post(`${base.value}/intervention`, {
    preserveScroll: true,
    onSuccess: () => { showInterventionForm.value = false; },
});

const editingIntervention = ref(false);
const interventionUpdateForm = useForm({
    ended_at: props.surgicalRequest.intervention?.ended_at ?? '',
    procedure_summary: props.surgicalRequest.intervention?.procedure_summary ?? '',
    notes: props.surgicalRequest.intervention?.notes ?? '',
});
const submitInterventionUpdate = () => {
    interventionUpdateForm.transform((data) => ({ ...data, _method: 'put' })).post(
        `${base.value}/intervention/${props.surgicalRequest.intervention.id}`,
        { preserveScroll: true, onSuccess: () => { editingIntervention.value = false; } },
    );
};

// --- Consommables (surgery.consumables.create) — traçabilité seule, sans
// prix. Chirurgie n'a aucune action de facturation : la Réception voit ces
// données seront transformées en prestations par le futur circuit facturable
// Chirurgie. Aucun prix ni encaissement n'est traité ici. ---
const showConsumableForm = ref(false);
const consumableForm = useForm({ label: '', quantity: 1, unit: '' });
const submitConsumable = () => consumableForm.post(`${base.value}/consumables`, {
    preserveScroll: true,
    onSuccess: () => { consumableForm.reset(); },
});
const removingConsumableId = ref(null);
const removeConsumable = (item) => {
    removingConsumableId.value = item.id;
    router.delete(`${base.value}/consumables/${item.id}`, {
        preserveScroll: true,
        onFinish: () => { removingConsumableId.value = null; },
    });
};

// --- Complications (surgery.complications.create) ---
const showComplicationForm = ref(false);
const complicationForm = useForm({ description: '' });
const submitComplication = () => complicationForm.post(`${base.value}/complications`, {
    preserveScroll: true,
    onSuccess: () => { complicationForm.reset(); showComplicationForm.value = false; },
});

// --- Soins péri/postopératoires (surgery.care.create / surgery.postoperative_care.create) ---
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
const showReportCreateForm = ref(!props.surgicalRequest.report);
const reportCreateForm = useForm({ content: '' });
const submitReportCreate = () => reportCreateForm.post(`${base.value}/report`, {
    preserveScroll: true,
    onSuccess: () => { showReportCreateForm.value = false; },
});

const editingReport = ref(false);
const reportUpdateForm = useForm({ content: props.surgicalRequest.report?.content ?? '' });
const submitReportUpdate = () => {
    reportUpdateForm.transform((data) => ({ ...data, _method: 'put' })).post(
        `${base.value}/report/${props.surgicalRequest.report.id}`,
        { preserveScroll: true, onSuccess: () => { editingReport.value = false; } },
    );
};
const validatingReport = ref(false);
const validateReport = () => {
    validatingReport.value = true;
    router.post(`${base.value}/report/${props.surgicalRequest.report.id}/validate`, {}, {
        preserveScroll: true,
        onFinish: () => { validatingReport.value = false; },
    });
};

// --- Sortie (surgery.discharge.create) ---
const showDischargeForm = ref(false);
const dischargeForm = useForm({ notes: '' });
const submitDischarge = () => dischargeForm.post(`${base.value}/discharge`, {
    preserveScroll: true,
    onSuccess: () => { showDischargeForm.value = false; },
});
</script>

<template>
    <Head :title="surgicalRequest.procedure_name" />

    <div class="mx-auto w-full max-w-[1500px] space-y-4">
        <EnTeteDossierChirurgical :surgical-request="surgicalRequest" :workspace="workspace">
            <template #actions>
                <Button :as="Link" :href="workspaceMeta.returnUrl" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">{{ workspaceMeta.returnLabel }}</span></Button>
            </template>
        </EnTeteDossierChirurgical>

        <nav class="overflow-x-auto rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-900 dark:bg-gray-950 print:hidden" :aria-label="`Étapes ${workspaceMeta.label}`">
            <ol :class="['flex items-center', tabs.length > 3 ? 'min-w-[780px]' : 'min-w-[520px]', 'lg:min-w-0']" role="tablist">
                <li v-for="(tab, index) in tabs" :key="tab.id" class="flex min-w-0 flex-1 items-center last:flex-none">
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="activeTab === tab.id"
                        :aria-current="activeTab === tab.id ? 'step' : undefined"
                        class="group flex shrink-0 items-center gap-2 rounded-md px-1 py-1 text-start outline-none transition-colors focus-visible:ring-2 focus-visible:ring-primary-300"
                        @click="activeTab = tab.id"
                    >
                        <span
                            :class="[
                                'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-bold transition-colors',
                                activeTab === tab.id
                                    ? (isAnesthesiaWorkspace ? 'border-2 border-violet-600 bg-white text-violet-600 dark:bg-gray-950' : 'border-2 border-primary-600 bg-white text-primary-600 dark:bg-gray-950')
                                    : tab.complete
                                        ? (isAnesthesiaWorkspace ? 'bg-violet-600 text-white' : 'bg-primary-600 text-white')
                                        : 'bg-gray-100 text-slate-400 dark:bg-gray-900',
                            ]"
                        >
                            <Icon v-if="tab.complete && activeTab !== tab.id" name="check" />
                            <span v-else>{{ index + 1 }}</span>
                        </span>
                        <span
                            :class="[
                                'whitespace-nowrap text-xs font-bold transition-colors',
                                activeTab === tab.id
                                    ? (isAnesthesiaWorkspace ? 'text-violet-600' : 'text-primary-600')
                                    : 'text-slate-400 group-hover:text-slate-600 dark:group-hover:text-slate-200',
                            ]"
                        >{{ tab.shortLabel }}</span>
                    </button>
                    <span
                        v-if="index < tabs.length - 1"
                        :class="[
                            'mx-3 h-px min-w-6 flex-1 transition-colors',
                            index < currentTabIndex
                                ? (isAnesthesiaWorkspace ? 'bg-violet-300 dark:bg-violet-800' : 'bg-primary-300 dark:bg-primary-800')
                                : 'bg-gray-200 dark:bg-gray-800',
                        ]"
                        aria-hidden="true"
                    ></span>
                </li>
            </ol>
        </nav>

        <main class="min-w-0 space-y-4">
            <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
            <CareSummaryReadOnly
                v-if="careSummary && ((isAnesthesiaWorkspace && activeTab === 'consultation') || (!isAnesthesiaWorkspace && activeTab === 'preparation'))"
                :care-summary="careSummary"
            />

            <ConsultationPreAnesthesique
                v-if="isAnesthesiaWorkspace && activeTab === 'consultation'"
                :surgical-request="surgicalRequest"
                :care-summary="careSummary"
                :can-create="can('anesthesia.create')"
                :can-update="can('anesthesia.update')"
            />

            <ExamenParaclinique
                v-if="isAnesthesiaWorkspace && activeTab === 'paraclinical'"
                :surgical-request="surgicalRequest"
                :care-summary="careSummary"
                :can-create="can('anesthesia.create')"
                :can-update="can('anesthesia.update')"
                :can-validate="can('anesthesia.validate')"
            />

            <ConduiteAnesthesique
                v-if="isAnesthesiaWorkspace && activeTab === 'peroperative'"
                :surgical-request="surgicalRequest"
                :reference-items="anesthesiaItems"
                :can-create="can('anesthesia.create')"
                :can-update="can('anesthesia.update')"
                :can-validate="can('anesthesia.validate')"
            />

            <!-- Demande -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'case'" class="shadow-sm xl:col-span-5">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="file-text" /></span>1. Demande</h2>
                    <Button v-if="can('surgery.update') && !showEditForm" size="sm" variant="white-outline" type="button" @click="showEditForm = true"><Icon class="text-base" name="edit" /><span class="ms-1.5">Modifier</span></Button>
                </div>

                <form v-if="showEditForm" class="space-y-3" @submit.prevent="submitEdit">
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="catalog_item_uuid">Intervention</FormLabel>
                        <select id="catalog_item_uuid" v-model="editForm.catalog_item_uuid" class="block min-h-11 w-full rounded-md border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required><option value="" disabled>Choisir dans le référentiel</option><option v-for="procedure in procedures" :key="procedure.uuid" :value="procedure.uuid">{{ procedure.name }}</option></select>
                        <FormError v-if="editForm.errors.catalog_item_uuid">{{ editForm.errors.catalog_item_uuid }}</FormError>
                    </FormGroup>
                    <FormGroup v-if="editUsesOtherProcedure" class="!mb-0"><FormLabel class="mb-1.5" for="procedure_details">Précision obligatoire</FormLabel><Input id="procedure_details" v-model="editForm.procedure_details" required /><FormError v-if="editForm.errors.procedure_details">{{ editForm.errors.procedure_details }}</FormError></FormGroup>
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="notes">Notes</FormLabel>
                        <textarea id="notes" v-model="editForm.notes" rows="2" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                    </FormGroup>
                    <div class="flex justify-end gap-2">
                        <Button size="sm" variant="white-outline" type="button" @click="showEditForm = false">Annuler</Button>
                        <Button size="sm" variant="primary" type="submit" :disabled="editForm.processing">Enregistrer</Button>
                    </div>
                </form>
                <dl v-else class="space-y-2 text-sm">
                    <div v-if="surgicalRequest.procedure_details"><dt class="text-xs text-slate-400">Précision de l’intervention</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.procedure_details }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Notes</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.notes ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Demandée par</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.requested_by?.name ?? '—' }}</dd></div>
                </dl>
                </CardBody>
            </Card>

            <!-- Programmation & préparation -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'case'" class="shadow-sm xl:col-span-7">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="calendar" /></span>2. Programmation</h2>
                    <Button v-if="isAlreadyScheduled && canEditSchedule && can('surgery.schedule') && !showScheduleForm" size="sm" variant="white-outline" type="button" @click="showScheduleForm = true"><Icon class="text-base" name="edit" /><span class="ms-1.5">Corriger</span></Button>
                </div>

                <template v-if="canEditSchedule && can('surgery.schedule')">
                    <form v-if="showScheduleForm" class="space-y-3" @submit.prevent="submitSchedule">
                        <p v-if="isAlreadyScheduled" class="flex items-start gap-2 rounded border border-gray-200 bg-gray-50/70 p-2.5 text-xs leading-5 text-slate-400 dark:border-gray-900 dark:bg-gray-1000/40">
                            <Icon class="mt-0.5 shrink-0 text-sm" name="info" />
                            Erreur de saisie ? Vous pouvez corriger le chirurgien ou la date tant que l'intervention n'a pas démarré.
                        </p>
                        <FormGroup class="!mb-0">
                            <FormLabel class="mb-1.5" for="surgeon_id">Chirurgien <span class="text-red-500">*</span></FormLabel>
                            <select id="surgeon_id" v-model="scheduleForm.surgeon_id" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required>
                                <option value="" disabled>Choisir</option>
                                <option v-for="user in surgeonUsers" :key="user.id" :value="user.id">{{ user.name }}</option>
                            </select>
                            <FormError v-if="scheduleForm.errors.surgeon_id">{{ scheduleForm.errors.surgeon_id }}</FormError>
                        </FormGroup>
                        <FormGroup class="!mb-0">
                            <FormLabel class="mb-1.5" for="scheduled_at">Date et heure <span class="text-red-500">*</span></FormLabel>
                            <input id="scheduled_at" v-model="scheduleForm.scheduled_at" type="datetime-local" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required />
                            <FormError v-if="scheduleForm.errors.scheduled_at">{{ scheduleForm.errors.scheduled_at }}</FormError>
                        </FormGroup>
                        <div class="flex justify-end gap-2">
                            <Button size="sm" variant="white-outline" type="button" @click="showScheduleForm = false">Annuler</Button>
                            <Button size="sm" variant="primary" type="submit" :disabled="scheduleForm.processing">{{ isAlreadyScheduled ? 'Corriger' : 'Programmer' }}</Button>
                        </div>
                    </form>
                    <Button v-else-if="!isAlreadyScheduled" size="sm" variant="primary" type="button" @click="showScheduleForm = true"><Icon class="text-base" name="calendar" /><span class="ms-1.5">Programmer l'intervention</span></Button>
                    <dl v-else class="mb-4 space-y-2 text-sm">
                        <div><dt class="text-xs text-slate-400">Chirurgien</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.surgeon?.name ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Programmée le</dt><dd class="text-slate-600 dark:text-slate-300">{{ formatDateTime(surgicalRequest.scheduled_at) ?? '—' }}</dd></div>
                    </dl>
                </template>
                <dl v-else class="mb-4 space-y-2 text-sm">
                    <div><dt class="text-xs text-slate-400">Chirurgien</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.surgeon?.name ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Programmée le</dt><dd class="text-slate-600 dark:text-slate-300">{{ formatDateTime(surgicalRequest.scheduled_at) ?? '—' }}</dd></div>
                </dl>

                <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-900">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400">Préparation du bloc</h3>
                        <Button v-if="can('surgery.preparation.update') && !showPreparationForm" size="xs" variant="white-outline" type="button" @click="showPreparationForm = true"><Icon name="edit" /></Button>
                    </div>
                    <form v-if="showPreparationForm" class="space-y-3" @submit.prevent="submitPreparation">
                        <Input v-model="preparationForm.operating_room" placeholder="Salle / bloc" />
                        <textarea v-model="preparationForm.preparation_notes" rows="2" placeholder="Matériel, consignes…" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                        <div class="flex justify-end gap-2">
                            <Button size="sm" variant="white-outline" type="button" @click="showPreparationForm = false">Annuler</Button>
                            <Button size="sm" variant="primary" type="submit" :disabled="preparationForm.processing">Enregistrer</Button>
                        </div>
                    </form>
                    <dl v-else class="space-y-2 text-sm">
                        <div><dt class="text-xs text-slate-400">Salle</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.operating_room ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Notes</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.preparation_notes ?? '—' }}</dd></div>
                    </dl>
                </div>
                </CardBody>
            </Card>

            <ValidationPreoperatoire
                v-if="!isAnesthesiaWorkspace && activeTab === 'preparation'"
                :surgical-request="surgicalRequest"
                :can-update="can('surgery.update')"
                :can-validate="surgicalRequest.status === 'SCHEDULED' && can('surgery.preoperative.validate')"
            />

            <!-- Équipe de bloc -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'case'" :class="['shadow-sm', can('anesthesia.view') ? 'xl:col-span-7' : 'xl:col-span-12']">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="users" /></span>4. Équipe de bloc</h2>
                    <Button v-if="can('surgery.update') && !showTeamForm" size="sm" variant="white-outline" type="button" @click="showTeamForm = true"><Icon class="text-base" name="plus" /><span class="ms-1.5">Ajouter</span></Button>
                </div>
                <form v-if="showTeamForm" class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_180px_auto]" @submit.prevent="submitTeam">
                    <select v-model="teamForm.user_id" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required>
                        <option value="" disabled>Choisir une personne</option>
                        <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                    </select>
                    <select v-model="teamForm.function" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required>
                        <option v-for="fn in teamFunctions" :key="fn.value" :value="fn.value">{{ TEAM_FUNCTION_LABELS[fn.value] }}</option>
                    </select>
                    <Button size="sm" variant="primary" type="submit" :disabled="teamForm.processing">Ajouter</Button>
                </form>
                <ul v-if="surgicalRequest.team_members?.length" class="space-y-2">
                    <li v-for="member in surgicalRequest.team_members" :key="member.id" class="flex items-center justify-between gap-2 rounded border border-gray-200 px-3 py-2 text-sm dark:border-gray-900">
                        <span class="text-slate-700 dark:text-white">{{ member.user?.name }}</span>
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 dark:bg-slate-900 dark:text-slate-400">{{ TEAM_FUNCTION_LABELS[member.function] ?? member.function }}</span>
                            <button
                                v-if="can('surgery.update')"
                                type="button"
                                class="text-slate-400 hover:text-red-600 disabled:opacity-50"
                                :disabled="removingMemberId === member.id"
                                :aria-label="`Retirer ${member.user?.name}`"
                                title="Retirer (erreur d'assignation)"
                                @click="removeMember(member)"
                            >
                                <Icon class="text-base" name="cross" />
                            </button>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-400">Aucun membre assigné.</p>
                </CardBody>
            </Card>

            <!-- Synthèse anesthésie : uniquement avec anesthesia.view. -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'case' && can('anesthesia.view')" class="border-violet-100 shadow-sm dark:border-violet-950 xl:col-span-5">
                <CardBody>
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-violet-700 dark:text-violet-300"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-violet-100 text-violet-600 dark:bg-violet-950 dark:text-violet-300"><Icon class="text-base" name="shield-check" /></span>Synthèse anesthésie</h2>
                            <p class="mt-1 text-xs text-slate-400">Lecture ciblée des informations nécessaires à la continuité du bloc.</p>
                        </div>
                        <Button :as="Link" :href="`/anesthesia/${surgicalRequest.uuid}`" size="sm" variant="white-outline"><Icon name="external" /><span class="ms-1.5">Ouvrir le volet anesthésie</span></Button>
                    </div>
                    <dl v-if="surgicalRequest.anesthesia_record" class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs text-slate-400">Anesthésiste</dt><dd class="font-medium text-slate-700 dark:text-white">{{ surgicalRequest.anesthesia_record.anesthetist?.name ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Décision</dt><dd :class="['font-bold', anesthesiaAssessment.surgery_authorized === false ? 'text-red-600' : anesthesiaAssessment.surgery_authorized === true ? 'text-green-600' : 'text-amber-600']">{{ surgeryAuthorizationLabel }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Classe ASA</dt><dd class="text-slate-600 dark:text-slate-300">{{ anesthesiaAssessment.asa_class || '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Plan anesthésique</dt><dd class="text-slate-600 dark:text-slate-300">{{ anesthesiaAssessment.anesthesia_plan || '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs text-slate-400">Évaluation</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.anesthesia_record.assessment_validated_at ? `Validée le ${formatDateTime(surgicalRequest.anesthesia_record.assessment_validated_at)}` : 'Brouillon / non validée' }}</dd></div>
                    </dl>
                    <p v-else class="text-sm text-slate-400">Aucune évaluation anesthésique enregistrée.</p>
                </CardBody>
            </Card>

            <EntreeBloc v-if="!isAnesthesiaWorkspace && activeTab === 'preparation'" :surgical-request="surgicalRequest" :can-edit="can('surgery.preparation.update')" />

            <!-- Intervention -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'intervention'" class="shadow-sm xl:col-span-7">
                <CardBody>
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="activity" /></span>6. Intervention</h2>

                <template v-if="!surgicalRequest.intervention">
                    <form v-if="can('surgery.intervention.create')" class="space-y-3" @submit.prevent="submitInterventionCreate">
                        <select v-model="interventionCreateForm.performed_by" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                            <option value="">Opérateur (optionnel)</option>
                            <option v-for="user in surgeonUsers" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <input v-model="interventionCreateForm.started_at" type="datetime-local" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" />
                        <textarea v-model="interventionCreateForm.notes" rows="2" placeholder="Notes de début d'intervention" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                        <FormError v-if="interventionCreateForm.errors.performed_by">{{ interventionCreateForm.errors.performed_by }}</FormError>
                        <Button size="sm" variant="primary" type="submit" :disabled="interventionCreateForm.processing || surgicalRequest.status !== 'PREOPERATIVE_VALIDATED'">
                            <Icon class="text-base" name="activity" /><span class="ms-1.5">Démarrer l'intervention</span>
                        </Button>
                        <p v-if="surgicalRequest.status !== 'PREOPERATIVE_VALIDATED'" class="text-xs text-slate-400">Le bilan préopératoire doit d'abord être validé.</p>
                    </form>
                </template>
                <template v-else>
                    <div class="mb-3 flex justify-end">
                        <Button v-if="can('surgery.intervention.update') && !editingIntervention" size="xs" variant="white-outline" type="button" @click="editingIntervention = true"><Icon name="edit" /></Button>
                    </div>
                    <form v-if="editingIntervention" class="space-y-3" @submit.prevent="submitInterventionUpdate">
                        <input v-model="interventionUpdateForm.ended_at" type="datetime-local" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" />
                        <textarea v-model="interventionUpdateForm.procedure_summary" rows="3" placeholder="Résumé de l'acte réalisé" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                        <textarea v-model="interventionUpdateForm.notes" rows="2" placeholder="Notes complémentaires" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                        <div class="flex justify-end gap-2">
                            <Button size="sm" variant="white-outline" type="button" @click="editingIntervention = false">Annuler</Button>
                            <Button size="sm" variant="primary" type="submit" :disabled="interventionUpdateForm.processing">Enregistrer</Button>
                        </div>
                    </form>
                    <dl v-else class="space-y-2 text-sm">
                        <div><dt class="text-xs text-slate-400">Opérateur</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.intervention.performed_by?.name ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Début</dt><dd class="text-slate-600 dark:text-slate-300">{{ formatDateTime(surgicalRequest.intervention.started_at) ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Fin</dt><dd class="text-slate-600 dark:text-slate-300">{{ formatDateTime(surgicalRequest.intervention.ended_at) ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Résumé</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.intervention.procedure_summary ?? '—' }}</dd></div>
                    </dl>
                </template>
                </CardBody>
            </Card>

            <!-- Consommables -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'intervention'" class="shadow-sm xl:col-span-5">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="cards" /></span>7. Consommables</h2>
                    <Button v-if="can('surgery.consumables.create') && !showConsumableForm" size="sm" variant="white-outline" type="button" @click="showConsumableForm = true"><Icon class="text-base" name="plus" /><span class="ms-1.5">Ajouter</span></Button>
                </div>
                <form v-if="showConsumableForm" class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_90px_110px_auto]" @submit.prevent="submitConsumable">
                    <Input v-model="consumableForm.label" placeholder="Libellé" required />
                    <Input v-model="consumableForm.quantity" type="number" min="1" placeholder="Qté" required />
                    <Input v-model="consumableForm.unit" placeholder="Unité" />
                    <Button size="sm" variant="primary" type="submit" :disabled="consumableForm.processing">Ajouter</Button>
                </form>
                <ul v-if="surgicalRequest.consumables?.length" class="space-y-1.5 text-sm">
                    <li v-for="item in surgicalRequest.consumables" :key="item.id" class="flex items-center justify-between gap-2 border-b border-gray-100 py-1.5 last:border-0 dark:border-gray-900">
                        <span class="text-slate-600 dark:text-slate-300">{{ item.label }}</span>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-slate-400">{{ item.quantity }} {{ item.unit ?? '' }}</span>
                            <button
                                v-if="can('surgery.consumables.create')"
                                type="button"
                                class="text-slate-400 hover:text-red-600 disabled:opacity-50"
                                :disabled="removingConsumableId === item.id"
                                :aria-label="`Retirer ${item.label}`"
                                title="Retirer (erreur de saisie)"
                                @click="removeConsumable(item)"
                            >
                                <Icon class="text-base" name="cross" />
                            </button>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-400">Aucun consommable enregistré.</p>
                <p class="mt-3 flex items-start gap-2 rounded border border-gray-200 bg-gray-50/70 p-2.5 text-xs leading-5 text-slate-400 dark:border-gray-900 dark:bg-gray-1000/40">
                    <Icon class="mt-0.5 shrink-0 text-sm" name="info" />
                    Traçabilité clinique uniquement. Aucune facturation ni aucun encaissement n'est effectué ici ; le futur circuit facturable Chirurgie devra relier ces données au référentiel financier de la Réception.
                </p>
                </CardBody>
            </Card>

            <SortieBloc
                v-if="!isAnesthesiaWorkspace && activeTab === 'block-exit'"
                :surgical-request="surgicalRequest"
                :can-edit="can('surgery.intervention.update')"
                :can-record-postoperative="can('surgery.postoperative_care.create')"
            />

            <!-- Complications -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'followup'" class="shadow-sm xl:col-span-4">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-300"><Icon class="text-base" name="alert-circle" /></span>8. Complications</h2>
                    <Button v-if="can('surgery.complications.create') && !showComplicationForm" size="sm" variant="white-outline" type="button" @click="showComplicationForm = true"><Icon class="text-base" name="plus" /><span class="ms-1.5">Ajouter</span></Button>
                </div>
                <form v-if="showComplicationForm" class="mb-4 space-y-2" @submit.prevent="submitComplication">
                    <textarea v-model="complicationForm.description" rows="2" placeholder="Description de la complication" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required></textarea>
                    <div class="flex justify-end gap-2">
                        <Button size="sm" variant="white-outline" type="button" @click="showComplicationForm = false">Annuler</Button>
                        <Button size="sm" variant="primary" type="submit" :disabled="complicationForm.processing">Enregistrer</Button>
                    </div>
                </form>
                <ul v-if="surgicalRequest.complications?.length" class="space-y-2 text-sm">
                    <li v-for="item in surgicalRequest.complications" :key="item.id" class="rounded border border-red-100 bg-red-50/50 px-3 py-2 text-red-700 dark:border-red-950 dark:bg-red-950/20 dark:text-red-300">
                        {{ item.description }}
                        <span class="mt-1 block text-xs opacity-70">{{ item.reported_by?.name }} · {{ formatDateTime(item.reported_at) }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-400">Aucune complication signalée.</p>
                <p class="mt-3 flex items-start gap-2 rounded border border-gray-200 bg-gray-50/70 p-2.5 text-xs leading-5 text-slate-400 dark:border-gray-900 dark:bg-gray-1000/40">
                    <Icon class="mt-0.5 shrink-0 text-sm" name="info" />
                    Historique non modifiable, comme un diagnostic : une correction s'ajoute en nouvelle entrée plutôt que d'écraser la précédente.
                </p>
                </CardBody>
            </Card>

            <!-- Soins -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'followup'" class="shadow-sm xl:col-span-8">
                <CardBody>
                <h2 class="mb-1 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="user-check" /></span>9. Suivi péri- et postopératoire</h2>
                <p class="mb-4 ms-10 text-xs text-slate-400">Notes propres au passage au bloc ; elles ne modifient pas la fiche déjà réalisée dans le module Soins.</p>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400">Peropératoires</h3>
                            <Button v-if="can('surgery.care.create') && !showPerioCareForm" size="xs" variant="white-outline" type="button" @click="showPerioCareForm = true"><Icon name="plus" /></Button>
                        </div>
                        <form v-if="showPerioCareForm" class="mb-3 space-y-2" @submit.prevent="submitPerioCare">
                            <textarea v-model="perioCareForm.note" rows="2" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required></textarea>
                            <div class="flex justify-end gap-2">
                                <Button size="xs" variant="white-outline" type="button" @click="showPerioCareForm = false">Annuler</Button>
                                <Button size="xs" variant="primary" type="submit" :disabled="perioCareForm.processing">Ajouter</Button>
                            </div>
                        </form>
                        <ul v-if="surgicalRequest.care_notes?.filter((n) => n.phase === 'PERIOPERATIVE').length" class="space-y-1.5 text-sm">
                            <li v-for="note in surgicalRequest.care_notes.filter((n) => n.phase === 'PERIOPERATIVE')" :key="note.id" class="text-slate-600 dark:text-slate-300">
                                {{ note.note }}<span class="block text-xs text-slate-400">{{ formatDateTime(note.recorded_at) }}</span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-slate-400">Aucun soin peropératoire.</p>
                    </div>
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400">Postopératoires</h3>
                            <Button v-if="can('surgery.postoperative_care.create') && !showPostCareForm" size="xs" variant="white-outline" type="button" @click="showPostCareForm = true"><Icon name="plus" /></Button>
                        </div>
                        <form v-if="showPostCareForm" class="mb-3 space-y-2" @submit.prevent="submitPostCare">
                            <textarea v-model="postCareForm.note" rows="2" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required></textarea>
                            <div class="flex justify-end gap-2">
                                <Button size="xs" variant="white-outline" type="button" @click="showPostCareForm = false">Annuler</Button>
                                <Button size="xs" variant="primary" type="submit" :disabled="postCareForm.processing">Ajouter</Button>
                            </div>
                        </form>
                        <ul v-if="surgicalRequest.care_notes?.filter((n) => n.phase === 'POSTOPERATIVE').length" class="space-y-1.5 text-sm">
                            <li v-for="note in surgicalRequest.care_notes.filter((n) => n.phase === 'POSTOPERATIVE')" :key="note.id" class="text-slate-600 dark:text-slate-300">
                                {{ note.note }}<span class="block text-xs text-slate-400">{{ formatDateTime(note.recorded_at) }}</span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-slate-400">Aucun soin postopératoire.</p>
                    </div>
                </div>
                <p class="mt-4 flex items-start gap-2 rounded border border-gray-200 bg-gray-50/70 p-2.5 text-xs leading-5 text-slate-400 dark:border-gray-900 dark:bg-gray-1000/40">
                    <Icon class="mt-0.5 shrink-0 text-sm" name="info" />
                    Historique non modifiable : une erreur de saisie se corrige en ajoutant une nouvelle note.
                </p>
                </CardBody>
            </Card>

            <!-- Compte rendu -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'followup'" :class="['shadow-sm', ['COMPLETED', 'DISCHARGED'].includes(surgicalRequest.status) ? 'xl:col-span-8' : 'xl:col-span-12']">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="file-text" /></span>10. Compte rendu opératoire</h2>
                    <div v-if="surgicalRequest.report" class="flex gap-2">
                        <Button v-if="can('surgery.report.update') && !surgicalRequest.report.validated_at && !editingReport" size="sm" variant="white-outline" type="button" @click="editingReport = true"><Icon class="text-base" name="edit" /><span class="ms-1.5">Modifier</span></Button>
                        <Button v-if="can('surgery.report.validate') && !surgicalRequest.report.validated_at" size="sm" variant="primary" type="button" :disabled="validatingReport" @click="validateReport"><Icon class="text-base" name="check" /><span class="ms-1.5">Valider</span></Button>
                    </div>
                </div>

                <form v-if="!surgicalRequest.report && can('surgery.report.create')" class="space-y-3" @submit.prevent="submitReportCreate">
                    <textarea v-model="reportCreateForm.content" rows="5" placeholder="Compte rendu opératoire détaillé…" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required></textarea>
                    <FormError v-if="reportCreateForm.errors.content">{{ reportCreateForm.errors.content }}</FormError>
                    <Button size="sm" variant="primary" type="submit" :disabled="reportCreateForm.processing">Créer le compte rendu</Button>
                </form>
                <template v-else-if="surgicalRequest.report">
                    <form v-if="editingReport" class="space-y-3" @submit.prevent="submitReportUpdate">
                        <textarea v-model="reportUpdateForm.content" rows="5" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required></textarea>
                        <div class="flex justify-end gap-2">
                            <Button size="sm" variant="white-outline" type="button" @click="editingReport = false">Annuler</Button>
                            <Button size="sm" variant="primary" type="submit" :disabled="reportUpdateForm.processing">Enregistrer</Button>
                        </div>
                    </form>
                    <div v-else>
                        <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ surgicalRequest.report.content }}</p>
                        <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-400">
                            <div>Rédigé par {{ surgicalRequest.report.author?.name ?? '—' }}</div>
                            <div v-if="surgicalRequest.report.validated_by" class="font-bold text-green-600 dark:text-green-400">Validé par {{ surgicalRequest.report.validator?.name }} · {{ formatDateTime(surgicalRequest.report.validated_at) }}</div>
                        </dl>
                    </div>
                </template>
                <p v-else class="text-sm text-slate-400">Aucun compte rendu.</p>
                </CardBody>
            </Card>

            <!-- Sortie -->
            <Card v-if="!isAnesthesiaWorkspace && activeTab === 'followup' && ['COMPLETED', 'DISCHARGED'].includes(surgicalRequest.status)" class="shadow-sm xl:col-span-4">
                <CardBody>
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="signout" /></span>11. Sortie</h2>

                <form v-if="surgicalRequest.status === 'COMPLETED' && can('surgery.discharge.create')" class="space-y-3" @submit.prevent="submitDischarge">
                    <textarea v-model="dischargeForm.notes" rows="2" placeholder="Consignes de sortie…" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                    <Button size="sm" variant="primary" type="submit" :disabled="dischargeForm.processing"><Icon class="text-base" name="check-circle" /><span class="ms-1.5">Enregistrer la sortie</span></Button>
                </form>
                <dl v-else-if="surgicalRequest.status === 'DISCHARGED'" class="space-y-2 text-sm">
                    <div><dt class="text-xs text-slate-400">Sortie enregistrée par</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.discharged_by?.name ?? '—' }} · {{ formatDateTime(surgicalRequest.discharged_at) }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Consignes</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.discharge_notes ?? '—' }}</dd></div>
                </dl>
                </CardBody>
            </Card>
            </div>

            <footer class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-900 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between print:hidden">
                <Button v-if="previousTab" size="rg" variant="white-outline" type="button" @click="activeTab = previousTab.id"><Icon name="arrow-left" /><span class="ms-2">{{ previousTab.shortLabel }}</span></Button>
                <span v-else></span>
                <div class="text-center"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ currentTab.owner }} sur {{ tabs.length }}</p><p class="mt-0.5 text-xs font-semibold text-slate-600 dark:text-slate-300">{{ currentTab.shortLabel }}</p></div>
                <Button v-if="nextTab" size="rg" type="button" @click="activeTab = nextTab.id"><span class="me-2">{{ nextTab.shortLabel }}</span><Icon name="arrow-right" /></Button>
                <span v-else></span>
            </footer>
        </main>
    </div>
</template>
