import { usePage } from '@inertiajs/vue3';
import { HR_SITE_BASE, mapHrPath } from './hrPath.js';

/**
 * ADR-182 — l'adresse d'un écran RH, où qu'il soit affiché.
 *
 * Les écrans RH sont les mêmes sur le site (`/administration/...`) et sur le
 * portail, qui les sert pour un site précis (`/super-admin/sites/A/rh/...`).
 * Chaque écran écrit son adresse telle qu'elle est sur le site ; `hrUrl` la
 * ramène à la base où l'écran est réellement ouvert, que le portail fournit
 * dans `hrContext`.
 */
export { HR_SITE_BASE, mapHrPath };

export const hrContext = () => usePage().props?.hrContext ?? null;

export const hrUrl = (path) => mapHrPath(path, hrContext()?.base ?? HR_SITE_BASE);

/** Le site dont on gère le personnel : celui choisi sur le portail, sinon le site courant. */
export const hrSiteName = () => hrContext()?.site?.name ?? usePage().props?.site?.name ?? '';
