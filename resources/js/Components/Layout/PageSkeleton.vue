<script setup>
import { computed } from 'vue';
import Card from '@/Components/Shadcn/Card.vue';
import Skeleton from '@/Components/Shadcn/Skeleton.vue';
import { skeletonFor } from '@/utilities/pageSkeleton';

/**
 * Le squelette d'une page en cours de chargement (shadcn-vue), à la forme de
 * la page qui arrive : tableau de bord, liste, fiche, formulaire, document
 * imprimable ou réglages — lue sur l'adresse visée (`skeletonFor`).
 *
 * Il n'annonce que « chargement » aux lecteurs d'écran : les blocs gris ne
 * disent rien d'utile, ils sont masqués (`aria-hidden` sur chaque Skeleton).
 */
const props = defineProps({
    path: { type: String, default: '' },
});

const kind = computed(() => skeletonFor(props.path));
</script>

<template>
    <div class="w-full space-y-5" role="status" aria-live="polite" aria-busy="true" :data-skeleton="kind">
        <span class="sr-only">Chargement de la page…</span>

        <!-- En-tête commun : surtitre, titre, phrase. -->
        <header v-if="kind !== 'document'" class="space-y-2">
            <Skeleton class="h-3 w-28" />
            <Skeleton class="h-7 w-72 max-w-full" />
            <Skeleton class="h-4 w-[28rem] max-w-full" />
        </header>

        <!-- Tableau de bord : compteurs, graphique, activité. -->
        <template v-if="kind === 'dashboard'">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card v-for="index in 4" :key="index" class="space-y-3 p-5">
                    <div class="flex items-center justify-between"><Skeleton class="h-3 w-24" /><Skeleton class="h-9 w-9 rounded-lg" /></div>
                    <Skeleton class="h-7 w-20" />
                    <Skeleton class="h-3 w-32" />
                </Card>
            </div>
            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <Card class="space-y-4 p-5"><Skeleton class="h-4 w-40" /><Skeleton class="h-64 w-full" /></Card>
                <Card class="space-y-4 p-5">
                    <Skeleton class="h-4 w-32" />
                    <div v-for="index in 5" :key="index" class="flex items-center gap-3"><Skeleton class="h-8 w-8 rounded-full" /><div class="flex-1 space-y-1.5"><Skeleton class="h-3 w-3/4" /><Skeleton class="h-3 w-1/2" /></div></div>
                </Card>
            </div>
        </template>

        <!-- Liste : barre d'outils, compteurs, tableau. -->
        <template v-else-if="kind === 'list'">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Card v-for="index in 4" :key="index" class="flex items-center gap-3 p-4"><Skeleton class="h-10 w-10 rounded-lg" /><div class="flex-1 space-y-1.5"><Skeleton class="h-3 w-20" /><Skeleton class="h-5 w-12" /></div></Card>
            </div>
            <Card class="overflow-hidden">
                <div class="flex flex-wrap items-center gap-3 border-b border-border p-4">
                    <Skeleton class="h-10 w-72 max-w-full" />
                    <Skeleton class="h-10 w-28" />
                    <Skeleton class="ms-auto h-10 w-36" />
                </div>
                <div class="grid grid-cols-[2fr_1fr_1fr_6rem] gap-4 border-b border-border px-4 py-3">
                    <Skeleton v-for="index in 4" :key="index" class="h-3 w-2/3" />
                </div>
                <div v-for="row in 8" :key="row" class="grid grid-cols-[2fr_1fr_1fr_6rem] items-center gap-4 border-b border-border px-4 py-3 last:border-b-0">
                    <div class="flex items-center gap-3"><Skeleton class="h-9 w-9 shrink-0 rounded-full" /><div class="flex-1 space-y-1.5"><Skeleton class="h-3.5 w-3/4" /><Skeleton class="h-3 w-1/2" /></div></div>
                    <Skeleton class="h-3.5 w-3/4" />
                    <Skeleton class="h-6 w-20 rounded-full" />
                    <Skeleton class="h-8 w-full" />
                </div>
            </Card>
        </template>

        <!-- Fiche : identité, contenu, colonne de repères. -->
        <template v-else-if="kind === 'detail'">
            <Card class="flex flex-wrap items-center gap-4 p-5">
                <Skeleton class="h-14 w-14 rounded-full" />
                <div class="min-w-0 flex-1 space-y-2"><Skeleton class="h-5 w-56 max-w-full" /><Skeleton class="h-3.5 w-80 max-w-full" /></div>
                <div class="flex gap-2"><Skeleton class="h-6 w-20 rounded-full" /><Skeleton class="h-6 w-24 rounded-full" /></div>
            </Card>
            <div class="flex gap-2 overflow-hidden"><Skeleton v-for="index in 5" :key="index" class="h-9 w-28 shrink-0" /></div>
            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <div class="space-y-5">
                    <Card v-for="card in 2" :key="card" class="space-y-4 p-5">
                        <Skeleton class="h-4 w-40" />
                        <div class="grid gap-4 sm:grid-cols-2"><div v-for="index in 4" :key="index" class="space-y-2"><Skeleton class="h-3 w-24" /><Skeleton class="h-4 w-3/4" /></div></div>
                    </Card>
                </div>
                <Card class="space-y-3 p-5"><Skeleton class="h-4 w-28" /><Skeleton v-for="index in 5" :key="index" class="h-3.5 w-full" /></Card>
            </div>
        </template>

        <!-- Formulaire : champs par paires, puis les actions. -->
        <template v-else-if="kind === 'form'">
            <Card class="space-y-6 p-5 sm:p-6">
                <Skeleton class="h-4 w-44" />
                <div class="grid gap-5 md:grid-cols-2">
                    <div v-for="index in 8" :key="index" class="space-y-2"><Skeleton class="h-3.5 w-28" /><Skeleton class="h-10 w-full" /></div>
                </div>
                <div class="space-y-2"><Skeleton class="h-3.5 w-32" /><Skeleton class="h-24 w-full" /></div>
                <div class="flex justify-end gap-2 border-t border-border pt-5"><Skeleton class="h-10 w-24" /><Skeleton class="h-10 w-36" /></div>
            </Card>
        </template>

        <!-- Document : une feuille à imprimer. -->
        <template v-else-if="kind === 'document'">
            <div class="flex justify-end gap-2"><Skeleton class="h-9 w-24" /><Skeleton class="h-9 w-32" /></div>
            <Card class="mx-auto w-full max-w-4xl space-y-6 p-8">
                <div class="flex items-center justify-between gap-4 border-b border-border pb-5"><Skeleton class="h-12 w-48" /><div class="space-y-2"><Skeleton class="ms-auto h-3 w-40" /><Skeleton class="ms-auto h-3 w-28" /></div></div>
                <Skeleton class="mx-auto h-6 w-72 max-w-full" />
                <div class="grid gap-4 sm:grid-cols-2"><div v-for="index in 6" :key="index" class="space-y-2"><Skeleton class="h-3 w-24" /><Skeleton class="h-4 w-4/5" /></div></div>
                <div class="space-y-2.5"><Skeleton v-for="index in 7" :key="index" class="h-3.5" :class="index % 3 === 0 ? 'w-3/5' : 'w-full'" /></div>
            </Card>
        </template>

        <!-- Réglages : sections et sommaire. -->
        <template v-else>
            <div class="flex gap-2 overflow-hidden"><Skeleton v-for="index in 4" :key="index" class="h-10 w-36 shrink-0" /></div>
            <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div class="space-y-5">
                    <Card v-for="card in 2" :key="card" class="overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-border px-5 py-4"><Skeleton class="h-10 w-10 rounded-lg" /><div class="flex-1 space-y-2"><Skeleton class="h-4 w-40" /><Skeleton class="h-3.5 w-3/4" /></div></div>
                        <div class="grid gap-5 p-5 md:grid-cols-2"><div v-for="index in 4" :key="index" class="space-y-2"><Skeleton class="h-3.5 w-28" /><Skeleton class="h-10 w-full" /></div></div>
                    </Card>
                </div>
                <Card class="hidden space-y-2 p-3 lg:block"><Skeleton v-for="index in 7" :key="index" class="h-8 w-full" /></Card>
            </div>
        </template>
    </div>
</template>
