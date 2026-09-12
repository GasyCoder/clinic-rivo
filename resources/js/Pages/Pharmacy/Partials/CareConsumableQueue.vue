<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { formatDateTime, formatRelativeTime } from '@/utilities/date';

const props = defineProps({
    careConsumables: { type: Object, required: true },
    capabilities: { type: Object, required: true },
    search: { type: String, default: '' },
});

const STATUS_TONES = {
    PENDING: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    PARTIALLY_SERVED: 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/30 dark:text-primary-300',
    SERVED: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300',
    CANCELLED: 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-400',
};
const statusTone = (status) => STATUS_TONES[status] ?? STATUS_TONES.CANCELLED;

const requests = computed(() => {
    const term = props.search.trim().toLowerCase();

    if (!term) {
        return props.careConsumables.requests ?? [];
    }

    return (props.careConsumables.requests ?? []).filter((request) => [
        request.request_number,
        request.episode?.patient_name,
        request.episode?.patient_number,
        request.episode?.episode_number,
        ...request.lines.map((line) => `${line.name} ${line.code}`),
    ].filter(Boolean).join(' ').toLowerCase().includes(term));
});

const toServe = computed(() => requests.value.filter((request) => request.can_be_served));
const history = computed(() => requests.value.filter((request) => !request.can_be_served));

// One request is served at a time: the pharmacist confirms the quantities
// actually taken off the shelf, which may be less than declared when a lot
// is short — never silently.
const serveTarget = ref(null);
const serveLines = ref([]);
const serveProcessing = ref(false);
const serveError = ref('');

const openServeDialog = (request) => {
    serveTarget.value = request;
    serveError.value = '';
    serveLines.value = request.lines
        .filter((line) => line.remaining_quantity > 0)
        .map((line) => ({
            uuid: line.uuid,
            name: line.name,
            code: line.code,
            unit: line.unit,
            remaining_quantity: line.remaining_quantity,
            quantity: line.remaining_quantity,
        }));
};
const closeServeDialog = () => {
    if (serveProcessing.value) return;
    serveTarget.value = null;
};
const confirmServe = () => {
    const lines = serveLines.value
        .map((line) => ({ uuid: line.uuid, quantity: Number(line.quantity) }))
        .filter((line) => line.quantity > 0);

    if (lines.length === 0) {
        serveError.value = 'Indiquez au moins une quantité à sortir du stock.';
        return;
    }

    const excessive = serveLines.value.find(
        (line) => Number(line.quantity) > line.remaining_quantity,
    );

    if (excessive) {
        serveError.value = `La quantité de ${excessive.name} dépasse le reliquat de la demande.`;
        return;
    }

    router.post(
        `/pharmacy/care-consumables/${serveTarget.value.uuid}/serve`,
        { lines },
        {
            preserveScroll: true,
            onStart: () => { serveProcessing.value = true; serveError.value = ''; },
            onSuccess: () => { serveTarget.value = null; },
            onError: (errors) => {
                serveError.value = errors.lines || errors.request || 'La sortie de stock a été refusée.';
            },
            onFinish: () => { serveProcessing.value = false; },
        },
    );
};
</script>

<template>
    <div class="space-y-5 p-5">
        <div class="flex items-start gap-3 rounded-lg border border-primary-200 bg-primary-50/60 px-4 py-3 text-primary-900 dark:border-primary-900 dark:bg-primary-950/20 dark:text-primary-100">
            <Icon name="info" class="mt-0.5 shrink-0 text-lg" />
            <p class="text-xs leading-5">
                Ces consommables ont <strong>déjà été utilisés</strong> sur le patient aux Soins : la sortie de stock ne dépend donc d’aucun règlement.
                La part patient est facturée séparément sur le passage et encaissée uniquement par la Réception&nbsp;/&nbsp;Caisse.
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                <p class="text-xl font-bold leading-none text-slate-700 dark:text-white">{{ careConsumables.summary.pending ?? 0 }}</p>
                <p class="mt-1 text-xs text-slate-400">Demandes à servir</p>
            </div>
            <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                <p class="text-xl font-bold leading-none text-slate-700 dark:text-white">{{ careConsumables.summary.partially_served ?? 0 }}</p>
                <p class="mt-1 text-xs text-slate-400">Servies partiellement</p>
            </div>
            <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                <p class="text-xl font-bold leading-none text-slate-700 dark:text-white">{{ careConsumables.summary.lines_to_serve ?? 0 }}</p>
                <p class="mt-1 text-xs text-slate-400">Unités restant à sortir</p>
            </div>
        </div>

        <section>
            <h2 class="mb-3 text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">À servir</h2>
            <div v-if="toServe.length" class="space-y-3">
                <article
                    v-for="request in toServe"
                    :key="request.uuid"
                    class="rounded-lg border border-s-2 border-gray-200 border-s-amber-500 bg-white p-4 dark:border-gray-800 dark:bg-gray-950"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-bold text-slate-700 dark:text-white">{{ request.request_number }}</span>
                                <span :class="['inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', statusTone(request.status)]">{{ request.status_label }}</span>
                            </div>
                            <p class="mt-1 text-sm font-bold text-slate-700 dark:text-white">
                                {{ request.episode?.patient_name || 'Patient interne' }}
                                <span class="font-mono text-xs font-normal text-slate-400">{{ request.episode?.patient_number }}</span>
                            </p>
                            <p class="mt-0.5 text-[11px] text-slate-400">
                                Passage {{ request.episode?.episode_number || '—' }} · déclaré par {{ request.requested_by || '—' }}
                                <span :title="formatDateTime(request.requested_at)"> · {{ formatRelativeTime(request.requested_at) }}</span>
                            </p>
                            <p v-if="request.notes" class="mt-1 text-xs text-slate-500 dark:text-slate-300">{{ request.notes }}</p>
                        </div>
                        <Button
                            v-if="capabilities.can_serve_care_consumables"
                            type="button"
                            size="rg"
                            variant="primary"
                            @click="openServeDialog(request)"
                        >
                            <Icon name="truck" /><span class="ms-2">Servir et sortir le stock</span>
                        </Button>
                    </div>

                    <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                        <li v-for="line in request.lines" :key="line.uuid" class="rounded border border-gray-200 px-3 py-2 dark:border-gray-800">
                            <p class="text-xs font-semibold text-slate-700 dark:text-white">{{ line.name }}</p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-slate-400">
                                <span class="font-mono">{{ line.code }}</span>
                                <span><strong class="text-slate-600 dark:text-slate-300">{{ line.remaining_quantity }}</strong> {{ line.unit }} à sortir</span>
                                <span v-if="line.quantity_served">déjà sortis : {{ line.quantity_served }}</span>
                            </p>
                        </li>
                    </ul>
                </article>
            </div>
            <div v-else class="rounded-lg border border-dashed border-gray-200 px-4 py-10 text-center dark:border-gray-800">
                <Icon class="text-2xl text-slate-300" name="check-circle" />
                <p class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-300">Aucune demande en attente</p>
                <p class="mt-1 text-xs text-slate-400">Les Soins n’ont déclaré aucun consommable à sortir du stock.</p>
            </div>
        </section>

        <section v-if="history.length">
            <h2 class="mb-3 text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Historique récent</h2>
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                <table class="w-full min-w-[820px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40">
                        <tr>
                            <th class="px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Demande</th>
                            <th class="px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Patient</th>
                            <th class="px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Consommables</th>
                            <th class="px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Statut</th>
                            <th class="px-4 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400">Traitée</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="request in history" :key="request.uuid">
                            <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{{ request.request_number }}</td>
                            <td class="px-4 py-3">
                                <span class="block text-sm font-semibold text-slate-700 dark:text-white">{{ request.episode?.patient_name || 'Patient interne' }}</span>
                                <span class="font-mono text-[11px] text-slate-400">{{ request.episode?.patient_number }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <ul class="space-y-0.5">
                                    <li v-for="line in request.lines" :key="line.uuid" class="text-xs text-slate-500 dark:text-slate-300">
                                        {{ line.name }} — {{ line.quantity_served }}/{{ line.quantity_requested }} {{ line.unit }}
                                        <span v-if="line.allocations.length" class="font-mono text-[10px] text-slate-400">
                                            (lot {{ line.allocations.map((allocation) => allocation.lot_number).join(', ') }})
                                        </span>
                                    </li>
                                </ul>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', statusTone(request.status)]">{{ request.status_label }}</span>
                                <span v-if="request.cancellation_reason" class="mt-1 block max-w-xs text-[11px] text-slate-400">{{ request.cancellation_reason }}</span>
                            </td>
                            <td class="px-4 py-3 text-end text-xs text-slate-500 dark:text-slate-300">
                                <span v-if="request.served_at">{{ formatDateTime(request.served_at) }}<span class="block text-[11px] text-slate-400">{{ request.served_by }}</span></span>
                                <span v-else-if="request.cancelled_at">{{ formatDateTime(request.cancelled_at) }}<span class="block text-[11px] text-slate-400">{{ request.cancelled_by }}</span></span>
                                <span v-else>—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div
            v-if="serveTarget"
            class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4"
            role="presentation"
            @click.self="closeServeDialog"
        >
            <section class="w-full max-w-lg rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="serve-consumables-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300"><Icon class="text-xl" name="truck" /></span>
                    <div class="min-w-0">
                        <h2 id="serve-consumables-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Servir {{ serveTarget.request_number }}</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500">
                            Confirmez les quantités réellement sorties. L’allocation suit l’ordre FEFO et n’entame jamais une quantité déjà réservée.
                        </p>
                    </div>
                </div>

                <ul class="mt-5 space-y-3">
                    <li v-for="line in serveLines" :key="line.uuid" class="rounded border border-gray-200 p-3 dark:border-gray-800">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold text-slate-700 dark:text-white">{{ line.name }}</p>
                                <p class="mt-0.5 font-mono text-[10px] text-slate-400">{{ line.code }} · reliquat {{ line.remaining_quantity }} {{ line.unit }}</p>
                            </div>
                            <div class="shrink-0">
                                <label :for="`serve-${line.uuid}`" class="mb-1 block text-end text-[11px] text-slate-400">Quantité</label>
                                <Input :id="`serve-${line.uuid}`" v-model="line.quantity" class="w-24 text-center" type="number" min="0" :max="line.remaining_quantity" step="1" />
                            </div>
                        </div>
                    </li>
                </ul>

                <FormError class="mt-3" :message="serveError" />

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" :disabled="serveProcessing" @click="closeServeDialog">Annuler</Button>
                    <Button size="rg" variant="primary" type="button" :disabled="serveProcessing" @click="confirmServe">
                        <Icon class="text-lg" name="check" />
                        <span class="ms-2">{{ serveProcessing ? 'Sortie en cours…' : 'Confirmer la sortie de stock' }}</span>
                    </Button>
                </div>
            </section>
        </div>
    </div>
</template>
