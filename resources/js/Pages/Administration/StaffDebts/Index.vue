<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlarmClock, ArrowRight, Banknote, Gavel, HandCoins, Landmark, Search, Wallet } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import StaffDebtStatusBadge from '@/Components/StaffDebts/StaffDebtStatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, monthLabel } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';
import { DEBT_VIEWS } from '@/utilities/staffDebts';

/**
 * ADR-228 — les dettes du personnel, une rubrique RH servie aussi au portail (ADR-187) :
 * le DG décide les demandes, le RH verse ce qui est accordé et suit les remboursements.
 * Quatre vues exclusives, comptées par le serveur ; la carte est le filtre.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    listing: { type: Object, required: true },
    can: { type: Object, required: true },
});

const { can: allowed } = usePermissions();
const search = ref(props.listing.search ?? '');

const visit = (params) => router.get(hrUrl('/administration/dettes'), params, { preserveScroll: true, preserveState: true, replace: true });
const selectView = (view) => visit({ vue: view, q: search.value || undefined });

let timer = null;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => visit({ vue: props.listing.view, q: value || undefined }), 300);
});

const tiles = computed(() => DEBT_VIEWS.map((view) => ({
    value: view.key,
    label: view.label,
    hint: view.hint,
    icon: view.icon,
    tone: view.tone,
    count: props.listing.counts[view.key] ?? 0,
    active: props.listing.view === view.key,
})));

const summary = computed(() => [
    { key: 'balance', icon: Landmark, label: 'Reste dû, toutes dettes', value: formatMoney(props.listing.summary.balance) },
    { key: 'arrears', icon: AlarmClock, label: 'Retard (espèces)', value: formatMoney(props.listing.summary.arrears) },
]);

const actionFor = (debt) => {
    if (debt.status === 'REQUESTED' && props.can.decide) return { label: 'Décider', icon: Gavel, variant: 'default' };
    if (debt.status === 'APPROVED' && props.can.disburse) return { label: 'Verser', icon: Wallet, variant: 'default' };

    return { label: 'Ouvrir', icon: ArrowRight, variant: 'outline' };
};
</script>

<template>
    <Head title="Dettes du personnel" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines · Pilotage"
            title="Dettes du personnel"
            description="Demandées par le personnel depuis leur compte, décidées par le DG, versées hors RIVO par le RH, remboursées par retenue sur la paie ou en espèces à la Caisse."
            :icon="HandCoins"
        >
            <template #actions>
                <Button v-if="allowed('salary_payments.view')" :as="Link" :href="hrUrl('/administration/paie')" variant="outline"><Banknote class="h-4 w-4" />Paie du mois</Button>
            </template>
        </PageHeader>

        <QueueCounters :tiles="tiles" @select="selectView" />

        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-64 flex-1">
                <IconInput v-model="search" :icon="Search" type="search" placeholder="Nom, matricule ou numéro de dette" aria-label="Rechercher une dette" />
            </div>
            <div v-for="item in summary" :key="item.key" class="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm">
                <component :is="item.icon" class="h-4 w-4 text-muted-foreground" />
                <span class="text-muted-foreground">{{ item.label }}</span>
                <span class="font-semibold tabular-nums text-foreground">{{ item.value }}</span>
            </div>
        </div>

        <Card v-if="! listing.debts.length" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><HandCoins class="h-6 w-6" /></span>
            <p class="text-sm font-semibold text-foreground">{{ listing.search ? 'Aucune dette ne correspond à cette recherche' : 'Rien dans cette vue' }}</p>
            <p class="max-w-md text-sm text-muted-foreground">Le personnel demande une dette depuis « Mes dettes » ; le DG la décide ici, depuis le portail.</p>
        </Card>

        <Card v-else class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5">Personne</th>
                            <th class="px-4 py-2.5">État</th>
                            <th class="px-4 py-2.5 text-right">Montant</th>
                            <th class="px-4 py-2.5">Remboursement</th>
                            <th class="px-4 py-2.5 text-right">Reste dû</th>
                            <th class="px-4 py-2.5"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="debt in listing.debts" :key="debt.uuid" class="hover:bg-muted/30">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-foreground">{{ debt.employee_name }}</p>
                                <p class="text-xs text-muted-foreground">{{ [debt.number, debt.employee_number].filter(Boolean).join(' · ') }} · {{ formatDateTime(debt.requested_at) }}</p>
                            </td>
                            <td class="px-4 py-3"><StaffDebtStatusBadge :status="debt.status" :label="debt.status_label" :tone="debt.status_tone" /></td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <p class="font-semibold text-foreground">{{ formatMoney(debt.amount ?? debt.requested_amount) }}</p>
                                <p v-if="debt.amount && debt.amount !== debt.requested_amount" class="text-xs text-muted-foreground line-through">{{ formatMoney(debt.requested_amount) }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-foreground">{{ formatMoney(debt.installment_amount) }} / mois</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ debt.repayment_mode_label ?? 'Mode à décider' }}<template v-if="debt.next_period"> · <span class="capitalize">{{ monthLabel(debt.next_period) }}</span></template>
                                </p>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <p class="font-semibold text-foreground">{{ debt.status === 'ACTIVE' ? formatMoney(debt.balance) : '—' }}</p>
                                <p v-if="Number(debt.arrears) > 0" class="flex items-center justify-end gap-1 text-xs text-destructive"><AlarmClock class="h-3 w-3" />{{ formatMoney(debt.arrears) }} en retard</p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button :as="Link" :href="hrUrl(`/administration/dettes/${debt.uuid}`)" size="sm" :variant="actionFor(debt).variant">
                                    <component :is="actionFor(debt).icon" class="h-4 w-4" />{{ actionFor(debt).label }}
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="listing.debts.length >= 300" class="border-t border-border px-4 py-2 text-xs text-muted-foreground">Les 300 premières : précisez la recherche.</p>
        </Card>
    </div>
</template>
