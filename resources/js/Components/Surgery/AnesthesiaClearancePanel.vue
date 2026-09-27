<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import AnesthesiaClearanceBadge from '@/Components/Surgery/AnesthesiaClearanceBadge.vue';
import { cn } from '@/lib/cn';
import { CircleCheck, CircleAlert, CircleSlash, PauseCircle, Plus, ShieldQuestion, Trash2, UserRound } from 'lucide-vue-next';

/**
 * ADR-170 — la décision d'anesthésie : prononcée par l'anesthésiste, **lue**
 * par le chirurgien.
 *
 * Le même composant sert les deux espaces, et c'est voulu : le bloc doit voir
 * exactement ce que l'anesthésie a décidé, motif compris. Ce qui change est le
 * droit d'écrire (`anesthesia.can_decide`, calculé par le serveur) — jamais ce
 * qui est affiché.
 *
 * Valider l'évaluation ne vaut pas autorisation : tant que personne n'a
 * décidé, le statut reste « Aucune décision » et l'incision est retenue. Une
 * absence n'est pas une décision.
 */
const props = defineProps({
    /** @type {{record_exists, anesthetist, clearance, conditions: [], can_decide, can_write}} */
    anesthesia: { type: Object, default: null },
    base: { type: String, required: true },
    recordId: { type: [Number, String], default: null },
});

const CHOICES = [
    { value: 'CLEARED', label: 'Autorisé', icon: CircleCheck, hint: 'Le bloc peut avoir lieu.' },
    { value: 'CLEARED_WITH_CONDITIONS', label: 'Autorisé sous conditions', icon: CircleAlert, hint: 'Chaque condition devra être levée avant l’incision.' },
    { value: 'NOT_CLEARED', label: 'Non autorisé', icon: CircleSlash, hint: 'Motif obligatoire.' },
    { value: 'DEFERRED', label: 'Reporté', icon: PauseCircle, hint: 'Motif obligatoire.' },
];

const clearance = computed(() => props.anesthesia?.clearance ?? null);
const conditions = computed(() => props.anesthesia?.conditions ?? []);
const openConditions = computed(() => conditions.value.filter((row) => row.open));

const editing = ref(false);
const form = useForm({
    status: clearance.value?.status && clearance.value.status !== 'DRAFT' ? clearance.value.status : '',
    reason: clearance.value?.reason ?? '',
    valid_until: '',
    conditions: openConditions.value.map((row) => row.label),
});

const needsReason = computed(() => ['NOT_CLEARED', 'DEFERRED'].includes(form.status));
const needsConditions = computed(() => form.status === 'CLEARED_WITH_CONDITIONS');

const addCondition = () => form.conditions.push('');
const removeCondition = (index) => form.conditions.splice(index, 1);

const submit = () => form
    .transform((data) => ({
        ...data,
        conditions: data.conditions.map((label) => label.trim()).filter(Boolean),
    }))
    .post(`${props.base}/anesthesia/${props.recordId}/clearance`, {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
    });

const resolveForm = useForm({ notes: '' });
const resolving = ref(null);
const resolve = (condition) => {
    resolving.value = condition.id;
    resolveForm.post(`${props.base}/anesthesia/${props.recordId}/clearance/conditions/${condition.id}/resolve`, {
        preserveScroll: true,
        onFinish: () => { resolving.value = null; resolveForm.reset(); },
    });
};
</script>

<template>
    <Card class="overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3.5">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300" aria-hidden="true">
                <ShieldQuestion class="h-4 w-4" />
            </span>
            <div class="min-w-0 flex-1 basis-48">
                <h3 class="text-sm font-semibold text-foreground">Autorisation anesthésique</h3>
                <p class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                    <UserRound class="h-3 w-3 shrink-0" aria-hidden="true" />
                    {{ anesthesia?.anesthetist ?? 'Aucun anesthésiste affecté' }}
                </p>
            </div>
            <AnesthesiaClearanceBadge :clearance="clearance" />
        </header>

        <div class="space-y-4 px-5 py-4">
            <p v-if="!anesthesia?.record_exists" class="text-sm text-muted-foreground">
                Le dossier d’anesthésie n’est pas encore ouvert. L’intervention ne peut pas démarrer tant qu’il
                ne l’est pas — en attente de l’anesthésiste.
            </p>

            <template v-else>
                <!-- Ce que la décision dit, pour tout le monde. -->
                <div v-if="clearance" class="space-y-1.5">
                    <p class="text-sm text-foreground">{{ clearance.description }}</p>
                    <p v-if="clearance.reason" :class="cn('rounded-md border px-3 py-2 text-sm', clearance.allows_incision ? 'border-border bg-muted/30 text-foreground' : 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100')">
                        <span class="font-semibold">Motif :</span> {{ clearance.reason }}
                    </p>
                    <p v-if="clearance.decided_by" class="text-xs text-muted-foreground">
                        Prononcée par {{ clearance.decided_by }}<span v-if="clearance.valid_until"> · valable jusqu’au {{ new Date(clearance.valid_until).toLocaleString('fr-FR') }}</span>
                    </p>
                    <p v-if="clearance.expired" class="text-xs font-semibold text-destructive">
                        Échue : elle doit être reprononcée avant l’incision.
                    </p>
                </div>

                <!-- Conditions : ce qui reste à lever retient l'incision. -->
                <div v-if="conditions.length" class="space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Conditions ({{ openConditions.length }} à lever sur {{ conditions.length }})
                    </p>
                    <ul class="space-y-2">
                        <li
                            v-for="condition in conditions"
                            :key="condition.id"
                            :class="cn('flex flex-wrap items-start gap-2 rounded-md border px-3 py-2', condition.open ? 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30' : 'border-border bg-muted/20')"
                        >
                            <component :is="condition.open ? CircleAlert : CircleCheck" :class="cn('mt-0.5 h-4 w-4 shrink-0', condition.open ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400')" aria-hidden="true" />
                            <span class="min-w-0 flex-1 basis-48">
                                <span class="block text-sm text-foreground">{{ condition.label }}</span>
                                <span v-if="condition.resolved_by" class="mt-0.5 block text-xs text-muted-foreground">
                                    Levée par {{ condition.resolved_by }}<span v-if="condition.resolution_notes"> — {{ condition.resolution_notes }}</span>
                                </span>
                            </span>
                            <Badge :variant="condition.open ? 'warning' : 'success'" class="shrink-0">{{ condition.status_label }}</Badge>
                            <Button
                                v-if="condition.open && anesthesia.can_write"
                                size="sm"
                                variant="white-outline"
                                type="button"
                                :disabled="resolveForm.processing && resolving === condition.id"
                                @click="resolve(condition)"
                            >Lever</Button>
                        </li>
                    </ul>
                </div>

                <!-- La décision elle-même : réservée à l'anesthésiste du dossier. -->
                <div v-if="anesthesia.can_decide">
                    <Button v-if="!editing" size="sm" type="button" @click="editing = true">
                        {{ clearance?.status === 'DRAFT' ? 'Prononcer la décision' : 'Reprononcer la décision' }}
                    </Button>

                    <form v-else class="space-y-3" @submit.prevent="submit">
                        <FormField label="Décision" :error="form.errors.status">
                            <div class="grid gap-2 sm:grid-cols-2">
                                <button
                                    v-for="choice in CHOICES"
                                    :key="choice.value"
                                    type="button"
                                    :aria-pressed="form.status === choice.value"
                                    :class="cn(
                                        'flex items-start gap-2 rounded-lg border px-3 py-2.5 text-start transition-colors',
                                        form.status === choice.value ? 'border-primary bg-primary/5 ring-1 ring-primary/40' : 'border-border hover:bg-muted/40',
                                    )"
                                    @click="form.status = choice.value"
                                >
                                    <component :is="choice.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-foreground">{{ choice.label }}</span>
                                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ choice.hint }}</span>
                                    </span>
                                </button>
                            </div>
                        </FormField>

                        <FormField
                            v-if="needsReason"
                            label="Motif"
                            hint="L’équipe chirurgicale le lira : elle ne peut pas le deviner."
                            :error="form.errors.reason"
                        >
                            <Textarea id="clearance_reason" v-model="form.reason" rows="2" required />
                        </FormField>

                        <FormField v-if="needsConditions" label="Conditions à lever avant l’incision" :error="form.errors.conditions">
                            <div class="space-y-2">
                                <div v-for="(_, index) in form.conditions" :key="index" class="flex items-center gap-2">
                                    <Input v-model="form.conditions[index]" class="flex-1" placeholder="Ex. bilan de coagulation à recontrôler" />
                                    <Button size="sm" variant="ghost" type="button" aria-label="Retirer cette condition" @click="removeCondition(index)">
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                                <Button size="sm" variant="white-outline" type="button" @click="addCondition"><Plus class="h-3.5 w-3.5" />Ajouter une condition</Button>
                            </div>
                        </FormField>

                        <FormField label="Valable jusqu’au" hint="Facultatif — au-delà, la décision devra être reprononcée." :error="form.errors.valid_until">
                            <DateTimePicker id="clearance_valid_until" v-model="form.valid_until" />
                        </FormField>

                        <p v-if="form.errors.clearance" class="text-xs text-destructive">{{ form.errors.clearance }}</p>

                        <div class="flex justify-end gap-2">
                            <Button size="sm" variant="ghost" type="button" @click="editing = false">Annuler</Button>
                            <Button size="sm" type="submit" :disabled="form.processing || !form.status">Enregistrer la décision</Button>
                        </div>
                    </form>
                </div>

                <p v-else-if="clearance?.status === 'DRAFT'" class="text-xs text-muted-foreground">
                    En attente de l’anesthésiste : la décision lui appartient et ne peut pas être prise ici.
                </p>
            </template>
        </div>
    </Card>
</template>
