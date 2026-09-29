<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, CloudUpload, GraduationCap, Info, User } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import EmployeeSectionCard from '@/Components/Administration/EmployeeFile/EmployeeSectionCard.vue';
import EmployeeStepBar from '@/Components/Administration/EmployeeFile/EmployeeStepBar.vue';
import IdentityFields from '@/Components/Administration/EmployeeFile/IdentityFields.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { employeeSteps } from '@/utilities/employeeSteps';
import { hrUrl } from '@/utilities/hrUrl';
import HrPageHeader from '../Partials/HrPageHeader.vue';

/**
 * ADR-221 — la création d'un employé garde le parcours à étapes, sans rien
 * retenir jusqu'au bout : « Continuer » sur l'Identité crée le dossier, puis
 * chaque étape suivante s'enregistre toute seule (Edit.vue, même barre).
 * Le matricule est proposé selon le modèle du site (ADR-191) et se corrige à
 * l'étape Poste.
 *
 * ADR-194 — « Nouveau stagiaire » : le même parcours, et son stage à la fin.
 */
defineOptions({ layout: AppLayout });
const props = defineProps({
    options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object],
    suggestedEmployeeNumber: { type: String, default: '' },
    employeeNumberModel: { type: String, default: '' },
    currentPair: { type: Object, default: null },
    internshipIntent: { type: Boolean, default: false },
});
const { can } = usePermissions();

const steps = computed(() => employeeSteps({ payroll: can('employees.payroll.view') || can('employees.payroll.update') }));

const form = useForm({
    sex: '', last_name: '', first_name: '', birth_date: '', birth_place: '',
    employee_number: props.suggestedEmployeeNumber ?? '',
    active: true,
    // ADR-194 — la photo 4 × 4 part avec le dossier (multipart).
    photo: null, remove_photo: false,
    after: 'edit',
    internship: props.internshipIntent,
});

const REQUIRED = { sex: 'Le genre', last_name: 'Le nom' };
const back = props.internshipIntent ? hrUrl('/administration/internships') : hrUrl('/administration/employees');

/** Ce qui manque avant de créer le dossier, dit sous le champ comme dans l'ancien parcours. */
const validate = () => {
    let first = null;
    Object.entries(REQUIRED).forEach(([field, label]) => {
        if (String(form[field] ?? '').trim() === '') {
            form.setError(field, `${label} est obligatoire avant de continuer.`);
            first ??= field;
        }
    });
    if (first) nextTick(() => document.getElementById(first)?.focus());
    return ! first;
};
watch(() => [form.sex, form.last_name], () => {
    Object.keys(REQUIRED).forEach((field) => {
        if (String(form[field] ?? '').trim() !== '' && String(form.errors[field] ?? '').includes('avant de continuer')) form.clearErrors(field);
    });
});

const submit = () => {
    if (form.processing || ! validate()) return;
    form.post(hrUrl('/administration/employees'), { preserveScroll: true });
};
const focusField = (field) => document.getElementById(field)?.focus();

/* Quitter avant « Continuer » : la saisie n'est encore nulle part. */
const typed = computed(() => form.isDirty && ! form.processing && ! form.wasSuccessful);
const { pendingVisit, leave, stay } = useUnsavedChangesGuard(typed);
const leaving = ref(false);
watch(pendingVisit, (visit) => { leaving.value = Boolean(visit); });
const stayHere = () => { leaving.value = false; stay(); };
const leaveAnyway = () => { leaving.value = false; leave(); };
</script>

<template>
    <Head :title="internshipIntent ? 'Nouveau stagiaire' : 'Nouvel employé'" />
    <div class="w-full space-y-4">
        <HrPageHeader
            compact
            :eyebrow="internshipIntent ? 'Stages · Dossier du stagiaire' : 'Dossier personnel · Parcours guidé'"
            :title="internshipIntent ? 'Nouveau stagiaire' : 'Créer un employé'"
            :description="internshipIntent
                ? 'Son dossier étape par étape, puis son stage : filière, école, encadrant et dates. Chaque étape s’enregistre toute seule.'
                : 'Avancez étape par étape. « Continuer » crée le dossier ; ensuite, chaque étape s’enregistre toute seule.'"
            :icon="internshipIntent ? GraduationCap : 'user-add'"
        >
            <template #actions><Button :as="Link" :href="back" variant="outline" size="sm"><ArrowLeft class="h-4 w-4" />{{ internshipIntent ? 'Retour aux stages' : 'Retour aux employés' }}</Button></template>
        </HrPageHeader>

        <form id="employee-wizard" class="scroll-mt-3 space-y-3" novalidate @submit.prevent="submit">
            <ValidationErrorSummary :errors="form.errors" @select="focusField" />

            <EmployeeStepBar :steps="steps" current="identity" locked />

            <EmployeeSectionCard
                :icon="User"
                title="Identité"
                description="Qui est la personne. Le nom et le genre sont exigés ; la civilité se déduit du genre."
            >
                <template #status>
                    <p class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><CloudUpload class="h-3.5 w-3.5" aria-hidden="true" />Enregistré à « Continuer »</p>
                </template>
                <IdentityFields
                    v-model:photo="form.photo"
                    v-model:remove-photo="form.remove_photo"
                    :form="form"
                    :options="options"
                    :photo-error="form.errors.photo"
                    photo-subtitle="Facultative : elle part avec le dossier."
                >
                    <p class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2.5 text-xs leading-5 text-muted-foreground">
                        <Info class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                        <span>
                            Matricule proposé<template v-if="suggestedEmployeeNumber"> : <strong class="font-mono text-foreground">{{ suggestedEmployeeNumber }}</strong> (modèle <span class="font-mono">{{ employeeNumberModel }}</span>)</template>, modifiable à l’étape Poste.
                            Aucun compte de connexion ni contrat n’est créé : chacun a ses propres droits.
                        </span>
                    </p>
                </IdentityFields>
            </EmployeeSectionCard>

            <footer class="sticky bottom-2 z-10 flex items-center justify-between gap-2 rounded-xl border border-border bg-card/95 p-2 shadow-lg backdrop-blur">
                <Button :as="Link" :href="back" variant="ghost" size="sm">Annuler</Button>
                <div class="flex items-center gap-2">
                    <p class="hidden px-1 text-end text-[11px] text-muted-foreground md:block">« Continuer » crée le dossier ; la suite s’enregistre toute seule.</p>
                    <Button type="submit" size="sm" :disabled="form.processing">
                        {{ form.processing ? 'Création du dossier…' : 'Continuer' }}<ArrowRight class="h-4 w-4" />
                    </Button>
                </div>
            </footer>
        </form>

        <Dialog
            :open="leaving"
            title="Le dossier n’est pas encore créé"
            description="Ce que vous avez saisi n’est enregistré qu’à « Continuer ». Si vous quittez maintenant, la saisie est perdue."
            :dismissible="false"
            @update:open="(value) => value || stayHere()"
        >
            <template #footer>
                <Button type="button" variant="outline" @click="leaveAnyway">Quitter sans créer</Button>
                <Button type="button" @click="stayHere">Rester</Button>
            </template>
        </Dialog>
    </div>
</template>
