<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { Check, FileSpreadsheet, FileText, Link2Off, Pencil, RotateCcw, Search, Trash2, TriangleAlert } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/**
 * ADR-098 — the content of one catalog file, read on the site: every line the
 * Excel contained, and whether the clinic already stocks it under its own name.
 */
const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    catalog: { type: Object, default: null },
    items: { type: Array, default: () => [] },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);

const search = ref('');
const show = ref('ALL');
// La famille vient du fichier du fournisseur : c'est sa classification à
// lui, pas encore celle de la clinique (ADR-098).
const family = ref('');
// Une ligne retirée n'est plus une ligne du catalogue : elle ne compte ni
// dans le total ni dans les filtres, mais reste atteignable pour être
// restaurée (ADR-009).
const live = computed(() => props.items.filter((item) => !item.archived));
const trashed = computed(() => props.items.filter((item) => item.archived));
const linkedCount = computed(() => live.value.filter((item) => item.linked_medicine_name).length);
const filters = computed(() => [
    { value: 'ALL', label: 'Toutes les lignes', count: live.value.length },
    { value: 'LINKED', label: 'Dans la clinique', count: linkedCount.value },
    { value: 'TODO', label: 'Pas encore dans la clinique', count: live.value.length - linkedCount.value },
    ...(trashed.value.length ? [{ value: 'TRASHED', label: 'Dans la corbeille', count: trashed.value.length }] : []),
]);
const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();
    const pool = show.value === 'TRASHED' ? trashed.value : live.value;

    return pool.filter((item) => (show.value === 'ALL' || show.value === 'TRASHED' || (show.value === 'LINKED') === Boolean(item.linked_medicine_name))
        && (!family.value || item.family_label === family.value)
        && (!needle || [item.reference, item.medicine_label, item.presentation, item.family_label, item.linked_medicine_name].filter(Boolean).some((value) => value.toLocaleLowerCase().includes(needle))));
});
const total = computed(() => live.value.reduce((sum, item) => sum + (Number(item.supplier_price) || 0), 0));

const families = computed(() => [...new Set(live.value.map((item) => item.family_label).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr')));

const itemUrl = (item, suffix = '') => `${folderHref.value}/catalogs/${props.catalog?.uuid}/items/${item.uuid}${suffix}`;

// Corriger une erreur de transcription : ce que le fichier disait, pas ce
// que la clinique a décidé. Le médicament déjà créé garde son nom.
const editing = ref(null);
const editForm = useForm({ reference: '', medicine_label: '', presentation: '', family_label: '', supplier_price: '' });
const startEdit = (item) => {
    editForm.defaults({
        reference: item.reference ?? '',
        medicine_label: item.medicine_label ?? '',
        presentation: item.presentation ?? '',
        family_label: item.family_label ?? '',
        supplier_price: item.supplier_price ?? '',
    });
    editForm.reset();
    editForm.clearErrors();
    editing.value = item;
};
const saveEdit = () => editForm.put(itemUrl(editing.value), { preserveScroll: true, onSuccess: () => { editing.value = null; } });

const removing = ref(null);
const removeForm = useForm({ reason: '' });
const askRemove = (item) => { removeForm.reset(); removeForm.clearErrors(); removing.value = item; };
const confirmRemove = () => removeForm.delete(itemUrl(removing.value), { preserveScroll: true, onSuccess: () => { removing.value = null; } });

const restore = (item) => useForm({}).post(itemUrl(item, '/restore'), { preserveScroll: true });

// Défaire un rattachement erroné clôt le prix qu'il avait créé : la
// confirmation le dit, parce que ce n'est pas une simple déliaison.
const unlinking = ref(null);
const unlinkForm = useForm({});
const unlink = () => unlinkForm.post(itemUrl(unlinking.value, '/unlink'), {
    preserveScroll: true,
    onSuccess: () => { unlinking.value = null; },
});
</script>

<template>
    <Head :title="catalog ? `Contenu · ${catalog.original_name}` : 'Contenu du catalogue'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Catalogues', href: supplier ? `${folderHref}/catalogs` : null },
            { label: catalog?.original_name ?? 'Contenu' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" /><p>{{ error }}</p>
        </section>

        <template v-else-if="catalog">
            <section class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <component :is="catalog.kind === 'EXCEL' ? FileSpreadsheet : FileText" :class="cn('h-11 w-11 shrink-0', catalog.kind === 'EXCEL' ? 'text-emerald-500' : 'text-red-500')" />
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="truncate font-heading text-xl font-bold text-foreground">{{ catalog.original_name }}</h1>
                                <Badge v-if="catalog.is_active" tone="success" dot>Catalogue actuel</Badge>
                                <Badge v-if="catalog.archived" tone="neutral">Archivé</Badge>
                            </div>
                            <p class="mt-0.5 text-sm text-muted-foreground">
                                {{ supplier?.name }} · {{ targetSite.name }}
                                <span v-if="catalog.catalog_date"> · daté du {{ formatDate(catalog.catalog_date) }}</span>
                                <span> · ajouté le {{ formatDate(catalog.created_at) }}</span><span v-if="catalog.creator"> par {{ catalog.creator }}</span>
                            </p>
                            <p v-if="catalog.notes" class="mt-0.5 text-sm text-muted-foreground">{{ catalog.notes }}</p>
                        </div>
                    </div>
                    <dl class="grid shrink-0 grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-muted px-4 py-2"><dt class="text-xs text-muted-foreground">Lignes</dt><dd class="text-xl font-bold tabular-nums text-foreground">{{ items.length }}</dd></div>
                        <div class="rounded-lg bg-emerald-50 px-4 py-2 dark:bg-emerald-950/30"><dt class="text-xs text-emerald-700 dark:text-emerald-300">Dans la clinique</dt><dd class="text-xl font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ linkedCount }}</dd></div>
                        <div class="rounded-lg bg-amber-50 px-4 py-2 dark:bg-amber-950/30"><dt class="text-xs text-amber-700 dark:text-amber-300">À ajouter</dt><dd class="text-xl font-bold tabular-nums text-amber-700 dark:text-amber-300">{{ items.length - linkedCount }}</dd></div>
                    </dl>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <div class="flex flex-col gap-3 border-b border-border px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex max-w-full gap-1 overflow-x-auto">
                        <button
                            v-for="filter in filters"
                            :key="filter.value"
                            type="button"
                            :class="['inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition', show === filter.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted ']"
                            @click="show = filter.value"
                        >{{ filter.label }} <span class="opacity-70">{{ filter.count }}</span></button>
                    </div>
                    <label class="relative block lg:w-80">
                        <span class="sr-only">Rechercher une ligne</span>
                        <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto text-muted-foreground h-4.5 w-4.5" />
                        <input v-model="search" type="search" class="h-9 w-full rounded-lg border border-border bg-muted ps-10 pe-3 text-sm outline-none focus:border-primary focus:bg-card" placeholder="Référence, nom, présentation…">
                    </label>
                    <select v-if="families.length" v-model="family" class="h-9 rounded-lg border border-border bg-muted px-3 text-sm text-foreground outline-none focus:border-primary focus:bg-card">
                        <option value="">Toutes les familles</option>
                        <option v-for="name in families" :key="name" :value="name">{{ name }}</option>
                    </select>
                </div>

                <div v-if="visible.length" class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead class="bg-muted text-xs font-semibold text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 text-start">Ligne</th>
                                <th class="px-4 py-3 text-start">Référence</th>
                                <th class="px-4 py-3 text-start">Médicament (nom du fournisseur)</th>
                                <th class="px-4 py-3 text-start">Présentation</th>
                                <th class="px-4 py-3 text-start">Famille</th>
                                <th class="px-4 py-3 text-end">Prix fournisseur</th>
                                <th class="px-5 py-3 text-start">Dans la clinique</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="item in visible" :key="item.uuid" class="hover:bg-accent/50">
                                <td class="px-4 py-3 tabular-nums text-muted-foreground">{{ item.row_number }}</td>
                                <td class="px-4 py-3 font-mono text-muted-foreground">{{ item.reference || '—' }}</td>
                                <td class="px-4 py-3 font-semibold text-foreground">{{ item.medicine_label }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ item.presentation || '—' }}</td>
                                <td class="px-4 py-3">
                                    <Badge v-if="item.family_label" tone="neutral">{{ item.family_label }}</Badge>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3 text-end font-semibold tabular-nums text-foreground">{{ formatMoney(item.supplier_price) }}</td>
                                <td class="px-5 py-3">
                                    <span v-if="item.archived" class="text-xs text-muted-foreground">Retirée{{ item.delete_reason ? ` · ${item.delete_reason}` : '' }}</span>
                                    <span v-else-if="item.linked_medicine_name" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300"><Check class="h-4 w-4" />{{ item.linked_medicine_name }}</span>
                                    <span v-else class="text-xs text-amber-600">Pas encore ajouté</span>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <template v-if="!item.archived">
                                            <Button v-if="can.unlink && item.linked_medicine_name" size="sm" variant="white-outline" type="button" :title="`Défaire le rattachement de ${item.medicine_label}`" @click="unlinking = item"><Link2Off class="h-4 w-4" /></Button>
                                            <Button v-if="can.update" size="sm" variant="white-outline" type="button" :title="`Corriger ${item.medicine_label}`" @click="startEdit(item)"><Pencil class="h-4 w-4" /></Button>
                                            <Button v-if="can.delete" size="sm" variant="white-outline" type="button" class="text-red-600" :title="`Mettre ${item.medicine_label} à la corbeille`" @click="askRemove(item)"><Trash2 class="h-4 w-4" /></Button>
                                        </template>
                                        <Button v-else-if="can.restore" size="sm" variant="white-outline" type="button" :title="`Restaurer ${item.medicine_label}`" @click="restore(item)"><RotateCcw class="h-4 w-4" /></Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else :icon="items.length ? 'search' : 'file-xls'" :title="items.length ? 'Aucune ligne trouvée' : 'Aucune ligne lue'" :description="items.length ? 'Modifiez la recherche ou le filtre.' : 'Ce fichier n’a pas encore été lu : utilisez « Lire les lignes » dans le dossier Catalogues.'" />
                <p v-if="items.length" class="border-t border-border px-5 py-3 text-xs text-muted-foreground">{{ items.length }} ligne(s) lue(s) dans le fichier · somme des prix unitaires {{ formatMoney(total) }}. Le nom affiché à la clinique est le nom standard du médicament rattaché.</p>
            </section>
        </template>

        <Dialog
            :open="editing !== null"
            title="Corriger la ligne de catalogue"
            description="Ce que le fichier du fournisseur disait. Le médicament déjà créé à la clinique garde son nom et son code."
            @update:open="editing = $event ? editing : null"
        >
            <div class="space-y-4">
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Référence <span class="text-red-500">*</span></span>
                    <Input v-model="editForm.reference" required />
                    <p v-if="editForm.errors.reference" class="mt-1.5 text-sm text-destructive">{{ editForm.errors.reference }}</p>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Médicament <span class="text-red-500">*</span></span>
                    <Input v-model="editForm.medicine_label" required />
                    <p v-if="editForm.errors.medicine_label" class="mt-1.5 text-sm text-destructive">{{ editForm.errors.medicine_label }}</p>
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-foreground">Présentation</span>
                        <Input v-model="editForm.presentation" />
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-foreground">Famille</span>
                        <Input v-model="editForm.family_label" list="catalogue-familles" />
                        <datalist id="catalogue-familles"><option v-for="name in families" :key="name" :value="name" /></datalist>
                        <p class="mt-1.5 text-xs text-muted-foreground">Telle que le fournisseur la nomme.</p>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-foreground">Prix fournisseur</span>
                        <Input v-model="editForm.supplier_price" type="number" min="0" step="0.01" />
                        <p v-if="editForm.errors.supplier_price" class="mt-1.5 text-sm text-destructive">{{ editForm.errors.supplier_price }}</p>
                        <p v-else class="mt-1.5 text-xs text-muted-foreground">Laissez vide si le catalogue n’en porte pas.</p>
                    </label>
                </div>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="editing = null">Annuler</Button>
                <Button type="button" variant="primary" :disabled="editForm.processing" @click="saveEdit"><Check class="h-4 w-4" />Enregistrer</Button>
            </template>
        </Dialog>

        <Dialog
            :open="removing !== null"
            title="Mettre la ligne à la corbeille"
            :description="removing ? `« ${removing.medicine_label} » quittera le catalogue. Ce n’est pas une suppression : la ligne se restaure, et le prix qu’elle a déjà créé reste.` : ''"
            @update:open="removing = $event ? removing : null"
        >
            <Textarea v-model="removeForm.reason" :rows="3" placeholder="Motif du retrait" />
            <p v-if="removeForm.errors.reason" class="mt-1.5 text-sm text-destructive">{{ removeForm.errors.reason }}</p>
            <template #footer>
                <Button type="button" variant="outline" @click="removing = null">Revenir</Button>
                <Button type="button" variant="destructive" :disabled="removeForm.processing || removeForm.reason.trim().length < 3" @click="confirmRemove"><Trash2 class="h-4 w-4" />Mettre à la corbeille</Button>
            </template>
        </Dialog>
    </div>
    <ConfirmModal
        :open="Boolean(unlinking)"
        title="Défaire ce rattachement ?"
        description="Le prix d’achat que ce rattachement avait créé est clos, jamais supprimé : la clinique a réellement cru ce prix, et l’audit doit continuer de le dire."
        confirm-label="Défaire le rattachement"
        tone="warning"
        :processing="unlinkForm.processing"
        @update:open="(value) => { if (!value) unlinking = null; }"
        @confirm="unlink"
    >
        <p v-if="unlinking" class="rounded-xl border border-border px-4 py-3 text-sm">
            <span class="font-semibold text-foreground">{{ unlinking.medicine_label }}</span>
            <span class="block text-xs text-muted-foreground">rattaché à « {{ unlinking.linked_medicine_name }} »</span>
        </p>
    </ConfirmModal>

</template>
