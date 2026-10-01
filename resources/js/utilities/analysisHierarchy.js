/**
 * Reconstruit les branches du catalogue à partir de la projection plate de
 * l'API. Les branches restent séparées par prestation : un parent provenant
 * d'une autre prestation ne peut jamais absorber une analyse par accident.
 */
export function buildAnalysisTree(analyses) {
    const sortSiblings = (a, b) => (a.display_order - b.display_order)
        || a.designation.localeCompare(b.designation, 'fr');
    const byCatalogItem = new Map();

    for (const item of analyses) {
        const key = item.catalog_item?.uuid ?? '__without_catalog_item__';
        if (! byCatalogItem.has(key)) byCatalogItem.set(key, []);
        byCatalogItem.get(key).push(item);
    }

    const trees = [];
    for (const items of byCatalogItem.values()) {
        const knownUuids = new Set(items.map((item) => item.uuid));
        const childrenByParent = new Map();
        const roots = [];

        for (const item of items) {
            const parentUuid = item.parent?.uuid;
            if (parentUuid && knownUuids.has(parentUuid)) {
                if (! childrenByParent.has(parentUuid)) childrenByParent.set(parentUuid, []);
                childrenByParent.get(parentUuid).push(item);
            } else {
                // Une recherche ou un filtre peut retirer le parent de la
                // réponse : l'enfant reste visible comme racine temporaire.
                roots.push(item);
            }
        }

        roots.sort(sortSiblings);
        for (const siblings of childrenByParent.values()) siblings.sort(sortSiblings);

        const branch = (item, treeDepth = 0) => ({
            ...item,
            tree_depth: treeDepth,
            children: (childrenByParent.get(item.uuid) ?? []).map((child) => branch(child, treeDepth + 1)),
        });
        trees.push(...roots.map((root) => branch(root)));
    }

    return trees;
}

export const flattenAnalysisTree = (nodes) => nodes.flatMap((node) => [
    node,
    ...flattenAnalysisTree(node.children ?? []),
]);
