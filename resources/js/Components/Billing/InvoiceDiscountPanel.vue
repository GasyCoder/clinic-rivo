<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { BadgePercent, Check, Loader2, TicketPercent, X } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { formatMoney } from '@/utilities/money';

/**
 * ADR-192 — la remise d'une facture, dans la fenêtre d'encaissement. Le serveur
 * dit à quelles remises la facture a droit et applique **la plus avantageuse** :
 * l'écran ne calcule rien, il n'envoie qu'un éventuel code de coupon. Une remise
 * ne se pose ni ne se retire après un premier paiement.
 */
const props = defineProps({
    invoice: { type: Object, required: true },
    canApply: { type: Boolean, default: false },
});

const emit = defineEmits(['changed']);

const loading = ref(false);
const processing = ref(false);
const state = ref(null);
const couponCode = ref('');
const checkedCode = ref('');
const error = ref('');
const confirmRemove = ref(false);

const best = computed(() => state.value?.offers?.[0] ?? null);
const others = computed(() => state.value?.offers?.slice(1) ?? []);

const load = async (code = '') => {
    loading.value = true;
    error.value = '';
    try {
        const query = code ? `?coupon_code=${encodeURIComponent(code)}` : '';
        const response = await fetch(`/invoices/${props.invoice.uuid}/discounts${query}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (! response.ok) throw new Error(String(response.status));
        state.value = await response.json();
        checkedCode.value = code && ! state.value.coupon_error ? code : '';
    } catch {
        error.value = 'Les remises n’ont pas pu être lues.';
    } finally {
        loading.value = false;
    }
};

const checkCoupon = () => load(couponCode.value.trim().toUpperCase());

const apply = () => {
    processing.value = true;
    error.value = '';
    router.post(`/invoices/${props.invoice.uuid}/discount`, { coupon_code: checkedCode.value || null }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            couponCode.value = '';
            checkedCode.value = '';
            emit('changed');
        },
        onError: (errors) => { error.value = errors.coupon_code || errors.discount || 'La remise n’a pas pu être appliquée.'; },
        onFinish: () => { processing.value = false; },
    });
};

const remove = () => {
    processing.value = true;
    error.value = '';
    router.delete(`/invoices/${props.invoice.uuid}/discount`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirmRemove.value = false;
            emit('changed');
        },
        onError: (errors) => { error.value = errors.discount || 'La remise n’a pas pu être retirée.'; },
        onFinish: () => { processing.value = false; },
    });
};

onMounted(() => load());
// Une autre facture, ou la même relue après une remise : on relit ce à quoi elle a droit.
watch(() => `${props.invoice.uuid}|${props.invoice.discount_amount}|${props.invoice.total_amount}`, () => load());
</script>

<template>
    <section class="rounded-xl border border-border bg-card p-4" aria-labelledby="invoice-discount-title">
        <div class="flex items-center justify-between gap-3">
            <h3 id="invoice-discount-title" class="flex items-center gap-2 text-sm font-semibold text-foreground">
                <span class="grid h-7 w-7 place-items-center rounded-md bg-primary/10 text-primary" aria-hidden="true"><BadgePercent class="h-4 w-4" /></span>
                Remise
            </h3>
            <Loader2 v-if="loading" class="h-4 w-4 animate-spin text-muted-foreground" aria-label="Chargement des remises" />
            <Badge v-else-if="state?.active" variant="success">Appliquée</Badge>
        </div>

        <template v-if="state">
            <!-- Remise en vigueur -->
            <div v-if="state.active" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2.5 dark:border-emerald-900 dark:bg-emerald-950/20">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-foreground">{{ state.active.label }} · {{ state.active.describe }}</p>
                    <p class="text-xs text-muted-foreground">−{{ formatMoney(state.active.amount) }}<template v-if="state.active.applied_by"> · appliquée par {{ state.active.applied_by }}</template></p>
                </div>
                <template v-if="canApply && state.discountable !== false">
                    <Button v-if="! confirmRemove" type="button" variant="outline" size="sm" :disabled="processing" @click="confirmRemove = true"><X class="h-3.5 w-3.5" />Retirer</Button>
                    <span v-else class="flex items-center gap-2">
                        <span class="text-xs text-muted-foreground">Retirer la remise ?</span>
                        <Button type="button" variant="destructive" size="sm" :disabled="processing" @click="remove">Oui, retirer</Button>
                        <Button type="button" variant="ghost" size="sm" :disabled="processing" @click="confirmRemove = false">Non</Button>
                    </span>
                </template>
            </div>

            <template v-else>
                <p v-if="! state.discountable" class="mt-3 text-sm text-muted-foreground">
                    La remise ne se modifie plus sur cette facture : un paiement a déjà été reçu, ou il ne reste rien à la charge du patient.
                </p>
                <template v-else>
                    <!-- La meilleure remise, celle que la Caisse applique. -->
                    <div v-if="best" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2.5">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-foreground">{{ best.label }} · {{ best.describe }}</p>
                            <p class="text-xs text-muted-foreground">−{{ formatMoney(best.amount) }} · reste {{ formatMoney(best.total_after) }} à la charge du patient</p>
                        </div>
                        <Button v-if="canApply" type="button" size="sm" :disabled="processing" @click="apply">
                            <Loader2 v-if="processing" class="h-3.5 w-3.5 animate-spin" /><Check v-else class="h-3.5 w-3.5" />Appliquer
                        </Button>
                    </div>
                    <p v-else class="mt-3 text-sm text-muted-foreground">Aucune remise ne s’applique à cette facture.</p>

                    <p v-if="others.length" class="mt-2 text-xs text-muted-foreground">
                        Moins avantageuse{{ others.length > 1 ? 's' : '' }}, non appliquée{{ others.length > 1 ? 's' : '' }} :
                        {{ others.map((offer) => `${offer.label} (−${formatMoney(offer.amount)})`).join(' · ') }}
                    </p>

                    <form v-if="canApply" class="mt-3 flex gap-2" @submit.prevent="checkCoupon">
                        <IconInput v-model="couponCode" :icon="TicketPercent" class="font-mono uppercase placeholder:font-sans placeholder:normal-case" placeholder="Code de coupon" maxlength="40" aria-label="Code de coupon" autocomplete="off" />
                        <Button type="submit" variant="outline" :disabled="loading || ! couponCode.trim()">Vérifier</Button>
                    </form>
                    <p v-if="state.coupon_error" class="mt-1.5 text-xs font-medium text-destructive" role="alert">{{ state.coupon_error }}</p>
                    <p v-else-if="checkedCode" class="mt-1.5 text-xs text-muted-foreground">Coupon {{ checkedCode }} valable : la meilleure remise ci-dessus en tient compte.</p>
                </template>
            </template>
        </template>

        <p v-if="error" class="mt-2 text-xs font-medium text-destructive" role="alert">{{ error }}</p>
    </section>
</template>
