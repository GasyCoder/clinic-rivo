<script setup>
import { computed } from 'vue';
import { AlertTriangle, CheckCircle2, Info, Loader2 } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';

/**
 * La fenêtre de confirmation de l'application, à la place de `confirm()` du
 * navigateur : un titre clair, ce qui va se passer, un résumé, et deux
 * boutons nommés par ce qu'ils font — jamais « OK ».
 *
 * Échap et le clic à l'extérieur ferment par défaut ; `dismissible=false`
 * les retire quand on y signe un acte (ADR-106). Pendant l'envoi, rien ne se
 * ferme et le bouton principal montre qu'il travaille.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Confirmer' },
    cancelLabel: { type: String, default: 'Annuler' },
    // primary | danger | warning | success
    tone: { type: String, default: 'primary' },
    icon: { type: [Object, Function], default: null },
    processing: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    dismissible: { type: Boolean, default: true },
    size: { type: String, default: 'md' },
});
const emit = defineEmits(['update:open', 'confirm']);

const TONES = {
    primary: { icon: Info, chip: 'bg-primary/10 text-primary', button: 'primary' },
    success: { icon: CheckCircle2, chip: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', button: 'success' },
    warning: { icon: AlertTriangle, chip: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', button: 'warning' },
    danger: { icon: AlertTriangle, chip: 'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300', button: 'destructive' },
};
const tone = computed(() => TONES[props.tone] ?? TONES.primary);
const glyph = computed(() => props.icon ?? tone.value.icon);

const close = (value) => {
    if (!value && props.processing) return;
    emit('update:open', value);
};
</script>

<template>
    <Dialog
        :open="open"
        :title="title"
        :description="description"
        :size="size"
        :dismissible="dismissible && !processing"
        @update:open="close"
    >
        <template #icon>
            <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-full', tone.chip]" aria-hidden="true">
                <component :is="glyph" class="h-5 w-5" />
            </span>
        </template>

        <slot />

        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="close(false)">{{ cancelLabel }}</Button>
            <Button type="button" :variant="tone.button" :disabled="processing || disabled" @click="emit('confirm')">
                <Loader2 v-if="processing" class="h-4 w-4 animate-spin" />
                <slot name="confirm-icon" v-else />
                {{ confirmLabel }}
            </Button>
        </template>
    </Dialog>
</template>
