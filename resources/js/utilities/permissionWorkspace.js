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
