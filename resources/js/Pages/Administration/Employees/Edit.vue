<script setup>
import { computed, nextTick, onMounted, provide, reactive, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Briefcase,
    CircleAlert,
    CircleCheck,
    CircleDashed,
    Contact,
    Eye,
    Gift,
    Landmark,
    ListPlus,
    Loader2,
    User,
    Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import BankSection from '@/Components/Administration/EmployeeFile/BankSection.vue';
import BenefitsSection from '@/Components/Administration/EmployeeFile/BenefitsSection.vue';
import ContactSection from '@/Components/Administration/EmployeeFile/ContactSection.vue';
import IdentitySection from '@/Components/Administration/EmployeeFile/IdentitySection.vue';
import MoreSection from '@/Components/Administration/EmployeeFile/MoreSection.vue';
import PaySection from '@/Components/Administration/EmployeeFile/PaySection.vue';
import PostSection from '@/Components/Administration/EmployeeFile/PostSection.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { cn } from '@/lib/cn';
import { formatTime } from '@/utilities/date';
import { hrUrl } from '@/utilities/hrUrl';
import HrPageHeader from '../Partials/HrPageHeader.vue';

/**
 * ADR-213 — la fiche d'un employé en sections, chacune enregistrée toute seule
 * environ une seconde après la dernière saisie : plus de parcours en étapes ni
 * de bouton « Enregistrer ». On passe directement d'une section à l'autre.
 *
 * Chaque section n'envoie que ses champs (mêmes droits, même validation, même
 * audit que l'ancien formulaire) : une erreur dans l'une n'empêche pas les
 * autres de s'enregistrer. Les sections restent montées (v-show) pour que leur
 * enregistrement continue quand on change de section.
 */
defineOptions({ layout: AppLayout });
const props = defineProps({
    employee: Object, options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object],
    // ADR-194 — le couple département/fonction déjà enregistré reste choisissable.
    currentPair: { type: Object, default: null },
    // ADR-206 — la rémunération et le compte bancaire, servis avec leur droit.
    payroll: { type: Object, default: null },
    // ADR-213 — le module Banques et les avantages de la personne.
    banks: { type: Array, default: () => [] },
    benefits: { type: Array, default: null },
    benefitOptions: { type: Object, default: null },
    section: { type: String, default: '' },
});
const { can } = usePermissions();

// La même adresse que l'ancien formulaire : un PUT partiel par section.
const url = hrUrl(`/administration/employees/${props.employee.uuid}`);
const canEdit = computed(() => can('employees.update'));
const canEditPayroll = computed(() => canEdit.value && can('employees.payroll.update'));

/* ------------------------------------------------------------------ */
/* Les sections et leur état d'enregistrement                          */
/* ------------------------------------------------------------------ */

const SECTIONS = [
    { key: 'identity', label: 'Identité', hint: 'Nom, genre, naissance, photo', icon: User },
    { key: 'post', label: 'Poste', hint: 'Matricule, service, fonction', icon: Briefcase },
    { key: 'contact', label: 'Contact', hint: 'Téléphone, adresse, pièce', icon: Contact },
    { key: 'more', label: 'Famille et qualification', hint: 'Famille, diplôme, matériel', icon: ListPlus },
    { key: 'pay', label: 'Rémunération', hint: 'Salaire ou indemnité', icon: Wallet, payroll: true },
    { key: 'bank', label: 'Banque', hint: 'Banque et compte', icon: Landmark, payroll: true },
    { key: 'benefits', label: 'Avantages et primes', hint: 'Montant et motif', icon: Gift, payroll: true },
];
const sections = computed(() => SECTIONS.filter((section) => ! section.payroll || props.payroll !== null));

const registry = reactive({});
provide('employeeSections', {
    register: (key, api) => { registry[key] = api; },
    unregister: (key) => { delete registry[key]; },
});

/** Les enregistrements d'une section (une carte d'avantage en est une à part). */
const entriesOf = (key) => Object.entries(registry)
    .filter(([name]) => name === key || (key === 'benefits' && name.startsWith('benefit:')))
    .map(([, api]) => api);
const RANK = { failed: 5, incomplete: 4, saving: 3, dirty: 2, saved: 1, idle: 0 };
const stateOf = (key) => entriesOf(key).reduce((worst, api) => (RANK[api.state] > RANK[worst] ? api.state : worst), 'idle');

const allStates = computed(() => sections.value.map((section) => stateOf(section.key)));
const overall = computed(() => allStates.value.reduce((worst, state) => (RANK[state] > RANK[worst] ? state : worst), 'idle'));
const lastSavedAt = computed(() => Object.values(registry).map((api) => api.savedAt).filter(Boolean).sort().at(-1) ?? null);

const STATE_ICONS = {
    failed: { icon: CircleAlert, tone: 'text-destructive', label: 'Échec de l’enregistrement' },
    incomplete: { icon: CircleDashed, tone: 'text-amber-600 dark:text-amber-400', label: 'À compléter' },
    saving: { icon: Loader2, tone: 'text-muted-foreground animate-spin', label: 'Enregistrement…' },
    dirty: { icon: CircleDashed, tone: 'text-amber-600 dark:text-amber-400', label: 'Modifications en attente' },
    saved: { icon: CircleCheck, tone: 'text-emerald-600 dark:text-emerald-400', label: 'Enregistré' },
};

/* ------------------------------------------------------------------ */
/* Navigation                                                          */
/* ------------------------------------------------------------------ */

const initial = sections.value.some((section) => section.key === props.section) ? props.section : 'identity';
const current = ref(initial);
const open = (key) => {
    current.value = key;
    nextTick(() => document.getElementById('employee-sections')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
};
// L'adresse garde la section ouverte : un retour ou un rechargement y ramène.
watch(current, (key) => {
    const target = new URL(window.location.href);
    target.searchParams.set('section', key);
    window.history.replaceState(window.history.state, '', target);
});
onMounted(() => {
    if (initial !== 'identity') nextTick(() => document.getElementById('employee-sections')?.scrollIntoView({ block: 'start' }));
});

/* ------------------------------------------------------------------ */
/* Quitter : tout ce qui attend part d'abord                           */
/* ------------------------------------------------------------------ */

const pending = computed(() => allStates.value.some((state) => ['dirty', 'saving', 'incomplete', 'failed'].includes(state)));
const { pendingVisit, leave, stay } = useUnsavedChangesGuard(pending);
const blocked = ref(false);

const flushAll = () => new Promise((resolve) => {
    const waiting = Object.values(registry).filter((api) => api.state === 'dirty');
    let left = waiting.length;
    let ok = true;

    if (! left) {
        resolve(true);
        return;
    }
    waiting.forEach((api) => api.flush(
        () => { left -= 1; if (! left) resolve(ok); },
        () => { ok = false; left -= 1; if (! left) resolve(ok); },
    ));
});

// Des modifications seulement en attente partent, puis on s'en va ; une saisie
// incomplète ou refusée demande à la personne ce qu'elle veut faire.
watch(pendingVisit, async (visit) => {
    if (! visit) return;
    const blocking = allStates.value.some((state) => ['incomplete', 'failed'].includes(state));
    if (! blocking && (await flushAll())) {
        leave();
        return;
    }
    blocked.value = true;
});
const stayHere = () => {
    blocked.value = false;
    stay();
    const firstProblem = sections.value.find((section) => ['incomplete', 'failed'].includes(stateOf(section.key)));
    if (firstProblem) open(firstProblem.key);
};
const leaveAnyway = () => {
    blocked.value = false;
    leave();
};
</script>

<template>
    <Head :title="`Fiche de ${employee.name}`" />
    <div class="w-full space-y-4">
        <HrPageHeader compact :eyebrow="`${employee.employee_number} · Fiche du personnel`" :title="employee.name" description="Chaque section s’enregistre toute seule, une seconde après votre dernière saisie. Passez directement d’une section à l’autre." icon="edit">
            <template #actions>
                <p class="flex min-h-9 items-center gap-1.5 text-xs font-medium" role="status" aria-live="polite">
                    <template v-if="overall === 'saving'"><Loader2 class="h-4 w-4 animate-spin text-muted-foreground" /><span class="text-muted-foreground">Enregistrement…</span></template>
                    <template v-else-if="overall === 'failed'"><CircleAlert class="h-4 w-4 text-destructive" /><span class="text-destructive">Une section n’a pas pu s’enregistrer</span></template>
                    <template v-else-if="overall === 'incomplete'"><CircleDashed class="h-4 w-4 text-amber-600" /><span class="text-amber-700 dark:text-amber-400">Une section est à compléter</span></template>
                    <template v-else-if="overall === 'dirty'"><CircleDashed class="h-4 w-4 text-amber-600" /><span class="text-muted-foreground">Modifications en attente…</span></template>
                    <template v-else-if="lastSavedAt"><CircleCheck class="h-4 w-4 text-emerald-600" /><span class="text-emerald-700 dark:text-emerald-400">Tout est enregistré · {{ formatTime(lastSavedAt) }}</span></template>
                    <template v-else><CircleCheck class="h-4 w-4 text-muted-foreground" /><span class="text-muted-foreground">Enregistrement automatique</span></template>
                </p>
                <Button :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}`)" variant="outline" size="sm"><Eye class="h-4 w-4" />Voir la fiche</Button>
                <Button :as="Link" :href="hrUrl('/administration/employees')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" />Employés</Button>
            </template>
        </HrPageHeader>

        <div id="employee-sections" class="grid scroll-mt-3 gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <!-- Les sections : un clic, pas de « Continuer ». -->
            <nav aria-label="Sections de la fiche" class="min-w-0 lg:sticky lg:top-3 lg:self-start">
                <Card class="p-2">
                    <div class="mb-2 hidden items-center gap-2.5 border-b border-border px-2 pb-2.5 pt-1 lg:flex">
                        <EmployeePhoto :src="employee.photo_url" :name="employee.name" size="sm" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-foreground">{{ employee.name }}</p>
                            <p class="truncate text-[11px] text-muted-foreground">{{ employee.job_title || 'Fonction à définir' }}</p>
                        </div>
                    </div>
                    <ul class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible" role="tablist" aria-orientation="vertical">
                        <li v-for="section in sections" :key="section.key" class="shrink-0">
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="current === section.key"
                                :aria-controls="`section-${section.key}`"
                                :class="cn(
                                    'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    current === section.key ? 'bg-primary/10 text-primary' : 'text-foreground hover:bg-accent/60',
                                )"
                                @click="open(section.key)"
                            >
                                <component :is="section.icon" class="h-4 w-4 shrink-0" />
                                <span class="min-w-0 flex-1">
                                    <span class="block whitespace-nowrap text-sm font-semibold lg:truncate">{{ section.label }}</span>
                                    <span class="hidden truncate text-[11px] text-muted-foreground lg:block">{{ section.hint }}</span>
                                </span>
                                <component
                                    :is="STATE_ICONS[stateOf(section.key)].icon"
                                    v-if="STATE_ICONS[stateOf(section.key)]"
                                    :class="cn('h-3.5 w-3.5 shrink-0', STATE_ICONS[stateOf(section.key)].tone)"
                                    :aria-label="STATE_ICONS[stateOf(section.key)].label"
                                />
                            </button>
                        </li>
                    </ul>
                </Card>
            </nav>

            <div class="min-w-0">
                <div id="section-identity" v-show="current === 'identity'" role="tabpanel">
                    <IdentitySection :employee="employee" :options="options" :url="url" :can-edit="canEdit" />
                </div>
                <div id="section-post" v-show="current === 'post'" role="tabpanel">
                    <PostSection :employee="employee" :departments="departments" :job-titles="jobTitles" :current-pair="currentPair" :url="url" :can-edit="canEdit" />
                </div>
                <div id="section-contact" v-show="current === 'contact'" role="tabpanel">
                    <ContactSection :employee="employee" :options="options" :addresses="addresses" :url="url" :can-edit="canEdit" />
                </div>
                <div id="section-more" v-show="current === 'more'" role="tabpanel">
                    <MoreSection :employee="employee" :options="options" :url="url" :can-edit="canEdit" />
                </div>
                <template v-if="payroll !== null">
                    <div id="section-pay" v-show="current === 'pay'" role="tabpanel">
                        <PaySection :payroll="payroll" :url="url" :can-edit="canEditPayroll" />
                    </div>
                    <div id="section-bank" v-show="current === 'bank'" role="tabpanel">
                        <BankSection :employee="employee" :payroll="payroll" :banks="banks" :url="url" :can-edit="canEditPayroll" />
                    </div>
                    <div v-if="benefitOptions" id="section-benefits" v-show="current === 'benefits'" role="tabpanel">
                        <BenefitsSection :benefits="benefits ?? []" :options="benefitOptions" :url="`${url}/benefits`" :can-edit="canEditPayroll" />
                    </div>
                </template>
            </div>
        </div>

        <!-- Quitter avec une saisie à compléter ou refusée. -->
        <Dialog
            :open="blocked"
            title="Une modification n’est pas enregistrée"
            description="Une section est à compléter ou a été refusée. Si vous quittez maintenant, cette modification sera perdue."
            :dismissible="false"
            @update:open="(value) => value || stayHere()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300"><CircleAlert class="h-5 w-5" /></span>
            </template>
            <template #footer>
                <Button type="button" variant="outline" @click="leaveAnyway">Quitter sans enregistrer</Button>
                <Button type="button" @click="stayHere">Rester et compléter</Button>
            </template>
        </Dialog>
    </div>
</template>
