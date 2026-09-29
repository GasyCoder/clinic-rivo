/**
 * ADR-222 — l'assistant d'aide au logiciel : ce que l'écran calcule sans le serveur.
 * Des fonctions pures, testées par `node --test`.
 */

/**
 * @typedef {{ role: 'user'|'assistant', content: string, status?: 'streaming'|'done'|'error'|'stopped', error?: string }} AssistantMessage
 * @typedef {{ type: string, [key: string]: any }} AssistantEvent
 */

export const ASSISTANT_BASE = '/assistant';

/** L'endroit où l'on se trouve, sans rien de la page : adresse sans requête, écran, section. */
export const pageContextFrom = (url = '', component = '', hash = '') => {
    const path = String(url || '').split(/[?#]/)[0] || '/';
    const section = String(hash || '').replace(/^#/, '');

    return {
        path: path.slice(0, 255),
        component: /^[A-Za-z0-9/_-]{1,120}$/.test(String(component || '')) ? component : null,
        section: /^[A-Za-z0-9_-]{1,60}$/.test(section) ? section : null,
    };
};

/**
 * Découpe un flux Server-Sent Events : renvoie les événements complets et ce qui
 * reste en attente (un morceau coupé au milieu d'une ligne attend le suivant).
 *
 * @returns {{ events: AssistantEvent[], rest: string }}
 */
export const parseSseChunk = (buffer) => {
    const blocks = String(buffer).replace(/\r\n/g, '\n').split('\n\n');
    const rest = blocks.pop() ?? '';
    const events = [];

    for (const block of blocks) {
        const data = block
            .split('\n')
            .filter((line) => line.startsWith('data:'))
            .map((line) => line.slice(5).trimStart())
            .join('\n');

        if (data === '' || data === '[DONE]') continue;

        try {
            const event = JSON.parse(data);
            if (event && typeof event.type === 'string') events.push(event);
        } catch {
            // Une ligne illisible est ignorée : le flux continue.
        }
    }

    return { events, rest };
};

const escapeHtml = (text) => String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

/** Gras, italique et code dans une ligne déjà échappée. */
const inline = (escaped) => escaped
    .replace(/`([^`\n]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
    .replace(/__([^_\n]+)__/g, '<strong>$1</strong>')
    .replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,;:!?]|$)/g, '$1<em>$2</em>');

/**
 * Un Markdown réduit, sûr par construction : tout le texte est d'abord échappé,
 * puis seules les balises de cette fonction sont ajoutées (titres, paragraphes,
 * listes, gras, italique, code). Aucun lien, aucune image, aucun HTML du modèle :
 * une réponse ne peut ni exécuter un script ni envoyer le lecteur ailleurs.
 */
export const renderAssistantMarkdown = (markdown) => {
    const lines = escapeHtml(markdown ?? '').replace(/\r\n/g, '\n').split('\n');
    const html = [];
    let list = null;
    let paragraph = [];
    let code = null;

    const closeParagraph = () => {
        if (paragraph.length) html.push(`<p>${paragraph.map(inline).join('<br>')}</p>`);
        paragraph = [];
    };
    const closeList = () => {
        if (list) html.push(`<${list.tag}>${list.items.map((item) => `<li>${inline(item)}</li>`).join('')}</${list.tag}>`);
        list = null;
    };

    for (const line of lines) {
        if (code !== null) {
            if (/^\s*```/.test(line)) {
                html.push(`<pre><code>${code.join('\n')}</code></pre>`);
                code = null;
            } else {
                code.push(line);
            }
            continue;
        }

        if (/^\s*```/.test(line)) {
            closeParagraph();
            closeList();
            code = [];
            continue;
        }

        const heading = line.match(/^\s*(#{1,4})\s+(.+)$/);
        const bullet = line.match(/^\s*[-*•]\s+(.+)$/);
        const numbered = line.match(/^\s*\d+[.)]\s+(.+)$/);

        if (heading) {
            closeParagraph();
            closeList();
            html.push(`<h4>${inline(heading[2])}</h4>`);
        } else if (bullet || numbered) {
            closeParagraph();
            const tag = bullet ? 'ul' : 'ol';
            if (list && list.tag !== tag) closeList();
            list ??= { tag, items: [] };
            list.items.push((bullet ?? numbered)[1]);
        } else if (line.trim() === '') {
            closeParagraph();
            closeList();
        } else {
            closeList();
            paragraph.push(line.trim());
        }
    }

    if (code !== null) html.push(`<pre><code>${code.join('\n')}</code></pre>`);
    closeParagraph();
    closeList();

    return html.join('');
};

/** Ce que l'écran dit d'une question refusée avant tout appel (HTTP d'erreur ordinaire). */
export const refusalMessage = (status, json) => {
    if (json && typeof json.message === 'string' && json.message !== '') return json.message;
    if (status === 419) return 'Votre session a expiré : rechargez la page.';
    if (status === 429) return 'Trop de questions en peu de temps. Réessayez dans quelques minutes.';
    if (status === 403) return 'Vous n’avez pas le droit d’utiliser l’assistant (ai_assistant.use).';
    if (status === 422) return 'La question n’est pas valide.';

    return 'L’assistant n’a pas pu répondre. Réessayez dans quelques instants.';
};

/** Le premier message d'erreur d'une réponse 422 de Laravel. */
export const firstValidationError = (json) => {
    const errors = json?.errors ?? {};
    const first = Object.values(errors)[0];

    return Array.isArray(first) ? first[0] : (typeof first === 'string' ? first : null);
};

/* ------------------------------------------------------------------ */
/* Réglages (Super Admin)                                              */
/* ------------------------------------------------------------------ */

export const ASSISTANT_SETTING_FIELDS = Object.freeze([
    'enabled', 'provider', 'model', 'max_output_tokens', 'temperature', 'timeout_seconds',
    'rate_limit_per_hour', 'daily_limit_per_user', 'monthly_token_budget', 'instructions',
]);

/** Les valeurs du formulaire, depuis ce que la cible porte. Un nombre vide reste vide : la valeur par défaut s'applique. */
export const assistantFormValues = (payload) => {
    const values = payload?.values ?? {};

    return Object.fromEntries(ASSISTANT_SETTING_FIELDS.map((field) => {
        const value = values[field];

        if (field === 'enabled') return [field, Boolean(value)];
        if (value === null || value === undefined) return [field, ''];

        return [field, typeof value === 'number' ? String(value) : value];
    }));
};

/** Les modèles proposés par le SDK pour un fournisseur, plus celui qui est saisi s'il n'y figure pas. */
export const modelOptionsFor = (providers, provider, current = '') => {
    const entry = (providers ?? []).find((item) => item.value === provider);
    const options = [...(entry?.models ?? [])].map((option) => ({ value: option.value, label: `${option.value} — ${option.label}` }));

    if (current && ! options.some((option) => option.value === current)) {
        options.push({ value: current, label: `${current} — saisi à la main` });
    }

    return options;
};

/** La phrase qui dit d'où vient la clé : jamais la clé elle-même. */
export const keySourceLabel = (key) => {
    if (! key?.present) return 'Aucune clé';
    if (key.source === 'environment') return 'Clé du fichier .env du serveur';

    return 'Clé enregistrée, chiffrée';
};

/** « 12 345 », « 1,2 M » : les tokens se lisent d'un coup d'œil. */
export const formatTokens = (value) => {
    const number = Number(value ?? 0);

    if (! Number.isFinite(number)) return '0';
    if (number >= 1_000_000) return `${(number / 1_000_000).toFixed(1).replace('.', ',')} M`;

    return new Intl.NumberFormat('fr-FR').format(Math.round(number)).replace(/ | /g, ' ');
};
