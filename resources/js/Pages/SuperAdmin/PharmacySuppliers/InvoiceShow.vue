<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import { TriangleAlert } from 'lucide-vue-next';
import SupplierInvoiceDetail from '@/Components/Pharmacy/SupplierInvoiceDetail.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    invoice: { type: Object, default: null },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);
const invoiceHref = computed(() => `${folderHref.value}/invoices/${props.invoice?.uuid}`);

const links = computed(() => ({
    edit: `${invoiceHref.value}/edit`,
    archive: invoiceHref.value,
    restore: `${invoiceHref.value}/restore`,
    order: (uuid) => `${folderHref.value}/orders/${uuid}`,
}));
</script>

<template>
    <Head :title="invoice ? `Facture ${invoice.invoice_number}` : 'Facture'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Factures', href: supplier ? `${folderHref}/invoices` : null },
            { label: invoice?.invoice_number ?? 'Facture' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" /><p>{{ error }}</p>
        </section>

        <SupplierInvoiceDetail
            v-else-if="invoice"
            :invoice="invoice"
            :can="can"
            :links="links"
            :attachment-note="`Document joint conservé sur le site ${targetSite.name} : il s’ouvre depuis la pharmacie du site.`"
        />
    </div>
</template>
