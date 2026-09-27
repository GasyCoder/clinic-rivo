<script setup>
import { computed } from 'vue';
import { Baby, CalendarPlus, ClipboardPen, Layers, ShieldAlert } from 'lucide-vue-next';
import ClinicalFieldHints from '@/Components/Clinical/ClinicalFieldHints.vue';
import ClinicalSubsection from '@/Components/Clinical/ClinicalSubsection.vue';
import PregnancySelectionCard from '@/Components/Maternity/PregnancySelectionCard.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { parityHints, pregnancyFromLastPeriod } from '@/utilities/maternityChecks';
import { continuesSinglePregnancy } from '@/utilities/maternityWorkflow';

/**
 * La grossesse de cette prise en charge (ADR-201, ADR-204).
 *
 * Le rattachement reste explicite — continuer la grossesse en cours ou en
 * créer une — et rien n'est rattaché en silence. Tant qu'il n'est pas fait,
 * aucun enregistrement automatique ne part : le serveur exigerait ce choix.
 *
 * DDR/DPA se saisissent à la création, puis une seule fois si la grossesse a
 * été ouverte sans datation ; ensuite elles se corrigent par l'action auditée.
 * G/P et facteurs de risque se précisent tant que la grossesse n'a que la
 * consultation qui l'a ouverte.
 *
 * Les repères de la grossesse (DPA, terme, DDR…) ne sont pas répétés ici :
 * l'en-tête du parcours les porte déjà, sur toutes les étapes.
 */
const props = defineProps({
    form: { type: Object, required: true },
    pregnancy: { type: Object, default: null },
    activePregnancies: { type: Array, default: () => [] },
    selectionRequired: { type: Boolean, default: false },
    canPrenatal: { type: Boolean, default: false },
    readOnly: { type: Boolean, default: false },
    reference: { type: Object, required: true },
});
defineEmits(['continue', 'create']);

/** Une seule grossesse active : elle se continue depuis l'en-tête, la carte de sélection se tait. */
const continueFromHeader = computed(() => continuesSinglePregnancy(props.selectionRequired, props.activePregnancies, props.pregnancy));
const creating = computed(() => props.form.pregnancy_choice === 'CREATE');
const datingEditable = computed(() => props.canPrenatal && (creating.value || (props.pregnancy !== null && ! props.selectionRequired && ! props.pregnancy.dated)));
const detailsEditable = computed(() => props.canPrenatal && (creating.value || Boolean(props.pregnancy?.details_editable)));

const lastPeriod = computed(() => pregnancyFromLastPeriod(
    props.form.pregnancy_data.last_menstrual_period,
    props.form.pregnancy_data.estimated_due_date,
    props.reference,
));
const hints = computed(() => [
    ...parityHints(props.form.pregnancy_data.gravidity, props.form.pregnancy_data.parity),
    ...(datingEditable.value ? (lastPeriod.value?.hints ?? []) : []),
]);
/** Reprend la DPA calculée — jamais d'office, jamais par-dessus une saisie. */
const applyDueDate = (hint) => { props.form.pregnancy_data.estimated_due_date = hint.action.value; };
</script>

<template>
    <div class="space-y-4">
        <PregnancySelectionCard
            v-if="selectionRequired && ! continueFromHeader"
            :active-pregnancies="activePregnancies"
            :choice="form.pregnancy_choice"
            :selected-uuid="form.pregnancy_uuid"
            :disabled="readOnly"
            @continue="$emit('continue', $event)"
            @create="$emit('create')"
        />
        <FormError v-if="form.errors.pregnancy_choice || form.errors.pregnancy_uuid">
            {{ form.errors.pregnancy_choice || form.errors.pregnancy_uuid }}
        </FormError>

        <fieldset v-if="datingEditable || detailsEditable" :disabled="readOnly">
            <ClinicalSubsection
                :icon="creating ? CalendarPlus : ClipboardPen"
                tone="rose"
                :title="creating ? 'Nouvelle grossesse' : 'Compléter la grossesse'"
                description="Gestité, parité, datation et facteurs de risque : repris ensuite par chaque consultation de cette grossesse."
            >
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <FormField v-if="detailsEditable" label="Gestité" hint="grossesses, celle-ci comprise" :error="form.errors['pregnancy_data.gravidity']">
                            <IconInput v-model="form.pregnancy_data.gravidity" :icon="Layers" type="number" min="0" max="30" inputmode="numeric" placeholder="G" />
                        </FormField>
                        <FormField v-if="detailsEditable" label="Parité" hint="accouchements" :error="form.errors['pregnancy_data.parity']">
                            <IconInput v-model="form.pregnancy_data.parity" :icon="Baby" type="number" min="0" max="30" inputmode="numeric" placeholder="P" />
                        </FormField>
                        <FormField v-if="datingEditable" label="Dernières règles (DDR)" :error="form.errors['pregnancy_data.last_menstrual_period']">
                            <DatePicker v-model="form.pregnancy_data.last_menstrual_period" />
                        </FormField>
                        <FormField v-if="datingEditable" label="Terme estimé (DPA)" hint="calculé depuis la DDR par le serveur" :error="form.errors['pregnancy_data.estimated_due_date']">
                            <DatePicker v-model="form.pregnancy_data.estimated_due_date" />
                        </FormField>
                    </div>
                    <ClinicalFieldHints :hints="hints" label="Repères sur la grossesse" @apply="applyDueDate" />
                    <FormField v-if="detailsEditable" as="div" :icon="ShieldAlert" label="Facteurs de risque" hint="facultatif" :error="form.errors['pregnancy_data.risk_factors']">
                        <Textarea v-model="form.pregnancy_data.risk_factors" :rows="3" placeholder="HTA, diabète, utérus cicatriciel, grossesse gémellaire… Ils s’afficheront en tête du dossier." />
                    </FormField>
                </div>
            </ClinicalSubsection>
        </fieldset>

        <p v-else-if="pregnancy && ! selectionRequired" class="text-xs text-muted-foreground">
            DDR, DPA, gestité, parité et facteurs de risque viennent de la grossesse longitudinale. La datation se corrige par l’action auditée « Corriger la datation ».
        </p>
    </div>
</template>
