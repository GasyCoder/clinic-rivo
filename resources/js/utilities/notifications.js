import { Bell, FlaskConical, KeyRound, PartyPopper, UserCheck, UserPlus } from 'lucide-vue-next';

/**
 * ADR-197 — ce que la cloche et la page « Notifications » partagent : l'icône
 * nommée par le serveur, le regroupement par jour et l'heure affichée.
 */

/** Le serveur nomme l'icône ; l'écran ne choisit jamais l'illustration d'une notification. */
export const NOTIFICATION_ICONS = { 'user-plus': UserPlus, 'user-check': UserCheck, 'key-round': KeyRound, 'party-popper': PartyPopper, 'flask-conical': FlaskConical, bell: Bell };

export const notificationIcon = (name) => NOTIFICATION_ICONS[name] ?? Bell;

const startOfDay = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());

const DAY = 24 * 60 * 60 * 1000;

/**
 * Les notifications rangées par jour, dans l'ordre reçu (les plus récentes
 * d'abord) : aujourd'hui, hier, les 7 derniers jours, puis plus anciennes. Un
 * groupe vide n'apparaît pas.
 *
 * @returns {Array<{key: string, label: string, items: Array<object>}>}
 */
export function groupNotificationsByDay(items, now = new Date()) {
    const today = startOfDay(now).getTime();
    const groups = [
        { key: 'today', label: 'Aujourd’hui', items: [] },
        { key: 'yesterday', label: 'Hier', items: [] },
        { key: 'week', label: 'Cette semaine', items: [] },
        { key: 'older', label: 'Plus anciennes', items: [] },
    ];

    for (const item of items ?? []) {
        const day = item.created_at ? startOfDay(new Date(item.created_at)).getTime() : 0;
        const age = Math.round((today - day) / DAY);
        const group = age <= 0 ? groups[0] : age === 1 ? groups[1] : age < 7 ? groups[2] : groups[3];
        group.items.push(item);
    }

    return groups.filter((group) => group.items.length);
}

const time = (date) => date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

/**
 * L'heure d'une notification : « à l'instant », « il y a 12 min » la première
 * heure, puis l'heure du jour, « hier, 14:32 », et la date au-delà.
 */
export function notificationTime(value, now = new Date()) {
    if (!value) return '';

    const date = new Date(value);
    const seconds = Math.max(0, (now.getTime() - date.getTime()) / 1000);

    if (seconds < 60) return 'à l’instant';
    if (seconds < 3600) return `il y a ${Math.floor(seconds / 60)} min`;

    const age = Math.round((startOfDay(now).getTime() - startOfDay(date).getTime()) / DAY);
    if (age <= 0) return time(date);
    if (age === 1) return `hier, ${time(date)}`;

    return `${date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric' })} · ${time(date)}`;
}

/** « 3 non lues », « 1 non lue », « Aucune non lue ». */
export function unreadLabel(count) {
    if (!count) return 'Aucune non lue';

    return `${count} non lue${count > 1 ? 's' : ''}`;
}
