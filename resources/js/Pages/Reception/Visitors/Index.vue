<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    patientSearch: String,
    patientMatches: Array,
    presentVisitors: Array,
    recentDepartures: Array,
});

const page = usePage();
const { can } = usePermissions();
const canCreate = computed(() => can('visitors.create'));
const canClose = computed(() => can('visitors.close'));

const form = useForm({
    full_name: '',
    phone: '',
    category: 'PATIENT_OR_FAMILY_VISIT',
    organization: '',
    professional_attachments: [],
    patient_uuid: '',
    reason: '',
});

const patientQuery = ref(props.patientSearch ?? '');
const selectedPatient = ref(null);
const closingUuid = ref(null);
const historyOpen = ref(false);
const professionalAttachmentsInput = ref(null);
const selectedAttachments = ref([]);
const professionalAttachmentsClientError = ref('');
const selectedAttachmentsOpen = ref(false);
const attachmentGalleryVisitor = ref(null);

const acceptedProfessionalAttachmentTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const maxProfessionalAttachmentSize = 5 * 1024 * 1024;
const maxProfessionalAttachments = 4;

const professionalAttachmentsServerError = computed(() => {
    const errorEntry = Object.entries(form.errors).find(([key]) => key === 'professional_attachments' || key.startsWith('professional_attachments.'));

    return errorEntry?.[1] ?? '';
});

const syncProfessionalAttachments = () => {
    form.professional_attachments = selectedAttachments.value.map((attachment) => attachment.file);
};

const resetProfessionalAttachmentsInput = () => {
    if (professionalAttachmentsInput.value) {
        professionalAttachmentsInput.value.value = '';
    }
};

const clearProfessionalAttachmentErrors = () => {
    professionalAttachmentsClientError.value = '';
    Object.keys(form.errors)
        .filter((key) => key === 'professional_attachments' || key.startsWith('professional_attachments.'))
        .forEach((key) => form.clearErrors(key));
};

const clearProfessionalAttachments = () => {
    selectedAttachments.value.forEach((attachment) => {
        if (attachment.previewUrl) {
            URL.revokeObjectURL(attachment.previewUrl);
        }
    });
    selectedAttachments.value = [];
    selectedAttachmentsOpen.value = false;
    syncProfessionalAttachments();
    clearProfessionalAttachmentErrors();
    resetProfessionalAttachmentsInput();
};

const addProfessionalAttachments = (fileList) => {
    clearProfessionalAttachmentErrors();
    const files = Array.from(fileList ?? []);

    if (!files.length) {
        return;
    }

    const remainingSlots = maxProfessionalAttachments - selectedAttachments.value.length;

    if (remainingSlots <= 0) {
        professionalAttachmentsClientError.value = 'Vous pouvez ajouter 4 fichiers maximum.';
        resetProfessionalAttachmentsInput();
        return;
    }

    const filesToAdd = files.slice(0, remainingSlots);
    const ignoredCount = files.length - filesToAdd.length;

    filesToAdd.forEach((file) => {
        if (!acceptedProfessionalAttachmentTypes.includes(file.type)) {
            professionalAttachmentsClientError.value = `${file.name} : seuls les fichiers JPEG, PNG, WebP ou PDF sont acceptés.`;
            return;
        }

        if (file.size > maxProfessionalAttachmentSize) {
            professionalAttachmentsClientError.value = `${file.name} dépasse la limite de 5 Mo.`;
            return;
        }

        selectedAttachments.value.push({
            file,
            isImage: file.type.startsWith('image/'),
            previewUrl: URL.createObjectURL(file),
        });
    });

    if (ignoredCount > 0) {
        professionalAttachmentsClientError.value = `${ignoredCount} fichier${ignoredCount > 1 ? 's ont' : ' a'} été ignoré${ignoredCount > 1 ? 's' : ''} : 4 fichiers maximum.`;
    }

    syncProfessionalAttachments();
    resetProfessionalAttachmentsInput();
};

const removeProfessionalAttachment = (index) => {
    const [removed] = selectedAttachments.value.splice(index, 1);

    if (removed?.previewUrl) {
        URL.revokeObjectURL(removed.previewUrl);
    }

    if (!selectedAttachments.value.length) {
        selectedAttachmentsOpen.value = false;
    }

    syncProfessionalAttachments();
    clearProfessionalAttachmentErrors();
    resetProfessionalAttachmentsInput();
};

const handleProfessionalAttachmentsChange = (event) => {
    addProfessionalAttachments(event.target.files);
};

const handleProfessionalAttachmentsDrop = (event) => {
    addProfessionalAttachments(event.dataTransfer?.files);
};

const openAttachmentGallery = (visitor) => {
    attachmentGalleryVisitor.value = visitor;
};

watch(() => form.category, (category) => {
    form.clearErrors('organization', 'patient_uuid');

    if (category === 'PROFESSIONAL') {
        form.patient_uuid = '';
        selectedPatient.value = null;
        patientQuery.value = '';
    } else {
        form.organization = '';
        clearProfessionalAttachments();
    }
});

onBeforeUnmount(() => {
    selectedAttachments.value.forEach((attachment) => {
        if (attachment.previewUrl) {
            URL.revokeObjectURL(attachment.previewUrl);
        }
    });
});

const submitPatientSearch = () => {
    router.get('/reception/visitors', {
        patient_q: patientQuery.value,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const selectPatient = (patient) => {
    selectedPatient.value = patient;
    form.patient_uuid = patient.uuid;
    form.clearErrors('patient_uuid');
};

const clearPatient = () => {
    selectedPatient.value = null;
    form.patient_uuid = '';
};

const submit = () => {
    form.post('/reception/visitors', {
        preserveScroll: true,
        onSuccess: () => {
            clearProfessionalAttachments();
            form.reset();
            form.category = 'PATIENT_OR_FAMILY_VISIT';
            selectedPatient.value = null;
            patientQuery.value = '';
        },
    });
};

const closeVisit = (visitor) => {
    if (!window.confirm(`Enregistrer la sortie de ${visitor.full_name} ?`)) {
        return;
    }

    closingUuid.value = visitor.uuid;
    router.post(`/reception/visitors/${visitor.uuid}/close`, {}, {
        preserveScroll: true,
        onFinish: () => {
            closingUuid.value = null;
        },
    });
};

const visitorInitials = (visitor) => visitor.full_name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');

const categoryLabel = (category) => category === 'PROFESSIONAL'
    ? 'Professionnel / partenariat'
    : 'Visite patient / famille';

const formatFileSize = (bytes) => {
    if (!bytes) {
        return '';
    }

    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} Mo`
        : `${Math.max(1, Math.round(bytes / 1024))} Ko`;
};
</script>

<template>
    <Head title="Réception visiteur" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300">
                    <Icon class="text-2xl" name="users" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">Réception visiteur</h1>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                        Registre des visites non cliniques et suivi des entrées et sorties.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-medium text-slate-500 dark:bg-gray-900 dark:text-slate-300">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    {{ presentVisitors.length }} présent{{ presentVisitors.length > 1 ? 's' : '' }}
                </span>
                <Button :as="Link" href="/reception" size="rg" variant="white-outline">
                    <Icon class="text-lg/4.5" name="arrow-left" />
                    <span class="ms-2">Accueil réception</span>
                </Button>
            </div>
        </header>

        <p v-if="page.props.errors?.visitor" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" role="alert">
            {{ page.props.errors.visitor }}
        </p>

        <div class="grid gap-5 xl:grid-cols-[430px_minmax(0,1fr)] xl:items-start">
            <Card v-if="canCreate" class="overflow-hidden shadow-sm">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Nouvelle entrée</h2>
                    <p class="mt-0.5 text-xs text-slate-400">L’heure d’entrée est enregistrée automatiquement.</p>
                </div>

                <form class="space-y-5 p-5" @submit.prevent="submit">
                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-slate-700 dark:text-white">Raison de la visite <span class="text-red-500">*</span></legend>
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                            <button
                                type="button"
                                :aria-pressed="form.category === 'PROFESSIONAL'"
                                :class="[
                                    'flex items-start gap-3 rounded-md border p-3 text-start transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100 dark:focus-visible:ring-primary-950',
                                    form.category === 'PROFESSIONAL'
                                        ? 'border-primary-500 bg-primary-50/50 dark:border-primary-700 dark:bg-primary-950/30'
                                        : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950 dark:hover:border-gray-700',
                                ]"
                                @click="form.category = 'PROFESSIONAL'"
                            >
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon name="briefcase" /></span>
                                <span>
                                    <span class="block text-sm font-bold text-slate-700 dark:text-white">Professionnel</span>
                                    <span class="mt-0.5 block text-xs leading-5 text-slate-400">Partenariat, fournisseur ou rendez-vous professionnel.</span>
                                </span>
                            </button>

                            <button
                                type="button"
                                :aria-pressed="form.category === 'PATIENT_OR_FAMILY_VISIT'"
                                :class="[
                                    'flex items-start gap-3 rounded-md border p-3 text-start transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100 dark:focus-visible:ring-primary-950',
                                    form.category === 'PATIENT_OR_FAMILY_VISIT'
                                        ? 'border-primary-500 bg-primary-50/50 dark:border-primary-700 dark:bg-primary-950/30'
                                        : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950 dark:hover:border-gray-700',
                                ]"
                                @click="form.category = 'PATIENT_OR_FAMILY_VISIT'"
                            >
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon name="contact" /></span>
                                <span>
                                    <span class="block text-sm font-bold text-slate-700 dark:text-white">Patient / famille</span>
                                    <span class="mt-0.5 block text-xs leading-5 text-slate-400">Visite ou accompagnement sans création d’un passage clinique.</span>
                                </span>
                            </button>
                        </div>
                        <FormError v-if="form.errors.category">{{ form.errors.category }}</FormError>
                    </fieldset>

                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="visitor_full_name">Nom complet <span class="text-red-500">*</span></FormLabel>
                        <IconInput id="visitor_full_name" v-model="form.full_name" icon="user" autocomplete="name" :aria-invalid="Boolean(form.errors.full_name)" />
                        <FormError v-if="form.errors.full_name">{{ form.errors.full_name }}</FormError>
                    </FormGroup>

                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="visitor_phone">Téléphone <span class="text-red-500">*</span></FormLabel>
                        <IconInput id="visitor_phone" v-model="form.phone" icon="call" type="tel" inputmode="tel" autocomplete="tel" :aria-invalid="Boolean(form.errors.phone)" />
                        <FormError v-if="form.errors.phone">{{ form.errors.phone }}</FormError>
                    </FormGroup>

                    <FormGroup v-if="form.category === 'PROFESSIONAL'" class="!mb-0">
                        <FormLabel class="mb-1.5" for="visitor_organization">Organisme <span class="text-red-500">*</span></FormLabel>
                        <IconInput id="visitor_organization" v-model="form.organization" icon="building" autocomplete="organization" placeholder="Entreprise ou institution" :aria-invalid="Boolean(form.errors.organization)" />
                        <FormError v-if="form.errors.organization">{{ form.errors.organization }}</FormError>
                    </FormGroup>

                    <FormGroup v-if="form.category === 'PROFESSIONAL'" class="!mb-0">
                        <FormLabel class="mb-1.5" for="visitor_professional_attachments">Documents <span class="font-normal text-slate-400">(facultatif · 4 max.)</span></FormLabel>

                        <div
                            :class="[
                                'overflow-hidden rounded-md border border-dashed transition-colors',
                                professionalAttachmentsServerError || professionalAttachmentsClientError
                                    ? 'border-red-400 bg-red-50/40 dark:border-red-900 dark:bg-red-950/20'
                                    : 'border-gray-300 bg-gray-50/60 hover:border-primary-400 dark:border-gray-800 dark:bg-gray-1000/30 dark:hover:border-primary-700',
                            ]"
                            @dragover.prevent
                            @drop.prevent="handleProfessionalAttachmentsDrop"
                        >
                            <input
                                id="visitor_professional_attachments"
                                ref="professionalAttachmentsInput"
                                class="sr-only"
                                type="file"
                                multiple
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                :aria-invalid="Boolean(professionalAttachmentsServerError || professionalAttachmentsClientError)"
                                @change="handleProfessionalAttachmentsChange"
                            />

                            <div v-if="selectedAttachments.length" class="flex items-center gap-3 p-3">
                                <button type="button" class="relative flex h-20 w-24 shrink-0 items-center justify-center overflow-hidden rounded border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950" aria-label="Voir les fichiers sélectionnés" @click="selectedAttachmentsOpen = true">
                                    <img v-if="selectedAttachments[0].isImage" :src="selectedAttachments[0].previewUrl" alt="" class="h-full w-full object-cover" />
                                    <span v-else class="flex flex-col items-center text-slate-500 dark:text-slate-300">
                                        <Icon class="text-2xl" name="file-text" />
                                        <span class="mt-1 text-[10px] font-bold">PDF</span>
                                    </span>
                                    <span v-if="selectedAttachments.length > 1" class="absolute inset-0 flex items-center justify-center bg-slate-900/65 text-sm font-bold text-white">
                                        +{{ selectedAttachments.length - 1 }}
                                    </span>
                                </button>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-bold text-slate-700 dark:text-white">{{ selectedAttachments[0].file.name }}</p>
                                    <p class="mt-0.5 text-[11px] text-slate-400">
                                        {{ selectedAttachments.length }} fichier{{ selectedAttachments.length > 1 ? 's' : '' }} · {{ 4 - selectedAttachments.length }} place{{ 4 - selectedAttachments.length !== 1 ? 's' : '' }} restante{{ 4 - selectedAttachments.length !== 1 ? 's' : '' }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
                                        <button type="button" class="text-xs font-bold text-primary-600 hover:underline" @click="selectedAttachmentsOpen = true">Gérer</button>
                                        <label v-if="selectedAttachments.length < 4" for="visitor_professional_attachments" class="cursor-pointer text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Ajouter</label>
                                        <button type="button" class="text-xs font-bold text-red-600 hover:underline dark:text-red-300" @click="clearProfessionalAttachments">Tout retirer</button>
                                    </div>
                                </div>
                            </div>

                            <label v-else for="visitor_professional_attachments" class="flex cursor-pointer flex-col items-center px-4 py-6 text-center">
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm dark:bg-gray-900 dark:text-slate-300">
                                    <Icon class="text-xl" name="upload-cloud" />
                                </span>
                                <span class="mt-2 text-sm font-bold text-slate-700 dark:text-white">Ajouter des fichiers</span>
                                <span class="mt-1 text-xs leading-5 text-slate-400">Images ou PDF · 4 fichiers max. · 5 Mo par fichier</span>
                                <span class="text-[11px] leading-5 text-slate-400">Cliquez ou déposez les fichiers ici. Stockage privé.</span>
                            </label>
                        </div>
                        <FormError v-if="professionalAttachmentsClientError">{{ professionalAttachmentsClientError }}</FormError>
                        <FormError v-else-if="professionalAttachmentsServerError">{{ professionalAttachmentsServerError }}</FormError>
                    </FormGroup>

                    <FormGroup v-if="form.category === 'PATIENT_OR_FAMILY_VISIT'" class="!mb-0">
                        <FormLabel class="mb-1.5" for="visitor_patient_search">Patient concerné <span class="font-normal text-slate-400">(facultatif)</span></FormLabel>

                        <div v-if="selectedPatient" class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-1000/40">
                            <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(selectedPatient)" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(selectedPatient) }}</span>
                                <span class="block text-xs text-slate-400">{{ selectedPatient.patient_number }}</span>
                            </span>
                            <button type="button" class="text-slate-400 hover:text-red-600" aria-label="Retirer le patient" @click="clearPatient"><Icon class="text-lg" name="cross-circle" /></button>
                        </div>

                        <template v-else>
                            <div class="flex gap-2" role="search">
                                <IconInput id="visitor_patient_search" v-model="patientQuery" class="min-w-0 flex-1" icon="search" type="search" placeholder="Nom ou n° patient" autocomplete="off" @keyup.enter.prevent="submitPatientSearch" />
                                <Button size="rg" type="button" variant="white-outline" @click="submitPatientSearch">Chercher</Button>
                            </div>

                            <div v-if="patientSearch" class="mt-2 overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                                <button
                                    v-for="patient in patientMatches"
                                    :key="patient.uuid"
                                    type="button"
                                    class="flex w-full items-center gap-3 border-b border-gray-200 p-3 text-start last:border-b-0 hover:bg-gray-50 dark:border-gray-900 dark:hover:bg-gray-1000"
                                    @click="selectPatient(patient)"
                                >
                                    <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(patient)" aria-hidden="true" />
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(patient) }}</span>
                                        <span class="block text-xs text-slate-400">{{ patient.patient_number }} · {{ formatDate(patient.birth_date) }}</span>
                                    </span>
                                </button>
                                <p v-if="patientMatches.length === 0" class="p-3 text-center text-xs text-slate-400">Aucun patient trouvé.</p>
                            </div>
                        </template>
                        <FormError v-if="form.errors.patient_uuid">{{ form.errors.patient_uuid }}</FormError>
                    </FormGroup>

                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="visitor_reason">Motif détaillé <span class="text-red-500">*</span></FormLabel>
                        <textarea
                            id="visitor_reason"
                            v-model="form.reason"
                            rows="3"
                            maxlength="1000"
                            placeholder="Précisez l’objet de la visite"
                            :aria-invalid="Boolean(form.errors.reason)"
                            class="block min-h-24 w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm leading-5 text-slate-700 outline-none transition placeholder-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 aria-[invalid=true]:border-red-400 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:border-primary-600 dark:focus:ring-primary-950"
                        ></textarea>
                        <FormError v-if="form.errors.reason">{{ form.errors.reason }}</FormError>
                    </FormGroup>

                    <Button block size="rg" type="submit" variant="primary" :disabled="form.processing">
                        <Icon class="text-lg" name="check" />
                        <span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Enregistrer l’entrée' }}</span>
                    </Button>
                </form>
            </Card>

            <div class="space-y-5">
                <Card class="overflow-hidden shadow-sm">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <div>
                            <h2 class="text-sm font-bold text-slate-700 dark:text-white">Visiteurs présents</h2>
                            <p class="mt-0.5 text-xs text-slate-400">Enregistrez la sortie au départ du visiteur.</p>
                        </div>
                        <span class="rounded bg-gray-100 px-2.5 py-1 text-xs font-bold text-slate-600 dark:bg-gray-900 dark:text-slate-300">{{ presentVisitors.length }}</span>
                    </div>

                    <div v-if="presentVisitors.length" class="divide-y divide-gray-200 dark:divide-gray-900">
                        <article v-for="visitor in presentVisitors" :key="visitor.uuid" class="p-4 sm:p-5">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                                <Avatar rounded size="rg" variant="slate-pale" :text="visitorInitials(visitor)" aria-hidden="true" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h3 class="font-heading text-sm font-bold text-slate-700 dark:text-white">{{ visitor.full_name }}</h3>
                                            <p class="mt-0.5 text-xs text-slate-400">{{ visitor.phone }}</p>
                                        </div>
                                        <span class="inline-flex w-fit rounded border border-gray-200 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-500 dark:border-gray-800 dark:text-slate-300">
                                            {{ categoryLabel(visitor.category) }}
                                        </span>
                                    </div>

                                    <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                        <div>
                                            <dt class="text-slate-400">Entrée</dt>
                                            <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-300">{{ formatDateTime(visitor.checked_in_at) }} · {{ formatRelativeTime(visitor.checked_in_at) }}</dd>
                                        </div>
                                        <div v-if="visitor.organization">
                                            <dt class="text-slate-400">Organisme</dt>
                                            <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-300">{{ visitor.organization }}</dd>
                                        </div>
                                        <div v-if="visitor.attachments?.length" class="sm:col-span-2">
                                            <dt class="text-slate-400">Documents</dt>
                                            <dd class="mt-1.5">
                                                <button
                                                    type="button"
                                                    class="inline-flex max-w-full items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-2 text-start transition hover:border-primary-300 dark:border-gray-800 dark:bg-gray-1000/40 dark:hover:border-primary-800"
                                                    :aria-label="`Voir les ${visitor.attachments.length} documents de ${visitor.full_name}`"
                                                    @click="openAttachmentGallery(visitor)"
                                                >
                                                    <span class="relative flex h-14 w-20 shrink-0 items-center justify-center overflow-hidden rounded bg-white text-slate-500 dark:bg-gray-950 dark:text-slate-300">
                                                        <img v-if="visitor.attachments[0].is_image" :src="visitor.attachments[0].url" alt="" loading="lazy" class="h-full w-full object-cover" />
                                                        <span v-else class="flex flex-col items-center">
                                                            <Icon class="text-xl" name="file-text" />
                                                            <span class="mt-0.5 text-[9px] font-bold">PDF</span>
                                                        </span>
                                                        <span v-if="visitor.attachments.length > 1" class="absolute inset-0 flex items-center justify-center bg-slate-900/65 text-sm font-bold text-white">
                                                            +{{ visitor.attachments.length - 1 }}
                                                        </span>
                                                    </span>
                                                    <span class="min-w-0">
                                                        <span class="block truncate text-xs font-bold text-slate-700 dark:text-white">{{ visitor.attachments[0].original_name }}</span>
                                                        <span class="mt-0.5 block text-[11px] text-slate-400">{{ visitor.attachments.length }} fichier{{ visitor.attachments.length > 1 ? 's' : '' }} · Voir</span>
                                                    </span>
                                                </button>
                                            </dd>
                                        </div>
                                        <div v-if="visitor.patient">
                                            <dt class="text-slate-400">Patient concerné</dt>
                                            <dd class="mt-0.5"><Link :href="`/patients/${visitor.patient.uuid}`" class="font-medium text-primary-600 hover:underline">{{ formatPatientName(visitor.patient) }}</Link></dd>
                                        </div>
                                        <div class="sm:col-span-2">
                                            <dt class="text-slate-400">Motif</dt>
                                            <dd class="mt-0.5 whitespace-pre-line text-sm leading-5 text-slate-600 dark:text-slate-300">{{ visitor.reason }}</dd>
                                        </div>
                                    </dl>
                                </div>

                                <Button v-if="canClose" class="justify-center sm:shrink-0" size="sm" type="button" variant="white-outline" :disabled="closingUuid === visitor.uuid" @click="closeVisit(visitor)">
                                    <Icon class="text-base" name="signout" />
                                    <span class="ms-1.5">{{ closingUuid === visitor.uuid ? 'Enregistrement…' : 'Enregistrer la sortie' }}</span>
                                </Button>
                            </div>
                        </article>
                    </div>

                    <div v-else class="px-5 py-12 text-center">
                        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="users" /></span>
                        <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun visiteur présent</p>
                        <p class="mt-1 text-xs text-slate-400">Les nouvelles entrées apparaîtront ici.</p>
                    </div>
                </Card>

                <Card class="overflow-hidden shadow-sm">
                    <button type="button" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-start" :aria-expanded="historyOpen" @click="historyOpen = !historyOpen">
                        <span>
                            <span class="block text-sm font-bold text-slate-700 dark:text-white">Sorties récentes</span>
                            <span class="mt-0.5 block text-xs text-slate-400">{{ recentDepartures.length }} départ{{ recentDepartures.length > 1 ? 's' : '' }} enregistré{{ recentDepartures.length > 1 ? 's' : '' }}</span>
                        </span>
                        <Icon :class="['text-lg text-slate-400 transition-transform', historyOpen ? 'rotate-180' : '']" name="chevron-down" />
                    </button>

                    <div v-if="historyOpen" class="border-t border-gray-200 dark:border-gray-900">
                        <div v-if="recentDepartures.length" class="divide-y divide-gray-200 dark:divide-gray-900">
                            <div v-for="visitor in recentDepartures" :key="visitor.uuid" class="grid gap-2 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_180px_180px] sm:items-center">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ visitor.full_name }}</p>
                                    <p class="mt-0.5 truncate text-xs text-slate-400">{{ categoryLabel(visitor.category) }}</p>
                                </div>
                                <p class="text-xs text-slate-500">Entrée : {{ formatDateTime(visitor.checked_in_at) }}</p>
                                <p class="text-xs text-slate-500">Sortie : {{ formatDateTime(visitor.checked_out_at) }}</p>
                            </div>
                        </div>
                        <p v-else class="px-5 py-8 text-center text-sm text-slate-400">Aucune sortie récente.</p>
                    </div>
                </Card>
            </div>
        </div>
    </div>

    <Teleport to="body">
        <div v-if="selectedAttachmentsOpen" class="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-950/55 p-4" role="dialog" aria-modal="true" aria-labelledby="selected-attachments-title" @click.self="selectedAttachmentsOpen = false">
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950">
                <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-950">
                    <div>
                        <h2 id="selected-attachments-title" class="text-sm font-bold text-slate-700 dark:text-white">Fichiers sélectionnés</h2>
                        <p class="mt-0.5 text-xs text-slate-400">{{ selectedAttachments.length }} sur 4</p>
                    </div>
                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded text-slate-400 hover:bg-gray-100 hover:text-slate-700 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer" @click="selectedAttachmentsOpen = false">
                        <Icon class="text-xl" name="cross" />
                    </button>
                </div>

                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    <article v-for="(attachment, index) in selectedAttachments" :key="`${attachment.file.name}-${attachment.file.lastModified}-${index}`" class="overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                        <a :href="attachment.previewUrl" target="_blank" rel="noopener" class="flex h-36 items-center justify-center bg-gray-50 dark:bg-gray-1000/40">
                            <img v-if="attachment.isImage" :src="attachment.previewUrl" alt="" class="h-full w-full object-contain" />
                            <span v-else class="flex flex-col items-center text-slate-500 dark:text-slate-300">
                                <Icon class="text-3xl" name="file-text" />
                                <span class="mt-2 text-xs font-bold">Document PDF</span>
                            </span>
                        </a>
                        <div class="flex items-center gap-3 p-3">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-xs font-bold text-slate-700 dark:text-white">{{ attachment.file.name }}</span>
                                <span class="mt-0.5 block text-[11px] text-slate-400">{{ formatFileSize(attachment.file.size) }}</span>
                            </span>
                            <button type="button" class="text-xs font-bold text-red-600 hover:underline dark:text-red-300" @click="removeProfessionalAttachment(index)">Retirer</button>
                        </div>
                    </article>
                </div>

                <div v-if="selectedAttachments.length < 4" class="border-t border-gray-200 px-5 py-4 dark:border-gray-800">
                    <label for="visitor_professional_attachments" class="inline-flex cursor-pointer items-center gap-2 text-xs font-bold text-primary-600 hover:underline" @click="selectedAttachmentsOpen = false">
                        <Icon name="plus" /> Ajouter d’autres fichiers
                    </label>
                </div>
            </div>
        </div>

        <div v-if="attachmentGalleryVisitor" class="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-950/55 p-4" role="dialog" aria-modal="true" aria-labelledby="visitor-attachments-title" @click.self="attachmentGalleryVisitor = null">
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950">
                <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-950">
                    <div class="min-w-0">
                        <h2 id="visitor-attachments-title" class="truncate text-sm font-bold text-slate-700 dark:text-white">Documents de {{ attachmentGalleryVisitor.full_name }}</h2>
                        <p class="mt-0.5 text-xs text-slate-400">{{ attachmentGalleryVisitor.attachments.length }} fichier{{ attachmentGalleryVisitor.attachments.length > 1 ? 's' : '' }}</p>
                    </div>
                    <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-gray-100 hover:text-slate-700 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer" @click="attachmentGalleryVisitor = null">
                        <Icon class="text-xl" name="cross" />
                    </button>
                </div>

                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    <a
                        v-for="attachment in attachmentGalleryVisitor.attachments"
                        :key="attachment.uuid"
                        :href="attachment.url"
                        target="_blank"
                        rel="noopener"
                        class="overflow-hidden rounded-md border border-gray-200 transition hover:border-primary-300 dark:border-gray-800 dark:hover:border-primary-800"
                    >
                        <span class="flex h-36 items-center justify-center bg-gray-50 dark:bg-gray-1000/40">
                            <img v-if="attachment.is_image" :src="attachment.url" :alt="attachment.original_name" loading="lazy" class="h-full w-full object-contain" />
                            <span v-else class="flex flex-col items-center text-slate-500 dark:text-slate-300">
                                <Icon class="text-3xl" name="file-text" />
                                <span class="mt-2 text-xs font-bold">Document PDF</span>
                            </span>
                        </span>
                        <span class="block p-3">
                            <span class="block truncate text-xs font-bold text-slate-700 dark:text-white">{{ attachment.original_name }}</span>
                            <span class="mt-0.5 block text-[11px] text-slate-400">{{ formatFileSize(attachment.size) }} · Ouvrir</span>
                        </span>
                    </a>
                </div>
            </div>
        </div>
    </Teleport>
</template>
