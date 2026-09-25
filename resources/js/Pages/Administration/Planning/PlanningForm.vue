<script setup>
import { computed, ref, watch } from 'vue';
import { Briefcase, Building2, CalendarClock, CalendarRange, Check, Clock, Info, Moon, Pencil, RefreshCw, ShieldPlus, Sun } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import TimeSelect from '@/Components/Shadcn/TimeSelect.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import HrEmployeePicker from '../Partials/HrEmployeePicker.vue';
import HrFormActions from '../Partials/HrFormActions.vue';
import HrFormSection from '../Partials/HrFormSection.vue';
import { cn } from '@/lib/cn';
import { PERIOD_LABELS, addDays, durationMinutes, endOnSameOrNextDay, formatDuration, shiftPeriod } from '@/utilities/planningCalendar';

/*
 * Un créneau du planning (ADR-066) — ADR-194 : du service (planning du
 * personnel) ou une garde. Le plus souvent sur une journée : un jour, une
 * heure de début, une heure de fin — une fin qui n'est pas après le début
 * tombe le lendemain (garde 19 h → 7 h). « Sur plusieurs jours » garde deux
 * dates et heures complètes. Aucune heure n'est proposée d'office.
 */
const props = defineProps({
    form: Object,
    employees: [Array, Object],
    departments: [Array, Object],
    kinds: { type: [Array, Object], default: () => [] },
    presetDate: { type: String, default: null },
    cancelHref: String,
    submitLabel: String,
});
defineEmits(['submit']);

// --- Type --------------------------------------------------------------------
const KIND_META = {
    SHIFT: { icon: Briefcase, hint: 'Planning du personnel : le service de la journée.', placeholder: 'Ex. Consultation, permanence, bloc' },
    ON_CALL: { icon: ShieldPlus, hint: 'Planning de garde : jour, nuit ou 24 h.', placeholder: 'Ex. Garde de nuit, garde du week-end' },
};

// --- Employé et département -------------------------------------------------------
const initialEmployee = props.employees.find((employee) => employee.uuid === props.form.employee_uuid);
const useEmployeeDepartment = ref(!props.form.department_uuid || props.form.department_uuid === initialEmployee?.department_uuid);
const selectedEmployee = computed(() => props.employees.find((employee) => employee.uuid === props.form.employee_uuid));
const departmentOptions = computed(() => props.departments.map((item) => ({ value: item.uuid, label: item.label })));
const appliedDepartment = computed(() => (useEmployeeDepartment.value
    ? selectedEmployee.value?.department || 'Aucun département au dossier'
    : props.departments.find((item) => item.uuid === props.form.department_uuid)?.label || 'Département à choisir'));
const chooseEmployeeDepartment = () => {
    useEmployeeDepartment.value = true;
    props.form.department_uuid = '';
    props.form.clearErrors('department_uuid');
};

// --- Période -----------------------------------------------------------------------
const split = (value) => (value ? { day: String(value).slice(0, 10), time: String(value).slice(11, 16) } : { day: '', time: '' });
const start = split(props.form.starts_at);
const end = split(props.form.ends_at);
const spansDays = start.day && end.day && end.day > addDays(start.day, 1);
const mode = ref(spansDays ? 'multi' : 'day');
const day = ref(start.day || props.presetDate || '');
const startTime = ref(start.time);
const endTime = ref(end.time);

watch([mode, day, startTime, endTime], () => {
    if (mode.value !== 'day') return;
    props.form.starts_at = day.value && startTime.value ? `${day.value}T${startTime.value}` : '';
    props.form.ends_at = endOnSameOrNextDay(day.value, startTime.value, endTime.value) ?? '';
}, { immediate: true });
const switchMode = (next) => {
    if (next === 'day') {
        const s = split(props.form.starts_at);
        const e = split(props.form.ends_at);
        day.value = s.day || day.value;
        startTime.value = s.time;
        endTime.value = e.time;
    }
    mode.value = next;
};

const endsNextDay = computed(() => mode.value === 'day' && startTime.value && endTime.value && endTime.value <= startTime.value);
const preview = computed(() => (props.form.starts_at && props.form.ends_at ? { starts_at: props.form.starts_at, ends_at: props.form.ends_at } : null));
const previewPeriod = computed(() => (preview.value ? shiftPeriod(preview.value) : null));
const previewDuration = computed(() => (preview.value ? durationMinutes(preview.value) : 0));
const fmt = (value) => new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })
    .format(new Date(`${String(value).slice(0, 16)}:00Z`));
const PERIOD_ICONS = { DAY: Sun, NIGHT: Moon, LONG: Clock };
</script>

<template>
    <form class="space-y-4" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
            <main class="space-y-4">
                <HrFormSection number="1" title="Quel planning ?" description="Le service de la journée, ou une garde.">
                    <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Type de créneau">
                        <button
                            v-for="kind in kinds"
                            :key="kind.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.kind === kind.value"
                            :class="cn('flex items-start gap-3 rounded-xl border p-4 text-start transition',
                                form.kind === kind.value
                                    ? kind.value === 'ON_CALL' ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-200 dark:bg-violet-950/30 dark:ring-violet-900' : 'border-sky-500 bg-sky-50 ring-2 ring-sky-200 dark:bg-sky-950/30 dark:ring-sky-900'
                                    : 'border-border hover:border-primary/40')"
                            @click="form.kind = kind.value"
                        >
                            <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                                form.kind === kind.value ? (kind.value === 'ON_CALL' ? 'bg-violet-600 text-white' : 'bg-sky-600 text-white') : 'bg-muted text-muted-foreground')"
                            ><component :is="KIND_META[kind.value]?.icon" class="h-4 w-4" /></span>
                            <span><span class="block text-sm font-semibold text-foreground">{{ kind.label }}</span><span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ KIND_META[kind.value]?.hint }}</span></span>
                        </button>
                    </div>
                    <p v-if="form.errors.kind" class="mt-2 text-xs text-destructive">{{ form.errors.kind }}</p>
                </HrFormSection>

                <HrFormSection number="2" title="Qui, et dans quel service ?" description="Le département du dossier est repris ; changez-le seulement pour ce créneau.">
                    <div class="space-y-4">
                        <HrEmployeePicker id="planning_employee" v-model="form.employee_uuid" :employees="employees" label="Personne planifiée" required :error="form.errors.employee_uuid" />
                        <div class="grid gap-3 md:grid-cols-2">
                            <button type="button" :class="cn('flex items-start gap-3 rounded-xl border p-3.5 text-start transition', useEmployeeDepartment ? 'border-primary bg-primary/5 ring-1 ring-ring/25' : 'border-border hover:border-primary/40')" @click="chooseEmployeeDepartment">
                                <RefreshCw class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                <span><span class="block text-sm font-semibold text-foreground">Département du dossier</span><span class="text-xs text-muted-foreground">{{ selectedEmployee?.department || 'Choisissez d’abord la personne' }}</span></span>
                            </button>
                            <button type="button" :class="cn('flex items-start gap-3 rounded-xl border p-3.5 text-start transition', !useEmployeeDepartment ? 'border-primary bg-primary/5 ring-1 ring-ring/25' : 'border-border hover:border-primary/40')" @click="useEmployeeDepartment = false">
                                <Pencil class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                <span><span class="block text-sm font-semibold text-foreground">Un autre département</span><span class="text-xs text-muted-foreground">Pour ce créneau seulement.</span></span>
                            </button>
                        </div>
                        <FormField v-if="!useEmployeeDepartment" as="div" label="Département du créneau" required :error="form.errors.department_uuid">
                            <Select id="planning_department" v-model="form.department_uuid" :options="departmentOptions" :icon="Building2" placeholder="Choisir un département" class="w-full min-w-0" aria-label="Département du créneau" />
                        </FormField>
                    </div>
                </HrFormSection>

                <HrFormSection number="3" title="Quand ?" description="Une fin qui n’est pas après le début tombe le lendemain.">
                    <div class="mb-4 inline-flex rounded-lg bg-muted p-0.5" role="tablist" aria-label="Durée">
                        <button type="button" role="tab" :aria-selected="mode === 'day'" :class="cn('inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition', mode === 'day' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground')" @click="switchMode('day')"><CalendarClock class="h-3.5 w-3.5" />Sur une journée</button>
                        <button type="button" role="tab" :aria-selected="mode === 'multi'" :class="cn('inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition', mode === 'multi' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground')" @click="switchMode('multi')"><CalendarRange class="h-3.5 w-3.5" />Sur plusieurs jours</button>
                    </div>
                    <div v-if="mode === 'day'" class="grid gap-4 md:grid-cols-3">
                        <FormField as="div" label="Jour" required :error="form.errors.starts_at"><DatePicker id="planning_day" v-model="day" format="long" /></FormField>
                        <FormField as="div" label="Début" required><TimeSelect id="planning_start" v-model="startTime" aria-label="Heure de début" /></FormField>
                        <FormField as="div" label="Fin" required :error="form.errors.ends_at">
                            <TimeSelect id="planning_end" v-model="endTime" aria-label="Heure de fin" />
                            <p v-if="endsNextDay" class="mt-1.5 flex items-center gap-1 text-xs font-medium text-violet-700 dark:text-violet-300"><Moon class="h-3.5 w-3.5" />Se termine le lendemain</p>
                        </FormField>
                    </div>
                    <div v-else class="grid gap-4 md:grid-cols-2">
                        <FormField as="div" label="Début" required :error="form.errors.starts_at"><DateTimePicker id="planning_starts_at" v-model="form.starts_at" required /></FormField>
                        <FormField as="div" label="Fin" required :error="form.errors.ends_at"><DateTimePicker id="planning_ends_at" v-model="form.ends_at" required /></FormField>
                    </div>
                </HrFormSection>

                <HrFormSection :icon="Pencil" title="Objet et consignes" description="Ce que la personne fait sur ce créneau, et ce qu’elle doit savoir." optional>
                    <div class="space-y-4">
                        <FormField label="Objet" :error="form.errors.title"><Input id="planning_title" v-model="form.title" maxlength="255" :placeholder="KIND_META[form.kind]?.placeholder" /></FormField>
                        <FormField label="Observation" :error="form.errors.observation"><Textarea id="planning_observation" v-model="form.observation" rows="3" maxlength="5000" placeholder="Consignes, joignable au…" /></FormField>
                    </div>
                </HrFormSection>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <div class="border-b border-border bg-muted/40 px-5 py-4">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Aperçu</p>
                        <div class="mt-2 flex items-center gap-3">
                            <EmployeePhoto :src="selectedEmployee?.photo_url" :name="selectedEmployee?.name ?? ''" size="md" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-foreground">{{ selectedEmployee?.name || 'Personne à choisir' }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ selectedEmployee?.job_title || selectedEmployee?.employee_number || '—' }}</p>
                            </div>
                        </div>
                    </div>
                    <dl class="divide-y divide-border px-5 text-sm">
                        <div class="py-3"><dt class="text-xs text-muted-foreground">Planning</dt><dd class="mt-1"><Badge :variant="form.kind === 'ON_CALL' ? 'secondary' : 'outline'"><component :is="KIND_META[form.kind]?.icon" class="h-3.5 w-3.5" />{{ kinds.find((kind) => kind.value === form.kind)?.planning }}</Badge></dd></div>
                        <div class="py-3"><dt class="text-xs text-muted-foreground">Département</dt><dd class="mt-1 font-semibold text-foreground">{{ appliedDepartment }}</dd></div>
                        <div class="py-3">
                            <dt class="text-xs text-muted-foreground">Période</dt>
                            <dd v-if="preview" class="mt-1 space-y-1">
                                <p class="font-semibold text-foreground">{{ fmt(preview.starts_at) }} → {{ fmt(preview.ends_at) }}</p>
                                <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><component :is="PERIOD_ICONS[previewPeriod]" class="h-3.5 w-3.5" />{{ PERIOD_LABELS[previewPeriod] }} · {{ formatDuration(previewDuration) }}</p>
                            </dd>
                            <dd v-else class="mt-1 text-muted-foreground">Jour et heures à choisir</dd>
                        </div>
                    </dl>
                </section>
                <section class="flex gap-3 rounded-2xl border border-border bg-card p-4 text-xs leading-5 text-muted-foreground shadow-sm">
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />Le planning organise le service. Il ne crée ni absence, ni retard, ni heure supplémentaire, et un chevauchement reste visible sans être bloqué.
                </section>
            </aside>
        </div>

        <HrFormActions :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing" :submit-icon="Check" />
    </form>
</template>
