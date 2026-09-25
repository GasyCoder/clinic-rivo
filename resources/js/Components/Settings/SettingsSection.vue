<script setup>
import { computed } from 'vue';
import Separator from '@/Components/Shadcn/Separator.vue';
import { SETTINGS_GROUPS, settingsSection } from '@/utilities/settingsSections';

/**
 * La page d'un module des paramètres (ADR-191, amendement du 2026-09-25) : son
 * icône et son groupe, un titre, une phrase, un filet, puis les champs empilés.
 * L'icône et le groupe sont ceux du menu (`settingsSections`), jamais recopiés.
 * Les actions du module (exporter, importer…) se placent à droite du titre.
 */
const props = defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    bodyClass: { type: String, default: 'space-y-8' },
});

const entry = computed(() => settingsSection(props.id));
const group = computed(() => SETTINGS_GROUPS.find((item) => item.id === entry.value?.group) ?? null);
</script>

<template>
    <section :id="`reglages-${id}`" class="scroll-mt-24 space-y-6" :aria-labelledby="`reglages-${id}-titre`">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-1 basis-64 items-start gap-4">
                <span v-if="entry?.icon" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <component :is="entry.icon" class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 space-y-1">
                    <p v-if="group" class="text-[0.7rem] font-semibold uppercase tracking-wider text-muted-foreground">{{ group.label }}</p>
                    <h3 :id="`reglages-${id}-titre`" class="text-lg font-medium text-foreground">{{ title }}</h3>
                    <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
        <Separator />
        <div :class="bodyClass"><slot /></div>
    </section>
</template>
