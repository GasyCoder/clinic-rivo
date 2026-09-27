import { usePage } from '@inertiajs/vue3';

/**
 * ADR-211 — l'adresse du module Partenaires, où qu'il soit ouvert.
 *
 * Le même écran vit sur le site (`/partenaires`) et sur le portail, qui le sert
 * pour un site précis (`/super-admin/sites/A/partenaires`) et fournit sa base
 * dans `partnersContext`. Même principe que `hrUrl` et `pharmacyUrl`.
 */
export const PARTNERS_SITE_BASE = '/partenaires';

/** `suffix` : « /uuid », « /uuid/restore »… ; vide pour la liste. */
export const partnersPath = (suffix = '', base = PARTNERS_SITE_BASE) => {
    const tail = String(suffix ?? '').replace(/^\/+/, '');

    return tail ? `${base}/${tail}` : base;
};

export const partnersContext = () => usePage().props?.partnersContext ?? null;

export const partnerUrl = (suffix = '') => partnersPath(suffix, partnersContext()?.base ?? PARTNERS_SITE_BASE);
