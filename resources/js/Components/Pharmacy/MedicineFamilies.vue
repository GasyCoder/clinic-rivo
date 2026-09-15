<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

/**
 * ADR-098 — medicine families: create, rename, archive with a reason, restore.
 * Shared by the clinic and the portal. A family still holding active medicines
 * cannot be archived; the server says so too.
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) }, // { create, update, delete, restore }
    // POST baseUrl; PUT/DELETE `${baseUrl}/${uuid}`; POST `${baseUrl}/${uuid}/restore`.
    baseUrl: { type: String, required: true },
    // When set, clicking a family name filters a list on the page.
    selectedName: { type: String, default: null },
});

const emit = defineEmits(['select']);

const showArchived = ref(false);
const visible = computed(() => props.categories.filter((category) => showArchived.value || !category.archived));
const archivedCount = computed(() => props.categories.filter((category) => category.archived).length);

const renaming = ref(null);
const renameForm = useForm({ name: '', description: '' });
const startRename = (category) => {
    renaming.value = category.uuid;
    renameForm.name = category.name;
    renameForm.description = category.description ?? '';
    renameForm.clearErrors();
};
const saveRename = (category) => renameForm.put(`${props.baseUrl}/${category.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { renaming.value = null; },
});

const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const confirmArchive = () => archiveForm.delete(`${props.baseUrl}/${archiving.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; archiveForm.reset(); },
});
const restore = (category) => router.post(`${props.baseUrl}/${category.uuid}/restore`, {}, { preserveScroll: true });

const createForm = useForm({ code: '', name: '', description: '' });
const submitCreate = () => createForm.post(props.baseUrl, { preserveScroll: true, onSuccess: () => createForm.reset() });

const inputClass = 'h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <div>
        <ul v-if="visible.length" class="divide-y divide-gray-100 rounded-lg border border-gray-100 dark:divide-gray-900 dark:border-gray-900">
            <li v-for="category in visible" :key="category.uuid" :class="['px-3 py-2.5', category.archived && 'bg-gray-50/70 dark:bg-gray-1000/40']">
                <form v-if="renaming === category.uuid" class="flex flex-col gap-2 sm:flex-row sm:items-center" @submit.prevent="saveRename(category)">
                    <input v-model="renameForm.name" :class="inputClass" maxlength="255" required aria-label="Nouveau nom de la famille">
                    <div class="flex shrink-0 gap-1.5">
                        <Button type="submit" size="sm" :disabled="renameForm.processing">Enregistrer</Button>
                        <Button type="button" size="sm" variant="white-outline" @click="renaming = null">Annuler</Button>
                    </div>
                    <p v-if="renameForm.errors.name || renameForm.errors.site" class="text-xs text-red-600 sm:basis-full">{{ renameForm.errors.name || renameForm.errors.site }}</p>
                </form>
                <div v-else class="flex flex-wrap items-center justify-between gap-2">
                    <button
                        type="button"
                        :class="['flex min-w-0 items-center gap-2 text-start text-sm', selectedName === category.name ? 'font-bold text-primary-700 dark:text-primary-300' : 'text-slate-700 dark:text-slate-200', category.archived && 'cursor-default']"
                        :disabled="category.archived"
                        @click="emit('select', selectedName === category.name ? '' : category.name)"
                    >
                        <span class="truncate font-semibold">{{ category.name }}</span>
                        <span class="font-mono text-xs text-slate-400">{{ category.code }}</span>
                        <span class="rounded-full bg-gray-100 px-1.5 text-xs text-slate-500 dark:bg-gray-900">{{ category.medicines_count }}</span>
                        <Badge v-if="category.archived" tone="neutral">Archivée</Badge>
                    </button>
                    <div class="flex shrink-0 gap-1.5">
                        <template v-if="!category.archived">
                            <Button v-if="can.update" type="button" size="sm" variant="white-outline" @click="startRename(category)">Renommer</Button>
                            <Button
                                v-if="can.delete"
                                type="button"
                                size="sm"
                                variant="white-outline"
                                class="text-red-600"
                                :disabled="category.active_medicines_count > 0"
                                :title="category.active_medicines_count > 0 ? `${category.active_medicines_count} médicament(s) actif(s) dans cette famille` : 'Archiver la famille'"
                                @click="archiving = category"
                            >Archiver</Button>
                        </template>
                        <Button v-else-if="can.restore" type="button" size="sm" variant="white-outline" @click="restore(category)">Restaurer</Button>
                    </div>
                    <p v-if="category.archived && category.delete_reason" class="basis-full text-xs text-slate-400">Motif : {{ category.delete_reason }}</p>
                </div>
            </li>
        </ul>
        <p v-else class="text-sm text-slate-400">Aucune famille pour l’instant.</p>

        <button v-if="archivedCount" type="button" class="mt-2 text-sm font-semibold text-primary-600 hover:underline" @click="showArchived = !showArchived">
            {{ showArchived ? 'Masquer' : 'Afficher' }} {{ archivedCount }} famille{{ archivedCount > 1 ? 's' : '' }} archivée{{ archivedCount > 1 ? 's' : '' }}
        </button>

        <form v-if="can.create" class="mt-4 grid gap-2 border-t border-gray-100 pt-4 dark:border-gray-900 sm:grid-cols-[120px_minmax(0,1fr)_auto]" @submit.prevent="submitCreate">
            <input v-model="createForm.code" maxlength="60" :class="[inputClass, 'uppercase']" placeholder="Code" required>
            <input v-model="createForm.name" maxlength="255" :class="inputClass" placeholder="Nom de la nouvelle famille" required>
            <Button size="rg" type="submit" :disabled="createForm.processing"><Icon name="plus" /><span class="ms-1.5">Créer</span></Button>
            <p v-if="createForm.errors.code || createForm.errors.name || createForm.errors.site" class="text-xs text-red-600 sm:col-span-3">{{ createForm.errors.code || createForm.errors.name || createForm.errors.site }}</p>
        </form>

        <div v-if="archiving" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="archiving = null">
            <section class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="archive-family-title">
                <h2 id="archive-family-title" class="font-heading text-lg font-bold text-slate-800 dark:text-white">Archiver la famille « {{ archiving.name }} »</h2>
                <p class="mt-1 text-sm text-slate-500">Elle ne sera plus proposée pour classer un médicament. Elle pourra être restaurée.</p>
                <form class="mt-4 space-y-4" @submit.prevent="confirmArchive">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></span>
                        <textarea v-model="archiveForm.reason" required rows="3" class="block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" />
                        <span v-if="archiveForm.errors.reason || archiveForm.errors.category || archiveForm.errors.site" class="mt-1 block text-xs text-red-600">{{ archiveForm.errors.reason || archiveForm.errors.category || archiveForm.errors.site }}</span>
                    </label>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="archiving = null">Retour</Button>
                        <Button type="submit" size="rg" variant="danger" :disabled="archiveForm.processing">Archiver</Button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
