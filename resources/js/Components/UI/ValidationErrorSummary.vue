<script setup>
import { computed } from 'vue';
import Icon from '@/Components/UI/Icon.vue';

const props = defineProps({
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['select']);
const entries = computed(() => Object.entries(props.errors).filter(([, message]) => Boolean(message)));
</script>

<template>
    <section
        v-if="entries.length"
        data-validation-summary
        tabindex="-1"
        role="alert"
        aria-live="assertive"
        class="rounded-md border border-red-200 bg-red-50 p-3 text-red-800 shadow-sm dark:border-red-900 dark:bg-red-950/30 dark:text-red-200"
    >
        <div class="flex items-start gap-2">
            <Icon name="alert-circle" class="mt-0.5 shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold">Corrigez {{ entries.length > 1 ? 'les champs signalés' : 'le champ signalé' }}</p>
                <div class="mt-1.5 space-y-1">
                    <button
                        v-for="([key, message], index) in entries"
                        :key="key"
                        type="button"
                        class="flex w-full items-start gap-2 rounded px-1 py-1 text-left text-xs leading-5 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-300 dark:hover:bg-red-950"
                        @click="emit('select', key)"
                    >
                        <span class="font-bold">{{ index + 1 }}.</span>
                        <span class="flex-1">{{ message }}</span>
                        <Icon name="arrow-right" class="mt-0.5 shrink-0" />
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
