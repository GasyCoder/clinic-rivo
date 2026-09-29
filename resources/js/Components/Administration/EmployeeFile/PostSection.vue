<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Briefcase, Building2, CalendarClock, Gift, Hash, Info } from 'lucide-vue-next';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import { cn } from '@/lib/cn';
import { hrUrl } from '@/utilities/hrUrl';
import { seniority } from '@/utilities/seniority';
import EmployeeSectionCard from './EmployeeSectionCard.vue';

/**
 * ADR-221 — Poste : matricule, département, fonction, date d'entrée, état.
 *
 * ADR-194 — la fonction suit le département ; une fonction reliée à aucun
 * département reste proposée partout, et le couple déjà enregistré reste
 * choisissable. Le serveur refuse de toute façon un couple incohérent.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    departments: { type: Array, default: () => [] },
    jobTitles: { type: Array, default: () => [] },
    currentPair: { type: Object, default: null },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: true },
});
const { can } = usePermissions();

const { form, state, savedAt, retry } = useSectionAutosave('post', {
    employee_number: props.employee.employee_number ?? '',
    department_uuid: props.employee.department_uuid ?? '',
    job_title_uuid: props.employee.job_title_uuid ?? '',
    hire_date: props.employee.hire_date ?? '',
    active: Boolean(props.employee.active),
}, () => props.url, {
    canEdit: () => props.canEdit,
    ready: () => String(form.employee_number ?? '').trim() !== '',
});

const referenceOptions = (items, empty, archived) => [
    { value: '', label: empty },
    ...(items ?? []).map((item) => ({ value: item.uuid, label: item.available ? item.label : `${item.label} — ${archived}`, disabled: !item.available })),
];
const departmentOptions = computed(() => referenceOptions(props.departments, 'Non affecté', 'archivé'));
const departmentRecord = computed(() => props.departments.find((item) => item.uuid === form.department_uuid));
const selectedDepartment = computed(() => departmentRecord.value?.label || 'Non affecté');

const isCurrentPair = (departmentUuid, jobTitleUuid) => props.currentPair
    && props.currentPair.department_uuid === departmentUuid
    && props.currentPair.job_title_uuid === jobTitleUuid;
const belongsTo = (jobTitle, departmentUuid) => ! departmentUuid
    || (jobTitle.department_uuids ?? []).length === 0
    || jobTitle.department_uuids.includes(departmentUuid)
    || isCurrentPair(departmentUuid, jobTitle.uuid);
const allowedJobTitles = computed(() => props.jobTitles.filter((item) => belongsTo(item, form.department_uuid)));
const jobTitleOptions = computed(() => {
    const option = (item) => ({ value: item.uuid, label: item.available ? item.label : `${item.label} — archivée`, disabled: ! item.available });
    const none = { value: '', label: 'Non renseignée' };

    if (! form.department_uuid) return [none, ...allowedJobTitles.value.map(option)];

    const own = allowedJobTitles.value.filter((item) => (item.department_uuids ?? []).includes(form.department_uuid) || isCurrentPair(form.department_uuid, item.uuid));
    const shared = allowedJobTitles.value.filter((item) => (item.department_uuids ?? []).length === 0 && ! own.includes(item));

    return [
        none,
        ...(own.length ? [{ label: `Fonctions de ${selectedDepartment.value}`, items: own.map(option) }] : []),
        ...(shared.length ? [{ label: 'Proposées dans tous les départements', items: shared.map(option) }] : []),
    ];
});

// Changer de département retire une fonction qui n'y existe pas, et le dit.
const clearedJobTitle = ref('');
watch(() => form.department_uuid, (departmentUuid) => {
    const current = props.jobTitles.find((item) => item.uuid === form.job_title_uuid);
    if (current && ! belongsTo(current, departmentUuid)) {
        clearedJobTitle.value = current.label;
        form.job_title_uuid = '';
    } else {
        clearedJobTitle.value = '';
    }
});
watch(() => form.job_title_uuid, (value) => { if (value) clearedJobTitle.value = ''; });

const jobTitleRecord = computed(() => props.jobTitles.find((item) => item.uuid === form.job_title_uuid));
const hireSeniority = computed(() => seniority(form.hire_date));
const invalid = (field) => Boolean(form.errors?.[field]);
</script>

<template>
    <EmployeeSectionCard
        :icon="Briefcase"
        title="Poste"
        description="Où la personne travaille. Choisissez d’abord le département : seules ses fonctions sont proposées."
        tone="bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300"
        :state="state"
        :saved-at="savedAt"
        incomplete-hint="Le matricule est obligatoire"
        :read-only="! canEdit"
        @retry="retry"
    >
        <fieldset :disabled="! canEdit" class="grid gap-4 sm:grid-cols-2">
            <FormField label="Matricule" required :error="form.errors.employee_number">
                <IconInput id="employee_number" v-model="form.employee_number" :icon="Hash" autocomplete="off" :aria-invalid="invalid('employee_number')" class="font-mono" />
                <span class="mt-1.5 block text-xs text-muted-foreground">Unique sur le site, archives comprises.</span>
            </FormField>
            <FormField as="div" label="Date d’entrée" :error="form.errors.hire_date">
                <DatePicker id="hire_date" v-model="form.hire_date" aria-label="Date d’entrée" :invalid="invalid('hire_date')" :disabled="! canEdit" />
                <span class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                    <CalendarClock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <template v-if="hireSeniority">Ancienneté : <strong class="font-semibold text-foreground">{{ hireSeniority.label }}</strong> (calculée)</template>
                    <template v-else>L’ancienneté se calcule depuis cette date.</template>
                </span>
            </FormField>
            <FormField as="div" label="Département" :error="form.errors.department_uuid">
                <ShadSelect id="department_uuid" v-model="form.department_uuid" :options="departmentOptions" :icon="Building2" placeholder="Non affecté" class="w-full" aria-label="Département" :disabled="! canEdit" />
            </FormField>
            <FormField as="div" label="Fonction" :error="form.errors.job_title_uuid">
                <ShadSelect id="job_title_uuid" v-model="form.job_title_uuid" :options="jobTitleOptions" :icon="Briefcase" placeholder="Non renseignée" class="w-full" aria-label="Fonction" :disabled="! canEdit" />
                <p class="mt-1.5 text-xs text-muted-foreground">
                    <template v-if="form.department_uuid">{{ allowedJobTitles.length }} fonction{{ allowedJobTitles.length > 1 ? 's' : '' }} pour {{ selectedDepartment }}.</template>
                    <template v-else>Choisissez un département pour ne voir que ses fonctions.</template>
                    <template v-if="can('hr_settings.view')"> · <Link :href="hrUrl('/administration/job-titles')" class="font-semibold text-primary hover:underline">Module Fonctions</Link></template>
                </p>
                <p v-if="jobTitleRecord" :class="cn('mt-1 flex items-center gap-1 text-xs', jobTitleRecord.grants_benefits ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground')">
                    <Gift class="h-3.5 w-3.5" aria-hidden="true" />{{ jobTitleRecord.grants_benefits ? 'Cette fonction ouvre droit aux avantages et primes.' : 'Cette fonction n’ouvre pas droit aux avantages.' }}
                </p>
            </FormField>
            <p v-if="clearedJobTitle" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 sm:col-span-2 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />« {{ clearedJobTitle }} » n’existe pas dans {{ selectedDepartment }} : choisissez une fonction de ce département.
            </p>
            <label for="active" class="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-muted/40 px-3.5 py-3 sm:col-span-2">
                <Checkbox id="active" v-model="form.active" class="mt-0.5" :disabled="! canEdit" />
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-foreground">Employé actif</span>
                    <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">Visible dans les parcours RH : présences, congés, planning. Décoché au départ de la personne.</span>
                </span>
            </label>
        </fieldset>
    </EmployeeSectionCard>
</template>
