<script setup>
import { computed } from 'vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import BlockingIssuesAlert from '@/Components/Surgery/BlockingIssuesAlert.vue';
import { cn } from '@/lib/cn';
import { Check, CircleAlert, CircleCheck, Clock, ShieldCheck, TriangleAlert } from 'lucide-vue-next';

/**
 * ADR-170 — « État de préparation opératoire ».
 *
 * Les lignes et leur état viennent du serveur (`SurgicalReadinessPresenter`) :
 * l'écran ne recalcule aucune règle. Quatre états seulement, chacun avec son
 * mot et son icône — la couleur ne dit jamais rien à elle seule :
 *
 *   Terminé · À faire · Bloquant · Attention
 */
const props = defineProps({
    /** @type {{can_start_intervention: boolean, blockers: [], warnings: [], steps: []}} */
    readiness: { type: Object, required: true },
    /** Masque la liste des blocages quand l'écran l'affiche déjà ailleurs. */
    showIssues: { type: Boolean, default: true },
});

const STATES = {
    DONE: { label: 'Terminé', icon: CircleCheck, variant: 'success', row: 'text-foreground' },
    PENDING: { label: 'À faire', icon: Clock, variant: 'outline', row: 'text-muted-foreground' },
    BLOCKING: { label: 'Bloquant', icon: CircleAlert, variant: 'destructive', row: 'text-foreground' },
    WARNING: { label: 'Attention', icon: TriangleAlert, variant: 'warning', row: 'text-foreground' },
};

const steps = computed(() => props.readiness.steps ?? []);
const done = computed(() => steps.value.filter((step) => step.status === 'DONE').length);
const ready = computed(() => props.readiness.can_start_intervention === true);
</script>

<template>
    <Card class="overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3.5">
            <span
                :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', ready ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-primary/10 text-primary')"
                aria-hidden="true"
            >
                <ShieldCheck class="h-4 w-4" />
            </span>
            <div class="min-w-0 flex-1 basis-56">
                <h2 class="text-sm font-semibold text-foreground">État de préparation opératoire</h2>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ done }} / {{ steps.length }} points réunis</p>
            </div>
            <Badge :variant="ready ? 'success' : 'destructive'">
                <component :is="ready ? Check : CircleAlert" class="h-3 w-3" aria-hidden="true" />
                {{ ready ? 'Prêt pour le bloc' : 'Incision bloquée' }}
            </Badge>
        </header>

        <ul class="divide-y divide-border">
            <li
                v-for="step in steps"
                :key="step.key"
                class="flex flex-wrap items-start gap-x-3 gap-y-1 px-5 py-2.5"
            >
                <component
                    :is="STATES[step.status].icon"
                    :class="cn('mt-0.5 h-4 w-4 shrink-0', {
                        DONE: 'text-emerald-600 dark:text-emerald-400',
                        PENDING: 'text-muted-foreground',
                        BLOCKING: 'text-destructive',
                        WARNING: 'text-amber-600 dark:text-amber-400',
                    }[step.status])"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1 basis-56">
                    <p :class="cn('text-sm font-medium', STATES[step.status].row)">{{ step.label }}</p>
                    <p v-if="step.detail" class="mt-0.5 text-xs text-muted-foreground">{{ step.detail }}</p>
                </div>
                <Badge :variant="STATES[step.status].variant" class="shrink-0">{{ STATES[step.status].label }}</Badge>
            </li>
        </ul>

        <div v-if="showIssues && (readiness.blockers?.length || readiness.warnings?.length)" class="space-y-3 border-t border-border px-5 py-4">
            <BlockingIssuesAlert :issues="readiness.blockers" level="blocking" />
            <BlockingIssuesAlert :issues="readiness.warnings" level="warning" />
        </div>
    </Card>
</template>
