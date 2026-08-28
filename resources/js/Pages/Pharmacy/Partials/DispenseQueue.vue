<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
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
const selectedDispense = ref(null);
const printingDispenseUuid = ref(null);
let ticketPrintFrame = null;
let ticketPrintCleanupTimer = null;

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

const showDispense = (dispense) => {
    selectedDispense.value = dispense;
};

const closeDispense = () => {
    selectedDispense.value = null;
};

const deliverDispense = (dispense) => {
    closeDispense();
    emit('deliver', dispense);
};

const clearTicketPrintFrame = () => {
    if (ticketPrintCleanupTimer) window.clearTimeout(ticketPrintCleanupTimer);
    ticketPrintCleanupTimer = null;
    ticketPrintFrame?.remove();
    ticketPrintFrame = null;
    printingDispenseUuid.value = null;
};

const printTicket = (dispense) => {
    if (!dispense?.invoice || !props.capabilities.can_print_ticket) return;

    clearTicketPrintFrame();
    printingDispenseUuid.value = dispense.uuid;

    const frame = document.createElement('iframe');
    frame.title = `Impression du ticket ${dispense.invoice.number}`;
    frame.setAttribute('aria-hidden', 'true');
    Object.assign(frame.style, {
        position: 'fixed',
        insetInlineStart: '-10000px',
        bottom: '0',
        width: '1px',
        height: '1px',
        border: '0',
        opacity: '0',
        pointerEvents: 'none',
    });
    frame.addEventListener('load', () => {
        frame.contentWindow?.addEventListener('afterprint', clearTicketPrintFrame, { once: true });
    }, { once: true });
    frame.src = `/pharmacy/dispenses/${encodeURIComponent(dispense.uuid)}/ticket?print=1&embedded=1`;
    document.body.appendChild(frame);
    ticketPrintFrame = frame;
    ticketPrintCleanupTimer = window.setTimeout(clearTicketPrintFrame, 120000);
};

onBeforeUnmount(clearTicketPrintFrame);

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

        <div class="overflow-x-auto bg-white dark:bg-gray-950">
            <table class="w-full min-w-[980px] border-collapse">
                <thead class="bg-gray-50/70 text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40">
                    <tr>
                        <th class="px-5 py-3 text-start">Demande</th>
                        <th class="px-4 py-3 text-start">Ordonnance / produits</th>
                        <th class="px-4 py-3 text-start">Ticket</th>
                        <th class="px-4 py-3 text-start">Statut</th>
                        <th class="px-5 py-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                    <tr v-for="dispense in filteredDispenses" :key="dispense.uuid" class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000/30">
                        <td class="px-5 py-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', dispense.type === 'EXTERNAL' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-primary-50 text-primary-600 dark:bg-primary-950/30 dark:text-primary-300']"><Icon :name="dispense.type === 'EXTERNAL' ? 'user' : 'file-docs'" /></span>
                                <div class="min-w-0">
                                    <p class="max-w-60 truncate text-sm font-bold text-slate-700 dark:text-white">{{ dispense.customer_name }}</p>
                                    <p class="mt-0.5 truncate text-[11px] text-slate-400"><template v-if="dispense.customer_number">{{ dispense.customer_number }}</template><template v-else>{{ dispense.type === 'EXTERNAL' ? 'Client externe' : 'Patient interne' }}</template><template v-if="dispense.episode_number"> · {{ dispense.episode_number }}</template></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-xs font-bold text-slate-700 dark:text-white">{{ dispense.line_count }} produit{{ dispense.line_count > 1 ? 's' : '' }}</p>
                            <p class="mt-0.5 max-w-56 truncate text-[11px] text-slate-400">{{ dispense.prescriber ? `Prescripteur : ${dispense.prescriber}` : `${dispense.remaining_quantity} unité(s) restante(s)` }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs font-bold text-slate-700 dark:text-white">{{ dispense.invoice?.number ?? 'À préparer' }}</p>
                            <p class="mt-0.5 text-[11px] text-slate-400">{{ dispense.invoice ? formatMoney(dispense.invoice.total_amount) : formatDate(dispense.requested_at) }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2 py-1 text-[10px] font-bold', statusPresentation(dispense.status).style]"><span :class="['h-1.5 w-1.5 rounded-full', statusPresentation(dispense.status).dot]" />{{ statusPresentation(dispense.status).label }}</span>
                            <p :class="['mt-1 text-[10px] font-medium', dispense.can_dispense ? 'text-emerald-600' : 'text-slate-400']">{{ dispense.can_dispense ? 'Règlement confirmé' : (dispense.invoice ? 'Contrôle Caisse requis' : 'Ticket non créé') }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Button size="rg" type="button" variant="white-outline" title="Voir les détails et les actions documentaires" :aria-label="`Voir les détails de ${dispense.customer_name}`" @click="showDispense(dispense)"><Icon class="text-base" name="eye" /><span class="ms-2">Voir</span></Button>
                                <Button v-if="dispense.can_prepare_invoice && capabilities.can_prepare_invoice" icon size="rg" type="button" title="Préparer le ticket" aria-label="Préparer le ticket" @click="emit('prepare-invoice', dispense)"><Icon class="text-base" name="file-text" /></Button>
                                <Button v-if="dispense.can_dispense && capabilities.can_dispense" icon size="rg" type="button" title="Ouvrir la délivrance" :aria-label="`Délivrer les produits de ${dispense.customer_name}`" @click="emit('deliver', dispense)"><Icon class="text-base" name="package" /></Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!filteredDispenses.length">
                        <td colspan="5" class="px-5 py-12 text-center">
                            <Icon class="text-2xl text-slate-300" name="check-circle" />
                            <p class="mt-2 text-sm font-bold text-slate-600 dark:text-slate-300">Aucune demande pour ce filtre</p>
                            <p class="mt-1 text-xs text-slate-400">Les patients internes et clients externes apparaissent ici selon leur étape.</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="selectedDispense" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="closeDispense">
            <section class="flex max-h-[88vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="pharmacy-dispense-details-title">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div class="flex min-w-0 items-start gap-3">
                        <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded', selectedDispense.type === 'EXTERNAL' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-primary-50 text-primary-600 dark:bg-primary-950/30 dark:text-primary-300']"><Icon class="text-xl" :name="selectedDispense.type === 'EXTERNAL' ? 'shopping-cart' : 'file-docs'" /></span>
                        <div class="min-w-0">
                            <h2 id="pharmacy-dispense-details-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">{{ selectedDispense.type === 'INTERNAL' ? 'Ordonnance à délivrer' : 'Produits du ticket Pharmacie' }}</h2>
                            <p class="mt-0.5 truncate text-sm text-slate-400">{{ selectedDispense.customer_name }}<template v-if="selectedDispense.episode_number"> · {{ selectedDispense.episode_number }}</template></p>
                        </div>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white" aria-label="Fermer" @click="closeDispense"><Icon class="text-xl" name="cross" /></button>
                </header>

                <div class="grid grid-cols-2 divide-x divide-gray-200 border-b border-gray-200 bg-gray-50/70 dark:divide-gray-900 dark:border-gray-900 dark:bg-gray-1000/30 sm:grid-cols-4">
                    <div class="px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Patient / client</p><p class="mt-1 truncate text-xs font-bold text-slate-700 dark:text-white">{{ selectedDispense.customer_number ?? selectedDispense.customer_phone ?? 'Client comptoir' }}</p></div>
                    <div class="px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Prescripteur</p><p class="mt-1 truncate text-xs font-bold text-slate-700 dark:text-white">{{ selectedDispense.prescriber ?? 'Non renseigné' }}</p></div>
                    <div class="px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Ticket</p><p class="mt-1 truncate font-mono text-xs font-bold text-slate-700 dark:text-white">{{ selectedDispense.invoice?.number ?? 'À préparer' }}</p></div>
                    <div class="px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Statut</p><span :class="['mt-1 inline-flex items-center gap-1.5 rounded-full border px-2 py-1 text-[10px] font-bold', statusPresentation(selectedDispense.status).style]"><span :class="['h-1.5 w-1.5 rounded-full', statusPresentation(selectedDispense.status).dot]" />{{ statusPresentation(selectedDispense.status).label }}</span></div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-5">
                    <div v-if="selectedDispense.status === 'AWAITING_PAYMENT'" class="mb-4 flex items-start gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200"><Icon class="mt-0.5 shrink-0" name="info" /><span>Le ticket doit être réglé à la Caisse avant toute sortie physique.</span></div>
                    <div v-else-if="selectedDispense.can_dispense" class="mb-4 flex items-start gap-2 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-200"><Icon class="mt-0.5 shrink-0" name="check-circle" /><span>Le règlement ou la prise en charge est confirmé. La délivrance FEFO est autorisée.</span></div>

                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                        <table class="w-full min-w-[660px] border-collapse">
                            <thead class="bg-gray-50 text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:bg-gray-1000"><tr><th class="px-4 py-2.5 text-start">Médicament</th><th class="px-4 py-2.5 text-end">Demandé</th><th class="px-4 py-2.5 text-end">Déjà délivré</th><th class="px-4 py-2.5 text-end">Reste</th></tr></thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                                <template v-for="line in selectedDispense.lines" :key="line.uuid">
                                    <tr><td class="px-4 py-3"><p class="text-sm font-bold text-slate-700 dark:text-white">{{ line.medicine_name }}</p><p class="mt-0.5 text-[11px] text-slate-400">{{ line.medicine_code }}</p></td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ line.quantity_requested }} {{ line.unit }}</td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ line.quantity_dispensed }}</td><td class="px-4 py-3 text-end text-sm font-black text-slate-700 dark:text-white">{{ line.remaining_quantity }}</td></tr>
                                    <tr v-if="capabilities.can_view_lots && line.lots.length" class="bg-gray-50/60 dark:bg-gray-1000/30"><td colspan="4" class="px-4 py-2"><div class="flex flex-wrap gap-1.5"><span v-for="(lot, index) in line.lots" :key="lot.uuid" class="rounded border border-gray-200 bg-white px-2 py-1 text-[10px] text-slate-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300"><strong class="text-slate-700 dark:text-white">{{ index + 1 }}. Lot {{ lot.lot_number }}</strong> · {{ lot.quantity }}<template v-if="capabilities.can_view_expiration"> · exp. {{ lot.expires_at }}</template></span></div></td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3 dark:border-gray-900 dark:bg-gray-1000">
                    <Button variant="white-outline" size="rg" type="button" @click="closeDispense">Fermer</Button>
                    <div class="flex items-center gap-2">
                        <Button v-if="selectedDispense.invoice && capabilities.can_print_ticket" size="rg" variant="white-outline" type="button" :disabled="printingDispenseUuid === selectedDispense.uuid" @click="printTicket(selectedDispense)"><Icon class="text-lg" name="printer" /><span class="ms-2">{{ printingDispenseUuid === selectedDispense.uuid ? 'Préparation…' : 'Imprimer le ticket' }}</span></Button>
                        <Button v-if="selectedDispense.can_dispense && capabilities.can_dispense" size="rg" type="button" @click="deliverDispense(selectedDispense)"><Icon class="text-lg" name="package" /><span class="ms-2">Délivrer</span></Button>
                    </div>
                </footer>
            </section>
        </div>
    </div>
</template>
