<script setup>
import { computed, ref } from 'vue';
import { Bot, Check, Copy, OctagonX, TriangleAlert, UserRound } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { renderAssistantMarkdown } from '@/utilities/assistant';

/**
 * ADR-222 — un message de la conversation. La réponse de l'assistant passe par un
 * Markdown réduit qui échappe tout avant d'ajouter ses propres balises : aucun HTML
 * du fournisseur n'atteint la page.
 */
const props = defineProps({
    message: { type: Object, required: true },
    status: { type: String, default: '' },
});

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
        <span
            :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full', isUser ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-primary')"
            aria-hidden="true"
        >
            <UserRound v-if="isUser" class="h-4 w-4" />
            <Bot v-else class="h-4 w-4" />
        </span>

        <div :class="cn('group min-w-0 max-w-[85%] space-y-1', isUser && 'items-end text-right')">
            <div
                v-if="isUser"
                class="inline-block whitespace-pre-wrap break-words rounded-2xl rounded-tr-sm bg-primary px-3.5 py-2 text-left text-sm text-primary-foreground"
            >{{ message.content }}</div>

            <div v-else class="rounded-2xl rounded-tl-sm border border-border bg-card px-3.5 py-2.5 text-sm text-card-foreground">
                <!-- Sûr : renderAssistantMarkdown échappe tout le texte avant d'ajouter ses balises. -->
                <div
                    v-if="message.content"
                    class="prose prose-sm max-w-none break-words dark:prose-invert prose-p:my-1.5 prose-ol:my-1.5 prose-ul:my-1.5 prose-li:my-0.5 prose-h4:mb-1 prose-h4:mt-2 prose-code:rounded prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:before:content-none prose-code:after:content-none"
                    v-html="html"
                />
                <p v-if="message.status === 'streaming'" class="flex items-center gap-2 text-muted-foreground" aria-live="polite">
                    <span class="flex gap-1" aria-hidden="true">
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.3s]" />
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.15s]" />
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current" />
                    </span>
                    <span class="text-xs">{{ status || (message.content ? '' : 'L’assistant réfléchit…') }}</span>
                </p>
                <p v-if="message.status === 'error'" class="flex items-start gap-2 text-destructive" role="alert">
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ message.error }}
                </p>
                <p v-if="message.status === 'stopped'" class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                    <OctagonX class="h-3.5 w-3.5" aria-hidden="true" />Réponse arrêtée.
                </p>
            </div>

            <button
                v-if="! isUser && message.content && message.status !== 'streaming'"
                type="button"
                class="inline-flex items-center gap-1 rounded px-1 text-xs text-muted-foreground opacity-70 transition hover:text-foreground hover:opacity-100 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                @click="copy"
            >
                <Check v-if="copied" class="h-3 w-3" aria-hidden="true" />
                <Copy v-else class="h-3 w-3" aria-hidden="true" />
                {{ copied ? 'Copié' : 'Copier' }}
            </button>
        </div>
    </div>
</template>
