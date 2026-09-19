<script setup>
/**
 * A small radio group rendered as segmented buttons — the pattern already
 * used for "Traitements en cours ?" on the interrogation step, extracted so
 * the examination screen does not re-implement it four times.
 *
 * Never renders a pre-selected option: `modelValue` starts null and stays
 * null until the doctor chooses. A pre-checked "Bon" or "Normal" would be an
 * assessment the software made.
 */
const props = defineProps({
    modelValue: { type: [String, null], default: null },
    /** [{ value, label, description?, tone? }] — tone: neutral|positive|warning */
    options: { type: Array, required: true },
    label: { type: String, default: '' },
    hint: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    /** Allows un-selecting by clicking the active option again. */
    clearable: { type: Boolean, default: true },
    name: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);

const TONES = {
    positive: 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200',
    warning: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200',
    neutral: 'border-primary/50 bg-primary/10 text-primary',
};

const select = (value) => {
    if (props.disabled) return;
    emit('update:modelValue', props.clearable && props.modelValue === value ? null : value);
};
</script>

<template>
    <fieldset :disabled="disabled">
        <legend v-if="label" class="text-xs font-bold text-foreground">{{ label }}</legend>
        <p v-if="hint" class="mt-0.5 text-[11px] leading-4 text-muted-foreground">{{ hint }}</p>

        <!-- flex-wrap rather than a fixed column count: three segments fit one
             row on a workstation and wrap on a phone without ever forcing a
             horizontal scrollbar. Un segment ne rétrécit jamais : il passe à la
             ligne plutôt que de tronquer « Amélioré » en « Amél… » (il ne
             tronque que s'il est seul plus large que son conteneur). -->
        <div :class="['flex flex-wrap gap-2', label || hint ? 'mt-2' : '']" role="radiogroup" :aria-label="label || name">
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                role="radio"
                :aria-checked="modelValue === option.value"
                :disabled="disabled"
                :class="[
                    'max-w-full shrink-0 grow rounded-md border px-3 py-2 text-start text-xs font-semibold transition-colors sm:grow-0',
                    modelValue === option.value
                        ? (TONES[option.tone] ?? TONES.neutral)
                        : 'border-border bg-card text-muted-foreground hover:bg-muted/35',
                    disabled ? 'cursor-not-allowed opacity-60' : '',
                ]"
                @click="select(option.value)"
            >
                <span class="block truncate">{{ option.label }}</span>
                <span v-if="option.description" class="mt-0.5 block truncate text-[10px] font-normal opacity-75">{{ option.description }}</span>
            </button>
        </div>
    </fieldset>
</template>
