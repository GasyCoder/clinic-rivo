<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive, ArrowLeft, ChevronRight, Copy, Eye, FilePlus2, FileText, FolderOpen, History, Pencil, RotateCcw, Search, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FolderCard from '@/Components/UI/FolderCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { familyTone, folderDocuments, folderSummary } from '@/utilities/documentFamilies';
import { hrUrl } from '@/utilities/hrUrl';

defineOptions({ layout: AppLayout });

/**
 * ADR-199 — les documents du personnel, rangés en dossiers comme les
 * fournisseurs : un dossier par type (Contrats, Congés, Attestations…). Un
 * dossier montre ses canevas (pour générer) et ses documents. Un document
 * reste figé : « Modifier » en produit une nouvelle version, « Archiver » le
 * range avec un motif ; rien n'est jamais effacé.
 */
const props = defineProps({
    folders: { type: Array, default: () => [] },
    folder: { type: Object, default: null },
    templates: { type: Array, default: () => [] },
    documents: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
});
const { can } = usePermissions();

const query = ref(props.filters.q ?? '');
const archivedView = computed(() => props.filters.statut === 'archives');
const visit = (params) => router.get(hrUrl('/administration/generated-documents'), params, { preserveState: true, preserveScroll: true, replace: true });
const search = () => visit({ q: query.value || undefined, dossier: props.folder?.key, statut: archivedView.value ? 'archives' : undefined });
let pause = null;
watch(query, () => { window.clearTimeout(pause); pause = window.setTimeout(search, 350); });
onBeforeUnmount(() => window.clearTimeout(pause));
const clearSearch = () => { query.value = ''; window.clearTimeout(pause); search(); };

const folderHref = (key, params = {}) => hrUrl(`/administration/generated-documents?${new URLSearchParams({ dossier: key, ...params })}`);
const currentFolder = computed(() => props.folders.find((item) => item.key === props.folder?.key) ?? null);
const generateHref = (template = null) => hrUrl(`/administration/generated-documents/create?${new URLSearchParams({
    ...(template ? { template: template.uuid } : {}),
    ...(props.folder ? { dossier: props.folder.key } : {}),
})}`);

const canModify = computed(() => can('generated_documents.create') && can('generated_documents.archive'));
const formatDate = (value) => (value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—');
const printHref = (document) => hrUrl(`/administration/generated-documents/${document.uuid}/print`);
const modifyHref = (document) => hrUrl(`/administration/generated-documents/create?from=${document.uuid}`);

// Archiver : un motif, jamais d'effacement.
const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (document) => { archiveForm.reset(); archiveForm.clearErrors(); archiving.value = document; };
const archive = () => archiveForm.delete(hrUrl(`/administration/generated-documents/${archiving.value.uuid}`), {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; },
});
const restoreErrors = ref('');
const restore = (document) => router.post(hrUrl(`/administration/generated-documents/${document.uuid}/restore`), {}, {
    preserveScroll: true,
    onError: (errors) => { restoreErrors.value = Object.values(errors)[0] ?? ''; },
    onSuccess: () => { restoreErrors.value = ''; },
});

const showList = computed(() => Boolean(props.documents));
</script>

<template>
    <Head :title="folder ? `Documents · ${folder.label}` : 'Documents'" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines"
            :title="folder ? folder.label : 'Documents'"
            :description="folder
                ? `Les canevas « ${folder.label} » publiés par le Super Administrateur, et les documents produits avec eux. Un document reste figé : le modifier en crée une nouvelle version, l’ancienne est archivée.`
                : 'Les documents du personnel, rangés par dossier : contrats, congés, attestations… Chaque dossier réunit ses canevas et les documents produits.'"
            :icon="folder ? FolderOpen : Copy"
            tone="primary"
        >
            <template #actions>
                <Button v-if="folder" :as="Link" :href="hrUrl('/administration/generated-documents')" variant="outline"><ArrowLeft class="h-4 w-4" />Tous les dossiers</Button>
                <Button v-if="can('generated_documents.create')" :as="Link" :href="generateHref()"><FilePlus2 class="h-4 w-4" />Générer un document</Button>
            </template>
        </PageHeader>

        <!-- Fil d'Ariane et recherche -->
        <div class="flex flex-wrap items-center gap-3">
            <nav class="flex items-center gap-1.5 text-sm text-muted-foreground" aria-label="Fil d’Ariane">
                <Link :href="hrUrl('/administration/generated-documents')" class="font-medium hover:text-foreground">Documents</Link>
                <template v-if="folder"><ChevronRight class="h-3.5 w-3.5" /><span class="font-semibold text-foreground">{{ folder.label }}</span></template>
            </nav>
            <form class="ms-auto w-full sm:w-80" role="search" @submit.prevent="search">
                <label class="relative block">
                    <span class="sr-only">Rechercher un document</span>
                    <IconInput v-model="query" :icon="Search" type="search" class="pe-9" :placeholder="folder ? `Chercher dans ${folder.label.toLowerCase()}…` : 'Personne, canevas, type…'" />
                    <button v-if="query" type="button" class="absolute inset-y-0 end-2 my-auto grid h-6 w-6 place-items-center rounded-md text-muted-foreground hover:bg-accent" aria-label="Effacer la recherche" @click="clearSearch"><X class="h-3.5 w-3.5" /></button>
                </label>
            </form>
        </div>

        <!-- Racine : les dossiers -->
        <Card v-if="! folder && ! showList" class="p-3">
            <div class="grid grid-cols-2 gap-1 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-7">
                <FolderCard
                    v-for="item in folders"
                    :key="item.key"
                    :href="folderHref(item.key)"
                    :title="item.label"
                    :subtitle="folderSummary(item)"
                    :meta="folderDocuments(item)"
                    :count="item.documents || null"
                    icon="folder-fill"
                    :tone="familyTone(item.key)"
                    :muted="! item.templates && ! item.documents && ! item.archived"
                />
            </div>
        </Card>

        <!-- Un dossier : ses canevas -->
        <Card v-if="folder" class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-heading text-base font-bold text-foreground">Canevas de ce dossier</h2>
                    <p class="text-sm text-muted-foreground">Composés au portail par le Super Administrateur. Choisissez-en un pour produire un document — la page 1 reprend la personne{{ folder.key === 'CONTRAT' ? ' et son contrat' : folder.key === 'CONGE' ? ' et son congé' : '' }}.</p>
                </div>
            </div>
            <div v-if="templates.length" class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="template in templates" :key="template.uuid" class="flex items-center gap-3 rounded-xl border border-border p-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><FileText class="h-4 w-4" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-foreground">{{ template.name }}</span>
                        <span class="block truncate text-xs text-muted-foreground">{{ template.data_context_label }}</span>
                    </span>
                    <Button v-if="can('generated_documents.create')" :as="Link" :href="generateHref(template)" size="sm" variant="outline"><FilePlus2 class="h-4 w-4" />Générer</Button>
                </div>
            </div>
            <p v-else class="mt-4 rounded-lg border border-dashed border-border px-4 py-5 text-center text-sm text-muted-foreground">
                Aucun canevas actif dans ce dossier. Le Super Administrateur le compose au portail (« Canevas de documents », dossier {{ folder.label }}).
            </p>
        </Card>

        <!-- Les documents (d'un dossier, ou trouvés par la recherche) -->
        <Card v-if="showList" class="overflow-hidden">
            <div v-if="folder" class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                <nav class="flex gap-1 rounded-lg bg-muted p-1" aria-label="Documents actifs ou archivés">
                    <Link :href="folderHref(folder.key, filters.q ? { q: filters.q } : {})" :aria-current="! archivedView ? 'page' : undefined" :class="cn('rounded-md px-3 py-1.5 text-sm font-semibold transition', ! archivedView ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')">Actifs · {{ currentFolder?.documents ?? 0 }}</Link>
                    <Link :href="folderHref(folder.key, { statut: 'archives', ...(filters.q ? { q: filters.q } : {}) })" :aria-current="archivedView ? 'page' : undefined" :class="cn('rounded-md px-3 py-1.5 text-sm font-semibold transition', archivedView ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')">Archivés · {{ currentFolder?.archived ?? 0 }}</Link>
                </nav>
            </div>
            <p v-if="restoreErrors" class="border-b border-border bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-200" role="alert">{{ restoreErrors }}</p>

            <div v-if="documents.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-sm">
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">
                            <th scope="col" class="px-5 py-3">Document</th>
                            <th scope="col" class="px-4 py-3">Personne</th>
                            <th scope="col" class="px-4 py-3">Repris de</th>
                            <th scope="col" class="px-4 py-3">Généré</th>
                            <th scope="col" class="px-5 py-3 text-end"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="document in documents.data" :key="document.uuid" :class="cn('transition-colors hover:bg-muted/40', document.archived && 'text-muted-foreground')">
                            <td class="px-5 py-3">
                                <p class="flex flex-wrap items-center gap-1.5 font-semibold text-foreground">
                                    {{ document.template_name }}
                                    <Badge v-if="! folder" variant="secondary" class="px-1.5 py-0 text-[10px] uppercase">{{ document.document_type }}</Badge>
                                    <Badge v-if="document.replaces && ! document.archived" variant="secondary" class="px-1.5 py-0 text-[10px]"><History class="h-3 w-3" />Nouvelle version</Badge>
                                </p>
                                <p v-if="document.archived" class="mt-0.5 text-xs">
                                    Archivé le {{ formatDate(document.archived_at) }}<template v-if="document.archived_by"> par {{ document.archived_by }}</template> · {{ document.archive_reason }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="flex items-center gap-2.5">
                                    <EmployeePhoto :src="document.employee.photo_url" :name="document.employee.name" size="sm" />
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-foreground">{{ document.employee.name }}</span>
                                        <span class="block font-mono text-xs text-muted-foreground">{{ document.employee.employee_number }}</span>
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">{{ document.source?.label ?? 'Le dossier de la personne' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-muted-foreground">{{ formatDate(document.created_at) }}<template v-if="document.generated_by"><br>{{ document.generated_by }}</template></td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-1">
                                    <Button v-if="can('generated_documents.print')" :as="Link" :href="printHref(document)" variant="ghost" size="icon" :title="document.archived ? 'Voir' : 'Voir et imprimer'" :aria-label="`Voir ${document.template_name} de ${document.employee.name}`"><Eye class="h-4 w-4" /></Button>
                                    <template v-if="! document.archived">
                                        <Button v-if="canModify" :as="Link" :href="modifyHref(document)" variant="ghost" size="icon" title="Modifier (nouvelle version)" :aria-label="`Modifier ${document.template_name} de ${document.employee.name}`"><Pencil class="h-4 w-4" /></Button>
                                        <Button v-if="can('generated_documents.archive')" type="button" variant="ghost" size="icon" title="Archiver" :aria-label="`Archiver ${document.template_name} de ${document.employee.name}`" @click="openArchive(document)"><Archive class="h-4 w-4" /></Button>
                                    </template>
                                    <Button v-else-if="can('generated_documents.restore') && ! document.replaced_by" type="button" variant="ghost" size="icon" title="Restaurer" :aria-label="`Restaurer ${document.template_name} de ${document.employee.name}`" @click="restore(document)"><RotateCcw class="h-4 w-4" /></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="flex flex-col items-center gap-2 px-4 py-10 text-center text-sm text-muted-foreground">
                <FileText class="h-8 w-8 text-muted-foreground/60" aria-hidden="true" />
                {{ filters.q ? 'Aucun document ne correspond à la recherche.' : archivedView ? 'Aucun document archivé dans ce dossier.' : 'Aucun document produit dans ce dossier pour l’instant.' }}
            </div>
            <HrPagination :paginator="documents" />
        </Card>

        <Dialog
            v-if="archiving"
            :open="Boolean(archiving)"
            title="Archiver ce document ?"
            :description="`${archiving.template_name} · ${archiving.employee.name}. Il reste consultable dans « Archivés » et peut être restauré ; il n’est jamais effacé.`"
            :dismissible="false"
            @update:open="(value) => { if (! value) archiving = null; }"
        >
            <FormField label="Motif" required :error="archiveForm.errors.reason">
                <Textarea v-model="archiveForm.reason" rows="3" maxlength="1000" placeholder="Ex. document remplacé, erreur de saisie…" />
            </FormField>
            <template #footer>
                <Button variant="outline" @click="archiving = null">Retour</Button>
                <Button variant="danger" :disabled="archiveForm.processing || archiveForm.reason.trim().length < 3" @click="archive"><Archive class="h-4 w-4" />Archiver</Button>
            </template>
        </Dialog>
    </div>
</template>
