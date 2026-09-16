<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Building2, Info, Server } from 'lucide-vue-next';
import { lucideIcon } from '@/lib/icons';

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
                <span class="flex h-11 w-11 items-center justify-center rounded bg-muted text-muted-foreground dark:text-muted-foreground"><Building2 class="h-5 w-5" /></span>
                <div><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Site opérationnel</p><h1 class="font-heading text-2xl font-bold text-foreground">{{ clinic.name }}</h1></div>
            </div>
            <span class="inline-flex items-center gap-2 self-start rounded border border-border bg-card px-3 py-2 text-xs font-medium text-muted-foreground sm:self-auto"><span :class="['h-1.5 w-1.5 rounded-full', clinic.integration_status === 'CONFIGURED' ? 'bg-emerald-500' : 'bg-muted-foreground/40 ']"></span>{{ clinic.integration_status === 'CONFIGURED' ? 'Endpoint API configuré' : 'Endpoint API à configurer' }}</span>
        </header>

        <nav class="flex gap-2 overflow-x-auto rounded-lg border border-border bg-card p-2" aria-label="Modules du site">
            <Link v-for="module in clinic.modules" :key="module.code" :href="`/super-admin/sites/${clinic.code}?module=${module.code}`" :class="['inline-flex shrink-0 items-center gap-2 rounded px-3 py-2 text-xs font-bold transition-colors', selectedModule.code === module.code ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground dark:hover:bg-muted dark:hover:text-muted-foreground']"><component class="h-4 w-4" :is="lucideIcon(module.icon)" />{{ module.label }}</Link>
        </nav>

        <section class="overflow-hidden rounded-lg border border-border bg-card">
            <div class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded border border-border text-muted-foreground dark:text-muted-foreground"><component class="h-4.5 w-4.5" :is="lucideIcon(selectedModule.icon)" /></span><div><h2 class="text-sm font-bold text-foreground">{{ selectedModule.label }}</h2><p class="mt-0.5 text-xs text-muted-foreground">{{ selectedModule.description }}</p></div></div>
                <span class="inline-flex items-center gap-2 self-start text-xs font-medium text-muted-foreground sm:self-auto"><Server class="h-4 w-4" />Données fournies par l’API de {{ clinic.name }}</span>
            </div>

            <div class="grid gap-px bg-muted sm:grid-cols-2 xl:grid-cols-3">
                <article v-for="area in selectedModule.areas" :key="area" class="min-h-28 bg-card p-5">
                    <div class="flex items-start justify-between gap-3"><component class="text-muted-foreground h-4.5 w-4.5" :is="lucideIcon(selectedModule.icon)" /><span class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">API requise</span></div>
                    <h3 class="mt-4 text-sm font-bold text-foreground">{{ area }}</h3>
                </article>
            </div>

            <div v-if="selectedModule.notice" class="flex items-start gap-3 border-t border-border bg-muted/70 px-5 py-4 /30"><Info class="mt-0.5 text-muted-foreground h-4 w-4" /><p class="text-xs leading-5 text-muted-foreground">{{ selectedModule.notice }}</p></div>
            <div v-else class="flex items-start gap-3 border-t border-border px-5 py-4"><Info class="mt-0.5 text-muted-foreground h-4 w-4" /><p class="text-xs leading-5 text-muted-foreground">Les permissions sont contrôlées par le portail puis par l’API du site. Toutes les actions sensibles sont auditées.</p></div>
        </section>
    </div>
</template>
