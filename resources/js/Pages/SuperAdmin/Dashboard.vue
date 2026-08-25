<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({ layout: AppLayout });

defineProps({
    sites: Array,
    modules: Array,
});

const page = usePage();
</script>

<template>
    <Head title="Vue d’ensemble" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration</p>
                <h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Vue d’ensemble</h1>
                <p class="mt-1 text-sm text-slate-500">Pilotage central de {{ page.props.site.brand }} pour les trois sites opérationnels.</p>
            </div>
            <Button :as="Link" href="/super-admin/workspaces/audit" size="rg" variant="white-outline">
                <Icon class="text-lg" name="activity" /><span class="ms-2">État des APIs</span>
            </Button>
        </header>

        <section class="grid overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950 sm:grid-cols-2 xl:grid-cols-4">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:border-e xl:border-b-0"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Sites</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ sites.length }}</p></div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 xl:border-b-0 xl:border-e"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Patients aujourd’hui</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">—</p></div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:border-e sm:border-b-0"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Passages ouverts</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">—</p></div>
            <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Recettes du jour</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">—</p></div>
        </section>

        <section>
            <div class="mb-3 flex items-end justify-between gap-3"><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Sites de la clinique</h2><p class="mt-1 text-xs text-slate-400">Chaque site conserve sa base, sa caisse et ses règles locales.</p></div><span class="text-xs text-slate-400">Données via API uniquement</span></div>
            <div class="grid gap-4 lg:grid-cols-3">
                <article v-for="site in sites" :key="site.code" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex items-start justify-between gap-4">
                        <span class="flex h-10 w-10 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-xl" name="building" /></span>
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500"><span :class="['h-1.5 w-1.5 rounded-full', site.integration_status === 'CONFIGURED' ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700']"></span>{{ site.integration_status === 'CONFIGURED' ? 'API configurée' : 'API à configurer' }}</span>
                    </div>
                    <h3 class="mt-4 font-heading text-lg font-bold text-slate-700 dark:text-white">{{ site.name }}</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ modules.length - 1 }} modules métier disponibles dans l’espace du site.</p>
                    <Link :href="`/super-admin/sites/${site.code}?module=OVERVIEW`" class="mt-4 inline-flex items-center gap-1.5 text-sm font-bold text-primary-600 hover:text-primary-700">Ouvrir le site <Icon name="arrow-right" /></Link>
                </article>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <Link href="/super-admin/workspaces/finance" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="wallet" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Finance par site</h2><p class="mt-1 text-xs leading-5 text-slate-500">Rapports consolidés et détail local.</p></Link>
            <Link href="/super-admin/workspaces/hr" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="briefcase" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Ressources humaines</h2><p class="mt-1 text-xs leading-5 text-slate-500">Employés, contrats, présence et planning.</p></Link>
            <Link href="/super-admin/workspaces/logistics" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="package" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Logistique</h2><p class="mt-1 text-xs leading-5 text-slate-500">Inventaire et suivi des équipements.</p></Link>
            <Link href="/super-admin/workspaces/guarding" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="shield-check" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Gardiennage</h2><p class="mt-1 text-xs leading-5 text-slate-500">Entrées, sorties et observations.</p></Link>
            <Link href="/super-admin/workspaces/tariffs" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="list-index" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Tarifs & mutuelles</h2><p class="mt-1 text-xs leading-5 text-slate-500">Désignations, grilles et organismes partenaires par site.</p></Link>
            <Link href="/super-admin/stock" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="capsule" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Stock médicaments</h2><p class="mt-1 text-xs leading-5 text-slate-500">Lots, disponibilités et alertes par site.</p></Link>
            <Link href="/super-admin/addresses" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="map-pin" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Référentiel adresses</h2><p class="mt-1 text-xs leading-5 text-slate-500">Localités disponibles à la réception.</p></Link>
            <Link href="/super-admin/workspaces/users" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="users" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Utilisateurs</h2><p class="mt-1 text-xs leading-5 text-slate-500">Comptes et affectations par site.</p></Link>
            <Link href="/super-admin/workspaces/roles" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="shield-check" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Rôles & permissions</h2><p class="mt-1 text-xs leading-5 text-slate-500">Droits métier et exceptions individuelles.</p></Link>
            <Link href="/super-admin/workspaces/settings" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="setting-alt" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Paramètres</h2><p class="mt-1 text-xs leading-5 text-slate-500">Nom de l’application et configuration.</p></Link>
            <Link href="/super-admin/workspaces/audit" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" name="history" /><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Audit & APIs</h2><p class="mt-1 text-xs leading-5 text-slate-500">Traçabilité et disponibilité des sites.</p></Link>
        </section>

        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950">
            <Icon class="mt-0.5 text-lg text-slate-400" name="shield-check" />
            <p class="text-xs leading-5 text-slate-500">Les indicateurs restent volontairement vides tant que les API sécurisées des sites ne sont pas connectées. Le portail central n’utilise aucune connexion directe à DB_MAMPIKONY, DB_AMBONDROMAMY ou DB_BORIZINY.</p>
        </div>
    </div>
</template>
