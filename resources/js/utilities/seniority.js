/**
 * ADR-197 — l'ancienneté de service, calculée depuis la date d'entrée pendant la
 * saisie. Le serveur fait le même calcul (`App\Support\Hr\Seniority`) pour la
 * fiche et l'impression : ce qui s'affiche ici n'est qu'un aperçu.
 */
const parse = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ''));

    return match ? { y: Number(match[1]), m: Number(match[2]), d: Number(match[3]) } : null;
};

const todayParts = () => {
    const now = new Date();

    return { y: now.getFullYear(), m: now.getMonth() + 1, d: now.getDate() };
};

export const seniorityLabel = (years, months) => {
    const parts = [
        years > 0 ? `${years} an${years > 1 ? 's' : ''}` : null,
        months > 0 ? `${months} mois` : null,
    ].filter(Boolean);

    return parts.length ? parts.join(' ') : 'Moins d’un mois';
};

/** `{ years, months, label, future }`, ou `null` sans date d'entrée valide. */
export const seniority = (hireDate, today = null) => {
    const hired = parse(hireDate);
    if (! hired) return null;

    const now = today ? parse(today) : todayParts();
    const hiredKey = hired.y * 10000 + hired.m * 100 + hired.d;
    const nowKey = now.y * 10000 + now.m * 100 + now.d;

    if (hiredKey > nowKey) return { years: 0, months: 0, label: 'Entrée à venir', future: true };

    const total = (now.y - hired.y) * 12 + (now.m - hired.m) - (now.d < hired.d ? 1 : 0);
    const years = Math.floor(total / 12);
    const months = total % 12;

    return { years, months, label: seniorityLabel(years, months), future: false };
};
