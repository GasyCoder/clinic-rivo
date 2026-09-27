const compareAnalyses = (left, right) => {
    const service = (left.catalog_item?.name ?? '').localeCompare(right.catalog_item?.name ?? '', 'fr');
    if (service !== 0) return service;

    const order = Number(left.display_order ?? 0) - Number(right.display_order ?? 0);
    if (order !== 0) return order;

    return (left.designation ?? '').localeCompare(right.designation ?? '', 'fr');
};

export const flattenAnalysisHierarchy = (items = []) => {
    const byUuid = new Map(items.map((item) => [item.uuid, item]));
    const children = new Map();
    const roots = [];

    items.forEach((item) => {
        const parentUuid = item.parent?.uuid;
        if (parentUuid && byUuid.has(parentUuid)) {
            const siblings = children.get(parentUuid) ?? [];
            siblings.push(item);
            children.set(parentUuid, siblings);
            return;
        }
        roots.push(item);
    });

    const flattened = [];
    const visited = new Set();
    const visit = (item, depth) => {
        if (visited.has(item.uuid)) return;
        visited.add(item.uuid);
        flattened.push({ ...item, hierarchy_depth: Math.max(depth, Number(item.hierarchy_depth ?? 0)) });
        (children.get(item.uuid) ?? []).sort(compareAnalyses).forEach((child) => visit(child, depth + 1));
    };

    roots.sort(compareAnalyses).forEach((root) => visit(root, Number(root.hierarchy_depth ?? 0)));
    items.filter((item) => !visited.has(item.uuid)).sort(compareAnalyses).forEach((item) => visit(item, Number(item.hierarchy_depth ?? 0)));

    return flattened;
};

export const availableAnalysisParents = (parents, catalogItemUuid, editingUuid = null) => {
    const byUuid = new Map(parents.map((parent) => [parent.uuid, parent]));
    const descendsFromEditingItem = (candidate) => {
        const visited = new Set();
        let current = candidate;

        while (current?.parent_uuid) {
            if (current.parent_uuid === editingUuid) return true;
            if (visited.has(current.uuid)) return true;
            visited.add(current.uuid);
            current = byUuid.get(current.parent_uuid);
        }
        return false;
    };

    return parents.filter((parent) => (
        parent.catalog_item_uuid === catalogItemUuid
        && parent.uuid !== editingUuid
        && !descendsFromEditingItem(parent)
    ));
};
