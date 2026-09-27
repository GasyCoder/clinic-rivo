<script setup>
import { computed } from 'vue';
import { CalendarClock, History } from 'lucide-vue-next';
import { formatDateTime, olderThanToday } from '@/utilities/date';

/**
 * Quand des constantes ont été relevées : **toujours la date avec l'heure**.
 *
 * L'heure seule (« 01:10 ») ne dit pas si c'était ce matin ou il y a deux
 * mois — un passage peut rester ouvert, un patient revenir des semaines plus
 * tard. Un relevé d'un autre jour le dit en plus, en ambre : « il y a 2 mois ».
 * Sans date connue, l'écran l'écrit plutôt que d'en inventer une.
 *
 * Un seul composant pour la bande des constantes (Médecine, Maternité,
 * Hospitalisation), le détail du passage et le dossier patient.
 */
const props = defineProps({
    /** Dernier enregistrement de la fiche Soins (`updated_at`). */
    at: { type: [String, Date], default: null },
    title: { type: String, default: 'Date et heure du dernier enregistrement de la fiche Soins' },
    /** Lu par les lecteurs d'écran avant la date ; vide quand le texte autour le dit déjà. */
    srPrefix: { type: String, default: 'Relevées le ' },
});

const label = computed(() => formatDateTime(props.at));
const age = computed(() => olderThanToday(props.at));
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1.5">
        <span
            class="inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-muted px-1.5 py-0.5 text-xs font-semibold tabular-nums text-foreground"
            :title="title"
        >
            <CalendarClock class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
            <span v-if="srPrefix" class="sr-only">{{ srPrefix }}</span>{{ label ?? 'Date inconnue' }}
        </span>
        <span
            v-if="age"
            class="inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
            title="Ces constantes ne sont pas du jour : recontrôlez-les si besoin."
        >
            <History class="h-3 w-3" aria-hidden="true" />{{ age }}
        </span>
    </span>
</template>
