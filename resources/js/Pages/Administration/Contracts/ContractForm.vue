<script setup>
import { computed } from 'vue';
import { CalendarRange, FileSignature, GraduationCap, Info, School, TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import HrEmployeePicker from '../Partials/HrEmployeePicker.vue';
import HrFormActions from '../Partials/HrFormActions.vue';
import HrFormSection from '../Partials/HrFormSection.vue';
import { formatPeriod } from '@/utilities/hr';

/*
 * Un contrat : le collaborateur, le type, les dates (ADR-066, ADR-071).
 *
 * ADR-194 — un type marqué « contrat de stage » ouvre la section Stage :
 * filière (exigée par le serveur), école, niveau et encadrant. Changer pour
 * un autre type la referme et le serveur efface ces champs.
 */
const props = defineProps({
    form: Object,
    employees: [Array, Object],
    contractTypes: [Array, Object],
    internshipFields: { type: [Array, Object], default: () => [] },
    cancelHref: String,
    submitLabel: String,
});
defineEmits(['submit']);

const selectedEmployee = computed(() => props.employees.find((employee) => employee.uuid === props.form.employee_uuid));
const selectedType = computed(() => props.contractTypes.find((type) => type.uuid === props.form.contract_type_uuid));
const isInternship = computed(() => Boolean(selectedType.value?.internship));
const selectedField = computed(() => props.internshipFields.find((field) => field.uuid === props.form.internship_field_uuid));
const supervisor = computed(() => props.employees.find((employee) => employee.uuid === props.form.internship_supervisor_uuid));

const typeOptions = computed(() => props.contractTypes.map((type) => ({ value: type.uuid, label: type.internship ? `${type.label} · contrat de stage` : type.label })));
const fieldOptions = computed(() => props.internshipFields.map((field) => ({
    value: field.uuid,
    label: `${field.label}${field.available ? '' : ' — archivée'}`,
    disabled: !field.available && field.uuid !== props.form.internship_field_uuid,
})));
</script>

<template>
    <form class="space-y-4" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
            <main class="space-y-4">
                <HrFormSection number="1" title="Choisir le collaborateur" description="Le contrat est relié au dossier Employé ; aucune identité n’est recopiée.">
                    <HrEmployeePicker id="contract_employee" v-model="form.employee_uuid" :employees="employees" required :error="form.errors.employee_uuid" />
                </HrFormSection>

                <HrFormSection number="2" title="Type et référence" description="Le document à signer se produit ensuite depuis « Documents » (canevas Super Admin).">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <FormField as="div" label="Type de contrat" required :error="form.errors.contract_type_uuid">
                            <Select id="contract_type" v-model="form.contract_type_uuid" :options="typeOptions" :icon="FileSignature" placeholder="Sélectionner un type" class="w-full min-w-0" aria-label="Type de contrat" />
                        </FormField>
                        <FormField label="Référence interne" hint="(facultative, unique)" :error="form.errors.reference_number">
                            <Input id="contract_reference" v-model="form.reference_number" placeholder="Ex. CTR-2026-014" />
                        </FormField>
                    </div>
                </HrFormSection>

                <!-- ADR-194 — le stage. -->
                <HrFormSection v-if="isInternship" :icon="GraduationCap" title="Le stage" description="La filière est obligatoire ; l’école, le niveau et l’encadrant aident à suivre le stagiaire.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <FormField as="div" label="Filière" required :error="form.errors.internship_field_uuid">
                            <Select id="internship_field" v-model="form.internship_field_uuid" :options="fieldOptions" :icon="GraduationCap" placeholder="Infirmier, Sage-femme…" class="w-full min-w-0" aria-label="Filière du stage" />
                            <p v-if="!internshipFields.length" class="mt-1.5 text-xs text-amber-700 dark:text-amber-300">Aucune filière : ajoutez-en dans Paramètres RH › Filière de stage.</p>
                        </FormField>
                        <FormField label="École ou établissement" :error="form.errors.internship_school">
                            <Input id="internship_school" v-model="form.internship_school" placeholder="Ex. Institut de formation paramédicale" />
                        </FormField>
                        <FormField label="Niveau d’études" :error="form.errors.internship_level">
                            <Input id="internship_level" v-model="form.internship_level" placeholder="Ex. 3e année" />
                        </FormField>
                        <div>
                            <HrEmployeePicker id="internship_supervisor" v-model="form.internship_supervisor_uuid" :employees="employees" label="Encadrant" placeholder="Choisir l’encadrant" :exclude-uuid="form.employee_uuid" :error="form.errors.internship_supervisor_uuid" />
                        </div>
                    </div>
                </HrFormSection>

                <HrFormSection number="3" title="Calendrier" description="La période est enregistrée telle qu’elle est déclarée ; aucun renouvellement n’est automatique.">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <FormField as="div" label="Signature" :error="form.errors.signed_on"><DatePicker id="contract_signed" v-model="form.signed_on" /></FormField>
                        <FormField as="div" label="Début" required :error="form.errors.starts_on"><DatePicker id="contract_starts" v-model="form.starts_on" required /></FormField>
                        <FormField as="div" :label="isInternship ? 'Fin du stage' : 'Fin d’essai'" :error="isInternship ? form.errors.ends_on : form.errors.trial_ends_on">
                            <DatePicker v-if="isInternship" id="contract_ends" v-model="form.ends_on" />
                            <DatePicker v-else id="contract_trial" v-model="form.trial_ends_on" />
                        </FormField>
                        <FormField v-if="!isInternship" as="div" label="Fin du contrat" :error="form.errors.ends_on"><DatePicker id="contract_ends" v-model="form.ends_on" /></FormField>
                    </div>
                </HrFormSection>

                <HrFormSection :icon="FileSignature" title="Note administrative" description="Une information utile au suivi de ce contrat." optional>
                    <Textarea id="contract_observation" v-model="form.observation" rows="3" placeholder="Observation facultative" />
                </HrFormSection>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <div class="border-b border-border bg-muted/40 px-5 py-4">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Aperçu</p>
                        <div class="mt-2 flex items-center gap-3">
                            <EmployeePhoto :src="selectedEmployee?.photo_url" :name="selectedEmployee?.name ?? ''" size="md" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-foreground">{{ selectedEmployee?.name || 'Collaborateur à choisir' }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ selectedEmployee ? `${selectedEmployee.employee_number} · ${selectedEmployee.job_title || 'Fonction non renseignée'}` : '—' }}</p>
                            </div>
                        </div>
                    </div>
                    <dl class="divide-y divide-border px-5 text-sm">
                        <div class="py-3"><dt class="text-xs text-muted-foreground">Nature</dt><dd class="mt-1 flex flex-wrap items-center gap-2 font-semibold text-foreground">{{ selectedType?.label || 'À choisir' }}<Badge v-if="isInternship" variant="secondary"><GraduationCap class="h-3.5 w-3.5" />Stage</Badge></dd></div>
                        <div v-if="isInternship" class="py-3"><dt class="text-xs text-muted-foreground">Stage</dt><dd class="mt-1 font-semibold text-foreground">{{ selectedField?.label || 'Filière à choisir' }}</dd><dd class="flex items-center gap-1.5 text-xs text-muted-foreground"><School class="h-3.5 w-3.5" />{{ form.internship_school || 'École non renseignée' }}<span v-if="form.internship_level"> · {{ form.internship_level }}</span></dd><dd class="text-xs text-muted-foreground">Encadrant : {{ supervisor?.name || 'non désigné' }}</dd></div>
                        <div class="py-3"><dt class="text-xs text-muted-foreground">Période</dt><dd class="mt-1 flex items-center gap-1.5 font-semibold text-foreground"><CalendarRange class="h-4 w-4 text-muted-foreground" />{{ formatPeriod(form.starts_on, form.ends_on) }}</dd></div>
                    </dl>
                </section>
                <section v-if="isInternship && !form.ends_on" class="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300">
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />Un stage a une fin : renseignez-la pour qu’il quitte « Stages en cours » à la bonne date.
                </section>
                <section class="flex gap-3 rounded-2xl border border-border bg-card p-4 text-xs leading-5 text-muted-foreground shadow-sm">
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />Aucune paie automatique : ce formulaire ne calcule ni salaire, ni CNAPS, ni IRSA, ni renouvellement.
                </section>
            </aside>
        </div>
        <HrFormActions :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing" />
    </form>
</template>
