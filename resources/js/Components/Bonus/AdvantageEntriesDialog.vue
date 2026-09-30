<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, HandCoins, Plus, Search, Stethoscope, Trash2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';
import { monthLabel, shiftMonth } from '@/utilities/bonus';
import { doctorTotals, entriesPayload, grandTotals, isBlank, lineProblem } from '@/utilities/advantageEntries';

/**
 * ADR-227 — « Saisir des avantages » : la liste des médecins (personnes dont les avantages
 * sont ouverts, jamais un nom libre), plusieurs lignes par médecin, compteurs et totaux en
 * direct. Tout part d'un geste ; le serveur revalide chaque ligne.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    month: { type: String, required: true },
    currentMonth: { type: String, required: true },
    doctors: { type: Array, default: () => [] },
    reasons: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open']);

const period = ref(props.month);
const search = ref('');
const rows = ref({});
const tried = ref(false);
const form = useForm({ lines: [] });

watch(() => props.open, (open) => {
    if (! open) return;
    period.value = props.month;
    search.value = '';
    rows.value = {};
    tried.value = false;
    form.clearErrors();
});

const blankLine = () => ({ amount: '', reason: '' });
const linesOf = (uuid) => rows.value[uuid] ?? [];
const addLine = (uuid) => { rows.value = { ...rows.value, [uuid]: [...linesOf(uuid), blankLine()] }; };
const removeLine = (uuid, index) => {
    const next = linesOf(uuid).filter((_, i) => i !== index);
    const copy = { ...rows.value };
    if (next.length) copy[uuid] = next; else delete copy[uuid];
    rows.value = copy;
};

const withPeriod = computed(() => Object.fromEntries(
    Object.entries(rows.value).map(([uuid, lines]) => [uuid, lines.map((line) => ({ ...line, period: period.value }))]),
));
const totals = computed(() => grandTotals(withPeriod.value));
const payload = computed(() => entriesPayload(withPeriod.value));
const canSubmit = computed(() => payload.value !== null && payload.value.length > 0 && ! form.processing);
const missing = computed(() => {
    if (! totals.value.count) return 'Ajoutez au moins un avantage.';
    if (payload.value === null) return 'Chaque avantage demande un montant supérieur à zéro et un motif.';

    return '';
});

const normalize = (text) => String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const shown = computed(() => {
    const words = normalize(search.value).split(/\s+/).filter(Boolean);
    if (! words.length) return props.doctors;

    return props.doctors.filter((doctor) => {
        const haystack = normalize([doctor.name, doctor.employee_number, doctor.job_title].join(' '));

        return words.every((word) => haystack.includes(word)) || linesOf(doctor.uuid).length;
    });
});

const serverError = (uuid, index) => {
    const flat = payload.value ?? [];
    let position = -1;
    let counter = 0;
    for (const [employee, lines] of Object.entries(rows.value)) {
        for (let i = 0; i < lines.length; i += 1) {
            if (isBlank(lines[i])) continue;
            if (employee === uuid && i === index) position = counter;
            counter += 1;
        }
    }
    if (position < 0 || ! flat.length) return '';

    return form.errors[`lines.${position}.amount`] ?? form.errors[`lines.${position}.reason`] ?? form.errors[`lines.${position}.employee_uuid`] ?? '';
};
const lineError = (uuid, index, line) => serverError(uuid, index) || (tried.value && ! isBlank(line) ? lineProblem({ ...line, period: period.value }) : '') || '';

const close = () => { if (! form.processing) emit('update:open', false); };
const submit = () => {
    tried.value = true;
    if (! canSubmit.value) return;
    form.transform(() => ({ lines: payload.value })).post(hrUrl('/administration/bonus/avantages/saisis'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
};
const firstError = computed(() => form.errors.lines ?? Object.values(form.errors)[0] ?? '');
</script>

<template>
    <Dialog
        :open="open"
        size="xl"
        title="Saisir des avantages"
        description="Choisissez un médecin, ajoutez ses avantages (montant et motif) : ils rejoignent sa paie du mois choisi."
        :dismissible="false"
        @update:open="(value) => value || close()"
    >
        <template #icon><HandCoins class="h-5 w-5" /></template>

        <form id="advantage-entries-form" class="grid gap-4" @submit.prevent="submit">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1 rounded-xl border border-border bg-card p-1 shadow-sm" role="group" aria-label="Mois de paie">
                    <Button type="button" variant="ghost" size="icon" aria-label="Mois précédent" @click="period = shiftMonth(period, -1)"><ChevronLeft class="h-4 w-4" /></Button>
                    <span class="flex min-w-40 items-center justify-center gap-2 px-2 text-sm font-semibold capitalize text-foreground">
                        <CalendarDays class="h-4 w-4 text-muted-foreground" />Paie de {{ monthLabel(period) }}
                    </span>
                    <Button type="button" variant="ghost" size="icon" aria-label="Mois suivant" :disabled="period >= shiftMonth(currentMonth, 1)" @click="period = shiftMonth(period, 1)"><ChevronRight class="h-4 w-4" /></Button>
                </div>
                <IconInput v-model="search" :icon="Search" class="min-w-52 flex-1" placeholder="Chercher un médecin (nom, matricule)" aria-label="Chercher un médecin" />
            </div>

            <p v-if="! doctors.length" class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                Aucun médecin en poste dont les avantages sont ouverts. Cochez « Avantages » dans son dossier, ou « Ouvre droit aux avantages » sur sa fonction.
            </p>

            <datalist id="advantage-entry-reasons">
                <option v-for="reason in reasons" :key="reason" :value="reason" />
            </datalist>

            <ul class="divide-y divide-border rounded-xl border border-border">
                <li v-for="doctor in shown" :key="doctor.uuid" class="space-y-2 px-3 py-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Stethoscope class="h-4 w-4" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-foreground">{{ doctor.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ [doctor.employee_number, doctor.job_title].filter(Boolean).join(' · ') }}</p>
                        </div>
                        <Badge v-if="doctorTotals(linesOf(doctor.uuid)).count" variant="outline" class="tabular-nums">
                            {{ doctorTotals(linesOf(doctor.uuid)).count }} · {{ formatMoney(doctorTotals(linesOf(doctor.uuid)).total) }}
                        </Badge>
                        <Button type="button" size="sm" variant="outline" @click="addLine(doctor.uuid)"><Plus class="h-4 w-4" />Ajouter un avantage</Button>
                    </div>

                    <div v-for="(line, index) in linesOf(doctor.uuid)" :key="index" class="ms-11 grid gap-2 sm:grid-cols-[10rem_1fr_auto]">
                        <div>
                            <Input v-model="line.amount" inputmode="decimal" placeholder="Montant (Ar)" :aria-label="`Montant de l’avantage ${index + 1} de ${doctor.name}`" :class="cn(lineError(doctor.uuid, index, line) && 'border-destructive')" />
                        </div>
                        <div>
                            <Input v-model="line.reason" list="advantage-entry-reasons" maxlength="160" placeholder="Motif (ex. ECHO)" :aria-label="`Motif de l’avantage ${index + 1} de ${doctor.name}`" />
                            <p v-if="lineError(doctor.uuid, index, line)" class="mt-1 text-xs font-medium text-destructive">{{ lineError(doctor.uuid, index, line) }}</p>
                        </div>
                        <Button type="button" variant="ghost" size="icon" class="text-destructive hover:text-destructive" :aria-label="`Retirer l’avantage ${index + 1}`" @click="removeLine(doctor.uuid, index)"><Trash2 class="h-4 w-4" /></Button>
                    </div>
                </li>
                <li v-if="doctors.length && ! shown.length" class="px-3 py-6 text-center text-sm text-muted-foreground">Aucun médecin ne correspond.</li>
            </ul>
            <p v-if="firstError" class="text-sm font-medium text-destructive">{{ firstError }}</p>
        </form>

        <template #footer>
            <p class="me-auto text-sm text-muted-foreground" aria-live="polite">
                <template v-if="totals.count">
                    <strong class="text-foreground">{{ totals.count }}</strong> avantage{{ totals.count > 1 ? 's' : '' }} ·
                    {{ totals.doctors }} médecin{{ totals.doctors > 1 ? 's' : '' }} ·
                    <strong class="tabular-nums text-foreground">{{ formatMoney(totals.total) }}</strong>
                </template>
                <template v-else>{{ missing }}</template>
            </p>
            <Button type="button" variant="outline" :disabled="form.processing" @click="close">Annuler</Button>
            <Button type="submit" form="advantage-entries-form" :disabled="! totals.count || form.processing">Enregistrer</Button>
        </template>
    </Dialog>
</template>
