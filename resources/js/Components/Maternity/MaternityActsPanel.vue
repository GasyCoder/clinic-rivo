<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    Check,
    CircleCheck,
    ClipboardCheck,
    Lock,
    Minus,
    Package,
    Pencil,
    Plus,
    Search,
    ShoppingBasket,
    Trash2,
    TriangleAlert,
    X,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';

/**
 * Les actes réalisés et le matériel utilisé d'une prise en charge Maternité.
 *
 * Extrait tel quel du dossier (ADR-138, ADR-140, ADR-141, ADR-142) pour servir
 * les deux parcours (ADR-204) : la synthèse d'une consultation prénatale et la
 * transmission d'un accouchement. Rien n'a changé de règle : un panier
 * enregistré d'un geste, tout ou rien ; un acte corrigé ou retiré selon ce que
 * le serveur permet ; le matériel transmis à la Pharmacie, jamais un prix.
 *
 * Le panier (`basketForm`) appartient à la page : c'est elle qui le garde dans
 * le brouillon serveur, pour qu'une actualisation ne le perde pas.
 */
const props = defineProps({
    orientation: { type: Object, required: true },
    record: { type: Object, default: null },
    procedureCatalog: { type: Array, default: () => [] },
    consumableCatalog: { type: Array, default: () => [] },
    consumableRequests: { type: Array, default: () => [] },
    plannedProcedures: { type: Array, default: () => [] },
    capabilities: { type: Object, required: true },
    /** Le panier, tenu par la page et gardé dans son brouillon. */
    basketForm: { type: Object, required: true },
});

const basketForm = props.basketForm;

// ── Actes demandés à la Réception ────────────────────────────────────────
// La sage-femme n'a pas à retrouver dans une liste ce que la Réception vient
// de lui envoyer : chaque acte demandé s'enregistre en un clic (ADR-136).
const catalogByUuid = computed(() => Object.fromEntries((props.procedureCatalog ?? []).map((item) => [item.uuid, item])));
const canRecordProcedures = computed(() => props.capabilities.can_procedures && props.orientation.status === 'IN_PROGRESS');
const recordingPlanned = ref(null);

const recordPlanned = (act) => {
    if (recordingPlanned.value) return;

    // « Autres » et tout acte qui exige une précision passent par le panier,
    // où la précision se saisit avant l'enregistrement.
    if (catalogByUuid.value[act.uuid]?.requires_note) {
        addToBasket(act.uuid, act.quantity);

        return;
    }

    recordingPlanned.value = act.uuid;
    router.post(
        `/maternity/orientations/${props.orientation.uuid}/procedures`,
        { catalog_item_uuid: act.uuid, quantity: act.quantity || 1, notes: '' },
        { preserveScroll: true, onFinish: () => { recordingPlanned.value = null; } },
    );
};

const pendingPlanned = computed(() => props.plannedProcedures.filter((act) => ! act.done));

// ── Panier d'actes (ADR-138) ─────────────────────────────────────────────
// On ajoute autant d'actes qu'il faut, on règle quantité et précision ligne
// par ligne, puis on enregistre le tout d'un geste : tout, ou rien.
const catalogFilter = ref('');
const plain = (text) => String(text ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const visibleCatalog = computed(() => {
    const term = plain(catalogFilter.value).trim();

    return (props.procedureCatalog ?? []).filter((item) => term === '' || plain(item.name).includes(term));
});
const basketIndex = (uuid) => basketForm.lines.findIndex((line) => line.catalog_item_uuid === uuid);
const inBasket = (uuid) => basketIndex(uuid) !== -1;

/** Un acte n'entre qu'une fois dans le panier : la quantité porte les répétitions. */
function addToBasket(uuid, quantity = 1) {
    if (! catalogByUuid.value[uuid] || inBasket(uuid)) return;

    basketForm.lines.push({ catalog_item_uuid: uuid, quantity: quantity || 1, notes: '' });
    suggestConsumablesFor(uuid);
}

// ── Matériel habituel de l'acte (ADR-142) ─────────────────────────────────
// Une suggestion de saisie, jamais une règle : la sage-femme confirme, corrige ou retire.
// Une quantité qu'elle a corrigée est une déclaration réelle, jamais réécrite.
const consumableByUuid = computed(() => Object.fromEntries((props.consumableCatalog ?? []).map((item) => [item.medicine_uuid, item])));
const canRequestConsumables = computed(() => Boolean(props.capabilities.can_request_consumables));

function suggestConsumablesFor(actUuid) {
    if (! canRequestConsumables.value) return;

    for (const suggestion of catalogByUuid.value[actUuid]?.default_consumables ?? []) {
        const existing = basketForm.consumables.find((line) => line.medicine_uuid === suggestion.medicine_uuid);

        if (existing) {
            if (! existing.suggested_by.includes(actUuid)) existing.suggested_by.push(actUuid);
            continue;
        }

        if (! consumableByUuid.value[suggestion.medicine_uuid]) continue;

        basketForm.consumables.push({
            medicine_uuid: suggestion.medicine_uuid,
            quantity: suggestion.quantity || 1,
            suggested_by: [actUuid],
            touched: false,
        });
    }
}

/** Retirer un acte retire seulement le matériel qu'il avait apporté et que personne n'a corrigé. */
function dropSuggestedConsumablesOf(actUuid) {
    basketForm.consumables = basketForm.consumables
        .map((line) => ({ ...line, suggested_by: (line.suggested_by ?? []).filter((uuid) => uuid !== actUuid) }))
        .filter((line) => line.touched || line.suggested_by.length > 0);
}

const consumableSearch = ref('');
const consumableOptions = computed(() => {
    const chosen = new Set(basketForm.consumables.map((line) => line.medicine_uuid));
    const term = plain(consumableSearch.value).trim();

    return (props.consumableCatalog ?? [])
        .filter((item) => ! chosen.has(item.medicine_uuid) && (term === '' || plain(`${item.name} ${item.code}`).includes(term)))
        .slice(0, 8);
});
const addConsumable = (item) => {
    basketForm.consumables.push({ medicine_uuid: item.medicine_uuid, quantity: 1, suggested_by: [], touched: true });
    consumableSearch.value = '';
};
const removeConsumable = (uuid) => {
    basketForm.consumables = basketForm.consumables.filter((line) => line.medicine_uuid !== uuid);
};
const changeConsumableQuantity = (line, delta) => {
    line.quantity = Math.max(1, Math.round((Number(line.quantity) || 0) + delta));
    line.touched = true;
};
const consumableItem = (line) => consumableByUuid.value[line.medicine_uuid];
/** Au-delà du stock connu : la Pharmacie refusera la sortie, autant le dire avant l'envoi. */
const consumableShort = (line) => {
    const item = consumableItem(line);

    return Boolean(item) && Number(line.quantity) > item.available_quantity;
};

const toggleInBasket = (uuid) => {
    const index = basketIndex(uuid);

    if (index === -1) addToBasket(uuid);
    else removeFromBasket(index);
};
const removeFromBasket = (index) => {
    const [removed] = basketForm.lines.splice(index, 1);
    if (removed) dropSuggestedConsumablesOf(removed.catalog_item_uuid);
};
const changeQuantity = (line, delta) => {
    line.quantity = Math.max(1, Math.round((Number(line.quantity) || 0) + delta));
};
const lineItem = (line) => catalogByUuid.value[line.catalog_item_uuid];
/** « Autres » ne se comprend pas sans sa description. */
const missingNote = (line) => Boolean(lineItem(line)?.requires_note) && String(line.notes ?? '').trim() === '';
const basketBlockers = computed(() => basketForm.lines.filter(missingNote).length);
const basketHasContent = computed(() => basketForm.lines.length > 0 || basketForm.consumables.length > 0);
const basketReady = computed(() => basketHasContent.value
    && basketBlockers.value === 0
    && basketForm.lines.every((line) => Number(line.quantity) > 0)
    && basketForm.consumables.every((line) => Number(line.quantity) >= 1));
const basketUnits = computed(() => basketForm.lines.reduce((total, line) => total + (Number(line.quantity) || 0), 0));
const plannedNotInBasket = computed(() => pendingPlanned.value.filter((act) => ! inBasket(act.uuid)));
const addPlannedToBasket = () => plannedNotInBasket.value.forEach((act) => addToBasket(act.uuid, act.quantity));
/** Les erreurs propres à une ligne s'affichent sur elle ; le reste au pied du panier. */
const basketError = computed(() => Object.entries(basketForm.errors).find(([key]) => ! key.startsWith('procedures.'))?.[1] ?? null);
const basketSaveLabel = computed(() => {
    const acts = basketForm.lines.length;

    if (acts === 0) return 'Transmettre le matériel';

    return `Enregistrer ${acts} acte${acts > 1 ? 's' : ''}${basketForm.consumables.length ? ' et transmettre le matériel' : ''}`;
});
const clearBasket = () => { basketForm.lines = []; basketForm.consumables = []; basketForm.consumable_notes = ''; };

const saveBasket = () => basketForm
    .transform((data) => ({
        // Chaque partie n'est envoyée que si elle existe : le matériel peut partir seul.
        ...(data.lines.length ? {
            procedures: data.lines.map((line) => ({
                catalog_item_uuid: line.catalog_item_uuid,
                quantity: line.quantity,
                notes: String(line.notes ?? '').trim() || null,
            })),
        } : {}),
        ...(data.consumables.length ? {
            consumables: data.consumables.map((line) => ({ medicine_uuid: line.medicine_uuid, quantity: Number(line.quantity) })),
            consumable_notes: String(data.consumable_notes ?? '').trim() || null,
        } : {}),
    }))
    .post(`/maternity/orientations/${props.orientation.uuid}/procedures/batch`, {
        preserveScroll: true,
        // Vidé directement : après un succès, `reset()` reviendrait au contenu
        // que le formulaire vient de prendre pour référence.
        onSuccess: clearBasket,
    });

// ── Corriger ou retirer un acte enregistré (ADR-140) ─────────────────────
// Les gestes permis viennent du serveur (`can_modify`) : l'acte enregistré par
// un médecin reste intact pour le personnel Maternité. Retirer ne détruit
// rien — l'acte quitte la liste et l'audit garde la trace.
const editingProcedure = ref(null);
const editForm = useForm({ quantity: 1, notes: '' });
const removingProcedure = ref(null);
// Annuler une demande de matériel : possible tant que la Pharmacie n'a rien sorti du stock (ADR-072).
const cancellingRequest = ref(null);
const cancelForm = useForm({ reason: '' });
const confirmCancelRequest = () => cancelForm.post(
    `/maternity/orientations/${props.orientation.uuid}/consumables/${cancellingRequest.value.uuid}/cancel`,
    { preserveScroll: true, onSuccess: () => { cancellingRequest.value = null; } },
);
const removeForm = useForm({});

const startEdit = (procedure) => {
    editForm.clearErrors();
    editForm.quantity = Number(procedure.quantity);
    editForm.notes = procedure.notes ?? '';
    editingProcedure.value = procedure.uuid;
};
const cancelEdit = () => {
    editForm.clearErrors();
    editingProcedure.value = null;
};
const saveEdit = (procedure) => editForm.put(
    `/maternity/orientations/${props.orientation.uuid}/procedures/${procedure.uuid}`,
    { preserveScroll: true, onSuccess: () => { editingProcedure.value = null; } },
);
const editNeedsNote = (procedure) => Boolean(catalogByUuid.value[procedure.catalog_item_uuid]?.requires_note);
const confirmRemove = () => removeForm.delete(
    `/maternity/orientations/${props.orientation.uuid}/procedures/${removingProcedure.value.uuid}`,
    { preserveScroll: true, onSuccess: () => { removingProcedure.value = null; } },
);
const performedOn = (procedure) => (procedure.performed_at ? new Date(procedure.performed_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '');
</script>

<template>
    <div class="space-y-4">
        <!-- Ce que la Réception a demandé à la Maternité : un clic par acte,
             sans le chercher dans la liste du catalogue (ADR-136). -->
        <Card v-if="plannedProcedures.length" class="p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                    <ClipboardCheck class="h-4 w-4" aria-hidden="true" />Demandé à la Réception
                </p>
                <span class="text-xs text-muted-foreground">
                    {{ pendingPlanned.length === 0 ? 'Tout est enregistré' : `${pendingPlanned.length} acte${pendingPlanned.length > 1 ? 's' : ''} à enregistrer` }}
                </span>
            </div>
            <ul class="mt-3 flex flex-wrap gap-2">
                <li
                    v-for="act in plannedProcedures"
                    :key="act.uuid"
                    :class="cn(
                        'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                        act.done ? 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-border bg-muted/40',
                    )"
                >
                    <CircleCheck v-if="act.done" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-label="Acte enregistré" />
                    <span class="font-semibold text-foreground">{{ act.name }}</span>
                    <span v-if="act.quantity !== 1" class="text-xs text-muted-foreground">× {{ act.quantity }}</span>
                    <Button
                        v-if="! act.done && canRecordProcedures"
                        type="button"
                        size="sm"
                        variant="primary"
                        class="h-7 px-2.5 text-xs"
                        :disabled="recordingPlanned !== null"
                        @click="recordPlanned(act)"
                    >
                        <Check class="h-3.5 w-3.5" />{{ recordingPlanned === act.uuid ? 'Enregistrement…' : (catalogByUuid[act.uuid]?.requires_note ? 'Préciser' : 'Enregistrer') }}
                    </Button>
                </li>
            </ul>
        </Card>

        <!-- Panier d'actes (ADR-138) : à gauche ce qu'on peut ajouter, à
             droite ce qui sera enregistré d'un seul geste. -->
        <div v-if="canRecordProcedures" class="grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
            <div class="rounded-xl border border-border bg-muted/40 p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Actes disponibles</p>
                    <div class="relative w-full sm:w-56">
                        <Search class="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                        <Input v-model="catalogFilter" type="search" class="h-8 ps-8 text-xs" placeholder="Filtrer les actes…" aria-label="Filtrer les actes" autocomplete="off" />
                    </div>
                </div>

                <!-- Un clic ajoute l'acte au panier, un second le retire. -->
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Actes Maternité">
                    <button
                        v-for="item in visibleCatalog"
                        :key="item.uuid"
                        type="button"
                        role="checkbox"
                        :aria-checked="inBasket(item.uuid)"
                        :class="cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            inBasket(item.uuid)
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-background text-foreground hover:bg-accent',
                        )"
                        @click="toggleInBasket(item.uuid)"
                    >
                        <Check v-if="inBasket(item.uuid)" class="h-3 w-3" aria-hidden="true" />
                        <Plus v-else class="h-3 w-3 text-muted-foreground" aria-hidden="true" />
                        {{ item.name }}
                    </button>
                    <p v-if="visibleCatalog.length === 0" class="py-2 text-xs text-muted-foreground">Aucun acte ne correspond à « {{ catalogFilter }} ».</p>
                </div>
            </div>

            <section class="flex flex-col overflow-hidden rounded-xl border border-border bg-card" aria-label="Panier d’actes">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-muted px-4 py-2.5">
                    <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                        <ShoppingBasket class="h-4 w-4" aria-hidden="true" />Panier d’actes
                        <Badge :variant="basketForm.lines.length ? 'default' : 'outline'">{{ basketForm.lines.length }}</Badge>
                    </p>
                    <div class="flex items-center gap-1">
                        <Button v-if="plannedNotInBasket.length" type="button" size="sm" variant="outline" class="h-7 px-2 text-[11px]" @click="addPlannedToBasket">
                            <ClipboardCheck class="h-3.5 w-3.5" />Ajouter les {{ plannedNotInBasket.length }} acte{{ plannedNotInBasket.length > 1 ? 's' : '' }} demandé{{ plannedNotInBasket.length > 1 ? 's' : '' }}
                        </Button>
                        <Button v-if="basketHasContent" type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px] text-muted-foreground" @click="clearBasket">
                            <Trash2 class="h-3.5 w-3.5" />Vider
                        </Button>
                    </div>
                </header>

                <p v-if="! basketHasContent" class="px-4 py-10 text-center text-sm text-muted-foreground">
                    Le panier est vide. Cliquez sur un acte pour l’ajouter, réglez sa quantité, puis enregistrez le tout d’un coup.
                </p>

                <ul v-if="basketForm.lines.length" class="divide-y divide-border">
                    <li v-for="(line, index) in basketForm.lines" :key="line.catalog_item_uuid" class="space-y-2 px-4 py-3">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-bold text-foreground">
                                {{ lineItem(line)?.name }}
                                <Badge v-if="lineItem(line)?.requires_note" variant="warning" class="ms-1.5 align-middle">Précision obligatoire</Badge>
                            </p>
                            <Button type="button" size="sm" variant="ghost" class="h-6 w-6 shrink-0 p-0 text-muted-foreground hover:text-destructive" :aria-label="`Retirer ${lineItem(line)?.name} du panier`" @click="removeFromBasket(index)">
                                <X class="h-4 w-4" />
                            </Button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="flex items-center" role="group" :aria-label="`Quantité de ${lineItem(line)?.name}`">
                                <Button type="button" size="sm" variant="outline" class="h-9 w-9 rounded-e-none p-0" :disabled="Number(line.quantity) <= 1" aria-label="Diminuer la quantité" @click="changeQuantity(line, -1)"><Minus class="h-3.5 w-3.5" /></Button>
                                <Input v-model="line.quantity" type="number" min="0.01" step="0.01" class="h-9 w-16 rounded-none border-x-0 px-1 text-center" aria-label="Quantité" />
                                <Button type="button" size="sm" variant="outline" class="h-9 w-9 rounded-s-none p-0" aria-label="Augmenter la quantité" @click="changeQuantity(line, 1)"><Plus class="h-3.5 w-3.5" /></Button>
                            </div>
                            <Input
                                v-model="line.notes"
                                class="h-9 min-w-0 flex-1"
                                :placeholder="lineItem(line)?.requires_note ? 'Précisez l’acte (obligatoire)' : 'Précision facultative'"
                                :aria-required="lineItem(line)?.requires_note ? 'true' : undefined"
                                :aria-invalid="missingNote(line) ? 'true' : undefined"
                                :aria-label="`Précision — ${lineItem(line)?.name}`"
                            />
                        </div>
                        <FormError v-if="basketForm.errors[`procedures.${index}.notes`] || basketForm.errors[`procedures.${index}.quantity`]">
                            {{ basketForm.errors[`procedures.${index}.notes`] || basketForm.errors[`procedures.${index}.quantity`] }}
                        </FormError>
                    </li>
                </ul>

                <!-- ADR-142 : le matériel utilisé est transmis à la Pharmacie pour la sortie de
                     stock, puis encaissé par la Caisse. La sage-femme ne voit jamais un prix. -->
                <div v-if="canRequestConsumables" class="space-y-2 border-t border-border px-4 py-3">
                    <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                        <Package class="h-4 w-4" aria-hidden="true" />Matériel utilisé
                        <Badge :variant="basketForm.consumables.length ? 'default' : 'outline'">{{ basketForm.consumables.length }}</Badge>
                    </p>
                    <p class="text-[11px] text-muted-foreground">Transmis à la Pharmacie pour la sortie de stock. Le patient règle à la Caisse.</p>

                    <ul v-if="basketForm.consumables.length" class="divide-y divide-border rounded-lg border border-border">
                        <li v-for="line in basketForm.consumables" :key="line.medicine_uuid" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-foreground">{{ consumableItem(line)?.name }}</p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ consumableItem(line)?.unit }} · {{ consumableItem(line)?.available_quantity ?? 0 }} en stock
                                    <span v-if="line.suggested_by?.length" class="italic"> · habituel pour l’acte</span>
                                </p>
                                <p v-if="consumableShort(line)" class="mt-0.5 flex items-center gap-1 text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                                    <TriangleAlert class="h-3 w-3" aria-hidden="true" />Au-delà du stock connu : la Pharmacie devra ajuster son inventaire.
                                </p>
                            </div>
                            <div class="flex items-center gap-1">
                                <div class="flex items-center" role="group" :aria-label="`Quantité de ${consumableItem(line)?.name}`">
                                    <Button type="button" size="sm" variant="outline" class="h-8 w-8 rounded-e-none p-0" :disabled="Number(line.quantity) <= 1" aria-label="Diminuer la quantité" @click="changeConsumableQuantity(line, -1)"><Minus class="h-3.5 w-3.5" /></Button>
                                    <Input v-model="line.quantity" type="number" min="1" step="1" class="h-8 w-14 rounded-none border-x-0 px-1 text-center" aria-label="Quantité" @input="line.touched = true" />
                                    <Button type="button" size="sm" variant="outline" class="h-8 w-8 rounded-s-none p-0" aria-label="Augmenter la quantité" @click="changeConsumableQuantity(line, 1)"><Plus class="h-3.5 w-3.5" /></Button>
                                </div>
                                <Button type="button" size="sm" variant="ghost" class="h-8 w-8 p-0 text-muted-foreground hover:text-destructive" :aria-label="`Retirer ${consumableItem(line)?.name}`" @click="removeConsumable(line.medicine_uuid)"><X class="h-4 w-4" /></Button>
                            </div>
                        </li>
                    </ul>

                    <div class="relative">
                        <Search class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                        <Input v-model="consumableSearch" type="search" class="h-9 ps-9" placeholder="Ajouter du matériel (compresses, gants, DIU…)" autocomplete="off" aria-label="Rechercher du matériel" />
                    </div>
                    <ul v-if="consumableSearch.trim() && consumableOptions.length" class="divide-y divide-border rounded-lg border border-border">
                        <li v-for="item in consumableOptions" :key="item.medicine_uuid">
                            <button type="button" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm hover:bg-accent" @click="addConsumable(item)">
                                <span class="font-medium text-foreground">{{ item.name }}</span>
                                <span class="text-[11px] text-muted-foreground">{{ item.available ? `${item.available_quantity} en stock` : 'Épuisé' }}</span>
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="consumableSearch.trim()" class="text-[11px] text-muted-foreground">Aucun matériel ne correspond. Seul le matériel de parapharmacie et celui configuré pour les actes de la Maternité peut être déclaré.</p>
                    <Input v-if="basketForm.consumables.length" v-model="basketForm.consumable_notes" class="h-9" placeholder="Note pour la Pharmacie (facultatif)" aria-label="Note pour la Pharmacie" />
                    <FormError v-if="basketForm.errors.consumables">{{ basketForm.errors.consumables }}</FormError>
                </div>

                <footer v-if="basketHasContent" class="mt-auto space-y-2 border-t border-border bg-muted/40 px-4 py-3">
                    <FormError v-if="basketError">{{ basketError }}</FormError>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-muted-foreground">
                            <template v-if="basketBlockers">{{ basketBlockers }} acte{{ basketBlockers > 1 ? 's' : '' }} à préciser avant d’enregistrer.</template>
                            <template v-else>
                                <template v-if="basketForm.lines.length">{{ basketForm.lines.length }} acte{{ basketForm.lines.length > 1 ? 's' : '' }} · {{ basketUnits }} au total</template>
                                <template v-if="basketForm.lines.length && basketForm.consumables.length"> · </template>
                                <template v-if="basketForm.consumables.length">{{ basketForm.consumables.length }} matériel{{ basketForm.consumables.length > 1 ? 's' : '' }} à la Pharmacie</template>
                            </template>
                        </p>
                        <Button type="button" variant="primary" :disabled="! basketReady || basketForm.processing" @click="saveBasket">
                            <Check class="h-4 w-4" />{{ basketForm.processing ? 'Enregistrement…' : basketSaveLabel }}
                        </Button>
                    </div>
                </footer>
            </section>
        </div>

        <div class="overflow-hidden rounded-xl border border-border">
            <p class="border-b border-border bg-muted px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Actes réalisés</p>
            <ul v-if="record?.procedures?.length" class="divide-y divide-border">
                <li v-for="procedure in record.procedures" :key="procedure.uuid" class="px-4 py-3">
                    <!-- Correction sur place : quantité et précision, jamais l'acte
                         lui-même (changer d'acte, c'est retirer et ajouter). -->
                    <form v-if="editingProcedure === procedure.uuid" class="space-y-2" @submit.prevent="saveEdit(procedure)">
                        <p class="text-sm font-bold text-foreground">{{ procedure.procedure_name }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <Input v-model="editForm.quantity" type="number" min="0.01" step="0.01" class="h-9 w-24" aria-label="Quantité" />
                            <Input
                                v-model="editForm.notes"
                                class="h-9 min-w-0 flex-1"
                                :placeholder="editNeedsNote(procedure) ? 'Précisez l’acte (obligatoire)' : 'Précision facultative'"
                                :aria-required="editNeedsNote(procedure) ? 'true' : undefined"
                                aria-label="Précision"
                            />
                            <Button type="submit" size="sm" variant="primary" :disabled="editForm.processing || (editNeedsNote(procedure) && ! String(editForm.notes ?? '').trim())">
                                <Check class="h-4 w-4" />{{ editForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                            </Button>
                            <Button type="button" size="sm" variant="ghost" @click="cancelEdit">Annuler</Button>
                        </div>
                        <FormError v-if="editForm.errors.notes || editForm.errors.quantity || editForm.errors.procedure">{{ editForm.errors.notes || editForm.errors.quantity || editForm.errors.procedure }}</FormError>
                    </form>

                    <div v-else class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-foreground">{{ procedure.procedure_name }} <span class="font-normal text-muted-foreground">× {{ procedure.quantity }}</span></p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Par {{ procedure.performer?.name }}<template v-if="performedOn(procedure)"> · {{ performedOn(procedure) }}</template> · {{ procedure.notes || 'Sans précision' }}
                                <template v-if="procedure.edited_at"> · <span class="italic">corrigé par {{ procedure.editor?.name ?? 'un collègue' }}</span></template>
                            </p>
                            <!-- Sans montant : la sage-femme ne voit jamais un prix. Un acte non chiffré se dit, il ne se tait pas (ADR-103). -->
                            <Badge v-if="procedure.billing" :variant="procedure.billing.needs_attention ? 'warning' : 'outline'" class="mt-1.5">
                                {{ procedure.billing.label }}
                            </Badge>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <template v-if="procedure.can_modify">
                                <Button type="button" size="sm" variant="ghost" class="h-8 w-8 p-0 text-muted-foreground hover:text-foreground" :aria-label="`Modifier ${procedure.procedure_name}`" title="Modifier" @click="startEdit(procedure)">
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <Button type="button" size="sm" variant="ghost" class="h-8 w-8 p-0 text-muted-foreground hover:text-destructive" :aria-label="`Retirer ${procedure.procedure_name}`" title="Retirer" @click="removeForm.clearErrors(); removingProcedure = procedure">
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </template>
                            <!-- L'acte d'un médecin reste intact : on le dit, on ne le masque pas. -->
                            <span v-else-if="procedure.locked_by_physician" class="inline-flex items-center gap-1 text-[11px] text-muted-foreground" title="Enregistré par un médecin : non modifiable depuis la Maternité">
                                <Lock class="h-3.5 w-3.5" aria-hidden="true" />Médecin
                            </span>
                        </div>
                    </div>
                </li>
            </ul>
            <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">Aucun acte Maternité enregistré.</p>
        </div>

        <div v-if="capabilities.can_view_consumables && consumableRequests.length" class="overflow-hidden rounded-xl border border-border">
            <p class="flex items-center gap-2 border-b border-border bg-muted px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">
                <Package class="h-3.5 w-3.5" aria-hidden="true" />Matériel transmis à la Pharmacie
            </p>
            <ul class="divide-y divide-border">
                <li v-for="request in consumableRequests" :key="request.uuid" class="space-y-1.5 px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-bold text-foreground">{{ request.request_number }} <span class="font-normal text-muted-foreground">· {{ performedOn({ performed_at: request.requested_at }) }}</span></p>
                        <div class="flex items-center gap-1">
                            <Badge :variant="request.status === 'SERVED' ? 'success' : request.status === 'CANCELLED' ? 'outline' : 'warning'">{{ request.status_label }}</Badge>
                            <Button v-if="capabilities.can_cancel_consumables && request.can_be_cancelled" type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px] text-muted-foreground hover:text-destructive" @click="cancelForm.clearErrors(); cancelForm.reason = ''; cancellingRequest = request">Annuler</Button>
                        </div>
                    </div>
                    <ul class="text-xs text-muted-foreground">
                        <li v-for="line in request.lines" :key="line.uuid">
                            {{ line.name }} × {{ line.quantity_requested }}<template v-if="line.quantity_served"> · {{ line.quantity_served }} sorti{{ line.quantity_served > 1 ? 's' : '' }}</template>
                        </li>
                    </ul>
                    <p v-if="request.cancellation_reason" class="text-xs italic text-muted-foreground">Annulée : {{ request.cancellation_reason }}</p>
                </li>
            </ul>
        </div>

        <Dialog
            :open="cancellingRequest !== null"
            title="Annuler cette demande de matériel ?"
            description="La Pharmacie ne la verra plus dans sa file et ce qui n'a pas encore été porté sur une facture est retiré du compte du patient."
            @update:open="(open) => { if (! open) cancellingRequest = null; }"
        >
            <FormField label="Motif" required :error="cancelForm.errors.reason || cancelForm.errors.request">
                <Input v-model="cancelForm.reason" placeholder="Ex. saisi par erreur" maxlength="500" />
            </FormField>

            <template #footer>
                <Button type="button" variant="outline" :disabled="cancelForm.processing" @click="cancellingRequest = null">Garder</Button>
                <Button type="button" variant="danger" :disabled="cancelForm.processing || ! cancelForm.reason.trim()" @click="confirmCancelRequest">
                    {{ cancelForm.processing ? 'Annulation…' : 'Annuler la demande' }}
                </Button>
            </template>
        </Dialog>

        <Dialog
            :open="removingProcedure !== null"
            title="Retirer cet acte ?"
            description="L’acte quitte la liste des actes réalisés. Rien n’est détruit : le retrait est tracé à l’audit avec son auteur."
            @update:open="(open) => { if (! open) removingProcedure = null; }"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/35 dark:text-red-300"><Trash2 class="h-5 w-5" /></span>
            </template>

            <p v-if="removingProcedure" class="text-sm text-foreground">
                <strong>{{ removingProcedure.procedure_name }}</strong> × {{ removingProcedure.quantity }}
                <span class="text-muted-foreground"> — enregistré par {{ removingProcedure.performer?.name }}</span>
            </p>
            <FormError v-if="removeForm.errors.procedure" class="mt-2">{{ removeForm.errors.procedure }}</FormError>

            <template #footer>
                <Button type="button" variant="outline" :disabled="removeForm.processing" @click="removingProcedure = null">Annuler</Button>
                <Button type="button" variant="danger" :disabled="removeForm.processing" @click="confirmRemove">
                    <Trash2 class="h-4 w-4" />{{ removeForm.processing ? 'Retrait…' : 'Retirer l’acte' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
