<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Archive, ChevronDown, Coins, Gift, Info, Plus, Tag } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { localToday } from '@/utilities/date';
import { hrUrl } from '@/utilities/hrUrl';
import { currencyLabel, formatMoney } from '@/utilities/money';
import BenefitCard from './BenefitCard.vue';
import EmployeeSectionCard from './EmployeeSectionCard.vue';

/**
 * ADR-213 — les avantages et primes : logement, transport, repas, téléphone,
 * assurance, prime… Chacun porte un montant (facultatif pour un avantage en
 * nature), un motif et une fréquence.
 *
 * Seules les fonctions cochées dans le module Fonctions y ouvrent droit
 * (« Médecin » d'office) : pour les autres, la section le dit et renvoie au
 * réglage. Ajouter est un geste explicite ; corriger s'enregistre tout seul ;
 * retirer demande un motif et garde l'avantage dans l'historique.
 */
const props = defineProps({
    benefits: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
});
const { can } = usePermissions();

const active = computed(() => props.benefits.filter((benefit) => ! benefit.archived));
const retired = computed(() => props.benefits.filter((benefit) => benefit.archived));
const showRetired = ref(false);
const typeChoices = computed(() => (props.options.types ?? []).filter((item) => item.available).map((item) => ({ value: item.uuid, label: item.label })));
const benefitUrl = (uuid) => `${props.url}/${uuid}`;

/* Ajouter : un geste explicite, un avantage n'existe pas à moitié. */
const adding = ref(false);
const addForm = useForm({ benefit_type_uuid: '', amount: '', reason: '', frequency: 'MONTHLY', starts_on: localToday(), ends_on: '' });
const openAdd = () => {
    addForm.reset();
    addForm.clearErrors();
    addForm.starts_on = localToday();
    adding.value = true;
};
const submitAdd = () => addForm
    .transform((data) => ({ ...data, ends_on: data.frequency === 'MONTHLY' ? data.ends_on : '' }))
    .post(props.url, { preserveScroll: true, preserveState: true, onSuccess: () => { adding.value = false; addForm.reset(); } });
const canSubmitAdd = computed(() => addForm.benefit_type_uuid && addForm.reason.trim().length >= 3 && addForm.starts_on && ! addForm.processing);

/* Retirer : avec un motif, jamais supprimé. */
const retiring = ref(null);
const retireForm = useForm({ reason: '' });
const openRetire = (benefit) => {
    retiring.value = benefit;
    retireForm.reset();
    retireForm.clearErrors();
};
const confirmRetire = () => retireForm.delete(benefitUrl(retiring.value.uuid), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { retiring.value = null; },
});

const frenchDate = (iso) => (iso ? String(iso).slice(0, 10).split('-').reverse().join('/') : '');
</script>

<template>
    <EmployeeSectionCard
        :icon="Gift"
        title="Avantages et primes"
        description="Logement, transport, repas, téléphone, assurance, prime… avec leur montant et leur motif. Aucun total ni net n’est calculé."
        tone="bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300"
        :read-only="! canEdit"
        read-only-hint="Lecture seule : déclarer un avantage demande le droit « employees.payroll.update »."
    >
        <div class="space-y-3">
            <p v-if="! options.eligible" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                <Info class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    <template v-if="options.job_title">La fonction « {{ options.job_title }} » n’ouvre pas droit aux avantages et primes.</template>
                    <template v-else>Choisissez d’abord la fonction de la personne (section Poste) : seules certaines fonctions ouvrent droit aux avantages.</template>
                    Cela se règle par fonction<template v-if="can('hr_settings.view')"> dans le <Link :href="hrUrl('/administration/job-titles')" class="font-semibold underline">module Fonctions</Link></template>.
                    <template v-if="active.length"> Les avantages déjà déclarés restent, et se corrigent ou se retirent ci-dessous.</template>
                </span>
            </p>

            <BenefitCard
                v-for="benefit in active"
                :key="benefit.uuid"
                :benefit="benefit"
                :type-options="options.types ?? []"
                :frequencies="options.frequencies ?? []"
                :url="benefitUrl(benefit.uuid)"
                :can-edit="canEdit"
                @retire="openRetire"
            />

            <p v-if="! active.length && ! adding" class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                <Gift class="mx-auto mb-2 h-6 w-6" />Aucun avantage ni prime déclaré.
            </p>

            <!-- Nouvel avantage -->
            <form v-if="adding" class="rounded-xl border border-primary/30 bg-primary/5 p-4" novalidate @submit.prevent="submitAdd">
                <p class="mb-3 flex items-center gap-2 text-sm font-bold text-foreground"><Plus class="h-4 w-4 text-primary" />Nouvel avantage ou prime</p>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <FormField as="div" label="Type" required :error="addForm.errors.benefit_type_uuid">
                        <ShadSelect v-model="addForm.benefit_type_uuid" :options="typeChoices" :icon="Tag" placeholder="Choisir…" class="w-full" aria-label="Type d’avantage" />
                    </FormField>
                    <FormField label="Montant" hint="(facultatif en nature)" :error="addForm.errors.amount">
                        <div class="relative">
                            <IconInput v-model="addForm.amount" :icon="Coins" inputmode="decimal" class="pe-12 tabular-nums" placeholder="Ex. 50 000" />
                            <span class="pointer-events-none absolute inset-y-0 end-0 grid place-items-center pe-3 text-xs font-medium text-muted-foreground">{{ currencyLabel() }}</span>
                        </div>
                    </FormField>
                    <FormField as="div" label="Fréquence" :error="addForm.errors.frequency">
                        <div class="inline-flex w-full rounded-md bg-muted p-0.5" role="radiogroup" aria-label="Fréquence">
                            <button
                                v-for="frequency in options.frequencies"
                                :key="frequency.value"
                                type="button"
                                role="radio"
                                :aria-checked="addForm.frequency === frequency.value"
                                :class="cn('flex-1 rounded px-2 py-1.5 text-xs font-semibold transition-colors', addForm.frequency === frequency.value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                                @click="addForm.frequency = frequency.value"
                            >{{ frequency.label }}</button>
                        </div>
                    </FormField>
                    <div class="grid grid-cols-2 gap-2">
                        <FormField as="div" :label="addForm.frequency === 'MONTHLY' ? 'Début' : 'Date'" required :error="addForm.errors.starts_on">
                            <DatePicker v-model="addForm.starts_on" aria-label="Début" />
                        </FormField>
                        <FormField v-if="addForm.frequency === 'MONTHLY'" as="div" label="Fin" hint="(facultatif)" :error="addForm.errors.ends_on">
                            <DatePicker v-model="addForm.ends_on" aria-label="Fin" :min="addForm.starts_on || undefined" />
                        </FormField>
                    </div>
                    <FormField label="Motif" required class="sm:col-span-2 xl:col-span-4" :error="addForm.errors.reason">
                        <Textarea v-model="addForm.reason" :rows="2" maxlength="1000" placeholder="Pourquoi cet avantage est accordé (ex. gardes de nuit, logement de fonction…)" />
                    </FormField>
                </div>
                <div class="mt-3 flex items-center justify-end gap-2">
                    <Button type="button" variant="ghost" size="sm" :disabled="addForm.processing" @click="adding = false">Annuler</Button>
                    <Button type="submit" size="sm" :disabled="! canSubmitAdd"><Plus class="h-4 w-4" />{{ addForm.processing ? 'Ajout…' : 'Ajouter l’avantage' }}</Button>
                </div>
            </form>
            <Button v-else-if="canEdit && options.eligible" type="button" variant="outline" size="sm" @click="openAdd"><Plus class="h-4 w-4" />Ajouter un avantage ou une prime</Button>

            <!-- Retirés : lisibles, avec leur motif. -->
            <div v-if="retired.length" class="pt-2">
                <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground" :aria-expanded="showRetired" @click="showRetired = ! showRetired">
                    <ChevronDown :class="cn('h-4 w-4 transition-transform', showRetired && 'rotate-180')" />Retirés ({{ retired.length }})
                </button>
                <ul v-if="showRetired" class="mt-2 divide-y divide-border rounded-xl border border-border">
                    <li v-for="benefit in retired" :key="benefit.uuid" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3.5 py-2.5 text-xs text-muted-foreground">
                        <span class="font-semibold text-foreground line-through">{{ benefit.type }}</span>
                        <span v-if="benefit.amount">{{ formatMoney(benefit.amount) }}</span>
                        <span>{{ benefit.frequency_label }} · depuis le {{ frenchDate(benefit.starts_on) }}</span>
                        <span class="basis-full">Motif de l’avantage : {{ benefit.reason }}</span>
                        <span class="basis-full">Retiré : {{ benefit.delete_reason }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <Dialog
            :open="retiring !== null"
            :title="retiring ? `Retirer « ${retiring.type} »` : ''"
            description="Rien n’est supprimé : l’avantage reste dans l’historique du dossier, avec votre motif. Pour qu’il s’arrête à une date, renseignez plutôt sa fin."
            :dismissible="false"
            @update:open="(value) => value || (retiring = null)"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300"><Archive class="h-5 w-5" /></span>
            </template>
            <form id="benefit-retire-form" novalidate @submit.prevent="confirmRetire">
                <FormField label="Motif du retrait" required :error="retireForm.errors.reason">
                    <Textarea v-model="retireForm.reason" :rows="3" maxlength="1000" placeholder="Pourquoi cet avantage est-il retiré ?" />
                </FormField>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="retireForm.processing" @click="retiring = null">Annuler</Button>
                <Button type="submit" form="benefit-retire-form" variant="destructive" :disabled="retireForm.processing || retireForm.reason.trim().length < 3">
                    <Archive class="h-4 w-4" />{{ retireForm.processing ? 'Retrait…' : 'Retirer' }}
                </Button>
            </template>
        </Dialog>
    </EmployeeSectionCard>
</template>
