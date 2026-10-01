/**
 * ADR-220 — la sélection multiple de la paillasse : ce que chaque geste peut
 * faire des demandes cochées. Le serveur rejuge chaque demande ; ceci dit
 * seulement, avant le clic, combien seront prises et pourquoi les autres non.
 */

/** Les demandes cochées, dans l'ordre de la page. */
export const selectedRows = (rows, selected) => {
    const chosen = new Set(selected);

    return (rows ?? []).filter((row) => chosen.has(row.uuid));
};

/**
 * @returns {{ archive: string[], unarchive: string[], trash: string[], refused: { archive: number, trash: number } }}
 */
export const selectionActions = (rows, selected) => {
    const picked = selectedRows(rows, selected);
    const archive = picked.filter((row) => !row.archived && row.archivable).map((row) => row.uuid);
    const unarchive = picked.filter((row) => row.archived).map((row) => row.uuid);
    const trash = picked.filter((row) => row.trashable).map((row) => row.uuid);

    return {
        archive,
        unarchive,
        trash,
        refused: {
            archive: picked.filter((row) => !row.archived).length - archive.length,
            trash: picked.length - trash.length,
        },
    };
};

/** L'état de la case « tout sélectionner » : coché, en partie, ou non. */
export const headerCheckState = (rows, selected) => {
    const uuids = (rows ?? []).map((row) => row.uuid);
    const chosen = new Set(selected);
    const count = uuids.filter((uuid) => chosen.has(uuid)).length;

    if (count === 0) return false;

    return count === uuids.length ? true : 'indeterminate';
};

/** Cocher ou décocher une demande, sans dépasser le plafond du serveur. */
export const toggleSelection = (selected, uuid, max = 50) => {
    if (selected.includes(uuid)) return selected.filter((value) => value !== uuid);

    return selected.length >= max ? selected : [...selected, uuid];
};

/** Pourquoi une demande ne se range pas ou ne part pas à la corbeille — dit avant le clic. */
export const rowRefusal = (row, action) => {
    if (action === 'archive' && !row.archived && !row.archivable) {
        return 'Seule une demande dont toutes les analyses sont envoyées au médecin se range.';
    }
    if (action === 'trash' && !row.trashable) {
        return 'Des résultats ont été envoyés au médecin : la demande ne part pas à la corbeille.';
    }

    return null;
};

/** Les entrées du menu « … » d'une ligne. */
export const rowMenuItems = (row, manage) => {
    const items = [];

    if (manage?.archive) {
        items.push(row.archived
            ? { key: 'unarchive', label: 'Désarchiver', description: 'La demande revient dans la file.' }
            : {
                key: 'archive',
                label: 'Archiver',
                description: rowRefusal(row, 'archive') ?? 'La ranger : elle quitte la file, rien n’est effacé.',
                disabled: !row.archivable,
            });
    }
    if (manage?.trash) {
        items.push({
            key: 'trash',
            label: 'Mettre à la corbeille',
            description: rowRefusal(row, 'trash') ?? 'Saisie à tort : restaurable depuis la Corbeille.',
            disabled: !row.trashable,
            destructive: true,
            separatorBefore: items.length > 0,
        });
    }

    return items;
};
