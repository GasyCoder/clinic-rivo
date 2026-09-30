<script setup>
import { computed } from 'vue';
import { Banknote, CalendarClock, Printer, Undo2, Wallet } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import { formatDateTime, monthLabel } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';

/**
 * ADR-228 — ce qu'une dette a remboursé (retenues sur la paie, espèces à la Caisse, les
 * annulées barrées) et ce qu'elle attend encore, mois par mois. L'échéancier à venir
 * est lu sur le reste dû par le serveur : une retenue partielle l'allonge d'autant.
 */
const props = defineProps({
    debt: { type: Object, required: true },
    /** Adresse du reçu d'un encaissement, quand ce compte peut l'ouvrir. */
    receiptUrl: { type: Function, default: null },
});

const active = computed(() => props.debt.repayments.filter((repayment) => ! repayment.reversed_at));
const SOURCE_ICONS = { SALARY: Wallet, CASH: Banknote };
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="space-y-2">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-foreground">
                <Banknote class="h-4 w-4 text-muted-foreground" />Remboursements
                <span class="text-xs font-normal text-muted-foreground">· {{ active.length }}</span>
            </h3>
            <p v-if="! debt.repayments.length" class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
                Aucun remboursement pour l’instant.
            </p>
            <ul v-else class="divide-y divide-border rounded-lg border border-border">
                <li v-for="repayment in debt.repayments" :key="repayment.uuid" class="flex items-start gap-3 px-3 py-2 text-sm">
                    <component :is="SOURCE_ICONS[repayment.source] ?? Banknote" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p :class="['font-medium', repayment.reversed_at ? 'text-muted-foreground line-through' : 'text-foreground']">
                            {{ repayment.source_label }} · <span class="capitalize">{{ monthLabel(repayment.period) }}</span>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatDateTime(repayment.recorded_at) }}<template v-if="repayment.recorded_by"> · {{ repayment.recorded_by }}</template><template v-if="repayment.receipt_number"> · Reçu {{ repayment.receipt_number }}</template>
                        </p>
                        <p v-if="repayment.note" class="text-xs text-muted-foreground">{{ repayment.note }}</p>
                        <p v-if="repayment.reversed_at" class="mt-0.5 flex items-center gap-1 text-xs text-destructive">
                            <Undo2 class="h-3 w-3" />Annulé {{ formatDateTime(repayment.reversed_at) }}<template v-if="repayment.reversed_by"> · {{ repayment.reversed_by }}</template> — {{ repayment.reverse_reason }}
                        </p>
                    </div>
                    <span :class="['shrink-0 tabular-nums', repayment.reversed_at ? 'text-muted-foreground line-through' : 'font-semibold text-foreground']">{{ formatMoney(repayment.amount) }}</span>
                    <a
                        v-if="receiptUrl && repayment.source === 'CASH' && ! repayment.reversed_at"
                        :href="receiptUrl(repayment)"
                        class="shrink-0 rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        :aria-label="`Reçu ${repayment.receipt_number}`"
                        :title="`Reçu ${repayment.receipt_number}`"
                    ><Printer class="h-4 w-4" /></a>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-foreground">
                <CalendarClock class="h-4 w-4 text-muted-foreground" />Échéancier à venir
                <span v-if="debt.schedule.length" class="text-xs font-normal text-muted-foreground">· {{ debt.schedule.length }} mois</span>
            </h3>
            <p v-if="! debt.schedule.length" class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
                Plus rien n’est attendu.
            </p>
            <ol v-else class="max-h-72 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                <li v-for="(month, index) in debt.schedule" :key="month.period" class="flex items-center gap-3 px-3 py-2 text-sm">
                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-muted text-[11px] font-semibold tabular-nums text-muted-foreground">{{ index + 1 }}</span>
                    <span class="flex-1 capitalize text-foreground">{{ monthLabel(month.period) }}</span>
                    <Badge v-if="index === 0 && debt.status === 'ACTIVE'" tone="primary">Prochain</Badge>
                    <span class="tabular-nums text-foreground">{{ formatMoney(month.amount) }}</span>
                </li>
            </ol>
            <p v-if="debt.repayment_mode === 'SALARY' && debt.schedule.length" class="text-xs text-muted-foreground">
                Retenu sur la paie de chaque mois ; ce qu’un salaire ne couvre pas est reporté au mois suivant.
            </p>
        </section>
    </div>
</template>
