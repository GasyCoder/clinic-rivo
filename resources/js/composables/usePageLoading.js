import { reactive, readonly } from 'vue';

/**
 * Le chargement d'une page : pendant qu'Inertia va chercher la page suivante,
 * la mise en page montre un squelette Boneyard à la place de l'ancienne.
 *
 * Seuls les vrais changements de page comptent — une visite GET qui ne garde
 * pas l'état de la page. Une recherche au fil de la frappe, un filtre, une
 * pagination qui garde l'état, un rechargement partiel ou un envoi de
 * formulaire ne remplacent jamais la page par un squelette.
 *
 * Le squelette apparaît dès le clic et reste assez longtemps pour être vu,
 * même quand la page répond vite (ADR-185, amendement du 2026-10-01).
 *
 * Jamais au premier affichage : la page est déjà rendue par le serveur, et
 * poser `active` avant l'hydratation ferait diverger le rendu du client de
 * celui du serveur.
 */
const state = reactive({ active: false, path: '' });

let timer = null;
let hideTimer = null;
let pendingHref = null;
let visibleSince = 0;

export const SKELETON_DELAY_MS = 0;
export const SKELETON_MIN_VISIBLE_MS = 450;

export const isPageVisit = (visit) => Boolean(visit)
    && visit.method === 'get'
    && ! visit.preserveState
    && ! (visit.only?.length)
    && ! visit.prefetch
    && ! visit.async;

const show = (path) => {
    clearTimeout(hideTimer);
    hideTimer = null;
    state.path = path;

    if (! state.active) visibleSince = Date.now();
    state.active = true;
};

const hide = (minimumVisible) => {
    clearTimeout(hideTimer);

    const remaining = Math.max(0, minimumVisible - (Date.now() - visibleSince));
    hideTimer = setTimeout(() => {
        state.active = false;
        hideTimer = null;
    }, remaining);
};

export function installPageLoading(router, {
    delay = SKELETON_DELAY_MS,
    minimumVisible = SKELETON_MIN_VISIBLE_MS,
} = {}) {
    if (typeof window === 'undefined') return;

    router.on('start', (event) => {
        const visit = event.detail?.visit;

        if (! isPageVisit(visit)) return;

        clearTimeout(timer);
        clearTimeout(hideTimer);
        hideTimer = null;
        pendingHref = visit.url?.href ?? null;

        const begin = () => {
            show(visit.url?.pathname ?? '');

            if (! visit.preserveScroll) window.scrollTo({ top: 0 });
        };

        if (delay > 0) timer = setTimeout(begin, delay);
        else begin();
    });

    router.on('finish', (event) => {
        // La fin d'une visite remplacée par une autre ne doit pas effacer le
        // squelette de celle qui est encore en cours.
        const href = event.detail?.visit?.url?.href ?? null;

        if (pendingHref !== null && href !== null && href !== pendingHref) return;

        clearTimeout(timer);
        timer = null;
        pendingHref = null;

        if (state.active) hide(minimumVisible);
    });
}

export function usePageLoading() {
    return readonly(state);
}
