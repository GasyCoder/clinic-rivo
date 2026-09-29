import { Briefcase, CircleCheck, Contact, Gift, Landmark, ListPlus, User, Wallet } from 'lucide-vue-next';

/**
 * ADR-221 — les étapes du dossier employé, dans l'ordre où on le remplit :
 * la personne, puis son poste, puis ce qu'elle perçoit et où elle le perçoit.
 *
 * La création et la modification lisent la même liste : une étape ajoutée ici
 * apparaît aux deux endroits. Rémunération, Avantages et Banque n'existent
 * qu'avec le droit de lire la rémunération (ADR-206).
 */
export const EMPLOYEE_STEPS = [
    { key: 'identity', label: 'Identité', hint: 'Qui est la personne ?', icon: User },
    { key: 'contact', label: 'Contact', hint: 'Comment la joindre ?', icon: Contact },
    { key: 'post', label: 'Poste', hint: 'Où travaille-t-elle ?', icon: Briefcase },
    { key: 'more', label: 'Compléments', hint: 'Famille, diplôme, matériel', icon: ListPlus },
    { key: 'pay', label: 'Rémunération', hint: 'Salaire ou indemnité', icon: Wallet, payroll: true },
    { key: 'benefits', label: 'Avantages', hint: 'Montant et motif', icon: Gift, payroll: true },
    { key: 'bank', label: 'Banque', hint: 'Banque et compte', icon: Landmark, payroll: true },
    { key: 'done', label: 'Récapitulatif', hint: 'Vérifier et terminer', icon: CircleCheck },
];

/**
 * @param {{ payroll?: boolean }} options
 * @returns {Array<{ key: string, label: string, hint: string, icon: object, number: number }>}
 */
export function employeeSteps({ payroll = false } = {}) {
    return EMPLOYEE_STEPS
        .filter((step) => ! step.payroll || payroll)
        .map((step, index) => ({ ...step, number: index + 1 }));
}

/** L'étape qui suit (ou précède) `key` ; `null` au bout du parcours. */
export function neighbourStep(steps, key, offset) {
    const index = steps.findIndex((step) => step.key === key);

    return index === -1 ? null : (steps[index + offset]?.key ?? null);
}
