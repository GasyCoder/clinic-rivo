<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';
import { formatMoney } from '@/utilities/money';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    estimateCatalog: { type: Array, default: () => [] },
    addressEntries: { type: Array, default: () => [] },
    mutualOrganizations: { type: Array, default: () => [] },
    capabilities: { type: Object, default: () => ({}) },
});

const steps = [
    { number: 1, label: 'Besoin' },
    { number: 2, label: 'Estimation' },
    { number: 3, label: 'Patient' },
    { number: 4, label: 'Épisode' },
    { number: 5, label: 'Prise en charge' },
    { number: 6, label: 'Confirmation' },
    { number: 7, label: 'Routage' },
];
const currentStep = ref(1);
const catalogQuery = ref('');
const moduleFilter = ref('');
const cart = ref([]);
const estimate = ref(null);
const estimateLoading = ref(false);
const estimateError = ref('');
const designationDeferred = ref(false);
const isEmergency = ref(false);

const patientMode = ref('search');
const patientQuery = ref('');
const patientMatches = ref([]);
const patientSearchLoading = ref(false);
const patientSearchPerformed = ref(false);
const selectedPatient = ref(null);
const duplicates = ref([]);
const arrivalLoading = ref(false);
const arrivalErrors = ref({});
const arrivalMessage = ref('');
const episode = ref(null);

const birthMode = ref('date');
const patientForm = reactive({
    first_name: '', last_name: '', birth_date: '', age: '', sex: 'M',
    phone: '', email: '', profession: '', address_entry_uuid: '', new_address_label: '',
    emergency_contact_name: '', emergency_contact_phone: '',
    emergency_contact_relationship: '', emergency_contact_email: '',
});

const financialMode = ref('SELF');
const financialLoading = ref(false);
const financialErrors = ref({});
const preview = ref(null);
const mutualForm = reactive({
    mutual_organization_uuid: '', employer_name: '',
    beneficiary_type: 'PRINCIPAL', membership_number: '',
});
const employeeQuery = ref('');
const employeeMatches = ref([]);
const employeeSearchLoading = ref(false);
const employeeSearchPerformed = ref(false);
const selectedEmployee = ref(null);

const finalForm = useForm({
    defer_designation: false,
    catalog_lines: [],
    payment_choice: 'LATER',
});

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const requestJson = async (url, options = {}) => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers ?? {}),
        },
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || 'La demande n’a pas pu être traitée.');
        error.payload = data;
        throw error;
    }

    return data;
};

const catalogMap = computed(() => new Map(props.estimateCatalog.map((item) => [item.catalog_item_uuid, item])));
const catalogModules = computed(() => {
    const modules = new Map();

    props.estimateCatalog.forEach((item) => {
        const module = modules.get(item.module) ?? {
            value: item.module,
            label: item.module_label,
            count: 0,
        };
        module.count += 1;
        modules.set(item.module, module);
    });

    return Array.from(modules.values()).sort((left, right) => left.label.localeCompare(right.label, 'fr'));
});
const catalogCategoryOptions = computed(() => [
    { value: '', label: 'Toutes', count: props.estimateCatalog.length },
    ...catalogModules.value,
]);
const cartIds = computed(() => new Set(cart.value.map((line) => line.catalog_item_uuid)));
const filteredCatalog = computed(() => {
    const needle = catalogQuery.value.trim().toLocaleLowerCase('fr');

    return props.estimateCatalog.filter((item) => {
        if (cartIds.value.has(item.catalog_item_uuid)) return false;
        if (moduleFilter.value && item.module !== moduleFilter.value) return false;

        return !needle || `${item.name} ${item.code} ${item.module_label}`
            .toLocaleLowerCase('fr')
            .includes(needle);
    });
});
const hasCatalogFilters = computed(() => catalogQuery.value.trim() !== '' || moduleFilter.value !== '');
const estimateLineMap = computed(() => new Map(
    (estimate.value?.lines ?? []).map((line) => [line.catalog_item_uuid, line]),
));
const cartLines = computed(() => cart.value.map((line, index) => ({
    index,
    line,
    catalog: catalogMap.value.get(line.catalog_item_uuid),
    estimated: estimateLineMap.value.get(line.catalog_item_uuid),
})));
const selectedOrganization = computed(() => props.mutualOrganizations.find(
    (organization) => organization.uuid === mutualForm.mutual_organization_uuid,
) ?? null);
const previewLines = computed(() => preview.value?.lines ?? []);

const addService = (item) => {
    cart.value.push({ catalog_item_uuid: item.catalog_item_uuid, quantity: 1 });
    estimate.value = null;
    estimateError.value = '';
    designationDeferred.value = false;
};
const resetCatalogFilters = () => {
    catalogQuery.value = '';
    moduleFilter.value = '';
};
const removeService = async (index) => {
    cart.value.splice(index, 1);
    estimate.value = null;
    if (currentStep.value === 2 && cart.value.length) await recalculateEstimate();
    if (!cart.value.length) currentStep.value = 1;
};
const cartPayload = () => cart.value.map((line) => ({
    catalog_item_uuid: line.catalog_item_uuid,
    quantity: Number(line.quantity),
}));
const recalculateEstimate = async () => {
    if (!cart.value.length) return;
    estimateLoading.value = true;
    estimateError.value = '';

    try {
        estimate.value = await requestJson('/reception/estimates', {
            method: 'POST',
            body: JSON.stringify({ lines: cartPayload() }),
        });
    } catch (error) {
        estimate.value = null;
        estimateError.value = Object.values(error.payload?.errors ?? {}).flat()[0] ?? error.message;
    } finally {
        estimateLoading.value = false;
    }
};
const showEstimate = async () => {
    if (!cart.value.length) return;
    currentStep.value = 2;
    await recalculateEstimate();
};
const continueWithoutKnownDesignation = () => {
    designationDeferred.value = true;
    cart.value = [];
    estimate.value = null;
    isEmergency.value = false;
    currentStep.value = 3;
};
const startEmergency = () => {
    designationDeferred.value = false;
    cart.value = [];
    estimate.value = null;
    isEmergency.value = true;
    currentStep.value = 3;
};
const continueToPatient = () => {
    isEmergency.value = false;
    currentStep.value = 3;
};

const resetEpisodeContact = () => {
    patientForm.emergency_contact_name = '';
    patientForm.emergency_contact_phone = '';
    patientForm.emergency_contact_relationship = '';
    patientForm.emergency_contact_email = '';
};
const searchPatients = async () => {
    if (patientQuery.value.trim().length < 2) return;
    patientSearchLoading.value = true;
    patientSearchPerformed.value = false;
    selectedPatient.value = null;
    resetEpisodeContact();
    arrivalMessage.value = '';

    try {
        const result = await requestJson(`/reception/patients/search?q=${encodeURIComponent(patientQuery.value.trim())}`);
        patientMatches.value = result.data ?? [];
        patientSearchPerformed.value = true;
    } catch (error) {
        arrivalMessage.value = error.message;
    } finally {
        patientSearchLoading.value = false;
    }
};
const choosePatient = (patient) => {
    selectedPatient.value = patient;
    duplicates.value = [];
    arrivalErrors.value = {};
};
const chooseExistingPatient = () => {
    patientMode.value = 'search';
    selectedPatient.value = null;
    duplicates.value = [];
    arrivalErrors.value = {};
    resetEpisodeContact();
};
const chooseAnotherPatient = () => {
    selectedPatient.value = null;
    arrivalErrors.value = {};
    resetEpisodeContact();
};
const chooseNewPatient = () => {
    patientMode.value = 'create';
    selectedPatient.value = null;
    duplicates.value = [];
    arrivalErrors.value = {};
    resetEpisodeContact();
};
const setBirthMode = (mode) => {
    birthMode.value = mode;
    if (mode === 'date') patientForm.age = '';
    else patientForm.birth_date = '';
};
const arrivalPayload = (confirmDuplicate = false) => {
    const contact = {
        emergency_contact_name: patientForm.emergency_contact_name || null,
        emergency_contact_phone: patientForm.emergency_contact_phone || null,
        emergency_contact_relationship: patientForm.emergency_contact_relationship || null,
        emergency_contact_email: patientForm.emergency_contact_email || null,
    };

    if (selectedPatient.value) {
        return { patient_uuid: selectedPatient.value.uuid, is_emergency: isEmergency.value, ...contact };
    }

    return {
        patient_type: 'STANDARD',
        first_name: patientForm.first_name || null,
        last_name: patientForm.last_name,
        birth_date: birthMode.value === 'date' ? patientForm.birth_date || null : null,
        age: birthMode.value === 'age' ? Number(patientForm.age) || null : null,
        sex: patientForm.sex,
        phone: patientForm.phone || null,
        email: patientForm.email || null,
        profession: patientForm.profession || null,
        address_entry_uuid: patientForm.address_entry_uuid || null,
        new_address_label: patientForm.new_address_label || null,
        confirm_duplicate: confirmDuplicate,
        is_emergency: isEmergency.value,
        ...contact,
    };
};
const createEpisode = async (confirmDuplicate = false) => {
    if (!selectedPatient.value && patientMode.value !== 'create') return;
    arrivalLoading.value = true;
    arrivalErrors.value = {};
    arrivalMessage.value = '';

    try {
        const result = await requestJson('/reception/patients', {
            method: 'POST',
            body: JSON.stringify(arrivalPayload(confirmDuplicate)),
        });
        selectedPatient.value = result.patient;
        episode.value = result.episode;
        duplicates.value = [];

        if (isEmergency.value) {
            window.location.assign(`/patients/${result.patient.uuid}`);
            return;
        }

        currentStep.value = 5;
    } catch (error) {
        arrivalErrors.value = error.payload?.errors ?? {};
        duplicates.value = error.payload?.duplicates ?? [];
        arrivalMessage.value = error.message;
    } finally {
        arrivalLoading.value = false;
    }
};

const selectFinancialMode = (mode) => {
    financialMode.value = mode;
    preview.value = null;
    financialErrors.value = {};
    selectedEmployee.value = null;
    employeeSearchPerformed.value = false;
};
const searchEmployees = async () => {
    if (employeeQuery.value.trim().length < 2) return;
    employeeSearchLoading.value = true;
    employeeSearchPerformed.value = false;
    selectedEmployee.value = null;
    financialErrors.value = {};

    try {
        const result = await requestJson(`/reception/employees/patient-lookup?q=${encodeURIComponent(employeeQuery.value.trim())}`);
        employeeMatches.value = result.data ?? [];
        employeeSearchPerformed.value = true;
    } catch (error) {
        financialErrors.value = { employee_uuid: [error.message] };
    } finally {
        employeeSearchLoading.value = false;
    }
};
const employeeSelectable = (employee) => employee.eligible
    && (!employee.linked_patient || employee.linked_patient.uuid === selectedPatient.value?.uuid);
const chooseEmployee = (employee) => {
    if (employeeSelectable(employee)) selectedEmployee.value = employee;
};
const chooseAnotherEmployee = () => {
    selectedEmployee.value = null;
    financialErrors.value = {};
};
const financialPayload = () => {
    const payload = { financial_mode: financialMode.value, lines: cartPayload() };
    if (financialMode.value === 'MUTUAL') Object.assign(payload, mutualForm);
    if (financialMode.value === 'STAFF') payload.employee_uuid = selectedEmployee.value?.uuid;
    return payload;
};
const configureFinancialContext = async () => {
    financialLoading.value = true;
    financialErrors.value = {};

    try {
        const result = await requestJson(`/reception/passages/${episode.value.uuid}/financial-context`, {
            method: 'POST',
            body: JSON.stringify(financialPayload()),
        });
        episode.value = { ...episode.value, ...result.episode };
        preview.value = result.preview;
        currentStep.value = 6;
    } catch (error) {
        financialErrors.value = error.payload?.errors ?? { financial_mode: [error.message] };
    } finally {
        financialLoading.value = false;
    }
};

const confirmCare = () => {
    finalForm.defer_designation = designationDeferred.value;
    finalForm.catalog_lines = cartPayload();
    finalForm.payment_choice = designationDeferred.value || financialMode.value === 'STAFF'
        ? null
        : 'LATER';
    finalForm.post(`/reception/passages/${episode.value.uuid}/prestations`, { preserveScroll: true });
};
const firstError = (errors, key) => Array.isArray(errors?.[key]) ? errors[key][0] : errors?.[key];
const modeLabel = computed(() => ({
    SELF: 'Paiement personnel', MUTUAL: 'Mutuelle', STAFF: 'Personnel clinique',
}[financialMode.value]));
const selectClass = 'block h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const selectLgClass = 'block h-11 w-full rounded-md border border-gray-200 bg-white px-4 text-base text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:border-primary-600 dark:focus:ring-primary-950';
</script>

<template>
    <Head title="Nouvelle prise en charge" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-primary-600">Réception patient</p>
                <h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Nouvelle prise en charge</h1>
                <p class="mt-1 text-sm text-slate-400">Le besoin d’abord, puis l’identité et le contexte financier du passage.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button :as="Link" href="/reception" size="rg" variant="white-outline"><Icon class="me-2" name="list" />Passages récents</Button>
                <Button v-if="currentStep > 1 && !episode" size="rg" variant="white-outline" @click="currentStep = Math.max(1, currentStep - 1)"><Icon class="me-2" name="arrow-left" />Retour</Button>
            </div>
        </header>

        <Card class="overflow-hidden shadow-sm">
            <nav class="overflow-x-auto border-b border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-900 dark:bg-gray-1000/40" aria-label="Progression de la prise en charge">
                <ol class="flex min-w-[780px] items-center">
                    <li v-for="step in steps" :key="step.number" class="flex flex-1 items-center last:flex-none">
                        <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold', step.number < currentStep ? 'bg-primary-600 text-white' : step.number === currentStep ? 'border-2 border-primary-600 bg-white text-primary-600 dark:bg-gray-950' : 'bg-gray-200 text-slate-400 dark:bg-gray-900']">
                            <Icon v-if="step.number < currentStep" name="check" /><span v-else>{{ step.number }}</span>
                        </span>
                        <span :class="['ms-2 whitespace-nowrap text-xs font-semibold', step.number === currentStep ? 'text-slate-700 dark:text-white' : 'text-slate-400']">{{ step.label }}</span>
                        <span v-if="step.number < steps.length" class="mx-3 h-px flex-1 bg-gray-200 dark:bg-gray-800"></span>
                    </li>
                </ol>
            </nav>

            <CardBody v-if="currentStep === 1" class="!p-5 lg:!p-7">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div><h2 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">Quel est votre besoin aujourd’hui ?</h2><p class="mt-1 text-sm text-slate-400">Choisissez les prestations configurées pour la Réception. Aucun dossier patient n’est créé à cette étape.</p></div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="capabilities.can_open_pharmacy_counter_sale" :as="Link" href="/pharmacy/counter-sales/create" size="rg" variant="white-outline"><Icon class="me-2" name="shopping-cart" />Vente comptoir Pharmacie</Button>
                        <Button size="rg" variant="danger" @click="startEmergency"><Icon class="me-2" name="activity" />Admission en urgence</Button>
                    </div>
                </div>

                <div class="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <section>
                        <div v-if="estimateCatalog.length" class="rounded-md border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-1000/40">
                            <div class="mb-2 flex items-center justify-between gap-3 px-1">
                                <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">Catégories de prestations</p>
                                <p class="text-xs text-slate-400">{{ estimateCatalog.length }} disponible{{ estimateCatalog.length > 1 ? 's' : '' }}</p>
                            </div>
                            <div class="flex gap-2 overflow-x-auto pb-1" role="group" aria-label="Filtrer par catégorie">
                                <button v-for="category in catalogCategoryOptions" :key="category.value || 'all'" type="button" :aria-pressed="moduleFilter === category.value" :class="['inline-flex shrink-0 items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition', moduleFilter === category.value ? 'border-primary-600 bg-primary-600 text-white shadow-sm' : 'border-gray-200 bg-white text-slate-600 hover:border-primary-300 hover:text-primary-700 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300']" @click="moduleFilter = category.value">
                                    <span>{{ category.label }}</span>
                                    <span :class="['rounded-full px-1.5 py-0.5 text-[10px] font-bold', moduleFilter === category.value ? 'bg-white/20 text-white' : 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400']">{{ category.count }}</span>
                                </button>
                            </div>
                        </div>
                        <IconInput v-if="estimateCatalog.length" v-model="catalogQuery" class="mt-3" icon="search" placeholder="Rechercher une désignation, un code ou un service…" autocomplete="off" />
                        <div v-if="estimateCatalog.length && filteredCatalog.length" class="mt-3 max-h-[480px] overflow-y-auto rounded-md border border-gray-200 dark:border-gray-800">
                            <button v-for="item in filteredCatalog" :key="item.catalog_item_uuid" type="button" class="grid w-full grid-cols-[40px_minmax(0,1fr)_auto] items-center gap-3 border-b border-gray-100 px-4 py-3 text-start transition last:border-0 hover:bg-gray-50 dark:border-gray-900 dark:hover:bg-gray-1000" @click="addService(item)"><span class="flex h-9 w-9 items-center justify-center rounded border border-gray-200 text-primary-600 dark:border-gray-800"><Icon name="plus" /></span><span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ item.name }}</span><span class="mt-0.5 block truncate text-xs text-slate-400">{{ item.code }} · {{ item.module_label }} · {{ item.routing_label }}</span></span><span class="text-end"><span :class="['block text-sm font-bold', item.tariff_available ? 'text-slate-700 dark:text-white' : 'text-amber-600']">{{ item.tariff_available ? formatMoney(item.unit_price) : 'À configurer' }}</span><span class="text-[11px] text-slate-400">{{ item.unit }}</span></span></button>
                        </div>
                        <div v-else-if="!estimateCatalog.length" class="rounded-md border border-amber-200 bg-amber-50/70 px-5 py-6 dark:border-amber-900 dark:bg-amber-950/20">
                            <div class="flex items-start gap-4">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xl text-amber-700 dark:bg-amber-900/50 dark:text-amber-300"><Icon name="alert-circle" /></span>
                                <div>
                                    <h3 class="text-sm font-bold text-amber-900 dark:text-amber-200">Catalogue Réception non configuré</h3>
                                    <p class="mt-1 max-w-2xl text-xs leading-5 text-amber-800 dark:text-amber-300">Aucune prestation active, facturable, sélectionnable et routée n’est disponible sur ce site. Le référentiel doit être configuré par l’Administration avant toute sélection.</p>
                                    <Button v-if="capabilities.can_manage_catalog" class="mt-3" :as="Link" href="/administration/catalog" size="sm" variant="white-outline"><Icon class="me-2" name="settings" />Ouvrir Désignations & tarifs</Button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="mt-3 rounded-md border border-dashed border-gray-300 px-5 py-8 text-center dark:border-gray-700">
                            <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-xl text-slate-400 dark:bg-gray-900"><Icon name="search" /></span>
                            <p class="mt-3 text-sm font-semibold text-slate-600 dark:text-slate-200">Aucune prestation disponible dans cette catégorie</p>
                            <p class="mt-1 text-xs text-slate-400">Modifiez la recherche ou choisissez une autre catégorie. Les prestations déjà sélectionnées sont masquées.</p>
                            <button v-if="hasCatalogFilters" type="button" class="mt-3 text-xs font-bold text-primary-600 hover:text-primary-700" @click="resetCatalogFilters">Afficher toutes les prestations</button>
                        </div>
                    </section>

                    <aside class="space-y-3 self-start xl:sticky xl:top-20">
                        <div class="overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                            <div class="flex items-center justify-between bg-gray-50 px-4 py-3 dark:bg-gray-1000"><span class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-white"><Icon name="cart" />Sélection</span><span class="rounded-full bg-primary-100 px-2 py-0.5 text-xs font-bold text-primary-700">{{ cart.length }}</span></div>
                            <div v-if="cartLines.length" class="divide-y divide-gray-100 dark:divide-gray-900"><div v-for="entry in cartLines" :key="entry.line.catalog_item_uuid" class="flex items-center gap-3 px-4 py-3"><span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-700 dark:text-white">{{ entry.catalog.name }}</span><span class="text-xs text-slate-400">{{ entry.catalog.module_label }}</span></span><button type="button" class="text-slate-400 hover:text-red-600" @click="removeService(entry.index)"><Icon name="cross" /></button></div></div>
                            <p v-else class="px-4 py-8 text-center text-sm text-slate-400">Ajoutez au moins une prestation.</p>
                            <div class="border-t border-gray-200 p-3 dark:border-gray-800"><Button class="w-full justify-center" size="rg" :disabled="!cart.length" @click="showEstimate">Voir l’estimation<Icon class="ms-2" name="arrow-right" /></Button></div>
                        </div>
                        <button type="button" class="group w-full rounded-md border border-dashed border-amber-300 bg-amber-50/60 p-4 text-start transition hover:border-amber-400 hover:bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20 dark:hover:border-amber-800" @click="continueWithoutKnownDesignation">
                            <span class="flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-amber-100 text-lg text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"><Icon name="info" /></span>
                                <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-700 dark:text-slate-100">Besoin à préciser après évaluation</span><span class="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400">La prestation et son montant seront définis après l’évaluation par les Soins.</span></span>
                                <Icon class="mt-1 shrink-0 text-slate-400 transition group-hover:translate-x-0.5" name="arrow-right" />
                            </span>
                        </button>
                    </aside>
                </div>
            </CardBody>

            <CardBody v-else-if="currentStep === 2" class="!p-5 lg:!p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-xl font-bold text-slate-700 dark:text-white">Estimation au tarif STANDARD</h2><p class="mt-1 text-sm text-slate-400">Le montant est recalculé par Laravel à partir des identifiants et quantités.</p></div><span class="rounded border border-gray-200 px-3 py-1.5 text-xs font-semibold text-slate-500 dark:border-gray-800">Estimation · pas une facture</span></div>
                <div class="mt-6 overflow-hidden rounded-md border border-gray-200 dark:border-gray-800"><div v-for="entry in cartLines" :key="entry.line.catalog_item_uuid" class="grid gap-3 border-b border-gray-100 px-4 py-3 last:border-0 sm:grid-cols-[minmax(0,1fr)_110px_150px_36px] sm:items-center dark:border-gray-900"><div><p class="text-sm font-bold text-slate-700 dark:text-white">{{ entry.catalog.name }}</p><p class="mt-0.5 text-xs text-slate-400">{{ entry.catalog.code }} · {{ entry.catalog.module_label }}</p></div><label><span class="mb-1 block text-[11px] font-semibold uppercase text-slate-400">Quantité</span><Input v-model="entry.line.quantity" type="number" min="0.01" max="9999.99" step="0.01" @change="recalculateEstimate" /></label><div class="sm:text-end"><p class="text-[11px] font-semibold uppercase text-slate-400">Sous-total</p><p class="mt-1 text-sm font-bold text-slate-700 dark:text-white">{{ entry.estimated ? formatMoney(entry.estimated.line_total) : '—' }}</p><p class="text-[11px] text-slate-400">{{ entry.estimated ? `${formatMoney(entry.estimated.unit_price)} / ${entry.catalog.unit}` : 'Tarif indisponible' }}</p></div><button type="button" class="flex h-8 w-8 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" @click="removeService(entry.index)"><Icon name="cross" /></button></div></div>
                <div v-if="estimateError" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">{{ estimateError }} Le parcours clinique pourra néanmoins être conservé sans inventer de tarif.</div>
                <div class="mt-4 flex flex-col gap-3 rounded-md bg-slate-900 px-5 py-4 text-white sm:flex-row sm:items-center sm:justify-between"><div><p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total standard estimé</p><p class="mt-1 font-heading text-2xl font-bold">{{ estimate ? formatMoney(estimate.total_amount) : 'À confirmer' }}</p></div><p class="max-w-md text-xs leading-5 text-slate-300">Le montant définitif peut varier selon le mode de prise en charge. Aucun Patient, Episode, facture ou paiement n’a encore été créé.</p></div>
                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-900"><Button size="rg" variant="white-outline" @click="currentStep = 1"><Icon class="me-2" name="arrow-left" />Modifier le besoin</Button><div class="flex flex-col gap-2 sm:flex-row"><Button :as="Link" href="/reception" size="rg" variant="white-outline">Je voulais seulement connaître le prix</Button><Button size="rg" :disabled="estimateLoading" @click="continueToPatient">Continuer la prise en charge<Icon class="ms-2" name="arrow-right" /></Button></div></div>
            </CardBody>

            <CardBody v-else-if="currentStep === 3" class="!p-5 lg:!p-7">
                <div>
                    <p v-if="isEmergency" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300">Urgence : la création du passage alertera immédiatement Soins et Médecine, sans attendre un mode financier.</p>
                    <h2 class="text-xl font-bold text-slate-700 dark:text-white">Identifier le Patient</h2>
                    <p class="mt-1 text-sm text-slate-400">Recherchez d’abord le dossier permanent. Créez-en un uniquement si le patient n’existe pas.</p>
                </div>

                <div class="mt-5 inline-flex rounded-md border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-gray-950">
                    <button type="button" :class="['rounded px-5 py-2.5 text-sm font-semibold transition', patientMode === 'search' ? 'bg-gray-100 text-slate-700 shadow-sm dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200']" @click="chooseExistingPatient"><Icon class="me-2" name="search" />Patient existant</button>
                    <button v-if="capabilities.can_create_patient" type="button" :class="['rounded px-5 py-2.5 text-sm font-semibold transition', patientMode === 'create' ? 'bg-gray-100 text-slate-700 shadow-sm dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200']" @click="chooseNewPatient"><Icon class="me-2" name="user-add" />Nouveau Patient</button>
                </div>

                <section v-if="patientMode === 'search'" class="mt-5 w-full">
                    <div class="rounded-md border border-gray-200 bg-gray-50/60 p-4 sm:p-5 dark:border-gray-800 dark:bg-gray-1000/40">
                        <div class="mb-3">
                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">Rechercher dans les dossiers patients</h3>
                            <p class="mt-1 text-xs text-slate-400">Numéro de dossier, nom, prénom, téléphone ou numéro de pièce d’identité.</p>
                        </div>
                        <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="searchPatients">
                            <IconInput v-model="patientQuery" size="lg" icon="search" placeholder="Ex. M-26-0001, Rakoto, 034…" autocomplete="off" @update:model-value="patientSearchPerformed = false" />
                            <Button size="lg" type="submit" class="justify-center" :disabled="patientQuery.trim().length < 2 || patientSearchLoading"><Icon class="me-2" :name="patientSearchLoading ? 'loader' : 'search'" />{{ patientSearchLoading ? 'Recherche…' : 'Rechercher' }}</Button>
                        </form>
                    </div>

                    <div v-if="selectedPatient" class="mt-4 flex flex-col gap-4 rounded-md border border-primary-200 bg-primary-50/50 p-4 sm:flex-row sm:items-center dark:border-primary-900 dark:bg-primary-950/20">
                        <Avatar rounded size="rg" variant="primary-pale" :text="formatPatientInitials(selectedPatient)" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="truncate text-base font-bold text-slate-700 dark:text-white">{{ formatPatientName(selectedPatient) }}</p>
                                <span class="rounded-full bg-primary-100 px-2 py-0.5 text-[11px] font-bold text-primary-700 dark:bg-primary-900/50 dark:text-primary-200"><Icon class="me-1" name="check-circle" />Patient sélectionné</span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-300">
                                <span class="font-mono font-semibold">{{ selectedPatient.patient_number }}</span>
                                <span><Icon class="me-1 text-slate-400" name="phone" />{{ selectedPatient.phone || 'Téléphone non renseigné' }}</span>
                                <span v-if="selectedPatient.age !== null"><Icon class="me-1 text-slate-400" name="calendar" />{{ selectedPatient.age }} ans</span>
                            </div>
                        </div>
                        <Button size="sm" variant="white-outline" @click="chooseAnotherPatient">Changer de patient</Button>
                    </div>

                    <div v-else-if="patientMatches.length" class="mt-4 overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                        <div class="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-xs font-semibold text-slate-500 dark:border-gray-800 dark:bg-gray-1000">{{ patientMatches.length }} dossier{{ patientMatches.length > 1 ? 's' : '' }} trouvé{{ patientMatches.length > 1 ? 's' : '' }}</div>
                        <button v-for="patient in patientMatches" :key="patient.uuid" type="button" class="grid w-full gap-3 border-b border-gray-100 px-4 py-3.5 text-start transition last:border-0 hover:bg-primary-50/50 sm:grid-cols-[44px_minmax(0,1fr)_minmax(180px,0.45fr)_auto] sm:items-center dark:border-gray-900 dark:hover:bg-primary-950/20" @click="choosePatient(patient)">
                            <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(patient)" />
                            <span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(patient) }}</span><span class="mt-0.5 block font-mono text-xs text-slate-400">{{ patient.patient_number }}</span></span>
                            <span class="min-w-0 text-xs text-slate-500"><span class="block truncate"><Icon class="me-1 text-slate-400" name="phone" />{{ patient.phone || 'Téléphone non renseigné' }}</span><span v-if="patient.age !== null" class="mt-0.5 block"><Icon class="me-1 text-slate-400" name="calendar" />{{ patient.age }} ans</span></span>
                            <span class="inline-flex items-center text-xs font-bold text-primary-600">Sélectionner<Icon class="ms-1" name="arrow-right" /></span>
                        </button>
                    </div>

                    <div v-else-if="patientSearchPerformed && !patientSearchLoading" class="mt-4 rounded-md border border-dashed border-gray-300 px-5 py-8 text-center dark:border-gray-700">
                        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-xl text-slate-400 dark:bg-gray-900"><Icon name="search" /></span>
                        <p class="mt-3 text-sm font-semibold text-slate-600 dark:text-slate-200">Aucun dossier patient trouvé</p>
                        <p class="mt-1 text-xs text-slate-400">Vérifiez la saisie ou créez un nouveau dossier si nécessaire.</p>
                        <Button v-if="capabilities.can_create_patient" class="mt-3" size="sm" variant="white-outline" @click="chooseNewPatient"><Icon class="me-2" name="user-add" />Créer un nouveau Patient</Button>
                    </div>
                </section>

                <section v-else class="mt-5 w-full overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                    <div class="border-b border-gray-200 bg-gray-50 px-5 py-4 dark:border-gray-800 dark:bg-gray-1000/50">
                        <h3 class="text-sm font-bold text-slate-700 dark:text-white">Identité permanente du Patient</h3>
                        <p class="mt-1 text-xs text-slate-400">Les informations ci-dessous seront conservées dans le dossier administratif.</p>
                    </div>
                    <div class="space-y-5 p-5">
                        <div class="grid gap-4 md:grid-cols-2">
                            <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom *</span><IconInput v-model="patientForm.last_name" size="lg" icon="user" autocomplete="family-name" placeholder="Nom de famille" :aria-invalid="Boolean(firstError(arrivalErrors, 'last_name'))" /><FormError v-if="firstError(arrivalErrors, 'last_name')" class="mt-1">{{ firstError(arrivalErrors, 'last_name') }}</FormError></label>
                            <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Prénom(s)</span><IconInput v-model="patientForm.first_name" size="lg" icon="user" autocomplete="given-name" placeholder="Prénom(s)" :aria-invalid="Boolean(firstError(arrivalErrors, 'first_name'))" /><FormError v-if="firstError(arrivalErrors, 'first_name')" class="mt-1">{{ firstError(arrivalErrors, 'first_name') }}</FormError></label>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <div>
                                <div class="mb-1.5 flex items-center justify-between gap-3"><span class="text-sm font-medium text-slate-700 dark:text-white">Naissance ou âge *</span><span class="inline-flex rounded border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950"><button type="button" :class="['rounded px-2.5 py-1 text-[11px] font-semibold', birthMode === 'date' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400']" @click="setBirthMode('date')">Date</button><button type="button" :class="['rounded px-2.5 py-1 text-[11px] font-semibold', birthMode === 'age' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400']" @click="setBirthMode('age')">Âge</button></span></div>
                                <Input v-if="birthMode === 'date'" v-model="patientForm.birth_date" size="lg" type="date" :aria-invalid="Boolean(firstError(arrivalErrors, 'birth_date'))" />
                                <Input v-else v-model="patientForm.age" size="lg" type="number" min="0" max="130" placeholder="Âge déclaré" :aria-invalid="Boolean(firstError(arrivalErrors, 'age'))" />
                                <FormError v-if="firstError(arrivalErrors, 'birth_date') || firstError(arrivalErrors, 'age')" class="mt-1">{{ firstError(arrivalErrors, 'birth_date') || firstError(arrivalErrors, 'age') }}</FormError>
                            </div>
                            <div>
                                <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Sexe *</span>
                                <div class="flex h-11 items-center gap-5 rounded-md border border-gray-200 bg-white px-4 dark:border-gray-800 dark:bg-gray-950"><label class="inline-flex items-center gap-2 text-sm"><input v-model="patientForm.sex" type="radio" value="M" class="text-primary-600" />Masculin</label><label class="inline-flex items-center gap-2 text-sm"><input v-model="patientForm.sex" type="radio" value="F" class="text-primary-600" />Féminin</label></div>
                                <FormError v-if="firstError(arrivalErrors, 'sex')" class="mt-1">{{ firstError(arrivalErrors, 'sex') }}</FormError>
                            </div>
                            <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Téléphone</span><IconInput v-model="patientForm.phone" size="lg" icon="phone" type="tel" autocomplete="tel" placeholder="Ex. 034 00 000 00" :aria-invalid="Boolean(firstError(arrivalErrors, 'phone'))" /><FormError v-if="firstError(arrivalErrors, 'phone')" class="mt-1">{{ firstError(arrivalErrors, 'phone') }}</FormError></label>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Email</span><IconInput v-model="patientForm.email" size="lg" icon="mail" type="email" autocomplete="email" placeholder="patient@exemple.mg" :aria-invalid="Boolean(firstError(arrivalErrors, 'email'))" /><FormError v-if="firstError(arrivalErrors, 'email')" class="mt-1">{{ firstError(arrivalErrors, 'email') }}</FormError></label>
                            <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Profession</span><IconInput v-model="patientForm.profession" size="lg" icon="briefcase" autocomplete="organization-title" placeholder="Profession" :aria-invalid="Boolean(firstError(arrivalErrors, 'profession'))" /><FormError v-if="firstError(arrivalErrors, 'profession')" class="mt-1">{{ firstError(arrivalErrors, 'profession') }}</FormError></label>
                            <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Adresse</span><select v-model="patientForm.address_entry_uuid" :class="selectLgClass" :aria-invalid="Boolean(firstError(arrivalErrors, 'address_entry_uuid'))"><option value="">Non renseignée</option><option v-for="address in addressEntries" :key="address.uuid" :value="address.uuid">{{ address.label }}</option></select><FormError v-if="firstError(arrivalErrors, 'address_entry_uuid')" class="mt-1">{{ firstError(arrivalErrors, 'address_entry_uuid') }}</FormError></label>
                        </div>
                    </div>
                </section>

                <section v-if="patientMode === 'create' || selectedPatient" class="mt-5 w-full overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                    <div class="flex items-start gap-3 border-b border-gray-200 bg-gray-50 px-5 py-4 dark:border-gray-800 dark:bg-gray-1000/50">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"><Icon name="users" /></span>
                        <div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Personne à contacter pour ce passage <span class="font-normal text-slate-400">(facultatif)</span></h3><p class="mt-1 text-xs text-slate-400">Ces coordonnées appartiennent uniquement au nouvel Episode et peuvent changer à chaque passage.</p></div>
                    </div>
                    <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom du contact</span><IconInput v-model="patientForm.emergency_contact_name" size="lg" icon="user" autocomplete="off" placeholder="Nom complet" :aria-invalid="Boolean(firstError(arrivalErrors, 'emergency_contact_name'))" /><FormError v-if="firstError(arrivalErrors, 'emergency_contact_name')" class="mt-1">{{ firstError(arrivalErrors, 'emergency_contact_name') }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Téléphone du contact</span><IconInput v-model="patientForm.emergency_contact_phone" size="lg" icon="phone" type="tel" autocomplete="off" placeholder="Ex. 034 00 000 00" :aria-invalid="Boolean(firstError(arrivalErrors, 'emergency_contact_phone'))" /><FormError v-if="firstError(arrivalErrors, 'emergency_contact_phone')" class="mt-1">{{ firstError(arrivalErrors, 'emergency_contact_phone') }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Lien avec le patient</span><IconInput v-model="patientForm.emergency_contact_relationship" size="lg" icon="users" autocomplete="off" placeholder="Ex. Conjoint, parent, enfant…" :aria-invalid="Boolean(firstError(arrivalErrors, 'emergency_contact_relationship'))" /><FormError v-if="firstError(arrivalErrors, 'emergency_contact_relationship')" class="mt-1">{{ firstError(arrivalErrors, 'emergency_contact_relationship') }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Email du contact</span><IconInput v-model="patientForm.emergency_contact_email" size="lg" icon="mail" type="email" autocomplete="off" placeholder="contact@exemple.mg" :aria-invalid="Boolean(firstError(arrivalErrors, 'emergency_contact_email'))" /><FormError v-if="firstError(arrivalErrors, 'emergency_contact_email')" class="mt-1">{{ firstError(arrivalErrors, 'emergency_contact_email') }}</FormError></label>
                    </div>
                </section>

                <div v-if="duplicates.length" class="mt-4 w-full rounded-md border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20"><p class="text-sm font-bold text-amber-800 dark:text-amber-200">Un dossier similaire existe déjà.</p><div class="mt-2 space-y-1 text-xs text-amber-700 dark:text-amber-300"><p v-for="patient in duplicates" :key="patient.uuid">{{ patient.patient_number }} · {{ formatPatientName(patient) }}</p></div><div class="mt-3 flex flex-wrap gap-2"><Button size="sm" variant="white-outline" @click="patientMode = 'search'; patientMatches = duplicates; patientSearchPerformed = true">Utiliser un dossier existant</Button><Button size="sm" :disabled="arrivalLoading" @click="createEpisode(true)">Créer quand même</Button></div></div>
                <FormError v-if="arrivalMessage && !duplicates.length" class="mt-4 w-full">{{ arrivalMessage }}</FormError>
                <div v-if="patientMode === 'create' || selectedPatient" class="mt-6 flex justify-end border-t border-gray-200 pt-5 dark:border-gray-900"><Button size="lg" :disabled="arrivalLoading" @click="createEpisode(false)">{{ arrivalLoading ? 'Création du passage…' : (isEmergency ? 'Créer le passage urgence' : 'Créer l’Episode') }}<Icon class="ms-2" name="arrow-right" /></Button></div>
            </CardBody>

            <CardBody v-else-if="currentStep === 5" class="!p-5 lg:!p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 class="text-xl font-bold text-slate-700 dark:text-white">Mode de prise en charge</h2><p class="mt-1 text-sm text-slate-400">Ce choix s’applique uniquement à l’Episode {{ episode.episode_number }}.</p></div>
                    <div class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-1000/40"><Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(selectedPatient)" /><span><strong class="block text-sm text-slate-700 dark:text-white">{{ formatPatientName(selectedPatient) }}</strong><span class="font-mono text-xs text-slate-400">{{ selectedPatient.patient_number }}</span></span></div>
                </div>

                <div class="mt-6 grid gap-3 md:grid-cols-3">
                    <button type="button" :aria-pressed="financialMode === 'SELF'" :class="['relative rounded-md border p-5 text-start transition lg:p-6', financialMode === 'SELF' ? 'border-primary-500 bg-primary-50/40 ring-1 ring-primary-100 dark:bg-primary-950/20' : 'border-gray-200 hover:border-primary-300 dark:border-gray-800']" @click="selectFinancialMode('SELF')"><Icon v-if="financialMode === 'SELF'" class="absolute end-4 top-4 text-lg text-primary-600" name="check-circle" /><Icon class="text-2xl text-primary-600" name="wallet" /><span class="mt-3 block text-sm font-bold text-slate-700 dark:text-white">Paiement personnel</span><span class="mt-1 block text-xs leading-5 text-slate-400">Tarif STANDARD, à la charge du patient.</span></button>
                    <button type="button" :disabled="!capabilities.can_use_mutual" :aria-pressed="financialMode === 'MUTUAL'" :class="['relative rounded-md border p-5 text-start transition disabled:cursor-not-allowed disabled:opacity-50 lg:p-6', financialMode === 'MUTUAL' ? 'border-primary-500 bg-primary-50/40 ring-1 ring-primary-100 dark:bg-primary-950/20' : 'border-gray-200 hover:border-primary-300 dark:border-gray-800']" @click="selectFinancialMode('MUTUAL')"><Icon v-if="financialMode === 'MUTUAL'" class="absolute end-4 top-4 text-lg text-primary-600" name="check-circle" /><Icon class="text-2xl text-primary-600" name="shield-check" /><span class="mt-3 block text-sm font-bold text-slate-700 dark:text-white">Mutuelle</span><span class="mt-1 block text-xs leading-5 text-slate-400">Organisme existant et tarif MUTUAL du site.</span></button>
                    <button type="button" :disabled="!capabilities.can_use_staff || !capabilities.can_link_staff" :aria-pressed="financialMode === 'STAFF'" :class="['relative rounded-md border p-5 text-start transition disabled:cursor-not-allowed disabled:opacity-50 lg:p-6', financialMode === 'STAFF' ? 'border-primary-500 bg-primary-50/40 ring-1 ring-primary-100 dark:bg-primary-950/20' : 'border-gray-200 hover:border-primary-300 dark:border-gray-800']" @click="selectFinancialMode('STAFF')"><Icon v-if="financialMode === 'STAFF'" class="absolute end-4 top-4 text-lg text-primary-600" name="check-circle" /><Icon class="text-2xl text-primary-600" name="briefcase" /><span class="mt-3 block text-sm font-bold text-slate-700 dark:text-white">Personnel clinique</span><span class="mt-1 block text-xs leading-5 text-slate-400">Employé RH existant et règles Personnel serveur.</span></button>
                </div>

                <section v-if="financialMode === 'MUTUAL'" class="mt-5 w-full overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                    <div class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 dark:bg-gray-1000/50">
                        <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"><Icon name="shield-check" /></span><div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Couverture mutuelle de cet Episode</h3><p class="mt-1 text-xs text-slate-400">Sélectionnez un organisme existant et renseignez l’adhésion pour ce passage.</p></div></div>
                        <span v-if="selectedOrganization" class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"><Icon class="me-1.5" name="check-circle" />{{ Number(selectedOrganization.coverage_rate).toLocaleString('fr-FR') }} % de couverture</span>
                    </div>
                    <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Organisme *</span><select v-model="mutualForm.mutual_organization_uuid" :class="selectLgClass" :aria-invalid="Boolean(firstError(financialErrors, 'mutual_organization_uuid'))"><option value="">Choisir un organisme</option><option v-for="organization in mutualOrganizations" :key="organization.uuid" :value="organization.uuid">{{ organization.name }} · {{ Number(organization.coverage_rate).toLocaleString('fr-FR') }} %</option></select><FormError v-if="firstError(financialErrors, 'mutual_organization_uuid')" class="mt-1">{{ firstError(financialErrors, 'mutual_organization_uuid') }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Entreprise *</span><IconInput v-model="mutualForm.employer_name" size="lg" icon="building" placeholder="Nom de l’entreprise" :aria-invalid="Boolean(firstError(financialErrors, 'employer_name'))" /><FormError v-if="firstError(financialErrors, 'employer_name')" class="mt-1">{{ firstError(financialErrors, 'employer_name') }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Qualité du bénéficiaire *</span><select v-model="mutualForm.beneficiary_type" :class="selectLgClass" :aria-invalid="Boolean(firstError(financialErrors, 'beneficiary_type'))"><option value="PRINCIPAL">Principal</option><option value="FAMILY_MEMBER">Membre de la famille</option></select><FormError v-if="firstError(financialErrors, 'beneficiary_type')" class="mt-1">{{ firstError(financialErrors, 'beneficiary_type') }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Matricule d’adhésion *</span><IconInput v-model="mutualForm.membership_number" size="lg" icon="card-view" placeholder="Numéro de matricule" :aria-invalid="Boolean(firstError(financialErrors, 'membership_number'))" /><FormError v-if="firstError(financialErrors, 'membership_number')" class="mt-1">{{ firstError(financialErrors, 'membership_number') }}</FormError></label>
                    </div>
                </section>

                <section v-if="financialMode === 'STAFF'" class="mt-5 w-full overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                    <div class="flex items-start gap-3 border-b border-gray-200 bg-gray-50 px-5 py-4 dark:border-gray-800 dark:bg-gray-1000/50"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"><Icon name="briefcase" /></span><div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Identifier l’Employé RH</h3><p class="mt-1 text-xs text-slate-400">Recherchez le dossier Employé existant puis confirmez la personne liée à ce Patient.</p></div></div>
                    <div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,0.7fr)]">
                        <div class="min-w-0">
                            <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Employé de la clinique *</label>
                            <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="searchEmployees">
                                <IconInput v-model="employeeQuery" size="lg" icon="search" placeholder="Matricule, nom, prénom, téléphone ou pièce d’identité…" autocomplete="off" @update:model-value="employeeSearchPerformed = false" />
                                <Button size="lg" type="submit" class="justify-center" :disabled="employeeQuery.trim().length < 2 || employeeSearchLoading"><Icon class="me-2" :name="employeeSearchLoading ? 'loader' : 'search'" />{{ employeeSearchLoading ? 'Recherche…' : 'Rechercher' }}</Button>
                            </form>

                            <div v-if="selectedEmployee" class="mt-4 flex flex-col gap-3 rounded-md border border-primary-200 bg-primary-50/50 p-4 sm:flex-row sm:items-center dark:border-primary-900 dark:bg-primary-950/20">
                                <Avatar rounded size="rg" variant="primary-pale" :text="formatPatientInitials(selectedEmployee)" />
                                <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(selectedEmployee) }}</p><span class="rounded-full bg-primary-100 px-2 py-0.5 text-[11px] font-bold text-primary-700 dark:bg-primary-900/50 dark:text-primary-200"><Icon class="me-1" name="check-circle" />Employé sélectionné</span></div><p class="mt-1 text-xs text-slate-500 dark:text-slate-300"><span class="font-mono font-semibold">{{ selectedEmployee.employee_number }}</span><span class="mx-2 text-slate-300">·</span>{{ selectedEmployee.profession || 'Fonction non renseignée' }}</p></div>
                                <Button size="sm" variant="white-outline" @click="chooseAnotherEmployee">Changer d’employé</Button>
                            </div>

                            <div v-else-if="employeeSearchPerformed && employeeMatches.length" class="mt-4 overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                                <div class="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-xs font-semibold text-slate-500 dark:border-gray-800 dark:bg-gray-1000">{{ employeeMatches.length }} employé{{ employeeMatches.length > 1 ? 's' : '' }} trouvé{{ employeeMatches.length > 1 ? 's' : '' }}</div>
                                <button v-for="employee in employeeMatches" :key="employee.uuid" type="button" :disabled="!employeeSelectable(employee)" :class="['grid w-full gap-3 border-b border-gray-100 px-4 py-3.5 text-start transition last:border-0 sm:grid-cols-[44px_minmax(0,1fr)_auto] sm:items-center dark:border-gray-900', employeeSelectable(employee) ? 'hover:bg-primary-50/50 dark:hover:bg-primary-950/20' : 'cursor-not-allowed bg-gray-50/70 opacity-60 dark:bg-gray-1000/40']" @click="chooseEmployee(employee)"><Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(employee)" /><span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(employee) }}</span><span class="mt-0.5 block text-xs text-slate-400"><span class="font-mono">{{ employee.employee_number }}</span> · {{ employee.profession || 'Fonction non renseignée' }}</span></span><span :class="['inline-flex items-center rounded-full px-2 py-1 text-[11px] font-bold', employeeSelectable(employee) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300']"><Icon class="me-1" :name="employeeSelectable(employee) ? 'check-circle' : 'alert-circle'" />{{ employeeSelectable(employee) ? 'Sélectionner' : 'Relié à un autre Patient' }}</span></button>
                            </div>

                            <div v-else-if="employeeSearchPerformed && !employeeSearchLoading" class="mt-4 rounded-md border border-dashed border-gray-300 px-5 py-7 text-center dark:border-gray-700"><Icon class="text-2xl text-slate-300" name="search" /><p class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-200">Aucun Employé RH trouvé</p><p class="mt-1 text-xs text-slate-400">Vérifiez le matricule, l’identité ou le numéro saisi.</p></div>
                            <FormError v-if="firstError(financialErrors, 'employee_uuid')" class="mt-2">{{ firstError(financialErrors, 'employee_uuid') }}</FormError>
                        </div>

                        <aside class="space-y-3">
                            <div class="rounded-md border border-sky-200 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/20"><div class="flex items-start gap-3"><Icon class="mt-0.5 text-xl text-sky-600" name="info" /><div><h4 class="text-sm font-bold text-sky-900 dark:text-sky-200">Identification limitée</h4><p class="mt-1 text-xs leading-5 text-sky-700 dark:text-sky-300">La Réception voit uniquement le matricule, l’identité, la fonction et l’état du lien Patient. Aucun historique RH ou financier n’est exposé.</p></div></div></div>
                            <div class="rounded-md border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20"><div class="flex items-start gap-3"><Icon class="mt-0.5 text-xl text-amber-600" name="alert-circle" /><div><h4 class="text-sm font-bold text-amber-900 dark:text-amber-200">Prévisualisation sans débit</h4><p class="mt-1 text-xs leading-5 text-amber-800 dark:text-amber-300">Le calcul ne consomme aucun crédit Bloc. Une consommation réelle n’a lieu qu’à la création de la prestation facturable.</p></div></div></div>
                        </aside>
                    </div>
                </section>
                <FormError v-if="firstError(financialErrors, 'financial_mode')" class="mt-4">{{ firstError(financialErrors, 'financial_mode') }}</FormError>
                <div class="mt-6 flex justify-end border-t border-gray-200 pt-5 dark:border-gray-900"><Button size="lg" :disabled="financialLoading || (financialMode === 'MUTUAL' && (!mutualForm.mutual_organization_uuid || !mutualForm.employer_name || !mutualForm.membership_number)) || (financialMode === 'STAFF' && !selectedEmployee)" @click="configureFinancialContext">{{ financialLoading ? 'Calcul Laravel…' : 'Calculer la prise en charge' }}<Icon class="ms-2" name="arrow-right" /></Button></div>
            </CardBody>

            <CardBody v-else-if="currentStep === 6" class="!p-5 lg:!p-7">
                <div><h2 class="text-xl font-bold text-slate-700 dark:text-white">Confirmer la prise en charge</h2><p class="mt-1 text-sm text-slate-400">Les montants ci-dessous ont été recalculés depuis le contexte de l’Episode. La confirmation figera les prestations puis déclenchera le routage.</p></div>
                <div class="mt-5 grid gap-3 sm:grid-cols-3"><div class="rounded-md border border-gray-200 p-4 dark:border-gray-800"><p class="text-[11px] font-bold uppercase text-slate-400">Patient</p><p class="mt-1 text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(selectedPatient) }}</p><p class="text-xs text-slate-400">{{ selectedPatient.patient_number }}</p></div><div class="rounded-md border border-gray-200 p-4 dark:border-gray-800"><p class="text-[11px] font-bold uppercase text-slate-400">Episode</p><p class="mt-1 font-mono text-sm font-bold text-slate-700 dark:text-white">{{ episode.episode_number }}</p><p class="text-xs text-slate-400">Un seul passage pour toutes les prestations</p></div><div class="rounded-md border border-gray-200 p-4 dark:border-gray-800"><p class="text-[11px] font-bold uppercase text-slate-400">Mode financier</p><p class="mt-1 text-sm font-bold text-slate-700 dark:text-white">{{ modeLabel }}</p><p v-if="preview?.organization_name" class="text-xs text-slate-400">{{ preview.organization_name }} · {{ Number(preview.coverage_rate).toLocaleString('fr-FR') }} %</p></div></div>
                <div class="mt-5 overflow-hidden rounded-md border border-gray-200 dark:border-gray-800"><div v-if="designationDeferred" class="px-4 py-5"><p class="text-sm font-bold text-slate-700 dark:text-white">Besoin à définir après évaluation</p><p class="mt-1 text-xs text-slate-400">Prestation et montant à définir après l’évaluation. Destination initiale : Soins.</p></div><div v-for="line in previewLines" v-else :key="line.catalog_item_uuid" class="grid gap-3 border-b border-gray-100 px-4 py-3 last:border-0 sm:grid-cols-[minmax(0,1fr)_90px_130px_130px_130px] sm:items-center dark:border-gray-900"><div><p class="text-sm font-bold text-slate-700 dark:text-white">{{ line.name }}</p><p class="text-xs text-slate-400">{{ line.code }} · {{ line.routing_label }}</p></div><p class="text-sm text-slate-500">× {{ Number(line.quantity).toLocaleString('fr-FR') }}</p><div><p class="text-[10px] font-bold uppercase text-slate-400">Brut</p><p class="text-sm font-semibold">{{ line.gross_amount ? formatMoney(line.gross_amount) : 'En attente' }}</p></div><div><p class="text-[10px] font-bold uppercase text-slate-400">Couverture</p><p class="text-sm font-semibold text-emerald-600">{{ line.coverage_amount !== null ? formatMoney(line.coverage_amount) : 'En attente' }}</p></div><div><p class="text-[10px] font-bold uppercase text-slate-400">Patient</p><p class="text-sm font-bold text-slate-700 dark:text-white">{{ line.patient_amount !== null ? formatMoney(line.patient_amount) : 'En attente' }}</p></div></div></div>
                <div class="mt-4 grid gap-3 sm:grid-cols-4"><div class="rounded-md bg-gray-50 p-4 dark:bg-gray-1000"><p class="text-[11px] font-bold uppercase text-slate-400">Montant brut</p><p class="mt-1 text-lg font-bold text-slate-700 dark:text-white">{{ preview?.totals.gross_amount ? formatMoney(preview.totals.gross_amount) : '—' }}</p></div><div class="rounded-md bg-emerald-50 p-4 dark:bg-emerald-950/20"><p class="text-[11px] font-bold uppercase text-emerald-600">Couverture</p><p class="mt-1 text-lg font-bold text-emerald-700 dark:text-emerald-300">{{ preview && preview.totals.coverage_amount !== null ? formatMoney(preview.totals.coverage_amount) : '—' }}</p></div><div class="rounded-md bg-amber-50 p-4 dark:bg-amber-950/20"><p class="text-[11px] font-bold uppercase text-amber-600">Reste patient</p><p class="mt-1 text-lg font-bold text-amber-700 dark:text-amber-300">{{ preview && preview.totals.patient_amount !== null ? formatMoney(preview.totals.patient_amount) : '—' }}</p></div><div class="rounded-md bg-primary-50 p-4 dark:bg-primary-950/20"><p class="text-[11px] font-bold uppercase text-primary-600">Destination initiale</p><p class="mt-1 text-lg font-bold text-primary-700 dark:text-primary-300">{{ designationDeferred ? 'Soins' : (preview?.initial_destination?.label || 'À calculer') }}</p></div></div>
                <div v-if="preview?.totals.resolution_pending" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">Une ligne reste financièrement en attente (tarif manquant ou politique Personnel non classifiée). Sa demande clinique et son routage seront conservés ; aucun montant ne sera inventé.</div>
                <FormError v-if="finalForm.errors.catalog_lines || finalForm.errors.financial_mode" class="mt-4">{{ finalForm.errors.catalog_lines || finalForm.errors.financial_mode }}</FormError>
                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-900"><Button size="rg" variant="white-outline" @click="currentStep = 5"><Icon class="me-2" name="arrow-left" />Modifier le mode</Button><Button size="rg" :disabled="finalForm.processing" @click="confirmCare"><Icon class="me-2" name="check" />{{ finalForm.processing ? 'Confirmation…' : 'Confirmer la prise en charge' }}</Button></div>
            </CardBody>
        </Card>
    </div>
</template>
