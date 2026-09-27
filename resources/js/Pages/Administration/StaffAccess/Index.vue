<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    CalendarClock,
    CheckCircle2,
    Inbox,
    KeyRound,
    LockKeyhole,
    MessageSquareText,
    Search,
    TimerOff,
    TimerReset,
    UserCheck,
    UserRoundCog,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import NoticesButton from '@/Components/Shadcn/NoticesButton.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import StaffAccessReceivedCard from '@/Components/StaffAccess/StaffAccessReceivedCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import { hrContext, hrUrl } from '@/utilities/hrUrl';
import { deadlineUrgency } from '@/utilities/staffAccess';

/**
 * ADR-197 / ADR-202 — les accès que le Super Admin a créés pour le personnel du
 * site et annoncés au RH. Aucun mot de passe : le RH dit à chaque employé que son
 * compte existe et où se connecter ; l'employé choisit son mot de passe à sa
 * première connexion. Ce qui demande un geste d'abord : un délai dépassé à rouvrir,
 * puis les employés qui ne se sont pas encore connectés.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    handovers: { type: Object, required: true },
    counts: { type: Object, default: () => ({ toutes: 0, 'a-rouvrir': 0, 'en-attente': 0, terminees: 0 }) },
    nextDeadline: { type: String, default: null },
    people: { type: Object, default: () => ({ waiting: 0, expired: 0 }) },
    filters: { type: Object, default: () => ({ vue: 'toutes', q: '' }) },
    loginUrl: { type: String, default: '' },
    activationDays: { type: Number, default: 14 },
});

const onPortal = hrContext() !== null;
const search = ref(props.filters.q ?? '');
const loading = ref(false);

const visit = (params) => {
    router.get(hrUrl('/administration/staff-access'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
};

const query = (overrides = {}) => {
    const params = { vue: props.filters.vue, q: search.value.trim(), ...overrides };
    if (params.vue === 'toutes') delete params.vue;
    if (!params.q) delete params.q;

    return params;
};

// La carte est le filtre ; un second clic sur la carte active revient à « Toutes ».
const select = (view) => visit(query({ vue: view === props.filters.vue && view !== 'toutes' ? 'toutes' : view }));

let timer = null;
let quiet = false;
watch(search, () => {
    clearTimeout(timer);
    if (quiet) {
        quiet = false;

        return;
    }
    timer = setTimeout(() => visit(query()), 300);
});
onBeforeUnmount(() => clearTimeout(timer));

const clearSearch = () => {
    search.value = '';
};

// Tout revoir : ni filtre, ni recherche, en une seule visite.
const showAll = () => {
    clearTimeout(timer);
    quiet = search.value !== '';
    search.value = '';
    visit({});
};

const next = computed(() => deadlineUrgency(props.nextDeadline));

const tiles = computed(() => [
    {
        value: 'a-rouvrir',
        label: 'Délai dépassé',
        hint: props.people.expired ? `${props.people.expired} employé${props.people.expired > 1 ? 's' : ''} à relancer` : 'Personne en retard',
        title: 'Un employé n’a pas fait sa première connexion à temps : rouvrez son délai',
        icon: TimerOff,
        tone: props.counts['a-rouvrir'] ? 'red' : 'neutral',
        count: props.counts['a-rouvrir'] ?? 0,
        active: props.filters.vue === 'a-rouvrir',
    },
    {
        value: 'en-attente',
        label: 'En attente de connexion',
        hint: next.value ? `${props.people.waiting} employé${props.people.waiting > 1 ? 's' : ''} · ${next.value.label.toLowerCase()}` : 'Personne n’attend',
        title: next.value ? `Le délai le plus proche : ${next.value.label.toLowerCase()}` : 'Aucun employé n’attend sa première connexion',
        icon: CalendarClock,
        tone: props.counts['en-attente'] ? (next.value?.tone === 'danger' ? 'amber' : 'primary') : 'neutral',
        count: props.counts['en-attente'] ?? 0,
        active: props.filters.vue === 'en-attente',
    },
    { value: 'terminees', label: 'Tous connectés', hint: 'Mot de passe choisi', title: 'Tous les employés de la remise se sont connectés', icon: UserCheck, tone: 'emerald', count: props.counts.terminees ?? 0, active: props.filters.vue === 'terminees' },
    { value: 'toutes', label: 'Toutes', hint: 'Remises reçues', title: 'Toutes les remises reçues du Super Admin', icon: Inbox, tone: 'neutral', count: props.counts.toutes ?? 0, active: props.filters.vue === 'toutes' },
]);

const GUIDE = [
    { icon: MessageSquareText, title: 'Prévenez chaque employé', text: 'Copiez son message (SMS, WhatsApp, email) ou imprimez sa fiche avec le QR code.' },
    { icon: KeyRound, title: 'Il choisit son mot de passe', text: 'Sur la page de connexion : son adresse, « Continuer », puis son propre mot de passe.' },
    { icon: UserCheck, title: 'Vous voyez qui s’est connecté', text: 'Prévenu dans la cloche à chaque première connexion ; un retard se rouvre d’un clic.' },
];

const notices = computed(() => [
    { key: 'secure', icon: LockKeyhole, title: 'Aucun mot de passe à remettre', text: 'Personne ne connaît le mot de passe d’un employé, ni vous, ni le Super Admin : il le choisit lui-même.' },
    { key: 'expiry', icon: TimerReset, title: 'Délai', text: `La première connexion est ouverte ${props.activationDays} jours après l’envoi. Passé ce délai, rouvrez-la depuis la remise.` },
    { key: 'lost', icon: UserRoundCog, title: 'Mot de passe oublié', text: 'L’employé le renouvelle lui-même par « Mot de passe oublié ? » sur la page de connexion.' },
]);

const EMPTY = {
    'a-rouvrir': { icon: CheckCircle2, title: 'Aucun délai dépassé', text: 'Tous les employés prévenus se sont connectés à temps, ou ont encore le temps de le faire.' },
    'en-attente': { icon: CheckCircle2, title: 'Personne n’attend sa première connexion', text: 'Tous les employés des remises reçues se sont connectés.' },
    terminees: { icon: UserCheck, title: 'Aucune remise terminée', text: 'Une remise apparaît ici quand tous ses employés se sont connectés.' },
    toutes: { icon: KeyRound, title: 'Aucun accès reçu pour l’instant', text: 'Quand vous ajoutez un employé, le Super Admin est prévenu. Il crée son compte et son adresse, puis vous les annonce : vous êtes alors notifié dans la cloche.' },
};
const empty = computed(() => (props.filters.q
    ? { icon: Search, title: `Aucune remise pour « ${props.filters.q} »`, text: 'Cherchez un nom, un matricule ou un identifiant.' }
    : EMPTY[props.filters.vue] ?? EMPTY.toutes));
</script>

<template>
    <Head title="Accès du personnel" />

    <div class="flex flex-col gap-6">
        <PageHeader
            eyebrow="Ressources humaines · Personnel"
            title="Accès du personnel"
            description="Prévenez chaque employé que son compte RIVO et son adresse professionnelle sont créés : il choisira lui-même son mot de passe."
            :icon="KeyRound"
        >
            <template #actions>
                <NoticesButton :notices="notices" heading="Annoncer les accès" subtitle="Comment l’employé entre, et que faire s’il tarde ou oublie." />
            </template>
        </PageHeader>

        <!-- Ce qui attend, et où -->
        <QueueCounters :tiles="tiles" @select="select" />

        <!-- Comment faire : seulement quand quelqu'un attend -->
        <ol v-if="counts['en-attente'] > 0 || counts['a-rouvrir'] > 0" class="grid gap-3 rounded-xl border border-border bg-card p-4 shadow-sm md:grid-cols-3" aria-label="Comment annoncer les accès">
            <li v-for="(step, index) in GUIDE" :key="step.title" class="flex items-start gap-3">
                <span class="relative grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                    <component :is="step.icon" class="h-[18px] w-[18px]" />
                    <span class="absolute -end-1.5 -top-1.5 grid h-4 w-4 place-items-center rounded-full bg-primary text-[10px] font-bold text-primary-foreground ring-2 ring-card">{{ index + 1 }}</span>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-foreground">{{ step.title }}</span>
                    <span class="block text-xs text-muted-foreground">{{ step.text }}</span>
                </span>
            </li>
        </ol>

        <!-- Recherche -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative w-full sm:max-w-md">
                <IconInput v-model="search" :icon="Search" type="text" enterkeyhint="search" autocomplete="off" placeholder="Chercher un employé, un matricule, un identifiant…" aria-label="Chercher dans les remises" class="pe-9" />
                <button v-if="search" type="button" class="absolute end-2 top-1/2 -translate-y-1/2 rounded p-1 text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="clearSearch">
                    <X class="h-4 w-4" />
                </button>
            </div>
            <p class="text-xs text-muted-foreground sm:ms-auto" aria-live="polite">
                {{ handovers.total }} remise{{ handovers.total > 1 ? 's' : '' }}<template v-if="filters.q"> pour « {{ filters.q }} »</template>
            </p>
        </div>

        <!-- Les remises -->
        <section :class="['flex flex-col gap-4 transition-opacity', loading ? 'opacity-60' : '']" :aria-busy="loading">
            <StaffAccessReceivedCard v-for="handover in handovers.data" :key="handover.uuid" :handover="handover" />

            <div v-if="!handovers.data.length" class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border bg-card px-6 py-14 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground"><component :is="empty.icon" class="h-5 w-5" aria-hidden="true" /></span>
                <p class="text-sm font-semibold text-foreground">{{ empty.title }}</p>
                <p class="max-w-md text-xs text-muted-foreground">{{ empty.text }}</p>
                <Button v-if="filters.q || filters.vue !== 'toutes'" type="button" variant="white-outline" size="sm" class="mt-2" @click="showAll">
                    Voir toutes les remises
                </Button>
            </div>

            <div v-if="handovers.last_page > 1" class="overflow-hidden rounded-xl border border-border">
                <HrPagination :paginator="handovers" />
            </div>
        </section>
    </div>
</template>
