<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { MapPin, ShieldCheck } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import AssistantComposer from '@/Components/Assistant/AssistantComposer.vue';
import AssistantMessage from '@/Components/Assistant/AssistantMessage.vue';
import AssistantRobot from '@/Components/Assistant/AssistantRobot.vue';
import AssistantSuggestionGrid from '@/Components/Assistant/AssistantSuggestionGrid.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-222 — la conversation dans la fenêtre de la bulle : l'accueil et les questions
 * proposées quand elle est vide, puis les messages, puis la zone de saisie. La même
 * vue sert les trois tailles ; `spacious` centre la lecture en grande fenêtre et en
 * plein écran.
 */
const props = defineProps({
    /** L'état partagé de useAssistantChat(). */
    chat: { type: Object, required: true },
    modelValue: { type: String, default: '' },
    user: { type: Object, default: null },
    maxLength: { type: Number, default: 1000 },
    spacious: { type: Boolean, default: false },
    roleLabel: { type: String, default: null },
    siteLabel: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue', 'ask']);

const scroller = ref(null);
const composer = ref(null);

const initials = computed(() => String(props.user?.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0]?.toUpperCase() ?? '')
    .join(''));
const isEmpty = computed(() => props.chat.messages.value.length === 0);
const lastIndex = computed(() => props.chat.messages.value.length - 1);

// Une conversation vide reste en haut : l'accueil et les questions proposées se lisent
// d'abord. Sinon, la dernière réponse.
const scrollToEnd = () => nextTick(() => {
    const element = scroller.value;
    if (element) element.scrollTop = isEmpty.value ? 0 : element.scrollHeight;
});

// Le défilement suit la réponse, morceau par morceau.
watch(() => {
    const messages = props.chat.messages.value;
    const last = messages[messages.length - 1];

    return `${messages.length}|${last?.content?.length ?? 0}|${last?.status ?? ''}`;
}, scrollToEnd);

onMounted(scrollToEnd);

defineExpose({ focus: () => composer.value?.focus(), scrollToEnd });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto overscroll-contain" aria-live="polite" data-assistant-messages>
            <div :class="cn('w-full space-y-5 px-4 py-5', spacious && 'mx-auto max-w-3xl sm:px-6')">
                <!-- Conversation vide : l'accueil et les questions proposées. -->
                <template v-if="isEmpty">
                    <div :class="cn('space-y-2 text-center', spacious && 'pt-4')">
                        <span :class="cn('mx-auto block', spacious ? 'h-16 w-16' : 'h-14 w-14')" aria-hidden="true">
                            <AssistantRobot />
                        </span>
                        <h2 :class="cn('font-semibold text-foreground', spacious ? 'text-xl sm:text-2xl' : 'text-lg')">
                            Bonjour{{ user?.name ? ` ${user.name}` : '' }}, comment puis-je vous aider ?
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Où trouver une fonction, comment faire une étape, pourquoi un bouton est grisé — en français, en malgache ou en anglais.
                        </p>
                        <div class="flex flex-wrap items-center justify-center gap-1.5 pt-1">
                            <Badge v-if="roleLabel" variant="secondary">{{ roleLabel }}</Badge>
                            <Badge v-if="siteLabel" variant="outline">{{ siteLabel }}</Badge>
                            <Badge v-if="chat.moduleTitle.value" variant="outline" class="gap-1">
                                <MapPin class="h-3 w-3" aria-hidden="true" />Vous êtes sur : {{ chat.moduleTitle.value }}
                            </Badge>
                        </div>
                    </div>

                    <AssistantSuggestionGrid
                        :groups="chat.groups.value"
                        :loading="chat.groupsLoading.value"
                        :disabled="chat.streaming.value"
                        @ask="emit('ask', $event)"
                    />

                    <p class="flex items-start justify-center gap-1.5 text-center text-xs text-muted-foreground">
                        <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        Aucun avis médical, aucune donnée de patient. Ne saisissez ni nom, ni numéro de dossier.
                    </p>
                </template>

                <AssistantMessage
                    v-for="(message, index) in chat.messages.value"
                    :key="index"
                    :message="message"
                    :initials="initials"
                    :status="index === lastIndex ? chat.status.value : ''"
                    :can-retry="index === lastIndex"
                    :show-follow-ups="index === lastIndex"
                    :disabled="chat.streaming.value"
                    @retry="chat.retry()"
                    @ask="emit('ask', $event)"
                />
            </div>
        </div>

        <footer class="border-t border-border bg-card px-3 py-3 sm:px-4">
            <div :class="cn('w-full space-y-2', spacious && 'mx-auto max-w-3xl')">
                <p v-if="chat.notice.value" class="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-300" role="status">
                    <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ chat.notice.value }}
                </p>
                <AssistantComposer
                    ref="composer"
                    :model-value="modelValue"
                    :streaming="chat.streaming.value"
                    :max-length="maxLength"
                    @update:model-value="emit('update:modelValue', $event)"
                    @send="emit('ask', modelValue)"
                    @stop="chat.stop()"
                />
            </div>
        </footer>
    </div>
</template>
