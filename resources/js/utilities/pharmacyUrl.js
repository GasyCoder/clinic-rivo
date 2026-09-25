import { usePage } from '@inertiajs/vue3';
import { PHARMACY_SITE_BASE, mapPharmacyPath } from './pharmacyPath.js';

/**
 * ADR-189 — l'adresse d'un écran de la Pharmacie, où qu'il soit affiché.
 *
 * Les écrans sont les mêmes sur le site (`/pharmacy/...`) et sur le portail,
 * qui les sert pour un site précis (`/super-admin/sites/A/pharmacie/...`).
 * Chaque écran écrit son adresse telle qu'elle est sur le site ;
 * `pharmacyUrl` la ramène à la base où l'écran est réellement ouvert, que le
 * portail fournit dans `pharmacyContext`.
 */
export { PHARMACY_SITE_BASE, mapPharmacyPath };

export const pharmacyContext = () => usePage().props?.pharmacyContext ?? null;

export const pharmacyUrl = (path) => mapPharmacyPath(path, pharmacyContext()?.base ?? PHARMACY_SITE_BASE);

/**
 * Les actes physiques — délivrer, servir un consommable, entrer en stock,
 * compter l'inventaire, ajuster, réceptionner, imprimer le ticket — restent
 * au site (ADR-098). Sur le portail, leurs boutons sont montrés verrouillés
 * avec cette raison ; le site les refuse de toute façon (`rivo.site-only`).
 */
export const SITE_ONLY_REASON = 'Ce geste se fait à la Pharmacie du site, par la personne qui a les produits en main.';

export const onPharmacyPortal = () => Boolean(pharmacyContext());
