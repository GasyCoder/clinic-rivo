<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

const props = defineProps({
    patient: { type: Object, required: true },
    episode: { type: Object, required: true },
    reason: { type: String, default: null },
    backHref: { type: String, default: null },
    backLabel: { type: String, default: 'Retour' },
});

const isEmergency = computed(() => props.episode.priority === 'EMERGENCY');
const sexLabel = computed(() => (props.patient.sex === 'F' ? 'F' : 'M'));
const financialModeLabel = computed(() => ({
    SELF: 'Sans mutuelle',
    MUTUAL: 'Mutuelle',
    STAFF: 'Personnel',
}[props.episode.financial_mode] ?? null));
</script>

<template>
    <div :class="['overflow-hidden rounded-lg border shadow-sm', isEmergency ? 'border-red-300 dark:border-red-900' : 'border-gray-200 dark:border-gray-900']">
        <div :class="['flex flex-wrap items-center justify-between gap-3 px-4 py-3', isEmergency ? 'bg-red-50 dark:bg-red-950/20' : 'bg-white dark:bg-gray-950']">
            <div class="flex min-w-0 items-center gap-3">
                <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold', isEmergency ? 'bg-red-600 text-white' : 'bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300']">{{ sexLabel }}</span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ patient.first_name }} {{ patient.last_name }}</h1>
                        <span v-if="isEmergency" class="inline-flex items-center gap-1 rounded bg-red-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white"><Icon name="alert-circle" />Urgence</span>
                    </div>
                    <p class="mt-0.5 truncate text-xs text-slate-400">
                        <span class="font-mono">{{ patient.patient_number }}</span>
                        <span> · Passage <span class="font-mono">{{ episode.episode_number }}</span></span>
                        <span v-if="patient.age !== null && patient.age !== undefined"> · {{ patient.age }} ans</span>
                        <span v-if="financialModeLabel"> · {{ financialModeLabel }}</span>
                    </p>
                </div>
            </div>
            <Button v-if="backHref" :as="Link" :href="backHref" size="sm" variant="white-outline"><Icon class="text-sm" name="arrow-left" /><span class="ms-1.5">{{ backLabel }}</span></Button>
        </div>
        <div v-if="reason" class="border-t border-gray-200 bg-gray-50/60 px-4 py-2 text-xs text-slate-500 dark:border-gray-900 dark:bg-gray-1000/30 dark:text-slate-300">
            <span class="font-semibold uppercase tracking-wide text-slate-400">Motif</span> · {{ reason }}
        </div>
    </div>
</template>
