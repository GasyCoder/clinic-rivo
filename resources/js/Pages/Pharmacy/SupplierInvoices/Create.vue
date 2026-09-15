<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import SupplierInvoiceForm from '@/Components/Pharmacy/SupplierInvoiceForm.vue';

defineOptions({ layout: AppLayout });

defineProps({ suppliers: Array, medicines: Array });

const page = usePage();
const initialSupplier = new URLSearchParams(page.url.split('?')[1] ?? '').get('supplier') ?? '';
</script>

<template>
    <Head title="Enregistrer une facture fournisseur" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Factures fournisseurs', href: '/pharmacy/supplier-invoices' }, { label: 'Enregistrer une facture' }]" />

        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Enregistrer une facture fournisseur</h1>
            <p class="mt-1 text-sm text-slate-500">Recopiez la facture reçue et joignez-en si possible la photo ou le PDF.</p>
        </div>

        <SupplierInvoiceForm
            :suppliers="suppliers"
            :supplier-uuid="initialSupplier"
            :medicines="medicines"
            :submit-url="(uuid) => `/pharmacy/suppliers/${uuid}/invoices`"
            cancel-href="/pharmacy/supplier-invoices"
        />
    </div>
</template>
