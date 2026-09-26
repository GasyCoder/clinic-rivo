<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, CalendarRange, Check, FileSignature, FileText, LoaderCircle, PenLine, Search, UserRound, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

const props = defineProps({
    employees: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    contractsByEmployee: { type: Object, default: () => ({}) },
    leavesByEmployee: { type: Object, default: () => ({}) },
    formFieldsByContext: { type: Object, default: () => ({}) },
    prefill: { type: Object, default: () => ({}) },
    /** ADR-184 — `{ name, title, has_signature }` réglé pour le site. */
    director: { type: Object, default: () => ({ name: null, title: 'Directeur général', has_signature: false }) },
});

/** Un directeur est réglé pour ce site : son nom ou sa signature. */
const directorConfigured = computed(() => Boolean(props.director?.name || props.director?.has_signature));

// Initial values do not trigger the reset watchers below, so a page opened
// from a contract keeps its canevas, employee and contract.
const form = useForm({
    document_template_uuid: props.prefill.document_template_uuid || '',
    employee_uuid: props.prefill.employee_uuid || '',
    employment_contract_uuid: props.prefill.employment_contract_uuid || '',
    leave_request_uuid: props.prefill.leave_request_uuid || '',
    form_data: {},
    // Cochée d'office dès qu'un directeur est réglé ; la décocher reste possible.
    with_director_signature: Boolean(props.director?.name || props.director?.has_signature),
});

const selectedTemplate = computed(() => props.templates.find((template) => template.uuid === form.document_template_uuid));
const selectedEmployee = computed(() => props.employees.find((employee) => employee.uuid === form.employee_uuid));
const needsContract = computed(() => selectedTemplate.value?.data_context === 'EMPLOYEE_AND_CONTRACT');
const needsLeave = computed(() => selectedTemplate.value?.data_context === 'EMPLOYEE_AND_LEAVE');
const availableContracts = computed(() => props.contractsByEmployee[form.employee_uuid] ?? []);
const availableLeaves = computed(() => props.leavesByEmployee[form.employee_uuid] ?? []);
const activeFields = computed(() => props.formFieldsByContext[selectedTemplate.value?.data_context] ?? []);

// Keys the RH has explicitly edited — once touched, a later debounced
// preview refresh must never clobber that in-progress edit with the
// auto-filled value again (this is what makes "pre-filled but still
// editable" actually work).
const touchedFields = ref(new Set());
const markTouched = (key) => touchedFields.value.add(key);

// Changer de canevas garde le contrat ou le congé déjà choisi quand le nouveau
// canevas en parle aussi (ouvert depuis un congé, puis un autre canevas de congé).
watch(() => form.document_template_uuid, () => {
    if (! needsContract.value) form.employment_contract_uuid = '';
    if (! needsLeave.value) form.leave_request_uuid = '';
    form.form_data = {};
    touchedFields.value = new Set();
});
watch(() => form.employee_uuid, () => {
    form.employment_contract_uuid = '';
    form.leave_request_uuid = '';
});

const preview = ref(null);
const previewLoading = ref(false);
const previewError = ref('');
let previewTimer;
let requestSequence = 0;

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const readyForPreview = computed(() => Boolean(
    form.document_template_uuid
    && form.employee_uuid
    && (!needsContract.value || form.employment_contract_uuid)
    && (!needsLeave.value || form.leave_request_uuid),
));

const loadPreview = async () => {
    if (!readyForPreview.value) {
        preview.value = null;
        previewError.value = '';

        return;
    }

    const sequence = ++requestSequence;
    previewLoading.value = true;
    previewError.value = '';

    try {
        const response = await fetch(hrUrl('/administration/generated-documents/preview'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({
                document_template_uuid: form.document_template_uuid,
                employee_uuid: form.employee_uuid,
                employment_contract_uuid: form.employment_contract_uuid || null,
                leave_request_uuid: form.leave_request_uuid || null,
                form_data: form.form_data,
                with_director_signature: form.with_director_signature,
            }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const message = Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? 'L’aperçu n’a pas pu être calculé.';

            throw new Error(message);
        }

        if (sequence === requestSequence) {
            preview.value = data;
            // Auto-prefill: only fields the RH hasn't touched yet get the
            // server's known/auto-filled value — anything already being
            // typed is left alone.
            for (const field of activeFields.value) {
                if (!touchedFields.value.has(field.key) && data.form_values?.[field.key] !== undefined) {
                    form.form_data[field.key] = data.form_values[field.key];
                }
            }
        }
    } catch (error) {
        if (sequence === requestSequence) {
            preview.value = null;
            previewError.value = error.message;
        }
    } finally {
        if (sequence === requestSequence) previewLoading.value = false;
    }
};

watch(
    () => [form.document_template_uuid, form.employee_uuid, form.employment_contract_uuid, form.leave_request_uuid, JSON.stringify(form.form_data), form.with_director_signature],
    () => {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(loadPreview, 350);
    },
);
onBeforeUnmount(() => clearTimeout(previewTimer));

const submit = () => {
    form.post(hrUrl('/administration/generated-documents'), { preserveScroll: true });
};

/*
 * ADR-070 / ADR-087 / ADR-198 — à quoi sert cette page : produire un document
 * administratif (attestation, contrat, courrier de congé…) à partir d'un
 * canevas composé par le Super Administrateur. Page 1 : les informations RH
 * de la personne, pré-remplies ; pages suivantes : le texte du canevas, tel
 * quel. Le document généré est figé et imprimable.
 */
const { can } = usePermissions();

// 1 · Quel document ? Les canevas, rangés par type.
const templateGroups = computed(() => {
    const groups = new Map();
    for (const template of props.templates) {
        const key = template.document_type || 'AUTRE';
        if (! groups.has(key)) groups.set(key, []);
        groups.get(key).push(template);
    }

    return [...groups.entries()].map(([type, items]) => ({ type, items }));
});

// 2 · Pour qui ? Une recherche au lieu d'une longue liste déroulante.
const employeeQuery = ref('');
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const matchingEmployees = computed(() => {
    const words = normalize(employeeQuery.value).split(/\s+/).filter(Boolean);

    return props.employees
        .filter((employee) => words.every((word) => normalize(`${employee.name} ${employee.employee_number} ${employee.job_title ?? ''} ${employee.department ?? ''}`).includes(word)))
        .slice(0, 8);
});
const chooseEmployee = (employee) => {
    form.employee_uuid = employee.uuid;
    employeeQuery.value = '';
};

const contractOptions = computed(() => availableContracts.value.map((contract) => ({ value: contract.uuid, label: contract.label })));
const leaveOptions = computed(() => availableLeaves.value.map((leave) => ({ value: leave.uuid, label: `${leave.label}${leave.status ? ` · ${leave.status}` : ''}` })));

const stepDone = computed(() => ({
    template: Boolean(form.document_template_uuid),
    employee: Boolean(form.employee_uuid),
    source: ! needsContract.value && ! needsLeave.value ? true : Boolean(needsContract.value ? form.employment_contract_uuid : form.leave_request_uuid),
}));

</script>

<template>
    <Head title="Générer un document" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines · Documents"
            title="Générer un document"
            description="Une attestation, un contrat, un courrier de congé… produit à partir d’un canevas du Super Administrateur. La page 1 reprend les informations de la personne (modifiables) ; les pages suivantes, le texte du canevas tel quel. Le document généré est figé, puis imprimé."
            :icon="FileSignature"
            tone="primary"
        >
            <template #actions>
                <Button :as="Link" :href="hrUrl('/administration/generated-documents')" variant="outline"><ArrowLeft class="h-4 w-4" />Documents générés</Button>
            </template>
        </PageHeader>

        <form class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_440px]" @submit.prevent="submit">
            <div class="space-y-4">
                <!-- 1 · Quel document ? -->
                <Card class="p-5">
                    <header class="flex items-start gap-3">
                        <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-bold', stepDone.template ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                            <Check v-if="stepDone.template" class="h-4 w-4" /><template v-else>1</template>
                        </span>
                        <div>
                            <h2 class="font-heading text-base font-bold text-foreground">Quel document ?</h2>
                            <p class="text-sm text-muted-foreground">Les canevas publiés pour ce site par le Super Administrateur.</p>
                        </div>
                    </header>

                    <div v-if="templates.length" class="mt-4 space-y-4">
                        <div v-for="group in templateGroups" :key="group.type">
                            <p class="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-muted-foreground">{{ group.type }}</p>
                            <div class="grid gap-2 sm:grid-cols-2" role="radiogroup" :aria-label="`Canevas ${group.type}`">
                                <button
                                    v-for="template in group.items"
                                    :key="template.uuid"
                                    type="button"
                                    role="radio"
                                    :aria-checked="form.document_template_uuid === template.uuid"
                                    :class="cn(
                                        'flex items-start gap-3 rounded-xl border p-3 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                        form.document_template_uuid === template.uuid ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:border-primary/40 hover:bg-accent/50',
                                    )"
                                    @click="form.document_template_uuid = template.uuid"
                                >
                                    <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', form.document_template_uuid === template.uuid ? 'bg-primary text-primary-foreground' : 'bg-primary/10 text-primary')"><FileText class="h-4 w-4" /></span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-foreground">{{ template.name }}</span>
                                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ template.data_context_label }}</span>
                                        <span v-if="template.description" class="mt-1 line-clamp-2 block text-xs text-muted-foreground">{{ template.description }}</span>
                                    </span>
                                </button>
                            </div>
                        </div>
                        <p v-if="form.errors.document_template_uuid" class="text-sm text-destructive">{{ form.errors.document_template_uuid }}</p>
                    </div>
                    <div v-else class="mt-4 rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        Aucun canevas n’est encore publié pour ce site. Le Super Administrateur les compose dans « Canevas de documents » (portail) et les envoie au site ; ils apparaissent ici dès qu’ils sont actifs.
                    </div>
                </Card>

                <!-- 2 · Pour qui ? -->
                <Card class="p-5">
                    <header class="flex items-start gap-3">
                        <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-bold', stepDone.employee ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                            <Check v-if="stepDone.employee" class="h-4 w-4" /><template v-else>2</template>
                        </span>
                        <div>
                            <h2 class="font-heading text-base font-bold text-foreground">Pour qui ?</h2>
                            <p class="text-sm text-muted-foreground">Ses informations remplissent la page 1.</p>
                        </div>
                    </header>

                    <div v-if="selectedEmployee" class="mt-4 flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 p-3">
                        <EmployeePhoto :src="selectedEmployee.photo_url" :name="selectedEmployee.name" size="md" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-foreground">{{ selectedEmployee.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ selectedEmployee.employee_number }}<template v-if="selectedEmployee.job_title"> · {{ selectedEmployee.job_title }}</template><template v-if="selectedEmployee.department"> · {{ selectedEmployee.department }}</template></p>
                        </div>
                        <Button type="button" variant="outline" size="sm" @click="form.employee_uuid = ''"><X class="h-4 w-4" />Changer</Button>
                    </div>
                    <div v-else class="mt-4 space-y-2">
                        <IconInput v-model="employeeQuery" :icon="Search" type="search" placeholder="Nom, matricule, fonction, service…" aria-label="Rechercher la personne" />
                        <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
                            <li v-for="employee in matchingEmployees" :key="employee.uuid">
                                <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-start transition hover:bg-accent" @click="chooseEmployee(employee)">
                                    <EmployeePhoto :src="employee.photo_url" :name="employee.name" size="sm" />
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-foreground">{{ employee.name }}</span>
                                        <span class="block truncate text-xs text-muted-foreground">{{ employee.employee_number }}<template v-if="employee.job_title"> · {{ employee.job_title }}</template></span>
                                    </span>
                                </button>
                            </li>
                            <li v-if="! matchingEmployees.length" class="px-3 py-4 text-center text-sm text-muted-foreground">Personne ne correspond.</li>
                        </ul>
                    </div>
                    <p v-if="form.errors.employee_uuid" class="mt-2 text-sm text-destructive">{{ form.errors.employee_uuid }}</p>
                </Card>

                <!-- 3 · Le contrat ou le congé concerné, si le canevas en parle -->
                <Card v-if="needsContract || needsLeave" class="p-5">
                    <header class="flex items-start gap-3">
                        <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-bold', stepDone.source ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                            <Check v-if="stepDone.source" class="h-4 w-4" /><template v-else>3</template>
                        </span>
                        <div>
                            <h2 class="font-heading text-base font-bold text-foreground">{{ needsContract ? 'Quel contrat ?' : 'Quelle demande de congé ?' }}</h2>
                            <p class="text-sm text-muted-foreground">{{ needsContract ? 'Ses dates et sa référence remplissent la page 1.' : 'Son type, ses dates et son motif remplissent la page 1.' }}</p>
                        </div>
                    </header>
                    <div class="mt-4">
                        <FormField v-if="needsContract" label="Contrat" required :error="form.errors.employment_contract_uuid" as="div">
                            <Select v-model="form.employment_contract_uuid" :options="contractOptions" :placeholder="form.employee_uuid ? 'Sélectionner un contrat' : 'Choisissez d’abord la personne'" :disabled="! form.employee_uuid" class="w-full" :icon="FileText" />
                        </FormField>
                        <FormField v-else label="Demande de congé" required :error="form.errors.leave_request_uuid" as="div">
                            <Select v-model="form.leave_request_uuid" :options="leaveOptions" :placeholder="form.employee_uuid ? 'Sélectionner une demande' : 'Choisissez d’abord la personne'" :disabled="! form.employee_uuid" class="w-full" :icon="CalendarRange" />
                        </FormField>
                        <p v-if="form.employee_uuid && needsContract && ! availableContracts.length" class="mt-2 text-sm text-amber-700 dark:text-amber-300">Cette personne n’a aucun contrat enregistré.</p>
                        <p v-if="form.employee_uuid && needsLeave && ! availableLeaves.length" class="mt-2 text-sm text-amber-700 dark:text-amber-300">Cette personne n’a aucune demande de congé enregistrée.</p>
                    </div>
                </Card>

                <!-- Page 1 : les informations de la personne -->
                <Card v-if="activeFields.length && readyForPreview" class="p-5">
                    <header class="flex items-start gap-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"><UserRound class="h-4 w-4" /></span>
                        <div>
                            <h2 class="font-heading text-base font-bold text-foreground">Page 1 · informations du document</h2>
                            <p class="text-sm text-muted-foreground">Reprises du dossier ; corrigez-les ici si besoin — le dossier, lui, ne change pas.</p>
                        </div>
                    </header>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <FormField v-for="field in activeFields" :key="field.key" :label="field.label" :required="field.required" as="div">
                            <DatePicker v-if="field.type === 'date'" :id="`field-${field.key}`" v-model="form.form_data[field.key]" @update:model-value="markTouched(field.key)" />
                            <Input v-else :id="`field-${field.key}`" v-model="form.form_data[field.key]" @input="markTouched(field.key)" />
                        </FormField>
                    </div>
                    <p v-if="form.errors.form_data" class="mt-3 text-sm text-destructive">{{ form.errors.form_data }}</p>
                </Card>

                <!-- ADR-184 — la signature du directeur général, réglée pour ce site depuis le portail. -->
                <Card class="p-4">
                    <div class="flex items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><PenLine class="h-4 w-4" /></span>
                        <div class="min-w-0 flex-1">
                            <label v-if="directorConfigured" class="flex cursor-pointer items-start gap-2.5">
                                <Checkbox v-model="form.with_director_signature" class="mt-0.5" aria-label="Apposer la signature du directeur général" />
                                <span>
                                    <span class="block text-sm font-semibold text-foreground">Apposer la signature du {{ (director.title || 'Directeur général').toLowerCase() }}</span>
                                    <span class="mt-0.5 block text-xs text-muted-foreground">
                                        <template v-if="director.name">{{ director.name }}</template><template v-if="director.name && director.has_signature"> · </template><template v-if="director.has_signature">signature enregistrée</template><template v-else> · aucune signature enregistrée : un espace est laissé pour signer à la main</template>.
                                        Le bloc est ajouté au bas du document et figé avec lui.
                                    </span>
                                </span>
                            </label>
                            <template v-else>
                                <p class="text-sm font-semibold text-foreground">Signature du directeur général</p>
                                <p class="mt-0.5 text-xs text-muted-foreground">Aucun directeur n’est renseigné pour ce site. Le Super Administrateur le règle dans Paramètres › Direction.</p>
                            </template>
                        </div>
                    </div>
                </Card>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <p v-if="! readyForPreview" class="me-auto text-sm text-muted-foreground">Choisissez un canevas, une personne<template v-if="needsContract || needsLeave"> et {{ needsContract ? 'son contrat' : 'sa demande de congé' }}</template>.</p>
                    <Button :as="Link" :href="hrUrl('/administration/generated-documents')" variant="outline">Annuler</Button>
                    <Button v-if="can('generated_documents.create')" type="submit" :disabled="form.processing || ! readyForPreview">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" /><FileSignature v-else class="h-4 w-4" />{{ form.processing ? 'Génération…' : 'Générer le document' }}
                    </Button>
                </div>
            </div>

            <!-- L'aperçu, avec les vraies informations -->
            <aside class="xl:sticky xl:top-4 xl:self-start">
                <Card class="overflow-hidden">
                    <header class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                        <div class="min-w-0">
                            <h2 class="text-sm font-bold text-foreground">Aperçu du document</h2>
                            <p class="truncate text-xs text-muted-foreground">{{ selectedTemplate?.name || 'Canevas à choisir' }} · {{ selectedEmployee?.name || 'personne à choisir' }}</p>
                        </div>
                        <Badge v-if="preview && ! previewLoading" variant="success">Données réelles</Badge>
                    </header>
                    <div v-if="previewLoading" class="flex items-center gap-2 p-6 text-sm text-muted-foreground"><LoaderCircle class="h-4 w-4 animate-spin" />Calcul de l’aperçu…</div>
                    <div v-else-if="previewError" class="p-4 text-sm text-destructive" role="alert">{{ previewError }}</div>
                    <div v-else-if="preview" class="max-h-[70vh] overflow-y-auto bg-white p-4 text-sm text-slate-800" v-html="preview.rendered_html" />
                    <div v-else class="flex flex-col items-center gap-2 p-8 text-center text-sm text-muted-foreground">
                        <FileText class="h-8 w-8 text-muted-foreground/60" aria-hidden="true" />
                        L’aperçu apparaît dès que le canevas et la personne sont choisis.
                    </div>
                </Card>
            </aside>
        </form>
    </div>
</template>
