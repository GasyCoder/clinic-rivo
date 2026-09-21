<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';
import { HandHeart, Plus, Search, Send, Trash2, X } from 'lucide-vue-next';

/**
 * ADR-162 — des soins demandés à l'équipe infirmière depuis le séjour.
 *
 * Le patient reste au lit : il n'y a pas de retour en Médecine à décider. Un
 * acte non encore réalisé se retire ; rien n'est supprimé (ADR-112).
 */
const props = defineProps({
    stayUuid: { type: String, required: true },
    careOrders: { type: Array, default: null },
    catalog: { type: Array, default: () => [] },
    canRequest: { type: Boolean, default: false },
});

const page = usePage();
/** Qui signe l'acte : le compte connecté, titré une seule fois. */
const signer = computed(() => doctorName(page.props.auth?.user?.name));
const fold = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const search = ref('');
const filtered = computed(() => {
    const query = fold(search.value.trim());

    return (query === '' ? props.catalog : props.catalog.filter((act) => fold(act.name).includes(query))).slice(0, 30);
});

const form = useForm({ items: [], instructions: '' });
const chosen = computed(() => new Set(form.items.map((item) => item.catalog_item_uuid)));
const nameOf = (uuid) => props.catalog.find((act) => act.uuid === uuid)?.name ?? '';
const add = (act) => {
    if (!chosen.value.has(act.uuid)) form.items.push({ catalog_item_uuid: act.uuid, quantity: 1 });
};

const confirming = ref(false);
const submit = () => form.post(`/hospitalisation/${props.stayUuid}/soins`, {
    preserveScroll: true,
    onSuccess: () => {
        form.reset();
        form.items = [];
        confirming.value = false;
    },
    onError: () => { confirming.value = false; },
});

const withdrawing = ref(null);
const withdraw = () => router.post(`/hospitalisation/${props.stayUuid}/soins/${withdrawing.value.uuid}/retirer`, {}, {
    preserveScroll: true,
    onFinish: () => { withdrawing.value = null; },
});

const STATE = { DONE: 'success', PARTIAL: 'secondary', PENDING: 'warning', NOT_PERFORMED: 'outline', CANCELLED: 'outline' };
</script>

<template>
    <div class="space-y-5">
        <Card v-if="canRequest" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><HandHeart class="h-4 w-4 text-muted-foreground" />Demander des soins</h2>
            <p class="mt-1 text-xs text-muted-foreground">La demande part dans la file Soins ; le patient reste hospitalisé après les soins.</p>
            <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
                <div class="min-w-0">
                    <IconInput v-model="search" :icon="Search" placeholder="Rechercher un acte" aria-label="Rechercher un acte de soins" />
                    <ul class="mt-2 max-h-64 space-y-1 overflow-y-auto">
                        <li v-for="act in filtered" :key="act.uuid">
                            <button type="button" class="flex w-full items-center justify-between gap-2 rounded-md border border-border bg-card px-2.5 py-1.5 text-start text-xs hover:bg-accent disabled:opacity-60" :disabled="chosen.has(act.uuid)" @click="add(act)">
                                <span class="truncate text-foreground">{{ act.name }}</span>
                                <Badge v-if="chosen.has(act.uuid)" variant="secondary">Retenu</Badge>
                                <Plus v-else class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                            </button>
                        </li>
                        <li v-if="!filtered.length" class="px-2 py-3 text-center text-xs text-muted-foreground">Aucun acte demandable.</li>
                    </ul>
                </div>
                <div class="min-w-0 space-y-3">
                    <p v-if="!form.items.length" class="rounded-md border border-dashed border-border bg-muted/30 px-3 py-8 text-center text-xs text-muted-foreground">Choisissez un acte à gauche.</p>
                    <ul v-else class="space-y-2">
                        <li v-for="(item, index) in form.items" :key="item.catalog_item_uuid" class="flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
                            <span class="min-w-0 flex-1 truncate text-sm text-foreground">{{ nameOf(item.catalog_item_uuid) }}</span>
                            <Input v-model="item.quantity" type="number" min="1" max="100" class="w-20" :aria-label="`Quantité — ${nameOf(item.catalog_item_uuid)}`" />
                            <Button type="button" size="icon-xs" variant="ghost" :aria-label="`Retirer ${nameOf(item.catalog_item_uuid)}`" @click="form.items.splice(index, 1)"><X class="h-3.5 w-3.5" /></Button>
                        </li>
                    </ul>
                    <FormField label="Consignes à l’équipe">
                        <Textarea v-model="form.instructions" :rows="2" maxlength="2000" />
                    </FormField>
                    <FormError :message="form.errors.care_order || form.errors.items" />
                    <div v-if="form.items.length" class="flex justify-end">
                        <Button type="button" size="sm" :disabled="form.processing" @click="confirming = true"><Send class="h-4 w-4" />Transmettre aux Soins</Button>
                    </div>
                </div>
            </div>
        </Card>

        <Card class="p-5">
            <h2 class="text-sm font-semibold text-foreground">Soins demandés pendant le séjour</h2>
            <p v-if="careOrders === null" class="mt-3 text-xs text-muted-foreground">Non visible avec vos droits (care_orders.view).</p>
            <p v-else-if="!careOrders.length" class="mt-3 text-xs text-muted-foreground">Aucun soin demandé depuis ce séjour.</p>
            <ul v-else class="mt-3 space-y-2">
                <li v-for="order in careOrders" :key="order.uuid" class="rounded-md border border-border bg-card p-3">
                    <p class="text-xs text-muted-foreground"><span class="font-semibold text-foreground">{{ formatDateTime(order.ordered_at) }}</span><template v-if="order.requested_by"> · {{ doctorName(order.requested_by) }}</template></p>
                    <ul class="mt-2 space-y-1">
                        <li v-for="item in order.items" :key="item.uuid" class="flex flex-wrap items-center gap-2 text-sm">
                            <span :class="['font-medium', item.state === 'CANCELLED' ? 'text-muted-foreground line-through' : 'text-foreground']">{{ item.name }}</span>
                            <span class="text-xs text-muted-foreground">× {{ Number(item.quantity) }}</span>
                            <Badge :variant="STATE[item.state] ?? 'outline'">{{ item.state_label }}</Badge>
                            <Button v-if="item.can_cancel" type="button" size="icon-xs" variant="ghost" class="ms-auto text-destructive" :aria-label="`Retirer ${item.name}`" title="Retirer" @click="withdrawing = item"><Trash2 class="h-3.5 w-3.5" /></Button>
                        </li>
                    </ul>
                    <p v-if="order.instructions" class="mt-2 text-xs text-muted-foreground">Consignes : {{ order.instructions }}</p>
                </li>
            </ul>
        </Card>

        <Dialog v-model:open="confirming" title="Transmettre aux Soins" description="Les actes partent dans la file Soins." size="md" :dismissible="false">
            <ul class="space-y-1 text-sm">
                <li v-for="item in form.items" :key="item.catalog_item_uuid" class="rounded-md border border-border px-3 py-1.5 text-foreground">{{ nameOf(item.catalog_item_uuid) }} × {{ item.quantity }}</li>
            </ul>
            <p v-if="form.instructions" class="mt-2 text-xs text-muted-foreground">Consignes : {{ form.instructions }}</p>
            <p class="mt-3 text-xs text-muted-foreground">Sous la responsabilité de <span class="font-semibold text-foreground">{{ signer }}</span>.</p>
            <template #footer>
                <Button type="button" size="sm" variant="ghost" @click="confirming = false">Revenir</Button>
                <Button type="button" size="sm" :disabled="form.processing" @click="submit"><Send class="h-4 w-4" />Je transmets</Button>
            </template>
        </Dialog>

        <Dialog :open="withdrawing !== null" title="Retirer cet acte ?" :description="withdrawing ? withdrawing.name : ''" size="md" :dismissible="false" @update:open="(value) => { if (!value) withdrawing = null; }">
            <p class="text-sm text-muted-foreground">L’acte reste lisible, barré, avec votre nom et l’heure.</p>
            <template #footer>
                <Button type="button" size="sm" variant="ghost" @click="withdrawing = null">Annuler</Button>
                <Button type="button" size="sm" variant="destructive" @click="withdraw"><Trash2 class="h-4 w-4" />Retirer</Button>
            </template>
        </Dialog>
    </div>
</template>
