<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';

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

    form.post(`/medicine/orientations/${props.orientationUuid}/diagnoses`, {
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
                    icon="search"
                    autocomplete="off"
                    :disabled="disabled"
                    placeholder="Rechercher par diagnostic ou code…"
                />
                <p v-if="search.trim().length === 1" class="mt-1.5 text-[11px] text-slate-400">Saisissez au moins 2 caractères.</p>
                <p v-if="loading" class="mt-1.5 text-[11px] text-slate-400">Recherche en cours…</p>
                <p v-if="searchError" class="mt-1.5 text-[11px] text-red-500">{{ searchError }}</p>
            </div>

            <div v-if="results.length" class="overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                <button
                    v-for="diagnostic in results"
                    :key="diagnostic.uuid"
                    type="button"
                    class="flex w-full items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-left transition-colors last:border-0 hover:bg-gray-50 dark:border-gray-900 dark:hover:bg-gray-900"
                    :disabled="form.processing || disabled"
                    @click="submit(diagnostic.uuid)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-semibold text-slate-700 dark:text-white">{{ diagnostic.label }}</span>
                        <span v-if="diagnostic.code" class="mt-0.5 block font-mono text-[10px] text-slate-400">{{ diagnostic.code }}</span>
                    </span>
                    <Icon name="plus" class="shrink-0 text-slate-400" />
                </button>
            </div>
            <p
                v-else-if="search.trim().length >= 2 && !loading && !searchError"
                class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-center text-[11px] text-slate-400 dark:border-gray-800"
            >Aucun diagnostic actif ne correspond à cette recherche.</p>

            <Button type="button" size="sm" variant="white-outline" :disabled="disabled" @click="manualMode = true">
                <Icon class="me-1.5 text-sm" name="edit" />Saisie manuelle
            </Button>
        </template>

        <!-- Saisie libre : reste dans ce dossier et n'alimente jamais
             automatiquement le catalogue partagé. -->
        <form v-else class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-950" @submit.prevent="submit(null)">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold text-slate-700 dark:text-white">Diagnostic manuel</p>
                    <p class="mt-0.5 text-[11px] text-slate-400">Cette saisie reste dans ce dossier et n’alimente pas le catalogue.</p>
                </div>
                <button type="button" class="shrink-0 text-[11px] font-semibold text-slate-500 hover:text-slate-700" @click="manualMode = false; form.clearErrors()">Revenir au catalogue</button>
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
                    <Icon class="me-1.5 text-sm" name="plus" />Enregistrer le diagnostic
                </Button>
            </div>
        </form>

        <FormError :message="form.errors.diagnosis" />
    </div>
</template>
