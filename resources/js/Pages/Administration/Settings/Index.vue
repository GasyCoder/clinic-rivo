<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from '../Partials/HrNav.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ references: Object, types: [Array, Object] });
const { can } = usePermissions();
const selectedType = ref(props.types?.[0]?.value ?? 'DEPARTMENT');
const editingUuid = ref(null);
const createForm = useForm({ type: selectedType.value, code: '', label: '', active: true, position: 0 });
const editForm = useForm({ type: '', code: '', label: '', active: true, position: 0 });
const archiveForms = ref({});
const currentItems = computed(() => props.references?.[selectedType.value] ?? []);
const chooseType = (type) => { selectedType.value = type; createForm.type = type; editingUuid.value = null; };
const submitCreate = () => createForm.post('/administration/settings', { preserveScroll: true, onSuccess: () => createForm.reset('code', 'label') });
const startEdit = (item) => { editingUuid.value = item.uuid; editForm.type = item.type; editForm.code = item.code; editForm.label = item.label; editForm.active = item.active; editForm.position = item.position; editForm.clearErrors(); };
const submitEdit = (item) => editForm.put(`/administration/settings/${item.uuid}`, { preserveScroll: true, onSuccess: () => editingUuid.value = null });
const archiveForm = (uuid) => archiveForms.value[uuid] ??= useForm({ reason: '' });
const archive = (item) => archiveForm(item.uuid).delete(`/administration/settings/${item.uuid}`, { preserveScroll: true });
const restore = (item) => router.post(`/administration/settings/${item.uuid}/restore`, {}, { preserveScroll: true });
</script>

<template>
    <Head title="Paramètres RH" />
    <div class="space-y-5">
        <HrNav />
        <header><p class="text-xs font-bold uppercase tracking-[.15em] text-slate-500">Référentiels configurables</p><h1 class="mt-1 font-heading text-3xl font-bold text-slate-800 dark:text-white">Paramètres RH</h1><p class="mt-2 text-sm text-slate-500">Départements, fonctions, contrats et attestations sont administrables sans modifier le code.</p></header>
        <div class="grid gap-5 xl:grid-cols-[260px_minmax(0,1fr)]">
            <nav class="h-fit rounded-xl border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-900 dark:bg-gray-950" aria-label="Types de paramètres"><button v-for="type in types" :key="type.value" type="button" :class="['flex w-full items-center justify-between rounded-lg px-3 py-3 text-start text-sm font-bold transition', selectedType === type.value ? 'bg-primary-600 text-white' : 'text-slate-600 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-gray-900']" @click="chooseType(type.value)"><span>{{ type.label }}</span><span :class="['rounded-full px-2 py-0.5 text-xs', selectedType === type.value ? 'bg-white/15' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ references?.[type.value]?.length ?? 0 }}</span></button></nav>
            <div class="space-y-4">
                <form v-if="can('hr_settings.create')" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950" @submit.prevent="submitCreate"><div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-50 text-primary-600"><Icon name="plus" /></span><div><h2 class="font-bold text-slate-800 dark:text-white">Ajouter une valeur</h2><p class="text-xs text-slate-500">Le code est stable ; le libellé est affiché dans les formulaires.</p></div></div><div class="mt-4 grid gap-3 md:grid-cols-[1fr_2fr_110px_auto]"><div><input v-model="createForm.code" required maxlength="80" placeholder="CODE" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm uppercase dark:border-gray-800 dark:bg-gray-950"><p v-if="createForm.errors.code" class="mt-1 text-xs text-red-600">{{ createForm.errors.code }}</p></div><div><input v-model="createForm.label" required maxlength="255" placeholder="Libellé affiché" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"><p v-if="createForm.errors.label" class="mt-1 text-xs text-red-600">{{ createForm.errors.label }}</p></div><input v-model="createForm.position" type="number" min="0" max="65535" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950" title="Ordre"><Button size="rg" :disabled="createForm.processing"><Icon name="check" /><span class="ms-2">Ajouter</span></Button></div></form>
                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950"><div class="grid grid-cols-[110px_minmax(0,1fr)_90px_140px] gap-3 bg-gray-50/70 px-5 py-3 text-xs font-bold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40"><span>Code</span><span>Libellé</span><span>Ordre</span><span class="text-end">Actions</span></div><div v-for="item in currentItems" :key="item.uuid" class="border-t border-gray-100 px-5 py-3 dark:border-gray-900"><form v-if="editingUuid === item.uuid" class="grid grid-cols-[110px_minmax(0,1fr)_90px_140px] items-start gap-3" @submit.prevent="submitEdit(item)"><input v-model="editForm.code" required class="h-9 rounded-lg border border-primary-300 px-2 text-xs dark:bg-gray-950"><div><input v-model="editForm.label" required class="h-9 w-full rounded-lg border border-primary-300 px-2 text-sm dark:bg-gray-950"><p v-if="editForm.errors.label || editForm.errors.code" class="mt-1 text-xs text-red-600">{{ editForm.errors.label || editForm.errors.code }}</p></div><input v-model="editForm.position" type="number" min="0" class="h-9 rounded-lg border border-primary-300 px-2 text-sm dark:bg-gray-950"><div class="flex justify-end gap-1"><Button icon size="rg" title="Enregistrer"><Icon name="check" /></Button><Button type="button" icon size="rg" variant="white-outline" title="Annuler" @click="editingUuid = null"><Icon name="cross" /></Button></div></form><div v-else class="grid grid-cols-[110px_minmax(0,1fr)_90px_140px] items-center gap-3"><span class="font-mono text-xs font-bold text-slate-500">{{ item.code }}</span><div><p :class="['text-sm font-semibold', item.archived ? 'line-through text-slate-400' : 'text-slate-700 dark:text-white']">{{ item.label }}</p><p class="mt-0.5 truncate font-mono text-[10px] text-slate-300">{{ item.uuid }}</p><p v-if="item.delete_reason" class="mt-1 text-xs text-slate-400">{{ item.delete_reason }}</p></div><span class="text-sm text-slate-500">{{ item.position }}</span><div class="flex justify-end gap-1"><Button v-if="!item.archived && can('hr_settings.update')" icon size="rg" variant="white-outline" title="Modifier" @click="startEdit(item)"><Icon name="edit" /></Button><Button v-if="item.archived && can('hr_settings.restore')" icon size="rg" variant="white-outline" title="Restaurer" @click="restore(item)"><Icon name="undo" /></Button></div></div><details v-if="!item.archived && can('hr_settings.archive')" class="mt-2"><summary class="cursor-pointer text-xs font-bold text-red-600">Archiver cette valeur</summary><form class="mt-2 flex gap-2" @submit.prevent="archive(item)"><input v-model="archiveForm(item.uuid).reason" required maxlength="1000" class="h-9 min-w-0 flex-1 rounded-lg border border-gray-200 px-3 text-xs dark:border-gray-800 dark:bg-gray-950" placeholder="Motif obligatoire"><Button size="sm" variant="danger" :disabled="archiveForm(item.uuid).processing">Archiver</Button></form><p v-if="archiveForm(item.uuid).errors.reason" class="mt-1 text-xs text-red-600">{{ archiveForm(item.uuid).errors.reason }}</p></details></div><p v-if="!currentItems.length" class="px-5 py-14 text-center text-sm text-slate-400">Aucune valeur configurée pour ce type.</p></section>
                <aside v-if="selectedType === 'ATTESTATION_TYPE'" class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800"><strong>Types d’attestation.</strong> Ajoutez ici uniquement les attestations validées par l’Administration/RH. Elles deviennent ensuite disponibles lors du dépôt d’un document dans le dossier employé.</aside>
            </div>
        </div>
    </div>
</template>
