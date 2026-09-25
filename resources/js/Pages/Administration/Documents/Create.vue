<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import { PenLine } from 'lucide-vue-next';

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
    leave_request_uuid: '',
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

watch(() => form.document_template_uuid, () => {
    form.employment_contract_uuid = '';
    form.leave_request_uuid = '';
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
</script>

<template>
    <Head title="Générer un document" />

    <div class="w-full space-y-4">
        <div>
            <Link :href="hrUrl('/administration/generated-documents')" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-primary-600"><Icon name="arrow-left" />Documents générés</Link>
            <h1 class="mt-1 font-heading text-xl font-bold text-slate-700 dark:text-white">Générer un document</h1>
            <p class="mt-1 text-sm text-slate-500">Choisissez un canevas et une personne : les informations déjà connues sont remplies automatiquement.</p>
        </div>

        <form class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]" @submit.prevent="submit">
            <main class="space-y-4">
                <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-xs font-bold uppercase text-slate-400">Canevas <span class="text-red-500">*</span></label>
                            <select v-model="form.document_template_uuid" required class="h-10 w-full rounded border border-gray-200 bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                                <option value="">Sélectionner un canevas</option>
                                <option v-for="template in templates" :key="template.uuid" :value="template.uuid">{{ template.document_type }} · {{ template.name }}</option>
                            </select>
                            <p v-if="form.errors.document_template_uuid" class="mt-1 text-xs text-red-600">{{ form.errors.document_template_uuid }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-bold uppercase text-slate-400">Employé <span class="text-red-500">*</span></label>
                            <select v-model="form.employee_uuid" required class="h-10 w-full rounded border border-gray-200 bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                                <option value="">Sélectionner un employé</option>
                                <option v-for="employee in employees" :key="employee.uuid" :value="employee.uuid">{{ employee.name }} ({{ employee.employee_number }})</option>
                            </select>
                            <p v-if="form.errors.employee_uuid" class="mt-1 text-xs text-red-600">{{ form.errors.employee_uuid }}</p>
                        </div>
                        <div v-if="needsContract">
                            <label class="mb-1.5 block text-xs font-bold uppercase text-slate-400">Contrat <span class="text-red-500">*</span></label>
                            <select v-model="form.employment_contract_uuid" required :disabled="!form.employee_uuid" class="h-10 w-full rounded border border-gray-200 bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                                <option value="">{{ form.employee_uuid ? 'Sélectionner un contrat' : 'Choisir d’abord un employé' }}</option>
                                <option v-for="contract in availableContracts" :key="contract.uuid" :value="contract.uuid">{{ contract.label }}</option>
                            </select>
                            <p v-if="form.employee_uuid && !availableContracts.length" class="mt-1 text-xs text-amber-600">Cet employé n’a aucun contrat enregistré.</p>
                            <p v-if="form.errors.employment_contract_uuid" class="mt-1 text-xs text-red-600">{{ form.errors.employment_contract_uuid }}</p>
                        </div>
                        <div v-if="needsLeave">
                            <label class="mb-1.5 block text-xs font-bold uppercase text-slate-400">Demande de congé <span class="text-red-500">*</span></label>
                            <select v-model="form.leave_request_uuid" required :disabled="!form.employee_uuid" class="h-10 w-full rounded border border-gray-200 bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                                <option value="">{{ form.employee_uuid ? 'Sélectionner une demande' : 'Choisir d’abord un employé' }}</option>
                                <option v-for="leave in availableLeaves" :key="leave.uuid" :value="leave.uuid">{{ leave.label }}</option>
                            </select>
                            <p v-if="form.employee_uuid && !availableLeaves.length" class="mt-1 text-xs text-amber-600">Cet employé n’a aucune demande de congé enregistrée.</p>
                            <p v-if="form.errors.leave_request_uuid" class="mt-1 text-xs text-red-600">{{ form.errors.leave_request_uuid }}</p>
                        </div>
                    </div>
                </section>

                <section v-if="activeFields.length" class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="text-xs font-bold uppercase tracking-wide text-slate-400">Page 1 : informations du document</h2>
                    <p class="mt-1 text-[11px] leading-4 text-slate-400">Pré-rempli automatiquement à partir du dossier de l’employé — modifiable avant génération.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div v-for="field in activeFields" :key="field.key">
                            <label class="mb-1 block text-xs font-bold text-slate-600 dark:text-slate-300">
                                {{ field.label }} <span v-if="field.required" class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.form_data[field.key]"
                                :type="field.type === 'date' ? 'date' : 'text'"
                                class="h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950"
                                @input="markTouched(field.key)"
                            >
                        </div>
                    </div>
                    <p v-if="form.errors.form_data" class="mt-2 text-xs text-red-600">{{ form.errors.form_data }}</p>
                </section>

                <!-- ADR-184 — la signature du directeur général, réglée pour ce site depuis le portail. -->
                <section class="rounded-xl border border-border bg-card p-4 shadow-sm">
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
                </section>

                <div class="flex justify-end gap-2">
                    <Button :as="Link" :href="hrUrl('/administration/generated-documents')" size="rg" variant="white-outline">Annuler</Button>
                    <Button type="submit" size="rg" :disabled="form.processing || !readyForPreview">
                        <Icon class="text-lg" name="file-text" /><span class="ms-2">{{ form.processing ? 'Génération…' : 'Générer le document' }}</span>
                    </Button>
                </div>
            </main>

            <aside class="space-y-3 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <header class="border-b border-gray-200 px-4 py-3 dark:border-gray-900">
                        <h2 class="text-xs font-bold uppercase tracking-wide text-slate-400">Aperçu du document (données réelles)</h2>
                        <p class="mt-0.5 text-[11px] text-slate-400">{{ selectedEmployee?.name || 'Employé à sélectionner' }}</p>
                    </header>
                    <div v-if="previewLoading" class="flex items-center gap-2 p-6 text-xs text-slate-400"><Icon class="animate-spin" name="loader" />Calcul de l’aperçu…</div>
                    <div v-else-if="previewError" class="p-4 text-xs text-red-600">{{ previewError }}</div>
                    <div v-else-if="preview" class="max-h-[70vh] overflow-y-auto bg-white p-4 text-sm text-slate-800" v-html="preview.rendered_html" />
                    <div v-else class="p-6 text-center text-xs text-slate-400">Complétez le formulaire pour voir l’aperçu du document.</div>
                </section>
            </aside>
        </form>
    </div>
</template>
