<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import ClinicalAccordionSection from './ClinicalAccordionSection.vue';
import DynamicTreatmentTable from './DynamicTreatmentTable.vue';

const props = defineProps({ surgicalRequest: Object, canEdit: Boolean });
const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const entry = props.surgicalRequest.block_entry ?? {};
const boolValue = (value) => value === true ? '1' : value === false ? '0' : '';
const activeSection = ref('preparation');

const form = useForm({
    height_cm: entry.height_cm ?? '',
    weight_kg: entry.weight_kg ?? '',
    temperature_celsius: entry.temperature_celsius ?? '',
    blood_pressure_systolic: entry.blood_pressure_systolic ?? '',
    blood_pressure_diastolic: entry.blood_pressure_diastolic ?? '',
    heart_rate: entry.heart_rate ?? '',
    oxygen_saturation: entry.oxygen_saturation ?? '',
    full_bath_completed: boolValue(entry.full_bath_completed),
    weighing_completed: boolValue(entry.weighing_completed),
    peripheral_iv_count: entry.peripheral_iv_count ?? '',
    serum_name: entry.serum_name ?? '',
    serum_quantity: entry.serum_quantity ?? '',
    serum_unit: entry.serum_unit ?? '',
    urinary_catheter_placed: boolValue(entry.urinary_catheter_placed),
    diuresis_quantity: entry.diuresis_quantity ?? '',
    diuresis_unit: entry.diuresis_unit ?? '',
    urine_appearance: entry.urine_appearance ?? '',
    catheter_placed_at: entry.catheter_placed_at?.slice(0, 16) ?? '',
});

const submit = (nextSection = null) => form.transform((data) => ({
    ...data,
    full_bath_completed: data.full_bath_completed === '' ? null : data.full_bath_completed === '1',
    weighing_completed: data.weighing_completed === '' ? null : data.weighing_completed === '1',
    urinary_catheter_placed: data.urinary_catheter_placed === '' ? null : data.urinary_catheter_placed === '1',
})).put(`${base.value}/block-entry`, {
    preserveScroll: true,
    onSuccess: () => {
        if (nextSection) activeSection.value = nextSection;
    },
});

const preliminaryItems = computed(() => (props.surgicalRequest.treatment_items ?? []).filter((item) => item.phase === 'PRELIMINARY'));
const preliminaryLocked = computed(() => ['IN_PROGRESS', 'COMPLETED', 'DISCHARGED'].includes(props.surgicalRequest.status));
const locked = computed(() => !props.canEdit || props.surgicalRequest.status === 'DISCHARGED');
const preparationComplete = computed(() => [form.full_bath_completed, form.weighing_completed, form.heart_rate, form.oxygen_saturation].some((value) => value !== ''));
const accessComplete = computed(() => [form.peripheral_iv_count, form.serum_name, form.urinary_catheter_placed, form.catheter_placed_at].some((value) => value !== ''));
const inputClass = 'mt-1 block h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <Card class="shadow-sm xl:col-span-12">
        <CardBody class="!p-0">
            <header class="flex flex-col gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300"><Icon name="signin" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Entrée au bloc</h2><p class="mt-0.5 text-xs text-slate-400">Trois tâches courtes, enregistrables progressivement.</p></div></div>
                <span v-if="locked" class="inline-flex items-center gap-1.5 self-start rounded bg-gray-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-500 dark:bg-gray-900"><Icon name="lock" /> Lecture seule</span>
            </header>

            <div class="space-y-3 p-4 sm:p-5">
                <form class="space-y-3" @submit.prevent="submit()">
                    <fieldset :disabled="locked" class="space-y-3 disabled:opacity-70">
                        <ClinicalAccordionSection
                            :open="activeSection === 'preparation'"
                            step="1"
                            title="Préparation du patient"
                            description="Hygiène et mesures complémentaires propres à l’arrivée au bloc."
                            :complete="preparationComplete"
                            @toggle="activeSection = activeSection === 'preparation' ? '' : 'preparation'"
                        >
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.8fr)]">
                                <section class="rounded-md border border-blue-100 bg-blue-50/40 p-4 dark:border-blue-950 dark:bg-blue-950/10">
                                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">Mesures complémentaires</h3>
                                    <p class="mt-1 text-[11px] leading-4 text-slate-400">Taille, poids, température et tension proviennent de Soins et ne sont pas redemandés.</p>
                                    <div class="mt-3 grid grid-cols-2 gap-3"><label class="text-xs text-slate-500">Fréquence cardiaque<Input v-model="form.heart_rate" size="lg" type="number" min="20" max="300" placeholder="batt/min" /></label><label class="text-xs text-slate-500">SpO₂<Input v-model="form.oxygen_saturation" size="lg" type="number" min="0" max="100" placeholder="%" /></label></div>
                                </section>
                                <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900">
                                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Préparation préopératoire</h3>
                                    <div class="mt-3 grid grid-cols-2 gap-3"><label class="text-xs text-slate-500">Toilette complète<select v-model="form.full_bath_completed" :class="inputClass"><option value="">Non renseigné</option><option value="1">Oui</option><option value="0">Non</option></select></label><label class="text-xs text-slate-500">Pesage réalisé<select v-model="form.weighing_completed" :class="inputClass"><option value="">Non renseigné</option><option value="1">Oui</option><option value="0">Non</option></select></label></div>
                                </section>
                            </div>
                            <div v-if="!locked" class="mt-4 flex justify-end"><Button size="rg" type="button" :disabled="form.processing" @click="submit('access')"><Icon name="save" /><span class="ms-2">Enregistrer et continuer</span><Icon class="ms-2" name="arrow-right" /></Button></div>
                        </ClinicalAccordionSection>

                        <ClinicalAccordionSection
                            :open="activeSection === 'access'"
                            step="2"
                            title="Accès et dispositifs"
                            description="Voie veineuse, perfusion et sonde urinaire."
                            :complete="accessComplete"
                            @toggle="activeSection = activeSection === 'access' ? '' : 'access'"
                        >
                            <div class="grid gap-4 lg:grid-cols-2">
                                <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900"><h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Voie veineuse</h3><div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="text-xs text-slate-500">Nombre de VVP<select v-model="form.peripheral_iv_count" :class="inputClass"><option value="">Non renseigné</option><option value="1">1 VVP</option><option value="2">2 VVP</option></select></label><label class="text-xs text-slate-500">Sérum<Input v-model="form.serum_name" size="lg" placeholder="Désignation" /></label><label class="text-xs text-slate-500">Quantité<Input v-model="form.serum_quantity" size="lg" type="number" min="0" step="0.01" /></label><label class="text-xs text-slate-500">Unité<Input v-model="form.serum_unit" size="lg" placeholder="ml, poche…" /></label></div></section>
                                <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900"><h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Sonde urinaire</h3><div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="text-xs text-slate-500">Sonde posée<select v-model="form.urinary_catheter_placed" :class="inputClass"><option value="">Non renseigné</option><option value="1">Oui</option><option value="0">Non</option></select></label><label class="text-xs text-slate-500">Date et heure<Input v-model="form.catheter_placed_at" size="lg" type="datetime-local" /></label><label class="text-xs text-slate-500">Aspect<Input v-model="form.urine_appearance" size="lg" /></label><div class="grid grid-cols-2 gap-2"><label class="text-xs text-slate-500">Diurèse<Input v-model="form.diuresis_quantity" size="lg" type="number" min="0" step="0.01" /></label><label class="text-xs text-slate-500">Unité<Input v-model="form.diuresis_unit" size="lg" /></label></div></div></section>
                            </div>
                            <div v-if="!locked" class="mt-4 flex justify-end"><Button size="rg" type="button" :disabled="form.processing" @click="submit('treatment')"><Icon name="save" /><span class="ms-2">Enregistrer et continuer</span><Icon class="ms-2" name="arrow-right" /></Button></div>
                        </ClinicalAccordionSection>
                    </fieldset>
                    <FormError v-if="form.errors.block_entry">{{ form.errors.block_entry }}</FormError>
                </form>

                <ClinicalAccordionSection
                    :open="activeSection === 'treatment'"
                    step="3"
                    title="Traitement préliminaire"
                    description="Médicaments et matériels réellement utilisés avant l’intervention."
                    :complete="preliminaryItems.length > 0"
                    @toggle="activeSection = activeSection === 'treatment' ? '' : 'treatment'"
                >
                    <DynamicTreatmentTable
                        title="Lignes enregistrées"
                        :items="preliminaryItems"
                        :categories="[{ value: 'MEDICATION', label: 'Médicament' }, { value: 'MATERIAL', label: 'Matériel' }]"
                        :store-url="`${base}/preliminary-treatments`"
                        :destroy-base-url="`${base}/preliminary-treatments`"
                        :can-edit="canEdit && !preliminaryLocked"
                    />
                </ClinicalAccordionSection>
            </div>
        </CardBody>
    </Card>
</template>
