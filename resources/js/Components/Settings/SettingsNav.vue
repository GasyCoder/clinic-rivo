<script setup>
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/cn';
import { SETTINGS_GROUPS, sectionsOf, settingsUrl } from '@/utilities/settingsSections';

/**
 * Le menu des modules des paramètres (ADR-191, amendement du 2026-09-25) : une
 * carte à droite du module ouvert, rangée par groupe, chaque module avec son
 * icône ; sur un écran étroit, une ligne d'icônes et de libellés qui défile au
 * dessus du module. Changer de module est une vraie navigation : l'adresse, le
 * bouton Précédent et la garde des modifications non enregistrées suivent.
 */
defineProps({
    current: { type: String, required: true },
    siteCode: { type: String, required: true },
});

/**
 * Sur un écran étroit, la ligne défile : le module ouvert y est ramené en vue,
 * sans faire défiler la page (seul le menu bouge). Lu après le rendu serveur.
 */
const nav = ref(null);
onMounted(() => {
    const el = nav.value;
    const active = el?.querySelector('[aria-current="page"]');
    if (! el || ! active || el.scrollWidth <= el.clientWidth) return;
    el.scrollLeft = active.offsetLeft - (el.clientWidth - active.offsetWidth) / 2;
});

const linkClass = (active) => cn(
    'group inline-flex h-9 shrink-0 items-center gap-2.5 whitespace-nowrap rounded-md px-3 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 lg:flex lg:w-full',
    active ? 'bg-primary/10 font-medium text-primary hover:bg-primary/10' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
);
</script>

<template>
    <nav
        ref="nav"
        class="relative flex gap-1 overflow-x-auto rounded-xl border border-border bg-card p-1.5 shadow-sm lg:block lg:space-y-3 lg:overflow-visible lg:p-2"
        aria-label="Modules des paramètres"
    >
        <div v-for="group in SETTINGS_GROUPS" :key="group.id" class="contents lg:block">
            <p class="hidden px-3 pb-1 pt-1.5 text-[0.7rem] font-semibold uppercase tracking-wider text-muted-foreground lg:block">{{ group.label }}</p>
            <Link
                v-for="section in sectionsOf(group.id)"
                :key="section.id"
                :href="settingsUrl(section.id, siteCode)"
                :aria-current="section.id === current ? 'page' : undefined"
                :class="linkClass(section.id === current)"
            >
                <component
                    :is="section.icon"
                    :class="cn('h-4 w-4 shrink-0', section.id === current ? 'text-primary' : 'text-muted-foreground group-hover:text-foreground')"
                    aria-hidden="true"
                />
                <span class="truncate">{{ section.label }}</span>
            </Link>
        </div>
    </nav>
</template>
