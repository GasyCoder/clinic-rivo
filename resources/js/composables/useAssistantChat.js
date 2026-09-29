import { ref } from 'vue';
import { ASSISTANT_BASE, firstValidationError, pageContextFrom, parseSseChunk, refusalMessage } from '@/utilities/assistant';

/**
 * ADR-222 — une conversation avec l'assistant : les messages, le flux de la réponse,
 * l'arrêt, l'historique. La réponse arrive par morceaux (Server-Sent Events lus par
 * `fetch`) ; un hébergement qui retient le flux la livre d'un bloc, sans autre effet.
 *
 * Rien n'est gardé dans le navigateur (ni localStorage, ni sessionStorage) : les
 * conversations vivent sur le serveur, à chaque compte les siennes.
 */
const csrfToken = () => (typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content ?? '' : '');

const jsonHeaders = () => ({
    Accept: 'application/json',
    'X-CSRF-TOKEN': csrfToken(),
    'X-Requested-With': 'XMLHttpRequest',
});

export function useAssistantChat() {
    /** @type {import('vue').Ref<import('@/utilities/assistant').AssistantMessage[]>} */
    const messages = ref([]);
    const conversationId = ref(null);
    const streaming = ref(false);
    const status = ref('');
    const notice = ref('');
    const history = ref([]);
    const historyLoading = ref(false);
    const suggestions = ref([]);
    const moduleTitle = ref(null);
    let controller = null;

    const lastAssistant = () => messages.value[messages.value.length - 1];

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
                if (last?.role === 'assistant') last.content += event.text ?? '';
                break;
            case 'done':
                status.value = '';
                conversationId.value = event.conversation_id ?? conversationId.value;
                if (last?.role === 'assistant') last.status = 'done';
                break;
            case 'error':
                status.value = '';
                fail(event.message || 'L’assistant n’a pas pu répondre.');
                break;
            default:
                break;
        }
    };

    /** Poser une question ; `page` : l'adresse, l'écran et la section, rien d'autre. */
    const send = async (question, page = {}) => {
        const text = String(question ?? '').trim();

        if (text === '' || streaming.value) return;

        notice.value = '';
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
                    page: pageContextFrom(page.url, page.component, page.hash),
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

            const last = lastAssistant();
            if (last?.status === 'streaming') {
                last.status = last.content ? 'done' : 'error';
                if (! last.content) last.error = 'La réponse s’est interrompue. Réessayez.';
            }
        } catch (error) {
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

    /** Arrêter la génération : la réponse reste affichée telle qu'elle est arrivée. */
    const stop = () => controller?.abort();

    const reset = () => {
        stop();
        messages.value = [];
        conversationId.value = null;
        notice.value = '';
        status.value = '';
    };

    const loadHistory = async () => {
        historyLoading.value = true;

        try {
            const response = await fetch(`${ASSISTANT_BASE}/conversations`, { headers: jsonHeaders(), credentials: 'same-origin' });
            const json = response.ok ? await response.json() : null;
            history.value = json?.conversations ?? [];
        } catch {
            history.value = [];
        } finally {
            historyLoading.value = false;
        }
    };

    const open = async (id) => {
        stop();

        const response = await fetch(`${ASSISTANT_BASE}/conversations/${encodeURIComponent(id)}`, { headers: jsonHeaders(), credentials: 'same-origin' });

        if (! response.ok) {
            reset();
            fail('Cette conversation n’est plus disponible.');

            return;
        }

        const json = await response.json();
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
    };

    const loadSuggestions = async (path) => {
        try {
            const response = await fetch(`${ASSISTANT_BASE}/suggestions?path=${encodeURIComponent(String(path ?? '').split(/[?#]/)[0])}`, {
                headers: jsonHeaders(),
                credentials: 'same-origin',
            });
            const json = response.ok ? await response.json() : null;
            suggestions.value = json?.suggestions ?? [];
            moduleTitle.value = json?.module ?? null;
        } catch {
            suggestions.value = [];
        }
    };

    return {
        messages, conversationId, streaming, status, notice, history, historyLoading, suggestions, moduleTitle,
        send, stop, reset, loadHistory, open, remove, loadSuggestions,
    };
}
