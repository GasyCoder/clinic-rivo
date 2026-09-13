<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import PermissionAccessRow from '@/Components/Rbac/PermissionAccessRow.vue';
import ResizableSplit from '@/Components/UI/ResizableSplit.vue';
import { PERMISSION_DOMAINS, permissionCategoryDomain, permissionCategoryLabel } from '@/utilities/permissionCategories';
import { usePermissions } from '@/composables/usePermissions';
import {
    isPermissionEffectivelyGranted,
    isSensitivePermission,
    normalizePermissionText as normalize,
    permissionEffect as resolvePermissionEffect,
    permissionMatchesFilter as matchesPermissionFilter,
    permissionMatchesSearch,
    roleGrantsPermission,
    summarizePermissionWorkspace,
} from '@/utilities/permissionWorkspace';

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
const permissionEffects = reactive({});
const permissionProvenance = reactive({});
const originalProfileOverrides = ref([]);
const deactivateTargets = ref([]);
const selectedUuids = ref(new Set());
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const steps = [
    { n: 1, label: 'Informations', icon: 'user-add' },
    { n: 2, label: 'Rôle', icon: 'briefcase' },
    { n: 3, label: 'Permissions', icon: 'shield-check' },
];

const form = useForm({
    site_code: selectedSiteCode.value,
    name: '',
    email: '',
    role_id: '',
    professional_profile_id: '',
    password: '',
    password_confirmation: '',
    permission_overrides: [],
    sync_profile_permissions: false,
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
const permissionCatalog = computed(() => selectedSite.value?.data?.permission_catalog ?? []);

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
const selectedRolePermissions = computed(() => new Set(selectedRole.value?.permissions ?? []));
const profileChanged = computed(() => isEditing.value
    && Number(editingUser.value?.professional_profile?.id ?? 0) !== Number(form.professional_profile_id ?? 0));
const oldProfileName = computed(() => editingUser.value?.professional_profile?.name ?? 'Aucun profil');
const newProfileName = computed(() => selectedProfile.value?.name ?? 'Aucun profil');

const permissionSearch = ref('');
const permissionFilter = ref('all');
const permissionMode = ref('simple');
const permissionSort = ref('label');
const selectedPermissionCategory = ref('');
const showAccessPreview = ref(false);
const pendingPermissionChange = ref(null);
const pendingBulkAction = ref(null);
const initialPermissionState = ref([]);


const permissionEffect = (permission) => resolvePermissionEffect(permission, permissionEffects);
const roleGrants = (permission) => roleGrantsPermission(permission, selectedRolePermissions.value);
const effectivelyGranted = (permission) => isPermissionEffectivelyGranted(permission, permissionEffects, selectedRolePermissions.value);

// This is display metadata only. Authorization remains exclusively enforced
// by Laravel. The warning list deliberately errs on the cautious side for
// destructive, identity, security and platform-wide capabilities.
const permissionSourceLabel = (permission) => {
    const provenance = permissionProvenance[permission.id];
    if (!provenance) return '';
    if (provenance.source === 'PROFILE') {
        return `Profil : ${provenance.source_profile_name ?? selectedProfile.value?.name ?? 'professionnel'}`;
    }
    return 'Décision manuelle';
};

const permissionSummary = computed(() => summarizePermissionWorkspace(
    permissionCatalog.value,
    permissionEffects,
    permissionProvenance,
    selectedRolePermissions.value,
));

const searchMatches = (permission) => permissionMatchesSearch(
    permission,
    permissionSearch.value,
    permissionCategoryLabel(permission.module),
);

const permissionCategories = computed(() => {
    const grouped = new Map();
    for (const permission of permissionCatalog.value) {
        if (!grouped.has(permission.module)) grouped.set(permission.module, []);
        grouped.get(permission.module).push(permission);
    }

    return Array.from(grouped.entries()).map(([key, permissions]) => ({
        key,
        label: permissionCategoryLabel(key),
        domain: permissionCategoryDomain(key),
        total: permissions.length,
        visible: permissions.filter(searchMatches).length,
        exceptions: permissions.filter((permission) => permissionEffect(permission) !== '').length,
        sensitive: permissions.filter(isSensitivePermission).length,
    })).sort((left, right) => {
        const domainOrder = domainIndex(left.domain) - domainIndex(right.domain);
        if (domainOrder !== 0) return domainOrder;
        return left.label.localeCompare(right.label, 'fr');
    });
});

const domainIndex = (key) => {
    const index = PERMISSION_DOMAINS.findIndex((domain) => domain.key === key);
    return index === -1 ? PERMISSION_DOMAINS.length : index;
};

/** The category list reads by area of work — Clinique, Pharmacie, Caisse… — not by code. */
const permissionCategoryGroups = computed(() => PERMISSION_DOMAINS
    .map((domain) => ({ ...domain, categories: permissionCategories.value.filter((category) => category.domain === domain.key) }))
    .filter((group) => group.categories.length));

const sensitivePermissionCount = computed(() => permissionCatalog.value.filter(isSensitivePermission).length);

watch(permissionCategories, (categories) => {
    if (selectedPermissionCategory.value === '__sensitive__') return;
    if (!categories.some((category) => category.key === selectedPermissionCategory.value)) {
        selectedPermissionCategory.value = categories[0]?.key ?? '';
    }
}, { immediate: true });

const currentCategoryLabel = computed(() => selectedPermissionCategory.value === '__sensitive__'
    ? 'Permissions sensibles'
    : permissionCategories.value.find((category) => category.key === selectedPermissionCategory.value)?.label ?? 'Permissions');

const permissionMatchesFilter = (permission) => matchesPermissionFilter(
    permission,
    permissionFilter.value,
    permissionEffects,
    selectedRolePermissions.value,
);

const currentPermissions = computed(() => permissionCatalog.value.filter((permission) => {
    const inCategory = selectedPermissionCategory.value === '__sensitive__'
        ? isSensitivePermission(permission)
        : permission.module === selectedPermissionCategory.value;
    return inCategory && searchMatches(permission) && permissionMatchesFilter(permission);
}).sort((left, right) => {
    if (permissionSort.value === 'state') {
        const stateOrder = { deny: 0, allow: 1, '': 2 };
        const difference = stateOrder[permissionEffect(left)] - stateOrder[permissionEffect(right)];
        if (difference !== 0) return difference;
    }
    if (permissionSort.value === 'category') {
        const difference = permissionCategoryLabel(left.module).localeCompare(permissionCategoryLabel(right.module), "fr");
        if (difference !== 0) return difference;
    }
    return left.label.localeCompare(right.label);
}));

const functionalGroup = (permission) => {
    const action = String(permission.name).split('.').at(-1);
    if (['view', 'view_deleted', 'print', 'export'].includes(action)) return 'Consulter et exporter';
    if (['create', 'import'].includes(action)) return 'Créer et importer';
    if (['delete', 'force_delete', 'restore', 'archive', 'unarchive'].includes(action)) return 'Suppression et restauration';
    if (['validate', 'approve', 'reject', 'cancel', 'close', 'open'].includes(action)) return 'Validation et opérations';
    return 'Gérer et mettre à jour';
};

const functionalPermissionGroups = computed(() => {
    const groups = new Map();
    for (const permission of currentPermissions.value) {
        const label = functionalGroup(permission);
        if (!groups.has(label)) groups.set(label, []);
        groups.get(label).push(permission);
    }
    return Array.from(groups.entries()).map(([label, permissions]) => ({ label, permissions }));
});

const snapshotPermissionState = () => permissionCatalog.value.map((permission) => ({
    id: permission.id,
    effect: permissionEffect(permission),
    provenance: permissionProvenance[permission.id] ? { ...permissionProvenance[permission.id] } : null,
}));

const capturePermissionState = () => { initialPermissionState.value = snapshotPermissionState(); };
const permissionChangesCount = computed(() => {
    const initial = new Map(initialPermissionState.value.map((entry) => [String(entry.id), entry]));
    return permissionCatalog.value.filter((permission) => {
        const before = initial.get(String(permission.id));
        const provenance = permissionProvenance[permission.id];
        return (before?.effect ?? '') !== permissionEffect(permission)
            || (before?.provenance?.source ?? '') !== (provenance?.source ?? '');
    }).length;
});
const permissionsDirty = computed(() => permissionChangesCount.value > 0);

const applyPermissionChange = (permission, effect) => {
    permissionEffects[permission.id] = effect;
    if (effect === 'allow' || effect === 'deny') {
        permissionProvenance[permission.id] = { source: 'MANUAL', source_profile_id: null, source_profile_name: null };
    } else {
        delete permissionProvenance[permission.id];
    }
};

const requestPermissionChange = (permission, effect) => {
    if (permissionEffect(permission) === effect) return;
    if (effect === 'allow' && isSensitivePermission(permission)) {
        pendingPermissionChange.value = { permission, effect };
        return;
    }
    applyPermissionChange(permission, effect);
};

const confirmPermissionChange = () => {
    if (!pendingPermissionChange.value) return;
    applyPermissionChange(pendingPermissionChange.value.permission, pendingPermissionChange.value.effect);
    pendingPermissionChange.value = null;
};

const openBulkAction = (effect, permissions = currentPermissions.value, scope = currentCategoryLabel.value) => {
    if (!permissions.length) return;
    pendingBulkAction.value = { effect, permissions: [...permissions], scope };
};

const confirmBulkAction = () => {
    if (!pendingBulkAction.value) return;
    for (const permission of pendingBulkAction.value.permissions) {
        applyPermissionChange(permission, pendingBulkAction.value.effect);
    }
    pendingBulkAction.value = null;
};

const restoreUnsavedPermissions = () => {
    for (const entry of initialPermissionState.value) {
        permissionEffects[entry.id] = entry.effect;
        if (entry.provenance) permissionProvenance[entry.id] = { ...entry.provenance };
        else delete permissionProvenance[entry.id];
    }
};

const effectivePermissionGroups = computed(() => permissionCategories.value.map((category) => {
    const permissions = permissionCatalog.value.filter((permission) => permission.module === category.key);
    return {
        ...category,
        allowed: permissions.filter(effectivelyGranted),
        denied: permissions.filter((permission) => !effectivelyGranted(permission)),
    };
}));

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
    editingRole.value = null;
    clearSelection();
};

const submitFilters = () => {
    clearSelection();
    router.get('/super-admin/workspaces/roles', {
        search: query.value || undefined,
        status: statusFilter.value,
        role: roleFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const resetPermissionEffects = (overrides = []) => {
    for (const key of Object.keys(permissionEffects)) delete permissionEffects[key];
    for (const key of Object.keys(permissionProvenance)) delete permissionProvenance[key];
    // Every permission receives the explicit neutral state used by the
    // segmented control: no user override means inheritance from the role.
    for (const permission of permissionCatalog.value) permissionEffects[permission.id] = '';
    for (const override of overrides) {
        permissionEffects[override.permission_id] = override.effect;
        permissionProvenance[override.permission_id] = {
            source: override.source ?? 'MANUAL',
            source_profile_id: override.source_profile_id ?? null,
            source_profile_name: override.source_profile_name ?? null,
        };
    }
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
    resetPermissionEffects();
    originalProfileOverrides.value = [];
    form.sync_profile_permissions = false;
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    permissionSearch.value = '';
    permissionFilter.value = 'all';
    permissionMode.value = 'simple';
    permissionSort.value = 'label';
    selectedPermissionCategory.value = permissionCategories.value[0]?.key ?? '';
    capturePermissionState();
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
    resetPermissionEffects(user.permission_overrides);
    originalProfileOverrides.value = (user.permission_overrides ?? []).filter((override) => override.source === 'PROFILE');
    form.sync_profile_permissions = false;
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    permissionSearch.value = '';
    permissionFilter.value = 'all';
    permissionMode.value = 'simple';
    permissionSort.value = 'label';
    selectedPermissionCategory.value = permissionCategories.value.find((category) => category.exceptions)?.key
        ?? permissionCategories.value[0]?.key
        ?? '';
    capturePermissionState();
    step.value = 1;
    maxStepReached.value = 3;
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
    if (permissionsDirty.value) {
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
    if (view.value === 'list' || !permissionsDirty.value) return;
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
    resetPermissionEffects();
    originalProfileOverrides.value = [];
    initialPermissionState.value = [];
    showAccessPreview.value = false;
    pendingPermissionChange.value = null;
    pendingBulkAction.value = null;
};

// Inertia fires onSuccess before onFinish, so form.processing is still true
// at that point — closeForm()'s guard (there to stop Annuler/backdrop from
// closing mid-submit) would otherwise block a real success from ever
// closing the wizard. A finished submission always gets to close.
const dismissForm = () => {
    view.value = 'list';
    editingUser.value = null;
    form.reset();
    form.clearErrors();
    resetPermissionEffects();
    originalProfileOverrides.value = [];
    initialPermissionState.value = [];
    showAccessPreview.value = false;
    pendingPermissionChange.value = null;
    pendingBulkAction.value = null;
};

const goToStep = (n) => {
    if (n <= maxStepReached.value) step.value = n;
};

const nextStep = () => {
    if (step.value === 1 && !step1Valid.value) return;
    if (step.value === 2 && !step2Valid.value) return;

    step.value = Math.min(3, step.value + 1);
    maxStepReached.value = Math.max(maxStepReached.value, step.value);
};

const prevStep = () => { step.value = Math.max(1, step.value - 1); };

const serializeOverrides = () => Object.entries(permissionEffects)
    .filter(([permissionId, effect]) => (effect === 'allow' || effect === 'deny')
        && permissionProvenance[permissionId]?.source !== 'PROFILE')
    .map(([permissionId, effect]) => ({ permission_id: Number(permissionId), effect }));

const removeProfilePreview = () => {
    for (const [permissionId, provenance] of Object.entries(permissionProvenance)) {
        if (provenance?.source !== 'PROFILE') continue;
        permissionEffects[permissionId] = '';
        delete permissionProvenance[permissionId];
    }
};

const onProfileChange = () => {
    removeProfilePreview();
    form.sync_profile_permissions = false;

    if (Number(form.professional_profile_id) !== Number(editingUser.value?.professional_profile?.id ?? 0)) {
        // Choosing a different profile used to clear the rights and stage
        // nothing, so the remote site received sync_profile_permissions=false
        // and saved an account carrying its new job title with none of its
        // access. Staged straight away instead — still explicit, still
        // adjustable, and MANUAL decisions are never overwritten.
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

const selectRole = (roleId) => {
    form.role_id = roleId;
    form.professional_profile_id = '';
    onProfileChange();
};

const selectProfile = (profileId) => {
    form.professional_profile_id = profileId;
    onProfileChange();
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

const roleRequiresProfile = (roleId) => Boolean(roles.value.find((item) => Number(item.id) === Number(roleId))?.profiles?.length);

const submitUser = () => {
    form.permission_overrides = serializeOverrides();
    form.transform((data) => {
        const payload = { ...data };
        if (!canAssignPermissions.value) {
            delete payload.permission_overrides;
            delete payload.sync_profile_permissions;
        }
        return payload;
    });

    const options = { preserveScroll: true, onSuccess: dismissForm };

    if (editingUser.value) {
        form.put(`/super-admin/workspaces/roles/${selectedSiteCode.value}/${editingUser.value.uuid}`, options);
        return;
    }

    form.post('/super-admin/workspaces/roles', options);
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
    '/super-admin/workspaces/roles/bulk/deactivate',
    { preserveScroll: true, onSuccess: dismissDeactivate },
);

const activate = (user) => router.post(
    `/super-admin/workspaces/roles/${selectedSiteCode.value}/${user.uuid}/activate`,
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
        '/super-admin/workspaces/roles/bulk/force-delete',
        { preserveScroll: true, onSuccess: dismissForceDelete },
    );
};

// Role-baseline editor — a SEPARATE capability from the wizard above: this
// edits what a ROLE grants every account by default (previously only
// possible by editing RolePermissionSeeder::GRANTS and redeploying), never
// the per-account allow/deny overrides, which keep applying on top exactly
// as before. SUPER_ADMIN never appears in `roles` (excluded server-side).
const editingRole = ref(null);
const roleDraftIds = ref(new Set());
const roleForm = useForm({ permission_ids: [] });
const rolePermissionSearch = ref('');
const roleCategoryPage = ref(1);
const ROLE_MODULES_PER_PAGE = 6;

const openRoleBaseline = (role) => {
    editingRole.value = role;
    roleDraftIds.value = new Set(
        permissionCatalog.value.filter((permission) => role.permissions.includes(permission.name)).map((permission) => permission.id),
    );
    roleForm.clearErrors();
    rolePermissionSearch.value = '';
    roleCategoryPage.value = 1;
};

const closeRoleBaseline = () => {
    if (roleForm.processing) return;
    editingRole.value = null;
    roleDraftIds.value = new Set();
};

// Same onSuccess/onFinish ordering as dismissForm() above.
const dismissRoleBaseline = () => {
    editingRole.value = null;
    roleDraftIds.value = new Set();
    roleForm.reset();
};

const toggleRolePermission = (id) => {
    const next = new Set(roleDraftIds.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    roleDraftIds.value = next;
};

const toggleModulePermissions = (permissions, checkAll) => {
    const next = new Set(roleDraftIds.value);
    for (const permission of permissions) { if (checkAll) next.add(permission.id); else next.delete(permission.id); }
    roleDraftIds.value = next;
};

const roleFilteredPermissions = computed(() => {
    const term = normalize(rolePermissionSearch.value.trim());
    if (term === '') return permissionCatalog.value;
    return permissionCatalog.value.filter((permission) => normalize(permission.label).includes(term) || normalize(permission.name).includes(term));
});

const roleGroupedPermissions = computed(() => {
    const groups = {};
    for (const permission of roleFilteredPermissions.value) (groups[permission.module] ??= []).push(permission);
    for (const list of Object.values(groups)) list.sort((a, b) => a.label.localeCompare(b.label));
    return groups;
});

const roleModuleEntries = computed(() => Object.entries(roleGroupedPermissions.value)
    .sort(([leftModule], [rightModule]) => permissionCategoryLabel(leftModule).localeCompare(permissionCategoryLabel(rightModule), "fr")));
const roleCategoryTotalPages = computed(() => Math.max(1, Math.ceil(roleModuleEntries.value.length / ROLE_MODULES_PER_PAGE)));
const rolePaginatedModuleEntries = computed(() => {
    const start = (Math.min(roleCategoryPage.value, roleCategoryTotalPages.value) - 1) * ROLE_MODULES_PER_PAGE;
    return roleModuleEntries.value.slice(start, start + ROLE_MODULES_PER_PAGE);
});

watch(rolePermissionSearch, () => { roleCategoryPage.value = 1; });

// Diff against the role's permissions as loaded when the editor opened —
// a plain count so the footer can show "3 changements" before saving.
const roleChangedCount = computed(() => {
    if (!editingRole.value) return 0;
    const original = new Set(permissionCatalog.value.filter((permission) => editingRole.value.permissions.includes(permission.name)).map((permission) => permission.id));
    let changed = 0;
    for (const id of roleDraftIds.value) if (!original.has(id)) changed++;
    for (const id of original) if (!roleDraftIds.value.has(id)) changed++;
    return changed;
});

const saveRoleBaseline = () => {
    roleForm.transform(() => ({ permission_ids: Array.from(roleDraftIds.value) })).put(
        `/super-admin/workspaces/roles/${selectedSiteCode.value}/permissions/${editingRole.value.code}`,
        { preserveScroll: true, onSuccess: dismissRoleBaseline },
    );
};
</script>

<template>
    <Head :title="view === 'list' ? 'Rôles & permissions' : view === 'roles' ? 'Socle des rôles' : (isEditing ? 'Modifier un utilisateur' : 'Créer un utilisateur')" />

    <div class="w-full space-y-5">
        <template v-if="view === 'list'">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Rôles & permissions</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">Chaque compte appartient à un site précis. Le rôle donne le socle commun ; les exceptions individuelles (autoriser/refuser) restent propres au compte, jamais au rôle entier.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="canManageRoleBaselines && selectedSite?.ok" size="rg" variant="white-outline" type="button" @click="view = 'roles'">
                        <Icon class="text-lg" name="shield-check" /><span class="ms-2">Socle des rôles</span>
                    </Button>
                    <Button v-if="canCreate && selectedSite?.ok" size="rg" variant="primary" type="button" @click="openCreate">
                        <Icon class="text-lg" name="user-add" /><span class="ms-2">Nouvel utilisateur</span>
                    </Button>
                </div>
            </div>

            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 xl:flex-row xl:items-center xl:justify-between">
                    <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded bg-gray-100 p-1 dark:bg-gray-900">
                        <button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex shrink-0 items-center gap-2 rounded px-3 py-2 text-xs font-bold', selectedSiteCode === site.site.code ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500']" @click="selectSite(site.site.code)">
                            <span :class="['h-1.5 w-1.5 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'OFFLINE' || site.status === 'ERROR' ? 'bg-red-500' : 'bg-slate-300']" />{{ site.site.name }}<span v-if="site.ok" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] dark:bg-gray-900">{{ site.data?.users?.length ?? 0 }}</span>
                        </button>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <form class="relative w-full sm:w-64" role="search" @submit.prevent="submitFilters">
                            <Input v-model="query" icon="start" type="search" placeholder="Nom ou adresse email" autocomplete="off" />
                            <button type="submit" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400" aria-label="Rechercher"><Icon class="text-lg" name="search" /></button>
                        </form>
                        <select v-model="roleFilter" class="h-9 min-w-40 rounded border-gray-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200" @change="submitFilters">
                            <option value="">Tous les rôles</option>
                            <option v-for="role in roles" :key="role.id" :value="role.code">{{ role.name }}</option>
                        </select>
                        <select v-model="statusFilter" class="h-9 min-w-32 rounded border-gray-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200" @change="submitFilters">
                            <option value="active">Actifs</option>
                            <option value="inactive">Désactivés</option>
                            <option value="all">Tous</option>
                        </select>
                        <div class="inline-flex shrink-0 gap-1 rounded bg-gray-100 p-1 dark:bg-gray-900">
                            <button type="button" :class="['flex h-7 w-7 items-center justify-center rounded', listMode === 'list' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-950 dark:text-primary-300' : 'text-slate-400']" aria-label="Vue liste" title="Vue liste" @click="listMode = 'list'"><Icon class="text-base" name="list" /></button>
                            <button type="button" :class="['flex h-7 w-7 items-center justify-center rounded', listMode === 'grid' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-950 dark:text-primary-300' : 'text-slate-400']" aria-label="Vue grille" title="Vue grille" @click="listMode = 'grid'"><Icon class="text-base" name="grid-alt" /></button>
                        </div>
                    </div>
                </div>

                <div v-if="selectedUsers.length" class="flex flex-col gap-3 border-b border-gray-200 bg-primary-50/60 px-5 py-3 dark:border-gray-900 dark:bg-primary-950/10 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 min-w-8 items-center justify-center rounded bg-primary-600 px-2 text-xs font-bold text-white">{{ selectedUsers.length }}</span>
                        <div>
                            <p class="text-sm font-bold text-slate-700 dark:text-white">compte(s) sélectionné(s)</p>
                            <p class="text-xs text-slate-500">Actions limitées au site {{ selectedSite?.site.name }} · 100 maximum</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="can('users.deactivate')" size="sm" variant="white-outline" type="button" @click="openBulkDeactivate"><Icon name="lock" /><span class="ms-2">Désactiver ({{ selectedUsers.length }})</span></Button>
                        <Button v-if="can('users.force_delete') && deletableSelectedUsers.length" size="sm" variant="danger-outline" type="button" @click="openBulkForceDelete"><Icon name="trash" /><span class="ms-2">Supprimer définitivement ({{ deletableSelectedUsers.length }})</span></Button>
                        <button type="button" class="px-2 py-1 text-xs font-bold text-slate-500 hover:text-slate-700 dark:hover:text-white" @click="clearSelection">Désélectionner</button>
                    </div>
                </div>

                <div v-if="!selectedSite?.ok" class="flex min-h-56 flex-col items-center justify-center px-6 py-10 text-center">
                    <span class="flex h-11 w-11 items-center justify-center rounded bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="server" /></span>
                    <h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Référentiel indisponible pour {{ selectedSite?.site.name }}</h2>
                    <p class="mt-1 max-w-lg text-xs leading-5 text-slate-500">{{ selectedSite?.message }}</p>
                </div>

                <div v-else-if="listMode === 'list'" class="overflow-x-auto">
                    <table class="w-full min-w-[900px] border-collapse">
                        <caption class="sr-only">Liste des utilisateurs du site {{ selectedSite.site.name }}</caption>
                        <thead>
                            <tr class="bg-gray-50/70 dark:bg-gray-1000/40">
                                <th class="w-10 border-b border-gray-200 px-5 py-2.5 dark:border-gray-900"><input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" :checked="allVisibleSelected" :disabled="selectableUsers.length === 0" aria-label="Sélectionner tous les comptes actifs affichés" @change="toggleAllVisible"></th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Utilisateur</th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Rôle</th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Dernière connexion</th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">État</th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in users" :key="user.uuid" :class="['transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000', selectedUuids.has(user.uuid) ? 'bg-primary-50/30 dark:bg-primary-950/10' : '']">
                                <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                    <input v-if="user.active && canManage(user)" type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" :checked="selectedUuids.has(user.uuid)" :aria-label="`Sélectionner ${user.name}`" @change="toggleUser(user.uuid)">
                                </td>
                                <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                    <div class="flex min-w-[260px] items-center gap-3">
                                        <Avatar rounded size="sm" variant="slate-pale" :text="initials(user.name)" aria-hidden="true" />
                                        <div class="min-w-0">
                                            <span class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ user.name }}</span>
                                            <span class="mt-0.5 block truncate text-xs text-slate-400">{{ user.email }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                    <p class="text-sm font-medium text-slate-600 dark:text-slate-200">{{ user.role?.name ?? 'Aucun rôle' }}</p>
                                    <p v-if="user.professional_profile" class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ user.professional_profile.name }}</p>
                                    <p v-else-if="roleRequiresProfile(user.role?.id)" class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">Profil métier à définir</p>
                                    <p v-if="user.permission_overrides.length" class="mt-0.5 text-xs text-slate-400">{{ user.permission_overrides.length }} exception{{ user.permission_overrides.length > 1 ? 's' : '' }} individuelle{{ user.permission_overrides.length > 1 ? 's' : '' }}</p>
                                </td>
                                <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">{{ formatDateTime(user.last_login_at) }}</td>
                                <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                    <span v-if="user.active" class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Actif</span>
                                    <div v-else>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500"><span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span> Désactivé</span>
                                        <p v-if="user.deactivation_reason" class="mt-1 max-w-xs truncate text-xs text-slate-400" :title="user.deactivation_reason">{{ user.deactivation_reason }}</p>
                                    </div>
                                </td>
                                <td class="border-b border-gray-200 px-5 py-3 text-end dark:border-gray-900">
                                    <div v-if="canManage(user)" class="inline-flex items-center gap-1">
                                        <button v-if="can('users.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" :aria-label="`Modifier ${user.name}`" title="Modifier" @click="openEdit(user)"><Icon class="text-base" name="edit" /></button>
                                        <button v-if="user.active && can('users.deactivate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-red-300 hover:text-red-600 dark:border-gray-800" :aria-label="`Désactiver ${user.name}`" title="Désactiver" @click="openDeactivate(user)"><Icon class="text-base" name="lock" /></button>
                                        <button v-if="!user.active && can('users.activate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-emerald-300 hover:text-emerald-700 dark:border-gray-800" :aria-label="`Réactiver ${user.name}`" title="Réactiver" @click="activate(user)"><Icon class="text-base" name="unlock" /></button>
                                        <button v-if="user.deletable && can('users.force_delete')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-red-500 hover:border-red-300 hover:bg-red-50 dark:border-gray-800" :aria-label="`Supprimer définitivement ${user.name}`" title="Supprimer définitivement" @click="openForceDelete(user)"><Icon class="text-base" name="trash" /></button>
                                    </div>
                                    <span v-else class="text-xs text-slate-400">Protégé</span>
                                </td>
                            </tr>

                            <tr v-if="users.length === 0">
                                <td colspan="6" class="px-5 py-12 text-center">
                                    <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="users" /></span>
                                    <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun utilisateur trouvé</p>
                                    <p class="mt-1 text-xs text-slate-400">Modifiez les filtres ou créez un compte autorisé.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                    <article v-for="user in users" :key="user.uuid" :class="['flex flex-col rounded border bg-white dark:bg-gray-950', selectedUuids.has(user.uuid) ? 'border-primary-300 ring-1 ring-primary-100 dark:border-primary-800 dark:ring-primary-950' : 'border-gray-200 dark:border-gray-900']">
                        <div class="flex items-start gap-3 p-4">
                            <input v-if="user.active && canManage(user)" type="checkbox" class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500" :checked="selectedUuids.has(user.uuid)" :aria-label="`Sélectionner ${user.name}`" @change="toggleUser(user.uuid)">
                            <Avatar rounded size="sm" variant="slate-pale" :text="initials(user.name)" aria-hidden="true" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ user.name }}</p>
                                <p class="mt-0.5 truncate text-xs text-slate-400">{{ user.email }}</p>
                            </div>
                            <span v-if="user.active" class="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-emerald-500" title="Actif" />
                            <span v-else class="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-slate-400" title="Désactivé" />
                        </div>

                        <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-900">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Rôle</p>
                            <p class="mt-1 text-sm font-medium text-slate-600 dark:text-slate-200">{{ user.role?.name ?? 'Aucun rôle' }}</p>
                            <p v-if="user.professional_profile" class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ user.professional_profile.name }}</p>
                            <p v-else-if="roleRequiresProfile(user.role?.id)" class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">Profil métier à définir</p>
                            <p v-if="user.permission_overrides.length" class="mt-1 inline-flex items-center gap-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-slate-500 dark:bg-gray-900">{{ user.permission_overrides.length }} exception{{ user.permission_overrides.length > 1 ? 's' : '' }}</p>
                            <p class="mt-2 text-[11px] text-slate-400">Connexion : {{ formatDateTime(user.last_login_at) }}</p>
                            <p v-if="!user.active && user.deactivation_reason" class="mt-1 truncate text-[11px] text-slate-400" :title="user.deactivation_reason">Motif : {{ user.deactivation_reason }}</p>
                        </div>

                        <div class="mt-auto flex items-center justify-end gap-1 border-t border-gray-200 px-4 py-2.5 dark:border-gray-900">
                            <template v-if="canManage(user)">
                                <button v-if="can('users.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" :aria-label="`Modifier ${user.name}`" title="Modifier" @click="openEdit(user)"><Icon class="text-base" name="edit" /></button>
                                <button v-if="user.active && can('users.deactivate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-red-300 hover:text-red-600 dark:border-gray-800" :aria-label="`Désactiver ${user.name}`" title="Désactiver" @click="openDeactivate(user)"><Icon class="text-base" name="lock" /></button>
                                <button v-if="!user.active && can('users.activate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-emerald-300 hover:text-emerald-700 dark:border-gray-800" :aria-label="`Réactiver ${user.name}`" title="Réactiver" @click="activate(user)"><Icon class="text-base" name="unlock" /></button>
                                <button v-if="user.deletable && can('users.force_delete')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-red-500 hover:border-red-300 hover:bg-red-50 dark:border-gray-800" :aria-label="`Supprimer définitivement ${user.name}`" title="Supprimer définitivement" @click="openForceDelete(user)"><Icon class="text-base" name="trash" /></button>
                            </template>
                            <span v-else class="text-xs text-slate-400">Protégé</span>
                        </div>
                    </article>

                    <div v-if="users.length === 0" class="col-span-full px-5 py-12 text-center">
                        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="users" /></span>
                        <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun utilisateur trouvé</p>
                        <p class="mt-1 text-xs text-slate-400">Modifiez les filtres ou créez un compte autorisé.</p>
                    </div>
                </div>
            </section>

            <aside class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950 sm:px-5">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900"><Icon class="text-lg" name="shield-check" /></span>
                    <div>
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Un accès propre à chaque compte</h2>
                        <p class="mt-1 text-xs leading-5 text-slate-500">Le rôle fournit uniquement le socle commun du service — deux comptes du même rôle peuvent avoir des droits différents. Une interdiction individuelle est toujours prioritaire sur une autorisation. Toute attribution est exécutée et auditée directement dans la base du site, jamais en local sur le portail. Un compte n'est jamais supprimé : il est désactivé pour préserver l'historique.</p>
                    </div>
                </div>
            </aside>
        </template>

        <template v-else-if="view === 'roles'">
            <div class="flex items-center gap-3">
                <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" aria-label="Retour à la liste" @click="editingRole ? closeRoleBaseline() : (view = 'list')"><Icon class="text-lg" name="arrow-left" /></button>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ selectedSite?.site.name }}</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ editingRole ? `Socle du rôle « ${editingRole.name} »` : 'Socle des rôles' }}</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ editingRole ? "Ces droits s'appliquent à tous les comptes de ce rôle sur ce site. Les exceptions individuelles de chaque compte restent inchangées et s'appliquent toujours par-dessus." : "Choisissez un rôle pour modifier les permissions qu'il accorde par défaut à tous ses comptes." }}</p>
                </div>
            </div>

            <div v-if="!editingRole" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="role in roles"
                    :key="role.id"
                    type="button"
                    class="rounded border border-gray-200 bg-white p-4 text-start transition-colors hover:border-primary-200 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-primary-900"
                    @click="openRoleBaseline(role)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm font-bold text-slate-700 dark:text-white">{{ role.name }}</p>
                        <Icon class="shrink-0 text-lg text-slate-300" name="chevron-right" />
                    </div>
                    <p class="mt-1 text-xs text-slate-400">{{ role.permissions.length }} permission{{ role.permissions.length > 1 ? 's' : '' }} accordée{{ role.permissions.length > 1 ? 's' : '' }} par défaut</p>
                    <p v-if="role.profiles.length" class="mt-0.5 text-[11px] text-slate-400">{{ role.profiles.length }} profil{{ role.profiles.length > 1 ? 's' : '' }} métier</p>
                </button>

                <div v-if="roles.length === 0" class="col-span-full px-5 py-12 text-center">
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-200">Aucun rôle disponible sur ce site.</p>
                </div>
            </div>

            <template v-else>
                <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-900 dark:bg-gray-950">
                    <FormError v-if="roleForm.errors.role">{{ roleForm.errors.role }}</FormError>
                    <FormError v-if="roleForm.errors.permission_ids">{{ roleForm.errors.permission_ids }}</FormError>

                    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                        <span class="relative block w-full sm:max-w-xs">
                            <Input v-model="rolePermissionSearch" icon="start" type="search" placeholder="Rechercher une permission…" autocomplete="off" />
                            <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" />
                        </span>
                        <span class="text-xs text-slate-400">{{ roleDraftIds.size }} permission{{ roleDraftIds.size > 1 ? 's' : '' }} accordée{{ roleDraftIds.size > 1 ? 's' : '' }}</span>
                    </div>

                    <p v-if="roleFilteredPermissions.length === 0" class="mt-6 py-8 text-center text-sm text-slate-400">Aucune permission ne correspond à « {{ rolePermissionSearch }} ».</p>

                    <template v-else>
                        <div class="mt-4 space-y-4">
                            <div v-for="[module, permissions] in rolePaginatedModuleEntries" :key="module">
                                <div class="mb-2 flex items-center justify-between">
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ permissionCategoryLabel(module) }} <span class="font-normal normal-case text-slate-300">· {{ permissions.length }} droit{{ permissions.length > 1 ? 's' : '' }}</span></p>
                                    <div class="flex items-center gap-2 text-[11px] font-bold text-primary-600 dark:text-primary-300">
                                        <button type="button" class="hover:underline" @click="toggleModulePermissions(permissions, true)">Tout cocher</button>
                                        <span class="text-slate-300">·</span>
                                        <button type="button" class="hover:underline" @click="toggleModulePermissions(permissions, false)">Tout décocher</button>
                                    </div>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                    <label v-for="permission in permissions" :key="permission.id" :class="['flex cursor-pointer items-start gap-2.5 rounded border p-3 transition-colors', roleDraftIds.has(permission.id) ? 'border-primary-200 border-s-4 border-s-primary-400 bg-primary-50/40 dark:border-primary-900 dark:border-s-primary-600 dark:bg-primary-950/10' : 'border-gray-200 dark:border-gray-800']">
                                        <input type="checkbox" class="mt-0.5 shrink-0 rounded border-gray-300 text-primary-600 focus:ring-primary-500" :checked="roleDraftIds.has(permission.id)" @change="toggleRolePermission(permission.id)">
                                        <span class="min-w-0">
                                            <span class="block text-xs font-bold text-slate-700 dark:text-white">{{ permission.label }}</span>
                                            <span class="mt-0.5 block truncate font-mono text-[10px] text-slate-400" :title="permission.name">{{ permission.name }}</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div v-if="roleCategoryTotalPages > 1" class="mt-5 flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-900">
                            <button type="button" class="flex items-center gap-1.5 rounded border border-gray-200 px-3 py-1.5 text-xs font-bold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:text-slate-300" :disabled="roleCategoryPage === 1" @click="roleCategoryPage--"><Icon class="text-sm" name="arrow-left" />Précédent</button>
                            <span class="text-xs text-slate-400">Catégories · page {{ roleCategoryPage }} / {{ roleCategoryTotalPages }}</span>
                            <button type="button" class="flex items-center gap-1.5 rounded border border-gray-200 px-3 py-1.5 text-xs font-bold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:text-slate-300" :disabled="roleCategoryPage === roleCategoryTotalPages" @click="roleCategoryPage++">Suivant<Icon class="text-sm" name="arrow-right" /></button>
                        </div>
                    </template>
                </section>

                <footer class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
                    <Button size="rg" variant="white-outline" type="button" :disabled="roleForm.processing" @click="closeRoleBaseline">Annuler</Button>
                    <Button size="rg" variant="primary" type="button" :disabled="roleForm.processing" @click="saveRoleBaseline">
                        <Icon class="text-lg" name="check" /><span class="ms-2">{{ roleForm.processing ? 'Enregistrement…' : (roleChangedCount ? `Enregistrer (${roleChangedCount} changement${roleChangedCount > 1 ? 's' : ''})` : 'Enregistrer') }}</span>
                    </Button>
                </footer>
            </template>
        </template>

        <template v-else-if="view === 'form'">
            <div class="flex items-center gap-3">
                <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" aria-label="Retour à la liste" @click="closeForm"><Icon class="text-lg" name="arrow-left" /></button>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ selectedSite?.site.name }}</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ isEditing ? `Modifier ${editingUser.name}` : 'Créer un utilisateur' }}</h1>
                </div>
            </div>

            <nav class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950" aria-label="Étapes">
                <ol class="grid grid-cols-3">
                    <li v-for="(s, index) in steps" :key="s.n">
                        <button
                            type="button"
                            :disabled="s.n > maxStepReached"
                            :class="['flex w-full items-center gap-3 px-4 py-3.5 text-start transition-colors', index > 0 ? 'border-s border-gray-200 dark:border-gray-900' : '', s.n > maxStepReached ? 'cursor-not-allowed opacity-50' : 'hover:bg-gray-50 dark:hover:bg-gray-1000']"
                            @click="goToStep(s.n)"
                        >
                            <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold', step === s.n ? 'bg-primary-600 text-white' : s.n < step ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-gray-100 text-slate-400 dark:bg-gray-900']">
                                <Icon v-if="s.n < step" class="text-base" name="check" />
                                <template v-else>{{ s.n }}</template>
                            </span>
                            <span class="min-w-0">
                                <span :class="['block text-xs font-bold uppercase tracking-wide', step === s.n ? 'text-primary-600 dark:text-primary-300' : 'text-slate-500']">Étape {{ s.n }}</span>
                                <span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ s.label }}</span>
                            </span>
                        </button>
                    </li>
                </ol>
            </nav>

            <form @submit.prevent="submitUser">
                <section v-if="step === 1" class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Informations du compte</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Un compte nominatif, jamais générique ni partagé.</p>

                    <div v-if="form.errors.site_code || form.errors.user" class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">{{ form.errors.site_code || form.errors.user }}</div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="user_name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom complet <span class="text-red-500">*</span></label>
                            <Input id="user_name" v-model="form.name" autocomplete="name" :aria-invalid="Boolean(form.errors.name)" />
                            <FormError v-if="form.errors.name">{{ form.errors.name }}</FormError>
                        </div>
                        <div>
                            <label for="user_email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Email professionnel <span class="text-red-500">*</span></label>
                            <Input id="user_email" v-model="form.email" type="email" autocomplete="off" :aria-invalid="Boolean(form.errors.email)" />
                            <FormError v-if="form.errors.email">{{ form.errors.email }}</FormError>
                        </div>
                    </div>

                    <div v-if="!isEditing" class="mt-6 flex items-start gap-3 rounded border border-primary-200 bg-primary-50/60 px-4 py-3 dark:border-primary-900 dark:bg-primary-950/20">
                        <Icon class="mt-0.5 shrink-0 text-lg text-primary-600 dark:text-primary-300" name="mail" />
                        <div>
                            <p class="text-sm font-bold text-slate-700 dark:text-white">Aucun mot de passe à saisir</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Un email sera envoyé à <strong>{{ form.email || "l'adresse renseignée" }}</strong> avec son identifiant de connexion et un lien pour créer son propre mot de passe.</p>
                        </div>
                    </div>

                    <div v-else class="mt-6 border-t border-gray-200 pt-5 dark:border-gray-900">
                        <h3 class="text-sm font-bold text-slate-700 dark:text-white">Nouveau mot de passe</h3>
                        <p class="mt-1 text-xs text-slate-400">Laissez vide pour conserver le mot de passe actuel.</p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="user_password" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Mot de passe</label>
                                <span class="relative block">
                                    <Input id="user_password" v-model="form.password" :type="showPassword ? 'text' : 'password'" icon="end" autocomplete="new-password" :aria-invalid="Boolean(form.errors.password)" />
                                    <button type="button" class="absolute inset-y-0 end-0 flex w-9 items-center justify-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword"><Icon class="text-lg" :name="showPassword ? 'eye-off' : 'eye'" /></button>
                                </span>
                                <FormError v-if="form.errors.password">{{ form.errors.password }}</FormError>
                            </div>
                            <div>
                                <label for="user_password_confirmation" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Confirmation</label>
                                <span class="relative block">
                                    <Input id="user_password_confirmation" v-model="form.password_confirmation" :type="showPasswordConfirmation ? 'text' : 'password'" icon="end" autocomplete="new-password" />
                                    <button type="button" class="absolute inset-y-0 end-0 flex w-9 items-center justify-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" :aria-label="showPasswordConfirmation ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPasswordConfirmation = !showPasswordConfirmation"><Icon class="text-lg" :name="showPasswordConfirmation ? 'eye-off' : 'eye'" /></button>
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-else-if="step === 2" class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Rôle métier</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Le rôle donne uniquement le socle de droits commun au service — il ne fige pas les permissions du compte, ajustables à l'étape suivante.</p>

                    <div class="mt-5">
                        <p class="mb-2 text-sm font-medium text-slate-700 dark:text-white">Rôle métier <span class="text-red-500">*</span></p>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <button
                                v-for="role in roles"
                                :key="role.id"
                                type="button"
                                :class="['rounded border p-4 text-start transition-colors', Number(form.role_id) === Number(role.id) ? 'border-primary-400 bg-primary-50/60 ring-1 ring-primary-200 dark:border-primary-700 dark:bg-primary-950/20 dark:ring-primary-900' : 'border-gray-200 hover:border-primary-200 dark:border-gray-800 dark:hover:border-primary-900']"
                                :aria-pressed="Number(form.role_id) === Number(role.id)"
                                @click="selectRole(role.id)"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-bold text-slate-700 dark:text-white">{{ role.name }}</p>
                                    <Icon v-if="Number(form.role_id) === Number(role.id)" class="shrink-0 text-lg text-primary-600 dark:text-primary-300" name="check-circle-fill" />
                                </div>
                                <p class="mt-1 text-xs text-slate-400">{{ role.permissions.length }} permission{{ role.permissions.length > 1 ? 's' : '' }} par défaut</p>
                                <p v-if="role.profiles.length" class="mt-0.5 text-[11px] text-slate-400">{{ role.profiles.length }} profil{{ role.profiles.length > 1 ? 's' : '' }} métier</p>
                            </button>
                        </div>
                        <FormError v-if="form.errors.role_id">{{ form.errors.role_id }}</FormError>
                    </div>

                    <div v-if="selectedRoleProfiles.length" class="mt-6">
                        <p class="mb-2 text-sm font-medium text-slate-700 dark:text-white">Profil professionnel <span class="text-red-500">*</span></p>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <button
                                v-for="profile in selectedRoleProfiles"
                                :key="profile.id"
                                type="button"
                                :class="['rounded border p-4 text-start transition-colors', Number(form.professional_profile_id) === Number(profile.id) ? 'border-primary-400 bg-primary-50/60 ring-1 ring-primary-200 dark:border-primary-700 dark:bg-primary-950/20 dark:ring-primary-900' : 'border-gray-200 hover:border-primary-200 dark:border-gray-800 dark:hover:border-primary-900']"
                                :aria-pressed="Number(form.professional_profile_id) === Number(profile.id)"
                                @click="selectProfile(profile.id)"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-bold text-slate-700 dark:text-white">{{ profile.name }}</p>
                                    <Icon v-if="Number(form.professional_profile_id) === Number(profile.id)" class="shrink-0 text-lg text-primary-600 dark:text-primary-300" name="check-circle-fill" />
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs text-slate-400">{{ profile.description }}</p>
                            </button>
                        </div>
                        <FormError v-if="form.errors.professional_profile_id">{{ form.errors.professional_profile_id }}</FormError>
                    </div>

                    <div v-if="selectedProfile" class="mt-5 rounded border border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/40">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-bold text-slate-700 dark:text-white">{{ selectedProfile.name }}</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ selectedProfile.description }}</p>
                                <p class="mt-1 text-[11px] text-slate-400">Le profil classe le métier ; il ne donne aucun droit automatiquement.</p>
                            </div>
                            <Button v-if="canAssignPermissions && selectedProfile.recommended_permissions?.length" size="sm" variant="white-outline" type="button" class="shrink-0" @click="applyProfileRecommendations">
                                <Icon class="text-base" name="shield-check" /><span class="ms-2">Appliquer les permissions du profil</span>
                            </Button>
                        </div>
                        <p v-if="selectedProfile.recommended_permissions?.length" class="mt-2 text-[11px] text-slate-400">{{ selectedProfile.recommended_permissions.length }} droit{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} recommandé{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }}, enregistré{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} à l'étape Permissions après application.</p>
                        <p v-else class="mt-2 text-[11px] text-slate-400">Aucun droit supplémentaire recommandé : le socle du rôle reste applicable.</p>
                        <div v-if="profileChanged" class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                            <p class="font-bold">Le profil professionnel a changé.</p>
                            <p>{{ oldProfileName }} → {{ newProfileName }}</p>
                            <p class="mt-1">Les permissions provenant de l’ancien profil seront retirées à l’enregistrement. Si vous appliquez le nouveau profil, ses recommandations seront ajoutées ; les permissions attribuées manuellement seront conservées.</p>
                        </div>
                    </div>

                    <div v-if="selectedRole" class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-900">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Socle du rôle « {{ selectedRole.name }} »</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ selectedRole.permissions.length }} permission{{ selectedRole.permissions.length > 1 ? 's' : '' }} accordée{{ selectedRole.permissions.length > 1 ? 's' : '' }} par défaut à ce rôle. Le détail est visible et ajustable à l'étape suivante.</p>
                    </div>
                </section>

                <section v-else class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <header class="border-b border-gray-200 px-4 py-4 dark:border-gray-900 sm:px-5">
                        <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                            <div class="flex min-w-0 items-center gap-3">
                                <Avatar :text="initials(form.name)" size="rg" variant="primary-pale" />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ form.name || 'Nouvel utilisateur' }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Rôle principal : <strong>{{ selectedRole?.name ?? 'Non défini' }}</strong><span v-if="selectedProfile"> · {{ selectedProfile.name }}</span></p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Button size="sm" variant="white-outline" type="button" @click="goToStep(2)"><Icon class="text-base" name="briefcase" /><span class="ms-1.5">Changer le rôle</span></Button>
                                <Button size="sm" variant="white-outline" type="button" @click="showAccessPreview = true"><Icon class="text-base" name="eye" /><span class="ms-1.5">Prévisualiser les accès</span></Button>
                                <Button v-if="permissionSummary.exceptions" size="sm" variant="white-outline" type="button" @click="openBulkAction('', permissionCatalog, 'toutes les exceptions individuelles')"><Icon class="text-base" name="undo" /><span class="ms-1.5">Réinitialiser les exceptions</span></Button>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-7">
                            <div class="rounded border border-gray-200 px-3 py-2 dark:border-gray-800"><p class="text-[10px] font-bold uppercase text-slate-400">Catalogue</p><p class="mt-0.5 text-lg font-bold text-slate-700 dark:text-white">{{ permissionSummary.total }}</p></div>
                            <div class="rounded border border-emerald-200 bg-emerald-50/40 px-3 py-2 dark:border-emerald-900 dark:bg-emerald-950/10"><p class="text-[10px] font-bold uppercase text-emerald-600">Accès effectifs</p><p class="mt-0.5 text-lg font-bold text-emerald-700 dark:text-emerald-300">{{ permissionSummary.effectiveAllowed }}</p></div>
                            <div class="rounded border border-red-200 bg-red-50/30 px-3 py-2 dark:border-red-900 dark:bg-red-950/10"><p class="text-[10px] font-bold uppercase text-red-500">Interdits effectifs</p><p class="mt-0.5 text-lg font-bold text-red-700 dark:text-red-300">{{ permissionSummary.effectiveDenied }}</p></div>
                            <div class="rounded border border-primary-200 bg-primary-50/30 px-3 py-2 dark:border-primary-900 dark:bg-primary-950/10"><p class="text-[10px] font-bold uppercase text-primary-600">Exceptions</p><p class="mt-0.5 text-lg font-bold text-primary-700 dark:text-primary-300">{{ permissionSummary.exceptions }}</p></div>
                            <div class="rounded border border-gray-200 px-3 py-2 dark:border-gray-800"><p class="text-[10px] font-bold uppercase text-slate-400">Héritées</p><p class="mt-0.5 text-lg font-bold text-slate-600 dark:text-slate-200">{{ permissionSummary.inherited }}</p></div>
                            <div class="rounded border border-gray-200 px-3 py-2 dark:border-gray-800"><p class="text-[10px] font-bold uppercase text-slate-400">Autor. manuelles</p><p class="mt-0.5 text-lg font-bold text-emerald-600">{{ permissionSummary.manualAllowed }}</p></div>
                            <div class="rounded border border-gray-200 px-3 py-2 dark:border-gray-800"><p class="text-[10px] font-bold uppercase text-slate-400">Refus manuels</p><p class="mt-0.5 text-lg font-bold text-red-600">{{ permissionSummary.manualDenied }}</p></div>
                        </div>
                    </header>

                    <FormError v-if="form.errors.permission_overrides" class="mx-4 mt-4">{{ form.errors.permission_overrides }}</FormError>
                    <p v-if="!canAssignPermissions" class="m-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">Vous n'avez pas la permission <code>permissions.assign</code> : le compte utilisera uniquement le socle de son rôle.</p>

                    <div v-if="canAssignPermissions && permissionsDirty" class="flex flex-col gap-3 border-b border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/20 sm:flex-row sm:items-center sm:justify-between" role="status" aria-live="polite">
                        <div class="flex items-start gap-2.5">
                            <Icon class="mt-0.5 shrink-0 text-lg text-amber-600 dark:text-amber-300" name="alert-circle" />
                            <div>
                                <p class="text-sm font-bold text-amber-900 dark:text-amber-100">Brouillon uniquement — les accès réels n’ont pas encore changé</p>
                                <p class="mt-0.5 text-xs leading-5 text-amber-800 dark:text-amber-200">{{ permissionChangesCount }} modification{{ permissionChangesCount > 1 ? 's' : '' }} préparée{{ permissionChangesCount > 1 ? 's' : '' }}. Enregistrez pour appliquer les interdictions sur {{ selectedSite?.site.name }}.</p>
                            </div>
                        </div>
                        <Button size="rg" variant="primary" type="submit" class="shrink-0" :disabled="form.processing">
                            <Icon class="text-lg" name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : `Enregistrer maintenant (${permissionChangesCount})` }}</span>
                        </Button>
                    </div>

                    <!-- Largeur de la colonne « Rechercher / Catégories » ajustable :
                         glisser la barre, flèches du clavier, double-clic pour revenir.
                         Choix propre au poste ; empilé sur écran étroit. -->
                    <ResizableSplit
                        v-if="canAssignPermissions"
                        class="min-h-[34rem]"
                        storage-key="rivo:super-admin:permission-split"
                        :default-ratio="0.24"
                        :min-ratio="0.16"
                        :max-ratio="0.45"
                        start-label="panneau des catégories"
                        end-label="panneau des permissions"
                    >
                        <template #start>
                        <aside class="border-b border-gray-200 bg-gray-50/60 p-4 dark:border-gray-900 dark:bg-gray-1000/30 h-full" aria-label="Navigation des catégories de permissions">
                            <label for="permission-search" class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-200">Rechercher</label>
                            <span class="relative block">
                                <Input id="permission-search" v-model="permissionSearch" icon="start" type="search" placeholder="Libellé, code, catégorie…" autocomplete="off" />
                                <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" />
                            </span>

                            <div class="mt-4">
                                <p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">Catégories</p>
                                <div class="max-h-72 overflow-y-auto pe-1 lg:max-h-[25rem]">
                                    <template v-for="group in permissionCategoryGroups" :key="group.key">
                                        <p
                                            v-show="!permissionSearch || group.categories.some((category) => category.visible)"
                                            class="sticky top-0 z-10 bg-gray-50/95 px-2.5 pb-1 pt-2.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 first:pt-0.5 dark:bg-gray-1000/95"
                                        >{{ group.label }}</p>
                                        <button
                                            v-for="category in group.categories"
                                            :key="category.key"
                                            v-show="!permissionSearch || category.visible"
                                            type="button"
                                            :title="`${category.label} · code ${category.key}`"
                                            :class="['flex w-full items-center gap-2 rounded px-2.5 py-2 text-start text-xs leading-4 transition-colors', selectedPermissionCategory === category.key ? 'bg-white font-bold text-primary-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-950 dark:text-primary-300 dark:ring-gray-800' : 'text-slate-600 hover:bg-white dark:text-slate-300 dark:hover:bg-gray-950']"
                                            :aria-current="selectedPermissionCategory === category.key ? 'page' : undefined"
                                            @click="selectedPermissionCategory = category.key"
                                        >
                                            <span class="min-w-0 flex-1">{{ category.label }}</span>
                                            <span v-if="category.exceptions" class="h-1.5 w-1.5 shrink-0 rounded-full bg-primary-500" title="Catégorie personnalisée" />
                                            <span class="shrink-0 tabular-nums text-[10px] text-slate-400">{{ permissionSearch ? category.visible : category.total }}</span>
                                        </button>
                                    </template>
                                </div>
                                <button type="button" :class="['mt-2 flex w-full items-center gap-2 rounded border px-2.5 py-2 text-start text-xs font-bold', selectedPermissionCategory === '__sensitive__' ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200' : 'border-amber-200 bg-white text-amber-700 dark:border-amber-900 dark:bg-gray-950 dark:text-amber-300']" @click="selectedPermissionCategory = '__sensitive__'; permissionFilter = 'all'">
                                    <Icon class="text-base" name="alert-circle" /><span class="flex-1">Permissions sensibles</span><span class="tabular-nums">{{ sensitivePermissionCount }}</span>
                                </button>
                            </div>

                            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                                <label for="permission-filter" class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-200">Afficher</label>
                                <select id="permission-filter" v-model="permissionFilter" class="h-9 w-full rounded border-gray-200 bg-white py-1.5 ps-3 pe-8 text-xs text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200">
                                    <option value="all">Toutes</option>
                                    <option value="exceptions">Exceptions uniquement</option>
                                    <option value="inherited">Héritées</option>
                                    <option value="allowed">Autorisées effectives</option>
                                    <option value="denied">Interdites effectives</option>
                                    <option value="sensitive">Sensibles</option>
                                </select>
                            </div>
                        </aside>
                        </template>
                        <template #end>

                        <div class="min-w-0">
                            <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-900">
                                <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                                    <div>
                                        <div class="flex items-center gap-2"><h3 class="text-base font-bold text-slate-700 dark:text-white">{{ currentCategoryLabel }}</h3><span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500 dark:bg-gray-900">{{ currentPermissions.length }}</span></div>
                                        <p class="mt-1 text-xs text-slate-500">Le rôle définit le socle. Seules les exceptions modifient l’accès de ce compte.</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="inline-flex rounded bg-gray-100 p-1 dark:bg-gray-900" aria-label="Mode d’affichage">
                                            <button type="button" :class="['rounded px-3 py-1.5 text-xs font-bold', permissionMode === 'simple' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-950 dark:text-primary-300' : 'text-slate-500']" :aria-pressed="permissionMode === 'simple'" @click="permissionMode = 'simple'">Simple</button>
                                            <button type="button" :class="['rounded px-3 py-1.5 text-xs font-bold', permissionMode === 'advanced' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-950 dark:text-primary-300' : 'text-slate-500']" :aria-pressed="permissionMode === 'advanced'" @click="permissionMode = 'advanced'">Avancé</button>
                                        </div>
                                        <label for="permission-sort" class="sr-only">Trier les permissions</label>
                                        <select id="permission-sort" v-model="permissionSort" class="h-9 rounded border-gray-200 bg-white py-1.5 ps-3 pe-8 text-xs text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200">
                                            <option value="label">Tri : libellé</option>
                                            <option value="state">Tri : état</option>
                                            <option value="category">Tri : catégorie</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <span class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Action sur la vue :</span>
                                    <button type="button" class="rounded border border-gray-200 px-2.5 py-1.5 text-[11px] font-bold text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300" :disabled="!currentPermissions.length" @click="openBulkAction('')">Tout hériter</button>
                                    <button type="button" class="rounded border border-emerald-200 px-2.5 py-1.5 text-[11px] font-bold text-emerald-700 hover:bg-emerald-50 dark:border-emerald-900 dark:text-emerald-300" :disabled="!currentPermissions.length" @click="openBulkAction('allow')">Tout autoriser</button>
                                    <button type="button" class="rounded border border-red-200 px-2.5 py-1.5 text-[11px] font-bold text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300" :disabled="!currentPermissions.length" @click="openBulkAction('deny')">Tout interdire</button>
                                    <span class="text-[11px] text-slate-400">{{ currentPermissions.length }} permission{{ currentPermissions.length > 1 ? 's' : '' }} filtrée{{ currentPermissions.length > 1 ? 's' : '' }}</span>
                                </div>
                            </div>

                            <div v-if="currentPermissions.length === 0" class="flex min-h-64 flex-col items-center justify-center px-6 py-10 text-center">
                                <span class="flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="search" /></span>
                                <p class="mt-3 text-sm font-bold text-slate-600 dark:text-slate-200">Aucune permission dans cette vue</p>
                                <p class="mt-1 text-xs text-slate-400">Modifiez la recherche, le filtre ou choisissez une autre catégorie.</p>
                            </div>

                            <div v-else-if="permissionMode === 'simple'" class="p-3 sm:p-4">
                                <details v-for="group in functionalPermissionGroups" :key="group.label" class="group mb-2 overflow-hidden rounded border border-gray-200 last:mb-0 dark:border-gray-800" open>
                                    <summary class="flex cursor-pointer list-none items-center gap-2 bg-gray-50/70 px-4 py-2.5 text-xs font-bold text-slate-700 marker:hidden dark:bg-gray-1000/50 dark:text-white">
                                        <Icon class="text-base text-primary-500" name="shield-check" /><span class="flex-1">{{ group.label }}</span><span class="font-normal text-slate-400">{{ group.permissions.length }} droit{{ group.permissions.length > 1 ? 's' : '' }}</span><Icon class="text-sm text-slate-400 transition-transform group-open:rotate-180" name="chevron-down" />
                                    </summary>
                                    <PermissionAccessRow
                                        v-for="permission in group.permissions"
                                        :key="permission.id"
                                        :permission="permission"
                                        :state="permissionEffect(permission)"
                                        :role-granted="roleGrants(permission)"
                                        :effective-granted="effectivelyGranted(permission)"
                                        :sensitive="isSensitivePermission(permission)"
                                        :source-label="permissionSourceLabel(permission)"
                                        @change="requestPermissionChange(permission, $event)"
                                    />
                                </details>
                            </div>

                            <div v-else class="p-3 sm:p-4">
                                <div class="overflow-hidden rounded border border-gray-200 dark:border-gray-800">
                                    <PermissionAccessRow
                                        v-for="permission in currentPermissions"
                                        :key="permission.id"
                                        :permission="permission"
                                        :state="permissionEffect(permission)"
                                        :role-granted="roleGrants(permission)"
                                        :effective-granted="effectivelyGranted(permission)"
                                        :sensitive="isSensitivePermission(permission)"
                                        :source-label="permissionSourceLabel(permission)"
                                        advanced
                                        @change="requestPermissionChange(permission, $event)"
                                    />
                                </div>
                            </div>
                        </div>
                        </template>
                    </ResizableSplit>
                </section>

                <footer class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-3">
                        <Button size="rg" variant="white-outline" type="button" :disabled="form.processing" @click="closeForm">Annuler</Button>
                        <button v-if="step === 3 && permissionsDirty" type="button" class="text-xs font-bold text-slate-500 underline-offset-2 hover:text-slate-700 hover:underline dark:hover:text-white" @click="restoreUnsavedPermissions">Annuler les changements de permissions</button>
                    </div>
                    <p v-if="step === 3 && permissionsDirty" class="text-xs font-bold text-amber-600 dark:text-amber-300" role="status"><Icon class="me-1 text-sm" name="alert-circle" />{{ permissionChangesCount }} modification{{ permissionChangesCount > 1 ? 's' : '' }} non enregistrée{{ permissionChangesCount > 1 ? 's' : '' }}</p>
                    <div class="flex flex-col-reverse gap-3 sm:flex-row">
                        <Button v-if="step > 1" size="rg" variant="white-outline" type="button" :disabled="form.processing" @click="prevStep"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Précédent</span></Button>
                        <Button v-if="isEditing && step === 1" size="rg" variant="white-outline" type="button" :disabled="!step1Valid || form.processing" @click="submitUser">
                            <Icon class="text-lg" name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Mettre à jour les informations' }}</span>
                        </Button>
                        <Button v-if="step < 3" size="rg" variant="primary" type="button" :disabled="(step === 1 && !step1Valid) || (step === 2 && !step2Valid)" @click="nextStep">{{ step === 1 && isEditing ? 'Continuer vers le rôle' : 'Suivant' }}<Icon class="ms-2 text-lg" name="arrow-right" /></Button>
                        <Button v-else size="rg" variant="primary" type="submit" :disabled="form.processing">
                            <Icon class="text-lg" name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : (isEditing ? (permissionChangesCount ? `Enregistrer (${permissionChangesCount})` : 'Enregistrer') : 'Créer le compte') }}</span>
                        </Button>
                    </div>
                </footer>
            </form>
        </template>

        <div v-if="showAccessPreview" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-3 sm:p-6" role="presentation" @click.self="showAccessPreview = false">
            <section class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="effective-access-title">
                <header class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-primary-50 text-primary-600 dark:bg-primary-950/30 dark:text-primary-300"><Icon class="text-xl" name="shield-check" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 id="effective-access-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Accès effectifs de {{ form.name }}</h2>
                        <p class="mt-1 text-xs text-slate-500">Aperçu calculé : interdiction individuelle, puis autorisation individuelle, puis socle du rôle {{ selectedRole?.name }}.</p>
                    </div>
                    <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded border border-gray-200 text-slate-400 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" aria-label="Fermer l’aperçu" @click="showAccessPreview = false"><Icon class="text-lg" name="cross" /></button>
                </header>
                <div class="grid grid-cols-3 gap-px bg-gray-200 dark:bg-gray-800">
                    <div class="bg-white px-4 py-3 dark:bg-gray-950"><p class="text-[10px] font-bold uppercase text-slate-400">Rôle</p><p class="mt-0.5 text-sm font-bold text-slate-700 dark:text-white">{{ selectedRole?.name }}</p></div>
                    <div class="bg-white px-4 py-3 dark:bg-gray-950"><p class="text-[10px] font-bold uppercase text-slate-400">Autorisées</p><p class="mt-0.5 text-sm font-bold text-emerald-600">{{ permissionSummary.effectiveAllowed }} / {{ permissionSummary.total }}</p></div>
                    <div class="bg-white px-4 py-3 dark:bg-gray-950"><p class="text-[10px] font-bold uppercase text-slate-400">Exceptions</p><p class="mt-0.5 text-sm font-bold text-primary-600">{{ permissionSummary.exceptions }}</p></div>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
                    <div class="grid gap-3 md:grid-cols-2">
                        <article v-for="group in effectivePermissionGroups" :key="group.key" class="overflow-hidden rounded border border-gray-200 dark:border-gray-800">
                            <header class="flex items-center justify-between bg-gray-50 px-3 py-2 dark:bg-gray-1000"><h3 class="text-xs font-bold text-slate-700 dark:text-white">{{ group.label }}</h3><span class="text-[10px] text-slate-400">{{ group.allowed.length }} / {{ group.total }}</span></header>
                            <ul class="divide-y divide-gray-100 dark:divide-gray-900">
                                <li v-for="permission in group.allowed" :key="`allowed-${permission.id}`" class="flex items-center gap-2 px-3 py-1.5 text-xs text-slate-600 dark:text-slate-300"><Icon class="shrink-0 text-sm text-emerald-500" name="check-circle" /><span class="min-w-0 flex-1 truncate">{{ permission.label }}</span></li>
                                <li v-for="permission in group.denied" :key="`denied-${permission.id}`" class="flex items-center gap-2 px-3 py-1.5 text-xs text-slate-400"><Icon class="shrink-0 text-sm text-red-400" name="cross-circle" /><span class="min-w-0 flex-1 truncate">{{ permission.label }}</span></li>
                            </ul>
                        </article>
                    </div>
                </div>
                <footer class="flex justify-end border-t border-gray-200 px-5 py-3 dark:border-gray-900"><Button size="rg" variant="primary" type="button" @click="showAccessPreview = false">Fermer</Button></footer>
            </section>
        </div>

        <div v-if="pendingPermissionChange" class="fixed inset-0 z-[1210] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="pendingPermissionChange = null">
            <section class="w-full max-w-md rounded-lg border border-amber-200 bg-white p-5 shadow-xl dark:border-amber-900 dark:bg-gray-950" role="alertdialog" aria-modal="true" aria-labelledby="sensitive-permission-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/30 dark:text-amber-300"><Icon class="text-xl" name="alert-circle" /></span>
                    <div><h2 id="sensitive-permission-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Autoriser cette permission sensible ?</h2><p class="mt-1 text-sm leading-5 text-slate-500"><strong>{{ pendingPermissionChange.permission.label }}</strong> permet une opération critique. Cette exception sera appliquée uniquement à ce compte et auditée lors de l’enregistrement.</p><code class="mt-2 block rounded bg-gray-100 px-2 py-1 text-xs text-slate-500 dark:bg-gray-900">{{ pendingPermissionChange.permission.name }}</code></div>
                </div>
                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Button size="rg" variant="white-outline" type="button" @click="pendingPermissionChange = null">Annuler</Button><Button size="rg" variant="primary" type="button" @click="confirmPermissionChange">Autoriser la permission</Button></div>
            </section>
        </div>

        <div v-if="pendingBulkAction" class="fixed inset-0 z-[1210] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="pendingBulkAction = null">
            <section class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="alertdialog" aria-modal="true" aria-labelledby="bulk-permissions-title">
                <div class="flex items-start gap-3">
                    <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', pendingBulkAction.effect === 'deny' ? 'bg-red-50 text-red-600 dark:bg-red-950/30' : pendingBulkAction.effect === 'allow' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30' : 'bg-gray-100 text-slate-600 dark:bg-gray-900']"><Icon class="text-xl" :name="pendingBulkAction.effect === 'deny' ? 'shield-off' : pendingBulkAction.effect === 'allow' ? 'shield-check' : 'undo'" /></span>
                    <div>
                        <h2 id="bulk-permissions-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Confirmer l’action groupée ?</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500"><strong>{{ pendingBulkAction.permissions.length }} permission{{ pendingBulkAction.permissions.length > 1 ? 's' : '' }}</strong> de « {{ pendingBulkAction.scope }} » passeront à l’état <strong>{{ pendingBulkAction.effect === 'allow' ? 'Autoriser' : pendingBulkAction.effect === 'deny' ? 'Interdire' : 'Hériter du rôle' }}</strong>.</p>
                        <p v-if="pendingBulkAction.effect === 'allow' && pendingBulkAction.permissions.some(isSensitivePermission)" class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200"><Icon class="me-1 text-sm" name="alert-circle" />Cette sélection contient des permissions sensibles. Vérifiez l’aperçu des accès avant l’enregistrement.</p>
                    </div>
                </div>
                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Button size="rg" variant="white-outline" type="button" @click="pendingBulkAction = null">Annuler</Button><Button size="rg" :variant="pendingBulkAction.effect === 'deny' ? 'secondary' : 'primary'" type="button" @click="confirmBulkAction">Appliquer au brouillon</Button></div>
            </section>
        </div>

        <div v-if="confirmingDiscard" class="fixed inset-0 z-[1210] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="confirmingDiscard = false">
            <section class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="alertdialog" aria-modal="true" aria-labelledby="discard-permissions-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/30"><Icon class="text-xl" name="alert-circle" /></span>
                    <div>
                        <h2 id="discard-permissions-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Quitter sans enregistrer ?</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500"><strong>{{ permissionChangesCount }} modification{{ permissionChangesCount > 1 ? 's' : '' }} de permission{{ permissionChangesCount > 1 ? 's' : '' }}</strong> n’{{ permissionChangesCount > 1 ? 'ont' : 'a' }} pas été envoyée{{ permissionChangesCount > 1 ? 's' : '' }} au site. Si vous quittez, le compte garde exactement ses accès actuels.</p>
                    </div>
                </div>
                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" @click="confirmDiscard">Quitter sans enregistrer</Button>
                    <Button size="rg" variant="primary" type="button" @click="confirmingDiscard = false">Continuer la modification</Button>
                </div>
            </section>
        </div>

        <div v-if="deactivateTargets.length" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeDeactivate">
            <section class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="deactivate-user-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-xl" name="lock" /></span>
                    <div>
                        <h2 id="deactivate-user-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">{{ deactivateTargets.length === 1 ? `Désactiver ${deactivateTargets[0].name} ?` : `Désactiver ${deactivateTargets.length} comptes ?` }}</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500">La connexion sera bloquée et les sessions ouvertes seront révoquées sur {{ selectedSite?.site.name }}. L’historique reste conservé.</p>
                        <ul v-if="deactivateTargets.length > 1" class="mt-2 max-h-28 overflow-y-auto rounded border border-gray-200 px-3 py-2 text-xs text-slate-500 dark:border-gray-800">
                            <li v-for="user in deactivateTargets" :key="user.uuid">{{ user.name }}</li>
                        </ul>
                    </div>
                </div>
                <div class="mt-5">
                    <label for="deactivation_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="font-normal text-slate-400">(pré-rempli, modifiable)</span></label>
                    <textarea id="deactivation_reason" v-model="deactivationForm.reason" rows="3" autofocus placeholder="Ex. fin de contrat ou changement d’affectation" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none transition-all placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950"></textarea>
                    <FormError v-if="deactivationForm.errors.reason">{{ deactivationForm.errors.reason }}</FormError>
                    <FormError v-if="deactivationForm.errors.uuids">{{ deactivationForm.errors.uuids }}</FormError>
                    <FormError v-if="deactivationForm.errors.user">{{ deactivationForm.errors.user }}</FormError>
                </div>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" :disabled="deactivationForm.processing" @click="closeDeactivate">Annuler</Button>
                    <Button size="rg" variant="secondary" type="button" :disabled="deactivationForm.processing" @click="confirmDeactivate"><Icon class="text-lg" name="lock" /><span class="ms-2">{{ deactivationForm.processing ? 'Désactivation…' : (deactivateTargets.length === 1 ? 'Désactiver le compte' : `Désactiver ${deactivateTargets.length} comptes`) }}</span></Button>
                </div>
            </section>
        </div>

        <div v-if="forceDeleteTargets.length" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeForceDelete">
            <section class="w-full max-w-md rounded-lg border border-red-200 bg-white p-6 shadow-xl dark:border-red-900 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="force-delete-user-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/30"><Icon class="text-xl" name="trash" /></span>
                    <div>
                        <h2 id="force-delete-user-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">{{ forceDeleteTargets.length === 1 ? `Supprimer ${forceDeleteTargets[0].name} ?` : `Supprimer ${forceDeleteTargets.length} comptes ?` }}</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500">Action <strong class="text-red-600 dark:text-red-400">irréversible</strong> et distincte de la désactivation : {{ forceDeleteTargets.length === 1 ? 'le compte est' : 'les comptes sont' }} retiré{{ forceDeleteTargets.length > 1 ? 's' : '' }} de la base, pas seulement bloqué{{ forceDeleteTargets.length > 1 ? 's' : '' }}. Possible uniquement parce {{ forceDeleteTargets.length === 1 ? "qu'il n'a" : "qu'ils n'ont" }} jamais servi.</p>
                        <ul v-if="forceDeleteTargets.length > 1" class="mt-2 max-h-28 overflow-y-auto rounded border border-gray-200 px-3 py-2 text-xs text-slate-500 dark:border-gray-800">
                            <li v-for="user in forceDeleteTargets" :key="user.uuid">{{ user.name }}</li>
                        </ul>
                    </div>
                </div>
                <div class="mt-5">
                    <label for="force_delete_confirm" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">
                        <template v-if="forceDeleteTargets.length === 1">Tapez <span class="font-mono font-bold">{{ forceDeleteTargets[0].email }}</span> pour confirmer</template>
                        <template v-else>Tapez <span class="font-mono font-bold">SUPPRIMER</span> pour confirmer</template>
                    </label>
                    <input id="force_delete_confirm" v-model="forceDeleteConfirmText" type="text" autocomplete="off" autofocus class="block h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-red-400 focus:ring-2 focus:ring-red-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                    <FormError v-if="forceDeleteForm.errors.user">{{ forceDeleteForm.errors.user }}</FormError>
                    <FormError v-if="forceDeleteForm.errors.uuids">{{ forceDeleteForm.errors.uuids }}</FormError>
                </div>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" :disabled="forceDeleteForm.processing" @click="closeForceDelete">Annuler</Button>
                    <Button size="rg" variant="danger" type="button" :disabled="!forceDeleteConfirmed || forceDeleteForm.processing" @click="confirmForceDelete"><Icon class="text-lg" name="trash" /><span class="ms-2">{{ forceDeleteForm.processing ? 'Suppression…' : (forceDeleteTargets.length === 1 ? 'Supprimer définitivement' : `Supprimer ${forceDeleteTargets.length} comptes`) }}</span></Button>
                </div>
            </section>
        </div>
    </div>
</template>
