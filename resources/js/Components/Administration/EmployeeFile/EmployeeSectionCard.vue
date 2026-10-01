<script setup>
import { CircleDashed, Lock } from 'lucide-vue-next';
import Card from '@/Components/Shadcn/Card.vue';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-221 — la coque d'une section de la fiche employé : son icône, son titre,
 * ce qu'elle contient, et l'état réel de son enregistrement automatique
 * (jamais « enregistré » avant la réponse du serveur).
 */
defineProps({
    icon: { type: [Object, Function], required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    tone: { type: String, default: 'bg-primary/10 text-primary' },
    state: { type: String, default: 'idle' },
    savedAt: { type: [String, null], default: null },
    /** Ce qui manque avant que la section puisse partir (état « incomplete »). */
    incompleteHint: { type: String, default: '' },
    /** Lecture seule : le compte n'a pas le droit d'écrire cette section. */
    readOnly: { type: Boolean, default: false },
    readOnlyHint: { type: String, default: 'Lecture seule : votre compte n’a pas le droit de modifier cette section.' },
});
defineEmits(['retry']);
</script>

<template>
    <Card class="overflow-hidden">
        <header class="flex flex-col gap-2 border-b border-border px-4 py-3 sm:flex-row sm:items-start sm:justify-between sm:px-5">
            <div class="flex min-w-0 items-start gap-3">
                <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', tone)"><component :is="icon" class="h-4 w-4" /></span>
                <div class="min-w-0">
                    <h2 class="font-heading text-base font-bold text-foreground">{{ title }}</h2>
                    <p v-if="description" class="mt-0.5 text-xs leading-5 text-muted-foreground">{{ description }}</p>
                </div>
            </div>
            <div class="shrink-0 sm:pt-1">
                <slot name="status">
                <p v-if="readOnly" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><Lock class="h-3.5 w-3.5" aria-hidden="true" />Lecture seule</p>
                <p v-else-if="state === 'incomplete'" class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-400" role="status">
                    <CircleDashed class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ incompleteHint || 'À compléter avant l’enregistrement' }}
                </p>
                <ClinicalSaveStatus
                    v-else
                    :saving="state === 'saving'"
                    :saved-at="savedAt"
                    :dirty="state === 'dirty'"
                    :failed="state === 'failed'"
                    retryable
                    @retry="$emit('retry')"
                />
                </slot>
            </div>
        </header>
        <p v-if="readOnly" class="border-b border-border bg-muted/40 px-5 py-2 text-xs text-muted-foreground">{{ readOnlyHint }}</p>
        <div class="p-4 sm:p-5">
            <slot />
        </div>
    </Card>
</template>
