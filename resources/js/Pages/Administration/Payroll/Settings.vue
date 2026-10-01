<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Baby, Calculator, CircleAlert, GraduationCap, HeartPulse, Landmark, Plus, Receipt, RotateCcw, Save, ShieldCheck, SlidersHorizontal, Trash2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';
import { bracketsFrom, payrollFormFrom, payrollPayload } from '@/utilities/payroll';

/**
 * ADR-233 — les paramètres de paie du site : cotisations (CNAPS, organisme médical), barème
 * IRSA par tranches, minimum, réduction par enfant. Une proposition (barème Madagascar) à
 * relire avec le comptable, désactivée tant qu'on ne l'active pas. La simulation calcule
 * sur le serveur, avec les valeurs en cours de saisie, sans rien enregistrer.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    settings: { type: Object, required: true },
    proposal: { type: Object, required: true },
    configured: { type: Boolean, default: false },
    updatedAt: { type: String, default: null },
    updatedBy: { type: String, default: null },
    canUpdate: { type: Boolean, default: false },
});

const form = useForm(payrollFormFrom(props.settings));
const resetOpen = ref(false);

const brackets = computed(() => bracketsFrom(form.irsa_brackets));
const addBracket = () => {
    const rows = [...form.irsa_brackets];
    const last = rows.pop();
    const previous = rows.length ? Number(String(rows[rows.length - 1].up_to).replace(/\s/g, '')) || 0 : 0;
    rows.push({ up_to: String(previous + 100000), rate: last?.rate ?? '0' }, last ?? { up_to: '', rate: '0' });
    form.irsa_brackets = rows;
};
const removeBracket = (index) => {
    form.irsa_brackets = form.irsa_brackets.filter((_, i) => i !== index);
    if (form.irsa_brackets.length) form.irsa_brackets[form.irsa_brackets.length - 1].up_to = '';
};
const bracketError = (index, field) => form.errors[`irsa_brackets.${index}.${field}`] ?? '';

const save = () => form.transform((data) => payrollPayload(data)).put(hrUrl('/administration/paie/parametres'), { preserveScroll: true });
const applyProposal = () => {
    const keep = form.legal_deductions_enabled;
    Object.assign(form, payrollFormFrom(props.proposal), { legal_deductions_enabled: keep });
    resetOpen.value = false;
};

// — Simulation : un bulletin calculé par le serveur sur les valeurs en cours de saisie. —
const simulation = useForm({ gross: '1000000', children: '0', remuneration_type: 'SALARY' });
const result = ref(null);
const simError = ref('');
const loading = ref(false);
let sequence = 0;
let timer = null;
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const simulate = async () => {
    const current = ++sequence;
    loading.value = true;
    try {
        const response = await fetch(hrUrl('/administration/paie/parametres/simulation'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({
                ...payrollPayload(form.data()),
                gross: String(simulation.gross).replace(/[\s  ]/g, '').replace(',', '.') || '0',
                children: Number(simulation.children) || 0,
                remuneration_type: simulation.remuneration_type,
            }),
        });
        const data = await response.json().catch(() => ({}));
        if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? 'Simulation impossible.');
        if (current === sequence) { result.value = data; simError.value = ''; }
    } catch (error) {
        if (current === sequence) { result.value = null; simError.value = error.message; }
    } finally {
        if (current === sequence) loading.value = false;
    }
};
watch(() => [form.data(), simulation.data()], () => {
    clearTimeout(timer);
    timer = setTimeout(simulate, 400);
}, { deep: true, immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

const simRows = computed(() => result.value ? [
    { label: 'Brut', amount: result.value.gross, strong: true },
    { label: 'CNAPS salarié', amount: result.value.cnaps, minus: true },
    { label: `${form.health_label || 'Organisme médical'} salarié`, amount: result.value.health, minus: true },
    { label: 'Base imposable', amount: result.value.taxable, muted: true },
    { label: 'IRSA brut', amount: result.value.irsa_before_reduction, muted: true },
    { label: 'Réduction enfants', amount: result.value.child_reduction, muted: true },
    { label: 'IRSA retenu', amount: result.value.irsa, minus: true },
] : []);
</script>

<template>
    <Head title="Paramètres de paie" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines · Paie"
            title="Paramètres de paie"
            description="Cotisations et impôt sur le salaire retenus par la paie de ce site. Une paie déjà payée garde les paramètres qu’elle a utilisés."
            :icon="SlidersHorizontal"
        >
            <template #actions>
                <Button :as="Link" :href="hrUrl('/administration/paie')" variant="outline"><ArrowLeft class="h-4 w-4" />Paie du mois</Button>
            </template>
        </PageHeader>

        <div v-if="! configured" class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            <CircleAlert class="mt-0.5 h-5 w-5 shrink-0" />
            <p><strong>Barème proposé, pas encore enregistré.</strong> Ce sont les taux courants à Madagascar (CNAPS, organisme médical, IRSA par tranches) : faites-les vérifier par votre comptable, complétez les plafonds, puis activez et enregistrez.</p>
        </div>
        <p v-else class="text-xs text-muted-foreground">Enregistrés le {{ formatDateTime(updatedAt) }}<template v-if="updatedBy"> par {{ updatedBy }}</template>.</p>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <form id="payroll-settings-form" class="space-y-5" @submit.prevent="save">
                <fieldset :disabled="! canUpdate" class="space-y-5">
                    <Card class="flex flex-wrap items-center gap-4 p-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><ShieldCheck class="h-5 w-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-foreground">Retenues légales</p>
                            <p class="text-xs text-muted-foreground">Activées, la paie retient CNAPS, organisme médical et IRSA, et affiche les charges patronales. Désactivées, le net est le brut moins les dettes.</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm font-medium">
                            <Switch v-model="form.legal_deductions_enabled" aria-label="Activer les retenues légales" />
                            <Badge :variant="form.legal_deductions_enabled ? 'success' : 'outline'">{{ form.legal_deductions_enabled ? 'Activées' : 'Désactivées' }}</Badge>
                        </label>
                    </Card>

                    <Card class="space-y-4 p-4">
                        <header class="flex items-center gap-2"><Landmark class="h-4 w-4 text-primary" /><h2 class="text-sm font-bold text-foreground">CNAPS</h2></header>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <FormField label="Taux salarié (%)" required :error="form.errors.cnaps_employee_rate"><Input v-model="form.cnaps_employee_rate" inputmode="decimal" /></FormField>
                            <FormField label="Taux employeur (%)" required :error="form.errors.cnaps_employer_rate"><Input v-model="form.cnaps_employer_rate" inputmode="decimal" /></FormField>
                            <FormField label="Plafond mensuel (Ar)" hint="(vide = sans plafond)" :error="form.errors.cnaps_ceiling"><Input v-model="form.cnaps_ceiling" inputmode="decimal" placeholder="8 × salaire minimum" /></FormField>
                        </div>
                    </Card>

                    <Card class="space-y-4 p-4">
                        <header class="flex items-center gap-2"><HeartPulse class="h-4 w-4 text-primary" /><h2 class="text-sm font-bold text-foreground">Organisme médical</h2></header>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <FormField label="Nom" :error="form.errors.health_label"><Input v-model="form.health_label" maxlength="60" placeholder="OSTIE / SMIE" /></FormField>
                            <FormField label="Taux salarié (%)" required :error="form.errors.health_employee_rate"><Input v-model="form.health_employee_rate" inputmode="decimal" /></FormField>
                            <FormField label="Taux employeur (%)" required :error="form.errors.health_employer_rate"><Input v-model="form.health_employer_rate" inputmode="decimal" /></FormField>
                            <FormField label="Plafond mensuel (Ar)" hint="(vide = sans)" :error="form.errors.health_ceiling"><Input v-model="form.health_ceiling" inputmode="decimal" /></FormField>
                        </div>
                    </Card>

                    <Card class="space-y-4 p-4">
                        <header class="flex items-center gap-2"><Receipt class="h-4 w-4 text-primary" /><h2 class="text-sm font-bold text-foreground">IRSA — impôt sur le salaire</h2></header>
                        <p class="text-xs text-muted-foreground">Base imposable = brut − CNAPS − organisme médical. Chaque tranche est imposée à son taux ; la dernière n’a pas de plafond.</p>
                        <div class="overflow-hidden rounded-lg border border-border">
                            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_7rem_2.5rem] gap-2 bg-muted/40 px-3 py-1.5 text-xs font-medium text-muted-foreground">
                                <span>De</span><span>Jusqu’à (Ar)</span><span>Taux (%)</span><span class="sr-only">Retirer</span>
                            </div>
                            <ol class="divide-y divide-border">
                                <li v-for="(bracket, index) in form.irsa_brackets" :key="index" class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_7rem_2.5rem] items-start gap-2 px-3 py-2">
                                    <span class="pt-2 text-sm tabular-nums text-muted-foreground">{{ formatMoney(brackets[index]?.from ?? 0) }}</span>
                                    <div>
                                        <Input v-if="index < form.irsa_brackets.length - 1" v-model="bracket.up_to" inputmode="decimal" :aria-label="`Plafond de la tranche ${index + 1}`" :class="bracketError(index, 'up_to') && 'border-destructive'" />
                                        <span v-else class="block pt-2 text-sm text-muted-foreground">et au-delà</span>
                                        <p v-if="bracketError(index, 'up_to')" class="mt-1 text-xs text-destructive">{{ bracketError(index, 'up_to') }}</p>
                                    </div>
                                    <div>
                                        <Input v-model="bracket.rate" inputmode="decimal" :aria-label="`Taux de la tranche ${index + 1}`" :class="bracketError(index, 'rate') && 'border-destructive'" />
                                        <p v-if="bracketError(index, 'rate')" class="mt-1 text-xs text-destructive">{{ bracketError(index, 'rate') }}</p>
                                    </div>
                                    <Button type="button" variant="ghost" size="icon" class="text-destructive hover:text-destructive" :disabled="form.irsa_brackets.length <= 1" :aria-label="`Retirer la tranche ${index + 1}`" @click="removeBracket(index)"><Trash2 class="h-4 w-4" /></Button>
                                </li>
                            </ol>
                            <div class="border-t border-border bg-muted/20 px-3 py-2">
                                <Button type="button" size="sm" variant="outline" :disabled="form.irsa_brackets.length >= 15" @click="addBracket"><Plus class="h-4 w-4" />Ajouter une tranche</Button>
                            </div>
                        </div>
                        <p v-if="form.errors.irsa_brackets" class="text-xs text-destructive">{{ form.errors.irsa_brackets }}</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <FormField label="IRSA minimum (Ar)" required :error="form.errors.irsa_minimum"><Input v-model="form.irsa_minimum" inputmode="decimal" /></FormField>
                            <FormField label="Réduction par enfant (Ar)" required :error="form.errors.irsa_child_reduction"><Input v-model="form.irsa_child_reduction" inputmode="decimal" /></FormField>
                            <FormField label="Arrondir la base à (Ar)" hint="(vide = non)" :error="form.errors.irsa_base_rounding"><Input v-model="form.irsa_base_rounding" inputmode="numeric" placeholder="Ex. 100" /></FormField>
                        </div>
                        <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><Baby class="h-3.5 w-3.5" />Les enfants à charge sont ceux de la fiche employé (étape Compléments). L’IRSA ne descend jamais sous le minimum quand la base dépasse la tranche à 0 %.</p>
                    </Card>

                    <Card class="flex flex-wrap items-center gap-4 p-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><GraduationCap class="h-5 w-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-foreground">Indemnités de stage</p>
                            <p class="text-xs text-muted-foreground">Par défaut, une indemnité de stagiaire n’est pas soumise aux retenues. Cochez si votre comptable en décide autrement.</p>
                        </div>
                        <Switch v-model="form.allowance_subject" aria-label="Soumettre les indemnités de stage aux retenues" />
                    </Card>
                </fieldset>

                <div class="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card/95 px-4 py-3 shadow-sm backdrop-blur">
                    <p v-if="! canUpdate" class="me-auto text-sm text-muted-foreground">Lecture seule : modifier demande le droit « salary_settings.update ».</p>
                    <p v-else class="me-auto text-sm text-muted-foreground">{{ form.isDirty ? 'Modifications non enregistrées.' : 'Aucune modification.' }}</p>
                    <Button v-if="canUpdate" type="button" variant="ghost" @click="resetOpen = true"><RotateCcw class="h-4 w-4" />Reprendre le barème proposé</Button>
                    <Button v-if="canUpdate" type="submit" :disabled="form.processing"><Save class="h-4 w-4" />Enregistrer</Button>
                </div>
            </form>

            <aside class="space-y-3 xl:sticky xl:top-4 xl:self-start">
                <Card class="space-y-3 p-4">
                    <header class="flex items-center gap-2"><Calculator class="h-4 w-4 text-primary" /><h2 class="text-sm font-bold text-foreground">Simulation d’un bulletin</h2></header>
                    <p class="text-xs text-muted-foreground">Calculée avec les valeurs ci-contre, même non enregistrées ni activées.</p>
                    <div class="grid grid-cols-2 gap-2">
                        <FormField label="Brut (Ar)"><Input v-model="simulation.gross" inputmode="decimal" /></FormField>
                        <FormField label="Enfants"><Input v-model="simulation.children" inputmode="numeric" /></FormField>
                    </div>
                    <p v-if="simError" class="text-xs text-destructive">{{ simError }}</p>
                    <dl v-else-if="result" :class="['divide-y divide-border rounded-lg border border-border text-sm', loading && 'opacity-60']">
                        <div v-for="row in simRows" :key="row.label" class="flex justify-between gap-3 px-3 py-1.5">
                            <dt :class="row.muted ? 'text-muted-foreground' : 'text-foreground'">{{ row.label }}</dt>
                            <dd :class="['tabular-nums', row.strong && 'font-semibold', row.minus && 'text-rose-700 dark:text-rose-300', row.muted && 'text-muted-foreground']">{{ row.minus ? '− ' : '' }}{{ formatMoney(row.amount) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 bg-primary/5 px-3 py-2">
                            <dt class="font-semibold text-foreground">Net à verser</dt>
                            <dd class="font-bold tabular-nums text-primary">{{ formatMoney(result.net) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 px-3 py-1.5 text-xs text-muted-foreground">
                            <dt>Charges patronales</dt><dd class="tabular-nums">{{ formatMoney(result.employer) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 px-3 py-1.5 text-xs text-muted-foreground">
                            <dt>Coût employeur</dt><dd class="tabular-nums">{{ formatMoney(result.cost) }}</dd>
                        </div>
                    </dl>
                </Card>
            </aside>
        </div>

        <ConfirmModal
            :open="resetOpen"
            title="Reprendre le barème proposé"
            description="Les taux, plafonds et tranches saisis sont remplacés par la proposition. Rien n’est enregistré avant « Enregistrer »."
            confirm-label="Reprendre la proposition"
            :icon="RotateCcw"
            @update:open="(value) => (resetOpen = value)"
            @confirm="applyProposal"
        />
    </div>
</template>
