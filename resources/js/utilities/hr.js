import { formatDate } from './date.js';

/*
 * Mise en forme des écrans RH, écrite une fois pour toutes les pages.
 *
 * Le serveur envoie des dates ISO (« 2026-10-14 ») et des nombres décimaux
 * (« 8.00 ») : les afficher tels quels donnait « 2026-10-14 » et
 * « 8.00 jour(s) » dans la liste des congés et des contrats.
 */

/** « 8 jours », « 1 jour », « 0,5 jour » ; un tiret quand rien n'est connu. */
export function formatDays(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);
    if (Number.isNaN(number)) {
        return '—';
    }

    const text = number.toLocaleString('fr-FR', { maximumFractionDigits: 2 });

    return `${text} ${Math.abs(number) > 1 ? 'jours' : 'jour'}`;
}

/** « Du 15/01/2024 au 15/10/2026 », ou « Depuis le 15/01/2024 » sans date de fin. */
export function formatPeriod(startsOn, endsOn) {
    if (!startsOn) {
        return '—';
    }

    return endsOn
        ? `Du ${formatDate(startsOn)} au ${formatDate(endsOn)}`
        : `Depuis le ${formatDate(startsOn)}`;
}

/** L'échéance d'un contrat : « Finit aujourd'hui », « Finit demain », « Finit dans 21 jours ». */
export function endingLabel(days) {
    if (days === null || days === undefined) {
        return null;
    }
    if (days <= 0) {
        return 'Finit aujourd’hui';
    }
    if (days === 1) {
        return 'Finit demain';
    }

    return `Finit dans ${days} jours`;
}

/** Une durée de présence : « 8 h », « 7 h 45 », « 25 min ». */
export function formatMinutes(minutes) {
    if (minutes === null || minutes === undefined) {
        return '—';
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (!hours) {
        return `${rest} min`;
    }

    return rest ? `${hours} h ${String(rest).padStart(2, '0')}` : `${hours} h`;
}

/** Les initiales d'une personne, pour un avatar : « RAKOTOBE Hanitra » → « RH ». */
export function initials(name) {
    return String(name ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toLocaleUpperCase('fr-FR'))
        .join('') || '?';
}
