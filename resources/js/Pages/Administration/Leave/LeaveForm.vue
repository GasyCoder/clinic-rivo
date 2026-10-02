<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import {
    CalendarDays, CircleAlert, FileUp, Loader2, Lock, MapPin, Phone, ShieldCheck,
} from 'lucide-vue-next';
import { formatDate } from '@/utilities/date';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import HrEmployeePicker from '../Partials/HrEmployeePicker.vue';
import HrFormActions from '../Partials/HrFormActions.vue';
import HrFormSection from '../Partials/HrFormSection.vue';

const props = defineProps({
    form: Object,
    employees: [Array, Object],
    leaveTypes: [Array, Object],
    defaultRequestedOn: String,
    cancelHref: String,
    submitLabel: String,
});

defineEmits(['submit']);

const requester = computed(() => props.employees.find((employee) => employee.uuid === props.form.employee_uuid));
const interim = computed(() => props.employees.find((employee) => employee.uuid === props.form.interim_employee_uuid));
const selectedType = computed(() => props.leaveTypes.find((type) => type.uuid === props.form.leave_type_uuid));
const typeRules = computed(() => selectedType.value?.rules ?? {});
const preview = ref(null);
const previewLoading = ref(false);
const previewError = ref('');
let previewTimer;
let requestSequence = 0;

watch(() => props.form.employee_uuid, (uuid) => {
    if (props.form.interim_employee_uuid === uuid) props.form.interim_employee_uuid = '';
});

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const loadPreview = async () => {
    const required = [props.form.employee_uuid, props.form.leave_type_uuid, props.form.starts_on, props.form.returns_on];
    if (required.some((value) => !value)) {
        preview.value = null;
        previewError.value = '';
        return;
    }

    const sequence = ++requestSequence;
    previewLoading.value = true;
    previewError.value = '';

    try {
        const response = await fetch(hrUrl('/administration/leave/preview'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({
                employee_uuid: props.form.employee_uuid,
                leave_type_uuid: props.form.leave_type_uuid,
                starts_on: props.form.starts_on,
                returns_on: props.form.returns_on,
            }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? 'Le calcul n’a pas pu être effectué.';
            throw new Error(message);
        }
        if (sequence === requestSequence) preview.value = data.preview;
    } catch (error) {
        if (sequence === requestSequence) {
            preview.value = null;
            previewError.value = error.message;
        }
    } finally {
        if (sequence === requestSequence) previewLoading.value = false;
    }
};

watch(
    () => [props.form.employee_uuid, props.form.leave_type_uuid, props.form.starts_on, props.form.returns_on],
    () => {
        clearTimeout(previewTimer);
        preview.value = null;
        previewError.value = '';
        previewTimer = setTimeout(loadPreview, 250);
    },
);
onBeforeUnmount(() => clearTimeout(previewTimer));

const periodLabel = computed(() => props.form.starts_on && props.form.returns_on
    ? `Du ${formatDate(props.form.starts_on)} au ${formatDate(props.form.returns_on)}`
    : 'Période à compléter');
const typeOptions = computed(() => props.leaveTypes.map((type) => ({ value: type.uuid, label: type.label })));
const typeFacts = computed(() => (selectedType.value ? [
    { label: 'Décompte', value: typeRules.value.day_count_method === 'WEEKDAYS_INCLUSIVE' ? 'Lundi à vendredi' : 'Jours calendaires' },
    { label: 'Solde annuel', value: typeRules.value.consumes_annual_balance ? `${typeRules.value.annual_quota_days} jours` : 'Non consommé' },
    { label: 'Validation', value: typeRules.value.requires_approval ? 'Décision requise' : 'Automatique' },
    { label: 'Justificatif', value: typeRules.value.requires_attachment ? 'Obligatoire' : 'Facultatif' },
] : []));
const days = (value) => value === null || value === undefined ? '—' : `${Number(value).toLocaleString('fr-FR')} jour(s)`;
const requestedDate = computed(() => props.defaultRequestedOn
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(`${props.defaultRequestedOn}T00:00:00`))
    : 'Date du serveur');
const onFile = (event) => { props.form.justification = event.target.files?.[0] ?? null; };
</script>

<template>
    <form class="space-y-4" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_370px]">
            <main class="space-y-4">
                <HrFormSection number="1" title="Demandeur et type de demande" description="Le type choisi décide du calcul, du quota, du justificatif et de la validation.">
                    <div class="grid gap-5 lg:grid-cols-2">
                        <HrEmployeePicker id="leave_employee" v-model="form.employee_uuid" :employees="employees" label="Demandeur" required :error="form.errors.employee_uuid" />
                        <FormField label="Type de demande" required :error="form.errors.leave_type_uuid" as="div">
                            <Select v-model="form.leave_type_uuid" :options="typeOptions" placeholder="Choisir le type" aria-label="Type de demande" />
                        </FormField>
                    </div>
                    <dl v-if="typeFacts.length" class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <div v-for="fact in typeFacts" :key="fact.label" class="rounded-xl bg-muted/50 p-3 text-xs">
                            <dt class="text-muted-foreground">{{ fact.label }}</dt>
                            <dd class="mt-1 font-semibold text-foreground">{{ fact.value }}</dd>
                        </div>
                    </dl>
                </HrFormSection>

                <HrFormSection number="2" title="Période et calcul automatique" description="Renseignez seulement les dates : le serveur calcule la durée et les soldes depuis l’historique validé.">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-border bg-muted/40 px-4 py-3">
                            <span class="text-xs font-medium text-muted-foreground">Date de demande</span>
                            <strong class="mt-1 flex items-center gap-2 text-sm text-foreground"><Lock class="h-3.5 w-3.5 text-muted-foreground" />{{ requestedDate }}</strong>
                            <p class="mt-1 text-[11px] leading-4 text-muted-foreground">Posée par le serveur à l’enregistrement.</p>
                        </div>
                        <FormField label="Premier jour" required :error="form.errors.starts_on" as="div"><DatePicker id="leave_starts_on" v-model="form.starts_on" required /></FormField>
                        <FormField label="Dernier jour" required :error="form.errors.returns_on" as="div"><DatePicker id="leave_returns_on" v-model="form.returns_on" :min="form.starts_on || undefined" required /></FormField>
                    </div>

                    <div v-if="previewLoading" class="mt-4 flex items-center gap-3 rounded-xl border border-border bg-muted/40 p-4 text-sm text-muted-foreground"><Loader2 class="h-4 w-4 animate-spin" />Calcul des jours et du solde…</div>
                    <div v-else-if="previewError" class="mt-4 flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"><CircleAlert class="mt-0.5 h-4 w-4 shrink-0" /><p>{{ previewError }}</p></div>
                    <div v-else-if="preview" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-primary/30 bg-primary/5 p-4">
                            <span class="text-[11px] font-bold uppercase tracking-wide text-primary">Durée calculée</span>
                            <strong class="mt-2 block text-xl text-foreground">{{ days(preview.days_requested) }}</strong>
                            <small class="mt-1 block leading-4 text-muted-foreground">{{ preview.day_count_method_label }}</small>
                        </div>
                        <template v-if="preview.consumes_annual_balance">
                            <div class="rounded-xl border border-border p-4">
                                <span class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Solde avant</span>
                                <strong class="mt-2 block text-xl text-foreground">{{ days(preview.balance_before) }}</strong>
                                <small class="mt-1 block text-muted-foreground">Après {{ days(preview.approved_days) }} validé(s)</small>
                            </div>
                            <div class="rounded-xl border border-border p-4">
                                <span class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Autres demandes</span>
                                <strong class="mt-2 block text-xl text-foreground">{{ days(preview.pending_days) }}</strong>
                                <small class="mt-1 block text-muted-foreground">En attente, pas encore déduites</small>
                            </div>
                            <div :class="['rounded-xl border p-4', Number(preview.projected_balance) < 0 ? 'border-destructive/30 bg-destructive/5' : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/20']">
                                <span class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Solde prévisionnel</span>
                                <strong :class="['mt-2 block text-xl', Number(preview.projected_balance) < 0 ? 'text-destructive' : 'text-emerald-700 dark:text-emerald-300']">{{ days(preview.projected_balance) }}</strong>
                                <small class="mt-1 block text-muted-foreground">Si toutes les demandes sont acceptées</small>
                            </div>
                        </template>
                        <div v-else class="rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/20 sm:col-span-2 lg:col-span-3">
                            <span class="text-[11px] font-bold uppercase tracking-wide text-sky-700 dark:text-sky-300">Hors solde annuel</span>
                            <strong class="mt-2 block text-sm text-foreground">Cette demande ne déduit pas le quota annuel.</strong>
                            <p class="mt-1 text-xs leading-5 text-muted-foreground">La durée reste calculée et gardée dans l’historique.</p>
                        </div>
                    </div>
                </HrFormSection>

                <HrFormSection number="3" title="Motif et justificatif" description="Le motif accompagne la décision. Le justificatif suit la règle du type.">
                    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px]">
                        <FormField label="Motif" required :error="form.errors.reason">
                            <Textarea id="leave_reason" v-model="form.reason" rows="5" required placeholder="Décrivez clairement la demande" />
                        </FormField>
                        <FormField label="Justificatif" :required="Boolean(typeRules.requires_attachment)" :hint="typeRules.requires_attachment ? '' : 'facultatif'" :error="form.errors.justification" as="div">
                            <label for="leave_justification" :class="['flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed p-4 text-center transition hover:border-primary/50', form.justification ? 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-border bg-muted/30']">
                                <FileUp class="h-6 w-6 text-primary" />
                                <strong class="mt-2 max-w-full truncate text-xs text-foreground">{{ form.justification?.name || 'Choisir un fichier' }}</strong>
                                <small class="mt-1 text-[11px] leading-4 text-muted-foreground">PDF, image ou Word · 10 Mo au plus</small>
                            </label>
                            <input id="leave_justification" type="file" class="sr-only" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" :required="typeRules.requires_attachment" @change="onFile">
                        </FormField>
                    </div>
                </HrFormSection>

                <HrFormSection number="4" title="Continuité et contact" description="L’intérimaire et les coordonnées utiles, seulement si nécessaire." optional>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <HrEmployeePicker id="leave_interim" v-model="form.interim_employee_uuid" :employees="employees" label="Intérimaire" placeholder="Aucun intérimaire" :exclude-uuid="form.employee_uuid" :error="form.errors.interim_employee_uuid" />
                        <FormField label="Adresse pendant l’absence" :icon="MapPin" :error="form.errors.leave_address"><Input id="leave_address" v-model="form.leave_address" /></FormField>
                        <FormField label="Téléphone d’urgence" :icon="Phone" :error="form.errors.emergency_phone"><Input id="leave_emergency_phone" v-model="form.emergency_phone" type="tel" /></FormField>
                    </div>
                </HrFormSection>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <div class="flex items-start justify-between gap-3 border-b border-border bg-muted/40 px-5 py-4">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Aperçu de la demande</p>
                            <h2 class="mt-1 text-base font-bold text-foreground">{{ requester?.name || 'Demandeur à choisir' }}</h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ selectedType?.label || 'Type à choisir' }}</p>
                        </div>
                        <Badge :variant="typeRules.requires_approval === false ? 'success' : 'warning'">{{ typeRules.requires_approval === false ? 'Validée d’office' : 'À décider' }}</Badge>
                    </div>
                    <dl class="divide-y divide-border px-5 text-sm">
                        <div class="py-3">
                            <dt class="flex items-center gap-1.5 text-xs text-muted-foreground"><CalendarDays class="h-3.5 w-3.5" />Période</dt>
                            <dd class="mt-1 font-semibold text-foreground">{{ periodLabel }}</dd>
                            <dd class="text-xs text-muted-foreground">{{ preview ? days(preview.days_requested) : 'Calcul à venir' }}</dd>
                        </div>
                        <div class="py-3"><dt class="text-xs text-muted-foreground">Solde prévisionnel</dt><dd class="mt-1 font-semibold text-foreground">{{ preview?.consumes_annual_balance ? days(preview.projected_balance) : 'Non concerné' }}</dd></div>
                        <div class="py-3"><dt class="text-xs text-muted-foreground">Intérimaire</dt><dd class="mt-1 font-semibold text-foreground">{{ interim?.name || 'Non renseigné' }}</dd></div>
                    </dl>
                </section>
                <section class="flex gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40"><ShieldCheck class="h-4 w-4" /></span>
                    <div>
                        <h2 class="text-sm font-bold text-foreground">Calcul fait par le serveur</h2>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">L’aperçu aide à préparer la demande. À l’enregistrement puis à l’approbation, le serveur recalcule tout depuis l’historique.</p>
                    </div>
                </section>
            </aside>
        </div>
        <HrFormActions :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing" submit-icon="calendar" />
    </form>
</template>
