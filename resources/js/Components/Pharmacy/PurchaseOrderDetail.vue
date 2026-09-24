<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import Input from '@/Components/Shadcn/Input.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { Ban, CheckCheck, FileText, Info, Mail, Package, PackageX, Paperclip, Pencil, Send, Undo2 } from 'lucide-vue-next';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatMoney, statusTone } from '@/utilities/pharmacyStatus';
import { openSupplierOrderMail } from '@/utilities/supplierOrderMail';
import { usePage } from '@inertiajs/vue3';

/**
 * ADR-098 — one purchase order, shared by the clinic and the central portal.
 * Each page passes its own addresses; an action without an address (or
 * without its permission) is simply not offered.
 */
const props = defineProps({
    order: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    links: { type: Object, required: true }, // { submit, cancel, receive?, supplier?, receipt?(uuid), invoice?(uuid), newInvoice? }
    receptionNote: { type: String, default: null },
});

// Envoyer engage la clinique auprès d'un tiers : la fenêtre dit à qui, quoi
// et pour combien, avant de le faire.
const sending = ref(false);
const sendProcessing = ref(false);
const sendOrder = () => router.post(props.links.submit, {}, {
    preserveScroll: true,
    onStart: () => { sendProcessing.value = true; },
    onFinish: () => { sendProcessing.value = false; },
    onSuccess: () => { sending.value = false; },
});
const unitCount = computed(() => props.order.lines.reduce((sum, line) => sum + (Number(line.quantity_ordered) || 0), 0));

/*
 * Renvoyer la commande par e-mail. Le brouillon s'ouvre déjà à l'envoi
 * (PurchaseOrderForm) ; ce bouton existe pour la fois où la messagerie ne s'est
 * pas ouverte, ou pour relancer un fournisseur. Rien n'est envoyé par RIVO :
 * c'est la messagerie de la personne qui écrit, depuis son adresse.
 */
const page = usePage();
const supplierEmail = computed(() => props.order.supplier_email ?? null);
const canMail = computed(() => Boolean(supplierEmail.value) && props.order.status !== 'DRAFT' && props.order.status !== 'CANCELLED');
const mailToSupplier = () => openSupplierOrderMail(supplierEmail.value, {
    order_number: props.order.order_number,
    ordered_on: props.order.ordered_at ? formatDate(props.order.ordered_at) : null,
    expected_delivery_on: props.order.expected_delivery_at ? formatDate(props.order.expected_delivery_at) : null,
    total: formatMoney(props.order.total_amount),
    notes: props.order.notes,
    lines: props.order.lines.map((line) => ({
        quantity: line.quantity_ordered,
        unit: line.unit,
        name: line.medicine_name,
        code: line.medicine_code,
    })),
}, {
    clinic: page.props.site?.name ?? null,
    author: page.props.auth?.user?.name ?? null,
});

/*
 * Une commande envoyée attend sa marchandise : ne rien dire laisserait croire
 * qu'il n'y a plus rien à faire. Le portail a déjà sa propre phrase (la
 * réception reste au site, ADR-098) ; sinon, c'est le droit qui manque et on
 * le nomme plutôt que de laisser un écran muet (ADR-154).
 */
const awaitingNote = computed(() => {
    if (props.receptionNote) {
        return props.receptionNote;
    }

    if (!props.can.receive) {
        return 'La marchandise reste à réceptionner. Réceptionner demande le droit « goods_receipts.create », qui s’accorde dans Rôles & permissions.';
    }

    return null;
});

const cancelling = ref(false);
const cancelForm = useForm({ reason: '' });
const confirmCancel = () => cancelForm.post(props.links.cancel, {
    preserveScroll: true,
    onSuccess: () => { cancelling.value = false; cancelForm.reset(); },
});

const awaitingGoods = () => ['ORDERED', 'PARTIALLY_RECEIVED'].includes(props.order.status);

/*
 * ADR-179 — la confirmation du fournisseur : une trace, jamais un passage
 * obligé. Son absence ne veut pas dire « pas confirmée » — beaucoup de
 * fournisseurs ne confirment jamais —, et rien n'en dépend.
 */
const confirming = ref(false);
const confirmForm = useForm({
    confirmed_at: new Date().toISOString().slice(0, 10),
    reference: '',
    notes: '',
    attachment: null,
});
const openConfirm = () => {
    const known = props.order.supplier_confirmation;
    confirmForm.defaults({
        confirmed_at: known?.confirmed_at?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
        reference: known?.reference ?? '',
        notes: known?.notes ?? '',
        attachment: null,
    });
    confirmForm.reset();
    confirming.value = true;
};
const submitConfirm = () => confirmForm.post(props.links.confirm, {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { confirming.value = false; },
});
const withdrawConfirmation = () => router.delete(props.links.confirm, { preserveScroll: true });

/*
 * ADR-179 — solder d'un geste une commande dont on n'attend plus rien. Ce
 * n'est pas une annulation : la commande a été envoyée, souvent livrée en
 * partie, et peut porter une facture.
 */
const closing = ref(false);
const closeForm = useForm({ reason: '' });
const confirmClose = () => closeForm.post(props.links.close, {
    preserveScroll: true,
    onSuccess: () => { closing.value = false; closeForm.reset(); },
});

// Revenir sur une rupture : le fournisseur livre finalement, ou elle a été
// signalée par erreur. La commande se rouvre.
const revertShortage = (line) => router.delete(props.links.shortage(line.id), { preserveScroll: true });
</script>

<template>
    <div class="space-y-5">
        <section class="rounded-xl border border-border bg-card p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-heading text-2xl font-bold text-foreground">Commande {{ order.order_number }}</h1>
                        <Badge :tone="statusTone(order.status)" dot>{{ order.status_label }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        <Link v-if="links.supplier" :href="links.supplier" class="font-semibold text-primary hover:underline">{{ order.supplier }}</Link>
                        <span v-else class="font-semibold">{{ order.supplier }}</span>
                        <span v-if="order.ordered_at"> · envoyée le {{ formatDate(order.ordered_at) }}</span>
                        <span v-if="order.expected_delivery_at"> · livraison attendue le {{ formatDate(order.expected_delivery_at) }}</span>
                        <span v-if="order.created_by_name"> · créée par {{ order.created_by_name }}</span>
                    </p>
                    <p v-if="order.notes" class="mt-1 text-sm text-muted-foreground">{{ order.notes }}</p>
                    <p v-if="order.cancellation_reason" class="mt-2 text-sm text-red-600">Motif d’annulation : {{ order.cancellation_reason }}</p>
                </div>
                <!--
                    ADR-176 — l'action de l'étape vient en premier : envoyer un
                    brouillon, réceptionner une commande partie. « Annuler »
                    reste possible jusqu'à la réception (ADR-097 : un
                    fournisseur peut ne jamais livrer), mais c'est une sortie
                    de secours et non le geste attendu — il passe à droite, en
                    discret, et ne devient rouge qu'au survol.
                -->
                <div class="flex flex-1 flex-wrap items-center gap-2 sm:justify-end">
                    <Button v-if="can.submit && order.status === 'DRAFT'" size="rg" @click="sending = true"><Send class="h-4 w-4" />Envoyer la commande</Button>
                    <Button v-if="can.receive && links.receive && awaitingGoods()" :as="Link" :href="links.receive" size="rg"><Package class="h-4 w-4" />Réceptionner</Button>
                    <Button v-if="can.update && links.edit && order.status === 'DRAFT'" :as="Link" :href="links.edit" size="rg" variant="white-outline"><Pencil class="h-4 w-4" />Modifier</Button>
                    <Button v-if="can.create_invoice && links.newInvoice && order.status !== 'CANCELLED' && order.status !== 'DRAFT'" :as="Link" :href="links.newInvoice" size="rg" variant="white-outline"><FileText class="h-4 w-4" />Enregistrer la facture</Button>
                    <Button v-if="canMail" size="rg" variant="white-outline" type="button" :title="`Écrire à ${supplierEmail}`" @click="mailToSupplier()"><Mail class="h-4 w-4" />Envoyer par e-mail</Button>
                    <Button v-if="can.close && links.close && awaitingGoods() && order.has_outstanding_lines" size="sm" variant="ghost" @click="closing = true"><CheckCheck class="h-4 w-4" />Clôturer</Button>
                    <Button v-if="can.cancel && !['RECEIVED', 'CLOSED', 'CANCELLED'].includes(order.status)" size="sm" variant="ghost" class="hover:text-destructive" @click="cancelling = true"><Ban class="h-4 w-4" />Annuler</Button>
                </div>
            </div>
            <p v-if="awaitingNote && awaitingGoods()" class="mt-4 flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-100">
                <Info class="mt-0.5 h-4 w-4" />{{ awaitingNote }}
            </p>

            <!--
                ADR-179 — la confirmation du fournisseur. Sans elle, la ligne ne
                dit pas « non confirmée » : elle dit que le fournisseur n'en a
                pas envoyé, ce qui est courant et n'empêche rien.
            -->
            <div v-if="links.confirm || order.supplier_confirmation" class="mt-4 rounded-lg border border-border bg-muted/30 px-4 py-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Confirmation du fournisseur</p>
                        <template v-if="order.supplier_confirmation">
                            <p class="mt-0.5 text-sm font-semibold text-foreground">
                                Confirmée le {{ formatDate(order.supplier_confirmation.confirmed_at) }}
                                <span v-if="order.supplier_confirmation.reference" class="font-mono font-normal text-muted-foreground"> · {{ order.supplier_confirmation.reference }}</span>
                            </p>
                            <p v-if="order.supplier_confirmation.notes" class="mt-0.5 text-sm text-muted-foreground">{{ order.supplier_confirmation.notes }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <span v-if="order.supplier_confirmation.recorded_by">Enregistrée par {{ order.supplier_confirmation.recorded_by }}</span>
                                <a
                                    v-if="order.supplier_confirmation.has_document && links.confirmationDocument"
                                    :href="links.confirmationDocument"
                                    class="ms-2 inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                                >
                                    <Paperclip class="h-3 w-3" />{{ order.supplier_confirmation.document_name || 'Document' }}
                                </a>
                            </p>
                        </template>
                        <p v-else class="mt-0.5 text-sm text-muted-foreground">
                            Aucune confirmation reçue. Beaucoup de fournisseurs n’en envoient pas : la commande se réceptionne sans.
                        </p>
                    </div>
                    <div v-if="can.confirm && links.confirm" class="flex shrink-0 items-center gap-2">
                        <Button size="sm" variant="white-outline" @click="openConfirm()">
                            <CheckCheck class="h-4 w-4" />{{ order.supplier_confirmation ? 'Corriger' : 'Enregistrer' }}
                        </Button>
                        <Button v-if="order.supplier_confirmation" size="sm" variant="ghost" class="hover:text-destructive" @click="withdrawConfirmation()">Retirer</Button>
                    </div>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[680px] text-sm">
                    <thead class="bg-muted text-xs font-semibold text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 text-start">Médicament</th>
                            <th class="px-4 py-3 text-end">Commandé</th>
                            <th class="px-4 py-3 text-end">Reçu</th>
                            <th class="px-4 py-3 text-end">Reste à recevoir</th>
                            <th class="px-4 py-3 text-end">Prix d’achat unitaire</th>
                            <th class="px-5 py-3 text-end">Total</th>
                            <th v-if="links.stock" class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="line in order.lines" :key="line.id">
                            <td class="px-5 py-3.5">
                                <span class="font-semibold text-foreground">{{ line.medicine_name }}</span> <span class="font-mono text-xs text-muted-foreground">{{ line.medicine_code }}</span>
                                <!-- ADR-179 — le fournisseur ne livrera pas ce qui reste. Rien n'est effacé. -->
                                <p v-if="line.shortage" class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-destructive">
                                    <span class="inline-flex items-center gap-1 font-semibold"><PackageX class="h-3.5 w-3.5" />En rupture</span>
                                    <span class="text-muted-foreground">{{ line.shortage.reason }}</span>
                                    <span v-if="line.shortage.by" class="text-muted-foreground">· {{ line.shortage.by }}, {{ formatDate(line.shortage.at) }}</span>
                                    <button
                                        v-if="can.shortage && links.shortage && order.status !== 'CANCELLED'"
                                        type="button"
                                        class="inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                                        @click="revertShortage(line)"
                                    >
                                        <Undo2 class="h-3 w-3" />Le fournisseur le livre finalement
                                    </button>
                                </p>
                            </td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ line.quantity_ordered }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums text-emerald-600">{{ line.quantity_received }}</td>
                            <td :class="['px-4 py-3.5 text-end font-semibold tabular-nums', line.quantity_remaining ? 'text-amber-600' : 'text-muted-foreground']">
                                <span v-if="line.shortage" class="text-muted-foreground">abandonné</span>
                                <span v-else>{{ line.quantity_remaining }}</span>
                            </td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ formatMoney(line.unit_price) }}</td>
                            <td class="px-5 py-3.5 text-end font-semibold tabular-nums">{{ formatMoney(line.line_total) }}</td>
                            <td v-if="links.stock" class="px-5 py-3.5 text-end"><Link :href="links.stock(line.medicine_uuid)" class="text-sm font-semibold text-primary hover:underline">Voir le stock</Link></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-border">
                            <td colspan="5" class="px-5 py-3 text-end text-sm text-muted-foreground">Total de la commande</td>
                            <td class="px-5 py-3 text-end text-base font-bold tabular-nums text-foreground">{{ formatMoney(order.total_amount) }}</td>
                            <td v-if="links.stock" />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <h2 class="font-heading text-base font-bold text-foreground">Réceptions</h2>
                <ul v-if="order.receipts.length" class="mt-3 divide-y divide-border">
                    <li v-for="receipt in order.receipts" :key="receipt.uuid">
                        <component :is="links.receipt ? Link : 'div'" :href="links.receipt ? links.receipt(receipt.uuid) : undefined" class="flex items-center justify-between gap-3 py-3 text-sm hover:text-primary">
                            <span><span class="font-mono font-semibold">{{ receipt.receipt_number }}</span> · {{ receipt.lines_count }} médicament{{ receipt.lines_count > 1 ? 's' : '' }}<span v-if="receipt.received_by"> · {{ receipt.received_by }}</span></span>
                            <span class="shrink-0 text-muted-foreground">{{ formatDateTime(receipt.received_at) }}</span>
                        </component>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-muted-foreground">Aucune marchandise reçue pour cette commande.</p>
            </section>

            <section class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <h2 class="font-heading text-base font-bold text-foreground">Factures du fournisseur</h2>
                <ul v-if="order.invoices.length" class="mt-3 divide-y divide-border">
                    <li v-for="invoice in order.invoices" :key="invoice.uuid">
                        <component :is="links.invoice ? Link : 'div'" :href="links.invoice ? links.invoice(invoice.uuid) : undefined" class="flex items-center justify-between gap-3 py-3 text-sm hover:text-primary">
                            <span class="font-mono font-semibold">{{ invoice.invoice_number }}</span>
                            <span class="tabular-nums text-muted-foreground">{{ formatMoney(invoice.total_amount) }}</span>
                        </component>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-muted-foreground">Aucune facture enregistrée pour cette commande.</p>
            </section>
        </div>

        <ConfirmModal
            :open="sending"
            title="Envoyer cette commande au fournisseur ?"
            confirm-label="Envoyer la commande"
            :icon="Send"
            :processing="sendProcessing"
            @update:open="sending = $event"
            @confirm="sendOrder"
        >
            <dl class="space-y-2 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Fournisseur</dt><dd class="font-semibold text-foreground">{{ order.supplier }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Produits</dt><dd class="font-semibold tabular-nums text-foreground">{{ order.lines.length }} ligne{{ order.lines.length > 1 ? 's' : '' }} · {{ unitCount }} unité{{ unitCount > 1 ? 's' : '' }}</dd></div>
                <div class="flex justify-between gap-3 border-t border-border pt-2"><dt class="text-muted-foreground">Montant total</dt><dd class="text-base font-bold tabular-nums text-foreground">{{ formatMoney(order.total_amount) }}</dd></div>
            </dl>
            <p class="mt-3 text-sm text-muted-foreground">Une fois envoyée, la commande ne peut plus être modifiée : seule une annulation motivée reste possible.</p>
        </ConfirmModal>

        <ConfirmModal
            :open="cancelling"
            :title="`Annuler la commande ${order.order_number} ?`"
            description="La commande reste visible dans l’historique, avec votre motif."
            confirm-label="Annuler la commande"
            cancel-label="Retour"
            tone="danger"
            :icon="Ban"
            :processing="cancelForm.processing"
            :disabled="cancelForm.reason.trim().length < 3"
            @update:open="cancelling = $event"
            @confirm="confirmCancel"
        >
            <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-foreground">Motif de l’annulation <span class="text-destructive">*</span></span>
                <Textarea v-model="cancelForm.reason" :rows="3" placeholder="Ex. le fournisseur ne peut pas livrer" />
            </label>
            <p v-if="cancelForm.errors.reason || cancelForm.errors.status || cancelForm.errors.site" class="mt-1.5 text-sm text-destructive">{{ cancelForm.errors.reason || cancelForm.errors.status || cancelForm.errors.site }}</p>
        </ConfirmModal>

        <!-- ADR-179 — la confirmation que le fournisseur a envoyée. -->
        <ConfirmModal
            v-if="links.confirm"
            :open="confirming"
            :title="order.supplier_confirmation ? 'Corriger la confirmation du fournisseur' : 'Enregistrer la confirmation du fournisseur'"
            description="Cette trace n’engage rien : la commande se réceptionne avec ou sans elle. Elle sert à distinguer les fournisseurs qui confirment de ceux qui ne confirment pas."
            confirm-label="Enregistrer"
            :icon="CheckCheck"
            :processing="confirmForm.processing"
            :disabled="!confirmForm.confirmed_at"
            @update:open="confirming = $event"
            @confirm="submitConfirm"
        >
            <div class="space-y-3">
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Date de la confirmation <span class="text-destructive">*</span></span>
                    <DatePicker v-model="confirmForm.confirmed_at" :max="new Date().toISOString().slice(0, 10)" />
                    <span class="mt-1 block text-xs text-muted-foreground">Celle du document du fournisseur, pas celle du jour de la saisie.</span>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Référence <span class="font-normal text-muted-foreground">(facultatif)</span></span>
                    <Input v-model="confirmForm.reference" maxlength="100" placeholder="Ex. AC-2026-118" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Remarque <span class="font-normal text-muted-foreground">(facultatif)</span></span>
                    <Textarea v-model="confirmForm.notes" :rows="2" placeholder="Ex. livraison annoncée sous 5 jours" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Document <span class="font-normal text-muted-foreground">(facultatif)</span></span>
                    <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.xlsx"
                        class="block w-full text-sm text-muted-foreground file:me-3 file:rounded-lg file:border-0 file:bg-muted file:px-3 file:py-2 file:text-sm file:font-semibold file:text-foreground"
                        @change="confirmForm.attachment = $event.target.files[0] ?? null"
                    >
                    <span v-if="order.supplier_confirmation?.has_document" class="mt-1 block text-xs text-muted-foreground">
                        Un document est déjà joint. En choisir un autre le remplace ; sans choix, il reste.
                    </span>
                </label>
                <p v-if="Object.keys(confirmForm.errors).length" class="text-sm text-destructive">
                    {{ Object.values(confirmForm.errors)[0] }}
                </p>
            </div>
        </ConfirmModal>

        <!-- ADR-179 — solder les reliquats d'une commande qu'on n'attend plus. -->
        <ConfirmModal
            v-if="links.close"
            :open="closing"
            :title="`Clôturer la commande ${order.order_number} ?`"
            description="Tous ses reliquats cessent d’être attendus et la commande quitte « À réceptionner ». Elle reste dans l’historique avec ce qui a été livré, son montant et ses factures — ce n’est pas une annulation."
            confirm-label="Clôturer la commande"
            cancel-label="Retour"
            :icon="CheckCheck"
            :processing="closeForm.processing"
            :disabled="closeForm.reason.trim().length < 3"
            @update:open="closing = $event"
            @confirm="confirmClose"
        >
            <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-foreground">Pourquoi n’attend-on plus rien ? <span class="text-destructive">*</span></span>
                <Textarea v-model="closeForm.reason" :rows="3" placeholder="Ex. le fournisseur a cessé son activité" />
            </label>
            <p v-if="closeForm.errors.reason || closeForm.errors.status" class="mt-1.5 text-sm text-destructive">{{ closeForm.errors.reason || closeForm.errors.status }}</p>
        </ConfirmModal>
    </div>
</template>
