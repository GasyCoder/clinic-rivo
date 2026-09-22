<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Receipt } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import SupplierInvoiceFields from '@/Components/Pharmacy/SupplierInvoiceFields.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney, formatNumber } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/*
 * ADR-175 — la facture d'une réception enregistrée sans elle. Mêmes champs
 * que dans l'assistant de réception : un seul formulaire pour les deux
 * moments, jamais deux qui divergent.
 */
const props = defineProps({ receipt: Object });

const today = new Date().toISOString().slice(0, 10);
const confirming = ref(false);
const form = useForm({
    invoice_number: '',
    invoice_date: today,
    due_date: '',
    total_amount: props.receipt.proposed_total ?? '',
    notes: '',
    attachment: null,
});

const ready = computed(() => form.invoice_number.trim() && Number(form.total_amount) > 0);
const submit = () => form.post(`/pharmacy/receipts/${props.receipt.uuid}/invoice`, {
    forceFormData: true,
    onError: () => { confirming.value = false; },
});
</script>

<template>
    <Head :title="`Facture de la réception ${receipt.receipt_number}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Achats', href: '/pharmacy/purchase-orders' },
            { label: 'Réceptions', href: '/pharmacy/receipts' },
            { label: receipt.receipt_number, href: `/pharmacy/receipts/${receipt.uuid}` },
            { label: 'Facture' },
        ]" />

        <PageHeader
            eyebrow="Achats"
            title="Facture du fournisseur"
            :description="`Réception ${receipt.receipt_number} · commande ${receipt.order_number} · ${receipt.supplier}. Une facture est une pièce comptable : elle ne touche jamais le stock.`"
            icon="money"
            tone="emerald"
        />

        <form class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]" @submit.prevent="confirming = true">
            <section class="rounded-2xl border border-border bg-card p-5 shadow-sm">
                <SupplierInvoiceFields :form="form" :errors="form.errors" :proposed-total="receipt.proposed_total" />
            </section>

            <aside class="space-y-4 rounded-2xl border border-border bg-card p-5 shadow-sm xl:sticky xl:top-20">
                <div>
                    <h2 class="font-heading text-base font-bold text-foreground">La livraison</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Reçue le</dt><dd class="text-end font-semibold text-foreground">{{ formatDateTime(receipt.received_at) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Produits</dt><dd class="font-semibold text-foreground">{{ receipt.lines_count }} · {{ formatNumber(receipt.units) }} unité{{ receipt.units > 1 ? 's' : '' }}</dd></div>
                        <div v-if="receipt.proposed_total" class="flex justify-between gap-3"><dt class="text-muted-foreground">Valeur reçue</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(receipt.proposed_total) }}</dd></div>
                    </dl>
                </div>
                <div class="space-y-2 border-t border-border pt-4">
                    <Button type="submit" class="w-full" size="lg" :disabled="!ready || form.processing"><Receipt class="h-4 w-4" />Enregistrer la facture</Button>
                    <Button :as="Link" :href="`/pharmacy/receipts/${receipt.uuid}`" variant="outline" class="w-full">Annuler</Button>
                </div>
            </aside>
        </form>

        <ConfirmModal
            v-model:open="confirming"
            title="Enregistrer cette facture ?"
            description="Elle est rattachée à cette réception et à sa commande. Le stock n’est pas touché."
            confirm-label="Enregistrer la facture"
            tone="success"
            :processing="form.processing"
            @confirm="submit"
        >
            <dl class="divide-y divide-border rounded-xl border border-border text-sm">
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Fournisseur</dt><dd class="text-end font-semibold text-foreground">{{ receipt.supplier }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Facture</dt><dd class="font-semibold text-foreground">{{ form.invoice_number }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Montant</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(form.total_amount) }}</dd></div>
            </dl>
        </ConfirmModal>
    </div>
</template>
