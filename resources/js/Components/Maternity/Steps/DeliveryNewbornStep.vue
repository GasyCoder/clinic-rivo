<script setup>
import { computed } from 'vue';
import { Plus, X } from 'lucide-vue-next';
import ClinicalFieldHints from '@/Components/Clinical/ClinicalFieldHints.vue';
import NewbornDossiers from '@/Components/Clinical/NewbornDossiers.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { apgarHints, birthWeightHints, newbornCountHints } from '@/utilities/maternityChecks';

/**
 * ADR-204 — accouchement, étape 5 : le ou les nouveau-nés.
 *
 * Rien ne change du circuit existant : une fiche par bébé (ADR-139), son
 * identité donnée par le serveur (ADR-144/146), son dossier patient créé
 * depuis la Maternité (ADR-177). Des jumeaux restent **une** grossesse.
 */
const props = defineProps({
    form: { type: Object, required: true },
    reference: { type: Object, required: true },
    patient: { type: Object, required: true },
    babies: { type: Object, default: null },
    newbornPatients: { type: Object, default: () => ({}) },
    expectedNewborns: { type: Number, default: null },
    maxNewborns: { type: Number, default: 5 },
    readOnly: { type: Boolean, default: false },
});
defineEmits(['add-newborn']);

const SEXES = [
    { value: '', label: 'Non renseigné' },
    { value: 'F', label: 'Féminin' },
    { value: 'M', label: 'Masculin' },
    { value: 'UNDETERMINED', label: 'Indéterminé' },
];
const newbornPatient = (newborn) => (newborn.uuid ? props.newbornPatients[newborn.uuid] : null);
const hintsFor = (newborn) => ({
    weight: birthWeightHints(newborn.birth_weight_g, props.reference),
    apgar: apgarHints(newborn.apgar, props.reference),
});
const countHint = computed(() => newbornCountHints(props.form.newborn_data.newborns.length, props.expectedNewborns));
</script>

<template>
    <div class="space-y-4">
        <!-- ADR-145, ADR-146 : les bébés de cette mère, chacun avec son dossier — hors du formulaire verrouillé. -->
        <NewbornDossiers
            v-if="babies"
            :babies="babies"
            :show-maternity-link="false"
            :creation-blocked-reason="form.isDirty ? 'Le dossier Maternité s’enregistre : le dossier patient reprend la fiche enregistrée.' : ''"
        />

        <fieldset class="space-y-3" :disabled="readOnly">
            <Card v-for="(newborn, index) in form.newborn_data.newborns" :key="index" class="p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Nouveau-né {{ index + 1 }}</h3>
                    <Button
                        v-if="form.newborn_data.newborns.length > 1 && ! newbornPatient(newborn)"
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="h-7 px-2 text-[11px] text-destructive hover:text-destructive"
                        @click="form.newborn_data.newborns.splice(index, 1)"
                    ><X class="h-3.5 w-3.5" />Retirer</Button>
                </div>
                <div class="mb-4 grid gap-4 md:grid-cols-2">
                    <FormField label="Nom" hint="facultatif — celui de la mère par défaut" :error="form.errors[`newborn_data.newborns.${index}.last_name`]">
                        <Input v-model="newborn.last_name" maxlength="100" autocomplete="off" :placeholder="patient.last_name" />
                    </FormField>
                    <FormField label="Prénom" hint="facultatif : pas toujours déjà prénommé" :error="form.errors[`newborn_data.newborns.${index}.first_name`]">
                        <Input v-model="newborn.first_name" maxlength="100" autocomplete="off" />
                    </FormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <FormField label="Sexe" :error="form.errors[`newborn_data.newborns.${index}.sex`]">
                        <Select v-model="newborn.sex" class="h-10 w-full min-w-0" :options="SEXES" />
                    </FormField>
                    <FormField label="Poids naissance (g)" :error="form.errors[`newborn_data.newborns.${index}.birth_weight_g`]">
                        <Input v-model="newborn.birth_weight_g" type="number" :min="reference.birth_weight.min" :max="reference.birth_weight.max" placeholder="ex. 3200" inputmode="numeric" />
                        <ClinicalFieldHints :hints="hintsFor(newborn).weight" label="Repères sur le poids de naissance" />
                    </FormField>
                    <FormField label="Apgar" :error="form.errors[`newborn_data.newborns.${index}.apgar`]">
                        <Input v-model="newborn.apgar" type="number" min="0" :max="reference.apgar.max" inputmode="numeric" />
                        <ClinicalFieldHints :hints="hintsFor(newborn).apgar" label="Repères sur le score d’Apgar" />
                    </FormField>
                    <FormField label="État du nouveau-né" :error="form.errors[`newborn_data.newborns.${index}.condition`]">
                        <Input v-model="newborn.condition" />
                    </FormField>
                </div>
                <FormField
                    as="div"
                    class="mt-4"
                    :label="form.newborn_data.newborns.length > 1 ? `Soins — nouveau-né ${index + 1}` : 'Soins bébé'"
                    :error="form.errors[`newborn_data.newborns.${index}.care_notes`]"
                >
                    <Textarea v-model="newborn.care_notes" :rows="3" />
                </FormField>
            </Card>

            <div class="flex flex-wrap items-center gap-3">
                <Button type="button" size="sm" variant="outline" :disabled="form.newborn_data.newborns.length >= maxNewborns" @click="$emit('add-newborn')">
                    <Plus class="h-4 w-4" />Ajouter un nouveau-né
                </Button>
                <ClinicalFieldHints :hints="countHint" label="Repères sur le nombre de nouveau-nés" />
                <span class="text-xs text-muted-foreground">{{ form.newborn_data.newborns.length }} / {{ maxNewborns }} — au-delà, le dossier serait refusé à l’enregistrement.</span>
            </div>
        </fieldset>
    </div>
</template>
