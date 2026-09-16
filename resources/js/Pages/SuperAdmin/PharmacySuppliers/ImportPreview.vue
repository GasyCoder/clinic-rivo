<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import { FileSpreadsheet } from 'lucide-vue-next';
import CatalogImportPreview from '@/Components/Pharmacy/CatalogImportPreview.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, required: true },
    catalog: { type: Object, required: true },
    preview: { type: Object, required: true },
});

const folderHref = `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier.uuid}`;
const catalogsHref = `${folderHref}/catalogs`;
</script>

<template>
    <Head :title="`Vérifier ${catalog.original_name}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: `/super-admin/pharmacy-suppliers?site=${targetSite.code}` },
            { label: targetSite.name, href: `/super-admin/pharmacy-suppliers?site=${targetSite.code}` },
            { label: supplier.name, href: folderHref },
            { label: 'Catalogues', href: catalogsHref },
            { label: 'Vérifier avant import' },
        ]" />

        <div class="flex items-start gap-3">
            <FileSpreadsheet class="text-emerald-500 h-9 w-9" />
            <div>
                <h1 class="font-heading text-2xl font-bold text-foreground">Vérifier « {{ catalog.original_name }} »</h1>
                <p class="mt-1 text-sm text-muted-foreground">Lu sur le site {{ targetSite.name }}. Rien n’est encore enregistré : contrôlez les lignes, puis confirmez.</p>
            </div>
        </div>

        <CatalogImportPreview
            :catalog="catalog"
            :preview="preview"
            :import-url="`${folderHref}/catalogs/${catalog.uuid}/import`"
            :cancel-href="catalogsHref"
        />
    </div>
</template>
