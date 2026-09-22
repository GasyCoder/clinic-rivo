<script setup>
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import CardBody from '@/Components/Shadcn/CardContent.vue';
import FormError from '@/Components/UI/FormError.vue';
import { ArrowRight, CalendarClock, CheckCircle2, NotebookPen, PackageOpen, Plus, ShieldCheck, Syringe, Trash2, UserRound } from 'lucide-vue-next';
import AnesthesiaStepHeader from '@/Components/Surgery/AnesthesiaStepHeader.vue';
import ClinicalSubsection from '@/Components/Surgery/ClinicalSubsection.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import Select from '@/Components/Shadcn/Select.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import ClinicalAccordionSection from '@/Components/Surgery/ClinicalAccordionSection.vue';
import { useValidationNavigation } from '@/composables/useValidationNavigation';
import { useAutosave } from '@/composables/useAutosave';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import { formatDateTime } from '@/utilities/date';

const props = defineProps({
    surgicalRequest: Object,
    referenceItems: { type: Array, default: () => [] },
    canCreate: Boolean,
    canUpdate: Boolean,
    canValidate: Boolean,
});

const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const record = computed(() => props.surgicalRequest.anesthesia_record ?? null);
const locked = computed(() => Boolean(record.value?.validated_at));
const canEdit = computed(() => !locked.value && (record.value ? props.canUpdate : props.canCreate));
const validating = ref(false);
const selectedCode = ref('');
const selectionError = ref('');
const activeSection = ref('items');

const form = useForm({
    notes: record.value?.notes ?? '',
    administered_at: record.value?.administered_at?.slice(0, 16) ?? '',
    anesthetic_items: (record.value?.anesthetic_items ?? []).map((item) => ({
        reference_code: item.reference_code,
        details: item.details ?? '',
        quantity: item.quantity ?? '',
        unit: item.unit ?? '',
    })),
});
const formElement = ref(null);
const sectionForError = (key) => ['notes', 'administered_at'].includes(key) ? 'observations' : 'items';
const {
    errorId, errorMessage, fieldAttrs, focusError, focusFirstError, hasError, invalidClass,
} = useValidationNavigation(form, activeSection, sectionForError, formElement);

const referenceByCode = computed(() => Object.fromEntries(props.referenceItems.map((item) => [item.code, item])));
const referenceOptions = computed(() => props.referenceItems.map((item) => ({ value: item.code, label: item.name })));
const categoryLabels = { MEDICATION: 'Médicament', MATERIAL: 'Matériel', TECHNIQUE: 'Technique', OTHER: 'Autre' };
const addItem = () => {
    selectionError.value = '';
    if (!selectedCode.value) return;
    if (form.anesthetic_items.some((item) => item.reference_code === selectedCode.value)) {
        selectionError.value = 'Cet élément est déjà présent ; modifiez directement sa quantité.';
        return;
    }
    form.anesthetic_items.push({ reference_code: selectedCode.value, details: '', quantity: '', unit: '' });
    selectedCode.value = '';
};
const removeItem = (index) => form.anesthetic_items.splice(index, 1);
const itemError = (index, field) => form.errors[`anesthetic_items.${index}.${field}`];

// Plus de bouton « Enregistrer » : le dossier s'enregistre tout seul quelques
// instants après la dernière saisie, par la même route, les mêmes droits et la
// même validation. La première saisie ouvre le dossier (POST), les suivantes
// le complètent (PUT) — exactement ce que faisait le bouton.
const send = (options) => {
    if (record.value) {
        form.put(`${base.value}/anesthesia/${record.value.id}`, options);
        return;
    }

    form.post(`${base.value}/anesthesia`, options);
};
const autosave = useAutosave(form, send, { enabled: () => canEdit.value });
/**
 * « Suivant » : enregistre ce qui reste, puis ouvre la section suivante. Une
 * erreur y amène le regard — jamais pendant la frappe, où l'enregistrement
 * automatique ne doit ni déplacer le curseur ni changer de section.
 */
const next = (nextSection) => autosave.flush(
    () => { activeSection.value = nextSection; },
    (errors) => focusFirstError(errors),
);
const validate = () => {
    validating.value = true;
    router.post(`${base.value}/anesthesia/${record.value.id}/validate`, {}, {
        preserveScroll: true,
        onFinish: () => { validating.value = false; },
    });
};
</script>

<template>
    <Card class="shadow-sm xl:col-span-12">
        <CardBody class="!p-0">
            <AnesthesiaStepHeader
                :icon="ShieldCheck"
                :step="3"
                title="Conduite anesthésique et éléments utilisés"
                description="Sélection contrôlée des produits, matériels ou techniques utilisés pendant l’intervention."
                :sections="[{ label: 'Éléments utilisés', complete: form.anesthetic_items.length > 0 }, { label: 'Conduite', complete: Boolean(form.notes || form.administered_at) }]"
                :status="locked ? 'LOCKED' : (!canEdit ? 'READONLY' : null)"
            />

            <form ref="formElement" class="space-y-4 p-4 sm:p-5" @submit.prevent="autosave.flush(undefined, focusFirstError)">
                <ValidationErrorSummary :errors="form.errors" @select="focusError" />
                <fieldset :disabled="!canEdit" class="space-y-3 disabled:opacity-70">
                    <ClinicalAccordionSection
                        :open="activeSection === 'items'"
                        step="1"
                        tone="violet"
                        title="Produits, matériels et techniques"
                        :icon="Syringe"
                        description="Choisissez uniquement les éléments réellement utilisés."
                        :complete="form.anesthetic_items.length > 0"
                        @toggle="activeSection = activeSection === 'items' ? '' : 'items'"
                    >
                    <div class="space-y-4">
                    <ClinicalSubsection :icon="Plus" title="Ajouter un élément" description="Référentiel contrôlé : un élément n’apparaît qu’une fois, sa quantité se corrige sur sa ligne.">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <Select v-model="selectedCode" :options="referenceOptions" placeholder="Choisir : Adrénaline, Kétamine, fil, lame…" class="w-full" />
                            <Button size="lg" variant="white-outline" type="button" :disabled="!selectedCode" @click="addItem"><Plus class="h-4 w-4" aria-hidden="true" /><span class="ms-2">Ajouter</span></Button>
                        </div>
                        <p v-if="selectionError" role="status" class="mt-2 text-xs font-medium text-amber-700 dark:text-amber-300">{{ selectionError }}</p>
                    </ClinicalSubsection>

                    <section
                        v-bind="fieldAttrs('anesthetic_items')"
                        :class="['overflow-hidden rounded-lg border border-border bg-card', invalidClass('anesthetic_items')]"
                    >
                        <header class="flex items-center gap-3 border-b border-border bg-muted/40 px-4 py-2.5">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><PackageOpen class="h-4 w-4" aria-hidden="true" /></span>
                            <h3 class="flex-1 text-sm font-semibold text-foreground">Éléments utilisés</h3>
                            <Badge variant="outline">{{ form.anesthetic_items.length }} élément{{ form.anesthetic_items.length > 1 ? 's' : '' }}</Badge>
                        </header>
                        <div v-if="form.anesthetic_items.length" class="divide-y divide-border">
                            <article v-for="(item, index) in form.anesthetic_items" :key="item.reference_code" class="grid grid-cols-1 gap-3 p-4 lg:grid-cols-[minmax(180px,1.2fr)_minmax(180px,1.5fr)_110px_120px_auto] lg:items-end">
                                <div v-bind="fieldAttrs(`anesthetic_items.${index}.reference_code`)" tabindex="-1" :class="hasError(`anesthetic_items.${index}.reference_code`) ? 'rounded border border-red-400 p-2' : ''"><span class="block text-xs font-medium text-muted-foreground">Élément</span><strong class="mt-1 flex items-center gap-2 text-sm text-foreground"><Syringe class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />{{ referenceByCode[item.reference_code]?.name ?? item.reference_code }}</strong><Badge variant="outline" class="mt-1.5">{{ categoryLabels[referenceByCode[item.reference_code]?.category] ?? 'Référentiel' }}</Badge><FormError v-if="itemError(index, 'reference_code')" :id="errorId(`anesthetic_items.${index}.reference_code`)" :message="itemError(index, 'reference_code')" /></div>
                                <label class="text-xs font-medium text-muted-foreground">Précision <span v-if="item.reference_code === 'ANESTH-OTHER'" class="text-destructive">*</span><Input v-model="item.details" v-bind="fieldAttrs(`anesthetic_items.${index}.details`)" size="lg" :required="item.reference_code === 'ANESTH-OTHER'" placeholder="Dose, présentation ou autre précision" /><FormError v-if="itemError(index, 'details')" :id="errorId(`anesthetic_items.${index}.details`)" :message="itemError(index, 'details')" /></label>
                                <label class="text-xs font-medium text-muted-foreground">Quantité<Input v-model="item.quantity" v-bind="fieldAttrs(`anesthetic_items.${index}.quantity`)" size="lg" type="number" min="0.01" step="0.01" /><FormError v-if="itemError(index, 'quantity')" :id="errorId(`anesthetic_items.${index}.quantity`)" :message="itemError(index, 'quantity')" /></label>
                                <label class="text-xs font-medium text-muted-foreground">Unité<Input v-model="item.unit" v-bind="fieldAttrs(`anesthetic_items.${index}.unit`)" size="lg" placeholder="mg, ml, unité" /><FormError v-if="itemError(index, 'unit')" :id="errorId(`anesthetic_items.${index}.unit`)" :message="itemError(index, 'unit')" /></label>
                                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-md text-destructive transition-colors hover:bg-destructive/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30" :aria-label="`Retirer ${referenceByCode[item.reference_code]?.name ?? item.reference_code}`" :title="`Retirer ${referenceByCode[item.reference_code]?.name ?? item.reference_code}`" @click="removeItem(index)"><Trash2 class="h-4 w-4" aria-hidden="true" /></button>
                            </article>
                        </div>
                        <div v-else class="flex flex-col items-center px-5 py-10 text-center"><span class="grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground"><PackageOpen class="h-6 w-6" aria-hidden="true" /></span><p class="mt-3 text-sm font-medium text-foreground">Aucun élément enregistré</p><p class="mt-1 text-xs text-muted-foreground">Choisissez ci-dessus les produits, matériels ou techniques réellement utilisés.</p></div>
                    </section>

                    <div v-if="canEdit" class="flex items-center justify-end gap-3"><ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" /><Button size="lg" type="button" :disabled="form.processing" @click="next('observations')">Suivant<ArrowRight class="ms-2 h-4 w-4" aria-hidden="true" /></Button></div>
                    </div>
                    </ClinicalAccordionSection>

                    <ClinicalAccordionSection
                        :open="activeSection === 'observations'"
                        step="2"
                        tone="violet"
                        title="Conduite et observations"
                        :icon="NotebookPen"
                        description="Horaire, technique, incidents et observations peropératoires."
                        :complete="Boolean(form.notes || form.administered_at)"
                        @toggle="activeSection = activeSection === 'observations' ? '' : 'observations'"
                    >
                    <ClinicalSubsection :icon="NotebookPen" title="Conduite peropératoire">
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(240px,1fr)]">
                        <label class="text-sm font-medium text-muted-foreground">Conduite et observations<Textarea v-model="form.notes" v-bind="fieldAttrs('notes')" rows="4" :class="invalidClass('notes')" placeholder="Technique, incidents, observations peropératoires…" /><FormError v-if="errorMessage('notes')" :id="errorId('notes')" :message="errorMessage('notes')" /></label>
                        <label class="text-sm font-medium text-muted-foreground"><span class="inline-flex items-center gap-1.5"><CalendarClock class="h-3.5 w-3.5" aria-hidden="true" />Anesthésie administrée le</span><DateTimePicker v-model="form.administered_at" v-bind="fieldAttrs('administered_at')" size="lg" /><FormError v-if="errorMessage('administered_at')" :id="errorId('administered_at')" :message="errorMessage('administered_at')" /></label>
                    </div>
                    </ClinicalSubsection>

                    <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                        <span v-if="canEdit" class="me-auto inline-flex items-center gap-2"><ClinicalSaveStatus :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" /><Button v-if="autosave.failed.value" size="sm" variant="white-outline" type="button" :disabled="form.processing" @click="autosave.flush(undefined, focusFirstError)">Réessayer</Button></span>
                        <Button v-if="record && canValidate && !locked" size="lg" variant="secondary" type="button" :disabled="validating || form.isDirty || form.processing" :title="form.isDirty || form.processing ? 'Enregistrement des dernières modifications en cours' : undefined" @click="validate"><CheckCircle2 class="h-4 w-4" aria-hidden="true" /><span class="ms-2">Valider le dossier</span></Button>
                    </div>
                    </ClinicalAccordionSection>
                </fieldset>

                <FormError v-if="form.errors.anesthesia">{{ form.errors.anesthesia }}</FormError>
            </form>

            <footer v-if="record" class="flex items-center gap-2 border-t border-border bg-muted/20 px-5 py-3 text-xs text-muted-foreground"><UserRound class="h-3.5 w-3.5" aria-hidden="true" />Anesthésiste : {{ record.anesthetist?.name ?? '—' }}<span v-if="record.validated_at"> · validé le {{ formatDateTime(record.validated_at) }}</span></footer>
        </CardBody>
    </Card>
</template>
