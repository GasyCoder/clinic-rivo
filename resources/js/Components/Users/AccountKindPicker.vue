<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeftRight, Check, CornerDownLeft, IdCard, Plus, Search, UserRound, X } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';
import { highlight, searchStaff } from '@/utilities/staffSearch';

/**
 * ADR-188 — qui est derrière le compte : du personnel de la clinique, relié à
 * sa fiche Employé, ou une personne externe, sans fiche.
 *
 * Pour le personnel, on cherche d'abord la personne (auto-complétion sur les
 * fiches déjà servies avec le formulaire : aucun appel par frappe). Une fois
 * la fiche choisie, l'écran reprend son nom et son email (`pick`).
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
        hint: 'Relié à sa fiche employé : nom et email repris, planning RH lu pour sa disponibilité.',
        icon: IdCard,
    },
    {
        value: 'EXTERNAL',
        label: 'Externe',
        hint: 'Personne sans fiche employé à la clinique : nom et email saisis ici.',
        icon: UserRound,
    },
];

const uid = useId();
const searchId = `staff-search-${uid}`;
const listId = `staff-results-${uid}`;
const optionId = (index) => `${listId}-${index}`;

const query = ref('');
const focused = ref(false);
const activeIndex = ref(-1);
const announcement = ref('');

const takenByOther = (employee) => Boolean(employee.account && employee.account.uuid !== props.currentUserUuid);
const selected = computed(() => props.employees.find((employee) => employee.uuid === props.employeeUuid) ?? null);
const freeCount = computed(() => props.employees.filter((employee) => ! takenByOther(employee)).length);

const searching = computed(() => query.value.trim() !== '');
// Sans rien taper, un aperçu court : la liste complète se trouve en cherchant.
const search = computed(() => searchStaff(props.employees, query.value, { isTaken: takenByOther, limit: searching.value ? 8 : 6 }));
const results = computed(() => search.value.results);
const panelOpen = computed(() => ! selected.value && (focused.value || searching.value));
const activeId = computed(() => (panelOpen.value && activeIndex.value >= 0 ? optionId(activeIndex.value) : undefined));

const firstFree = () => results.value.findIndex((employee) => ! takenByOther(employee));
watch(results, () => { activeIndex.value = firstFree(); }, { immediate: true });

const focusSearch = () => nextTick(() => document.getElementById(searchId)?.focus());

const chooseKind = (value) => {
    emit('update:kind', value);
    if (value === 'EXTERNAL') emit('update:employeeUuid', '');
    // Personnel : on cherche la personne tout de suite, sans second clic.
    if (value === 'STAFF' && ! selected.value && props.employees.length) focusSearch();
};

const pick = (employee) => {
    if (! employee || takenByOther(employee)) return;
    emit('update:employeeUuid', employee.uuid);
    emit('pick', employee);
    query.value = '';
    focused.value = false;
    announcement.value = `Fiche reliée : ${employee.name}, ${employee.employee_number}. Nom et email repris de la fiche.`;
};

const change = () => {
    emit('update:employeeUuid', '');
    announcement.value = '';
    focusSearch();
};

/** Le prochain résultat choisissable dans ce sens ; on reste en place s'il n'y en a pas. */
const move = (step) => {
    const count = results.value.length;
    if (! count) return;
    let index = activeIndex.value;
    for (let tries = 0; tries < count; tries += 1) {
        index = (index + step + count) % count;
        if (! takenByOther(results.value[index])) {
            activeIndex.value = index;
            document.getElementById(optionId(index))?.scrollIntoView({ block: 'nearest' });

            return;
        }
    }
};

const onKeydown = (event) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        focused.value = true;
        move(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
    } else if (event.key === 'Enter') {
        // Entrée relie la fiche active : elle ne soumet jamais le formulaire.
        event.preventDefault();
        if (activeIndex.value >= 0) pick(results.value[activeIndex.value]);
    } else if (event.key === 'Escape') {
        if (searching.value) {
            event.preventDefault();
            query.value = '';
        } else {
            focused.value = false;
        }
    }
};

const initials = (employee) => `${employee.first_name?.[0] ?? ''}${employee.last_name?.[0] ?? ''}`.toUpperCase()
    || employee.name?.slice(0, 2).toUpperCase()
    || 'EM';

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

        <!-- Personnel clinique : on cherche la personne, puis sa fiche est reliée. -->
        <div v-if="kind === 'STAFF'" :class="cn('rounded-xl border bg-card shadow-sm', errors.employee_uuid ? 'border-destructive/60' : 'border-border')">
            <!-- La fiche choisie. -->
            <div v-if="selected" class="flex flex-wrap items-center gap-3 p-3.5">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary text-sm font-bold text-primary-foreground" aria-hidden="true">{{ initials(selected) }}</span>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="truncate text-sm font-semibold text-foreground">{{ selected.name }}</span>
                        <Badge variant="success" class="gap-1"><Check class="h-3 w-3" :stroke-width="3" />Fiche RH reliée</Badge>
                    </p>
                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                        <span class="font-mono">{{ selected.employee_number }}</span>
                        <template v-if="selected.job_title"> · {{ selected.job_title }}</template>
                        <template v-if="selected.department"> · {{ selected.department }}</template>
                    </p>
                </div>
                <Button type="button" size="sm" variant="white-outline" class="shrink-0" @click="change">
                    <ArrowLeftRight class="h-3.5 w-3.5" />Changer
                </Button>
            </div>

            <!-- Aucune fiche en poste sur le site. -->
            <div v-else-if="! employees.length" class="px-3.5 py-6 text-center">
                <p class="text-sm font-semibold text-foreground">Aucune fiche employé en poste{{ siteName ? ` sur ${siteName}` : '' }}</p>
                <p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-muted-foreground">Créez d’abord la fiche de la personne dans Ressources humaines, puis revenez relier son compte.</p>
                <Link v-if="createEmployeeHref" :href="createEmployeeHref" class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
                    <Plus class="h-4 w-4" />Créer une fiche employé
                </Link>
            </div>

            <!-- La recherche. -->
            <div v-else class="space-y-2 p-3.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label :for="searchId" class="text-sm font-semibold text-foreground">Rechercher la personne</label>
                    <Badge variant="outline" class="tabular-nums">{{ freeCount }} fiche{{ freeCount > 1 ? 's' : '' }} libre{{ freeCount > 1 ? 's' : '' }}</Badge>
                </div>
                <div class="relative">
                    <IconInput
                        :id="searchId"
                        v-model="query"
                        :icon="Search"
                        type="text"
                        enterkeyhint="search"
                        role="combobox"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Nom, matricule, fonction ou service…"
                        aria-autocomplete="list"
                        :aria-expanded="panelOpen"
                        :aria-controls="listId"
                        :aria-activedescendant="activeId"
                        :aria-invalid="Boolean(errors.employee_uuid)"
                        class="pe-9"
                        @focus="focused = true"
                        @blur="focused = false"
                        @keydown="onKeydown"
                    />
                    <button v-if="query" type="button" class="absolute inset-y-0 end-0 z-10 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @mousedown.prevent @click="query = ''">
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <div v-if="panelOpen" class="overflow-hidden rounded-lg border border-border bg-popover shadow-md">
                    <p v-if="! searching" class="border-b border-border bg-muted/40 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ employees.length > results.length ? `Aperçu — tapez pour chercher parmi ${employees.length} fiches` : 'Fiches en poste' }}
                    </p>
                    <ul v-if="results.length" :id="listId" role="listbox" aria-label="Fiches employé" class="max-h-72 divide-y divide-border overflow-y-auto">
                        <li
                            v-for="(employee, index) in results"
                            :id="optionId(index)"
                            :key="employee.uuid"
                            role="option"
                            :aria-selected="index === activeIndex"
                            :aria-disabled="takenByOther(employee) || undefined"
                            :class="cn(
                                'flex cursor-pointer items-center gap-3 px-3 py-2.5 transition-colors',
                                index === activeIndex && 'bg-accent',
                                takenByOther(employee) && 'cursor-not-allowed opacity-60',
                            )"
                            @mousedown.prevent
                            @mousemove="! takenByOther(employee) && (activeIndex = index)"
                            @click="pick(employee)"
                        >
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground" aria-hidden="true">{{ initials(employee) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-foreground">
                                    <template v-for="(part, partIndex) in highlight(employee.name, query)" :key="partIndex"><mark v-if="part.match" class="rounded-[2px] bg-primary/15 text-foreground">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template>
                                </span>
                                <span class="block truncate text-xs text-muted-foreground">
                                    <span class="font-mono"><template v-for="(part, partIndex) in highlight(employee.employee_number, query)" :key="partIndex"><mark v-if="part.match" class="rounded-[2px] bg-primary/15 text-foreground">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                                    <template v-if="employee.job_title"> · {{ employee.job_title }}</template>
                                    <template v-if="employee.department"> · {{ employee.department }}</template>
                                </span>
                            </span>
                            <Badge v-if="takenByOther(employee)" variant="outline" class="shrink-0">Compte : {{ employee.account.name }}</Badge>
                            <span v-else-if="index === activeIndex" class="hidden shrink-0 items-center gap-1 text-[11px] font-medium text-muted-foreground sm:inline-flex" aria-hidden="true">
                                <CornerDownLeft class="h-3 w-3" />Relier
                            </span>
                        </li>
                    </ul>
                    <div v-else class="px-3 py-5 text-center">
                        <p class="text-sm text-muted-foreground">Aucune fiche ne correspond à « {{ query }} ».</p>
                        <Link v-if="createEmployeeHref" :href="createEmployeeHref" class="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline" @mousedown.prevent>
                            <Plus class="h-3.5 w-3.5" />Créer sa fiche employé
                        </Link>
                    </div>
                    <p v-if="searching && search.total > results.length" class="border-t border-border bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground">
                        {{ search.total - results.length }} autre{{ search.total - results.length > 1 ? 's' : '' }} — précisez la recherche.
                    </p>
                    <p class="hidden border-t border-border px-3 py-1.5 text-[11px] text-muted-foreground sm:block">↑ ↓ pour choisir · Entrée pour relier · Échap pour effacer</p>
                </div>
                <p v-else class="text-xs leading-5 text-muted-foreground">Le nom et l’email du compte seront repris de la fiche choisie.</p>
            </div>
            <FormError v-if="errors.employee_uuid" class="px-3.5 pb-3">{{ errors.employee_uuid }}</FormError>
        </div>
        <p v-else-if="kind === 'EXTERNAL'" class="text-xs leading-5 text-muted-foreground">
            Aucun planning RH ne vaut pour ce compte. S’il travaille un jour à la clinique, créez sa fiche puis modifiez ce compte pour la relier.
        </p>

        <p class="sr-only" aria-live="polite">{{ announcement }}</p>
    </section>
</template>
