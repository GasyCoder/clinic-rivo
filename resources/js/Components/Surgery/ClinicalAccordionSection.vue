<script setup>
import { Check, ChevronDown } from 'lucide-vue-next';

defineProps({
    open: Boolean,
    step: { type: [String, Number], required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    complete: Boolean,
    tone: { type: String, default: 'primary' },
});

defineEmits(['toggle']);
</script>

<template>
    <section :class="['overflow-hidden rounded-xl border bg-card transition-all', open ? 'border-primary/35 shadow-sm' : 'border-border']">
        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-start transition-colors hover:bg-accent/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring/30 sm:px-5" :aria-expanded="open" @click="$emit('toggle')">
            <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-lg text-sm font-bold', open ? 'bg-primary text-primary-foreground' : complete ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-muted text-muted-foreground']">
                <Check v-if="complete" class="h-4 w-4" aria-hidden="true" />
                <span v-else>{{ step }}</span>
            </span>
            <span class="min-w-0 flex-1">
                <strong class="block text-sm text-foreground">{{ title }}</strong>
                <span v-if="description" class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ description }}</span>
            </span>
            <ChevronDown :class="['h-4 w-4 shrink-0 text-muted-foreground transition-transform', open ? 'rotate-180' : '']" aria-hidden="true" />
        </button>
        <div v-show="open" class="border-t border-border bg-muted/15 p-4 sm:p-5"><slot /></div>
    </section>
</template>
