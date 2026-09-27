<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    CircleHelp,
    KeyRound,
    Server,
    ShieldCheck,
    TriangleAlert,
    UserCog,
    WifiOff,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Popover from '@/Components/Shadcn/Popover.vue';
import AccountDirectory from '@/Components/Rbac/AccountDirectory.vue';
import AccountWorkspace from '@/Components/Rbac/AccountWorkspace.vue';
import PermissionCatalog from '@/Components/Rbac/PermissionCatalog.vue';
import RoleCreatePanel from '@/Components/Rbac/RoleCreatePanel.vue';
import RoleDirectory from '@/Components/Rbac/RoleDirectory.vue';
import RoleWorkspace from '@/Components/Rbac/RoleWorkspace.vue';
import ErrorBoundary from '@/Components/UI/ErrorBoundary.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { buildPermissionModules } from '@/utilities/permissionWorkspace';
import { roleInitials } from '@/utilities/roleDescriptions';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

/**
 * « Rôles & permissions » — le centre de gestion des droits d'un site
 * (ADR-100, ADR-178).
 *
 * L'écran précédent réglait un rôle en cinq gestes : choisir un onglet parmi
 * quatre grandes cartes, ouvrir une fenêtre pour changer de rôle, choisir une
 * catégorie parmi quatre-vingt-six dans un rail, cocher, puis chercher la
 * barre d'enregistrement. Il n'affichait qu'une catégorie à la fois.
 *
 * Désormais : les rôles à gauche, toujours visibles et cherchables ; à droite,
 * le rôle choisi, son résumé, et ses droits rangés en quatorze modules
 * — Pharmacie, Chirurgie, Caisse — dont chacun se lit comme une grille
 * « Voir | Créer | Modifier | Supprimer… ». Les exceptions d'un compte et le
 * catalogue des droits restent à un onglet, avec la même allure.
 *
 * Rien ne change à la résolution des droits ni aux routes :
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
const page = usePage();

/* ------------------------------------------------------------------ */
/* État initial, lu dans l'adresse                                     */
/* ------------------------------------------------------------------ */

/**
 * `?site=A&vue=comptes&compte=…` : une actualisation, un lien partagé ou le
 * retour d'un enregistrement rouvrent exactement ce qu'on regardait. L'adresse
 * est lue depuis la page Inertia — la même au rendu serveur et dans le
 * navigateur, donc sans écart d'hydratation.
 */
const VIEWS = { roles: 'roles', comptes: 'accounts', catalogue: 'catalog' };
const SLUGS = { roles: 'roles', accounts: 'comptes', catalog: 'catalogue' };

const initialQuery = new URLSearchParams(String(page.url ?? '').split('?')[1] ?? '');
const firstReachableSite = props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code ?? '';
const requestedSite = initialQuery.get('site');

const selectedSiteCode = ref(props.sites.some((site) => site.site.code === requestedSite) ? requestedSite : firstReachableSite);
const view = ref(VIEWS[initialQuery.get('vue')] ?? 'roles');
const selectedRoleCode = ref(String(initialQuery.get('role') ?? '').toUpperCase());
const selectedUserUuid = ref(initialQuery.get('compte') ?? '');
const creating = ref(false);
const accountRoleFilter = ref('');

/** Le brouillon de l'espace ouvert (socle ou exceptions) porte des modifications. */
const dirty = ref(false);
/** Combien, pour le dire dans la confirmation plutôt que « des modifications ». */
const pendingChanges = ref(0);

/** Recherche, filtre et modules ouverts : gardés d'un rôle à l'autre pour comparer. */
const roleSearch = ref('');
const roleFilter = ref('all');
const roleExpanded = ref([]);
const accountSearch = ref('');
const accountFilter = ref('all');
const accountExpanded = ref([]);

/* ------------------------------------------------------------------ */
/* Données du site choisi                                              */
/* ------------------------------------------------------------------ */

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value) ?? props.sites[0]);
const siteData = computed(() => selectedSite.value?.data ?? {});
const roles = computed(() => siteData.value.roles ?? []);
const users = computed(() => siteData.value.users ?? []);
const catalog = computed(() => siteData.value.permission_catalog ?? []);
const modules = computed(() => buildPermissionModules(catalog.value));

const editableRoles = computed(() => roles.value.filter((role) => ! role.protected && ! role.archived));

const activeRole = computed(() => roles.value.find((role) => role.code === selectedRoleCode.value)
    ?? editableRoles.value[0]
    ?? roles.value[0]
    ?? null);

const activeUser = computed(() => users.value.find((user) => user.uuid === selectedUserUuid.value)
    ?? users.value.find((user) => ! accountRoleFilter.value || user.role?.code === accountRoleFilter.value)
    ?? users.value[0]
    ?? null);

const activeUserRole = computed(() => roles.value.find((role) => role.code === activeUser.value?.role?.code) ?? null);

/* ------------------------------------------------------------------ */
/* Droits de l'administrateur connecté                                 */
/* ------------------------------------------------------------------ */

const canCreateRole = computed(() => can('roles.create'));
const canAssignPermissions = computed(() => can('permissions.assign'));
const roleAbilities = computed(() => ({
    edit: can('users.manage'),
    reset: can('users.manage'),
    rename: can('roles.update'),
    archive: can('roles.archive'),
    restore: can('roles.restore'),
}));
const permissionCatalogAbilities = computed(() => ({
    create: can('permissions.create'),
    update: can('permissions.update'),
    remove: can('permissions.delete'),
}));

/* ------------------------------------------------------------------ */
/* Sections                                                            */
/* ------------------------------------------------------------------ */

const tabs = computed(() => [
    { value: 'roles', label: 'Rôles', icon: ShieldCheck, count: editableRoles.value.length, hint: 'Ce que reçoit tout compte du métier' },
    { value: 'accounts', label: 'Exceptions par compte', icon: UserCog, count: users.value.length, hint: 'Un écart pour une seule personne' },
    { value: 'catalog', label: 'Catalogue des droits', icon: KeyRound, count: catalog.value.length, hint: 'Les mots que l’application sait vérifier' },
]);

const currentTab = computed(() => tabs.value.find((tab) => tab.value === view.value) ?? tabs.value[0]);

/* ------------------------------------------------------------------ */
/* Changer d'objet sans perdre un brouillon                            */
/* ------------------------------------------------------------------ */

/**
 * Changer de rôle, de compte, de section ou de site recrée l'espace de
 * travail : un brouillon non enregistré y serait perdu sans un mot. On le dit
 * avant — c'est la seule confirmation d'une simple navigation.
 */
const pendingSwitch = ref(null);

const guarded = (target, run) => {
    if (! dirty.value) {
        run();

        return;
    }

    pendingSwitch.value = { target, run };
};

const confirmSwitch = () => {
    const next = pendingSwitch.value;

    pendingSwitch.value = null;
    dirty.value = false;
    pendingChanges.value = 0;
    next?.run();
};

const directoryOpen = ref(false);

const selectSite = (code) => {
    if (code === selectedSiteCode.value) return;

    guarded(`le site ${props.sites.find((site) => site.site.code === code)?.site.name ?? code}`, () => {
        selectedSiteCode.value = code;
        selectedRoleCode.value = '';
        selectedUserUuid.value = '';
        accountRoleFilter.value = '';
        creating.value = false;
    });
};

const selectView = (value) => {
    if (value === view.value && ! creating.value) return;

    guarded(`« ${tabs.value.find((tab) => tab.value === value)?.label} »`, () => {
        view.value = value;
        creating.value = false;
    });
};

const selectRole = (code) => {
    directoryOpen.value = false;

    if (code === activeRole.value?.code && ! creating.value) return;

    const role = roles.value.find((item) => item.code === code);

    guarded(`le rôle « ${role?.name ?? code} »`, () => {
        creating.value = false;
        selectedRoleCode.value = code;
        if (roleFilter.value === 'changed') roleFilter.value = 'all';
    });
};

const startCreate = () => {
    directoryOpen.value = false;

    if (creating.value) return;

    guarded('la création d’un rôle', () => { creating.value = true; });
};

const onRoleCreated = (code) => {
    creating.value = false;
    selectedRoleCode.value = code;
};

const selectUser = (uuid) => {
    directoryOpen.value = false;

    if (uuid === activeUser.value?.uuid) return;

    const user = users.value.find((item) => item.uuid === uuid);

    guarded(`le compte de ${user?.name ?? 'ce compte'}`, () => {
        selectedUserUuid.value = uuid;
        if (accountFilter.value === 'changed') accountFilter.value = 'all';
    });
};

/** D'un rôle à ses comptes : la liste s'ouvre filtrée sur ce rôle. */
const showAccountsOf = (role) => guarded('« Exceptions par compte »', () => {
    view.value = 'accounts';
    creating.value = false;
    accountRoleFilter.value = role.code;
    selectedUserUuid.value = users.value.find((user) => user.role?.code === role.code)?.uuid ?? selectedUserUuid.value;
});

/** D'un compte au socle de son rôle. */
const showRole = (code) => guarded('le socle du rôle', () => {
    view.value = 'roles';
    creating.value = false;
    selectedRoleCode.value = code;
});

/* ------------------------------------------------------------------ */
/* Quitter la page                                                     */
/* ------------------------------------------------------------------ */

const leaveGuard = useUnsavedChangesGuard(dirty);

/* ------------------------------------------------------------------ */
/* L'adresse suit ce qu'on regarde                                     */
/* ------------------------------------------------------------------ */

const mounted = ref(false);

const currentUrl = () => {
    const params = new URLSearchParams();

    if (selectedSiteCode.value) params.set('site', selectedSiteCode.value);
    if (view.value !== 'roles') params.set('vue', SLUGS[view.value]);
    if (view.value === 'roles' && activeRole.value && ! creating.value) params.set('role', activeRole.value.code);
    if (view.value === 'accounts' && activeUser.value) params.set('compte', activeUser.value.uuid);

    const query = params.toString();

    return `${window.location.pathname}${query ? `?${query}` : ''}`;
};

/**
 * Une visite côté client, sans requête : Inertia met l'adresse et son
 * historique à jour sans recharger la page ni recréer ses composants.
 */
const syncUrl = () => {
    if (! mounted.value) return;

    const url = currentUrl();

    if (url === `${window.location.pathname}${window.location.search}`) return;

    router.replace({ url, preserveState: true, preserveScroll: true });
};

onMounted(() => {
    mounted.value = true;
    syncUrl();
});

watch(
    () => [selectedSiteCode.value, view.value, activeRole.value?.code, activeUser.value?.uuid, creating.value],
    syncUrl,
);
</script>

<template>
    <Head title="Rôles & permissions" />

    <div class="w-full space-y-5">
        <header>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Super Administration</p>
                <h1 class="mt-0.5 font-heading text-2xl font-bold tracking-tight text-foreground">Rôles &amp; permissions</h1>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Définissez ce que chaque métier peut faire sur un site, puis, si besoin, l’écart d’un compte précis.
                    Les comptes se créent dans
                    <a class="font-semibold text-primary hover:underline" href="/super-admin/workspaces/users">Utilisateurs</a>.
                    <Popover width-class="w-[min(24rem,calc(100vw-2rem))]" align="start">
                        <template #trigger>
                            <button type="button" class="ms-1 inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                                <CircleHelp class="h-3.5 w-3.5" />Comment les droits s’appliquent
                            </button>
                        </template>
                        <div class="space-y-3 p-4 text-sm">
                            <p class="font-semibold text-foreground">Pour chaque permission, dans cet ordre :</p>
                            <ol class="space-y-2">
                                <li class="flex gap-2.5">
                                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-destructive text-[11px] font-bold text-destructive-foreground">1</span>
                                    <span><strong class="text-foreground">Interdiction individuelle</strong> — l’emporte toujours, même si le rôle accorde le droit.</span>
                                </li>
                                <li class="flex gap-2.5">
                                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-600 text-[11px] font-bold text-white">2</span>
                                    <span><strong class="text-foreground">Autorisation individuelle</strong> — s’ajoute à ce que le rôle accorde.</span>
                                </li>
                                <li class="flex gap-2.5">
                                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">3</span>
                                    <span><strong class="text-foreground">Socle du rôle</strong> — ce que reçoit tout compte du métier, y compris ceux créés plus tard.</span>
                                </li>
                            </ol>
                            <p class="border-t border-border pt-3 text-xs text-muted-foreground">
                                Rien n’est écrit dans le portail : chaque enregistrement part vers l’API du site choisi, qui revérifie le droit et l’audite.
                            </p>
                        </div>
                    </Popover>
                </p>
            </div>
        </header>

        <!-- Les sections à gauche, le site à droite : on sait toujours quel
             site on règle sans que son choix repousse le travail plus bas. -->
        <div class="flex flex-col-reverse gap-2 border-b border-border lg:flex-row lg:items-end lg:justify-between">
            <nav class="-mb-px flex min-w-0 gap-1 overflow-x-auto" aria-label="Sections">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    :class="cn(
                        'inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                        view === tab.value ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                    )"
                    :aria-current="view === tab.value ? 'page' : undefined"
                    :title="tab.hint"
                    @click="selectView(tab.value)"
                >
                    <component :is="tab.icon" class="h-4 w-4" />
                    {{ tab.label }}
                    <span
                        v-if="selectedSite?.ok"
                        :class="cn('rounded-full px-2 py-px text-[11px] tabular-nums', view === tab.value ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground')"
                    >{{ tab.count }}</span>
                    <span v-if="dirty && view === tab.value" class="h-2 w-2 rounded-full bg-amber-500" title="Modifications non enregistrées" />
                </button>
            </nav>
            <div class="flex min-w-0 items-center gap-2.5 pb-2 lg:pb-1.5">
                <span class="shrink-0 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Site</span>
                <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded-lg bg-muted p-1" role="group" aria-label="Site">
                    <button
                        v-for="site in sites"
                        :key="site.site.code"
                        type="button"
                        :class="cn(
                            'inline-flex shrink-0 items-center gap-2 rounded-md px-3 py-1.5 text-xs font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            site.site.code === selectedSiteCode ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                        )"
                        :aria-pressed="site.site.code === selectedSiteCode"
                        @click="selectSite(site.site.code)"
                    >
                        <Server v-if="site.ok" class="h-3.5 w-3.5" />
                        <WifiOff v-else class="h-3.5 w-3.5 text-destructive" />
                        {{ site.site.name }}
                        <Badge v-if="! site.ok" variant="destructive" class="px-1.5 py-0 text-[10px]">Hors ligne</Badge>
                    </button>
                </div>
            </div>
        </div>

        <Card v-if="! selectedSite?.ok" class="px-6 py-14 text-center">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-red-50 text-destructive dark:bg-red-950/40" aria-hidden="true">
                <WifiOff class="h-5 w-5" />
            </span>
            <p class="mt-3 text-sm font-bold text-foreground">{{ selectedSite?.site?.name ?? 'Ce site' }} est injoignable</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                {{ selectedSite?.message ?? 'Son API ne répond pas.' }} Aucun rôle ne peut être lu ni modifié tant que son API ne répond pas.
            </p>
        </Card>

        <!-- Une section qui tombe ne doit pas emporter l'écran : sans cette
             garde, un rendu interrompu laissait la zone de contenu vide et
             chaque changement de section échouait ensuite à démonter l'enfant
             à moitié monté. La clé par section redonne sa chance à la suivante. -->
        <ErrorBoundary v-else :key="`${selectedSiteCode}-${view}`" :section="currentTab.label">
            <div v-if="view === 'roles'" class="grid gap-5 lg:grid-cols-[16.5rem_minmax(0,1fr)] xl:grid-cols-[18rem_minmax(0,1fr)]">
                <aside class="hidden lg:sticky lg:top-20 lg:block lg:h-[calc(100vh-6rem)] lg:self-start">
                    <RoleDirectory
                        :roles="roles"
                        :selected-code="activeRole?.code ?? ''"
                        :dirty-code="dirty ? activeRole?.code ?? '' : ''"
                        :creating="creating"
                        :can-create="canCreateRole"
                        :catalog-size="catalog.length"
                        @select="selectRole"
                        @create="startCreate"
                    />
                </aside>

                <div class="min-w-0 space-y-4">
                    <!-- Sous 1024 px, la liste passe dans un panneau : la grille
                         garde toute la largeur de la tablette. -->
                    <Popover
                        :open="directoryOpen"
                        align="start"
                        width-class="w-[min(24rem,calc(100vw-2rem))]"
                        @update:open="directoryOpen = $event"
                    >
                        <template #trigger>
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl border border-border bg-card px-3 py-2.5 text-start shadow-sm lg:hidden"
                            >
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary text-xs font-bold text-primary-foreground">{{ creating ? '+' : roleInitials(activeRole?.name) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Rôle</span>
                                    <span class="block truncate text-sm font-bold text-foreground">{{ creating ? 'Nouveau rôle' : activeRole?.name }}</span>
                                </span>
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary"><ArrowLeftRight class="h-4 w-4" />Changer</span>
                            </button>
                        </template>
                        <div class="h-[min(32rem,70vh)]">
                            <RoleDirectory
                                :roles="roles"
                                :selected-code="activeRole?.code ?? ''"
                                :dirty-code="dirty ? activeRole?.code ?? '' : ''"
                                :creating="creating"
                                :can-create="canCreateRole"
                                :catalog-size="catalog.length"
                                @select="selectRole"
                                @create="startCreate"
                            />
                        </div>
                    </Popover>

                    <RoleCreatePanel
                        v-if="creating"
                        :site-code="selectedSiteCode"
                        :site-name="selectedSite.site.name"
                        :roles="roles"
                        :catalog="catalog"
                        @created="onRoleCreated"
                        @cancel="creating = false"
                    />

                    <RoleWorkspace
                        v-else-if="activeRole"
                        :key="`${selectedSiteCode}:${activeRole.code}`"
                        v-model:search="roleSearch"
                        v-model:filter="roleFilter"
                        v-model:expanded="roleExpanded"
                        :site-code="selectedSiteCode"
                        :site-name="selectedSite.site.name"
                        :role="activeRole"
                        :modules="modules"
                        :catalog="catalog"
                        :users="users"
                        :abilities="roleAbilities"
                        @update:dirty="dirty = $event"
                        @update:pending="pendingChanges = $event"
                        @show-accounts="showAccountsOf(activeRole)"
                    />

                    <Card v-else class="px-6 py-14 text-center text-sm text-muted-foreground">Aucun rôle sur ce site.</Card>
                </div>
            </div>

            <div v-else-if="view === 'accounts'" class="grid gap-5 lg:grid-cols-[16.5rem_minmax(0,1fr)] xl:grid-cols-[18rem_minmax(0,1fr)]">
                <template v-if="users.length">
                    <aside class="hidden lg:sticky lg:top-20 lg:block lg:h-[calc(100vh-6rem)] lg:self-start">
                        <AccountDirectory
                            v-model:role-filter="accountRoleFilter"
                            :users="users"
                            :roles="roles"
                            :selected-uuid="activeUser?.uuid ?? ''"
                            :dirty-uuid="dirty ? activeUser?.uuid ?? '' : ''"
                            @select="selectUser"
                        />
                    </aside>

                    <div class="min-w-0 space-y-4">
                        <Popover
                            :open="directoryOpen"
                            align="start"
                            width-class="w-[min(24rem,calc(100vw-2rem))]"
                            @update:open="directoryOpen = $event"
                        >
                            <template #trigger>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-3 rounded-xl border border-border bg-card px-3 py-2.5 text-start shadow-sm lg:hidden"
                                >
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary text-xs font-bold text-primary-foreground">{{ roleInitials(activeUser?.name) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Compte</span>
                                        <span class="block truncate text-sm font-bold text-foreground">{{ activeUser?.name }}</span>
                                    </span>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary"><ArrowLeftRight class="h-4 w-4" />Changer</span>
                                </button>
                            </template>
                            <div class="h-[min(34rem,72vh)]">
                                <AccountDirectory
                                    v-model:role-filter="accountRoleFilter"
                                    :users="users"
                                    :roles="roles"
                                    :selected-uuid="activeUser?.uuid ?? ''"
                                    :dirty-uuid="dirty ? activeUser?.uuid ?? '' : ''"
                                    @select="selectUser"
                                />
                            </div>
                        </Popover>

                        <AccountWorkspace
                            v-if="activeUser"
                            :key="`${selectedSiteCode}:${activeUser.uuid}`"
                            v-model:search="accountSearch"
                            v-model:filter="accountFilter"
                            v-model:expanded="accountExpanded"
                            :site-code="selectedSiteCode"
                            :site-name="selectedSite.site.name"
                            :user="activeUser"
                            :role="activeUserRole"
                            :modules="modules"
                            :catalog="catalog"
                            :can-assign="canAssignPermissions"
                            @update:dirty="dirty = $event"
                            @update:pending="pendingChanges = $event"
                            @show-role="showRole"
                        />
                    </div>
                </template>

                <Card v-else class="px-6 py-14 text-center lg:col-span-2">
                    <UserCog class="mx-auto h-6 w-6 text-muted-foreground" aria-hidden="true" />
                    <p class="mt-2 text-sm font-semibold text-foreground">Aucun compte sur ce site</p>
                    <p class="mt-1 text-xs text-muted-foreground">Les exceptions se règlent sur un compte existant, créé depuis « Utilisateurs ».</p>
                </Card>
            </div>

            <PermissionCatalog
                v-else
                :permissions="catalog"
                :site-code="selectedSiteCode"
                :site-name="selectedSite.site.name"
                :can="permissionCatalogAbilities"
            />
        </ErrorBoundary>

        <ConfirmModal
            :open="pendingSwitch !== null"
            tone="warning"
            title="Abandonner vos modifications ?"
            :description="`Si vous ouvrez ${pendingSwitch?.target ?? 'autre chose'}, elles ne seront pas envoyées au site.`"
            confirm-label="Abandonner et continuer"
            cancel-label="Rester ici"
            @update:open="pendingSwitch = $event ? pendingSwitch : null"
            @confirm="confirmSwitch"
        >
            <template #confirm-icon><TriangleAlert class="h-4 w-4" /></template>
            <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-100">
                <strong class="tabular-nums">{{ pendingChanges }} modification{{ pendingChanges > 1 ? 's' : '' }}</strong>
                en attente. Les droits restent exactement tels qu’ils sont aujourd’hui sur {{ selectedSite?.site?.name }}.
            </p>
        </ConfirmModal>

        <ConfirmModal
            :open="leaveGuard.pendingVisit.value !== null"
            tone="warning"
            title="Quitter sans enregistrer ?"
            description="Les modifications de cette page n’ont pas été envoyées au site."
            confirm-label="Quitter sans enregistrer"
            cancel-label="Rester ici"
            @update:open="(open) => open || leaveGuard.stay()"
            @confirm="leaveGuard.leave"
        >
            <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-100">
                <strong class="tabular-nums">{{ pendingChanges }} modification{{ pendingChanges > 1 ? 's' : '' }}</strong>
                seront perdues : les droits resteront exactement tels qu’ils sont aujourd’hui.
            </p>
        </ConfirmModal>
    </div>
</template>
