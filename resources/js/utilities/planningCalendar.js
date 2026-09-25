/*
 * ADR-194 — la lecture du planning en calendrier : semaine (un tableau de
 * garde, une ligne par personne) et mois (une grille de jours).
 *
 * Les dates se calculent sur des chaînes « AAAA-MM-JJ » et en UTC : un
 * changement d'heure du navigateur ne décale jamais un jour. Les heures d'un
 * créneau se lisent sur la chaîne ISO du serveur (« …T19:00:00+03:00 ») : on
 * affiche l'heure de la clinique, pas celle du poste qui regarde.
 */

const DAY_MS = 86_400_000;

function toUtc(date) {
    const [year, month, day] = String(date).slice(0, 10).split('-').map(Number);
    return Date.UTC(year, month - 1, day);
}

function fromUtc(time) {
    return new Date(time).toISOString().slice(0, 10);
}

/** « 2026-09-25 » + n jours. */
export function addDays(date, days) {
    return fromUtc(toUtc(date) + days * DAY_MS);
}

/** 0 = lundi … 6 = dimanche. */
export function weekdayIndex(date) {
    return (new Date(toUtc(date)).getUTCDay() + 6) % 7;
}

/** Le lundi de la semaine de cette date. */
export function startOfWeek(date) {
    return addDays(date, -weekdayIndex(date));
}

/** Les sept jours (lundi → dimanche) de la semaine de cette date. */
export function weekDays(date) {
    const monday = startOfWeek(date);
    return Array.from({ length: 7 }, (_, index) => addDays(monday, index));
}

/** « 2026-09 » → le premier du mois. */
export function startOfMonth(date) {
    return `${String(date).slice(0, 7)}-01`;
}

export function addMonths(date, months) {
    const time = new Date(toUtc(startOfMonth(date)));
    time.setUTCMonth(time.getUTCMonth() + months);
    return fromUtc(time.getTime());
}

/**
 * La grille d'un mois : des semaines complètes, du lundi de la première au
 * dimanche de la dernière. Chaque case dit si elle appartient au mois.
 */
export function monthGrid(date) {
    const first = startOfMonth(date);
    const month = first.slice(0, 7);
    const last = addDays(addMonths(first, 1), -1);
    const weeks = [];

    for (let day = startOfWeek(first); toUtc(day) <= toUtc(last) || weekdayIndex(day) !== 0; day = addDays(day, 1)) {
        if (weekdayIndex(day) === 0) {
            weeks.push([]);
        }
        weeks[weeks.length - 1].push({ date: day, inMonth: day.slice(0, 7) === month });
    }

    return weeks;
}

/** Le jour d'un créneau, selon l'heure de la clinique. */
export function shiftDay(value) {
    return String(value ?? '').slice(0, 10);
}

/** « 19:00 » : l'heure d'un créneau, selon l'heure de la clinique. */
export function shiftTime(value) {
    return String(value ?? '').slice(11, 16);
}

/** La durée d'un créneau, en minutes. */
export function durationMinutes(shift) {
    const start = Date.parse(shift.starts_at);
    const end = Date.parse(shift.ends_at);

    return Number.isNaN(start) || Number.isNaN(end) ? 0 : Math.max(0, Math.round((end - start) / 60_000));
}

/**
 * Jour, nuit ou 24 h : une lecture des heures, pas une catégorie enregistrée.
 * Nuit : commence à 18 h ou plus tard, avant 6 h, ou finit un autre jour.
 */
export function shiftPeriod(shift) {
    if (durationMinutes(shift) >= 20 * 60) {
        return 'LONG';
    }

    const hour = Number(shiftTime(shift.starts_at).slice(0, 2));
    const crossesMidnight = shiftDay(shift.ends_at) !== shiftDay(shift.starts_at) && shiftTime(shift.ends_at) !== '00:00';

    return hour >= 18 || hour < 6 || crossesMidnight ? 'NIGHT' : 'DAY';
}

export const PERIOD_LABELS = { DAY: 'Jour', NIGHT: 'Nuit', LONG: '24 h' };

/** « 8 h », « 12 h 30 ». */
export function formatDuration(minutes) {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (!hours) {
        return `${rest} min`;
    }

    return rest ? `${hours} h ${String(rest).padStart(2, '0')}` : `${hours} h`;
}

/** Les créneaux par jour de début, chacun trié par heure. */
export function groupByDay(shifts) {
    const days = new Map();

    [...shifts]
        .sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at)))
        .forEach((shift) => {
            const key = shiftDay(shift.starts_at);
            if (!days.has(key)) {
                days.set(key, []);
            }
            days.get(key).push(shift);
        });

    return days;
}

/**
 * Le tableau de garde d'une semaine : une ligne par personne planifiée,
 * rangées par département puis par nom, et ses créneaux jour par jour.
 */
export function rosterRows(shifts, days) {
    const rows = new Map();

    [...shifts]
        .sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at)))
        .forEach((shift) => {
            const day = shiftDay(shift.starts_at);
            if (!days.includes(day)) {
                return;
            }

            const key = shift.employee.uuid;
            if (!rows.has(key)) {
                rows.set(key, {
                    employee: shift.employee,
                    department: shift.department || shift.employee.department || '',
                    cells: Object.fromEntries(days.map((date) => [date, []])),
                    total: 0,
                });
            }

            const row = rows.get(key);
            row.cells[day].push(shift);
            row.total += 1;
        });

    return [...rows.values()].sort((a, b) => a.department.localeCompare(b.department, 'fr')
        || String(a.employee.name).localeCompare(String(b.employee.name), 'fr'));
}

/** « Rakoto H. » : un nom court pour une case de calendrier. */
export function shortName(name) {
    const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);

    if (parts.length < 2) {
        return parts[0] ?? '';
    }

    const last = parts[0].charAt(0) + parts[0].slice(1).toLocaleLowerCase('fr-FR');

    return `${last} ${parts[1].charAt(0).toLocaleUpperCase('fr-FR')}.`;
}

/**
 * La fin d'un créneau saisi sur une journée : si l'heure de fin n'est pas
 * après l'heure de début, elle tombe le lendemain (une garde 19 h → 7 h).
 */
export function endOnSameOrNextDay(day, start, end) {
    if (!day || !start || !end) {
        return null;
    }

    return end > start ? `${day}T${end}` : `${addDays(day, 1)}T${end}`;
}
