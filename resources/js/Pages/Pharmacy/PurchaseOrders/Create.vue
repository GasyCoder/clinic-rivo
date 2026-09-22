<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import PurchaseOrderForm from '@/Components/Pharmacy/PurchaseOrderForm.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({ suppliers: Array, supplierUuid: String, medicines: Array, canSend: Boolean });

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
    <Head title="Nouvelle commande" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Commandes', href: '/pharmacy/purchase-orders' }, { label: 'Nouvelle commande' }]" />

        <PageHeader eyebrow="Achats" title="Nouvelle commande" description="Choisissez le fournisseur, ajoutez les produits, puis enregistrez en brouillon ou envoyez directement." icon="truck" tone="violet" />

        <PurchaseOrderForm
            :suppliers="suppliers"
            :supplier-uuid="props.supplierUuid"
            :medicines="medicines"
            :can-send="canSend"
            :catalog-href="props.supplierUuid ? `/pharmacy/suppliers/${props.supplierUuid}/catalogs` : '/pharmacy/stock'"
            :submit-url="(uuid) => `/pharmacy/suppliers/${uuid}/purchase-orders`"
            cancel-href="/pharmacy/purchase-orders"
            @supplier-change="onSupplierChange"
        />
    </div>
</template>
