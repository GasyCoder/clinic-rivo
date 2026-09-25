<script setup>
import { computed } from 'vue';
import { RotateCcw } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { isHex } from '@/utilities/themePalette';

/**
 * Une couleur (ADR-191) : la pastille ouvre le sélecteur du navigateur, le code
 * se saisit à côté. Vide, la couleur `fallback` s'applique ; elle est montrée
 * en pâle, pour qu'un champ vide ne se lise pas « aucune couleur ».
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    fallback: { type: String, default: '' },
    fallbackLabel: { type: String, default: 'd’origine' },
    label: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const shown = computed(() => (isHex(props.modelValue) ? props.modelValue : props.fallback || '#000000'));
const picker = computed({
    get: () => shown.value.toLowerCase(),
    set: (value) => emit('update:modelValue', String(value).toUpperCase()),
});
</script>

<template>
    <div class="flex items-center gap-2">
        <label
            :class="['relative h-9 w-9 shrink-0 overflow-hidden rounded-md border shadow-sm', invalid ? 'border-destructive' : 'border-input', disabled ? 'cursor-not-allowed' : 'cursor-pointer']"
            :style="{ backgroundColor: shown }"
        >
            <span class="sr-only">Choisir : {{ label }}</span>
            <input v-model="picker" type="color" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" :disabled="disabled" />
        </label>
        <Input
            :id="id || undefined"
            :model-value="modelValue"
            maxlength="7"
            spellcheck="false"
            :placeholder="fallback ? fallback.toUpperCase() : '#RRVVBB'"
            :aria-label="`${label} (code #RRVVBB)`"
            :aria-invalid="invalid || undefined"
            :disabled="disabled"
            :class="['h-9 w-32 font-mono uppercase', invalid && 'border-destructive']"
            @update:model-value="(value) => emit('update:modelValue', String(value).toUpperCase())"
        />
        <Button
            v-if="modelValue && ! disabled"
            type="button"
            variant="ghost"
            size="sm"
            :aria-label="`${label} : revenir à la couleur ${fallbackLabel}`"
            @click="emit('update:modelValue', '')"
        ><RotateCcw class="h-4 w-4" />Rétablir</Button>
    </div>
</template>
