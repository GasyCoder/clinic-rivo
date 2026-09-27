<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Network } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { cn } from '@/lib/cn';
import { HR_FIGURE_TONES } from '@/utilities/hrFigures';
import { hrUrl } from '@/utilities/hrUrl';

/**
 * L'effectif actif par département, sur l'accueil RH du site — celui du RH du
 * site comme celui que le Super Admin ouvre depuis le portail (ADR-187) : une
 * seule lecture (HrOverviewService::departments). Chaque département ouvre la
 * liste des employés filtrée sur son nom.
 */
const props = defineProps({
    departments: { type: Array, default: () => [] },
    siteName: { type: String, default: '' },
    canCreate: { type: Boolean, default: false },
});

const total = computed(() => props.departments.reduce((sum, department) => sum + department.employees_count, 0));
const max = computed(() => Math.max(1, ...props.departments.map((department) => department.employees_count)));
const share = (count) => (total.value ? Math.round((count / total.value) * 100) : 0);
const plural = (count, word) => `${count} ${word}${count > 1 ? 's' : ''}`;
const listUrl = (department) => (department.uuid
    ? hrUrl(`/administration/employees?q=${encodeURIComponent(department.label)}`)
    : null);
</script>

<template>
    <Card class="p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', HR_FIGURE_TONES.sky)"><Network class="h-5 w-5" /></span>
                <div>
                    <h2 class="font-heading text-base font-bold text-foreground">Effectif par département</h2>
                    <p class="mt-0.5 text-sm text-muted-foreground">{{ plural(total, 'employé actif') }}<template v-if="siteName"> à {{ siteName }}</template>.</p>
                </div>
            </div>
            <Button :as="Link" :href="hrUrl('/administration/employees')" variant="ghost" size="sm">
                Voir les employés<ArrowRight class="h-4 w-4" />
            </Button>
        </div>

        <ul v-if="departments.length" class="mt-4 space-y-3">
            <li v-for="department in departments" :key="department.uuid || department.label">
                <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                    <component
                        :is="listUrl(department) ? Link : 'span'"
                        :href="listUrl(department) ?? undefined"
                        :class="cn('truncate text-foreground', listUrl(department) && 'hover:text-primary hover:underline')"
                    >{{ department.label }}</component>
                    <span class="shrink-0 tabular-nums text-muted-foreground"><strong class="text-foreground">{{ department.employees_count }}</strong> · {{ share(department.employees_count) }} %</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                    <div class="h-full rounded-full bg-primary" :style="{ width: `${Math.max(4, Math.round((department.employees_count / max) * 100))}%` }" />
                </div>
            </li>
        </ul>
        <div v-else class="mt-4 rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
            Aucun employé actif à répartir.
            <Link v-if="canCreate" :href="hrUrl('/administration/employees/create')" class="ms-1 font-semibold text-primary hover:underline">Créer le premier dossier</Link>
        </div>
    </Card>
</template>
