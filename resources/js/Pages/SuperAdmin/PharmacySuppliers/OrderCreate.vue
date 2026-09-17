<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import { TriangleAlert } from 'lucide-vue-next';
import PurchaseOrderForm from '@/Components/Pharmacy/PurchaseOrderForm.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    medicines: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);
</script>

<template>
    <Head title="Nouvelle commande fournisseur" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Commandes', href: supplier ? `${folderHref}/orders` : null },
            { label: 'Nouvelle commande' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" /><p>{{ error }}</p>
        </section>

        <template v-else-if="supplier">
            <div>
                <h1 class="font-heading text-2xl font-bold text-foreground">Nouvelle commande · {{ supplier.name }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">Enregistrée en brouillon sur le site {{ targetSite.name }}, à votre nom. Vous pourrez la vérifier avant de l’envoyer ; la réception se fera à la pharmacie du site.</p>
            </div>

            <PurchaseOrderForm
                :supplier-name="supplier.name"
                :supplier-uuid="supplier.uuid"
                :medicines="medicines"
                :catalog-href="`${folderHref}/catalogs`"
                :submit-url="() => `${folderHref}/orders`"
                :cancel-href="`${folderHref}/orders`"
            />
        </template>
    </div>
</template>
