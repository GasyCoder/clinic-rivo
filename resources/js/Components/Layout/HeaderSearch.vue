<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { CircleUser, FolderClock, Loader2, Search } from 'lucide-vue-next';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { cn } from '@/lib/cn';

/**
 * La recherche rapide de l'en-tête.
 *
 * Elle interroge le même `LIKE` sur les mêmes colonnes que le répertoire
 * `/patients` : deux écrans qui répondraient différemment à la même saisie
 * feraient douter de celui qu'on regarde. Le serveur filtre par permission ;
 * cet écran n'en décide rien.
 *
 * `Entrée` sans sélection ouvre le répertoire complet avec le terme : la
 * liste courte est un raccourci, jamais le seul chemin vers un résultat.
 */
const term = ref('');
const results = ref({ patients: [], episodes: [] });
const loading = ref(false);
const open = ref(false);
const highlighted = ref(-1);
const input = ref(null);

let controller = null;
let timer = null;

const rows = computed(() => [
    ...results.value.patients.map((row) => ({ ...row, kind: 'patient' })),
    ...results.value.episodes.map((row) => ({ ...row, kind: 'episode' })),
]);

const reset = () => {
    results.value = { patients: [], episodes: [] };
    highlighted.value = -1;
};

const fetchResults = async (value) => {
    // Une requête plus récente doit toujours l'emporter : sans cela, une
    // réponse lente à « ra » écraserait les résultats de « rako ».
    controller?.abort();
    controller = new AbortController();
    loading.value = true;

    try {
        const response = await fetch(`/recherche?q=${encodeURIComponent(value)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (!response.ok) {
            reset();

            return;
        }

        const payload = await response.json();
        results.value = { patients: payload.patients ?? [], episodes: payload.episodes ?? [] };
        highlighted.value = -1;
    } catch (error) {
        if (error.name !== 'AbortError') reset();
    } finally {
        loading.value = false;
    }
};

watch(term, (value) => {
    clearTimeout(timer);
    open.value = true;

    if (value.trim().length < 2) {
        reset();
        loading.value = false;

        return;
    }

    timer = setTimeout(() => fetchResults(value.trim()), 250);
});

const go = (row) => {
    open.value = false;
    term.value = '';
    reset();
    router.visit(row.url);
};

const submit = () => {
    if (highlighted.value >= 0 && rows.value[highlighted.value]) {
        go(rows.value[highlighted.value]);

        return;
    }

    if (term.value.trim() === '') return;

    open.value = false;
    router.visit(`/patients?q=${encodeURIComponent(term.value.trim())}`);
};

const move = (delta) => {
    if (rows.value.length === 0) return;

    highlighted.value = (highlighted.value + delta + rows.value.length) % rows.value.length;
};

/** Ctrl/⌘+K : le raccourci que tout le monde essaie déjà. */
const onShortcut = (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        input.value?.$el?.querySelector('input')?.focus();
    }
};

onMounted(() => window.addEventListener('keydown', onShortcut));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onShortcut);
    clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <div class="relative w-full max-w-md">
        <IconInput
            ref="input"
            v-model="term"
            :icon="Search"
            type="search"
            placeholder="Patient, n° dossier, téléphone ou passage…"
            autocomplete="off"
            aria-label="Rechercher un patient ou un passage"
            role="combobox"
            :aria-expanded="open && term.trim().length >= 2"
            @focus="open = true"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="submit"
            @keydown.esc="open = false"
        />

        <!-- Le raccourci est annoncé plutôt que caché : personne ne devine
             une combinaison de touches qui ne s'affiche nulle part. -->
        <kbd class="pointer-events-none absolute end-2.5 top-1/2 hidden -translate-y-1/2 rounded border border-border bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground lg:block">
            Ctrl K
        </kbd>

        <div
            v-if="open && term.trim().length >= 2"
            class="absolute inset-x-0 top-full z-[1200] mt-2 overflow-hidden rounded-xl border border-border bg-popover shadow-2xl"
        >
            <p v-if="loading" class="flex items-center gap-2 px-4 py-3 text-xs text-muted-foreground">
                <Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />Recherche…
            </p>

            <ul v-else-if="rows.length" class="max-h-80 divide-y divide-border overflow-y-auto" role="listbox">
                <li v-for="(row, index) in rows" :key="`${row.kind}-${row.uuid}`">
                    <button
                        type="button"
                        role="option"
                        :aria-selected="highlighted === index"
                        :class="cn('flex w-full items-center gap-3 px-4 py-2.5 text-start transition-colors',
                            highlighted === index ? 'bg-accent' : 'hover:bg-accent/60')"
                        @mouseenter="highlighted = index"
                        @click="go(row)"
                    >
                        <component
                            :is="row.kind === 'patient' ? CircleUser : FolderClock"
                            class="h-4 w-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-bold text-foreground">{{ row.label }}</span>
                            <span class="block truncate text-xs text-muted-foreground">{{ row.meta }}</span>
                        </span>
                        <span class="shrink-0 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                            {{ row.kind === 'patient' ? 'Dossier' : 'Passage' }}
                        </span>
                    </button>
                </li>
            </ul>

            <p v-else class="px-4 py-3 text-xs text-muted-foreground">
                Aucun résultat. <span class="font-semibold text-foreground">Entrée</span> ouvre le répertoire complet.
            </p>
        </div>
    </div>
</template>
