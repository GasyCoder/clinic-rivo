/**
 * ADR-229 — une adresse des dettes du personnel ramenée à la base où l'écran est ouvert.
 * Ces écrans n'existent qu'au portail, pour un site (`/super-admin/sites/A/finance/dettes`) ;
 * chaque écran écrit son adresse sous la racine de convention `/finance/dettes`. Sans
 * dépendance, pour être testée seule.
 */
export const STAFF_DEBT_SITE_BASE = '/finance/dettes';

/** `/finance/dettes/reglages` → `${base}/reglages` ; tout autre chemin est inchangé. */
export const mapStaffDebtPath = (path, base) => {
    const value = String(path ?? '');

    if (! base || base === STAFF_DEBT_SITE_BASE) return value;

    return value.replace(/^\/finance\/dettes(?=[/?#]|$)/, base);
};
