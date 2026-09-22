<script setup>
import { computed, nextTick } from 'vue';
import { Minus, Pencil, Plus, Sparkles, X } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-175 — le tableau d'entrée en stock, le même pour la marchandise
 * réceptionnée et pour une entrée sans commande : une ligne par produit,
 * tout se corrige sur place, rien ne s'ouvre ailleurs.
 *
 * Entrée passe à la même colonne de la ligne suivante, Tab à la case
 * suivante : on remplit une colonne entière sans lâcher le clavier.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    // received : lignes cochables venues d'une réception ; manual : lignes ajoutées.
    mode: { type: String, default: 'received' },
    canSetSalePrice: { type: Boolean, default: false },
    canRename: { type: Boolean, default: false },
    canSeeCost: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

// Les deux modes se cochent : la marchandise réceptionnée arrive cochée,
// le catalogue complet arrive décoché et on coche ce qui est arrivé.
const allSelected = computed({
    get: () => props.rows.length > 0 && props.rows.every((row) => row.selected),
    set: (value) => props.rows.forEach((row) => { row.selected = value; }),
});
// Une ligne décochée du catalogue n'affiche aucun champ : la liste reste
// lisible, et cocher ouvre la saisie de cette ligne-là.
const editable = (row) => props.mode === 'received' || row.selected;
const today = new Date().toISOString().slice(0, 10);

const knownLot = (row) => (row.known_lots ?? []).find(
    (lot) => lot.lot_number.toLocaleLowerCase() === String(row.lot_number ?? '').trim().toLocaleLowerCase(),
);
/*
 * Le n° de lot et la péremption se lisent sur la boîte : le système ne les
 * invente jamais (ADR-175). La seule chose qu'il sache, c'est un lot que la
 * pharmacie tient déjà — sa péremption est alors un fait enregistré, pas une
 * supposition, et le champ se remplit tout seul.
 *
 * `@input` sur un composant est écouté avant que `v-model` ait posé la
 * nouvelle valeur : sans ce `nextTick`, on cherchait le lot précédent, et le
 * remplissage ne marchait jamais à la première frappe.
 */
const onLotInput = async (row) => {
    await nextTick();
    const lot = knownLot(row);
    if (lot?.expires_at) row.expires_at = lot.expires_at;
};

const step = (row, delta) => {
    const next = Math.max(1, (Number(row.quantity) || 0) + delta);
    row.quantity = row.max_quantity ? Math.min(next, row.max_quantity) : next;
};
const overMax = (row) => row.max_quantity && Number(row.quantity) > row.max_quantity;
const priceChanged = (row) => row.sale_price !== '' && row.sale_price !== null
    && row.current_sale_price !== null && Number(row.sale_price) !== Number(row.current_sale_price);
const missingPrice = (row) => row.current_sale_price === null && (row.sale_price === '' || row.sale_price === null);
const rowErrors = (row) => props.errors[row.key] ?? [];
const rowDisabled = (row) => !row.selected;

// Entrée : même colonne, ligne suivante.
const onEnter = (event, index, column) => {
    event.preventDefault();
    const next = document.querySelector(`[data-entry-cell="${index + 1}-${column}"]`);
    if (next) {
        next.focus();
        next.select?.();
    }
};

const cellInput = 'h-9 text-sm';
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[880px] text-sm">
            <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th class="w-10 px-4 py-3 text-start">
                        <Checkbox v-model="allSelected" aria-label="Tout cocher" />
                    </th>
                    <th class="px-4 py-3 text-start">Produit</th>
                    <th class="w-40 px-3 py-3 text-start">N° de lot</th>
                    <th class="w-40 px-3 py-3 text-start">Péremption</th>
                    <th class="w-40 px-3 py-3 text-start">Quantité</th>
                    <th v-if="canSeeCost" class="w-32 px-3 py-3 text-end">Prix d’achat</th>
                    <th v-if="canSetSalePrice" class="w-40 px-3 py-3 text-start">Prix de vente</th>

                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <template v-for="(row, index) in rows" :key="row.key">
                    <tr :class="cn('align-top transition-colors', rowDisabled(row) ? 'bg-card hover:bg-muted/20' : 'bg-primary/[0.04]', rowErrors(row).length && 'bg-red-50/50 dark:bg-red-950/10')">
                        <td class="px-4 py-3.5">
                            <Checkbox v-model="row.selected" :aria-label="`Entrer ${row.name} en stock`" />
                        </td>

                        <td class="px-4 py-3" @click="row.selected || (row.selected = true)">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-foreground">{{ row.sale_name?.trim() || row.name }}</p>
                                <Badge v-if="row.is_new" tone="warning"><Sparkles class="h-3 w-3" />Nouveau produit</Badge>
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <span class="font-mono">{{ row.code }}</span>
                                <span v-if="row.unit"> · {{ row.unit }}</span>
                                <span v-if="row.receipt_number"> · réception {{ row.receipt_number }}</span>
                            </p>
                            <p v-if="row.sale_name?.trim() && row.sale_name.trim() !== row.name" class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">Vendu sous ce nom · fournisseur : « {{ row.name }} »</p>
                            <p v-if="row.notes" class="mt-1 text-xs italic text-muted-foreground">« {{ row.notes }} »</p>

                            <div v-if="canRename && editable(row)" class="mt-1.5">
                                <div v-if="row.renaming" class="flex max-w-sm items-center gap-1.5">
                                    <Input v-model="row.sale_name" :class="cellInput" maxlength="255" :placeholder="row.name" aria-label="Nom à la pharmacie" />
                                    <Button type="button" size="icon-xs" variant="ghost" aria-label="Garder le nom du fournisseur" @click="row.sale_name = ''; row.renaming = false"><X class="h-3.5 w-3.5" /></Button>
                                </div>
                                <button v-else type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline" @click="row.renaming = true">
                                    <Pencil class="h-3 w-3" />{{ row.is_new ? 'Donner son nom à la pharmacie' : 'Vendre sous un autre nom' }}
                                </button>
                            </div>
                        </td>

                        <td class="px-3 py-3">
                            <span v-if="!editable(row)" class="text-sm text-muted-foreground">—</span>
                            <Input
                                v-else
                                v-model="row.lot_number"
                                :class="cn(cellInput, 'font-mono')"
                                maxlength="100"
                                :list="row.known_lots?.length ? `lots-${row.key}` : undefined"
                                :disabled="rowDisabled(row)"
                                :data-entry-cell="`${index}-lot`"
                                placeholder="Lu sur la boîte"
                                @input="onLotInput(row)"
                                @keydown.enter="onEnter($event, index, 'lot')"
                            />
                            <datalist v-if="row.known_lots?.length" :id="`lots-${row.key}`"><option v-for="lot in row.known_lots" :key="lot.uuid" :value="lot.lot_number" /></datalist>
                            <p v-if="knownLot(row)" class="mt-1 text-[11px] text-emerald-600 dark:text-emerald-400">Lot connu : s’y ajoute</p>
                        </td>

                        <td class="px-3 py-3">
                            <span v-if="!editable(row)" class="text-sm text-muted-foreground">—</span>
                            <DatePicker
                                v-else
                                v-model="row.expires_at"
                                :min="today"
                                size="sm"
                                :readonly="Boolean(knownLot(row))"
                                :disabled="rowDisabled(row)"
                            />
                        </td>

                        <td class="px-3 py-3">
                            <span v-if="!editable(row)" class="text-sm text-muted-foreground">—</span>
                            <div v-else class="flex items-center">
                                <Button type="button" size="icon-xs" variant="outline" class="rounded-e-none" :disabled="rowDisabled(row) || Number(row.quantity) <= 1" aria-label="Moins" tabindex="-1" @click="step(row, -1)"><Minus class="h-3 w-3" /></Button>
                                <input
                                    v-model.number="row.quantity"
                                    type="number"
                                    min="1"
                                    :max="row.max_quantity || undefined"
                                    :disabled="rowDisabled(row)"
                                    :data-entry-cell="`${index}-qty`"
                                    :class="cn('h-7 w-16 border-y border-input bg-card text-center text-sm font-semibold tabular-nums text-foreground outline-none focus:border-primary', overMax(row) && 'border-red-400 text-red-600')"
                                    @keydown.enter="onEnter($event, index, 'qty')"
                                >
                                <Button type="button" size="icon-xs" variant="outline" class="rounded-s-none" :disabled="rowDisabled(row) || (row.max_quantity && Number(row.quantity) >= row.max_quantity)" aria-label="Plus" tabindex="-1" @click="step(row, 1)"><Plus class="h-3 w-3" /></Button>
                            </div>
                            <p v-if="row.max_quantity && editable(row)" :class="cn('mt-1 text-[11px]', overMax(row) ? 'text-red-600' : 'text-muted-foreground')">
                                {{ overMax(row) ? `Plus que commandé (${row.max_quantity} au plus)` : `${row.max_quantity} au plus` }}
                            </p>
                        </td>

                        <td v-if="canSeeCost" class="px-3 py-3.5 text-end tabular-nums text-muted-foreground">
                            {{ row.unit_purchase_price ? formatMoney(row.unit_purchase_price) : '—' }}
                        </td>

                        <td v-if="canSetSalePrice" class="px-3 py-3">
                            <span v-if="!editable(row)" class="block text-end text-sm text-muted-foreground">{{ row.current_sale_price ? formatMoney(row.current_sale_price) : 'À fixer' }}</span>
                            <div v-else class="relative">
                                <Input
                                    v-model="row.sale_price"
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    :class="cn(cellInput, 'pe-11 text-end tabular-nums', missingPrice(row) && 'border-amber-400')"
                                    :placeholder="missingPrice(row) ? 'À fixer' : ''"
                                    :disabled="rowDisabled(row)"
                                    :data-entry-cell="`${index}-price`"
                                    @keydown.enter="onEnter($event, index, 'price')"
                                />
                                <span class="pointer-events-none absolute inset-y-0 end-2.5 flex items-center text-[11px] text-muted-foreground">MGA</span>
                            </div>
                            <p v-if="missingPrice(row) && editable(row)" class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">Sans prix, invendable</p>
                            <p v-else-if="priceChanged(row) && editable(row)" class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">Était {{ formatMoney(row.current_sale_price) }}</p>
                        </td>

                    </tr>
                    <tr v-if="rowErrors(row).length">
                        <td :colspan="7" class="bg-red-50/50 px-4 pb-3 text-xs font-medium text-red-700 dark:bg-red-950/10 dark:text-red-300">{{ rowErrors(row).join(' ') }}</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
