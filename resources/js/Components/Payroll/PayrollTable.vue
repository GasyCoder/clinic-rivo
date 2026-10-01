<script setup>
import { computed } from 'vue';
import { Banknote, Building2, ChevronRight, CircleAlert, Smartphone } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import PayrollLines from '@/Components/Payroll/PayrollLines.vue';
import PayrollRowActions from '@/Components/Payroll/PayrollRowActions.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { PAYROLL_STATUS, paymentIncomplete, payrollStatusOf } from '@/utilities/payrollBoard';

/**
 * ADR-233 — la paie du mois en tableau : une ligne par personne, les montants alignés,
 * les actions toujours à droite. Une ligne se déplie sur son détail (gains, retenues).
 */
const props = defineProps({
    rows: { type: Array, required: true },
    selected: { type: Array, required: true },
    expanded: { type: Array, required: true },
    legalEnabled: { type: Boolean, default: false },
    canPay: { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'toggle-all', 'expand', 'pay', 'cancel', 'payslip']);

const MODE_ICONS = { BANK: Building2, MOBILE_MONEY: Smartphone, CASH: Banknote };
const headerState = computed(() => {
    const count = props.rows.filter((row) => props.selected.includes(row.uuid)).length;
    if (count === 0) return false;

    return count === props.rows.length ? true : 'indeterminate';
});
const isOpen = (row) => props.expanded.includes(row.uuid);
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-sm">
                <thead class="bg-muted/40 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="w-10 px-3 py-2.5">
                            <Checkbox :model-value="headerState" aria-label="Sélectionner les paies affichées" @update:model-value="(value) => emit('toggle-all', value === true)" />
                        </th>
                        <th class="px-3 py-2.5">Salarié</th>
                        <th class="px-3 py-2.5">Mode de paiement</th>
                        <th class="px-3 py-2.5 text-right">Brut</th>
                        <th class="px-3 py-2.5 text-right">Retenues</th>
                        <th class="px-3 py-2.5 text-right">Net à verser</th>
                        <th class="px-3 py-2.5">Statut</th>
                        <th class="px-3 py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <template v-for="row in props.rows" :key="row.uuid">
                        <tr :class="['align-middle transition-colors hover:bg-muted/30', props.selected.includes(row.uuid) && 'bg-primary/5']">
                            <td class="px-3 py-2.5">
                                <Checkbox :model-value="props.selected.includes(row.uuid)" :aria-label="`Sélectionner ${row.name}`" @update:model-value="(value) => emit('toggle', row.uuid, value)" />
                            </td>
                            <td class="px-3 py-2.5">
                                <button type="button" class="group flex items-start gap-1.5 text-left" :aria-expanded="isOpen(row)" @click="emit('expand', row.uuid)">
                                    <ChevronRight :class="['mt-0.5 h-4 w-4 shrink-0 text-muted-foreground transition-transform', isOpen(row) && 'rotate-90']" aria-hidden="true" />
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-foreground group-hover:underline">{{ row.name }}</span>
                                        <span class="block max-w-[18rem] truncate text-xs text-muted-foreground">{{ [row.employee_number, row.job_title, row.department].filter(Boolean).join(' · ') }}</span>
                                    </span>
                                </button>
                                <Badge v-if="! row.in_post" variant="outline" class="ms-6 mt-1">Plus en poste</Badge>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="flex items-center gap-1.5 text-xs" :title="row.payment_mode.summary">
                                    <component :is="MODE_ICONS[row.payment_mode.mode] ?? CircleAlert" :class="['h-4 w-4 shrink-0', paymentIncomplete(row) ? 'text-amber-600' : 'text-muted-foreground']" aria-hidden="true" />
                                    <span class="min-w-0">
                                        <span class="block font-medium text-foreground">{{ row.payment_mode.label }}</span>
                                        <span :class="['block max-w-[12rem] truncate', paymentIncomplete(row) ? 'font-medium text-amber-700 dark:text-amber-300' : 'text-muted-foreground']">{{ row.payment_mode.summary }}</span>
                                    </span>
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-foreground">{{ formatMoney(row.gross) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">
                                <span class="block text-rose-700 dark:text-rose-300">{{ Number(row.deductions_amount) > 0 ? `− ${formatMoney(row.deductions_amount)}` : '—' }}</span>
                                <span v-if="Number(row.debts_amount) > 0" class="block text-[11px] text-muted-foreground">dont dettes {{ formatMoney(row.debts_amount) }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-right font-bold tabular-nums text-primary">{{ formatMoney(row.total) }}</td>
                            <td class="px-3 py-2.5">
                                <Badge :variant="PAYROLL_STATUS[payrollStatusOf(row)].variant">{{ PAYROLL_STATUS[payrollStatusOf(row)].label }}</Badge>
                                <span v-if="row.payment" class="mt-1 block text-[11px] text-muted-foreground">{{ formatDateTime(row.payment.paid_at) }}</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <PayrollRowActions
                                    :row="row"
                                    :can-pay="props.canPay"
                                    :can-cancel="props.canCancel"
                                    :expanded="isOpen(row)"
                                    compact
                                    @pay="emit('pay', row)"
                                    @cancel="emit('cancel', row)"
                                    @payslip="emit('payslip', row)"
                                    @details="emit('expand', row.uuid)"
                                />
                            </td>
                        </tr>
                        <tr v-if="isOpen(row)" class="bg-muted/20">
                            <td colspan="8" class="p-0">
                                <div class="ms-12 me-4 my-2 overflow-hidden rounded-lg border border-border bg-card">
                                    <PayrollLines :row="row" :legal-enabled="props.legalEnabled" />
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
