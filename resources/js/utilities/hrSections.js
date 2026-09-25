import { CLINIC_WORKSPACES } from './clinicWorkspaces.js';
import { mapHrPath } from './hrPath.js';

/**
 * ADR-182 — les rubriques de l'espace RH d'un site (Employés, Contrats…), une
 * seule liste : celle du menu RH du site. Sur le portail, chaque adresse est
 * ramenée à l'espace RH du site choisi, et chaque rubrique garde son droit.
 */
export const hrSections = (base, can) => (CLINIC_WORKSPACES.find((workspace) => workspace.key === 'hr')?.children ?? [])
    .filter((section) => ! section.permission || can(section.permission))
    .map((section) => ({
        code: section.code,
        label: section.label,
        icon: section.icon,
        href: mapHrPath(section.link, base),
        prefixes: (section.activeLinks ?? []).map((link) => mapHrPath(link, base)),
    }));
