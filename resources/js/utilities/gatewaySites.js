/**
 * ADR-184 (amendement du 2026-10-02) — ce que la passerelle (rivo.mg) affiche
 * pour chaque site et pour le logo central, à partir de l'identité publique
 * que chaque déploiement sert (`/branding/identity`), lue par le serveur.
 *
 * `branding` vaut `undefined` tant que la lecture n'est pas revenue (prop
 * différée) : la page montre alors une place réservée, jamais un faux logo.
 */

export const SITE_STATUS = {
    online: { label: 'Disponible', tone: 'success' },
    maintenance: { label: 'En maintenance', tone: 'warning' },
    unavailable: { label: 'Indisponible', tone: 'neutral' },
};

/** L'identité d'un site, ou `null` pendant le chargement. */
export const identityOf = (branding, code) => {
    if (!branding) {
        return null;
    }

    return branding.clinics?.[code] ?? { status: 'unavailable', logo_url: null, icon_url: null };
};

/** L'état affiché d'un site ; `null` pendant le chargement. */
export const statusOf = (identity) => (identity ? (SITE_STATUS[identity.status] ?? SITE_STATUS.unavailable) : null);

/** Le logo d'un site : celui qu'il a réglé, sinon son icône, sinon rien (une icône de bâtiment le remplace). */
export const siteLogoOf = (identity, broken = new Set()) => {
    for (const url of [identity?.logo_url, identity?.icon_url]) {
        if (url && !broken.has(url)) {
            return url;
        }
    }

    return null;
};

/**
 * Le logo central : celui du portail ; s'il ne répond pas, celui de la
 * passerelle elle-même ; sinon les initiales.
 */
export const centralLogoOf = (branding, ownLogo, broken = new Set()) => {
    for (const url of [branding?.central?.logo_url, ownLogo]) {
        if (url && !broken.has(url)) {
            return url;
        }
    }

    return null;
};
