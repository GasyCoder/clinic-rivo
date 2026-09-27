<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import { TriangleAlert } from 'lucide-vue-next';
import SupplierInvoiceForm from '@/Components/Pharmacy/SupplierInvoiceForm.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    invoice: { type: Object, default: null },
    medicines: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);
const invoiceHref = computed(() => `${folderHref.value}/invoices/${props.invoice?.uuid}`);
</script>

<template>
    <Head :title="invoice ? `Modifier la facture ${invoice.invoice_number}` : 'Modifier une facture'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Factures', href: supplier ? `${folderHref}/invoices` : null },
            { label: invoice?.invoice_number ?? 'Facture', href: invoice ? invoiceHref : null },
            { label: 'Modifier' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" /><p>{{ error }}</p>
        </section>

        <template v-else-if="invoice">
            <div>
                <h1 class="font-heading text-2xl font-bold text-foreground">Modifier la facture {{ invoice.invoice_number }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">Facture de {{ supplier?.name }} sur le site {{ targetSite.name }}. Corriger une facture ne modifie jamais le stock ; sans nouveau document, le document actuel est conservé.</p>
            </div>

            <SupplierInvoiceForm
                :supplier-name="supplier?.name ?? ''"
                :supplier-uuid="supplier?.uuid ?? ''"
                :medicines="medicines"
                :orders="orders"
                :invoice="invoice"
                :submit-url="() => `${invoiceHref}/update`"
                :cancel-href="invoiceHref"
            />
        </template>
    </div>
</template>
