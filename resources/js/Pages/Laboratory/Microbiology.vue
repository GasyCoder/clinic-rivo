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
import Switch from '@/Components/Shadcn/Switch.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import {
    Archive, ArchiveRestore, ArrowLeft, Bug, ChevronDown, Download, FolderTree, Microscope, Pencil, Pill, Plus, Search,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

/**
 * ADR-213 — le référentiel de microbiologie du site : une famille regroupe ses
 * germes et les antibiotiques testés sur eux. Rien n'est supprimé : une entrée
 * s'archive avec un motif et se restaure.
 */
const props = defineProps({
    families: { type: Array, default: () => [] },
    showArchived: { type: Boolean, default: false },
    isEmpty: { type: Boolean, default: false },
    can: { type: Object, default: () => ({}) },
});

const KIND_LABELS = { family: 'famille', bacterium: 'germe', antibiotic: 'antibiotique' };

const query = ref('');
const normalize = (text) => String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const matches = (text) => normalize(text).includes(normalize(query.value.trim()));
const visibleFamilies = computed(() => {
    if (!query.value.trim()) return props.families;
    return props.families
        .map((family) => (matches(family.name)
            ? family
            : { ...family, bacteria: family.bacteria.filter((entry) => matches(entry.name)), antibiotics: family.antibiotics.filter((entry) => matches(entry.name)) }))
        .filter((family) => matches(family.name) || family.bacteria.length || family.antibiotics.length);
});
const totals = computed(() => ({
    families: props.families.filter((family) => !family.archived).length,
    bacteria: props.families.reduce((sum, family) => sum + family.bacteria.filter((entry) => !entry.archived).length, 0),
    antibiotics: props.families.reduce((sum, family) => sum + family.antibiotics.filter((entry) => !entry.archived).length, 0),
}));

const collapsed = ref(new Set());
const toggle = (uuid) => {
    const next = new Set(collapsed.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    collapsed.value = next;
};

const setArchives = (value) => router.get('/laboratory/microbiologie', value ? { archives: 1 } : {}, { preserveScroll: true, preserveState: true, replace: true });

// Ajouter / modifier
const editor = ref({ open: false, kind: 'family', entry: null, family: null });
const form = useForm({ name: '', family_uuid: null, comment: '', is_active: true });
const openEditor = (kind, family = null, entry = null) => {
    form.clearErrors();
    form.name = entry?.name ?? '';
    form.comment = entry?.comment ?? '';
    form.is_active = entry?.is_active ?? true;
    form.family_uuid = family?.uuid ?? null;
    editor.value = { open: true, kind, entry, family };
};
const submit = () => {
    const { kind, entry } = editor.value;
    form.transform((data) => ({
        name: data.name,
        ...(kind !== 'family' ? { family_uuid: data.family_uuid } : {}),
        ...(kind === 'antibiotic' ? { comment: data.comment } : {}),
        ...(entry ? { is_active: data.is_active } : {}),
    }));
    const options = { preserveScroll: true, onSuccess: () => { editor.value.open = false; } };
    if (entry) form.put(`/laboratory/microbiologie/${kind}/${entry.uuid}`, options);
    else form.post(`/laboratory/microbiologie/${kind}`, options);
};
const editorTitle = computed(() => {
    const { kind, entry, family } = editor.value;
    const label = KIND_LABELS[kind];
    if (entry) return `Modifier ${kind === 'family' ? 'la' : 'le'} ${label}`;
    return kind === 'family' ? 'Nouvelle famille' : `Nouvel ${label === 'germe' ? 'germe' : 'antibiotique'}${family ? ` — ${family.name}` : ''}`;
});

// Archiver
const archiving = ref({ open: false, kind: null, entry: null });
const archiveForm = useForm({ reason: '' });
const openArchive = (kind, entry) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiving.value = { open: true, kind, entry };
};
const archive = () => archiveForm.delete(`/laboratory/microbiologie/${archiving.value.kind}/${archiving.value.entry.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { archiving.value.open = false; },
});
const restore = (kind, entry) => router.post(`/laboratory/microbiologie/${kind}/${entry.uuid}/restore`, {}, { preserveScroll: true });

const importing = ref(false);
const importStarter = () => {
    importing.value = true;
    router.post('/laboratory/microbiologie/referentiel-de-depart', {}, { preserveScroll: true, onFinish: () => { importing.value = false; } });
};

const sections = [
    { key: 'bacteria', kind: 'bacterium', label: 'Germes', icon: Bug, empty: 'Aucun germe.' },
    { key: 'antibiotics', kind: 'antibiotic', label: 'Antibiotiques testés', icon: Pill, empty: 'Aucun antibiotique.' },
];
</script>

<template>
    <Head title="Germes & antibiotiques" />

    <div class="mx-auto w-full max-w-screen-xl space-y-5">
        <Button :as="Link" href="/laboratory" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" /> File du laboratoire</Button>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><Microscope class="h-6 w-6" /></span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Germes & antibiotiques</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ totals.families }} famille(s) · {{ totals.bacteria }} germe(s) · {{ totals.antibiotics }} antibiotique(s) — ce qu’une culture propose et ce qu’un antibiogramme teste.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-muted-foreground">
                    <Switch :model-value="showArchived" @update:model-value="setArchives" /> Afficher les archivés
                </label>
                <Button v-if="can.create" type="button" size="sm" @click="openEditor('family')"><Plus class="h-4 w-4" /> Nouvelle famille</Button>
            </div>
        </header>

        <Card v-if="isEmpty" class="p-8 text-center">
            <FolderTree class="mx-auto h-9 w-9 text-muted-foreground/60" />
            <p class="mt-3 font-semibold text-foreground">Le référentiel est vide.</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-muted-foreground">
                Importez le référentiel de départ — les familles, germes et antibiotiques du laboratoire utilisé jusqu’ici — puis corrigez-le. Vous pouvez aussi tout saisir à la main.
            </p>
            <Button v-if="can.create" type="button" class="mt-4" :disabled="importing" @click="importStarter"><Download class="h-4 w-4" /> Importer le référentiel de départ</Button>
        </Card>

        <template v-else>
            <IconInput v-model="query" :icon="Search" class="w-full sm:w-80" placeholder="Famille, germe ou antibiotique" aria-label="Rechercher dans le référentiel" />

            <p v-if="!visibleFamilies.length" class="py-10 text-center text-sm text-muted-foreground">Rien ne correspond à la recherche.</p>

            <Card v-for="family in visibleFamilies" :key="family.uuid" :class="cn('overflow-hidden', family.archived && 'opacity-70')">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
                    <button type="button" class="flex min-w-0 items-center gap-2 text-left" :aria-expanded="!collapsed.has(family.uuid)" @click="toggle(family.uuid)">
                        <ChevronDown :class="cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform', collapsed.has(family.uuid) && '-rotate-90')" />
                        <span class="truncate text-base font-bold text-foreground">{{ family.name }}</span>
                        <Badge variant="outline">{{ family.bacteria.length }} germe(s) · {{ family.antibiotics.length }} ATB</Badge>
                        <Badge v-if="!family.is_active" tone="warning">Inactive</Badge>
                        <Badge v-if="family.archived" tone="neutral" :title="family.delete_reason">Archivée</Badge>
                    </button>
                    <div class="flex items-center gap-1">
                        <template v-if="family.archived">
                            <Button v-if="can.restore" type="button" size="xs" variant="outline" @click="restore('family', family)"><ArchiveRestore class="h-3.5 w-3.5" /> Restaurer</Button>
                        </template>
                        <template v-else>
                            <Button v-if="can.update" type="button" size="xs" variant="ghost" icon :aria-label="`Modifier ${family.name}`" @click="openEditor('family', null, family)"><Pencil class="h-3.5 w-3.5" /></Button>
                            <Button v-if="can.archive" type="button" size="xs" variant="ghost" icon :aria-label="`Archiver ${family.name}`" @click="openArchive('family', family)"><Archive class="h-3.5 w-3.5" /></Button>
                        </template>
                    </div>
                </header>

                <div v-show="!collapsed.has(family.uuid)" class="grid gap-0 md:grid-cols-2 md:divide-x md:divide-border">
                    <section v-for="section in sections" :key="section.key" class="p-4">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-muted-foreground"><component :is="section.icon" class="h-3.5 w-3.5" /> {{ section.label }}</h3>
                            <Button v-if="can.create && !family.archived" type="button" size="xs" variant="outline" @click="openEditor(section.kind, family)"><Plus class="h-3.5 w-3.5" /> Ajouter</Button>
                        </div>
                        <ul class="divide-y divide-border rounded-lg border border-border">
                            <li v-for="entry in family[section.key]" :key="entry.uuid" :class="cn('flex items-center justify-between gap-2 px-3 py-1.5', entry.archived && 'opacity-60')">
                                <div class="min-w-0">
                                    <p :class="cn('truncate text-sm text-foreground', section.kind === 'bacterium' && 'italic')">{{ entry.name }}</p>
                                    <p v-if="entry.comment" class="truncate text-[11px] text-muted-foreground" :title="entry.comment">{{ entry.comment }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <Badge v-if="entry.used" variant="outline" title="Déjà utilisé dans un antibiogramme">Utilisé</Badge>
                                    <Badge v-if="!entry.is_active" tone="warning">Inactif</Badge>
                                    <template v-if="entry.archived">
                                        <Badge tone="neutral" :title="entry.delete_reason">Archivé</Badge>
                                        <Button v-if="can.restore" type="button" size="xs" variant="ghost" icon :aria-label="`Restaurer ${entry.name}`" @click="restore(section.kind, entry)"><ArchiveRestore class="h-3.5 w-3.5" /></Button>
                                    </template>
                                    <template v-else>
                                        <Button v-if="can.update" type="button" size="xs" variant="ghost" icon :aria-label="`Modifier ${entry.name}`" @click="openEditor(section.kind, family, entry)"><Pencil class="h-3.5 w-3.5" /></Button>
                                        <Button v-if="can.archive" type="button" size="xs" variant="ghost" icon :aria-label="`Archiver ${entry.name}`" @click="openArchive(section.kind, entry)"><Archive class="h-3.5 w-3.5" /></Button>
                                    </template>
                                </div>
                            </li>
                            <li v-if="!family[section.key].length" class="px-3 py-2 text-xs text-muted-foreground">{{ section.empty }}</li>
                        </ul>
                    </section>
                </div>
            </Card>
        </template>
    </div>

    <Dialog v-model:open="editor.open" :title="editorTitle" :dismissible="false">
        <form id="microbiology-form" class="space-y-4" @submit.prevent="submit">
            <FormField label="Nom" :error="form.errors.name" required>
                <Input v-model="form.name" autofocus maxlength="200" />
            </FormField>
            <p v-if="form.errors.family_uuid" class="text-sm text-destructive">{{ form.errors.family_uuid }}</p>
            <FormField v-if="editor.kind === 'antibiotic'" label="Commentaire" hint="(facultatif)" :error="form.errors.comment">
                <Textarea v-model="form.comment" rows="2" maxlength="500" />
            </FormField>
            <label v-if="editor.entry" class="flex items-center gap-2 text-sm text-foreground">
                <Switch v-model="form.is_active" /> Proposé à la paillasse
            </label>
        </form>
        <template #footer>
            <Button type="button" variant="outline" @click="editor.open = false">Annuler</Button>
            <Button type="submit" form="microbiology-form" :disabled="form.processing || !form.name.trim()">Enregistrer</Button>
        </template>
    </Dialog>

    <Dialog
        v-model:open="archiving.open"
        :title="`Archiver ${archiving.entry?.name ?? ''}`"
        description="L’entrée n’est plus proposée ; les résultats déjà saisis gardent son nom. Elle se restaure à tout moment."
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
