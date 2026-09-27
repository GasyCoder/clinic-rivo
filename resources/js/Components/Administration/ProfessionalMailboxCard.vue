<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowRight, AtSign, CircleAlert, Clock, KeyRound, MailCheck, MailX, Undo2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import { hrUrl } from '@/utilities/hrUrl';
import { formatDateTime } from '@/utilities/date';
import { usePermissions } from '@/composables/usePermissions';

/**
 * ADR-190 — l'adresse email professionnelle de l'employé, sur sa fiche.
 *
 * ADR-197 — plus rien à demander ici : ajouter l'employé suffit. Le Super Admin
 * en est prévenu, crée ensemble son compte RIVO et son adresse (un seul mot de
 * passe) et envoie l'accès au RH, qui le remet (« Accès du personnel »). Une fois
 * créée, l'adresse devient l'email de la fiche. Au départ de l'employé, la boîte
 * est suspendue depuis le portail, jamais supprimée.
 */
const props = defineProps({
    data: { type: Object, required: true },
    employeeUuid: { type: String, required: true },
});

const { can } = usePermissions();
const current = computed(() => props.data.current);
const status = computed(() => current.value?.status ?? null);

const STATUS = {
    REQUESTED: { tone: 'warning', icon: Clock },
    ACTIVE: { tone: 'success', icon: MailCheck },
    SUSPENDED: { tone: 'secondary', icon: MailX },
    REJECTED: { tone: 'destructive', icon: MailX },
    CANCELLED: { tone: 'outline', icon: Undo2 },
};
const badgeVariant = (value) => STATUS[value]?.tone ?? 'outline';

const cancelOpen = ref(false);
const cancelling = ref(false);
const cancelRequest = () => {
    cancelling.value = true;
    router.post(hrUrl(`/administration/professional-mailboxes/${current.value.uuid}/cancel`), {}, {
        preserveScroll: true,
        onFinish: () => { cancelling.value = false; cancelOpen.value = false; },
    });
};
</script>

<template>
    <section class="rounded-xl border border-border bg-card p-5 shadow-sm" aria-labelledby="professional-mailbox-title">
        <div class="flex items-start gap-3">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><AtSign class="h-4 w-4" /></span>
            <div class="min-w-0 flex-1">
                <h2 id="professional-mailbox-title" class="font-heading text-lg font-bold text-foreground">Email professionnel</h2>
                <p class="mt-0.5 text-xs text-muted-foreground">Créée par le Super Admin avec le compte RIVO de l’employé.</p>
            </div>
        </div>

        <p v-if="! data.configured" class="mt-4 flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2.5 text-xs leading-5 text-muted-foreground">
            <CircleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />Le domaine des adresses professionnelles n’est pas encore configuré.
        </p>

        <!-- L'adresse connue : ouverte, ou la dernière refusée ou annulée. -->
        <div v-if="current" class="mt-4 rounded-lg border border-border p-3.5">
            <div class="flex flex-wrap items-center gap-2">
                <span class="break-all font-mono text-sm font-semibold text-foreground">{{ current.address }}</span>
                <Badge :variant="badgeVariant(status)" class="gap-1"><component :is="STATUS[status]?.icon" class="h-3 w-3" />{{ current.status_label }}</Badge>
            </div>
            <dl class="mt-2 space-y-1 text-xs text-muted-foreground">
                <div v-if="status === 'REQUESTED'">Demandée le {{ formatDateTime(current.requested_at) }}<template v-if="current.requested_by"> par {{ current.requested_by }}</template> — en attente du Super Admin.</div>
                <div v-if="status === 'ACTIVE' || status === 'SUSPENDED'">Créée le {{ formatDateTime(current.activated_at) }}<template v-if="current.decided_by"> par {{ current.decided_by }}</template> ; c’est l’email de la fiche.</div>
                <div v-if="status === 'SUSPENDED'">Suspendue le {{ formatDateTime(current.suspended_at) }} — {{ current.suspension_reason }}</div>
                <div v-if="status === 'REJECTED'">Refusée le {{ formatDateTime(current.decided_at) }} — {{ current.rejection_reason }}</div>
                <div v-if="current.request_note && status === 'REQUESTED'">Note : {{ current.request_note }}</div>
            </dl>
            <p v-if="current.to_suspend" class="mt-3 flex items-start gap-2 rounded-md bg-amber-50 px-2.5 py-2 text-xs leading-5 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                <CircleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />L’employé n’est plus en poste : le Super Admin suspend sa boîte depuis le portail.
            </p>
            <Button v-if="status === 'REQUESTED' && data.can_request" type="button" size="sm" variant="white-outline" class="mt-3" @click="cancelOpen = true">
                <Undo2 class="h-3.5 w-3.5" />Annuler la demande
            </Button>
        </div>
        <!-- ADR-197 — rien à demander : l'ajout de l'employé prévient le Super Admin. -->
        <p v-if="! current?.open && data.configured" class="mt-4 flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2.5 text-xs leading-5 text-muted-foreground">
            <KeyRound class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>
                <template v-if="! current">Pas encore d’adresse.</template>
                Le Super Admin est prévenu de l’ajout de l’employé : il crée son compte RIVO et son adresse, puis vous envoie l’accès à remettre.
                <Link v-if="can('staff_access.receive')" :href="hrUrl('/administration/staff-access')" class="font-semibold text-primary hover:underline">Accès du personnel</Link>
            </span>
        </p>

        <Link v-if="can('professional_emails.view')" :href="hrUrl('/administration/professional-emails')" class="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline">
            Toutes les adresses du site<ArrowRight class="h-3.5 w-3.5" />
        </Link>

        <ConfirmModal
            v-model:open="cancelOpen"
            title="Annuler la demande d’adresse ?"
            :description="current ? `La demande ${current.address} ne sera pas traitée. Elle reste dans l’historique.` : ''"
            confirm-label="Annuler la demande"
            cancel-label="Garder la demande"
            tone="danger"
            :processing="cancelling"
            @confirm="cancelRequest"
        />
    </section>
</template>
