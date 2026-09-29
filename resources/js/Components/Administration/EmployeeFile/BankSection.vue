<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CreditCard, Landmark, UserRound } from 'lucide-vue-next';
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
}, () => props.url, {
    canEdit: () => props.canEdit,
    // Un numéro de compte part avec son titulaire.
    ready: () => ! String(form.bank_account_number ?? '').trim() || String(form.bank_account_holder ?? '').trim() !== '',
});

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
        <fieldset :disabled="! canEdit" class="grid gap-4 sm:grid-cols-2">
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
        </fieldset>
    </EmployeeSectionCard>
</template>
