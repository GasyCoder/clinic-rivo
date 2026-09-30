import { usePage } from '@inertiajs/vue3';
import { STAFF_DEBT_SITE_BASE, mapStaffDebtPath } from './staffDebtPath.js';

/**
 * ADR-229 — l'adresse d'un écran des dettes du personnel : le portail fournit, dans
 * `staffDebtContext`, la base du site ouvert (`/super-admin/sites/A/finance/dettes`).
 */
export { STAFF_DEBT_SITE_BASE, mapStaffDebtPath };

export const staffDebtContext = () => usePage().props?.staffDebtContext ?? null;

export const staffDebtUrl = (path) => mapStaffDebtPath(path, staffDebtContext()?.base ?? STAFF_DEBT_SITE_BASE);
