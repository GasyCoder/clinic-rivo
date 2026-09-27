/**
 * ADR-212 — la recommandation notée à l'accueil d'un nouveau patient.
 *
 * L'écran garde `{ enabled, mode, chosen, name, phone }` ; seul ce qui part
 * au serveur est calculé ici, une fois pour toutes.
 */
export const emptyReferral = () => ({ enabled: false, mode: 'search', chosen: null, name: '', phone: '' });

/**
 * Ce que l'arrivée envoie : `null` quand personne n'a recommandé la clinique,
 * sinon la personne choisie (personnel ou partenaire) ou un nom saisi.
 * `undefined` quand la case est cochée mais que rien n'est encore choisi :
 * l'accueil ne part pas en oubliant la recommandation.
 */
export function referralPayload(referral) {
    if (! referral?.enabled) return null;

    if (referral.mode === 'other') {
        const name = String(referral.name ?? '').trim();
        if (! name) return undefined;

        return { source: 'OTHER', name, phone: String(referral.phone ?? '').trim() || null };
    }

    const chosen = referral.chosen;
    if (chosen?.source === 'EMPLOYEE') return { source: 'EMPLOYEE', employee_uuid: chosen.uuid };
    if (chosen?.source === 'PARTNER') return { source: 'PARTNER', partner_uuid: chosen.uuid };

    return undefined;
}
