<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Ban, Banknote, CalendarDays, ChevronLeft, ChevronRight, CircleCheck, Gift, HandCoins, Hourglass, Landmark, Wallet } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { hrContext, hrUrl } from '@/utilities/hrUrl';
import { monthLabel, shiftMonth } from '@/utilities/bonus';

/**
 * ADR-227 — la paie du mois : salaire de base déclaré + avantages du mois (saisis,
 * déclarés sur la fiche, à l'acte validés) = brut. ADR-228 — les dettes du personnel à
 * retenue sur salaire s'en retranchent (jamais plus que le brut) : à verser = brut −
 * retenues. Aucune cotisation ni impôt (ADR-066). « Marquer payé » fige la paie, ses
 * avantages et ses retenues ; le virement se fait hors RIVO.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    month: { type: String, required: true },
    currentMonth: { type: String, required: true },
    board: { type: Object, required: true },
});

const { can } = usePermissions();

// ADR-229 — les dettes du personnel se gèrent dans Finance, au portail : le lien n'existe
// que là, vers les dettes du même site.
const staffDebtsUrl = computed(() => {
    const context = hrContext();

    return context && can('staff_debts.view') ? `/super-admin/sites/${encodeURIComponent(context.site.code)}/finance/dettes?vue=en-cours` : null;
});

const LINE_KINDS = {
    BASE: { label: 'Salaire de base', icon: Wallet },
    DECLARED: { label: 'Avantage de la fiche', icon: Gift },
    ENTRY: { label: 'Avantage saisi', icon: HandCoins },
    ACTS: { label: 'Avantages à l’acte', icon: Gift },
    DEBT: { label: 'Retenue de dette', icon: Landmark },
};

const goTo = (month) => router.get(hrUrl('/administration/paie'), { mois: month }, { preserveScroll: true, preserveState: true });

const cards = computed(() => [
    { key: 'to_pay', icon: Hourglass, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', value: props.board.summary.to_pay, label: 'À payer', hint: formatMoney(props.board.summary.amount_to_pay) },
    { key: 'advantages', icon: HandCoins, tone: 'bg-primary/10 text-primary', value: formatMoney(props.board.summary.advantages_to_pay), label: 'Dont avantages', hint: 'Sur les paies encore à payer' },
    { key: 'deductions', icon: Landmark, tone: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300', value: formatMoney(props.board.summary.deductions_to_pay ?? 0), label: 'Retenues de dettes', hint: 'Déduites des paies encore à payer' },
    { key: 'paid', icon: CircleCheck, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', value: props.board.summary.paid, label: 'Payées', hint: formatMoney(props.board.summary.amount_paid) },
]);

const pending = ref(null);
const form = useForm({ note: '', reason: '' });
const open = (mode, row) => {
    form.reset();
    form.clearErrors();
    pending.value = { mode, row };
};
const confirm = () => {
    const { mode, row } = pending.value;
    const options = { preserveScroll: true, onSuccess: () => { pending.value = null; } };
    if (mode === 'pay') {
        form.transform((data) => ({ employee_uuid: row.uuid, mois: props.month, note: data.note })).post(hrUrl('/administration/paie/payer'), options);
    } else {
        form.transform((data) => ({ reason: data.reason })).post(hrUrl(`/administration/paie/${row.payment.uuid}/annuler`), options);
    }
};
const error = computed(() => Object.values(form.errors)[0] ?? '');
</script>

<template>
    <Head title="Paie du mois" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines · Pilotage"
            title="Paie du mois"
            description="Salaire de base déclaré + avantages du mois = brut ; les dettes du personnel s’en retiennent. Marquer payé fige la paie, ses avantages et ses retenues ; le virement se fait hors RIVO."
            :icon="Banknote"
        >
            <template #actions>
                <Button v-if="staffDebtsUrl" :as="Link" :href="staffDebtsUrl" variant="outline">
                    <Landmark class="h-4 w-4" />Dettes du personnel
                </Button>
                <Button v-if="can('advantage_entries.view')" :as="Link" :href="hrUrl(`/administration/bonus?onglet=saisis&mois=${month}`)" variant="outline">
                    <HandCoins class="h-4 w-4" />Avantages saisis
                </Button>
            </template>
        </PageHeader>

        <div class="flex w-fit items-center gap-1 rounded-xl border border-border bg-card p-1 shadow-sm">
            <Button type="button" variant="ghost" size="icon" aria-label="Mois précédent" @click="goTo(shiftMonth(month, -1))"><ChevronLeft class="h-4 w-4" /></Button>
            <span class="flex min-w-44 items-center justify-center gap-2 px-2 text-sm font-semibold capitalize text-foreground">
                <CalendarDays class="h-4 w-4 text-muted-foreground" />{{ monthLabel(month) }}
            </span>
            <Button type="button" variant="ghost" size="icon" aria-label="Mois suivant" :disabled="month >= currentMonth" @click="goTo(shiftMonth(month, 1))"><ChevronRight class="h-4 w-4" /></Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="card in cards" :key="card.key" class="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone]"><component :is="card.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-xl font-bold leading-none tabular-nums text-foreground">{{ card.value }}</span>
                    <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                    <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                </span>
            </div>
        </div>

        <Card v-if="! board.rows.length" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><Banknote class="h-6 w-6" /></span>
            <p class="text-sm font-semibold text-foreground">Rien à payer pour {{ monthLabel(month) }}</p>
            <p class="max-w-md text-sm text-muted-foreground">La paie liste les personnes en poste dont la rémunération a un montant (étape Rémunération du dossier), et celles qui ont des avantages ce mois-ci.</p>
        </Card>

        <Card v-for="row in board.rows" :key="row.uuid" class="overflow-hidden">
            <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border px-4 py-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-foreground">{{ row.name }}</p>
                    <p class="truncate text-xs text-muted-foreground">{{ [row.employee_number, row.job_title, row.remuneration_label].filter(Boolean).join(' · ') }}</p>
                </div>
                <Badge v-if="! row.in_post" variant="outline">Plus en poste</Badge>
                <span v-if="Number(row.deductions_amount) > 0" class="text-right text-xs leading-tight text-muted-foreground">
                    Brut <span class="tabular-nums">{{ formatMoney(row.gross) }}</span><br />
                    Retenues <span class="tabular-nums text-rose-700 dark:text-rose-300">− {{ formatMoney(row.deductions_amount) }}</span>
                </span>
                <span class="rounded-lg bg-primary/5 px-3 py-1.5 text-sm font-bold tabular-nums text-primary" :title="Number(row.deductions_amount) > 0 ? 'À verser : brut moins les retenues de dettes' : 'À verser'">{{ formatMoney(row.total) }}</span>
            </header>

            <ul class="divide-y divide-border text-sm">
                <li v-for="(line, index) in row.lines" :key="`${line.kind}-${line.uuid ?? index}`" class="flex items-center gap-3 px-4 py-2">
                    <component :is="LINE_KINDS[line.kind]?.icon ?? Gift" class="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span class="min-w-0 flex-1">
                        <span class="text-foreground">{{ line.label }}</span>
                        <span class="text-xs text-muted-foreground"> · {{ LINE_KINDS[line.kind]?.label ?? line.kind }}</span>
                    </span>
                    <span :class="['tabular-nums', line.kind === 'DEBT' ? 'text-rose-700 dark:text-rose-300' : 'text-foreground']">{{ formatMoney(line.amount) }}</span>
                </li>
                <li v-if="! row.lines.length" class="px-4 py-2 text-xs text-muted-foreground">Aucune ligne ce mois-ci.</li>
            </ul>

            <footer class="flex flex-wrap items-center gap-2 border-t border-border px-4 py-3">
                <template v-if="row.payment">
                    <Badge variant="success"><Banknote class="h-3.5 w-3.5" />Payée</Badge>
                    <span class="text-xs text-muted-foreground">{{ formatDateTime(row.payment.paid_at) }} · {{ row.payment.paid_by }}<template v-if="row.payment.payment_note"> — {{ row.payment.payment_note }}</template></span>
                    <Button v-if="can('salary_payments.cancel')" type="button" size="sm" variant="ghost" class="ms-auto text-destructive hover:text-destructive" @click="open('cancel', row)"><Ban class="h-4 w-4" />Annuler</Button>
                </template>
                <template v-else>
                    <Badge variant="outline"><Hourglass class="h-3.5 w-3.5" />À payer</Badge>
                    <span v-if="! row.payable && month > currentMonth" class="text-xs text-muted-foreground">Mois pas encore commencé.</span>
                    <Button v-if="can('salary_payments.pay') && row.payable" type="button" size="sm" variant="success" class="ms-auto" @click="open('pay', row)"><Banknote class="h-4 w-4" />Marquer payé</Button>
                </template>
                <details v-if="row.cancelled.length" class="w-full text-xs">
                    <summary class="cursor-pointer text-muted-foreground hover:text-foreground">{{ row.cancelled.length }} paie{{ row.cancelled.length > 1 ? 's' : '' }} annulée{{ row.cancelled.length > 1 ? 's' : '' }}</summary>
                    <p v-for="payment in row.cancelled" :key="payment.uuid" class="mt-1 text-muted-foreground">{{ formatMoney(payment.total) }} · {{ formatDateTime(payment.cancelled_at) }} · {{ payment.cancelled_by }} — {{ payment.cancel_reason }}</p>
                </details>
            </footer>
        </Card>

        <ConfirmModal
            :open="pending !== null"
            :title="pending?.mode === 'cancel' ? 'Annuler la paie' : 'Marquer la paie payée'"
            :description="pending ? `${pending.row.name} · ${monthLabel(month)}` : ''"
            :confirm-label="pending?.mode === 'cancel' ? 'Annuler la paie' : 'Marquer payé'"
            :tone="pending?.mode === 'cancel' ? 'danger' : 'success'"
            :icon="pending?.mode === 'cancel' ? Ban : Banknote"
            :processing="form.processing"
            :disabled="pending?.mode === 'cancel' && ! form.reason.trim()"
            :dismissible="false"
            @update:open="(value) => value || form.processing || (pending = null)"
            @confirm="confirm"
        >
            <div v-if="pending" class="space-y-4 text-sm">
                <template v-if="pending.mode === 'pay'">
                    <p class="text-foreground">Montant à verser : <strong class="tabular-nums">{{ formatMoney(pending.row.total) }}</strong>, dont {{ formatMoney(pending.row.advantages_amount) }} d’avantages<template v-if="Number(pending.row.deductions_amount) > 0">, après {{ formatMoney(pending.row.deductions_amount) }} de retenues de dettes</template>.</p>
                    <p class="text-muted-foreground">Le serveur recompte et fige les lignes ; les avantages portés passent « Payé » et ne se paieront pas deux fois<template v-if="Number(pending.row.deductions_amount) > 0"> ; chaque retenue devient un remboursement de la dette</template>.</p>
                    <FormField label="Note" hint="(facultatif)">
                        <Textarea v-model="form.note" :rows="2" maxlength="500" placeholder="Ex. virement BOA du 30/09" />
                    </FormField>
                </template>
                <template v-else>
                    <p class="text-muted-foreground">La paie reste dans l’historique, marquée annulée ; ses avantages repassent en attente et ses retenues de dettes sont annulées (la dette redevient due d’autant).</p>
                    <FormField label="Motif" required>
                        <Textarea v-model="form.reason" :rows="2" maxlength="1000" placeholder="Pourquoi cette paie est annulée" />
                    </FormField>
                </template>
                <p v-if="error" class="text-sm font-medium text-destructive">{{ error }}</p>
            </div>
        </ConfirmModal>
    </div>
</template>
