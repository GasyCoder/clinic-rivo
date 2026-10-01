/**
 * ADR-239 — les règles d'écran des modes de paiement du portail. Le site
 * revérifie tout ; ce fichier dit seulement quels champs une catégorie
 * demande, ce qu'on propose par défaut et ce qui part au site.
 */

/** Ce que chaque catégorie demande dans la fiche. */
export const CATEGORY_FIELDS = {
    CASH: { name: true, bank: false, detail: false },
    MOBILE_MONEY: { name: true, bank: false, detail: false },
    BANK: { name: false, bank: true, detail: false },
    COVERAGE: { name: true, bank: false, detail: false },
    OTHER: { name: true, bank: false, detail: true },
};

/** Les réglages qu'on propose à la création, selon la catégorie : jamais imposés. */
export const CATEGORY_DEFAULTS = {
    CASH: { affects_cash_balance: true, requires_reference: false },
    MOBILE_MONEY: { affects_cash_balance: false, requires_reference: true },
    BANK: { affects_cash_balance: false, requires_reference: true },
    COVERAGE: { affects_cash_balance: false, requires_reference: false },
    OTHER: { affects_cash_balance: false, requires_reference: false },
};

/** Exemples de libellé, pour que le champ dise ce qu'on attend. */
export const NAME_PLACEHOLDERS = {
    CASH: 'Espèces',
    MOBILE_MONEY: 'MVola, Orange Money…',
    BANK: 'Laissez vide : le nom de la banque',
    COVERAGE: 'Prise en charge partenaire',
    OTHER: 'Carte bancaire TPE',
};

export const fieldsFor = (category) => CATEGORY_FIELDS[category] ?? CATEGORY_FIELDS.OTHER;

/** « Carte bancaire » → « CARTE_BANCAIRE » : la forme d'un code accepté par le site. */
export function codeFrom(text) {
    return String(text ?? '')
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 40);
}

/**
 * Le code proposé tant que l'utilisateur n'a pas écrit le sien :
 * BANK_BOA pour une banque, OTHER_CARTE_BANCAIRE pour « Autre », sinon le libellé.
 */
export function suggestedCode(form, banks = []) {
    if (form.category === 'BANK') {
        const bank = banks.find((item) => item.uuid === form.bank_uuid);
        return bank ? codeFrom(`BANK_${bank.code}`) : '';
    }

    if (form.category === 'OTHER') {
        return form.category_detail ? codeFrom(`OTHER_${form.category_detail}`) : '';
    }

    const prefix = form.category === 'MOBILE_MONEY' ? 'MOBILE_MONEY_' : '';

    return form.name ? codeFrom(`${prefix}${form.name}`) : '';
}

/** Le libellé que le site écrira pour une banque quand le champ reste vide. */
export function bankLabel(bank) {
    return bank ? `${bank.code} — ${bank.name}` : '';
}

/**
 * Un mode « Banque » déjà en service sans banque précise (« Chèque »,
 * « Virement bancaire ») reste générique : le site ne lui en impose pas.
 */
export const isGenericBankMethod = (method) => method?.category === 'BANK' && !method?.bank_uuid;

/** Ce qui manque encore, en mots, avant d'envoyer ; vide = prêt. */
export function missingFields(form, { editing = null } = {}) {
    const fields = fieldsFor(form.category);
    const missing = [];

    if (!editing && !String(form.code ?? '').trim()) missing.push('le code');
    if (!form.category) missing.push('la catégorie');
    if (fields.bank && !form.bank_uuid && !isGenericBankMethod(editing)) missing.push('la banque');
    if (fields.detail && String(form.category_detail ?? '').trim().length < 2 && !(editing?.category === 'OTHER' && !editing?.category_detail)) {
        missing.push('la précision de la catégorie');
    }
    if (fields.name && !String(form.name ?? '').trim()) missing.push('le libellé');

    return missing;
}

/** Seuls les champs de la catégorie choisie partent au site. */
export function payloadFor(form, { editing = null } = {}) {
    const fields = fieldsFor(form.category);

    return {
        ...(editing ? {} : { site_code: form.site_code, code: form.code }),
        name: String(form.name ?? '').trim() || null,
        category: form.category,
        ...(fields.bank ? { bank_uuid: form.bank_uuid || null } : {}),
        ...(fields.detail ? { category_detail: String(form.category_detail ?? '').trim() || null } : {}),
        affects_cash_balance: Boolean(form.affects_cash_balance),
        requires_reference: Boolean(form.requires_reference),
    };
}

/** Le nom de la catégorie sur une ligne : « Banque · BOA », « Autre · Carte bancaire ». */
export function categoryCaption(method) {
    if (method.category === 'BANK') return method.bank ? `Banque · ${method.bank.code}` : 'Banque · toutes banques';
    if (method.category === 'OTHER' && method.category_detail) return `Autre · ${method.category_detail}`;

    return method.category_label;
}

/** Recherche sur le libellé, le code, la banque et la précision, sans accents ni casse. */
export function matchesSearch(method, needle) {
    const fold = (text) => String(text ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
    const words = fold(needle).split(/\s+/).filter(Boolean);
    if (!words.length) return true;
    const haystack = fold([method.name, method.code, method.category_label, method.category_detail, method.bank?.code, method.bank?.name].join(' '));

    return words.every((word) => haystack.includes(word));
}
