<script setup>
import { computed, ref, useAttrs } from 'vue';
import { Eye, EyeOff, Lock } from 'lucide-vue-next';
import Input from '@/Components/Shadcn/Input.vue';
import { cn } from '@/lib/cn';

/**
 * Un mot de passe, avec son bouton « afficher / masquer ».
 *
 * Le champ vient **avant** le bouton dans le document : dans un `<label>`
 * (FormField), c'est le premier élément étiquetable qui reçoit le libellé —
 * dans l'autre ordre, cliquer sur « Mot de passe » activerait le bouton.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    size: { type: String, default: 'lg' },
    /** Le cadenas en tête du champ. */
    icon: { type: Boolean, default: true },
});
defineEmits(['update:modelValue']);

const visible = ref(false);
const attrs = useAttrs();
const inputClass = computed(() => cn(
    'pe-11',
    props.icon && (props.size === 'lg' ? 'ps-11' : 'ps-9'),
    attrs.class,
));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <div class="relative">
        <Lock
            v-if="icon"
            :class="cn('pointer-events-none absolute top-1/2 z-10 -translate-y-1/2 text-muted-foreground', size === 'lg' ? 'start-4 h-4.5 w-4.5' : 'start-3 h-4 w-4')"
            aria-hidden="true"
        />
        <Input
            :model-value="modelValue"
            :type="visible ? 'text' : 'password'"
            :size="size"
            :class="inputClass"
            v-bind="forwardedAttrs"
            @update:model-value="$emit('update:modelValue', $event)"
        />
        <button
            type="button"
            class="absolute inset-y-0 end-0 flex w-11 items-center justify-center rounded-e-lg text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring/40"
            :aria-label="visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
            :aria-pressed="visible"
            @click="visible = ! visible"
        >
            <EyeOff v-if="visible" class="h-4 w-4" aria-hidden="true" />
            <Eye v-else class="h-4 w-4" aria-hidden="true" />
        </button>
    </div>
</template>
