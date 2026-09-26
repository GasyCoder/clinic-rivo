/**
 * ADR-194 — la messagerie : ce que l'écran calcule, écrit une fois et testé.
 *
 * Rien ici ne décide d'une règle : le serveur relit les adresses, nettoie le
 * corps et choisit les dossiers. Ces fonctions ne servent qu'à l'affichage et à
 * préparer ce qu'on envoie (citation d'une réponse, destinataires d'un « Répondre
 * à tous »).
 */

export const WEBMAIL_BASE = '/messagerie';

const EMAIL = /^[^\s@<>(),;:"[\]]+@[^\s@<>(),;:"[\]]+\.[^\s@<>(),;:"[\]]+$/;

/** Une adresse seule, sans nom : ce que le serveur acceptera. */
export function isEmail(value) {
    return EMAIL.test(String(value ?? '').trim());
}

/**
 * Les adresses d'un champ, comme le serveur les lit : séparées par une virgule,
 * un point-virgule ou un retour à la ligne ; « Nom <adresse> » accepté.
 *
 * @returns {{ valid: string[], invalid: string[] }}
 */
export function parseRecipients(value) {
    const valid = [];
    const invalid = [];

    for (const raw of String(value ?? '').split(/[,;\n]+/)) {
        const chunk = raw.trim();
        if (!chunk) continue;

        const inside = chunk.match(/<([^>]+)>\s*$/);
        const email = (inside ? inside[1] : chunk).trim().toLowerCase();

        if (isEmail(email)) {
            if (!valid.includes(email)) valid.push(email);
        } else {
            invalid.push(chunk);
        }
    }

    return { valid, invalid };
}

/** « Dr Vola » ou, à défaut de nom, l'adresse. */
export function senderLabel(party) {
    if (!party) return 'Expéditeur inconnu';

    return String(party.name ?? '').trim() || String(party.email ?? '').trim() || 'Expéditeur inconnu';
}

/** Deux lettres pour la pastille : initiales du nom, sinon de l'adresse. */
export function initialsOf(party) {
    const name = String(party?.name ?? '').trim();
    const source = name || String(party?.email ?? '').split('@')[0] || '?';
    const words = source.split(/[\s._-]+/).filter(Boolean);

    return (words.length > 1 ? words[0][0] + words[1][0] : source.slice(0, 2)).toUpperCase();
}

const TONES = ['bg-sky-100 text-sky-700', 'bg-emerald-100 text-emerald-700', 'bg-amber-100 text-amber-700', 'bg-violet-100 text-violet-700', 'bg-rose-100 text-rose-700', 'bg-teal-100 text-teal-700', 'bg-indigo-100 text-indigo-700'];

/** Une couleur de pastille stable par adresse : la même personne garde la même. */
export function avatarTone(email) {
    let hash = 0;
    for (const character of String(email ?? '')) hash = (hash * 31 + character.charCodeAt(0)) % 997;

    return TONES[hash % TONES.length];
}

const pad = (number) => String(number).padStart(2, '0');
const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

/**
 * La date d'un message dans la liste : l'heure aujourd'hui, « Hier », le jour
 * et le mois cette année, la date complète avant.
 */
export function formatListDate(iso, now = new Date()) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';

    const sameDay = (left, right) => left.getFullYear() === right.getFullYear()
        && left.getMonth() === right.getMonth()
        && left.getDate() === right.getDate();
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (sameDay(date, now)) return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
    if (sameDay(date, yesterday)) return 'Hier';
    if (date.getFullYear() === now.getFullYear()) return `${date.getDate()} ${MONTHS[date.getMonth()]}`;

    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
}

/** La date complète d'un message ouvert : « 25/09/2026 à 14:32 ». */
export function formatFullDate(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';

    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} à ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** 1 536 000 → « 1,5 Mo ». */
export function formatSize(bytes) {
    const value = Number(bytes ?? 0);
    if (value < 1024) return `${value} o`;
    if (value < 1024 * 1024) return `${Math.round(value / 1024)} Ko`;
    if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1).replace('.', ',')} Mo`;

    return `${(value / (1024 * 1024 * 1024)).toFixed(1).replace('.', ',')} Go`;
}

/** La part utilisée de la boîte, en pourcentage entier ; `null` si l'hébergeur ne la dit pas. */
export function quotaPercent(quota) {
    if (!quota?.limit) return null;

    return Math.min(100, Math.round((Number(quota.used ?? 0) / Number(quota.limit)) * 100));
}

/** « Re: » une seule fois, quelle que soit la langue du message d'origine. */
export function replySubject(subject) {
    const clean = String(subject ?? '').trim();

    return /^(re|ré)\s*:/i.test(clean) ? clean : `Re: ${clean}`.trim();
}

/** « Tr: » une seule fois. */
export function forwardSubject(subject) {
    const clean = String(subject ?? '').trim();

    return /^(tr|fwd?)\s*:/i.test(clean) ? clean : `Tr: ${clean}`.trim();
}

export const formatParty = (party) => (party?.name ? `${party.name} <${party.email}>` : party?.email ?? '');

/**
 * Les destinataires d'une réponse. « Répondre » : l'adresse de réponse, sinon
 * l'expéditeur. « Répondre à tous » ajoute en copie les autres destinataires et
 * copies, sans jamais s'écrire à soi-même.
 *
 * @returns {{ to: string, cc: string }}
 */
export function replyRecipients(message, ownAddress, all = false) {
    const own = String(ownAddress ?? '').toLowerCase();
    const primary = (message?.reply_to?.length ? message.reply_to : [message?.from]).filter(Boolean);
    const seen = new Set([own]);
    const pick = (parties) => parties.filter((party) => {
        const email = String(party?.email ?? '').toLowerCase();
        if (!email || seen.has(email)) return false;
        seen.add(email);

        return true;
    });

    const to = pick(primary);
    const cc = all ? pick([...(message?.to ?? []), ...(message?.cc ?? [])]) : [];

    return { to: to.map(formatParty).join(', '), cc: cc.map(formatParty).join(', ') };
}

export const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');

const paragraphs = (text) => String(text ?? '')
    .split(/\r?\n/)
    .map((line) => (line.trim() === '' ? '<p></p>' : `<p>${escapeHtml(line)}</p>`))
    .join('');

/**
 * Le corps d'une réponse : une ligne vide pour écrire, puis le message d'origine
 * cité. Le texte d'origine est échappé : rien de ce qu'il contient ne devient du
 * HTML dans l'éditeur.
 */
export function quotedReply(message) {
    const header = `Le ${formatFullDate(message?.date)}, ${escapeHtml(senderLabel(message?.from))} a écrit :`;

    return `<p></p><p></p><p>${header}</p><blockquote>${paragraphs(message?.quote_text)}</blockquote>`;
}

/** Le corps d'un transfert : l'en-tête d'origine, puis son texte. */
export function forwardedBody(message) {
    const lines = [
        '---------- Message transféré ----------',
        `De : ${formatParty(message?.from)}`,
        `Date : ${formatFullDate(message?.date)}`,
        `Objet : ${message?.subject ?? ''}`,
        `À : ${(message?.to ?? []).map(formatParty).join(', ')}`,
    ];

    return `<p></p><p></p>${lines.map((line) => `<p>${escapeHtml(line)}</p>`).join('')}<p></p>${paragraphs(message?.quote_text)}`;
}

/** L'adresse d'un dossier, avec ses filtres ; les valeurs vides n'y figurent pas. */
export function folderUrl(folder, { q = null, filter = null, label = null, page = 1 } = {}) {
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (filter) params.set('filtre', filter);
    if (label) params.set('libelle', label);
    if (page > 1) params.set('page', String(page));
    const query = params.toString();

    return `${WEBMAIL_BASE}/dossier/${encodeURIComponent(folder)}${query ? `?${query}` : ''}`;
}

/** L'adresse d'un message ; `images` affiche ses images distantes. */
export function messageUrl(folder, uid, { images = false } = {}) {
    return `${WEBMAIL_BASE}/dossier/${encodeURIComponent(folder)}/${uid}${images ? '?images=1' : ''}`;
}

/**
 * Les actions groupées qu'un dossier propose. La suppression définitive n'existe
 * que dans la corbeille et les indésirables — ailleurs, un message va d'abord à
 * la corbeille. « Remettre en boîte de réception » n'a de sens qu'en dehors d'elle.
 */
export function folderActions(folderRole) {
    const actions = ['read', 'unread', 'star', 'unstar'];

    if (folderRole !== 'archive') actions.push('archive');
    if (folderRole !== 'spam' && folderRole !== 'drafts') actions.push('spam');
    if (['archive', 'spam', 'trash'].includes(folderRole)) actions.push('inbox');
    if (folderRole === 'trash' || folderRole === 'spam') actions.push('delete');
    else actions.push('trash');

    return actions;
}

/** Les couleurs des libellés : un ton clair et sa pastille. */
export const LABEL_COLORS = {
    blue: { dot: 'bg-sky-500', soft: 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-200', name: 'Bleu' },
    green: { dot: 'bg-emerald-500', soft: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200', name: 'Vert' },
    red: { dot: 'bg-rose-500', soft: 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-200', name: 'Rouge' },
    amber: { dot: 'bg-amber-500', soft: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200', name: 'Ambre' },
    violet: { dot: 'bg-violet-500', soft: 'bg-violet-100 text-violet-800 dark:bg-violet-950/60 dark:text-violet-200', name: 'Violet' },
    slate: { dot: 'bg-slate-500', soft: 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200', name: 'Gris' },
};

export const labelColor = (color) => LABEL_COLORS[color] ?? LABEL_COLORS.slate;

/**
 * Le document affiché dans le cadre isolé : le corps déjà nettoyé par le serveur,
 * une politique de contenu qui interdit tout script et toute ressource hors des
 * images, et une feuille de style de lecture.
 */
export function frameDocument(bodyHtml, { allowRemote = false, printHeader = null } = {}) {
    const images = allowRemote ? 'data: https: http:' : 'data:';
    const policy = `default-src 'none'; img-src ${images}; style-src 'unsafe-inline'; font-src data:; form-action 'none'; base-uri 'none'`;

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        + `<meta http-equiv="Content-Security-Policy" content="${policy}">`
        + '<meta name="referrer" content="no-referrer">'
        + '<style>html,body{margin:0;padding:0;background:#fff;color:#1f2937;}'
        + 'body{font:14px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;overflow-wrap:anywhere;padding:4px 2px;}'
        + 'img{max-width:100%;height:auto;}table{max-width:100%;}a{color:#2563eb;}'
        + 'blockquote{margin:8px 0;padding-left:12px;border-left:3px solid #d1d5db;color:#4b5563;}'
        + 'pre{white-space:pre-wrap;}'
        + '.rivo-print{display:none;}@media print{.rivo-print{display:block;margin:0 0 16px;padding-bottom:12px;border-bottom:1px solid #d1d5db;font-size:12px;color:#374151;}.rivo-print h1{font-size:18px;margin:0 0 6px;color:#111827;}}'
        + '</style></head><body>'
        + (printHeader ? `<header class="rivo-print">${printHeader}</header>` : '')
        + String(bodyHtml ?? '')
        + '</body></html>';
}

const fold = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

/**
 * ADR-194 — les boîtes qu'on peut ouvrir, filtrées par ce qu'on tape : nom,
 * adresse, fonction ou site, sans accents ni majuscules, tous les mots.
 *
 * @param {Array<{owner: string, address: string, job?: string|null, site_name?: string|null}>} boxes
 */
export function filterBoxes(boxes, query) {
    const words = fold(query).split(/\s+/).filter(Boolean);
    if (!words.length) return [...(boxes ?? [])];

    return (boxes ?? []).filter((box) => {
        const haystack = fold([box.owner, box.address, box.job, box.site_name].filter(Boolean).join(' '));

        return words.every((word) => haystack.includes(word));
    });
}

/**
 * Les boîtes rangées par site, dans l'ordre où elles arrivent : sur le portail,
 * chaque site est un groupe ; sur un site, un seul groupe sans titre.
 *
 * @returns {Array<{site: string|null, boxes: Array<object>}>}
 */
export function groupBoxesBySite(boxes, bySite = true) {
    if (!bySite) return (boxes ?? []).length ? [{ site: null, boxes: [...boxes] }] : [];

    const groups = new Map();
    for (const box of boxes ?? []) {
        const site = box.site_name || box.site_code || 'Site';
        if (!groups.has(site)) groups.set(site, []);
        groups.get(site).push(box);
    }

    return [...groups].map(([site, list]) => ({ site, boxes: list }));
}

/** Les actions qui changent un message sans le déplacer : appliquées à l'écran tout de suite. */
export const INSTANT_ACTIONS = ['read', 'unread', 'star', 'unstar', 'label', 'unlabel'];

/** Les actions qui sortent un message de la liste. */
export const REMOVING_ACTIONS = ['archive', 'spam', 'inbox', 'trash', 'delete', 'move'];

const messageKey = (message) => `${message.folder}:${message.uid}`;

/**
 * ADR-194 — ce qu'une action change à l'écran, sans attendre le serveur (à ~260 ms
 * aller-retour, il confirme en arrière-plan). Seuls les accessoires modifiés sont
 * rendus ; si le serveur refuse, Inertia rétablit les précédents.
 *
 * @param {{ list?: object|null, message?: object|null, folders?: Array<object>, labels?: Array<object> }} props
 * @param {{ action: string, items: Array<{folder: string, uid: number}>, label?: string }} request
 * @returns {object} les accessoires à remplacer
 */
export function applyActionLocally(props, request) {
    const { action, items = [] } = request;
    const targets = new Set(items.map(messageKey));
    const keyword = request.label ? props.labels?.find((label) => label.uuid === request.label)?.keyword ?? null : null;
    const unseenDelta = {};
    const totalDelta = {};
    // Les compteurs des filtres de la liste (tous, non lus, favoris) suivent aussi.
    const countsDelta = { all: 0, unseen: 0, flagged: 0 };
    const note = (bucket, folder, amount) => { bucket[folder] = (bucket[folder] ?? 0) + amount; };

    const change = (message) => {
        switch (action) {
            case 'read': return { ...message, seen: true };
            case 'unread': return { ...message, seen: false };
            case 'star': return { ...message, flagged: true };
            case 'unstar': return { ...message, flagged: false };
            case 'label': return keyword ? { ...message, keywords: [...new Set([...(message.keywords ?? []), keyword])] } : message;
            case 'unlabel': return keyword ? { ...message, keywords: (message.keywords ?? []).filter((candidate) => candidate !== keyword) } : message;
            default: return message;
        }
    };
    const touch = (message) => {
        if (!targets.has(messageKey(message))) return message;
        const next = change(message);
        if (Boolean(message.seen) !== Boolean(next.seen)) {
            note(unseenDelta, message.folder, next.seen ? -1 : 1);
            countsDelta.unseen += next.seen ? -1 : 1;
        }
        if (Boolean(message.flagged) !== Boolean(next.flagged)) countsDelta.flagged += next.flagged ? 1 : -1;
        return next;
    };

    const result = {};

    if (props.list) {
        if (REMOVING_ACTIONS.includes(action)) {
            const kept = props.list.items.filter((message) => {
                if (!targets.has(messageKey(message))) return true;
                note(totalDelta, message.folder, -1);
                countsDelta.all -= 1;
                if (!message.seen) {
                    note(unseenDelta, message.folder, -1);
                    countsDelta.unseen -= 1;
                }
                if (message.flagged) countsDelta.flagged -= 1;
                return false;
            });
            const removed = props.list.items.length - kept.length;
            result.list = { ...props.list, items: kept, total: Math.max(0, (props.list.total ?? 0) - removed) };
        } else if (INSTANT_ACTIONS.includes(action)) {
            result.list = { ...props.list, items: props.list.items.map(touch) };
        }

        if (result.list && props.list.counts) {
            const counts = props.list.counts;
            result.list.counts = {
                all: Math.max(0, (counts.all ?? 0) + countsDelta.all),
                unseen: Math.max(0, (counts.unseen ?? 0) + countsDelta.unseen),
                flagged: Math.max(0, (counts.flagged ?? 0) + countsDelta.flagged),
            };
        }
    }

    if (props.message && INSTANT_ACTIONS.includes(action)) {
        result.message = touch(props.message);
    }

    if (props.folders && (Object.keys(unseenDelta).length || Object.keys(totalDelta).length)) {
        result.folders = props.folders.map((folder) => ((folder.key in unseenDelta) || (folder.key in totalDelta)
            ? {
                ...folder,
                unseen: Math.max(0, (folder.unseen ?? 0) + (unseenDelta[folder.key] ?? 0)),
                total: Math.max(0, (folder.total ?? 0) + (totalDelta[folder.key] ?? 0)),
            }
            : folder));
    }

    return result;
}

/**
 * ADR-194 — ce qui ne change pas d'un dossier ou d'un message à l'autre : la boîte,
 * ses libellés, ses modèles, les collègues, les limites et l'espace utilisé. Une
 * navigation dans la messagerie ne les redemande pas au serveur.
 */
export const WEBMAIL_STATIC_PROPS = ['mailbox', 'labels', 'labelColors', 'templates', 'contacts', 'limits', 'quota'];

/** L'étiquette des pages de la messagerie gardées d'avance (préchargement au survol). */
export const WEBMAIL_CACHE_TAG = 'webmail';

/**
 * Les options d'une navigation dans la messagerie : la page reste en place — la
 * colonne des dossiers ne disparaît pas derrière un squelette pleine page —, et
 * seules les données qui changent sont relues.
 */
export const WEBMAIL_NAVIGATION = Object.freeze({ preserveState: true, except: WEBMAIL_STATIC_PROPS });

/** Le dossier et, s'il y en a un, le message d'une adresse de la messagerie. */
export function parseWebmailPath(pathname) {
    const match = /^\/messagerie\/dossier\/([^/?#]+)(?:\/(\d+))?\/?$/.exec(String(pathname ?? ''));
    if (!match) return null;

    return { folder: decodeURIComponent(match[1]), uid: match[2] ? Number(match[2]) : null };
}

/**
 * Le chemin parcouru de la liste vers un message : revenir à cette liste se fait
 * alors par l'historique du navigateur — la page réapparaît aussitôt, sans attendre
 * le serveur — puis elle est relue en arrière-plan.
 */
const trail = { list: null, message: null };

export function rememberOpening(listUrl, messageUrlOpened) {
    trail.list = listUrl;
    trail.message = messageUrlOpened;
}

/** Un message remplacé par son voisin (flèches) : le retour mène toujours à la même liste. */
export function followOpening(messageUrlOpened) {
    if (trail.list) trail.message = messageUrlOpened;
}

export function forgetOpening() {
    trail.list = null;
    trail.message = null;
}

/** Le message affiché a-t-il été ouvert depuis une liste de ce dossier, juste derrière lui dans l'historique ? */
export function canReturnByHistory(currentUrl, folderKey) {
    if (!trail.list || trail.message !== currentUrl) return false;

    return parseWebmailPath(trail.list.split('?')[0])?.folder === folderKey;
}

/**
 * Un formulaire en FormData, comme Inertia l'envoie : les fichiers et les tableaux
 * en `nom[0]`, les objets en `nom[clé]` ; un champ vide ou nul n'est pas envoyé.
 */
export function toFormData(data, form = new FormData(), prefix = '') {
    for (const [key, value] of Object.entries(data ?? {})) {
        const name = prefix ? `${prefix}[${key}]` : key;

        if (value === null || value === undefined || value === '') continue;
        if (value instanceof Blob) form.append(name, value);
        else if (Array.isArray(value)) value.forEach((item, index) => toFormData({ [index]: item }, form, name));
        else if (typeof value === 'object') toFormData(value, form, name);
        else form.append(name, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    }

    return form;
}

/**
 * ADR-194 — ce que la page recharge après un envoi : l'avis seulement — un
 * rechargement partiel qui ne touche pas au serveur de messagerie —, sauf dans
 * Envoyés ou Brouillons, dont la liste change. Un brouillon change le compteur des
 * brouillons.
 */
export function reloadAfterSending(currentFolder, draft = false) {
    if (['envoyes', 'brouillons'].includes(currentFolder)) return ['list', 'folders', 'flash', 'errors'];

    return draft ? ['folders', 'flash', 'errors'] : ['flash', 'errors'];
}
