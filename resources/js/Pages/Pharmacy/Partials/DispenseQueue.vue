<script setup>
import { computed, ref } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

const props = defineProps({
    dispenses: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    capabilities: { type: Object, required: true },
});

const emit = defineEmits(['prepare-invoice', 'deliver']);

const search = ref('');
const statusFilter = ref('ALL');
const typeFilter = ref('ALL');
const expanded = ref(new Set());

const statusFilters = computed(() => [
    { value: 'ALL', label: 'Toutes', count: props.dispenses.length },
    { value: 'READY', label: 'À délivrer', count: props.summary.ready ?? 0 },
    { value: 'AWAITING_PAYMENT', label: 'Attente Caisse', count: props.summary.awaiting_payment ?? 0 },
    { value: 'AWAITING_INVOICE', label: 'À facturer', count: props.summary.awaiting_invoice ?? 0 },
    { value: 'PARTIALLY_DISPENSED', label: 'Partielles', count: props.dispenses.filter((item) => item.status === 'PARTIALLY_DISPENSED').length },
]);

const filteredDispenses = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return props.dispenses.filter((dispense) => {
        const matchesStatus = statusFilter.value === 'ALL'
            || (statusFilter.value === 'READY'
                ? ['READY', 'PARTIALLY_DISPENSED'].includes(dispense.status)
                : dispense.status === statusFilter.value);
        const matchesType = typeFilter.value === 'ALL' || dispense.type === typeFilter.value;
        const matchesSearch = !needle || [
            dispense.customer_name,
            dispense.customer_phone,
            dispense.customer_number,
            dispense.episode_number,
            dispense.invoice?.number,
            dispense.prescriber,
            dispense.external_prescription_reference,
            ...dispense.lines.map((line) => line.medicine_name),
        ].filter(Boolean).some((value) => String(value).toLocaleLowerCase().includes(needle));

        return matchesStatus && matchesType && matchesSearch;
    });
});

const statusPresentation = (status) => ({
    AWAITING_INVOICE: { label: 'Facture à préparer', style: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-300', dot: 'bg-sky-500' },
    AWAITING_PAYMENT: { label: 'En attente de la Caisse', style: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300', dot: 'bg-amber-500' },
    READY: { label: 'Payée · prête à délivrer', style: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-300', dot: 'bg-emerald-500' },
    PARTIALLY_DISPENSED: { label: 'Délivrance partielle', style: 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/20 dark:text-primary-300', dot: 'bg-primary-500' },
}[status] ?? { label: status, style: 'border-gray-200 bg-gray-50 text-slate-600', dot: 'bg-slate-400' });

const toggle = (uuid) => {
    const next = new Set(expanded.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    expanded.value = next;
};

const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—';
const formatMoney = (value) => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(Number(value || 0))} MGA`;
</script>

<template>
    <div>
        <div class="grid border-b border-gray-200 bg-gray-50/70 dark:border-gray-900 dark:bg-gray-1000 sm:grid-cols-3">
            <button type="button" class="border-b border-gray-200 px-5 py-4 text-start transition hover:bg-sky-50 dark:border-gray-900 dark:hover:bg-sky-950/10 sm:border-b-0 sm:border-e" @click="statusFilter = 'AWAITING_INVOICE'">
                <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">À facturer</span>
                <span class="mt-1 flex items-end justify-between"><strong class="text-xl text-sky-600">{{ summary.awaiting_invoice ?? 0 }}</strong><Icon class="text-lg text-sky-300" name="file-text" /></span>
            </button>
            <button type="button" class="border-b border-gray-200 px-5 py-4 text-start transition hover:bg-amber-50 dark:border-gray-900 dark:hover:bg-amber-950/10 sm:border-b-0 sm:border-e" @click="statusFilter = 'AWAITING_PAYMENT'">
                <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">En attente Caisse</span>
                <span class="mt-1 flex items-end justify-between"><strong class="text-xl text-amber-600">{{ summary.awaiting_payment ?? 0 }}</strong><Icon class="text-lg text-amber-300" name="clock" /></span>
            </button>
            <button type="button" class="px-5 py-4 text-start transition hover:bg-emerald-50 dark:hover:bg-emerald-950/10" @click="statusFilter = 'READY'">
                <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Prêtes à délivrer</span>
                <span class="mt-1 flex items-end justify-between"><strong class="text-xl text-emerald-600">{{ summary.ready ?? 0 }}</strong><Icon class="text-lg text-emerald-300" name="check-circle" /></span>
            </button>
        </div>

        <div class="space-y-3 border-b border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950 sm:p-5">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex max-w-full gap-1 overflow-x-auto pb-1 xl:pb-0">
                    <button
                        v-for="filter in statusFilters"
                        :key="filter.value"
                        type="button"
                        :class="[
                            'inline-flex shrink-0 items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-bold transition',
                            statusFilter === filter.value ? 'border-slate-700 bg-slate-700 text-white' : 'border-gray-200 bg-white text-slate-500 hover:border-slate-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300',
                        ]"
                        @click="statusFilter = filter.value"
                    >
                        {{ filter.label }} <span :class="['rounded-full px-1.5 py-0.5 text-[10px]', statusFilter === filter.value ? 'bg-white/20' : 'bg-gray-100 dark:bg-gray-900']">{{ filter.count }}</span>
                    </button>
                </div>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <label class="relative block flex-1">
                    <span class="sr-only">Rechercher une demande</span>
                    <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" />
                    <input v-model="search" type="search" class="h-10 w-full rounded-lg border border-gray-200 bg-gray-50 ps-10 pe-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-900 dark:text-white" placeholder="Client, patient, facture, ordonnance ou médicament…">
                </label>
                <select v-model="typeFilter" class="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-600 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 sm:w-52">
                    <option value="ALL">Tous les parcours</option>
                    <option value="EXTERNAL">Clients externes</option>
                    <option value="INTERNAL">Patients internes</option>
                </select>
            </div>
        </div>

        <div class="space-y-2 bg-gray-50/60 p-3 dark:bg-gray-1000 sm:p-4">
            <div class="hidden grid-cols-[minmax(230px,1.7fr)_minmax(90px,.65fr)_minmax(110px,.75fr)_minmax(135px,.9fr)_minmax(210px,1.35fr)] gap-3 px-3 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-400 lg:grid">
                <span>Demande</span>
                <span>Facture</span>
                <span>Montant</span>
                <span>Contrôle Caisse</span>
                <span class="text-end">Statut et actions</span>
            </div>

            <article
                v-for="dispense in filteredDispenses"
                :key="dispense.uuid"
                :class="[
                    'overflow-hidden rounded-lg border bg-white transition dark:bg-gray-950',
                    dispense.can_dispense ? 'border-emerald-300 dark:border-emerald-900' : 'border-gray-200 dark:border-gray-800',
                ]"
            >
                <div class="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-[minmax(230px,1.7fr)_minmax(90px,.65fr)_minmax(110px,.75fr)_minmax(135px,.9fr)_minmax(210px,1.35fr)] lg:items-center">
                    <button type="button" class="flex min-w-0 items-center gap-3 text-start sm:col-span-2 lg:col-span-1" @click="toggle(dispense.uuid)">
                            <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', dispense.type === 'EXTERNAL' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-primary-50 text-primary-600 dark:bg-primary-950/30 dark:text-primary-300']"><Icon :name="dispense.type === 'EXTERNAL' ? 'user' : 'file-docs'" /></span>
                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-2">
                                    <strong class="truncate text-sm text-slate-700 dark:text-white">{{ dispense.customer_name }}</strong>
                                    <span :class="['rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase', dispense.type === 'EXTERNAL' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300']">{{ dispense.type === 'EXTERNAL' ? 'Client externe' : 'Patient interne' }}</span>
                                </span>
                                <span class="mt-0.5 block truncate text-[11px] text-slate-500">
                                    <template v-if="dispense.customer_number">{{ dispense.customer_number }} · </template>
                                    <template v-if="dispense.episode_number">Passage {{ dispense.episode_number }} · </template>
                                    {{ dispense.line_count }} ligne(s) · {{ dispense.remaining_quantity }} unité(s) restante(s)
                                </span>
                                <span class="mt-0.5 block truncate text-[10px] text-slate-400">{{ formatDate(dispense.requested_at) }}<template v-if="dispense.prescriber"> · Prescripteur {{ dispense.prescriber }}</template></span>
                            </span>
                    </button>

                    <div class="min-w-0">
                            <span class="mb-0.5 block text-[9px] font-bold uppercase tracking-wide text-slate-400 lg:hidden">Facture</span>
                            <span class="block truncate text-xs font-bold text-slate-700 dark:text-white">{{ dispense.invoice?.number ?? 'À préparer' }}</span>
                    </div>

                    <div class="min-w-0">
                            <span class="mb-0.5 block text-[9px] font-bold uppercase tracking-wide text-slate-400 lg:hidden">Montant</span>
                            <span class="block truncate text-xs font-bold text-slate-700 dark:text-white">{{ dispense.invoice ? formatMoney(dispense.invoice.total_amount) : '—' }}</span>
                    </div>

                    <div class="min-w-0">
                            <span class="mb-0.5 block text-[9px] font-bold uppercase tracking-wide text-slate-400 lg:hidden">Contrôle Caisse</span>
                            <span :class="['block text-xs font-bold', dispense.can_dispense ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-500']">{{ dispense.can_dispense ? 'Règlement confirmé' : (dispense.invoice ? 'Délivrance verrouillée' : 'Facture non préparée') }}</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-1 lg:justify-end">
                            <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2 py-1 text-[10px] font-bold', statusPresentation(dispense.status).style]"><span :class="['h-1.5 w-1.5 rounded-full', statusPresentation(dispense.status).dot]" />{{ statusPresentation(dispense.status).label }}</span>
                            <Button v-if="dispense.can_prepare_invoice && capabilities.can_prepare_invoice" size="sm" type="button" @click="emit('prepare-invoice', dispense)"><Icon name="file-text" /><span class="ms-1.5">Préparer la facture</span></Button>
                            <Button v-if="dispense.can_dispense && capabilities.can_dispense" size="sm" type="button" @click="emit('deliver', dispense)"><Icon name="package" /><span class="ms-1.5">Ouvrir la délivrance</span></Button>
                            <button type="button" class="ms-auto flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-gray-200 text-slate-500 hover:border-slate-300 dark:border-gray-800 dark:hover:text-white lg:ms-0" :aria-label="expanded.has(dispense.uuid) ? 'Masquer le détail' : 'Afficher le détail'" @click="toggle(dispense.uuid)"><Icon :name="expanded.has(dispense.uuid) ? 'chevron-up' : 'chevron-down'" /></button>
                    </div>
                </div>

                <div v-if="expanded.has(dispense.uuid)" class="border-t border-gray-200 bg-gray-50/70 p-4 dark:border-gray-900 dark:bg-gray-1000 sm:p-5">
                    <div v-if="dispense.status === 'AWAITING_PAYMENT'" class="mb-3 flex items-start gap-2 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200"><Icon class="mt-0.5 shrink-0" name="info" /><span>La demande est préparée. Le client doit régler à la Caisse avant toute sortie physique.</span></div>
                    <div v-else-if="dispense.can_dispense" class="mb-3 flex items-start gap-2 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-200"><Icon class="mt-0.5 shrink-0" name="check-circle" /><span>La Caisse a confirmé le règlement ou la prise en charge. La délivrance FEFO est autorisée.</span></div>
                    <div v-if="dispense.type === 'EXTERNAL' && (dispense.customer_phone || dispense.external_prescription_reference)" class="mb-3 flex flex-wrap gap-x-5 gap-y-1 rounded-lg border border-emerald-100 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-200">
                        <span v-if="dispense.customer_phone"><strong>Téléphone :</strong> {{ dispense.customer_phone }}</span>
                        <span v-if="dispense.external_prescription_reference"><strong>Ordonnance :</strong> {{ dispense.external_prescription_reference }}</span>
                    </div>
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
                        <div v-for="line in dispense.lines" :key="line.id" class="border-b border-gray-100 p-3 last:border-0 dark:border-gray-900 sm:px-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold text-slate-700 dark:text-white">{{ line.medicine_name }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ line.medicine_code }} · demandé {{ line.quantity_requested }} {{ line.unit }} · déjà délivré {{ line.quantity_dispensed }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-bold text-primary-700 dark:bg-primary-950/30 dark:text-primary-300">Reste {{ line.remaining_quantity }}</span>
                            </div>
                            <div v-if="capabilities.can_view_lots && line.lots.length" class="mt-2 flex flex-wrap gap-1.5">
                                <span v-for="(lot, index) in line.lots" :key="lot.uuid" class="rounded border border-gray-200 bg-white px-2 py-1 text-[10px] text-slate-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300"><strong class="text-slate-700 dark:text-white">{{ index + 1 }}. Lot {{ lot.lot_number }}</strong> · {{ lot.quantity }}<template v-if="capabilities.can_view_expiration"> · exp. {{ lot.expires_at }}</template></span>
                            </div>
                        </div>
                    </div>
                </div>

            </article>

            <div v-if="!filteredDispenses.length" class="rounded-xl border border-dashed border-gray-300 bg-white px-5 py-14 text-center dark:border-gray-800 dark:bg-gray-950">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-xl text-emerald-500 dark:bg-emerald-950/30"><Icon name="check-circle" /></span>
                <p class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Aucune demande pour ce filtre</p>
                <p class="mt-1 text-xs text-slate-400">Les patients internes et clients externes apparaissent ici selon leur étape.</p>
            </div>
        </div>
    </div>
</template>
