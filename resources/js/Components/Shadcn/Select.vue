<script setup>
import { computed, useAttrs } from 'vue';
import { Check, ChevronDown } from 'lucide-vue-next';
import {
    SelectContent,
    SelectGroup,
    SelectIcon,
    SelectItem,
    SelectLabel,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectValue,
    SelectViewport,
} from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const EMPTY_VALUE = '__rivo_all__';
const props = defineProps({
    modelValue: { type: String, default: '' },
    /**
     * Soit une liste plate `[{ value, label }]`, soit des groupes
     * `[{ label, items: [{ value, label }] }]`. Les deux formes coexistent
     * parce que certains référentiels sont classés — les allergènes par
     * famille (ADR-032) — et qu'aplatir la liste ferait perdre ce
     * classement au lieu de simplement changer son apparence.
     */
    options: { type: Array, required: true },
    placeholder: { type: String, default: 'Sélectionner' },
    /**
     * Repère de tête facultatif — le composant lucide lui-même, jamais un
     * nom : une icône retirée de l'icon set casse alors au build plutôt que
     * de laisser un carré vide dans le champ.
     */
    icon: { type: [Object, Function], default: null },
});
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();
const model = computed({
    get: () => props.modelValue || EMPTY_VALUE,
    set: (value) => emit('update:modelValue', value === EMPTY_VALUE ? '' : value),
});
const triggerClass = computed(() => cn(
    'flex h-10 min-w-[176px] items-center justify-between gap-3 rounded-lg border border-input bg-card px-3 py-2 text-sm text-foreground shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50',
    attrs.class,
));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    return rest;
});
const itemValue = (option) => option.value || EMPTY_VALUE;
const isGroup = (option) => Array.isArray(option?.items);
</script>

<template>
    <SelectRoot v-model="model">
        <SelectTrigger :class="triggerClass" v-bind="forwardedAttrs">
            <span class="flex min-w-0 items-center gap-2">
                <component :is="icon" v-if="icon" class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <SelectValue class="truncate" :placeholder="placeholder" />
            </span>
            <SelectIcon as-child><ChevronDown class="h-4 w-4 shrink-0 text-muted-foreground" /></SelectIcon>
        </SelectTrigger>
        <SelectPortal>
            <!-- La hauteur est bornée à la place réellement disponible, sinon
                 une liste longue ouverte près du bas de page (le référentiel
                 d'allergènes, par exemple) déborde au-dessus de la fenêtre et
                 ses premières entrées deviennent inatteignables. -->
            <SelectContent
                position="popper"
                :side-offset="6"
                :avoid-collisions="true"
                class="z-[1500] max-h-[min(24rem,var(--reka-select-content-available-height))] min-w-[var(--reka-select-trigger-width)] overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-xl"
            >
                <SelectViewport class="max-h-[inherit] overflow-y-auto overscroll-contain p-1">
                    <template v-for="(option, index) in options" :key="isGroup(option) ? `group-${index}` : itemValue(option)">
                        <SelectGroup v-if="isGroup(option)">
                            <SelectLabel class="px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ option.label }}</SelectLabel>
                            <SelectItem
                                v-for="item in option.items"
                                :key="itemValue(item)"
                                :value="itemValue(item)"
                                class="relative flex cursor-default select-none items-center rounded-md py-2 pe-8 ps-3 text-sm outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
                            >
                                <SelectItemText>{{ item.label }}</SelectItemText>
                                <SelectItemIndicator class="absolute end-2 grid place-items-center">
                                    <Check class="h-4 w-4 text-primary" />
                                </SelectItemIndicator>
                            </SelectItem>
                        </SelectGroup>
                        <SelectItem
                            v-else
                            :value="itemValue(option)"
                            class="relative flex cursor-default select-none items-center rounded-md py-2 pe-8 ps-3 text-sm outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
                        >
                            <SelectItemText>{{ option.label }}</SelectItemText>
                            <SelectItemIndicator class="absolute end-2 grid place-items-center">
                                <Check class="h-4 w-4 text-primary" />
                            </SelectItemIndicator>
                        </SelectItem>
                    </template>
                </SelectViewport>
            </SelectContent>
        </SelectPortal>
    </SelectRoot>
</template>
