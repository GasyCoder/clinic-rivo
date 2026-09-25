import { CalendarClock, SlidersHorizontal, Users } from 'lucide-vue-next';
import { CLINIC_WORKSPACES } from './clinicWorkspaces.js';
import { mapHrPath } from './hrPath.js';

/**
 * ADR-187 — les rubriques de l'espace RH d'un site (Employés, Contrats…), une
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

/**
 * Les rubriques RH rangées par thème : l'accueil RH en fait ses colonnes, la barre
 * RH du portail ses deux niveaux. L'accueil RH (`hr-home`) n'appartient à aucun
 * thème ; toute autre rubrique en a un, et un seul.
 */
export const HR_SECTION_GROUPS = [
    { key: 'people', label: 'Personnel', icon: Users, codes: ['hr-employees', 'hr-contracts', 'hr-internships', 'hr-documents', 'hr-professional-emails'] },
    { key: 'time', label: 'Temps de travail', icon: CalendarClock, codes: ['hr-attendance', 'hr-leave', 'hr-planning'] },
    { key: 'steering', label: 'Pilotage', icon: SlidersHorizontal, codes: ['hr-reports', 'hr-block-credit', 'hr-departments', 'hr-job-titles', 'hr-settings'] },
];

export const HR_HOME_CODE = 'hr-home';

/**
 * Les thèmes, chacun avec les seules rubriques que ce compte peut ouvrir (le
 * résultat de `hrSections`) ; un thème vide disparaît. Une rubrique ajoutée au
 * menu RH sans thème rejoint le dernier plutôt que de disparaître de la barre.
 */
export const groupHrSections = (sections) => {
    const grouped = new Set(HR_SECTION_GROUPS.flatMap((group) => group.codes));
    const orphans = sections.filter((section) => section.code !== HR_HOME_CODE && ! grouped.has(section.code));

    return HR_SECTION_GROUPS
        .map((group, index) => ({
            ...group,
            sections: [
                ...group.codes.map((code) => sections.find((section) => section.code === code)).filter(Boolean),
                ...(index === HR_SECTION_GROUPS.length - 1 ? orphans : []),
            ],
        }))
        .filter((group) => group.sections.length > 0);
};
