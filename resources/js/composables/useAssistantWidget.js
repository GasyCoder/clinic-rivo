import { ref } from 'vue';
import { DEFAULT_MODE, readPreferences, writePreferences } from '@/utilities/assistantWidget';

/**
 * ADR-222 — l'état de la bulle de l'assistant pour toute la visite : ouverte ou
 * non, sa taille, sa place, la question en cours de saisie. Chaque page monte sa
 * propre mise en page ; cet état, lui, reste : on change d'écran, la fenêtre et la
 * question commencée sont toujours là.
 *
 * Le poste retient seulement la place de la bulle, la taille préférée et la place
 * de la fenêtre (utilities/assistantWidget.js) — jamais une question ni une réponse.
 */
const browserStorage = () => {
    try {
        return window.localStorage ?? null;
    } catch {
        return null;
    }
};

function createWidget(storage) {
    const preferences = readPreferences(storage);

    const open = ref(false);
    /** @type {import('vue').Ref<'compact'|'large'|'full'>} */
    const mode = ref(preferences.mode);
    /** La taille à retrouver en quittant le plein écran. */
    const restoreMode = ref(preferences.mode === 'full' ? DEFAULT_MODE : preferences.mode);
    const anchor = ref(preferences.anchor);
    /** La place où l'on a déplacé la fenêtre ; `null` : à côté de la bulle. */
    const moved = ref(preferences.moved);
    /** `chat` ou `history` (dans la petite et la grande fenêtre). */
    const view = ref('chat');
    /** Une réponse est arrivée pendant que la fenêtre était réduite. */
    const unread = ref(false);
    const draft = ref('');
    /** La page pour laquelle les questions proposées ont été chargées. */
    const suggestionsPath = ref(null);
    /** L'historique a été lu une fois depuis l'ouverture de la visite. */
    const historyLoaded = ref(false);

    const persist = () => writePreferences(storage, { anchor: anchor.value, mode: mode.value, moved: moved.value });

    const setMode = (value) => {
        if (value !== 'full') restoreMode.value = value;
        mode.value = value;
        persist();
    };

    const toggleFull = () => setMode(mode.value === 'full' ? restoreMode.value : 'full');

    const setAnchor = (value) => {
        anchor.value = value;
        // La bulle a bougé : la fenêtre se rouvre à côté d'elle.
        moved.value = null;
        persist();
    };

    const setMoved = (value) => {
        moved.value = value;
        persist();
    };

    const show = () => {
        open.value = true;
        unread.value = false;
    };

    const hide = () => {
        open.value = false;
        view.value = 'chat';
    };

    return {
        open, mode, restoreMode, anchor, moved, view, unread, draft, suggestionsPath, historyLoaded,
        setMode, toggleFull, setAnchor, setMoved, show, hide,
    };
}

let shared = null;
let owner = null;

/**
 * Lié au compte connecté, comme la conversation (useAssistantChat) : un autre compte
 * sur le même poste retrouve la bulle à sa place, mais jamais la question commencée
 * par le précédent.
 *
 * @param {number|string|null} accountId
 */
export function useAssistantWidget(accountId = null) {
    // Au rendu serveur : un état propre à chaque rendu, sans stockage.
    if (typeof window === 'undefined') return createWidget(null);

    if (shared === null || owner !== accountId) {
        shared = createWidget(browserStorage());
        owner = accountId;
    }

    return shared;
}
