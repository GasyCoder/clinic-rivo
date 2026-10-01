<script setup>
import { computed } from 'vue';
import { Check, CircleAlert, CircleDashed, Lock } from 'lucide-vue-next';
import Card from '@/Components/Shadcn/Card.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-221 — la barre d'étapes du dossier employé, la même à la création et à
 * la modification : une pastille par étape, l'avancement au-dessus, l'étape en
 * cours nommée dessous sur un écran étroit.
 *
 *   locked     à la création : tant que le dossier n'existe pas, seule
 *              l'Identité s'ouvre (il n'y a rien où enregistrer le reste)
 *   stateOf    l'état d'enregistrement d'une étape : une étape à compléter ou
 *              refusée le montre sur sa pastille
 */
const props = defineProps({
    steps: { type: Array, required: true },
    current: { type: String, required: true },
    locked: { type: Boolean, default: false },
    stateOf: { type: Function, default: () => 'idle' },
    /** Aussi employée par la fiche d'une analyse (ADR-063) : ses libellés lui sont propres. */
    navLabel: { type: String, default: 'Étapes du dossier employé' },
    lockedHint: { type: String, default: 'après la création du dossier' },
});
const emit = defineEmits(['select']);

const currentIndex = computed(() => Math.max(0, props.steps.findIndex((step) => step.key === props.current)));
const currentStep = computed(() => props.steps[currentIndex.value]);
const progress = computed(() => Math.round(((currentIndex.value + 1) / props.steps.length) * 100));

const kind = (step, index) => {
    if (step.key === props.current) return 'current';
    if (props.locked && index > 0) return 'locked';
    if (['failed', 'incomplete'].includes(props.stateOf(step.key))) return 'attention';
    return index < currentIndex.value ? 'done' : 'reached';
};
const select = (step, index) => {
    if (kind(step, index) === 'locked') return;
    emit('select', step.key);
};
</script>

<template>
    <Card class="overflow-hidden">
        <div class="h-0.5 bg-muted" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" :aria-label="`Avancement : ${progress} %`">
            <div class="h-full bg-primary transition-[width] duration-300" :style="{ width: `${progress}%` }" />
        </div>
        <nav :aria-label="navLabel">
            <ol class="grid divide-x divide-border" :style="{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }">
                <li v-for="(step, index) in steps" :key="step.key">
                    <button
                        type="button"
                        :disabled="kind(step, index) === 'locked'"
                        :title="kind(step, index) === 'locked' ? `${step.label} — ${lockedHint}` : step.hint"
                        :aria-current="kind(step, index) === 'current' ? 'step' : undefined"
                        :aria-label="`Étape ${step.number} : ${step.label}${kind(step, index) === 'locked' ? ` (${lockedHint})` : ''}${kind(step, index) === 'attention' ? ' (à reprendre)' : ''}`"
                        :class="cn(
                            'flex h-full w-full items-center justify-center gap-2 px-1 py-2 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring lg:flex-col lg:gap-1 lg:px-1.5 lg:py-2.5',
                            kind(step, index) === 'current' ? 'bg-primary/5' : 'hover:bg-accent/60',
                            kind(step, index) === 'locked' && 'cursor-not-allowed opacity-50 hover:bg-transparent',
                        )"
                        @click="select(step, index)"
                    >
                        <span
                            :class="cn(
                                'grid h-7 w-7 shrink-0 place-items-center rounded-full text-[11px] font-bold ring-1 ring-inset',
                                kind(step, index) === 'current' && 'bg-primary text-primary-foreground ring-primary',
                                kind(step, index) === 'done' && 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
                                kind(step, index) === 'attention' && 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',
                                ['reached', 'locked'].includes(kind(step, index)) && 'bg-muted text-muted-foreground ring-border',
                            )"
                            aria-hidden="true"
                        >
                            <Check v-if="kind(step, index) === 'done'" class="h-4 w-4" :stroke-width="3" />
                            <Lock v-else-if="kind(step, index) === 'locked'" class="h-3.5 w-3.5" />
                            <CircleAlert v-else-if="kind(step, index) === 'attention' && stateOf(step.key) === 'failed'" class="h-4 w-4" />
                            <CircleDashed v-else-if="kind(step, index) === 'attention'" class="h-4 w-4" />
                            <component :is="step.icon" v-else class="h-4 w-4" />
                        </span>
                        <span :class="cn('hidden w-full truncate text-center text-xs font-semibold lg:block', kind(step, index) === 'current' ? 'text-primary' : 'text-foreground')">{{ step.label }}</span>
                    </button>
                </li>
            </ol>
        </nav>
        <p class="border-t border-border px-3 py-1.5 text-xs text-muted-foreground lg:hidden" aria-hidden="true">
            Étape {{ currentStep?.number }} sur {{ steps.length }} · <strong class="font-semibold text-foreground">{{ currentStep?.label }}</strong> — {{ currentStep?.hint }}
        </p>
    </Card>
</template>
