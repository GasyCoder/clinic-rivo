<script setup>
import { ArrowRight, CircleCheck, CircleX, Clock, LogOut, Undo2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import { itemQuantity, stepIcon, stepStyle } from '@/utilities/episodePathway';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';

/**
 * Le parcours détaillé d'un passage, de la Réception à la sortie (ADR-117).
 *
 * Tout ce qui s'affiche est déjà composé côté Laravel : cette liste ne décide
 * ni de l'ordre, ni de ce qui manque, ni de la suite des soins — elle dessine.
 */
defineProps({
    steps: { type: Array, required: true },
});

const ITEM_ICONS = {
    DONE: CircleCheck,
    PARTIAL: Clock,
    PENDING: Clock,
    NOT_PERFORMED: CircleX,
    CANCELLED: CircleX,
};
const ITEM_CLASSES = {
    DONE: 'text-emerald-600 dark:text-emerald-300',
    PARTIAL: 'text-amber-600 dark:text-amber-300',
    PENDING: 'text-muted-foreground',
    NOT_PERFORMED: 'text-red-600 dark:text-red-300',
    CANCELLED: 'text-muted-foreground',
};

/**
 * Les dates propres à l'étape : une orientation a trois moments, les autres
 * étapes un seul. Une étape encore à venir (la sortie à prononcer) n'a pas de
 * date et n'en invente pas.
 */
const moments = (step) => {
    if (step.type === 'ORIENTATION') {
        return [
            step.at && `Orienté le ${formatDateTime(step.at)}`,
            step.accepted_at && `Pris en charge le ${formatDateTime(step.accepted_at)}`,
            step.completed_at && `Terminé le ${formatDateTime(step.completed_at)}`,
        ].filter(Boolean).join(' · ');
    }

    return [
        step.at && `Le ${formatDateTime(step.at)}`,
        step.completed_at && `Terminé le ${formatDateTime(step.completed_at)}`,
    ].filter(Boolean).join(' · ');
};
</script>

<template>
    <ol class="py-1">
        <li
            v-for="(step, index) in steps"
            :key="step.key"
            class="relative flex items-start gap-3 px-5 py-3"
        >
            <span
                v-if="index < steps.length - 1"
                class="absolute -bottom-3 left-9 top-[2.75rem] w-px -translate-x-1/2 bg-border"
                aria-hidden="true"
            />
            <span :class="['relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full', stepStyle(step).bubble]">
                <component :is="stepIcon(step)" class="h-4 w-4" aria-hidden="true" />
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-bold text-foreground">{{ step.label }}</span>
                    <span
                        v-if="step.qualifier"
                        class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted-foreground"
                    >{{ step.qualifier }}</span>
                    <Badge :variant="stepStyle(step).badge" class="px-2 py-0.5 text-[10px]">{{ step.state_label }}</Badge>
                </div>

                <!-- « Médecine → Soins » : d'où vient la demande, ce que « Soins »
                     tout seul ne dit pas quand il apparaît deux fois. -->
                <p v-if="step.from_label" class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span>{{ step.from_label }}</span>
                    <ArrowRight class="h-3 w-3" aria-hidden="true" />
                    <span class="font-semibold text-foreground">{{ step.label }}</span>
                </p>

                <p v-for="note in step.notes" :key="note" class="mt-0.5 text-xs text-muted-foreground">{{ note }}</p>

                <!-- La suite décidée par le médecin : retour en Médecine, ou sortie
                     directe. Sans elle, deux étapes « Soins » ne disent pas où
                     le patient va ensuite. -->
                <p
                    v-if="step.follow_up"
                    :class="[
                        'mt-1.5 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium',
                        step.follow_up.code === 'RETURN_TO_MEDICINE'
                            ? 'bg-primary/10 text-primary'
                            : 'bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:text-amber-200',
                    ]"
                >
                    <Undo2 v-if="step.follow_up.code === 'RETURN_TO_MEDICINE'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <LogOut v-else class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ step.follow_up.label }}
                </p>

                <ul v-if="step.items.length" class="mt-2 space-y-1">
                    <li v-for="(item, itemIndex) in step.items" :key="itemIndex" class="flex items-center gap-1.5 text-xs">
                        <component :is="ITEM_ICONS[item.state] ?? Clock" :class="['h-3.5 w-3.5 shrink-0', ITEM_CLASSES[item.state]]" aria-hidden="true" />
                        <span :class="['font-medium text-foreground', item.state === 'CANCELLED' && 'line-through opacity-60']">{{ item.name }}</span>
                        <span v-if="itemQuantity(item.quantity)" class="text-muted-foreground">{{ itemQuantity(item.quantity) }}</span>
                        <span class="text-muted-foreground">· {{ item.state_label }}</span>
                    </li>
                </ul>

                <p
                    v-if="step.amount !== null && step.amount_caption"
                    :class="['mt-1 text-xs font-semibold', step.amount_is_due ? 'text-red-600 dark:text-red-300' : 'text-foreground']"
                >
                    {{ step.amount_caption }} : {{ formatMoney(step.amount) }}
                </p>

                <p v-if="moments(step)" class="mt-1 text-xs text-muted-foreground">{{ moments(step) }}</p>
            </div>
        </li>
    </ol>
</template>
