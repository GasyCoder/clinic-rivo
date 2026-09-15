<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Icon from '@/Components/UI/Icon.vue';
import SupplierOfferTables from '@/Components/Pharmacy/SupplierOfferTables.vue';

defineOptions({ layout: AppLayout });

defineProps({
    supplier: { type: Object, required: true },
    offers: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`Produits et prix · ${supplier.name}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Fournisseurs', href: '/pharmacy/suppliers' }, { label: supplier.name, href: `/pharmacy/suppliers/${supplier.uuid}` }, { label: 'Produits et prix' }]" />

        <div class="flex items-center gap-3">
            <Icon name="folder-fill" class="text-4xl leading-none text-emerald-500" />
            <div>
                <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Produits et prix</h1>
                <p class="text-sm text-slate-500">Les médicaments de la clinique que {{ supplier.name }} fournit, et le prix d’achat qu’il pratique. Un ancien prix n’est jamais effacé.</p>
            </div>
        </div>

        <SupplierOfferTables :offers="offers" :history="history" :stock-href="(uuid) => `/pharmacy/stock/${uuid}`" />
    </div>
</template>
