<script setup>
import { computed, useAttrs } from 'vue';
import { cn } from '@/lib/cn';

/**
 * Le pendant multiligne d'`Input`, mêmes bordure, focus et état désactivé.
 *
 * Ces classes étaient recopiées à la main sur chaque écran qui avait besoin
 * d'une zone de texte, et elles avaient déjà divergé — bordure de focus
 * rouge ici, primaire là. Une quatrième occurrence a suffi à l'extraire.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    rows: { type: [String, Number], default: 4 },
});
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();

const componentClass = computed(() => cn(
    'flex w-full resize-y rounded-lg border border-input bg-card px-3 py-2 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50',
    attrs.class,
));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <textarea
        :class="componentClass"
        :rows="rows"
        :value="modelValue"
        v-bind="forwardedAttrs"
        @input="emit('update:modelValue', $event.target.value)"
    ></textarea>
</template>
