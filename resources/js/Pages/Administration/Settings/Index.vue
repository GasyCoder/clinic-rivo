<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrEmptyState from '../Partials/HrEmptyState.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ references: Object, types: [Array, Object], leaveDayCountMethods: [Array, Object] });
const { can } = usePermissions();
const selectedType = ref(props.types?.[0]?.value ?? 'DEPARTMENT');
const editingUuid = ref(null);
const defaultLeaveRules = () => ({ consumes_annual_balance: false, annual_quota_days: null, max_days_per_request: null, requires_attachment: false, requires_approval: true, day_count_method: 'CALENDAR_DAYS_INCLUSIVE' });
const createForm = useForm({ type: selectedType.value, code: '', label: '', active: true, position: 0, metadata: defaultLeaveRules() });
const editForm = useForm({ type: '', code: '', label: '', active: true, position: 0, metadata: defaultLeaveRules() });
const archiveForms = ref({});
const currentItems = computed(() => props.references?.[selectedType.value] ?? []);
const selectedMeta = computed(() => props.types.find((type) => type.value === selectedType.value));
const usageByType = {
    DEPARTMENT: { icon: 'building', title: 'Employés et planning', description: 'Utilisé comme affectation principale du dossier employé et comme proposition automatique dans le planning.' },
    JOB_TITLE: { icon: 'briefcase', title: 'Dossiers employés', description: 'Utilisé pour la fonction structurée affichée dans le dossier et les listes du personnel.' },
    CONTRACT_TYPE: { icon: 'file-docs', title: 'Contrats', description: 'Proposé lors de la création et de la correction des contrats de travail.' },
    LEAVE_TYPE: { icon: 'calendar', title: 'Congés et permissions', description: 'Pilote le calcul des jours, le quota annuel, le justificatif et la validation des nouvelles demandes.' },
    ATTESTATION_TYPE: { icon: 'file-text', title: 'Documents RH', description: 'Proposé lors du classement des attestations dans le dossier privé de l’employé.' },
};
const selectedUsage = computed(() => usageByType[selectedType.value]);
const activeCount = computed(() => currentItems.value.filter((item) => !item.archived && item.active).length);
const archivedCount = computed(() => currentItems.value.filter((item) => item.archived).length);
const codeTouched = ref(false);
const generateCode = (label) => label
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 80);
watch(() => createForm.label, (label) => {
    if (!codeTouched.value) createForm.code = generateCode(label);
});
const chooseType = (type) => {
    selectedType.value = type;
    editingUuid.value = null;
    createForm.clearErrors();
    createForm.reset('code', 'label', 'position');
    createForm.type = type;
    createForm.metadata = defaultLeaveRules();
    codeTouched.value = false;
};
const submitCreate = () => createForm.post('/administration/settings', {
    preserveScroll: true,
    onSuccess: () => {
        createForm.reset('code', 'label', 'position');
        createForm.type = selectedType.value;
        createForm.metadata = defaultLeaveRules();
        codeTouched.value = false;
    },
});
const startEdit = (item) => { editingUuid.value = item.uuid; editForm.type = item.type; editForm.code = item.code; editForm.label = item.label; editForm.active = item.active; editForm.position = item.position; editForm.metadata = { ...defaultLeaveRules(), ...(item.metadata ?? {}) }; editForm.clearErrors(); };
const submitEdit = (item) => editForm.put(`/administration/settings/${item.uuid}`, { preserveScroll: true, onSuccess: () => editingUuid.value = null });
const archiveForm = (uuid) => archiveForms.value[uuid] ??= useForm({ reason: '' });
const archive = (item) => archiveForm(item.uuid).delete(`/administration/settings/${item.uuid}`, { preserveScroll: true });
const restore = (item) => router.post(`/administration/settings/${item.uuid}/restore`, {}, { preserveScroll: true });
</script>

<template>
    <Head title="Paramètres RH" />
    <div class="space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Référentiels et règles configurables" title="Paramètres RH" description="Gérez les départements, fonctions, contrats, congés et attestations. Les règles de congé sont appliquées par le serveur, sans calcul dupliqué dans les formulaires." icon="settings" tone="slate" />

        <div class="grid gap-5 xl:grid-cols-[270px_minmax(0,1fr)]">
            <aside class="space-y-4 xl:sticky xl:top-4">
                <nav class="overflow-x-auto rounded-xl border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-900 dark:bg-gray-950" aria-label="Types de paramètres"><div class="flex min-w-max gap-1 xl:min-w-0 xl:flex-col"><button v-for="type in types" :key="type.value" type="button" :class="['flex min-w-48 items-center justify-between rounded-lg px-3 py-3 text-start text-sm font-bold transition xl:min-w-0 xl:w-full', selectedType === type.value ? 'bg-primary-600 text-white shadow-sm' : 'text-slate-600 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-gray-900']" @click="chooseType(type.value)"><span>{{ type.label }}</span><span :class="['rounded-full px-2 py-0.5 text-xs', selectedType === type.value ? 'bg-white/15' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ references?.[type.value]?.length ?? 0 }}</span></button></div></nav>
                <div class="hidden space-y-3 xl:block"><HrStatCard label="Valeurs actives" :value="activeCount" :hint="selectedMeta?.label" icon="check-circle" tone="emerald" /><HrStatCard label="Archives" :value="archivedCount" hint="Restaurables sur autorisation" icon="archive" tone="slate" /></div>
            </aside>

            <main class="min-w-0 space-y-4">
                <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-start gap-3"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-lg text-primary-600 dark:bg-primary-950"><Icon :name="selectedUsage?.icon || 'settings'" /></span><div><p class="text-[11px] font-bold uppercase tracking-wide text-primary-600">{{ selectedMeta?.label }}</p><h2 class="mt-1 text-base font-bold text-slate-800 dark:text-white">{{ selectedUsage?.title }}</h2><p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">{{ selectedUsage?.description }}</p></div></div><div class="flex shrink-0 gap-2 text-xs"><span class="rounded-full bg-emerald-50 px-2.5 py-1 font-bold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">{{ activeCount }} actif(s)</span><span class="rounded-full bg-gray-100 px-2.5 py-1 font-bold text-slate-500 dark:bg-gray-900">{{ archivedCount }} archivé(s)</span></div></div>

                <form v-if="can('hr_settings.create')" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950" @submit.prevent="submitCreate">
                    <div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-300"><Icon name="plus" /></span><div><h2 class="font-bold text-slate-800 dark:text-white">Ajouter une valeur</h2><p class="mt-1 text-xs leading-5 text-slate-500">Le code reste stable dans les données ; le libellé est présenté aux utilisateurs.</p></div></div>
                    <div class="mt-4 grid gap-3 md:grid-cols-[2fr_1fr_110px_auto]"><div><label class="mb-1.5 block text-xs font-bold text-slate-500">Libellé affiché</label><input v-model="createForm.label" required maxlength="255" placeholder="Ex. Ressources humaines" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"><p v-if="createForm.errors.label" class="mt-1 text-xs text-red-600">{{ createForm.errors.label }}</p></div><div><label class="mb-1.5 flex items-center justify-between gap-2 text-xs font-bold text-slate-500"><span>Code</span><span class="font-medium text-primary-600">généré</span></label><input v-model="createForm.code" required maxlength="80" placeholder="CODE_AUTOMATIQUE" class="h-10 w-full rounded-lg border border-gray-200 px-3 font-mono text-xs uppercase dark:border-gray-800 dark:bg-gray-950" @input="codeTouched = true"><p class="mt-1 text-[10px] leading-4 text-slate-400">Créé depuis le libellé, puis modifiable.</p><p v-if="createForm.errors.code" class="mt-1 text-xs text-red-600">{{ createForm.errors.code }}</p></div><div><label class="mb-1.5 block text-xs font-bold text-slate-500">Ordre</label><input v-model="createForm.position" type="number" min="0" max="65535" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"></div><Button class="self-end md:mb-5" size="rg" :disabled="createForm.processing"><Icon name="check" /><span class="ms-2">Ajouter</span></Button></div>
                    <fieldset v-if="selectedType === 'LEAVE_TYPE'" class="mt-5 rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20"><legend class="px-2 text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">Règles appliquées aux nouvelles demandes</legend><div class="grid gap-4 md:grid-cols-3"><label class="flex cursor-pointer items-start gap-3 rounded-lg bg-white p-3 dark:bg-gray-950"><input v-model="createForm.metadata.consumes_annual_balance" type="checkbox" class="mt-0.5 rounded border-gray-300 text-amber-600"><span><strong class="block text-xs text-slate-700 dark:text-white">Consomme le solde annuel</strong><small class="mt-1 block leading-4 text-slate-400">Les demandes approuvées réduisent le quota.</small></span></label><label class="flex cursor-pointer items-start gap-3 rounded-lg bg-white p-3 dark:bg-gray-950"><input v-model="createForm.metadata.requires_approval" type="checkbox" class="mt-0.5 rounded border-gray-300 text-amber-600"><span><strong class="block text-xs text-slate-700 dark:text-white">Validation requise</strong><small class="mt-1 block leading-4 text-slate-400">Sinon la demande est acceptée à sa création.</small></span></label><label class="flex cursor-pointer items-start gap-3 rounded-lg bg-white p-3 dark:bg-gray-950"><input v-model="createForm.metadata.requires_attachment" type="checkbox" class="mt-0.5 rounded border-gray-300 text-amber-600"><span><strong class="block text-xs text-slate-700 dark:text-white">Justificatif obligatoire</strong><small class="mt-1 block leading-4 text-slate-400">PDF, image ou document Word.</small></span></label></div><div class="mt-4 grid gap-3 md:grid-cols-3"><div><label class="mb-1.5 block text-xs font-bold text-slate-500">Quota annuel (jours)</label><input v-model="createForm.metadata.annual_quota_days" type="number" min="0.01" max="366" step="0.01" :required="createForm.metadata.consumes_annual_balance" :disabled="!createForm.metadata.consumes_annual_balance" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm disabled:bg-gray-100 dark:border-gray-800 dark:bg-gray-950 dark:disabled:bg-gray-900"><p v-if="createForm.errors['metadata.annual_quota_days']" class="mt-1 text-xs text-red-600">{{ createForm.errors['metadata.annual_quota_days'] }}</p></div><div><label class="mb-1.5 block text-xs font-bold text-slate-500">Maximum par demande</label><input v-model="createForm.metadata.max_days_per_request" type="number" min="0.01" max="366" step="0.01" placeholder="Aucune limite" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"></div><div><label class="mb-1.5 block text-xs font-bold text-slate-500">Mode de décompte</label><select v-model="createForm.metadata.day_count_method" required class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"><option v-for="method in leaveDayCountMethods" :key="method.value" :value="method.value">{{ method.label }}</option></select></div></div></fieldset>
                </form>

                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                    <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-900"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Valeurs du référentiel</p></div>
                    <div v-if="currentItems.length" class="divide-y divide-gray-100 dark:divide-gray-900">
                        <article v-for="item in currentItems" :key="item.uuid" class="p-4 sm:p-5">
                            <form v-if="editingUuid === item.uuid" class="space-y-4" @submit.prevent="submitEdit(item)"><div class="grid gap-3 md:grid-cols-[1fr_2fr_100px_auto]"><div><label class="mb-1 block text-[11px] font-bold uppercase text-slate-400">Code</label><input v-model="editForm.code" required class="h-9 w-full rounded-lg border border-primary-300 px-2 text-xs dark:bg-gray-950"></div><div><label class="mb-1 block text-[11px] font-bold uppercase text-slate-400">Libellé</label><input v-model="editForm.label" required class="h-9 w-full rounded-lg border border-primary-300 px-2 text-sm dark:bg-gray-950"><p v-if="editForm.errors.label || editForm.errors.code" class="mt-1 text-xs text-red-600">{{ editForm.errors.label || editForm.errors.code }}</p></div><div><label class="mb-1 block text-[11px] font-bold uppercase text-slate-400">Ordre</label><input v-model="editForm.position" type="number" min="0" class="h-9 w-full rounded-lg border border-primary-300 px-2 text-sm dark:bg-gray-950"></div><div class="flex items-end justify-end gap-1"><Button icon size="rg" title="Enregistrer"><Icon name="check" /></Button><Button type="button" icon size="rg" variant="white-outline" title="Annuler" @click="editingUuid = null"><Icon name="cross" /></Button></div></div><div v-if="item.type === 'LEAVE_TYPE'" class="grid gap-3 rounded-xl border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-900 dark:bg-amber-950/20 md:grid-cols-2 xl:grid-cols-3"><label class="flex items-center gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><input v-model="editForm.metadata.consumes_annual_balance" type="checkbox" class="rounded text-amber-600">Consomme le solde annuel</label><label class="flex items-center gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><input v-model="editForm.metadata.requires_approval" type="checkbox" class="rounded text-amber-600">Validation requise</label><label class="flex items-center gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><input v-model="editForm.metadata.requires_attachment" type="checkbox" class="rounded text-amber-600">Justificatif obligatoire</label><label class="text-xs text-slate-500">Quota annuel<input v-model="editForm.metadata.annual_quota_days" type="number" min="0.01" max="366" step="0.01" :required="editForm.metadata.consumes_annual_balance" :disabled="!editForm.metadata.consumes_annual_balance" class="mt-1 h-9 w-full rounded-lg border border-gray-200 px-2 dark:border-gray-800 dark:bg-gray-950"></label><label class="text-xs text-slate-500">Maximum par demande<input v-model="editForm.metadata.max_days_per_request" type="number" min="0.01" max="366" step="0.01" class="mt-1 h-9 w-full rounded-lg border border-gray-200 px-2 dark:border-gray-800 dark:bg-gray-950"></label><label class="text-xs text-slate-500">Mode de décompte<select v-model="editForm.metadata.day_count_method" class="mt-1 h-9 w-full rounded-lg border border-gray-200 px-2 dark:border-gray-800 dark:bg-gray-950"><option v-for="method in leaveDayCountMethods" :key="method.value" :value="method.value">{{ method.label }}</option></select></label><p v-if="Object.keys(editForm.errors).some((key) => key.startsWith('metadata.'))" class="text-xs text-red-600 md:col-span-2 xl:col-span-3">{{ Object.entries(editForm.errors).find(([key]) => key.startsWith('metadata.'))?.[1] }}</p></div></form>
                            <template v-else><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><span class="rounded-md bg-gray-100 px-2 py-1 font-mono text-xs font-bold text-slate-500 dark:bg-gray-900">{{ item.code }}</span><h3 :class="['text-sm font-bold', item.archived ? 'line-through text-slate-400' : 'text-slate-700 dark:text-white']">{{ item.label }}</h3><span v-if="item.archived" class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-slate-500 dark:bg-gray-900">Archivé</span></div><p class="mt-1.5 truncate font-mono text-[10px] text-slate-300">{{ item.uuid }}</p><p v-if="item.delete_reason" class="mt-2 text-xs text-slate-400">Motif : {{ item.delete_reason }}</p></div><div class="flex shrink-0 items-center gap-2"><span class="me-2 text-xs text-slate-400">Ordre {{ item.position }}</span><Button v-if="!item.archived && can('hr_settings.update')" icon size="rg" variant="white-outline" title="Modifier" @click="startEdit(item)"><Icon name="edit" /></Button><Button v-if="item.archived && can('hr_settings.restore')" icon size="rg" variant="white-outline" title="Restaurer" @click="restore(item)"><Icon name="undo" /></Button></div></div>
                            <details v-if="!item.archived && can('hr_settings.archive')" class="group mt-3"><summary class="cursor-pointer list-none text-xs font-bold text-red-600"><span class="inline-flex items-center gap-1.5"><Icon name="archive" /> Archiver cette valeur <Icon class="transition group-open:rotate-180" name="chevron-down" /></span></summary><form class="mt-3 flex flex-col gap-2 rounded-lg bg-red-50/60 p-3 dark:bg-red-950/20 sm:flex-row" @submit.prevent="archive(item)"><input v-model="archiveForm(item.uuid).reason" required maxlength="1000" class="h-9 min-w-0 flex-1 rounded-lg border border-red-200 px-3 text-xs dark:border-red-900 dark:bg-gray-950" placeholder="Motif obligatoire"><Button size="sm" variant="danger" :disabled="archiveForm(item.uuid).processing">Confirmer l’archivage</Button></form><p v-if="archiveForm(item.uuid).errors.reason" class="mt-1 text-xs text-red-600">{{ archiveForm(item.uuid).errors.reason }}</p></details></template>
                        </article>
                    </div>
                    <HrEmptyState v-else icon="settings" title="Aucune valeur configurée" description="Ajoutez la première valeur de ce référentiel pour la rendre disponible dans les formulaires." />
                </section>

                <aside v-if="selectedType === 'ATTESTATION_TYPE'" class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-300"><Icon class="mt-0.5 shrink-0 text-lg" name="info" /><p><strong>Types d’attestation.</strong> Ajoutez uniquement les attestations validées par l’Administration/RH. Elles seront disponibles lors du dépôt d’un document dans le dossier employé.</p></aside>
            </main>
        </div>
    </div>
</template>
