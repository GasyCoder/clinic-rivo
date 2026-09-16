<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { Plus } from 'lucide-vue-next';

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

const inputClass = 'h-10 w-full rounded-lg border border-border bg-white px-3 text-sm ';
</script>

<template>
    <div>
        <ul v-if="visible.length" class="divide-y divide-border rounded-lg border border-border dark:divide-gray-900">
            <li v-for="category in visible" :key="category.uuid" :class="['px-3 py-2.5', category.archived && 'bg-muted/70 /40']">
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
                        :class="['flex min-w-0 items-center gap-2 text-start text-sm', selectedName === category.name ? 'font-bold text-primary' : 'text-foreground ', category.archived && 'cursor-default']"
                        :disabled="category.archived"
                        @click="emit('select', selectedName === category.name ? '' : category.name)"
                    >
                        <span class="truncate font-semibold">{{ category.name }}</span>
                        <span class="font-mono text-xs text-muted-foreground">{{ category.code }}</span>
                        <span class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{{ category.medicines_count }}</span>
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
                    <p v-if="category.archived && category.delete_reason" class="basis-full text-xs text-muted-foreground">Motif : {{ category.delete_reason }}</p>
                </div>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">Aucune famille pour l’instant.</p>

        <button v-if="archivedCount" type="button" class="mt-2 text-sm font-semibold text-primary hover:underline" @click="showArchived = !showArchived">
            {{ showArchived ? 'Masquer' : 'Afficher' }} {{ archivedCount }} famille{{ archivedCount > 1 ? 's' : '' }} archivée{{ archivedCount > 1 ? 's' : '' }}
        </button>

        <form v-if="can.create" class="mt-4 grid gap-2 border-t border-border pt-4 sm:grid-cols-[120px_minmax(0,1fr)_auto]" @submit.prevent="submitCreate">
            <input v-model="createForm.code" maxlength="60" :class="[inputClass, 'uppercase']" placeholder="Code" required>
            <input v-model="createForm.name" maxlength="255" :class="inputClass" placeholder="Nom de la nouvelle famille" required>
            <Button size="rg" type="submit" :disabled="createForm.processing"><Plus class="h-4 w-4" />Créer</Button>
            <p v-if="createForm.errors.code || createForm.errors.name || createForm.errors.site" class="text-xs text-red-600 sm:col-span-3">{{ createForm.errors.code || createForm.errors.name || createForm.errors.site }}</p>
        </form>

        <div v-if="archiving" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="archiving = null">
            <section class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="archive-family-title">
                <h2 id="archive-family-title" class="font-heading text-lg font-bold text-foreground">Archiver la famille « {{ archiving.name }} »</h2>
                <p class="mt-1 text-sm text-muted-foreground">Elle ne sera plus proposée pour classer un médicament. Elle pourra être restaurée.</p>
                <form class="mt-4 space-y-4" @submit.prevent="confirmArchive">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></span>
                        <textarea v-model="archiveForm.reason" required rows="3" class="block w-full rounded-lg border border-border bg-white px-3 py-2 text-sm" />
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
