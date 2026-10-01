<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Building2, CircleAlert, FlaskConical, Hand, Settings } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

/**
 * ADR-215 — le Laboratoire de chaque site, lu par son API : la file, les
 * demandes, les résultats, l'historique et les rapports, avec les mêmes écrans
 * et les mêmes règles qu'au site. Les référentiels (prélèvements et tubes,
 * microbiologie) s'y gèrent ; les gestes cliniques restent au site.
 */
defineProps({
    sites: { type: Array, default: () => [] },
});
const { can } = usePermissions();
</script>

<template>
    <Head title="Laboratoire des sites" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Laboratoire · Sites"
            title="Laboratoire des sites"
            description="Chaque site a son laboratoire, sa file et ses référentiels. Choisissez un site pour lire ses demandes, ses résultats et ses rapports, et gérer ses prélèvements, tubes, germes et antibiotiques."
            :icon="FlaskConical"
        >
            <template v-if="can('settings.view')" #actions>
                <Button :as="Link" href="/super-admin/laboratory/settings/compte-rendu" variant="outline"><Settings class="h-4 w-4" />Configurer les comptes rendus</Button>
            </template>
        </PageHeader>

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
                        <span class="block text-xs text-muted-foreground">Ouvrir son laboratoire</span>
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
            <span>Réceptionner, prélever, saisir, terminer, valider, renvoyer, signaler un critique, confier à l’extérieur et conclure se font au laboratoire du site, par la personne qui a le prélèvement sous les yeux : ces boutons sont montrés verrouillés depuis le portail.</span>
        </p>
    </div>
</template>
