<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Binary, CircleAlert, FlaskConical, Layers, ListChecks, Ruler, Tag } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import EmployeeStepBar from '@/Components/Administration/EmployeeFile/EmployeeStepBar.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import AnalysisForm from '@/Components/Analyses/AnalysisForm.vue';
import { analysisCatalogUrls, isPortalContext } from '@/utilities/analysisCatalogUrls';
import { analysisPayload, missingFields, missingSentence, newAnalysisForm, stepsFor } from '@/utilities/analysisForm';

defineOptions({ layout: AppLayout });

/**
 * ADR-063, amendement du 2026-10-01 — créer une analyse ne demande que son
 * identité : la fiche n'existe qu'une fois créée, puis chaque étape s'y
 * enregistre toute seule. Les autres étapes se voient, verrouillées.
 */
const props = defineProps({
    clinicSite: Object,
    context: { type: Object, default: () => ({ mode: 'portal' }) },
    catalogItems: Array,
    parents: Array,
    levels: Array,
    resultTypes: Array,
    entryModes: { type: Array, default: () => [] },
    disciplines: { type: Array, default: () => [] },
});

const portal = isPortalContext(props.context);
// ADR-238 — nommer une nouvelle discipline demande ce droit (revérifié par le serveur).
const canCreateDiscipline = computed(() => (usePage().props.permissions ?? []).includes('lab_disciplines.create'));
const urls = computed(() => analysisCatalogUrls(props.context, props.clinicSite.code));

// Aucune prestation d'office : proposer la première venue y rangerait l'analyse
// sans que personne l'ait choisie.
const form = useForm(newAnalysisForm({ siteCode: portal ? props.clinicSite.code : null }));

const icons = { identite: Tag, resultat: Binary, normes: Ruler, 'sous-analyses': Layers, recap: ListChecks };
const steps = computed(() => stepsFor(form.level).map((step) => ({ ...step, icon: icons[step.key] })));
// Le type de résultat a sa valeur par défaut : seule l'identité est à remplir ici.
const missing = computed(() => missingFields(form).filter((item) => item.step === 'identite'));

const create = () => {
    if (missing.value.length || form.processing) return;
    form.transform((data) => ({ ...analysisPayload(data), after: 'edit' })).post(urls.value.store, { preserveScroll: true });
};
</script>

<template>
    <Head title="Nouvelle analyse" />
    <form class="w-full space-y-5 pb-24" @submit.prevent="create">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><FlaskConical class="h-5 w-5" /></span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ portal ? `Super Administration · ${clinicSite.name}` : 'Administration · Catalogue des analyses' }}</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-foreground">Nouvelle analyse</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Son identité d'abord ; une fois créée, la suite s'enregistre toute seule.</p>
                </div>
            </div>
            <Button :as="Link" :href="urls.index" variant="white-outline"><ArrowLeft class="h-4 w-4" />Catalogue</Button>
        </header>

        <EmployeeStepBar :steps="steps" current="identite" locked nav-label="Étapes de la fiche d’analyse" locked-hint="après la création de l’analyse" />

        <ValidationErrorSummary :errors="form.errors" />

        <AnalysisForm
            :form="form"
            step="identite"
            :catalog-items="catalogItems"
            :parents="parents"
            :levels="levels"
            :result-types="resultTypes"
            :entry-modes="entryModes"
            :disciplines="disciplines"
            :can-create-discipline="canCreateDiscipline"
        />

        <Card class="sticky bottom-3 z-10 flex flex-col gap-3 p-3 shadow-lg sm:flex-row sm:items-center sm:justify-between">
            <p class="flex min-w-0 items-center gap-2 text-sm" :class="missing.length ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'">
                <CircleAlert v-if="missing.length" class="h-4 w-4 shrink-0" />
                <span class="truncate">{{ missing.length ? `À remplir : ${missingSentence(missing)}.` : 'Prête : la fiche s’ouvre sur l’étape Résultat.' }}</span>
            </p>
            <div class="flex shrink-0 justify-end gap-2">
                <Button :as="Link" :href="urls.index" variant="white-outline">Annuler</Button>
                <Button type="submit" :disabled="missing.length > 0 || form.processing">
                    {{ form.processing ? 'Création…' : 'Créer et continuer' }}<ArrowRight class="h-4 w-4" />
                </Button>
            </div>
        </Card>
    </form>
</template>
