<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    CircleCheck,
    Info,
    PackageOpen,
    ReceiptText,
    RotateCcw,
    TriangleAlert,
    Truck,
    Wallet,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Card from '@/Components/Shadcn/Card.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import FormError from '@/Components/UI/FormError.vue';
import { formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatMoney, statusTone } from '@/utilities/pharmacyStatus';
import { cn } from '@/lib/cn';

const props = defineProps({
    careConsumables: { type: Object, required: true },
    capabilities: { type: Object, required: true },
    search: { type: String, default: '' },
    /** `to-serve` | `partial` | `unbilled` | `history` — porté par la page, comme les compteurs. */
    tab: { type: String, default: 'to-serve' },
});

defineEmits(['change-tab']);

const requests = computed(() => {
    const term = props.search.trim().toLowerCase();

    if (!term) {
        return props.careConsumables.requests ?? [];
    }

    return (props.careConsumables.requests ?? []).filter((request) => [
        request.request_number,
        request.episode?.patient_name,
        request.episode?.patient_number,
        request.episode?.episode_number,
        ...request.lines.map((line) => `${line.name} ${line.code}`),
    ].filter(Boolean).join(' ').toLowerCase().includes(term));
});

const toServe = computed(() => requests.value.filter((request) => request.can_be_served
    && (props.tab !== 'partial' || request.status === 'PARTIALLY_SERVED')));
const history = computed(() => requests.value.filter((request) => !request.can_be_served));
const searching = computed(() => props.search.trim().length > 0);

/**
 * Les demandes dont au moins une ligne n'a jamais atteint le compte du
 * patient. Servies ou non : une fois le produit sorti du stock, l'oubli ne
 * se répare plus tout seul, et c'est la seule façon qu'un consommable
 * finisse réellement gratuit.
 */
const unbilled = computed(() => requests.value
    .filter((request) => (request.billing?.unbilled_lines ?? 0) > 0)
    .map((request) => ({
        ...request,
        unbilledLines: request.lines.filter((line) => line.billing?.state === 'NOT_BILLED'),
    })));

const remainingUnits = (request) => request.lines.reduce(
    (total, line) => total + (line.remaining_quantity ?? 0),
    0,
);

// One request is served at a time: the pharmacist confirms the quantities
// actually taken off the shelf, which may be less than declared when a lot
// is short — never silently.
const serveTarget = ref(null);
const serveLines = ref([]);
const serveProcessing = ref(false);
const serveError = ref('');

const linesFor = (request) => request.lines
    .filter((line) => line.remaining_quantity > 0)
    .map((line) => ({
        uuid: line.uuid,
        name: line.name,
        code: line.code,
        unit: line.unit,
        remaining_quantity: line.remaining_quantity,
        quantity: line.remaining_quantity,
    }));

const openServeDialog = (request) => {
    serveTarget.value = request;
    serveError.value = '';
    serveLines.value = linesFor(request);
};
const closeServeDialog = () => {
    if (serveProcessing.value) return;
    serveTarget.value = null;
};
const resetServeLines = () => {
    if (serveTarget.value) serveLines.value = linesFor(serveTarget.value);
    serveError.value = '';
};

// Ce que le pharmacien s'apprête réellement à sortir, relu pendant la
// saisie : une quantité corrigée à la baisse doit se voir avant le clic,
// pas seulement après coup dans l'historique.
const serveTotal = computed(() => serveLines.value.reduce(
    (total, line) => total + (Number(line.quantity) > 0 ? Number(line.quantity) : 0),
    0,
));
const servePartial = computed(() => serveLines.value.some(
    (line) => Number(line.quantity) < line.remaining_quantity,
));

const confirmServe = () => {
    const lines = serveLines.value
        .map((line) => ({ uuid: line.uuid, quantity: Number(line.quantity) }))
        .filter((line) => line.quantity > 0);

    if (lines.length === 0) {
        serveError.value = 'Indiquez au moins une quantité à sortir du stock.';
        return;
    }

    const excessive = serveLines.value.find(
        (line) => Number(line.quantity) > line.remaining_quantity,
    );

    if (excessive) {
        serveError.value = `La quantité de ${excessive.name} dépasse le reliquat de la demande.`;
        return;
    }

    router.post(
        `/pharmacy/care-consumables/${serveTarget.value.uuid}/serve`,
        { lines },
        {
            preserveScroll: true,
            onStart: () => { serveProcessing.value = true; serveError.value = ''; },
            onSuccess: () => { serveTarget.value = null; },
            onError: (errors) => {
                serveError.value = errors.lines || errors.request || 'La sortie de stock a été refusée.';
            },
            onFinish: () => { serveProcessing.value = false; },
        },
    );
};
</script>

<template>
    <div class="space-y-4">
        <!-- La règle qui distingue ce circuit d'une délivrance d'ordonnance
             (ADR-072 amende ADR-049). Elle tient en une ligne, au-dessus de
             l'écran qu'elle gouverne. -->
        <p class="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-xs leading-5 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/25 dark:text-amber-200">
            <Info class="mt-px h-4 w-4 shrink-0" aria-hidden="true" />
            <span>
                <strong class="font-semibold">Ce matériel est facturé au patient</strong>, ligne par ligne, au tarif du catalogue — et encaissé uniquement par la Réception&nbsp;/&nbsp;Caisse (ADR-012).
                Ce qui ne dépend d’aucun règlement, c’est la <strong class="font-semibold">sortie de stock</strong> : le consommable est déjà sur le patient, le garder en stock rendrait l’inventaire faux.
            </span>
        </p>

        <!-- À servir / Servies partiellement -->
        <div v-if="tab === 'to-serve' || tab === 'partial'" class="space-y-3">
            <article
                v-for="request in toServe"
                :key="request.uuid"
                class="overflow-hidden rounded-xl border border-s-[3px] border-border border-s-amber-500 bg-card shadow-sm"
            >
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-3.5">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs font-bold text-foreground">{{ request.request_number }}</span>
                            <Badge :tone="statusTone(request.status)">{{ request.status_label }}</Badge>
                        </div>
                        <p class="mt-1.5 truncate text-sm font-bold text-foreground">
                            {{ request.episode?.patient_name || 'Patient interne' }}
                            <span class="ms-1 font-mono text-xs font-normal text-muted-foreground">{{ request.episode?.patient_number }}</span>
                        </p>
                        <p class="mt-0.5 text-[11px] text-muted-foreground">
                            Passage {{ request.episode?.episode_number || '—' }} · déclaré par {{ request.requested_by || '—' }}
                            <span :title="formatDateTime(request.requested_at)"> · {{ formatRelativeTime(request.requested_at) }}</span>
                        </p>
                        <p v-if="request.notes" class="mt-2 max-w-2xl rounded-lg bg-muted/60 px-3 py-2 text-xs leading-5 text-muted-foreground">{{ request.notes }}</p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <p class="text-end">
                            <span class="block text-2xl font-bold leading-none tabular-nums text-amber-600 dark:text-amber-300">{{ remainingUnits(request) }}</span>
                            <span class="mt-1 block text-[11px] text-muted-foreground">unité{{ remainingUnits(request) > 1 ? 's' : '' }} à sortir</span>
                        </p>
                        <Button
                            v-if="capabilities.can_serve_care_consumables"
                            type="button"
                            @click="openServeDialog(request)"
                        >
                            <Truck class="h-4 w-4" aria-hidden="true" />Servir et sortir le stock
                        </Button>
                    </div>
                </div>

                <ul class="grid gap-px bg-border sm:grid-cols-2">
                    <li v-for="line in request.lines" :key="line.uuid" class="bg-card px-4 py-2.5">
                        <div class="flex items-start justify-between gap-2">
                            <p class="min-w-0 truncate text-xs font-semibold text-foreground">{{ line.name }}</p>
                            <!-- Le prix est du côté Pharmacie, jamais au poste
                                 de soins : l'infirmier ne voit et ne saisit
                                 aucun montant (ADR-036). -->
                            <span v-if="line.billing?.amount" class="shrink-0 text-xs font-bold tabular-nums text-foreground">{{ formatMoney(line.billing.amount) }}</span>
                            <span
                                v-else-if="line.billing?.needs_attention"
                                class="inline-flex shrink-0 items-center gap-1 text-[11px] font-bold text-destructive"
                                :title="line.billing.reason"
                            ><TriangleAlert class="h-3.5 w-3.5" aria-hidden="true" />Non facturé</span>
                        </div>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-muted-foreground">
                            <span class="font-mono">{{ line.code }}</span>
                            <span><strong class="font-bold tabular-nums text-foreground">{{ line.remaining_quantity }}</strong> {{ line.unit }} à sortir</span>
                            <span v-if="line.quantity_served" class="text-emerald-600 dark:text-emerald-400">déjà sortis : {{ line.quantity_served }}</span>
                        </p>
                    </li>
                </ul>

                <!-- Ce que le passage doit pour ce matériel, et où il se
                     règle. La Pharmacie n'encaisse rien (ADR-013) : elle lit,
                     elle ne propose aucun paiement. -->
                <div
                    v-if="request.billing"
                    :class="cn(
                        'flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 border-t px-4 py-2.5 text-xs',
                        request.billing.unbilled_lines ? 'border-destructive/30 bg-destructive/5' : 'border-border bg-muted/40',
                    )"
                >
                    <span class="flex items-center gap-2 text-muted-foreground">
                        <Wallet class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span>
                            Porté au compte du patient :
                            <strong class="font-bold tabular-nums text-foreground">{{ formatMoney(request.billing.total_amount) }}</strong>
                        </span>
                    </span>

                    <span v-if="request.billing.unbilled_lines" class="flex items-center gap-1.5 font-bold text-destructive">
                        <TriangleAlert class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ request.billing.unbilled_lines }} ligne{{ request.billing.unbilled_lines > 1 ? 's' : '' }} non facturée{{ request.billing.unbilled_lines > 1 ? 's' : '' }} — à régulariser à la Réception
                    </span>
                    <span v-else-if="request.billing.invoices.length" class="flex flex-wrap items-center gap-2">
                        <template v-for="invoice in request.billing.invoices" :key="invoice.number">
                            <span class="font-mono text-[11px] text-muted-foreground">{{ invoice.number }}</span>
                            <Badge :tone="invoice.settled ? 'success' : 'warning'">{{ invoice.status_label }}</Badge>
                        </template>
                    </span>
                    <span v-else class="text-muted-foreground">En attente d’une facture du passage — Réception&nbsp;/&nbsp;Caisse</span>
                </div>
            </article>

            <Card v-if="!toServe.length">
                <EmptyState
                    icon="check-circle"
                    :title="searching ? 'Aucune demande ne correspond' : (tab === 'partial' ? 'Aucun reliquat en attente' : 'Aucune demande en attente')"
                    :description="searching
                        ? 'Aucune demande ouverte ne porte ce patient, ce passage ou ce matériel.'
                        : (tab === 'partial'
                            ? 'Toutes les demandes ouvertes attendent encore leur première sortie de stock.'
                            : 'Les Soins n’ont déclaré aucun consommable à sortir du stock.')"
                >
                    <Button v-if="tab === 'partial'" type="button" variant="outline" size="sm" @click="$emit('change-tab', 'to-serve')">
                        Voir toutes les demandes à servir
                    </Button>
                </EmptyState>
            </Card>
        </div>

        <!-- Historique récent -->
        <!-- Le seul chemin par lequel un consommable finit réellement
             gratuit. La facturation est volontairement non bloquante — une
             compresse posée sur une plaie ne s'annule pas parce qu'un tarif
             manque (ADR-072) — mais l'échec était jusqu'ici silencieux :
             plus rien ne le signalait ensuite, et le patient repartait sans
             que la clinique ait compté ce qu'elle avait consommé. -->
        <Card v-else-if="tab === 'unbilled'" class="overflow-hidden border-destructive/40">
            <p class="flex items-start gap-2.5 border-b border-border bg-destructive/5 px-5 py-3 text-xs leading-5 text-foreground">
                <ReceiptText class="mt-px h-4 w-4 shrink-0 text-destructive" aria-hidden="true" />
                <span>
                    Ce matériel est sorti — ou va sortir — du stock <strong class="font-semibold">sans avoir été porté au compte du patient</strong>.
                    La Pharmacie ne peut pas le corriger ici : c’est la Réception qui configure le tarif de vente ou le contexte financier du passage,
                    puis régularise avant la sortie administrative.
                </span>
            </p>

            <ul v-if="unbilled.length" class="divide-y divide-border">
                <li v-for="request in unbilled" :key="request.uuid" class="px-5 py-4">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-mono text-xs font-bold text-foreground">{{ request.request_number }}</span>
                        <Badge :tone="statusTone(request.status)">{{ request.status_label }}</Badge>
                        <span class="text-sm font-semibold text-foreground">{{ request.episode?.patient_name || 'Patient interne' }}</span>
                        <span class="font-mono text-[11px] text-muted-foreground">
                            {{ request.episode?.patient_number }} · passage {{ request.episode?.episode_number || '—' }}
                        </span>
                    </div>
                    <ul class="mt-2 space-y-1.5">
                        <li v-for="line in request.unbilledLines" :key="line.uuid" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2">
                            <p class="text-xs font-bold text-foreground">
                                {{ line.name }}
                                <span class="ms-1 font-mono text-[11px] font-normal text-muted-foreground">{{ line.code }}</span>
                                <span class="ms-1 font-normal text-muted-foreground">— {{ line.quantity_requested }} {{ line.unit }}</span>
                            </p>
                            <p class="mt-0.5 text-[11px] leading-4 text-muted-foreground">{{ line.billing?.reason }}</p>
                        </li>
                    </ul>
                </li>
            </ul>

            <EmptyState
                v-else
                icon="check-circle"
                :title="searching ? 'Aucune demande ne correspond' : 'Tout est facturé'"
                description="Chaque consommable déclaré aux Soins a été porté au compte de son passage."
            >
                <Button type="button" variant="outline" size="sm" @click="$emit('change-tab', 'to-serve')">
                    Revenir aux demandes à servir
                </Button>
            </EmptyState>
        </Card>

        <Card v-else-if="tab === 'history'" class="overflow-hidden">
            <p class="border-b border-border px-5 py-2.5 text-xs text-muted-foreground">
                Les vingt dernières demandes servies ou annulées —
                <button type="button" class="font-bold text-primary hover:underline" @click="$emit('change-tab', 'to-serve')">revenir aux demandes à servir</button>.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse">
                    <thead class="bg-muted/70">
                        <tr>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Demande</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Patient</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Consommables</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Statut</th>
                            <th class="border-b border-border px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-muted-foreground">Facturé</th>
                            <th class="border-b border-border px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-muted-foreground">Traitée</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="request in history" :key="request.uuid" class="align-top">
                            <td class="px-5 py-3 font-mono text-xs text-muted-foreground">{{ request.request_number }}</td>
                            <td class="px-5 py-3">
                                <span class="block text-sm font-semibold text-foreground">{{ request.episode?.patient_name || 'Patient interne' }}</span>
                                <span class="font-mono text-[11px] text-muted-foreground">{{ request.episode?.patient_number }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <ul class="space-y-0.5">
                                    <li v-for="line in request.lines" :key="line.uuid" class="text-xs text-muted-foreground">
                                        <span class="font-medium text-foreground">{{ line.name }}</span>
                                        — <span class="tabular-nums">{{ line.quantity_served }}/{{ line.quantity_requested }}</span> {{ line.unit }}
                                        <span v-if="line.allocations.length" class="font-mono text-[10px]">
                                            (lot {{ line.allocations.map((allocation) => allocation.lot_number).join(', ') }})
                                        </span>
                                    </li>
                                </ul>
                            </td>
                            <td class="px-5 py-3">
                                <Badge :tone="statusTone(request.status)">{{ request.status_label }}</Badge>
                                <span v-if="request.cancellation_reason" class="mt-1 block max-w-xs text-[11px] text-muted-foreground">{{ request.cancellation_reason }}</span>
                            </td>
                            <!-- Une demande servie sans facturation reste une
                                 anomalie visible : c'est une fois le produit
                                 sorti du stock que plus rien ne se répare
                                 tout seul. -->
                            <td class="px-5 py-3 text-end">
                                <span class="block text-sm font-bold tabular-nums text-foreground">{{ formatMoney(request.billing?.total_amount ?? 0) }}</span>
                                <span v-if="request.billing?.unbilled_lines" class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-bold text-destructive">
                                    <TriangleAlert class="h-3 w-3" aria-hidden="true" />{{ request.billing.unbilled_lines }} non facturée{{ request.billing.unbilled_lines > 1 ? 's' : '' }}
                                </span>
                                <span v-else-if="request.billing?.invoices.length" class="mt-0.5 block font-mono text-[11px] text-muted-foreground">
                                    {{ request.billing.invoices.map((invoice) => invoice.number).join(', ') }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-end text-xs text-muted-foreground">
                                <span v-if="request.served_at">{{ formatDateTime(request.served_at) }}<span class="block text-[11px]">{{ request.served_by }}</span></span>
                                <span v-else-if="request.cancelled_at">{{ formatDateTime(request.cancelled_at) }}<span class="block text-[11px]">{{ request.cancelled_by }}</span></span>
                                <span v-else>—</span>
                            </td>
                        </tr>
                        <tr v-if="!history.length">
                            <td colspan="6" class="p-0">
                                <EmptyState
                                    icon="inbox"
                                    :title="searching ? 'Aucune demande ne correspond' : 'Aucune demande traitée'"
                                    description="Les demandes servies ou annulées apparaîtront ici."
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- La fenêtre passe par la primitive partagée (ADR-099) : elle
             portait son propre voile et sa propre boîte, sans piège de focus.
             Non fermable au clic extérieur : on y saisit des quantités de
             stock, et un clic à côté ne doit pas les perdre. -->
        <Dialog
            :open="serveTarget !== null"
            :title="`Servir ${serveTarget?.request_number ?? ''}`"
            description="Confirmez les quantités réellement sorties. L’allocation suit l’ordre FEFO et n’entame jamais une quantité déjà réservée."
            :dismissible="false"
            @update:open="closeServeDialog"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                    <Truck class="h-5 w-5" aria-hidden="true" />
                </span>
            </template>

            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Quantités à sortir</p>
                <Button type="button" variant="ghost" size="xs" :disabled="serveProcessing" @click="resetServeLines">
                    <RotateCcw class="h-3.5 w-3.5" aria-hidden="true" />Tout le reliquat
                </Button>
            </div>

            <ul class="mt-2 divide-y divide-border overflow-hidden rounded-lg border border-border">
                <li v-for="line in serveLines" :key="line.uuid" class="flex items-start justify-between gap-3 px-3 py-2.5">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-bold text-foreground">{{ line.name }}</p>
                        <p class="mt-0.5 font-mono text-[10px] text-muted-foreground">{{ line.code }} · reliquat {{ line.remaining_quantity }} {{ line.unit }}</p>
                    </div>
                    <div class="shrink-0">
                        <label :for="`serve-${line.uuid}`" class="mb-1 block text-end text-[11px] text-muted-foreground">Quantité</label>
                        <Input
                            :id="`serve-${line.uuid}`"
                            v-model="line.quantity"
                            :class="cn('w-24 text-center tabular-nums', Number(line.quantity) > line.remaining_quantity && 'border-destructive')"
                            type="number"
                            min="0"
                            :max="line.remaining_quantity"
                            step="1"
                        />
                    </div>
                </li>
            </ul>

            <p :class="cn(
                'mt-3 flex items-center gap-2 rounded-lg px-3 py-2 text-xs',
                servePartial ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:text-amber-200' : 'bg-muted/60 text-muted-foreground',
            )">
                <component :is="servePartial ? PackageOpen : CircleCheck" class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span>
                    <strong class="font-bold tabular-nums">{{ serveTotal }}</strong> unité{{ serveTotal > 1 ? 's' : '' }} sortie{{ serveTotal > 1 ? 's' : '' }} du stock.
                    <template v-if="servePartial">Le reliquat restera à servir sur cette demande.</template>
                </span>
            </p>

            <FormError class="mt-3" :message="serveError" />

            <template #footer>
                <Button type="button" variant="outline" :disabled="serveProcessing" @click="closeServeDialog">Annuler</Button>
                <Button type="button" :disabled="serveProcessing || serveTotal === 0" @click="confirmServe">
                    {{ serveProcessing ? 'Sortie en cours…' : 'Confirmer la sortie de stock' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
