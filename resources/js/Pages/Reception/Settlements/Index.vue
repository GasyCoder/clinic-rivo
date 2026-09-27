<script setup>
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import { Banknote, BedDouble, CircleAlert, CircleCheck, DoorOpen, FilePlus2, FileSpreadsheet, Lock, Printer, Search, ShieldCheck, Stethoscope, Wallet, X } from 'lucide-vue-next';
import { lucideIcon } from '@/lib/icons';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/UI/Input.vue';
import { formatDate, formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tab: String,
    filters: Object,
    episodes: Object,
    counts: Object,
    exitTypes: Array,
    capabilities: Object,
});

const search = ref(props.filters?.q ?? '');
watch(() => props.filters?.q, (value) => { search.value = value ?? ''; });

const goTo = (params) => router.get('/reception/sorties', {
    tab: props.tab,
    q: search.value || undefined,
    ...params,
}, { preserveState: true, preserveScroll: true, replace: true });

const submitSearch = () => goTo({});
const switchTab = (tab) => router.get('/reception/sorties', { tab, q: search.value || undefined }, { preserveScroll: true });

/* ------------------------------------------------------------------ *
 * Exit dialog
 * ------------------------------------------------------------------ */

const target = ref(null);

const form = useForm({
    exit_type: '',
    reason: '',
    comment: '',
    responsible_name: '',
    responsible_phone: '',
    responsible_relationship: '',
    due_date: '',
    left_at_estimate: '',
    last_known_service: '',
});

const openExit = (episode) => {
    target.value = episode;
    form.reset();
    form.clearErrors();
    // Never pre-select a derogation: which exit is legal depends on the
    // balance, and the server re-decides it anyway. Only the unambiguous
    // settled case is proposed up front.
    form.exit_type = episode.account?.is_settled && !episode.account?.pending_count ? 'PAID_CASH' : '';
};

const closeExit = () => { target.value = null; };

const account = computed(() => target.value?.account ?? null);
const isSettled = computed(() => account.value?.is_settled === true);

/**
 * ADR-090 (amendement du 2026-09-20) — tant qu'une prestation n'est portée
 * sur aucune facture, la sortie est refusée par le serveur, quel qu'en soit le
 * type : le reste à payer ne compte que les factures, et « payé comptant » sur
 * un compte à zéro laissait partir le passage avec cet argent perdu. L'écran
 * n'en décide pas, il évite seulement de proposer ce qui sera refusé.
 */
const hasUnbilled = computed(() => (account.value?.pending_count ?? 0) > 0);

/**
 * Ce que le patient doit **réellement**, prestations non facturées comprises.
 *
 * Le « reste à payer » du serveur (CDC §33.2) ne somme que les factures : à
 * zéro, il s'affichait en vert « soldé » alors que 70 000 Ar attendaient d'être
 * facturés — un chiffre exact, mais qui disait le contraire de la situation.
 * L'écran additionne donc les deux, et nomme la part qui n'est pas encore sur
 * une facture. C'est un affichage : le serveur ne s'en sert pas pour décider.
 */
const owed = (episodeAccount) => {
    const balance = Number(episodeAccount?.balance_amount ?? 0);
    const unbilled = Number(episodeAccount?.pending_amount ?? 0);

    return { balance, unbilled, total: balance + unbilled };
};

/** Soldé pour de bon : plus rien à encaisser, et plus rien à facturer. */
const reallySettled = (episodeAccount) => episodeAccount?.is_settled === true && !episodeAccount?.pending_count;

/**
 * Les sorties qui ont un sens pour ce compte.
 *
 * Une dette validée ou une évasion laissent une créance sur ce que le patient
 * doit : sur un compte soldé, sans prestation en attente, il n'y a rien à
 * laisser, et le serveur les refuserait toujours. Les proposer verrouillées,
 * avec « aucune dette ne peut être créée », ne servait qu'à faire lire trois
 * lignes pour une seule décision possible. Elles disparaissent donc.
 *
 * Les autres verrous restent affichés, parce qu'ils disent quoi faire :
 * facturer, encaisser, ou demander un droit. Le serveur décide de toute façon.
 */
const visibleExitTypes = computed(() => (props.exitTypes ?? []).filter(
    (type) => !(isSettled.value && !hasUnbilled.value && type.value !== 'PAID_CASH'),
));

/* ── Sélection multiple ────────────────────────────────────────────────
 *
 * Ce que l'écran coche n'est qu'un ensemble d'UUID : chaque action groupée
 * relit et rejuge chaque passage côté serveur, avec les mêmes règles que pour
 * un passage seul. Les boutons annoncent seulement combien de passages
 * cochés sont *concernés* par l'action, pour qu'on sache ce qu'on va faire
 * avant de le faire. */
const selected = ref([]);
const isSelected = (uuid) => selected.value.includes(uuid);
const rows = computed(() => props.episodes?.data ?? []);
const selectedRows = computed(() => rows.value.filter((episode) => isSelected(episode.uuid)));
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((episode) => isSelected(episode.uuid)));
const someSelected = computed(() => selected.value.length > 0 && !allSelected.value);

const toggleRow = (uuid, on) => {
    selected.value = on
        ? [...new Set([...selected.value, uuid])]
        : selected.value.filter((id) => id !== uuid);
};
const toggleAll = (on) => { selected.value = on ? rows.value.map((episode) => episode.uuid) : []; };

// Changer d'onglet, de page ou de recherche change de liste : une sélection
// qui survivrait cocherait des passages qu'on ne voit plus.
watch(() => [props.tab, props.episodes?.meta?.current_page, props.filters?.q], () => { selected.value = []; });
// Après une action, ce qui a quitté la page n'est plus coché ; le reste l'est
// toujours, avec son état recalculé (facturer, puis prononcer la sortie).
watch(rows, (list) => {
    const present = new Set(list.map((episode) => episode.uuid));

    selected.value = selected.value.filter((uuid) => present.has(uuid));
});

const bulkReady = computed(() => props.tab === 'pending' && props.capabilities.can_view_accounts);
/** Comptes soldés pour de bon : la seule sortie qui se prononce en lot. */
const exitRows = computed(() => (bulkReady.value && props.capabilities.can_record_exit
    ? selectedRows.value.filter((episode) => reallySettled(episode.account))
    : []));
/** Passages qui ont encore des prestations à porter sur une facture. */
const invoiceRows = computed(() => (bulkReady.value && props.capabilities.can_record_exit && props.capabilities.can_invoice
    ? selectedRows.value.filter((episode) => (episode.account?.pending_count ?? 0) > 0)
    : []));
/** Sorties prononcées : seules celles-là ont une fiche à imprimer. */
const slipRows = computed(() => (props.tab === 'discharged'
    ? selectedRows.value.filter((episode) => episode.administrative_exit)
    : []));

const uuidsQuery = (list) => list.map((episode) => `uuids[]=${encodeURIComponent(episode.uuid)}`).join('&');
const slipsHref = computed(() => `/reception/sorties/fiches?${uuidsQuery(slipRows.value)}`);
const exportHref = computed(() => `/reception/sorties/export?${uuidsQuery(selectedRows.value)}`);

const bulkConfirm = ref(null);
const bulkProcessing = ref(false);
const bulkTargets = computed(() => (bulkConfirm.value === 'invoice' ? invoiceRows.value : exitRows.value));

const runBulk = () => {
    if (!bulkConfirm.value || bulkProcessing.value || bulkTargets.value.length === 0) return;

    bulkProcessing.value = true;
    router.post(
        bulkConfirm.value === 'invoice' ? '/reception/sorties/facturation-groupee' : '/reception/sorties/sortie-groupee',
        { episode_uuids: bulkTargets.value.map((episode) => episode.uuid) },
        {
            preserveScroll: true,
            onFinish: () => {
                bulkProcessing.value = false;
                bulkConfirm.value = null;
            },
        },
    );
};

// Le rapport du dernier lot : ce qui est passé, ce qui ne l'est pas, et pourquoi.
const page = usePage();
const bulkReport = computed(() => page.props.flash?.bulk_report ?? null);
const reportHidden = ref(false);
watch(bulkReport, () => { reportHidden.value = false; });

const invoicing = ref(false);
const invoicePending = () => {
    if (!target.value || invoicing.value) return;

    invoicing.value = true;
    router.post(`/reception/passages/${target.value.uuid}/facturer-prestations`, {}, {
        preserveScroll: true,
        onFinish: () => { invoicing.value = false; },
    });
};

/**
 * Après facturation, la page revient avec le compte à jour : la fenêtre doit
 * suivre la ligne fraîche, sinon elle continuerait d'afficher les prestations
 * comme non facturées et resterait bloquée. Un compte devenu soldé (facture
 * intégralement couverte) repropose « payé comptant », comme à l'ouverture.
 */
watch(() => props.episodes?.data, (rows) => {
    if (!target.value) return;

    const fresh = (rows ?? []).find((episode) => episode.uuid === target.value.uuid);

    if (!fresh) {
        closeExit();

        return;
    }

    target.value = fresh;

    if (!form.exit_type && fresh.account?.is_settled && !fresh.account?.pending_count) {
        form.exit_type = 'PAID_CASH';
    }
});

/**
 * Un aperçu de ce que le serveur écrira si l'agent laisse le motif vide.
 *
 * Ce n'est qu'un **placeholder** : il n'est jamais envoyé, et c'est bien le
 * serveur qui compose la phrase finale, après avoir reverrouillé et
 * recalculé le compte. Montrer ici un texte qu'on soumettrait figerait des
 * montants que le compte peut contredire entre l'affichage et le clic —
 * le même piège que les tarifs envoyés par le navigateur (ADR-028).
 */
const generatedReasonHint = computed(() => {
    const amounts = account.value;

    if (!amounts) {
        return 'Ce qui justifie cette sortie — conservé dans l’audit.';
    }

    switch (form.exit_type) {
        case 'PAID_CASH':
            return `Compte soldé : ${amounts.invoiced_amount} facturés, ${amounts.paid_amount} réglés…`;
        case 'DEBT_VALIDATED':
            return `Dérogation autorisée : reste à payer ${amounts.balance_amount}…`;
        case 'ESCAPED':
            return `Départ constaté sans règlement régulier : reste à payer ${amounts.balance_amount}…`;
        default:
            return 'Choisissez un type de sortie : le motif sera composé pour vous.';
    }
});

/**
 * CDC §33.3 — the same rule the server enforces, mirrored here only so the
 * agent sees *why* an option is unavailable instead of being refused after
 * submitting. The server decides; this never does.
 */
const unavailableReason = (type) => {
    if (hasUnbilled.value) {
        return 'Facturez d’abord les prestations en attente.';
    }
    // Ces deux sorties engagent la clinique sur un montant : elles restent
    // affichées — la Réception voit qu'elles existent — mais ne se déverrouillent
    // que par un droit que le Super Administrateur accorde (ADR-090, amendement
    // du 2026-09-20). Le serveur refuse de toute façon.
    if (type.value === 'DEBT_VALIDATED' && !props.capabilities.can_authorize_debt) {
        return 'Verrouillé : demandez à un administrateur d’autoriser cette dérogation (permission debts.authorize).';
    }
    if (type.value === 'ESCAPED' && !props.capabilities.can_record_escape) {
        return 'Verrouillé : demandez à un administrateur d’enregistrer ce constat (permission debts.record_escape).';
    }
    // Without billing.view the balance is unknown here, so nothing is
    // pre-judged: the server still refuses an illegal exit, with a precise
    // message. Disabling every option instead would be a guess.
    if (!account.value) {
        return null;
    }
    if (type.value === 'PAID_CASH' && !isSettled.value) {
        return `Le compte n’est pas soldé (reste ${formatMoney(account.value.balance_amount)}).`;
    }
    if (type.value !== 'PAID_CASH' && isSettled.value) {
        return 'Le compte est soldé : aucune dette ne peut être créée.';
    }
    return null;
};

const exitTypeMeta = {
    PAID_CASH: {
        icon: 'check-circle',
        hint: 'Le compte est soldé. Le passage est clos et la facture acquittée.',
        tone: 'emerald',
    },
    DEBT_VALIDATED: {
        icon: 'shield-check',
        hint: 'Dérogation autorisée : une créance est créée au nom d’un responsable identifié.',
        tone: 'amber',
    },
    ESCAPED: {
        icon: 'alert-circle',
        hint: 'Le patient est parti sans règlement régulier. La créance est conservée.',
        tone: 'red',
    },
};

const submit = () => {
    if (!target.value) return;
    form.post(`/reception/passages/${target.value.uuid}/sortie-administrative`, {
        preserveScroll: true,
        onSuccess: () => closeExit(),
    });
};

const exitBadge = (value) => ({
    PAID_CASH: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300',
    DEBT_VALIDATED: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    ESCAPED: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300',
}[value] ?? 'border-border text-muted-foreground ');

/**
 * Ce que la Réception regarde avant d'ouvrir la liste : combien de passages
 * attendent, et combien d'argent reste dû. Les deux viennent du serveur —
 * recalculés depuis la page affichée, ils mentiraient dès la deuxième.
 *
 * « Reste à payer » n'apparaît qu'à qui peut voir les comptes : le serveur
 * ne l'envoie pas sans `billing.view`, et la carte suit.
 */
const counterTiles = computed(() => [
    { value: 'pending', label: 'À régler', hint: 'Passages en attente', icon: Wallet, tone: 'amber', count: props.counts.pending ?? 0, active: props.tab === 'pending' },
    // ADR-156 — sortis médicalement, pas encore clôturés par leur service :
    // pas réglables (ADR-054/084), mais jamais perdus de vue pour autant.
    { value: 'in_care', label: 'Sortie médicale prononcée', hint: 'Service pas encore clôturé', icon: Stethoscope, tone: 'sky', count: props.counts.in_care ?? 0, active: props.tab === 'in_care' },
    { value: 'discharged', label: 'Sorties prononcées', hint: 'Passages clos', icon: CircleCheck, tone: 'emerald', count: props.counts.discharged ?? 0, active: props.tab === 'discharged' },
    { value: '__debt__', label: 'Sorties avec dette', hint: 'Créances enregistrées', icon: ShieldCheck, tone: 'red', count: props.counts.with_debt ?? 0, filterable: false },
    ...(props.counts.outstanding !== null && props.counts.outstanding !== undefined
        ? [{ value: '__outstanding__', label: formatMoney(props.counts.outstanding), hint: 'Factures en cours, hors prestations non facturées', icon: Banknote, tone: 'sky', count: '', filterable: false }]
        : []),
]);
</script>

<template>
    <Head title="Sorties & règlements" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-100 text-primary dark:bg-primary-950 dark:text-primary-300">
                    <Wallet class="h-6 w-6" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Sorties &amp; règlements</h1>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">
                        Les passages que le médecin a terminés et qui attendent la décision administrative.
                        Contrôlez le compte, encaissez le reste à payer à la Caisse, puis prononcez la sortie.
                    </p>
                </div>
            </div>
            <Button v-if="capabilities.can_view_cash" :as="Link" href="/cash" size="sm" variant="white-outline">
                <Wallet class="me-1.5 h-4 w-4" /> Ouvrir la Caisse
            </Button>
        </header>

        <QueueCounters :tiles="counterTiles" @select="switchTab" />

        <Card class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 border-b border-border px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <p class="text-xs text-muted-foreground">
                    <template v-if="tab === 'pending'">Passages en attente de règlement, les plus anciens d’abord.</template>
                    <template v-else-if="tab === 'in_care'">
                        Sortie médicale prononcée, service pas encore clôturé : ces passages arriveront dans « À régler »
                        dès que le médecin aura clôturé sa consultation — <button type="button" class="font-bold text-primary hover:underline" @click="switchTab('pending')">revenir à la file</button>.
                    </template>
                    <template v-else>Sorties déjà prononcées — <button type="button" class="font-bold text-primary hover:underline" @click="switchTab('pending')">revenir à la file</button>.</template>
                </p>

                <form class="flex w-full items-center gap-2 lg:w-auto" @submit.prevent="submitSearch">
                    <div class="w-full lg:w-80">
                        <IconInput
                            v-model="search"
                            :icon="Search"
                            placeholder="Patient, n° dossier, n° passage, téléphone"
                            aria-label="Rechercher un passage"
                        />
                    </div>
                    <Button size="sm" type="submit" variant="white-outline">Rechercher</Button>
                </form>
            </div>

            <!-- Actions groupées : n'apparaît qu'avec une sélection. Chaque bouton dit
                 combien de passages cochés il concerne, et reste inactif à zéro. -->
            <div v-if="selectedRows.length" class="flex flex-wrap items-center gap-2 border-b border-primary/20 bg-primary/5 px-5 py-3" role="toolbar" aria-label="Actions sur la sélection">
                <p class="me-2 text-sm font-semibold text-foreground">
                    {{ selectedRows.length }} passage{{ selectedRows.length > 1 ? 's' : '' }} sélectionné{{ selectedRows.length > 1 ? 's' : '' }}
                </p>

                <template v-if="tab === 'pending'">
                    <Button
                        v-if="capabilities.can_invoice && capabilities.can_record_exit && capabilities.can_view_accounts"
                        type="button"
                        size="sm"
                        variant="warning"
                        :disabled="invoiceRows.length === 0"
                        :title="invoiceRows.length === 0 ? 'Aucun passage coché n’a de prestation à facturer.' : undefined"
                        @click="bulkConfirm = 'invoice'"
                    >
                        <FilePlus2 class="h-4 w-4" />Facturer les prestations ({{ invoiceRows.length }})
                    </Button>
                    <Button
                        v-if="capabilities.can_record_exit && capabilities.can_view_accounts"
                        type="button"
                        size="sm"
                        variant="success"
                        :disabled="exitRows.length === 0"
                        :title="exitRows.length === 0 ? 'Aucun passage coché n’est soldé : rien à sortir en « payé comptant ».' : undefined"
                        @click="bulkConfirm = 'exit'"
                    >
                        <DoorOpen class="h-4 w-4" />Sortie « payé comptant » ({{ exitRows.length }})
                    </Button>
                </template>
                <template v-else>
                    <Button v-if="slipRows.length" :as="Link" :href="slipsHref" size="sm" variant="white-outline">
                        <Printer class="h-4 w-4" />Imprimer les fiches ({{ slipRows.length }})
                    </Button>
                    <Button v-else type="button" size="sm" variant="white-outline" disabled title="Aucun passage coché n’a de fiche de sortie.">
                        <Printer class="h-4 w-4" />Imprimer les fiches (0)
                    </Button>
                </template>

                <Button as="a" :href="exportHref" size="sm" variant="white-outline" title="Télécharger la sélection au format Excel">
                    <FileSpreadsheet class="h-4 w-4" />Exporter Excel
                </Button>
                <Button type="button" size="sm" variant="ghost" class="ms-auto text-muted-foreground" @click="selected = []">
                    <X class="h-4 w-4" />Tout désélectionner
                </Button>
            </div>

            <!-- Rapport du dernier lot. -->
            <div v-if="bulkReport && !reportHidden" class="border-b border-border px-5 py-3" role="status">
                <div class="flex items-start gap-3">
                    <component :is="bulkReport.failed.length === 0 ? CircleCheck : CircleAlert" :class="['mt-0.5 h-5 w-5 shrink-0', bulkReport.failed.length === 0 ? 'text-emerald-600' : 'text-amber-600']" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-foreground">
                            {{ bulkReport.done }} sur {{ bulkReport.total }} passage{{ bulkReport.total > 1 ? 's' : '' }} traité{{ bulkReport.done > 1 ? 's' : '' }}
                            <span v-if="bulkReport.failed.length" class="font-normal text-muted-foreground">· {{ bulkReport.failed.length }} refusé{{ bulkReport.failed.length > 1 ? 's' : '' }} par le serveur</span>
                        </p>
                        <ul v-if="bulkReport.failed.length" class="mt-2 space-y-1.5 text-xs">
                            <li v-for="failure in bulkReport.failed" :key="failure.episode_number" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                                <span class="font-bold">{{ failure.patient }} · {{ failure.episode_number }}</span> — {{ failure.message }}
                            </li>
                        </ul>
                    </div>
                    <Button type="button" size="icon-xs" variant="ghost" class="shrink-0 text-muted-foreground" aria-label="Fermer le rapport" @click="reportHidden = true">
                        <X class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <div v-if="episodes.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[60rem]">
                    <thead class="bg-muted/70 /40">
                        <tr>
                            <th class="w-10 px-4 py-2.5">
                                <Checkbox
                                    :model-value="allSelected"
                                    :class="someSelected ? 'opacity-70' : ''"
                                    aria-label="Sélectionner tous les passages de la page"
                                    :title="someSelected ? 'Sélection partielle : cliquer pour tout sélectionner' : undefined"
                                    @update:model-value="toggleAll"
                                />
                            </th>
                            <th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Patient</th>
                            <th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Passage</th>
                            <th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Sortie médicale</th>
                            <th v-if="capabilities.can_view_accounts" class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Facturé</th>
                            <th v-if="capabilities.can_view_accounts" class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Payé</th>
                            <th v-if="capabilities.can_view_accounts" class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Reste à payer</th>
                            <th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{{ tab === 'pending' ? 'Action' : 'Sortie' }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="episode in episodes.data" :key="episode.uuid" :class="['align-top transition-colors', isSelected(episode.uuid) && 'bg-primary/5']">
                            <td class="w-10 px-4 py-3">
                                <Checkbox
                                    :model-value="isSelected(episode.uuid)"
                                    :aria-label="`Sélectionner ${formatPatientName(episode.patient)} · ${episode.episode_number}`"
                                    @update:model-value="toggleRow(episode.uuid, $event)"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-start gap-2.5">
                                    <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(episode.patient)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <component
                                            :is="capabilities.can_view_patients && episode.patient && !episode.patient.deleted_at ? Link : 'span'"
                                            :href="capabilities.can_view_patients && episode.patient && !episode.patient.deleted_at ? `/patients/${episode.patient.uuid}` : undefined"
                                            class="block truncate text-sm font-bold text-foreground hover:text-primary"
                                        >{{ formatPatientName(episode.patient) }}</component>
                                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ episode.patient?.patient_number ?? '—' }}<template v-if="episode.patient?.phone"> · {{ episode.patient.phone }}</template></span>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <Link :href="`/passages/${episode.uuid}`" class="block text-sm font-bold text-foreground hover:text-primary">{{ episode.episode_number }}</Link>
                                <span class="mt-0.5 block text-xs text-muted-foreground" :title="formatDateTime(episode.started_at)">Arrivé {{ formatRelativeTime(episode.started_at) }}</span>
                                <span v-if="episode.priority === 'EMERGENCY'" class="mt-1 inline-flex items-center gap-1 rounded border border-red-200 px-1.5 py-0.5 text-[10px] font-bold uppercase text-red-600 dark:border-red-900 dark:text-red-300"><CircleAlert class="h-4 w-4" /> Urgence</span>
                            </td>

                            <td class="px-4 py-3">
                                <template v-if="episode.medical_discharge">
                                    <span class="block text-sm text-muted-foreground">{{ episode.medical_discharge.type_label }}</span>
                                    <span class="mt-0.5 block text-xs text-muted-foreground">{{ formatDateTime(episode.medical_discharge.discharged_at) }}</span>
                                </template>
                                <template v-else>
                                    <span class="block text-sm text-muted-foreground">{{ episode.medical_status_label ?? 'Parcours de soins terminé' }}</span>
                                    <span class="mt-0.5 block text-xs text-muted-foreground">Sans sortie médicale prononcée</span>
                                </template>
                                <!-- ADR-156 — le module Hospitalisation ne liste
                                     plus les séjours terminés : toutes les sorties
                                     se suivent ici, et un passage qui est passé
                                     par un lit le dit. -->
                                <span v-if="episode.stay" class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                    <Badge :variant="episode.stay.is_active ? 'warning' : 'outline'">
                                        <BedDouble class="h-3 w-3" />{{ episode.stay.is_active ? 'Hospitalisé' : 'Hospitalisation' }}
                                    </Badge>
                                    <span>
                                        {{ episode.stay.service || 'Service non précisé' }}<template v-if="episode.stay.room_bed"> · {{ episode.stay.room_bed }}</template>
                                    </span>
                                    <Link
                                        v-if="episode.stay.uuid"
                                        :href="`/hospitalisation/${episode.stay.uuid}`"
                                        class="font-semibold text-primary hover:underline"
                                    >Séjour</Link>
                                </span>
                            </td>

                            <td v-if="capabilities.can_view_accounts" class="px-4 py-3 text-end text-sm tabular-nums text-muted-foreground">{{ formatMoney(episode.account?.invoiced_amount ?? 0) }}</td>
                            <td v-if="capabilities.can_view_accounts" class="px-4 py-3 text-end text-sm tabular-nums text-muted-foreground">{{ formatMoney(episode.account?.paid_amount ?? 0) }}</td>
                            <td v-if="capabilities.can_view_accounts" class="px-4 py-3 text-end">
                                <!-- Le total réellement dû : le vert « 0 Ar » d'un
                                     compte dont les prestations ne sont pas encore
                                     facturées disait le contraire de la situation. -->
                                <span :class="['font-heading text-sm font-bold tabular-nums', reallySettled(episode.account) ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400']">
                                    {{ formatMoney(owed(episode.account).total) }}
                                </span>
                                <span v-if="episode.account?.pending_count" class="mt-1 flex items-center justify-end gap-1 text-[11px] font-semibold text-amber-700 dark:text-amber-400">
                                    <CircleAlert class="h-3.5 w-3.5 shrink-0" />
                                    <template v-if="owed(episode.account).balance > 0">dont </template>{{ formatMoney(episode.account.pending_amount) }} non facturé{{ episode.account.pending_count > 1 ? 's' : '' }} ({{ episode.account.pending_count }} prestation{{ episode.account.pending_count > 1 ? 's' : '' }})
                                </span>
                            </td>

                            <td class="px-4 py-3 text-end">
                                <template v-if="tab === 'pending'">
                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                        <Button
                                            v-if="capabilities.can_view_cash && !episode.account?.is_settled"
                                            :as="Link"
                                            href="/cash"
                                            size="sm"
                                            variant="white-outline"
                                        >Encaisser</Button>
                                        <Button
                                            v-if="capabilities.can_record_exit"
                                            size="sm"
                                            :variant="reallySettled(episode.account) ? 'success' : 'primary'"
                                            @click="openExit(episode)"
                                        >Prononcer la sortie</Button>
                                    </div>
                                </template>
                                <template v-else-if="episode.administrative_exit">
                                    <span :class="['inline-flex items-center gap-1 rounded border px-2 py-0.5 text-[10px] font-bold uppercase', exitBadge(episode.administrative_exit.type)]">
                                        {{ episode.administrative_exit.type_label }}
                                    </span>
                                    <span class="mt-1 block text-xs text-muted-foreground">{{ formatDateTime(episode.administrative_exit.exited_at) }}<template v-if="episode.administrative_exit.author"> · {{ episode.administrative_exit.author }}</template></span>
                                    <span v-if="Number(episode.administrative_exit.balance_amount) > 0" class="mt-0.5 block text-xs font-bold text-red-600 dark:text-red-400">Créance {{ formatMoney(episode.administrative_exit.balance_amount) }}</span>
                                    <!-- ADR-116 — la fiche de sortie, contrôlée ensuite au poste de gardiennage. -->
                                    <Button
                                        :as="Link"
                                        :href="`/reception/passages/${episode.uuid}/sortie-administrative/fiche`"
                                        size="sm"
                                        variant="white-outline"
                                        class="mt-1.5"
                                    >Imprimer la fiche de sortie</Button>
                                </template>
                                <template v-else-if="tab === 'in_care'">
                                    <span class="block text-xs text-muted-foreground">En attente de clôture par le service</span>
                                    <Button :as="Link" :href="`/passages/${episode.uuid}`" size="sm" variant="white-outline" class="mt-1.5">Voir le passage</Button>
                                </template>
                                <span v-else class="text-xs text-muted-foreground">{{ episode.administrative_status_label }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-5 py-14 text-center text-sm text-muted-foreground">
                <template v-if="tab === 'pending'">Aucun passage n’attend de règlement. Un passage arrive ici dès que la consultation est clôturée ou que les soins se terminent sans suite médicale.</template>
                <template v-else-if="tab === 'in_care'">Aucune sortie médicale en attente de clôture.</template>
                <template v-else>Aucune sortie administrative enregistrée.</template>
            </p>

            <div v-if="episodes.meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
                <p class="text-xs text-muted-foreground">{{ episodes.meta.from }}–{{ episodes.meta.to }} sur {{ episodes.meta.total }}</p>
                <div class="flex flex-wrap items-center gap-1">
                    <component
                        :is="link.url ? Link : 'span'"
                        v-for="(link, index) in episodes.links"
                        :key="index"
                        :href="link.url || undefined"
                        preserve-scroll
                        :class="['rounded px-2.5 py-1 text-xs font-bold', link.active ? 'bg-primary-600 text-white' : link.url ? 'text-muted-foreground hover:bg-muted dark:hover:bg-gray-900' : 'text-muted-foreground']"
                        v-html="link.label"
                    />
                </div>
            </div>
        </Card>
    </div>

    <!-- Exit dialog ---------------------------------------------------- -->
    <div v-if="target" class="fixed inset-0 z-[1200] flex items-start justify-center overflow-y-auto bg-slate-950/55 p-4" role="presentation" @click.self="closeExit">
        <section class="my-6 w-full max-w-2xl overflow-hidden rounded-lg border border-border bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="exit-dialog-title">
            <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                <div class="min-w-0">
                    <h2 id="exit-dialog-title" class="font-heading text-lg font-bold text-foreground">Sortie administrative</h2>
                    <p class="mt-0.5 truncate text-sm text-muted-foreground">{{ formatPatientName(target.patient) }} · {{ target.episode_number }}</p>
                </div>
                <button type="button" class="text-muted-foreground hover:text-muted-foreground" aria-label="Fermer" @click="closeExit"><X class="h-5 w-5" /></button>
            </header>

            <!-- CDC §33.2 — contrôle du compte patient -->
            <div v-if="account" class="flex flex-wrap items-end justify-between gap-3 border-b border-border bg-muted/60 px-5 py-4 /30">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-muted-foreground">{{ hasUnbilled ? 'Reste à régler (facturé ou non)' : 'Reste à payer' }}</p>
                    <p :class="['mt-0.5 font-heading text-3xl font-bold', reallySettled(account) ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400']">{{ formatMoney(owed(account).total) }}</p>
                </div>
                <dl class="flex gap-5 text-xs">
                    <div><dt class="text-muted-foreground">Total facturé</dt><dd class="mt-0.5 font-bold tabular-nums text-muted-foreground">{{ formatMoney(account.invoiced_amount) }}</dd></div>
                    <div><dt class="text-muted-foreground">Déjà payé</dt><dd class="mt-0.5 font-bold tabular-nums text-muted-foreground">{{ formatMoney(account.paid_amount) }}</dd></div>
                    <div v-if="hasUnbilled"><dt class="font-semibold text-amber-700 dark:text-amber-400">Non facturé</dt><dd class="mt-0.5 font-bold tabular-nums text-amber-700 dark:text-amber-400">{{ formatMoney(account.pending_amount) }}</dd></div>
                </dl>
            </div>
            <div v-else class="border-b border-border px-5 py-4 text-sm text-muted-foreground">
                Le solde du compte n’est pas visible avec vos droits (<code>billing.view</code>). Le serveur le contrôlera malgré tout avant d’accepter la sortie.
            </div>

            <div v-if="account?.pending_count" class="flex items-start gap-2 border-b border-amber-200 bg-amber-50 px-5 py-3 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                <CircleAlert class="mt-0.5 h-4 w-4" />
                <span class="min-w-0 flex-1">{{ account.pending_count }} prestation{{ account.pending_count > 1 ? 's' : '' }} ({{ formatMoney(account.pending_amount) }}) n’{{ account.pending_count > 1 ? 'ont' : 'a' }} pas encore été portée{{ account.pending_count > 1 ? 's' : '' }} sur une facture. <strong>La sortie est impossible tant qu’{{ account.pending_count > 1 ? 'elles ne sont' : 'elle n’est' }} pas facturée{{ account.pending_count > 1 ? 's' : '' }}</strong> : sinon ce montant ne serait jamais réclamé.</span>
                <Button
                    v-if="capabilities.can_invoice"
                    type="button"
                    size="sm"
                    variant="warning"
                    class="shrink-0"
                    :disabled="invoicing"
                    @click="invoicePending"
                >
                    <FilePlus2 class="h-4 w-4" />{{ invoicing ? 'Facturation…' : 'Facturer ces prestations' }}
                </Button>
            </div>

            <form class="space-y-4 p-5" @submit.prevent="submit">
                <div>
                    <p class="mb-2 text-xs font-bold uppercase tracking-[0.12em] text-muted-foreground">Type de sortie <span class="text-red-500">*</span></p>
                    <div class="space-y-2">
                        <button
                            v-for="type in visibleExitTypes"
                            :key="type.value"
                            type="button"
                            :disabled="!!unavailableReason(type)"
                            :aria-pressed="form.exit_type === type.value"
                            :class="[
                                'flex w-full items-start gap-3 rounded-lg border px-3.5 py-3 text-start transition',
                                form.exit_type === type.value
                                    ? 'border-primary-600 bg-primary/10 ring-1 ring-primary-200 dark:bg-primary-950/30 dark:ring-primary-900'
                                    : 'border-border',
                                unavailableReason(type)
                                    ? 'cursor-not-allowed opacity-55'
                                    : 'hover:border-primary/40 hover:bg-primary/10/40 dark:hover:bg-primary-950/10',
                            ]"
                            @click="form.exit_type = type.value"
                        >
                            <span :class="['mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full', form.exit_type === type.value ? 'bg-primary-600 text-white' : 'bg-muted text-muted-foreground ']">
                                <component :is="lucideIcon(exitTypeMeta[type.value]?.icon ?? 'info')" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-bold text-foreground">{{ type.label }}</span>
                                <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ exitTypeMeta[type.value]?.hint }}</span>
                                <span v-if="unavailableReason(type)" class="mt-1 block text-xs font-semibold text-muted-foreground">
                                    <Lock class="h-4 w-4" /> {{ unavailableReason(type) }}
                                </span>
                            </span>
                        </button>
                    </div>
                    <FormError v-if="form.errors.exit_type">{{ form.errors.exit_type }}</FormError>
                </div>

                <!-- §33.3 dette validée -->
                <div v-if="form.exit_type === 'DEBT_VALIDATED'" class="space-y-3 rounded-lg border border-amber-200 bg-amber-50/40 p-4 dark:border-amber-900 dark:bg-amber-950/10">
                    <p class="text-xs font-bold uppercase tracking-[0.12em] text-amber-700 dark:text-amber-300">Responsable du paiement</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <FormLabel for="responsible_name">Nom complet <span class="text-red-500">*</span></FormLabel>
                            <Input id="responsible_name" v-model="form.responsible_name" class="mt-1" :aria-invalid="!!form.errors.responsible_name" />
                            <FormError v-if="form.errors.responsible_name">{{ form.errors.responsible_name }}</FormError>
                        </div>
                        <div>
                            <FormLabel for="responsible_phone">Téléphone <span class="text-red-500">*</span></FormLabel>
                            <Input id="responsible_phone" v-model="form.responsible_phone" class="mt-1" :aria-invalid="!!form.errors.responsible_phone" />
                            <FormError v-if="form.errors.responsible_phone">{{ form.errors.responsible_phone }}</FormError>
                        </div>
                        <div>
                            <FormLabel for="responsible_relationship">Lien avec le patient</FormLabel>
                            <Input id="responsible_relationship" v-model="form.responsible_relationship" class="mt-1" placeholder="Époux, employeur…" />
                        </div>
                        <div>
                            <FormLabel for="due_date">Échéance (facultative)</FormLabel>
                            <DatePicker id="due_date" v-model="form.due_date" class="mt-1" />
                            <FormError v-if="form.errors.due_date">{{ form.errors.due_date }}</FormError>
                        </div>
                    </div>
                </div>

                <!-- §33.3 évadé -->
                <div v-if="form.exit_type === 'ESCAPED'" class="space-y-3 rounded-lg border border-red-200 bg-red-50/40 p-4 dark:border-red-900 dark:bg-red-950/10">
                    <p class="text-xs font-bold uppercase tracking-[0.12em] text-red-700 dark:text-red-300">Constat du départ</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <FormLabel for="left_at_estimate">Date et heure estimées <span class="text-red-500">*</span></FormLabel>
                            <DateTimePicker id="left_at_estimate" v-model="form.left_at_estimate" class="mt-1" :invalid="!!form.errors.left_at_estimate" />
                            <FormError v-if="form.errors.left_at_estimate">{{ form.errors.left_at_estimate }}</FormError>
                        </div>
                        <div>
                            <FormLabel for="last_known_service">Dernier service connu</FormLabel>
                            <Input id="last_known_service" v-model="form.last_known_service" class="mt-1" placeholder="Médecine, Soins…" />
                        </div>
                    </div>
                    <p class="text-xs leading-5 text-red-700 dark:text-red-300">La créance reste enregistrée : une sortie évadé n’efface jamais ce que le patient doit.</p>
                </div>

                <!-- Le motif reste enregistré pour chaque sortie (§34.1
                     règle 8), mais il n'a plus à être tapé : laissé vide, il
                     est composé par le serveur à partir du compte qu'il
                     recalcule sous verrou. Ce que l'agent écrit l'emporte. -->
                <div v-if="form.exit_type">
                    <FormLabel for="exit_reason">
                        Motif <span class="font-normal text-muted-foreground">· généré automatiquement si vous ne l’écrivez pas</span>
                    </FormLabel>
                    <textarea
                        id="exit_reason"
                        v-model="form.reason"
                        rows="2"
                        class="mt-1 block w-full rounded border border-border bg-white px-4 py-2 text-sm text-foreground outline-none transition focus:border-primary focus:ring-2 focus:ring-primary-200"
                        :aria-invalid="!!form.errors.reason"
                        :placeholder="generatedReasonHint"
                    />
                    <FormError v-if="form.errors.reason">{{ form.errors.reason }}</FormError>
                </div>

                <div v-if="form.exit_type && form.exit_type !== 'PAID_CASH'">
                    <FormLabel for="exit_comment">Commentaire (facultatif)</FormLabel>
                    <textarea
                        id="exit_comment"
                        v-model="form.comment"
                        rows="2"
                        class="mt-1 block w-full rounded border border-border bg-white px-4 py-2 text-sm text-foreground outline-none transition focus:border-primary focus:ring-2 focus:ring-primary-200"
                    />
                </div>

                <p class="rounded border border-border bg-muted/60 px-3 py-2 text-[11px] leading-5 text-muted-foreground /30 dark:text-muted-foreground">
                    La sortie administrative clôt le passage. Elle ne modifie aucune donnée médicale et n’encaisse rien :
                    tout règlement reste enregistré à la Caisse.
                </p>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <Button size="rg" type="button" variant="white-outline" @click="closeExit">Annuler</Button>
                    <Button size="rg" type="submit" :disabled="form.processing || !form.exit_type || hasUnbilled">
                        {{ form.processing ? 'Enregistrement…' : 'Prononcer la sortie' }}
                    </Button>
                </div>
            </form>
        </section>
    </div>

    <!-- Confirmation d'une action groupée : on nomme les passages concernés, et on
         dit ce que le serveur refera pour chacun. Non fermable au clic extérieur :
         on y confirme, on n'y passe pas. -->
    <Dialog
        :open="bulkConfirm !== null"
        :title="bulkConfirm === 'invoice' ? 'Facturer les prestations en attente' : 'Prononcer la sortie « payé comptant »'"
        :description="bulkConfirm === 'invoice'
            ? 'Une facture est créée par passage, avec toutes ses prestations en attente. Rien n’est encaissé : le règlement reste à la Caisse.'
            : 'Chaque passage est clos (compte soldé, rien à facturer). Le serveur revérifie chaque compte : un passage dont la situation a changé est refusé, sans empêcher les autres.'"
        :dismissible="false"
        @update:open="(open) => { if (!open && !bulkProcessing) bulkConfirm = null; }"
    >
        <ul class="max-h-64 space-y-1.5 overflow-y-auto text-sm">
            <li v-for="episode in bulkTargets" :key="episode.uuid" class="flex items-center justify-between gap-3 rounded-md border border-border bg-muted/40 px-3 py-2">
                <span class="min-w-0 truncate font-semibold text-foreground">{{ formatPatientName(episode.patient) }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">
                    {{ episode.episode_number }}
                    <template v-if="bulkConfirm === 'invoice'"> · {{ formatMoney(episode.account?.pending_amount ?? 0) }}</template>
                </span>
            </li>
        </ul>
        <p v-if="selectedRows.length > bulkTargets.length" class="mt-3 text-xs text-muted-foreground">
            {{ selectedRows.length - bulkTargets.length }} autre{{ selectedRows.length - bulkTargets.length > 1 ? 's' : '' }} passage{{ selectedRows.length - bulkTargets.length > 1 ? 's' : '' }} coché{{ selectedRows.length - bulkTargets.length > 1 ? 's' : '' }} {{ selectedRows.length - bulkTargets.length > 1 ? 'ne sont pas concernés' : 'n’est pas concerné' }} par cette action.
        </p>
        <template #footer>
            <Button type="button" variant="white-outline" :disabled="bulkProcessing" @click="bulkConfirm = null">Annuler</Button>
            <Button type="button" :variant="bulkConfirm === 'invoice' ? 'warning' : 'success'" :disabled="bulkProcessing || bulkTargets.length === 0" @click="runBulk">
                {{ bulkProcessing ? 'Traitement…' : (bulkConfirm === 'invoice' ? `Facturer ${bulkTargets.length} passage${bulkTargets.length > 1 ? 's' : ''}` : `Prononcer ${bulkTargets.length} sortie${bulkTargets.length > 1 ? 's' : ''}`) }}
            </Button>
        </template>
    </Dialog>
</template>
