<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import SupplierInvoiceDetail from '@/Components/Pharmacy/SupplierInvoiceDetail.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({ invoice: Object, can: Object });

const links = computed(() => ({
    edit: `/pharmacy/supplier-invoices/${props.invoice.uuid}/edit`,
    archive: `/pharmacy/supplier-invoices/${props.invoice.uuid}`,
    restore: `/pharmacy/supplier-invoices/${props.invoice.uuid}/restore`,
    attachment: `/pharmacy/supplier-invoices/${props.invoice.uuid}/attachment`,
    order: (uuid) => `/pharmacy/purchase-orders/${uuid}`,
    stock: (uuid) => `/pharmacy/stock/${uuid}`,
}));
</script>

<template>
    <Head :title="`Facture ${invoice.invoice_number}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Factures fournisseurs', href: '/pharmacy/supplier-invoices' }, { label: invoice.invoice_number }]" />

        <SupplierInvoiceDetail :invoice="invoice" :can="can" :links="links" />
    </div>
</template>
