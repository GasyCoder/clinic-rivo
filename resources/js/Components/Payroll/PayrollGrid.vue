<script setup>
import { Banknote, Building2, CircleAlert, Smartphone } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import PayrollLines from '@/Components/Payroll/PayrollLines.vue';
import PayrollRowActions from '@/Components/Payroll/PayrollRowActions.vue';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { PAYROLL_STATUS, payrollStatusOf } from '@/utilities/payrollBoard';

/**
 * ADR-233 — la paie du mois en grille (vue « Grille ») : une carte par personne, le net en
 * grand. `detailed` montre chaque carte avec son détail complet (vue « Détail »).
 */
const props = defineProps({
    rows: { type: Array, required: true },
    selected: { type: Array, required: true },
    expanded: { type: Array, required: true },
    legalEnabled: { type: Boolean, default: false },
    canPay: { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
    detailed: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'expand', 'pay', 'cancel', 'payslip']);

const MODE_ICONS = { BANK: Building2, MOBILE_MONEY: Smartphone, CASH: Banknote };
const isOpen = (row) => props.detailed || props.expanded.includes(row.uuid);
</script>

<template>
    <div :class="props.detailed ? 'space-y-3' : 'grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4'">
        <Card
            v-for="row in props.rows"
            :key="row.uuid"
            :class="cn('flex flex-col overflow-hidden', props.selected.includes(row.uuid) && 'ring-1 ring-primary/50', ! props.detailed && isOpen(row) && 'sm:col-span-2')"
        >
            <header class="flex items-start gap-3 px-4 pt-3">
                <Checkbox class="mt-0.5" :model-value="props.selected.includes(row.uuid)" :aria-label="`Sélectionner ${row.name}`" @update:model-value="(value) => emit('toggle', row.uuid, value)" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-foreground">{{ row.name }}</p>
                    <p class="truncate text-xs text-muted-foreground">{{ [row.employee_number, row.job_title].filter(Boolean).join(' · ') }}</p>
                    <p v-if="row.department" class="truncate text-xs text-muted-foreground">{{ row.department }}</p>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1">
                    <Badge :variant="PAYROLL_STATUS[payrollStatusOf(row)].variant">{{ PAYROLL_STATUS[payrollStatusOf(row)].label }}</Badge>
                    <Badge v-if="! row.in_post" variant="outline">Plus en poste</Badge>
                </div>
            </header>

            <div class="flex flex-wrap items-end justify-between gap-3 px-4 py-3">
                <div>
                    <p class="text-[11px] text-muted-foreground">Net à verser</p>
                    <p class="text-xl font-bold tabular-nums leading-tight text-primary">{{ formatMoney(row.total) }}</p>
                </div>
                <dl class="text-right text-xs leading-tight text-muted-foreground">
                    <div>Brut <span class="tabular-nums text-foreground">{{ formatMoney(row.gross) }}</span></div>
                    <div>Retenues <span class="tabular-nums text-rose-700 dark:text-rose-300">{{ Number(row.deductions_amount) > 0 ? `− ${formatMoney(row.deductions_amount)}` : '—' }}</span></div>
                    <div v-if="Number(row.debts_amount) > 0">dont dettes <span class="tabular-nums">{{ formatMoney(row.debts_amount) }}</span></div>
                </dl>
            </div>

            <p class="flex items-center gap-1.5 px-4 pb-3 text-xs text-muted-foreground" :title="row.payment_mode.summary">
                <component :is="MODE_ICONS[row.payment_mode.mode] ?? CircleAlert" :class="['h-4 w-4 shrink-0', ! row.payment_mode.mode && 'text-amber-600']" aria-hidden="true" />
                <span class="truncate">{{ row.payment_mode.label }} · {{ row.payment_mode.summary }}</span>
            </p>

            <div v-if="isOpen(row)" class="border-t border-border">
                <PayrollLines :row="row" :legal-enabled="props.legalEnabled" />
            </div>

            <footer class="mt-auto flex items-center gap-2 border-t border-border px-4 py-2.5">
                <span class="min-w-0 flex-1 truncate text-[11px] text-muted-foreground">
                    <template v-if="row.payment">Payée {{ formatDateTime(row.payment.paid_at) }} · {{ row.payment.paid_by }}</template>
                    <template v-else-if="row.cancelled.length">{{ row.cancelled.length }} paie{{ row.cancelled.length > 1 ? 's' : '' }} annulée{{ row.cancelled.length > 1 ? 's' : '' }}</template>
                </span>
                <PayrollRowActions
                    :row="row"
                    :can-pay="props.canPay"
                    :can-cancel="props.canCancel"
                    :with-details="! props.detailed"
                    :expanded="isOpen(row)"
                    compact
                    @pay="emit('pay', row)"
                    @cancel="emit('cancel', row)"
                    @payslip="emit('payslip', row)"
                    @details="emit('expand', row.uuid)"
                />
            </footer>
        </Card>
    </div>
</template>
