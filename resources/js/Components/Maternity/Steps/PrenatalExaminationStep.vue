<script setup>
import { computed } from 'vue';
import { Activity, Baby, Compass, Footprints, HeartPulse, Hourglass, NotebookPen, Ruler, Stethoscope, Waves } from 'lucide-vue-next';
import ClinicalFieldHints from '@/Components/Clinical/ClinicalFieldHints.vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import MaternityVitalsPanel from '@/Components/Maternity/MaternityVitalsPanel.vue';
import ClinicalSubsection from '@/Components/Clinical/ClinicalSubsection.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { fetalHeartRateHints, fundalHeightHints, gestationalAgeHints } from '@/utilities/maternityChecks';
import { withIcons } from '@/utilities/maternityFieldIcons';

/**
 * ADR-204 — étape 3 : les constantes générales, lues sur la fiche Soins
 * (jamais ressaisies), puis l'examen obstétrical du jour.
 *
 * Aucun champ n'est obligatoire : ce qui se mesure dépend du terme. Les repères
 * sous les champs sont une aide (ADR-137), jamais un verrou.
 */
const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, required: true },
    reference: { type: Object, required: true },
    pregnancy: { type: Object, default: null },
    careRecord: { type: Object, default: null },
    careRecordUrl: { type: String, default: null },
    allergies: { type: Array, default: () => [] },
    readOnly: { type: Boolean, default: false },
});

/** Datée, la grossesse donne le terme ; sinon il se saisit (ADR-201). */
const dated = computed(() => Boolean(props.pregnancy?.dated ?? (props.pregnancy?.last_menstrual_period || props.pregnancy?.estimated_due_date)));
const weeks = computed(() => (dated.value ? props.pregnancy?.gestational_age_weeks : props.form.prenatal_data.gestational_age_weeks));
const fundalHints = computed(() => fundalHeightHints(props.form.prenatal_data.fundal_height_cm, weeks.value, props.reference));
const heartHints = computed(() => fetalHeartRateHints(props.form.prenatal_data.fetal_heart_rate, props.reference));
const ageHints = computed(() => (dated.value ? [] : gestationalAgeHints(props.form.prenatal_data.gestational_age_weeks, props.reference)));
const fetalMovements = computed(() => withIcons('fetal_movements', props.options.fetal_movements));
const contractions = computed(() => withIcons('contractions', props.options.contractions));
</script>

<template>
    <div class="space-y-5">
        <section class="space-y-2" aria-labelledby="prenatal-vitals-title">
            <h3 id="prenatal-vitals-title" class="flex items-center gap-2 text-sm font-bold text-foreground"><Activity class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Constantes générales</h3>
            <MaternityVitalsPanel :care-record="careRecord" :care-record-url="careRecordUrl" :allergies="allergies" />
        </section>

        <fieldset class="space-y-4" :disabled="readOnly">
            <ClinicalSubsection :icon="Stethoscope" tone="rose" title="Mesures obstétricales" description="Selon le terme : rien n’est obligatoire, les repères sous les champs ne bloquent rien.">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <FormField v-if="dated" label="Terme" hint="calculé par le serveur">
                        <p class="flex h-10 items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 text-sm font-semibold text-foreground">
                            <Hourglass class="h-4 w-4 text-muted-foreground" aria-hidden="true" />{{ pregnancy?.gestational_age_label ?? 'Non calculable' }}
                        </p>
                    </FormField>
                    <div v-else class="grid grid-cols-2 gap-3">
                        <FormField label="Terme (SA)" :error="form.errors['prenatal_data.gestational_age_weeks']">
                            <Input v-model="form.prenatal_data.gestational_age_weeks" type="number" min="0" :max="reference.gestational_age.max" inputmode="numeric" />
                            <ClinicalFieldHints :hints="ageHints" label="Repères sur le terme" />
                        </FormField>
                        <FormField label="Jours" :error="form.errors['prenatal_data.gestational_age_days']">
                            <Input v-model="form.prenatal_data.gestational_age_days" type="number" min="0" max="6" inputmode="numeric" />
                        </FormField>
                    </div>
                    <FormField label="Hauteur utérine" hint="cm" :error="form.errors['prenatal_data.fundal_height_cm']">
                        <IconInput v-model="form.prenatal_data.fundal_height_cm" :icon="Ruler" type="number" min="0" :max="reference.fundal_height.max" step="0.1" inputmode="decimal" placeholder="Ex. 28" />
                        <ClinicalFieldHints :hints="fundalHints" label="Repères sur la hauteur utérine" />
                    </FormField>
                    <FormField label="Activité cardiaque fœtale" hint="bpm" :error="form.errors['prenatal_data.fetal_heart_rate']">
                        <IconInput v-model="form.prenatal_data.fetal_heart_rate" :icon="HeartPulse" type="number" :min="reference.fetal_heart_rate.min" :max="reference.fetal_heart_rate.max" inputmode="numeric" placeholder="Ex. 140" />
                        <ClinicalFieldHints :hints="heartHints" label="Repères sur le rythme cardiaque fœtal" />
                    </FormField>
                </div>
            </ClinicalSubsection>

            <ClinicalSubsection :icon="Baby" tone="rose" title="Le fœtus" description="Ce que la patiente rapporte et ce que l’examen trouve.">
                <div class="grid gap-5 lg:grid-cols-3">
                    <div class="space-y-2">
                        <p class="flex items-center gap-1.5 text-xs font-bold text-foreground"><Footprints class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />Mouvements fœtaux</p>
                        <ClinicalSegmentedChoice v-model="form.prenatal_data.fetal_movements" name="prenatal-fetal-movements" :options="fetalMovements" :disabled="readOnly" />
                    </div>
                    <div class="space-y-2">
                        <p class="flex items-center gap-1.5 text-xs font-bold text-foreground"><Waves class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />Contractions</p>
                        <ClinicalSegmentedChoice v-model="form.prenatal_data.contractions" name="prenatal-contractions" :options="contractions" :disabled="readOnly" />
                    </div>
                    <FormField :icon="Compass" label="Présentation fœtale" hint="si pertinente" :error="form.errors['prenatal_data.presentation']">
                        <Select v-model="form.prenatal_data.presentation" class="h-10 w-full min-w-0" :options="[{ value: '', label: 'Non renseignée' }, ...options.presentations]" />
                    </FormField>
                </div>
            </ClinicalSubsection>

            <ClinicalSubsection :icon="NotebookPen" tone="rose" title="Observations cliniques" description="Ce que l’examen ne dit pas dans les cases ci-dessus.">
                <Textarea v-model="form.prenatal_data.notes" :rows="4" aria-label="Observations cliniques" placeholder="Examen général, œdèmes, col, remarques… (facultatif)" />
                <FormError v-if="form.errors['prenatal_data.notes']" class="mt-1">{{ form.errors['prenatal_data.notes'] }}</FormError>
            </ClinicalSubsection>
        </fieldset>
    </div>
</template>
