<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import { Link2, Save } from 'lucide-vue-next';
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

const inputClass = 'h-11 w-full rounded-lg border border-border bg-white px-3 text-sm text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25 disabled:bg-muted disabled:text-muted-foreground ';
const labelClass = 'mb-1.5 block text-sm font-medium text-foreground';
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <ValidationErrorSummary :errors="form.errors" @select="focusInvalidField" />

        <div v-if="form.supplier_catalog_item_uuid" class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-100">
            <Link2 class="mt-0.5 h-4.5 w-4.5" />
            <p>Ce médicament vient du catalogue d’un fournisseur. Une fois ajouté, il y sera rattaché avec le prix pratiqué par ce fournisseur.</p>
        </div>

        <section class="rounded-xl border border-border bg-white p-5 shadow-sm">
            <h2 class="font-heading text-base font-bold text-foreground">Identité du médicament</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block">
                    <span :class="labelClass">Code <span v-if="!editing" class="text-red-500">*</span></span>
                    <input v-model="form.code" name="code" :class="[inputClass, 'uppercase']" :disabled="editing" :required="!editing">
                    <span v-if="editing" class="mt-1 block text-xs text-muted-foreground">Le code ne change pas : les fichiers d’import le désignent.</span>
                </label>
                <label class="block lg:col-span-2"><span :class="labelClass">Nom standard à la clinique <span class="text-red-500">*</span></span><input v-model="form.name" name="name" :class="inputClass" required><span class="mt-1 block text-xs text-muted-foreground">Le nom sous lequel il est vendu et suivi, quel que soit le nom donné par chaque fournisseur.</span></label>
                <label class="block"><span :class="labelClass">DCI <span class="text-red-500">*</span></span><input v-model="form.generic_name" name="generic_name" :class="inputClass" placeholder="Ex. Amoxicilline" required></label>
                <label class="block"><span :class="labelClass">Forme <span class="text-red-500">*</span></span><select v-model="form.form" name="form" :class="inputClass" required><option value="">Choisir</option><option v-for="option in medicineForms" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                <label class="block"><span :class="labelClass">Dosage <span class="text-red-500">*</span></span><input v-model="form.strength" name="strength" :class="inputClass" placeholder="Ex. 500 mg" required></label>
                <label class="block"><span :class="labelClass">Unité de vente <span class="text-red-500">*</span></span><input v-model="form.unit" name="unit" :class="inputClass" placeholder="Ex. boîte, comprimé" required></label>
                <label class="block"><span :class="labelClass">Laboratoire</span><input v-model="form.manufacturer" name="manufacturer" :class="inputClass"></label>
                <label class="block"><span :class="labelClass">Code-barres</span><input v-model="form.barcode" name="barcode" :class="inputClass"></label>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-white p-5 shadow-sm">
            <h2 class="font-heading text-base font-bold text-foreground">Classement et stock</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <label class="block"><span :class="labelClass">Famille</span><select v-model="form.medicine_category_uuid" name="medicine_category_uuid" :class="inputClass"><option value="">Sans famille</option><option v-for="category in categories" :key="category.uuid" :value="category.uuid">{{ category.name }}</option></select></label>
                <label class="block"><span :class="labelClass">Alerter sous <span class="text-red-500">*</span></span><input v-model="form.minimum_stock" name="minimum_stock" type="number" min="0" :class="inputClass" required></label>
                <label class="flex items-center gap-3 self-end rounded-lg border border-border px-3 py-3 text-sm text-foreground"><input v-model="form.prescription_required" type="checkbox" class="h-4 w-4">Délivré sur ordonnance</label>
            </div>
            <fieldset v-if="suppliers.length" class="mt-4">
                <legend :class="labelClass">Fournisseurs habituels</legend>
                <div class="flex flex-wrap gap-2">
                    <label v-for="supplier in suppliers" :key="supplier.uuid" class="flex items-center gap-2 rounded-full border border-border px-3 py-1.5 text-sm text-muted-foreground">
                        <input v-model="form.supplier_uuids" type="checkbox" :value="supplier.uuid">{{ supplier.name }}
                    </label>
                </div>
                <p v-if="editing" class="mt-1 text-xs text-muted-foreground">Un fournisseur qui a un prix en cours pour ce médicament reste rattaché.</p>
            </fieldset>
        </section>

        <section class="rounded-xl border border-border bg-white p-5 shadow-sm">
            <h2 class="font-heading text-base font-bold text-foreground">Prix de vente</h2>
            <p v-if="editing && m.sale_price" class="mt-0.5 text-sm text-muted-foreground">Prix actuel : <strong>{{ formatMoney(m.sale_price) }}</strong>. Un nouveau prix s’applique aux prochaines ventes ; les ventes passées gardent le leur.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span :class="labelClass">Prix de vente par unité (MGA) <span v-if="!editing" class="text-red-500">*</span></span>
                    <input v-model="form.sale_price" name="sale_price" type="number" min="0.01" step="0.01" :class="inputClass" :required="!editing" :disabled="editing && !canChangePrice">
                    <span v-if="editing && !canChangePrice" class="mt-1 block text-xs text-muted-foreground">Changer le prix de vente demande la permission de modifier les tarifs.</span>
                </label>
                <label v-if="!editing || priceChanged" class="block">
                    <span :class="labelClass">{{ editing ? 'Pourquoi le prix change' : 'Motif du prix' }} <span class="text-red-500">*</span></span>
                    <input v-model="form.tariff_reason" name="tariff_reason" :class="inputClass" :placeholder="editing ? 'Ex. nouveau tarif fournisseur' : ''" required>
                </label>
            </div>
        </section>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button :as="Link" :href="cancelHref" size="lg" variant="white-outline">Annuler</Button>
            <Button type="submit" size="lg" :disabled="form.processing || (editing && !form.isDirty)">
                <Save class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : (editing ? 'Enregistrer les modifications' : 'Ajouter au catalogue') }}
            </Button>
        </div>
    </form>
</template>
