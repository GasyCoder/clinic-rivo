<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import { formatDate, monthLabel } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { planSummary } from '@/utilities/staffDebts';
import { staffDebtUrl } from '@/utilities/staffDebtUrl';

defineOptions({ layout: AppLayout });

/**
 * ADR-230 — les deux documents d'une dette du personnel, à imprimer et signer à la main :
 * la reconnaissance de dette (à l'accord) et le protocole d'accord de départ. Tout vient
 * du serveur, figé sur la dette ; l'écran n'ajoute rien et ne recalcule rien.
 */
const props = defineProps({
    document: { type: Object, required: true },
});

const doc = computed(() => props.document);
const isDeparture = computed(() => doc.value.kind === 'DEPARTURE_AGREEMENT');
const employeeLine = computed(() => [doc.value.employee.name, doc.value.employee.employee_number].filter(Boolean).join(' · '));
const backHref = computed(() => staffDebtUrl(`/finance/dettes/${doc.value.debt.uuid}`));
const isZero = (value) => ! value || Number(value) === 0;
const hasInterest = computed(() => ! isZero(doc.value.terms.interest_amount));
const director = computed(() => doc.value.establishment.director_name
    ? `${doc.value.establishment.director_name}, ${doc.value.establishment.director_title}`
    : doc.value.establishment.director_title);
</script>

<template>
    <PaperSheet
        :page-title="`${doc.title} ${doc.debt.number}`"
        :document-title="doc.title"
        :back-href="backHref"
        back-label="Retour à la dette"
        max-width-class="max-w-[46rem]"
    >
        <table class="ps-table">
            <tbody>
                <tr>
                    <th class="ps-label ps-label-blue-soft" style="width: 34%">Dette</th>
                    <td><strong>{{ doc.debt.number }}</strong></td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Employé</th>
                    <td>{{ employeeLine }}</td>
                </tr>
                <tr v-if="doc.employee.job_title || doc.employee.department">
                    <th class="ps-label ps-label-blue-soft">Fonction</th>
                    <td>{{ [doc.employee.job_title, doc.employee.department].filter(Boolean).join(' · ') }}</td>
                </tr>
                <tr v-if="doc.employee.identity_document_number">
                    <th class="ps-label ps-label-blue-soft">Pièce d’identité</th>
                    <td>
                        {{ doc.employee.identity_document_number }}
                        <template v-if="doc.employee.identity_document_issued_on"> · délivrée le {{ formatDate(doc.employee.identity_document_issued_on) }}</template>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Reconnaissance de dette -->
        <template v-if="! isDeparture">
            <p class="sdd-text">
                Je soussigné(e) <strong>{{ doc.employee.name }}</strong>, employé(e) de {{ doc.establishment.name }}<template v-if="doc.establishment.site"> — site de {{ doc.establishment.site }}</template>,
                reconnais avoir reçu de mon employeur la somme de <strong>{{ formatMoney(doc.terms.amount) }}</strong><template v-if="doc.terms.disbursed_on"> le {{ formatDate(doc.terms.disbursed_on) }}</template>,
                et m’engage à la rembourser selon les conditions ci-dessous.
            </p>

            <table class="ps-table">
                <tbody>
                    <tr>
                        <th class="ps-label ps-label-blue-soft" style="width: 34%">Montant reçu</th>
                        <td>{{ formatMoney(doc.terms.amount) }}</td>
                    </tr>
                    <tr v-if="hasInterest || doc.terms.interest_waived">
                        <th class="ps-label ps-label-blue-soft">Intérêt</th>
                        <td>{{ doc.terms.interest_waived ? 'Aucun — intérêt remis' : formatMoney(doc.terms.interest_amount) }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Total à rembourser</th>
                        <td><strong>{{ formatMoney(doc.terms.total) }}</strong></td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Remboursement</th>
                        <td>
                            {{ doc.terms.repayment_mode_label }} · {{ formatMoney(doc.terms.installment_amount) }} par mois
                            <template v-if="doc.terms.plan"><br>{{ planSummary(doc.terms.plan) }}</template>
                        </td>
                    </tr>
                    <tr v-if="doc.terms.disbursement_mode_label">
                        <th class="ps-label ps-label-blue-soft">Versement</th>
                        <td>
                            {{ doc.terms.disbursement_mode_label }}
                            <template v-if="doc.terms.disbursement_reference"> · réf. {{ doc.terms.disbursement_reference }}</template>
                        </td>
                    </tr>
                    <tr v-if="doc.terms.decided_at">
                        <th class="ps-label ps-label-blue-soft">Accordée</th>
                        <td>le {{ formatDate(doc.terms.decided_at) }}<template v-if="doc.terms.decided_by"> par {{ doc.terms.decided_by }}</template></td>
                    </tr>
                </tbody>
            </table>

            <p v-if="doc.penalty && doc.penalty.applies" class="sdd-text">
                <strong>Retard.</strong> Toute mensualité payée en espèces et non réglée
                {{ doc.penalty.grace_days }} jour(s) après la fin de son mois porte une pénalité de
                {{ doc.penalty.rate }} % par mois sur le montant en retard<template v-if="doc.penalty.cap">,
                sans que le total des pénalités dépasse {{ formatMoney(doc.penalty.cap) }} ({{ doc.penalty.cap_rate }} % du montant reçu)</template>.
            </p>
            <p v-else class="sdd-text">
                Le remboursement étant retenu sur le salaire, aucune pénalité de retard ne s’applique.
            </p>
            <p class="sdd-text">
                En cas de départ de la clinique avant la fin du remboursement, le reste dû sera réglé selon un
                protocole d’accord établi à cette date.
            </p>
        </template>

        <!-- Protocole d'accord de départ -->
        <template v-else>
            <p class="sdd-text">
                <strong>{{ doc.employee.name }}</strong><template v-if="doc.departure.left_on"> a quitté {{ doc.establishment.name }} le {{ formatDate(doc.departure.left_on) }}</template><template v-else> quitte {{ doc.establishment.name }}</template>.
                Au {{ formatDate(doc.departure.settled_at) }}, le reste dû sur la dette {{ doc.debt.number }} était de
                <strong>{{ formatMoney(doc.departure.balance_before) }}</strong>. Les parties conviennent de le régler comme suit.
            </p>

            <table class="ps-table">
                <tbody>
                    <tr>
                        <th class="ps-label ps-label-blue-soft" style="width: 34%">Montant reçu à l’origine</th>
                        <td>{{ formatMoney(doc.terms.amount) }} · total dû {{ formatMoney(doc.terms.total) }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Déjà remboursé</th>
                        <td>{{ formatMoney(doc.terms.repaid_before) }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Reste dû au départ</th>
                        <td><strong>{{ formatMoney(doc.departure.balance_before) }}</strong></td>
                    </tr>
                    <tr v-if="! isZero(doc.departure.penalties_waived)">
                        <th class="ps-label ps-label-blue-soft">Pénalités remises</th>
                        <td>{{ formatMoney(doc.departure.penalties_waived) }}</td>
                    </tr>
                    <tr v-if="! isZero(doc.departure.retained)">
                        <th class="ps-label ps-label-blue-soft">Retenu sur le solde de tout compte</th>
                        <td>
                            {{ formatMoney(doc.departure.retained) }}
                            <template v-if="doc.departure.retained_on"> le {{ formatDate(doc.departure.retained_on) }}</template>
                        </td>
                    </tr>
                    <tr v-if="! isZero(doc.departure.written_off)">
                        <th class="ps-label ps-label-blue-soft">Remise accordée</th>
                        <td>{{ formatMoney(doc.departure.written_off) }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Reste à rembourser</th>
                        <td><strong>{{ formatMoney(doc.departure.rest) }}</strong></td>
                    </tr>
                    <tr v-if="! isZero(doc.departure.rest) && doc.departure.installment">
                        <th class="ps-label ps-label-blue-soft">Échéancier</th>
                        <td>
                            {{ formatMoney(doc.departure.installment) }} par mois, en espèces à la Caisse,
                            {{ doc.departure.count }} versement(s)
                            <template v-if="doc.departure.first_period">de {{ monthLabel(doc.departure.first_period) }}</template>
                            <template v-if="doc.departure.last_period && doc.departure.last_period !== doc.departure.first_period"> à {{ monthLabel(doc.departure.last_period) }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-if="! isZero(doc.departure.rest)" class="sdd-text">
                <template v-if="doc.departure.penalties_continue">
                    Tout versement en retard continue de porter la pénalité de {{ doc.departure.penalty_rate }} % par mois prévue à l’accord de la dette.
                </template>
                <template v-else>
                    Aucune pénalité de retard ne s’applique au reste convenu ci-dessus.
                </template>
            </p>
            <p v-else class="sdd-text">La dette est entièrement réglée par le présent protocole.</p>
            <p v-if="doc.departure.note" class="sdd-text"><strong>Observations.</strong> {{ doc.departure.note }}</p>
        </template>

        <p class="sdd-place">Fait le {{ formatDate(doc.printed_on) }}, en deux exemplaires.</p>

        <div class="sdd-signatures">
            <div>
                <span>L’employé(e)</span>
                <small>« Lu et approuvé », signature</small>
            </div>
            <div>
                <span>{{ director }}</span>
                <small>Signature et cachet</small>
            </div>
        </div>
    </PaperSheet>
</template>

<style scoped>
.sdd-text {
    margin: 0.9rem 0;
    font-size: 0.9rem;
    line-height: 1.55;
    text-align: justify;
}
.sdd-place {
    margin-top: 1.25rem;
    font-size: 0.85rem;
}
.sdd-signatures {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-top: 1.25rem;
    font-size: 0.85rem;
}
.sdd-signatures > div {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-height: 6rem;
    border-bottom: 1px solid #334155;
}
.sdd-signatures small {
    color: #64748b;
}
</style>
