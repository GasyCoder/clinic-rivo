<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { cn } from '@/lib/cn';

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

const closeArchive = () => {
    if (archiveForm.processing) return;

    archiving.value = null;
    archiveForm.clearErrors();
};
</script>

<template>
    <div>
        <ul v-if="visible.length" class="divide-y divide-border rounded-lg border border-border">
            <li v-for="category in visible" :key="category.uuid" :class="cn('px-3 py-2.5', category.archived && 'bg-muted/40')">
                <form v-if="renaming === category.uuid" class="flex flex-col gap-2 sm:flex-row sm:items-center" @submit.prevent="saveRename(category)">
                    <Input v-model="renameForm.name" maxlength="255" required aria-label="Nouveau nom de la famille" />
                    <div class="flex shrink-0 gap-1.5">
                        <Button type="submit" size="sm" variant="primary" :disabled="renameForm.processing">Enregistrer</Button>
                        <Button type="button" size="sm" variant="outline" @click="renaming = null">Annuler</Button>
                    </div>
                    <p v-if="renameForm.errors.name || renameForm.errors.site" class="text-xs text-destructive sm:basis-full">
                        {{ renameForm.errors.name || renameForm.errors.site }}
                    </p>
                </form>

                <div v-else class="flex flex-wrap items-center justify-between gap-2">
                    <button
                        type="button"
                        :class="cn(
                            'flex min-w-0 items-center gap-2 text-start text-sm',
                            selectedName === category.name ? 'font-bold text-primary' : 'text-foreground',
                            category.archived && 'cursor-default',
                        )"
                        :disabled="category.archived"
                        @click="emit('select', selectedName === category.name ? '' : category.name)"
                    >
                        <span class="truncate font-semibold">{{ category.name }}</span>
                        <span class="font-mono text-xs text-muted-foreground">{{ category.code }}</span>
                        <span class="rounded-full bg-muted px-1.5 text-xs tabular-nums text-muted-foreground">{{ category.medicines_count }}</span>
                        <Badge v-if="category.archived" tone="neutral">Archivée</Badge>
                    </button>

                    <div class="flex shrink-0 gap-1.5">
                        <template v-if="! category.archived">
                            <Button v-if="can.update" type="button" size="sm" variant="outline" @click="startRename(category)">Renommer</Button>
                            <!-- Une famille qui classe encore des médicaments
                                 actifs ne s'archive pas : le motif du refus se
                                 lit avant le clic, pas après l'envoi. -->
                            <Button
                                v-if="can.delete"
                                type="button"
                                size="sm"
                                variant="danger-outline"
                                :disabled="category.active_medicines_count > 0"
                                :title="category.active_medicines_count > 0 ? `${category.active_medicines_count} médicament(s) actif(s) dans cette famille` : 'Archiver la famille'"
                                @click="archiving = category"
                            >Archiver</Button>
                        </template>
                        <Button v-else-if="can.restore" type="button" size="sm" variant="outline" @click="restore(category)">Restaurer</Button>
                    </div>

                    <p v-if="category.archived && category.delete_reason" class="basis-full text-xs text-muted-foreground">Motif : {{ category.delete_reason }}</p>
                </div>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">Aucune famille pour l’instant.</p>

        <Button v-if="archivedCount" type="button" size="sm" variant="link" class="mt-2 px-0" @click="showArchived = ! showArchived">
            {{ showArchived ? 'Masquer' : 'Afficher' }} {{ archivedCount }} famille{{ archivedCount > 1 ? 's' : '' }} archivée{{ archivedCount > 1 ? 's' : '' }}
        </Button>

        <form v-if="can.create" class="mt-4 grid gap-2 border-t border-border pt-4 sm:grid-cols-[120px_minmax(0,1fr)_auto]" @submit.prevent="submitCreate">
            <Input v-model="createForm.code" maxlength="60" class="uppercase" placeholder="Code" required aria-label="Code de la nouvelle famille" />
            <Input v-model="createForm.name" maxlength="255" placeholder="Nom de la nouvelle famille" required aria-label="Nom de la nouvelle famille" />
            <Button type="submit" variant="primary" :disabled="createForm.processing"><Plus class="h-4 w-4" />Créer</Button>
            <p v-if="createForm.errors.code || createForm.errors.name || createForm.errors.site" class="text-xs text-destructive sm:col-span-3">
                {{ createForm.errors.code || createForm.errors.name || createForm.errors.site }}
            </p>
        </form>

        <!-- La fenêtre passe par la primitive partagée (ADR-099) : elle
             portait son propre voile et sa propre boîte, sans piège de focus
             ni fermeture par Échap. -->
        <Dialog
            :open="archiving !== null"
            :title="`Archiver la famille « ${archiving?.name ?? ''} »`"
            description="Elle ne sera plus proposée pour classer un médicament. Elle pourra être restaurée."
            :dismissible="! archiveForm.processing"
            @update:open="closeArchive"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/35 dark:text-amber-300">
                    <TriangleAlert class="h-5 w-5" />
                </span>
            </template>

            <FormField
                label="Motif"
                required
                :error="archiveForm.errors.reason || archiveForm.errors.category || archiveForm.errors.site"
            >
                <Textarea v-model="archiveForm.reason" rows="3" required placeholder="Ex. remplacée par une autre famille" />
            </FormField>

            <template #footer>
                <Button type="button" variant="outline" :disabled="archiveForm.processing" @click="closeArchive">Retour</Button>
                <Button type="button" variant="destructive" :disabled="archiveForm.processing || ! archiveForm.reason.trim()" @click="confirmArchive">
                    {{ archiveForm.processing ? 'Archivage…' : 'Archiver' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
