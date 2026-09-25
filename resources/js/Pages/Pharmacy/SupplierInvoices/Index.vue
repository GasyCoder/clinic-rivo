<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import { Paperclip, Pencil, Plus, Search } from 'lucide-vue-next';
import PurchasesHeader from '@/Components/Pharmacy/PurchasesHeader.vue';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';

defineOptions({ layout: AppLayout });

const props = defineProps({ invoices: Object, filters: Object, can: Object, purchases: Object });

const search = ref(props.filters?.q ?? '');
const submitSearch = () => router.get(pharmacyUrl('/pharmacy/supplier-invoices'), {
    q: search.value || undefined,
    supplier: props.filters?.supplier || undefined,
}, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Factures fournisseurs" />

    <div class="w-full space-y-5">
        <PurchasesHeader active="invoices" :purchases="purchases">
            <template #actions>
                <Button v-if="can.create" :as="Link" :href="pharmacyUrl('/pharmacy/supplier-invoices/create')" size="rg">
                    <Plus class="h-4 w-4" /><span class="ms-2">Enregistrer une facture</span>
                </Button>
            </template>
        </PurchasesHeader>

        <div v-if="filters?.supplier_name" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-primary-200 bg-primary-50/60 px-4 py-3 text-sm dark:border-primary-900 dark:bg-primary-950/20">
            <span class="text-primary-900 dark:text-primary-100">Factures du fournisseur <strong>{{ filters.supplier_name }}</strong></span>
            <span class="flex gap-4">
                <Link :href="pharmacyUrl(`/pharmacy/suppliers/${filters.supplier}`)" class="font-semibold text-primary-700 hover:underline dark:text-primary-300">Ouvrir son dossier</Link>
                <Link :href="pharmacyUrl('/pharmacy/supplier-invoices')" class="font-semibold text-primary-700 hover:underline dark:text-primary-300">Toutes les factures</Link>
            </span>
        </div>

        <ExplorerView storage-key="pharmacy-invoices"
            :count="invoices.data.length"
            count-label="facture"
            empty-icon="file-text"
            empty-title="Aucune facture"
            empty-description="Les factures des fournisseurs apparaîtront ici une fois enregistrées."
        >
            <template #toolbar>
                <form class="flex w-full gap-2 sm:w-auto" @submit.prevent="submitSearch">
                    <label class="relative block flex-1 sm:w-72">
                        <span class="sr-only">Rechercher une facture</span>
                        <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto text-slate-400 h-4 w-4" />
                        <input v-model="search" type="search" class="h-9 w-full rounded-lg border border-gray-200 bg-gray-50 ps-10 pe-3 text-sm outline-none focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-900 dark:text-white" placeholder="Numéro ou fournisseur…">
                    </label>
                    <Button type="submit" size="sm" variant="white-outline">Rechercher</Button>
                </form>
            </template>

            <template #grid>
                <ExplorerTile v-for="invoice in invoices.data"
                    :key="invoice.uuid"
                    :href="pharmacyUrl(`/pharmacy/supplier-invoices/${invoice.uuid}`)"
                    icon="file-text"
                    tone="violet"
                    :badge="invoice.has_attachment ? 'Document' : null"
                    :title="invoice.invoice_number"
                    :subtitle="invoice.supplier"
                    :highlight="formatMoney(invoice.total_amount)"
                    :meta="formatDate(invoice.invoice_date)"
                >
                    <template v-if="can.update || invoice.has_attachment" #actions>
                        <Button v-if="invoice.has_attachment" as="a" :href="pharmacyUrl(`/pharmacy/supplier-invoices/${invoice.uuid}/attachment`)" target="_blank" size="sm" variant="white-outline" title="Ouvrir le document"><Paperclip class="h-4 w-4" /></Button>
                        <Button v-if="can.update" :as="Link" :href="pharmacyUrl(`/pharmacy/supplier-invoices/${invoice.uuid}/edit`)" size="sm" variant="white-outline" title="Modifier"><Pencil class="h-4 w-4" /></Button>
                    </template>
                </ExplorerTile>
            </template>

            <template #list>
                <table class="w-full min-w-[680px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Facture</th>
                            <th class="px-4 py-3 text-start">Fournisseur</th>
                            <th class="px-4 py-3 text-start">Date</th>
                            <th class="px-4 py-3 text-end">Montant</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="invoice in invoices.data" :key="invoice.uuid" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/40">
                            <td class="px-5 py-3.5 font-mono font-semibold text-slate-800 dark:text-white">{{ invoice.invoice_number }}</td>
                            <td class="px-4 py-3.5 text-slate-700 dark:text-slate-200">{{ invoice.supplier }}</td>
                            <td class="px-4 py-3.5 text-slate-500">{{ formatDate(invoice.invoice_date) }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ formatMoney(invoice.total_amount) }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                    <Button :as="Link" :href="pharmacyUrl(`/pharmacy/supplier-invoices/${invoice.uuid}`)" size="sm" variant="white-outline">Voir</Button>
                                    <Button v-if="can.update" :as="Link" :href="pharmacyUrl(`/pharmacy/supplier-invoices/${invoice.uuid}/edit`)" size="sm" variant="white-outline" :title="`Modifier ${invoice.invoice_number}`"><Pencil class="h-4 w-4" /></Button>
                                    <Button v-if="invoice.has_attachment" as="a" :href="pharmacyUrl(`/pharmacy/supplier-invoices/${invoice.uuid}/attachment`)" target="_blank" size="sm" variant="white-outline" title="Ouvrir le document"><Paperclip class="h-4 w-4" /></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </ExplorerView>
    </div>
</template>
