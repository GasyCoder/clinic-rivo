<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CalendarClock, Construction } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { returnLabel, windowLabel } from '@/utilities/maintenance';

/**
 * ADR-193 — la maintenance du site dite aux personnes connectées :
 *
 *   à venir   dans les 24 heures qui précèdent son début, pour enregistrer son
 *             travail avant la fermeture ;
 *   en cours  seulement pour le compte qui la traverse (droit dédié) : les autres
 *             voient la page de maintenance, jamais ce bandeau.
 *
 * Servi par le serveur (`site.maintenance`), jamais calculé ici.
 */
const page = usePage();
const maintenance = computed(() => page.props.site?.maintenance ?? null);
const upcoming = computed(() => maintenance.value?.state === 'UPCOMING');
const bypassing = computed(() => maintenance.value?.state === 'ACTIVE' && maintenance.value.bypassing);
</script>

<template>
    <div
        v-if="upcoming || bypassing"
        :class="cn(
            'mb-5 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm',
            upcoming
                ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100'
                : 'border-destructive/40 bg-destructive/10 text-foreground',
        )"
        role="status"
        data-maintenance-banner
    >
        <component :is="upcoming ? CalendarClock : Construction" :class="cn('mt-0.5 h-4 w-4 shrink-0', upcoming ? 'text-amber-600 dark:text-amber-300' : 'text-destructive')" aria-hidden="true" />
        <p v-if="upcoming">
            <span class="font-semibold">Maintenance prévue {{ windowLabel(maintenance.starts_at, maintenance.ends_at) }}.</span>
            Le site sera alors fermé : enregistrez votre travail avant.
        </p>
        <p v-else>
            <span class="font-semibold">Site en maintenance.</span>
            Vous y accédez grâce au droit « Utiliser le site pendant sa maintenance » ; les autres comptes voient la page de maintenance. {{ returnLabel(maintenance.ends_at) }}
        </p>
    </div>
</template>
