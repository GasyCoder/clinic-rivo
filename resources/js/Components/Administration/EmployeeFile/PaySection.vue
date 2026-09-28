<script setup>
import { computed } from 'vue';
import { Banknote, Check, CircleOff, Coins, HandCoins, ShieldCheck, Wallet } from 'lucide-vue-next';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import { cn } from '@/lib/cn';
import { currencyLabel, formatMoney } from '@/utilities/money';
import EmployeeSectionCard from './EmployeeSectionCard.vue';

/**
 * ADR-206 / ADR-213 — la rémunération déclarée : un salaire (personnel), une
 * indemnité (stagiaire) ou rien (bénévole). Aucune paie, retenue ni net n'en
 * est calculé (ADR-066). Un salaire ou une indemnité ne part qu'avec son montant.
 */
const props = defineProps({
    payroll: { type: Object, default: null },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
});

const REMUNERATION_TYPES = [
    { value: 'SALARY', label: 'Salaire', hint: 'Personnel : salaire mensuel de base', icon: Banknote },
    { value: 'ALLOWANCE', label: 'Indemnité', hint: 'Stagiaire indemnisé', icon: HandCoins },
    { value: 'UNPAID', label: 'Non rémunéré', hint: 'Bénévole, stagiaire non indemnisé', icon: CircleOff },
];

const amountNumber = (value) => {
    const cleaned = String(value ?? '').replace(/[\s  ]/g, '').replace(',', '.');

    return cleaned !== '' && Number.isFinite(Number(cleaned)) ? Number(cleaned) : null;
};

const { form, state, savedAt, retry } = useSectionAutosave('pay', {
    remuneration_type: props.payroll?.remuneration_type ?? '',
    remuneration_amount: props.payroll?.remuneration_amount ?? '',
}, () => props.url, {
    canEdit: () => props.canEdit,
    ready: () => ! hasAmount.value || amountNumber(form.remuneration_amount) !== null,
});

const hasAmount = computed(() => ['SALARY', 'ALLOWANCE'].includes(form.remuneration_type));
const amount = computed(() => amountNumber(form.remuneration_amount));
const choose = (value) => {
    if (! props.canEdit) return;
    form.remuneration_type = form.remuneration_type === value ? '' : value;
    if (! hasAmount.value) form.remuneration_amount = '';
    form.clearErrors('remuneration_type', 'remuneration_amount');
};
</script>

<template>
    <EmployeeSectionCard
        :icon="Wallet"
        title="Rémunération"
        description="Ce que la personne reçoit chaque mois. Une déclaration du RH : aucune paie, retenue ni net n’en est calculé."
        tone="bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
        :state="state"
        :saved-at="savedAt"
        incomplete-hint="Indiquez le montant"
        :read-only="! canEdit"
        read-only-hint="Lecture seule : modifier la rémunération demande le droit « employees.payroll.update »."
        @retry="retry"
    >
        <div class="space-y-4">
            <div id="remuneration_type" role="radiogroup" aria-label="Rémunération" class="grid gap-3 sm:grid-cols-3">
                <button
                    v-for="item in REMUNERATION_TYPES"
                    :key="item.value"
                    type="button"
                    role="radio"
                    :aria-checked="form.remuneration_type === item.value"
                    :disabled="! canEdit"
                    :class="cn(
                        'flex items-start gap-3 rounded-xl border p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-default',
                        form.remuneration_type === item.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-card enabled:hover:border-primary/40 enabled:hover:bg-accent/50',
                    )"
                    @click="choose(item.value)"
                >
                    <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-full', form.remuneration_type === item.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                        <Check v-if="form.remuneration_type === item.value" class="h-4 w-4" :stroke-width="3" />
                        <component :is="item.icon" v-else class="h-4 w-4" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-foreground">{{ item.label }}</span>
                        <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ item.hint }}</span>
                    </span>
                </button>
            </div>
            <p v-if="form.errors.remuneration_type" class="text-xs font-medium text-destructive">{{ form.errors.remuneration_type }}</p>

            <FormField v-if="hasAmount" class="block max-w-sm" :label="form.remuneration_type === 'SALARY' ? 'Salaire de base mensuel' : 'Indemnité mensuelle'" required :error="form.errors.remuneration_amount">
                <div class="relative">
                    <IconInput id="remuneration_amount" v-model="form.remuneration_amount" :icon="Coins" inputmode="decimal" autocomplete="off" placeholder="Ex. 450 000" :disabled="! canEdit" :class="cn('pe-14 tabular-nums')" />
                    <span class="pointer-events-none absolute inset-y-0 end-0 grid place-items-center pe-3 text-sm font-medium text-muted-foreground">{{ currencyLabel() }}</span>
                </div>
                <span class="mt-1.5 block text-xs text-muted-foreground">
                    <template v-if="amount !== null">Soit <strong class="font-semibold text-foreground">{{ formatMoney(amount) }}</strong> par mois.</template>
                    <template v-else>Montant brut par mois, en chiffres.</template>
                </span>
            </FormField>

            <p class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 p-2.5 text-[11px] leading-4 text-muted-foreground">
                <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" />
                <span>Confidentiel : seuls les comptes qui ont le droit de voir la rémunération la lisent, sur la fiche comme à l’impression. Les avantages et primes se déclarent dans leur propre section.</span>
            </p>
        </div>
    </EmployeeSectionCard>
</template>
