<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { AlarmClock, Ban, Banknote, HandCoins, Lock, Printer, Search, Undo2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { toMinor } from '@/utilities/staffDebts';

/**
 * ADR-228 — la Caisse encaisse le remboursement en espèces d'une dette du personnel :
 * dans la session de ce caissier, avec un reçu. Une dette retenue sur salaire peut
 * aussi se rembourser en avance ici. Un encaissement fait par erreur s'annule tant que
 * la caisse est ouverte. Aucun motif de dette ici : un nom, un numéro, un reste dû.
 */
const props = defineProps({
    staffDebts: { type: Object, required: true },
    cashRegisterUuid: { type: String, default: null },
    operational: { type: Boolean, default: false },
});

const search = ref('');
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
const shown = computed(() => {
    const words = normalize(search.value).split(/\s+/).filter(Boolean);

    return props.staffDebts.debts.filter((debt) => {
        const haystack = normalize(`${debt.employee_name} ${debt.number} ${debt.employee_number ?? ''}`);

        return words.every((word) => haystack.includes(word));
    });
});

const collecting = ref(null);
const form = useForm({ amount: '', note: '' });
const openCollect = (debt) => {
    form.reset();
    form.clearErrors();
    form.amount = String(debt.suggested_amount).replace(/\.00$/, '');
    collecting.value = debt;
};
const presets = computed(() => {
    const debt = collecting.value;
    if (! debt) return [];

    return [
        { label: 'Mensualité', amount: debt.installment_amount },
        Number(debt.arrears) > 0 ? { label: 'Retard', amount: debt.arrears } : null,
        { label: 'Tout le reste', amount: debt.balance },
    ].filter(Boolean).filter((preset, index, all) => all.findIndex((other) => toMinor(other.amount) === toMinor(preset.amount)) === index)
        .map((preset) => ({ ...preset, amount: toMinor(preset.amount) > toMinor(debt.balance) ? debt.balance : preset.amount }));
});
const amountValid = computed(() => {
    const amount = toMinor(form.amount);

    return amount !== null && amount > 0 && collecting.value && amount <= toMinor(collecting.value.balance);
});
const collect = () => form
    .transform((data) => ({ ...data, cash_register_uuid: props.cashRegisterUuid }))
    .post(`/cash/staff-debts/${collecting.value.uuid}/repayments`, { preserveScroll: true, onSuccess: () => { collecting.value = null; } });

const reversing = ref(null);
const reverseForm = useForm({ reason: '' });
const openReverse = (repayment) => {
    reverseForm.reset();
    reverseForm.clearErrors();
    reversing.value = repayment;
};
const reverse = () => reverseForm.post(`/cash/staff-debt-repayments/${reversing.value.uuid}/cancel`, {
    preserveScroll: true,
    onSuccess: () => { reversing.value = null; },
});

const firstError = (value) => Object.values(value.errors)[0] ?? '';
</script>

<template>
    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-60 flex-1">
                <IconInput v-model="search" :icon="Search" type="search" placeholder="Nom, matricule ou numéro de dette" aria-label="Rechercher une dette du personnel" />
            </div>
            <p class="text-xs text-muted-foreground">{{ shown.length }} dette{{ shown.length > 1 ? 's' : '' }} en remboursement</p>
        </div>

        <p v-if="! operational" class="flex items-center gap-2 rounded-lg border border-border bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
            <Lock class="h-4 w-4 shrink-0" />Ouvrez votre caisse pour encaisser un remboursement.
        </p>

        <p v-if="! staffDebts.debts.length" class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
            Aucune dette du personnel n’attend de remboursement.
        </p>
        <ul v-else class="divide-y divide-border rounded-lg border border-border">
            <li v-for="debt in shown" :key="debt.uuid" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 text-sm">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-foreground">{{ debt.employee_name }}</p>
                    <p class="text-xs text-muted-foreground">{{ [debt.number, debt.employee_number].filter(Boolean).join(' · ') }} · {{ debt.repayment_mode_label }} · {{ formatMoney(debt.installment_amount) }} / mois</p>
                </div>
                <Badge v-if="Number(debt.arrears) > 0" tone="danger"><AlarmClock class="h-3.5 w-3.5" />{{ formatMoney(debt.arrears) }} en retard</Badge>
                <span class="text-right">
                    <span class="block text-xs text-muted-foreground">Reste dû</span>
                    <span class="font-bold tabular-nums text-foreground">{{ formatMoney(debt.balance) }}</span>
                </span>
                <Button type="button" size="sm" :disabled="! operational" @click="openCollect(debt)"><HandCoins class="h-4 w-4" />Encaisser</Button>
            </li>
            <li v-if="! shown.length" class="px-4 py-6 text-center text-sm text-muted-foreground">Aucune dette ne correspond à cette recherche.</li>
        </ul>

        <section v-if="staffDebts.recent.length" class="space-y-2">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Banknote class="h-4 w-4 text-muted-foreground" />Encaissés dans votre caisse ouverte</h3>
            <ul class="divide-y divide-border rounded-lg border border-border">
                <li v-for="repayment in staffDebts.recent" :key="repayment.uuid" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm">
                    <div class="min-w-0 flex-1">
                        <p :class="['font-medium', repayment.reversed_at ? 'text-muted-foreground line-through' : 'text-foreground']">{{ repayment.employee_name }} · {{ repayment.receipt_number }}</p>
                        <p class="text-xs text-muted-foreground">
                            Dette {{ repayment.debt_number }} · {{ formatDateTime(repayment.recorded_at) }}
                            <template v-if="repayment.reversed_at"> · annulé : {{ repayment.reverse_reason }}</template>
                        </p>
                    </div>
                    <span :class="['tabular-nums', repayment.reversed_at ? 'text-muted-foreground line-through' : 'font-semibold text-foreground']">{{ formatMoney(repayment.amount) }}</span>
                    <template v-if="! repayment.reversed_at">
                        <Button :as="Link" :href="`/cash/staff-debt-repayments/${repayment.uuid}/recu`" size="sm" variant="outline"><Printer class="h-4 w-4" />Reçu</Button>
                        <Button type="button" size="sm" variant="ghost" class="text-destructive hover:text-destructive" @click="openReverse(repayment)"><Undo2 class="h-4 w-4" />Annuler</Button>
                    </template>
                </li>
            </ul>
        </section>

        <Dialog :open="collecting !== null" title="Encaisser un remboursement" :description="collecting ? `${collecting.employee_name} · dette ${collecting.number}` : ''" :dismissible="! form.processing" @update:open="(value) => form.processing || (collecting = value ? collecting : null)">
            <form v-if="collecting" class="space-y-4" @submit.prevent="collect">
                <dl class="grid grid-cols-3 gap-2 rounded-lg bg-muted/50 px-3 py-2 text-sm">
                    <div><dt class="text-xs text-muted-foreground">Reste dû</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(collecting.balance) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Par mois</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(collecting.installment_amount) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Ce mois-ci</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(collecting.repaid_this_month) }}</dd></div>
                </dl>
                <FormField label="Montant remis en espèces" :icon="Banknote" required :error="form.errors.amount">
                    <div class="relative">
                        <IconInput v-model="form.amount" :icon="Banknote" inputmode="decimal" class="pe-10 tabular-nums" autofocus />
                        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Ar</span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button
                            v-for="preset in presets"
                            :key="preset.label"
                            type="button"
                            class="rounded-full border border-border bg-card px-2.5 py-0.5 text-xs font-medium text-foreground transition hover:border-primary hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @click="form.amount = String(preset.amount).replace(/\.00$/, '')"
                        >{{ preset.label }} · {{ formatMoney(preset.amount) }}</button>
                    </div>
                </FormField>
                <FormField label="Note" hint="(facultatif)" :error="form.errors.note">
                    <Textarea v-model="form.note" :rows="2" maxlength="500" />
                </FormField>
                <p v-if="form.errors.debt || form.errors.cash_session || form.errors.cash_register_uuid" class="text-sm font-medium text-destructive">{{ form.errors.debt ?? form.errors.cash_session ?? form.errors.cash_register_uuid }}</p>
                <div class="flex justify-end gap-2 border-t border-border pt-4">
                    <Button type="button" variant="outline" :disabled="form.processing" @click="collecting = null">Annuler</Button>
                    <Button type="submit" variant="success" :disabled="! amountValid || form.processing"><HandCoins class="h-4 w-4" />Encaisser</Button>
                </div>
            </form>
        </Dialog>

        <ConfirmModal
            :open="reversing !== null"
            title="Annuler l’encaissement"
            :description="reversing ? `${reversing.receipt_number} · ${formatMoney(reversing.amount)}` : ''"
            confirm-label="Annuler l’encaissement"
            tone="danger"
            :icon="Ban"
            :processing="reverseForm.processing"
            :disabled="reverseForm.reason.trim().length < 3"
            :dismissible="false"
            @update:open="(value) => value || reverseForm.processing || (reversing = null)"
            @confirm="reverse"
        >
            <div class="space-y-3 text-sm">
                <p class="text-muted-foreground">Les espèces sortent de la caisse (mouvement inverse) et la dette redevient due d’autant. L’encaissement reste dans l’historique, barré.</p>
                <FormField label="Motif" required :error="reverseForm.errors.reason">
                    <Textarea v-model="reverseForm.reason" :rows="2" maxlength="1000" />
                </FormField>
                <p v-if="firstError(reverseForm) && ! reverseForm.errors.reason" class="font-medium text-destructive">{{ firstError(reverseForm) }}</p>
            </div>
        </ConfirmModal>
    </div>
</template>
