export function formatDate(value) {
    if (!value) {
        return null;
    }

    return new Date(value).toLocaleDateString('fr-FR');
}

/** L'heure seule — un statut de sauvegarde n'a pas besoin de la date du jour. */
export function formatTime(value) {
    if (!value) {
        return null;
    }

    return new Date(value).toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * « 15 sept. 2026 à 16:09 » — la forme lisible d'un en-tête clinique, où le
 * mois abrégé lève l'ambiguïté jour/mois que `15/09` laisse planer.
 */
export function formatDayTime(value) {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return `${date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })} à ${date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
}

/**
 * Valeur d'un champ `datetime-local` (« AAAA-MM-JJTHH:mm », sans fuseau ni
 * secondes), à l'heure locale — comme `formatDateTime` l'affiche.
 *
 * Une date sérialisée par Laravel est en UTC (« …T06:00:00.000000Z ») :
 * découper la chaîne donnerait 06:00 là où l'écran affiche 09:00, et une
 * correction enregistrerait l'heure décalée.
 */
export function toDatetimeLocalInput(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? ''
        : new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
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
export function formatRelativeTime(value, now = Date.now()) {
    if (!value) {
        return null;
    }

    let duration = (new Date(value).getTime() - new Date(now).getTime()) / 1000;

    for (const division of RELATIVE_DIVISIONS) {
        if (Math.abs(duration) < division.amount) {
            return relativeTimeFormatter.format(Math.round(duration), division.unit);
        }
        duration /= division.amount;
    }
}

/**
 * Depuis quand date une mesure, **seulement si elle n'est pas du jour** :
 * « il y a 2 mois ». Des constantes relevées la veille, ou lors d'un passage
 * ouvert depuis des semaines, ne sont pas celles d'aujourd'hui ; celles du
 * jour n'ont besoin que de leur heure. `null` sans date lisible.
 */
export function olderThanToday(value, now = new Date()) {
    if (!value) {
        return null;
    }

    const at = new Date(value);
    const today = new Date(now);

    if (Number.isNaN(at.getTime())) {
        return null;
    }

    const sameDay = at.getFullYear() === today.getFullYear()
        && at.getMonth() === today.getMonth()
        && at.getDate() === today.getDate();

    return sameDay ? null : formatRelativeTime(at, today);
}

/**
 * AAAA-MM-JJ dans le fuseau du poste, pour un champ date.
 *
 * Jamais `toISOString().slice(0, 10)` : il passe en UTC, et à Madagascar
 * (UTC+3) il donne la veille entre minuit et 3 h — et un minuit local
 * (« AAAA-MM-JJT00:00:00 ») y recule toujours d'un jour.
 */
export function toLocalDateInput(date = new Date()) {
    const pad = (number) => String(number).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function localToday() {
    return toLocalDateInput(new Date());
}

/** « 2026-09 » décalé de `offset` mois, en « AAAA-MM ». */
export function shiftMonth(month, offset) {
    const [year, number] = String(month).split('-').map(Number);
    const date = new Date(Date.UTC(year, number - 1 + offset, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** « 2026-09 » → « septembre 2026 ». */
export function monthLabel(month) {
    const [year, number] = String(month).split('-').map(Number);
    if (! year || ! number) return String(month ?? '');

    return new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(year, number - 1, 1)));
}
