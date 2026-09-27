<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import {
    Ambulance, Baby, BedDouble, CircleCheck, DoorOpen, ExternalLink, History, Hospital,
    Info, Pencil, Printer, Scissors, Skull, UsersRound, ChevronDown, ChevronUp,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import FormError from '@/Components/UI/FormError.vue';
import { formatDateTime, localToday } from '@/utilities/date';
import {
    DECISION_HINTS, DISCHARGE_CONDITIONS, FOLLOW_UPS, decisionIsLocked, followUpDate, isServiceDecision,
} from '@/utilities/consultationClosure';

/**
 * « Conduite à tenir » (ADR-203).
 *
 * Un choix, puis — seulement pour la conduite choisie — ce qui la précise.
 * Rien ne part d'ici : la conduite est transmise par « Clôturer », en un seul
 * geste signé. Il n'y a plus de bouton « Transmettre la demande » par
 * destination, ni de second formulaire de sortie : le transfert est une
 * conduite, jamais un type de sortie.
 *
 * `form` appartient à la page : c'est elle qui l'envoie à la clôture et qui le
 * garde dans le brouillon serveur (ADR-073).
 */
const props = defineProps({
    orientationUuid: { type: String, required: true },
    form: { type: Object, required: true },
    /** `consultation_orientation` du serveur : conduite active, historique, préremplissage. */
    state: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    dischargeTypes: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    surgeryCatalog: { type: Array, default: () => [] },
    transferDestinations: { type: Array, default: () => [] },
    isEmergency: { type: Boolean, default: false },
    hospitalStay: { type: Object, default: null },
    medicalDischarge: { type: Object, default: null },
    disabled: { type: Boolean, default: false },
    closed: { type: Boolean, default: false },
});

const ICONS = {
    DISCHARGE: DoorOpen,
    HOSPITALIZATION: Hospital,
    SURGERY: Scissors,
    REFERRAL: Ambulance,
    MATERNITY: Baby,
    PEDIATRICS: UsersRound,
    CONTINUED_HOSPITALIZATION: BedDouble,
};

const active = computed(() => props.state?.active ?? null);
const locked = computed(() => decisionIsLocked({ active: active.value, medicalDischarge: props.medicalDischarge }));
const history = computed(() => props.state?.history ?? []);
const showHistory = ref(false);

const defaultPriority = () => (props.isEmergency ? 'URGENT' : 'NORMAL');

// Une conduite choisie plus tôt, sans être partie, reste choisie ici.
watch(() => active.value, (value) => {
    if (value && value.status !== 'SUBMITTED' && !props.form.type) {
        props.form.type = value.type;
        props.form.priority = value.priority ?? defaultPriority();
    }
}, { immediate: true });

const choose = (type) => {
    if (props.disabled) return;

    props.form.type = type;
    if (!props.form.priority) props.form.priority = defaultPriority();
    props.form.clearErrors();
};

const error = (key) => props.form.errors?.[`decision.${key}`] ?? '';

const priorityOptions = computed(() => props.priorities.map((priority) => ({
    value: priority.value,
    label: priority.label,
    tone: priority.value === 'URGENT' ? 'warning' : 'neutral',
})));

const dischargeTypeOptions = computed(() => props.dischargeTypes.map((type) => ({
    value: type.value,
    label: type.label,
    tone: type.value === 'DECEASED' ? 'warning' : 'neutral',
})));

const surgeryOptions = computed(() => props.surgeryCatalog.map((item) => ({ value: item.uuid, label: item.name })));

/* ── La sortie : un type, le reste facultatif et replié ───────────────── */

const isDeceased = computed(() => props.form.discharge_type === 'DECEASED');
const dischargeDetailsOpen = ref(Boolean(
    props.form.patient_condition || props.form.recommendations || props.form.follow_up_at,
));
const dischargeDetailsCount = computed(() => [
    props.form.patient_condition,
    String(props.form.discharge_prescription ?? '').trim(),
    String(props.form.recommendations ?? '').trim(),
    props.form.follow_up_at,
].filter(Boolean).length);

const setFollowUp = (days) => { props.form.follow_up_at = followUpDate(days); };

/* ── Le transfert : un site de la clinique, ou un autre établissement ─── */

const LATER = '__LATER__';
const OTHER = '__OTHER__';
const facilityOptions = computed(() => [
    { value: LATER, label: 'À préciser plus tard' },
    ...props.transferDestinations.map((site) => ({ value: site.destination, label: site.destination })),
    { value: OTHER, label: 'Autre établissement…' },
]);
const knownFacility = (value) => props.transferDestinations.some((site) => site.destination === value);
const facilityChoice = ref(!props.form.facility ? LATER : (knownFacility(props.form.facility) ? props.form.facility : OTHER));
const otherFacility = ref(facilityChoice.value === OTHER ? props.form.facility : '');

watch([facilityChoice, otherFacility], ([choice, other]) => {
    props.form.facility = choice === LATER ? '' : (choice === OTHER ? other : choice);
});

/* ── Changer une conduite déjà transmise ──────────────────────────────── */

const changeOpen = ref(false);
const changeForm = useForm({ type: null, priority: null });
const canChange = computed(() => Boolean(props.state?.can_select)
    && !props.medicalDischarge
    && !props.disabled
    && !props.closed);

const confirmChange = () => changeForm.post(`/medicine/orientations/${props.orientationUuid}/orientation`, {
    preserveScroll: true,
    onSuccess: () => {
        changeOpen.value = false;
        props.form.type = null;
    },
});

const printUrl = computed(() => {
    const request = active.value?.request;

    if (!request) return null;
    if (request.kind === 'HOSPITALIZATION') return `/medicine/orientations/${props.orientationUuid}/hospitalization-requests/${request.uuid}/print`;
    if (request.kind === 'REFERRAL') return `/medicine/orientations/${props.orientationUuid}/medical-referrals/${request.uuid}/print`;

    return null;
});
</script>

<template>
    <div>
        <!-- Un patient au lit ne se conclut pas comme un patient qui rentre
             chez lui (ADR-149, ADR-162). -->
        <div v-if="hospitalStay && !locked" class="mb-3 flex items-start gap-2.5 rounded-md border border-sky-200 bg-sky-50/60 px-3 py-2.5 text-xs leading-5 text-sky-900 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-200">
            <BedDouble class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p class="min-w-0">
                <strong class="font-semibold">Patient hospitalisé</strong>
                <span v-if="hospitalStay.room_bed"> · {{ hospitalStay.room_bed }}</span>
                <span v-if="hospitalStay.service"> · {{ hospitalStay.service }}</span>.
                Sa sortie se prononce sur <a :href="hospitalStay.url" class="font-semibold underline underline-offset-2">la page du séjour</a>.
            </p>
        </div>

        <!-- Une conduite déjà fixée : on la lit, on ne la ressaisit pas. -->
        <div v-if="locked" class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-4 dark:border-emerald-900 dark:bg-emerald-950/20">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                        <component :is="medicalDischarge ? DoorOpen : (ICONS[active?.type] ?? CircleCheck)" class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-bold text-foreground">
                            {{ medicalDischarge ? 'Sortie médicale' : active.type_label }}
                            <Badge tone="success">{{ medicalDischarge ? medicalDischarge.type_label : 'Transmise' }}</Badge>
                            <Badge v-if="!medicalDischarge && active.priority === 'URGENT'" tone="warning">Urgent</Badge>
                        </p>
                        <p v-if="medicalDischarge" class="mt-1 text-xs text-muted-foreground">
                            Prononcée le {{ formatDateTime(medicalDischarge.discharged_at) }}<template v-if="medicalDischarge.created_by"> par {{ medicalDischarge.created_by }}</template>.
                        </p>
                        <p v-else class="mt-1 text-xs text-muted-foreground">
                            <template v-if="active.request?.summary">{{ active.request.summary }} · </template>
                            <template v-if="active.submitted_at">le {{ formatDateTime(active.submitted_at) }}</template>
                            <template v-if="active.request?.module_label">. Elle se complète dans l’espace {{ active.request.module_label }}.</template>
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="active?.request?.module_url" :as="Link" :href="active.request.module_url" size="sm" variant="outline">
                        <ExternalLink class="h-4 w-4" aria-hidden="true" />{{ active.request.module_label }}
                    </Button>
                    <Button v-if="printUrl" :as="'a'" :href="printUrl" size="sm" variant="outline">
                        <Printer class="h-4 w-4" aria-hidden="true" />Document
                    </Button>
                    <Button v-if="canChange" type="button" size="sm" variant="white-outline" @click="changeOpen = true">
                        <Pencil class="h-4 w-4" aria-hidden="true" />Changer de conduite
                    </Button>
                </div>
            </div>
        </div>

        <!-- Le choix. Rien n'est présélectionné : aucune conduite n'est
             décidée à la place du médecin. -->
        <div v-else-if="!closed">
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3" role="radiogroup" aria-label="Conduite à tenir">
                <button
                    v-for="type in types"
                    :key="type.value"
                    type="button"
                    role="radio"
                    :aria-checked="form.type === type.value"
                    :disabled="disabled"
                    :class="[
                        'flex items-start gap-3 rounded-lg border p-3 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-60',
                        form.type === type.value
                            ? 'border-primary bg-primary/10 ring-1 ring-primary/30'
                            : 'border-border bg-card hover:border-primary/40 hover:bg-accent',
                    ]"
                    @click="choose(type.value)"
                >
                    <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md', form.type === type.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground']">
                        <component :is="ICONS[type.value] ?? CircleCheck" class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-foreground">{{ type.label }}</span>
                        <span class="mt-0.5 block text-[11px] leading-4 text-muted-foreground">{{ DECISION_HINTS[type.value] }}</span>
                    </span>
                </button>
            </div>
            <p v-if="!types.length" class="text-xs text-muted-foreground">Aucune conduite ne vous est ouverte sur ce passage : votre compte n’en a pas le droit.</p>
            <FormError class="mt-2" :message="error('type')" />

            <!-- Ce qui précise la conduite choisie — et elle seule. -->
            <div v-if="form.type" class="mt-4 space-y-4 rounded-lg border border-border bg-muted/30 p-4">
                <!-- Sortie médicale -->
                <template v-if="form.type === 'DISCHARGE'">
                    <ClinicalSegmentedChoice
                        v-model="form.discharge_type"
                        name="closure_discharge_type"
                        label="Type de sortie"
                        :options="dischargeTypeOptions"
                        :clearable="false"
                        :disabled="disabled"
                    />
                    <FormError :message="error('discharge_type')" />

                    <p v-if="isDeceased" class="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                        <Skull class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        L’heure, le lieu et les causes du décès s’établissent ensuite au registre des décès, où la clôture vous conduit.
                    </p>

                    <div v-else class="rounded-md border border-border bg-card">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-start"
                            :aria-expanded="dischargeDetailsOpen"
                            @click="dischargeDetailsOpen = !dischargeDetailsOpen"
                        >
                            <span class="text-xs font-semibold text-foreground">
                                Précisions de sortie
                                <span class="font-normal text-muted-foreground">(facultatif{{ dischargeDetailsCount ? ` · ${dischargeDetailsCount} renseignée${dischargeDetailsCount > 1 ? 's' : ''}` : '' }})</span>
                            </span>
                            <component :is="dischargeDetailsOpen ? ChevronUp : ChevronDown" class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                        </button>
                        <div v-if="dischargeDetailsOpen" class="space-y-4 border-t border-border p-3">
                            <ClinicalSegmentedChoice
                                v-model="form.patient_condition"
                                name="closure_patient_condition"
                                label="État du patient"
                                :options="DISCHARGE_CONDITIONS"
                                :disabled="disabled"
                            />
                            <div class="grid gap-4 lg:grid-cols-2">
                                <FormField label="Traitement de sortie" hint="repris de l’ordonnance" :error="error('discharge_prescription')">
                                    <Textarea v-model="form.discharge_prescription" rows="3" :disabled="disabled" />
                                </FormField>
                                <FormField label="Conseils et surveillance" :error="error('recommendations')">
                                    <Textarea v-model="form.recommendations" rows="3" :disabled="disabled" placeholder="Repos, hydratation, reconsulter en cas de fièvre…" />
                                </FormField>
                            </div>
                            <FormField label="Contrôle" as="div" :error="error('follow_up_at')">
                                <div class="flex flex-wrap items-center gap-2">
                                    <DatePicker v-model="form.follow_up_at" class="w-44" :min="localToday()" :disabled="disabled" />
                                    <button
                                        v-for="option in FOLLOW_UPS"
                                        :key="option.days"
                                        type="button"
                                        :disabled="disabled"
                                        class="rounded-md border border-border bg-card px-2.5 py-1.5 text-[11px] font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                                        @click="setFollowUp(option.days)"
                                    >{{ option.label }}</button>
                                    <button
                                        v-if="form.follow_up_at"
                                        type="button"
                                        class="text-[11px] font-semibold text-muted-foreground underline underline-offset-2 hover:text-foreground"
                                        @click="form.follow_up_at = ''"
                                    >Aucun contrôle</button>
                                </div>
                            </FormField>
                        </div>
                    </div>
                </template>

                <!-- Chirurgie : l'intervention, sans laquelle le bloc ne peut rien programmer. -->
                <FormField v-if="form.type === 'SURGERY'" label="Intervention envisagée" required :error="error('catalog_item_uuid')">
                    <Select v-model="form.catalog_item_uuid" :options="surgeryOptions" :icon="Scissors" placeholder="Choisir l’intervention…" :disabled="disabled" />
                </FormField>

                <!-- Transfert : un site de la clinique, un autre établissement, ou plus tard. -->
                <div v-if="form.type === 'REFERRAL'" class="grid gap-3 lg:grid-cols-2">
                    <FormField label="Établissement destinataire" hint="facultatif" as="div" :error="error('facility')">
                        <Select v-model="facilityChoice" :options="facilityOptions" :icon="Ambulance" :disabled="disabled" />
                    </FormField>
                    <FormField v-if="facilityChoice === '__OTHER__'" label="Nom de l’établissement" required>
                        <Input v-model="otherFacility" :disabled="disabled" placeholder="CHU, clinique…" />
                    </FormField>
                </div>

                <template v-if="isServiceDecision(form.type)">
                    <ClinicalSegmentedChoice
                        v-model="form.priority"
                        name="closure_priority"
                        label="Priorité"
                        :options="priorityOptions"
                        :clearable="false"
                        :disabled="disabled"
                    />
                    <FormField label="Consignes pour le service" hint="facultatif" :error="error('notes')">
                        <Textarea v-model="form.notes" rows="2" :disabled="disabled" />
                    </FormField>
                    <p class="flex items-start gap-2 text-[11px] leading-4 text-muted-foreground">
                        <Info class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        Motif, diagnostic, résumé clinique et traitements partent repris du dossier : rien à ressaisir.
                    </p>
                </template>

                <p v-if="form.type === 'CONTINUED_HOSPITALIZATION'" class="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                    <BedDouble class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    La visite se conclut ; le patient garde son lit et son séjour continue.
                </p>
            </div>
        </div>

        <!-- Changer de conduite reste tracé, replié. -->
        <div v-if="history.length" class="mt-3">
            <button
                type="button"
                class="flex items-center gap-2 text-[11px] text-muted-foreground hover:text-foreground"
                :aria-expanded="showHistory"
                @click="showHistory = !showHistory"
            >
                <History class="h-3.5 w-3.5" aria-hidden="true" />
                {{ history.length }} conduite{{ history.length > 1 ? 's' : '' }} annulée{{ history.length > 1 ? 's' : '' }}
                <component :is="showHistory ? ChevronUp : ChevronDown" class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <ul v-if="showHistory" class="mt-1.5 max-h-36 divide-y divide-border overflow-y-auto rounded-md border border-border">
                <li v-for="entry in [...history].reverse()" :key="entry.uuid" class="flex items-center gap-2 px-2.5 py-1.5 text-[11px] text-muted-foreground" :title="entry.cancellation_reason ?? undefined">
                    <span class="min-w-0 flex-1 truncate line-through">{{ entry.type_label }}</span>
                    <span class="shrink-0 tabular-nums">{{ formatDateTime(entry.cancelled_at) }}<template v-if="entry.cancelled_by"> · {{ entry.cancelled_by }}</template></span>
                </li>
            </ul>
        </div>

        <!-- Une demande déjà partie ne se remplace pas d'un clic : la changer
             l'annule (ADR-084), et le service qui l'avait reçue la perd. -->
        <Dialog
            :open="changeOpen"
            title="Changer de conduite ?"
            :description="active ? `« ${active.type_label} » est déjà transmise.` : ''"
            :dismissible="false"
            close-label="Garder cette conduite"
            @update:open="changeOpen = $event"
        >
            <p class="text-sm leading-6 text-foreground">
                La demande actuelle sera annulée — jamais effacée : elle reste tracée avec votre nom et l’heure.
                Vous choisirez ensuite une autre conduite, qui partira à la clôture.
            </p>
            <p class="mt-2 text-xs leading-5 text-muted-foreground">Si le service l’a déjà prise en charge, l’annulation est refusée et le message vous le dit.</p>
            <FormError class="mt-2" :message="changeForm.errors.orientation || changeForm.errors.type" />

            <template #footer>
                <Button type="button" variant="outline" :disabled="changeForm.processing" @click="changeOpen = false">Garder cette conduite</Button>
                <Button type="button" variant="warning" :disabled="changeForm.processing" @click="confirmChange">
                    <Pencil class="h-4 w-4" aria-hidden="true" />Annuler la demande et changer
                </Button>
            </template>
        </Dialog>
    </div>
</template>
