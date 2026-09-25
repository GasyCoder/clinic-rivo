<script setup>
import { onMounted, ref } from 'vue';
import { Building2, ShieldCheck } from 'lucide-vue-next';
import Label from '@/Components/Shadcn/Label.vue';
import RadioGroup from '@/Components/Shadcn/RadioGroup.vue';
import RadioGroupItem from '@/Components/Shadcn/RadioGroupItem.vue';
import { cn } from '@/lib/cn';

/**
 * Le choix du site réglé (ADR-184, ADR-191) : un bouton par site et un pour le
 * portail, en un clic, avec l'état de chacun sous les yeux — joignable, non
 * configuré ou injoignable. Un groupe radio shadcn : flèches du clavier, un seul
 * choix. Changer de site avec des modifications en cours reste confirmé par la
 * page, qui reçoit le choix et décide.
 */
defineProps({
    targets: { type: Array, required: true },
    modelValue: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);

/** Sur un écran étroit, la ligne défile : le site choisi y est ramené en vue, sans bouger la page. */
const root = ref(null);
onMounted(() => {
    const el = root.value?.$el ?? root.value;
    const checked = el?.querySelector?.('[data-state="checked"]')?.closest('label');
    if (! el || ! checked || el.scrollWidth <= el.clientWidth) return;
    el.scrollLeft = checked.offsetLeft - (el.clientWidth - checked.offsetWidth) / 2;
});

const stateOf = (target) => {
    if (target.ok) return { dot: 'bg-emerald-500', label: 'joignable' };
    if (target.status === 'UNCONFIGURED') return { dot: 'bg-muted-foreground/60', label: 'non configuré' };

    return { dot: 'bg-destructive', label: 'injoignable' };
};

const OPTION_CLASS = cn(
    'relative inline-flex h-9 shrink-0 cursor-pointer select-none items-center gap-2 whitespace-nowrap rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground',
    '[&:has([data-state=checked])]:bg-card [&:has([data-state=checked])]:text-foreground [&:has([data-state=checked])]:shadow-sm',
    '[&:has(:focus-visible)]:ring-2 [&:has(:focus-visible)]:ring-ring/40',
);
</script>

<template>
    <RadioGroup
        ref="root"
        :model-value="modelValue"
        class="relative flex max-w-full gap-1 overflow-x-auto rounded-lg border border-border bg-muted/60 p-1"
        aria-label="Site réglé"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <Label v-for="target in targets" :key="target.site.code" :class="OPTION_CLASS" :title="`${target.site.name} — ${stateOf(target).label}`">
            <RadioGroupItem :value="target.site.code" class="sr-only" />
            <component :is="target.kind === 'portal' ? ShieldCheck : Building2" class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{{ target.site.name }}</span>
            <span :class="cn('h-1.5 w-1.5 shrink-0 rounded-full', stateOf(target).dot)" aria-hidden="true" />
            <span class="sr-only">({{ stateOf(target).label }})</span>
        </Label>
    </RadioGroup>
</template>
