<script setup>
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, onMounted, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { Check, CloudUpload, Download, ExternalLink, Eye, FileCheck, FileSpreadsheet, FileText, LayoutGrid, List, ListChecks, Pencil, Upload, X } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';

/**
 * ADR-098 — the catalog files of one supplier folder, shared by the clinic
 * (consultation, and management when granted) and the central portal. Both
 * expose the same URL shape under `baseUrl`: POST to upload, and per catalog
 * /activate, DELETE, /restore, GET /import (preview).
 */
const props = defineProps({
    supplierName: { type: String, required: true },
    catalogs: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    baseUrl: { type: String, required: true },
    // The clinic opens its own file directly; the portal streams it back
    // through the site API (`downloadUrl`), never a central copy.
    canOpenFile: { type: Boolean, default: true },
    downloadUrl: { type: Function, default: null },
    // The parsed lines of a catalog are browsed from the clinic folder.
    canBrowseItems: { type: Boolean, default: true },
    // Blank Excel file with the columns a catalog must have to be read.
    templateUrl: { type: String, default: null },
});

// Grid or list, like a file explorer; remembered per browser, never required.
const VIEW_KEY = 'rivo.supplier-catalogs.view';
const readView = () => {
    try { return localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid'; } catch { return 'grid'; }
};
const view = ref('grid');
// Applied once the browser has the page: the server renders the default
// view, and reading storage while rendering would not match it (hydration).
onMounted(() => {
    view.value = readView();
});
const setView = (value) => {
    view.value = value;
    try { localStorage.setItem(VIEW_KEY, value); } catch { /* private window */ }
};

const selected = ref(null);
const toggleSelected = (catalog) => { selected.value = selected.value === catalog.uuid ? null : catalog.uuid; };

const showArchived = ref(false);
const visible = computed(() => props.catalogs.filter((catalog) => showArchived.value || !catalog.archived));
const archivedCount = computed(() => props.catalogs.filter((catalog) => catalog.archived).length);

const formatSize = (bytes) => {
    if (!bytes) return '—';
    const units = ['o', 'Ko', 'Mo'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit += 1; }

    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
};

const uploading = ref(false);
const uploadForm = useForm({ file: null, catalog_date: '', notes: '' });
const submitUpload = () => uploadForm.post(props.baseUrl, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { uploadForm.reset(); uploading.value = false; },
});

const activate = (catalog) => {
    if (!confirm(`Utiliser « ${catalog.original_name} » comme catalogue actuel de ${props.supplierName} ?`)) return;
    router.post(`${props.baseUrl}/${catalog.uuid}/activate`, {}, { preserveScroll: true });
};
const restore = (catalog) => router.post(`${props.baseUrl}/${catalog.uuid}/restore`, {}, { preserveScroll: true });

const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const confirmArchive = () => archiveForm.delete(`${props.baseUrl}/${archiving.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; archiveForm.reset(); },
});

const itemsHref = (catalog) => `${props.baseUrl}/${catalog.uuid}/items`;
const fileHref = (catalog) => (props.downloadUrl
    ? props.downloadUrl(catalog)
    : `${props.baseUrl}/${catalog.uuid}`);

// Only what is written about a catalog changes; a new tariff is a new file.
const editing = ref(null);
const editForm = useForm({ catalog_date: '', notes: '' });
const startEdit = (catalog) => {
    editing.value = catalog;
    editForm.catalog_date = catalog.catalog_date ?? '';
    editForm.notes = catalog.notes ?? '';
    editForm.clearErrors();
};
const saveEdit = () => editForm.patch(`${props.baseUrl}/${editing.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { editing.value = null; },
});

const VIEW_OPTIONS = [
    { value: 'grid', icon: LayoutGrid, label: 'Grandes icônes' },
    { value: 'list', icon: List, label: 'Liste' },
];
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="inline-flex rounded-lg border border-border bg-card p-0.5" role="group" aria-label="Affichage des catalogues">
                    <button
                        v-for="option in VIEW_OPTIONS"
                        :key="option.value"
                        type="button"
                        :title="option.label"
                        :aria-pressed="view === option.value"
                        :class="['inline-flex h-9 items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition', view === option.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground']"
                        @click="setView(option.value)"
                    >
                        <component :is="option.icon" class="h-4 w-4" /><span class="hidden sm:inline">{{ option.label }}</span>
                    </button>
                </div>
                <span class="text-sm text-muted-foreground">{{ visible.length }} fichier{{ visible.length > 1 ? 's' : '' }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="templateUrl" as="a" :href="templateUrl" size="rg" variant="white-outline" title="Fichier Excel vide avec les colonnes attendues">
                    <Download class="h-4 w-4" />Télécharger le canevas Excel
                </Button>
                <Button v-if="can.create" size="rg" @click="uploading = !uploading">
                    <component :is="uploading ? X : Upload" class="h-4 w-4" />{{ uploading ? 'Fermer' : 'Importer un catalogue' }}
                </Button>
            </div>
        </div>

        <form v-if="uploading" class="space-y-4 rounded-xl border border-primary/30 bg-card p-5 shadow-sm" @submit.prevent="submitUpload">
            <div>
                <h2 class="font-heading text-base font-bold text-foreground">Importer un catalogue</h2>
                <p class="mt-1 text-sm text-muted-foreground">Fichier Excel ou PDF. Un fichier Excel pourra ensuite être lu ligne par ligne s’il contient les colonnes <strong>Référence</strong>, <strong>Médicament</strong>, <strong>Présentation</strong> et <strong>Prix fournisseur</strong>.</p>
            </div>
            <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-input px-4 py-8 text-center transition hover:border-primary">
                <component :is="uploadForm.file ? FileCheck : CloudUpload" class="h-9 w-9 text-muted-foreground" />
                <span class="text-sm font-semibold text-foreground">{{ uploadForm.file ? uploadForm.file.name : 'Choisir un fichier' }}</span>
                <span class="text-xs text-muted-foreground">.xlsx, .xls ou .pdf — 10 Mo au maximum</span>
                <input type="file" accept=".xlsx,.xls,.pdf" class="sr-only" @change="uploadForm.file = $event.target.files[0]">
            </label>
            <p v-if="uploadForm.errors.file || uploadForm.errors.site" class="text-sm text-red-600">{{ uploadForm.errors.file || uploadForm.errors.site }}</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Date du catalogue</span>
                    <DatePicker v-model="uploadForm.catalog_date" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Remarque</span>
                    <input v-model="uploadForm.notes" type="text" class="h-11 w-full rounded-lg border border-border bg-card px-3 text-sm" placeholder="Ex. tarif valable jusqu’en décembre">
                </label>
            </div>
            <div class="flex justify-end gap-2">
                <Button type="button" size="rg" variant="white-outline" @click="uploading = false">Annuler</Button>
                <Button type="submit" size="rg" :disabled="uploadForm.processing || !uploadForm.file"><Upload class="h-4 w-4" />Ajouter au dossier</Button>
            </div>
        </form>

        <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div v-if="visible.length && view === 'grid'" class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                <div
                    v-for="catalog in visible"
                    :key="catalog.uuid"
                    :class="['group relative flex flex-col items-center rounded-xl border p-4 text-center transition', selected === catalog.uuid ? 'border-primary bg-primary/5 ' : 'border-transparent hover:border-border hover:bg-muted dark:hover:border-border ', catalog.archived && 'opacity-60']"
                >
                    <button type="button" class="flex w-full flex-col items-center" :aria-expanded="selected === catalog.uuid" @click="toggleSelected(catalog)">
                        <span class="relative">
                            <component :is="catalog.kind === 'EXCEL' ? FileSpreadsheet : FileText" :class="cn('h-12 w-12 shrink-0', catalog.archived ? 'text-muted-foreground' : (catalog.kind === 'EXCEL' ? 'text-emerald-500' : 'text-red-500'))" />
                            <span v-if="catalog.is_active" class="absolute -end-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-white ring-2 ring-card" title="Catalogue actuel"><Check class="h-3 w-3" /></span>
                        </span>
                        <span class="mt-2 line-clamp-2 break-all text-sm font-semibold text-foreground">{{ catalog.original_name }}</span>
                        <span class="mt-0.5 text-xs text-muted-foreground">{{ catalog.catalog_date ? formatDate(catalog.catalog_date) : formatDate(catalog.created_at) }} · {{ formatSize(catalog.size) }}</span>
                        <span v-if="catalog.kind === 'EXCEL'" :class="['mt-0.5 text-xs', catalog.is_imported ? 'text-emerald-600' : 'text-amber-600']">{{ catalog.is_imported ? `${catalog.items_count} lignes lues` : 'Pas encore lu' }}</span>
                        <Badge v-if="catalog.archived" tone="neutral" class="mt-1">Archivé</Badge>
                    </button>

                    <div v-if="selected === catalog.uuid" class="mt-3 flex w-full flex-col gap-1.5">
                        <template v-if="!catalog.archived">
                            <Button v-if="catalog.kind === 'EXCEL' && can.create" :as="Link" :href="`${baseUrl}/${catalog.uuid}/import`" size="sm" :variant="catalog.is_imported ? 'white-outline' : 'primary'" class="justify-center">{{ catalog.is_imported ? 'Relire les lignes' : 'Lire les lignes' }}</Button>
                            <Button v-if="canBrowseItems && catalog.kind === 'EXCEL' && catalog.is_imported" :as="Link" :href="itemsHref(catalog)" size="sm" variant="white-outline" class="justify-center">Voir les produits</Button>
                            <Button v-if="canOpenFile || downloadUrl" as="a" :href="fileHref(catalog)" :target="canOpenFile ? '_blank' : null" size="sm" variant="white-outline" class="justify-center">
                                <component :is="canOpenFile ? Eye : Download" class="h-4 w-4" />{{ canOpenFile ? 'Ouvrir le fichier' : 'Télécharger' }}
                            </Button>
                            <Button v-if="can.update && !catalog.is_active" size="sm" variant="white-outline" class="justify-center" @click="activate(catalog)">Utiliser ce catalogue</Button>
                            <Button v-if="can.update" size="sm" variant="white-outline" class="justify-center" @click="startEdit(catalog)">Modifier</Button>
                            <Button v-if="can.delete" size="sm" variant="white-outline" class="justify-center text-red-600" @click="archiving = catalog">Mettre à la corbeille</Button>
                        </template>
                        <Button v-else-if="can.restore" size="sm" variant="white-outline" class="justify-center" @click="restore(catalog)">Restaurer</Button>
                    </div>
                </div>
            </div>

            <ul v-else-if="visible.length" class="divide-y divide-border">
                <li v-for="catalog in visible" :key="catalog.uuid" :class="['flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between', catalog.archived && 'bg-muted/70 /40']">
                    <div class="flex min-w-0 items-center gap-4">
                        <component :is="catalog.kind === 'EXCEL' ? FileSpreadsheet : FileText" :class="cn('h-9 w-9 shrink-0', catalog.archived ? 'text-muted-foreground' : (catalog.kind === 'EXCEL' ? 'text-emerald-500' : 'text-red-500'))" />
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link v-if="canBrowseItems && catalog.kind === 'EXCEL' && catalog.is_imported" :href="itemsHref(catalog)" class="truncate font-semibold text-foreground hover:text-primary">{{ catalog.original_name }}</Link>
                                <span v-else class="truncate font-semibold text-foreground">{{ catalog.original_name }}</span>
                                <Badge v-if="catalog.is_active" tone="success" dot>Catalogue actuel</Badge>
                                <Badge v-if="catalog.archived" tone="neutral">Archivé</Badge>
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ catalog.kind_label }} · {{ formatSize(catalog.size) }}
                                <span v-if="catalog.catalog_date"> · daté du {{ formatDate(catalog.catalog_date) }}</span>
                                <span> · ajouté le {{ formatDate(catalog.created_at) }}</span>
                                <span v-if="catalog.creator"> par {{ catalog.creator }}</span>
                            </p>
                            <p v-if="catalog.kind === 'EXCEL'" class="mt-0.5 text-xs" :class="catalog.is_imported ? 'text-emerald-600' : 'text-amber-600'">
                                {{ catalog.is_imported ? `${catalog.items_count} ligne${catalog.items_count > 1 ? 's' : ''} lue${catalog.items_count > 1 ? 's' : ''}` : 'Lignes pas encore lues' }}
                            </p>
                            <p v-if="catalog.archived && catalog.delete_reason" class="mt-0.5 text-xs text-muted-foreground">Motif : {{ catalog.delete_reason }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        <template v-if="!catalog.archived">
                            <Button v-if="catalog.kind === 'EXCEL' && can.create" :as="Link" :href="`${baseUrl}/${catalog.uuid}/import`" size="sm" :variant="catalog.is_imported ? 'white-outline' : 'primary'">
                                <ListChecks class="h-4 w-4" />{{ catalog.is_imported ? 'Relire les lignes' : 'Lire les lignes' }}
                            </Button>
                            <Button v-if="canBrowseItems && catalog.kind === 'EXCEL' && catalog.is_imported" :as="Link" :href="itemsHref(catalog)" size="sm" variant="white-outline">
                                <Eye class="h-4 w-4" />Voir les produits
                            </Button>
                            <Button v-if="canOpenFile || downloadUrl" as="a" :href="fileHref(catalog)" :target="canOpenFile ? '_blank' : null" size="sm" variant="white-outline" :title="`${canOpenFile ? 'Ouvrir' : 'Télécharger'} ${catalog.original_name}`">
                                <component :is="canOpenFile ? ExternalLink : Download" class="h-4 w-4" />{{ canOpenFile ? 'Ouvrir le fichier' : 'Télécharger' }}
                            </Button>
                            <Button v-if="can.update && !catalog.is_active" size="sm" variant="white-outline" @click="activate(catalog)">Utiliser ce catalogue</Button>
                            <Button v-if="can.update" size="sm" variant="white-outline" @click="startEdit(catalog)"><Pencil class="h-4 w-4" />Modifier</Button>
                            <Button v-if="can.delete" size="sm" variant="white-outline" class="text-red-600" @click="archiving = catalog">Mettre à la corbeille</Button>
                        </template>
                        <Button v-else-if="can.restore" size="sm" variant="white-outline" @click="restore(catalog)">Restaurer</Button>
                    </div>
                </li>
            </ul>
            <EmptyState v-else icon="folder" title="Dossier vide" description="Aucun catalogue n’a encore été ajouté pour ce fournisseur." />

            <div v-if="archivedCount" class="border-t border-border px-5 py-3">
                <button type="button" class="text-sm font-semibold text-primary hover:underline" @click="showArchived = !showArchived">
                    {{ showArchived ? 'Masquer' : 'Afficher' }} {{ archivedCount }} catalogue{{ archivedCount > 1 ? 's' : '' }} archivé{{ archivedCount > 1 ? 's' : '' }}
                </button>
            </div>
        </section>

        <div v-if="editing" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="editing = null">
            <section class="w-full max-w-md rounded-xl bg-card p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="edit-catalog-title">
                <h2 id="edit-catalog-title" class="font-heading text-lg font-bold text-foreground">Modifier « {{ editing.original_name }} »</h2>
                <p class="mt-1 text-sm text-muted-foreground">Le fichier lui-même ne change pas : pour un nouveau tarif, importez un nouveau catalogue.</p>
                <form class="mt-4 space-y-4" @submit.prevent="saveEdit">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-foreground">Date du catalogue</span>
                        <DatePicker v-model="editForm.catalog_date" />
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-foreground">Remarque</span>
                        <input v-model="editForm.notes" type="text" maxlength="2000" class="h-11 w-full rounded-lg border border-border bg-card px-3 text-sm">
                    </label>
                    <p v-if="editForm.errors.catalog_date || editForm.errors.notes || editForm.errors.catalog || editForm.errors.site" class="text-xs text-red-600">{{ editForm.errors.catalog_date || editForm.errors.notes || editForm.errors.catalog || editForm.errors.site }}</p>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="editing = null">Annuler</Button>
                        <Button type="submit" size="rg" :disabled="editForm.processing">Enregistrer</Button>
                    </div>
                </form>
            </section>
        </div>

        <div v-if="archiving" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="archiving = null">
            <section class="w-full max-w-md rounded-xl bg-card p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="archive-catalog-title">
                <h2 id="archive-catalog-title" class="font-heading text-lg font-bold text-foreground">Mettre « {{ archiving.original_name }} » à la corbeille</h2>
                <p class="mt-1 text-sm text-muted-foreground">Le fichier reste dans le dossier et pourra être restauré.</p>
                <form class="mt-4 space-y-4" @submit.prevent="confirmArchive">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></span>
                        <textarea v-model="archiveForm.reason" required rows="3" class="block w-full rounded-lg border border-border bg-card px-3 py-2 text-sm" />
                        <span v-if="archiveForm.errors.reason" class="mt-1 block text-xs text-red-600">{{ archiveForm.errors.reason }}</span>
                    </label>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="archiving = null">Retour</Button>
                        <Button type="submit" size="rg" variant="danger" :disabled="archiveForm.processing">Mettre à la corbeille</Button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
