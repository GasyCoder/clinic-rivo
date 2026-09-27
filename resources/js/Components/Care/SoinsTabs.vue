<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Bandage, HeartPulse, Lock, Syringe } from 'lucide-vue-next';
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
 * ADR-158 — les trois profils sont **toujours** affichés. Masquer ceux dont le
 * compte n'a pas le droit faisait lire l'écran comme une version ancienne :
 * « où sont les trois profils ? ». Un espace sans droit est donc montré
 * verrouillé, avec la permission qui l'ouvre — même parti pris que les types de
 * sortie de l'ADR-090, et que le refus qui nomme le droit manquant (ADR-154).
 *
 * Le filtrage n'est qu'une commodité : chaque route vérifie de son côté la
 * permission de son espace.
 */
const props = defineProps({
    /** `care` | `maternity` | `anesthesia` */
    current: { type: String, required: true },
});

const { can } = usePermissions();

// ADR-157 — `care.create` comme l'entrée de menu et la route : c'est le droit
// d'ouvrir une fiche, donc de faire les soins. `care.view` ne fait que nourrir
// la projection en lecture des dossiers Médecine et Chirurgie (ADR-048/054),
// et `care.update` sert à Médecine pour corriger une fiche depuis sa
// consultation (ADR-093) : ni l'un ni l'autre n'ouvre la file des infirmières.
const TABS = [
    { key: 'care', label: 'Infirmière', href: '/care', icon: Bandage, permission: 'care.create' },
    { key: 'maternity', label: 'Maternité', href: '/maternity', icon: HeartPulse, permission: 'maternity.view' },
    { key: 'anesthesia', label: 'Anesthésie', href: '/anesthesia', icon: Syringe, permission: 'anesthesia.view' },
];

// L'onglet courant est toujours ouvert : on y est.
const tabs = computed(() => TABS.map((tab) => ({
    ...tab,
    open: tab.key === props.current || can(tab.permission),
})));
</script>

<template>
    <nav class="border-b border-border" aria-label="Espaces du module Soins">
        <ul class="-mb-px flex gap-1 overflow-x-auto" role="tablist">
            <li v-for="tab in tabs" :key="tab.key" role="presentation">
                <Link
                    v-if="tab.open"
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
                <!-- Verrouillé, jamais masqué : l'espace existe, et on dit quel
                     droit l'ouvre — un Super Administrateur le coche au socle du
                     rôle ou en exception (ADR-064, ADR-022). -->
                <span
                    v-else
                    role="tab"
                    aria-disabled="true"
                    :aria-selected="false"
                    :title="`Espace non accessible — demandez le droit « ${tab.permission} » à un administrateur.`"
                    class="inline-flex cursor-not-allowed items-center gap-2 whitespace-nowrap border-b-2 border-transparent px-4 py-2.5 text-sm font-semibold text-muted-foreground/50"
                >
                    <component :is="tab.icon" class="h-4 w-4" />{{ tab.label }}
                    <Lock class="h-3.5 w-3.5" />
                </span>
            </li>
        </ul>
    </nav>
</template>
