<script setup>
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import CardBody from '@/Components/Shadcn/CardContent.vue';
import FormError from '@/Components/UI/FormError.vue';
import { Activity, ArrowRight, Baby, Bone, Gauge, Heart, Palette, Ruler, Smile, Puzzle, Brain, Calendar, Cigarette, Check, ClipboardList, Clock, Droplets, FileText, Hash, HeartPulse, Info, NotebookPen, Pill, Scissors, Stethoscope, Syringe, TriangleAlert, UserCheck, Venus, Wind, Wine } from 'lucide-vue-next';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import AnesthesiaStepHeader from '@/Components/Surgery/AnesthesiaStepHeader.vue';
import ClinicalSubsection from '@/Components/Surgery/ClinicalSubsection.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import Select from '@/Components/Shadcn/Select.vue';
import TriStateChoice from '@/Components/Surgery/TriStateChoice.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import ClinicalAccordionSection from '@/Components/Surgery/ClinicalAccordionSection.vue';
import { useValidationNavigation } from '@/composables/useValidationNavigation';
import { useAutosave } from '@/composables/useAutosave';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';

const props = defineProps({
    surgicalRequest: Object,
    careSummary: { type: Object, default: null },
    canCreate: Boolean,
    canUpdate: Boolean,
});

const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const record = computed(() => props.surgicalRequest.anesthesia_record ?? null);
const patient = computed(() => props.surgicalRequest.episode?.patient ?? {});

const medicalConditions = [
    ['HYPERTENSION', 'HTA'], ['DIABETES', 'Diabète'], ['JAUNDICE', 'Ictère'],
    ['CONJUNCTIVITIS', 'Conjonctivite'], ['RHINITIS', 'Rhinite'], ['ASTHMA', 'Asthme'],
    ['HEADACHE', 'Céphalée'], ['SEIZURE', 'Convulsion'], ['FEVER', 'Fièvre'],
    ['VERTIGO', 'Vertige'], ['EPIGASTRIC_PAIN', 'Épigastralgie'],
    ['NAUSEA_VOMITING', 'Nausée / vomissement'], ['COUGH', 'Toux'],
    ['ACUTE_PAIN', 'Douleur aiguë'], ['CHRONIC_PAIN', 'Douleur chronique'],
    ['ABDOMINAL_PAIN', 'Douleur abdominale'], ['CHEST_PAIN', 'Douleur thoracique'],
    ['LUMBAR_PAIN', 'Douleur lombaire'],
];

const clinicalDefaults = {
    cardiovascular: '', pulmonary: '', neurological: '', coloration: '',
    venous_access: '', spinal_access: '',
};
const gynecoDefaults = {
    gravida: '', para: '', abortion: '', last_menstrual_period: '',
    contraception: '', contraception_date: '', delivery_route: '', delivery_date: '',
    parity_status: '', obstetric_hemorrhage: '', notes: '',
};
const existing = record.value?.consultation_data ?? {};
const selectValue = (value) => value === null || value === undefined ? '' : String(value);
const form = useForm({
    consultation_data: {
        admission_reason: '', tobacco: '', alcohol: '', other_toxic_exposure: '',
        medical_conditions: [], medical_history_notes: '', cough_duration: '', sputum: '',
        pain_notes: '', anesthetic_history: '', surgical_history: '', anesthetic_incidents: '',
        blood_pressure_systolic: '', blood_pressure_diastolic: '', heart_rate: '',
        oxygen_saturation: '', respiratory_rate: '', temperature_celsius: '',
        weight_kg: '', height_cm: '', mouth_opening: '', mallampati: '',
        thyromental_distance: '', cervical_spine: '', dental_prosthesis: '',
        other_prosthesis: '', last_meal_time: '', last_drink_time: '',
        neuropsychological_status: '',
        ...existing,
        neuropsychological_status: selectValue(existing.neuropsychological_status),
        gyneco_obstetric: {
            ...gynecoDefaults,
            ...(existing.gyneco_obstetric ?? {}),
            contraception: selectValue(existing.gyneco_obstetric?.contraception),
            delivery_route: selectValue(existing.gyneco_obstetric?.delivery_route),
            parity_status: selectValue(existing.gyneco_obstetric?.parity_status),
        },
        clinical_exam: { ...clinicalDefaults, ...(existing.clinical_exam ?? {}) },
    },
});

const contraceptionOptions = [{ value: 'ORAL', label: 'Orale' }, { value: 'INJECTION', label: 'Injection' }];
const deliveryOptions = [{ value: 'VAGINAL', label: 'Voie basse' }, { value: 'CESAREAN', label: 'Césarienne' }];
const parityOptions = [{ value: 'PRIMIPAROUS', label: 'Primipare' }, { value: 'MULTIPAROUS', label: 'Multipare' }];
const neuropsychologicalOptions = [
    { value: 'CALM', label: 'Calme' },
    { value: 'RELAXED', label: 'Détendu(e)' },
    { value: 'ANXIOUS', label: 'Anxieux(se)' },
    { value: 'AGITATED', label: 'Agité(e)' },
];
/** Un appareil, une icône : un repère de lecture, rien n'est déduit du texte. */
const systemFields = [
    { key: 'cardiovascular', label: 'Cardio-vasculaire', icon: Heart },
    { key: 'pulmonary', label: 'Pulmonaire', icon: Wind },
    { key: 'neurological', label: 'Neurologique', icon: Brain },
    { key: 'coloration', label: 'Coloration', icon: Palette },
    { key: 'venous_access', label: 'Abord veineux', icon: Syringe },
    { key: 'spinal_access', label: 'Abord rachidien', icon: Bone },
];
const airwayFields = [
    { key: 'mouth_opening', label: 'Ouverture buccale', icon: Smile },
    { key: 'mallampati', label: 'Mallampati', icon: Gauge },
    { key: 'thyromental_distance', label: 'Distance thyromentonnière', icon: Ruler },
    { key: 'cervical_spine', label: 'Rachis cervical', icon: Bone },
    { key: 'dental_prosthesis', label: 'Prothèse dentaire', icon: Smile },
    { key: 'other_prosthesis', label: 'Autre prothèse', icon: Puzzle },
];
const assessmentLocked = computed(() => Boolean(record.value?.assessment_validated_at));
const canEdit = computed(() => !assessmentLocked.value && (record.value ? props.canUpdate : props.canCreate));
const isFemale = computed(() => String(patient.value.sex ?? '') === 'F');
const activeSection = ref('history');
const formElement = ref(null);
const examErrorPrefixes = [
    'consultation_data.clinical_exam.',
    'consultation_data.blood_pressure_',
    'consultation_data.heart_rate',
    'consultation_data.oxygen_saturation',
    'consultation_data.respiratory_rate',
    'consultation_data.temperature_celsius',
    'consultation_data.weight_kg',
    'consultation_data.height_cm',
    'consultation_data.mouth_opening',
    'consultation_data.mallampati',
    'consultation_data.thyromental_distance',
    'consultation_data.cervical_spine',
    'consultation_data.dental_prosthesis',
    'consultation_data.other_prosthesis',
    'consultation_data.last_meal_time',
    'consultation_data.last_drink_time',
    'consultation_data.neuropsychological_status',
];
const sectionForError = (key) => examErrorPrefixes.some((prefix) => key.startsWith(prefix)) ? 'exam' : 'history';
const {
    errorId, errorMessage, fieldAttrs, focusError, focusFirstError, invalidClass,
} = useValidationNavigation(form, activeSection, sectionForError, formElement);
const hasValue = (value) => Array.isArray(value) ? value.length > 0 : value !== null && value !== undefined && value !== '';
const historyComplete = computed(() => [
    form.consultation_data.admission_reason,
    form.consultation_data.medical_conditions,
    form.consultation_data.medical_history_notes,
    form.consultation_data.anesthetic_history,
    form.consultation_data.surgical_history,
].some(hasValue));
const examComplete = computed(() => [
    ...Object.values(form.consultation_data.clinical_exam),
    form.consultation_data.heart_rate,
    form.consultation_data.oxygen_saturation,
    form.consultation_data.respiratory_rate,
    form.consultation_data.mallampati,
].some(hasValue));

// Plus de bouton « Enregistrer » : le dossier s'enregistre tout seul quelques
// instants après la dernière saisie, par la même route, les mêmes droits et la
// même validation. La première saisie ouvre le dossier (POST), les suivantes
// le complètent (PUT) — exactement ce que faisait le bouton.
const send = (options) => {
    if (record.value) {
        form.put(`${base.value}/anesthesia/${record.value.id}`, options);
        return;
    }

    form.post(`${base.value}/anesthesia`, options);
};
const autosave = useAutosave(form, send, { enabled: () => canEdit.value });
/**
 * « Suivant » : enregistre ce qui reste, puis ouvre la section suivante. Une
 * erreur y amène le regard — jamais pendant la frappe, où l'enregistrement
 * automatique ne doit ni déplacer le curseur ni changer de section.
 */
const next = (nextSection) => autosave.flush(
    () => { activeSection.value = nextSection; },
    (errors) => focusFirstError(errors),
);
</script>

<template>
    <Card class="shadow-sm xl:col-span-12">
        <CardBody class="!p-0">
            <AnesthesiaStepHeader
                :icon="UserCheck"
                :step="1"
                title="Consultation pré-anesthésique"
                description="Deux sous-étapes courtes. Les données déjà enregistrées aux Soins restent consultables au-dessus, sans nouvelle saisie."
                :sections="[{ label: 'Antécédents', complete: historyComplete }, { label: 'Examen clinique', complete: examComplete }]"
                :status="assessmentLocked ? 'VALIDATED' : (!canEdit ? 'READONLY' : null)"
            />

            <form ref="formElement" class="space-y-4 p-4 sm:p-5" @submit.prevent="autosave.flush(undefined, focusFirstError)">
                <ValidationErrorSummary :errors="form.errors" @select="focusError" />
                <fieldset :disabled="!canEdit" class="space-y-3 disabled:opacity-70">
                    <ClinicalAccordionSection
                        :open="activeSection === 'history'"
                        step="1"
                        tone="violet"
                        title="Antécédents"
                        description="Motif, habitudes, antécédents médicaux, anesthésiques et chirurgicaux."
                        :icon="FileText"
                        :complete="historyComplete"
                        @toggle="activeSection = activeSection === 'history' ? '' : 'history'"
                    >
                    <div class="space-y-4">
                    <ClinicalSubsection :icon="ClipboardList" title="Admission et habitudes" description="Motif d’entrée et exposition aux toxiques.">
                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <label class="text-sm font-medium text-muted-foreground">Motif d’entrée
                                <Textarea v-model="form.consultation_data.admission_reason" v-bind="fieldAttrs('consultation_data.admission_reason')" rows="2" :class="invalidClass('consultation_data.admission_reason')" placeholder="Motif clinique de l’admission" />
                                <FormError v-if="errorMessage('consultation_data.admission_reason')" :id="errorId('consultation_data.admission_reason')" :message="errorMessage('consultation_data.admission_reason')" />
                            </label>
                            <div class="flex items-start gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3 text-sm">
                                <Scissors class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                                <div class="min-w-0">
                                    <span class="block text-xs font-medium text-muted-foreground">Intervention prévue</span>
                                    <strong class="mt-0.5 block text-foreground">{{ surgicalRequest.procedure_name }}</strong>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Cigarette class="h-3.5 w-3.5" aria-hidden="true" />Tabac</span><IconInput v-model="form.consultation_data.tobacco" v-bind="fieldAttrs('consultation_data.tobacco')" :icon="Cigarette" size="lg" placeholder="Non / quantité / durée" /><FormError v-if="errorMessage('consultation_data.tobacco')" :id="errorId('consultation_data.tobacco')" :message="errorMessage('consultation_data.tobacco')" /></label>
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Wine class="h-3.5 w-3.5" aria-hidden="true" />Alcool</span><IconInput v-model="form.consultation_data.alcohol" v-bind="fieldAttrs('consultation_data.alcohol')" :icon="Wine" size="lg" placeholder="Non / fréquence" /><FormError v-if="errorMessage('consultation_data.alcohol')" :id="errorId('consultation_data.alcohol')" :message="errorMessage('consultation_data.alcohol')" /></label>
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><TriangleAlert class="h-3.5 w-3.5" aria-hidden="true" />Autre toxique</span><IconInput v-model="form.consultation_data.other_toxic_exposure" v-bind="fieldAttrs('consultation_data.other_toxic_exposure')" :icon="TriangleAlert" size="lg" placeholder="Préciser" /><FormError v-if="errorMessage('consultation_data.other_toxic_exposure')" :id="errorId('consultation_data.other_toxic_exposure')" :message="errorMessage('consultation_data.other_toxic_exposure')" /></label>
                        </div>
                    </ClinicalSubsection>

                    <ClinicalSubsection :icon="FileText" title="Détails des antécédents" body-class="p-0">
                        <div class="grid grid-cols-1 divide-y divide-border lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                            <div class="space-y-4 p-4">
                                <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><HeartPulse class="h-3.5 w-3.5 text-primary" aria-hidden="true" />Médicaux</h4>
                                <div class="grid grid-cols-2 gap-2">
                                    <label
                                        v-for="condition in medicalConditions"
                                        :key="condition[0]"
                                        :class="['flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-xs font-medium transition-colors focus-within:ring-2 focus-within:ring-ring/30', form.consultation_data.medical_conditions.includes(condition[0]) ? 'border-primary/40 bg-primary/10 text-foreground' : 'border-border bg-card text-muted-foreground hover:bg-accent/60']"
                                    >
                                        <input v-model="form.consultation_data.medical_conditions" type="checkbox" :value="condition[0]" class="sr-only" />
                                        <span :class="['grid h-4 w-4 shrink-0 place-items-center rounded border', form.consultation_data.medical_conditions.includes(condition[0]) ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background']" aria-hidden="true">
                                            <Check v-if="form.consultation_data.medical_conditions.includes(condition[0])" class="h-3 w-3" />
                                        </span>
                                        {{ condition[1] }}
                                    </label>
                                </div>
                                <label class="block text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><NotebookPen class="h-3.5 w-3.5" aria-hidden="true" />Autres antécédents ou précisions</span><Textarea v-model="form.consultation_data.medical_history_notes" v-bind="fieldAttrs('consultation_data.medical_history_notes')" rows="3" :class="invalidClass('consultation_data.medical_history_notes')" placeholder="Autres antécédents ou précisions" /><FormError v-if="errorMessage('consultation_data.medical_history_notes')" :id="errorId('consultation_data.medical_history_notes')" :message="errorMessage('consultation_data.medical_history_notes')" /></label>
                                <div class="grid grid-cols-2 gap-2"><label class="block text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Wind class="h-3.5 w-3.5" aria-hidden="true" />Durée de la toux</span><IconInput v-model="form.consultation_data.cough_duration" v-bind="fieldAttrs('consultation_data.cough_duration')" :icon="Clock" placeholder="Ex. 3 jours" /><FormError v-if="errorMessage('consultation_data.cough_duration')" :id="errorId('consultation_data.cough_duration')" :message="errorMessage('consultation_data.cough_duration')" /></label><label class="block text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Droplets class="h-3.5 w-3.5" aria-hidden="true" />Crachat</span><IconInput v-model="form.consultation_data.sputum" v-bind="fieldAttrs('consultation_data.sputum')" :icon="Droplets" placeholder="Aspect, couleur…" /><FormError v-if="errorMessage('consultation_data.sputum')" :id="errorId('consultation_data.sputum')" :message="errorMessage('consultation_data.sputum')" /></label></div>
                                <label class="block text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><TriangleAlert class="h-3.5 w-3.5" aria-hidden="true" />Description de la douleur</span><IconInput v-model="form.consultation_data.pain_notes" v-bind="fieldAttrs('consultation_data.pain_notes')" :icon="TriangleAlert" placeholder="Siège, intensité, type…" /><FormError v-if="errorMessage('consultation_data.pain_notes')" :id="errorId('consultation_data.pain_notes')" :message="errorMessage('consultation_data.pain_notes')" /></label>
                            </div>

                            <div class="space-y-4 p-4">
                                <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Scissors class="h-3.5 w-3.5 text-primary" aria-hidden="true" />Anesthésiques et chirurgicaux</h4>
                                <label class="block text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Syringe class="h-3.5 w-3.5" aria-hidden="true" />Antécédents anesthésiques</span><Textarea v-model="form.consultation_data.anesthetic_history" v-bind="fieldAttrs('consultation_data.anesthetic_history')" rows="4" :class="invalidClass('consultation_data.anesthetic_history')" /><FormError v-if="errorMessage('consultation_data.anesthetic_history')" :id="errorId('consultation_data.anesthetic_history')" :message="errorMessage('consultation_data.anesthetic_history')" /></label>
                                <label class="block text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Scissors class="h-3.5 w-3.5" aria-hidden="true" />Antécédents chirurgicaux</span><Textarea v-model="form.consultation_data.surgical_history" v-bind="fieldAttrs('consultation_data.surgical_history')" rows="4" :class="invalidClass('consultation_data.surgical_history')" /><FormError v-if="errorMessage('consultation_data.surgical_history')" :id="errorId('consultation_data.surgical_history')" :message="errorMessage('consultation_data.surgical_history')" /></label>
                                <label class="block text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><TriangleAlert class="h-3.5 w-3.5" aria-hidden="true" />Incident antérieur</span><Textarea v-model="form.consultation_data.anesthetic_incidents" v-bind="fieldAttrs('consultation_data.anesthetic_incidents')" rows="3" :class="invalidClass('consultation_data.anesthetic_incidents')" /><FormError v-if="errorMessage('consultation_data.anesthetic_incidents')" :id="errorId('consultation_data.anesthetic_incidents')" :message="errorMessage('consultation_data.anesthetic_incidents')" /></label>
                            </div>

                            <div class="space-y-4 p-4">
                                <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Venus class="h-3.5 w-3.5 text-primary" aria-hidden="true" />Gynéco-obstétricaux</h4>
                                <p v-if="!isFemale" class="flex items-start gap-2 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground"><Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />Section affichée en lecture contextuelle ; le sexe administratif du patient n’est pas féminin.</p>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                    <label class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Hash class="h-3.5 w-3.5" aria-hidden="true" />Grossesses (G)</span><IconInput v-model="form.consultation_data.gyneco_obstetric.gravida" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.gravida')" :icon="Hash" type="number" min="0" max="30" placeholder="0" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.gravida')" :id="errorId('consultation_data.gyneco_obstetric.gravida')" :message="errorMessage('consultation_data.gyneco_obstetric.gravida')" /></label>
                                    <label class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Hash class="h-3.5 w-3.5" aria-hidden="true" />Accouchements (P)</span><IconInput v-model="form.consultation_data.gyneco_obstetric.para" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.para')" :icon="Hash" type="number" min="0" max="30" placeholder="0" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.para')" :id="errorId('consultation_data.gyneco_obstetric.para')" :message="errorMessage('consultation_data.gyneco_obstetric.para')" /></label>
                                    <label class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Hash class="h-3.5 w-3.5" aria-hidden="true" />Avortements (A)</span><IconInput v-model="form.consultation_data.gyneco_obstetric.abortion" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.abortion')" :icon="Hash" type="number" min="0" max="30" placeholder="0" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.abortion')" :id="errorId('consultation_data.gyneco_obstetric.abortion')" :message="errorMessage('consultation_data.gyneco_obstetric.abortion')" /></label>
                                </div>
                                <label class="block text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Calendar class="h-3.5 w-3.5" aria-hidden="true" />DDR</span><DatePicker v-model="form.consultation_data.gyneco_obstetric.last_menstrual_period" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.last_menstrual_period')" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.last_menstrual_period')" :id="errorId('consultation_data.gyneco_obstetric.last_menstrual_period')" :message="errorMessage('consultation_data.gyneco_obstetric.last_menstrual_period')" /></label>
                                <div class="grid grid-cols-2 gap-2"><label class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Pill class="h-3.5 w-3.5" aria-hidden="true" />Contraception</span><Select v-model="form.consultation_data.gyneco_obstetric.contraception" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.contraception')" :options="contraceptionOptions" placeholder="Non renseignée" :class="['mt-1 w-full', invalidClass('consultation_data.gyneco_obstetric.contraception')]" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.contraception')" :id="errorId('consultation_data.gyneco_obstetric.contraception')" :message="errorMessage('consultation_data.gyneco_obstetric.contraception')" /></label><label class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Calendar class="h-3.5 w-3.5" aria-hidden="true" />Date</span><DatePicker v-model="form.consultation_data.gyneco_obstetric.contraception_date" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.contraception_date')" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.contraception_date')" :id="errorId('consultation_data.gyneco_obstetric.contraception_date')" :message="errorMessage('consultation_data.gyneco_obstetric.contraception_date')" /></label></div>
                                <div class="grid grid-cols-2 gap-2"><label class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Baby class="h-3.5 w-3.5" aria-hidden="true" />Accouchement</span><Select v-model="form.consultation_data.gyneco_obstetric.delivery_route" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.delivery_route')" :options="deliveryOptions" placeholder="Non renseigné" :class="['mt-1 w-full', invalidClass('consultation_data.gyneco_obstetric.delivery_route')]" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.delivery_route')" :id="errorId('consultation_data.gyneco_obstetric.delivery_route')" :message="errorMessage('consultation_data.gyneco_obstetric.delivery_route')" /></label><label class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Calendar class="h-3.5 w-3.5" aria-hidden="true" />Date</span><DatePicker v-model="form.consultation_data.gyneco_obstetric.delivery_date" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.delivery_date')" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.delivery_date')" :id="errorId('consultation_data.gyneco_obstetric.delivery_date')" :message="errorMessage('consultation_data.gyneco_obstetric.delivery_date')" /></label></div>
                                <label class="block text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Venus class="h-3.5 w-3.5" aria-hidden="true" />Parité</span><Select v-model="form.consultation_data.gyneco_obstetric.parity_status" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.parity_status')" :options="parityOptions" placeholder="Non renseignée" :class="['mt-1 w-full', invalidClass('consultation_data.gyneco_obstetric.parity_status')]" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.parity_status')" :id="errorId('consultation_data.gyneco_obstetric.parity_status')" :message="errorMessage('consultation_data.gyneco_obstetric.parity_status')" /></label>
                                <label class="block text-sm font-medium text-muted-foreground" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.obstetric_hemorrhage')"><span class="inline-flex items-center gap-1.5"><Droplets class="h-3.5 w-3.5" aria-hidden="true" />Hémorragie obstétricale</span><TriStateChoice v-model="form.consultation_data.gyneco_obstetric.obstetric_hemorrhage" empty-label="Non renseignée" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.obstetric_hemorrhage')" :id="errorId('consultation_data.gyneco_obstetric.obstetric_hemorrhage')" :message="errorMessage('consultation_data.gyneco_obstetric.obstetric_hemorrhage')" /></label>
                                <label class="block text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><NotebookPen class="h-3.5 w-3.5" aria-hidden="true" />Précisions</span><Textarea v-model="form.consultation_data.gyneco_obstetric.notes" v-bind="fieldAttrs('consultation_data.gyneco_obstetric.notes')" rows="2" :class="invalidClass('consultation_data.gyneco_obstetric.notes')" placeholder="Précisions" /><FormError v-if="errorMessage('consultation_data.gyneco_obstetric.notes')" :id="errorId('consultation_data.gyneco_obstetric.notes')" :message="errorMessage('consultation_data.gyneco_obstetric.notes')" /></label>
                            </div>
                        </div>
                    </ClinicalSubsection>

                    <div v-if="canEdit" class="flex flex-col gap-3 rounded-lg border border-border bg-muted/30 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs font-medium text-muted-foreground">Enregistré automatiquement pendant la saisie.</p>
                        <span class="inline-flex items-center gap-3"><ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" /><Button size="lg" type="button" :disabled="form.processing" @click="next('exam')">Suivant<ArrowRight class="ms-2 h-4 w-4" aria-hidden="true" /></Button></span>
                    </div>
                    </div>
                    </ClinicalAccordionSection>

                    <ClinicalAccordionSection
                        :open="activeSection === 'exam'"
                        step="2"
                        tone="violet"
                        title="Examen clinique"
                        description="Examen par systèmes, voies aériennes et mesures spécifiques à l’anesthésie."
                        :icon="Stethoscope"
                        :complete="examComplete"
                        @toggle="activeSection = activeSection === 'exam' ? '' : 'exam'"
                    >
                    <div class="space-y-4">
                    <ClinicalSubsection :icon="Stethoscope" title="Examen par systèmes et voies aériennes" description="Seules les mesures propres à l’anesthésie se saisissent ici.">
                        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
                            <div class="space-y-3">
                                <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Stethoscope class="h-3.5 w-3.5 text-primary" aria-hidden="true" />Systèmes</h4>
                                <label v-for="field in systemFields" :key="field.key" class="block text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><component :is="field.icon" class="h-3.5 w-3.5" aria-hidden="true" />{{ field.label }}</span><IconInput v-model="form.consultation_data.clinical_exam[field.key]" v-bind="fieldAttrs(`consultation_data.clinical_exam.${field.key}`)" :icon="field.icon" placeholder="RAS ou préciser" /><FormError v-if="errorMessage(`consultation_data.clinical_exam.${field.key}`)" :id="errorId(`consultation_data.clinical_exam.${field.key}`)" :message="errorMessage(`consultation_data.clinical_exam.${field.key}`)" /></label>
                            </div>
                            <div class="space-y-3">
                                <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><HeartPulse class="h-3.5 w-3.5 text-primary" aria-hidden="true" />Mesures complémentaires</h4>
                                <p v-if="careSummary?.can_view_vitals" class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 p-2.5 text-[11px] leading-4 text-muted-foreground"><Info class="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" />TA, température, poids et taille proviennent de Soins et ne sont pas ressaisis ici.</p>
                                <div class="grid grid-cols-2 gap-2"><label class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><HeartPulse class="h-3.5 w-3.5" aria-hidden="true" />FC</span><IconInput v-model="form.consultation_data.heart_rate" v-bind="fieldAttrs('consultation_data.heart_rate')" :icon="HeartPulse" type="number" min="20" max="300" placeholder="batt/min" /><FormError v-if="errorMessage('consultation_data.heart_rate')" :id="errorId('consultation_data.heart_rate')" :message="errorMessage('consultation_data.heart_rate')" /></label><label class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Droplets class="h-3.5 w-3.5" aria-hidden="true" />SpO₂</span><IconInput v-model="form.consultation_data.oxygen_saturation" v-bind="fieldAttrs('consultation_data.oxygen_saturation')" :icon="Droplets" type="number" min="0" max="100" placeholder="%" /><FormError v-if="errorMessage('consultation_data.oxygen_saturation')" :id="errorId('consultation_data.oxygen_saturation')" :message="errorMessage('consultation_data.oxygen_saturation')" /></label></div>
                                <label class="block text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Activity class="h-3.5 w-3.5" aria-hidden="true" />FR</span><IconInput v-model="form.consultation_data.respiratory_rate" v-bind="fieldAttrs('consultation_data.respiratory_rate')" :icon="Activity" type="number" min="1" max="150" placeholder="cycles/min" /><FormError v-if="errorMessage('consultation_data.respiratory_rate')" :id="errorId('consultation_data.respiratory_rate')" :message="errorMessage('consultation_data.respiratory_rate')" /></label>
                            </div>
                            <div class="space-y-3">
                                <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Wind class="h-3.5 w-3.5 text-primary" aria-hidden="true" />Voies aériennes et prothèses</h4>
                                <label v-for="field in airwayFields" :key="field.key" class="block text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><component :is="field.icon" class="h-3.5 w-3.5" aria-hidden="true" />{{ field.label }}</span><IconInput v-model="form.consultation_data[field.key]" v-bind="fieldAttrs(`consultation_data.${field.key}`)" :icon="field.icon" /><FormError v-if="errorMessage(`consultation_data.${field.key}`)" :id="errorId(`consultation_data.${field.key}`)" :message="errorMessage(`consultation_data.${field.key}`)" /></label>
                            </div>
                        </div>
                    </ClinicalSubsection>

                    <ClinicalSubsection :icon="Clock" title="Jeûne et état neuropsychologique">
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Clock class="h-3.5 w-3.5" aria-hidden="true" />Heure du dernier repas</span><IconInput v-model="form.consultation_data.last_meal_time" v-bind="fieldAttrs('consultation_data.last_meal_time')" :icon="Clock" size="lg" type="time" /><FormError v-if="errorMessage('consultation_data.last_meal_time')" :id="errorId('consultation_data.last_meal_time')" :message="errorMessage('consultation_data.last_meal_time')" /></label>
                        <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Clock class="h-3.5 w-3.5" aria-hidden="true" />Heure de la dernière boisson</span><IconInput v-model="form.consultation_data.last_drink_time" v-bind="fieldAttrs('consultation_data.last_drink_time')" :icon="Clock" size="lg" type="time" /><FormError v-if="errorMessage('consultation_data.last_drink_time')" :id="errorId('consultation_data.last_drink_time')" :message="errorMessage('consultation_data.last_drink_time')" /></label>
                        <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Brain class="h-3.5 w-3.5" aria-hidden="true" />État neuropsychologique</span><Select v-model="form.consultation_data.neuropsychological_status" v-bind="fieldAttrs('consultation_data.neuropsychological_status')" :options="neuropsychologicalOptions" placeholder="Non renseigné" :class="['mt-1 w-full', invalidClass('consultation_data.neuropsychological_status')]" /><FormError v-if="errorMessage('consultation_data.neuropsychological_status')" :id="errorId('consultation_data.neuropsychological_status')" :message="errorMessage('consultation_data.neuropsychological_status')" /></label>
                    </div>
                    </ClinicalSubsection>

                    <div v-if="canEdit" class="flex items-center border-t border-border pt-4">
                        <span v-if="canEdit" class="me-auto inline-flex items-center gap-2"><ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" /><Button v-if="autosave.failed.value" size="sm" variant="white-outline" type="button" :disabled="form.processing" @click="autosave.flush(undefined, focusFirstError)">Réessayer</Button></span>
                    </div>
                    </div>
                    </ClinicalAccordionSection>
                </fieldset>

                <FormError v-if="form.errors.assessment">{{ form.errors.assessment }}</FormError>
            </form>
        </CardBody>
    </Card>
</template>
