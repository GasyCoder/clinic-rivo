<script setup>
import { computed } from 'vue';
import { CheckCircle2, Circle, Eye, Lock } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';

/**
 * En-tête commun aux trois étapes de l'espace Anesthésie : l'icône du geste,
 * où l'on en est (« Étape 2 sur 3 »), et l'avancement des sous-étapes.
 *
 * L'avancement ne décide rien : il lit les mêmes indicateurs `complete` que
 * les sections repliables. Chaque sous-étape porte son mot (« fait » / « à
 * faire ») en plus de sa couleur — la couleur ne porte jamais seule l'état.
 */
const props = defineProps({
    icon: { type: [Object, Function], required: true },
    step: { type: Number, required: true },
    total: { type: Number, default: 3 },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    sections: { type: Array, default: () => [] },
    /** VALIDATED | LOCKED | READONLY | null */
    status: { type: String, default: null },
    statusLabel: { type: String, default: '' },
});

const done = computed(() => props.sections.filter((section) => section.complete).length);
const percent = computed(() => (props.sections.length ? Math.round((done.value / props.sections.length) * 100) : 0));
</script>

<template>
    <header class="border-b border-border bg-muted/30 px-4 py-4 sm:px-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15">
                    <component :is="icon" class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-primary">Anesthésie · étape {{ step }} sur {{ total }}</p>
                    <h2 class="mt-0.5 font-heading text-base font-bold text-foreground sm:text-lg">{{ title }}</h2>
                    <p v-if="description" class="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">{{ description }}</p>
                </div>
            </div>

            <div class="flex shrink-0 flex-col items-start gap-2 lg:items-end">
                <Badge v-if="status === 'VALIDATED'" variant="success"><CheckCircle2 class="h-3.5 w-3.5" aria-hidden="true" />{{ statusLabel || 'Évaluation validée' }}</Badge>
                <Badge v-else-if="status === 'LOCKED'" variant="success"><Lock class="h-3.5 w-3.5" aria-hidden="true" />{{ statusLabel || 'Dossier validé' }}</Badge>
                <Badge v-else-if="status === 'READONLY'" variant="outline"><Eye class="h-3.5 w-3.5" aria-hidden="true" />{{ statusLabel || 'Lecture seule' }}</Badge>

                <div v-if="sections.length" class="w-full min-w-[14rem] lg:w-60">
                    <div class="flex items-center justify-between text-xs text-muted-foreground">
                        <span>Avancement</span>
                        <span class="font-semibold text-foreground">{{ done }} / {{ sections.length }}</span>
                    </div>
                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" :aria-label="`${done} sous-étape(s) sur ${sections.length} renseignée(s)`">
                        <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${percent}%` }" />
                    </div>
                    <ul class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
                        <li v-for="section in sections" :key="section.label" class="inline-flex items-center gap-1 text-[11px]" :class="section.complete ? 'text-foreground' : 'text-muted-foreground'">
                            <CheckCircle2 v-if="section.complete" class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                            <Circle v-else class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ section.label }}<span class="sr-only"> : {{ section.complete ? 'renseigné' : 'à renseigner' }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </header>
</template>
