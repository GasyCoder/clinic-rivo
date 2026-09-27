<script setup>
import { Baby, CalendarHeart, Sparkles } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-204 — la sage-femme dit ce qu'elle commence. Deux parcours distincts,
 * toujours rattachés à la même grossesse.
 *
 * Ce que la Réception a demandé peut **présélectionner** un parcours (« suggéré
 * par la Réception ») : c'est une aide, jamais un choix fait à sa place. Le
 * choix ouvre le dossier ; il se change ensuite tant qu'il n'est pas terminé.
 */
const props = defineProps({
    options: { type: Array, required: true },
    suggested: { type: String, default: null },
    current: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    processing: { type: String, default: null },
    title: { type: String, default: 'Quelle prise en charge commencez-vous ?' },
    description: { type: String, default: 'Le parcours adapte les étapes du dossier. Il reste lié à la grossesse de la patiente : le jour de l’accouchement retrouve tout le suivi prénatal.' },
});
defineEmits(['choose']);

const DETAILS = {
    PRENATAL: {
        icon: CalendarHeart,
        text: 'Interrogatoire, examen obstétrical, examens complémentaires, synthèse et prochain rendez-vous.',
        tone: 'text-rose-700 bg-rose-100 dark:text-rose-300 dark:bg-rose-950/50',
    },
    DELIVERY: {
        icon: Baby,
        text: 'Admission, travail, surveillance, accouchement, nouveau-né(s) et transmission — l’historique prénatal toujours à portée.',
        tone: 'text-sky-700 bg-sky-100 dark:text-sky-300 dark:bg-sky-950/50',
    },
};
</script>

<template>
    <Card class="p-5">
        <h2 class="text-base font-bold text-foreground">{{ title }}</h2>
        <p class="mt-1 text-xs leading-5 text-muted-foreground">{{ description }}</p>

        <div class="mt-4 grid gap-3 md:grid-cols-2" role="group" :aria-label="title">
            <button
                v-for="option in props.options"
                :key="option.value"
                type="button"
                :disabled="disabled || processing !== null"
                :aria-pressed="current === option.value"
                :class="cn(
                    'flex min-h-[7rem] items-start gap-3 rounded-xl border p-4 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60',
                    current === option.value
                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                        : suggested === option.value
                            ? 'border-primary/50 bg-card hover:bg-accent'
                            : 'border-border bg-card hover:bg-accent',
                )"
                @click="$emit('choose', option.value)"
            >
                <span :class="cn('grid h-11 w-11 shrink-0 place-items-center rounded-lg', DETAILS[option.value]?.tone)">
                    <component :is="DETAILS[option.value]?.icon" class="h-5 w-5" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-bold text-foreground">{{ option.label }}</span>
                        <Badge v-if="current === option.value" tone="success" class="px-2 py-0.5 text-[10px]">Parcours actuel</Badge>
                        <Badge v-else-if="suggested === option.value" tone="info" class="px-2 py-0.5 text-[10px]"><Sparkles class="h-3 w-3" aria-hidden="true" />Suggéré par la Réception</Badge>
                    </span>
                    <span class="mt-1 block text-xs leading-5 text-muted-foreground">{{ DETAILS[option.value]?.text }}</span>
                    <span v-if="processing === option.value" class="mt-2 block text-xs font-semibold text-primary">Ouverture…</span>
                </span>
            </button>
        </div>
    </Card>
</template>
