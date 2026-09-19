<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@/Components/Shadcn/Button.vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormError from '@/Components/UI/FormError.vue';
import { CalendarDays, Check, CircleAlert, CircleCheck, CircleX, Plus, Share2, Square, SquareCheckBig, UserRound } from 'lucide-vue-next';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';

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
    // ADR-094 — faux uniquement pour un passage venu seulement pour un ECG,
    // une échographie ou une analyse. Le serveur décide ; ce drapeau ne fait
    // que reproduire sa réponse à l'écran.
    requiresDiagnosis: { type: Boolean, default: true },
    disabled: { type: Boolean, default: false },
    cancellable: { type: Boolean, default: true },
});

const emit = defineEmits(['submit', 'cancel']);

const ICONS = {
    NORMAL: CircleCheck,
    TRANSFER: Share2,
    AT_PATIENT_REQUEST: UserRound,
    MEDICAL_DECISION_REFUSAL: CircleX,
    DECEASED: CircleAlert,
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

/**
 * Ce que le serveur exigera, dit avant de cliquer.
 *
 * Le diagnostic n'en fait partie que lorsqu'il est réellement dû : un
 * passage venu seulement pour un ECG, une échographie ou une analyse n'en
 * doit aucun (ADR-094). Ce garde-fou reproduit la règle serveur — il ne la
 * décide pas, et `StoreMedicalDischargeRequest` la revérifie toujours.
 */
/**
 * ADR-107 — une sortie pour décès ne demande ni état du patient, ni
 * traitement de sortie, ni conseils, ni rendez-vous : le type de sortie dit
 * déjà l'état, et les trois autres n'ont pas de destinataire. Le serveur
 * refuse ces champs et pose l'état lui-même ; l'écran ne fait que refléter
 * sa règle.
 */
const isDeceased = computed(() => props.form.type === 'DECEASED');

const canSubmit = computed(() => !props.disabled
    && !props.form.processing
    && (!props.requiresDiagnosis || selectedDiagnoses.value.size > 0)
    && (isDeceased.value || Boolean(props.form.patient_condition)));

/**
 * Prononcer une sortie médicale est un acte définitif : le passage change de
 * statut médical (ADR-035), et un décès ouvre en plus son acte de
 * constatation (ADR-107). Même format que la demande d'examen et
 * l'ordonnance (ADR-106) : on relit ce qui est prononcé, et on signe.
 */
const confirming = ref(false);
const openConfirmation = () => { confirming.value = true; };
const closeConfirmation = () => { confirming.value = false; };
const confirmDischarge = () => {
    confirming.value = false;
    emit('submit');
};

/** Ce que la fenêtre relit : ce qui part réellement, jamais un résumé deviné. */
const confirmationLines = computed(() => [
    ['Type de sortie', props.types.find((type) => type.value === props.form.type)?.label],
    ['Décision datée du', props.form.discharged_at ? formatHuman(props.form.discharged_at) : null],
    ['Établissement destinataire', props.form.type === 'TRANSFER' ? props.form.transfer_destination : null],
    ['État du patient', isDeceased.value ? 'Décédé' : props.form.patient_condition],
    ['Diagnostic final', props.form.final_diagnosis],
    ['Date et heure du décès', isDeceased.value && props.form.death_occurred_at ? formatHuman(props.form.death_occurred_at) : null],
    ['Lieu du décès', isDeceased.value ? props.form.death_place : null],
    ['Causes constatées', isDeceased.value ? props.form.death_causes : null],
].filter(([, value]) => String(value ?? '').trim() !== '')
    .map(([label, value]) => ({ label, value: String(value).trim() })));

const chipClass = (active) => [
    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60',
    active
        ? 'border-primary bg-primary/10 text-primary'
        : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:bg-accent hover:text-foreground',
];
const rowClass = (active) => [
    'flex w-full items-start gap-2.5 rounded-md border px-3 py-2 text-start text-xs transition-colors disabled:cursor-not-allowed',
    active
        ? 'border-primary/50 bg-primary/5 text-foreground'
        : 'border-border text-muted-foreground line-through decoration-muted-foreground/60 hover:bg-accent',
];
// `Select` attend `{ value, label }`. « Autre établissement » reste une
// option à part entière : c'est elle qui ouvre la saisie libre, et la retirer
// empêcherait de référer vers un établissement hors du référentiel.
const destinationOptions = computed(() => [
    ...props.siteOptions.map((site) => ({ value: site.destination, label: site.destination })),
    { value: 'OTHER', label: 'Autre établissement' },
]);

const labelClass = 'mb-1.5 flex h-6 items-center text-sm font-medium text-foreground';
</script>

<template>
    <form class="space-y-5" @submit.prevent="canSubmit && openConfirmation()">
        <div class="flex items-start gap-2.5 rounded-md border border-amber-200 bg-amber-50/60 px-3 py-2.5 text-[11px] text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
            <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
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
                                     : 'border-primary bg-primary/10 text-primary')
                                 : 'border-border text-muted-foreground hover:border-primary/40 hover:bg-accent hover:text-foreground']"
                >
                    <input v-model="form.type" type="radio" name="discharge_type" :value="option.value" :disabled="disabled" class="sr-only" />
                    <component :is="ICONS[option.value] ?? CircleCheck" class="h-4 w-4 shrink-0" />
                    <span class="truncate whitespace-nowrap font-semibold leading-tight">{{ SHORT_LABELS[option.value] ?? option.label }}</span>
                </label>
            </div>
            <FormError class="mt-1.5" :message="form.errors.type" />
        </div>

        <!-- Date : maintenant par défaut -->
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <CalendarDays class="h-4 w-4 text-muted-foreground" />
            <span class="text-muted-foreground">Décision datée du</span>
            <strong class="text-foreground">{{ formatHuman(form.discharged_at) }}</strong>
            <button v-if="!editingDate" type="button" class="font-semibold text-primary hover:underline disabled:opacity-50" :disabled="disabled" @click="editingDate = true">Changer</button>
            <IconInput v-else id="discharged_at" v-model="form.discharged_at" class="w-56" :icon="CalendarDays" type="datetime-local" :disabled="disabled" />
            <FormError class="w-full" :message="form.errors.discharged_at" />
        </div>

        <!-- Transfert -->
        <FormField
            v-if="form.type === 'TRANSFER'"
            label="Établissement / service destinataire"
            required
            class="max-w-md"
            :error="form.errors.transfer_destination"
        >
            <Select
                id="transfer_destination"
                v-model="destinationChoice"
                :options="destinationOptions"
                :disabled="disabled"
                placeholder="Choisir…"
            />
            <Input v-if="destinationChoice === 'OTHER'" v-model="destinationOther" class="mt-2" :disabled="disabled" placeholder="Nom de l’établissement" />
        </FormField>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Diagnostic final : cochés d'office -->
            <div>
                <p :class="labelClass">Diagnostic final <span v-if="requiresDiagnosis" class="text-red-500">*</span><span v-else class="font-normal text-muted-foreground"> · facultatif</span></p>
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
                        <component :is="selectedDiagnoses.has(diagnosis.id) ? SquareCheckBig : Square" class="mt-px h-4 w-4 shrink-0" />
                        <span class="font-semibold">{{ diagnosis.description }}</span>
                    </button>
                </div>
                <p v-else-if="requiresDiagnosis" class="rounded-md border border-dashed border-amber-300 bg-amber-50/50 px-3 py-2 text-[11px] text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                    Aucun diagnostic posé : ajoutez-le dans « 1 · Diagnostic » ci-dessus, il apparaîtra ici déjà coché.
                </p>
                <!-- Passage paraclinique seul : le compte rendu de l'examen
                     tient lieu de conclusion, et le résultat n'est souvent
                     pas encore revenu. Rien à réclamer (ADR-094). -->
                <p v-else class="rounded-md border border-dashed border-border bg-muted/30 px-3 py-2 text-[11px] text-muted-foreground">
                    Passage venu uniquement pour un examen : le diagnostic n’est pas exigé. Vous pouvez en poser un dans « 1 · Diagnostic » si vous le souhaitez.
                </p>
                <FormError :message="form.errors.final_diagnosis" />
            </div>

            <!-- État du patient. Pour un décès, ce n'est pas un choix :
                 le type de sortie *est* la réponse, et aucune des cinq
                 options ne conviendrait (ADR-107). -->
            <div v-if="!isDeceased">
                <ClinicalSegmentedChoice
                    v-model="form.patient_condition"
                    name="discharge_patient_condition"
                    label="État du patient à la sortie *"
                    :options="CONDITIONS"
                    :disabled="disabled"
                />
                <FormError :message="form.errors.patient_condition" />
            </div>
            <div v-else>
                <p :class="labelClass">État du patient à la sortie</p>
                <p class="text-xs text-muted-foreground">Décédé — porté au dossier par le type de sortie.</p>
            </div>
        </div>

        <!-- Décès : l'heure, le lieu et les causes s'établissent dans le
             registre des décès, qui les exige pour l'acte (ADR-107) — pas
             ici, où ils seraient saisis deux fois. -->
        <p v-if="form.type === 'DECEASED'" class="flex items-start gap-2 rounded-md border border-border bg-muted/40 p-3 text-xs leading-5 text-muted-foreground">
            <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <span>L’heure, le lieu et les causes du décès se renseignent dans le <strong class="font-semibold text-foreground">registre des décès</strong>, avec l’acte de constatation. Le passage y apparaît dès la confirmation.</span>
        </p>

        <!-- Traitement de sortie, conseils de surveillance et rendez-vous de
             contrôle s'adressent à quelqu'un qui rentre chez lui. Ce ne sont
             pas des cases à laisser vides : ce sont des instructions qui
             n'ont pas de destinataire (ADR-107). -->
        <div v-if="!isDeceased" class="grid gap-5 lg:grid-cols-2">
            <!-- Traitement de sortie : l'ordonnance, cochée d'office -->
            <div>
                <p :class="labelClass">Traitement de sortie <span class="font-normal text-muted-foreground">· repris de l’ordonnance</span></p>
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
                        <component :is="selectedLines.has(line) ? SquareCheckBig : Square" class="mt-px h-4 w-4 shrink-0" />
                        <span>{{ line }}</span>
                    </button>
                </div>
                <p v-else class="text-[11px] text-muted-foreground">Aucun médicament prescrit pour ce passage.</p>
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
                        <Check v-if="selectedAdvice.has(advice)" class="h-4 w-4" />{{ advice }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Contrôle -->
        <div v-if="!isDeceased">
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
                <span v-if="form.follow_up_at" class="ms-1 text-[11px] text-muted-foreground">→ {{ formatHuman(form.follow_up_at) }}</span>
            </div>
            <FormError :message="form.errors.follow_up_at" />
        </div>

        <!-- Précision : facultative -->
        <div>
            <button v-if="!showPrecision" type="button" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-muted-foreground transition-colors hover:text-primary" :disabled="disabled" @click="showPrecision = true">
                <Plus class="h-4 w-4" />Ajouter une précision (facultatif)
            </button>
            <Input v-else id="discharge_observations" v-model="form.observations" :disabled="disabled" placeholder="Précision éventuelle" />
        </div>

        <FormError :message="form.errors.medical_discharge" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-border pt-3">
            <p v-if="!canSubmit && !disabled" class="me-auto text-[11px] text-muted-foreground">
                {{ requiresDiagnosis && !selectedDiagnoses.size ? 'Cochez au moins un diagnostic.' : 'Choisissez l’état du patient.' }}
            </p>
            <Button v-if="cancellable" type="button" size="sm" variant="white-outline" @click="emit('cancel')">Annuler</Button>
            <Button type="submit" size="sm" :disabled="!canSubmit">
                <Check class="h-4 w-4" />Confirmer la sortie médicale
            </Button>
        </div>

        <!-- ADR-106/107 — prononcer une sortie médicale change le statut
             médical du passage (ADR-035) et, pour un décès, ouvre son acte
             de constatation. Non fermable au clic extérieur : c'est un acte
             définitif, pas une fenêtre qu'on parcourt. -->
        <Dialog
            :open="confirming"
            :title="isDeceased ? 'Confirmer le décès' : 'Confirmer la sortie médicale'"
            :description="isDeceased
                ? 'Le passage sera porté au registre des décès, où son acte de constatation reste à établir.'
                : 'La Réception / Caisse conserve la responsabilité de la sortie administrative.'"
            :dismissible="false"
            close-label="Revenir au formulaire"
            @update:open="closeConfirmation"
        >
            <template #icon>
                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-full', isDeceased ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-primary']">
                    <component :is="isDeceased ? CircleAlert : CircleCheck" class="h-5 w-5" />
                </span>
            </template>

            <dl class="divide-y divide-border overflow-hidden rounded-lg border border-border">
                <div v-for="line in confirmationLines" :key="line.label" class="px-3 py-2">
                    <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{{ line.label }}</dt>
                    <dd class="mt-0.5 whitespace-pre-line text-sm text-foreground">{{ line.value }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs leading-5 text-muted-foreground">
                Vous prononcez sous votre responsabilité, en tant que
                <strong class="font-semibold text-foreground">{{ $page.props.auth.user.name }}</strong>.
                Une sortie médicale ne se prononce qu’une fois pour ce passage.
            </p>

            <template #footer>
                <Button type="button" variant="outline" :disabled="form.processing" @click="closeConfirmation">
                    Revenir au formulaire
                </Button>
                <Button type="button" :disabled="form.processing" @click="confirmDischarge">
                    <Check class="h-4 w-4" />{{ isDeceased ? 'Je confirme le décès' : 'Je confirme la sortie' }}
                </Button>
            </template>
        </Dialog>
    </form>
</template>
