<script setup>
import { Building2, Gift, HandCoins, Landmark, Receipt, ShieldCheck, Wallet } from 'lucide-vue-next';
import { formatMoney } from '@/utilities/money';
import { formatDateTime } from '@/utilities/date';
import { DEDUCTION_KINDS } from '@/utilities/payroll';

/**
 * ADR-233 — le détail d'une paie, tel que le serveur l'a calculé (ou figé) : gains,
 * retenues légales, retenues de dettes (ADR-228), puis les charges patronales pour
 * information. Partagé par le tableau, la grille et la vue détaillée.
 */
const props = defineProps({
    row: { type: Object, required: true },
    legalEnabled: { type: Boolean, default: false },
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

const isDeduction = (line) => DEDUCTION_KINDS.includes(line.kind);
</script>

<template>
    <div class="text-sm">
        <ul class="divide-y divide-border">
            <li v-for="(line, index) in props.row.lines" :key="`${line.kind}-${line.uuid ?? index}`" class="flex items-center gap-3 px-4 py-2">
                <component :is="LINE_KINDS[line.kind]?.icon ?? Gift" :class="['h-4 w-4 shrink-0', isDeduction(line) ? 'text-rose-600 dark:text-rose-300' : 'text-muted-foreground']" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span class="text-foreground">{{ line.label }}</span>
                    <span class="text-xs text-muted-foreground"> · {{ LINE_KINDS[line.kind]?.label ?? line.kind }}</span>
                </span>
                <span :class="['tabular-nums', isDeduction(line) ? 'text-rose-700 dark:text-rose-300' : 'text-foreground']">{{ formatMoney(line.amount) }}</span>
            </li>
            <li v-if="props.row.legal && ! props.row.legal.applies && props.row.legal.reason && props.legalEnabled" class="px-4 py-2 text-xs text-muted-foreground">{{ props.row.legal.reason }}</li>
            <li class="flex items-center gap-3 bg-primary/5 px-4 py-2 font-semibold">
                <span class="flex-1 text-foreground">Net à verser</span>
                <span class="tabular-nums text-primary">{{ formatMoney(props.row.total) }}</span>
            </li>
            <li v-if="props.row.employer_lines.length" class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-muted/30 px-4 py-2 text-xs text-muted-foreground">
                <Building2 class="h-3.5 w-3.5" aria-hidden="true" />
                <span>Charges patronales (information) :</span>
                <span v-for="line in props.row.employer_lines" :key="line.kind">{{ line.label }} {{ formatMoney(line.amount) }}</span>
                <span class="ms-auto">Coût employeur <strong class="tabular-nums text-foreground">{{ formatMoney(props.row.cost) }}</strong></span>
            </li>
        </ul>
        <div v-if="props.row.payment?.payment_note || props.row.cancelled.length" class="space-y-1 border-t border-border px-4 py-2 text-xs text-muted-foreground">
            <p v-if="props.row.payment?.payment_note">Note : {{ props.row.payment.payment_note }}</p>
            <p v-for="payment in props.row.cancelled" :key="payment.uuid">
                Paie annulée · {{ formatMoney(payment.total) }} · {{ formatDateTime(payment.cancelled_at) }} · {{ payment.cancelled_by }} — {{ payment.cancel_reason }}
            </p>
        </div>
    </div>
</template>
