<script setup>
import { computed } from 'vue';
import { CalendarDays, CalendarRange, Coins, Lock, Repeat } from 'lucide-vue-next';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import { formatMoney } from '@/utilities/money';
import { debtPlan, installmentFor, periodOptions, planSummary, salaryShare, toMinor } from '@/utilities/staffDebts';

/**
 * ADR-228 — les conditions d'une dette, saisies de la même façon par l'employé qui la
 * demande et par le DG qui l'accorde ou l'ajuste : le montant, la mensualité et le mois
 * du premier remboursement. Le plan (nombre de mensualités, dernier mois) se lit en
 * direct ; le serveur le recalcule de toute façon.
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
});

const plan = computed(() => debtPlan(props.form.amount, props.form.installment_amount, props.form.first_period));
const periods = computed(() => periodOptions(props.currentMonth, 18, props.keepPeriod));
const share = computed(() => (props.salary ? salaryShare(props.form.installment_amount, props.salary) : null));
const tooBig = computed(() => {
    const amount = toMinor(props.form.amount);
    const installment = toMinor(props.form.installment_amount);

    return amount !== null && installment !== null && installment > amount;
});

const QUICK = [3, 6, 10, 12];
const pickMonths = (months) => {
    const installment = installmentFor(props.form.amount, months);
    if (installment) props.form.installment_amount = installment.replace(/\.00$/, '');
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
                        class="pe-10 tabular-nums"
                    />
                    <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Ar</span>
                </div>
                <p v-if="lockAmount" class="mt-1 text-xs text-muted-foreground">Déjà versée : le montant est ce qui a été remis.</p>
            </FormField>

            <FormField label="Remboursement par mois" :icon="Repeat" required :error="form.errors.installment_amount">
                <div class="relative">
                    <IconInput v-model="form.installment_amount" :icon="Repeat" inputmode="decimal" placeholder="Ex. 100000" :disabled="disabled" class="pe-10 tabular-nums" />
                    <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Ar</span>
                </div>
                <div v-if="! disabled && toMinor(form.amount)" class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span class="text-xs text-muted-foreground">Rembourser en</span>
                    <button
                        v-for="months in QUICK"
                        :key="months"
                        type="button"
                        class="rounded-full border border-border bg-card px-2.5 py-0.5 text-xs font-medium text-foreground transition hover:border-primary hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="pickMonths(months)"
                    >{{ months }} mois</button>
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
                <p v-if="tooBig" class="font-medium text-destructive">Le remboursement par mois ne peut pas dépasser le montant.</p>
                <template v-else-if="plan">
                    <p class="font-semibold text-foreground">{{ planSummary(plan) }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatMoney(plan.installment) }} par mois<template v-if="plan.count > 1 && plan.last_amount !== plan.installment">, puis {{ formatMoney(plan.last_amount) }} le dernier mois</template>.
                        <template v-if="share !== null"> Soit {{ share }} % du salaire déclaré.</template>
                    </p>
                </template>
                <p v-else class="text-muted-foreground">Indiquez le montant, le remboursement par mois et le premier mois : le plan s’affiche ici.</p>
            </div>
        </div>
    </div>
</template>
