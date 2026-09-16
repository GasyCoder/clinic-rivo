<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Briefcase,
    Check,
    CircleCheck,
    Eye,
    EyeOff,
    LayoutGrid,
    List,
    Lock,
    LockOpen,
    Mail,
    Pencil,
    Search,
    Server,
    ShieldCheck,
    Trash2,
    TriangleAlert,
    UserPlus,
    Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import FormError from '@/Components/UI/FormError.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const query = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? 'active');
const roleFilter = ref(props.filters.role ?? '');
const editingUser = ref(null);
const view = ref('list');
const listMode = ref('list');
const step = ref(1);
const maxStepReached = ref(1);
const deactivateTargets = ref([]);
const selectedUuids = ref(new Set());
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const steps = [
    { n: 1, label: 'Informations', icon: UserPlus },
    { n: 2, label: 'Rôle', icon: Briefcase },
];

const form = useForm({
    site_code: selectedSiteCode.value,
    name: '',
    email: '',
    role_id: '',
    professional_profile_id: '',
    password: '',
    password_confirmation: '',
});
const deactivationForm = useForm({ reason: '' });
const forceDeleteTargets = ref([]);
const forceDeleteConfirmText = ref('');
const forceDeleteForm = useForm({});

const canCreate = computed(() => can('users.create') && can('roles.assign'));
const canAssignPermissions = computed(() => can('permissions.assign'));
const canManageRoleBaselines = computed(() => can('users.manage'));
const isEditing = computed(() => editingUser.value !== null);

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const users = computed(() => selectedSite.value?.data?.users ?? []);
const roles = computed(() => selectedSite.value?.data?.roles ?? []);

// Selection is scoped to the visible, active users of the current site —
// switching site, or a manageable user disappearing after a page refresh
// (filters, deactivation elsewhere), never leaves a stale uuid selected.
const selectableUsers = computed(() => users.value.filter((user) => user.active && canManage(user)));
const selectedUsers = computed(() => selectableUsers.value.filter((user) => selectedUuids.value.has(user.uuid)));
const deletableSelectedUsers = computed(() => selectedUsers.value.filter((user) => user.deletable));
const allVisibleSelected = computed(() => selectableUsers.value.length > 0 && selectedUsers.value.length === selectableUsers.value.length);

const toggleUser = (uuid) => {
    const next = new Set(selectedUuids.value);
    if (next.has(uuid)) next.delete(uuid); else next.add(uuid);
    selectedUuids.value = next;
};
const toggleAllVisible = () => {
    selectedUuids.value = allVisibleSelected.value ? new Set() : new Set(selectableUsers.value.map((user) => user.uuid));
};
const clearSelection = () => { selectedUuids.value = new Set(); };

const selectedRole = computed(() => roles.value.find((role) => Number(role.id) === Number(form.role_id)) ?? null);
const selectedRoleProfiles = computed(() => selectedRole.value?.profiles ?? []);
const selectedProfile = computed(() => selectedRoleProfiles.value.find(
    (profile) => Number(profile.id) === Number(form.professional_profile_id),
) ?? null);
const profileChanged = computed(() => isEditing.value
    && Number(editingUser.value?.professional_profile?.id ?? 0) !== Number(form.professional_profile_id ?? 0));
const oldProfileName = computed(() => editingUser.value?.professional_profile?.name ?? 'Aucun profil');
const newProfileName = computed(() => selectedProfile.value?.name ?? 'Aucun profil');


const initials = (name) => {
    const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    return `${parts[0]?.[0] ?? ''}${parts.length > 1 ? parts.at(-1)[0] : ''}`.toUpperCase() || 'UT';
};

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : 'Jamais';

const selectSite = (code) => {
    selectedSiteCode.value = code;
    form.site_code = code;
    view.value = 'list';
    editingUser.value = null;
    clearSelection();
};

const roleFilterOptions = computed(() => [
    { value: '', label: 'Tous les rôles' },
    ...roles.value.map((role) => ({ value: role.code, label: role.name })),
]);
const statusFilterOptions = [
    { value: 'active', label: 'Actifs' },
    { value: 'inactive', label: 'Désactivés' },
    { value: 'all', label: 'Tous' },
];
const submitFilters = () => {
    clearSelection();
    router.get('/super-admin/workspaces/users', {
        search: query.value || undefined,
        status: statusFilter.value,
        role: roleFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const step1Valid = computed(() => {
    if (form.name.trim() === '' || form.email.trim() === '') return false;
    // Password is only ever set here when editing — creation never asks
    // for one, the account is provisioned by email invitation instead.
    if (isEditing.value && form.password !== '' && form.password.length < 12) return false;
    if (form.password !== '' && form.password !== form.password_confirmation) return false;
    return true;
});
const step2Valid = computed(() => form.role_id !== '' && (!selectedRoleProfiles.value.length || form.professional_profile_id !== ''));

const openCreate = () => {
    editingUser.value = null;
    form.reset();
    form.clearErrors();
    form.site_code = selectedSiteCode.value;
    form.role_id = roles.value.find((role) => role.code !== 'SUPER_ADMIN')?.id ?? roles.value[0]?.id ?? '';
    form.professional_profile_id = '';
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    step.value = 1;
    maxStepReached.value = 1;
    view.value = 'form';
};

const openEdit = (user) => {
    editingUser.value = user;
    form.clearErrors();
    form.site_code = selectedSiteCode.value;
    form.name = user.name;
    form.email = user.email;
    form.role_id = user.role?.id ?? '';
    form.professional_profile_id = user.professional_profile?.id ?? '';
    form.password = '';
    form.password_confirmation = '';
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    step.value = 1;
    maxStepReached.value = 2;
    view.value = 'form';
};

/**
 * Leaving the form never silently discards staged permission decisions.
 *
 * Every Autoriser/Interdire — including "Tout interdire" — only changes the
 * draft until "Enregistrer" reaches the site API. Closing used to reset that
 * draft without a word, so a screen full of "Exception · Interdit" could be
 * abandoned while the account kept every access (reported 2026-09-13).
 */
const confirmingDiscard = ref(false);

const closeForm = () => {
    if (form.processing) return;
    if (form.isDirty) {
        confirmingDiscard.value = true;
        return;
    }
    discardForm();
};

const confirmDiscard = () => {
    confirmingDiscard.value = false;
    discardForm();
};

/** A reload or a closed tab would lose the same draft: the browser asks first. */
const warnBeforeUnload = (event) => {
    if (view.value === 'list' || ! form.isDirty) return;
    event.preventDefault();
    event.returnValue = '';
};
onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload));

const discardForm = () => {
    view.value = 'list';
    editingUser.value = null;
    form.reset();
    form.clearErrors();
};

// Inertia fires onSuccess before onFinish, so form.processing is still true
// at that point — closeForm()'s guard (there to stop Annuler/backdrop from
// closing mid-submit) would otherwise block a real success from ever
// closing the wizard. A finished submission always gets to close.
const dismissForm = () => discardForm();

const goToStep = (n) => {
    if (n <= maxStepReached.value) step.value = n;
};

const nextStep = () => {
    if (step.value === 1 && !step1Valid.value) return;
    if (step.value === 2 && !step2Valid.value) return;

    step.value = Math.min(2, step.value + 1);
    maxStepReached.value = Math.max(maxStepReached.value, step.value);
};

const prevStep = () => { step.value = Math.max(1, step.value - 1); };

/**
 * Changer de rôle repart sans profil : un profil n'appartient qu'à son rôle.
 *
 * Les permissions recommandées du nouveau profil ne sont plus « préparées »
 * ici — elles le sont côté serveur au moment de l'enregistrement, parce que
 * cet écran ne porte plus les exceptions individuelles. `UpdateUserAction`
 * applique les recommandations dès que le profil change, sans jamais
 * écraser une décision individuelle déjà prise (ADR-033).
 */
const selectRole = (roleId) => {
    form.role_id = roleId;
    form.professional_profile_id = '';
};

const selectProfile = (profileId) => {
    form.professional_profile_id = profileId;
};

const roleRequiresProfile = (roleId) => Boolean(roles.value.find((item) => Number(item.id) === Number(roleId))?.profiles?.length);

/**
 * Le payload ne porte plus `permission_overrides` : omise, la clé laisse les
 * exceptions du compte intactes côté serveur (`UpdateUserAction`). Les
 * modifier est le geste de l'écran « Rôles & permissions ».
 */
const submitUser = () => {
    const options = { preserveScroll: true, onSuccess: dismissForm };

    if (editingUser.value) {
        form.put(`/super-admin/workspaces/users/${selectedSiteCode.value}/${editingUser.value.uuid}`, options);
        return;
    }

    form.post('/super-admin/workspaces/users', options);
};

// A single row action and the bulk-selection action share one dialog and
// one endpoint — deactivating "one" is just deactivating a one-user batch.
const openDeactivate = (user) => {
    deactivateTargets.value = [user];
    deactivationForm.reset();
    deactivationForm.clearErrors();
    deactivationForm.reason = 'Désactivation demandée par la Super Administration.';
};

const openBulkDeactivate = () => {
    if (selectedUsers.value.length === 0) return;
    deactivateTargets.value = selectedUsers.value;
    deactivationForm.reset();
    deactivationForm.clearErrors();
    deactivationForm.reason = 'Désactivation demandée par la Super Administration.';
};

const closeDeactivate = () => {
    if (deactivationForm.processing) return;
    deactivateTargets.value = [];
    deactivationForm.reset();
    deactivationForm.clearErrors();
};

// Same onSuccess/onFinish ordering as dismissForm() above — unconditional,
// used only for a genuine success.
const dismissDeactivate = () => {
    deactivateTargets.value = [];
    clearSelection();
    deactivationForm.reset();
    deactivationForm.clearErrors();
};

const confirmDeactivate = () => deactivationForm.transform((data) => ({
    ...data,
    site_code: selectedSiteCode.value,
    uuids: deactivateTargets.value.map((user) => user.uuid),
})).post(
    '/super-admin/workspaces/users/bulk/deactivate',
    { preserveScroll: true, onSuccess: dismissDeactivate },
);

const activate = (user) => router.post(
    `/super-admin/workspaces/users/${selectedSiteCode.value}/${user.uuid}/activate`,
    {},
    { preserveScroll: true },
);

const canManage = (user) => user.role?.code !== 'SUPER_ADMIN' || can('users.assign_super_admin');

// ADR-062: irreversible, and only ever offered for an account the server
// already reports as never used (user.deletable) — a row action and the
// bulk-selection action share one dialog and one endpoint, exactly like
// deactivate above. Confirming by typing the email back (single account) or
// the fixed word "SUPPRIMER" (several at once) is a deliberate extra step
// against a stray click on a destructive, irreversible action.
const openForceDelete = (user) => {
    forceDeleteTargets.value = [user];
    forceDeleteConfirmText.value = '';
    forceDeleteForm.clearErrors();
};

const openBulkForceDelete = () => {
    if (deletableSelectedUsers.value.length === 0) return;
    forceDeleteTargets.value = deletableSelectedUsers.value;
    forceDeleteConfirmText.value = '';
    forceDeleteForm.clearErrors();
};

const closeForceDelete = () => {
    if (forceDeleteForm.processing) return;
    forceDeleteTargets.value = [];
    forceDeleteConfirmText.value = '';
    forceDeleteForm.clearErrors();
};

const dismissForceDelete = () => {
    if (forceDeleteTargets.value.length) {
        const deletedUuids = new Set(forceDeleteTargets.value.map((user) => user.uuid));
        const next = new Set(selectedUuids.value);
        for (const uuid of deletedUuids) next.delete(uuid);
        selectedUuids.value = next;
    }

    forceDeleteTargets.value = [];
    forceDeleteConfirmText.value = '';
    forceDeleteForm.clearErrors();
};

const forceDeleteConfirmed = computed(() => {
    if (forceDeleteTargets.value.length === 0) return false;

    const typed = forceDeleteConfirmText.value.trim().toLowerCase();

    return forceDeleteTargets.value.length === 1
        ? typed === forceDeleteTargets.value[0].email.toLowerCase()
        : typed === 'supprimer';
});

const confirmForceDelete = () => {
    if (!forceDeleteConfirmed.value) return;

    forceDeleteForm.transform((data) => ({
        ...data,
        site_code: selectedSiteCode.value,
        uuids: forceDeleteTargets.value.map((user) => user.uuid),
    })).post(
        '/super-admin/workspaces/users/bulk/force-delete',
        { preserveScroll: true, onSuccess: dismissForceDelete },
    );
};


const pageTitle = computed(() => {
    if (view.value === 'list') return 'Rôles & permissions';
    if (view.value === 'roles') return 'Socle des rôles';
    return isEditing.value ? 'Modifier un utilisateur' : 'Créer un utilisateur';
});
</script>

<template>
    <Head :title="pageTitle" />

    <div class="w-full space-y-5">
        <template v-if="view === 'list'">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold tracking-tight text-foreground">Utilisateurs</h1>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">Chaque compte appartient à un site précis et porte exactement un rôle, qui lui donne son socle de droits. Le socle lui-même et les exceptions individuelles se règlent dans <a class="font-semibold text-primary hover:underline" href="/super-admin/workspaces/roles">Rôles &amp; permissions</a>.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="canManageRoleBaselines" :as="Link" href="/super-admin/workspaces/roles" variant="outline">
                        <ShieldCheck class="h-4 w-4" />Rôles &amp; permissions
                    </Button>
                    <Button v-if="canCreate && selectedSite?.ok" type="button" variant="primary" @click="openCreate">
                        <UserPlus class="h-4 w-4" />Nouvel utilisateur
                    </Button>
                </div>
            </div>

            <Card class="overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-border px-4 py-3 xl:flex-row xl:items-center xl:justify-between">
                    <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded-lg bg-muted p-1">
                        <button
                            v-for="site in sites"
                            :key="site.site.code"
                            type="button"
                            :class="cn(
                                'inline-flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-xs font-bold transition-colors',
                                selectedSiteCode === site.site.code ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            )"
                            @click="selectSite(site.site.code)"
                        >
                            <span :class="cn('h-1.5 w-1.5 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'OFFLINE' || site.status === 'ERROR' ? 'bg-destructive' : 'bg-muted-foreground')" />
                            {{ site.site.name }}
                            <span v-if="site.ok" class="rounded bg-muted px-1.5 py-0.5 text-[10px] tabular-nums">{{ site.data?.users?.length ?? 0 }}</span>
                        </button>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <form class="w-full sm:w-64" role="search" @submit.prevent="submitFilters">
                            <IconInput v-model="query" :icon="Search" type="search" placeholder="Nom ou adresse email" autocomplete="off" aria-label="Rechercher un compte" />
                        </form>
                        <Select v-model="roleFilter" class="h-10 w-full sm:w-44" :options="roleFilterOptions" @update:model-value="submitFilters" />
                        <Select v-model="statusFilter" class="h-10 w-full sm:w-36" :options="statusFilterOptions" @update:model-value="submitFilters" />
                        <div class="inline-flex shrink-0 gap-1 rounded-lg bg-muted p-1">
                            <button type="button" :class="cn('grid h-7 w-7 place-items-center rounded-md transition-colors', listMode === 'list' ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground')" aria-label="Vue liste" title="Vue liste" @click="listMode = 'list'"><List class="h-4 w-4" /></button>
                            <button type="button" :class="cn('grid h-7 w-7 place-items-center rounded-md transition-colors', listMode === 'grid' ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground')" aria-label="Vue grille" title="Vue grille" @click="listMode = 'grid'"><LayoutGrid class="h-4 w-4" /></button>
                        </div>
                    </div>
                </div>

                <div v-if="selectedUsers.length" class="flex flex-col gap-3 border-b border-border bg-primary/5 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <span class="grid h-8 min-w-8 place-items-center rounded-lg bg-primary px-2 text-xs font-bold text-primary-foreground">{{ selectedUsers.length }}</span>
                        <div>
                            <p class="text-sm font-bold text-foreground">compte(s) sélectionné(s)</p>
                            <p class="text-xs text-muted-foreground">Actions limitées au site {{ selectedSite?.site.name }} · 100 maximum</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="can('users.deactivate')" type="button" size="sm" variant="outline" @click="openBulkDeactivate"><Lock class="h-4 w-4" />Désactiver ({{ selectedUsers.length }})</Button>
                        <Button v-if="can('users.force_delete') && deletableSelectedUsers.length" type="button" size="sm" variant="danger-outline" @click="openBulkForceDelete"><Trash2 class="h-4 w-4" />Supprimer définitivement ({{ deletableSelectedUsers.length }})</Button>
                        <Button type="button" size="sm" variant="ghost" @click="clearSelection">Désélectionner</Button>
                    </div>
                </div>

                <div v-if="!selectedSite?.ok" class="flex min-h-56 flex-col items-center justify-center px-6 py-10 text-center">
                    <span class="grid h-11 w-11 place-items-center rounded-lg bg-muted text-muted-foreground"><Server class="h-5 w-5" /></span>
                    <h2 class="mt-3 text-sm font-bold text-foreground">Référentiel indisponible pour {{ selectedSite?.site.name }}</h2>
                    <p class="mt-1 max-w-lg text-xs leading-5 text-muted-foreground">{{ selectedSite?.message }}</p>
                </div>

                <div v-else-if="listMode === 'list'" class="overflow-x-auto">
                    <table class="w-full min-w-[900px] border-collapse">
                        <caption class="sr-only">Liste des utilisateurs du site {{ selectedSite.site.name }}</caption>
                        <thead>
                            <tr class="bg-muted/40">
                                <th class="w-10 border-b border-border px-5 py-2.5">
                                    <Checkbox :model-value="allVisibleSelected" :disabled="selectableUsers.length === 0" aria-label="Sélectionner tous les comptes actifs affichés" @update:model-value="toggleAllVisible" />
                                </th>
                                <th class="border-b border-border px-5 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">Utilisateur</th>
                                <th class="border-b border-border px-5 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">Rôle</th>
                                <th class="border-b border-border px-5 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">Dernière connexion</th>
                                <th class="border-b border-border px-5 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">État</th>
                                <th class="border-b border-border px-5 py-2.5 text-end text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in users" :key="user.uuid" :class="cn('transition-colors hover:bg-accent/40', selectedUuids.has(user.uuid) ? 'bg-primary/5' : '')">
                                <td class="border-b border-border px-5 py-3">
                                    <Checkbox v-if="user.active && canManage(user)" :model-value="selectedUuids.has(user.uuid)" :aria-label="`Sélectionner ${user.name}`" @update:model-value="toggleUser(user.uuid)" />
                                </td>
                                <td class="border-b border-border px-5 py-3">
                                    <div class="flex min-w-[260px] items-center gap-3">
                                        <Avatar size="sm" variant="slate-pale" :text="initials(user.name)" aria-hidden="true" />
                                        <div class="min-w-0">
                                            <span class="truncate text-sm font-bold text-foreground">{{ user.name }}</span>
                                            <span class="mt-0.5 block truncate text-xs text-muted-foreground">{{ user.email }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="border-b border-border px-5 py-3">
                                    <p class="text-sm font-medium text-foreground">{{ user.role?.name ?? 'Aucun rôle' }}</p>
                                    <p v-if="user.professional_profile" class="mt-0.5 text-xs text-muted-foreground">{{ user.professional_profile.name }}</p>
                                    <p v-else-if="roleRequiresProfile(user.role?.id)" class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">Profil métier à définir</p>
                                    <p v-if="user.permission_overrides.length" class="mt-0.5 text-xs text-muted-foreground">{{ user.permission_overrides.length }} exception{{ user.permission_overrides.length > 1 ? 's' : '' }} individuelle{{ user.permission_overrides.length > 1 ? 's' : '' }}</p>
                                </td>
                                <td class="border-b border-border px-5 py-3 text-sm text-muted-foreground">{{ formatDateTime(user.last_login_at) }}</td>
                                <td class="border-b border-border px-5 py-3">
                                    <Badge v-if="user.active" variant="success">Actif</Badge>
                                    <div v-else>
                                        <Badge variant="outline">Désactivé</Badge>
                                        <p v-if="user.deactivation_reason" class="mt-1 max-w-xs truncate text-xs text-muted-foreground" :title="user.deactivation_reason">{{ user.deactivation_reason }}</p>
                                    </div>
                                </td>
                                <td class="border-b border-border px-5 py-3 text-end">
                                    <div v-if="canManage(user)" class="inline-flex items-center gap-1">
                                        <Button v-if="can('users.update')" type="button" size="icon" variant="outline" class="h-8 w-8" :aria-label="`Modifier ${user.name}`" title="Modifier" @click="openEdit(user)"><Pencil class="h-4 w-4" /></Button>
                                        <Button v-if="user.active && can('users.deactivate')" type="button" size="icon" variant="outline" class="h-8 w-8" :aria-label="`Désactiver ${user.name}`" title="Désactiver" @click="openDeactivate(user)"><Lock class="h-4 w-4" /></Button>
                                        <Button v-if="!user.active && can('users.activate')" type="button" size="icon" variant="outline" class="h-8 w-8" :aria-label="`Réactiver ${user.name}`" title="Réactiver" @click="activate(user)"><LockOpen class="h-4 w-4" /></Button>
                                        <Button v-if="user.deletable && can('users.force_delete')" type="button" size="icon" variant="danger-outline" class="h-8 w-8" :aria-label="`Supprimer définitivement ${user.name}`" title="Supprimer définitivement" @click="openForceDelete(user)"><Trash2 class="h-4 w-4" /></Button>
                                    </div>
                                    <span v-else class="text-xs text-muted-foreground">Protégé</span>
                                </td>
                            </tr>

                            <tr v-if="users.length === 0">
                                <td colspan="6" class="px-5 py-12 text-center">
                                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><Users class="h-5 w-5" /></span>
                                    <p class="mt-3 text-sm font-medium text-foreground">Aucun utilisateur trouvé</p>
                                    <p class="mt-1 text-xs text-muted-foreground">Modifiez les filtres ou créez un compte autorisé.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                    <Card v-for="user in users" :key="user.uuid" :class="cn('flex flex-col', selectedUuids.has(user.uuid) ? 'border-primary/40 ring-1 ring-primary/20' : '')">
                        <div class="flex items-start gap-3 p-4">
                            <Checkbox v-if="user.active && canManage(user)" class="mt-1" :model-value="selectedUuids.has(user.uuid)" :aria-label="`Sélectionner ${user.name}`" @update:model-value="toggleUser(user.uuid)" />
                            <Avatar size="sm" variant="slate-pale" :text="initials(user.name)" aria-hidden="true" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold text-foreground">{{ user.name }}</p>
                                <p class="mt-0.5 truncate text-xs text-muted-foreground">{{ user.email }}</p>
                            </div>
                            <span :class="cn('mt-1.5 h-2 w-2 shrink-0 rounded-full', user.active ? 'bg-emerald-500' : 'bg-muted-foreground')" :title="user.active ? 'Actif' : 'Désactivé'" />
                        </div>

                        <div class="border-t border-border px-4 py-3">
                            <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Rôle</p>
                            <p class="mt-1 text-sm font-medium text-foreground">{{ user.role?.name ?? 'Aucun rôle' }}</p>
                            <p v-if="user.professional_profile" class="mt-0.5 text-xs text-muted-foreground">{{ user.professional_profile.name }}</p>
                            <p v-else-if="roleRequiresProfile(user.role?.id)" class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">Profil métier à définir</p>
                            <Badge v-if="user.permission_overrides.length" variant="outline" class="mt-1.5 px-2 py-0 text-[11px]">{{ user.permission_overrides.length }} exception{{ user.permission_overrides.length > 1 ? 's' : '' }}</Badge>
                            <p class="mt-2 text-[11px] text-muted-foreground">Connexion : {{ formatDateTime(user.last_login_at) }}</p>
                            <p v-if="!user.active && user.deactivation_reason" class="mt-1 truncate text-[11px] text-muted-foreground" :title="user.deactivation_reason">Motif : {{ user.deactivation_reason }}</p>
                        </div>

                        <div class="mt-auto flex items-center justify-end gap-1 border-t border-border px-4 py-2.5">
                            <template v-if="canManage(user)">
                                <Button v-if="can('users.update')" type="button" size="icon" variant="outline" class="h-8 w-8" :aria-label="`Modifier ${user.name}`" title="Modifier" @click="openEdit(user)"><Pencil class="h-4 w-4" /></Button>
                                <Button v-if="user.active && can('users.deactivate')" type="button" size="icon" variant="outline" class="h-8 w-8" :aria-label="`Désactiver ${user.name}`" title="Désactiver" @click="openDeactivate(user)"><Lock class="h-4 w-4" /></Button>
                                <Button v-if="!user.active && can('users.activate')" type="button" size="icon" variant="outline" class="h-8 w-8" :aria-label="`Réactiver ${user.name}`" title="Réactiver" @click="activate(user)"><LockOpen class="h-4 w-4" /></Button>
                                <Button v-if="user.deletable && can('users.force_delete')" type="button" size="icon" variant="danger-outline" class="h-8 w-8" :aria-label="`Supprimer définitivement ${user.name}`" title="Supprimer définitivement" @click="openForceDelete(user)"><Trash2 class="h-4 w-4" /></Button>
                            </template>
                            <span v-else class="text-xs text-muted-foreground">Protégé</span>
                        </div>
                    </Card>

                    <div v-if="users.length === 0" class="col-span-full px-5 py-12 text-center">
                        <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><Users class="h-5 w-5" /></span>
                        <p class="mt-3 text-sm font-medium text-foreground">Aucun utilisateur trouvé</p>
                        <p class="mt-1 text-xs text-muted-foreground">Modifiez les filtres ou créez un compte autorisé.</p>
                    </div>
                </div>
            </Card>

            <Card class="p-4 sm:px-5">
                <div class="flex items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><ShieldCheck class="h-4.5 w-4.5" /></span>
                    <div>
                        <h2 class="text-sm font-bold text-foreground">Un accès propre à chaque compte</h2>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">Le rôle fournit uniquement le socle commun du service — deux comptes du même rôle peuvent avoir des droits différents. Une interdiction individuelle est toujours prioritaire sur une autorisation. Toute attribution est exécutée et auditée directement dans la base du site, jamais en local sur le portail. Un compte n'est jamais supprimé : il est désactivé pour préserver l'historique.</p>
                    </div>
                </div>
            </Card>
        </template>

        <template v-else-if="view === 'form'">
            <div class="flex items-center gap-3">
                <Button type="button" size="icon" variant="outline" class="h-10 w-10" aria-label="Retour à la liste" @click="closeForm"><ArrowLeft class="h-4.5 w-4.5" /></Button>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ selectedSite?.site.name }}</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold tracking-tight text-foreground">{{ isEditing ? `Modifier ${editingUser.name}` : 'Créer un utilisateur' }}</h1>
                </div>
            </div>

            <nav class="overflow-hidden rounded-xl border border-border bg-card shadow-sm" aria-label="Étapes">
                <ol class="grid grid-cols-2">
                    <li v-for="(s, index) in steps" :key="s.n">
                        <button
                            type="button"
                            :disabled="s.n > maxStepReached"
                            :class="cn(
                                'flex w-full items-center gap-3 px-4 py-3.5 text-start transition-colors',
                                index > 0 ? 'border-s border-border' : '',
                                s.n > maxStepReached ? 'cursor-not-allowed opacity-50' : 'hover:bg-accent/50',
                            )"
                            @click="goToStep(s.n)"
                        >
                            <span :class="cn(
                                'grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-bold',
                                step === s.n ? 'bg-primary text-primary-foreground'
                                    : s.n < step ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                        : 'bg-muted text-muted-foreground',
                            )">
                                <Check v-if="s.n < step" class="h-4 w-4" />
                                <template v-else>{{ s.n }}</template>
                            </span>
                            <span class="min-w-0">
                                <span :class="cn('block text-xs font-bold uppercase tracking-wide', step === s.n ? 'text-primary' : 'text-muted-foreground')">Étape {{ s.n }}</span>
                                <span class="block truncate text-sm font-bold text-foreground">{{ s.label }}</span>
                            </span>
                        </button>
                    </li>
                </ol>
            </nav>

            <form @submit.prevent="submitUser">
                <Card v-if="step === 1" class="p-6">
                    <h2 class="text-sm font-bold text-foreground">Informations du compte</h2>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Un compte nominatif, jamais générique ni partagé.</p>

                    <div v-if="form.errors.site_code || form.errors.user" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">{{ form.errors.site_code || form.errors.user }}</div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <FormField label="Nom complet" required :error="form.errors.name">
                            <Input v-model="form.name" autocomplete="name" :aria-invalid="Boolean(form.errors.name)" />
                        </FormField>
                        <FormField label="Email professionnel" required :error="form.errors.email">
                            <Input v-model="form.email" type="email" autocomplete="off" :aria-invalid="Boolean(form.errors.email)" />
                        </FormField>
                    </div>

                    <div v-if="!isEditing" class="mt-6 flex items-start gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-3">
                        <Mail class="mt-0.5 h-4.5 w-4.5 shrink-0 text-primary" />
                        <div>
                            <p class="text-sm font-bold text-foreground">Aucun mot de passe à saisir</p>
                            <p class="mt-1 text-xs leading-5 text-muted-foreground">Un email sera envoyé à <strong>{{ form.email || "l'adresse renseignée" }}</strong> avec son identifiant de connexion et un lien pour créer son propre mot de passe.</p>
                        </div>
                    </div>

                    <div v-else class="mt-6 border-t border-border pt-5">
                        <h3 class="text-sm font-bold text-foreground">Nouveau mot de passe</h3>
                        <p class="mt-1 text-xs text-muted-foreground">Laissez vide pour conserver le mot de passe actuel.</p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <FormField as="div" label="Mot de passe" :error="form.errors.password">
                                <div class="relative">
                                    <Input v-model="form.password" :type="showPassword ? 'text' : 'password'" class="pe-10" autocomplete="new-password" :aria-invalid="Boolean(form.errors.password)" />
                                    <button type="button" class="absolute inset-y-0 end-0 grid w-10 place-items-center text-muted-foreground hover:text-foreground" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword">
                                        <component :is="showPassword ? EyeOff : Eye" class="h-4 w-4" />
                                    </button>
                                </div>
                            </FormField>
                            <FormField as="div" label="Confirmation">
                                <div class="relative">
                                    <Input v-model="form.password_confirmation" :type="showPasswordConfirmation ? 'text' : 'password'" class="pe-10" autocomplete="new-password" />
                                    <button type="button" class="absolute inset-y-0 end-0 grid w-10 place-items-center text-muted-foreground hover:text-foreground" :aria-label="showPasswordConfirmation ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPasswordConfirmation = !showPasswordConfirmation">
                                        <component :is="showPasswordConfirmation ? EyeOff : Eye" class="h-4 w-4" />
                                    </button>
                                </div>
                            </FormField>
                        </div>
                    </div>
                </Card>

                <Card v-else-if="step === 2" class="p-6">
                    <h2 class="text-sm font-bold text-foreground">Rôle métier</h2>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Le rôle donne uniquement le socle de droits commun au service — il ne fige pas les permissions du compte, ajustables à l'étape suivante.</p>

                    <div class="mt-5">
                        <p class="mb-2 text-sm font-medium text-foreground">Rôle métier <span class="text-destructive">*</span></p>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <button
                                v-for="role in roles"
                                :key="role.id"
                                type="button"
                                :class="cn(
                                    'rounded-lg border p-4 text-start transition-colors',
                                    Number(form.role_id) === Number(role.id) ? 'border-primary bg-primary/5 ring-1 ring-ring/25' : 'border-border hover:border-primary/40',
                                )"
                                :aria-pressed="Number(form.role_id) === Number(role.id)"
                                @click="selectRole(role.id)"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-bold text-foreground">{{ role.name }}</p>
                                    <CircleCheck v-if="Number(form.role_id) === Number(role.id)" class="h-4.5 w-4.5 shrink-0 text-primary" />
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">{{ role.permissions.length }} permission{{ role.permissions.length > 1 ? 's' : '' }} par défaut</p>
                                <p v-if="role.profiles.length" class="mt-0.5 text-[11px] text-muted-foreground">{{ role.profiles.length }} profil{{ role.profiles.length > 1 ? 's' : '' }} métier</p>
                            </button>
                        </div>
                        <FormError v-if="form.errors.role_id">{{ form.errors.role_id }}</FormError>
                    </div>

                    <div v-if="selectedRoleProfiles.length" class="mt-6">
                        <p class="mb-2 text-sm font-medium text-foreground">Profil professionnel <span class="text-destructive">*</span></p>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <button
                                v-for="profile in selectedRoleProfiles"
                                :key="profile.id"
                                type="button"
                                :class="cn(
                                    'rounded-lg border p-4 text-start transition-colors',
                                    Number(form.professional_profile_id) === Number(profile.id) ? 'border-primary bg-primary/5 ring-1 ring-ring/25' : 'border-border hover:border-primary/40',
                                )"
                                :aria-pressed="Number(form.professional_profile_id) === Number(profile.id)"
                                @click="selectProfile(profile.id)"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-bold text-foreground">{{ profile.name }}</p>
                                    <CircleCheck v-if="Number(form.professional_profile_id) === Number(profile.id)" class="h-4.5 w-4.5 shrink-0 text-primary" />
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs text-muted-foreground">{{ profile.description }}</p>
                            </button>
                        </div>
                        <FormError v-if="form.errors.professional_profile_id">{{ form.errors.professional_profile_id }}</FormError>
                    </div>

                    <div v-if="selectedProfile" class="mt-5 rounded-lg border border-border bg-muted/40 px-4 py-3">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-bold text-foreground">{{ selectedProfile.name }}</p>
                                <p class="mt-1 text-xs leading-5 text-muted-foreground">{{ selectedProfile.description }}</p>
                                <p class="mt-1 text-[11px] text-muted-foreground">Le profil classe le métier ; il ne donne aucun droit automatiquement.</p>
                            </div>
                        </div>
                        <p v-if="selectedProfile.recommended_permissions?.length" class="mt-2 text-[11px] text-muted-foreground">{{ selectedProfile.recommended_permissions.length }} droit{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} recommandé{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} par ce profil, ajouté{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} à l’enregistrement lorsque le profil change. Ils restent ensuite ajustables dans « Rôles &amp; permissions ».</p>
                        <p v-else class="mt-2 text-[11px] text-muted-foreground">Aucun droit supplémentaire recommandé : le socle du rôle reste applicable.</p>
                        <div v-if="profileChanged" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                            <p class="font-bold">Le profil professionnel a changé.</p>
                            <p>{{ oldProfileName }} → {{ newProfileName }}</p>
                            <p class="mt-1">À l’enregistrement, les permissions venues de l’ancien profil seront retirées et les recommandations du nouveau ajoutées. Une permission attribuée individuellement n’est jamais écrasée.</p>
                        </div>
                    </div>

                    <div v-if="selectedRole" class="mt-5 border-t border-border pt-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Socle du rôle « {{ selectedRole.name }} »</p>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">{{ selectedRole.permissions.length }} permission{{ selectedRole.permissions.length > 1 ? 's' : '' }} accordée{{ selectedRole.permissions.length > 1 ? 's' : '' }} par défaut à ce rôle, sur tous ses comptes. Le socle lui-même et les exceptions de ce compte se règlent dans « Rôles &amp; permissions ».</p>
                    </div>
                </Card>
                <footer class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-3">
                        <Button type="button" variant="outline" :disabled="form.processing" @click="closeForm">Annuler</Button>
                    </div>
                    <div class="flex flex-col-reverse gap-3 sm:flex-row">
                        <Button v-if="step > 1" type="button" variant="outline" :disabled="form.processing" @click="prevStep"><ArrowLeft class="h-4 w-4" />Précédent</Button>
                        <Button v-if="isEditing && step === 1" type="button" variant="outline" :disabled="!step1Valid || form.processing" @click="submitUser">
                            <Check class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : 'Mettre à jour les informations' }}
                        </Button>
                        <Button v-if="step < 2" type="button" variant="primary" :disabled="!step1Valid" @click="nextStep">{{ isEditing ? 'Continuer vers le rôle' : 'Suivant' }}<ArrowRight class="h-4 w-4" /></Button>
                        <Button v-else type="submit" variant="primary" :disabled="form.processing || !step2Valid">
                            <Check class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : (isEditing ? 'Enregistrer' : 'Créer le compte') }}
                        </Button>
                    </div>
                </footer>
            </form>
        </template>

        <Dialog
            :open="confirmingDiscard"
            title="Quitter sans enregistrer ?"
            @update:open="confirmingDiscard = $event"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/35 dark:text-amber-300"><TriangleAlert class="h-5 w-5" /></span>
            </template>
            <p class="text-sm leading-5 text-muted-foreground">Les informations saisies n’ont pas été envoyées au site. Si vous quittez, le compte reste exactement tel qu’il est aujourd’hui.</p>
            <template #footer>
                <Button type="button" variant="outline" @click="confirmDiscard">Quitter sans enregistrer</Button>
                <Button type="button" variant="primary" @click="confirmingDiscard = false">Continuer la modification</Button>
            </template>
        </Dialog>

        <Dialog
            :open="deactivateTargets.length > 0"
            :title="deactivateTargets.length === 1 ? `Désactiver ${deactivateTargets[0].name} ?` : `Désactiver ${deactivateTargets.length} comptes ?`"
            :description="`La connexion sera bloquée et les sessions ouvertes seront révoquées sur ${selectedSite?.site.name}. L’historique reste conservé.`"
            :dismissible="!deactivationForm.processing"
            @update:open="closeDeactivate"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"><Lock class="h-5 w-5" /></span>
            </template>

            <ul v-if="deactivateTargets.length > 1" class="mb-4 max-h-28 overflow-y-auto rounded-lg border border-border px-3 py-2 text-xs text-muted-foreground">
                <li v-for="user in deactivateTargets" :key="user.uuid">{{ user.name }}</li>
            </ul>

            <FormField as="div" label="Motif" hint="(pré-rempli, modifiable)" :error="deactivationForm.errors.reason">
                <textarea
                    v-model="deactivationForm.reason"
                    rows="3"
                    autofocus
                    placeholder="Ex. fin de contrat ou changement d’affectation"
                    class="block w-full resize-y rounded-lg border border-input bg-card px-3 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-2 focus:ring-ring/25"
                ></textarea>
            </FormField>
            <FormError v-if="deactivationForm.errors.uuids">{{ deactivationForm.errors.uuids }}</FormError>
            <FormError v-if="deactivationForm.errors.user">{{ deactivationForm.errors.user }}</FormError>

            <template #footer>
                <Button type="button" variant="outline" :disabled="deactivationForm.processing" @click="closeDeactivate">Annuler</Button>
                <Button type="button" variant="secondary" :disabled="deactivationForm.processing" @click="confirmDeactivate">
                    <Lock class="h-4 w-4" />{{ deactivationForm.processing ? 'Désactivation…' : (deactivateTargets.length === 1 ? 'Désactiver le compte' : `Désactiver ${deactivateTargets.length} comptes`) }}
                </Button>
            </template>
        </Dialog>

        <Dialog
            :open="forceDeleteTargets.length > 0"
            :title="forceDeleteTargets.length === 1 ? `Supprimer ${forceDeleteTargets[0].name} ?` : `Supprimer ${forceDeleteTargets.length} comptes ?`"
            :dismissible="!forceDeleteForm.processing"
            @update:open="closeForceDelete"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-red-50 text-destructive dark:bg-red-950/35"><Trash2 class="h-5 w-5" /></span>
            </template>

            <p class="text-sm leading-5 text-muted-foreground">Action <strong class="text-destructive">irréversible</strong> et distincte de la désactivation : {{ forceDeleteTargets.length === 1 ? 'le compte est' : 'les comptes sont' }} retiré{{ forceDeleteTargets.length > 1 ? 's' : '' }} de la base, pas seulement bloqué{{ forceDeleteTargets.length > 1 ? 's' : '' }}. Possible uniquement parce {{ forceDeleteTargets.length === 1 ? "qu'il n'a" : "qu'ils n'ont" }} jamais servi.</p>
            <ul v-if="forceDeleteTargets.length > 1" class="mt-3 max-h-28 overflow-y-auto rounded-lg border border-border px-3 py-2 text-xs text-muted-foreground">
                <li v-for="user in forceDeleteTargets" :key="user.uuid">{{ user.name }}</li>
            </ul>

            <div class="mt-4">
                <label for="force_delete_confirm" class="mb-1.5 block text-sm font-medium text-foreground">
                    <template v-if="forceDeleteTargets.length === 1">Tapez <span class="font-mono font-bold">{{ forceDeleteTargets[0].email }}</span> pour confirmer</template>
                    <template v-else>Tapez <span class="font-mono font-bold">SUPPRIMER</span> pour confirmer</template>
                </label>
                <Input id="force_delete_confirm" v-model="forceDeleteConfirmText" autocomplete="off" autofocus />
                <FormError v-if="forceDeleteForm.errors.user">{{ forceDeleteForm.errors.user }}</FormError>
                <FormError v-if="forceDeleteForm.errors.uuids">{{ forceDeleteForm.errors.uuids }}</FormError>
            </div>

            <template #footer>
                <Button type="button" variant="outline" :disabled="forceDeleteForm.processing" @click="closeForceDelete">Annuler</Button>
                <Button type="button" variant="destructive" :disabled="!forceDeleteConfirmed || forceDeleteForm.processing" @click="confirmForceDelete">
                    <Trash2 class="h-4 w-4" />{{ forceDeleteForm.processing ? 'Suppression…' : (forceDeleteTargets.length === 1 ? 'Supprimer définitivement' : `Supprimer ${forceDeleteTargets.length} comptes`) }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
