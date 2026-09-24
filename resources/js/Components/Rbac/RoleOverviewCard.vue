<script setup>
import { computed, nextTick, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    Check,
    ChevronRight,
    IdCard,
    Loader2,
    MoreHorizontal,
    Pencil,
    RotateCcw,
    ShieldCheck,
    TriangleAlert,
    Users,
    X,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import Input from '@/Components/Shadcn/Input.vue';
import FormError from '@/Components/UI/FormError.vue';
import { roleDescription, roleInitials } from '@/utilities/roleDescriptions';
import { cn } from '@/lib/cn';

/**
 * L'en-tête d'un rôle : qui il est, à qui il s'applique, ce que son socle
 * couvre — et les gestes qui touchent au rôle lui-même (ADR-178).
 *
 * Renommer se fait sur place : le libellé devient un champ, Entrée enregistre,
 * Échap annule. Le code ne change jamais — c'est l'identité que les socles et
 * l'audit désignent (ADR-100). Archiver et réinitialiser, eux, touchent tous
 * les comptes du rôle : ils passent par le menu, puis par une confirmation.
 */
const props = defineProps({
    siteCode: { type: String, required: true },
    role: { type: Object, required: true },
    /** Droits accordés dans le brouillon, sur le catalogue entier. */
    granted: { type: Number, default: 0 },
    catalogSize: { type: Number, default: 0 },
    sensitiveGranted: { type: Number, default: 0 },
    modulesCovered: { type: Number, default: 0 },
    modulesTotal: { type: Number, default: 0 },
    /** Écart entre le socle enregistré et le socle livré avec l'application. */
    defaultDiff: { type: Object, default: null },
    dirty: { type: Boolean, default: false },
    abilities: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['action', 'show-accounts']);

const description = computed(() => roleDescription(props.role.code)
    || 'Rôle créé depuis le portail : son périmètre est celui que son socle accorde ci-dessous.');

const ratio = computed(() => (props.catalogSize ? Math.round((props.granted / props.catalogSize) * 100) : 0));

/* ------------------------------------------------------------------ */
/* Renommer, sur place                                                 */
/* ------------------------------------------------------------------ */

const renaming = ref(false);
const renameInput = ref(null);
const renameForm = useForm({ name: '' });

const startRename = async () => {
    renameForm.clearErrors();
    renameForm.name = props.role.name;
    renaming.value = true;
    await nextTick();
    renameInput.value?.$el?.focus?.();
    renameInput.value?.$el?.select?.();
};

const cancelRename = () => {
    renaming.value = false;
    renameForm.clearErrors();
};

const submitRename = () => {
    const name = renameForm.name.trim();

    if (name === '' || name === props.role.name) {
        cancelRename();
        return;
    }

    renameForm.name = name;
    renameForm.put(`/super-admin/workspaces/roles/${props.siteCode}/${props.role.code}`, {
        preserveScroll: true,
        onSuccess: () => { renaming.value = false; },
    });
};

/* ------------------------------------------------------------------ */
/* Menu                                                                */
/* ------------------------------------------------------------------ */

const menuItems = computed(() => {
    const items = [];

    if (props.role.users_count > 0) {
        items.push({
            key: 'accounts',
            label: `Voir les ${props.role.users_count} compte${props.role.users_count > 1 ? 's' : ''} du rôle`,
            description: 'Et leurs exceptions individuelles',
            icon: Users,
        });
    }

    if (props.abilities.reset && props.role.has_default_baseline && ! props.role.archived) {
        const isDefault = props.defaultDiff?.total === 0;

        items.push({
            key: 'reset',
            label: 'Réinitialiser au socle par défaut',
            description: isDefault ? 'Ce rôle utilise déjà le socle livré avec l’application.' : 'Retrouver les permissions livrées avec l’application.',
            icon: RotateCcw,
            disabled: isDefault,
            separatorBefore: items.length > 0,
        });
    }

    if (props.abilities.archive && ! props.role.archived) {
        items.push({
            key: 'archive',
            label: 'Archiver le rôle…',
            description: props.role.users_count > 0
                ? `${props.role.users_count} compte${props.role.users_count > 1 ? 's portent' : ' porte'} encore ce rôle : réaffectez-les d’abord.`
                : 'Il quitte les affectations possibles, son socle est conservé.',
            icon: Archive,
            disabled: props.role.users_count > 0,
            destructive: true,
            separatorBefore: items.length > 0,
        });
    }

    return items;
});

const onMenu = (key) => {
    if (key === 'accounts') emit('show-accounts');
    else emit('action', key);
};
</script>

<template>
    <Card class="overflow-hidden">
        <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-start">
            <span
                :class="cn(
                    'grid h-12 w-12 shrink-0 place-items-center rounded-xl text-sm font-bold',
                    role.archived ? 'border border-dashed border-border text-muted-foreground' : 'bg-primary text-primary-foreground shadow-sm',
                )"
                aria-hidden="true"
            >{{ roleInitials(role.name) }}</span>

            <div class="min-w-0 flex-1">
                <form v-if="renaming" class="flex flex-wrap items-center gap-2" @submit.prevent="submitRename">
                    <Input
                        ref="renameInput"
                        v-model="renameForm.name"
                        class="h-9 max-w-sm text-base font-bold"
                        aria-label="Libellé du rôle"
                        autocomplete="off"
                        @keydown.esc.prevent="cancelRename"
                    />
                    <Button type="submit" size="sm" variant="primary" :disabled="renameForm.processing">
                        <Loader2 v-if="renameForm.processing" class="h-4 w-4 animate-spin" />
                        <Check v-else class="h-4 w-4" />Enregistrer
                    </Button>
                    <Button type="button" size="sm" variant="ghost" :disabled="renameForm.processing" @click="cancelRename">
                        <X class="h-4 w-4" />Annuler
                    </Button>
                    <p class="basis-full text-xs text-muted-foreground">Seul le libellé change : le code <code class="font-mono">{{ role.code }}</code> reste l’identité du rôle.</p>
                    <FormError v-if="renameForm.errors.name" class="basis-full">{{ renameForm.errors.name }}</FormError>
                </form>

                <div v-else class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <h2 class="font-heading text-xl font-bold tracking-tight text-foreground">{{ role.name }}</h2>
                    <code class="rounded-md border border-border bg-muted/60 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-muted-foreground">{{ role.code }}</code>
                    <Badge v-if="role.archived" variant="outline" class="px-2 py-0.5 text-[11px]"><Archive class="h-3 w-3" />Archivé</Badge>
                    <Badge v-if="dirty" variant="warning" class="px-2 py-0.5 text-[11px]">Non enregistré</Badge>
                    <Button
                        v-if="abilities.rename && ! role.archived"
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        :aria-label="`Renommer « ${role.name} »`"
                        title="Renommer"
                        @click="startRename"
                    >
                        <Pencil class="h-3.5 w-3.5" />
                    </Button>
                </div>

                <p class="mt-1 text-sm text-muted-foreground">{{ description }}</p>

                <div class="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                    <button
                        v-if="role.users_count > 0"
                        type="button"
                        class="inline-flex items-center gap-1.5 font-semibold text-foreground hover:text-primary hover:underline"
                        @click="emit('show-accounts')"
                    >
                        <Users class="h-3.5 w-3.5 text-muted-foreground" />
                        {{ role.users_count }} compte{{ role.users_count > 1 ? 's' : '' }}
                    </button>
                    <span v-else class="inline-flex items-center gap-1.5"><Users class="h-3.5 w-3.5" />Aucun compte</span>
                    <span v-if="role.profiles?.length" class="inline-flex min-w-0 items-center gap-1.5" :title="role.profiles.map((profile) => profile.name).join(' · ')">
                        <IdCard class="h-3.5 w-3.5 shrink-0" />
                        <span class="truncate">{{ role.profiles.map((profile) => profile.name).join(' · ') }}</span>
                    </span>
                    <span v-if="role.has_default_baseline && defaultDiff" class="inline-flex items-center gap-1.5">
                        <ShieldCheck class="h-3.5 w-3.5" />
                        <template v-if="defaultDiff.total === 0">Socle livré avec l’application</template>
                        <template v-else>Socle personnalisé · +{{ defaultDiff.added.length }} / −{{ defaultDiff.removed.length }} par rapport au défaut</template>
                    </span>
                </div>

                <p v-if="role.archived && role.archive_reason" class="mt-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                    <strong class="text-foreground">Motif d’archivage :</strong> {{ role.archive_reason }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <Button
                    v-if="role.archived && abilities.restore"
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="emit('action', 'restore')"
                >
                    <ArchiveRestore class="h-4 w-4" />Restaurer le rôle
                </Button>
                <DropdownMenu v-if="menuItems.length" :items="menuItems" label="Actions sur le rôle" @select="onMenu">
                    <template #trigger>
                        <Button type="button" variant="outline" size="sm" :aria-label="`Actions sur le rôle « ${role.name} »`">
                            <MoreHorizontal class="h-4 w-4" /><span class="hidden sm:inline">Actions</span>
                        </Button>
                    </template>
                </DropdownMenu>
            </div>
        </div>

        <div class="grid gap-4 border-t border-border bg-muted/30 px-5 py-3.5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
            <div class="min-w-0">
                <div class="flex items-baseline justify-between gap-3">
                    <p class="text-sm text-muted-foreground">
                        <strong class="font-heading text-lg tabular-nums text-foreground">{{ granted }}</strong>
                        <span class="tabular-nums"> / {{ catalogSize }}</span>
                        permission{{ granted > 1 ? 's' : '' }} accordée{{ granted > 1 ? 's' : '' }}
                    </p>
                    <span class="text-xs font-semibold tabular-nums text-muted-foreground">{{ ratio }} %</span>
                </div>
                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                    <div class="h-full rounded-full bg-primary transition-[width] duration-300" :style="{ width: `${ratio}%` }" />
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 font-semibold text-foreground">
                    <span class="tabular-nums">{{ modulesCovered }}</span>
                    <span class="font-normal text-muted-foreground">module{{ modulesCovered > 1 ? 's' : '' }} sur {{ modulesTotal }}</span>
                </span>
                <span
                    :class="cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-semibold',
                        sensitiveGranted ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200' : 'border-border bg-card text-muted-foreground',
                    )"
                    title="Suppression, restauration, gestion des comptes et des droits…"
                >
                    <TriangleAlert class="h-3.5 w-3.5" />
                    <span class="tabular-nums">{{ sensitiveGranted }}</span>
                    <span class="font-normal">sensible{{ sensitiveGranted > 1 ? 's' : '' }}</span>
                </span>
            </div>
        </div>

        <!-- ADR-150 — ce socle n'est pas la seule source des droits de ces
             comptes : une exception individuelle l'emporte (ADR-033). Sans ce
             rappel, un socle à zéro se lit « personne n'y a accès ». -->
        <div
            v-if="role.users_with_exceptions_count"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-amber-200 bg-amber-50/60 px-5 py-2.5 text-xs leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100"
        >
            <TriangleAlert class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-300" />
            <span class="min-w-0 flex-1">
                <strong>{{ role.users_with_exceptions_count }} compte{{ role.users_with_exceptions_count > 1 ? 's' : '' }}</strong>
                de ce rôle {{ role.users_with_exceptions_count > 1 ? 'portent' : 'porte' }} des exceptions individuelles, qui l’emportent sur ce socle :
                un droit décoché ici peut rester ouvert pour {{ role.users_with_exceptions_count > 1 ? 'eux' : 'lui' }}, un droit coché peut lui rester fermé.
            </span>
            <button type="button" class="inline-flex items-center gap-1 font-semibold text-amber-900 hover:underline dark:text-amber-100" @click="emit('show-accounts')">
                Exceptions par compte<ChevronRight class="h-3.5 w-3.5" />
            </button>
        </div>

        <p v-if="readonly && ! role.archived" class="border-t border-border bg-muted/40 px-5 py-2.5 text-xs text-muted-foreground">
            Consultation seule : modifier un socle demande le droit <code class="font-mono">users.manage</code>.
        </p>
    </Card>
</template>
