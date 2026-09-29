<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Banknote,
    CircleAlert,
    Eye,
    EyeOff,
    LockKeyhole,
    ShieldCheck,
    Store,
    UserRound,
    UsersRound,
    WalletCards,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useToastStore } from '@/stores/toast';
import { formatMoney } from '@/utilities/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    registers: Array,
    /** Ticket Pharmacie apporté depuis la Réception (ADR-104), à suivre jusqu'au poste choisi. */
    pharmacyReference: { type: String, default: null },
});

const registerUrl = (register) => (props.pharmacyReference
    ? `/cash/${register.uuid}?pharmacy_reference=${encodeURIComponent(props.pharmacyReference)}`
    : `/cash/${register.uuid}`);

const { can } = usePermissions();
const toast = useToastStore();
const openTarget = ref(null);
const visibleFunds = ref(new Set());

const openForm = useForm({
    opening_amount: 0,
    notes: 'Ouverture de caisse en début de journée.',
    cash_register_uuid: '',
});

// A session open by someone else is never mine to resume — only its own
// opener can enter it; anyone else must pick a different, available poste.
const registerStatus = (register) => {
    if (!register.is_assigned_to_me) return 'reserved';
    if (!register.is_open && !register.is_locked) return 'available';

    return register.is_mine ? (register.is_locked ? 'locked' : 'open') : 'blocked';
};

const selectRegister = (register) => {
    const status = registerStatus(register);

    if (status === 'blocked') {
        toast.warning(`${register.name} est déjà utilisée par ${register.opener_name}. Choisissez une autre caisse disponible.`);
        return;
    }

    if (status === 'reserved') {
        toast.warning(`${register.name} est attribuée à ${register.assigned_user_name}. Seul ce titulaire peut l’ouvrir.`);
        return;
    }

    if (status === 'locked') {
        toast.warning(`${register.name} est verrouillée par la Super Administration. Elle redevient accessible une fois déverrouillée.`);
        return;
    }

    if (status === 'open') {
        router.visit(registerUrl(register));
        return;
    }

    openForm.clearErrors();
    openForm.opening_amount = register.opening_fund_amount ?? 0;
    openForm.notes = 'Ouverture de caisse en début de journée.';
    openForm.cash_register_uuid = register.uuid;
    openTarget.value = register;
};

const closeOpenDialog = () => {
    if (!openForm.processing) openTarget.value = null;
};
const handleOpenDialog = (open) => {
    if (!open) closeOpenDialog();
};

const registerSummary = computed(() => props.registers.reduce((summary, register) => {
    summary[registerStatus(register)] += 1;
    if (register.assigned_user_name && register.is_assigned_to_me) summary.assignedToMe += 1;
    return summary;
}, { available: 0, open: 0, locked: 0, blocked: 0, reserved: 0, assignedToMe: 0 }));

// The cashier's own register comes first, followed by unassigned registers
// they may use. Reserved or occupied registers remain visible so the reason
// they cannot be opened is explicit instead of looking like missing data.
const orderedRegisters = computed(() => [...props.registers].sort((left, right) => {
    const rank = (register) => {
        const status = registerStatus(register);

        if (status === 'open') return 0;
        if (register.assigned_user_name && register.is_assigned_to_me) return 1;
        if (status === 'available') return 2;
        if (status === 'locked') return 3;

        return 4;
    };

    return rank(left) - rank(right) || left.name.localeCompare(right.name, 'fr');
}));

const isMyAssignedRegister = (register) => Boolean(register.assigned_user_name && register.is_assigned_to_me);
const isUnavailable = (register) => ['locked', 'blocked', 'reserved'].includes(registerStatus(register));
const isFundVisible = (register) => visibleFunds.value.has(register.uuid);
const toggleFundVisibility = (register) => {
    const next = new Set(visibleFunds.value);

    if (next.has(register.uuid)) next.delete(register.uuid);
    else next.add(register.uuid);

    visibleFunds.value = next;
};

const statusLabel = (register) => ({
    open: 'Session en cours',
    locked: 'Session suspendue',
    blocked: 'Poste occupé',
    reserved: 'Réservée',
    available: isMyAssignedRegister(register) ? 'Ma caisse' : 'Disponible',
}[registerStatus(register)]);

const statusDetail = (register) => ({
    open: `Ouverte par ${register.opener_name}`,
    locked: 'Verrouillée par la Super Administration',
    blocked: `Utilisée par ${register.opener_name}`,
    reserved: `Attribuée à ${register.assigned_user_name}`,
    available: isMyAssignedRegister(register)
        ? 'Attribuée à votre compte'
        : 'Accessible aux caissiers autorisés',
}[registerStatus(register)]);

const statusBadgeVariant = (register) => ({
    open: 'success',
    locked: 'warning',
    blocked: 'outline',
    reserved: 'outline',
    available: isMyAssignedRegister(register) ? 'default' : 'secondary',
}[registerStatus(register)]);

const confirmOpen = () => openForm.post('/cash/open', {
    preserveScroll: true,
    onSuccess: () => { openTarget.value = null; },
});
</script>

<template>
    <Head title="Caisse" />

    <div class="mx-auto w-full max-w-[1500px] space-y-4">
        <header class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div class="grid gap-4 px-5 py-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                <div class="flex min-w-0 items-center gap-3.5">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15"><WalletCards class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Réception · Encaissement</p>
                        <h1 class="mt-1 truncate font-heading text-2xl font-bold tracking-tight text-foreground">Postes de caisse</h1>
                        <p class="mt-1 truncate text-sm text-muted-foreground">Choisissez le poste utilisé pour cette session d’encaissement.</p>
                    </div>
                </div>
                <nav class="flex flex-nowrap items-center gap-2 overflow-x-auto pb-1 lg:overflow-visible lg:pb-0" aria-label="Raccourcis de la caisse">
                    <Button :as="Link" href="/reception" size="sm" variant="outline"><ArrowLeft class="h-4 w-4" />Accueil réception</Button>
                    <Button v-if="can('patients.view')" :as="Link" href="/patients" size="sm" variant="outline"><UsersRound class="h-4 w-4" />Patients</Button>
                </nav>
            </div>
            <div v-if="registers.length" class="grid grid-cols-2 divide-x divide-border border-t border-border bg-muted/25 sm:grid-cols-5">
                <div class="px-5 py-2.5"><p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Total</p><p class="mt-0.5 text-sm font-bold text-foreground">{{ registers.length }}</p></div>
                <div class="px-5 py-2.5"><p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Mes caisses</p><p class="mt-0.5 text-sm font-bold text-primary">{{ registerSummary.assignedToMe }}</p></div>
                <div class="border-t border-border px-5 py-2.5 sm:border-t-0"><p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Disponibles</p><p class="mt-0.5 text-sm font-bold text-primary">{{ registerSummary.available }}</p></div>
                <div class="border-t border-border px-5 py-2.5 sm:border-t-0"><p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Mes sessions</p><p class="mt-0.5 text-sm font-bold text-emerald-600 dark:text-emerald-400">{{ registerSummary.open }}</p></div>
                <div class="col-span-2 border-t border-border px-5 py-2.5 sm:col-span-1 sm:border-t-0"><p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Indisponibles</p><p class="mt-0.5 text-sm font-bold text-amber-600 dark:text-amber-400">{{ registerSummary.blocked + registerSummary.locked + registerSummary.reserved }}</p></div>
            </div>
        </header>

        <Card class="overflow-hidden">
            <div class="grid gap-4 border-b border-border px-5 py-4 md:grid-cols-[minmax(0,1fr)_minmax(280px,440px)] md:items-center">
                <div>
                    <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><Store class="h-4 w-4 text-primary" />Choisir un poste</h2>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Chaque poste tient sa propre session, indépendamment des autres postes.</p>
                </div>
                <div class="rounded-lg border border-primary/15 bg-primary/5 px-3 py-2.5 text-xs leading-5 text-muted-foreground">
                    Les tickets Pharmacie restent contrôlés et encaissés ici, dans l’espace Caisse. La Pharmacie n’encaisse jamais directement.
                </div>
            </div>
            <div v-if="registers.length === 0" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"><CircleAlert class="h-6 w-6" /></span>
                <div>
                    <h3 class="text-sm font-bold text-foreground">Aucune caisse créée ou active</h3>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">Demandez à un administrateur d’en créer une ou d’en réactiver une existante avant de pouvoir encaisser.</p>
                </div>
            </div>

            <div v-else class="grid grid-cols-1 gap-4 bg-muted/10 p-4 md:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="register in orderedRegisters"
                    :key="register.uuid"
                    :class="['group relative flex min-h-56 flex-col items-stretch overflow-hidden rounded-xl border bg-card text-start shadow-sm transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30',
                        registerStatus(register) === 'open' ? 'border-emerald-300 hover:-translate-y-0.5 hover:border-emerald-400 hover:shadow-md dark:border-emerald-900'
                        : registerStatus(register) === 'locked' ? 'border-amber-200 bg-amber-50/40 dark:border-amber-900 dark:bg-amber-950/10'
                        : registerStatus(register) === 'blocked' || registerStatus(register) === 'reserved' ? 'border-border bg-muted/40 opacity-80'
                        : 'border-border hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md']"
                >
                    <span class="absolute inset-x-0 top-0 h-1.5" :style="{ backgroundColor: register.color }" aria-hidden="true" />
                    <div class="flex items-start justify-between gap-4 px-5 pb-4 pt-6">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-muted-foreground">Poste de caisse</p>
                            <p class="mt-1 truncate text-base font-bold text-foreground">{{ register.name }}</p>
                        </div>
                        <Badge :variant="statusBadgeVariant(register)" class="shrink-0">{{ statusLabel(register) }}</Badge>
                    </div>

                    <dl class="mx-5 grid grid-cols-2 gap-2 border-t border-border py-4 text-xs">
                        <div class="min-w-0 rounded-lg bg-muted/55 px-3 py-2.5">
                            <dt class="flex items-center gap-1.5 text-muted-foreground"><UserRound class="h-3.5 w-3.5" />Titulaire</dt>
                            <dd class="mt-1 truncate font-bold text-foreground">{{ isMyAssignedRegister(register) ? 'Moi' : register.assigned_user_name ?? 'Non attribuée' }}</dd>
                        </div>
                        <div class="min-w-0 rounded-lg bg-muted/55 px-3 py-2.5">
                            <dt class="flex min-h-7 items-center justify-between gap-1.5 text-muted-foreground">
                                <span class="flex min-w-0 items-center gap-1.5"><Banknote class="h-3.5 w-3.5 shrink-0" /><span class="truncate">Fond initial</span></span>
                                <Button
                                    v-if="register.opening_fund_amount !== null"
                                    type="button"
                                    variant="ghost"
                                    size="icon-xs"
                                    class="-me-1 shrink-0"
                                    :title="isFundVisible(register) ? 'Masquer le fond initial' : 'Afficher le fond initial'"
                                    :aria-label="`${isFundVisible(register) ? 'Masquer' : 'Afficher'} le fond initial de ${register.name}`"
                                    @click="toggleFundVisibility(register)"
                                >
                                    <EyeOff v-if="isFundVisible(register)" class="h-3.5 w-3.5" />
                                    <Eye v-else class="h-3.5 w-3.5" />
                                </Button>
                            </dt>
                            <dd class="mt-0.5 truncate font-bold tabular-nums text-foreground">
                                {{ register.opening_fund_amount === null ? 'À saisir' : isFundVisible(register) ? formatMoney(register.opening_fund_amount) : '••••••' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-auto flex min-h-14 items-center justify-between gap-3 border-t border-border bg-muted/20 px-5 py-3.5">
                        <p class="min-w-0 truncate text-[11px] text-muted-foreground">{{ statusDetail(register) }}</p>
                        <button
                            type="button"
                            :aria-disabled="isUnavailable(register)"
                            :class="['inline-flex shrink-0 items-center gap-1 rounded-md px-1 py-1 text-xs font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30', isUnavailable(register) ? 'text-muted-foreground' : 'text-primary transition-transform group-hover:translate-x-0.5']"
                            @click="selectRegister(register)"
                        >
                            <LockKeyhole v-if="isUnavailable(register)" class="h-3.5 w-3.5" />
                            {{ isUnavailable(register) ? 'Indisponible' : registerStatus(register) === 'open' ? 'Reprendre' : 'Ouvrir' }}
                            <ArrowRight v-if="!isUnavailable(register)" class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </article>
            </div>
        </Card>

        <Dialog
            :open="Boolean(openTarget)"
            :title="openTarget?.name ?? 'Ouvrir une session'"
            :description="openTarget?.opening_fund_amount !== null ? 'Le fond de caisse défini par la Super Administration sera appliqué.' : 'Renseignez le fond réellement remis au caissier.'"
            @update:open="handleOpenDialog"
        >
            <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><WalletCards class="h-5 w-5" /></span></template>
            <form v-if="openTarget" class="space-y-4" @submit.prevent="confirmOpen">
                    <FormField label="Fond initial" required :error="openForm.errors.opening_amount">
                        <Input id="open_amount" v-model="openForm.opening_amount" type="number" min="0" step="0.01" size="lg" class="font-bold" :disabled="openTarget.opening_fund_amount !== null" required autofocus />
                        <p v-if="openTarget.opening_fund_amount !== null" class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground"><ShieldCheck class="h-3.5 w-3.5 text-emerald-600" />Montant fixé dans la configuration de cette caisse.</p>
                    </FormField>
                    <FormField label="Note d’ouverture" hint="(facultative)" :error="openForm.errors.notes">
                        <Textarea id="open_notes" v-model="openForm.notes" :rows="3" />
                    </FormField>
                    <FormError v-if="openForm.errors.cash_register_uuid">{{ openForm.errors.cash_register_uuid }}</FormError>
                    <FormError v-if="openForm.errors.cash_session">{{ openForm.errors.cash_session }}</FormError>
                    <div class="flex justify-end gap-2 border-t border-border pt-4">
                        <Button variant="outline" type="button" :disabled="openForm.processing" @click="closeOpenDialog">Annuler</Button>
                        <Button type="submit" :disabled="openForm.processing"><ShieldCheck class="h-4 w-4" />{{ openForm.processing ? 'Ouverture…' : 'Ouvrir la session' }}</Button>
                    </div>
            </form>
        </Dialog>
    </div>
</template>
