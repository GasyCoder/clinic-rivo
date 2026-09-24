<script setup>
import { computed, ref } from 'vue';
import { Search, UserRound, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import { normalizePermissionText } from '@/utilities/permissionWorkspace';
import { roleInitials } from '@/utilities/roleDescriptions';
import { cn } from '@/lib/cn';

/**
 * La liste des comptes d'un site, pour régler leurs exceptions (ADR-178).
 *
 * Même place et même allure que la liste des rôles : on passe d'un compte à
 * l'autre d'un clic, sans fenêtre. Chaque ligne dit déjà ce qui compte ici —
 * le rôle du compte et combien d'exceptions il porte par-dessus.
 */
const props = defineProps({
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    selectedUuid: { type: String, default: '' },
    dirtyUuid: { type: String, default: '' },
    /** Code du rôle dont on veut voir les comptes, posé depuis l'écran d'un rôle. */
    roleFilter: { type: String, default: '' },
});

const emit = defineEmits(['select', 'update:roleFilter']);

const search = ref('');
const onlyExceptions = ref(false);

const roleOptions = computed(() => [
    { value: '', label: 'Tous les rôles' },
    ...props.roles
        .filter((role) => ! role.protected && props.users.some((user) => user.role?.code === role.code))
        .map((role) => ({ value: role.code, label: role.name })),
]);

const matches = (user) => {
    const term = normalizePermissionText(search.value).trim();

    if (props.roleFilter && user.role?.code !== props.roleFilter) return false;
    if (onlyExceptions.value && ! (user.permission_overrides ?? []).length) return false;

    return term === '' || normalizePermissionText(`${user.name} ${user.email} ${user.role?.name ?? ''}`).includes(term);
};

const visible = computed(() => props.users.filter(matches));
const withExceptions = computed(() => props.users.filter((user) => (user.permission_overrides ?? []).length).length);

const counts = (user) => {
    const overrides = user.permission_overrides ?? [];

    return {
        allow: overrides.filter((override) => override.effect === 'allow').length,
        deny: overrides.filter((override) => override.effect === 'deny').length,
    };
};

const list = ref(null);

const onKeydown = (event) => {
    if (! ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

    const buttons = Array.from(list.value?.querySelectorAll('[data-account]') ?? []);

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
</script>

<template>
    <div class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <div class="shrink-0 space-y-2.5 border-b border-border p-3">
            <p class="text-sm font-bold text-foreground">
                Comptes <span class="ms-1 font-semibold tabular-nums text-muted-foreground">{{ users.length }}</span>
            </p>
            <div class="relative">
                <IconInput
                    v-model="search"
                    :icon="Search"
                    type="search"
                    class="h-9 pe-9 [&::-webkit-search-cancel-button]:appearance-none"
                    placeholder="Nom, e-mail, rôle…"
                    autocomplete="off"
                    aria-label="Rechercher un compte"
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
            <Select
                :model-value="roleFilter"
                :options="roleOptions"
                class="h-9 w-full min-w-0"
                aria-label="Filtrer par rôle"
                @update:model-value="emit('update:roleFilter', $event)"
            />
            <button
                type="button"
                :class="cn(
                    'inline-flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors',
                    onlyExceptions ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground',
                )"
                :aria-pressed="onlyExceptions"
                @click="onlyExceptions = ! onlyExceptions"
            >
                Avec exceptions seulement
                <span class="tabular-nums">{{ withExceptions }}</span>
            </button>
        </div>

        <nav ref="list" class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2" aria-label="Comptes du site" @keydown="onKeydown">
            <button
                v-for="user in visible"
                :key="user.uuid"
                type="button"
                data-account
                :class="cn(
                    'group relative flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                    user.uuid === selectedUuid ? 'bg-primary/10' : 'hover:bg-accent',
                )"
                :aria-current="user.uuid === selectedUuid ? 'true' : undefined"
                @click="emit('select', user.uuid)"
            >
                <span v-if="user.uuid === selectedUuid" class="absolute inset-y-2 start-0 w-1 rounded-e-full bg-primary" aria-hidden="true" />
                <span
                    :class="cn(
                        'grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold',
                        user.uuid === selectedUuid ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                        ! user.active ? 'opacity-60' : '',
                    )"
                    aria-hidden="true"
                >{{ roleInitials(user.name) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-1.5">
                        <span :class="cn('truncate text-sm', user.uuid === selectedUuid ? 'font-bold text-foreground' : 'font-semibold text-foreground/90', ! user.active ? 'text-muted-foreground' : '')">{{ user.name }}</span>
                        <span v-if="user.uuid === dirtyUuid" class="h-2 w-2 shrink-0 rounded-full bg-amber-500" title="Modifications non enregistrées" />
                    </span>
                    <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">
                        {{ user.role?.name ?? 'Sans rôle' }}<template v-if="! user.active"> · désactivé</template>
                    </span>
                </span>
                <span v-if="counts(user).allow || counts(user).deny" class="flex shrink-0 items-center gap-1 text-[11px] font-bold tabular-nums">
                    <span v-if="counts(user).allow" class="rounded-full bg-emerald-50 px-1.5 py-0.5 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300" :title="`${counts(user).allow} autorisation(s) en plus du rôle`">+{{ counts(user).allow }}</span>
                    <span v-if="counts(user).deny" class="rounded-full bg-red-50 px-1.5 py-0.5 text-red-700 dark:bg-red-950/40 dark:text-red-300" :title="`${counts(user).deny} interdiction(s) malgré le rôle`">−{{ counts(user).deny }}</span>
                </span>
            </button>

            <div v-if="! visible.length" class="px-3 py-10 text-center">
                <UserRound class="mx-auto h-5 w-5 text-muted-foreground" aria-hidden="true" />
                <p class="mt-2 text-xs text-muted-foreground">Aucun compte ne correspond.</p>
            </div>
        </nav>
    </div>
</template>
