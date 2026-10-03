<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CircleAlert, Coins, FileText, HandCoins, Info, Plus, UserRound } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import SearchSelect from '@/Components/Shadcn/SearchSelect.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { formatMoney } from '@/utilities/money';
import { staffDebtContext, staffDebtUrl } from '@/utilities/staffDebtUrl';
import { toMinor, totalWithInterest } from '@/utilities/staffDebts';

/**
 * ADR-245 — le Super Admin crée une dette pour un membre du personnel en poste. Elle
 * arrive « Demandée » : sa fiche s'ouvre ensuite, où il fixe le remboursement et la
 * valide. Les limites du site s'y lisent comme dérogations à confirmer ; ici, elles ne
 * sont qu'un rappel. Le site revérifie tout.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    employees: { type: Array, required: true },
    rules: { type: Object, required: true },
    preselected: { type: String, default: null },
});

const siteName = computed(() => staffDebtContext()?.site?.name ?? '');

const form = useForm({
    employee_uuid: props.employees.some((employee) => employee.uuid === props.preselected) ? props.preselected : '',
    amount: '',
    reason: '',
});

const options = computed(() => props.employees.map((employee) => ({
    value: employee.uuid,
    label: employee.name,
    description: [employee.employee_number, employee.job_title, employee.department].filter(Boolean).join(' · '),
})));
const chosen = computed(() => props.employees.find((employee) => employee.uuid === form.employee_uuid) ?? null);

const preview = computed(() => totalWithInterest(form.amount, props.rules.interest_tiers ?? []));
const outsideLimits = computed(() => {
    const amount = toMinor(form.amount);
    if (! amount) return null;
    if (props.rules.min_amount && amount < toMinor(props.rules.min_amount)) return `En dessous du minimum du site (${formatMoney(props.rules.min_amount)}) : une dérogation à confirmer à la validation.`;
    if (props.rules.max_amount && amount > toMinor(props.rules.max_amount)) return `Au-dessus du maximum du site (${formatMoney(props.rules.max_amount)}) : une dérogation à confirmer à la validation.`;

    return null;
});

const blocked = computed(() => chosen.value?.pending === true);
const ready = computed(() => Boolean(form.employee_uuid) && toMinor(form.amount) > 0 && ! blocked.value);

const submit = () => {
    if (! ready.value) return;
    form.post(staffDebtUrl('/finance/dettes'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Nouvelle dette du personnel" />

    <form class="space-y-5" @submit.prevent="submit">
        <PageHeader
            :eyebrow="`Finance · ${siteName}`"
            title="Nouvelle dette du personnel"
            description="La dette est enregistrée au nom de la personne, puis vous fixez son remboursement et la validez."
            :icon="HandCoins"
        >
            <template #actions>
                <Button :as="Link" :href="staffDebtUrl('/finance/dettes')" variant="outline"><ArrowLeft class="h-4 w-4" />Retour</Button>
                <Button type="submit" :disabled="form.processing || ! ready"><Plus class="h-4 w-4" />Créer la dette</Button>
            </template>
        </PageHeader>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <Card class="space-y-5 px-5 py-5">
                <FormField label="Membre du personnel" :icon="UserRound" required :error="form.errors.employee_uuid || form.errors.employee">
                    <SearchSelect
                        v-model="form.employee_uuid"
                        :options="options"
                        placeholder="Choisir une personne en poste"
                        search-placeholder="Nom, matricule, fonction…"
                        empty-text="Aucune personne en poste ne correspond."
                        :invalid="Boolean(form.errors.employee_uuid || form.errors.employee)"
                    />
                </FormField>

                <div v-if="chosen" class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <span v-if="chosen.engaged">{{ chosen.engaged }} dette{{ chosen.engaged > 1 ? 's' : '' }} déjà en cours.</span>
                    <span v-else>Aucune dette en cours.</span>
                </div>
                <p v-if="blocked" class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-foreground" role="alert">
                    <CircleAlert class="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                    Une dette de cette personne attend déjà votre décision : validez-la ou refusez-la avant d’en créer une autre.
                </p>

                <FormField label="Montant" :icon="Coins" required :error="form.errors.amount">
                    <IconInput v-model="form.amount" :icon="Coins" inputmode="decimal" placeholder="Ex. 300000" class="tabular-nums" />
                </FormField>
                <p v-if="outsideLimits" class="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400"><Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ outsideLimits }}</p>

                <FormField label="Motif" :icon="FileText" :error="form.errors.reason" hint="Facultatif. Lu par la direction seulement.">
                    <Textarea v-model="form.reason" rows="3" maxlength="1000" placeholder="Ex. avance sur frais médicaux" />
                </FormField>
            </Card>

            <aside class="space-y-4">
                <Card class="space-y-3 px-5 py-4">
                    <h2 class="text-sm font-semibold text-foreground">Ce qui sera à rembourser</h2>
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Montant</dt><dd class="tabular-nums">{{ toMinor(form.amount) ? formatMoney(form.amount) : '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Intérêt de la tranche</dt><dd class="tabular-nums">{{ preview?.interest ? formatMoney(preview.interest.amount) : '—' }}</dd></div>
                        <div class="flex justify-between gap-3 border-t border-border pt-1.5 font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ preview ? formatMoney(preview.total) : '—' }}</dd></div>
                    </dl>
                    <p class="text-xs text-muted-foreground">Un aperçu : l’intérêt est figé à la validation, où vous pouvez aussi le remettre.</p>
                </Card>
                <Card class="space-y-2 px-5 py-4 text-xs text-muted-foreground">
                    <p class="font-semibold text-foreground">Ensuite</p>
                    <p>La fiche de la dette s’ouvre : fixez la mensualité, le premier mois et le mode de remboursement, puis accordez-la.</p>
                    <p>La personne est prévenue et suit sa dette dans « Mes dettes ».</p>
                </Card>
            </aside>
        </div>
    </form>
</template>
