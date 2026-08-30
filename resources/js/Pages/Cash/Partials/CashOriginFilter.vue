<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '@/Components/UI/Icon.vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: 'all',
    },
    options: {
        type: Array,
        default: () => [],
    },
    counts: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['update:modelValue']);
const root = ref(null);
const open = ref(false);
const selectedOption = computed(() => props.options.find((option) => option.value === props.modelValue)
    ?? props.options[0]
    ?? { value: 'all', label: 'Toutes' });

const selectOption = (value) => {
    emit('update:modelValue', value);
    open.value = false;
};

const closeOnOutsidePointer = (event) => {
    if (root.value && !root.value.contains(event.target)) open.value = false;
};

onMounted(() => document.addEventListener('pointerdown', closeOnOutsidePointer));
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeOnOutsidePointer));
</script>

<template>
    <div ref="root" class="relative w-full sm:w-52" @keydown.esc="open = false">
        <button
            type="button"
            class="flex h-9 w-full items-center justify-between gap-3 rounded border border-gray-200 bg-white px-3 text-start transition-colors hover:border-gray-300 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:hover:border-gray-700 dark:focus:border-primary-700 dark:focus:ring-primary-950"
            aria-haspopup="listbox"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="min-w-0">
                <span class="me-2 text-[9px] font-bold uppercase tracking-[0.12em] text-slate-400">Origine</span>
                <strong class="text-xs font-bold text-slate-700 dark:text-white">{{ selectedOption.label }}</strong>
                <span class="ms-1.5 text-[11px] tabular-nums text-slate-400">{{ counts[selectedOption.value] ?? 0 }}</span>
            </span>
            <Icon :class="['shrink-0 text-sm text-slate-400 transition-transform', open && 'rotate-180']" name="chevron-down" />
        </button>

        <div v-if="open" class="absolute end-0 z-50 mt-1.5 w-full min-w-56 overflow-hidden rounded border border-gray-200 bg-white p-1 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="listbox" aria-label="Filtrer par origine">
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                role="option"
                :aria-selected="modelValue === option.value"
                :class="['flex w-full items-center gap-3 rounded-sm px-3 py-2 text-start text-xs transition-colors', modelValue === option.value ? 'bg-primary-50 font-bold text-primary-700 dark:bg-primary-950/50 dark:text-primary-300' : 'text-slate-600 hover:bg-gray-50 dark:text-slate-300 dark:hover:bg-gray-900']"
                @click="selectOption(option.value)"
            >
                <span class="flex-1">{{ option.label }}</span>
                <span class="tabular-nums text-slate-400">{{ counts[option.value] ?? 0 }}</span>
                <Icon v-if="modelValue === option.value" class="text-sm text-primary-600 dark:text-primary-300" name="check" />
                <span v-else class="w-3.5" aria-hidden="true"></span>
            </button>
        </div>
    </div>
</template>
