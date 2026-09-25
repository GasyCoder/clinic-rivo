<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    Briefcase,
    BriefcaseBusiness,
    Check,
    CircleCheck,
    CircleOff,
    Hash,
    Layers,
    Network,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    Settings,
    Users,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { hrUrl } from '@/utilities/hrUrl';

/**
 * ADR-183 — les modules Départements et Fonctions, une même page pour deux
 * référentiels. Mêmes droits que les Paramètres RH (`hr_settings.*`), mêmes
 * actions : l'écran ne décide rien, le serveur revérifie tout.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    kind: { type: String, required: true },
    items: { type: Array, default: () => [] },
});

const { can } = usePermissions();

const COPY = {
    departments: {
        title: 'Départements',
        singular: 'département',
        article: 'un département',
        newLabel: 'Nouveau département',
        feminine: false,
        icon: Network,
        headerIcon: 'building',
        description: 'Les services de la clinique : chaque dossier employé y est affecté, et le planning les propose.',
        placeholder: 'Ex. Médecine, Pharmacie, Accueil…',
        usage: 'Affectation du dossier employé et proposition du planning.',
    },
    'job-titles': {
        title: 'Fonctions',
        singular: 'fonction',
        article: 'une fonction',
        newLabel: 'Nouvelle fonction',
        feminine: true,
        icon: BriefcaseBusiness,
        headerIcon: 'briefcase',
        description: 'Les postes occupés par le personnel : chaque dossier employé porte sa fonction, reprise dans les listes et les documents.',
        placeholder: 'Ex. Infirmier, Sage-femme, Caissier…',
        usage: 'Fonction du dossier employé, reprise dans les listes et les documents RH.',
    },
};
const copy = computed(() => COPY[props.kind] ?? COPY.departments);
/** L'adresse du module, ramenée au site choisi quand l'écran est ouvert sur le portail (ADR-182). */
const basePath = computed(() => hrUrl(`/administration/${props.kind}`));

/* Filtres : tout est servi, la liste est courte — le filtre ne recharge rien. */
const query = ref('');
const statusFilter = ref('current');

const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const stateOf = (item) => (item.archived ? 'archived' : item.active ? 'active' : 'inactive');

const counts = computed(() => ({
    current: props.items.filter((item) => ! item.archived).length,
    active: props.items.filter((item) => stateOf(item) === 'active').length,
    inactive: props.items.filter((item) => stateOf(item) === 'inactive').length,
    archived: props.items.filter((item) => item.archived).length,
}));
const cards = computed(() => [
    { value: 'current', label: 'En service', hint: 'actifs et inactifs', icon: Layers, tone: 'bg-primary/10 text-primary' },
    { value: 'active', label: 'Actifs', hint: 'proposés aux dossiers', icon: CircleCheck, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' },
    { value: 'inactive', label: 'Inactifs', hint: 'plus proposés', icon: CircleOff, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' },
    { value: 'archived', label: 'Archivés', hint: 'restaurables', icon: Archive, tone: 'bg-muted text-muted-foreground' },
]);

const shown = computed(() => {
    const terms = normalize(query.value).split(/\s+/).filter(Boolean);

    return props.items.filter((item) => {
        if (statusFilter.value === 'current' && item.archived) return false;
        if (statusFilter.value !== 'current' && stateOf(item) !== statusFilter.value) return false;
        const haystack = normalize(`${item.label} ${item.code}`);

        return terms.every((term) => haystack.includes(term));
    });
});
const totalEmployees = computed(() => props.items.filter((item) => ! item.archived).reduce((sum, item) => sum + item.employees_count, 0));

/* ------------------------------------------------------------------ */
/* Créer et modifier                                                   */
/* ------------------------------------------------------------------ */

const editing = ref(null);
const dialogOpen = ref(false);
const codeTouched = ref(false);
const form = useForm({ label: '', code: '', position: '', active: true });

/** Le code proposé depuis le libellé : la même règle que le serveur. */
const codeFrom = (label) => String(label ?? '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 80);

watch(() => form.label, (label) => {
    if (! codeTouched.value) form.code = codeFrom(label);
});

const focusLabel = () => nextTick(() => document.getElementById('structure-label')?.focus());

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.active = true;
    codeTouched.value = false;
    dialogOpen.value = true;
    focusLabel();
};
const openEdit = (item) => {
    editing.value = item;
    form.clearErrors();
    form.label = item.label;
    form.code = item.code;
    form.position = item.position ?? '';
    form.active = item.active;
    codeTouched.value = true;
    dialogOpen.value = true;
    focusLabel();
};
const closeDialog = () => {
    if (form.processing) return;
    dialogOpen.value = false;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => { dialogOpen.value = false; } };
    const payload = (data) => ({ ...data, position: data.position === '' ? null : data.position });

    if (editing.value) {
        form.transform(payload).put(`${basePath.value}/${editing.value.uuid}`, options);
        return;
    }

    form.transform(payload).post(basePath.value, options);
};

/* ------------------------------------------------------------------ */
/* Archiver et restaurer                                               */
/* ------------------------------------------------------------------ */

const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (item) => {
    archiving.value = item;
    archiveForm.reset();
    archiveForm.clearErrors();
};
const closeArchive = () => {
    if (archiveForm.processing) return;
    archiving.value = null;
};
const confirmArchive = () => archiveForm.delete(`${basePath.value}/${archiving.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; },
});

const restoring = ref(null);
const restore = (item) => {
    restoring.value = item.uuid;
    router.post(`${basePath.value}/${item.uuid}/restore`, {}, {
        preserveScroll: true,
        onFinish: () => { restoring.value = null; },
    });
};

const employeeLine = (item) => {
    if (! item.employees_count) return 'Aucun employé';
    const inactive = item.employees_count - item.active_employees_count;

    return `${item.employees_count} employé${item.employees_count > 1 ? 's' : ''}${inactive ? ` · ${inactive} inactif${inactive > 1 ? 's' : ''}` : ''}`;
};
</script>

<template>
    <Head :title="copy.title" />
    <div class="w-full space-y-5">
        <PageHeader eyebrow="Ressources humaines · Structure" :title="copy.title" :description="copy.description" :icon="copy.headerIcon">
            <template #actions>
                <Button v-if="can('hr_settings.view')" :as="Link" :href="hrUrl('/administration/settings')" variant="outline">
                    <Settings class="h-4 w-4" />Autres paramètres
                </Button>
                <Button v-if="can('hr_settings.create')" type="button" @click="openCreate">
                    <Plus class="h-4 w-4" />{{ copy.newLabel }}
                </Button>
            </template>
        </PageHeader>

        <!-- Compteurs : chaque carte est un filtre. -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" role="group" aria-label="Filtrer par état">
            <button
                v-for="card in cards"
                :key="card.value"
                type="button"
                :aria-pressed="statusFilter === card.value"
                :class="cn(
                    'relative flex items-center gap-3 rounded-xl border bg-card p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    statusFilter === card.value ? 'border-primary ring-1 ring-primary' : 'border-border hover:border-primary/40 hover:bg-accent/40',
                )"
                @click="statusFilter = card.value"
            >
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone)"><component :is="card.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-2xl font-bold leading-none tabular-nums text-foreground">{{ counts[card.value] }}</span>
                    <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                    <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                </span>
                <Check v-if="statusFilter === card.value" class="absolute end-3 top-3 h-4 w-4 text-primary" aria-hidden="true" />
            </button>
        </div>

        <Card class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="flex items-center gap-2 text-sm text-muted-foreground">
                    <Users class="h-4 w-4" />
                    <span><strong class="font-semibold text-foreground">{{ totalEmployees }}</strong> employé(s) réparti(s) · {{ copy.usage }}</span>
                </p>
                <div class="relative w-full sm:w-72">
                    <IconInput v-model="query" :icon="Search" type="search" :placeholder="`Rechercher ${copy.article}…`" :aria-label="`Rechercher ${copy.article}`" class="pe-9" />
                    <button v-if="query" type="button" class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="query = ''">
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <ul v-if="shown.length" class="divide-y divide-border">
                <li v-for="item in shown" :key="item.uuid" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 transition-colors hover:bg-accent/30">
                    <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', item.archived ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-primary')">
                        <component :is="copy.icon" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1 basis-48">
                        <p class="flex flex-wrap items-center gap-2">
                            <span :class="cn('text-sm font-semibold', item.archived ? 'text-muted-foreground line-through' : 'text-foreground')">{{ item.label }}</span>
                            <Badge v-if="item.archived" variant="outline">Archivé{{ copy.feminine ? 'e' : '' }}</Badge>
                            <Badge v-else-if="! item.active" variant="warning">Inactif{{ copy.feminine ? 've' : '' }}</Badge>
                        </p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1 font-mono"><Hash class="h-3 w-3" />{{ item.code }}</span>
                            <span class="inline-flex items-center gap-1"><Users class="h-3 w-3" />{{ employeeLine(item) }}</span>
                            <span v-if="item.position">Ordre {{ item.position }}</span>
                        </p>
                        <p v-if="item.archived && item.delete_reason" class="mt-1 text-xs text-muted-foreground">Motif : {{ item.delete_reason }}</p>
                    </div>
                    <div class="ms-auto flex shrink-0 items-center gap-1">
                        <template v-if="! item.archived">
                            <Button v-if="can('hr_settings.update')" type="button" size="sm" icon variant="ghost" :title="`Modifier ${item.label}`" :aria-label="`Modifier ${item.label}`" @click="openEdit(item)">
                                <Pencil class="h-4 w-4" />
                            </Button>
                            <Button v-if="can('hr_settings.archive')" type="button" size="sm" icon variant="ghost" class="hover:text-destructive" :title="`Archiver ${item.label}`" :aria-label="`Archiver ${item.label}`" @click="openArchive(item)">
                                <Archive class="h-4 w-4" />
                            </Button>
                        </template>
                        <Button v-else-if="can('hr_settings.restore')" type="button" size="sm" variant="outline" :disabled="restoring === item.uuid" @click="restore(item)">
                            <RotateCcw class="h-4 w-4" />Restaurer
                        </Button>
                    </div>
                </li>
            </ul>

            <div v-else class="px-5 py-14 text-center">
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><component :is="copy.icon" class="h-6 w-6" /></span>
                <p class="mt-3 text-sm font-bold text-foreground">{{ query ? 'Aucun résultat' : items.length ? 'Rien dans ce filtre' : `Aucun${copy.feminine ? 'e' : ''} ${copy.singular} pour l’instant` }}</p>
                <p class="mx-auto mt-1 max-w-md text-xs leading-5 text-muted-foreground">
                    {{ query ? 'Essayez un autre mot, ou effacez la recherche.' : items.length ? 'Choisissez un autre filtre au-dessus.' : `Ajoutez ${copy.article} : ${copy.usage.charAt(0).toLowerCase()}${copy.usage.slice(1)}` }}
                </p>
                <Button v-if="! query && ! items.length && can('hr_settings.create')" type="button" class="mt-4" @click="openCreate"><Plus class="h-4 w-4" />{{ copy.newLabel }}</Button>
            </div>
        </Card>

        <!-- Créer / modifier -->
        <Dialog
            :open="dialogOpen"
            :title="editing ? `Modifier « ${editing.label} »` : copy.newLabel"
            :description="editing ? 'Les dossiers qui portent déjà cette valeur suivent le nouveau libellé.' : 'Le code se déduit du libellé ; il reste modifiable.'"
            :dismissible="false"
            @update:open="(value) => value || closeDialog()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="editing ? Pencil : copy.icon" class="h-5 w-5" /></span>
            </template>
            <form id="structure-form" class="grid gap-4" novalidate @submit.prevent="submit">
                <FormField label="Libellé" required :error="form.errors.label">
                    <IconInput id="structure-label" v-model="form.label" :icon="copy.icon" maxlength="255" :placeholder="copy.placeholder" :aria-invalid="Boolean(form.errors.label)" />
                </FormField>
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_7rem]">
                    <FormField label="Code" required :hint="codeTouched ? '' : '(généré)'" :error="form.errors.code">
                        <IconInput v-model="form.code" :icon="Hash" maxlength="80" class="font-mono uppercase" placeholder="CODE" @input="codeTouched = true" />
                    </FormField>
                    <FormField label="Ordre" hint="(facultatif)" :error="form.errors.position">
                        <Input v-model="form.position" type="number" min="0" max="65535" inputmode="numeric" />
                    </FormField>
                </div>
                <label v-if="editing" for="structure-active" class="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-muted/40 px-3.5 py-3">
                    <Checkbox id="structure-active" v-model="form.active" class="mt-0.5" />
                    <span>
                        <span class="block text-sm font-semibold text-foreground">Proposé{{ copy.feminine ? 'e' : '' }} dans les formulaires</span>
                        <span class="block text-xs leading-5 text-muted-foreground">Décoché, les dossiers qui le portent le gardent, mais il n’est plus proposé pour un nouveau dossier.</span>
                    </span>
                </label>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="form.processing" @click="closeDialog">Annuler</Button>
                <Button type="submit" form="structure-form" :disabled="form.processing || ! form.label.trim()">
                    <Check class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Ajouter' }}
                </Button>
            </template>
        </Dialog>

        <!-- Archiver -->
        <Dialog
            :open="archiving !== null"
            :title="archiving ? `Archiver « ${archiving.label} »` : ''"
            description="Rien n’est supprimé : la valeur se restaure à tout moment depuis le filtre « Archivés »."
            :dismissible="false"
            @update:open="(value) => value || closeArchive()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300"><Archive class="h-5 w-5" /></span>
            </template>
            <form id="structure-archive-form" class="space-y-4" novalidate @submit.prevent="confirmArchive">
                <p v-if="archiving?.employees_count" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    <Briefcase class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ archiving.employees_count }} dossier(s) employé le portent : ils le gardent, mais il ne sera plus proposé pour un nouveau dossier.</span>
                </p>
                <FormField label="Motif" required :error="archiveForm.errors.reason">
                    <Textarea v-model="archiveForm.reason" :rows="3" maxlength="1000" placeholder="Pourquoi cette valeur n’est-elle plus utilisée ?" />
                </FormField>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="archiveForm.processing" @click="closeArchive">Annuler</Button>
                <Button type="submit" form="structure-archive-form" variant="destructive" :disabled="archiveForm.processing || ! archiveForm.reason.trim()">
                    <Archive class="h-4 w-4" />{{ archiveForm.processing ? 'Archivage…' : 'Archiver' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
