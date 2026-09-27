<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Check, LoaderCircle } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { lucideIcon } from '@/lib/icons';

/*
 * La barre d'enregistrement des formulaires RH, collée en bas : « Annuler »
 * à gauche, l'action à droite. Pendant l'envoi, le bouton le dit et ne se
 * clique plus deux fois.
 */
const props = defineProps({
    cancelHref: String,
    submitLabel: String,
    processing: Boolean,
    // Un composant lucide, ou un nom de la table `lib/icons.js`.
    submitIcon: { type: [String, Object, Function], default: () => Check },
});
const glyph = computed(() => (typeof props.submitIcon === 'string' ? lucideIcon(props.submitIcon) : props.submitIcon));
</script>

<template>
    <footer class="sticky bottom-3 z-10 flex flex-col-reverse gap-2 rounded-xl border border-border bg-card/95 p-3 shadow-lg backdrop-blur supports-[backdrop-filter]:bg-card/80 sm:flex-row sm:items-center sm:justify-between">
        <Button :as="Link" :href="cancelHref" variant="outline">Annuler</Button>
        <div class="flex items-center justify-end gap-3">
            <slot />
            <Button type="submit" :disabled="processing">
                <LoaderCircle v-if="processing" class="h-4 w-4 animate-spin" />
                <component :is="glyph" v-else class="h-4 w-4" />
                {{ processing ? 'Enregistrement…' : submitLabel }}
            </Button>
        </div>
    </footer>
</template>
