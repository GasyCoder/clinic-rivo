<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

defineProps({
    cashSession: Object,
    summary: Object,
    recentPayments: Array,
    recentSessions: Array,
});

const page = usePage();
const { can } = usePermissions();
const status = computed(() => page.props.flash?.status);

const openForm = useForm({
    opening_amount: 0,
    notes: '',
});

const closeForm = useForm({
    actual_closing_amount: '',
    notes: '',
});

const openCash = () => openForm.post('/cash/open', { preserveScroll: true });
const closeCash = () => closeForm.post('/cash/close', { preserveScroll: true });
</script>

<template>
    <Head title="Caisse" />

    <div class="w-full space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                    <Icon class="text-2xl" name="wallet" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">Caisse</h1>
                    <p class="mt-0.5 text-sm text-slate-400">Session unique du site, encaissements et reçus.</p>
                </div>
            </div>

            <Button :as="Link" href="/patients" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" />
                <span class="ms-2">Patients & Caisse</span>
            </Button>
        </div>

        <div v-if="status" class="flex items-center gap-3 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300" role="status">
            <Icon class="text-lg" name="check-circle" />
            <span>{{ status }}</span>
        </div>

        <template v-if="cashSession">
            <Card class="overflow-hidden shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold text-slate-700 dark:text-white">Session {{ cashSession.session_number }}</h2>
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-bold text-green-700 dark:bg-green-950 dark:text-green-300">
                                <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Ouverte
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">Par {{ cashSession.opener.name }} · {{ formatDateTime(cashSession.opened_at) }}</p>
                    </div>
                    <p class="text-xs text-slate-400">Une seule caisse peut être ouverte sur ce site.</p>
                </div>

                <CardBody>
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <div class="rounded-md bg-slate-50 p-4 dark:bg-gray-1000">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Fond initial</p>
                            <p class="mt-2 text-lg font-bold text-slate-700 dark:text-white">{{ formatMoney(cashSession.opening_amount) }}</p>
                        </div>
                        <div class="rounded-md bg-primary-50 p-4 dark:bg-primary-950/30">
                            <p class="text-xs font-medium uppercase tracking-wide text-primary-500">Total encaissé</p>
                            <p class="mt-2 text-lg font-bold text-primary-700 dark:text-primary-300">{{ formatMoney(summary.total_collected) }}</p>
                        </div>
                        <div class="rounded-md bg-green-50 p-4 dark:bg-green-950/30">
                            <p class="text-xs font-medium uppercase tracking-wide text-green-600">Espèces encaissées</p>
                            <p class="mt-2 text-lg font-bold text-green-700 dark:text-green-300">{{ formatMoney(summary.cash_collected) }}</p>
                        </div>
                        <div class="rounded-md bg-yellow-50 p-4 dark:bg-yellow-950/30">
                            <p class="text-xs font-medium uppercase tracking-wide text-yellow-600">Espèces attendues</p>
                            <p class="mt-2 text-lg font-bold text-yellow-700 dark:text-yellow-300">{{ formatMoney(summary.expected_cash) }}</p>
                        </div>
                    </div>

                    <form v-if="can('cash.close')" class="mt-6 border-t border-gray-200 pt-5 dark:border-gray-900" @submit.prevent="closeCash">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(220px,0.5fr)_minmax(0,1fr)_auto] md:items-end">
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="actual_closing_amount">Espèces comptées <span class="text-red-500">*</span></FormLabel>
                                <Input id="actual_closing_amount" v-model="closeForm.actual_closing_amount" type="number" min="0" step="0.01" required />
                                <FormError v-if="closeForm.errors.actual_closing_amount">{{ closeForm.errors.actual_closing_amount }}</FormError>
                            </FormGroup>
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="close_notes">Note de clôture</FormLabel>
                                <Input id="close_notes" v-model="closeForm.notes" placeholder="Observation facultative" />
                                <FormError v-if="closeForm.errors.notes">{{ closeForm.errors.notes }}</FormError>
                            </FormGroup>
                            <Button size="rg" variant="secondary" type="submit" :disabled="closeForm.processing">
                                <Icon class="text-lg" name="lock" />
                                <span class="ms-2">{{ closeForm.processing ? 'Clôture…' : 'Clôturer la caisse' }}</span>
                            </Button>
                        </div>
                        <FormError v-if="closeForm.errors.cash_session">{{ closeForm.errors.cash_session }}</FormError>
                    </form>
                </CardBody>
            </Card>
        </template>

        <Card v-else class="shadow-sm">
            <CardBody class="mx-auto max-w-3xl py-8 sm:py-10">
                <div class="mb-6 flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-yellow-100 text-yellow-600 dark:bg-yellow-950 dark:text-yellow-300">
                        <Icon class="text-xl" name="wallet" />
                    </span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-700 dark:text-white">La caisse est fermée</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-400">Ouvrez une session avant tout encaissement. Le fond de caisse représente les espèces présentes au départ.</p>
                    </div>
                </div>

                <form v-if="can('cash.open')" class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(220px,0.55fr)_minmax(0,1fr)_auto] md:items-end" @submit.prevent="openCash">
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="opening_amount">Fond de caisse <span class="text-red-500">*</span></FormLabel>
                        <Input id="opening_amount" v-model="openForm.opening_amount" type="number" min="0" step="0.01" required />
                        <FormError v-if="openForm.errors.opening_amount">{{ openForm.errors.opening_amount }}</FormError>
                    </FormGroup>
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="open_notes">Note d’ouverture</FormLabel>
                        <Input id="open_notes" v-model="openForm.notes" placeholder="Observation facultative" />
                        <FormError v-if="openForm.errors.notes">{{ openForm.errors.notes }}</FormError>
                    </FormGroup>
                    <Button size="rg" variant="primary" type="submit" :disabled="openForm.processing">
                        <Icon class="text-lg" name="unlock" />
                        <span class="ms-2">{{ openForm.processing ? 'Ouverture…' : 'Ouvrir la caisse' }}</span>
                    </Button>
                    <FormError v-if="openForm.errors.cash_session" class="md:col-span-3">{{ openForm.errors.cash_session }}</FormError>
                </form>
            </CardBody>
        </Card>

        <Card class="overflow-hidden shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <div>
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Dernières opérations de paiement</h2>
                    <p class="mt-0.5 text-xs text-slate-400">Paiements et annulations conservés dans l’historique.</p>
                </div>
                <Icon class="text-xl text-primary-500" name="money" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40">
                        <tr>
                            <th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Patient</th>
                            <th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Facture</th>
                            <th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Date / heure</th>
                            <th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Mode</th>
                            <th class="px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400">Montant</th>
                            <th class="px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400">Reçu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="payment in recentPayments" :key="payment.uuid" class="hover:bg-gray-50 dark:hover:bg-gray-1000">
                            <td class="px-5 py-3">
                                <Link :href="`/patients/${payment.invoice.patient.uuid}`" class="text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ formatPatientName(payment.invoice.patient) }}</Link>
                                <span class="block text-xs text-slate-400">{{ payment.invoice.patient.patient_number }}</span>
                            </td>
                            <td class="px-5 py-3 text-sm text-slate-500">{{ payment.invoice.invoice_number }}</td>
                            <td class="px-5 py-3 text-sm text-slate-500">{{ formatDateTime(payment.paid_at) }}</td>
                            <td class="px-5 py-3 text-sm text-slate-500">{{ payment.method.name }}</td>
                            <td class="px-5 py-3 text-end text-sm">
                                <span :class="['font-bold', payment.status === 'CANCELLED' ? 'text-slate-400 line-through' : 'text-slate-700 dark:text-white']">{{ formatMoney(payment.amount) }}</span>
                                <span v-if="payment.status === 'CANCELLED'" class="ms-2 rounded border border-red-200 px-1.5 py-0.5 text-[11px] font-medium text-red-600 dark:border-red-900 dark:text-red-300">Annulé</span>
                            </td>
                            <td class="px-5 py-3 text-end">
                                <Link v-if="payment.receipt" :href="`/receipts/${payment.receipt.uuid}`" class="text-sm font-medium text-primary-600 hover:underline">{{ payment.receipt.receipt_number }}</Link>
                            </td>
                        </tr>
                        <tr v-if="recentPayments.length === 0">
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-slate-400">Aucun paiement enregistré.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card class="overflow-hidden shadow-sm">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <h2 class="text-sm font-bold text-slate-700 dark:text-white">Historique des sessions</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40">
                        <tr>
                            <th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Session</th>
                            <th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Ouverture</th>
                            <th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Clôture</th>
                            <th class="px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400">Attendu</th>
                            <th class="px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400">Écart</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="session in recentSessions" :key="session.uuid">
                            <td class="px-5 py-3 text-sm font-bold text-slate-700 dark:text-white">{{ session.session_number }}</td>
                            <td class="px-5 py-3 text-sm text-slate-500">{{ formatDateTime(session.opened_at) }} · {{ session.opener.name }}</td>
                            <td class="px-5 py-3 text-sm text-slate-500">{{ session.closed_at ? `${formatDateTime(session.closed_at)} · ${session.closer?.name}` : 'En cours' }}</td>
                            <td class="px-5 py-3 text-end text-sm text-slate-500">{{ session.expected_closing_amount == null ? '—' : formatMoney(session.expected_closing_amount) }}</td>
                            <td :class="['px-5 py-3 text-end text-sm font-bold', Number(session.variance_amount) === 0 ? 'text-green-600' : 'text-red-600']">{{ session.variance_amount == null ? '—' : formatMoney(session.variance_amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
