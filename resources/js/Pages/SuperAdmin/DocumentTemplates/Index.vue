<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive, ArrowLeft, ChevronRight, Copy, ExternalLink, FilePlus2, FileText, FolderOpen, Pencil, Plus, Power, PowerOff, RotateCcw, Search, Server, TriangleAlert, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FolderCard from '@/Components/UI/FolderCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { familyKey, familyTone } from '@/utilities/documentFamilies';

defineOptions({ layout: AppLayout });

/**
 * ADR-199 — les canevas d'un site, rangés en dossiers comme les fournisseurs :
 * un dossier par type (Contrats, Congés, Attestations…). Les canevas de tous
 * les sites sont déjà là : ouvrir un dossier ou changer de site ne rappelle
 * aucun site, l'adresse suit seulement (`?site=A&dossier=CONTRAT`).
 */
const props = defineProps({
    sites: { type: Array, default: () => [] },
    selectedSiteCode: { type: String, default: null },
    selectedFolder: { type: String, default: null },
    dataContexts: { type: Array, default: () => [] },
    families: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const firstOnlineSite = props.sites.find((site) => site.ok)?.site.code;
const requestedSite = props.sites.find((site) => site.site.code === props.selectedSiteCode)?.site.code;
const selectedSiteCode = ref(requestedSite ?? firstOnlineSite ?? props.sites[0]?.site.code);
const openFolderKey = ref(props.selectedFolder);
const search = ref('');
const showArchived = ref(false);

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const allTemplates = computed(() => selectedSite.value?.data?.templates ?? []);
const contextLabel = (value) => props.dataContexts.find((context) => context.value === value)?.label ?? value;
const familyOf = (key) => props.families.find((family) => family.key === key) ?? null;
const folderLabel = (key) => familyOf(key)?.label ?? (key.charAt(0) + key.slice(1).toLowerCase());

// Les sept dossiers connus, puis ceux qu'un type libre a créés : rien ne disparaît.
const folders = computed(() => {
    const extra = [...new Set(allTemplates.value.map((template) => familyKey(template.document_type)))]
        .filter((key) => ! familyOf(key))
        .sort();

    return [...props.families.map((family) => family.key), ...extra].map((key) => {
        const inFolder = allTemplates.value.filter((template) => familyKey(template.document_type) === key);
        const live = inFolder.filter((template) => ! template.archived);

        return {
            key,
            label: folderLabel(key),
            active: live.filter((template) => template.active).length,
            inactive: live.filter((template) => ! template.active).length,
            archived: inFolder.length - live.length,
            documents: inFolder.reduce((total, template) => total + (template.generated_documents_count ?? 0), 0),
        };
    });
});
const openFolder = computed(() => folders.value.find((folder) => folder.key === openFolderKey.value) ?? null);
const openFamily = computed(() => (openFolder.value ? familyOf(openFolder.value.key) : null));
// Deux lignes courtes par tuile : ce qui est proposé au RH, puis ce qui a été produit.
const folderSubtitle = (folder) => {
    if (folder.active) return `${folder.active} canevas actif${folder.active > 1 ? 's' : ''}`;

    return folder.inactive || folder.archived ? 'Aucun actif' : 'Aucun canevas';
};
const folderMeta = (folder) => (folder.documents ? `${folder.documents} document${folder.documents > 1 ? 's' : ''}` : 'Aucun document');

/**
 * ADR-198 — un canevas de contrat ou de congé réglé sur un autre contexte ne reprend pas les dates et n'est pas
 * proposé à l'impression : on le signale, sans rien bloquer. Renvoie le contexte attendu, sinon null.
 */
const contextMismatch = (template) => {
    const key = familyKey(template.document_type);
    const expected = ['CONTRAT', 'CONGE'].includes(key) ? familyOf(key)?.context : null;

    return expected && template.data_context !== expected ? expected : null;
};

const needle = computed(() => search.value.trim().toLocaleLowerCase());
const matches = (template) => ! needle.value || [template.name, template.document_type, template.description]
    .filter(Boolean)
    .some((value) => value.toLocaleLowerCase().includes(needle.value));

/** Dans un dossier : ses canevas actifs ou archivés ; à la racine, une recherche parcourt tous les dossiers. */
const listed = computed(() => allTemplates.value.filter((template) => {
    if (openFolder.value) {
        return familyKey(template.document_type) === openFolder.value.key && template.archived === showArchived.value && matches(template);
    }

    return needle.value !== '' && matches(template);
}));
const showTable = computed(() => Boolean(openFolder.value) || needle.value !== '');

const syncAddress = () => {
    const url = new URL(window.location.href);
    url.searchParams.set('site', selectedSiteCode.value);
    if (openFolderKey.value) url.searchParams.set('dossier', openFolderKey.value);
    else url.searchParams.delete('dossier');
    window.history.replaceState(window.history.state, '', url);
};
const selectSite = (code) => {
    selectedSiteCode.value = code;
    search.value = '';
    showArchived.value = false;
    syncAddress();
};
const enterFolder = (key) => {
    openFolderKey.value = key;
    search.value = '';
    showArchived.value = false;
    syncAddress();
};
const leaveFolder = () => enterFolder(null);

const base = computed(() => `/super-admin/workspaces/document-templates/${selectedSiteCode.value}`);
const createHref = computed(() => `${base.value}/create${openFolder.value ? `?type=${encodeURIComponent(openFolder.value.key)}` : ''}`);
const editHref = (template) => `${base.value}/${template.uuid}/edit`;
const siteRh = computed(() => `/super-admin/sites/${selectedSiteCode.value}/rh`);
const generateHref = (template) => `${siteRh.value}/generated-documents/create?${new URLSearchParams({ template: template.uuid, dossier: familyKey(template.document_type) })}`;
const documentsHref = computed(() => `${siteRh.value}/generated-documents${openFolder.value ? `?dossier=${encodeURIComponent(openFolder.value.key)}` : ''}`);

// Les écritures partent au site ; la page revient telle quelle (même site, même dossier).
const keep = { preserveScroll: true, preserveState: true };
const duplicateTemplate = (template) => router.post(`${base.value}/${template.uuid}/duplicate`, {}, keep);
const toggleActive = (template) => router.post(`${base.value}/${template.uuid}/${template.active ? 'deactivate' : 'activate'}`, {}, keep);
const restoreTemplate = (template) => router.post(`${base.value}/${template.uuid}/restore`, {}, keep);

const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (template) => { archiveForm.reset(); archiveForm.clearErrors(); archiving.value = template; };
const confirmArchive = () => archiveForm.delete(`${base.value}/${archiving.value.uuid}`, {
    ...keep,
    onSuccess: () => { archiving.value = null; },
});

const formatDate = (value) => (value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' }).format(new Date(value)) : '—');
const statusBadge = (template) => (template.archived
    ? { label: 'Archivé', variant: 'destructive' }
    : template.active ? { label: 'Actif', variant: 'success' } : { label: 'Inactif', variant: 'secondary' });
</script>

<template>
    <Head :title="openFolder ? `Canevas · ${openFolder.label}` : 'Canevas de documents'" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Super Administration"
            :title="openFolder ? openFolder.label : 'Canevas de documents'"
            :description="openFolder
                ? `Les canevas « ${openFolder.label} » de ${selectedSite?.site.name ?? 'ce site'}. Le RH les retrouve dans son dossier « ${openFolder.label} » pour produire les documents du personnel.`
                : 'Contrats, congés, attestations… un dossier par type, comme les fournisseurs. Chaque canevas est composé ici et poussé sur le site choisi.'"
            :icon="openFolder ? FolderOpen : FileText"
            tone="primary"
        >
            <template #actions>
                <Button v-if="openFolder" type="button" variant="outline" @click="leaveFolder"><ArrowLeft class="h-4 w-4" />Tous les dossiers</Button>
                <Button v-if="can('document_templates.create')" :as="Link" :href="createHref" :disabled="! selectedSite?.ok"><Plus class="h-4 w-4" />Nouveau canevas</Button>
            </template>
        </PageHeader>

        <!-- Sites -->
        <nav class="flex gap-1 overflow-x-auto rounded-xl border border-border bg-muted/60 p-1" aria-label="Site des canevas">
            <button
                v-for="site in sites"
                :key="site.site.code"
                type="button"
                :aria-current="selectedSiteCode === site.site.code ? 'page' : undefined"
                :class="cn('inline-flex min-w-36 items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition', selectedSiteCode === site.site.code ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                @click="selectSite(site.site.code)"
            >
                <span :class="cn('h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'UNCONFIGURED' ? 'bg-muted-foreground/40' : 'bg-red-500')" aria-hidden="true" />
                {{ site.site.name }}
            </button>
        </nav>

        <Card v-if="! selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
            <span class="grid h-11 w-11 place-items-center rounded-xl bg-muted text-muted-foreground"><Server class="h-5 w-5" /></span>
            <h2 class="mt-3 text-sm font-bold text-foreground">API indisponible pour {{ selectedSite?.site.name }}</h2>
            <p class="mt-1 max-w-xl text-sm text-muted-foreground">{{ selectedSite?.message }}</p>
            <p class="mt-3 text-xs text-muted-foreground">Les autres sites restent utilisables ; aucune base clinique n’est lue directement.</p>
        </Card>

        <template v-else>
            <!-- Fil d'Ariane et recherche -->
            <div class="flex flex-wrap items-center gap-3">
                <nav class="flex items-center gap-1.5 text-sm text-muted-foreground" aria-label="Fil d’Ariane">
                    <button type="button" class="font-medium hover:text-foreground" @click="leaveFolder">{{ selectedSite.site.name }}</button>
                    <template v-if="openFolder"><ChevronRight class="h-3.5 w-3.5" /><span class="font-semibold text-foreground">{{ openFolder.label }}</span></template>
                </nav>
                <label class="relative ms-auto block w-full sm:w-80">
                    <span class="sr-only">Rechercher un canevas</span>
                    <IconInput v-model="search" :icon="Search" type="search" class="pe-9" :placeholder="openFolder ? `Chercher dans ${openFolder.label.toLowerCase()}…` : 'Nom, type, description…'" />
                    <button v-if="search" type="button" class="absolute inset-y-0 end-2 my-auto grid h-6 w-6 place-items-center rounded-md text-muted-foreground hover:bg-accent" aria-label="Effacer la recherche" @click="search = ''"><X class="h-3.5 w-3.5" /></button>
                </label>
            </div>

            <!-- Racine : les dossiers -->
            <Card v-if="! showTable" class="p-3">
                <div class="grid grid-cols-2 gap-1 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-7">
                    <FolderCard
                        v-for="folder in folders"
                        :key="folder.key"
                        :title="folder.label"
                        :subtitle="folderSubtitle(folder)"
                        :meta="folderMeta(folder)"
                        :count="folder.active || null"
                        icon="folder-fill"
                        :tone="familyTone(folder.key)"
                        :muted="! folder.active && ! folder.inactive && ! folder.archived"
                        @open="enterFolder(folder.key)"
                    />
                </div>
            </Card>

            <!-- Un dossier (ou une recherche) : ses canevas -->
            <Card v-else class="overflow-hidden">
                <div v-if="openFolder" class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
                    <nav class="flex gap-1 rounded-lg bg-muted p-1" aria-label="Canevas en service ou archivés">
                        <button type="button" :aria-current="! showArchived ? 'page' : undefined" :class="cn('rounded-md px-3 py-1.5 text-sm font-semibold transition', ! showArchived ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')" @click="showArchived = false">En service · {{ openFolder.active + openFolder.inactive }}</button>
                        <button type="button" :aria-current="showArchived ? 'page' : undefined" :class="cn('rounded-md px-3 py-1.5 text-sm font-semibold transition', showArchived ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')" @click="showArchived = true">Archivés · {{ openFolder.archived }}</button>
                    </nav>
                    <p v-if="openFamily" class="text-xs text-muted-foreground">Contexte attendu : <span class="font-semibold text-foreground">{{ contextLabel(openFamily.context) }}</span></p>
                    <Button v-if="can('generated_documents.view')" :as="Link" :href="documentsHref" variant="outline" size="sm" class="ms-auto">
                        <ExternalLink class="h-4 w-4" />Documents produits sur {{ selectedSite.site.name }}
                    </Button>
                </div>

                <div v-if="listed.length" class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">
                                <th scope="col" class="px-5 py-3">Canevas</th>
                                <th v-if="! openFolder" scope="col" class="px-4 py-3">Dossier</th>
                                <th scope="col" class="px-4 py-3">Contexte de données</th>
                                <th scope="col" class="px-4 py-3">Statut</th>
                                <th scope="col" class="px-4 py-3 text-end">Documents</th>
                                <th scope="col" class="px-4 py-3">Mis à jour</th>
                                <th scope="col" class="px-5 py-3 text-end"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="template in listed" :key="template.uuid" :class="cn('transition-colors hover:bg-muted/40', template.archived && 'text-muted-foreground')">
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-foreground">{{ template.name }}</p>
                                    <p v-if="template.description" class="mt-0.5 max-w-sm truncate text-xs text-muted-foreground">{{ template.description }}</p>
                                    <p v-if="template.archived" class="mt-0.5 text-xs">Archivé le {{ formatDate(template.archived_at) }} · {{ template.archive_reason }}</p>
                                </td>
                                <td v-if="! openFolder" class="px-4 py-3">
                                    <button type="button" class="text-sm font-medium text-primary hover:underline" @click="enterFolder(familyKey(template.document_type))">{{ folderLabel(familyKey(template.document_type)) }}</button>
                                </td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">
                                    {{ contextLabel(template.data_context) }}
                                    <span v-if="contextMismatch(template)" class="mt-1 flex items-center gap-1 font-medium text-amber-700 dark:text-amber-300" :title="`Ce dossier attend « ${contextLabel(contextMismatch(template))} » : sinon les dates ne sont pas reprises, et le canevas n’est pas proposé à l’impression.`">
                                        <TriangleAlert class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Attendu : {{ contextLabel(contextMismatch(template)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3"><Badge :variant="statusBadge(template).variant">{{ statusBadge(template).label }}</Badge></td>
                                <td class="px-4 py-3 text-end font-semibold text-foreground">{{ template.generated_documents_count }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-muted-foreground">{{ formatDate(template.updated_at) }}<template v-if="template.creator"><br>{{ template.creator }}</template></td>
                                <td class="px-5 py-3">
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            v-if="! template.archived && template.active && can('generated_documents.create')"
                                            :as="Link"
                                            :href="generateHref(template)"
                                            variant="outline"
                                            size="sm"
                                            :title="`Produire un document avec « ${template.name} » sur ${selectedSite.site.name}`"
                                        ><FilePlus2 class="h-4 w-4" />Générer</Button>
                                        <Button v-if="can('document_templates.update')" :as="Link" :href="editHref(template)" variant="ghost" size="icon" :title="template.archived ? 'Voir (lecture seule)' : 'Modifier'" :aria-label="`${template.archived ? 'Voir' : 'Modifier'} ${template.name}`"><Pencil class="h-4 w-4" /></Button>
                                        <Button v-if="can('document_templates.duplicate')" type="button" variant="ghost" size="icon" title="Dupliquer" :aria-label="`Dupliquer ${template.name}`" @click="duplicateTemplate(template)"><Copy class="h-4 w-4" /></Button>
                                        <template v-if="! template.archived">
                                            <Button v-if="can('document_templates.update')" type="button" variant="ghost" size="icon" :title="template.active ? 'Désactiver (plus proposé au RH)' : 'Activer (proposé au RH)'" :aria-label="`${template.active ? 'Désactiver' : 'Activer'} ${template.name}`" @click="toggleActive(template)">
                                                <component :is="template.active ? PowerOff : Power" class="h-4 w-4" />
                                            </Button>
                                            <Button v-if="can('document_templates.archive')" type="button" variant="ghost" size="icon" title="Archiver" :aria-label="`Archiver ${template.name}`" @click="openArchive(template)"><Archive class="h-4 w-4" /></Button>
                                        </template>
                                        <Button v-else-if="can('document_templates.restore')" type="button" variant="ghost" size="icon" title="Restaurer" :aria-label="`Restaurer ${template.name}`" @click="restoreTemplate(template)"><RotateCcw class="h-4 w-4" /></Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="flex flex-col items-center gap-3 px-4 py-12 text-center text-sm text-muted-foreground">
                    <FileText class="h-8 w-8 text-muted-foreground/60" aria-hidden="true" />
                    <p v-if="needle">Aucun canevas ne correspond à la recherche.</p>
                    <p v-else-if="showArchived">Aucun canevas archivé dans ce dossier.</p>
                    <template v-else>
                        <p>Aucun canevas dans ce dossier pour {{ selectedSite.site.name }} : le RH ne peut encore rien y produire.</p>
                        <Button v-if="can('document_templates.create')" :as="Link" :href="createHref" size="sm"><Plus class="h-4 w-4" />Composer un canevas « {{ openFolder.label }} »</Button>
                    </template>
                </div>
            </Card>
        </template>

        <Dialog
            v-if="archiving"
            :open="Boolean(archiving)"
            title="Archiver ce canevas ?"
            :description="`« ${archiving.name} » ne sera plus proposé au RH. Les documents déjà produits restent inchangés, et le canevas se restaure depuis « Archivés ».`"
            :dismissible="false"
            @update:open="(value) => { if (! value) archiving = null; }"
        >
            <FormField label="Motif" required :error="archiveForm.errors.reason">
                <Textarea v-model="archiveForm.reason" rows="3" maxlength="1000" placeholder="Ex. remplacé par un nouveau modèle…" />
            </FormField>
            <template #footer>
                <Button variant="outline" @click="archiving = null">Retour</Button>
                <Button variant="danger" :disabled="archiveForm.processing || archiveForm.reason.trim().length < 3" @click="confirmArchive"><Archive class="h-4 w-4" />Archiver</Button>
            </template>
        </Dialog>
    </div>
</template>
