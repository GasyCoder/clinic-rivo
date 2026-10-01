<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { SendHorizontal, Square } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-222 — la zone de saisie : grandit avec le texte, Entrée envoie, Maj+Entrée
 * va à la ligne, « Arrêter » pendant une réponse.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    streaming: { type: Boolean, default: false },
    maxLength: { type: Number, default: 1000 },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'send', 'stop']);

const textarea = ref(null);
const length = computed(() => props.modelValue.length);
const tooLong = computed(() => length.value > props.maxLength);
const canSend = computed(() => ! props.disabled && ! props.streaming && props.modelValue.trim() !== '' && ! tooLong.value);

const resize = () => {
    const element = textarea.value;
    if (! element) return;
    element.style.height = 'auto';
    element.style.height = `${Math.min(element.scrollHeight, 180)}px`;
};

watch(() => props.modelValue, () => nextTick(resize));

const onKeydown = (event) => {
    if (event.key === 'Enter' && ! event.shiftKey && ! event.isComposing) {
        event.preventDefault();
        if (canSend.value) emit('send');
    }
};

defineExpose({ focus: () => textarea.value?.focus() });
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-end gap-2 rounded-xl border border-input bg-background p-2 focus-within:ring-2 focus-within:ring-ring/40">
            <label for="assistant-question" class="sr-only">Votre question sur le logiciel</label>
            <textarea
                id="assistant-question"
                ref="textarea"
                :value="modelValue"
                rows="1"
                :maxlength="maxLength + 200"
                :disabled="disabled"
                placeholder="Posez votre question sur le logiciel…"
                class="max-h-[180px] min-h-[36px] flex-1 resize-none border-0 bg-transparent px-1.5 py-1.5 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-0"
                @input="emit('update:modelValue', $event.target.value)"
                @keydown="onKeydown"
            />
            <Button v-if="streaming" type="button" size="icon" variant="outline" aria-label="Arrêter la réponse" title="Arrêter la réponse" @click="emit('stop')">
                <Square class="h-4 w-4" aria-hidden="true" />
            </Button>
            <Button v-else type="button" size="icon" :disabled="! canSend" aria-label="Envoyer la question" title="Envoyer (Entrée)" @click="emit('send')">
                <SendHorizontal class="h-4 w-4" aria-hidden="true" />
            </Button>
        </div>
        <p class="flex justify-between gap-3 px-1 text-[0.7rem] text-muted-foreground">
            <span>Aucune donnée de patient : l’assistant aide seulement à utiliser le logiciel.</span>
            <span :class="cn('shrink-0 tabular-nums', tooLong && 'font-medium text-destructive')">{{ length }} / {{ maxLength }}</span>
        </p>
    </div>
</template>
