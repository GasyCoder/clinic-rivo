<script setup>
import { computed } from 'vue';
import { lucideIcon } from '@/lib/icons';

/** One titled block of a form: icon or step number, title, hint, actions. */
const props = defineProps({
    icon: { type: String, default: 'edit' },
    step: { type: [String, Number], default: null },
    title: { type: String, required: true },
    description: { type: String, default: null },
});

const glyph = computed(() => lucideIcon(props.icon));
</script>

<template>
    <section class="rounded-xl border border-border bg-card shadow-sm">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <span v-if="step !== null" class="text-sm font-bold">{{ step }}</span>
                    <component :is="glyph" v-else class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <h2 class="font-heading text-base font-bold text-foreground">{{ title }}</h2>
                    <p v-if="description || $slots.description" class="mt-0.5 text-sm text-muted-foreground"><slot name="description">{{ description }}</slot></p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap gap-2"><slot name="actions" /></div>
        </header>
        <div class="p-5"><slot /></div>
    </section>
</template>
