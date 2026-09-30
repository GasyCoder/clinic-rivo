/**
 * Mobile Money à Madagascar : les opérateurs et le préfixe qui les désigne.
 * Le préfixe ne fait que proposer l'opérateur ; le choix du RH l'emporte toujours.
 */
export const MOBILE_MONEY_OPERATORS = [
    { value: 'YAS', label: 'MVola (Yas)', short: 'Yas', prefixes: ['034', '038'], tone: 'bg-amber-400 text-amber-950' },
    { value: 'ORANGE', label: 'Orange Money', short: 'Orange', prefixes: ['032', '037'], tone: 'bg-orange-500 text-white' },
    { value: 'AIRTEL', label: 'Airtel Money', short: 'Airtel', prefixes: ['033'], tone: 'bg-red-600 text-white' },
];

/** Le numéro en chiffres nationaux : « +261 34 12 345 67 » → « 0341234567 ». */
export function nationalDigits(number) {
    const digits = String(number ?? '').replace(/\D+/g, '');

    if (digits.startsWith('261') && digits.length >= 12) return `0${digits.slice(3)}`;

    return digits;
}

/** L'opérateur que le préfixe désigne, ou null. */
export function operatorFromNumber(number) {
    const prefix = nationalDigits(number).slice(0, 3);

    return MOBILE_MONEY_OPERATORS.find((operator) => operator.prefixes.includes(prefix))?.value ?? null;
}

/** « 034 12 345 67 » : l'écriture habituelle d'un numéro malgache à 10 chiffres. */
export function formatMobileNumber(number) {
    const digits = nationalDigits(number);

    if (digits.length !== 10) return String(number ?? '').trim();

    return `${digits.slice(0, 3)} ${digits.slice(3, 5)} ${digits.slice(5, 8)} ${digits.slice(8)}`;
}

export function operatorOf(value) {
    return MOBILE_MONEY_OPERATORS.find((operator) => operator.value === value) ?? null;
}
