<script setup>
import { computed } from 'vue';
import Icon from '@/Components/UI/Icon.vue';

/**
 * The recorded examination, read-only and readable in seconds.
 *
 * Lists what was examined and what was not, because "not examined" is a
 * clinical fact of its own: a reader must never have to guess whether a
 * missing system was normal or simply skipped.
 */
const props = defineProps({
    examination: { type: Object, default: null },
    /** Show systems nobody examined. Off by default to keep the recap short. */
    showUnexamined: { type: Boolean, default: false },
});

const systems = computed(() => props.examination?.systems ?? []);
const abnormal = computed(() => systems.value.filter((system) => system.status === 'ABNORMAL'));
const normal = computed(() => systems.value.filter((system) => system.status === 'NORMAL'));
const unexamined = computed(() => systems.value.filter((system) => system.status === 'NOT_EXAMINED'));
const hasContent = computed(() => Boolean(props.examination?.general_condition)
    || Boolean(props.examination?.consciousness_status)
    || abnormal.value.length > 0
    || normal.value.length > 0);
</script>

<template>
    <div v-if="hasContent" class="space-y-3">
        <div v-if="examination.general_condition_label || examination.consciousness_status_label" class="flex flex-wrap gap-2">
            <span v-if="examination.general_condition_label" class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-[11px] dark:border-gray-800 dark:bg-gray-1000">
                <span class="text-slate-400">État général</span>
                <strong class="font-bold text-slate-700 dark:text-white">{{ examination.general_condition_label }}</strong>
            </span>
            <span v-if="examination.consciousness_status_label" class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-[11px] dark:border-gray-800 dark:bg-gray-1000">
                <span class="text-slate-400">Conscience</span>
                <strong class="font-bold text-slate-700 dark:text-white">{{ examination.consciousness_status_label }}</strong>
                <span v-if="examination.consciousness_details" class="text-slate-500">· {{ examination.consciousness_details }}</span>
            </span>
        </div>

        <p v-if="examination.general_observation" class="text-xs leading-5 text-slate-600 dark:text-slate-300">
            {{ examination.general_observation }}
        </p>

        <!-- Anomalies first: they are what changes the next decision. -->
        <ul v-if="abnormal.length" class="space-y-1.5">
            <li v-for="system in abnormal" :key="system.system_code" class="rounded-md border border-amber-200 bg-amber-50/60 px-2.5 py-2 dark:border-amber-900 dark:bg-amber-950/20">
                <p class="flex items-center gap-1.5 text-[11px] font-bold text-amber-800 dark:text-amber-200">
                    <Icon name="alert-circle" class="text-xs" />{{ system.label }} — Anormal
                </p>
                <p v-if="system.findings" class="mt-0.5 text-[11px] leading-4 text-amber-900/80 dark:text-amber-100/80">{{ system.findings }}</p>
            </li>
        </ul>

        <p v-if="normal.length" class="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
            <Icon name="check" class="text-xs text-emerald-600 dark:text-emerald-400" />
            <span class="font-semibold text-emerald-700 dark:text-emerald-300">Normaux :</span>
            {{ normal.map((system) => system.label).join(', ') }}
        </p>

        <!-- Never folded into "normal": a system nobody looked at is a gap in
             the examination, and the reader is told so explicitly. -->
        <p v-if="showUnexamined && unexamined.length" class="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400">
            <Icon name="minus" class="text-xs" />
            <span class="font-semibold">Non examinés :</span>
            {{ unexamined.map((system) => system.label).join(', ') }}
        </p>
        <p v-else-if="unexamined.length" class="text-[11px] text-slate-400">
            {{ unexamined.length }} appareil(s) non examiné(s).
        </p>

        <p v-if="examination.examined_by" class="text-[10px] text-slate-400">
            Examen consigné par {{ examination.examined_by }}.
        </p>
    </div>

    <p v-else class="text-xs text-slate-400">Aucun examen clinique consigné pour l’instant.</p>
</template>
