import { ref } from 'vue';
import { ASSISTANT_BASE, firstValidationError, pageContextFrom, parseSseChunk, refusalMessage, takeReveal } from '@/utilities/assistant';

/**
 * ADR-222 — une conversation avec l'assistant : les messages, le flux de la réponse,
 * l'arrêt, « Réessayer », les questions proposées et l'historique. La réponse arrive
 * par morceaux (Server-Sent Events lus par `fetch`) ; un hébergement qui retient le
 * flux la livre d'un bloc, sans autre effet.
 *
 * Un seul état pour toute la visite : la bulle de l'assistant est remontée à chaque
 * page, la conversation en cours (et une réponse qui continuait d'arriver) reste.
 * Rien n'est gardé dans le stockage du navigateur (ni localStorage, ni
 * sessionStorage) : les conversations vivent sur le serveur, à chaque compte les siennes.
 */
const csrfToken = () => (typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content ?? '' : '');

const jsonHeaders = () => ({
    Accept: 'application/json',
    'X-CSRF-TOKEN': csrfToken(),
    'X-Requested-With': 'XMLHttpRequest',
});

const getJson = async (path) => {
    const response = await fetch(`${ASSISTANT_BASE}${path}`, { headers: jsonHeaders(), credentials: 'same-origin' });

    return response.ok ? response.json() : null;
};

function createAssistantChat() {
    /** @type {import('vue').Ref<import('@/utilities/assistant').AssistantMessage[]>} */
    const messages = ref([]);
    const conversationId = ref(null);
    const streaming = ref(false);
    const status = ref('');
    const notice = ref('');
    /** @type {import('vue').Ref<import('@/utilities/assistant').AssistantConversation[]>} */
    const history = ref([]);
    const historyLoading = ref(false);
    /** @type {import('vue').Ref<import('@/utilities/assistant').AssistantSuggestionGroup[]>} */
    const groups = ref([]);
    const groupsLoading = ref(false);
    const moduleTitle = ref(null);
    let controller = null;
    /** La page d'où la dernière question a été posée : « Réessayer » la reprend. */
    let lastPage = {};

    const lastAssistant = () => messages.value[messages.value.length - 1];

    /*
     * Le texte reçu s'affiche en coulant : les morceaux attendent ici et se dévoilent
     * quelques caractères par image (takeReveal). La réponse n'est dite terminée
     * (« Copier », questions de suivi) qu'une fois tout le texte à l'écran.
     */
    let pending = '';
    let target = null;
    let frame = null;
    /** La fin de la réponse (`done`), en attente que tout le texte soit dévoilé. */
    let completion = null;

    const nextFrame = (callback) => (typeof requestAnimationFrame === 'function' ? requestAnimationFrame(callback) : setTimeout(callback, 16));
    const cancelFrame = (handle) => (typeof cancelAnimationFrame === 'function' ? cancelAnimationFrame(handle) : clearTimeout(handle));

    const finish = () => {
        if (completion === null) return;

        const { message, event } = completion;
        completion = null;
        message.status = 'done';
        message.followUps = Array.isArray(event.follow_ups) ? event.follow_ups.slice(0, 3) : [];
    };

    const pump = () => {
        frame = null;
        if (target === null) {
            pending = '';

            return;
        }

        const [shown, rest] = takeReveal(pending);
        target.content += shown;
        pending = rest;

        if (pending !== '') frame = nextFrame(pump);
        else finish();
    };

    const reveal = (message, text) => {
        target = message;
        pending += text;
        frame ??= nextFrame(pump);
    };

    /** Tout le texte en attente d'un coup : une erreur, un arrêt, une nouvelle question. */
    const flushReveal = () => {
        if (frame !== null) cancelFrame(frame);
        frame = null;
        if (target !== null && pending !== '') target.content += pending;
        pending = '';
        finish();
    };

    /** Une autre conversation s'ouvre : ce qui attendait ne s'affiche nulle part. */
    const dropReveal = () => {
        if (frame !== null) cancelFrame(frame);
        frame = null;
        pending = '';
        target = null;
        completion = null;
    };

    const fail = (message) => {
        const last = lastAssistant();

        if (last && last.role === 'assistant' && last.status === 'streaming') {
            last.status = 'error';
            last.error = message;
        } else {
            messages.value.push({ role: 'assistant', content: '', status: 'error', error: message });
        }
    };

    const apply = (event) => {
        const last = lastAssistant();

        switch (event.type) {
            case 'meta':
                if (event.redacted) notice.value = 'Des données personnelles de votre question ont été masquées avant l’envoi.';
                if (typeof event.question === 'string') {
                    const question = [...messages.value].reverse().find((message) => message.role === 'user');
                    if (question) question.content = event.question;
                }
                break;
            case 'conversation':
                conversationId.value = event.id ?? conversationId.value;
                break;
            case 'status':
                status.value = event.text ?? '';
                break;
            case 'delta':
                status.value = '';
                if (last?.role === 'assistant' && event.text) reveal(last, event.text);
                break;
            case 'done':
                status.value = '';
                conversationId.value = event.conversation_id ?? conversationId.value;
                if (last?.role === 'assistant') {
                    completion = { message: last, event };
                    // Plus rien à dévoiler : terminée tout de suite ; sinon, quand le texte a coulé.
                    if (pending === '' && frame === null) finish();
                }
                break;
            case 'error':
                status.value = '';
                flushReveal();
                fail(event.message || 'L’assistant n’a pas pu répondre.');
                break;
            default:
                break;
        }
    };

    /** Poser une question ; `page` : l'adresse, l'écran et la section d'où l'on vient, rien d'autre. */
    const send = async (question, page = {}) => {
        const text = String(question ?? '').trim();

        if (text === '' || streaming.value) return;

        // La réponse précédente finit de couler d'un coup : la nouvelle ne s'y mélange jamais.
        flushReveal();
        lastPage = page ?? {};
        notice.value = '';
        messages.value.forEach((message) => { message.followUps = []; });
        messages.value.push({ role: 'user', content: text, status: 'done' });
        messages.value.push({ role: 'assistant', content: '', status: 'streaming' });
        streaming.value = true;
        status.value = '';
        controller = new AbortController();

        try {
            const response = await fetch(`${ASSISTANT_BASE}/messages`, {
                method: 'POST',
                headers: { ...jsonHeaders(), 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
                body: JSON.stringify({
                    message: text,
                    conversation_id: conversationId.value,
                    page: pageContextFrom(lastPage.url, lastPage.component, lastPage.hash),
                }),
            });

            const contentType = response.headers.get('content-type') ?? '';

            if (! response.ok || ! contentType.includes('text/event-stream')) {
                const json = contentType.includes('application/json') ? await response.json().catch(() => null) : null;
                fail(firstValidationError(json) ?? refusalMessage(response.status, json));

                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            for (;;) {
                const { value, done } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const parsed = parseSseChunk(buffer);
                buffer = parsed.rest;
                parsed.events.forEach(apply);
            }

            parseSseChunk(`${buffer}\n\n`).events.forEach(apply);

            // Terminée (`done`) : le texte finit de couler et la réponse se dit terminée
            // d'elle-même. Sinon, le flux s'est coupé sans fin annoncée.
            if (completion === null) {
                flushReveal();
                const last = lastAssistant();
                if (last?.status === 'streaming') {
                    last.status = last.content ? 'done' : 'error';
                    if (! last.content) last.error = 'La réponse s’est interrompue. Réessayez.';
                }
            }
        } catch (error) {
            flushReveal();
            const last = lastAssistant();

            if (error?.name === 'AbortError') {
                if (last?.status === 'streaming') last.status = 'stopped';
            } else {
                fail('Le serveur ne répond pas. Vérifiez la connexion, puis réessayez.');
            }
        } finally {
            streaming.value = false;
            status.value = '';
            controller = null;
        }
    };

    /**
     * Reposer la dernière question après un échec : la réponse en erreur et la
     * question qui l'a précédée sont retirées de l'écran, puis la question repart.
     */
    const retry = () => {
        if (streaming.value) return;

        const last = lastAssistant();
        const question = messages.value[messages.value.length - 2];

        if (last?.role !== 'assistant' || last.status !== 'error' || question?.role !== 'user') return;

        messages.value.splice(messages.value.length - 2, 2);
        send(question.content, lastPage);
    };

    /** Arrêter la génération : la réponse reste affichée telle qu'elle est arrivée. */
    const stop = () => controller?.abort();

    const reset = () => {
        stop();
        dropReveal();
        messages.value = [];
        conversationId.value = null;
        notice.value = '';
        status.value = '';
    };

    /** L'historique reçu avec la page : il s'affiche sans attendre. */
    const setHistory = (items) => {
        history.value = Array.isArray(items) ? items : [];
    };

    const loadHistory = async () => {
        historyLoading.value = true;

        try {
            history.value = (await getJson('/conversations'))?.conversations ?? [];
        } catch {
            history.value = [];
        } finally {
            historyLoading.value = false;
        }
    };

    const open = async (id) => {
        stop();
        dropReveal();

        const json = await getJson(`/conversations/${encodeURIComponent(id)}`).catch(() => null);

        if (! json) {
            reset();
            fail('Cette conversation n’est plus disponible.');

            return;
        }

        conversationId.value = json.id;
        notice.value = '';
        messages.value = (json.messages ?? []).map((message) => ({ role: message.role, content: message.content, status: 'done' }));
    };

    const remove = async (id) => {
        const response = await fetch(`${ASSISTANT_BASE}/conversations/${encodeURIComponent(id)}`, {
            method: 'DELETE',
            headers: jsonHeaders(),
            credentials: 'same-origin',
        });

        if (response.ok) {
            history.value = history.value.filter((item) => item.id !== id);
            if (conversationId.value === id) reset();
        }

        return response.ok;
    };

    /** Les questions proposées, groupées par module, pour la page d'où l'on vient. */
    const loadSuggestions = async (path) => {
        groupsLoading.value = true;

        try {
            const json = await getJson(`/suggestions?path=${encodeURIComponent(String(path ?? '').split(/[?#]/)[0])}`);
            groups.value = json?.groups ?? [];
            moduleTitle.value = json?.module ?? null;
        } catch {
            groups.value = [];
        } finally {
            groupsLoading.value = false;
        }
    };

    return {
        messages, conversationId, streaming, status, notice, history, historyLoading, groups, groupsLoading, moduleTitle,
        send, retry, stop, reset, setHistory, loadHistory, open, remove, loadSuggestions,
    };
}

let shared = null;
let owner = null;

/**
 * L'état de l'assistant, partagé par toute la visite (voir plus haut), et lié au
 * compte connecté : un autre compte sur le même poste, même sans rechargement de
 * la page, repart d'un état vide — jamais la conversation du précédent.
 *
 * @param {number|string|null} accountId
 */
export function useAssistantChat(accountId = null) {
    // Au rendu serveur, un état propre à chaque rendu : un module partagé le serait
    // entre toutes les requêtes, donc entre les comptes.
    if (typeof window === 'undefined') return createAssistantChat();

    if (shared === null || owner !== accountId) {
        shared?.stop();
        shared = createAssistantChat();
        owner = accountId;
    }

    return shared;
}
