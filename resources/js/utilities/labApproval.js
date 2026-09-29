/**
 * ADR-216, amendement quater — la validation du médecin, lue sur ce que le
 * serveur sert (`item.approval` : AWAITING, APPROVED, IN_CORRECTION). L'écran ne
 * décide rien : il met en mots et en couleur un état déjà calculé.
 */

/** La pastille d'une analyse envoyée ; `null` tant que rien n'est envoyé. */
export function approvalBadge(item) {
    const approval = item?.approval;
    if (!approval) {
        return null;
    }
    if (approval.state === 'APPROVED') {
        return {
            label: 'Validée',
            tone: 'success',
            title: ['Validée par le médecin', approval.by ? `par ${approval.by}` : null].filter(Boolean).join(' '),
        };
    }
    if (approval.state === 'AWAITING') {
        return { label: 'Terminée · à valider', tone: 'warning', title: 'Envoyée par le laboratoire, en attente de la validation du médecin' };
    }

    return { label: 'En correction', tone: 'danger', title: 'Reprise par le laboratoire' };
}

/** Les analyses qui attendent la validation du médecin. */
export function awaitingApproval(items) {
    return (items ?? []).filter((item) => item?.approval?.state === 'AWAITING');
}

/** Tout est-il validé, par qui, et quand (la dernière validation) ? */
export function approvalSummary(items) {
    const delivered = (items ?? []).filter((item) => item?.approval);
    const approved = delivered.filter((item) => item.approval.state === 'APPROVED');
    const by = [...new Set(approved.map((item) => item.approval.by).filter(Boolean))];
    const at = approved.map((item) => item.approval.at).filter(Boolean).sort().at(-1) ?? null;

    return { allApproved: delivered.length > 0 && approved.length === delivered.length, by, at, count: approved.length };
}

/**
 * Le statut d'une demande d'analyses pour le médecin, quand la validation le
 * décide ; `null` sinon (le statut ordinaire s'applique alors).
 *
 *   AWAITING   au moins un résultat reçu attend sa validation
 *   APPROVED   tout est reçu et validé
 */
export function requestApprovalStatus(request) {
    const approval = request?.approval;
    if (!approval || request.status === 'CANCELLED') {
        return null;
    }
    if (approval.awaiting > 0) {
        return {
            state: 'AWAITING',
            label: 'Terminé · à valider',
            detail: approval.awaiting === approval.total ? null : `${approval.awaiting} sur ${approval.total} à valider`,
        };
    }
    if (approval.total > 0 && approval.approved === approval.total) {
        return { state: 'APPROVED', label: 'Résultats validés', detail: null };
    }

    return null;
}

/** La ligne sous une analyse : reçue et à valider, ou validée par qui et quand. */
export function itemApprovalLine(item, format = (value) => value) {
    const approval = item?.approval;
    if (!approval || item.in_correction) {
        return null;
    }
    if (approval.state === 'AWAITING') {
        return { tone: 'warning', text: 'Terminé par le laboratoire · à valider' };
    }
    if (approval.state === 'APPROVED') {
        return { tone: 'success', text: ['Validé', approval.by ? `par ${approval.by}` : null, approval.at ? `le ${format(approval.at)}` : null].filter(Boolean).join(' ') };
    }

    return null;
}
