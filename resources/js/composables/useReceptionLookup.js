import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * ADR-211 — une recherche de l'accueil dans un référentiel (le Personnel, les
 * partenaires) : lancée d'elle-même après une courte pause, ou tout de suite par
 * Entrée. Une recherche plus récente annule la précédente, pour qu'une réponse
 * lente n'écrase jamais la bonne.
 */
export function useReceptionLookup(endpoint, { delay = 350 } = {}) {
    const query = ref('');
    const results = ref([]);
    const loading = ref(false);
    const performed = ref(false);
    const error = ref('');

    let controller = null;
    let timer = null;

    const search = async () => {
        clearTimeout(timer);
        const term = query.value.trim();

        if (term.length < 2) return;

        controller?.abort();
        const current = new AbortController();
        controller = current;
        loading.value = true;
        error.value = '';

        try {
            const response = await fetch(`${endpoint}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: current.signal,
            });
            const payload = await response.json().catch(() => ({}));

            if (controller !== current) return;
            if (! response.ok) throw new Error(payload.message || 'La recherche n’a pas abouti.');

            results.value = payload.data ?? [];
            performed.value = true;
        } catch (exception) {
            if (exception.name === 'AbortError') return;
            results.value = [];
            performed.value = true;
            error.value = exception.message;
        } finally {
            // Une recherche annulée ne dit pas « terminé » à la place de celle qui l'a remplacée.
            if (controller === current) loading.value = false;
        }
    };

    watch(query, (value) => {
        performed.value = false;
        clearTimeout(timer);
        if (value.trim().length >= 2) timer = setTimeout(search, delay);
    });

    const reset = () => {
        clearTimeout(timer);
        controller?.abort();
        query.value = '';
        results.value = [];
        performed.value = false;
        loading.value = false;
        error.value = '';
    };

    onBeforeUnmount(() => {
        clearTimeout(timer);
        controller?.abort();
    });

    return { query, results, loading, performed, error, search, reset };
}
