<script setup>
/**
 * Un bloc de champs dans une étape clinique : une icône qui dit de quoi il
 * s'agit, un titre, une phrase facultative, et à droite ce que le bloc veut
 * mettre en avant (un total, une pastille). Tokens shadcn seulement (ADR-099).
 */
defineProps({
    icon: { type: [Object, Function], default: null },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    bodyClass: { type: String, default: 'p-4' },
});
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-border bg-card">
        <header class="flex items-center gap-3 border-b border-border bg-muted/40 px-4 py-2.5">
            <span v-if="icon" class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                <component :is="icon" class="h-4 w-4" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-foreground">{{ title }}</h3>
                <p v-if="description" class="text-xs leading-5 text-muted-foreground">{{ description }}</p>
            </div>
            <slot name="aside" />
        </header>
        <div :class="bodyClass"><slot /></div>
    </section>
</template>
