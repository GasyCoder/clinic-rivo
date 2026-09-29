import { formatDateTime } from '@/utilities/date';

/**
 * ADR-216 — ce que l'écran de la paillasse dit de l'envoi au médecin. Une aide :
 * le serveur (`SendLabResultsAction`) revérifie chaque analyse et refuse ce qui
 * ne peut pas partir, en le nommant.
 */

/** La valeur du choix « Aucun médecin — patient externe ». */
export const NO_RECIPIENT = '__none__';

const savedResults = (item) => (item.nodes ?? []).filter((node) => node.takes_result && node.result).length;

/**
 * Une analyse peut-elle partir, et pourquoi pas ?
 *
 * @returns {{ sendable: boolean, note: string, correction: boolean }}
 */
export function sendabilityOf(item) {
    const correction = Boolean(item.returned_at);

    if (item.status === 'VALIDATED') {
        return { sendable: false, correction: false, note: `Déjà envoyée${item.validated_at ? ` le ${formatDateTime(item.validated_at)}` : ''}.` };
    }
    if (item.status === 'COMPLETED') {
        return { sendable: true, correction, note: 'Résultat rendu, prêt à partir.' };
    }

    const saved = savedResults(item);
    if (saved === 0) {
        return { sendable: false, correction, note: item.has_definitions ? 'Aucun résultat saisi.' : 'Enregistrez d’abord le résultat.' };
    }

    return { sendable: true, correction, note: `${saved} résultat${saved > 1 ? 's' : ''} saisi${saved > 1 ? 's' : ''}.` };
}

export const sendableItems = (items) => (items ?? []).filter((item) => sendabilityOf(item).sendable);

/**
 * Le destinataire proposé à l'ouverture : celui déjà choisi pour la demande,
 * sinon le prescripteur quand il peut recevoir ; sinon rien — le technicien
 * choisit, jamais un médecin deviné.
 */
export function defaultRecipient(recipient, options) {
    const known = (uuid) => (options ?? []).some((option) => option.uuid === uuid);

    if (recipient?.addressed) {
        if (!recipient.uuid) return NO_RECIPIENT;
        if (known(recipient.uuid)) return recipient.uuid;
    }
    if (recipient?.proposed_uuid && known(recipient.proposed_uuid)) return recipient.proposed_uuid;

    return '';
}
