export const normalizePermissionText = (value) => String(value ?? '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '');

export const permissionEffect = (permission, effects) => effects[permission.id] || '';

export const roleGrantsPermission = (permission, rolePermissionNames) => rolePermissionNames.has(permission.name);

export const isPermissionEffectivelyGranted = (permission, effects, rolePermissionNames) => {
    const effect = permissionEffect(permission, effects);
    if (effect === 'deny') return false;
    if (effect === 'allow') return true;
    return roleGrantsPermission(permission, rolePermissionNames);
};

export const isSensitivePermission = (permission) => {
    const action = String(permission.name).split('.').at(-1);
    const sensitiveActions = ['force_delete', 'delete', 'restore', 'manage', 'assign', 'approve'];
    const sensitiveModules = ['users', 'roles', 'permissions', 'super_admin', 'audit', 'api', 'settings'];

    return sensitiveActions.includes(action) || sensitiveModules.includes(permission.module);
};

export const permissionMatchesSearch = (permission, search, categoryLabel) => {
    const term = normalizePermissionText(search.trim());
    if (!term) return true;

    return normalizePermissionText(permission.label).includes(term)
        || normalizePermissionText(permission.name).includes(term)
        || normalizePermissionText(categoryLabel).includes(term);
};

export const permissionMatchesFilter = (permission, filter, effects, rolePermissionNames) => {
    const effect = permissionEffect(permission, effects);
    if (filter === 'exceptions') return effect !== '';
    if (filter === 'inherited') return effect === '';
    if (filter === 'allowed') return isPermissionEffectivelyGranted(permission, effects, rolePermissionNames);
    if (filter === 'denied') return !isPermissionEffectivelyGranted(permission, effects, rolePermissionNames);
    if (filter === 'sensitive') return isSensitivePermission(permission);
    return true;
};

export const summarizePermissionWorkspace = (catalog, effects, provenance, rolePermissionNames) => {
    const total = catalog.length;
    const effectiveAllowed = catalog.filter((permission) => isPermissionEffectivelyGranted(permission, effects, rolePermissionNames)).length;
    const exceptions = catalog.filter((permission) => permissionEffect(permission, effects) !== '').length;
    const manualAllowed = catalog.filter((permission) => permissionEffect(permission, effects) === 'allow'
        && provenance[permission.id]?.source !== 'PROFILE').length;
    const manualDenied = catalog.filter((permission) => permissionEffect(permission, effects) === 'deny'
        && provenance[permission.id]?.source !== 'PROFILE').length;

    return {
        total,
        effectiveAllowed,
        effectiveDenied: total - effectiveAllowed,
        exceptions,
        inherited: catalog.filter((permission) => permissionEffect(permission, effects) === '').length,
        manualAllowed,
        manualDenied,
    };
};

/**
 * Les permissions d'une catégorie regroupées par nature d'action.
 *
 * Une catégorie de vingt droits se lit mal à plat : « Consulter »,
 * « Créer », « Supprimer » ne portent pas le même risque, et c'est cette
 * distinction — pas l'ordre alphabétique — qui guide la décision.
 */
export const permissionActionGroup = (permission) => {
    const action = String(permission.name).split('.').at(-1);

    if (['view', 'view_deleted', 'print', 'export'].includes(action)) return 'Consulter et exporter';
    if (['create', 'import'].includes(action)) return 'Créer et importer';
    if (['delete', 'force_delete', 'restore', 'archive', 'unarchive'].includes(action)) return 'Suppression et restauration';
    if (['validate', 'approve', 'reject', 'cancel', 'close', 'open'].includes(action)) return 'Validation et opérations';

    return 'Gérer et mettre à jour';
};

/**
 * Ce qui a été coché et décoché depuis l'état enregistré.
 *
 * Un compteur seul — « 3 modifications » — ne dit pas *lesquelles* : sur un
 * socle de rôle qui s'applique à tous les comptes d'un service, on doit
 * pouvoir relire l'écart avant de l'envoyer au site.
 */
export const diffPermissionSelection = (catalog, baselineIds, draftIds) => {
    const added = [];
    const removed = [];

    for (const permission of catalog) {
        const before = baselineIds.has(permission.id);
        const after = draftIds.has(permission.id);

        if (before === after) continue;
        (after ? added : removed).push(permission);
    }

    return { added, removed, total: added.length + removed.length };
};
