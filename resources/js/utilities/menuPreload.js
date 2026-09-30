import { router } from '@inertiajs/vue3';

/**
 * Préparer une page du menu avant le clic (ADR-195, amendement du 2026-09-30).
 *
 * Une entrée peut déclarer `warm` : `component` charge le code de sa page (Inertia ne
 * précharge que les données), `data` demande aussi la page elle-même en arrière-plan,
 * gardée 30 s — le clic la trouve prête. Au survol, le lien s'en charge (`prefetch`) ;
 * au clavier et au toucher, il n'y a pas de survol : c'est ici.
 *
 * Réservé aux pages lentes à ouvrir : la messagerie attend le serveur de messagerie.
 */
export function warmMenuItem(item, { data = false } = {}) {
    const warm = item?.warm;
    if (!warm) return;

    warm.component?.();

    if (data && warm.data && item.link) {
        router.prefetch(item.link, {}, { cacheFor: '30s', cacheTags: warm.cacheTags ?? [] });
    }
}

/** Les attributs du lien d'une entrée : le préchargement au survol, s'il est demandé. */
export function menuLinkPrefetch(item) {
    return item?.warm?.data ? { prefetch: 'hover', cacheTags: item.warm.cacheTags ?? [] } : {};
}
