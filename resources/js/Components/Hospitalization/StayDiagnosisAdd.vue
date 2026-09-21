<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Input from '@/Components/Shadcn/Input.vue';
import FormError from '@/Components/UI/FormError.vue';
import { Check, Plus, Stethoscope } from 'lucide-vue-next';

/**
 * ADR-147 — poser un diagnostic sur le séjour.
 *
 * Le médecin conclut au terme du séjour : ce diagnostic est enregistré sur le
 * séjour, jamais dans la consultation qui a demandé l'hospitalisation — elle
 * est le plus souvent close (ADR-076). Append-only ; le serveur revérifie.
 *
 * Écrit une seule fois : la carte « Diagnostics » de la Vue d'ensemble et
 * l'étape Sortie l'emploient toutes les deux. Un diagnostic posé ici rejoint
 * `diagnoses`, et le formulaire de sortie le coche de lui-même — c'est ce qui
 * dispense la sortie de renvoyer vers un autre onglet.
 */
const props = defineProps({
    stayUuid: { type: String, required: true },
    label: { type: String, default: 'Ajouter un diagnostic' },
});

const open = ref(false);
const search = ref('');
const results = ref([]);
const form = useForm({ diagnostic_catalog_uuid: null, description: '', notes: '' });
let timer = null;

watch(search, (term) => {
    clearTimeout(timer);
    const query = term.trim();

    if (query.length < 2) {
        results.value = [];

        return;
    }

    timer = setTimeout(async () => {
        try {
            const response = await fetch(`/diagnostic-catalog/search?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
            });
            results.value = response.ok ? (await response.json()).data ?? [] : [];
        } catch {
            // Le catalogue est une aide de saisie : son indisponibilité ne doit
            // jamais empêcher de poser un diagnostic à la main.
            results.value = [];
        }
    }, 300);
});

onBeforeUnmount(() => clearTimeout(timer));

const reset = () => {
    form.reset();
    form.clearErrors();
    search.value = '';
    results.value = [];
    open.value = false;
};

const add = (catalogUuid = null) => {
    form.diagnostic_catalog_uuid = catalogUuid;
    form.description = catalogUuid ? '' : search.value.trim();

    if (!catalogUuid && !form.description) {
        return;
    }

    form.post(`/hospitalisation/${props.stayUuid}/diagnostics`, {
        preserveScroll: true,
        onSuccess: reset,
    });
};
</script>

<template>
    <div>
        <Button v-if="!open" type="button" size="xs" variant="outline" @click="open = true">
            <Plus class="h-3.5 w-3.5" aria-hidden="true" />{{ label }}
        </Button>
        <div v-else class="rounded-md border border-border bg-muted/30 p-2.5">
            <Input
                v-model="search"
                placeholder="Rechercher au catalogue, ou saisir un libellé"
                autofocus
                @keydown.enter.prevent="add()"
            />
            <ul v-if="results.length" class="mt-1.5 space-y-1">
                <li v-for="result in results" :key="result.uuid">
                    <button
                        type="button"
                        class="flex w-full items-start gap-2 rounded-md border border-border bg-card px-2.5 py-1.5 text-start text-xs hover:bg-accent"
                        @click="add(result.uuid)"
                    >
                        <Stethoscope class="mt-px h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span><span class="font-semibold">{{ result.name }}</span>
                            <span v-if="result.code" class="text-muted-foreground"> · {{ result.code }}</span></span>
                    </button>
                </li>
            </ul>
            <p v-else-if="search.trim().length >= 2" class="mt-1.5 text-[11px] text-muted-foreground">
                Aucun diagnostic du catalogue : « Ajouter » l’enregistre tel quel.
            </p>
            <FormError :message="form.errors.description || form.errors.diagnostic_catalog_uuid" />
            <div class="mt-2 flex justify-end gap-2">
                <Button type="button" size="xs" variant="ghost" @click="reset">Annuler</Button>
                <Button type="button" size="xs" :disabled="form.processing || !search.trim()" @click="add()">
                    <Check class="h-3.5 w-3.5" aria-hidden="true" />Ajouter
                </Button>
            </div>
        </div>
    </div>
</template>
