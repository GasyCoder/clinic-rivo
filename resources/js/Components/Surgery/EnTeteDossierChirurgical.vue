<script setup>
import { computed } from 'vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDateTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

const props = defineProps({
    surgicalRequest: Object,
    workspace: { type: String, default: 'surgery' },
});

const patient = computed(() => props.surgicalRequest.episode?.patient ?? {});
const age = computed(() => {
    if (patient.value.declared_age) return `${patient.value.declared_age} ans`;
    if (!patient.value.birth_date) return '—';

    const birth = new Date(patient.value.birth_date);
    const today = new Date();
    let years = today.getFullYear() - birth.getFullYear();
    const monthDifference = today.getMonth() - birth.getMonth();
    if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birth.getDate())) years -= 1;
    return `${years} ans${patient.value.birth_date_is_approximate ? ' estimé' : ''}`;
});

const sexLabel = computed(() => ({ M: 'Masculin', F: 'Féminin' }[patient.value.sex] ?? patient.value.sex ?? '—'));
const status = computed(() => ({
    PENDING: { label: 'Demande créée', style: 'border-slate-200 text-slate-600', dot: 'bg-slate-400' },
    SCHEDULED: { label: 'Programmée', style: 'border-blue-200 text-blue-700 dark:border-blue-900 dark:text-blue-300', dot: 'bg-blue-500' },
    PREOPERATIVE_VALIDATED: { label: 'Prête pour le bloc', style: 'border-indigo-200 text-indigo-700 dark:border-indigo-900 dark:text-indigo-300', dot: 'bg-indigo-500' },
    IN_PROGRESS: { label: 'Intervention en cours', style: 'border-amber-200 text-amber-700 dark:border-amber-900 dark:text-amber-300', dot: 'bg-amber-500' },
    COMPLETED: { label: 'Intervention terminée', style: 'border-green-200 text-green-700 dark:border-green-900 dark:text-green-300', dot: 'bg-green-500' },
    DISCHARGED: { label: 'Sortie enregistrée', style: 'border-gray-200 text-slate-500 dark:border-gray-800', dot: 'bg-slate-400' },
    CANCELLED: { label: 'Annulée', style: 'border-red-200 text-red-700 dark:border-red-900 dark:text-red-300', dot: 'bg-red-500' },
}[props.surgicalRequest.status] ?? { label: props.surgicalRequest.status, style: 'border-gray-200 text-slate-500', dot: 'bg-slate-400' }));
const workspaceMeta = computed(() => props.workspace === 'anesthesia'
    ? { label: 'Anesthésie', icon: 'shield-check', iconStyle: 'bg-violet-100 text-violet-600 dark:bg-violet-950 dark:text-violet-300', badgeStyle: 'text-violet-600 dark:text-violet-300' }
    : { label: 'Chirurgie', icon: 'masks', iconStyle: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300', badgeStyle: 'text-emerald-600 dark:text-emerald-300' });
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950 print:border-gray-300 print:shadow-none">
        <div class="flex flex-col gap-4 px-4 py-4 lg:flex-row lg:items-center lg:justify-between lg:px-5">
            <div class="flex min-w-0 items-center gap-3">
                <span :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-md', workspaceMeta.iconStyle]"><Icon class="text-2xl" :name="workspaceMeta.icon" /></span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span :class="['text-[10px] font-bold uppercase tracking-[0.16em]', workspaceMeta.badgeStyle]">{{ workspaceMeta.label }}</span>
                        <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-bold', status.style]"><span :class="['h-1.5 w-1.5 rounded-full', status.dot]" />{{ status.label }}</span>
                    </div>
                    <h1 class="mt-1 truncate font-heading text-xl font-bold text-slate-700 dark:text-white sm:text-2xl">{{ surgicalRequest.procedure_name }}</h1>
                    <p class="mt-0.5 truncate text-xs text-slate-400">Passage {{ surgicalRequest.episode?.episode_number ?? '—' }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 print:hidden"><slot name="actions" /></div>
        </div>

        <dl class="grid grid-cols-2 divide-x divide-y divide-gray-200 border-t border-gray-200 dark:divide-gray-900 dark:border-gray-900 sm:grid-cols-4 xl:grid-cols-7 xl:divide-y-0">
            <div class="col-span-2 flex items-center gap-2.5 px-4 py-3 sm:col-span-2 xl:col-span-1">
                <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(patient)" />
                <div class="min-w-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Patient</dt><dd class="truncate text-xs font-bold text-slate-700 dark:text-white">{{ formatPatientName(patient) }}</dd><span class="block truncate text-[10px] text-slate-400">{{ patient.patient_number ?? '—' }}</span></div>
            </div>
            <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Âge / sexe</dt><dd class="mt-1 text-xs font-semibold text-slate-600 dark:text-slate-300">{{ age }} · {{ sexLabel }}</dd></div>
            <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Chirurgien</dt><dd class="mt-1 truncate text-xs font-semibold text-slate-600 dark:text-slate-300">{{ surgicalRequest.surgeon?.name ?? 'Non affecté' }}</dd></div>
            <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Anesthésiste</dt><dd class="mt-1 truncate text-xs font-semibold text-slate-600 dark:text-slate-300">{{ surgicalRequest.anesthesia_record?.anesthetist?.name ?? 'Non affecté' }}</dd></div>
            <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Programmation</dt><dd class="mt-1 text-xs font-semibold text-slate-600 dark:text-slate-300">{{ formatDateTime(surgicalRequest.scheduled_at) ?? 'Non programmée' }}</dd></div>
            <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Salle</dt><dd class="mt-1 truncate text-xs font-semibold text-slate-600 dark:text-slate-300">{{ surgicalRequest.operating_room ?? 'Non affectée' }}</dd></div>
            <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">N° passage</dt><dd class="mt-1 truncate font-mono text-xs font-semibold text-slate-600 dark:text-slate-300">{{ surgicalRequest.episode?.episode_number ?? '—' }}</dd></div>
        </dl>
    </section>
</template>
