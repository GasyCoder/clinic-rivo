/**
 * ADR-193 — la maintenance d'un site, telle que les écrans la disent : la page de
 * maintenance du site, le bandeau d'avertissement et le module du portail lisent
 * les mêmes phrases, écrites une fois.
 *
 * Les dates arrivent du serveur en ISO 8601 (avec leur décalage) et s'affichent à
 * l'heure du poste ; un champ « date et heure » s'écrit « AAAA-MM-JJTHH:mm », à
 * l'heure du poste lui aussi.
 */

export const MAINTENANCE_STATES = Object.freeze({
    ACTIVE: { label: 'En cours', variant: 'destructive' },
    UPCOMING: { label: 'Programmée', variant: 'warning' },
    ENDED: { label: 'Terminée', variant: 'outline' },
    LIFTED: { label: 'Levée', variant: 'outline' },
});

/** Les fins proposées d'un clic, en minutes après le début. */
export const MAINTENANCE_DURATIONS = Object.freeze([
    { minutes: 30, label: '30 min' },
    { minutes: 60, label: '1 h' },
    { minutes: 120, label: '2 h' },
    { minutes: 240, label: '4 h' },
]);

const pad = (value) => String(value).padStart(2, '0');

const toDate = (value) => {
    if (!value) return null;
    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
};

/** « AAAA-MM-JJTHH:mm » à l'heure du poste, comme un champ `datetime-local`. */
export function toLocalInput(value) {
    const date = toDate(value);
    if (!date) return '';

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** Une valeur de champ « date et heure » décalée de `minutes`, ou vide si elle n'est pas lisible. */
export function addMinutes(value, minutes) {
    const date = toDate(value);
    if (!date) return '';

    return toLocalInput(new Date(date.getTime() + minutes * 60000));
}

/** L'heure qu'il est, arrondie à la minute suivante : le plus tôt qu'un début « programmé » puisse être. */
export function nextMinuteInput(now = new Date()) {
    const date = new Date(now.getTime());
    date.setSeconds(0, 0);

    return toLocalInput(new Date(date.getTime() + 60000));
}

const time = (date) => date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
const day = (date) => date.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
const sameDay = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

/** « aujourd'hui à 22:00 », « demain à 06:00 », sinon « vendredi 26 septembre à 22:00 ». */
export function whenLabel(value, now = new Date()) {
    const date = toDate(value);
    if (!date) return '';

    const tomorrow = new Date(now.getTime());
    tomorrow.setDate(tomorrow.getDate() + 1);

    if (sameDay(date, now)) return `aujourd’hui à ${time(date)}`;
    if (sameDay(date, tomorrow)) return `demain à ${time(date)}`;

    return `${day(date)} à ${time(date)}`;
}

/**
 * La fenêtre en une phrase : « aujourd'hui de 22:00 à 23:30 », « à partir de
 * demain à 06:00 », ou « vendredi 26 septembre à 22:00 jusqu'à samedi 27 septembre à 02:00 ».
 */
export function windowLabel(startsAt, endsAt, now = new Date()) {
    const start = toDate(startsAt);
    const end = toDate(endsAt);
    if (!start) return '';
    if (!end) return `à partir de ${whenLabel(start, now)}`;

    if (sameDay(start, end)) {
        const prefix = whenLabel(start, now).replace(/ à \d{2}:\d{2}$/, '');

        return `${prefix} de ${time(start)} à ${time(end)}`;
    }

    return `${whenLabel(start, now)} jusqu’à ${whenLabel(end, now)}`;
}

/** Ce que la page de maintenance dit du retour : l'heure prévue, sinon « dès la fin de l'intervention ». */
export function returnLabel(endsAt, now = new Date()) {
    const end = toDate(endsAt);

    return end ? `Retour prévu ${whenLabel(end, now)}.` : 'Retour dès la fin de l’intervention.';
}

/** Combien de temps reste avant `value`, en millisecondes (0 si c'est passé ou illisible). */
export function msUntil(value, now = new Date()) {
    const date = toDate(value);

    return date ? Math.max(0, date.getTime() - now.getTime()) : 0;
}
