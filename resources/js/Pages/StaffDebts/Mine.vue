<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Ban, BellRing, CalendarClock, CalendarRange, Check, ChevronDown, CircleAlert, CircleX, Coins, FileText, Gavel, Gift, HandCoins,
    Hash, Hourglass, Info, Landmark, ListChecks, Lock, Percent, Plus, Scale, ScrollText, Send, ShieldCheck, UserRound, Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StaffDebtRepayments from '@/Components/StaffDebts/StaffDebtRepayments.vue';
import StaffDebtStatusBadge from '@/Components/StaffDebts/StaffDebtStatusBadge.vue';
import { formatDate, formatDateTime, monthLabel } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { cn } from '@/lib/cn';
import { STATUS_ICONS, debtSteps, isOpenDebt, planSummary, repaidShare, ruleIssues, tierLabel, toMinor, totalWithInterest } from '@/utilities/staffDebts';

/**
 * ADR-228 — « Mes dettes » : demander une dette au DG, suivre sa décision, son versement
 * et ses remboursements. Une demande se retire tant que le DG n'a pas décidé. Pleine
 * largeur : les dettes à gauche, chacune avec son avancement en quatre étapes ; à droite,
 * la fiche et le déroulé.
 *
 * ADR-229 — les règles du site se lisent avant de demander ; le montant hors de la
 * fourchette se dit pendant la saisie, et le serveur refuse de toute façon.
 *
 * ADR-234 — la demande ne porte que le montant, un motif facultatif et l'acceptation des
 * règles et des conditions du site (servies par le serveur, gardées sur la demande). Le
 * remboursement par mois et le premier mois sont fixés par le DG.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    space: { type: Object, required: true },
    focus: { type: String, default: null },
});

const requesting = ref(false);
const form = useForm({ amount: '', reason: '', accept_terms: false, terms_version: props.space.conditions_version ?? '' });
const rules = computed(() => props.space.rules ?? null);
const conditions = computed(() => props.space.conditions ?? []);
const requestTotals = computed(() => totalWithInterest(form.amount, rules.value?.interest_tiers ?? []));
// Seul le montant se vérifie à la demande : le reste est fixé par le DG.
const amountIssue = computed(() => ruleIssues(form.amount, null, rules.value, formatMoney).amount ?? null);
const ready = computed(() => (toMinor(form.amount) ?? 0) > 0 && ! amountIssue.value && form.accept_terms);

// Des règles changées entre-temps se relisent : la case se décoche.
watch(() => props.space.conditions_version, (version) => {
    form.terms_version = version ?? '';
    form.accept_terms = false;
});

// Les règles du site, en phrases : ce qu'il faut savoir avant de demander.
const ruleLines = computed(() => {
    const value = rules.value;
    if (! value?.configured) return [];
    const lines = [];
    if (value.min_amount && value.max_amount) lines.push({ icon: Scale, text: `De ${formatMoney(value.min_amount)} à ${formatMoney(value.max_amount)} par dette.` });
    else if (value.min_amount) lines.push({ icon: Scale, text: `Au moins ${formatMoney(value.min_amount)} par dette.` });
    else if (value.max_amount) lines.push({ icon: Scale, text: `Au plus ${formatMoney(value.max_amount)} par dette.` });
    if (value.max_months) lines.push({ icon: CalendarRange, text: `Remboursée en ${value.max_months} mois au plus.` });
    if (value.max_salary_share) {
        lines.push({ icon: Wallet, text: `Les mensualités ne dépassent pas ${value.max_salary_share} % du salaire déclaré${value.max_installment ? ` : au plus ${formatMoney(value.max_installment)} par mois pour vous aujourd’hui` : ''}.` });
    }
    lines.push({ icon: Lock, text: 'Une dette en cours ferme les demandes : en demander une autre avant de l’avoir soldée demande l’autorisation du Super Admin.' });
    if (value.max_open_debts) lines.push({ icon: Landmark, text: `${value.max_open_debts} dette${value.max_open_debts > 1 ? 's' : ''} en cours au plus à la fois.` });
    if (value.min_seniority_months) lines.push({ icon: CalendarClock, text: `Il faut ${value.min_seniority_months} mois d’ancienneté.` });
    if (value.exclude_interns) lines.push({ icon: UserRound, text: 'Les stagiaires ne demandent pas de dette.' });

    return lines;
});

const openRequest = () => {
    form.reset();
    form.terms_version = props.space.conditions_version ?? '';
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

const summary = computed(() => props.space.summary);
const tiles = computed(() => [
    {
        key: 'balance', icon: Landmark, label: 'Reste à rembourser', value: formatMoney(summary.value.balance), tone: 'bg-primary/10 text-primary',
        hint: summary.value.active ? `${summary.value.active} dette${summary.value.active > 1 ? 's' : ''} en remboursement` : 'Rien à rembourser',
    },
    {
        key: 'next', icon: CalendarClock, label: 'Prochain remboursement', value: summary.value.next ? formatMoney(summary.value.next.amount) : '—', tone: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
        hint: summary.value.next ? monthLabel(summary.value.next.period) : 'Aucun remboursement prévu',
    },
    {
        key: 'repaid', icon: Wallet, label: 'Déjà remboursé', value: formatMoney(summary.value.repaid), tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
        hint: 'Toutes vos dettes',
    },
    {
        key: 'pending', icon: Hourglass, label: 'Demande en attente', value: summary.value.pending, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
        hint: summary.value.pending ? 'Attend la décision du DG' : 'Aucune demande en attente',
    },
]);
const hasArrears = computed(() => (toMinor(summary.value.arrears) ?? 0) > 0);

// Les dettes qui attendent encore quelque chose d'abord, puis les closes ; les plus récentes en tête.
const openDebts = computed(() => props.space.debts.filter(isOpenDebt));
const closedDebts = computed(() => props.space.debts.filter((debt) => ! isOpenDebt(debt)));
const FILTERS = [
    { key: 'toutes', label: 'Toutes' },
    { key: 'en-cours', label: 'En cours' },
    { key: 'closes', label: 'Closes' },
];
const filter = ref('toutes');
const counts = computed(() => ({ toutes: props.space.debts.length, 'en-cours': openDebts.value.length, closes: closedDebts.value.length }));
const shown = computed(() => ({ toutes: [...openDebts.value, ...closedDebts.value], 'en-cours': openDebts.value, closes: closedDebts.value }[filter.value]));

// Une dette en cours s'ouvre d'office ; une dette close se replie sur son avancement.
const expanded = ref(new Set(props.space.debts.filter((debt) => isOpenDebt(debt) || debt.uuid === props.focus).map((debt) => debt.uuid)));
const isExpanded = (debt) => expanded.value.has(debt.uuid);
const toggle = (debt) => {
    const next = new Set(expanded.value);
    next.has(debt.uuid) ? next.delete(debt.uuid) : next.add(debt.uuid);
    expanded.value = next;
};

const STEP_ICONS = { request: FileText, decision: Gavel, disbursement: Wallet, repayment: Landmark };
const STEP_STYLES = {
    done: { bar: 'bg-emerald-500', dot: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', text: 'text-foreground' },
    current: { bar: 'bg-primary', dot: 'bg-primary text-primary-foreground ring-4 ring-primary/15', text: 'text-foreground' },
    stopped: { bar: 'bg-destructive', dot: 'bg-destructive/10 text-destructive', text: 'text-destructive' },
    upcoming: { bar: 'bg-muted', dot: 'border border-dashed border-border text-muted-foreground', text: 'text-muted-foreground' },
    skipped: { bar: 'bg-muted/60', dot: 'bg-muted text-muted-foreground/60', text: 'text-muted-foreground/70' },
};
const STEP_STATE_LABELS = { done: 'fait', current: 'en cours', stopped: 'arrêtée ici', upcoming: 'à venir', skipped: 'sans objet' };
const stepIcon = (step) => (step.state === 'done' ? Check : step.state === 'stopped' ? CircleX : STEP_ICONS[step.key]);
const stepDetail = (step) => {
    if (step.note) return step.note;
    if (step.at) return formatDate(step.at) + (step.by && step.key !== 'request' ? ` · ${step.by}` : '');
    return step.state === 'upcoming' ? 'À venir' : step.state === 'skipped' ? 'Sans objet' : '';
};

const nextRepayment = (debt) => (['ACTIVE', 'APPROVED'].includes(debt.status) ? debt.schedule[0] ?? null : null);
const owesArrears = (debt) => (toMinor(debt.arrears) ?? 0) > 0;

const HOW_IT_WORKS = [
    { icon: FileText, title: 'La direction l’enregistre', text: 'Le Super Admin crée la dette à votre nom, avec le montant convenu.' },
    { icon: Gavel, title: 'Il la valide', text: 'Il fixe le remboursement par mois, le premier mois, et la retenue sur la paie ou les espèces à la Caisse.' },
    { icon: Wallet, title: 'Elle vous est versée', text: 'L’argent vous est remis hors RIVO, puis marqué versé. Rien n’est retenu avant le versement.' },
    { icon: Landmark, title: 'Vous remboursez', text: 'Chaque mois, retenu sur votre paie ou remis à la Caisse contre un reçu.' },
];

onMounted(async () => {
    if (! props.focus) return;
    await nextTick();
    document.getElementById(`dette-${props.focus}`)?.scrollIntoView({ block: 'start', behavior: 'smooth' });
});
</script>

<template>
    <Head title="Mes dettes" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Mon compte"
            title="Mes dettes"
            description="Demandez une dette au DG, puis suivez sa décision, son versement et vos remboursements, retenus sur votre paie ou remis en espèces à la Caisse."
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

        <div v-if="space.employee && hasArrears" class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm" role="status">
            <CircleAlert class="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
            <p class="text-foreground"><strong class="tabular-nums text-destructive">{{ formatMoney(summary.arrears) }}</strong> en retard : remettez-les à la Caisse, qui vous délivre un reçu.</p>
        </div>

        <div v-if="space.employee" class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div v-for="tile in tiles" :key="tile.key" class="flex items-center gap-3 rounded-xl border border-border bg-card px-3 py-3 shadow-sm sm:px-4">
                <span :class="['hidden h-10 w-10 shrink-0 place-items-center rounded-lg sm:grid', tile.tone]"><component :is="tile.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-xs font-semibold text-muted-foreground">{{ tile.label }}</span>
                    <span class="mt-0.5 block truncate text-xl font-bold leading-tight tabular-nums text-foreground">{{ tile.value }}</span>
                    <span class="block truncate text-xs text-muted-foreground first-letter:uppercase">{{ tile.hint }}</span>
                </span>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 space-y-4">
                <div v-if="space.debts.length" class="flex flex-wrap items-center gap-3">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><ListChecks class="h-4 w-4 text-muted-foreground" />Mes dettes</h2>
                    <div v-if="openDebts.length && closedDebts.length" class="ms-auto inline-flex rounded-lg border border-border bg-muted/40 p-0.5" role="group" aria-label="Filtrer mes dettes">
                        <button
                            v-for="item in FILTERS"
                            :key="item.key"
                            type="button"
                            :aria-pressed="filter === item.key"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                filter === item.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            ]"
                            @click="filter = item.key"
                        >
                            {{ item.label }}<span class="rounded-full bg-muted px-1.5 text-[11px] tabular-nums text-muted-foreground">{{ counts[item.key] }}</span>
                        </button>
                    </div>
                </div>

                <Card v-if="space.employee && ! space.debts.length" class="flex flex-col items-center gap-3 px-6 py-14 text-center">
                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/10 text-primary"><HandCoins class="h-7 w-7" /></span>
                    <p class="text-base font-semibold text-foreground">Aucune dette pour l’instant</p>
                    <p class="max-w-lg text-sm text-muted-foreground">Une dette enregistrée à votre nom par la direction s’affiche ici. Vous êtes prévenu à chaque étape dans la cloche des notifications.</p>
                    <Button v-if="space.can_request" type="button" @click="openRequest"><Plus class="h-4 w-4" />Faire une demande</Button>
                </Card>

                <Card v-else-if="! space.employee" class="flex flex-col items-center gap-3 px-6 py-14 text-center">
                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-muted text-muted-foreground"><Lock class="h-7 w-7" /></span>
                    <p class="text-base font-semibold text-foreground">Aucune fiche du personnel reliée</p>
                    <p class="max-w-lg text-sm text-muted-foreground">Vos dettes s’affichent ici une fois votre compte relié à votre fiche par le RH.</p>
                </Card>

                <Card
                    v-for="debt in shown"
                    :id="`dette-${debt.uuid}`"
                    :key="debt.uuid"
                    :class="['scroll-mt-24 overflow-hidden', focus === debt.uuid ? 'ring-2 ring-primary/40' : '']"
                >
                    <header class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4">
                        <span class="hidden h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary sm:grid"><component :is="STATUS_ICONS[debt.status] ?? HandCoins" class="h-5 w-5" /></span>
                        <div class="min-w-[14rem] flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-base font-bold text-foreground">
                                Dette {{ debt.number }}
                                <StaffDebtStatusBadge :status="debt.status" :label="debt.status_label" :tone="debt.status_tone" />
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Enregistrée le {{ formatDateTime(debt.requested_at) }}<template v-if="debt.repayment_mode_label"> · {{ debt.repayment_mode_label }}</template>
                            </p>
                        </div>
                        <div class="ms-auto text-end">
                            <p class="text-lg font-bold leading-tight tabular-nums text-foreground">{{ formatMoney(debt.amount ?? debt.requested_amount) }}</p>
                            <p class="text-xs text-muted-foreground">{{ debt.granted ? 'accordé' : 'enregistré' }}</p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-expanded="isExpanded(debt)"
                            :aria-controls="`dette-detail-${debt.uuid}`"
                            @click="toggle(debt)"
                        >
                            {{ isExpanded(debt) ? 'Replier' : 'Détail' }}
                            <ChevronDown :class="['h-4 w-4 transition-transform', isExpanded(debt) ? 'rotate-180' : '']" />
                        </Button>
                    </header>

                    <ol class="grid gap-x-4 gap-y-3 border-t border-border px-5 py-4 sm:grid-cols-2 lg:grid-cols-4" :aria-label="`Avancement de la dette ${debt.number}`">
                        <li v-for="step in debtSteps(debt)" :key="step.key" class="min-w-0">
                            <span :class="['block h-1 rounded-full', STEP_STYLES[step.state].bar]" aria-hidden="true" />
                            <div class="mt-2.5 flex items-start gap-2.5">
                                <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-full', STEP_STYLES[step.state].dot]">
                                    <component :is="stepIcon(step)" class="h-3.5 w-3.5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p :class="['text-sm font-semibold leading-tight', STEP_STYLES[step.state].text]">
                                        {{ step.label }}<span class="sr-only"> — {{ STEP_STATE_LABELS[step.state] }}</span>
                                    </p>
                                    <p class="mt-0.5 truncate text-xs text-muted-foreground" :title="stepDetail(step)">{{ stepDetail(step) }}</p>
                                </div>
                            </div>
                        </li>
                    </ol>

                    <div v-show="isExpanded(debt)" :id="`dette-detail-${debt.uuid}`" class="space-y-4 border-t border-border bg-muted/20 px-5 py-4">
                        <div class="grid gap-3 text-sm lg:grid-cols-3">
                            <div class="rounded-lg border border-border bg-card px-4 py-3">
                                <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><FileText class="h-3.5 w-3.5" />Votre demande</p>
                                <p class="mt-1.5 font-semibold tabular-nums text-foreground">
                                    {{ formatMoney(debt.requested.amount) }}<template v-if="debt.requested.installment_amount"> · {{ formatMoney(debt.requested.installment_amount) }} par mois</template>
                                </p>
                                <p v-if="Number(debt.requested.interest_amount) > 0" class="flex items-center gap-1 text-xs text-muted-foreground"><Percent class="h-3 w-3" />Intérêt {{ formatMoney(debt.requested.interest_amount) }} · {{ formatMoney(debt.requested.total) }} à rembourser</p>
                                <p v-if="debt.requested.plan" class="text-xs text-muted-foreground first-letter:uppercase">{{ planSummary(debt.requested.plan) }}</p>
                                <p v-else class="text-xs text-muted-foreground">Remboursement fixé par le DG.</p>
                                <p v-if="debt.reason" class="mt-2 rounded-md bg-muted/60 px-2.5 py-1.5 text-xs text-foreground">{{ debt.reason }}</p>
                                <p v-if="debt.terms" class="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground"><ShieldCheck class="h-3.5 w-3.5 shrink-0 text-emerald-600" />Règles acceptées le {{ formatDateTime(debt.terms.accepted_at) }}</p>
                            </div>

                            <div v-if="debt.granted" class="rounded-lg border border-primary/30 bg-primary/5 px-4 py-3">
                                <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary"><Gavel class="h-3.5 w-3.5" />Accordé par le DG<template v-if="debt.granted.adjusted"> · ajusté</template></p>
                                <p class="mt-1.5 font-semibold tabular-nums text-foreground">{{ formatMoney(debt.granted.amount) }} · {{ formatMoney(debt.granted.installment_amount) }} par mois</p>
                                <p v-if="Number(debt.granted.interest.amount) > 0" class="flex items-center gap-1 text-xs text-muted-foreground"><Percent class="h-3 w-3" />Intérêt {{ formatMoney(debt.granted.interest.amount) }} · {{ formatMoney(debt.granted.total) }} à rembourser</p>
                                <p v-else-if="debt.granted.interest.waived" class="flex items-center gap-1 text-xs text-muted-foreground"><Percent class="h-3 w-3" />Sans intérêt</p>
                                <p class="text-xs text-muted-foreground first-letter:uppercase">{{ planSummary(debt.granted.plan) }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ debt.granted.repayment_mode_label }}</p>
                                <p v-if="debt.decision_note" class="mt-2 rounded-md bg-card px-2.5 py-1.5 text-xs text-muted-foreground">« {{ debt.decision_note }} »</p>
                            </div>
                            <div v-else-if="debt.status === 'REQUESTED'" class="flex items-start gap-2.5 rounded-lg border border-dashed border-border bg-card px-4 py-3 text-muted-foreground">
                                <Hourglass class="mt-0.5 h-4 w-4 shrink-0" />
                                <p>Attend la décision du DG. Vous êtes prévenu dès qu’il accorde, ajuste ou refuse.</p>
                            </div>

                            <div v-if="debt.disbursement" class="rounded-lg border border-border bg-card px-4 py-3">
                                <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Landmark class="h-3.5 w-3.5" />Remboursement</p>
                                <div class="mt-1.5 flex items-baseline justify-between gap-2">
                                    <span class="font-semibold tabular-nums text-foreground">Reste {{ formatMoney(debt.balance) }}</span>
                                    <span class="text-xs tabular-nums text-muted-foreground">{{ repaidShare(debt) }} %</span>
                                </div>
                                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="repaidShare(debt)" aria-valuemin="0" aria-valuemax="100" :aria-label="`${repaidShare(debt)} % remboursé`">
                                    <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${repaidShare(debt)}%` }" />
                                </div>
                                <p class="mt-1.5 text-xs text-muted-foreground">Remboursé {{ formatMoney(debt.repaid) }} sur {{ formatMoney(debt.total_due) }} · versée le {{ formatDate(debt.disbursement.on) }}</p>
                                <p v-if="nextRepayment(debt)" class="mt-1 flex items-start gap-1.5 text-xs text-foreground">
                                    <CalendarClock class="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                    <span>Prochain : {{ monthLabel(nextRepayment(debt).period) }} · <span class="tabular-nums">{{ formatMoney(nextRepayment(debt).amount) }}</span></span>
                                </p>
                                <p v-if="owesArrears(debt)" class="mt-1 flex items-center gap-1.5 text-xs font-medium text-destructive"><CircleAlert class="h-3.5 w-3.5" />{{ formatMoney(debt.arrears) }} en retard à la Caisse</p>
                            </div>
                            <div v-else-if="debt.status === 'APPROVED'" class="flex items-start gap-2.5 rounded-lg border border-dashed border-primary/40 bg-card px-4 py-3 text-foreground">
                                <Wallet class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                <p>Elle va vous être versée ; les remboursements commencent ensuite<template v-if="nextRepayment(debt)">, à partir de <span class="font-medium">{{ monthLabel(nextRepayment(debt).period) }}</span></template>.</p>
                            </div>
                        </div>

                        <p v-if="debt.refusal_reason" class="flex items-start gap-2 rounded-lg bg-destructive/5 px-3 py-2 text-sm text-destructive"><CircleX class="mt-0.5 h-4 w-4 shrink-0" />Refusée : {{ debt.refusal_reason }}</p>
                        <p v-if="debt.status === 'CANCELLED' && debt.cancel_reason" class="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground"><Ban class="mt-0.5 h-4 w-4 shrink-0" />{{ debt.cancel_reason }}</p>
                        <p v-if="debt.write_off_reason" class="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground"><Gift class="mt-0.5 h-4 w-4 shrink-0" />Reste remis par le DG ({{ formatMoney(debt.written_off_amount) }}) : {{ debt.write_off_reason }}</p>

                        <div v-if="debt.disbursement || debt.repayments.length" class="rounded-lg border border-border bg-card px-4 py-3">
                            <StaffDebtRepayments :debt="debt" />
                        </div>
                    </div>

                    <footer v-if="debt.can.withdraw" class="flex flex-wrap items-center justify-end gap-2 border-t border-border px-5 py-3">
                        <p class="me-auto text-xs text-muted-foreground">Vous pouvez la retirer tant que le DG n’a pas décidé.</p>
                        <Button type="button" size="sm" variant="ghost" class="text-destructive hover:text-destructive" @click="withdrawing = debt"><Ban class="h-4 w-4" />Retirer ma demande</Button>
                    </footer>
                </Card>
            </div>

            <aside class="space-y-5 xl:sticky xl:top-20 xl:self-start">
                <Card v-if="space.employee" class="space-y-3 px-5 py-4 text-sm">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><UserRound class="h-4 w-4 text-muted-foreground" />Ma fiche</h2>
                    <p class="font-semibold text-foreground">{{ space.employee.name }}</p>
                    <p class="flex items-center gap-2 text-muted-foreground"><Hash class="h-4 w-4 shrink-0" />{{ space.employee.employee_number ?? 'Sans matricule' }}</p>
                    <p v-if="! space.employee.in_post" class="flex items-center gap-1.5 text-xs font-medium text-destructive"><CircleAlert class="h-3.5 w-3.5" />N’est plus en poste.</p>
                </Card>

                <Card v-if="space.employee && (ruleLines.length || rules?.interest_tiers?.length)" class="space-y-3 px-5 py-4 text-sm">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Scale class="h-4 w-4 text-muted-foreground" />Règles de ce site</h2>
                    <ul class="space-y-2">
                        <li v-for="line in ruleLines" :key="line.text" class="flex items-start gap-2 text-foreground"><component :is="line.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />{{ line.text }}</li>
                    </ul>
                    <div v-if="rules?.interest_tiers?.length" class="space-y-1.5 border-t border-border pt-3">
                        <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Percent class="h-3.5 w-3.5" />Intérêt selon le montant</p>
                        <p v-for="tier in rules.interest_tiers" :key="tier.from" class="text-xs text-foreground">{{ tierLabel(tier, formatMoney) }}</p>
                        <p class="text-xs text-muted-foreground">Ajouté une fois au montant, remboursé avec lui. Le DG peut le remettre.</p>
                    </div>
                </Card>

                <Card class="space-y-4 px-5 py-4">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><CalendarRange class="h-4 w-4 text-muted-foreground" />Comment ça se passe</h2>
                    <ol class="relative space-y-4 border-s border-border ps-5">
                        <li v-for="(item, index) in HOW_IT_WORKS" :key="item.title" class="relative">
                            <span class="absolute -start-[1.95rem] top-0 grid h-6 w-6 place-items-center rounded-full border border-border bg-card text-primary">
                                <component :is="item.icon" class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <p class="text-sm font-semibold text-foreground"><span class="text-muted-foreground">{{ index + 1 }}.</span> {{ item.title }}</p>
                            <p class="mt-0.5 text-xs leading-5 text-muted-foreground">{{ item.text }}</p>
                        </li>
                    </ol>
                    <div class="space-y-2 border-t border-border pt-3 text-xs text-muted-foreground">
                        <p class="flex items-start gap-2"><BellRing class="mt-0.5 h-3.5 w-3.5 shrink-0" />Vous êtes prévenu à chaque étape dans la cloche des notifications.</p>
                        <p class="flex items-start gap-2"><Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" />Votre motif n’est lu que par le DG ; la Caisse ne voit que le montant.</p>
                    </div>
                </Card>
            </aside>
        </div>

        <Dialog :open="requesting" title="Demander une dette" description="Indiquez le montant : le DG fixe le remboursement, puis l’accorde, l’ajuste ou la refuse." size="lg" :dismissible="! form.processing" @update:open="(value) => form.processing || (requesting = value)">
            <form class="space-y-4" @submit.prevent="submit">
                <FormField label="Montant demandé" :icon="Coins" required :error="form.errors.amount">
                    <div class="relative">
                        <IconInput
                            v-model="form.amount"
                            :icon="Coins"
                            inputmode="decimal"
                            placeholder="Ex. 300000"
                            :disabled="form.processing"
                            :aria-invalid="amountIssue ? 'true' : undefined"
                            :class="cn('pe-10 tabular-nums', amountIssue && 'border-destructive')"
                        />
                        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Ar</span>
                    </div>
                    <p v-if="rules?.min_amount && rules?.max_amount" :class="cn('mt-1 text-xs', amountIssue ? 'font-medium text-destructive' : 'text-muted-foreground')">
                        De {{ formatMoney(rules.min_amount) }} à {{ formatMoney(rules.max_amount) }}.
                    </p>
                    <p v-if="requestTotals?.interest" class="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                        <Percent class="h-3.5 w-3.5 text-primary" />
                        + intérêt <strong class="tabular-nums text-foreground">{{ formatMoney(requestTotals.interest.amount) }}</strong>
                        = <strong class="tabular-nums text-foreground">{{ formatMoney(requestTotals.total) }}</strong> à rembourser
                    </p>
                </FormField>

                <div class="flex items-start gap-3 rounded-xl border border-dashed border-border bg-muted/40 px-4 py-3 text-sm">
                    <CalendarRange class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <p class="text-foreground">
                        Le DG fixe le remboursement par mois et le premier mois selon le montant<template v-if="rules?.max_installment"> et votre salaire — au plus <strong class="tabular-nums">{{ formatMoney(rules.max_installment) }}</strong> par mois pour vous aujourd’hui</template>.
                        Il choisit la retenue sur la paie ou les espèces à la Caisse.
                    </p>
                </div>

                <FormField label="Motif" :icon="FileText" hint="(facultatif)" :error="form.errors.reason">
                    <Textarea v-model="form.reason" :rows="2" maxlength="1000" placeholder="Pourquoi cette dette : lu par le DG seulement" :disabled="form.processing" />
                </FormField>

                <section class="space-y-3 rounded-xl border border-border px-4 py-3" aria-labelledby="regles-dette">
                    <h3 id="regles-dette" class="flex items-center gap-2 text-sm font-semibold text-foreground"><ScrollText class="h-4 w-4 text-muted-foreground" />Règles et conditions</h3>
                    <ul class="max-h-52 space-y-1.5 overflow-y-auto pe-1 text-xs leading-5 text-foreground">
                        <li v-for="(line, index) in conditions" :key="index" class="flex items-start gap-2"><Check class="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" />{{ line }}</li>
                    </ul>
                    <label class="flex items-start gap-3 border-t border-border pt-3 text-sm">
                        <Checkbox v-model="form.accept_terms" class="mt-0.5" :disabled="form.processing" :aria-invalid="form.errors.accept_terms ? 'true' : undefined" />
                        <span class="font-semibold text-foreground">J’ai lu et j’accepte les règles et les conditions ci-dessus.</span>
                    </label>
                    <p v-if="form.errors.accept_terms" class="text-sm font-medium text-destructive">{{ form.errors.accept_terms }}</p>
                </section>

                <p v-if="form.errors.employee" class="text-sm font-medium text-destructive">{{ form.errors.employee }}</p>
                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                    <p class="me-auto flex items-center gap-1.5 text-xs text-muted-foreground"><Lock class="h-3.5 w-3.5" />Votre motif n’est lu que par le DG.</p>
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
