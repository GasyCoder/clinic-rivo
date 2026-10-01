/**
 * ADR-227 — la saisie des avantages d'un mois, à l'écran. Rien n'y décide : le serveur
 * revalide chaque ligne. Ces fonctions lisent un montant écrit à la française, comptent
 * par médecin et disent ce qui manque avant l'envoi.
 */

/** « 150 000,50 » → 150000.5 ; vide ou illisible → null. */
export function parseAmount(value) {
    if (value === null || value === undefined) return null;
    const clean = String(value).replace(/[\s  ]+/g, '').replace(',', '.');
    if (clean === '' || ! /^\d+(\.\d{1,2})?$/.test(clean)) return null;

    return Number(clean);
}

/** Ce qui empêche une ligne de partir, ou `null`. */
export function lineProblem(line) {
    const amount = parseAmount(line?.amount);
    if (String(line?.amount ?? '').trim() === '') return 'Montant obligatoire';
    if (amount === null) return 'Montant illisible';
    if (amount <= 0) return 'Le montant doit être supérieur à zéro';
    if (! String(line?.reason ?? '').trim()) return 'Motif obligatoire';
    if (! /^\d{4}-(0[1-9]|1[0-2])$/.test(String(line?.period ?? ''))) return 'Mois de paie obligatoire';

    return null;
}

/** Une ligne restée entièrement vide ne compte pas : on l'ignore plutôt que de la refuser. */
export function isBlank(line) {
    return String(line?.amount ?? '').trim() === '' && String(line?.reason ?? '').trim() === '';
}

/** Nombre et somme des lignes renseignées d'un médecin. */
export function doctorTotals(lines) {
    const filled = (lines ?? []).filter((line) => ! isBlank(line));

    return {
        count: filled.length,
        total: filled.reduce((sum, line) => sum + (parseAmount(line.amount) ?? 0), 0),
    };
}

/** Totaux de toute la saisie : `{ doctors, count, total }`. */
export function grandTotals(rows) {
    return Object.values(rows ?? {}).reduce((acc, lines) => {
        const { count, total } = doctorTotals(lines);
        if (count) acc.doctors += 1;
        acc.count += count;
        acc.total += total;

        return acc;
    }, { doctors: 0, count: 0, total: 0 });
}

/** Les lignes à envoyer, ou `null` si l'une d'elles est incomplète. */
export function entriesPayload(rows) {
    const lines = [];
    for (const [employeeUuid, doctorLines] of Object.entries(rows ?? {})) {
        for (const line of doctorLines ?? []) {
            if (isBlank(line)) continue;
            if (lineProblem(line)) return null;
            lines.push({ employee_uuid: employeeUuid, amount: String(parseAmount(line.amount)), reason: String(line.reason).trim(), period: line.period });
        }
    }

    return lines;
}

/** « 50000.00 » → « 50 000 » : le montant proposé d'un article, prêt à être relu dans le champ. */
export function amountInput(value) {
    const amount = parseAmount(value);
    if (amount === null) return '';
    const [whole, decimals] = amount.toFixed(2).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

    return decimals === '00' ? grouped : `${grouped},${decimals}`;
}

/** Une ligne préremplie depuis un article proposé (ECHO · 50 000) ; le montant reste modifiable. */
export function lineFromArticle(article) {
    return { reason: String(article?.label ?? '').trim(), amount: amountInput(article?.amount) };
}

/** L'article proposé qui porte ce libellé (accents et casse ignorés), ou `null`. */
export function findArticle(articles, label) {
    const key = normalizeLabel(label);
    if (! key) return null;

    return (articles ?? []).find((article) => normalizeLabel(article.label) === key) ?? null;
}

function normalizeLabel(value) {
    return String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase();
}
