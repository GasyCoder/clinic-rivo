<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Ban, CalendarRange, CircleAlert, FileText, HandCoins, Hourglass, Info, Landmark, Plus, Send, Wallet } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StaffDebtRepayments from '@/Components/StaffDebts/StaffDebtRepayments.vue';
import StaffDebtStatusBadge from '@/Components/StaffDebts/StaffDebtStatusBadge.vue';
import StaffDebtTermsFields from '@/Components/StaffDebts/StaffDebtTermsFields.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { debtPlan, planSummary, toMinor } from '@/utilities/staffDebts';

/**
 * ADR-228 — « Mes dettes » : demander une dette au DG (montant, remboursement par mois,
 * premier mois, motif), suivre sa décision, son versement et ses remboursements. Une
 * demande se retire tant que le DG n'a pas décidé.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    space: { type: Object, required: true },
    focus: { type: String, default: null },
});

const requesting = ref(false);
const form = useForm({ amount: '', installment_amount: '', first_period: props.space.current_month, reason: '' });
const plan = computed(() => debtPlan(form.amount, form.installment_amount, form.first_period));
const ready = computed(() => plan.value !== null && toMinor(form.installment_amount) <= toMinor(form.amount) && form.reason.trim().length >= 5);

const openRequest = () => {
    form.reset();
    form.clearErrors();
    requesting.value = true;
};
const submit = () => form.post('/mes-dettes', { preserveScroll: true, onSuccess: () => { requesting.value = false; } });

const withdrawing = ref(null);
const withdrawForm = useForm({});
const withdraw = () => withdrawForm.post(`/mes-dettes/${withdrawing.value.uuid}/retirer`, {
    preserveScroll: true,
    onSuccess: () => { withdrawing.value = null; },
});

const progress = (debt) => {
    const amount = toMinor(debt.amount ?? 0) || 0;
    if (! amount) return 0;

    return Math.min(100, Math.round(((toMinor(debt.repaid) + toMinor(debt.written_off_amount)) / amount) * 100));
};

const tiles = computed(() => [
    { key: 'balance', icon: Landmark, label: 'Reste à rembourser', value: formatMoney(props.space.summary.balance), tone: 'bg-primary/10 text-primary' },
    { key: 'active', icon: Wallet, label: 'En remboursement', value: props.space.summary.active, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' },
    { key: 'pending', icon: Hourglass, label: 'Demande en attente', value: props.space.summary.pending, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' },
]);

onMounted(async () => {
    if (! props.focus) return;
    await nextTick();
    document.getElementById(`dette-${props.focus}`)?.scrollIntoView({ block: 'start', behavior: 'smooth' });
});
</script>

<template>
    <Head title="Mes dettes" />
    <div class="mx-auto w-full max-w-5xl space-y-5">
        <PageHeader
            eyebrow="Mon compte"
            title="Mes dettes"
            description="Demandez une dette au DG, puis suivez sa décision, son versement et vos remboursements (retenus sur votre paie ou remis en espèces à la Caisse)."
            :icon="HandCoins"
        >
            <template #actions>
                <Button v-if="space.can_request" type="button" @click="openRequest"><Plus class="h-4 w-4" />Demander une dette</Button>
            </template>
        </PageHeader>

        <div v-if="space.request_blocker" class="flex items-start gap-3 rounded-xl border border-border bg-muted/50 px-4 py-3 text-sm">
            <Info class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <p class="text-foreground">{{ space.request_blocker }}</p>
        </div>

        <div v-if="space.employee" class="grid gap-3 sm:grid-cols-3">
            <div v-for="tile in tiles" :key="tile.key" class="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-lg', tile.tone]"><component :is="tile.icon" class="h-5 w-5" /></span>
                <span>
                    <span class="block text-xl font-bold leading-none tabular-nums text-foreground">{{ tile.value }}</span>
                    <span class="mt-1 block text-xs font-semibold text-muted-foreground">{{ tile.label }}</span>
                </span>
            </div>
        </div>

        <Card v-if="space.employee && ! space.debts.length" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><HandCoins class="h-6 w-6" /></span>
            <p class="text-sm font-semibold text-foreground">Aucune dette pour l’instant</p>
            <p class="max-w-md text-sm text-muted-foreground">Une demande part au DG, qui l’accorde, l’ajuste ou la refuse. Vous êtes prévenu à chaque étape.</p>
            <Button v-if="space.can_request" type="button" variant="outline" @click="openRequest"><Plus class="h-4 w-4" />Faire une demande</Button>
        </Card>

        <Card v-for="debt in space.debts" :id="`dette-${debt.uuid}`" :key="debt.uuid" :class="['scroll-mt-24 overflow-hidden', focus === debt.uuid ? 'ring-2 ring-primary/40' : '']">
            <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border px-4 py-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-foreground">Dette {{ debt.number }}</p>
                    <p class="text-xs text-muted-foreground">Demandée le {{ formatDateTime(debt.requested_at) }}</p>
                </div>
                <StaffDebtStatusBadge :status="debt.status" :label="debt.status_label" :tone="debt.status_tone" />
                <span class="rounded-lg bg-primary/5 px-3 py-1.5 text-sm font-bold tabular-nums text-primary">{{ formatMoney(debt.amount ?? debt.requested_amount) }}</span>
            </header>

            <div class="space-y-4 px-4 py-4">
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-lg border border-border px-3 py-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Votre demande</p>
                        <p class="mt-1 text-foreground">{{ formatMoney(debt.requested.amount) }} · {{ formatMoney(debt.requested.installment_amount) }} par mois</p>
                        <p class="text-xs text-muted-foreground">{{ planSummary(debt.requested.plan) }}</p>
                        <p class="mt-1 flex items-start gap-1.5 text-xs text-muted-foreground"><FileText class="mt-0.5 h-3 w-3 shrink-0" />{{ debt.reason }}</p>
                    </div>
                    <div v-if="debt.granted" class="rounded-lg border border-primary/30 bg-primary/5 px-3 py-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-primary">Accordé par le DG<template v-if="debt.granted.adjusted"> · ajusté</template></p>
                        <p class="mt-1 text-foreground">{{ formatMoney(debt.granted.amount) }} · {{ formatMoney(debt.granted.installment_amount) }} par mois</p>
                        <p class="text-xs text-muted-foreground">{{ planSummary(debt.granted.plan) }} · {{ debt.granted.repayment_mode_label }}</p>
                        <p v-if="debt.decision_note" class="mt-1 text-xs text-muted-foreground">« {{ debt.decision_note }} »</p>
                    </div>
                    <div v-else-if="debt.status === 'REQUESTED'" class="flex items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2 text-muted-foreground">
                        <Hourglass class="h-4 w-4 shrink-0" />Attend la décision du DG.
                    </div>
                </div>

                <p v-if="debt.refusal_reason" class="flex items-start gap-2 rounded-lg bg-destructive/5 px-3 py-2 text-sm text-destructive"><CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />Refusée : {{ debt.refusal_reason }}</p>
                <p v-if="debt.status === 'CANCELLED' && debt.cancel_reason" class="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground"><Ban class="mt-0.5 h-4 w-4 shrink-0" />{{ debt.cancel_reason }}</p>
                <p v-if="debt.status === 'APPROVED'" class="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-sm text-foreground"><Wallet class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />Accordée : le RH vous la verse, puis les remboursements commencent.</p>
                <p v-if="debt.disbursement" class="flex items-start gap-2 text-sm text-muted-foreground"><Wallet class="mt-0.5 h-4 w-4 shrink-0" />Versée le {{ formatDate(debt.disbursement.on) }} · {{ debt.disbursement.mode_label }}</p>
                <p v-if="debt.write_off_reason" class="text-sm text-muted-foreground">Reste remis par le DG ({{ formatMoney(debt.written_off_amount) }}) : {{ debt.write_off_reason }}</p>

                <div v-if="debt.granted && debt.disbursement" class="space-y-1.5">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">Remboursé {{ formatMoney(debt.repaid) }} sur {{ formatMoney(debt.amount) }}</span>
                        <span class="font-semibold tabular-nums text-foreground">Reste {{ formatMoney(debt.balance) }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="progress(debt)" aria-valuemin="0" aria-valuemax="100" :aria-label="`${progress(debt)} % remboursé`">
                        <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${progress(debt)}%` }" />
                    </div>
                </div>

                <StaffDebtRepayments v-if="debt.disbursement || debt.repayments.length" :debt="debt" />
            </div>

            <footer v-if="debt.can.withdraw" class="flex justify-end border-t border-border px-4 py-3">
                <Button type="button" size="sm" variant="ghost" class="text-destructive hover:text-destructive" @click="withdrawing = debt"><Ban class="h-4 w-4" />Retirer ma demande</Button>
            </footer>
        </Card>

        <Dialog :open="requesting" title="Demander une dette" description="Votre demande part au DG, qui l’accorde, l’ajuste ou la refuse." size="lg" :dismissible="! form.processing" @update:open="(value) => form.processing || (requesting = value)">
            <form class="space-y-4" @submit.prevent="submit">
                <StaffDebtTermsFields :form="form" :current-month="space.current_month" :disabled="form.processing" />
                <FormField label="Motif" :icon="FileText" required :error="form.errors.reason">
                    <Textarea v-model="form.reason" :rows="3" maxlength="1000" placeholder="Pourquoi cette dette : lu par le DG et le RH seulement" :disabled="form.processing" />
                </FormField>
                <p v-if="form.errors.employee" class="text-sm font-medium text-destructive">{{ form.errors.employee }}</p>
                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                    <p class="me-auto flex items-center gap-1.5 text-xs text-muted-foreground"><CalendarRange class="h-3.5 w-3.5" />Remboursée par retenue sur votre paie ou en espèces : le DG décide.</p>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="requesting = false">Annuler</Button>
                    <Button type="submit" :disabled="! ready || form.processing"><Send class="h-4 w-4" />Envoyer au DG</Button>
                </div>
            </form>
        </Dialog>

        <ConfirmModal
            :open="withdrawing !== null"
            title="Retirer ma demande"
            :description="withdrawing ? `Demande ${withdrawing.number} de ${formatMoney(withdrawing.requested_amount)}` : ''"
            confirm-label="Retirer la demande"
            tone="danger"
            :icon="Ban"
            :processing="withdrawForm.processing"
            @update:open="(value) => value || withdrawForm.processing || (withdrawing = null)"
            @confirm="withdraw"
        >
            <p class="text-sm text-muted-foreground">Le DG ne la verra plus à décider. Vous pourrez en faire une autre.</p>
        </ConfirmModal>
    </div>
</template>
