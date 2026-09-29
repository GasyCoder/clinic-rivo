<script setup>
import { computed, nextTick, onMounted, provide, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CircleAlert,
    CircleCheck,
    CircleDashed,
    Eye,
    Loader2,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import BankSection from '@/Components/Administration/EmployeeFile/BankSection.vue';
import BenefitsSection from '@/Components/Administration/EmployeeFile/BenefitsSection.vue';
import ContactSection from '@/Components/Administration/EmployeeFile/ContactSection.vue';
import EmployeeRecap from '@/Components/Administration/EmployeeFile/EmployeeRecap.vue';
import EmployeeStepBar from '@/Components/Administration/EmployeeFile/EmployeeStepBar.vue';
import IdentitySection from '@/Components/Administration/EmployeeFile/IdentitySection.vue';
import MoreSection from '@/Components/Administration/EmployeeFile/MoreSection.vue';
import PaySection from '@/Components/Administration/EmployeeFile/PaySection.vue';
import PostSection from '@/Components/Administration/EmployeeFile/PostSection.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { formatTime } from '@/utilities/date';
import { employeeSteps, neighbourStep } from '@/utilities/employeeSteps';
import { hrUrl } from '@/utilities/hrUrl';
import HrPageHeader from '../Partials/HrPageHeader.vue';

/**
 * ADR-221 — le dossier d'un employé en parcours à étapes, comme à la création,
 * mais chaque étape s'enregistre toute seule environ une seconde après la
 * dernière saisie. « Continuer » enregistre ce qui reste de l'étape, puis passe
 * à la suivante ; on peut aussi cliquer directement sur une étape.
 *
 * Chaque étape n'envoie que ses champs (mêmes droits, même validation, même
 * audit) : une erreur dans l'une n'empêche pas les autres de s'enregistrer. Les
 * étapes restent montées (v-show) pour que leur enregistrement continue quand
 * on passe à la suivante.
 */
defineOptions({ layout: AppLayout });
const props = defineProps({
    employee: Object, options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object],
    // ADR-194 — le couple département/fonction déjà enregistré reste choisissable.
    currentPair: { type: Object, default: null },
    // ADR-206 — la rémunération et le compte bancaire, servis avec leur droit.
    payroll: { type: Object, default: null },
    // ADR-221 — le module Banques et les avantages de la personne.
    banks: { type: Array, default: () => [] },
    benefits: { type: Array, default: null },
    benefitOptions: { type: Object, default: null },
    section: { type: String, default: '' },
    // ADR-194 — « Nouveau stagiaire » : au bout du parcours, son stage.
    internshipIntent: { type: Boolean, default: false },
});
const { can } = usePermissions();

// La même adresse que l'ancien formulaire : un PUT partiel par étape.
const url = hrUrl(`/administration/employees/${props.employee.uuid}`);
const canEdit = computed(() => can('employees.update'));
const canEditPayroll = computed(() => canEdit.value && can('employees.payroll.update'));

/* ------------------------------------------------------------------ */
/* Les étapes et leur état d'enregistrement                            */
/* ------------------------------------------------------------------ */

const steps = computed(() => employeeSteps({ payroll: props.payroll !== null && props.benefitOptions !== null }));

const registry = reactive({});
provide('employeeSections', {
    register: (key, api) => { registry[key] = api; },
    unregister: (key) => { delete registry[key]; },
});

/** Les enregistrements d'une étape : ses champs, et ce qui s'y ajoute (une carte d'avantage, une adresse). */
const entriesOf = (key) => Object.entries(registry)
    .filter(([name]) => name === key || name.startsWith(`${key}:`) || (key === 'benefits' && name.startsWith('benefit:')))
    .map(([, api]) => api);
const RANK = { failed: 5, incomplete: 4, saving: 3, dirty: 2, saved: 1, idle: 0 };
const worst = (states) => states.reduce((current, state) => (RANK[state] > RANK[current] ? state : current), 'idle');
const stateOf = (key) => worst(entriesOf(key).map((api) => api.state));

const allStates = computed(() => steps.value.map((step) => stateOf(step.key)));
const overall = computed(() => worst(allStates.value));
const lastSavedAt = computed(() => Object.values(registry).map((api) => api.savedAt).filter(Boolean).sort().at(-1) ?? null);

/* ------------------------------------------------------------------ */
/* Navigation                                                          */
/* ------------------------------------------------------------------ */

const initial = steps.value.some((step) => step.key === props.section) ? props.section : 'identity';
const current = ref(initial);
const attention = ref('');
const scrollToWizard = (smooth = true) => nextTick(() => document.getElementById('employee-wizard')?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' }));
const open = (key) => {
    current.value = key;
    attention.value = '';
    scrollToWizard();
};
// L'adresse garde l'étape ouverte : un retour ou un rechargement y ramène.
watch(current, (key) => {
    const target = new URL(window.location.href);
    target.searchParams.set('section', key);
    window.history.replaceState(window.history.state, '', target);
});
onMounted(() => {
    if (initial !== 'identity') scrollToWizard(false);
});

const previous = computed(() => neighbourStep(steps.value, current.value, -1));
const next = computed(() => neighbourStep(steps.value, current.value, 1));
const currentMeta = computed(() => steps.value.find((step) => step.key === current.value));

/** Attend qu'aucun enregistrement de l'étape ne soit en route. */
const whileSaving = (key) => new Promise((resolve) => {
    const busy = () => entriesOf(key).some((api) => api.state === 'saving');
    if (! busy()) {
        resolve();
        return;
    }
    const stop = watch(busy, (value) => {
        if (value) return;
        stop();
        resolve();
    });
});

/** Envoie tout de suite ce que l'étape attend encore ; vrai si tout est passé. */
const settle = async (key) => {
    await whileSaving(key);
    const waiting = entriesOf(key).filter((api) => api.state === 'dirty');
    const results = await Promise.all(waiting.map((api) => new Promise((resolve) => {
        api.flush(() => resolve(true), () => resolve(false));
    })));
    await whileSaving(key);

    return results.every(Boolean) && ! ['incomplete', 'failed'].includes(stateOf(key));
};

const advancing = ref(false);
const blockedMessage = (key) => (stateOf(key) === 'failed'
    ? 'Cette étape n’a pas pu s’enregistrer : corrigez ce qui est indiqué, puis continuez.'
    : 'Cette étape n’est pas complète : terminez la saisie indiquée (ou annulez-la), puis continuez.');

/** « Continuer » : l'étape s'enregistre d'abord, puis on passe à la suivante. */
const goNext = async () => {
    if (advancing.value || ! next.value) return;
    advancing.value = true;
    const ok = await settle(current.value);
    advancing.value = false;
    if (! ok) {
        attention.value = blockedMessage(current.value);
        scrollToWizard();
        return;
    }
    open(next.value);
};
const goPrevious = () => previous.value && open(previous.value);

/* ------------------------------------------------------------------ */
/* Terminer, ou quitter : tout ce qui attend part d'abord              */
/* ------------------------------------------------------------------ */

const pending = computed(() => allStates.value.some((state) => ['dirty', 'saving', 'incomplete', 'failed'].includes(state)));
const { pendingVisit, leave, stay } = useUnsavedChangesGuard(pending);
const blocked = ref(false);

const flushAll = async () => {
    const results = await Promise.all(steps.value.map((step) => settle(step.key)));
    return results.every(Boolean);
};

// Des modifications seulement en attente partent, puis on s'en va ; une saisie
// incomplète ou refusée demande à la personne ce qu'elle veut faire.
watch(pendingVisit, async (visit) => {
    if (! visit) return;
    if (await flushAll()) {
        leave();
        return;
    }
    blocked.value = true;
});
const firstProblem = () => steps.value.find((step) => ['incomplete', 'failed'].includes(stateOf(step.key)));
const stayHere = () => {
    blocked.value = false;
    stay();
    const problem = firstProblem();
    if (problem) {
        open(problem.key);
        attention.value = blockedMessage(problem.key);
    }
};
const leaveAnyway = () => {
    blocked.value = false;
    leave();
};

const finishUrl = computed(() => (props.internshipIntent
    ? hrUrl(`/administration/contracts/create?employee=${props.employee.uuid}&type=stage`)
    : hrUrl(`/administration/employees/${props.employee.uuid}`)));
const finishing = ref(false);
const finish = async () => {
    if (finishing.value) return;
    finishing.value = true;
    const ok = await flushAll();
    finishing.value = false;
    if (! ok) {
        const problem = firstProblem();
        if (problem) {
            open(problem.key);
            attention.value = blockedMessage(problem.key);
        }
        return;
    }
    router.visit(finishUrl.value);
};
</script>

<template>
    <Head :title="`Fiche de ${employee.name}`" />
    <div class="w-full space-y-4">
        <HrPageHeader compact :eyebrow="`${employee.employee_number} · Dossier personnel · Parcours guidé`" :title="employee.name" description="Avancez étape par étape : chaque étape s’enregistre toute seule, une seconde après votre dernière saisie." icon="edit">
            <template #actions>
                <Button :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}`)" variant="outline" size="sm"><Eye class="h-4 w-4" />Voir la fiche</Button>
                <Button :as="Link" :href="hrUrl('/administration/employees')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" />Employés</Button>
            </template>
        </HrPageHeader>

        <div id="employee-wizard" class="scroll-mt-3 space-y-3">
            <EmployeeStepBar :steps="steps" :current="current" :state-of="stateOf" @select="open" />

            <p v-if="attention" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
                <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ attention }}
            </p>

            <div class="min-w-0">
                <div id="section-identity" v-show="current === 'identity'">
                    <IdentitySection :employee="employee" :options="options" :url="url" :can-edit="canEdit" />
                </div>
                <div id="section-contact" v-show="current === 'contact'">
                    <ContactSection :employee="employee" :options="options" :addresses="addresses" :url="url" :can-edit="canEdit" />
                </div>
                <div id="section-post" v-show="current === 'post'">
                    <PostSection :employee="employee" :departments="departments" :job-titles="jobTitles" :current-pair="currentPair" :url="url" :can-edit="canEdit" />
                </div>
                <div id="section-more" v-show="current === 'more'">
                    <MoreSection :employee="employee" :options="options" :url="url" :can-edit="canEdit" />
                </div>
                <template v-if="payroll !== null && benefitOptions !== null">
                    <div id="section-pay" v-show="current === 'pay'">
                        <PaySection :payroll="payroll" :url="url" :can-edit="canEditPayroll" />
                    </div>
                    <div id="section-benefits" v-show="current === 'benefits'">
                        <BenefitsSection :benefits="benefits ?? []" :options="benefitOptions" :url="`${url}/benefits`" :can-edit="canEditPayroll" />
                    </div>
                    <div id="section-bank" v-show="current === 'bank'">
                        <BankSection :employee="employee" :payroll="payroll" :banks="banks" :url="url" :can-edit="canEditPayroll" />
                    </div>
                </template>
                <div id="section-done" v-show="current === 'done'">
                    <EmployeeRecap :steps="steps" :employee="employee" :payroll="payroll" :benefits="benefits" :state-of="stateOf" @edit="open" />
                </div>
            </div>

            <footer class="sticky bottom-2 z-10 flex items-center justify-between gap-2 rounded-xl border border-border bg-card/95 p-2 shadow-lg backdrop-blur">
                <p class="flex min-h-8 min-w-0 items-center gap-1.5 px-1 text-xs font-medium [&>span]:truncate" role="status" aria-live="polite">
                    <template v-if="overall === 'saving' || advancing || finishing"><Loader2 class="h-4 w-4 animate-spin text-muted-foreground" /><span class="text-muted-foreground">Enregistrement…</span></template>
                    <template v-else-if="overall === 'failed'"><CircleAlert class="h-4 w-4 text-destructive" /><span class="text-destructive">Une étape n’a pas pu s’enregistrer</span></template>
                    <template v-else-if="overall === 'incomplete'"><CircleDashed class="h-4 w-4 text-amber-600" /><span class="text-amber-700 dark:text-amber-400">Une étape est à compléter</span></template>
                    <template v-else-if="overall === 'dirty'"><CircleDashed class="h-4 w-4 text-amber-600" /><span class="text-muted-foreground">Modifications en attente…</span></template>
                    <template v-else-if="lastSavedAt"><CircleCheck class="h-4 w-4 text-emerald-600" /><span class="text-emerald-700 dark:text-emerald-400">Tout est enregistré · {{ formatTime(lastSavedAt) }}</span></template>
                    <template v-else><CircleCheck class="h-4 w-4 text-muted-foreground" /><span class="text-muted-foreground">Enregistrement automatique</span></template>
                </p>
                <div class="flex shrink-0 items-center justify-end gap-2">
                    <Button v-if="previous" type="button" variant="outline" size="sm" :aria-label="`Précédent : ${steps.find((step) => step.key === previous)?.label}`" @click="goPrevious"><ArrowLeft class="h-4 w-4" /><span class="hidden sm:inline">Précédent</span></Button>
                    <Button v-if="next" type="button" size="sm" :disabled="advancing" @click="goNext">
                        Continuer<span class="hidden sm:inline">· {{ steps.find((step) => step.key === next)?.label }}</span><ArrowRight class="h-4 w-4" />
                    </Button>
                    <Button v-else type="button" variant="success" size="sm" :disabled="finishing" @click="finish">
                        <Check class="h-4 w-4" :stroke-width="3" />{{ internshipIntent ? 'Terminer et saisir le stage' : 'Terminer' }}
                    </Button>
                </div>
            </footer>
            <p class="sr-only" aria-live="polite">Étape {{ currentMeta?.number }} sur {{ steps.length }} : {{ currentMeta?.label }}</p>
        </div>

        <!-- Quitter avec une saisie à compléter ou refusée. -->
        <Dialog
            :open="blocked"
            title="Une modification n’est pas enregistrée"
            description="Une étape est à compléter ou a été refusée. Si vous quittez maintenant, cette modification sera perdue."
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
