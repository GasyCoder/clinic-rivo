<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlarmClock, ArrowRight, Download, Plus, Gavel, HandCoins, Landmark, Lock, Percent, Scale, Search, Settings2, ShieldAlert, Wallet } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import StaffDebtStatusBadge from '@/Components/StaffDebts/StaffDebtStatusBadge.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import { formatDateTime, monthLabel } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { staffDebtContext, staffDebtUrl } from '@/utilities/staffDebtUrl';
import { DEBT_VIEWS } from '@/utilities/staffDebts';

/**
 * ADR-229 — les dettes du personnel d'un site, dans Finance au portail (servies par l'API
 * du site). Le DG décide les demandes, marque versé ce qu'il a accordé, suit les
 * remboursements, relance les retards ; il règle les limites et les intérêts du site et
 * exporte la liste. Quatre vues exclusives, comptées par le serveur ; la carte est le filtre.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    listing: { type: Object, required: true },
    rules: { type: Object, default: () => ({ configured: false, requests_open: true, amount_limits_set: false, has_interest: false }) },
    can: { type: Object, required: true },
});

const search = ref(props.listing.search ?? '');
const siteName = computed(() => staffDebtContext()?.site?.name ?? '');

const visit = (params) => router.get(staffDebtUrl('/finance/dettes'), params, { preserveScroll: true, preserveState: true, replace: true });
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

// L'export suit la vue et la recherche de l'écran ; il est audité par le site.
const exportUrl = computed(() => {
    const query = new URLSearchParams({ vue: props.listing.view });
    if (props.listing.search) query.set('q', props.listing.search);

    return `${staffDebtUrl('/finance/dettes/export')}?${query}`;
});
</script>

<template>
    <Head title="Dettes du personnel" />
    <div class="w-full space-y-5">
        <PageHeader
            :eyebrow="`Finance · ${siteName}`"
            title="Dettes du personnel"
            description="Demandées par le personnel depuis « Mes dettes », décidées et marquées versées ici (l’argent est remis hors RIVO), remboursées par retenue sur la paie ou en espèces à la Caisse."
            :icon="HandCoins"
        >
            <template #actions>
                <Button v-if="can.create" :as="Link" :href="staffDebtUrl('/finance/dettes/nouvelle')"><Plus class="h-4 w-4" />Nouvelle dette</Button>
                <Button v-if="can.export" :as="'a'" :href="exportUrl" variant="outline"><Download class="h-4 w-4" />Exporter en Excel</Button>
                <Button v-if="can.settings" :as="Link" :href="staffDebtUrl('/finance/dettes/reglages')" variant="outline"><Settings2 class="h-4 w-4" />Réglages</Button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <Badge v-if="! rules.requests_open" variant="destructive" class="gap-1.5"><Lock class="h-3.5 w-3.5" />Demandes fermées</Badge>
            <!-- ADR-229 (amendement du 2026-09-30) — sans minimum ni maximum, le personnel ne peut pas demander. -->
            <Badge v-else-if="! rules.amount_limits_set" variant="warning" class="gap-1.5"><Lock class="h-3.5 w-3.5" />Demandes fermées au personnel : montant minimum et maximum à régler</Badge>
            <Badge v-else variant="secondary" class="gap-1.5"><HandCoins class="h-3.5 w-3.5" />Demandes ouvertes</Badge>
            <Badge v-if="rules.amount_limits_set" variant="secondary" class="gap-1.5 tabular-nums"><Scale class="h-3.5 w-3.5" />De {{ formatMoney(rules.min_amount) }} à {{ formatMoney(rules.max_amount) }} par dette</Badge>
            <Badge variant="secondary" class="gap-1.5"><Percent class="h-3.5 w-3.5" />{{ rules.has_interest ? 'Intérêts par tranche' : 'Sans intérêt' }}</Badge>
            <Link v-if="can.settings" :href="staffDebtUrl('/finance/dettes/reglages')" class="text-xs font-semibold text-primary hover:underline">{{ rules.amount_limits_set ? 'Modifier' : 'Régler les montants' }}</Link>
        </div>

        <!-- Cinq vues sur une seule bande : les compteurs ne repoussent pas la liste. -->
        <QueueCounters compact :tiles="tiles" class="sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-5" @select="selectView" />

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
            <p class="max-w-md text-sm text-muted-foreground">Une dette se crée ici pour un membre du personnel, puis se valide : il la suit dans « Mes dettes », sur son site.</p>
            <Button v-if="can.create && ! listing.search" :as="Link" :href="staffDebtUrl('/finance/dettes/nouvelle')"><Plus class="h-4 w-4" />Nouvelle dette</Button>
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
                                <p class="flex items-center gap-1.5 font-semibold text-foreground">{{ debt.employee_name }}<ShieldAlert v-if="debt.derogated" class="h-3.5 w-3.5 text-amber-600" aria-label="Accordée par dérogation" /></p>
                                <p class="text-xs text-muted-foreground">{{ [debt.number, debt.employee_number].filter(Boolean).join(' · ') }} · {{ formatDateTime(debt.requested_at) }}</p>
                            </td>
                            <td class="px-4 py-3"><StaffDebtStatusBadge :status="debt.status" :label="debt.status_label" :tone="debt.status_tone" /></td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <p class="font-semibold text-foreground">{{ formatMoney(debt.amount ?? debt.requested_amount) }}</p>
                                <p v-if="debt.amount && debt.amount !== debt.requested_amount" class="text-xs text-muted-foreground line-through">{{ formatMoney(debt.requested_amount) }}</p>
                                <p v-if="Number(debt.interest_amount) > 0" class="text-xs text-muted-foreground">+ {{ formatMoney(debt.interest_amount) }} d’intérêt</p>
                            </td>
                            <td class="px-4 py-3">
                                <p v-if="debt.installment_amount" class="text-foreground">{{ formatMoney(debt.installment_amount) }} / mois</p>
                                <p v-else class="text-muted-foreground">À fixer par le DG</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ debt.repayment_mode_label ?? 'Mode à décider' }}<template v-if="debt.next_period"> · <span class="capitalize">{{ monthLabel(debt.next_period) }}</span></template>
                                </p>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <p class="font-semibold text-foreground">{{ debt.status === 'ACTIVE' ? formatMoney(debt.balance) : '—' }}</p>
                                <p v-if="Number(debt.arrears) > 0" class="flex items-center justify-end gap-1 text-xs text-destructive"><AlarmClock class="h-3 w-3" />{{ formatMoney(debt.arrears) }} en retard</p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button :as="Link" :href="staffDebtUrl(`/finance/dettes/${debt.uuid}`)" size="sm" :variant="actionFor(debt).variant">
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
