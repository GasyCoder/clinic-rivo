<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { Activity, ArrowRight, Briefcase, Building2, History, ListOrdered, MapPin, Package, Pill, Settings2, ShieldCheck, Users, Wallet } from 'lucide-vue-next';

defineOptions({ layout: AppLayout });

defineProps({
    sites: Array,
    modules: Array,
});

const page = usePage();
</script>

<template>
    <Head title="Dashboard" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Super Administration</p>
                <h1 class="mt-1 font-heading text-2xl font-bold text-foreground">Dashboard</h1>
                <p class="mt-1 text-sm text-muted-foreground">Pilotage central de {{ page.props.site.brand }} pour les trois sites opérationnels.</p>
            </div>
            <Button :as="Link" href="/super-admin/workspaces/audit" size="rg" variant="white-outline">
                <Activity class="h-4.5 w-4.5" />État des APIs
            </Button>
        </header>

        <section class="grid overflow-hidden rounded-lg border border-border bg-card sm:grid-cols-2 xl:grid-cols-4">
            <div class="border-b border-border px-5 py-4 sm:border-e xl:border-b-0"><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Sites</p><p class="mt-1 text-xl font-bold text-foreground">{{ sites.length }}</p></div>
            <div class="border-b border-border px-5 py-4 xl:border-b-0 xl:border-e"><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Patients aujourd’hui</p><p class="mt-1 text-xl font-bold text-foreground">—</p></div>
            <div class="border-b border-border px-5 py-4 sm:border-e sm:border-b-0"><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Passages ouverts</p><p class="mt-1 text-xl font-bold text-foreground">—</p></div>
            <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Recettes du jour</p><p class="mt-1 text-xl font-bold text-foreground">—</p></div>
        </section>

        <section>
            <div class="mb-3 flex items-end justify-between gap-3"><div><h2 class="text-sm font-bold text-foreground">Sites de la clinique</h2><p class="mt-1 text-xs text-muted-foreground">Chaque site conserve sa base, sa caisse et ses règles locales.</p></div><span class="text-xs text-muted-foreground">Données via API uniquement</span></div>
            <div class="grid gap-4 lg:grid-cols-3">
                <article v-for="site in sites" :key="site.code" class="rounded-lg border border-border bg-card p-5">
                    <div class="flex items-start justify-between gap-4">
                        <span class="flex h-10 w-10 items-center justify-center rounded bg-muted text-muted-foreground dark:text-muted-foreground"><Building2 class="h-5 w-5" /></span>
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><span :class="['h-1.5 w-1.5 rounded-full', site.integration_status === 'CONFIGURED' ? 'bg-emerald-500' : 'bg-muted-foreground/40 ']"></span>{{ site.integration_status === 'CONFIGURED' ? 'API configurée' : 'API à configurer' }}</span>
                    </div>
                    <h3 class="mt-4 font-heading text-lg font-bold text-foreground">{{ site.name }}</h3>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">{{ modules.length - 1 }} modules métier disponibles dans l’espace du site.</p>
                    <Link :href="`/super-admin/sites/${site.code}?module=OVERVIEW`" class="mt-4 inline-flex items-center gap-1.5 text-sm font-bold text-primary hover:text-primary">Ouvrir le site <ArrowRight class="h-4 w-4" /></Link>
                </article>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <Link href="/super-admin/workspaces/finance" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><Wallet class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Finance par site</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Rapports consolidés et détail local.</p></Link>
            <Link href="/super-admin/workspaces/hr" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><Briefcase class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Ressources humaines</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Employés, contrats, présence et planning.</p></Link>
            <Link href="/super-admin/workspaces/logistics" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><Package class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Logistique</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Inventaire et suivi des équipements.</p></Link>
            <Link href="/super-admin/workspaces/guarding" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><ShieldCheck class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Gardiennage</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Entrées, sorties et observations.</p></Link>
            <Link href="/super-admin/workspaces/tariffs" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><ListOrdered class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Tarifs & mutuelles</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Désignations, grilles et organismes partenaires par site.</p></Link>
            <Link href="/super-admin/stock" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><Pill class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Stock médicaments</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Lots, disponibilités et alertes par site.</p></Link>
            <Link href="/super-admin/addresses" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><MapPin class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Référentiel adresses</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Localités disponibles à la réception.</p></Link>
            <Link href="/super-admin/workspaces/users" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><Users class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Utilisateurs</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Comptes et affectations par site.</p></Link>
            <Link href="/super-admin/workspaces/roles" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><ShieldCheck class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Rôles & permissions</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Droits métier et exceptions individuelles.</p></Link>
            <Link href="/super-admin/workspaces/settings" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><Settings2 class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Paramètres</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Nom de l’application et configuration.</p></Link>
            <Link href="/super-admin/workspaces/audit" class="rounded-lg border border-border bg-card p-4 transition-colors hover:border-border dark:hover:border-border"><History class="text-muted-foreground h-5 w-5" /><h2 class="mt-3 text-sm font-bold text-foreground">Audit & APIs</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Traçabilité et disponibilité des sites.</p></Link>
        </section>

        <div class="flex items-start gap-3 rounded-lg border border-border bg-card p-4">
            <ShieldCheck class="mt-0.5 text-muted-foreground h-4.5 w-4.5" />
            <p class="text-xs leading-5 text-muted-foreground">Les indicateurs restent volontairement vides tant que les API sécurisées des sites ne sont pas connectées. Le portail central n’utilise aucune connexion directe à DB_MAMPIKONY, DB_AMBONDROMAMY ou DB_BORIZINY.</p>
        </div>
    </div>
</template>
