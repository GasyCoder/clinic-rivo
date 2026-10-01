/**
 * ADR-233 — l'écran « Paie du mois » : statut d'une ligne, vues, recherche et filtres.
 * Le serveur sert toutes les lignes du mois et calcule chaque montant ; ici on ne fait que
 * trier ce qui est déjà servi. Aucun montant n'est recalculé.
 */

/** Les vues de l'écran, dans l'ordre des onglets. La clé est celle de l'adresse (`?vue=`). */
export const PAYROLL_VIEWS = [
    { key: 'toutes', label: 'Toutes' },
    { key: 'a-payer', label: 'À payer' },
    { key: 'payees', label: 'Payées' },
];

/** Les présentations de la liste. */
export const PAYROLL_LAYOUTS = ['table', 'grid', 'detail'];
export const PAYROLL_LAYOUT_KEY = 'rivo:payroll:layout';

export const PAYMENT_MODE_OPTIONS = [
    { value: 'BANK', label: 'Virement bancaire' },
    { value: 'MOBILE_MONEY', label: 'Mobile Money' },
    { value: 'CASH', label: 'Espèces' },
    { value: 'NONE', label: 'Mode non renseigné' },
];

/**
 * Le statut d'une ligne : `paid` (paie figée), `to_pay` (peut être marquée payée),
 * `waiting` (rien à payer pour l'instant — mois à venir, ou seulement une paie annulée).
 */
export function payrollStatusOf(row) {
    if (row?.payment) return 'paid';
    if (row?.payable) return 'to_pay';

    return 'waiting';
}

export const PAYROLL_STATUS = {
    paid: { label: 'Payée', variant: 'success' },
    to_pay: { label: 'À payer', variant: 'warning' },
    waiting: { label: 'Non payable', variant: 'outline' },
};

/** La ligne entre-t-elle dans la vue ? */
export function inView(row, view) {
    if (view === 'a-payer') return payrollStatusOf(row) === 'to_pay';
    if (view === 'payees') return payrollStatusOf(row) === 'paid';

    return true;
}

const normalize = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim();

/** Recherche sur le nom, le matricule, la fonction et le service : tous les mots, dans n'importe quel ordre. */
export function matchesSearch(row, query) {
    const words = normalize(query).split(/\s+/).filter(Boolean);
    if (! words.length) return true;
    const haystack = normalize([row.name, row.employee_number, row.job_title, row.department].filter(Boolean).join(' '));

    return words.every((word) => haystack.includes(word));
}

/** Les retenues de dettes que la ligne porte. */
export const hasDebtDeduction = (row) => (row?.lines ?? []).some((line) => line.kind === 'DEBT');

/** La ligne passe-t-elle les filtres (hors vue) ? */
export function matchesFilters(row, filters = {}) {
    if (! matchesSearch(row, filters.q)) return false;
    if (filters.service && (row.department ?? '') !== filters.service) return false;
    if (filters.mode) {
        const mode = row.payment_mode?.mode ?? null;
        if (filters.mode === 'NONE' ? mode !== null : mode !== filters.mode) return false;
    }
    if (filters.dettes && ! hasDebtDeduction(row)) return false;

    return true;
}

/** Les lignes affichées : la vue et les filtres. */
export function filterPayrollRows(rows, filters = {}) {
    return (rows ?? []).filter((row) => inView(row, filters.vue) && matchesFilters(row, filters));
}

/**
 * Le nombre de chaque vue, les autres filtres appliqués : chaque compte est ce que
 * donnerait un clic sur son onglet, jamais une liste vide derrière un nombre.
 */
export function payrollViewCounts(rows, filters = {}) {
    const matching = (rows ?? []).filter((row) => matchesFilters(row, filters));

    return Object.fromEntries(PAYROLL_VIEWS.map((view) => [view.key, matching.filter((row) => inView(row, view.key)).length]));
}

/** Les services présents dans le mois, pour le filtre. */
export function serviceOptions(rows) {
    return [...new Set((rows ?? []).map((row) => row.department).filter(Boolean))]
        .sort((a, b) => a.localeCompare(b, 'fr'))
        .map((label) => ({ value: label, label }));
}

/** Les filtres actifs, hors vue : de quoi afficher « Effacer les filtres ». */
export const hasActiveFilters = (filters = {}) => Boolean(String(filters.q ?? '').trim() || filters.service || filters.mode || filters.dettes);

/** Les paramètres d'adresse de l'état de l'écran (valeurs par défaut omises). */
export function payrollQuery(month, filters = {}) {
    const params = new URLSearchParams({ mois: month });
    if (filters.vue && filters.vue !== 'toutes') params.set('vue', filters.vue);
    if (String(filters.q ?? '').trim()) params.set('q', String(filters.q).trim());
    if (filters.service) params.set('service', filters.service);
    if (filters.mode) params.set('mode', filters.mode);
    if (filters.dettes) params.set('dettes', '1');

    return params.toString();
}

/** Total net (« total ») d'un ensemble de lignes, en nombre — pour un récapitulatif à l'écran. */
export const netOf = (rows) => (rows ?? []).reduce((sum, row) => sum + Number(row.total ?? 0), 0);
