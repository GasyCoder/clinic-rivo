/**
 * Les désignations d'un site, rangées comme la clinique les pense : par
 * domaine, l'Imagerie séparée en Échographie et ECG, le Laboratoire par
 * discipline.
 *
 * Rien n'est deviné ici. La famille d'un examen d'imagerie est celle réglée au
 * catalogue (ADR-106) — un examen sans famille reste « non classé », jamais
 * rangé d'office — et la discipline d'une analyse est celle que porte son
 * analyse racine dans le catalogue des analyses (ADR-063), servie par le site.
 * Cet utilitaire ne fait que les lire et les compter.
 */

export const IMAGING = 'IMAGING';
export const LABORATORY = 'LABORATORY';
export const IMAGING_UNCLASSIFIED = 'IMAGING:UNCLASSIFIED';
export const NO_DISCIPLINE = '—';

/** Les familles d'imagerie, dans l'ordre où la clinique les lit. */
const IMAGING_FAMILIES = [
    { key: 'IMAGING:ULTRASOUND', modality: 'ULTRASOUND', label: 'Échographie' },
    { key: 'IMAGING:CARDIOLOGY', modality: 'CARDIOLOGY', label: 'ECG / Cardiologie' },
    { key: IMAGING_UNCLASSIFIED, modality: null, label: 'Imagerie non classée' },
];

/** Le groupe d'une désignation : son domaine, ou sa famille d'imagerie. */
export function catalogGroupKey(item) {
    if (item.module !== IMAGING) return item.module;

    return IMAGING_FAMILIES.find((family) => family.modality && family.modality === item.imaging_modality)?.key
        ?? IMAGING_UNCLASSIFIED;
}

/** Le domaine dont relève un groupe (`IMAGING:ULTRASOUND` → `IMAGING`). */
export const groupModule = (key) => String(key ?? '').split(':')[0];

/**
 * Les groupes présents, dans l'ordre des domaines servi par le site, les
 * familles d'imagerie à la place de l'Imagerie. Un groupe vide n'est pas
 * proposé : une puce qui ouvre une liste vide n'aide personne.
 *
 * @param {Array} items les désignations déjà filtrées (hors groupe)
 * @param {Array} modules `[{ value, label }]`, l'ordre du site
 */
export function catalogGroups(items, modules = []) {
    const order = [];
    modules.forEach((module) => {
        if (module.value === IMAGING) IMAGING_FAMILIES.forEach((family) => order.push({ key: family.key, label: family.label }));
        else order.push({ key: module.value, label: module.label });
    });

    const stats = new Map();
    items.forEach((item) => {
        const key = catalogGroupKey(item);
        const entry = stats.get(key) ?? { count: 0, billable: 0, priced: 0, label: item.module_label };
        entry.count += 1;
        if (item.billable) {
            entry.billable += 1;
            if (item.current_standard_tariff) entry.priced += 1;
        }
        stats.set(key, entry);
    });

    const known = new Set(order.map((group) => group.key));
    // Un domaine que le site servirait sans le déclarer reste visible, en fin de liste.
    stats.forEach((entry, key) => {
        if (! known.has(key)) order.push({ key, label: entry.label ?? key });
    });

    return order
        .filter((group) => stats.has(group.key))
        .map((group) => {
            const { count, billable, priced } = stats.get(group.key);

            return {
                key: group.key,
                module: groupModule(group.key),
                label: group.label,
                count,
                billable,
                priced,
                unclassified: group.key === IMAGING_UNCLASSIFIED,
            };
        });
}

/** Un libellé comparable : sans accents, sans casse, espaces simplifiés. */
export function normalizeLabel(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLocaleLowerCase('fr')
        .replace(/[’`]/g, "'")
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Les désignations qui portent le même nom qu'une autre du même domaine.
 * Deux « Glycémie » au Laboratoire sont deux prestations, deux tarifs et
 * deux choix à la Réception : la seconde est presque toujours un doublon.
 * Seules les désignations en service comptent — une archivée n'est plus
 * proposée à personne.
 *
 * @returns {Map<string, Array<{uuid: string, code: string}>>} par uuid, les autres
 */
export function duplicateNames(items) {
    const byName = new Map();
    items.filter((item) => ! item.archived).forEach((item) => {
        const key = `${item.module}|${normalizeLabel(item.name)}`;
        byName.set(key, [...(byName.get(key) ?? []), item]);
    });

    const others = new Map();
    byName.forEach((group) => {
        if (group.length < 2) return;
        group.forEach((item) => others.set(
            item.uuid,
            group.filter((other) => other.uuid !== item.uuid).map((other) => ({ uuid: other.uuid, code: other.code })),
        ));
    });

    return others;
}

/** La discipline d'une analyse, comparable (`HEMATOLOGIE`), ou `—` sans discipline. */
export function disciplineKey(item) {
    const value = String(item.analysis_discipline ?? '').trim();

    return value ? value.toLocaleUpperCase('fr') : NO_DISCIPLINE;
}

/** « SEROLOGIE (TECHNIQUE ELISA…) » se lit « Serologie » ; le détail reste au survol. */
export function disciplineLabel(key) {
    if (key === NO_DISCIPLINE) return 'Sans discipline';
    const short = key.split(' (')[0].trim();

    return short.charAt(0) + short.slice(1).toLocaleLowerCase('fr');
}

/** Les disciplines présentes parmi les désignations du Laboratoire, les plus fournies d'abord. */
export function laboratoryDisciplines(items) {
    const counts = new Map();
    items.filter((item) => item.module === LABORATORY).forEach((item) => {
        const key = disciplineKey(item);
        counts.set(key, (counts.get(key) ?? 0) + 1);
    });

    return Array.from(counts, ([key, count]) => ({ key, label: disciplineLabel(key), title: key === NO_DISCIPLINE ? 'Aucune discipline n’est renseignée sur l’analyse racine' : key, count }))
        .sort((left, right) => {
            if (left.key === NO_DISCIPLINE) return 1;
            if (right.key === NO_DISCIPLINE) return -1;

            return right.count - left.count || left.label.localeCompare(right.label, 'fr');
        });
}

/**
 * Ce qu'un Super Administrateur doit encore régler sur ce site, lu sur les
 * désignations en service. Chaque point est un fait, jamais une correction
 * faite à sa place.
 */
export function catalogAttention(items) {
    const active = items.filter((item) => ! item.archived);
    const duplicates = duplicateNames(active);

    return {
        // ADR-052 — sans politique Personnel, un passage Personnel ne se facture pas.
        staffUnclassified: active.filter((item) => item.billable && item.staff_coverage_policy === 'UNCLASSIFIED').length,
        duplicates: duplicates.size,
        // ADR-106 — un examen sans famille n'apparaît dans aucun onglet ECG / Échographie.
        imagingUnclassified: active.filter((item) => catalogGroupKey(item) === IMAGING_UNCLASSIFIED).length,
        // ADR-063 — une prestation de Laboratoire sans analyse n'a rien où saisir un résultat.
        laboratoryWithoutAnalysis: active.filter((item) => item.module === LABORATORY && ! item.analyses_count).length,
    };
}

/** Le filtre d'un point d'attention, appliqué à une désignation. */
export function matchesAttention(item, attention, duplicates) {
    switch (attention) {
        case 'STAFF': return ! item.archived && item.billable && item.staff_coverage_policy === 'UNCLASSIFIED';
        case 'DUPLICATES': return duplicates.has(item.uuid);
        case 'IMAGING_UNCLASSIFIED': return ! item.archived && catalogGroupKey(item) === IMAGING_UNCLASSIFIED;
        case 'LAB_NO_ANALYSIS': return ! item.archived && item.module === LABORATORY && ! item.analyses_count;
        default: return true;
    }
}

/** L'adresse du catalogue des analyses, ouverte sur une prestation du Laboratoire. */
export function analysesUrl(siteCode, item) {
    const params = new URLSearchParams({ site: siteCode, catalog_item: item.uuid });

    return `/super-admin/analyses?${params.toString()}`;
}

/** L'adresse des tarifs, ouverte sur une désignation (depuis le catalogue des analyses). */
export function tariffsUrl(siteCode, code) {
    const params = new URLSearchParams({ site: siteCode, q: code });

    return `/super-admin/workspaces/tariffs?${params.toString()}`;
}
