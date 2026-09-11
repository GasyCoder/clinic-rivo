<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    selectedSiteCode: { type: String, default: null },
    dataContexts: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const firstOnlineSite = props.sites.find((site) => site.ok)?.site.code;
const requestedSite = props.sites.find((site) => site.site.code === props.selectedSiteCode)?.site.code;
const selectedSiteCode = ref(requestedSite ?? firstOnlineSite ?? props.sites[0]?.site.code);
const search = ref('');
const statusFilter = ref('ALL');
const typeFilter = ref('');
const archiving = ref(null);

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const siteData = computed(() => selectedSite.value?.data ?? {});
const summary = computed(() => siteData.value.summary ?? { active: 0, archived: 0 });
const documentTypes = computed(() => siteData.value.document_types ?? []);
const contextLabel = (value) => props.dataContexts.find((context) => context.value === value)?.label ?? value;
const templates = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return (siteData.value.templates ?? []).filter((template) => {
        const matchesSearch = !needle || [template.name, template.document_type]
            .filter(Boolean)
            .some((value) => value.toLocaleLowerCase().includes(needle));
        const matchesStatus = statusFilter.value === 'ALL'
            || (statusFilter.value === 'ACTIVE' && !template.archived)
            || (statusFilter.value === 'ARCHIVED' && template.archived);
        const matchesType = !typeFilter.value || template.document_type === typeFilter.value;

        return matchesSearch && matchesStatus && matchesType;
    });
});

const archiveForm = useForm({ reason: '' });

const selectSite = (code) => {
    selectedSiteCode.value = code;
    search.value = '';
    statusFilter.value = 'ALL';
    typeFilter.value = '';

    const url = new URL(window.location.href);
    url.searchParams.set('site', code);
    window.history.replaceState(window.history.state, '', url);
};

const openArchive = (template) => {
    archiving.value = template;
    archiveForm.reset();
    archiveForm.clearErrors();
};
const closeArchive = () => { archiving.value = null; };
const confirmArchive = () => {
    archiveForm.delete(`/super-admin/workspaces/document-templates/${selectedSiteCode.value}/${archiving.value.uuid}`, {
        preserveScroll: true,
        onSuccess: closeArchive,
    });
};
const restoreTemplate = (template) => {
    router.post(`/super-admin/workspaces/document-templates/${selectedSiteCode.value}/${template.uuid}/restore`, {}, { preserveScroll: true });
};
const duplicateTemplate = (template) => {
    router.post(`/super-admin/workspaces/document-templates/${selectedSiteCode.value}/${template.uuid}/duplicate`, {}, { preserveScroll: true });
};
const toggleActive = (template) => {
    router.post(
        `/super-admin/workspaces/document-templates/${selectedSiteCode.value}/${template.uuid}/${template.active ? 'deactivate' : 'activate'}`,
        {},
        { preserveScroll: true },
    );
};
const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—';
</script>

<template>
    <Head title="Canevas de documents" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-xl" name="file-text" /></span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Canevas de documents</h1>
                    <p class="mt-1 text-sm text-slate-500">Contrats, congés, attestations, certificats, lettres, décisions… composés ici et poussés sur le site choisi.</p>
                </div>
            </div>
            <Button v-if="can('document_templates.create')" :as="Link" :href="`/super-admin/workspaces/document-templates/${selectedSiteCode}/create`" size="rg" :disabled="!selectedSite?.ok">
                <Icon class="text-lg" name="plus" /><span class="ms-2">Nouveau canevas</span>
            </Button>
        </header>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex gap-1 overflow-x-auto border-b border-gray-200 bg-gray-50/70 p-2 dark:border-gray-900 dark:bg-gray-1000/40">
                <button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex min-w-40 items-center justify-center gap-2 rounded px-4 py-2.5 text-sm font-bold transition', selectedSiteCode === site.site.code ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-white']" @click="selectSite(site.site.code)">
                    <span :class="['h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'UNCONFIGURED' ? 'bg-slate-300' : 'bg-red-500']" />
                    {{ site.site.name }}
                </button>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                <span class="flex h-11 w-11 items-center justify-center rounded bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="server" /></span>
                <h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">API indisponible pour {{ selectedSite?.site.name }}</h2>
                <p class="mt-1 max-w-xl text-xs leading-5 text-slate-500">{{ selectedSite?.message }}</p>
                <p class="mt-3 text-xs text-slate-400">Les autres sites restent utilisables et aucune base clinique n’est accédée directement.</p>
            </div>

            <template v-else>
                <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="search" type="search" placeholder="Rechercher un canevas…" class="h-9 w-56 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                        <select v-model="typeFilter" class="h-9 rounded border border-gray-200 bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                            <option value="">Tous les types</option>
                            <option v-for="type in documentTypes" :key="type" :value="type">{{ type }}</option>
                        </select>
                        <select v-model="statusFilter" class="h-9 rounded border border-gray-200 bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                            <option value="ALL">Tous statuts ({{ summary.active + summary.archived }})</option>
                            <option value="ACTIVE">Actifs ({{ summary.active }})</option>
                            <option value="ARCHIVED">Archivés ({{ summary.archived }})</option>
                        </select>
                    </div>
                    <p class="text-xs text-slate-400">Site {{ selectedSite.site.name }} · données API</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-[10px] uppercase tracking-wide text-slate-400 dark:border-gray-900">
                            <tr>
                                <th class="px-5 py-3 text-start">Canevas</th>
                                <th class="px-4 py-3 text-start">Type</th>
                                <th class="px-4 py-3 text-start">Contexte de données</th>
                                <th class="px-4 py-3 text-start">Statut</th>
                                <th class="px-4 py-3 text-end">Documents générés</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                            <tr v-for="template in templates" :key="template.uuid" class="hover:bg-gray-50/60 dark:hover:bg-gray-1000/30">
                                <td class="px-5 py-3">
                                    <p class="text-sm font-bold text-slate-700 dark:text-white">{{ template.name }}</p>
                                    <p v-if="template.description" class="mt-0.5 max-w-xs truncate text-xs text-slate-400">{{ template.description }}</p>
                                    <p v-if="template.archived" class="mt-0.5 text-xs text-red-500">{{ template.archive_reason }}</p>
                                </td>
                                <td class="px-4 py-3"><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600 dark:bg-gray-900 dark:text-slate-300">{{ template.document_type }}</code></td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ contextLabel(template.data_context) }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="template.archived" class="rounded px-2 py-1 text-[10px] font-bold uppercase text-red-600">Archivé</span>
                                    <span v-else :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', template.active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ template.active ? 'Actif' : 'Inactif' }}</span>
                                </td>
                                <td class="px-4 py-3 text-end font-bold text-slate-700 dark:text-white">{{ template.generated_documents_count }}</td>
                                <td class="px-5 py-3 text-end">
                                    <div class="inline-flex gap-1">
                                        <Link v-if="!template.archived && can('document_templates.update')" :href="`/super-admin/workspaces/document-templates/${selectedSiteCode}/${template.uuid}/edit`" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-primary-600 dark:border-gray-800" title="Modifier"><Icon name="edit" /></Link>
                                        <button v-if="can('document_templates.duplicate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-primary-600 dark:border-gray-800" title="Dupliquer" @click="duplicateTemplate(template)"><Icon name="copy" /></button>
                                        <button v-if="!template.archived && can('document_templates.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-primary-600 dark:border-gray-800" :title="template.active ? 'Désactiver' : 'Activer'" @click="toggleActive(template)"><Icon :name="template.active ? 'eye-off' : 'eye'" /></button>
                                        <button v-if="!template.archived && can('document_templates.archive')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-red-200 text-red-500 hover:bg-red-50 dark:border-red-900" title="Archiver" @click="openArchive(template)"><Icon name="archive" /></button>
                                        <button v-if="template.archived && can('document_templates.restore')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-primary-600 dark:border-gray-800" title="Restaurer" @click="restoreTemplate(template)"><Icon name="undo" /></button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!templates.length"><td colspan="6" class="px-5 py-12 text-center text-xs text-slate-400">Aucun canevas ne correspond à ces filtres.</td></tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <div v-if="archiving" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="closeArchive">
            <div class="w-full max-w-md rounded-lg bg-white shadow-xl dark:bg-gray-950">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div><h2 class="text-lg font-bold text-slate-700 dark:text-white">Archiver le canevas</h2><p class="mt-1 text-xs text-slate-500">« {{ archiving.name }} » — les documents déjà générés restent inchangés.</p></div>
                    <button type="button" class="text-slate-400 hover:text-slate-700" @click="closeArchive"><Icon class="text-xl" name="cross" /></button>
                </header>
                <form class="space-y-4 p-5" @submit.prevent="confirmArchive">
                    <div><label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label><textarea v-model="archiveForm.reason" required rows="3" class="block w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950" /><p v-if="archiveForm.errors.reason" class="mt-1 text-xs text-red-600">{{ archiveForm.errors.reason }}</p></div>
                    <div class="flex justify-end gap-2"><Button type="button" variant="white-outline" @click="closeArchive">Annuler</Button><Button type="submit" variant="danger" :disabled="archiveForm.processing">Archiver</Button></div>
                </form>
            </div>
        </div>
    </div>
</template>
