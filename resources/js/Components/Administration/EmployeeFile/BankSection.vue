<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Banknote, Check, CreditCard, Landmark, Smartphone, UserRound } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import { hrUrl } from '@/utilities/hrUrl';
import EmployeeSectionCard from './EmployeeSectionCard.vue';

/**
 * ADR-221 — le compte bancaire : la banque se choisit dans le module Banques
 * (plus de texte libre : « BOA » et « Bank of Africa » sont une seule banque),
 * puis le numéro et le titulaire tel qu'il figure à la banque.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    payroll: { type: Object, default: null },
    banks: { type: Array, default: () => [] },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
});
const { can } = usePermissions();

const { form, state, savedAt, retry } = useSectionAutosave('bank', {
    bank_uuid: props.payroll?.bank_uuid ?? '',
    bank_account_number: props.payroll?.bank_account_number ?? '',
    bank_account_holder: props.payroll?.bank_account_holder ?? '',
    salary_payment_mode: props.payroll?.salary_payment_mode ?? '',
    mobile_money_number: props.payroll?.mobile_money_number ?? '',
}, () => props.url, {
    canEdit: () => props.canEdit,
    // Un numéro de compte part avec son titulaire.
    ready: () => ! String(form.bank_account_number ?? '').trim() || String(form.bank_account_holder ?? '').trim() !== '',
});

const paymentModes = [
    { value: 'BANK', label: 'Virement bancaire', hint: 'Sur son compte en banque', icon: Landmark },
    { value: 'MOBILE_MONEY', label: 'Mobile Money', hint: 'Sur son numéro', icon: Smartphone },
    { value: 'CASH', label: 'Espèces', hint: 'Remis en main propre', icon: Banknote },
];
// Un second clic retire le choix : « non renseigné » reste possible.
const pickMode = (value) => { form.salary_payment_mode = form.salary_payment_mode === value ? '' : value; };
// Les champs du compte : pour un virement, ou tant qu'un compte est déjà noté (rien ne se cache).
const showBank = computed(() => ['', 'BANK'].includes(form.salary_payment_mode) || Boolean(form.bank_uuid || form.bank_account_number));

const bankOptions = computed(() => [
    { value: '', label: 'Non renseignée' },
    ...props.banks.map((bank) => ({ value: bank.uuid, label: bank.available ? bank.label : `${bank.label} — archivée`, disabled: ! bank.available })),
]);
const holderSuggestion = computed(() => [props.employee.last_name, props.employee.first_name].filter(Boolean).join(' ').toUpperCase());
const useNameAsHolder = () => {
    form.bank_account_holder = holderSuggestion.value;
    form.clearErrors('bank_account_holder');
};
</script>

<template>
    <EmployeeSectionCard
        :icon="Landmark"
        title="Banque et compte"
        description="Le compte où la personne reçoit sa rémunération. La banque vient de la liste du module Banques."
        tone="bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-300"
        :state="state"
        :saved-at="savedAt"
        incomplete-hint="Indiquez le titulaire du compte"
        :read-only="! canEdit"
        read-only-hint="Lecture seule : modifier le compte bancaire demande le droit « employees.payroll.update »."
        @retry="retry"
    >
        <fieldset :disabled="! canEdit" class="grid gap-5">
            <FormField as="div" label="Mode de paiement du salaire" :error="form.errors.salary_payment_mode">
                <div class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Mode de paiement du salaire">
                    <button
                        v-for="mode in paymentModes"
                        :key="mode.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.salary_payment_mode === mode.value"
                        :disabled="! canEdit"
                        :class="cn(
                            'relative flex items-center gap-3 rounded-xl border p-3 text-start transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                            form.salary_payment_mode === mode.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-background hover:border-primary/40',
                        )"
                        @click="pickMode(mode.value)"
                    >
                        <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', form.salary_payment_mode === mode.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')"><component :is="mode.icon" class="h-4 w-4" aria-hidden="true" /></span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-foreground">{{ mode.label }}</span>
                            <span class="block text-xs text-muted-foreground">{{ mode.hint }}</span>
                        </span>
                        <Check v-if="form.salary_payment_mode === mode.value" class="absolute end-3 top-3 h-4 w-4 text-primary" aria-hidden="true" />
                    </button>
                </div>
            </FormField>

            <FormField v-if="form.salary_payment_mode === 'MOBILE_MONEY'" label="Numéro Mobile Money" :error="form.errors.mobile_money_number" class="sm:max-w-sm">
                <IconInput id="mobile_money_number" v-model="form.mobile_money_number" :icon="Smartphone" type="tel" autocomplete="off" placeholder="03X XX XXX XX" />
            </FormField>
            <p v-if="form.salary_payment_mode === 'CASH' && ! showBank" class="rounded-lg border border-dashed border-border px-3 py-3 text-xs text-muted-foreground">Payé en espèces : aucun compte à renseigner.</p>

            <div v-if="showBank" class="grid gap-4 rounded-xl border border-border p-4 sm:grid-cols-2">
            <FormField as="div" label="Banque" class="sm:col-span-2" :error="form.errors.bank_uuid">
                <ShadSelect id="bank_uuid" v-model="form.bank_uuid" :options="bankOptions" :icon="Landmark" placeholder="Non renseignée" class="w-full sm:max-w-md" aria-label="Banque" :disabled="! canEdit" />
                <p class="mt-1.5 text-xs text-muted-foreground">
                    <template v-if="! banks.length">Aucune banque n’est encore enregistrée. </template>
                    Une banque manque ?
                    <Link v-if="can('hr_settings.view')" :href="hrUrl('/administration/banks')" class="font-semibold text-primary hover:underline">Ouvrir le module Banques</Link>
                    <span v-else>Demandez-la au RH (module Banques).</span>
                </p>
            </FormField>
            <FormField label="Numéro de compte (RIB)" :error="form.errors.bank_account_number">
                <IconInput id="bank_account_number" v-model="form.bank_account_number" :icon="CreditCard" autocomplete="off" placeholder="Ex. 00005 00001 12345678901 23" class="font-mono uppercase" />
            </FormField>
            <FormField label="Nom du titulaire" :required="Boolean(form.bank_account_number)" :error="form.errors.bank_account_holder">
                <IconInput id="bank_account_holder" v-model="form.bank_account_holder" :icon="UserRound" autocomplete="off" placeholder="Nom sur le compte" />
                <button
                    v-if="canEdit && holderSuggestion && form.bank_account_holder !== holderSuggestion"
                    type="button"
                    class="mt-1.5 text-xs font-semibold text-primary hover:underline"
                    @click="useNameAsHolder"
                >Reprendre « {{ holderSuggestion }} »</button>
            </FormField>
            </div>
        </fieldset>
    </EmployeeSectionCard>
</template>
