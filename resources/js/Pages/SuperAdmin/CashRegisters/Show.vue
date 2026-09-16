<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import { ArrowLeft, Check, Download, Lock, LockOpen } from 'lucide-vue-next';
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
    CLOSED: 'border-border text-muted-foreground',
}[status] ?? 'border-input text-muted-foreground');

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
        <header class="flex flex-col gap-4 border-b border-border pb-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <p class="mb-1 text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Super Administration · {{ targetSite.name }}</p>
                <h1 class="truncate font-heading text-2xl font-bold text-foreground">{{ profile?.register.name ?? 'Fiche de caisse' }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">Supervision opérationnelle et historique financier transmis par l’API du site.</p>
            </div>
            <Button :as="Link" href="/super-admin/cash-registers" size="rg" variant="white-outline">
                <ArrowLeft class="h-4.5 w-4.5" />Retour aux caisses
            </Button>
        </header>

        <section v-if="!apiState.ok" class="border-s-4 border-red-500 bg-card px-5 py-6 shadow-sm">
            <p class="text-sm font-bold text-foreground">Le site {{ targetSite.name }} ne peut pas transmettre cette fiche.</p>
            <p class="mt-1 text-sm text-muted-foreground">{{ apiState.message }}</p>
        </section>

        <template v-else-if="profile">
            <section class="overflow-hidden border border-border bg-card shadow-sm">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_310px]">
                    <div class="border-b border-border p-5 lg:border-b-0 lg:border-e">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Session en cours</p>
                                <template v-if="activeSession">
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                                        <h2 class="font-heading text-xl font-bold text-foreground">{{ activeSession.session_number }}</h2>
                                        <span :class="['border-s-2 ps-2 text-xs font-bold uppercase tracking-wide', statusClass(activeSession.status)]">{{ statusLabel(activeSession.status) }}</span>
                                    </div>
                                    <p class="mt-2 text-sm text-muted-foreground">Ouverte par <strong class="text-foreground">{{ activeSession.opened_by }}</strong> le {{ formatDateTime(activeSession.opened_at) }}</p>
                                </template>
                                <template v-else>
                                    <h2 class="mt-2 font-heading text-xl font-bold text-foreground">Aucune session active</h2>
                                    <p class="mt-1 text-sm text-muted-foreground">Cette caisse ne peut être ni verrouillée ni clôturée actuellement.</p>
                                </template>
                            </div>
                            <p class="text-xs font-medium text-muted-foreground">{{ profile.register.sessions_count }} session{{ profile.register.sessions_count > 1 ? 's' : '' }} enregistrée{{ profile.register.sessions_count > 1 ? 's' : '' }}</p>
                        </div>

                        <dl v-if="activeSession" class="mt-5 grid grid-cols-2 border-y border-border xl:grid-cols-5">
                            <div class="px-3 py-3 first:ps-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Fond initial</dt><dd class="mt-1 text-base font-bold text-foreground">{{ formatMoney(activeSession.opening_amount) }}</dd></div>
                            <div class="border-s border-border px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Entrées</dt><dd class="mt-1 text-base font-bold text-foreground">{{ formatMoney(activeSession.totals.total_in) }}</dd></div>
                            <div class="border-s border-border px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Sorties</dt><dd class="mt-1 text-base font-bold text-foreground">{{ formatMoney(activeSession.totals.total_out) }}</dd></div>
                            <div class="border-s border-border px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Net encaissé</dt><dd class="mt-1 text-base font-bold text-foreground">{{ formatMoney(activeSession.totals.net_total) }}</dd></div>
                            <div class="col-span-2 border-t border-border px-3 py-3 xl:col-span-1 xl:border-s xl:border-t-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Espèces attendues</dt><dd class="mt-1 text-base font-bold text-foreground">{{ formatMoney(activeSession.totals.expected_cash) }}</dd></div>
                        </dl>

                        <div v-if="sessionIsLocked" class="mt-4 border-s-4 border-amber-500 bg-amber-50/70 px-4 py-3 dark:bg-amber-950/20">
                            <p class="text-sm font-bold text-amber-800 dark:text-amber-200">Encaissements suspendus par {{ activeSession.locked_by }}</p>
                            <p class="mt-1 text-xs leading-5 text-amber-700 dark:text-amber-300">{{ activeSession.lock_reason }} · {{ formatDateTime(activeSession.locked_at) }}</p>
                        </div>
                    </div>

                    <aside class="p-5">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Contrôle de session</p>
                        <p class="mt-2 text-xs leading-5 text-muted-foreground">Le verrouillage est réversible. La clôture libère définitivement la session après comptage des espèces — c'est la seule façon de mettre fin à la garde d'un titulaire absent.</p>
                        <div v-if="activeSession" class="mt-4 space-y-2">
                            <Button v-if="!sessionIsLocked && can('cash_registers.lock')" block size="rg" variant="warning" type="button" @click="openDialog('lock')"><Lock class="h-4.5 w-4.5" />Verrouiller la session</Button>
                            <Button v-if="sessionIsLocked && can('cash_registers.unlock')" block size="rg" variant="primary" type="button" @click="openDialog('unlock')"><LockOpen class="h-4.5 w-4.5" />Déverrouiller la session</Button>
                            <Button v-if="can('cash_registers.close')" block size="rg" variant="danger-outline" type="button" @click="openDialog('close')"><Check class="h-4.5 w-4.5" />Clôturer avec comptage</Button>
                        </div>
                        <dl class="mt-5 divide-y divide-border border-t border-border text-xs">
                            <div class="flex justify-between gap-3 py-2.5"><dt class="text-muted-foreground">Code du site</dt><dd class="font-bold text-muted-foreground">{{ targetSite.code }}</dd></div>
                            <div class="flex justify-between gap-3 py-2.5"><dt class="text-muted-foreground">Référentiel</dt><dd class="font-bold text-muted-foreground">{{ profile.register.active ? 'Actif' : 'Inactif' }}</dd></div>
                            <div class="flex justify-between gap-3 py-2.5"><dt class="text-muted-foreground">Mouvements cumulés</dt><dd class="font-bold text-muted-foreground">{{ formatMoney(profile.lifetime.net_total) }}</dd></div>
                        </dl>
                    </aside>
                </div>
            </section>

            <section class="overflow-hidden border border-border bg-card shadow-sm">
                <header class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-1 rounded bg-muted p-1">
                        <button type="button" :class="['rounded px-3 py-1.5 text-xs font-bold transition-colors', historyTab === 'movements' ? 'bg-card text-primary shadow-sm ' : 'text-muted-foreground hover:text-foreground dark:text-muted-foreground dark:hover:text-muted-foreground']" @click="historyTab = 'movements'">
                            Mouvements <span class="ms-1 text-muted-foreground">({{ profile.movements.length }})</span>
                        </button>
                        <button type="button" :class="['rounded px-3 py-1.5 text-xs font-bold transition-colors', historyTab === 'sessions' ? 'bg-card text-primary shadow-sm ' : 'text-muted-foreground hover:text-foreground dark:text-muted-foreground dark:hover:text-muted-foreground']" @click="historyTab = 'sessions'">
                            Historique <span class="ms-1 text-muted-foreground">({{ profile.recent_sessions.length }})</span>
                        </button>
                    </div>
                    <div class="flex items-center gap-3">
                        <p class="text-xs text-muted-foreground">{{ historyTab === 'movements' ? `${focusSession?.session_number ?? 'Aucune session'} · 50 opérations récentes au maximum` : 'Ouvertures, clôtures, comptages et écarts des 12 dernières sessions.' }}</p>
                        <Button v-if="can('cash_registers.export')" as="a" :href="exportUrl" size="sm" variant="white-outline"><Download class="h-4 w-4" />Exporter Excel</Button>
                    </div>
                </header>

                <div v-if="historyTab === 'movements'" class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-start text-sm">
                        <thead class="bg-muted text-[10px] font-bold uppercase tracking-wide text-muted-foreground"><tr><th class="px-5 py-3 text-start">Date / heure</th><th class="px-4 py-3 text-start">Opération</th><th class="px-4 py-3 text-start">Référence</th><th class="px-4 py-3 text-start">Mode</th><th class="px-4 py-3 text-start">Agent</th><th class="px-5 py-3 text-end">Montant</th></tr></thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="movement in profile.movements" :key="movement.uuid">
                                <td class="whitespace-nowrap px-5 py-3 text-xs text-muted-foreground">{{ formatDateTime(movement.occurred_at) }}</td>
                                <td class="px-4 py-3"><p class="font-bold text-foreground">{{ movement.description }}</p><p class="mt-0.5 text-[11px] uppercase text-muted-foreground">{{ movement.type }}</p></td>
                                <td class="px-4 py-3 text-xs font-medium text-muted-foreground">{{ movement.payment_number ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">{{ movement.payment_method ?? 'Non renseigné' }}</td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">{{ movement.recorded_by }}</td>
                                <td :class="['whitespace-nowrap px-5 py-3 text-end font-bold', movement.direction === 'OUT' ? 'text-red-600' : 'text-foreground']">{{ movement.direction === 'OUT' ? '−' : '+' }} {{ formatMoney(movement.amount) }}</td>
                            </tr>
                            <tr v-if="profile.movements.length === 0"><td colspan="6" class="px-5 py-10 text-center text-sm text-muted-foreground">Aucun mouvement enregistré pour cette session.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[1000px] text-sm">
                        <thead class="bg-muted text-[10px] font-bold uppercase tracking-wide text-muted-foreground"><tr><th class="px-5 py-3 text-start">Session</th><th class="px-4 py-3 text-start">Ouverture</th><th class="px-4 py-3 text-start">Clôture</th><th class="px-4 py-3 text-end">Fond initial</th><th class="px-4 py-3 text-end">Compté</th><th class="px-5 py-3 text-end">Écart</th></tr></thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="session in profile.recent_sessions" :key="session.uuid">
                                <td class="px-5 py-3"><p class="font-bold text-foreground">{{ session.session_number }}</p><span :class="['mt-1 inline-block border-s-2 ps-2 text-[10px] font-bold uppercase tracking-wide', statusClass(session.status)]">{{ statusLabel(session.status) }}</span></td>
                                <td class="px-4 py-3"><p class="text-xs font-bold text-muted-foreground">{{ session.opened_by ?? 'Sans titulaire' }}</p><p class="mt-0.5 text-[11px] text-muted-foreground">{{ formatDateTime(session.opened_at) }}</p></td>
                                <td class="px-4 py-3"><p class="text-xs font-bold text-muted-foreground">{{ session.closed_by ?? '—' }}</p><p class="mt-0.5 text-[11px] text-muted-foreground">{{ formatDateTime(session.closed_at) ?? 'Session en cours' }}</p></td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-xs font-medium text-muted-foreground">{{ formatMoney(session.opening_amount) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-xs font-medium text-muted-foreground">{{ session.actual_closing_amount === null ? '—' : formatMoney(session.actual_closing_amount) }}</td>
                                <td :class="['whitespace-nowrap px-5 py-3 text-end text-xs font-bold', Number(session.variance_amount) === 0 ? 'text-muted-foreground' : 'text-amber-700 dark:text-amber-300']">{{ session.variance_amount === null ? '—' : formatMoney(session.variance_amount) }}</td>
                            </tr>
                            <tr v-if="profile.recent_sessions.length === 0"><td colspan="6" class="px-5 py-10 text-center text-sm text-muted-foreground">Aucune session enregistrée.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <div v-if="dialog && activeSession" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" @click.self="closeDialog">
            <form v-if="dialog === 'lock'" class="w-full max-w-lg overflow-hidden border border-border bg-card shadow-xl" @submit.prevent="submitLock">
                <header class="border-b border-border px-5 py-4"><h2 class="font-heading text-lg font-bold text-foreground">Verrouiller {{ activeSession.session_number }}</h2><p class="mt-1 text-sm text-muted-foreground">Les paiements seront suspendus, mais la session restera ouverte et conservera ses montants.</p></header>
                <div class="p-5"><label for="lock_reason" class="mb-1.5 block text-sm font-bold text-foreground">Motif du verrouillage <span class="font-normal text-muted-foreground">(pré-rempli, modifiable)</span></label><textarea id="lock_reason" v-model="lockForm.reason" rows="4" required class="w-full border border-border bg-card px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-ring/25" placeholder="Incident, contrôle ou mesure de sécurité…"></textarea><FormError v-if="lockForm.errors.reason">{{ lockForm.errors.reason }}</FormError><FormError v-if="lockForm.errors.cash_session">{{ lockForm.errors.cash_session }}</FormError></div>
                <footer class="flex justify-end gap-2 border-t border-border px-5 py-4"><Button size="rg" variant="white-outline" type="button" @click="closeDialog">Annuler</Button><Button size="rg" variant="warning" :disabled="lockForm.processing"><Lock class="h-4 w-4" />Confirmer le verrouillage</Button></footer>
            </form>

            <form v-else-if="dialog === 'unlock'" class="w-full max-w-lg overflow-hidden border border-border bg-card shadow-xl" @submit.prevent="submitUnlock">
                <header class="border-b border-border px-5 py-4"><h2 class="font-heading text-lg font-bold text-foreground">Déverrouiller {{ activeSession.session_number }}</h2><p class="mt-1 text-sm text-muted-foreground">La même session reprendra et les encaissements redeviendront possibles.</p></header>
                <div class="p-5"><label for="unlock_reason" class="mb-1.5 block text-sm font-bold text-foreground">Motif de la reprise <span class="font-normal text-muted-foreground">(pré-rempli, modifiable)</span></label><textarea id="unlock_reason" v-model="unlockForm.reason" rows="4" required class="w-full border border-border bg-card px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-ring/25" placeholder="Contrôle terminé, reprise autorisée…"></textarea><FormError v-if="unlockForm.errors.reason">{{ unlockForm.errors.reason }}</FormError><FormError v-if="unlockForm.errors.cash_session">{{ unlockForm.errors.cash_session }}</FormError></div>
                <footer class="flex justify-end gap-2 border-t border-border px-5 py-4"><Button size="rg" variant="white-outline" type="button" @click="closeDialog">Annuler</Button><Button size="rg" variant="primary" :disabled="unlockForm.processing"><LockOpen class="h-4 w-4" />Autoriser la reprise</Button></footer>
            </form>

            <form v-else class="w-full max-w-xl overflow-hidden border border-border bg-card shadow-xl" @submit.prevent="submitClose">
                <header class="border-b border-border px-5 py-4"><h2 class="font-heading text-lg font-bold text-foreground">Clôturer définitivement {{ activeSession.session_number }}</h2><p class="mt-1 text-sm text-muted-foreground">Cette action libère la caisse. Le montant attendu sera recalculé par le site au moment de la clôture.</p></header>
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <div><p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Espèces attendues actuellement</p><p class="mt-1 text-lg font-bold text-foreground">{{ formatMoney(expectedCash) }}</p><label for="closing_amount" class="mb-1.5 mt-4 block text-sm font-bold text-foreground">Espèces réellement comptées <span class="text-red-500">*</span></label><input id="closing_amount" v-model="closeForm.actual_closing_amount" type="number" min="0" step="0.01" required class="h-11 w-full border border-border bg-card px-3 text-sm font-bold outline-none focus:border-primary focus:ring-2 focus:ring-ring/25"><FormError v-if="closeForm.errors.actual_closing_amount">{{ closeForm.errors.actual_closing_amount }}</FormError><p v-if="closeVariance !== null" :class="['mt-2 text-xs font-bold', closeVariance === 0 ? 'text-emerald-700' : 'text-amber-700']">Écart prévisionnel : {{ closeVariance > 0 ? '+' : '' }}{{ formatMoney(closeVariance) }}</p></div>
                    <div><label for="closing_reason" class="mb-1.5 block text-sm font-bold text-foreground">Motif de clôture centrale <span class="font-normal text-muted-foreground">(pré-rempli, modifiable)</span></label><textarea id="closing_reason" v-model="closeForm.reason" rows="7" required class="w-full border border-border bg-card px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-ring/25" placeholder="Précisez la raison et tout incident constaté…"></textarea><FormError v-if="closeForm.errors.reason">{{ closeForm.errors.reason }}</FormError><FormError v-if="closeForm.errors.cash_session">{{ closeForm.errors.cash_session }}</FormError></div>
                </div>
                <footer class="flex justify-end gap-2 border-t border-border px-5 py-4"><Button size="rg" variant="white-outline" type="button" @click="closeDialog">Annuler</Button><Button size="rg" variant="danger" :disabled="closeForm.processing"><Check class="h-4 w-4" />Clôturer la session</Button></footer>
            </form>
        </div>
    </div>
</template>
