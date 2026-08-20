<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({ layout: AppLayout });

defineProps({
    workspace: Object,
    sites: Array,
    brand: String,
});
</script>

<template>
    <Head :title="workspace.title" />

    <div class="w-full space-y-5">
        <header class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-xl" :name="workspace.icon" /></span>
            <div><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ workspace.title }}</h1><p class="mt-1 max-w-3xl text-sm text-slate-500">{{ workspace.description }}</p></div>
        </header>

        <section v-if="workspace.code === 'FINANCE'" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="grid grid-cols-[minmax(180px,1fr)_repeat(3,minmax(130px,0.5fr))] border-b border-gray-200 bg-gray-50/70 px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900 dark:bg-gray-1000/40"><span>Site</span><span>Recettes</span><span>Paiements</span><span>Solde</span></div>
            <div v-for="site in sites" :key="site.code" class="grid grid-cols-[minmax(180px,1fr)_repeat(3,minmax(130px,0.5fr))] items-center border-b border-gray-200 px-5 py-4 last:border-0 dark:border-gray-900"><Link :href="`/super-admin/sites/${site.code}?module=CASH`" class="text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ site.name }}</Link><span class="text-sm text-slate-400">—</span><span class="text-sm text-slate-400">—</span><span class="text-sm text-slate-400">—</span></div>
        </section>

        <section v-else-if="workspace.code === 'SETTINGS'" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950"><label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom de l’application</label><div class="flex h-10 items-center rounded border border-gray-200 bg-gray-50 px-4 text-sm font-medium text-slate-600 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-200">{{ brand }}</div><p class="mt-2 text-xs leading-5 text-slate-400">La modification sera activée avec la commande API idempotente qui diffusera la valeur séparément aux sites sélectionnés.</p></div>
            <div class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Cibles</h2><ul class="mt-3 space-y-2"><li v-for="site in sites" :key="site.code" class="flex items-center justify-between text-xs text-slate-500"><span>{{ site.name }}</span><span>API {{ site.integration_status === 'CONFIGURED' ? 'configurée' : 'à configurer' }}</span></li></ul></div>
        </section>

        <section v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="area in workspace.areas" :key="area" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950"><div class="flex items-start justify-between gap-3"><span class="flex h-9 w-9 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-lg" :name="workspace.icon" /></span><span class="rounded bg-gray-100 px-2 py-1 text-[10px] font-medium uppercase tracking-wide text-slate-400 dark:bg-gray-900">API requise</span></div><h2 class="mt-4 text-sm font-bold text-slate-700 dark:text-white">{{ area }}</h2><p class="mt-1 text-xs leading-5 text-slate-500">Gestion centralisée par site, avec autorisation locale et audit.</p></article>
        </section>

        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950"><Icon class="mt-0.5 text-lg text-slate-400" name="info" /><p class="text-xs leading-5 text-slate-500">Cet espace pose la séparation des responsabilités et la navigation. Les actions distantes resteront désactivées jusqu’à l’implémentation des API authentifiées, des clés d’idempotence, des files de reprise et de l’audit dans chaque site.</p></div>
    </div>
</template>
