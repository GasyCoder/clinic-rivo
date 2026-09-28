/**
 * Adresses de « Désignations & tarifs », écrites une seule fois pour les deux
 * contextes du même écran (ADR-044, amendement du 2026-09-28 ter) :
 *
 *   site     /administration/catalog/...                 la base du site, lue directement
 *   portail  /super-admin/workspaces/tariffs/...          le site choisi, par son API
 *
 * Le mode vient du serveur (prop `context`), jamais deviné par l'écran.
 */
export const isPortalCatalog = (context) => context?.mode !== 'site';

const query = (params) => {
    const search = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') search.set(key, value);
    });
    const text = search.toString();

    return text ? `?${text}` : '';
};

export const catalogUrls = (context, siteCode = '') => {
    if (isPortalCatalog(context)) {
        const base = '/super-admin/workspaces/tariffs';
        const item = (uuid) => `${base}/items/${siteCode}/${uuid}`;

        return {
            index: (params = {}) => `${base}${query({ site: siteCode, ...params })}`,
            create: (module = null) => `${base}/items/create${query({ site: siteCode, module })}`,
            store: `${base}/items`,
            edit: (uuid, grid = null) => `${item(uuid)}/edit${grid ? `?grille=${grid}#tarifs` : ''}`,
            update: item,
            destroy: item,
            restore: (uuid) => `${item(uuid)}/restore`,
            tariff: (uuid) => `${item(uuid)}/tariffs`,
            tariffArchive: (uuid) => `${item(uuid)}/tariffs/archive`,
            careConsumables: (uuid) => `${item(uuid)}/care-consumables`,
            analyses: (uuid) => `/super-admin/analyses${query({ site: siteCode, catalog_item: uuid })}`,
        };
    }

    const base = '/administration/catalog';
    const item = (uuid) => `${base}/${uuid}`;

    return {
        index: (params = {}) => `${base}${query(params)}`,
        create: (module = null) => `${base}/create${query({ module })}`,
        store: base,
        edit: (uuid, grid = null) => `${item(uuid)}/edit${grid ? `?grille=${grid}#tarifs` : ''}`,
        update: item,
        destroy: item,
        restore: (uuid) => `${item(uuid)}/restore`,
        tariff: (uuid) => `${item(uuid)}/tariff`,
        tariffArchive: (uuid) => `${item(uuid)}/tariff/archive`,
        careConsumables: (uuid) => `${item(uuid)}/care-consumables`,
        analyses: (uuid) => `/administration/analyses${query({ catalog_item: uuid })}`,
        pendingReview: (id) => `${base}/pending-medicines/${id}/review`,
    };
};
