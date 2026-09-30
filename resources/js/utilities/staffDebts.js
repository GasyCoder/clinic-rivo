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

/**
 * La part remboursée d'une dette (remboursements et reste remis), en pour cent, sur ce
 * qui est à rembourser en tout — le montant et son intérêt (ADR-229).
 */
export function repaidShare(debt) {
    const total = toMinor(debt?.total_due ?? debt?.amount ?? 0);
    if (! total) return 0;

    return Math.min(100, Math.round((((toMinor(debt.repaid) ?? 0) + (toMinor(debt.written_off_amount) ?? 0)) / total) * 100));
}

/** `Money::percentage` : un pourcentage d'un montant en unités mineures, arrondi au plus proche. */
function percentageMinor(amountMinor, rate) {
    const basisPoints = toMinor(rate);
    if (amountMinor < 0 || basisPoints === null || basisPoints > 10000) return null;

    return Math.floor(amountMinor / 10000) * basisPoints + Math.floor(((amountMinor % 10000) * basisPoints + 5000) / 10000);
}

/**
 * ADR-229 — l'intérêt d'un montant selon les tranches du site, écrit comme
 * `StaffDebtInterest::for()` (vérifié par test) : un montant fixe, ou un pourcentage du
 * montant emprunté arrondi à l'ariary. null quand aucune tranche ne couvre le montant.
 * Un aperçu seulement : le serveur recalcule et fige l'intérêt à la décision du DG.
 */
export function interestFor(amount, tiers) {
    const amountMinor = toMinor(amount);
    if (! amountMinor || ! Array.isArray(tiers)) return null;

    for (const tier of tiers) {
        const from = toMinor(tier.from);
        const to = tier.to === null || tier.to === undefined || tier.to === '' ? null : toMinor(tier.to);
        if (from === null || amountMinor < from || (to !== null && amountMinor > to)) continue;

        const interest = tier.mode === 'PERCENT'
            ? Math.floor(((percentageMinor(amountMinor, tier.value) ?? 0) + 50) / 100) * 100
            : toMinor(tier.value) ?? 0;

        return { mode: tier.mode, value: tier.value, amount: fromMinor(interest), from: tier.from, to: tier.to ?? null };
    }

    return null;
}

/** Ce qui est à rembourser en tout : le montant et l'intérêt de sa tranche. */
export function totalWithInterest(amount, tiers) {
    const amountMinor = toMinor(amount);
    if (! amountMinor) return null;

    const interest = interestFor(amount, tiers);

    return { interest, total: fromMinor(amountMinor + (interest ? toMinor(interest.amount) : 0)) };
}

/** Le texte d'une tranche : « de 50 000 à 1 000 000 Ar : 5 % ». */
export function tierLabel(tier, money) {
    const range = tier.to ? `de ${money(tier.from)} à ${money(tier.to)}` : `à partir de ${money(tier.from)}`;
    const value = tier.mode === 'PERCENT' ? `${String(tier.value).replace(/\.00$/, '').replace('.', ',')} %` : money(tier.value);

    return `${range} : ${value}`;
}

/**
 * ADR-229 — ce que des conditions dépassent dans les limites du site, par champ, pour
 * prévenir pendant la saisie. `maxInstallment` : la mensualité que le salaire permet
 * encore (servie par le serveur, jamais le salaire lui-même). Le serveur revérifie tout.
 */
export function ruleIssues(amount, installment, rules, money, maxInstallment = null) {
    const issues = {};
    const amountMinor = toMinor(amount);
    const installmentMinor = toMinor(installment);
    if (! rules || ! amountMinor) return issues;

    if (rules.min_amount && amountMinor < toMinor(rules.min_amount)) issues.amount = `Le montant minimum est de ${money(rules.min_amount)}.`;
    else if (rules.max_amount && amountMinor > toMinor(rules.max_amount)) issues.amount = `Le montant maximum est de ${money(rules.max_amount)}.`;

    if (! installmentMinor) return issues;

    const total = toMinor(totalWithInterest(amount, rules.interest_tiers)?.total) ?? amountMinor;
    if (rules.max_months) {
        const count = Math.ceil(total / Math.min(installmentMinor, total));
        if (count > rules.max_months) {
            issues.installment_amount = `Remboursement en ${rules.max_months} mois au plus : au moins ${money(fromMinor(Math.ceil(Math.ceil(total / rules.max_months) / 100) * 100))} par mois.`;
        }
    }

    if (! issues.installment_amount && maxInstallment !== null && maxInstallment !== undefined && installmentMinor > toMinor(maxInstallment)) {
        issues.installment_amount = `Au plus ${money(maxInstallment)} par mois (${rules.max_salary_share} % du salaire déclaré, dettes en cours comprises).`;
    }

    return issues;
}

/** Les dettes qui attendent encore quelque chose : une décision, un versement, des remboursements. */
export const OPEN_STATUSES = ['REQUESTED', 'APPROVED', 'ACTIVE'];
export const isOpenDebt = (debt) => OPEN_STATUSES.includes(debt?.status);

/**
 * Où en est une dette, en quatre étapes : la demande, la décision du DG, le versement
 * (remis hors RIVO, marqué au portail — ADR-229), le remboursement. Chaque étape est `done`, `current`, `stopped` (la dette
 * s'est arrêtée là), `upcoming` ou `skipped` (elle n'aura pas lieu). Tout est lu sur
 * l'état et l'historique servis par le serveur : rien n'est décidé ici.
 */
export function debtSteps(debt) {
    const events = debt?.timeline ?? [];
    const event = (key) => events.find((item) => item.key === key) ?? null;
    const step = (key, label, state, source = null, note = null) => ({ key, label, state, at: source?.at ?? null, by: source?.by ?? null, note });
    const status = debt?.status;
    const granted = debt?.granted != null;

    const steps = [step('request', 'Demandée', 'done', event('requested') ?? { at: debt?.requested_at })];

    if (status === 'REQUESTED') steps.push(step('decision', 'Décision du DG', 'current', null, 'En attente de sa décision'));
    else if (status === 'REFUSED') steps.push(step('decision', 'Refusée par le DG', 'stopped', event('refused')));
    else if (! granted) steps.push(step('decision', 'Demande retirée', 'stopped', event('cancelled')));
    else steps.push(step('decision', debt.granted.adjusted ? 'Accordée · ajustée' : 'Accordée', 'done', event('approved')));

    if (debt?.disbursement) steps.push(step('disbursement', 'Versée', 'done', event('disbursed') ?? { at: debt.disbursement.on }));
    else if (status === 'APPROVED') steps.push(step('disbursement', 'Versement', 'current', null, 'Elle va vous être versée'));
    else if (status === 'CANCELLED' && granted) steps.push(step('disbursement', 'Accord annulé', 'stopped', event('cancelled')));
    else steps.push(step('disbursement', 'Versement', status === 'REQUESTED' ? 'upcoming' : 'skipped'));

    if (status === 'SETTLED') steps.push(step('repayment', 'Soldée', 'done', event('settled')));
    else if (status === 'WRITTEN_OFF') steps.push(step('repayment', 'Reste remis par le DG', 'done', event('written_off')));
    else if (status === 'ACTIVE') steps.push(step('repayment', 'Remboursement', 'current', null, `${repaidShare(debt)} % remboursé`));
    else steps.push(step('repayment', 'Remboursement', status === 'REQUESTED' || status === 'APPROVED' ? 'upcoming' : 'skipped'));

    return steps;
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
