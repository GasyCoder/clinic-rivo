<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import FormError from '@/Components/UI/FormError.vue';
import InputWrap from '@/Components/UI/InputWrap.vue';
import Input from '@/Components/UI/Input.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDate, formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

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

const steps = [
    { key: 'type', label: 'Type' },
    { key: 'identity', label: 'Identité' },
    { key: 'confirm', label: 'Confirmation' },
];
const stepIndex = ref(0);
const currentStep = computed(() => steps[stepIndex.value].key);

const patientType = ref(null); // 'existing' | 'new'
const selectedPatient = ref(null);

// Only matters on a fresh mount (e.g. a hard refresh while duplicates are
// flashed) — the normal duplicate round-trip uses preserveState on the
// form.post() below, so the wizard's own state survives without this.
if (duplicates.value?.length > 0) {
    stepIndex.value = 2;
    patientType.value ??= 'new';
}

const chooseType = (type) => {
    patientType.value = type;
    stepIndex.value = 1;
};

const goBack = () => {
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
    stepIndex.value = 2;
};

// --- New patient form ---
const form = useForm({
    first_name: '',
    last_name: '',
    birth_date: '',
    age: '',
    sex: 'M',
    civility: null,
    phone: '',
    email: '',
    address: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    emergency_contact_email: '',
    emergency_contact_relationship: '',
    confirm_duplicate: false,
});

// Some patients don't know their exact birth date, only their age — the
// two inputs are mutually exclusive, switching modes clears the other.
const birthDateMode = ref('date'); // 'date' | 'age'

const setBirthDateMode = (mode) => {
    birthDateMode.value = mode;
    if (mode === 'date') {
        form.age = '';
    } else {
        form.birth_date = '';
    }
};

const civilityOptions = [
    { value: 'MR', label: 'M.' },
    { value: 'MRS', label: 'Mme' },
    { value: 'GIRL', label: 'Enfant fille' },
    { value: 'BOY', label: 'Enfant garçon' },
];

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

// --- Final submission (step 3) ---
const submitting = ref(false);

const confirmArrival = () => {
    if (patientType.value === 'existing') {
        router.post('/reception', { patient_id: selectedPatient.value.id }, { preserveScroll: true });
        return;
    }

    form.post('/reception', { preserveState: true, preserveScroll: true });
};

const confirmDespiteDuplicate = () => {
    form.confirm_duplicate = true;
    form.post('/reception', { preserveState: true, preserveScroll: true });
};

const restart = () => {
    stepIndex.value = 0;
    patientType.value = null;
    selectedPatient.value = null;
    form.reset();
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
</script>

<template>
    <Head title="Réception" />

    <div class="space-y-8">
        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">
                Réception
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Un passage est créé dans tous les cas — patient déjà connu ou nouveau dossier.
            </p>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <!-- Stepper -->
            <div class="border-b border-gray-200 bg-gray-50/60 px-6 py-5 dark:border-gray-900 dark:bg-gray-1000/40">
                <ol class="flex items-center">
                    <li v-for="(step, index) in steps" :key="step.key" class="flex flex-1 items-center last:flex-none">
                        <div class="flex items-center gap-3">
                            <span
                                :class="[
                                    'flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-sm font-bold transition-all duration-300',
                                    index < stepIndex ? 'bg-primary-600 text-white' : '',
                                    index === stepIndex ? 'bg-primary-600 text-white ring-4 ring-primary-100 dark:ring-primary-950' : '',
                                    index > stepIndex ? 'bg-gray-100 text-slate-400 dark:bg-gray-900' : '',
                                ]"
                            >
                                <Icon v-if="index < stepIndex" class="text-base" name="check" />
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
            </div>

            <div class="p-6 sm:p-8">
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
                        <h2 class="mb-1 font-heading text-lg font-bold text-slate-700 dark:text-white">
                            Qui se présente ?
                        </h2>
                        <p class="mb-6 text-sm text-slate-500">
                            Choisissez si ce patient est déjà connu de la clinique.
                        </p>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <button
                                type="button"
                                class="group flex flex-col items-start gap-3 rounded-lg border-2 border-gray-200 p-6 text-start transition-all duration-300 hover:border-primary-400 hover:bg-primary-50/50 dark:border-gray-800 dark:hover:border-primary-700 dark:hover:bg-primary-950/30"
                                @click="chooseType('existing')"
                            >
                                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950">
                                    <Icon class="text-2xl" name="search" />
                                </span>
                                <span class="font-heading text-base font-bold text-slate-700 dark:text-white">Patient existant</span>
                                <span class="text-sm text-slate-500">Rechercher un patient déjà enregistré et créer son passage.</span>
                            </button>

                            <button
                                type="button"
                                class="group flex flex-col items-start gap-3 rounded-lg border-2 border-gray-200 p-6 text-start transition-all duration-300 hover:border-primary-400 hover:bg-primary-50/50 dark:border-gray-800 dark:hover:border-primary-700 dark:hover:bg-primary-950/30"
                                @click="chooseType('new')"
                            >
                                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950">
                                    <Icon class="text-2xl" name="user-add" />
                                </span>
                                <span class="font-heading text-base font-bold text-slate-700 dark:text-white">Nouveau patient</span>
                                <span class="text-sm text-slate-500">Créer son dossier et son premier passage en une fois.</span>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2a: search existing -->
                    <div v-else-if="currentStep === 'identity' && patientType === 'existing'" key="search">
                        <h2 class="mb-1 font-heading text-lg font-bold text-slate-700 dark:text-white">
                            Rechercher le patient
                        </h2>
                        <p class="mb-6 text-sm text-slate-500">
                            Par nom, numéro patient ou téléphone.
                        </p>

                        <form class="relative max-w-md" @submit.prevent="submitSearch">
                            <Input v-model="query" icon="start" placeholder="Nom, numéro patient ou téléphone" autofocus />
                            <button type="submit" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400">
                                <Icon class="text-lg/4.5" name="search" />
                            </button>
                        </form>

                        <div v-if="search" class="mt-5">
                            <ul v-if="matches.length > 0" class="divide-y divide-gray-200 dark:divide-gray-900">
                                <li v-for="match in matches" :key="match.id" class="flex items-center justify-between gap-4 py-3">
                                    <div>
                                        <p class="text-sm font-medium text-slate-700 dark:text-white">
                                            {{ formatPatientName(match) }}
                                            <span class="ms-2 text-xs font-normal text-slate-400">{{ match.patient_number }}</span>
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-400">
                                            Né(e) le {{ formatDate(match.birth_date) }} <span v-if="match.phone">· {{ match.phone }}</span>
                                        </p>
                                    </div>
                                    <Button size="sm" variant="primary" @click="pickExistingPatient(match)">
                                        Choisir
                                    </Button>
                                </li>
                            </ul>

                            <div v-else class="py-6 text-center">
                                <p class="text-sm text-slate-400">Aucun patient trouvé pour « {{ search }} ».</p>
                                <Button class="mt-3" size="sm" variant="white-outline" @click="chooseType('new')">
                                    <Icon class="text-lg/4.5" name="user-add" />
                                    <span class="ms-2">Créer un nouveau dossier</span>
                                </Button>
                            </div>
                        </div>

                        <Button class="mt-8" size="rg" variant="white-outline" type="button" @click="goBack">
                            <Icon class="text-lg/4.5" name="arrow-left" />
                            <span class="ms-2">Retour</span>
                        </Button>
                    </div>

                    <!-- Step 2b: new patient identity -->
                    <div v-else-if="currentStep === 'identity' && patientType === 'new'" key="new-identity" class="space-y-8">
                        <div>
                            <h2 class="mb-1 font-heading text-lg font-bold text-slate-700 dark:text-white">
                                Identité du patient
                            </h2>
                            <p class="text-sm text-slate-500">
                                Ces informations forment son dossier permanent.
                                <span class="text-red-500">*</span> champ obligatoire.
                            </p>
                        </div>

                        <!-- Civilité -->
                        <section>
                            <FormLabel class="mb-3 block">Civilité</FormLabel>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="option in civilityOptions"
                                    :key="option.value"
                                    type="button"
                                    :class="[
                                        'rounded-full border px-4 py-1.5 text-sm font-medium transition-all duration-300',
                                        form.civility === option.value
                                            ? 'border-primary-600 bg-primary-600 text-white'
                                            : 'border-gray-200 text-slate-600 hover:border-primary-300 dark:border-gray-800 dark:text-slate-300',
                                    ]"
                                    @click="form.civility = form.civility === option.value ? null : option.value"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </section>

                        <!-- Identité -->
                        <section class="rounded-lg border border-gray-100 bg-gray-50/50 p-5 dark:border-gray-900 dark:bg-gray-1000/30">
                            <h3 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500">
                                <Icon class="text-base" name="user" />
                                Identité
                            </h3>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <FormGroup>
                                    <FormLabel html-for="first_name">Prénom</FormLabel>
                                    <IconInput id="first_name" v-model="form.first_name" icon="user" autocomplete="off" />
                                    <FormError v-if="form.errors.first_name">{{ form.errors.first_name }}</FormError>
                                </FormGroup>

                                <FormGroup>
                                    <FormLabel html-for="last_name">Nom <span class="text-red-500">*</span></FormLabel>
                                    <IconInput id="last_name" v-model="form.last_name" icon="user" autocomplete="off" />
                                    <FormError v-if="form.errors.last_name">{{ form.errors.last_name }}</FormError>
                                </FormGroup>
                            </div>

                            <div class="mt-5">
                                <FormLabel class="mb-2 block">Sexe <span class="text-red-500">*</span></FormLabel>
                                <div class="flex h-9 items-center gap-6">
                                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                        <input v-model="form.sex" type="radio" value="M" class="accent-primary-600" />
                                        Masculin
                                    </label>
                                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                        <input v-model="form.sex" type="radio" value="F" class="accent-primary-600" />
                                        Féminin
                                    </label>
                                </div>
                                <FormError v-if="form.errors.sex">{{ form.errors.sex }}</FormError>
                            </div>
                        </section>

                        <!-- Naissance -->
                        <section class="rounded-lg border border-gray-100 bg-gray-50/50 p-5 dark:border-gray-900 dark:bg-gray-1000/30">
                            <h3 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500">
                                <Icon class="text-base" name="calendar-check" />
                                Naissance <span class="text-red-500">*</span>
                            </h3>

                            <div class="mb-4 inline-flex rounded-md border border-gray-200 p-1 dark:border-gray-800">
                                <button
                                    type="button"
                                    :class="[
                                        'rounded px-3 py-1.5 text-xs font-bold transition-all duration-300',
                                        birthDateMode === 'date' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-800' : 'text-slate-500',
                                    ]"
                                    @click="setBirthDateMode('date')"
                                >
                                    Date de naissance connue
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        'rounded px-3 py-1.5 text-xs font-bold transition-all duration-300',
                                        birthDateMode === 'age' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-800' : 'text-slate-500',
                                    ]"
                                    @click="setBirthDateMode('age')"
                                >
                                    Âge seulement
                                </button>
                            </div>

                            <FormGroup v-if="birthDateMode === 'date'" class="max-w-xs">
                                <InputWrap>
                                    <Input id="birth_date" v-model="form.birth_date" type="date" />
                                </InputWrap>
                                <FormError v-if="form.errors.birth_date">{{ form.errors.birth_date }}</FormError>
                            </FormGroup>

                            <FormGroup v-else class="max-w-xs">
                                <InputWrap>
                                    <Input id="age" v-model="form.age" type="number" min="0" max="130" placeholder="Âge en années" />
                                </InputWrap>
                                <p class="mt-1 text-xs text-slate-400">
                                    Une date de naissance approximative sera enregistrée.
                                </p>
                                <FormError v-if="form.errors.age">{{ form.errors.age }}</FormError>
                            </FormGroup>
                        </section>

                        <!-- Coordonnées -->
                        <section class="rounded-lg border border-gray-100 bg-gray-50/50 p-5 dark:border-gray-900 dark:bg-gray-1000/30">
                            <h3 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500">
                                <Icon class="text-base" name="call" />
                                Coordonnées
                            </h3>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <FormGroup>
                                    <FormLabel html-for="phone">Téléphone</FormLabel>
                                    <IconInput id="phone" v-model="form.phone" icon="call" autocomplete="off" />
                                    <FormError v-if="form.errors.phone">{{ form.errors.phone }}</FormError>
                                </FormGroup>

                                <FormGroup>
                                    <FormLabel html-for="email">Email</FormLabel>
                                    <IconInput id="email" v-model="form.email" icon="mail" type="email" autocomplete="off" />
                                    <FormError v-if="form.errors.email">{{ form.errors.email }}</FormError>
                                </FormGroup>

                                <FormGroup class="sm:col-span-2">
                                    <FormLabel html-for="address">Adresse</FormLabel>
                                    <textarea
                                        id="address"
                                        v-model="form.address"
                                        rows="2"
                                        class="block w-full box-border rounded border border-gray-200 bg-white px-4 py-2 text-sm leading-5 text-slate-700 outline-none transition-all placeholder-slate-300 focus:border-primary-500 focus:outline-offset-0 focus:outline-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:border-primary-600 dark:focus:outline-primary-950"
                                    ></textarea>
                                    <FormError v-if="form.errors.address">{{ form.errors.address }}</FormError>
                                </FormGroup>
                            </div>
                        </section>

                        <!-- Personne à contacter -->
                        <section class="rounded-lg border border-gray-100 bg-gray-50/50 p-5 dark:border-gray-900 dark:bg-gray-1000/30">
                            <h3 class="mb-1 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500">
                                <Icon class="text-base" name="users-fill" />
                                Personne à contacter
                            </h3>
                            <p class="mb-4 text-xs text-slate-400">Facultatif.</p>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <FormGroup>
                                    <FormLabel html-for="emergency_contact_name">Nom</FormLabel>
                                    <IconInput id="emergency_contact_name" v-model="form.emergency_contact_name" icon="user" autocomplete="off" />
                                </FormGroup>

                                <FormGroup>
                                    <FormLabel html-for="emergency_contact_relationship">Lien de parenté</FormLabel>
                                    <Input id="emergency_contact_relationship" v-model="form.emergency_contact_relationship" autocomplete="off" />
                                </FormGroup>

                                <FormGroup>
                                    <FormLabel html-for="emergency_contact_phone">Téléphone</FormLabel>
                                    <IconInput id="emergency_contact_phone" v-model="form.emergency_contact_phone" icon="call" autocomplete="off" />
                                </FormGroup>

                                <FormGroup>
                                    <FormLabel html-for="emergency_contact_email">Email</FormLabel>
                                    <IconInput id="emergency_contact_email" v-model="form.emergency_contact_email" icon="mail" type="email" autocomplete="off" />
                                </FormGroup>
                            </div>
                        </section>

                        <div class="flex justify-between">
                            <Button size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">Retour</span>
                            </Button>
                            <Button size="rg" variant="primary" type="button" :disabled="!identityComplete" @click="stepIndex = 2">
                                <span class="me-2">Continuer</span>
                                <Icon class="text-lg/4.5" name="arrow-right" />
                            </Button>
                        </div>
                    </div>

                    <!-- Step 3: confirm -->
                    <div v-else-if="currentStep === 'confirm'" key="confirm">
                        <h2 class="mb-1 font-heading text-lg font-bold text-slate-700 dark:text-white">
                            Confirmer l'arrivée
                        </h2>
                        <p class="mb-6 text-sm text-slate-500">
                            Un passage sera créé pour ce patient.
                        </p>

                        <div class="rounded-lg border border-gray-200 bg-gray-50/60 p-5 dark:border-gray-900 dark:bg-gray-1000/40">
                            <template v-if="patientType === 'existing' && selectedPatient">
                                <p class="font-heading text-base font-bold text-slate-700 dark:text-white">
                                    {{ formatPatientName(selectedPatient) }}
                                </p>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ selectedPatient.patient_number }} · né(e) le {{ formatDate(selectedPatient.birth_date) }}
                                    <span v-if="selectedPatient.phone">· {{ selectedPatient.phone }}</span>
                                </p>
                                <span class="mt-3 inline-flex items-center rounded bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-950 dark:text-primary-400">
                                    Patient existant
                                </span>
                            </template>

                            <template v-else-if="patientType === 'new'">
                                <p class="font-heading text-base font-bold text-slate-700 dark:text-white">
                                    <span v-if="civilityLabel">{{ civilityLabel }}</span>
                                    {{ formatPatientName(form) }}
                                </p>
                                <p class="mt-1 text-sm text-slate-500">
                                    <span v-if="birthSummary">{{ birthSummary }}</span> · {{ form.sex === 'M' ? 'Masculin' : 'Féminin' }}
                                    <span v-if="form.phone">· {{ form.phone }}</span>
                                    <span v-if="form.email">· {{ form.email }}</span>
                                </p>
                                <span class="mt-3 inline-flex items-center rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-400">
                                    Nouveau dossier
                                </span>
                            </template>
                        </div>

                        <div
                            v-if="duplicates && duplicates.length > 0"
                            class="mt-5 rounded-lg border border-yellow-300 bg-yellow-50 p-5 dark:border-yellow-900 dark:bg-yellow-950"
                        >
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
                                    <Button class="mt-4" size="sm" variant="white-outline" :disabled="form.processing" @click="confirmDespiteDuplicate">
                                        Créer quand même, c'est un patient différent
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-8 flex justify-between">
                            <Button size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">Modifier</span>
                            </Button>
                            <Button size="rg" variant="primary" type="button" :disabled="form.processing" @click="confirmArrival">
                                <Icon class="text-lg/4.5" name="check" />
                                <span class="ms-2">Confirmer l'arrivée</span>
                            </Button>
                        </div>
                    </div>
                </Transition>
            </div>
        </div>

        <!-- Recent passages: "identifier les patients présents" / "consulter
             le statut du parcours patient" (CDC §5.2.1) -->
        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <h2 class="border-b border-gray-200 p-5 text-sm font-bold uppercase tracking-wide text-slate-500 dark:border-gray-900">
                Passages récents
            </h2>

            <div v-if="recentEpisodes.length === 0" class="p-10 text-center text-sm text-slate-400">
                Aucun passage enregistré.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">Patient</th>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">N° passage</th>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">Date / heure</th>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">Il y a</th>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="episode in recentEpisodes" :key="episode.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-1000">
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <Link :href="`/patients/${episode.patient.id}`" class="text-sm font-medium text-primary-600 hover:text-primary-700">
                                    {{ formatPatientName(episode.patient) }}
                                </Link>
                                <span class="ms-1 text-xs text-slate-400">{{ episode.patient.patient_number }}</span>
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
                                <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-gray-900 dark:text-slate-300">
                                    {{ statusLabels[episode.status] }} · {{ administrativeStatusLabels[episode.administrative_status] }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
