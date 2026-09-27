<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { KeyRound, Lock, Users } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';

/**
 * ADR-199 — les comptes du personnel, en un seul module à deux onglets :
 *
 *  - « Comptes » : la vie du compte. Changer le rôle, désactiver au départ,
 *    donner un nouveau mot de passe, créer le compte d'une personne extérieure ;
 *  - « Accès du personnel » : l'arrivée. Un employé ajouté par le RH reçoit ici,
 *    en un geste, son adresse professionnelle et son compte RIVO (ADR-197).
 *
 * Chaque onglet reste une vraie page, avec son adresse et sa permission (même
 * principe que les onglets de Soins, ADR-134). Un onglet sans droit est montré
 * verrouillé, avec le droit qui l'ouvre, jamais masqué (ADR-158).
 */
const props = defineProps({
    /** `accounts` | `staff-access` */
    current: { type: String, required: true },
    /** Le nombre d'employés qui attendent leur accès, quand la page le connaît. */
    pending: { type: Number, default: null },
});

const { can } = usePermissions();

const TABS = [
    { key: 'accounts', label: 'Comptes', hint: 'Tous les comptes : rôle, départ, extérieurs', href: '/super-admin/workspaces/users', icon: Users, permission: 'users.view' },
    { key: 'staff-access', label: 'Accès du personnel', hint: 'Nouveaux employés : adresse + compte', href: '/super-admin/staff-access', icon: KeyRound, permission: 'staff_access.view' },
];

const tabs = computed(() => TABS.map((tab) => ({ ...tab, open: tab.key === props.current || can(tab.permission) })));
</script>

<template>
    <nav class="border-b border-border" aria-label="Comptes du personnel">
        <ul class="-mb-px flex gap-1 overflow-x-auto" role="tablist">
            <li v-for="tab in tabs" :key="tab.key" role="presentation">
                <Link
                    v-if="tab.open"
                    :href="tab.href"
                    role="tab"
                    :aria-selected="tab.key === current"
                    :aria-current="tab.key === current ? 'page' : undefined"
                    :title="tab.hint"
                    :class="cn(
                        'inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                        tab.key === current ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                    )"
                >
                    <component :is="tab.icon" class="h-4 w-4" aria-hidden="true" />{{ tab.label }}
                    <span
                        v-if="tab.key === 'staff-access' && pending"
                        class="rounded-full bg-destructive px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-destructive-foreground"
                        :aria-label="`${pending} en attente`"
                    >{{ pending }}</span>
                </Link>
                <span
                    v-else
                    role="tab"
                    aria-disabled="true"
                    :aria-selected="false"
                    :title="`Non accessible — demandez le droit « ${tab.permission} » à un administrateur.`"
                    class="inline-flex cursor-not-allowed items-center gap-2 whitespace-nowrap border-b-2 border-transparent px-4 py-2.5 text-sm font-semibold text-muted-foreground/50"
                >
                    <component :is="tab.icon" class="h-4 w-4" aria-hidden="true" />{{ tab.label }}<Lock class="h-3.5 w-3.5" aria-hidden="true" />
                </span>
            </li>
        </ul>
    </nav>
</template>
