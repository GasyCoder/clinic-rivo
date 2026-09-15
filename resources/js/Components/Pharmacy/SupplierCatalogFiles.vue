<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
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
    // The portal never streams a clinic's file: only the site can open it.
    canOpenFile: { type: Boolean, default: true },
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
const view = ref(readView());
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
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950" role="group" aria-label="Affichage des catalogues">
                    <button
                        v-for="option in [{ value: 'grid', icon: 'grid-alt', label: 'Grandes icônes' }, { value: 'list', icon: 'list', label: 'Liste' }]"
                        :key="option.value"
                        type="button"
                        :title="option.label"
                        :aria-pressed="view === option.value"
                        :class="['inline-flex h-9 items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition', view === option.value ? 'bg-slate-700 text-white dark:bg-white dark:text-slate-800' : 'text-slate-500 hover:text-slate-700 dark:hover:text-white']"
                        @click="setView(option.value)"
                    >
                        <Icon :name="option.icon" /><span class="hidden sm:inline">{{ option.label }}</span>
                    </button>
                </div>
                <span class="text-sm text-slate-500">{{ visible.length }} fichier{{ visible.length > 1 ? 's' : '' }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="templateUrl" as="a" :href="templateUrl" size="rg" variant="white-outline" title="Fichier Excel vide avec les colonnes attendues">
                    <Icon name="download" /><span class="ms-2">Télécharger le canevas Excel</span>
                </Button>
                <Button v-if="can.create" size="rg" @click="uploading = !uploading">
                    <Icon :name="uploading ? 'cross' : 'upload'" /><span class="ms-2">{{ uploading ? 'Fermer' : 'Importer un catalogue' }}</span>
                </Button>
            </div>
        </div>

        <form v-if="uploading" class="space-y-4 rounded-xl border border-primary-200 bg-white p-5 shadow-sm dark:border-primary-900 dark:bg-gray-950" @submit.prevent="submitUpload">
            <div>
                <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Importer un catalogue</h2>
                <p class="mt-1 text-sm text-slate-500">Fichier Excel ou PDF. Un fichier Excel pourra ensuite être lu ligne par ligne s’il contient les colonnes <strong>Référence</strong>, <strong>Médicament</strong>, <strong>Présentation</strong> et <strong>Prix fournisseur</strong>.</p>
            </div>
            <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 px-4 py-8 text-center transition hover:border-primary-400 dark:border-gray-700">
                <Icon :name="uploadForm.file ? 'file-check' : 'upload-cloud'" class="text-4xl text-slate-400" />
                <span class="text-sm font-semibold text-slate-700 dark:text-white">{{ uploadForm.file ? uploadForm.file.name : 'Choisir un fichier' }}</span>
                <span class="text-xs text-slate-400">.xlsx, .xls ou .pdf — 10 Mo au maximum</span>
                <input type="file" accept=".xlsx,.xls,.pdf" class="sr-only" @change="uploadForm.file = $event.target.files[0]">
            </label>
            <p v-if="uploadForm.errors.file || uploadForm.errors.site" class="text-sm text-red-600">{{ uploadForm.errors.file || uploadForm.errors.site }}</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date du catalogue</span>
                    <input v-model="uploadForm.catalog_date" type="date" class="h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Remarque</span>
                    <input v-model="uploadForm.notes" type="text" class="h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Ex. tarif valable jusqu’en décembre">
                </label>
            </div>
            <div class="flex justify-end gap-2">
                <Button type="button" size="rg" variant="white-outline" @click="uploading = false">Annuler</Button>
                <Button type="submit" size="rg" :disabled="uploadForm.processing || !uploadForm.file"><Icon name="upload" /><span class="ms-2">Ajouter au dossier</span></Button>
            </div>
        </form>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div v-if="visible.length && view === 'grid'" class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                <div
                    v-for="catalog in visible"
                    :key="catalog.uuid"
                    :class="['group relative flex flex-col items-center rounded-xl border p-4 text-center transition', selected === catalog.uuid ? 'border-primary-400 bg-primary-50/60 dark:border-primary-700 dark:bg-primary-950/20' : 'border-transparent hover:border-gray-200 hover:bg-gray-50 dark:hover:border-gray-800 dark:hover:bg-gray-900/40', catalog.archived && 'opacity-60']"
                >
                    <button type="button" class="flex w-full flex-col items-center" :aria-expanded="selected === catalog.uuid" @click="toggleSelected(catalog)">
                        <span class="relative">
                            <Icon :name="catalog.kind === 'EXCEL' ? 'file-xls' : 'file-pdf'" :class="['text-6xl leading-none', catalog.archived ? 'text-slate-300' : (catalog.kind === 'EXCEL' ? 'text-emerald-500' : 'text-red-500')]" />
                            <span v-if="catalog.is_active" class="absolute -end-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-white ring-2 ring-white dark:ring-gray-950" title="Catalogue actuel"><Icon name="check" class="text-xs" /></span>
                        </span>
                        <span class="mt-2 line-clamp-2 break-all text-sm font-semibold text-slate-800 dark:text-white">{{ catalog.original_name }}</span>
                        <span class="mt-0.5 text-xs text-slate-500">{{ catalog.catalog_date ? formatDate(catalog.catalog_date) : formatDate(catalog.created_at) }} · {{ formatSize(catalog.size) }}</span>
                        <span v-if="catalog.kind === 'EXCEL'" :class="['mt-0.5 text-xs', catalog.is_imported ? 'text-emerald-600' : 'text-amber-600']">{{ catalog.is_imported ? `${catalog.items_count} lignes lues` : 'Pas encore lu' }}</span>
                        <Badge v-if="catalog.archived" tone="neutral" class="mt-1">Archivé</Badge>
                    </button>

                    <div v-if="selected === catalog.uuid" class="mt-3 flex w-full flex-col gap-1.5">
                        <template v-if="!catalog.archived">
                            <Button v-if="catalog.kind === 'EXCEL' && can.create" :as="Link" :href="`${baseUrl}/${catalog.uuid}/import`" size="sm" :variant="catalog.is_imported ? 'white-outline' : 'primary'" class="justify-center">{{ catalog.is_imported ? 'Relire les lignes' : 'Lire les lignes' }}</Button>
                            <Button v-if="canBrowseItems && catalog.kind === 'EXCEL' && catalog.is_imported" :as="Link" :href="itemsHref(catalog)" size="sm" variant="white-outline" class="justify-center">Voir les produits</Button>
                            <Button v-if="canOpenFile" as="a" :href="`${baseUrl}/${catalog.uuid}`" target="_blank" size="sm" variant="white-outline" class="justify-center">Ouvrir le fichier</Button>
                            <Button v-if="can.update && !catalog.is_active" size="sm" variant="white-outline" class="justify-center" @click="activate(catalog)">Utiliser ce catalogue</Button>
                            <Button v-if="can.update" size="sm" variant="white-outline" class="justify-center" @click="startEdit(catalog)">Modifier</Button>
                            <Button v-if="can.delete" size="sm" variant="white-outline" class="justify-center text-red-600" @click="archiving = catalog">Archiver</Button>
                        </template>
                        <Button v-else-if="can.restore" size="sm" variant="white-outline" class="justify-center" @click="restore(catalog)">Restaurer</Button>
                    </div>
                </div>
            </div>

            <ul v-else-if="visible.length" class="divide-y divide-gray-100 dark:divide-gray-900">
                <li v-for="catalog in visible" :key="catalog.uuid" :class="['flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between', catalog.archived && 'bg-gray-50/70 dark:bg-gray-1000/40']">
                    <div class="flex min-w-0 items-center gap-4">
                        <Icon :name="catalog.kind === 'EXCEL' ? 'file-xls' : 'file-pdf'" :class="['text-4xl leading-none', catalog.archived ? 'text-slate-300' : (catalog.kind === 'EXCEL' ? 'text-emerald-500' : 'text-red-500')]" />
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link v-if="canBrowseItems && catalog.kind === 'EXCEL' && catalog.is_imported" :href="itemsHref(catalog)" class="truncate font-semibold text-slate-800 hover:text-primary-600 dark:text-white">{{ catalog.original_name }}</Link>
                                <span v-else class="truncate font-semibold text-slate-800 dark:text-white">{{ catalog.original_name }}</span>
                                <Badge v-if="catalog.is_active" tone="success" dot>Catalogue actuel</Badge>
                                <Badge v-if="catalog.archived" tone="neutral">Archivé</Badge>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ catalog.kind_label }} · {{ formatSize(catalog.size) }}
                                <span v-if="catalog.catalog_date"> · daté du {{ formatDate(catalog.catalog_date) }}</span>
                                <span> · ajouté le {{ formatDate(catalog.created_at) }}</span>
                                <span v-if="catalog.creator"> par {{ catalog.creator }}</span>
                            </p>
                            <p v-if="catalog.kind === 'EXCEL'" class="mt-0.5 text-xs" :class="catalog.is_imported ? 'text-emerald-600' : 'text-amber-600'">
                                {{ catalog.is_imported ? `${catalog.items_count} ligne${catalog.items_count > 1 ? 's' : ''} lue${catalog.items_count > 1 ? 's' : ''}` : 'Lignes pas encore lues' }}
                            </p>
                            <p v-if="catalog.archived && catalog.delete_reason" class="mt-0.5 text-xs text-slate-400">Motif : {{ catalog.delete_reason }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        <template v-if="!catalog.archived">
                            <Button v-if="catalog.kind === 'EXCEL' && can.create" :as="Link" :href="`${baseUrl}/${catalog.uuid}/import`" size="sm" :variant="catalog.is_imported ? 'white-outline' : 'primary'">
                                <Icon name="list-check" /><span class="ms-1.5">{{ catalog.is_imported ? 'Relire les lignes' : 'Lire les lignes' }}</span>
                            </Button>
                            <Button v-if="canBrowseItems && catalog.kind === 'EXCEL' && catalog.is_imported" :as="Link" :href="itemsHref(catalog)" size="sm" variant="white-outline">
                                <Icon name="eye" /><span class="ms-1.5">Voir les produits</span>
                            </Button>
                            <Button v-if="canOpenFile" as="a" :href="`${baseUrl}/${catalog.uuid}`" target="_blank" size="sm" variant="white-outline" :title="`Ouvrir ${catalog.original_name}`">
                                <Icon name="external" /><span class="ms-1.5">Ouvrir le fichier</span>
                            </Button>
                            <Button v-if="can.update && !catalog.is_active" size="sm" variant="white-outline" @click="activate(catalog)">Utiliser ce catalogue</Button>
                            <Button v-if="can.update" size="sm" variant="white-outline" @click="startEdit(catalog)"><Icon name="edit" /><span class="ms-1.5">Modifier</span></Button>
                            <Button v-if="can.delete" size="sm" variant="white-outline" class="text-red-600" @click="archiving = catalog">Archiver</Button>
                        </template>
                        <Button v-else-if="can.restore" size="sm" variant="white-outline" @click="restore(catalog)">Restaurer</Button>
                    </div>
                </li>
            </ul>
            <EmptyState v-else icon="folder" title="Dossier vide" description="Aucun catalogue n’a encore été ajouté pour ce fournisseur." />

            <div v-if="archivedCount" class="border-t border-gray-100 px-5 py-3 dark:border-gray-900">
                <button type="button" class="text-sm font-semibold text-primary-600 hover:underline" @click="showArchived = !showArchived">
                    {{ showArchived ? 'Masquer' : 'Afficher' }} {{ archivedCount }} catalogue{{ archivedCount > 1 ? 's' : '' }} archivé{{ archivedCount > 1 ? 's' : '' }}
                </button>
            </div>
        </section>

        <div v-if="editing" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="editing = null">
            <section class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="edit-catalog-title">
                <h2 id="edit-catalog-title" class="font-heading text-lg font-bold text-slate-800 dark:text-white">Modifier « {{ editing.original_name }} »</h2>
                <p class="mt-1 text-sm text-slate-500">Le fichier lui-même ne change pas : pour un nouveau tarif, importez un nouveau catalogue.</p>
                <form class="mt-4 space-y-4" @submit.prevent="saveEdit">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date du catalogue</span>
                        <input v-model="editForm.catalog_date" type="date" class="h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Remarque</span>
                        <input v-model="editForm.notes" type="text" maxlength="2000" class="h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white">
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
            <section class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="archive-catalog-title">
                <h2 id="archive-catalog-title" class="font-heading text-lg font-bold text-slate-800 dark:text-white">Archiver « {{ archiving.original_name }} »</h2>
                <p class="mt-1 text-sm text-slate-500">Le fichier reste dans le dossier et pourra être restauré.</p>
                <form class="mt-4 space-y-4" @submit.prevent="confirmArchive">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></span>
                        <textarea v-model="archiveForm.reason" required rows="3" class="block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" />
                        <span v-if="archiveForm.errors.reason" class="mt-1 block text-xs text-red-600">{{ archiveForm.errors.reason }}</span>
                    </label>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="archiving = null">Retour</Button>
                        <Button type="submit" size="rg" variant="danger" :disabled="archiveForm.processing">Archiver</Button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
