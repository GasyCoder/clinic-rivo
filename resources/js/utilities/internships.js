/**
 * ADR-243 — les stagiaires d'une page de « Stages », un par dossier : un stagiaire
 * qui a fait deux stages n'est coché, archivé ou supprimé qu'une fois. Chaque
 * dossier porte ce que les règles de l'ADR-236 lisent (archivé, ce qui le retient).
 *
 * @param {Array<{ employee?: object }>} stages
 */
export function internDossiers(stages = []) {
    const seen = new Map();

    stages.forEach((stage) => {
        const employee = stage?.employee;
        if (! employee?.uuid || seen.has(employee.uuid)) return;

        seen.set(employee.uuid, {
            uuid: employee.uuid,
            name: employee.name,
            employee_number: employee.employee_number,
            archived: Boolean(employee.archived),
            deletion_blockers: employee.deletion_blockers ?? null,
        });
    });

    return [...seen.values()];
}
