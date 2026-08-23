<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';

const props = defineProps({ surgicalRequest: Object, canUpdate: Boolean, canValidate: Boolean });
const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const form = useForm({ preoperative_notes: props.surgicalRequest.preoperative_notes ?? '' });
const validating = ref(false);
const submit = () => form.transform((data) => ({ ...data, _method: 'put' })).post(base.value, { preserveScroll: true });
const validate = () => {
    validating.value = true;
    router.post(`${base.value}/preoperative/validate`, {}, { preserveScroll: true, onFinish: () => { validating.value = false; } });
};
</script>

<template>
    <Card class="shadow-sm xl:col-span-12">
        <CardBody>
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-emerald-600">Contrôle équipe chirurgicale</p>
                    <h2 class="mt-1 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-600 dark:text-slate-200"><Icon name="check-circle" /> Feu vert chirurgical avant bloc</h2>
                    <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-400">Utile pour confirmer que l’organisation chirurgicale est prête avant le démarrage. Ce contrôle ne répète ni l’examen clinique, ni la décision de l’anesthésiste.</p>
                </div>
                <Button v-if="canValidate && !surgicalRequest.preoperative_validated_at" size="lg" type="button" :disabled="validating || !surgicalRequest.preoperative_notes" @click="validate"><Icon name="check" /><span class="ms-2">Confirmer le feu vert</span></Button>
            </div>

            <form v-if="canUpdate && !surgicalRequest.preoperative_validated_at" class="space-y-3" @submit.prevent="submit">
                <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_280px]">
                    <label class="text-xs font-medium text-slate-500">Observations ou réserve chirurgicale<textarea v-model="form.preoperative_notes" rows="3" class="mt-1 block w-full rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Organisation, matériel ou consigne à transmettre…"></textarea></label>
                    <aside class="rounded-md border border-emerald-100 bg-emerald-50/50 p-3 text-xs leading-5 text-slate-500 dark:border-emerald-950 dark:bg-emerald-950/15"><strong class="block text-emerald-700 dark:text-emerald-300">Ce qui n’est pas ressaisi ici</strong><span class="mt-1 block">Constantes et actes des Soins · examen anesthésique · résultats paracliniques.</span></aside>
                </div>
                <FormError v-if="form.errors.preoperative_notes">{{ form.errors.preoperative_notes }}</FormError>
                <div class="flex justify-end"><Button size="lg" type="submit" :disabled="form.processing"><Icon name="save" /><span class="ms-2">Enregistrer le contrôle</span></Button></div>
            </form>
            <div v-else class="rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-900 dark:bg-gray-1000">
                <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ surgicalRequest.preoperative_notes || 'Aucune observation chirurgicale.' }}</p>
                <p v-if="surgicalRequest.preoperative_validated_at" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-green-600"><Icon name="lock" /> Feu vert chirurgical confirmé — contrôle verrouillé.</p>
            </div>
        </CardBody>
    </Card>
</template>
