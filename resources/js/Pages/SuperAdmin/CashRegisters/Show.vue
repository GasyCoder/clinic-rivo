<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: Object,
    profile: Object,
    apiState: Object,
});

const { can } = usePermissions();
const dialog = ref(null);
const historyTab = ref('movements');
const activeSession = computed(() => props.profile?.active_session ?? null);
const focusSession = computed(() => props.profile?.focus_session ?? null);
const sessionIsLocked = computed(() => activeSession.value?.status === 'LOCKED');
const expectedCash = computed(() => activeSession.value?.totals?.expected_cash ?? '0.00');

const lockForm = useForm({ reason: '' });
const unlockForm = useForm({ reason: '' });
const closeForm = useForm({ actual_closing_amount: '', reason: '' });

const statusLabel = (status) => ({ OPEN: 'Ouverte', LOCKED: 'Verrouillée', CLOSED: 'Clôturée' }[status] ?? status);
const statusClass = (status) => ({
    OPEN: 'border-emerald-500 text-emerald-700 dark:text-emerald-300',
    LOCKED: 'border-amber-500 text-amber-700 dark:text-amber-300',
    CLOSED: 'border-slate-400 text-slate-500 dark:text-slate-300',
}[status] ?? 'border-slate-300 text-slate-500');

const closeVariance = computed(() => {
    if (closeForm.actual_closing_amount === '') return null;
    return Number(closeForm.actual_closing_amount) - Number(expectedCash.value);
});

const openDialog = (type) => {
    lockForm.reset();
    unlockForm.reset();
    closeForm.reset();
    lockForm.clearErrors();
    unlockForm.clearErrors();
    closeForm.clearErrors();
    // Lock/unlock are quick, frequent controls — a confirmation with a
    // sensible default is enough; the operator can still overwrite it, but
    // isn't forced to type something before the button will even submit.
    if (type === 'lock') lockForm.reason = 'Verrouillage de contrôle demandé par la Super Administration.';
    if (type === 'unlock') unlockForm.reason = 'Contrôle terminé, reprise autorisée par la Super Administration.';
    if (type === 'close') {
        closeForm.actual_closing_amount = expectedCash.value;
        closeForm.reason = 'Clôture centrale après comptage, demandée par la Super Administration.';
    }
    dialog.value = type;
};

const closeDialog = () => {
    if (!lockForm.processing && !unlockForm.processing && !closeForm.processing) dialog.value = null;
};

// Inertia fires onSuccess before onFinish, so the submitting form's own
// `processing` flag is still true at this point — closeDialog()'s guard
// (there to stop "Annuler"/the backdrop from closing mid-submit) would
// therefore block a real success from ever closing the dialog. A finished
// submission always gets to close, unconditionally.
const dismissDialog = () => { dialog.value = null; };

const endpoint = computed(() => `/super-admin/cash-registers/${props.targetSite.code}/${props.profile.register.uuid}/session`);
const exportUrl = computed(() => `/super-admin/cash-registers/${props.targetSite.code}/${props.profile.register.uuid}/export?type=${historyTab.value}`);
const submitLock = () => lockForm.post(`${endpoint.value}/lock`, { preserveScroll: true, onSuccess: dismissDialog });
const submitUnlock = () => unlockForm.post(`${endpoint.value}/unlock`, { preserveScroll: true, onSuccess: dismissDialog });
const submitClose = () => closeForm.post(`${endpoint.value}/close`, { preserveScroll: true, onSuccess: dismissDialog });
</script>

<template>
    <Head :title="profile ? `Caisse · ${profile.register.name}` : 'Caisse indisponible'" />

    <div class="mx-auto w-full max-w-[1540px] space-y-5">
        <header class="flex flex-col gap-4 border-b border-gray-200 pb-4 dark:border-gray-900 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <p class="mb-1 text-[10px] font-bold uppercase tracking-[0.18em] text-primary-600 dark:text-primary-300">Super Administration · {{ targetSite.name }}</p>
                <h1 class="truncate font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ profile?.register.name ?? 'Fiche de caisse' }}</h1>
                <p class="mt-1 text-sm text-slate-400">Supervision opérationnelle et historique financier transmis par l’API du site.</p>
            </div>
            <Button :as="Link" href="/super-admin/cash-registers" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" /><span class="ms-2">Retour aux caisses</span>
            </Button>
        </header>

        <section v-if="!apiState.ok" class="border-s-4 border-red-500 bg-white px-5 py-6 shadow-sm dark:bg-gray-950">
            <p class="text-sm font-bold text-slate-700 dark:text-white">Le site {{ targetSite.name }} ne peut pas transmettre cette fiche.</p>
            <p class="mt-1 text-sm text-slate-500">{{ apiState.message }}</p>
        </section>

        <template v-else-if="profile">
            <section class="overflow-hidden border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_310px]">
                    <div class="border-b border-gray-200 p-5 dark:border-gray-900 lg:border-b-0 lg:border-e">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Session en cours</p>
                                <template v-if="activeSession">
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                                        <h2 class="font-heading text-xl font-bold text-slate-700 dark:text-white">{{ activeSession.session_number }}</h2>
                                        <span :class="['border-s-2 ps-2 text-xs font-bold uppercase tracking-wide', statusClass(activeSession.status)]">{{ statusLabel(activeSession.status) }}</span>
                                    </div>
                                    <p class="mt-2 text-sm text-slate-500">Ouverte par <strong class="text-slate-700 dark:text-slate-200">{{ activeSession.opened_by }}</strong> le {{ formatDateTime(activeSession.opened_at) }}</p>
                                </template>
                                <template v-else>
                                    <h2 class="mt-2 font-heading text-xl font-bold text-slate-700 dark:text-white">Aucune session active</h2>
                                    <p class="mt-1 text-sm text-slate-500">Cette caisse ne peut être ni verrouillée ni clôturée actuellement.</p>
                                </template>
                            </div>
                            <p class="text-xs font-medium text-slate-400">{{ profile.register.sessions_count }} session{{ profile.register.sessions_count > 1 ? 's' : '' }} enregistrée{{ profile.register.sessions_count > 1 ? 's' : '' }}</p>
                        </div>

                        <dl v-if="activeSession" class="mt-5 grid grid-cols-2 border-y border-gray-200 dark:border-gray-900 xl:grid-cols-5">
                            <div class="px-3 py-3 first:ps-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Fond initial</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(activeSession.opening_amount) }}</dd></div>
                            <div class="border-s border-gray-200 px-3 py-3 dark:border-gray-900"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Entrées</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(activeSession.totals.total_in) }}</dd></div>
                            <div class="border-s border-gray-200 px-3 py-3 dark:border-gray-900"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Sorties</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(activeSession.totals.total_out) }}</dd></div>
                            <div class="border-s border-gray-200 px-3 py-3 dark:border-gray-900"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Net encaissé</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(activeSession.totals.net_total) }}</dd></div>
                            <div class="col-span-2 border-t border-gray-200 px-3 py-3 dark:border-gray-900 xl:col-span-1 xl:border-s xl:border-t-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Espèces attendues</dt><dd class="mt-1 text-base font-bold text-slate-800 dark:text-white">{{ formatMoney(activeSession.totals.expected_cash) }}</dd></div>
                        </dl>

                        <div v-if="sessionIsLocked" class="mt-4 border-s-4 border-amber-500 bg-amber-50/70 px-4 py-3 dark:bg-amber-950/20">
                            <p class="text-sm font-bold text-amber-800 dark:text-amber-200">Encaissements suspendus par {{ activeSession.locked_by }}</p>
                            <p class="mt-1 text-xs leading-5 text-amber-700 dark:text-amber-300">{{ activeSession.lock_reason }} · {{ formatDateTime(activeSession.locked_at) }}</p>
                        </div>
                    </div>

                    <aside class="p-5">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Contrôle de session</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">Le verrouillage est réversible. La clôture libère définitivement la session après comptage des espèces — c'est la seule façon de mettre fin à la garde d'un titulaire absent.</p>
                        <div v-if="activeSession" class="mt-4 space-y-2">
                            <Button v-if="!sessionIsLocked && can('cash_registers.lock')" block size="rg" variant="warning" type="button" @click="openDialog('lock')"><Icon class="text-lg" name="lock" /><span class="ms-2">Verrouiller la session</span></Button>
                            <Button v-if="sessionIsLocked && can('cash_registers.unlock')" block size="rg" variant="primary" type="button" @click="openDialog('unlock')"><Icon class="text-lg" name="unlock" /><span class="ms-2">Déverrouiller la session</span></Button>
                            <Button v-if="can('cash_registers.close')" block size="rg" variant="danger-outline" type="button" @click="openDialog('close')"><Icon class="text-lg" name="check" /><span class="ms-2">Clôturer avec comptage</span></Button>
                        </div>
                        <dl class="mt-5 divide-y divide-gray-200 border-t border-gray-200 text-xs dark:divide-gray-900 dark:border-gray-900">
                            <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-400">Code du site</dt><dd class="font-bold text-slate-600 dark:text-slate-200">{{ targetSite.code }}</dd></div>
                            <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-400">Référentiel</dt><dd class="font-bold text-slate-600 dark:text-slate-200">{{ profile.register.active ? 'Actif' : 'Inactif' }}</dd></div>
                            <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-400">Mouvements cumulés</dt><dd class="font-bold text-slate-600 dark:text-slate-200">{{ formatMoney(profile.lifetime.net_total) }}</dd></div>
                        </dl>
                    </aside>
                </div>
            </section>

            <section class="overflow-hidden border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <header class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-1 rounded bg-gray-100 p-1 dark:bg-gray-1000">
                        <button type="button" :class="['rounded px-3 py-1.5 text-xs font-bold transition-colors', historyTab === 'movements' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-950 dark:text-primary-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200']" @click="historyTab = 'movements'">
                            Mouvements <span class="ms-1 text-slate-400">({{ profile.movements.length }})</span>
                        </button>
                        <button type="button" :class="['rounded px-3 py-1.5 text-xs font-bold transition-colors', historyTab === 'sessions' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-950 dark:text-primary-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200']" @click="historyTab = 'sessions'">
                            Historique <span class="ms-1 text-slate-400">({{ profile.recent_sessions.length }})</span>
                        </button>
                    </div>
                    <div class="flex items-center gap-3">
                        <p class="text-xs text-slate-400">{{ historyTab === 'movements' ? `${focusSession?.session_number ?? 'Aucune session'} · 50 opérations récentes au maximum` : 'Ouvertures, clôtures, comptages et écarts des 12 dernières sessions.' }}</p>
                        <Button v-if="can('cash_registers.export')" as="a" :href="exportUrl" size="sm" variant="white-outline"><Icon class="text-base" name="download" /><span class="ms-2">Exporter Excel</span></Button>
                    </div>
                </header>

                <div v-if="historyTab === 'movements'" class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-start text-sm">
                        <thead class="bg-gray-50 text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:bg-gray-1000"><tr><th class="px-5 py-3 text-start">Date / heure</th><th class="px-4 py-3 text-start">Opération</th><th class="px-4 py-3 text-start">Référence</th><th class="px-4 py-3 text-start">Mode</th><th class="px-4 py-3 text-start">Agent</th><th class="px-5 py-3 text-end">Montant</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                            <tr v-for="movement in profile.movements" :key="movement.uuid">
                                <td class="whitespace-nowrap px-5 py-3 text-xs text-slate-500">{{ formatDateTime(movement.occurred_at) }}</td>
                                <td class="px-4 py-3"><p class="font-bold text-slate-700 dark:text-white">{{ movement.description }}</p><p class="mt-0.5 text-[11px] uppercase text-slate-400">{{ movement.type }}</p></td>
                                <td class="px-4 py-3 text-xs font-medium text-slate-500">{{ movement.payment_number ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ movement.payment_method ?? 'Non renseigné' }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ movement.recorded_by }}</td>
                                <td :class="['whitespace-nowrap px-5 py-3 text-end font-bold', movement.direction === 'OUT' ? 'text-red-600' : 'text-slate-700 dark:text-white']">{{ movement.direction === 'OUT' ? '−' : '+' }} {{ formatMoney(movement.amount) }}</td>
                            </tr>
                            <tr v-if="profile.movements.length === 0"><td colspan="6" class="px-5 py-10 text-center text-sm text-slate-400">Aucun mouvement enregistré pour cette session.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[1000px] text-sm">
                        <thead class="bg-gray-50 text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:bg-gray-1000"><tr><th class="px-5 py-3 text-start">Session</th><th class="px-4 py-3 text-start">Ouverture</th><th class="px-4 py-3 text-start">Clôture</th><th class="px-4 py-3 text-end">Fond initial</th><th class="px-4 py-3 text-end">Compté</th><th class="px-5 py-3 text-end">Écart</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                            <tr v-for="session in profile.recent_sessions" :key="session.uuid">
                                <td class="px-5 py-3"><p class="font-bold text-slate-700 dark:text-white">{{ session.session_number }}</p><span :class="['mt-1 inline-block border-s-2 ps-2 text-[10px] font-bold uppercase tracking-wide', statusClass(session.status)]">{{ statusLabel(session.status) }}</span></td>
                                <td class="px-4 py-3"><p class="text-xs font-bold text-slate-600 dark:text-slate-200">{{ session.opened_by ?? 'Sans titulaire' }}</p><p class="mt-0.5 text-[11px] text-slate-400">{{ formatDateTime(session.opened_at) }}</p></td>
                                <td class="px-4 py-3"><p class="text-xs font-bold text-slate-600 dark:text-slate-200">{{ session.closed_by ?? '—' }}</p><p class="mt-0.5 text-[11px] text-slate-400">{{ formatDateTime(session.closed_at) ?? 'Session en cours' }}</p></td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-xs font-medium text-slate-600 dark:text-slate-200">{{ formatMoney(session.opening_amount) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-xs font-medium text-slate-600 dark:text-slate-200">{{ session.actual_closing_amount === null ? '—' : formatMoney(session.actual_closing_amount) }}</td>
                                <td :class="['whitespace-nowrap px-5 py-3 text-end text-xs font-bold', Number(session.variance_amount) === 0 ? 'text-slate-500' : 'text-amber-700 dark:text-amber-300']">{{ session.variance_amount === null ? '—' : formatMoney(session.variance_amount) }}</td>
                            </tr>
                            <tr v-if="profile.recent_sessions.length === 0"><td colspan="6" class="px-5 py-10 text-center text-sm text-slate-400">Aucune session enregistrée.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <div v-if="dialog && activeSession" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" @click.self="closeDialog">
            <form v-if="dialog === 'lock'" class="w-full max-w-lg overflow-hidden border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" @submit.prevent="submitLock">
                <header class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="font-heading text-lg font-bold text-slate-700 dark:text-white">Verrouiller {{ activeSession.session_number }}</h2><p class="mt-1 text-sm text-slate-500">Les paiements seront suspendus, mais la session restera ouverte et conservera ses montants.</p></header>
                <div class="p-5"><label for="lock_reason" class="mb-1.5 block text-sm font-bold text-slate-700 dark:text-white">Motif du verrouillage <span class="font-normal text-slate-400">(pré-rempli, modifiable)</span></label><textarea id="lock_reason" v-model="lockForm.reason" rows="4" required class="w-full border border-gray-200 bg-white px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Incident, contrôle ou mesure de sécurité…"></textarea><FormError v-if="lockForm.errors.reason">{{ lockForm.errors.reason }}</FormError><FormError v-if="lockForm.errors.cash_session">{{ lockForm.errors.cash_session }}</FormError></div>
                <footer class="flex justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-900"><Button size="rg" variant="white-outline" type="button" @click="closeDialog">Annuler</Button><Button size="rg" variant="warning" :disabled="lockForm.processing"><Icon name="lock" /><span class="ms-2">Confirmer le verrouillage</span></Button></footer>
            </form>

            <form v-else-if="dialog === 'unlock'" class="w-full max-w-lg overflow-hidden border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" @submit.prevent="submitUnlock">
                <header class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="font-heading text-lg font-bold text-slate-700 dark:text-white">Déverrouiller {{ activeSession.session_number }}</h2><p class="mt-1 text-sm text-slate-500">La même session reprendra et les encaissements redeviendront possibles.</p></header>
                <div class="p-5"><label for="unlock_reason" class="mb-1.5 block text-sm font-bold text-slate-700 dark:text-white">Motif de la reprise <span class="font-normal text-slate-400">(pré-rempli, modifiable)</span></label><textarea id="unlock_reason" v-model="unlockForm.reason" rows="4" required class="w-full border border-gray-200 bg-white px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Contrôle terminé, reprise autorisée…"></textarea><FormError v-if="unlockForm.errors.reason">{{ unlockForm.errors.reason }}</FormError><FormError v-if="unlockForm.errors.cash_session">{{ unlockForm.errors.cash_session }}</FormError></div>
                <footer class="flex justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-900"><Button size="rg" variant="white-outline" type="button" @click="closeDialog">Annuler</Button><Button size="rg" variant="primary" :disabled="unlockForm.processing"><Icon name="unlock" /><span class="ms-2">Autoriser la reprise</span></Button></footer>
            </form>

            <form v-else class="w-full max-w-xl overflow-hidden border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" @submit.prevent="submitClose">
                <header class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="font-heading text-lg font-bold text-slate-700 dark:text-white">Clôturer définitivement {{ activeSession.session_number }}</h2><p class="mt-1 text-sm text-slate-500">Cette action libère la caisse. Le montant attendu sera recalculé par le site au moment de la clôture.</p></header>
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <div><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Espèces attendues actuellement</p><p class="mt-1 text-lg font-bold text-slate-700 dark:text-white">{{ formatMoney(expectedCash) }}</p><label for="closing_amount" class="mb-1.5 mt-4 block text-sm font-bold text-slate-700 dark:text-white">Espèces réellement comptées <span class="text-red-500">*</span></label><input id="closing_amount" v-model="closeForm.actual_closing_amount" type="number" min="0" step="0.01" required class="h-11 w-full border border-gray-200 bg-white px-3 text-sm font-bold outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><FormError v-if="closeForm.errors.actual_closing_amount">{{ closeForm.errors.actual_closing_amount }}</FormError><p v-if="closeVariance !== null" :class="['mt-2 text-xs font-bold', closeVariance === 0 ? 'text-emerald-700' : 'text-amber-700']">Écart prévisionnel : {{ closeVariance > 0 ? '+' : '' }}{{ formatMoney(closeVariance) }}</p></div>
                    <div><label for="closing_reason" class="mb-1.5 block text-sm font-bold text-slate-700 dark:text-white">Motif de clôture centrale <span class="font-normal text-slate-400">(pré-rempli, modifiable)</span></label><textarea id="closing_reason" v-model="closeForm.reason" rows="7" required class="w-full border border-gray-200 bg-white px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Précisez la raison et tout incident constaté…"></textarea><FormError v-if="closeForm.errors.reason">{{ closeForm.errors.reason }}</FormError><FormError v-if="closeForm.errors.cash_session">{{ closeForm.errors.cash_session }}</FormError></div>
                </div>
                <footer class="flex justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-900"><Button size="rg" variant="white-outline" type="button" @click="closeDialog">Annuler</Button><Button size="rg" variant="danger" :disabled="closeForm.processing"><Icon name="check" /><span class="ms-2">Clôturer la session</span></Button></footer>
            </form>
        </div>
    </div>
</template>
