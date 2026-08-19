<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({
    layout: AppLayout,
});

const props = defineProps({
    patients: Object,
    search: String,
});

const query = ref(props.search ?? '');

const submitSearch = () => {
    router.get('/patients', { q: query.value }, { preserveState: true, replace: true });
};

const sexLabel = (sex) => (sex === 'M' ? 'Masculin' : 'Féminin');
</script>

<template>
    <Head title="Patients" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">
                    Patients
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ patients.total }} patient(s) enregistré(s).
                </p>
            </div>

            <Button :as="Link" href="/patients/create" size="rg" variant="primary">
                <Icon class="text-xl/4.5" name="plus" />
                <span class="ms-2">Nouveau patient</span>
            </Button>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="border-b border-gray-200 p-5 dark:border-gray-900">
                <form class="relative max-w-sm" @submit.prevent="submitSearch">
                    <Input
                        v-model="query"
                        icon="start"
                        placeholder="Nom, numéro patient ou téléphone"
                    />
                    <button
                        type="submit"
                        class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400"
                    >
                        <Icon class="text-lg/4.5" name="search" />
                    </button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">N° patient</th>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">Nom</th>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">Sexe</th>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">Date de naissance</th>
                            <th class="border-b border-gray-200 px-5 py-2 text-start text-sm font-normal text-slate-400 dark:border-gray-900">Téléphone</th>
                            <th class="border-b border-gray-200 px-5 py-2 dark:border-gray-900"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="patient in patients.data"
                            :key="patient.id"
                            class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-1000"
                        >
                            <td class="border-b border-gray-200 px-5 py-3 text-sm font-medium text-slate-700 dark:border-gray-900 dark:text-white">
                                {{ patient.patient_number }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-600 dark:border-gray-900 dark:text-slate-300">
                                {{ patient.last_name }} {{ patient.first_name }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">
                                {{ sexLabel(patient.sex) }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">
                                {{ patient.birth_date }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">
                                {{ patient.phone ?? '—' }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-end dark:border-gray-900">
                                <Link
                                    :href="`/patients/${patient.id}`"
                                    class="text-sm font-medium text-primary-600 hover:text-primary-700"
                                >
                                    Voir
                                </Link>
                            </td>
                        </tr>

                        <tr v-if="patients.data.length === 0">
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-slate-400">
                                Aucun patient trouvé.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="patients.last_page > 1" class="flex flex-wrap items-center justify-center gap-1 p-5">
                <template v-for="(link, index) in patients.links" :key="index">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-state
                        :class="[
                            'rounded px-3 py-1.5 text-sm',
                            link.active
                                ? 'bg-primary-600 text-white'
                                : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900',
                        ]"
                        v-html="link.label"
                    />
                    <span
                        v-else
                        class="rounded px-3 py-1.5 text-sm text-slate-300"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>
    </div>
</template>
