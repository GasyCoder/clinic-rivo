<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/UI/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDate } from '@/utilities/date';
import { formatNumber, statusTone } from '@/utilities/pharmacyStatus';
import { escapeHtml, openPrintWindow, writeAndPrint } from '@/utilities/printWindow';

defineOptions({ layout: AppLayout });

/*
 * ADR-098 — the counting sheet. The pharmacist writes what is on the shelf;
 * only the lots whose count differs become « Inventaire » adjustments, all
 * validated together. A count below the reserved quantity is refused.
 */
const props = defineProps({
    capabilities: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    lots: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');
const family = ref('');
const onlyCounted = ref(false);
const counts = reactive(Object.fromEntries(props.lots.map((lot) => [lot.uuid, ''])));

const isCounted = (lot) => counts[lot.uuid] !== '' && counts[lot.uuid] !== null;
const gap = (lot) => (isCounted(lot) ? Number(counts[lot.uuid]) - lot.quantity_on_hand : null);

const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return props.lots.filter((lot) => (!family.value || lot.category === family.value)
        && (!onlyCounted.value || isCounted(lot))
        && (!needle || [lot.medicine_name, lot.medicine_code, lot.lot_number].some((value) => value?.toLocaleLowerCase().includes(needle))));
});

const counted = computed(() => props.lots.filter(isCounted));
const differences = computed(() => counted.value.filter((lot) => gap(lot) !== 0));

const fillVisibleWithRecorded = () => {
    visible.value.forEach((lot) => {
        if (!isCounted(lot)) counts[lot.uuid] = lot.quantity_on_hand;
    });
};

const form = useForm({ counts: [], reason: '' });
const submittedUuids = ref([]);
const errorFor = (lot) => {
    const index = submittedUuids.value.indexOf(lot.uuid);

    return index === -1 ? null : form.errors[`counts.${index}.counted_quantity`] ?? null;
};

const confirming = ref(false);
const submit = () => {
    submittedUuids.value = counted.value.map((lot) => lot.uuid);

    form.transform((data) => ({
        reason: data.reason,
        counts: counted.value.map((lot) => ({ lot_uuid: lot.uuid, counted_quantity: Number(counts[lot.uuid]) })),
    })).post('/pharmacy/stock/inventory', {
        preserveScroll: true,
        onSuccess: () => { confirming.value = false; },
        onError: () => { confirming.value = false; },
    });
};

const printSheet = () => {
    const printWindow = openPrintWindow('Feuille d’inventaire');
    if (!printWindow) return;

    const rows = visible.value.map((lot) => `<tr>
        <td>${escapeHtml(lot.medicine_name)}<br><small>${escapeHtml(lot.medicine_code)}</small></td>
        <td>${escapeHtml(lot.lot_number)}</td>
        <td>${escapeHtml(lot.expires_at ? formatDate(lot.expires_at) : '—')}</td>
        <td class="n">${lot.quantity_on_hand}</td>
        <td class="n">${lot.reserved_quantity}</td>
        <td class="blank"></td>
    </tr>`).join('');

    writeAndPrint(printWindow, `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Feuille d’inventaire</title>
<style>@page{size:A4;margin:10mm}body{font-family:Arial,sans-serif;font-size:10pt;color:#111}h1{font-size:14pt;margin:0 0 2mm}p{margin:0 0 4mm;color:#555}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:2mm;text-align:left;vertical-align:top}th{background:#eee}
td.n{text-align:right}td.blank{width:28mm}small{color:#666}tr{page-break-inside:avoid}</style></head><body>
<h1>Feuille d’inventaire — ${escapeHtml(page.props.site?.name ?? '')}</h1>
<p>Imprimée le ${escapeHtml(new Date().toLocaleString('fr-FR'))} · ${visible.value.length} lot(s)${family.value ? ` · famille ${escapeHtml(family.value)}` : ''}. Comptez ce qui est sur l’étagère et notez-le dans la dernière colonne.</p>
<table><thead><tr><th>Médicament</th><th>Lot</th><th>Péremption</th><th>Enregistré</th><th>Réservé</th><th>Compté</th></tr></thead><tbody>${rows}</tbody></table>
</body></html>`);
};

const inputClass = 'h-10 w-24 rounded-lg border border-gray-200 bg-white px-2 text-end text-sm tabular-nums outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <Head title="Inventaire" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Médicaments & stock', href: '/pharmacy/stock' }, { label: 'Inventaire' }]" />

        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Inventaire</h1>
                <p class="mt-1 text-sm text-slate-500">Comptez ce qui est réellement sur l’étagère et saisissez-le. Seuls les lots avec un écart sont corrigés, tous en une fois ; chaque correction est tracée avec votre motif.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button type="button" size="rg" variant="white-outline" :disabled="!visible.length" @click="printSheet"><Icon name="printer" /><span class="ms-2">Imprimer la feuille</span></Button>
            </div>
        </div>

        <EmptyState v-if="!capabilities.can_view_lots" icon="lock" title="Lots non accessibles" description="Votre compte n’a pas l’autorisation de consulter les lots, nécessaire pour faire un inventaire." />

        <template v-else>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950"><p class="text-2xl font-bold tabular-nums text-slate-800 dark:text-white">{{ lots.length }}</p><p class="text-sm text-slate-500">lots à compter</p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950"><p class="text-2xl font-bold tabular-nums text-slate-800 dark:text-white">{{ counted.length }}</p><p class="text-sm text-slate-500">déjà comptés</p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950"><p :class="['text-2xl font-bold tabular-nums', differences.length ? 'text-amber-600' : 'text-slate-800 dark:text-white']">{{ differences.length }}</p><p class="text-sm text-slate-500">avec un écart</p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950"><p class="text-2xl font-bold tabular-nums text-slate-800 dark:text-white">{{ counted.length - differences.length }}</p><p class="text-sm text-slate-500">conformes</p></div>
            </div>

            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 lg:flex-row lg:items-center">
                    <label class="relative block flex-1">
                        <span class="sr-only">Rechercher</span>
                        <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" />
                        <input v-model="search" type="search" class="h-10 w-full rounded-lg border border-gray-200 bg-gray-50 ps-10 pe-3 text-sm outline-none focus:border-primary-500 focus:bg-white dark:border-gray-800 dark:bg-gray-900 dark:text-white" placeholder="Médicament, code ou lot…">
                    </label>
                    <select v-if="categories.length" v-model="family" class="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white lg:w-60" aria-label="Filtrer par famille">
                        <option value="">Toutes les familles</option>
                        <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                    </select>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><input v-model="onlyCounted" type="checkbox" class="h-4 w-4 rounded border-gray-300">Seulement les lots comptés</label>
                    <Button type="button" size="rg" variant="white-outline" :disabled="!visible.length" title="Remplit les lots non encore comptés avec le stock enregistré : il ne reste qu’à corriger les écarts." @click="fillVisibleWithRecorded">Pré-remplir avec le stock enregistré</Button>
                </div>

                <div v-if="visible.length" class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                            <tr>
                                <th class="px-5 py-3 text-start">Médicament</th>
                                <th class="px-4 py-3 text-start">Lot</th>
                                <th class="px-4 py-3 text-start">Péremption</th>
                                <th class="px-4 py-3 text-end">Enregistré</th>
                                <th class="px-4 py-3 text-end">Réservé</th>
                                <th class="px-4 py-3 text-end">Compté</th>
                                <th class="px-4 py-3 text-end">Écart</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                            <template v-for="lot in visible" :key="lot.uuid">
                                <tr :class="gap(lot) ? 'bg-amber-50/40 dark:bg-amber-950/10' : ''">
                                    <td class="px-5 py-3">
                                        <p class="font-semibold text-slate-800 dark:text-white">{{ lot.medicine_name }}</p>
                                        <p class="font-mono text-xs text-slate-400">{{ lot.medicine_code }}</p>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-slate-700 dark:text-slate-200">{{ lot.lot_number }}</td>
                                    <td class="px-4 py-3"><span class="text-slate-600 dark:text-slate-300">{{ lot.expires_at ? formatDate(lot.expires_at) : '—' }}</span> <Badge v-if="lot.status === 'EXPIRED' || lot.status === 'EXPIRING_SOON'" :tone="statusTone(lot.status)">{{ lot.status_label }}</Badge></td>
                                    <td class="px-4 py-3 text-end tabular-nums">{{ formatNumber(lot.quantity_on_hand) }} <span class="text-xs text-slate-400">{{ lot.unit }}</span></td>
                                    <td class="px-4 py-3 text-end tabular-nums text-slate-500">{{ formatNumber(lot.reserved_quantity) }}</td>
                                    <td class="px-4 py-3 text-end">
                                        <input v-model="counts[lot.uuid]" type="number" min="0" step="1" :class="[inputClass, errorFor(lot) && 'border-red-400']" :aria-label="`Quantité comptée, lot ${lot.lot_number}`">
                                    </td>
                                    <td :class="['px-4 py-3 text-end font-bold tabular-nums', gap(lot) > 0 ? 'text-emerald-600' : gap(lot) < 0 ? 'text-red-600' : 'text-slate-400']">
                                        {{ gap(lot) === null ? '—' : (gap(lot) > 0 ? `+${gap(lot)}` : gap(lot)) }}
                                    </td>
                                    <td class="px-5 py-3 text-end">
                                        <button v-if="isCounted(lot)" type="button" class="text-xs font-bold text-slate-500 hover:text-slate-700" @click="counts[lot.uuid] = ''">Effacer</button>
                                        <Link v-else :href="`/pharmacy/stock/${lot.medicine_uuid}`" class="text-xs font-bold text-primary-600 hover:underline">Voir</Link>
                                    </td>
                                </tr>
                                <tr v-if="errorFor(lot) || (isCounted(lot) && Number(counts[lot.uuid]) < lot.reserved_quantity)">
                                    <td colspan="8" class="bg-red-50/60 px-5 pb-3 text-xs text-red-700 dark:bg-red-950/10 dark:text-red-300">
                                        {{ errorFor(lot) || `Le comptage est inférieur aux ${lot.reserved_quantity} unité(s) réservée(s) pour des ordonnances : il sera refusé. Vérifiez le comptage ou traitez d’abord ces ordonnances.` }}
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else icon="package" title="Aucun lot à afficher" description="Modifiez la recherche ou le filtre." />
            </section>

            <section class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950 lg:flex-row lg:items-end">
                <label class="block flex-1">
                    <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif de l’inventaire <span class="text-red-500">*</span></span>
                    <input v-model="form.reason" type="text" maxlength="2000" class="h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Ex. inventaire mensuel de septembre">
                    <span v-if="form.errors.reason || form.errors.counts" class="mt-1 block text-xs text-red-600">{{ form.errors.reason || form.errors.counts }}</span>
                </label>
                <div class="flex gap-2">
                    <Button :as="Link" href="/pharmacy/stock" size="lg" variant="white-outline">Annuler</Button>
                    <Button type="button" size="lg" :disabled="form.processing || !counted.length || form.reason.trim().length < 3" @click="confirming = true">
                        <Icon name="check" /><span class="ms-2">{{ form.processing ? 'Validation…' : `Valider l’inventaire (${counted.length})` }}</span>
                    </Button>
                </div>
            </section>
        </template>

        <ConfirmModal
            v-model:open="confirming"
            title="Valider l’inventaire ?"
            description="Chaque écart devient une correction de stock tracée, avec son motif. Un lot conforme ne crée aucun mouvement."
            confirm-label="Valider l’inventaire"
            tone="warning"
            :processing="form.processing"
            @confirm="submit"
        >
            <dl class="divide-y divide-border rounded-xl border border-border text-sm">
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Lots comptés</dt><dd class="font-semibold tabular-nums text-foreground">{{ counted.length }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Avec un écart</dt><dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ differences.length }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Motif</dt><dd class="text-end font-semibold text-foreground">{{ form.reason }}</dd></div>
            </dl>
        </ConfirmModal>
    </div>
</template>
