<script setup>
import { reactive } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

defineProps({ items: Object });

const forms = reactive({});
const formFor = (item) => {
    if (!forms[item.uuid]) {
        forms[item.uuid] = useForm({ result_value: '', result_notes: '' });
    }
    return forms[item.uuid];
};
const submitResult = (item) => formFor(item).post(`/laboratory/items/${item.uuid}/result`, { preserveScroll: true });
</script>

<template>
    <Head title="Laboratoire" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <header class="flex items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-2xl" name="activity" /></span>
            <div>
                <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">Laboratoire</h1>
                <p class="mt-1 text-sm text-slate-400">Analyses demandées par Médecine — saisie du résultat.</p>
            </div>
        </header>

        <Card class="overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40">
                        <tr>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Patient</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Analyse</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Demandé</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Résultat</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="item in items.data" :key="item.uuid">
                            <td class="px-5 py-3">
                                <Link :href="`/patients/${item.patient.uuid}`" class="block text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ formatPatientName(item.patient) }}</Link>
                                <span class="text-xs text-slate-400">{{ item.patient.patient_number }} · {{ item.episode_number }}</span>
                            </td>
                            <td class="px-5 py-3"><span class="text-sm font-semibold text-slate-700 dark:text-white">{{ item.name }}</span><span class="ms-1 font-mono text-xs text-slate-400">{{ item.code }}</span></td>
                            <td class="px-5 py-3 text-xs text-slate-400">{{ formatDateTime(item.requested_at) }}<br>Dr {{ item.requested_by }}</td>
                            <td class="px-5 py-3">
                                <template v-if="item.resulted_at">
                                    <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ item.result_value }}</p>
                                    <p v-if="item.result_notes" class="mt-0.5 text-xs text-slate-400">{{ item.result_notes }}</p>
                                    <p class="mt-0.5 text-[11px] text-slate-400">{{ formatDateTime(item.resulted_at) }} · {{ item.resulted_by }}</p>
                                </template>
                                <span v-else class="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-950 dark:text-amber-200">En attente</span>
                            </td>
                            <td class="px-5 py-3 text-end">
                                <form v-if="!item.resulted_at" class="ms-auto flex max-w-xs flex-col gap-1.5" @submit.prevent="submitResult(item)">
                                    <Input v-model="formFor(item).result_value" size="sm" placeholder="Résultat" />
                                    <Input v-model="formFor(item).result_notes" size="sm" placeholder="Note (optionnel)" />
                                    <FormError :message="formFor(item).errors.result_value" />
                                    <Button type="submit" size="sm" :disabled="formFor(item).processing || !formFor(item).result_value.trim()">Enregistrer</Button>
                                </form>
                            </td>
                        </tr>
                        <tr v-if="items.data.length === 0"><td colspan="5" class="px-5 py-12 text-center text-sm text-slate-400">Aucune analyse demandée.</td></tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
