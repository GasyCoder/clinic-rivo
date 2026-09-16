<script setup>
import { computed, ref, watch } from 'vue';
import {
    ArrowLeftRight,
    Check,
    ChevronRight,
    Minus,
    RotateCcw,
    Search,
    ShieldCheck,
    TriangleAlert,
    X,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import PermissionCategoryNav from '@/Components/Rbac/PermissionCategoryNav.vue';
import FormError from '@/Components/UI/FormError.vue';
import ResizableSplit from '@/Components/UI/ResizableSplit.vue';
import { PERMISSION_DOMAINS, permissionCategoryDomain, permissionCategoryLabel } from '@/utilities/permissionCategories';
import {
    comparePermissions,
    diffPermissionSelection,
    isSensitivePermission,
    permissionActionGroup,
    permissionLabel,
    permissionMatchesSearch,
} from '@/utilities/permissionWorkspace';
import { cn } from '@/lib/cn';

/**
 * Le socle d'un rôle : ce que **tout** compte de ce rôle reçoit par défaut
 * sur ce site (ADR-064). Ce n'est pas l'exception d'un compte — celle-ci
 * s'applique toujours par-dessus, et cet écran n'y touche jamais.
 *
 * L'écran précédent paginait les catégories six par six : on ne pouvait ni
 * retrouver un droit dont on connaissait le nom, ni relire ce qu'on venait
 * de cocher trois pages plus tôt avant d'enregistrer. Trois choses le
 * remplacent — une recherche qui traverse tout le catalogue, un rail de
 * catégories toujours visible, et un écart consultable avant l'envoi.
 */
const props = defineProps({
    roles: { type: Array, default: () => [] },
    permissionCatalog: { type: Array, default: () => [] },
    siteName: { type: String, default: '' },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['save', 'close', 'update:dirty']);

const selectedRoleCode = ref(props.roles[0]?.code ?? '');
const draftIds = ref(new Set());
const search = ref('');
const filter = ref('all');
const selectedCategory = ref('');
const showDiff = ref(false);
const pendingRoleCode = ref(null);

const selectedRole = computed(() => props.roles.find((role) => role.code === selectedRoleCode.value) ?? null);

/** Le socle tel qu'il est enregistré sur le site : la référence de l'écart. */
const baselineIds = computed(() => new Set(
    props.permissionCatalog
        .filter((permission) => selectedRole.value?.permissions?.includes(permission.name))
        .map((permission) => permission.id),
));

const granted = (permission) => draftIds.value.has(permission.id);

const toggle = (permission) => {
    const next = new Set(draftIds.value);
    if (next.has(permission.id)) next.delete(permission.id); else next.add(permission.id);
    draftIds.value = next;
};

const setMany = (permissions, grant) => {
    const next = new Set(draftIds.value);
    for (const permission of permissions) {
        if (grant) next.add(permission.id); else next.delete(permission.id);
    }
    draftIds.value = next;
};

const diff = computed(() => diffPermissionSelection(props.permissionCatalog, baselineIds.value, draftIds.value));
const dirty = computed(() => diff.value.total > 0);
const changedIds = computed(() => new Set([...diff.value.added, ...diff.value.removed].map((permission) => permission.id)));

watch(dirty, (value) => emit('update:dirty', value), { immediate: true });

const matchesSearch = (permission) => permissionMatchesSearch(
    permission,
    search.value,
    permissionCategoryLabel(permission.module),
);

const matchesFilter = (permission) => {
    if (filter.value === 'granted') return granted(permission);
    if (filter.value === 'missing') return ! granted(permission);
    if (filter.value === 'changed') return changedIds.value.has(permission.id);
    if (filter.value === 'sensitive') return isSensitivePermission(permission);

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
        granted: permissions.filter(granted).length,
        changed: permissions.filter((permission) => changedIds.value.has(permission.id)).length,
        visible: permissions.filter(visible).length,
    })).sort((left, right) => {
        const order = domainIndex(left.domain) - domainIndex(right.domain);

        return order !== 0 ? order : left.label.localeCompare(right.label, 'fr');
    });
});

const categoryGroups = computed(() => PERMISSION_DOMAINS
    .map((domain) => ({ ...domain, categories: categories.value.filter((category) => category.domain === domain.key) }))
    .filter((group) => group.categories.length));

/** Le rail montre « accordées / total » : le compteur est aussi l'état. */
const navGroups = computed(() => categoryGroups.value.map((group) => ({
    key: group.key,
    label: group.label,
    categories: group.categories.map((category) => ({
        key: category.key,
        label: category.label,
        title: `${category.label} · code ${category.key}`,
        count: `${category.granted}/${category.total}`,
        marked: category.changed > 0,
    })),
})));

/** Ce que le site dit accorder à ce rôle, à l'instant où on l'a chargé. */
const baselineSignature = (code) => {
    const role = props.roles.find((item) => item.code === code);

    return role ? `${code}:${[...role.permissions].sort().join(',')}` : '';
};

const loadedSignature = ref(null);

const loadRole = (code) => {
    selectedRoleCode.value = code;
    draftIds.value = new Set(baselineIds.value);
    loadedSignature.value = baselineSignature(code);
    search.value = '';
    filter.value = 'all';
    selectedCategory.value = categories.value[0]?.key ?? '';
};

/**
 * Le brouillon part du socle réellement enregistré — au montage comme au
 * retour d'un enregistrement réussi, où le site renvoie le nouveau socle.
 * Sans ce départ, l'écart lirait « tout retiré » sur un rôle intact.
 *
 * Il ne repart en revanche **que** si le site a réellement changé d'avis :
 * un enregistrement refusé réaffiche la page avec le même socle, et
 * recharger là reviendrait à jeter la saisie précisément quand elle vient
 * d'échouer — le message d'erreur s'afficherait au-dessus d'un formulaire
 * vidé.
 */
watch(() => props.roles, () => {
    const exists = props.roles.some((role) => role.code === selectedRoleCode.value);
    const code = exists ? selectedRoleCode.value : props.roles[0]?.code ?? '';

    if (exists && baselineSignature(code) === loadedSignature.value) return;

    loadRole(code);
}, { immediate: true });

watch(categories, (list) => {
    if (! list.some((category) => category.key === selectedCategory.value)) {
        selectedCategory.value = list[0]?.key ?? '';
    }
});

/**
 * Une recherche traverse tout le catalogue, un filtre aussi.
 *
 * Chercher « supprimer » en restant dans la seule catégorie ouverte
 * reviendrait à ne rien chercher : c'est précisément quand on ne sait pas
 * où vit un droit qu'on le tape. La catégorie ouverte ne cadre donc la vue
 * que lorsque ni recherche ni filtre ne sont actifs.
 */
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
    { value: 'granted', label: 'Accordées' },
    { value: 'missing', label: 'Non accordées' },
    { value: 'sensitive', label: 'Sensibles' },
    { value: 'changed', label: 'Modifiées' },
];

/**
 * Les compteurs des filtres se lisent sur la recherche en cours, jamais sur
 * le filtre actif : ils servent à décider vers quel filtre aller.
 */
const filterCounts = computed(() => {
    const searched = props.permissionCatalog.filter(matchesSearch);

    return {
        all: searched.length,
        granted: searched.filter(granted).length,
        missing: searched.filter((permission) => ! granted(permission)).length,
        sensitive: searched.filter(isSensitivePermission).length,
        changed: searched.filter((permission) => changedIds.value.has(permission.id)).length,
    };
});

/** Une case à trois états : tout accordé, rien, ou une partie seulement. */
const sectionState = (permissions) => {
    const count = permissions.filter(granted).length;

    return count === 0 ? 'none' : count === permissions.length ? 'all' : 'some';
};

/**
 * Cliquer une catégorie quitte la recherche et le filtre en cours : tant
 * qu'ils sont actifs la vue les suit (voir `scopedToCategory`), et le clic
 * n'aurait donc rien changé à l'écran.
 */
const openCategory = (key) => {
    search.value = '';
    filter.value = 'all';
    selectedCategory.value = key;
};

const resetDraft = () => { draftIds.value = new Set(baselineIds.value); };

/**
 * Le rôle se choisit dans une fenêtre, plus dans une liste empilée au-dessus
 * des catégories.
 *
 * Onze rôles puis quarante catégories dans la même colonne, cela faisait
 * plus de 900 px à parcourir pour atteindre le travail réel — et la liste
 * des rôles occupait cette place en permanence alors qu'on n'en change
 * qu'une fois. La fenêtre rend la colonne aux catégories et apporte ce qui
 * manquait vraiment : une recherche.
 */
const switching = ref(false);
const roleSearch = ref('');

const visibleRoles = computed(() => {
    const term = roleSearch.value.trim().toLowerCase();

    if (! term) return props.roles;

    return props.roles.filter((role) => `${role.name} ${role.code}`.toLowerCase().includes(term));
});

const openSwitcher = () => {
    roleSearch.value = '';
    switching.value = true;
};

const chooseRole = (code) => {
    switching.value = false;
    requestRoleSwitch(code);
};

const requestRoleSwitch = (code) => {
    if (code === selectedRoleCode.value) return;
    if (dirty.value) { pendingRoleCode.value = code; return; }
    loadRole(code);
};

const confirmRoleSwitch = () => {
    loadRole(pendingRoleCode.value);
    pendingRoleCode.value = null;
};

const pendingRoleName = computed(() => props.roles.find((role) => role.code === pendingRoleCode.value)?.name ?? '');

const save = () => emit('save', { role: selectedRole.value, permissionIds: Array.from(draftIds.value) });
</script>

<template>
    <div class="space-y-4">
        <FormError v-if="errors.role">{{ errors.role }}</FormError>
        <FormError v-if="errors.permission_ids">{{ errors.permission_ids }}</FormError>

        <!-- Deux panneaux séparés par une barre que l'on glisse. La largeur
             utile du rail dépend de ce qu'on fait : parcourir des catégories
             n'a pas les mêmes besoins que relire des libellés longs à
             droite. Le choix appartient au poste, et il y reste. -->
        <ResizableSplit
            storage-key="rivo:super-admin:baseline-split"
            :default-ratio="0.26"
            :min-ratio="0.18"
            :max-ratio="0.45"
            start-label="panneau du rôle et des catégories"
            end-label="panneau du socle"
        >
            <template #start>
            <div class="space-y-4 pe-1 sticky top-4">
                <!-- Le rôle réglé, en clair, et une seule commande pour en
                     changer : la liste complète ne monopolise plus la
                     colonne pour un choix qu'on ne fait qu'une fois. -->
                <Card class="overflow-hidden">
                    <p class="border-b border-border px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Rôle réglé</p>
                    <div class="p-3">
                        <div class="flex items-start gap-2.5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                <ShieldCheck class="h-4.5 w-4.5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate text-sm font-bold text-foreground">{{ selectedRole?.name ?? 'Aucun rôle' }}</span>
                                    <span v-if="dirty" class="h-2 w-2 shrink-0 rounded-full bg-amber-500" title="Modifications non enregistrées" />
                                </span>
                                <span class="mt-0.5 block font-mono text-[10px] text-muted-foreground">{{ selectedRole?.code }}</span>
                                <span class="mt-0.5 block text-[11px] text-muted-foreground">
                                    {{ draftIds.size }} droit{{ draftIds.size > 1 ? 's' : '' }}
                                    <template v-if="selectedRole?.profiles?.length"> · {{ selectedRole.profiles.length }} profil{{ selectedRole.profiles.length > 1 ? 's' : '' }}</template>
                                    <template v-if="selectedRole?.users_count"> · {{ selectedRole.users_count }} compte{{ selectedRole.users_count > 1 ? 's' : '' }}</template>
                                </span>
                            </span>
                        </div>
                        <Button type="button" variant="outline" size="sm" class="mt-3 w-full" @click="openSwitcher">
                            <ArrowLeftRight class="h-4 w-4" />Changer de rôle
                            <span class="ms-auto text-[11px] font-normal text-muted-foreground">{{ roles.length }}</span>
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
                            <h2 class="truncate text-base font-bold text-foreground">Socle du rôle « {{ selectedRole?.name }} »</h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ draftIds.size }} permission{{ draftIds.size > 1 ? 's' : '' }} accordée{{ draftIds.size > 1 ? 's' : '' }} sur {{ permissionCatalog.length }} · {{ siteName }}</p>
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
                            <span class="ms-1 tabular-nums font-normal">{{ filterCounts[option.value] }}</span>
                        </button>
                    </div>
                </header>

                <div v-if="visibleCount === 0" class="flex flex-1 flex-col items-center justify-center px-6 py-14 text-center">
                    <span class="grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><Search class="h-5 w-5" /></span>
                    <p class="mt-3 text-sm font-semibold text-foreground">Aucune permission dans cette vue</p>
                    <p class="mt-1 text-xs text-muted-foreground">Changez la recherche, le filtre ou la catégorie.</p>
                </div>

                <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
                    <section v-for="section in visibleSections" :key="section.key" class="mb-5 last:mb-0">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2">
                            <div class="flex min-w-0 items-center gap-2">
                                <h3 class="truncate text-sm font-bold text-foreground">{{ section.label }}</h3>
                                <Badge variant="outline" class="px-2 py-0 text-[10px] tabular-nums">{{ section.granted }} / {{ section.total }}</Badge>
                                <Badge v-if="section.changed" variant="warning" class="px-2 py-0 text-[10px]">{{ section.changed }} modifié{{ section.changed > 1 ? 's' : '' }}</Badge>
                            </div>
                            <div class="flex items-center gap-1">
                                <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px]" @click="setMany(section.shown, true)">
                                    <Check class="h-3.5 w-3.5" />Tout accorder
                                </Button>
                                <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px]" @click="setMany(section.shown, false)">
                                    <Minus class="h-3.5 w-3.5" />Tout retirer
                                </Button>
                            </div>
                        </div>

                        <div v-for="group in section.groups" :key="`${section.key}-${group.label}`" class="mb-3 last:mb-0">
                            <div class="mb-1.5 flex items-center gap-2">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ group.label }}</p>
                                <span class="h-px flex-1 bg-border" />
                                <button
                                    type="button"
                                    class="text-[11px] font-semibold text-primary hover:underline"
                                    @click="setMany(group.permissions, sectionState(group.permissions) !== 'all')"
                                >{{ sectionState(group.permissions) === 'all' ? 'Tout retirer' : 'Tout accorder' }}</button>
                            </div>

                            <div class="grid gap-1.5 md:grid-cols-2 2xl:grid-cols-3">
                                <label
                                    v-for="permission in group.permissions"
                                    :key="permission.id"
                                    :class="cn(
                                        'flex cursor-pointer items-start gap-2.5 rounded-lg border p-2.5 transition-colors',
                                        granted(permission)
                                            ? 'border-primary/40 bg-primary/5'
                                            : 'border-border hover:bg-accent/50',
                                        changedIds.has(permission.id) ? 'ring-1 ring-amber-400' : '',
                                    )"
                                >
                                    <Checkbox
                                        class="mt-0.5"
                                        :model-value="granted(permission)"
                                        :aria-label="permissionLabel(permission)"
                                        @update:model-value="toggle(permission)"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-1">
                                            <span class="text-xs font-semibold text-foreground">{{ permissionLabel(permission) }}</span>
                                            <TriangleAlert v-if="isSensitivePermission(permission)" class="h-3 w-3 shrink-0 text-amber-500" aria-label="Permission sensible" />
                                        </span>
                                        <span class="mt-0.5 block truncate font-mono text-[10px] text-muted-foreground" :title="permission.name">{{ permission.name }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </section>
                </div>
            </Card>
            </template>
        </ResizableSplit>

        <!-- Barre collante : l'écart est lisible et annulable là où l'on
             enregistre, jamais au prix d'un retour en haut de page. -->
        <div class="sticky bottom-0 z-20 -mx-1 rounded-xl border border-border bg-card/95 px-4 py-3 shadow-lg backdrop-blur">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', dirty ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' : 'bg-muted text-muted-foreground')">
                        <ShieldCheck class="h-4.5 w-4.5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-foreground">
                            <template v-if="dirty">{{ diff.total }} modification{{ diff.total > 1 ? 's' : '' }} non enregistrée{{ diff.total > 1 ? 's' : '' }}</template>
                            <template v-else>Socle à jour</template>
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            <template v-if="dirty">+{{ diff.added.length }} accordée{{ diff.added.length > 1 ? 's' : '' }} · −{{ diff.removed.length }} retirée{{ diff.removed.length > 1 ? 's' : '' }} · s'appliquera à tous les comptes {{ selectedRole?.name }}</template>
                            <template v-else>Les exceptions individuelles de chaque compte restent inchangées.</template>
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="dirty" type="button" variant="outline" size="sm" @click="showDiff = true">Voir les modifications</Button>
                    <Button v-if="dirty" type="button" variant="ghost" size="sm" :disabled="processing" @click="resetDraft"><RotateCcw class="h-4 w-4" />Annuler</Button>
                    <Button type="button" variant="outline" size="sm" :disabled="processing" @click="emit('close')">Fermer</Button>
                    <Button type="button" variant="primary" size="sm" :disabled="processing || ! dirty" @click="save">
                        <Check class="h-4 w-4" />{{ processing ? 'Enregistrement…' : 'Enregistrer le socle' }}
                    </Button>
                </div>
            </div>
        </div>

        <Dialog
            :open="showDiff"
            size="lg"
            title="Modifications du socle"
            :description="`Ce qui sera envoyé à ${siteName} pour le rôle « ${selectedRole?.name} ».`"
            body-class="max-h-[60vh] overflow-y-auto"
            @update:open="showDiff = $event"
        >
            <div class="space-y-5">
                <div>
                    <p class="mb-2 flex items-center gap-2 text-sm font-bold text-emerald-700 dark:text-emerald-300"><Check class="h-4 w-4" />Accordées ({{ diff.added.length }})</p>
                    <ul v-if="diff.added.length" class="space-y-1">
                        <li v-for="permission in diff.added" :key="`add-${permission.id}`" class="flex items-baseline gap-2 rounded-md bg-emerald-50 px-2.5 py-1.5 text-xs dark:bg-emerald-950/25">
                            <span class="font-semibold text-foreground">{{ permission.label }}</span>
                            <span class="truncate font-mono text-[10px] text-muted-foreground">{{ permission.name }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-muted-foreground">Aucune.</p>
                </div>
                <div>
                    <p class="mb-2 flex items-center gap-2 text-sm font-bold text-destructive"><X class="h-4 w-4" />Retirées ({{ diff.removed.length }})</p>
                    <ul v-if="diff.removed.length" class="space-y-1">
                        <li v-for="permission in diff.removed" :key="`remove-${permission.id}`" class="flex items-baseline gap-2 rounded-md bg-red-50 px-2.5 py-1.5 text-xs dark:bg-red-950/25">
                            <span class="font-semibold text-foreground">{{ permission.label }}</span>
                            <span class="truncate font-mono text-[10px] text-muted-foreground">{{ permission.name }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-muted-foreground">Aucune.</p>
                </div>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="showDiff = false">Continuer la modification</Button>
                <Button type="button" variant="primary" :disabled="processing" @click="showDiff = false; save()"><Check class="h-4 w-4" />Enregistrer le socle</Button>
            </template>
        </Dialog>

        <!-- Choisir un rôle : une recherche, et ce que chaque rôle porte
             réellement — c'est ce qui manquait à la liste empilée. -->
        <Dialog
            :open="switching"
            size="lg"
            title="Choisir un rôle"
            description="Le socle que vous réglerez ensuite s’applique à tous les comptes de ce rôle."
            @update:open="switching = $event"
        >
            <IconInput
                v-model="roleSearch"
                :icon="Search"
                type="search"
                placeholder="Nom ou code du rôle…"
                autocomplete="off"
                aria-label="Rechercher un rôle"
            />

            <div class="mt-3 max-h-80 space-y-1 overflow-y-auto">
                <button
                    v-for="role in visibleRoles"
                    :key="role.code"
                    type="button"
                    :class="cn(
                        'flex w-full items-center gap-2.5 rounded-lg border px-3 py-2.5 text-start transition-colors',
                        role.code === selectedRoleCode
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:bg-accent',
                    )"
                    :aria-current="role.code === selectedRoleCode ? 'true' : undefined"
                    @click="chooseRole(role.code)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-sm font-semibold text-foreground">{{ role.name }}</span>
                            <Badge v-if="role.code === selectedRoleCode" variant="default" class="px-1.5 py-0 text-[10px]">En cours</Badge>
                        </span>
                        <span class="mt-0.5 block text-[11px] text-muted-foreground">
                            <span class="font-mono">{{ role.code }}</span>
                            · {{ role.permissions.length }} droit{{ role.permissions.length > 1 ? 's' : '' }}
                            · {{ role.profiles.length }} profil{{ role.profiles.length > 1 ? 's' : '' }}
                            <template v-if="role.users_count !== undefined"> · {{ role.users_count }} compte{{ role.users_count > 1 ? 's' : '' }}</template>
                        </span>
                    </span>
                    <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground" />
                </button>

                <p v-if="! visibleRoles.length" class="px-3 py-8 text-center text-xs text-muted-foreground">
                    Aucun rôle ne correspond à « {{ roleSearch }} ».
                </p>
            </div>

            <template #footer>
                <Button type="button" variant="outline" @click="switching = false">Fermer</Button>
            </template>
        </Dialog>

        <Dialog
            :open="pendingRoleCode !== null"
            title="Changer de rôle sans enregistrer ?"
            :description="`${diff.total} modification${diff.total > 1 ? 's' : ''} du socle « ${selectedRole?.name} » n'${diff.total > 1 ? 'ont' : 'a'} pas été envoyée${diff.total > 1 ? 's' : ''} au site. Le socle actuel reste alors inchangé.`"
            @update:open="pendingRoleCode = $event ? pendingRoleCode : null"
        >
            <template #footer>
                <Button type="button" variant="outline" @click="pendingRoleCode = null">Continuer la modification</Button>
                <Button type="button" variant="destructive" @click="confirmRoleSwitch">Ouvrir « {{ pendingRoleName }} »</Button>
            </template>
        </Dialog>
    </div>
</template>
