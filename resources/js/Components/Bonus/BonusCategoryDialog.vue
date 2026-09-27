<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Check, CircleAlert, Hash, Medal, Pencil, Search, Users, Wallet } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';

/**
 * ADR-212 — créer ou corriger une catégorie de bonus : ce qu'elle compte, son
 * seuil mensuel, son montant fixe, et le personnel concerné. Corriger une
 * catégorie ne réécrit aucun bonus déjà validé (figé sur le bonus).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** La catégorie à corriger, ou `null` pour en créer une. */
    category: { type: Object, default: null },
    measures: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open']);

const blank = () => ({ name: '', measure: '', threshold: '', amount: '', description: '', employee_uuids: [] });
const form = useForm(blank());
const staffQuery = ref('');

watch(() => props.open, (open) => {
    if (! open) return;
    form.clearErrors();
    staffQuery.value = '';
    const category = props.category;
    Object.assign(form, category ? {
        name: category.name,
        measure: category.measure,
        threshold: String(category.threshold),
        amount: String(Number(category.amount)),
        description: category.description ?? '',
        // Seuls les membres en poste se choisissent ; ceux partis restent dans la catégorie (serveur).
        employee_uuids: category.employees.filter((employee) => employee.in_post).map((employee) => employee.uuid),
    } : blank());
    nextTick(() => document.getElementById('bonus-category-name')?.focus());
});

const measureOptions = computed(() => props.measures.map((measure) => ({ value: measure.value, label: measure.label })));
const measure = computed(() => props.measures.find((item) => item.value === form.measure) ?? null);
const needsAccount = computed(() => Boolean(form.measure) && form.measure !== 'REFERRED_PATIENTS');

const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const shownStaff = computed(() => {
    const terms = normalize(staffQuery.value).split(/\s+/).filter(Boolean);

    return props.staff.filter((employee) => {
        const haystack = normalize(`${employee.name} ${employee.employee_number ?? ''} ${employee.job_title ?? ''}`);

        return terms.every((term) => haystack.includes(term));
    });
});
const selected = (uuid) => form.employee_uuids.includes(uuid);
const toggle = (uuid) => {
    form.employee_uuids = selected(uuid) ? form.employee_uuids.filter((item) => item !== uuid) : [...form.employee_uuids, uuid];
};

const amountPreview = computed(() => {
    const amount = Number(String(form.amount).replace(/\s/g, '').replace(',', '.'));

    return Number.isFinite(amount) && amount > 0 ? formatMoney(amount) : null;
});
const canSubmit = computed(() => form.name.trim() && form.measure && Number(form.threshold) >= 1 && amountPreview.value);

const close = () => {
    if (! form.processing) emit('update:open', false);
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.category) form.put(hrUrl(`/administration/bonus/categories/${props.category.uuid}`), options);
    else form.post(hrUrl('/administration/bonus/categories'), options);
};
</script>

<template>
    <Dialog
        :open="open"
        size="xl"
        :title="category ? `Modifier « ${category.name} »` : 'Nouvelle catégorie de bonus'"
        description="Ce que la catégorie compte chaque mois, à partir de combien de patients, pour quel montant — et qui elle concerne."
        :dismissible="false"
        @update:open="(value) => value || close()"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="category ? Pencil : Medal" class="h-5 w-5" /></span>
        </template>

        <form id="bonus-category-form" class="grid gap-6 lg:grid-cols-2 lg:gap-8" novalidate @submit.prevent="submit">
            <section class="grid content-start gap-4" aria-label="Règle">
                <FormField label="Nom" required :error="form.errors.name">
                    <IconInput id="bonus-category-name" v-model="form.name" :icon="Medal" maxlength="120" placeholder="Ex. Bonus chirurgien" />
                </FormField>
                <FormField label="Ce qui est compté" required as="div" :error="form.errors.measure">
                    <Select v-model="form.measure" :options="measureOptions" placeholder="Choisir" class="w-full" aria-label="Ce qui est compté" />
                    <p v-if="measure" class="mt-1.5 text-xs leading-5 text-muted-foreground">{{ measure.description }} Chaque patient ne compte qu’une fois dans le mois.</p>
                </FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Seuil mensuel" required :error="form.errors.threshold">
                        <IconInput v-model="form.threshold" :icon="Hash" type="number" min="1" inputmode="numeric" placeholder="Ex. 20" />
                    </FormField>
                    <FormField label="Montant du bonus" required :error="form.errors.amount">
                        <IconInput v-model="form.amount" :icon="Wallet" inputmode="decimal" placeholder="Ex. 20 000" />
                    </FormField>
                </div>
                <p v-if="Number(form.threshold) >= 1 && amountPreview" class="rounded-lg border border-primary/20 bg-primary/5 px-3.5 py-2.5 text-sm text-foreground">
                    À partir de <strong>{{ form.threshold }} patient{{ Number(form.threshold) > 1 ? 's' : '' }}</strong> dans le mois : <strong>{{ amountPreview }}</strong>.
                </p>
                <FormField label="Description" hint="(facultatif)" :error="form.errors.description">
                    <Textarea v-model="form.description" :rows="3" maxlength="2000" placeholder="À qui elle s’adresse, depuis quand…" />
                </FormField>
            </section>

            <section class="grid content-start gap-3 lg:border-s lg:border-border lg:ps-8" aria-labelledby="bonus-staff-title">
                <h3 id="bonus-staff-title" class="flex items-center gap-2 text-sm font-bold text-foreground">
                    <span class="grid h-7 w-7 place-items-center rounded-md bg-primary/10 text-primary"><Users class="h-4 w-4" /></span>
                    Personnel concerné
                    <span class="ms-auto text-xs font-medium text-muted-foreground">{{ form.employee_uuids.length }} choisi{{ form.employee_uuids.length > 1 ? 's' : '' }}</span>
                </h3>
                <IconInput v-model="staffQuery" :icon="Search" placeholder="Nom, matricule, fonction…" aria-label="Rechercher dans le personnel" />
                <p v-if="form.errors.employee_uuids" class="text-xs font-medium text-destructive">{{ form.errors.employee_uuids }}</p>
                <ul class="max-h-80 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    <li v-for="employee in shownStaff" :key="employee.uuid">
                        <label :for="`bonus-staff-${employee.uuid}`" class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-accent/40">
                            <Checkbox :id="`bonus-staff-${employee.uuid}`" :model-value="selected(employee.uuid)" @update:model-value="toggle(employee.uuid)" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-foreground">{{ employee.name }}</span>
                                <span class="block truncate text-xs text-muted-foreground">{{ [employee.employee_number, employee.job_title].filter(Boolean).join(' · ') }}</span>
                            </span>
                            <span v-if="needsAccount && ! employee.has_account" class="inline-flex shrink-0 items-center gap-1 text-[11px] font-semibold text-amber-700 dark:text-amber-300" title="Sans compte de connexion relié, ses patients soignés ne sont pas comptés.">
                                <CircleAlert class="h-3.5 w-3.5" />Sans compte
                            </span>
                        </label>
                    </li>
                    <li v-if="! shownStaff.length" class="px-3 py-6 text-center text-sm text-muted-foreground">Personne ne correspond.</li>
                </ul>
                <p v-if="needsAccount" :class="cn('text-xs leading-5 text-muted-foreground')">Les patients soignés se lisent sur le compte de connexion relié à la fiche RH : sans compte, rien n’est compté.</p>
            </section>
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="close">Annuler</Button>
            <Button type="submit" form="bonus-category-form" :disabled="form.processing || ! canSubmit">
                <Check class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : category ? 'Enregistrer' : 'Créer la catégorie' }}
            </Button>
        </template>
    </Dialog>
</template>
