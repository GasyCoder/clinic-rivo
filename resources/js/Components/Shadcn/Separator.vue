<script setup>
import { computed, useAttrs } from 'vue';
import { Separator } from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

/** Un filet de séparation (shadcn/ui). Décoratif par défaut : il n'est pas lu. */
const props = defineProps({
    orientation: { type: String, default: 'horizontal' },
    decorative: { type: Boolean, default: true },
});
const attrs = useAttrs();
const componentClass = computed(() => cn('shrink-0 bg-border', props.orientation === 'vertical' ? 'h-full w-px' : 'h-px w-full', attrs.class));
</script>

<template>
    <Separator :orientation="orientation" :decorative="decorative" :class="componentClass" />
</template>
