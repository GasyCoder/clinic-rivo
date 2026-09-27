/**
 * ADR-197 — l'accès du personnel : ce que l'écran du portail vérifie avant
 * d'envoyer (le serveur revérifie tout), et comment il présente une remise.
 */

/** La partie avant « @ » d'une adresse professionnelle — la même règle que le serveur. */
export const LOCAL_PART = /^(?!.*\.\.)[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/;

/** Récent : ajouté depuis moins de 7 jours. */
export function isRecent(value, now = new Date()) {
    if (!value) return false;

    return now.getTime() - new Date(value).getTime() < 7 * 24 * 60 * 60 * 1000;
}

/** L'adresse proposée : celle déjà demandée, sinon celle que le site propose. */
export function initialLocalPart(employee) {
    const mailbox = employee?.mailbox;
    if (mailbox?.address && ['REQUESTED', 'ACTIVE'].includes(mailbox.status)) return String(mailbox.address).split('@')[0];

    return employee?.suggestion ?? '';
}

/** L'adresse existe déjà : elle est reprise et reçoit un nouveau mot de passe, rien à saisir. */
export const hasActiveMailbox = (employee) => employee?.mailbox?.status === 'ACTIVE';

/**
 * Ce qui manque encore à une ligne avant de créer son accès, ou `null`.
 *
 * @param {{ employee: object, local_part: string, role_id: string|number, profile_id: string|number }} row
 * @param {Array<{ id: number, profiles: Array<{ id: number }> }>} roles  les rôles du site de l'employé
 */
export function rowProblem(row, roles) {
    if (row.employee?.mailbox?.status === 'SUSPENDED') return 'Adresse suspendue : réactivez-la d’abord.';
    if (!hasActiveMailbox(row.employee)) {
        const local = String(row.local_part ?? '').trim();
        if (!local) return 'Indiquez l’adresse.';
        if (!LOCAL_PART.test(local)) return 'Adresse : lettres sans accent, chiffres, point, tiret ou soulignement.';
    }

    const role = (roles ?? []).find((candidate) => String(candidate.id) === String(row.role_id));
    if (!role) return 'Choisissez le rôle.';
    if (role.profiles?.length && !role.profiles.some((profile) => String(profile.id) === String(row.profile_id))) return 'Choisissez le profil métier.';

    return null;
}

/**
 * ADR-199 — le rôle et le profil que la fonction de l'employé propose, s'ils
 * existent parmi les rôles de son site ; sinon rien n'est prérempli.
 *
 * @returns {{ role_id: string, profile_id: string, fromJobTitle: boolean }}
 */
export function proposedSelection(employee, roles) {
    const proposed = employee?.proposed_access;
    const role = proposed ? (roles ?? []).find((candidate) => String(candidate.id) === String(proposed.role_id)) : null;
    if (!role) return { role_id: '', profile_id: '', fromJobTitle: false };

    const profile = (role.profiles ?? []).find((candidate) => String(candidate.id) === String(proposed.profile_id));

    return { role_id: String(role.id), profile_id: profile ? String(profile.id) : '', fromJobTitle: true };
}

/**
 * ADR-202 — une remise se lit sur ses employés : à envoyer au RH, en attente de
 * connexion, délai dépassé (à rouvrir), tous connectés. Aucun mot de passe : chacun
 * choisit le sien à sa première connexion.
 */
export const HANDOVER_TONES = {
    DRAFT: 'warning',
    WAITING: 'info',
    TO_REOPEN: 'danger',
    COMPLETE: 'success',
};

export const handoverTone = (status) => HANDOVER_TONES[status] ?? 'neutral';

/** Où en est un employé de la remise : sa couleur. */
export const ITEM_STATE_TONES = {
    ACTIVATED: 'success',
    WAITING: 'info',
    EXPIRED: 'danger',
    DISABLED: 'neutral',
    REMOVED: 'neutral',
};

export const itemStateTone = (state) => ITEM_STATE_TONES[state] ?? 'neutral';

/**
 * Les étapes d'une remise, dans l'ordre où elles arrivent : créés, envoyés au RH,
 * premières connexions. La dernière dit combien se sont connectés ; elle échoue
 * quand un employé a laissé passer le délai. Chaque étape est `done`, `current`
 * (la prochaine à venir), `pending` ou `failed`.
 */
export function handoverSteps(handover) {
    const counts = handover?.counts ?? {};
    const total = counts.total ?? handover?.items?.length ?? 0;
    const activated = counts.activated ?? 0;
    const status = handover?.status;
    const sent = Boolean(handover?.sent_at);

    return [
        { key: 'created', label: 'Créés', at: handover?.created_at, by: handover?.created_by, state: 'done' },
        { key: 'sent', label: 'Envoyés au RH', at: handover?.sent_at, by: handover?.sent_by, state: sent ? 'done' : 'current' },
        {
            key: 'activated',
            label: status === 'COMPLETE' ? 'Tous connectés' : `Connectés ${activated}/${total}`,
            at: status === 'COMPLETE' ? handover?.completed_at : null,
            by: null,
            detail: status === 'TO_REOPEN'
                ? `${counts.expired} délai${counts.expired > 1 ? 's' : ''} dépassé${counts.expired > 1 ? 's' : ''}`
                : status === 'WAITING' ? `${counts.waiting} en attente` : null,
            state: status === 'COMPLETE' ? 'done' : status === 'TO_REOPEN' ? 'failed' : sent ? 'current' : 'pending',
        },
    ];
}

/** Les remises regroupées pour les filtres du portail. */
export const HANDOVER_GROUPS = [
    { key: 'todo', label: 'À envoyer', statuses: ['DRAFT'] },
    { key: 'reopen', label: 'Délai dépassé', statuses: ['TO_REOPEN'] },
    { key: 'waiting', label: 'En attente de connexion', statuses: ['WAITING'] },
    { key: 'done', label: 'Tous connectés', statuses: ['COMPLETE'] },
];

export const handoverGroup = (status) => HANDOVER_GROUPS.find((group) => group.statuses.includes(status))?.key ?? 'waiting';

/**
 * Le délai de première connexion le plus proche : rouge sous 2 jours, ambre sous
 * 4, neutre au-delà ; `null` quand personne n'attend plus.
 *
 * @returns {{ tone: 'danger'|'warning'|'neutral', hours: number, label: string }|null}
 */
export function deadlineUrgency(deadline, now = new Date()) {
    if (!deadline) return null;

    const hours = (new Date(deadline).getTime() - now.getTime()) / 3_600_000;
    const tone = hours < 48 ? 'danger' : hours < 96 ? 'warning' : 'neutral';

    return { tone, hours, label: `Délai ${remaining(hours)}` };
}

/** « dans 45 min », « dans 5 h », « dans 3 jours » ; « dépassé » une fois passé. */
function remaining(hours) {
    if (hours <= 0) return 'dépassé';
    if (hours < 1) return `dans ${Math.max(1, Math.round(hours * 60))} min`;
    if (hours < 48) return `dans ${Math.round(hours)} h`;

    return `dans ${Math.round(hours / 24)} jours`;
}

/** Pour le RH (site) : ce que la remise attend de lui, et le libellé du bouton. */
export function receivedAction(status) {
    return {
        TO_REOPEN: { label: 'Rouvrir', hint: 'Un employé n’a pas fait sa première connexion à temps : rouvrez son délai, puis prévenez-le.', primary: true },
        WAITING: { label: 'Prévenir les employés', hint: 'Dites à chaque employé que son compte existe et où se connecter : il choisira lui-même son mot de passe.', primary: true },
        COMPLETE: { label: 'Voir', hint: 'Tous se sont connectés et ont choisi leur mot de passe.', primary: false },
    }[status] ?? { label: 'Voir', hint: '', primary: false };
}

/** Le lien de connexion, l'adresse déjà écrite : l'employé n'a plus qu'à « Continuer ». */
export function loginLink(loginUrl, email) {
    if (!loginUrl) return '';

    return email ? `${loginUrl}?email=${encodeURIComponent(email)}` : loginUrl;
}

/**
 * Ce que le RH envoie ou dit à un employé : son compte existe, où se connecter, avec
 * quelle adresse ; il choisira son mot de passe. Aucun secret : c'est un message
 * qu'on peut recopier dans n'importe quelle messagerie.
 */
export function shareMessage(item, { loginUrl, brand, site, deadline } = {}) {
    const first = String(item?.employee_name ?? '').trim().split(/\s+/)[0] ?? '';
    const where = [brand, site].filter(Boolean).join(' — ');
    const until = deadline ? ` avant le ${new Date(deadline).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })}` : '';

    return [
        `Bonjour ${first},`.replace(' ,', ','),
        '',
        `Votre compte ${where || 'RIVO'} est créé.`,
        `Connectez-vous${until} sur : ${loginLink(loginUrl, item?.login_email)}`,
        `Adresse : ${item?.login_email ?? ''}`,
        '',
        'Tapez votre adresse, puis « Continuer » : vous choisirez vous-même votre mot de passe. Personne d’autre ne le connaîtra.',
        ...(item?.mailbox_address ? [`Le même mot de passe ouvrira votre messagerie professionnelle ${item.mailbox_address}.`] : []),
    ].join('\n');
}
