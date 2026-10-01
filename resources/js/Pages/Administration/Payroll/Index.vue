<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Ban, Banknote, Building2, CalendarDays, ChevronLeft, ChevronRight, CircleAlert, CircleCheck, FileSpreadsheet, FileText, Gift,
    HandCoins, Hourglass, Landmark, Receipt, ShieldCheck, SlidersHorizontal, Smartphone, Wallet, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { hrContext, hrUrl } from '@/utilities/hrUrl';
import { monthLabel, shiftMonth } from '@/utilities/bonus';
import { DEDUCTION_KINDS } from '@/utilities/payroll';

/**
 * ADR-227 / ADR-233 — la paie du mois : salaire de base + avantages du mois = brut ; les
 * retenues légales (CNAPS, organisme médical, IRSA, selon les paramètres de paie) et les
 * dettes du personnel (ADR-228) s'en retranchent = net à verser. Les charges patronales se
 * lisent pour information. « Marquer payé » (un salarié ou une sélection) fige la paie, ses
 * paramètres et son mode de paiement ; le virement se fait hors RIVO. Le serveur calcule
 * tout : l'écran ne recompte rien.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    month: { type: String, required: true },
    currentMonth: { type: String, required: true },
    board: { type: Object, required: true },
    bulkLimit: { type: Number, default: 100 },
});

const { can } = usePermissions();
const page = usePage();

// ADR-229 — les dettes du personnel se gèrent dans Finance, au portail.
const staffDebtsUrl = computed(() => {
    const context = hrContext();

    return context && can('staff_debts.view') ? `/super-admin/sites/${encodeURIComponent(context.site.code)}/finance/dettes?vue=en-cours` : null;
});

const LINE_KINDS = {
    BASE: { label: 'Salaire de base', icon: Wallet },
    DECLARED: { label: 'Avantage de la fiche', icon: Gift },
    ENTRY: { label: 'Avantage saisi', icon: HandCoins },
    // Retiré (ADR-226) : reste lisible sur une paie déjà payée, dont les lignes sont figées.
    ACTS: { label: 'Avantages à l’acte', icon: Gift },
    CNAPS: { label: 'Cotisation', icon: ShieldCheck },
    HEALTH: { label: 'Cotisation', icon: ShieldCheck },
    IRSA: { label: 'Impôt sur le salaire', icon: Receipt },
    DEBT: { label: 'Retenue de dette', icon: Landmark },
};
const MODE_ICONS = { BANK: Building2, MOBILE_MONEY: Smartphone, CASH: Banknote };

const goTo = (month) => router.get(hrUrl('/administration/paie'), { mois: month }, { preserveScroll: true });

const summary = computed(() => props.board.summary);
const cards = computed(() => [
    { key: 'to_pay', icon: Hourglass, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', value: summary.value.to_pay, label: 'À payer', hint: `${formatMoney(summary.value.amount_to_pay)} net à verser` },
    { key: 'gross', icon: Wallet, tone: 'bg-primary/10 text-primary', value: formatMoney(summary.value.gross_to_pay), label: 'Brut à payer', hint: `dont ${formatMoney(summary.value.advantages_to_pay)} d’avantages` },
    { key: 'legal', icon: ShieldCheck, tone: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300', value: formatMoney(summary.value.legal_to_pay), label: 'Retenues légales', hint: props.board.settings.legal_enabled ? 'CNAPS, organisme médical, IRSA' : 'Non activées' },
    { key: 'debts', icon: Landmark, tone: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300', value: formatMoney(summary.value.deductions_to_pay ?? 0), label: 'Retenues de dettes', hint: 'Sur les paies à payer' },
    { key: 'employer', icon: Building2, tone: 'bg-muted text-muted-foreground', value: formatMoney(summary.value.employer_to_pay), label: 'Charges patronales', hint: `Coût du mois ${formatMoney(summary.value.cost_month)}` },
    { key: 'paid', icon: CircleCheck, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', value: summary.value.paid, label: 'Payées', hint: `${formatMoney(summary.value.amount_paid)} versés` },
]);

const isDeduction = (line) => DEDUCTION_KINDS.includes(line.kind);

// — Sélection : payer, imprimer les bulletins, exporter. —
const selected = ref([]);
watch(() => [props.month, props.board.rows.length], () => { selected.value = []; });
const payable = computed(() => props.board.rows.filter((row) => row.payable));
const selectedRows = computed(() => props.board.rows.filter((row) => selected.value.includes(row.uuid)));
const selectedPayable = computed(() => selectedRows.value.filter((row) => row.payable));
const selectedNet = computed(() => selectedPayable.value.reduce((sum, row) => sum + Number(row.total), 0));
const allPayableSelected = computed(() => payable.value.length > 0 && payable.value.every((row) => selected.value.includes(row.uuid)));
const toggleAllPayable = (value) => { selected.value = value ? payable.value.map((row) => row.uuid).slice(0, props.bulkLimit) : []; };
const toggle = (uuid, value) => { selected.value = value ? [...new Set([...selected.value, uuid])] : selected.value.filter((item) => item !== uuid); };

const query = (extra = {}) => {
    const params = new URLSearchParams({ mois: props.month, ...extra });
    for (const uuid of selected.value) params.append('uuids[]', uuid);

    return params.toString();
};
const payslipsUrl = (uuids = null) => {
    if (uuids) {
        const params = new URLSearchParams({ mois: props.month });
        uuids.forEach((uuid) => params.append('uuids[]', uuid));

        return hrUrl(`/administration/paie/bulletins?${params}`);
    }

    return hrUrl(`/administration/paie/bulletins?${query()}`);
};
const exportUrl = (type) => hrUrl(`/administration/paie/export?${query({ type })}`);

// — Payer, annuler. —
const pending = ref(null);
const form = useForm({ note: '', reason: '' });
const open = (mode, row = null) => {
    form.reset();
    form.clearErrors();
    pending.value = { mode, row };
};
const confirm = () => {
    const { mode, row } = pending.value;
    const options = { preserveScroll: true, onSuccess: () => { pending.value = null; if (mode === 'batch') selected.value = []; } };
    if (mode === 'pay') {
        form.transform((data) => ({ employee_uuid: row.uuid, mois: props.month, note: data.note })).post(hrUrl('/administration/paie/payer'), options);
    } else if (mode === 'batch') {
        form.transform((data) => ({ employee_uuids: selectedPayable.value.map((item) => item.uuid), mois: props.month, note: data.note })).post(hrUrl('/administration/paie/payer-lot'), options);
    } else {
        form.transform((data) => ({ reason: data.reason })).post(hrUrl(`/administration/paie/${row.payment.uuid}/annuler`), options);
    }
};
const error = computed(() => Object.values(form.errors)[0] ?? '');
const modal = computed(() => {
    const mode = pending.value?.mode;
    if (mode === 'cancel') return { title: 'Annuler la paie', confirm: 'Annuler la paie', tone: 'danger', icon: Ban };
    if (mode === 'batch') return { title: `Marquer ${selectedPayable.value.length} paies payées`, confirm: 'Marquer payées', tone: 'success', icon: Banknote };

    return { title: 'Marquer la paie payée', confirm: 'Marquer payé', tone: 'success', icon: Banknote };
});

// Rapport d'une paie en lot : ce qui est passé, ce qui ne l'est pas, et pourquoi.
const report = computed(() => (page.props.flash?.bulk_report?.action === 'payroll_pay' ? page.props.flash.bulk_report : null));
const reportHidden = ref(false);
watch(report, () => { reportHidden.value = false; });
</script>

<template>
    <Head title="Paie du mois" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines · Pilotage"
            title="Paie du mois"
            description="Brut (salaire + avantages) − retenues légales − dettes = net à verser. Marquer payé fige la paie, ses paramètres et son mode de paiement ; le virement se fait hors RIVO."
            :icon="Banknote"
        >
            <template #actions>
                <Button v-if="can('salary_settings.view')" :as="Link" :href="hrUrl('/administration/paie/parametres')" variant="outline"><SlidersHorizontal class="h-4 w-4" />Paramètres</Button>
                <Button v-if="staffDebtsUrl" :as="Link" :href="staffDebtsUrl" variant="outline"><Landmark class="h-4 w-4" />Dettes</Button>
                <Button v-if="can('advantage_entries.view')" :as="Link" :href="hrUrl(`/administration/bonus?onglet=saisis&mois=${month}`)" variant="outline"><HandCoins class="h-4 w-4" />Avantages</Button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-1 rounded-xl border border-border bg-card p-1 shadow-sm">
                <Button type="button" variant="ghost" size="icon" aria-label="Mois précédent" @click="goTo(shiftMonth(month, -1))"><ChevronLeft class="h-4 w-4" /></Button>
                <span class="flex min-w-44 items-center justify-center gap-2 px-2 text-sm font-semibold capitalize text-foreground">
                    <CalendarDays class="h-4 w-4 text-muted-foreground" />{{ monthLabel(month) }}
                </span>
                <Button type="button" variant="ghost" size="icon" aria-label="Mois suivant" :disabled="month >= currentMonth" @click="goTo(shiftMonth(month, 1))"><ChevronRight class="h-4 w-4" /></Button>
            </div>
            <div v-if="board.rows.length" class="ms-auto flex flex-wrap gap-2">
                <Button :as="'a'" :href="payslipsUrl(board.rows.map((row) => row.uuid))" variant="outline" size="sm"><FileText class="h-4 w-4" />Tous les bulletins</Button>
                <template v-if="can('salary_payments.export')">
                    <Button :as="'a'" :href="hrUrl(`/administration/paie/export?mois=${month}&type=journal`)" variant="outline" size="sm"><FileSpreadsheet class="h-4 w-4" />Journal de paie</Button>
                    <Button :as="'a'" :href="hrUrl(`/administration/paie/export?mois=${month}&type=virements`)" variant="outline" size="sm"><Building2 class="h-4 w-4" />Liste de virement</Button>
                </template>
            </div>
        </div>

        <div v-if="! board.settings.legal_enabled" class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            <CircleAlert class="h-5 w-5 shrink-0" />
            <p class="min-w-0 flex-1">
                <strong>Retenues légales non activées</strong> : le net est le brut moins les dettes, sans CNAPS, {{ board.settings.health_label }} ni IRSA.
                <template v-if="! board.settings.configured"> Un barème Madagascar est proposé : faites-le vérifier, puis activez-le.</template>
            </p>
            <Button v-if="can('salary_settings.view')" :as="Link" :href="hrUrl('/administration/paie/parametres')" size="sm" variant="outline"><SlidersHorizontal class="h-4 w-4" />Paramètres de paie</Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <div v-for="card in cards" :key="card.key" class="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone]"><component :is="card.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-xl font-bold leading-none tabular-nums text-foreground">{{ card.value }}</span>
                    <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                    <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                </span>
            </div>
        </div>

        <div v-if="report && ! reportHidden" class="flex items-start gap-3 rounded-lg border border-border bg-card px-4 py-3 shadow-sm" role="status">
            <component :is="report.failed.length ? CircleAlert : CircleCheck" :class="['mt-0.5 h-5 w-5 shrink-0', report.failed.length ? 'text-amber-600' : 'text-emerald-600']" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-foreground">{{ report.done }} sur {{ report.total }} paie{{ report.total > 1 ? 's' : '' }} marquée{{ report.done > 1 ? 's' : '' }} payée{{ report.done > 1 ? 's' : '' }} · {{ formatMoney(report.amount) }} à verser</p>
                <ul v-if="report.failed.length" class="mt-2 space-y-1 text-sm">
                    <li v-for="(failure, index) in report.failed" :key="index" class="text-muted-foreground"><span class="font-medium text-foreground">{{ failure.label }}</span> — {{ failure.message }}</li>
                </ul>
            </div>
            <Button type="button" size="icon-xs" variant="ghost" aria-label="Fermer le rapport" @click="reportHidden = true"><X class="h-4 w-4" /></Button>
        </div>

        <!-- Barre de sélection. -->
        <div v-if="board.rows.length" class="sticky top-0 z-10 flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card/95 px-4 py-2.5 shadow-sm backdrop-blur">
            <label class="flex items-center gap-2 text-sm font-medium text-foreground">
                <Checkbox :model-value="allPayableSelected" :disabled="! payable.length" aria-label="Sélectionner toutes les paies à payer" @update:model-value="toggleAllPayable" />
                Toutes les paies à payer ({{ payable.length }})
            </label>
            <span v-if="selected.length" class="text-sm text-muted-foreground">{{ selected.length }} sélectionnée{{ selected.length > 1 ? 's' : '' }}<template v-if="selectedPayable.length"> · {{ formatMoney(selectedNet) }} net</template></span>
            <div v-if="selected.length" class="ms-auto flex flex-wrap gap-2">
                <Button v-if="can('salary_payments.pay') && selectedPayable.length" type="button" size="sm" variant="success" @click="open('batch')"><Banknote class="h-4 w-4" />Marquer payées ({{ selectedPayable.length }})</Button>
                <Button :as="'a'" :href="payslipsUrl()" size="sm" variant="outline"><FileText class="h-4 w-4" />Bulletins ({{ selected.length }})</Button>
                <Button v-if="can('salary_payments.export')" :as="'a'" :href="exportUrl('journal')" size="sm" variant="outline"><FileSpreadsheet class="h-4 w-4" />Journal</Button>
                <Button v-if="can('salary_payments.export')" :as="'a'" :href="exportUrl('virements')" size="sm" variant="outline"><Building2 class="h-4 w-4" />Virements</Button>
                <Button type="button" size="sm" variant="ghost" @click="selected = []">Désélectionner</Button>
            </div>
        </div>

        <Card v-if="! board.rows.length" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><Banknote class="h-6 w-6" /></span>
            <p class="text-sm font-semibold text-foreground">Rien à payer pour {{ monthLabel(month) }}</p>
            <p class="max-w-md text-sm text-muted-foreground">La paie liste les personnes en poste dont la rémunération a un montant (étape Rémunération du dossier), et celles qui ont des avantages ce mois-ci.</p>
        </Card>

        <Card v-for="row in board.rows" :key="row.uuid" :class="cn('overflow-hidden', selected.includes(row.uuid) && 'ring-1 ring-primary/50')">
            <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border px-4 py-3">
                <Checkbox :model-value="selected.includes(row.uuid)" :aria-label="`Sélectionner ${row.name}`" @update:model-value="(value) => toggle(row.uuid, value)" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-foreground">{{ row.name }}</p>
                    <p class="truncate text-xs text-muted-foreground">{{ [row.employee_number, row.job_title, row.remuneration_label].filter(Boolean).join(' · ') }}</p>
                </div>
                <Badge v-if="! row.in_post" variant="outline">Plus en poste</Badge>
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground" :title="row.payment_mode.summary">
                    <component :is="MODE_ICONS[row.payment_mode.mode] ?? CircleAlert" :class="['h-4 w-4', ! row.payment_mode.mode && 'text-amber-600']" />
                    <span class="max-w-[16rem] truncate">{{ row.payment_mode.label }} · {{ row.payment_mode.summary }}</span>
                </span>
                <span class="text-right text-xs leading-tight text-muted-foreground">
                    Brut <span class="tabular-nums text-foreground">{{ formatMoney(row.gross) }}</span><br />
                    Retenues <span class="tabular-nums text-rose-700 dark:text-rose-300">− {{ formatMoney(row.deductions_amount) }}</span>
                </span>
                <span class="rounded-lg bg-primary/5 px-3 py-1.5 text-end">
                    <span class="block text-[11px] text-muted-foreground">Net à verser</span>
                    <span class="block text-sm font-bold tabular-nums text-primary">{{ formatMoney(row.total) }}</span>
                </span>
            </header>

            <ul class="divide-y divide-border text-sm">
                <li v-for="(line, index) in row.lines" :key="`${line.kind}-${line.uuid ?? index}`" class="flex items-center gap-3 px-4 py-2">
                    <component :is="LINE_KINDS[line.kind]?.icon ?? Gift" class="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span class="min-w-0 flex-1">
                        <span class="text-foreground">{{ line.label }}</span>
                        <span class="text-xs text-muted-foreground"> · {{ LINE_KINDS[line.kind]?.label ?? line.kind }}</span>
                    </span>
                    <span :class="['tabular-nums', isDeduction(line) ? 'text-rose-700 dark:text-rose-300' : 'text-foreground']">{{ formatMoney(line.amount) }}</span>
                </li>
                <li v-if="row.legal && ! row.legal.applies && row.legal.reason && board.settings.legal_enabled" class="px-4 py-2 text-xs text-muted-foreground">{{ row.legal.reason }}</li>
                <li v-if="row.employer_lines.length" class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-muted/30 px-4 py-2 text-xs text-muted-foreground">
                    <Building2 class="h-3.5 w-3.5" />
                    <span>Charges patronales (information) :</span>
                    <span v-for="line in row.employer_lines" :key="line.kind">{{ line.label }} {{ formatMoney(line.amount) }}</span>
                    <span class="ms-auto">Coût employeur <strong class="tabular-nums text-foreground">{{ formatMoney(row.cost) }}</strong></span>
                </li>
            </ul>

            <footer class="flex flex-wrap items-center gap-2 border-t border-border px-4 py-3">
                <template v-if="row.payment">
                    <Badge variant="success"><Banknote class="h-3.5 w-3.5" />Payée</Badge>
                    <span class="text-xs text-muted-foreground">{{ formatDateTime(row.payment.paid_at) }} · {{ row.payment.paid_by }}<template v-if="row.payment.payment_note"> — {{ row.payment.payment_note }}</template></span>
                </template>
                <template v-else>
                    <Badge variant="outline"><Hourglass class="h-3.5 w-3.5" />À payer</Badge>
                    <span v-if="! row.payable && month > currentMonth" class="text-xs text-muted-foreground">Mois pas encore commencé.</span>
                </template>
                <div class="ms-auto flex flex-wrap gap-2">
                    <Button :as="'a'" :href="payslipsUrl([row.uuid])" size="sm" variant="ghost"><FileText class="h-4 w-4" />Bulletin</Button>
                    <Button v-if="row.payment && can('salary_payments.cancel')" type="button" size="sm" variant="ghost" class="text-destructive hover:text-destructive" @click="open('cancel', row)"><Ban class="h-4 w-4" />Annuler</Button>
                    <Button v-if="! row.payment && can('salary_payments.pay') && row.payable" type="button" size="sm" variant="success" @click="open('pay', row)"><Banknote class="h-4 w-4" />Marquer payé</Button>
                </div>
                <details v-if="row.cancelled.length" class="w-full text-xs">
                    <summary class="cursor-pointer text-muted-foreground hover:text-foreground">{{ row.cancelled.length }} paie{{ row.cancelled.length > 1 ? 's' : '' }} annulée{{ row.cancelled.length > 1 ? 's' : '' }}</summary>
                    <p v-for="payment in row.cancelled" :key="payment.uuid" class="mt-1 text-muted-foreground">{{ formatMoney(payment.total) }} · {{ formatDateTime(payment.cancelled_at) }} · {{ payment.cancelled_by }} — {{ payment.cancel_reason }}</p>
                </details>
            </footer>
        </Card>

        <ConfirmModal
            :open="pending !== null"
            :title="modal.title"
            :description="pending?.row ? `${pending.row.name} · ${monthLabel(month)}` : monthLabel(month)"
            :confirm-label="modal.confirm"
            :tone="modal.tone"
            :icon="modal.icon"
            :processing="form.processing"
            :disabled="pending?.mode === 'cancel' && ! form.reason.trim()"
            :dismissible="false"
            @update:open="(value) => value || form.processing || (pending = null)"
            @confirm="confirm"
        >
            <div v-if="pending" class="space-y-4 text-sm">
                <template v-if="pending.mode === 'pay'">
                    <dl class="divide-y divide-border rounded-lg border border-border">
                        <div class="flex justify-between px-3 py-1.5"><dt>Brut</dt><dd class="tabular-nums">{{ formatMoney(pending.row.gross) }}</dd></div>
                        <div v-if="Number(pending.row.legal_amount) > 0" class="flex justify-between px-3 py-1.5"><dt>Retenues légales</dt><dd class="tabular-nums text-rose-700 dark:text-rose-300">− {{ formatMoney(pending.row.legal_amount) }}</dd></div>
                        <div v-if="Number(pending.row.debts_amount) > 0" class="flex justify-between px-3 py-1.5"><dt>Retenues de dettes</dt><dd class="tabular-nums text-rose-700 dark:text-rose-300">− {{ formatMoney(pending.row.debts_amount) }}</dd></div>
                        <div class="flex justify-between bg-primary/5 px-3 py-2 font-semibold"><dt>Net à verser</dt><dd class="tabular-nums text-primary">{{ formatMoney(pending.row.total) }}</dd></div>
                        <div class="flex justify-between px-3 py-1.5 text-xs text-muted-foreground"><dt>Mode</dt><dd>{{ pending.row.payment_mode.label }} · {{ pending.row.payment_mode.summary }}</dd></div>
                    </dl>
                    <p class="text-muted-foreground">Le serveur recompte et fige les lignes, les paramètres de paie et le mode de paiement ; les avantages portés passent « Payé »<template v-if="Number(pending.row.debts_amount) > 0"> et chaque retenue devient un remboursement de la dette</template>.</p>
                </template>
                <template v-else-if="pending.mode === 'batch'">
                    <p class="text-foreground"><strong>{{ selectedPayable.length }}</strong> paie{{ selectedPayable.length > 1 ? 's' : '' }} · <strong class="tabular-nums">{{ formatMoney(selectedNet) }}</strong> net à verser.</p>
                    <p class="text-muted-foreground">Chaque paie est recomptée et figée séparément : un refus n’empêche pas les autres, et le rapport dit pourquoi.</p>
                </template>
                <template v-else>
                    <p class="text-muted-foreground">La paie reste dans l’historique, marquée annulée ; ses avantages repassent en attente et ses retenues de dettes sont annulées (la dette redevient due d’autant).</p>
                    <FormField label="Motif" required>
                        <Textarea v-model="form.reason" :rows="2" maxlength="1000" placeholder="Pourquoi cette paie est annulée" />
                    </FormField>
                </template>
                <FormField v-if="pending.mode !== 'cancel'" label="Note" hint="(facultatif)">
                    <Textarea v-model="form.note" :rows="2" maxlength="500" placeholder="Ex. virement BOA du 30/09" />
                </FormField>
                <p v-if="error" class="text-sm font-medium text-destructive">{{ error }}</p>
            </div>
        </ConfirmModal>
    </div>
</template>
