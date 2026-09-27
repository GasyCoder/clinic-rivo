import { reactive, readonly } from 'vue';

/**
 * Le chargement d'une page : pendant qu'Inertia va chercher la page suivante,
 * la mise en page montre un squelette à la place de l'ancienne (shadcn-vue).
 *
 * Seuls les vrais changements de page comptent — une visite GET qui ne garde
 * pas l'état de la page. Une recherche au fil de la frappe, un filtre, une
 * pagination qui garde l'état, un rechargement partiel ou un envoi de
 * formulaire ne remplacent jamais la page par un squelette.
 *
 * Le squelette n'apparaît qu'après un court délai : une page servie vite
 * s'affiche directement, sans clignotement.
 */
const state = reactive({ active: false, path: '' });

let timer = null;
let pendingHref = null;

export const SKELETON_DELAY_MS = 200;

export const isPageVisit = (visit) => Boolean(visit)
    && visit.method === 'get'
    && ! visit.preserveState
    && ! (visit.only?.length)
    && ! visit.prefetch
    && ! visit.async;

export function installPageLoading(router, delay = SKELETON_DELAY_MS) {
    if (typeof window === 'undefined') return;

    router.on('start', (event) => {
        const visit = event.detail?.visit;

        if (! isPageVisit(visit)) return;

        clearTimeout(timer);
        pendingHref = visit.url?.href ?? null;

        timer = setTimeout(() => {
            state.path = visit.url?.pathname ?? '';
            state.active = true;

            if (! visit.preserveScroll) window.scrollTo({ top: 0 });
        }, delay);
    });

    router.on('finish', (event) => {
        // La fin d'une visite remplacée par une autre ne doit pas effacer le
        // squelette de celle qui est encore en cours.
        const href = event.detail?.visit?.url?.href ?? null;

        if (pendingHref !== null && href !== null && href !== pendingHref) return;

        clearTimeout(timer);
        timer = null;
        pendingHref = null;
        state.active = false;
    });
}

export function usePageLoading() {
    return readonly(state);
}
