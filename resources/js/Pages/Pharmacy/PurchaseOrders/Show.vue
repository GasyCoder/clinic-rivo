<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PurchaseOrderDetail from '@/Components/Pharmacy/PurchaseOrderDetail.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({ order: Object, can: Object });

const links = computed(() => ({
    edit: `/pharmacy/purchase-orders/${props.order.uuid}/edit`,
    submit: `/pharmacy/purchase-orders/${props.order.uuid}/submit`,
    cancel: `/pharmacy/purchase-orders/${props.order.uuid}/cancel`,
    receive: `/pharmacy/purchase-orders/${props.order.uuid}/receive`,
    supplier: `/pharmacy/suppliers/${props.order.supplier_uuid}`,
    receipt: (uuid) => `/pharmacy/receipts/${uuid}`,
    invoice: (uuid) => `/pharmacy/supplier-invoices/${uuid}`,
    stock: (uuid) => `/pharmacy/stock/${uuid}`,
}));
</script>

<template>
    <Head :title="`Commande ${order.order_number}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Commandes', href: '/pharmacy/purchase-orders' }, { label: order.order_number }]" />

        <PurchaseOrderDetail :order="order" :can="can" :links="links" />
    </div>
</template>
