<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Binary, Check, CircleAlert, FlaskConical, Layers, ListChecks, Ruler, Tag } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import EmployeeStepBar from '@/Components/Administration/EmployeeFile/EmployeeStepBar.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import AnalysisForm from '@/Components/Analyses/AnalysisForm.vue';
import { useAutosave } from '@/composables/useAutosave';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { analysisCatalogUrls, isPortalContext } from '@/utilities/analysisCatalogUrls';
import {
    LEVELS, adoptChildUuids, analysisFormFrom, analysisPayload, missingFields, missingSentence, stepOfField, stepsFor,
} from '@/utilities/analysisForm';

defineOptions({ layout: AppLayout });

/**
 * ADR-063, amendement du 2026-10-01 — la fiche d'une analyse, en étapes, sans
 * bouton « Enregistrer » : chaque modification part toute seule, par la même
 * route, les mêmes droits et la même validation qu'avant (au portail, par
 * l'API du site). Rien ne part tant qu'un champ obligatoire manque.
 */
const props = defineProps({
    clinicSite: Object,
    context: { type: Object, default: () => ({ mode: 'portal' }) },
    analysis: Object,
    catalogItems: Array,
    parents: Array,
    levels: Array,
    resultTypes: Array,
    entryModes: { type: Array, default: () => [] },
    examCategories: { type: Array, default: () => [] },
    initialStep: { type: String, default: 'identite' },
});

const portal = isPortalContext(props.context);
const urls = computed(() => analysisCatalogUrls(props.context, props.clinicSite.code));
const form = useForm(analysisFormFrom(props.analysis, { siteCode: portal ? props.clinicSite.code : null }));

const icons = { identite: Tag, resultat: Binary, normes: Ruler, 'sous-analyses': Layers, recap: ListChecks };
const steps = computed(() => stepsFor(form.level).map((step) => ({ ...step, icon: icons[step.key] })));
const current = ref(steps.value.some((step) => step.key === props.initialStep) ? props.initialStep : 'identite');
// Un groupe redevenu analyse simple perd son étape Sous-analyses.
watch(steps, (list) => {
    if (! list.some((step) => step.key === current.value)) current.value = 'identite';
});

const index = computed(() => steps.value.findIndex((step) => step.key === current.value));
const previous = computed(() => steps.value[index.value - 1] ?? null);
const next = computed(() => steps.value[index.value + 1] ?? null);

/* ------------------------------------------------------------------ */
/* Enregistrement automatique                                          */
/* ------------------------------------------------------------------ */

const missing = computed(() => missingFields(form));
const ready = computed(() => missing.value.length === 0);

const send = (options) => form
    .transform((data) => ({ ...analysisPayload(data), _autosave: true }))
    .put(urls.value.update(props.analysis.uuid), { ...options, only: ['analysis'] });
const { saving, savedAt, failed, flush, retry } = useAutosave(form, send, { enabled: () => ready.value, delay: 1000 });

// Une sous-analyse nouvelle reçoit son UUID au retour : sans lui, l'enregistrement
// suivant la recréerait (et serait refusé, son code étant pris).
watch(() => props.analysis, (saved) => { adoptChildUuids(form.children, saved?.children ?? []); });

const errorSteps = computed(() => new Set(Object.keys(form.errors ?? {}).map(stepOfField).filter(Boolean)));
const stateOf = (key) => {
    if (errorSteps.value.has(key)) return 'failed';
    if (missing.value.some((item) => item.step === key)) return 'incomplete';

    return 'idle';
};

/* ------------------------------------------------------------------ */
/* Étapes                                                              */
/* ------------------------------------------------------------------ */

const go = (key) => {
    current.value = key;
    if (typeof window !== 'undefined') {
        const url = new URL(window.location.href);
        url.searchParams.set('etape', key);
        window.history.replaceState(window.history.state, '', url);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
};

const firstProblemStep = () => missing.value[0]?.step ?? [...errorSteps.value][0] ?? null;
const attention = ref('');
// « Continuer » laisse partir ce qui attend, puis passe ; il retient une étape
// qui manque de quelque chose et dit pourquoi.
const continueTo = () => {
    attention.value = '';
    const blocking = missing.value.filter((item) => item.step === current.value);
    if (blocking.length) {
        attention.value = `À remplir avant de continuer : ${missingSentence(blocking)}.`;
        return;
    }
    flush(() => next.value && go(next.value.key));
};

const finishing = ref(false);
const finish = () => {
    attention.value = '';
    const problem = firstProblemStep();
    if (problem) {
        go(problem);
        attention.value = missing.value.length ? `À remplir : ${missingSentence(missing.value)}.` : 'Corrigez le champ signalé : il n’a pas été enregistré.';
        return;
    }
    finishing.value = true;
    flush(() => router.visit(urls.value.index), () => { finishing.value = false; });
};

/* ------------------------------------------------------------------ */
/* Quitter                                                             */
/* ------------------------------------------------------------------ */

const pending = computed(() => form.isDirty || saving.value || failed.value);
const { pendingVisit, leave, stay } = useUnsavedChangesGuard(pending);
const blocked = ref(false);
watch(pendingVisit, (visit) => {
    if (! visit) return;
    if (ready.value && ! failed.value) {
        flush(leave, () => { blocked.value = true; });
        return;
    }
    blocked.value = true;
});
const stayHere = () => {
    blocked.value = false;
    stay();
    const problem = firstProblemStep();
    if (problem) go(problem);
};
const leaveAnyway = () => {
    blocked.value = false;
    leave();
};

const status = computed(() => (ready.value ? null : `À compléter : ${missingSentence(missing.value)}.`));
</script>

<template>
    <Head :title="`Analyse · ${analysis.designation}`" />
    <div class="w-full space-y-5 pb-24">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><FlaskConical class="h-5 w-5" /></span>
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ portal ? `Super Administration · ${clinicSite.name}` : 'Administration · Catalogue des analyses' }}</p>
                    <h1 class="mt-0.5 truncate font-heading text-2xl font-bold text-foreground">{{ form.designation || analysis.designation }}</h1>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <Badge variant="outline" class="font-mono">{{ form.code || analysis.code }}</Badge>
                        <Badge variant="secondary">{{ LEVELS[form.level]?.label ?? form.level }}</Badge>
                        <Badge v-if="analysis.is_active === false" tone="warning">Désactivée</Badge>
                        <span v-if="analysis.hierarchy_path && analysis.hierarchy_path !== analysis.designation" class="truncate text-xs text-muted-foreground">{{ analysis.hierarchy_path }}</span>
                    </div>
                </div>
            </div>
            <Button :as="Link" :href="urls.index" variant="white-outline"><ArrowLeft class="h-4 w-4" />Catalogue</Button>
        </header>

        <EmployeeStepBar :steps="steps" :current="current" :state-of="stateOf" nav-label="Étapes de la fiche d’analyse" @select="go" />

        <ValidationErrorSummary :errors="form.errors" />

        <AnalysisForm
            :form="form"
            :step="current"
            :analysis-uuid="analysis.uuid"
            :hierarchy-path="analysis.hierarchy_path"
            :catalog-items="catalogItems"
            :parents="parents"
            :levels="levels"
            :result-types="resultTypes"
            :entry-modes="entryModes"
            :exam-categories="examCategories"
            @go="go"
        />

        <Card class="sticky bottom-3 z-10 flex flex-col gap-3 p-3 shadow-lg sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 text-sm">
                <p v-if="attention" class="flex items-center gap-2 font-medium text-amber-700 dark:text-amber-400"><CircleAlert class="h-4 w-4 shrink-0" />{{ attention }}</p>
                <p v-else-if="status" class="flex items-center gap-2 text-amber-700 dark:text-amber-400"><CircleAlert class="h-4 w-4 shrink-0" /><span class="truncate">{{ status }} Rien n’est enregistré tant qu’il manque.</span></p>
                <ClinicalSaveStatus v-else :saving="saving" :saved-at="savedAt" :dirty="form.isDirty" :failed="failed" retryable @retry="retry" />
                <p v-if="! attention && ! status && ! saving && ! form.isDirty && ! failed && ! savedAt" class="text-xs text-muted-foreground">Chaque modification s’enregistre toute seule.</p>
            </div>
            <div class="flex shrink-0 justify-end gap-2">
                <Button v-if="previous" type="button" variant="white-outline" @click="go(previous.key)"><ArrowLeft class="h-4 w-4" />Précédent</Button>
                <Button v-if="next" type="button" :disabled="saving" @click="continueTo">Continuer · {{ next.label }}<ArrowRight class="h-4 w-4" /></Button>
                <Button v-else type="button" :disabled="finishing" @click="finish"><Check class="h-4 w-4" />{{ finishing ? 'Enregistrement…' : 'Terminer' }}</Button>
            </div>
        </Card>

        <ConfirmModal
            :open="blocked"
            tone="warning"
            title="Des modifications ne sont pas enregistrées"
            :description="status ? `${status} Rien n’a été enregistré depuis.` : 'Le dernier enregistrement a été refusé : corrigez le champ signalé, ou quittez sans enregistrer.'"
            confirm-label="Quitter sans enregistrer"
            cancel-label="Rester et corriger"
            @update:open="(value) => { if (! value) stayHere(); }"
            @confirm="leaveAnyway"
        />
    </div>
</template>
