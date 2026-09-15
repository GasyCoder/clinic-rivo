<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Icon from '@/Components/UI/Icon.vue';
import PurchaseOrderDetail from '@/Components/Pharmacy/PurchaseOrderDetail.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    order: { type: Object, default: null },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);
const orderHref = computed(() => `${folderHref.value}/orders/${props.order?.uuid}`);

// Receiving is absent on purpose: it stays at the site's pharmacy (ADR-098).
const links = computed(() => ({
    edit: `${orderHref.value}/edit`,
    submit: `${orderHref.value}/submit`,
    cancel: `${orderHref.value}/cancel`,
    supplier: folderHref.value,
    invoice: (uuid) => `${folderHref.value}/invoices/${uuid}`,
    newInvoice: `${folderHref.value}/invoices/create?order=${props.order?.uuid}`,
}));
</script>

<template>
    <Head :title="order ? `Commande ${order.order_number}` : 'Commande'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Commandes', href: supplier ? `${folderHref}/orders` : null },
            { label: order?.order_number ?? 'Commande' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <Icon name="alert" class="mt-0.5 text-lg" /><p>{{ error }}</p>
        </section>

        <PurchaseOrderDetail
            v-else-if="order"
            :order="order"
            :can="can"
            :links="links"
            :reception-note="`La marchandise se réceptionne à la pharmacie de ${targetSite.name} : c’est là que les lots et les dates de péremption sont lus sur les boîtes.`"
        />
    </div>
</template>
