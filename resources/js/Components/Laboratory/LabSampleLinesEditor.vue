<script setup>
import { computed } from 'vue';
import Button from '@/Components/Shadcn/Button.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import TubeChip from '@/Components/Laboratory/TubeChip.vue';
import { Minus, Plus, TestTube, Trash2 } from 'lucide-vue-next';
import { emptySampleLine, sampleTubeOf } from '@/utilities/labReception';

/**
 * ADR-214 — les prélèvements à enregistrer : un type, le tube (celui du type,
 * ou un autre), et combien de tubes. Le serveur relit chaque ligne.
 */
const props = defineProps({
    modelValue: { type: Array, required: true },
    options: { type: Object, default: () => ({ sample_types: [], tubes: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const typeOptions = computed(() => (props.options?.sample_types ?? []).map((type) => ({ value: type.uuid, label: type.name })));
const tubeOptions = computed(() => [
    { value: '', label: 'Tube du type de prélèvement' },
    ...(props.options?.tubes ?? []).map((tube) => ({ value: tube.uuid, label: `${tube.code} — ${tube.name}${tube.color ? ` (${tube.color})` : ''}` })),
]);

const add = () => props.modelValue.push(emptySampleLine(props.options));
const remove = (index) => props.modelValue.splice(index, 1);
const step = (line, delta) => { line.quantity = Math.min(10, Math.max(1, Number(line.quantity || 1) + delta)); };
const errorOf = (index) => props.errors[`samples.${index}.sample_type_uuid`] ?? props.errors[`samples.${index}.tube_type_uuid`] ?? props.errors[`samples.${index}.quantity`] ?? null;
const instructionsOf = (line) => (props.options?.sample_types ?? []).find((type) => type.uuid === line.sample_type_uuid)?.instructions ?? null;
</script>

<template>
    <div class="space-y-2">
        <p v-if="!typeOptions.length" class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
            Aucun type de prélèvement actif : le référentiel « Prélèvements & tubes » est vide.
        </p>
        <div v-for="(line, index) in modelValue" :key="index" class="rounded-lg border border-border bg-card p-3">
            <div class="grid gap-2 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_auto_auto] md:items-center">
                <Select v-model="line.sample_type_uuid" :options="typeOptions" :icon="TestTube" placeholder="Type de prélèvement" :disabled="disabled" aria-label="Type de prélèvement" />
                <Select v-model="line.tube_type_uuid" :options="tubeOptions" placeholder="Tube" :disabled="disabled" aria-label="Tube" />
                <div class="inline-flex items-center gap-1" role="group" aria-label="Nombre de tubes">
                    <Button type="button" size="xs" variant="outline" :disabled="disabled || line.quantity <= 1" aria-label="Un tube de moins" @click="step(line, -1)"><Minus class="h-3.5 w-3.5" /></Button>
                    <Input v-model.number="line.quantity" size="sm" type="number" min="1" max="10" class="w-14 text-center" :disabled="disabled" aria-label="Nombre de tubes" />
                    <Button type="button" size="xs" variant="outline" :disabled="disabled || line.quantity >= 10" aria-label="Un tube de plus" @click="step(line, 1)"><Plus class="h-3.5 w-3.5" /></Button>
                </div>
                <Button type="button" size="xs" variant="ghost" :disabled="disabled" aria-label="Retirer la ligne" @click="remove(index)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
            </div>
            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                <TubeChip :tube="sampleTubeOf(line, options)" />
                <span v-if="instructionsOf(line)">{{ instructionsOf(line) }}</span>
            </div>
            <p v-if="errorOf(index)" class="mt-1 text-xs text-destructive">{{ errorOf(index) }}</p>
        </div>
        <Button v-if="typeOptions.length" type="button" size="sm" variant="outline" :disabled="disabled" @click="add">
            <Plus class="h-4 w-4" /> Ajouter un prélèvement
        </Button>
    </div>
</template>
