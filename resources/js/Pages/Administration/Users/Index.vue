<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Button from '@/Components/Shadcn/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import AccountKindPicker from '@/Components/Users/AccountKindPicker.vue';
import { Check, ChevronRight, CircleAlert, CircleCheck, CircleX, IdCard, Lock, LockOpen, Pencil, Search, ShieldCheck, UserPlus, UserRound, Users, X } from 'lucide-vue-next';
import Input from '@/Components/Shadcn/Input.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    users: Object,
    roles: Array,
    permissionCatalog: Array,
    filters: Object,
    /** ADR-188 — les fiches Employé qu'un compte peut relier. */
    employees: { type: Array, default: () => [] },
});

const page = usePage();
const { can } = usePermissions();
const query = ref(props.filters.q ?? '');
const statusFilter = ref(props.filters.status ?? 'active');
const roleFilter = ref(props.filters.role ?? '');
const editingUser = ref(null);
const formOpen = ref(false);
const permissionEffects = reactive({});
const permissionProvenance = reactive({});
const originalProfileOverrides = ref([]);
const deactivateTarget = ref(null);

const form = useForm({
    name: '',
    email: '',
    role_id: '',
    professional_profile_id: '',
    password: '',
    password_confirmation: '',
    permission_overrides: [],
    sync_profile_permissions: false,
    // ADR-188 — personnel clinique (une fiche Employé) ou externe : choisi, jamais par défaut.
    account_kind: '',
    employee_uuid: '',
});
const createEmployeeHref = computed(() => (can('employees.create') ? '/administration/employees/create' : ''));
const accountKindLabel = (user) => (user.account_kind === 'STAFF' ? 'Personnel clinique' : user.account_kind === 'EXTERNAL' ? 'Externe' : null);

/** Choisir une fiche propose son nom et son email, sans écraser une saisie. */
const prefilled = ref({ name: '', email: '' });
// ADR-188 — la fiche choisie donne le nom et l'email du compte. Une saisie
// faite à la main n'est jamais écrasée ; une valeur reprise d'une fiche
// précédente, si : changer de personne ne garde pas l'email de l'autre.
const onEmployeePick = (employee) => {
    if (form.name.trim() === '' || form.name === prefilled.value.name) form.name = employee.name;
    if (form.email.trim() === '' || form.email === prefilled.value.email) form.email = employee.email ?? '';
    prefilled.value = { name: employee.name, email: employee.email ?? '' };
    // La fiche RH n'a pas d'email : c'est la seule chose qui reste à saisir.
    if (! employee.email) nextTick(() => document.getElementById('user_email')?.focus());
};

const deactivationForm = useForm({ reason: '' });

const canCreate = computed(() => can('users.create') && can('roles.assign'));
const canAssignPermissions = computed(() => can('permissions.assign'));
const isEditing = computed(() => editingUser.value !== null);
/** Le nom et l'email se montrent quand on sait de qui il s'agit — jamais avant d'avoir cherché la personne. */
const identityVisible = computed(() => isEditing.value
    || form.account_kind === 'EXTERNAL'
    || (form.account_kind === 'STAFF' && form.employee_uuid !== '')
    || Boolean(form.errors.name || form.errors.email));
const fromStaffRecord = computed(() => form.account_kind === 'STAFF' && form.employee_uuid !== '');
// Passer à « Externe » : ce qui venait d'une fiche, et n'a pas été retouché, ne suit pas vers une autre personne.
watch(() => form.account_kind, (kind) => {
    if (kind !== 'EXTERNAL') return;
    if (prefilled.value.name !== '' && form.name === prefilled.value.name) form.name = '';
    if (prefilled.value.email !== '' && form.email === prefilled.value.email) form.email = '';
    prefilled.value = { name: '', email: '' };
});
const selectedRole = computed(() => props.roles.find((role) => Number(role.id) === Number(form.role_id)) ?? null);
const selectedRoleProfiles = computed(() => selectedRole.value?.profiles ?? []);
const selectedProfile = computed(() => selectedRoleProfiles.value.find(
    (profile) => Number(profile.id) === Number(form.professional_profile_id),
) ?? null);
const profileChanged = computed(() => isEditing.value
    && Number(editingUser.value?.professional_profile?.id ?? 0) !== Number(form.professional_profile_id ?? 0));
const selectedRolePermissions = computed(() => new Set(selectedRole.value?.permissions ?? []));
const overrideCount = computed(() => serializeOverrides().length);

// Whether the CURRENT profile's recommendations are already reflected in
// the live preview — true both right after clicking "Appliquer" and for an
// account that already had them from a previous save (profile untouched
// this session). form.sync_profile_permissions alone can't tell those two
// "nothing to do" cases apart from "just picked a new profile, unapplied".
const profileRecommendationsApplied = computed(() => {
    const recommended = selectedProfile.value?.recommended_permissions ?? [];
    if (!recommended.length) return true;

    return recommended.every((permission) => (
        permissionProvenance[permission.id]?.source === 'PROFILE'
        && Number(permissionProvenance[permission.id]?.source_profile_id) === Number(selectedProfile.value.id)
    ));
});

// The menu shows exactly these areas by permission alone (Menu.vue) — never
// by profile. Mirroring that same DENY > ALLOW > role priority here gives a
// live, honest preview of what the account can actually reach, independent
// of which profile happens to be selected.
const MENU_ACCESS_AREAS = [
    { key: 'care.update', label: 'Soins' },
    { key: 'maternity.view', label: 'Maternité' },
    { key: 'anesthesia.view', label: 'Anesthésie' },
    { key: 'surgery.view', label: 'Chirurgie' },
];
const permissionIdByName = computed(() => new Map(
    props.permissionCatalog.map((permission) => [permission.name, permission.id]),
));
const effectiveMenuAccess = computed(() => MENU_ACCESS_AREAS.map((area) => {
    const permissionId = permissionIdByName.value.get(area.key);
    const override = permissionId !== undefined ? permissionProvenance[permissionId] : null;
    const granted = override ? override.effect === 'allow' : selectedRolePermissions.value.has(area.key);

    return { ...area, granted, source: override?.source ?? (granted ? 'ROLE' : null) };
}));
const oldProfileName = computed(() => editingUser.value?.professional_profile?.name ?? 'Aucun profil');
const newProfileName = computed(() => selectedProfile.value?.name ?? 'Aucun profil');

const groupedPermissions = computed(() => {
    const groups = {};

    for (const permission of props.permissionCatalog) {
        (groups[permission.module] ??= []).push(permission);
    }

    return groups;
});

const moduleLabels = {
    users: 'Utilisateurs',
    roles: 'Rôles',
    permissions: 'Permissions',
    super_admin: 'Super Administration',
    sites: 'Sites',
    reports: 'Rapports financiers',
    settings: 'Paramètres',
    audit: 'Audit',
    api: 'Intégrations API',
    employees: 'Employés et RH',
    contracts: 'Contrats',
    attendance: 'Présences',
    leave: 'Congés',
    planning: 'Planning',
    logistics: 'Logistique',
    administrative_stock: 'Stock administratif',
    equipment: 'Équipements',
    guarding: 'Gardiennage',
    hr_reports: 'Rapports RH',
    catalog: 'Référentiels & tarifs',
    patients: 'Patients',
    episodes: 'Passages',
    billing: 'Facturation',
    payments: 'Paiements',
    cash: 'Caisse',
    receipts: 'Reçus',
    consultations: 'Consultations',
    diagnoses: 'Diagnostics',
    prescriptions: 'Ordonnances',
    pharmacy: 'Pharmacie',
    medicines: 'Médicaments',
    stock: 'Stock pharmacie',
    care: 'Soins',
    vitals: 'Constantes',
    medical_orders: 'Ordres médicaux',
    maternity: 'Maternité',
    anesthesia: 'Anesthésie',
    surgery: 'Chirurgie',
};

const initials = (name) => {
    const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    return `${parts[0]?.[0] ?? ''}${parts.length > 1 ? parts.at(-1)[0] : ''}`.toUpperCase() || 'UT';
};

const formatDateTime = (value) => {
    if (!value) return 'Jamais';
    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
};

const submitFilters = () => {
    router.get('/administration/users', {
        q: query.value || undefined,
        status: statusFilter.value,
        role: roleFilter.value || undefined,
    }, { preserveState: true, replace: true });
};

const resetPermissionEffects = (overrides = []) => {
    for (const key of Object.keys(permissionEffects)) delete permissionEffects[key];
    for (const key of Object.keys(permissionProvenance)) delete permissionProvenance[key];
    for (const override of overrides) {
        permissionEffects[override.permission_id] = override.effect;
        permissionProvenance[override.permission_id] = {
            source: override.source ?? 'MANUAL',
            source_profile_id: override.source_profile_id ?? null,
            source_profile_name: override.source_profile_name ?? null,
        };
    }
};

const openCreate = () => {
    editingUser.value = null;
    form.reset();
    form.clearErrors();
    form.role_id = props.roles.find((role) => role.code !== 'SUPER_ADMIN')?.id ?? props.roles[0]?.id ?? '';
    form.professional_profile_id = '';
    resetPermissionEffects();
    originalProfileOverrides.value = [];
    form.sync_profile_permissions = false;
    prefilled.value = { name: '', email: '' };
    formOpen.value = true;
};

const openEdit = (user) => {
    editingUser.value = user;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.role_id = user.role?.id ?? '';
    form.professional_profile_id = user.professional_profile?.id ?? '';
    form.password = '';
    form.password_confirmation = '';
    resetPermissionEffects(user.permission_overrides);
    originalProfileOverrides.value = (user.permission_overrides ?? []).filter((override) => override.source === 'PROFILE');
    form.sync_profile_permissions = false;
    form.account_kind = user.account_kind ?? '';
    form.employee_uuid = user.employee?.uuid ?? '';
    prefilled.value = { name: '', email: '' };
    formOpen.value = true;
};

const closeForm = () => {
    if (form.processing) return;
    formOpen.value = false;
    editingUser.value = null;
    form.reset();
    form.clearErrors();
    resetPermissionEffects();
    originalProfileOverrides.value = [];
};

const serializeOverrides = () => Object.entries(permissionEffects)
    .filter(([permissionId, effect]) => (effect === 'allow' || effect === 'deny')
        && permissionProvenance[permissionId]?.source !== 'PROFILE')
    .map(([permissionId, effect]) => ({ permission_id: Number(permissionId), effect }));

const onRoleChange = () => {
    form.professional_profile_id = '';
    onProfileChange();
};

const removeProfilePreview = () => {
    for (const [permissionId, provenance] of Object.entries(permissionProvenance)) {
        if (provenance?.source !== 'PROFILE') continue;
        delete permissionEffects[permissionId];
        delete permissionProvenance[permissionId];
    }
};

const onProfileChange = () => {
    removeProfilePreview();
    form.sync_profile_permissions = false;

    if (Number(form.professional_profile_id) !== Number(editingUser.value?.professional_profile?.id ?? 0)) {
        // Choosing a different profile used to clear the rights and stage
        // nothing, so saving produced an account that carried its new job
        // title with none of its access — a Sage-femme without Maternité.
        // The recommendations are now staged straight away: still explicit
        // and adjustable before saving (the checkbox stays visible, and
        // applyProfileRecommendations never overwrites a MANUAL decision),
        // but the obvious outcome no longer needs a second discovery.
        applyProfileRecommendations();

        return;
    }

    for (const override of originalProfileOverrides.value) {
        if (permissionProvenance[override.permission_id]?.source === 'MANUAL') continue;
        permissionEffects[override.permission_id] = override.effect;
        permissionProvenance[override.permission_id] = {
            source: 'PROFILE',
            source_profile_id: override.source_profile_id,
            source_profile_name: override.source_profile_name,
        };
    }
};

const applyProfileRecommendations = () => {
    if (!canAssignPermissions.value || !selectedProfile.value) return;

    removeProfilePreview();
    for (const permission of selectedProfile.value.recommended_permissions ?? []) {
        if (permissionProvenance[permission.id]?.source === 'MANUAL') continue;
        permissionEffects[permission.id] = 'allow';
        permissionProvenance[permission.id] = {
            source: 'PROFILE',
            source_profile_id: selectedProfile.value.id,
            source_profile_name: selectedProfile.value.name,
        };
    }
    form.sync_profile_permissions = true;
};

const markPermissionManual = (permissionId) => {
    const effect = permissionEffects[permissionId];
    if (effect !== 'allow' && effect !== 'deny') {
        delete permissionProvenance[permissionId];
        return;
    }

    permissionProvenance[permissionId] = {
        source: 'MANUAL',
        source_profile_id: null,
        source_profile_name: null,
    };
};

const convertPermissionToManual = (permissionId) => {
    if (!permissionEffects[permissionId]) return;
    markPermissionManual(permissionId);
};

const roleRequiresProfile = (roleId) => {
    const role = props.roles.find((item) => Number(item.id) === Number(roleId));
    return Boolean(role?.profiles?.length);
};

/**
 * Recommendations of an account's profile that it does not actually hold.
 * Covers both the account saved without applying them and one whose profile
 * was changed outside the app (ADR-033 calls direct SQL unsupported — this
 * at least stops it from staying invisible).
 */
const missingProfileRights = (user) => {
    if (! user.professional_profile) return [];

    // Profiles are nested under their role, never a flat list.
    const profile = props.roles
        .flatMap((role) => role.profiles ?? [])
        .find((item) => Number(item.id) === Number(user.professional_profile.id));
    const held = new Set((user.permission_overrides ?? [])
        .filter((override) => override.effect === 'allow')
        .map((override) => Number(override.permission_id)));

    return (profile?.recommended_permissions ?? [])
        .filter((permission) => ! held.has(Number(permission.id)));
};

// A profile whose recommended permissions aren't reflected yet — freshly
// picked and never applied, or already broken on arrival (e.g. edited
// directly in the database) — must never save silently as a bare label. The
// admin is stopped and made to choose explicitly, which is also the only
// thing that can catch a mismatch that didn't come from this form at all.
const needsProfileConfirmation = computed(() => (
    canAssignPermissions.value
    && !editingUser.value?.is_current
    && Boolean(selectedProfile.value?.recommended_permissions?.length)
    && !profileRecommendationsApplied.value
));
const showProfileConfirm = ref(false);

const performSubmit = () => {
    form.permission_overrides = serializeOverrides();
    form.transform((data) => {
        const payload = { ...data };

        if (!canAssignPermissions.value || editingUser.value?.is_current) {
            delete payload.permission_overrides;
            delete payload.sync_profile_permissions;
        }

        // Un compte externe n'envoie aucune fiche ; un choix absent n'envoie rien.
        payload.employee_uuid = payload.account_kind === 'STAFF' ? payload.employee_uuid : null;
        if (! payload.account_kind) {
            delete payload.account_kind;
            delete payload.employee_uuid;
        }

        return payload;
    });

    const options = {
        preserveScroll: true,
        onSuccess: closeForm,
    };

    if (editingUser.value) {
        form.put(`/administration/users/${editingUser.value.uuid}`, options);
        return;
    }

    form.post('/administration/users', options);
};

const submitUser = () => {
    if (needsProfileConfirmation.value) {
        showProfileConfirm.value = true;
        return;
    }
    performSubmit();
};
const cancelProfileConfirm = () => { showProfileConfirm.value = false; };
const confirmApplyAndSubmit = () => {
    applyProfileRecommendations();
    showProfileConfirm.value = false;
    performSubmit();
};
const confirmSkipAndSubmit = () => {
    showProfileConfirm.value = false;
    performSubmit();
};

const openDeactivate = (user) => {
    deactivateTarget.value = user;
    deactivationForm.reset();
    deactivationForm.clearErrors();
};

const closeDeactivate = () => {
    if (deactivationForm.processing) return;
    deactivateTarget.value = null;
    deactivationForm.reset();
    deactivationForm.clearErrors();
};

const confirmDeactivate = () => {
    deactivationForm.post(`/administration/users/${deactivateTarget.value.uuid}/deactivate`, {
        preserveScroll: true,
        onSuccess: closeDeactivate,
    });
};

const activate = (user) => {
    router.post(`/administration/users/${user.uuid}/activate`, {}, { preserveScroll: true });
};

const canManage = (user) => user.role?.code !== 'SUPER_ADMIN' || can('users.assign_super_admin');
</script>

<template>
    <Head title="Utilisateurs" />

    <div class="w-full space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                    <span>Administration</span>
                    <ChevronRight class="h-3.5 w-3.5" />
                    <span>Accès</span>
                </div>
                <h1 class="mt-1 font-heading text-2xl font-bold text-foreground">Utilisateurs et accès</h1>
                <p class="mt-1 text-sm text-muted-foreground">Comptes locaux du site {{ page.props.site?.name }} et droits opérationnels.</p>
            </div>

            <Button v-if="canCreate" size="rg" variant="primary" type="button" @click="openCreate">
                <UserPlus class="h-4.5 w-4.5" />
                Nouvel utilisateur
            </Button>
        </div>

        <section class="overflow-hidden rounded-lg border border-border bg-card">
            <div class="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center lg:justify-between lg:px-5">
                <form class="relative w-full lg:max-w-md" role="search" @submit.prevent="submitFilters">
                    <Input v-model="query" class="ps-9" type="search" placeholder="Nom ou adresse email" autocomplete="off" />
                    <button type="submit" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-muted-foreground" aria-label="Rechercher">
                        <Search class="h-4.5 w-4.5" />
                    </button>
                </form>

                <div class="grid grid-cols-2 gap-2 sm:flex">
                    <label class="sr-only" for="users-role-filter">Filtrer par rôle</label>
                    <select id="users-role-filter" v-model="roleFilter" class="h-9 min-w-44 rounded border-border bg-card py-1.5 ps-3 pe-8 text-sm text-muted-foreground focus:border-primary focus:ring-ring/25" @change="submitFilters">
                        <option value="">Tous les rôles</option>
                        <option v-for="role in roles" :key="role.id" :value="role.code">{{ role.name }}</option>
                    </select>

                    <label class="sr-only" for="users-status-filter">Filtrer par état</label>
                    <select id="users-status-filter" v-model="statusFilter" class="h-9 min-w-36 rounded border-border bg-card py-1.5 ps-3 pe-8 text-sm text-muted-foreground focus:border-primary focus:ring-ring/25" @change="submitFilters">
                        <option value="active">Actifs</option>
                        <option value="inactive">Désactivés</option>
                        <option value="all">Tous</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] border-collapse">
                    <caption class="sr-only">Liste des utilisateurs du site</caption>
                    <thead>
                        <tr class="bg-muted/70 /40">
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Utilisateur</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Rôle</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Dernière connexion</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">État</th>
                            <th class="border-b border-border px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users.data" :key="user.uuid" class="transition-colors hover:bg-muted/70">
                            <td class="border-b border-border px-5 py-3">
                                <div class="flex min-w-[260px] items-center gap-3">
                                    <Avatar size="sm" variant="slate-pale" :text="initials(user.name)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="truncate text-sm font-bold text-foreground">{{ user.name }}</span>
                                            <span v-if="user.is_current" class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">Vous</span>
                                        </div>
                                        <span class="mt-0.5 block truncate text-xs text-muted-foreground">{{ user.email }}</span>
                                        <span v-if="accountKindLabel(user)" class="mt-1 flex items-center gap-1 text-[11px] text-muted-foreground" :title="user.employee ? `Fiche ${user.employee.employee_number}` : 'Aucune fiche employé'">
                                            <component :is="user.employee ? IdCard : UserRound" class="h-3 w-3" />{{ accountKindLabel(user) }}<template v-if="user.employee"> · <span class="font-mono">{{ user.employee.employee_number }}</span></template>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="border-b border-border px-5 py-3">
                                <p class="text-sm font-medium text-muted-foreground">{{ user.role?.name ?? 'Aucun rôle' }}</p>
                                <p v-if="user.professional_profile" class="mt-0.5 text-xs text-muted-foreground">
                                    {{ user.professional_profile.name }}
                                </p>
                                <!-- A profile whose recommendations were never applied leaves an
                                     account carrying its job title with none of its access. That
                                     used to be invisible until someone logged in and found the
                                     menu missing. -->
                                <p v-if="missingProfileRights(user).length" class="mt-1 inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-bold text-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
                                    <CircleAlert class="h-4 w-4" />Droits du profil non appliqués ({{ missingProfileRights(user).length }})
                                </p>
                                <p v-else-if="roleRequiresProfile(user.role?.id)" class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                    Profil métier à définir
                                </p>
                                <p v-if="user.permission_overrides.length" class="mt-0.5 text-xs text-muted-foreground">{{ user.permission_overrides.length }} exception{{ user.permission_overrides.length > 1 ? 's' : '' }} individuelle{{ user.permission_overrides.length > 1 ? 's' : '' }}</p>
                            </td>
                            <td class="border-b border-border px-5 py-3 text-sm text-muted-foreground">{{ formatDateTime(user.last_login_at) }}</td>
                            <td class="border-b border-border px-5 py-3">
                                <span v-if="user.active" class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Actif</span>
                                <div v-else>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><span class="h-1.5 w-1.5 rounded-full bg-muted-foreground"></span> Désactivé</span>
                                    <p v-if="user.deactivation_reason" class="mt-1 max-w-xs truncate text-xs text-muted-foreground" :title="user.deactivation_reason">{{ user.deactivation_reason }}</p>
                                </div>
                            </td>
                            <td class="border-b border-border px-5 py-3 text-end">
                                <div v-if="canManage(user)" class="inline-flex items-center gap-1">
                                    <button v-if="can('users.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:border-border hover:text-foreground dark:hover:text-white" :aria-label="`Modifier ${user.name}`" title="Modifier" @click="openEdit(user)">
                                        <Pencil class="h-4 w-4" />
                                    </button>
                                    <button v-if="user.active && can('users.deactivate') && !user.is_current" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:border-red-300 hover:text-red-600" :aria-label="`Désactiver ${user.name}`" title="Désactiver" @click="openDeactivate(user)">
                                        <Lock class="h-4 w-4" />
                                    </button>
                                    <button v-if="!user.active && can('users.activate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:border-emerald-300 hover:text-emerald-700" :aria-label="`Réactiver ${user.name}`" title="Réactiver" @click="activate(user)">
                                        <LockOpen class="h-4 w-4" />
                                    </button>
                                </div>
                                <span v-else class="text-xs text-muted-foreground">Protégé</span>
                            </td>
                        </tr>

                        <tr v-if="users.data.length === 0">
                            <td colspan="5" class="px-5 py-12 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><Users class="h-5 w-5" /></span>
                                <p class="mt-3 text-sm font-medium text-muted-foreground">Aucun utilisateur trouvé</p>
                                <p class="mt-1 text-xs text-muted-foreground">Modifiez les filtres ou créez un compte autorisé.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="users.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-4">
                <span class="text-xs text-muted-foreground">Page {{ users.current_page }} sur {{ users.last_page }}</span>
                <div class="flex flex-wrap items-center gap-1">
                    <template v-for="(link, index) in users.links" :key="index">
                        <Link v-if="link.url" :href="link.url" preserve-state :class="['rounded px-3 py-1.5 text-sm', link.active ? 'bg-primary text-white' : 'text-muted-foreground hover:bg-muted dark:hover:bg-muted']" v-html="link.label" />
                        <span v-else class="rounded px-3 py-1.5 text-sm text-muted-foreground" v-html="link.label" />
                    </template>
                </div>
            </div>
        </section>

        <aside class="rounded-lg border border-border bg-card p-4 sm:px-5">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground"><ShieldCheck class="h-4.5 w-4.5" /></span>
                <div>
                    <h2 class="text-sm font-bold text-foreground">Un accès propre à chaque compte</h2>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Le rôle fournit uniquement le socle commun du service. Le profil précise le métier principal ; ses droits recommandés doivent être appliqués au compte puis peuvent être adaptés individuellement. Toute attribution est auditée. Un compte n’est jamais supprimé : il est désactivé pour préserver l’historique.</p>
                </div>
            </div>
        </aside>

        <div v-if="formOpen" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeForm">
            <section class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg border border-border bg-card shadow-xl" role="dialog" aria-modal="true" aria-labelledby="user-form-title">
                <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                    <div>
                        <h2 id="user-form-title" class="font-heading text-lg font-bold text-foreground">{{ isEditing ? 'Modifier l’utilisateur' : 'Créer un utilisateur' }}</h2>
                        <p class="mt-1 text-xs text-muted-foreground">Un compte nominatif, un rôle métier et uniquement les permissions nécessaires.</p>
                    </div>
                    <button type="button" class="text-muted-foreground hover:text-muted-foreground dark:hover:text-white" aria-label="Fermer" @click="closeForm"><X class="h-5 w-5" /></button>
                </header>

                <form class="overflow-y-auto" @submit.prevent="submitUser">
                    <div class="space-y-6 p-5">
                        <div v-if="form.errors.user" class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                            {{ form.errors.user }}
                        </div>

                        <AccountKindPicker
                            v-model:kind="form.account_kind"
                            v-model:employee-uuid="form.employee_uuid"
                            :employees="employees"
                            :current-user-uuid="editingUser?.uuid ?? ''"
                            :errors="form.errors"
                            :create-employee-href="createEmployeeHref"
                            @pick="onEmployeePick"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <template v-if="identityVisible">
                                <div>
                                    <label for="user_name" class="mb-1.5 block text-sm font-medium text-foreground">Nom complet <span class="text-red-500">*</span></label>
                                    <Input id="user_name" v-model="form.name" autocomplete="name" :aria-invalid="Boolean(form.errors.name)" />
                                    <FormError v-if="form.errors.name">{{ form.errors.name }}</FormError>
                                    <p v-else-if="fromStaffRecord && form.name === prefilled.name" class="mt-1.5 flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-300"><Check class="h-3.5 w-3.5" />Repris de la fiche RH — modifiable.</p>
                                </div>
                                <div>
                                    <label for="user_email" class="mb-1.5 block text-sm font-medium text-foreground">Email professionnel <span class="text-red-500">*</span></label>
                                    <Input id="user_email" v-model="form.email" type="email" autocomplete="off" :aria-invalid="Boolean(form.errors.email)" />
                                    <FormError v-if="form.errors.email">{{ form.errors.email }}</FormError>
                                    <p v-else-if="fromStaffRecord && prefilled.email === '' && form.email.trim() === ''" class="mt-1.5 text-xs text-amber-700 dark:text-amber-300">La fiche RH n’a pas d’email : saisissez l’adresse professionnelle.</p>
                                    <p v-else-if="fromStaffRecord && prefilled.email !== '' && form.email === prefilled.email" class="mt-1.5 flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-300"><Check class="h-3.5 w-3.5" />Repris de la fiche RH.</p>
                                </div>
                            </template>
                            <div :class="selectedRoleProfiles.length ? '' : 'sm:col-span-2'">
                                <label for="user_role" class="mb-1.5 block text-sm font-medium text-foreground">Rôle métier <span class="text-red-500">*</span></label>
                                <select id="user_role" v-model="form.role_id" :disabled="editingUser?.is_current" class="block h-9 w-full rounded border-border bg-card py-1.5 ps-3 pe-9 text-sm text-foreground focus:border-primary focus:ring-ring/25 disabled:bg-muted disabled:text-muted-foreground" :aria-invalid="Boolean(form.errors.role_id)" @change="onRoleChange">
                                    <option disabled value="">Choisir un rôle</option>
                                    <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                                </select>
                                <p v-if="editingUser?.is_current" class="mt-1.5 text-xs text-muted-foreground">Votre propre rôle ne peut pas être modifié depuis cette session.</p>
                                <FormError v-if="form.errors.role_id">{{ form.errors.role_id }}</FormError>
                            </div>

                            <div v-if="selectedRoleProfiles.length">
                                <label for="user_professional_profile" class="mb-1.5 block text-sm font-medium text-foreground">Profil professionnel <span class="text-red-500">*</span></label>
                                <select id="user_professional_profile" v-model="form.professional_profile_id" :disabled="editingUser?.is_current" class="block h-9 w-full rounded border-border bg-card py-1.5 ps-3 pe-9 text-sm text-foreground focus:border-primary focus:ring-ring/25 disabled:bg-muted disabled:text-muted-foreground" :aria-invalid="Boolean(form.errors.professional_profile_id)" @change="onProfileChange">
                                    <option disabled value="">Choisir un profil</option>
                                    <option v-for="profile in selectedRoleProfiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
                                </select>
                                <FormError v-if="form.errors.professional_profile_id">{{ form.errors.professional_profile_id }}</FormError>
                            </div>

                            <div v-if="selectedProfile" class="sm:col-span-2 rounded border border-border bg-muted/70 px-4 py-3 /40">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-foreground">{{ selectedProfile.name }}</p>
                                        <p class="mt-1 text-xs leading-5 text-muted-foreground">{{ selectedProfile.description }}</p>
                                        <p class="mt-1 text-[11px] text-muted-foreground">Le profil classe le métier ; il ne donne aucun droit automatiquement.</p>
                                    </div>
                                    <Button
                                        v-if="canAssignPermissions && !editingUser?.is_current && selectedProfile.recommended_permissions?.length"
                                        :size="profileRecommendationsApplied ? 'sm' : 'rg'"
                                        :variant="profileRecommendationsApplied ? 'white-outline' : 'primary'"
                                        type="button"
                                        :class="['shrink-0', !profileRecommendationsApplied && 'ring-2 ring-ring/25 ']"
                                        @click="applyProfileRecommendations"
                                    >
                                        <component :is="profileRecommendationsApplied ? Check : ShieldCheck" class="h-4 w-4" />
                                        {{ profileRecommendationsApplied ? 'Permissions appliquées — réappliquer' : `Appliquer les permissions du profil ${selectedProfile.name}` }}
                                    </Button>
                                </div>
                                <p v-if="selectedProfile.recommended_permissions?.length && !profileRecommendationsApplied" class="mt-2 text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                                    ⚠ {{ selectedProfile.recommended_permissions.length }} droit{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} recommandé{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }}, pas encore appliqué{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} — sans clic ci-dessus, ce profil restera une étiquette sans accès réel.
                                </p>
                                <p v-else-if="selectedProfile.recommended_permissions?.length" class="mt-2 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">
                                    ✓ {{ selectedProfile.recommended_permissions.length }} droit{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} appliqué{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} individuellement, effectif{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} après enregistrement.
                                </p>
                                <p v-else class="mt-2 text-[11px] text-muted-foreground">Aucun droit supplémentaire recommandé : le socle du rôle reste applicable.</p>
                                <div v-if="profileChanged" class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                                    <p class="font-bold">Le profil professionnel a changé.</p>
                                    <p>{{ oldProfileName }} → {{ newProfileName }}</p>
                                    <p class="mt-1">Les permissions provenant de l’ancien profil seront retirées à l’enregistrement. Si vous appliquez le nouveau profil, ses recommandations seront ajoutées ; les permissions manuelles seront conservées.</p>
                                </div>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <span v-for="area in effectiveMenuAccess" :key="area.key" :class="['inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs font-bold', area.granted ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-muted text-muted-foreground ']">
                                    <component :is="area.granted ? CircleCheck : CircleX" class="h-3.5 w-3.5" />
                                    {{ area.label }}
                                </span>
                            </div>
                        </div>

                        <div class="border-t border-border pt-5">
                            <h3 class="text-sm font-bold text-foreground">{{ isEditing ? 'Nouveau mot de passe' : 'Mot de passe initial' }}</h3>
                            <p class="mt-1 text-xs text-muted-foreground">{{ isEditing ? 'Laissez vide pour conserver le mot de passe actuel.' : 'Au moins 12 caractères avec majuscule, minuscule, chiffre et symbole.' }}</p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="user_password" class="mb-1.5 block text-sm font-medium text-foreground">Mot de passe <span v-if="!isEditing" class="text-red-500">*</span></label>
                                    <Input id="user_password" v-model="form.password" type="password" autocomplete="new-password" :aria-invalid="Boolean(form.errors.password)" />
                                    <FormError v-if="form.errors.password">{{ form.errors.password }}</FormError>
                                </div>
                                <div>
                                    <label for="user_password_confirmation" class="mb-1.5 block text-sm font-medium text-foreground">Confirmation <span v-if="!isEditing" class="text-red-500">*</span></label>
                                    <Input id="user_password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                                </div>
                            </div>
                        </div>

                        <div v-if="canAssignPermissions && !editingUser?.is_current && permissionCatalog.length" class="border-t border-border pt-5">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-bold text-foreground">Exceptions individuelles</h3>
                                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Conservez « Hérité du rôle » par défaut. Une interdiction individuelle est prioritaire sur une autorisation.</p>
                                </div>
                                <span v-if="overrideCount" class="shrink-0 rounded bg-muted px-2 py-1 text-xs font-medium text-muted-foreground">{{ overrideCount }} exception{{ overrideCount > 1 ? 's' : '' }}</span>
                            </div>

                            <FormError v-if="form.errors.permission_overrides">{{ form.errors.permission_overrides }}</FormError>

                            <div class="mt-4 space-y-2">
                                <details v-for="(permissions, module) in groupedPermissions" :key="module" class="group rounded border border-border">
                                    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium text-muted-foreground">
                                        <span>{{ moduleLabels[module] ?? module }}</span>
                                        <span class="flex items-center gap-2 text-xs font-normal text-muted-foreground">{{ permissions.length }} droits <ChevronRight class="transition-transform group-open:rotate-90 h-4 w-4" /></span>
                                    </summary>
                                    <div class="border-t border-border">
                                        <div v-for="permission in permissions" :key="permission.id" class="grid gap-2 border-b border-border px-4 py-3 last:border-b-0 sm:grid-cols-[1fr_180px] sm:items-center">
                                            <div>
                                                <p class="text-xs font-medium text-muted-foreground">{{ permission.label }}</p>
                                                <p class="mt-0.5 text-[11px] text-muted-foreground">{{ permission.name }} · {{ selectedRolePermissions.has(permission.name) ? 'autorisé par le rôle' : 'non autorisé par le rôle' }}</p>
                                                <div v-if="permissionProvenance[permission.id]" class="mt-1 flex flex-wrap items-center gap-2 text-[10px] font-medium">
                                                    <span :class="permissionProvenance[permission.id].source === 'PROFILE' ? 'text-primary' : 'text-muted-foreground'">
                                                        {{ permissionProvenance[permission.id].source === 'PROFILE' ? `Source : Profil ${permissionProvenance[permission.id].source_profile_name ?? selectedProfile?.name ?? 'professionnel'}` : 'Source : Manuel' }}
                                                    </span>
                                                    <button v-if="permissionProvenance[permission.id].source === 'PROFILE'" type="button" class="text-muted-foreground underline hover:text-muted-foreground" @click="convertPermissionToManual(permission.id)">Conserver manuellement</button>
                                                </div>
                                            </div>
                                            <select v-model="permissionEffects[permission.id]" class="h-8 rounded border-border bg-card py-1 ps-2 pe-7 text-xs text-muted-foreground focus:border-primary focus:ring-ring/25" @change="markPermissionManual(permission.id)">
                                                <option value="">Hérité du rôle</option>
                                                <option value="allow">Autoriser</option>
                                                <option value="deny">Refuser</option>
                                            </select>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>

                    <footer class="flex flex-col-reverse gap-3 border-t border-border px-5 py-4 sm:flex-row sm:justify-end">
                        <Button size="rg" variant="white-outline" type="button" :disabled="form.processing" @click="closeForm">Annuler</Button>
                        <Button size="rg" variant="primary" type="submit" :disabled="form.processing || ! form.account_kind || (form.account_kind === 'STAFF' && ! form.employee_uuid)">
                            <Check class="h-4.5 w-4.5" />
                            {{ form.processing ? 'Enregistrement…' : (isEditing ? 'Enregistrer' : 'Créer le compte') }}
                        </Button>
                    </footer>
                </form>
            </section>
        </div>

        <div v-if="showProfileConfirm" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="cancelProfileConfirm">
            <section class="w-full max-w-md rounded-lg border border-border bg-card p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="profile-confirm-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"><CircleAlert class="h-5 w-5" /></span>
                    <div>
                        <h2 id="profile-confirm-title" class="font-heading text-lg font-bold text-foreground">Permissions du profil {{ selectedProfile?.name }} non appliquées</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">Sans les appliquer, ce profil reste une étiquette : le compte n’accédera à aucun menu spécifique ({{ selectedProfile?.recommended_permissions?.length }} droit{{ selectedProfile?.recommended_permissions?.length > 1 ? 's' : '' }} recommandé{{ selectedProfile?.recommended_permissions?.length > 1 ? 's' : '' }} resterai{{ selectedProfile?.recommended_permissions?.length > 1 ? 'ent' : 't' }} sans effet).</p>
                    </div>
                </div>
                <div class="mt-6 flex flex-col-reverse gap-2">
                    <Button size="rg" variant="white-outline" type="button" @click="cancelProfileConfirm">Annuler, revoir le formulaire</Button>
                    <Button size="rg" variant="secondary" type="button" @click="confirmSkipAndSubmit">Enregistrer sans les droits du profil</Button>
                    <Button size="rg" variant="primary" type="button" @click="confirmApplyAndSubmit"><ShieldCheck class="h-4.5 w-4.5" />Appliquer et enregistrer</Button>
                </div>
            </section>
        </div>

        <div v-if="deactivateTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeDeactivate">
            <section class="w-full max-w-md rounded-lg border border-border bg-card p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="deactivate-user-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"><Lock class="h-5 w-5" /></span>
                    <div>
                        <h2 id="deactivate-user-title" class="font-heading text-lg font-bold text-foreground">Désactiver {{ deactivateTarget.name }} ?</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">La connexion sera bloquée et les sessions ouvertes seront révoquées. L’historique reste conservé.</p>
                    </div>
                </div>
                <div class="mt-5">
                    <label for="deactivation_reason" class="mb-1.5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></label>
                    <textarea id="deactivation_reason" v-model="deactivationForm.reason" rows="3" autofocus placeholder="Ex. fin de contrat ou changement d’affectation" class="block w-full resize-y rounded border border-border bg-card px-4 py-2 text-sm text-foreground outline-none transition-all placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-ring/25"></textarea>
                    <FormError v-if="deactivationForm.errors.reason">{{ deactivationForm.errors.reason }}</FormError>
                    <FormError v-if="deactivationForm.errors.user">{{ deactivationForm.errors.user }}</FormError>
                </div>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" :disabled="deactivationForm.processing" @click="closeDeactivate">Annuler</Button>
                    <Button size="rg" variant="secondary" type="button" :disabled="deactivationForm.processing" @click="confirmDeactivate"><Lock class="h-4.5 w-4.5" />{{ deactivationForm.processing ? 'Désactivation…' : 'Désactiver le compte' }}</Button>
                </div>
            </section>
        </div>
    </div>
</template>
