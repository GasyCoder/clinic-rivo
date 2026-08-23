<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { formatDateTime } from '@/utilities/date';
import ClinicalAccordionSection from './ClinicalAccordionSection.vue';
import DynamicTreatmentTable from './DynamicTreatmentTable.vue';

const props = defineProps({ surgicalRequest: Object, canEdit: Boolean, canRecordPostoperative: Boolean });
const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const exit = props.surgicalRequest.block_exit ?? {};
const toLocal = (value) => value ? value.slice(0, 16).replace(' ', 'T') : '';
const activeSection = ref('vitals');

const exitForm = useForm({
    block_entered_at: toLocal(exit.block_entered_at), block_exited_at: toLocal(exit.block_exited_at),
    entry_blood_pressure_systolic: exit.entry_blood_pressure_systolic ?? '', entry_blood_pressure_diastolic: exit.entry_blood_pressure_diastolic ?? '',
    entry_heart_rate: exit.entry_heart_rate ?? '', entry_oxygen_saturation: exit.entry_oxygen_saturation ?? '', entry_respiratory_rate: exit.entry_respiratory_rate ?? '', entry_temperature_celsius: exit.entry_temperature_celsius ?? '',
    exit_blood_pressure_systolic: exit.exit_blood_pressure_systolic ?? '', exit_blood_pressure_diastolic: exit.exit_blood_pressure_diastolic ?? '',
    exit_heart_rate: exit.exit_heart_rate ?? '', exit_oxygen_saturation: exit.exit_oxygen_saturation ?? '', exit_respiratory_rate: exit.exit_respiratory_rate ?? '', exit_temperature_celsius: exit.exit_temperature_celsius ?? '',
    perfusion_serum: exit.perfusion_serum ?? '', perfusion_bag: exit.perfusion_bag ?? '',
    transfusion_blood: exit.transfusion_blood ?? '', transfusion_quantity: exit.transfusion_quantity ?? '', transfusion_unit: exit.transfusion_unit ?? '',
    urine_appearance: exit.urine_appearance ?? '', urine_quantity: exit.urine_quantity ?? '', urine_unit: exit.urine_unit ?? '',
    blood_loss_quantity: exit.blood_loss_quantity ?? '', blood_loss_unit: exit.blood_loss_unit ?? '',
    drug_name: exit.drug_name ?? '', drug_quantity: exit.drug_quantity ?? '', drug_unit: exit.drug_unit ?? '',
    antibiotic_name: exit.antibiotic_name ?? '', antibiotic_quantity: exit.antibiotic_quantity ?? '', antibiotic_unit: exit.antibiotic_unit ?? '',
    awakening_status: exit.awakening_status ?? '', awakening_score: exit.awakening_score ?? '',
});
const submitExit = (nextSection = null) => exitForm.put(`${base.value}/block-exit`, {
    preserveScroll: true,
    onSuccess: () => {
        if (nextSection) activeSection.value = nextSection;
    },
});

const observationForm = useForm({
    observed_at: '', diuresis_quantity: '', diuresis_unit: '', temperature_celsius: '',
    blood_pressure_systolic: '', blood_pressure_diastolic: '', heart_rate: '', oxygen_saturation: '',
});
const submitObservation = () => observationForm.post(`${base.value}/postoperative-observations`, {
    preserveScroll: true,
    onSuccess: () => observationForm.reset(),
});
const removeObservation = (observation) => router.delete(`${base.value}/postoperative-observations/${observation.id}`, { preserveScroll: true });
const observations = computed(() => props.surgicalRequest.observations ?? []);
const postoperativeItems = computed(() => (props.surgicalRequest.treatment_items ?? []).filter((item) => item.phase === 'POSTOPERATIVE'));
const canUseExit = computed(() => ['IN_PROGRESS', 'COMPLETED'].includes(props.surgicalRequest.status));
const canEditPostoperative = computed(() => props.canRecordPostoperative && canUseExit.value);
const vitalsComplete = computed(() => Boolean(exitForm.block_exited_at || exitForm.exit_heart_rate || exitForm.exit_oxygen_saturation));
const balanceComplete = computed(() => Boolean(exitForm.perfusion_serum || exitForm.transfusion_blood || exitForm.urine_quantity || exitForm.awakening_status));

const awakeningLabels = {
    PERFECTLY_AWAKE: 'Parfaitement réveillé',
    RESPONDS_TO_REQUEST: 'Se réveille à la demande',
    NO_SIMPLE_COMMAND_RESPONSE: 'Ne répond pas aux ordres simples',
};
</script>

<template>
    <Card class="shadow-sm xl:col-span-12">
        <CardBody class="!p-0">
            <header class="flex flex-col gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300"><Icon name="signout" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Sortie du bloc et surveillance</h2><p class="mt-0.5 text-xs text-slate-400">Bilan de sortie, réveil puis surveillance postopératoire.</p></div></div>
                <span v-if="!canUseExit" class="inline-flex items-center gap-1.5 self-start rounded bg-amber-50 px-2 py-1 text-[10px] font-bold uppercase text-amber-700 dark:bg-amber-950/20 dark:text-amber-300"><Icon name="clock" /> Disponible après le démarrage</span>
            </header>

            <div class="space-y-3 p-4 sm:p-5">
                <form class="space-y-3" @submit.prevent="submitExit()">
                    <fieldset :disabled="!canEdit || !canUseExit" class="space-y-3 disabled:opacity-60">
                        <ClinicalAccordionSection
                            :open="activeSection === 'vitals'"
                            step="1"
                            title="Horaires et paramètres"
                            description="Comparer les mesures à l’entrée et à la sortie du bloc."
                            :complete="vitalsComplete"
                            @toggle="activeSection = activeSection === 'vitals' ? '' : 'vitals'"
                        >
                            <div class="grid gap-3 sm:grid-cols-2"><label class="text-xs text-slate-500">Heure d’entrée<Input v-model="exitForm.block_entered_at" size="lg" type="datetime-local" /></label><label class="text-xs text-slate-500">Heure de sortie<Input v-model="exitForm.block_exited_at" size="lg" type="datetime-local" /></label></div>
                            <div class="mt-4 overflow-x-auto rounded-md border border-gray-200 dark:border-gray-900">
                                <table class="w-full min-w-[720px] border-collapse text-sm">
                                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Paramètre</th><th class="px-3 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Entrée</th><th class="px-3 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Sortie</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Unité</th></tr></thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                                        <tr><td class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300">PA systolique</td><td class="p-2"><Input v-model="exitForm.entry_blood_pressure_systolic" type="number" min="40" max="300" /></td><td class="p-2"><Input v-model="exitForm.exit_blood_pressure_systolic" type="number" min="40" max="300" /></td><td class="px-4 text-xs text-slate-400">mmHg</td></tr>
                                        <tr><td class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300">PA diastolique</td><td class="p-2"><Input v-model="exitForm.entry_blood_pressure_diastolic" type="number" min="20" max="200" /></td><td class="p-2"><Input v-model="exitForm.exit_blood_pressure_diastolic" type="number" min="20" max="200" /></td><td class="px-4 text-xs text-slate-400">mmHg</td></tr>
                                        <tr><td class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300">Fréquence cardiaque</td><td class="p-2"><Input v-model="exitForm.entry_heart_rate" type="number" min="20" max="300" /></td><td class="p-2"><Input v-model="exitForm.exit_heart_rate" type="number" min="20" max="300" /></td><td class="px-4 text-xs text-slate-400">batt/min</td></tr>
                                        <tr><td class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300">SpO₂</td><td class="p-2"><Input v-model="exitForm.entry_oxygen_saturation" type="number" min="0" max="100" /></td><td class="p-2"><Input v-model="exitForm.exit_oxygen_saturation" type="number" min="0" max="100" /></td><td class="px-4 text-xs text-slate-400">%</td></tr>
                                        <tr><td class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300">Fréquence respiratoire</td><td class="p-2"><Input v-model="exitForm.entry_respiratory_rate" type="number" min="1" max="150" /></td><td class="p-2"><Input v-model="exitForm.exit_respiratory_rate" type="number" min="1" max="150" /></td><td class="px-4 text-xs text-slate-400">cycles/min</td></tr>
                                        <tr><td class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300">Température</td><td class="p-2"><Input v-model="exitForm.entry_temperature_celsius" type="number" min="25" max="45" step="0.01" /></td><td class="p-2"><Input v-model="exitForm.exit_temperature_celsius" type="number" min="25" max="45" step="0.01" /></td><td class="px-4 text-xs text-slate-400">°C</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="canEdit && canUseExit" class="mt-4 flex justify-end"><Button size="rg" type="button" :disabled="exitForm.processing" @click="submitExit('balance')"><Icon name="save" /><span class="ms-2">Enregistrer et continuer</span><Icon class="ms-2" name="arrow-right" /></Button></div>
                        </ClinicalAccordionSection>

                        <ClinicalAccordionSection
                            :open="activeSection === 'balance'"
                            step="2"
                            title="Bilan peropératoire et réveil"
                            description="Apports, sorties, doses reçues et état de réveil."
                            :complete="balanceComplete"
                            @toggle="activeSection = activeSection === 'balance' ? '' : 'balance'"
                        >
                            <div class="grid gap-4 lg:grid-cols-3">
                                <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900"><h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Apports</h3><div class="mt-3 space-y-2"><Input v-model="exitForm.perfusion_serum" placeholder="Perfusion — sérum" /><Input v-model="exitForm.perfusion_bag" placeholder="Poche" /><Input v-model="exitForm.transfusion_blood" placeholder="Transfusion — sang" /><div class="grid grid-cols-2 gap-2"><Input v-model="exitForm.transfusion_quantity" type="number" min="0" step="0.01" placeholder="Quantité" /><Input v-model="exitForm.transfusion_unit" placeholder="Unité" /></div></div></section>
                                <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900"><h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Sorties</h3><div class="mt-3 space-y-2"><Input v-model="exitForm.urine_appearance" placeholder="Urine — aspect" /><div class="grid grid-cols-2 gap-2"><Input v-model="exitForm.urine_quantity" type="number" min="0" step="0.01" placeholder="Quantité" /><Input v-model="exitForm.urine_unit" placeholder="Unité" /></div><div class="grid grid-cols-2 gap-2"><Input v-model="exitForm.blood_loss_quantity" type="number" min="0" step="0.01" placeholder="Pertes sanguines" /><Input v-model="exitForm.blood_loss_unit" placeholder="Unité" /></div></div></section>
                                <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900"><h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Doses reçues</h3><div class="mt-3 space-y-2"><Input v-model="exitForm.drug_name" placeholder="Drogue" /><div class="grid grid-cols-2 gap-2"><Input v-model="exitForm.drug_quantity" type="number" min="0" step="0.01" placeholder="Quantité" /><Input v-model="exitForm.drug_unit" placeholder="Unité" /></div><Input v-model="exitForm.antibiotic_name" placeholder="Antibiotique" /><div class="grid grid-cols-2 gap-2"><Input v-model="exitForm.antibiotic_quantity" type="number" min="0" step="0.01" placeholder="Quantité" /><Input v-model="exitForm.antibiotic_unit" placeholder="Unité" /></div></div></section>
                            </div>
                            <section class="mt-4 rounded-md border border-gray-200 p-4 dark:border-gray-900"><h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">État de réveil</h3><div class="mt-3 grid gap-2 lg:grid-cols-3"><label v-for="(label, value) in awakeningLabels" :key="value" :class="['flex min-h-11 cursor-pointer items-center gap-2 rounded border px-3 py-2 text-xs transition-colors', exitForm.awakening_status === value ? 'border-primary-300 bg-primary-50 font-semibold text-primary-700 dark:border-primary-900 dark:bg-primary-950/20 dark:text-primary-300' : 'border-gray-200 text-slate-500 dark:border-gray-800']"><input v-model="exitForm.awakening_status" type="radio" :value="value" class="h-4 w-4" />{{ label }}</label></div><label class="mt-3 block max-w-sm text-xs text-slate-500">Score utilisé par la clinique<Input v-model="exitForm.awakening_score" placeholder="Valeur confirmée" /></label></section>
                            <div v-if="canEdit && canUseExit" class="mt-4 flex justify-end"><Button size="rg" type="button" :disabled="exitForm.processing" @click="submitExit('postoperative')"><Icon name="save" /><span class="ms-2">Enregistrer et continuer</span><Icon class="ms-2" name="arrow-right" /></Button></div>
                        </ClinicalAccordionSection>
                    </fieldset>
                    <FormError v-if="exitForm.errors.block_exit">{{ exitForm.errors.block_exit }}</FormError>
                    <FormError v-if="exitForm.errors.block_exited_at">{{ exitForm.errors.block_exited_at }}</FormError>
                </form>

                <ClinicalAccordionSection
                    :open="activeSection === 'postoperative'"
                    step="3"
                    title="Surveillance postopératoire"
                    description="Constantes successives et traitements après la sortie du bloc."
                    :complete="observations.length > 0 || postoperativeItems.length > 0"
                    @toggle="activeSection = activeSection === 'postoperative' ? '' : 'postoperative'"
                >
                    <section>
                        <form v-if="canEditPostoperative" class="grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-8" @submit.prevent="submitObservation">
                            <Input v-model="observationForm.observed_at" type="datetime-local" required />
                            <Input v-model="observationForm.diuresis_quantity" type="number" min="0" step="0.01" placeholder="Diurèse" />
                            <Input v-model="observationForm.diuresis_unit" placeholder="Unité" />
                            <Input v-model="observationForm.temperature_celsius" type="number" min="25" max="45" step="0.01" placeholder="T° °C" />
                            <Input v-model="observationForm.blood_pressure_systolic" type="number" min="40" max="300" placeholder="TA syst." />
                            <Input v-model="observationForm.blood_pressure_diastolic" type="number" min="20" max="200" placeholder="TA diast." />
                            <Input v-model="observationForm.heart_rate" type="number" min="20" max="300" placeholder="FC" />
                            <div class="flex gap-2"><Input v-model="observationForm.oxygen_saturation" type="number" min="0" max="100" placeholder="SpO₂ %" /><Button icon type="submit" aria-label="Ajouter"><Icon name="plus" /></Button></div>
                        </form>
                        <FormError v-if="observationForm.errors.observation">{{ observationForm.errors.observation }}</FormError>

                        <div class="mt-3 overflow-x-auto rounded-md border border-gray-200 dark:border-gray-900">
                            <table class="w-full min-w-[720px] border-collapse text-sm"><thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-3 py-2.5 text-start text-[10px] font-bold uppercase text-slate-400">Heure</th><th class="text-[10px] font-bold uppercase text-slate-400">Diurèse</th><th class="text-[10px] font-bold uppercase text-slate-400">T°</th><th class="text-[10px] font-bold uppercase text-slate-400">TA</th><th class="text-[10px] font-bold uppercase text-slate-400">FC</th><th class="text-[10px] font-bold uppercase text-slate-400">SpO₂</th><th class="w-12"></th></tr></thead><tbody class="divide-y divide-gray-200 dark:divide-gray-900"><tr v-for="observation in observations" :key="observation.id"><td class="px-3 py-2 text-xs text-slate-600 dark:text-slate-300">{{ formatDateTime(observation.observed_at) }}</td><td class="text-center text-xs text-slate-500">{{ observation.diuresis_quantity ?? '—' }} {{ observation.diuresis_unit ?? '' }}</td><td class="text-center text-xs text-slate-500">{{ observation.temperature_celsius ?? '—' }}</td><td class="text-center text-xs text-slate-500">{{ observation.blood_pressure_systolic ?? '—' }}/{{ observation.blood_pressure_diastolic ?? '—' }}</td><td class="text-center text-xs text-slate-500">{{ observation.heart_rate ?? '—' }}</td><td class="text-center text-xs text-slate-500">{{ observation.oxygen_saturation ?? '—' }}</td><td><button v-if="canEditPostoperative" type="button" class="flex h-8 w-8 items-center justify-center rounded text-red-500 hover:bg-red-50 dark:hover:bg-red-950" @click="removeObservation(observation)"><Icon name="trash" /></button></td></tr><tr v-if="observations.length === 0"><td colspan="7" class="px-3 py-5 text-center text-xs text-slate-400">Aucune constante enregistrée.</td></tr></tbody></table>
                        </div>
                    </section>

                    <div class="mt-4">
                        <DynamicTreatmentTable
                            title="Traitement postopératoire"
                            :items="postoperativeItems"
                            :categories="[
                                { value: 'SERUM', label: 'Sérum' },
                                { value: 'ANTIBIOTIC', label: 'Antibiotique' },
                                { value: 'ANALGESIC_NSAID', label: 'Antalgique / AINS' },
                                { value: 'OTHER', label: 'Autre médicament' },
                                { value: 'MATERIAL', label: 'Matériel' },
                            ]"
                            :store-url="`${base}/postoperative-treatments`"
                            :destroy-base-url="`${base}/postoperative-treatments`"
                            :can-edit="canEditPostoperative"
                        />
                    </div>
                </ClinicalAccordionSection>
            </div>
        </CardBody>
    </Card>
</template>
