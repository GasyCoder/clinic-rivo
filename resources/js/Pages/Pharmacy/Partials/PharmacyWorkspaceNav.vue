<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';

defineProps({
    capabilities: { type: Object, required: true },
    active: { type: String, default: '' },
    dispenseCount: { type: Number, default: 0 },
    linkMode: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);
</script>

<template>
    <nav class="sticky top-16 z-[1020] flex max-w-full gap-1 overflow-x-auto rounded-lg border border-gray-200 bg-white/95 p-2 shadow-sm backdrop-blur dark:border-gray-900 dark:bg-gray-950/95" aria-label="Sections Pharmacie">
        <component
            :is="linkMode ? Link : 'button'"
            v-if="capabilities.can_view_stock"
            :href="linkMode ? '/pharmacy?tab=stock' : undefined"
            :type="linkMode ? undefined : 'button'"
            :class="['inline-flex shrink-0 items-center gap-2 rounded px-4 py-2.5 text-xs font-bold transition', active === 'stock' ? 'bg-slate-700 text-white shadow-sm dark:bg-white dark:text-slate-800' : 'bg-gray-100 text-slate-500 hover:text-slate-700 dark:bg-gray-900 dark:text-slate-300 dark:hover:text-white']"
            @click="!linkMode && emit('select', 'stock')"
        >
            <Icon name="package" />Stock & lots
        </component>
        <component
            :is="linkMode ? Link : 'button'"
            v-if="capabilities.can_view_prescriptions"
            :href="linkMode ? '/pharmacy?tab=dispenses' : undefined"
            :type="linkMode ? undefined : 'button'"
            :class="['inline-flex shrink-0 items-center gap-2 rounded px-4 py-2.5 text-xs font-bold transition', active === 'dispenses' ? 'bg-slate-700 text-white shadow-sm dark:bg-white dark:text-slate-800' : 'bg-gray-100 text-slate-500 hover:text-slate-700 dark:bg-gray-900 dark:text-slate-300 dark:hover:text-white']"
            @click="!linkMode && emit('select', 'dispenses')"
        >
            <Icon name="file-docs" />Demandes de dispensation
            <span v-if="dispenseCount" :class="['rounded-full px-1.5 py-0.5 text-[10px]', active === 'dispenses' ? 'bg-white/20 text-white dark:bg-slate-700' : 'bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-300']">{{ dispenseCount }}</span>
        </component>
        <component
            :is="linkMode ? Link : 'button'"
            v-if="capabilities.can_view_categories || capabilities.can_view_suppliers"
            :href="linkMode ? '/pharmacy?tab=setup' : undefined"
            :type="linkMode ? undefined : 'button'"
            :class="['inline-flex shrink-0 items-center gap-2 rounded px-4 py-2.5 text-xs font-bold transition', active === 'setup' ? 'bg-slate-700 text-white shadow-sm dark:bg-white dark:text-slate-800' : 'bg-gray-100 text-slate-500 hover:text-slate-700 dark:bg-gray-900 dark:text-slate-300 dark:hover:text-white']"
            @click="!linkMode && emit('select', 'setup')"
        >
            <Icon name="setting" />Paramétrage
        </component>
    </nav>
</template>
