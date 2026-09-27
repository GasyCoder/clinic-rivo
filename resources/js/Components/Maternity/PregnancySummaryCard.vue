<script setup>
import { computed } from 'vue';
import { CalendarCheck, CalendarDays, History, ShieldAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { DUE_DATE_TONES, dueDateCountdown } from '@/utilities/pregnancyDueDate';

/**
 * La grossesse longitudinale, en tête du panneau « Suivi de la grossesse »
 * (ADR-201). Tout vient du serveur : DDR, DPA, terme, consultations.
 *
 * Dans le dossier, c'est l'en-tête du parcours qui porte la grossesse et ses
 * gestes (continuer, corriger la datation) : cette carte ne sert plus qu'au
 * panneau d'historique, en lecture (ADR-204).
 */
const props = defineProps({
    pregnancy: { type: Object, required: true },
});

const title = computed(() => (props.pregnancy.status === 'ONGOING' ? 'Grossesse actuelle' : 'Grossesse'));
const dueDate = computed(() => dueDateCountdown(props.pregnancy.estimated_due_date, props.pregnancy.status));
const termHint = computed(() => (props.pregnancy.gestational_age_source === 'snapshot'
    ? 'Terme enregistré avec cette consultation : une correction de datation ne le réécrit pas.'
    : 'Calculé par le serveur depuis la datation de la grossesse.'));
</script>

<template>
    <Card class="overflow-hidden">
        <div class="flex items-start gap-3 border-b border-border px-5 py-4">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                <CalendarDays class="h-5 w-5" aria-hidden="true" />
            </span>
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">{{ title }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-bold text-foreground">{{ pregnancy.reference }}</h2>
                    <Badge :tone="pregnancy.status === 'ONGOING' ? 'info' : 'success'">{{ pregnancy.status_label }}</Badge>
                </div>
            </div>
        </div>

        <!-- Dans un panneau latéral : trois colonnes au plus, le dernier repère prend le reste de la ligne. -->
        <dl class="grid grid-cols-2 gap-px bg-border sm:grid-cols-3">
            <div class="bg-card px-5 py-3">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">DDR</dt>
                <dd class="mt-1 text-sm font-semibold text-foreground">{{ formatDate(pregnancy.last_menstrual_period) ?? 'Non renseignée' }}</dd>
            </div>
            <div class="bg-rose-50 px-5 py-3 dark:bg-rose-950/25">
                <dt class="flex items-center gap-1 text-[11px] font-bold uppercase tracking-wide text-rose-700 dark:text-rose-300">
                    <CalendarCheck class="h-3.5 w-3.5" aria-hidden="true" />DPA<span class="sr-only"> — date prévue d’accouchement</span>
                </dt>
                <dd class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="text-base font-bold tabular-nums text-rose-700 dark:text-rose-300">{{ formatDate(pregnancy.estimated_due_date) ?? 'Non renseignée' }}</span>
                    <span v-if="dueDate" :class="['rounded-full px-2 py-px text-[11px] font-semibold', DUE_DATE_TONES[dueDate.tone]]">{{ dueDate.label }}</span>
                </dd>
            </div>
            <div class="bg-card px-5 py-3" :title="pregnancy.gestational_age_label ? termHint : undefined">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Terme au passage</dt>
                <dd class="mt-1 text-sm font-semibold text-foreground">{{ pregnancy.gestational_age_label ?? 'Non calculable' }}</dd>
            </div>
            <div class="bg-card px-5 py-3">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Consultations</dt>
                <dd class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-foreground"><History class="h-3.5 w-3.5" aria-hidden="true" />{{ pregnancy.consultations_count }}</dd>
            </div>
            <div class="col-span-2 bg-card px-5 py-3">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Dernière consultation</dt>
                <dd class="mt-1 text-sm font-semibold text-foreground">{{ formatDateTime(pregnancy.last_consultation_at) ?? 'Aucune' }}</dd>
            </div>
        </dl>

        <div v-if="pregnancy.risk_factors" class="flex items-start gap-2 border-t border-border bg-amber-50/60 px-5 py-2.5 text-xs text-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p><strong>Facteurs de risque consignés :</strong> {{ pregnancy.risk_factors }}</p>
        </div>
    </Card>
</template>
