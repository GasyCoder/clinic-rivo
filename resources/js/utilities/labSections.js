import { CLINIC_WORKSPACES, SIDEBAR_GROUPS } from './clinicWorkspaces.js';
import { mapLabPath } from './labPath.js';

/**
 * ADR-215 — les rubriques du Laboratoire d'un site (Paillasse, Feuille de
 * paillasse, Rapports, Prélèvements & tubes, Germes & antibiotiques), une seule
 * liste : celle du menu Laboratoire du site. Sur le portail, chaque adresse est
 * ramenée au Laboratoire du site choisi, et chaque rubrique garde son droit.
 */
export const labSections = (base, can) => {
    const group = SIDEBAR_GROUPS.find((entry) => entry.key === 'laboratory-space');

    return (group?.members ?? [])
        .map((key) => CLINIC_WORKSPACES.find((workspace) => workspace.key === key))
        .filter((workspace) => workspace && (! workspace.permission || can(workspace.permission)))
        .map((workspace) => ({
            code: workspace.key,
            label: group.labels?.[workspace.key] ?? workspace.text,
            icon: workspace.icon,
            href: mapLabPath(workspace.link, base),
        }));
};

/**
 * La rubrique de la page ouverte : l'adresse la plus longue qui la contient.
 * La Paillasse (la racine) couvre une demande ou l'historique d'un patient ;
 * une rubrique plus précise l'emporte toujours sur elle.
 */
export const activeLabSection = (sections, path) => sections
    .filter((section) => path === section.href || path.startsWith(`${section.href}/`))
    .sort((a, b) => b.href.length - a.href.length)[0]?.code ?? null;
