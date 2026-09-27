<script setup>
import { hrSiteName, hrUrl } from '@/utilities/hrUrl';
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({
    contract: Object,
    templates: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
});
const page = usePage();
const { can } = usePermissions();
const showSheet = ref(false);

const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(`${value}T00:00:00`))
    : 'Non renseignée';
const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '';

// A canevas whose name or type mentions the contract type (CDD, CDI…) is
// proposed first; the choice itself always stays with the RH.
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
const orderedTemplates = computed(() => {
    const type = normalize(props.contract.contract_type);
    const matches = (template) => type !== '' && normalize(`${template.name} ${template.document_type}`).includes(type);

    return [...props.templates]
        .map((template) => ({ ...template, recommended: matches(template) }))
        .sort((a, b) => Number(b.recommended) - Number(a.recommended));
});

const generateHref = (template) => hrUrl(`/administration/generated-documents/create?${new URLSearchParams({
    template: template.uuid,
    employee: props.contract.employee.uuid,
    contract: props.contract.uuid,
})}`);

const printSheet = () => {
    showSheet.value = true;
    setTimeout(() => window.print(), 50);
};
</script>

<template>
    <Head title="Imprimer le contrat" />
    <div class="mx-auto max-w-4xl space-y-4">
        <div class="print-actions flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <Button :as="Link" :href="hrUrl('/administration/contracts')" size="rg" variant="white-outline"><Icon name="arrow-left" /><span class="ms-2">Retour aux contrats</span></Button>
            <Button size="rg" variant="white-outline" @click="showSheet = !showSheet"><Icon name="file-text" /><span class="ms-2">{{ showSheet ? 'Masquer la fiche résumé' : 'Voir la fiche résumé' }}</span></Button>
        </div>

        <section class="print-actions rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <h1 class="font-heading text-lg font-bold text-slate-800 dark:text-white">Imprimer le contrat de {{ contract.employee.name }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ contract.contract_type }} · du {{ formatDate(contract.starts_on) }} au {{ contract.ends_on ? formatDate(contract.ends_on) : 'sans date de fin' }}. Choisissez le canevas à utiliser : les informations de l’employé et du contrat sont remplies automatiquement.</p>

            <div v-if="orderedTemplates.length" class="mt-4 grid gap-2 sm:grid-cols-2">
                <Link
                    v-for="template in orderedTemplates"
                    :key="template.uuid"
                    :href="generateHref(template)"
                    :class="['group flex items-center gap-3 rounded-xl border p-3 transition hover:shadow-sm', template.recommended ? 'border-primary-300 bg-primary-50/50 dark:border-primary-800 dark:bg-primary-950/20' : 'border-gray-200 hover:border-primary-300 dark:border-gray-800']"
                >
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-xl text-sky-600 dark:bg-sky-950/40 dark:text-sky-300"><Icon name="file-docs" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-slate-800 dark:text-white">{{ template.name }}</span>
                        <span class="block text-xs text-slate-400">{{ template.document_type }}<template v-if="template.recommended"> · <strong class="text-primary-600">Correspond au type {{ contract.contract_type }}</strong></template></span>
                    </span>
                    <Icon name="printer" class="text-lg text-slate-400 group-hover:text-primary-600" />
                </Link>
            </div>
            <p v-else-if="can('generated_documents.create') && !contract.archived" class="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300"><Icon name="alert-circle" class="mt-0.5" />Aucun canevas de contrat n’est disponible sur ce site. Le Super Admin doit publier un canevas « Employé et contrat ». En attendant, vous pouvez imprimer la fiche résumé.</p>
            <p v-else-if="contract.archived" class="mt-4 text-sm text-slate-500">Ce contrat est archivé : seule sa fiche résumé s’imprime.</p>
            <p v-else class="mt-4 text-sm text-slate-500">Votre compte n’a pas le droit de générer un document. Seule la fiche résumé s’imprime.</p>

            <div v-if="documents.length" class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-900">
                <h2 class="text-xs font-bold uppercase tracking-wide text-slate-400">Déjà générés pour ce contrat</h2>
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-900">
                    <li v-for="document in documents" :key="document.uuid" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0"><span class="block truncate font-medium text-slate-700 dark:text-slate-200">{{ document.template_name }}</span><span class="text-xs text-slate-400">{{ formatDateTime(document.created_at) }}</span></span>
                        <Button v-if="can('generated_documents.print')" :as="Link" :href="hrUrl(`/administration/generated-documents/${document.uuid}/print`)" size="sm" variant="white-outline"><Icon name="printer" /><span class="ms-1.5">Réimprimer</span></Button>
                    </li>
                </ul>
            </div>
        </section>

        <template v-if="showSheet">
            <div class="print-actions flex justify-end"><Button size="rg" variant="white-outline" @click="printSheet"><Icon name="printer" /><span class="ms-2">Imprimer la fiche résumé</span></Button></div>
            <article class="print-document bg-white p-8 text-slate-900">
                <header class="flex justify-between border-b-2 border-slate-900 pb-4"><div><strong class="text-lg uppercase">{{ page.props.site?.brand || 'Clinique Saint Georges' }}</strong><p class="mt-1 text-xs">Site {{ hrSiteName() }}</p></div><div class="text-end"><h1 class="text-xl font-black uppercase">Fiche de contrat</h1><p class="font-mono text-xs">{{ contract.uuid }}</p></div></header>
                <div class="mt-8 grid grid-cols-2 gap-5 text-sm"><p><strong>Employé :</strong><br>{{ contract.employee.name }}</p><p><strong>Matricule :</strong><br>{{ contract.employee.employee_number }}</p><p><strong>Fonction :</strong><br>{{ contract.employee.job_title || 'Non renseignée' }}</p><p><strong>Service :</strong><br>{{ contract.employee.department || 'Non renseigné' }}</p><p><strong>Type :</strong><br>{{ contract.contract_type }}</p><p><strong>Référence :</strong><br>{{ contract.reference_number || 'Non renseignée' }}</p><p><strong>Signature :</strong><br>{{ formatDate(contract.signed_on) }}</p><p><strong>Début :</strong><br>{{ formatDate(contract.starts_on) }}</p><p><strong>Fin d’essai :</strong><br>{{ formatDate(contract.trial_ends_on) }}</p><p><strong>Fin :</strong><br>{{ formatDate(contract.ends_on) }}</p><p class="col-span-2"><strong>Observation :</strong><br>{{ contract.observation || 'Aucune' }}</p></div>
                <footer class="mt-16 grid grid-cols-2 gap-16 text-center text-xs"><p class="border-t border-slate-500 pt-2">Signature du personnel</p><p class="border-t border-slate-500 pt-2">Administration / RH</p></footer>
            </article>
        </template>
    </div>
</template>

<style>@media print {.print-actions,aside,nav{display:none!important}.print-document{padding:0!important}}</style>
