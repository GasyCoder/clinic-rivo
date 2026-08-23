<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const areas = [
    { title: 'Médicaments', description: 'Consulter les médicaments autorisés du référentiel.', icon: 'capsule', permission: 'medicines.view' },
    { title: 'Stock médicaments', description: 'Voir les quantités disponibles par produit et par lot.', icon: 'package', permission: 'stock.view' },
    { title: 'Lots & péremptions', description: 'Suivre les numéros de lots et les dates de péremption.', icon: 'calendar', permission: 'stock.lots.view' },
    { title: 'Entrées & sorties', description: 'Tracer chaque mouvement physique du stock pharmacie.', icon: 'swap', permission: 'stock.entry' },
    { title: 'Inventaires', description: 'Réaliser et valider les inventaires pharmaceutiques.', icon: 'list-check', permission: 'stock.inventory' },
    { title: 'Délivrances & retours', description: 'Préparer les délivrances et enregistrer les retours autorisés.', icon: 'check-circle', permission: 'pharmacy.dispense' },
];
</script>

<template>
    <Head title="Pharmacie" />

    <div class="w-full space-y-5">
        <header>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Gestion clinique</p>
            <h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Pharmacie</h1>
            <p class="mt-1 text-sm text-slate-500">Délivrance, lots, péremptions et stock de médicaments.</p>
        </header>

        <section class="grid gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 dark:border-gray-900 dark:bg-gray-900 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="area in areas.filter((item) => can(item.permission))" :key="area.title" class="min-h-40 bg-white p-5 dark:bg-gray-950">
                <div class="flex items-start justify-between gap-3"><Icon class="text-xl text-slate-400" :name="area.icon" /><span class="text-[10px] font-medium uppercase tracking-wide text-slate-400">À construire</span></div>
                <h2 class="mt-5 text-sm font-bold text-slate-700 dark:text-white">{{ area.title }}</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ area.description }}</p>
            </article>
        </section>

        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950"><Icon class="mt-0.5 text-lg text-slate-400" name="shield-check" /><p class="text-xs leading-5 text-slate-500">La Pharmacie ne possède aucune caisse et n’encaisse aucun paiement. Les règlements restent exclusivement à la Réception / Caisse.</p></div>
    </div>
</template>
