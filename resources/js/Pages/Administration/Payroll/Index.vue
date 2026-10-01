<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Ban, Banknote, Building2, CalendarDays, ChevronDown, ChevronLeft, ChevronRight, CircleAlert, CircleCheck, Download, FileSpreadsheet, FileText,
    HandCoins, Hourglass, Landmark, LayoutGrid, List, Rows3, Search, ShieldCheck, SlidersHorizontal, Wallet, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import PayrollGrid from '@/Components/Payroll/PayrollGrid.vue';
import PayrollTable from '@/Components/Payroll/PayrollTable.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/money';
import { hrContext, hrUrl } from '@/utilities/hrUrl';
import { monthLabel, shiftMonth } from '@/utilities/bonus';
import {
    PAYMENT_MODE_OPTIONS, PAYROLL_LAYOUT_KEY, PAYROLL_LAYOUTS, PAYROLL_VIEWS, filterPayrollRows, hasActiveFilters, netOf,
    payrollQuery, payrollViewCounts, serviceOptions,
} from '@/utilities/payrollBoard';

/**
 * ADR-227 / ADR-233 — la paie du mois : salaire de base + avantages du mois = brut ; les
 * retenues légales (CNAPS, organisme médical, IRSA, selon les paramètres de paie) et les
 * dettes du personnel (ADR-228) s'en retranchent = net à verser. Les charges patronales se
 * lisent pour information. « Marquer payé » (un salarié ou une sélection) fige la paie, ses
 * paramètres et son mode de paiement ; le virement se fait hors RIVO. Le serveur calcule
 * tout : l'écran ne recompte rien, il trie et filtre ce qui est servi.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    month: { type: String, required: true },
    currentMonth: { type: String, required: true },
    board: { type: Object, required: true },
    bulkLimit: { type: Number, default: 100 },
    filters: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const page = usePage();

// ADR-229 — les dettes du personnel se gèrent dans Finance, au portail.
const staffDebtsUrl = computed(() => {
    const context = hrContext();

    return context && can('staff_debts.view') ? `/super-admin/sites/${encodeURIComponent(context.site.code)}/finance/dettes?vue=en-cours` : null;
});

// — Vue, recherche et filtres : l'adresse les garde (on rouvre le même état). —
const state = ref({
    vue: props.filters.vue ?? 'toutes',
    q: props.filters.q ?? '',
    service: props.filters.service ?? '',
    mode: props.filters.mode ?? '',
    dettes: Boolean(props.filters.dettes),
});
const rows = computed(() => filterPayrollRows(props.board.rows, state.value));
const counts = computed(() => payrollViewCounts(props.board.rows, state.value));
const services = computed(() => serviceOptions(props.board.rows));
const filtersActive = computed(() => hasActiveFilters(state.value));
const resetFilters = () => { state.value = { ...state.value, q: '', service: '', mode: '', dettes: false }; };
const setView = (vue) => { state.value = { ...state.value, vue }; };

onMounted(() => {
    // La présentation choisie reste sur le poste ; lue après l'hydratation (ADR-115).
    try {
        const stored = window.localStorage.getItem(PAYROLL_LAYOUT_KEY);
        if (PAYROLL_LAYOUTS.includes(stored)) layout.value = stored;
    } catch { /* stockage indisponible : tableau */ }
});
watch(state, (value) => {
    if (typeof window === 'undefined') return;
    window.history.replaceState(window.history.state, '', `${window.location.pathname}?${payrollQuery(props.month, value)}`);
}, { deep: true });

const goTo = (month) => router.get(window.location.pathname, Object.fromEntries(new URLSearchParams(payrollQuery(month, state.value))), { preserveScroll: true });

// — Présentation : tableau, grille, détail. —
const layout = ref('table');
const LAYOUTS = [
    { key: 'table', label: 'Tableau', icon: List },
    { key: 'grid', label: 'Grille', icon: LayoutGrid },
    { key: 'detail', label: 'Détail', icon: Rows3 },
];
const setLayout = (value) => {
    layout.value = value;
    try { window.localStorage.setItem(PAYROLL_LAYOUT_KEY, value); } catch { /* sans effet */ }
};
const expanded = ref([]);
const toggleExpanded = (uuid) => {
    expanded.value = expanded.value.includes(uuid) ? expanded.value.filter((item) => item !== uuid) : [...expanded.value, uuid];
};

// — Chiffres du mois ; les cartes « À payer » et « Payées » ouvrent leur vue. —
const summary = computed(() => props.board.summary);
const cards = computed(() => [
    { key: 'to_pay', view: 'a-payer', icon: Hourglass, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', value: summary.value.to_pay, label: 'À payer', hint: `${formatMoney(summary.value.amount_to_pay)} net à verser` },
    { key: 'gross', icon: Wallet, tone: 'bg-primary/10 text-primary', value: formatMoney(summary.value.gross_to_pay), label: 'Brut à payer', hint: `dont ${formatMoney(summary.value.advantages_to_pay)} d’avantages` },
    { key: 'legal', icon: ShieldCheck, tone: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300', value: formatMoney(summary.value.legal_to_pay), label: 'Retenues légales', hint: props.board.settings.legal_enabled ? 'CNAPS, organisme médical, IRSA' : 'Non activées' },
    { key: 'debts', icon: Landmark, tone: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300', value: formatMoney(summary.value.deductions_to_pay ?? 0), label: 'Retenues de dettes', hint: 'Sur les paies à payer' },
    { key: 'employer', icon: Building2, tone: 'bg-muted text-muted-foreground', value: formatMoney(summary.value.employer_to_pay), label: 'Charges patronales', hint: `Coût du mois ${formatMoney(summary.value.cost_month)}` },
    { key: 'paid', view: 'payees', icon: CircleCheck, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', value: summary.value.paid, label: 'Payées', hint: `${formatMoney(summary.value.amount_paid)} versés` },
]);

// — Sélection : payer, imprimer les bulletins, exporter. Une sélection ne survit ni à un changement de mois ni de filtre. —
const selected = ref([]);
watch(() => [props.month, props.board.rows.length], () => { selected.value = []; });
watch(state, () => { selected.value = selected.value.filter((uuid) => rows.value.some((row) => row.uuid === uuid)); }, { deep: true });
const shownPayable = computed(() => rows.value.filter((row) => row.payable));
const selectedRows = computed(() => props.board.rows.filter((row) => selected.value.includes(row.uuid)));
const selectedPayable = computed(() => selectedRows.value.filter((row) => row.payable));
const selectedNet = computed(() => netOf(selectedPayable.value));
const toggle = (uuid, value) => { selected.value = value ? [...new Set([...selected.value, uuid])] : selected.value.filter((item) => item !== uuid); };
const toggleAllShown = (value) => { selected.value = value ? rows.value.map((row) => row.uuid).slice(0, props.bulkLimit) : []; };
const selectShownPayable = () => { selected.value = shownPayable.value.map((row) => row.uuid).slice(0, props.bulkLimit); };

const withUuids = (params, uuids) => {
    for (const uuid of uuids) params.append('uuids[]', uuid);

    return params;
};
const payslipsUrl = (uuids) => hrUrl(`/administration/paie/bulletins?${withUuids(new URLSearchParams({ mois: props.month }), uuids)}`);
const exportUrl = (type, uuids = []) => hrUrl(`/administration/paie/export?${withUuids(new URLSearchParams({ mois: props.month, type }), uuids)}`);
const openPayslips = (uuids) => window.location.assign(payslipsUrl(uuids));

// Exporter : ce qui est affiché (vue et filtres), jamais plus.
const exportItems = computed(() => {
    const shown = rows.value.map((row) => row.uuid);
    const items = [{ key: 'payslips', label: `Bulletins affichés (${shown.length})`, icon: FileText, disabled: ! shown.length, description: 'Un bulletin par page, à imprimer' }];
    if (can('salary_payments.export')) {
        items.push(
            { key: 'journal', label: 'Journal de paie', icon: FileSpreadsheet, disabled: ! shown.length, description: 'Excel : brut, retenues, net par personne', separatorBefore: true },
            { key: 'virements', label: 'Liste de virement', icon: Building2, disabled: ! shown.length, description: 'Excel : par mode de paiement et banque' },
        );
    }

    return items;
});
const onExport = (key) => {
    const shown = rows.value.map((row) => row.uuid).slice(0, props.bulkLimit);
    if (key === 'payslips') openPayslips(shown);
    else window.location.assign(exportUrl(key, shown));
};
const tooManyShown = computed(() => rows.value.length > props.bulkLimit);

// — Payer, annuler. —
const pending = ref(null);
const form = useForm({ note: '', reason: '' });
const open = (mode, row = null) => {
    form.reset();
    form.clearErrors();
    pending.value = { mode, row };
};
const confirm = () => {
    const { mode, row } = pending.value;
    const options = { preserveScroll: true, onSuccess: () => { pending.value = null; if (mode === 'batch') selected.value = []; } };
    if (mode === 'pay') {
        form.transform((data) => ({ employee_uuid: row.uuid, mois: props.month, note: data.note })).post(hrUrl('/administration/paie/payer'), options);
    } else if (mode === 'batch') {
        form.transform((data) => ({ employee_uuids: selectedPayable.value.map((item) => item.uuid), mois: props.month, note: data.note })).post(hrUrl('/administration/paie/payer-lot'), options);
    } else {
        form.transform((data) => ({ reason: data.reason })).post(hrUrl(`/administration/paie/${row.payment.uuid}/annuler`), options);
    }
};
const error = computed(() => Object.values(form.errors)[0] ?? '');
const modal = computed(() => {
    const mode = pending.value?.mode;
    if (mode === 'cancel') return { title: 'Annuler la paie', confirm: 'Annuler la paie', tone: 'danger', icon: Ban };
    if (mode === 'batch') return { title: `Marquer ${selectedPayable.value.length} paies payées`, confirm: 'Marquer payées', tone: 'success', icon: Banknote };

    return { title: 'Marquer la paie payée', confirm: 'Marquer payé', tone: 'success', icon: Banknote };
});

// Rapport d'une paie en lot : ce qui est passé, ce qui ne l'est pas, et pourquoi.
const report = computed(() => (page.props.flash?.bulk_report?.action === 'payroll_pay' ? page.props.flash.bulk_report : null));
const reportHidden = ref(false);
watch(report, () => { reportHidden.value = false; });

const viewProps = computed(() => ({
    rows: rows.value,
    selected: selected.value,
    expanded: expanded.value,
    legalEnabled: Boolean(props.board.settings.legal_enabled),
    canPay: can('salary_payments.pay'),
    canCancel: can('salary_payments.cancel'),
}));
const emptyText = computed(() => {
    if (filtersActive.value) return 'Aucune paie ne correspond à la recherche ou aux filtres.';
    if (state.value.vue === 'a-payer') return `Plus rien à payer pour ${monthLabel(props.month)}.`;
    if (state.value.vue === 'payees') return `Aucune paie encore marquée payée pour ${monthLabel(props.month)}.`;

    return '';
});
</script>

<template>
    <Head title="Paie du mois" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines · Pilotage"
            title="Paie du mois"
            description="Brut (salaire + avantages) − retenues légales − dettes = net à verser. Marquer payé fige la paie, ses paramètres et son mode de paiement ; le virement se fait hors RIVO."
            :icon="Banknote"
        >
            <template #actions>
                <Button v-if="can('salary_settings.view')" :as="Link" :href="hrUrl('/administration/paie/parametres')" variant="outline"><SlidersHorizontal class="h-4 w-4" />Paramètres</Button>
                <Button v-if="staffDebtsUrl" :as="Link" :href="staffDebtsUrl" variant="outline"><Landmark class="h-4 w-4" />Dettes</Button>
                <Button v-if="can('advantage_entries.view')" :as="Link" :href="hrUrl(`/administration/bonus?onglet=saisis&mois=${month}`)" variant="outline"><HandCoins class="h-4 w-4" />Avantages</Button>
                <DropdownMenu v-if="board.rows.length" :items="exportItems" label="Exporter ce qui est affiché" @select="onExport">
                    <template #trigger>
                        <Button type="button"><Download class="h-4 w-4" />Exporter<ChevronDown class="h-4 w-4" /></Button>
                    </template>
                </DropdownMenu>
            </template>
        </PageHeader>

        <div v-if="! board.settings.legal_enabled" class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            <CircleAlert class="h-5 w-5 shrink-0" />
            <p class="min-w-0 flex-1">
                <strong>Retenues légales non activées</strong> : le net est le brut moins les dettes, sans CNAPS, {{ board.settings.health_label }} ni IRSA.
                <template v-if="! board.settings.configured"> Un barème Madagascar est proposé : faites-le vérifier, puis activez-le.</template>
            </p>
            <Button v-if="can('salary_settings.view')" :as="Link" :href="hrUrl('/administration/paie/parametres')" size="sm" variant="outline"><SlidersHorizontal class="h-4 w-4" />Paramètres de paie</Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <component
                :is="card.view ? 'button' : 'div'"
                v-for="card in cards"
                :key="card.key"
                :type="card.view ? 'button' : undefined"
                :aria-pressed="card.view ? state.vue === card.view : undefined"
                :class="cn(
                    'flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-left shadow-sm',
                    card.view && 'transition-colors hover:border-primary/40 hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30',
                    card.view && state.vue === card.view && 'border-primary/60 ring-1 ring-primary/30',
                )"
                @click="card.view && setView(state.vue === card.view ? 'toutes' : card.view)"
            >
                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone]"><component :is="card.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-xl font-bold leading-none tabular-nums text-foreground">{{ card.value }}</span>
                    <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                    <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                </span>
            </component>
        </div>

        <div v-if="report && ! reportHidden" class="flex items-start gap-3 rounded-lg border border-border bg-card px-4 py-3 shadow-sm" role="status">
            <component :is="report.failed.length ? CircleAlert : CircleCheck" :class="['mt-0.5 h-5 w-5 shrink-0', report.failed.length ? 'text-amber-600' : 'text-emerald-600']" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-foreground">{{ report.done }} sur {{ report.total }} paie{{ report.total > 1 ? 's' : '' }} marquée{{ report.done > 1 ? 's' : '' }} payée{{ report.done > 1 ? 's' : '' }} · {{ formatMoney(report.amount) }} à verser</p>
                <ul v-if="report.failed.length" class="mt-2 space-y-1 text-sm">
                    <li v-for="(failure, index) in report.failed" :key="index" class="text-muted-foreground"><span class="font-medium text-foreground">{{ failure.label }}</span> — {{ failure.message }}</li>
                </ul>
            </div>
            <Button type="button" size="icon-xs" variant="ghost" aria-label="Fermer le rapport" @click="reportHidden = true"><X class="h-4 w-4" /></Button>
        </div>

        <!-- Mois, vues, présentation ; puis recherche et filtres. -->
        <div class="space-y-3 rounded-xl border border-border bg-card p-3 shadow-sm">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1 rounded-lg border border-border bg-background p-1">
                    <Button type="button" variant="ghost" size="icon" aria-label="Mois précédent" @click="goTo(shiftMonth(month, -1))"><ChevronLeft class="h-4 w-4" /></Button>
                    <span class="flex min-w-40 items-center justify-center gap-2 px-2 text-sm font-semibold capitalize text-foreground">
                        <CalendarDays class="h-4 w-4 text-muted-foreground" />{{ monthLabel(month) }}
                    </span>
                    <Button type="button" variant="ghost" size="icon" aria-label="Mois suivant" :disabled="month >= currentMonth" @click="goTo(shiftMonth(month, 1))"><ChevronRight class="h-4 w-4" /></Button>
                </div>

                <div class="inline-flex rounded-lg border border-border bg-muted/50 p-1" role="tablist" aria-label="Statut de la paie">
                    <button
                        v-for="view in PAYROLL_VIEWS"
                        :key="view.key"
                        type="button"
                        role="tab"
                        :aria-selected="state.vue === view.key"
                        :class="cn(
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground',
                            state.vue === view.key && 'bg-card text-foreground shadow-sm',
                        )"
                        @click="setView(view.key)"
                    >
                        {{ view.label }}
                        <span :class="cn('rounded-full px-1.5 text-[11px] tabular-nums', view.key === 'a-payer' && counts[view.key] > 0 ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200' : 'bg-muted text-muted-foreground')">{{ counts[view.key] }}</span>
                    </button>
                </div>

                <div class="ms-auto inline-flex rounded-lg border border-border bg-muted/50 p-1" role="group" aria-label="Présentation">
                    <Button
                        v-for="option in LAYOUTS"
                        :key="option.key"
                        type="button"
                        size="sm"
                        variant="ghost"
                        :class="cn('gap-1.5 px-2.5', layout === option.key && 'bg-card text-foreground shadow-sm hover:bg-card')"
                        :aria-pressed="layout === option.key"
                        :title="option.label"
                        @click="setLayout(option.key)"
                    ><component :is="option.icon" class="h-4 w-4" /><span class="hidden sm:inline">{{ option.label }}</span></Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <IconInput v-model="state.q" :icon="Search" type="search" class="w-full sm:w-72" placeholder="Nom, matricule, fonction…" aria-label="Rechercher un salarié" />
                <Select v-model="state.service" :options="[{ value: '', label: 'Tous les services' }, ...services]" placeholder="Tous les services" class="w-full sm:w-52" aria-label="Filtrer par service" />
                <Select v-model="state.mode" :options="[{ value: '', label: 'Tous les modes' }, ...PAYMENT_MODE_OPTIONS]" placeholder="Tous les modes" class="w-full sm:w-52" aria-label="Filtrer par mode de paiement" />
                <label class="flex h-[var(--control-h)] items-center gap-2 rounded-lg border border-border px-3 text-sm text-foreground">
                    <Switch v-model="state.dettes" aria-label="Seulement les paies avec une retenue de dette" />
                    Avec retenue de dette
                </label>
                <Button v-if="filtersActive" type="button" variant="ghost" size="sm" @click="resetFilters"><X class="h-4 w-4" />Effacer les filtres</Button>
                <span class="ms-auto text-xs text-muted-foreground">{{ rows.length }} sur {{ board.rows.length }} · {{ formatMoney(netOf(rows)) }} net</span>
            </div>
        </div>

        <!-- Barre de sélection : visible dès qu'une paie est cochée. -->
        <div v-if="selected.length || shownPayable.length" class="sticky top-0 z-10 flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card/95 px-4 py-2.5 shadow-sm backdrop-blur">
            <template v-if="selected.length">
                <span class="text-sm font-medium text-foreground">{{ selected.length }} sélectionnée{{ selected.length > 1 ? 's' : '' }}<template v-if="selectedPayable.length"> · {{ formatMoney(selectedNet) }} net à verser</template></span>
                <div class="ms-auto flex flex-wrap gap-2">
                    <Button v-if="can('salary_payments.pay') && selectedPayable.length" type="button" size="sm" variant="success" @click="open('batch')"><Banknote class="h-4 w-4" />Marquer payées ({{ selectedPayable.length }})</Button>
                    <Button type="button" size="sm" variant="outline" @click="openPayslips(selected)"><FileText class="h-4 w-4" />Bulletins ({{ selected.length }})</Button>
                    <Button v-if="can('salary_payments.export')" :as="'a'" :href="exportUrl('journal', selected)" size="sm" variant="outline"><FileSpreadsheet class="h-4 w-4" />Journal</Button>
                    <Button v-if="can('salary_payments.export')" :as="'a'" :href="exportUrl('virements', selected)" size="sm" variant="outline"><Building2 class="h-4 w-4" />Virements</Button>
                    <Button type="button" size="sm" variant="ghost" @click="selected = []">Désélectionner</Button>
                </div>
            </template>
            <template v-else>
                <span class="text-sm text-muted-foreground">{{ shownPayable.length }} paie{{ shownPayable.length > 1 ? 's' : '' }} à payer affichée{{ shownPayable.length > 1 ? 's' : '' }} · {{ formatMoney(netOf(shownPayable)) }} net</span>
                <Button class="ms-auto" type="button" size="sm" variant="outline" @click="selectShownPayable"><CircleCheck class="h-4 w-4" />Sélectionner les paies à payer</Button>
            </template>
        </div>
        <p v-if="tooManyShown" class="text-xs text-muted-foreground">Plus de {{ bulkLimit }} paies affichées : la sélection, les bulletins et les exports s’arrêtent aux {{ bulkLimit }} premières. Filtrez pour réduire la liste.</p>

        <Card v-if="! board.rows.length" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><Banknote class="h-6 w-6" /></span>
            <p class="text-sm font-semibold text-foreground">Rien à payer pour {{ monthLabel(month) }}</p>
            <p class="max-w-md text-sm text-muted-foreground">La paie liste les personnes en poste dont la rémunération a un montant (étape Rémunération du dossier), et celles qui ont des avantages ce mois-ci. Un salarié n’est pas payé pour un mois qui précède son entrée.</p>
        </Card>

        <Card v-else-if="! rows.length" class="flex flex-col items-center gap-3 px-6 py-10 text-center">
            <Search class="h-8 w-8 text-muted-foreground/60" />
            <p class="text-sm text-muted-foreground">{{ emptyText }}</p>
            <div class="flex gap-2">
                <Button v-if="filtersActive" type="button" size="sm" variant="outline" @click="resetFilters">Effacer les filtres</Button>
                <Button v-if="state.vue !== 'toutes'" type="button" size="sm" variant="outline" @click="setView('toutes')">Voir toutes les paies</Button>
            </div>
        </Card>

        <PayrollTable
            v-else-if="layout === 'table'"
            v-bind="viewProps"
            @toggle="toggle"
            @toggle-all="toggleAllShown"
            @expand="toggleExpanded"
            @pay="(row) => open('pay', row)"
            @cancel="(row) => open('cancel', row)"
            @payslip="(row) => openPayslips([row.uuid])"
        />
        <PayrollGrid
            v-else
            v-bind="viewProps"
            :detailed="layout === 'detail'"
            @toggle="toggle"
            @expand="toggleExpanded"
            @pay="(row) => open('pay', row)"
            @cancel="(row) => open('cancel', row)"
            @payslip="(row) => openPayslips([row.uuid])"
        />

        <ConfirmModal
            :open="pending !== null"
            :title="modal.title"
            :description="pending?.row ? `${pending.row.name} · ${monthLabel(month)}` : monthLabel(month)"
            :confirm-label="modal.confirm"
            :tone="modal.tone"
            :icon="modal.icon"
            :processing="form.processing"
            :disabled="pending?.mode === 'cancel' && ! form.reason.trim()"
            :dismissible="false"
            @update:open="(value) => value || form.processing || (pending = null)"
            @confirm="confirm"
        >
            <div v-if="pending" class="space-y-4 text-sm">
                <template v-if="pending.mode === 'pay'">
                    <dl class="divide-y divide-border rounded-lg border border-border">
                        <div class="flex justify-between px-3 py-1.5"><dt>Brut</dt><dd class="tabular-nums">{{ formatMoney(pending.row.gross) }}</dd></div>
                        <div v-if="Number(pending.row.legal_amount) > 0" class="flex justify-between px-3 py-1.5"><dt>Retenues légales</dt><dd class="tabular-nums text-rose-700 dark:text-rose-300">− {{ formatMoney(pending.row.legal_amount) }}</dd></div>
                        <div v-if="Number(pending.row.debts_amount) > 0" class="flex justify-between px-3 py-1.5"><dt>Retenues de dettes</dt><dd class="tabular-nums text-rose-700 dark:text-rose-300">− {{ formatMoney(pending.row.debts_amount) }}</dd></div>
                        <div class="flex justify-between bg-primary/5 px-3 py-2 font-semibold"><dt>Net à verser</dt><dd class="tabular-nums text-primary">{{ formatMoney(pending.row.total) }}</dd></div>
                        <div class="flex justify-between px-3 py-1.5 text-xs text-muted-foreground"><dt>Mode</dt><dd>{{ pending.row.payment_mode.label }} · {{ pending.row.payment_mode.summary }}</dd></div>
                    </dl>
                    <p class="text-muted-foreground">Le serveur recompte et fige les lignes, les paramètres de paie et le mode de paiement ; les avantages portés passent « Payé »<template v-if="Number(pending.row.debts_amount) > 0"> et chaque retenue devient un remboursement de la dette</template>.</p>
                </template>
                <template v-else-if="pending.mode === 'batch'">
                    <p class="text-foreground"><strong>{{ selectedPayable.length }}</strong> paie{{ selectedPayable.length > 1 ? 's' : '' }} · <strong class="tabular-nums">{{ formatMoney(selectedNet) }}</strong> net à verser.</p>
                    <p class="text-muted-foreground">Chaque paie est recomptée et figée séparément : un refus n’empêche pas les autres, et le rapport dit pourquoi.</p>
                </template>
                <template v-else>
                    <p class="text-muted-foreground">La paie reste dans l’historique, marquée annulée ; ses avantages repassent en attente et ses retenues de dettes sont annulées (la dette redevient due d’autant).</p>
                    <FormField label="Motif" required>
                        <Textarea v-model="form.reason" :rows="2" maxlength="1000" placeholder="Pourquoi cette paie est annulée" />
                    </FormField>
                </template>
                <FormField v-if="pending.mode !== 'cancel'" label="Note" hint="(facultatif)">
                    <Textarea v-model="form.note" :rows="2" maxlength="500" placeholder="Ex. virement BOA du 30/09" />
                </FormField>
                <p v-if="error" class="text-sm font-medium text-destructive">{{ error }}</p>
            </div>
        </ConfirmModal>
    </div>
</template>
