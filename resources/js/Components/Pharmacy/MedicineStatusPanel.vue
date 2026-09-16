<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { Ban, RefreshCw } from 'lucide-vue-next';

/**
 * ADR-098 — a medicine is deactivated, never deleted: prescriptions, lots and
 * movements keep pointing to it. Shared by the clinic and the portal; the
 * server refuses while units are still reserved.
 */
const props = defineProps({
    medicine: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    // POST `${baseUrl}/deactivate` and `${baseUrl}/reactivate`.
    baseUrl: { type: String, required: true },
});

const open = ref(false);
const form = useForm({ reason: '' });
const deactivate = () => form.post(`${props.baseUrl}/deactivate`, {
    preserveScroll: true,
    onSuccess: () => { open.value = false; form.reset(); },
});
const reactivate = () => router.post(`${props.baseUrl}/reactivate`, {}, { preserveScroll: true });
</script>

<template>
    <section v-if="(medicine.active && can.deactivate) || (!medicine.active && can.reactivate)" :class="['flex flex-col gap-3 rounded-xl border p-5 sm:flex-row sm:items-center sm:justify-between', medicine.active ? 'border-border bg-card' : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20']">
        <div>
            <h2 class="flex items-center gap-2 font-heading text-base font-bold text-foreground">
                {{ medicine.active ? 'Ne plus utiliser ce médicament' : 'Médicament désactivé' }}
                <Badge v-if="!medicine.active" tone="warning">Inactif</Badge>
            </h2>
            <p class="mt-0.5 text-sm text-muted-foreground">
                <template v-if="medicine.active">Il ne sera plus proposé à la vente, aux commandes ni aux entrées de stock. Son historique reste consultable et il pourra être réactivé.</template>
                <template v-else>Motif : {{ medicine.deactivation_reason || '—' }}. Réactivez-le pour le proposer de nouveau.</template>
            </p>
        </div>
        <Button v-if="medicine.active" type="button" size="rg" variant="white-outline" class="shrink-0 text-red-600" @click="open = true"><Ban class="h-4 w-4" />Désactiver</Button>
        <Button v-else type="button" size="rg" class="shrink-0" @click="reactivate"><RefreshCw class="h-4 w-4" />Réactiver</Button>

        <div v-if="open" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="open = false">
            <section class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="deactivate-medicine-title">
                <h2 id="deactivate-medicine-title" class="font-heading text-lg font-bold text-foreground">Désactiver « {{ medicine.name }} »</h2>
                <p class="mt-1 text-sm text-muted-foreground">Impossible tant que des unités sont réservées pour une ordonnance ou une vente en cours.</p>
                <form class="mt-4 space-y-4" @submit.prevent="deactivate">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></span>
                        <textarea v-model="form.reason" required rows="3" class="block w-full rounded-lg border border-border bg-white px-3 py-2 text-sm" placeholder="Ex. retiré du marché, remplacé par un autre dosage" />
                        <span v-if="form.errors.reason || form.errors.medicine || form.errors.site" class="mt-1 block text-xs text-red-600">{{ form.errors.reason || form.errors.medicine || form.errors.site }}</span>
                    </label>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="open = false">Retour</Button>
                        <Button type="submit" size="rg" variant="danger" :disabled="form.processing">Désactiver</Button>
                    </div>
                </form>
            </section>
        </div>
    </section>
</template>
