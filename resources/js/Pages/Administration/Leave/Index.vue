<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrEmptyState from '../Partials/HrEmptyState.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ leaves: Object, filterStatus: String, summary: Object, statuses: [Array, Object] });
const { can } = usePermissions();
const forms = ref({});
const total = computed(() => Object.values(props.summary).reduce((sum, value) => sum + Number(value ?? 0), 0));
const actionForm = (uuid, action) => forms.value[`${uuid}-${action}`] ??= useForm({ reason: '' });
const act = (leave, action) => actionForm(leave.uuid, action).post(`/administration/leave/${leave.uuid}/${action}`, { preserveScroll: true });
const tone = (status) => ({ PENDING: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300', APPROVED: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', REJECTED: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300', CANCELLED: 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300' }[status]);
const statusMeta = {
    PENDING: { icon: 'clock', tone: 'amber' },
    APPROVED: { icon: 'check-circle', tone: 'emerald' },
    REJECTED: { icon: 'cross-circle', tone: 'rose' },
    CANCELLED: { icon: 'archive', tone: 'slate' },
};
</script>

<template>
    <Head title="Congés" />
    <div class="space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Demandes et décisions" title="Congés" description="Suivez les demandes et prenez une décision auditée. Les durées et soldes restent les valeurs saisies, sans calcul automatique de droits." icon="calendar" tone="amber">
            <template #actions><Button v-if="can('leave.create')" :as="Link" href="/administration/leave/create" size="rg"><Icon name="plus" /><span class="ms-2">Nouvelle demande</span></Button></template>
        </HrPageHeader>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <Link href="/administration/leave?status=ALL"><HrStatCard label="Toutes" :value="total" hint="Tous les états" icon="reports" tone="primary" :active="filterStatus === 'ALL'" /></Link>
            <Link v-for="status in statuses" :key="status.value" :href="`/administration/leave?status=${status.value}`"><HrStatCard :label="status.label" :value="summary[status.value]" :hint="status.value === 'PENDING' ? 'Décision à prendre' : 'Décisions enregistrées'" :icon="statusMeta[status.value]?.icon" :tone="statusMeta[status.value]?.tone" :active="filterStatus === status.value" /></Link>
        </section>

        <section v-if="leaves.data.length" class="space-y-3">
            <article v-for="leave in leaves.data" :key="leave.uuid" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto]">
                    <div><div class="flex flex-wrap items-center gap-2"><h2 class="font-bold text-slate-800 dark:text-white">{{ leave.employee.name }}</h2><span :class="['rounded-full px-2.5 py-1 text-xs font-bold', tone(leave.status)]">{{ leave.status_label }}</span></div><p class="mt-1 font-mono text-xs text-slate-400">{{ leave.employee.employee_number }}</p><p class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ leave.reason }}</p></div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm"><div><dt class="text-xs text-slate-400">Départ</dt><dd class="mt-1 font-bold text-slate-700 dark:text-white">{{ leave.starts_on }}</dd></div><div><dt class="text-xs text-slate-400">Retour</dt><dd class="mt-1 font-bold text-slate-700 dark:text-white">{{ leave.returns_on }}</dd></div><div><dt class="text-xs text-slate-400">Jours demandés</dt><dd class="mt-1 font-bold text-slate-700 dark:text-white">{{ leave.days_requested ?? 'N/R' }}</dd></div><div><dt class="text-xs text-slate-400">Intérimaire</dt><dd class="mt-1 truncate font-bold text-slate-700 dark:text-white">{{ leave.interim_employee?.name ?? 'N/R' }}</dd></div></dl>
                    <Button v-if="can('leave.print')" :as="Link" :href="`/administration/leave/${leave.uuid}/print`" icon size="rg" variant="white-outline" title="Imprimer"><Icon name="printer" /></Button>
                </div>
                <div v-if="leave.decision_reason || leave.cancel_reason" class="flex items-start gap-2 border-t border-gray-100 bg-gray-50/50 px-5 py-3 text-xs text-slate-500 dark:border-gray-900 dark:bg-gray-1000/20"><Icon class="mt-0.5 shrink-0" name="check-circle" /><p><strong class="text-slate-600 dark:text-slate-300">Décision :</strong> {{ leave.decision_reason || leave.cancel_reason }}<span v-if="leave.decided_by"> · {{ leave.decided_by }}</span></p></div>
                <details v-if="leave.status === 'PENDING' && (can('leave.approve') || can('leave.reject') || can('leave.cancel'))" class="group border-t border-gray-200 dark:border-gray-900">
                    <summary class="flex cursor-pointer list-none items-center justify-between bg-gray-50/60 px-5 py-3 text-xs font-bold text-slate-600 dark:bg-gray-1000/30 dark:text-slate-300"><span class="flex items-center gap-2"><Icon name="edit" /> Traiter cette demande</span><Icon class="text-slate-400 transition group-open:rotate-180" name="chevron-down" /></summary>
                    <div class="grid gap-3 p-4 lg:grid-cols-3">
                        <form v-if="can('leave.approve')" class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3 dark:border-emerald-900 dark:bg-emerald-950/20" @submit.prevent="act(leave, 'approve')"><label class="text-xs font-bold text-emerald-700 dark:text-emerald-300">Accepter</label><input v-model="actionForm(leave.uuid, 'approve').reason" maxlength="1000" placeholder="Note facultative" class="mt-2 h-9 w-full rounded-lg border border-emerald-200 px-3 text-xs dark:border-emerald-900 dark:bg-gray-950"><Button class="mt-2" block size="sm" :disabled="actionForm(leave.uuid, 'approve').processing">Confirmer l’acceptation</Button></form>
                        <form v-if="can('leave.reject')" class="rounded-lg border border-red-200 bg-red-50/50 p-3 dark:border-red-900 dark:bg-red-950/20" @submit.prevent="act(leave, 'reject')"><label class="text-xs font-bold text-red-700 dark:text-red-300">Refuser</label><input v-model="actionForm(leave.uuid, 'reject').reason" required maxlength="1000" placeholder="Motif obligatoire" class="mt-2 h-9 w-full rounded-lg border border-red-200 px-3 text-xs dark:border-red-900 dark:bg-gray-950"><Button class="mt-2" block size="sm" variant="danger" :disabled="actionForm(leave.uuid, 'reject').processing">Confirmer le refus</Button></form>
                        <form v-if="can('leave.cancel')" class="rounded-lg border border-gray-200 bg-gray-50/50 p-3 dark:border-gray-800 dark:bg-gray-1000/20" @submit.prevent="act(leave, 'cancel')"><label class="text-xs font-bold text-slate-600 dark:text-slate-300">Annuler</label><input v-model="actionForm(leave.uuid, 'cancel').reason" required maxlength="1000" placeholder="Motif obligatoire" class="mt-2 h-9 w-full rounded-lg border border-gray-200 px-3 text-xs dark:border-gray-800 dark:bg-gray-950"><Button class="mt-2" block size="sm" variant="white-outline" :disabled="actionForm(leave.uuid, 'cancel').processing">Confirmer l’annulation</Button></form>
                    </div>
                </details>
            </article>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950"><HrPagination :paginator="leaves" /></div>
        </section>
        <section v-else class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950"><HrEmptyState icon="calendar" title="Aucune demande dans cet état" description="Sélectionnez un autre état ou créez une nouvelle demande de congé." /></section>
    </div>
</template>
