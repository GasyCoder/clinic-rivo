<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Bandage, HeartPulse, Syringe } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';

/**
 * Le module Soins, en trois onglets : Infirmière, Maternité, Anesthésie.
 *
 * Trois espaces qui s'ouvraient chacun depuis sa propre entrée du menu se lisent
 * désormais comme un seul module. Chaque onglet reste une **vraie page** — le
 * bouton Précédent du navigateur fonctionne, l'adresse se partage, et la file de
 * chaque équipe garde ses données — comme les onglets de la Pharmacie (ADR-098).
 *
 * Un onglet n'apparaît qu'avec le droit de son espace : le rôle ne suffit pas
 * (une infirmière sans `maternity.view` ne voit pas Maternité) et l'onglet
 * courant est toujours affiché, puisqu'on y est. Avec un seul onglet
 * visible, la barre disparaît : elle n'aurait rien à offrir.
 *
 * Le filtrage n'est qu'une commodité : chaque route vérifie de son côté la
 * permission de son espace.
 */
const props = defineProps({
    /** `care` | `maternity` | `anesthesia` */
    current: { type: String, required: true },
});

const { can } = usePermissions();

// `care.update` comme l'entrée de menu (clinicWorkspaces) : `care.view` seul ne
// fait que nourrir la projection en lecture seule des dossiers Médecine et
// Chirurgie (ADR-048/054), il n'ouvre pas la file des infirmières.
const TABS = [
    { key: 'care', label: 'Infirmière', href: '/care', icon: Bandage, permission: 'care.update' },
    { key: 'maternity', label: 'Maternité', href: '/maternity', icon: HeartPulse, permission: 'maternity.view' },
    { key: 'anesthesia', label: 'Anesthésie', href: '/anesthesia', icon: Syringe, permission: 'anesthesia.view' },
];

const tabs = computed(() => TABS.filter((tab) => tab.key === props.current || can(tab.permission)));
</script>

<template>
    <nav v-if="tabs.length > 1" class="border-b border-border" aria-label="Espaces du module Soins">
        <ul class="-mb-px flex gap-1 overflow-x-auto" role="tablist">
            <li v-for="tab in tabs" :key="tab.key" role="presentation">
                <Link
                    :href="tab.href"
                    role="tab"
                    :aria-selected="tab.key === current"
                    :aria-current="tab.key === current ? 'page' : undefined"
                    :class="cn(
                        'inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                        tab.key === current
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                    )"
                >
                    <component :is="tab.icon" class="h-4 w-4" />{{ tab.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
