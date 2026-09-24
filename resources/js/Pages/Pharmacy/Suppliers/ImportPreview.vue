<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import { FileSpreadsheet } from 'lucide-vue-next';
import CatalogImportPreview from '@/Components/Pharmacy/CatalogImportPreview.vue';

defineOptions({ layout: AppLayout });

defineProps({
    supplier: { type: Object, required: true },
    catalog: { type: Object, required: true },
    preview: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`Vérifier ${catalog.original_name}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs', href: '/pharmacy/suppliers' },
            { label: supplier.name, href: `/pharmacy/suppliers/${supplier.uuid}` },
            { label: 'Catalogues', href: `/pharmacy/suppliers/${supplier.uuid}/catalogs` },
            { label: 'Vérifier avant import' },
        ]" />

        <div class="flex items-start gap-3">
            <FileSpreadsheet class="text-4xl leading-none text-emerald-500 h-4 w-4" />
            <div>
                <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Vérifier « {{ catalog.original_name }} »</h1>
                <p class="mt-1 text-sm text-slate-500">Rien n’est encore enregistré. Contrôlez ce qui a été lu dans le fichier, puis confirmez.</p>
            </div>
        </div>

        <CatalogImportPreview :catalog="catalog"
            :preview="preview"
            :import-url="`/pharmacy/suppliers/${supplier.uuid}/catalogs/${catalog.uuid}/import`"
            :cancel-href="`/pharmacy/suppliers/${supplier.uuid}/catalogs`"
        />
    </div>
</template>
