<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import {
    Archive, ArchiveRestore, ArrowDown, ArrowLeft, ArrowUp, Combine, LayoutList, Pencil, Plus, Search, TriangleAlert,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { labUrl } from '@/utilities/labUrl';

defineOptions({ layout: AppLayout });

/**
 * ADR-238 — les disciplines du laboratoire (Hématologie, Biochimie…) : elles
 * rangent la feuille de paillasse et les sections du compte rendu, dans cet
 * ordre. Un référentiel, jamais un texte libre. Rien n'est supprimé : une
 * discipline s'archive avec un motif quand plus aucune analyse ne la porte,
 * se fusionne avec une autre (le geste qui répare une faute de frappe), se
 * restaure.
 */
const props = defineProps({
    disciplines: { type: Array, default: () => [] },
    withoutDiscipline: { type: Number, default: 0 },
    showArchived: { type: Boolean, default: false },
    can: { type: Object, default: () => ({}) },
});

const query = ref('');
const normalize = (text) => String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const visible = computed(() => {
    const needle = normalize(query.value.trim());
    return props.disciplines.filter((entry) => !needle || normalize(entry.name).includes(needle));
});
const active = computed(() => props.disciplines.filter((entry) => !entry.archived));

const setArchives = (value) => router.get(labUrl('/laboratory/disciplines'), value ? { archives: 1 } : {}, { preserveScroll: true, preserveState: true, replace: true });

// Ajouter / modifier
const editor = ref({ open: false, entry: null });
const form = useForm({ name: '', display_order: null, is_active: true });
const openEditor = (entry = null) => {
    form.clearErrors();
    form.name = entry?.name ?? '';
    form.display_order = entry?.display_order ?? null;
    form.is_active = entry?.is_active ?? true;
    editor.value = { open: true, entry };
};
const submit = () => {
    const { entry } = editor.value;
    form.transform((data) => ({
        name: data.name,
        display_order: data.display_order === '' ? null : data.display_order,
        ...(entry ? { is_active: data.is_active } : {}),
    }));
    const options = { preserveScroll: true, onSuccess: () => { editor.value.open = false; } };
    if (entry) form.put(labUrl(`/laboratory/disciplines/${entry.uuid}`), options);
    else form.post(labUrl('/laboratory/disciplines'), options);
};

// Réordonner : on échange l'ordre avec la voisine, sans rien d'autre.
const moving = ref(false);
const move = (entry, direction) => {
    const list = active.value;
    const index = list.findIndex((item) => item.uuid === entry.uuid);
    const neighbour = list[index + direction];
    if (!neighbour || moving.value) return;
    moving.value = true;
    const mine = entry.display_order === neighbour.display_order ? neighbour.display_order + direction : neighbour.display_order;
    router.put(labUrl(`/laboratory/disciplines/${entry.uuid}`), { display_order: mine }, {
        preserveScroll: true,
        onSuccess: () => router.put(labUrl(`/laboratory/disciplines/${neighbour.uuid}`), { display_order: entry.display_order }, {
            preserveScroll: true,
            onFinish: () => { moving.value = false; },
        }),
        onError: () => { moving.value = false; },
    });
};

// Archiver
const archiving = ref({ open: false, entry: null });
const archiveForm = useForm({ reason: '' });
const openArchive = (entry) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiving.value = { open: true, entry };
};
const archive = () => archiveForm.delete(labUrl(`/laboratory/disciplines/${archiving.value.entry.uuid}`), {
    preserveScroll: true,
    onSuccess: () => { archiving.value.open = false; },
});
const restore = (entry) => router.post(labUrl(`/laboratory/disciplines/${entry.uuid}/restore`), {}, { preserveScroll: true });

// Fusionner
const merging = ref({ open: false, entry: null });
const mergeForm = useForm({ target_uuid: '' });
const mergeTargets = computed(() => [
    { value: '', label: 'Choisir la discipline qui la remplace' },
    ...active.value.filter((entry) => entry.uuid !== merging.value.entry?.uuid).map((entry) => ({ value: entry.uuid, label: entry.name })),
]);
const openMerge = (entry) => {
    mergeForm.reset();
    mergeForm.clearErrors();
    merging.value = { open: true, entry };
};
const merge = () => mergeForm.post(labUrl(`/laboratory/disciplines/${merging.value.entry.uuid}/merge`), {
    preserveScroll: true,
    onSuccess: () => { merging.value.open = false; },
});
const mergeTargetName = computed(() => active.value.find((entry) => entry.uuid === mergeForm.target_uuid)?.name ?? '');
</script>

<template>
    <Head title="Disciplines du laboratoire" />

    <div class="w-full space-y-5">
        <Button :as="Link" :href="labUrl('/laboratory')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" /> File du laboratoire</Button>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><LayoutList class="h-6 w-6" /></span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Disciplines</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ active.length }} discipline(s) — elles rangent la feuille de paillasse et les sections du compte rendu, dans cet ordre.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-muted-foreground">
                    <Switch :model-value="showArchived" @update:model-value="setArchives" /> Afficher les archivées
                </label>
                <Button v-if="can.create" type="button" size="sm" @click="openEditor()"><Plus class="h-4 w-4" /> Nouvelle discipline</Button>
            </div>
        </header>

        <Card v-if="withoutDiscipline > 0" class="flex items-start gap-3 border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/30">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-300" />
            <p class="text-sm text-foreground">
                {{ withoutDiscipline }} analyse(s) principale(s) n’ont pas de discipline : elles s’impriment sous « Sans discipline ».
                Choisissez-la dans leur fiche, au catalogue des analyses (étape Résultat).
            </p>
        </Card>

        <div class="w-full sm:w-72"><IconInput v-model="query" :icon="Search" class="w-full" placeholder="Rechercher une discipline" aria-label="Rechercher une discipline" /></div>

        <Card class="overflow-hidden">
            <ul class="divide-y divide-border">
                <li v-for="entry in visible" :key="entry.uuid" :class="cn('flex flex-wrap items-center gap-3 px-4 py-3', entry.archived && 'opacity-60')">
                    <div v-if="can.update && !entry.archived && !query" class="flex flex-col">
                        <Button type="button" size="xs" variant="ghost" icon :disabled="moving || active[0]?.uuid === entry.uuid" :aria-label="`Monter ${entry.name}`" @click="move(entry, -1)"><ArrowUp class="h-3.5 w-3.5" /></Button>
                        <Button type="button" size="xs" variant="ghost" icon :disabled="moving || active.at(-1)?.uuid === entry.uuid" :aria-label="`Descendre ${entry.name}`" @click="move(entry, 1)"><ArrowDown class="h-3.5 w-3.5" /></Button>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                            {{ entry.name }}
                            <Badge v-if="!entry.is_active" tone="warning" title="N’est plus proposée dans la fiche d’une analyse">Non proposée</Badge>
                            <Badge v-if="entry.archived" tone="neutral" :title="entry.delete_reason">Archivée</Badge>
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ entry.roots_count }} analyse(s) principale(s) · {{ entry.analyses_count }} ligne(s) au catalogue
                            <template v-if="entry.archived && entry.delete_reason"> · {{ entry.delete_reason }}</template>
                        </p>
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-if="entry.archived">
                            <Button v-if="can.restore" type="button" size="xs" variant="outline" @click="restore(entry)"><ArchiveRestore class="h-3.5 w-3.5" /> Restaurer</Button>
                        </template>
                        <template v-else>
                            <Button v-if="can.update" type="button" size="xs" variant="ghost" icon :aria-label="`Modifier ${entry.name}`" @click="openEditor(entry)"><Pencil class="h-3.5 w-3.5" /></Button>
                            <Button
                                v-if="can.merge"
                                type="button"
                                size="xs"
                                variant="ghost"
                                icon
                                :disabled="active.length < 2"
                                :title="`Fusionner « ${entry.name} » avec une autre discipline`"
                                :aria-label="`Fusionner ${entry.name}`"
                                @click="openMerge(entry)"
                            ><Combine class="h-3.5 w-3.5" /></Button>
                            <Button
                                v-if="can.archive"
                                type="button"
                                size="xs"
                                variant="ghost"
                                icon
                                :disabled="entry.analyses_count > 0"
                                :title="entry.analyses_count > 0 ? 'Des analyses la portent encore : fusionnez-la avec une autre.' : `Archiver ${entry.name}`"
                                :aria-label="`Archiver ${entry.name}`"
                                @click="openArchive(entry)"
                            ><Archive class="h-3.5 w-3.5" /></Button>
                        </template>
                    </div>
                </li>
                <li v-if="!visible.length" class="px-4 py-10 text-center text-sm text-muted-foreground">
                    {{ query ? 'Rien ne correspond à la recherche.' : 'Aucune discipline : créez-en une, ou choisissez-la dans la fiche d’une analyse.' }}
                </li>
            </ul>
        </Card>
    </div>

    <Dialog v-model:open="editor.open" :title="editor.entry ? `Modifier « ${editor.entry.name} »` : 'Nouvelle discipline'" :dismissible="false">
        <form id="discipline-form" class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
                <FormField label="Nom" :error="form.errors.name" required>
                    <Input v-model="form.name" autofocus maxlength="120" placeholder="HÉMATOLOGIE" />
                </FormField>
                <FormField label="Ordre" hint="(facultatif)" :error="form.errors.display_order">
                    <Input v-model.number="form.display_order" type="number" min="0" />
                </FormField>
            </div>
            <p v-if="editor.entry" class="text-xs text-muted-foreground">Renommer change aussi le nom imprimé sur les comptes rendus à venir ; ceux déjà remis ne bougent pas.</p>
            <label v-if="editor.entry" class="flex items-center gap-2 text-sm text-foreground">
                <Switch v-model="form.is_active" /> Proposée dans la fiche d’une analyse
            </label>
        </form>
        <template #footer>
            <Button type="button" variant="outline" @click="editor.open = false">Annuler</Button>
            <Button type="submit" form="discipline-form" :disabled="form.processing || !form.name.trim()">Enregistrer</Button>
        </template>
    </Dialog>

    <Dialog
        v-model:open="merging.open"
        :title="`Fusionner « ${merging.entry?.name ?? ''} »`"
        description="Toutes ses analyses passent dans la discipline choisie, puis elle est archivée. Les comptes rendus déjà remis ne bougent pas."
        :dismissible="false"
    >
        <FormField label="Remplacée par" :error="mergeForm.errors.target_uuid" required>
            <Select v-model="mergeForm.target_uuid" :options="mergeTargets" />
        </FormField>
        <p v-if="mergeTargetName" class="text-sm text-muted-foreground">
            {{ merging.entry?.analyses_count }} ligne(s) du catalogue passeront dans « {{ mergeTargetName }} ».
        </p>
        <template #footer>
            <Button type="button" variant="outline" @click="merging.open = false">Annuler</Button>
            <Button type="button" :disabled="mergeForm.processing || !mergeForm.target_uuid" @click="merge"><Combine class="h-4 w-4" /> Fusionner</Button>
        </template>
    </Dialog>

    <Dialog
        v-model:open="archiving.open"
        :title="`Archiver « ${archiving.entry?.name ?? ''} »`"
        description="Elle n’est plus proposée et ne range plus rien. Elle se restaure à tout moment."
        :dismissible="false"
    >
        <FormField label="Motif" :error="archiveForm.errors.reason" required>
            <Textarea v-model="archiveForm.reason" rows="2" />
        </FormField>
        <template #footer>
            <Button type="button" variant="outline" @click="archiving.open = false">Annuler</Button>
            <Button type="button" variant="danger" :disabled="archiveForm.processing || archiveForm.reason.trim().length < 3" @click="archive"><Archive class="h-4 w-4" /> Archiver</Button>
        </template>
    </Dialog>
</template>
