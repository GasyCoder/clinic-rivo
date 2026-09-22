<script setup>
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import CardBody from '@/Components/Shadcn/CardContent.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/Surgery/SurgeryIcon.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { formatDateTime } from '@/utilities/date';
import { cn } from '@/lib/cn';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import { useAutosave } from '@/composables/useAutosave';
import { ArrowDown, ArrowDownToLine, ArrowUp, ArrowUpFromLine, BedDouble, ClipboardCheck, Clock, Droplet, Droplets, Eye, FlaskConical, Gauge, GaugeCircle, Hand, HeartPulse, LogIn, LogOut, Minus, Moon, Package, Pill, Plus, ShieldPlus, Sun, Syringe, Thermometer, Trash2, Wind, ArrowRight } from 'lucide-vue-next';
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
// Les constantes d'entrée et de sortie du bloc, une ligne par paramètre. Les
// bornes sont celles déjà appliquées par le formulaire ; le serveur revalide.
const VITAL_ROWS = [
    { key: 'blood_pressure_systolic', label: 'PA systolique', unit: 'mmHg', icon: Gauge, min: 40, max: 300 },
    { key: 'blood_pressure_diastolic', label: 'PA diastolique', unit: 'mmHg', icon: GaugeCircle, min: 20, max: 200 },
    { key: 'heart_rate', label: 'Fréquence cardiaque', unit: 'batt/min', icon: HeartPulse, min: 20, max: 300 },
    { key: 'oxygen_saturation', label: 'SpO₂', unit: '%', icon: Droplet, min: 0, max: 100 },
    { key: 'respiratory_rate', label: 'Fréquence respiratoire', unit: 'cycles/min', icon: Wind, min: 1, max: 150 },
    { key: 'temperature_celsius', label: 'Température', unit: '°C', icon: Thermometer, min: 25, max: 45, step: '0.01' },
];

// Écart sortie − entrée : de l'arithmétique d'affichage, jamais une interprétation.
const vitalDelta = (key) => {
    const entry = exitForm[`entry_${key}`];
    const exitValue = exitForm[`exit_${key}`];
    if (entry === '' || entry === null || exitValue === '' || exitValue === null) return null;
    const delta = Number(exitValue) - Number(entry);
    if (!Number.isFinite(delta)) return null;
    return Math.round(delta * 100) / 100;
};

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
// Plus de bouton « Enregistrer » : le bilan s'enregistre tout seul quelques
// instants après la dernière saisie, par la même route et les mêmes droits.
const autosave = useAutosave(exitForm, (options) => exitForm.put(`${base.value}/block-exit`, options), {
    enabled: () => props.canEdit && canUseExit.value,
});
/** « Suivant » : enregistre ce qui reste, puis ouvre la section suivante. */
const next = (nextSection) => autosave.flush(() => { activeSection.value = nextSection; });
const vitalsComplete = computed(() => Boolean(exitForm.block_exited_at || exitForm.exit_heart_rate || exitForm.exit_oxygen_saturation));
const balanceComplete = computed(() => Boolean(exitForm.perfusion_serum || exitForm.transfusion_blood || exitForm.urine_quantity || exitForm.awakening_status));

const awakeningLabels = {
    PERFECTLY_AWAKE: 'Parfaitement réveillé',
    RESPONDS_TO_REQUEST: 'Se réveille à la demande',
    NO_SIMPLE_COMMAND_RESPONSE: 'Ne répond pas aux ordres simples',
};
// Une icône par état, en plus du libellé : jamais la couleur seule.
const awakeningIcons = { PERFECTLY_AWAKE: Sun, RESPONDS_TO_REQUEST: Hand, NO_SIMPLE_COMMAND_RESPONSE: Moon };
</script>

<template>
    <Card class="shadow-sm xl:col-span-12">
        <CardBody class="!p-0">
            <header class="flex flex-col gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300"><Icon name="signout" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Sortie du bloc et surveillance</h2><p class="mt-0.5 text-xs text-slate-400">Bilan de sortie, réveil puis surveillance postopératoire.</p></div></div>
                <span v-if="!canUseExit" class="inline-flex items-center gap-1.5 self-start rounded bg-amber-50 px-2 py-1 text-[10px] font-bold uppercase text-amber-700 dark:bg-amber-950/20 dark:text-amber-300"><Icon name="clock" /> Disponible après le démarrage</span>
            </header>

            <div class="space-y-3 p-4 sm:p-5">
                <form class="space-y-3" @submit.prevent="autosave.flush()">
                    <fieldset :disabled="!canEdit || !canUseExit" class="space-y-3 disabled:opacity-60">
                        <ClinicalAccordionSection
                            :open="activeSection === 'vitals'"
                            step="1"
                            title="Horaires et paramètres"
                            description="Comparer les mesures à l’entrée et à la sortie du bloc."
                            :complete="vitalsComplete"
                            @toggle="activeSection = activeSection === 'vitals' ? '' : 'vitals'"
                        >
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block space-y-1.5">
                                    <span class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><LogIn class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />Heure d’entrée au bloc</span>
                                    <DateTimePicker v-model="exitForm.block_entered_at" size="lg" />
                                </label>
                                <label class="block space-y-1.5">
                                    <span class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><LogOut class="h-3.5 w-3.5 text-sky-600 dark:text-sky-400" aria-hidden="true" />Heure de sortie du bloc</span>
                                    <DateTimePicker v-model="exitForm.block_exited_at" size="lg" />
                                </label>
                            </div>
                            <div class="mt-4 overflow-x-auto rounded-lg border border-border">
                                <table class="w-full min-w-[760px] border-collapse text-sm">
                                    <thead class="bg-muted/40">
                                        <tr>
                                            <th class="px-4 py-2.5 text-start text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Paramètre</th>
                                            <th class="px-3 py-2.5 text-start text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><span class="inline-flex items-center gap-1.5"><LogIn class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />Entrée</span></th>
                                            <th class="px-3 py-2.5 text-start text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><span class="inline-flex items-center gap-1.5"><LogOut class="h-3.5 w-3.5 text-sky-600 dark:text-sky-400" aria-hidden="true" />Sortie</span></th>
                                            <th class="px-3 py-2.5 text-start text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Écart</th>
                                            <th class="px-4 py-2.5 text-start text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Unité</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        <tr v-for="row in VITAL_ROWS" :key="row.key" class="transition-colors hover:bg-muted/20">
                                            <td class="px-4 py-2">
                                                <span class="flex items-center gap-2.5">
                                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary" aria-hidden="true"><component :is="row.icon" class="h-4 w-4" /></span>
                                                    <span class="text-sm font-medium text-foreground">{{ row.label }}</span>
                                                </span>
                                            </td>
                                            <td class="p-2"><Input v-model="exitForm[`entry_${row.key}`]" type="number" :min="row.min" :max="row.max" :step="row.step" :aria-label="`${row.label} à l’entrée (${row.unit})`" /></td>
                                            <td class="p-2"><Input v-model="exitForm[`exit_${row.key}`]" type="number" :min="row.min" :max="row.max" :step="row.step" :aria-label="`${row.label} à la sortie (${row.unit})`" /></td>
                                            <td class="px-3 py-2">
                                                <span v-if="vitalDelta(row.key) === null" class="text-xs text-muted-foreground">—</span>
                                                <span v-else :class="cn('inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums', vitalDelta(row.key) === 0 ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-foreground')">
                                                    <component :is="vitalDelta(row.key) > 0 ? ArrowUp : (vitalDelta(row.key) < 0 ? ArrowDown : Minus)" class="h-3 w-3" aria-hidden="true" />
                                                    {{ vitalDelta(row.key) > 0 ? '+' : '' }}{{ vitalDelta(row.key).toLocaleString('fr-FR') }}
                                                </span>
                                            </td>
                                            <td class="px-4 text-xs text-muted-foreground">{{ row.unit }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="canEdit && canUseExit" class="mt-4 flex items-center justify-end gap-3"><ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="exitForm.isDirty" :failed="autosave.failed.value" /><Button size="rg" type="button" :disabled="exitForm.processing" @click="next('balance')">Suivant<ArrowRight class="h-4 w-4" aria-hidden="true" /></Button></div>
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
                                <!-- Apports -->
                                <section class="rounded-lg border border-border bg-card p-4">
                                    <h3 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        <span class="grid h-7 w-7 place-items-center rounded-md bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300" aria-hidden="true"><ArrowDownToLine class="h-3.5 w-3.5" /></span>Apports
                                    </h3>
                                    <div class="mt-3 space-y-3">
                                        <label class="block space-y-1"><span class="text-xs text-muted-foreground">Perfusion — sérum</span><IconInput v-model="exitForm.perfusion_serum" :icon="Syringe" placeholder="Ex. Ringer lactate" /></label>
                                        <label class="block space-y-1"><span class="text-xs text-muted-foreground">Poche</span><IconInput v-model="exitForm.perfusion_bag" :icon="Package" placeholder="Ex. 500 ml" /></label>
                                        <div class="space-y-1">
                                            <span class="text-xs text-muted-foreground">Transfusion — sang</span>
                                            <IconInput v-model="exitForm.transfusion_blood" :icon="Droplet" placeholder="Produit sanguin" aria-label="Transfusion — sang" />
                                            <div class="grid grid-cols-2 gap-2"><Input v-model="exitForm.transfusion_quantity" type="number" min="0" step="0.01" placeholder="Quantité" aria-label="Transfusion — quantité" /><Input v-model="exitForm.transfusion_unit" placeholder="Unité" aria-label="Transfusion — unité" /></div>
                                        </div>
                                    </div>
                                </section>
                                <!-- Sorties -->
                                <section class="rounded-lg border border-border bg-card p-4">
                                    <h3 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        <span class="grid h-7 w-7 place-items-center rounded-md bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300" aria-hidden="true"><ArrowUpFromLine class="h-3.5 w-3.5" /></span>Sorties
                                    </h3>
                                    <div class="mt-3 space-y-3">
                                        <div class="space-y-1">
                                            <span class="text-xs text-muted-foreground">Urines</span>
                                            <IconInput v-model="exitForm.urine_appearance" :icon="Eye" placeholder="Aspect" aria-label="Urines — aspect" />
                                            <div class="grid grid-cols-2 gap-2"><IconInput v-model="exitForm.urine_quantity" :icon="FlaskConical" type="number" min="0" step="0.01" placeholder="Quantité" aria-label="Urines — quantité" /><Input v-model="exitForm.urine_unit" placeholder="Unité" aria-label="Urines — unité" /></div>
                                        </div>
                                        <div class="space-y-1">
                                            <span class="text-xs text-muted-foreground">Pertes sanguines</span>
                                            <div class="grid grid-cols-2 gap-2"><IconInput v-model="exitForm.blood_loss_quantity" :icon="Droplets" type="number" min="0" step="0.01" placeholder="Quantité" aria-label="Pertes sanguines — quantité" /><Input v-model="exitForm.blood_loss_unit" placeholder="Unité" aria-label="Pertes sanguines — unité" /></div>
                                        </div>
                                    </div>
                                </section>
                                <!-- Doses reçues -->
                                <section class="rounded-lg border border-border bg-card p-4">
                                    <h3 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        <span class="grid h-7 w-7 place-items-center rounded-md bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300" aria-hidden="true"><Pill class="h-3.5 w-3.5" /></span>Doses reçues
                                    </h3>
                                    <div class="mt-3 space-y-3">
                                        <div class="space-y-1">
                                            <span class="text-xs text-muted-foreground">Drogue</span>
                                            <IconInput v-model="exitForm.drug_name" :icon="Pill" placeholder="Nom" aria-label="Drogue — nom" />
                                            <div class="grid grid-cols-2 gap-2"><Input v-model="exitForm.drug_quantity" type="number" min="0" step="0.01" placeholder="Quantité" aria-label="Drogue — quantité" /><Input v-model="exitForm.drug_unit" placeholder="Unité" aria-label="Drogue — unité" /></div>
                                        </div>
                                        <div class="space-y-1">
                                            <span class="text-xs text-muted-foreground">Antibiotique</span>
                                            <IconInput v-model="exitForm.antibiotic_name" :icon="ShieldPlus" placeholder="Nom" aria-label="Antibiotique — nom" />
                                            <div class="grid grid-cols-2 gap-2"><Input v-model="exitForm.antibiotic_quantity" type="number" min="0" step="0.01" placeholder="Quantité" aria-label="Antibiotique — quantité" /><Input v-model="exitForm.antibiotic_unit" placeholder="Unité" aria-label="Antibiotique — unité" /></div>
                                        </div>
                                    </div>
                                </section>
                            </div>
                            <!-- État de réveil -->
                            <section class="mt-4 rounded-lg border border-border bg-card p-4">
                                <h3 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    <span class="grid h-7 w-7 place-items-center rounded-md bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300" aria-hidden="true"><BedDouble class="h-3.5 w-3.5" /></span>État de réveil
                                </h3>
                                <div class="mt-3 grid gap-2 lg:grid-cols-3" role="radiogroup" aria-label="État de réveil">
                                    <label
                                        v-for="(label, value) in awakeningLabels"
                                        :key="value"
                                        :class="cn('flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 text-sm transition-colors', exitForm.awakening_status === value ? 'border-primary bg-primary/5 font-medium text-foreground ring-1 ring-primary/40' : 'border-border text-muted-foreground hover:bg-muted/40')"
                                    >
                                        <input v-model="exitForm.awakening_status" type="radio" :value="value" class="h-4 w-4 accent-[hsl(var(--primary))]" />
                                        <component :is="awakeningIcons[value]" class="h-4 w-4 shrink-0" aria-hidden="true" />{{ label }}
                                    </label>
                                </div>
                                <label class="mt-3 block max-w-sm space-y-1"><span class="text-xs text-muted-foreground">Score utilisé par la clinique</span><IconInput v-model="exitForm.awakening_score" :icon="ClipboardCheck" placeholder="Valeur confirmée" /></label>
                            </section>
                            <div v-if="canEdit && canUseExit" class="mt-4 flex items-center justify-end gap-3"><ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="exitForm.isDirty" :failed="autosave.failed.value" /><Button size="rg" type="button" :disabled="exitForm.processing" @click="next('postoperative')">Suivant<ArrowRight class="h-4 w-4" aria-hidden="true" /></Button></div>
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
                        <form v-if="canEditPostoperative" class="rounded-lg border border-border bg-muted/10 p-3" @submit.prevent="submitObservation">
                            <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-foreground"><Plus class="h-3.5 w-3.5 text-primary" aria-hidden="true" />Nouveau relevé</p>
                            <div class="grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-8">
                                <label class="col-span-2 space-y-1 md:col-span-1 xl:col-span-1"><span class="flex items-center gap-1 text-[11px] text-muted-foreground"><Clock class="h-3 w-3" aria-hidden="true" />Heure</span><DateTimePicker v-model="observationForm.observed_at" required /></label>
                                <label class="space-y-1"><span class="flex items-center gap-1 text-[11px] text-muted-foreground"><FlaskConical class="h-3 w-3" aria-hidden="true" />Diurèse</span><Input v-model="observationForm.diuresis_quantity" type="number" min="0" step="0.01" placeholder="Qté" /></label>
                                <label class="space-y-1"><span class="text-[11px] text-muted-foreground">Unité</span><Input v-model="observationForm.diuresis_unit" placeholder="ml" /></label>
                                <label class="space-y-1"><span class="flex items-center gap-1 text-[11px] text-muted-foreground"><Thermometer class="h-3 w-3" aria-hidden="true" />T° (°C)</span><Input v-model="observationForm.temperature_celsius" type="number" min="25" max="45" step="0.01" /></label>
                                <label class="space-y-1"><span class="flex items-center gap-1 text-[11px] text-muted-foreground"><Gauge class="h-3 w-3" aria-hidden="true" />TA syst.</span><Input v-model="observationForm.blood_pressure_systolic" type="number" min="40" max="300" placeholder="mmHg" /></label>
                                <label class="space-y-1"><span class="flex items-center gap-1 text-[11px] text-muted-foreground"><GaugeCircle class="h-3 w-3" aria-hidden="true" />TA diast.</span><Input v-model="observationForm.blood_pressure_diastolic" type="number" min="20" max="200" placeholder="mmHg" /></label>
                                <label class="space-y-1"><span class="flex items-center gap-1 text-[11px] text-muted-foreground"><HeartPulse class="h-3 w-3" aria-hidden="true" />FC</span><Input v-model="observationForm.heart_rate" type="number" min="20" max="300" placeholder="bpm" /></label>
                                <label class="space-y-1"><span class="flex items-center gap-1 text-[11px] text-muted-foreground"><Droplet class="h-3 w-3" aria-hidden="true" />SpO₂ (%)</span><Input v-model="observationForm.oxygen_saturation" type="number" min="0" max="100" /></label>
                            </div>
                            <div class="mt-3 flex justify-end"><Button size="sm" type="submit" :disabled="observationForm.processing"><Plus class="h-4 w-4" />Ajouter le relevé</Button></div>
                        </form>
                        <FormError v-if="observationForm.errors.observation">{{ observationForm.errors.observation }}</FormError>

                        <div class="mt-3 overflow-x-auto rounded-lg border border-border">
                            <table class="w-full min-w-[720px] border-collapse text-sm">
                                <thead class="bg-muted/40">
                                    <tr>
                                        <th class="px-3 py-2.5 text-start text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Clock class="h-3.5 w-3.5" aria-hidden="true" />Heure</span></th>
                                        <th class="px-3 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><span class="inline-flex items-center gap-1.5"><FlaskConical class="h-3.5 w-3.5" aria-hidden="true" />Diurèse</span></th>
                                        <th class="px-3 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Thermometer class="h-3.5 w-3.5" aria-hidden="true" />T°</span></th>
                                        <th class="px-3 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Gauge class="h-3.5 w-3.5" aria-hidden="true" />TA</span></th>
                                        <th class="px-3 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><span class="inline-flex items-center gap-1.5"><HeartPulse class="h-3.5 w-3.5" aria-hidden="true" />FC</span></th>
                                        <th class="px-3 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><span class="inline-flex items-center gap-1.5"><Droplet class="h-3.5 w-3.5" aria-hidden="true" />SpO₂</span></th>
                                        <th class="w-12"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="observation in observations" :key="observation.id" class="hover:bg-muted/20">
                                        <td class="px-3 py-2 text-xs font-medium text-foreground">{{ formatDateTime(observation.observed_at) }}</td>
                                        <td class="text-center text-xs text-muted-foreground tabular-nums">{{ observation.diuresis_quantity ?? '—' }} {{ observation.diuresis_unit ?? '' }}</td>
                                        <td class="text-center text-xs text-muted-foreground tabular-nums">{{ observation.temperature_celsius ?? '—' }}</td>
                                        <td class="text-center text-xs text-muted-foreground tabular-nums">{{ observation.blood_pressure_systolic ?? '—' }}/{{ observation.blood_pressure_diastolic ?? '—' }}</td>
                                        <td class="text-center text-xs text-muted-foreground tabular-nums">{{ observation.heart_rate ?? '—' }}</td>
                                        <td class="text-center text-xs text-muted-foreground tabular-nums">{{ observation.oxygen_saturation ?? '—' }}</td>
                                        <td><button v-if="canEditPostoperative" type="button" class="flex h-8 w-8 items-center justify-center rounded-md text-destructive hover:bg-destructive/10" :aria-label="`Supprimer le relevé de ${formatDateTime(observation.observed_at)}`" @click="removeObservation(observation)"><Trash2 class="h-4 w-4" /></button></td>
                                    </tr>
                                    <tr v-if="observations.length === 0"><td colspan="7" class="px-3 py-6 text-center text-xs text-muted-foreground"><HeartPulse class="mx-auto mb-1 h-5 w-5 opacity-50" aria-hidden="true" />Aucune constante enregistrée.</td></tr>
                                </tbody>
                            </table>
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
