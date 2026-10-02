<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@/Components/Shadcn/Button.vue';
import Select from '@/Components/Shadcn/Select.vue';
import { Check, Copy, Download, FileSpreadsheet, Sparkles, TriangleAlert } from 'lucide-vue-next';
import { copyText, jsonRequest } from '@/utilities/jsonRequest';

/**
 * ADR-241 — « Générer le prompt » : la structure exacte du catalogue affiché,
 * traduite en un prompt qu'une autre IA suit pour recréer son formulaire.
 * Le site lit le fichier et compose le prompt ; l'écran le montre et ne copie
 * que lui.
 */
const props = defineProps({
    catalogs: { type: Array, default: () => [] },
    /** (catalog) => adresse JSON de la structure et du prompt */
    promptUrl: { type: Function, required: true },
    /** (catalog) => adresse du fichier, à joindre au prompt */
    downloadUrl: { type: Function, default: null },
});

// Le catalogue actif d'abord : c'est celui que le fournisseur fait foi aujourd'hui.
const usable = computed(() => [...props.catalogs.filter((catalog) => !catalog.archived)]
    .sort((a, b) => Number(b.is_active) - Number(a.is_active)));
const options = computed(() => usable.value.map((catalog) => ({
    value: catalog.uuid,
    label: `${catalog.original_name}${catalog.is_active ? ' · actif' : ''}${catalog.kind === 'PDF' ? ' · PDF' : ''}`,
})));

const chosen = ref(usable.value[0]?.uuid ?? '');
watch(usable, (list) => {
    if (!list.some((catalog) => catalog.uuid === chosen.value)) chosen.value = list[0]?.uuid ?? '';
});

const loading = ref(false);
const error = ref('');
const prompt = ref('');
const structure = ref(null);
const copied = ref(false);

const generate = async () => {
    const catalog = usable.value.find((item) => item.uuid === chosen.value);

    if (!catalog) return;

    loading.value = true;
    error.value = '';
    copied.value = false;
    const result = await jsonRequest(props.promptUrl(catalog));
    loading.value = false;

    if (!result.ok) {
        error.value = result.message;
        prompt.value = '';
        structure.value = null;

        return;
    }

    prompt.value = result.data?.prompt ?? '';
    structure.value = result.data?.structure ?? null;
};

const copy = async () => {
    copied.value = await copyText(prompt.value);
    if (copied.value) setTimeout(() => { copied.value = false; }, 2500);
};

const chosenCatalog = computed(() => usable.value.find((catalog) => catalog.uuid === chosen.value) ?? null);
const fileHref = computed(() => (props.downloadUrl && chosenCatalog.value ? props.downloadUrl(chosenCatalog.value) : null));

const summary = computed(() => {
    if (!structure.value?.readable) return null;
    const sheets = structure.value.sheets ?? [];

    return {
        sheets: sheets.length,
        columns: sheets.reduce((sum, sheet) => sum + (sheet.columns?.length ?? 0), 0),
        rows: sheets.reduce((sum, sheet) => sum + (sheet.data_rows ?? 0), 0),
        sections: sheets.reduce((sum, sheet) => sum + (sheet.sections?.length ?? 0), 0),
    };
});
</script>

<template>
    <section class="rounded-xl border border-border bg-card shadow-sm">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 font-heading text-base font-bold text-foreground"><Sparkles class="h-4.5 w-4.5 text-primary" aria-hidden="true" />Prompt du catalogue</h2>
                <p class="mt-0.5 text-sm text-muted-foreground">Un prompt qui demande à une autre IA un canevas Excel reproduisant exactement ce catalogue. Joignez-lui le fichier du fournisseur : c’est lui qui fait foi.</p>
            </div>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <Select v-if="options.length > 1" v-model="chosen" :icon="FileSpreadsheet" :options="options" class="w-full sm:w-72" aria-label="Catalogue" />
                <Button type="button" :disabled="!chosen || loading" @click="generate">
                    <Sparkles class="h-4 w-4" />{{ loading ? 'Lecture du fichier…' : 'Générer le prompt' }}
                </Button>
            </div>
        </header>

        <p v-if="!usable.length" class="px-5 py-4 text-sm text-muted-foreground">Ajoutez un catalogue pour en générer le prompt.</p>

        <p v-if="error" class="mx-5 my-4 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive" role="alert">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />{{ error }}
        </p>

        <div v-if="prompt" class="space-y-3 px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p v-if="summary" class="text-xs text-muted-foreground">
                    {{ summary.sheets }} feuille{{ summary.sheets > 1 ? 's' : '' }} · {{ summary.columns }} colonne{{ summary.columns > 1 ? 's' : '' }} ·
                    {{ summary.rows }} ligne{{ summary.rows > 1 ? 's' : '' }}<span v-if="summary.sections"> · {{ summary.sections }} rubrique{{ summary.sections > 1 ? 's' : '' }}</span>
                </p>
                <p v-else class="text-xs text-muted-foreground">{{ structure?.reason }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="fileHref" as="a" :href="fileHref" variant="ghost" size="sm" title="Le fichier à joindre au prompt">
                        <Download class="h-4 w-4" />Fichier à joindre
                    </Button>
                    <Button type="button" variant="outline" size="sm" aria-live="polite" @click="copy">
                        <component :is="copied ? Check : Copy" class="h-4 w-4" />{{ copied ? 'Copié' : 'Copier' }}
                    </Button>
                </div>
            </div>
            <pre
                class="max-h-[28rem] overflow-auto whitespace-pre-wrap rounded-lg border border-border bg-muted/40 p-4 font-mono text-xs leading-relaxed text-foreground"
                aria-label="Prompt généré"
                tabindex="0"
            >{{ prompt }}</pre>
        </div>
    </section>
</template>
