<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/UI/Button.vue';
import FolderCard from '@/Components/UI/FolderCard.vue';
import { lucideIcon } from '@/lib/icons';
import { Folder, Plus } from 'lucide-vue-next';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';

defineOptions({ layout: AppLayout });

const props = defineProps({
    supplier: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const plural = (count, word) => `${count} ${word}${count > 1 ? 's' : ''}`;

// The folder's content, as sub-folders. Each one only appears to accounts
// allowed to open the screen behind it.
const folders = computed(() => [
    {
        show: props.can.view_catalogs,
        href: pharmacyUrl(`/pharmacy/suppliers/${props.supplier.uuid}/catalogs`),
        title: 'Catalogues',
        meta: plural(props.counts.catalogs ?? 0, 'fichier'),
        tone: 'amber',
    },
    {
        show: props.can.view_orders,
        href: pharmacyUrl(`/pharmacy/purchase-orders?supplier=${props.supplier.uuid}`),
        title: 'Commandes',
        meta: plural(props.counts.orders ?? 0, 'commande'),
        count: props.counts.open_orders,
        tone: 'violet',
    },
    {
        show: props.can.view_invoices,
        href: pharmacyUrl(`/pharmacy/supplier-invoices?supplier=${props.supplier.uuid}`),
        title: 'Factures',
        meta: plural(props.counts.invoices ?? 0, 'facture'),
        tone: 'slate',
    },
    {
        show: props.can.view_offers,
        href: pharmacyUrl(`/pharmacy/suppliers/${props.supplier.uuid}/products`),
        title: 'Produits et prix',
        meta: plural(props.counts.offers ?? 0, 'produit'),
        tone: 'emerald',
    },
].filter((folder) => folder.show));

const details = computed(() => [
    { label: 'Personne à contacter', value: props.supplier.contact_name, icon: 'user' },
    { label: 'Téléphone', value: props.supplier.phone, icon: 'call', href: props.supplier.phone ? `tel:${props.supplier.phone}` : null },
    { label: 'E-mail', value: props.supplier.email, icon: 'mail', href: props.supplier.email ? `mailto:${props.supplier.email}` : null },
    { label: 'Adresse', value: props.supplier.address, icon: 'map-pin' },
]);
</script>

<template>
    <Head :title="`Fournisseur · ${supplier.name}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Fournisseurs', href: pharmacyUrl('/pharmacy/suppliers') }, { label: supplier.name }]" />

        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <Folder class="text-5xl leading-none text-amber-400 h-4 w-4" />
                    <div class="min-w-0">
                        <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">{{ supplier.name }}</h1>
                        <p class="font-mono text-xs text-slate-400">{{ supplier.code }}</p>
                    </div>
                </div>
                <Button v-if="can.create_order" :as="Link" :href="pharmacyUrl(`/pharmacy/purchase-orders/create?supplier=${supplier.uuid}`)" size="rg">
                    <Plus class="h-4 w-4" /><span class="ms-2">Passer une commande</span>
                </Button>
            </div>

            <dl class="mt-5 grid gap-3 border-t border-gray-100 pt-5 dark:border-gray-900 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="detail in details" :key="detail.label" class="flex items-start gap-2.5">
                    <component :is="lucideIcon(detail.icon)" class="mt-0.5 text-slate-400 h-4 w-4" />
                    <div class="min-w-0">
                        <dt class="text-xs text-slate-500">{{ detail.label }}</dt>
                        <dd class="mt-0.5 break-words text-sm font-medium text-slate-800 dark:text-white">
                            <a v-if="detail.href" :href="detail.href" class="text-primary-600 hover:underline">{{ detail.value }}</a>
                            <span v-else>{{ detail.value || '—' }}</span>
                        </dd>
                    </div>
                </div>
            </dl>
        </section>

        <section>
            <h2 class="mb-2 px-1 text-sm font-semibold text-slate-500">Contenu du dossier</h2>
            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-900 dark:bg-gray-1000/40">
                <div v-if="folders.length" class="grid grid-cols-2 gap-1 sm:grid-cols-4">
                    <FolderCard v-for="folder in folders"
                        :key="folder.href"
                        :href="folder.href"
                        :title="folder.title"
                        :meta="folder.meta"
                        :count="folder.count"
                        :tone="folder.tone"
                    />
                </div>
                <p v-else class="px-3 py-8 text-center text-sm text-slate-500">Votre compte ne permet d’ouvrir aucun élément de ce dossier.</p>
            </div>
        </section>
    </div>
</template>
