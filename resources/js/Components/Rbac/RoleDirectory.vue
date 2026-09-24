<script setup>
import { computed, ref } from 'vue';
import { Archive, ChevronDown, Lock, Plus, Search, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { normalizePermissionText } from '@/utilities/permissionWorkspace';
import { roleInitials } from '@/utilities/roleDescriptions';
import { cn } from '@/lib/cn';

/**
 * La liste des rôles d'un site (ADR-178).
 *
 * Elle remplace deux choses : la fenêtre « Changer de rôle » — il fallait
 * l'ouvrir à chaque fois — et le tableau « Rôles du site », quatrième onglet
 * qu'on ne consultait que pour renommer ou archiver. Tout rôle est à un clic,
 * avec ce qu'il porte : ses droits, ses comptes, et une pastille quand son
 * socle a des modifications non enregistrées.
 *
 * Le rôle système (SUPER_ADMIN) y figure, verrouillé : il ne se règle jamais
 * depuis un site (ADR-025, ADR-027), et l'écran le dit plutôt que de le taire.
 * Les rôles archivés restent consultables et restaurables, repliés en bas.
 */
const props = defineProps({
    roles: { type: Array, default: () => [] },
    selectedCode: { type: String, default: '' },
    /** Le rôle dont le socle porte des modifications non enregistrées. */
    dirtyCode: { type: String, default: '' },
    creating: { type: Boolean, default: false },
    canCreate: { type: Boolean, default: false },
    catalogSize: { type: Number, default: 0 },
});

const emit = defineEmits(['select', 'create']);

const search = ref('');
const showArchived = ref(false);

const matches = (role) => {
    const term = normalizePermissionText(search.value).trim();

    return term === '' || normalizePermissionText(`${role.name} ${role.code}`).includes(term);
};

const active = computed(() => props.roles.filter((role) => ! role.archived && ! role.protected && matches(role)));
const system = computed(() => props.roles.filter((role) => ! role.archived && role.protected && matches(role)));
const archived = computed(() => props.roles.filter((role) => role.archived && matches(role)));
const archivedTotal = computed(() => props.roles.filter((role) => role.archived).length);
const editableTotal = computed(() => props.roles.filter((role) => ! role.archived && ! role.protected).length);

/** Une recherche qui ne trouve qu'un rôle archivé l'affiche, sans clic de plus. */
const archivedOpen = computed(() => showArchived.value || (search.value.trim() !== '' && archived.value.length > 0));

const nothingFound = computed(() => ! active.value.length && ! system.value.length && ! archived.value.length);

const list = ref(null);

/** Les flèches passent d'un rôle à l'autre ; Entrée l'ouvre (ce sont des boutons). */
const onKeydown = (event) => {
    if (! ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

    const buttons = Array.from(list.value?.querySelectorAll('[data-role]') ?? []);

    if (! buttons.length) return;

    const at = buttons.indexOf(document.activeElement);
    const next = {
        ArrowDown: Math.min(buttons.length - 1, at + 1),
        ArrowUp: Math.max(0, at === -1 ? 0 : at - 1),
        Home: 0,
        End: buttons.length - 1,
    }[event.key];

    event.preventDefault();
    buttons[next]?.focus();
};

const permissionCount = (role) => role.permissions?.length ?? 0;
</script>

<template>
    <div class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <div class="shrink-0 space-y-3 border-b border-border p-3">
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-bold text-foreground">
                    Rôles <span class="ms-1 font-semibold tabular-nums text-muted-foreground">{{ editableTotal }}</span>
                </p>
                <Button
                    v-if="canCreate"
                    type="button"
                    size="xs"
                    :variant="creating ? 'primary' : 'outline'"
                    :aria-pressed="creating"
                    @click="emit('create')"
                >
                    <Plus class="h-3.5 w-3.5" />Nouveau rôle
                </Button>
            </div>
            <div class="relative">
                <IconInput
                    v-model="search"
                    :icon="Search"
                    type="search"
                    class="h-9 pe-9 [&::-webkit-search-cancel-button]:appearance-none"
                    placeholder="Rechercher un rôle…"
                    autocomplete="off"
                    aria-label="Rechercher un rôle"
                    @keydown.esc="search = ''"
                />
                <Button
                    v-if="search"
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    class="absolute end-1 top-1/2 z-20 -translate-y-1/2"
                    aria-label="Effacer la recherche"
                    @click="search = ''"
                >
                    <X class="h-3.5 w-3.5" />
                </Button>
            </div>
        </div>

        <nav ref="list" class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2" aria-label="Rôles du site" @keydown="onKeydown">
            <button
                v-for="role in active"
                :key="role.code"
                type="button"
                data-role
                :class="cn(
                    'group relative flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                    role.code === selectedCode && ! creating ? 'bg-primary/10' : 'hover:bg-accent',
                )"
                :aria-current="role.code === selectedCode && ! creating ? 'true' : undefined"
                @click="emit('select', role.code)"
            >
                <span v-if="role.code === selectedCode && ! creating" class="absolute inset-y-2 start-0 w-1 rounded-e-full bg-primary" aria-hidden="true" />
                <span
                    :class="cn(
                        'grid h-9 w-9 shrink-0 place-items-center rounded-lg text-xs font-bold',
                        role.code === selectedCode && ! creating ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground group-hover:bg-card',
                    )"
                    aria-hidden="true"
                >{{ roleInitials(role.name) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-1.5">
                        <span :class="cn('truncate text-sm', role.code === selectedCode && ! creating ? 'font-bold text-foreground' : 'font-semibold text-foreground/90')">{{ role.name }}</span>
                        <span v-if="role.code === dirtyCode" class="h-2 w-2 shrink-0 rounded-full bg-amber-500" title="Modifications non enregistrées" />
                    </span>
                    <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">
                        <template v-if="role.users_count">{{ role.users_count }} compte{{ role.users_count > 1 ? 's' : '' }}</template><template v-else>Aucun compte</template><template v-if="role.profiles?.length"> · {{ role.profiles.length }} profil{{ role.profiles.length > 1 ? 's' : '' }}</template>
                    </span>
                </span>
                <span
                    class="shrink-0 rounded-full border border-border bg-card px-2 py-0.5 text-[11px] font-bold tabular-nums text-muted-foreground"
                    :title="`${permissionCount(role)} permission${permissionCount(role) > 1 ? 's' : ''} sur ${catalogSize}`"
                >{{ permissionCount(role) }}</span>
            </button>

            <template v-if="system.length">
                <p class="px-2.5 pb-1 pt-3 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Rôle système</p>
                <button
                    v-for="role in system"
                    :key="role.code"
                    type="button"
                    data-role
                    :class="cn(
                        'relative flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                        role.code === selectedCode && ! creating ? 'bg-muted' : 'hover:bg-accent',
                    )"
                    :aria-current="role.code === selectedCode && ! creating ? 'true' : undefined"
                    @click="emit('select', role.code)"
                >
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground" aria-hidden="true"><Lock class="h-4 w-4" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-foreground/80">{{ role.name }}</span>
                        <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">Géré par le portail, non modifiable ici</span>
                    </span>
                </button>
            </template>

            <template v-if="archivedTotal">
                <button
                    type="button"
                    class="mt-2 flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-start text-[11px] font-bold uppercase tracking-wider text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    :aria-expanded="archivedOpen"
                    @click="showArchived = ! showArchived"
                >
                    <ChevronDown :class="cn('h-3.5 w-3.5 transition-transform', archivedOpen ? '' : '-rotate-90')" />
                    Archivés
                    <span class="ms-auto tabular-nums">{{ archivedTotal }}</span>
                </button>
                <template v-if="archivedOpen">
                    <button
                        v-for="role in archived"
                        :key="role.code"
                        type="button"
                        data-role
                        :class="cn(
                            'relative flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                            role.code === selectedCode && ! creating ? 'bg-muted' : 'hover:bg-accent',
                        )"
                        :aria-current="role.code === selectedCode && ! creating ? 'true' : undefined"
                        @click="emit('select', role.code)"
                    >
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-dashed border-border text-muted-foreground" aria-hidden="true"><Archive class="h-4 w-4" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-muted-foreground line-through decoration-muted-foreground/40">{{ role.name }}</span>
                            <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">Archivé · {{ permissionCount(role) }} droit{{ permissionCount(role) > 1 ? 's' : '' }} conservé{{ permissionCount(role) > 1 ? 's' : '' }}</span>
                        </span>
                    </button>
                </template>
            </template>

            <p v-if="nothingFound" class="px-3 py-8 text-center text-xs text-muted-foreground">
                Aucun rôle ne correspond à « {{ search }} ».
            </p>
        </nav>
    </div>
</template>
