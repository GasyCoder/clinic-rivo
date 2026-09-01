<script setup>
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

defineProps({
    analyses: { type: Array, default: () => [] },
    emptyMessage: { type: String, default: 'Aucune analyse ne correspond à ces filtres.' },
    canUpdate: { type: Boolean, default: false },
    canActivate: { type: Boolean, default: false },
    canDeactivate: { type: Boolean, default: false },
});

defineEmits(['edit', 'toggle']);

const levelLabel = (value) => ({
    PARENT: 'Groupe',
    CHILD: 'Résultat',
    NORMAL: 'Autonome',
}[value] ?? value);

const typeLabel = (value) => ({
    NUMERIC: 'Numérique',
    TEXT: 'Texte',
    CHOICE: 'Choix',
    BOOLEAN: 'Oui / Non',
}[value] ?? value);
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[1120px] border-collapse">
            <thead class="bg-gray-50/80 dark:bg-gray-1000/40">
                <tr>
                    <th class="px-4 py-3 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Structure de l’analyse</th>
                    <th class="px-4 py-3 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Prestation</th>
                    <th class="px-4 py-3 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Résultat</th>
                    <th class="px-4 py-3 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Valeurs de référence</th>
                    <th class="px-4 py-3 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Statut</th>
                    <th class="px-4 py-3 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                <tr
                    v-for="analysis in analyses"
                    :key="analysis.uuid"
                    :class="[
                        'transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000/30',
                        analysis.level === 'PARENT' && 'bg-slate-50/45 dark:bg-slate-950/30',
                    ]"
                >
                    <td class="py-3 pe-4" :style="{ paddingInlineStart: `${16 + Math.min(Number(analysis.hierarchy_depth ?? 0), 6) * 24}px` }">
                        <div class="flex items-start gap-3">
                            <span :class="['mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded border', analysis.level === 'PARENT' ? 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/30 dark:text-primary-300' : 'border-gray-200 bg-white text-slate-400 dark:border-gray-800 dark:bg-gray-950']">
                                <Icon class="text-sm" :name="analysis.level === 'PARENT' ? 'folder' : analysis.level === 'CHILD' ? 'activity' : 'file-text'" />
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-bold text-slate-700 dark:text-white">{{ analysis.designation }}</p>
                                    <span :class="['rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide', analysis.level === 'PARENT' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ levelLabel(analysis.level) }}</span>
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-[10px] text-slate-400">
                                    <code class="font-bold text-primary-600 dark:text-primary-400">{{ analysis.code }}</code>
                                    <span v-if="analysis.parent">dans {{ analysis.parent.designation }}</span>
                                    <span v-if="analysis.source_system" class="rounded border border-gray-200 px-1.5 py-0.5 font-bold uppercase dark:border-gray-800">Historique</span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ analysis.catalog_item.name }}</p>
                        <code class="mt-1 block text-[10px] text-slate-400">{{ analysis.catalog_item.code }}</code>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500">
                        <p>{{ typeLabel(analysis.result_type) }}<span v-if="analysis.unit" class="font-semibold"> · {{ analysis.unit }}</span></p>
                        <p v-if="analysis.predefined_values?.length" class="mt-1 max-w-52 truncate text-[10px] text-slate-400">{{ analysis.predefined_values.join(' · ') }}</p>
                    </td>
                    <td class="px-4 py-3 text-xs leading-5 text-slate-500">
                        <p v-if="analysis.reference_general"><span class="text-slate-400">Gén.</span> {{ analysis.reference_general }}</p>
                        <p v-if="analysis.reference_male"><span class="text-slate-400">H</span> {{ analysis.reference_male }}</p>
                        <p v-if="analysis.reference_female"><span class="text-slate-400">F</span> {{ analysis.reference_female }}</p>
                        <span v-if="!analysis.reference_general && !analysis.reference_male && !analysis.reference_female" class="text-slate-300">—</span>
                    </td>
                    <td class="px-4 py-3">
                        <span :class="['inline-flex rounded px-2 py-1 text-[10px] font-bold uppercase', analysis.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ analysis.is_active ? 'Active' : 'Inactive' }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-end gap-2">
                            <Button v-if="canUpdate" type="button" size="sm" variant="white-outline" title="Modifier" @click="$emit('edit', analysis)"><Icon name="edit" /></Button>
                            <Button v-if="analysis.is_active ? canDeactivate : canActivate" type="button" size="sm" :variant="analysis.is_active ? 'warning' : 'success'" @click="$emit('toggle', analysis)">{{ analysis.is_active ? 'Désactiver' : 'Activer' }}</Button>
                        </div>
                    </td>
                </tr>
                <tr v-if="analyses.length === 0">
                    <td colspan="6" class="px-5 py-14 text-center">
                        <Icon class="text-2xl text-slate-300" name="activity" />
                        <p class="mt-2 text-sm text-slate-400">{{ emptyMessage }}</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
