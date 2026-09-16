<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { Check, FileSpreadsheet, FileText, Search, TriangleAlert } from 'lucide-vue-next';
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
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);

const search = ref('');
const show = ref('ALL');
const linkedCount = computed(() => props.items.filter((item) => item.linked_medicine_name).length);
const filters = computed(() => [
    { value: 'ALL', label: 'Toutes les lignes', count: props.items.length },
    { value: 'LINKED', label: 'Dans la clinique', count: linkedCount.value },
    { value: 'TODO', label: 'Pas encore dans la clinique', count: props.items.length - linkedCount.value },
]);
const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return props.items.filter((item) => (show.value === 'ALL' || (show.value === 'LINKED') === Boolean(item.linked_medicine_name))
        && (!needle || [item.reference, item.medicine_label, item.presentation, item.linked_medicine_name].filter(Boolean).some((value) => value.toLocaleLowerCase().includes(needle))));
});
const total = computed(() => props.items.reduce((sum, item) => sum + (Number(item.supplier_price) || 0), 0));
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
                </div>

                <div v-if="visible.length" class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead class="bg-muted text-xs font-semibold text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 text-start">Ligne</th>
                                <th class="px-4 py-3 text-start">Référence</th>
                                <th class="px-4 py-3 text-start">Médicament (nom du fournisseur)</th>
                                <th class="px-4 py-3 text-start">Présentation</th>
                                <th class="px-4 py-3 text-end">Prix fournisseur</th>
                                <th class="px-5 py-3 text-start">Dans la clinique</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="item in visible" :key="item.uuid" class="hover:bg-accent/50">
                                <td class="px-4 py-3 tabular-nums text-muted-foreground">{{ item.row_number }}</td>
                                <td class="px-4 py-3 font-mono text-muted-foreground">{{ item.reference || '—' }}</td>
                                <td class="px-4 py-3 font-semibold text-foreground">{{ item.medicine_label }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ item.presentation || '—' }}</td>
                                <td class="px-4 py-3 text-end font-semibold tabular-nums text-foreground">{{ formatMoney(item.supplier_price) }}</td>
                                <td class="px-5 py-3">
                                    <span v-if="item.linked_medicine_name" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300"><Check class="h-4 w-4" />{{ item.linked_medicine_name }}</span>
                                    <span v-else class="text-xs text-amber-600">Pas encore ajouté</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else :icon="items.length ? 'search' : 'file-xls'" :title="items.length ? 'Aucune ligne trouvée' : 'Aucune ligne lue'" :description="items.length ? 'Modifiez la recherche ou le filtre.' : 'Ce fichier n’a pas encore été lu : utilisez « Lire les lignes » dans le dossier Catalogues.'" />
                <p v-if="items.length" class="border-t border-border px-5 py-3 text-xs text-muted-foreground">{{ items.length }} ligne(s) lue(s) dans le fichier · somme des prix unitaires {{ formatMoney(total) }}. Le nom affiché à la clinique est le nom standard du médicament rattaché.</p>
            </section>
        </template>
    </div>
</template>
