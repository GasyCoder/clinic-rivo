<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const firstOnlineSite = props.sites.find((site) => site.ok)?.site.code;
const selectedSiteCode = ref(firstOnlineSite ?? props.sites[0]?.site.code);
const search = ref('');
const statusFilter = ref('ALL');
const editing = ref(null);
const creating = ref(false);

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const summary = computed(() => selectedSite.value?.meta?.summary ?? { active: 0, inactive: 0, cash_affecting: 0 });
// The categories come from the target site itself: each site answers with the
// vocabulary its own version knows, rather than the portal assuming one.
const categoryOptions = computed(() => selectedSite.value?.meta?.categories ?? []);
const allMethods = computed(() => selectedSite.value?.data ?? []);
const methods = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase('fr');

    return allMethods.value.filter((method) => {
        const matchesSearch = !needle
            || `${method.name} ${method.code}`.toLocaleLowerCase('fr').includes(needle);
        const matchesStatus = statusFilter.value === 'ALL'
            || (statusFilter.value === 'ACTIVE' && method.active)
            || (statusFilter.value === 'INACTIVE' && !method.active);

        return matchesSearch && matchesStatus;
    });
});

const form = useForm({ site_code: selectedSiteCode.value, code: '', name: '', category: 'CASH', affects_cash_balance: false, requires_reference: false });

const selectSite = (code) => {
    selectedSiteCode.value = code;
    form.site_code = code;
    search.value = '';
    statusFilter.value = 'ALL';
    closeForm();
};

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.site_code = selectedSiteCode.value;
    creating.value = true;
};

const openEdit = (method) => {
    creating.value = false;
    form.clearErrors();
    form.site_code = selectedSiteCode.value;
    form.code = method.code;
    form.name = method.name;
    form.category = method.category;
    form.affects_cash_balance = method.affects_cash_balance;
    form.requires_reference = method.requires_reference;
    editing.value = method;
};

function closeForm() {
    if (form.processing) return;
    creating.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.site_code = selectedSiteCode.value;
}

const submit = () => {
    if (editing.value) {
        form.transform((data) => ({
            name: data.name,
            category: data.category,
            affects_cash_balance: data.affects_cash_balance,
            requires_reference: data.requires_reference,
        }))
            .put(`/super-admin/payment-methods/${selectedSiteCode.value}/${editing.value.uuid}`, {
                preserveScroll: true,
                onSuccess: closeForm,
            });
        return;
    }

    form.transform((data) => data).post('/super-admin/payment-methods', {
        preserveScroll: true,
        onSuccess: closeForm,
    });
};

const toggle = (method) => router.post(
    `/super-admin/payment-methods/${selectedSiteCode.value}/${method.uuid}/${method.active ? 'deactivate' : 'activate'}`,
    {},
    { preserveScroll: true },
);


</script>

<template>
    <Head title="Modes de paiement" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-xl" name="card-view" /></span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Modes de paiement</h1>
                    <p class="mt-1 text-sm text-slate-500">Moyens de règlement acceptés par la caisse de chaque clinique.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="hidden items-center gap-2 rounded border border-gray-200 bg-white px-3 py-2 text-xs text-slate-500 dark:border-gray-900 dark:bg-gray-950 md:inline-flex"><Icon name="shield-check" />Écritures auditées sur le site destinataire</span>
                <Button v-if="can('payment_methods.create')" size="rg" :disabled="!selectedSite?.ok" @click="openCreate"><Icon class="text-lg" name="plus" /><span class="ms-2">Nouveau mode</span></Button>
            </div>
        </header>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex gap-1 overflow-x-auto border-b border-gray-200 bg-gray-50/70 p-2 dark:border-gray-900 dark:bg-gray-1000/40">
                <button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex min-w-40 items-center justify-center gap-2 rounded px-4 py-2.5 text-sm font-bold transition', selectedSiteCode === site.site.code ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-white']" @click="selectSite(site.site.code)">
                    <span :class="['h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'UNCONFIGURED' ? 'bg-slate-300' : 'bg-red-500']" />
                    {{ site.site.name }}
                </button>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                <span class="flex h-11 w-11 items-center justify-center rounded bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="server" /></span>
                <h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">API indisponible pour {{ selectedSite?.site.name }}</h2>
                <p class="mt-1 max-w-xl text-xs leading-5 text-slate-500">{{ selectedSite?.message }}</p>
                <p class="mt-3 text-xs text-slate-400">Les autres sites restent utilisables et aucune base clinique n’est accédée directement.</p>
            </div>

            <template v-else>
                <div class="grid border-b border-gray-200 dark:border-gray-900 sm:grid-cols-3">
                    <div class="border-b border-gray-200 px-5 py-3.5 dark:border-gray-900 sm:border-b-0 sm:border-e"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Actifs</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ summary.active }}</p></div>
                    <div class="border-b border-gray-200 px-5 py-3.5 dark:border-gray-900 sm:border-b-0 sm:border-e"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Impactent le fond de caisse</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ summary.cash_affecting }}</p></div>
                    <div class="px-5 py-3.5"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Désactivés</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ summary.inactive }}</p></div>
                </div>

                <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                    <label class="relative block w-full sm:max-w-md"><Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" /><input v-model="search" type="search" class="h-9 w-full rounded border border-gray-200 bg-white ps-10 pe-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Rechercher un mode ou un code"></label>
                    <select v-model="statusFilter" class="h-9 min-w-40 rounded border border-gray-200 bg-white px-3 text-sm text-slate-600 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200"><option value="ALL">Tous les états</option><option value="ACTIVE">Actifs</option><option value="INACTIVE">Désactivés</option></select>
                </div>

                <form v-if="creating || editing" class="border-b border-gray-200 p-5 dark:border-gray-900" @submit.prevent="submit">
                    <div class="mb-4 flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-bold text-slate-700 dark:text-white">{{ editing ? `Modifier « ${editing.name} »` : 'Nouveau mode de paiement' }}</h2>
                            <p class="mt-1 text-xs text-slate-500">Site destinataire : <strong>{{ selectedSite.site.name }}</strong>. Le code identifie le mode sur les paiements déjà encaissés : il n’est plus modifiable après création.</p>
                        </div>
                        <button type="button" class="text-slate-400 hover:text-slate-700 dark:hover:text-white" @click="closeForm"><Icon class="text-xl" name="cross" /></button>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Code <span class="text-red-500">*</span></label>
                            <Input v-model="form.code" :disabled="Boolean(editing)" placeholder="MOBILE_MONEY_MVOLA" class="uppercase" />
                            <FormError :message="form.errors.code" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Libellé <span class="text-red-500">*</span></label>
                            <Input v-model="form.name" placeholder="MVola" />
                            <FormError :message="form.errors.name" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Catégorie <span class="text-red-500">*</span></label>
                            <select v-model="form.category" class="h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                                <option v-for="category in categoryOptions" :key="category.value" :value="category.value">{{ category.label }}</option>
                            </select>
                            <p class="mt-1 text-xs leading-5 text-slate-400">« Mobile money » regroupe MVola, Orange Money et Airtel Money — chacun reste un mode distinct pour le rapprochement.</p>
                            <FormError :message="form.errors.category" />
                        </div>
                        <label class="flex items-start gap-3 rounded border border-gray-200 p-3 dark:border-gray-800">
                            <input v-model="form.affects_cash_balance" type="checkbox" class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            <span>
                                <span class="block text-sm font-bold text-slate-700 dark:text-white">Impacte le fond de caisse</span>
                                <span class="mt-0.5 block text-xs leading-5 text-slate-500">Uniquement pour les espèces : le montant entre physiquement dans le tiroir et compte au comptage de clôture.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 rounded border border-gray-200 p-3 dark:border-gray-800">
                            <input v-model="form.requires_reference" type="checkbox" class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            <span>
                                <span class="block text-sm font-bold text-slate-700 dark:text-white">Exige une référence</span>
                                <span class="mt-0.5 block text-xs leading-5 text-slate-500">Le caissier devra saisir le n° de transaction (mobile money, chèque, virement). Sans cette case, aucun champ n’est demandé : le n° de paiement généré fait office de référence.</span>
                            </span>
                        </label>
                    </div>
                    <FormError class="mt-3" :message="form.errors.method || form.errors.site_code" />
                    <div class="mt-5 flex justify-end gap-3">
                        <Button type="button" size="rg" variant="white-outline" @click="closeForm">Annuler</Button>
                        <Button size="rg" :disabled="form.processing"><Icon name="check" /><span class="ms-2">{{ editing ? 'Enregistrer' : 'Créer sur le site' }}</span></Button>
                    </div>
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1080px] text-sm">
                        <thead class="bg-gray-50/70 text-[11px] font-medium uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40">
                            <tr>
                                <th class="px-5 py-3 text-start">Mode de paiement</th>
                                <th class="px-4 py-3 text-start">Catégorie</th>
                            <th class="px-4 py-3 text-start">Fond de caisse</th>
                            <th class="px-4 py-3 text-start">Référence</th>
                                <th class="px-4 py-3 text-end">Paiements enregistrés</th>
                                <th class="px-4 py-3 text-start">État</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                            <tr v-for="method in methods" :key="method.uuid" :class="method.active ? '' : 'bg-gray-50/50 dark:bg-gray-1000/20'">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-full', method.active ? 'bg-primary-50 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300' : 'bg-gray-100 text-slate-400 dark:bg-gray-900']"><Icon :name="method.category_icon" /></span>
                                        <div>
                                            <p class="font-bold text-slate-700 dark:text-white">{{ method.name }}</p>
                                            <p class="font-mono text-[11px] text-slate-400">{{ method.code }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-gray-900 dark:text-slate-300"><Icon :name="method.category_icon" />{{ method.category_label }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span v-if="method.affects_cash_balance" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300"><Icon name="wallet" />Espèces en tiroir</span>
                                    <span v-else class="text-xs text-slate-400">Hors tiroir</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span v-if="method.requires_reference" class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700 dark:bg-sky-950/30 dark:text-sky-300">Saisie exigée</span>
                                    <span v-else class="text-xs text-slate-400">Générée</span>
                                </td>
                                <td class="px-4 py-3 text-end font-semibold tabular-nums text-slate-600 dark:text-slate-300">{{ method.payments_count }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="method.active" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Actif</span>
                                    <span v-else class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500"><span class="h-1.5 w-1.5 rounded-full bg-slate-400" />Désactivé</span>
                                </td>
                                <td class="px-5 py-3 text-end">
                                    <div class="inline-flex gap-1">
                                        <button v-if="can('payment_methods.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-primary-600 dark:border-gray-800" title="Modifier" @click="openEdit(method)"><Icon name="edit" /></button>
                                        <button v-if="method.active && can('payment_methods.deactivate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-amber-600 dark:border-gray-800" title="Désactiver" @click="toggle(method)"><Icon name="cross" /></button>
                                        <button v-if="!method.active && can('payment_methods.activate')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-emerald-600 dark:border-gray-800" title="Réactiver" @click="toggle(method)"><Icon name="check" /></button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!methods.length">
                                <td colspan="7" class="px-5 py-12 text-center">
                                    <Icon class="text-2xl text-slate-300" name="card-view" />
                                    <p class="mt-2 text-sm font-medium text-slate-500">Aucun mode de paiement ne correspond à ces filtres.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="border-t border-gray-200 bg-gray-50/70 px-5 py-3 text-xs leading-5 text-slate-500 dark:border-gray-900 dark:bg-gray-1000/40">
                    Chaque site conserve sa propre liste : les opérateurs mobile money diffèrent d’une ville à l’autre. Un mode n’est jamais supprimé — les paiements encaissés gardent leur référence — et le dernier mode actif d’un site ne peut pas être désactivé.
                </p>
            </template>
        </section>
    </div>
</template>
