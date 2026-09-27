<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Link2, Save } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — the medicine record, to add or to correct, shared by the clinic
 * and the central portal. The code is chosen once and never changes. A new
 * sale price asks for its reason: the previous price stays in the history.
 */
const props = defineProps({
    medicine: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    medicineForms: { type: Array, default: () => [] },
    submitUrl: { type: String, required: true },
    cancelHref: { type: String, required: true },
    canChangePrice: { type: Boolean, default: true },
    // Set when the medicine is being added from a supplier catalog line.
    supplierCatalogItemUuid: { type: String, default: '' },
    initialName: { type: String, default: '' },
});

const editing = computed(() => Boolean(props.medicine));
const m = props.medicine ?? {};

const form = useForm({
    supplier_catalog_item_uuid: props.supplierCatalogItemUuid,
    code: m.code ?? '',
    name: m.name ?? props.initialName,
    generic_name: m.generic_name ?? '',
    form: m.form ?? '',
    strength: m.strength ?? '',
    unit: m.unit ?? '',
    manufacturer: m.manufacturer ?? '',
    barcode: m.barcode ?? '',
    medicine_category_uuid: m.medicine_category_uuid ?? '',
    supplier_uuids: [...(m.supplier_uuids ?? [])],
    minimum_stock: m.minimum_stock ?? 0,
    prescription_required: m.prescription_required ?? true,
    sale_price: m.sale_price ?? '',
    tariff_reason: editing.value ? '' : 'Prix de vente initial',
    description: m.description ?? '',
});

const priceChanged = computed(() => editing.value && String(form.sale_price) !== String(m.sale_price ?? '') && form.sale_price !== '');

const submit = () => {
    if (!editing.value) {
        form.post(props.submitUrl);

        return;
    }

    form.transform(({ code, supplier_catalog_item_uuid, tariff_reason, ...data }) => ({
        ...data,
        ...(priceChanged.value ? { tariff_reason } : { sale_price: m.sale_price ?? null }),
    })).put(props.submitUrl, { preserveScroll: true });
};

const focusInvalidField = (key) => document.querySelector(`[name="${CSS.escape(key)}"]`)?.focus();

/**
 * Les listes passent par le `Select` partagé (ADR-099) plutôt que par un
 * `<select>` habillé à la main : c'est lui qui porte le clavier, le focus
 * visible et le mode sombre. Le « Choisir » vide devient le `placeholder`,
 * qui n'est pas une option sélectionnable — on ne choisit pas « rien » quand
 * le champ est obligatoire.
 */
const formOptions = computed(() => props.medicineForms.map((option) => ({ value: option.value, label: option.label })));

const categoryOptions = computed(() => [
    { value: '', label: 'Sans famille' },
    ...props.categories.map((category) => ({ value: category.uuid, label: category.name })),
]);

const toggleSupplier = (uuid, checked) => {
    const next = new Set(form.supplier_uuids);

    if (checked) next.add(uuid); else next.delete(uuid);

    form.supplier_uuids = [...next];
};
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <ValidationErrorSummary :errors="form.errors" @select="focusInvalidField" />

        <p v-if="form.supplier_catalog_item_uuid" class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-100">
            <Link2 class="mt-0.5 h-4.5 w-4.5 shrink-0" />
            <span>Ce médicament vient du catalogue d’un fournisseur. Une fois ajouté, il y sera rattaché avec le prix pratiqué par ce fournisseur.</span>
        </p>

        <Card class="p-5">
            <h2 class="font-heading text-base font-bold text-foreground">Identité du médicament</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <FormField
                    label="Code"
                    :required="! editing"
                    :error="form.errors.code"
                    :hint="editing ? '' : '(définitif)'"
                >
                    <Input v-model="form.code" name="code" class="uppercase" :disabled="editing" :required="! editing" autocomplete="off" />
                    <!-- Le code est ce que désignent les fichiers d'import et
                         les historiques : il ne se corrige pas après coup. -->
                    <span v-if="editing" class="mt-1 block text-xs text-muted-foreground">Le code ne change pas : les fichiers d’import le désignent.</span>
                </FormField>

                <FormField label="Nom standard à la clinique" required :error="form.errors.name" class="lg:col-span-2">
                    <Input v-model="form.name" name="name" required autocomplete="off" />
                    <span class="mt-1 block text-xs text-muted-foreground">Le nom sous lequel il est vendu et suivi, quel que soit le nom donné par chaque fournisseur.</span>
                </FormField>

                <FormField label="DCI" required :error="form.errors.generic_name">
                    <Input v-model="form.generic_name" name="generic_name" placeholder="Ex. Amoxicilline" required autocomplete="off" />
                </FormField>

                <FormField as="div" label="Forme" required :error="form.errors.form">
                    <Select v-model="form.form" name="form" :options="formOptions" placeholder="Choisir une forme" class="w-full" />
                </FormField>

                <FormField label="Dosage" required :error="form.errors.strength">
                    <Input v-model="form.strength" name="strength" placeholder="Ex. 500 mg" required autocomplete="off" />
                </FormField>

                <FormField label="Unité de vente" required :error="form.errors.unit">
                    <Input v-model="form.unit" name="unit" placeholder="Ex. boîte, comprimé" required autocomplete="off" />
                </FormField>

                <FormField label="Laboratoire" :error="form.errors.manufacturer">
                    <Input v-model="form.manufacturer" name="manufacturer" autocomplete="off" />
                </FormField>

                <FormField label="Code-barres" :error="form.errors.barcode">
                    <Input v-model="form.barcode" name="barcode" autocomplete="off" />
                </FormField>
            </div>
        </Card>

        <Card class="p-5">
            <h2 class="font-heading text-base font-bold text-foreground">Classement et stock</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <FormField as="div" label="Famille" :error="form.errors.medicine_category_uuid">
                    <Select v-model="form.medicine_category_uuid" name="medicine_category_uuid" :options="categoryOptions" placeholder="Sans famille" class="w-full" />
                </FormField>

                <FormField label="Alerter sous" required :error="form.errors.minimum_stock">
                    <Input v-model="form.minimum_stock" name="minimum_stock" type="number" min="0" required />
                </FormField>

                <label class="flex h-10 cursor-pointer items-center gap-3 self-end rounded-lg border border-input bg-card px-3 text-sm text-foreground shadow-sm transition-colors hover:bg-accent/50">
                    <Checkbox v-model="form.prescription_required" aria-label="Délivré sur ordonnance" />
                    Délivré sur ordonnance
                </label>
            </div>

            <fieldset v-if="suppliers.length" class="mt-4">
                <legend class="mb-1.5 text-sm font-medium text-foreground">Fournisseurs habituels</legend>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="supplier in suppliers"
                        :key="supplier.uuid"
                        class="flex cursor-pointer items-center gap-2 rounded-full border border-input bg-card px-3 py-1.5 text-sm text-foreground shadow-sm transition-colors hover:bg-accent/50"
                    >
                        <Checkbox
                            :model-value="form.supplier_uuids.includes(supplier.uuid)"
                            :aria-label="supplier.name"
                            @update:model-value="toggleSupplier(supplier.uuid, $event)"
                        />
                        {{ supplier.name }}
                    </label>
                </div>
                <p v-if="editing" class="mt-1.5 text-xs text-muted-foreground">Un fournisseur qui a un prix en cours pour ce médicament reste rattaché.</p>
            </fieldset>
        </Card>

        <Card class="p-5">
            <h2 class="font-heading text-base font-bold text-foreground">Prix de vente</h2>
            <p v-if="editing && m.sale_price" class="mt-0.5 text-sm text-muted-foreground">
                Prix actuel : <strong class="text-foreground">{{ formatMoney(m.sale_price) }}</strong>.
                Un nouveau prix s’applique aux prochaines ventes ; les ventes passées gardent le leur.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <FormField label="Prix de vente par unité (MGA)" :required="! editing" :error="form.errors.sale_price">
                    <Input
                        v-model="form.sale_price"
                        name="sale_price"
                        type="number"
                        min="0.01"
                        step="0.01"
                        :required="! editing"
                        :disabled="editing && ! canChangePrice"
                    />
                    <span v-if="editing && ! canChangePrice" class="mt-1 block text-xs text-muted-foreground">Changer le prix de vente demande la permission de modifier les tarifs.</span>
                </FormField>

                <!-- Un prix qui change sans motif ne se relit pas : l'ancien
                     reste dans l'historique, encore faut-il savoir pourquoi. -->
                <FormField
                    v-if="! editing || priceChanged"
                    :label="editing ? 'Pourquoi le prix change' : 'Motif du prix'"
                    required
                    :error="form.errors.tariff_reason"
                >
                    <Input
                        v-model="form.tariff_reason"
                        name="tariff_reason"
                        :placeholder="editing ? 'Ex. nouveau tarif fournisseur' : ''"
                        required
                        autocomplete="off"
                    />
                </FormField>
            </div>
        </Card>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button :as="Link" :href="cancelHref" size="lg" variant="outline">Annuler</Button>
            <Button type="submit" size="lg" variant="primary" :disabled="form.processing || (editing && ! form.isDirty)">
                <Save class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : (editing ? 'Enregistrer les modifications' : 'Ajouter au catalogue') }}
            </Button>
        </div>
    </form>
</template>
