export function formatDate(value) {
    if (!value) {
        return null;
    }

    return new Date(value).toLocaleDateString('fr-FR');
}

export function formatDateTime(value) {
    if (!value) {
        return null;
    }

    return new Date(value).toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

// No "week" step: at exactly 7 days, dividing day→week would show "il y a 1
// semaine" instead of "il y a 7 jours" — days carry through to a month.
const RELATIVE_DIVISIONS = [
    { amount: 60, unit: 'second' },
    { amount: 60, unit: 'minute' },
    { amount: 24, unit: 'hour' },
    { amount: 30, unit: 'day' },
    { amount: 12, unit: 'month' },
    { amount: Number.POSITIVE_INFINITY, unit: 'year' },
];

// numeric: 'always' (not 'auto') — 'auto' substitutes idioms like "avant-hier"
// / "la semaine dernière" instead of the literal "il y a X jours" wanted here.
const relativeTimeFormatter = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'always' });

/** "il y a 7 jours", "il y a 3 heures", ... */
export function formatRelativeTime(value) {
    if (!value) {
        return null;
    }

    let duration = (new Date(value).getTime() - Date.now()) / 1000;

    for (const division of RELATIVE_DIVISIONS) {
        if (Math.abs(duration) < division.amount) {
            return relativeTimeFormatter.format(Math.round(duration), division.unit);
        }
        duration /= division.amount;
    }
}
