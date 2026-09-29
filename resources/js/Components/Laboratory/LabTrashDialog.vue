<script setup>
import { computed, ref, watch } from 'vue';
import { Trash2 } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';

/**
 * ADR-220 — mettre à la corbeille une demande (ou une sélection) saisie à tort.
 * Le motif est exigé ; la fenêtre dit ce qui se passe avant la confirmation :
 * rien n'est détruit, ce qui attendait d'être facturé est annulé, ce qui est sur
 * une facture reste à la Caisse, et la demande se restaure depuis la Corbeille.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    count: { type: Number, default: 1 },
    refused: { type: Number, default: 0 },
    processing: { type: Boolean, default: false },
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:open', 'confirm']);

const reason = ref('');
watch(() => props.open, (open) => { if (open) reason.value = ''; });

const ready = computed(() => reason.value.trim().length >= 3 && props.count > 0 && !props.processing);
const title = computed(() => (props.count > 1 ? `Mettre ${props.count} demandes à la corbeille` : 'Mettre la demande à la corbeille'));
const confirm = () => { if (ready.value) emit('confirm', reason.value.trim()); };
</script>

<template>
    <Dialog
        :open="open"
        :title="title"
        description="Pour une demande saisie à tort. Elle quitte la file et les dossiers, garde sa trace et se restaure depuis la Corbeille."
        size="md"
        :dismissible="!processing"
        @update:open="emit('update:open', $event)"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-destructive/10 text-destructive"><Trash2 class="h-5 w-5" /></span>
        </template>

        <div class="space-y-4">
            <ul class="space-y-1.5 rounded-lg border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
                <li>• Ce qui attendait d’être facturé pour ces analyses est annulé.</li>
                <li>• Ce qui est déjà sur une facture reste à la Caisse, qui régularise.</li>
                <li>• Une demande dont un résultat a été envoyé au médecin n’y part jamais.</li>
            </ul>
            <p v-if="refused" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200" role="status">
                {{ refused }} demande{{ refused > 1 ? 's' : '' }} de la sélection {{ refused > 1 ? 'ne partiront' : 'ne partira' }} pas : des résultats ont été envoyés au médecin.
            </p>
            <FormField label="Motif" required :error="error">
                <Textarea v-model="reason" rows="3" maxlength="1000" placeholder="Ex. : demande saisie en double, mauvais patient…" @keydown.ctrl.enter.prevent="confirm" />
            </FormField>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="emit('update:open', false)">Annuler</Button>
            <Button type="button" variant="destructive" :disabled="!ready" @click="confirm">
                <Trash2 class="h-4 w-4" /> {{ count > 1 ? `Mettre ${count} à la corbeille` : 'Mettre à la corbeille' }}
            </Button>
        </template>
    </Dialog>
</template>
