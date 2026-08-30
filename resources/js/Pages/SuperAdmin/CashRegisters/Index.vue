<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({ sites: Array, filters: Object });
const { can } = usePermissions();
const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const showCreate = ref(false);
const editing = ref(null);
const archiving = ref(null);
const createForm = useForm({ site_code: selectedSiteCode.value, name: '' });
const editForm = useForm({ name: '' });
const archiveForm = useForm({ reason: '' });

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const registers = computed(() => selectedSite.value?.data ?? []);

const selectSite = (code) => {
    selectedSiteCode.value = code;
    createForm.site_code = code;
    showCreate.value = false;
    editing.value = null;
};

const applyFilters = (event) => {
    const form = new FormData(event.currentTarget);
    router.get('/super-admin/cash-registers', { search: form.get('search'), status: form.get('status') }, { preserveState: true, replace: true });
};

const submitCreate = () => createForm.post('/super-admin/cash-registers', {
    preserveScroll: true,
    onSuccess: () => { createForm.reset('name'); showCreate.value = false; },
});

const startEdit = (register) => {
    editing.value = register;
    editForm.name = register.name;
    editForm.clearErrors();
};

const submitEdit = () => editForm.put(`/super-admin/cash-registers/${selectedSiteCode.value}/${editing.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { editing.value = null; editForm.reset(); },
});

const openArchive = (register) => { archiving.value = register; archiveForm.reset(); archiveForm.clearErrors(); };
const submitArchive = () => archiveForm.delete(`/super-admin/cash-registers/${selectedSiteCode.value}/${archiving.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; archiveForm.reset(); },
});
const restore = (register) => router.post(`/super-admin/cash-registers/${selectedSiteCode.value}/${register.uuid}/restore`, {}, { preserveScroll: true });
const toggleActive = (register) => router.post(
    `/super-admin/cash-registers/${selectedSiteCode.value}/${register.uuid}/${register.active ? 'deactivate' : 'activate'}`,
    {},
    { preserveScroll: true },
);
</script>

<template>
    <Head title="Caisses" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-xl" name="wallet" /></span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Caisses nommées</h1>
                    <p class="mt-1 text-sm text-slate-500">Un site garde une seule caisse ouverte à la fois ; ces noms permettent seulement de savoir laquelle a servi.</p>
                </div>
            </div>
            <Button v-if="can('cash_registers.create')" size="rg" type="button" :disabled="!selectedSite?.ok" :title="selectedSite?.ok ? 'Ajouter une caisse' : 'Configurez et connectez l’API du site pour ajouter une caisse'" @click="showCreate = !showCreate"><Icon class="text-lg" :name="showCreate ? 'cross' : 'plus'" /><span class="ms-2">{{ showCreate ? 'Fermer' : 'Nouvelle caisse' }}</span></Button>
        </header>

        <form v-if="showCreate" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950" @submit.prevent="submitCreate">
            <div class="grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)_auto] lg:items-end">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Site <span class="text-red-500">*</span></label>
                    <select v-model="createForm.site_code" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                        <option v-for="site in sites" :key="site.site.code" :value="site.site.code" :disabled="!site.ok">{{ site.site.name }}{{ site.ok ? '' : ' — indisponible' }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom de la caisse <span class="text-red-500">*</span></label>
                    <input v-model="createForm.name" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Ex. Caisse 2">
                    <p v-if="createForm.errors.name" class="mt-1 text-xs text-red-600">{{ createForm.errors.name }}</p>
                    <p v-if="createForm.errors.site_code" class="mt-1 text-xs text-red-600">{{ createForm.errors.site_code }}</p>
                </div>
                <Button size="rg" :disabled="createForm.processing"><Icon class="text-lg" name="check" /><span class="ms-2">Enregistrer</span></Button>
            </div>
        </form>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 xl:flex-row xl:items-center xl:justify-between">
                <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded bg-gray-100 p-1 dark:bg-gray-900">
                    <button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex shrink-0 items-center gap-2 rounded px-3 py-2 text-xs font-bold', selectedSiteCode === site.site.code ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500']" @click="selectSite(site.site.code)">
                        <span :class="['h-1.5 w-1.5 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'OFFLINE' || site.status === 'ERROR' ? 'bg-red-500' : 'bg-slate-300']" />{{ site.site.name }}<span v-if="site.ok" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] dark:bg-gray-900">{{ site.meta?.summary?.displayed ?? 0 }}</span>
                    </button>
                </div>
                <form class="flex flex-col gap-2 sm:flex-row" @submit.prevent="applyFilters">
                    <label class="relative block sm:w-64"><Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" /><input name="search" :value="filters.search" type="search" class="h-9 w-full rounded border border-gray-200 bg-white ps-10 pe-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Rechercher une caisse"></label>
                    <select name="status" :value="filters.status" class="h-9 rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="ACTIVE">Actives</option><option value="ARCHIVED">Archivées</option><option value="ALL">Toutes</option></select>
                    <Button size="sm" variant="white-outline">Filtrer</Button>
                </form>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-56 flex-col items-center justify-center px-6 py-10 text-center">
                <span class="flex h-11 w-11 items-center justify-center rounded bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="server" /></span>
                <h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Référentiel indisponible pour {{ selectedSite?.site.name }}</h2>
                <p class="mt-1 max-w-lg text-xs leading-5 text-slate-500">{{ selectedSite?.message }}</p>
            </div>

            <div v-else>
                <div class="grid grid-cols-[minmax(0,1fr)_140px_100px_180px] border-b border-gray-200 bg-gray-50 px-5 py-3 text-[11px] font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900 dark:bg-gray-1000">
                    <span>Caisse et session</span><span>Référentiel</span><span class="text-end">Historique</span><span class="text-end">Actions</span>
                </div>
                <div v-for="register in registers" :key="register.uuid" class="grid grid-cols-[minmax(0,1fr)_140px_100px_180px] items-center border-b border-gray-200 px-5 py-3 last:border-0 dark:border-gray-900">
                    <div v-if="editing?.uuid === register.uuid" class="me-4">
                        <input v-model="editForm.name" class="h-9 w-full rounded border border-primary-400 bg-white px-3 text-sm text-slate-700 outline-none ring-2 ring-primary-100 dark:bg-gray-950 dark:text-white">
                        <p v-if="editForm.errors.name" class="mt-1 text-xs text-red-600">{{ editForm.errors.name }}</p>
                    </div>
                    <div v-else>
                        <Link :href="`/super-admin/cash-registers/${selectedSiteCode}/${register.uuid}`" class="font-bold text-slate-700 hover:text-primary-600 dark:text-white dark:hover:text-primary-300">{{ register.name }}</Link>
                        <p v-if="register.session" :class="['mt-1 border-s-2 ps-2 text-[11px] font-medium', register.session.status === 'LOCKED' ? 'border-amber-500 text-amber-700 dark:text-amber-300' : 'border-emerald-500 text-emerald-700 dark:text-emerald-300']">
                            {{ register.session.status === 'LOCKED' ? 'Session verrouillée' : 'Session ouverte' }} · {{ register.session.session_number }} · {{ register.session.opened_by }}
                        </p>
                        <p v-else-if="!register.archived" class="mt-1 text-[11px] text-slate-400">Aucune session active</p>
                        <p v-if="register.archived" class="mt-0.5 text-[11px] text-slate-400">{{ register.archive_reason }}</p>
                    </div>
                    <span>
                        <span v-if="register.archived" class="inline-flex rounded border border-gray-200 bg-gray-50 px-2 py-1 text-[11px] font-bold text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-400">Archivée</span>
                        <span v-else-if="register.active" class="inline-flex rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-bold text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">Active</span>
                        <span v-else class="inline-flex rounded border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-bold text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">Inactive</span>
                    </span>
                    <span class="text-end text-sm text-slate-500">{{ register.sessions_count }}</span>
                    <div class="flex justify-end gap-2">
                        <template v-if="editing?.uuid === register.uuid">
                            <button type="button" class="flex h-8 w-8 items-center justify-center rounded border border-primary-200 text-primary-600" title="Enregistrer" :disabled="editForm.processing" @click="submitEdit"><Icon name="check" /></button>
                            <button type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 dark:border-gray-800" title="Annuler" @click="editing = null"><Icon name="cross" /></button>
                        </template>
                        <template v-else-if="!register.archived">
                            <Button :as="Link" :href="`/super-admin/cash-registers/${selectedSiteCode}/${register.uuid}`" icon size="rg" variant="white-outline" title="Voir la fiche de supervision" aria-label="Voir la fiche de supervision"><Icon name="eye" /></Button>
                            <button v-if="can('cash_registers.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-primary-600 dark:border-gray-800" title="Renommer" @click="startEdit(register)"><Icon name="edit" /></button>
                            <button
                                v-if="register.active ? can('cash_registers.deactivate') : can('cash_registers.activate')"
                                type="button"
                                :class="['flex h-8 w-8 items-center justify-center rounded border', register.active ? 'border-amber-200 text-amber-600 hover:bg-amber-50 dark:border-amber-900' : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50 dark:border-emerald-900']"
                                :title="register.active ? 'Désactiver' : 'Activer'"
                                @click="toggleActive(register)"
                            ><Icon :name="register.active ? 'toggle-off' : 'toggle-on'" /></button>
                            <button v-if="can('cash_registers.archive')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-red-200 text-red-500 hover:bg-red-50 dark:border-red-900" title="Archiver" @click="openArchive(register)"><Icon name="archive" /></button>
                        </template>
                        <button v-else-if="can('cash_registers.restore')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-primary-600 dark:border-gray-800" title="Restaurer" @click="restore(register)"><Icon name="undo" /></button>
                    </div>
                </div>
                <div v-if="!registers.length" class="px-5 py-12 text-center"><Icon class="text-2xl text-slate-300" name="wallet" /><p class="mt-2 text-sm text-slate-400">Aucune caisse ne correspond à ces filtres.</p></div>
            </div>
        </section>

        <div v-if="archiving" class="fixed inset-0 z-[1000] flex items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true">
            <form class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-950" @submit.prevent="submitArchive">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-red-50 text-red-600 dark:bg-red-950/30"><Icon class="text-lg" name="archive" /></span>
                    <div><h2 class="text-base font-bold text-slate-700 dark:text-white">Archiver « {{ archiving.name }} » ?</h2><p class="mt-1 text-sm text-slate-500">Elle ne sera plus proposée à l’ouverture de caisse. Son historique de sessions reste consultable.</p></div>
                </div>
                <label class="mb-1.5 mt-5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label>
                <textarea v-model="archiveForm.reason" rows="3" class="w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Pourquoi cette caisse doit-elle être archivée ?"></textarea>
                <p v-if="archiveForm.errors.reason" class="mt-1 text-xs text-red-600">{{ archiveForm.errors.reason }}</p>
                <p v-if="archiveForm.errors.register" class="mt-1 text-xs text-red-600">{{ archiveForm.errors.register }}</p>
                <div class="mt-5 flex justify-end gap-3">
                    <Button size="rg" variant="white-outline" type="button" @click="archiving = null">Annuler</Button>
                    <Button size="rg" variant="danger" :disabled="archiveForm.processing"><Icon class="text-lg" name="archive" /><span class="ms-2">Archiver</span></Button>
                </div>
            </form>
        </div>
    </div>
</template>
