/**
 * ADR-214 — les bornes critiques d'une analyse, au catalogue et à la paillasse.
 *
 * Le serveur reste juge : il relit chaque borne en nombre (« 2,5 » vaut 2,5),
 * vérifie que la basse reste sous la haute, et marque critique d'office un
 * résultat au-delà. Ce module ne fait que préparer le formulaire et montrer la
 * même lecture pendant la saisie.
 */

export const CRITICAL_PROFILES = [
    { key: 'general', label: 'Générale' },
    { key: 'male', label: 'Homme' },
    { key: 'female', label: 'Femme' },
    { key: 'child_male', label: 'Enfant garçon' },
    { key: 'child_female', label: 'Enfant fille' },
];

const text = (value) => (value === null || value === undefined ? '' : String(value).replace('.', ','));

/** Les cinq profils en champs de texte, remplis de ce que le catalogue porte. */
export const criticalRangesForm = (ranges = null) => Object.fromEntries(CRITICAL_PROFILES.map(({ key }) => [
    key,
    { low: text(ranges?.[key]?.low), high: text(ranges?.[key]?.high) },
]));

/** Ce qui part au serveur : les seuls profils qui portent une borne ; `null` quand rien n'est saisi. */
export const criticalRangesPayload = (value = {}) => {
    const kept = {};
    CRITICAL_PROFILES.forEach(({ key }) => {
        const low = String(value?.[key]?.low ?? '').trim();
        const high = String(value?.[key]?.high ?? '').trim();
        if (low !== '' || high !== '') kept[key] = { low: low || null, high: high || null };
    });

    return Object.keys(kept).length ? kept : null;
};

/** Combien de profils portent une borne (sert au résumé de l'étape). */
export const criticalRangesCount = (value = {}) => Object.keys(criticalRangesPayload(value) ?? {}).length;

const NUMBER = /^-?\d+(?:[.,]\d+)?$/;

/** BAS ou HAUT critique pendant la saisie ; `null` entre les bornes, sans borne ou pour un texte. */
export const criticalFlag = (critical, value) => {
    const raw = String(value ?? '').trim();
    if (!critical || !NUMBER.test(raw)) return null;
    const number = Number(raw.replace(',', '.'));
    if (critical.low !== null && critical.low !== undefined && number < critical.low) return 'LOW';
    if (critical.high !== null && critical.high !== undefined && number > critical.high) return 'HIGH';

    return null;
};
