<script setup>
import { CircleCheck, CircleDashed, FlaskConical, Hourglass, Printer, ScanLine, Undo2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { cn } from '@/lib/cn';

/**
 * ADR-204 — le suivi paraclinique de la grossesse, consultation par consultation.
 *
 * Relu, jamais recopié : chaque ligne vient d'une demande du Laboratoire ou de
 * l'imagerie, avec son état réel. Une famille sans droit de lecture n'est pas
 * servie — l'écran le dit au lieu d'afficher une liste vide qui se lirait
 * « aucun examen ».
 */
defineProps({
    /** `{ groups, restricted, counts }` — servi par `PregnancyParaclinicalHistory`. */
    history: { type: Object, default: null },
});

const STATUS = {
    DONE: { label: 'Résultat', icon: CircleCheck, tone: 'success' },
    REQUESTED: { label: 'En attente', icon: Hourglass, tone: 'warning' },
    CANCELLED: { label: 'Retiré', icon: Undo2, tone: 'neutral' },
};
</script>

<template>
    <div class="space-y-3">
        <p v-if="! history" class="rounded-lg border border-dashed border-border px-4 py-6 text-center text-xs text-muted-foreground">
            Reliez d’abord cette prise en charge à une grossesse : son suivi paraclinique se lit sur toutes ses consultations.
        </p>
        <template v-else>
            <p v-if="history.restricted.lab || history.restricted.imaging" class="text-[11px] text-muted-foreground">
                <template v-if="history.restricted.lab">Analyses non visibles avec vos droits (laboratory_orders.view). </template>
                <template v-if="history.restricted.imaging">Imagerie non visible avec vos droits (imaging_orders.view).</template>
            </p>

            <ol class="relative space-y-3 border-s border-border ps-4">
                <li v-for="group in history.groups" :key="group.record_uuid" class="relative">
                    <span :class="cn('absolute -start-[1.3rem] top-1 h-2.5 w-2.5 rounded-full border-2 border-card', group.is_current ? 'bg-primary' : 'bg-muted-foreground/60')" aria-hidden="true" />
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                        <p class="text-sm font-bold text-foreground">{{ group.label }}<template v-if="group.gestational_age_label"> · {{ group.gestational_age_label }}</template></p>
                        <p class="text-xs text-muted-foreground">{{ formatDate(group.at) }}<template v-if="group.episode_number"> · {{ group.episode_number }}</template></p>
                        <Badge v-if="group.is_current" tone="info" class="px-1.5 py-0 text-[10px]">Ce passage</Badge>
                    </div>
                    <ul v-if="group.entries.length" class="mt-1.5 space-y-1">
                        <li v-for="entry in group.entries" :key="entry.item_uuid" class="flex flex-wrap items-center gap-x-2 gap-y-0.5 rounded-md border border-border bg-card px-2.5 py-1.5 text-xs">
                            <component :is="entry.kind === 'LAB' ? FlaskConical : ScanLine" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" :aria-label="entry.kind === 'LAB' ? 'Analyse' : 'Imagerie'" />
                            <span class="font-semibold text-foreground">{{ entry.exam }}</span>
                            <Badge :tone="STATUS[entry.status]?.tone" class="gap-1 px-1.5 py-0 text-[10px]">
                                <component :is="STATUS[entry.status]?.icon ?? CircleDashed" class="h-3 w-3" aria-hidden="true" />{{ STATUS[entry.status]?.label ?? entry.status }}
                            </Badge>
                            <span class="text-muted-foreground">{{ entry.origin }}</span>
                            <span v-if="entry.resulted_at" class="text-muted-foreground">· rendu le {{ formatDateTime(entry.resulted_at) }}</span>
                            <span v-if="entry.result" class="w-full truncate text-muted-foreground" :title="entry.result">Résultat : {{ entry.result }}</span>
                            <Button v-if="entry.print_url" as="a" :href="entry.print_url" size="icon-xs" variant="ghost" class="ms-auto" :aria-label="`Compte rendu — ${entry.exam}`" title="Compte rendu"><Printer class="h-3.5 w-3.5" /></Button>
                        </li>
                    </ul>
                    <p v-else class="mt-1 text-xs text-muted-foreground">Aucune demande.</p>
                </li>
            </ol>
        </template>
    </div>
</template>
