<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Banknote, CalendarDays, ChevronLeft, ChevronRight, HandCoins, Hourglass, Pencil, Plus, Stethoscope, Trash2, Users } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';
import { monthLabel, shiftMonth } from '@/utilities/bonus';
import { lineProblem } from '@/utilities/advantageEntries';

/**
 * ADR-227 — les avantages saisis d'un mois : par médecin, leur nombre, leur total, ce qui
 * attend la paie et ce qui est déjà payé. Un avantage se corrige ou se supprime tant que
 * la paie ne l'a pas porté.
 */
const props = defineProps({
    month: { type: String, required: true },
    currentMonth: { type: String, required: true },
    entries: { type: Object, required: true },
});
const emit = defineEmits(['add']);

const { can } = usePermissions();

const goTo = (month) => router.get(hrUrl('/administration/bonus'), { mois: month, onglet: 'saisis' }, { preserveScroll: true, preserveState: true });

const cards = computed(() => [
    { key: 'people', icon: Users, tone: 'bg-primary/10 text-primary', value: props.entries.summary.people, label: 'Médecins', hint: `${props.entries.summary.count} avantage${props.entries.summary.count > 1 ? 's' : ''}` },
    { key: 'pending', icon: Hourglass, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', value: formatMoney(props.entries.summary.pending_total), label: 'En attente de paie', hint: 'Rejoint la paie du mois' },
    { key: 'paid', icon: Banknote, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', value: formatMoney(props.entries.summary.paid_total), label: 'Payés', hint: 'Portés par une paie marquée payée' },
]);

// — Corriger une ligne. —
const editing = ref(null);
const editForm = useForm({ amount: '', reason: '' });
const openEdit = (person, entry) => {
    editForm.clearErrors();
    editForm.amount = String(Number(entry.amount));
    editForm.reason = entry.reason;
    editing.value = { person, entry };
};
const editProblem = computed(() => lineProblem({ amount: editForm.amount, reason: editForm.reason, period: props.month }));
const confirmEdit = () => editForm.put(hrUrl(`/administration/bonus/avantages/saisis/${editing.value.entry.uuid}`), {
    preserveScroll: true,
    onSuccess: () => { editing.value = null; },
});

// — Supprimer une ligne. —
const removing = ref(null);
const removeForm = useForm({});
const confirmRemove = () => removeForm.delete(hrUrl(`/administration/bonus/avantages/saisis/${removing.value.entry.uuid}`), {
    preserveScroll: true,
    onSuccess: () => { removing.value = null; },
});
const removeError = computed(() => Object.values(removeForm.errors)[0] ?? '');
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1 rounded-xl border border-border bg-card p-1 shadow-sm">
                <Button type="button" variant="ghost" size="icon" aria-label="Mois précédent" @click="goTo(shiftMonth(month, -1))"><ChevronLeft class="h-4 w-4" /></Button>
                <span class="flex min-w-44 items-center justify-center gap-2 px-2 text-sm font-semibold capitalize text-foreground">
                    <CalendarDays class="h-4 w-4 text-muted-foreground" />{{ monthLabel(month) }}
                </span>
                <Button type="button" variant="ghost" size="icon" aria-label="Mois suivant" :disabled="month >= shiftMonth(currentMonth, 1)" @click="goTo(shiftMonth(month, 1))"><ChevronRight class="h-4 w-4" /></Button>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="can('salary_payments.view')" :as="Link" :href="hrUrl(`/administration/paie?mois=${month}`)" variant="outline"><Banknote class="h-4 w-4" />Paie du mois</Button>
                <Button v-if="can('advantage_entries.create')" type="button" @click="emit('add')"><Plus class="h-4 w-4" />Saisir des avantages</Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div v-for="card in cards" :key="card.key" class="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone]"><component :is="card.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-xl font-bold leading-none tabular-nums text-foreground">{{ card.value }}</span>
                    <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                    <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                </span>
            </div>
        </div>

        <Card v-if="! entries.people.length" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><HandCoins class="h-6 w-6" /></span>
            <p class="text-sm font-semibold text-foreground">Aucun avantage saisi pour {{ monthLabel(month) }}</p>
            <p class="max-w-md text-sm text-muted-foreground">Saisissez les primes des médecins (montant et motif, ex. ECHO) : elles s'ajoutent à leur salaire dans la paie du mois.</p>
            <Button v-if="can('advantage_entries.create')" type="button" @click="emit('add')"><Plus class="h-4 w-4" />Saisir des avantages</Button>
        </Card>

        <Card v-else class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-start font-medium">Médecin</th>
                            <th scope="col" class="px-4 py-2 text-end font-medium">Avantages</th>
                            <th scope="col" class="px-4 py-2 text-end font-medium">En attente</th>
                            <th scope="col" class="px-4 py-2 text-end font-medium">Payé</th>
                            <th scope="col" class="px-4 py-2 text-end font-medium">Total</th>
                        </tr>
                    </thead>
                    <template v-for="person in entries.people" :key="person.uuid">
                        <tbody class="border-t border-border">
                            <tr class="bg-card">
                                <td class="px-4 py-2">
                                    <span class="flex items-center gap-2">
                                        <Stethoscope class="h-4 w-4 shrink-0 text-muted-foreground" />
                                        <span>
                                            <span class="block font-semibold text-foreground">{{ person.name }}</span>
                                            <span class="block text-xs text-muted-foreground">{{ [person.employee_number, person.job_title].filter(Boolean).join(' · ') }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-end tabular-nums text-foreground">{{ person.count }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-muted-foreground">{{ formatMoney(person.pending_total) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-muted-foreground">{{ formatMoney(person.paid_total) }}</td>
                                <td class="px-4 py-2 text-end font-semibold tabular-nums text-foreground">{{ formatMoney(person.total) }}</td>
                            </tr>
                            <tr v-for="entry in person.entries" :key="entry.uuid" class="text-xs">
                                <td class="py-1.5 pe-4 ps-10">
                                    <span class="font-medium text-foreground">{{ entry.reason }}</span>
                                    <span class="text-muted-foreground"> · {{ entry.created_by }} · {{ formatDateTime(entry.created_at) }}</span>
                                </td>
                                <td class="px-4 py-1.5 text-end">
                                    <Badge :variant="entry.status === 'PAID' ? 'success' : 'warning'">{{ entry.status_label }}</Badge>
                                </td>
                                <td class="px-4 py-1.5 text-end tabular-nums text-foreground" colspan="2">{{ formatMoney(entry.amount) }}</td>
                                <td class="px-4 py-1.5 text-end">
                                    <span v-if="entry.editable" class="inline-flex gap-1">
                                        <Button v-if="can('advantage_entries.update')" type="button" variant="ghost" size="icon" :aria-label="`Modifier ${entry.reason}`" @click="openEdit(person, entry)"><Pencil class="h-4 w-4" /></Button>
                                        <Button v-if="can('advantage_entries.delete')" type="button" variant="ghost" size="icon" class="text-destructive hover:text-destructive" :aria-label="`Supprimer ${entry.reason}`" @click="removeForm.clearErrors(); removing = { person, entry }"><Trash2 class="h-4 w-4" /></Button>
                                    </span>
                                    <span v-else class="text-muted-foreground">Payé avec la paie</span>
                                </td>
                            </tr>
                        </tbody>
                    </template>
                    <tfoot class="border-t-2 border-border bg-muted/30 text-sm">
                        <tr>
                            <th scope="row" class="px-4 py-2 text-start font-semibold text-foreground">Total · {{ entries.summary.people }} médecin{{ entries.summary.people > 1 ? 's' : '' }}</th>
                            <td class="px-4 py-2 text-end tabular-nums text-foreground">{{ entries.summary.count }}</td>
                            <td class="px-4 py-2 text-end tabular-nums text-muted-foreground">{{ formatMoney(entries.summary.pending_total) }}</td>
                            <td class="px-4 py-2 text-end tabular-nums text-muted-foreground">{{ formatMoney(entries.summary.paid_total) }}</td>
                            <td class="px-4 py-2 text-end font-bold tabular-nums text-foreground">{{ formatMoney(entries.summary.total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>

        <ConfirmModal
            :open="editing !== null"
            title="Modifier l’avantage"
            :description="editing ? `${editing.person.name} · ${monthLabel(month)}` : ''"
            confirm-label="Enregistrer"
            :icon="Pencil"
            :processing="editForm.processing"
            :disabled="editProblem !== null"
            :dismissible="false"
            @update:open="(open) => open || editForm.processing || (editing = null)"
            @confirm="confirmEdit"
        >
            <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                <FormField label="Montant (Ar)" required :error="editForm.errors.amount">
                    <Input v-model="editForm.amount" inputmode="decimal" />
                </FormField>
                <FormField label="Motif" required :error="editForm.errors.reason">
                    <Input v-model="editForm.reason" maxlength="160" />
                </FormField>
            </div>
            <p v-if="editForm.errors.entry" class="mt-2 text-sm font-medium text-destructive">{{ editForm.errors.entry }}</p>
        </ConfirmModal>

        <ConfirmModal
            :open="removing !== null"
            title="Supprimer l’avantage"
            :description="removing ? `${removing.entry.reason} · ${formatMoney(removing.entry.amount)} · ${removing.person.name}` : ''"
            confirm-label="Supprimer"
            tone="danger"
            :icon="Trash2"
            :processing="removeForm.processing"
            :dismissible="false"
            @update:open="(open) => open || removeForm.processing || (removing = null)"
            @confirm="confirmRemove"
        >
            <p class="text-sm text-muted-foreground">Il ne rejoindra pas la paie. Il reste tracé dans l’audit.</p>
            <p v-if="removeError" class="mt-2 text-sm font-medium text-destructive">{{ removeError }}</p>
        </ConfirmModal>
    </div>
</template>
