<script setup>
import Icon from '@/Components/UI/Icon.vue';

/** One titled block of a form: icon or step number, title, hint, actions. */
defineProps({
    icon: { type: String, default: 'edit' },
    step: { type: [String, Number], default: null },
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-900">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-lg text-primary-600 dark:bg-primary-950/40 dark:text-primary-300">
                    <span v-if="step !== null" class="text-sm font-bold">{{ step }}</span>
                    <Icon v-else :name="icon" />
                </span>
                <div class="min-w-0">
                    <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">{{ title }}</h2>
                    <p v-if="description || $slots.description" class="mt-0.5 text-sm text-slate-500"><slot name="description">{{ description }}</slot></p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap gap-2"><slot name="actions" /></div>
        </header>
        <div class="p-5"><slot /></div>
    </section>
</template>
