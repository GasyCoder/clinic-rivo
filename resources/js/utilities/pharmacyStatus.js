/**
 * The only place a Pharmacy status is mapped to a colour. Wording comes from
 * the backend (`status_label`), so no screen keeps its own copy of either.
 */
const TONES = {
    // Stock
    AVAILABLE: 'success',
    OUT_OF_STOCK: 'danger',
    EXPIRING_SOON: 'warning',
    EXPIRED: 'danger',
    UNAVAILABLE: 'neutral',
    INACTIVE: 'neutral',
    // ADR-176 — commandé, jamais entré en stock : un état à part, jamais une rupture.
    NEVER_RECEIVED: 'info',
    // Dispensing
    AWAITING_INVOICE: 'info',
    AWAITING_PAYMENT: 'warning',
    READY: 'success',
    PARTIALLY_DISPENSED: 'primary',
    DISPENSED: 'neutral',
    // Purchase orders
    DRAFT: 'neutral',
    ORDERED: 'info',
    PARTIALLY_RECEIVED: 'warning',
    RECEIVED: 'success',
    // ADR-179 — clôturée : plus rien n'est attendu, mais elle n'a pas été
    // livrée en entier. Ni « Reçue », ni « Annulée ».
    CLOSED: 'neutral',
    CANCELLED: 'danger',
    // Care consumables
    PENDING: 'warning',
    PARTIALLY_SERVED: 'primary',
    SERVED: 'success',
};

export const statusTone = (status) => TONES[status] ?? 'neutral';

export const formatNumber = (value) => new Intl.NumberFormat('fr-FR').format(value ?? 0);

export const formatMoney = (value) => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(Number(value || 0))} MGA`;
