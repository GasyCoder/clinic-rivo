<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, Ban, BellRing, BriefcaseBusiness, CalendarDays, CircleAlert, CircleCheck, CircleX, DoorOpen, FileSignature, FileText, Gavel, Gift, HandCoins,
    Hash, History, Landmark, Lock, NotebookPen, Pencil, Percent, Printer, Scale, ScrollText, ShieldAlert, ShieldCheck, Timer, UserRound, Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import StaffDebtRepayments from '@/Components/StaffDebts/StaffDebtRepayments.vue';
import StaffDebtStatusBadge from '@/Components/StaffDebts/StaffDebtStatusBadge.vue';
import StaffDebtTermsFields from '@/Components/StaffDebts/StaffDebtTermsFields.vue';
import { formatDate, formatDateTime, monthLabel, shiftMonth } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { staffDebtUrl } from '@/utilities/staffDebtUrl';
import { debtPlan, fromMinor, periodOptions, planSummary, repaidShare as debtRepaidShare, ruleIssues, tierLabel, toMinor, totalWithInterest } from '@/utilities/staffDebts';

/**
 * ADR-228 / ADR-229 — une dette du personnel, dans Finance au portail. Le DG l'accorde en
 * ajustant montant, mensualité, premier mois et mode de remboursement, ou la refuse avec
 * un motif ; il ajuste ensuite, annule un accord pas encore versé, marque versé ce qui a
 * été remis hors RIVO, remet le reste, relance un retard. L'intérêt de la tranche du site
 * se fige à l'accord (il peut le remettre) ; des conditions hors des limites du site sont
 * une dérogation, à confirmer. Chaque bouton suit le droit servi par le serveur, qui
 * revérifie tout.
 *
 * ADR-234 — une demande ne porte que le montant : le DG fixe le remboursement par mois et
 * le premier mois (proposé : le mois suivant). Les règles acceptées par l'employé et les
 * dettes déjà en cours à sa demande se lisent sur la demande.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    debt: { type: Object, required: true },
    repaymentModes: { type: Array, required: true },
    disbursementModes: { type: Array, required: true },
    currentMonth: { type: String, required: true },
    today: { type: String, required: true },
});

const base = computed(() => staffDebtUrl(`/finance/dettes/${props.debt.uuid}`));
const salaryDeclared = computed(() => props.debt.employee?.salary_declared ?? false);
const rules = computed(() => props.debt.rules ?? null);
const hasTiers = computed(() => (rules.value?.interest_tiers ?? []).length > 0);
const maxInstallment = computed(() => rules.value?.installment_cap?.available ?? null);
const amountOnly = computed(() => ! props.debt.requested.installment_amount);
const tooManyDebts = computed(() => Boolean(rules.value?.max_open_debts) && (rules.value?.engaged_debts ?? 0) >= rules.value.max_open_debts);
const MODE_ICONS = { SALARY: Wallet, CASH: HandCoins };

// Accorder : le montant demandé, que le DG ajuste ; le remboursement est le sien à fixer
// (ADR-234) — une demande d'avant garde sa mensualité et son premier mois proposés.
const requestedPeriod = props.debt.requested.first_period;
const decision = useForm({
    amount: props.debt.requested.amount.replace(/\.00$/, ''),
    installment_amount: (props.debt.requested.installment_amount ?? '').replace(/\.00$/, ''),
    first_period: requestedPeriod ? (requestedPeriod < props.currentMonth ? props.currentMonth : requestedPeriod) : shiftMonth(props.currentMonth, 1),
    repayment_mode: salaryDeclared.value ? 'SALARY' : 'CASH',
    note: '',
    waive_interest: false,
    waive_penalty: false,
    accept_derogations: false,
});
const decisionTotals = computed(() => (decision.waive_interest ? { interest: null, total: decision.amount } : totalWithInterest(decision.amount, rules.value?.interest_tiers ?? [])));
const decisionPlan = computed(() => debtPlan(decisionTotals.value?.total ?? decision.amount, decision.installment_amount, decision.first_period));
// Ce qui dépasse les limites du site : l'écran le dit, le serveur le revérifie.
const decisionIssues = computed(() => {
    const issues = Object.values(ruleIssues(decision.amount, decision.installment_amount, rules.value, formatMoney, maxInstallment.value));
    if (tooManyDebts.value) issues.push(`Déjà ${rules.value.engaged_debts} dette${rules.value.engaged_debts > 1 ? 's' : ''} accordée ou en cours (maximum : ${rules.value.max_open_debts}).`);

    return issues;
});
const needsDerogation = computed(() => decisionIssues.value.length > 0 || Boolean(decision.errors.derogation));
const approving = ref(false);
const approve = () => decision.post(`${base.value}/accorder`, { preserveScroll: true, onSuccess: () => { approving.value = false; } });

// Refuser, annuler un accord, remettre : un motif.
const reasonAction = ref(null);
const reasonForm = useForm({ reason: '' });
const REASON_ACTIONS = {
    refuse: { title: 'Refuser la demande', label: 'Refuser', path: 'refuser', icon: CircleX, tone: 'danger', help: 'L’employé est prévenu, avec ce motif.' },
    cancel: { title: 'Annuler l’accord', label: 'Annuler l’accord', path: 'annuler', icon: Ban, tone: 'danger', help: 'Rien n’a été versé : la dette se clôt, l’employé est prévenu.' },
    write_off: { title: 'Remettre le reste', label: 'Remettre le reste', path: 'remettre', icon: Gift, tone: 'warning', help: 'Le reste dû n’est plus à rembourser : la paie ne retient plus rien et la dette se clôt.' },
};
const openReason = (key) => {
    reasonForm.reset();
    reasonForm.clearErrors();
    reasonAction.value = key;
};
const submitReason = () => reasonForm.post(`${base.value}/${REASON_ACTIONS[reasonAction.value].path}`, {
    preserveScroll: true,
    onSuccess: () => { reasonAction.value = null; },
});

// Ajuster une dette accordée ou en cours.
const adjusting = ref(false);
const adjustment = useForm({ amount: '', installment_amount: '', first_period: '', repayment_mode: '', reason: '', accept_derogations: false });
const openAdjust = () => {
    const granted = props.debt.granted;
    adjustment.defaults({
        amount: granted.amount.replace(/\.00$/, ''),
        installment_amount: granted.installment_amount.replace(/\.00$/, ''),
        first_period: granted.first_period,
        repayment_mode: granted.repayment_mode,
        reason: '',
        accept_derogations: false,
    });
    adjustment.reset();
    adjustment.clearErrors();
    adjusting.value = true;
};
const submitAdjust = () => adjustment
    .transform((data) => (props.debt.status === 'ACTIVE' ? { ...data, amount: null } : data))
    .post(`${base.value}/ajuster`, { preserveScroll: true, onSuccess: () => { adjusting.value = false; } });

// Verser : l'argent est remis hors RIVO, le versement se marque ici.
const disbursing = ref(false);
const disbursement = useForm({ disbursed_on: props.today, disbursement_mode: 'CASH', reference: '', note: '' });
const disburse = () => disbursement.post(`${base.value}/verser`, { preserveScroll: true, onSuccess: () => { disbursing.value = false; } });

const repaidShare = computed(() => debtRepaidShare(props.debt));

// Relancer un remboursement en espèces en retard : l'employé et le RH du site sont prévenus.
const reminding = ref(false);
const reminder = useForm({});
const remind = () => reminder.post(`${base.value}/relancer`, { preserveScroll: true, onSuccess: () => { reminding.value = false; } });

// ADR-230 — les pénalités de retard (remboursement en espèces seulement), figées à l'accord.
const rateText = (value) => String(value ?? '').replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1').replace('.', ',');
const penalty = computed(() => props.debt.penalty ?? { rule: null, due: '0.00', items: [] });
const hasPenalties = computed(() => Boolean(penalty.value.rule) || penalty.value.items.length > 0);
const sitePenalty = computed(() => rules.value?.penalty_rate && Number(rules.value.penalty_rate) > 0);
const waivingPenalty = ref(null);
const penaltyWaiver = useForm({ reason: '' });
const openPenaltyWaiver = (item) => {
    penaltyWaiver.reset();
    penaltyWaiver.clearErrors();
    waivingPenalty.value = item;
};
const waivePenalty = () => penaltyWaiver.post(`${base.value}/penalites/${waivingPenalty.value.uuid}/remettre`, {
    preserveScroll: true,
    onSuccess: () => { waivingPenalty.value = null; },
});

// ADR-230 — le règlement au départ, convenu avec la personne qui a quitté la clinique.
const departure = computed(() => props.debt.departure ?? null);
const departureTerms = computed(() => departure.value?.terms ?? null);
const settlingDeparture = ref(false);
const departureForm = useForm({
    retained_amount: '',
    retained_on: '',
    write_off_amount: '',
    waive_penalties: false,
    installment_amount: '',
    first_period: props.currentMonth,
    keep_penalties: true,
    note: '',
});
const departurePeriods = computed(() => periodOptions(props.currentMonth));
// Aperçu seulement : le serveur recompte tout au moment de l'enregistrement.
const departureRest = computed(() => {
    if (! departure.value) return null;
    const balance = toMinor(departure.value.balance) ?? 0;
    const penalties = departureForm.waive_penalties ? (toMinor(departure.value.penalties_due) ?? 0) : 0;
    const rest = balance - penalties - (toMinor(departureForm.retained_amount) ?? 0) - (toMinor(departureForm.write_off_amount) ?? 0);

    return fromMinor(Math.max(0, rest));
});
const departureRestsDue = computed(() => (toMinor(departureRest.value) ?? 0) > 0);
const departurePlan = computed(() => (departureRestsDue.value ? debtPlan(departureRest.value, departureForm.installment_amount, departureForm.first_period) : null));
const departureNeedsWriteOffRight = computed(() => (toMinor(departureForm.write_off_amount) ?? 0) > 0 || departureForm.waive_penalties);
const departureReady = computed(() => departureForm.note.trim().length >= 5
    && (! (toMinor(departureForm.retained_amount) > 0) || Boolean(departureForm.retained_on))
    && (! departureRestsDue.value || Boolean(departurePlan.value))
    && (! departureNeedsWriteOffRight.value || props.debt.can.waive_penalty));
const openDeparture = () => {
    departureForm.reset();
    departureForm.clearErrors();
    settlingDeparture.value = true;
};
const settleDeparture = () => departureForm
    .transform((data) => ({
        ...data,
        retained_amount: data.retained_amount === '' ? null : data.retained_amount,
        retained_on: data.retained_on === '' ? null : data.retained_on,
        write_off_amount: data.write_off_amount === '' ? null : data.write_off_amount,
        installment_amount: departureRestsDue.value ? data.installment_amount : null,
        first_period: departureRestsDue.value ? data.first_period : null,
    }))
    .post(`${base.value}/depart`, { preserveScroll: true, onSuccess: () => { settlingDeparture.value = false; } });
const isZeroMoney = (value) => ! value || Number(value) === 0;

const TIMELINE_ICONS = { requested: FileText, approved: CircleCheck, refused: CircleX, disbursed: Wallet, written_off: Gift, cancelled: Ban, settled: CircleCheck, penalty: Timer, departure: DoorOpen };
const firstError = (form) => Object.values(form.errors)[0] ?? '';
</script>

<template>
    <Head :title="`Dette ${debt.number}`" />
    <div class="w-full space-y-5">
        <div class="flex flex-wrap items-center gap-3">
            <Button :as="Link" :href="staffDebtUrl('/finance/dettes')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" />Dettes du personnel</Button>
        </div>

        <header class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span class="grid h-11 w-11 place-items-center rounded-xl bg-primary/10 text-primary"><HandCoins class="h-5 w-5" /></span>
            <div class="min-w-0 flex-1">
                <h1 class="text-xl font-bold text-foreground">{{ debt.employee_name }}</h1>
                <p class="text-sm text-muted-foreground">Dette {{ debt.number }}<template v-if="debt.employee_number"> · {{ debt.employee_number }}</template> · demandée le {{ formatDateTime(debt.requested_at) }}</p>
            </div>
            <StaffDebtStatusBadge :status="debt.status" :label="debt.status_label" :tone="debt.status_tone" />
        </header>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 space-y-5">
                <Card class="space-y-3 px-5 py-4">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><FileText class="h-4 w-4 text-muted-foreground" />La demande</h2>
                    <dl class="grid gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-xs text-muted-foreground">Montant</dt>
                            <dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(debt.requested.amount) }}</dd>
                            <dd v-if="Number(debt.requested.interest_amount) > 0" class="text-xs text-muted-foreground">+ {{ formatMoney(debt.requested.interest_amount) }} d’intérêt à la demande</dd>
                        </div>
                        <template v-if="! amountOnly">
                            <div><dt class="text-xs text-muted-foreground">Par mois</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(debt.requested.installment_amount) }}</dd></div>
                            <div><dt class="text-xs text-muted-foreground">Plan</dt><dd class="text-foreground">{{ planSummary(debt.requested.plan) }}</dd></div>
                        </template>
                        <div v-else class="sm:col-span-2">
                            <dt class="text-xs text-muted-foreground">Remboursement</dt>
                            <dd class="text-foreground">{{ debt.can.decide ? 'À fixer par vous : la demande ne porte que le montant.' : 'Fixé par le DG à sa décision.' }}</dd>
                        </div>
                    </dl>
                    <p v-if="debt.reason" class="rounded-lg bg-muted/60 px-3 py-2 text-sm text-foreground">{{ debt.reason }}</p>
                    <p v-else class="text-sm text-muted-foreground">Sans motif.</p>
                    <p v-if="debt.engaged_at_request > 0" class="flex items-start gap-2 rounded-lg border border-amber-300/70 bg-amber-50 px-3 py-2 text-sm text-foreground dark:border-amber-900 dark:bg-amber-950/30">
                        <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                        Demandée alors que {{ debt.engaged_at_request }} dette{{ debt.engaged_at_request > 1 ? 's étaient' : ' était' }} déjà en cours : permise par l’autorisation du Super Admin.
                    </p>
                    <details v-if="debt.terms" class="group rounded-lg border border-border px-3 py-2 text-sm">
                        <summary class="flex cursor-pointer list-none items-center gap-2 text-foreground">
                            <ShieldCheck class="h-4 w-4 shrink-0 text-emerald-600" />Règles et conditions acceptées le {{ formatDateTime(debt.terms.accepted_at) }}
                            <span class="ms-auto text-xs text-muted-foreground group-open:hidden">Lire</span>
                        </summary>
                        <ul class="mt-2 space-y-1.5 border-t border-border pt-2 text-xs leading-5 text-muted-foreground">
                            <li v-for="(line, index) in debt.terms.conditions" :key="index" class="flex items-start gap-2"><ScrollText class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ line }}</li>
                        </ul>
                    </details>
                </Card>

                <!-- Le DG décide. -->
                <Card v-if="debt.can.decide" class="space-y-4 border-amber-300/60 px-5 py-4 dark:border-amber-900">
                    <div class="flex items-center gap-2">
                        <Gavel class="h-4 w-4 text-amber-600" />
                        <h2 class="text-sm font-semibold text-foreground">Votre décision</h2>
                        <span class="text-xs text-muted-foreground">{{ amountOnly ? '— fixez le remboursement, ajustez le montant, ou refusez' : '— accordez tel quel, ajustez, ou refusez' }}</span>
                    </div>
                    <p v-if="amountOnly" class="flex items-start gap-2 text-sm text-muted-foreground">
                        <CalendarDays class="mt-0.5 h-4 w-4 shrink-0" />L’employé n’a demandé que le montant : choisissez le remboursement par mois — ou une durée — selon ce que son salaire permet, et le premier mois.
                    </p>
                    <StaffDebtTermsFields
                        :form="decision"
                        :current-month="currentMonth"
                        :salary="debt.employee?.salary"
                        :rules="rules"
                        :max-installment="maxInstallment"
                        :waive-interest="decision.waive_interest"
                        limit-mode="derogation"
                        :disabled="decision.processing"
                    />

                    <label v-if="hasTiers" class="flex items-start gap-3 rounded-xl border border-border px-3 py-2.5 text-sm">
                        <Checkbox v-model="decision.waive_interest" class="mt-0.5" />
                        <span>
                            <span class="block font-semibold text-foreground">Remettre l’intérêt</span>
                            <span class="block text-xs text-muted-foreground">La dette se rembourse sans l’intérêt de sa tranche. Écrit sur la dette.</span>
                        </span>
                    </label>
                    <label v-if="sitePenalty && decision.repayment_mode === 'CASH'" class="flex items-start gap-3 rounded-xl border border-border px-3 py-2.5 text-sm">
                        <Checkbox v-model="decision.waive_penalty" class="mt-0.5" />
                        <span>
                            <span class="block font-semibold text-foreground">Sans pénalité de retard</span>
                            <span class="block text-xs text-muted-foreground">Un retard à la Caisse ne portera aucune pénalité ({{ rateText(rules.penalty_rate) }} % par mois sinon). Écrit sur la dette à l’accord.</span>
                        </span>
                    </label>
                    <p v-if="tooManyDebts" class="flex items-start gap-2 rounded-xl border border-amber-300/70 bg-amber-50 px-3 py-2 text-sm text-foreground dark:border-amber-900 dark:bg-amber-950/30">
                        <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />Déjà {{ rules.engaged_debts }} dette{{ rules.engaged_debts > 1 ? 's' : '' }} accordée ou en cours pour cette personne (maximum du site : {{ rules.max_open_debts }}).
                    </p>

                    <FormField label="Remboursement" as="div" required :error="decision.errors.repayment_mode">
                        <div class="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Mode de remboursement">
                            <button
                                v-for="mode in repaymentModes"
                                :key="mode.value"
                                type="button"
                                role="radio"
                                :aria-checked="decision.repayment_mode === mode.value"
                                :disabled="mode.value === 'SALARY' && ! salaryDeclared"
                                :class="[
                                    'flex items-start gap-3 rounded-xl border px-3 py-2.5 text-left text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60',
                                    decision.repayment_mode === mode.value ? 'border-primary bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:border-primary/40',
                                ]"
                                @click="decision.repayment_mode = mode.value"
                            >
                                <component :is="MODE_ICONS[mode.value]" class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                <span>
                                    <span class="block font-semibold text-foreground">{{ mode.label }}</span>
                                    <span class="block text-xs text-muted-foreground">
                                        <template v-if="mode.value === 'SALARY'">{{ salaryDeclared ? 'Retenue chaque mois sur la paie, jamais plus que le salaire du mois.' : 'Aucun salaire déclaré dans son dossier : impossible.' }}</template>
                                        <template v-else>Remis chaque mois à la Caisse, qui délivre un reçu.</template>
                                    </span>
                                </span>
                            </button>
                        </div>
                    </FormField>

                    <FormField label="Note pour l’employé" :icon="NotebookPen" hint="(facultatif)" :error="decision.errors.note">
                        <Textarea v-model="decision.note" :rows="2" maxlength="1000" placeholder="Ex. accordée sur 4 mois au lieu de 3" />
                    </FormField>

                    <label v-if="needsDerogation" class="flex items-start gap-3 rounded-xl border border-amber-300/70 bg-amber-50 px-3 py-2.5 text-sm dark:border-amber-900 dark:bg-amber-950/30">
                        <Checkbox v-model="decision.accept_derogations" class="mt-0.5" />
                        <span>
                            <span class="block font-semibold text-foreground">J’accorde par dérogation</span>
                            <span class="block text-xs text-muted-foreground">Ces conditions dépassent les limites du site. La dérogation reste écrite sur la dette et dans l’audit.</span>
                        </span>
                    </label>
                    <p v-if="decision.errors.derogation && ! decision.accept_derogations" class="text-sm font-medium text-destructive">{{ decision.errors.derogation }}</p>
                    <p v-if="decision.errors.debt || decision.errors.reason || decision.errors.employee" class="text-sm font-medium text-destructive">{{ decision.errors.debt ?? decision.errors.reason ?? decision.errors.employee }}</p>
                    <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                        <Button type="button" variant="outline" class="text-destructive hover:text-destructive" @click="openReason('refuse')"><CircleX class="h-4 w-4" />Refuser</Button>
                        <Button type="button" variant="success" :disabled="! decisionPlan || decision.processing || (needsDerogation && ! decision.accept_derogations)" @click="approving = true"><CircleCheck class="h-4 w-4" />Accorder</Button>
                    </div>
                </Card>

                <!-- Ce qui a été accordé. -->
                <Card v-if="debt.granted" class="space-y-4 px-5 py-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><CircleCheck class="h-4 w-4 text-emerald-600" />Accordée<template v-if="debt.granted.adjusted"> · ajustée</template></h2>
                        <div class="ms-auto flex flex-wrap gap-2">
                            <Button v-if="debt.can.adjust" type="button" size="sm" variant="outline" @click="openAdjust"><Pencil class="h-4 w-4" />Ajuster</Button>
                            <Button v-if="debt.can.cancel" type="button" size="sm" variant="ghost" class="text-destructive hover:text-destructive" @click="openReason('cancel')"><Ban class="h-4 w-4" />Annuler l’accord</Button>
                            <Button v-if="debt.can.remind" type="button" size="sm" variant="outline" @click="reminding = true"><BellRing class="h-4 w-4" />Relancer</Button>
                            <Button v-if="debt.can.write_off" type="button" size="sm" variant="ghost" @click="openReason('write_off')"><Gift class="h-4 w-4" />Remettre le reste</Button>
                            <Button :as="Link" :href="`${base}/reconnaissance`" size="sm" variant="ghost"><FileSignature class="h-4 w-4" />Reconnaissance de dette</Button>
                        </div>
                    </div>
                    <dl class="grid gap-3 text-sm sm:grid-cols-4">
                        <div>
                            <dt class="text-xs text-muted-foreground">Montant</dt>
                            <dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(debt.granted.amount) }}</dd>
                            <dd v-if="Number(debt.granted.interest.amount) > 0" class="flex items-center gap-1 text-xs text-muted-foreground"><Percent class="h-3 w-3" />+ {{ formatMoney(debt.granted.interest.amount) }} · {{ formatMoney(debt.granted.total) }} au total</dd>
                            <dd v-else-if="debt.granted.interest.waived" class="flex items-center gap-1 text-xs text-muted-foreground"><Percent class="h-3 w-3" />Intérêt remis</dd>
                        </div>
                        <div><dt class="text-xs text-muted-foreground">Par mois</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(debt.granted.installment_amount) }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Mode</dt><dd class="flex items-center gap-1.5 text-foreground"><component :is="MODE_ICONS[debt.granted.repayment_mode]" class="h-3.5 w-3.5 text-muted-foreground" />{{ debt.granted.repayment_mode_label }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Plan</dt><dd class="text-foreground">{{ planSummary(debt.granted.plan) }}</dd></div>
                    </dl>
                    <p v-if="debt.decision_note" class="rounded-lg bg-muted/60 px-3 py-2 text-sm text-muted-foreground">« {{ debt.decision_note }} »</p>
                    <div v-if="debt.derogations.length" class="flex items-start gap-2 rounded-lg border border-amber-300/70 bg-amber-50 px-3 py-2 text-sm dark:border-amber-900 dark:bg-amber-950/30">
                        <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                        <div class="min-w-0">
                            <p class="font-semibold text-foreground">Accordée par dérogation</p>
                            <p v-for="derogation in debt.derogations" :key="derogation" class="text-xs text-muted-foreground">{{ derogation }}</p>
                        </div>
                    </div>

                    <!-- Le versement : remis hors RIVO, marqué ici. -->
                    <div v-if="debt.status === 'APPROVED'" class="flex flex-wrap items-center gap-3 rounded-xl border border-dashed border-primary/40 bg-primary/5 px-4 py-3">
                        <Wallet class="h-4 w-4 shrink-0 text-primary" />
                        <p class="min-w-0 flex-1 text-sm text-foreground">À verser hors RIVO (espèces, virement, Mobile Money), puis à marquer versée : les remboursements commencent ensuite.</p>
                        <Button v-if="debt.can.disburse" type="button" size="sm" @click="disbursing = true"><Wallet class="h-4 w-4" />Marquer versée</Button>
                        <span v-else class="flex items-center gap-1 text-xs text-muted-foreground"><Lock class="h-3.5 w-3.5" />Marquer versée demande le droit « staff_debts.disburse ».</span>
                    </div>
                    <p v-if="debt.disbursement" class="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
                        <Wallet class="h-4 w-4" />Versée le {{ formatDate(debt.disbursement.on) }} · {{ debt.disbursement.mode_label }}<template v-if="debt.disbursement.reference"> · réf. {{ debt.disbursement.reference }}</template><template v-if="debt.disbursement.note"> — {{ debt.disbursement.note }}</template>
                    </p>

                    <div v-if="debt.disbursement" class="space-y-1.5">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">Remboursé {{ formatMoney(debt.repaid) }}<template v-if="Number(debt.written_off_amount) > 0"> · remis {{ formatMoney(debt.written_off_amount) }}</template></span>
                            <span class="font-semibold tabular-nums text-foreground">Reste {{ formatMoney(debt.balance) }} sur {{ formatMoney(debt.total_due) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="repaidShare" aria-valuemin="0" aria-valuemax="100" :aria-label="`${repaidShare} % remboursé`">
                            <div class="h-full rounded-full bg-primary" :style="{ width: `${repaidShare}%` }" />
                        </div>
                        <p v-if="Number(debt.arrears) > 0" class="flex items-center gap-1 text-xs text-destructive"><CircleAlert class="h-3.5 w-3.5" />{{ formatMoney(debt.arrears) }} en retard à la Caisse.</p>
                    </div>
                </Card>

                <p v-if="debt.refusal_reason" class="flex items-start gap-2 rounded-xl bg-destructive/5 px-4 py-3 text-sm text-destructive"><CircleX class="mt-0.5 h-4 w-4 shrink-0" />Refusée : {{ debt.refusal_reason }}</p>
                <p v-if="debt.status === 'CANCELLED'" class="flex items-start gap-2 rounded-xl bg-muted px-4 py-3 text-sm text-muted-foreground"><Ban class="mt-0.5 h-4 w-4 shrink-0" />Annulée : {{ debt.cancel_reason }}</p>
                <p v-if="debt.write_off_reason" class="flex items-start gap-2 rounded-xl bg-muted px-4 py-3 text-sm text-muted-foreground"><Gift class="mt-0.5 h-4 w-4 shrink-0" />Reste remis ({{ formatMoney(debt.written_off_amount) }}) : {{ debt.write_off_reason }}</p>

                <!-- ADR-230 — la personne a quitté la clinique : le reste dû se règle à son départ. -->
                <Card v-if="departure" :class="['space-y-3 px-5 py-4', departure.awaiting ? 'border-amber-300/60 dark:border-amber-900' : '']">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><DoorOpen class="h-4 w-4 text-amber-600" />Règlement au départ</h2>
                        <div class="ms-auto flex flex-wrap gap-2">
                            <Button v-if="debt.can.settle_departure" type="button" size="sm" @click="openDeparture"><Scale class="h-4 w-4" />Régler au départ</Button>
                            <Button v-if="departure.settled_at" :as="Link" :href="`${base}/protocole-depart`" size="sm" variant="outline"><Printer class="h-4 w-4" />Protocole d’accord</Button>
                        </div>
                    </div>
                    <template v-if="departure.awaiting">
                        <p class="text-sm text-foreground">
                            {{ debt.employee_name }} a quitté la clinique<template v-if="departure.left_on"> le {{ formatDate(departure.left_on) }}</template> avec
                            <strong class="tabular-nums">{{ formatMoney(departure.balance) }}</strong> encore dus<template v-if="! isZeroMoney(departure.penalties_due)">, dont {{ formatMoney(departure.penalties_due) }} de pénalités</template>.
                            La paie ne retient plus rien : convenez avec la personne de ce qui est retenu sur son solde de tout compte, remis, et remboursé ensuite.
                        </p>
                        <p v-if="! debt.can.settle_departure" class="flex items-center gap-1 text-xs text-muted-foreground"><Lock class="h-3.5 w-3.5" />Régler au départ demande le droit « staff_debts.decide ».</p>
                    </template>
                    <template v-else-if="departureTerms">
                        <p class="text-sm text-muted-foreground">Convenu le {{ formatDate(departure.settled_at) }}<template v-if="departure.settled_by"> par {{ departure.settled_by }}</template> — reste dû au départ {{ formatMoney(departureTerms.balance_before) }}.</p>
                        <dl class="grid gap-3 text-sm sm:grid-cols-4">
                            <div v-if="! isZeroMoney(departureTerms.retained)"><dt class="text-xs text-muted-foreground">Retenu au solde</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(departureTerms.retained) }}</dd><dd v-if="departureTerms.retained_on" class="text-xs text-muted-foreground">le {{ formatDate(departureTerms.retained_on) }}</dd></div>
                            <div v-if="! isZeroMoney(departureTerms.written_off)"><dt class="text-xs text-muted-foreground">Remis</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(departureTerms.written_off) }}</dd></div>
                            <div v-if="! isZeroMoney(departureTerms.penalties_waived)"><dt class="text-xs text-muted-foreground">Pénalités remises</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(departureTerms.penalties_waived) }}</dd></div>
                            <div><dt class="text-xs text-muted-foreground">Reste à rembourser</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(departureTerms.rest) }}</dd>
                                <dd v-if="! isZeroMoney(departureTerms.rest) && departureTerms.installment" class="text-xs text-muted-foreground">{{ formatMoney(departureTerms.installment) }} / mois en espèces, {{ planSummary({ count: departureTerms.count, first_period: departureTerms.first_period, last_period: departureTerms.last_period }) }}</dd>
                            </div>
                        </dl>
                        <p v-if="departureTerms.note" class="rounded-lg bg-muted/60 px-3 py-2 text-sm text-muted-foreground">« {{ departureTerms.note }} »</p>
                    </template>
                </Card>

                <!-- ADR-230 — les pénalités de retard d'un remboursement en espèces. -->
                <Card v-if="hasPenalties" class="space-y-3 px-5 py-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Timer class="h-4 w-4 text-muted-foreground" />Pénalités de retard</h2>
                        <span v-if="! isZeroMoney(penalty.due)" class="ms-auto text-sm font-semibold tabular-nums text-destructive">{{ formatMoney(penalty.due) }} dues</span>
                    </div>
                    <p v-if="penalty.rule" class="text-xs text-muted-foreground">
                        {{ rateText(penalty.rule.rate) }} % par mois sur le montant en retard, {{ penalty.rule.grace_days }} jour{{ penalty.rule.grace_days > 1 ? 's' : '' }} après la fin du mois<template v-if="penalty.rule.cap">, jamais plus de {{ formatMoney(penalty.rule.cap) }} en tout ({{ rateText(penalty.rule.cap_rate) }} % du montant)</template>. Règle figée à l’accord ; une pénalité par mois, jamais effacée.
                    </p>
                    <p v-else class="text-xs text-muted-foreground">Aucune nouvelle pénalité : la règle ne s’applique plus à cette dette.</p>
                    <ul v-if="penalty.items.length" class="divide-y divide-border rounded-xl border border-border">
                        <li v-for="item in penalty.items" :key="item.uuid" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm">
                            <span class="min-w-0 flex-1">
                                <span :class="['font-semibold tabular-nums', item.waived_at ? 'text-muted-foreground line-through' : 'text-foreground']">{{ formatMoney(item.amount) }}</span>
                                <span class="text-xs text-muted-foreground"> — {{ rateText(item.rate) }} % de {{ formatMoney(item.base_amount) }} en retard · {{ monthLabel(item.period) }}</span>
                                <span v-if="item.waived_at" class="block text-xs text-muted-foreground">Remise le {{ formatDate(item.waived_at) }}<template v-if="item.waived_by"> par {{ item.waived_by }}</template> : {{ item.waiver_reason }}</span>
                            </span>
                            <Button v-if="! item.waived_at && debt.can.waive_penalty" type="button" size="sm" variant="ghost" @click="openPenaltyWaiver(item)"><Gift class="h-4 w-4" />Remettre</Button>
                        </li>
                    </ul>
                </Card>

                <Card v-if="debt.disbursement || debt.repayments.length" class="px-5 py-4">
                    <StaffDebtRepayments :debt="debt" />
                </Card>
            </div>

            <aside class="space-y-5">
                <Card v-if="debt.employee" class="space-y-3 px-5 py-4 text-sm">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><UserRound class="h-4 w-4 text-muted-foreground" />La personne</h2>
                    <dl class="space-y-2">
                        <div class="flex items-start gap-2"><BriefcaseBusiness class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" /><dd class="text-foreground">{{ [debt.employee.job_title, debt.employee.department].filter(Boolean).join(' · ') || 'Fonction non renseignée' }}</dd></div>
                        <div class="flex items-start gap-2"><Hash class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" /><dd class="text-foreground">{{ debt.employee.employee_number ?? 'Sans matricule' }}<template v-if="debt.employee.hired_on"> · entrée le {{ formatDate(debt.employee.hired_on) }}</template></dd></div>
                        <div class="flex items-start gap-2"><Wallet class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                            <dd class="text-foreground">
                                <template v-if="debt.employee.salary">Salaire déclaré <strong class="tabular-nums">{{ formatMoney(debt.employee.salary) }}</strong></template>
                                <template v-else-if="! debt.employee.salary_visible">Salaire : non visible avec vos droits</template>
                                <template v-else>Aucun salaire déclaré</template>
                            </dd>
                        </div>
                        <div class="flex items-start gap-2"><Landmark class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                            <dd class="text-foreground">
                                <template v-if="debt.employee.other_active">{{ debt.employee.other_active }} autre{{ debt.employee.other_active > 1 ? 's' : '' }} dette{{ debt.employee.other_active > 1 ? 's' : '' }} en cours : reste {{ formatMoney(debt.employee.other_balance) }}<template v-if="Number(debt.employee.other_installments) > 0">, {{ formatMoney(debt.employee.other_installments) }} par mois retenus sur la paie</template></template>
                                <template v-else>Aucune autre dette en cours</template>
                            </dd>
                        </div>
                    </dl>
                    <p v-if="! debt.employee.in_post" class="flex items-center gap-1.5 text-xs font-medium text-destructive"><CircleAlert class="h-3.5 w-3.5" />N’est plus en poste.</p>
                </Card>

                <Card v-if="rules && (rules.configured || hasTiers || sitePenalty)" class="space-y-3 px-5 py-4 text-sm">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Scale class="h-4 w-4 text-muted-foreground" />Limites du site</h2>
                    <dl class="space-y-1.5 text-xs">
                        <div v-if="rules.min_amount || rules.max_amount" class="flex justify-between gap-3"><dt class="text-muted-foreground">Montant</dt><dd class="text-end tabular-nums text-foreground">{{ rules.min_amount ? formatMoney(rules.min_amount) : '—' }} à {{ rules.max_amount ? formatMoney(rules.max_amount) : '—' }}</dd></div>
                        <div v-if="rules.max_months" class="flex justify-between gap-3"><dt class="text-muted-foreground">Durée maximale</dt><dd class="text-foreground">{{ rules.max_months }} mois</dd></div>
                        <div v-if="rules.max_open_debts" class="flex justify-between gap-3"><dt class="text-muted-foreground">Dettes en cours</dt><dd class="text-foreground">{{ rules.engaged_debts }} / {{ rules.max_open_debts }}</dd></div>
                        <div v-if="rules.max_salary_share" class="flex justify-between gap-3"><dt class="text-muted-foreground">Mensualités</dt><dd class="text-foreground">≤ {{ rules.max_salary_share }} % du salaire</dd></div>
                        <div v-if="rules.installment_cap" class="flex justify-between gap-3"><dt class="text-muted-foreground">Encore possible / mois</dt><dd class="tabular-nums font-semibold text-foreground">{{ formatMoney(rules.installment_cap.available) }}</dd></div>
                        <div v-if="rules.min_seniority_months" class="flex justify-between gap-3"><dt class="text-muted-foreground">Ancienneté</dt><dd class="text-foreground">≥ {{ rules.min_seniority_months }} mois</dd></div>
                    </dl>
                    <p v-if="rules.max_salary_share && ! rules.installment_cap" class="text-xs text-muted-foreground">Aucun salaire déclaré : la part du salaire ne se vérifie pas.</p>
                    <div v-if="hasTiers" class="space-y-1 border-t border-border pt-2.5">
                        <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Percent class="h-3.5 w-3.5" />Intérêt</p>
                        <p v-for="tier in rules.interest_tiers" :key="tier.from" class="text-xs text-foreground">{{ tierLabel(tier, formatMoney) }}</p>
                    </div>
                    <div v-if="sitePenalty" class="space-y-1 border-t border-border pt-2.5">
                        <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Timer class="h-3.5 w-3.5" />Pénalité de retard</p>
                        <p class="text-xs text-foreground">{{ rateText(rules.penalty_rate) }} % par mois, espèces seulement, après {{ rules.penalty_grace_days ?? 0 }} jour{{ (rules.penalty_grace_days ?? 0) > 1 ? 's' : '' }}<template v-if="rules.penalty_cap_rate"> · plafond {{ rateText(rules.penalty_cap_rate) }} % du montant</template></p>
                    </div>
                </Card>

                <Card class="space-y-3 px-5 py-4">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><History class="h-4 w-4 text-muted-foreground" />Historique</h2>
                    <ol class="relative space-y-3 border-s border-border ps-4">
                        <li v-for="event in debt.timeline" :key="`${event.key}-${event.at}`" class="relative">
                            <span class="absolute -start-[1.4rem] top-0.5 grid h-5 w-5 place-items-center rounded-full border border-border bg-card"><component :is="TIMELINE_ICONS[event.key] ?? CalendarDays" class="h-3 w-3 text-muted-foreground" /></span>
                            <p class="text-sm font-semibold text-foreground">{{ event.label }}</p>
                            <p class="text-xs text-muted-foreground">{{ formatDateTime(event.at) }}<template v-if="event.by"> · {{ event.by }}</template></p>
                            <p v-if="event.detail" class="text-xs text-muted-foreground">{{ event.detail }}</p>
                        </li>
                    </ol>
                </Card>
            </aside>
        </div>

        <ConfirmModal
            :open="approving"
            title="Accorder la dette"
            :description="`${debt.employee_name} · ${debt.number}`"
            confirm-label="Accorder"
            tone="success"
            :icon="CircleCheck"
            :processing="decision.processing"
            :dismissible="false"
            @update:open="(value) => value || decision.processing || (approving = false)"
            @confirm="approve"
        >
            <div class="space-y-2 text-sm">
                <p class="text-foreground">
                    <strong class="tabular-nums">{{ formatMoney(decision.amount) }}</strong>
                    <template v-if="decisionTotals?.interest"> + intérêt <strong class="tabular-nums">{{ formatMoney(decisionTotals.interest.amount) }}</strong> = {{ formatMoney(decisionTotals.total) }} à rembourser</template>,
                    {{ planSummary(decisionPlan) }}, {{ repaymentModes.find((mode) => mode.value === decision.repayment_mode)?.label.toLowerCase() }}.
                </p>
                <p v-if="decision.accept_derogations && needsDerogation" class="flex items-start gap-1.5 text-amber-700 dark:text-amber-300"><ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" />Accordée par dérogation aux limites du site.</p>
                <p class="text-muted-foreground">L’employé est prévenu. Elle sera à verser hors RIVO, puis à marquer versée ici ; rien n’est retenu avant.</p>
                <p v-if="firstError(decision)" class="font-medium text-destructive">{{ firstError(decision) }}</p>
            </div>
        </ConfirmModal>

        <ConfirmModal
            :open="reasonAction !== null"
            :title="reasonAction ? REASON_ACTIONS[reasonAction].title : ''"
            :description="`${debt.employee_name} · ${debt.number}`"
            :confirm-label="reasonAction ? REASON_ACTIONS[reasonAction].label : ''"
            :tone="reasonAction ? REASON_ACTIONS[reasonAction].tone : 'danger'"
            :icon="reasonAction ? REASON_ACTIONS[reasonAction].icon : Ban"
            :processing="reasonForm.processing"
            :disabled="reasonForm.reason.trim().length < 3"
            :dismissible="false"
            @update:open="(value) => value || reasonForm.processing || (reasonAction = null)"
            @confirm="submitReason"
        >
            <div v-if="reasonAction" class="space-y-3 text-sm">
                <p class="text-muted-foreground">{{ REASON_ACTIONS[reasonAction].help }}<template v-if="reasonAction === 'write_off'"> Reste remis : <strong class="tabular-nums text-foreground">{{ formatMoney(debt.balance) }}</strong>.</template></p>
                <FormField label="Motif" required :error="reasonForm.errors.reason">
                    <Textarea v-model="reasonForm.reason" :rows="3" maxlength="1000" />
                </FormField>
                <p v-if="reasonForm.errors.debt" class="font-medium text-destructive">{{ reasonForm.errors.debt }}</p>
            </div>
        </ConfirmModal>

        <ConfirmModal
            :open="reminding"
            title="Relancer le remboursement"
            :description="`${debt.employee_name} · ${debt.number}`"
            confirm-label="Envoyer la relance"
            tone="warning"
            :icon="BellRing"
            :processing="reminder.processing"
            @update:open="(value) => value || reminder.processing || (reminding = false)"
            @confirm="remind"
        >
            <div class="space-y-2 text-sm">
                <p class="text-foreground"><strong class="tabular-nums text-destructive">{{ formatMoney(debt.arrears) }}</strong> en retard, remboursée en espèces à la Caisse.</p>
                <p class="text-muted-foreground">L’employé et le RH du site sont prévenus dans leur cloche. Une relance part aussi d’elle-même une fois par mois.</p>
                <p v-if="reminder.errors.debt" class="font-medium text-destructive">{{ reminder.errors.debt }}</p>
            </div>
        </ConfirmModal>

        <Dialog :open="adjusting" title="Ajuster la dette" :description="`${debt.employee_name} · ${debt.number}`" size="lg" :dismissible="! adjustment.processing" @update:open="(value) => adjustment.processing || (adjusting = value)">
            <form class="space-y-4" @submit.prevent="submitAdjust">
                <StaffDebtTermsFields
                    :form="adjustment"
                    :current-month="currentMonth"
                    :keep-period="debt.granted?.first_period"
                    :lock-amount="debt.status === 'ACTIVE'"
                    :salary="debt.employee?.salary"
                    :rules="rules"
                    :max-installment="maxInstallment"
                    :waive-interest="debt.granted?.interest?.waived ?? false"
                    :fixed-interest="debt.status === 'ACTIVE' ? debt.granted?.interest?.amount ?? null : null"
                    limit-mode="derogation"
                    :disabled="adjustment.processing"
                />
                <FormField label="Remboursement" :icon="Wallet" required :error="adjustment.errors.repayment_mode">
                    <Select v-model="adjustment.repayment_mode" :options="repaymentModes.map((mode) => ({ ...mode, disabled: mode.value === 'SALARY' && ! salaryDeclared }))" />
                </FormField>
                <FormField label="Motif de l’ajustement" required :error="adjustment.errors.reason">
                    <Textarea v-model="adjustment.reason" :rows="2" maxlength="1000" placeholder="Ex. salaire réduit ce trimestre" />
                </FormField>
                <label v-if="adjustment.errors.derogation || adjustment.accept_derogations" class="flex items-start gap-3 rounded-xl border border-amber-300/70 bg-amber-50 px-3 py-2.5 text-sm dark:border-amber-900 dark:bg-amber-950/30">
                    <Checkbox v-model="adjustment.accept_derogations" class="mt-0.5" />
                    <span>
                        <span class="block font-semibold text-foreground">J’ajuste par dérogation</span>
                        <span class="block text-xs text-muted-foreground">{{ adjustment.errors.derogation ?? 'La dérogation reste écrite sur la dette et dans l’audit.' }}</span>
                    </span>
                </label>
                <p v-if="adjustment.errors.debt" class="text-sm font-medium text-destructive">{{ adjustment.errors.debt }}</p>
                <div class="flex justify-end gap-2 border-t border-border pt-4">
                    <Button type="button" variant="outline" :disabled="adjustment.processing" @click="adjusting = false">Annuler</Button>
                    <Button type="submit" :disabled="adjustment.processing || adjustment.reason.trim().length < 3"><Pencil class="h-4 w-4" />Enregistrer l’ajustement</Button>
                </div>
            </form>
        </Dialog>

        <Dialog :open="disbursing" title="Marquer la dette versée" :description="`${formatMoney(debt.amount)} à ${debt.employee_name}`" :dismissible="! disbursement.processing" @update:open="(value) => disbursement.processing || (disbursing = value)">
            <form class="space-y-4" @submit.prevent="disburse">
                <p class="text-sm text-muted-foreground">L’argent a été remis hors RIVO ; RIVO en garde la date, le moyen et la référence.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Date du versement" :icon="CalendarDays" required :error="disbursement.errors.disbursed_on">
                        <DatePicker v-model="disbursement.disbursed_on" :max="today" />
                    </FormField>
                    <FormField label="Moyen" :icon="Wallet" required :error="disbursement.errors.disbursement_mode">
                        <Select v-model="disbursement.disbursement_mode" :options="disbursementModes" />
                    </FormField>
                </div>
                <FormField label="Référence" :icon="Hash" hint="(facultatif)" :error="disbursement.errors.reference">
                    <IconInput v-model="disbursement.reference" :icon="Hash" maxlength="120" placeholder="Ex. n° de virement ou de transaction" />
                </FormField>
                <FormField label="Note" hint="(facultatif)" :error="disbursement.errors.note">
                    <Textarea v-model="disbursement.note" :rows="2" maxlength="500" />
                </FormField>
                <p v-if="disbursement.errors.debt" class="text-sm font-medium text-destructive">{{ disbursement.errors.debt }}</p>
                <div class="flex justify-end gap-2 border-t border-border pt-4">
                    <Button type="button" variant="outline" :disabled="disbursement.processing" @click="disbursing = false">Annuler</Button>
                    <Button type="submit" :disabled="disbursement.processing || ! disbursement.disbursed_on"><Wallet class="h-4 w-4" />Marquer versée</Button>
                </div>
            </form>
        </Dialog>

        <ConfirmModal
            :open="waivingPenalty !== null"
            title="Remettre la pénalité"
            :description="`${debt.employee_name} · ${debt.number}`"
            confirm-label="Remettre"
            tone="warning"
            :icon="Gift"
            :processing="penaltyWaiver.processing"
            :disabled="penaltyWaiver.reason.trim().length < 3"
            :dismissible="false"
            @update:open="(value) => value || penaltyWaiver.processing || (waivingPenalty = null)"
            @confirm="waivePenalty"
        >
            <div v-if="waivingPenalty" class="space-y-3 text-sm">
                <p class="text-muted-foreground">
                    La pénalité de <strong class="tabular-nums text-foreground">{{ formatMoney(waivingPenalty.amount) }}</strong> ({{ monthLabel(waivingPenalty.period) }})
                    n’est plus due. Elle reste dans l’historique, avec votre nom et le motif.
                </p>
                <FormField label="Motif" required :error="penaltyWaiver.errors.reason">
                    <Textarea v-model="penaltyWaiver.reason" :rows="3" maxlength="1000" />
                </FormField>
                <p v-if="penaltyWaiver.errors.debt" class="font-medium text-destructive">{{ penaltyWaiver.errors.debt }}</p>
            </div>
        </ConfirmModal>

        <Dialog v-if="departure" :open="settlingDeparture" title="Régler la dette au départ" :description="`${debt.employee_name} · ${debt.number} · reste dû ${formatMoney(departure.balance)}`" size="lg" :dismissible="! departureForm.processing" @update:open="(value) => departureForm.processing || (settlingDeparture = value)">
            <form class="space-y-4" @submit.prevent="settleDeparture">
                <p class="text-sm text-muted-foreground">
                    Ce qui a été convenu avec la personne. Le serveur recompte tout et l’enregistre ; le protocole d’accord s’imprime ensuite, à signer par les deux parties.
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Retenu sur le solde de tout compte" :icon="Wallet" hint="(facultatif)" :error="departureForm.errors.retained_amount">
                        <IconInput v-model="departureForm.retained_amount" :icon="Wallet" inputmode="decimal" placeholder="0" />
                    </FormField>
                    <FormField label="Date de la retenue" :icon="CalendarDays" :required="toMinor(departureForm.retained_amount) > 0" :error="departureForm.errors.retained_on">
                        <DatePicker v-model="departureForm.retained_on" :max="today" />
                    </FormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Remise accordée" :icon="Gift" hint="(facultatif)" :error="departureForm.errors.write_off_amount">
                        <IconInput v-model="departureForm.write_off_amount" :icon="Gift" inputmode="decimal" placeholder="0" :disabled="! debt.can.waive_penalty" />
                    </FormField>
                    <label v-if="! isZeroMoney(departure.penalties_due)" class="flex items-start gap-3 self-end rounded-xl border border-border px-3 py-2.5 text-sm">
                        <Checkbox v-model="departureForm.waive_penalties" :disabled="! debt.can.waive_penalty" class="mt-0.5" />
                        <span>
                            <span class="block font-semibold text-foreground">Remettre les pénalités</span>
                            <span class="block text-xs text-muted-foreground">{{ formatMoney(departure.penalties_due) }} de pénalités dues.</span>
                        </span>
                    </label>
                </div>
                <p v-if="! debt.can.waive_penalty" class="flex items-center gap-1 text-xs text-muted-foreground"><Lock class="h-3.5 w-3.5" />Remettre une partie ou les pénalités demande le droit « staff_debts.write_off ».</p>

                <div class="rounded-xl border border-border bg-muted/40 px-3 py-2.5 text-sm">
                    Reste à rembourser : <strong class="tabular-nums text-foreground">{{ formatMoney(departureRest) }}</strong>
                    <span class="text-xs text-muted-foreground"> — aperçu, le serveur recompte.</span>
                </div>

                <template v-if="departureRestsDue">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Mensualité en espèces" :icon="HandCoins" required :error="departureForm.errors.installment_amount">
                            <IconInput v-model="departureForm.installment_amount" :icon="HandCoins" inputmode="decimal" placeholder="Ex. 50000" />
                        </FormField>
                        <FormField label="Premier mois" :icon="CalendarDays" required :error="departureForm.errors.first_period">
                            <Select v-model="departureForm.first_period" :options="departurePeriods" />
                        </FormField>
                    </div>
                    <p v-if="departurePlan" class="text-xs text-muted-foreground">{{ planSummary(departurePlan) }}, remboursées à la Caisse.</p>
                    <label v-if="penalty.rule" class="flex items-start gap-3 rounded-xl border border-border px-3 py-2.5 text-sm">
                        <Checkbox v-model="departureForm.keep_penalties" class="mt-0.5" />
                        <span>
                            <span class="block font-semibold text-foreground">Les retards continuent de porter la pénalité</span>
                            <span class="block text-xs text-muted-foreground">{{ rateText(penalty.rule.rate) }} % par mois, comme prévu à l’accord. Décoché : plus aucune pénalité sur ce reste.</span>
                        </span>
                    </label>
                </template>

                <FormField label="Ce qui a été convenu" required :error="departureForm.errors.note">
                    <Textarea v-model="departureForm.note" :rows="3" maxlength="2000" placeholder="Ex. retenue sur le solde de tout compte, le reste en espèces chaque mois." />
                </FormField>
                <p v-if="departureForm.errors.debt" class="text-sm font-medium text-destructive">{{ departureForm.errors.debt }}</p>
                <div class="flex justify-end gap-2 border-t border-border pt-4">
                    <Button type="button" variant="outline" :disabled="departureForm.processing" @click="settlingDeparture = false">Annuler</Button>
                    <Button type="submit" :disabled="departureForm.processing || ! departureReady"><Scale class="h-4 w-4" />Enregistrer le règlement</Button>
                </div>
            </form>
        </Dialog>
    </div>
</template>
