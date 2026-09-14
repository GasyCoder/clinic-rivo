<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@/Components/UI/Button.vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';

/**
 * La sortie médicale — cochée, pas rédigée.
 *
 * Tout ce qu'elle demande est déjà dans le dossier : les diagnostics posés,
 * les lignes de l'ordonnance. Le médecin sélectionne ce qui s'applique et
 * choisit l'état du patient, le contrôle et les conseils dans des listes ;
 * le texte enregistré est composé à partir de ses choix. Rien n'est ressaisi,
 * et le serveur reçoit exactement les mêmes champs qu'avant (ADR-035).
 *
 * Seuls restent en saisie les cas qui l'exigent vraiment : un transfert vers
 * un établissement hors liste, et les causes d'un décès, qui alimentent un
 * certificat.
 */
const props = defineProps({
    /** L'objet `useForm` du parent : la saisie reste liée à son brouillon. */
    form: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    siteOptions: { type: Array, default: () => [] },
    /** Diagnostics actifs de la consultation : [{ id, description }]. */
    diagnoses: { type: Array, default: () => [] },
    /** Lignes de l'ordonnance active, déjà composées avec leur posologie. */
    prescriptionLines: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    cancellable: { type: Boolean, default: true },
});

const emit = defineEmits(['submit', 'cancel']);

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

/** Aucune valeur par défaut : l'état au départ est un constat du médecin. */
const CONDITIONS = [
    { value: 'Guéri', label: 'Guéri', tone: 'positive' },
    { value: 'Amélioré', label: 'Amélioré', tone: 'positive' },
    { value: 'Stable', label: 'Stable', tone: 'neutral' },
    { value: 'Non amélioré', label: 'Non amélioré', tone: 'warning' },
    { value: 'Aggravé', label: 'Aggravé', tone: 'warning' },
];

const ADVICE = [
    'Suivre le traitement prescrit jusqu’au bout',
    'Reconsulter en cas de fièvre, douleur ou aggravation',
    'Repos',
    'Bonne hydratation',
    'Revenir avec les résultats des examens',
];

const FOLLOW_UPS = [
    { days: null, label: 'Aucun' },
    { days: 3, label: 'Dans 3 jours' },
    { days: 7, label: 'Dans 7 jours' },
    { days: 15, label: 'Dans 15 jours' },
    { days: 30, label: 'Dans 1 mois' },
];

const lines = (value) => String(value ?? '').split('\n').map((line) => line.trim()).filter(Boolean);

/* ── Diagnostic final : les diagnostics posés, cochés d'office ─────────── */

const selectedDiagnoses = ref(new Set(props.diagnoses.map((diagnosis) => diagnosis.id)));

// Un diagnostic posé plus haut sur la même page arrive déjà coché.
watch(() => props.diagnoses.map((diagnosis) => diagnosis.id), (ids, previous = []) => {
    const next = new Set([...selectedDiagnoses.value].filter((id) => ids.includes(id)));
    ids.filter((id) => !previous.includes(id)).forEach((id) => next.add(id));
    selectedDiagnoses.value = next;
});

watch(selectedDiagnoses, (selected) => {
    props.form.final_diagnosis = props.diagnoses
        .filter((diagnosis) => selected.has(diagnosis.id))
        .map((diagnosis) => diagnosis.description)
        .join('\n');
}, { immediate: true });

const toggleDiagnosis = (id) => {
    const next = new Set(selectedDiagnoses.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selectedDiagnoses.value = next;
};

/* ── Traitement de sortie : les lignes de l'ordonnance, cochées d'office ── */

const selectedLines = ref(new Set(props.prescriptionLines));

watch(() => [...props.prescriptionLines], (current, previous = []) => {
    const next = new Set([...selectedLines.value].filter((line) => current.includes(line)));
    current.filter((line) => !previous.includes(line)).forEach((line) => next.add(line));
    selectedLines.value = next;
});

watch(selectedLines, (selected) => {
    props.form.discharge_prescription = props.prescriptionLines.filter((line) => selected.has(line)).join('\n');
}, { immediate: true });

const toggleLine = (line) => {
    const next = new Set(selectedLines.value);
    next.has(line) ? next.delete(line) : next.add(line);
    selectedLines.value = next;
};

/* ── Conseils : pastilles, restaurées depuis un brouillon ─────────────── */

const selectedAdvice = ref(new Set(lines(props.form.recommendations).filter((line) => ADVICE.includes(line))));

watch(selectedAdvice, (selected) => {
    props.form.recommendations = ADVICE.filter((advice) => selected.has(advice)).join('\n');
});

const toggleAdvice = (advice) => {
    const next = new Set(selectedAdvice.value);
    next.has(advice) ? next.delete(advice) : next.add(advice);
    selectedAdvice.value = next;
};

/* ── Date de la décision : maintenant, modifiable sur demande ──────────── */

const editingDate = ref(false);

const pad = (value) => String(value).padStart(2, '0');
const toInput = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
const formatHuman = (value) => {
    const date = value ? new Date(value) : null;

    return date && !Number.isNaN(date.getTime())
        ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
        : '—';
};

/* ── Contrôle : un délai choisi, la date calculée ──────────────────────── */

const followUpDays = ref(null);

watch([followUpDays, () => props.form.discharged_at], ([days, dischargedAt]) => {
    if (days === null) {
        props.form.follow_up_at = '';

        return;
    }

    const base = dischargedAt ? new Date(dischargedAt) : new Date();
    base.setDate(base.getDate() + days);
    props.form.follow_up_at = toInput(base);
});

/* ── Transfert : un site de la clinique ou un établissement « Autre » ──── */

const destinationChoice = ref('');
const destinationOther = ref('');

watch([destinationChoice, destinationOther], () => {
    if (props.form.type !== 'TRANSFER') return;

    props.form.transfer_destination = destinationChoice.value === 'OTHER'
        ? destinationOther.value
        : destinationChoice.value;
});

/* ── Précision libre : facultative, repliée ────────────────────────────── */

const showPrecision = ref(Boolean(props.form.observations));

const canSubmit = computed(() => !props.disabled
    && !props.form.processing
    && selectedDiagnoses.value.size > 0
    && Boolean(props.form.patient_condition));

const chipClass = (active) => [
    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60',
    active
        ? 'border-primary-400 bg-primary-50 text-primary-700 dark:border-primary-700 dark:bg-primary-950/30 dark:text-primary-300'
        : 'border-gray-200 bg-white text-slate-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300',
];
const rowClass = (active) => [
    'flex w-full items-start gap-2.5 rounded-md border px-3 py-2 text-start text-xs transition-colors disabled:cursor-not-allowed',
    active
        ? 'border-primary-300 bg-primary-50/50 text-slate-700 dark:border-primary-800 dark:bg-primary-950/20 dark:text-white'
        : 'border-gray-200 text-slate-400 line-through decoration-slate-300 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-1000',
];
const selectClass = 'block h-9 w-full appearance-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const textareaClass = 'block w-full resize-y rounded border border-gray-200 bg-white px-3 py-2 text-sm leading-5 text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const labelClass = 'mb-1.5 block text-[11px] font-bold text-slate-700 dark:text-white';
</script>

<template>
    <form class="space-y-5" @submit.prevent="canSubmit && emit('submit')">
        <div class="flex items-start gap-2.5 rounded-md border border-amber-200 bg-amber-50/60 px-3 py-2.5 text-[11px] text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
            <Icon class="mt-0.5 shrink-0 text-sm" name="alert-circle" />
            <p><strong>Acte médical définitif.</strong> La Réception / Caisse conserve la responsabilité de la sortie administrative.</p>
        </div>

        <!-- Type de sortie -->
        <div>
            <p :class="labelClass">Type de sortie <span class="text-red-500">*</span></p>
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

        <!-- Date : maintenant par défaut -->
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <Icon class="text-sm text-slate-400" name="calendar" />
            <span class="text-slate-500 dark:text-slate-400">Décision datée du</span>
            <strong class="text-slate-700 dark:text-white">{{ formatHuman(form.discharged_at) }}</strong>
            <button v-if="!editingDate" type="button" class="font-semibold text-primary-700 hover:underline disabled:opacity-50 dark:text-primary-300" :disabled="disabled" @click="editingDate = true">Changer</button>
            <IconInput v-else id="discharged_at" v-model="form.discharged_at" class="w-56" icon="calendar" type="datetime-local" :disabled="disabled" />
            <FormError class="w-full" :message="form.errors.discharged_at" />
        </div>

        <!-- Transfert -->
        <div v-if="form.type === 'TRANSFER'" class="max-w-md">
            <label for="transfer_destination" :class="labelClass">Établissement / service destinataire <span class="text-red-500">*</span></label>
            <span class="relative block">
                <select id="transfer_destination" v-model="destinationChoice" :disabled="disabled" :class="selectClass">
                    <option value="">Choisir…</option>
                    <option v-for="site in siteOptions" :key="site.code" :value="site.destination">{{ site.destination }}</option>
                    <option value="OTHER">Autre établissement</option>
                </select>
                <Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" />
            </span>
            <Input v-if="destinationChoice === 'OTHER'" v-model="destinationOther" class="mt-2" :disabled="disabled" placeholder="Nom de l’établissement" />
            <FormError :message="form.errors.transfer_destination" />
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Diagnostic final : cochés d'office -->
            <div>
                <p :class="labelClass">Diagnostic final <span class="text-red-500">*</span></p>
                <div v-if="diagnoses.length" class="space-y-1.5">
                    <button
                        v-for="diagnosis in diagnoses"
                        :key="diagnosis.id"
                        type="button"
                        :disabled="disabled"
                        :aria-pressed="selectedDiagnoses.has(diagnosis.id)"
                        :class="rowClass(selectedDiagnoses.has(diagnosis.id))"
                        @click="toggleDiagnosis(diagnosis.id)"
                    >
                        <Icon class="mt-px shrink-0 text-sm" :name="selectedDiagnoses.has(diagnosis.id) ? 'checkbox-checked' : 'checkbox'" />
                        <span class="font-semibold">{{ diagnosis.description }}</span>
                    </button>
                </div>
                <p v-else class="rounded-md border border-dashed border-amber-300 bg-amber-50/50 px-3 py-2 text-[11px] text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                    Aucun diagnostic posé : ajoutez-le dans « 1 · Diagnostic » ci-dessus, il apparaîtra ici déjà coché.
                </p>
                <FormError :message="form.errors.final_diagnosis" />
            </div>

            <!-- État du patient -->
            <div>
                <ClinicalSegmentedChoice
                    v-model="form.patient_condition"
                    name="discharge_patient_condition"
                    label="État du patient à la sortie *"
                    :options="CONDITIONS"
                    :disabled="disabled"
                />
                <FormError :message="form.errors.patient_condition" />
            </div>
        </div>

        <!-- Décès : le certificat exige le détail -->
        <div v-if="form.type === 'DECEASED'" class="grid gap-3 rounded-md border border-red-100 bg-red-50/30 p-4 dark:border-red-950 dark:bg-red-950/10 md:grid-cols-2">
            <div>
                <label for="death_occurred_at" :class="labelClass">Date et heure du décès <span class="text-red-500">*</span></label>
                <IconInput id="death_occurred_at" v-model="form.death_occurred_at" icon="calendar" type="datetime-local" :disabled="disabled" />
                <FormError :message="form.errors.death_occurred_at" />
            </div>
            <div>
                <label for="death_place" :class="labelClass">Lieu du décès <span class="text-red-500">*</span></label>
                <Input id="death_place" v-model="form.death_place" :disabled="disabled" />
                <FormError :message="form.errors.death_place" />
            </div>
            <div class="md:col-span-2">
                <label for="death_causes" :class="labelClass">Causes constatées <span class="text-red-500">*</span></label>
                <textarea id="death_causes" v-model="form.death_causes" :disabled="disabled" rows="3" :class="textareaClass" />
                <FormError :message="form.errors.death_causes" />
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Traitement de sortie : l'ordonnance, cochée d'office -->
            <div>
                <p :class="labelClass">Traitement de sortie <span class="font-normal text-slate-400">· repris de l’ordonnance</span></p>
                <div v-if="prescriptionLines.length" class="space-y-1.5">
                    <button
                        v-for="line in prescriptionLines"
                        :key="line"
                        type="button"
                        :disabled="disabled"
                        :aria-pressed="selectedLines.has(line)"
                        :class="rowClass(selectedLines.has(line))"
                        @click="toggleLine(line)"
                    >
                        <Icon class="mt-px shrink-0 text-sm" :name="selectedLines.has(line) ? 'checkbox-checked' : 'checkbox'" />
                        <span>{{ line }}</span>
                    </button>
                </div>
                <p v-else class="text-[11px] text-slate-400">Aucun médicament prescrit pour ce passage.</p>
            </div>

            <!-- Conseils -->
            <div>
                <p :class="labelClass">Conseils et surveillance</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="advice in ADVICE"
                        :key="advice"
                        type="button"
                        :disabled="disabled"
                        :aria-pressed="selectedAdvice.has(advice)"
                        :class="chipClass(selectedAdvice.has(advice))"
                        @click="toggleAdvice(advice)"
                    >
                        <Icon v-if="selectedAdvice.has(advice)" class="text-sm" name="check" />{{ advice }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Contrôle -->
        <div>
            <p :class="labelClass">Contrôle</p>
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="option in FOLLOW_UPS"
                    :key="option.label"
                    type="button"
                    :disabled="disabled"
                    :aria-pressed="followUpDays === option.days"
                    :class="chipClass(followUpDays === option.days)"
                    @click="followUpDays = option.days"
                >
                    {{ option.label }}
                </button>
                <span v-if="form.follow_up_at" class="ms-1 text-[11px] text-slate-500 dark:text-slate-400">→ {{ formatHuman(form.follow_up_at) }}</span>
            </div>
            <FormError :message="form.errors.follow_up_at" />
        </div>

        <!-- Précision : facultative -->
        <div>
            <button v-if="!showPrecision" type="button" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-500 hover:text-primary-700 dark:text-slate-400" :disabled="disabled" @click="showPrecision = true">
                <Icon class="text-sm" name="plus" />Ajouter une précision (facultatif)
            </button>
            <Input v-else id="discharge_observations" v-model="form.observations" :disabled="disabled" placeholder="Précision éventuelle" />
        </div>

        <FormError :message="form.errors.medical_discharge" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-200 pt-3 dark:border-gray-900">
            <p v-if="!canSubmit && !disabled" class="me-auto text-[11px] text-slate-400">
                {{ !diagnoses.length || !selectedDiagnoses.size ? 'Cochez au moins un diagnostic.' : 'Choisissez l’état du patient.' }}
            </p>
            <Button v-if="cancellable" type="button" size="sm" variant="white-outline" @click="emit('cancel')">Annuler</Button>
            <Button type="submit" size="sm" :disabled="!canSubmit">
                <Icon class="me-1.5 text-sm" name="check" />Confirmer la sortie médicale
            </Button>
        </div>
    </form>
</template>
