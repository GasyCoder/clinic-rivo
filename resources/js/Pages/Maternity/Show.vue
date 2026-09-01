<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClinicalPatientHeader from '@/Components/Clinical/ClinicalPatientHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({ orientation: Object, record: Object, procedureCatalog: Array, capabilities: Object });
const patient = computed(() => props.orientation.episode.patient);
const episode = computed(() => props.orientation.episode);
const activeSection = ref('context');
const sections = computed(() => [
    { key: 'context', label: 'Contexte', icon: 'file-text', visible: true },
    { key: 'prenatal', label: 'Grossesse & prénatal', icon: 'calendar', visible: props.capabilities.can_prenatal || props.record?.prenatal_data },
    { key: 'labor', label: 'Travail', icon: 'activity', visible: props.capabilities.can_labor || props.record?.labor_data },
    { key: 'delivery', label: 'Accouchement', icon: 'heart', visible: props.capabilities.can_delivery || props.record?.delivery_data },
    { key: 'newborn', label: 'Nouveau-né', icon: 'users', visible: props.capabilities.can_newborn || props.record?.newborn_data },
    { key: 'procedures', label: 'Actes & transmission', icon: 'list-check', visible: true },
].filter((section) => section.visible));
const form = useForm({
    obstetric_context: props.record?.obstetric_context ?? '',
    pregnancy_data: { gravidity: props.record?.pregnancy_data?.gravidity ?? '', parity: props.record?.pregnancy_data?.parity ?? '', last_menstrual_period: props.record?.pregnancy_data?.last_menstrual_period ?? '', estimated_due_date: props.record?.pregnancy_data?.estimated_due_date ?? '', risk_factors: props.record?.pregnancy_data?.risk_factors ?? '' },
    prenatal_data: { gestational_age_weeks: props.record?.prenatal_data?.gestational_age_weeks ?? '', fundal_height_cm: props.record?.prenatal_data?.fundal_height_cm ?? '', fetal_heart_rate: props.record?.prenatal_data?.fetal_heart_rate ?? '', notes: props.record?.prenatal_data?.notes ?? '' },
    labor_data: { started_at: props.record?.labor_data?.started_at ?? '', membranes_status: props.record?.labor_data?.membranes_status ?? 'UNKNOWN', cervical_dilation_cm: props.record?.labor_data?.cervical_dilation_cm ?? '', contractions: props.record?.labor_data?.contractions ?? '', surveillance_notes: props.record?.labor_data?.surveillance_notes ?? '' },
    delivery_data: { occurred_at: props.record?.delivery_data?.occurred_at ?? '', mode: props.record?.delivery_data?.mode ?? '', placenta_status: props.record?.delivery_data?.placenta_status ?? '', complications: props.record?.delivery_data?.complications ?? '' },
    newborn_data: { newborns: props.record?.newborn_data?.newborns?.length ? props.record.newborn_data.newborns : [{ sex: '', birth_weight_g: '', condition: '', apgar: '' }] },
    maternal_care_notes: props.record?.maternal_care_notes ?? '', baby_care_notes: props.record?.baby_care_notes ?? '', observations: props.record?.observations ?? '', transmission_notes: props.record?.transmission_notes ?? '',
});
const procedureForm = useForm({ catalog_item_uuid: '', quantity: 1, notes: '' });
const cesareanForm = useForm({ type: 'SIMPLE', indication: '' });
const selectClass = 'mt-1 block h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const textareaClass = 'mt-1 block w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const save = () => form.put(`/maternity/orientations/${props.orientation.uuid}/record`, { preserveScroll: true });
const addNewborn = () => form.newborn_data.newborns.push({ sex: '', birth_weight_g: '', condition: '', apgar: '' });
const saveProcedure = () => procedureForm.post(`/maternity/orientations/${props.orientation.uuid}/procedures`, { preserveScroll: true, onSuccess: () => procedureForm.reset() });
const requestCesarean = () => cesareanForm.post(`/maternity/orientations/${props.orientation.uuid}/cesarean`, { preserveScroll: true, onSuccess: () => cesareanForm.reset('indication') });
</script>

<template>
    <Head title="Dossier Maternité" />
    <div class="w-full space-y-4">
        <ClinicalPatientHeader :patient="patient" :episode="episode" :reason="orientation.reason" back-href="/maternity" back-label="File Maternité" />

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-1.5 dark:border-gray-900 dark:bg-gray-1000">
            <div class="flex min-w-max gap-1">
                <button v-for="section in sections" :key="section.key" type="button" :class="['flex h-10 items-center gap-2 rounded-md px-4 text-xs font-bold transition', activeSection === section.key ? 'bg-white text-rose-700 shadow-sm dark:bg-gray-950 dark:text-rose-300' : 'text-slate-500 hover:text-slate-700 dark:hover:text-white']" @click="activeSection = section.key"><Icon :name="section.icon" />{{ section.label }}</button>
            </div>
        </div>

        <form @submit.prevent="save">
            <Card class="overflow-hidden shadow-sm">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">{{ sections.find((section) => section.key === activeSection)?.label }}</h2><p class="mt-1 text-xs text-slate-500">Les données restent rattachées au passage {{ episode.episode_number }}.</p></div>
                <div class="space-y-5 p-5">
                    <template v-if="activeSection === 'context'">
                        <label class="block text-xs font-semibold text-slate-600">Motif et contexte obstétrical<textarea v-model="form.obstetric_context" rows="6" :class="textareaClass" placeholder="Motif, antécédents obstétricaux et contexte clinique utile…" /></label>
                        <div class="grid gap-4 md:grid-cols-2"><label class="text-xs font-semibold text-slate-600">Gestité<Input v-model="form.pregnancy_data.gravidity" type="number" min="0" /></label><label class="text-xs font-semibold text-slate-600">Parité<Input v-model="form.pregnancy_data.parity" type="number" min="0" /></label><label class="text-xs font-semibold text-slate-600">Dernières règles<Input v-model="form.pregnancy_data.last_menstrual_period" type="date" /></label><label class="text-xs font-semibold text-slate-600">Terme estimé<Input v-model="form.pregnancy_data.estimated_due_date" type="date" /></label></div>
                        <label class="block text-xs font-semibold text-slate-600">Facteurs de risque<textarea v-model="form.pregnancy_data.risk_factors" rows="3" :class="textareaClass" /></label>
                    </template>
                    <template v-if="activeSection === 'prenatal'">
                        <div class="grid gap-4 md:grid-cols-3"><label class="text-xs font-semibold text-slate-600">Terme (semaines)<Input v-model="form.prenatal_data.gestational_age_weeks" type="number" min="0" max="45" /></label><label class="text-xs font-semibold text-slate-600">Hauteur utérine (cm)<Input v-model="form.prenatal_data.fundal_height_cm" type="number" min="0" step="0.1" /></label><label class="text-xs font-semibold text-slate-600">Rythme cardiaque fœtal<Input v-model="form.prenatal_data.fetal_heart_rate" type="number" min="40" max="250" /></label></div><label class="block text-xs font-semibold text-slate-600">Constatations prénatales<textarea v-model="form.prenatal_data.notes" rows="7" :class="textareaClass" /></label>
                    </template>
                    <template v-if="activeSection === 'labor'">
                        <div class="grid gap-4 md:grid-cols-3"><label class="text-xs font-semibold text-slate-600">Début du travail<Input v-model="form.labor_data.started_at" type="datetime-local" /></label><label class="text-xs font-semibold text-slate-600">Membranes<select v-model="form.labor_data.membranes_status" :class="selectClass"><option value="UNKNOWN">Non précisé</option><option value="INTACT">Intactes</option><option value="RUPTURED">Rompues</option></select></label><label class="text-xs font-semibold text-slate-600">Dilatation (cm)<Input v-model="form.labor_data.cervical_dilation_cm" type="number" min="0" max="10" step="0.1" /></label></div><label class="block text-xs font-semibold text-slate-600">Contractions<textarea v-model="form.labor_data.contractions" rows="3" :class="textareaClass" /></label><label class="block text-xs font-semibold text-slate-600">Surveillance du travail<textarea v-model="form.labor_data.surveillance_notes" rows="6" :class="textareaClass" /></label>
                    </template>
                    <template v-if="activeSection === 'delivery'">
                        <div class="grid gap-4 md:grid-cols-2"><label class="text-xs font-semibold text-slate-600">Date et heure<Input v-model="form.delivery_data.occurred_at" type="datetime-local" /></label><label class="text-xs font-semibold text-slate-600">Voie d’accouchement<select v-model="form.delivery_data.mode" :class="selectClass"><option value="">Non renseignée</option><option value="VAGINAL">Voie basse</option><option value="INSTRUMENTAL">Instrumental</option><option value="CESAREAN">Césarienne réalisée en Chirurgie</option></select></label></div><label class="block text-xs font-semibold text-slate-600">Placenta<textarea v-model="form.delivery_data.placenta_status" rows="3" :class="textareaClass" /></label><label class="block text-xs font-semibold text-slate-600">Complications<textarea v-model="form.delivery_data.complications" rows="4" :class="textareaClass" /></label>
                        <div v-if="capabilities.can_delivery && orientation.status === 'IN_PROGRESS'" class="rounded-lg border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20"><div class="flex items-start gap-3"><Icon class="mt-0.5 text-amber-600" name="alert-circle" /><div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Décision de césarienne</h3><p class="mt-1 text-xs text-slate-500">Crée une demande Chirurgie sur ce même passage. Aucune intervention n’est créée dans Maternité.</p></div></div><div class="mt-4 grid gap-3 lg:grid-cols-[180px_minmax(0,1fr)_auto]"><select v-model="cesareanForm.type" :class="selectClass"><option value="SIMPLE">Simple</option><option value="TWIN">Gémellaire</option></select><Input v-model="cesareanForm.indication" placeholder="Indication clinique obligatoire" /><Button type="button" variant="warning" :disabled="!cesareanForm.indication || cesareanForm.processing" @click="requestCesarean">Transmettre à Chirurgie</Button></div><FormError :message="Object.values(cesareanForm.errors)[0]" /></div>
                    </template>
                    <template v-if="activeSection === 'newborn'">
                        <div class="space-y-3"><article v-for="(newborn, index) in form.newborn_data.newborns" :key="index" class="rounded-lg border border-gray-200 p-4 dark:border-gray-900"><div class="mb-3 flex items-center justify-between"><h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Nouveau-né {{ index + 1 }}</h3><button v-if="form.newborn_data.newborns.length > 1" type="button" class="text-xs font-bold text-red-500" @click="form.newborn_data.newborns.splice(index, 1)">Retirer</button></div><div class="grid gap-4 md:grid-cols-4"><label class="text-xs font-semibold text-slate-600">Sexe<select v-model="newborn.sex" :class="selectClass"><option value="">Non renseigné</option><option value="F">Féminin</option><option value="M">Masculin</option><option value="UNDETERMINED">Indéterminé</option></select></label><label class="text-xs font-semibold text-slate-600">Poids naissance (g)<Input v-model="newborn.birth_weight_g" type="number" min="100" max="8000" /></label><label class="text-xs font-semibold text-slate-600">Apgar<Input v-model="newborn.apgar" type="number" min="0" max="10" /></label><label class="text-xs font-semibold text-slate-600">État du nouveau-né<Input v-model="newborn.condition" /></label></div></article><Button type="button" size="sm" variant="white-outline" @click="addNewborn"><Icon name="plus" /><span class="ms-2">Ajouter un nouveau-né</span></Button></div><div class="grid gap-4 lg:grid-cols-2"><label class="text-xs font-semibold text-slate-600">Soins mère<textarea v-model="form.maternal_care_notes" rows="5" :class="textareaClass" /></label><label class="text-xs font-semibold text-slate-600">Soins bébé<textarea v-model="form.baby_care_notes" rows="5" :class="textareaClass" /></label></div>
                    </template>
                    <template v-if="activeSection === 'procedures'">
                        <div v-if="capabilities.can_procedures && orientation.status === 'IN_PROGRESS'" class="grid gap-3 rounded-lg border border-gray-200 bg-gray-50/50 p-4 dark:border-gray-900 dark:bg-gray-1000/20 lg:grid-cols-[minmax(220px,1fr)_100px_minmax(240px,1fr)_auto]"><select v-model="procedureForm.catalog_item_uuid" :class="selectClass"><option value="">Choisir un acte Maternité</option><option v-for="item in procedureCatalog" :key="item.uuid" :value="item.uuid">{{ item.name }}</option></select><Input v-model="procedureForm.quantity" type="number" min="0.01" step="0.01" /><Input v-model="procedureForm.notes" placeholder="Précision facultative" /><Button type="button" :disabled="!procedureForm.catalog_item_uuid || procedureForm.processing" @click="saveProcedure">Enregistrer l’acte</Button><FormError :message="Object.values(procedureForm.errors)[0]" /></div>
                        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-900"><div class="border-b border-gray-200 bg-gray-50 px-4 py-3 text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:border-gray-900 dark:bg-gray-1000">Actes réalisés</div><div v-if="record?.procedures?.length" class="divide-y divide-gray-200 dark:divide-gray-900"><div v-for="procedure in record.procedures" :key="procedure.uuid" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"><div><p class="text-sm font-bold text-slate-700 dark:text-white">{{ procedure.procedure_name }} <span class="font-normal text-slate-400">× {{ procedure.quantity }}</span></p><p class="mt-1 text-xs text-slate-400">Par {{ procedure.performer?.name }} · {{ procedure.notes || 'Sans précision' }}</p></div></div></div><p v-else class="px-4 py-8 text-center text-sm text-slate-400">Aucun acte Maternité enregistré.</p></div>
                        <div class="grid gap-4 lg:grid-cols-2"><label class="text-xs font-semibold text-slate-600">Observations<textarea v-model="form.observations" rows="5" :class="textareaClass" /></label><label class="text-xs font-semibold text-slate-600">Transmission / sortie du module<textarea v-model="form.transmission_notes" rows="5" :class="textareaClass" /></label></div>
                    </template>
                    <FormError :message="Object.values(form.errors)[0]" />
                </div>
                <div class="flex flex-col gap-3 border-t border-gray-200 bg-gray-50/50 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/20 sm:flex-row sm:items-center sm:justify-between"><span class="text-xs text-slate-400">{{ record?.updated_at ? 'Dernière mise à jour enregistrée' : 'Dossier à renseigner' }}</span><div class="flex gap-2"><Button v-if="capabilities.can_edit" type="submit" :disabled="form.processing"><Icon name="save" /><span class="ms-2">Enregistrer le dossier</span></Button><Link v-if="capabilities.can_complete && record" :href="`/maternity/orientations/${orientation.uuid}/complete`" method="post" as="button" preserve-scroll><Button type="button" variant="success"><Icon name="check" /><span class="ms-2">Terminer la prise en charge</span></Button></Link></div></div>
            </Card>
        </form>
    </div>
</template>
