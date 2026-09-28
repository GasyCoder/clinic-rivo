<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Check, GraduationCap, Hash, Info, Sparkles, User, Zap } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import EmployeePhotoField from '@/Components/Administration/EmployeePhotoField.vue';
import { cn } from '@/lib/cn';
import { hrUrl } from '@/utilities/hrUrl';
import HrPageHeader from '../Partials/HrPageHeader.vue';

/**
 * ADR-213 — une création courte : le genre, le nom et le matricule suffisent
 * pour ouvrir le dossier. On arrive ensuite dans la fiche en sections, où tout
 * le reste (poste, contact, rémunération, banque, avantages) s'enregistre tout
 * seul. Plus de parcours en six étapes.
 *
 * ADR-194 — « Nouveau stagiaire » : après le dossier, son stage.
 */
defineOptions({ layout: AppLayout });
const props = defineProps({
    options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object],
    // ADR-191 — le prochain matricule du modèle du site, proposé et modifiable.
    suggestedEmployeeNumber: { type: String, default: '' },
    employeeNumberModel: { type: String, default: '' },
    currentPair: { type: Object, default: null },
    internshipIntent: { type: Boolean, default: false },
});

const form = useForm({
    sex: '', last_name: '', first_name: '',
    employee_number: props.suggestedEmployeeNumber ?? '',
    active: true,
    // ADR-194 — la photo 4 × 4 part avec le dossier (multipart).
    photo: null, remove_photo: false,
    after: props.internshipIntent ? 'internship' : 'edit',
});

const typedName = computed(() => [form.last_name, form.first_name].filter(Boolean).join(' '));
const ready = computed(() => form.sex && form.last_name.trim() && ! form.processing);
const back = props.internshipIntent ? hrUrl('/administration/internships') : hrUrl('/administration/employees');
const submit = () => form.post(hrUrl('/administration/employees'));
const focusField = (field) => document.getElementById(field)?.focus();
</script>

<template>
    <Head :title="internshipIntent ? 'Nouveau stagiaire' : 'Nouvel employé'" />
    <div class="mx-auto w-full max-w-3xl space-y-4">
        <HrPageHeader
            compact
            :eyebrow="internshipIntent ? 'Stages · Dossier du stagiaire' : 'Dossier personnel'"
            :title="internshipIntent ? 'Nouveau stagiaire' : 'Nouvel employé'"
            :description="internshipIntent
                ? 'D’abord son dossier (genre, nom, matricule), puis son stage : filière, école, encadrant et dates.'
                : 'Trois informations pour ouvrir le dossier. Vous complétez ensuite la fiche : chaque section s’enregistre toute seule.'"
            :icon="internshipIntent ? GraduationCap : 'user-add'"
        >
            <template #actions><Button :as="Link" :href="back" variant="outline" size="sm"><ArrowLeft class="h-4 w-4" />{{ internshipIntent ? 'Retour aux stages' : 'Retour aux employés' }}</Button></template>
        </HrPageHeader>

        <ValidationErrorSummary :errors="form.errors" @select="focusField" />

        <Card class="overflow-hidden">
            <form novalidate @submit.prevent="submit">
                <div class="flex flex-col gap-4 border-b border-border bg-muted/30 p-4 sm:flex-row sm:items-center sm:p-5">
                    <EmployeePhotoField
                        v-model="form.photo"
                        v-model:remove="form.remove_photo"
                        :name="typedName"
                        :error="form.errors.photo"
                    />
                </div>

                <div class="space-y-4 p-4 sm:p-5">
                    <FormField as="div" label="Genre" required :error="form.errors.sex">
                        <div id="sex" role="radiogroup" aria-label="Genre" tabindex="-1" class="grid gap-2.5 focus:outline-none sm:grid-cols-2">
                            <button
                                v-for="item in options.sexes"
                                :key="item.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.sex === item.value"
                                :class="cn(
                                    'flex items-center gap-2.5 rounded-lg border p-2.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    form.sex === item.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-card hover:border-primary/40 hover:bg-accent/50',
                                )"
                                @click="form.sex = item.value; form.clearErrors('sex')"
                            >
                                <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full', form.sex === item.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                                    <Check v-if="form.sex === item.value" class="h-4 w-4" :stroke-width="3" />
                                    <User v-else class="h-4 w-4" />
                                </span>
                                <span>
                                    <span class="block text-sm font-semibold text-foreground">{{ item.label }}</span>
                                    <span class="mt-0.5 block text-xs text-muted-foreground">Civilité {{ item.value === 'M' ? 'M.' : 'Mme' }}</span>
                                </span>
                            </button>
                        </div>
                    </FormField>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Nom" required :error="form.errors.last_name">
                            <Input id="last_name" v-model="form.last_name" autocomplete="family-name" :aria-invalid="Boolean(form.errors.last_name)" />
                        </FormField>
                        <FormField label="Prénoms" :error="form.errors.first_name">
                            <Input id="first_name" v-model="form.first_name" autocomplete="given-name" />
                        </FormField>
                    </div>
                    <FormField label="Matricule" :error="form.errors.employee_number">
                        <IconInput id="employee_number" v-model="form.employee_number" :icon="Hash" autocomplete="off" :placeholder="suggestedEmployeeNumber || 'Ex. RH-2026-001'" class="font-mono sm:max-w-xs" />
                        <span v-if="suggestedEmployeeNumber" class="mt-1.5 flex items-start gap-1.5 text-xs text-muted-foreground">
                            <Sparkles class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" aria-hidden="true" />
                            <span>Proposé selon le modèle <span class="font-mono text-foreground">{{ employeeNumberModel }}</span> — modifiable. Laissé vide, il est attribué d’office.</span>
                        </span>
                        <span v-if="suggestedEmployeeNumber && form.employee_number !== suggestedEmployeeNumber" class="mt-1 block text-xs">
                            <button v-if="form.employee_number !== suggestedEmployeeNumber" type="button" class="font-semibold text-primary hover:underline" @click="form.employee_number = suggestedEmployeeNumber">Reprendre {{ suggestedEmployeeNumber }}</button>
                        </span>
                    </FormField>
                    <p class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2.5 text-xs leading-5 text-muted-foreground">
                        <Zap class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                        <span v-if="internshipIntent">Après la création, vous saisirez son stage ; sa fiche se complète ensuite, section par section.</span>
                        <span v-else>Après la création, la fiche s’ouvre : poste, contact, rémunération, banque et avantages s’y complètent et s’enregistrent tout seuls, section par section.</span>
                    </p>
                    <p class="flex items-start gap-2 text-[11px] leading-4 text-muted-foreground">
                        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />Aucun compte de connexion ni contrat n’est créé : chacun a ses propres droits (Utilisateurs, Contrats).
                    </p>
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 p-3 sm:flex-row sm:items-center sm:justify-between">
                    <Button :as="Link" :href="back" variant="ghost" size="sm">Annuler</Button>
                    <Button type="submit" variant="success" size="sm" :disabled="! ready">
                        {{ form.processing ? 'Création…' : internshipIntent ? 'Créer et saisir le stage' : 'Créer et compléter la fiche' }}<ArrowRight class="h-4 w-4" />
                    </Button>
                </footer>
            </form>
        </Card>
    </div>
</template>
