<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    users: Object,
    roles: Array,
    permissionCatalog: Array,
    filters: Object,
});

const page = usePage();
const { can } = usePermissions();
const query = ref(props.filters.q ?? '');
const statusFilter = ref(props.filters.status ?? 'active');
const roleFilter = ref(props.filters.role ?? '');
const editingUser = ref(null);
const formOpen = ref(false);
const permissionEffects = reactive({});
const deactivateTarget = ref(null);

const form = useForm({
    name: '',
    email: '',
    role_id: '',
    professional_profile_id: '',
    password: '',
    password_confirmation: '',
    permission_overrides: [],
});

const deactivationForm = useForm({ reason: '' });

const canCreate = computed(() => can('users.create') && can('roles.assign'));
const canAssignPermissions = computed(() => can('permissions.assign'));
const isEditing = computed(() => editingUser.value !== null);
const selectedRole = computed(() => props.roles.find((role) => Number(role.id) === Number(form.role_id)) ?? null);
const selectedRoleProfiles = computed(() => selectedRole.value?.profiles ?? []);
const selectedProfile = computed(() => selectedRoleProfiles.value.find(
    (profile) => Number(profile.id) === Number(form.professional_profile_id),
) ?? null);
const profileChanged = computed(() => isEditing.value
    && Number(editingUser.value?.professional_profile?.id ?? 0) !== Number(form.professional_profile_id ?? 0));
const selectedRolePermissions = computed(() => new Set(selectedRole.value?.permissions ?? []));
const overrideCount = computed(() => serializeOverrides().length);

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
    for (const override of overrides) permissionEffects[override.permission_id] = override.effect;
};

const openCreate = () => {
    editingUser.value = null;
    form.reset();
    form.clearErrors();
    form.role_id = props.roles.find((role) => role.code !== 'SUPER_ADMIN')?.id ?? props.roles[0]?.id ?? '';
    form.professional_profile_id = '';
    resetPermissionEffects();
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
    formOpen.value = true;
};

const closeForm = () => {
    if (form.processing) return;
    formOpen.value = false;
    editingUser.value = null;
    form.reset();
    form.clearErrors();
    resetPermissionEffects();
};

const serializeOverrides = () => Object.entries(permissionEffects)
    .filter(([, effect]) => effect === 'allow' || effect === 'deny')
    .map(([permissionId, effect]) => ({ permission_id: Number(permissionId), effect }));

const onRoleChange = () => {
    form.professional_profile_id = '';
};

const applyProfileRecommendations = () => {
    if (!canAssignPermissions.value || !selectedProfile.value) return;

    for (const permission of selectedProfile.value.recommended_permissions ?? []) {
        permissionEffects[permission.id] = 'allow';
    }
};

const roleRequiresProfile = (roleId) => {
    const role = props.roles.find((item) => Number(item.id) === Number(roleId));
    return Boolean(role?.profiles?.length);
};

const submitUser = () => {
    form.permission_overrides = serializeOverrides();
    form.transform((data) => {
        const payload = { ...data };

        if (!canAssignPermissions.value || editingUser.value?.is_current) {
            delete payload.permission_overrides;
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
                <div class="flex items-center gap-2 text-xs font-medium text-slate-400">
                    <span>Administration</span>
                    <Icon class="text-sm" name="chevron-right" />
                    <span>Accès</span>
                </div>
                <h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Utilisateurs et accès</h1>
                <p class="mt-1 text-sm text-slate-500">Comptes locaux du site {{ page.props.site?.name }} et droits opérationnels.</p>
            </div>

            <Button v-if="canCreate" size="rg" variant="primary" type="button" @click="openCreate">
                <Icon class="text-lg" name="user-add" />
                <span class="ms-2">Nouvel utilisateur</span>
            </Button>
        </div>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 lg:flex-row lg:items-center lg:justify-between lg:px-5">
                <form class="relative w-full lg:max-w-md" role="search" @submit.prevent="submitFilters">
                    <Input v-model="query" icon="start" type="search" placeholder="Nom ou adresse email" autocomplete="off" />
                    <button type="submit" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400" aria-label="Rechercher">
                        <Icon class="text-lg" name="search" />
                    </button>
                </form>

                <div class="grid grid-cols-2 gap-2 sm:flex">
                    <label class="sr-only" for="users-role-filter">Filtrer par rôle</label>
                    <select id="users-role-filter" v-model="roleFilter" class="h-9 min-w-44 rounded border-gray-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200" @change="submitFilters">
                        <option value="">Tous les rôles</option>
                        <option v-for="role in roles" :key="role.id" :value="role.code">{{ role.name }}</option>
                    </select>

                    <label class="sr-only" for="users-status-filter">Filtrer par état</label>
                    <select id="users-status-filter" v-model="statusFilter" class="h-9 min-w-36 rounded border-gray-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200" @change="submitFilters">
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
                        <tr class="bg-gray-50/70 dark:bg-gray-1000/40">
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Utilisateur</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Rôle</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Dernière connexion</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">État</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users.data" :key="user.uuid" class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000">
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <div class="flex min-w-[260px] items-center gap-3">
                                    <Avatar rounded size="sm" variant="slate-pale" :text="initials(user.name)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ user.name }}</span>
                                            <span v-if="user.is_current" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-gray-900">Vous</span>
                                        </div>
                                        <span class="mt-0.5 block truncate text-xs text-slate-400">{{ user.email }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <p class="text-sm font-medium text-slate-600 dark:text-slate-200">{{ user.role?.name ?? 'Aucun rôle' }}</p>
                                <p v-if="user.professional_profile" class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    {{ user.professional_profile.name }}
                                </p>
                                <p v-else-if="roleRequiresProfile(user.role?.id)" class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                    Profil métier à définir
                                </p>
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
                                    <button v-if="can('users.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" :aria-label="`Modifier ${user.name}`" title="Modifier" @click="openEdit(user)">
                                        <Icon class="text-base" name="edit" />
                                    </button>
                                    <button v-if="user.active && can('users.deactivate') && !user.is_current" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-red-300 hover:text-red-600 dark:border-gray-800" :aria-label="`Désactiver ${user.name}`" title="Désactiver" @click="openDeactivate(user)">
                                        <Icon class="text-base" name="lock" />
                                    </button>
                                    <button v-if="!user.active && can('users.activate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-emerald-300 hover:text-emerald-700 dark:border-gray-800" :aria-label="`Réactiver ${user.name}`" title="Réactiver" @click="activate(user)">
                                        <Icon class="text-base" name="unlock" />
                                    </button>
                                </div>
                                <span v-else class="text-xs text-slate-400">Protégé</span>
                            </td>
                        </tr>

                        <tr v-if="users.data.length === 0">
                            <td colspan="5" class="px-5 py-12 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="users" /></span>
                                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun utilisateur trouvé</p>
                                <p class="mt-1 text-xs text-slate-400">Modifiez les filtres ou créez un compte autorisé.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="users.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 p-4 dark:border-gray-900">
                <span class="text-xs text-slate-400">Page {{ users.current_page }} sur {{ users.last_page }}</span>
                <div class="flex flex-wrap items-center gap-1">
                    <template v-for="(link, index) in users.links" :key="index">
                        <Link v-if="link.url" :href="link.url" preserve-state :class="['rounded px-3 py-1.5 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900']" v-html="link.label" />
                        <span v-else class="rounded px-3 py-1.5 text-sm text-slate-300" v-html="link.label" />
                    </template>
                </div>
            </div>
        </section>

        <aside class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950 sm:px-5">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900"><Icon class="text-lg" name="shield-check" /></span>
                <div>
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Un accès propre à chaque compte</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Le rôle fournit uniquement le socle commun du service. Le profil précise le métier principal ; ses droits recommandés doivent être appliqués au compte puis peuvent être adaptés individuellement. Toute attribution est auditée. Un compte n’est jamais supprimé : il est désactivé pour préserver l’historique.</p>
                </div>
            </div>
        </aside>

        <div v-if="formOpen" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeForm">
            <section class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="user-form-title">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div>
                        <h2 id="user-form-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">{{ isEditing ? 'Modifier l’utilisateur' : 'Créer un utilisateur' }}</h2>
                        <p class="mt-1 text-xs text-slate-500">Un compte nominatif, un rôle métier et uniquement les permissions nécessaires.</p>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white" aria-label="Fermer" @click="closeForm"><Icon class="text-xl" name="cross" /></button>
                </header>

                <form class="overflow-y-auto" @submit.prevent="submitUser">
                    <div class="space-y-6 p-5">
                        <div v-if="form.errors.user" class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                            {{ form.errors.user }}
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
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
                            <div :class="selectedRoleProfiles.length ? '' : 'sm:col-span-2'">
                                <label for="user_role" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Rôle métier <span class="text-red-500">*</span></label>
                                <select id="user_role" v-model="form.role_id" :disabled="editingUser?.is_current" class="block h-9 w-full rounded border-gray-200 bg-white py-1.5 ps-3 pe-9 text-sm text-slate-700 focus:border-primary-500 focus:ring-primary-200 disabled:bg-gray-50 disabled:text-slate-400 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-900" :aria-invalid="Boolean(form.errors.role_id)" @change="onRoleChange">
                                    <option disabled value="">Choisir un rôle</option>
                                    <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                                </select>
                                <p v-if="editingUser?.is_current" class="mt-1.5 text-xs text-slate-400">Votre propre rôle ne peut pas être modifié depuis cette session.</p>
                                <FormError v-if="form.errors.role_id">{{ form.errors.role_id }}</FormError>
                            </div>

                            <div v-if="selectedRoleProfiles.length">
                                <label for="user_professional_profile" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Profil professionnel <span class="text-red-500">*</span></label>
                                <select id="user_professional_profile" v-model="form.professional_profile_id" :disabled="editingUser?.is_current" class="block h-9 w-full rounded border-gray-200 bg-white py-1.5 ps-3 pe-9 text-sm text-slate-700 focus:border-primary-500 focus:ring-primary-200 disabled:bg-gray-50 disabled:text-slate-400 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-900" :aria-invalid="Boolean(form.errors.professional_profile_id)">
                                    <option disabled value="">Choisir un profil</option>
                                    <option v-for="profile in selectedRoleProfiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
                                </select>
                                <FormError v-if="form.errors.professional_profile_id">{{ form.errors.professional_profile_id }}</FormError>
                            </div>

                            <div v-if="selectedProfile" class="sm:col-span-2 rounded border border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/40">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-slate-700 dark:text-white">{{ selectedProfile.name }}</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ selectedProfile.description }}</p>
                                        <p class="mt-1 text-[11px] text-slate-400">Le profil classe le métier ; il ne donne aucun droit automatiquement.</p>
                                    </div>
                                    <Button v-if="canAssignPermissions && !editingUser?.is_current && selectedProfile.recommended_permissions?.length" size="sm" variant="white-outline" type="button" class="shrink-0" @click="applyProfileRecommendations">
                                        <Icon class="text-base" name="shield-check" />
                                        <span class="ms-2">Appliquer les droits principaux</span>
                                    </Button>
                                </div>
                                <p v-if="selectedProfile.recommended_permissions?.length" class="mt-2 text-[11px] text-slate-400">
                                    {{ selectedProfile.recommended_permissions.length }} droit{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} recommandé{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }}, enregistré{{ selectedProfile.recommended_permissions.length > 1 ? 's' : '' }} individuellement après application.
                                </p>
                                <p v-else class="mt-2 text-[11px] text-slate-400">Aucun droit supplémentaire recommandé : le socle du rôle reste applicable.</p>
                                <p v-if="profileChanged" class="mt-2 text-[11px] font-medium text-amber-700 dark:text-amber-300">Le changement de profil ne retire pas les permissions individuelles existantes. Vérifiez les exceptions ci-dessous avant d’enregistrer.</p>
                            </div>
                        </div>

                        <div class="border-t border-gray-200 pt-5 dark:border-gray-900">
                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">{{ isEditing ? 'Nouveau mot de passe' : 'Mot de passe initial' }}</h3>
                            <p class="mt-1 text-xs text-slate-400">{{ isEditing ? 'Laissez vide pour conserver le mot de passe actuel.' : 'Au moins 12 caractères avec majuscule, minuscule, chiffre et symbole.' }}</p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="user_password" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Mot de passe <span v-if="!isEditing" class="text-red-500">*</span></label>
                                    <Input id="user_password" v-model="form.password" type="password" autocomplete="new-password" :aria-invalid="Boolean(form.errors.password)" />
                                    <FormError v-if="form.errors.password">{{ form.errors.password }}</FormError>
                                </div>
                                <div>
                                    <label for="user_password_confirmation" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Confirmation <span v-if="!isEditing" class="text-red-500">*</span></label>
                                    <Input id="user_password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                                </div>
                            </div>
                        </div>

                        <div v-if="canAssignPermissions && !editingUser?.is_current && permissionCatalog.length" class="border-t border-gray-200 pt-5 dark:border-gray-900">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-bold text-slate-700 dark:text-white">Exceptions individuelles</h3>
                                    <p class="mt-1 text-xs leading-5 text-slate-400">Conservez « Hérité du rôle » par défaut. Une interdiction individuelle est prioritaire sur une autorisation.</p>
                                </div>
                                <span v-if="overrideCount" class="shrink-0 rounded bg-gray-100 px-2 py-1 text-xs font-medium text-slate-500 dark:bg-gray-900">{{ overrideCount }} exception{{ overrideCount > 1 ? 's' : '' }}</span>
                            </div>

                            <FormError v-if="form.errors.permission_overrides">{{ form.errors.permission_overrides }}</FormError>

                            <div class="mt-4 space-y-2">
                                <details v-for="(permissions, module) in groupedPermissions" :key="module" class="group rounded border border-gray-200 dark:border-gray-800">
                                    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium text-slate-600 dark:text-slate-200">
                                        <span>{{ moduleLabels[module] ?? module }}</span>
                                        <span class="flex items-center gap-2 text-xs font-normal text-slate-400">{{ permissions.length }} droits <Icon class="text-base transition-transform group-open:rotate-90" name="chevron-right" /></span>
                                    </summary>
                                    <div class="border-t border-gray-200 dark:border-gray-800">
                                        <div v-for="permission in permissions" :key="permission.id" class="grid gap-2 border-b border-gray-100 px-4 py-3 last:border-b-0 dark:border-gray-900 sm:grid-cols-[1fr_180px] sm:items-center">
                                            <div>
                                                <p class="text-xs font-medium text-slate-600 dark:text-slate-200">{{ permission.label }}</p>
                                                <p class="mt-0.5 text-[11px] text-slate-400">{{ permission.name }} · {{ selectedRolePermissions.has(permission.name) ? 'autorisé par le rôle' : 'non autorisé par le rôle' }}</p>
                                            </div>
                                            <select v-model="permissionEffects[permission.id]" class="h-8 rounded border-gray-200 bg-white py-1 ps-2 pe-7 text-xs text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200">
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

                    <footer class="flex flex-col-reverse gap-3 border-t border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:justify-end">
                        <Button size="rg" variant="white-outline" type="button" :disabled="form.processing" @click="closeForm">Annuler</Button>
                        <Button size="rg" variant="primary" type="submit" :disabled="form.processing">
                            <Icon class="text-lg" name="check" />
                            <span class="ms-2">{{ form.processing ? 'Enregistrement…' : (isEditing ? 'Enregistrer' : 'Créer le compte') }}</span>
                        </Button>
                    </footer>
                </form>
            </section>
        </div>

        <div v-if="deactivateTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeDeactivate">
            <section class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="deactivate-user-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-xl" name="lock" /></span>
                    <div>
                        <h2 id="deactivate-user-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Désactiver {{ deactivateTarget.name }} ?</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500">La connexion sera bloquée et les sessions ouvertes seront révoquées. L’historique reste conservé.</p>
                    </div>
                </div>
                <div class="mt-5">
                    <label for="deactivation_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label>
                    <textarea id="deactivation_reason" v-model="deactivationForm.reason" rows="3" autofocus placeholder="Ex. fin de contrat ou changement d’affectation" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none transition-all placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950"></textarea>
                    <FormError v-if="deactivationForm.errors.reason">{{ deactivationForm.errors.reason }}</FormError>
                    <FormError v-if="deactivationForm.errors.user">{{ deactivationForm.errors.user }}</FormError>
                </div>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" :disabled="deactivationForm.processing" @click="closeDeactivate">Annuler</Button>
                    <Button size="rg" variant="secondary" type="button" :disabled="deactivationForm.processing" @click="confirmDeactivate"><Icon class="text-lg" name="lock" /><span class="ms-2">{{ deactivationForm.processing ? 'Désactivation…' : 'Désactiver le compte' }}</span></Button>
                </div>
            </section>
        </div>
    </div>
</template>
