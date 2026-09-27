<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import { Folder, Paperclip, Pencil, Plus, RotateCcw, Trash2, TriangleAlert } from 'lucide-vue-next';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    invoices: { type: Array, default: () => [] },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);

// Une facture est une pièce comptable : elle se corrige et se met à la
// corbeille, jamais elle ne se détruit (ADR-010). Le stock n'est pas touché.
const removing = ref(null);
const removeForm = useForm({ reason: '' });
const askRemove = (invoice) => { removeForm.reset(); removeForm.clearErrors(); removing.value = invoice; };
const confirmRemove = () => removeForm.delete(`${folderHref.value}/invoices/${removing.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { removing.value = null; },
});
const restore = (invoice) => useForm({}).post(`${folderHref.value}/invoices/${invoice.uuid}/restore`, { preserveScroll: true });
</script>

<template>
    <Head :title="supplier ? `Factures · ${supplier.name}` : 'Factures'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Factures' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" /><p>{{ error }}</p>
        </section>

        <template v-else-if="supplier">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <Folder class="h-11 w-11 shrink-0 fill-sky-200 text-sky-500 dark:fill-sky-500/20" />
                    <div>
                        <h1 class="font-heading text-2xl font-bold text-foreground">Factures</h1>
                        <p class="text-sm text-muted-foreground">{{ supplier.name }} · {{ targetSite.name }}. Une facture ne modifie jamais le stock.</p>
                    </div>
                </div>
                <Button v-if="can.create_invoice" :as="Link" :href="`${folderHref}/invoices/create`" size="rg"><Plus class="h-4 w-4" />Enregistrer une facture</Button>
            </div>

            <ExplorerView storage-key="portal-invoices" :count="invoices.length" count-label="facture" empty-icon="file-text" empty-title="Aucune facture" empty-description="Aucune facture de ce fournisseur n’est enregistrée sur ce site.">
                <template #grid>
                    <ExplorerTile
                        v-for="invoice in invoices"
                        :key="invoice.uuid"
                        :href="`${folderHref}/invoices/${invoice.uuid}`"
                        icon="file-text"
                        tone="violet"
                        :badge="invoice.has_attachment ? 'Document' : null"
                        :title="invoice.invoice_number"
                        :highlight="formatMoney(invoice.total_amount)"
                        :meta="formatDate(invoice.invoice_date)"
                    />
                </template>
                <template #list>
                    <table class="w-full min-w-[520px] text-sm">
                        <thead class="bg-muted text-xs font-semibold text-muted-foreground">
                            <tr>
                                <th class="px-5 py-3 text-start">Facture</th>
                                <th class="px-4 py-3 text-start">Date</th>
                                <th class="px-4 py-3 text-end">Montant</th>
                                <th class="px-4 py-3 text-center">Document</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="invoice in invoices" :key="invoice.uuid" class="transition-colors hover:bg-accent/50">
                                <td class="px-5 py-3.5 font-mono font-semibold text-foreground">{{ invoice.invoice_number }}</td>
                                <td class="px-4 py-3.5 text-muted-foreground">{{ formatDate(invoice.invoice_date) }}</td>
                                <td class="px-4 py-3.5 text-end tabular-nums">{{ formatMoney(invoice.total_amount) }}</td>
                                <td class="px-4 py-3.5 text-center"><Paperclip class="text-muted-foreground h-4.5 w-4.5" v-if="invoice.has_attachment" /><span v-else class="text-muted-foreground">—</span></td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Button :as="Link" :href="`${folderHref}/invoices/${invoice.uuid}`" size="sm" variant="white-outline">Voir</Button>
                                        <template v-if="!invoice.archived">
                                            <Button v-if="can.update_invoice" :as="Link" :href="`${folderHref}/invoices/${invoice.uuid}/edit`" size="sm" variant="white-outline" :title="`Modifier ${invoice.invoice_number}`"><Pencil class="h-4 w-4" /></Button>
                                            <Button v-if="can.archive_invoice" size="sm" variant="white-outline" type="button" class="text-red-600" :title="`Mettre ${invoice.invoice_number} à la corbeille`" @click="askRemove(invoice)"><Trash2 class="h-4 w-4" /></Button>
                                        </template>
                                        <Button v-else-if="can.restore_invoice" size="sm" variant="white-outline" type="button" :title="`Restaurer ${invoice.invoice_number}`" @click="restore(invoice)"><RotateCcw class="h-4 w-4" /></Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ExplorerView>
        </template>

        <Dialog
            :open="removing !== null"
            title="Mettre la facture à la corbeille"
            :description="removing ? `${removing.invoice_number} quittera le dossier. Ce n’est pas une suppression : elle se restaure, et le stock n’est jamais touché par une facture.` : ''"
            @update:open="removing = $event ? removing : null"
        >
            <Textarea v-model="removeForm.reason" :rows="3" placeholder="Motif du retrait" />
            <p v-if="removeForm.errors.reason" class="mt-1.5 text-sm text-destructive">{{ removeForm.errors.reason }}</p>
            <template #footer>
                <Button type="button" variant="outline" @click="removing = null">Revenir</Button>
                <Button type="button" variant="destructive" :disabled="removeForm.processing || removeForm.reason.trim().length < 3" @click="confirmRemove"><Trash2 class="h-4 w-4" />Mettre à la corbeille</Button>
            </template>
        </Dialog>
    </div>
</template>
