<script setup>
import { computed, watch } from 'vue';
import { Baby, CircleUser, FolderClock, IdCard, Info, Type } from 'lucide-vue-next';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Separator from '@/Components/Shadcn/Separator.vue';
import NumberingOptionIcon from '@/Components/Settings/NumberingOptionIcon.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';

/**
 * ADR-191 — la forme des numéros de patient et de passage, et le matricule
 * proposé aux RH. Un numéro déjà attribué ne change jamais : le réglage vaut
 * pour les suivants, et le serveur saute tout numéro qui existerait déjà.
 */
const props = defineProps({
    form: { type: Object, required: true },
    saved: { type: Object, required: true },
    numbering: { type: Object, default: () => ({}) },
    fallbacks: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
});

const PATIENT_FIELDS = ['patient_number_prefix', 'patient_number_year', 'patient_number_digits', 'patient_number_separator', 'patient_number_reset', 'episode_number_digits'];
const EMPLOYEE_FIELDS = ['employee_number_prefix', 'employee_number_separator', 'employee_number_digits'];

const patientDefaults = computed(() => props.options.defaults?.patient ?? { year: '2', digits: 4, separator: '-', reset: 'yearly', episode_digits: 2 });
const employeeDefaults = computed(() => props.options.defaults?.employee ?? { prefix: 'EMP', separator: '-', digits: 4 });
const SEPARATOR_NAMES = { '-': 'Tiret', '/': 'Barre oblique', '.': 'Point', '_': 'Tiret bas' };
/** Le caractère lui-même est dans le repère de l'option : le libellé n'écrit que son nom. */
const separators = computed(() => (props.options.separators ?? ['-', '/', '.', '_']).map((value) => ({ value, label: SEPARATOR_NAMES[value] ?? value })));
const range = ([min, max]) => Array.from({ length: max - min + 1 }, (_, index) => ({ value: String(min + index), label: `${min + index} chiffres` }));

const year = computed(() => Number(props.numbering.current_year) || new Date().getFullYear());
const yearOptions = computed(() => [
    { value: 'none', label: 'Sans année' },
    { value: '2', label: `${String(year.value % 100).padStart(2, '0')} — deux chiffres` },
    { value: '4', label: `${year.value} — quatre chiffres` },
]);
const resetOptions = [
    { value: 'yearly', label: 'Chaque année, le 1er janvier' },
    { value: 'never', label: 'Jamais — compteur continu' },
];

const value = (field, fallback) => (props.form[field] === '' || props.form[field] === null || props.form[field] === undefined ? fallback : props.form[field]);
/** La valeur par défaut n'est pas écrite : le champ vide dit « comme d'habitude ». */
const choose = (field, next, fallback, numeric = false) => {
    props.form[field] = ! next || String(next) === String(fallback) ? '' : (numeric ? Number(next) : next);
};

const patient = computed(() => {
    const yearMode = String(value('patient_number_year', patientDefaults.value.year));

    return {
        prefix: String(value('patient_number_prefix', props.fallbacks.patient_number_prefix || 'X')).toUpperCase(),
        year: yearMode,
        digits: Number(value('patient_number_digits', patientDefaults.value.digits)),
        separator: String(value('patient_number_separator', patientDefaults.value.separator)),
        reset: yearMode === 'none' ? 'never' : String(value('patient_number_reset', patientDefaults.value.reset)),
        episodeDigits: Number(value('episode_number_digits', patientDefaults.value.episode_digits)),
    };
});

// Sans année dans le numéro, la remise à 1 annuelle redonnerait les mêmes numéros.
watch(() => patient.value.year, (mode) => {
    if (mode === 'none' && props.form.patient_number_reset !== 'never') props.form.patient_number_reset = 'never';
});

const unchanged = (fields) => fields.every((field) => String(props.form[field] ?? '') === String(props.saved[field] ?? ''));

const patientNumber = computed(() => {
    if (props.numbering.available && unchanged(PATIENT_FIELDS)) return props.numbering.patient_next;

    const format = patient.value;
    const counter = format.reset === 'yearly' ? props.numbering.yearly_next : props.numbering.continuous_next;
    const yearPart = format.year === 'none' ? '' : format.year === '4' ? String(year.value) : String(year.value % 100).padStart(2, '0');

    return [format.prefix, yearPart, String(Number(counter) || 1).padStart(format.digits, '0')].filter(Boolean).join(format.separator);
});
const episodeNumber = computed(() => `${patientNumber.value}${patient.value.separator}${'1'.padStart(patient.value.episodeDigits, '0')}`);
const newbornNumber = computed(() => `${patientNumber.value}${patient.value.separator}B1`);

const employee = computed(() => ({
    prefix: String(value('employee_number_prefix', props.fallbacks.employee_number_prefix || employeeDefaults.value.prefix)).toUpperCase(),
    separator: String(value('employee_number_separator', employeeDefaults.value.separator)),
    digits: Number(value('employee_number_digits', employeeDefaults.value.digits)),
}));
const employeeExact = computed(() => props.numbering.available && unchanged(EMPLOYEE_FIELDS));
const employeeNumber = computed(() => (employeeExact.value
    ? props.numbering.employee_next
    : `${employee.value.prefix}${employee.value.separator}${'1'.padStart(employee.value.digits, '0')}`));
</script>

<template>
    <SettingsSection id="numerotation" title="Numérotation" body-class="space-y-10" description="Le numéro des nouveaux patients et de leurs passages, et le matricule proposé à la création d’un employé. Un numéro déjà attribué ne change jamais.">
        <!-- Patients et passages -->
        <div class="space-y-6" role="group" aria-labelledby="numerotation-patients">
            <div>
                <h4 id="numerotation-patients" class="flex items-center gap-2 text-base font-medium text-foreground"><CircleUser class="h-4 w-4 text-primary" aria-hidden="true" />Patients et passages</h4>
                <p class="text-sm text-muted-foreground">Le numéro donné à un nouveau dossier patient, puis à chacun de ses passages.</p>
            </div>
            <div class="grid gap-6 sm:grid-cols-2 cq-4xl:grid-cols-3">
                <SettingsField label="Préfixe" for="reglage-patient-prefix" :description="`Lettres ou chiffres. Vide : le code du site (${fallbacks.patient_number_prefix || '—'}).`" :error="form.errors.patient_number_prefix">
                    <IconInput id="reglage-patient-prefix" v-model="form.patient_number_prefix" :icon="Type" class="font-mono uppercase" maxlength="12" :placeholder="fallbacks.patient_number_prefix" :disabled="readonly" />
                </SettingsField>
                <SettingsField label="Année dans le numéro" for="reglage-patient-year" description="Sur deux ou quatre chiffres, ou sans année." :error="form.errors.patient_number_year">
                    <Select id="reglage-patient-year" :model-value="patient.year" :options="yearOptions" class="w-full" :disabled="readonly" @update:model-value="choose('patient_number_year', $event, patientDefaults.year)">
                        <template #leading="{ option }"><NumberingOptionIcon kind="year" :value="option.value" :year="year" /></template>
                    </Select>
                </SettingsField>
                <SettingsField label="Séparateur" for="reglage-patient-separator" description="Entre le préfixe, l’année, le compteur et le rang du passage." :error="form.errors.patient_number_separator">
                    <Select id="reglage-patient-separator" :model-value="patient.separator" :options="separators" class="w-full" :disabled="readonly" @update:model-value="choose('patient_number_separator', $event, patientDefaults.separator)">
                        <template #leading="{ option }"><NumberingOptionIcon kind="separator" :value="option.value" /></template>
                    </Select>
                </SettingsField>
                <SettingsField label="Chiffres du compteur" for="reglage-patient-digits" description="Complétés par des zéros : 4 donne 0001." :error="form.errors.patient_number_digits">
                    <Select id="reglage-patient-digits" :model-value="String(patient.digits)" :options="range(options.patient_digits ?? [3, 8])" class="w-full" :disabled="readonly" @update:model-value="choose('patient_number_digits', $event, patientDefaults.digits, true)">
                        <template #leading="{ option }"><NumberingOptionIcon kind="digits" :value="option.value" /></template>
                    </Select>
                </SettingsField>
                <SettingsField label="Remise à 1" for="reglage-patient-reset" :description="patient.year === 'none' ? 'Sans année dans le numéro, le compteur est forcément continu.' : 'Chaque 1er janvier, ou un compteur continu.'" :error="form.errors.patient_number_reset">
                    <Select id="reglage-patient-reset" :model-value="patient.reset" :options="resetOptions" class="w-full" :disabled="readonly || patient.year === 'none'" @update:model-value="choose('patient_number_reset', $event, patientDefaults.reset)">
                        <template #leading="{ option }"><NumberingOptionIcon kind="reset" :value="option.value" /></template>
                    </Select>
                </SettingsField>
                <SettingsField label="Rang du passage" for="reglage-episode-digits" description="Ses chiffres, ajoutés au numéro du patient." :error="form.errors.episode_number_digits">
                    <Select id="reglage-episode-digits" :model-value="String(patient.episodeDigits)" :options="range(options.episode_digits ?? [2, 4])" class="w-full" :disabled="readonly" @update:model-value="choose('episode_number_digits', $event, patientDefaults.episode_digits, true)">
                        <template #leading="{ option }"><NumberingOptionIcon kind="digits" :value="option.value" /></template>
                    </Select>
                </SettingsField>
            </div>
            <SettingsField label="Aperçu" description="Les prochains numéros, avec ces réglages.">
                <dl class="max-w-2xl divide-y divide-border rounded-md border border-border text-sm" aria-label="Aperçu des numéros">
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5"><dt class="flex items-center gap-2 text-muted-foreground"><CircleUser class="h-4 w-4 shrink-0" aria-hidden="true" />Prochain patient</dt><dd class="font-mono font-medium text-foreground">{{ patientNumber }}</dd></div>
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5"><dt class="flex items-center gap-2 text-muted-foreground"><FolderClock class="h-4 w-4 shrink-0" aria-hidden="true" />Son premier passage</dt><dd class="font-mono font-medium text-foreground">{{ episodeNumber }}</dd></div>
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5"><dt class="flex items-center gap-2 text-muted-foreground"><Baby class="h-4 w-4 shrink-0" aria-hidden="true" />Un bébé né à la clinique</dt><dd class="font-mono font-medium text-foreground">{{ newbornNumber }}</dd></div>
                </dl>
            </SettingsField>
        </div>

        <Separator />

        <!-- Matricule -->
        <div class="space-y-6" role="group" aria-labelledby="numerotation-matricule">
            <div>
                <h4 id="numerotation-matricule" class="flex items-center gap-2 text-base font-medium text-foreground"><IdCard class="h-4 w-4 text-primary" aria-hidden="true" />Matricule des employés</h4>
                <p class="text-sm text-muted-foreground">Proposé à la création d’un employé, modifiable par le RH.</p>
            </div>
            <div class="grid gap-6 sm:grid-cols-3">
                <SettingsField label="Préfixe" for="reglage-employee-prefix" :description="`Vide : ${employeeDefaults.prefix}.`" :error="form.errors.employee_number_prefix">
                    <IconInput id="reglage-employee-prefix" v-model="form.employee_number_prefix" :icon="Type" class="font-mono uppercase" maxlength="12" :placeholder="employeeDefaults.prefix" :disabled="readonly" />
                </SettingsField>
                <SettingsField label="Séparateur" for="reglage-employee-separator" :error="form.errors.employee_number_separator">
                    <Select id="reglage-employee-separator" :model-value="employee.separator" :options="separators" class="w-full" :disabled="readonly" @update:model-value="choose('employee_number_separator', $event, employeeDefaults.separator)">
                        <template #leading="{ option }"><NumberingOptionIcon kind="separator" :value="option.value" /></template>
                    </Select>
                </SettingsField>
                <SettingsField label="Chiffres" for="reglage-employee-digits" :error="form.errors.employee_number_digits">
                    <Select id="reglage-employee-digits" :model-value="String(employee.digits)" :options="range(options.employee_digits ?? [3, 8])" class="w-full" :disabled="readonly" @update:model-value="choose('employee_number_digits', $event, employeeDefaults.digits, true)">
                        <template #leading="{ option }"><NumberingOptionIcon kind="digits" :value="option.value" /></template>
                    </Select>
                </SettingsField>
            </div>
            <SettingsField label="Aperçu" description="Il suit le plus grand matricule de ce modèle, archives comprises ; à l’import, une ligne sans matricule reçoit le suivant.">
                <div class="flex max-w-2xl items-center justify-between gap-3 rounded-md border border-border px-4 py-2.5 text-sm" aria-label="Aperçu du matricule">
                    <span class="flex items-center gap-2 text-muted-foreground"><IdCard class="h-4 w-4 shrink-0" aria-hidden="true" />Prochain matricule proposé</span>
                    <span class="font-mono font-medium text-foreground">{{ employeeNumber }}</span>
                </div>
            </SettingsField>
        </div>

        <p class="flex items-start gap-2 rounded-md border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
            <Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />Seuls les nouveaux numéros suivent ces réglages. Un numéro qui existerait déjà est sauté, jamais redonné.
        </p>
    </SettingsSection>
</template>
