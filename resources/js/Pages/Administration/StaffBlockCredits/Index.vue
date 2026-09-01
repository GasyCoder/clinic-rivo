<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import HrEmptyState from '../Partials/HrEmptyState.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/utilities/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    employees: Object,
    selectedEmployee: Object,
    movements: Object,
    filters: Object,
});

const { can } = usePermissions();
const query = ref(props.filters.q ?? '');
const showAllocation = ref(false);
const newIdempotencyKey = () => crypto.randomUUID();
const allocationForm = useForm({ amount: '', reason: '', idempotency_key: newIdempotencyKey() });
const employeeName = (employee) => [employee?.last_name, employee?.first_name].filter(Boolean).join(' ');
const creditMetrics = computed(() => props.selectedEmployee ? [
    { key: 'allocated', label: 'Alloué', value: props.selectedEmployee.credit.allocated, tone: 'text-sky-700 dark:text-sky-300' },
    { key: 'consumed', label: 'Consommé au Bloc', value: props.selectedEmployee.credit.consumed, tone: 'text-amber-700 dark:text-amber-300' },
    { key: 'reversed', label: 'Réversé', value: props.selectedEmployee.credit.reversed, tone: 'text-violet-700 dark:text-violet-300' },
] : []);
const projectedBalance = computed(() => Number(props.selectedEmployee?.credit.available ?? 0) + Number(allocationForm.amount || 0));
const submitSearch = () => router.get('/administration/staff-block-credits', {
    q: query.value || undefined,
    employee: props.selectedEmployee?.uuid,
}, { preserveState: true, replace: true });
const clearSearch = () => {
    query.value = '';
    submitSearch();
};
const selectEmployee = (employee) => router.get('/administration/staff-block-credits', {
    q: query.value || undefined,
    employee: employee.uuid,
}, { preserveState: true, replace: true });
const resetAllocation = () => {
    allocationForm.reset();
    allocationForm.clearErrors();
    allocationForm.idempotency_key = newIdempotencyKey();
};
watch(() => props.selectedEmployee?.uuid, () => {
    resetAllocation();
    showAllocation.value = false;
});
const allocate = () => allocationForm.post(`/administration/staff-block-credits/${props.selectedEmployee.uuid}`, {
    preserveScroll: true,
    onSuccess: () => {
        resetAllocation();
        showAllocation.value = false;
    },
});
const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—';
const movementTone = (type) => ({
    ALLOCATION: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    CONSUMPTION: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    REVERSAL: 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
}[type] ?? 'bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300');
</script>

<template>
    <Head title="Crédit forfaitaire Bloc" />
    <div class="space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Administration · Registre du personnel" title="Crédit forfaitaire Bloc" description="Consultez le solde d’un employé, enregistrez une allocation manuelle autorisée et suivez chaque mouvement sans altérer l’historique." icon="wallet" tone="primary" />

        <aside class="grid gap-3 rounded-2xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-800 dark:border-primary-900 dark:bg-primary-950/20 dark:text-primary-300 lg:grid-cols-3">
            <div class="flex gap-3"><Icon class="mt-0.5 shrink-0 text-lg" name="edit" /><p><strong>Allocation manuelle.</strong><br><span class="text-xs leading-5 opacity-80">Aucun montant par défaut ni renouvellement automatique.</span></p></div>
            <div class="flex gap-3"><Icon class="mt-0.5 shrink-0 text-lg" name="shield-check" /><p><strong>Registre immuable.</strong><br><span class="text-xs leading-5 opacity-80">Une correction crée un mouvement inverse ; elle ne modifie jamais une ligne.</span></p></div>
            <div class="flex gap-3"><Icon class="mt-0.5 shrink-0 text-lg" name="lock" /><p><strong>Idempotence protégée.</strong><br><span class="text-xs leading-5 opacity-80">Un double envoi ne doit pas créer une seconde allocation.</span></p></div>
        </aside>

        <div class="grid items-start gap-5 xl:grid-cols-[390px_minmax(0,1fr)]">
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950 xl:sticky xl:top-4">
                <div class="border-b border-gray-200 bg-gray-50/70 p-4 dark:border-gray-900 dark:bg-gray-1000/40"><div class="flex items-center gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950"><Icon name="users" /></span><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Choisir un employé</h2><p class="mt-0.5 text-xs text-slate-500">{{ employees.total }} dossier(s) correspondant(s)</p></div></div><form class="mt-4 flex gap-2" @submit.prevent="submitSearch"><div class="relative min-w-0 flex-1"><Input v-model="query" size="lg" type="search" placeholder="Nom ou matricule" /><button v-if="query" type="button" class="absolute end-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-red-500" title="Effacer" @click="clearSearch"><Icon name="cross" /></button></div><Button icon size="lg" title="Rechercher"><Icon name="search" /></Button></form></div>

                <div v-if="employees.data.length" class="max-h-[580px] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-900">
                    <button v-for="employee in employees.data" :key="employee.uuid" type="button" :class="['flex w-full items-center gap-3 border-s-4 px-4 py-3.5 text-start transition', selectedEmployee?.uuid === employee.uuid ? 'border-primary-500 bg-primary-50/70 dark:bg-primary-950/20' : 'border-transparent hover:bg-gray-50 dark:hover:bg-gray-900']" @click="selectEmployee(employee)">
                        <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-black', selectedEmployee?.uuid === employee.uuid ? 'bg-primary-600 text-white' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ employee.last_name?.[0] }}{{ employee.first_name?.[0] }}</span>
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ employeeName(employee) }}</span><span class="mt-0.5 block truncate text-xs text-slate-400">{{ employee.employee_number }} · {{ employee.job_title || 'Fonction non renseignée' }}</span></span>
                        <span class="shrink-0 text-end"><span class="block text-sm font-black text-primary-700 dark:text-primary-300">{{ formatMoney(employee.credit.available) }}</span><span class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Disponible</span></span>
                    </button>
                </div>
                <HrEmptyState v-else icon="search" title="Aucun employé trouvé" description="Modifiez le nom ou le matricule utilisé pour la recherche."><Button v-if="query" type="button" size="sm" variant="white-outline" @click="clearSearch">Effacer la recherche</Button></HrEmptyState>
                <HrPagination :paginator="employees" />
            </section>

            <main v-if="selectedEmployee" class="min-w-0 space-y-4">
                <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex flex-col gap-4 bg-gradient-to-br from-primary-700 to-cyan-600 p-5 text-white sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3"><span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/15 text-sm font-black">{{ selectedEmployee.last_name?.[0] }}{{ selectedEmployee.first_name?.[0] }}</span><div class="min-w-0"><p class="text-[11px] font-bold uppercase tracking-wide text-white/70">{{ selectedEmployee.employee_number }}</p><h2 class="mt-1 truncate text-lg font-black">{{ employeeName(selectedEmployee) }}</h2><p class="truncate text-xs text-white/75">{{ selectedEmployee.job_title || 'Fonction non renseignée' }}</p></div></div>
                        <span :class="['w-fit rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', selectedEmployee.active ? 'bg-emerald-300/20 text-emerald-50' : 'bg-white/15 text-white/70']">{{ selectedEmployee.active ? 'Dossier actif' : 'Inactif / archivé' }}</span>
                    </div>

                    <div class="grid gap-4 p-5 lg:grid-cols-[minmax(240px,1.2fr)_2fr]">
                        <div class="rounded-2xl border border-primary-200 bg-primary-50 p-5 dark:border-primary-900 dark:bg-primary-950/20"><p class="text-[11px] font-bold uppercase tracking-wide text-primary-600 dark:text-primary-300">Solde disponible</p><p class="mt-2 text-3xl font-black tracking-tight text-primary-800 dark:text-primary-200">{{ formatMoney(selectedEmployee.credit.available) }}</p><p class="mt-2 text-xs leading-5 text-slate-500">Solde calculé à partir du registre des allocations, consommations Bloc et réversions.</p></div>
                        <dl class="grid gap-3 sm:grid-cols-3"><div v-for="metric in creditMetrics" :key="metric.key" class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ metric.label }}</dt><dd :class="['mt-2 text-base font-black', metric.tone]">{{ formatMoney(metric.value) }}</dd></div></dl>
                    </div>

                    <div v-if="can('staff_block_credits.allocate') && selectedEmployee.active" class="border-t border-gray-200 p-5 dark:border-gray-900">
                        <div v-if="!showAllocation" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h3 class="text-sm font-bold text-slate-800 dark:text-white">Nouvelle allocation manuelle</h3><p class="mt-1 text-xs leading-5 text-slate-500">Une décision motivée ajoute un nouveau mouvement audité au registre.</p></div><Button type="button" size="rg" @click="showAllocation = true"><Icon name="plus" /><span class="ms-2">Préparer une allocation</span></Button></div>
                        <form v-else class="rounded-2xl border border-primary-200 bg-primary-50/60 p-4 dark:border-primary-900 dark:bg-primary-950/20" @submit.prevent="allocate">
                            <div class="flex items-start justify-between gap-3"><div><p class="text-[11px] font-bold uppercase tracking-wide text-primary-600">Mouvement à confirmer</p><h3 class="mt-1 text-sm font-bold text-slate-800 dark:text-white">Allocation manuelle</h3></div><button type="button" class="text-slate-400 hover:text-red-500" title="Fermer" @click="showAllocation = false; resetAllocation()"><Icon name="cross" /></button></div>
                            <div class="mt-4 grid gap-4 md:grid-cols-[190px_minmax(0,1fr)]"><label><span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Montant à allouer (Ar) <span class="text-red-500">*</span></span><Input v-model="allocationForm.amount" size="lg" type="number" min="1" step="0.01" required /><FormError v-if="allocationForm.errors.amount">{{ allocationForm.errors.amount }}</FormError></label><label><span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Motif de la décision <span class="text-red-500">*</span></span><Input v-model="allocationForm.reason" size="lg" minlength="5" maxlength="1000" required placeholder="Expliquer l’allocation manuelle" /><FormError v-if="allocationForm.errors.reason">{{ allocationForm.errors.reason }}</FormError></label></div>
                            <div class="mt-4 flex flex-col gap-3 rounded-xl bg-white p-3 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between"><div class="text-xs text-slate-500">Solde après confirmation : <strong class="ms-1 text-base text-primary-700 dark:text-primary-300">{{ formatMoney(projectedBalance) }}</strong></div><div class="flex justify-end gap-2"><Button type="button" size="rg" variant="white-outline" @click="showAllocation = false; resetAllocation()">Annuler</Button><Button size="rg" :disabled="allocationForm.processing"><Icon name="shield-check" /><span class="ms-2">Confirmer l’allocation</span></Button></div></div>
                            <FormError v-if="allocationForm.errors.idempotency_key" class="mt-2">{{ allocationForm.errors.idempotency_key }}</FormError>
                        </form>
                    </div>
                    <div v-else-if="!selectedEmployee.active" class="border-t border-gray-200 bg-gray-50 px-5 py-4 text-xs text-slate-500 dark:border-gray-900 dark:bg-gray-1000/40"><Icon class="me-2" name="lock" />Une allocation ne peut pas être ajoutée à un dossier inactif ou archivé.</div>
                </section>

                <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex flex-col gap-2 border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Historique immuable</h2><p class="mt-1 text-xs text-slate-500">{{ movements?.total ?? 0 }} mouvement(s), du plus récent au plus ancien.</p></div><span class="w-fit rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><Icon class="me-1" name="shield-check" />Lecture seule</span></div>
                    <div v-if="movements?.data?.length" class="overflow-x-auto"><table class="min-w-[850px] w-full text-sm"><thead class="bg-gray-50 text-[10px] uppercase tracking-wide text-slate-400 dark:bg-gray-900"><tr><th class="px-5 py-2.5 text-start">Date</th><th class="px-4 py-2.5 text-start">Mouvement</th><th class="px-4 py-2.5 text-end">Montant</th><th class="px-4 py-2.5 text-end">Solde après</th><th class="px-5 py-2.5 text-start">Traçabilité</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-900"><tr v-for="movement in movements.data" :key="movement.uuid" class="hover:bg-gray-50/70 dark:hover:bg-gray-900/50"><td class="whitespace-nowrap px-5 py-4 text-xs text-slate-500">{{ formatDateTime(movement.created_at) }}</td><td class="px-4 py-4"><span :class="['inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', movementTone(movement.movement_type)]">{{ movement.movement_type_label }}</span></td><td :class="['px-4 py-4 text-end font-black', Number(movement.amount) < 0 ? 'text-red-600' : 'text-emerald-600']">{{ Number(movement.amount) > 0 ? '+' : '' }}{{ formatMoney(movement.amount) }}</td><td class="px-4 py-4 text-end font-black text-slate-700 dark:text-white">{{ formatMoney(movement.balance_after) }}</td><td class="max-w-sm px-5 py-4 text-xs text-slate-500"><span v-if="movement.episode_number" class="mb-1 block font-bold text-slate-700 dark:text-slate-300">Épisode {{ movement.episode_number }}</span><span class="block">{{ movement.billable_item_description || movement.reason || 'Sans détail' }}</span><span class="mt-1 block text-[10px] text-slate-400">Enregistré par {{ movement.created_by || 'le système' }}</span></td></tr></tbody></table></div>
                    <HrEmptyState v-else icon="activity" title="Aucun mouvement" description="La première allocation manuelle apparaîtra ici avec son auteur et son solde résultant." />
                    <HrPagination :paginator="movements" />
                </section>
            </main>

            <section v-else class="overflow-hidden rounded-2xl border border-dashed border-gray-300 bg-white dark:border-gray-800 dark:bg-gray-950"><HrEmptyState icon="wallet" title="Sélectionnez un employé" description="Son solde disponible, l’action d’allocation autorisée et son registre de mouvements apparaîtront dans cet espace." /></section>
        </div>
    </div>
</template>
