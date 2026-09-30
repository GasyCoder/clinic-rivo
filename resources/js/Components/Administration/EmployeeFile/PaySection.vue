<script setup>
import { computed, onMounted } from 'vue';
import { Banknote, Check, CircleOff, Coins, Gift, HandCoins, ShieldCheck, Wallet } from 'lucide-vue-next';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import { cn } from '@/lib/cn';
import { currencyLabel, formatMoney } from '@/utilities/money';
import EmployeeSectionCard from './EmployeeSectionCard.vue';

/**
 * ADR-206 / ADR-221 / ADR-225 — la rémunération déclarée. Un employé : « Salaire » et
 * « Avantages » se cochent ensemble ou séparément ; « Non rémunéré » (bénévole) exclut
 * les deux. Un stagiaire : « Indemnité » par défaut. « Avantages » ouvre les avantages et
 * primes de la personne et ses avantages à l'acte (module Bonus) ; tant qu'elle n'a jamais
 * été touchée, c'est la fonction qui décide (module Fonctions). Aucune paie calculée.
 */
const props = defineProps({
    payroll: { type: Object, default: null },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
    /** Un stagiaire, ou un dossier créé par « Nouveau stagiaire ». */
    intern: { type: Boolean, default: false },
});

const amountNumber = (value) => {
    const cleaned = String(value ?? '').replace(/[\s\u00a0\u202f]/g, '').replace(',', '.');

    return cleaned !== '' && Number.isFinite(Number(cleaned)) ? Number(cleaned) : null;
};

const { form, state, savedAt, retry } = useSectionAutosave('pay', {
    remuneration_type: props.payroll?.remuneration_type ?? '',
    remuneration_amount: props.payroll?.remuneration_amount ?? '',
    benefits_enabled: props.payroll?.benefits_enabled ?? null,
}, () => props.url, {
    canEdit: () => props.canEdit,
    ready: () => ! hasAmount.value || amountNumber(form.remuneration_amount) !== null,
});

const hasAmount = computed(() => ['SALARY', 'ALLOWANCE'].includes(form.remuneration_type));
const amount = computed(() => amountNumber(form.remuneration_amount));
const benefitsByJobTitle = computed(() => Boolean(props.payroll?.benefits_by_job_title));
const benefitsOn = computed(() => form.benefits_enabled ?? benefitsByJobTitle.value);

const setType = (value) => {
    form.remuneration_type = value;
    if (! hasAmount.value) form.remuneration_amount = '';
    form.clearErrors('remuneration_type', 'remuneration_amount');
};
const toggleType = (value) => {
    if (! props.canEdit) return;
    setType(form.remuneration_type === value ? '' : value);
    // Bénévole : ni salaire ni avantages.
    if (form.remuneration_type === 'UNPAID') form.benefits_enabled = false;
};
const toggleBenefits = () => {
    if (! props.canEdit) return;
    form.benefits_enabled = ! benefitsOn.value;
    if (form.benefits_enabled && form.remuneration_type === 'UNPAID') setType('');
};

const cards = computed(() => {
    const list = [];
    const type = form.remuneration_type;

    if (! props.intern || type === 'SALARY') {
        list.push({ key: 'SALARY', label: 'Salaire', hint: 'Salaire mensuel de base', icon: Banknote, on: type === 'SALARY', toggle: () => toggleType('SALARY') });
    }
    if (props.intern || type === 'ALLOWANCE') {
        list.push({ key: 'ALLOWANCE', label: 'Indemnité', hint: 'Stagiaire indemnisé', icon: HandCoins, on: type === 'ALLOWANCE', toggle: () => toggleType('ALLOWANCE') });
    }
    if (! props.intern || benefitsOn.value) {
        list.push({
            key: 'BENEFITS', label: 'Avantages', icon: Gift, on: benefitsOn.value, toggle: toggleBenefits, multi: true,
            hint: form.benefits_enabled === null && benefitsByJobTitle.value
                ? `Ouverts par sa fonction${props.payroll?.job_title ? ` (${props.payroll.job_title})` : ''}`
                : 'Avantages et primes, avantages à l’acte',
        });
    }
    list.push({ key: 'UNPAID', label: 'Non rémunéré', hint: props.intern ? 'Stagiaire non indemnisé' : 'Bénévole', icon: CircleOff, on: type === 'UNPAID', toggle: () => toggleType('UNPAID') });

    return list;
});

const summary = computed(() => {
    const parts = [];
    if (form.remuneration_type === 'SALARY') parts.push('salaire');
    if (form.remuneration_type === 'ALLOWANCE') parts.push('indemnité');
    if (benefitsOn.value) parts.push('avantages');
    if (form.remuneration_type === 'UNPAID') return 'Non rémunéré.';

    return parts.length ? `${parts.join(' + ').replace(/^./, (c) => c.toUpperCase())}.` : 'Rien n’est encore choisi.';
});

// Un stagiaire est indemnisé par défaut : le choix est posé, le montant reste à saisir.
onMounted(() => {
    if (props.intern && props.canEdit && ! form.remuneration_type) setType('ALLOWANCE');
});
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
            <div id="remuneration_type" role="group" aria-label="Rémunération" :class="cn('grid gap-3', cards.length === 4 ? 'sm:grid-cols-2 xl:grid-cols-4' : 'sm:grid-cols-3')">
                <button
                    v-for="item in cards"
                    :key="item.key"
                    type="button"
                    :role="item.multi ? 'checkbox' : 'radio'"
                    :aria-checked="item.on"
                    :disabled="! canEdit"
                    :class="cn(
                        'flex items-start gap-3 rounded-xl border p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-default',
                        item.on ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-card enabled:hover:border-primary/40 enabled:hover:bg-accent/50',
                    )"
                    @click="item.toggle()"
                >
                    <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-full', item.on ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                        <Check v-if="item.on" class="h-4 w-4" :stroke-width="3" />
                        <component :is="item.icon" v-else class="h-4 w-4" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-foreground">{{ item.label }}</span>
                        <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ item.hint }}</span>
                    </span>
                </button>
            </div>
            <p class="text-xs text-muted-foreground"><span class="font-semibold text-foreground">{{ summary }}</span> <template v-if="! intern">« Salaire » et « Avantages » se cochent ensemble ou séparément.</template></p>
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
                <span>Confidentiel : seuls les comptes qui ont le droit de voir la rémunération la lisent, sur la fiche comme à l’impression. Le détail des avantages se déclare à l’étape Avantages ; les avantages à l’acte se comptent dans le module Bonus.</span>
            </p>
        </div>
    </EmployeeSectionCard>
</template>
