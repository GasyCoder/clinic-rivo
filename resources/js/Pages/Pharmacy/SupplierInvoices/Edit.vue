<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import SupplierInvoiceForm from '@/Components/Pharmacy/SupplierInvoiceForm.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({ invoice: Object, medicines: Array, orders: Array });

const invoiceHref = `/pharmacy/supplier-invoices/${props.invoice.uuid}`;
</script>

<template>
    <Head :title="`Modifier la facture ${invoice.invoice_number}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Factures fournisseurs', href: '/pharmacy/supplier-invoices' }, { label: invoice.invoice_number, href: invoiceHref }, { label: 'Modifier' }]" />

        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Modifier la facture {{ invoice.invoice_number }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ invoice.supplier }}. Corriger une facture ne modifie jamais le stock. Sans nouveau document, le document actuel est conservé.</p>
        </div>

        <SupplierInvoiceForm
            :supplier-name="invoice.supplier"
            :supplier-uuid="invoice.supplier_uuid ?? 'fixed'"
            :medicines="medicines"
            :orders="orders"
            :invoice="invoice"
            :submit-url="() => `${invoiceHref}/update`"
            :cancel-href="invoiceHref"
        />
    </div>
</template>
