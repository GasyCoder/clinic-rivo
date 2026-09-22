<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { cn } from '@/lib/cn';
import { CircleCheck, Clock, ClipboardCheck, Lock, PenLine, UserCheck } from 'lucide-vue-next';

/**
 * ADR-170 — un temps de la checklist de sécurité du bloc.
 *
 * Les points **viennent du serveur** (`SurgicalSafetyChecklistItems`) : aucun
 * item n'est écrit dans l'écran, parce que cette liste est un point de départ
 * que la clinique doit pouvoir relire et corriger sans toucher au frontend.
 *
 * Chaque métier confirme **sa propre part** : un bouton de confirmation
 * n'apparaît que pour les rôles que ce compte tient réellement sur ce
 * dossier (`can_confirm`, décidé par le serveur). Pour les autres, l'écran dit
 * qui l'on attend plutôt que de proposer un geste qui serait refusé.
 */
const props = defineProps({
    /** @type {{phase, label, moment, completed_at, notes, items: [], confirmations: [], available: boolean}} */
    checklist: { type: Object, required: true },
    base: { type: String, required: true },
    readonly: { type: Boolean, default: false },
});

const completed = computed(() => Boolean(props.checklist.completed_at));
const items = computed(() => props.checklist.items ?? []);
const missingRequired = computed(() => items.value.filter((item) => item.required && !item.checked).length);

const form = useForm({
    items: Object.fromEntries(items.value.map((item) => [item.key, item.checked])),
    notes: props.checklist.notes ?? '',
    confirm_as: '',
});

watch(() => props.checklist, (next) => {
    form.items = Object.fromEntries((next.items ?? []).map((item) => [item.key, item.checked]));
    form.notes = next.notes ?? '';
    form.confirm_as = '';
}, { deep: true });

const showNotes = ref(Boolean(props.checklist.notes));

const submit = (role = '') => {
    form.confirm_as = role;
    form.post(`${props.base}/checklist/${props.checklist.phase}`, {
        preserveScroll: true,
        onFinish: () => { form.confirm_as = ''; },
    });
};
</script>

<template>
    <Card :class="cn('overflow-hidden', !checklist.available && 'border-dashed bg-muted/20 shadow-none')">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3.5">
            <span
                :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', completed ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-primary/10 text-primary')"
                aria-hidden="true"
            >
                <component :is="completed ? CircleCheck : ClipboardCheck" class="h-4 w-4" />
            </span>
            <div class="min-w-0 flex-1 basis-48">
                <h3 class="text-sm font-semibold text-foreground">{{ checklist.label }}</h3>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ checklist.moment }}</p>
            </div>
            <Badge :variant="completed ? 'success' : (missingRequired ? 'destructive' : 'outline')">
                <component :is="completed ? Lock : Clock" class="h-3 w-3" aria-hidden="true" />
                {{ completed ? 'Confirmé par l’équipe' : (missingRequired ? `${missingRequired} point(s) requis` : 'En cours') }}
            </Badge>
        </header>

        <div v-if="!checklist.available" class="px-5 py-3 text-sm text-muted-foreground">
            Ce temps se renseigne une fois l’intervention démarrée.
        </div>

        <div v-else class="space-y-4 px-5 py-4">
            <ul class="space-y-2">
                <li v-for="item in items" :key="item.key" class="flex items-start gap-2.5">
                    <Checkbox
                        :id="`${checklist.phase}-${item.key}`"
                        v-model="form.items[item.key]"
                        :disabled="readonly || completed"
                        class="mt-0.5"
                    />
                    <label :for="`${checklist.phase}-${item.key}`" class="min-w-0 flex-1 cursor-pointer">
                        <span class="text-sm text-foreground">{{ item.label }}</span>
                        <Badge v-if="!item.required" variant="outline" class="ms-2 align-middle">Facultatif</Badge>
                        <span v-if="item.hint" class="mt-0.5 block text-xs text-muted-foreground">{{ item.hint }}</span>
                    </label>
                </li>
            </ul>

            <p v-if="form.errors.items" class="text-xs text-destructive">{{ form.errors.items }}</p>
            <p v-if="form.errors.checklist" class="text-xs text-destructive">{{ form.errors.checklist }}</p>

            <div v-if="showNotes || (!completed && !readonly)">
                <FormField v-if="!completed && !readonly" label="Observations" :error="form.errors.notes">
                    <Textarea :id="`${checklist.phase}-notes`" v-model="form.notes" rows="2" />
                </FormField>
                <p v-else-if="checklist.notes" class="text-sm text-foreground">{{ checklist.notes }}</p>
            </div>

            <div v-if="!completed && !readonly" class="flex justify-end">
                <Button size="sm" variant="white-outline" type="button" :disabled="form.processing" @click="submit()">
                    <PenLine class="h-3.5 w-3.5" />Enregistrer les points cochés
                </Button>
            </div>

            <!-- Confirmations : chaque métier signe sa propre part. -->
            <ul class="space-y-2 rounded-lg border border-border bg-muted/20 p-3">
                <li v-for="confirmation in checklist.confirmations" :key="confirmation.role" class="flex flex-wrap items-center gap-2">
                    <UserCheck
                        :class="cn('h-4 w-4 shrink-0', confirmation.confirmed_at ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground')"
                        aria-hidden="true"
                    />
                    <span class="text-sm font-medium text-foreground">{{ confirmation.label }}</span>
                    <span v-if="confirmation.confirmed_at" class="text-xs text-muted-foreground">
                        confirmé{{ confirmation.confirmed_by ? ` par ${confirmation.confirmed_by}` : '' }}
                    </span>
                    <span v-else class="text-xs text-muted-foreground">en attente</span>
                    <Button
                        v-if="confirmation.can_confirm && !readonly"
                        size="sm"
                        class="ms-auto"
                        type="button"
                        :disabled="form.processing"
                        @click="submit(confirmation.role)"
                    >Je confirme</Button>
                    <Badge v-else-if="confirmation.confirmed_at" variant="success" class="ms-auto">Signé</Badge>
                </li>
            </ul>
            <p v-if="form.errors.confirm_as" class="text-xs text-destructive">{{ form.errors.confirm_as }}</p>
        </div>
    </Card>
</template>
