<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PurchaseOrderForm from '@/Components/Pharmacy/PurchaseOrderForm.vue';

defineOptions({ layout: AppLayout });

defineProps({ suppliers: Array, medicines: Array });

const page = usePage();
const initialSupplier = new URLSearchParams(page.url.split('?')[1] ?? '').get('supplier') ?? '';
</script>

<template>
    <Head title="Nouvelle commande fournisseur" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Commandes', href: '/pharmacy/purchase-orders' }, { label: 'Nouvelle commande' }]" />

        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Nouvelle commande fournisseur</h1>
            <p class="mt-1 text-sm text-slate-500">La commande est enregistrée en brouillon. Vous pourrez la vérifier avant de l’envoyer.</p>
        </div>

        <PurchaseOrderForm
            :suppliers="suppliers"
            :supplier-uuid="initialSupplier"
            :medicines="medicines"
            :catalog-href="initialSupplier ? `/pharmacy/suppliers/${initialSupplier}/catalogs` : '/pharmacy/stock'"
            :submit-url="(uuid) => `/pharmacy/suppliers/${uuid}/purchase-orders`"
            cancel-href="/pharmacy/purchase-orders"
        />
    </div>
</template>
