<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { Pencil, Plus, Search } from 'lucide-vue-next';

/**
 * Recording a diagnosis: catalogue search, or manual entry when the
 * référentiel has nothing that fits.
 *
 * No hypothesis/final choice any more (ADR-082): what the doctor records is
 * the diagnosis. Entries stored as hypotheses before that change keep their
 * type and stay flagged, so an old hypothesis is never read as a confirmed
 * diagnosis.
 *
 * Posts to the same append-only endpoint as everything else, so a diagnosis
 * recorded here is the same act, audited the same way (ADR-035).
 */
const props = defineProps({
    orientationUuid: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
    /** Wizard step to come back to once recorded — the screen the doctor is on. */
    returnStep: { type: String, default: null },
});

const emit = defineEmits(['recorded']);

const form = useForm({
    // Plus de choix : une saisie est un diagnostic. L'ADR-035 distinguait
    // hypothèse et diagnostic final ; le propriétaire a retiré cette
    // distinction de la saisie (ADR-082). Les lignes déjà enregistrées comme
    // hypothèses gardent leur type et restent signalées comme telles.
    type: 'FINAL',
    diagnostic_catalog_uuid: null,
    description: '',
    manual_code: '',
    notes: '',
});

const manualMode = ref(false);
const search = ref('');
const results = ref([]);
const loading = ref(false);
const searchError = ref('');
let timer = null;

const reset = () => {
    form.reset('diagnostic_catalog_uuid', 'description', 'manual_code', 'notes');
    form.clearErrors();
    search.value = '';
    results.value = [];
};

watch(search, (value) => {
    clearTimeout(timer);
    const query = value.trim();

    if (query.length < 2) {
        results.value = [];
        loading.value = false;
        searchError.value = '';

        return;
    }

    loading.value = true;
    timer = setTimeout(async () => {
        try {
            const response = await fetch(`/diagnostic-catalog/search?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('search failed');
            results.value = (await response.json()).data ?? [];
            searchError.value = '';
        } catch {
            results.value = [];
            searchError.value = 'La recherche du catalogue est momentanément indisponible.';
        } finally {
            loading.value = false;
        }
    }, 250);
});

const submit = (catalogUuid = null) => {
    form.diagnostic_catalog_uuid = catalogUuid;

    if (catalogUuid) {
        form.description = '';
        form.manual_code = '';
    }

    form.transform((data) => ({ ...data, return_step: props.returnStep })).post(`/medicine/orientations/${props.orientationUuid}/diagnoses`, {
        preserveScroll: true,
        onSuccess: () => {
            reset();
            manualMode.value = false;
            emit('recorded');
        },
    });
};
</script>

<template>
    <div :class="compact ? 'space-y-3' : 'space-y-4'">
        <template v-if="!manualMode">
            <div>
                <IconInput
                    id="clinical_diagnosis_search"
                    v-model="search"
                    :icon="Search"
                    autocomplete="off"
                    :disabled="disabled"
                    placeholder="Rechercher par diagnostic ou code…"
                />
                <p v-if="search.trim().length === 1" class="mt-1.5 text-[11px] text-muted-foreground">Saisissez au moins 2 caractères.</p>
                <p v-if="loading" class="mt-1.5 text-[11px] text-muted-foreground">Recherche en cours…</p>
                <p v-if="searchError" class="mt-1.5 text-[11px] text-destructive">{{ searchError }}</p>
            </div>

            <div v-if="results.length" class="overflow-hidden rounded-md border border-border">
                <button
                    v-for="diagnostic in results"
                    :key="diagnostic.uuid"
                    type="button"
                    class="flex w-full items-center gap-3 border-b border-border px-3 py-2.5 text-left transition-colors last:border-0 hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="form.processing || disabled"
                    @click="submit(diagnostic.uuid)"
                >
                    <span class="min-w-0 flex-1">
                        <!-- The search endpoint returns the catalogue's own `name`
                             (DiagnosticCatalogSearchController): reading a `label`
                             that does not exist left every result blank. -->
                        <span class="block truncate text-xs font-semibold text-foreground">{{ diagnostic.name }}</span>
                        <span v-if="diagnostic.code || diagnostic.category" class="mt-0.5 block text-[10px] text-muted-foreground">
                            <span v-if="diagnostic.code" class="font-mono">{{ diagnostic.code }}</span><template v-if="diagnostic.code && diagnostic.category"> · </template>{{ diagnostic.category }}
                        </span>
                    </span>
                    <Plus class="h-4 w-4 shrink-0 text-muted-foreground" />
                </button>
            </div>
            <p
                v-else-if="search.trim().length >= 2 && !loading && !searchError"
                class="rounded-md border border-dashed border-border px-3 py-4 text-center text-[11px] text-muted-foreground"
            >Aucun diagnostic actif ne correspond à cette recherche.</p>

            <Button type="button" size="sm" variant="outline" :disabled="disabled" @click="manualMode = true">
                <Pencil class="h-4 w-4" />Saisie manuelle
            </Button>
        </template>

        <!-- Saisie libre : reste dans ce dossier et n'alimente jamais
             automatiquement le catalogue partagé. -->
        <form v-else class="rounded-md border border-border bg-card p-3" @submit.prevent="submit(null)">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold text-foreground">Diagnostic manuel</p>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">Cette saisie reste dans ce dossier et n’alimente pas le catalogue.</p>
                </div>
                <button type="button" class="shrink-0 text-[11px] font-semibold text-muted-foreground transition-colors hover:text-foreground" @click="manualMode = false; form.clearErrors()">Revenir au catalogue</button>
            </div>
            <div class="space-y-2">
                <div>
                    <Input v-model="form.description" :disabled="disabled" maxlength="500" placeholder="Libellé du diagnostic *" aria-label="Libellé du diagnostic" />
                    <FormError :message="form.errors.description" />
                </div>
                <Input v-model="form.manual_code" :disabled="disabled" maxlength="50" placeholder="Code (facultatif)" aria-label="Code du diagnostic" />
                <Input v-model="form.notes" :disabled="disabled" maxlength="1000" placeholder="Note (facultatif)" aria-label="Note du diagnostic" />
            </div>
            <div class="mt-3 flex justify-end">
                <Button type="submit" size="sm" :disabled="form.processing || disabled">
                    <Plus class="h-4 w-4" />Enregistrer le diagnostic
                </Button>
            </div>
        </form>

        <FormError :message="form.errors.diagnosis" />
    </div>
</template>
