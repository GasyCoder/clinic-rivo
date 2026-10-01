<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';
import { monthLabel } from '@/utilities/bonus';
import { DEDUCTION_KINDS } from '@/utilities/payroll';

/**
 * ADR-233 — les bulletins de paie du mois, un par page : gains, retenues légales et dettes,
 * net à verser, charges patronales pour information, mode de paiement. Une paie payée
 * montre ses lignes figées ; une paie à payer est marquée « provisoire ». Lu tel que le
 * serveur l'a calculé : l'écran ne recompte rien.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    month: { type: String, required: true },
    rows: { type: Array, default: () => [] },
});

const legal = computed(() => usePage().props.site?.documents ?? {});
const brand = computed(() => usePage().props.site?.brand ?? '');

const gains = (row) => row.lines.filter((line) => ! DEDUCTION_KINDS.includes(line.kind));
const deductions = (row) => row.lines.filter((line) => DEDUCTION_KINDS.includes(line.kind));
const positive = (amount) => Math.abs(Number(amount));
const period = computed(() => monthLabel(props.month));
</script>

<template>
    <div class="psl-doc space-y-6">
        <p v-if="! rows.length" class="mx-auto max-w-xl rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-muted-foreground">
            Aucun bulletin pour {{ period }}.
        </p>

        <PaperSheet
            v-for="(row, index) in rows"
            :key="row.uuid"
            :page-title="`Bulletins de paie — ${period}`"
            :document-title="row.payment ? `Bulletin de paie — ${period}` : `Bulletin de paie — ${period} (provisoire)`"
            :back-href="hrUrl(`/administration/paie?mois=${month}`)"
            back-label="Paie du mois"
            :show-actions="index === 0"
        >
            <table class="ps-table">
                <tbody>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Employeur</th>
                        <td>{{ brand }}<template v-if="legal.address"> — {{ legal.address }}</template></td>
                        <th class="ps-label ps-label-blue-soft">NIF · STAT</th>
                        <td>{{ [legal.nif, legal.stat].filter(Boolean).join(' · ') || '—' }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Salarié</th>
                        <td><strong>{{ row.name }}</strong></td>
                        <th class="ps-label ps-label-blue-soft">Matricule</th>
                        <td>{{ row.employee_number || '—' }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Fonction · Service</th>
                        <td>{{ [row.job_title, row.department].filter(Boolean).join(' · ') || '—' }}</td>
                        <th class="ps-label ps-label-blue-soft">Entrée · Ancienneté</th>
                        <td>{{ row.hire_date ? formatDate(row.hire_date) : '—' }}<template v-if="row.seniority"> · {{ row.seniority }}</template></td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Rémunération</th>
                        <td>{{ row.remuneration_label || '—' }}</td>
                        <th class="ps-label ps-label-blue-soft">Enfants à charge</th>
                        <td>{{ row.children }}</td>
                    </tr>
                </tbody>
            </table>

            <table class="ps-table psl-grid">
                <thead>
                    <tr><th class="ps-section ps-section-green" colspan="2">GAINS</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(line, lineIndex) in gains(row)" :key="`g-${lineIndex}`">
                        <td>{{ line.label }}</td>
                        <td class="psl-amount">{{ formatMoney(line.amount) }}</td>
                    </tr>
                    <tr class="psl-total">
                        <td>Salaire brut</td>
                        <td class="psl-amount">{{ formatMoney(row.gross) }}</td>
                    </tr>
                </tbody>
            </table>

            <table class="ps-table psl-grid">
                <thead>
                    <tr><th class="ps-section ps-section-yellow" colspan="2">RETENUES</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(line, lineIndex) in deductions(row)" :key="`d-${lineIndex}`">
                        <td>{{ line.label }}</td>
                        <td class="psl-amount">{{ formatMoney(positive(line.amount)) }}</td>
                    </tr>
                    <tr v-if="! deductions(row).length">
                        <td colspan="2" class="ps-muted">{{ row.legal?.reason ?? 'Aucune retenue ce mois-ci.' }}</td>
                    </tr>
                    <tr class="psl-total">
                        <td>Total des retenues</td>
                        <td class="psl-amount">{{ formatMoney(row.deductions_amount) }}</td>
                    </tr>
                </tbody>
            </table>

            <table class="ps-table">
                <tbody>
                    <tr>
                        <th class="ps-strong psl-net-label">NET À PAYER</th>
                        <td class="psl-net">{{ formatMoney(row.total) }}</td>
                    </tr>
                </tbody>
            </table>

            <table class="ps-table psl-grid">
                <tbody>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Mode de paiement</th>
                        <td>{{ row.payment_mode.label }} — {{ row.payment_mode.summary }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Statut</th>
                        <td>
                            <template v-if="row.payment">Payée le {{ formatDateTime(row.payment.paid_at) }}<template v-if="row.payment.payment_note"> — {{ row.payment.payment_note }}</template></template>
                            <template v-else>À payer — bulletin provisoire, recalculé jusqu’au paiement</template>
                        </td>
                    </tr>
                    <tr v-if="row.employer_lines.length">
                        <th class="ps-label ps-label-blue-soft">Charges patronales</th>
                        <td>
                            {{ row.employer_lines.map((line) => `${line.label} : ${formatMoney(line.amount)}`).join(' · ') }}
                            <span class="ps-muted"> — coût employeur {{ formatMoney(row.cost) }} (pour information, ne change pas le net)</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="psl-signatures">
                <div><p>L’employeur</p></div>
                <div><p>Le salarié — « Pour acquit »</p></div>
            </div>
        </PaperSheet>
    </div>
</template>

<style>
.psl-grid td:first-child { width: 72%; }
.psl-amount { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.psl-total td { font-weight: 700; background: #f3f3f3; }
.psl-net-label { width: 72%; font-size: 14px; }
.psl-net { text-align: right; font-size: 16px; font-weight: 800; font-variant-numeric: tabular-nums; }
.psl-signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 18px; }
.psl-signatures > div { min-height: 70px; border-bottom: 1px dotted #000; }
.psl-signatures p { font-weight: 700; }

@media print {
    .psl-doc { display: block; }
    .psl-doc > .ps-page + .ps-page { break-before: page; page-break-before: always; }
}
</style>
