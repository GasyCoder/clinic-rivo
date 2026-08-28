<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CheckBox from '@/Components/UI/CheckBox.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    episode: { type: Object, required: true },
    billingCatalog: { type: Array, default: () => [] },
    pricingContext: { type: Object, default: () => ({}) },
    paymentMethods: { type: Array, default: () => [] },
    openCashSession: Object,
});

const query = ref('');
const moduleFilter = ref('');
const form = useForm({
    defer_designation: false,
    catalog_lines: [],
    payment_choice: 'LATER',
    payment_method_id: props.paymentMethods[0]?.id ?? '',
    payment_reference: '',
});

const patientTypeLabels = {
    STANDARD: 'Patient standard',
    MUTUAL: 'Patient mutualiste',
    STAFF: 'Personnel clinique',
};
const routeLabels = {
    MEDICINE_DIRECT: 'Médecine directe',
    CARE_THEN_MEDICINE: 'Soins puis Médecine',
    CARE_ONLY: 'Soins uniquement',
};
const routeDescriptions = {
    MEDICINE_DIRECT: 'Accès direct à la file Médecine.',
    CARE_THEN_MEDICINE: 'Passage aux Soins, puis transmission à Médecine.',
    CARE_ONLY: 'La prise en charge peut se terminer aux Soins.',
};

const isStaff = computed(() => props.episode.patient.patient_type === 'STAFF');
const isMutual = computed(() => props.episode.patient.patient_type === 'MUTUAL');
const mutualCoverageMissing = computed(() => isMutual.value && !props.episode.patient.mutual_coverage);
const selectedIds = computed(() => new Set(form.catalog_lines.map((line) => line.catalog_item_uuid)));
const catalogMap = computed(() => new Map(props.billingCatalog.map((item) => [item.uuid, item])));
const catalogModules = computed(() => Array.from(new Map(
    props.billingCatalog.map((item) => [item.module, item.module_label]),
).entries()).map(([value, label]) => ({ value, label })));
const filteredCatalog = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase('fr');

    return props.billingCatalog.filter((item) => {
        if (selectedIds.value.has(item.uuid)) return false;
        if (moduleFilter.value && item.module !== moduleFilter.value) return false;

        return !needle
            || `${item.code} ${item.name} ${item.module_label}`.toLocaleLowerCase('fr').includes(needle);
    });
});
const selectedServices = computed(() => form.catalog_lines.map((line, index) => ({
    line,
    index,
    item: catalogMap.value.get(line.catalog_item_uuid),
})).filter(({ item }) => item));
const hasUnpricedServices = computed(() => selectedServices.value.some(({ item }) => !item.tariff_available));
const total = computed(() => selectedServices.value.reduce(
    (sum, { line, item }) => sum + Number(line.quantity || 0) * Number(item.tariff_amount || 0),
    0,
));
const coverageTotal = computed(() => selectedServices.value.reduce(
    (sum, { line, item }) => sum + Number(line.quantity || 0) * Number(item.coverage_amount || 0),
    0,
));
const patientTotal = computed(() => selectedServices.value.reduce(
    (sum, { line, item }) => sum + Number(line.quantity || 0) * Number(item.patient_amount ?? item.tariff_amount ?? 0),
    0,
));
const canPayNow = computed(() => !isStaff.value
    && !mutualCoverageMissing.value
    && !hasUnpricedServices.value
    && patientTotal.value > 0
    && Boolean(props.openCashSession)
    && props.paymentMethods.length > 0);
const complete = computed(() => selectedServices.value.length > 0 || form.defer_designation);
const selectedRoutes = computed(() => Array.from(new Set(
    selectedServices.value.map(({ item }) => item.routing_mode),
)));

const addService = (item) => {
    if (selectedIds.value.has(item.uuid)) return;
    form.catalog_lines.push({ catalog_item_uuid: item.uuid, quantity: 1 });
    form.defer_designation = false;

    if (!item.tariff_available) form.payment_choice = 'LATER';
};
const removeService = (index) => form.catalog_lines.splice(index, 1);
const chooseDeferred = (value) => {
    form.defer_designation = value;
    if (value) form.catalog_lines = [];
};
const choosePayment = (choice) => {
    if (choice === 'NOW' && !canPayNow.value) return;
    form.payment_choice = choice;
};
const submit = () => {
    form.transform((data) => ({
        defer_designation: data.defer_designation,
        catalog_lines: data.catalog_lines,
        payment_choice: !isStaff.value && data.catalog_lines.length ? data.payment_choice : null,
        payment_method_id: !isStaff.value && data.catalog_lines.length && data.payment_choice === 'NOW' ? data.payment_method_id : null,
        payment_reference: !isStaff.value && data.catalog_lines.length && data.payment_choice === 'NOW' ? data.payment_reference : null,
    })).post(`/reception/passages/${props.episode.uuid}/prestations`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Passage ${episode.episode_number}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <Card class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <Avatar rounded size="md" variant="slate-pale" :text="formatPatientInitials(episode.patient)" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="truncate font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ formatPatientName(episode.patient) }}</h1>
                            <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1 rounded border border-red-200 bg-red-50 px-2 py-1 text-xs font-bold text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"><span class="h-1.5 w-1.5 rounded-full bg-red-500" />Urgence</span>
                            <span v-else class="inline-flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-2 py-1 text-xs font-semibold text-slate-500 dark:border-gray-800 dark:bg-gray-1000"><span class="h-1.5 w-1.5 rounded-full bg-slate-300" />Normal</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                            <span>PASSAGE <strong class="font-mono text-slate-600 dark:text-slate-300">{{ episode.episode_number }}</strong></span>
                            <span>PATIENT <strong class="font-mono text-slate-600 dark:text-slate-300">{{ episode.patient.patient_number }}</strong></span>
                        </div>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <Button :as="Link" href="/reception/patients" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Retour aux passages</Button>
                </div>
            </div>

            <div class="grid border-t border-gray-200 bg-gray-50/50 dark:border-gray-900 dark:bg-gray-1000/30 sm:grid-cols-3">
                <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-900 sm:border-b-0 sm:border-e"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Arrivée</p><p class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ formatDateTime(episode.started_at) }}</p></div>
                <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-900 sm:border-b-0 sm:border-e"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Type patient</p><p class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ patientTypeLabels[episode.patient.patient_type] }}</p></div>
                <div class="px-5 py-3"><p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Barème tarifaire</p><p class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ pricingContext.label }}<span v-if="pricingContext.organization_name" class="font-normal text-slate-400"> · {{ pricingContext.organization_name }}</span></p><p v-if="isMutual && pricingContext.coverage_rate" class="mt-0.5 text-xs text-slate-400">Mutuelle {{ Number(pricingContext.coverage_rate).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) }} % · Patient {{ Number(pricingContext.patient_rate).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) }} %</p></div>
            </div>
        </Card>

        <Card class="overflow-visible shadow-sm">
            <div class="flex items-center gap-3 border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300"><Icon class="text-lg" name="activity" /></span>
                <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Prestations et parcours clinique</h2><p class="mt-0.5 text-xs text-slate-400">Sélectionnez le besoin connu ; le système calcule sa destination.</p></div>
            </div>

            <div v-if="mutualCoverageMissing || pricingContext.missing_tariffs_count" class="border-b border-gray-200 bg-amber-50/60 px-5 py-2.5 text-xs leading-5 text-amber-800 dark:border-gray-900 dark:bg-amber-950/15 dark:text-amber-300">
                <span v-if="mutualCoverageMissing"><strong>Couverture mutuelle incomplète :</strong> la facturation restera en attente jusqu’à la régularisation du dossier.</span>
                <span v-else><strong>{{ pricingContext.missing_tariffs_count }} tarif{{ pricingContext.missing_tariffs_count > 1 ? 's' : '' }} {{ pricingContext.category === 'MUTUAL' ? 'mutuelle' : 'sans mutuelle' }} à configurer :</strong> les prestations concernées restent orientables, sans reprise automatique de l’autre grille.</span>
            </div>

            <div class="grid items-start gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_390px]">
                <section class="min-w-0" aria-labelledby="catalog-search-title">
                    <div class="mb-2 flex flex-wrap items-end justify-between gap-2">
                        <div><h3 id="catalog-search-title" class="text-sm font-bold text-slate-700 dark:text-white">Rechercher une désignation</h3><p class="mt-0.5 text-xs text-slate-400">Le prix affiché vient du barème {{ pricingContext.category === 'MUTUAL' ? 'Mutuelle' : 'Sans mutuelle' }} du site.</p></div>
                        <span class="text-xs text-slate-400">{{ filteredCatalog.length }} disponible{{ filteredCatalog.length > 1 ? 's' : '' }}</span>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_190px]">
                        <IconInput v-model="query" icon="search" placeholder="Échographie, ECG, consultation…" autocomplete="off" />
                        <select v-model="moduleFilter" class="block h-9 w-full rounded border border-gray-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-600 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200 dark:focus:ring-primary-950" aria-label="Filtrer par service clinique">
                            <option value="">Tous les services</option>
                            <option v-for="module in catalogModules" :key="module.value" :value="module.value">{{ module.label }}</option>
                        </select>
                    </div>

                    <div class="mt-2 max-h-[350px] overflow-y-auto rounded-md border border-gray-200 dark:border-gray-800">
                        <button v-for="item in filteredCatalog" :key="item.uuid" type="button" class="grid w-full grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-start transition-colors last:border-0 hover:bg-gray-50 dark:border-gray-900 dark:hover:bg-gray-1000" @click="addService(item)">
                            <span :class="['flex h-8 w-8 items-center justify-center rounded border', item.tariff_available ? 'border-gray-200 text-primary-600 dark:border-gray-800' : 'border-amber-200 text-amber-600 dark:border-amber-900']"><Icon name="plus" /></span>
                            <span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ item.name }}</span><span class="mt-0.5 block truncate text-xs text-slate-400">{{ item.code }} · {{ item.module_label }} · {{ item.unit }}</span></span>
                            <span class="ps-3 text-end"><span :class="['block text-sm font-bold', item.tariff_available ? 'text-slate-700 dark:text-white' : 'text-amber-700 dark:text-amber-300']">{{ item.tariff_available ? formatMoney(item.tariff_amount) : 'À configurer' }}</span><span v-if="isMutual && item.tariff_available" class="mt-0.5 block text-[11px] font-semibold text-emerald-600">Patient : {{ formatMoney(item.patient_amount) }}</span><span v-else class="mt-0.5 block text-[11px] font-semibold text-slate-400">{{ routeLabels[item.routing_mode] ?? 'Parcours à définir' }}</span></span>
                        </button>
                        <div v-if="filteredCatalog.length === 0" class="px-4 py-9 text-center"><Icon class="text-2xl text-slate-300" name="search" /><p class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-200">Aucune prestation trouvée</p><p class="mt-1 text-xs text-slate-400">Modifiez la recherche ou le filtre de service.</p></div>
                    </div>

                    <label class="mt-3 flex cursor-pointer items-start gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2.5 dark:border-gray-700">
                        <CheckBox id="defer_designation" :model-value="form.defer_designation" size="sm" :disabled="selectedServices.length > 0" @update:model-value="chooseDeferred" />
                        <span><span class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Besoin à définir après évaluation</span><span class="mt-0.5 block text-xs leading-5 text-slate-400">La prestation et son montant seront définis après l’évaluation par les Soins.</span></span>
                    </label>
                    <FormError v-if="form.errors.catalog_lines || form.errors.defer_designation" class="mt-2">{{ form.errors.catalog_lines || form.errors.defer_designation }}</FormError>

                    <div class="mt-4 overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                        <div class="flex flex-col gap-2 border-b border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                            <div class="flex items-center gap-2"><Icon class="text-base text-slate-400" name="wallet" /><div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Règlement</h3><p class="mt-0.5 text-xs text-slate-400">La facture est créée après confirmation du parcours.</p></div></div>
                            <span v-if="openCashSession && !isStaff" class="text-xs font-semibold text-slate-500"><span class="me-1 inline-block h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Caisse {{ openCashSession.session_number }} ouverte</span>
                            <span v-else-if="!isStaff" class="text-xs font-semibold text-slate-400">Caisse fermée</span>
                        </div>
                        <div v-if="isStaff" class="px-4 py-3 text-xs leading-5 text-amber-700 dark:text-amber-300"><strong>Personnel clinique :</strong> le tarif brut configuré est mémorisé ; RH / Finance calculera ensuite la gratuité et le crédit chirurgie.</div>
                        <div v-else-if="selectedServices.length && (hasUnpricedServices || mutualCoverageMissing)" class="px-4 py-3 text-xs leading-5 text-amber-700 dark:text-amber-300"><strong>Facturation en attente :</strong> le parcours clinique sera conservé, sans encaissement, jusqu’à configuration du tarif ou de la couverture.</div>
                        <div v-else-if="selectedServices.length && isMutual && patientTotal === 0" class="flex items-start gap-3 bg-emerald-50/60 px-4 py-3 text-xs leading-5 text-emerald-800 dark:bg-emerald-950/20 dark:text-emerald-200"><Icon class="mt-0.5 shrink-0" name="shield-check" /><span><strong>Prise en charge à 100 % :</strong> la facture indiquera la part de {{ pricingContext.organization_name }}, mais aucun paiement patient ni reçu de caisse ne sera créé.</span></div>
                        <div v-else-if="selectedServices.length" class="grid gap-3 p-3 sm:grid-cols-2">
                            <button type="button" :class="['rounded-md border p-3 text-start', form.payment_choice === 'NOW' ? 'border-primary-500 bg-primary-50/40 dark:bg-primary-950/20' : 'border-gray-200 dark:border-gray-800', !canPayNow ? 'cursor-not-allowed opacity-50' : '']" :disabled="!canPayNow" @click="choosePayment('NOW')"><span class="text-sm font-bold text-slate-700 dark:text-white">Payer maintenant</span><span class="mt-1 block text-xs text-slate-400">Encaissement de la part patient et reçu immédiat.</span></button>
                            <button type="button" :class="['rounded-md border p-3 text-start', form.payment_choice === 'LATER' ? 'border-primary-500 bg-primary-50/40 dark:bg-primary-950/20' : 'border-gray-200 dark:border-gray-800']" @click="choosePayment('LATER')"><span class="text-sm font-bold text-slate-700 dark:text-white">Payer plus tard</span><span class="mt-1 block text-xs text-slate-400">Facture validée avec solde à payer.</span></button>
                            <template v-if="form.payment_choice === 'NOW'">
                                <label><span class="mb-1 block text-xs font-medium text-slate-500">Mode de paiement</span><select v-model="form.payment_method_id" class="block h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option v-for="method in paymentMethods" :key="method.id" :value="method.id">{{ method.name }}</option></select></label>
                                <label><span class="mb-1 block text-xs font-medium text-slate-500">Référence <span class="font-normal text-slate-400">(facultatif)</span></span><Input v-model="form.payment_reference" /></label>
                            </template>
                        </div>
                        <div v-else class="px-4 py-3 text-xs text-slate-400">Le choix du règlement apparaît après l’ajout d’une prestation tarifée.</div>
                    </div>
                </section>

                <aside class="self-start overflow-hidden rounded-md border border-gray-200 bg-white xl:sticky xl:top-20 dark:border-gray-800 dark:bg-gray-950" aria-label="Panier des prestations">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/30">
                        <div class="flex items-center gap-2.5">
                            <span class="relative flex h-9 w-9 items-center justify-center rounded bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300"><Icon class="text-lg" name="cart" /><span v-if="selectedServices.length" class="absolute -end-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full border-2 border-white bg-primary-600 px-1 text-[10px] font-bold text-white dark:border-gray-950">{{ selectedServices.length }}</span></span>
                            <div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Panier des prestations</h3><p class="mt-0.5 text-xs text-slate-400">{{ selectedServices.length }} désignation{{ selectedServices.length > 1 ? 's' : '' }}</p></div>
                        </div>
                        <div class="text-end"><p class="text-[10px] font-medium uppercase tracking-wide text-slate-400">{{ isMutual ? 'À payer patient' : 'Total' }}</p><p class="mt-0.5 text-base font-bold text-slate-800 dark:text-white">{{ hasUnpricedServices ? 'À finaliser' : formatMoney(patientTotal) }}</p><p v-if="isMutual && !hasUnpricedServices" class="mt-0.5 text-[10px] text-slate-400">Brut {{ formatMoney(total) }} · mutuelle {{ formatMoney(coverageTotal) }}</p></div>
                    </div>

                    <div v-if="selectedServices.length" class="max-h-[430px] overflow-y-auto">
                        <div v-for="selection in selectedServices" :key="selection.item.uuid" class="border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-900">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0"><p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ selection.item.name }}</p><p class="mt-0.5 text-xs text-slate-400">{{ selection.item.code }} · {{ selection.item.unit }}</p></div>
                                <button type="button" class="flex h-7 w-7 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/20" :aria-label="`Retirer ${selection.item.name}`" @click="removeService(selection.index)"><Icon name="trash" /></button>
                            </div>
                            <div class="mt-2 grid grid-cols-[minmax(0,1fr)_74px_auto] items-end gap-2">
                                <div><p class="text-[10px] font-medium uppercase tracking-wide text-slate-400">Parcours</p><p class="mt-0.5 text-xs font-semibold text-slate-600 dark:text-slate-300">{{ routeLabels[selection.item.routing_mode] }}</p></div>
                                <label><span class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Qté</span><Input v-model="selection.line.quantity" class="!w-[74px] text-end" type="number" min="0.01" max="9999.99" step="0.01" /></label>
                                <div class="min-w-[92px] text-end"><p class="text-[10px] font-medium uppercase tracking-wide text-slate-400">{{ isMutual ? 'Part patient' : 'Montant' }}</p><p :class="['mt-1 text-sm font-bold', selection.item.tariff_available ? 'text-slate-700 dark:text-white' : 'text-amber-700 dark:text-amber-300']">{{ selection.item.tariff_available ? formatMoney(Number(selection.line.quantity || 0) * Number(selection.item.patient_amount ?? selection.item.tariff_amount)) : 'À configurer' }}</p><p v-if="isMutual && selection.item.tariff_available" class="mt-0.5 text-[10px] text-slate-400">Mutuelle {{ formatMoney(Number(selection.line.quantity || 0) * Number(selection.item.coverage_amount)) }}</p></div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-5 py-10 text-center"><span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-900"><Icon class="text-xl" name="cart" /></span><p class="mt-3 text-sm font-semibold text-slate-600 dark:text-slate-200">Panier vide</p><p class="mt-1 text-xs leading-5 text-slate-400">Ajoutez une ou plusieurs prestations depuis la liste.</p></div>

                    <div v-if="selectedRoutes.length" class="border-t border-gray-200 bg-gray-50/50 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/20">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Parcours calculé</p>
                        <div class="mt-2 space-y-1.5"><p v-for="routeMode in selectedRoutes" :key="routeMode" class="flex items-start gap-2 text-xs leading-5 text-slate-500"><Icon class="mt-0.5 shrink-0 text-slate-400" name="check-circle" /><span><strong class="text-slate-600 dark:text-slate-300">{{ routeLabels[routeMode] }} :</strong> {{ routeDescriptions[routeMode] }}</span></p></div>
                    </div>
                </aside>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-900">
                <Button :as="Link" :href="`/patients/${episode.patient.uuid}`" size="rg" variant="white-outline"><Icon class="me-2" name="arrow-left" />Retour au patient</Button>
                <div class="text-end"><p class="mb-2 text-xs text-slate-400">Le parcours et le barème utilisés seront historisés pour ce passage.</p><Button size="rg" :disabled="!complete || form.processing" @click="submit"><Icon class="me-2" name="check" />{{ form.processing ? 'Confirmation…' : 'Confirmer le parcours' }}</Button></div>
            </div>
        </Card>
    </div>
</template>
