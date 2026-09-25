<script setup>
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/cn';

/**
 * La pagination des listes RH. Les libellés « Précédent / Suivant » viennent
 * de Laravel (entités HTML comprises), d'où `v-html` : ce sont nos propres
 * traductions, jamais une saisie.
 */
defineProps({ paginator: Object });
</script>

<template>
    <nav
        v-if="paginator?.last_page > 1"
        class="flex flex-col gap-3 border-t border-border bg-card px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        aria-label="Pagination"
    >
        <span class="text-xs tabular-nums text-muted-foreground">
            Page {{ paginator.current_page }} sur {{ paginator.last_page }} · {{ paginator.total }} résultat{{ paginator.total > 1 ? 's' : '' }}
        </span>
        <div class="flex max-w-full items-center gap-1 overflow-x-auto pb-1 sm:pb-0">
            <template v-for="(link, index) in paginator.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-state
                    preserve-scroll
                    :aria-current="link.active ? 'page' : undefined"
                    :class="cn(
                        'inline-flex h-8 min-w-8 items-center justify-center whitespace-nowrap rounded-md border px-2.5 text-sm font-medium tabular-nums transition-colors',
                        link.active
                            ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                            : 'border-border bg-card text-muted-foreground hover:bg-accent hover:text-foreground',
                    )"
                    v-html="link.label"
                />
                <span v-else class="inline-flex h-8 min-w-8 items-center justify-center whitespace-nowrap rounded-md px-2.5 text-sm text-muted-foreground/50" v-html="link.label" />
            </template>
        </div>
    </nav>
</template>
