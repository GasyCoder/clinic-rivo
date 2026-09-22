<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import CardBody from '@/Components/Shadcn/CardContent.vue';
import FormError from '@/Components/UI/FormError.vue';
import { Activity, ArrowRight, Brain, Clock, ClipboardCheck, Droplet, Droplets, Ear, Eye, FileCheck2, FlaskConical, Gauge, Hand, HeartPulse, Info, Bean, ListChecks, MessageSquare, PackagePlus, Pill, ScanLine, ShieldCheck, Stethoscope, Syringe, TestTube, UserRound, Venus, Wind } from 'lucide-vue-next';
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
    canValidate: Boolean,
});

const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const record = computed(() => props.surgicalRequest.anesthesia_record ?? null);
/** Un appareil, une icône : rien n'est déduit du texte saisi. */
const pathologyFields = [
    { key: 'cardiac', label: 'Cardiaque', icon: HeartPulse },
    { key: 'respiratory', label: 'Respiratoire', icon: Wind },
    { key: 'renal', label: 'Rénale', icon: Bean },
    { key: 'digestive', label: 'Digestive', icon: Pill },
    { key: 'neurological', label: 'Neurologique', icon: Brain },
    { key: 'gynecological', label: 'Gynécologique', icon: Venus },
    { key: 'ent', label: 'ORL', icon: Ear },
];
const assessmentLocked = computed(() => Boolean(record.value?.assessment_validated_at));
const canEdit = computed(() => !assessmentLocked.value && (record.value ? props.canUpdate : props.canCreate));
const validating = ref(false);

const careBloodGroup = computed(() => props.careSummary?.blood_group ?? '');
const existing = record.value?.paraclinical_data ?? {};
const selectValue = (value) => value === null || value === undefined ? '' : String(value);

const laboratoryDefaults = {
    hemoglobin: '', hematocrit: '', psa: '', creatinine: '', glycemia: '',
    urea: '', tdr: '', crp: '', widal_to: '', widal_th: '',
};
const pathologyDefaults = {
    cardiac: '', respiratory: '', renal: '', digestive: '', neurological: '',
    gynecological: '', ent: '',
};
const form = useForm({
    paraclinical_data: {
        blood_group: '',
        rhesus: '',
        transfusion_recommended_units: '',
        transfusion_received: '',
        preoperative_transfusion_units: '',
        ultrasound_notes: '',
        ultrasonographer: '',
        glasgow_eye: '',
        glasgow_verbal: '',
        glasgow_motor: '',
        apfel_score: '',
        conclusion: '',
        therapeutic_recommendation: '',
        surgery_authorized: '',
        asa_class: '',
        nyha_class: '',
        anesthesia_plan: '',
        fasting_hours: '',
        ...existing,
        blood_group: selectValue(existing.blood_group),
        rhesus: selectValue(existing.rhesus),
        glasgow_eye: selectValue(existing.glasgow_eye),
        glasgow_verbal: selectValue(existing.glasgow_verbal),
        glasgow_motor: selectValue(existing.glasgow_motor),
        apfel_score: selectValue(existing.apfel_score),
        laboratory: { ...laboratoryDefaults, ...(existing.laboratory ?? {}) },
        associated_pathologies: { ...pathologyDefaults, ...(existing.associated_pathologies ?? {}) },
    },
});

const bloodGroupOptions = ['A', 'B', 'AB', 'O'].map((value) => ({ value, label: value }));
const rhesusOptions = [{ value: 'POSITIVE', label: 'Positif (+)' }, { value: 'NEGATIVE', label: 'Négatif (−)' }];
const glasgowEyeOptions = [{ value: '4', label: 'Spontanée — 4' }, { value: '3', label: 'À la demande — 3' }, { value: '2', label: 'À la douleur — 2' }, { value: '1', label: 'Aucune — 1' }];
const glasgowVerbalOptions = [{ value: '5', label: 'Orientée — 5' }, { value: '4', label: 'Confuse — 4' }, { value: '3', label: 'Inappropriée — 3' }, { value: '2', label: 'Incompréhensible — 2' }, { value: '1', label: 'Aucune — 1' }];
const glasgowMotorOptions = [{ value: '6', label: 'Aux ordres — 6' }, { value: '5', label: 'Localise la douleur — 5' }, { value: '4', label: 'Évitement — 4' }, { value: '3', label: 'Décortication — 3' }, { value: '2', label: 'Décérébration — 2' }, { value: '1', label: 'Aucune — 1' }];
const apfelOptions = [0, 1, 2, 3, 4].map((value) => ({ value: String(value), label: `${value} / 4` }));
const glasgowTotal = computed(() => {
    const values = [form.paraclinical_data.glasgow_eye, form.paraclinical_data.glasgow_verbal, form.paraclinical_data.glasgow_motor];
    return values.every((value) => value !== '' && value !== null) ? values.reduce((sum, value) => sum + Number(value), 0) : null;
});
const activeSection = ref('results');
const formElement = ref(null);
const resultErrorPrefixes = [
    'paraclinical_data.blood_group',
    'paraclinical_data.rhesus',
    'paraclinical_data.transfusion_',
    'paraclinical_data.preoperative_transfusion_',
    'paraclinical_data.ultrasound_',
    'paraclinical_data.ultrasonographer',
    'paraclinical_data.laboratory.',
];
const scoreErrorPrefixes = [
    'paraclinical_data.glasgow_',
    'paraclinical_data.apfel_score',
    'paraclinical_data.associated_pathologies',
];
const sectionForError = (key) => {
    if (resultErrorPrefixes.some((prefix) => key.startsWith(prefix))) return 'results';
    if (scoreErrorPrefixes.some((prefix) => key.startsWith(prefix))) return 'scores';
    return 'decision';
};
const {
    errorId, errorMessage, fieldAttrs, focusError, focusFirstError, invalidClass,
} = useValidationNavigation(form, activeSection, sectionForError, formElement);
const hasValue = (value) => value !== null && value !== undefined && value !== '';
const resultsComplete = computed(() => [
    ...Object.values(form.paraclinical_data.laboratory),
    form.paraclinical_data.ultrasound_notes,
    form.paraclinical_data.transfusion_received,
].some(hasValue));
const scoresComplete = computed(() => [
    form.paraclinical_data.glasgow_eye,
    form.paraclinical_data.glasgow_verbal,
    form.paraclinical_data.glasgow_motor,
    form.paraclinical_data.apfel_score,
    ...Object.values(form.paraclinical_data.associated_pathologies),
].some(hasValue));
const decisionComplete = computed(() => [
    form.paraclinical_data.conclusion,
    form.paraclinical_data.surgery_authorized,
    form.paraclinical_data.asa_class,
    form.paraclinical_data.anesthesia_plan,
].some(hasValue));

// Plus de bouton « Enregistrer » : le bilan s'enregistre tout seul quelques
// instants après la dernière saisie, par la même route et la même validation.
const send = (options) => {
    if (record.value) {
        form.put(`${base.value}/anesthesia/${record.value.id}`, options);
        return;
    }

    form.post(`${base.value}/anesthesia`, options);
};
const autosave = useAutosave(form, send, { enabled: () => canEdit.value });
/** « Suivant » : enregistre ce qui reste, puis ouvre la section suivante. */
const next = (nextSection) => autosave.flush(
    () => { activeSection.value = nextSection; },
    (errors) => focusFirstError(errors),
);

const validateAssessment = () => {
    validating.value = true;
    router.post(`${base.value}/anesthesia/${record.value.id}/assessment/validate`, {}, {
        preserveScroll: true,
        onFinish: () => { validating.value = false; },
    });
};
</script>

<template>
    <Card class="shadow-sm xl:col-span-12">
        <CardBody class="!p-0">
            <AnesthesiaStepHeader
                :icon="Activity"
                :step="2"
                title="Examen paraclinique et décision"
                description="Résultats, scores puis décision : un seul groupe de champs ouvert à la fois pour faciliter la saisie."
                :sections="[{ label: 'Résultats', complete: resultsComplete }, { label: 'Scores', complete: scoresComplete }, { label: 'Conclusion', complete: decisionComplete }]"
                :status="assessmentLocked ? 'VALIDATED' : (!canEdit ? 'READONLY' : null)"
            />

            <form ref="formElement" class="space-y-4 p-4 sm:p-5" @submit.prevent="autosave.flush(undefined, focusFirstError)">
                <ValidationErrorSummary :errors="form.errors" @select="focusError" />
                <fieldset :disabled="!canEdit" class="space-y-3 disabled:opacity-70">
                    <ClinicalAccordionSection
                        :open="activeSection === 'results'"
                        step="1"
                        tone="violet"
                        title="Résultats disponibles"
                        :icon="FlaskConical"
                        description="Transfusion, biologie et échographie utiles à l’évaluation."
                        :complete="resultsComplete"
                        @toggle="activeSection = activeSection === 'results' ? '' : 'results'"
                    >
                    <div class="space-y-4">
                    <ClinicalSubsection :icon="Droplet" title="Groupe sanguin et transfusion">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                            <div v-if="careBloodGroup" class="flex items-start gap-3 rounded-lg border border-border bg-muted/30 px-3 py-2 sm:col-span-2"><Droplet class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" /><div><span class="block text-xs font-medium text-muted-foreground">Groupe sanguin · Soins</span><strong class="mt-0.5 block text-sm text-foreground">{{ careBloodGroup }}</strong><small class="text-[11px] text-muted-foreground">Lecture seule, non recopié.</small></div></div>
                            <template v-else>
                                <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Droplet class="h-3.5 w-3.5" aria-hidden="true" />Groupe</span><Select v-model="form.paraclinical_data.blood_group" v-bind="fieldAttrs('paraclinical_data.blood_group')" :options="bloodGroupOptions" placeholder="Non renseigné" :class="['mt-1 w-full', invalidClass('paraclinical_data.blood_group')]" /><FormError v-if="errorMessage('paraclinical_data.blood_group')" :id="errorId('paraclinical_data.blood_group')" :message="errorMessage('paraclinical_data.blood_group')" /></label>
                                <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Droplet class="h-3.5 w-3.5" aria-hidden="true" />Rhésus</span><Select v-model="form.paraclinical_data.rhesus" v-bind="fieldAttrs('paraclinical_data.rhesus')" :options="rhesusOptions" placeholder="Non renseigné" :class="['mt-1 w-full', invalidClass('paraclinical_data.rhesus')]" /><FormError v-if="errorMessage('paraclinical_data.rhesus')" :id="errorId('paraclinical_data.rhesus')" :message="errorMessage('paraclinical_data.rhesus')" /></label>
                            </template>
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><PackagePlus class="h-3.5 w-3.5" aria-hidden="true" />Culots recommandés</span><IconInput v-model="form.paraclinical_data.transfusion_recommended_units" v-bind="fieldAttrs('paraclinical_data.transfusion_recommended_units')" :icon="Droplets" type="number" min="0" max="100" placeholder="Nombre" /><FormError v-if="errorMessage('paraclinical_data.transfusion_recommended_units')" :id="errorId('paraclinical_data.transfusion_recommended_units')" :message="errorMessage('paraclinical_data.transfusion_recommended_units')" /></label>
                            <label class="text-sm font-medium text-muted-foreground" v-bind="fieldAttrs('paraclinical_data.transfusion_received')"><span class="inline-flex items-center gap-1.5"><Syringe class="h-3.5 w-3.5" aria-hidden="true" />Transfusion reçue</span><TriStateChoice v-model="form.paraclinical_data.transfusion_received" empty-label="Non renseignée" /><FormError v-if="errorMessage('paraclinical_data.transfusion_received')" :id="errorId('paraclinical_data.transfusion_received')" :message="errorMessage('paraclinical_data.transfusion_received')" /></label>
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><PackagePlus class="h-3.5 w-3.5" aria-hidden="true" />Culots en préopératoire</span><IconInput v-model="form.paraclinical_data.preoperative_transfusion_units" v-bind="fieldAttrs('paraclinical_data.preoperative_transfusion_units')" :icon="Droplets" type="number" min="0" max="100" placeholder="Nombre" /><FormError v-if="errorMessage('paraclinical_data.preoperative_transfusion_units')" :id="errorId('paraclinical_data.preoperative_transfusion_units')" :message="errorMessage('paraclinical_data.preoperative_transfusion_units')" /></label>
                        </div>
                    </ClinicalSubsection>

                    <ClinicalSubsection :icon="FlaskConical" title="Biologie et échographie" body-class="p-0">
                        <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-5">
                            <label v-for="field in [['hemoglobin', 'Hb'], ['hematocrit', 'Hte'], ['psa', 'PSA'], ['creatinine', 'Créatinine'], ['glycemia', 'Glycémie'], ['urea', 'Urée'], ['tdr', 'TDR'], ['crp', 'CRP'], ['widal_to', 'Widal TO'], ['widal_th', 'Widal TH']]" :key="field[0]" class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><TestTube class="h-3.5 w-3.5" aria-hidden="true" />{{ field[1] }}</span><IconInput v-model="form.paraclinical_data.laboratory[field[0]]" v-bind="fieldAttrs(`paraclinical_data.laboratory.${field[0]}`)" :icon="FlaskConical" size="lg" placeholder="Valeur / unité" /><FormError v-if="errorMessage(`paraclinical_data.laboratory.${field[0]}`)" :id="errorId(`paraclinical_data.laboratory.${field[0]}`)" :message="errorMessage(`paraclinical_data.laboratory.${field[0]}`)" /></label>
                        </div>
                        <div class="grid grid-cols-1 gap-3 border-t border-border p-4 lg:grid-cols-[minmax(0,2fr)_minmax(240px,1fr)]">
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><ScanLine class="h-3.5 w-3.5" aria-hidden="true" />Résultat de l’échographie</span><Textarea v-model="form.paraclinical_data.ultrasound_notes" v-bind="fieldAttrs('paraclinical_data.ultrasound_notes')" rows="3" :class="invalidClass('paraclinical_data.ultrasound_notes')" /><FormError v-if="errorMessage('paraclinical_data.ultrasound_notes')" :id="errorId('paraclinical_data.ultrasound_notes')" :message="errorMessage('paraclinical_data.ultrasound_notes')" /></label>
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><UserRound class="h-3.5 w-3.5" aria-hidden="true" />Échographiste</span><IconInput v-model="form.paraclinical_data.ultrasonographer" v-bind="fieldAttrs('paraclinical_data.ultrasonographer')" :icon="UserRound" size="lg" placeholder="Nom de l’échographiste" /><FormError v-if="errorMessage('paraclinical_data.ultrasonographer')" :id="errorId('paraclinical_data.ultrasonographer')" :message="errorMessage('paraclinical_data.ultrasonographer')" /></label>
                        </div>
                    </ClinicalSubsection>

                    <div v-if="canEdit" class="flex items-center justify-end gap-3"><ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" /><Button size="lg" type="button" :disabled="form.processing" @click="next('scores')">Suivant<ArrowRight class="ms-2 h-4 w-4" aria-hidden="true" /></Button></div>
                    </div>
                    </ClinicalAccordionSection>

                    <ClinicalAccordionSection
                        :open="activeSection === 'scores'"
                        step="2"
                        tone="violet"
                        title="Scores et pathologies associées"
                        :icon="Gauge"
                        description="Glasgow, Apfel et éléments associés à prendre en compte."
                        :complete="scoresComplete"
                        @toggle="activeSection = activeSection === 'scores' ? '' : 'scores'"
                    >
                    <div class="space-y-4">
                    <section class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)]">
                        <ClinicalSubsection :icon="Brain" title="Score de Glasgow" description="Total calculé à partir des trois réponses.">
                            <template #aside><strong class="rounded-full border border-border bg-background px-3 py-1 text-lg text-foreground shadow-sm" :aria-label="`Glasgow ${glasgowTotal ?? 'non calculé'} sur 15`">{{ glasgowTotal ?? '—' }}<span class="text-xs font-normal text-muted-foreground"> / 15</span></strong></template>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Eye class="h-3.5 w-3.5" aria-hidden="true" />Ouverture des yeux</span><Select v-model="form.paraclinical_data.glasgow_eye" v-bind="fieldAttrs('paraclinical_data.glasgow_eye')" :options="glasgowEyeOptions" placeholder="Choisir" :class="['mt-1 w-full', invalidClass('paraclinical_data.glasgow_eye')]" /><FormError v-if="errorMessage('paraclinical_data.glasgow_eye')" :id="errorId('paraclinical_data.glasgow_eye')" :message="errorMessage('paraclinical_data.glasgow_eye')" /></label>
                                <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><MessageSquare class="h-3.5 w-3.5" aria-hidden="true" />Réponse verbale</span><Select v-model="form.paraclinical_data.glasgow_verbal" v-bind="fieldAttrs('paraclinical_data.glasgow_verbal')" :options="glasgowVerbalOptions" placeholder="Choisir" :class="['mt-1 w-full', invalidClass('paraclinical_data.glasgow_verbal')]" /><FormError v-if="errorMessage('paraclinical_data.glasgow_verbal')" :id="errorId('paraclinical_data.glasgow_verbal')" :message="errorMessage('paraclinical_data.glasgow_verbal')" /></label>
                                <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Hand class="h-3.5 w-3.5" aria-hidden="true" />Réponse motrice</span><Select v-model="form.paraclinical_data.glasgow_motor" v-bind="fieldAttrs('paraclinical_data.glasgow_motor')" :options="glasgowMotorOptions" placeholder="Choisir" :class="['mt-1 w-full', invalidClass('paraclinical_data.glasgow_motor')]" /><FormError v-if="errorMessage('paraclinical_data.glasgow_motor')" :id="errorId('paraclinical_data.glasgow_motor')" :message="errorMessage('paraclinical_data.glasgow_motor')" /></label>
                            </div>
                        </ClinicalSubsection>
                        <ClinicalSubsection :icon="Gauge" title="Score d’Apfel">
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Gauge class="h-3.5 w-3.5" aria-hidden="true" />Score d’Apfel</span><Select v-model="form.paraclinical_data.apfel_score" v-bind="fieldAttrs('paraclinical_data.apfel_score')" :options="apfelOptions" placeholder="Non renseigné" :class="['mt-1 w-full', invalidClass('paraclinical_data.apfel_score')]" /><FormError v-if="errorMessage('paraclinical_data.apfel_score')" :id="errorId('paraclinical_data.apfel_score')" :message="errorMessage('paraclinical_data.apfel_score')" /></label>
                            <p class="mt-3 flex items-start gap-2 text-xs leading-5 text-muted-foreground"><Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />Saisie du score clinique constaté, sans interprétation automatique ni seuil inventé.</p>
                        </ClinicalSubsection>
                    </section>

                    <ClinicalSubsection :icon="HeartPulse" title="Pathologies associées" description="« RAS » ou précision, par appareil.">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <label v-for="field in pathologyFields" :key="field.key" class="text-xs font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><component :is="field.icon" class="h-3.5 w-3.5" aria-hidden="true" />{{ field.label }}</span><IconInput v-model="form.paraclinical_data.associated_pathologies[field.key]" v-bind="fieldAttrs(`paraclinical_data.associated_pathologies.${field.key}`)" :icon="field.icon" size="lg" placeholder="RAS ou préciser" /><FormError v-if="errorMessage(`paraclinical_data.associated_pathologies.${field.key}`)" :id="errorId(`paraclinical_data.associated_pathologies.${field.key}`)" :message="errorMessage(`paraclinical_data.associated_pathologies.${field.key}`)" /></label>
                        </div>
                    </ClinicalSubsection>

                    <div v-if="canEdit" class="flex items-center justify-end gap-3"><ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" /><Button size="lg" type="button" :disabled="form.processing" @click="next('decision')">Suivant<ArrowRight class="ms-2 h-4 w-4" aria-hidden="true" /></Button></div>
                    </div>
                    </ClinicalAccordionSection>

                    <ClinicalAccordionSection
                        :open="activeSection === 'decision'"
                        step="3"
                        tone="violet"
                        title="Décision anesthésique"
                        :icon="ClipboardCheck"
                        description="Conclusion, autorisation, classification et plan anesthésique."
                        :complete="decisionComplete"
                        @toggle="activeSection = activeSection === 'decision' ? '' : 'decision'"
                    >
                    <ClinicalSubsection :icon="ClipboardCheck" title="Conclusion et conduite anesthésique" body-class="p-0">
                        <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><FileCheck2 class="h-3.5 w-3.5" aria-hidden="true" />Conclusion</span><Textarea v-model="form.paraclinical_data.conclusion" v-bind="fieldAttrs('paraclinical_data.conclusion')" rows="4" :class="invalidClass('paraclinical_data.conclusion')" /><FormError v-if="errorMessage('paraclinical_data.conclusion')" :id="errorId('paraclinical_data.conclusion')" :message="errorMessage('paraclinical_data.conclusion')" /></label>
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Pill class="h-3.5 w-3.5" aria-hidden="true" />Recommandation thérapeutique</span><Textarea v-model="form.paraclinical_data.therapeutic_recommendation" v-bind="fieldAttrs('paraclinical_data.therapeutic_recommendation')" rows="4" :class="invalidClass('paraclinical_data.therapeutic_recommendation')" /><FormError v-if="errorMessage('paraclinical_data.therapeutic_recommendation')" :id="errorId('paraclinical_data.therapeutic_recommendation')" :message="errorMessage('paraclinical_data.therapeutic_recommendation')" /></label>
                        </div>
                        <div class="grid grid-cols-1 gap-4 border-t border-border p-4 sm:grid-cols-2 lg:grid-cols-5">
                            <label class="text-sm font-medium text-muted-foreground" v-bind="fieldAttrs('paraclinical_data.surgery_authorized')"><span class="inline-flex items-center gap-1.5"><ShieldCheck class="h-3.5 w-3.5" aria-hidden="true" />Chirurgie autorisée</span><TriStateChoice v-model="form.paraclinical_data.surgery_authorized" empty-label="À décider" /><FormError v-if="errorMessage('paraclinical_data.surgery_authorized')" :id="errorId('paraclinical_data.surgery_authorized')" :message="errorMessage('paraclinical_data.surgery_authorized')" /></label>
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><ListChecks class="h-3.5 w-3.5" aria-hidden="true" />Classe ASA</span><IconInput v-model="form.paraclinical_data.asa_class" v-bind="fieldAttrs('paraclinical_data.asa_class')" :icon="ListChecks" size="lg" placeholder="Ex. ASA II" /><FormError v-if="errorMessage('paraclinical_data.asa_class')" :id="errorId('paraclinical_data.asa_class')" :message="errorMessage('paraclinical_data.asa_class')" /></label>
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><HeartPulse class="h-3.5 w-3.5" aria-hidden="true" />Classe NYHA</span><IconInput v-model="form.paraclinical_data.nyha_class" v-bind="fieldAttrs('paraclinical_data.nyha_class')" :icon="HeartPulse" size="lg" placeholder="Ex. NYHA I" /><FormError v-if="errorMessage('paraclinical_data.nyha_class')" :id="errorId('paraclinical_data.nyha_class')" :message="errorMessage('paraclinical_data.nyha_class')" /></label>
                            <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Clock class="h-3.5 w-3.5" aria-hidden="true" />Jeûne prescrit</span><IconInput v-model="form.paraclinical_data.fasting_hours" v-bind="fieldAttrs('paraclinical_data.fasting_hours')" :icon="Clock" size="lg" type="number" min="0" max="72" step="0.5" placeholder="heures" /><FormError v-if="errorMessage('paraclinical_data.fasting_hours')" :id="errorId('paraclinical_data.fasting_hours')" :message="errorMessage('paraclinical_data.fasting_hours')" /></label>
                            <label class="text-sm text-muted-foreground lg:col-span-1">Plan anesthésique<Textarea v-model="form.paraclinical_data.anesthesia_plan" v-bind="fieldAttrs('paraclinical_data.anesthesia_plan')" rows="2" :class="invalidClass('paraclinical_data.anesthesia_plan')" /><FormError v-if="errorMessage('paraclinical_data.anesthesia_plan')" :id="errorId('paraclinical_data.anesthesia_plan')" :message="errorMessage('paraclinical_data.anesthesia_plan')" /></label>
                        </div>
                    </ClinicalSubsection>

                    <div class="mt-4 flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="inline-flex items-center gap-2 text-xs text-muted-foreground"><template v-if="canEdit"><ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" /><Button v-if="autosave.failed.value" size="sm" variant="white-outline" type="button" :disabled="form.processing" @click="autosave.flush(undefined, focusFirstError)">Réessayer</Button></template><span v-else>Lecture seule.</span></p>
                        <div class="flex flex-wrap justify-end gap-2">
                            <Button v-if="record && canValidate && !assessmentLocked" size="lg" variant="secondary" type="button" :disabled="validating || form.isDirty || form.processing" :title="form.isDirty || form.processing ? 'Enregistrement des dernières modifications en cours' : undefined" @click="validateAssessment"><ShieldCheck class="h-4 w-4" aria-hidden="true" /><span class="ms-2">Valider l’évaluation</span></Button>
                        </div>
                    </div>
                    </ClinicalAccordionSection>
                </fieldset>

                <FormError v-if="form.errors.assessment">{{ form.errors.assessment }}</FormError>
            </form>
        </CardBody>
    </Card>
</template>
