/**
 * ADR-160 — où en est une demande au bloc, dit de la même façon partout.
 *
 * La page du séjour et la liste des hospitalisés lisaient chacune leur copie de
 * ces libellés : deux copies finissent par dire deux choses du même patient.
 */
export const SURGERY_STATUS = {
    PENDING: { label: 'À programmer', variant: 'warning' },
    SCHEDULED: { label: 'Programmée', variant: 'secondary' },
    PREOPERATIVE_VALIDATED: { label: 'Prête pour le bloc', variant: 'success' },
    IN_PROGRESS: { label: 'Au bloc', variant: 'default' },
    COMPLETED: { label: 'Opéré', variant: 'success' },
    DISCHARGED: { label: 'Sorti du bloc', variant: 'outline' },
    CANCELLED: { label: 'Annulée', variant: 'outline' },
};

export const surgeryStatus = (status) => SURGERY_STATUS[status] ?? { label: status, variant: 'outline' };
