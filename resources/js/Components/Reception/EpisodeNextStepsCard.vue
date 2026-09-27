<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Compass, Pencil } from 'lucide-vue-next';
import NextStepPicker from '@/Components/Reception/NextStepPicker.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { nextStepIcon, orderedNextSteps } from '@/utilities/nextSteps';

/**
 * ADR-177 — la prochaine étape suggérée d'un passage, relue et corrigée
 * après l'accueil.
 *
 * Une indication, jamais une orientation : la modifier ne crée, n'annule ni
 * ne cache rien. Le passage reste visible de tous les services autorisés,
 * suggéré ou non. Seul un passage ouvert se corrige, et seulement avec
 * `episodes.update` — le serveur le revérifie (`can_update_next_steps` n'est
 * qu'un reflet).
 */
const props = defineProps({
    episodeUuid: { type: String, required: true },
    /** Les valeurs enregistrées, dans l'ordre canonique. */
    nextSteps: { type: Array, default: () => [] },
    /** `[{ value, label }]`, servi par `ReceptionNextStep::options()`. */
    options: { type: Array, default: () => [] },
    canUpdate: { type: Boolean, default: false },
});

const editing = ref(false);
const draft = ref([...props.nextSteps]);
const saving = ref(false);
const error = ref('');

watch(() => props.nextSteps, (value) => {
    if (!editing.value) draft.value = [...value];
});

const labelled = computed(() => orderedNextSteps(props.nextSteps, props.options)
    .map((value) => props.options.find((option) => option.value === value))
    .filter(Boolean));

const startEditing = () => {
    draft.value = [...props.nextSteps];
    error.value = '';
    editing.value = true;
};

const cancel = () => {
    editing.value = false;
    error.value = '';
};

const save = () => {
    saving.value = true;
    error.value = '';
    router.put(`/reception/passages/${props.episodeUuid}/prochaines-etapes`, { next_steps: draft.value }, {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
        onError: (errors) => { error.value = errors.next_steps ?? Object.values(errors)[0] ?? 'La suggestion n’a pas pu être enregistrée.'; },
        onFinish: () => { saving.value = false; },
    });
};
</script>

<template>
    <Card class="overflow-hidden">
        <div class="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div class="flex items-start gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><Compass class="h-4.5 w-4.5" aria-hidden="true" /></span>
                <div>
                    <h2 class="text-sm font-bold text-foreground">Prochaine étape suggérée</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Indicative : elle ne cache ce passage à aucun service autorisé.</p>
                </div>
            </div>
            <Button v-if="canUpdate && !editing" type="button" size="xs" variant="white-outline" @click="startEditing">
                <Pencil class="h-3.5 w-3.5" aria-hidden="true" />Modifier
            </Button>
        </div>

        <div v-if="editing" class="space-y-3 p-4">
            <NextStepPicker v-model="draft" :options="options" :disabled="saving" compact />
            <p v-if="error" class="text-xs font-semibold text-destructive" role="alert">{{ error }}</p>
            <div class="flex justify-end gap-2">
                <Button type="button" size="sm" variant="white-outline" :disabled="saving" @click="cancel">Annuler</Button>
                <Button type="button" size="sm" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</Button>
            </div>
        </div>
        <div v-else class="px-5 py-4">
            <div v-if="labelled.length" class="flex flex-wrap gap-1.5">
                <Badge v-for="option in labelled" :key="option.value" variant="outline">
                    <component :is="nextStepIcon(option.value)" class="h-3.5 w-3.5" aria-hidden="true" />{{ option.label }}
                </Badge>
            </div>
            <p v-else class="text-sm text-muted-foreground">Aucune suggestion — c’est un état normal.</p>
        </div>
    </Card>
</template>
