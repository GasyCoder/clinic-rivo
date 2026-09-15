<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/UI/Button.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    invoices: { type: Array, default: () => [] },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);
</script>

<template>
    <Head :title="supplier ? `Factures · ${supplier.name}` : 'Factures'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Factures' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <Icon name="alert" class="mt-0.5 text-lg" /><p>{{ error }}</p>
        </section>

        <template v-else-if="supplier">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <Icon name="folder-fill" class="text-5xl leading-none text-slate-400" />
                    <div>
                        <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Factures</h1>
                        <p class="text-sm text-slate-500">{{ supplier.name }} · {{ targetSite.name }}. Une facture ne modifie jamais le stock.</p>
                    </div>
                </div>
                <Button v-if="can.create_invoice" :as="Link" :href="`${folderHref}/invoices/create`" size="rg"><Icon name="plus" /><span class="ms-2">Enregistrer une facture</span></Button>
            </div>

            <ExplorerView storage-key="portal-invoices" :count="invoices.length" count-label="facture" empty-icon="file-text" empty-title="Aucune facture" empty-description="Aucune facture de ce fournisseur n’est enregistrée sur ce site.">
                <template #grid>
                    <ExplorerTile
                        v-for="invoice in invoices"
                        :key="invoice.uuid"
                        :href="`${folderHref}/invoices/${invoice.uuid}`"
                        icon="file-text"
                        tone="violet"
                        :badge="invoice.has_attachment ? 'Document' : null"
                        :title="invoice.invoice_number"
                        :highlight="formatMoney(invoice.total_amount)"
                        :meta="formatDate(invoice.invoice_date)"
                    />
                </template>
                <template #list>
                    <table class="w-full min-w-[520px] text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                            <tr>
                                <th class="px-5 py-3 text-start">Facture</th>
                                <th class="px-4 py-3 text-start">Date</th>
                                <th class="px-4 py-3 text-end">Montant</th>
                                <th class="px-4 py-3 text-center">Document</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                            <tr v-for="invoice in invoices" :key="invoice.uuid" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/40">
                                <td class="px-5 py-3.5 font-mono font-semibold text-slate-800 dark:text-white">{{ invoice.invoice_number }}</td>
                                <td class="px-4 py-3.5 text-slate-500">{{ formatDate(invoice.invoice_date) }}</td>
                                <td class="px-4 py-3.5 text-end tabular-nums">{{ formatMoney(invoice.total_amount) }}</td>
                                <td class="px-4 py-3.5 text-center"><Icon v-if="invoice.has_attachment" name="clip" class="text-lg text-slate-400" /><span v-else class="text-slate-300">—</span></td>
                                <td class="px-5 py-3.5 text-end"><Button :as="Link" :href="`${folderHref}/invoices/${invoice.uuid}`" size="sm" variant="white-outline">Voir</Button></td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ExplorerView>
        </template>
    </div>
</template>
