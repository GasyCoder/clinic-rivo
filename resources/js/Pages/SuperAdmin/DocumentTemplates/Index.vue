<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive, ChevronRight, Copy, Eye, FilePlus2, FileText, Files, Folder, FolderOpen, FolderTree, LayoutGrid, List,
    MoreHorizontal, Pencil, Plus, Power, PowerOff, RotateCcw, Search, Server, TriangleAlert, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Sheet from '@/Components/Shadcn/Sheet.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { familyKey, familyTone } from '@/utilities/documentFamilies';
import {
    MODULE_NAME, MODULE_SPACE, STATUS_VIEWS, buildFolders, expectedContext, filterTemplates, modelCount, statusCounts, statusViewKey, templateStatus,
} from '@/utilities/documentTemplates';

defineOptions({ layout: AppLayout });

/**
 * ADR-240 — « Modèles de documents » : une gestion documentaire. À gauche
 * l'arborescence des dossiers du site, à droite ses modèles (liste ou
 * grille), et la fiche d'un modèle dans un panneau, avec son aperçu. Tous les
 * modèles de tous les sites sont déjà là : changer de site, de dossier ou
 * d'état ne rappelle aucun site, seule l'adresse suit.
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
const folderKey = ref(props.selectedFolder);
const statusView = ref('service');
const search = ref('');
const layout = ref('list');

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const templates = computed(() => selectedSite.value?.data?.templates ?? []);
const folders = computed(() => buildFolders(templates.value, props.families));
const openFolder = computed(() => folders.value.find((folder) => folder.key === folderKey.value) ?? null);
const counts = computed(() => statusCounts(templates.value, openFolder.value?.key ?? null));
const listed = computed(() => filterTemplates(templates.value, { folder: openFolder.value?.key ?? null, view: statusView.value, search: search.value }));
const totals = computed(() => {
    const live = templates.value.filter((template) => ! template.archived);

    return {
        live: live.length,
        active: live.filter((template) => template.active).length,
        documents: live.reduce((sum, template) => sum + (template.generated_documents_count ?? 0), 0),
        check: live.filter((template) => expectedContext(template)).length,
    };
});
const visibleViews = computed(() => STATUS_VIEWS.filter((view) => view.key !== 'a-verifier' || counts.value['a-verifier'] > 0 || statusView.value === 'a-verifier'));

const contextOf = (value) => props.dataContexts.find((context) => context.value === value) ?? null;
const contextLabel = (value) => contextOf(value)?.label ?? value;
const folderLabel = (key) => folders.value.find((folder) => folder.key === key)?.label ?? key;

const TONES = {
    sky: 'text-sky-600 bg-sky-50 dark:bg-sky-950/40 dark:text-sky-300',
    emerald: 'text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 dark:text-emerald-300',
    violet: 'text-violet-600 bg-violet-50 dark:bg-violet-950/40 dark:text-violet-300',
    amber: 'text-amber-600 bg-amber-50 dark:bg-amber-950/40 dark:text-amber-300',
    primary: 'text-primary bg-primary/10',
    slate: 'text-muted-foreground bg-muted',
};
const toneClass = (key) => TONES[familyTone(key)] ?? TONES.slate;

// L'adresse suit le site, le dossier et l'état ; rien n'est relu sur le serveur.
const syncAddress = () => {
    const url = new URL(window.location.href);
    url.searchParams.set('site', selectedSiteCode.value);
    if (folderKey.value) url.searchParams.set('dossier', folderKey.value);
    else url.searchParams.delete('dossier');
    if (statusView.value !== 'service') url.searchParams.set('statut', statusView.value);
    else url.searchParams.delete('statut');
    window.history.replaceState(window.history.state, '', url);
};
const selectSite = (code) => {
    selectedSiteCode.value = code;
    folderKey.value = null;
    statusView.value = 'service';
    search.value = '';
    syncAddress();
};
const selectFolder = (key) => {
    folderKey.value = key;
    if (statusView.value === 'a-verifier' && key && ! ['CONTRAT', 'CONGE'].includes(key)) statusView.value = 'service';
    syncAddress();
};
const selectView = (key) => {
    statusView.value = statusViewKey(key);
    syncAddress();
};

const LAYOUT_KEY = 'rivo:document-templates:layout';
const setLayout = (value) => {
    layout.value = value;
    try { window.localStorage.setItem(LAYOUT_KEY, value); } catch { /* stockage indisponible */ }
};
onMounted(() => {
    statusView.value = statusViewKey(new URL(window.location.href).searchParams.get('statut'));
    try { if (window.localStorage.getItem(LAYOUT_KEY) === 'grid') layout.value = 'grid'; } catch { /* stockage indisponible */ }
});

const base = computed(() => `/super-admin/workspaces/document-templates/${selectedSiteCode.value}`);
const createHref = (key = openFolder.value?.key) => `${base.value}/create${key ? `?type=${encodeURIComponent(key)}` : ''}`;
const editHref = (template) => `${base.value}/${template.uuid}/edit`;
const siteRh = computed(() => `/super-admin/sites/${selectedSiteCode.value}/rh`);
const generateHref = (template) => `${siteRh.value}/generated-documents/create?${new URLSearchParams({ template: template.uuid, dossier: familyKey(template.document_type) })}`;
const documentsHref = computed(() => `${siteRh.value}/generated-documents${openFolder.value ? `?dossier=${encodeURIComponent(openFolder.value.key)}` : ''}`);
const canGenerate = (template) => ! template.archived && template.active && can('generated_documents.create');

// Les écritures partent au site ; la page revient telle quelle (même site, même dossier).
const keep = { preserveScroll: true, preserveState: true };
const details = ref(null);
const archiving = ref(null);
const archiveForm = useForm({ reason: '' });

const actionsFor = (template) => [
    { key: 'details', label: 'Voir la fiche', icon: Eye },
    can('document_templates.update') && { key: 'edit', label: template.archived ? 'Ouvrir (lecture seule)' : 'Modifier', icon: Pencil },
    can('document_templates.duplicate') && { key: 'duplicate', label: 'Dupliquer', icon: Copy, description: 'Une copie inactive, à retoucher' },
    ! template.archived && can('document_templates.update') && (template.active
        ? { key: 'deactivate', label: 'Retirer du RH', icon: PowerOff, description: 'Le RH ne le voit plus, rien n’est effacé', separatorBefore: true }
        : { key: 'activate', label: 'Proposer au RH', icon: Power, separatorBefore: true }),
    ! template.archived && can('document_templates.archive') && { key: 'archive', label: 'Archiver', icon: Archive, destructive: true },
    template.archived && can('document_templates.restore') && { key: 'restore', label: 'Restaurer', icon: RotateCcw, separatorBefore: true },
].filter(Boolean);

const runAction = (template, key) => {
    if (key === 'details') details.value = template;
    else if (key === 'edit') router.visit(editHref(template));
    else if (key === 'duplicate') router.post(`${base.value}/${template.uuid}/duplicate`, {}, keep);
    else if (key === 'activate' || key === 'deactivate') router.post(`${base.value}/${template.uuid}/${key}`, {}, { ...keep, onSuccess: () => { details.value = null; } });
    else if (key === 'restore') router.post(`${base.value}/${template.uuid}/restore`, {}, { ...keep, onSuccess: () => { details.value = null; } });
    else if (key === 'archive') {
        archiveForm.reset();
        archiveForm.clearErrors();
        archiving.value = template;
    }
};
const confirmArchive = () => archiveForm.delete(`${base.value}/${archiving.value.uuid}`, {
    ...keep,
    onSuccess: () => { archiving.value = null; details.value = null; },
});

const formatDate = (value) => (value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' }).format(new Date(value)) : '—');
const detailsContext = computed(() => (details.value ? contextOf(details.value.data_context) : null));
const detailsExpected = computed(() => (details.value ? expectedContext(details.value) : null));
const detailsPages = computed(() => (details.value?.content?.pages?.length ?? (details.value?.content_html ? 1 : 0)));
const emptyMessage = computed(() => {
    if (search.value.trim()) return 'Aucun modèle ne correspond à la recherche.';
    if (statusView.value === 'archives') return 'Aucun modèle archivé ici.';
    if (statusView.value === 'inactifs') return 'Aucun modèle inactif ici.';
    if (statusView.value === 'a-verifier') return 'Aucun modèle à vérifier : chaque contrat et chaque congé reprend bien ses dates.';

    return openFolder.value
        ? `Aucun modèle « ${openFolder.value.label} » sur ${selectedSite.value?.site.name} : le RH ne peut encore rien y produire.`
        : `Aucun modèle sur ${selectedSite.value?.site.name} pour l’instant.`;
});
</script>

<template>
    <Head :title="openFolder ? `${openFolder.label} · ${MODULE_NAME}` : MODULE_NAME" />

    <div class="w-full space-y-5">
        <!-- En-tête -->
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><Files class="h-5 w-5" /></span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Super Administration · {{ MODULE_SPACE }}</p>
                    <h1 class="font-heading text-xl font-bold text-foreground">{{ MODULE_NAME }}</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">Les modèles que le RH de chaque site utilise pour produire contrats, congés, attestations… Rangés par dossier, rédigés ici et envoyés au site choisi ; le RH n’y ajoute que la page 1, remplie depuis le dossier de la personne.</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="can('generated_documents.view') && selectedSite?.ok" :as="Link" :href="documentsHref" variant="outline"><FileText class="h-4 w-4" />Documents produits</Button>
                <Button v-if="can('document_templates.create')" :as="Link" :href="createHref()" :disabled="! selectedSite?.ok"><Plus class="h-4 w-4" />Nouveau modèle</Button>
            </div>
        </header>

        <!-- Sites -->
        <nav class="flex gap-1 overflow-x-auto rounded-xl border border-border bg-muted/60 p-1" aria-label="Site des modèles">
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
            <!-- Repères du site -->
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div class="rounded-lg border border-border bg-card px-3 py-2"><p class="text-xs text-muted-foreground">Modèles en service</p><p class="text-lg font-bold text-foreground">{{ totals.live }}</p></div>
                <div class="rounded-lg border border-border bg-card px-3 py-2"><p class="text-xs text-muted-foreground">Proposés au RH</p><p class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ totals.active }}</p></div>
                <div class="rounded-lg border border-border bg-card px-3 py-2"><p class="text-xs text-muted-foreground">Documents produits</p><p class="text-lg font-bold text-foreground">{{ totals.documents }}</p></div>
                <button type="button" :disabled="! totals.check" :class="cn('rounded-lg border px-3 py-2 text-start transition', totals.check ? 'border-amber-300 bg-amber-50 hover:bg-amber-100 dark:border-amber-900 dark:bg-amber-950/30' : 'border-border bg-card')" @click="selectFolder(null); selectView('a-verifier')">
                    <span class="block text-xs text-muted-foreground">À vérifier</span>
                    <span :class="cn('block text-lg font-bold', totals.check ? 'text-amber-700 dark:text-amber-300' : 'text-foreground')">{{ totals.check }}</span>
                </button>
            </div>

            <div class="grid gap-4 lg:grid-cols-[17rem_minmax(0,1fr)]">
                <!-- Arborescence -->
                <Card class="h-fit p-2 lg:sticky lg:top-20">
                    <p class="flex items-center gap-2 px-2 pb-2 pt-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><FolderTree class="h-3.5 w-3.5" />Dossiers</p>
                    <nav class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible" aria-label="Dossiers de modèles">
                        <button type="button" :aria-current="! openFolder ? 'page' : undefined" :class="cn('flex shrink-0 items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition', ! openFolder ? 'bg-accent font-semibold text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground')" @click="selectFolder(null)">
                            <span class="grid h-7 w-7 place-items-center rounded-md bg-muted text-foreground"><Files class="h-4 w-4" /></span>
                            <span class="flex-1 text-start">Tous les modèles</span>
                            <span class="text-xs tabular-nums text-muted-foreground">{{ totals.live }}</span>
                        </button>
                        <button
                            v-for="folder in folders"
                            :key="folder.key"
                            type="button"
                            :aria-current="openFolder?.key === folder.key ? 'page' : undefined"
                            :class="cn('flex shrink-0 items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition', openFolder?.key === folder.key ? 'bg-accent font-semibold text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground')"
                            :title="`${folder.label} : ${modelCount(folder.total)}, ${folder.active} proposé(s) au RH`"
                            @click="selectFolder(folder.key)"
                        >
                            <span :class="cn('grid h-7 w-7 place-items-center rounded-md', toneClass(folder.key))"><component :is="openFolder?.key === folder.key ? FolderOpen : Folder" class="h-4 w-4" /></span>
                            <span :class="cn('flex-1 truncate text-start', ! folder.total && 'opacity-70')">{{ folder.label }}</span>
                            <span v-if="folder.total" class="text-xs tabular-nums text-muted-foreground">{{ folder.active }}/{{ folder.total }}</span>
                        </button>
                    </nav>
                    <p class="hidden px-2.5 pb-1 pt-3 text-[11px] leading-relaxed text-muted-foreground lg:block">Chiffre : proposés au RH / en service. Un type écrit à la main crée son propre dossier.</p>
                </Card>

                <!-- Contenu du dossier -->
                <Card class="min-w-0 overflow-hidden">
                    <div class="space-y-3 border-b border-border px-4 py-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <nav class="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground" aria-label="Fil d’Ariane">
                                <button type="button" class="font-medium hover:text-foreground" @click="selectFolder(null)">{{ selectedSite.site.name }}</button>
                                <template v-if="openFolder"><ChevronRight class="h-3.5 w-3.5" /><span class="truncate font-semibold text-foreground">{{ openFolder.label }}</span></template>
                            </nav>
                            <label class="relative ms-auto block w-full sm:w-72">
                                <span class="sr-only">Rechercher un modèle</span>
                                <IconInput v-model="search" :icon="Search" type="search" class="pe-9" :placeholder="openFolder ? `Chercher dans ${openFolder.label.toLowerCase()}…` : 'Nom, type, description…'" />
                                <button v-if="search" type="button" class="absolute inset-y-0 end-2 my-auto grid h-6 w-6 place-items-center rounded-md text-muted-foreground hover:bg-accent" aria-label="Effacer la recherche" @click="search = ''"><X class="h-3.5 w-3.5" /></button>
                            </label>
                            <div class="flex rounded-lg border border-border p-0.5" role="group" aria-label="Présentation">
                                <button type="button" :aria-pressed="layout === 'list'" :class="cn('grid h-7 w-7 place-items-center rounded-md', layout === 'list' ? 'bg-accent text-foreground' : 'text-muted-foreground')" title="Liste" @click="setLayout('list')"><List class="h-4 w-4" /></button>
                                <button type="button" :aria-pressed="layout === 'grid'" :class="cn('grid h-7 w-7 place-items-center rounded-md', layout === 'grid' ? 'bg-accent text-foreground' : 'text-muted-foreground')" title="Grille" @click="setLayout('grid')"><LayoutGrid class="h-4 w-4" /></button>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <nav class="flex flex-wrap gap-1 rounded-lg bg-muted p-1" aria-label="État des modèles">
                                <button
                                    v-for="view in visibleViews"
                                    :key="view.key"
                                    type="button"
                                    :aria-current="statusView === view.key ? 'page' : undefined"
                                    :class="cn('rounded-md px-3 py-1.5 text-sm font-semibold transition', statusView === view.key ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground', view.key === 'a-verifier' && 'text-amber-700 dark:text-amber-300')"
                                    @click="selectView(view.key)"
                                >{{ view.label }} · {{ counts[view.key] }}</button>
                            </nav>
                            <p v-if="openFolder?.context" class="text-xs text-muted-foreground">Données reprises attendues : <span class="font-semibold text-foreground">{{ contextLabel(openFolder.context) }}</span></p>
                        </div>
                    </div>

                    <!-- Liste -->
                    <div v-if="listed.length && layout === 'list'" class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead>
                                <tr class="border-b border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">
                                    <th scope="col" class="px-4 py-2.5">Modèle</th>
                                    <th v-if="! openFolder" scope="col" class="px-3 py-2.5">Dossier</th>
                                    <th scope="col" class="px-3 py-2.5">Données reprises</th>
                                    <th scope="col" class="px-3 py-2.5">État</th>
                                    <th scope="col" class="px-3 py-2.5 text-end">Documents</th>
                                    <th scope="col" class="px-3 py-2.5">Mis à jour</th>
                                    <th scope="col" class="px-4 py-2.5 text-end"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="template in listed" :key="template.uuid" :class="cn('transition-colors hover:bg-muted/40', template.archived && 'text-muted-foreground')">
                                    <td class="px-4 py-2.5">
                                        <button type="button" class="flex items-start gap-2.5 text-start" @click="details = template">
                                            <span :class="cn('mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-md', toneClass(familyKey(template.document_type)))"><FileText class="h-4 w-4" /></span>
                                            <span class="min-w-0">
                                                <span class="block font-semibold text-foreground hover:underline">{{ template.name }}</span>
                                                <span v-if="template.description" class="block max-w-sm truncate text-xs text-muted-foreground">{{ template.description }}</span>
                                                <span v-if="template.archived" class="block text-xs">Archivé le {{ formatDate(template.archived_at) }}</span>
                                            </span>
                                        </button>
                                    </td>
                                    <td v-if="! openFolder" class="px-3 py-2.5">
                                        <button type="button" class="text-sm font-medium text-primary hover:underline" @click="selectFolder(familyKey(template.document_type))">{{ folderLabel(familyKey(template.document_type)) }}</button>
                                    </td>
                                    <td class="px-3 py-2.5 text-xs text-muted-foreground">
                                        {{ contextLabel(template.data_context) }}
                                        <span class="mt-1 flex flex-wrap gap-1">
                                            <span v-for="label in template.applies_to_labels ?? []" :key="label" class="rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-medium text-primary">{{ label }}</span>
                                            <span v-if="template.data_context !== 'EMPLOYEE_ONLY' && ! template.applies_to_labels?.length" class="rounded-md bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">Tous les types</span>
                                        </span>
                                        <span v-if="expectedContext(template)" class="mt-1 flex items-center gap-1 font-medium text-amber-700 dark:text-amber-300" :title="`Ce dossier attend « ${contextLabel(expectedContext(template))} » : sinon les dates ne sont pas reprises, et le modèle n’est pas proposé à l’impression.`">
                                            <TriangleAlert class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Attendu : {{ contextLabel(expectedContext(template)) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5"><Badge :variant="templateStatus(template).variant">{{ templateStatus(template).label }}</Badge></td>
                                    <td class="px-3 py-2.5 text-end font-semibold tabular-nums text-foreground">{{ template.generated_documents_count }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-xs text-muted-foreground">{{ formatDate(template.updated_at) }}<template v-if="template.creator"><br>{{ template.creator }}</template></td>
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center justify-end gap-1">
                                            <Button v-if="canGenerate(template)" :as="Link" :href="generateHref(template)" variant="outline" size="sm" :title="`Produire un document avec « ${template.name} » sur ${selectedSite.site.name}`"><FilePlus2 class="h-4 w-4" />Générer</Button>
                                            <DropdownMenu :items="actionsFor(template)" :label="template.name" @select="(key) => runAction(template, key)">
                                                <template #trigger>
                                                    <Button variant="ghost" size="icon" :aria-label="`Actions sur ${template.name}`"><MoreHorizontal class="h-4 w-4" /></Button>
                                                </template>
                                            </DropdownMenu>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Grille -->
                    <div v-else-if="listed.length" class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                        <article v-for="template in listed" :key="template.uuid" :class="cn('flex flex-col rounded-xl border border-border bg-background p-3 transition hover:border-primary/40 hover:shadow-sm', template.archived && 'opacity-75')">
                            <button type="button" class="flex items-start gap-3 text-start" @click="details = template">
                                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', toneClass(familyKey(template.document_type)))"><FileText class="h-5 w-5" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold text-foreground">{{ template.name }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ folderLabel(familyKey(template.document_type)) }} · {{ contextLabel(template.data_context) }}</span>
                                </span>
                            </button>
                            <p v-if="template.applies_to_labels?.length" class="mt-2 flex flex-wrap gap-1">
                                <span v-for="label in template.applies_to_labels" :key="label" class="rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-medium text-primary">{{ label }}</span>
                            </p>
                            <p v-if="template.description" class="mt-2 line-clamp-2 text-xs text-muted-foreground">{{ template.description }}</p>
                            <p v-if="expectedContext(template)" class="mt-2 flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-300"><TriangleAlert class="h-3.5 w-3.5 shrink-0" />Attendu : {{ contextLabel(expectedContext(template)) }}</p>
                            <div class="mt-auto flex items-center gap-2 pt-3">
                                <Badge :variant="templateStatus(template).variant">{{ templateStatus(template).label }}</Badge>
                                <span class="text-xs text-muted-foreground">{{ template.generated_documents_count }} doc.</span>
                                <div class="ms-auto flex items-center gap-1">
                                    <Button v-if="canGenerate(template)" :as="Link" :href="generateHref(template)" variant="outline" size="xs"><FilePlus2 class="h-3.5 w-3.5" />Générer</Button>
                                    <DropdownMenu :items="actionsFor(template)" :label="template.name" @select="(key) => runAction(template, key)">
                                        <template #trigger>
                                            <Button variant="ghost" size="icon" :aria-label="`Actions sur ${template.name}`"><MoreHorizontal class="h-4 w-4" /></Button>
                                        </template>
                                    </DropdownMenu>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div v-else class="flex flex-col items-center gap-3 px-4 py-14 text-center text-sm text-muted-foreground">
                        <span class="grid h-12 w-12 place-items-center rounded-xl bg-muted"><FolderOpen class="h-6 w-6" aria-hidden="true" /></span>
                        <p class="max-w-md">{{ emptyMessage }}</p>
                        <Button v-if="can('document_templates.create') && statusView === 'service' && ! search.trim()" :as="Link" :href="createHref()" size="sm"><Plus class="h-4 w-4" />{{ openFolder ? `Nouveau modèle « ${openFolder.label} »` : 'Nouveau modèle' }}</Button>
                    </div>
                </Card>
            </div>
        </template>

        <!-- Fiche d'un modèle -->
        <Sheet
            :open="Boolean(details)"
            :title="details?.name ?? 'Modèle'"
            :description="details ? `${folderLabel(familyKey(details.document_type))} · ${selectedSite?.site.name}` : ''"
            content-class="sm:max-w-xl"
            @update:open="(value) => { if (! value) details = null; }"
        >
            <template v-if="details">
                <div class="space-y-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge :variant="templateStatus(details).variant">{{ templateStatus(details).label }}</Badge>
                        <Badge variant="outline">Type {{ details.document_type }}</Badge>
                        <span class="text-xs text-muted-foreground">{{ detailsPages }} page{{ detailsPages > 1 ? 's' : '' }} de texte</span>
                    </div>
                    <p v-if="details.description" class="text-sm text-foreground">{{ details.description }}</p>
                    <p v-if="details.archived" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">Archivé le {{ formatDate(details.archived_at) }}<template v-if="details.archive_reason"> · {{ details.archive_reason }}</template></p>

                    <section class="space-y-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Données reprises du dossier RH</h3>
                        <p class="text-sm font-medium text-foreground">{{ contextLabel(details.data_context) }}</p>
                        <p v-if="detailsExpected" class="flex items-start gap-1.5 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200"><TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />Ce dossier attend « {{ contextLabel(detailsExpected) }} » : sinon les dates ne sont pas reprises, et le modèle n’est pas proposé à l’impression.</p>
                        <div v-if="detailsContext?.fields?.length" class="flex flex-wrap gap-1.5">
                            <span v-for="field in detailsContext.fields" :key="field" class="rounded-md border border-border bg-muted/40 px-2 py-0.5 text-xs text-foreground">{{ field }}</span>
                        </div>
                        <p v-if="detailsContext?.offered_from?.length" class="text-xs text-muted-foreground">Proposé au RH depuis : {{ detailsContext.offered_from.join(' · ') }}</p>
                    </section>

                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Documents produits</dt><dd class="font-semibold text-foreground">{{ details.generated_documents_count }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Mis à jour</dt><dd class="font-semibold text-foreground">{{ formatDate(details.updated_at) }}</dd><dd v-if="details.creator" class="text-xs text-muted-foreground">{{ details.creator }}</dd></div>
                    </dl>

                    <section v-if="details.content_html" class="space-y-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Aperçu du texte</h3>
                        <div class="max-h-96 overflow-y-auto template-paper rounded-lg border border-border p-4 text-[13px] shadow-inner">
                            <div class="template-preview" v-html="details.content_html" />
                        </div>
                        <p class="text-[11px] text-muted-foreground">La page 1 (informations de la personne) est ajoutée par le RH à la génération.</p>
                    </section>
                </div>
            </template>
            <template #footer>
                <div v-if="details" class="flex w-full flex-wrap justify-end gap-2">
                    <DropdownMenu :items="actionsFor(details).filter((item) => ! ['details', 'edit'].includes(item.key))" label="Autres actions" @select="(key) => runAction(details, key)">
                        <template #trigger><Button variant="outline"><MoreHorizontal class="h-4 w-4" />Autres actions</Button></template>
                    </DropdownMenu>
                    <Button v-if="can('document_templates.update')" :as="Link" :href="editHref(details)" variant="outline"><Pencil class="h-4 w-4" />{{ details.archived ? 'Ouvrir' : 'Modifier' }}</Button>
                    <Button v-if="canGenerate(details)" :as="Link" :href="generateHref(details)"><FilePlus2 class="h-4 w-4" />Générer un document</Button>
                </div>
            </template>
        </Sheet>

        <Dialog
            v-if="archiving"
            :open="Boolean(archiving)"
            title="Archiver ce modèle ?"
            :description="`« ${archiving.name} » ne sera plus proposé au RH. Les documents déjà produits restent inchangés, et le modèle se restaure depuis « Archivés ».`"
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

<style scoped>
.template-paper { background-color: white; color: rgb(15 23 42); }
.template-preview :deep(table) { border-collapse: collapse; width: 100%; margin: 0.5rem 0; }
.template-preview :deep(td), .template-preview :deep(th) { border: 1px solid rgb(203 213 225); padding: 0.25rem 0.4rem; }
.template-preview :deep(h1) { font-size: 1.25rem; font-weight: 700; }
.template-preview :deep(h2) { font-size: 1.1rem; font-weight: 700; }
.template-preview :deep(h3), .template-preview :deep(h4) { font-weight: 700; }
.template-preview :deep(ul) { list-style: disc; padding-left: 1.25rem; }
.template-preview :deep(ol) { list-style: decimal; padding-left: 1.25rem; }
.template-preview :deep(p) { margin: 0.25rem 0; }
.template-preview :deep(img) { max-width: 100%; }
.template-preview :deep(.canevas-page-break) { margin: 0.75rem 0; border-top: 1px dashed rgb(203 213 225); }
</style>
