<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import SurgerySection from '@/Components/Surgery/SurgerySection.vue';
import FormError from '@/Components/UI/FormError.vue';
import { formatDateTime } from '@/utilities/date';
import { AlertTriangle, Ban, ClipboardList, Minus, NotebookPen, Package, PackageCheck, Plus, Search, Send, Trash2, X } from 'lucide-vue-next';

/**
 * ADR-169 — le matériel utilisé au bloc, relié au stock de la Pharmacie.
 *
 * ```text
 * stock       produit de la Pharmacie → la Pharmacie le sort du stock sans attendre
 *             le règlement, la ligne rejoint le compte du patient (Caisse)
 * hors stock  saisie libre, pour un produit absent du stock : tracée, ni stock ni facture
 * ```
 *
 * Le matériel habituel de l'intervention (configuré au référentiel) est proposé
 * à l'ouverture ; l'équipe le confirme, le corrige ou le retire. Aucun prix ici :
 * le bloc ne voit ni ne saisit de montant (ADR-036).
 */
const props = defineProps({
    surgicalRequest: { type: Object, required: true },
    /** Demandes transmises à la Pharmacie pour ce dossier. */
    requests: { type: Array, default: () => [] },
    /** Produits déclarables : parapharmacie + matériel configuré pour un acte de Chirurgie. */
    catalog: { type: Array, default: () => [] },
    /** Matériel habituel de l'intervention demandée. */
    suggestions: { type: Array, default: () => [] },
    canDeclare: { type: Boolean, default: false },
});

const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const freeLines = computed(() => props.surgicalRequest.consumables ?? []);
const catalogByUuid = computed(() => Object.fromEntries(props.catalog.map((item) => [item.medicine_uuid, item])));
const lineCount = computed(() => props.requests.reduce((sum, request) => sum + request.lines.length, 0) + freeLines.value.length);

// ── Déclarer du matériel du stock ──────────────────────────────────────────
const open = ref(false);
const form = useForm({ lines: [], notes: '' });
const search = ref('');

const openForm = () => {
    form.clearErrors();
    form.lines = props.suggestions
        .filter((suggestion) => catalogByUuid.value[suggestion.medicine_uuid])
        .map((suggestion) => ({ medicine_uuid: suggestion.medicine_uuid, quantity: Math.max(1, suggestion.default_quantity), suggested: true }));
    form.notes = '';
    search.value = '';
    open.value = true;
};
const closeForm = () => { open.value = false; form.reset(); form.clearErrors(); };

const plain = (text) => String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const results = computed(() => {
    const term = plain(search.value).trim();
    if (!term) return [];

    return props.catalog
        .filter((item) => plain(`${item.name} ${item.code}`).includes(term))
        .slice(0, 8);
});
const inBasket = (uuid) => form.lines.some((line) => line.medicine_uuid === uuid);
const add = (item) => {
    if (!inBasket(item.medicine_uuid)) form.lines.push({ medicine_uuid: item.medicine_uuid, quantity: 1, suggested: false });
    search.value = '';
};
const remove = (uuid) => { form.lines = form.lines.filter((line) => line.medicine_uuid !== uuid); };
const step = (line, delta) => { line.quantity = Math.max(1, Math.min(999, Number(line.quantity || 1) + delta)); };
const item = (line) => catalogByUuid.value[line.medicine_uuid];
/** « 4 paquet » : l'espace est composé ici, un espace de gabarit disparaît au rendu. */
const quantityLabel = (quantity, unit) => [quantity, unit].filter((part) => part !== null && part !== undefined && part !== '').join(' ');
/** Plus demandé que le stock enregistré : la Pharmacie devra ajuster avant de servir (ADR-072). */
const short = (line) => (item(line) ? Number(line.quantity) > item(line).available_quantity : false);

const canSubmit = computed(() => form.lines.length > 0 && form.lines.every((line) => Number(line.quantity) >= 1) && !form.processing);
const submit = () => form
    .transform((data) => ({
        lines: data.lines.map((line) => ({ medicine_uuid: line.medicine_uuid, quantity: Number(line.quantity) })),
        notes: String(data.notes ?? '').trim() || null,
    }))
    .post(`${base.value}/consumable-requests`, { preserveScroll: true, onSuccess: closeForm });

// ── Annuler une demande tant que rien n'est sorti du stock ─────────────────
const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });
const askCancel = (request) => { cancelForm.clearErrors(); cancelForm.reason = ''; cancelling.value = request; };
const confirmCancel = () => cancelForm.post(`${base.value}/consumable-requests/${cancelling.value.uuid}/cancel`, {
    preserveScroll: true,
    onSuccess: () => { cancelling.value = null; },
});

// ── Ligne hors stock (saisie libre) ────────────────────────────────────────
const freeOpen = ref(false);
const freeForm = useForm({ label: '', quantity: 1, unit: '' });
const submitFree = () => freeForm.post(`${base.value}/consumables`, {
    preserveScroll: true,
    onSuccess: () => { freeForm.reset(); freeOpen.value = false; },
});
const removingFree = ref(null);
const removeFree = (line) => {
    removingFree.value = line.id;
    router.delete(`${base.value}/consumables/${line.id}`, { preserveScroll: true, onFinish: () => { removingFree.value = null; } });
};

const statusVariant = (status) => ({ SERVED: 'success', CANCELLED: 'outline', PARTIALLY_SERVED: 'warning' }[status] ?? 'warning');
</script>

<template>
    <SurgerySection
        :icon="Package"
        title="Consommables"
        description="Produits du stock Pharmacie utilisés au bloc : la Pharmacie les sort du stock, la Caisse les facture au patient."
    >
        <template #badge>
            <Badge variant="secondary">{{ lineCount }}</Badge>
        </template>
        <template #actions>
            <Button v-if="canDeclare && !open" size="sm" variant="white-outline" type="button" @click="openForm"><Plus class="h-3.5 w-3.5" />Déclarer du matériel</Button>
        </template>

        <!-- Déclaration : recherche dans le stock, panier, transmission à la Pharmacie. -->
        <div v-if="open" class="mb-5 space-y-3 rounded-xl border border-border bg-muted/20 p-3">
            <p v-if="suggestions.length && form.lines.some((line) => line.suggested)" class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <ClipboardList class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Matériel habituel de l’intervention proposé : confirmez, corrigez ou retirez.
            </p>

            <ul v-if="form.lines.length" class="divide-y divide-border rounded-lg border border-border bg-card">
                <li v-for="line in form.lines" :key="line.medicine_uuid" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-foreground">
                            {{ item(line)?.name }}
                            <Badge v-if="line.suggested" variant="outline" class="text-[10px]">Habituel</Badge>
                        </p>
                        <p class="text-[11px] text-muted-foreground">{{ item(line)?.code }}<template v-if="item(line)?.unit"> · {{ item(line)?.unit }}</template> · {{ item(line)?.available_quantity ?? 0 }} en stock</p>
                        <p v-if="short(line)" class="mt-0.5 flex items-center gap-1 text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                            <AlertTriangle class="h-3 w-3" aria-hidden="true" />Stock enregistré insuffisant : la Pharmacie ajustera son inventaire avant de servir.
                        </p>
                    </div>
                    <div class="flex items-center gap-1">
                        <div class="flex items-center" role="group" :aria-label="`Quantité de ${item(line)?.name}`">
                            <Button type="button" size="sm" variant="outline" class="h-8 w-8 rounded-e-none p-0" :disabled="Number(line.quantity) <= 1" aria-label="Diminuer la quantité" @click="step(line, -1)"><Minus class="h-3.5 w-3.5" /></Button>
                            <Input v-model.number="line.quantity" type="number" min="1" max="999" class="h-8 w-14 rounded-none border-x-0 text-center tabular-nums" :aria-label="`Quantité de ${item(line)?.name}`" />
                            <Button type="button" size="sm" variant="outline" class="h-8 w-8 rounded-s-none p-0" aria-label="Augmenter la quantité" @click="step(line, 1)"><Plus class="h-3.5 w-3.5" /></Button>
                        </div>
                        <Button type="button" size="icon-xs" variant="ghost" class="text-muted-foreground hover:text-destructive" :aria-label="`Retirer ${item(line)?.name}`" title="Retirer" @click="remove(line.medicine_uuid)"><X class="h-3.5 w-3.5" /></Button>
                    </div>
                </li>
            </ul>

            <div class="relative">
                <IconInput v-model="search" :icon="Search" type="search" autocomplete="off" placeholder="Ajouter un produit du stock (fil, compresses, drain, gants…)" aria-label="Rechercher un produit du stock" />
                <ul v-if="results.length" class="mt-1 divide-y divide-border overflow-hidden rounded-lg border border-border bg-popover shadow-sm">
                    <li v-for="result in results" :key="result.medicine_uuid">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm transition-colors hover:bg-accent disabled:cursor-default disabled:opacity-60 disabled:hover:bg-transparent"
                            :disabled="inBasket(result.medicine_uuid)"
                            @click="add(result)"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-foreground">{{ result.name }}</span>
                                <span class="block text-[11px] text-muted-foreground">{{ result.code }}<template v-if="result.unit"> · {{ result.unit }}</template></span>
                            </span>
                            <span v-if="inBasket(result.medicine_uuid)" class="shrink-0 text-[11px] font-semibold text-primary">Ajouté</span>
                            <span v-else :class="['shrink-0 text-[11px] tabular-nums', result.available ? 'text-muted-foreground' : 'font-semibold text-amber-700 dark:text-amber-300']">{{ result.available ? `${result.available_quantity} en stock` : 'Épuisé' }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else-if="search.trim()" class="mt-1 text-[11px] text-muted-foreground">
                    Aucun produit ne correspond. Seuls la parapharmacie et le matériel configuré pour un acte de Chirurgie sont proposés ; sinon, notez-le hors stock.
                </p>
            </div>

            <Input v-if="form.lines.length" v-model="form.notes" class="h-9" placeholder="Note pour la Pharmacie (facultatif)" aria-label="Note pour la Pharmacie" />
            <FormError v-if="form.errors.lines">{{ form.errors.lines }}</FormError>
            <FormError v-for="(message, key) in form.errors" v-show="key.startsWith('lines.')" :key="key">{{ message }}</FormError>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
                <Button type="button" size="xs" variant="link" class="h-auto px-0" @click="freeOpen = true"><NotebookPen class="h-3.5 w-3.5" />Produit absent du stock ? Noter hors stock</Button>
                <div class="ms-auto flex flex-wrap justify-end gap-2">
                    <Button size="sm" variant="white-outline" type="button" @click="closeForm">Annuler</Button>
                    <Button size="sm" type="button" :disabled="!canSubmit" @click="submit"><Send class="h-3.5 w-3.5" />Transmettre à la Pharmacie<template v-if="form.lines.length"> ({{ form.lines.length }})</template></Button>
                </div>
            </div>
        </div>

        <!-- Ligne hors stock : tracée, sans stock ni facture. -->
        <form v-if="freeOpen && canDeclare" class="mb-5 space-y-2 rounded-xl border border-dashed border-border p-3" @submit.prevent="submitFree">
            <p class="text-xs text-muted-foreground">Hors stock : la ligne est tracée dans le dossier, sans sortie de stock ni facture.</p>
            <div class="grid grid-cols-[minmax(0,1fr)_5rem_6rem] gap-2">
                <FormField label="Libellé" required :error="freeForm.errors.label"><Input id="consumable_label" v-model="freeForm.label" required /></FormField>
                <FormField label="Qté" required :error="freeForm.errors.quantity"><Input id="consumable_quantity" v-model="freeForm.quantity" type="number" min="1" required /></FormField>
                <FormField label="Unité" :error="freeForm.errors.unit"><Input id="consumable_unit" v-model="freeForm.unit" /></FormField>
            </div>
            <div class="flex justify-end gap-2">
                <Button size="sm" variant="white-outline" type="button" @click="freeOpen = false">Fermer</Button>
                <Button size="sm" type="submit" :disabled="freeForm.processing">Enregistrer hors stock</Button>
            </div>
        </form>

        <!-- Ce qui est parti à la Pharmacie. -->
        <div v-if="requests.length" class="space-y-2">
            <p class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Transmis à la Pharmacie</p>
            <ul class="divide-y divide-border rounded-lg border border-border">
                <li v-for="request in requests" :key="request.uuid" class="space-y-1.5 px-3 py-2.5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-foreground">
                            {{ request.request_number }}
                            <span class="font-normal text-muted-foreground">· {{ formatDateTime(request.requested_at) }}<template v-if="request.requested_by"> · {{ request.requested_by }}</template></span>
                        </p>
                        <div class="flex items-center gap-1">
                            <Badge :variant="statusVariant(request.status)">
                                <PackageCheck v-if="request.status === 'SERVED'" class="h-3 w-3" /><Ban v-else-if="request.status === 'CANCELLED'" class="h-3 w-3" />{{ request.status_label }}
                            </Badge>
                            <Button v-if="canDeclare && request.can_be_cancelled" type="button" size="icon-xs" variant="ghost" class="text-muted-foreground hover:text-destructive" :aria-label="`Annuler la demande ${request.request_number}`" title="Annuler la demande" @click="askCancel(request)"><Trash2 class="h-3.5 w-3.5" /></Button>
                        </div>
                    </div>
                    <ul class="text-xs text-muted-foreground">
                        <li v-for="line in request.lines" :key="line.uuid" class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-foreground">{{ line.name }} <span class="text-muted-foreground">× {{ quantityLabel(line.quantity_requested, line.unit) }}</span></span>
                            <span v-if="line.quantity_served" class="tabular-nums">{{ line.quantity_served }} sorti{{ line.quantity_served > 1 ? 's' : '' }} du stock</span>
                        </li>
                    </ul>
                    <p v-if="request.notes" class="text-xs italic text-muted-foreground">Note : {{ request.notes }}</p>
                    <p v-if="request.cancellation_reason" class="text-xs italic text-muted-foreground">Annulée : {{ request.cancellation_reason }}</p>
                </li>
            </ul>
        </div>

        <!-- Lignes hors stock, dont celles saisies avant le lien au stock. -->
        <div v-if="freeLines.length" :class="['space-y-2', requests.length && 'mt-4']">
            <p class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Hors stock · ni sortie de stock ni facture</p>
            <ul class="divide-y divide-border rounded-lg border border-dashed border-border text-sm">
                <li v-for="line in freeLines" :key="line.id" class="flex items-center justify-between gap-2 px-3 py-1.5">
                    <span class="text-foreground">{{ line.label }}</span>
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="text-muted-foreground">{{ quantityLabel(line.quantity, line.unit) }}</span>
                        <Button
                            v-if="canDeclare"
                            size="icon-xs"
                            variant="ghost"
                            type="button"
                            :disabled="removingFree === line.id"
                            :aria-label="`Retirer ${line.label}`"
                            title="Retirer (erreur de saisie)"
                            @click="removeFree(line)"
                        ><X class="h-3.5 w-3.5" /></Button>
                    </span>
                </li>
            </ul>
        </div>

        <p v-if="!requests.length && !freeLines.length && !open" class="text-sm text-muted-foreground">Aucun matériel déclaré.</p>

        <Dialog
            :open="Boolean(cancelling)"
            :title="cancelling ? `Annuler la demande ${cancelling.request_number}` : 'Annuler la demande'"
            description="La Pharmacie ne la verra plus dans sa file, et ce qu'elle avait porté au compte du patient est retiré tant que rien n'est facturé."
            :dismissible="false"
            @update:open="(value) => { if (!value) cancelling = null; }"
        >
            <FormField label="Motif" required :error="cancelForm.errors.reason || cancelForm.errors.request">
                <Textarea v-model="cancelForm.reason" :rows="3" placeholder="Matériel non utilisé, saisie en double…" />
            </FormField>
            <template #footer>
                <Button variant="white-outline" type="button" @click="cancelling = null">Garder la demande</Button>
                <Button variant="destructive" type="button" :disabled="cancelForm.processing || cancelForm.reason.trim().length < 3" @click="confirmCancel"><Ban class="h-4 w-4" />Annuler la demande</Button>
            </template>
        </Dialog>
    </SurgerySection>
</template>
