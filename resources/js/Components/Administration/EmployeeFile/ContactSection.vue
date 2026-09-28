<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Contact, IdCard, Mail, MapPin, Phone, Plus } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import { usePermissions } from '@/composables/usePermissions';
import EmployeeSectionCard from './EmployeeSectionCard.vue';

/**
 * ADR-213 — Contact et pièce d'identité, enregistrés tout seuls.
 *
 * Une nouvelle adresse, elle, s'ajoute par un bouton : enregistrée à la pause
 * de frappe, elle créerait une entrée du référentiel pour chaque mot tapé.
 * L'email est l'adresse professionnelle (ADR-190) : il se lit, ne se saisit pas.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    options: { type: Object, required: true },
    addresses: { type: Array, default: () => [] },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: true },
});
const { can } = usePermissions();

const { form, state, savedAt, retry } = useSectionAutosave('contact', {
    phone: props.employee.phone ?? '',
    address_entry_uuid: props.employee.address_entry_uuid ?? '',
    identity_document_type: props.employee.identity_document_type ?? '',
    identity_document_number: props.employee.identity_document_number ?? '',
    identity_document_issued_on: props.employee.identity_document_issued_on ?? '',
    identity_document_issued_at: props.employee.identity_document_issued_at ?? '',
}, () => props.url, {
    canEdit: () => props.canEdit,
    // Un type de pièce sans numéro (ou l'inverse) serait refusé : on attend les deux.
    ready: () => Boolean(form.identity_document_type) === Boolean(String(form.identity_document_number ?? '').trim()),
});

watch(() => form.identity_document_number, (number) => {
    if (number && ! form.identity_document_type) form.identity_document_type = 'CIN';
});

const addressOptions = computed(() => [
    { value: '', label: 'Non renseignée' },
    ...props.addresses.map((item) => ({ value: item.uuid, label: item.available ? item.label : `${item.label} — archivée`, disabled: ! item.available })),
]);
const identityTypeOptions = computed(() => [{ value: '', label: 'Non renseigné' }, ...(props.options.identity_document_types ?? [])]);

/* Nouvelle adresse : un geste explicite. */
const addingAddress = ref(false);
const addressForm = useForm({ new_address_label: '' });
const addAddress = () => addressForm.transform((data) => ({ ...data, _autosave: true })).put(props.url, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: (page) => {
        const created = page.props.employee?.address_entry_uuid;
        addressForm.reset();
        addingAddress.value = false;
        // La nouvelle adresse est déjà enregistrée sur la fiche : la liste la reprend sans renvoi.
        if (created) {
            form.address_entry_uuid = created;
            form.defaults('address_entry_uuid', created);
        }
    },
});
</script>

<template>
    <EmployeeSectionCard
        :icon="Contact"
        title="Contact et pièce d’identité"
        description="Comment joindre la personne, et sa pièce administrative. Tout est facultatif."
        tone="bg-cyan-50 text-cyan-600 dark:bg-cyan-950/50 dark:text-cyan-300"
        :state="state"
        :saved-at="savedAt"
        incomplete-hint="Type et numéro de pièce vont ensemble"
        :read-only="! canEdit"
        @retry="retry"
    >
        <fieldset :disabled="! canEdit" class="grid gap-6 lg:grid-cols-2 lg:divide-x lg:divide-border">
            <section class="space-y-4" aria-labelledby="contact-title">
                <h3 id="contact-title" class="flex items-center gap-2 text-sm font-bold text-foreground"><Phone class="h-4 w-4 text-cyan-600" />Contact</h3>
                <FormField label="Téléphone" :error="form.errors.phone">
                    <IconInput id="phone" v-model="form.phone" :icon="Phone" type="tel" autocomplete="tel" />
                </FormField>
                <div class="space-y-1.5">
                    <p class="text-sm font-medium text-foreground">Email</p>
                    <div class="flex items-start gap-2.5 rounded-lg border border-dashed border-border bg-muted/40 px-3 py-2">
                        <Mail class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <p v-if="employee.email" class="min-w-0 text-sm">
                            <span class="block truncate font-medium text-foreground">{{ employee.email }}</span>
                            <span class="block text-xs text-muted-foreground">L’adresse professionnelle : elle ne se modifie pas ici.</span>
                        </p>
                        <p v-else class="text-xs leading-5 text-muted-foreground">L’email d’un employé est son adresse professionnelle, créée avec son accès.</p>
                    </div>
                </div>
                <FormField as="div" label="Adresse" :error="form.errors.address_entry_uuid || addressForm.errors.new_address_label">
                    <template v-if="can('address_entries.create') && canEdit" #action>
                        <button type="button" class="text-xs font-semibold text-primary hover:underline" @click="addingAddress = ! addingAddress">{{ addingAddress ? 'Annuler' : '+ Nouvelle adresse' }}</button>
                    </template>
                    <ShadSelect id="address_entry_uuid" v-model="form.address_entry_uuid" :options="addressOptions" :icon="MapPin" placeholder="Non renseignée" class="w-full" aria-label="Adresse" :disabled="! canEdit" />
                    <form v-if="addingAddress" class="mt-2 flex gap-2" @submit.prevent="addAddress">
                        <IconInput v-model="addressForm.new_address_label" :icon="MapPin" placeholder="Saisir la nouvelle adresse" aria-label="Nouvelle adresse" class="flex-1" />
                        <Button type="submit" size="sm" :disabled="addressForm.processing || ! addressForm.new_address_label.trim()"><Plus class="h-4 w-4" />Ajouter</Button>
                    </form>
                </FormField>
            </section>
            <section class="space-y-4 lg:ps-6" aria-labelledby="document-title">
                <h3 id="document-title" class="flex items-center gap-2 text-sm font-bold text-foreground"><IdCard class="h-4 w-4 text-violet-600" />Pièce administrative</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Numéro de pièce" :error="form.errors.identity_document_number">
                        <Input id="identity_document_number" v-model="form.identity_document_number" />
                    </FormField>
                    <FormField as="div" label="Type" :error="form.errors.identity_document_type">
                        <ShadSelect id="identity_document_type" v-model="form.identity_document_type" :options="identityTypeOptions" placeholder="Non renseigné" class="w-full" aria-label="Type de pièce" :disabled="! canEdit" />
                    </FormField>
                    <FormField as="div" label="Délivrée le" :error="form.errors.identity_document_issued_on">
                        <DatePicker id="identity_document_issued_on" v-model="form.identity_document_issued_on" aria-label="Pièce délivrée le" :disabled="! canEdit" />
                    </FormField>
                    <FormField label="Délivrée à" :error="form.errors.identity_document_issued_at">
                        <Input id="identity_document_issued_at" v-model="form.identity_document_issued_at" />
                    </FormField>
                </div>
            </section>
        </fieldset>
    </EmployeeSectionCard>
</template>
