<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { AlarmClock, ArrowRight, Building2, CircleAlert, Download, HandCoins, Hourglass, Landmark, Lock, Percent, Settings2, Wallet } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { formatMoney } from '@/utilities/money';
import { fromMinor, toMinor } from '@/utilities/staffDebts';

/**
 * ADR-229 — Finance › Dettes du personnel, tous les sites. Ce que chaque site a en jeu,
 * lu par son API (jamais sa base) : les demandes à décider, les dettes à verser, le
 * reste dû et les retards, et l'état de ses réglages. Un site injoignable le dit et
 * n'empêche pas les autres de s'afficher.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, required: true },
    can: { type: Object, default: () => ({}) },
});

const reachable = computed(() => props.sites.filter((site) => site.overview));
const sum = (pick) => fromMinor(reachable.value.reduce((total, site) => total + (toMinor(pick(site.overview)) ?? 0), 0));
const count = (pick) => reachable.value.reduce((total, site) => total + (Number(pick(site.overview)) || 0), 0);

const totals = computed(() => [
    { key: 'decide', icon: Hourglass, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', label: 'À décider', value: count((o) => o.counts['a-decider']), hint: formatMoney(sum((o) => o.requested_amount)) + ' demandés' },
    { key: 'disburse', icon: Wallet, tone: 'bg-primary/10 text-primary', label: 'À verser', value: count((o) => o.counts['a-verser']), hint: formatMoney(sum((o) => o.to_disburse_amount)) + ' accordés' },
    { key: 'balance', icon: Landmark, tone: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300', label: 'Reste dû', value: formatMoney(sum((o) => o.balance)), hint: `${count((o) => o.counts['en-cours'])} dette(s) en remboursement` },
    { key: 'arrears', icon: AlarmClock, tone: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300', label: 'En retard', value: formatMoney(sum((o) => o.arrears)), hint: `${count((o) => o.late)} dette(s) en retard à la Caisse` },
]);

const offline = computed(() => props.sites.filter((site) => ! site.overview));
</script>

<template>
    <Head title="Dettes du personnel" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Finance · Tous les sites"
            title="Dettes du personnel"
            description="Demandées par le personnel de chaque site, décidées et marquées versées ici, remboursées par retenue sur la paie ou en espèces à la Caisse. Chaque site garde ses limites et ses intérêts."
            :icon="HandCoins"
        />

        <p v-if="offline.length" class="flex items-start gap-2 rounded-xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-foreground dark:border-amber-900 dark:bg-amber-950/30">
            <CircleAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
            <span>Les totaux ne comptent pas {{ offline.map((site) => site.name).join(', ') }} : {{ offline.length > 1 ? 'ces sites ne répondent pas' : 'ce site ne répond pas' }} ou n’{{ offline.length > 1 ? 'ont' : 'a' }} pas d’API configurée.</span>
        </p>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div v-for="tile in totals" :key="tile.key" class="flex items-center gap-3 rounded-xl border border-border bg-card px-3 py-3 shadow-sm sm:px-4">
                <span :class="['hidden h-10 w-10 shrink-0 place-items-center rounded-lg sm:grid', tile.tone]"><component :is="tile.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-xs font-semibold text-muted-foreground">{{ tile.label }}</span>
                    <span class="mt-0.5 block truncate text-xl font-bold leading-tight tabular-nums text-foreground">{{ tile.value }}</span>
                    <span class="block truncate text-xs text-muted-foreground">{{ tile.hint }}</span>
                </span>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
            <Card v-for="site in sites" :key="site.code" class="flex flex-col gap-4 px-5 py-4">
                <header class="flex flex-wrap items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-base font-bold text-foreground">{{ site.name }}</h2>
                        <p class="text-xs text-muted-foreground">Site {{ site.code }}</p>
                    </div>
                    <template v-if="site.overview">
                        <Badge v-if="! site.overview.rules.requests_open" variant="destructive" class="gap-1"><Lock class="h-3 w-3" />Demandes fermées</Badge>
                        <Badge v-else-if="site.overview.rules.amount_limits_set === false" variant="warning" class="gap-1" title="Le personnel ne peut pas demander tant que le montant minimum et maximum ne sont pas réglés."><Lock class="h-3 w-3" />Montants à régler</Badge>
                        <Badge v-if="site.overview.rules.has_interest" variant="secondary" class="gap-1"><Percent class="h-3 w-3" />Intérêts</Badge>
                    </template>
                </header>

                <template v-if="site.overview">
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <Link :href="`${site.url}?vue=a-decider`" class="rounded-lg border border-border px-3 py-2 transition hover:border-primary/40">
                            <dt class="flex items-center gap-1.5 text-xs text-muted-foreground"><Hourglass class="h-3.5 w-3.5" />À décider</dt>
                            <dd class="text-lg font-bold tabular-nums text-foreground">{{ site.overview.counts['a-decider'] }}</dd>
                        </Link>
                        <Link :href="`${site.url}?vue=a-verser`" class="rounded-lg border border-border px-3 py-2 transition hover:border-primary/40">
                            <dt class="flex items-center gap-1.5 text-xs text-muted-foreground"><Wallet class="h-3.5 w-3.5" />À verser</dt>
                            <dd class="text-lg font-bold tabular-nums text-foreground">{{ site.overview.counts['a-verser'] }}</dd>
                        </Link>
                        <Link :href="`${site.url}?vue=en-cours`" class="rounded-lg border border-border px-3 py-2 transition hover:border-primary/40">
                            <dt class="flex items-center gap-1.5 text-xs text-muted-foreground"><Landmark class="h-3.5 w-3.5" />Reste dû</dt>
                            <dd class="text-lg font-bold tabular-nums text-foreground">{{ formatMoney(site.overview.balance) }}</dd>
                        </Link>
                        <div class="rounded-lg border border-border px-3 py-2">
                            <dt class="flex items-center gap-1.5 text-xs text-muted-foreground"><AlarmClock class="h-3.5 w-3.5" />En retard</dt>
                            <dd :class="['text-lg font-bold tabular-nums', Number(site.overview.arrears) > 0 ? 'text-destructive' : 'text-foreground']">{{ formatMoney(site.overview.arrears) }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-muted-foreground">
                        {{ site.overview.counts['en-cours'] }} en remboursement · {{ site.overview.counts.closes }} close{{ site.overview.counts.closes > 1 ? 's' : '' }}
                        <template v-if="Number(site.overview.interest) > 0"> · dont {{ formatMoney(site.overview.interest) }} d’intérêts</template>
                    </p>
                    <div class="mt-auto flex flex-wrap gap-2 border-t border-border pt-3">
                        <Button :as="Link" :href="site.url" size="sm"><ArrowRight class="h-4 w-4" />Ouvrir</Button>
                        <Button v-if="can.settings" :as="Link" :href="`${site.url}/reglages`" size="sm" variant="outline"><Settings2 class="h-4 w-4" />Réglages</Button>
                        <Button v-if="can.export" as="a" :href="`${site.url}/export`" size="sm" variant="ghost"><Download class="h-4 w-4" />Excel</Button>
                    </div>
                </template>
                <p v-else class="flex items-start gap-2 rounded-lg bg-muted/60 px-3 py-2 text-sm text-muted-foreground">
                    <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />{{ site.message ?? 'Ce site ne répond pas.' }}
                </p>
            </Card>
        </div>
    </div>
</template>
