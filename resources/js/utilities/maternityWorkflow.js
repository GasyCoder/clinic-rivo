/**
 * ADR-204 — les deux parcours de la Maternité, écrits une fois.
 *
 * Une consultation prénatale et un accouchement ne se remplissent pas de la
 * même façon : chacun a ses étapes. « Suivant » facilite le flux, il ne
 * verrouille rien — toute étape reste atteignable depuis le stepper.
 *
 * Aucune règle clinique ici : seulement l'ordre des étapes, leur adresse dans
 * la page (`#interrogatoire`) et ce qui fait dire qu'une étape « est
 * renseignée ». Le serveur reste seul juge de ce qui s'enregistre.
 */

export const PRENATAL_STEPS = [
    { key: 'overview', hash: 'vue-ensemble', label: 'Vue d’ensemble', short: 'Vue' },
    { key: 'interview', hash: 'interrogatoire', label: 'Interrogatoire', short: 'Interrog.' },
    { key: 'examination', hash: 'examen', label: 'Examen', short: 'Examen' },
    { key: 'paraclinical', hash: 'paraclinique', label: 'Paraclinique', short: 'Paraclin.' },
    // ADR-205 — la sage-femme prescrit, comme le médecin : après les examens, avant la synthèse.
    { key: 'prescription', hash: 'ordonnance', label: 'Ordonnance', short: 'Ordonn.' },
    { key: 'summary', hash: 'synthese', label: 'Synthèse', short: 'Synthèse' },
    { key: 'appointment', hash: 'rendez-vous', label: 'Rendez-vous', short: 'RDV' },
];

export const DELIVERY_STEPS = [
    { key: 'admission', hash: 'admission', label: 'Admission', short: 'Admission' },
    { key: 'labor', hash: 'travail', label: 'Travail', short: 'Travail' },
    { key: 'monitoring', hash: 'surveillance', label: 'Surveillance', short: 'Surveill.' },
    { key: 'birth', hash: 'accouchement', label: 'Accouchement', short: 'Accouch.' },
    { key: 'newborn', hash: 'nouveau-ne', label: 'Nouveau-né', short: 'Bébé' },
    // ADR-205 — l'ordonnance de sortie de la mère (fer, antalgiques…), avant la transmission.
    { key: 'prescription', hash: 'ordonnance', label: 'Ordonnance', short: 'Ordonn.' },
    { key: 'transmission', hash: 'transmission', label: 'Transmission', short: 'Transm.' },
];

/** `PRENATAL` | `DELIVERY` → ses étapes ; un parcours inconnu n'a pas d'étapes. */
export function stepsFor(type) {
    if (type === 'PRENATAL') return PRENATAL_STEPS;
    if (type === 'DELIVERY') return DELIVERY_STEPS;

    return [];
}

export function stepIndex(steps, key) {
    return steps.findIndex((step) => step.key === key);
}

/** L'étape précédente et la suivante, `null` aux extrémités. */
export function neighbours(steps, key) {
    const index = stepIndex(steps, key);

    return {
        previous: index > 0 ? steps[index - 1] : null,
        next: index >= 0 && index < steps.length - 1 ? steps[index + 1] : null,
    };
}

/** L'étape désignée par l'adresse (`#examen`), sinon la première. */
export function stepFromHash(steps, hash) {
    const wanted = String(hash ?? '').replace(/^#/, '');

    return steps.find((step) => step.hash === wanted)?.key ?? steps[0]?.key ?? null;
}

/** Une valeur réellement saisie — un zéro l'est, une chaîne vide non. */
export function hasValue(value) {
    if (value === null || value === undefined || value === '' || value === false) return false;
    if (Array.isArray(value)) return value.some(hasValue);
    if (typeof value === 'object') return Object.values(value).some(hasValue);

    return true;
}

const pick = (source, keys) => keys.map((key) => source?.[key]);

/**
 * Une étape « renseignée » : ce que le passage y a réellement saisi.
 *
 * Les données longitudinales préremplies (DDR, DPA, G/P) ne rendent pas la
 * vue d'ensemble « renseignée » : seul le rattachement à une grossesse le fait.
 *
 * @param {string} key
 * @param {object} form        le formulaire du dossier
 * @param {object} context     { pregnancyLinked, procedures, labCount, imagingCount, prescriptionCount }
 */
export function stepFilled(key, form, context = {}) {
    const prenatal = form?.prenatal_data ?? {};
    const labor = form?.labor_data ?? {};

    switch (key) {
        case 'overview':
            return Boolean(context.pregnancyLinked || form?.pregnancy_choice);
        case 'interview':
            return hasValue([...pick(prenatal, ['visit_reason', 'visit_reason_details', 'reported_since_last', 'interval_notes']), form?.obstetric_context]);
        case 'examination':
            return hasValue(pick(prenatal, ['fundal_height_cm', 'fetal_heart_rate', 'fetal_movements', 'contractions', 'presentation', 'notes']));
        case 'paraclinical':
            return (context.labCount ?? 0) + (context.imagingCount ?? 0) > 0;
        case 'prescription':
            return (context.prescriptionCount ?? 0) > 0;
        case 'summary':
            return hasValue(pick(prenatal, ['clinical_summary', 'watch_points', 'plan'])) || (context.procedures ?? 0) > 0;
        case 'appointment':
            return Boolean(prenatal.next_appointment?.enabled && prenatal.next_appointment?.scheduled_at);
        case 'admission':
            return hasValue(form?.obstetric_context);
        case 'labor':
            return hasValue([labor.started_at, labor.cervical_dilation_cm, labor.contractions, labor.membranes_status === 'UNKNOWN' ? '' : labor.membranes_status]);
        case 'monitoring':
            return hasValue(labor.surveillance_notes);
        case 'birth':
            return hasValue(form?.delivery_data);
        case 'newborn':
            return hasValue(form?.newborn_data);
        case 'transmission':
            return hasValue([form?.maternal_care_notes, form?.observations, form?.transmission_notes]) || (context.procedures ?? 0) > 0;
        default:
            return false;
    }
}

/**
 * Une seule grossesse active, et c'est celle que la page montre : on la continue
 * depuis l'en-tête, sans seconde carte qui la répéterait (ADR-201, ADR-204).
 * Avec plusieurs grossesses actives, ou aucune, la carte de sélection décide.
 */
export function continuesSinglePregnancy(selectionRequired, activePregnancies = [], pregnancy = null) {
    return Boolean(selectionRequired
        && activePregnancies.length === 1
        && pregnancy?.uuid
        && pregnancy.uuid === activePregnancies[0].uuid);
}
