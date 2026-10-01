<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Banknote, Building2, Check, CircleEllipsis, CreditCard, Hash, Info, Landmark, LayoutGrid, Pencil, Plus, Power,
    PowerOff, ReceiptText, Search, Server, ShieldCheck, Smartphone, Tag, Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import SearchSelect from '@/Components/Shadcn/SearchSelect.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import { usePermissions } from '@/composables/usePermissions';
import {
    CATEGORY_DEFAULTS, NAME_PLACEHOLDERS, bankLabel, categoryCaption, fieldsFor, isGenericBankMethod, matchesSearch,
    missingFields, payloadFor, suggestedCode,
} from '@/utilities/paymentMethods';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();

/** Une icône par catégorie, lue sur la valeur du site — jamais sur un libellé. */
const CATEGORY_ICONS = { CASH: Banknote, MOBILE_MONEY: Smartphone, BANK: Landmark, COVERAGE: ShieldCheck, OTHER: CircleEllipsis };
const CATEGORY_HINTS = {
    CASH: 'Entre dans le tiroir, compté à la clôture.',
    MOBILE_MONEY: 'Un mode par opérateur : MVola, Orange Money, Airtel Money.',
    BANK: 'Rattaché à une banque du référentiel du site.',
    COVERAGE: 'Une prise en charge, sans argent encaissé.',
    OTHER: 'Vous nommez vous-même la catégorie.',
};
const categoryIcon = (value) => CATEGORY_ICONS[value] ?? CreditCard;

const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const search = ref('');
const statusFilter = ref('ALL');
const categoryFilter = ref('');

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
// Le vocabulaire vient du site lui-même : chaque version répond avec les catégories qu'elle connaît.
const categories = computed(() => selectedSite.value?.meta?.categories ?? []);
const banks = computed(() => selectedSite.value?.meta?.banks ?? []);
const allMethods = computed(() => selectedSite.value?.data ?? []);

const counts = computed(() => ({
    ALL: allMethods.value.length,
    ACTIVE: allMethods.value.filter((method) => method.active).length,
    INACTIVE: allMethods.value.filter((method) => !method.active).length,
    CASH: allMethods.value.filter((method) => method.active && method.affects_cash_balance).length,
}));
const counterTiles = computed(() => [
    { value: 'ALL', label: 'Tous', icon: LayoutGrid, count: counts.value.ALL, active: statusFilter.value === 'ALL', tone: 'neutral' },
    { value: 'ACTIVE', label: 'Actifs', hint: 'proposés à la caisse', icon: Power, count: counts.value.ACTIVE, active: statusFilter.value === 'ACTIVE', tone: 'emerald' },
    { value: 'INACTIVE', label: 'Désactivés', hint: 'gardés pour l’historique', icon: PowerOff, count: counts.value.INACTIVE, active: statusFilter.value === 'INACTIVE', tone: 'neutral' },
    { value: 'CASH', label: 'Fond de caisse', hint: 'espèces en tiroir', icon: Wallet, count: counts.value.CASH, active: statusFilter.value === 'CASH', tone: 'amber' },
]);

const methods = computed(() => allMethods.value.filter((method) => {
    const status = statusFilter.value === 'ALL'
        || (statusFilter.value === 'ACTIVE' && method.active)
        || (statusFilter.value === 'INACTIVE' && !method.active)
        || (statusFilter.value === 'CASH' && method.active && method.affects_cash_balance);

    return status && (!categoryFilter.value || method.category === categoryFilter.value) && matchesSearch(method, search.value);
}));
const categoryFilterOptions = computed(() => [{ value: '', label: 'Toutes les catégories' }, ...categories.value.map((category) => ({ value: category.value, label: category.label }))]);

const selectSite = (code) => {
    selectedSiteCode.value = code;
    search.value = '';
    statusFilter.value = 'ALL';
    categoryFilter.value = '';
};

// --- La fiche -------------------------------------------------------------

const dialogOpen = ref(false);
const editing = ref(null);
const codeTouched = ref(false);
const form = useForm({
    site_code: '', code: '', name: '', category: 'CASH', bank_uuid: '', category_detail: '',
    affects_cash_balance: true, requires_reference: false,
});

const fields = computed(() => fieldsFor(form.category));
const bankOptions = computed(() => {
    const options = banks.value.map((bank) => ({ value: bank.uuid, label: bankLabel(bank) }));
    // Une banque archivée depuis reste lisible sur le mode qui la porte déjà.
    const current = editing.value?.bank;
    if (current && !options.some((option) => option.value === current.uuid)) options.push({ value: current.uuid, label: `${bankLabel(current)} (archivée)` });

    return options;
});
const chosenBank = computed(() => banks.value.find((bank) => bank.uuid === form.bank_uuid) ?? (editing.value?.bank?.uuid === form.bank_uuid ? editing.value.bank : null));
const missing = computed(() => missingFields(form, { editing: editing.value }));
const banksLink = computed(() => `/super-admin/sites/${selectedSiteCode.value}/rh/banks`);

// Le code se propose tant qu'on ne l'a pas écrit soi-même ; il ne change plus après la création.
watch(() => [form.category, form.bank_uuid, form.category_detail, form.name], () => {
    if (!editing.value && !codeTouched.value) form.code = suggestedCode(form, banks.value);
});

const chooseCategory = (value) => {
    if (form.category === value) return;
    form.category = value;
    form.clearErrors('bank_uuid', 'category_detail', 'name');
    // À la création seulement : on propose les réglages usuels de la catégorie.
    if (!editing.value) Object.assign(form, CATEGORY_DEFAULTS[value] ?? {});
};

const openCreate = () => {
    editing.value = null;
    codeTouched.value = false;
    form.defaults({
        site_code: selectedSiteCode.value, code: '', name: '', category: 'CASH', bank_uuid: '', category_detail: '',
        ...CATEGORY_DEFAULTS.CASH,
    });
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
};

const openEdit = (method) => {
    editing.value = method;
    codeTouched.value = true;
    form.clearErrors();
    Object.assign(form, {
        site_code: selectedSiteCode.value,
        code: method.code,
        // Le libellé d'une banque suit la banque tant qu'on ne l'a pas réécrit.
        name: method.bank && method.name === bankLabel(method.bank) ? '' : method.name,
        category: method.category,
        bank_uuid: method.bank_uuid ?? '',
        category_detail: method.category_detail ?? '',
        affects_cash_balance: method.affects_cash_balance,
        requires_reference: method.requires_reference,
    });
    dialogOpen.value = true;
};

const closeDialog = () => {
    if (form.processing) return;
    dialogOpen.value = false;
};

const submit = () => {
    if (missing.value.length) return;
    const options = { preserveScroll: true, onSuccess: () => { dialogOpen.value = false; } };
    const request = form.transform(() => payloadFor(form, { editing: editing.value }));

    if (editing.value) {
        request.put(`/super-admin/payment-methods/${selectedSiteCode.value}/${editing.value.uuid}`, options);
        return;
    }

    request.post('/super-admin/payment-methods', options);
};

// --- Activer, désactiver ---------------------------------------------------

const toggling = ref(null);
const toggleProcessing = ref(false);
const confirmToggle = () => {
    const method = toggling.value;
    if (!method) return;
    toggleProcessing.value = true;
    router.post(`/super-admin/payment-methods/${selectedSiteCode.value}/${method.uuid}/${method.active ? 'deactivate' : 'activate'}`, {}, {
        preserveScroll: true,
        onFinish: () => { toggleProcessing.value = false; toggling.value = null; },
    });
};
</script>

<template>
    <Head title="Modes de paiement" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><CreditCard class="h-5 w-5" /></span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Finances</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-foreground">Modes de paiement</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Les moyens de règlement que la caisse de chaque clinique accepte. Chaque écriture est faite et auditée par le site.</p>
                </div>
            </div>
            <Button v-if="can('payment_methods.create')" :disabled="!selectedSite?.ok" @click="openCreate"><Plus class="h-4 w-4" />Nouveau mode</Button>
        </header>

        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Site">
            <button
                v-for="site in sites"
                :key="site.site.code"
                type="button"
                role="tab"
                :aria-selected="selectedSiteCode === site.site.code"
                :class="['inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                         selectedSiteCode === site.site.code ? 'border-primary bg-primary/5 text-foreground' : 'border-border bg-card text-muted-foreground hover:text-foreground']"
                @click="selectSite(site.site.code)"
            >
                <Building2 class="h-4 w-4" />
                {{ site.site.name }}
                <span :class="['h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'UNCONFIGURED' ? 'bg-muted-foreground/40' : 'bg-red-500']" :title="site.ok ? 'Site joignable' : site.status === 'UNCONFIGURED' ? 'Site non configuré' : 'Site injoignable'" />
            </button>
        </div>

        <Card v-if="!selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
            <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-muted text-muted-foreground"><Server class="h-5 w-5" /></span>
            <h2 class="mt-3 text-sm font-bold text-foreground">API indisponible pour {{ selectedSite?.site.name }}</h2>
            <p class="mt-1 max-w-xl text-xs leading-5 text-muted-foreground">{{ selectedSite?.message }}</p>
            <p class="mt-3 text-xs text-muted-foreground">Les autres sites restent utilisables ; aucune base clinique n’est lue directement.</p>
        </Card>

        <template v-else>
            <QueueCounters :tiles="counterTiles" compact @select="statusFilter = $event" />

            <Card class="overflow-hidden p-0">
                <div class="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center sm:justify-between">
                    <IconInput v-model="search" :icon="Search" type="search" class="w-full sm:max-w-sm" placeholder="Libellé, code, banque…" aria-label="Rechercher un mode de paiement" />
                    <Select v-model="categoryFilter" :options="categoryFilterOptions" placeholder="Toutes les catégories" class="sm:w-56" aria-label="Filtrer par catégorie" />
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[920px] text-sm">
                        <thead class="bg-muted/50 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-5 py-3 text-start">Mode de paiement</th>
                                <th class="px-4 py-3 text-start">Catégorie</th>
                                <th class="px-4 py-3 text-start">Caisse</th>
                                <th class="px-4 py-3 text-start">Référence</th>
                                <th class="px-4 py-3 text-end">Paiements</th>
                                <th class="px-4 py-3 text-start">État</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="method in methods" :key="method.uuid" :class="method.active ? '' : 'bg-muted/30'">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', method.active ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground']">
                                            <component :is="categoryIcon(method.category)" class="h-4 w-4" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="font-semibold text-foreground">{{ method.name }}</p>
                                            <p class="font-mono text-[11px] text-muted-foreground">{{ method.code }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge tone="neutral"><component :is="categoryIcon(method.category)" class="h-3.5 w-3.5" />{{ categoryCaption(method) }}</Badge>
                                    <p v-if="method.bank" class="mt-1 text-xs text-muted-foreground">
                                        {{ method.bank.name }}<span v-if="method.bank.archived" class="text-amber-700 dark:text-amber-300"> · banque archivée</span>
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge v-if="method.affects_cash_balance" tone="warning"><Wallet class="h-3.5 w-3.5" />Fond de caisse</Badge>
                                    <span v-else class="text-xs text-muted-foreground">Hors tiroir</span>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge v-if="method.requires_reference" tone="info"><ReceiptText class="h-3.5 w-3.5" />Saisie exigée</Badge>
                                    <span v-else class="text-xs text-muted-foreground">Générée</span>
                                </td>
                                <td class="px-4 py-3 text-end font-semibold tabular-nums text-muted-foreground">{{ method.payments_count }}</td>
                                <td class="px-4 py-3">
                                    <Badge :tone="method.active ? 'success' : 'neutral'">{{ method.active ? 'Actif' : 'Désactivé' }}</Badge>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex justify-end gap-1">
                                        <Button v-if="can('payment_methods.update')" variant="ghost" size="icon" :aria-label="`Modifier ${method.name}`" title="Modifier" @click="openEdit(method)"><Pencil class="h-4 w-4" /></Button>
                                        <Button v-if="method.active && can('payment_methods.deactivate')" variant="ghost" size="icon" :aria-label="`Désactiver ${method.name}`" title="Désactiver" @click="toggling = method"><PowerOff class="h-4 w-4" /></Button>
                                        <Button v-if="!method.active && can('payment_methods.activate')" variant="ghost" size="icon" :aria-label="`Réactiver ${method.name}`" title="Réactiver" @click="toggling = method"><Power class="h-4 w-4" /></Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!methods.length">
                                <td colspan="7" class="px-5 py-12 text-center">
                                    <LayoutGrid class="mx-auto h-6 w-6 text-muted-foreground" />
                                    <p class="mt-2 text-sm font-medium text-muted-foreground">Aucun mode de paiement ne correspond à ces filtres.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="flex items-start gap-2 border-t border-border bg-muted/40 px-5 py-3 text-xs leading-5 text-muted-foreground">
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    Chaque site garde sa propre liste. Un mode n’est jamais supprimé — les paiements encaissés gardent leur référence — et le dernier mode actif d’un site ne peut pas être désactivé. Seule la Réception / Caisse encaisse.
                </p>
            </Card>
        </template>

        <Dialog
            :open="dialogOpen"
            :title="editing ? `Modifier « ${editing.name} »` : 'Nouveau mode de paiement'"
            :description="`Site : ${selectedSite?.site.name ?? ''}. Le code identifie le mode sur les paiements : il ne change plus après la création.`"
            size="xl"
            :dismissible="false"
            close-label="Annuler"
            @update:open="(value) => value || closeDialog()"
        >
            <form id="payment-method-form" class="space-y-5" @submit.prevent="submit">
                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-foreground">Catégorie <span class="text-destructive">*</span></legend>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" role="radiogroup" aria-label="Catégorie">
                        <button
                            v-for="category in categories"
                            :key="category.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.category === category.value"
                            :class="['flex items-start gap-3 rounded-lg border p-3 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                                     form.category === category.value ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40']"
                            @click="chooseCategory(category.value)"
                        >
                            <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-md', form.category === category.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground']">
                                <component :is="categoryIcon(category.value)" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-foreground">{{ category.label }}</span>
                                <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ CATEGORY_HINTS[category.value] ?? '' }}</span>
                            </span>
                        </button>
                    </div>
                    <p v-if="form.errors.category" class="mt-1 text-xs text-destructive">{{ form.errors.category }}</p>
                </fieldset>

                <template v-if="fields.bank">
                    <FormField as="div" label="Banque" :icon="Landmark" :required="!isGenericBankMethod(editing)" :error="form.errors.bank_uuid">
                        <SearchSelect
                            id="payment-method-bank"
                            v-model="form.bank_uuid"
                            :options="bankOptions"
                            placeholder="Choisir une banque du référentiel"
                            search-placeholder="Sigle ou nom de la banque"
                            empty-text="Aucune banque ne correspond."
                            :invalid="Boolean(form.errors.bank_uuid)"
                        />
                    </FormField>
                    <p v-if="isGenericBankMethod(editing)" class="-mt-3 text-xs leading-5 text-muted-foreground">
                        Mode générique (toutes banques) : laissez vide pour le garder ainsi.
                    </p>
                    <p v-if="!banks.length" class="-mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>Aucune banque active sur ce site. Ajoutez-la d’abord dans <Link :href="banksLink" class="font-semibold underline underline-offset-2">Ressources humaines › Banques</Link>.</span>
                    </p>
                </template>

                <FormField v-if="fields.detail" label="Précisez la catégorie" :icon="Tag" :required="!(editing?.category === 'OTHER' && !editing?.category_detail)" :error="form.errors.category_detail">
                    <Input v-model="form.category_detail" maxlength="60" placeholder="Carte bancaire, bon d’achat…" />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Libellé"
                        :icon="CreditCard"
                        :required="fields.name"
                        :hint="fields.name ? '' : '(facultatif)'"
                        :error="form.errors.name"
                    >
                        <Input v-model="form.name" maxlength="100" :placeholder="fields.bank && chosenBank ? bankLabel(chosenBank) : NAME_PLACEHOLDERS[form.category]" />
                    </FormField>
                    <FormField label="Code" :icon="Hash" :required="!editing" :error="form.errors.code">
                        <Input v-model="form.code" :disabled="Boolean(editing)" maxlength="40" class="font-mono uppercase" placeholder="MOBILE_MONEY_MVOLA" @input="codeTouched = true" />
                    </FormField>
                </div>
                <p v-if="fields.bank && !form.name && chosenBank" class="-mt-3 text-xs text-muted-foreground">Le libellé sera « {{ bankLabel(chosenBank) }} ».</p>

                <div class="grid gap-2 md:grid-cols-2">
                    <label class="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                        <span>
                            <span class="flex items-center gap-2 text-sm font-semibold text-foreground"><Wallet class="h-4 w-4 text-muted-foreground" />Impacte le fond de caisse</span>
                            <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">Uniquement pour les espèces : le montant entre dans le tiroir et compte au comptage de clôture.</span>
                        </span>
                        <Switch v-model="form.affects_cash_balance" aria-label="Impacte le fond de caisse" />
                    </label>
                    <label class="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                        <span>
                            <span class="flex items-center gap-2 text-sm font-semibold text-foreground"><ReceiptText class="h-4 w-4 text-muted-foreground" />Exige une référence</span>
                            <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">Le caissier saisit le n° de transaction (mobile money, chèque, virement). Sinon, le n° de paiement généré en tient lieu.</span>
                        </span>
                        <Switch v-model="form.requires_reference" aria-label="Exige une référence" />
                    </label>
                </div>

                <p v-if="form.errors.method || form.errors.site_code" class="text-sm text-destructive">{{ form.errors.method || form.errors.site_code }}</p>
            </form>

            <template #footer>
                <p v-if="missing.length" class="me-auto self-center text-xs text-muted-foreground">À compléter : {{ missing.join(', ') }}.</p>
                <Button type="button" variant="outline" :disabled="form.processing" @click="closeDialog">Annuler</Button>
                <Button type="submit" form="payment-method-form" :disabled="form.processing || missing.length > 0">
                    <Check class="h-4 w-4" />{{ editing ? 'Enregistrer' : 'Créer sur le site' }}
                </Button>
            </template>
        </Dialog>

        <ConfirmModal
            :open="Boolean(toggling)"
            :title="toggling?.active ? `Désactiver « ${toggling?.name} » ?` : `Réactiver « ${toggling?.name} » ?`"
            :description="toggling?.active
                ? 'La caisse ne le proposera plus. Les paiements déjà encaissés gardent leur mode.'
                : 'La caisse le proposera de nouveau.'"
            :confirm-label="toggling?.active ? 'Désactiver' : 'Réactiver'"
            :tone="toggling?.active ? 'warning' : 'success'"
            :icon="toggling?.active ? PowerOff : Power"
            :processing="toggleProcessing"
            @update:open="(value) => value || toggleProcessing || (toggling = null)"
            @confirm="confirmToggle"
        />
    </div>
</template>
