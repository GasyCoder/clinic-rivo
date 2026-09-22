<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, ArrowRight, Building2, CalendarDays, Check, ClipboardCheck, FileText, Hash, Minus, PackageCheck, Pencil, Plus, Sparkles, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Input from '@/Components/Shadcn/Input.vue';
import SupplierInvoiceFields from '@/Components/Pharmacy/SupplierInvoiceFields.vue';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';
import { formatMoney, formatNumber } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/*
 * ADR-175 — réceptionner, c'est constater ce qui est arrivé : quantités,
 * lots et péremptions lus sur les boîtes. Rien n'entre au stock ici ; c'est
 * l'écran « Entrée en stock » qui range la marchandise.
 *
 * La facture du fournisseur se saisit dans la foulée si elle accompagne la
 * livraison ; sinon la réception reste « facture en attente ». La date de
 * réception est celle du serveur : elle n'est pas demandée.
 */
const props = defineProps({ order: Object, can: Object });

const step = ref(1);
const confirming = ref(false);
const today = new Date().toISOString().slice(0, 10);

/*
 * ADR-176 — le n° de lot et la péremption se recopient de la boîte : rien ne
 * les invente. Seul un lot que la pharmacie tient déjà porte une péremption
 * enregistrée ; la saisir une seconde fois n'apprend rien à personne.
 * Le `nextTick` est nécessaire : `@input` sur un composant est écouté avant
 * que `v-model` ait posé la nouvelle valeur.
 */
const knownLot = (line) => (line._known_lots ?? []).find(
    (lot) => lot.lot_number.toLocaleLowerCase() === String(line.lot_number ?? '').trim().toLocaleLowerCase(),
);
const onLotInput = async (line) => {
    await nextTick();
    const lot = knownLot(line);
    if (lot?.expires_at) line.expires_at = lot.expires_at;
};

const form = useForm({
    notes: '',
    lines: props.order.lines.map((line) => ({
        purchase_order_line_id: line.id,
        quantity_received: line.quantity_remaining,
        lot_number: '',
        expires_at: '',
        unit_purchase_price: props.can.record_cost ? line.unit_price : null,
        notes: '',
        sale_name: '',
        _received: true,
        _renaming: false,
        _name: line.medicine_name,
        _code: line.medicine_code,
        _unit: line.unit,
        _is_new: line.is_new,
        _remaining: line.quantity_remaining,
        _ordered: line.quantity_ordered,
        _unit_price: line.unit_price,
        _known_lots: line.known_lots ?? [],
    })),
    invoice: {
        invoice_number: '',
        invoice_date: today,
        due_date: '',
        total_amount: '',
        notes: '',
        attachment: null,
    },
});

const kept = computed(() => form.lines.filter((line) => line._received));
const units = computed(() => kept.value.reduce((sum, line) => sum + (Number(line.quantity_received) || 0), 0));
const receivedValue = computed(() => kept.value.reduce(
    (sum, line) => sum + (Number(line.quantity_received) || 0) * (Number(line.unit_purchase_price ?? line._unit_price) || 0), 0,
));
const newProducts = computed(() => kept.value.filter((line) => line._is_new).length);
const partial = computed(() => kept.value.length < form.lines.length
    || kept.value.some((line) => Number(line.quantity_received) < line._remaining));

const step1Error = computed(() => {
    if (!kept.value.length) return 'Cochez au moins un produit réellement arrivé.';
    const incomplete = kept.value.filter((line) => !line.lot_number.trim() || !line.expires_at
        || !(Number(line.quantity_received) >= 1) || Number(line.quantity_received) > line._remaining).length;

    return incomplete ? `${incomplete} produit${incomplete > 1 ? 's' : ''} sans lot, sans péremption ou avec une quantité impossible.` : '';
});
const invoiceStarted = computed(() => Boolean(form.invoice.invoice_number.trim() || form.invoice.total_amount || form.invoice.attachment));
const invoiceError = computed(() => {
    if (!invoiceStarted.value) return '';
    if (!form.invoice.invoice_number.trim()) return 'Indiquez le numéro de la facture.';
    if (!(Number(form.invoice.total_amount) > 0)) return 'Indiquez le montant de la facture.';

    return '';
});

const stepLine = (line, delta) => {
    line.quantity_received = Math.min(line._remaining, Math.max(1, (Number(line.quantity_received) || 0) + delta));
};

const proposedTotal = computed(() => (props.can.see_cost && receivedValue.value ? receivedValue.value.toFixed(2) : null));

const submit = () => {
    form.transform((data) => ({
        notes: data.notes,
        lines: data.lines
            .filter((line) => line._received)
            .map((line) => ({
                purchase_order_line_id: line.purchase_order_line_id,
                quantity_received: Number(line.quantity_received),
                lot_number: line.lot_number.trim(),
                expires_at: line.expires_at,
                unit_purchase_price: line.unit_purchase_price,
                notes: line.notes || null,
                sale_name: line.sale_name?.trim() || null,
            })),
        invoice: invoiceStarted.value ? data.invoice : null,
    })).post(`/pharmacy/purchase-orders/${props.order.uuid}/receipts`, {
        forceFormData: true,
        onError: () => {
            confirming.value = false;
            if (Object.keys(form.errors).some((key) => key.startsWith('lines'))) step.value = 1;
        },
    });
};

const lineError = (index, field) => form.errors[`lines.${index}.${field}`];
const steps = [
    { number: 1, label: 'Ce qui est arrivé', icon: PackageCheck },
    { number: 2, label: 'Facture fournisseur', icon: FileText },
];
</script>

<template>
    <Head :title="`Réceptionner ${order.order_number}`" />

    <div class="w-full space-y-6 pb-28">
        <Breadcrumb :items="[
            { label: 'Achats', href: '/pharmacy/purchase-orders' },
            { label: order.order_number, href: `/pharmacy/purchase-orders/${order.uuid}` },
            { label: 'Réceptionner' },
        ]" />

        <PageHeader
            eyebrow="Achats"
            title="Réceptionner la marchandise"
            description="Recopiez le lot et la péremption inscrits sur les boîtes, et la quantité réellement arrivée. La marchandise entrera au stock à l’étape « Entrée en stock »."
            icon="package"
            tone="violet"
        />

        <!-- Progression -->
        <ol class="flex items-center gap-3">
            <li v-for="(item, index) in steps" :key="item.number" class="flex flex-1 items-center gap-3">
                <button
                    type="button"
                    :disabled="item.number === 2 && (Boolean(step1Error) || !can.record_invoice)"
                    :class="cn('flex flex-1 items-center gap-3 rounded-xl border px-4 py-3 text-start transition disabled:opacity-50',
                               step === item.number ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-card hover:border-primary/40')"
                    @click="step = item.number"
                >
                    <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-bold',
                                     step > item.number ? 'bg-emerald-600 text-white' : step === item.number ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                        <Check v-if="step > item.number" class="h-4 w-4" /><template v-else>{{ item.number }}</template>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-foreground">{{ item.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ item.number === 1 ? `${kept.length} produit${kept.length > 1 ? 's' : ''} · ${formatNumber(units)} unité${units > 1 ? 's' : ''}` : (invoiceStarted ? form.invoice.invoice_number || 'en cours de saisie' : 'facultatif — peut attendre') }}</span>
                    </span>
                </button>
                <ArrowRight v-if="index === 0" class="hidden h-4 w-4 shrink-0 text-muted-foreground sm:block" />
            </li>
        </ol>

        <!-- La commande, en lecture -->
        <section class="grid gap-3 rounded-2xl border border-border bg-card p-5 shadow-sm sm:grid-cols-3">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Fournisseur</p>
                    <p class="truncate font-semibold text-foreground">{{ order.supplier }}</p>
                    <p v-if="order.supplier_contact" class="truncate text-xs text-muted-foreground">{{ order.supplier_contact }}</p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-muted text-muted-foreground"><Hash class="h-5 w-5" /></span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Commande</p>
                    <Link :href="`/pharmacy/purchase-orders/${order.uuid}`" class="font-mono font-semibold text-primary hover:underline">{{ order.order_number }}</Link>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-muted text-muted-foreground"><CalendarDays class="h-5 w-5" /></span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Réception</p>
                    <p class="font-semibold text-foreground">{{ formatDate(today) }}</p>
                    <p class="text-xs text-muted-foreground">datée automatiquement</p>
                </div>
            </div>
        </section>

        <!-- ÉTAPE 1 -->
        <section v-show="step === 1" class="rounded-2xl border border-border bg-card shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border p-5">
                <div>
                    <h2 class="font-heading text-lg font-bold text-foreground">Ce qui est arrivé</h2>
                    <p class="text-sm text-muted-foreground">Décochez un produit qui n’est pas dans la livraison ; il restera attendu sur la commande.</p>
                </div>
                <Badge v-if="partial" tone="warning">Réception partielle</Badge>
            </header>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="w-10 px-4 py-3" />
                            <th class="px-4 py-3 text-start">Produit</th>
                            <th class="w-44 px-3 py-3 text-start">Quantité arrivée</th>
                            <th class="w-40 px-3 py-3 text-start">N° de lot</th>
                            <th class="w-40 px-3 py-3 text-start">Péremption</th>
                            <th v-if="can.record_cost" class="w-36 px-3 py-3 text-start">Prix d’achat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template v-for="(line, index) in form.lines" :key="line.purchase_order_line_id">
                            <tr :class="cn('align-top transition-colors', line._received ? 'hover:bg-muted/20' : 'bg-muted/30 opacity-60')">
                                <td class="px-4 py-3.5"><Checkbox v-model="line._received" :aria-label="`${line._name} est arrivé`" /></td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold text-foreground">{{ line.sale_name?.trim() || line._name }}</p>
                                        <Badge v-if="line._is_new" tone="warning"><Sparkles class="h-3 w-3" />Nouveau produit</Badge>
                                    </div>
                                    <p class="mt-0.5 text-xs text-muted-foreground"><span class="font-mono">{{ line._code }}</span><span v-if="line._unit"> · {{ line._unit }}</span> · commandé {{ formatNumber(line._ordered) }}</p>
                                    <div v-if="can.rename && line._received && line._is_new" class="mt-1.5">
                                        <div v-if="line._renaming" class="flex max-w-sm items-center gap-1.5">
                                            <Input v-model="line.sale_name" class="h-9 text-sm" maxlength="255" :placeholder="line._name" aria-label="Nom à la pharmacie" />
                                            <Button type="button" size="icon-xs" variant="ghost" aria-label="Garder le nom du fournisseur" @click="line.sale_name = ''; line._renaming = false"><X class="h-3.5 w-3.5" /></Button>
                                        </div>
                                        <button v-else type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline" @click="line._renaming = true">
                                            <Pencil class="h-3 w-3" />Donner son nom à la pharmacie
                                        </button>
                                    </div>
                                    <Input v-if="line._received" v-model="line.notes" class="mt-2 h-8 max-w-sm text-xs" maxlength="500" placeholder="Remarque sur ce produit (ex. 1 boîte abîmée)" />
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center">
                                        <Button type="button" size="icon-xs" variant="outline" class="rounded-e-none" tabindex="-1" :disabled="!line._received || Number(line.quantity_received) <= 1" aria-label="Moins" @click="stepLine(line, -1)"><Minus class="h-3 w-3" /></Button>
                                        <input
                                            v-model.number="line.quantity_received"
                                            type="number"
                                            min="1"
                                            :max="line._remaining"
                                            :disabled="!line._received"
                                            :class="cn('h-7 w-16 border-y border-input bg-card text-center text-sm font-semibold tabular-nums text-foreground outline-none focus:border-primary', Number(line.quantity_received) > line._remaining && 'border-red-400 text-red-600')"
                                        >
                                        <Button type="button" size="icon-xs" variant="outline" class="rounded-s-none" tabindex="-1" :disabled="!line._received || Number(line.quantity_received) >= line._remaining" aria-label="Plus" @click="stepLine(line, 1)"><Plus class="h-3 w-3" /></Button>
                                    </div>
                                    <p class="mt-1 text-[11px] text-muted-foreground">{{ formatNumber(line._remaining) }} encore attendu{{ line._remaining > 1 ? 's' : '' }}</p>
                                    <p v-if="lineError(index, 'quantity_received')" class="mt-1 text-[11px] text-destructive">{{ lineError(index, 'quantity_received') }}</p>
                                </td>
                                <td class="px-3 py-3">
                                    <Input
                                        v-model="line.lot_number"
                                        class="h-9 font-mono text-sm"
                                        maxlength="100"
                                        placeholder="Lu sur la boîte"
                                        :list="line._known_lots?.length ? `recu-lots-${index}` : undefined"
                                        :disabled="!line._received"
                                        @input="onLotInput(line)"
                                    />
                                    <datalist v-if="line._known_lots?.length" :id="`recu-lots-${index}`"><option v-for="lot in line._known_lots" :key="lot.uuid" :value="lot.lot_number" /></datalist>
                                    <p v-if="knownLot(line)" class="mt-1 text-[11px] text-emerald-600 dark:text-emerald-400">Lot connu : sa péremption est reprise</p>
                                    <p v-if="lineError(index, 'lot_number')" class="mt-1 text-[11px] text-destructive">{{ lineError(index, 'lot_number') }}</p>
                                </td>
                                <td class="px-3 py-3">
                                    <DatePicker v-model="line.expires_at" size="sm" :disabled="!line._received" />
                                    <p v-if="lineError(index, 'expires_at')" class="mt-1 text-[11px] text-destructive">{{ lineError(index, 'expires_at') }}</p>
                                </td>
                                <td v-if="can.record_cost" class="px-3 py-3">
                                    <span class="relative block">
                                        <Input v-model="line.unit_purchase_price" type="number" min="0" step="0.01" class="h-9 pe-11 text-end text-sm tabular-nums" :disabled="!line._received" />
                                        <span class="pointer-events-none absolute inset-y-0 end-2.5 flex items-center text-[11px] text-muted-foreground">MGA</span>
                                    </span>
                                    <p class="mt-1 text-[11px] text-muted-foreground">Repris de la commande</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <footer class="flex flex-col gap-3 border-t border-border p-5 sm:flex-row sm:items-center sm:justify-between">
                <label class="block w-full max-w-md">
                    <span class="mb-1.5 block text-sm font-semibold text-foreground">Remarque sur la livraison <span class="font-normal text-muted-foreground">(facultatif)</span></span>
                    <Input v-model="form.notes" maxlength="2000" placeholder="Ex. 2 cartons abîmés, chauffeur prévenu" />
                </label>
            </footer>
        </section>

        <!-- ÉTAPE 2 -->
        <section v-show="step === 2" class="rounded-2xl border border-border bg-card shadow-sm">
            <header class="border-b border-border p-5">
                <h2 class="font-heading text-lg font-bold text-foreground">Facture du fournisseur</h2>
                <p class="text-sm text-muted-foreground">Si la facture accompagne la livraison, recopiez-la ici. Sinon, passez : la réception restera « facture en attente » et la facture pourra être enregistrée plus tard.</p>
            </header>
            <div class="p-5">
                <SupplierInvoiceFields :form="form.invoice" :errors="form.errors" prefix="invoice" :proposed-total="proposedTotal" />
                <p v-if="invoiceError" class="mt-3 text-sm text-amber-600 dark:text-amber-400">{{ invoiceError }}</p>
            </div>
        </section>

        <!-- Barre d'action -->
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80">
            <div class="mx-auto flex w-full max-w-7xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                    <p class="text-sm text-muted-foreground"><span class="text-xl font-bold tabular-nums text-foreground">{{ kept.length }}</span> produit{{ kept.length > 1 ? 's' : '' }} · <span class="font-semibold text-foreground">{{ formatNumber(units) }}</span> unité{{ units > 1 ? 's' : '' }}</p>
                    <p v-if="can.see_cost && receivedValue" class="text-sm text-muted-foreground">Valeur reçue <span class="font-semibold tabular-nums text-foreground">{{ formatMoney(receivedValue) }}</span></p>
                    <p v-if="step === 1 && step1Error" class="text-sm text-amber-600 dark:text-amber-400">{{ step1Error }}</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Button v-if="step === 2" type="button" variant="ghost" @click="step = 1"><ArrowLeft class="h-4 w-4" />Revenir aux produits</Button>
                    <Button :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}`" variant="outline">Annuler</Button>
                    <Button v-if="step === 1 && can.record_invoice" :disabled="Boolean(step1Error)" @click="step = 2">
                        Continuer vers la facture<ArrowRight class="h-4 w-4" />
                    </Button>
                    <Button v-else size="lg" :disabled="Boolean(step1Error) || Boolean(invoiceError) || form.processing" @click="confirming = true">
                        <ClipboardCheck class="h-4 w-4" />{{ invoiceStarted ? 'Enregistrer réception et facture' : 'Enregistrer la réception' }}
                    </Button>
                </div>
            </div>
        </div>

        <ConfirmModal
            v-model:open="confirming"
            title="Enregistrer cette réception ?"
            :description="invoiceStarted
                ? 'La réception et la facture sont enregistrées ensemble. La marchandise n’entre pas encore au stock : elle attendra l’écran « Entrée en stock ».'
                : 'La marchandise n’entre pas encore au stock : elle attendra l’écran « Entrée en stock ». La facture reste en attente.'"
            confirm-label="Enregistrer la réception"
            tone="success"
            :processing="form.processing"
            @confirm="submit"
        >
            <dl class="divide-y divide-border rounded-xl border border-border text-sm">
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Fournisseur</dt><dd class="text-end font-semibold text-foreground">{{ order.supplier }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Commande</dt><dd class="font-mono font-semibold text-foreground">{{ order.order_number }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Reçu</dt>
                    <dd class="font-semibold text-foreground">{{ kept.length }} produit{{ kept.length > 1 ? 's' : '' }} · {{ formatNumber(units) }} unité{{ units > 1 ? 's' : '' }}<span v-if="partial" class="text-amber-600 dark:text-amber-400"> · partielle</span></dd>
                </div>
                <div v-if="newProducts" class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Nouveaux produits</dt><dd class="font-semibold text-amber-600 dark:text-amber-400">{{ newProducts }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Facture</dt>
                    <dd class="text-end font-semibold text-foreground">{{ invoiceStarted ? `${form.invoice.invoice_number} · ${formatMoney(form.invoice.total_amount)}` : 'En attente' }}</dd>
                </div>
            </dl>
        </ConfirmModal>
    </div>
</template>
