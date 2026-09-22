<script setup>
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import FormError from '@/Components/UI/FormError.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import ClinicalAccordionSection from './ClinicalAccordionSection.vue';
import DynamicTreatmentTable from './DynamicTreatmentTable.vue';
import SurgerySection from './SurgerySection.vue';
import { ArrowRight, LockKeyhole, LogIn, Save } from 'lucide-vue-next';

const props = defineProps({
    surgicalRequest: Object,
    canEdit: Boolean,
    /** Rang et état dans l'étape Préparation (ADR-048) : après le feu vert. */
    order: { type: Number, default: null },
    state: { type: String, default: null },
});
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
    peripheral_iv_count: entry.peripheral_iv_count !== null && entry.peripheral_iv_count !== undefined ? String(entry.peripheral_iv_count) : '',
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
const yesNoOptions = [{ value: '1', label: 'Oui' }, { value: '0', label: 'Non' }];
const ivOptions = [{ value: '1', label: '1 VVP' }, { value: '2', label: '2 VVP' }];
/** Un libellé au-dessus de son champ, à la même distance pour tous. */
const FIELD = 'grid min-w-0 gap-1 text-xs text-muted-foreground';
</script>

<template>
    <SurgerySection
        :icon="LogIn"
        title="Entrée au bloc"
        description="Trois tâches courtes, enregistrables progressivement."
        :order="order"
        :state="state"
        waiting-label="Après le feu vert"
    >
        <template #waiting>S’ouvre une fois le feu vert chirurgical confirmé : préparation du patient, accès et dispositifs, traitement préliminaire.</template>
        <template v-if="locked" #badge>
            <Badge variant="outline"><LockKeyhole class="h-3 w-3" />Lecture seule</Badge>
        </template>
            <div class="space-y-3">
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
                                    <p class="mt-1 text-[11px] leading-4 text-muted-foreground">Taille, poids, température et tension proviennent de Soins et ne sont pas redemandés.</p>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="min-w-0 text-xs text-muted-foreground">Fréquence cardiaque<Input v-model="form.heart_rate" size="lg" type="number" min="20" max="300" placeholder="batt/min" /></label><label class="min-w-0 text-xs text-muted-foreground">SpO₂<Input v-model="form.oxygen_saturation" size="lg" type="number" min="0" max="100" placeholder="%" /></label></div>
                                </section>
                                <section class="rounded-md border border-border p-4">
                                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Préparation préopératoire</h3>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="min-w-0 text-xs text-muted-foreground">Toilette complète<Select v-model="form.full_bath_completed" :options="yesNoOptions" placeholder="Non renseigné" class="mt-1 w-full" /></label><label class="min-w-0 text-xs text-muted-foreground">Pesage réalisé<Select v-model="form.weighing_completed" :options="yesNoOptions" placeholder="Non renseigné" class="mt-1 w-full" /></label></div>
                                </section>
                            </div>
                            <div v-if="!locked" class="mt-4 flex justify-end"><Button size="rg" type="button" :disabled="form.processing" @click="submit('access')"><Save class="h-4 w-4" />Enregistrer et continuer<ArrowRight class="h-4 w-4" /></Button></div>
                        </ClinicalAccordionSection>

                        <ClinicalAccordionSection
                            :open="activeSection === 'access'"
                            step="2"
                            title="Accès et dispositifs"
                            description="Voie veineuse, perfusion et sonde urinaire."
                            :complete="accessComplete"
                            @toggle="activeSection = activeSection === 'access' ? '' : 'access'"
                        >
                            <!-- Côte à côte seulement quand chaque carte a la place de montrer la date entière ; sinon empilées, champs sur une ligne. -->
                            <div class="eb-access-host">
                                <div class="eb-access grid gap-4">
                                    <section class="eb-card rounded-xl border border-border p-4">
                                        <h3 class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Voie veineuse</h3>
                                        <div class="eb-fields eb-fields--iv mt-3 grid gap-3">
                                            <label :class="FIELD">Nombre de VVP<Select v-model="form.peripheral_iv_count" :options="ivOptions" placeholder="Non renseigné" class="h-11 w-full min-w-0" /></label>
                                            <label :class="FIELD">Sérum<Input v-model="form.serum_name" size="lg" placeholder="Désignation" /></label>
                                            <label :class="FIELD">Quantité<Input v-model="form.serum_quantity" size="lg" type="number" min="0" step="0.01" /></label>
                                            <label :class="FIELD">Unité<Input v-model="form.serum_unit" size="lg" placeholder="ml, poche…" /></label>
                                        </div>
                                    </section>
                                    <section class="eb-card rounded-xl border border-border p-4">
                                        <h3 class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Sonde urinaire</h3>
                                        <div class="eb-fields eb-fields--urinary mt-3 grid gap-3">
                                            <label :class="FIELD">Sonde posée<Select v-model="form.urinary_catheter_placed" :options="yesNoOptions" placeholder="Non renseigné" class="h-11 w-full min-w-0" /></label>
                                            <label :class="FIELD">Date et heure de pose<DateTimePicker v-model="form.catheter_placed_at" class="h-11" /></label>
                                            <label :class="FIELD">Aspect<Input v-model="form.urine_appearance" size="lg" /></label>
                                            <div class="grid min-w-0 grid-cols-2 gap-2">
                                                <label :class="FIELD">Diurèse<Input v-model="form.diuresis_quantity" size="lg" type="number" min="0" step="0.01" /></label>
                                                <label :class="FIELD">Unité<Input v-model="form.diuresis_unit" size="lg" /></label>
                                            </div>
                                        </div>
                                    </section>
                                </div>
                            </div>
                            <div v-if="!locked" class="mt-4 flex justify-end"><Button size="rg" type="button" :disabled="form.processing" @click="submit('treatment')"><Save class="h-4 w-4" />Enregistrer et continuer<ArrowRight class="h-4 w-4" /></Button></div>
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
    </SurgerySection>
</template>

<style scoped>
/* Les deux cartes ne se mettent côte à côte que si chacune garde la date
   entière (« 25/09/2026 09:30 ») ; sinon elles s'empilent et leurs champs
   tiennent sur une seule ligne — autant de hauteur, rien de tronqué. */
.eb-access-host {
    container: eb-access / inline-size;
}

@container eb-access (min-width: 50rem) {
    .eb-access {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

.eb-card {
    container: eb-card / inline-size;
}

.eb-fields {
    grid-template-columns: minmax(0, 1fr);
}

@container eb-card (min-width: 22rem) {
    .eb-fields {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@container eb-card (min-width: 36rem) {
    .eb-fields--iv {
        grid-template-columns: minmax(9.5rem, 1fr) minmax(0, 1.3fr) minmax(0, 1fr) minmax(0, 1fr);
    }
}

@container eb-card (min-width: 38rem) {
    .eb-fields--urinary {
        grid-template-columns: minmax(9.5rem, 1fr) minmax(10.5rem, 1.2fr) minmax(0, 1fr) minmax(0, 1fr);
    }
}
</style>
