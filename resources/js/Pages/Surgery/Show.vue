<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    surgicalRequest: Object,
    users: Array,
    teamFunctions: Array,
});

const page = usePage();
const { can } = usePermissions();
const status = computed(() => page.props.flash?.status);
const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);

const STATUS_LABELS = {
    PENDING: 'En attente',
    SCHEDULED: 'Programmée',
    PREOPERATIVE_VALIDATED: 'Bilan préop. validé',
    IN_PROGRESS: 'En cours',
    COMPLETED: 'Terminée',
    DISCHARGED: 'Sortie',
};
const STATUS_VARIANTS = {
    PENDING: 'bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-400',
    SCHEDULED: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    PREOPERATIVE_VALIDATED: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
    IN_PROGRESS: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-950 dark:text-yellow-300',
    COMPLETED: 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',
    DISCHARGED: 'bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-400',
};
const TEAM_FUNCTION_LABELS = {
    SURGEON: 'Chirurgien',
    ANESTHETIST: 'Anesthésiste',
    OR_NURSE: 'Infirmier de bloc',
    PARAMEDICAL: 'Paramédical',
};
const statusLabel = (s) => STATUS_LABELS[s] ?? s;
const statusVariant = (s) => STATUS_VARIANTS[s] ?? STATUS_VARIANTS.PENDING;

// --- En-tête : acte, notes, bilan préopératoire (surgery.update) ---
const showEditForm = ref(false);
const editForm = useForm({
    procedure_name: props.surgicalRequest.procedure_name,
    notes: props.surgicalRequest.notes ?? '',
    preoperative_notes: props.surgicalRequest.preoperative_notes ?? '',
});
const submitEdit = () => editForm.put(base.value, {
    preserveScroll: true,
    onSuccess: () => { showEditForm.value = false; },
});

// --- Programmation (surgery.schedule) ---
const showScheduleForm = ref(false);
const scheduleForm = useForm({ surgeon_id: '', scheduled_at: '' });
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

// --- Validation du bilan préopératoire (surgery.preoperative.validate) ---
const validatingPreoperative = ref(false);
const validatePreoperative = () => {
    validatingPreoperative.value = true;
    router.post(`${base.value}/preoperative/validate`, {}, {
        preserveScroll: true,
        onFinish: () => { validatingPreoperative.value = false; },
    });
};

// --- Équipe de bloc (surgery.update) ---
const showTeamForm = ref(false);
const teamForm = useForm({ user_id: '', function: props.teamFunctions[0]?.value ?? '' });
const submitTeam = () => teamForm.post(`${base.value}/team`, {
    preserveScroll: true,
    onSuccess: () => { showTeamForm.value = false; teamForm.reset(); },
});

// --- Anesthésie (anesthesia.create / update / validate) ---
const showAnesthesiaForm = ref(!props.surgicalRequest.anesthesia_record);
const anesthesiaCreateForm = useForm({ anesthetist_id: '', notes: '', administered_at: '' });
const submitAnesthesiaCreate = () => anesthesiaCreateForm.post(`${base.value}/anesthesia`, {
    preserveScroll: true,
    onSuccess: () => { showAnesthesiaForm.value = false; },
});

const editingAnesthesia = ref(false);
const anesthesiaUpdateForm = useForm({
    notes: props.surgicalRequest.anesthesia_record?.notes ?? '',
    administered_at: props.surgicalRequest.anesthesia_record?.administered_at ?? '',
});
const submitAnesthesiaUpdate = () => {
    anesthesiaUpdateForm.transform((data) => ({ ...data, _method: 'put' })).post(
        `${base.value}/anesthesia/${props.surgicalRequest.anesthesia_record.id}`,
        { preserveScroll: true, onSuccess: () => { editingAnesthesia.value = false; } },
    );
};
const validatingAnesthesia = ref(false);
const validateAnesthesia = () => {
    validatingAnesthesia.value = true;
    router.post(`${base.value}/anesthesia/${props.surgicalRequest.anesthesia_record.id}/validate`, {}, {
        preserveScroll: true,
        onFinish: () => { validatingAnesthesia.value = false; },
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

// --- Consommables (surgery.consumables.create) ---
const showConsumableForm = ref(false);
const consumableForm = useForm({ label: '', quantity: 1, unit: '' });
const submitConsumable = () => consumableForm.post(`${base.value}/consumables`, {
    preserveScroll: true,
    onSuccess: () => { consumableForm.reset(); },
});

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

    <div class="mx-auto w-full max-w-screen-2xl space-y-6 lg:space-y-8">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                    <Icon class="text-2xl" name="grid-alt" />
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">{{ surgicalRequest.procedure_name }}</h1>
                        <span :class="['rounded px-2 py-1 text-xs font-bold', statusVariant(surgicalRequest.status)]">{{ statusLabel(surgicalRequest.status) }}</span>
                    </div>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-400">
                        <Link v-if="surgicalRequest.episode?.patient" :href="`/patients/${surgicalRequest.episode.patient.uuid}`" class="font-medium text-primary-600 hover:underline">
                            {{ formatPatientName(surgicalRequest.episode.patient) }}
                        </Link>
                        <span v-if="surgicalRequest.episode"> · Passage {{ surgicalRequest.episode.episode_number }}</span>
                    </p>
                </div>
            </div>
            <Button :as="Link" href="/surgery" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Chirurgie</span></Button>
        </header>

        <div v-if="status" class="flex items-center gap-3 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300" role="status">
            <Icon class="text-lg" name="check-circle" /><span>{{ status }}</span>
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <!-- Demande -->
            <Card class="shadow-sm">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="file-text" /></span>Demande</h2>
                    <Button v-if="can('surgery.update') && !showEditForm" size="sm" variant="white-outline" type="button" @click="showEditForm = true"><Icon class="text-base" name="edit" /><span class="ms-1.5">Modifier</span></Button>
                </div>

                <form v-if="showEditForm" class="space-y-3" @submit.prevent="submitEdit">
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="procedure_name">Acte</FormLabel>
                        <Input id="procedure_name" v-model="editForm.procedure_name" required />
                        <FormError v-if="editForm.errors.procedure_name">{{ editForm.errors.procedure_name }}</FormError>
                    </FormGroup>
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="notes">Notes</FormLabel>
                        <textarea id="notes" v-model="editForm.notes" rows="2" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                    </FormGroup>
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="preoperative_notes">Bilan préopératoire</FormLabel>
                        <textarea id="preoperative_notes" v-model="editForm.preoperative_notes" rows="3" placeholder="ASA, à jeun, allergies, bilan biologique…" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                    </FormGroup>
                    <div class="flex justify-end gap-2">
                        <Button size="sm" variant="white-outline" type="button" @click="showEditForm = false">Annuler</Button>
                        <Button size="sm" variant="primary" type="submit" :disabled="editForm.processing">Enregistrer</Button>
                    </div>
                </form>
                <dl v-else class="space-y-2 text-sm">
                    <div><dt class="text-xs text-slate-400">Notes</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.notes ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Demandée par</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.requested_by?.name ?? '—' }}</dd></div>
                </dl>
                </CardBody>
            </Card>

            <!-- Programmation & préparation -->
            <Card class="shadow-sm">
                <CardBody>
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="calendar" /></span>Programmation</h2>

                <template v-if="surgicalRequest.status === 'PENDING' && can('surgery.schedule')">
                    <form v-if="showScheduleForm" class="space-y-3" @submit.prevent="submitSchedule">
                        <FormGroup class="!mb-0">
                            <FormLabel class="mb-1.5" for="surgeon_id">Chirurgien <span class="text-red-500">*</span></FormLabel>
                            <select id="surgeon_id" v-model="scheduleForm.surgeon_id" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required>
                                <option value="" disabled>Choisir</option>
                                <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
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
                            <Button size="sm" variant="primary" type="submit" :disabled="scheduleForm.processing">Programmer</Button>
                        </div>
                    </form>
                    <Button v-else size="sm" variant="primary" type="button" @click="showScheduleForm = true"><Icon class="text-base" name="calendar" /><span class="ms-1.5">Programmer l'intervention</span></Button>
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

            <!-- Préopératoire -->
            <Card class="shadow-sm">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="check-circle" /></span>Bilan préopératoire</h2>
                    <Button
                        v-if="surgicalRequest.status === 'SCHEDULED' && can('surgery.preoperative.validate')"
                        size="sm" variant="primary" type="button" :disabled="validatingPreoperative"
                        @click="validatePreoperative"
                    >
                        <Icon class="text-base" name="check" /><span class="ms-1.5">Valider</span>
                    </Button>
                </div>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-xs text-slate-400">Bilan</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.preoperative_notes ?? 'Non renseigné (voir « Demande » ci-dessus)' }}</dd></div>
                    <div v-if="surgicalRequest.preoperative_assessed_by"><dt class="text-xs text-slate-400">Évalué par</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.preoperative_assessed_by.name }} · {{ formatDateTime(surgicalRequest.preoperative_assessed_at) }}</dd></div>
                    <div v-if="surgicalRequest.preoperative_validated_by"><dt class="text-xs font-bold text-green-600">Validé par</dt><dd class="text-green-700 dark:text-green-300">{{ surgicalRequest.preoperative_validated_by.name }} · {{ formatDateTime(surgicalRequest.preoperative_validated_at) }}</dd></div>
                </dl>
                </CardBody>
            </Card>

            <!-- Équipe de bloc -->
            <Card class="shadow-sm">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="users" /></span>Équipe de bloc</h2>
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
                    <li v-for="member in surgicalRequest.team_members" :key="member.id" class="flex items-center justify-between rounded border border-gray-200 px-3 py-2 text-sm dark:border-gray-900">
                        <span class="text-slate-700 dark:text-white">{{ member.user?.name }}</span>
                        <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 dark:bg-slate-900 dark:text-slate-400">{{ TEAM_FUNCTION_LABELS[member.function] ?? member.function }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-400">Aucun membre assigné.</p>
                </CardBody>
            </Card>

            <!-- Anesthésie -->
            <Card class="shadow-sm">
                <CardBody>
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="shield-check" /></span>Anesthésie</h2>

                <form v-if="!surgicalRequest.anesthesia_record && can('anesthesia.create')" class="space-y-3" @submit.prevent="submitAnesthesiaCreate">
                    <select v-model="anesthesiaCreateForm.anesthetist_id" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                        <option value="">Anesthésiste (optionnel)</option>
                        <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                    </select>
                    <textarea v-model="anesthesiaCreateForm.notes" rows="2" placeholder="Type d'anesthésie, observations…" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                    <input v-model="anesthesiaCreateForm.administered_at" type="datetime-local" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" />
                    <Button size="sm" variant="primary" type="submit" :disabled="anesthesiaCreateForm.processing">Créer le dossier</Button>
                </form>

                <template v-else-if="surgicalRequest.anesthesia_record">
                    <div class="mb-3 flex items-center justify-end gap-2">
                        <Button v-if="can('anesthesia.update') && !surgicalRequest.anesthesia_record.validated_at && !editingAnesthesia" size="xs" variant="white-outline" type="button" @click="editingAnesthesia = true"><Icon name="edit" /></Button>
                        <Button v-if="can('anesthesia.validate') && !surgicalRequest.anesthesia_record.validated_at" size="sm" variant="primary" type="button" :disabled="validatingAnesthesia" @click="validateAnesthesia"><Icon class="text-base" name="check" /><span class="ms-1.5">Valider</span></Button>
                    </div>
                    <form v-if="editingAnesthesia" class="space-y-3" @submit.prevent="submitAnesthesiaUpdate">
                        <textarea v-model="anesthesiaUpdateForm.notes" rows="2" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></textarea>
                        <input v-model="anesthesiaUpdateForm.administered_at" type="datetime-local" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" />
                        <div class="flex justify-end gap-2">
                            <Button size="sm" variant="white-outline" type="button" @click="editingAnesthesia = false">Annuler</Button>
                            <Button size="sm" variant="primary" type="submit" :disabled="anesthesiaUpdateForm.processing">Enregistrer</Button>
                        </div>
                    </form>
                    <dl v-else class="space-y-2 text-sm">
                        <div><dt class="text-xs text-slate-400">Anesthésiste</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.anesthesia_record.anesthetist?.name ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Notes</dt><dd class="text-slate-600 dark:text-slate-300">{{ surgicalRequest.anesthesia_record.notes ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Administrée le</dt><dd class="text-slate-600 dark:text-slate-300">{{ formatDateTime(surgicalRequest.anesthesia_record.administered_at) ?? '—' }}</dd></div>
                        <div v-if="surgicalRequest.anesthesia_record.validated_by"><dt class="text-xs font-bold text-green-600">Validée par</dt><dd class="text-green-700 dark:text-green-300">{{ surgicalRequest.anesthesia_record.validator?.name }} · {{ formatDateTime(surgicalRequest.anesthesia_record.validated_at) }}</dd></div>
                    </dl>
                </template>
                <p v-else class="text-sm text-slate-400">Aucun dossier d'anesthésie.</p>
                </CardBody>
            </Card>

            <!-- Intervention -->
            <Card class="shadow-sm">
                <CardBody>
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="activity" /></span>Intervention</h2>

                <template v-if="!surgicalRequest.intervention">
                    <form v-if="can('surgery.intervention.create')" class="space-y-3" @submit.prevent="submitInterventionCreate">
                        <select v-model="interventionCreateForm.performed_by" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                            <option value="">Opérateur (optionnel)</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
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
            <Card class="shadow-sm">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="cards" /></span>Consommables</h2>
                    <Button v-if="can('surgery.consumables.create') && !showConsumableForm" size="sm" variant="white-outline" type="button" @click="showConsumableForm = true"><Icon class="text-base" name="plus" /><span class="ms-1.5">Ajouter</span></Button>
                </div>
                <form v-if="showConsumableForm" class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_90px_110px_auto]" @submit.prevent="submitConsumable">
                    <Input v-model="consumableForm.label" placeholder="Libellé" required />
                    <Input v-model="consumableForm.quantity" type="number" min="1" placeholder="Qté" required />
                    <Input v-model="consumableForm.unit" placeholder="Unité" />
                    <Button size="sm" variant="primary" type="submit" :disabled="consumableForm.processing">Ajouter</Button>
                </form>
                <ul v-if="surgicalRequest.consumables?.length" class="space-y-1.5 text-sm">
                    <li v-for="item in surgicalRequest.consumables" :key="item.id" class="flex items-center justify-between border-b border-gray-100 py-1.5 last:border-0 dark:border-gray-900">
                        <span class="text-slate-600 dark:text-slate-300">{{ item.label }}</span>
                        <span class="text-slate-400">{{ item.quantity }} {{ item.unit ?? '' }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-400">Aucun consommable enregistré.</p>
                </CardBody>
            </Card>

            <!-- Complications -->
            <Card class="shadow-sm">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-300"><Icon class="text-base" name="alert-circle" /></span>Complications</h2>
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
                </CardBody>
            </Card>

            <!-- Soins -->
            <Card class="shadow-sm xl:col-span-2">
                <CardBody>
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="user-check" /></span>Soins</h2>
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
                </CardBody>
            </Card>

            <!-- Compte rendu -->
            <Card class="shadow-sm xl:col-span-2">
                <CardBody>
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="file-text" /></span>Compte rendu opératoire</h2>
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
            <Card v-if="['COMPLETED', 'DISCHARGED'].includes(surgicalRequest.status)" class="shadow-sm xl:col-span-2">
                <CardBody>
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="signout" /></span>Sortie</h2>

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
    </div>
</template>
