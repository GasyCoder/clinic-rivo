<script setup>
import { computed } from 'vue';
import Button from '@/Components/Shadcn/Button.vue';
import CheckBox from '@/Components/UI/CheckBox.vue';
import FormError from '@/Components/UI/FormError.vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import Input from '@/Components/Shadcn/Input.vue';

/**
 * Information the patient reveals during the interview: a new allergy, a new
 * antecedent, a habitual treatment nobody had recorded.
 *
 * Two destinations, never confused. The line is always kept on the
 * consultation — it is what the patient said today. Copying it into the
 * patient's permanent record is a second, explicit act behind a checkbox,
 * and it requires `patients.medical_history.manage`: the interview never
 * writes the permanent file silently.
 */
const props = defineProps({
    allergies: { type: Array, required: true },
    antecedents: { type: Array, required: true },
    habitualTreatments: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    /** Whether this account may write the permanent patient record. */
    canPromote: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['add', 'remove', 'update']);

const ANTECEDENT_TYPES = [
    { value: 'PERSONAL', label: 'Personnel' },
    { value: 'FAMILIAL', label: 'Familial' },
];

const total = computed(() => props.allergies.length + props.antecedents.length + props.habitualTreatments.length);
const patch = (group, index, field, value) => emit('update', { group, index, field, value });
</script>

<template>
    <section class="rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Informations nouvelles signalées</h3>
            <span v-if="total" class="text-[11px] font-semibold text-primary">{{ total }} signalée(s)</span>
        </div>
        <p class="mt-1 text-[11px] leading-4 text-muted-foreground">
            Ce que le patient révèle pendant l’entretien. Conservé sur la consultation ; le dossier permanent n’est mis à jour que si vous le cochez.
        </p>

        <div v-if="!disabled" class="mt-3 flex flex-wrap gap-2">
            <Button type="button" size="sm" variant="white-outline" @click="emit('add', 'allergies')">
                <Plus class="h-4 w-4 me-1.5" aria-hidden="true" />Nouvelle allergie
            </Button>
            <Button type="button" size="sm" variant="white-outline" @click="emit('add', 'antecedents')">
                <Plus class="h-4 w-4 me-1.5" aria-hidden="true" />Nouvel antécédent
            </Button>
            <Button type="button" size="sm" variant="white-outline" @click="emit('add', 'habitualTreatments')">
                <Plus class="h-4 w-4 me-1.5" aria-hidden="true" />Traitement habituel
            </Button>
        </div>

        <p v-if="!total" class="mt-3 text-[11px] text-muted-foreground">Aucune information nouvelle signalée.</p>

        <div v-else class="mt-3 space-y-2">
            <!-- Allergies -->
            <div
                v-for="(item, index) in allergies"
                :key="`allergy-${index}`"
                class="rounded-md border border-red-100 bg-red-50/40 p-3 dark:border-red-950 dark:bg-red-950/10"
            >
                <div class="flex items-start gap-2">
                    <div class="min-w-0 flex-1 space-y-2">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-red-700 dark:text-red-300">Allergie</p>
                        <Input
                            :model-value="item.substance"
                            :disabled="disabled"
                            maxlength="255"
                            placeholder="Substance (ex. Amoxicilline)"
                            aria-label="Substance de l’allergie signalée"
                            @update:model-value="patch('allergies', index, 'substance', $event)"
                        />
                        <FormError :message="errors[`reported_allergies.${index}.substance`]" />
                        <Input
                            :model-value="item.reaction"
                            :disabled="disabled"
                            maxlength="500"
                            placeholder="Réaction observée (facultatif)"
                            aria-label="Réaction de l’allergie signalée"
                            @update:model-value="patch('allergies', index, 'reaction', $event)"
                        />
                    </div>
                    <button v-if="!disabled" type="button" class="mt-1 flex size-7 shrink-0 items-center justify-center rounded border border-border text-muted-foreground hover:border-red-200 hover:text-red-600" aria-label="Retirer cette allergie" @click="emit('remove', { group: 'allergies', index })">
                        <Trash2 class="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>
                <CheckBox
                    v-if="canPromote && !disabled"
                    :id="`promote-allergy-${index}`"
                    class="mt-2"
                    :model-value="item.promote_to_patient_record"
                    @update:model-value="patch('allergies', index, 'promote_to_patient_record', $event)"
                >
                    <span class="text-[11px]">Ajouter également au dossier patient</span>
                </CheckBox>
            </div>

            <!-- Antécédents -->
            <div
                v-for="(item, index) in antecedents"
                :key="`antecedent-${index}`"
                class="rounded-md border border-border bg-card p-3"
            >
                <div class="flex items-start gap-2">
                    <div class="min-w-0 flex-1 space-y-2">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Antécédent</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="type in ANTECEDENT_TYPES"
                                :key="type.value"
                                type="button"
                                role="radio"
                                :aria-checked="item.type === type.value"
                                :disabled="disabled"
                                :class="[
                                    'rounded border px-2.5 py-1 text-[11px] font-semibold transition-colors',
                                    item.type === type.value
                                        ? 'border-primary/50 bg-primary/10 text-primary'
                                        : 'border-border bg-card text-muted-foreground hover:bg-muted/35',
                                ]"
                                @click="patch('antecedents', index, 'type', type.value)"
                            >{{ type.label }}</button>
                        </div>
                        <Input
                            :model-value="item.description"
                            :disabled="disabled"
                            maxlength="2000"
                            placeholder="Ex. hypertension depuis 2019"
                            aria-label="Description de l’antécédent signalé"
                            @update:model-value="patch('antecedents', index, 'description', $event)"
                        />
                        <FormError :message="errors[`reported_antecedents.${index}.description`]" />
                    </div>
                    <button v-if="!disabled" type="button" class="mt-1 flex size-7 shrink-0 items-center justify-center rounded border border-border text-muted-foreground hover:border-red-200 hover:text-red-600" aria-label="Retirer cet antécédent" @click="emit('remove', { group: 'antecedents', index })">
                        <Trash2 class="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>
                <CheckBox
                    v-if="canPromote && !disabled"
                    :id="`promote-antecedent-${index}`"
                    class="mt-2"
                    :model-value="item.promote_to_patient_record"
                    @update:model-value="patch('antecedents', index, 'promote_to_patient_record', $event)"
                >
                    <span class="text-[11px]">Ajouter également au dossier patient</span>
                </CheckBox>
            </div>

            <!-- Traitements habituels découverts pendant l'entretien -->
            <div
                v-for="(item, index) in habitualTreatments"
                :key="`habitual-${index}`"
                class="rounded-md border border-border bg-card p-3"
            >
                <div class="flex items-start gap-2">
                    <div class="min-w-0 flex-1 space-y-2">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Traitement habituel</p>
                        <Input
                            :model-value="item.medication_name"
                            :disabled="disabled"
                            maxlength="255"
                            placeholder="Nom du médicament"
                            aria-label="Nom du traitement habituel signalé"
                            @update:model-value="patch('habitualTreatments', index, 'medication_name', $event)"
                        />
                        <FormError :message="errors[`reported_habitual_treatments.${index}.medication_name`]" />
                        <div class="grid gap-2 sm:grid-cols-2">
                            <Input :model-value="item.dosage" :disabled="disabled" maxlength="150" placeholder="5 mg" aria-label="Dosage du traitement habituel" @update:model-value="patch('habitualTreatments', index, 'dosage', $event)" />
                            <Input :model-value="item.frequency" :disabled="disabled" maxlength="150" placeholder="1 fois/jour" aria-label="Fréquence du traitement habituel" @update:model-value="patch('habitualTreatments', index, 'frequency', $event)" />
                        </div>
                    </div>
                    <button v-if="!disabled" type="button" class="mt-1 flex size-7 shrink-0 items-center justify-center rounded border border-border text-muted-foreground hover:border-red-200 hover:text-red-600" aria-label="Retirer ce traitement habituel" @click="emit('remove', { group: 'habitualTreatments', index })">
                        <Trash2 class="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>
                <CheckBox
                    v-if="canPromote && !disabled"
                    :id="`promote-habitual-${index}`"
                    class="mt-2"
                    :model-value="item.promote_to_patient_record"
                    @update:model-value="patch('habitualTreatments', index, 'promote_to_patient_record', $event)"
                >
                    <span class="text-[11px]">Ajouter également au dossier patient</span>
                </CheckBox>
            </div>
        </div>

        <!-- Dit une seule fois, et seulement quand c'est vrai : le compte ne
             peut pas écrire le dossier permanent, la saisie reste utile. -->
        <p v-if="total && !canPromote" class="mt-2 text-[11px] leading-4 text-muted-foreground">
            Ces informations sont conservées sur la consultation. Votre compte ne peut pas modifier le dossier patient permanent.
        </p>
        <FormError class="mt-1" :message="errors.reported_information" />
    </section>
</template>
