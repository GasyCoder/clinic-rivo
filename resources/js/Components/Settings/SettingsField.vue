<script setup>
import Label from '@/Components/Shadcn/Label.vue';

/**
 * Un champ des paramètres (ADR-191), comme le « FormItem » de shadcn/ui : le
 * libellé au-dessus, le contrôle, puis une phrase d'aide et l'erreur éventuelle.
 */
defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    error: { type: String, default: '' },
    /** L'identifiant du champ : un clic sur le libellé y place le curseur. */
    for: { type: String, default: '' },
});
</script>

<template>
    <div class="min-w-0 space-y-2">
        <Label v-if="$props.for" :for="$props.for">{{ label }}</Label>
        <p v-else class="text-sm font-medium leading-none text-foreground">{{ label }}</p>
        <slot />
        <p v-if="description" class="text-[0.8rem] leading-5 text-muted-foreground">{{ description }}</p>
        <p v-if="error" class="text-[0.8rem] font-medium text-destructive" role="alert">{{ error }}</p>
    </div>
</template>
