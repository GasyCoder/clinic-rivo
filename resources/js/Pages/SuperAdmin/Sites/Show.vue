<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({ layout: AppLayout });

defineProps({
    clinic: Object,
    selectedModule: Object,
});
</script>

<template>
    <Head :title="`${clinic.name} — ${selectedModule.label}`" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-xl" name="building" /></span>
                <div><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Site opérationnel</p><h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ clinic.name }}</h1></div>
            </div>
            <span class="inline-flex items-center gap-2 self-start rounded border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-slate-500 dark:border-gray-800 dark:bg-gray-950 sm:self-auto"><span :class="['h-1.5 w-1.5 rounded-full', clinic.integration_status === 'CONFIGURED' ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700']"></span>{{ clinic.integration_status === 'CONFIGURED' ? 'Endpoint API configuré' : 'Endpoint API à configurer' }}</span>
        </header>

        <nav class="flex gap-2 overflow-x-auto rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-900 dark:bg-gray-950" aria-label="Modules du site">
            <Link v-for="module in clinic.modules" :key="module.code" :href="`/super-admin/sites/${clinic.code}?module=${module.code}`" :class="['inline-flex shrink-0 items-center gap-2 rounded px-3 py-2 text-xs font-bold transition-colors', selectedModule.code === module.code ? 'bg-slate-700 text-white dark:bg-slate-200 dark:text-slate-900' : 'text-slate-500 hover:bg-gray-100 hover:text-slate-700 dark:hover:bg-gray-900 dark:hover:text-slate-300']"><Icon class="text-base" :name="module.icon" />{{ module.label }}</Link>
        </nav>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded border border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400"><Icon class="text-lg" :name="selectedModule.icon" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">{{ selectedModule.label }}</h2><p class="mt-0.5 text-xs text-slate-400">{{ selectedModule.description }}</p></div></div>
                <span class="inline-flex items-center gap-2 self-start text-xs font-medium text-slate-400 sm:self-auto"><Icon name="server" />Données fournies par l’API de {{ clinic.name }}</span>
            </div>

            <div class="grid gap-px bg-gray-200 dark:bg-gray-900 sm:grid-cols-2 xl:grid-cols-3">
                <article v-for="area in selectedModule.areas" :key="area" class="min-h-28 bg-white p-5 dark:bg-gray-950">
                    <div class="flex items-start justify-between gap-3"><Icon class="text-lg text-slate-400" :name="selectedModule.icon" /><span class="text-[10px] font-medium uppercase tracking-wide text-slate-400">API requise</span></div>
                    <h3 class="mt-4 text-sm font-bold text-slate-700 dark:text-white">{{ area }}</h3>
                </article>
            </div>

            <div v-if="selectedModule.notice" class="flex items-start gap-3 border-t border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/30"><Icon class="mt-0.5 text-base text-slate-400" name="info" /><p class="text-xs leading-5 text-slate-500">{{ selectedModule.notice }}</p></div>
            <div v-else class="flex items-start gap-3 border-t border-gray-200 px-5 py-4 dark:border-gray-900"><Icon class="mt-0.5 text-base text-slate-400" name="info" /><p class="text-xs leading-5 text-slate-500">Les permissions sont contrôlées par le portail puis par l’API du site. Toutes les actions sensibles sont auditées.</p></div>
        </section>
    </div>
</template>
