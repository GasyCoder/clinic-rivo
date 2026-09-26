/**
 * ADR-066 — les chiffres RH, dans le même ordre et avec les mêmes mots partout
 * où ils paraissent : l'accueil du site, l'espace RH, le portail. `href` est
 * l'adresse de la liste derrière le chiffre, telle qu'elle est sur le site ;
 * le portail la ramène à l'espace RH d'un site (`mapHrPath`, ADR-187).
 *
 * Chaque chiffre ouvre la liste de CE chiffre (ADR-194) : cliquer « 1 » et
 * trouver sept lignes du mois laissait chercher laquelle était concernée.
 *
 * `todo` : ce qui attend une décision. `headcount` : l'effectif du jour.
 * `label` est la phrase complète ; `tile` tient sur une ligne dans une tuile ;
 * `short` titre une colonne de tableau.
 */
export const HR_FIGURES = [
    { key: 'pending_leave', group: 'todo', icon: 'calendar', label: 'Congés à décider', short: 'Congés à décider', tile: 'Congés à décider', href: '/administration/leave?status=PENDING', tone: 'amber' },
    { key: 'open_attendance', group: 'todo', icon: 'clock', label: 'Présences sans heure de sortie', short: 'Présences ouvertes', tile: 'Présences sans sortie', href: '/administration/attendance?open=1', tone: 'sky' },
    { key: 'contracts_ending_soon', group: 'todo', icon: 'file-docs', label: 'Contrats qui finissent sous 30 jours', short: 'Contrats < 30 j', tile: 'Fins de contrat ≤ 30 j', href: '/administration/contracts?status=ending', tone: 'rose' },
    { key: 'active_employees', group: 'headcount', icon: 'users', label: 'Employés actifs', short: 'Employés actifs', tile: 'Employés actifs', href: '/administration/employees', tone: 'primary' },
    { key: 'current_contracts', group: 'headcount', icon: 'file-docs', label: 'Contrats en cours', short: 'Contrats en cours', tile: 'Contrats en cours', href: '/administration/contracts?status=current', tone: 'sky' },
    // ADR-198 — actifs, mais absents ce jour : un congé accepté couvre la date.
    { key: 'on_leave_today', group: 'headcount', icon: 'sun', label: 'En congé aujourd’hui', short: 'En congé', tile: 'En congé', href: '/administration/employees?status=on_leave', tone: 'sky' },
    { key: 'today_attendance', group: 'headcount', icon: 'check-circle', label: 'Pointés aujourd’hui', short: 'Pointés', tile: 'Pointés aujourd’hui', href: '/administration/attendance?from=today&to=today', tone: 'emerald' },
    { key: 'upcoming_shifts', group: 'headcount', icon: 'calender-date', label: 'Créneaux dans 7 jours', short: 'Créneaux 7 j', tile: 'Créneaux à 7 jours', href: '/administration/planning', tone: 'violet' },
];

export const HR_FIGURE_TONES = {
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    rose: 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
    primary: 'bg-primary/10 text-primary dark:bg-primary-950/40 dark:text-primary-300',
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
    violet: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300',
};

/** Un chiffre que le compte ne peut pas voir revient `null` : ce n'est pas un zéro. */
export const isVisibleFigure = (summary, key) => summary?.[key] !== null && summary?.[key] !== undefined;

/** Ce qui attend une décision sur un site : la somme des chiffres « à traiter » visibles. */
export const pendingTotal = (summary) => HR_FIGURES
    .filter((figure) => figure.group === 'todo' && isVisibleFigure(summary, figure.key))
    .reduce((total, figure) => total + Number(summary[figure.key] || 0), 0);
