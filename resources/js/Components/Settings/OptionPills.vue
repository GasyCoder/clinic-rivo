<script setup>
import { cn } from '@/lib/cn';

/**
 * Un choix parmi quelques options, en pastilles (ADR-191) : un groupe radio
 * accessible (flèches, Tab, espace), qui dit l'option choisie autrement que par
 * la couleur. `options` : [{ value, label, hint? }].
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    options: { type: Array, required: true },
    label: { type: String, required: true },
    size: { type: String, default: 'default' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const select = (value) => {
    if (! props.disabled) emit('update:modelValue', value);
};

const move = (event, index) => {
    if (props.disabled) return;
    const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0;
    if (! step) return;
    event.preventDefault();
    const next = (index + step + props.options.length) % props.options.length;
    select(props.options[next].value);
    event.currentTarget.parentElement?.children[next]?.focus();
};
</script>

<template>
    <div role="radiogroup" :aria-label="label" class="inline-flex flex-wrap items-center gap-1 rounded-lg bg-muted p-1">
        <button
            v-for="(option, index) in options"
            :key="String(option.value)"
            type="button"
            role="radio"
            :aria-checked="modelValue === option.value"
            :tabindex="modelValue === option.value || (modelValue === null && index === 0) ? 0 : -1"
            :title="option.hint || undefined"
            :disabled="disabled"
            :class="cn(
                'inline-flex items-center gap-1.5 rounded-md font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-sm',
                modelValue === option.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                disabled && 'cursor-not-allowed opacity-70 hover:text-muted-foreground',
            )"
            @click="select(option.value)"
            @keydown="move($event, index)"
        >{{ option.label }}</button>
    </div>
</template>
