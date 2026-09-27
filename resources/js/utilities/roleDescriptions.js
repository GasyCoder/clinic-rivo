/**
 * Ce que fait chaque rôle livré avec l'application, en une phrase (ADR-178).
 *
 * La table `roles` n'a pas de description : un rôle n'y est qu'un code et un
 * libellé. Pour les métiers définis par les décisions du projet (ADR-006,
 * ADR-025, ADR-026, ADR-033), la phrase reprend ce que ces décisions disent de
 * leur périmètre — elle ne décide de rien, le socle seul donne des droits. Un
 * rôle créé depuis le portail n'a pas de phrase : l'écran décrit alors ce que
 * son socle couvre réellement, calculé sur ses permissions.
 */
export const ROLE_DESCRIPTIONS = {
    SUPER_ADMIN: 'Portail central : toutes les permissions sur le portail, aucune sur un site. Il ne se règle jamais ici.',
    ADMINISTRATION: 'Ressources humaines : employés, contrats, présences, congés, planning et rapports RH.',
    RECEPTION: 'Accueil des patients, passages, caisse et sorties administratives.',
    MEDICINE: 'Consultations, diagnostics, ordonnances, orientations et sorties médicales.',
    NURSE: 'Soins infirmiers et constantes ; profils Infirmier, Sage-femme et Anesthésiste.',
    SURGERY: 'Bloc opératoire : programmation, intervention, compte rendu et suivi.',
    PHARMACY: 'Délivrance, stock, lots, péremptions et réceptions de la pharmacie.',
    LABORATORY: 'Analyses : demandes, résultats et catalogue du laboratoire.',
    LOGISTICS: 'Équipements, affectations, maintenance et stock administratif.',
    SUPPORT: 'Gardiennage et entretien ; les tâches se donnent au compte selon son profil.',
    MAINTENANCE: 'Technique et informatique ; les tâches se donnent au compte selon son profil.',
};

export const roleDescription = (code) => ROLE_DESCRIPTIONS[String(code ?? '').toUpperCase()] ?? '';

/** « AN » pour « Anesthésie », « AR » pour « Administration / RH » : repère visuel de la liste. */
export const roleInitials = (name) => {
    const words = String(name ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .split(/[^A-Za-z0-9]+/)
        .filter(Boolean);

    if (words.length === 0) return '?';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();

    return `${words[0][0]}${words[1][0]}`.toUpperCase();
};
