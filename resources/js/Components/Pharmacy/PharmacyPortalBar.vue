<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Building2, Hand, Pill } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { pharmacySections } from '@/utilities/pharmacySections';
import { cn } from '@/lib/cn';

/**
 * ADR-189 — la navigation de la Pharmacie d'un site, quand le portail
 * l'affiche.
 *
 * Sur le site, les rubriques de la Pharmacie vivent dans le menu latéral. Le
 * portail a son propre menu : cette barre les rend ici, dans le même ordre et
 * avec les mêmes droits, dit de quel site on consulte la Pharmacie, et rappelle
 * que les gestes physiques restent au site (ADR-098).
 */
const page = usePage();
const { can } = usePermissions();

const context = computed(() => page.props.pharmacyContext);
const base = computed(() => context.value?.base ?? '');
const currentPath = computed(() => page.url.split('?')[0]);

// Une seule liste de rubriques : celle du menu Pharmacie du site.
const sections = computed(() => pharmacySections(base.value, can));

const isActive = (section) => (section.prefixes.length
    ? section.prefixes.some((prefix) => currentPath.value === prefix || currentPath.value.startsWith(`${prefix}/`))
    : currentPath.value === section.href);

const otherSites = computed(() => (context.value?.sites ?? []).filter((site) => site.code !== context.value?.site?.code));
</script>

<template>
    <nav v-if="context" class="mb-5 space-y-3 print:hidden" aria-label="Pharmacie du site">
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><Pill class="h-4 w-4" /></span>
            <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Pharmacie · via l’API du site</p>
                <p class="truncate font-heading text-sm font-bold text-foreground">{{ context.site.name }}</p>
            </div>
            <Badge variant="secondary" class="ms-1">Actions tracées à votre nom</Badge>

            <div class="ms-auto flex flex-wrap items-center gap-1.5">
                <Link
                    v-for="site in otherSites"
                    :key="site.code"
                    :href="site.url"
                    :class="cn('inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-semibold text-muted-foreground transition hover:text-foreground', ! site.configured && 'pointer-events-none opacity-50')"
                    :aria-disabled="! site.configured"
                    :title="site.configured ? `Voir la Pharmacie de ${site.name}` : `L’API de ${site.name} n’est pas configurée`"
                ><Building2 class="h-3.5 w-3.5" />{{ site.name }}</Link>
                <Link :href="context.overview_url" class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-semibold text-muted-foreground transition hover:bg-accent hover:text-foreground">
                    <ArrowLeft class="h-3.5 w-3.5" />Stock de tous les sites
                </Link>
            </div>
        </div>

        <div class="flex max-w-full gap-1 overflow-x-auto rounded-xl border border-border bg-card p-1 shadow-sm">
            <Link
                v-for="section in sections"
                :key="section.code"
                :href="section.href"
                :aria-current="isActive(section) ? 'page' : undefined"
                :class="cn(
                    'inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors',
                    isActive(section) ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                )"
            ><component :is="section.icon" class="h-4 w-4" />{{ section.label }}</Link>
        </div>

        <p class="flex items-start gap-2 px-1 text-xs text-muted-foreground">
            <Hand class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>Délivrer, servir un consommable, réceptionner, entrer en stock, compter l’inventaire et ajuster se font à la Pharmacie du site, par la personne qui a les produits en main : ces boutons sont montrés verrouillés ici.</span>
        </p>
    </nav>
</template>
