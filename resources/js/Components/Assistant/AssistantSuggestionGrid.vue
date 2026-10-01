<script setup>
import { ArrowUpRight } from 'lucide-vue-next';
import Skeleton from '@/Components/Shadcn/Skeleton.vue';
import { assistantModuleIcon } from '@/utilities/assistantModules';

/**
 * ADR-222 — les questions proposées quand la conversation est vide, groupées par
 * module : celui de la page d'où l'on vient d'abord, puis ceux du métier du compte.
 * Elles viennent du serveur (App\Ai\AssistantSuggestions) ; un clic pose la question.
 */
defineProps({
    groups: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['ask']);
</script>

<template>
    <div v-if="loading && ! groups.length" class="grid gap-3 cq-2xl:grid-cols-2" aria-busy="true" aria-label="Chargement des suggestions">
        <div v-for="index in 4" :key="index" class="space-y-3 rounded-xl border border-border p-4">
            <Skeleton class="h-4 w-32" />
            <Skeleton class="h-3.5 w-full" />
            <Skeleton class="h-3.5 w-4/5" />
        </div>
    </div>

    <div v-else-if="groups.length" class="grid gap-3 cq-2xl:grid-cols-2" data-assistant-suggestions>
        <section
            v-for="group in groups"
            :key="group.key"
            class="rounded-xl border border-border bg-card p-4 shadow-sm"
            :aria-labelledby="`assistant-suggestions-${group.key}`"
        >
            <h3 :id="`assistant-suggestions-${group.key}`" class="mb-2.5 flex items-center gap-2 text-sm font-semibold text-foreground">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                    <component :is="assistantModuleIcon(group.key)" class="h-4 w-4" />
                </span>
                {{ group.title }}
            </h3>
            <ul class="space-y-1">
                <li v-for="question in group.questions" :key="question">
                    <button
                        type="button"
                        class="group flex w-full items-start gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-muted-foreground transition hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50"
                        :disabled="disabled"
                        @click="emit('ask', question)"
                    >
                        <span class="min-w-0 flex-1">{{ question }}</span>
                        <ArrowUpRight class="mt-0.5 h-3.5 w-3.5 shrink-0 opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100" aria-hidden="true" />
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>
