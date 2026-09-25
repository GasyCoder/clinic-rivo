<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Ban, CalendarDays, CircleCheck, CircleX, Clock, ClipboardList, Plus, Printer, UserRound,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import HrPagination from '../Partials/HrPagination.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate } from '@/utilities/date';
import { formatDays, initials } from '@/utilities/hr';

defineOptions({ layout: AppLayout });

/*
 * ADR-066, ADR-069 — les demandes de congé et leurs décisions.
 *
 * Chaque décision passe par une fenêtre qui dit ce qu'elle fait et montre la
 * réponse du serveur : accepter recalcule le solde et peut être refusé
 * (« solde annuel insuffisant »). Les trois petits formulaires dépliés d'avant
 * n'affichaient aucune de ces erreurs — le refus passait inaperçu.
 */
const props = defineProps({ leaves: Object, filterStatus: String, summary: Object, statuses: [Array, Object] });
const { can } = usePermissions();

const total = computed(() => Object.values(props.summary).reduce((sum, value) => sum + Number(value ?? 0), 0));

const STATUS = {
    PENDING: { icon: Clock, tone: 'amber', badge: 'warning', hint: 'Décision à prendre' },
    APPROVED: { icon: CircleCheck, tone: 'emerald', badge: 'success', hint: 'Décisions enregistrées' },
    REJECTED: { icon: CircleX, tone: 'rose', badge: 'danger', hint: 'Décisions enregistrées' },
    CANCELLED: { icon: Ban, tone: 'slate', badge: 'neutral', hint: 'Demandes retirées' },
};

// --- Décider ----------------------------------------------------------------
const DECISIONS = {
    approve: {
        title: 'Accepter la demande',
        description: 'Le solde est recalculé par le serveur depuis l’historique au moment de l’acceptation.',
        label: 'Note', hint: '(facultatif)', required: false, confirm: 'Accepter', variant: 'success', icon: CircleCheck,
    },
    reject: {
        title: 'Refuser la demande',
        description: 'Le motif est conservé avec la décision et communiqué à l’employé.',
        label: 'Motif du refus', hint: '', required: true, confirm: 'Refuser', variant: 'destructive', icon: CircleX,
    },
    cancel: {
        title: 'Annuler la demande',
        description: 'La demande est retirée sans être effacée : elle reste dans l’historique, avec son motif.',
        label: 'Motif de l’annulation', hint: '', required: true, confirm: 'Annuler la demande', variant: 'outline', icon: Ban,
    },
};

const deciding = ref(null);
const decisionForm = useForm({ reason: '' });
const current = computed(() => (deciding.value ? DECISIONS[deciding.value.action] : null));
const decisionErrors = computed(() => Object.values(decisionForm.errors));

const openDecision = (leave, action) => {
    decisionForm.reset();
    decisionForm.clearErrors();
    deciding.value = { leave, action };
};
const submitDecision = () => {
    if (current.value.required && !decisionForm.reason.trim()) return;
    decisionForm.post(hrUrl(`/administration/leave/${deciding.value.leave.uuid}/${deciding.value.action}`), {
        preserveScroll: true,
        onSuccess: () => { deciding.value = null; },
    });
};
const canDecide = computed(() => can('leave.approve') || can('leave.reject') || can('leave.cancel'));
</script>

<template>
    <Head title="Congés" />

    <div class="space-y-5">
        <PageHeader
            eyebrow="Ressources humaines"
            title="Congés"
            description="Les demandes et leurs décisions. La durée et le solde sont calculés par le serveur selon les règles de chaque type ; toute décision est tracée."
            :icon="CalendarDays"
            tone="amber"
        >
            <template #actions>
                <Button v-if="can('leave.create')" :as="Link" :href="hrUrl('/administration/leave/create')"><Plus class="h-4 w-4" />Nouvelle demande</Button>
            </template>
        </PageHeader>

        <!-- La carte est le filtre. -->
        <section class="grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
            <Link :href="hrUrl('/administration/leave?status=ALL')" :aria-current="filterStatus === 'ALL' ? 'page' : undefined">
                <HrStatCard label="Toutes" :value="total" hint="Tous les états" :icon="ClipboardList" tone="primary" :active="filterStatus === 'ALL'" />
            </Link>
            <Link
                v-for="status in statuses"
                :key="status.value"
                :href="hrUrl(`/administration/leave?status=${status.value}`)"
                :aria-current="filterStatus === status.value ? 'page' : undefined"
            >
                <HrStatCard
                    :label="status.label"
                    :value="summary[status.value]"
                    :hint="STATUS[status.value]?.hint"
                    :icon="STATUS[status.value]?.icon"
                    :tone="STATUS[status.value]?.tone"
                    :active="filterStatus === status.value"
                />
            </Link>
        </section>

        <section v-if="leaves.data.length" class="space-y-3">
            <article v-for="leave in leaves.data" :key="leave.uuid" class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                    <!-- Qui, quoi, pourquoi -->
                    <div class="flex min-w-0 gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">{{ initials(leave.employee.name) }}</span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    v-if="can('employees.view')"
                                    :href="hrUrl(`/administration/employees/${leave.employee.uuid}`)"
                                    class="font-semibold text-foreground hover:text-primary hover:underline"
                                >{{ leave.employee.name }}</Link>
                                <span v-else class="font-semibold text-foreground">{{ leave.employee.name }}</span>
                                <Badge :tone="STATUS[leave.status]?.badge">{{ leave.status_label }}</Badge>
                                <Badge v-if="leave.leave_type" variant="outline">{{ leave.leave_type }}</Badge>
                            </div>
                            <p class="mt-0.5 font-mono text-xs text-muted-foreground">{{ leave.employee.employee_number }}</p>
                            <p v-if="leave.reason" class="mt-2 text-sm leading-6 text-muted-foreground">{{ leave.reason }}</p>
                            <p v-if="leave.interim_employee" class="mt-1 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                <UserRound class="h-3.5 w-3.5" />Intérim : {{ leave.interim_employee.name }}
                            </p>
                        </div>
                    </div>

                    <!-- La période et le compte -->
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4 lg:grid-cols-2">
                        <div>
                            <dt class="text-xs text-muted-foreground">Premier jour</dt>
                            <dd class="mt-0.5 font-semibold text-foreground">{{ formatDate(leave.starts_on) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Dernier jour</dt>
                            <dd class="mt-0.5 font-semibold text-foreground">{{ formatDate(leave.returns_on) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Durée</dt>
                            <dd class="mt-0.5 font-semibold text-foreground">{{ formatDays(leave.days_requested) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Solde après la demande</dt>
                            <dd class="mt-0.5 font-semibold text-foreground">{{ leave.consumes_balance_snapshot ? formatDays(leave.projected_remaining_days_snapshot) : 'Non concerné' }}</dd>
                        </div>
                    </dl>
                </div>

                <p v-if="leave.decision_reason || leave.cancel_reason" class="border-t border-border bg-muted/30 px-5 py-2.5 text-xs text-muted-foreground">
                    <strong class="text-foreground">{{ leave.status === 'CANCELLED' ? 'Annulation' : 'Décision' }} :</strong>
                    {{ leave.decision_reason || leave.cancel_reason }}<span v-if="leave.decided_by"> · {{ leave.decided_by }}</span>
                </p>

                <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-5 py-3">
                    <p class="text-xs text-muted-foreground">Demandée le {{ formatDate(leave.requested_on) }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <template v-if="leave.status === 'PENDING' && canDecide">
                            <Button v-if="can('leave.approve')" size="sm" variant="success" @click="openDecision(leave, 'approve')"><CircleCheck class="h-4 w-4" />Accepter</Button>
                            <Button v-if="can('leave.reject')" size="sm" variant="danger-outline" @click="openDecision(leave, 'reject')"><CircleX class="h-4 w-4" />Refuser</Button>
                            <Button v-if="can('leave.cancel')" size="sm" variant="ghost" @click="openDecision(leave, 'cancel')"><Ban class="h-4 w-4" />Annuler</Button>
                        </template>
                        <Button
                            v-if="can('leave.print')"
                            :as="Link"
                            :href="hrUrl(`/administration/leave/${leave.uuid}/print`)"
                            size="icon"
                            variant="outline"
                            :aria-label="`Imprimer la demande de ${leave.employee.name}`"
                            title="Imprimer"
                        ><Printer class="h-4 w-4" /></Button>
                    </div>
                </footer>
            </article>

            <div class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm"><HrPagination :paginator="leaves" /></div>
        </section>

        <section v-else class="rounded-2xl border border-border bg-card shadow-sm">
            <EmptyState :icon="CalendarDays" title="Aucune demande dans cet état" description="Choisissez un autre état, ou enregistrez une nouvelle demande de congé." />
        </section>

        <Dialog
            v-if="deciding"
            :open="Boolean(deciding)"
            :title="current.title"
            :description="current.description"
            :dismissible="false"
            @update:open="(value) => { if (!value) deciding = null; }"
        >
            <div class="space-y-4">
                <div class="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm">
                    <p class="font-semibold text-foreground">{{ deciding.leave.employee.name }} · {{ deciding.leave.leave_type }}</p>
                    <p class="mt-0.5 text-muted-foreground">
                        Du {{ formatDate(deciding.leave.starts_on) }} au {{ formatDate(deciding.leave.returns_on) }} · {{ formatDays(deciding.leave.days_requested) }}
                    </p>
                </div>

                <div v-if="decisionErrors.length" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200" role="alert">
                    <p v-for="message in decisionErrors" :key="message">{{ message }}</p>
                </div>

                <FormField :label="current.label" :hint="current.hint" :required="current.required">
                    <Textarea v-model="decisionForm.reason" rows="3" maxlength="1000" :placeholder="current.required ? 'Obligatoire' : 'Ex. validé par la direction'" />
                </FormField>
            </div>

            <template #footer>
                <Button variant="outline" @click="deciding = null">Retour</Button>
                <Button
                    :variant="current.variant"
                    :disabled="decisionForm.processing || (current.required && !decisionForm.reason.trim())"
                    @click="submitDecision"
                ><component :is="current.icon" class="h-4 w-4" />{{ current.confirm }}</Button>
            </template>
        </Dialog>
    </div>
</template>
