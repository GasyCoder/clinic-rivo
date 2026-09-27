/**
 * Ce qu'une DPA dit aujourd'hui, pour la lire d'un coup d'œil : dans combien
 * de temps, ou depuis combien de jours elle est passée.
 *
 * Un repère d'affichage, jamais une règle : la DPA elle-même vient du serveur
 * (ADR-201), et seul le délai jusqu'à aujourd'hui est compté ici, dans le
 * fuseau du poste. Une grossesse qui n'est plus en cours n'a pas de délai.
 */
const DAY = 86_400_000;

/** Rose tant qu'on a le temps, ambre dans les deux dernières semaines et le jour même, rouge une fois passée. */
export const DUE_DATE_TONES = {
    ahead: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
    soon: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    due: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    past: 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300',
};

/** « AAAA-MM-JJ » lu comme une date locale — jamais `new Date('AAAA-MM-JJ')`, qui passe en UTC. */
const localDay = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ''));

    return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
};

export function dueDateCountdown(estimatedDueDate, status = 'ONGOING', today = new Date()) {
    const due = localDay(estimatedDueDate);

    if (! due || status !== 'ONGOING') return null;

    const start = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const days = Math.round((due - start) / DAY);

    if (days === 0) return { days, tone: 'due', label: 'Aujourd’hui' };
    if (days < 0) return { days, tone: 'past', label: `Dépassée de ${-days} j` };
    if (days < 14) return { days, tone: 'soon', label: `Dans ${days} j` };

    const weeks = Math.floor(days / 7);
    const rest = days % 7;

    return { days, tone: 'ahead', label: `Dans ${weeks} sem.${rest ? ` ${rest} j` : ''}` };
}
