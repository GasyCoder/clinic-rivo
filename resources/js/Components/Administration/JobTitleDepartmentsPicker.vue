<script setup>
import { computed } from 'vue';
import { Building2, Check } from 'lucide-vue-next';
import { cn } from '@/lib/cn';

/**
 * ADR-194 — les départements où une fonction existe, choisis en pastilles.
 * Un département archivé n'est plus proposé, sauf s'il est déjà coché : une
 * fonction qui le porte le garde. Aucun coché = proposée partout.
 */
const props = defineProps({
    departments: { type: Array, default: () => [] },
    error: { type: String, default: '' },
});
const selected = defineModel({ type: Array, default: () => [] });

const shown = computed(() => props.departments.filter((item) => ! item.archived || selected.value.includes(item.uuid)));
const toggle = (uuid) => {
    selected.value = selected.value.includes(uuid)
        ? selected.value.filter((item) => item !== uuid)
        : [...selected.value, uuid];
};
</script>

<template>
    <fieldset class="rounded-xl border border-sky-200 bg-sky-50/60 p-4 dark:border-sky-900 dark:bg-sky-950/20">
        <legend class="flex items-center gap-1.5 px-1 text-xs font-bold uppercase tracking-wide text-sky-700 dark:text-sky-300">
            <Building2 class="h-3.5 w-3.5" />Départements où cette fonction existe
        </legend>
        <div v-if="shown.length" class="flex flex-wrap gap-2">
            <button
                v-for="department in shown"
                :key="department.uuid"
                type="button"
                :aria-pressed="selected.includes(department.uuid)"
                :class="cn('inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    selected.includes(department.uuid)
                        ? 'border-sky-500 bg-sky-600 text-white'
                        : 'border-border bg-card text-foreground hover:border-sky-400')"
                @click="toggle(department.uuid)"
            >
                <Check v-if="selected.includes(department.uuid)" class="h-3.5 w-3.5" />{{ department.label }}
                <span v-if="department.archived" class="text-[10px] font-normal opacity-80">(archivé)</span>
            </button>
        </div>
        <p v-else class="text-xs text-muted-foreground">Aucun département enregistré : créez-les d’abord dans le module Départements.</p>
        <p class="mt-3 text-xs leading-5 text-muted-foreground">
            <template v-if="selected.length">Le dossier employé ne proposera cette fonction que dans {{ selected.length > 1 ? 'ces départements' : 'ce département' }}.</template>
            <template v-else>Aucun département coché : la fonction reste proposée dans tous les départements.</template>
        </p>
        <p v-if="error" class="mt-1 text-xs text-destructive">{{ error }}</p>
    </fieldset>
</template>
