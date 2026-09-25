<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Briefcase,
    Check,
    ChevronRight,
    Circle,
    CircleCheck,
    ExternalLink,
    Eye,
    EyeOff,
    IdCard,
    Info,
    KeyRound,
    LayoutGrid,
    List,
    Loader2,
    Lock,
    LockOpen,
    Mail,
    Pencil,
    Search,
    Send,
    Server,
    ShieldCheck,
    Trash2,
    TriangleAlert,
    UserPlus,
    UserRound,
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
import AccountKindPicker from '@/Components/Users/AccountKindPicker.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { matchesSearchTerms } from '@/utilities/permissionWorkspace';
import { roleDescription, roleInitials } from '@/utilities/roleDescriptions';

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
    { n: 1, label: 'Informations', description: 'Identité et email de connexion', icon: UserRound },
    { n: 2, label: 'Rôle', description: 'Rôle et profil métier', icon: Briefcase },
];

const form = useForm({
    site_code: selectedSiteCode.value,
    name: '',
    email: '',
    role_id: '',
    professional_profile_id: '',
    password: '',
    password_confirmation: '',
    // ADR-188 — personnel clinique (une fiche Employé) ou externe : choisi, jamais par défaut.
    account_kind: '',
    employee_uuid: '',
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
const employees = computed(() => selectedSite.value?.data?.employees ?? []);
const createEmployeeHref = computed(() => (can('employees.create') ? `/super-admin/sites/${selectedSiteCode.value}/rh/employees/create` : ''));
const accountKindLabel = (user) => (user.account_kind === 'STAFF' ? 'Personnel clinique' : user.account_kind === 'EXTERNAL' ? 'Externe' : null);

/**
 * Choisir une fiche propose son nom et son email au compte, sans écraser une
 * saisie : un champ n'est repris que s'il est vide ou s'il vient de la fiche
 * choisie juste avant.
 */
const prefilled = ref({ name: '', email: '' });
// ADR-188 — la fiche choisie donne le nom et l'email du compte. Une saisie
// faite à la main n'est jamais écrasée ; une valeur reprise d'une fiche
// précédente, si : changer de personne ne garde pas l'email de l'autre.
const onEmployeePick = (employee) => {
    if (form.name.trim() === '' || form.name === prefilled.value.name) form.name = employee.name;
    if (form.email.trim() === '' || form.email === prefilled.value.email) form.email = employee.email ?? '';
    prefilled.value = { name: employee.name, email: employee.email ?? '' };
    emailTouched.value = false;
    // La fiche RH n'a pas d'email : c'est la seule chose qui reste à saisir.
    if (! employee.email) nextTick(() => document.getElementById('user-email')?.focus());
};
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
const kindReady = computed(() => form.account_kind === 'EXTERNAL' || (form.account_kind === 'STAFF' && form.employee_uuid !== ''));

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

/**
 * Une adresse plausible. Le site valide l'email de toute façon ; ce contrôle
 * empêche seulement « Suivant » de s'ouvrir sur une faute de frappe.
 */
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const emailValid = computed(() => EMAIL_PATTERN.test(form.email.trim()));
const emailTouched = ref(false);
const emailHint = computed(() => (emailTouched.value && form.email.trim() !== '' && ! emailValid.value
    ? 'Adresse invalide : vérifiez le « @ » et le domaine.'
    : ''));

const passwordOpen = ref(false);
const passwordTooShort = computed(() => form.password !== '' && form.password.length < 12);
const passwordMismatch = computed(() => form.password !== '' && form.password_confirmation !== '' && form.password !== form.password_confirmation);

/** Refermer le changement de mot de passe l'abandonne : le mot de passe actuel reste. */
const togglePassword = () => {
    passwordOpen.value = ! passwordOpen.value;

    if (! passwordOpen.value) {
        form.password = '';
        form.password_confirmation = '';
    }
};

const step1Valid = computed(() => {
    if (! kindReady.value) return false;
    if (form.name.trim() === '' || ! emailValid.value) return false;
    // Password is only ever set here when editing — creation never asks
    // for one, the account is provisioned by email invitation instead.
    if (isEditing.value && form.password !== '' && form.password.length < 12) return false;
    if (form.password !== '' && form.password !== form.password_confirmation) return false;
    return true;
});
const step2Valid = computed(() => form.role_id !== '' && (!selectedRoleProfiles.value.length || form.professional_profile_id !== ''));

/** Ce qui retient le bouton de l'étape : dit en clair, jamais un bouton grisé muet (ADR-154). */
const blocker = computed(() => {
    if (step.value === 1) {
        if (form.account_kind === '') return 'Indiquez s’il s’agit du personnel de la clinique ou d’une personne externe.';
        if (form.account_kind === 'STAFF' && form.employee_uuid === '') return 'Choisissez la fiche employé de cette personne.';
        if (form.name.trim() === '') return 'Renseignez le nom complet.';
        if (form.email.trim() === '') return 'Renseignez l’email professionnel.';
        if (! emailValid.value) return 'L’adresse email n’est pas valide.';
        if (passwordTooShort.value) return 'Le mot de passe doit compter au moins 12 caractères.';
        if (form.password !== '' && form.password !== form.password_confirmation) return 'La confirmation ne correspond pas au mot de passe.';

        return '';
    }

    if (form.role_id === '') return 'Choisissez un rôle métier.';
    if (selectedRoleProfiles.value.length && form.professional_profile_id === '') return 'Choisissez le profil professionnel.';

    return '';
});

const checklist = computed(() => [
    { key: 'kind', label: form.account_kind === 'STAFF' ? 'Fiche employé reliée' : 'Personnel clinique ou externe', done: kindReady.value },
    { key: 'name', label: 'Nom complet', done: form.name.trim() !== '' },
    { key: 'email', label: 'Email professionnel valide', done: emailValid.value },
    { key: 'role', label: 'Rôle métier', done: form.role_id !== '' },
    ...(selectedRoleProfiles.value.length ? [{ key: 'profile', label: 'Profil professionnel', done: form.professional_profile_id !== '' }] : []),
]);

/** Plus de six rôles : une recherche plutôt qu'une grille à parcourir. */
const roleSearch = ref('');
const shownRoles = computed(() => roles.value.filter((role) => matchesSearchTerms(
    `${role.name} ${role.code} ${roleDescription(role.code)}`,
    roleSearch.value,
)));

/** Le lien vers le socle d'un rôle, ou les exceptions d'un compte, dans « Rôles & permissions ». */
const rolesScreenUrl = (params) => `/super-admin/workspaces/roles?${new URLSearchParams({ site: selectedSiteCode.value, ...params })}`;

const openCreate = () => {
    editingUser.value = null;
    form.reset();
    form.clearErrors();
    form.site_code = selectedSiteCode.value;
    // Aucun rôle présélectionné : un compte reçoit le rôle qu'on lui choisit,
    // jamais le premier de la liste par défaut.
    form.role_id = '';
    form.professional_profile_id = '';
    resetFormHelpers();
    step.value = 1;
    maxStepReached.value = 1;
    view.value = 'form';
    focusField('account-kind-staff');
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
    form.account_kind = user.account_kind ?? '';
    form.employee_uuid = user.employee?.uuid ?? '';
    resetFormHelpers();
    step.value = 1;
    maxStepReached.value = 2;
    view.value = 'form';
    focusField('user-name');
};

function resetFormHelpers() {
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    passwordOpen.value = false;
    emailTouched.value = false;
    roleSearch.value = '';
    prefilled.value = { name: '', email: '' };
}

/** Le formulaire remplace la liste : on repart du haut, le curseur dans le premier champ. */
async function focusField(id) {
    window.scrollTo({ top: 0 });
    await nextTick();
    document.getElementById(id)?.focus();
}

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
 * Entrée valide l'étape en cours, jamais le compte : à l'étape 1, elle mène au
 * rôle au lieu d'envoyer un compte dont personne n'a encore choisi le rôle.
 */
const onFormSubmit = () => {
    if (step.value < 2) {
        nextStep();
        return;
    }

    submitUser();
};

/** Changer d'étape ramène en haut du formulaire, sur son titre. */
const stepHeading = ref(null);

watch(step, async () => {
    await nextTick();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    stepHeading.value?.focus({ preventScroll: true });
});

/**
 * Une erreur du site sur le nom, l'email ou le mot de passe (« email déjà
 * utilisé ») arrive pendant qu'on est à l'étape 2 : on revient là où se
 * corrige le champ, au lieu de laisser un bouton qui échoue sans rien dire.
 */
const STEP_ONE_FIELDS = ['account_kind', 'employee_uuid', 'name', 'email', 'password', 'password_confirmation'];

watch(() => form.errors, (errors) => {
    if (step.value === 2 && STEP_ONE_FIELDS.some((field) => errors?.[field])) {
        step.value = 1;
        if (errors.password) passwordOpen.value = true;
    }
}, { deep: true });

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
    const role = roles.value.find((item) => Number(item.id) === Number(roleId));

    if (! role) return;

    // Un rôle à profils se choisit avec son profil, dans une fenêtre : le
    // compte ne porte jamais un rôle sans le profil qu'il exige.
    if (role.profiles?.length) {
        openProfileDialog(role);
        return;
    }

    form.role_id = role.id;
    form.professional_profile_id = '';
};

/**
 * La fenêtre du profil. Rien n'est écrit dans le formulaire avant
 * « Valider » : annuler (bouton, Échap, clic à côté) rend exactement le rôle
 * et le profil d'avant.
 */
const pendingRole = ref(null);
const profileDraft = ref('');

const openProfileDialog = (role) => {
    pendingRole.value = role;
    profileDraft.value = Number(form.role_id) === Number(role.id) ? form.professional_profile_id : '';
};

const pendingProfiles = computed(() => pendingRole.value?.profiles ?? []);
const draftProfile = computed(() => pendingProfiles.value.find((profile) => Number(profile.id) === Number(profileDraft.value)) ?? null);

/** En modification, choisir un autre profil retire ce qu'apportait l'ancien (ADR-033) : on le dit avant. */
const draftProfileChanged = computed(() => isEditing.value
    && draftProfile.value !== null
    && Number(editingUser.value?.professional_profile?.id ?? 0) !== Number(draftProfile.value.id));

const selectProfile = (profileId) => {
    profileDraft.value = profileId;
};

const confirmProfile = () => {
    if (! pendingRole.value || ! draftProfile.value) return;

    form.role_id = pendingRole.value.id;
    form.professional_profile_id = draftProfile.value.id;
    pendingRole.value = null;
};

const cancelProfile = () => {
    pendingRole.value = null;
    profileDraft.value = '';
};

const roleRequiresProfile = (roleId) => Boolean(roles.value.find((item) => Number(item.id) === Number(roleId))?.profiles?.length);

/**
 * Le payload ne porte plus `permission_overrides` : omise, la clé laisse les
 * exceptions du compte intactes côté serveur (`UpdateUserAction`). Les
 * modifier est le geste de l'écran « Rôles & permissions ».
 */
const submitUser = () => {
    const options = { preserveScroll: true, onSuccess: dismissForm };

    // Un compte externe n'envoie aucune fiche ; un choix absent n'envoie rien (le lien reste tel quel).
    form.transform((data) => {
        const payload = { ...data, employee_uuid: data.account_kind === 'STAFF' ? data.employee_uuid : null };
        if (! payload.account_kind) {
            delete payload.account_kind;
            delete payload.employee_uuid;
        }

        return payload;
    });

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
                                            <span v-if="accountKindLabel(user)" class="mt-1 flex items-center gap-1 text-[11px] text-muted-foreground" :title="user.employee ? `Fiche ${user.employee.employee_number}` : 'Aucune fiche employé'">
                                                <component :is="user.employee ? IdCard : UserRound" class="h-3 w-3" />{{ accountKindLabel(user) }}<template v-if="user.employee"> · <span class="font-mono">{{ user.employee.employee_number }}</span></template>
                                            </span>
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
                                <p v-if="accountKindLabel(user)" class="mt-1 flex items-center gap-1 text-[11px] text-muted-foreground">
                                    <component :is="user.employee ? IdCard : UserRound" class="h-3 w-3" />{{ accountKindLabel(user) }}<template v-if="user.employee"> · <span class="font-mono">{{ user.employee.employee_number }}</span></template>
                                </p>
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
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <Button type="button" size="icon" variant="outline" class="h-10 w-10 shrink-0" aria-label="Retour à la liste" @click="closeForm"><ArrowLeft class="h-4.5 w-4.5" /></Button>
                    <div class="min-w-0">
                        <nav class="flex items-center gap-1 text-xs font-medium text-muted-foreground" aria-label="Fil d’Ariane">
                            <button type="button" class="rounded hover:text-foreground hover:underline" @click="closeForm">Utilisateurs</button>
                            <ChevronRight class="h-3.5 w-3.5" aria-hidden="true" />
                            <span class="truncate">{{ selectedSite?.site.name }}</span>
                        </nav>
                        <h1 class="mt-0.5 truncate font-heading text-2xl font-bold tracking-tight text-foreground">{{ isEditing ? `Modifier ${editingUser.name}` : 'Créer un utilisateur' }}</h1>
                    </div>
                </div>
                <Badge variant="outline" class="self-start sm:self-auto">
                    <Server class="h-3.5 w-3.5" />Enregistré sur {{ selectedSite?.site.name }}, via l’API du site
                </Badge>
            </header>

            <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_19rem] xl:grid-cols-[minmax(0,1fr)_21rem]">
                <form class="min-w-0 space-y-5" novalidate @submit.prevent="onFormSubmit">
                    <nav class="overflow-hidden rounded-xl border border-border bg-card shadow-sm" aria-label="Étapes">
                        <ol class="grid grid-cols-2">
                            <li v-for="(s, index) in steps" :key="s.n" :class="index > 0 ? 'border-s border-border' : ''">
                                <button
                                    type="button"
                                    :disabled="s.n > maxStepReached"
                                    :aria-current="step === s.n ? 'step' : undefined"
                                    :class="cn(
                                        'relative flex w-full items-center gap-3 px-4 py-3.5 text-start transition-colors sm:px-5',
                                        s.n > maxStepReached ? 'cursor-not-allowed' : 'hover:bg-accent/50',
                                        step === s.n ? 'bg-primary/5' : '',
                                    )"
                                    @click="goToStep(s.n)"
                                >
                                    <span :class="cn(
                                        'grid h-9 w-9 shrink-0 place-items-center rounded-full ring-1 transition-colors',
                                        step === s.n ? 'bg-primary text-primary-foreground ring-primary'
                                            : s.n < step ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900'
                                                : 'bg-muted text-muted-foreground ring-border',
                                    )">
                                        <Check v-if="s.n < step" class="h-4 w-4" :stroke-width="3" />
                                        <component :is="s.icon" v-else class="h-4 w-4" />
                                    </span>
                                    <span class="min-w-0">
                                        <span :class="cn('block text-[11px] font-semibold uppercase tracking-wide', step === s.n ? 'text-primary' : 'text-muted-foreground')">
                                            Étape {{ s.n }} sur {{ steps.length }}<template v-if="s.n < step"> · terminée</template>
                                        </span>
                                        <span :class="cn('block truncate text-sm font-bold', s.n > maxStepReached ? 'text-muted-foreground' : 'text-foreground')">{{ s.label }}</span>
                                        <span class="hidden truncate text-xs text-muted-foreground sm:block">{{ s.description }}</span>
                                    </span>
                                    <span v-if="step === s.n" class="absolute inset-x-0 bottom-0 h-0.5 bg-primary" aria-hidden="true" />
                                </button>
                            </li>
                        </ol>
                    </nav>

                    <div
                        v-if="form.errors.site_code || form.errors.user"
                        class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
                        role="alert"
                    >
                        <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ form.errors.site_code || form.errors.user }}</span>
                    </div>

                    <!-- Étape 1 — qui est la personne. -->
                    <Card v-if="step === 1" class="overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-border px-5 py-4 sm:px-6">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><UserRound class="h-5 w-5" /></span>
                            <div class="min-w-0">
                                <h2 ref="stepHeading" tabindex="-1" class="text-base font-bold text-foreground focus:outline-none">Informations du compte</h2>
                                <p class="mt-0.5 text-sm text-muted-foreground">Un compte nominatif, pour une seule personne — jamais générique ni partagé.</p>
                            </div>
                        </div>

                        <div class="space-y-6 px-5 py-5 sm:px-6">
                            <AccountKindPicker
                                v-model:kind="form.account_kind"
                                v-model:employee-uuid="form.employee_uuid"
                                :employees="employees"
                                :current-user-uuid="editingUser?.uuid ?? ''"
                                :errors="form.errors"
                                :create-employee-href="createEmployeeHref"
                                :site-name="selectedSite?.site.name ?? ''"
                                @pick="onEmployeePick"
                            />

                            <div v-if="identityVisible" class="grid gap-5 md:grid-cols-2">
                                <div>
                                    <FormField label="Nom complet" required :error="form.errors.name">
                                        <IconInput
                                            id="user-name"
                                            v-model="form.name"
                                            :icon="UserRound"
                                            placeholder="Ex. Rakoto Andry"
                                            autocomplete="name"
                                            :aria-invalid="Boolean(form.errors.name)"
                                        />
                                    </FormField>
                                    <p v-if="! form.errors.name && fromStaffRecord && form.name === prefilled.name" class="mt-1.5 flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-300"><CircleCheck class="h-3.5 w-3.5" />Repris de la fiche RH — modifiable.</p>
                                    <p v-else-if="! form.errors.name" class="mt-1.5 text-xs text-muted-foreground">Prénom et nom, tels qu’ils apparaîtront dans l’audit.</p>
                                </div>
                                <div>
                                    <FormField label="Email professionnel" required :error="form.errors.email || emailHint">
                                        <IconInput
                                            id="user-email"
                                            v-model="form.email"
                                            :icon="Mail"
                                            type="email"
                                            inputmode="email"
                                            placeholder="prenom.nom@exemple.mg"
                                            autocomplete="off"
                                            spellcheck="false"
                                            :aria-invalid="Boolean(form.errors.email || emailHint)"
                                            @blur="emailTouched = true"
                                        />
                                    </FormField>
                                    <template v-if="! form.errors.email && ! emailHint">
                                        <p v-if="fromStaffRecord && prefilled.email === '' && form.email.trim() === ''" class="mt-1.5 flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300"><Info class="h-3.5 w-3.5 shrink-0" />La fiche RH n’a pas d’email : saisissez l’adresse professionnelle.</p>
                                        <p v-else-if="fromStaffRecord && prefilled.email !== '' && form.email === prefilled.email" class="mt-1.5 flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-300"><CircleCheck class="h-3.5 w-3.5 shrink-0" />Repris de la fiche RH — sert d’identifiant de connexion sur {{ selectedSite?.site.name }}.</p>
                                        <p v-else class="mt-1.5 text-xs text-muted-foreground">Sert d’identifiant de connexion sur {{ selectedSite?.site.name }}.</p>
                                    </template>
                                </div>
                            </div>

                            <!-- Création : pas de mot de passe, une invitation. -->
                            <section v-if="! isEditing" class="rounded-xl border border-border bg-muted/30 p-4" aria-labelledby="invitation-title">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><Send class="h-4 w-4" /></span>
                                    <div class="min-w-0">
                                        <p id="invitation-title" class="text-sm font-bold text-foreground">Aucun mot de passe à saisir</p>
                                        <p class="mt-0.5 text-xs leading-5 text-muted-foreground">Le compte s’active par invitation : la personne choisit elle-même son mot de passe.</p>
                                    </div>
                                </div>
                                <ol class="mt-4 grid gap-2 sm:grid-cols-3">
                                    <li class="flex items-start gap-2.5 rounded-lg border border-border bg-card p-3">
                                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground">1</span>
                                        <span class="text-xs leading-5 text-muted-foreground"><strong class="block text-foreground">Compte créé</strong>sur {{ selectedSite?.site.name }}, avec le rôle choisi.</span>
                                    </li>
                                    <li class="flex items-start gap-2.5 rounded-lg border border-border bg-card p-3">
                                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground">2</span>
                                        <span class="min-w-0 text-xs leading-5 text-muted-foreground"><strong class="block text-foreground">Email envoyé</strong>à <span class="break-all font-medium text-foreground">{{ emailValid ? form.email.trim() : 'l’adresse renseignée' }}</span>, avec l’identifiant et un lien.</span>
                                    </li>
                                    <li class="flex items-start gap-2.5 rounded-lg border border-border bg-card p-3">
                                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground">3</span>
                                        <span class="text-xs leading-5 text-muted-foreground"><strong class="block text-foreground">Mot de passe choisi</strong>par la personne elle-même, en suivant le lien.</span>
                                    </li>
                                </ol>
                            </section>

                            <!-- Modification : le mot de passe ne change que si on le demande. -->
                            <section v-else class="rounded-xl border border-border">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-3 px-4 py-3 text-start transition-colors hover:bg-accent/40"
                                    :aria-expanded="passwordOpen"
                                    aria-controls="user-password-fields"
                                    @click="togglePassword"
                                >
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground" aria-hidden="true"><KeyRound class="h-4 w-4" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-bold text-foreground">Mot de passe</span>
                                        <span class="block text-xs text-muted-foreground">{{ passwordOpen ? 'Un nouveau mot de passe remplacera l’actuel à l’enregistrement.' : 'Inchangé — le mot de passe actuel est conservé.' }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs font-semibold text-primary">{{ passwordOpen ? 'Ne pas changer' : 'Changer' }}</span>
                                </button>
                                <div v-if="passwordOpen" id="user-password-fields" class="grid gap-5 border-t border-border px-4 py-4 md:grid-cols-2">
                                    <div>
                                        <FormField as="div" label="Nouveau mot de passe" :error="form.errors.password">
                                            <div class="relative">
                                                <IconInput v-model="form.password" :icon="KeyRound" :type="showPassword ? 'text' : 'password'" class="pe-10" autocomplete="new-password" :aria-invalid="Boolean(form.errors.password || passwordTooShort)" aria-label="Nouveau mot de passe" />
                                                <button type="button" class="absolute inset-y-0 end-0 z-10 grid w-10 place-items-center text-muted-foreground hover:text-foreground" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword">
                                                    <component :is="showPassword ? EyeOff : Eye" class="h-4 w-4" />
                                                </button>
                                            </div>
                                        </FormField>
                                        <p :class="cn('mt-1.5 flex items-center gap-1.5 text-xs', form.password.length >= 12 ? 'text-emerald-700 dark:text-emerald-300' : 'text-muted-foreground')">
                                            <component :is="form.password.length >= 12 ? CircleCheck : Info" class="h-3.5 w-3.5" />12 caractères au moins<template v-if="form.password"> · {{ form.password.length }}</template>
                                        </p>
                                    </div>
                                    <div>
                                        <FormField as="div" label="Confirmation" :error="passwordMismatch ? 'Ne correspond pas au mot de passe.' : ''">
                                            <div class="relative">
                                                <IconInput v-model="form.password_confirmation" :icon="KeyRound" :type="showPasswordConfirmation ? 'text' : 'password'" class="pe-10" autocomplete="new-password" :aria-invalid="passwordMismatch" aria-label="Confirmation du mot de passe" />
                                                <button type="button" class="absolute inset-y-0 end-0 z-10 grid w-10 place-items-center text-muted-foreground hover:text-foreground" :aria-label="showPasswordConfirmation ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPasswordConfirmation = !showPasswordConfirmation">
                                                    <component :is="showPasswordConfirmation ? EyeOff : Eye" class="h-4 w-4" />
                                                </button>
                                            </div>
                                        </FormField>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </Card>

                    <!-- Étape 2 — ce que la personne fait à la clinique. -->
                    <Card v-else-if="step === 2" class="overflow-hidden">
                        <div class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><Briefcase class="h-5 w-5" /></span>
                                <div class="min-w-0">
                                    <h2 ref="stepHeading" tabindex="-1" class="text-base font-bold text-foreground focus:outline-none">Rôle et profil métier</h2>
                                    <p class="mt-0.5 text-sm text-muted-foreground">Le rôle donne le socle de droits commun au service. Les exceptions de ce compte se règlent ensuite dans « Rôles &amp; permissions ».</p>
                                </div>
                            </div>
                            <div v-if="roles.length > 6" class="w-full sm:w-60 sm:shrink-0">
                                <IconInput v-model="roleSearch" :icon="Search" type="search" placeholder="Rechercher un rôle…" autocomplete="off" aria-label="Rechercher un rôle" @keydown.enter.prevent />
                            </div>
                        </div>

                        <div class="space-y-6 px-5 py-5 sm:px-6">
                            <fieldset>
                                <legend class="mb-2.5 text-sm font-semibold text-foreground">Rôle métier<span class="ms-0.5 text-destructive">*</span></legend>
                                <div class="grid gap-2 sm:grid-cols-2 2xl:grid-cols-3" role="radiogroup" aria-label="Rôle métier">
                                    <button
                                        v-for="role in shownRoles"
                                        :key="role.id"
                                        type="button"
                                        role="radio"
                                        :aria-checked="Number(form.role_id) === Number(role.id)"
                                        :aria-haspopup="role.profiles.length ? 'dialog' : undefined"
                                        :title="roleDescription(role.code) || 'Rôle créé depuis le portail.'"
                                        :class="cn(
                                            'group flex items-center gap-2.5 rounded-lg border px-3 py-2.5 text-start transition-[border-color,background-color,box-shadow] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            Number(form.role_id) === Number(role.id) ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-card hover:border-primary/40 hover:bg-accent/30',
                                        )"
                                        @click="selectRole(role.id)"
                                    >
                                        <span :class="cn(
                                            'grid h-8 w-8 shrink-0 place-items-center rounded-md text-[11px] font-bold',
                                            Number(form.role_id) === Number(role.id) ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground group-hover:text-foreground',
                                        )" aria-hidden="true">{{ roleInitials(role.name) }}</span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-baseline justify-between gap-2">
                                                <span class="truncate text-sm font-semibold text-foreground">{{ role.name }}</span>
                                                <span class="inline-flex shrink-0 items-center gap-2 text-[11px] tabular-nums text-muted-foreground">
                                                    <span class="inline-flex items-center gap-0.5" :title="`${role.permissions.length} droit(s) par défaut`"><ShieldCheck class="h-3 w-3" />{{ role.permissions.length }}</span>
                                                    <span v-if="role.profiles.length" class="inline-flex items-center gap-0.5" :title="`${role.profiles.length} profil(s) métier`"><IdCard class="h-3 w-3" />{{ role.profiles.length }}</span>
                                                </span>
                                            </span>
                                            <span
                                                v-if="Number(form.role_id) === Number(role.id) && selectedProfile"
                                                class="mt-0.5 block truncate text-xs font-medium text-primary"
                                            >Profil : {{ selectedProfile.name }}</span>
                                            <span
                                                v-else-if="Number(form.role_id) === Number(role.id) && role.profiles.length"
                                                class="mt-0.5 block truncate text-xs font-medium text-amber-700 dark:text-amber-300"
                                            >Profil à choisir</span>
                                            <span v-else class="mt-0.5 block truncate text-xs text-muted-foreground">{{ roleDescription(role.code) || 'Rôle créé depuis le portail.' }}</span>
                                        </span>
                                        <CircleCheck v-if="Number(form.role_id) === Number(role.id)" class="h-4 w-4 shrink-0 text-primary" />
                                        <Circle v-else class="h-4 w-4 shrink-0 text-muted-foreground/40" />
                                    </button>
                                </div>
                                <p v-if="! shownRoles.length" class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                                    Aucun rôle ne correspond à « {{ roleSearch }} ».
                                    <button type="button" class="font-semibold text-primary hover:underline" @click="roleSearch = ''">Tout afficher</button>
                                </p>
                                <FormError v-if="form.errors.role_id">{{ form.errors.role_id }}</FormError>
                                <p class="mt-2 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                    <IdCard class="h-3 w-3" />Un rôle marqué d’un nombre de profils demande de choisir le profil métier : une fenêtre s’ouvre à la sélection.
                                </p>
                            </fieldset>

                            <!-- Le profil choisi, rappelé en une ligne ; il se change dans sa fenêtre. -->
                            <div
                                v-if="selectedRoleProfiles.length"
                                :class="cn(
                                    'flex flex-col gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:items-center sm:justify-between',
                                    selectedProfile ? 'border-border bg-muted/30' : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/25',
                                )"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', selectedProfile ? 'bg-primary/10 text-primary' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300')" aria-hidden="true"><IdCard class="h-4 w-4" /></span>
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Profil professionnel</p>
                                        <p class="truncate text-sm font-bold text-foreground">{{ selectedProfile?.name ?? `À choisir pour ${selectedRole?.name}` }}</p>
                                        <FormError v-if="form.errors.professional_profile_id">{{ form.errors.professional_profile_id }}</FormError>
                                    </div>
                                </div>
                                <Button type="button" size="sm" :variant="selectedProfile ? 'outline' : 'primary'" class="shrink-0" @click="openProfileDialog(selectedRole)">
                                    <IdCard class="h-4 w-4" />{{ selectedProfile ? 'Changer de profil' : 'Choisir le profil' }}
                                </Button>
                            </div>
                            <p v-if="profileChanged" class="-mt-3 flex items-start gap-2 px-1 text-xs leading-5 text-amber-800 dark:text-amber-200">
                                <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>Profil modifié ({{ oldProfileName }} → {{ newProfileName }}) : à l’enregistrement, les droits venus de l’ancien profil sont retirés et ceux du nouveau ajoutés, sans toucher aux décisions individuelles.</span>
                            </p>

                            <div v-if="selectedRole" class="flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs leading-5 text-muted-foreground">
                                    <strong class="text-foreground">{{ selectedRole.permissions.length }} permission{{ selectedRole.permissions.length > 1 ? 's' : '' }}</strong>
                                    accordée{{ selectedRole.permissions.length > 1 ? 's' : '' }} par défaut au rôle « {{ selectedRole.name }} », sur tous ses comptes.
                                </p>
                                <a
                                    v-if="canManageRoleBaselines"
                                    :href="rolesScreenUrl({ role: selectedRole.code })"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-primary hover:underline"
                                >Voir le socle du rôle<ExternalLink class="h-3.5 w-3.5" /></a>
                            </div>
                        </div>
                    </Card>

                    <footer class="z-20 flex flex-col gap-3 rounded-xl border border-border bg-card/95 px-4 py-3 shadow-lg backdrop-blur supports-[backdrop-filter]:bg-card/85 sm:sticky sm:bottom-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <Button type="button" variant="ghost" :disabled="form.processing" @click="closeForm">Annuler</Button>
                            <p v-if="blocker" class="flex min-w-0 items-start gap-1.5 text-xs leading-4 text-muted-foreground" aria-live="polite">
                                <Info class="mt-px h-3.5 w-3.5 shrink-0" /><span>{{ blocker }}</span>
                            </p>
                        </div>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row">
                            <Button v-if="step > 1" type="button" variant="outline" :disabled="form.processing" @click="prevStep"><ArrowLeft class="h-4 w-4" />Précédent</Button>
                            <Button v-if="isEditing && step === 1" type="button" variant="outline" :disabled="!step1Valid || form.processing" @click="submitUser">
                                <Check class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : 'Mettre à jour les informations' }}
                            </Button>
                            <Button v-if="step < 2" type="submit" variant="primary" :disabled="!step1Valid">{{ isEditing ? 'Continuer vers le rôle' : 'Suivant : le rôle' }}<ArrowRight class="h-4 w-4" /></Button>
                            <Button v-else type="submit" variant="primary" :disabled="form.processing || !step2Valid">
                                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
                                <Check v-else class="h-4 w-4" />
                                {{ form.processing ? 'Enregistrement…' : (isEditing ? 'Enregistrer' : 'Créer le compte et envoyer l’invitation') }}
                            </Button>
                        </div>
                    </footer>
                </form>

                <!-- Aperçu : ce qui sera enregistré, relu sans changer d'étape. -->
                <aside class="space-y-4 lg:sticky lg:top-20" aria-label="Aperçu du compte">
                    <Card class="overflow-hidden">
                        <div class="flex flex-col items-center gap-1.5 border-b border-border bg-muted/30 px-5 py-5 text-center">
                            <span
                                :class="cn(
                                    'grid h-14 w-14 place-items-center rounded-full text-base font-bold ring-4 ring-card',
                                    form.name.trim() ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                                )"
                                aria-hidden="true"
                            >
                                <template v-if="form.name.trim()">{{ initials(form.name) }}</template>
                                <UserRound v-else class="h-6 w-6" />
                            </span>
                            <p :class="cn('mt-1 max-w-full truncate text-sm font-bold', form.name.trim() ? 'text-foreground' : 'text-muted-foreground')">{{ form.name.trim() || 'Nom à renseigner' }}</p>
                            <p :class="cn('max-w-full break-all text-xs', emailValid ? 'text-muted-foreground' : 'text-muted-foreground/70')">{{ form.email.trim() || 'email à renseigner' }}</p>
                        </div>
                        <dl class="divide-y divide-border text-sm">
                            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                                <dt class="flex items-center gap-2 text-muted-foreground"><Server class="h-3.5 w-3.5" />Site</dt>
                                <dd class="text-end font-medium text-foreground">{{ selectedSite?.site.name }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                                <dt class="flex items-center gap-2 text-muted-foreground"><Users class="h-3.5 w-3.5" />Personne</dt>
                                <dd :class="cn('text-end font-medium', form.account_kind ? 'text-foreground' : 'text-muted-foreground')">
                                    {{ form.account_kind === 'EXTERNAL' ? 'Externe' : form.account_kind === 'STAFF' ? (employees.find((employee) => employee.uuid === form.employee_uuid)?.employee_number ?? 'Fiche à choisir') : 'À choisir' }}
                                </dd>
                            </div>
                            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                                <dt class="flex items-center gap-2 text-muted-foreground"><Briefcase class="h-3.5 w-3.5" />Rôle</dt>
                                <dd :class="cn('text-end font-medium', selectedRole ? 'text-foreground' : 'text-muted-foreground')">{{ selectedRole?.name ?? 'À choisir' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                                <dt class="flex items-center gap-2 text-muted-foreground"><IdCard class="h-3.5 w-3.5" />Profil</dt>
                                <dd :class="cn('text-end font-medium', selectedProfile ? 'text-foreground' : 'text-muted-foreground')">
                                    {{ ! selectedRole ? '—' : selectedRoleProfiles.length ? (selectedProfile?.name ?? 'À choisir') : 'Aucun pour ce rôle' }}
                                </dd>
                            </div>
                            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                                <dt class="flex items-center gap-2 text-muted-foreground"><ShieldCheck class="h-3.5 w-3.5" />Socle</dt>
                                <dd class="text-end font-medium text-foreground">{{ selectedRole ? `${selectedRole.permissions.length} droit${selectedRole.permissions.length > 1 ? 's' : ''}` : '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                                <dt class="flex items-center gap-2 text-muted-foreground"><KeyRound class="h-3.5 w-3.5" />Mot de passe</dt>
                                <dd class="text-end font-medium text-foreground">{{ ! isEditing ? 'Par invitation' : form.password ? 'Nouveau' : 'Inchangé' }}</dd>
                            </div>
                            <div v-if="isEditing" class="flex items-start justify-between gap-3 px-5 py-2.5">
                                <dt class="flex items-center gap-2 text-muted-foreground"><Lock class="h-3.5 w-3.5" />Exceptions</dt>
                                <dd class="text-end font-medium text-foreground">{{ editingUser.permission_overrides.length }} · conservées</dd>
                            </div>
                        </dl>
                    </Card>

                    <Card class="p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">{{ isEditing ? 'Avant d’enregistrer' : 'Avant de créer le compte' }}</p>
                        <ul class="mt-3 space-y-2">
                            <li v-for="item in checklist" :key="item.key" class="flex items-center gap-2.5 text-sm">
                                <CircleCheck v-if="item.done" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <Circle v-else class="h-4 w-4 shrink-0 text-muted-foreground/50" />
                                <span :class="item.done ? 'text-foreground' : 'text-muted-foreground'">{{ item.label }}</span>
                            </li>
                        </ul>
                        <p v-if="isEditing" class="mt-3 border-t border-border pt-3 text-xs leading-5 text-muted-foreground">
                            Les exceptions individuelles de ce compte ne sont pas modifiées ici.
                            <a
                                v-if="canAssignPermissions"
                                :href="rolesScreenUrl({ vue: 'comptes', compte: editingUser.uuid })"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                            >Les régler<ExternalLink class="h-3 w-3" /></a>
                        </p>
                    </Card>
                </aside>
            </div>
        </template>

        <Dialog
            :open="pendingRole !== null"
            :title="`Profil professionnel — ${pendingRole?.name ?? ''}`"
            description="Ce rôle regroupe plusieurs métiers : choisissez celui de la personne. Le profil classe le métier ; il ne donne aucun droit automatiquement."
            size="lg"
            @update:open="(open) => open || cancelProfile()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><IdCard class="h-5 w-5" /></span>
            </template>

            <div class="space-y-2" role="radiogroup" aria-label="Profil professionnel">
                <button
                    v-for="profile in pendingProfiles"
                    :key="profile.id"
                    type="button"
                    role="radio"
                    :aria-checked="Number(profileDraft) === Number(profile.id)"
                    :class="cn(
                        'flex w-full items-start gap-3 rounded-lg border px-3.5 py-3 text-start transition-[border-color,background-color,box-shadow] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        Number(profileDraft) === Number(profile.id) ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-card hover:border-primary/40 hover:bg-accent/30',
                    )"
                    @click="selectProfile(profile.id)"
                    @dblclick="selectProfile(profile.id); confirmProfile()"
                >
                    <span :class="cn(
                        'grid h-8 w-8 shrink-0 place-items-center rounded-md',
                        Number(profileDraft) === Number(profile.id) ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                    )" aria-hidden="true"><IdCard class="h-4 w-4" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-foreground">{{ profile.name }}</span>
                            <span v-if="profile.recommended_permissions?.length" class="shrink-0 text-[11px] tabular-nums text-muted-foreground">{{ profile.recommended_permissions.length }} droit{{ profile.recommended_permissions.length > 1 ? 's' : '' }} recommandé{{ profile.recommended_permissions.length > 1 ? 's' : '' }}</span>
                        </span>
                        <span class="mt-0.5 block text-xs leading-4 text-muted-foreground">{{ profile.description }}</span>
                    </span>
                    <CircleCheck v-if="Number(profileDraft) === Number(profile.id)" class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <Circle v-else class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground/40" />
                </button>
            </div>

            <p v-if="draftProfile" class="mt-4 text-xs leading-5 text-muted-foreground">
                <template v-if="draftProfile.recommended_permissions?.length">Ses {{ draftProfile.recommended_permissions.length }} droit{{ draftProfile.recommended_permissions.length > 1 ? 's' : '' }} recommandé{{ draftProfile.recommended_permissions.length > 1 ? 's' : '' }} sont ajouté{{ draftProfile.recommended_permissions.length > 1 ? 's' : '' }} à l’enregistrement lorsque le profil change, puis restent ajustables dans « Rôles &amp; permissions ».</template>
                <template v-else>Aucun droit supplémentaire recommandé : le socle du rôle reste applicable.</template>
            </p>
            <div v-if="draftProfileChanged" class="mt-3 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <p class="font-bold">Le profil professionnel change : {{ oldProfileName }} → {{ draftProfile.name }}</p>
                    <p class="mt-0.5">À l’enregistrement, les permissions venues de l’ancien profil seront retirées et les recommandations du nouveau ajoutées. Une permission attribuée individuellement n’est jamais écrasée.</p>
                </div>
            </div>

            <template #footer>
                <Button type="button" variant="outline" @click="cancelProfile">Annuler</Button>
                <Button type="button" variant="primary" :disabled="! draftProfile" @click="confirmProfile">
                    <Check class="h-4 w-4" />Valider le profil
                </Button>
            </template>
        </Dialog>

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
