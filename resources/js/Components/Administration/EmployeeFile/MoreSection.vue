<script setup>
import { computed } from 'vue';
import { BadgeCheck, GraduationCap, HeartHandshake, ListPlus, Shirt } from 'lucide-vue-next';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import EmployeeSectionCard from './EmployeeSectionCard.vue';

/**
 * ADR-213 — Famille, qualification et matériel : des informations déclaratives,
 * toutes facultatives, enregistrées toutes seules.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    options: { type: Object, required: true },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: true },
});

const { form, state, savedAt, retry } = useSectionAutosave('more', {
    marital_status: props.employee.marital_status ?? '',
    children_count: props.employee.children_count ?? '',
    children_details: props.employee.children_details ?? '',
    diploma: props.employee.diploma ?? '',
    education_level: props.employee.education_level ?? '',
    badge: props.employee.badge ?? '',
    blouse: props.employee.blouse ?? '',
    observation: props.employee.observation ?? '',
}, () => props.url, { canEdit: () => props.canEdit });

const maritalOptions = computed(() => [{ value: '', label: 'Non renseignée' }, ...(props.options.marital_statuses ?? [])]);
</script>

<template>
    <EmployeeSectionCard
        :icon="ListPlus"
        title="Famille et qualification"
        description="Informations déclaratives, sans aucun calcul automatique. Tout est facultatif."
        tone="bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300"
        :state="state"
        :saved-at="savedAt"
        :read-only="! canEdit"
        @retry="retry"
    >
        <fieldset :disabled="! canEdit" class="grid gap-4 lg:grid-cols-3">
            <section class="space-y-4 rounded-xl border border-border p-3.5">
                <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><HeartHandshake class="h-4 w-4 text-rose-600" />Famille</h3>
                <FormField as="div" label="Situation matrimoniale" :error="form.errors.marital_status">
                    <ShadSelect id="marital_status" v-model="form.marital_status" :options="maritalOptions" placeholder="Non renseignée" class="w-full" aria-label="Situation matrimoniale" :disabled="! canEdit" />
                </FormField>
                <FormField label="Nombre d’enfants" :error="form.errors.children_count">
                    <Input id="children_count" v-model="form.children_count" type="number" min="0" inputmode="numeric" />
                </FormField>
                <FormField label="Note sur les enfants" :error="form.errors.children_details">
                    <Textarea id="children_details" v-model="form.children_details" :rows="3" placeholder="Prénoms, dates de naissance…" />
                </FormField>
            </section>
            <section class="space-y-4 rounded-xl border border-border p-3.5">
                <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><GraduationCap class="h-4 w-4 text-amber-600" />Qualification</h3>
                <FormField label="Diplôme" :error="form.errors.diploma">
                    <Input id="diploma" v-model="form.diploma" />
                </FormField>
                <FormField label="Niveau d’études" :error="form.errors.education_level">
                    <Input id="education_level" v-model="form.education_level" />
                </FormField>
                <FormField label="Observation RH" :error="form.errors.observation">
                    <Textarea id="observation" v-model="form.observation" :rows="3" placeholder="Information utile au suivi administratif" />
                </FormField>
            </section>
            <section class="space-y-4 rounded-xl border border-border p-3.5">
                <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><Shirt class="h-4 w-4 text-emerald-600" />Matériel remis</h3>
                <FormField label="Badge" :error="form.errors.badge">
                    <IconInput id="badge" v-model="form.badge" :icon="BadgeCheck" placeholder="Numéro ou référence" />
                </FormField>
                <FormField label="Blouse" :error="form.errors.blouse">
                    <IconInput id="blouse" v-model="form.blouse" :icon="Shirt" placeholder="Taille ou référence" />
                </FormField>
            </section>
        </fieldset>
    </EmployeeSectionCard>
</template>
