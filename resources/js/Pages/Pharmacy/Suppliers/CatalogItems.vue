<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { Check, FileSpreadsheet, Plus, Search } from 'lucide-vue-next';
import { formatMoney } from '@/utilities/pharmacyStatus';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';

defineOptions({ layout: AppLayout });

const props = defineProps({
    supplier: { type: Object, required: true },
    catalog: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    medicines: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const search = ref('');
const show = ref('ALL');

const linkedCount = computed(() => props.items.filter((item) => item.linked_medicine_uuid).length);
const filters = computed(() => [
    { value: 'ALL', label: 'Toutes les lignes', count: props.items.length },
    { value: 'TODO', label: 'Pas encore dans la clinique', count: props.items.length - linkedCount.value },
    { value: 'LINKED', label: 'Déjà dans la clinique', count: linkedCount.value },
]);

const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return props.items.filter((item) => {
        const matchesState = show.value === 'ALL'
            || (show.value === 'LINKED' ? Boolean(item.linked_medicine_uuid) : !item.linked_medicine_uuid);
        const matchesSearch = !needle || [item.reference, item.medicine_label, item.presentation]
            .filter(Boolean)
            .some((value) => value.toLocaleLowerCase().includes(needle));

        return matchesState && matchesSearch;
    });
});

const linkForms = reactive({});
const linkForm = (item) => {
    linkForms[item.uuid] ??= useForm({ medicine_uuid: '', change_reason: `Catalogue fournisseur ${props.catalog.original_name}` });

    return linkForms[item.uuid];
};
const submitLink = (item) => linkForm(item).post(
    pharmacyUrl(`/pharmacy/suppliers/${props.supplier.uuid}/catalog-items/${item.uuid}/link`),
    { preserveScroll: true },
);

const addToCatalogHref = (item) => pharmacyUrl(`/pharmacy/medicines/create?${new URLSearchParams({
    supplier_catalog_item: item.uuid,
    name: item.medicine_label,
}).toString()}`);
</script>

<template>
    <Head :title="`${catalog.original_name} · ${supplier.name}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs', href: pharmacyUrl('/pharmacy/suppliers') },
            { label: supplier.name, href: pharmacyUrl(`/pharmacy/suppliers/${supplier.uuid}`) },
            { label: 'Catalogues', href: pharmacyUrl(`/pharmacy/suppliers/${supplier.uuid}/catalogs`) },
            { label: catalog.original_name },
        ]" />

        <div class="flex items-start gap-3">
            <FileSpreadsheet class="text-4xl leading-none text-emerald-500 h-4 w-4" />
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">{{ catalog.original_name }}</h1>
                    <Badge v-if="catalog.is_active" tone="success" dot>Catalogue actuel</Badge>
                    <Badge v-if="catalog.archived" tone="neutral">Archivé</Badge>
                </div>
                <p class="mt-1 text-sm text-slate-500">Ce que {{ supplier.name }} propose. Ces lignes n’entrent pas dans le stock : ajoutez au catalogue de la clinique uniquement les médicaments que vous choisissez de proposer.</p>
            </div>
        </div>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex max-w-full gap-1.5 overflow-x-auto">
                    <button
                        v-for="filter in filters"
                        :key="filter.value"
                        type="button"
                        :class="['inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-semibold transition', show === filter.value ? 'border-slate-700 bg-slate-700 text-white dark:border-white dark:bg-white dark:text-slate-800' : 'border-gray-200 text-slate-600 hover:border-slate-300 dark:border-gray-800 dark:text-slate-300']"
                        @click="show = filter.value"
                    >
                        {{ filter.label }}
                        <span :class="['rounded-full px-1.5 text-xs', show === filter.value ? 'bg-white/20' : 'bg-gray-100 dark:bg-gray-900']">{{ filter.count }}</span>
                    </button>
                </div>
                <label class="relative block lg:w-80">
                    <span class="sr-only">Rechercher un produit</span>
                    <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto text-slate-400 h-4 w-4" />
                    <input v-model="search" type="search" class="h-10 w-full rounded-lg border border-gray-200 bg-gray-50 ps-10 pe-3 text-sm outline-none focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-900 dark:text-white" placeholder="Référence ou nom…">
                </label>
            </div>

            <div v-if="visible.length" class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Produit proposé</th>
                            <th class="px-4 py-3 text-end">Prix fournisseur</th>
                            <th class="px-5 py-3 text-start">Actions · dans la clinique</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="item in visible" :key="item.uuid">
                            <td class="px-5 py-3.5">
                                <p class="font-semibold text-slate-800 dark:text-white">{{ item.medicine_label }}</p>
                                <p class="mt-0.5 text-xs text-slate-400"><span v-if="item.reference" class="font-mono">{{ item.reference }}</span><span v-if="item.reference && item.presentation"> · </span>{{ item.presentation }}</p>
                            </td>
                            <td class="px-4 py-3.5 text-end font-semibold tabular-nums text-slate-800 dark:text-white">{{ formatMoney(item.supplier_price) }}</td>
                            <td class="px-5 py-3.5">
                                <Badge v-if="item.linked_medicine_uuid" tone="success"><Check class="h-4 w-4" />{{ item.linked_medicine_name }}</Badge>
                                <div v-else-if="can.link || can.add_to_catalog" class="flex flex-wrap items-center gap-2">
                                    <form v-if="can.link && medicines.length" class="flex items-center gap-1.5" @submit.prevent="submitLink(item)">
                                        <select v-model="linkForm(item).medicine_uuid" required class="h-9 max-w-56 rounded-lg border border-gray-200 bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" :aria-label="`Médicament de la clinique correspondant à ${item.medicine_label}`">
                                            <option value="">C’est le même que…</option>
                                            <option v-for="medicine in medicines" :key="medicine.uuid" :value="medicine.uuid">{{ medicine.name }}</option>
                                        </select>
                                        <button type="submit" class="h-9 rounded-lg bg-primary-600 px-3 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" :disabled="linkForm(item).processing || !linkForm(item).medicine_uuid">Rattacher</button>
                                    </form>
                                    <Link v-if="can.add_to_catalog" :href="addToCatalogHref(item)" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-gray-200 px-3 text-sm font-semibold text-slate-700 hover:border-primary-400 hover:text-primary-600 dark:border-gray-800 dark:text-slate-200">
                                        <Plus class="h-4 w-4" />Ajouter au catalogue
                                    </Link>
                                    <p v-if="linkForm(item).errors.medicine_uuid || linkForm(item).errors.quoted_price" class="w-full text-xs text-red-600">{{ linkForm(item).errors.medicine_uuid || linkForm(item).errors.quoted_price }}</p>
                                </div>
                                <span v-else class="text-sm text-slate-400">Pas encore</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="list" title="Aucune ligne" description="Modifiez la recherche ou le filtre." />
        </section>
    </div>
</template>
