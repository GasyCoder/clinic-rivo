/** first_name is optional on Patient — avoids a dangling trailing space when absent. */
export function formatPatientName(patient) {
    if (!patient) return 'Patient indisponible';

    return [patient.last_name, patient.first_name].filter(Boolean).join(' ');
}

export function formatPatientInitials(patient) {
    if (!patient) return '?';

    return [patient.first_name, patient.last_name]
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}
