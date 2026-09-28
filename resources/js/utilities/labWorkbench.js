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
    { value: 'to_receive', label: 'À réceptionner', hint: 'Règlement et prélèvements', tone: 'primary' },
    { value: 'to_do', label: 'À faire', hint: 'À analyser ou en cours', tone: 'amber' },
    { value: 'to_redo', label: 'À refaire', hint: 'Renvoyées avec un motif', tone: 'red' },
    { value: 'to_validate', label: 'À valider', hint: 'Terminées, pour le biologiste', tone: 'sky' },
    { value: 'validated', label: 'Validées', hint: 'Toutes les analyses validées', tone: 'emerald' },
    { value: 'all', label: 'Toutes', hint: 'Demandes non retirées', tone: 'neutral' },
];

/** ADR-214 — l'état d'une demande dans la file, tel que la ligne le dit. */
export const LAB_STATE_LABELS = { to_receive: 'À réceptionner', to_do: 'À faire', to_redo: 'À refaire', to_validate: 'À valider', validated: 'Validée' };
export const LAB_STATE_TONES = { to_receive: 'info', to_do: 'warning', to_redo: 'danger', to_validate: 'primary', validated: 'success' };

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
        value: result?.value ?? '',
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
