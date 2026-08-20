<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import CheckBox from '@/Components/UI/CheckBox.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({
    layout: AppLayout,
});

const props = defineProps({
    patients: Object,
    search: String,
});

const { can } = usePermissions();
const canUpdatePatient = computed(() => can('patients.update'));
const canDeletePatient = computed(() => can('patients.delete'));

const query = ref(props.search ?? '');
const selectedUuids = ref([]);
const deleteTargets = ref([]);
const deleteReason = ref('');
const deleteError = ref('');
const deleteProcessing = ref(false);

const currentPageUuids = computed(() => props.patients.data.map((patient) => patient.uuid));
const allCurrentSelected = computed(() => currentPageUuids.value.length > 0
    && currentPageUuids.value.every((uuid) => selectedUuids.value.includes(uuid))
);

const submitSearch = () => {
    selectedUuids.value = [];
    router.get('/patients', { q: query.value }, { preserveState: true, replace: true });
};

const toggleCurrentPage = (checked) => {
    if (checked) {
        selectedUuids.value = [...new Set([...selectedUuids.value, ...currentPageUuids.value])];
        return;
    }

    selectedUuids.value = selectedUuids.value.filter((uuid) => !currentPageUuids.value.includes(uuid));
};

const togglePatient = (uuid, checked) => {
    selectedUuids.value = checked
        ? [...new Set([...selectedUuids.value, uuid])]
        : selectedUuids.value.filter((selectedUuid) => selectedUuid !== uuid);
};

const openDeleteDialog = (patients) => {
    deleteTargets.value = patients;
    deleteReason.value = '';
    deleteError.value = '';
};

const closeDeleteDialog = () => {
    if (deleteProcessing.value) {
        return;
    }

    deleteTargets.value = [];
    deleteReason.value = '';
    deleteError.value = '';
};

const openBulkDeleteDialog = () => {
    openDeleteDialog(props.patients.data.filter((patient) => selectedUuids.value.includes(patient.uuid)));
};

const confirmDelete = () => {
    const reason = deleteReason.value.trim();

    if (!reason) {
        deleteError.value = 'Indiquez le motif de la suppression.';
        return;
    }

    deleteError.value = '';
    const targets = [...deleteTargets.value];
    const targetUuids = targets.map((patient) => patient.uuid);
    const options = {
        preserveScroll: true,
        onStart: () => {
            deleteProcessing.value = true;
        },
        onSuccess: () => {
            selectedUuids.value = selectedUuids.value.filter((uuid) => !targetUuids.includes(uuid));
            deleteTargets.value = [];
            deleteReason.value = '';
        },
        onFinish: () => {
            deleteProcessing.value = false;
        },
    };

    if (targets.length === 1) {
        router.delete(`/patients/${targets[0].uuid}`, {
            ...options,
            data: { reason },
        });
        return;
    }

    router.post('/patients/bulk-delete', {
        patient_uuids: targetUuids,
        reason,
    }, options);
};

const sexLabel = (sex) => (sex === 'M' ? 'Masculin' : 'Féminin');

watch(
    () => currentPageUuids.value.join(','),
    () => {
        selectedUuids.value = selectedUuids.value.filter((uuid) => currentPageUuids.value.includes(uuid));
    },
);
</script>

<template>
    <Head title="Patients" />

    <div class="w-full space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">Patients</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ patients.total }} dossier{{ patients.total > 1 ? 's' : '' }} patient{{ patients.total > 1 ? 's' : '' }} enregistré{{ patients.total > 1 ? 's' : '' }}.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="can('cash.view')" :as="Link" href="/cash" size="rg" variant="white-outline">
                    <Icon class="text-xl/4.5" name="wallet" />
                    <span class="ms-2">Gérer la caisse</span>
                </Button>
                <Button :as="Link" href="/reception" size="rg" variant="primary">
                    <Icon class="text-xl/4.5" name="user-add" />
                    <span class="ms-2">Nouvelle arrivée</span>
                </Button>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <form class="relative w-full sm:max-w-md" role="search" @submit.prevent="submitSearch">
                    <Input v-model="query" icon="start" type="search" placeholder="Nom, numéro patient ou téléphone" autocomplete="off" />
                    <button type="submit" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400" aria-label="Rechercher">
                        <Icon class="text-lg/4.5" name="search" />
                    </button>
                </form>

                <div v-if="canDeletePatient && selectedUuids.length" class="flex items-center justify-between gap-3 rounded border border-gray-200 px-3 py-2 dark:border-gray-800">
                    <span class="text-xs font-bold text-slate-600 dark:text-slate-300">
                        {{ selectedUuids.length }} sélectionné{{ selectedUuids.length > 1 ? 's' : '' }}
                    </span>
                    <Button size="sm" variant="danger" type="button" @click="openBulkDeleteDialog">
                        <Icon class="text-base" name="trash" />
                        <span class="ms-1.5">Supprimer</span>
                    </Button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse">
                    <caption class="sr-only">Liste des patients</caption>
                    <thead>
                        <tr class="bg-gray-50/70 dark:bg-gray-1000/40">
                            <th v-if="canDeletePatient" class="w-12 border-b border-gray-200 px-4 py-2.5 text-center dark:border-gray-900">
                                <CheckBox
                                    id="patients-select-all"
                                    size="sm"
                                    :model-value="allCurrentSelected"
                                    aria-label="Sélectionner tous les patients de cette page"
                                    @update:model-value="toggleCurrentPage"
                                />
                            </th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Patient</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Sexe</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Naissance</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Téléphone</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Priorité</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="patient in patients.data"
                            :key="patient.uuid"
                            class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000"
                        >
                            <td v-if="canDeletePatient" class="border-b border-gray-200 px-4 py-3 text-center dark:border-gray-900">
                                <CheckBox
                                    :id="`patient-${patient.uuid}`"
                                    size="sm"
                                    :model-value="selectedUuids.includes(patient.uuid)"
                                    :aria-label="`Sélectionner ${formatPatientName(patient)}`"
                                    @update:model-value="togglePatient(patient.uuid, $event)"
                                />
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <div class="flex min-w-[230px] items-center gap-3">
                                    <Avatar
                                        rounded
                                        size="sm"
                                        variant="slate-pale"
                                        :text="formatPatientInitials(patient)"
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0">
                                        <Link :href="`/patients/${patient.uuid}`" class="block truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">
                                            {{ formatPatientName(patient) }}
                                        </Link>
                                        <span class="mt-0.5 block text-xs text-slate-400">{{ patient.patient_number }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">{{ sexLabel(patient.sex) }}</td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">
                                {{ formatDate(patient.birth_date) }}
                                <span v-if="patient.birth_date_is_approximate" class="ms-1 text-xs text-slate-400">Estimée</span>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">{{ patient.phone ?? '—' }}</td>
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <span v-if="patient.active_emergency_episodes_count > 0" class="inline-flex items-center gap-1.5 rounded border border-red-200 px-2 py-1 text-xs font-bold uppercase text-red-600 dark:border-red-900 dark:text-red-300">
                                    <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Urgence
                                </span>
                                <span v-else class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-slate-300 dark:bg-slate-600"></span> Normal
                                </span>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-end dark:border-gray-900">
                                <div class="inline-flex items-center gap-1.5">
                                    <Button
                                        :as="Link"
                                        :href="`/patients/${patient.uuid}`"
                                        icon
                                        size="sm"
                                        variant="white-outline"
                                        :aria-label="`Voir ${formatPatientName(patient)}`"
                                        title="Voir le dossier"
                                    >
                                        <Icon class="text-base" name="eye" />
                                    </Button>
                                    <Button
                                        v-if="canUpdatePatient"
                                        :as="Link"
                                        :href="`/patients/${patient.uuid}/edit`"
                                        icon
                                        size="sm"
                                        variant="white-outline"
                                        :aria-label="`Modifier ${formatPatientName(patient)}`"
                                        title="Modifier le patient"
                                    >
                                        <Icon class="text-base" name="edit" />
                                    </Button>
                                    <Button
                                        v-if="canDeletePatient"
                                        icon
                                        size="sm"
                                        variant="danger-outline"
                                        type="button"
                                        :aria-label="`Supprimer ${formatPatientName(patient)}`"
                                        title="Supprimer le patient"
                                        @click="openDeleteDialog([patient])"
                                    >
                                        <Icon class="text-base" name="trash" />
                                    </Button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="patients.data.length === 0">
                            <td :colspan="canDeletePatient ? 7 : 6" class="px-5 py-12 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900">
                                    <Icon class="text-xl" name="users" />
                                </span>
                                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun patient trouvé</p>
                                <p class="mt-1 text-xs text-slate-400">Modifiez votre recherche ou enregistrez une nouvelle arrivée.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="patients.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 p-4 dark:border-gray-900">
                <span class="text-xs text-slate-400">Page {{ patients.current_page }} sur {{ patients.last_page }}</span>
                <div class="flex flex-wrap items-center gap-1">
                    <template v-for="(link, index) in patients.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            :class="[
                                'rounded px-3 py-1.5 text-sm',
                                link.active ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900',
                            ]"
                            v-html="link.label"
                        />
                        <span v-else class="rounded px-3 py-1.5 text-sm text-slate-300" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>

        <div
            v-if="deleteTargets.length"
            class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4"
            role="presentation"
            @click.self="closeDeleteDialog"
        >
            <section class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="delete-patient-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-300">
                        <Icon class="text-xl" name="trash" />
                    </span>
                    <div>
                        <h2 id="delete-patient-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">
                            {{ deleteTargets.length > 1 ? `Supprimer ${deleteTargets.length} patients ?` : 'Supprimer ce patient ?' }}
                        </h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500">
                            Le dossier sera archivé par Soft Delete. Aucune donnée clinique ne sera supprimée définitivement.
                        </p>
                    </div>
                </div>

                <div class="mt-5">
                    <label for="delete_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label>
                    <textarea
                        id="delete_reason"
                        v-model="deleteReason"
                        rows="3"
                        autofocus
                        placeholder="Ex. dossier créé en double"
                        class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none transition-all placeholder:text-slate-300 focus:border-red-500 focus:ring-2 focus:ring-red-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-red-950"
                        @input="deleteError = ''"
                    ></textarea>
                    <p v-if="deleteError" class="mt-1.5 text-xs text-red-600">{{ deleteError }}</p>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" :disabled="deleteProcessing" @click="closeDeleteDialog">Annuler</Button>
                    <Button size="rg" variant="danger" type="button" :disabled="deleteProcessing" @click="confirmDelete">
                        <Icon class="text-lg" name="trash" />
                        <span class="ms-2">{{ deleteProcessing ? 'Suppression…' : 'Confirmer la suppression' }}</span>
                    </Button>
                </div>
            </section>
        </div>
    </div>
</template>
