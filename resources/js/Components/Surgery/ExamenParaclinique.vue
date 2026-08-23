<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import ClinicalAccordionSection from '@/Components/Surgery/ClinicalAccordionSection.vue';

const props = defineProps({
    surgicalRequest: Object,
    careSummary: { type: Object, default: null },
    canCreate: Boolean,
    canUpdate: Boolean,
    canValidate: Boolean,
});

const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const record = computed(() => props.surgicalRequest.anesthesia_record ?? null);
const assessmentLocked = computed(() => Boolean(record.value?.assessment_validated_at));
const canEdit = computed(() => !assessmentLocked.value && (record.value ? props.canUpdate : props.canCreate));
const validating = ref(false);

const careBloodGroup = computed(() => props.careSummary?.blood_group ?? '');
const existing = record.value?.paraclinical_data ?? {};

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
        laboratory: { ...laboratoryDefaults, ...(existing.laboratory ?? {}) },
        associated_pathologies: { ...pathologyDefaults, ...(existing.associated_pathologies ?? {}) },
    },
});

const inputClass = 'mt-1 block min-h-11 w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const textareaClass = `${inputClass} resize-y`;
const glasgowTotal = computed(() => {
    const values = [form.paraclinical_data.glasgow_eye, form.paraclinical_data.glasgow_verbal, form.paraclinical_data.glasgow_motor];
    return values.every((value) => value !== '' && value !== null) ? values.reduce((sum, value) => sum + Number(value), 0) : null;
});
const activeSection = ref('results');
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

const submit = (nextSection = null) => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (nextSection) activeSection.value = nextSection;
        },
    };

    if (record.value) {
        form.put(`${base.value}/anesthesia/${record.value.id}`, options);
        return;
    }

    form.post(`${base.value}/anesthesia`, options);
};

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
            <div class="border-b border-violet-100 bg-violet-50/80 px-4 py-3 dark:border-violet-950 dark:bg-violet-950/30 sm:px-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-violet-600">Étape anesthésie · 2/3</p>
                        <h2 class="mt-0.5 flex items-center gap-2 font-heading text-base font-bold text-slate-700 dark:text-white"><Icon name="activity" /> Examen paraclinique et décision</h2>
                        <p class="mt-1 max-w-3xl text-sm text-slate-500">Résultats, scores puis décision : un seul groupe de champs ouvert à la fois pour faciliter la saisie.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span v-if="assessmentLocked" class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-700 dark:bg-green-950 dark:text-green-300"><Icon name="check-circle" /> Évaluation validée</span>
                        <span v-else-if="!canEdit" class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900"><Icon name="eye" /> Lecture seule</span>
                    </div>
                </div>
            </div>

            <form class="space-y-4 p-4 sm:p-5" @submit.prevent="submit()">
                <fieldset :disabled="!canEdit" class="space-y-3 disabled:opacity-70">
                    <ClinicalAccordionSection
                        :open="activeSection === 'results'"
                        step="1"
                        tone="violet"
                        title="Résultats disponibles"
                        description="Transfusion, biologie et échographie utiles à l’évaluation."
                        :complete="resultsComplete"
                        @toggle="activeSection = activeSection === 'results' ? '' : 'results'"
                    >
                    <div class="space-y-4">
                    <section class="overflow-hidden rounded-md border border-rose-100 dark:border-rose-950">
                        <header class="bg-rose-50 px-4 py-3 dark:bg-rose-950/30"><h3 class="text-sm font-bold uppercase tracking-wide text-rose-800 dark:text-rose-200">Groupe sanguin et transfusion</h3></header>
                        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 xl:grid-cols-5">
                            <div v-if="careBloodGroup" class="rounded-md border border-blue-100 bg-blue-50/60 px-3 py-2 sm:col-span-2 dark:border-blue-950 dark:bg-blue-950/20"><span class="block text-[10px] font-bold uppercase tracking-wide text-blue-600">Groupe sanguin · Soins</span><strong class="mt-1 block text-sm text-slate-700 dark:text-white">{{ careBloodGroup }}</strong><small class="text-[10px] text-slate-400">Lecture seule, non recopié.</small></div>
                            <template v-else>
                                <label class="text-sm text-slate-500">Groupe<select v-model="form.paraclinical_data.blood_group" :class="inputClass"><option value="">Non renseigné</option><option v-for="group in ['A', 'B', 'AB', 'O']" :key="group" :value="group">{{ group }}</option></select></label>
                                <label class="text-sm text-slate-500">Rhésus<select v-model="form.paraclinical_data.rhesus" :class="inputClass"><option value="">Non renseigné</option><option value="POSITIVE">Positif (+)</option><option value="NEGATIVE">Négatif (−)</option></select></label>
                            </template>
                            <label class="text-sm text-slate-500">Culots recommandés<Input v-model="form.paraclinical_data.transfusion_recommended_units" type="number" min="0" max="100" /></label>
                            <label class="text-sm text-slate-500">Transfusion reçue<select v-model="form.paraclinical_data.transfusion_received" :class="inputClass"><option value="">Non renseignée</option><option :value="true">Oui</option><option :value="false">Non</option></select></label>
                            <label class="text-sm text-slate-500">Culots en préopératoire<Input v-model="form.paraclinical_data.preoperative_transfusion_units" type="number" min="0" max="100" /></label>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-md border border-sky-100 dark:border-sky-950">
                        <header class="bg-sky-50 px-4 py-3 dark:bg-sky-950/30"><h3 class="text-sm font-bold uppercase tracking-wide text-sky-800 dark:text-sky-200">Biologie et échographie</h3></header>
                        <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-5">
                            <label v-for="field in [['hemoglobin', 'Hb'], ['hematocrit', 'Hte'], ['psa', 'PSA'], ['creatinine', 'Créatinine'], ['glycemia', 'Glycémie'], ['urea', 'Urée'], ['tdr', 'TDR'], ['crp', 'CRP'], ['widal_to', 'Widal TO'], ['widal_th', 'Widal TH']]" :key="field[0]" class="text-xs font-medium text-slate-500">{{ field[1] }}<Input v-model="form.paraclinical_data.laboratory[field[0]]" size="lg" placeholder="Valeur / unité" /></label>
                        </div>
                        <div class="grid grid-cols-1 gap-3 border-t border-sky-100 p-4 dark:border-sky-950 lg:grid-cols-[minmax(0,2fr)_minmax(240px,1fr)]">
                            <label class="text-sm text-slate-500">Résultat de l’échographie<textarea v-model="form.paraclinical_data.ultrasound_notes" rows="3" :class="textareaClass"></textarea></label>
                            <label class="text-sm text-slate-500">Échographiste<Input v-model="form.paraclinical_data.ultrasonographer" size="lg" /></label>
                        </div>
                    </section>

                    <div v-if="canEdit" class="flex justify-end"><Button size="lg" type="button" :disabled="form.processing" @click="submit('scores')"><Icon name="save" /><span class="ms-2">Enregistrer et passer aux scores</span><Icon class="ms-2" name="arrow-right" /></Button></div>
                    </div>
                    </ClinicalAccordionSection>

                    <ClinicalAccordionSection
                        :open="activeSection === 'scores'"
                        step="2"
                        tone="violet"
                        title="Scores et pathologies associées"
                        description="Glasgow, Apfel et éléments associés à prendre en compte."
                        :complete="scoresComplete"
                        @toggle="activeSection = activeSection === 'scores' ? '' : 'scores'"
                    >
                    <div class="space-y-4">
                    <section class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)]">
                        <div class="overflow-hidden rounded-md border border-amber-100 dark:border-amber-950">
                            <header class="flex items-center justify-between bg-amber-50 px-4 py-3 dark:bg-amber-950/30"><h3 class="text-sm font-bold uppercase tracking-wide text-amber-800 dark:text-amber-200">Score de Glasgow</h3><strong class="rounded-full bg-white px-3 py-1 text-lg text-amber-700 shadow-sm dark:bg-gray-950">{{ glasgowTotal ?? '—' }}<span class="text-xs font-normal text-slate-400"> / 15</span></strong></header>
                            <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-3">
                                <label class="text-sm text-slate-500">Ouverture des yeux<select v-model="form.paraclinical_data.glasgow_eye" :class="inputClass"><option value="">Choisir</option><option :value="4">Spontanée — 4</option><option :value="3">À la demande — 3</option><option :value="2">À la douleur — 2</option><option :value="1">Aucune — 1</option></select></label>
                                <label class="text-sm text-slate-500">Réponse verbale<select v-model="form.paraclinical_data.glasgow_verbal" :class="inputClass"><option value="">Choisir</option><option :value="5">Orientée — 5</option><option :value="4">Confuse — 4</option><option :value="3">Inappropriée — 3</option><option :value="2">Incompréhensible — 2</option><option :value="1">Aucune — 1</option></select></label>
                                <label class="text-sm text-slate-500">Réponse motrice<select v-model="form.paraclinical_data.glasgow_motor" :class="inputClass"><option value="">Choisir</option><option :value="6">Aux ordres — 6</option><option :value="5">Orientée — 5</option><option :value="4">Évitement — 4</option><option :value="3">Flexion — 3</option><option :value="2">Extension — 2</option><option :value="1">Aucune — 1</option></select></label>
                            </div>
                        </div>
                        <div class="rounded-md border border-amber-100 bg-amber-50/40 p-4 dark:border-amber-950 dark:bg-amber-950/20">
                            <label class="text-sm font-bold uppercase tracking-wide text-amber-800 dark:text-amber-200">Score d’Apfel<select v-model="form.paraclinical_data.apfel_score" :class="inputClass"><option value="">Non renseigné</option><option v-for="score in [0, 1, 2, 3, 4]" :key="score" :value="score">{{ score }} / 4</option></select></label>
                            <p class="mt-3 text-xs leading-5 text-slate-400">Saisie du score clinique constaté, sans interprétation automatique ni seuil inventé.</p>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-md border border-orange-100 dark:border-orange-950">
                        <header class="bg-orange-50 px-4 py-3 dark:bg-orange-950/30"><h3 class="text-sm font-bold uppercase tracking-wide text-orange-800 dark:text-orange-200">Pathologies associées</h3></header>
                        <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4">
                            <label v-for="field in [['cardiac', 'Cardiaque'], ['respiratory', 'Respiratoire'], ['renal', 'Rénale'], ['digestive', 'Digestive'], ['neurological', 'Neurologique'], ['gynecological', 'Gynécologique'], ['ent', 'ORL']]" :key="field[0]" class="text-xs font-medium text-slate-500">{{ field[1] }}<Input v-model="form.paraclinical_data.associated_pathologies[field[0]]" size="lg" placeholder="RAS ou préciser" /></label>
                        </div>
                    </section>

                    <div v-if="canEdit" class="flex justify-end"><Button size="lg" type="button" :disabled="form.processing" @click="submit('decision')"><Icon name="save" /><span class="ms-2">Enregistrer et passer à la décision</span><Icon class="ms-2" name="arrow-right" /></Button></div>
                    </div>
                    </ClinicalAccordionSection>

                    <ClinicalAccordionSection
                        :open="activeSection === 'decision'"
                        step="3"
                        tone="violet"
                        title="Décision anesthésique"
                        description="Conclusion, autorisation, classification et plan anesthésique."
                        :complete="decisionComplete"
                        @toggle="activeSection = activeSection === 'decision' ? '' : 'decision'"
                    >
                    <section class="overflow-hidden rounded-md border border-emerald-100 dark:border-emerald-950">
                        <header class="bg-emerald-50 px-4 py-3 dark:bg-emerald-950/30"><h3 class="text-sm font-bold uppercase tracking-wide text-emerald-800 dark:text-emerald-200">Conclusion et conduite anesthésique</h3></header>
                        <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
                            <label class="text-sm text-slate-500">Conclusion<textarea v-model="form.paraclinical_data.conclusion" rows="4" :class="textareaClass"></textarea></label>
                            <label class="text-sm text-slate-500">Recommandation thérapeutique<textarea v-model="form.paraclinical_data.therapeutic_recommendation" rows="4" :class="textareaClass"></textarea></label>
                        </div>
                        <div class="grid grid-cols-1 gap-4 border-t border-emerald-100 p-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-emerald-950">
                            <label class="text-sm text-slate-500">Chirurgie autorisée<select v-model="form.paraclinical_data.surgery_authorized" :class="inputClass"><option value="">Décision non renseignée</option><option :value="true">Oui</option><option :value="false">Non</option></select></label>
                            <label class="text-sm text-slate-500">Classe ASA<Input v-model="form.paraclinical_data.asa_class" size="lg" placeholder="Ex. ASA II" /></label>
                            <label class="text-sm text-slate-500">Classe NYHA<Input v-model="form.paraclinical_data.nyha_class" size="lg" placeholder="Ex. NYHA I" /></label>
                            <label class="text-sm text-slate-500">Jeûne prescrit<Input v-model="form.paraclinical_data.fasting_hours" type="number" min="0" max="72" step="0.5" placeholder="heures" /></label>
                            <label class="text-sm text-slate-500 lg:col-span-1">Plan anesthésique<textarea v-model="form.paraclinical_data.anesthesia_plan" rows="2" :class="textareaClass"></textarea></label>
                        </div>
                    </section>

                    <div class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-900">
                        <p class="text-xs text-slate-400"><Icon name="info" class="me-1" />Enregistrez toute modification avant la validation clinique définitive.</p>
                        <div class="flex flex-wrap justify-end gap-2">
                            <Button v-if="canEdit" size="lg" type="submit" :disabled="form.processing"><Icon name="save" /><span class="ms-2">Enregistrer la décision</span></Button>
                            <Button v-if="record && canValidate && !assessmentLocked" size="lg" variant="secondary" type="button" :disabled="validating || form.isDirty" title="Enregistrez d’abord les modifications" @click="validateAssessment"><Icon name="shield-check" /><span class="ms-2">Valider l’évaluation</span></Button>
                        </div>
                    </div>
                    </ClinicalAccordionSection>
                </fieldset>

                <FormError v-if="form.errors.assessment">{{ form.errors.assessment }}</FormError>
            </form>
        </CardBody>
    </Card>
</template>
