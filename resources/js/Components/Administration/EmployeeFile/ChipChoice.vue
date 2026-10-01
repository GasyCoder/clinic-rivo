<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/cn';

/**
 * Un choix en pastilles (tailles, Oui / Non). Un second clic sur la pastille choisie
 * la retire : « non renseigné » reste possible. Une valeur saisie autrefois qui
 * n'est pas dans la liste reste affichée, choisie, plutôt que de disparaître.
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    options: { type: Array, required: true },
    label: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    size: { type: String, default: 'md' },
});
const emit = defineEmits(['update:modelValue']);

const current = computed(() => String(props.modelValue ?? '').trim());
const items = computed(() => {
    const list = props.options.map((option) => (typeof option === 'string' ? { value: option, label: option } : option));
    const known = list.some((item) => item.value.toLowerCase() === current.value.toLowerCase());

    return current.value && ! known ? [...list, { value: current.value, label: current.value, legacy: true }] : list;
});
const isOn = (item) => item.value.toLowerCase() === current.value.toLowerCase();
const pick = (item) => emit('update:modelValue', isOn(item) ? '' : item.value);
</script>

<template>
    <div class="flex flex-wrap gap-1.5" role="group" :aria-label="label">
        <button
            v-for="item in items"
            :key="item.value"
            type="button"
            :disabled="disabled"
            :aria-pressed="isOn(item)"
            :title="item.legacy ? 'Valeur saisie auparavant' : undefined"
            :class="cn(
                'rounded-md border font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                size === 'sm' ? 'h-8 min-w-9 px-2.5 text-xs' : 'h-9 min-w-10 px-3 text-sm',
                isOn(item)
                    ? (item.tone === 'negative' ? 'border-foreground/30 bg-muted text-foreground' : 'border-primary bg-primary text-primary-foreground')
                    : 'border-border bg-background text-muted-foreground hover:border-primary/40 hover:text-foreground',
                item.legacy && ! isOn(item) && 'border-dashed',
            )"
            @click="pick(item)"
        >{{ item.label }}</button>
    </div>
</template>
