import { usePage } from '@inertiajs/vue3';
import { LAB_SITE_BASE, mapLabPath } from './labPath.js';

/**
 * ADR-215 — l'adresse d'un écran du Laboratoire, où qu'il soit affiché.
 *
 * Les écrans sont les mêmes sur le site (`/laboratory/...`) et sur le portail,
 * qui les sert pour un site précis (`/super-admin/sites/A/laboratoire/...`).
 * Chaque écran écrit son adresse telle qu'elle est sur le site ; `labUrl` la
 * ramène à la base où l'écran est réellement ouvert, que le portail fournit
 * dans `laboratoryContext`.
 */
export { LAB_SITE_BASE, mapLabPath };

export const laboratoryContext = () => usePage().props?.laboratoryContext ?? null;

export const labUrl = (path) => mapLabPath(path, laboratoryContext()?.base ?? LAB_SITE_BASE);

/**
 * Les gestes cliniques — réceptionner, prélever, saisir, terminer, valider,
 * renvoyer, signaler un critique, confier à l'extérieur, conclure — restent au
 * laboratoire du site. Sur le portail, leurs boutons sont montrés verrouillés
 * avec cette raison ; le site les refuse de toute façon (`rivo.site-only`).
 */
export const LAB_SITE_ONLY_REASON = 'Ce geste se fait au laboratoire du site, par la personne qui a le prélèvement sous les yeux.';

export const onLabPortal = () => Boolean(laboratoryContext());
