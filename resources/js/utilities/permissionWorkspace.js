import {
    PERMISSION_MODULES,
    permissionCategoryIcon,
    permissionCategoryLabel,
    permissionCategoryModule,
    permissionCategoryOrder,
    permissionModule,
    permissionResourceIcon,
    permissionResourceLabel,
    permissionResourceOrder,
} from './permissionCategories.js';

export const normalizePermissionText = (value) => String(value ?? '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '');

/**
 * Le libellé d'une permission, ou son nom à défaut.
 *
 * Une permission peut entrer en base sans libellé — un seeder partiel, une
 * migration qui n'a posé que le nom. Trié tel quel, ce vide faisait tomber
 * l'écran entier : `null.localeCompare` interrompt le rendu d'un composant,
 * et Vue ne s'en relève pas (constaté sur `consultations.reopen`). Le nom
 * est un repli honnête — c'est ce que le code écrit, et c'est lisible.
 */
export const permissionLabel = (permission) => {
    const label = String(permission?.label ?? '').trim();

    return label !== '' ? label : String(permission?.name ?? '');
};

/** Comparateur par libellé — jamais de tri qui puisse lever une exception. */
export const comparePermissions = (left, right) => permissionLabel(left)
    .localeCompare(permissionLabel(right), 'fr');

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

/**
 * Tous les mots tapés doivent se retrouver, dans n'importe quel ordre :
 * « supprimer patient » trouve « Supprimer définitivement un patient ».
 */
export const matchesSearchTerms = (haystack, search) => {
    const terms = normalizePermissionText(search).split(/\s+/).filter(Boolean);

    if (terms.length === 0) return true;

    const text = normalizePermissionText(haystack);

    return terms.every((term) => text.includes(term));
};

export const permissionMatchesSearch = (permission, search, categoryLabel) => matchesSearchTerms(
    `${permissionLabel(permission)} ${permission.name} ${categoryLabel ?? ''}`,
    search,
);

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
 * Les colonnes de la grille, dans le même ordre pour toutes les fonctionnalités
 * (ADR-178) : « Voir | Créer | Modifier | Supprimer | Restaurer | Valider |
 * Exporter ». On lit la colonne « Supprimer » de haut en bas pour savoir ce
 * qu'un rôle peut retirer, au lieu de chercher le mot dans quarante listes.
 *
 * Une colonne peut réunir deux actions voisines — `delete` et `archive`
 * retirent tous deux un élément, jamais les deux sur la même ressource. Le
 * reste (imprimer, clôturer, encaisser…) garde son libellé complet à côté.
 */
export const PERMISSION_ACTION_COLUMNS = [
    { key: 'view', label: 'Voir', title: 'Consulter', actions: ['view'] },
    { key: 'create', label: 'Créer', title: 'Créer', actions: ['create'] },
    { key: 'update', label: 'Modifier', title: 'Modifier', actions: ['update'] },
    { key: 'delete', label: 'Supprimer', title: 'Supprimer ou archiver', actions: ['delete', 'archive'] },
    { key: 'restore', label: 'Restaurer', title: 'Restaurer', actions: ['restore'] },
    { key: 'validate', label: 'Valider', title: 'Valider ou approuver', actions: ['validate', 'approve'] },
    { key: 'export', label: 'Exporter', title: 'Exporter', actions: ['export'] },
];

/** `patients.medical_history.view` → `view`. */
export const permissionAction = (permission) => String(permission?.name ?? '').split('.').at(-1);

const categoryOf = (permission) => permission?.module || String(permission?.name ?? '').split('.')[0];

/**
 * Ce qu'une permission fait, en un mot, là où la colonne n'est pas là pour le
 * dire : sur un téléphone, les cases deviennent des pastilles et portent
 * leur verbe.
 */
export const permissionActionLabel = (permission) => {
    const column = PERMISSION_ACTION_COLUMNS.find((item) => item.actions.includes(permissionAction(permission)));

    return column?.label ?? permissionLabel(permission);
};

/**
 * Le catalogue d'un site, rangé comme on le lit : modules → fonctionnalités →
 * actions (ADR-178).
 *
 * Une fonctionnalité est une ressource — `patients`, mais aussi
 * `surgery.report` ou `catalog.tariffs`, qui ont leurs propres Voir / Créer /
 * Modifier —, avec son icône (la sienne, sinon celle de sa catégorie, sinon
 * celle de son module). Chaque ligne range ses permissions dans les colonnes de
 * `PERMISSION_ACTION_COLUMNS` ; ce qui n'y entre pas reste à côté, sous son
 * libellé complet.
 *
 * Une permission qui porte le nom d'une sous-ressource — `pharmacy.dispense`,
 * « Délivrer les médicaments » — rejoint la ligne de cette sous-ressource :
 * c'est l'acte principal de ce que la ligne décrit.
 *
 * @returns {Array<{key, label, description, icon, groups, rows, permissions}>}
 */
export const buildPermissionModules = (catalog) => {
    const resourceKeys = new Set(catalog
        .map((permission) => String(permission.name).split('.').slice(0, -1).join('.'))
        .filter((key) => key.includes('.')));

    const rows = new Map();

    for (const permission of catalog) {
        const parts = String(permission.name).split('.');
        const category = categoryOf(permission);
        const resource = resourceKeys.has(permission.name)
            ? permission.name
            : (parts.slice(0, -1).join('.') || category);

        if (! rows.has(resource)) {
            rows.set(resource, { key: resource, category, nested: resource !== category, permissions: [] });
        }

        rows.get(resource).permissions.push(permission);
    }

    const finalized = Array.from(rows.values()).map((row) => {
        const cells = {};
        const placed = new Set();

        for (const column of PERMISSION_ACTION_COLUMNS) {
            for (const action of column.actions) {
                const candidate = row.permissions.find((permission) => ! placed.has(permission.id)
                    && permission.name !== row.key
                    && permissionAction(permission) === action);

                if (candidate) {
                    cells[column.key] = candidate;
                    placed.add(candidate.id);
                    break;
                }
            }
        }

        const others = row.permissions.filter((permission) => ! placed.has(permission.id)).sort(comparePermissions);
        const ordered = [
            ...PERMISSION_ACTION_COLUMNS.map((column) => cells[column.key]).filter(Boolean),
            ...others,
        ];

        return {
            key: row.key,
            category: row.category,
            nested: row.nested,
            label: permissionResourceLabel(row.key),
            icon: permissionResourceIcon(row.key),
            categoryLabel: permissionCategoryLabel(row.category),
            cells,
            others,
            permissions: ordered,
        };
    });

    const compareRows = (left, right) => (
        permissionCategoryOrder(left.category) - permissionCategoryOrder(right.category)
        || left.category.localeCompare(right.category)
        || Number(left.nested) - Number(right.nested)
        || permissionResourceOrder(left.key) - permissionResourceOrder(right.key)
        || left.label.localeCompare(right.label, 'fr')
    );

    return PERMISSION_MODULES
        .map((module) => {
            const moduleRows = finalized
                .filter((row) => permissionCategoryModule(row.category) === module.key)
                .sort(compareRows);

            const groups = [];

            for (const row of moduleRows) {
                let group = groups.find((candidate) => candidate.category === row.category);

                if (! group) {
                    group = {
                        category: row.category,
                        label: row.categoryLabel,
                        icon: permissionCategoryIcon(row.category),
                        base: null,
                        children: [],
                    };
                    groups.push(group);
                }

                if (row.nested) group.children.push(row); else group.base = row;
            }

            return {
                key: module.key,
                label: module.label,
                description: module.description,
                icon: module.icon,
                groups,
                rows: moduleRows,
                permissions: moduleRows.flatMap((row) => row.permissions),
            };
        })
        .filter((module) => module.rows.length > 0);
};

/**
 * Le texte dans lequel une recherche cherche une permission : son libellé, son
 * nom, sa fonctionnalité et son verbe. On tape ce qu'on voit à l'écran
 * — « Dossiers patients », « Supprimer » — autant que le code d'un 403.
 *
 * Le nom du module n'y est pas : « supprimer patient » ramenait toutes les
 * suppressions du module « Accueil & patients », adresses comprises. Le module
 * se voit déjà, replié, avec son compteur.
 */
export const permissionSearchIndex = (modules) => {
    const index = new Map();

    for (const module of modules) {
        for (const row of module.rows) {
            for (const permission of row.permissions) {
                index.set(permission.id, normalizePermissionText([
                    permissionLabel(permission),
                    permission.name,
                    row.label,
                    row.categoryLabel,
                    permissionActionLabel(permission),
                ].join(' ')));
            }
        }
    }

    return index;
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

/** Le module d'une permission, pour regrouper un écart ou une liste. */
export const permissionModuleOf = (permission) => permissionModule(permissionCategoryModule(categoryOf(permission)));
