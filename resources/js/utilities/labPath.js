/**
 * ADR-215 — une adresse du Laboratoire ramenée à la base où l'écran est ouvert :
 * le site (`/laboratory`) ou, sur le portail, le Laboratoire d'un site
 * (`/super-admin/sites/A/laboratoire`). Sans dépendance, pour être testée seule.
 */
export const LAB_SITE_BASE = '/laboratory';

/** `/laboratory/rapports` → `${base}/rapports` ; tout autre chemin est inchangé. */
export const mapLabPath = (path, base) => {
    const value = String(path ?? '');

    if (! base || base === LAB_SITE_BASE) return value;

    return value.replace(/^\/laboratory(?=[/?#]|$)/, base);
};
