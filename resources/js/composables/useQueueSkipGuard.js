import { computed, ref } from 'vue';

/**
 * Sauter un patient de la file : on demande, on n'interdit pas.
 *
 * Prendre le n° 2 avant le n° 1 est parfois la bonne décision — le premier
 * est aux toilettes, son dossier n'est pas remonté, il n'est pas revenu d'un
 * autre service. Aucune règle du CDC n'impose l'ordre d'arrivée, et le serveur
 * ne bloque donc rien : ce garde-fou est ergonomique, il évite l'oubli, pas la
 * décision. Médecine et Soins s'en servent de la même façon.
 *
 * @param {object} options
 * @param {() => Array} options.rows          La page affichée, dans l'ordre de la file.
 * @param {() => number} [options.currentPage] Page courante : au-delà de la première, des patients arrivés avant ne sont pas affichés.
 * @param {(orientation: object) => void} options.accept Ce qu'on fait une fois la décision prise.
 */
export function useQueueSkipGuard({ rows, currentPage = () => 1, accept }) {
    const pending = ref(null);

    /** Les patients encore à prendre en charge placés avant celui-ci (un même patient ne se double pas lui-même). */
    const pendingAhead = (orientation) => {
        const list = rows();
        const index = list.findIndex((row) => row.uuid === orientation.uuid);
        const patient = orientation.episode?.patient?.uuid;

        return index <= 0
            ? []
            : list.slice(0, index).filter((row) => row.status === 'PENDING' && row.episode?.patient?.uuid !== patient);
    };

    const request = (orientation) => {
        const ahead = pendingAhead(orientation);
        // Une page précédente contient forcément des patients arrivés avant,
        // que cette page n'affiche pas : on ne peut pas prétendre le contraire.
        const earlierPages = (currentPage() ?? 1) > 1;

        if (ahead.length === 0 && !earlierPages) {
            accept(orientation);

            return;
        }

        pending.value = { orientation, ahead, earlierPages };
    };

    const confirm = () => {
        const target = pending.value?.orientation;
        pending.value = null;

        if (target) accept(target);
    };

    const cancel = () => {
        pending.value = null;
    };

    const isEmergency = (orientation) => orientation.episode?.priority === 'EMERGENCY';
    // Passer devant une urgence n'est pas passer devant une attente ordinaire :
    // le message change de ton pour que la différence se voie.
    const skippedEmergencies = computed(() => (pending.value?.ahead ?? []).filter(isEmergency));

    return { pending, request, confirm, cancel, pendingAhead, skippedEmergencies };
}
