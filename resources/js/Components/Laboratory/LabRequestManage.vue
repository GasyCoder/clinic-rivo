<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Check, FilePen, ListPlus, Search, Settings2, Trash2, X } from 'lucide-vue-next';
import LabTrashDialog from '@/Components/Laboratory/LabTrashDialog.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { LAB_SITE_ONLY_REASON, labUrl } from '@/utilities/labUrl';

/**
 * ADR-220 — gérer une demande depuis sa paillasse : ajouter ou retirer une
 * analyse, reprendre les renseignements, archiver, mettre à la corbeille.
 * Chaque geste est rejugé par le serveur ; le menu dit, avant le clic, pourquoi
 * une entrée est indisponible — jamais une entrée grisée sans raison.
 */
const props = defineProps({
    labRequest: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    manage: { type: Object, default: () => ({}) },
    addable: { type: Array, default: () => [] },
});

const base = computed(() => labUrl(`/laboratory/requests/${props.labRequest.uuid}`));
const locked = computed(() => props.manage.edit_locked && !props.manage.edit);

const menu = computed(() => {
    const entries = [];
    if (props.manage.edit || locked.value) {
        const reason = locked.value ? LAB_SITE_ONLY_REASON : props.manage.archived ? 'Désarchivez d’abord la demande pour la corriger.' : null;
        entries.push(
            { key: 'notes', label: 'Modifier les renseignements', description: reason ?? 'Le renseignement clinique, repris sur la feuille et le compte rendu.', disabled: Boolean(reason) },
            { key: 'add', label: 'Ajouter une analyse', description: reason ?? (props.addable.length ? 'Du catalogue du laboratoire, facturée comme une demande du médecin.' : 'Toutes les analyses du catalogue sont déjà dans la demande.'), disabled: Boolean(reason) || !props.addable.length },
        );
    }
    if (props.manage.archive) {
        entries.push(props.manage.archived
            ? { key: 'unarchive', label: 'Désarchiver', description: 'La demande revient dans la file.', separatorBefore: entries.length > 0 }
            : { key: 'archive', label: 'Archiver', description: props.manage.archivable ? 'La ranger : elle quitte la file, rien n’est effacé.' : 'Seule une demande dont toutes les analyses sont envoyées au médecin se range.', disabled: !props.manage.archivable, separatorBefore: entries.length > 0 });
    }
    if (props.manage.trash) {
        entries.push({ key: 'trash', label: 'Mettre à la corbeille', description: props.manage.trashable ? 'Saisie à tort : restaurable depuis la Corbeille.' : 'Des résultats ont été envoyés au médecin : la demande ne part pas à la corbeille.', disabled: !props.manage.trashable, destructive: true, separatorBefore: true });
    }

    return entries;
});

const onSelect = (key) => {
    if (key === 'notes') { notesForm.defaults({ notes: props.labRequest.notes ?? '' }); notesForm.reset(); notesOpen.value = true; }
    if (key === 'add') { chosen.value = []; search.value = ''; addForm.clearErrors(); addOpen.value = true; }
    if (key === 'archive' || key === 'unarchive') router.post(`${base.value}/${key}`, {}, { preserveScroll: true });
    if (key === 'trash') { trashError.value = ''; trashOpen.value = true; }
};

// Renseignements cliniques
const notesOpen = ref(false);
const notesForm = useForm({ notes: props.labRequest.notes ?? '' });
const saveNotes = () => notesForm.put(`${base.value}/renseignements`, { preserveScroll: true, onSuccess: () => { notesOpen.value = false; } });

// Ajouter une analyse
const addOpen = ref(false);
const search = ref('');
const chosen = ref([]);
const addForm = useForm({ catalog_item_uuids: [] });
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
const filtered = computed(() => {
    const words = normalize(search.value).split(/\s+/).filter(Boolean);

    return props.addable.filter((item) => words.every((word) => normalize(`${item.code} ${item.name}`).includes(word))).slice(0, 80);
});
const toggleChosen = (uuid) => { chosen.value = chosen.value.includes(uuid) ? chosen.value.filter((value) => value !== uuid) : [...chosen.value, uuid]; };
const addItems = () => {
    addForm.catalog_item_uuids = chosen.value;
    addForm.post(`${base.value}/items`, { preserveScroll: true, onSuccess: () => { addOpen.value = false; } });
};

// Retirer une analyse
const removing = ref(null);
const removeForm = useForm({ reason: '' });
watch(removing, () => { removeForm.reset(); removeForm.clearErrors(); });
const openRemove = (item) => { removing.value = item; };
const removeItem = () => removeForm.delete(labUrl(`/laboratory/items/${removing.value.uuid}`), { preserveScroll: true, onSuccess: () => { removing.value = null; } });
const canRemove = (item) => Boolean(props.manage.edit && !props.manage.archived && item.removable && props.items.length > 1);

// Corbeille
const trashOpen = ref(false);
const trashing = ref(false);
const trashError = ref('');
const confirmTrash = (reason) => router.delete(base.value, {
    data: { reason },
    onStart: () => { trashing.value = true; },
    onError: (errors) => { trashError.value = errors.reason ?? errors.request ?? Object.values(errors)[0] ?? ''; },
    onFinish: () => { trashing.value = false; },
});

defineExpose({ openRemove, canRemove });
</script>

<template>
    <DropdownMenu v-if="menu.length" :items="menu" label="Gérer la demande" @select="onSelect">
        <template #trigger>
            <Button type="button" size="sm" variant="outline"><Settings2 class="h-4 w-4" /> Gérer</Button>
        </template>
    </DropdownMenu>

    <!-- Renseignements -->
    <Dialog v-model:open="notesOpen" title="Renseignements cliniques" description="Repris sur la feuille de paillasse et le compte rendu. L’ancienne valeur reste à l’audit." size="md" :dismissible="!notesForm.processing">
        <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><FilePen class="h-5 w-5" /></span></template>
        <FormField label="Renseignements" :error="notesForm.errors.notes">
            <Textarea v-model="notesForm.notes" rows="4" maxlength="2000" placeholder="Ex. : fièvre depuis 3 jours, sous antibiotique…" @keydown.ctrl.enter.prevent="saveNotes" />
        </FormField>
        <template #footer>
            <Button type="button" variant="outline" :disabled="notesForm.processing" @click="notesOpen = false">Annuler</Button>
            <Button type="button" :disabled="notesForm.processing || !notesForm.isDirty" @click="saveNotes"><Check class="h-4 w-4" /> Enregistrer</Button>
        </template>
    </Dialog>

    <!-- Ajouter une analyse -->
    <Dialog v-model:open="addOpen" title="Ajouter une analyse" description="Du catalogue du laboratoire. Elle rejoint la demande et le compte du patient, comme une demande du médecin." size="lg" :dismissible="!addForm.processing">
        <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><ListPlus class="h-5 w-5" /></span></template>
        <div class="space-y-3">
            <IconInput v-model="search" :icon="Search" class="w-full" placeholder="Nom ou code de l’analyse" aria-label="Chercher une analyse" />
            <ul class="max-h-[45vh] divide-y divide-border overflow-y-auto rounded-lg border border-border" role="listbox" aria-multiselectable="true">
                <li v-for="item in filtered" :key="item.uuid">
                    <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-muted/50">
                        <Checkbox :model-value="chosen.includes(item.uuid)" :aria-label="`Choisir ${item.name}`" @update:model-value="toggleChosen(item.uuid)" />
                        <span class="min-w-0 flex-1 truncate font-medium text-foreground">{{ item.name }}</span>
                        <span v-if="item.code" class="shrink-0 font-mono text-xs text-muted-foreground">{{ item.code }}</span>
                    </label>
                </li>
                <li v-if="!filtered.length" class="px-3 py-6 text-center text-sm text-muted-foreground">Aucune analyse ne correspond.</li>
            </ul>
            <p v-if="addForm.errors.catalog_item_uuids" class="text-sm text-destructive" role="alert">{{ addForm.errors.catalog_item_uuids }}</p>
        </div>
        <template #footer>
            <Button type="button" variant="outline" :disabled="addForm.processing" @click="addOpen = false">Annuler</Button>
            <Button type="button" :disabled="addForm.processing || !chosen.length" @click="addItems"><ListPlus class="h-4 w-4" /> Ajouter{{ chosen.length > 1 ? ` ${chosen.length} analyses` : '' }}</Button>
        </template>
    </Dialog>

    <!-- Retirer une analyse -->
    <Dialog :open="removing !== null" :title="`Retirer « ${removing?.name ?? ''} »`" description="L’analyse quitte la demande et garde sa trace. Ce qu’elle attendait d’être facturé est annulé ; ce qui est sur une facture reste à la Caisse." size="md" :dismissible="!removeForm.processing" @update:open="(open) => { if (!open) removing = null; }">
        <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-destructive/10 text-destructive"><X class="h-5 w-5" /></span></template>
        <FormField label="Motif" required :error="removeForm.errors.reason || removeForm.errors.item">
            <Textarea v-model="removeForm.reason" rows="3" maxlength="1000" placeholder="Ex. : analyse non prescrite, saisie en double…" @keydown.ctrl.enter.prevent="removeItem" />
        </FormField>
        <template #footer>
            <Button type="button" variant="outline" :disabled="removeForm.processing" @click="removing = null">Annuler</Button>
            <Button type="button" variant="destructive" :disabled="removeForm.processing || removeForm.reason.trim().length < 3" @click="removeItem"><Trash2 class="h-4 w-4" /> Retirer l’analyse</Button>
        </template>
    </Dialog>

    <LabTrashDialog v-model:open="trashOpen" :count="1" :processing="trashing" :error="trashError" @confirm="confirmTrash" />

</template>
