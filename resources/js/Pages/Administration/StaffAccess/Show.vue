<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import {
    ArrowLeft,
    AtSign,
    CalendarClock,
    CheckCircle2,
    Copy,
    KeyRound,
    Link2,
    MessageSquareText,
    Printer,
    RotateCcw,
    Send,
    ShieldCheck,
    TimerOff,
    UserCheck,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { cn } from '@/lib/cn';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatDateTime } from '@/utilities/date';
import { hrUrl } from '@/utilities/hrUrl';
import { handoverTone, itemStateTone, loginLink, shareMessage } from '@/utilities/staffAccess';
import { avatarTone, initialsOf } from '@/utilities/webmail';

/**
 * ADR-197 / ADR-202 — une remise d'accès au RH : les comptes créés par le Super
 * Admin pour le personnel. Aucun mot de passe : le RH dit à chaque employé que son
 * compte existe et où se connecter (message à recopier, ou fiche imprimée avec un
 * QR code) ; l'employé tape son adresse et choisit lui-même son mot de passe.
 * Le RH voit qui s'est connecté, et rouvre le délai d'un retardataire.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    handover: { type: Object, required: true },
    siteName: { type: String, default: '' },
    brand: { type: String, default: '' },
    loginUrl: { type: String, default: '' },
    activationDays: { type: Number, default: 14 },
});

const toast = useToastStore();
const items = computed(() => props.handover.items ?? []);
const waiting = computed(() => items.value.filter((item) => item.state === 'WAITING'));
const expired = computed(() => items.value.filter((item) => item.state === 'EXPIRED'));
const counts = computed(() => props.handover.counts ?? {});

const copy = async (text, done = 'Copié.') => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(done);
    } catch {
        toast.error('Copie impossible : sélectionnez le texte.');
    }
};

const messageFor = (item) => shareMessage(item, { loginUrl: props.loginUrl, brand: props.brand, site: props.siteName, deadline: item.open_until });

// --- Fiches imprimées : une par employé, avec le QR code du lien de connexion ---
const qr = ref({});
const printing = ref([]);
const printable = computed(() => items.value.filter((item) => printing.value.includes(item.uuid)));

onMounted(async () => {
    const entries = await Promise.all(items.value.map(async (item) => [
        item.uuid,
        await QRCode.toDataURL(loginLink(props.loginUrl, item.login_email), { margin: 1, width: 220 }).catch(() => null),
    ]));
    qr.value = Object.fromEntries(entries);
});

const print = (list) => {
    if (!list.length) return;
    printing.value = list.map((item) => item.uuid);
    // Le temps que les fiches choisies s'affichent, puis l'impression.
    requestAnimationFrame(() => requestAnimationFrame(() => window.print()));
};

// À l'impression, seules les fiches sortent — ni le menu, ni l'en-tête.
let style = null;
const afterPrint = () => { printing.value = []; };
onMounted(() => {
    style = document.createElement('style');
    style.textContent = '@media print { body * { visibility: hidden !important; } #staff-access-slips, #staff-access-slips * { visibility: visible !important; } #staff-access-slips { position: absolute; inset: 0 auto auto 0; width: 100%; } }';
    document.head.appendChild(style);
    window.addEventListener('afterprint', afterPrint);
});
onBeforeUnmount(() => {
    style?.remove();
    window.removeEventListener('afterprint', afterPrint);
});

// --- Rouvrir la première connexion d'un retardataire ---
const reopenTarget = ref(null);
const reopening = ref(false);
const reopen = () => {
    if (!reopenTarget.value) return;
    reopening.value = true;
    router.post(hrUrl(`/administration/staff-access/${props.handover.uuid}/items/${reopenTarget.value.uuid}/reopen`), {}, {
        preserveScroll: true,
        onSuccess: () => { reopenTarget.value = null; },
        onFinish: () => { reopening.value = false; },
    });
};

const title = computed(() => ({
    TO_REOPEN: 'Délai dépassé',
    WAITING: 'Accès à annoncer',
    COMPLETE: 'Tous connectés',
}[props.handover.status] ?? 'Accès du personnel'));
const subtitle = computed(() => {
    const count = items.value.length;
    const by = props.handover.sent_by ? ` par ${props.handover.sent_by}` : '';

    return `${count} accès envoyé${count > 1 ? 's' : ''} le ${formatDateTime(props.handover.sent_at)}${by}.`;
});

const STATE_ICONS = { ACTIVATED: UserCheck, WAITING: CalendarClock, EXPIRED: TimerOff };
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-6 print:hidden">
        <PageHeader eyebrow="Ressources humaines · Accès du personnel" :title="title" :description="subtitle" :icon="KeyRound">
            <template #actions>
                <Button :as="Link" :href="hrUrl('/administration/staff-access')" variant="outline"><ArrowLeft class="h-4 w-4" />Toutes les remises</Button>
            </template>
        </PageHeader>

        <!-- Ce que la remise attend -->
        <div
            :class="cn('flex items-start gap-3 rounded-xl border px-4 py-3.5 text-sm',
                handover.status === 'COMPLETE' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200'
                    : handover.status === 'TO_REOPEN' ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200'
                        : 'border-primary/20 bg-primary/5 text-foreground')"
        >
            <component :is="handover.status === 'COMPLETE' ? CheckCircle2 : handover.status === 'TO_REOPEN' ? TimerOff : ShieldCheck" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <div class="min-w-0">
                <template v-if="handover.status === 'COMPLETE'">
                    <p class="font-semibold">Tous se sont connectés et ont choisi leur mot de passe.</p>
                    <p class="text-xs">Un employé qui l’oublie le renouvelle lui-même par « Mot de passe oublié ? » sur la page de connexion.</p>
                </template>
                <template v-else>
                    <p class="font-semibold">
                        <template v-if="expired.length">{{ expired.length }} employé{{ expired.length > 1 ? 's ont' : ' a' }} laissé passer le délai : rouvrez-le, puis prévenez-{{ expired.length > 1 ? 'les' : 'le' }}.</template>
                        <template v-else>{{ waiting.length }} employé{{ waiting.length > 1 ? 's doivent' : ' doit' }} encore se connecter.</template>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Dites à chacun que son compte est créé et où se connecter : il tape son adresse, puis « Continuer », et choisit lui-même son mot de passe. Personne d’autre ne le connaît, pas même vous. Délai : {{ activationDays }} jours.
                    </p>
                </template>
            </div>
        </div>

        <section class="min-w-0 overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div class="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :tone="handoverTone(handover.status)">{{ handover.status_label }}</Badge>
                    <span class="text-xs text-muted-foreground">{{ counts.activated ?? 0 }}/{{ counts.total ?? items.length }} connecté{{ (counts.activated ?? 0) > 1 ? 's' : '' }}</span>
                </div>
                <div class="flex flex-wrap gap-2 sm:ms-auto">
                    <Button type="button" variant="white-outline" size="sm" @click="copy(loginUrl, 'Lien de connexion copié.')">
                        <Link2 class="h-4 w-4" aria-hidden="true" />Copier le lien de connexion
                    </Button>
                    <Button v-if="waiting.length" type="button" size="sm" @click="print(waiting)">
                        <Printer class="h-4 w-4" aria-hidden="true" />Imprimer les fiches ({{ waiting.length }})
                    </Button>
                </div>
            </div>

            <ul class="divide-y divide-border">
                <li v-for="item in items" :key="item.uuid" class="grid gap-3 px-4 py-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_auto] lg:items-center">
                    <div class="flex min-w-0 items-center gap-3">
                        <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-bold', avatarTone(item.uuid))" aria-hidden="true">{{ initialsOf({ name: item.employee_name }) }}</span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-foreground">{{ item.employee_name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ [item.job_title, item.employee_number].filter(Boolean).join(' · ') || '—' }}</p>
                            <p class="truncate text-[11px] text-muted-foreground/80">{{ item.role }}<template v-if="item.profile"> · {{ item.profile }}</template></p>
                        </div>
                    </div>

                    <div class="min-w-0 space-y-1.5">
                        <div class="flex min-w-0 items-center gap-2 rounded-lg border border-border bg-muted/30 px-3 py-1.5">
                            <AtSign class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate font-mono text-xs" :title="item.login_email">{{ item.login_email }}</span>
                            <button type="button" class="rounded p-1 text-muted-foreground hover:text-foreground" :aria-label="`Copier l’adresse de ${item.employee_name}`" @click="copy(item.login_email)"><Copy class="h-3.5 w-3.5" /></button>
                        </div>
                        <p class="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                            <Badge :tone="itemStateTone(item.state)" class="py-0 text-[11px]">
                                <component :is="STATE_ICONS[item.state] ?? UserCheck" class="h-3 w-3" aria-hidden="true" />{{ item.state_label }}
                            </Badge>
                            <template v-if="item.state === 'ACTIVATED' && item.activated_at">le {{ formatDateTime(item.activated_at) }}</template>
                            <template v-else-if="item.state === 'WAITING' && item.open_until">jusqu’au {{ formatDate(item.open_until) }}</template>
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-1.5 lg:justify-end">
                        <template v-if="item.state === 'WAITING'">
                            <Button type="button" size="sm" variant="white-outline" @click="copy(messageFor(item), `Message pour ${item.employee_name} copié : collez-le dans un SMS, WhatsApp ou un email.`)">
                                <MessageSquareText class="h-4 w-4" aria-hidden="true" />Copier le message
                            </Button>
                            <Button type="button" size="sm" variant="ghost" :aria-label="`Imprimer la fiche de ${item.employee_name}`" @click="print([item])">
                                <Printer class="h-4 w-4" aria-hidden="true" />Fiche
                            </Button>
                        </template>
                        <Button v-else-if="item.state === 'EXPIRED'" type="button" size="sm" @click="reopenTarget = item">
                            <RotateCcw class="h-4 w-4" aria-hidden="true" />Rouvrir
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <p class="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
            <Send class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
            Vous êtes prévenu dans la cloche à chaque première connexion. Si un employé dit ne pas l’avoir faite lui-même, prévenez tout de suite le Super Admin.
        </p>
    </div>

    <!-- Fiches : ne sortent qu'à l'impression, une par employé choisi. -->
    <div id="staff-access-slips" class="hidden print:block">
        <article
            v-for="item in printable"
            :key="item.uuid"
            class="mb-6 flex break-inside-avoid gap-6 rounded-lg border-2 border-dashed border-slate-400 p-6 text-slate-900"
        >
            <div class="min-w-0 flex-1">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-500">{{ brand }} · {{ siteName }}</p>
                <h2 class="mt-1 text-xl font-bold">Votre compte est créé</h2>
                <p class="mt-1 text-base font-semibold">{{ item.employee_name }}<template v-if="item.job_title"> — {{ item.job_title }}</template></p>
                <ol class="mt-4 list-decimal space-y-1.5 ps-5 text-sm">
                    <li>Ouvrez <span class="font-mono">{{ loginUrl }}</span>, ou scannez le code.</li>
                    <li>Tapez votre adresse <span class="font-mono font-semibold">{{ item.login_email }}</span>, puis « Continuer ».</li>
                    <li>Choisissez votre mot de passe : 12 caractères au moins, avec majuscule, minuscule, chiffre et symbole.</li>
                </ol>
                <p v-if="item.mailbox_address" class="mt-3 text-sm">Il ouvrira aussi votre messagerie <span class="font-mono">{{ item.mailbox_address }}</span>.</p>
                <p v-if="item.open_until" class="mt-3 text-sm font-semibold">À faire avant le {{ formatDate(item.open_until) }}.</p>
                <p class="mt-4 text-xs text-slate-600">Personne d’autre ne connaîtra votre mot de passe : ne le communiquez jamais. Si quelqu’un a activé ce compte à votre place, prévenez le RH.</p>
            </div>
            <img v-if="qr[item.uuid]" :src="qr[item.uuid]" alt="" class="h-36 w-36 shrink-0 self-center">
        </article>
    </div>

    <ConfirmModal
        :open="Boolean(reopenTarget)"
        :title="reopenTarget ? `Rouvrir la première connexion de ${reopenTarget.employee_name} ?` : ''"
        :description="`Il pourra de nouveau taper son adresse et choisir son mot de passe, pendant ${activationDays} jours. Prévenez-le ensuite.`"
        confirm-label="Rouvrir"
        :icon="RotateCcw"
        :processing="reopening"
        @update:open="(value) => { if (!value) reopenTarget = null; }"
        @confirm="reopen"
    />
</template>
