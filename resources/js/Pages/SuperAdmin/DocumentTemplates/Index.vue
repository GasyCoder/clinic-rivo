<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { Archive, Copy, Eye, EyeOff, FileText, Pencil, Plus, RotateCcw, Server, X } from 'lucide-vue-next';
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
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground dark:text-muted-foreground"><FileText class="h-5 w-5" /></span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-foreground">Canevas de documents</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Contrats, congés, attestations, certificats, lettres, décisions… composés ici et poussés sur le site choisi.</p>
                </div>
            </div>
            <Button v-if="can('document_templates.create')" :as="Link" :href="`/super-admin/workspaces/document-templates/${selectedSiteCode}/create`" size="rg" :disabled="!selectedSite?.ok">
                <Plus class="h-4.5 w-4.5" />Nouveau canevas
            </Button>
        </header>

        <section class="overflow-hidden rounded-lg border border-border bg-card">
            <div class="flex gap-1 overflow-x-auto border-b border-border bg-muted/70 p-2 /40">
                <button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex min-w-40 items-center justify-center gap-2 rounded px-4 py-2.5 text-sm font-bold transition', selectedSiteCode === site.site.code ? 'bg-card text-foreground shadow-sm ' : 'text-muted-foreground hover:text-foreground']" @click="selectSite(site.site.code)">
                    <span :class="['h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'UNCONFIGURED' ? 'bg-muted-foreground/40' : 'bg-red-500']" />
                    {{ site.site.name }}
                </button>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                <span class="flex h-11 w-11 items-center justify-center rounded bg-muted text-muted-foreground"><Server class="h-5 w-5" /></span>
                <h2 class="mt-3 text-sm font-bold text-foreground">API indisponible pour {{ selectedSite?.site.name }}</h2>
                <p class="mt-1 max-w-xl text-xs leading-5 text-muted-foreground">{{ selectedSite?.message }}</p>
                <p class="mt-3 text-xs text-muted-foreground">Les autres sites restent utilisables et aucune base clinique n’est accédée directement.</p>
            </div>

            <template v-else>
                <div class="flex flex-col gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="search" type="search" placeholder="Rechercher un canevas…" class="h-9 w-56 rounded border border-border bg-card px-3 text-sm">
                        <select v-model="typeFilter" class="h-9 rounded border border-border bg-card px-2 text-sm">
                            <option value="">Tous les types</option>
                            <option v-for="type in documentTypes" :key="type" :value="type">{{ type }}</option>
                        </select>
                        <select v-model="statusFilter" class="h-9 rounded border border-border bg-card px-2 text-sm">
                            <option value="ALL">Tous statuts ({{ summary.active + summary.archived }})</option>
                            <option value="ACTIVE">Actifs ({{ summary.active }})</option>
                            <option value="ARCHIVED">Archivés ({{ summary.archived }})</option>
                        </select>
                    </div>
                    <p class="text-xs text-muted-foreground">Site {{ selectedSite.site.name }} · données API</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-border text-[10px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-5 py-3 text-start">Canevas</th>
                                <th class="px-4 py-3 text-start">Type</th>
                                <th class="px-4 py-3 text-start">Contexte de données</th>
                                <th class="px-4 py-3 text-start">Statut</th>
                                <th class="px-4 py-3 text-end">Documents générés</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="template in templates" :key="template.uuid" class="hover:bg-muted/60">
                                <td class="px-5 py-3">
                                    <p class="text-sm font-bold text-foreground">{{ template.name }}</p>
                                    <p v-if="template.description" class="mt-0.5 max-w-xs truncate text-xs text-muted-foreground">{{ template.description }}</p>
                                    <p v-if="template.archived" class="mt-0.5 text-xs text-red-500">{{ template.archive_reason }}</p>
                                </td>
                                <td class="px-4 py-3"><code class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-bold text-muted-foreground">{{ template.document_type }}</code></td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">{{ contextLabel(template.data_context) }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="template.archived" class="rounded px-2 py-1 text-[10px] font-bold uppercase text-red-600">Archivé</span>
                                    <span v-else :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', template.active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-muted text-muted-foreground ']">{{ template.active ? 'Actif' : 'Inactif' }}</span>
                                </td>
                                <td class="px-4 py-3 text-end font-bold text-foreground">{{ template.generated_documents_count }}</td>
                                <td class="px-5 py-3 text-end">
                                    <div class="inline-flex gap-1">
                                        <Link v-if="!template.archived && can('document_templates.update')" :href="`/super-admin/workspaces/document-templates/${selectedSiteCode}/${template.uuid}/edit`" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Modifier"><Pencil class="h-4 w-4" /></Link>
                                        <button v-if="can('document_templates.duplicate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Dupliquer" @click="duplicateTemplate(template)"><Copy class="h-4 w-4" /></button>
                                        <button v-if="!template.archived && can('document_templates.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" :title="template.active ? 'Désactiver' : 'Activer'" @click="toggleActive(template)"><component :is="template.active ? EyeOff : Eye" /></button>
                                        <button v-if="!template.archived && can('document_templates.archive')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-red-200 text-red-500 hover:bg-red-50 dark:border-red-900" title="Archiver" @click="openArchive(template)"><Archive class="h-4 w-4" /></button>
                                        <button v-if="template.archived && can('document_templates.restore')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Restaurer" @click="restoreTemplate(template)"><RotateCcw class="h-4 w-4" /></button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!templates.length"><td colspan="6" class="px-5 py-12 text-center text-xs text-muted-foreground">Aucun canevas ne correspond à ces filtres.</td></tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <div v-if="archiving" class="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4" @click.self="closeArchive">
            <div class="w-full max-w-md rounded-lg bg-card shadow-xl">
                <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                    <div><h2 class="text-lg font-bold text-foreground">Archiver le canevas</h2><p class="mt-1 text-xs text-muted-foreground">« {{ archiving.name }} » — les documents déjà générés restent inchangés.</p></div>
                    <button type="button" class="text-muted-foreground hover:text-foreground" @click="closeArchive"><X class="h-5 w-5" /></button>
                </header>
                <form class="space-y-4 p-5" @submit.prevent="confirmArchive">
                    <div><label class="mb-1.5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></label><textarea v-model="archiveForm.reason" required rows="3" class="block w-full rounded border border-border bg-card px-3 py-2 text-sm" /><p v-if="archiveForm.errors.reason" class="mt-1 text-xs text-red-600">{{ archiveForm.errors.reason }}</p></div>
                    <div class="flex justify-end gap-2"><Button type="button" variant="white-outline" @click="closeArchive">Annuler</Button><Button type="submit" variant="danger" :disabled="archiveForm.processing">Archiver</Button></div>
                </form>
            </div>
        </div>
    </div>
</template>
