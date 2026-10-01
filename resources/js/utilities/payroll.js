/**
 * ADR-233 — la paie à l'écran : passer des paramètres servis par le serveur au formulaire,
 * et du formulaire à l'envoi. Aucun calcul de retenue ici : la simulation et la paie sont
 * calculées par le serveur (`PayrollCalculator`).
 */

/** « 350000.00 » → « 350000 » ; « 1.50 » → « 1.5 » ; null → ''. */
export function inputOf(value) {
    if (value === null || value === undefined || value === '') return '';
    const number = Number(value);
    if (! Number.isFinite(number)) return String(value);

    return String(Number(number.toFixed(2)));
}

/** « 350 000 », « 1,5 » → « 350000 », « 1.5 » ; vide → null. */
export function cleanNumber(value) {
    if (value === null || value === undefined) return null;
    const clean = String(value).replace(/[\s  ]+/g, '').replace(',', '.');

    return clean === '' ? null : clean;
}

/** Les paramètres du serveur (`PayrollSetting::snapshot()`) prêts pour le formulaire. */
export function payrollFormFrom(settings) {
    return {
        legal_deductions_enabled: Boolean(settings?.legal_deductions_enabled),
        cnaps_employee_rate: inputOf(settings?.cnaps_employee_rate),
        cnaps_employer_rate: inputOf(settings?.cnaps_employer_rate),
        cnaps_ceiling: inputOf(settings?.cnaps_ceiling),
        health_label: settings?.health_label ?? '',
        health_employee_rate: inputOf(settings?.health_employee_rate),
        health_employer_rate: inputOf(settings?.health_employer_rate),
        health_ceiling: inputOf(settings?.health_ceiling),
        irsa_brackets: (settings?.irsa_brackets ?? []).map((bracket) => ({ up_to: inputOf(bracket.up_to), rate: inputOf(bracket.rate) })),
        irsa_minimum: inputOf(settings?.irsa_minimum),
        irsa_child_reduction: inputOf(settings?.irsa_child_reduction),
        irsa_base_rounding: inputOf(settings?.irsa_base_rounding),
        allowance_subject: Boolean(settings?.allowance_subject),
    };
}

/** Le formulaire tel qu'il part : nombres nettoyés, la dernière tranche toujours sans plafond. */
export function payrollPayload(form) {
    const brackets = (form?.irsa_brackets ?? []).map((bracket, index, all) => ({
        up_to: index === all.length - 1 ? null : cleanNumber(bracket.up_to),
        rate: cleanNumber(bracket.rate) ?? '0',
    }));

    return {
        legal_deductions_enabled: Boolean(form?.legal_deductions_enabled),
        cnaps_employee_rate: cleanNumber(form?.cnaps_employee_rate) ?? '0',
        cnaps_employer_rate: cleanNumber(form?.cnaps_employer_rate) ?? '0',
        cnaps_ceiling: cleanNumber(form?.cnaps_ceiling),
        health_label: String(form?.health_label ?? '').trim() || null,
        health_employee_rate: cleanNumber(form?.health_employee_rate) ?? '0',
        health_employer_rate: cleanNumber(form?.health_employer_rate) ?? '0',
        health_ceiling: cleanNumber(form?.health_ceiling),
        irsa_brackets: brackets,
        irsa_minimum: cleanNumber(form?.irsa_minimum) ?? '0',
        irsa_child_reduction: cleanNumber(form?.irsa_child_reduction) ?? '0',
        irsa_base_rounding: cleanNumber(form?.irsa_base_rounding),
        allowance_subject: Boolean(form?.allowance_subject),
    };
}

/** Le début de chaque tranche : 0 pour la première, le plafond de la précédente ensuite. */
export function bracketsFrom(rows) {
    let from = 0;

    return (rows ?? []).map((row) => {
        const current = { from, up_to: cleanNumber(row.up_to) };
        const upper = Number(current.up_to);
        if (Number.isFinite(upper) && current.up_to !== null) from = upper;

        return current;
    });
}

/** Les lignes d'une paie qui sont des retenues (montant négatif). */
export const DEDUCTION_KINDS = ['CNAPS', 'HEALTH', 'IRSA', 'DEBT'];
