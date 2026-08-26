<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import ClinicalAccordionSection from '@/Components/Surgery/ClinicalAccordionSection.vue';
import { useValidationNavigation } from '@/composables/useValidationNavigation';
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

const submit = (nextSection = null) => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (nextSection) activeSection.value = nextSection;
        },
        onError: (errors) => focusFirstError(errors),
    };

    if (record.value) {
        form.put(`${base.value}/anesthesia/${record.value.id}`, options);
        return;
    }
    form.post(`${base.value}/anesthesia`, options);
};
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
            <header class="border-b border-violet-100 bg-violet-50/80 px-4 py-3 dark:border-violet-950 dark:bg-violet-950/30 sm:px-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-violet-600">Étape anesthésie · 3/3</p>
                        <h2 class="mt-0.5 flex items-center gap-2 font-heading text-base font-bold text-slate-700 dark:text-white"><Icon name="shield-check" /> Conduite anesthésique et éléments utilisés</h2>
                        <p class="mt-1 text-sm text-slate-500">Sélection contrôlée des produits, matériels ou techniques utilisés pendant l’intervention.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="locked" class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-700 dark:bg-green-950 dark:text-green-300"><Icon name="lock" /> Dossier validé</span>
                    </div>
                </div>
            </header>

            <form ref="formElement" class="space-y-4 p-4 sm:p-5" @submit.prevent="submit()">
                <ValidationErrorSummary :errors="form.errors" @select="focusError" />
                <fieldset :disabled="!canEdit" class="space-y-3 disabled:opacity-70">
                    <ClinicalAccordionSection
                        :open="activeSection === 'items'"
                        step="1"
                        tone="violet"
                        title="Produits, matériels et techniques"
                        description="Choisissez uniquement les éléments réellement utilisés."
                        :complete="form.anesthetic_items.length > 0"
                        @toggle="activeSection = activeSection === 'items' ? '' : 'items'"
                    >
                    <div class="space-y-4">
                    <section class="rounded-md border border-gray-200 p-4 dark:border-gray-900">
                        <h3 class="mb-3 text-xs font-bold uppercase tracking-wide text-slate-400">Ajouter un élément</h3>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <select v-model="selectedCode" class="min-h-11 rounded-md border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                                <option value="">Choisir : Adrénaline, Kétamine, fil, lame…</option>
                                <option v-for="item in referenceItems" :key="item.code" :value="item.code">{{ item.name }}</option>
                            </select>
                            <Button size="lg" variant="white-outline" type="button" :disabled="!selectedCode" @click="addItem"><Icon name="plus" /><span class="ms-2">Ajouter</span></Button>
                        </div>
                        <p v-if="selectionError" class="mt-2 text-xs text-amber-600">{{ selectionError }}</p>
                    </section>

                    <section
                        v-bind="fieldAttrs('anesthetic_items')"
                        :class="['overflow-hidden rounded-md border border-gray-200 dark:border-gray-900', invalidClass('anesthetic_items')]"
                    >
                        <div v-if="form.anesthetic_items.length" class="divide-y divide-gray-200 dark:divide-gray-900">
                            <article v-for="(item, index) in form.anesthetic_items" :key="item.reference_code" class="grid grid-cols-1 gap-3 p-4 lg:grid-cols-[minmax(180px,1.2fr)_minmax(180px,1.5fr)_110px_120px_auto] lg:items-end">
                                <div v-bind="fieldAttrs(`anesthetic_items.${index}.reference_code`)" tabindex="-1" :class="hasError(`anesthetic_items.${index}.reference_code`) ? 'rounded border border-red-400 p-2' : ''"><span class="block text-[10px] font-bold uppercase tracking-wide text-slate-400">Élément</span><strong class="mt-2 block text-sm text-slate-700 dark:text-white">{{ referenceByCode[item.reference_code]?.name ?? item.reference_code }}</strong><small class="text-slate-400">{{ categoryLabels[referenceByCode[item.reference_code]?.category] ?? 'Référentiel' }}</small><FormError v-if="itemError(index, 'reference_code')" :id="errorId(`anesthetic_items.${index}.reference_code`)" :message="itemError(index, 'reference_code')" /></div>
                                <label class="text-xs text-slate-500">Précision <span v-if="item.reference_code === 'ANESTH-OTHER'" class="text-red-500">*</span><Input v-model="item.details" v-bind="fieldAttrs(`anesthetic_items.${index}.details`)" size="lg" :required="item.reference_code === 'ANESTH-OTHER'" placeholder="Dose, présentation ou autre précision" /><FormError v-if="itemError(index, 'details')" :id="errorId(`anesthetic_items.${index}.details`)" :message="itemError(index, 'details')" /></label>
                                <label class="text-xs text-slate-500">Quantité<Input v-model="item.quantity" v-bind="fieldAttrs(`anesthetic_items.${index}.quantity`)" size="lg" type="number" min="0.01" step="0.01" /><FormError v-if="itemError(index, 'quantity')" :id="errorId(`anesthetic_items.${index}.quantity`)" :message="itemError(index, 'quantity')" /></label>
                                <label class="text-xs text-slate-500">Unité<Input v-model="item.unit" v-bind="fieldAttrs(`anesthetic_items.${index}.unit`)" size="lg" placeholder="mg, ml, unité" /><FormError v-if="itemError(index, 'unit')" :id="errorId(`anesthetic_items.${index}.unit`)" :message="itemError(index, 'unit')" /></label>
                                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-md text-red-500 hover:bg-red-50 dark:hover:bg-red-950" aria-label="Retirer" @click="removeItem(index)"><Icon name="trash" /></button>
                            </article>
                        </div>
                        <div v-else class="px-5 py-10 text-center"><Icon class="text-2xl text-slate-300" name="cards" /><p class="mt-2 text-sm text-slate-400">Aucun élément enregistré.</p></div>
                    </section>

                    <div v-if="canEdit" class="flex justify-end"><Button size="lg" type="button" :disabled="form.processing" @click="submit('observations')"><Icon name="save" /><span class="ms-2">Enregistrer et continuer</span><Icon class="ms-2" name="arrow-right" /></Button></div>
                    </div>
                    </ClinicalAccordionSection>

                    <ClinicalAccordionSection
                        :open="activeSection === 'observations'"
                        step="2"
                        tone="violet"
                        title="Conduite et observations"
                        description="Horaire, technique, incidents et observations peropératoires."
                        :complete="Boolean(form.notes || form.administered_at)"
                        @toggle="activeSection = activeSection === 'observations' ? '' : 'observations'"
                    >
                    <section class="grid grid-cols-1 gap-4 rounded-md border border-violet-100 bg-violet-50/30 p-4 dark:border-violet-950 dark:bg-violet-950/10 lg:grid-cols-[minmax(0,2fr)_minmax(240px,1fr)]">
                        <label class="text-sm text-slate-500">Conduite et observations<textarea v-model="form.notes" v-bind="fieldAttrs('notes')" rows="4" :class="['mt-1 block w-full resize-y rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white', invalidClass('notes')]" placeholder="Technique, incidents, observations peropératoires…"></textarea><FormError v-if="errorMessage('notes')" :id="errorId('notes')" :message="errorMessage('notes')" /></label>
                        <label class="text-sm text-slate-500">Anesthésie administrée le<Input v-model="form.administered_at" v-bind="fieldAttrs('administered_at')" size="lg" type="datetime-local" /><FormError v-if="errorMessage('administered_at')" :id="errorId('administered_at')" :message="errorMessage('administered_at')" /></label>
                    </section>

                    <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-900">
                        <Button v-if="canEdit" size="lg" type="submit" :disabled="form.processing"><Icon name="save" /><span class="ms-2">Enregistrer la conduite</span></Button>
                        <Button v-if="record && canValidate && !locked" size="lg" variant="secondary" type="button" :disabled="validating || form.isDirty" title="Enregistrez d’abord les modifications" @click="validate"><Icon name="check-circle" /><span class="ms-2">Valider le dossier</span></Button>
                    </div>
                    </ClinicalAccordionSection>
                </fieldset>

                <FormError v-if="form.errors.anesthesia">{{ form.errors.anesthesia }}</FormError>
            </form>

            <footer v-if="record" class="border-t border-gray-100 px-5 py-3 text-xs text-slate-400 dark:border-gray-900">Anesthésiste : {{ record.anesthetist?.name ?? '—' }}<span v-if="record.validated_at"> · validé le {{ formatDateTime(record.validated_at) }}</span></footer>
        </CardBody>
    </Card>
</template>
