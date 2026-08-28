<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
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
const allocationForm = useForm({ amount: '', reason: '', idempotency_key: crypto.randomUUID() });
const submitSearch = () => router.get('/administration/staff-block-credits', { q: query.value || undefined }, { preserveState: true, replace: true });
const selectEmployee = (employee) => router.get('/administration/staff-block-credits', {
    q: query.value || undefined,
    employee: employee.uuid,
}, { preserveState: true, replace: true });
const allocate = () => allocationForm.post(`/administration/staff-block-credits/${props.selectedEmployee.uuid}`, {
    preserveScroll: true,
    onSuccess: () => {
        allocationForm.reset();
        allocationForm.idempotency_key = crypto.randomUUID();
    },
});
const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—';
</script>

<template>
    <Head title="Crédit forfaitaire Bloc" />
    <div class="w-full space-y-5">
        <header>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Administration · RH / Finance</p>
            <h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Crédit forfaitaire Bloc</h1>
            <p class="mt-1 text-sm text-slate-500">Allocations manuelles et mouvements immuables rattachés au dossier Employé.</p>
        </header>

        <div class="grid gap-5 xl:grid-cols-[420px_minmax(0,1fr)]">
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                <form class="border-b border-gray-200 p-4 dark:border-gray-900" @submit.prevent="submitSearch">
                    <Input v-model="query" type="search" placeholder="Matricule ou nom" />
                </form>
                <div class="divide-y divide-gray-100 dark:divide-gray-900">
                    <button v-for="employee in employees.data" :key="employee.uuid" type="button" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-start hover:bg-gray-50 dark:hover:bg-gray-900" @click="selectEmployee(employee)">
                        <span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ employee.last_name }} {{ employee.first_name }}</span><span class="mt-0.5 block text-xs text-slate-400">{{ employee.employee_number }} · {{ employee.profession || 'Fonction non renseignée' }}</span></span>
                        <span class="shrink-0 text-end"><span class="block text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(employee.credit.available) }}</span><span class="text-[10px] uppercase tracking-wide text-slate-400">Disponible</span></span>
                    </button>
                    <p v-if="!employees.data.length" class="px-4 py-10 text-center text-sm text-slate-400">Aucun employé trouvé.</p>
                </div>
                <div v-if="employees.last_page > 1" class="flex flex-wrap gap-1 border-t border-gray-200 p-3 dark:border-gray-900">
                    <template v-for="(link, index) in employees.links" :key="index">
                        <Link v-if="link.url" :href="link.url" preserve-state :class="['rounded px-2.5 py-1 text-xs', link.active ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900']" v-html="link.label" />
                        <span v-else class="px-2.5 py-1 text-xs text-slate-300" v-html="link.label" />
                    </template>
                </div>
            </section>

            <section v-if="selectedEmployee" class="space-y-4">
                <div class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div><p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ selectedEmployee.employee_number }}</p><h2 class="mt-1 text-lg font-bold text-slate-700 dark:text-white">{{ selectedEmployee.last_name }} {{ selectedEmployee.first_name }}</h2><p class="text-xs text-slate-500">{{ selectedEmployee.profession || 'Fonction non renseignée' }}</p></div>
                        <span :class="['rounded px-2 py-1 text-xs font-bold', selectedEmployee.active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-slate-500']">{{ selectedEmployee.active ? 'Actif' : 'Inactif / archivé' }}</span>
                    </div>
                    <dl class="mt-5 grid gap-3 sm:grid-cols-4">
                        <div v-for="(value, key) in selectedEmployee.credit" :key="key" class="rounded border border-gray-200 p-3 dark:border-gray-800"><dt class="text-[10px] font-medium uppercase tracking-wide text-slate-400">{{ { allocated: 'Alloué', consumed: 'Consommé', reversed: 'Réversé', available: 'Disponible' }[key] }}</dt><dd class="mt-1 text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(value) }}</dd></div>
                    </dl>

                    <form v-if="can('staff_block_credits.allocate') && selectedEmployee.active" class="mt-5 grid gap-3 border-t border-gray-200 pt-5 md:grid-cols-[180px_minmax(0,1fr)_auto] md:items-end dark:border-gray-800" @submit.prevent="allocate">
                        <label><span class="mb-1 block text-xs font-medium text-slate-500">Montant à allouer (Ar)</span><Input v-model="allocationForm.amount" type="number" min="1" step="1" /></label>
                        <label><span class="mb-1 block text-xs font-medium text-slate-500">Motif</span><Input v-model="allocationForm.reason" placeholder="Décision RH / Finance" /></label>
                        <Button size="rg" :disabled="allocationForm.processing"><Icon class="me-2" name="plus" />Allouer</Button>
                        <FormError v-if="Object.keys(allocationForm.errors).length" class="md:col-span-3">{{ Object.values(allocationForm.errors)[0] }}</FormError>
                    </form>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Historique immuable</h2><p class="mt-0.5 text-xs text-slate-500">Toute correction est un nouveau mouvement inverse ; aucune ligne n’est modifiable ou supprimable.</p></div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm"><thead class="bg-gray-50 text-[10px] uppercase tracking-wide text-slate-400 dark:bg-gray-900"><tr><th class="px-4 py-2 text-start">Date</th><th class="px-4 py-2 text-start">Mouvement</th><th class="px-4 py-2 text-end">Montant</th><th class="px-4 py-2 text-end">Solde</th><th class="px-4 py-2 text-start">Référence / motif</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-900"><tr v-for="movement in movements?.data ?? []" :key="movement.uuid"><td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{{ formatDateTime(movement.created_at) }}</td><td class="px-4 py-3 font-bold text-slate-700 dark:text-white">{{ movement.movement_type_label }}</td><td :class="['px-4 py-3 text-end font-bold', Number(movement.amount) < 0 ? 'text-red-600' : 'text-emerald-600']">{{ formatMoney(movement.amount) }}</td><td class="px-4 py-3 text-end font-bold text-slate-700 dark:text-white">{{ formatMoney(movement.balance_after) }}</td><td class="px-4 py-3 text-xs text-slate-500"><span v-if="movement.episode_number" class="font-semibold">{{ movement.episode_number }} · </span>{{ movement.billable_item_description || movement.reason }}<span class="mt-0.5 block text-[10px] text-slate-400">{{ movement.created_by || 'Système' }}</span></td></tr><tr v-if="!(movements?.data ?? []).length"><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-400">Aucun mouvement.</td></tr></tbody></table>
                    </div>
                    <div v-if="movements?.last_page > 1" class="flex flex-wrap gap-1 border-t border-gray-200 p-3 dark:border-gray-900">
                        <template v-for="(link, index) in movements.links" :key="index">
                            <Link v-if="link.url" :href="link.url" preserve-state preserve-scroll :class="['rounded px-2.5 py-1 text-xs', link.active ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900']" v-html="link.label" />
                            <span v-else class="px-2.5 py-1 text-xs text-slate-300" v-html="link.label" />
                        </template>
                    </div>
                </div>
            </section>
            <div v-else class="flex min-h-72 items-center justify-center rounded-lg border border-dashed border-gray-300 p-8 text-center dark:border-gray-800"><div><Icon class="text-3xl text-slate-300" name="wallet" /><p class="mt-3 text-sm font-semibold text-slate-600 dark:text-slate-300">Sélectionnez un employé</p><p class="mt-1 text-xs text-slate-400">Son solde et son historique apparaîtront ici.</p></div></div>
        </div>
        <Link href="/administration" class="inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-primary-600"><Icon name="arrow-left" />Retour à l’Administration</Link>
    </div>
</template>
