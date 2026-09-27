<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import SupplierInvoiceDetail from '@/Components/Pharmacy/SupplierInvoiceDetail.vue';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';

defineOptions({ layout: AppLayout });

const props = defineProps({ invoice: Object, can: Object });

const links = computed(() => ({
    edit: pharmacyUrl(`/pharmacy/supplier-invoices/${props.invoice.uuid}/edit`),
    archive: pharmacyUrl(`/pharmacy/supplier-invoices/${props.invoice.uuid}`),
    restore: pharmacyUrl(`/pharmacy/supplier-invoices/${props.invoice.uuid}/restore`),
    attachment: pharmacyUrl(`/pharmacy/supplier-invoices/${props.invoice.uuid}/attachment`),
    order: (uuid) => pharmacyUrl(`/pharmacy/purchase-orders/${uuid}`),
    stock: (uuid) => pharmacyUrl(`/pharmacy/stock/${uuid}`),
}));
</script>

<template>
    <Head :title="`Facture ${invoice.invoice_number}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Factures fournisseurs', href: pharmacyUrl('/pharmacy/supplier-invoices') }, { label: invoice.invoice_number }]" />

        <SupplierInvoiceDetail :invoice="invoice" :can="can" :links="links" />
    </div>
</template>
