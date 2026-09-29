<script setup>
import { computed } from 'vue';
import { CircleAlert, CircleCheck, CircleCheckBig, CircleDashed, Loader2, Pencil } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import EmployeeSectionCard from './EmployeeSectionCard.vue';

/**
 * ADR-221 — la dernière étape du dossier : ce qui est enregistré, étape par
 * étape, lu sur les données du serveur (jamais sur la saisie en cours), et un
 * crayon pour y revenir. Rien ne s'enregistre ici : tout l'a déjà été.
 */
const props = defineProps({
    steps: { type: Array, required: true },
    employee: { type: Object, required: true },
    payroll: { type: Object, default: null },
    benefits: { type: Array, default: null },
    stateOf: { type: Function, required: true },
});
defineEmits(['edit']);

const joined = (...parts) => parts.filter(Boolean).join(' · ');
const summaries = computed(() => {
    const employee = props.employee;
    const payroll = props.payroll;
    const current = (props.benefits ?? []).filter((benefit) => ! benefit.archived && benefit.current);

    return {
        identity: joined(employee.name, employee.birth_date && `Né(e) le ${formatDate(employee.birth_date)}`, employee.birth_place),
        contact: joined(employee.phone || 'Téléphone non renseigné', employee.address || 'Adresse non renseignée'),
        post: joined(employee.employee_number, employee.department || 'Non affecté', employee.job_title || 'Fonction non renseignée', employee.hire_date && `Entrée le ${formatDate(employee.hire_date)}`),
        more: joined(employee.diploma && `Diplôme : ${employee.diploma}`, employee.education_level && `Niveau : ${employee.education_level}`, employee.badge && `Badge ${employee.badge}`) || 'Rien de renseigné (facultatif)',
        pay: payroll?.remuneration_label
            ? joined(payroll.remuneration_label, payroll.remuneration_amount !== null && payroll.remuneration_amount !== undefined && payroll.remuneration_amount !== '' && `${formatMoney(Number(payroll.remuneration_amount))} par mois`)
            : 'Non renseignée',
        benefits: current.length ? `${current.length} en cours : ${current.map((benefit) => benefit.type).join(', ')}` : 'Aucun avantage en cours',
        bank: payroll?.bank ? joined(payroll.bank.label, payroll.bank_account_number) : 'Aucune banque',
    };
});

const STATUS = {
    failed: { icon: CircleAlert, tone: 'text-destructive', label: 'Refusé — à corriger' },
    incomplete: { icon: CircleDashed, tone: 'text-amber-600 dark:text-amber-400', label: 'À compléter' },
    dirty: { icon: Loader2, tone: 'text-muted-foreground animate-spin', label: 'Enregistrement…' },
    saving: { icon: Loader2, tone: 'text-muted-foreground animate-spin', label: 'Enregistrement…' },
};
const statusOf = (key) => STATUS[props.stateOf(key)] ?? { icon: CircleCheck, tone: 'text-emerald-600 dark:text-emerald-400', label: 'Enregistré' };
const rows = computed(() => props.steps.filter((step) => step.key !== 'done'));
const allSaved = computed(() => rows.value.every((step) => ! STATUS[props.stateOf(step.key)]));
</script>

<template>
    <EmployeeSectionCard
        :icon="CircleCheckBig"
        title="Récapitulatif"
        description="Ce qui est enregistré dans le dossier. Revenez sur une étape avec le crayon ; « Terminer » ouvre la fiche."
        tone="bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300"
    >
        <template #status>
            <p :class="cn('inline-flex items-center gap-1.5 text-xs font-medium', allSaved ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400')" role="status">
                <CircleCheck v-if="allSaved" class="h-3.5 w-3.5" aria-hidden="true" />
                <CircleDashed v-else class="h-3.5 w-3.5" aria-hidden="true" />
                {{ allSaved ? 'Tout est enregistré' : 'Une étape attend encore' }}
            </p>
        </template>
        <ul class="divide-y divide-border rounded-lg border border-border">
            <li v-for="step in rows" :key="step.key" class="flex items-start gap-3 px-3 py-2.5">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><component :is="step.icon" class="h-4 w-4" /></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-foreground">{{ step.label }}</p>
                    <p class="mt-0.5 break-words text-xs leading-5 text-muted-foreground">{{ summaries[step.key] }}</p>
                    <p :class="cn('mt-1 inline-flex items-center gap-1 text-[11px] font-medium', statusOf(step.key).tone.replace('animate-spin', ''))">
                        <component :is="statusOf(step.key).icon" :class="cn('h-3 w-3', statusOf(step.key).tone)" aria-hidden="true" />{{ statusOf(step.key).label }}
                    </p>
                </div>
                <Button type="button" variant="ghost" icon :aria-label="`Revenir à l’étape ${step.label}`" :title="`Revenir à l’étape ${step.label}`" @click="$emit('edit', step.key)"><Pencil class="h-4 w-4" /></Button>
            </li>
        </ul>
    </EmployeeSectionCard>
</template>
