<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Icon from '@/Components/UI/Icon.vue';
import PurchaseOrderForm from '@/Components/Pharmacy/PurchaseOrderForm.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    order: { type: Object, default: null },
    medicines: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);
const orderHref = computed(() => `${folderHref.value}/orders/${props.order?.uuid}`);
</script>

<template>
    <Head :title="order ? `Modifier la commande ${order.order_number}` : 'Modifier une commande'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Commandes', href: supplier ? `${folderHref}/orders` : null },
            { label: order?.order_number ?? 'Commande', href: order ? orderHref : null },
            { label: 'Modifier' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <Icon name="alert" class="mt-0.5 text-lg" /><p>{{ error }}</p>
        </section>

        <template v-else-if="order">
            <div>
                <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Modifier la commande {{ order.order_number }}</h1>
                <p class="mt-1 text-sm text-slate-500">Brouillon de {{ targetSite.name }} chez {{ supplier?.name }}. Enregistré à votre nom ; une fois envoyée, la commande ne se modifie plus.</p>
            </div>

            <PurchaseOrderForm
                :supplier-name="supplier?.name ?? ''"
                :supplier-uuid="supplier?.uuid ?? ''"
                :medicines="medicines"
                :order="order"
                :submit-url="() => orderHref"
                :cancel-href="orderHref"
            />
        </template>
    </div>
</template>
