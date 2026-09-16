<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArchiveRestore,
    Check,
    Pencil,
    Plus,
    Info,
    Server,
    ShieldCheck,
    Trash2,
    TriangleAlert,
    UserCog,
    KeyRound,
    Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import RoleBaselineEditor from '@/Components/Rbac/RoleBaselineEditor.vue';
import UserPermissionOverrides from '@/Components/Rbac/UserPermissionOverrides.vue';
import PermissionCatalog from '@/Components/Rbac/PermissionCatalog.vue';
import ErrorBoundary from '@/Components/UI/ErrorBoundary.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

/**
 * « Rôles & permissions » — le référentiel des rôles d'un site, leur socle,
 * et les exceptions accordées compte par compte.
 *
 * Cet écran et « Utilisateurs » n'en faisaient qu'un. Deux gestes de portée
 * très différente s'y croisaient : créer un compte touche une personne,
 * modifier un socle touche tous ceux qui exercent ce métier. Ils sont
 * séparés (ADR-100), sans rien changer à la résolution des droits :
 *
 *     DENY individuel  >  ALLOW individuel  >  socle du rôle
 *
 * Le portail n'écrit jamais dans une base clinique : chaque commande part
 * vers l'API du site choisi, qui revérifie la permission et audite l'acteur
 * distant (ADR-004, ADR-027).
 */
const props = defineProps({
    sites: { type: Array, default: () => [] },
});

const { can } = usePermissions();

const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const tab = ref('baselines');
const baselineDirty = ref(false);
const overridesDirty = ref(false);

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value) ?? props.sites[0]);
const allRoles = computed(() => selectedSite.value?.data?.roles ?? []);
const users = computed(() => selectedSite.value?.data?.users ?? []);
const permissionCatalog = computed(() => selectedSite.value?.data?.permission_catalog ?? []);

/**
 * Le rôle Super Admin n'est jamais réglé ici : il reçoit automatiquement
 * toutes les permissions sur le portail et aucune sur un site (ADR-025,
 * ADR-027). Un rôle archivé n'est plus affectable, mais reste lisible.
 */
const editableRoles = computed(() => allRoles.value.filter((role) => ! role.protected && ! role.archived));
const archivedRoles = computed(() => allRoles.value.filter((role) => role.archived));

const canCreateRole = computed(() => can('roles.create'));
const canUpdateRole = computed(() => can('roles.update'));
const canArchiveRole = computed(() => can('roles.archive'));
const canRestoreRole = computed(() => can('roles.restore'));
const canManageBaselines = computed(() => can('users.manage'));
const canAssignPermissions = computed(() => can('permissions.assign'));
const permissionCatalogAbilities = computed(() => ({
    create: can('permissions.create'),
    update: can('permissions.update'),
    remove: can('permissions.delete'),
}));

/**
 * Quatre gestes, du plus large au plus étroit. L'ordre n'est pas décoratif :
 * il dit qui est touché, et c'est la seule chose qu'on doit comprendre avant
 * de cliquer sur cet écran.
 */
const tabs = computed(() => [
    {
        value: 'baselines',
        label: 'Socle des rôles',
        icon: ShieldCheck,
        count: `${editableRoles.value.length} rôle${editableRoles.value.length > 1 ? 's' : ''}`,
        hint: 'Ce que reçoit automatiquement tout compte du métier',
        scope: `Une modification ici s’applique à **tous** les comptes du rôle choisi sur ${selectedSite.value?.site.name ?? 'ce site'}, y compris ceux créés plus tard.`,
        dirty: baselineDirty.value,
    },
    {
        value: 'accounts',
        label: 'Exceptions par compte',
        icon: UserCog,
        count: `${users.value.length} compte${users.value.length > 1 ? 's' : ''}`,
        hint: 'Un écart pour une seule personne',
        scope: 'Une exception ne concerne que le compte choisi. Une interdiction individuelle l’emporte sur le socle de son rôle ; une autorisation s’y ajoute.',
        dirty: overridesDirty.value,
    },
    {
        value: 'roles',
        label: 'Rôles du site',
        icon: Users,
        count: archivedRoles.value.length
            ? `${editableRoles.value.length} actifs · ${archivedRoles.value.length} archivé${archivedRoles.value.length > 1 ? 's' : ''}`
            : `${editableRoles.value.length} actif${editableRoles.value.length > 1 ? 's' : ''}`,
        hint: 'Créer, renommer, archiver un métier',
        scope: 'Un rôle encore porté par un compte ne s’archive pas : ses titulaires perdraient tout leur socle d’un coup.',
        dirty: false,
    },
    {
        value: 'permissions',
        label: 'Catalogue des droits',
        icon: KeyRound,
        count: `${permissionCatalog.value.length} droit${permissionCatalog.value.length > 1 ? 's' : ''}`,
        hint: 'Les mots que l’application sait vérifier',
        scope: 'Créer une permission crée le mot, pas le contrôle : elle reste « pas encore vérifiée » tant qu’aucune route, règle serveur ou écran ne l’utilise.',
        dirty: false,
    },
]);

const currentTab = computed(() => tabs.value.find((item) => item.value === tab.value) ?? tabs.value[0]);

/** `**gras**` dans les phrases de portée : un seul mot à mettre en avant. */
const scopeParts = computed(() => currentTab.value.scope.split(/\*\*(.+?)\*\*/));

const selectSite = (code) => {
    if (code === selectedSiteCode.value) return;
    selectedSiteCode.value = code;
};

/* ------------------------------------------------------------------ */
/* Socle d'un rôle (ADR-064)                                          */
/* ------------------------------------------------------------------ */

const baselineForm = useForm({ permission_ids: [] });

const saveBaseline = ({ role, permissionIds }) => {
    baselineForm.permission_ids = permissionIds;
    baselineForm.put(`/super-admin/workspaces/roles/${selectedSiteCode.value}/permissions/${role.code}`, {
        preserveScroll: true,
    });
};

/* ------------------------------------------------------------------ */
/* Exceptions individuelles (ADR-022, ADR-033)                        */
/* ------------------------------------------------------------------ */

const overridesForm = useForm({ permission_overrides: [] });

const saveOverrides = ({ user, overrides }) => {
    overridesForm.permission_overrides = overrides;
    overridesForm.put(
        `/super-admin/workspaces/roles/${selectedSiteCode.value}/accounts/${user.uuid}/permissions`,
        { preserveScroll: true },
    );
};

/* ------------------------------------------------------------------ */
/* Référentiel des rôles (ADR-100)                                    */
/* ------------------------------------------------------------------ */

const creating = ref(false);
const createForm = useForm({ site_code: '', code: '', name: '', permission_ids: [] });
/** « Partir du socle de … » : la liste envoyée est celle réellement copiée. */
const copyFrom = ref('');

const copyOptions = computed(() => [
    { value: '', label: 'Aucune permission pour commencer' },
    ...editableRoles.value.map((role) => ({
        value: role.code,
        label: `Reprendre le socle de ${role.name} (${role.permissions.length})`,
    })),
]);

watch(copyFrom, (code) => {
    const source = allRoles.value.find((role) => role.code === code);

    createForm.permission_ids = source
        ? permissionCatalog.value
            .filter((permission) => source.permissions.includes(permission.name))
            .map((permission) => permission.id)
        : [];
});

const openCreate = () => {
    createForm.reset();
    createForm.clearErrors();
    copyFrom.value = '';
    createForm.site_code = selectedSiteCode.value;
    creating.value = true;
};

const submitCreate = () => createForm.post('/super-admin/workspaces/roles', {
    preserveScroll: true,
    onSuccess: () => { creating.value = false; },
});

const renaming = ref(null);
const renameForm = useForm({ name: '' });

const openRename = (role) => {
    renameForm.reset();
    renameForm.clearErrors();
    renameForm.name = role.name;
    renaming.value = role;
};

const submitRename = () => renameForm.put(
    `/super-admin/workspaces/roles/${selectedSiteCode.value}/${renaming.value.code}`,
    { preserveScroll: true, onSuccess: () => { renaming.value = null; } },
);

const archiving = ref(null);
const archiveForm = useForm({ reason: '' });

const openArchive = (role) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiving.value = role;
};

const submitArchive = () => archiveForm.delete(
    `/super-admin/workspaces/roles/${selectedSiteCode.value}/${archiving.value.code}`,
    { preserveScroll: true, onSuccess: () => { archiving.value = null; } },
);

const restore = (role) => router.post(
    `/super-admin/workspaces/roles/${selectedSiteCode.value}/${role.code}/restore`,
    {},
    { preserveScroll: true },
);

const codeHint = 'Majuscules, sans accent ni espace : lettres, chiffres et « _ ». Il ne change plus ensuite.';
</script>

<template>
    <Head title="Rôles & permissions" />

    <div class="w-full space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Super Administration</p>
                <h1 class="mt-0.5 font-heading text-2xl font-bold tracking-tight text-foreground">Rôles &amp; permissions</h1>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Le socle d’un rôle s’applique à tous ses comptes ; une exception ne concerne qu’une personne.
                    Une interdiction individuelle l’emporte toujours. Les comptes eux-mêmes se créent dans
                    <a class="font-semibold text-primary hover:underline" href="/super-admin/workspaces/users">Utilisateurs</a>.
                </p>
            </div>
            <Button
                v-if="canCreateRole && selectedSite?.ok"
                type="button"
                variant="primary"
                @click="openCreate"
            >
                <Plus class="h-4 w-4" />Nouveau rôle
            </Button>
        </div>

        <Card class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border px-4 py-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="shrink-0 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Site</span>
                    <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded-lg bg-muted p-1">
                    <button
                        v-for="site in sites"
                        :key="site.site.code"
                        type="button"
                        :class="cn(
                            'inline-flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-xs font-bold transition-colors',
                            site.site.code === selectedSiteCode ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                        )"
                        :aria-current="site.site.code === selectedSiteCode ? 'true' : undefined"
                        @click="selectSite(site.site.code)"
                    >
                        <Server class="h-3.5 w-3.5" />{{ site.site.name }}
                        <Badge v-if="! site.ok" variant="destructive" class="px-1.5 py-0 text-[10px]">Hors ligne</Badge>
                    </button>
                    </div>
                </div>

                <p v-if="selectedSite?.ok" class="shrink-0 text-xs text-muted-foreground">
                    Chaque site a ses propres rôles et son propre catalogue. Rien n’est écrit ici : tout part vers l’API du site.
                </p>
            </div>

            <p v-if="! selectedSite?.ok" class="px-4 py-10 text-center text-sm text-muted-foreground">
                {{ selectedSite?.message ?? 'Ce site est injoignable.' }} Aucun rôle ne peut être lu ni modifié tant que son API ne répond pas.
            </p>

            <!-- Quatre gestes, du plus large au plus étroit. Une pastille
                 ne dit ni ce qu'on va toucher, ni combien : la carte porte
                 le compte, et le bandeau juste en dessous dit qui est
                 concerné — avant le clic, pas après. -->
            <nav v-else class="grid gap-px bg-border sm:grid-cols-2 xl:grid-cols-4" aria-label="Sections">
                <button
                    v-for="item in tabs"
                    :key="item.value"
                    type="button"
                    :class="cn(
                        'flex items-start gap-3 bg-card p-4 text-start transition-colors',
                        tab === item.value ? 'bg-primary/5' : 'hover:bg-accent/60',
                    )"
                    :aria-current="tab === item.value ? 'true' : undefined"
                    @click="tab = item.value"
                >
                    <span :class="cn(
                        'grid h-10 w-10 shrink-0 place-items-center rounded-lg',
                        tab === item.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                    )">
                        <component :is="item.icon" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span :class="cn('truncate text-sm font-bold', tab === item.value ? 'text-primary' : 'text-foreground')">{{ item.label }}</span>
                            <span v-if="item.dirty" class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" title="Modifications non enregistrées" />
                        </span>
                        <span class="mt-0.5 block truncate text-[11px] font-semibold tabular-nums text-muted-foreground">{{ item.count }}</span>
                        <span class="mt-0.5 block text-[11px] leading-4 text-muted-foreground">{{ item.hint }}</span>
                    </span>
                </button>
            </nav>

            <!-- Qui est touché par ce qu'on s'apprête à faire. C'est la
                 seule chose à comprendre avant de cliquer ici. -->
            <p v-if="selectedSite?.ok" class="flex items-start gap-2.5 border-t border-border bg-muted/40 px-4 py-3 text-xs leading-5 text-muted-foreground">
                <Info class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    <template v-for="(part, index) in scopeParts" :key="index">
                        <strong v-if="index % 2" class="text-foreground">{{ part }}</strong>
                        <template v-else>{{ part }}</template>
                    </template>
                </span>
            </p>
        </Card>

        <!-- Une section qui tombe ne doit pas emporter l'écran : sans cette
             garde, un rendu interrompu laissait la zone de contenu vide et
             chaque changement d'onglet échouait ensuite à démonter l'enfant
             à moitié monté — la page paraissait figée, sans un mot. La clé
             par onglet redonne sa chance à la section suivante. -->
        <ErrorBoundary v-if="selectedSite?.ok" :key="`${selectedSiteCode}-${tab}`" :section="currentTab.label">
            <RoleBaselineEditor
                v-if="tab === 'baselines'"
                :roles="editableRoles"
                :permission-catalog="permissionCatalog"
                :site-name="selectedSite.site.name"
                :processing="baselineForm.processing"
                :errors="baselineForm.errors"
                @save="saveBaseline"
                @update:dirty="baselineDirty = $event"
            />

            <UserPermissionOverrides
                v-else-if="tab === 'accounts'"
                :users="users"
                :roles="allRoles"
                :permission-catalog="permissionCatalog"
                :site-name="selectedSite.site.name"
                :can-assign="canAssignPermissions"
                :processing="overridesForm.processing"
                :errors="overridesForm.errors"
                @save="saveOverrides"
                @update:dirty="overridesDirty = $event"
            />

            <PermissionCatalog
                v-else-if="tab === 'permissions'"
                :permissions="permissionCatalog"
                :site-code="selectedSiteCode"
                :site-name="selectedSite.site.name"
                :can="permissionCatalogAbilities"
            />

            <template v-else>
                <Card class="overflow-hidden">
                    <div class="border-b border-border px-4 py-3">
                        <h2 class="text-sm font-bold text-foreground">Rôles de {{ selectedSite.site.name }}</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Un rôle porté par des comptes ne s’archive pas : ses titulaires perdraient tout leur socle.
                            Réaffectez-les d’abord.
                        </p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/60 text-start text-[11px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2.5 text-start font-bold">Rôle</th>
                                    <th class="px-4 py-2.5 text-start font-bold">Code</th>
                                    <th class="px-4 py-2.5 text-end font-bold">Permissions</th>
                                    <th class="px-4 py-2.5 text-end font-bold">Comptes</th>
                                    <th class="px-4 py-2.5 text-end font-bold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="role in allRoles" :key="role.code" :class="role.archived ? 'bg-muted/30' : ''">
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="font-semibold text-foreground">{{ role.name }}</span>
                                            <Badge v-if="role.protected" variant="secondary" class="px-1.5 py-0 text-[10px]">Architecture</Badge>
                                            <Badge v-if="role.archived" variant="outline" class="px-1.5 py-0 text-[10px]">Archivé</Badge>
                                        </div>
                                        <p v-if="role.archived && role.archive_reason" class="mt-0.5 text-[11px] text-muted-foreground">{{ role.archive_reason }}</p>
                                        <p v-else-if="role.profiles.length" class="mt-0.5 text-[11px] text-muted-foreground">
                                            {{ role.profiles.map((profile) => profile.name).join(' · ') }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ role.code }}</td>
                                    <td class="px-4 py-3 text-end tabular-nums text-foreground">{{ role.permissions.length }}</td>
                                    <td class="px-4 py-3 text-end tabular-nums text-foreground">{{ role.users_count }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-1">
                                            <template v-if="role.archived">
                                                <Button v-if="canRestoreRole" type="button" size="sm" variant="outline" @click="restore(role)">
                                                    <ArchiveRestore class="h-4 w-4" />Restaurer
                                                </Button>
                                            </template>
                                            <template v-else-if="! role.protected">
                                                <Button v-if="canManageBaselines" type="button" size="sm" variant="ghost" title="Régler le socle" @click="tab = 'baselines'">
                                                    <ShieldCheck class="h-4 w-4" />
                                                </Button>
                                                <Button v-if="canUpdateRole" type="button" size="sm" variant="ghost" title="Renommer" @click="openRename(role)">
                                                    <Pencil class="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    v-if="canArchiveRole"
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    :disabled="role.users_count > 0"
                                                    :title="role.users_count > 0 ? `${role.users_count} compte(s) portent encore ce rôle` : 'Archiver'"
                                                    @click="openArchive(role)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                </Button>
                                            </template>
                                            <span v-else class="text-[11px] text-muted-foreground">Non modifiable</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <p v-if="archivedRoles.length" class="text-xs text-muted-foreground">
                    {{ archivedRoles.length }} rôle{{ archivedRoles.length > 1 ? 's' : '' }} archivé{{ archivedRoles.length > 1 ? 's' : '' }} :
                    conservé{{ archivedRoles.length > 1 ? 's' : '' }} avec son socle, restaurable{{ archivedRoles.length > 1 ? 's' : '' }} tel quel.
                </p>
            </template>
        </ErrorBoundary>

        <!-- Créer un rôle -->
        <Dialog
            :open="creating"
            size="lg"
            title="Nouveau rôle"
            description="Le rôle est créé dans la base du site choisi, via son API."
            :dismissible="! createForm.processing"
            @update:open="creating = $event"
        >
            <form class="space-y-4" @submit.prevent="submitCreate">
                <FormError v-if="createForm.errors.site_code">{{ createForm.errors.site_code }}</FormError>

                <FormField label="Site" :error="createForm.errors.site_code">
                    <Select
                        v-model="createForm.site_code"
                        :options="sites.filter((site) => site.ok).map((site) => ({ value: site.site.code, label: site.site.name }))"
                    />
                </FormField>

                <FormField label="Code" :error="createForm.errors.code" :hint="codeHint">
                    <Input v-model="createForm.code" placeholder="KINESITHERAPEUTE" autocomplete="off" />
                </FormField>

                <FormField label="Libellé" :error="createForm.errors.name">
                    <Input v-model="createForm.name" placeholder="Kinésithérapeute" autocomplete="off" />
                </FormField>

                <FormField
                    label="Socle de départ"
                    :error="createForm.errors.permission_ids"
                    hint="Le socle se règle ensuite en détail. Reprendre celui d’un rôle existant copie ses permissions au moment du clic, sans lien entre les deux rôles ensuite."
                >
                    <Select v-model="copyFrom" :options="copyOptions" />
                </FormField>

                <p class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                    {{ createForm.permission_ids.length }} permission{{ createForm.permission_ids.length > 1 ? 's' : '' }} seront accordée{{ createForm.permission_ids.length > 1 ? 's' : '' }} à la création.
                </p>
            </form>

            <template #footer>
                <Button type="button" variant="outline" :disabled="createForm.processing" @click="creating = false">Annuler</Button>
                <Button type="button" variant="primary" :disabled="createForm.processing" @click="submitCreate">
                    <Check class="h-4 w-4" />{{ createForm.processing ? 'Création…' : 'Créer le rôle' }}
                </Button>
            </template>
        </Dialog>

        <!-- Renommer -->
        <Dialog
            :open="renaming !== null"
            :title="`Renommer « ${renaming?.name ?? ''} »`"
            description="Le code du rôle ne change jamais : il est l’identité que les socles et l’audit désignent."
            :dismissible="! renameForm.processing"
            @update:open="renaming = $event ? renaming : null"
        >
            <FormField label="Libellé" :error="renameForm.errors.name">
                <Input v-model="renameForm.name" autocomplete="off" />
            </FormField>

            <template #footer>
                <Button type="button" variant="outline" :disabled="renameForm.processing" @click="renaming = null">Annuler</Button>
                <Button type="button" variant="primary" :disabled="renameForm.processing" @click="submitRename">
                    <Check class="h-4 w-4" />{{ renameForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                </Button>
            </template>
        </Dialog>

        <!-- Archiver -->
        <Dialog
            :open="archiving !== null"
            :title="`Archiver « ${archiving?.name ?? ''} » ?`"
            description="Le rôle quitte les affectations possibles. Il n’est jamais supprimé : son socle est conservé et il reste restaurable."
            :dismissible="! archiveForm.processing"
            @update:open="archiving = $event ? archiving : null"
        >
            <div class="space-y-3">
                <p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                    Un motif est obligatoire : c’est ce que lira la personne qui retrouvera ce rôle archivé dans six mois.
                </p>
                <FormField label="Motif" :error="archiveForm.errors.reason">
                    <Textarea v-model="archiveForm.reason" rows="3" placeholder="Métier repris par le rôle NURSE depuis le 01/09." />
                </FormField>
            </div>

            <template #footer>
                <Button type="button" variant="outline" :disabled="archiveForm.processing" @click="archiving = null">Annuler</Button>
                <Button type="button" variant="destructive" :disabled="archiveForm.processing" @click="submitArchive">
                    {{ archiveForm.processing ? 'Archivage…' : 'Archiver le rôle' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
