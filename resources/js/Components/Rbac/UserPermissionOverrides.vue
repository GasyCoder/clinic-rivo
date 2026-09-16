<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowLeftRight, Check, ChevronRight, RotateCcw, Search, ShieldCheck, TriangleAlert, UserCog } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import PermissionAccessRow from '@/Components/Rbac/PermissionAccessRow.vue';
import PermissionCategoryNav from '@/Components/Rbac/PermissionCategoryNav.vue';
import FormError from '@/Components/UI/FormError.vue';
import ResizableSplit from '@/Components/UI/ResizableSplit.vue';
import { PERMISSION_DOMAINS, permissionCategoryDomain, permissionCategoryLabel } from '@/utilities/permissionCategories';
import {
    comparePermissions,
    isSensitivePermission,
    permissionActionGroup,
    permissionLabel,
    permissionMatchesSearch,
} from '@/utilities/permissionWorkspace';
import { cn } from '@/lib/cn';

/**
 * Les exceptions d'**un compte**, par-dessus le socle de son rôle.
 *
 * Elles se réglaient dans la troisième étape du formulaire de création d'un
 * compte : on ne pouvait donc corriger un droit qu'en rouvrant l'identité de
 * la personne, et l'écran mélangeait deux gestes de portée très différente —
 * créer quelqu'un, et lui ouvrir une porte de plus. Elles vivent désormais
 * ici, à côté du socle qu'elles nuancent.
 *
 * L'ordre de résolution reste celui de l'ADR-022/033, et cet écran ne le
 * change pas :
 *
 *     DENY individuel  >  ALLOW individuel  >  socle du rôle
 *
 * Les lignes venues d'un profil métier (`source = PROFILE`) sont affichées
 * avec leur provenance mais restent modifiables : une décision explicite
 * reprise ici devient une décision MANUAL, jamais réécrite ensuite par une
 * application de recommandations (ADR-033).
 */
const props = defineProps({
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    permissionCatalog: { type: Array, default: () => [] },
    siteName: { type: String, default: '' },
    canAssign: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['save', 'update:dirty']);

const selectedUuid = ref('');
const search = ref('');
const filter = ref('all');
const selectedCategory = ref('');
const userSearch = ref('');
const showDiff = ref(false);
const pendingUuid = ref(null);

/** `{ [permissionId]: 'allow' | 'deny' }` — l'absence vaut « selon le rôle ». */
const draft = ref({});

const selectedUser = computed(() => props.users.find((user) => user.uuid === selectedUuid.value) ?? null);

const selectedRole = computed(() => props.roles.find(
    (role) => role.code === selectedUser.value?.role?.code,
) ?? null);

/** Ce que le socle du rôle accorde déjà : l'exception ne sert qu'à s'en écarter. */
const rolePermissionNames = computed(() => new Set(selectedRole.value?.permissions ?? []));

/** Les exceptions telles qu'elles sont enregistrées sur le site. */
const savedEffects = computed(() => Object.fromEntries(
    (selectedUser.value?.permission_overrides ?? []).map((override) => [override.permission_id, override.effect]),
));

const provenance = computed(() => Object.fromEntries(
    (selectedUser.value?.permission_overrides ?? []).map((override) => [
        override.permission_id,
        { source: override.source, profile: override.source_profile_name },
    ]),
));

const effectOf = (permission) => draft.value[permission.id] ?? '';
const roleGrants = (permission) => rolePermissionNames.value.has(permission.name);

/** Un DENY individuel l'emporte toujours, y compris sur le socle du rôle. */
const effectivelyGranted = (permission) => {
    const effect = effectOf(permission);

    if (effect === 'deny') return false;
    if (effect === 'allow') return true;

    return roleGrants(permission);
};

const sourceLabel = (permission) => {
    const origin = provenance.value[permission.id];

    if (! origin || draft.value[permission.id] !== savedEffects.value[permission.id]) return '';

    return origin.source === 'PROFILE'
        ? `Profil ${origin.profile ?? 'métier'}`
        : 'Décision individuelle';
};

const changed = computed(() => props.permissionCatalog.filter(
    (permission) => (draft.value[permission.id] ?? '') !== (savedEffects.value[permission.id] ?? ''),
));

const changedIds = computed(() => new Set(changed.value.map((permission) => permission.id)));
const dirty = computed(() => changed.value.length > 0);

watch(dirty, (value) => emit('update:dirty', value), { immediate: true });

const applyEffect = (permission, effect) => {
    const next = { ...draft.value };

    if (effect === '') delete next[permission.id]; else next[permission.id] = effect;

    draft.value = next;
};

/**
 * Autoriser une permission sensible demande une confirmation.
 *
 * Un `deny` et un retour au socle ne la demandent pas : ils ne peuvent que
 * restreindre. C'est l'ouverture d'un droit — supprimer, valider, encaisser —
 * qui mérite un deuxième regard.
 */
const pendingChange = ref(null);

const setEffect = (permission, effect) => {
    if (effect === 'allow' && isSensitivePermission(permission)) {
        pendingChange.value = { permission, effect };

        return;
    }

    applyEffect(permission, effect);
};

const confirmPendingChange = () => {
    applyEffect(pendingChange.value.permission, pendingChange.value.effect);
    pendingChange.value = null;
};

/**
 * Les actions groupées portent sur ce qui est **affiché**, jamais sur tout
 * le catalogue : appliquer à une liste qu'on ne voit pas serait signer à
 * l'aveugle. La confirmation nomme la portée et le nombre exact de lignes.
 */
const pendingBulk = ref(null);

const openBulk = (permissions, effect, scope) => {
    if (! permissions.length) return;

    pendingBulk.value = { permissions: [...permissions], effect, scope };
};

const bulkHasSensitive = computed(() => Boolean(pendingBulk.value?.permissions.some(isSensitivePermission)));

const confirmBulk = () => {
    const next = { ...draft.value };

    for (const permission of pendingBulk.value.permissions) {
        if (pendingBulk.value.effect === '') delete next[permission.id];
        else next[permission.id] = pendingBulk.value.effect;
    }

    draft.value = next;
    pendingBulk.value = null;
};

const bulkLabel = { allow: 'Autoriser', deny: 'Interdire', '': 'Revenir au socle du rôle' };

const matchesSearch = (permission) => permissionMatchesSearch(
    permission,
    search.value,
    permissionCategoryLabel(permission.module),
);

const matchesFilter = (permission) => {
    if (filter.value === 'exceptions') return effectOf(permission) !== '';
    if (filter.value === 'granted') return effectivelyGranted(permission);
    if (filter.value === 'denied') return ! effectivelyGranted(permission);
    if (filter.value === 'sensitive') return isSensitivePermission(permission);
    if (filter.value === 'changed') return changedIds.value.has(permission.id);

    return true;
};

const visible = (permission) => matchesSearch(permission) && matchesFilter(permission);

const domainIndex = (key) => {
    const index = PERMISSION_DOMAINS.findIndex((domain) => domain.key === key);

    return index === -1 ? PERMISSION_DOMAINS.length : index;
};

const categories = computed(() => {
    const grouped = new Map();

    for (const permission of props.permissionCatalog) {
        if (! grouped.has(permission.module)) grouped.set(permission.module, []);
        grouped.get(permission.module).push(permission);
    }

    return Array.from(grouped.entries()).map(([key, permissions]) => ({
        key,
        label: permissionCategoryLabel(key),
        domain: permissionCategoryDomain(key),
        permissions,
        total: permissions.length,
        exceptions: permissions.filter((permission) => effectOf(permission) !== '').length,
        changed: permissions.filter((permission) => changedIds.value.has(permission.id)).length,
        visible: permissions.filter(visible).length,
    })).sort((left, right) => {
        const order = domainIndex(left.domain) - domainIndex(right.domain);

        return order !== 0 ? order : left.label.localeCompare(right.label, 'fr');
    });
});

const navGroups = computed(() => PERMISSION_DOMAINS
    .map((domain) => ({
        key: domain.key,
        label: domain.label,
        categories: categories.value
            .filter((category) => category.domain === domain.key)
            .map((category) => ({
                key: category.key,
                label: category.label,
                title: `${category.label} · code ${category.key}`,
                // Le rail compte les **exceptions**, pas les droits : c'est
                // ce que cet écran décide. Le socle se lit à côté.
                count: `${category.exceptions}/${category.total}`,
                marked: category.changed > 0,
            })),
    }))
    .filter((group) => group.categories.length));

/**
 * Ce que le site dit des exceptions de ce compte, à l'instant du chargement.
 * Le brouillon ne repart que si cette réponse a réellement changé : un
 * enregistrement refusé réaffiche la même page, et recharger là jetterait la
 * saisie précisément quand elle vient d'échouer.
 */
const signatureOf = (uuid) => {
    const user = props.users.find((item) => item.uuid === uuid);

    if (! user) return '';

    return `${uuid}:${[...(user.permission_overrides ?? [])]
        .map((override) => `${override.permission_id}:${override.effect}`)
        .sort()
        .join(',')}`;
};

const loadedSignature = ref(null);

const loadUser = (uuid) => {
    selectedUuid.value = uuid;
    draft.value = { ...savedEffects.value };
    loadedSignature.value = signatureOf(uuid);
    search.value = '';
    filter.value = 'all';
    selectedCategory.value = categories.value[0]?.key ?? '';
};

watch(() => props.users, () => {
    const exists = props.users.some((user) => user.uuid === selectedUuid.value);
    const uuid = exists ? selectedUuid.value : props.users[0]?.uuid ?? '';

    if (exists && signatureOf(uuid) === loadedSignature.value) return;

    loadUser(uuid);
}, { immediate: true });

watch(categories, (list) => {
    if (! list.some((category) => category.key === selectedCategory.value)) {
        selectedCategory.value = list[0]?.key ?? '';
    }
});

const scopedToCategory = computed(() => search.value.trim() === '' && filter.value === 'all');

const visibleCategories = computed(() => (scopedToCategory.value
    ? categories.value.filter((category) => category.key === selectedCategory.value)
    : categories.value.filter((category) => category.visible > 0)));

const visibleSections = computed(() => visibleCategories.value.map((category) => {
    const permissions = category.permissions.filter(visible);
    const groups = new Map();

    for (const permission of permissions) {
        const label = permissionActionGroup(permission);
        if (! groups.has(label)) groups.set(label, []);
        groups.get(label).push(permission);
    }

    return {
        ...category,
        shown: permissions,
        groups: Array.from(groups.entries()).map(([label, items]) => ({
            label,
            permissions: items.sort(comparePermissions),
        })),
    };
}));

const visibleCount = computed(() => visibleSections.value.reduce((total, section) => total + section.shown.length, 0));

const filters = [
    { value: 'all', label: 'Toutes' },
    { value: 'exceptions', label: 'Exceptions' },
    { value: 'granted', label: 'Accès effectifs' },
    { value: 'denied', label: 'Sans accès' },
    { value: 'sensitive', label: 'Sensibles' },
    { value: 'changed', label: 'Modifiées' },
];

const filterCounts = computed(() => {
    const searched = props.permissionCatalog.filter(matchesSearch);

    return {
        all: searched.length,
        exceptions: searched.filter((permission) => effectOf(permission) !== '').length,
        granted: searched.filter(effectivelyGranted).length,
        denied: searched.filter((permission) => ! effectivelyGranted(permission)).length,
        sensitive: searched.filter(isSensitivePermission).length,
        changed: searched.filter((permission) => changedIds.value.has(permission.id)).length,
    };
});

const summary = computed(() => {
    const allowed = props.permissionCatalog.filter((permission) => effectOf(permission) === 'allow').length;
    const denied = props.permissionCatalog.filter((permission) => effectOf(permission) === 'deny').length;

    return {
        allowed,
        denied,
        exceptions: allowed + denied,
        effective: props.permissionCatalog.filter(effectivelyGranted).length,
    };
});

const visibleUsers = computed(() => {
    const needle = userSearch.value.trim().toLowerCase();

    if (! needle) return props.users;

    return props.users.filter((user) => `${user.name} ${user.email} ${user.role?.name ?? ''}`
        .toLowerCase()
        .includes(needle));
});

const openCategory = (key) => {
    search.value = '';
    filter.value = 'all';
    selectedCategory.value = key;
};

const resetDraft = () => { draft.value = { ...savedEffects.value }; };

/**
 * Le compte se choisit dans une fenêtre, comme le rôle sur l'écran voisin.
 *
 * La liste des comptes empilée au-dessus des quarante catégories repoussait
 * le travail réel hors de l'écran, pour un choix qu'on ne fait qu'une fois
 * par réglage. La colonne revient aux catégories.
 */
const switching = ref(false);

const openSwitcher = () => {
    userSearch.value = '';
    switching.value = true;
};

const chooseUser = (uuid) => {
    switching.value = false;
    requestUserSwitch(uuid);
};

const requestUserSwitch = (uuid) => {
    if (uuid === selectedUuid.value) return;
    if (dirty.value) { pendingUuid.value = uuid; return; }
    loadUser(uuid);
};

const confirmUserSwitch = () => {
    loadUser(pendingUuid.value);
    pendingUuid.value = null;
};

const pendingUserName = computed(() => props.users.find((user) => user.uuid === pendingUuid.value)?.name ?? '');

const describe = (permission) => {
    const from = savedEffects.value[permission.id] ?? '';
    const to = draft.value[permission.id] ?? '';
    const word = { '': 'Selon le rôle', allow: 'Autorisé', deny: 'Interdit' };

    return `${word[from]} → ${word[to]}`;
};

const save = () => emit('save', {
    user: selectedUser.value,
    overrides: Object.entries(draft.value).map(([permissionId, effect]) => ({
        permission_id: Number(permissionId),
        effect,
    })),
});
</script>

<template>
    <div class="space-y-4">
        <FormError v-if="errors.permission_overrides">{{ errors.permission_overrides }}</FormError>

        <p
            v-if="! canAssign"
            class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200"
        >
            Vous n’avez pas la permission <code>permissions.assign</code> : cet écran reste consultable, mais aucune exception ne peut être enregistrée.
        </p>

        <div v-if="! users.length" class="rounded-xl border border-border bg-card px-5 py-12 text-center">
            <UserCog class="mx-auto h-6 w-6 text-muted-foreground" aria-hidden="true" />
            <p class="mt-2 text-sm font-semibold text-foreground">Aucun compte sur ce site</p>
            <p class="mt-1 text-xs text-muted-foreground">Les exceptions se règlent sur un compte existant, depuis « Utilisateurs ».</p>
        </div>

        <!-- Deux panneaux séparés par une barre que l'on glisse : la largeur
             du rail n'est pas la même selon qu'on relit des catégories ou
             qu'on lit des libellés longs à droite. Le choix appartient au
             poste de travail, et il y reste (localStorage). -->
        <ResizableSplit
            v-else
            storage-key="rivo:super-admin:overrides-split"
            :default-ratio="0.26"
            :min-ratio="0.18"
            :max-ratio="0.45"
            start-label="panneau du compte et des catégories"
            end-label="panneau des exceptions"
        >
            <template #start>
            <div class="space-y-4 pe-1 sticky top-4">
                <Card class="overflow-hidden">
                    <p class="border-b border-border px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Compte réglé</p>
                    <div class="p-3">
                        <div class="flex items-start gap-2.5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                <UserCog class="h-4.5 w-4.5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate text-sm font-bold text-foreground">{{ selectedUser?.name ?? 'Aucun compte' }}</span>
                                    <Badge v-if="selectedUser && ! selectedUser.active" variant="outline" class="shrink-0 px-1.5 py-0 text-[10px]">Désactivé</Badge>
                                    <span v-if="dirty" class="h-2 w-2 shrink-0 rounded-full bg-amber-500" title="Modifications non enregistrées" />
                                </span>
                                <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">{{ selectedUser?.email }}</span>
                                <span class="mt-0.5 block text-[11px] text-muted-foreground">
                                    {{ selectedUser?.role?.name ?? 'Sans rôle' }} · {{ summary.exceptions }} exception{{ summary.exceptions > 1 ? 's' : '' }}
                                </span>
                            </span>
                        </div>
                        <Button type="button" variant="outline" size="sm" class="mt-3 w-full" @click="openSwitcher">
                            <ArrowLeftRight class="h-4 w-4" />Changer de compte
                            <span class="ms-auto text-[11px] font-normal text-muted-foreground">{{ users.length }}</span>
                        </Button>
                    </div>
                </Card>

                <div>
                    <p class="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Catégories</p>
                    <PermissionCategoryNav
                        :groups="navGroups"
                        :model-value="scopedToCategory ? selectedCategory : ''"
                        marked-title="Catégorie modifiée"
                        @update:model-value="openCategory"
                    />
                </div>
            </div>
            </template>

            <template #end>
            <Card class="flex min-h-[32rem] flex-col overflow-hidden ms-1">
                <header class="space-y-3 border-b border-border p-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-bold text-foreground">Exceptions de {{ selectedUser?.name }}</h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ selectedUser?.email }} · rôle {{ selectedUser?.role?.name ?? '—' }} · {{ siteName }}
                            </p>
                        </div>
                        <IconInput
                            v-model="search"
                            :icon="Search"
                            type="search"
                            class="lg:w-72"
                            placeholder="Rechercher dans tout le catalogue…"
                            autocomplete="off"
                            aria-label="Rechercher une permission"
                        />
                    </div>

                    <!-- Le socle s'applique tout seul et suit ses évolutions
                         (ADR-064) : l'exception ne sert qu'à s'en écarter. -->
                    <div
                        v-if="selectedRole"
                        class="flex items-start gap-2.5 rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2.5 dark:border-emerald-900 dark:bg-emerald-950/15"
                        role="status"
                    >
                        <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-300" />
                        <p class="text-xs leading-5 text-emerald-900 dark:text-emerald-100">
                            Le rôle « {{ selectedRole.name }} » accorde déjà {{ selectedRole.permissions.length }} permission{{ selectedRole.permissions.length > 1 ? 's' : '' }},
                            marquée{{ selectedRole.permissions.length > 1 ? 's' : '' }} « Inclus dans le rôle » — rien à cocher, elles suivent le socle.
                            Une interdiction individuelle l’emporte toujours sur ce socle.
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50/40 px-3 py-2 dark:border-emerald-900 dark:bg-emerald-950/15">
                            <p class="text-[10px] font-bold uppercase text-emerald-700 dark:text-emerald-300">Accès effectifs</p>
                            <p class="mt-0.5 text-lg font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ summary.effective }}</p>
                        </div>
                        <div class="rounded-lg border border-primary/30 bg-primary/5 px-3 py-2">
                            <p class="text-[10px] font-bold uppercase text-primary">Autorisations</p>
                            <p class="mt-0.5 text-lg font-bold tabular-nums text-primary">{{ summary.allowed }}</p>
                        </div>
                        <div class="rounded-lg border border-red-200 bg-red-50/30 px-3 py-2 dark:border-red-900 dark:bg-red-950/15">
                            <p class="text-[10px] font-bold uppercase text-destructive">Interdictions</p>
                            <p class="mt-0.5 text-lg font-bold tabular-nums text-destructive">{{ summary.denied }}</p>
                        </div>
                        <div class="rounded-lg border border-border px-3 py-2">
                            <p class="text-[10px] font-bold uppercase text-muted-foreground">Catalogue</p>
                            <p class="mt-0.5 text-lg font-bold tabular-nums text-foreground">{{ permissionCatalog.length }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            v-for="option in filters"
                            :key="option.value"
                            type="button"
                            :class="cn(
                                'rounded-full border px-3 py-1 text-[11px] font-bold transition-colors',
                                filter === option.value
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground',
                            )"
                            :aria-pressed="filter === option.value"
                            @click="filter = option.value"
                        >
                            {{ option.label }}
                            <span class="ms-1 font-normal tabular-nums">{{ filterCounts[option.value] }}</span>
                        </button>
                    </div>
                </header>

                <div v-if="visibleCount === 0" class="flex flex-1 flex-col items-center justify-center px-6 py-14 text-center">
                    <span class="grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><Search class="h-5 w-5" /></span>
                    <p class="mt-3 text-sm font-semibold text-foreground">Aucune permission dans cette vue</p>
                    <p class="mt-1 text-xs text-muted-foreground">Changez la recherche, le filtre ou la catégorie.</p>
                </div>

                <div v-else class="min-h-0 flex-1 overflow-y-auto">
                    <section v-for="section in visibleSections" :key="section.key">
                        <div class="sticky top-0 z-10 flex flex-wrap items-center gap-2 border-b border-border bg-muted/60 px-4 py-2 backdrop-blur">
                            <h3 class="truncate text-sm font-bold text-foreground">{{ section.label }}</h3>
                            <Badge variant="outline" class="px-2 py-0 text-[10px] tabular-nums">{{ section.exceptions }} exception{{ section.exceptions > 1 ? 's' : '' }}</Badge>
                            <Badge v-if="section.changed" variant="warning" class="px-2 py-0 text-[10px]">{{ section.changed }} modifié{{ section.changed > 1 ? 's' : '' }}</Badge>
                            <div v-if="canAssign" class="ms-auto flex items-center gap-1">
                                <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px]" @click="openBulk(section.shown, 'allow', `« ${section.label} » (${section.shown.length} affichée${section.shown.length > 1 ? 's' : ''})`)">Tout autoriser</Button>
                                <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px]" @click="openBulk(section.shown, 'deny', `« ${section.label} » (${section.shown.length} affichée${section.shown.length > 1 ? 's' : ''})`)">Tout interdire</Button>
                                <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px]" @click="openBulk(section.shown, '', `« ${section.label} » (${section.shown.length} affichée${section.shown.length > 1 ? 's' : ''})`)">Selon le rôle</Button>
                            </div>
                        </div>

                        <div v-for="group in section.groups" :key="`${section.key}-${group.label}`">
                            <p class="border-b border-border/60 bg-card px-4 py-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ group.label }}</p>
                            <PermissionAccessRow
                                v-for="permission in group.permissions"
                                :key="permission.id"
                                :permission="permission"
                                :state="effectOf(permission)"
                                :role-granted="roleGrants(permission)"
                                :effective-granted="effectivelyGranted(permission)"
                                :sensitive="isSensitivePermission(permission)"
                                :source-label="sourceLabel(permission)"
                                :disabled="! canAssign"
                                advanced
                                @change="setEffect(permission, $event)"
                            />
                        </div>
                    </section>
                </div>
            </Card>
            </template>
        </ResizableSplit>

        <div v-if="users.length" class="sticky bottom-0 z-20 -mx-1 rounded-xl border border-border bg-card/95 px-4 py-3 shadow-lg backdrop-blur">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', dirty ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' : 'bg-muted text-muted-foreground')">
                        <UserCog class="h-4.5 w-4.5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-foreground">
                            <template v-if="dirty">{{ changed.length }} modification{{ changed.length > 1 ? 's' : '' }} non enregistrée{{ changed.length > 1 ? 's' : '' }}</template>
                            <template v-else>Exceptions à jour</template>
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            <template v-if="dirty">Ne concerne que {{ selectedUser?.name }} — le socle du rôle reste inchangé.</template>
                            <template v-else>Le socle du rôle « {{ selectedRole?.name ?? '—' }} » continue de s’appliquer par-dessous.</template>
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="dirty" type="button" variant="outline" size="sm" @click="showDiff = true">Voir les modifications</Button>
                    <Button v-if="dirty" type="button" variant="ghost" size="sm" :disabled="processing" @click="resetDraft"><RotateCcw class="h-4 w-4" />Annuler</Button>
                    <Button type="button" variant="primary" size="sm" :disabled="processing || ! dirty || ! canAssign" @click="save">
                        <Check class="h-4 w-4" />{{ processing ? 'Enregistrement…' : 'Enregistrer les exceptions' }}
                    </Button>
                </div>
            </div>
        </div>

        <Dialog
            :open="showDiff"
            size="lg"
            :title="`Modifications préparées · ${selectedUser?.name ?? ''}`"
            description="Rien n’est appliqué tant que vous n’avez pas enregistré."
            @update:open="showDiff = $event"
        >
            <ul class="max-h-96 space-y-1.5 overflow-y-auto">
                <li
                    v-for="permission in changed"
                    :key="permission.id"
                    class="flex items-start justify-between gap-3 rounded-lg border border-border px-3 py-2"
                >
                    <span class="min-w-0">
                        <span class="block text-xs font-semibold text-foreground">{{ permissionLabel(permission) }}</span>
                        <span class="mt-0.5 block truncate font-mono text-[10px] text-muted-foreground">{{ permission.name }}</span>
                    </span>
                    <span class="shrink-0 text-[11px] font-bold text-muted-foreground">{{ describe(permission) }}</span>
                </li>
            </ul>
            <template #footer>
                <Button type="button" variant="outline" @click="showDiff = false">Fermer</Button>
            </template>
        </Dialog>

        <Dialog
            :open="pendingChange !== null"
            title="Autoriser cette permission sensible ?"
            description="Elle donne un pouvoir de suppression, de validation ou d’encaissement. Rien n’est appliqué tant que vous n’avez pas enregistré."
            @update:open="pendingChange = $event ? pendingChange : null"
        >
            <p class="text-sm leading-5 text-muted-foreground">
                <strong class="text-foreground">{{ pendingChange ? permissionLabel(pendingChange.permission) : '' }}</strong>
                <span class="mt-1 block font-mono text-[11px]">{{ pendingChange?.permission.name }}</span>
            </p>
            <template #footer>
                <Button type="button" variant="outline" @click="pendingChange = null">Annuler</Button>
                <Button type="button" variant="primary" @click="confirmPendingChange">Autoriser la permission</Button>
            </template>
        </Dialog>

        <Dialog
            :open="pendingBulk !== null"
            title="Confirmer l’action groupée ?"
            @update:open="pendingBulk = $event ? pendingBulk : null"
        >
            <p class="text-sm leading-5 text-muted-foreground">
                <strong class="text-foreground">{{ bulkLabel[pendingBulk?.effect ?? ''] }}</strong>
                sur {{ pendingBulk?.scope }}. Le brouillon seul est modifié : rien n’est envoyé au site tant que vous n’avez pas enregistré.
            </p>
            <p v-if="bulkHasSensitive" class="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                Cette sélection contient des permissions sensibles.
            </p>
            <template #footer>
                <Button type="button" variant="outline" @click="pendingBulk = null">Annuler</Button>
                <Button type="button" :variant="pendingBulk?.effect === 'deny' ? 'destructive' : 'primary'" @click="confirmBulk">Appliquer au brouillon</Button>
            </template>
        </Dialog>

        <Dialog
            :open="switching"
            size="lg"
            title="Choisir un compte"
            description="Les exceptions que vous réglerez ensuite ne concernent que ce compte."
            @update:open="switching = $event"
        >
            <IconInput
                v-model="userSearch"
                :icon="Search"
                type="search"
                placeholder="Nom, e-mail, rôle…"
                autocomplete="off"
                aria-label="Rechercher un compte"
            />

            <div class="mt-3 max-h-80 space-y-1 overflow-y-auto">
                <button
                    v-for="user in visibleUsers"
                    :key="user.uuid"
                    type="button"
                    :class="cn(
                        'flex w-full items-center gap-2.5 rounded-lg border px-3 py-2.5 text-start transition-colors',
                        user.uuid === selectedUuid ? 'border-primary bg-primary/5' : 'border-border hover:bg-accent',
                    )"
                    :aria-current="user.uuid === selectedUuid ? 'true' : undefined"
                    @click="chooseUser(user.uuid)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-sm font-semibold text-foreground">{{ user.name }}</span>
                            <Badge v-if="user.uuid === selectedUuid" variant="default" class="px-1.5 py-0 text-[10px]">En cours</Badge>
                            <Badge v-if="! user.active" variant="outline" class="px-1.5 py-0 text-[10px]">Désactivé</Badge>
                        </span>
                        <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">
                            {{ user.email }} · {{ user.role?.name ?? 'Sans rôle' }}
                            <template v-if="user.permission_overrides.length"> · {{ user.permission_overrides.length }} exception{{ user.permission_overrides.length > 1 ? 's' : '' }}</template>
                        </span>
                    </span>
                    <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground" />
                </button>

                <p v-if="! visibleUsers.length" class="px-3 py-8 text-center text-xs text-muted-foreground">
                    Aucun compte ne correspond à « {{ userSearch }} ».
                </p>
            </div>

            <template #footer>
                <Button type="button" variant="outline" @click="switching = false">Fermer</Button>
            </template>
        </Dialog>

        <Dialog
            :open="pendingUuid !== null"
            :title="`Quitter ${selectedUser?.name ?? ''} ?`"
            :description="`${changed.length} modification(s) préparée(s) seront perdues si vous ouvrez ${pendingUserName}.`"
            @update:open="pendingUuid = $event ? pendingUuid : null"
        >
            <template #footer>
                <Button type="button" variant="outline" @click="pendingUuid = null">Rester ici</Button>
                <Button type="button" variant="destructive" @click="confirmUserSwitch">Abandonner les modifications</Button>
            </template>
        </Dialog>
    </div>
</template>
