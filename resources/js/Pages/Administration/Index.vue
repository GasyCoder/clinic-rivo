<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { CalendarPlus, ShieldCheck, UserPlus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrFigures from '@/Components/Administration/HrFigures.vue';
import HrAreaBoard from '@/Components/Administration/HrAreaBoard.vue';
import HrDepartmentHeadcount from '@/Components/Administration/HrDepartmentHeadcount.vue';
import { usePermissions } from '@/composables/usePermissions';
import { HR_SITE_BASE } from '@/utilities/hrPath';
import { hrContext, hrUrl } from '@/utilities/hrUrl';

defineOptions({ layout: AppLayout });

/**
 * L'accueil RH d'un site (ADR-066), le même sur le site et sur le portail
 * (ADR-187) : ce qui attend une décision, l'effectif, puis chaque rubrique.
 * La grille des rubriques est partagée avec la page RH du portail (ADR-194).
 */
const props = defineProps({
    summary: { type: Object, required: true },
    siteName: String,
    departments: { type: Array, default: () => [] },
});

const { can } = usePermissions();

// A figure the account may not open is hidden, exactly as on the portal.
const visibleSummary = computed(() => ({
    ...props.summary,
    current_contracts: can('contracts.view') ? props.summary.current_contracts : null,
    contracts_ending_soon: can('contracts.view') ? props.summary.contracts_ending_soon : null,
    today_attendance: can('attendance.view') ? props.summary.today_attendance : null,
    open_attendance: can('attendance.view') ? props.summary.open_attendance : null,
    pending_leave: can('leave.view') ? props.summary.pending_leave : null,
    on_leave_today: can('leave.view') ? props.summary.on_leave_today : null,
    upcoming_shifts: can('planning.view') ? props.summary.upcoming_shifts : null,
}));
</script>

<template>
    <Head title="Accueil RH" />

    <div class="w-full space-y-6">
        <PageHeader
            eyebrow="Ressources humaines"
            :title="`Accueil RH · ${siteName}`"
            description="Le travail RH du site : ce qui attend une décision, l’effectif, puis chaque tâche en un clic."
            icon="briefcase"
            tone="primary"
        >
            <template #actions>
                <Button v-if="can('leave.create')" :as="Link" :href="hrUrl('/administration/leave/create')" variant="outline">
                    <CalendarPlus class="h-4 w-4" />Demande de congé
                </Button>
                <Button v-if="can('employees.create')" :as="Link" :href="hrUrl('/administration/employees/create')">
                    <UserPlus class="h-4 w-4" />Nouvel employé
                </Button>
            </template>
        </PageHeader>

        <section aria-labelledby="hr-figures-title">
            <h2 id="hr-figures-title" class="mb-3 font-heading text-base font-bold text-foreground">Aujourd’hui</h2>
            <HrFigures :summary="visibleSummary" linkable />
        </section>

        <HrAreaBoard :summary="visibleSummary" :base="hrContext()?.base ?? HR_SITE_BASE" />

        <HrDepartmentHeadcount :departments="departments" :site-name="siteName" :can-create="can('employees.create')" />

        <p class="flex items-start gap-2 px-1 text-xs text-muted-foreground">
            <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />Les dossiers RH restent sur ce site. Le Super Administrateur les gère aussi depuis le portail, par l’API du site : mêmes règles, et chaque modification tracée à son nom. Aucune paie n’est calculée automatiquement.
        </p>
    </div>
</template>
