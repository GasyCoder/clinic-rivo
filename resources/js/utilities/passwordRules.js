/**
 * Les règles d'un mot de passe, telles que le serveur les applique (App\Support\SecurePassword :
 * 12 caractères, majuscule et minuscule, chiffre, symbole). Elles ne décident de rien : l'écran
 * les coche au fil de la frappe, le serveur tranche.
 *
 * Les expressions reprennent celles de Laravel (Rules\Password), lettres accentuées comprises.
 */
export const PASSWORD_MIN_LENGTH = 12;

const RULES = [
    { key: 'length', label: `${PASSWORD_MIN_LENGTH} caractères au moins`, test: (value) => [...value].length >= PASSWORD_MIN_LENGTH },
    { key: 'case', label: 'Une majuscule et une minuscule', test: (value) => /\p{Lu}/u.test(value) && /\p{Ll}/u.test(value) },
    { key: 'number', label: 'Un chiffre', test: (value) => /\p{N}/u.test(value) },
    { key: 'symbol', label: 'Un symbole (! ? # @ …)', test: (value) => /\p{Z}|\p{S}|\p{P}/u.test(value) },
];

/** @returns {{ key: string, label: string, ok: boolean }[]} */
export function passwordChecks(password) {
    const value = String(password ?? '');

    return RULES.map(({ key, label, test }) => ({ key, label, ok: test(value) }));
}

/** Toutes les règles sont remplies. */
export function passwordMeetsRules(password) {
    return passwordChecks(password).every((check) => check.ok);
}
