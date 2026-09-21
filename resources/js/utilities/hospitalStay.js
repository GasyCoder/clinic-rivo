/**
 * ADR-113 / ADR-165 — la durée d'un séjour, lue de la même façon partout : la
 * liste des hospitalisés et la feuille de tour de salle ne doivent pas compter
 * deux durées différentes pour le même patient.
 *
 * Jours révolus depuis l'admission, jusqu'à la sortie si elle a eu lieu.
 */
export const stayDays = (stay, now = new Date()) => {
    const end = stay.discharged_at ? new Date(stay.discharged_at) : now;
    const days = Math.floor((end - new Date(stay.admitted_at)) / 86400000);

    return days < 1 ? 'Moins d’un jour' : `${days} jour${days > 1 ? 's' : ''}`;
};

/** « 120/80 », ou « — » tant que la paire n'est pas complète. */
export const bloodPressure = (reading) => (reading?.blood_pressure_systolic && reading?.blood_pressure_diastolic
    ? `${reading.blood_pressure_systolic}/${reading.blood_pressure_diastolic}`
    : '—');
