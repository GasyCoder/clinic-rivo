/**
 * La fiche d'une analyse du catalogue (ADR-063) : ses étapes, ce qui manque,
 * ce qui part au serveur — écrit une fois, lu par la création et la
 * modification, au site comme au portail.
 *
 *   identite        prestation, désignation, code, niveau, groupe parent
 *   resultat        type de résultat, saisie au laboratoire, unité, valeurs
 *   normes          références par profil, bornes critiques
 *   sous-analyses   seulement pour un groupe
 *   recap           relire et terminer
 *
 * La création ne demande que l'identité : la fiche n'existe qu'une fois créée,
 * puis chaque étape s'enregistre toute seule (enregistrement automatique).
 */
import { criticalRangesForm, criticalRangesPayload } from './criticalRanges.js';

export const LEVELS = {
    NORMAL: { label: 'Analyse simple', hint: 'Un résultat à elle seule, sans groupe au-dessus. Ex : Glycémie.' },
    PARENT: { label: 'Groupe', hint: 'Réunit plusieurs résultats sous un titre. Ex : NFS, Ionogramme.' },
    CHILD: { label: 'Sous-analyse', hint: 'Un résultat qui appartient à un groupe. Ex : Hémoglobine dans la NFS.' },
};

export const RESULT_TYPES = {
    NUMERIC: { label: 'Numérique', hint: 'Une valeur et son unité, comparée à la norme.', example: '1,05 g/L' },
    TEXT: { label: 'Texte', hint: 'Un texte libre ou une description.', example: 'Aspect clair' },
    CHOICE: { label: 'Choix', hint: 'Une valeur parmi une liste proposée.', example: 'A+, B−…' },
    BOOLEAN: { label: 'Oui / Non', hint: 'Deux états.', example: 'Positif / Négatif' },
};

/** Les modes de saisie qui lisent la liste des valeurs proposées (LabEntryMode). */
const CHOICE_ENTRY_MODES = ['CHOICE', 'MULTI_CHOICE', 'NEG_POS_CHOICE'];

export const isNumericResult = (data) => data?.result_type === 'NUMERIC' || data?.entry_mode === 'NUMERIC';
export const usesPredefinedValues = (data) => data?.result_type === 'CHOICE' || CHOICE_ENTRY_MODES.includes(data?.entry_mode);
export const mayHaveParent = (level) => level === 'PARENT' || level === 'CHILD';

export const STEPS = [
    { key: 'identite', label: 'Identité', hint: 'Prestation, désignation, code et niveau' },
    { key: 'resultat', label: 'Résultat', hint: 'Comment le laboratoire saisit le résultat' },
    { key: 'normes', label: 'Normes', hint: 'Références et bornes critiques, facultatives' },
    { key: 'sous-analyses', label: 'Sous-analyses', hint: 'Les résultats réunis dans ce groupe' },
    { key: 'recap', label: 'Récapitulatif', hint: 'Relire, puis terminer' },
];

/** Les étapes de cette fiche : les sous-analyses n'existent que pour un groupe. */
export const stepsFor = (level) => STEPS
    .filter((step) => step.key !== 'sous-analyses' || level === 'PARENT')
    .map((step, index) => ({ ...step, number: index + 1 }));

/** Où vit chaque champ : une erreur du serveur ramène à son étape. */
const STEP_OF_FIELD = {
    catalog_item_uuid: 'identite', designation: 'identite', code: 'identite', level: 'identite', parent_uuid: 'identite',
    result_type: 'resultat', entry_mode: 'resultat', unit: 'resultat', predefined_values: 'resultat',
    predefined_values_text: 'resultat', exam_category: 'resultat', description: 'resultat', display_order: 'resultat', is_bold: 'resultat',
    reference_general: 'normes', reference_male: 'normes', reference_female: 'normes',
    reference_child_male: 'normes', reference_child_female: 'normes', critical_ranges: 'normes',
    children: 'sous-analyses',
};

export const stepOfField = (field) => STEP_OF_FIELD[String(field).split('.')[0]] ?? null;

const blank = (value) => String(value ?? '').trim() === '';

/** Ce qui manque encore pour enregistrer, dans l'ordre de l'écran. */
export function missingFields(data) {
    const missing = [];
    if (blank(data.catalog_item_uuid)) missing.push({ field: 'catalog_item_uuid', label: 'la prestation Laboratoire', step: 'identite' });
    if (blank(data.designation)) missing.push({ field: 'designation', label: 'la désignation', step: 'identite' });
    if (blank(data.code)) missing.push({ field: 'code', label: 'le code', step: 'identite' });
    if (data.level === 'CHILD' && blank(data.parent_uuid)) missing.push({ field: 'parent_uuid', label: 'le groupe parent', step: 'identite' });
    if (blank(data.result_type)) missing.push({ field: 'result_type', label: 'le type de résultat', step: 'resultat' });

    if (data.level === 'PARENT') {
        const incomplete = countIncompleteChildren(data.children ?? []);
        if (incomplete) {
            missing.push({
                field: 'children',
                label: incomplete === 1 ? 'une sous-analyse sans code ou sans désignation' : `${incomplete} sous-analyses sans code ou sans désignation`,
                step: 'sous-analyses',
            });
        }
    }

    return missing;
}

const countIncompleteChildren = (children) => children.reduce((count, child) => count
    + (blank(child.code) || blank(child.designation) || blank(child.result_type) ? 1 : 0)
    + (child.level === 'PARENT' ? countIncompleteChildren(child.children ?? []) : 0), 0);

/** « la désignation et le code » — ce qui manque, en une phrase. */
export const missingSentence = (missing) => {
    const labels = missing.map((item) => item.label);
    if (labels.length <= 1) return labels[0] ?? '';

    return `${labels.slice(0, -1).join(', ')} et ${labels.at(-1)}`;
};

/* ------------------------------------------------------------------ */
/* Le formulaire                                                       */
/* ------------------------------------------------------------------ */

export const emptyChild = () => ({
    uuid: null,
    code: '',
    designation: '',
    description: '',
    exam_category: '',
    level: 'CHILD',
    result_type: 'NUMERIC',
    entry_mode: null,
    reference_general: '',
    reference_male: '',
    reference_female: '',
    reference_child_male: '',
    reference_child_female: '',
    critical_ranges: criticalRangesForm(),
    unit: '',
    predefined_values_text: '',
    is_bold: false,
    children: [],
});

/**
 * Une sous-analyse lue sur le serveur. Seules les actives : une sous-analyse
 * retirée est désactivée, jamais supprimée ; la recharger dans le formulaire
 * la réactiverait au prochain enregistrement.
 */
const childFrom = (row) => ({
    uuid: row.uuid,
    code: row.code ?? '',
    designation: row.designation ?? '',
    description: row.description ?? '',
    exam_category: row.exam_category ?? '',
    level: row.level,
    result_type: row.result_type,
    entry_mode: row.entry_mode ?? null,
    reference_general: row.reference_general ?? '',
    reference_male: row.reference_male ?? '',
    reference_female: row.reference_female ?? '',
    reference_child_male: row.reference_child_male ?? '',
    reference_child_female: row.reference_child_female ?? '',
    critical_ranges: criticalRangesForm(row.critical_ranges),
    unit: row.unit ?? '',
    predefined_values_text: (row.predefined_values ?? []).join('|'),
    is_bold: row.is_bold ?? false,
    children: row.level === 'PARENT' ? activeChildren(row.children).map(childFrom) : [],
});

const activeChildren = (rows) => (rows ?? []).filter((row) => row.is_active !== false);

export const newAnalysisForm = ({ siteCode = null, catalogItemUuid = '' } = {}) => ({
    ...(siteCode ? { site_code: siteCode } : {}),
    catalog_item_uuid: catalogItemUuid,
    parent_uuid: '',
    code: '',
    level: 'NORMAL',
    designation: '',
    description: '',
    exam_category: '',
    result_type: 'NUMERIC',
    entry_mode: null,
    reference_general: '',
    reference_male: '',
    reference_female: '',
    reference_child_male: '',
    reference_child_female: '',
    critical_ranges: criticalRangesForm(),
    unit: '',
    predefined_values_text: '',
    display_order: 0,
    is_active: true,
    is_bold: false,
    children: [],
});

export const analysisFormFrom = (analysis, { siteCode = null } = {}) => ({
    ...(siteCode ? { site_code: siteCode } : {}),
    catalog_item_uuid: analysis.catalog_item?.uuid ?? '',
    parent_uuid: analysis.parent?.uuid ?? '',
    code: analysis.code ?? '',
    level: analysis.level,
    designation: analysis.designation ?? '',
    description: analysis.description ?? '',
    exam_category: analysis.exam_category ?? '',
    result_type: analysis.result_type,
    entry_mode: analysis.entry_mode ?? null,
    reference_general: analysis.reference_general ?? '',
    reference_male: analysis.reference_male ?? '',
    reference_female: analysis.reference_female ?? '',
    reference_child_male: analysis.reference_child_male ?? '',
    reference_child_female: analysis.reference_child_female ?? '',
    critical_ranges: criticalRangesForm(analysis.critical_ranges),
    unit: analysis.unit ?? '',
    predefined_values_text: (analysis.predefined_values ?? []).join('|'),
    display_order: analysis.display_order ?? 0,
    is_active: analysis.is_active ?? true,
    is_bold: analysis.is_bold ?? false,
    children: analysis.level === 'PARENT' ? activeChildren(analysis.children).map(childFrom) : [],
});

export const splitPredefinedValues = (text) => String(text ?? '')
    .split('|')
    .map((value) => value.trim())
    .filter(Boolean)
    .filter((value, index, all) => all.indexOf(value) === index);

const childPayload = (child, index) => ({
    ...child,
    display_order: index + 1,
    predefined_values: splitPredefinedValues(child.predefined_values_text),
    critical_ranges: criticalRangesPayload(child.critical_ranges),
    children: child.level === 'PARENT' ? (child.children ?? []).map(childPayload) : [],
});

/** Ce qui part au serveur : listes découpées, parent retiré quand le niveau n'en a pas. */
export const analysisPayload = (data) => ({
    ...data,
    parent_uuid: mayHaveParent(data.level) ? (data.parent_uuid || null) : null,
    predefined_values: splitPredefinedValues(data.predefined_values_text),
    critical_ranges: criticalRangesPayload(data.critical_ranges),
    children: data.level === 'PARENT' ? (data.children ?? []).map(childPayload) : [],
});

const sameCode = (a, b) => String(a ?? '').trim().toLowerCase() === String(b ?? '').trim().toLowerCase();

/**
 * Après un enregistrement, une sous-analyse nouvelle reçoit son UUID du
 * serveur : sans lui, l'enregistrement suivant la recréerait (et serait
 * refusé, son code étant déjà pris). Rapprochée par son code, sinon par sa
 * place ; rien n'est touché pour une sous-analyse qui a déjà le sien.
 *
 * @returns {boolean} vrai si un UUID a été posé
 */
export function adoptChildUuids(formChildren, savedChildren) {
    const saved = activeChildren(savedChildren);
    const known = new Set(formChildren.map((child) => child.uuid).filter(Boolean));
    const free = saved.filter((row) => ! known.has(row.uuid));
    let changed = false;

    formChildren.forEach((child, index) => {
        let match = saved.find((row) => row.uuid && row.uuid === child.uuid) ?? null;

        if (! child.uuid) {
            match = free.find((row) => sameCode(row.code, child.code))
                ?? (free.includes(saved[index]) ? saved[index] : null);

            if (match) {
                child.uuid = match.uuid;
                free.splice(free.indexOf(match), 1);
                changed = true;
            }
        }

        if (match && child.level === 'PARENT' && match.level === 'PARENT') {
            changed = adoptChildUuids(child.children ?? [], match.children ?? []) || changed;
        }
    });

    return changed;
}

/** Recherche d'une prestation, sans accents ni casse, sur le code et le nom, tous les mots. */
const fold = (value) => String(value ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

export const matchesCatalogItem = (item, query) => {
    const words = fold(query).split(/\s+/).filter(Boolean);
    const haystack = fold(`${item.code} ${item.name}`);

    return words.every((word) => haystack.includes(word));
};
