<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PurchaseOrderForm from '@/Components/Pharmacy/PurchaseOrderForm.vue';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';

defineOptions({ layout: AppLayout });

const props = defineProps({ order: Object, supplier: Object, medicines: Array, canSend: Boolean });

const orderHref = pharmacyUrl(`/pharmacy/purchase-orders/${props.order.uuid}`);
</script>

<template>
    <Head :title="`Modifier la commande ${order.order_number}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Commandes', href: pharmacyUrl('/pharmacy/purchase-orders') }, { label: order.order_number, href: orderHref }, { label: 'Modifier' }]" />

        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Modifier la commande {{ order.order_number }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ order.supplier }} · brouillon. Une fois envoyée, une commande ne se modifie plus : elle s’annule.</p>
        </div>

        <PurchaseOrderForm
            :supplier="supplier"
            :supplier-name="order.supplier"
            :supplier-uuid="order.supplier_uuid"
            :can-send="canSend"
            :medicines="medicines"
            :order="order"
            :submit-url="() => orderHref"
            :cancel-href="orderHref"
        />
    </div>
</template>
