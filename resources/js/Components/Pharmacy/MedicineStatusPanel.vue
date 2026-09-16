<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Ban, RefreshCw, TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-098 — un médicament se désactive, il ne se supprime jamais :
 * ordonnances, lots et mouvements continuent de le désigner. Partagé par la
 * clinique et le portail ; le serveur refuse tant que des unités sont
 * réservées.
 */
const props = defineProps({
    medicine: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    // POST `${baseUrl}/deactivate` et `${baseUrl}/reactivate`.
    baseUrl: { type: String, required: true },
});

const open = ref(false);
const form = useForm({ reason: '' });

const deactivate = () => form.post(`${props.baseUrl}/deactivate`, {
    preserveScroll: true,
    onSuccess: () => { open.value = false; form.reset(); },
});

const reactivate = () => router.post(`${props.baseUrl}/reactivate`, {}, { preserveScroll: true });

const close = () => {
    if (form.processing) return;

    open.value = false;
    form.clearErrors();
};
</script>

<template>
    <Card
        v-if="(medicine.active && can.deactivate) || (! medicine.active && can.reactivate)"
        :class="cn(
            'flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between',
            medicine.active ? '' : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20',
        )"
    >
        <div class="min-w-0">
            <h2 class="flex flex-wrap items-center gap-2 font-heading text-base font-bold text-foreground">
                {{ medicine.active ? 'Ne plus utiliser ce médicament' : 'Médicament désactivé' }}
                <Badge v-if="! medicine.active" tone="warning">Inactif</Badge>
            </h2>
            <p class="mt-0.5 text-sm text-muted-foreground">
                <template v-if="medicine.active">
                    Il ne sera plus proposé à la vente, aux commandes ni aux entrées de stock.
                    Son historique reste consultable et il pourra être réactivé.
                </template>
                <template v-else>
                    Motif : {{ medicine.deactivation_reason || '—' }}. Réactivez-le pour le proposer de nouveau.
                </template>
            </p>
        </div>

        <Button v-if="medicine.active" type="button" variant="danger-outline" class="shrink-0" @click="open = true">
            <Ban class="h-4 w-4" />Désactiver
        </Button>
        <Button v-else type="button" variant="primary" class="shrink-0" @click="reactivate">
            <RefreshCw class="h-4 w-4" />Réactiver
        </Button>
    </Card>

    <!-- La fenêtre passe par la primitive partagée (ADR-099) : elle portait
         son propre voile et sa propre boîte, sans piège de focus ni Échap. -->
    <Dialog
        :open="open"
        :title="`Désactiver « ${medicine.name} »`"
        description="Impossible tant que des unités sont réservées pour une ordonnance ou une vente en cours."
        :dismissible="! form.processing"
        @update:open="close"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/35 dark:text-amber-300">
                <TriangleAlert class="h-5 w-5" />
            </span>
        </template>

        <FormField
            label="Motif"
            required
            :error="form.errors.reason || form.errors.medicine || form.errors.site"
        >
            <Textarea v-model="form.reason" rows="3" required placeholder="Ex. retiré du marché, remplacé par un autre dosage" />
        </FormField>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="close">Retour</Button>
            <Button type="button" variant="destructive" :disabled="form.processing || ! form.reason.trim()" @click="deactivate">
                {{ form.processing ? 'Désactivation…' : 'Désactiver' }}
            </Button>
        </template>
    </Dialog>
</template>
