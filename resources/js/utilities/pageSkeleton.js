/**
 * La forme du squelette de chargement d'une page, lue sur l'adresse visée.
 *
 * Inertia ne connaît la page d'arrivée qu'avec la réponse : c'est donc
 * l'adresse qui dit à quoi elle ressemblera — un tableau de bord, une liste,
 * une fiche, un formulaire, un document imprimable ou un écran de réglages.
 * Une adresse inconnue reçoit la forme la plus courante, la liste.
 */
export const SKELETON_KINDS = ['dashboard', 'list', 'detail', 'form', 'document', 'settings'];

const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;

/** Les dernières étapes d'adresse qui ouvrent une feuille à imprimer. */
const DOCUMENT_SEGMENTS = new Set([
    'print', 'impression', 'dossier-medical', 'dossiers-medicaux', 'journal', 'journaux-de-traitement',
    'ticket', 'dossier', 'fiche', 'fiches', 'tour-de-salle', 'regimes',
]);

const FORM_SEGMENTS = new Set(['create', 'edit', 'nouveau', 'modifier']);

const SETTINGS_PATHS = ['/super-admin/settings', '/profil', '/super-admin/workspaces/roles'];

export function skeletonFor(pathname) {
    const path = String(pathname ?? '/').split('?')[0].replace(/\/+$/, '') || '/';
    const segments = path.split('/').filter(Boolean);
    const last = segments.at(-1) ?? '';

    if (path === '/' || path === '/super-admin' || last === 'dashboard') return 'dashboard';
    if (DOCUMENT_SEGMENTS.has(last)) return 'document';
    if (FORM_SEGMENTS.has(last) || segments.includes('prise-en-charge')) return 'form';
    if (SETTINGS_PATHS.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) return 'settings';
    if (UUID.test(path) || segments.some((segment) => /^\d+$/.test(segment))) return 'detail';

    return 'list';
}
