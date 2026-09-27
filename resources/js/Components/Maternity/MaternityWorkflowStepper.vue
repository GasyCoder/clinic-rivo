<script setup>
import { computed } from 'vue';
import { CircleCheck } from 'lucide-vue-next';
import { cn } from '@/lib/cn';

/**
 * ADR-204 — les étapes d'un parcours Maternité.
 *
 * Un stepper qui guide sans enfermer : chaque étape se clique, dans l'ordre
 * qu'on veut. La coche dit ce qui est renseigné ; rien n'est verrouillé.
 * Sur un téléphone, les pastilles se réduisent à leur numéro et le titre de
 * l'étape ouverte se lit en dessous.
 */
const props = defineProps({
    /** [{ key, label, short, icon, filled }] */
    steps: { type: Array, required: true },
    current: { type: String, required: true },
    label: { type: String, default: 'Étapes du dossier' },
});
defineEmits(['select']);

const index = computed(() => props.steps.findIndex((step) => step.key === props.current));
const currentStep = computed(() => props.steps[index.value] ?? null);
</script>

<template>
    <nav :aria-label="label" class="rounded-xl border border-border bg-card p-1.5 shadow-sm">
        <ol class="grid gap-1" :style="{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }">
            <li v-for="(step, position) in steps" :key="step.key" class="min-w-0">
                <button
                    type="button"
                    :aria-current="step.key === current ? 'step' : undefined"
                    :title="step.label"
                    :class="cn(
                        'flex h-11 w-full min-w-0 items-center justify-center gap-2 rounded-lg px-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:justify-start md:px-2.5',
                        step.key === current
                            ? 'bg-primary text-primary-foreground shadow-sm'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    )"
                    @click="$emit('select', step.key)"
                >
                    <span
                        :class="cn(
                            'grid h-6 w-6 shrink-0 place-items-center rounded-full text-[11px] font-bold',
                            step.key === current ? 'bg-primary-foreground/20' : step.filled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-muted',
                        )"
                    >
                        <CircleCheck v-if="step.filled && step.key !== current" class="h-3.5 w-3.5" aria-label="Étape renseignée" />
                        <template v-else>{{ position + 1 }}</template>
                    </span>
                    <component :is="step.icon" v-if="step.icon" class="hidden h-4 w-4 shrink-0 xl:block" aria-hidden="true" />
                    <span class="hidden truncate md:inline">{{ step.label }}</span>
                </button>
            </li>
        </ol>
        <p v-if="currentStep" class="px-2 pb-1 pt-2 text-xs font-semibold text-foreground md:hidden">
            Étape {{ index + 1 }} sur {{ steps.length }} · {{ currentStep.label }}
        </p>
    </nav>
</template>
