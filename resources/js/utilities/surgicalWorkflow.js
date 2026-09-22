/**
 * ADR-048 — le dossier du bloc se lit dans l'ordre de son workflow.
 *
 * Rien n'est décidé ici : le statut vient du serveur (SurgicalRequestStatus) et
 * les verrous sont exactement les transitions que le modèle refuse — programmer
 * avant le feu vert, feu vert avant le démarrage, démarrage avant le compte
 * rendu, compte rendu validé avant la sortie. Cet utilitaire dit seulement où
 * en est le dossier, ce qui vient ensuite, et pourquoi une étape n'est pas
 * encore ouverte. L'ordre des étapes est celui de l'ADR-048 ; il guide, il
 * n'interdit rien que le serveur ne refuse déjà.
 */

export const SURGERY_STATUS_ORDER = ['PENDING', 'SCHEDULED', 'PREOPERATIVE_VALIDATED', 'IN_PROGRESS', 'COMPLETED', 'DISCHARGED'];

/** Rang d'un statut dans le workflow ; -1 pour une demande annulée. */
export const statusRank = (status) => SURGERY_STATUS_ORDER.indexOf(status);

/** Une donnée clinique saisie, dans un objet ou une liste imbriqués. */
export const hasClinicalData = (value) => {
    if (Array.isArray(value)) return value.some(hasClinicalData);
    if (value && typeof value === 'object') return Object.values(value).some(hasClinicalData);

    return value !== null && value !== undefined && value !== '';
};

const CANCELLED_NOTE = 'Demande annulée : le dossier reste consultable, plus rien ne s’y enregistre.';

/**
 * Les cinq étapes Chirurgie, avec ce qui est fait et ce qu'on attend encore.
 * `waiting` n'empêche pas d'ouvrir l'étape : il dit ce qui doit se passer avant
 * que son action principale soit possible.
 */
export const surgerySteps = (request) => {
    const rank = statusRank(request.status);
    const cancelled = request.status === 'CANCELLED';
    const waiting = (condition, reason) => (cancelled ? CANCELLED_NOTE : (condition ? reason : null));

    return [
        {
            id: 'case',
            label: 'Dossier',
            description: 'Demande, programmation, équipe',
            complete: Boolean(request.surgeon && request.scheduled_at),
            waiting: cancelled ? CANCELLED_NOTE : null,
        },
        {
            id: 'preparation',
            label: 'Préparation',
            description: 'Anesthésie, feu vert, entrée au bloc',
            complete: Boolean(request.preoperative_validated_at && request.block_entry),
            waiting: waiting(rank < 1, 'Le feu vert se confirme une fois l’intervention programmée.'),
        },
        {
            id: 'intervention',
            label: 'Intervention',
            description: 'Acte opératoire et consommables',
            complete: Boolean(request.intervention?.ended_at),
            waiting: waiting(rank < 2 && !request.intervention, 'L’intervention démarre une fois le feu vert préopératoire confirmé.'),
        },
        {
            id: 'block-exit',
            label: 'Sortie du bloc',
            description: 'Réveil et surveillance postopératoire',
            complete: Boolean(request.block_exit),
            waiting: waiting(rank < 3, 'La sortie du bloc se renseigne une fois l’intervention démarrée.'),
        },
        {
            id: 'followup',
            label: 'Suivi & clôture',
            description: 'Compte rendu, complications et sortie',
            complete: request.status === 'DISCHARGED',
            waiting: waiting(rank < 3, 'Le suivi s’ouvre une fois l’intervention démarrée : compte rendu, puis sortie de Chirurgie.'),
        },
    ];
};

/**
 * La prochaine action du dossier, lue sur son statut réel.
 *
 * `permission` est le droit que le serveur exigera ; l'écran le compare aux
 * droits du compte pour dire, le cas échéant, qui peut la faire — jamais pour
 * l'autoriser à sa place.
 */
export const surgeryNextAction = (request) => {
    switch (request.status) {
        case 'CANCELLED':
            return { key: null, step: null, tone: 'muted', title: 'Demande annulée', detail: CANCELLED_NOTE, permission: null };
        case 'PENDING':
            return {
                key: 'schedule', step: 'case', tone: 'primary', permission: 'surgery.schedule',
                title: 'Programmer l’intervention',
                detail: 'Choisir le chirurgien et la date : le dossier passe « Programmée ».',
                cta: 'Programmer',
            };
        case 'SCHEDULED':
            return {
                key: 'preoperative', step: 'preparation', tone: 'primary', permission: 'surgery.preoperative.validate',
                title: 'Confirmer le feu vert préopératoire',
                detail: 'Observations de l’équipe chirurgicale, puis confirmation : l’intervention pourra démarrer.',
                cta: 'Ouvrir le feu vert',
            };
        case 'PREOPERATIVE_VALIDATED':
            return {
                key: 'start', step: 'intervention', tone: 'primary', permission: 'surgery.intervention.create',
                title: 'Démarrer l’intervention',
                detail: 'Opérateur et heure de début : le dossier passe « Au bloc ».',
                cta: 'Démarrer',
            };
        case 'IN_PROGRESS':
            if (!request.intervention?.ended_at) {
                return {
                    key: 'end', step: 'intervention', tone: 'warning', permission: 'surgery.intervention.update',
                    title: 'Terminer l’intervention',
                    detail: 'Heure de fin et résumé de l’acte réalisé.',
                    cta: 'Renseigner la fin',
                };
            }
            if (!request.block_exit) {
                return {
                    key: 'block-exit', step: 'block-exit', tone: 'warning', permission: 'surgery.intervention.update',
                    title: 'Renseigner la sortie du bloc',
                    detail: 'Horaires, paramètres et réveil, puis la surveillance postopératoire.',
                    cta: 'Ouvrir la sortie du bloc',
                };
            }
            if (!request.report) {
                return {
                    key: 'report', step: 'followup', tone: 'warning', permission: 'surgery.report.create',
                    title: 'Rédiger le compte rendu opératoire',
                    detail: 'Sa validation clôt l’intervention.',
                    cta: 'Rédiger',
                };
            }

            return {
                key: 'validate-report', step: 'followup', tone: 'warning', permission: 'surgery.report.validate',
                title: request.report.validated_at ? 'Compte rendu validé' : 'Valider le compte rendu opératoire',
                detail: 'La validation clôt l’intervention : le compte rendu ne se modifie plus.',
                cta: 'Relire et valider',
            };
        case 'COMPLETED':
            // La sortie de Chirurgie verrouille la sortie du bloc et la
            // surveillance postopératoire (le serveur ne les accepte plus
            // qu'« Au bloc » ou « Opéré ») : on les propose d'abord.
            if (!request.block_exit) {
                return {
                    key: 'block-exit', step: 'block-exit', tone: 'warning', permission: 'surgery.intervention.update',
                    title: 'Renseigner la sortie du bloc',
                    detail: 'Avant la sortie de Chirurgie : après elle, la sortie du bloc ne se renseigne plus.',
                    cta: 'Ouvrir la sortie du bloc',
                };
            }

            return {
                key: 'discharge', step: 'followup', tone: 'primary', permission: 'surgery.discharge.create',
                title: 'Enregistrer la sortie de Chirurgie',
                detail: 'Consignes de sortie : le dossier chirurgical est ensuite clôturé.',
                cta: 'Enregistrer la sortie',
            };
        case 'DISCHARGED':
            return { key: null, step: 'followup', tone: 'success', title: 'Dossier chirurgical clôturé', detail: 'La sortie de Chirurgie est enregistrée. Le dossier reste consultable.', permission: null };
        default:
            return { key: null, step: null, tone: 'muted', title: 'Statut inconnu', detail: '', permission: null };
    }
};

/** Les trois étapes Anesthésie, sur le dossier anesthésique partagé (ADR-048). */
export const anesthesiaSteps = (record) => [
    {
        id: 'consultation',
        label: 'Consultation',
        description: 'Antécédents et examen clinique',
        complete: hasClinicalData(record?.consultation_data),
        waiting: null,
    },
    {
        id: 'paraclinical',
        label: 'Paraclinique',
        description: 'Résultats, scores et décision',
        complete: Boolean(record?.assessment_validated_at),
        waiting: hasClinicalData(record?.consultation_data) ? null : 'La décision se prend après la consultation pré-anesthésique.',
    },
    {
        id: 'peroperative',
        label: 'Conduite anesthésique',
        description: 'Produits utilisés et observations',
        complete: Boolean(record?.validated_at),
        waiting: record?.assessment_validated_at ? null : 'Se renseigne en salle, après la validation de l’évaluation.',
    },
];

export const anesthesiaNextAction = (record) => {
    if (!hasClinicalData(record?.consultation_data)) {
        return {
            key: 'consultation', step: 'consultation', tone: 'primary', permission: record ? 'anesthesia.update' : 'anesthesia.create',
            title: 'Réaliser la consultation pré-anesthésique',
            detail: 'Antécédents, examen clinique et voies aériennes.',
            cta: 'Commencer',
        };
    }
    if (!record.assessment_validated_at) {
        return {
            key: 'assessment', step: 'paraclinical', tone: 'primary', permission: 'anesthesia.validate',
            title: 'Compléter le bilan et valider l’évaluation',
            detail: 'Résultats paracliniques, classe ASA et décision : la chirurgie est-elle autorisée ?',
            cta: 'Ouvrir le bilan',
        };
    }
    if (!record.validated_at) {
        return {
            key: 'peroperative', step: 'peroperative', tone: 'warning', permission: 'anesthesia.validate',
            title: 'Renseigner la conduite anesthésique',
            detail: 'Produits et techniques utilisés, observations, puis validation du dossier.',
            cta: 'Ouvrir la conduite',
        };
    }

    return { key: null, step: 'peroperative', tone: 'success', title: 'Dossier anesthésique validé', detail: 'Il reste consultable.', permission: null };
};

/**
 * L'étape où le dossier s'ouvre : celle de la prochaine action. Un dossier
 * clôturé se relit par sa fin ; une demande annulée, par sa demande.
 */
export const openingStep = (steps, next, cancelled = false) => next.step ?? (cancelled ? steps[0].id : steps[steps.length - 1].id);
