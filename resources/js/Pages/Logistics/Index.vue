<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const areas = [
    { title: 'Inventaire des équipements', description: 'Recenser chaque équipement avec son numéro d’inventaire et son état.', icon: 'list-check', permission: 'equipment.inventory' },
    { title: 'Affectations & localisations', description: 'Suivre le service, le local et le responsable de chaque équipement.', icon: 'map-pin', permission: 'equipment.assign' },
    { title: 'Suivi des équipements', description: 'Consulter l’état, l’historique et les mouvements des équipements.', icon: 'activity', permission: 'equipment.view' },
    { title: 'Maintenance', description: 'Planifier et tracer les opérations de maintenance.', icon: 'setting', permission: 'equipment.maintenance.manage' },
    { title: 'Mise hors service', description: 'Tracer la réforme d’un équipement avec motif et audit.', icon: 'archive', permission: 'equipment.decommission' },
    { title: 'Stock administratif', description: 'Gérer les fournitures hors médicaments et hors pharmacie.', icon: 'package', permission: 'administrative_stock.view' },
];
</script>

<template>
    <Head title="Logistique" />

    <div class="w-full space-y-5">
        <header>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Gestion interne</p>
            <h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Logistique & équipements</h1>
            <p class="mt-1 text-sm text-slate-500">Inventaire, affectations, état et maintenance des équipements de la clinique.</p>
        </header>

        <section class="grid gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 dark:border-gray-900 dark:bg-gray-900 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="area in areas.filter((item) => can(item.permission))" :key="area.title" class="min-h-40 bg-white p-5 dark:bg-gray-950">
                <div class="flex items-start justify-between gap-3"><Icon class="text-xl text-slate-400" :name="area.icon" /><span class="text-[10px] font-medium uppercase tracking-wide text-slate-400">À construire</span></div>
                <h2 class="mt-5 text-sm font-bold text-slate-700 dark:text-white">{{ area.title }}</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ area.description }}</p>
            </article>
        </section>

        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950"><Icon class="mt-0.5 text-lg text-slate-400" name="info" /><p class="text-xs leading-5 text-slate-500">Les médicaments, lots et péremptions ne sont jamais gérés ici : ils appartiennent exclusivement au module Pharmacie.</p></div>
    </div>
</template>
