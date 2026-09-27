<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Building2, Handshake } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-211 — quand le portail ouvre les Partenaires d'un site : de quel site il
 * s'agit, les autres sites, et le retour à la liste des sites. Sur le site, le
 * module vit dans le menu latéral et cette barre n'existe pas.
 */
const page = usePage();
const context = computed(() => page.props.partnersContext);
const otherSites = computed(() => (context.value?.sites ?? []).filter((site) => site.code !== context.value?.site?.code));
</script>

<template>
    <nav v-if="context" class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 shadow-sm print:hidden" aria-label="Partenaires du site">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><Handshake class="h-4 w-4" /></span>
        <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Partenaires · via l’API du site</p>
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
                :title="site.configured ? `Voir les partenaires de ${site.name}` : `L’API de ${site.name} n’est pas configurée`"
            ><Building2 class="h-3.5 w-3.5" />{{ site.name }}</Link>
            <Link :href="context.overview_url" class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-semibold text-muted-foreground transition hover:bg-accent hover:text-foreground">
                <ArrowLeft class="h-3.5 w-3.5" />Tous les sites
            </Link>
        </div>
    </nav>
</template>
