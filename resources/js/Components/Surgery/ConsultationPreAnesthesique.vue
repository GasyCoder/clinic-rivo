<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
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
        gyneco_obstetric: { ...gynecoDefaults, ...(existing.gyneco_obstetric ?? {}) },
        clinical_exam: { ...clinicalDefaults, ...(existing.clinical_exam ?? {}) },
    },
});

const inputClass = 'mt-1 block min-h-11 w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const textareaClass = `${inputClass} resize-y`;
const assessmentLocked = computed(() => Boolean(record.value?.assessment_validated_at));
const canEdit = computed(() => !assessmentLocked.value && (record.value ? props.canUpdate : props.canCreate));
const isFemale = computed(() => String(patient.value.sex ?? '') === 'F');
const activeSection = ref('history');
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
</script>

<template>
    <Card class="shadow-sm xl:col-span-12">
        <CardBody class="!p-0">
            <div class="border-b border-violet-100 bg-violet-50/80 px-4 py-3 dark:border-violet-950 dark:bg-violet-950/30 sm:px-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-violet-600">Étape anesthésie · 1/3</p>
                        <h2 class="mt-0.5 flex items-center gap-2 font-heading text-base font-bold text-slate-700 dark:text-white">
                            <Icon name="user-round-search" /> Consultation pré-anesthésique
                        </h2>
                        <p class="mt-1 max-w-3xl text-sm text-slate-500">Deux sous-étapes courtes. Les données déjà enregistrées aux Soins restent consultables au-dessus, sans nouvelle saisie.</p>
                    </div>
                    <span v-if="assessmentLocked" class="inline-flex items-center gap-1.5 self-start rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-700 dark:bg-green-950 dark:text-green-300"><Icon name="check-circle" /> Évaluation validée</span>
                    <span v-else-if="!canEdit" class="inline-flex items-center gap-1.5 self-start rounded-full bg-gray-100 px-3 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900"><Icon name="eye" /> Lecture seule</span>
                </div>
            </div>

            <form class="space-y-4 p-4 sm:p-5" @submit.prevent="submit()">
                <fieldset :disabled="!canEdit" class="space-y-3 disabled:opacity-70">
                    <ClinicalAccordionSection
                        :open="activeSection === 'history'"
                        step="1"
                        tone="violet"
                        title="Antécédents"
                        description="Motif, habitudes, antécédents médicaux, anesthésiques et chirurgicaux."
                        icon="file-text"
                        :complete="historyComplete"
                        @toggle="activeSection = activeSection === 'history' ? '' : 'history'"
                    >
                    <div class="space-y-4">
                    <section class="rounded-md border border-emerald-100 bg-emerald-50/40 p-4 dark:border-emerald-950 dark:bg-emerald-950/20">
                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <label class="text-sm font-medium text-slate-600 dark:text-slate-300">Motif d’entrée
                                <textarea v-model="form.consultation_data.admission_reason" rows="2" :class="textareaClass" placeholder="Motif clinique de l’admission"></textarea>
                            </label>
                            <div class="rounded-md border border-emerald-100 bg-white/70 px-4 py-3 text-sm dark:border-emerald-950 dark:bg-gray-950/50">
                                <span class="block text-xs font-bold uppercase tracking-wide text-slate-400">Intervention prévue</span>
                                <strong class="mt-1 block text-slate-700 dark:text-white">{{ surgicalRequest.procedure_name }}</strong>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <label class="text-sm text-slate-500">Tabac<Input v-model="form.consultation_data.tobacco" size="lg" placeholder="Non / quantité / durée" /></label>
                            <label class="text-sm text-slate-500">Alcool<Input v-model="form.consultation_data.alcohol" size="lg" placeholder="Non / fréquence" /></label>
                            <label class="text-sm text-slate-500">Autre toxique<Input v-model="form.consultation_data.other_toxic_exposure" size="lg" placeholder="Préciser" /></label>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-md border border-amber-100 dark:border-amber-950">
                        <header class="bg-amber-50 px-4 py-3 dark:bg-amber-950/30"><h3 class="text-xs font-bold uppercase tracking-wide text-amber-800 dark:text-amber-200">Détails des antécédents</h3></header>
                        <div class="grid grid-cols-1 divide-y divide-amber-100 dark:divide-amber-950 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                            <div class="space-y-4 p-4">
                                <h4 class="text-xs font-bold uppercase text-slate-400">Médicaux</h4>
                                <div class="grid grid-cols-2 gap-2">
                                    <label v-for="condition in medicalConditions" :key="condition[0]" class="flex min-h-10 cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-xs font-medium text-slate-600 dark:border-gray-800 dark:text-slate-300">
                                        <input v-model="form.consultation_data.medical_conditions" type="checkbox" :value="condition[0]" class="h-4 w-4 rounded border-gray-300 text-primary-600" />
                                        {{ condition[1] }}
                                    </label>
                                </div>
                                <textarea v-model="form.consultation_data.medical_history_notes" rows="3" :class="textareaClass" placeholder="Autres antécédents ou précisions"></textarea>
                                <div class="grid grid-cols-2 gap-2"><Input v-model="form.consultation_data.cough_duration" placeholder="Durée de la toux" /><Input v-model="form.consultation_data.sputum" placeholder="Crachat" /></div>
                                <Input v-model="form.consultation_data.pain_notes" placeholder="Description de la douleur" />
                            </div>

                            <div class="space-y-4 p-4">
                                <h4 class="text-xs font-bold uppercase text-slate-400">Anesthésiques et chirurgicaux</h4>
                                <label class="block text-sm text-slate-500">Antécédents anesthésiques<textarea v-model="form.consultation_data.anesthetic_history" rows="4" :class="textareaClass"></textarea></label>
                                <label class="block text-sm text-slate-500">Antécédents chirurgicaux<textarea v-model="form.consultation_data.surgical_history" rows="4" :class="textareaClass"></textarea></label>
                                <label class="block text-sm text-slate-500">Incident antérieur<textarea v-model="form.consultation_data.anesthetic_incidents" rows="3" :class="textareaClass"></textarea></label>
                            </div>

                            <div class="space-y-4 p-4">
                                <h4 class="text-xs font-bold uppercase text-slate-400">Gynéco-obstétricaux</h4>
                                <p v-if="!isFemale" class="rounded-md bg-gray-50 p-3 text-xs text-slate-400 dark:bg-gray-1000">Section affichée en lecture contextuelle ; le sexe administratif du patient n’est pas féminin.</p>
                                <div class="grid grid-cols-3 gap-2"><label class="text-xs text-slate-500">G<Input v-model="form.consultation_data.gyneco_obstetric.gravida" type="number" min="0" /></label><label class="text-xs text-slate-500">P<Input v-model="form.consultation_data.gyneco_obstetric.para" type="number" min="0" /></label><label class="text-xs text-slate-500">A<Input v-model="form.consultation_data.gyneco_obstetric.abortion" type="number" min="0" /></label></div>
                                <label class="block text-sm text-slate-500">DDR<Input v-model="form.consultation_data.gyneco_obstetric.last_menstrual_period" type="date" /></label>
                                <div class="grid grid-cols-2 gap-2"><label class="text-xs text-slate-500">Contraception<select v-model="form.consultation_data.gyneco_obstetric.contraception" :class="inputClass"><option value="">Non renseignée</option><option value="ORAL">Orale</option><option value="INJECTION">Injection</option></select></label><label class="text-xs text-slate-500">Date<Input v-model="form.consultation_data.gyneco_obstetric.contraception_date" type="date" /></label></div>
                                <div class="grid grid-cols-2 gap-2"><label class="text-xs text-slate-500">Accouchement<select v-model="form.consultation_data.gyneco_obstetric.delivery_route" :class="inputClass"><option value="">Non renseigné</option><option value="VAGINAL">Voie basse</option><option value="CESAREAN">Césarienne</option></select></label><label class="text-xs text-slate-500">Date<Input v-model="form.consultation_data.gyneco_obstetric.delivery_date" type="date" /></label></div>
                                <label class="block text-sm text-slate-500">Parité<select v-model="form.consultation_data.gyneco_obstetric.parity_status" :class="inputClass"><option value="">Non renseignée</option><option value="PRIMIPAROUS">Primipare</option><option value="MULTIPAROUS">Multipare</option></select></label>
                                <label class="block text-sm text-slate-500">Hémorragie obstétricale<select v-model="form.consultation_data.gyneco_obstetric.obstetric_hemorrhage" :class="inputClass"><option value="">Non renseignée</option><option :value="true">Oui</option><option :value="false">Non</option></select></label>
                                <textarea v-model="form.consultation_data.gyneco_obstetric.notes" rows="2" :class="textareaClass" placeholder="Précisions"></textarea>
                            </div>
                        </div>
                    </section>

                    <div v-if="canEdit" class="flex flex-col gap-3 rounded-md bg-gray-50 p-3 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-1000">
                        <p class="text-xs text-slate-400">Enregistrez ce volet ; l’examen clinique s’ouvrira automatiquement.</p>
                        <Button size="lg" type="button" :disabled="form.processing" @click="submit('exam')"><Icon name="save" /><span class="ms-2">Enregistrer et continuer</span><Icon class="ms-2" name="arrow-right" /></Button>
                    </div>
                    </div>
                    </ClinicalAccordionSection>

                    <ClinicalAccordionSection
                        :open="activeSection === 'exam'"
                        step="2"
                        tone="violet"
                        title="Examen clinique"
                        description="Examen par systèmes, voies aériennes et mesures spécifiques à l’anesthésie."
                        icon="activity"
                        :complete="examComplete"
                        @toggle="activeSection = activeSection === 'exam' ? '' : 'exam'"
                    >
                    <div class="space-y-4">
                    <section class="overflow-hidden rounded-md border border-orange-100 dark:border-orange-950">
                        <div class="grid grid-cols-1 gap-5 p-4 xl:grid-cols-3">
                            <div class="space-y-3">
                                <h4 class="text-xs font-bold uppercase text-slate-400">Systèmes</h4>
                                <label v-for="field in [['cardiovascular', 'Cardio-vasculaire'], ['pulmonary', 'Pulmonaire'], ['neurological', 'Neurologique'], ['coloration', 'Coloration'], ['venous_access', 'Abord veineux'], ['spinal_access', 'Abord rachidien']]" :key="field[0]" class="block text-xs text-slate-500">{{ field[1] }}<Input v-model="form.consultation_data.clinical_exam[field[0]]" /></label>
                            </div>
                            <div class="space-y-3">
                                <h4 class="text-xs font-bold uppercase text-slate-400">Mesures complémentaires</h4>
                                <p v-if="careSummary?.can_view_vitals" class="rounded border border-blue-100 bg-blue-50/60 p-2.5 text-[11px] leading-4 text-blue-700 dark:border-blue-950 dark:bg-blue-950/20 dark:text-blue-300"><Icon name="info" class="me-1" />TA, température, poids et taille proviennent de Soins et ne sont pas ressaisis ici.</p>
                                <div class="grid grid-cols-2 gap-2"><label class="text-xs text-slate-500">FC<Input v-model="form.consultation_data.heart_rate" type="number" min="20" max="300" placeholder="batt/min" /></label><label class="text-xs text-slate-500">SpO₂<Input v-model="form.consultation_data.oxygen_saturation" type="number" min="0" max="100" placeholder="%" /></label></div>
                                <label class="block text-xs text-slate-500">FR<Input v-model="form.consultation_data.respiratory_rate" type="number" min="1" max="150" placeholder="cycles/min" /></label>
                            </div>
                            <div class="space-y-3">
                                <h4 class="text-xs font-bold uppercase text-slate-400">Voies aériennes et prothèses</h4>
                                <label v-for="field in [['mouth_opening', 'Ouverture buccale'], ['mallampati', 'Mallampati'], ['thyromental_distance', 'Distance thyromentonnière'], ['cervical_spine', 'Rachis cervical'], ['dental_prosthesis', 'Prothèse dentaire'], ['other_prosthesis', 'Autre prothèse']]" :key="field[0]" class="block text-xs text-slate-500">{{ field[1] }}<Input v-model="form.consultation_data[field[0]]" /></label>
                            </div>
                        </div>
                    </section>

                    <section class="grid grid-cols-1 gap-4 rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-900 dark:bg-gray-1000 lg:grid-cols-3">
                        <label class="text-sm text-slate-500">Heure du dernier repas<Input v-model="form.consultation_data.last_meal_time" size="lg" type="time" /></label>
                        <label class="text-sm text-slate-500">Heure de la dernière boisson<Input v-model="form.consultation_data.last_drink_time" size="lg" type="time" /></label>
                        <label class="text-sm text-slate-500">État neuropsychologique<select v-model="form.consultation_data.neuropsychological_status" :class="inputClass"><option value="">Non renseigné</option><option value="CALM">Calme</option><option value="RELAXED">Détendu(e)</option><option value="ANXIOUS">Anxieux(se)</option><option value="AGITATED">Agité(e)</option></select></label>
                    </section>

                    <div v-if="canEdit" class="flex justify-end border-t border-gray-100 pt-4 dark:border-gray-900">
                        <Button size="lg" type="submit" :disabled="form.processing"><Icon name="save" /><span class="ms-2">Enregistrer l’examen</span></Button>
                    </div>
                    </div>
                    </ClinicalAccordionSection>
                </fieldset>

                <FormError v-if="form.errors.assessment">{{ form.errors.assessment }}</FormError>
            </form>
        </CardBody>
    </Card>
</template>
