<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Select from '@/Components/Shadcn/Select.vue';
import FormError from '@/Components/UI/FormError.vue';
import ClinicalDischargeForm from '@/Components/Clinical/ClinicalDischargeForm.vue';
import {
    ArrowLeft,
    BedDouble,
    Check,
    DoorOpen,
    FolderOpen,
    Pencil,
    Plus,
    Printer,
    Utensils,
    X,
} from 'lucide-vue-next';
import { formatDate, formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * ADR-113 — le séjour d'un patient hospitalisé.
 *
 * Au centre, la fiche de régime, tenue jour par jour par la Médecine et les
 * Soins, en texte libre et sans aucun montant. Sur le côté, le séjour et la
 * sortie médicale, qui seule le termine (CDC §33.1). Ce que le dossier sait
 * déjà — N° de dossier, allergies, tabac, motif — n'est jamais ressaisi.
 */
const props = defineProps({
    stay: { type: Object, required: true },
    dischargeTypes: { type: Array, default: () => [] },
    transferDestinations: { type: Array, default: () => [] },
    capabilities: { type: Object, required: true },
});

const isActive = computed(() => props.stay.status === 'ACTIVE');

/** Les quatre colonnes « Régime » de la feuille papier, dans son ordre. */
const MEALS = [
    { key: 'tea_bread', label: 'Thé / Pain' },
    { key: 'sosoa_brochette', label: 'Sosoa / Brochette' },
    { key: 'yogurt', label: 'Yaourt' },
    { key: 'puree', label: 'Purée' },
];

const pad = (value) => String(value).padStart(2, '0');
const today = () => {
    const now = new Date();

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};
const nowTime = () => {
    const now = new Date();

    return `${pad(now.getHours())}:${pad(now.getMinutes())}`;
};

const emptyEntry = () => ({
    served_on: today(),
    served_time: nowTime(),
    tea_bread: '',
    sosoa_brochette: '',
    yogurt: '',
    puree: '',
    observation: '',
});

// ── Ajouter une ligne ───────────────────────────────────────────────────────
const newEntry = useForm(emptyEntry());
const hasContent = (form) => [...MEALS.map((meal) => meal.key), 'observation']
    .some((key) => String(form[key] ?? '').trim() !== '');
const addEntry = () => newEntry.post(`/hospitalisation/${props.stay.uuid}/regime`, {
    preserveScroll: true,
    onSuccess: () => {
        newEntry.defaults(emptyEntry());
        newEntry.reset();
    },
});

// ── Corriger une ligne ──────────────────────────────────────────────────────
// Corrigée, jamais supprimée : l'audit garde l'ancienne valeur.
const editingUuid = ref(null);
const editEntry = useForm(emptyEntry());
const startEdit = (entry) => {
    editEntry.clearErrors();
    Object.keys(emptyEntry()).forEach((key) => { editEntry[key] = entry[key] ?? ''; });
    editingUuid.value = entry.uuid;
};
const saveEdit = () => editEntry.put(`/hospitalisation/${props.stay.uuid}/regime/${editingUuid.value}`, {
    preserveScroll: true,
    onSuccess: () => { editingUuid.value = null; },
});

// ── Service et chambre / lit ────────────────────────────────────────────────
const editingRoom = ref(false);
const roomForm = useForm({ service: props.stay.service ?? '', room_bed: props.stay.room_bed ?? '' });
const saveRoom = () => roomForm.put(`/hospitalisation/${props.stay.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { editingRoom.value = false; },
});

// ── Demande d'hospitalisation ───────────────────────────────────────────────
// Elle part en un clic depuis la consultation, reprise du dossier ; c'est ici
// que le médecin la complète (ADR-113, amendement).
const PRIORITIES = [
    { value: 'LOW', label: 'Faible' },
    { value: 'NORMAL', label: 'Normale' },
    { value: 'URGENT', label: 'Urgente' },
];
const priorityLabel = (value) => PRIORITIES.find((option) => option.value === value)?.label ?? '—';
const editingRequest = ref(false);
const requestForm = useForm({
    reason: props.stay.request.reason ?? '',
    admission_diagnosis: props.stay.request.admission_diagnosis ?? '',
    clinical_summary: props.stay.request.clinical_summary ?? '',
    planned_treatment: props.stay.request.planned_treatment ?? '',
    priority: props.stay.request.priority ?? 'NORMAL',
    instructions: props.stay.request.instructions ?? '',
});
const saveRequest = () => requestForm.put(`/hospitalisation/${props.stay.uuid}/demande`, {
    preserveScroll: true,
    onSuccess: () => { editingRequest.value = false; },
});
const requestIncomplete = computed(() => !props.stay.request.reason || !props.stay.request.admission_diagnosis);

// ── Sortie médicale ─────────────────────────────────────────────────────────
// Le même formulaire que la consultation, avec sa propre confirmation signée
// (ADR-107) : un seul façonnage de la sortie médicale dans l'application.
const toLocalDateTimeInput = (value = new Date()) => {
    const date = new Date(value);

    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};
const showDischarge = ref(false);
const dischargeForm = useForm({
    type: 'NORMAL',
    final_diagnosis: props.stay.request.admission_diagnosis ?? '',
    patient_condition: '',
    discharge_prescription: '',
    recommendations: '',
    follow_up_at: '',
    observations: '',
    transfer_destination: '',
    death_occurred_at: '',
    death_place: '',
    death_causes: '',
    discharged_at: toLocalDateTimeInput(),
});
const submitDischarge = () => dischargeForm.post(`/hospitalisation/${props.stay.uuid}/sortie`, { preserveScroll: true });

const smokerLabel = computed(() => (props.stay.smoker === null ? 'Non renseigné' : (props.stay.smoker ? 'Oui' : 'Non')));
const allergyLabel = computed(() => (props.stay.allergies.length ? props.stay.allergies.join(', ') : 'Aucune allergie connue au dossier'));
</script>

<template>
    <Head :title="`Hospitalisation — ${stay.patient.name}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <BedDouble class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="font-heading text-lg font-bold text-foreground">{{ stay.patient.name }}</h1>
                            <Badge :variant="isActive ? 'default' : 'outline'">{{ stay.status_label }}</Badge>
                        </div>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ stay.patient.patient_number }} · Passage {{ stay.episode.episode_number }}<template v-if="stay.patient.age !== null"> · {{ stay.patient.age }} ans</template>
                            · Entré le {{ formatDateTime(stay.admitted_at) }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button :as="Link" href="/hospitalisation" size="sm" variant="white-outline"><ArrowLeft class="h-4 w-4" />Hospitalisation</Button>
                    <Button :as="Link" :href="stay.episode.url" size="sm" variant="white-outline"><FolderOpen class="h-4 w-4" />Passage</Button>
                    <Button :as="Link" :href="`/hospitalisation/${stay.uuid}/regime/impression`" size="sm" variant="white-outline"><Printer class="h-4 w-4" />Imprimer la fiche</Button>
                </div>
            </div>
        </Card>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <!-- Fiche de régime -->
            <Card class="min-w-0 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border px-5 py-3.5">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><Utensils class="h-4 w-4" /></span>
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-foreground">Fiche de régime</h2>
                        <p class="text-xs text-muted-foreground">Repas donnés jour par jour, en texte libre. Aucun montant.</p>
                    </div>
                </div>

                <!-- En-tête de la feuille, repris du dossier : rien n'est ressaisi. -->
                <dl class="grid gap-px border-b border-border bg-border text-xs sm:grid-cols-2">
                    <div class="bg-card px-5 py-2.5"><dt class="text-muted-foreground">N° de dossier</dt><dd class="mt-0.5 font-medium text-foreground">{{ stay.patient.patient_number }}</dd></div>
                    <div class="bg-card px-5 py-2.5"><dt class="text-muted-foreground">Tabac</dt><dd class="mt-0.5 font-medium text-foreground">{{ smokerLabel }}</dd></div>
                    <div class="bg-card px-5 py-2.5"><dt class="text-muted-foreground">Allergie</dt><dd :class="['mt-0.5 font-medium', stay.allergies.length ? 'text-destructive' : 'text-foreground']">{{ allergyLabel }}</dd></div>
                    <div class="bg-card px-5 py-2.5"><dt class="text-muted-foreground">Motif d’hospitalisation</dt><dd class="mt-0.5 font-medium text-foreground">{{ stay.request.reason || '—' }}</dd></div>
                </dl>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] border-collapse text-sm">
                        <caption class="sr-only">Fiche de régime</caption>
                        <thead>
                            <tr class="border-b border-border bg-muted/40 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                <th scope="col" class="w-32 px-3 py-2.5 text-start">Jour</th>
                                <th scope="col" class="w-24 px-3 py-2.5 text-start">Heure</th>
                                <th v-for="meal in MEALS" :key="meal.key" scope="col" class="px-3 py-2.5 text-start">{{ meal.label }}</th>
                                <th scope="col" class="px-3 py-2.5 text-start">Observation</th>
                                <th v-if="capabilities.can_record_diet" scope="col" class="w-20 px-3 py-2.5"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template v-for="entry in stay.diet_entries" :key="entry.uuid">
                                <tr v-if="editingUuid === entry.uuid" class="bg-accent/40 align-top">
                                    <td class="px-2 py-2"><Input v-model="editEntry.served_on" type="date" aria-label="Jour" /></td>
                                    <td class="px-2 py-2"><Input v-model="editEntry.served_time" type="time" aria-label="Heure" /></td>
                                    <td v-for="meal in MEALS" :key="meal.key" class="px-2 py-2"><Input v-model="editEntry[meal.key]" :aria-label="meal.label" maxlength="255" /></td>
                                    <td class="px-2 py-2"><Input v-model="editEntry.observation" aria-label="Observation" maxlength="2000" /></td>
                                    <td class="px-2 py-2">
                                        <div class="flex justify-end gap-1">
                                            <Button type="button" size="icon-xs" :disabled="editEntry.processing || !hasContent(editEntry)" title="Enregistrer" aria-label="Enregistrer la correction" @click="saveEdit"><Check class="h-3.5 w-3.5" /></Button>
                                            <Button type="button" size="icon-xs" variant="ghost" title="Annuler" aria-label="Annuler la correction" @click="editingUuid = null"><X class="h-3.5 w-3.5" /></Button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-else class="align-top">
                                    <td class="px-3 py-2.5 text-foreground">{{ formatDate(entry.served_on) }}</td>
                                    <td class="px-3 py-2.5 tabular-nums text-foreground">{{ entry.served_time }}</td>
                                    <td v-for="meal in MEALS" :key="meal.key" class="px-3 py-2.5 text-foreground">{{ entry[meal.key] || '—' }}</td>
                                    <td class="px-3 py-2.5 text-muted-foreground">
                                        {{ entry.observation || '—' }}
                                        <span class="mt-0.5 block text-[10px]">{{ entry.recorded_by }}<template v-if="entry.updated_by"> · corrigé par {{ entry.updated_by }}</template></span>
                                    </td>
                                    <td v-if="capabilities.can_record_diet" class="px-3 py-2 text-end">
                                        <Button type="button" size="icon-xs" variant="ghost" class="text-muted-foreground" title="Corriger" :aria-label="`Corriger la ligne du ${formatDate(entry.served_on)} à ${entry.served_time}`" @click="startEdit(entry)"><Pencil class="h-3.5 w-3.5" /></Button>
                                    </td>
                                </tr>
                            </template>

                            <tr v-if="!stay.diet_entries.length && !capabilities.can_add_diet">
                                <td :colspan="capabilities.can_record_diet ? 8 : 7" class="px-4 py-10 text-center text-sm text-muted-foreground">Aucune ligne dans la fiche de régime.</td>
                            </tr>

                            <!-- La ligne d'ajout est la dernière ligne de la grille,
                                 comme on remplit la feuille papier. -->
                            <tr v-if="capabilities.can_add_diet" class="bg-muted/30 align-top">
                                <td class="px-2 py-2"><Input v-model="newEntry.served_on" type="date" aria-label="Jour" /></td>
                                <td class="px-2 py-2"><Input v-model="newEntry.served_time" type="time" aria-label="Heure" /></td>
                                <td v-for="meal in MEALS" :key="meal.key" class="px-2 py-2"><Input v-model="newEntry[meal.key]" :placeholder="meal.label" :aria-label="meal.label" maxlength="255" /></td>
                                <td class="px-2 py-2"><Input v-model="newEntry.observation" placeholder="Observation" aria-label="Observation" maxlength="2000" /></td>
                                <td class="px-2 py-2 text-end">
                                    <Button type="button" size="sm" :disabled="newEntry.processing || !hasContent(newEntry)" @click="addEntry"><Plus class="h-4 w-4" />Ajouter</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="Object.keys(newEntry.errors).length || Object.keys(editEntry.errors).length" class="border-t border-border px-5 py-2.5">
                    <FormError v-for="(message, key) in { ...newEntry.errors, ...editEntry.errors }" :key="key" :message="message" />
                </div>
            </Card>

            <!-- Colonne latérale : le séjour et sa demande. La sortie est en bas. -->
            <aside class="space-y-5">
                <Card class="p-5">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-foreground">Séjour</h2>
                        <Button v-if="capabilities.can_update_stay && !editingRoom" type="button" size="icon-xs" variant="ghost" class="text-muted-foreground" title="Modifier" aria-label="Modifier le service et la chambre" @click="editingRoom = true"><Pencil class="h-3.5 w-3.5" /></Button>
                    </div>
                    <dl v-if="!editingRoom" class="mt-3 space-y-2.5 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Service</dt><dd class="text-foreground">{{ stay.service || 'Non précisé' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Chambre / lit</dt><dd class="text-foreground">{{ stay.room_bed || 'Non renseigné' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Entrée</dt><dd class="text-foreground">{{ formatDateTime(stay.admitted_at) }}<span v-if="stay.request.requested_by" class="block text-xs text-muted-foreground">Demandée par Dr {{ stay.request.requested_by }}</span></dd></div>
                    </dl>
                    <form v-else class="mt-3 space-y-3" @submit.prevent="saveRoom">
                        <FormField label="Service" :error="roomForm.errors.service">
                            <Input v-model="roomForm.service" placeholder="Ex. : Médecine interne" maxlength="150" />
                        </FormField>
                        <FormField label="Chambre / lit" :error="roomForm.errors.room_bed">
                            <Input v-model="roomForm.room_bed" placeholder="Ex. : Chambre 3, lit B" maxlength="100" />
                        </FormField>
                        <div class="flex justify-end gap-2">
                            <Button type="button" size="sm" variant="ghost" @click="editingRoom = false; roomForm.reset()">Annuler</Button>
                            <Button type="submit" size="sm" :disabled="roomForm.processing"><Check class="h-4 w-4" />Enregistrer</Button>
                        </div>
                    </form>
                </Card>

                <!-- La demande : partie en un clic de la consultation, complétée ici. -->
                <Card class="p-5">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-foreground">Demande d’hospitalisation</h2>
                        <Button v-if="capabilities.can_edit_request && !editingRequest" type="button" size="sm" variant="outline" @click="editingRequest = true"><Pencil class="h-3.5 w-3.5" />{{ requestIncomplete ? 'Compléter' : 'Modifier' }}</Button>
                    </div>
                    <p v-if="requestIncomplete && !editingRequest" class="mt-2 text-xs text-amber-700 dark:text-amber-300">Motif ou diagnostic d’entrée encore à préciser.</p>
                    <dl v-if="!editingRequest" class="mt-3 space-y-2.5 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Motif</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.reason || '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Diagnostic d’entrée</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.admission_diagnosis || '—' }}</dd></div>
                        <div v-if="stay.request.clinical_summary"><dt class="text-xs text-muted-foreground">Résumé clinique et examens</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.clinical_summary }}</dd></div>
                        <div v-if="stay.request.planned_treatment"><dt class="text-xs text-muted-foreground">Traitement prévu</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.planned_treatment }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Priorité</dt><dd class="text-foreground">{{ priorityLabel(stay.request.priority) }}</dd></div>
                        <div v-if="stay.request.instructions"><dt class="text-xs text-muted-foreground">Consignes</dt><dd class="whitespace-pre-line text-foreground">{{ stay.request.instructions }}</dd></div>
                    </dl>
                    <form v-else class="mt-3 space-y-3" @submit.prevent="saveRequest">
                        <FormField label="Motif d’hospitalisation" :error="requestForm.errors.reason">
                            <Textarea v-model="requestForm.reason" :rows="2" maxlength="3000" />
                        </FormField>
                        <FormField label="Diagnostic d’entrée" :error="requestForm.errors.admission_diagnosis">
                            <Textarea v-model="requestForm.admission_diagnosis" :rows="2" maxlength="3000" />
                        </FormField>
                        <FormField label="Résumé clinique et examens" :error="requestForm.errors.clinical_summary">
                            <Textarea v-model="requestForm.clinical_summary" :rows="5" maxlength="5000" />
                        </FormField>
                        <FormField label="Traitement prévu" :error="requestForm.errors.planned_treatment">
                            <Textarea v-model="requestForm.planned_treatment" :rows="3" maxlength="3000" />
                        </FormField>
                        <FormField label="Priorité" :error="requestForm.errors.priority">
                            <Select v-model="requestForm.priority" :options="PRIORITIES" aria-label="Priorité" />
                        </FormField>
                        <FormField label="Consignes au service" :error="requestForm.errors.instructions">
                            <Textarea v-model="requestForm.instructions" :rows="2" maxlength="3000" />
                        </FormField>
                        <FormError :message="requestForm.errors.hospitalization_request" />
                        <div class="flex justify-end gap-2">
                            <Button type="button" size="sm" variant="ghost" @click="editingRequest = false; requestForm.reset()">Annuler</Button>
                            <Button type="submit" size="sm" :disabled="requestForm.processing"><Check class="h-4 w-4" />Enregistrer</Button>
                        </div>
                    </form>
                </Card>
            </aside>
        </div>

        <!-- La sortie, en bas et sur toute la largeur : son formulaire est une
             grille à deux colonnes (diagnostic, état, traitement, conseils) qui
             s'écrasait dans la colonne latérale de 22 rem. Le bouton qui
             l'ouvre est à la même place, dans l'en-tête de la carte. -->
        <Card v-if="stay.discharge" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><DoorOpen class="h-4 w-4 text-muted-foreground" />Sortie</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs text-muted-foreground">Type</dt><dd class="text-foreground">{{ stay.discharge.type_label }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Date</dt><dd class="text-foreground">{{ formatDateTime(stay.discharged_at) }}<span v-if="stay.discharged_by" class="block text-xs text-muted-foreground">Prononcée par Dr {{ stay.discharged_by }}</span></dd></div>
                <div v-if="stay.discharge.final_diagnosis"><dt class="text-xs text-muted-foreground">Diagnostic final</dt><dd class="whitespace-pre-line text-foreground">{{ stay.discharge.final_diagnosis }}</dd></div>
                <div v-if="stay.discharge.patient_condition"><dt class="text-xs text-muted-foreground">État du patient</dt><dd class="text-foreground">{{ stay.discharge.patient_condition }}</dd></div>
            </dl>
        </Card>

        <Card v-else-if="capabilities.can_discharge" class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><DoorOpen class="h-4 w-4 text-muted-foreground" />Sortie d’hospitalisation</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Seule la sortie médicale termine le séjour. Le passage rejoint ensuite « Sorties & règlements ».</p>
                </div>
                <Button v-if="!showDischarge" type="button" size="sm" variant="outline" class="shrink-0" @click="showDischarge = true"><DoorOpen class="h-4 w-4" />Prononcer la sortie</Button>
            </div>
            <div v-if="showDischarge" class="mt-4 border-t border-border pt-4">
                <ClinicalDischargeForm
                    :form="dischargeForm"
                    :types="dischargeTypes"
                    :site-options="transferDestinations"
                    :requires-diagnosis="true"
                    @submit="submitDischarge"
                    @cancel="showDischarge = false"
                />
                <FormError :message="dischargeForm.errors.medical_discharge" />
            </div>
        </Card>
    </div>
</template>
