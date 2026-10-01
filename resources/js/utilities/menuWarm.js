/**
 * Les entrées du menu préparées avant le clic (utilities/menuPreload.js). Sans
 * import : ce fichier est lu aussi par les tests du menu, hors de Vite.
 */

/** L'entrée « Messagerie » : la boîte s'ouvre le temps de survoler le lien (ADR-195, 2026-09-30). */
export const WEBMAIL_MENU_WARM = Object.freeze({
    component: () => import('@/Pages/Webmail/Index.vue'),
    data: true,
    cacheTags: ['webmail'],
});
