<script setup>
import { formatMoney } from '@/utilities/money';
import { computed, onBeforeUnmount, ref } from 'vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import { lucideIcon } from '@/lib/icons';
import { CircleCheck, Clock, Eye, FileText, Info, Package, Printer, Search, X } from 'lucide-vue-next';
import { statusTone } from '@/utilities/pharmacyStatus';

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

const STATUS_LABELS = {
    AWAITING_INVOICE: 'Ticket à préparer',
    AWAITING_PAYMENT: 'En attente de la Caisse',
    READY: 'Réglée · prête à délivrer',
    PARTIALLY_DISPENSED: 'Délivrée en partie',
};
const statusLabel = (status) => STATUS_LABELS[status] ?? status;
const TILE_TONES = { READY: 'emerald', PARTIALLY_DISPENSED: 'violet', AWAITING_PAYMENT: 'amber', AWAITING_INVOICE: 'sky' };
const TILE_BADGES = { READY: 'À délivrer', PARTIALLY_DISPENSED: 'Partielle', AWAITING_PAYMENT: 'Caisse', AWAITING_INVOICE: 'À facturer' };

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
</script>

<template>
    <div>
        <div class="grid border-b border-gray-200 bg-gray-50/70 dark:border-gray-900 dark:bg-gray-1000 sm:grid-cols-3">
            <button type="button" class="border-b border-gray-200 px-5 py-4 text-start transition hover:bg-sky-50 dark:border-gray-900 dark:hover:bg-sky-950/10 sm:border-b-0 sm:border-e" @click="statusFilter = 'AWAITING_INVOICE'">
                <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">À facturer</span>
                <span class="mt-1 flex items-end justify-between"><strong class="text-xl text-sky-600">{{ summary.awaiting_invoice ?? 0 }}</strong><FileText class="text-sky-300 h-4 w-4" /></span>
            </button>
            <button type="button" class="border-b border-gray-200 px-5 py-4 text-start transition hover:bg-amber-50 dark:border-gray-900 dark:hover:bg-amber-950/10 sm:border-b-0 sm:border-e" @click="statusFilter = 'AWAITING_PAYMENT'">
                <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">En attente Caisse</span>
                <span class="mt-1 flex items-end justify-between"><strong class="text-xl text-amber-600">{{ summary.awaiting_payment ?? 0 }}</strong><Clock class="text-amber-300 h-4 w-4" /></span>
            </button>
            <button type="button" class="px-5 py-4 text-start transition hover:bg-emerald-50 dark:hover:bg-emerald-950/10" @click="statusFilter = 'READY'">
                <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Prêtes à délivrer</span>
                <span class="mt-1 flex items-end justify-between"><strong class="text-xl text-emerald-600">{{ summary.ready ?? 0 }}</strong><CircleCheck class="text-emerald-300 h-4 w-4" /></span>
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
                    <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto text-slate-400 h-4 w-4" />
                    <input v-model="search" type="search" class="h-10 w-full rounded-lg border border-gray-200 bg-gray-50 ps-10 pe-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-900 dark:text-white" placeholder="Client, patient, facture, ordonnance ou médicament…">
                </label>
                <select v-model="typeFilter" class="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-600 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 sm:w-52">
                    <option value="ALL">Tous les parcours</option>
                    <option value="EXTERNAL">Clients externes</option>
                    <option value="INTERNAL">Patients internes</option>
                </select>
            </div>
        </div>

        <ExplorerView storage-key="pharmacy-dispenses"
            :framed="false"
            :count="filteredDispenses.length"
            count-label="demande"
            empty-icon="check-circle"
            empty-title="Aucune demande pour ce filtre"
            empty-description="Les patients internes et clients externes apparaissent ici selon leur étape."
        >
            <template #grid>
                <ExplorerTile v-for="dispense in filteredDispenses"
                    :key="dispense.uuid"
                    :icon="dispense.type === 'EXTERNAL' ? 'cart' : 'file-docs'"
                    :tone="TILE_TONES[dispense.status] ?? 'primary'"
                    :badge="TILE_BADGES[dispense.status] ?? null"
                    :title="dispense.customer_name"
                    :subtitle="dispense.invoice?.number ?? 'Ticket à préparer'"
                    :highlight="`${dispense.line_count} produit${dispense.line_count > 1 ? 's' : ''}`"
                    :meta="dispense.invoice ? formatMoney(dispense.invoice.total_amount) : formatDate(dispense.requested_at)"
                    @open="showDispense(dispense)"
                >
                    <template #actions>
                        <Button size="sm" type="button" variant="white-outline" @click="showDispense(dispense)"><Eye class="h-4 w-4" /></Button>
                        <Button v-if="dispense.can_prepare_invoice && capabilities.can_prepare_invoice" size="sm" type="button" title="Préparer le ticket" @click="emit('prepare-invoice', dispense)"><FileText class="h-4 w-4" /></Button>
                        <Button v-if="dispense.can_dispense && capabilities.can_dispense" size="sm" type="button" title="Délivrer" @click="emit('deliver', dispense)"><Package class="h-4 w-4" /></Button>
                    </template>
                </ExplorerTile>
            </template>
            <template #list>
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
                                <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', dispense.type === 'EXTERNAL' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-primary-50 text-primary-600 dark:bg-primary-950/30 dark:text-primary-300']"><component :is="lucideIcon(dispense.type === 'EXTERNAL' ? 'user' : 'file-docs')" class="h-4 w-4" /></span>
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
                            <Badge :tone="statusTone(dispense.status)" dot>{{ statusLabel(dispense.status) }}</Badge>
                            <p :class="['mt-1 text-xs', dispense.can_dispense ? 'text-emerald-600' : 'text-slate-400']">{{ dispense.can_dispense ? 'Règlement confirmé' : (dispense.invoice ? 'Contrôle Caisse requis' : 'Ticket non créé') }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Button size="rg" type="button" variant="white-outline" title="Voir les détails et les actions documentaires" :aria-label="`Voir les détails de ${dispense.customer_name}`" @click="showDispense(dispense)"><Eye class="text-base h-4 w-4" /><span class="ms-2">Voir</span></Button>
                                <Button v-if="dispense.can_prepare_invoice && capabilities.can_prepare_invoice" icon size="rg" type="button" title="Préparer le ticket" aria-label="Préparer le ticket" @click="emit('prepare-invoice', dispense)"><FileText class="text-base h-4 w-4" /></Button>
                                <Button v-if="dispense.can_dispense && capabilities.can_dispense" icon size="rg" type="button" title="Ouvrir la délivrance" :aria-label="`Délivrer les produits de ${dispense.customer_name}`" @click="emit('deliver', dispense)"><Package class="text-base h-4 w-4" /></Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            </template>
        </ExplorerView>

        <div v-if="selectedDispense" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="closeDispense">
            <section class="flex max-h-[88vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="pharmacy-dispense-details-title">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div class="flex min-w-0 items-start gap-3">
                        <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded', selectedDispense.type === 'EXTERNAL' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-primary-50 text-primary-600 dark:bg-primary-950/30 dark:text-primary-300']"><component :is="lucideIcon(selectedDispense.type === 'EXTERNAL' ? 'cart' : 'file-docs')" class="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <h2 id="pharmacy-dispense-details-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">{{ selectedDispense.type === 'INTERNAL' ? 'Ordonnance à délivrer' : 'Produits du ticket Pharmacie' }}</h2>
                            <p class="mt-0.5 truncate text-sm text-slate-400">{{ selectedDispense.customer_name }}<template v-if="selectedDispense.episode_number"> · {{ selectedDispense.episode_number }}</template></p>
                        </div>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white" aria-label="Fermer" @click="closeDispense"><X class="h-4 w-4" /></button>
                </header>

                <div class="grid grid-cols-2 divide-x divide-gray-200 border-b border-gray-200 bg-gray-50/70 dark:divide-gray-900 dark:border-gray-900 dark:bg-gray-1000/30 sm:grid-cols-4">
                    <div class="px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Patient / client</p><p class="mt-1 truncate text-xs font-bold text-slate-700 dark:text-white">{{ selectedDispense.customer_number ?? selectedDispense.customer_phone ?? 'Client comptoir' }}</p></div>
                    <div class="px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Prescripteur</p><p class="mt-1 truncate text-xs font-bold text-slate-700 dark:text-white">{{ selectedDispense.prescriber ?? 'Non renseigné' }}</p></div>
                    <div class="px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Ticket</p><p class="mt-1 truncate font-mono text-xs font-bold text-slate-700 dark:text-white">{{ selectedDispense.invoice?.number ?? 'À préparer' }}</p></div>
                    <div class="px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Statut</p><Badge class="mt-1" :tone="statusTone(selectedDispense.status)" dot>{{ statusLabel(selectedDispense.status) }}</Badge></div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-5">
                    <div v-if="selectedDispense.status === 'AWAITING_PAYMENT'" class="mb-4 flex items-start gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200"><Info class="mt-0.5 shrink-0 h-4 w-4" /><span>Le ticket doit être réglé à la Caisse avant toute sortie physique.</span></div>
                    <div v-else-if="selectedDispense.can_dispense" class="mb-4 flex items-start gap-2 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-200"><CircleCheck class="mt-0.5 shrink-0 h-4 w-4" /><span>Le règlement ou la prise en charge est confirmé. La délivrance FEFO est autorisée.</span></div>

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
                        <Button v-if="selectedDispense.invoice && capabilities.can_print_ticket" size="rg" variant="white-outline" type="button" :disabled="printingDispenseUuid === selectedDispense.uuid" @click="printTicket(selectedDispense)"><Printer class="h-4 w-4" /><span class="ms-2">{{ printingDispenseUuid === selectedDispense.uuid ? 'Préparation…' : 'Imprimer le ticket' }}</span></Button>
                        <Button v-if="selectedDispense.can_dispense && capabilities.can_dispense" size="rg" type="button" @click="deliverDispense(selectedDispense)"><Package class="h-4 w-4" /><span class="ms-2">Délivrer</span></Button>
                    </div>
                </footer>
            </section>
        </div>
    </div>
</template>
