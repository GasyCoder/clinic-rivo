import { Ban, CircleCheck, CircleX, Gift, Hourglass, Landmark, Wallet } from 'lucide-vue-next';
import { monthLabel, shiftMonth } from './date.js';

/**
 * ADR-228 — les dettes du personnel à l'écran. Rien n'y décide : le serveur recompte
 * tout (plan, retenue, reste dû). Le plan calculé ici n'est qu'un aperçu pendant la
 * saisie, écrit comme `StaffDebtLedger::plan()` (vérifié par test).
 */

/** « 300 000,50 » / « 300000.5 » / 300000 → 30000050 ; null s'il ne se lit pas. */
export function toMinor(value) {
    if (typeof value === 'number') return Number.isFinite(value) && value >= 0 ? Math.round(value * 100) : null;

    const text = String(value ?? '').replace(/[\s  ]/g, '').replace(',', '.');
    if (! /^\d+(\.\d{1,2})?$/.test(text)) return null;

    const [whole, fraction = ''] = text.split('.');

    return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
}

/** 30000050 → « 300000.50 » */
export function fromMinor(minor) {
    const value = Math.max(0, Math.trunc(minor));

    return `${Math.trunc(value / 100)}.${String(value % 100).padStart(2, '0')}`;
}

/**
 * Le plan d'un montant : combien de mensualités, la dernière (plus petite si le montant
 * ne tombe pas juste), et le mois de la dernière. null tant qu'il manque quelque chose.
 */
export function debtPlan(amount, installment, firstPeriod) {
    const amountMinor = toMinor(amount);
    let installmentMinor = toMinor(installment);
    if (! amountMinor || ! installmentMinor || ! /^\d{4}-\d{2}$/.test(String(firstPeriod ?? ''))) return null;

    installmentMinor = Math.min(installmentMinor, amountMinor);
    const count = Math.ceil(amountMinor / installmentMinor);

    return {
        count,
        installment: fromMinor(installmentMinor),
        last_amount: fromMinor(amountMinor - (count - 1) * installmentMinor),
        first_period: firstPeriod,
        last_period: shiftMonth(firstPeriod, count - 1),
    };
}

/** La mensualité qui rembourse un montant en `months` mois, arrondie à la centaine au-dessus. */
export function installmentFor(amount, months) {
    const amountMinor = toMinor(amount);
    if (! amountMinor || months < 1) return null;

    const perMonth = Math.ceil(amountMinor / months);

    return fromMinor(Math.min(amountMinor, Math.ceil(perMonth / 10000) * 10000));
}

/** Les mois proposés pour un premier remboursement : ce mois-ci et les suivants. */
export function periodOptions(currentMonth, count = 12, keep = null) {
    const options = Array.from({ length: count }, (_, index) => shiftMonth(currentMonth, index));
    if (keep && ! options.includes(keep)) options.unshift(keep);

    return options.map((value) => ({ value, label: monthLabel(value) }));
}

/** Le texte d'un plan : « 3 mensualités, d'octobre 2026 à décembre 2026 ». */
export function planSummary(plan) {
    if (! plan) return '';

    const count = `${plan.count} mensualité${plan.count > 1 ? 's' : ''}`;

    return plan.count === 1
        ? `${count}, en ${monthLabel(plan.first_period)}`
        : `${count}, de ${monthLabel(plan.first_period)} à ${monthLabel(plan.last_period)}`;
}

/** La part d'un salaire que prend une mensualité, en pour cent (arrondi). */
export function salaryShare(installment, salary) {
    const installmentMinor = toMinor(installment);
    const salaryMinor = toMinor(salary);
    if (! installmentMinor || ! salaryMinor) return null;

    return Math.round((installmentMinor / salaryMinor) * 100);
}

export const STATUS_ICONS = {
    REQUESTED: Hourglass,
    APPROVED: Wallet,
    ACTIVE: Landmark,
    SETTLED: CircleCheck,
    REFUSED: CircleX,
    CANCELLED: Ban,
    WRITTEN_OFF: Gift,
};

/** Les vues de la liste, dans l'ordre du travail ; les comptes viennent du serveur. */
export const DEBT_VIEWS = [
    { key: 'a-decider', label: 'À décider', hint: 'Attendent la décision du DG', icon: Hourglass, tone: 'warning' },
    { key: 'a-verser', label: 'À verser', hint: 'Accordées, à remettre hors RIVO', icon: Wallet, tone: 'primary' },
    { key: 'en-cours', label: 'En remboursement', hint: 'Versées, reste dû', icon: Landmark, tone: 'primary' },
    { key: 'closes', label: 'Closes', hint: 'Soldées, remises, refusées, annulées', icon: CircleCheck, tone: 'neutral' },
];
