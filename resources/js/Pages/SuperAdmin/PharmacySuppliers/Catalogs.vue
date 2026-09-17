<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import { Info, TriangleAlert } from 'lucide-vue-next';
import SupplierCatalogFiles from '@/Components/Pharmacy/SupplierCatalogFiles.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    catalogs: { type: Array, default: () => [] },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);

// An archived folder is read-only: restore the supplier before changing its catalogs.
const catalogCan = computed(() => (props.supplier?.archived
    ? { create: false, update: false, delete: false, restore: false }
    : props.can));
</script>

<template>
    <Head :title="supplier ? `Catalogues · ${supplier.name}` : 'Catalogues'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Catalogues' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" />
            <p>{{ error }}</p>
        </section>

        <template v-else-if="supplier">
            <p class="flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-100">
                <Info class="mt-0.5 h-4.5 w-4.5" />
                Les catalogues ajoutés ici sont enregistrés sur le site {{ targetSite.name }}. La pharmacie les consulte dans son dossier fournisseur ; cliquez sur un fichier Excel lu pour voir son contenu ligne par ligne.
            </p>

            <SupplierCatalogFiles
                :supplier-name="supplier.name"
                :catalogs="catalogs"
                :can="catalogCan"
                :base-url="`${folderHref}/catalogs`"
                :can-open-file="false"
                :download-url="(catalog) => `${folderHref}/catalogs/${catalog.uuid}/download?name=${encodeURIComponent(catalog.original_name)}`"
                template-url="/super-admin/pharmacy-suppliers/catalog-template"
            />
        </template>
    </div>
</template>
