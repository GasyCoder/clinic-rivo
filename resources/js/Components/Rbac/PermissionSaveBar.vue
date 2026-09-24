<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Check, CircleCheck, CircleDot, Eye, Loader2, RotateCcw } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';

/**
 * Où l'on en est, et le bouton qui envoie (ADR-178).
 *
 * Trois états, toujours au même endroit :
 *  - rien à envoyer — la barre se fait discrète, au bas du travail ;
 *  - des modifications en attente — elle colle au bas de l'écran, dit
 *    combien, lesquelles (« Revoir »), et à qui elles s'appliqueront ;
 *  - enregistré — la confirmation reste quelques secondes sur place, en plus
 *    du message global : on voit que c'est fait sans chercher où.
 */
const props = defineProps({
    dirty: { type: Boolean, default: false },
    count: { type: Number, default: 0 },
    added: { type: Number, default: null },
    removed: { type: Number, default: null },
    processing: { type: Boolean, default: false },
    canSave: { type: Boolean, default: true },
    /** Horodatage du dernier enregistrement réussi. */
    savedAt: { type: Number, default: null },
    /** Ce que touchera l'envoi : « s’appliqueront aux 3 comptes du rôle ». */
    scope: { type: String, default: '' },
    cleanText: { type: String, default: 'Aucune modification en attente.' },
    saveLabel: { type: String, default: 'Enregistrer les modifications' },
    addedLabel: { type: String, default: 'accordée' },
    removedLabel: { type: String, default: 'retirée' },
    errors: { type: Array, default: () => [] },
});

const emit = defineEmits(['save', 'discard', 'review']);

const justSaved = ref(false);
let timer = null;

watch(() => props.savedAt, (value) => {
    if (! value) return;

    justSaved.value = true;
    clearTimeout(timer);
    timer = setTimeout(() => { justSaved.value = false; }, 6000);
});

watch(() => props.dirty, (value) => { if (value) justSaved.value = false; });

onBeforeUnmount(() => clearTimeout(timer));

const savedTime = computed(() => (props.savedAt
    ? new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(props.savedAt))
    : ''));

const plural = (count, word) => `${count} ${word}${count > 1 ? 's' : ''}`;
</script>

<template>
    <div
        :class="cn(
            'z-30 rounded-xl border px-4 py-3 transition-[background-color,border-color,box-shadow]',
            dirty
                ? 'sticky bottom-3 border-amber-300 bg-card/95 shadow-xl ring-1 ring-amber-200/60 backdrop-blur dark:border-amber-800 dark:ring-amber-900/40'
                : justSaved
                    ? 'sticky bottom-3 border-emerald-300 bg-card/95 shadow-lg backdrop-blur dark:border-emerald-800'
                    : 'border-border bg-card/60',
        )"
        role="status"
        aria-live="polite"
    >
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <span
                    :class="cn(
                        'grid h-9 w-9 shrink-0 place-items-center rounded-full',
                        dirty ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'
                            : justSaved ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
                                : 'bg-muted text-muted-foreground',
                    )"
                    aria-hidden="true"
                >
                    <CircleDot v-if="dirty" class="h-4.5 w-4.5" />
                    <CircleCheck v-else class="h-4.5 w-4.5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-foreground">
                        <template v-if="dirty">{{ plural(count, 'modification') }} non enregistrée{{ count > 1 ? 's' : '' }}</template>
                        <template v-else-if="justSaved">Modifications enregistrées<span v-if="savedTime" class="font-normal text-muted-foreground"> · {{ savedTime }}</span></template>
                        <template v-else>À jour</template>
                    </p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <template v-if="dirty">
                            <span v-if="added !== null && added > 0" class="font-semibold text-emerald-700 dark:text-emerald-300">+{{ plural(added, addedLabel) }}</span>
                            <span v-if="removed !== null && removed > 0" class="font-semibold text-destructive">−{{ plural(removed, removedLabel) }}</span>
                            <span v-if="scope">{{ scope }}</span>
                        </template>
                        <template v-else>{{ cleanText }}</template>
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 md:justify-end">
                <slot name="extra" />
                <template v-if="dirty">
                    <Button type="button" variant="ghost" size="sm" @click="emit('review')">
                        <Eye class="h-4 w-4" />Revoir
                    </Button>
                    <Button type="button" variant="outline" size="sm" :disabled="processing" @click="emit('discard')">
                        <RotateCcw class="h-4 w-4" />Annuler
                    </Button>
                </template>
                <Button
                    type="button"
                    variant="primary"
                    :size="dirty ? 'default' : 'sm'"
                    :disabled="processing || ! dirty || ! canSave"
                    @click="emit('save')"
                >
                    <Loader2 v-if="processing" class="h-4 w-4 animate-spin" />
                    <Check v-else class="h-4 w-4" />
                    {{ processing ? 'Enregistrement…' : saveLabel }}
                </Button>
            </div>
        </div>

        <div v-if="errors.length" class="mt-2 space-y-1">
            <FormError v-for="error in errors" :key="error">{{ error }}</FormError>
        </div>
    </div>
</template>
