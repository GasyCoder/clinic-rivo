<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';

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
});

const emit = defineEmits(['discharge-selected']);

const active = computed(() => props.state?.active ?? null);
const prefill = computed(() => props.state?.prefill ?? {});
const canSelect = computed(() => props.state?.can_select && !props.disabled);
const history = computed(() => props.state?.history ?? []);

/** Changing destination is deliberate, so it takes a click to reopen. */
const picking = ref(false);
const showPicker = computed(() => picking.value || !active.value);

const ICONS = {
    DISCHARGE: 'check-circle',
    HOSPITALIZATION: 'building',
    SURGERY: 'plus-circle',
    MATERNITY: 'heart',
    PEDIATRICS: 'users',
    REFERRAL: 'share',
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
    { preserveScroll: true },
);
const submitHospitalization = () => hospitalizationForm.post(
    `/medicine/orientations/${props.orientationUuid}/hospitalization-requests`,
    { preserveScroll: true },
);
const submitReferral = () => referralForm.post(
    `/medicine/orientations/${props.orientationUuid}/medical-referrals`,
    { preserveScroll: true },
);

/**
 * Maternité and Pédiatrie have no request record of their own: their demand
 * travels as the orientation's `reason`, composed here with its labels so
 * the receiving service reads a structured note rather than a blob.
 */
const serviceReferralForm = useForm({ destination: '', reason: '', return_step: props.returnStep });
const submitService = () => {
    serviceReferralForm.destination = serviceForm.destination;
    serviceReferralForm.reason = [
        ['Motif', serviceForm.motif],
        ['Indication', serviceForm.indication],
        ['Observations', serviceForm.observations],
    ].filter(([, value]) => String(value ?? '').trim() !== '')
        .map(([label, value]) => `${label} : ${String(value).trim()}`)
        .join('\n');

    serviceReferralForm.post(`/medicine/orientations/${props.orientationUuid}/referrals`, { preserveScroll: true });
};

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

const textareaClass = 'block w-full resize-y rounded border border-gray-200 bg-white px-3 py-2 text-sm leading-5 text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900">
            <div class="min-w-0">
                <h3 class="text-sm font-bold text-slate-700 dark:text-white">Suite de la prise en charge</h3>
                <p class="mt-0.5 text-[11px] text-slate-400">
                    La conduite à tenir peut être décidée dès que vous disposez d’assez d’éléments.
                </p>
            </div>

            <div v-if="active && !showPicker" class="flex shrink-0 items-center gap-2">
                <a
                    v-if="printUrl"
                    :href="printUrl"
                    class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300"
                >
                    <Icon class="text-sm" name="printer" />Document
                </a>
                <Button type="button" size="sm" variant="white-outline" :disabled="!canSelect" @click="picking = true">
                    <Icon class="me-1.5 text-sm" name="edit" />Modifier
                </Button>
            </div>
        </header>

        <!-- L'orientation en cours, toujours visible : « Décision » quitte le
             parcours, l'information non (§15). -->
        <div v-if="active && !showPicker" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-200 bg-primary-50/40 px-4 py-3 dark:border-gray-900 dark:bg-primary-950/20">
            <span class="inline-flex items-center gap-2 text-sm font-bold text-primary-700 dark:text-primary-300">
                <Icon :name="ICONS[active.type] ?? 'share'" />{{ active.type_label }}
            </span>
            <span
                :class="[
                    'rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                    active.status === 'SUBMITTED'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200'
                        : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
                ]"
            >{{ active.status_label }}</span>
            <span v-if="active.priority_label" class="text-[11px] text-slate-500 dark:text-slate-400">
                Priorité : <strong :class="active.priority === 'URGENT' ? 'text-amber-600 dark:text-amber-400' : ''">{{ active.priority_label }}</strong>
            </span>
            <span v-if="active.request?.summary" class="min-w-0 truncate text-[11px] text-slate-500 dark:text-slate-400">{{ active.request.summary }}</span>
        </div>

        <div class="p-4">
            <!-- Le choix. Rien n'est pré-sélectionné : une orientation non
                 choisie n'est pas « Poursuivre l'évaluation » par défaut. -->
            <div v-if="showPicker">
                <p class="mb-2.5 text-xs font-bold text-slate-700 dark:text-white">La conduite à tenir est-elle déjà déterminée ?</p>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="type in types"
                        :key="type.value"
                        type="button"
                        :disabled="!canSelect || selectForm.processing"
                        :class="[
                            'flex items-center gap-2.5 rounded-lg border px-3 py-2.5 text-start transition-colors disabled:opacity-50',
                            active?.type === type.value
                                ? 'border-primary-500 bg-primary-50 dark:border-primary-700 dark:bg-primary-950/30'
                                : 'border-gray-200 hover:border-primary-300 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-1000/60',
                        ]"
                        @click="choose(type.value)"
                    >
                        <Icon :name="ICONS[type.value] ?? 'share'" class="shrink-0 text-base text-primary-600 dark:text-primary-300" />
                        <span class="min-w-0 text-xs font-bold text-slate-700 dark:text-white">{{ type.label }}</span>
                    </button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <Button type="button" size="sm" variant="white-outline" :disabled="!canSelect || selectForm.processing" @click="keepEvaluating">
                        <Icon class="me-1.5 text-sm" name="clock" />Poursuivre l’évaluation
                    </Button>
                    <p class="text-[11px] text-slate-400">Vous pourrez la décider plus tard, après les examens ou la prescription.</p>
                </div>

                <FormError :message="selectForm.errors.type || selectForm.errors.orientation" />
            </div>

            <!-- Progressive disclosure : uniquement le formulaire de la
                 destination retenue (§29). -->
            <template v-else-if="active">
                <p class="mb-3 text-xs font-bold text-slate-700 dark:text-white">{{ active.form_title }}</p>

                <form v-if="active.type === 'SURGERY'" class="space-y-3" @submit.prevent="submitSurgery">
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_surgery_item">Intervention envisagée <span class="text-red-500">*</span></label>
                        <select id="orientation_surgery_item" v-model="surgeryForm.catalog_item_uuid" :disabled="disabled" class="block h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                            <option value="">Sélectionner…</option>
                            <option v-for="item in surgeryCatalog" :key="item.uuid" :value="item.uuid">{{ item.name }}</option>
                        </select>
                        <FormError :message="surgeryForm.errors.catalog_item_uuid" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_surgery_diagnostic">Diagnostic / hypothèse <span class="text-red-500">*</span></label>
                        <textarea id="orientation_surgery_diagnostic" v-model="surgeryForm.diagnostic" :disabled="disabled" rows="2" maxlength="2000" :class="textareaClass" />
                        <FormError :message="surgeryForm.errors.diagnostic" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_surgery_indication">Indication chirurgicale</label>
                        <textarea id="orientation_surgery_indication" v-model="surgeryForm.indication" :disabled="disabled" rows="3" maxlength="2000" :class="textareaClass" />
                    </div>
                    <ClinicalSegmentedChoice
                        v-model="surgeryForm.priority"
                        name="orientation_surgery_priority"
                        label="Priorité"
                        :options="priorityOptions"
                        :clearable="false"
                        :disabled="disabled"
                    />
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_surgery_notes">Notes</label>
                        <textarea id="orientation_surgery_notes" v-model="surgeryForm.notes" :disabled="disabled" rows="2" maxlength="2000" :class="textareaClass" />
                    </div>
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" :disabled="disabled || surgeryForm.processing">
                            <Icon class="me-1.5 text-sm" name="send" />Transmettre la demande
                        </Button>
                    </div>
                </form>

                <form v-else-if="active.type === 'HOSPITALIZATION'" class="space-y-3" @submit.prevent="submitHospitalization">
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_hosp_reason">Motif d’hospitalisation <span class="text-red-500">*</span></label>
                        <textarea id="orientation_hosp_reason" v-model="hospitalizationForm.reason" :disabled="disabled" rows="2" maxlength="3000" :class="textareaClass" />
                        <FormError :message="hospitalizationForm.errors.reason" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_hosp_service">Service souhaité</label>
                            <Input id="orientation_hosp_service" v-model="hospitalizationForm.requested_service" :disabled="disabled" maxlength="150" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_hosp_when">Admission souhaitée</label>
                            <Input id="orientation_hosp_when" v-model="hospitalizationForm.requested_admission_at" :disabled="disabled" type="datetime-local" />
                        </div>
                    </div>
                    <!-- Prérempli depuis le dossier : à corriger, jamais à
                         ressaisir (§17). -->
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_hosp_diagnosis">Diagnostic d’entrée <span class="font-normal text-slate-400">· repris du dossier</span></label>
                        <textarea id="orientation_hosp_diagnosis" v-model="hospitalizationForm.admission_diagnosis" :disabled="disabled" rows="2" maxlength="3000" :class="textareaClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_hosp_summary">Résumé clinique et examens <span class="font-normal text-slate-400">· repris du dossier</span></label>
                        <textarea id="orientation_hosp_summary" v-model="hospitalizationForm.clinical_summary" :disabled="disabled" rows="4" maxlength="5000" :class="textareaClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_hosp_treatment">Traitement prévu <span class="font-normal text-slate-400">· repris de l’ordonnance</span></label>
                        <textarea id="orientation_hosp_treatment" v-model="hospitalizationForm.planned_treatment" :disabled="disabled" rows="3" maxlength="3000" :class="textareaClass" />
                    </div>
                    <ClinicalSegmentedChoice
                        v-model="hospitalizationForm.priority"
                        name="orientation_hosp_priority"
                        label="Priorité"
                        :options="priorityOptions"
                        :clearable="false"
                        :disabled="disabled"
                    />
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_hosp_instructions">Instructions au service</label>
                        <textarea id="orientation_hosp_instructions" v-model="hospitalizationForm.instructions" :disabled="disabled" rows="2" maxlength="3000" :class="textareaClass" />
                    </div>
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" :disabled="disabled || hospitalizationForm.processing">
                            <Icon class="me-1.5 text-sm" name="send" />Transmettre la demande
                        </Button>
                    </div>
                </form>

                <form v-else-if="active.type === 'REFERRAL'" class="space-y-3" @submit.prevent="submitReferral">
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_referral_facility">Établissement / service destinataire <span class="text-red-500">*</span></label>
                        <select
                            v-if="transferDestinations.length"
                            id="orientation_referral_site"
                            v-model="transferChoice"
                            :disabled="disabled"
                            class="mb-2 block h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white"
                            @change="referralForm.facility = transferChoice"
                        >
                            <option value="">Autre établissement…</option>
                            <option v-for="site in transferDestinations" :key="site.code" :value="site.destination">{{ site.destination }}</option>
                        </select>
                        <Input id="orientation_referral_facility" v-model="referralForm.facility" :disabled="disabled" maxlength="255" placeholder="CHU, hôpital, cabinet…" />
                        <FormError :message="referralForm.errors.facility" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_referral_reason">Motif de la référence <span class="text-red-500">*</span></label>
                        <textarea id="orientation_referral_reason" v-model="referralForm.reason" :disabled="disabled" rows="2" maxlength="3000" :class="textareaClass" />
                        <FormError :message="referralForm.errors.reason" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_referral_diagnosis">Diagnostic <span class="font-normal text-slate-400">· repris du dossier</span></label>
                        <textarea id="orientation_referral_diagnosis" v-model="referralForm.diagnosis" :disabled="disabled" rows="2" maxlength="3000" :class="textareaClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_referral_summary">Résumé clinique et examens réalisés <span class="font-normal text-slate-400">· repris du dossier</span></label>
                        <textarea id="orientation_referral_summary" v-model="referralForm.clinical_summary" :disabled="disabled" rows="4" maxlength="5000" :class="textareaClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_referral_treatments">Traitements administrés ou prescrits <span class="font-normal text-slate-400">· repris de l’ordonnance</span></label>
                        <textarea id="orientation_referral_treatments" v-model="referralForm.treatments_given" :disabled="disabled" rows="3" maxlength="3000" :class="textareaClass" />
                    </div>
                    <ClinicalSegmentedChoice
                        v-model="referralForm.priority"
                        name="orientation_referral_priority"
                        label="Priorité"
                        :options="priorityOptions"
                        :clearable="false"
                        :disabled="disabled"
                    />
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_referral_reco">Recommandations</label>
                        <textarea id="orientation_referral_reco" v-model="referralForm.recommendations" :disabled="disabled" rows="2" maxlength="3000" :class="textareaClass" />
                    </div>
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" :disabled="disabled || referralForm.processing">
                            <Icon class="me-1.5 text-sm" name="send" />Transmettre et générer la lettre
                        </Button>
                    </div>
                </form>

                <form v-else-if="active.type === 'MATERNITY' || active.type === 'PEDIATRICS'" class="space-y-3" @submit.prevent="submitService">
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_service_motif">Motif <span class="text-red-500">*</span></label>
                        <textarea id="orientation_service_motif" v-model="serviceForm.motif" :disabled="disabled" rows="2" maxlength="2000" :class="textareaClass" />
                        <FormError :message="serviceReferralForm.errors.reason" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_service_indication">Indication <span class="font-normal text-slate-400">· reprise du dossier</span></label>
                        <textarea id="orientation_service_indication" v-model="serviceForm.indication" :disabled="disabled" rows="2" maxlength="2000" :class="textareaClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-700 dark:text-white" for="orientation_service_observations">Observations</label>
                        <textarea id="orientation_service_observations" v-model="serviceForm.observations" :disabled="disabled" rows="2" maxlength="2000" :class="textareaClass" />
                    </div>
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" :disabled="disabled || serviceReferralForm.processing">
                            <Icon class="me-1.5 text-sm" name="send" />Transmettre la demande
                        </Button>
                    </div>
                </form>

                <!-- La sortie médicale garde son formulaire complet dans
                     l'écran : type, état du patient, recommandations,
                     rendez-vous et, le cas échéant, décès. -->
                <div v-else-if="active.type === 'DISCHARGE'">
                    <slot name="discharge">
                        <p class="text-[11px] text-slate-400">Renseignez la sortie médicale ci-dessous.</p>
                    </slot>
                </div>

                <p v-if="active.status === 'SUBMITTED'" class="mt-3 flex items-center gap-2 rounded border border-emerald-200 bg-emerald-50/60 px-3 py-2 text-[11px] text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
                    <Icon class="shrink-0 text-sm" name="check-circle" />
                    Demande transmise. Vous pouvez la corriger en la retransmettant, ou changer d’orientation tant que la consultation n’est pas clôturée.
                </p>
            </template>

            <!-- Changer d'avis est tracé : la première intention reste lisible. -->
            <div v-if="history.length" class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-900">
                <p class="mb-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-400">Orientations précédentes</p>
                <ul class="space-y-1">
                    <li v-for="entry in history" :key="entry.uuid" class="text-[11px] text-slate-500 dark:text-slate-400">
                        <span class="line-through">{{ entry.type_label }}</span>
                        <span class="ms-1.5">— annulée<span v-if="entry.cancelled_by"> par {{ entry.cancelled_by }}</span></span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
