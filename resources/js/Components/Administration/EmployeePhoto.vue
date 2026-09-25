<script setup>
import { computed, ref, watch } from 'vue';
import { cn } from '@/lib/cn';
import { initials } from '@/utilities/hr';

/*
 * ADR-194 — la photo d'identité d'un employé ou d'un stagiaire, carrée comme
 * une photo 4 × 4. Sans photo — ou si elle ne se charge pas —, ses initiales.
 */
const props = defineProps({
    src: { type: String, default: null },
    name: { type: String, default: '' },
    size: { type: String, default: 'md' },
    rounded: { type: Boolean, default: false },
    class: { type: String, default: '' },
});

const failed = ref(false);
watch(() => props.src, () => { failed.value = false; });

const SIZES = {
    xs: 'h-7 w-7 text-[10px]',
    sm: 'h-9 w-9 text-xs',
    md: 'h-11 w-11 text-sm',
    lg: 'h-16 w-16 text-base',
    xl: 'h-28 w-28 text-2xl',
};
const componentClass = computed(() => cn(
    'relative grid shrink-0 place-items-center overflow-hidden bg-primary/10 font-bold text-primary ring-1 ring-border',
    props.rounded ? 'rounded-full' : 'rounded-lg',
    SIZES[props.size] ?? SIZES.md,
    props.class,
));
</script>

<template>
    <span :class="componentClass">
        <img
            v-if="src && !failed"
            :src="src"
            :alt="`Photo de ${name}`"
            class="h-full w-full object-cover"
            loading="lazy"
            @error="failed = true"
        >
        <span v-else aria-hidden="true">{{ initials(name) }}</span>
    </span>
</template>
