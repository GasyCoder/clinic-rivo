<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, CalendarClock, CalendarRange, Coins, GraduationCap, HandCoins, Landmark, Lock, Percent, Plus, Save, Scale,
    Settings2, Timer, Trash2, Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { staffDebtContext, staffDebtUrl } from '@/utilities/staffDebtUrl';
import { fromMinor, interestFor, tierLabel, toMinor } from '@/utilities/staffDebts';

/**
 * ADR-229 — les réglages des dettes du personnel d'un site : ouvrir ou fermer les
 * demandes, les montants possibles, la durée, la part du salaire que les mensualités
 * peuvent prendre, les dettes en cours par personne, l'ancienneté, les stagiaires, et
 * l'intérêt par tranche de montant (fixe ou en pourcentage). Une valeur vide ne pose
 * aucune limite. Les nouvelles règles valent pour les demandes et décisions à venir :
 * aucune dette accordée n'est recalculée. Le site revérifie tout et l'audite.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    settings: { type: Object, required: true },
    updated: { type: Object, default: null },
    limits: { type: Object, default: () => ({ max_tiers: 20 }) },
});

const siteName = computed(() => staffDebtContext()?.site?.name ?? '');
const plain = (value) => (value === null || value === undefined ? '' : String(value).replace(/\.00$/, ''));

const form = useForm({
    requests_open: props.settings.requests_open ?? true,
    closed_message: props.settings.closed_message ?? '',
    min_amount: plain(props.settings.min_amount),
    max_amount: plain(props.settings.max_amount),
    max_months: plain(props.settings.max_months),
    max_salary_share: plain(props.settings.max_salary_share),
    max_open_debts: plain(props.settings.max_open_debts),
    min_seniority_months: plain(props.settings.min_seniority_months),
    exclude_interns: props.settings.exclude_interns ?? false,
    penalty_rate: plain(props.settings.penalty_rate),
    penalty_grace_days: plain(props.settings.penalty_grace_days),
    penalty_cap_rate: plain(props.settings.penalty_cap_rate),
    interest_tiers: (props.settings.interest_tiers ?? []).map((tier) => ({ from: plain(tier.from), to: plain(tier.to), mode: tier.mode, value: plain(tier.value) })),
});

const MODES = [
    { value: 'PERCENT', label: '% du montant' },
    { value: 'FIXED', label: 'Montant fixe (Ar)' },
];

const addTier = () => {
    const last = form.interest_tiers[form.interest_tiers.length - 1];
    const from = last?.to && toMinor(last.to) !== null ? plain(fromMinor(toMinor(last.to) + 100)) : (last ? '' : (form.min_amount || '0'));
    form.interest_tiers.push({ from, to: '', mode: 'PERCENT', value: '' });
};
const removeTier = (index) => form.interest_tiers.splice(index, 1);

// Un montant d'essai : l'intérêt et le total qu'il porterait avec les tranches saisies.
const sample = ref('1000000');
const sampleInterest = computed(() => interestFor(sample.value, form.interest_tiers.filter((tier) => tier.from !== '' && tier.value !== '')));
const sampleTotal = computed(() => {
    const amount = toMinor(sample.value);
    if (! amount) return null;

    return fromMinor(amount + (sampleInterest.value ? toMinor(sampleInterest.value.amount) : 0));
});

// ADR-230 — la pénalité qu'un remboursement en espèces en retard porterait, sur un exemple.
const penaltyExample = computed(() => {
    const rate = toMinor(form.penalty_rate);
    if (! rate) return null;
    const base = 10000000; // 100 000 Ar en retard
    const monthly = Math.round((base * rate) / 10000);

    return { base: fromMinor(base), monthly: fromMinor(monthly) };
});

const tierError = (index, field) => form.errors[`interest_tiers.${index}.${field}`] ?? null;
const submit = () => form
    .transform((data) => ({ ...data, interest_tiers: data.interest_tiers.map((tier) => ({ ...tier, to: tier.to === '' ? null : tier.to })) }))
    .put(staffDebtUrl('/finance/dettes/reglages'), { preserveScroll: true });

const LIMITS = [
    { key: 'max_months', label: 'Durée maximale', suffix: 'mois', icon: CalendarRange, help: 'La dette se rembourse en ce nombre de mois au plus, intérêt compris.' },
    { key: 'max_salary_share', label: 'Part du salaire', suffix: '%', icon: Wallet, help: 'Les mensualités de toutes ses dettes ne dépassent pas cette part du salaire déclaré.' },
    { key: 'max_open_debts', label: 'Dettes en cours par personne', suffix: 'au plus', icon: Landmark, help: 'Accordées ou en remboursement, à la fois.' },
    { key: 'min_seniority_months', label: 'Ancienneté minimale', suffix: 'mois', icon: CalendarClock, help: 'Depuis la date d’entrée de sa fiche.' },
];
</script>

<template>
    <Head title="Réglages des dettes du personnel" />
    <form class="w-full space-y-5" @submit.prevent="submit">
        <div class="flex flex-wrap items-center gap-3">
            <Button :as="Link" :href="staffDebtUrl('/finance/dettes')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" />Dettes du personnel</Button>
        </div>

        <PageHeader
            :eyebrow="`Finance · ${siteName}`"
            title="Réglages des dettes du personnel"
            description="Ce que le personnel de ce site peut demander, et l’intérêt de chaque tranche de montant. Une valeur vide ne pose aucune limite. Les dettes déjà accordées ne changent pas."
            :icon="Settings2"
        >
            <template #actions>
                <Button type="submit" :disabled="form.processing || ! form.isDirty"><Save class="h-4 w-4" />Enregistrer</Button>
            </template>
        </PageHeader>

        <p v-if="updated" class="text-xs text-muted-foreground">Réglé le {{ formatDateTime(updated.at) }}<template v-if="updated.by"> par {{ updated.by }}</template>.</p>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 space-y-5">
                <Card class="space-y-4 px-5 py-4">
                    <div class="flex items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><HandCoins class="h-4 w-4" /></span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-foreground">Demandes</h2>
                            <p class="text-xs text-muted-foreground">Fermées, personne ne peut en faire une nouvelle ; les dettes en cours continuent.</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm font-medium text-foreground">
                            <Switch v-model="form.requests_open" aria-label="Demandes ouvertes" />{{ form.requests_open ? 'Ouvertes' : 'Fermées' }}
                        </label>
                    </div>
                    <FormField v-if="! form.requests_open" label="Message affiché au personnel" :icon="Lock" hint="(facultatif)" :error="form.errors.closed_message">
                        <Textarea v-model="form.closed_message" :rows="2" maxlength="500" placeholder="Ex. Les demandes reprennent en janvier." />
                    </FormField>
                    <label class="flex items-start gap-3 rounded-xl border border-border px-3 py-2.5 text-sm">
                        <Switch v-model="form.exclude_interns" class="mt-0.5" aria-label="Exclure les stagiaires" />
                        <span>
                            <span class="flex items-center gap-1.5 font-semibold text-foreground"><GraduationCap class="h-4 w-4 text-muted-foreground" />Les stagiaires ne demandent pas de dette</span>
                            <span class="block text-xs text-muted-foreground">Un stagiaire est une personne dont le contrat en cours est un stage.</span>
                        </span>
                    </label>
                </Card>

                <Card class="space-y-4 px-5 py-4">
                    <div class="flex items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Scale class="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-foreground">Limites</h2>
                            <p class="text-xs text-muted-foreground">Le personnel ne peut pas les dépasser. Le DG le peut, par dérogation écrite sur la dette.</p>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Montant minimum" :icon="Coins" :error="form.errors.min_amount">
                            <div class="relative">
                                <IconInput v-model="form.min_amount" :icon="Coins" inputmode="decimal" placeholder="Ex. 50000" class="pe-10 tabular-nums" />
                                <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Ar</span>
                            </div>
                        </FormField>
                        <FormField label="Montant maximum" :icon="Coins" :error="form.errors.max_amount">
                            <div class="relative">
                                <IconInput v-model="form.max_amount" :icon="Coins" inputmode="decimal" placeholder="Ex. 10000000" class="pe-10 tabular-nums" />
                                <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Ar</span>
                            </div>
                        </FormField>
                        <FormField v-for="limit in LIMITS" :key="limit.key" :label="limit.label" :icon="limit.icon" :error="form.errors[limit.key]">
                            <div class="relative">
                                <IconInput v-model="form[limit.key]" :icon="limit.icon" type="number" min="1" inputmode="numeric" placeholder="Sans limite" class="pe-16 tabular-nums" />
                                <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">{{ limit.suffix }}</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">{{ limit.help }}</p>
                        </FormField>
                    </div>
                </Card>

                <Card class="space-y-4 px-5 py-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Percent class="h-4 w-4" /></span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-foreground">Intérêt par tranche de montant</h2>
                            <p class="text-xs text-muted-foreground">Un pourcentage du montant emprunté, ou un montant fixe. Ajouté une fois, remboursé avec la dette ; figé à l’accord, le DG peut le remettre. Sans tranche, aucun intérêt.</p>
                        </div>
                        <Button type="button" size="sm" variant="outline" :disabled="form.interest_tiers.length >= limits.max_tiers" @click="addTier"><Plus class="h-4 w-4" />Ajouter une tranche</Button>
                    </div>

                    <p v-if="form.errors.interest_tiers" class="text-sm font-medium text-destructive">{{ form.errors.interest_tiers }}</p>

                    <div v-if="form.interest_tiers.length" class="space-y-3">
                        <div v-for="(tier, index) in form.interest_tiers" :key="index" class="grid gap-3 rounded-xl border border-border bg-muted/20 p-3 sm:grid-cols-[1fr_1fr_1.2fr_1fr_auto] sm:items-end">
                            <FormField :label="`De (tranche ${index + 1})`" :error="tierError(index, 'from')">
                                <IconInput v-model="tier.from" :icon="Coins" inputmode="decimal" placeholder="0" class="tabular-nums" />
                            </FormField>
                            <FormField label="À" hint="(vide : sans plafond)" :error="tierError(index, 'to')">
                                <IconInput v-model="tier.to" :icon="Coins" inputmode="decimal" placeholder="Exigé avec un taux" class="tabular-nums" />
                            </FormField>
                            <FormField label="Intérêt" :error="tierError(index, 'mode')">
                                <Select v-model="tier.mode" :options="MODES" />
                            </FormField>
                            <FormField :label="tier.mode === 'PERCENT' ? 'Taux' : 'Montant'" :error="tierError(index, 'value')">
                                <div class="relative">
                                    <IconInput v-model="tier.value" :icon="tier.mode === 'PERCENT' ? Percent : Coins" inputmode="decimal" :placeholder="tier.mode === 'PERCENT' ? 'Ex. 5' : 'Ex. 30000'" class="pe-9 tabular-nums" />
                                    <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">{{ tier.mode === 'PERCENT' ? '%' : 'Ar' }}</span>
                                </div>
                            </FormField>
                            <Button type="button" variant="ghost" size="icon" class="text-destructive hover:text-destructive" :aria-label="`Retirer la tranche ${index + 1}`" @click="removeTier(index)"><Trash2 class="h-4 w-4" /></Button>
                        </div>
                    </div>
                    <p v-else class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">Aucune tranche : les dettes de ce site sont sans intérêt.</p>
                </Card>

                <!-- ADR-230 — la pénalité de retard d'un remboursement en espèces. -->
                <Card class="space-y-4 px-5 py-4">
                    <div class="flex items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Timer class="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-foreground">Pénalité de retard</h2>
                            <p class="text-xs text-muted-foreground">Seulement pour un remboursement en espèces : une mensualité non payée à la Caisse après le délai porte cette pénalité, une fois par mois, sur le montant en retard. Figée sur la dette à l’accord ; le DG peut l’écarter à l’accord ou remettre une pénalité. Vide : aucune pénalité.</p>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField label="Taux par mois" :icon="Percent" :error="form.errors.penalty_rate">
                            <div class="relative">
                                <IconInput v-model="form.penalty_rate" :icon="Percent" inputmode="decimal" placeholder="Aucune" class="pe-9 tabular-nums" />
                                <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">%</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">10 % au plus.</p>
                        </FormField>
                        <FormField label="Délai de grâce" :icon="CalendarClock" :error="form.errors.penalty_grace_days">
                            <div class="relative">
                                <IconInput v-model="form.penalty_grace_days" :icon="CalendarClock" type="number" min="0" max="60" inputmode="numeric" placeholder="0" class="pe-14 tabular-nums" />
                                <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">jours</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">Après la fin du mois dû.</p>
                        </FormField>
                        <FormField label="Plafond" :icon="Scale" :error="form.errors.penalty_cap_rate">
                            <div class="relative">
                                <IconInput v-model="form.penalty_cap_rate" :icon="Scale" inputmode="decimal" placeholder="Exigé avec un taux" class="pe-9 tabular-nums" />
                                <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">%</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">Du montant emprunté, toutes pénalités comprises. Exigé dès qu’un taux est saisi.</p>
                        </FormField>
                    </div>
                    <p v-if="penaltyExample" class="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                        Exemple : {{ formatMoney(penaltyExample.base) }} en retard porte {{ formatMoney(penaltyExample.monthly) }} de pénalité par mois de retard.
                    </p>
                </Card>
            </div>

            <aside class="space-y-5 xl:sticky xl:top-20 xl:self-start">
                <Card class="space-y-3 px-5 py-4 text-sm">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Percent class="h-4 w-4 text-muted-foreground" />Essayer un montant</h2>
                    <div class="relative">
                        <IconInput v-model="sample" :icon="Coins" inputmode="decimal" aria-label="Montant d’essai" class="pe-10 tabular-nums" />
                        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Ar</span>
                    </div>
                    <div v-if="sampleTotal" class="space-y-1 rounded-lg bg-muted/50 px-3 py-2">
                        <p class="flex justify-between gap-2"><span class="text-muted-foreground">Intérêt</span><span class="font-semibold tabular-nums text-foreground">{{ sampleInterest ? formatMoney(sampleInterest.amount) : 'Aucun' }}</span></p>
                        <p class="flex justify-between gap-2"><span class="text-muted-foreground">À rembourser</span><span class="font-semibold tabular-nums text-foreground">{{ formatMoney(sampleTotal) }}</span></p>
                        <p v-if="sampleInterest" class="text-xs text-muted-foreground">Tranche {{ tierLabel(sampleInterest, formatMoney) }}</p>
                    </div>
                    <p class="text-xs text-muted-foreground">Un aperçu : le site recalcule l’intérêt, à l’ariary près, à chaque demande et à l’accord.</p>
                </Card>
            </aside>
        </div>
    </form>
</template>
