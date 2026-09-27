<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import { Activity, CalendarCheck, CircleCheck, FlaskConical, List } from 'lucide-vue-next';
import Input from '@/Components/UI/Input.vue';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    items: Object,
    counts: { type: Object, default: () => ({}) },
    filter: { type: String, default: 'pending' },
});

const selectFilter = (filter) => router.get(
    '/laboratory',
    filter === 'pending' ? {} : { filter },
    { preserveScroll: true, replace: true },
);

/**
 * Ce que la paillasse a devant elle, et ce qu'elle a rendu aujourd'hui.
 * Un cumul total ne dirait rien de la journée en cours — et le compte vient
 * du serveur, jamais de la page affichée.
 */
const counterTiles = computed(() => [
    { value: 'pending', label: 'À analyser', hint: 'Résultat non saisi', icon: FlaskConical, tone: 'amber', count: props.counts.pending ?? 0, active: props.filter === 'pending' },
    { value: 'resulted', label: 'Résultats saisis', hint: 'Toutes périodes', icon: CircleCheck, tone: 'emerald', count: props.counts.resulted ?? 0, active: props.filter === 'resulted' },
    { value: '__today__', label: 'Rendus aujourd’hui', hint: 'Depuis minuit', icon: CalendarCheck, tone: 'sky', count: props.counts.resulted_today ?? 0, filterable: false },
    { value: 'all', label: 'Toutes les analyses', hint: 'Demandes non retirées', icon: List, tone: 'neutral', count: props.counts.all ?? 0, active: props.filter === 'all' },
]);

const forms = reactive({});
const formFor = (item) => {
    if (!forms[item.uuid]) {
        forms[item.uuid] = useForm({ result_value: '', result_notes: '' });
    }
    return forms[item.uuid];
};
const submitResult = (item) => formFor(item).post(`/laboratory/items/${item.uuid}/result`, { preserveScroll: true });
const actionableDefinitions = (item) => (item.reference_definitions ?? []).filter((definition) => definition.level !== 'PARENT');
const primaryDefinition = (item) => actionableDefinitions(item).length === 1 ? actionableDefinitions(item)[0] : null;
</script>

<template>
    <Head title="Laboratoire" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <header class="flex items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"><Activity class="h-6 w-6" /></span>
            <div>
                <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Laboratoire</h1>
                <p class="mt-1 text-sm text-muted-foreground">Analyses demandées par Médecine — saisie du résultat.</p>
            </div>
        </header>

        <QueueCounters :tiles="counterTiles" @select="selectFilter" />

        <Card class="overflow-hidden shadow-sm">
            <p class="border-b border-border px-5 py-2.5 text-xs text-muted-foreground">
                <template v-if="filter === 'pending'">Analyses en attente d’un résultat, les plus anciennes d’abord.</template>
                <template v-else-if="filter === 'resulted'">Analyses déjà rendues — <button type="button" class="font-bold text-primary hover:underline" @click="selectFilter('pending')">revenir à la paillasse</button>.</template>
                <template v-else>Toutes les analyses non retirées — <button type="button" class="font-bold text-primary hover:underline" @click="selectFilter('pending')">revenir à la paillasse</button>.</template>
            </p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] border-collapse">
                    <thead class="bg-muted/70 /40">
                        <tr>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Patient</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Analyse</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Demandé</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Résultat</th>
                            <th class="border-b border-border px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-muted-foreground">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="item in items.data" :key="item.uuid">
                            <td class="px-5 py-3">
                                <Link :href="`/patients/${item.patient.uuid}`" class="block text-sm font-bold text-foreground hover:text-primary">{{ formatPatientName(item.patient) }}</Link>
                                <span class="text-xs text-muted-foreground">{{ item.patient.patient_number }} · {{ item.episode_number }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="text-sm font-semibold text-foreground">{{ item.name }}</span><span class="ms-1 font-mono text-xs text-muted-foreground">{{ item.code }}</span>
                                <div v-if="actionableDefinitions(item).length" class="mt-2 overflow-hidden rounded border border-border">
                                    <div v-for="definition in actionableDefinitions(item)" :key="definition.code" class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 border-b border-border px-2.5 py-1.5 text-[10px] last:border-b-0">
                                        <span class="font-semibold text-muted-foreground">{{ definition.designation }}</span>
                                        <span class="text-end text-muted-foreground"><template v-if="definition.reference">Réf. {{ definition.reference }}<span v-if="definition.unit"> {{ definition.unit }}</span></template><template v-else>Référence non configurée</template>· {{ definition.reference_profile }}</span>
                                    </div>
                                </div>
                                <p v-else class="mt-1 text-[10px] text-amber-600 dark:text-amber-300">Structure et références non configurées.</p>
                            </td>
                            <td class="px-5 py-3 text-xs text-muted-foreground">{{ formatDateTime(item.requested_at) }}<br>Dr {{ item.requested_by }}</td>
                            <td class="px-5 py-3">
                                <template v-if="item.resulted_at">
                                    <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ item.result_value }}</p>
                                    <p v-if="item.result_notes" class="mt-0.5 text-xs text-muted-foreground">{{ item.result_notes }}</p>
                                    <p class="mt-0.5 text-[11px] text-muted-foreground">{{ formatDateTime(item.resulted_at) }} · {{ item.resulted_by }}</p>
                                </template>
                                <span v-else class="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-950 dark:text-amber-200">En attente</span>
                            </td>
                            <td class="px-5 py-3 text-end">
                                <form v-if="!item.resulted_at" class="ms-auto flex max-w-xs flex-col gap-1.5" @submit.prevent="submitResult(item)">
                                    <select v-if="primaryDefinition(item)?.predefined_values?.length" v-model="formFor(item).result_value" class="block h-8 w-full rounded-sm border border-border bg-white px-3 text-xs text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25"><option value="">Choisir le résultat</option><option v-for="value in primaryDefinition(item).predefined_values" :key="value" :value="value">{{ value }}</option></select>
                                    <Input v-else v-model="formFor(item).result_value" size="sm" :placeholder="primaryDefinition(item)?.unit ? `Résultat (${primaryDefinition(item).unit})` : 'Résultat'" />
                                    <Input v-model="formFor(item).result_notes" size="sm" placeholder="Note (optionnel)" />
                                    <FormError :message="formFor(item).errors.result_value" />
                                    <Button type="submit" size="sm" :disabled="formFor(item).processing || !formFor(item).result_value.trim()">Enregistrer</Button>
                                </form>
                            </td>
                        </tr>
                        <tr v-if="items.data.length === 0"><td colspan="5" class="px-5 py-12 text-center text-sm text-muted-foreground">Aucune analyse demandée.</td></tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
