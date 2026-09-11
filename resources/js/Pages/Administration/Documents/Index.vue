<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrEmptyState from '../Partials/HrEmptyState.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ documents: Object, filters: Object });
const { can } = usePermissions();
const search = ref(props.filters?.q ?? '');
const submitSearch = () => {
    router.get('/administration/generated-documents', { q: search.value || undefined }, { preserveState: true, replace: true });
};
const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—';
</script>

<template>
    <Head title="Documents générés" />
    <div class="space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Contrats, attestations, certificats, lettres…" title="Documents générés" description="Chaque document reste figé tel qu’il a été produit : modifier l’employé ou le canevas ensuite ne change jamais un document déjà généré." icon="copy" tone="primary">
            <template #actions><Button v-if="can('generated_documents.create')" :as="Link" href="/administration/generated-documents/create" size="rg"><Icon name="plus" /><span class="ms-2">Générer un document</span></Button></template>
        </HrPageHeader>

        <form class="flex gap-2" @submit.prevent="submitSearch">
            <input v-model="search" type="search" placeholder="Rechercher par employé, type ou canevas…" class="h-10 w-full max-w-md rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <Button type="submit" size="rg" variant="white-outline">Rechercher</Button>
        </form>

        <section v-if="documents.data.length" class="space-y-3">
            <article v-for="document in documents.data" :key="document.uuid" class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-bold text-slate-800 dark:text-white">{{ document.template_name }}</h2>
                        <code class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600 dark:bg-gray-900 dark:text-slate-300">{{ document.document_type }}</code>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ document.employee.name }} ({{ document.employee.employee_number }})</p>
                    <p class="mt-1 text-xs text-slate-400">Généré le {{ formatDate(document.created_at) }}<span v-if="document.generated_by"> · {{ document.generated_by }}</span></p>
                </div>
                <Button v-if="can('generated_documents.print')" :as="Link" :href="`/administration/generated-documents/${document.uuid}/print`" size="rg" variant="white-outline"><Icon name="printer" /><span class="ms-2">Ouvrir</span></Button>
            </article>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950"><HrPagination :paginator="documents" /></div>
        </section>
        <section v-else class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950"><HrEmptyState icon="copy" title="Aucun document généré" description="Générez un document à partir d’un canevas actif." /></section>
    </div>
</template>
