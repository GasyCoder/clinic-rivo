/**
 * Adresses du catalogue des analyses, écrites une seule fois pour les deux
 * contextes du même écran (ADR-063, amendement du 2026-09-28) :
 *
 *   site     /administration/analyses/...          la base du site, lue directement
 *   portail  /super-admin/analyses/{site}/...      le site choisi, par son API
 *
 * Le mode vient du serveur (prop `context`), jamais deviné par l'écran.
 */
export const isPortalContext = (context) => context?.mode !== 'site';

export const analysisCatalogUrls = (context, siteCode = '') => {
    const portal = isPortalContext(context);
    const base = portal ? '/super-admin/analyses' : '/administration/analyses';
    const scoped = portal ? `${base}/${siteCode}` : base;

    return {
        index: base,
        store: base,
        create: `${scoped}/create`,
        edit: (uuid) => `${scoped}/${uuid}/edit`,
        update: (uuid) => `${scoped}/${uuid}`,
        toggle: (uuid, active) => `${scoped}/${uuid}/${active ? 'deactivate' : 'activate'}`,
        import: `${base}/import`,
        template: `${base}/import-template`,
        export: (status) => (portal
            ? `${base}/export?site_code=${encodeURIComponent(siteCode)}&status=${encodeURIComponent(status)}`
            : `${base}/export?status=${encodeURIComponent(status)}`),
    };
};
