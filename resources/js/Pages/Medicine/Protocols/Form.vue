<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import PrescriptionLineEditor from '@/Components/Clinical/PrescriptionLineEditor.vue';
import { editorFieldsFor } from '@/utilities/posology';
import { ArrowLeft, BookMarked, Plus, Save, Trash2, X } from 'lucide-vue-next';

defineOptions({ layout: AppLayout });

/**
 * Rédiger un protocole thérapeutique (ADR-111).
 *
 * Trois questions, dans l'ordre où un médecin les pose : quel diagnostic ?
 * chez quels patients ? avec quelle ordonnance ? Les signes évocateurs sont
 * facultatifs — sans eux, le protocole ne propose pas le diagnostic, il sert
 * seulement l'ordonnance une fois le diagnostic posé.
 */
const props = defineProps({
    protocol: { type: Object, default: null },
    diagnostics: { type: Array, required: true },
    medicines: { type: Array, required: true },
    administration_routes: { type: Array, required: true },
});

let sequence = 0;
const toEditorLine = (line) => ({
    _key: `line-${++sequence}`,
    medicine_uuid: line.medicine_uuid ?? '',
    dosage: line.dosage ?? '',
    route: line.route ?? null,
    frequency: line.frequency ?? '',
    duration: line.duration ?? '',
    quantity: line.quantity ?? '',
    instructions: line.instructions ?? '',
    ...editorFieldsFor(line),
});

const form = useForm({
    diagnostic_catalog_uuid: props.protocol?.diagnostic_catalog_uuid ?? '',
    name: props.protocol?.name ?? '',
    indications: [...(props.protocol?.indications ?? [])],
    min_age_years: props.protocol?.min_age_years ?? '',
    max_age_years: props.protocol?.max_age_years ?? '',
    sex: props.protocol?.sex ?? '',
    min_weight_kg: props.protocol?.min_weight_kg ?? '',
    max_weight_kg: props.protocol?.max_weight_kg ?? '',
    notes: props.protocol?.notes ?? '',
    is_active: props.protocol?.is_active ?? true,
    lines: (props.protocol?.lines ?? []).map(toEditorLine),
});

const editing = computed(() => props.protocol !== null);

const sexOptions = [
    { value: '', label: 'Tous' },
    { value: 'F', label: 'Femmes' },
    { value: 'M', label: 'Hommes' },
];

// Un signe par entrée, ajouté à la touche Entrée : plus lisible qu'une
// liste séparée par des virgules, et chaque signe se retire d'un clic.
const indicationDraft = ref('');
const addIndication = () => {
    const sign = indicationDraft.value.trim();
    const known = form.indications.some((existing) => existing.toLocaleLowerCase('fr') === sign.toLocaleLowerCase('fr'));

    if (sign !== '' && !known) {
        form.indications.push(sign);
    }

    indicationDraft.value = '';
};
const removeIndication = (index) => form.indications.splice(index, 1);

const medicineOptions = computed(() => props.medicines.map((medicine) => ({
    value: medicine.uuid,
    label: medicine.strength ? `${medicine.name} · ${medicine.strength}` : medicine.name,
})));
const medicineFor = (line) => props.medicines.find((medicine) => medicine.uuid === line.medicine_uuid);

const addLine = () => form.lines.push(toEditorLine({}));
const removeLine = (index) => form.lines.splice(index, 1);
const updateLine = (index, { field, value }) => {
    form.lines = form.lines.map((line, position) => (position === index ? { ...line, [field]: value } : line));
};

/**
 * Seuls les champs du protocole partent au serveur : `_dose_*` et
 * `_duration_*` ne servent qu'à composer la saisie. Une quantité non fixée
 * est envoyée vide — c'est alors la posologie qui la déduira à la
 * prescription (ADR-110).
 */
const submit = () => {
    form.transform((data) => ({
        ...data,
        lines: data.lines.map((line) => ({
            medicine_uuid: line.medicine_uuid,
            dosage: line.dosage,
            route: line.route,
            frequency: line.frequency,
            duration: line.duration,
            quantity: line._quantity_touched ? line.quantity : null,
            instructions: line.instructions,
        })),
    }));

    if (editing.value) {
        form.put(`/medicine/protocoles/${props.protocol.uuid}`, { preserveScroll: true });
    } else {
        form.post('/medicine/protocoles', { preserveScroll: true });
    }
};

const lineError = (index, field) => form.errors[`lines.${index}.${field}`];
</script>

<template>
    <Head :title="editing ? 'Modifier le protocole' : 'Nouveau protocole'" />

    <form class="mx-auto w-full max-w-5xl space-y-5" @submit.prevent="submit">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <BookMarked class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <h1 class="font-heading text-lg font-bold text-foreground">{{ editing ? 'Modifier le protocole' : 'Nouveau protocole' }}</h1>
                        <p class="mt-0.5 max-w-2xl text-sm text-muted-foreground">
                            Corriger un protocole ne réécrit aucun dossier : les diagnostics et ordonnances déjà enregistrés gardent ce qui a été prescrit.
                        </p>
                    </div>
                </div>
                <Button :as="Link" href="/medicine/protocoles" variant="white-outline">
                    <ArrowLeft class="h-4 w-4" aria-hidden="true" />Retour
                </Button>
            </div>
        </Card>

        <Card class="p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">1 · Diagnostic traité</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <FormField as="div" label="Diagnostic" required :error="form.errors.diagnostic_catalog_uuid">
                    <Select
                        v-model="form.diagnostic_catalog_uuid"
                        :options="diagnostics"
                        placeholder="Choisir dans le référentiel…"
                        aria-label="Diagnostic traité"
                        class="w-full min-w-0"
                    />
                </FormField>
                <FormField label="Nom du protocole" required :error="form.errors.name">
                    <Input v-model="form.name" maxlength="255" placeholder="Ex. Paludisme simple — adulte" />
                </FormField>
            </div>

            <FormField as="div" class="mt-4" label="Signes évocateurs" hint="· facultatif" :error="form.errors.indications">
                <div class="flex gap-2">
                    <Input
                        v-model="indicationDraft"
                        maxlength="120"
                        placeholder="Ex. fièvre, frissons, céphalées… puis Entrée"
                        aria-label="Ajouter un signe évocateur"
                        class="min-w-0 flex-1"
                        @keydown.enter.prevent="addIndication"
                    />
                    <Button type="button" variant="white-outline" :disabled="indicationDraft.trim() === ''" @click="addIndication">
                        <Plus class="h-4 w-4" aria-hidden="true" />Ajouter
                    </Button>
                </div>
                <p class="mt-1.5 text-[11px] leading-4 text-muted-foreground">
                    Cherchés mot pour mot, sans accents ni majuscules, dans l’interrogatoire et l’examen. Sans signe, le protocole ne propose pas le diagnostic : il ne sert qu’à l’ordonnance.
                </p>
                <div v-if="form.indications.length" class="mt-2 flex flex-wrap gap-1.5">
                    <Badge v-for="(sign, index) in form.indications" :key="sign" tone="neutral" class="gap-1 py-0.5 pe-1 ps-2.5 font-medium">
                        {{ sign }}
                        <button type="button" class="grid h-4 w-4 place-items-center rounded-full hover:bg-accent" :aria-label="`Retirer ${sign}`" @click="removeIndication(index)">
                            <X class="h-3 w-3" aria-hidden="true" />
                        </button>
                    </Badge>
                </div>
            </FormField>
        </Card>

        <Card class="p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">2 · Patients concernés</h2>
            <p class="mt-1 text-xs leading-5 text-muted-foreground">
                Laissez vide pour ne rien restreindre. Une borne posée exclut un patient dont la valeur est inconnue : un protocole pédiatrique ne s’applique pas faute de connaître l’âge.
            </p>
            <div class="mt-3 grid gap-4 sm:grid-cols-5">
                <FormField label="Âge min." hint="· ans" :error="form.errors.min_age_years">
                    <Input v-model="form.min_age_years" type="number" min="0" max="130" inputmode="numeric" />
                </FormField>
                <FormField label="Âge max." hint="· ans" :error="form.errors.max_age_years">
                    <Input v-model="form.max_age_years" type="number" min="0" max="130" inputmode="numeric" />
                </FormField>
                <FormField as="div" label="Sexe" :error="form.errors.sex">
                    <Select v-model="form.sex" :options="sexOptions" aria-label="Sexe" class="w-full min-w-0" />
                </FormField>
                <FormField label="Poids min." hint="· kg" :error="form.errors.min_weight_kg">
                    <Input v-model="form.min_weight_kg" type="number" min="0" max="400" step="0.1" inputmode="decimal" />
                </FormField>
                <FormField label="Poids max." hint="· kg" :error="form.errors.max_weight_kg">
                    <Input v-model="form.max_weight_kg" type="number" min="0" max="400" step="0.1" inputmode="decimal" />
                </FormField>
            </div>
        </Card>

        <Card class="p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">3 · Ordonnance type</h2>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Proposée au médecin une fois ce diagnostic posé — il l’ajuste toujours avant de valider.</p>
                </div>
                <Button type="button" variant="white-outline" size="sm" @click="addLine">
                    <Plus class="h-4 w-4" aria-hidden="true" />Ajouter un médicament
                </Button>
            </div>
            <FormError :message="form.errors.lines" />

            <div v-if="form.lines.length" class="mt-4 space-y-3">
                <article v-for="(line, index) in form.lines" :key="line._key" class="rounded-xl border border-border p-3">
                    <header class="mb-3 flex items-end gap-2">
                        <FormField as="div" class="flex-1" label="Médicament" required :error="lineError(index, 'medicine_uuid')">
                            <Select
                                :model-value="line.medicine_uuid"
                                :options="medicineOptions"
                                placeholder="Choisir au référentiel Pharmacie…"
                                aria-label="Médicament"
                                class="w-full min-w-0"
                                @update:model-value="updateLine(index, { field: 'medicine_uuid', value: $event })"
                            />
                        </FormField>
                        <Button
                            type="button"
                            icon
                            variant="ghost"
                            size="sm"
                            class="mb-0.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                            aria-label="Retirer ce médicament"
                            @click="removeLine(index)"
                        >
                            <Trash2 class="h-4 w-4" aria-hidden="true" />
                        </Button>
                    </header>

                    <PrescriptionLineEditor
                        :line="line"
                        :routes="administration_routes"
                        :quantity-unit="medicineFor(line)?.unit ?? 'unité(s)'"
                        :medicine-form="medicineFor(line)?.form"
                        :error-for="(field) => lineError(index, field)"
                        @update="updateLine(index, $event)"
                    />
                </article>
            </div>
            <p v-else class="mt-4 rounded-lg border border-dashed border-border px-4 py-6 text-center text-xs text-muted-foreground">
                Aucun médicament encore : ajoutez au moins une ligne à l’ordonnance type.
            </p>
        </Card>

        <Card class="p-5">
            <FormField label="Notes pour le prescripteur" hint="· facultatif" :error="form.errors.notes">
                <Textarea v-model="form.notes" rows="3" maxlength="3000" placeholder="Ex. contrôle à J3 si la fièvre persiste ; adapter en cas d’insuffisance rénale." />
            </FormField>
            <label class="mt-4 flex items-center gap-2.5 text-sm text-foreground">
                <Checkbox v-model="form.is_active" />
                Protocole actif — proposé en consultation
            </label>
        </Card>

        <div class="flex justify-end gap-2">
            <Button :as="Link" href="/medicine/protocoles" variant="white-outline">Annuler</Button>
            <Button type="submit" :disabled="form.processing">
                <Save class="h-4 w-4" aria-hidden="true" />{{ editing ? 'Enregistrer les modifications' : 'Enregistrer le protocole' }}
            </Button>
        </div>
    </form>
</template>
