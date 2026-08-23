<script setup>
import { computed, ref } from 'vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDateTime } from '@/utilities/date';

const props = defineProps({
    careSummary: { type: Object, required: true },
});

const open = ref(false);
const bloodPressure = computed(() => {
    const systolic = props.careSummary.blood_pressure_systolic;
    const diastolic = props.careSummary.blood_pressure_diastolic;
    return systolic && diastolic ? `${systolic}/${diastolic} mmHg` : '—';
});
const allergies = computed(() => (props.careSummary.allergy_snapshot ?? [])
    .map((allergy) => typeof allergy === 'string' ? allergy : allergy?.substance)
    .filter(Boolean));
const vitalItems = computed(() => [
    ['Tension artérielle', bloodPressure.value],
    ['Fréquence cardiaque', props.careSummary.heart_rate ? `${props.careSummary.heart_rate} bpm` : '—'],
    ['SpO₂', props.careSummary.spo2 !== null && props.careSummary.spo2 !== undefined ? `${props.careSummary.spo2} %` : '—'],
    ['Température', props.careSummary.temperature_celsius ? `${props.careSummary.temperature_celsius} °C` : '—'],
    ['Groupe sanguin', props.careSummary.blood_group || '—'],
    ['Poids', props.careSummary.weight_kg ? `${props.careSummary.weight_kg} kg` : '—'],
    ['Taille', props.careSummary.height_cm ? `${props.careSummary.height_cm} cm` : '—'],
    ['IMC', props.careSummary.bmi || '—'],
    ['Diabète connu', props.careSummary.known_diabetes === true ? 'Oui' : props.careSummary.known_diabetes === false ? 'Non' : '—'],
]);
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-blue-200 bg-blue-50/50 shadow-sm dark:border-blue-950 dark:bg-blue-950/15 xl:col-span-12">
        <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-start sm:px-5"
            :aria-expanded="open"
            aria-controls="care-summary-content"
            @click="open = !open"
        >
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300"><Icon name="activity" /></span>
            <span class="min-w-0 flex-1">
                <span class="flex flex-wrap items-center gap-2">
                    <strong class="text-sm text-slate-700 dark:text-white">Informations déjà saisies aux Soins</strong>
                    <span class="inline-flex items-center gap-1 rounded bg-white px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-blue-700 shadow-sm dark:bg-gray-950 dark:text-blue-300"><Icon name="eye" /> Lecture seule</span>
                </span>
                <span class="mt-0.5 block text-xs leading-5 text-slate-500">Constantes, allergies, actes et transmission sont repris du même passage ; aucune ressaisie ici.</span>
            </span>
            <span class="hidden text-xs font-semibold text-blue-700 sm:block dark:text-blue-300">{{ open ? 'Masquer' : 'Consulter' }}</span>
            <Icon :class="['text-slate-400 transition-transform', open ? 'rotate-180' : '']" name="chevron-down" />
        </button>

        <div v-show="open" id="care-summary-content" class="border-t border-blue-100 bg-white/80 p-4 dark:border-blue-950 dark:bg-gray-950/50 sm:p-5">
            <div v-if="careSummary.can_view_vitals" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-9">
                <div v-for="item in vitalItems" :key="item[0]" class="rounded border border-gray-200 bg-white px-3 py-2 dark:border-gray-900 dark:bg-gray-950">
                    <span class="block text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ item[0] }}</span>
                    <strong class="mt-1 block text-xs text-slate-700 dark:text-white">{{ item[1] }}</strong>
                </div>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <div v-if="careSummary.can_view_allergies" class="rounded border border-red-100 bg-red-50/40 p-3 dark:border-red-950 dark:bg-red-950/10">
                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-red-600">Allergies / vigilance</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ allergies.length ? allergies.join(' · ') : (careSummary.allergy_note || 'Aucune allergie signalée dans cette fiche.') }}</p>
                    <p v-if="allergies.length && careSummary.allergy_note" class="mt-1 text-xs text-slate-400">{{ careSummary.allergy_note }}</p>
                </div>
                <div class="rounded border border-gray-200 p-3 dark:border-gray-900">
                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Transmission Soins</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ careSummary.transmission_reason || careSummary.diagnostic_note || 'Aucune transmission renseignée.' }}</p>
                </div>
                <div class="rounded border border-gray-200 p-3 dark:border-gray-900">
                    <h3 class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Traçabilité</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ careSummary.updated_by || careSummary.created_by || '—' }}</p>
                    <p class="mt-1 text-xs text-slate-400">Mise à jour : {{ formatDateTime(careSummary.updated_at) || '—' }}</p>
                </div>
            </div>

            <div class="mt-4 overflow-hidden rounded border border-gray-200 dark:border-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2.5 dark:border-gray-900">
                    <h3 class="text-xs font-bold text-slate-700 dark:text-white">Actes réalisés aux Soins</h3>
                    <span class="rounded bg-gray-100 px-2 py-1 text-[10px] font-bold text-slate-500 dark:bg-gray-900">{{ careSummary.procedures?.length ?? 0 }}</span>
                </div>
                <div v-if="careSummary.procedures?.length" class="overflow-x-auto">
                    <table class="w-full min-w-[680px] border-collapse">
                        <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Acte</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Qté</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Observation</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Réalisation</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-900"><tr v-for="procedure in careSummary.procedures" :key="procedure.uuid"><td class="px-4 py-3"><strong class="block text-xs text-slate-700 dark:text-white">{{ procedure.name }}</strong><small class="font-mono text-[10px] text-slate-400">{{ procedure.code }}</small></td><td class="px-4 py-3 text-xs text-slate-500">{{ procedure.quantity }}</td><td class="px-4 py-3 text-xs text-slate-500">{{ procedure.notes || '—' }}</td><td class="px-4 py-3 text-end text-xs text-slate-500">{{ procedure.performed_by || '—' }}<small class="mt-0.5 block text-[10px] text-slate-400">{{ formatDateTime(procedure.performed_at) }}</small></td></tr></tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-5 text-center text-xs text-slate-400">Aucun acte réalisé n’est enregistré dans la fiche Soins.</p>
            </div>
        </div>
    </section>
</template>
