<script setup>
import { CalendarClock, Stethoscope, UserRound } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import StayDiagnosisAdd from '@/Components/Hospitalization/StayDiagnosisAdd.vue';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';

/**
 * ADR-147 — ce que le séjour a conclu, et ce que le dossier avait déjà consigné.
 *
 * Placée sous la carte « Séjour » : on lit où est le patient, puis ce qu'il a.
 * Chaque diagnostic dit d'où il vient — la consultation qui a précédé, ou le
 * séjour lui-même — et qui l'a posé. La sortie n'est plus ici (ADR-156) : ces
 * diagnostics restent la trace clinique du séjour.
 */
defineProps({
    stayUuid: { type: String, required: true },
    /** Ceux du passage et ceux du séjour, déjà consignés (servis avec `diagnoses.view`). */
    diagnoses: { type: Array, default: () => [] },
    canAdd: { type: Boolean, default: false },
});

const ORIGINS = {
    CONSULTATION: { label: 'Consultation', variant: 'outline' },
    STAY: { label: 'Séjour', variant: 'secondary' },
};
const origin = (value) => ORIGINS[value] ?? null;
</script>

<template>
    <Card class="flex flex-col overflow-hidden">
        <header class="flex items-start gap-3 border-b border-border px-5 py-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                <Stethoscope class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground">
                    Diagnostics
                    <Badge v-if="diagnoses.length" variant="outline" class="tabular-nums">{{ diagnoses.length }}</Badge>
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">Posés en consultation et pendant le séjour</p>
            </div>
        </header>

        <div class="flex-1 px-5 py-4">
            <ul v-if="diagnoses.length" class="space-y-2">
                <li
                    v-for="diagnosis in diagnoses"
                    :key="diagnosis.id"
                    class="rounded-lg border border-border bg-muted/30 px-3.5 py-2.5"
                >
                    <p class="whitespace-pre-line text-sm font-semibold text-foreground">{{ diagnosis.description }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <Badge v-if="origin(diagnosis.origin)" :variant="origin(diagnosis.origin).variant" class="px-2 py-0.5 text-[11px]">{{ origin(diagnosis.origin).label }}</Badge>
                        <span v-if="diagnosis.recorded_by" class="inline-flex items-center gap-1">
                            <UserRound class="h-3 w-3 shrink-0" aria-hidden="true" />{{ doctorName(diagnosis.recorded_by) }}
                        </span>
                        <span v-if="diagnosis.recorded_at" class="inline-flex items-center gap-1 tabular-nums">
                            <CalendarClock class="h-3 w-3 shrink-0" aria-hidden="true" />{{ formatDateTime(diagnosis.recorded_at) }}
                        </span>
                    </p>
                </li>
            </ul>
            <p v-else class="flex items-center gap-2 rounded-lg bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground">
                <Stethoscope class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Aucun diagnostic consigné pour ce passage.
            </p>
        </div>

        <footer v-if="canAdd" class="border-t border-border bg-muted/20 px-5 py-3">
            <StayDiagnosisAdd :stay-uuid="stayUuid" />
        </footer>
    </Card>
</template>
