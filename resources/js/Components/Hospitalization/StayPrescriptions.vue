<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import ClinicalPrescriptionSuggestions from '@/Components/Clinical/ClinicalPrescriptionSuggestions.vue';
import PrescriptionAlerts from '@/Components/Clinical/PrescriptionAlerts.vue';
import PrescriptionLineEditor from '@/Components/Clinical/PrescriptionLineEditor.vue';
import { editorFieldsFor, isUndosedForm } from '@/utilities/posology';
import { checkLine } from '@/utilities/prescriptionChecks';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';
import { Pill, Plus, Printer, Search, Send, Trash2, X } from 'lucide-vue-next';

/**
 * ADR-162 — l'ordonnance du patient hospitalisé, écrite sur la page du séjour.
 *
 * Mêmes lignes, même éditeur et même relecture (ADR-110, ADR-128) qu'en
 * consultation ; le serveur réserve le stock en FEFO et transmet à la
 * Pharmacie, qui délivre au service sans attendre le règlement.
 *
 * ADR-163 — et mêmes propositions (ADR-111) : l'ordonnance des protocoles et de
 * la pratique de la clinique pour les diagnostics du passage. « Ajouter » ne
 * prescrit rien : la ligne rejoint la préparation, modifiable avant validation.
 */
const props = defineProps({
    stayUuid: { type: String, required: true },
    /** `null` : le compte n'a pas le droit de lire les ordonnances. */
    prescriptions: { type: Array, default: null },
    medicines: { type: Array, default: () => [] },
    routes: { type: Array, default: () => [] },
    canPrescribe: { type: Boolean, default: false },
    patient: { type: Object, default: () => ({ age: null, weightKg: null, allergies: [] }) },
    /** ADR-163 — `null` : le compte ne prescrit pas, rien n'est proposé. */
    suggestions: { type: Object, default: null },
});

const page = usePage();
/** Qui signe l'acte : le compte connecté, titré une seule fois. */
const signer = computed(() => doctorName(page.props.auth?.user?.name));

const fold = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const search = ref('');
const filteredMedicines = computed(() => {
    const query = fold(search.value.trim());
    const list = query === ''
        ? props.medicines
        : props.medicines.filter((medicine) => [medicine.name, medicine.generic_name, medicine.code, medicine.strength]
            .some((value) => fold(value).includes(query)));

    return list.slice(0, 30);
});

let sequence = 0;
const blankLine = (extra) => ({
    _key: `line-${++sequence}`,
    quantity: 1,
    _dose_amount: '',
    _dose_unit: 'mg',
    _duration_amount: '',
    _duration_unit: 'jours',
    dosage: '',
    route: null,
    frequency: '',
    duration: '',
    instructions: '',
    ...extra,
});

const form = useForm({ lines: [] });
const medicineFor = (line) => props.medicines.find((medicine) => medicine.uuid === line.medicine_uuid) ?? null;
const selected = computed(() => new Set(form.lines.map((line) => line.medicine_uuid)));

const addMedicine = (medicine) => {
    if (!medicine.available || selected.value.has(medicine.uuid)) return;
    form.lines.push(blankLine({ manual: false, medicine_uuid: medicine.uuid }));
};
const addManual = () => form.lines.push(blankLine({ manual: true, medication_name: '' }));
/** ADR-111 — une ligne proposée arrive préremplie, et garde son origine (revérifiée par le serveur). */
const addSuggested = (line, group) => {
    const medicine = props.medicines.find((item) => item.uuid === line.medicine_uuid);

    if (!medicine?.available || selected.value.has(line.medicine_uuid)) return;

    form.lines.push(blankLine({
        manual: false,
        medicine_uuid: medicine.uuid,
        dosage: line.dosage ?? '',
        route: line.route ?? null,
        frequency: line.frequency ?? '',
        duration: line.duration ?? '',
        instructions: line.instructions ?? '',
        quantity: line.quantity ?? 1,
        ...editorFieldsFor(line),
        suggestion_source: group.source,
        suggestion_protocol_uuid: group.protocol_uuid ?? null,
        suggestion_label: group.source === 'PROTOCOL' ? group.name : 'Pratique de la clinique',
    }));
};
const updateLine = (index, { field, value }) => {
    form.lines = form.lines.map((line, position) => (position === index ? { ...line, [field]: value } : line));
};
const removeLine = (index) => form.lines.splice(index, 1);

const alertsFor = (line) => checkLine({
    line,
    medicine: line.manual ? null : medicineFor(line),
    patient: { age: props.patient.age, weightKg: props.patient.weightKg, allergyConflict: null, allergies: props.patient.allergies ?? [] },
});

/** ADR-110 — la dose n'est exigée que d'un produit qui se dose. */
const lineIsComplete = (line) => {
    const dosed = line.manual || !isUndosedForm(medicineFor(line)?.form);
    const posology = (!dosed || String(line.dosage ?? '').trim() !== '') && String(line.frequency ?? '').trim() !== '';

    if (!posology || Number(line.quantity) < 1) return false;
    if (line.manual) return String(line.medication_name ?? '').trim() !== '';

    const medicine = medicineFor(line);

    return Boolean(medicine?.available) && Number(line.quantity) <= medicine.available_quantity;
};
const isReady = computed(() => form.lines.length > 0 && form.lines.every(lineIsComplete));

const routeShort = (value) => props.routes.find((route) => route.value === value)?.short_label;
const posology = (line) => [line.dosage, routeShort(line.route), line.frequency, line.duration].filter(Boolean).join(' · ');
const lineName = (line) => (line.manual ? line.medication_name : medicineFor(line)?.name);

// Valider est un acte signé (ADR-106) : chaque ligne est relue avant l'envoi.
const confirming = ref(false);
const submit = () => form
    .transform((data) => ({
        lines: data.lines.map((line) => ({
            manual: Boolean(line.manual),
            ...(line.manual ? { medication_name: line.medication_name } : { medicine_uuid: line.medicine_uuid }),
            ...(line.suggestion_source ? { suggestion_source: line.suggestion_source, suggestion_protocol_uuid: line.suggestion_protocol_uuid } : {}),
            quantity: line.quantity,
            dosage: line.dosage,
            route: line.route,
            frequency: line.frequency,
            duration: line.duration,
            instructions: line.instructions,
        })),
    }))
    .post(`/hospitalisation/${props.stayUuid}/ordonnances`, {
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = false;
            form.lines = [];
            search.value = '';
        },
        onError: () => { confirming.value = false; },
    });

// Retirer une ordonnance : motif exigé, stock réservé libéré (même règle qu'en consultation).
const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });
const openCancel = (prescription) => {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelling.value = prescription;
};
const submitCancel = () => cancelForm.post(`/hospitalisation/${props.stayUuid}/ordonnances/${cancelling.value.uuid}/annuler`, {
    preserveScroll: true,
    onSuccess: () => { cancelling.value = null; },
});

const DISPENSE_VARIANT = { DISPENSED: 'success', PARTIALLY_DISPENSED: 'warning', READY: 'secondary', CANCELLED: 'outline' };
</script>

<template>
    <div class="space-y-5">
        <Card v-if="canPrescribe" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Pill class="h-4 w-4 text-muted-foreground" />Nouvelle ordonnance</h2>
            <p class="mt-1 text-xs text-muted-foreground">La Pharmacie la reçoit aussitôt et délivre au service, sans attendre le passage à la Caisse.</p>

            <ClinicalPrescriptionSuggestions
                v-if="suggestions"
                class="mt-4"
                :prescription="suggestions.prescription"
                :selected-uuids="selected"
                :protocol-count="suggestions.protocol_count"
                :practice-cases="suggestions.practice_cases"
                :practice-min-cases="suggestions.practice_min_cases"
                :has-diagnosis="suggestions.has_diagnosis"
                :can-manage-protocols="suggestions.can_manage_protocols"
                :routes="routes"
                @add="addSuggested"
            />

            <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
                <div class="min-w-0">
                    <IconInput v-model="search" :icon="Search" placeholder="Rechercher un médicament" aria-label="Rechercher un médicament" />
                    <ul class="mt-2 max-h-80 space-y-1 overflow-y-auto">
                        <li v-for="medicine in filteredMedicines" :key="medicine.uuid">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-2 rounded-md border border-border bg-card px-2.5 py-2 text-start text-xs transition-colors hover:bg-accent disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="!medicine.available || selected.has(medicine.uuid)"
                                @click="addMedicine(medicine)"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-foreground">{{ medicine.name }}</span>
                                    <span class="block truncate text-muted-foreground">{{ [medicine.form_label, medicine.strength].filter(Boolean).join(' · ') }}</span>
                                </span>
                                <Badge v-if="selected.has(medicine.uuid)" variant="secondary">Retenu</Badge>
                                <Badge v-else-if="!medicine.available" variant="outline">Épuisé</Badge>
                                <span v-else class="shrink-0 tabular-nums text-muted-foreground">{{ medicine.available_quantity }} {{ medicine.unit }}</span>
                            </button>
                        </li>
                    </ul>
                    <p v-if="!medicines.length" class="mt-2 rounded-md border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
                        Aucun médicament au référentiel Pharmacie de ce site. Un médicament absent se prescrit « hors référentiel ».
                    </p>
                    <p v-else-if="!filteredMedicines.length" class="mt-2 px-2 py-3 text-center text-xs text-muted-foreground">Aucun médicament ne correspond.</p>
                    <Button type="button" size="xs" variant="outline" class="mt-2" @click="addManual"><Plus class="h-3.5 w-3.5" />Médicament hors référentiel</Button>
                </div>

                <div class="min-w-0 space-y-3">
                    <p v-if="!form.lines.length" class="rounded-md border border-dashed border-border bg-muted/30 px-3 py-8 text-center text-xs text-muted-foreground">
                        Choisissez un médicament à gauche pour l’ajouter à l’ordonnance.
                    </p>
                    <div v-for="(line, index) in form.lines" :key="line._key" class="rounded-md border border-border bg-card p-3">
                        <header class="mb-2 flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <Input
                                    v-if="line.manual"
                                    :model-value="line.medication_name"
                                    placeholder="Nom du médicament"
                                    aria-label="Nom du médicament"
                                    maxlength="255"
                                    @update:model-value="updateLine(index, { field: 'medication_name', value: $event })"
                                />
                                <p v-else class="truncate text-sm font-semibold text-foreground">{{ medicineFor(line)?.name }}</p>
                                <p v-if="line.suggestion_label" class="mt-0.5 text-[11px] text-primary">Proposé : {{ line.suggestion_label }}</p>
                                <p v-if="line.manual" class="mt-1 text-[11px] text-muted-foreground">Hors référentiel : sans stock réservé.</p>
                            </div>
                            <Button type="button" size="icon-xs" variant="ghost" class="shrink-0 text-muted-foreground" :aria-label="`Retirer ${lineName(line) || 'la ligne'}`" @click="removeLine(index)"><X class="h-3.5 w-3.5" /></Button>
                        </header>
                        <PrescriptionLineEditor
                            :line="line"
                            :routes="routes"
                            :quantity-unit="line.manual ? 'unité(s)' : (medicineFor(line)?.unit ?? 'unité(s)')"
                            :medicine-form="line.manual ? null : medicineFor(line)?.form"
                            :error-for="(field) => form.errors[`lines.${index}.${field}`]"
                            @update="updateLine(index, $event)"
                        />
                        <PrescriptionAlerts class="mt-2" :alerts="alertsFor(line)" compact />
                    </div>
                    <FormError :message="form.errors.prescription || form.errors.lines" />
                    <div v-if="form.lines.length" class="flex justify-end">
                        <Button type="button" size="sm" :disabled="!isReady || form.processing" @click="confirming = true"><Send class="h-4 w-4" />Valider et transmettre</Button>
                    </div>
                </div>
            </div>
        </Card>

        <Card class="p-5">
            <h2 class="text-sm font-semibold text-foreground">Ordonnances du séjour</h2>
            <p v-if="prescriptions === null" class="mt-3 text-xs text-muted-foreground">Non visible avec vos droits (prescriptions.view).</p>
            <p v-else-if="!prescriptions.length" class="mt-3 rounded-md border border-dashed border-border bg-muted/30 px-3 py-6 text-center text-xs text-muted-foreground">
                Aucune ordonnance depuis ce séjour.
            </p>
            <ul v-else class="mt-4 space-y-3">
                <li v-for="prescription in prescriptions" :key="prescription.uuid" class="rounded-md border border-border bg-card p-3" :class="prescription.status === 'CANCELLED' ? 'opacity-60' : ''">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="text-xs text-muted-foreground">
                            <span class="font-semibold text-foreground">{{ formatDateTime(prescription.prescribed_at) }}</span>
                            <template v-if="prescription.prescribed_by"> · {{ doctorName(prescription.prescribed_by) }}</template>
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge v-if="prescription.status === 'CANCELLED'" variant="outline">Retirée</Badge>
                            <Badge v-else-if="prescription.dispense_status_label" :variant="DISPENSE_VARIANT[prescription.dispense_status] ?? 'secondary'">Pharmacie : {{ prescription.dispense_status_label }}</Badge>
                            <Button v-if="prescription.print_url" as="a" :href="prescription.print_url" size="icon-xs" variant="ghost" aria-label="Imprimer l’ordonnance" title="Imprimer"><Printer class="h-3.5 w-3.5" /></Button>
                            <Button v-if="prescription.can_cancel" type="button" size="icon-xs" variant="ghost" class="text-destructive" aria-label="Retirer l’ordonnance" title="Retirer" @click="openCancel(prescription)"><Trash2 class="h-3.5 w-3.5" /></Button>
                        </div>
                    </div>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li v-for="line in prescription.lines" :key="line.id" class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-medium text-foreground">{{ line.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ line.posology || 'Posologie non précisée' }} · qté {{ line.quantity }}</span>
                            <Badge v-if="line.is_manual" variant="outline">Hors référentiel</Badge>
                        </li>
                    </ul>
                    <p v-if="prescription.cancel_reason" class="mt-2 text-xs text-muted-foreground">Motif du retrait : {{ prescription.cancel_reason }}</p>
                </li>
            </ul>
        </Card>

        <Dialog v-model:open="confirming" title="Valider l’ordonnance" description="Relisez chaque ligne : la Pharmacie la délivrera au service." size="lg" :dismissible="false">
            <ul class="space-y-2 text-sm">
                <li v-for="line in form.lines" :key="line._key" class="rounded-md border border-border px-3 py-2">
                    <p class="font-semibold text-foreground">{{ lineName(line) }} <span class="font-normal text-muted-foreground">· qté {{ line.quantity }}</span></p>
                    <p class="text-xs text-muted-foreground">{{ posology(line) || 'Posologie non précisée' }}</p>
                    <p v-if="line.manual" class="text-xs text-muted-foreground">Hors référentiel : aucun stock réservé.</p>
                </li>
            </ul>
            <p class="mt-3 text-xs text-muted-foreground">Sous la responsabilité de <span class="font-semibold text-foreground">{{ signer }}</span>.</p>
            <template #footer>
                <Button type="button" size="sm" variant="ghost" :disabled="form.processing" @click="confirming = false">Revenir</Button>
                <Button type="button" size="sm" :disabled="form.processing" @click="submit"><Send class="h-4 w-4" />Je valide l’ordonnance</Button>
            </template>
        </Dialog>

        <Dialog :open="cancelling !== null" title="Retirer l’ordonnance" description="Le stock réservé est libéré ; l’ordonnance reste lisible, barrée." size="md" :dismissible="false" @update:open="(value) => { if (!value) cancelling = null; }">
            <FormField label="Motif du retrait" required :error="cancelForm.errors.reason">
                <Textarea v-model="cancelForm.reason" :rows="3" maxlength="1000" />
            </FormField>
            <FormError :message="cancelForm.errors.prescription" />
            <template #footer>
                <Button type="button" size="sm" variant="ghost" :disabled="cancelForm.processing" @click="cancelling = null">Annuler</Button>
                <Button type="button" size="sm" variant="destructive" :disabled="cancelForm.processing || cancelForm.reason.trim().length < 3" @click="submitCancel"><Trash2 class="h-4 w-4" />Retirer</Button>
            </template>
        </Dialog>
    </div>
</template>
