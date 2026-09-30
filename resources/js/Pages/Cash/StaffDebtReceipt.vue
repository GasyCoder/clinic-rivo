<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';

defineOptions({ layout: AppLayout });

/**
 * ADR-228 — le reçu d'un remboursement en espèces d'une dette du personnel,
 * remis par la Caisse (ADR-012). Il atteste un encaissement réel : un
 * encaissement annulé reste imprimable, mais le dit en toutes lettres. Aucun
 * motif de dette n'y figure : un nom, un numéro, un montant, un reste dû.
 */
const props = defineProps({
    receipt: { type: Object, required: true },
});

const reversed = computed(() => Boolean(props.receipt.reversed_at));
</script>

<template>
    <PaperSheet
        :page-title="`Reçu ${receipt.receipt_number}`"
        document-title="Reçu de remboursement · dette du personnel"
        back-href="/cash"
        back-label="Retour à la caisse"
        max-width-class="max-w-[40rem]"
    >
        <p v-if="reversed" class="sdr-void">
            Encaissement annulé le {{ formatDateTime(receipt.reversed_at) }}<template v-if="receipt.reversed_by"> par {{ receipt.reversed_by }}</template>
            <template v-if="receipt.reverse_reason"> — {{ receipt.reverse_reason }}</template>
        </p>

        <table class="ps-table">
            <tbody>
                <tr>
                    <th class="ps-label ps-label-blue-soft" style="width: 32%">N° de reçu</th>
                    <td><strong>{{ receipt.receipt_number }}</strong></td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Date</th>
                    <td>{{ formatDateTime(receipt.recorded_at) }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Employé</th>
                    <td>{{ receipt.employee_name }}<template v-if="receipt.employee_number"> · {{ receipt.employee_number }}</template></td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Dette</th>
                    <td>{{ receipt.debt_number }} · montant accordé {{ formatMoney(receipt.debt_amount) }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Montant reçu en espèces</th>
                    <td><strong :class="reversed ? 'sdr-struck' : undefined">{{ formatMoney(receipt.amount) }}</strong></td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Reste dû à ce jour</th>
                    <td>{{ formatMoney(receipt.balance) }}</td>
                </tr>
                <tr v-if="receipt.note">
                    <th class="ps-label ps-label-blue-soft">Note</th>
                    <td>{{ receipt.note }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Encaissé par</th>
                    <td>{{ receipt.recorded_by ?? '—' }}<template v-if="receipt.register_name"> · {{ receipt.register_name }}</template></td>
                </tr>
            </tbody>
        </table>

        <div class="sdr-signatures">
            <div>Signature de l’employé</div>
            <div>Signature et cachet de la Caisse</div>
        </div>
    </PaperSheet>
</template>

<style scoped>
.sdr-void {
    margin-bottom: 0.75rem;
    border: 2px solid #b91c1c;
    padding: 0.5rem 0.75rem;
    color: #b91c1c;
    font-weight: 700;
    text-align: center;
    text-transform: uppercase;
}
.sdr-struck {
    text-decoration: line-through;
}
.sdr-signatures {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-top: 2.5rem;
    font-size: 0.85rem;
}
.sdr-signatures > div {
    border-top: 1px solid #334155;
    padding-top: 0.35rem;
    min-height: 4rem;
}
</style>
