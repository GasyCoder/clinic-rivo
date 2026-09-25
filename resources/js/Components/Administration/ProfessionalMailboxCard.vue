<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowRight, AtSign, CircleAlert, Clock, MailCheck, MailX, Send, Undo2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { hrUrl } from '@/utilities/hrUrl';
import { formatDateTime } from '@/utilities/date';
import { usePermissions } from '@/composables/usePermissions';

/**
 * ADR-190 — l'adresse email professionnelle de l'employé, sur sa fiche.
 *
 * Le RH la demande ici ; le Super Admin la crée depuis le portail — seul à
 * parler à l'hébergeur — et remet le mot de passe. Une fois créée, l'adresse
 * devient l'email de la fiche. Au départ de l'employé, la boîte est suspendue
 * depuis le portail, jamais supprimée.
 */
const props = defineProps({
    data: { type: Object, required: true },
    employeeUuid: { type: String, required: true },
});

const { can } = usePermissions();
const current = computed(() => props.data.current);
const status = computed(() => current.value?.status ?? null);
// Une demande se refait après un refus ou une annulation : seule une adresse ouverte l'empêche.
const canAskAgain = computed(() => props.data.can_request && props.data.configured && ! current.value?.open);

const STATUS = {
    REQUESTED: { tone: 'warning', icon: Clock },
    ACTIVE: { tone: 'success', icon: MailCheck },
    SUSPENDED: { tone: 'secondary', icon: MailX },
    REJECTED: { tone: 'destructive', icon: MailX },
    CANCELLED: { tone: 'outline', icon: Undo2 },
};
const badgeVariant = (value) => STATUS[value]?.tone ?? 'outline';

const form = useForm({ local_part: props.data.suggestion ?? '', note: '' });
const submit = () => form.post(hrUrl(`/administration/employees/${props.employeeUuid}/professional-mailbox`), {
    preserveScroll: true,
    onSuccess: () => form.reset('note'),
});

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
                <p class="mt-0.5 text-xs text-muted-foreground">Demandé ici ; créé chez l’hébergeur par qui en a reçu le droit.</p>
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
        <p v-else-if="data.configured && ! data.can_request" class="mt-4 text-sm text-muted-foreground">Aucune adresse professionnelle.</p>

        <!-- Demander une adresse. -->
        <form v-if="canAskAgain" class="mt-4 space-y-3" novalidate @submit.prevent="submit">
            <FormField label="Adresse demandée" required :error="form.errors.local_part">
                <div class="flex items-stretch">
                    <Input v-model="form.local_part" class="rounded-e-none font-mono" autocomplete="off" spellcheck="false" :aria-invalid="Boolean(form.errors.local_part)" />
                    <span class="inline-flex items-center rounded-e-md border border-s-0 border-input bg-muted px-3 font-mono text-sm text-muted-foreground">@{{ data.domain }}</span>
                </div>
            </FormField>
            <p v-if="! form.errors.local_part" class="-mt-1 text-xs text-muted-foreground">Proposée depuis la fiche (prenom.nom). Le Super Admin peut l’ajuster si elle est déjà prise sur un autre site.</p>
            <FormField label="Note pour le Super Admin" :error="form.errors.note">
                <Textarea v-model="form.note" rows="2" placeholder="Facultatif — service, date d’arrivée…" />
            </FormField>
            <Button type="submit" size="sm" :disabled="form.processing || ! form.local_part.trim()">
                <Send class="h-3.5 w-3.5" />{{ current ? 'Demander une nouvelle adresse' : 'Demander la création' }}
            </Button>
        </form>

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
