<script setup>
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import { BedDouble, ExternalLink } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';

/**
 * ADR-160 — le patient vient de son lit et y remonte après le bloc.
 *
 * Chirurgie et Anesthésie le lisent au même endroit : sans ce bandeau, rien
 * dans le dossier ne disait qu'un lit l'attend, ni dans quel service. Le lien
 * vers le séjour n'est servi qu'avec le droit de l'ouvrir.
 */
defineProps({
    stay: { type: Object, required: true },
});
</script>

<template>
    <section
        class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100 print:hidden"
        aria-label="Patient hospitalisé"
    >
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-200">
            <BedDouble class="h-4 w-4" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold">Patient hospitalisé — il remonte à son lit après le bloc</p>
            <p class="text-xs opacity-80">
                <template v-if="stay.service">{{ stay.service }}</template>
                <template v-if="stay.service && stay.room_bed"> · </template>
                <template v-if="stay.room_bed">Chambre / lit {{ stay.room_bed }}</template>
                <template v-if="!stay.service && !stay.room_bed">Service et chambre non renseignés</template>
                · admis le {{ formatDateTime(stay.admitted_at) }}
            </p>
        </div>
        <Button v-if="stay.url" :as="Link" :href="stay.url" size="sm" variant="white-outline" class="shrink-0">
            <ExternalLink class="h-4 w-4" />Voir le séjour
        </Button>
    </section>
</template>
