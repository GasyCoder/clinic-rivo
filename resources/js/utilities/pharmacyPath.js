/**
 * ADR-189 — une adresse de la Pharmacie ramenée à la base où l'écran est
 * ouvert : le site (`/pharmacy`) ou, sur le portail, la Pharmacie d'un site
 * (`/super-admin/sites/A/pharmacie`). Sans dépendance, pour être testée seule.
 */
export const PHARMACY_SITE_BASE = '/pharmacy';

/** `/pharmacy/stock` → `${base}/stock` ; tout autre chemin est inchangé. */
export const mapPharmacyPath = (path, base) => {
    const value = String(path ?? '');

    if (! base || base === PHARMACY_SITE_BASE) return value;

    return value.replace(/^\/pharmacy(?=[/?#]|$)/, base);
};
