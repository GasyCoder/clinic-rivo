<script setup>
import { computed } from 'vue';
import { SliderRange, SliderRoot, SliderThumb, SliderTrack } from 'reka-ui';
import { cn } from '@/lib/cn';

/**
 * Curseur shadcn (reka-ui) : une seule valeur, clavier (flèches, Début, Fin,
 * Page), souris et tactile. `tone="dark"` pour une surface sombre (la scène
 * du recadrage photo), où la piste claire resterait invisible.
 */
const props = defineProps({
    modelValue: { type: Number, default: 0 },
    min: { type: Number, default: 0 },
    max: { type: Number, default: 100 },
    step: { type: Number, default: 1 },
    ariaLabel: { type: String, default: '' },
    ariaValueText: { type: String, default: undefined },
    disabled: { type: Boolean, default: false },
    tone: { type: String, default: 'default' },
    class: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const value = computed({
    get: () => [props.modelValue],
    set: (next) => { if (Array.isArray(next) && typeof next[0] === 'number') emit('update:modelValue', next[0]); },
});
const dark = computed(() => props.tone === 'dark');
</script>

<template>
    <SliderRoot
        v-model="value"
        :min="min"
        :max="max"
        :step="step"
        :disabled="disabled"
        :class="cn('relative flex w-full touch-none select-none items-center data-[disabled]:opacity-50', props.class)"
    >
        <SliderTrack :class="cn('relative h-1.5 w-full grow overflow-hidden rounded-full', dark ? 'bg-white/20' : 'bg-muted')">
            <SliderRange class="absolute h-full rounded-full bg-primary" />
        </SliderTrack>
        <SliderThumb
            :aria-label="ariaLabel"
            :aria-valuetext="ariaValueText"
            :class="cn(
                'block h-4 w-4 cursor-grab rounded-full border-2 border-primary shadow-md transition-transform hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing',
                dark ? 'bg-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950' : 'bg-background',
            )"
        />
    </SliderRoot>
</template>
