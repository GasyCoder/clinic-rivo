<script setup>
import { ref } from 'vue';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

const props = defineProps({
    registers: Array,
    filters: Object,
    summary: Object,
});

const { can } = usePermissions();

const statusOptions = [
    { value: 'active', label: 'Actives' },
    { value: 'archived', label: 'Archivées' },
    { value: 'all', label: 'Toutes' },
];
const changeStatus = (status) => router.get('/administration/cash-registers', { status }, { preserveState: true, replace: true });

const createForm = useForm({ name: '' });
const createRegister = () => createForm.post('/administration/cash-registers', {
    preserveScroll: true,
    onSuccess: () => createForm.reset(),
});

const registerToRename = ref(null);
const renameForm = useForm({ name: '' });
const openRename = (register) => {
    registerToRename.value = register;
    renameForm.clearErrors();
    renameForm.name = register.name;
};
const closeRename = () => {
    if (!renameForm.processing) registerToRename.value = null;
};
const submitRename = () => renameForm.put(`/administration/cash-registers/${registerToRename.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { registerToRename.value = null; },
});

const registerToArchive = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (register) => {
    registerToArchive.value = register;
    archiveForm.clearErrors();
    archiveForm.reason = '';
};
const closeArchive = () => {
    if (!archiveForm.processing) registerToArchive.value = null;
};
const submitArchive = () => archiveForm.delete(`/administration/cash-registers/${registerToArchive.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { registerToArchive.value = null; },
});

const restoreRegister = (register) => router.post(`/administration/cash-registers/${register.uuid}/restore`, {}, { preserveScroll: true });
const toggleActive = (register) => router.post(
    `/administration/cash-registers/${register.uuid}/${register.active ? 'deactivate' : 'activate'}`,
    {},
    { preserveScroll: true },
);
</script>

<template>
    <Head title="Caisses" />
    <div class="w-full space-y-5">
        <header>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Administration</p>
            <h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Caisses nommées</h1>
            <p class="mt-1 text-sm text-slate-500">Le site garde une seule caisse ouverte à la fois ; ces noms permettent seulement de savoir laquelle a servi, avec un historique complet par caisse.</p>
        </header>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                <div class="inline-flex rounded-md bg-gray-100 p-1 dark:bg-gray-900">
                    <button
                        v-for="option in statusOptions"
                        :key="option.value"
                        type="button"
                        :class="['h-8 rounded px-3 text-xs font-bold transition-all', filters.status === option.value ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500 dark:text-slate-400']"
                        @click="changeStatus(option.value)"
                    >{{ option.label }}</button>
                </div>
                <p class="text-xs text-slate-400">{{ summary.active }} active{{ summary.active > 1 ? 's' : '' }} · {{ summary.archived }} archivée{{ summary.archived > 1 ? 's' : '' }}</p>
            </div>

            <form v-if="can('cash_registers.create')" class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 sm:flex-row sm:items-end" @submit.prevent="createRegister">
                <FormGroup class="!mb-0 flex-1">
                    <FormLabel class="mb-1.5" for="new_register_name">Nouvelle caisse</FormLabel>
                    <Input id="new_register_name" v-model="createForm.name" placeholder="Ex. Caisse 2" required />
                    <FormError v-if="createForm.errors.name">{{ createForm.errors.name }}</FormError>
                </FormGroup>
                <Button size="rg" type="submit" :disabled="createForm.processing"><Icon class="text-lg" name="plus" /><span class="ms-2">Créer</span></Button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Caisse</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Statut</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Sessions</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Actions</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="register in registers" :key="register.uuid" class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000/30">
                            <td class="px-4 py-3"><span class="text-sm font-bold text-slate-700 dark:text-white">{{ register.name }}</span><p v-if="register.archived" class="mt-0.5 text-xs text-slate-400">Archivée {{ formatDateTime(register.archived_at) }} — {{ register.archive_reason }}</p></td>
                            <td class="px-4 py-3">
                                <span v-if="register.archived" class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-2 py-0.5 text-[11px] font-bold text-slate-500 dark:border-gray-800 dark:text-slate-400"><span class="h-1.5 w-1.5 rounded-full bg-slate-300"></span>Archivée</span>
                                <span v-else-if="register.active" class="inline-flex items-center gap-1.5 rounded-full border border-green-200 px-2 py-0.5 text-[11px] font-bold text-green-700 dark:border-green-900 dark:text-green-300"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>Active</span>
                                <span v-else class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 px-2 py-0.5 text-[11px] font-bold text-amber-700 dark:border-amber-900 dark:text-amber-300"><span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>Inactive</span>
                            </td>
                            <td class="px-4 py-3 text-end text-sm text-slate-500">{{ register.sessions_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <Button v-if="!register.archived && can('cash_registers.update')" size="sm" variant="white-outline" type="button" @click="openRename(register)"><Icon class="text-sm" name="edit" /><span class="ms-1.5">Renommer</span></Button>
                                    <Button v-if="!register.archived && (register.active ? can('cash_registers.deactivate') : can('cash_registers.activate'))" size="sm" :variant="register.active ? 'warning' : 'success'" type="button" @click="toggleActive(register)"><Icon class="text-sm" :name="register.active ? 'toggle-off' : 'toggle-on'" /><span class="ms-1.5">{{ register.active ? 'Désactiver' : 'Activer' }}</span></Button>
                                    <Button v-if="!register.archived && can('cash_registers.archive')" size="sm" variant="danger-outline" type="button" @click="openArchive(register)"><Icon class="text-sm" name="archive" /><span class="ms-1.5">Archiver</span></Button>
                                    <Button v-if="register.archived && can('cash_registers.restore')" size="sm" variant="white-outline" type="button" @click="restoreRegister(register)"><Icon class="text-sm" name="undo" /><span class="ms-1.5">Restaurer</span></Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="registers.length === 0"><td colspan="4" class="px-5 py-10 text-center"><Icon class="text-2xl text-slate-300" name="wallet" /><p class="mt-2 text-sm font-medium text-slate-500">Aucune caisse dans ce filtre.</p></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Dialog :open="Boolean(registerToRename)" as="div" class="relative z-[1300]" @close="closeRename">
            <div class="fixed inset-0 bg-slate-950/55 backdrop-blur-[1px]" aria-hidden="true"></div>
            <div class="fixed inset-0 overflow-y-auto p-4">
                <div class="flex min-h-full items-center justify-center">
                    <DialogPanel v-if="registerToRename" class="w-full max-w-sm overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-950">
                        <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <DialogTitle class="font-heading text-base font-bold text-slate-700 dark:text-white">Renommer la caisse</DialogTitle>
                            <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 transition-colors hover:bg-gray-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-50 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer" :disabled="renameForm.processing" @click="closeRename"><Icon class="text-xl" name="cross" /></button>
                        </header>
                        <form @submit.prevent="submitRename">
                            <div class="space-y-3 px-5 py-4">
                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="rename_register_name">Nom</FormLabel>
                                    <Input id="rename_register_name" v-model="renameForm.name" required autofocus />
                                    <FormError v-if="renameForm.errors.name">{{ renameForm.errors.name }}</FormError>
                                </FormGroup>
                            </div>
                            <footer class="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-50/60 px-5 py-4 dark:border-gray-800 dark:bg-gray-1000/30 sm:flex-row sm:justify-end">
                                <Button type="button" size="rg" variant="white-outline" :disabled="renameForm.processing" @click="closeRename">Annuler</Button>
                                <Button type="submit" size="rg" variant="primary" :disabled="renameForm.processing">{{ renameForm.processing ? 'Enregistrement…' : 'Enregistrer' }}</Button>
                            </footer>
                        </form>
                    </DialogPanel>
                </div>
            </div>
        </Dialog>

        <Dialog :open="Boolean(registerToArchive)" as="div" class="relative z-[1300]" @close="closeArchive">
            <div class="fixed inset-0 bg-slate-950/55 backdrop-blur-[1px]" aria-hidden="true"></div>
            <div class="fixed inset-0 overflow-y-auto p-4">
                <div class="flex min-h-full items-center justify-center">
                    <DialogPanel v-if="registerToArchive" class="w-full max-w-md overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-950">
                        <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-300"><Icon class="text-xl" name="archive" /></span>
                                <div class="min-w-0">
                                    <DialogTitle class="font-heading text-base font-bold text-slate-700 dark:text-white">Archiver « {{ registerToArchive.name }} » ?</DialogTitle>
                                    <p class="mt-1 text-xs leading-5 text-slate-400">Son historique de sessions reste consultable ; elle ne sera plus proposée à l’ouverture de caisse.</p>
                                </div>
                            </div>
                            <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 transition-colors hover:bg-gray-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-50 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer" :disabled="archiveForm.processing" @click="closeArchive"><Icon class="text-xl" name="cross" /></button>
                        </header>
                        <form @submit.prevent="submitArchive">
                            <div class="space-y-3 px-5 py-4">
                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="archive_register_reason">Motif <span class="text-red-500">*</span></FormLabel>
                                    <Input id="archive_register_reason" v-model="archiveForm.reason" placeholder="Ex. Fusionnée avec une autre caisse" required autofocus />
                                    <FormError v-if="archiveForm.errors.reason">{{ archiveForm.errors.reason }}</FormError>
                                </FormGroup>
                                <FormError v-if="archiveForm.errors.register">{{ archiveForm.errors.register }}</FormError>
                            </div>
                            <footer class="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-50/60 px-5 py-4 dark:border-gray-800 dark:bg-gray-1000/30 sm:flex-row sm:justify-end">
                                <Button type="button" size="rg" variant="white-outline" :disabled="archiveForm.processing" @click="closeArchive">Conserver</Button>
                                <Button type="submit" size="rg" variant="danger" :disabled="archiveForm.processing"><Icon class="me-2 text-base" name="archive" />{{ archiveForm.processing ? 'Archivage…' : 'Confirmer l’archivage' }}</Button>
                            </footer>
                        </form>
                    </DialogPanel>
                </div>
            </div>
        </Dialog>
    </div>
</template>
