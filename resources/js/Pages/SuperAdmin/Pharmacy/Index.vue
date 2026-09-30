<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Building2, CircleAlert, Hand, Pill } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

defineOptions({ layout: AppLayout });

/**
 * ADR-231 — point d'entrée unique de la Pharmacie dans la navigation du
 * portail. Les écrans et les données restent ceux du site, servis par API.
 */
defineProps({
    sites: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Pharmacies des sites" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Pharmacie & stocks"
            title="Pharmacies des sites"
            description="Chaque pharmacie conserve ses médicaments, ses lots, ses achats et ses files. Choisissez un site pour ouvrir ses vrais écrans, servis par son API."
            :icon="Pill"
        />

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Card v-for="site in sites" :key="site.code" class="p-0">
                <Link
                    v-if="site.configured"
                    :href="site.url"
                    class="group flex items-center gap-3 rounded-xl p-4 transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-heading text-base font-bold text-foreground">{{ site.name }}</span>
                        <span class="block text-xs text-muted-foreground">Ouvrir sa pharmacie</span>
                    </span>
                    <ArrowRight class="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                </Link>
                <div v-else class="flex items-center gap-3 p-4 opacity-70">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><Building2 class="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-heading text-base font-bold text-foreground">{{ site.name }}</span>
                        <span class="mt-0.5 inline-flex items-center gap-1 text-xs text-muted-foreground"><CircleAlert class="h-3.5 w-3.5" />L’API de ce site n’est pas configurée</span>
                    </span>
                    <Badge variant="outline">Indisponible</Badge>
                </div>
            </Card>
        </div>

        <p class="flex items-start gap-2 px-1 text-xs text-muted-foreground">
            <Hand class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>Délivrer, servir un consommable, réceptionner physiquement, inventorier et ajuster le stock restent des gestes du site. Le portail les montre verrouillés et n’encaisse jamais à la Pharmacie.</span>
        </p>
    </div>
</template>
