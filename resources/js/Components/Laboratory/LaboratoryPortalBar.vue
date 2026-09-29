<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Building2, FlaskConical, Hand } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { activeLabSection, labSections } from '@/utilities/labSections';
import { cn } from '@/lib/cn';

/**
 * ADR-215 — la navigation du Laboratoire d'un site, quand le portail
 * l'affiche.
 *
 * Sur le site, les rubriques du Laboratoire vivent dans le menu latéral. Le
 * portail a son propre menu : cette barre les rend ici, dans le même ordre et
 * avec les mêmes droits, dit de quel site on lit le Laboratoire, et rappelle
 * que les gestes cliniques restent au site.
 */
const page = usePage();
const { can } = usePermissions();

const context = computed(() => page.props.laboratoryContext);
const base = computed(() => context.value?.base ?? '');
const currentPath = computed(() => page.url.split('?')[0]);

// Une seule liste de rubriques : celle du menu Laboratoire du site.
const sections = computed(() => labSections(base.value, can));
const active = computed(() => activeLabSection(sections.value, currentPath.value));

const otherSites = computed(() => (context.value?.sites ?? []).filter((site) => site.code !== context.value?.site?.code));
</script>

<template>
    <nav v-if="context" class="mb-5 space-y-3 print:hidden" aria-label="Laboratoire du site">
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><FlaskConical class="h-4 w-4" /></span>
            <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Laboratoire · via l’API du site</p>
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
                    :title="site.configured ? `Voir le Laboratoire de ${site.name}` : `L’API de ${site.name} n’est pas configurée`"
                ><Building2 class="h-3.5 w-3.5" />{{ site.name }}</Link>
                <Link :href="context.overview_url" class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-semibold text-muted-foreground transition hover:bg-accent hover:text-foreground">
                    <ArrowLeft class="h-3.5 w-3.5" />Tous les sites
                </Link>
            </div>
        </div>

        <div class="flex max-w-full gap-1 overflow-x-auto rounded-xl border border-border bg-card p-1 shadow-sm">
            <Link
                v-for="section in sections"
                :key="section.code"
                :href="section.href"
                :aria-current="active === section.code ? 'page' : undefined"
                :class="cn(
                    'inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors',
                    active === section.code ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                )"
            ><component :is="section.icon" class="h-4 w-4" />{{ section.label }}</Link>
        </div>

        <p class="flex items-start gap-2 px-1 text-xs text-muted-foreground">
            <Hand class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>Réceptionner, prélever, saisir, envoyer au médecin, renvoyer à refaire, signaler un critique, confier à l’extérieur et conclure se font au laboratoire du site, par la personne qui a le prélèvement sous les yeux : ces boutons sont montrés verrouillés ici.</span>
        </p>
    </nav>
</template>
