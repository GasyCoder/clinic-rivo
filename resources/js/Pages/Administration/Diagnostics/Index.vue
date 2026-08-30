<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({ diagnostics: Array, filters: Object, summary: Object });
const { can } = usePermissions();
const search = ref(props.filters.q ?? '');

const applyFilters = (status = props.filters.status) => router.get(
    '/administration/diagnostics',
    { q: search.value || undefined, status },
    { preserveState: true, replace: true },
);

const emptyForm = () => ({ code: '', name: '', category: '', description: '' });
const createForm = useForm(emptyForm());
const createDiagnostic = () => createForm.post('/administration/diagnostics', {
    preserveScroll: true,
    onSuccess: () => createForm.reset(),
});

const editingUuid = ref(null);
const editForm = useForm(emptyForm());
const startEdit = (diagnostic) => {
    editingUuid.value = diagnostic.uuid;
    editForm.clearErrors();
    editForm.code = diagnostic.code ?? '';
    editForm.name = diagnostic.name;
    editForm.category = diagnostic.category ?? '';
    editForm.description = diagnostic.description ?? '';
};
const closeEdit = () => {
    if (!editForm.processing) editingUuid.value = null;
};
const saveEdit = () => editForm.put(`/administration/diagnostics/${editingUuid.value}`, {
    preserveScroll: true,
    onSuccess: closeEdit,
});
const toggleActive = (diagnostic) => router.post(
    `/administration/diagnostics/${diagnostic.uuid}/${diagnostic.is_active ? 'deactivate' : 'activate'}`,
    {},
    { preserveScroll: true },
);
</script>

<template>
    <Head title="Référentiel des diagnostics" />
    <div class="w-full space-y-5">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Administration · Référentiel clinique</p>
                <h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Diagnostics</h1>
                <p class="mt-1 text-sm text-slate-500">Libellés proposés aux médecins. Une modification ne change jamais les snapshots déjà enregistrés dans les dossiers.</p>
            </div>
            <p class="text-xs tabular-nums text-slate-400">{{ summary.active }} actifs · {{ summary.inactive }} inactifs</p>
        </header>

        <section v-if="can('diagnostic_catalog.manage')" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="mb-4 flex items-center gap-2"><Icon class="text-lg text-primary-600" name="plus" /><h2 class="text-sm font-bold text-slate-700 dark:text-white">Ajouter au référentiel</h2></div>
            <form class="grid gap-3 lg:grid-cols-[9rem_minmax(14rem,1.3fr)_minmax(12rem,1fr)_minmax(16rem,1.5fr)_auto] lg:items-start" @submit.prevent="createDiagnostic">
                <div><label for="diagnostic_code" class="mb-1.5 block text-xs font-semibold text-slate-600">Code</label><Input id="diagnostic_code" v-model="createForm.code" placeholder="Facultatif" /><FormError :message="createForm.errors.code" /></div>
                <div><label for="diagnostic_name" class="mb-1.5 block text-xs font-semibold text-slate-600">Libellé *</label><Input id="diagnostic_name" v-model="createForm.name" required placeholder="Ex. Appendicite aiguë" /><FormError :message="createForm.errors.name" /></div>
                <div><label for="diagnostic_category" class="mb-1.5 block text-xs font-semibold text-slate-600">Catégorie</label><Input id="diagnostic_category" v-model="createForm.category" placeholder="Facultatif" /><FormError :message="createForm.errors.category" /></div>
                <div><label for="diagnostic_description" class="mb-1.5 block text-xs font-semibold text-slate-600">Description</label><Input id="diagnostic_description" v-model="createForm.description" placeholder="Précision facultative" /><FormError :message="createForm.errors.description" /></div>
                <Button class="lg:mt-[25px]" size="rg" type="submit" :disabled="createForm.processing"><Icon class="me-2 text-base" name="plus" />Ajouter</Button>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 md:flex-row md:items-center md:justify-between">
                <form class="flex w-full max-w-xl gap-2" @submit.prevent="applyFilters()">
                    <IconInput v-model="search" class="flex-1" icon="search" placeholder="Rechercher par libellé, code ou catégorie" />
                    <Button size="rg" variant="white-outline" type="submit">Rechercher</Button>
                </form>
                <div class="inline-flex self-start rounded-md bg-gray-100 p-1 dark:bg-gray-900">
                    <button v-for="option in [{ value: 'active', label: 'Actifs' }, { value: 'inactive', label: 'Inactifs' }, { value: 'all', label: 'Tous' }]" :key="option.value" type="button" :class="['h-8 rounded px-3 text-xs font-bold', filters.status === option.value ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500']" @click="applyFilters(option.value)">{{ option.label }}</button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Code</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Diagnostic</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Catégorie</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Utilisations</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Actions</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <template v-for="diagnostic in diagnostics" :key="diagnostic.uuid">
                            <tr class="hover:bg-gray-50/60 dark:hover:bg-gray-1000/30">
                                <td class="px-4 py-3 font-mono text-xs font-bold text-slate-500">{{ diagnostic.code || '—' }}</td>
                                <td class="px-4 py-3"><div class="flex items-center gap-2"><span class="text-sm font-bold text-slate-700 dark:text-white">{{ diagnostic.name }}</span><span :class="['rounded px-1.5 py-0.5 text-[10px] font-bold uppercase', diagnostic.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ diagnostic.is_active ? 'Actif' : 'Inactif' }}</span></div><p v-if="diagnostic.description" class="mt-1 max-w-2xl text-xs text-slate-400">{{ diagnostic.description }}</p></td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ diagnostic.category || '—' }}</td>
                                <td class="px-4 py-3 text-end text-sm tabular-nums text-slate-500">{{ diagnostic.diagnoses_count }}</td>
                                <td class="px-4 py-3"><div v-if="can('diagnostic_catalog.manage')" class="flex justify-end gap-2"><Button type="button" size="sm" variant="white-outline" @click="startEdit(diagnostic)"><Icon class="me-1.5" name="edit" />Modifier</Button><Button type="button" size="sm" :variant="diagnostic.is_active ? 'warning' : 'success'" @click="toggleActive(diagnostic)">{{ diagnostic.is_active ? 'Désactiver' : 'Activer' }}</Button></div></td>
                            </tr>
                            <tr v-if="editingUuid === diagnostic.uuid" class="bg-primary-50/30 dark:bg-primary-950/10"><td colspan="5" class="p-4"><form class="grid gap-3 lg:grid-cols-[9rem_1.3fr_1fr_1.5fr_auto] lg:items-start" @submit.prevent="saveEdit"><Input v-model="editForm.code" placeholder="Code" /><Input v-model="editForm.name" required placeholder="Libellé" /><Input v-model="editForm.category" placeholder="Catégorie" /><Input v-model="editForm.description" placeholder="Description" /><div class="flex gap-2"><Button size="rg" type="submit" :disabled="editForm.processing">Enregistrer</Button><Button size="rg" type="button" variant="white-outline" @click="closeEdit">Annuler</Button></div><FormError class="lg:col-span-5" :message="editForm.errors.code || editForm.errors.name || editForm.errors.category || editForm.errors.description" /></form></td></tr>
                        </template>
                        <tr v-if="diagnostics.length === 0"><td colspan="5" class="px-5 py-12 text-center"><Icon class="text-2xl text-slate-300" name="search" /><p class="mt-2 text-sm font-medium text-slate-500">Aucun diagnostic ne correspond à ce filtre.</p></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
