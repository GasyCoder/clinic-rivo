<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { Pill } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { useAutosave } from '@/composables/useAutosave';
import { labUrl } from '@/utilities/labUrl';

/**
 * ADR-213 — l'antibiogramme d'un germe identifié : un antibiotique par ligne
 * (ceux de la famille du germe), Sensible / Intermédiaire / Résistant et, s'il
 * est mesuré, le diamètre. Une ligne sans lettre n'est pas un résultat.
 */
const props = defineProps({
    itemUuid: { type: String, required: true },
    antibiogram: { type: Object, required: true },
    microbiology: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const family = computed(() => props.microbiology.find((entry) => entry.uuid === props.antibiogram.family_uuid) ?? null);

const initialLines = () => {
    const saved = new Map((props.antibiogram.lines ?? []).map((line) => [line.antibiotic_uuid, line]));
    const rows = (family.value?.antibiotics ?? []).map((antibiotic) => ({
        antibiotic_uuid: antibiotic.uuid,
        name: antibiotic.name,
        comment: antibiotic.comment,
        interpretation: saved.get(antibiotic.uuid)?.interpretation ?? '',
        measure: saved.get(antibiotic.uuid)?.measure ?? '',
    }));
    // Un antibiotique archivé depuis garde sa ligne déjà saisie.
    for (const line of props.antibiogram.lines ?? []) {
        if (!rows.some((row) => row.antibiotic_uuid === line.antibiotic_uuid)) {
            rows.push({ antibiotic_uuid: line.antibiotic_uuid, name: line.antibiotic, comment: null, interpretation: line.interpretation, measure: line.measure ?? '' });
        }
    }

    return rows;
};

const form = useForm({ lines: initialLines(), notes: props.antibiogram.notes ?? '' });

const autosave = useAutosave(form, (options) => form
    .transform((data) => ({
        lines: data.lines
            .filter((line) => line.interpretation)
            .map((line) => ({ antibiotic_uuid: line.antibiotic_uuid, interpretation: line.interpretation, measure: line.measure === '' ? null : line.measure })),
        notes: data.notes,
    }))
    .put(labUrl(`/laboratory/items/${props.itemUuid}/antibiograms/${props.antibiogram.uuid}`), options), { enabled: () => !props.disabled });

const counts = computed(() => ['S', 'I', 'R'].map((value) => ({ value, count: form.lines.filter((line) => line.interpretation === value).length })));
const TONES = {
    S: 'bg-emerald-600 text-white',
    I: 'bg-amber-500 text-white',
    R: 'bg-destructive text-destructive-foreground',
};
const choose = (line, value) => { line.interpretation = line.interpretation === value ? '' : value; };
</script>

<template>
    <section class="rounded-lg border border-border bg-muted/20">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-2">
            <p class="flex items-center gap-2 text-sm font-semibold text-foreground">
                <Pill class="h-4 w-4 text-primary" />
                Antibiogramme — <span class="italic">{{ antibiogram.bacterium }}</span>
                <span v-if="antibiogram.family" class="text-xs font-normal text-muted-foreground">({{ antibiogram.family }})</span>
            </p>
            <div class="flex items-center gap-2 text-xs">
                <span v-for="entry in counts" :key="entry.value" :class="cn('rounded px-1.5 py-0.5 font-bold', entry.count ? TONES[entry.value] : 'bg-muted text-muted-foreground')">
                    {{ entry.value }} {{ entry.count }}
                </span>
                <ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" retryable @retry="autosave.retry" />
            </div>
        </header>

        <p v-if="!form.lines.length" class="px-3 py-4 text-xs text-muted-foreground">
            Aucun antibiotique n’est défini pour la famille de ce germe : ajoutez-les dans « Germes & antibiotiques ».
        </p>

        <ul v-else class="divide-y divide-border">
            <li v-for="(line, index) in form.lines" :key="line.antibiotic_uuid" class="grid items-center gap-2 px-3 py-1.5 sm:grid-cols-[minmax(0,1fr)_auto_7rem]">
                <p class="min-w-0 text-sm">
                    <span class="font-medium text-foreground">{{ line.name }}</span>
                    <span v-if="line.comment" class="block truncate text-[11px] text-muted-foreground" :title="line.comment">{{ line.comment }}</span>
                    <span v-if="form.errors[`lines.${index}.antibiotic_uuid`]" class="block text-[11px] text-destructive">{{ form.errors[`lines.${index}.antibiotic_uuid`] }}</span>
                </p>
                <div class="inline-flex rounded-lg border border-border p-0.5" role="radiogroup" :aria-label="`Sensibilité à ${line.name}`">
                    <button
                        v-for="option in options.antibiogram"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="line.interpretation === option.value"
                        :title="option.label"
                        :disabled="disabled"
                        :class="cn('w-8 rounded-md py-1 text-xs font-bold transition-colors disabled:opacity-60',
                            line.interpretation === option.value ? TONES[option.value] : 'text-muted-foreground hover:bg-accent')"
                        @click="choose(line, option.value)"
                    >{{ option.value }}</button>
                </div>
                <div class="flex items-center gap-1">
                    <Input v-model="line.measure" inputmode="decimal" class="h-8 w-16 text-right tabular-nums" :disabled="disabled || !line.interpretation" :aria-label="`Diamètre pour ${line.name}`" placeholder="—" />
                    <span class="text-[11px] text-muted-foreground">mm</span>
                </div>
            </li>
        </ul>

        <div class="border-t border-border p-3">
            <Textarea v-model="form.notes" rows="2" :disabled="disabled" placeholder="Commentaire sur l’antibiogramme (facultatif)" aria-label="Commentaire sur l’antibiogramme" />
        </div>
    </section>
</template>
