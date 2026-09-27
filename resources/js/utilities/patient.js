/** first_name is optional on Patient — avoids a dangling trailing space when absent. */
export function formatPatientName(patient) {
    if (!patient) return 'Patient indisponible';

    return [patient.last_name, patient.first_name].filter(Boolean).join(' ');
}

/**
 * Only MR/MRS read naturally in front of a name; GIRL/BOY stay a separate
 * detail (civility_label) rather than becoming "Enfant fille RAKOTO Jeanne".
 */
const CIVILITY_NAME_PREFIXES = { MR: 'M.', MRS: 'Mme' };

export function formatPatientCivilName(patient) {
    if (!patient) return 'Patient indisponible';

    return [CIVILITY_NAME_PREFIXES[patient.civility], formatPatientName(patient)]
        .filter(Boolean)
        .join(' ');
}

/** Declared age and computed age are two different facts — never conflated. */
export function formatPatientAge(patient) {
    if (patient?.age === null || patient?.age === undefined) return null;

    return `${patient.age} an${patient.age > 1 ? 's' : ''}${patient.birth_date ? '' : ' (déclaré)'}`;
}

export function formatPatientBirthDate(patient) {
    if (!patient?.birth_date) return null;

    const formatted = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' })
        .format(new Date(`${patient.birth_date}T00:00:00`));

    return patient.birth_date_is_approximate ? `${formatted} (approximative)` : formatted;
}

export function formatPatientInitials(patient) {
    if (!patient) return '?';

    return [patient.first_name, patient.last_name]
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}
