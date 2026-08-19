<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import BlockHead from '@/Components/UI/BlockHead.vue';
import BlockTitle from '@/Components/UI/BlockTitle.vue';
import BlockText from '@/Components/UI/BlockText.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import FormError from '@/Components/UI/FormError.vue';
import InputWrap from '@/Components/UI/InputWrap.vue';
import Input from '@/Components/UI/Input.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Radio from '@/Components/UI/Radio.vue';
import RadioButton from '@/Components/UI/RadioButton.vue';
import CheckBox from '@/Components/UI/CheckBox.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({
    layout: AppLayout,
});

const props = defineProps({
    search: String,
    matches: Array,
    recentEpisodes: Array,
});

const page = usePage();
const duplicates = computed(() => page.props.flash?.duplicates ?? null);
const { can } = usePermissions();
const canUpdatePatient = computed(() => can('patients.update'));

const steps = [
    { key: 'type', label: 'Type' },
    { key: 'identity', label: 'Identité' },
    { key: 'contact', label: 'Contact' },
    { key: 'confirm', label: 'Confirmation' },
];
const stepIndex = ref(0);
const currentStep = computed(() => steps[stepIndex.value].key);

const patientType = ref(null); // 'existing' | 'new'
const selectedPatient = ref(null);
const showArrivalFlow = ref(Boolean(props.search) || Boolean(duplicates.value?.length));
const recentTab = ref('normal'); // 'normal' | 'emergency'

const normalRecentEpisodes = computed(() => props.recentEpisodes.filter((episode) => episode.priority !== 'EMERGENCY'));
const emergencyRecentEpisodes = computed(() => props.recentEpisodes.filter((episode) => episode.priority === 'EMERGENCY'));
const filteredRecentEpisodes = computed(() => recentTab.value === 'emergency'
    ? emergencyRecentEpisodes.value
    : normalRecentEpisodes.value
);

if (props.search) {
    patientType.value = 'existing';
    stepIndex.value = 1;
}

// Only matters on a fresh mount (e.g. a hard refresh while duplicates are
// flashed) — the normal duplicate round-trip uses preserveState on the
// form.post() below, so the wizard's own state survives without this.
if (duplicates.value?.length > 0) {
    stepIndex.value = 3;
    patientType.value = 'new';
}

const chooseType = (type) => {
    resetPatientForm();
    selectedPatient.value = null;
    patientType.value = type;
    stepIndex.value = 1;
};

const goBack = () => {
    if (currentStep.value === 'confirm' && patientType.value === 'existing' && !canUpdatePatient.value) {
        selectedPatient.value = null;
        stepIndex.value = 1;
        return;
    }

    if (currentStep.value === 'identity' && patientType.value === 'existing' && selectedPatient.value) {
        resetPatientForm();
        selectedPatient.value = null;
        return;
    }

    if (stepIndex.value > 0) {
        stepIndex.value -= 1;
    }
};

// --- Existing patient search ---
const query = ref(props.search ?? '');

const submitSearch = () => {
    router.get('/reception', { q: query.value }, { preserveState: true, preserveScroll: true, replace: true });
};

const pickExistingPatient = (patient) => {
    selectedPatient.value = patient;

    if (canUpdatePatient.value) {
        hydratePatientForm(patient);
        return;
    }

    stepIndex.value = 3;
};

// --- New patient form ---
const form = useForm({
    first_name: '',
    last_name: '',
    birth_date: '',
    age: '',
    sex: 'M',
    civility: null,
    identity_document_type: null,
    identity_document_number: '',
    phone: '',
    email: '',
    address: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    emergency_contact_email: '',
    emergency_contact_relationship: '',
    is_emergency: false,
    confirm_duplicate: false,
});

// Some patients don't know their exact birth date, only their age — the
// two inputs are mutually exclusive, switching modes clears the other.
const birthDateMode = ref('date'); // 'date' | 'age'

const resetPatientForm = () => {
    const isEmergency = form.is_emergency;

    form.reset();
    form.clearErrors();
    form.is_emergency = isEmergency;
    birthDateMode.value = 'date';
};

const startArrivalFlow = () => {
    resetPatientForm();
    selectedPatient.value = null;
    patientType.value = null;
    stepIndex.value = 0;
    showArrivalFlow.value = true;
};

const showRecentPassages = () => {
    resetPatientForm();
    selectedPatient.value = null;
    patientType.value = null;
    stepIndex.value = 0;
    showArrivalFlow.value = false;

    if (query.value || props.search) {
        query.value = '';
        router.get('/reception', {}, { preserveState: true, preserveScroll: true, replace: true });
    }
};

const hydratePatientForm = (patient) => {
    resetPatientForm();

    form.first_name = patient.first_name ?? '';
    form.last_name = patient.last_name ?? '';
    form.sex = patient.sex ?? 'M';
    form.civility = patient.civility ?? null;
    form.identity_document_type = patient.identity_document_type ?? null;
    form.identity_document_number = patient.identity_document_number ?? '';
    form.phone = patient.phone ?? '';
    form.email = patient.email ?? '';
    form.address = patient.address ?? '';
    form.emergency_contact_name = patient.emergency_contact_name ?? '';
    form.emergency_contact_phone = patient.emergency_contact_phone ?? '';
    form.emergency_contact_email = patient.emergency_contact_email ?? '';
    form.emergency_contact_relationship = patient.emergency_contact_relationship ?? '';

    if (patient.birth_date_is_approximate) {
        birthDateMode.value = 'age';
        form.age = patient.age ?? '';
        form.birth_date = '';
    } else {
        birthDateMode.value = 'date';
        form.birth_date = patient.birth_date ?? '';
        form.age = '';
    }
};

const setBirthDateMode = (mode) => {
    birthDateMode.value = mode;
    if (mode === 'date') {
        form.age = '';
    } else {
        form.birth_date = '';
    }
};

const civilityOptions = [
    { value: 'MR', label: 'M.', sex: 'M' },
    { value: 'MRS', label: 'Mme', sex: 'F' },
    { value: 'GIRL', label: 'Enfant fille', sex: 'F' },
    { value: 'BOY', label: 'Enfant garçon', sex: 'M' },
];

// Each civility implies a sex — choosing one sets it directly, but sex
// stays a normal editable field afterward in case it needs correcting.
const chooseCivility = (value) => {
    form.civility = value;
    form.sex = civilityOptions.find((option) => option.value === value)?.sex ?? form.sex;
};

const identityComplete = computed(() => form.last_name && form.sex && (form.birth_date || form.age));

const civilityLabel = computed(() => civilityOptions.find((option) => option.value === form.civility)?.label);

const birthSummary = computed(() => {
    if (form.birth_date) {
        return `né(e) le ${formatDate(form.birth_date)}`;
    }
    if (form.age) {
        return `${form.age} ans (âge déclaré, date de naissance approximative)`;
    }
    return null;
});

// --- Final submission (step 4) ---
const confirmArrival = () => {
    if (patientType.value === 'existing') {
        form.transform((data) => canUpdatePatient.value
            ? {
                ...data,
                patient_id: selectedPatient.value.id,
                update_patient: true,
            }
            : {
                patient_id: selectedPatient.value.id,
                is_emergency: data.is_emergency,
            }
        ).post('/reception', {
            preserveState: true,
            preserveScroll: true,
            onError: (errors) => {
                const contactFields = [
                    'emergency_contact_name',
                    'emergency_contact_phone',
                    'emergency_contact_email',
                    'emergency_contact_relationship',
                ];

                stepIndex.value = Object.keys(errors).some((field) => contactFields.includes(field)) ? 2 : 1;
            },
        });
        return;
    }

    form.transform((data) => data).post('/reception', { preserveState: true, preserveScroll: true });
};

const confirmDespiteDuplicate = () => {
    form.confirm_duplicate = true;
    form.transform((data) => data).post('/reception', { preserveState: true, preserveScroll: true });
};

const statusLabels = {
    OPEN: 'Ouvert',
    CLOSED: 'Clos',
    CANCELLED: 'Annulé',
};

const administrativeStatusLabels = {
    PENDING_ORIENTATION: 'En attente d’orientation',
    ORIENTED: 'Orienté',
    IN_CARE: 'En cours de soins',
    PENDING_SETTLEMENT: 'En attente de règlement',
    DISCHARGED: 'Sorti',
};

const statusBadgeClasses = {
    OPEN: 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',
    CLOSED: 'bg-slate-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300',
    CANCELLED: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
};

const statusBadgeClass = (status) => statusBadgeClasses[status] ?? statusBadgeClasses.CLOSED;
</script>

<template>
    <Head title="Réception" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-6 lg:space-y-8">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                    <Icon class="text-2xl" name="user-add" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">
                        Réception
                    </h1>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-400">
                        Retrouvez un patient ou créez son dossier, puis enregistrez son nouveau passage.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex w-fit items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-medium text-slate-500 dark:bg-gray-900 dark:text-slate-300">
                    <Icon class="text-base text-primary-500" name="activity" />
                    {{ recentEpisodes.length }} passage{{ recentEpisodes.length > 1 ? 's' : '' }} récent{{ recentEpisodes.length > 1 ? 's' : '' }}
                </span>
                <Button
                    class="justify-center"
                    size="rg"
                    :variant="showArrivalFlow ? 'white-outline' : 'primary'"
                    type="button"
                    @click="showArrivalFlow ? showRecentPassages() : startArrivalFlow()"
                >
                    <Icon class="text-lg/4.5" :name="showArrivalFlow ? 'arrow-left' : 'user-add'" />
                    <span class="ms-2">{{ showArrivalFlow ? 'Retour aux passages' : 'Nouvelle arrivée' }}</span>
                </Button>
            </div>
        </header>

        <Card v-if="showArrivalFlow" class="overflow-hidden shadow-sm">
            <!-- Stepper -->
            <nav aria-label="Progression de l'enregistrement" class="border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-900 dark:bg-gray-1000/40 sm:px-6">
                <ol class="flex items-center" role="list">
                    <li v-for="(step, index) in steps" :key="step.key" class="flex flex-1 items-center last:flex-none">
                        <div class="flex items-center gap-3">
                            <span
                                :aria-current="index === stepIndex ? 'step' : undefined"
                                :class="[
                                    'flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-xs font-bold transition-all duration-300',
                                    index < stepIndex ? 'bg-primary-600 text-white' : '',
                                    index === stepIndex ? 'bg-primary-600 text-white ring-4 ring-primary-100 dark:ring-primary-950' : '',
                                    index > stepIndex ? 'bg-gray-100 text-slate-400 dark:bg-gray-900' : '',
                                ]"
                            >
                                <Icon v-if="index < stepIndex" class="text-sm" name="check" />
                                <span v-else>{{ index + 1 }}</span>
                            </span>
                            <span
                                :class="[
                                    'hidden text-sm font-bold sm:inline',
                                    index <= stepIndex ? 'text-slate-700 dark:text-white' : 'text-slate-400',
                                ]"
                            >
                                {{ step.label }}
                            </span>
                        </div>
                        <div
                            v-if="index < steps.length - 1"
                            :class="[
                                'mx-4 h-0.5 flex-1 rounded transition-all duration-300',
                                index < stepIndex ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-900',
                            ]"
                        ></div>
                    </li>
                </ol>
            </nav>

            <CardBody class="!p-4 sm:!p-5 lg:!p-6">
                <div
                    v-if="form.is_emergency && currentStep !== 'type'"
                    class="mb-6 flex items-start gap-3 rounded-md border border-red-300 bg-red-50 p-4 text-red-800 dark:border-red-900 dark:bg-red-950/60 dark:text-red-200"
                    role="status"
                >
                    <Icon class="mt-0.5 shrink-0 text-xl" name="alert-circle" />
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wide">Urgence médicale</p>
                        <p class="mt-0.5 text-xs leading-5 opacity-80">Le passage sera prioritaire et immédiatement orienté vers Médecine / Soins.</p>
                    </div>
                </div>

                <Transition
                    mode="out-in"
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0 translate-x-2"
                    enter-to-class="opacity-100 translate-x-0"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <!-- Step 1: type -->
                    <div v-if="currentStep === 'type'" key="type">
                        <BlockHead class="!pb-4">
                            <BlockTitle as="h2">Qui se présente ?</BlockTitle>
                            <BlockText>Choisissez si ce patient est déjà connu de la clinique.</BlockText>
                        </BlockHead>

                        <div
                            :class="[
                                'mb-4 rounded-md border px-4 py-3 transition-colors',
                                form.is_emergency
                                    ? 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/60'
                                    : 'border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-gray-1000/40',
                            ]"
                        >
                            <div class="flex items-start gap-2.5">
                                <span :class="['mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full', form.is_emergency ? 'bg-red-100 text-red-600 dark:bg-red-900/70 dark:text-red-300' : 'bg-white text-slate-400 dark:bg-gray-900']">
                                    <Icon class="text-lg" name="alert-circle" />
                                </span>
                                <div>
                                    <CheckBox id="is_emergency" v-model="form.is_emergency" name="is_emergency" size="sm">
                                        <span :class="['font-bold', form.is_emergency ? 'text-red-700 dark:text-red-200' : 'text-slate-700 dark:text-white']">
                                            Admission en urgence
                                        </span>
                                    </CheckBox>
                                    <p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
                                        À cocher si le patient part directement vers Médecine / Soins pendant que la famille complète ce dossier normal.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <button
                                type="button"
                                class="group relative flex min-h-40 flex-col items-start gap-2.5 rounded-md border-2 border-gray-200 p-5 text-start transition-all duration-300 hover:-translate-y-0.5 hover:border-primary-400 hover:bg-primary-50/50 hover:shadow-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100 dark:border-gray-800 dark:hover:border-primary-700 dark:hover:bg-primary-950/30 dark:focus-visible:ring-primary-950"
                                @click="chooseType('existing')"
                            >
                                <Icon class="absolute end-5 top-5 text-xl text-slate-300 transition-transform duration-300 group-hover:translate-x-1 group-hover:text-primary-500" name="arrow-right" />
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950">
                                    <Icon class="text-xl" name="search" />
                                </span>
                                <span class="font-heading text-base font-bold text-slate-700 dark:text-white">Patient existant</span>
                                <span class="max-w-sm text-sm leading-5 text-slate-400">Rechercher un dossier déjà enregistré et créer un passage.</span>
                            </button>

                            <button
                                type="button"
                                class="group relative flex min-h-40 flex-col items-start gap-2.5 rounded-md border-2 border-gray-200 p-5 text-start transition-all duration-300 hover:-translate-y-0.5 hover:border-primary-400 hover:bg-primary-50/50 hover:shadow-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100 dark:border-gray-800 dark:hover:border-primary-700 dark:hover:bg-primary-950/30 dark:focus-visible:ring-primary-950"
                                @click="chooseType('new')"
                            >
                                <Icon class="absolute end-5 top-5 text-xl text-slate-300 transition-transform duration-300 group-hover:translate-x-1 group-hover:text-primary-500" name="arrow-right" />
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950">
                                    <Icon class="text-xl" name="user-add" />
                                </span>
                                <span class="font-heading text-base font-bold text-slate-700 dark:text-white">Nouveau patient</span>
                                <span class="max-w-sm text-sm leading-5 text-slate-400">Créer son dossier administratif.</span>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2a: search existing -->
                    <div v-else-if="currentStep === 'identity' && patientType === 'existing' && !selectedPatient" key="search">
                        <BlockHead>
                            <BlockTitle as="h2">Rechercher le patient</BlockTitle>
                            <BlockText>Par nom, numéro patient ou téléphone.</BlockText>
                        </BlockHead>

                        <form class="flex max-w-2xl flex-col gap-3 sm:flex-row" role="search" @submit.prevent="submitSearch">
                            <FormGroup class="!mb-0 flex-1">
                                <FormLabel class="sr-only" for="patient_search">Rechercher un patient</FormLabel>
                                <IconInput
                                    id="patient_search"
                                    v-model="query"
                                    icon="search"
                                    type="search"
                                    placeholder="Nom, numéro patient ou téléphone"
                                    autocomplete="off"
                                    autofocus
                                />
                            </FormGroup>
                            <Button class="justify-center" size="rg" variant="primary" type="submit">
                                Rechercher
                            </Button>
                        </form>

                        <div v-if="search" class="mt-5">
                            <div v-if="matches.length > 0">
                                <p class="mb-2 text-xs font-medium text-slate-400">
                                    {{ matches.length }} résultat{{ matches.length > 1 ? 's' : '' }} trouvé{{ matches.length > 1 ? 's' : '' }}
                                </p>
                                <ul class="divide-y divide-gray-200 overflow-hidden rounded-md border border-gray-200 dark:divide-gray-900 dark:border-gray-800">
                                    <li v-for="match in matches" :key="match.id" class="flex flex-col gap-3 bg-white p-4 transition-colors hover:bg-gray-50 dark:bg-gray-950 dark:hover:bg-gray-1000 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-700 dark:text-white">
                                            {{ formatPatientName(match) }}
                                            <span class="ms-2 text-xs font-normal text-slate-400">{{ match.patient_number }}</span>
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-400">
                                            Né(e) le {{ formatDate(match.birth_date) }} <span v-if="match.phone">· {{ match.phone }}</span>
                                        </p>
                                        </div>
                                        <Button class="justify-center sm:shrink-0" size="sm" type="button" variant="primary" @click="pickExistingPatient(match)">
                                            Choisir ce patient
                                        </Button>
                                    </li>
                                </ul>
                            </div>

                            <div v-else class="py-6 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900">
                                    <Icon class="text-xl" name="search" />
                                </span>
                                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun patient trouvé pour « {{ search }} ».</p>
                                <p class="mt-1 text-xs text-slate-400">Vérifiez la saisie ou créez un nouveau dossier.</p>
                                <Button class="mt-4" size="sm" type="button" variant="white-outline" @click="chooseType('new')">
                                    <Icon class="text-lg/4.5" name="user-add" />
                                    <span class="ms-2">Créer un nouveau dossier</span>
                                </Button>
                            </div>
                        </div>

                        <div class="mt-8 border-t border-gray-200 pt-6 dark:border-gray-900">
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">Retour</span>
                            </Button>
                        </div>
                    </div>

                    <!-- Step 2b: create or update the patient identity -->
                    <form
                        v-else-if="currentStep === 'identity' && (patientType === 'new' || (patientType === 'existing' && selectedPatient && canUpdatePatient))"
                        :key="patientType === 'existing' ? 'existing-identity' : 'new-identity'"
                        @submit.prevent="stepIndex = 2"
                    >
                        <BlockHead>
                            <BlockTitle as="h2">{{ patientType === 'existing' ? 'Mettre à jour le patient' : 'Identité du patient' }}</BlockTitle>
                            <BlockText>
                                <template v-if="patientType === 'existing'">
                                    Vérifiez et corrigez le dossier {{ selectedPatient.patient_number }} avant de créer son passage.
                                </template>
                                <template v-else>Ces informations forment son dossier permanent.</template>
                                <span class="text-red-500">*</span> champ obligatoire.
                            </BlockText>
                        </BlockHead>

                        <!-- Identité -->
                        <section class="border-b border-gray-200 pb-7 dark:border-gray-900">
                            <div class="mb-5 flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                    <Icon class="text-lg" name="user" />
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-700 dark:text-white">Identité administrative</h3>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-400">Nom, civilité et document d’identité du patient.</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-5 md:grid-cols-[minmax(150px,0.45fr)_minmax(0,1fr)_minmax(0,1fr)]">
                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="civility">Civilité</FormLabel>
                                    <div class="relative">
                                        <select
                                            id="civility"
                                            name="civility"
                                            autocomplete="honorific-prefix"
                                            class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm leading-4.5 text-slate-700 outline-none transition-all focus:z-10 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:ring-offset-0 dark:border-gray-800 dark:bg-gray-950 dark:text-white focus:dark:border-primary-600 focus:dark:ring-primary-950"
                                            :value="form.civility ?? ''"
                                            :aria-invalid="Boolean(form.errors.civility)"
                                            aria-describedby="civility_help"
                                            @change="chooseCivility($event.target.value || null)"
                                        >
                                            <option value="">Choisir</option>
                                            <option v-for="option in civilityOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <FormError v-if="form.errors.civility">{{ form.errors.civility }}</FormError>
                                </FormGroup>

                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="last_name">Nom <span class="text-red-500">*</span></FormLabel>
                                    <IconInput
                                        id="last_name"
                                        v-model="form.last_name"
                                        icon="user"
                                        autocomplete="off"
                                        required
                                        :aria-invalid="Boolean(form.errors.last_name)"
                                        :aria-describedby="form.errors.last_name ? 'last_name_error' : undefined"
                                    />
                                    <FormError v-if="form.errors.last_name" id="last_name_error">{{ form.errors.last_name }}</FormError>
                                </FormGroup>

                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="first_name">Prénom(s)</FormLabel>
                                    <IconInput
                                        id="first_name"
                                        v-model="form.first_name"
                                        icon="user"
                                        autocomplete="off"
                                        :aria-invalid="Boolean(form.errors.first_name)"
                                        :aria-describedby="form.errors.first_name ? 'first_name_error' : undefined"
                                    />
                                    <FormError v-if="form.errors.first_name" id="first_name_error">{{ form.errors.first_name }}</FormError>
                                </FormGroup>
                            </div>
                            <p id="civility_help" class="mt-2 text-xs text-slate-400">La civilité renseigne aussi le sexe, qui reste modifiable.</p>

                            <fieldset class="mt-5">
                                <legend class="mb-3 text-sm font-medium text-slate-700 dark:text-white">Sexe <span class="text-red-500">*</span></legend>
                                <div class="flex min-h-6 flex-wrap items-center gap-x-6 gap-y-3">
                                    <Radio id="sex-m" v-model="form.sex" name="sex" value="M">Masculin</Radio>
                                    <Radio id="sex-f" v-model="form.sex" name="sex" value="F">Féminin</Radio>
                                </div>
                                <FormError v-if="form.errors.sex">{{ form.errors.sex }}</FormError>
                            </fieldset>

                            <div class="mt-6 grid grid-cols-1 gap-7 border-t border-gray-200 pt-6 dark:border-gray-900 lg:grid-cols-2 lg:gap-8">
                                <fieldset class="min-w-0">
                                    <legend class="sr-only">Pièce d’identité facultative</legend>
                                    <div class="mb-4 flex items-start gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                            <Icon class="text-lg" name="cards" />
                                        </span>
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">
                                                Pièce d’identité <span class="text-xs font-normal text-slate-400">(facultatif)</span>
                                            </h3>
                                            <p class="mt-0.5 text-xs leading-5 text-slate-400">Document présenté lors de l’admission.</p>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <RadioButton
                                            id="doc-cin"
                                            nocontrol
                                            name="identity_document_type"
                                            v-model="form.identity_document_type"
                                            value="CIN"
                                        >
                                            CIN
                                        </RadioButton>
                                        <RadioButton
                                            id="doc-passport"
                                            nocontrol
                                            name="identity_document_type"
                                            v-model="form.identity_document_type"
                                            value="PASSPORT"
                                        >
                                            Passeport
                                        </RadioButton>
                                        <button
                                            v-if="form.identity_document_type"
                                            type="button"
                                            class="text-xs text-slate-400 underline hover:text-slate-600 dark:hover:text-slate-200"
                                            @click="form.identity_document_type = null; form.identity_document_number = ''"
                                        >
                                            Effacer
                                        </button>
                                        <div class="min-w-[180px] flex-1">
                                            <IconInput
                                                id="identity_document_number"
                                                v-model="form.identity_document_number"
                                                icon="cards"
                                                :disabled="!form.identity_document_type"
                                                placeholder="Numéro du document"
                                                autocomplete="off"
                                                :aria-invalid="Boolean(form.errors.identity_document_number)"
                                                :aria-describedby="form.errors.identity_document_number ? 'identity_document_number_error' : undefined"
                                            />
                                        </div>
                                    </div>
                                    <FormError v-if="form.errors.identity_document_type">{{ form.errors.identity_document_type }}</FormError>
                                    <FormError v-if="form.errors.identity_document_number" id="identity_document_number_error">{{ form.errors.identity_document_number }}</FormError>
                                </fieldset>

                                <fieldset class="min-w-0">
                                    <legend class="sr-only">Naissance</legend>
                                    <div class="mb-4 flex items-start gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                            <Icon class="text-lg" name="calendar" />
                                        </span>
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">Naissance <span class="text-red-500">*</span></h3>
                                            <p class="mt-0.5 text-xs leading-5 text-slate-400">Saisissez la date exacte ou l’âge déclaré si elle est inconnue.</p>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-start gap-2">
                                        <RadioButton
                                            id="birth-mode-date"
                                            nocontrol
                                            name="birth_mode"
                                            :model-value="birthDateMode"
                                            value="date"
                                            @update:model-value="setBirthDateMode"
                                        >
                                            Date de naissance
                                        </RadioButton>
                                        <RadioButton
                                            id="birth-mode-age"
                                            nocontrol
                                            name="birth_mode"
                                            :model-value="birthDateMode"
                                            value="age"
                                            @update:model-value="setBirthDateMode"
                                        >
                                            Âge
                                        </RadioButton>

                                        <FormGroup v-if="birthDateMode === 'date'" class="!mb-0 min-w-[180px] flex-1">
                                            <FormLabel class="sr-only" for="birth_date">Date de naissance</FormLabel>
                                            <InputWrap>
                                                <Input
                                                    id="birth_date"
                                                    v-model="form.birth_date"
                                                    type="date"
                                                    required
                                                    :aria-invalid="Boolean(form.errors.birth_date)"
                                                    :aria-describedby="form.errors.birth_date ? 'birth_date_error' : undefined"
                                                />
                                            </InputWrap>
                                            <FormError v-if="form.errors.birth_date" id="birth_date_error">{{ form.errors.birth_date }}</FormError>
                                        </FormGroup>

                                        <FormGroup v-else class="!mb-0 min-w-[180px] flex-1">
                                            <FormLabel class="sr-only" for="age">Âge en années</FormLabel>
                                            <InputWrap>
                                                <Input
                                                    id="age"
                                                    v-model="form.age"
                                                    type="number"
                                                    min="0"
                                                    max="130"
                                                    placeholder="Âge en années"
                                                    required
                                                    :aria-invalid="Boolean(form.errors.age)"
                                                    :aria-describedby="form.errors.age ? 'age_error' : 'age_help'"
                                                />
                                            </InputWrap>
                                            <p id="age_help" class="mt-1 text-xs text-slate-400">
                                                Une date de naissance approximative sera enregistrée.
                                            </p>
                                            <FormError v-if="form.errors.age" id="age_error">{{ form.errors.age }}</FormError>
                                        </FormGroup>
                                    </div>
                                </fieldset>
                            </div>
                        </section>

                        <!-- Coordonnées -->
                        <section class="pt-7">
                            <div class="mb-5 flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                    <Icon class="text-lg" name="call" />
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-700 dark:text-white">Coordonnées</h3>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-400">Moyens de contact et adresse du patient.</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="phone">Téléphone</FormLabel>
                                    <IconInput
                                        id="phone"
                                        v-model="form.phone"
                                        icon="call"
                                        type="tel"
                                        inputmode="tel"
                                        autocomplete="off"
                                        :aria-invalid="Boolean(form.errors.phone)"
                                        :aria-describedby="form.errors.phone ? 'phone_error' : undefined"
                                    />
                                    <FormError v-if="form.errors.phone" id="phone_error">{{ form.errors.phone }}</FormError>
                                </FormGroup>

                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="email">Email</FormLabel>
                                    <IconInput
                                        id="email"
                                        v-model="form.email"
                                        icon="mail"
                                        type="email"
                                        inputmode="email"
                                        autocomplete="off"
                                        :aria-invalid="Boolean(form.errors.email)"
                                        :aria-describedby="form.errors.email ? 'email_error' : undefined"
                                    />
                                    <FormError v-if="form.errors.email" id="email_error">{{ form.errors.email }}</FormError>
                                </FormGroup>

                                <FormGroup class="!mb-0 sm:col-span-2">
                                    <FormLabel class="mb-1.5" for="address">Adresse</FormLabel>
                                    <textarea
                                        id="address"
                                        v-model="form.address"
                                        rows="2"
                                        :aria-invalid="Boolean(form.errors.address)"
                                        :aria-describedby="form.errors.address ? 'address_error' : undefined"
                                        class="block min-h-20 w-full resize-y box-border rounded border border-gray-200 bg-white px-4 py-2 text-sm leading-5 text-slate-700 outline-none transition-all placeholder-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:ring-offset-0 aria-[invalid=true]:border-red-400 aria-[invalid=true]:focus:border-red-500 aria-[invalid=true]:focus:ring-red-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:border-primary-600 dark:focus:ring-primary-950 aria-[invalid=true]:focus:dark:ring-red-950"
                                    ></textarea>
                                    <FormError v-if="form.errors.address" id="address_error">{{ form.errors.address }}</FormError>
                                </FormGroup>
                            </div>
                        </section>

                        <div class="mt-7 flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 dark:border-gray-900 sm:flex-row sm:justify-between">
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">{{ patientType === 'existing' ? 'Changer de patient' : 'Retour' }}</span>
                            </Button>
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="primary" type="submit" :disabled="!identityComplete">
                                <span class="me-2">Continuer</span>
                                <Icon class="text-lg/4.5" name="arrow-right" />
                            </Button>
                        </div>
                    </form>

                    <!-- Step 3: emergency contact -->
                    <form
                        v-else-if="currentStep === 'contact' && (patientType === 'new' || (patientType === 'existing' && canUpdatePatient))"
                        key="contact"
                        @submit.prevent="stepIndex = 3"
                    >
                        <BlockHead>
                            <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                <Icon class="text-xl" name="contact" />
                            </div>
                            <BlockTitle as="h2">Personne à contacter</BlockTitle>
                            <BlockText>
                                {{ patientType === 'existing' ? 'Vérifiez les coordonnées du proche à joindre si nécessaire.' : 'Ajoutez les coordonnées d’un proche à joindre si nécessaire.' }}
                                Cette étape est facultative.
                            </BlockText>
                        </BlockHead>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_name">Nom</FormLabel>
                                <IconInput
                                    id="emergency_contact_name"
                                    v-model="form.emergency_contact_name"
                                    icon="user"
                                    autocomplete="off"
                                    :aria-invalid="Boolean(form.errors.emergency_contact_name)"
                                    :aria-describedby="form.errors.emergency_contact_name ? 'emergency_contact_name_error' : undefined"
                                />
                                <FormError v-if="form.errors.emergency_contact_name" id="emergency_contact_name_error">{{ form.errors.emergency_contact_name }}</FormError>
                            </FormGroup>

                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_relationship">Lien de parenté</FormLabel>
                                <InputWrap>
                                    <Input
                                        id="emergency_contact_relationship"
                                        v-model="form.emergency_contact_relationship"
                                        autocomplete="off"
                                        :aria-invalid="Boolean(form.errors.emergency_contact_relationship)"
                                        :aria-describedby="form.errors.emergency_contact_relationship ? 'emergency_contact_relationship_error' : undefined"
                                    />
                                </InputWrap>
                                <FormError v-if="form.errors.emergency_contact_relationship" id="emergency_contact_relationship_error">{{ form.errors.emergency_contact_relationship }}</FormError>
                            </FormGroup>

                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_phone">Téléphone</FormLabel>
                                <IconInput
                                    id="emergency_contact_phone"
                                    v-model="form.emergency_contact_phone"
                                    icon="call"
                                    type="tel"
                                    inputmode="tel"
                                    autocomplete="off"
                                    :aria-invalid="Boolean(form.errors.emergency_contact_phone)"
                                    :aria-describedby="form.errors.emergency_contact_phone ? 'emergency_contact_phone_error' : undefined"
                                />
                                <FormError v-if="form.errors.emergency_contact_phone" id="emergency_contact_phone_error">{{ form.errors.emergency_contact_phone }}</FormError>
                            </FormGroup>

                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_email">Email</FormLabel>
                                <IconInput
                                    id="emergency_contact_email"
                                    v-model="form.emergency_contact_email"
                                    icon="mail"
                                    type="email"
                                    inputmode="email"
                                    autocomplete="off"
                                    :aria-invalid="Boolean(form.errors.emergency_contact_email)"
                                    :aria-describedby="form.errors.emergency_contact_email ? 'emergency_contact_email_error' : undefined"
                                />
                                <FormError v-if="form.errors.emergency_contact_email" id="emergency_contact_email_error">{{ form.errors.emergency_contact_email }}</FormError>
                            </FormGroup>
                        </div>

                        <div class="mt-8 flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 dark:border-gray-900 sm:flex-row sm:justify-between">
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">Retour</span>
                            </Button>
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="primary" type="submit">
                                <span class="me-2">Continuer</span>
                                <Icon class="text-lg/4.5" name="arrow-right" />
                            </Button>
                        </div>
                    </form>

                    <!-- Step 4: confirm -->
                    <div v-else-if="currentStep === 'confirm'" key="confirm">
                        <BlockHead>
                            <BlockTitle as="h2">Confirmer l'arrivée</BlockTitle>
                            <BlockText>Un passage sera créé pour ce patient.</BlockText>
                        </BlockHead>

                        <Card
                            variant="light"
                            :class="[
                                'overflow-hidden bg-gray-50 dark:bg-gray-1000/60',
                                form.is_emergency ? '!border-red-300 dark:!border-red-900' : '!border-gray-200 dark:!border-gray-900',
                            ]"
                        >
                            <CardBody class="!p-5 sm:!p-6">
                                <template v-if="patientType === 'existing' && selectedPatient">
                                    <div class="flex items-start gap-4">
                                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                            <Icon class="text-xl" name="user-check" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                <p class="font-heading text-base font-bold text-slate-700 dark:text-white">
                                                    {{ formatPatientName(canUpdatePatient ? form : selectedPatient) }}
                                                </p>
                                                <div class="flex flex-wrap gap-2">
                                                    <span v-if="form.is_emergency" class="inline-flex w-fit items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-bold uppercase text-red-700 dark:bg-red-950 dark:text-red-300">
                                                        <Icon name="alert-circle" /> Urgence
                                                    </span>
                                                    <span class="inline-flex w-fit rounded bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                                                        Patient existant
                                                    </span>
                                                </div>
                                            </div>
                                            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                                <div>
                                                    <dt class="text-xs text-slate-400">N° patient</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ selectedPatient.patient_number }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Naissance</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ canUpdatePatient ? birthSummary : formatDate(selectedPatient.birth_date) }}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Sexe</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ (canUpdatePatient ? form.sex : selectedPatient.sex) === 'M' ? 'Masculin' : 'Féminin' }}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Téléphone</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ (canUpdatePatient ? form.phone : selectedPatient.phone) || 'Non renseigné' }}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Email</dt>
                                                    <dd class="mt-0.5 break-all font-medium text-slate-600 dark:text-slate-200">
                                                        {{ (canUpdatePatient ? form.email : selectedPatient.email) || 'Non renseigné' }}
                                                    </dd>
                                                </div>
                                                <div v-if="canUpdatePatient ? form.identity_document_type : selectedPatient.identity_document_type">
                                                    <dt class="text-xs text-slate-400">Pièce d’identité</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ (canUpdatePatient ? form.identity_document_type : selectedPatient.identity_document_type) === 'CIN' ? 'CIN' : 'Passeport' }}
                                                        {{ canUpdatePatient ? form.identity_document_number : selectedPatient.identity_document_number }}
                                                    </dd>
                                                </div>
                                                <div
                                                    v-if="canUpdatePatient && (form.emergency_contact_name || form.emergency_contact_phone)"
                                                    class="sm:col-span-2"
                                                >
                                                    <dt class="text-xs text-slate-400">Personne à contacter</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ form.emergency_contact_name || 'Nom non renseigné' }}
                                                        <span v-if="form.emergency_contact_relationship">· {{ form.emergency_contact_relationship }}</span>
                                                        <span v-if="form.emergency_contact_phone">· {{ form.emergency_contact_phone }}</span>
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>
                                    </div>
                                </template>

                                <template v-else-if="patientType === 'new'">
                                    <div class="flex items-start gap-4">
                                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-950 dark:text-green-300">
                                            <Icon class="text-xl" name="user-add" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                <p class="font-heading text-base font-bold text-slate-700 dark:text-white">
                                                    <span v-if="civilityLabel">{{ civilityLabel }}</span>
                                                    {{ formatPatientName(form) }}
                                                </p>
                                                <div class="flex flex-wrap gap-2">
                                                    <span v-if="form.is_emergency" class="inline-flex w-fit items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-bold uppercase text-red-700 dark:bg-red-950 dark:text-red-300">
                                                        <Icon name="alert-circle" /> Urgence
                                                    </span>
                                                    <span class="inline-flex w-fit rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                                                        Nouveau dossier
                                                    </span>
                                                </div>
                                            </div>
                                            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                                <div>
                                                    <dt class="text-xs text-slate-400">Naissance</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ birthSummary }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Sexe</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ form.sex === 'M' ? 'Masculin' : 'Féminin' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Téléphone</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ form.phone || 'Non renseigné' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Email</dt>
                                                    <dd class="mt-0.5 break-all font-medium text-slate-600 dark:text-slate-200">{{ form.email || 'Non renseigné' }}</dd>
                                                </div>
                                                <div v-if="form.identity_document_type">
                                                    <dt class="text-xs text-slate-400">Pièce d’identité</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ form.identity_document_type === 'CIN' ? 'CIN' : 'Passeport' }} {{ form.identity_document_number }}
                                                    </dd>
                                                </div>
                                                <div v-if="form.emergency_contact_name || form.emergency_contact_phone" class="sm:col-span-2">
                                                    <dt class="text-xs text-slate-400">Personne à contacter</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ form.emergency_contact_name || 'Nom non renseigné' }}
                                                        <span v-if="form.emergency_contact_relationship">· {{ form.emergency_contact_relationship }}</span>
                                                        <span v-if="form.emergency_contact_phone">· {{ form.emergency_contact_phone }}</span>
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>
                                    </div>
                                </template>
                            </CardBody>
                        </Card>

                        <Card
                            v-if="duplicates && duplicates.length > 0"
                            class="mt-5 !border-yellow-300 bg-yellow-50 dark:!border-yellow-900 dark:bg-yellow-950"
                        >
                            <CardBody class="!p-5">
                                <div class="flex items-start gap-3">
                                    <Icon class="mt-0.5 text-xl text-yellow-600" name="alert-circle" />
                                    <div class="flex-grow">
                                        <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                            Un ou plusieurs patients correspondent déjà à ce nom et cette date de naissance :
                                        </p>
                                        <ul class="mt-2 space-y-1 text-sm text-yellow-700 dark:text-yellow-300">
                                            <li v-for="match in duplicates" :key="match.id">
                                                {{ match.patient_number }} — {{ formatPatientName(match) }} ({{ formatDate(match.birth_date) }})
                                            </li>
                                        </ul>
                                        <Button class="mt-4" size="sm" type="button" variant="white-outline" :disabled="form.processing" @click="confirmDespiteDuplicate">
                                            Créer quand même, c'est un patient différent
                                        </Button>
                                    </div>
                                </div>
                            </CardBody>
                        </Card>

                        <div class="mt-8 flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 dark:border-gray-900 sm:flex-row sm:justify-between">
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">{{ patientType === 'existing' && !canUpdatePatient ? 'Changer de patient' : 'Modifier' }}</span>
                            </Button>
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="primary" type="button" :disabled="form.processing" @click="confirmArrival">
                                <Icon class="text-lg/4.5" name="check" />
                                <span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Confirmer l’arrivée' }}</span>
                            </Button>
                        </div>
                    </div>
                </Transition>
            </CardBody>
        </Card>

        <!-- Recent passages: "identifier les patients présents" / "consulter
             le statut du parcours patient" (CDC §5.2.1) -->
        <Card v-if="!showArrivalFlow" class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300">
                        <Icon class="text-lg" name="clock" />
                    </span>
                    <div>
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Passages récents</h2>
                        <p class="mt-0.5 text-xs text-slate-400">Dernières arrivées enregistrées à la réception.</p>
                    </div>
                </div>

                <div class="inline-flex w-full rounded-md bg-gray-100 p-1 dark:bg-gray-900 sm:w-auto" role="tablist" aria-label="Type de passages récents">
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="recentTab === 'normal'"
                        :class="[
                            'flex flex-1 items-center justify-center gap-2 rounded px-3 py-2 text-xs font-bold transition-all sm:flex-none',
                            recentTab === 'normal'
                                ? 'bg-white text-primary-700 shadow-sm dark:bg-gray-950 dark:text-primary-300'
                                : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-white',
                        ]"
                        @click="recentTab = 'normal'"
                    >
                        <Icon class="text-base" name="user-check" />
                        Normal
                        <span class="rounded-full bg-primary-100 px-1.5 py-0.5 text-[10px] text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                            {{ normalRecentEpisodes.length }}
                        </span>
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="recentTab === 'emergency'"
                        :class="[
                            'flex flex-1 items-center justify-center gap-2 rounded px-3 py-2 text-xs font-bold transition-all sm:flex-none',
                            recentTab === 'emergency'
                                ? 'bg-white text-red-700 shadow-sm dark:bg-gray-950 dark:text-red-300'
                                : 'text-slate-500 hover:text-red-600 dark:text-slate-400 dark:hover:text-red-300',
                        ]"
                        @click="recentTab = 'emergency'"
                    >
                        <Icon class="text-base" name="alert-circle" />
                        Urgence
                        <span class="rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] text-red-700 dark:bg-red-950 dark:text-red-300">
                            {{ emergencyRecentEpisodes.length }}
                        </span>
                    </button>
                </div>
            </div>

            <div v-if="filteredRecentEpisodes.length === 0" class="px-5 py-12 text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900">
                    <Icon class="text-2xl" :name="recentTab === 'emergency' ? 'alert-circle' : 'activity'" />
                </span>
                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">
                    {{ recentTab === 'emergency' ? 'Aucun passage en urgence récent' : 'Aucun passage normal récent' }}
                </p>
                <p class="mt-1 text-xs text-slate-400">Le prochain passage de cette catégorie apparaîtra ici.</p>
            </div>

            <template v-else>
                <div class="divide-y divide-gray-200 dark:divide-gray-900 md:hidden">
                    <article
                        v-for="episode in filteredRecentEpisodes"
                        :key="episode.id"
                        :class="['p-5', episode.priority === 'EMERGENCY' ? 'bg-red-50/60 dark:bg-red-950/20' : '']"
                    >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <Avatar
                                rounded
                                size="sm"
                                variant="primary-pale"
                                :text="formatPatientInitials(episode.patient)"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <Link :href="`/patients/${episode.patient.uuid}`" class="block truncate text-sm font-bold text-primary-600 hover:text-primary-700">
                                    {{ formatPatientName(episode.patient) }}
                                </Link>
                                <p class="mt-0.5 text-xs text-slate-400">{{ episode.patient.patient_number }} · {{ episode.episode_number }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1.5">
                            <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-bold uppercase text-red-700 dark:bg-red-950 dark:text-red-300">
                                <Icon name="alert-circle" /> Urgence
                            </span>
                            <span :class="['rounded px-2 py-0.5 text-xs font-medium', statusBadgeClass(episode.status)]">
                                {{ statusLabels[episode.status] ?? episode.status }}
                            </span>
                        </div>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <dt class="text-slate-400">Date / heure</dt>
                            <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ formatDateTime(episode.started_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">Enregistré</dt>
                            <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ formatRelativeTime(episode.started_at) }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-slate-400">Parcours administratif</dt>
                            <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                {{ administrativeStatusLabels[episode.administrative_status] ?? episode.administrative_status }}
                            </dd>
                        </div>
                    </dl>
                    </article>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full border-collapse">
                    <caption class="sr-only">Liste des passages récents</caption>
                    <thead>
                        <tr class="bg-gray-50/70 dark:bg-gray-1000/40">
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Patient</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">N° passage</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Date / heure</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Il y a</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="episode in filteredRecentEpisodes"
                            :key="episode.id"
                            :class="['transition-colors hover:bg-gray-50 dark:hover:bg-gray-1000', episode.priority === 'EMERGENCY' ? 'bg-red-50/60 dark:bg-red-950/20' : '']"
                        >
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <div class="flex items-center gap-3">
                                    <Avatar
                                        rounded
                                        size="sm"
                                        variant="primary-pale"
                                        :text="formatPatientInitials(episode.patient)"
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0">
                                        <Link :href="`/patients/${episode.patient.uuid}`" class="block truncate text-sm font-medium text-primary-600 hover:text-primary-700">
                                            {{ formatPatientName(episode.patient) }}
                                        </Link>
                                        <span class="mt-0.5 block text-xs text-slate-400">{{ episode.patient.patient_number }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-600 dark:border-gray-900 dark:text-slate-300">
                                {{ episode.episode_number }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">
                                {{ formatDateTime(episode.started_at) }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-400 dark:border-gray-900">
                                {{ formatRelativeTime(episode.started_at) }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <div class="flex flex-col items-start gap-1.5">
                                    <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-bold uppercase text-red-700 dark:bg-red-950 dark:text-red-300">
                                        <Icon name="alert-circle" /> Urgence
                                    </span>
                                    <span :class="['rounded px-2 py-0.5 text-xs font-medium', statusBadgeClass(episode.status)]">
                                        {{ statusLabels[episode.status] ?? episode.status }}
                                    </span>
                                    <span class="text-xs text-slate-400">
                                        {{ administrativeStatusLabels[episode.administrative_status] ?? episode.administrative_status }}
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    </table>
                </div>
            </template>
        </Card>
    </div>
</template>
