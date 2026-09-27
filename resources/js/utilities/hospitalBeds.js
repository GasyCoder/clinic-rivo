/**
 * ADR-164 — l'apparence d'un lit selon son état, partagée par le portail, le
 * plan des lits de l'Hospitalisation et le choix d'un lit sur le séjour : un
 * lit libre a la même couleur partout.
 */
export const BED_STATE = {
    FREE: {
        label: 'Libre',
        badge: 'success',
        swatch: 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/40',
        chip: 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200',
    },
    OCCUPIED: {
        label: 'Occupé',
        badge: 'default',
        swatch: 'border-sky-400 bg-sky-500 dark:border-sky-500 dark:bg-sky-600',
        chip: 'border-sky-500 bg-sky-500 text-white hover:bg-sky-600 dark:border-sky-600 dark:bg-sky-600',
    },
    OUT_OF_SERVICE: {
        label: 'Hors service',
        badge: 'outline',
        swatch: 'border-border bg-muted',
        chip: 'border-border bg-muted text-muted-foreground hover:bg-muted/70',
    },
};

const CARE_LEVEL_VARIANT = { STANDARD: 'outline', CONTINUOUS: 'warning', INTENSIVE: 'destructive' };

export const careLevelVariant = (level) => CARE_LEVEL_VARIANT[level] ?? 'outline';
