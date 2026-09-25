<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Check, IdCard, Plus, Search, UserRound, Users, X } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-188 — qui est derrière le compte : du personnel de la clinique, relié à
 * sa fiche Employé, ou une personne externe, sans fiche.
 *
 * Le même composant sert l'assistant du portail et l'écran Utilisateurs du
 * site. Il ne décide rien : le site revérifie que la fiche existe, qu'elle est
 * en poste et qu'aucun autre compte ne la porte.
 */
const props = defineProps({
    kind: { type: String, default: '' },
    employeeUuid: { type: String, default: '' },
    /** Les fiches servies par le site : en poste, avec le compte qui les porte déjà. */
    employees: { type: Array, default: () => [] },
    /** Le compte modifié : sa propre fiche reste choisissable. */
    currentUserUuid: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
    /** Où créer une fiche manquante, si le compte en a le droit. */
    createEmployeeHref: { type: String, default: '' },
    siteName: { type: String, default: '' },
});
const emit = defineEmits(['update:kind', 'update:employeeUuid', 'pick']);

const OPTIONS = [
    {
        value: 'STAFF',
        label: 'Personnel clinique',
        hint: 'Relié à sa fiche employé : son planning RH dit quand la personne est disponible.',
        icon: IdCard,
    },
    {
        value: 'EXTERNAL',
        label: 'Externe',
        hint: 'Personne sans fiche employé à la clinique.',
        icon: UserRound,
    },
];

const chooseKind = (value) => {
    emit('update:kind', value);
    if (value === 'EXTERNAL') emit('update:employeeUuid', '');
};

const query = ref('');
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const takenByOther = (employee) => Boolean(employee.account && employee.account.uuid !== props.currentUserUuid);
const selected = computed(() => props.employees.find((employee) => employee.uuid === props.employeeUuid) ?? null);
const freeCount = computed(() => props.employees.filter((employee) => ! takenByOther(employee)).length);

/** Les fiches libres d'abord ; celles d'un autre compte restent visibles, pour qu'on sache pourquoi. */
const shown = computed(() => {
    const terms = normalize(query.value).split(/\s+/).filter(Boolean);

    return props.employees
        .filter((employee) => terms.every((term) => normalize(`${employee.name} ${employee.employee_number} ${employee.job_title ?? ''} ${employee.department ?? ''}`).includes(term)))
        .sort((left, right) => Number(takenByOther(left)) - Number(takenByOther(right)));
});

const pick = (employee) => {
    if (takenByOther(employee)) return;
    emit('update:employeeUuid', employee.uuid);
    emit('pick', employee);
};

const initials = (employee) => `${employee.first_name?.[0] ?? ''}${employee.last_name?.[0] ?? ''}`.toUpperCase() || 'EM';

watch(() => props.kind, (kind) => {
    if (kind !== 'STAFF') query.value = '';
});
</script>

<template>
    <section class="space-y-3" aria-labelledby="account-kind-title">
        <div>
            <p id="account-kind-title" class="text-sm font-medium text-foreground">Qui utilisera ce compte ?<span class="ms-0.5 text-destructive">*</span></p>
            <p class="text-xs text-muted-foreground">Le lien avec les Ressources humaines se fait ici, pas sur la fiche employé.</p>
        </div>

        <div role="radiogroup" aria-labelledby="account-kind-title" class="grid gap-3 sm:grid-cols-2">
            <button
                v-for="option in OPTIONS"
                :id="`account-kind-${option.value.toLowerCase()}`"
                :key="option.value"
                type="button"
                role="radio"
                :aria-checked="kind === option.value"
                :class="cn(
                    'relative flex items-start gap-3 rounded-xl border p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    kind === option.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-card hover:border-primary/40 hover:bg-accent/40',
                    errors.account_kind && ! kind && 'border-destructive/60',
                )"
                @click="chooseKind(option.value)"
            >
                <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', kind === option.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                    <component :is="option.icon" class="h-4 w-4" />
                </span>
                <span class="min-w-0 pe-5">
                    <span class="block text-sm font-semibold text-foreground">{{ option.label }}</span>
                    <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ option.hint }}</span>
                </span>
                <Check v-if="kind === option.value" class="absolute end-3 top-3 h-4 w-4 text-primary" aria-hidden="true" />
            </button>
        </div>
        <FormError v-if="errors.account_kind">{{ errors.account_kind }}</FormError>

        <!-- Personnel clinique : la fiche de la personne. -->
        <div v-if="kind === 'STAFF'" class="overflow-hidden rounded-xl border border-border">
            <div class="flex flex-col gap-2 border-b border-border bg-muted/40 px-3.5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="flex items-center gap-2 text-sm font-semibold text-foreground">
                    <Users class="h-4 w-4 text-muted-foreground" />Fiche employé
                    <Badge variant="outline" class="tabular-nums">{{ freeCount }} libre{{ freeCount > 1 ? 's' : '' }}</Badge>
                </p>
                <div v-if="employees.length" class="relative w-full sm:w-64">
                    <IconInput v-model="query" :icon="Search" type="search" placeholder="Nom, matricule, fonction…" aria-label="Rechercher une fiche employé" class="h-9 pe-8" />
                    <button v-if="query" type="button" class="absolute inset-y-0 end-0 grid w-8 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="query = ''">
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>

            <ul v-if="shown.length" role="radiogroup" aria-label="Fiche employé" class="max-h-72 divide-y divide-border overflow-y-auto">
                <li v-for="employee in shown" :key="employee.uuid">
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="employeeUuid === employee.uuid"
                        :aria-disabled="takenByOther(employee) || undefined"
                        :class="cn(
                            'flex w-full items-center gap-3 px-3.5 py-2.5 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                            employeeUuid === employee.uuid ? 'bg-primary/5' : 'hover:bg-accent/50',
                            takenByOther(employee) && 'cursor-not-allowed opacity-60 hover:bg-transparent',
                        )"
                        @click="pick(employee)"
                    >
                        <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold', employeeUuid === employee.uuid ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                            <Check v-if="employeeUuid === employee.uuid" class="h-4 w-4" :stroke-width="3" />
                            <template v-else>{{ initials(employee) }}</template>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-foreground">{{ employee.name }}</span>
                            <span class="block truncate text-xs text-muted-foreground">
                                <span class="font-mono">{{ employee.employee_number }}</span>
                                <template v-if="employee.job_title"> · {{ employee.job_title }}</template>
                                <template v-if="employee.department"> · {{ employee.department }}</template>
                            </span>
                        </span>
                        <Badge v-if="takenByOther(employee)" variant="outline" class="shrink-0">Compte : {{ employee.account.name }}</Badge>
                        <Badge v-else-if="employee.account" variant="success" class="shrink-0">Ce compte</Badge>
                    </button>
                </li>
            </ul>
            <p v-else-if="employees.length" class="px-3.5 py-6 text-center text-sm text-muted-foreground">Aucune fiche ne correspond à « {{ query }} ».</p>
            <div v-else class="px-3.5 py-6 text-center">
                <p class="text-sm font-semibold text-foreground">Aucune fiche employé en poste{{ siteName ? ` sur ${siteName}` : '' }}</p>
                <p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-muted-foreground">Créez d’abord la fiche de la personne dans Ressources humaines, puis revenez relier son compte.</p>
                <Link v-if="createEmployeeHref" :href="createEmployeeHref" class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
                    <Plus class="h-4 w-4" />Créer une fiche employé
                </Link>
            </div>
            <FormError v-if="errors.employee_uuid" class="px-3.5 pb-3">{{ errors.employee_uuid }}</FormError>
        </div>
        <p v-else-if="kind === 'EXTERNAL'" class="text-xs leading-5 text-muted-foreground">
            Aucun planning RH ne vaut pour ce compte. S’il travaille un jour à la clinique, créez sa fiche puis modifiez ce compte pour la relier.
        </p>

        <p v-if="selected && kind === 'STAFF'" class="sr-only" aria-live="polite">Fiche choisie : {{ selected.name }}, {{ selected.employee_number }}.</p>
    </section>
</template>
