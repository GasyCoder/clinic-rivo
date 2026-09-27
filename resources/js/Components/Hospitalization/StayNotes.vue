<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { NotebookPen, Send } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';

/**
 * ADR-162 — la note quotidienne du séjour, courte et structurée S/O/A/P.
 *
 * Elle remplace la visite de service : trois lignes n'exigent plus d'ouvrir
 * une consultation. Append-only : une erreur se corrige par une nouvelle note.
 */
const props = defineProps({
    stayUuid: { type: String, required: true },
    /** `null` : le compte n'a pas le droit de lire les notes. */
    notes: { type: Array, default: null },
    canWrite: { type: Boolean, default: false },
});

const SECTIONS = [
    { key: 'subjective', letter: 'S', label: 'Subjectif', hint: 'Ce que dit le patient : plaintes, douleur, sommeil.' },
    { key: 'objective', letter: 'O', label: 'Objectif', hint: 'Ce que vous constatez : examen, résultats du jour.' },
    { key: 'assessment', letter: 'A', label: 'Analyse', hint: 'Votre évaluation : évolution, hypothèses.' },
    { key: 'plan', letter: 'P', label: 'Plan', hint: 'La suite : traitement, examens, surveillance, sortie envisagée.' },
];

const form = useForm({ subjective: '', objective: '', assessment: '', plan: '' });
const hasContent = computed(() => SECTIONS.some((section) => String(form[section.key] ?? '').trim() !== ''));
const submit = () => form.post(`/hospitalisation/${props.stayUuid}/notes`, {
    preserveScroll: true,
    onSuccess: () => form.reset(),
});
</script>

<template>
    <div class="space-y-5">
        <Card v-if="canWrite" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><NotebookPen class="h-4 w-4 text-muted-foreground" />Note du jour</h2>
            <p class="mt-1 text-xs text-muted-foreground">Quelques lignes suffisent. Au moins une rubrique ; la note est datée et signée à votre nom.</p>
            <form class="mt-4 grid gap-4 md:grid-cols-2" @submit.prevent="submit">
                <FormField v-for="section in SECTIONS" :key="section.key" :label="`${section.letter} · ${section.label}`" :error="form.errors[section.key]">
                    <Textarea v-model="form[section.key]" :rows="3" maxlength="3000" :placeholder="section.hint" />
                </FormField>
                <div class="flex items-center justify-end gap-3 md:col-span-2">
                    <FormError :message="form.errors.subjective && !hasContent ? form.errors.subjective : null" />
                    <Button type="submit" size="sm" :disabled="form.processing || !hasContent"><Send class="h-4 w-4" />Enregistrer la note</Button>
                </div>
            </form>
        </Card>

        <Card class="p-5">
            <h2 class="text-sm font-semibold text-foreground">Notes du séjour</h2>
            <p v-if="notes === null" class="mt-3 text-xs text-muted-foreground">Non visible avec vos droits (hospital_notes.view).</p>
            <p v-else-if="!notes.length" class="mt-3 rounded-md border border-dashed border-border bg-muted/30 px-3 py-6 text-center text-xs text-muted-foreground">
                Aucune note pour ce séjour.
            </p>
            <ol v-else class="mt-4 space-y-3">
                <li v-for="note in notes" :key="note.uuid" class="rounded-md border border-border bg-card p-3">
                    <p class="text-xs text-muted-foreground">
                        <span class="font-semibold text-foreground">{{ formatDateTime(note.written_at) }}</span>
                        <template v-if="note.written_by"> · {{ doctorName(note.written_by) }}</template>
                    </p>
                    <dl class="mt-2 grid gap-x-6 gap-y-2 text-sm md:grid-cols-2">
                        <template v-for="section in SECTIONS" :key="section.key">
                            <div v-if="note[section.key]">
                                <dt class="text-xs font-semibold text-muted-foreground">{{ section.letter }} · {{ section.label }}</dt>
                                <dd class="whitespace-pre-line text-foreground">{{ note[section.key] }}</dd>
                            </div>
                        </template>
                    </dl>
                </li>
            </ol>
        </Card>
    </div>
</template>
