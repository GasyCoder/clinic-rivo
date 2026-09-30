<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Banknote, CircleOff, CreditCard, Gift, HandCoins, Landmark, Pencil, Plus, ShieldCheck, UserRound, Wallet } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { formatMoney } from '@/utilities/money';

/**
 * ADR-206 — la rémunération déclarée et le compte bancaire d'un dossier employé.
 * La carte n'est rendue que si le serveur a servi ces données, c'est-à-dire à qui
 * détient `employees.payroll.view` ; aucune paie n'est calculée.
 *
 * ADR-221 — la banque vient du module Banques ; les avantages et primes en
 * cours se lisent ici (sans total ni net), et se gèrent dans la fiche.
 */
const props = defineProps({
    payroll: { type: Object, required: true },
    editHref: { type: String, default: null },
    benefits: { type: Array, default: () => [] },
    benefitsHref: { type: String, default: null },
});
const current = computed(() => props.benefits.filter((benefit) => ! benefit.archived && benefit.current));

const TYPES = {
    SALARY: { icon: Banknote, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' },
    ALLOWANCE: { icon: HandCoins, tone: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300' },
    UNPAID: { icon: CircleOff, tone: 'bg-muted text-muted-foreground' },
};
const type = computed(() => TYPES[props.payroll.remuneration_type] ?? { icon: Wallet, tone: 'bg-muted text-muted-foreground' });
const amount = computed(() => (props.payroll.remuneration_amount !== null && props.payroll.remuneration_amount !== undefined && props.payroll.remuneration_amount !== ''
    ? formatMoney(props.payroll.remuneration_amount)
    : null));
</script>

<template>
    <Card class="p-5">
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300"><Wallet class="h-4 w-4" /></span>
                <div>
                    <h2 class="font-heading text-lg font-bold text-foreground">Rémunération et banque</h2>
                    <p class="flex items-center gap-1 text-xs text-muted-foreground"><ShieldCheck class="h-3.5 w-3.5" aria-hidden="true" />Confidentiel · déclaré par le RH</p>
                </div>
            </div>
            <Button v-if="editHref" :as="Link" :href="editHref" variant="ghost" size="icon" title="Modifier" aria-label="Modifier la rémunération"><Pencil class="h-4 w-4" /></Button>
        </div>

        <div class="mt-4 flex items-center gap-3 rounded-lg border border-border px-3.5 py-3">
            <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-lg', type.tone]"><component :is="type.icon" class="h-5 w-5" /></span>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-foreground">{{ payroll.remuneration_label || 'Rémunération non renseignée' }}</p>
                <p v-if="amount" class="text-lg font-bold tabular-nums text-foreground">{{ amount }} <span class="text-xs font-normal text-muted-foreground">/ mois</span></p>
            </div>
        </div>

        <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
            <div class="flex items-start gap-2.5">
                <Landmark class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Banque</dt>
                    <dd class="mt-0.5 text-sm text-foreground">
                        <template v-if="payroll.bank">{{ payroll.bank.label }}<span v-if="! payroll.bank.available" class="text-xs text-muted-foreground"> · archivée</span></template>
                        <template v-else>Non renseignée</template>
                    </dd>
                </div>
            </div>
            <div class="flex items-start gap-2.5">
                <Landmark class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Mode de paiement</dt>
                    <dd class="mt-0.5 text-sm text-foreground">{{ payroll.salary_payment_label || 'Non renseigné' }}<span v-if="payroll.mobile_money_number" class="font-mono"> · {{ payroll.mobile_money_number }}</span></dd>
                </div>
            </div>
            <div class="flex items-start gap-2.5">
                <CreditCard class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Numéro de compte</dt>
                    <dd class="mt-0.5 break-all font-mono text-sm text-foreground">{{ payroll.bank_account_number || 'Non renseigné' }}</dd>
                </div>
            </div>
            <div class="flex items-start gap-2.5">
                <UserRound class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Titulaire</dt>
                    <dd class="mt-0.5 text-sm text-foreground">{{ payroll.bank_account_holder || 'Non renseigné' }}</dd>
                </div>
            </div>
        </dl>
        <p v-if="! payroll.bank_account_number" class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground"><Landmark class="h-3.5 w-3.5" aria-hidden="true" />Aucun compte bancaire enregistré.</p>

        <!-- ADR-221 — avantages et primes en cours, sans total ni net. -->
        <div class="mt-4 border-t border-border pt-4">
            <div class="flex items-center justify-between gap-2">
                <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Gift class="h-3.5 w-3.5" aria-hidden="true" />Avantages et primes</p>
                <Link v-if="benefitsHref" :href="benefitsHref" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"><Plus class="h-3.5 w-3.5" />Gérer</Link>
            </div>
            <ul v-if="current.length" class="mt-2 space-y-1.5">
                <li v-for="benefit in current" :key="benefit.uuid" class="flex items-start justify-between gap-2 text-sm">
                    <span class="min-w-0">
                        <span class="font-medium text-foreground">{{ benefit.type }}</span>
                        <span class="block truncate text-xs text-muted-foreground" :title="benefit.reason">{{ benefit.reason }}</span>
                    </span>
                    <span class="shrink-0 text-end text-xs tabular-nums text-muted-foreground">
                        <span v-if="benefit.amount" class="block font-semibold text-foreground">{{ formatMoney(benefit.amount) }}</span>
                        {{ benefit.frequency_label }}
                    </span>
                </li>
            </ul>
            <p v-else class="mt-2 text-xs text-muted-foreground">Aucun avantage en cours.</p>
        </div>
    </Card>
</template>
