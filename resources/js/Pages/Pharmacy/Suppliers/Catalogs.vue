<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import { Folder } from 'lucide-vue-next';
import SupplierCatalogFiles from '@/Components/Pharmacy/SupplierCatalogFiles.vue';

defineOptions({ layout: AppLayout });

defineProps({
    supplier: { type: Object, required: true },
    catalogs: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});
</script>

<template>
    <Head :title="`Catalogues · ${supplier.name}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Fournisseurs', href: '/pharmacy/suppliers' }, { label: supplier.name, href: `/pharmacy/suppliers/${supplier.uuid}` }, { label: 'Catalogues' }]" />

        <div class="flex items-center gap-3">
            <Folder class="text-4xl leading-none text-amber-400 h-4 w-4" />
            <div>
                <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Catalogues</h1>
                <p class="text-sm text-slate-500">Les tarifs envoyés par {{ supplier.name }}. Un seul est utilisé à la fois ; les anciens restent consultables.</p>
            </div>
        </div>

        <SupplierCatalogFiles :supplier-name="supplier.name"
            :catalogs="catalogs"
            :can="can"
            :base-url="`/pharmacy/suppliers/${supplier.uuid}/catalogs`"
            template-url="/pharmacy/suppliers/catalog-template"
        />
    </div>
</template>
