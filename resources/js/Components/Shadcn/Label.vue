<script setup>
import { computed, useAttrs } from 'vue';
import { Label } from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

/** Le libellé d'un champ (shadcn/ui) : un clic place le curseur dans le champ désigné par `for`. */
const attrs = useAttrs();
const componentClass = computed(() => cn('text-sm font-medium leading-none text-foreground peer-disabled:cursor-not-allowed peer-disabled:opacity-70', attrs.class));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <Label :class="componentClass" v-bind="forwardedAttrs"><slot /></Label>
</template>
