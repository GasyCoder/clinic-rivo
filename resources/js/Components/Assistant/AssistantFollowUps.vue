<script setup>
import { CornerDownRight } from 'lucide-vue-next';

/**
 * ADR-222 — deux ou trois questions de suivi sous la dernière réponse. Elles viennent
 * du serveur, prises dans l'aide des modules que le compte peut ouvrir : l'assistant
 * sait y répondre. Un clic pose la question.
 */
defineProps({
    questions: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['ask']);
</script>

<template>
    <div class="pt-1" data-assistant-follow-ups>
        <p class="mb-1.5 text-xs font-medium text-muted-foreground">Pour continuer</p>
        <ul class="flex flex-wrap gap-2">
            <li v-for="question in questions" :key="question">
                <button
                    type="button"
                    class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-border bg-background px-3 py-1.5 text-left text-xs text-foreground transition hover:border-primary/50 hover:bg-primary/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50"
                    :disabled="disabled"
                    @click="emit('ask', question)"
                >
                    <CornerDownRight class="h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" />
                    <span class="min-w-0">{{ question }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
