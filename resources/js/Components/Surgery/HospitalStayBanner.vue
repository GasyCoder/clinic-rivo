<script setup>
import { computed } from 'vue';
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
const props = defineProps({
    stay: { type: Object, required: true },
    /** Demande annulée : le patient reste hospitalisé, mais ne descend plus au bloc. */
    cancelled: { type: Boolean, default: false },
    /**
     * Pastille dans la ligne du titre du dossier, plutôt qu'un bandeau pleine
     * largeur : le fait reste sous les yeux sans repousser la page. Le détail
     * se lit au survol.
     */
    inline: { type: Boolean, default: false },
});

const headline = computed(() => (props.cancelled
    ? 'Patient hospitalisé — la demande de bloc est annulée'
    : 'Patient hospitalisé — il remonte à son lit après le bloc'));
const location = computed(() => [props.stay.service, props.stay.room_bed ? `Chambre / lit ${props.stay.room_bed}` : null].filter(Boolean).join(' · ')
    || 'Service et chambre non renseignés');
const detail = computed(() => `${headline.value}. ${location.value} · admis le ${formatDateTime(props.stay.admitted_at)}.${props.stay.url ? ' Cliquer pour ouvrir le séjour.' : ''}`);
const pillLabel = computed(() => ['Hospitalisé', props.stay.room_bed ?? props.stay.service].filter(Boolean).join(' · '));
const PILL = 'inline-flex max-w-full items-center gap-1.5 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200 print:hidden';
</script>

<template>
    <component
        :is="stay.url ? Link : 'span'"
        v-if="inline"
        :href="stay.url ?? undefined"
        :class="[PILL, stay.url && 'transition-colors hover:border-sky-300 hover:bg-sky-100 dark:hover:bg-sky-900/50']"
        :title="detail"
        :aria-label="detail"
    >
        <BedDouble class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span class="truncate">{{ pillLabel }}</span>
        <ExternalLink v-if="stay.url" class="h-3 w-3 shrink-0 opacity-70" aria-hidden="true" />
    </component>
    <section
        v-else
        class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100 print:hidden"
        aria-label="Patient hospitalisé"
    >
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-200">
            <BedDouble class="h-4 w-4" aria-hidden="true" />
        </span>
        <div class="min-w-[14rem] flex-1">
            <p class="font-semibold">{{ headline }}</p>
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
