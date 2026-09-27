<script setup>
defineProps({
    modelValue: { type: [Boolean, String, Number], default: '' },
    yesLabel: { type: String, default: 'Oui' },
    noLabel: { type: String, default: 'Non' },
    emptyLabel: { type: String, default: 'Non renseigné' },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="mt-1 grid grid-cols-3 rounded-lg border border-input bg-card p-1 shadow-sm" role="radiogroup">
        <button
            v-for="choice in [{ value: '', label: emptyLabel }, { value: true, label: yesLabel }, { value: false, label: noLabel }]"
            :key="String(choice.value)"
            type="button"
            role="radio"
            :aria-checked="modelValue === choice.value"
            :class="['min-h-9 rounded-md px-2 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30', modelValue === choice.value ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground']"
            @click="$emit('update:modelValue', choice.value)"
        >
            {{ choice.label }}
        </button>
    </div>
</template>
