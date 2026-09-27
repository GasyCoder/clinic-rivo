<script setup>
import { computed, ref } from 'vue';
import { Info, TriangleAlert } from 'lucide-vue-next';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import PermissionEffectCell from '@/Components/Rbac/PermissionEffectCell.vue';
import PermissionToggle from '@/Components/Rbac/PermissionToggle.vue';
import { PERMISSION_ACTION_COLUMNS, permissionActionLabel, permissionLabel } from '@/utilities/permissionWorkspace';
import { cn } from '@/lib/cn';

/**
 * La grille d'un module : une ligne par fonctionnalité, une colonne par action
 * (ADR-178).
 *
 *     Fonctionnalité        Voir  Créer  Modifier  Supprimer  Restaurer  Valider  Exporter
 *     Dossiers patients      ■      ■       ■          □          □         ·         ■
 *       + Voir les patients supprimés  + Supprimer définitivement un patient
 *
 * Les colonnes sont les mêmes partout : on lit « Supprimer » de haut en bas
 * pour savoir ce qu'un rôle peut retirer. Chaque fonctionnalité porte son
 * icône — Dossier médical, Diagnostics, Ordonnances se reconnaissent avant
 * d'être lus ; une sous-fonctionnalité est décalée, avec une icône plus
 * petite. Ce qui n'a pas de colonne garde son
 * libellé complet, sous la ligne. Sous 38 rem de large, la grille devient des
 * pastilles qui portent leur verbe — une tablette en portrait la lit sans
 * défilement horizontal (requête de conteneur, pas de fenêtre).
 *
 * La grille ne décide de rien : `describe(permission)` lui dit l'état de
 * chaque case, et elle remonte les gestes. Le même composant sert le socle
 * d'un rôle (cases à cocher) et les exceptions d'un compte (trois états).
 */
const props = defineProps({
    groups: { type: Array, default: () => [] },
    /** `baseline` (socle d'un rôle) ou `overrides` (exceptions d'un compte). */
    mode: { type: String, default: 'baseline' },
    /**
     * `(permission) => { active, dimmed, changed, sensitive, … }` —
     * baseline : `granted`, `denyCount` ; overrides : `effect`, `roleGranted`, `sourceLabel`.
     */
    describe: { type: Function, required: true },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'set-many', 'change']);

const columns = PERMISSION_ACTION_COLUMNS;

const visible = (permission) => ! props.describe(permission).dimmed;

const shownRows = (group) => [group.base, ...group.children]
    .filter(Boolean)
    .filter((row) => row.permissions.some(visible));

const shownGroups = computed(() => props.groups
    .map((group) => ({ ...group, rows: shownRows(group) }))
    .filter((group) => group.rows.length > 0));

const allRows = computed(() => shownGroups.value.flatMap((group) => group.rows));

/**
 * Une légende de colonne n'existe que si la colonne sert : un module sans
 * aucune restauration n'affiche pas une colonne « Restaurer » de tirets.
 * Les colonnes restent néanmoins toutes à leur place, pour que « Voir » soit
 * au même endroit dans tous les modules.
 */
const columnUsed = (column) => allRows.value.some((row) => row.cells[column.key]);

/** Une case à trois états : tout accordé, rien, ou une partie seulement. */
const selectionState = (permissions) => {
    const shown = permissions.filter(visible);
    const active = shown.filter((permission) => props.describe(permission).active).length;

    if (active === 0) return false;

    return active === shown.length ? true : 'indeterminate';
};

const columnPermissions = (column) => allRows.value
    .map((row) => row.cells[column.key])
    .filter((permission) => permission && visible(permission));

const toggleColumn = (column) => {
    const permissions = columnPermissions(column);

    if (! permissions.length || props.readonly) return;

    emit('set-many', { permissions, value: selectionState(permissions) !== true });
};

const columnTitle = (column) => (selectionState(columnPermissions(column)) === true
    ? `Retirer « ${column.label} » à toutes les fonctionnalités affichées de ce module`
    : `Accorder « ${column.label} » à toutes les fonctionnalités affichées de ce module`);

const setRow = (row, value) => {
    if (props.readonly) return;

    emit('set-many', { permissions: row.permissions.filter(visible), value });
};

const rowActive = (row) => row.permissions.filter((permission) => props.describe(permission).active).length;

const hasColumns = (row) => columns.some((column) => row.cells[column.key]);

/** Le détail d'une ligne : libellés complets et codes, à la demande. */
const openDetails = ref(new Set());

const toggleDetails = (row) => {
    const next = new Set(openDetails.value);

    if (next.has(row.key)) next.delete(row.key); else next.add(row.key);
    openDetails.value = next;
};

const deniedInRow = (row) => props.mode === 'baseline'
    && row.permissions.some((permission) => props.describe(permission).denyCount > 0);

const onChange = (permission, effect) => emit('change', { permission, effect });

/** L'état d'une case, sans `active` : ce drapeau sert aux compteurs, pas à la case. */
const cellProps = (permission) => {
    const { active: _active, ...rest } = props.describe(permission);

    return rest;
};
</script>

<template>
    <div class="pm" :class="`pm--${mode}`">
        <div class="pm-head border-b border-border bg-muted/40 px-4 py-2">
            <span class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground" aria-hidden="true">Fonctionnalité</span>
            <template v-for="column in columns" :key="column.key">
                <button
                    v-if="mode === 'baseline' && ! readonly && columnUsed(column)"
                    type="button"
                    :class="cn(
                        'mx-auto rounded-md px-1 py-0.5 text-center text-[11px] font-bold leading-4 tracking-tight transition-colors hover:bg-primary/10 hover:text-primary',
                        selectionState(columnPermissions(column)) === true ? 'text-primary' : 'text-muted-foreground',
                    )"
                    :title="columnTitle(column)"
                    :aria-label="columnTitle(column)"
                    @click="toggleColumn(column)"
                >{{ column.label }}</button>
                <span
                    v-else
                    :class="cn('text-center text-[11px] font-bold leading-4 tracking-tight', columnUsed(column) ? 'text-muted-foreground' : 'text-muted-foreground/40')"
                    :title="column.title"
                    aria-hidden="true"
                >{{ column.label }}</span>
            </template>
        </div>

        <template v-for="group in shownGroups" :key="group.category">
            <p
                v-if="! group.base"
                class="flex items-center gap-2 border-t border-border bg-muted/25 px-4 pb-1.5 pt-2.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground first:border-t-0"
            >
                <component :is="group.icon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ group.label }}
            </p>

            <div
                v-for="row in group.rows"
                :key="row.key"
                :class="cn('pm-row border-t border-border px-4 py-2.5 transition-colors hover:bg-muted/30', row.nested ? 'pm-row--nested bg-muted/10' : '')"
            >
                <div :class="cn('pm-label flex min-w-0 items-center gap-2.5', row.nested ? 'ps-4' : '')">
                    <Checkbox
                        v-if="mode === 'baseline'"
                        :model-value="selectionState(row.permissions)"
                        :disabled="readonly"
                        :aria-label="`Toutes les actions : ${row.label}`"
                        @update:model-value="setRow(row, $event)"
                    />
                    <span
                        :class="cn(
                            'grid shrink-0 place-items-center transition-colors',
                            row.nested ? 'h-7 w-7 rounded-md' : 'h-8 w-8 rounded-lg',
                            rowActive(row) > 0
                                ? (row.nested ? 'bg-primary/5 text-primary/80' : 'bg-primary/10 text-primary')
                                : 'bg-muted text-muted-foreground',
                        )"
                        aria-hidden="true"
                    >
                        <component :is="row.icon" :class="row.nested ? 'h-3.5 w-3.5' : 'h-4 w-4'" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <span :class="cn('text-sm leading-5', row.nested ? 'font-medium text-foreground/90' : 'font-semibold text-foreground')">{{ row.label }}</span>
                            <span class="text-[11px] font-semibold tabular-nums text-muted-foreground">{{ rowActive(row) }}/{{ row.permissions.length }}</span>
                            <TriangleAlert
                                v-if="deniedInRow(row)"
                                class="h-3.5 w-3.5 shrink-0 self-center text-destructive"
                                aria-label="Un droit de cette ligne est refusé individuellement à des comptes du rôle"
                            />
                        </span>
                    </span>
                    <button
                        type="button"
                        :class="cn('-my-0.5 me-1 grid h-6 w-6 shrink-0 place-items-center rounded-md text-muted-foreground/70 transition-colors hover:bg-accent hover:text-foreground', openDetails.has(row.key) ? 'bg-primary/10 text-primary' : '')"
                        :aria-expanded="openDetails.has(row.key)"
                        :aria-label="`${openDetails.has(row.key) ? 'Masquer' : 'Afficher'} le détail : ${row.label}`"
                        :title="openDetails.has(row.key) ? 'Masquer le détail' : 'Libellés complets et codes'"
                        @click="toggleDetails(row)"
                    >
                        <Info class="h-3.5 w-3.5" />
                    </button>
                </div>

                <!-- Grille : une case par colonne, ou les actions propres à la
                     ligne quand elle n'a aucune action standard. -->
                <div class="pm-cells">
                    <template v-if="hasColumns(row)">
                        <div v-for="column in columns" :key="column.key" class="pm-cell">
                            <template v-if="row.cells[column.key]">
                                <PermissionToggle
                                    v-if="mode === 'baseline'"
                                    :permission="row.cells[column.key]"
                                    v-bind="cellProps(row.cells[column.key])"
                                    :disabled="readonly"
                                    :text="column.label"
                                    :context="row.label"
                                    @toggle="emit('toggle', row.cells[column.key])"
                                />
                                <PermissionEffectCell
                                    v-else
                                    :permission="row.cells[column.key]"
                                    v-bind="cellProps(row.cells[column.key])"
                                    :disabled="readonly"
                                    :text="column.label"
                                    :context="row.label"
                                    @change="onChange(row.cells[column.key], $event)"
                                />
                            </template>
                            <span v-else class="text-sm text-muted-foreground/35" aria-hidden="true">·</span>
                        </div>
                    </template>
                    <div v-else class="pm-others-inline">
                        <template v-for="permission in row.others" :key="permission.id">
                            <PermissionToggle
                                v-if="mode === 'baseline'"
                                :permission="permission"
                                v-bind="cellProps(permission)"
                                presentation="chip"
                                :disabled="readonly"
                                :context="row.label"
                                @toggle="emit('toggle', permission)"
                            />
                            <PermissionEffectCell
                                v-else
                                :permission="permission"
                                v-bind="cellProps(permission)"
                                presentation="chip"
                                :disabled="readonly"
                                :context="row.label"
                                @change="onChange(permission, $event)"
                            />
                        </template>
                    </div>
                </div>

                <!-- Pastilles : l'écran étroit lit chaque action par son verbe. -->
                <div class="pm-chips">
                    <template v-for="permission in row.permissions" :key="permission.id">
                        <PermissionToggle
                            v-if="mode === 'baseline'"
                            :permission="permission"
                            v-bind="cellProps(permission)"
                            presentation="chip"
                            :disabled="readonly"
                            :text="row.others.includes(permission) ? '' : permissionActionLabel(permission)"
                            :context="row.label"
                            @toggle="emit('toggle', permission)"
                        />
                        <PermissionEffectCell
                            v-else
                            :permission="permission"
                            v-bind="cellProps(permission)"
                            presentation="chip"
                            :disabled="readonly"
                            :text="row.others.includes(permission) ? '' : permissionActionLabel(permission)"
                            :context="row.label"
                            @change="onChange(permission, $event)"
                        />
                    </template>
                </div>

                <!-- Grille : les actions sans colonne, sous la ligne, par leur libellé. -->
                <div v-if="hasColumns(row) && row.others.length" class="pm-others-line">
                    <template v-for="permission in row.others" :key="permission.id">
                        <PermissionToggle
                            v-if="mode === 'baseline'"
                            :permission="permission"
                            v-bind="cellProps(permission)"
                            presentation="chip"
                            :disabled="readonly"
                            :context="row.label"
                            @toggle="emit('toggle', permission)"
                        />
                        <PermissionEffectCell
                            v-else
                            :permission="permission"
                            v-bind="cellProps(permission)"
                            presentation="chip"
                            :disabled="readonly"
                            :context="row.label"
                            @change="onChange(permission, $event)"
                        />
                    </template>
                </div>

                <!-- Détail : chaque permission avec son libellé complet, son
                     code — celui que cite un refus 403 (ADR-154) — et ce qui
                     la distingue. -->
                <ul v-if="openDetails.has(row.key)" class="pm-details mt-1 space-y-1 rounded-lg border border-border bg-muted/30 p-2">
                    <li
                        v-for="permission in row.permissions"
                        :key="`detail-${permission.id}`"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md px-2 py-1.5 hover:bg-card"
                    >
                        <span class="min-w-0 flex-1 text-xs font-semibold text-foreground">{{ permissionLabel(permission) }}</span>
                        <code class="font-mono text-[11px] text-muted-foreground">{{ permission.name }}</code>
                        <span
                            v-if="describe(permission).sensitive"
                            class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-1.5 py-px text-[10px] font-semibold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300"
                        ><TriangleAlert class="h-3 w-3" />Sensible</span>
                        <span
                            v-if="describe(permission).denyCount"
                            class="rounded-full bg-destructive/10 px-1.5 py-px text-[10px] font-semibold text-destructive"
                        >Refusé à {{ describe(permission).denyCount }} compte{{ describe(permission).denyCount > 1 ? 's' : '' }} de ce rôle</span>
                        <span
                            v-if="describe(permission).sourceLabel"
                            class="rounded-full bg-primary/10 px-1.5 py-px text-[10px] font-semibold text-primary"
                        >{{ describe(permission).sourceLabel }}</span>
                        <span
                            v-if="describe(permission).changed"
                            class="rounded-full bg-amber-100 px-1.5 py-px text-[10px] font-semibold text-amber-800 dark:bg-amber-950/50 dark:text-amber-200"
                        >Modifiée</span>
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>

<style scoped>
/*
 * Mobile d'abord : pastilles. La grille ne s'installe que lorsque le module
 * a la place de ses sept colonnes — mesurée sur la carte elle-même, pas sur
 * la fenêtre, parce que la colonne des rôles en prend déjà une partie.
 */
.pm {
    container: pm / inline-size;
    --pm-col: 3.875rem;
}

.pm-head,
.pm-cells,
.pm-others-line {
    display: none;
}

.pm-row {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

/*
 * Les pastilles s'alignent sous le libellé, après ce qui le précède :
 *   socle, fonctionnalité       case 1rem + 0,625 + icône 2rem + 0,625     = 4,25rem
 *   socle, sous-fonctionnalité  retrait 1rem + case + 0,625 + icône 1,75rem + 0,625 = 5rem
 *   compte, fonctionnalité      icône 2rem + 0,625                          = 2,625rem
 *   compte, sous-fonctionnalité retrait 1rem + icône 1,75rem + 0,625        = 3,375rem
 */
.pm {
    --pm-indent: 4.25rem;
}

.pm-row--nested {
    --pm-indent: 5rem;
}

.pm--overrides {
    --pm-indent: 2.625rem;
}

.pm--overrides .pm-row--nested {
    --pm-indent: 3.375rem;
}

.pm-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    padding-inline-start: var(--pm-indent);
}

@container pm (min-width: 38rem) {
    /* Aucun écart entre colonnes : l'en-tête n'en a pas, et le moindre
       écart propre aux lignes décalait chaque case de sa légende. */
    .pm-head,
    .pm-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) repeat(7, var(--pm-col));
        align-items: center;
        column-gap: 0;
        row-gap: 0.5rem;
    }

    .pm-head {
        align-items: end;
    }

    .pm-cells {
        display: contents;
    }

    .pm-cell {
        display: grid;
        place-items: center;
        min-height: 2rem;
    }

    .pm-chips {
        display: none;
    }

    .pm-others-inline {
        grid-column: 2 / -1;
        display: flex;
        flex-wrap: wrap;
        gap: 0.375rem;
        padding-inline-start: 0.75rem;
    }

    .pm-others-line {
        grid-column: 1 / -1;
        display: flex;
        flex-wrap: wrap;
        gap: 0.375rem;
        padding-inline-start: var(--pm-indent);
    }

    .pm-details {
        grid-column: 1 / -1;
    }
}
</style>
