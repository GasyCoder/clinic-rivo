<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PurchaseOrderForm from '@/Components/Pharmacy/PurchaseOrderForm.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({ suppliers: Array, supplierUuid: String, medicines: Array });

// Ce qu'un fournisseur peut livrer ne se devine pas côté navigateur : le
// serveur le redit à chaque changement (ADR-098), catalogue compris.
const onSupplierChange = (uuid) => router.get('/pharmacy/purchase-orders/create', uuid ? { supplier: uuid } : {}, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['supplierUuid', 'medicines'],
});
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
            :supplier-uuid="props.supplierUuid"
            :medicines="medicines"
            :catalog-href="props.supplierUuid ? `/pharmacy/suppliers/${props.supplierUuid}/catalogs` : '/pharmacy/stock'"
            @supplier-change="onSupplierChange"
            :submit-url="(uuid) => `/pharmacy/suppliers/${uuid}/purchase-orders`"
            cancel-href="/pharmacy/purchase-orders"
        />
    </div>
</template>
