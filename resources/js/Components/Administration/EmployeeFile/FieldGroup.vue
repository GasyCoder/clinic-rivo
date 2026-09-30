<script setup>
import { cn } from '@/lib/cn';

/** Un bloc de champs dans une étape de la fiche employé : icône, titre, phrase, puis les champs. */
defineProps({
    icon: { type: [Object, Function], required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    tone: { type: String, default: 'bg-primary/10 text-primary' },
    class: { type: String, default: '' },
});
</script>

<template>
    <section :class="cn('rounded-xl border border-border bg-card p-4', $props.class)">
        <header :class="cn('mb-4 flex gap-3', description ? 'items-start' : 'items-center')">
            <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-lg', tone)"><component :is="icon" class="h-4 w-4" aria-hidden="true" /></span>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-bold text-foreground">{{ title }}</h3>
                <p v-if="description" class="mt-0.5 text-xs leading-5 text-muted-foreground">{{ description }}</p>
            </div>
            <slot name="action" />
        </header>
        <div class="grid gap-4">
            <slot />
        </div>
    </section>
</template>
