<script setup>
import { computed } from 'vue';
import { CalendarDays, CalendarRange, CircleAlert, Coins, Lock, Percent, Repeat } from 'lucide-vue-next';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { cn } from '@/lib/cn';
import Select from '@/Components/Shadcn/Select.vue';
import { formatMoney } from '@/utilities/money';
import { debtPlan, fromMinor, installmentFor, periodOptions, planSummary, quickMonths, ruleIssues, salaryShare, toMinor, totalWithInterest } from '@/utilities/staffDebts';

/**
 * ADR-228 — les conditions d'une dette, saisies de la même façon par l'employé qui la
 * demande et par le DG qui l'accorde ou l'ajuste : le montant, la mensualité et le mois
 * du premier remboursement. Le plan (nombre de mensualités, dernier mois) se lit en
 * direct ; le serveur le recalcule de toute façon.
 *
 * ADR-229 — avec les règles du site : l'intérêt de la tranche s'ajoute au montant, le
 * plan porte sur le total, et les limites dépassées se disent pendant la saisie —
 * un refus pour l'employé, une dérogation à confirmer pour le DG.
 *
 * ADR-234 — c'est le DG qui fixe le remboursement d'une demande : les durées proposées
 * respectent la durée maximale du site, et « Au plus permis » reprend la mensualité que le
 * salaire permet encore.
 */
const props = defineProps({
    form: { type: Object, required: true },
    currentMonth: { type: String, required: true },
    /** Une dette versée : le montant est ce qui a été remis, il ne change plus. */
    lockAmount: { type: Boolean, default: false },
    /** Le mois déjà retenu, même passé, pour une dette en cours. */
    keepPeriod: { type: String, default: null },
    /** Le salaire déclaré, pour dire quelle part la mensualité en prend (avec le droit de le voir). */
    salary: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    /** ADR-229 — les règles du site (limites, tranches d'intérêt), servies par le serveur. */
    rules: { type: Object, default: null },
    /** La mensualité que le salaire permet encore ; jamais le salaire lui-même. */
    maxInstallment: { type: String, default: null },
    /** L'intérêt remis par le DG : le total est le montant seul. */
    waiveInterest: { type: Boolean, default: false },
    /** Une dette versée garde l'intérêt figé à l'accord. */
    fixedInterest: { type: String, default: null },
    /** `refuse` : l'employé ne peut pas dépasser ; `derogation` : le DG peut, en le confirmant. */
    limitMode: { type: String, default: 'refuse' },
});

const totals = computed(() => {
    const amount = toMinor(props.form.amount);
    if (! amount) return null;
    if (props.fixedInterest !== null) {
        const fixed = toMinor(props.fixedInterest) ?? 0;

        return { interest: fixed > 0 ? { amount: props.fixedInterest } : null, total: fromMinor(amount + fixed) };
    }
    if (props.waiveInterest) return { interest: null, total: props.form.amount };

    return totalWithInterest(props.form.amount, props.rules?.interest_tiers ?? []);
});
const interest = computed(() => totals.value?.interest ?? null);
const plan = computed(() => debtPlan(totals.value?.total ?? props.form.amount, props.form.installment_amount, props.form.first_period));
const issues = computed(() => ruleIssues(props.form.amount, props.form.installment_amount, props.lockAmount ? { ...props.rules, min_amount: null, max_amount: null } : props.rules, formatMoney, props.maxInstallment));
const issueList = computed(() => Object.values(issues.value));
// Le montant hors de la fourchette se voit sur le champ lui-même : refus pour le personnel, dérogation pour le DG.
const amountOff = computed(() => (issues.value.amount ? (props.limitMode === 'refuse' ? 'refuse' : 'derogation') : null));
defineExpose({ issues });
const periods = computed(() => periodOptions(props.currentMonth, 18, props.keepPeriod));
const share = computed(() => (props.salary ? salaryShare(props.form.installment_amount, props.salary) : null));
const tooBig = computed(() => {
    const total = toMinor(totals.value?.total ?? props.form.amount);
    const installment = toMinor(props.form.installment_amount);

    return total !== null && installment !== null && installment > total;
});

const quick = computed(() => quickMonths(props.rules?.max_months ?? null));
const pickMonths = (months) => {
    const installment = installmentFor(totals.value?.total ?? props.form.amount, months);
    if (installment) props.form.installment_amount = installment.replace(/\.00$/, '');
};
// La mensualité que le salaire permet encore, sans dépasser ce qui est à rembourser.
const salaryMax = computed(() => {
    const cap = toMinor(props.maxInstallment);
    const total = toMinor(totals.value?.total ?? props.form.amount);
    if (! cap || ! total) return null;

    return fromMinor(Math.min(cap, total));
});
const pickSalaryMax = () => {
    if (salaryMax.value) props.form.installment_amount = salaryMax.value.replace(/\.00$/, '');
};
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField label="Montant" :icon="Coins" required :error="form.errors.amount">
                <div class="relative">
                    <IconInput
                        v-model="form.amount"
                        :icon="lockAmount ? Lock : Coins"
                        inputmode="decimal"
                        placeholder="Ex. 300000"
                        :disabled="disabled || lockAmount"
                        :aria-invalid="amountOff ? 'true' : undefined"
                        :class="cn('pe-10 tabular-nums', amountOff === 'refuse' && 'border-destructive', amountOff === 'derogation' && 'border-amber-400')"
                    />
                    <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Ar</span>
                </div>
                <p v-if="lockAmount" class="mt-1 text-xs text-muted-foreground">Déjà versée : le montant est ce qui a été remis.</p>
                <p
                    v-else-if="rules && (rules.min_amount || rules.max_amount)"
                    :class="cn('mt-1 text-xs', amountOff === 'refuse' ? 'font-medium text-destructive' : amountOff === 'derogation' ? 'font-medium text-amber-700 dark:text-amber-300' : 'text-muted-foreground')"
                >
                    <template v-if="rules.min_amount && rules.max_amount">De {{ formatMoney(rules.min_amount) }} à {{ formatMoney(rules.max_amount) }}.</template>
                    <template v-else-if="rules.min_amount">Au moins {{ formatMoney(rules.min_amount) }}.</template>
                    <template v-else>Au plus {{ formatMoney(rules.max_amount) }}.</template>
                </p>
            </FormField>

            <FormField label="Remboursement par mois" :icon="Repeat" required :error="form.errors.installment_amount">
                <div class="relative">
                    <IconInput v-model="form.installment_amount" :icon="Repeat" inputmode="decimal" placeholder="Ex. 100000" :disabled="disabled" class="pe-10 tabular-nums" />
                    <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Ar</span>
                </div>
                <div v-if="! disabled && toMinor(form.amount)" class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span class="text-xs text-muted-foreground">Rembourser en</span>
                    <button
                        v-for="months in quick"
                        :key="months"
                        type="button"
                        class="rounded-full border border-border bg-card px-2.5 py-0.5 text-xs font-medium text-foreground transition hover:border-primary hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="pickMonths(months)"
                    >{{ months }} mois</button>
                    <button
                        v-if="salaryMax"
                        type="button"
                        class="rounded-full border border-dashed border-border bg-card px-2.5 py-0.5 text-xs font-medium text-foreground transition hover:border-primary hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        title="La mensualité la plus haute que son salaire permet encore, dettes en cours comprises"
                        @click="pickSalaryMax"
                    >Au plus permis : {{ formatMoney(salaryMax) }}</button>
                </div>
            </FormField>
        </div>

        <FormField label="Premier remboursement" :icon="CalendarDays" required :error="form.errors.first_period">
            <Select v-model="form.first_period" :options="periods" :icon="CalendarDays" placeholder="Choisir le mois" :disabled="disabled" />
        </FormField>

        <div
            :class="[
                'flex items-start gap-3 rounded-xl border px-4 py-3 text-sm',
                tooBig ? 'border-destructive/40 bg-destructive/5' : plan ? 'border-primary/30 bg-primary/5' : 'border-dashed border-border bg-muted/40',
            ]"
            aria-live="polite"
        >
            <CalendarRange :class="['mt-0.5 h-4 w-4 shrink-0', tooBig ? 'text-destructive' : 'text-primary']" />
            <div class="min-w-0">
                <p v-if="tooBig" class="font-medium text-destructive">Le remboursement par mois ne peut pas dépasser ce qui est à rembourser.</p>
                <template v-else-if="plan">
                    <p v-if="interest" class="mb-1 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                        <Percent class="h-3.5 w-3.5 text-primary" />
                        {{ formatMoney(form.amount) }} + intérêt <strong class="tabular-nums text-foreground">{{ formatMoney(interest.amount) }}</strong>
                        = <strong class="tabular-nums text-foreground">{{ formatMoney(totals.total) }}</strong> à rembourser
                    </p>
                    <p v-else-if="waiveInterest" class="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground"><Percent class="h-3.5 w-3.5" />Sans intérêt : remis par le DG.</p>
                    <p class="font-semibold text-foreground">{{ planSummary(plan) }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatMoney(plan.installment) }} par mois<template v-if="plan.count > 1 && plan.last_amount !== plan.installment">, puis {{ formatMoney(plan.last_amount) }} le dernier mois</template>.
                        <template v-if="share !== null"> Soit {{ share }} % du salaire déclaré.</template>
                    </p>
                </template>
                <p v-else class="text-muted-foreground">Indiquez le montant, le remboursement par mois et le premier mois : le plan s’affiche ici.</p>
            </div>
        </div>

        <div
            v-if="issueList.length"
            :class="['flex items-start gap-3 rounded-xl border px-4 py-3 text-sm', limitMode === 'refuse' ? 'border-destructive/40 bg-destructive/5' : 'border-amber-300/70 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30']"
            role="status"
        >
            <CircleAlert :class="['mt-0.5 h-4 w-4 shrink-0', limitMode === 'refuse' ? 'text-destructive' : 'text-amber-600']" />
            <div class="min-w-0 space-y-1">
                <p class="font-semibold text-foreground">{{ limitMode === 'refuse' ? 'Hors des limites du site' : 'Hors des limites du site : une dérogation' }}</p>
                <p v-for="issue in issueList" :key="issue" class="text-foreground">{{ issue }}</p>
                <p v-if="limitMode !== 'refuse'" class="text-xs text-muted-foreground">Vous pourrez accorder quand même en confirmant la dérogation : elle restera écrite sur la dette.</p>
            </div>
        </div>
    </div>
</template>
