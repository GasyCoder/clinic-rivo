<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { BadgeCheck, Hash, Info, PenLine, Settings } from 'lucide-vue-next';
import { lucideIcon } from '@/lib/icons';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

defineProps({
    workspace: Object,
    sites: Array,
    brand: String,
});
const { can } = usePermissions();
</script>

<template>
    <Head :title="workspace.title" />

    <div class="w-full space-y-5">
        <header class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground dark:text-muted-foreground"><component class="h-5 w-5" :is="lucideIcon(workspace.icon)" /></span>
            <div><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Super Administration</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-foreground">{{ workspace.title }}</h1><p class="mt-1 max-w-3xl text-sm text-muted-foreground">{{ workspace.description }}</p></div>
        </header>

        <section v-if="workspace.code === 'HR' && can('settings.view')" class="rounded-lg border border-border bg-card p-5">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Settings class="h-5 w-5" /></span>
                <div>
                    <h2 class="text-sm font-bold text-foreground">Configuration du personnel</h2>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Choisissez le site puis le réglage RH. Chaque modification passe par l’API et l’audit du site.</p>
                </div>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <article v-for="site in sites" :key="site.code" class="rounded-lg border border-border p-4">
                    <h3 class="text-sm font-bold text-foreground">{{ site.name }}</h3>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <Link :href="`/super-admin/human-resources/settings/matricules?site=${site.code}`" class="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-2 font-medium text-muted-foreground hover:bg-muted hover:text-foreground"><Hash class="h-3.5 w-3.5" />Matricules</Link>
                        <Link :href="`/super-admin/human-resources/settings/badges?site=${site.code}`" class="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-2 font-medium text-muted-foreground hover:bg-muted hover:text-foreground"><BadgeCheck class="h-3.5 w-3.5" />Badges</Link>
                        <Link :href="`/super-admin/human-resources/settings/direction?site=${site.code}`" class="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-2 font-medium text-muted-foreground hover:bg-muted hover:text-foreground"><PenLine class="h-3.5 w-3.5" />Direction</Link>
                    </div>
                </article>
            </div>
        </section>

        <section v-if="workspace.code === 'FINANCE'" class="overflow-hidden rounded-lg border border-border bg-card">
            <div class="grid grid-cols-[minmax(180px,1fr)_repeat(3,minmax(130px,0.5fr))] border-b border-border bg-muted/70 px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-muted-foreground /40"><span>Site</span><span>Recettes</span><span>Paiements</span><span>Solde</span></div>
            <div v-for="site in sites" :key="site.code" class="grid grid-cols-[minmax(180px,1fr)_repeat(3,minmax(130px,0.5fr))] items-center border-b border-border px-5 py-4 last:border-0"><Link :href="`/super-admin/sites/${site.code}?module=CASH`" class="text-sm font-bold text-foreground hover:text-primary">{{ site.name }}</Link><span class="text-sm text-muted-foreground">—</span><span class="text-sm text-muted-foreground">—</span><span class="text-sm text-muted-foreground">—</span></div>
        </section>

        <section v-if="workspace.code === 'FINANCE'" class="overflow-hidden rounded-lg border border-border bg-card">
            <header class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div><p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">Rapport financier · Chirurgie</p><h2 class="mt-1 text-lg font-bold text-foreground">Revenus par intervention</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Lecture financière uniquement. Les montants proviendront des prestations, factures et paiements de la Caisse.</p></div>
                <span class="inline-flex self-start rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300">Circuit facturable à relier</span>
            </header>
            <div class="overflow-x-auto">
                <div class="min-w-[760px]">
                    <div class="grid grid-cols-[minmax(260px,1.8fr)_repeat(4,minmax(110px,0.6fr))] border-b border-border bg-muted/70 px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-muted-foreground /40"><span>Intervention</span><span>Prévu</span><span>Réel</span><span>Écart</span><span>Dette NP</span></div>
                    <div v-for="row in workspace.surgical_revenue_rows" :key="row.code" class="grid grid-cols-[minmax(260px,1.8fr)_repeat(4,minmax(110px,0.6fr))] border-b border-border px-5 py-3 text-sm last:border-0"><strong class="font-medium text-foreground">{{ row.name }}</strong><span class="text-muted-foreground">—</span><span class="text-muted-foreground">—</span><span class="text-muted-foreground">—</span><span class="text-muted-foreground">—</span></div>
                </div>
            </div>
        </section>

        <section v-else-if="workspace.code === 'SETTINGS'" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="rounded-lg border border-border bg-card p-5"><label class="mb-1.5 block text-sm font-medium text-foreground">Nom de l’application</label><div class="flex h-10 items-center rounded border border-border bg-muted px-4 text-sm font-medium text-muted-foreground">{{ brand }}</div><p class="mt-2 text-xs leading-5 text-muted-foreground">La modification sera activée avec la commande API idempotente qui diffusera la valeur séparément aux sites sélectionnés.</p></div>
            <div class="rounded-lg border border-border bg-card p-5"><h2 class="text-sm font-bold text-foreground">Cibles</h2><ul class="mt-3 space-y-2"><li v-for="site in sites" :key="site.code" class="flex items-center justify-between text-xs text-muted-foreground"><span>{{ site.name }}</span><span>API {{ site.integration_status === 'CONFIGURED' ? 'configurée' : 'à configurer' }}</span></li></ul></div>
        </section>

        <section v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="area in workspace.areas" :key="area" class="rounded-lg border border-border bg-card p-5"><div class="flex items-start justify-between gap-3"><span class="flex h-9 w-9 items-center justify-center rounded bg-muted text-muted-foreground dark:text-muted-foreground"><component class="h-4.5 w-4.5" :is="lucideIcon(workspace.icon)" /></span><span class="rounded bg-muted px-2 py-1 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">API requise</span></div><h2 class="mt-4 text-sm font-bold text-foreground">{{ area }}</h2><p class="mt-1 text-xs leading-5 text-muted-foreground">Gestion centralisée par site, avec autorisation locale et audit.</p></article>
        </section>

        <div class="flex items-start gap-3 rounded-lg border border-border bg-card p-4"><Info class="mt-0.5 text-muted-foreground h-4.5 w-4.5" /><p class="text-xs leading-5 text-muted-foreground">Cet espace pose la séparation des responsabilités et la navigation. Les actions distantes resteront désactivées jusqu’à l’implémentation des API authentifiées, des clés d’idempotence, des files de reprise et de l’audit dans chaque site.</p></div>
    </div>
</template>
