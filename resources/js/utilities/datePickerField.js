import { parseDate } from '@internationalized/date';
import { cn } from '@/lib/cn';

/**
 * ADR-099 — ce que partagent `DatePicker` et `DateTimePicker` : le déclencheur
 * (même allure qu'un `Input`), la lecture des bornes et le libellé français.
 */

export const pad = (value) => String(value).padStart(2, '0');

/** « AAAA-MM-JJ » (ou le début d'un « AAAA-MM-JJTHH:mm ») → `CalendarDate`, sinon `null`. */
export const toCalendarDate = (value) => {
    const match = /^(\d{4}-\d{2}-\d{2})/.exec(value ?? '');
    if (!match) return null;

    try {
        return parseDate(match[1]);
    } catch {
        return null;
    }
};

export const formatDayLabel = (isoDate, month = 'short') => {
    const [year, monthNumber, day] = isoDate.split('-').map(Number);

    return new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month, year: 'numeric' })
        .format(new Date(year, monthNumber - 1, day));
};

/** « 25/09/2026 » : tient dans les cases étroites des formulaires cliniques. */
export const formatDayNumeric = (isoDate) => {
    const [year, month, day] = isoDate.split('-');

    return `${day}/${month}/${year}`;
};

export const triggerClass = ({ size = 'default', invalid = false, readonly = false, extra = '' } = {}) => cn(
    'flex w-full items-center gap-2 rounded-lg border border-input bg-card px-3 text-start text-sm shadow-sm transition-colors',
    size === 'lg' ? 'h-11 px-4' : 'h-10',
    'hover:bg-accent/40 focus-visible:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/25',
    'data-[state=open]:border-primary/60 data-[state=open]:ring-2 data-[state=open]:ring-ring/25',
    readonly ? 'cursor-default bg-muted/40 hover:bg-muted/40 disabled:opacity-100' : 'disabled:cursor-not-allowed disabled:opacity-50',
    invalid && 'border-destructive',
    extra,
);
