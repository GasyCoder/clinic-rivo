<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    AtSign,
    CalendarClock,
    Check,
    CheckCircle2,
    Clock,
    KeyRound,
    Send,
    TimerOff,
    UserCheck,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime } from '@/utilities/date';
import { hrUrl } from '@/utilities/hrUrl';
import { deadlineUrgency, handoverSteps, handoverTone, itemStateTone, receivedAction } from '@/utilities/staffAccess';
import { avatarTone, initialsOf } from '@/utilities/webmail';

/**
 * ADR-197 / ADR-202 — une remise reçue par le RH, lue d'un coup d'œil : ce qu'elle
 * attend de lui, où en sont les premières connexions, et à qui elle revient. Aucun
 * mot de passe : chaque employé choisit le sien.
 */
const props = defineProps({
    handover: { type: Object, required: true },
    /** Au-delà, « +N autres » : la remise ouverte les montre tous. */
    visibleItems: { type: Number, default: 6 },
});

const STATUS_ICONS = { WAITING: CalendarClock, TO_REOPEN: TimerOff, COMPLETE: CheckCircle2 };
const STEP_ICONS = { sent: Send, activated: UserCheck };
const STATE_ICONS = { ACTIVATED: UserCheck, WAITING: Clock, EXPIRED: TimerOff };

const count = computed(() => props.handover.items?.length ?? 0);
const action = computed(() => receivedAction(props.handover.status));
const deadline = computed(() => (props.handover.status === 'WAITING' ? deadlineUrgency(props.handover.next_deadline) : null));
const todo = computed(() => ['WAITING', 'TO_REOPEN'].includes(props.handover.status));
// Le RH ne voit pas la création : envoyés, puis premières connexions.
const steps = computed(() => handoverSteps(props.handover).filter((step) => step.key !== 'created'));
const shown = computed(() => (props.handover.items ?? []).slice(0, props.visibleItems));
const hidden = computed(() => Math.max(0, count.value - shown.value.length));
const href = computed(() => hrUrl(`/administration/staff-access/${props.handover.uuid}`));

const title = computed(() => {
    const counts = props.handover.counts ?? {};

    return {
        WAITING: `${counts.waiting ?? count.value} employé${(counts.waiting ?? count.value) > 1 ? 's' : ''} à prévenir`,
        TO_REOPEN: `${counts.expired} délai${counts.expired > 1 ? 's' : ''} dépassé${counts.expired > 1 ? 's' : ''}`,
        COMPLETE: `${count.value} accès · tous connectés`,
    }[props.handover.status] ?? `${count.value} accès`;
});

const ICON_TILE = {
    WAITING: 'bg-primary/10 text-primary',
    TO_REOPEN: 'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300',
    COMPLETE: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
};

const URGENCY = {
    danger: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
    warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
    neutral: 'border-border bg-muted/40 text-muted-foreground',
};

const stepTone = (state) => ({
    done: 'bg-primary text-primary-foreground',
    current: 'bg-card text-primary ring-2 ring-primary',
    pending: 'bg-card text-muted-foreground ring-1 ring-border',
    failed: 'bg-red-50 text-red-600 ring-1 ring-red-300 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
}[state]);
</script>

<template>
    <article
        :class="cn(
            'overflow-hidden rounded-xl border bg-card shadow-sm transition-shadow hover:shadow-md',
            handover.status === 'TO_REOPEN' ? 'border-red-200 dark:border-red-900' : todo ? 'border-primary/25' : 'border-border',
        )"
    >
        <!-- Ce que la remise attend -->
        <header class="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:p-5">
            <div class="flex min-w-0 flex-1 items-start gap-3.5">
                <span :class="cn('grid h-11 w-11 shrink-0 place-items-center rounded-xl', ICON_TILE[handover.status] ?? ICON_TILE.WAITING)" aria-hidden="true">
                    <KeyRound class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-base font-semibold text-foreground">{{ title }}</h3>
                        <Badge :tone="handoverTone(handover.status)" class="py-0.5 text-[11px]">
                            <component :is="STATUS_ICONS[handover.status] ?? KeyRound" class="h-3.5 w-3.5" aria-hidden="true" />{{ handover.status_label }}
                        </Badge>
                        <span
                            v-if="deadline"
                            :class="cn('inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold', URGENCY[deadline.tone])"
                            :title="`Premier délai : le ${formatDateTime(handover.next_deadline)}`"
                        >
                            <Clock class="h-3.5 w-3.5" aria-hidden="true" />{{ deadline.label }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">{{ action.hint }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground/90">
                        Envoyés le {{ formatDateTime(handover.sent_at) }}<template v-if="handover.sent_by"> par {{ handover.sent_by }}</template>
                    </p>
                </div>
            </div>
            <Button :as="Link" :href="href" :variant="action.primary ? 'default' : 'white-outline'" class="shrink-0 self-stretch sm:self-start">
                {{ action.label }}<ArrowRight class="h-4 w-4" aria-hidden="true" />
            </Button>
        </header>

        <!-- Où elle en est -->
        <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-2 border-y border-border bg-muted/25 px-4 py-3 sm:px-5" aria-label="Avancement de la remise">
            <template v-for="(step, index) in steps" :key="step.key">
                <li class="flex min-w-0 items-center gap-2">
                    <span :class="cn('grid h-6 w-6 shrink-0 place-items-center rounded-full', stepTone(step.state))" aria-hidden="true">
                        <Check v-if="step.state === 'done'" class="h-3.5 w-3.5" />
                        <component :is="STEP_ICONS[step.key]" v-else class="h-3.5 w-3.5" />
                    </span>
                    <span class="min-w-0 leading-tight">
                        <span :class="cn('block text-xs font-semibold', step.state === 'failed' ? 'text-red-700 dark:text-red-400' : step.state === 'pending' ? 'text-muted-foreground' : 'text-foreground')">{{ step.label }}</span>
                        <span class="block text-[11px] text-muted-foreground">
                            <template v-if="step.at">{{ formatDateTime(step.at) }}</template>
                            <template v-else-if="step.detail">{{ step.detail }}</template>
                            <template v-else>—</template>
                        </span>
                    </span>
                </li>
                <li v-if="index < steps.length - 1" class="mx-1 hidden h-px w-6 bg-border sm:block lg:w-10" aria-hidden="true" />
            </template>
        </ol>

        <!-- À qui elle revient, et qui s'est connecté -->
        <ul class="grid gap-2 p-4 sm:grid-cols-2 sm:px-5 xl:grid-cols-3" :aria-label="`Employés de la remise (${count})`">
            <li v-for="item in shown" :key="item.uuid" class="flex min-w-0 items-center gap-2.5 rounded-lg border border-border bg-background/60 px-3 py-2">
                <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold', avatarTone(item.uuid))" aria-hidden="true">{{ initialsOf({ name: item.employee_name }) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-foreground">{{ item.employee_name }}</span>
                    <span class="flex min-w-0 items-center gap-1 font-mono text-[11px] text-muted-foreground/90">
                        <AtSign class="h-3 w-3 shrink-0" aria-hidden="true" /><span class="truncate" :title="item.login_email">{{ item.login_email }}</span>
                    </span>
                    <Badge :tone="itemStateTone(item.state)" class="mt-1 py-0 text-[10px]" :title="item.activated_at ? `Le ${formatDateTime(item.activated_at)}` : item.open_until ? `Jusqu’au ${formatDate(item.open_until)}` : ''">
                        <component :is="STATE_ICONS[item.state] ?? UserCheck" class="h-3 w-3" aria-hidden="true" />{{ item.state_label }}
                    </Badge>
                </span>
            </li>
            <li v-if="hidden" class="flex items-center">
                <Link :href="href" class="rounded-lg px-3 py-2 text-xs font-semibold text-primary hover:underline">+{{ hidden }} autre{{ hidden > 1 ? 's' : '' }} — ouvrir la remise</Link>
            </li>
        </ul>
    </article>
</template>
