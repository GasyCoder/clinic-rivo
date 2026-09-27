/**
 * ADR-187 — une adresse RH ramenée à la base où l'écran est ouvert : le site
 * (`/administration`) ou, sur le portail, l'espace RH d'un site
 * (`/super-admin/sites/A/rh`). Sans dépendance, pour être testée seule.
 */
export const HR_SITE_BASE = '/administration';

/** `/administration/employees` → `${base}/employees` ; tout autre chemin est inchangé. */
export const mapHrPath = (path, base) => {
    const value = String(path ?? '');

    if (! base || base === HR_SITE_BASE) return value;

    return value.replace(/^\/administration(?=[/?#]|$)/, base);
};
