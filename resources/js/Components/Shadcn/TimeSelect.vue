<script setup>
import { computed } from 'vue';
import { Clock } from 'lucide-vue-next';
import Select from '@/Components/Shadcn/Select.vue';
import { cn } from '@/lib/cn';

/*
 * Une heure « HH:MM » en deux listes — heures, puis minutes par 5 — comme
 * les colonnes du sélecteur de date et d'heure (ADR-099) : aucun champ natif.
 *
 * Choisir l'heure sans minutes les pose à « 00 », visiblement ; rien d'autre
 * n'est deviné. Une valeur déjà enregistrée hors du pas (09:17) reste
 * proposée telle quelle, jamais arrondie en silence.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: undefined },
    class: { type: String, default: '' },
    ariaLabel: { type: String, default: 'Heure' },
});
const emit = defineEmits(['update:modelValue']);

const pad = (value) => String(value).padStart(2, '0');
const hour = computed(() => (props.modelValue ? props.modelValue.slice(0, 2) : ''));
const minute = computed(() => (props.modelValue ? props.modelValue.slice(3, 5) : ''));

const hours = Array.from({ length: 24 }, (_, index) => ({ value: pad(index), label: `${pad(index)} h` }));
const minutes = computed(() => {
    const list = Array.from({ length: 12 }, (_, index) => pad(index * 5));
    if (minute.value && !list.includes(minute.value)) {
        list.push(minute.value);
        list.sort();
    }
    return list.map((value) => ({ value, label: value }));
});

const setHour = (value) => emit('update:modelValue', value ? `${value}:${minute.value || '00'}` : '');
const setMinute = (value) => emit('update:modelValue', hour.value && value ? `${hour.value}:${value}` : props.modelValue);
</script>

<template>
    <div :class="cn('flex items-center gap-1.5', props.class)" role="group" :aria-label="ariaLabel">
        <Select
            :id="id"
            :model-value="hour"
            :options="hours"
            :icon="Clock"
            placeholder="--"
            class="min-w-0 flex-1"
            :aria-label="`${ariaLabel} : heure`"
            @update:model-value="setHour"
        />
        <span class="text-sm font-semibold text-muted-foreground" aria-hidden="true">:</span>
        <Select
            :model-value="minute"
            :options="minutes"
            placeholder="--"
            class="w-[5.5rem] min-w-0"
            :disabled="!hour"
            :aria-label="`${ariaLabel} : minutes`"
            @update:model-value="setMinute"
        />
    </div>
</template>
