<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Building2, CircleAlert, Handshake } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

/**
 * ADR-211 — les Partenaires se gèrent site par site : chaque site a les siens,
 * dans sa base. Le portail les ouvre par l'API du site, avec les mêmes écrans
 * et les mêmes règles qu'au site.
 */
defineProps({
    sites: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Partenaires" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Référentiels · Partenaires"
            title="Partenaires des sites"
            description="Chaque site a ses partenaires : médicaux (médecins, infirmiers…) et autres (écoles, entreprises). Choisissez un site pour les consulter et les gérer."
            :icon="Handshake"
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
                        <span class="block text-xs text-muted-foreground">Ouvrir ses partenaires</span>
                    </span>
                    <ArrowRight class="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                </Link>
                <div v-else :class="cn('flex items-center gap-3 p-4 opacity-70')">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><Building2 class="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-heading text-base font-bold text-foreground">{{ site.name }}</span>
                        <span class="mt-0.5 inline-flex items-center gap-1 text-xs text-muted-foreground"><CircleAlert class="h-3.5 w-3.5" />L’API de ce site n’est pas configurée</span>
                    </span>
                    <Badge variant="outline">Indisponible</Badge>
                </div>
            </Card>
        </div>
    </div>
</template>
