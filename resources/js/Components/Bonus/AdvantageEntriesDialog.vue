<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, HandCoins, Plus, Search, Stethoscope, Trash2, Zap } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';
import { monthLabel, shiftMonth } from '@/utilities/bonus';
import {
    amountInput,
    doctorTotals,
    entriesPayload,
    findArticle,
    grandTotals,
    isBlank,
    lineFromArticle,
    lineProblem,
} from '@/utilities/advantageEntries';

/**
 * ADR-227 — « Saisir des avantages », médecin par médecin : la liste des médecins à gauche
 * (personnes dont les avantages sont ouverts, jamais un nom libre), ses avantages à droite —
 * article ou motif, montant —, autant de lignes qu'il en faut, pour autant de médecins qu'on
 * veut. Les articles déjà saisis (ECHO 50 000, AUTO CHIR 70 000…) s'ajoutent d'un clic, au
 * dernier montant utilisé, toujours modifiable. Tout part d'un geste ; le serveur revalide chaque ligne.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    month: { type: String, required: true },
    currentMonth: { type: String, required: true },
    doctors: { type: Array, default: () => [] },
    /** Articles déjà saisis, proposés en un clic au dernier montant utilisé : `{ label, amount }`. */
    articles: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open']);

const period = ref(props.month);
const search = ref('');
const rows = ref({});
const selected = ref(null);
const tried = ref(false);
const form = useForm({ lines: [] });

watch(() => props.open, (open) => {
    if (! open) return;
    period.value = props.month;
    search.value = '';
    rows.value = {};
    tried.value = false;
    selected.value = props.doctors[0]?.uuid ?? null;
    form.clearErrors();
});

const linesOf = (uuid) => rows.value[uuid] ?? [];
const setLines = (uuid, lines) => {
    const copy = { ...rows.value };
    if (lines.length) copy[uuid] = lines; else delete copy[uuid];
    rows.value = copy;
};

const focusLast = async (field) => {
    await nextTick();
    const inputs = document.querySelectorAll(`[data-advantage-${field}]`);
    inputs[inputs.length - 1]?.focus();
};
const addLine = (uuid, line = { reason: '', amount: '' }) => {
    setLines(uuid, [...linesOf(uuid), line]);
    focusLast(line.reason ? 'amount' : 'reason');
};
const addArticle = (uuid, article) => addLine(uuid, lineFromArticle(article));
const removeLine = (uuid, index) => setLines(uuid, linesOf(uuid).filter((_, i) => i !== index));

/** Un motif tapé qui nomme un article connu reçoit son montant, si aucun n'est encore saisi. */
const completeAmount = (line) => {
    if (String(line.amount ?? '').trim() !== '') return;
    const article = findArticle(props.articles, line.reason);
    if (article?.amount) line.amount = amountInput(article.amount);
};

const withPeriod = computed(() => Object.fromEntries(
    Object.entries(rows.value).map(([uuid, lines]) => [uuid, lines.map((line) => ({ ...line, period: period.value }))]),
));
const totals = computed(() => grandTotals(withPeriod.value));
const payload = computed(() => entriesPayload(withPeriod.value));
const canSubmit = computed(() => payload.value !== null && payload.value.length > 0 && ! form.processing);
const missing = computed(() => {
    if (! totals.value.count) return 'Choisissez un médecin et ajoutez ses avantages.';
    if (payload.value === null) return 'Chaque avantage demande un article (motif) et un montant supérieur à zéro.';

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
const current = computed(() => props.doctors.find((doctor) => doctor.uuid === selected.value) ?? null);
const currentTotals = computed(() => doctorTotals(current.value ? linesOf(current.value.uuid) : []));

const serverError = (uuid, index) => {
    let position = -1;
    let counter = 0;
    for (const [employee, lines] of Object.entries(rows.value)) {
        for (let i = 0; i < lines.length; i += 1) {
            if (isBlank(lines[i])) continue;
            if (employee === uuid && i === index) position = counter;
            counter += 1;
        }
    }
    if (position < 0) return '';

    return form.errors[`lines.${position}.amount`] ?? form.errors[`lines.${position}.reason`] ?? form.errors[`lines.${position}.employee_uuid`] ?? '';
};
const lineError = (uuid, index, line) => serverError(uuid, index) || (tried.value && ! isBlank(line) ? lineProblem({ ...line, period: period.value }) : '') || '';
const doctorHasError = (uuid) => linesOf(uuid).some((line, index) => lineError(uuid, index, line));

const close = () => { if (! form.processing) emit('update:open', false); };
const submit = () => {
    tried.value = true;
    if (! canSubmit.value) {
        const faulty = Object.keys(rows.value).find((uuid) => doctorHasError(uuid));
        if (faulty) selected.value = faulty;

        return;
    }
    form.transform(() => ({ lines: payload.value })).post(hrUrl('/administration/bonus/avantages/saisis'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: () => {
            const faulty = Object.keys(rows.value).find((uuid) => doctorHasError(uuid));
            if (faulty) selected.value = faulty;
        },
    });
};
const firstError = computed(() => form.errors.lines ?? Object.values(form.errors)[0] ?? '');
</script>

<template>
    <Dialog
        :open="open"
        size="wide"
        title="Saisir des avantages"
        description="Choisissez un médecin, ajoutez ses avantages (article et montant), puis passez au suivant. Tout est enregistré d’un geste et rejoint la paie du mois choisi."
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
            </div>

            <p v-if="! doctors.length" class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                Aucun médecin en poste dont les avantages sont ouverts. Cochez « Avantages » dans son dossier, ou « Ouvre droit aux avantages » sur sa fonction.
            </p>

            <div v-else class="grid gap-4 lg:grid-cols-[19rem_minmax(0,1fr)]">
                <!-- Les médecins : un clic ouvre ses avantages ; ceux qui en ont portent leur total. -->
                <aside class="flex min-h-0 flex-col gap-2">
                    <IconInput v-model="search" :icon="Search" placeholder="Chercher un médecin" aria-label="Chercher un médecin" />
                    <ul class="max-h-[22rem] space-y-1 overflow-y-auto rounded-xl border border-border p-1 lg:max-h-[min(34rem,60vh)]" aria-label="Médecins">
                        <li v-for="doctor in shown" :key="doctor.uuid">
                            <button
                                type="button"
                                :aria-current="selected === doctor.uuid ? 'true' : undefined"
                                :class="cn(
                                    'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start transition-colors',
                                    selected === doctor.uuid ? 'bg-primary/10 ring-1 ring-primary/40' : 'hover:bg-muted',
                                )"
                                @click="selected = doctor.uuid"
                            >
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Stethoscope class="h-4 w-4" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-foreground">{{ doctor.name }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">{{ [doctor.job_title, doctor.employee_number].filter(Boolean).join(' · ') }}</span>
                                </span>
                                <span v-if="doctorTotals(linesOf(doctor.uuid)).count" class="shrink-0 text-end">
                                    <Badge :variant="doctorHasError(doctor.uuid) ? 'destructive' : 'secondary'" class="tabular-nums">{{ doctorTotals(linesOf(doctor.uuid)).count }}</Badge>
                                    <span class="mt-0.5 block text-[11px] font-semibold tabular-nums text-foreground">{{ formatMoney(doctorTotals(linesOf(doctor.uuid)).total) }}</span>
                                </span>
                            </button>
                        </li>
                        <li v-if="! shown.length" class="px-3 py-6 text-center text-sm text-muted-foreground">Aucun médecin ne correspond.</li>
                    </ul>
                </aside>

                <!-- Les avantages du médecin choisi. -->
                <section v-if="current" class="flex min-w-0 flex-col gap-3 rounded-xl border border-border bg-card p-4" :aria-label="`Avantages de ${current.name}`">
                    <header class="flex flex-wrap items-center gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Stethoscope class="h-5 w-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-base font-bold text-foreground">{{ current.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ [current.job_title, current.employee_number].filter(Boolean).join(' · ') }} · paie de {{ monthLabel(period) }}</p>
                        </div>
                        <span class="rounded-lg bg-primary/5 px-3 py-1.5 text-end">
                            <span class="block text-[11px] text-muted-foreground">{{ currentTotals.count }} avantage{{ currentTotals.count > 1 ? 's' : '' }}</span>
                            <span class="block text-sm font-bold tabular-nums text-primary">{{ formatMoney(currentTotals.total) }}</span>
                        </span>
                    </header>

                    <div v-if="articles.length">
                        <p class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><Zap class="h-3.5 w-3.5" />Ajouter en un clic</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="article in articles"
                                :key="article.label"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-3 py-1 text-xs font-medium text-foreground transition-colors hover:border-primary/50 hover:bg-primary/5"
                                :title="`Déjà saisi : ${article.label}, au dernier montant utilisé`"
                                @click="addArticle(current.uuid, article)"
                            >
                                <Plus class="h-3 w-3 text-muted-foreground" />{{ article.label }}
                                <span v-if="article.amount" class="tabular-nums text-muted-foreground">{{ formatMoney(article.amount) }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-border">
                        <div class="hidden grid-cols-[2rem_minmax(0,1fr)_11rem_2.5rem] gap-2 bg-muted/40 px-3 py-1.5 text-xs font-medium text-muted-foreground sm:grid">
                            <span>N°</span><span>Article ou motif</span><span class="text-end">Montant (Ar)</span><span class="sr-only">Retirer</span>
                        </div>
                        <p v-if="! linesOf(current.uuid).length" class="px-3 py-6 text-center text-sm text-muted-foreground">
                            Aucun avantage pour {{ current.name }}. Cliquez un article ci-dessus, ou « Ajouter une ligne ».
                        </p>
                        <ol v-else class="divide-y divide-border">
                            <li v-for="(line, index) in linesOf(current.uuid)" :key="index" class="grid grid-cols-[2rem_minmax(0,1fr)_2.5rem] items-start gap-2 px-3 py-2 sm:grid-cols-[2rem_minmax(0,1fr)_11rem_2.5rem]">
                                <span class="pt-2 text-xs font-semibold tabular-nums text-muted-foreground">{{ index + 1 }}</span>
                                <div class="min-w-0">
                                    <Input
                                        v-model="line.reason"
                                        data-advantage-reason
                                        list="advantage-entry-articles"
                                        maxlength="160"
                                        placeholder="Ex. ECHO, AUTO CHIR, CHOL"
                                        :aria-label="`Article de l’avantage ${index + 1} de ${current.name}`"
                                        @change="completeAmount(line)"
                                    />
                                    <p v-if="lineError(current.uuid, index, line)" class="mt-1 text-xs font-medium text-destructive">{{ lineError(current.uuid, index, line) }}</p>
                                </div>
                                <Input
                                    v-model="line.amount"
                                    data-advantage-amount
                                    inputmode="decimal"
                                    placeholder="0"
                                    :class="cn('col-start-2 text-end tabular-nums sm:col-start-auto', lineError(current.uuid, index, line) && 'border-destructive')"
                                    :aria-label="`Montant de l’avantage ${index + 1} de ${current.name}`"
                                    @keydown.enter.prevent="addLine(current.uuid)"
                                />
                                <Button type="button" variant="ghost" size="icon" class="row-start-1 text-destructive hover:text-destructive sm:row-start-auto" :aria-label="`Retirer l’avantage ${index + 1}`" @click="removeLine(current.uuid, index)"><Trash2 class="h-4 w-4" /></Button>
                            </li>
                        </ol>
                        <div class="flex items-center justify-between gap-3 border-t border-border bg-muted/20 px-3 py-2">
                            <Button type="button" size="sm" variant="outline" @click="addLine(current.uuid)"><Plus class="h-4 w-4" />Ajouter une ligne</Button>
                            <span class="text-sm">Total <strong class="tabular-nums text-foreground">{{ formatMoney(currentTotals.total) }}</strong></span>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">Entrée dans un montant ajoute une ligne. Le montant proposé par un article reste modifiable.</p>
                </section>
                <section v-else class="grid place-items-center rounded-xl border border-dashed border-border px-4 py-12 text-sm text-muted-foreground">
                    Choisissez un médecin dans la liste.
                </section>
            </div>

            <datalist id="advantage-entry-articles">
                <option v-for="article in articles" :key="article.label" :value="article.label" />
            </datalist>
            <p v-if="firstError" class="text-sm font-medium text-destructive">{{ firstError }}</p>
        </form>

        <template #footer>
            <p class="me-auto text-sm text-muted-foreground" aria-live="polite">
                <template v-if="totals.count && ! (tried && payload === null)">
                    <strong class="text-foreground">{{ totals.count }}</strong> avantage{{ totals.count > 1 ? 's' : '' }} ·
                    {{ totals.doctors }} médecin{{ totals.doctors > 1 ? 's' : '' }} ·
                    <strong class="tabular-nums text-foreground">{{ formatMoney(totals.total) }}</strong>
                </template>
                <template v-else>{{ missing }}</template>
            </p>
            <Button type="button" variant="outline" :disabled="form.processing" @click="close">Annuler</Button>
            <Button type="submit" form="advantage-entries-form" :disabled="! totals.count || form.processing">Enregistrer {{ totals.count ? `(${totals.count})` : '' }}</Button>
        </template>
    </Dialog>
</template>
