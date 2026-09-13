<script setup>
import { ref, watch } from 'vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';

/**
 * La sortie médicale.
 *
 * Extraite de l'écran parce que la sortie peut désormais être décidée à
 * deux endroits — dès l'examen clinique, ou à la clôture — et qu'un
 * formulaire de cette taille recopié deux fois finit par diverger.
 *
 * Elle reste l'acte médical qu'elle a toujours été : elle ne clôt pas le
 * passage administratif, et n'encaisse rien (ADR-035).
 */
const props = defineProps({
    /** L'objet `useForm` du parent : la saisie reste liée à son brouillon. */
    form: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    siteOptions: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    cancellable: { type: Boolean, default: true },
});

const emit = defineEmits(['submit', 'cancel']);

const destinationChoice = ref('');
const destinationOther = ref('');

/**
 * Un site de la clinique se choisit dans la liste ; ailleurs, le nom est
 * saisi. Dans les deux cas c'est la même colonne qui est enregistrée, pour
 * qu'aucune lecture ultérieure n'ait à deviner d'où vient la valeur.
 */
watch([destinationChoice, destinationOther], () => {
    if (props.form.type !== 'TRANSFER') return;

    props.form.transfer_destination = destinationChoice.value === 'OTHER'
        ? destinationOther.value
        : destinationChoice.value;
});

const ICONS = {
    NORMAL: 'check-circle',
    TRANSFER: 'share',
    AT_PATIENT_REQUEST: 'user',
    MEDICAL_DECISION_REFUSAL: 'cross-circle',
    DECEASED: 'alert-circle',
};

const SHORT_LABELS = {
    NORMAL: 'Sortie normale',
    TRANSFER: 'Transfert',
    AT_PATIENT_REQUEST: 'Demande du patient',
    MEDICAL_DECISION_REFUSAL: 'Refus médical',
    DECEASED: 'Décès',
};

const textareaClass = 'block w-full resize-y rounded border border-gray-200 bg-white px-3 py-2 text-sm leading-5 text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const selectClass = 'block h-9 w-full appearance-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <form class="space-y-4" @submit.prevent="emit('submit')">
        <div class="flex items-start gap-2.5 rounded-md border border-amber-200 bg-amber-50/60 px-3 py-2.5 text-[11px] text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
            <Icon class="mt-0.5 shrink-0 text-sm" name="alert-circle" />
            <p><strong>Acte médical définitif.</strong> La Réception / Caisse conserve la responsabilité de la sortie administrative.</p>
        </div>

        <div>
            <label class="mb-1.5 block text-[11px] font-bold text-slate-700 dark:text-white">Type de sortie <span class="text-red-500">*</span></label>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="option in types"
                    :key="option.value"
                    :title="option.label"
                    :class="['inline-flex w-auto shrink-0 cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-xs transition-colors',
                             form.type === option.value
                                 ? (option.value === 'DECEASED'
                                     ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950/20 dark:text-red-300'
                                     : 'border-primary-500 bg-primary-50/60 text-primary-700 dark:border-primary-700 dark:bg-primary-950/20 dark:text-primary-300')
                                 : 'border-gray-200 text-slate-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:bg-gray-1000']"
                >
                    <input v-model="form.type" type="radio" name="discharge_type" :value="option.value" :disabled="disabled" class="sr-only" />
                    <Icon class="shrink-0 text-base" :name="ICONS[option.value] ?? 'check-circle'" />
                    <span class="truncate whitespace-nowrap font-semibold leading-tight">{{ SHORT_LABELS[option.value] ?? option.label }}</span>
                </label>
            </div>
            <FormError class="mt-1.5" :message="form.errors.type" />
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div :class="form.type === 'TRANSFER' ? '' : 'md:col-span-2 md:max-w-xs'">
                <label for="discharged_at" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Date et heure <span class="text-red-500">*</span></label>
                <IconInput id="discharged_at" v-model="form.discharged_at" icon="calendar" type="datetime-local" :disabled="disabled" />
                <FormError :message="form.errors.discharged_at" />
            </div>
            <div v-if="form.type === 'TRANSFER'">
                <label for="transfer_destination" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Établissement / service destinataire <span class="text-red-500">*</span></label>
                <span class="relative block">
                    <select id="transfer_destination" v-model="destinationChoice" :disabled="disabled" :class="selectClass">
                        <option value="">Choisir…</option>
                        <option v-for="site in siteOptions" :key="site.code" :value="site.destination">{{ site.destination }}</option>
                        <option value="OTHER">Autre</option>
                    </select>
                    <Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" />
                </span>
                <Input v-if="destinationChoice === 'OTHER'" v-model="destinationOther" class="mt-2" :disabled="disabled" placeholder="Précisez l’établissement ou le service" />
                <FormError :message="form.errors.transfer_destination" />
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label for="final_diagnosis" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Diagnostic final <span class="text-red-500">*</span></label>
                <textarea id="final_diagnosis" v-model="form.final_diagnosis" :disabled="disabled" rows="3" :class="textareaClass" />
                <FormError :message="form.errors.final_diagnosis" />
            </div>
            <div>
                <label for="patient_condition" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">État du patient <span class="text-red-500">*</span></label>
                <textarea id="patient_condition" v-model="form.patient_condition" :disabled="disabled" rows="3" :class="textareaClass" placeholder="Stable, amélioré, état clinique au départ…" />
                <FormError :message="form.errors.patient_condition" />
            </div>
        </div>

        <div v-if="form.type === 'DECEASED'" class="grid gap-3 rounded-md border border-red-100 bg-red-50/30 p-4 dark:border-red-950 dark:bg-red-950/10 md:grid-cols-2">
            <div>
                <label for="death_occurred_at" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Date et heure du décès <span class="text-red-500">*</span></label>
                <IconInput id="death_occurred_at" v-model="form.death_occurred_at" icon="calendar" type="datetime-local" :disabled="disabled" />
                <FormError :message="form.errors.death_occurred_at" />
            </div>
            <div>
                <label for="death_place" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Lieu du décès <span class="text-red-500">*</span></label>
                <Input id="death_place" v-model="form.death_place" :disabled="disabled" />
                <FormError :message="form.errors.death_place" />
            </div>
            <div class="md:col-span-2">
                <label for="death_causes" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Causes constatées <span class="text-red-500">*</span></label>
                <textarea id="death_causes" v-model="form.death_causes" :disabled="disabled" rows="3" :class="textareaClass" />
                <FormError :message="form.errors.death_causes" />
            </div>
        </div>

        <!-- Repris de l'ordonnance active : à corriger, jamais à ressaisir. -->
        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label for="discharge_prescription" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Traitement de sortie <span class="font-normal text-slate-400">· repris de l’ordonnance</span></label>
                <textarea id="discharge_prescription" v-model="form.discharge_prescription" :disabled="disabled" rows="4" :class="textareaClass" />
            </div>
            <div>
                <label for="recommendations" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Conseils et surveillance</label>
                <textarea id="recommendations" v-model="form.recommendations" :disabled="disabled" rows="4" :class="textareaClass" placeholder="Conseils de sortie, signes devant amener à reconsulter…" />
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label for="follow_up_at" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Contrôle éventuel</label>
                <IconInput id="follow_up_at" v-model="form.follow_up_at" icon="calendar" type="datetime-local" :disabled="disabled" />
                <FormError :message="form.errors.follow_up_at" />
            </div>
            <div>
                <label for="discharge_observations" class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white">Observations</label>
                <Input id="discharge_observations" v-model="form.observations" :disabled="disabled" />
            </div>
        </div>

        <FormError :message="form.errors.medical_discharge" />

        <div class="flex justify-end gap-2 border-t border-gray-200 pt-3 dark:border-gray-900">
            <Button v-if="cancellable" type="button" size="sm" variant="white-outline" @click="emit('cancel')">Annuler</Button>
            <Button type="submit" size="sm" :disabled="disabled || form.processing">
                <Icon class="me-1.5 text-sm" name="check" />Confirmer la sortie médicale
            </Button>
        </div>
    </form>
</template>
