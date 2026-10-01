<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Building2, HandCoins, ListChecks, Settings2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';

/**
 * ADR-229 — Finance › Dettes du personnel, pour un site : de quel site on lit les dettes,
 * le passage aux autres sites et le retour à « Tous les sites ». Les écrans viennent de
 * l'API du site ; les gestes y sont revérifiés et signés du nom du Super Admin.
 */
const page = usePage();
const { can } = usePermissions();

const context = computed(() => page.props.staffDebtContext);
const base = computed(() => context.value?.base ?? '');
const currentPath = computed(() => page.url.split('?')[0]);

const sections = computed(() => [
    { code: 'list', label: 'Dettes', icon: ListChecks, href: base.value },
    ...(can('staff_debts.settings') ? [{ code: 'settings', label: 'Réglages', icon: Settings2, href: `${base.value}/reglages` }] : []),
]);
const active = computed(() => (currentPath.value === `${base.value}/reglages` ? 'settings' : 'list'));
const otherSites = computed(() => (context.value?.sites ?? []).filter((site) => site.code !== context.value?.site?.code));
</script>

<template>
    <nav v-if="context" class="mb-5 space-y-3 print:hidden" aria-label="Dettes du personnel du site">
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><HandCoins class="h-4 w-4" /></span>
            <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Dettes du personnel · via l’API du site</p>
                <p class="truncate font-heading text-sm font-bold text-foreground">{{ context.site.name }}</p>
            </div>
            <Badge variant="secondary" class="ms-1">Actions tracées à votre nom</Badge>

            <div class="flex max-w-full gap-1 rounded-lg border border-border bg-muted/30 p-0.5 sm:ms-3">
                <Link
                    v-for="section in sections"
                    :key="section.code"
                    :href="section.href"
                    :aria-current="active === section.code ? 'page' : undefined"
                    :class="cn(
                        'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-semibold transition-colors',
                        active === section.code ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    )"
                ><component :is="section.icon" class="h-3.5 w-3.5" />{{ section.label }}</Link>
            </div>

            <div class="ms-auto flex flex-wrap items-center gap-1.5">
                <Link
                    v-for="site in otherSites"
                    :key="site.code"
                    :href="site.url"
                    :class="cn('inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-semibold text-muted-foreground transition hover:text-foreground', ! site.configured && 'pointer-events-none opacity-50')"
                    :aria-disabled="! site.configured"
                    :title="site.configured ? `Voir les dettes du personnel de ${site.name}` : `L’API de ${site.name} n’est pas configurée`"
                ><Building2 class="h-3.5 w-3.5" />{{ site.name }}</Link>
                <Link :href="context.overview_url" class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-semibold text-muted-foreground transition hover:bg-accent hover:text-foreground">
                    <ArrowLeft class="h-3.5 w-3.5" />Tous les sites
                </Link>
            </div>
        </div>
    </nav>
</template>
