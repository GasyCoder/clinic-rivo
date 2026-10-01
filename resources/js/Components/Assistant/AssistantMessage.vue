<script setup>
import { computed, ref } from 'vue';
import { Check, Copy, OctagonX, RotateCcw, TriangleAlert } from 'lucide-vue-next';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Button from '@/Components/Shadcn/Button.vue';
import AssistantFollowUps from '@/Components/Assistant/AssistantFollowUps.vue';
import AssistantRobot from '@/Components/Assistant/AssistantRobot.vue';
import { cn } from '@/lib/cn';
import { renderAssistantMarkdown } from '@/utilities/assistant';

/**
 * ADR-222 — un message de la conversation. La réponse de l'assistant passe par un
 * Markdown réduit qui échappe tout avant d'ajouter ses propres balises : aucun HTML
 * du fournisseur n'atteint la page.
 */
const props = defineProps({
    message: { type: Object, required: true },
    /** Ce que fait l'assistant pendant qu'il répond (« Recherche dans l'aide… »). */
    status: { type: String, default: '' },
    /** Les initiales du compte, pour son avatar. */
    initials: { type: String, default: '' },
    /** Seule la dernière réponse en erreur propose « Réessayer ». */
    canRetry: { type: Boolean, default: false },
    /** Seule la dernière réponse montre ses questions de suivi. */
    showFollowUps: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['retry', 'ask']);

const isUser = computed(() => props.message.role === 'user');
const html = computed(() => renderAssistantMarkdown(props.message.content));
const copied = ref(false);

const copy = async () => {
    try {
        await navigator.clipboard.writeText(props.message.content);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch {
        copied.value = false;
    }
};
</script>

<template>
    <div :class="cn('flex gap-3', isUser && 'flex-row-reverse')" :data-role="message.role">
        <Avatar
            v-if="isUser"
            size="sm"
            :initials="initials || '?'"
            class="mt-0.5 h-8 w-8"
            aria-hidden="true"
        />
        <span v-else class="mt-0.5 h-8 w-8 shrink-0" aria-hidden="true">
            <AssistantRobot :talking="message.status === 'streaming'" />
        </span>

        <div :class="cn('min-w-0 max-w-[85%] space-y-1.5 sm:max-w-[80%]', isUser && 'flex flex-col items-end')">
            <p class="sr-only">{{ isUser ? 'Vous' : 'Assistant' }} :</p>

            <div
                v-if="isUser"
                class="whitespace-pre-wrap break-words rounded-2xl rounded-tr-sm bg-primary px-4 py-2.5 text-sm text-primary-foreground shadow-sm"
            >{{ message.content }}</div>

            <div v-else class="rounded-2xl rounded-tl-sm border border-border bg-card px-4 py-3 text-sm text-card-foreground shadow-sm">
                <!-- Sûr : renderAssistantMarkdown échappe tout le texte avant d'ajouter ses balises. -->
                <div
                    v-if="message.content"
                    :class="message.status === 'streaming' && 'rivo-assistant-streaming'"
                    class="prose prose-sm max-w-none break-words dark:prose-invert prose-p:my-1.5 prose-ol:my-1.5 prose-ul:my-1.5 prose-li:my-0.5 prose-h4:mb-1 prose-h4:mt-3 prose-code:rounded prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:before:content-none prose-code:after:content-none prose-table:my-0 prose-th:px-2.5 prose-th:py-1.5 prose-td:px-2.5 prose-td:py-1.5 [&_.assistant-table]:my-2 [&_.assistant-table]:overflow-x-auto [&_.assistant-table]:rounded-lg [&_.assistant-table]:border [&_.assistant-table]:border-border [&_thead]:bg-muted/60"
                    v-html="html"
                />

                <!-- Avant le premier mot, ou pendant une recherche dans l'aide : ce qu'il fait. -->
                <p v-if="message.status === 'streaming' && (! message.content || status)" :class="cn('flex items-center gap-2 text-muted-foreground', message.content && 'mt-1.5')" role="status">
                    <span class="flex gap-1" aria-hidden="true">
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.3s]" />
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.15s]" />
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current" />
                    </span>
                    <span class="text-xs">{{ status || 'L’assistant réfléchit…' }}</span>
                </p>
                <!-- Pendant qu'il écrit, le curseur au bout du texte le montre ; les lecteurs d'écran l'entendent. -->
                <span v-else-if="message.status === 'streaming'" class="sr-only" role="status">L’assistant écrit…</span>

                <div v-if="message.status === 'error'" class="flex flex-wrap items-center gap-x-3 gap-y-2" role="alert">
                    <p class="flex min-w-0 flex-1 items-start gap-2 text-destructive">
                        <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ message.error }}
                    </p>
                    <Button v-if="canRetry" type="button" size="sm" variant="outline" :disabled="disabled" @click="emit('retry')">
                        <RotateCcw class="h-3.5 w-3.5" aria-hidden="true" />Réessayer
                    </Button>
                </div>

                <p v-if="message.status === 'stopped'" class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                    <OctagonX class="h-3.5 w-3.5" aria-hidden="true" />Réponse arrêtée.
                </p>
            </div>

            <button
                v-if="! isUser && message.content && message.status !== 'streaming'"
                type="button"
                class="inline-flex items-center gap-1 rounded px-1 text-xs text-muted-foreground transition hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="copied ? 'Réponse copiée' : 'Copier la réponse'"
                @click="copy"
            >
                <Check v-if="copied" class="h-3 w-3" aria-hidden="true" />
                <Copy v-else class="h-3 w-3" aria-hidden="true" />
                {{ copied ? 'Copié' : 'Copier' }}
            </button>

            <AssistantFollowUps
                v-if="showFollowUps && ! isUser && message.status === 'done' && message.followUps?.length"
                :questions="message.followUps"
                :disabled="disabled"
                @ask="emit('ask', $event)"
            />
        </div>
    </div>
</template>

<style>
/*
 * ADR-222 — pendant que la réponse arrive, un curseur clignote au bout du texte, là
 * où le prochain mot va s'écrire. Non « scoped » : le texte vient de v-html. La
 * préférence « Réduire les animations » arrête le clignotement (shadcn.css).
 */
.rivo-assistant-streaming > :last-child:is(p, h4, pre)::after,
.rivo-assistant-streaming > :last-child:is(ul, ol) > li:last-child::after {
    content: '';
    display: inline-block;
    width: 0.45em;
    height: 1.05em;
    margin-inline-start: 0.15em;
    vertical-align: text-bottom;
    border-radius: 1px;
    background: currentColor;
    opacity: 0.7;
    animation: rivo-assistant-caret 1s steps(1) infinite;
}

@keyframes rivo-assistant-caret {
    50% { opacity: 0; }
}
</style>
