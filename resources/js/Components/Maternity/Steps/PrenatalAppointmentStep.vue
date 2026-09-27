<script setup>
import { CalendarClock, CalendarPlus } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { formatDateTime, toLocalDateInput } from '@/utilities/date';

/**
 * ADR-204 — étape 6 : le prochain rendez-vous, **facultatif**.
 *
 * `Appointment ≠ Episode` : programmer un rendez-vous n'ouvre aucun passage.
 * Il n'est créé qu'à la fin de la consultation ; le jour venu, la patiente
 * passe par la Réception, qui ouvre son nouveau passage, et la nouvelle
 * consultation rejoint la même grossesse. Sans rendez-vous, la consultation se
 * termine tout aussi bien.
 */
const props = defineProps({
    form: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
    defaultReason: { type: String, default: 'Suivi prénatal' },
    /** Le rendez-vous déjà créé par cette consultation, une fois terminée. */
    appointment: { type: Object, default: null },
});

const planned = () => props.form.prenatal_data.next_appointment;
const toggle = (enabled) => {
    const current = planned() ?? {};
    props.form.prenatal_data.next_appointment = {
        enabled,
        scheduled_at: current.scheduled_at ?? '',
        reason: current.reason || props.defaultReason,
        notes: current.notes ?? '',
    };
};
</script>

<template>
    <div class="space-y-4">
        <Card v-if="appointment" class="flex flex-wrap items-center gap-3 p-4">
            <CalendarClock class="h-5 w-5 text-muted-foreground" aria-hidden="true" />
            <div class="min-w-0">
                <p class="text-sm font-bold text-foreground">Rendez-vous programmé le {{ formatDateTime(appointment.scheduled_at) }}</p>
                <p class="text-xs text-muted-foreground">{{ appointment.reason }}<template v-if="appointment.notes"> · {{ appointment.notes }}</template></p>
            </div>
            <Badge tone="info" class="ms-auto">{{ appointment.status_label }}</Badge>
        </Card>

        <Card v-else class="p-4">
            <label class="flex min-h-11 cursor-pointer items-center justify-between gap-3">
                <span class="flex items-center gap-2">
                    <CalendarPlus class="h-5 w-5 text-muted-foreground" aria-hidden="true" />
                    <span>
                        <span class="block text-sm font-bold text-foreground">Programmer un rendez-vous</span>
                        <span class="block text-xs text-muted-foreground">Facultatif. Il est créé à la fin de la consultation ; il n’ouvre aucun passage.</span>
                    </span>
                </span>
                <Switch :model-value="Boolean(form.prenatal_data.next_appointment?.enabled)" :disabled="readOnly" aria-label="Programmer un rendez-vous" @update:model-value="toggle" />
            </label>

            <fieldset v-if="form.prenatal_data.next_appointment?.enabled" class="mt-4 grid gap-4 md:grid-cols-2" :disabled="readOnly">
                <FormField label="Date et heure" required :error="form.errors['prenatal_data.next_appointment.scheduled_at']">
                    <DateTimePicker v-model="form.prenatal_data.next_appointment.scheduled_at" format="long" :min="toLocalDateInput()" />
                </FormField>
                <FormField label="Motif" :error="form.errors['prenatal_data.next_appointment.reason']">
                    <Input v-model="form.prenatal_data.next_appointment.reason" maxlength="190" :placeholder="defaultReason" />
                </FormField>
                <FormField as="div" label="Note" hint="facultatif" class="md:col-span-2" :error="form.errors['prenatal_data.next_appointment.notes']">
                    <Textarea v-model="form.prenatal_data.next_appointment.notes" :rows="2" maxlength="1000" />
                </FormField>
            </fieldset>
        </Card>
    </div>
</template>
