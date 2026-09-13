<script setup>
import Icon from '@/Components/UI/Icon.vue';

defineProps({
    permission: { type: Object, required: true },
    state: { type: String, default: '' },
    roleGranted: { type: Boolean, default: false },
    effectiveGranted: { type: Boolean, default: false },
    sensitive: { type: Boolean, default: false },
    advanced: { type: Boolean, default: false },
    sourceLabel: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

defineEmits(['change']);

const choices = [
    { value: '', label: 'Hériter' },
    { value: 'allow', label: 'Autoriser' },
    { value: 'deny', label: 'Interdire' },
];
</script>

<template>
    <div
        :class="[
            'grid gap-3 border-b border-gray-100 px-4 py-3 last:border-b-0 dark:border-gray-900 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center',
            state === 'allow' ? 'bg-emerald-50/30 dark:bg-emerald-950/10' : state === 'deny' ? 'bg-red-50/30 dark:bg-red-950/10' : '',
        ]"
    >
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-1.5">
                <p class="text-sm font-semibold text-slate-700 dark:text-white">{{ permission.label }}</p>
                <span v-if="sensitive" class="inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-900">
                    <Icon class="text-xs" name="alert-circle" />Sensible
                </span>
                <span v-if="state === ''" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500 dark:bg-gray-900">Hérité</span>
                <span v-else-if="state === 'allow'" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">Exception · Autorisé</span>
                <span v-else class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700 dark:bg-red-950 dark:text-red-300">Exception · Interdit</span>
            </div>
            <p v-if="advanced" class="mt-1 truncate font-mono text-[11px] text-slate-400" :title="permission.name">{{ permission.name }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px]">
                <span class="text-slate-400">Rôle : <strong :class="roleGranted ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500'">{{ roleGranted ? 'autorisé' : 'interdit' }}</strong></span>
                <span class="text-slate-400">Effectif : <strong :class="effectiveGranted ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">{{ effectiveGranted ? 'autorisé' : 'interdit' }}</strong></span>
                <span v-if="sourceLabel" class="text-primary-600 dark:text-primary-300">{{ sourceLabel }}</span>
            </div>
        </div>

        <fieldset class="min-w-0" :disabled="disabled">
            <legend class="sr-only">Accès pour {{ permission.label }}</legend>
            <div class="inline-flex w-full rounded border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-800 dark:bg-gray-900 sm:w-auto" role="radiogroup" :aria-label="`Accès pour ${permission.label}`">
                <button
                    v-for="choice in choices"
                    :key="choice.value || 'inherit'"
                    type="button"
                    role="radio"
                    :aria-checked="state === choice.value"
                    :disabled="disabled"
                    :class="[
                        'min-h-8 flex-1 rounded px-2.5 py-1 text-[11px] font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400 sm:flex-none',
                        state === choice.value
                            ? choice.value === 'allow'
                                ? 'bg-emerald-600 text-white shadow-sm'
                                : choice.value === 'deny'
                                    ? 'bg-red-600 text-white shadow-sm'
                                    : 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white'
                            : 'text-slate-500 hover:bg-white hover:text-slate-700 dark:hover:bg-gray-950 dark:hover:text-white',
                    ]"
                    @click="$emit('change', choice.value)"
                >
                    {{ choice.label }}
                </button>
            </div>
        </fieldset>
    </div>
</template>
