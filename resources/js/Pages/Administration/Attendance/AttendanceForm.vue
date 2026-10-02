<script setup>
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import { computed } from 'vue';
import Button from '@/Components/Shadcn/Button.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { CircleAlert, CircleCheck, Clock, LogIn, LogOut, Undo2 } from 'lucide-vue-next';
import { formatDate, toDatetimeLocalInput } from '@/utilities/date';
import HrEmployeePicker from '../Partials/HrEmployeePicker.vue';
import HrFormActions from '../Partials/HrFormActions.vue';
import HrFormSection from '../Partials/HrFormSection.vue';

const props = defineProps({ form: Object, employees: [Array, Object], cancelHref: String, submitLabel: String });
defineEmits(['submit']);

const selectedEmployee = computed(() => props.employees.find((employee) => employee.uuid === props.form.employee_uuid));
const workDate = computed(() => (props.form.started_at ? formatDate(props.form.started_at.slice(0, 10)) : 'Déduite de l’heure d’entrée'));
const minutes = computed(() => {
    if (!props.form.started_at || !props.form.ended_at) return null;

    return Math.round((new Date(props.form.ended_at) - new Date(props.form.started_at)) / 60000);
});
const duration = computed(() => (minutes.value > 0 ? `${Math.floor(minutes.value / 60)} h ${String(minutes.value % 60).padStart(2, '0')}` : null));

// ADR-235 — une présence est un fait constaté : le serveur refuse une heure à venir (10 min de marge).
// L'écran le dit avant l'envoi, sans rien bloquer de plus que le serveur.
const inFuture = (value) => Boolean(value) && new Date(value).getTime() > Date.now() + 10 * 60_000;
const warnings = computed(() => [
    inFuture(props.form.started_at) && 'L’heure d’entrée est dans le futur : une présence se saisit une fois constatée (le planning sert à prévoir).',
    inFuture(props.form.ended_at) && 'L’heure de sortie est dans le futur.',
    minutes.value !== null && minutes.value <= 0 && 'La sortie doit suivre l’entrée.',
].filter(Boolean));

const setNow = (field) => { props.form[field] = toDatetimeLocalInput(new Date()); props.form.clearErrors(field); };
</script>

<template>
    <form class="space-y-4" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <main class="space-y-4">
                <HrFormSection number="1" title="Identifier la personne" description="Une seule session est créée pour l’employé choisi.">
                    <HrEmployeePicker id="attendance_employee" v-model="form.employee_uuid" :employees="employees" required :error="form.errors.employee_uuid" />
                </HrFormSection>

                <HrFormSection number="2" title="Constater la session" description="La sortie est facultative : laissez-la vide tant que la personne est présente.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <div class="mb-1.5 flex h-6 items-center justify-between gap-2">
                                <span class="flex items-center gap-1.5 text-sm font-medium text-foreground"><LogIn class="h-3.5 w-3.5 text-muted-foreground" />Heure d’entrée<span class="text-destructive">*</span></span>
                                <Button type="button" variant="link" size="xs" class="h-6 px-0" @click="setNow('started_at')">Maintenant</Button>
                            </div>
                            <DateTimePicker id="attendance_started_at" v-model="form.started_at" required />
                            <p v-if="form.errors.started_at" class="mt-1 text-xs font-medium text-destructive">{{ form.errors.started_at }}</p>
                        </div>
                        <div>
                            <div class="mb-1.5 flex h-6 items-center justify-between gap-2">
                                <span class="flex items-center gap-1.5 text-sm font-medium text-foreground"><LogOut class="h-3.5 w-3.5 text-muted-foreground" />Heure de sortie</span>
                                <Button type="button" variant="link" size="xs" class="h-6 px-0" @click="setNow('ended_at')">Maintenant</Button>
                            </div>
                            <DateTimePicker id="attendance_ended_at" v-model="form.ended_at" />
                            <p v-if="form.errors.ended_at" class="mt-1 text-xs font-medium text-destructive">{{ form.errors.ended_at }}</p>
                        </div>
                    </div>
                    <ul v-if="warnings.length" class="mt-4 space-y-1.5">
                        <li v-for="warning in warnings" :key="warning" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                            <CircleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ warning }}
                        </li>
                    </ul>
                </HrFormSection>

                <HrFormSection title="Observation" description="Seulement pour une correction ou une particularité utile." icon="edit" optional>
                    <FormField label="Observation" :error="form.errors.observation">
                        <Textarea id="attendance_observation" v-model="form.observation" rows="4" placeholder="Ex. sortie non saisie le jour même, horaire corrigé selon le registre…" />
                    </FormField>
                </HrFormSection>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Aperçu de la session</p>
                            <h2 class="mt-1 text-base font-bold text-foreground">{{ selectedEmployee?.name || 'Employé à choisir' }}</h2>
                            <p v-if="selectedEmployee" class="mt-0.5 text-xs text-muted-foreground">{{ selectedEmployee.employee_number }} · {{ selectedEmployee.department || 'Sans département' }}</p>
                        </div>
                        <Badge :variant="form.ended_at ? 'success' : 'warning'">{{ form.ended_at ? 'Terminée' : 'En cours' }}</Badge>
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-muted/50 p-3"><dt class="text-[11px] font-bold uppercase text-muted-foreground">Date de travail</dt><dd class="mt-1 text-sm font-semibold text-foreground">{{ workDate }}</dd></div>
                        <div class="rounded-xl bg-muted/50 p-3"><dt class="flex items-center gap-1 text-[11px] font-bold uppercase text-muted-foreground"><Clock class="h-3 w-3" />Durée</dt><dd class="mt-1 text-sm font-semibold text-foreground">{{ duration || 'En attente de sortie' }}</dd></div>
                    </dl>
                </section>
                <section class="flex gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40"><CircleCheck class="h-4 w-4" /></span>
                    <div>
                        <h2 class="text-sm font-bold text-foreground">Ce que RIVO calcule</h2>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">La date de travail vient de l’heure d’entrée, la durée n’apparaît qu’avec la sortie. Aucun retard, absence ni heure supplémentaire n’est déduit.</p>
                    </div>
                </section>
            </aside>
        </div>
        <HrFormActions :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing">
            <Button v-if="form.ended_at" type="button" variant="outline" @click="form.ended_at = ''"><Undo2 class="h-4 w-4" />Laisser en cours</Button>
        </HrFormActions>
    </form>
</template>
