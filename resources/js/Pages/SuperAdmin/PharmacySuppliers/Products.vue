<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Icon from '@/Components/UI/Icon.vue';
import SupplierOfferTables from '@/Components/Pharmacy/SupplierOfferTables.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    offers: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);
</script>

<template>
    <Head :title="supplier ? `Produits et prix · ${supplier.name}` : 'Produits et prix'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Produits et prix' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <Icon name="alert" class="mt-0.5 text-lg" /><p>{{ error }}</p>
        </section>

        <template v-else-if="supplier">
            <div class="flex items-center gap-3">
                <Icon name="folder-fill" class="text-4xl leading-none text-emerald-500" />
                <div>
                    <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Produits et prix</h1>
                    <p class="text-sm text-slate-500">Les médicaments de {{ targetSite.name }} que {{ supplier.name }} fournit, et le prix d’achat qu’il pratique. Un ancien prix n’est jamais effacé.</p>
                </div>
            </div>

            <SupplierOfferTables :offers="offers" :history="history" />
        </template>
    </div>
</template>
