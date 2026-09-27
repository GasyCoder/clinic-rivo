<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Archive, Check, CheckCheck, Lock, RotateCcw, Search, TriangleAlert, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PermissionMatrix from '@/Components/Rbac/PermissionMatrix.vue';
import PermissionModuleCard from '@/Components/Rbac/PermissionModuleCard.vue';
import PermissionSaveBar from '@/Components/Rbac/PermissionSaveBar.vue';
import PermissionToolbar from '@/Components/Rbac/PermissionToolbar.vue';
import { keepHeaderInPlace, nextModuleState } from '@/composables/useExclusiveModules';
import RoleOverviewCard from '@/Components/Rbac/RoleOverviewCard.vue';
import {
    diffPermissionSelection,
    isSensitivePermission,
    matchesSearchTerms,
    permissionLabel,
    permissionModuleOf,
    permissionSearchIndex,
} from '@/utilities/permissionWorkspace';
import { cn } from '@/lib/cn';

/**
 * Le socle d'un rôle : ce que **tout** compte de ce rôle reçoit par défaut
 * sur ce site (ADR-064), réglé dans la grille des modules (ADR-178).
 *
 * Ce n'est pas l'exception d'un compte — celle-ci s'applique toujours
 * par-dessus, et cet écran n'y touche jamais :
 *
 *     DENY individuel  >  ALLOW individuel  >  socle du rôle
 *
 * Tout ce qu'on coche reste un brouillon jusqu'à « Enregistrer » ; la barre
 * du bas dit combien, lesquelles, et à qui elles s'appliqueront. Une seule
 * confirmation : accorder des permissions sensibles à tout un métier.
 *
 * Le composant est recréé à chaque changement de rôle (clé posée par la
 * page) : un brouillon n'appartient qu'à son rôle.
 */
const props = defineProps({
    siteCode: { type: String, required: true },
    siteName: { type: String, default: '' },
    role: { type: Object, required: true },
    modules: { type: Array, default: () => [] },
    catalog: { type: Array, default: () => [] },
    /**
     * ADR-153 — les comptes du site, avec leurs exceptions. Un `DENY` nominatif
     * l'emporte sur ce socle (ADR-033) : cocher un droit ici ne l'ouvre pas
     * pour eux, et la case doit le dire elle-même.
     */
    users: { type: Array, default: () => [] },
    /** `{ edit, reset, rename, archive, restore }` — calculés par la page depuis `can()`. */
    abilities: { type: Object, default: () => ({}) },
    search: { type: String, default: '' },
    filter: { type: String, default: 'all' },
    /** Modules dépliés, gardés par la page d'un rôle à l'autre. */
    expanded: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:dirty', 'update:pending', 'update:search', 'update:filter', 'update:expanded', 'show-accounts']);

/** Archivé ou sans `users.manage` : on lit le socle, on ne le change pas. */
const readonly = computed(() => props.role.archived || ! props.abilities.edit);

/* ------------------------------------------------------------------ */
/* Socle enregistré, brouillon, écart                                  */
/* ------------------------------------------------------------------ */

/** Le socle tel qu'il est enregistré sur le site : la référence de l'écart. */
const baselineIds = computed(() => new Set(
    props.catalog
        .filter((permission) => props.role.permissions?.includes(permission.name))
        .map((permission) => permission.id),
));

const draftIds = ref(new Set(baselineIds.value));

/** Ce que le site dit accorder à ce rôle, à l'instant où on l'a chargé. */
const baselineSignature = () => [...(props.role.permissions ?? [])].sort().join(',');
const loadedSignature = ref(baselineSignature());

/**
 * Le brouillon repart du socle réellement enregistré au retour d'un
 * enregistrement réussi, où le site renvoie le nouveau socle.
 *
 * Il ne repart en revanche **que** si le site a réellement changé d'avis :
 * un enregistrement refusé réaffiche la page avec le même socle, et recharger
 * là jetterait la saisie précisément quand elle vient d'échouer.
 */
watch(() => props.role.permissions, () => {
    const signature = baselineSignature();

    if (signature === loadedSignature.value) return;

    draftIds.value = new Set(baselineIds.value);
    loadedSignature.value = signature;
});

const granted = (permission) => draftIds.value.has(permission.id);

const diff = computed(() => diffPermissionSelection(props.catalog, baselineIds.value, draftIds.value));
const dirty = computed(() => diff.value.total > 0);
const changedIds = computed(() => new Set([...diff.value.added, ...diff.value.removed].map((permission) => permission.id)));

watch(dirty, (value) => emit('update:dirty', value), { immediate: true });
watch(() => diff.value.total, (count) => emit('update:pending', count), { immediate: true });

const toggle = (permission) => {
    if (readonly.value) return;

    const next = new Set(draftIds.value);
    if (next.has(permission.id)) next.delete(permission.id); else next.add(permission.id);
    draftIds.value = next;
};

const setMany = (permissions, value) => {
    if (readonly.value) return;

    const next = new Set(draftIds.value);
    for (const permission of permissions) {
        if (value) next.add(permission.id); else next.delete(permission.id);
    }
    draftIds.value = next;
};

const discard = () => { draftIds.value = new Set(baselineIds.value); };

/* ------------------------------------------------------------------ */
/* Exceptions des comptes de ce rôle (ADR-150, ADR-153)                */
/* ------------------------------------------------------------------ */

/**
 * Pour le rôle réglé : combien de ses comptes refusent ou s'accordent chaque
 * droit à titre individuel. Le constat qui l'a motivé : un socle enregistré
 * avec « Consulter les patients hospitalisés » n'ouvrait rien pour le compte
 * connecté, parce qu'il portait un `DENY` nominatif — invisible ici.
 */
const overridesByPermission = computed(() => {
    const map = new Map();

    for (const user of props.users) {
        if (user.role?.code !== props.role.code) continue;

        for (const override of user.permission_overrides ?? []) {
            const entry = map.get(override.permission_id) ?? { allow: 0, deny: 0 };
            if (override.effect === 'deny') entry.deny += 1;
            if (override.effect === 'allow') entry.allow += 1;
            map.set(override.permission_id, entry);
        }
    }

    return map;
});

const anyDenied = computed(() => Array.from(overridesByPermission.value.values()).some((entry) => entry.deny > 0));

/* ------------------------------------------------------------------ */
/* Recherche, filtres, visibilité                                      */
/* ------------------------------------------------------------------ */

const searchIndex = computed(() => permissionSearchIndex(props.modules));

const matchesSearch = (permission) => matchesSearchTerms(searchIndex.value.get(permission.id) ?? permission.name, props.search);

const matchesFilter = (permission) => {
    if (props.filter === 'granted') return granted(permission);
    if (props.filter === 'missing') return ! granted(permission);
    if (props.filter === 'sensitive') return isSensitivePermission(permission);
    if (props.filter === 'changed') return changedIds.value.has(permission.id);

    return true;
};

const filtering = computed(() => props.search.trim() !== '' || props.filter !== 'all');

const visibleIds = computed(() => new Set(props.catalog
    .filter((permission) => matchesSearch(permission) && matchesFilter(permission))
    .map((permission) => permission.id)));

/**
 * Les compteurs des filtres se lisent sur la recherche en cours, jamais sur
 * le filtre actif : ils servent à décider vers quel filtre aller.
 */
const filterOptions = computed(() => {
    const searched = props.catalog.filter(matchesSearch);

    return [
        { value: 'all', label: 'Toutes', count: searched.length },
        { value: 'granted', label: 'Accordées', count: searched.filter(granted).length, tone: 'primary' },
        { value: 'missing', label: 'Non accordées', count: searched.filter((permission) => ! granted(permission)).length, tone: 'muted' },
        { value: 'sensitive', label: 'Sensibles', count: searched.filter(isSensitivePermission).length, tone: 'warning' },
        { value: 'changed', label: 'Modifiées', count: searched.filter((permission) => changedIds.value.has(permission.id)).length, tone: 'warning' },
    ];
});

const describe = (permission) => {
    const isGranted = granted(permission);

    return {
        active: isGranted,
        granted: isGranted,
        changed: changedIds.value.has(permission.id),
        sensitive: isSensitivePermission(permission),
        denyCount: overridesByPermission.value.get(permission.id)?.deny ?? 0,
        dimmed: filtering.value && ! visibleIds.value.has(permission.id),
    };
};

const moduleStats = computed(() => Object.fromEntries(props.modules.map((module) => {
    const shown = filtering.value
        ? module.permissions.filter((permission) => visibleIds.value.has(permission.id))
        : module.permissions;

    return [module.key, {
        shown,
        total: shown.length,
        active: shown.filter(granted).length,
        allActive: module.permissions.filter(granted).length,
        changed: module.permissions.filter((permission) => changedIds.value.has(permission.id)).length,
    }];
})));

const shownModules = computed(() => props.modules.filter((module) => moduleStats.value[module.key].total > 0));

const shownCount = computed(() => (filtering.value ? visibleIds.value.size : null));

/* ------------------------------------------------------------------ */
/* Modules dépliés                                                     */
/* ------------------------------------------------------------------ */

/**
 * Un seul module ouvert à la fois : en ouvrir un referme les autres. Pendant
 * une recherche, chaque module qui contient un résultat s'ouvre de lui-même —
 * un résultat caché dans un module fermé n'en serait pas un — ; en ouvrir un
 * à la main referme les autres. Le module ouvert suit d'un rôle à l'autre.
 */
const collapsedWhileFiltering = ref(new Set());

watch(() => [props.search, props.filter], () => { collapsedWhileFiltering.value = new Set(); });

const isExpanded = (module) => (filtering.value
    ? ! collapsedWhileFiltering.value.has(module.key)
    : props.expanded.includes(module.key));

/** Ouvrir un module referme les autres ; l'en-tête cliqué reste où il était. */
const toggleModule = (module) => keepHeaderInPlace(module.key, () => {
    const next = nextModuleState({
        key: module.key,
        opening: ! isExpanded(module),
        filtering: filtering.value,
        shownKeys: shownModules.value.map((shown) => shown.key),
        collapsed: collapsedWhileFiltering.value,
    });

    if (next.collapsed) collapsedWhileFiltering.value = next.collapsed;
    else emit('update:expanded', next.expanded);
});

const anyExpanded = computed(() => shownModules.value.some(isExpanded));

const collapseAll = () => {
    if (filtering.value) collapsedWhileFiltering.value = new Set(shownModules.value.map((module) => module.key));
    else emit('update:expanded', []);
};

/* ------------------------------------------------------------------ */
/* Résumé                                                              */
/* ------------------------------------------------------------------ */

const sensitiveGranted = computed(() => props.catalog.filter((permission) => granted(permission) && isSensitivePermission(permission)).length);
const modulesCovered = computed(() => props.modules.filter((module) => moduleStats.value[module.key].allActive > 0).length);

const defaultIds = computed(() => new Set(
    props.catalog
        .filter((permission) => props.role.default_permissions?.includes(permission.name))
        .map((permission) => permission.id),
));

/** L'écart du socle **enregistré** avec celui livré par l'application. */
const defaultDiff = computed(() => (props.role.has_default_baseline
    ? diffPermissionSelection(props.catalog, baselineIds.value, defaultIds.value)
    : null));

/* ------------------------------------------------------------------ */
/* Enregistrer                                                         */
/* ------------------------------------------------------------------ */

const baselineForm = useForm({ permission_ids: [] });
const resetForm = useForm({});
const savedAt = ref(null);
const reviewing = ref(false);
const confirmingSensitive = ref(false);

/**
 * Accorder une permission sensible à **tout** un métier mérite un deuxième
 * regard ; retirer un droit, non — il ne peut que restreindre.
 */
const sensitiveAdditions = computed(() => diff.value.added.filter(isSensitivePermission));

const sensitiveDescription = computed(() => {
    const pronoun = sensitiveAdditions.value.length > 1 ? 'les' : 'la';
    const count = props.role.users_count ?? 0;

    if (count === 0) return `Tout compte du rôle « ${props.role.name} » ${pronoun} recevra dès l’enregistrement.`;
    if (count === 1) return `Le compte du rôle « ${props.role.name} » ${pronoun} recevra dès l’enregistrement.`;

    return `Les ${count} comptes du rôle « ${props.role.name} » ${pronoun} recevront dès l’enregistrement.`;
});

const requestSave = () => {
    if (! dirty.value || readonly.value || baselineForm.processing) return;

    reviewing.value = false;

    if (sensitiveAdditions.value.length) {
        confirmingSensitive.value = true;

        return;
    }

    submit();
};

const submit = () => {
    confirmingSensitive.value = false;
    baselineForm.permission_ids = Array.from(draftIds.value);
    baselineForm.put(`/super-admin/workspaces/roles/${props.siteCode}/permissions/${props.role.code}`, {
        preserveScroll: true,
        onSuccess: () => { savedAt.value = Date.now(); },
    });
};

const saveErrors = computed(() => [...new Set([
    ...Object.values(baselineForm.errors),
    ...Object.values(resetForm.errors),
].filter(Boolean))]);

const scope = computed(() => {
    const count = props.role.users_count ?? 0;

    if (count === 0) return 'Aucun compte ne porte encore ce rôle.';

    return `S’appliqueront ${count > 1 ? `aux ${count} comptes` : 'au compte'} du rôle.`;
});

/** L'écart relu avant l'envoi, rangé par module comme la grille. */
const reviewSections = computed(() => [
    { key: 'add', title: 'Accordées', permissions: diff.value.added },
    { key: 'remove', title: 'Retirées', permissions: diff.value.removed },
]);

const diffByModule = (permissions) => {
    const groups = new Map();

    for (const permission of permissions) {
        const module = permissionModuleOf(permission);
        if (! groups.has(module.key)) groups.set(module.key, { module, permissions: [] });
        groups.get(module.key).permissions.push(permission);
    }

    return Array.from(groups.values());
};

/* ------------------------------------------------------------------ */
/* Actions sur le rôle                                                 */
/* ------------------------------------------------------------------ */

const confirmingReset = ref(false);
const archiving = ref(false);
const archiveForm = useForm({ reason: '' });

const onAction = (key) => {
    if (key === 'reset') confirmingReset.value = true;
    if (key === 'archive') {
        archiveForm.reset();
        archiveForm.clearErrors();
        archiving.value = true;
    }
    if (key === 'restore') {
        router.post(`/super-admin/workspaces/roles/${props.siteCode}/${props.role.code}/restore`, {}, { preserveScroll: true });
    }
};

const resetToDefault = () => resetForm.post(
    `/super-admin/workspaces/roles/${props.siteCode}/permissions/${props.role.code}/reset`,
    {
        preserveScroll: true,
        onSuccess: () => {
            confirmingReset.value = false;
            savedAt.value = Date.now();
        },
    },
);

const submitArchive = () => archiveForm.delete(
    `/super-admin/workspaces/roles/${props.siteCode}/${props.role.code}`,
    { preserveScroll: true, onSuccess: () => { archiving.value = false; } },
);

const plural = (count, word) => `${count} ${word}${count > 1 ? 's' : ''}`;
</script>

<template>
    <div class="space-y-4">
        <!-- Le rôle système ne se règle jamais depuis un site (ADR-025,
             ADR-027) : on le dit, plutôt que d'afficher une grille vide qui
             laisserait croire à un oubli. -->
        <template v-if="role.protected">
            <Card class="px-6 py-12 text-center">
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground" aria-hidden="true">
                    <Lock class="h-5 w-5" />
                </span>
                <h2 class="mt-3 font-heading text-lg font-bold text-foreground">{{ role.name }}</h2>
                <p class="mx-auto mt-1.5 max-w-lg text-sm leading-6 text-muted-foreground">
                    Ce rôle appartient à l’architecture du portail : il reçoit automatiquement toutes les permissions sur
                    <code class="font-mono">admin.rivo.mg</code> et aucune sur un site clinique. Il ne se règle ni ne s’attribue ici.
                </p>
            </Card>
        </template>

        <template v-else>
            <RoleOverviewCard
                :site-code="siteCode"
                :role="role"
                :granted="draftIds.size"
                :catalog-size="catalog.length"
                :sensitive-granted="sensitiveGranted"
                :modules-covered="modulesCovered"
                :modules-total="modules.length"
                :default-diff="defaultDiff"
                :dirty="dirty"
                :abilities="abilities"
                :readonly="readonly"
                @action="onAction"
                @show-accounts="emit('show-accounts')"
            />

            <div class="space-y-3">
                <PermissionToolbar
                    :search="search"
                    :filter="filter"
                    :filters="filterOptions"
                    :any-expanded="anyExpanded"
                    :shown="shownCount"
                    @update:search="emit('update:search', $event)"
                    @update:filter="emit('update:filter', $event)"
                    @collapse-all="collapseAll"
                />

                <!-- La légende des cases, une fois pour toutes. -->
                <p class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] text-muted-foreground" aria-hidden="true">
                    <span class="inline-flex items-center gap-1.5"><span class="grid h-4 w-4 place-items-center rounded border border-primary bg-primary text-primary-foreground"><Check class="h-3 w-3" :stroke-width="3" /></span>Accordée</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-4 w-4 rounded border border-input bg-card" />Non accordée</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-4 w-4 rounded border border-input bg-card ring-2 ring-amber-400 ring-offset-1 ring-offset-background" />Modifiée, pas encore enregistrée</span>
                    <span class="inline-flex items-center gap-1.5"><TriangleAlert class="h-3.5 w-3.5 text-amber-500" />Sensible</span>
                    <span v-if="anyDenied" class="inline-flex items-center gap-1.5">
                        <span class="grid h-4 min-w-4 place-items-center rounded-full bg-destructive px-1 text-[9px] font-bold text-destructive-foreground">N</span>
                        Refusée individuellement à N compte(s) : la cocher ne l’ouvre pas pour eux
                    </span>
                </p>

                <div v-if="! shownModules.length" class="rounded-xl border border-dashed border-border bg-card px-6 py-12 text-center">
                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><Search class="h-5 w-5" /></span>
                    <p class="mt-3 text-sm font-semibold text-foreground">Aucune permission ne correspond</p>
                    <p class="mt-1 text-xs text-muted-foreground">Changez la recherche ou le filtre.</p>
                    <Button type="button" variant="outline" size="sm" class="mt-4" @click="emit('update:search', ''); emit('update:filter', 'all')">
                        Tout afficher
                    </Button>
                </div>

                <PermissionModuleCard
                    v-for="module in shownModules"
                    :key="module.key"
                    :module="module"
                    :expanded="isExpanded(module)"
                    :active="moduleStats[module.key].active"
                    :total="moduleStats[module.key].total"
                    :changed="moduleStats[module.key].changed"
                    :filtering="filtering"
                    @toggle="toggleModule(module)"
                >
                    <template v-if="! readonly" #actions>
                        <div class="flex items-center rounded-lg border border-border bg-card p-0.5">
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                class="h-7 px-2"
                                :disabled="moduleStats[module.key].active === moduleStats[module.key].total"
                                :title="`Tout sélectionner dans « ${module.label} »${filtering ? ' (permissions affichées)' : ''}`"
                                :aria-label="`Tout sélectionner dans « ${module.label} »`"
                                @click="setMany(moduleStats[module.key].shown, true)"
                            >
                                <CheckCheck class="h-3.5 w-3.5" /><span class="hidden lg:inline">Tout sélectionner</span>
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                class="h-7 px-2"
                                :disabled="moduleStats[module.key].active === 0"
                                :title="`Tout désélectionner dans « ${module.label} »${filtering ? ' (permissions affichées)' : ''}`"
                                :aria-label="`Tout désélectionner dans « ${module.label} »`"
                                @click="setMany(moduleStats[module.key].shown, false)"
                            >
                                <X class="h-3.5 w-3.5" /><span class="hidden lg:inline">Tout désélectionner</span>
                            </Button>
                        </div>
                    </template>

                    <PermissionMatrix
                        :groups="module.groups"
                        mode="baseline"
                        :describe="describe"
                        :readonly="readonly"
                        @toggle="toggle"
                        @set-many="setMany($event.permissions, $event.value)"
                    />
                </PermissionModuleCard>

                <PermissionSaveBar
                    v-if="! role.archived"
                    :dirty="dirty"
                    :count="diff.total"
                    :added="diff.added.length"
                    :removed="diff.removed.length"
                    :processing="baselineForm.processing || resetForm.processing"
                    :can-save="! readonly"
                    :saved-at="savedAt"
                    :scope="scope"
                    :clean-text="`Le socle de « ${role.name} » s’applique ${role.users_count ? `à ${plural(role.users_count, 'compte')}` : 'à tout compte qui recevra ce rôle'} ; leurs exceptions individuelles restent inchangées.`"
                    :errors="saveErrors"
                    save-label="Enregistrer les modifications"
                    @save="requestSave"
                    @discard="discard"
                    @review="reviewing = true"
                />
            </div>
        </template>

        <!-- Relire avant d'envoyer : un compteur seul ne dit pas lesquelles. -->
        <Dialog
            :open="reviewing"
            size="lg"
            title="Modifications du socle"
            :description="`Ce qui sera envoyé à ${siteName} pour le rôle « ${role.name} ».`"
            body-class="max-h-[60vh] overflow-y-auto"
            @update:open="reviewing = $event"
        >
            <div class="space-y-5">
                <div v-for="section in reviewSections" :key="section.key">
                    <p :class="cn('mb-2 flex items-center gap-2 text-sm font-bold', section.key === 'add' ? 'text-emerald-700 dark:text-emerald-300' : 'text-destructive')">
                        <Check v-if="section.key === 'add'" class="h-4 w-4" /><X v-else class="h-4 w-4" />{{ section.title }} ({{ section.permissions.length }})
                    </p>
                    <p v-if="! section.permissions.length" class="text-xs text-muted-foreground">Aucune.</p>
                    <div v-for="group in diffByModule(section.permissions)" :key="`${section.key}-${group.module.key}`" class="mb-2">
                        <p class="mb-1 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ group.module.label }}</p>
                        <ul class="space-y-1">
                            <li
                                v-for="permission in group.permissions"
                                :key="`${section.key}-${permission.id}`"
                                :class="cn('flex flex-wrap items-baseline gap-x-2 rounded-md px-2.5 py-1.5 text-xs', section.key === 'add' ? 'bg-emerald-50 dark:bg-emerald-950/25' : 'bg-red-50 dark:bg-red-950/25')"
                            >
                                <span class="font-semibold text-foreground">{{ permissionLabel(permission) }}</span>
                                <span class="font-mono text-[10px] text-muted-foreground">{{ permission.name }}</span>
                                <TriangleAlert v-if="isSensitivePermission(permission)" class="h-3 w-3 text-amber-500" aria-label="Sensible" />
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="reviewing = false">Continuer la modification</Button>
                <Button type="button" variant="primary" :disabled="baselineForm.processing || readonly" @click="requestSave">
                    <Check class="h-4 w-4" />Enregistrer les modifications
                </Button>
            </template>
        </Dialog>

        <!-- La seule confirmation d'un enregistrement ordinaire : ouvrir un
             droit sensible à tout un métier. -->
        <ConfirmModal
            :open="confirmingSensitive"
            tone="warning"
            :title="`Accorder ${plural(sensitiveAdditions.length, 'permission')} sensible${sensitiveAdditions.length > 1 ? 's' : ''} ?`"
            :description="sensitiveDescription"
            confirm-label="Enregistrer et accorder"
            :processing="baselineForm.processing"
            @update:open="confirmingSensitive = $event"
            @confirm="submit"
        >
            <ul class="max-h-64 space-y-1 overflow-y-auto">
                <li v-for="permission in sensitiveAdditions" :key="`sensitive-${permission.id}`" class="rounded-md bg-amber-50 px-2.5 py-1.5 text-xs dark:bg-amber-950/25">
                    <span class="font-semibold text-foreground">{{ permissionLabel(permission) }}</span>
                    <span class="ms-2 font-mono text-[10px] text-muted-foreground">{{ permission.name }}</span>
                </li>
            </ul>
            <p v-if="diff.total > sensitiveAdditions.length" class="mt-3 text-xs text-muted-foreground">
                {{ diff.total - sensitiveAdditions.length > 1 ? `Les ${diff.total - sensitiveAdditions.length} autres modifications partent` : 'L’autre modification part' }}
                avec {{ sensitiveAdditions.length > 1 ? 'elles' : 'elle' }}.
            </p>
        </ConfirmModal>

        <ConfirmModal
            :open="confirmingReset"
            tone="warning"
            title="Réinitialiser le socle du rôle ?"
            :description="`Le rôle « ${role.name} » retrouvera les permissions définies par défaut dans l’application.`"
            confirm-label="Restaurer le socle par défaut"
            :processing="resetForm.processing"
            @update:open="confirmingReset = $event"
            @confirm="resetToDefault"
        >
            <template #confirm-icon><RotateCcw class="h-4 w-4" /></template>
            <div class="space-y-3 text-sm leading-6 text-muted-foreground">
                <p>
                    Cette action touchera <strong class="text-foreground">{{ plural(role.users_count ?? 0, 'compte') }}</strong>
                    par leur rôle. Leurs autorisations et interdictions individuelles resteront inchangées et continueront de l’emporter sur le socle.
                </p>
                <div v-if="defaultDiff" class="grid gap-2 sm:grid-cols-3">
                    <div class="rounded-lg border border-border bg-muted/30 px-3 py-2">
                        <p class="text-[10px] font-bold uppercase tracking-wide">Socle actuel</p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums text-foreground">{{ baselineIds.size }}</p>
                    </div>
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50/50 px-3 py-2 dark:border-emerald-900 dark:bg-emerald-950/20">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">À ajouter</p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ defaultDiff.added.length }}</p>
                    </div>
                    <div class="rounded-lg border border-red-200 bg-red-50/50 px-3 py-2 dark:border-red-900 dark:bg-red-950/20">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-destructive">À retirer</p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums text-destructive">{{ defaultDiff.removed.length }}</p>
                    </div>
                </div>
                <p v-if="dirty" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />Les modifications non enregistrées visibles à l’écran seront abandonnées.
                </p>
            </div>
        </ConfirmModal>

        <ConfirmModal
            :open="archiving"
            tone="danger"
            :title="`Archiver « ${role.name} » ?`"
            description="Le rôle quitte les affectations possibles. Il n’est jamais supprimé : son socle est conservé et il reste restaurable."
            confirm-label="Archiver le rôle"
            :processing="archiveForm.processing"
            :disabled="archiveForm.reason.trim().length < 5"
            :dismissible="! archiveForm.processing"
            @update:open="archiving = $event"
            @confirm="submitArchive"
        >
            <template #confirm-icon><Archive class="h-4 w-4" /></template>
            <FormField label="Motif" :error="archiveForm.errors.reason" hint="(5 caractères au moins)">
                <Textarea v-model="archiveForm.reason" rows="3" placeholder="Métier repris par le rôle NURSE depuis le 01/09." />
            </FormField>
            <p class="mt-2 text-xs text-muted-foreground">
                C’est ce que lira la personne qui retrouvera ce rôle archivé dans six mois.
            </p>
        </ConfirmModal>
    </div>
</template>
