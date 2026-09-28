<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    ArrowLeft,
    Ban,
    Building2,
    Circle,
    CircleCheck,
    ClipboardCheck,
    Eye,
    FileText,
    Hash,
    History,
    Pencil,
    Receipt,
    RotateCcw,
    Route,
    Server,
    Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import FormSectionCard from '@/Components/SuperAdmin/Tariffs/FormSectionCard.vue';
import FormError from '@/Components/UI/FormError.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/money';
import { categoryIcon } from '@/utilities/tariffCategoryIcons';
import {
    catalogGroupKey,
    categoryChoices,
    categoryFields,
} from '@/utilities/catalogGroups';

/**
 * Une désignation d'un site, sur sa propre page (ADR-044, amendement du
 * 2026-09-28) : la créer, ou la modifier avec ses deux tarifs et leur
 * historique. Tout part par l'API du site ; l'écran ne décide rien, et le
 * motif automatique d'un tarif est écrit par le serveur, jamais par le
 * navigateur.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    /** Le site de la désignation. Jamais `site` : la prop partagée du même nom porte le menu du portail. */
    targetSite: { type: Object, required: true },
    item: { type: Object, default: null },
    options: { type: Object, default: null },
    /** La catégorie d'où l'on vient (`LABORATORY`, `IMAGING:ULTRASOUND`) : la nouvelle désignation y est rangée d'avance. */
    category: { type: String, default: null },
    siteError: { type: String, default: null },
});

const { can } = usePermissions();
const editing = computed(() => props.item !== null);
const archived = computed(() => Boolean(props.item?.archived));
const canEdit = computed(() => (editing.value ? can('catalog.items.update') && ! archived.value : can('catalog.items.create')));
const canManageTariffs = computed(() => (can('catalog.tariffs.create') || can('catalog.tariffs.update')) && ! archived.value);

const categories = computed(() => categoryChoices(props.options?.modules ?? []));
const initialCategory = (() => {
    if (props.item) return catalogGroupKey(props.item);
    const keys = categoryChoices(props.options?.modules ?? []).map((choice) => choice.value);

    return keys.includes(props.category) ? props.category : (keys.includes('RECEPTION') ? 'RECEPTION' : (keys[0] ?? 'RECEPTION'));
})();
const categoryKey = ref(initialCategory);
const categoryLabel = computed(() => categories.value.find((choice) => choice.value === categoryKey.value)?.label ?? '');
const backUrl = computed(() => `/super-admin/workspaces/tariffs?${new URLSearchParams({ site: props.targetSite.code, module: categoryKey.value }).toString()}`);

// ------------------------------------------------------------------------
// La désignation
// ------------------------------------------------------------------------
const form = useForm({
    site_code: props.targetSite.code,
    code: props.item?.code ?? '',
    name: props.item?.name ?? '',
    type: props.item?.type ?? 'SERVICE',
    ...categoryFields(initialCategory),
    unit: props.item?.unit ?? 'acte',
    billable: props.item?.billable ?? true,
    stockable: props.item?.stockable ?? false,
    reception_selectable: props.item?.reception_selectable ?? false,
    reception_routing_mode: props.item?.reception_routing_mode ?? null,
    staff_coverage_policy: props.item?.staff_coverage_policy ?? 'UNCLASSIFIED',
    care_requires_allergy_check: props.item?.care_requires_allergy_check ?? false,
    care_recommends_vitals: props.item?.care_recommends_vitals ?? false,
    description: props.item?.description ?? '',
    tariff_amount: '',
    mutual_tariff_amount: '',
    tariff_reason_auto: true,
    tariff_reason: '',
});

watch(categoryKey, (key) => Object.assign(form, categoryFields(key)));

const typeOptions = computed(() => (props.options?.types ?? []).map((type) => ({ value: type.value, label: type.label })));
const selectedType = computed(() => (props.options?.types ?? []).find((type) => type.value === form.type));
const isService = computed(() => form.type === 'SERVICE');
const isCareService = computed(() => isService.value && form.module === 'CARE');
const routingModel = computed({
    get: () => form.reception_routing_mode ?? '',
    set: (value) => { form.reception_routing_mode = value || null; },
});
const routingOptions = computed(() => [{ value: '', label: 'Choisir le parcours' }, ...(props.options?.routing_modes ?? [])]);

// Le type décide de ce qui est facturable et stockable ; il ne change plus après la création.
watch(() => form.type, (type) => {
    if (editing.value) return;
    form.billable = Boolean(selectedType.value?.billable);
    form.stockable = Boolean(selectedType.value?.stockable);
    form.reception_selectable = false;
    form.reception_routing_mode = null;
    form.staff_coverage_policy = 'UNCLASSIFIED';
    if (type === 'SERVICE') form.unit = 'acte';
    if (type === 'MEDICINE' || type === 'CONSUMABLE') form.unit = 'unité';
});
watch(isCareService, (enabled) => {
    if (enabled) return;
    form.care_requires_allergy_check = false;
    form.care_recommends_vitals = false;
});

/** Ce qui manque encore pour enregistrer : dit en clair, au lieu d'un bouton grisé muet. */
const missing = computed(() => [
    ! form.name.trim() && 'la désignation',
    ! editing.value && ! form.code.trim() && 'le code',
    ! form.unit.trim() && 'l’unité',
    isService.value && form.reception_selectable && ! form.reception_routing_mode && 'le parcours à la Réception',
    ! editing.value && selectedType.value?.billable && ! String(form.tariff_amount).trim() && 'le tarif sans mutuelle',
    ! editing.value && ! form.tariff_reason_auto && ! form.tariff_reason.trim() && 'le motif',
].filter(Boolean));
const canSubmit = computed(() => canEdit.value && ! form.processing && ! missing.value.length && (! editing.value || form.isDirty));

const submit = () => {
    if (! canSubmit.value) return;
    const care = (data) => ({
        care_requires_allergy_check: isCareService.value ? data.care_requires_allergy_check : false,
        care_recommends_vitals: isCareService.value ? data.care_recommends_vitals : false,
    });

    if (editing.value) {
        form.transform((data) => ({
            name: data.name,
            module: data.module,
            imaging_modality: data.module === 'IMAGING' ? (data.imaging_modality || null) : null,
            unit: data.unit,
            reception_selectable: data.reception_selectable,
            reception_routing_mode: data.reception_selectable ? data.reception_routing_mode : null,
            staff_coverage_policy: data.staff_coverage_policy,
            ...care(data),
            description: data.description || null,
        })).put(`/super-admin/workspaces/tariffs/items/${props.targetSite.code}/${props.item.uuid}`, {
            preserveScroll: true,
            onSuccess: () => form.defaults(),
        });
        return;
    }

    form.transform((data) => ({
        ...data,
        code: data.code.trim().toUpperCase(),
        imaging_modality: data.module === 'IMAGING' ? (data.imaging_modality || null) : null,
        reception_routing_mode: data.reception_selectable ? data.reception_routing_mode : null,
        ...care(data),
        tariff_reason: data.tariff_reason_auto ? '' : data.tariff_reason,
        description: data.description || null,
    })).post('/super-admin/workspaces/tariffs/items', { preserveScroll: true });
};

// ------------------------------------------------------------------------
// Les deux tarifs (en modification)
// ------------------------------------------------------------------------
const tariffCategories = computed(() => props.options?.tariff_categories?.length
    ? props.options.tariff_categories
    : [{ value: 'STANDARD', label: 'Sans mutuelle' }, { value: 'MUTUAL', label: 'Mutuelle' }]);
const currentOf = (category) => (category === 'MUTUAL' ? props.item?.current_mutual_tariff : props.item?.current_standard_tariff);
const historyOf = (category) => (props.item?.tariffs ?? []).filter((tariff) => tariff.tariff_category === category);
const tariffForms = {
    STANDARD: useForm({ tariff_category: 'STANDARD', tariff_amount: '', reason_auto: true, reason: '' }),
    MUTUAL: useForm({ tariff_category: 'MUTUAL', tariff_amount: '', reason_auto: true, reason: '' }),
};
const expandedHistory = ref({ STANDARD: false, MUTUAL: false });
const tariffReady = (category) => {
    const tariffForm = tariffForms[category];
    const amount = Number(tariffForm.tariff_amount);

    return canManageTariffs.value && ! tariffForm.processing && amount > 0
        && amount !== Number(currentOf(category)?.amount ?? 0)
        && (tariffForm.reason_auto || tariffForm.reason.trim());
};
const submitTariff = (category) => {
    if (! tariffReady(category)) return;
    const tariffForm = tariffForms[category];
    tariffForm
        .transform((data) => ({ ...data, reason: data.reason_auto ? '' : data.reason }))
        .post(`/super-admin/workspaces/tariffs/items/${props.targetSite.code}/${props.item.uuid}/tariffs`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => tariffForm.reset('tariff_amount', 'reason'),
        });
};

// Suspendre un tarif ou archiver la désignation : une décision, dont le motif s'écrit toujours à la main (ADR-009).
const confirmation = ref(null);
const confirmationForm = useForm({ tariff_category: 'STANDARD', reason: '' });
const askSuspend = (category) => {
    confirmationForm.reset();
    confirmationForm.clearErrors();
    confirmationForm.tariff_category = category;
    confirmation.value = { mode: 'tariff', category };
};
const askArchive = () => {
    confirmationForm.reset();
    confirmationForm.clearErrors();
    confirmation.value = { mode: 'item' };
};
const closeConfirmation = () => {
    if (confirmationForm.processing) return;
    confirmation.value = null;
};
const submitConfirmation = () => {
    const base = `/super-admin/workspaces/tariffs/items/${props.targetSite.code}/${props.item.uuid}`;
    const visit = { preserveScroll: true, preserveState: true, onSuccess: () => { confirmation.value = null; } };
    if (confirmation.value.mode === 'tariff') confirmationForm.post(`${base}/tariffs/archive`, visit);
    else confirmationForm.delete(base, visit);
};
const restore = () => router.post(`/super-admin/workspaces/tariffs/items/${props.targetSite.code}/${props.item.uuid}/restore`, {}, { preserveScroll: true });

// Arrivée depuis un tarif de la liste (`?grille=MUTUAL#tarifs`) : le champ de cette grille est prêt à saisir.
const requestedGrid = new URL(usePage().url, 'http://rivo.local').searchParams.get('grille');
onMounted(() => {
    if (! editing.value || ! requestedGrid) return;
    document.getElementById('tarifs')?.scrollIntoView({ block: 'start' });
    document.getElementById(`tarif-${requestedGrid === 'MUTUAL' ? 'MUTUAL' : 'STANDARD'}`)?.focus({ preventScroll: true });
});

const formatDate = (value) => (value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—');
const titleText = computed(() => (editing.value ? props.item.name : 'Nouvelle désignation'));
const listUrl = computed(() => `/super-admin/workspaces/tariffs?${new URLSearchParams({ site: props.targetSite.code }).toString()}`);

// ------------------------------------------------------------------------
// L'aperçu et la liste de contrôle (colonne de droite)
// ------------------------------------------------------------------------
const labelIn = (list, value) => (list ?? []).find((entry) => entry.value === value)?.label ?? '';
const preview = computed(() => ({
    name: form.name.trim(),
    code: form.code.trim().toUpperCase(),
    unit: form.unit.trim(),
    type: labelIn(props.options?.types, form.type),
    reception: isService.value && form.reception_selectable
        ? (labelIn(props.options?.routing_modes, form.reception_routing_mode) || 'Parcours à choisir')
        : 'Non proposée',
    policy: form.billable ? labelIn(props.options?.staff_coverage_policies, form.staff_coverage_policy) : 'Non facturable',
}));
/** Le tarif qu'affichera la liste : celui saisi à la création, sinon celui en vigueur. */
const previewTariff = (category) => {
    if (! form.billable) return 'Non facturable';
    if (editing.value) {
        const current = currentOf(category);

        return current ? formatMoney(current.amount, current.currency) : 'Aucun tarif';
    }
    const amount = category === 'MUTUAL' ? form.mutual_tariff_amount : form.tariff_amount;

    return Number(amount) > 0 ? formatMoney(amount) : (category === 'MUTUAL' ? 'Facultatif' : 'À saisir');
};
const checklist = computed(() => [
    { label: 'Catégorie et type', done: Boolean(categoryKey.value && form.type) },
    { label: 'Désignation', done: Boolean(form.name.trim()) },
    ...(editing.value ? [] : [{ label: 'Code', done: Boolean(form.code.trim()) }]),
    { label: 'Unité', done: Boolean(form.unit.trim()) },
    ...(isService.value && form.reception_selectable ? [{ label: 'Parcours à la Réception', done: Boolean(form.reception_routing_mode) }] : []),
    ...(! editing.value && selectedType.value?.billable ? [{ label: 'Tarif sans mutuelle', done: Number(form.tariff_amount) > 0 }] : []),
    ...(! editing.value && selectedType.value?.billable ? [{ label: 'Motif', done: form.tariff_reason_auto || Boolean(form.tariff_reason.trim()) }] : []),
]);
const checklistDone = computed(() => checklist.value.filter((entry) => entry.done).length);
const submitLabel = computed(() => (form.processing ? 'Enregistrement…' : editing.value ? 'Enregistrer les modifications' : 'Créer la désignation'));
const statusLine = computed(() => {
    if (missing.value.length) return `À compléter : ${missing.value.join(', ')}.`;
    if (editing.value && ! form.isDirty) return 'Aucune modification.';

    return editing.value ? 'Modifications prêtes à enregistrer.' : `Prête à créer sur ${props.targetSite.name}.`;
});
</script>

<template>
    <Head :title="titleText" />

    <div class="w-full space-y-6 pb-24 xl:pb-6">
        <!-- En-tête : où l'on est, ce que l'on crée ou modifie -->
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0 space-y-3">
                <Breadcrumb :items="[
                    { label: 'Tarifs & mutuelles', href: listUrl },
                    { label: categoryLabel || 'Catégorie', href: backUrl },
                    { label: editing ? item.code : 'Nouvelle désignation' },
                ]" />
                <div class="flex min-w-0 items-center gap-3.5">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl border border-border bg-card text-foreground shadow-sm">
                        <component :is="categoryIcon(categoryKey)" class="h-6 w-6" />
                    </span>
                    <div class="min-w-0">
                        <h1 class="truncate text-2xl font-semibold tracking-tight text-foreground">{{ titleText }}</h1>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                            <span class="inline-flex items-center gap-1.5"><Building2 class="h-3.5 w-3.5" />{{ targetSite.name }}</span>
                            <span aria-hidden="true">·</span><span>{{ categoryLabel }}</span>
                            <template v-if="editing"><span aria-hidden="true">·</span><span class="font-mono text-xs">{{ item.code }}</span></template>
                            <Badge v-if="archived" variant="outline" class="py-0.5"><Archive class="h-3 w-3" />Archivée</Badge>
                        </p>
                    </div>
                </div>
            </div>
            <div v-if="editing && ! siteError" class="flex shrink-0 flex-wrap items-center gap-2">
                <Button v-if="archived && can('catalog.items.restore')" type="button" variant="outline" @click="restore"><RotateCcw class="h-4 w-4" />Restaurer</Button>
                <Button v-else-if="! archived && can('catalog.items.delete')" type="button" variant="outline" @click="askArchive"><Archive class="h-4 w-4" />Archiver</Button>
            </div>
        </div>

        <Card v-if="siteError || ! options" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
            <span class="grid h-11 w-11 place-items-center rounded-lg border border-border text-muted-foreground"><Server class="h-5 w-5" /></span>
            <h2 class="mt-3 text-sm font-semibold text-foreground">{{ targetSite.name }} ne répond pas</h2>
            <p class="mt-1 max-w-xl text-sm text-muted-foreground">{{ siteError ?? 'Les listes de choix du site sont indisponibles.' }}</p>
            <Button as="a" :href="backUrl" variant="outline" class="mt-4"><ArrowLeft class="h-4 w-4" />Retour aux tarifs</Button>
        </Card>

        <div v-else class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] 2xl:grid-cols-[minmax(0,1fr)_24rem]">
            <!-- ------------------------------------------------------------ -->
            <!-- Le formulaire                                                  -->
            <!-- ------------------------------------------------------------ -->
            <div class="min-w-0 space-y-6">
                <p v-if="archived" class="flex items-start gap-2.5 rounded-lg border border-border bg-card px-4 py-3 text-sm text-muted-foreground">
                    <Archive class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Archivée le {{ formatDate(item.archived_at) }}<template v-if="item.archive_reason"> — {{ item.archive_reason }}</template>. Elle n’est plus proposée ; restaurez-la pour la modifier.</span>
                </p>

                <form id="item-form" class="space-y-6" @submit.prevent="submit">
                    <fieldset :disabled="! canEdit" class="contents">
                        <FormSectionCard :icon="FileText" title="Identification" description="Ce que lisent la Réception et la Caisse, et où la désignation se range.">
                            <div class="grid gap-x-4 gap-y-5 md:grid-cols-2 2xl:grid-cols-4">
                                <FormField label="Désignation" required :error="form.errors.name" class="md:col-span-2">
                                    <Input v-model="form.name" placeholder="Ex. Échographie abdominale" maxlength="255" />
                                </FormField>
                                <FormField label="Code" required :error="form.errors.code" :hint="editing ? '(définitif)' : ''">
                                    <IconInput v-model="form.code" :icon="Hash" :disabled="editing || ! canEdit" class="uppercase" placeholder="ECHO-ABD" maxlength="50" />
                                </FormField>
                                <FormField label="Unité" required :error="form.errors.unit">
                                    <Input v-model="form.unit" placeholder="acte, examen, unité…" maxlength="50" />
                                </FormField>
                                <FormField label="Catégorie" required as="div" :error="form.errors.module || form.errors.imaging_modality" class="md:col-span-1 2xl:col-span-2">
                                    <Select v-model="categoryKey" :options="categories" :disabled="! canEdit" aria-label="Catégorie" class="w-full" />
                                </FormField>
                                <FormField label="Type" required as="div" :error="form.errors.type" :hint="editing ? '(définitif)' : ''" class="md:col-span-1 2xl:col-span-2">
                                    <Select v-model="form.type" :options="typeOptions" :disabled="editing || ! canEdit" aria-label="Type" class="w-full" />
                                </FormField>
                                <FormField label="Description" hint="(facultatif)" :error="form.errors.description" class="md:col-span-2 2xl:col-span-4">
                                    <Textarea v-model="form.description" :rows="2" placeholder="Une précision utile, si besoin" />
                                </FormField>
                            </div>
                        </FormSectionCard>

                        <FormSectionCard v-if="isService" :icon="Route" title="Parcours du patient" description="Ce que la Réception peut choisir à l’arrivée, et les rappels aux Soins.">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <label :class="cn('flex cursor-pointer items-start justify-between gap-4 rounded-lg border px-4 py-3.5 transition-colors', form.reception_selectable ? 'border-primary/40 bg-primary/5' : 'border-border')">
                                    <span>
                                        <span class="block text-sm font-medium text-foreground">Proposée à la Réception</span>
                                        <span class="mt-0.5 block text-sm text-muted-foreground">La réceptionniste la choisit en créant le passage.</span>
                                    </span>
                                    <Switch v-model="form.reception_selectable" :disabled="! canEdit" aria-label="Proposée à la Réception" />
                                </label>
                                <FormField label="Parcours" :required="form.reception_selectable" as="div" :error="form.errors.reception_routing_mode">
                                    <Select v-model="routingModel" :options="routingOptions" :disabled="! canEdit || ! form.reception_selectable" aria-label="Parcours du patient" class="w-full" />
                                    <p v-if="! form.reception_selectable" class="mt-1.5 text-sm text-muted-foreground">Activez « Proposée à la Réception » pour le choisir.</p>
                                </FormField>
                                <template v-if="isCareService">
                                    <label :class="cn('flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3.5 transition-colors', form.care_requires_allergy_check ? 'border-primary/40 bg-primary/5' : 'border-border')">
                                        <Checkbox v-model="form.care_requires_allergy_check" :disabled="! canEdit" class="mt-0.5" />
                                        <span class="text-sm"><span class="font-medium text-foreground">Vérifier les allergies</span><span class="mt-0.5 block text-muted-foreground">Rappel affiché avant de réaliser l’acte.</span></span>
                                    </label>
                                    <label :class="cn('flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3.5 transition-colors', form.care_recommends_vitals ? 'border-primary/40 bg-primary/5' : 'border-border')">
                                        <Checkbox v-model="form.care_recommends_vitals" :disabled="! canEdit" class="mt-0.5" />
                                        <span class="text-sm"><span class="font-medium text-foreground">Relever les constantes</span><span class="mt-0.5 block text-muted-foreground">Recommandé à l’infirmier pour cet acte.</span></span>
                                    </label>
                                </template>
                            </div>
                        </FormSectionCard>

                        <FormSectionCard v-if="form.billable" :icon="Receipt" title="Facturation" :description="editing ? 'La prise en charge du personnel. Les tarifs se règlent juste en dessous.' : 'Les deux tarifs de départ et la prise en charge du personnel.'">
                            <div class="grid gap-x-4 gap-y-5 md:grid-cols-2 2xl:grid-cols-4">
                                <FormField label="Avantage Personnel" as="div" :error="form.errors.staff_coverage_policy" :class="editing ? 'md:col-span-2' : 'md:col-span-2'">
                                    <Select v-model="form.staff_coverage_policy" :options="options.staff_coverage_policies ?? []" :disabled="! canEdit" aria-label="Politique Avantage Personnel" class="w-full" />
                                    <p class="mt-1.5 text-sm text-muted-foreground">Jamais déduite du nom ni de la catégorie (ADR-052).</p>
                                </FormField>
                                <template v-if="! editing && selectedType?.billable">
                                    <FormField label="Tarif sans mutuelle" required :error="form.errors.tariff_amount">
                                        <div class="relative">
                                            <Input v-model="form.tariff_amount" type="number" min="1" step="1" inputmode="numeric" class="pe-10 tabular-nums" placeholder="0" />
                                            <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">Ar</span>
                                        </div>
                                    </FormField>
                                    <FormField label="Tarif mutuelle" hint="(facultatif)" :error="form.errors.mutual_tariff_amount">
                                        <div class="relative">
                                            <Input v-model="form.mutual_tariff_amount" type="number" min="1" step="1" inputmode="numeric" class="pe-10 tabular-nums" placeholder="0" />
                                            <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">Ar</span>
                                        </div>
                                    </FormField>
                                    <div class="md:col-span-2 2xl:col-span-4">
                                        <div class="flex flex-col gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3.5 lg:flex-row lg:items-center lg:justify-between">
                                            <label class="flex cursor-pointer items-start gap-3">
                                                <Checkbox v-model="form.tariff_reason_auto" class="mt-0.5" aria-describedby="item-reason-auto" />
                                                <span class="text-sm">
                                                    <span class="font-medium text-foreground">Motif automatique</span>
                                                    <span id="item-reason-auto" class="mt-0.5 block text-muted-foreground">{{ form.tariff_reason_auto ? '« Tarif initial fixé à la création de la désignation. »' : 'Écrivez le motif à droite.' }}</span>
                                                </span>
                                            </label>
                                            <div v-if="! form.tariff_reason_auto" class="w-full lg:max-w-md">
                                                <Input v-model="form.tariff_reason" placeholder="Motif — ex. grille validée par la direction" maxlength="1000" aria-label="Motif" />
                                            </div>
                                        </div>
                                        <FormError v-if="form.errors.tariff_reason" class="mt-2">{{ form.errors.tariff_reason }}</FormError>
                                        <p class="mt-2 text-sm text-muted-foreground">Le tarif mutuelle reste distinct : il ne reprend jamais le tarif sans mutuelle.</p>
                                    </div>
                                </template>
                            </div>
                        </FormSectionCard>
                    </fieldset>
                    <FormError v-if="form.errors.site_code">{{ form.errors.site_code }}</FormError>
                </form>

                <!-- Les deux tarifs et leur historique (en modification) -->
                <FormSectionCard v-if="editing" id="tarifs" :icon="Wallet" title="Tarifs" description="Chaque modification crée une nouvelle version ; les factures déjà émises gardent leur montant.">
                    <p v-if="! item.billable" class="text-sm text-muted-foreground">Non facturable : cette désignation n’a pas de tarif.</p>
                    <div v-else class="grid gap-4 lg:grid-cols-2">
                        <section
                            v-for="grid in tariffCategories"
                            :key="grid.value"
                            class="flex flex-col rounded-lg border border-border"
                            :aria-labelledby="`tarif-titre-${grid.value}`"
                        >
                            <div class="flex items-start justify-between gap-3 px-4 pt-4">
                                <div class="min-w-0">
                                    <h3 :id="`tarif-titre-${grid.value}`" class="text-sm font-medium text-muted-foreground">{{ grid.label }}</h3>
                                    <p v-if="currentOf(grid.value)" class="mt-1 text-2xl font-semibold tabular-nums text-foreground">{{ formatMoney(currentOf(grid.value).amount, currentOf(grid.value).currency) }}</p>
                                    <p v-else class="mt-1 text-2xl font-semibold text-muted-foreground">—</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        <template v-if="currentOf(grid.value)">Depuis le {{ formatDate(currentOf(grid.value).effective_from) }}<template v-if="currentOf(grid.value).creator"> · {{ currentOf(grid.value).creator }}</template></template>
                                        <template v-else>Aucun tarif en vigueur</template>
                                    </p>
                                </div>
                                <Button
                                    v-if="currentOf(grid.value) && can('catalog.tariffs.archive') && ! archived"
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    class="text-muted-foreground hover:text-destructive"
                                    @click="askSuspend(grid.value)"
                                ><Ban class="h-3.5 w-3.5" />Suspendre</Button>
                            </div>

                            <form v-if="canManageTariffs" class="space-y-3 px-4 pt-4" @submit.prevent="submitTariff(grid.value)">
                                <div class="flex gap-2">
                                    <div class="relative min-w-0 flex-1">
                                        <Input
                                            :id="`tarif-${grid.value}`"
                                            v-model="tariffForms[grid.value].tariff_amount"
                                            type="number"
                                            min="1"
                                            step="1"
                                            inputmode="numeric"
                                            class="pe-10 tabular-nums"
                                            :placeholder="currentOf(grid.value) ? 'Nouveau montant' : 'Montant'"
                                            :aria-label="`Nouveau tarif ${grid.label}`"
                                        />
                                        <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">Ar</span>
                                    </div>
                                    <Button type="submit" :disabled="! tariffReady(grid.value)">
                                        <Pencil v-if="currentOf(grid.value)" class="h-4 w-4" /><CircleCheck v-else class="h-4 w-4" />{{ currentOf(grid.value) ? 'Modifier' : 'Définir' }}
                                    </Button>
                                </div>
                                <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                                    <Checkbox v-model="tariffForms[grid.value].reason_auto" class="mt-0.5" />
                                    <span>
                                        <span class="text-foreground">Motif automatique</span>
                                        <span v-if="tariffForms[grid.value].reason_auto" class="block text-xs text-muted-foreground">Il dira la grille et le nouveau montant.</span>
                                    </span>
                                </label>
                                <Input
                                    v-if="! tariffForms[grid.value].reason_auto"
                                    v-model="tariffForms[grid.value].reason"
                                    placeholder="Motif du changement"
                                    maxlength="1000"
                                    :aria-label="`Motif du tarif ${grid.label}`"
                                />
                                <FormError v-for="error in Object.values(tariffForms[grid.value].errors)" :key="error">{{ error }}</FormError>
                            </form>

                            <div class="mt-4 border-t border-border px-4 py-3">
                                <button
                                    v-if="historyOf(grid.value).length"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
                                    :aria-expanded="expandedHistory[grid.value]"
                                    @click="expandedHistory[grid.value] = ! expandedHistory[grid.value]"
                                >
                                    <History class="h-3.5 w-3.5" />Historique · {{ historyOf(grid.value).length }}
                                </button>
                                <p v-else class="text-xs text-muted-foreground">Aucun historique.</p>
                                <ol v-if="expandedHistory[grid.value]" class="mt-3 space-y-2.5">
                                    <li v-for="tariff in historyOf(grid.value)" :key="tariff.uuid" class="text-xs">
                                        <p class="flex items-center justify-between gap-2">
                                            <span :class="cn('font-medium tabular-nums', tariff.current ? 'text-foreground' : 'text-muted-foreground')">{{ formatMoney(tariff.amount, tariff.currency) }}</span>
                                            <span class="text-muted-foreground">{{ tariff.current ? 'En vigueur' : `jusqu’au ${formatDate(tariff.effective_until)}` }}</span>
                                        </p>
                                        <p class="mt-0.5 text-muted-foreground">{{ formatDate(tariff.effective_from) }}<template v-if="tariff.creator"> · {{ tariff.creator }}</template></p>
                                        <p v-if="tariff.change_reason" class="mt-0.5 text-muted-foreground">{{ tariff.change_reason }}</p>
                                    </li>
                                </ol>
                            </div>
                        </section>
                    </div>
                </FormSectionCard>
            </div>

            <!-- ------------------------------------------------------------ -->
            <!-- Aperçu, contrôle et enregistrement                             -->
            <!-- ------------------------------------------------------------ -->
            <aside class="space-y-4 xl:sticky xl:top-20 xl:self-start">
                <Card>
                    <div class="flex items-center gap-2 border-b border-border px-5 py-3.5">
                        <Eye class="h-4 w-4 text-muted-foreground" />
                        <h2 class="text-sm font-semibold text-foreground">Aperçu dans la liste</h2>
                    </div>
                    <div class="space-y-4 p-5">
                        <div class="flex items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-foreground"><component :is="categoryIcon(categoryKey)" class="h-4 w-4" /></span>
                            <div class="min-w-0">
                                <p :class="cn('truncate font-medium', preview.name ? 'text-foreground' : 'text-muted-foreground')">{{ preview.name || 'Nom de la désignation' }}</p>
                                <p class="mt-0.5 truncate text-xs text-muted-foreground"><span class="font-mono">{{ preview.code || 'CODE' }}</span> · {{ preview.unit || 'unité' }} · {{ categoryLabel }}</p>
                            </div>
                        </div>
                        <dl class="divide-y divide-border rounded-lg border border-border text-sm">
                            <div class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                                <dt class="text-muted-foreground">Sans mutuelle</dt>
                                <dd class="font-medium tabular-nums text-foreground">{{ previewTariff('STANDARD') }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                                <dt class="text-muted-foreground">Mutuelle</dt>
                                <dd class="font-medium tabular-nums text-foreground">{{ previewTariff('MUTUAL') }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                                <dt class="text-muted-foreground">Réception</dt>
                                <dd class="truncate text-end text-foreground">{{ preview.reception }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                                <dt class="text-muted-foreground">Personnel</dt>
                                <dd class="truncate text-end text-foreground">{{ preview.policy }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <Card v-if="canEdit" class="hidden xl:block">
                    <div class="flex items-center justify-between gap-2 border-b border-border px-5 py-3.5">
                        <span class="flex items-center gap-2"><ClipboardCheck class="h-4 w-4 text-muted-foreground" /><h2 class="text-sm font-semibold text-foreground">Avant d’enregistrer</h2></span>
                        <span class="text-xs tabular-nums text-muted-foreground">{{ checklistDone }} / {{ checklist.length }}</span>
                    </div>
                    <ul class="space-y-2.5 px-5 py-4">
                        <li v-for="entry in checklist" :key="entry.label" class="flex items-center gap-2.5 text-sm">
                            <CircleCheck v-if="entry.done" class="h-4 w-4 shrink-0 text-primary" />
                            <Circle v-else class="h-4 w-4 shrink-0 text-muted-foreground/50" />
                            <span :class="entry.done ? 'text-foreground' : 'text-muted-foreground'">{{ entry.label }}</span>
                        </li>
                    </ul>
                    <div class="space-y-2 border-t border-border p-5">
                        <Button type="submit" form="item-form" class="w-full" :disabled="! canSubmit">
                            <CircleCheck class="h-4 w-4" />{{ submitLabel }}
                        </Button>
                        <Button as="a" :href="backUrl" variant="outline" class="w-full">Annuler</Button>
                        <p class="pt-1 text-center text-xs text-muted-foreground">{{ statusLine }}</p>
                    </div>
                </Card>
            </aside>
        </div>

        <!-- Sur un écran étroit, l'enregistrement reste sous la main, au bas de l'écran. -->
        <div v-if="canEdit && ! siteError && options" class="sticky bottom-4 z-20 xl:hidden">
            <div class="flex flex-col gap-3 rounded-xl border border-border bg-card/95 px-4 py-3 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted-foreground">{{ statusLine }}</p>
                <div class="flex items-center gap-2">
                    <Button as="a" :href="backUrl" variant="outline">Annuler</Button>
                    <Button type="submit" form="item-form" :disabled="! canSubmit"><CircleCheck class="h-4 w-4" />{{ submitLabel }}</Button>
                </div>
            </div>
        </div>

        <Dialog
            :open="confirmation !== null"
            :title="confirmation?.mode === 'tariff' ? `Suspendre le tarif ${confirmation.category === 'MUTUAL' ? 'mutuelle' : 'sans mutuelle'} ?` : 'Archiver cette désignation ?'"
            :description="confirmation?.mode === 'tariff'
                ? 'Aucun tarif de remplacement n’est inventé : cette grille reste sans tarif jusqu’à une nouvelle décision.'
                : 'Elle ne sera plus proposée aux nouveaux passages. Les factures et passages existants ne changent pas.'"
            :dismissible="false"
            @update:open="(value) => value || closeConfirmation()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-destructive/10 text-destructive"><component :is="confirmation?.mode === 'tariff' ? Ban : Archive" class="h-5 w-5" /></span>
            </template>
            <form id="item-confirmation-form" @submit.prevent="submitConfirmation">
                <FormField label="Motif" required :error="confirmationForm.errors.reason">
                    <Textarea v-model="confirmationForm.reason" :rows="3" placeholder="La décision, en une phrase" />
                </FormField>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="confirmationForm.processing" @click="closeConfirmation">Annuler</Button>
                <Button type="submit" form="item-confirmation-form" variant="danger" :disabled="confirmationForm.processing || ! confirmationForm.reason.trim()">
                    <component :is="confirmation?.mode === 'tariff' ? Ban : Archive" class="h-4 w-4" />Confirmer
                </Button>
            </template>
        </Dialog>
    </div>
</template>
