/**
 * ADR-188 — le compte de connexion d'un employé, vu depuis son dossier RH.
 *
 * Le dossier RH et le compte vivent dans deux écrans (Employés, Utilisateurs) :
 * la fiche dit s'il y a un compte, et mène à l'écran qui le gère — celui du
 * portail pour le Super Admin (`context` : l'espace RH d'un site vu du
 * portail), celui du site sinon. Aucun droit n'est donné ici : l'écran
 * Utilisateurs revérifie tout.
 *
 * `null` : rien à proposer (pas le droit, ou fiche qui ne peut plus recevoir
 * de compte — un nouveau lien exige une fiche active et non archivée).
 */
export const employeeAccountLink = (employee, can, context = null) => {
    if (! employee) return null;
    const site = context?.site?.code ?? null;
    const usersPage = (params) => (site
        ? `/super-admin/workspaces/users?${new URLSearchParams({ site, ...params })}`
        : `/administration/users?${new URLSearchParams(params)}`);

    if (employee.user_account) {
        if (! can('users.view')) return null;

        return {
            kind: 'view',
            label: 'Voir le compte',
            href: usersPage(site ? { search: employee.user_account.name } : { q: employee.user_account.name }),
        };
    }

    if (! can('users.create') || ! employee.active || employee.archived) return null;

    return { kind: 'create', label: 'Créer son compte', href: usersPage({ employe: employee.uuid }) };
};

/** L'employé demandé dans l'adresse de l'écran Utilisateurs (`?employe=`). */
export const requestedEmployeeUuid = (url) => new URLSearchParams(String(url ?? '').split('?')[1] ?? '').get('employe');
