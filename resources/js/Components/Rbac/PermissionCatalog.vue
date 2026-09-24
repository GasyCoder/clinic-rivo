<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Check, ChevronsDownUp, CircleCheck, CircleDashed, KeyRound, Pencil, Plus, Search, Trash2, TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import PermissionModuleCard from '@/Components/Rbac/PermissionModuleCard.vue';
import { keepHeaderInPlace, nextModuleState } from '@/composables/useExclusiveModules';
import { PERMISSION_MODULES, permissionCategoryIcon, permissionCategoryLabel, permissionCategoryModule, permissionCategoryOrder } from '@/utilities/permissionCategories';
import { comparePermissions, isSensitivePermission, permissionLabel, permissionMatchesSearch } from '@/utilities/permissionWorkspace';
import { cn } from '@/lib/cn';

/**
 * Le catalogue des permissions d'un site (ADR-101).
 *
 * Une permission n'est pas un réglage : c'est un mot que le code doit
 * connaître. On peut en créer une — préparer un droit avant sa
 * fonctionnalité, nommer un besoin métier — mais tant qu'aucune route,
 * Policy ou écran ne la vérifie, elle n'ouvre et ne ferme rien.
 *
 * Plutôt que d'interdire, cet écran le **dit** : `used_by_app` est calculé
 * côté serveur depuis le code lui-même, jamais depuis une liste tenue à la
 * main. C'est aussi ce qui décide de ce que l'écran autorise — une
 * permission que `can:` vérifie ne se supprime pas, la retirer rendrait son
 * écran inaccessible à tout le monde sans message.
 */
const props = defineProps({
    permissions: { type: Array, default: () => [] },
    siteCode: { type: String, default: '' },
    siteName: { type: String, default: '' },
    can: { type: Object, default: () => ({ create: false, update: false, remove: false }) },
});

const search = ref('');
const filter = ref('all');

const matches = (permission) => permissionMatchesSearch(
    permission,
    search.value,
    permissionCategoryLabel(permission.module),
);

const matchesFilter = (permission) => {
    if (filter.value === 'used') return permission.used_by_app;
    if (filter.value === 'unused') return ! permission.used_by_app;
    if (filter.value === 'unassigned') return ! permission.roles_count && ! permission.accounts_count;
    if (filter.value === 'sensitive') return isSensitivePermission(permission);

    return true;
};

const visible = computed(() => props.permissions.filter((p) => matches(p) && matchesFilter(p)));

const counts = computed(() => {
    const searched = props.permissions.filter(matches);

    return {
        all: searched.length,
        used: searched.filter((p) => p.used_by_app).length,
        unused: searched.filter((p) => ! p.used_by_app).length,
        unassigned: searched.filter((p) => ! p.roles_count && ! p.accounts_count).length,
        sensitive: searched.filter(isSensitivePermission).length,
    };
});

const filters = [
    { value: 'all', label: 'Toutes' },
    { value: 'used', label: 'Vérifiées par l’application' },
    { value: 'unused', label: 'Pas encore vérifiées' },
    { value: 'unassigned', label: 'Attribuées à personne' },
    { value: 'sensitive', label: 'Sensibles' },
];

/**
 * Le catalogue se lit par module — Pharmacie, Chirurgie, Caisse — puis par
 * fonctionnalité, dans l'ordre de l'écran des rôles (ADR-178) ; jamais par
 * ordre alphabétique de code.
 */
const moduleSections = computed(() => {
    const byCategory = new Map();

    for (const permission of visible.value) {
        if (! byCategory.has(permission.module)) byCategory.set(permission.module, []);
        byCategory.get(permission.module).push(permission);
    }

    return PERMISSION_MODULES
        .map((module) => ({
            ...module,
            categories: Array.from(byCategory.entries())
                .filter(([key]) => permissionCategoryModule(key) === module.key)
                .map(([key, items]) => ({
                    key,
                    label: permissionCategoryLabel(key),
                    icon: permissionCategoryIcon(key),
                    permissions: items.sort(comparePermissions),
                }))
                .sort((left, right) => permissionCategoryOrder(left.key) - permissionCategoryOrder(right.key)
                    || left.label.localeCompare(right.label, 'fr')),
        }))
        .filter((module) => module.categories.length > 0)
        .map((module) => {
            const permissions = module.categories.flatMap((category) => category.permissions);

            return { ...module, count: permissions.length, used: permissions.filter((permission) => permission.used_by_app).length };
        });
});

/**
 * Replié par défaut : quatorze lignes au lieu de trois cent soixante-dix-sept.
 * Une recherche ou un filtre ouvre d'eux-mêmes les modules qui ont un résultat.
 */
const filtering = computed(() => search.value.trim() !== '' || filter.value !== 'all');
const expandedModules = ref(new Set());
const collapsedWhileFiltering = ref(new Set());

watch([search, filter], () => { collapsedWhileFiltering.value = new Set(); });

const isExpanded = (module) => (filtering.value
    ? ! collapsedWhileFiltering.value.has(module.key)
    : expandedModules.value.has(module.key));

/** Un seul module ouvert à la fois, comme dans le socle des rôles (ADR-178). */
const toggleModule = (module) => keepHeaderInPlace(module.key, () => {
    const next = nextModuleState({
        key: module.key,
        opening: ! isExpanded(module),
        filtering: filtering.value,
        shownKeys: moduleSections.value.map((shown) => shown.key),
        collapsed: collapsedWhileFiltering.value,
    });

    if (next.collapsed) collapsedWhileFiltering.value = next.collapsed;
    else expandedModules.value = new Set(next.expanded);
});

const anyExpanded = computed(() => moduleSections.value.some(isExpanded));

const collapseAll = () => {
    if (filtering.value) collapsedWhileFiltering.value = new Set(moduleSections.value.map((module) => module.key));
    else expandedModules.value = new Set();
};

/* ------------------------------------------------------------------ */
/* Créer                                                              */
/* ------------------------------------------------------------------ */

const creating = ref(false);
const createForm = useForm({ site_code: '', name: '', label: '' });

/** L'aperçu montre le nom tel qu'il sera enregistré, avant de valider. */
const normalizedName = computed(() => createForm.name.trim().toLowerCase().replace(/\s+/g, '_'));
const nameIsValid = computed(() => /^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/.test(normalizedName.value));
const nameTaken = computed(() => props.permissions.some((p) => p.name === normalizedName.value));

const openCreate = () => {
    createForm.reset();
    createForm.clearErrors();
    createForm.site_code = props.siteCode;
    creating.value = true;
};

const submitCreate = () => createForm
    .transform((data) => ({ ...data, name: normalizedName.value }))
    .post('/super-admin/workspaces/roles/permissions', {
        preserveScroll: true,
        onSuccess: () => { creating.value = false; },
    });

/* ------------------------------------------------------------------ */
/* Corriger le libellé                                                */
/* ------------------------------------------------------------------ */

const editing = ref(null);
const editForm = useForm({ label: '' });

const openEdit = (permission) => {
    editForm.reset();
    editForm.clearErrors();
    editForm.label = permissionLabel(permission);
    editing.value = permission;
};

const submitEdit = () => editForm.put(
    `/super-admin/workspaces/roles/${props.siteCode}/catalog/${editing.value.id}`,
    { preserveScroll: true, onSuccess: () => { editing.value = null; } },
);

/* ------------------------------------------------------------------ */
/* Retirer                                                            */
/* ------------------------------------------------------------------ */

const removing = ref(null);
const removeForm = useForm({});

/** Trois raisons de refuser, nommées avant le clic plutôt qu'après l'envoi. */
const blockers = (permission) => {
    const reasons = [];

    if (permission.used_by_app) reasons.push('l’application la vérifie quelque part');
    if (permission.roles_count) reasons.push(`${permission.roles_count} rôle${permission.roles_count > 1 ? 's' : ''} l’accorde${permission.roles_count > 1 ? 'nt' : ''}`);
    if (permission.accounts_count) reasons.push(`${permission.accounts_count} compte${permission.accounts_count > 1 ? 's' : ''} porte${permission.accounts_count > 1 ? 'nt' : ''} une exception dessus`);

    return reasons;
};

const removable = (permission) => props.can.remove && blockers(permission).length === 0;

const submitRemove = () => removeForm.delete(
    `/super-admin/workspaces/roles/${props.siteCode}/catalog/${removing.value.id}`,
    { preserveScroll: true, onSuccess: () => { removing.value = null; } },
);
</script>

<template>
    <div class="space-y-4">
        <Card class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <h2 class="text-base font-bold text-foreground">Catalogue des permissions · {{ siteName }}</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ permissions.length }} permission{{ permissions.length > 1 ? 's' : '' }} connue{{ permissions.length > 1 ? 's' : '' }} de ce site.
                        Une permission est un mot que le code doit connaître — créer le mot ne crée pas le contrôle.
                    </p>
                </div>
                <div class="flex shrink-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <IconInput
                        v-model="search"
                        :icon="Search"
                        type="search"
                        class="sm:w-72 [&::-webkit-search-cancel-button]:appearance-none"
                        placeholder="Libellé, nom, catégorie…"
                        autocomplete="off"
                        aria-label="Rechercher une permission"
                        @keydown.esc="search = ''"
                    />
                    <Button v-if="can.create" type="button" variant="primary" @click="openCreate">
                        <Plus class="h-4 w-4" />Nouvelle permission
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1.5 px-4 py-3" role="group" aria-label="Filtrer le catalogue">
                <button
                    v-for="option in filters"
                    :key="option.value"
                    type="button"
                    :class="cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        filter === option.value
                            ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                            : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
                    )"
                    :aria-pressed="filter === option.value"
                    @click="filter = option.value"
                >
                    {{ option.label }}
                    <span :class="cn('tabular-nums', filter === option.value ? 'text-primary-foreground/80' : 'font-normal')">{{ counts[option.value] }}</span>
                </button>
                <button
                    v-if="anyExpanded"
                    type="button"
                    class="ms-auto inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground"
                    @click="collapseAll"
                >
                    <ChevronsDownUp class="h-3.5 w-3.5" />
                    Tout replier
                </button>
            </div>

            <!-- Ce que « pas encore vérifiée » veut dire, écrit une fois en
                 tête plutôt que répété sur chaque ligne. -->
            <p
                v-if="counts.unused"
                class="flex items-start gap-2.5 border-t border-amber-200 bg-amber-50/60 px-4 py-2.5 text-xs leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100"
                role="status"
            >
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-300" />
                <span>
                    <strong>{{ counts.unused }} permission{{ counts.unused > 1 ? 's' : '' }} n’{{ counts.unused > 1 ? 'ont' : 'a' }} pas encore de contrôle dans l’application.</strong>
                    On peut les attribuer dès maintenant — elles n’ouvriront ni ne fermeront rien tant qu’une route, une règle serveur
                    ou un écran ne les vérifiera pas. C’est normal pour un module en préparation.
                </span>
            </p>
        </Card>

        <div v-if="! visible.length" class="rounded-xl border border-dashed border-border bg-card px-4 py-14 text-center">
            <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><Search class="h-5 w-5" /></span>
            <p class="mt-3 text-sm font-semibold text-foreground">Aucune permission dans cette vue</p>
            <p class="mt-1 text-xs text-muted-foreground">Changez la recherche ou le filtre.</p>
        </div>

        <!-- Les mêmes modules que la grille des rôles (ADR-178), repliés : la
             barre dit combien de leurs droits l'application vérifie déjà. -->
        <PermissionModuleCard
            v-for="module in moduleSections"
            :key="module.key"
            :module="module"
            :expanded="isExpanded(module)"
            :active="module.used"
            :total="module.count"
            :filtering="filtering"
            active-label="vérifiées par l’application"
            @toggle="toggleModule(module)"
        >
            <template v-for="section in module.categories" :key="section.key">
                <p class="flex items-center gap-2 border-b border-border bg-muted/40 px-4 py-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                    <component :is="section.icon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ section.label }} <span class="font-semibold tabular-nums">{{ section.permissions.length }}</span>
                </p>

                <div
                    v-for="permission in section.permissions"
                    :key="permission.id"
                    class="grid gap-3 border-b border-border/60 px-4 py-3 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <p class="text-sm font-semibold text-foreground">{{ permissionLabel(permission) }}</p>
                            <Badge v-if="isSensitivePermission(permission)" variant="warning" class="px-1.5 py-0 text-[10px] uppercase tracking-wide">
                                <TriangleAlert class="h-3 w-3" />Sensible
                            </Badge>
                            <Badge v-if="permission.used_by_app" variant="success" class="px-1.5 py-0 text-[10px]">
                                <CircleCheck class="h-3 w-3" />Vérifiée par l’application
                            </Badge>
                            <Badge v-else variant="outline" class="px-1.5 py-0 text-[10px] text-amber-700 dark:text-amber-300">
                                <CircleDashed class="h-3 w-3" />Pas encore vérifiée
                            </Badge>
                        </div>
                        <p class="mt-0.5 truncate font-mono text-[11px] text-muted-foreground" :title="permission.name">{{ permission.name }}</p>
                        <p class="mt-0.5 text-[11px] text-muted-foreground">
                            <template v-if="permission.roles_count || permission.accounts_count">
                                Accordée par {{ permission.roles_count }} rôle{{ permission.roles_count > 1 ? 's' : '' }}<template v-if="permission.accounts_count">, {{ permission.accounts_count }} exception{{ permission.accounts_count > 1 ? 's' : '' }} de compte</template>.
                            </template>
                            <template v-else>Attribuée à aucun rôle ni compte.</template>
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-1">
                        <Button
                            v-if="can.update"
                            type="button"
                            size="sm"
                            variant="ghost"
                            title="Corriger le libellé"
                            :aria-label="`Corriger le libellé de ${permission.name}`"
                            @click="openEdit(permission)"
                        >
                            <Pencil class="h-4 w-4" />
                        </Button>
                        <Button
                            v-if="can.remove"
                            type="button"
                            size="sm"
                            variant="ghost"
                            :disabled="! removable(permission)"
                            :title="removable(permission) ? 'Retirer du catalogue' : `Impossible : ${blockers(permission).join(' ; ')}`"
                            :aria-label="`Retirer ${permission.name} du catalogue`"
                            @click="removing = permission"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </template>
        </PermissionModuleCard>

        <!-- Créer -->
        <Dialog
            :open="creating"
            size="lg"
            title="Nouvelle permission"
            description="Elle est ajoutée au catalogue de ce site et devient attribuable immédiatement."
            :dismissible="! createForm.processing"
            @update:open="creating = $event"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><KeyRound class="h-5 w-5" /></span>
            </template>

            <form class="space-y-4" @submit.prevent="submitCreate">
                <FormField
                    label="Nom"
                    :error="createForm.errors.name"
                    hint="Convention « ressource.action » (ADR-007), en minuscules et sans accent. Il ne change plus ensuite : le code l’écrit en clair."
                >
                    <Input v-model="createForm.name" placeholder="kinesitherapie.view" autocomplete="off" spellcheck="false" />
                </FormField>

                <p v-if="createForm.name.trim()" class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs">
                    <span class="text-muted-foreground">Enregistré comme </span>
                    <code class="font-mono font-bold text-foreground">{{ normalizedName }}</code>
                    <span v-if="nameTaken" class="mt-1 block font-semibold text-destructive">Ce nom existe déjà dans ce catalogue.</span>
                    <span v-else-if="! nameIsValid" class="mt-1 block font-semibold text-destructive">Il manque la partie « . action » — par exemple <code class="font-mono">kinesitherapie.view</code>.</span>
                </p>

                <FormField label="Libellé" :error="createForm.errors.label" hint="La phrase que lira la personne qui coche la case.">
                    <Input v-model="createForm.label" placeholder="Voir les séances de kinésithérapie" autocomplete="off" />
                </FormField>

                <p class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-100">
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        Créer le mot ne crée pas le contrôle. Cette permission apparaîtra
                        « <strong>Pas encore vérifiée</strong> » tant qu’une route, une règle serveur ou un écran ne l’utilisera pas —
                        l’accorder à quelqu’un ne lui ouvrira donc rien d’ici là.
                    </span>
                </p>
            </form>

            <template #footer>
                <Button type="button" variant="outline" :disabled="createForm.processing" @click="creating = false">Annuler</Button>
                <Button type="button" variant="primary" :disabled="createForm.processing || ! nameIsValid || nameTaken || ! createForm.label.trim()" @click="submitCreate">
                    <Check class="h-4 w-4" />{{ createForm.processing ? 'Création…' : 'Créer la permission' }}
                </Button>
            </template>
        </Dialog>

        <!-- Corriger le libellé -->
        <Dialog
            :open="editing !== null"
            :title="`Libellé de « ${editing?.name ?? ''} »`"
            description="Le nom ne change jamais : le code l’écrit en clair, et une route pointerait vers un nom disparu."
            :dismissible="! editForm.processing"
            @update:open="editing = $event ? editing : null"
        >
            <FormField label="Libellé" :error="editForm.errors.label">
                <Input v-model="editForm.label" autocomplete="off" />
            </FormField>

            <template #footer>
                <Button type="button" variant="outline" :disabled="editForm.processing" @click="editing = null">Annuler</Button>
                <Button type="button" variant="primary" :disabled="editForm.processing" @click="submitEdit">
                    <Check class="h-4 w-4" />{{ editForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                </Button>
            </template>
        </Dialog>

        <!-- Retirer -->
        <Dialog
            :open="removing !== null"
            :title="`Retirer « ${removing?.name ?? ''} » du catalogue ?`"
            description="Le retrait est définitif. Il n’est proposé que pour une permission que rien ne vérifie et que personne ne détient."
            :dismissible="! removeForm.processing"
            @update:open="removing = $event ? removing : null"
        >
            <p class="text-sm leading-5 text-muted-foreground">
                <strong class="text-foreground">{{ removing ? permissionLabel(removing) : '' }}</strong>
                <span class="mt-1 block font-mono text-[11px]">{{ removing?.name }}</span>
            </p>
            <p v-if="removing" class="mt-3 text-xs text-muted-foreground">
                Elle n’est vérifiée nulle part et n’est accordée à aucun rôle ni compte : son retrait ne change l’accès de personne.
            </p>

            <template #footer>
                <Button type="button" variant="outline" :disabled="removeForm.processing" @click="removing = null">Annuler</Button>
                <Button type="button" variant="destructive" :disabled="removeForm.processing" @click="submitRemove">
                    {{ removeForm.processing ? 'Retrait…' : 'Retirer du catalogue' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
