<script setup>
import { computed } from 'vue';
import {
    AtSign,
    Check,
    CheckCircle2,
    Clock,
    KeyRound,
    Loader2,
    Send,
    ShieldCheck,
    TimerOff,
    UserCheck,
    UserPlus,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import StaffAccessReceiverPicker from '@/Components/StaffAccess/StaffAccessReceiverPicker.vue';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { deadlineUrgency, handoverSteps, handoverTone, itemStateTone } from '@/utilities/staffAccess';
import { avatarTone, initialsOf } from '@/utilities/webmail';

/**
 * ADR-197 / ADR-199 / ADR-202 — une remise d'accès au RH d'un site, lue d'un coup
 * d'œil : où elle en est (créée, envoyée, premières connexions), et pour chaque
 * compte, si l'employé s'est connecté. Aucun mot de passe n'existe : chacun choisit
 * le sien à sa première connexion.
 */
const props = defineProps({
    /** La remise, avec `site_code`, `site_name`, `receivers` et `accounts`. */
    handover: { type: Object, required: true },
    canSend: { type: Boolean, default: false },
    canDesignate: { type: Boolean, default: false },
    sending: { type: Boolean, default: false },
    /** Le nom du site n'est utile que si plusieurs sites sont affichés. */
    showSite: { type: Boolean, default: true },
});
const emit = defineEmits(['send', 'designated']);

const STATUS_ICONS = { DRAFT: Clock, WAITING: Send, TO_REOPEN: TimerOff, COMPLETE: CheckCircle2 };
const STEP_ICONS = { created: UserPlus, sent: Send, activated: UserCheck };

const steps = computed(() => handoverSteps(props.handover));
const isDraft = computed(() => props.handover.status === 'DRAFT');
const noReceiver = computed(() => isDraft.value && (props.handover.receivers ?? 0) === 0);
const count = computed(() => props.handover.items?.length ?? 0);

/** Ce que la remise attend, en une phrase : la suite à donner, pas l'historique. */
const nextStep = computed(() => ({
    DRAFT: noReceiver.value ? 'Désignez qui préviendra les employés au site, puis envoyez-les au RH.' : 'Envoyez-les au RH du site : il sera prévenu dans sa cloche, et préviendra les employés.',
    WAITING: 'Le RH a été prévenu. Chaque employé se connecte avec son adresse et choisit son mot de passe.',
    TO_REOPEN: 'Un employé a laissé passer le délai sans se connecter : le RH du site peut le rouvrir.',
    COMPLETE: 'Tous les employés se sont connectés et ont choisi leur mot de passe.',
}[props.handover.status] ?? ''));

const deadline = computed(() => deadlineUrgency(props.handover.next_deadline));

const tone = (state) => ({
    done: 'bg-primary text-primary-foreground ring-primary',
    current: 'bg-card text-primary ring-2 ring-primary',
    pending: 'bg-card text-muted-foreground ring-1 ring-border',
    skipped: 'bg-muted text-muted-foreground/60 ring-1 ring-border',
    failed: 'bg-red-50 text-red-600 ring-1 ring-red-300 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
}[state]);
</script>

<template>
    <article class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <!-- En-tête : quoi, où, où en est-on -->
        <header class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:p-5">
            <span :class="cn('grid h-11 w-11 shrink-0 place-items-center rounded-xl', handover.status === 'COMPLETE' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300' : handover.status === 'TO_REOPEN' ? 'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300' : 'bg-primary/10 text-primary')" aria-hidden="true">
                <KeyRound class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-base font-semibold text-foreground">{{ count }} accès<span v-if="showSite" class="font-normal text-muted-foreground"> · {{ handover.site_name }}</span></h3>
                    <Badge :tone="handoverTone(handover.status)" class="py-0.5 text-[11px]">
                        <component :is="STATUS_ICONS[handover.status] ?? Clock" class="h-3.5 w-3.5" aria-hidden="true" />{{ handover.status_label }}
                    </Badge>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">{{ nextStep }}</p>
            </div>
            <div v-if="isDraft" class="shrink-0">
                <Button type="button" :disabled="!canSend || sending || noReceiver" @click="emit('send', handover)">
                    <Loader2 v-if="sending" class="h-4 w-4 animate-spin" aria-hidden="true" />
                    <Send v-else class="h-4 w-4" aria-hidden="true" />Envoyer au RH
                </Button>
            </div>
        </header>

        <!-- Frise d'avancement -->
        <div class="border-y border-border bg-muted/25 px-4 py-4 sm:px-5">
            <ol class="grid gap-3 sm:flex sm:items-start sm:gap-0" aria-label="Avancement de la remise">
                <li v-for="(step, index) in steps" :key="step.key" class="flex items-start gap-3 sm:flex-1 sm:flex-col sm:items-center sm:gap-2 sm:text-center">
                    <div class="flex items-center sm:w-full">
                        <span :class="cn('hidden h-0.5 flex-1 sm:block', index === 0 ? 'bg-transparent' : ['done', 'failed', 'skipped'].includes(step.state) ? 'bg-primary/60' : 'bg-border')" aria-hidden="true" />
                        <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full', tone(step.state))">
                            <Check v-if="step.state === 'done'" class="h-4 w-4" aria-hidden="true" />
                            <component :is="STEP_ICONS[step.key]" v-else class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span :class="cn('hidden h-0.5 flex-1 sm:block', index === steps.length - 1 ? 'bg-transparent' : ['done', 'failed', 'skipped'].includes(steps[index + 1].state) ? 'bg-primary/60' : 'bg-border')" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p :class="cn('text-xs font-semibold', step.state === 'pending' || step.state === 'skipped' ? 'text-muted-foreground' : step.state === 'failed' ? 'text-red-700 dark:text-red-400' : 'text-foreground')">{{ step.label }}</p>
                        <p class="text-[11px] text-muted-foreground">
                            <template v-if="step.at">{{ formatDateTime(step.at) }}</template>
                            <template v-else-if="step.detail">{{ step.detail }}</template>
                            <template v-else-if="step.state === 'current'">à venir</template>
                            <template v-else>—</template>
                        </p>
                        <p v-if="step.by" class="truncate text-[11px] text-muted-foreground/80">{{ step.by }}</p>
                    </div>
                </li>
            </ol>
            <p class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
                <ShieldCheck class="h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" />
                Aucun mot de passe créé ni transmis : chaque employé choisit le sien à sa première connexion.
                <template v-if="deadline"> Délai le plus proche : le {{ formatDateTime(handover.next_deadline) }}.</template>
            </p>
        </div>

        <!-- Les comptes de la remise : colonnes sur grand écran, empilés sur téléphone -->
        <div>
            <div class="hidden grid-cols-[minmax(0,1.3fr)_minmax(0,1.2fr)_minmax(0,0.8fr)_minmax(0,0.9fr)] gap-4 px-5 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground sm:grid" aria-hidden="true">
                <span>Employé</span><span>Identifiant (compte et messagerie)</span><span>Rôle</span><span>Première connexion</span>
            </div>
            <ul class="divide-y divide-border border-t border-border sm:border-t-0" :aria-label="`Comptes de la remise (${count})`">
                <li v-for="item in handover.items" :key="item.uuid" class="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1.2fr)_minmax(0,0.8fr)_minmax(0,0.9fr)] sm:items-center sm:gap-4 sm:px-5 sm:py-2.5">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold', avatarTone(item.uuid))" aria-hidden="true">{{ initialsOf({ name: item.employee_name }) }}</span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-foreground">{{ item.employee_name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ [item.job_title, item.employee_number].filter(Boolean).join(' · ') || '—' }}</p>
                        </div>
                    </div>
                    <div class="min-w-0 ps-[2.625rem] sm:ps-0">
                        <span class="flex min-w-0 items-center gap-1.5 font-mono text-xs text-foreground">
                            <AtSign class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" /><span class="truncate" :title="item.login_email">{{ item.login_email }}</span>
                        </span>
                        <p v-if="item.mailbox_address && item.mailbox_address !== item.login_email" class="truncate text-[11px] text-muted-foreground">Messagerie : {{ item.mailbox_address }}</p>
                    </div>
                    <div class="ps-[2.625rem] sm:ps-0">
                        <Badge variant="outline" class="py-0.5 text-[11px]">{{ item.role }}<template v-if="item.profile"> · {{ item.profile }}</template></Badge>
                    </div>
                    <div class="ps-[2.625rem] sm:ps-0">
                        <Badge v-if="!isDraft" :tone="itemStateTone(item.state)" class="py-0.5 text-[11px]" :title="item.activated_at ? `Le ${formatDateTime(item.activated_at)}` : item.open_until ? `Jusqu’au ${formatDateTime(item.open_until)}` : ''">
                            {{ item.state_label }}
                        </Badge>
                        <span v-else class="text-[11px] text-muted-foreground">après l’envoi</span>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Personne au site ne peut recevoir : le Super Admin désigne qui remettra (ADR-199) -->
        <div v-if="noReceiver" class="border-t border-border p-4 sm:px-5">
            <StaffAccessReceiverPicker
                :site="handover.site_code"
                :site-name="handover.site_name"
                :accounts="handover.accounts ?? []"
                :can-designate="canDesignate"
                @designated="emit('designated', $event)"
            />
        </div>

    </article>
</template>
