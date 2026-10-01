/**
 * ADR-236 — ce qu'une sélection de dossiers employés permet, et pourquoi un dossier
 * archivé ne se supprime pas. Le serveur rejuge tout : ces règles ne font que dire,
 * avant le clic, ce qui passera.
 */

/** Le droit de supprimer définitivement, et ce que le dossier a déjà porté. */
export function forceDeleteState(employee, canForceDelete) {
    if (! employee?.archived) return { allowed: false, reason: 'Archivez d’abord ce dossier, avec un motif.' };
    if (! canForceDelete) return { allowed: false, reason: 'Demandez le droit « employees.force_delete » à un administrateur.' };

    const blockers = employee.deletion_blockers ?? [];
    if (blockers.length) return { allowed: false, reason: `Il a servi (${blockers.join(', ')}) : il reste archivé.` };

    return { allowed: true, reason: 'N’a servi nulle part : peut être supprimé définitivement.' };
}

/**
 * Pour les dossiers cochés : combien chaque geste en prendra.
 *
 * @param {Array<object>} rows       les dossiers cochés
 * @param {{ archive: boolean, restore: boolean, forceDelete: boolean, badge: boolean }} can
 */
export function bulkTargets(rows, can) {
    const active = rows.filter((row) => ! row.archived);
    const archived = rows.filter((row) => row.archived);

    return {
        badge: can.badge ? active : [],
        archive: can.archive ? active : [],
        restore: can.restore ? archived : [],
        forceDelete: can.forceDelete ? archived.filter((row) => forceDeleteState(row, true).allowed) : [],
    };
}

/** « EMP-0002 (RAKOTO Vola) » — les autres dossiers qui désignent peut-être la même personne. */
export function duplicateLabel(duplicates = []) {
    return duplicates.map((other) => (other.number ? `${other.number} (${other.name})` : other.name)).join(', ');
}
