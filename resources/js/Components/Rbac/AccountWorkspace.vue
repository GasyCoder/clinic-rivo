<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Check, ChevronRight, IdCard, Mail, RotateCcw, Search, ShieldCheck, ShieldPlus, ShieldX, SlidersHorizontal, TriangleAlert, X } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import PermissionMatrix from '@/Components/Rbac/PermissionMatrix.vue';
import PermissionModuleCard from '@/Components/Rbac/PermissionModuleCard.vue';
import PermissionSaveBar from '@/Components/Rbac/PermissionSaveBar.vue';
import PermissionToolbar from '@/Components/Rbac/PermissionToolbar.vue';
import { keepHeaderInPlace, nextModuleState } from '@/composables/useExclusiveModules';
import {
    isSensitivePermission,
    matchesSearchTerms,
    permissionLabel,
    permissionSearchIndex,
} from '@/utilities/permissionWorkspace';
import { roleInitials } from '@/utilities/roleDescriptions';
import { cn } from '@/lib/cn';

/**
 * Les exceptions d'**un compte**, par-dessus le socle de son rôle (ADR-100,
 * ADR-178).
 *
 * L'ordre de résolution reste celui de l'ADR-022/033, et cet écran ne le
 * change pas :
 *
 *     DENY individuel  >  ALLOW individuel  >  socle du rôle
 *
 * La grille est celle du socle : mêmes modules, mêmes colonnes. Chaque case
 * y montre le résultat pour ce compte — vert quand il a l'accès, rouge quand
 * une interdiction le lui retire — et s'ouvre sur ses trois réglages.
 *
 * Les lignes venues d'un profil métier (`source = PROFILE`) sont affichées
 * avec leur provenance mais restent modifiables : une décision reprise ici
 * devient une décision MANUAL, jamais réécrite ensuite par une application de
 * recommandations (ADR-033).
 */
const props = defineProps({
    siteCode: { type: String, required: true },
    siteName: { type: String, default: '' },
    user: { type: Object, required: true },
    /** Le rôle du compte, tel que le site le décrit — son socle est la référence. */
    role: { type: Object, default: null },
    modules: { type: Array, default: () => [] },
    catalog: { type: Array, default: () => [] },
    canAssign: { type: Boolean, default: false },
    search: { type: String, default: '' },
    filter: { type: String, default: 'all' },
    expanded: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:dirty', 'update:pending', 'update:search', 'update:filter', 'update:expanded', 'show-role']);

const readonly = computed(() => ! props.canAssign);

/** Ce que le socle du rôle accorde déjà : l'exception ne sert qu'à s'en écarter. */
const rolePermissionNames = computed(() => new Set(props.role?.permissions ?? []));

/** Les exceptions telles qu'elles sont enregistrées sur le site. */
const savedEffects = computed(() => Object.fromEntries(
    (props.user.permission_overrides ?? []).map((override) => [override.permission_id, override.effect]),
));

const provenance = computed(() => Object.fromEntries(
    (props.user.permission_overrides ?? []).map((override) => [
        override.permission_id,
        { source: override.source, profile: override.source_profile_name },
    ]),
));

/** `{ [permissionId]: 'allow' | 'deny' }` — l'absence vaut « selon le rôle ». */
const draft = ref({ ...savedEffects.value });

const signature = () => [...(props.user.permission_overrides ?? [])]
    .map((override) => `${override.permission_id}:${override.effect}`)
    .sort()
    .join(',');

const loadedSignature = ref(signature());

/**
 * Le brouillon ne repart que si la réponse du site a réellement changé : un
 * enregistrement refusé réaffiche la même page, et recharger là jetterait la
 * saisie précisément quand elle vient d'échouer.
 */
watch(() => props.user.permission_overrides, () => {
    const current = signature();

    if (current === loadedSignature.value) return;

    draft.value = { ...savedEffects.value };
    loadedSignature.value = current;
});

const effectOf = (permission) => draft.value[permission.id] ?? '';
const roleGrants = (permission) => rolePermissionNames.value.has(permission.name);

/** Un DENY individuel l'emporte toujours, y compris sur le socle du rôle. */
const effectivelyGranted = (permission) => {
    const effect = effectOf(permission);

    if (effect === 'deny') return false;
    if (effect === 'allow') return true;

    return roleGrants(permission);
};

const changed = computed(() => props.catalog.filter(
    (permission) => (draft.value[permission.id] ?? '') !== (savedEffects.value[permission.id] ?? ''),
));
const changedIds = computed(() => new Set(changed.value.map((permission) => permission.id)));
const dirty = computed(() => changed.value.length > 0);

watch(dirty, (value) => emit('update:dirty', value), { immediate: true });
watch(() => changed.value.length, (count) => emit('update:pending', count), { immediate: true });

const setEffect = (permission, effect) => {
    if (readonly.value) return;

    const next = { ...draft.value };
    if (effect === '') delete next[permission.id]; else next[permission.id] = effect;
    draft.value = next;
};

/** Les actions groupées portent sur ce qui est **affiché**, jamais sur ce qu'on ne voit pas. */
const setEffectMany = (permissions, effect) => {
    if (readonly.value) return;

    const next = { ...draft.value };
    for (const permission of permissions) {
        if (effect === '') delete next[permission.id]; else next[permission.id] = effect;
    }
    draft.value = next;
};

const discard = () => { draft.value = { ...savedEffects.value }; };

const sourceLabel = (permission) => {
    const origin = provenance.value[permission.id];

    if (! origin || draft.value[permission.id] !== savedEffects.value[permission.id]) return '';

    return origin.source === 'PROFILE'
        ? `Profil ${origin.profile ?? 'métier'}`
        : 'Décision individuelle';
};

/* ------------------------------------------------------------------ */
/* Recherche, filtres, visibilité                                      */
/* ------------------------------------------------------------------ */

const searchIndex = computed(() => permissionSearchIndex(props.modules));
const matchesSearch = (permission) => matchesSearchTerms(searchIndex.value.get(permission.id) ?? permission.name, props.search);

const matchesFilter = (permission) => {
    if (props.filter === 'exceptions') return effectOf(permission) !== '';
    if (props.filter === 'granted') return effectivelyGranted(permission);
    if (props.filter === 'denied') return ! effectivelyGranted(permission);
    if (props.filter === 'sensitive') return isSensitivePermission(permission);
    if (props.filter === 'changed') return changedIds.value.has(permission.id);

    return true;
};

const filtering = computed(() => props.search.trim() !== '' || props.filter !== 'all');

const visibleIds = computed(() => new Set(props.catalog
    .filter((permission) => matchesSearch(permission) && matchesFilter(permission))
    .map((permission) => permission.id)));

const filterOptions = computed(() => {
    const searched = props.catalog.filter(matchesSearch);

    return [
        { value: 'all', label: 'Toutes', count: searched.length },
        { value: 'exceptions', label: 'Exceptions', count: searched.filter((permission) => effectOf(permission) !== '').length, tone: 'primary' },
        { value: 'granted', label: 'Accès effectifs', count: searched.filter(effectivelyGranted).length, tone: 'success' },
        { value: 'denied', label: 'Sans accès', count: searched.filter((permission) => ! effectivelyGranted(permission)).length, tone: 'muted' },
        { value: 'sensitive', label: 'Sensibles', count: searched.filter(isSensitivePermission).length, tone: 'warning' },
        { value: 'changed', label: 'Modifiées', count: searched.filter((permission) => changedIds.value.has(permission.id)).length, tone: 'warning' },
    ];
});

const describe = (permission) => ({
    active: effectivelyGranted(permission),
    effect: effectOf(permission),
    roleGranted: roleGrants(permission),
    changed: changedIds.value.has(permission.id),
    sensitive: isSensitivePermission(permission),
    dimmed: filtering.value && ! visibleIds.value.has(permission.id),
    sourceLabel: sourceLabel(permission),
});

const moduleStats = computed(() => Object.fromEntries(props.modules.map((module) => {
    const shown = filtering.value
        ? module.permissions.filter((permission) => visibleIds.value.has(permission.id))
        : module.permissions;

    return [module.key, {
        shown,
        total: shown.length,
        active: shown.filter(effectivelyGranted).length,
        exceptions: module.permissions.filter((permission) => effectOf(permission) !== '').length,
        changed: module.permissions.filter((permission) => changedIds.value.has(permission.id)).length,
    }];
})));

const shownModules = computed(() => props.modules.filter((module) => moduleStats.value[module.key].total > 0));
const shownCount = computed(() => (filtering.value ? visibleIds.value.size : null));

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

const moduleMenu = (module) => {
    const count = moduleStats.value[module.key].total;
    const suffix = filtering.value ? ` (${count} affichée${count > 1 ? 's' : ''})` : '';

    return [
        { key: '', label: `Suivre le rôle partout${suffix}`, description: 'Retire les exceptions : le socle décide.', icon: ShieldCheck },
        { key: 'allow', label: `Toujours autoriser partout${suffix}`, description: 'Accès même si le rôle ne l’accorde pas.', icon: ShieldPlus },
        { key: 'deny', label: `Toujours interdire partout${suffix}`, description: 'Verrouillé, même si le rôle l’accorde.', icon: ShieldX, destructive: true },
    ];
};

/* ------------------------------------------------------------------ */
/* Résumé                                                              */
/* ------------------------------------------------------------------ */

const summary = computed(() => {
    const allowed = props.catalog.filter((permission) => effectOf(permission) === 'allow').length;
    const denied = props.catalog.filter((permission) => effectOf(permission) === 'deny').length;

    return { allowed, denied, effective: props.catalog.filter(effectivelyGranted).length };
});

const resetStats = computed(() => {
    const overrides = props.user.permission_overrides ?? [];

    return {
        total: overrides.length,
        manual: overrides.filter((override) => override.source !== 'PROFILE').length,
        profile: overrides.filter((override) => override.source === 'PROFILE').length,
    };
});

/* ------------------------------------------------------------------ */
/* Enregistrer, réinitialiser                                          */
/* ------------------------------------------------------------------ */

const overridesForm = useForm({ permission_overrides: [] });
const resetForm = useForm({});
const savedAt = ref(null);
const reviewing = ref(false);
const confirmingSensitive = ref(false);
const confirmingReset = ref(false);

/**
 * Ouvrir un droit sensible à ce compte mérite un deuxième regard ; interdire
 * ou revenir au rôle, non — ils ne peuvent que restreindre.
 */
const sensitiveOpenings = computed(() => changed.value.filter(
    (permission) => effectOf(permission) === 'allow' && isSensitivePermission(permission),
));

const requestSave = () => {
    if (! dirty.value || readonly.value || overridesForm.processing) return;

    reviewing.value = false;

    if (sensitiveOpenings.value.length) {
        confirmingSensitive.value = true;

        return;
    }

    submit();
};

const submit = () => {
    confirmingSensitive.value = false;
    overridesForm.permission_overrides = Object.entries(draft.value).map(([permissionId, effect]) => ({
        permission_id: Number(permissionId),
        effect,
    }));
    overridesForm.put(`/super-admin/workspaces/roles/${props.siteCode}/accounts/${props.user.uuid}/permissions`, {
        preserveScroll: true,
        onSuccess: () => { savedAt.value = Date.now(); },
    });
};

const resetToRole = () => resetForm.post(
    `/super-admin/workspaces/roles/${props.siteCode}/accounts/${props.user.uuid}/permissions/reset`,
    {
        preserveScroll: true,
        onSuccess: () => {
            confirmingReset.value = false;
            savedAt.value = Date.now();
        },
    },
);

const saveErrors = computed(() => [...new Set([
    ...Object.values(overridesForm.errors),
    ...Object.values(resetForm.errors),
].filter(Boolean))]);

const describeChange = (permission) => {
    const words = { '': 'Suit le rôle', allow: 'Toujours autorisé', deny: 'Toujours interdit' };

    return `${words[savedEffects.value[permission.id] ?? '']} → ${words[draft.value[permission.id] ?? '']}`;
};

const plural = (count, word) => `${count} ${word}${count > 1 ? 's' : ''}`;
</script>

<template>
    <div class="space-y-4">
        <Card class="overflow-hidden">
            <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-start">
                <span
                    :class="cn('grid h-12 w-12 shrink-0 place-items-center rounded-full text-sm font-bold', user.active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')"
                    aria-hidden="true"
                >{{ roleInitials(user.name) }}</span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <h2 class="font-heading text-xl font-bold tracking-tight text-foreground">{{ user.name }}</h2>
                        <Badge v-if="! user.active" variant="outline" class="px-2 py-0.5 text-[11px]">Désactivé</Badge>
                        <Badge v-if="dirty" variant="warning" class="px-2 py-0.5 text-[11px]">Non enregistré</Badge>
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                        <span class="inline-flex min-w-0 items-center gap-1.5"><Mail class="h-3.5 w-3.5 shrink-0" /><span class="truncate">{{ user.email }}</span></span>
                        <button
                            v-if="role"
                            type="button"
                            class="inline-flex items-center gap-1.5 font-semibold text-foreground hover:text-primary hover:underline"
                            :title="`Ouvrir le socle du rôle « ${role.name} »`"
                            @click="emit('show-role', role.code)"
                        >
                            <ShieldCheck class="h-3.5 w-3.5 text-muted-foreground" />Rôle {{ role.name }}<ChevronRight class="h-3 w-3" />
                        </button>
                        <span v-else class="inline-flex items-center gap-1.5"><ShieldCheck class="h-3.5 w-3.5" />Sans rôle</span>
                        <span v-if="user.professional_profile" class="inline-flex items-center gap-1.5"><IdCard class="h-3.5 w-3.5" />Profil {{ user.professional_profile.name }}</span>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <!-- Toujours affiché (ADR-158) : un bouton absent se lit « la fonction n'existe pas ». -->
                    <Button
                        v-if="canAssign"
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="overridesForm.processing || resetForm.processing || resetStats.total === 0"
                        :title="resetStats.total === 0
                            ? 'Rien à réinitialiser : ce compte ne porte aucune exception, il suit déjà son rôle.'
                            : `Retirer les ${resetStats.total} exception${resetStats.total > 1 ? 's' : ''} : le compte suivra de nouveau son rôle partout.`"
                        @click="confirmingReset = true"
                    >
                        <RotateCcw class="h-4 w-4" />Réinitialiser le compte
                        <span v-if="resetStats.total" class="font-normal tabular-nums">({{ resetStats.total }})</span>
                    </Button>
                </div>
            </div>

            <div class="grid gap-2 border-t border-border bg-muted/30 px-5 py-3.5 sm:grid-cols-3">
                <div class="flex items-center gap-3 rounded-lg border border-emerald-200 bg-card px-3 py-2 dark:border-emerald-900">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><Check class="h-4 w-4" /></span>
                    <span>
                        <span class="block text-lg font-bold leading-6 tabular-nums text-foreground">{{ summary.effective }}<span class="text-xs font-normal text-muted-foreground"> / {{ catalog.length }}</span></span>
                        <span class="block text-[11px] text-muted-foreground">accès effectifs</span>
                    </span>
                </div>
                <div class="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-emerald-600 text-white"><ShieldPlus class="h-4 w-4" /></span>
                    <span>
                        <span class="block text-lg font-bold leading-6 tabular-nums text-foreground">{{ summary.allowed }}</span>
                        <span class="block text-[11px] text-muted-foreground">toujours autorisé{{ summary.allowed > 1 ? 's' : '' }} en plus</span>
                    </span>
                </div>
                <div class="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-destructive text-destructive-foreground"><ShieldX class="h-4 w-4" /></span>
                    <span>
                        <span class="block text-lg font-bold leading-6 tabular-nums text-foreground">{{ summary.denied }}</span>
                        <span class="block text-[11px] text-muted-foreground">toujours interdit{{ summary.denied > 1 ? 's' : '' }} malgré le rôle</span>
                    </span>
                </div>
            </div>

            <p v-if="! canAssign" class="border-t border-amber-200 bg-amber-50 px-5 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                Consultation seule : enregistrer une exception demande le droit <code class="font-mono">permissions.assign</code>.
            </p>
        </Card>

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

            <!-- Ce que dit une case, une fois pour toutes. -->
            <p class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] text-muted-foreground" aria-hidden="true">
                <span class="inline-flex items-center gap-1.5"><span class="grid h-4 w-4 place-items-center rounded border border-emerald-300 bg-emerald-50 text-emerald-600 dark:border-emerald-800 dark:bg-emerald-950/40"><Check class="h-3 w-3" :stroke-width="3" /></span>Accordé par le rôle</span>
                <span class="inline-flex items-center gap-1.5"><span class="grid h-4 w-4 place-items-center rounded border border-emerald-600 bg-emerald-600 text-white"><Check class="h-3 w-3" :stroke-width="3" /></span>Toujours autorisé (exception)</span>
                <span class="inline-flex items-center gap-1.5"><span class="grid h-4 w-4 place-items-center rounded border border-destructive bg-destructive text-destructive-foreground"><X class="h-3 w-3" :stroke-width="3" /></span>Toujours interdit (exception)</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-4 w-4 rounded border border-input bg-card" />Pas d’accès</span>
                <span class="inline-flex items-center gap-1.5"><SlidersHorizontal class="h-3.5 w-3.5" />Cliquez une case pour la régler</span>
            </p>

            <div v-if="! shownModules.length" class="rounded-xl border border-dashed border-border bg-card px-6 py-12 text-center">
                <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><Search class="h-5 w-5" /></span>
                <p class="mt-3 text-sm font-semibold text-foreground">Aucune permission ne correspond</p>
                <p class="mt-1 text-xs text-muted-foreground">Changez la recherche ou le filtre.</p>
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
                active-label="avec accès"
                @toggle="toggleModule(module)"
            >
                <template #actions>
                    <Badge v-if="moduleStats[module.key].exceptions" variant="outline" class="hidden px-2 py-0.5 text-[11px] md:inline-flex">
                        {{ plural(moduleStats[module.key].exceptions, 'exception') }}
                    </Badge>
                    <DropdownMenu
                        v-if="! readonly"
                        :items="moduleMenu(module)"
                        :label="`Appliquer à « ${module.label} »`"
                        @select="setEffectMany(moduleStats[module.key].shown, $event)"
                    >
                        <template #trigger>
                            <Button type="button" variant="outline" size="xs" class="h-8" :aria-label="`Appliquer une exception à tout le module « ${module.label} »`">
                                <SlidersHorizontal class="h-3.5 w-3.5" /><span class="hidden lg:inline">Tout le module</span>
                            </Button>
                        </template>
                    </DropdownMenu>
                </template>

                <PermissionMatrix
                    :groups="module.groups"
                    mode="overrides"
                    :describe="describe"
                    :readonly="readonly"
                    @change="setEffect($event.permission, $event.effect)"
                />
            </PermissionModuleCard>

            <PermissionSaveBar
                :dirty="dirty"
                :count="changed.length"
                :processing="overridesForm.processing || resetForm.processing"
                :can-save="canAssign"
                :saved-at="savedAt"
                :scope="`Ne concerne que ${user.name} — le socle du rôle reste inchangé.`"
                :clean-text="`Le socle du rôle « ${role?.name ?? '—'} » s’applique par-dessous ; seules les exceptions de ce compte s’en écartent.`"
                :errors="saveErrors"
                save-label="Enregistrer les exceptions"
                @save="requestSave"
                @discard="discard"
                @review="reviewing = true"
            />
        </div>

        <Dialog
            :open="reviewing"
            size="lg"
            :title="`Modifications préparées · ${user.name}`"
            description="Rien n’est appliqué tant que vous n’avez pas enregistré."
            body-class="max-h-[60vh] overflow-y-auto"
            @update:open="reviewing = $event"
        >
            <ul class="space-y-1.5">
                <li
                    v-for="permission in changed"
                    :key="permission.id"
                    class="flex items-start justify-between gap-3 rounded-lg border border-border px-3 py-2"
                >
                    <span class="min-w-0">
                        <span class="block text-xs font-semibold text-foreground">{{ permissionLabel(permission) }}</span>
                        <span class="mt-0.5 block truncate font-mono text-[10px] text-muted-foreground">{{ permission.name }}</span>
                    </span>
                    <span class="shrink-0 text-[11px] font-bold text-muted-foreground">{{ describeChange(permission) }}</span>
                </li>
            </ul>
            <template #footer>
                <Button type="button" variant="outline" @click="reviewing = false">Continuer la modification</Button>
                <Button type="button" variant="primary" :disabled="overridesForm.processing || ! canAssign" @click="requestSave">
                    <Check class="h-4 w-4" />Enregistrer les exceptions
                </Button>
            </template>
        </Dialog>

        <ConfirmModal
            :open="confirmingSensitive"
            tone="warning"
            :title="sensitiveOpenings.length > 1 ? `Autoriser ces ${sensitiveOpenings.length} permissions sensibles ?` : 'Autoriser cette permission sensible ?'"
            :description="`${sensitiveOpenings.length > 1 ? 'Elles donnent' : 'Elle donne'} à ${user.name} un pouvoir de suppression, de validation, d’attribution ou de gestion. Rien n’est appliqué avant cette confirmation.`"
            confirm-label="Enregistrer et autoriser"
            :processing="overridesForm.processing"
            @update:open="confirmingSensitive = $event"
            @confirm="submit"
        >
            <ul class="max-h-64 space-y-1 overflow-y-auto">
                <li v-for="permission in sensitiveOpenings" :key="`sensitive-${permission.id}`" class="rounded-md bg-amber-50 px-2.5 py-1.5 text-xs dark:bg-amber-950/25">
                    <span class="font-semibold text-foreground">{{ permissionLabel(permission) }}</span>
                    <span class="ms-2 font-mono text-[10px] text-muted-foreground">{{ permission.name }}</span>
                </li>
            </ul>
        </ConfirmModal>

        <ConfirmModal
            :open="confirmingReset"
            tone="danger"
            title="Réinitialiser les permissions du compte ?"
            :description="`${user.name} héritera uniquement du socle de son rôle.`"
            confirm-label="Revenir au rôle uniquement"
            :processing="resetForm.processing"
            @update:open="confirmingReset = $event"
            @confirm="resetToRole"
        >
            <template #confirm-icon><RotateCcw class="h-4 w-4" /></template>
            <div class="space-y-3 text-sm leading-6 text-muted-foreground">
                <p>
                    Les <strong class="text-foreground">{{ plural(resetStats.total, 'exception') }} individuelle{{ resetStats.total > 1 ? 's' : '' }}</strong>
                    seront retirées : {{ plural(resetStats.manual, 'décision') }} manuelle{{ resetStats.manual > 1 ? 's' : '' }}
                    et {{ plural(resetStats.profile, 'recommandation') }} de profil déjà appliquée{{ resetStats.profile > 1 ? 's' : '' }}.
                </p>
                <p class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs leading-5">
                    Le profil professionnel reste affecté au compte, mais ses recommandations ne seront pas réappliquées automatiquement. Le compte recevra seulement les droits du rôle « {{ role?.name ?? '—' }} ».
                </p>
                <p v-if="dirty" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />Les modifications non enregistrées visibles à l’écran seront abandonnées.
                </p>
            </div>
        </ConfirmModal>
    </div>
</template>
