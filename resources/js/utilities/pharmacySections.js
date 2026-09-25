import { CLINIC_WORKSPACES } from './clinicWorkspaces.js';
import { mapPharmacyPath } from './pharmacyPath.js';

/**
 * ADR-189 — les rubriques de la Pharmacie d'un site (Ordonnances, Stock,
 * Achats…), une seule liste : celle du menu Pharmacie du site. Sur le portail,
 * chaque adresse est ramenée à la Pharmacie du site choisi, et chaque rubrique
 * garde son droit.
 */
export const pharmacySections = (base, can) => (CLINIC_WORKSPACES.find((workspace) => workspace.key === 'pharmacy')?.children ?? [])
    .filter((section) => (section.anyPermission
        ? section.anyPermission.some((permission) => can(permission))
        : ! section.permission || can(section.permission)))
    .map((section) => ({
        code: section.code,
        label: section.label,
        icon: section.icon,
        href: mapPharmacyPath(section.link, base),
        prefixes: (section.activeLinks ?? []).map((link) => mapPharmacyPath(link, base)),
    }));
