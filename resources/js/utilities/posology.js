/**
 * Ce que la posologie permet de déduire, et ce qu'elle ne permet pas.
 *
 * Écrit une seule fois : le même calcul sert l'éditeur de ligne et la fenêtre
 * de confirmation, et deux formules finiraient par annoncer deux quantités
 * pour la même ordonnance (ADR-110).
 */

/**
 * Les unités et rythmes proposés à la saisie d'une ligne d'ordonnance.
 *
 * Partagés : l'éditeur les présente, et le préremplissage depuis un protocole
 * (ADR-111) s'en sert pour découper « 500 mg » — deux listes finiraient par
 * ne pas reconnaître la même unité.
 */
export const DOSE_UNITS = ['mg', 'g', 'ml', 'UI', 'µg', 'comprimé(s)', 'gélule(s)', 'goutte(s)', 'bouffée(s)', 'cuillère(s)'];
export const DURATION_UNITS = ['jours', 'semaines', 'mois', 'prise unique'];
export const FREQUENCIES = [
    '1 fois/jour', '2 fois/jour', '3 fois/jour', '4 fois/jour',
    'matin et soir', 'matin, midi et soir', 'toutes les 4 h', 'toutes les 6 h',
    'toutes les 8 h', 'toutes les 12 h', 'au coucher', 'si besoin',
];

/**
 * Combien de prises par jour une fréquence représente.
 *
 * Seules les fréquences qui portent réellement un compte sont converties.
 * « si besoin » n'en porte aucun — une prise conditionnelle n'a pas de
 * cadence — et une fréquence tapée à la main non plus : deviner un nombre
 * dans une phrase libre reviendrait à inventer une posologie.
 */
export const INTAKES_PER_DAY = {
    '1 fois/jour': 1,
    '2 fois/jour': 2,
    '3 fois/jour': 3,
    '4 fois/jour': 4,
    'matin et soir': 2,
    'matin, midi et soir': 3,
    'toutes les 4 h': 6,
    'toutes les 6 h': 4,
    'toutes les 8 h': 3,
    'toutes les 12 h': 2,
    'au coucher': 1,
};

/**
 * Combien de jours une durée représente.
 *
 * Le mois vaut trente jours : c'est la convention de délivrance, pas un
 * calcul clinique, et l'écran l'écrit sous le champ plutôt que de la laisser
 * deviner.
 */
export const DAYS_PER_DURATION_UNIT = {
    jours: 1,
    semaines: 7,
    mois: 30,
};

/**
 * Les formes pharmaceutiques qui ne se dosent pas.
 *
 * Une compresse stérile ou une paire de gants n'a pas de dose : on en utilise
 * un nombre. Réclamer « 500 mg » sur un paquet de dix compresses n'était pas
 * seulement inutile, c'était un champ obligatoire impossible à remplir
 * honnêtement.
 *
 * La liste porte des **formes du référentiel** (ADR-036), jamais un libellé
 * ni un code : rien n'est déduit du nom d'un produit (ADR-052).
 */
export const UNDOSED_FORMS = ['PARAPHARMACY_CONSUMABLE'];

export const isUndosedForm = (form) => UNDOSED_FORMS.includes(form);

/**
 * La quantité que la posologie implique, ou `null` quand elle n'implique
 * rien.
 *
 * Le calcul suppose **une unité par prise** : c'est le cas courant, et il est
 * écrit à l'écran. Une dose qui couvre deux comprimés se corrige à la main —
 * le déduire exigerait de comparer la dose au dosage du produit, deux textes
 * libres dont l'unité ne correspond pas toujours.
 */
export const suggestedQuantity = ({ frequency, durationAmount, durationUnit }) => {
    const perDay = INTAKES_PER_DAY[String(frequency ?? '').trim()];

    if (!perDay) {
        return null;
    }

    // « prise unique » est une durée, et sa quantité est le nombre de prises
    // d'une seule journée.
    if (durationUnit === 'prise unique') {
        return perDay;
    }

    const days = Number(String(durationAmount ?? '').trim());
    const factor = DAYS_PER_DURATION_UNIT[durationUnit];

    if (!Number.isFinite(days) || days <= 0 || !factor) {
        return null;
    }

    return Math.ceil(perDay * days * factor);
};

/** La phrase qui dit sur quoi le calcul repose, jamais un chiffre nu. */
export const quantityBasis = ({ frequency, durationAmount, durationUnit }) => {
    const perDay = INTAKES_PER_DAY[String(frequency ?? '').trim()];

    if (!perDay) {
        return null;
    }

    if (durationUnit === 'prise unique') {
        return `${perDay} prise${perDay > 1 ? 's' : ''} · une unité par prise`;
    }

    const days = Number(String(durationAmount ?? '').trim());

    if (!Number.isFinite(days) || days <= 0 || !DAYS_PER_DURATION_UNIT[durationUnit]) {
        return null;
    }

    const total = days * DAYS_PER_DURATION_UNIT[durationUnit];
    const spelled = durationUnit === 'jours' ? '' : ` (${total} jours)`;

    return `${perDay}/jour × ${days} ${durationUnit}${spelled} · une unité par prise`;
};

/**
 * Sépare « 500 mg » en `{ amount: '500', unit: 'mg' }` pour l'éditeur de
 * ligne, qui présente le nombre et l'unité dans deux champs.
 *
 * Seule une unité connue est reconnue. Tout le reste — « 1 comprimé le
 * matin », « selon poids » — est rendu tel quel dans `amount` avec une unité
 * vide, plutôt que d'être tronqué ou affublé d'une unité qu'il n'a pas : un
 * protocole préremplit une ligne, il ne doit rien réécrire en chemin
 * (ADR-111).
 *
 * @param {?string} text
 * @param {string[]} units
 */
export const splitAmount = (text, units) => {
    const value = String(text ?? '').trim();

    if (value === '') {
        return { amount: '', unit: null };
    }

    if (units.includes(value)) {
        return { amount: '', unit: value };
    }

    const match = value.match(/^(\d+(?:[.,]\d+)?)\s*(.+)$/);

    if (match && units.includes(match[2].trim())) {
        return { amount: match[1], unit: match[2].trim() };
    }

    return { amount: value, unit: '' };
};

/**
 * Une quantité et son unité, jamais d'unité orpheline ni d'espace en trop :
 * « 500 mg », « 1 comprimé le matin » (unité vide), « » (rien de saisi).
 */
export const composeAmount = (amount, unit) => {
    const value = String(amount ?? '').trim();

    return value === '' ? '' : [value, unit].filter(Boolean).join(' ');
};

/**
 * Les champs de saisie d'une ligne dont la posologie est déjà écrite — une
 * ligne proposée par un protocole, ou un protocole qu'on rouvre (ADR-111).
 *
 * Une quantité fixée par le protocole est une décision : elle est marquée
 * « touchée », si bien que le calcul automatique de l'ADR-110 ne la réécrit
 * pas. Sans quantité, c'est au calcul de la déduire.
 */
export const editorFieldsFor = ({ dosage, duration, quantity } = {}) => {
    const dose = splitAmount(dosage, DOSE_UNITS);
    const length = splitAmount(duration, DURATION_UNITS);

    return {
        _dose_amount: dose.amount,
        _dose_unit: dose.unit ?? 'mg',
        _duration_amount: length.unit === 'prise unique' ? '' : length.amount,
        _duration_unit: length.unit ?? 'jours',
        _quantity_touched: quantity !== null && quantity !== undefined && quantity !== '',
    };
};
