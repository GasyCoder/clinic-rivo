/**
 * Ce que la posologie permet de déduire, et ce qu'elle ne permet pas.
 *
 * Écrit une seule fois : le même calcul sert l'éditeur de ligne et la fenêtre
 * de confirmation, et deux formules finiraient par annoncer deux quantités
 * pour la même ordonnance (ADR-110).
 */

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
