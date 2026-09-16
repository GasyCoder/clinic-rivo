<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Activity,
    Baby,
    CalendarDays,
    Check,
    CircleCheck,
    ClipboardList,
    FileText,
    HeartPulse,
    Lock,
    Plus,
    Save,
    Scissors,
    SquareArrowOutUpRight,
    TriangleAlert,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClinicalPatientHeader from '@/Components/Clinical/ClinicalPatientHeader.vue';
import VitalSignsStrip from '@/Components/Clinical/VitalSignsStrip.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

const props = defineProps({
    orientation: Object,
    record: Object,
    procedureCatalog: Array,
    capabilities: Object,
    /** Projection partagée des constantes du passage (ADR-054), lecture seule. */
    careRecord: { type: Object, default: null },
    careRecordUrl: { type: String, default: null },
    allergies: { type: Array, default: () => [] },
});

const patient = computed(() => props.orientation.episode.patient);
const episode = computed(() => props.orientation.episode);
const readOnly = computed(() => ! props.capabilities.can_edit);

/** Cinq au maximum : `UpdateMaternityRecordRequest` refuse au-delà. */
const MAX_NEWBORNS = 5;

const form = useForm({
    obstetric_context: props.record?.obstetric_context ?? '',
    pregnancy_data: {
        gravidity: props.record?.pregnancy_data?.gravidity ?? '',
        parity: props.record?.pregnancy_data?.parity ?? '',
        last_menstrual_period: props.record?.pregnancy_data?.last_menstrual_period ?? '',
        estimated_due_date: props.record?.pregnancy_data?.estimated_due_date ?? '',
        risk_factors: props.record?.pregnancy_data?.risk_factors ?? '',
    },
    prenatal_data: {
        gestational_age_weeks: props.record?.prenatal_data?.gestational_age_weeks ?? '',
        fundal_height_cm: props.record?.prenatal_data?.fundal_height_cm ?? '',
        fetal_heart_rate: props.record?.prenatal_data?.fetal_heart_rate ?? '',
        notes: props.record?.prenatal_data?.notes ?? '',
    },
    labor_data: {
        started_at: props.record?.labor_data?.started_at ?? '',
        membranes_status: props.record?.labor_data?.membranes_status ?? 'UNKNOWN',
        cervical_dilation_cm: props.record?.labor_data?.cervical_dilation_cm ?? '',
        contractions: props.record?.labor_data?.contractions ?? '',
        surveillance_notes: props.record?.labor_data?.surveillance_notes ?? '',
    },
    delivery_data: {
        occurred_at: props.record?.delivery_data?.occurred_at ?? '',
        mode: props.record?.delivery_data?.mode ?? '',
        placenta_status: props.record?.delivery_data?.placenta_status ?? '',
        complications: props.record?.delivery_data?.complications ?? '',
    },
    newborn_data: {
        newborns: props.record?.newborn_data?.newborns?.length
            ? props.record.newborn_data.newborns
            : [{ sex: '', birth_weight_g: '', condition: '', apgar: '' }],
    },
    maternal_care_notes: props.record?.maternal_care_notes ?? '',
    baby_care_notes: props.record?.baby_care_notes ?? '',
    observations: props.record?.observations ?? '',
    transmission_notes: props.record?.transmission_notes ?? '',
});

const procedureForm = useForm({ catalog_item_uuid: '', quantity: 1, notes: '' });
const cesareanForm = useForm({ type: 'SIMPLE', indication: '' });
const completeForm = useForm({});

const confirmingCesarean = ref(false);
const confirmingComplete = ref(false);

const hasValue = (value) => {
    if (value === null || value === undefined || value === '') return false;
    if (Array.isArray(value)) return value.some(hasValue);
    if (typeof value === 'object') return Object.values(value).some(hasValue);

    return true;
};

/**
 * Un point vert par onglet déjà renseigné.
 *
 * Six sections dont on ne voyait que celle ouverte : il fallait toutes les
 * parcourir pour savoir où le dossier en était, et quoi reprendre après une
 * interruption au chevet de la patiente.
 */
const sectionFilled = {
    context: () => hasValue(form.obstetric_context) || hasValue(form.pregnancy_data),
    prenatal: () => hasValue(form.prenatal_data),
    labor: () => hasValue({ ...form.labor_data, membranes_status: form.labor_data.membranes_status === 'UNKNOWN' ? '' : form.labor_data.membranes_status }),
    delivery: () => hasValue(form.delivery_data),
    newborn: () => hasValue(form.newborn_data) || hasValue(form.maternal_care_notes) || hasValue(form.baby_care_notes),
    procedures: () => Boolean(props.record?.procedures?.length) || hasValue(form.observations) || hasValue(form.transmission_notes),
};

const sections = computed(() => [
    { key: 'context', label: 'Contexte', icon: FileText, visible: true },
    { key: 'prenatal', label: 'Grossesse & prénatal', icon: CalendarDays, visible: props.capabilities.can_prenatal || hasValue(props.record?.prenatal_data) },
    { key: 'labor', label: 'Travail', icon: Activity, visible: props.capabilities.can_labor || hasValue(props.record?.labor_data) },
    { key: 'delivery', label: 'Accouchement', icon: HeartPulse, visible: props.capabilities.can_delivery || hasValue(props.record?.delivery_data) },
    { key: 'newborn', label: 'Nouveau-né', icon: Baby, visible: props.capabilities.can_newborn || hasValue(props.record?.newborn_data) },
    { key: 'procedures', label: 'Actes & transmission', icon: ClipboardList, visible: true },
].filter((section) => section.visible).map((section) => ({ ...section, filled: sectionFilled[section.key]() })));

const activeSection = ref('context');
const currentSection = computed(() => sections.value.find((section) => section.key === activeSection.value));

const membranesOptions = [
    { value: 'UNKNOWN', label: 'Non précisé' },
    { value: 'INTACT', label: 'Intactes' },
    { value: 'RUPTURED', label: 'Rompues' },
];
const deliveryModeOptions = [
    { value: '', label: 'Non renseignée' },
    { value: 'VAGINAL', label: 'Voie basse' },
    { value: 'INSTRUMENTAL', label: 'Instrumental' },
    { value: 'CESAREAN', label: 'Césarienne réalisée en Chirurgie' },
];
const newbornSexOptions = [
    { value: '', label: 'Non renseigné' },
    { value: 'F', label: 'Féminin' },
    { value: 'M', label: 'Masculin' },
    { value: 'UNDETERMINED', label: 'Indéterminé' },
];
const cesareanTypeOptions = [
    { value: 'SIMPLE', label: 'Simple' },
    { value: 'TWIN', label: 'Gémellaire' },
];
const procedureOptions = computed(() => [
    { value: '', label: 'Choisir un acte Maternité' },
    ...(props.procedureCatalog ?? []).map((item) => ({ value: item.uuid, label: item.name })),
]);

/**
 * Un bloc que le compte n'a pas le droit d'écrire ne part pas.
 *
 * `UpdateMaternityRecordRequest` les déclare `prohibited` sans la permission
 * correspondante, et « prohibited » refuse un tableau non vide — or le
 * formulaire envoyait toujours les cinq blocs avec leurs valeurs par défaut.
 * Une sage-femme qui avait `maternity.update` sans `maternity.labor.manage`
 * ne pouvait donc rien enregistrer du tout, sur une erreur illisible
 * (« Le champ labor data est interdit »). Le serveur garde sa règle : c'est
 * lui la protection, ceci n'envoie simplement plus ce qu'il refuse.
 */
const save = () => form.transform((data) => {
    const payload = { ...data };

    if (! props.capabilities.can_prenatal) delete payload.prenatal_data;
    if (! props.capabilities.can_labor) delete payload.labor_data;
    if (! props.capabilities.can_delivery) delete payload.delivery_data;
    if (! props.capabilities.can_newborn) {
        delete payload.newborn_data;
        delete payload.baby_care_notes;
    }

    return payload;
}).put(`/maternity/orientations/${props.orientation.uuid}/record`, { preserveScroll: true });

const addNewborn = () => {
    if (form.newborn_data.newborns.length >= MAX_NEWBORNS) return;
    form.newborn_data.newborns.push({ sex: '', birth_weight_g: '', condition: '', apgar: '' });
};

const saveProcedure = () => procedureForm.post(
    `/maternity/orientations/${props.orientation.uuid}/procedures`,
    { preserveScroll: true, onSuccess: () => procedureForm.reset() },
);

const requestCesarean = () => cesareanForm.post(
    `/maternity/orientations/${props.orientation.uuid}/cesarean`,
    {
        preserveScroll: true,
        onSuccess: () => { cesareanForm.reset('indication'); confirmingCesarean.value = false; },
    },
);

const completeCare = () => completeForm.post(
    `/maternity/orientations/${props.orientation.uuid}/complete`,
    { preserveScroll: true, onSuccess: () => { confirmingComplete.value = false; } },
);

const firstError = (bag) => Object.values(bag)[0];

/**
 * Les constantes du passage viennent des Soins, relevées une seule fois et
 * lues ici comme en Médecine et au bloc (ADR-054). Maternité ne les ressaisit
 * pas : une seconde version d'une mesure que personne n'a prise deux fois
 * finirait par contredire la première (ADR-077).
 */
const careBloodPressure = computed(() => {
    const systolic = props.careRecord?.blood_pressure_systolic;
    const diastolic = props.careRecord?.blood_pressure_diastolic;

    return systolic && diastolic ? `${systolic}/${diastolic}` : null;
});
</script>

<template>
    <Head title="Dossier Maternité" />

    <div class="w-full space-y-4">
        <ClinicalPatientHeader
            :patient="patient"
            :episode="episode"
            :reason="orientation.reason"
            back-href="/maternity"
            back-label="File Maternité"
        />

        <!-- D'abord les valeurs relevées à l'arrivée, puis ce qu'elles
             impliquent : même bandeau qu'en consultation Médecine. -->
        <VitalSignsStrip
            v-if="careRecord"
            :care-record="careRecord"
            :blood-pressure="careBloodPressure"
            :allergies="allergies"
            :recorded-at="careRecord.updated_at ?? careRecord.created_at"
        />

        <!-- Sans passage par les Soins, il n'y a aucune constante à montrer :
             on le dit plutôt que d'afficher des tirets qui se liraient
             « normal ». -->
        <Card v-else class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <p class="text-xs text-muted-foreground">Aucune constante relevée pour ce passage : la patiente n’est pas passée par les Soins, ou votre compte ne peut pas les consulter.</p>
            <Button v-if="careRecordUrl" :as="Link" :href="careRecordUrl" size="sm" variant="outline">
                <SquareArrowOutUpRight class="h-4 w-4" />Ouvrir la fiche de soins
            </Button>
        </Card>

        <div v-if="careRecord && careRecordUrl" class="flex justify-end">
            <!-- Corriger une constante se fait sur la fiche qui la porte, avec
                 ses propres règles (ADR-092) — jamais dans un second
                 formulaire qui finirait par diverger. -->
            <Button :as="Link" :href="careRecordUrl" size="sm" variant="ghost">
                <SquareArrowOutUpRight class="h-4 w-4" />Ouvrir la fiche de soins complète
            </Button>
        </div>

        <!-- Lecture seule : le dossier s'affichait éditable et seul le bouton
             « Enregistrer » disparaissait, si bien qu'on pouvait saisir un
             relevé entier avant de découvrir qu'il n'irait nulle part. -->
        <Card v-if="readOnly" class="flex items-start gap-3 border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20">
            <Lock class="mt-0.5 h-4.5 w-4.5 shrink-0 text-amber-600 dark:text-amber-300" />
            <div>
                <p class="text-sm font-bold text-amber-900 dark:text-amber-100">Dossier en lecture seule</p>
                <p class="mt-0.5 text-xs leading-5 text-amber-800 dark:text-amber-200">
                    <template v-if="orientation.status !== 'IN_PROGRESS'">La prise en charge Maternité de ce passage est {{ orientation.status_label.toLowerCase() }} : le dossier n'est plus modifiable.</template>
                    <template v-else>Votre compte ne dispose pas du droit de modifier ce dossier.</template>
                </p>
            </div>
        </Card>

        <!-- Une pastille par section déjà renseignée : on voit d'un coup
             d'œil où le dossier en est, sans ouvrir les six onglets. -->
        <Card class="overflow-x-auto p-1.5">
            <div class="flex min-w-max gap-1">
                <button
                    v-for="section in sections"
                    :key="section.key"
                    type="button"
                    :class="cn(
                        'flex h-10 items-center gap-2 rounded-lg px-4 text-xs font-bold transition-colors',
                        activeSection === section.key ? 'bg-accent text-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                    )"
                    :aria-current="activeSection === section.key ? 'page' : undefined"
                    @click="activeSection = section.key"
                >
                    <component :is="section.icon" class="h-4 w-4" />
                    {{ section.label }}
                    <CircleCheck v-if="section.filled" class="h-3.5 w-3.5 text-emerald-500" aria-label="Section renseignée" />
                </button>
            </div>
        </Card>

        <form @submit.prevent="save">
            <Card class="overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-4">
                    <div>
                        <h2 class="text-sm font-bold text-foreground">{{ currentSection?.label }}</h2>
                        <p class="mt-1 text-xs text-muted-foreground">Les données restent rattachées au passage {{ episode.episode_number }}.</p>
                    </div>
                    <Badge v-if="currentSection?.filled" variant="success">Renseignée</Badge>
                </header>

                <fieldset class="min-w-0 space-y-5 p-5" :disabled="readOnly">
                    <template v-if="activeSection === 'context'">
                        <FormField as="div" label="Motif et contexte obstétrical" :error="form.errors.obstetric_context">
                            <Textarea v-model="form.obstetric_context" :rows="6" placeholder="Motif, antécédents obstétricaux et contexte clinique utile…" />
                        </FormField>
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <FormField label="Gestité" :error="form.errors['pregnancy_data.gravidity']">
                                <Input v-model="form.pregnancy_data.gravidity" type="number" min="0" max="30" />
                            </FormField>
                            <FormField label="Parité" :error="form.errors['pregnancy_data.parity']">
                                <Input v-model="form.pregnancy_data.parity" type="number" min="0" max="30" />
                            </FormField>
                            <FormField label="Dernières règles" :error="form.errors['pregnancy_data.last_menstrual_period']">
                                <Input v-model="form.pregnancy_data.last_menstrual_period" type="date" />
                            </FormField>
                            <FormField label="Terme estimé" :error="form.errors['pregnancy_data.estimated_due_date']">
                                <Input v-model="form.pregnancy_data.estimated_due_date" type="date" />
                            </FormField>
                        </div>
                        <FormField as="div" label="Facteurs de risque" :error="form.errors['pregnancy_data.risk_factors']">
                            <Textarea v-model="form.pregnancy_data.risk_factors" :rows="3" />
                        </FormField>
                    </template>

                    <template v-else-if="activeSection === 'prenatal'">
                        <div class="grid gap-4 md:grid-cols-3">
                            <FormField label="Terme (semaines)" :error="form.errors['prenatal_data.gestational_age_weeks']">
                                <Input v-model="form.prenatal_data.gestational_age_weeks" type="number" min="0" max="45" />
                            </FormField>
                            <FormField label="Hauteur utérine (cm)" :error="form.errors['prenatal_data.fundal_height_cm']">
                                <Input v-model="form.prenatal_data.fundal_height_cm" type="number" min="0" max="60" step="0.1" />
                            </FormField>
                            <FormField label="Rythme cardiaque fœtal (bpm)" :error="form.errors['prenatal_data.fetal_heart_rate']">
                                <Input v-model="form.prenatal_data.fetal_heart_rate" type="number" min="40" max="250" />
                            </FormField>
                        </div>
                        <FormField as="div" label="Constatations prénatales" :error="form.errors['prenatal_data.notes']">
                            <Textarea v-model="form.prenatal_data.notes" :rows="7" />
                        </FormField>
                    </template>

                    <template v-else-if="activeSection === 'labor'">
                        <div class="grid gap-4 md:grid-cols-3">
                            <FormField label="Début du travail" :error="form.errors['labor_data.started_at']">
                                <Input v-model="form.labor_data.started_at" type="datetime-local" />
                            </FormField>
                            <FormField label="Membranes" :error="form.errors['labor_data.membranes_status']">
                                <Select v-model="form.labor_data.membranes_status" class="h-10 w-full min-w-0" :options="membranesOptions" />
                            </FormField>
                            <FormField label="Dilatation (cm)" :error="form.errors['labor_data.cervical_dilation_cm']">
                                <Input v-model="form.labor_data.cervical_dilation_cm" type="number" min="0" max="10" step="0.1" />
                            </FormField>
                        </div>
                        <FormField as="div" label="Contractions" :error="form.errors['labor_data.contractions']">
                            <Textarea v-model="form.labor_data.contractions" :rows="3" />
                        </FormField>
                        <FormField as="div" label="Surveillance du travail" :error="form.errors['labor_data.surveillance_notes']">
                            <Textarea v-model="form.labor_data.surveillance_notes" :rows="6" />
                        </FormField>
                    </template>

                    <template v-else-if="activeSection === 'delivery'">
                        <div class="grid gap-4 md:grid-cols-2">
                            <FormField label="Date et heure" :error="form.errors['delivery_data.occurred_at']">
                                <Input v-model="form.delivery_data.occurred_at" type="datetime-local" />
                            </FormField>
                            <FormField label="Voie d’accouchement" :error="form.errors['delivery_data.mode']">
                                <Select v-model="form.delivery_data.mode" class="h-10 w-full min-w-0" :options="deliveryModeOptions" />
                            </FormField>
                        </div>
                        <FormField as="div" label="Placenta" :error="form.errors['delivery_data.placenta_status']">
                            <Textarea v-model="form.delivery_data.placenta_status" :rows="3" />
                        </FormField>
                        <FormField as="div" label="Complications" :error="form.errors['delivery_data.complications']">
                            <Textarea v-model="form.delivery_data.complications" :rows="4" />
                        </FormField>

                        <!-- Décider la césarienne crée une demande Chirurgie sur
                             ce même passage (ADR-067) : ce n'est pas un champ du
                             dossier, d'où le bloc séparé et la confirmation. -->
                        <div v-if="capabilities.can_delivery && orientation.status === 'IN_PROGRESS'" class="rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20">
                            <div class="flex items-start gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300"><Scissors class="h-4.5 w-4.5" /></span>
                                <div>
                                    <h3 class="text-sm font-bold text-foreground">Décision de césarienne</h3>
                                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Crée une demande Chirurgie sur ce même passage. Aucune intervention n’est créée dans Maternité — elle reste sous le contrôle du bloc.</p>
                                </div>
                            </div>
                            <div class="mt-4 grid gap-3 lg:grid-cols-[180px_minmax(0,1fr)_auto]">
                                <Select v-model="cesareanForm.type" class="h-10 w-full min-w-0" :options="cesareanTypeOptions" aria-label="Type de césarienne" />
                                <Input v-model="cesareanForm.indication" placeholder="Indication clinique obligatoire" />
                                <Button type="button" variant="warning" :disabled="! cesareanForm.indication || cesareanForm.processing" @click="confirmingCesarean = true">
                                    <Scissors class="h-4 w-4" />Transmettre à Chirurgie
                                </Button>
                            </div>
                            <FormError v-if="firstError(cesareanForm.errors)">{{ firstError(cesareanForm.errors) }}</FormError>
                        </div>
                    </template>

                    <template v-else-if="activeSection === 'newborn'">
                        <div class="space-y-3">
                            <Card v-for="(newborn, index) in form.newborn_data.newborns" :key="index" class="p-4">
                                <div class="mb-3 flex items-center justify-between">
                                    <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Nouveau-né {{ index + 1 }}</h3>
                                    <Button
                                        v-if="form.newborn_data.newborns.length > 1"
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        class="h-7 px-2 text-[11px] text-destructive hover:text-destructive"
                                        @click="form.newborn_data.newborns.splice(index, 1)"
                                    ><X class="h-3.5 w-3.5" />Retirer</Button>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <FormField label="Sexe" :error="form.errors[`newborn_data.newborns.${index}.sex`]">
                                        <Select v-model="newborn.sex" class="h-10 w-full min-w-0" :options="newbornSexOptions" />
                                    </FormField>
                                    <FormField label="Poids naissance (g)" :error="form.errors[`newborn_data.newborns.${index}.birth_weight_g`]">
                                        <Input v-model="newborn.birth_weight_g" type="number" min="100" max="8000" />
                                    </FormField>
                                    <FormField label="Apgar" :error="form.errors[`newborn_data.newborns.${index}.apgar`]">
                                        <Input v-model="newborn.apgar" type="number" min="0" max="10" />
                                    </FormField>
                                    <FormField label="État du nouveau-né" :error="form.errors[`newborn_data.newborns.${index}.condition`]">
                                        <Input v-model="newborn.condition" />
                                    </FormField>
                                </div>
                            </Card>

                            <div class="flex flex-wrap items-center gap-3">
                                <Button type="button" size="sm" variant="outline" :disabled="form.newborn_data.newborns.length >= MAX_NEWBORNS" @click="addNewborn">
                                    <Plus class="h-4 w-4" />Ajouter un nouveau-né
                                </Button>
                                <span class="text-xs text-muted-foreground">{{ form.newborn_data.newborns.length }} / {{ MAX_NEWBORNS }} — au-delà, le dossier serait refusé à l’enregistrement.</span>
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <FormField as="div" label="Soins mère" :error="form.errors.maternal_care_notes">
                                <Textarea v-model="form.maternal_care_notes" :rows="5" />
                            </FormField>
                            <FormField as="div" label="Soins bébé" :error="form.errors.baby_care_notes">
                                <Textarea v-model="form.baby_care_notes" :rows="5" />
                            </FormField>
                        </div>
                    </template>

                    <template v-else-if="activeSection === 'procedures'">
                        <div v-if="capabilities.can_procedures && orientation.status === 'IN_PROGRESS'" class="rounded-xl border border-border bg-muted/40 p-4">
                            <p class="mb-3 text-xs font-bold uppercase tracking-wide text-muted-foreground">Enregistrer un acte réalisé</p>
                            <div class="grid gap-3 lg:grid-cols-[minmax(220px,1fr)_110px_minmax(240px,1fr)_auto]">
                                <Select v-model="procedureForm.catalog_item_uuid" class="h-10 w-full min-w-0" :options="procedureOptions" aria-label="Acte Maternité" />
                                <Input v-model="procedureForm.quantity" type="number" min="0.01" step="0.01" aria-label="Quantité" />
                                <Input v-model="procedureForm.notes" placeholder="Précision facultative" />
                                <Button type="button" :disabled="! procedureForm.catalog_item_uuid || procedureForm.processing" @click="saveProcedure">
                                    <Check class="h-4 w-4" />Enregistrer l’acte
                                </Button>
                            </div>
                            <FormError v-if="firstError(procedureForm.errors)">{{ firstError(procedureForm.errors) }}</FormError>
                        </div>

                        <div class="overflow-hidden rounded-xl border border-border">
                            <p class="border-b border-border bg-muted px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Actes réalisés</p>
                            <ul v-if="record?.procedures?.length" class="divide-y divide-border">
                                <li v-for="procedure in record.procedures" :key="procedure.uuid" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div>
                                        <p class="text-sm font-bold text-foreground">{{ procedure.procedure_name }} <span class="font-normal text-muted-foreground">× {{ procedure.quantity }}</span></p>
                                        <p class="mt-1 text-xs text-muted-foreground">Par {{ procedure.performer?.name }} · {{ procedure.notes || 'Sans précision' }}</p>
                                    </div>
                                </li>
                            </ul>
                            <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">Aucun acte Maternité enregistré.</p>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <FormField as="div" label="Observations" :error="form.errors.observations">
                                <Textarea v-model="form.observations" :rows="5" />
                            </FormField>
                            <FormField as="div" label="Transmission / sortie du module" :error="form.errors.transmission_notes">
                                <Textarea v-model="form.transmission_notes" :rows="5" />
                            </FormField>
                        </div>
                    </template>
                </fieldset>

                <footer class="flex flex-col gap-3 border-t border-border bg-muted/40 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <span class="text-xs text-muted-foreground">{{ record?.updated_at ? 'Dernière mise à jour enregistrée' : 'Dossier à renseigner' }}</span>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="capabilities.can_edit" type="submit" variant="primary" :disabled="form.processing">
                            <Save class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : 'Enregistrer le dossier' }}
                        </Button>
                        <Button v-if="capabilities.can_complete && record" type="button" variant="success" @click="confirmingComplete = true">
                            <Check class="h-4 w-4" />Terminer la prise en charge
                        </Button>
                    </div>
                </footer>
            </Card>

            <FormError v-if="firstError(form.errors)" class="mt-2">{{ firstError(form.errors) }}</FormError>
        </form>

        <Dialog
            :open="confirmingCesarean"
            title="Transmettre la césarienne à Chirurgie ?"
            description="Une demande est créée sur ce même passage. La programmation et l’intervention restent sous le contrôle du bloc opératoire ; Maternité n’enregistre aucun acte chirurgical."
            @update:open="confirmingCesarean = $event"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/35 dark:text-amber-300"><Scissors class="h-5 w-5" /></span>
            </template>

            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Patiente</dt><dd class="font-semibold text-foreground">{{ patient.first_name }} {{ patient.last_name }} · {{ episode.episode_number }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Type</dt><dd class="font-semibold text-foreground">{{ cesareanTypeOptions.find((option) => option.value === cesareanForm.type)?.label }}</dd></div>
                <div class="flex flex-col gap-1"><dt class="text-muted-foreground">Indication</dt><dd class="rounded-md bg-muted px-3 py-2 text-foreground">{{ cesareanForm.indication }}</dd></div>
            </dl>

            <template #footer>
                <Button type="button" variant="outline" :disabled="cesareanForm.processing" @click="confirmingCesarean = false">Annuler</Button>
                <Button type="button" variant="warning" :disabled="cesareanForm.processing" @click="requestCesarean">
                    <Scissors class="h-4 w-4" />{{ cesareanForm.processing ? 'Transmission…' : 'Transmettre à Chirurgie' }}
                </Button>
            </template>
        </Dialog>

        <Dialog
            :open="confirmingComplete"
            title="Terminer la prise en charge Maternité ?"
            description="Le dossier passe en lecture seule et le passage quitte la file Maternité. Les données déjà enregistrées sont conservées."
            @update:open="confirmingComplete = $event"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950/35 dark:text-emerald-300"><Check class="h-5 w-5" /></span>
            </template>

            <!-- Terminer rend le dossier non modifiable : ce qui n'est pas
                 encore enregistré doit l'être avant, pas après. -->
            <p v-if="form.isDirty" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Le dossier porte des modifications non enregistrées. Enregistrez-les d’abord : terminer maintenant les perdrait.
            </p>
            <p v-else class="text-sm text-muted-foreground">Le dossier de {{ patient.first_name }} {{ patient.last_name }} sera clos pour le passage {{ episode.episode_number }}.</p>

            <template #footer>
                <Button type="button" variant="outline" :disabled="completeForm.processing" @click="confirmingComplete = false">Annuler</Button>
                <Button type="button" variant="success" :disabled="completeForm.processing || form.isDirty" @click="completeCare">
                    <Check class="h-4 w-4" />{{ completeForm.processing ? 'Clôture…' : 'Terminer la prise en charge' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
