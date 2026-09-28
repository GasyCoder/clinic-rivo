<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TubeChip from '@/Components/Laboratory/TubeChip.vue';
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
    Archive, ArchiveRestore, ArrowLeft, Download, Droplet, FolderTree, Pencil, Plus, Search, TestTube,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { labUrl } from '@/utilities/labUrl';

defineOptions({ layout: AppLayout });

/**
 * ADR-214 — le référentiel des types de prélèvement et des tubes du site : ce
 * que la réception propose quand elle enregistre un prélèvement, et la couleur
 * qui s'imprime sur son étiquette. Aucun prix : facturer un prélèvement est une
 * prestation du catalogue au tarif du Super Admin (ADR-024). Rien n'est
 * supprimé : une entrée s'archive avec un motif et se restaure.
 */
const props = defineProps({
    sampleTypes: { type: Array, default: () => [] },
    tubes: { type: Array, default: () => [] },
    showArchived: { type: Boolean, default: false },
    isEmpty: { type: Boolean, default: false },
    can: { type: Object, default: () => ({}) },
});

const tab = ref('sample');
const TABS = [
    { value: 'sample', label: 'Types de prélèvement', icon: Droplet },
    { value: 'tube', label: 'Tubes', icon: TestTube },
];
const counts = computed(() => ({
    sample: props.sampleTypes.filter((entry) => !entry.archived).length,
    tube: props.tubes.filter((entry) => !entry.archived).length,
}));

const query = ref('');
const normalize = (text) => String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const matches = (...texts) => {
    const needle = normalize(query.value.trim());
    return !needle || texts.some((text) => normalize(text).includes(needle));
};
const visibleSamples = computed(() => props.sampleTypes.filter((entry) => matches(entry.name, entry.instructions, entry.tube?.code, entry.tube?.name)));
const visibleTubes = computed(() => props.tubes.filter((entry) => matches(entry.code, entry.name, entry.cap_color)));

const tubeOptions = computed(() => [
    { value: '', label: 'Aucun tube' },
    ...props.tubes.filter((tube) => !tube.archived && tube.is_active).map((tube) => ({ value: tube.uuid, label: `${tube.code} — ${tube.name}${tube.cap_color ? ` (${tube.cap_color})` : ''}` })),
]);

const setArchives = (value) => router.get(labUrl('/laboratory/prelevements'), value ? { archives: 1 } : {}, { preserveScroll: true, preserveState: true, replace: true });

// Ajouter / modifier
const editor = ref({ open: false, kind: 'sample', entry: null });
const form = useForm({ name: '', code: '', cap_color: '', color_hex: '', tube_type_uuid: '', instructions: '', is_active: true });
const openEditor = (kind, entry = null) => {
    form.clearErrors();
    form.name = entry?.name ?? '';
    form.code = entry?.code ?? '';
    form.cap_color = entry?.cap_color ?? '';
    form.color_hex = entry?.color_hex ?? '';
    form.tube_type_uuid = entry?.tube?.uuid ?? '';
    form.instructions = entry?.instructions ?? '';
    form.is_active = entry?.is_active ?? true;
    editor.value = { open: true, kind, entry };
};
const submit = () => {
    const { kind, entry } = editor.value;
    form.transform((data) => ({
        name: data.name,
        ...(kind === 'tube'
            ? { code: data.code, cap_color: data.cap_color || null, color_hex: data.color_hex || null }
            : { tube_type_uuid: data.tube_type_uuid || null, instructions: data.instructions || null }),
        ...(entry ? { is_active: data.is_active } : {}),
    }));
    const options = { preserveScroll: true, onSuccess: () => { editor.value.open = false; } };
    if (entry) form.put(labUrl(`/laboratory/prelevements/${kind}/${entry.uuid}`), options);
    else form.post(labUrl(`/laboratory/prelevements/${kind}`), options);
};
const editorTitle = computed(() => {
    const { kind, entry } = editor.value;
    if (kind === 'tube') return entry ? `Modifier le tube ${entry.code}` : 'Nouveau tube';
    return entry ? `Modifier « ${entry.name} »` : 'Nouveau type de prélèvement';
});
const previewTube = computed(() => ({ code: form.code || 'CODE', name: form.name, color: form.cap_color, hex: form.color_hex }));

// Archiver
const archiving = ref({ open: false, kind: null, entry: null });
const archiveForm = useForm({ reason: '' });
const openArchive = (kind, entry) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiving.value = { open: true, kind, entry };
};
const archive = () => archiveForm.delete(labUrl(`/laboratory/prelevements/${archiving.value.kind}/${archiving.value.entry.uuid}`), {
    preserveScroll: true,
    onSuccess: () => { archiving.value.open = false; },
});
const restore = (kind, entry) => router.post(labUrl(`/laboratory/prelevements/${kind}/${entry.uuid}/restore`), {}, { preserveScroll: true });

const importing = ref(false);
const importStarter = () => {
    importing.value = true;
    router.post(labUrl('/laboratory/prelevements/referentiel-de-depart'), {}, { preserveScroll: true, onFinish: () => { importing.value = false; } });
};
const archiveName = computed(() => {
    const { kind, entry } = archiving.value;
    return entry ? (kind === 'tube' ? `${entry.code} — ${entry.name}` : entry.name) : '';
});
</script>

<template>
    <Head title="Prélèvements & tubes" />

    <div class="mx-auto w-full max-w-screen-xl space-y-5">
        <Button :as="Link" :href="labUrl('/laboratory')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" /> File du laboratoire</Button>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><TestTube class="h-6 w-6" /></span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Prélèvements & tubes</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ counts.sample }} type(s) de prélèvement · {{ counts.tube }} tube(s) — ce que la réception propose et la couleur imprimée sur l’étiquette.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-muted-foreground">
                    <Switch :model-value="showArchived" @update:model-value="setArchives" /> Afficher les archivés
                </label>
                <Button v-if="can.create && !isEmpty" type="button" size="sm" @click="openEditor(tab)">
                    <Plus class="h-4 w-4" /> {{ tab === 'tube' ? 'Nouveau tube' : 'Nouveau type' }}
                </Button>
            </div>
        </header>

        <Card v-if="isEmpty" class="p-8 text-center">
            <FolderTree class="mx-auto h-9 w-9 text-muted-foreground/60" />
            <p class="mt-3 font-semibold text-foreground">Le référentiel est vide.</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-muted-foreground">
                Importez le référentiel de départ — les tubes (EDTA, sec, citrate…) et les prélèvements courants (sang, urine, selles…) —, puis corrigez-le. Vous pouvez aussi tout saisir à la main.
            </p>
            <div class="mt-4 flex flex-wrap justify-center gap-2">
                <Button v-if="can.create" type="button" :disabled="importing" @click="importStarter"><Download class="h-4 w-4" /> Importer le référentiel de départ</Button>
                <Button v-if="can.create" type="button" variant="outline" @click="openEditor('tube')"><Plus class="h-4 w-4" /> Saisir un tube</Button>
            </div>
        </Card>

        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="inline-flex rounded-lg bg-muted p-1" role="tablist" aria-label="Référentiel">
                    <Button
                        v-for="item in TABS"
                        :key="item.value"
                        type="button"
                        role="tab"
                        size="sm"
                        :variant="tab === item.value ? 'secondary' : 'ghost'"
                        :aria-selected="tab === item.value"
                        class="shadow-none"
                        @click="tab = item.value"
                    >
                        <component :is="item.icon" class="h-4 w-4" /> {{ item.label }}
                        <Badge variant="outline" class="ms-1">{{ counts[item.value] }}</Badge>
                    </Button>
                </div>
                <div class="w-full sm:w-72"><IconInput v-model="query" :icon="Search" class="w-full" :placeholder="tab === 'tube' ? 'Code, nom ou couleur' : 'Prélèvement ou tube'" aria-label="Rechercher dans le référentiel" /></div>
            </div>

            <Card v-if="tab === 'sample'" class="overflow-hidden">
                <ul class="divide-y divide-border">
                    <li v-for="entry in visibleSamples" :key="entry.uuid" :class="cn('flex flex-wrap items-center gap-3 px-4 py-3', entry.archived && 'opacity-60')">
                        <Droplet class="h-4 w-4 shrink-0 text-muted-foreground" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                                {{ entry.name }}
                                <Badge v-if="!entry.is_active" tone="warning">Inactif</Badge>
                                <Badge v-if="entry.used" variant="outline" title="Déjà utilisé pour un prélèvement">Utilisé</Badge>
                                <Badge v-if="entry.archived" tone="neutral" :title="entry.delete_reason">Archivé</Badge>
                            </p>
                            <p v-if="entry.instructions" class="mt-0.5 text-xs text-muted-foreground">{{ entry.instructions }}</p>
                        </div>
                        <TubeChip :tube="entry.tube" with-name />
                        <div class="flex items-center gap-1">
                            <template v-if="entry.archived">
                                <Button v-if="can.restore" type="button" size="xs" variant="outline" @click="restore('sample', entry)"><ArchiveRestore class="h-3.5 w-3.5" /> Restaurer</Button>
                            </template>
                            <template v-else>
                                <Button v-if="can.update" type="button" size="xs" variant="ghost" icon :aria-label="`Modifier ${entry.name}`" @click="openEditor('sample', entry)"><Pencil class="h-3.5 w-3.5" /></Button>
                                <Button v-if="can.archive" type="button" size="xs" variant="ghost" icon :aria-label="`Archiver ${entry.name}`" @click="openArchive('sample', entry)"><Archive class="h-3.5 w-3.5" /></Button>
                            </template>
                        </div>
                    </li>
                    <li v-if="!visibleSamples.length" class="px-4 py-10 text-center text-sm text-muted-foreground">
                        {{ query ? 'Rien ne correspond à la recherche.' : 'Aucun type de prélèvement.' }}
                    </li>
                </ul>
            </Card>

            <Card v-else class="overflow-hidden">
                <ul class="divide-y divide-border">
                    <li v-for="entry in visibleTubes" :key="entry.uuid" :class="cn('flex flex-wrap items-center gap-3 px-4 py-3', entry.archived && 'opacity-60')">
                        <span class="inline-block h-6 w-6 shrink-0 rounded-full border border-border" :style="{ backgroundColor: entry.color_hex || 'transparent' }" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                                <span class="font-mono">{{ entry.code }}</span> — {{ entry.name }}
                                <Badge v-if="!entry.is_active" tone="warning">Inactif</Badge>
                                <Badge v-if="entry.used" variant="outline" title="Déjà utilisé pour un prélèvement">Utilisé</Badge>
                                <Badge v-if="entry.archived" tone="neutral" :title="entry.delete_reason">Archivé</Badge>
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Bouchon {{ entry.cap_color || 'non précisé' }} · {{ entry.sample_types_count }} type(s) de prélèvement
                            </p>
                        </div>
                        <div class="flex items-center gap-1">
                            <template v-if="entry.archived">
                                <Button v-if="can.restore" type="button" size="xs" variant="outline" @click="restore('tube', entry)"><ArchiveRestore class="h-3.5 w-3.5" /> Restaurer</Button>
                            </template>
                            <template v-else>
                                <Button v-if="can.update" type="button" size="xs" variant="ghost" icon :aria-label="`Modifier ${entry.code}`" @click="openEditor('tube', entry)"><Pencil class="h-3.5 w-3.5" /></Button>
                                <Button
                                    v-if="can.archive"
                                    type="button"
                                    size="xs"
                                    variant="ghost"
                                    icon
                                    :disabled="entry.sample_types_count > 0"
                                    :title="entry.sample_types_count > 0 ? 'Proposé par un type de prélèvement : changez-le d’abord.' : undefined"
                                    :aria-label="`Archiver ${entry.code}`"
                                    @click="openArchive('tube', entry)"
                                ><Archive class="h-3.5 w-3.5" /></Button>
                            </template>
                        </div>
                    </li>
                    <li v-if="!visibleTubes.length" class="px-4 py-10 text-center text-sm text-muted-foreground">
                        {{ query ? 'Rien ne correspond à la recherche.' : 'Aucun tube.' }}
                    </li>
                </ul>
            </Card>
        </template>
    </div>

    <Dialog v-model:open="editor.open" :title="editorTitle" :dismissible="false">
        <form id="sample-type-form" class="space-y-4" @submit.prevent="submit">
            <template v-if="editor.kind === 'tube'">
                <div class="grid gap-4 sm:grid-cols-[9rem_minmax(0,1fr)]">
                    <FormField label="Code" :error="form.errors.code" required hint="lettres, chiffres, - ou _">
                        <Input v-model="form.code" autofocus maxlength="30" class="font-mono uppercase" />
                    </FormField>
                    <FormField label="Nom" :error="form.errors.name" required>
                        <Input v-model="form.name" maxlength="120" placeholder="Tube EDTA" />
                    </FormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem]">
                    <FormField label="Couleur du bouchon" hint="(facultatif)" :error="form.errors.cap_color">
                        <Input v-model="form.cap_color" maxlength="60" placeholder="Violet" />
                    </FormField>
                    <FormField label="Teinte" hint="(étiquette)" :error="form.errors.color_hex">
                        <div class="flex items-center gap-2">
                            <input v-model="form.color_hex" type="color" class="h-9 w-12 cursor-pointer rounded-md border border-border bg-card p-1" aria-label="Teinte du bouchon" />
                            <Button v-if="form.color_hex" type="button" size="xs" variant="ghost" @click="form.color_hex = ''">Aucune</Button>
                        </div>
                    </FormField>
                </div>
                <p class="flex items-center gap-2 text-xs text-muted-foreground">Aperçu : <TubeChip :tube="previewTube" with-name /></p>
            </template>
            <template v-else>
                <FormField label="Nom" :error="form.errors.name" required>
                    <Input v-model="form.name" autofocus maxlength="150" placeholder="Sang veineux" />
                </FormField>
                <FormField label="Tube proposé" hint="(facultatif)" :error="form.errors.tube_type_uuid">
                    <Select v-model="form.tube_type_uuid" :options="tubeOptions" />
                </FormField>
                <FormField label="Consignes de prélèvement" hint="(facultatif)" :error="form.errors.instructions">
                    <Textarea v-model="form.instructions" rows="2" maxlength="500" placeholder="À jeun, remplir jusqu’au trait…" />
                </FormField>
            </template>
            <label v-if="editor.entry" class="flex items-center gap-2 text-sm text-foreground">
                <Switch v-model="form.is_active" /> Proposé à la réception
            </label>
        </form>
        <template #footer>
            <Button type="button" variant="outline" @click="editor.open = false">Annuler</Button>
            <Button type="submit" form="sample-type-form" :disabled="form.processing || !form.name.trim() || (editor.kind === 'tube' && !form.code.trim())">Enregistrer</Button>
        </template>
    </Dialog>

    <Dialog
        v-model:open="archiving.open"
        :title="`Archiver ${archiveName}`"
        description="L’entrée n’est plus proposée ; les prélèvements déjà enregistrés gardent son nom et son tube. Elle se restaure à tout moment."
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
