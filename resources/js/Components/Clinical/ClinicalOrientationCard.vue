<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import { Building2, ChevronDown, ChevronUp, CircleCheck, CirclePlus, Clock, HeartPulse, History, Pencil, Printer, Send, Share2, UsersRound } from 'lucide-vue-next';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { formatDateTime } from '@/utilities/date';

/**
 * « La conduite à tenir est-elle déjà déterminée ? »
 *
 * The whole point of ADR-084 lives here: the doctor answers where the
 * patient is going at the moment they know it — during the interview, after
 * the examination, or once the results are back — and the matching request
 * form opens immediately below. No "Décision" step to reach before being
 * allowed to state a decision already taken, and no destination chosen
 * twice.
 *
 * Progressive disclosure, deliberately: only the selected orientation's form
 * is mounted. Six forms stacked on one screen would be the same wall of
 * fields this refactor is removing.
 */
const props = defineProps({
    orientationUuid: { type: String, required: true },
    /**
     * La poignée `useFormDraft` de la page. Les demandes saisies ici sont
     * longues ; sans elle, une actualisation les efface (ADR-073).
     */
    draft: { type: Object, default: null },
    /** The server's `consultation_orientation` payload. */
    state: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    surgeryCatalog: { type: Array, default: () => [] },
    transferDestinations: { type: Array, default: () => [] },
    isEmergency: { type: Boolean, default: false },
    /** Where to come back to once the request is transmitted. */
    returnStep: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    /** Replie la carte derrière son en-tête, là où elle n'est pas le sujet de l'écran. */
    collapsible: { type: Boolean, default: false },
});

const emit = defineEmits(['discharge-selected']);

const active = computed(() => props.state?.active ?? null);
const prefill = computed(() => props.state?.prefill ?? {});
const canSelect = computed(() => props.state?.can_select && !props.disabled);
const history = computed(() => props.state?.history ?? []);
const showHistory = ref(false);
const expanded = ref(!props.collapsible);

/** Changing destination is deliberate, so it takes a click to reopen. */
const picking = ref(false);
const showPicker = computed(() => picking.value || !active.value);

const ICONS = {
    DISCHARGE: CircleCheck,
    HOSPITALIZATION: Building2,
    SURGERY: CirclePlus,
    MATERNITY: HeartPulse,
    PEDIATRICS: UsersRound,
    REFERRAL: Share2,
};

const defaultPriority = () => (props.isEmergency ? 'URGENT' : 'NORMAL');

const selectForm = useForm({ type: '', priority: '' });
const choose = (type) => {
    if (!canSelect.value) return;

    selectForm.type = type;
    selectForm.priority = defaultPriority();
    selectForm.post(`/medicine/orientations/${props.orientationUuid}/orientation`, {
        preserveScroll: true,
        onSuccess: () => {
            picking.value = false;
            if (type === 'DISCHARGE') emit('discharge-selected');
        },
    });
};

/** "Poursuivre l'évaluation": no destination committed to, yet. */
const keepEvaluating = () => {
    if (!canSelect.value) return;

    selectForm.type = '';
    selectForm.priority = '';
    selectForm.post(`/medicine/orientations/${props.orientationUuid}/orientation`, {
        preserveScroll: true,
        onSuccess: () => { picking.value = false; },
    });
};

/* ---------------------------------------------------------------------- */
/* Les demandes. Chacune part déjà remplie de ce qui a été consigné (§17). */
/* ---------------------------------------------------------------------- */

const surgeryForm = useForm({
    catalog_item_uuid: '',
    diagnostic: '',
    indication: '',
    priority: 'NORMAL',
    notes: '',
    return_step: props.returnStep,
});

const hospitalizationForm = useForm({
    reason: '',
    admission_diagnosis: '',
    clinical_summary: '',
    planned_treatment: '',
    requested_service: '',
    requested_admission_at: '',
    priority: 'NORMAL',
    instructions: '',
    return_step: props.returnStep,
});

const referralForm = useForm({
    facility: '',
    reason: '',
    diagnosis: '',
    clinical_summary: '',
    treatments_given: '',
    priority: 'NORMAL',
    recommendations: '',
    notes: '',
    return_step: props.returnStep,
});

const serviceForm = useForm({ destination: '', motif: '', indication: '', observations: '' });

const transferChoice = ref('');

/**
 * Fills the form the doctor is about to see. Runs when the destination
 * changes, never on every keystroke: re-applying the pre-fill over a field
 * being edited would overwrite the correction the doctor just made.
 */
const applyPrefill = (type) => {
    const data = prefill.value;

    if (type === 'SURGERY') {
        surgeryForm.diagnostic = data.diagnosis ?? '';
        surgeryForm.indication = data.clinical_summary ?? '';
        surgeryForm.priority = active.value?.priority ?? defaultPriority();
    }

    if (type === 'HOSPITALIZATION') {
        hospitalizationForm.reason = data.reason ?? '';
        hospitalizationForm.admission_diagnosis = data.diagnosis ?? '';
        hospitalizationForm.clinical_summary = [data.clinical_summary, data.paraclinical].filter(Boolean).join('\n');
        hospitalizationForm.planned_treatment = data.treatments ?? '';
        hospitalizationForm.priority = active.value?.priority ?? defaultPriority();
    }

    if (type === 'REFERRAL') {
        referralForm.reason = data.reason ?? '';
        referralForm.diagnosis = data.diagnosis ?? '';
        referralForm.clinical_summary = [data.clinical_summary, data.paraclinical].filter(Boolean).join('\n');
        referralForm.treatments_given = data.treatments ?? '';
        referralForm.priority = active.value?.priority ?? defaultPriority();
    }

    if (type === 'MATERNITY' || type === 'PEDIATRICS') {
        serviceForm.destination = type;
        serviceForm.motif = data.reason ?? '';
        serviceForm.indication = data.diagnosis ?? '';
    }
};

watch(() => active.value?.type, (type) => {
    if (type) applyPrefill(type);
}, { immediate: true });

const submitSurgery = () => surgeryForm.post(
    `/medicine/orientations/${props.orientationUuid}/surgical-referrals`,
    { preserveScroll: true, onSuccess: closeTransmitConfirmation },
);
const submitHospitalization = () => hospitalizationForm.post(
    `/medicine/orientations/${props.orientationUuid}/hospitalization-requests`,
    { preserveScroll: true, onSuccess: closeTransmitConfirmation },
);
const submitReferral = () => referralForm.post(
    `/medicine/orientations/${props.orientationUuid}/medical-referrals`,
    { preserveScroll: true, onSuccess: closeTransmitConfirmation },
);

/**
 * Maternité and Pédiatrie have no request record of their own: their demand
 * travels as the orientation's `reason`, composed here with its labels so
 * the receiving service reads a structured note rather than a blob.
 */
const serviceReferralForm = useForm({ destination: '', reason: '', return_step: props.returnStep });

// Rattachés au brouillon serveur : ce que le médecin tape dans une demande
// de chirurgie, d'hospitalisation ou de transfert survit à une actualisation
// comme le reste de la consultation.
if (props.draft?.register) {
    props.draft.register('surgical_referral', surgeryForm);
    props.draft.register('hospitalization', hospitalizationForm);
    props.draft.register('referral', referralForm);
    props.draft.register('service_orientation', serviceForm);
    props.draft.register('service_referral', serviceReferralForm);
}
const submitService = () => {
    serviceReferralForm.destination = serviceForm.destination;
    serviceReferralForm.reason = [
        ['Motif', serviceForm.motif],
        ['Indication', serviceForm.indication],
        ['Observations', serviceForm.observations],
    ].filter(([, value]) => String(value ?? '').trim() !== '')
        .map(([label, value]) => `${label} : ${String(value).trim()}`)
        .join('\n');

    serviceReferralForm.post(`/medicine/orientations/${props.orientationUuid}/referrals`, {
        preserveScroll: true,
        onSuccess: closeTransmitConfirmation,
    });
};

/**
 * Transmettre une demande est un acte signé, comme une demande d'examen ou
 * une ordonnance (ADR-106) : le service destinataire prend le patient en
 * charge sur cette seule base, et l'orientation passe à `SUBMITTED`, ce qui
 * débloque la clôture (ADR-084). Un clic ne doit pas suffire à l'engager.
 *
 * La fenêtre nomme la destination et relit ce qui part — confirmer « une
 * demande » sans la voir ne serait pas une signature consciente.
 */
const pendingTransmit = ref(null);

const TRANSMIT_SUBMITS = {
    SURGERY: () => submitSurgery(),
    HOSPITALIZATION: () => submitHospitalization(),
    REFERRAL: () => submitReferral(),
    SERVICE: () => submitService(),
};

const transmitProcessing = computed(() => surgeryForm.processing
    || hospitalizationForm.processing
    || referralForm.processing
    || serviceReferralForm.processing);

/** Ce que la fenêtre relit : ce qui part réellement, jamais un résumé deviné. */
const transmitLines = computed(() => {
    const lines = {
        SURGERY: [
            ['Intervention envisagée', surgeryOptions.value.find((option) => option.value === surgeryForm.catalog_item_uuid)?.label],
            ['Diagnostic / hypothèse', surgeryForm.diagnostic],
            ['Indication', surgeryForm.indication],
            ['Priorité', priorityOptions.value.find((option) => option.value === surgeryForm.priority)?.label],
        ],
        HOSPITALIZATION: [
            ['Motif d’hospitalisation', hospitalizationForm.reason],
            ['Service souhaité', hospitalizationForm.requested_service],
            ['Diagnostic d’entrée', hospitalizationForm.admission_diagnosis],
            ['Priorité', priorityOptions.value.find((option) => option.value === hospitalizationForm.priority)?.label],
        ],
        REFERRAL: [
            ['Établissement destinataire', referralForm.facility],
            ['Motif de la référence', referralForm.reason],
            ['Diagnostic', referralForm.diagnosis],
            ['Priorité', priorityOptions.value.find((option) => option.value === referralForm.priority)?.label],
        ],
        SERVICE: [
            ['Motif', serviceForm.motif],
            ['Indication', serviceForm.indication],
            ['Observations', serviceForm.observations],
        ],
    }[pendingTransmit.value] ?? [];

    return lines
        .filter(([, value]) => String(value ?? '').trim() !== '')
        .map(([label, value]) => ({ label, value: String(value).trim() }));
});

const openTransmitConfirmation = (kind) => { pendingTransmit.value = kind; };

function closeTransmitConfirmation() {
    pendingTransmit.value = null;
}

const confirmTransmit = () => TRANSMIT_SUBMITS[pendingTransmit.value]?.();

// `Select` attend `{ value, label }` : la forme des référentiels reste
// celle du serveur, c'est la projection qui s'adapte à la primitive.
const surgeryOptions = computed(() => props.surgeryCatalog.map((item) => ({ value: item.uuid, label: item.name })));
const transferOptions = computed(() => props.transferDestinations.map((site) => ({ value: site.destination, label: site.destination })));

const priorityOptions = computed(() => props.priorities.map((priority) => ({
    value: priority.value,
    label: priority.label,
    tone: priority.value === 'URGENT' ? 'warning' : 'neutral',
})));

const printUrl = computed(() => {
    const request = active.value?.request;

    if (!request) return null;
    if (request.kind === 'HOSPITALIZATION') return `/medicine/orientations/${props.orientationUuid}/hospitalization-requests/${request.uuid}/print`;
    if (request.kind === 'REFERRAL') return `/medicine/orientations/${props.orientationUuid}/medical-referrals/${request.uuid}/print`;

    return null;
});

</script>

<template>
    <section class="overflow-hidden rounded-lg border border-border bg-card">
        <header :class="['flex flex-wrap items-center justify-between gap-3 px-4 py-3', expanded ? 'border-b border-border' : '']">
            <button
                v-if="collapsible"
                type="button"
                class="flex min-w-0 flex-1 items-center gap-2 text-start"
                :aria-expanded="expanded"
                @click="expanded = !expanded"
            >
                <component :is="expanded ? ChevronUp : ChevronDown" class="h-4 w-4 shrink-0 text-muted-foreground" />
                <span class="min-w-0">
                    <span class="block text-sm font-bold text-foreground">Suite de la prise en charge</span>
                    <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">
                        {{ active ? `${active.type_label} · ${active.status_label}` : 'Non déterminée — ouvrir pour la définir' }}
                    </span>
                </span>
            </button>
            <div v-else class="min-w-0">
                <h3 class="text-sm font-bold text-foreground">Suite de la prise en charge</h3>
                <p class="mt-0.5 text-[11px] text-muted-foreground">
                    Choisissez où va le patient et transmettez sa demande : c’est elle qui permet de clôturer.
                </p>
            </div>

            <div v-if="active && !showPicker && expanded" class="flex shrink-0 items-center gap-2">
                <a
                    v-if="printUrl"
                    :href="printUrl"
                    class="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-[11px] font-semibold text-muted-foreground hover:bg-accent"
                >
                    <Printer class="h-4 w-4" />Document
                </a>
                <Button type="button" size="sm" variant="white-outline" :disabled="!canSelect" @click="picking = true">
                    <Pencil class="h-4 w-4" />Modifier
                </Button>
            </div>
        </header>

        <!-- L'orientation en cours, toujours visible : « Décision » quitte le
             parcours, l'information non (§15). -->
        <div v-if="active && !showPicker && expanded" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border bg-primary/5 px-4 py-3">
            <span class="inline-flex items-center gap-2 text-sm font-bold text-primary">
                <component :is="ICONS[active.type] ?? Share2" class="h-4 w-4" />{{ active.type_label }}
            </span>
            <span
                :class="[
                    'rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                    active.status === 'SUBMITTED'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200'
                        : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
                ]"
            >{{ active.status_label }}</span>
            <span v-if="active.priority_label" class="text-[11px] text-muted-foreground">
                Priorité : <strong :class="active.priority === 'URGENT' ? 'text-amber-600 dark:text-amber-400' : ''">{{ active.priority_label }}</strong>
            </span>
            <span v-if="active.request?.summary" class="min-w-0 truncate text-[11px] text-muted-foreground">{{ active.request.summary }}</span>
        </div>

        <div v-show="expanded" class="p-4">
            <!-- Le choix. Rien n'est pré-sélectionné : une orientation non
                 choisie n'est pas « Poursuivre l'évaluation » par défaut. -->
            <div v-if="showPicker">
                <p class="mb-2.5 text-xs font-bold text-foreground">La conduite à tenir est-elle déjà déterminée ?</p>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="type in types"
                        :key="type.value"
                        type="button"
                        :disabled="!canSelect || selectForm.processing"
                        :class="[
                            'flex items-center gap-2.5 rounded-lg border px-3 py-2.5 text-start transition-colors disabled:opacity-50',
                            active?.type === type.value
                                ? 'border-primary bg-primary/10'
                                : 'border-border hover:border-primary/50 hover:bg-accent',
                        ]"
                        @click="choose(type.value)"
                    >
                        <component :is="ICONS[type.value] ?? Share2" class="h-4 w-4 shrink-0 text-primary" />
                        <span class="min-w-0 text-xs font-bold text-foreground">{{ type.label }}</span>
                    </button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <Button type="button" size="sm" variant="white-outline" :disabled="!canSelect || selectForm.processing" @click="keepEvaluating">
                        <Clock class="h-4 w-4" />Poursuivre l’évaluation
                    </Button>
                    <p class="text-[11px] text-muted-foreground">Vous pourrez la décider plus tard, après les examens ou la prescription.</p>
                </div>

                <FormError :message="selectForm.errors.type || selectForm.errors.orientation" />
            </div>

            <!-- Progressive disclosure : uniquement le formulaire de la
                 destination retenue (§29). -->
            <template v-else-if="active">
                <p class="mb-3 text-xs font-bold text-foreground">{{ active.form_title }}</p>

                <form v-if="active.type === 'SURGERY'" class="space-y-3" @submit.prevent="openTransmitConfirmation('SURGERY')">
                    <FormField label="Intervention envisagée" required :error="surgeryForm.errors.catalog_item_uuid">
                        <Select
                            id="orientation_surgery_item"
                            v-model="surgeryForm.catalog_item_uuid"
                            :options="surgeryOptions"
                            :disabled="disabled"
                            placeholder="Sélectionner…"
                        />
                    </FormField>
                    <FormField label="Diagnostic / hypothèse" required :error="surgeryForm.errors.diagnostic">
                        <Textarea id="orientation_surgery_diagnostic" v-model="surgeryForm.diagnostic" :disabled="disabled" :rows="2" maxlength="2000" />
                    </FormField>
                    <FormField label="Indication chirurgicale">
                        <Textarea id="orientation_surgery_indication" v-model="surgeryForm.indication" :disabled="disabled" :rows="3" maxlength="2000" />
                    </FormField>
                    <ClinicalSegmentedChoice
                        v-model="surgeryForm.priority"
                        name="orientation_surgery_priority"
                        label="Priorité"
                        :options="priorityOptions"
                        :clearable="false"
                        :disabled="disabled"
                    />
                    <FormField label="Notes">
                        <Textarea id="orientation_surgery_notes" v-model="surgeryForm.notes" :disabled="disabled" :rows="2" maxlength="2000" />
                    </FormField>
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" :disabled="disabled || surgeryForm.processing">
                            <Send class="h-4 w-4" />Transmettre la demande
                        </Button>
                    </div>
                </form>

                <form v-else-if="active.type === 'HOSPITALIZATION'" class="space-y-3" @submit.prevent="openTransmitConfirmation('HOSPITALIZATION')">
                    <FormField label="Motif d’hospitalisation" required :error="hospitalizationForm.errors.reason">
                        <Textarea id="orientation_hosp_reason" v-model="hospitalizationForm.reason" :disabled="disabled" :rows="2" maxlength="3000" />
                    </FormField>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField label="Service souhaité">
                            <Input id="orientation_hosp_service" v-model="hospitalizationForm.requested_service" :disabled="disabled" maxlength="150" />
                        </FormField>
                        <FormField label="Admission souhaitée">
                            <Input id="orientation_hosp_when" v-model="hospitalizationForm.requested_admission_at" :disabled="disabled" type="datetime-local" />
                        </FormField>
                    </div>
                    <!-- Prérempli depuis le dossier : à corriger, jamais à
                         ressaisir (§17). -->
                    <FormField label="Diagnostic d’entrée" hint="· repris du dossier">
                        <Textarea id="orientation_hosp_diagnosis" v-model="hospitalizationForm.admission_diagnosis" :disabled="disabled" :rows="2" maxlength="3000" />
                    </FormField>
                    <FormField label="Résumé clinique et examens" hint="· repris du dossier">
                        <Textarea id="orientation_hosp_summary" v-model="hospitalizationForm.clinical_summary" :disabled="disabled" :rows="10" maxlength="5000" />
                    </FormField>
                    <FormField label="Traitement prévu" hint="· repris de l’ordonnance">
                        <Textarea id="orientation_hosp_treatment" v-model="hospitalizationForm.planned_treatment" :disabled="disabled" :rows="3" maxlength="3000" />
                    </FormField>
                    <ClinicalSegmentedChoice
                        v-model="hospitalizationForm.priority"
                        name="orientation_hosp_priority"
                        label="Priorité"
                        :options="priorityOptions"
                        :clearable="false"
                        :disabled="disabled"
                    />
                    <FormField label="Instructions au service">
                        <Textarea id="orientation_hosp_instructions" v-model="hospitalizationForm.instructions" :disabled="disabled" :rows="2" maxlength="3000" />
                    </FormField>
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" :disabled="disabled || hospitalizationForm.processing">
                            <Send class="h-4 w-4" />Transmettre la demande
                        </Button>
                    </div>
                </form>

                <form v-else-if="active.type === 'REFERRAL'" class="space-y-3" @submit.prevent="openTransmitConfirmation('REFERRAL')">
                    <FormField label="Établissement / service destinataire" required :error="referralForm.errors.facility">
                        <Select
                            v-if="transferDestinations.length"
                            id="orientation_referral_site"
                            v-model="transferChoice"
                            class="mb-2"
                            :options="transferOptions"
                            :disabled="disabled"
                            placeholder="Autre établissement…"
                            @update:model-value="referralForm.facility = transferChoice"
                        />
                        <Input id="orientation_referral_facility" v-model="referralForm.facility" :disabled="disabled" maxlength="255" placeholder="CHU, hôpital, cabinet…" />
                    </FormField>
                    <FormField label="Motif de la référence" required :error="referralForm.errors.reason">
                        <Textarea id="orientation_referral_reason" v-model="referralForm.reason" :disabled="disabled" :rows="2" maxlength="3000" />
                    </FormField>
                    <FormField label="Diagnostic" hint="· repris du dossier">
                        <Textarea id="orientation_referral_diagnosis" v-model="referralForm.diagnosis" :disabled="disabled" :rows="2" maxlength="3000" />
                    </FormField>
                    <FormField label="Résumé clinique et examens réalisés" hint="· repris du dossier">
                        <Textarea id="orientation_referral_summary" v-model="referralForm.clinical_summary" :disabled="disabled" :rows="10" maxlength="5000" />
                    </FormField>
                    <FormField label="Traitements administrés ou prescrits" hint="· repris de l’ordonnance">
                        <Textarea id="orientation_referral_treatments" v-model="referralForm.treatments_given" :disabled="disabled" :rows="3" maxlength="3000" />
                    </FormField>
                    <ClinicalSegmentedChoice
                        v-model="referralForm.priority"
                        name="orientation_referral_priority"
                        label="Priorité"
                        :options="priorityOptions"
                        :clearable="false"
                        :disabled="disabled"
                    />
                    <FormField label="Recommandations">
                        <Textarea id="orientation_referral_reco" v-model="referralForm.recommendations" :disabled="disabled" :rows="2" maxlength="3000" />
                    </FormField>
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" :disabled="disabled || referralForm.processing">
                            <Send class="h-4 w-4" />Transmettre et générer la lettre
                        </Button>
                    </div>
                </form>

                <form v-else-if="active.type === 'MATERNITY' || active.type === 'PEDIATRICS'" class="space-y-3" @submit.prevent="openTransmitConfirmation('SERVICE')">
                    <FormField label="Motif" required :error="serviceReferralForm.errors.reason">
                        <Textarea id="orientation_service_motif" v-model="serviceForm.motif" :disabled="disabled" :rows="2" maxlength="2000" />
                    </FormField>
                    <FormField label="Indication" hint="· reprise du dossier">
                        <Textarea id="orientation_service_indication" v-model="serviceForm.indication" :disabled="disabled" :rows="2" maxlength="2000" />
                    </FormField>
                    <FormField label="Observations">
                        <Textarea id="orientation_service_observations" v-model="serviceForm.observations" :disabled="disabled" :rows="2" maxlength="2000" />
                    </FormField>
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" :disabled="disabled || serviceReferralForm.processing">
                            <Send class="h-4 w-4" />Transmettre la demande
                        </Button>
                    </div>
                </form>

                <!-- La sortie médicale garde son formulaire complet dans
                     l'écran : type, état du patient, recommandations,
                     rendez-vous et, le cas échéant, décès. -->
                <div v-else-if="active.type === 'DISCHARGE'">
                    <slot name="discharge">
                        <p class="text-[11px] text-muted-foreground">Renseignez la sortie médicale ci-dessous.</p>
                    </slot>
                </div>

                <p v-if="active.status === 'SUBMITTED'" class="mt-3 flex items-center gap-2 rounded border border-emerald-200 bg-emerald-50/60 px-3 py-2 text-[11px] text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
                    <CircleCheck class="h-4 w-4 shrink-0" />
                    Demande transmise. Vous pouvez la corriger en la retransmettant, ou changer d’orientation tant que la consultation n’est pas clôturée.
                </p>
            </template>

            <!-- Changer d'avis reste tracé, mais replié : une ligne de
                 résumé, la liste seulement à la demande, et bornée en hauteur
                 pour qu'un historique long ne déborde jamais sur la page. -->
            <div v-if="history.length" class="mt-4 border-t border-border pt-2.5">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded px-1 py-1 text-start text-[11px] text-muted-foreground hover:bg-accent"
                    :aria-expanded="showHistory"
                    @click="showHistory = !showHistory"
                >
                    <History class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <span class="shrink-0 font-semibold">{{ history.length }} orientation{{ history.length > 1 ? 's' : '' }} annulée{{ history.length > 1 ? 's' : '' }}</span>
                    <span v-if="!showHistory" class="min-w-0 flex-1 truncate text-muted-foreground">· dernière : {{ history[history.length - 1].type_label }}</span>
                    <span v-else class="flex-1" />
                    <component :is="showHistory ? ChevronUp : ChevronDown" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                </button>
                <ul v-if="showHistory" class="mt-1.5 max-h-36 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    <li
                        v-for="entry in [...history].reverse()"
                        :key="entry.uuid"
                        class="flex items-center gap-2 px-2.5 py-1.5 text-[11px] text-muted-foreground"
                        :title="entry.cancellation_reason ?? undefined"
                    >
                        <span class="min-w-0 flex-1 truncate line-through">{{ entry.type_label }}</span>
                        <span class="shrink-0 tabular-nums">{{ formatDateTime(entry.cancelled_at) }}<template v-if="entry.cancelled_by"> · {{ entry.cancelled_by }}</template></span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- ADR-106 — transmettre engage le service destinataire et débloque
             la clôture. Même format que la demande d'examen et l'ordonnance :
             on relit ce qui part, et on signe. Non fermable au clic
             extérieur : c'est un acte, pas une fenêtre qu'on parcourt. -->
        <Dialog
            :open="pendingTransmit !== null"
            title="Confirmer la demande"
            :description="active?.type_label ?? ''"
            :dismissible="false"
            close-label="Revenir à la demande"
            @update:open="closeTransmitConfirmation"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                    <Send class="h-5 w-5" />
                </span>
            </template>

            <dl v-if="transmitLines.length" class="divide-y divide-border overflow-hidden rounded-lg border border-border">
                <div v-for="line in transmitLines" :key="line.label" class="px-3 py-2">
                    <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{{ line.label }}</dt>
                    <dd class="mt-0.5 whitespace-pre-line text-sm text-foreground">{{ line.value }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs leading-5 text-muted-foreground">
                Vous transmettez sous votre responsabilité, en tant que
                <strong class="font-semibold text-foreground">{{ $page.props.auth.user.name }}</strong>.
                Vous pourrez encore changer d’orientation tant que la destination n’a pas pris la demande en charge.
            </p>

            <template #footer>
                <Button type="button" variant="outline" :disabled="transmitProcessing" @click="closeTransmitConfirmation">
                    Revenir à la demande
                </Button>
                <Button type="button" :disabled="transmitProcessing" @click="confirmTransmit">
                    <Send class="h-4 w-4" />Je confirme et transmets
                </Button>
            </template>
        </Dialog>
    </section>
</template>
