<script setup>
import Card from '@/Components/Shadcn/Card.vue';

/**
 * Une section du formulaire d'une désignation : une carte avec son icône, son
 * titre et une phrase, puis ses champs. Même allure pour chaque section, pour
 * que la page se lise d'un coup d'œil.
 */
defineProps({
    id: { type: String, default: undefined },
    icon: { type: [Object, Function], required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <Card :id="id" class="scroll-mt-24">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-foreground">
                    <component :is="icon" class="h-4 w-4" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-foreground">{{ title }}</h2>
                    <p v-if="description" class="mt-0.5 text-sm text-muted-foreground">{{ description }}</p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2"><slot name="actions" /></div>
        </header>
        <div class="p-5"><slot /></div>
    </Card>
</template>
