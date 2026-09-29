/**
 * ADR-213 — les règles d'écran de la paillasse, écrites une fois et testées.
 *
 * Le serveur reste juge : il revalide chaque ligne selon son mode, recalcule la
 * position par rapport à la référence et propose l'interprétation. Ce module
 * ne fait que la montrer pendant la saisie, avec les mêmes règles.
 */

const NUMBER = /^-?\d+(?:[.,]\d+)?$/;

export const LAB_STATUS_TONES = {
    PENDING: 'warning',
    IN_PROGRESS: 'info',
    COMPLETED: 'primary',
    VALIDATED: 'success',
    TO_REDO: 'danger',
};

export const LAB_VIEWS = [
    // ADR-217 — plus de vue « À réceptionner » : une demande non commencée est à traiter.
    { value: 'to_do', label: 'À traiter', hint: 'À commencer ou en cours', tone: 'primary' },
    { value: 'to_redo', label: 'À refaire', hint: 'Renvoyées avec un motif', tone: 'red' },
    // ADR-216 — les clés restent (liens, signets) ; le sens est l'envoi au médecin.
    { value: 'to_validate', label: 'Terminées', hint: 'À envoyer au médecin', tone: 'sky' },
    { value: 'validated', label: 'Envoyées', hint: 'Toutes envoyées au médecin', tone: 'emerald' },
    { value: 'all', label: 'Toutes', hint: 'Demandes non retirées', tone: 'neutral' },
    // ADR-220 — rangées par le laboratoire : elles ne sont dans aucune autre vue.
    { value: 'archived', label: 'Archivées', hint: 'Rangées par le laboratoire', tone: 'neutral' },
];

/** ADR-214 — l'état d'une demande dans la file, tel que la ligne le dit. */
export const LAB_STATE_LABELS = { to_do: 'À traiter', to_redo: 'À refaire', to_validate: 'Terminée · à envoyer', validated: 'Envoyée' };
export const LAB_STATE_TONES = { to_do: 'warning', to_redo: 'danger', to_validate: 'primary', validated: 'success' };

/**
 * ADR-217 — le bouton de la ligne dans la file, comme dans labo-vuejs : on
 * « Traite » une demande que personne n'a commencée, on « Continue » une demande
 * en cours. Sans le droit de commencer (portail), on ne fait que l'ouvrir.
 */
export const LAB_ROW_ACTIONS = {
    start: { label: 'Traiter' },
    continue: { label: 'Continuer' },
    redo: { label: 'Reprendre' },
    send: { label: 'Envoyer' },
    open: { label: 'Voir' },
};
export const rowAction = (request, canStart = true) => (request?.action === 'start' && !canStart ? 'open' : (request?.action ?? 'open'));

/**
 * ADR-219 — la carte d'une analyse dans « Tâche(s) à traiter », comme labo-vuejs :
 * une couleur et une icône par état, le libellé écrit à côté (jamais la couleur seule).
 */
export const LAB_TASK_STATES = {
    PENDING: { label: 'À faire', icon: 'pending', badge: 'neutral', card: 'border-border bg-muted/30', square: 'bg-muted-foreground/25 text-background', bar: 'bg-muted-foreground/40' },
    IN_PROGRESS: { label: 'En cours', icon: 'progress', badge: 'warning', card: 'border-orange-200 bg-orange-50/70 dark:border-orange-900/60 dark:bg-orange-950/20', square: 'bg-orange-500 text-white', bar: 'bg-orange-500' },
    COMPLETED: { label: 'À envoyer', icon: 'completed', badge: 'primary', card: 'border-sky-200 bg-sky-50/70 dark:border-sky-900/60 dark:bg-sky-950/20', square: 'bg-sky-600 text-white', bar: 'bg-sky-600' },
    VALIDATED: { label: 'Envoyée', icon: 'validated', badge: 'success', card: 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-900/60 dark:bg-emerald-950/20', square: 'bg-emerald-600 text-white', bar: 'bg-emerald-600' },
    TO_REDO: { label: 'À refaire', icon: 'redo', badge: 'danger', card: 'border-red-200 bg-red-50/70 dark:border-red-900/60 dark:bg-red-950/20', square: 'bg-red-600 text-white', bar: 'bg-red-600' },
};
export const labTaskState = (status) => LAB_TASK_STATES[status] ?? LAB_TASK_STATES.PENDING;

/** ADR-219 — le mode de saisie d'une ligne, en une pastille (« TEST », « CHAMP LIBRE » dans labo-vuejs). */
export const LAB_ENTRY_MODE_LABELS = {
    NUMERIC: 'Numérique',
    TEXT: 'Champ libre',
    CHOICE: 'Liste',
    MULTI_CHOICE: 'Choix multiple',
    NEG_POS: 'Nég. / Pos.',
    NEG_POS_VALUE: 'Nég. / Pos. + valeur',
    NEG_POS_CHOICE: 'Nég. / Pos. + précision',
    ABSENCE_PRESENCE: 'Absence / Présence',
    CULTURE: 'Culture',
    NUGENT: 'Score de Nugent',
    LABEL: 'Intitulé',
};

/** ADR-219 — un nom d'analyse est en gras seulement si le catalogue le dit (`is_bold`). */
export const designationWeight = (node) => (node?.is_bold ? 'font-bold' : 'font-normal');

/** Les deux lettres de l'analyse ouverte (« Hémostase » → « Hé », « Bilan lipidique » → « BL »). */
export const analysisInitials = (name) => {
    const words = String(name ?? '').trim().split(/[\s\-_/]+/).filter(Boolean);
    if (!words.length) return '?';
    if (words.length === 1) return (words[0].charAt(0).toUpperCase() + (words[0].charAt(1) ?? '').toLowerCase()).trim();

    return (words[0].charAt(0) + words[1].charAt(0)).toUpperCase();
};

/** ADR-219 — ce qu'une remise à zéro effacerait : sans rien, pas de bouton actif. */
export const hasEntries = (item) => (item?.nodes ?? []).some((node) => node.result || node.note);

/** L'avancement d'une analyse : ce qui attend un résultat, et ce qui en a un. */
export const itemProgress = (item) => {
    const inputs = (item?.nodes ?? []).filter((node) => node.takes_result);
    const done = inputs.filter((node) => node.result).length;

    return { done, total: inputs.length, ratio: inputs.length ? done / inputs.length : 0 };
};

export const numericValue = (value) => {
    const text = String(value ?? '').trim();

    return NUMBER.test(text) ? Number(text.replace(',', '.')) : null;
};

/** BAS, NORMAL ou HAUT — `null` quand la valeur n'est pas un nombre ou que la référence ne se lit pas. */
export const rangeFlag = (range, value) => {
    const number = numericValue(value);
    if (number === null || !range || (range.min === null && range.max === null)) {
        return null;
    }
    if (range.min !== null && number < range.min) return 'LOW';
    if (range.max !== null && number > range.max) return 'HIGH';

    return 'NORMAL';
};

/**
 * ADR-219 — ce que dit la valeur numérique saisie, sous le champ et par sa
 * bordure : critique, au-dessus, en dessous, dans la norme, ou pas un nombre.
 * Rien sans valeur ; rien d'affirmé sans norme lisible.
 */
export const numericHint = (range, value, critical = false) => {
    const text = String(value ?? '').trim();
    if (text === '') return null;
    if (numericValue(text) === null) {
        return { key: 'invalid', label: 'Saisissez un nombre (ex. 4,6)', text: 'text-destructive', border: 'border-destructive focus-visible:border-destructive' };
    }
    if (critical) {
        return { key: 'critical', label: 'Valeur critique : elle sera signalée à l’enregistrement', text: 'text-destructive', border: 'border-destructive bg-destructive/5 focus-visible:border-destructive' };
    }
    const flag = rangeFlag(range, text);
    if (flag === 'HIGH') return { key: 'high', label: 'Au-dessus de la norme', text: 'text-red-700 dark:text-red-400', border: 'border-red-400 bg-red-50/60 dark:bg-red-950/20 focus-visible:border-red-500' };
    if (flag === 'LOW') return { key: 'low', label: 'En dessous de la norme', text: 'text-amber-700 dark:text-amber-400', border: 'border-amber-400 bg-amber-50/60 dark:bg-amber-950/20 focus-visible:border-amber-500' };
    if (flag === 'NORMAL') return { key: 'normal', label: 'Dans la norme', text: 'text-emerald-700 dark:text-emerald-400', border: 'border-emerald-400 focus-visible:border-emerald-500' };

    return null;
};

export const NUGENT_KEYS = ['lactobacilli', 'gardnerella', 'mobiluncus'];

/** Le score de Nugent, seulement quand les trois sous-scores sont donnés. */
export const nugentScore = (parts = {}) => {
    const values = NUGENT_KEYS.map((key) => parts?.[key]);
    if (values.some((value) => value === null || value === undefined || value === '')) {
        return null;
    }

    return values.reduce((sum, value) => sum + Number(value), 0);
};

export const nugentReading = (score) => {
    if (score === null || score === undefined) return null;
    if (score <= 3) return { label: 'Flore normale', suggested: 'NORMAL', tone: 'success' };
    if (score <= 6) return { label: 'Flore intermédiaire', suggested: null, tone: 'warning' };

    return { label: 'Vaginose bactérienne', suggested: 'PATHOLOGICAL', tone: 'danger' };
};

/** L'interprétation proposée — la même règle que le serveur, jamais imposée. */
export const suggestedInterpretation = (node, entry) => {
    if (!node?.interpretable) return null;
    if (node.entry_mode === 'NUMERIC') {
        const flag = rangeFlag(node.range, entry?.value);
        if (flag === 'NORMAL') return 'NORMAL';
        if (flag === 'LOW' || flag === 'HIGH') return 'PATHOLOGICAL';

        return null;
    }
    if (node.entry_mode === 'NUGENT') {
        return nugentReading(nugentScore(entry?.selections))?.suggested ?? null;
    }

    return null;
};

/** L'état de saisie d'une ligne, depuis le résultat enregistré. */
export const entryFromNode = (node) => {
    const result = node.result ?? null;
    const selections = result?.selections ?? {};

    return {
        analysis_uuid: node.uuid,
        // Un nombre se relit comme il se tape : « 4,6 », jamais « 4.6 ».
        value: node.entry_mode === 'NUMERIC' && /^-?\d+\.\d+$/.test(String(result?.value ?? ''))
            ? String(result.value).replace('.', ',')
            : (result?.value ?? ''),
        selections: node.entry_mode === 'MULTI_CHOICE'
            ? (Array.isArray(selections) ? [...selections] : [])
            : node.entry_mode === 'CULTURE'
                ? { bacteria: [...(selections.bacteria ?? [])], other: selections.other ?? '' }
                : { ...selections },
        interpretation: result?.interpretation ?? null,
        interpretation_set: result?.interpretation !== undefined && result?.interpretation !== null
            && result.interpretation !== suggestedInterpretation(node, { value: result.value, selections }),
    };
};

/** Ce qui part au serveur : une ligne par analyse qui prend un résultat. */
export const resultsPayload = (entries) => entries.map((entry) => {
    const line = {
        analysis_uuid: entry.analysis_uuid,
        value: entry.value === null || entry.value === undefined ? '' : String(entry.value),
        selections: entry.selections,
    };
    if (entry.interpretation_set) {
        line.interpretation = entry.interpretation || null;
    }

    return line;
});

/** Une ligne porte-t-elle quelque chose ? (sert au compteur « N / M saisies ») */
export const entryFilled = (node, entry) => {
    if (!entry) return false;
    switch (node.entry_mode) {
        case 'MULTI_CHOICE':
            return Array.isArray(entry.selections) && entry.selections.length > 0;
        case 'NUGENT':
            return NUGENT_KEYS.some((key) => entry.selections?.[key] !== undefined && entry.selections?.[key] !== '' && entry.selections?.[key] !== null);
        default:
            return String(entry.value ?? '').trim() !== '';
    }
};

/**
 * ADR-218 — la note de chaque ligne (« Notes : » du compte rendu), groupes
 * compris. `initial` est ce que le serveur a servi : une note vidée part vide
 * (le serveur l'efface), une ligne jamais annotée ne part pas.
 */
export const NOTE_MAX_LENGTH = 1000;
export const notesFromNodes = (nodes = []) => Object.fromEntries(nodes.map((node) => [node.uuid, node.note ?? '']));
export const notesPayload = (notes = {}, initial = {}) => Object.entries(notes)
    .map(([uuid, note]) => ({ analysis_uuid: uuid, note: String(note ?? '').trim() }))
    .filter(({ analysis_uuid, note }) => note !== '' || String(initial[analysis_uuid] ?? '').trim() !== '');

export const FLAG_LABELS = { LOW: 'Bas', HIGH: 'Élevé', NORMAL: 'Dans la norme' };

export const INTERPRETATION_LABELS = { NORMAL: 'Normal', PATHOLOGICAL: 'Pathologique' };
export const ANTIBIOGRAM_TONES = { S: 'success', I: 'warning', R: 'danger' };

/** L'interprétation affichée : choisie, sinon proposée, sinon enregistrée. */
export const effectiveInterpretation = (node, entry) => {
    if (!node?.interpretable) return null;
    if (entry?.interpretation_set) return entry.interpretation || null;

    return suggestedInterpretation(node, entry) ?? node.result?.interpretation ?? null;
};

/** Un résultat enregistré, lu comme il s'imprime — jamais recalculé côté serveur à partir de ce texte. */
export const resultText = (node, options = {}) => {
    const result = node?.result;
    if (!result) return '';
    const selections = result.selections ?? {};
    switch (node.entry_mode) {
        case 'CULTURE': {
            const label = (options.culture ?? []).find((option) => option.value === result.value)?.label ?? result.value;
            if (result.value === 'GROWTH') {
                const names = (node.antibiograms ?? []).map((antibiogram) => antibiogram.bacterium).filter(Boolean);
                return names.length ? `${label} : ${names.join(', ')}` : label;
            }
            if (result.value === 'OTHER' && selections.other) return `${label} : ${selections.other}`;
            return label ?? '';
        }
        case 'NUGENT': {
            const score = nugentScore(selections);
            if (score === null) return 'Score incomplet';
            return `Score ${score}/10 — ${nugentReading(score).label}`;
        }
        case 'NEG_POS_VALUE':
        case 'NEG_POS_CHOICE':
            return selections.detail ? `${result.value} (${selections.detail})` : (result.value ?? '');
        default:
            return result.value ?? '';
    }
};
