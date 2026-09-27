<script setup>
import { computed } from 'vue';
import { Scissors } from 'lucide-vue-next';
import ClinicalFieldHints from '@/Components/Clinical/ClinicalFieldHints.vue';
import Button from '@/Components/Shadcn/Button.vue';
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { futureDateHints } from '@/utilities/maternityChecks';

/**
 * ADR-204 — accouchement, étape 4 : l'accouchement.
 *
 * La date et l'heure consignées ici sont celles qui clôturent la grossesse
 * (ADR-201) : jamais l'heure de la clôture du dossier. Décider une césarienne
 * crée une demande Chirurgie sur ce même passage (ADR-067) — un geste confirmé,
 * pas un champ du dossier.
 */
const props = defineProps({
    form: { type: Object, required: true },
    cesareanForm: { type: Object, required: true },
    canRequestCesarean: { type: Boolean, default: false },
    readOnly: { type: Boolean, default: false },
});
defineEmits(['request-cesarean']);

const MODES = [
    { value: '', label: 'Non renseignée' },
    { value: 'VAGINAL', label: 'Voie basse' },
    { value: 'INSTRUMENTAL', label: 'Instrumental' },
    { value: 'CESAREAN', label: 'Césarienne réalisée en Chirurgie' },
];
const CESAREAN_TYPES = [
    { value: 'SIMPLE', label: 'Simple' },
    { value: 'TWIN', label: 'Gémellaire' },
];
const dateHints = computed(() => futureDateHints(props.form.delivery_data.occurred_at, 'Accouchement'));
const firstError = (bag) => Object.values(bag ?? {})[0];
</script>

<template>
    <div class="space-y-5">
        <fieldset class="space-y-5" :disabled="readOnly">
            <div class="grid gap-4 md:grid-cols-2">
                <FormField label="Date et heure de l’accouchement" :error="form.errors['delivery_data.occurred_at']">
                    <DateTimePicker v-model="form.delivery_data.occurred_at" format="long" />
                    <ClinicalFieldHints :hints="dateHints" label="Repères sur la date de l’accouchement" />
                </FormField>
                <FormField label="Voie d’accouchement" :error="form.errors['delivery_data.mode']">
                    <Select v-model="form.delivery_data.mode" class="h-10 w-full min-w-0" :options="MODES" />
                </FormField>
            </div>
            <FormField as="div" label="Placenta / délivrance" :error="form.errors['delivery_data.placenta_status']">
                <Textarea v-model="form.delivery_data.placenta_status" :rows="3" />
            </FormField>
            <FormField as="div" label="Complications" :error="form.errors['delivery_data.complications']">
                <Textarea v-model="form.delivery_data.complications" :rows="4" />
            </FormField>
        </fieldset>

        <div v-if="canRequestCesarean" class="rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20">
            <div class="flex items-start gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300"><Scissors class="h-4.5 w-4.5" /></span>
                <div>
                    <h3 class="text-sm font-bold text-foreground">Décision de césarienne</h3>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Crée une demande Chirurgie sur ce même passage. Aucune intervention n’est créée dans Maternité — elle reste sous le contrôle du bloc.</p>
                </div>
            </div>
            <div class="mt-4 grid gap-3 lg:grid-cols-[180px_minmax(0,1fr)_auto]">
                <Select v-model="cesareanForm.type" class="h-10 w-full min-w-0" :options="CESAREAN_TYPES" aria-label="Type de césarienne" />
                <Input v-model="cesareanForm.indication" placeholder="Indication clinique obligatoire" />
                <Button type="button" variant="warning" :disabled="! cesareanForm.indication || cesareanForm.processing" @click="$emit('request-cesarean')">
                    <Scissors class="h-4 w-4" />Transmettre à Chirurgie
                </Button>
            </div>
            <FormError v-if="firstError(cesareanForm.errors)">{{ firstError(cesareanForm.errors) }}</FormError>
        </div>
    </div>
</template>
