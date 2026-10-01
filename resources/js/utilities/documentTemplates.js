import { familyKey } from './documentFamilies.js';

/**
 * ADR-240 — « Modèles de documents » : les règles d'écran de la gestion
 * documentaire du portail. Le site revérifie tout ; ce fichier dit seulement
 * comment ranger, filtrer et nommer les modèles qu'il a déjà servis.
 */

/** Le nom du module, écrit une fois : menu, titres, liens de retour. */
export const MODULE_NAME = 'Modèles de documents';
export const MODULE_SPACE = 'Gestion documentaire';

/** Les vues d'état, exclusives deux à deux sauf « En service » (actifs + inactifs). */
export const STATUS_VIEWS = [
    { key: 'service', label: 'En service' },
    { key: 'actifs', label: 'Proposés au RH' },
    { key: 'inactifs', label: 'Inactifs' },
    { key: 'a-verifier', label: 'À vérifier' },
    { key: 'archives', label: 'Archivés' },
];

export const statusViewKey = (value) => (STATUS_VIEWS.some((view) => view.key === value) ? value : 'service');

/** Les dossiers dont le contexte est imposé : sinon les dates ne sont pas reprises (ADR-207). */
const EXPECTED_CONTEXTS = { CONTRAT: 'EMPLOYEE_AND_CONTRACT', CONGE: 'EMPLOYEE_AND_LEAVE' };

/** Le contexte qu'un modèle devrait avoir et n'a pas ; null s'il est juste ou libre. */
export function expectedContext(template) {
    const expected = EXPECTED_CONTEXTS[familyKey(template?.document_type)] ?? null;

    return expected && template?.data_context !== expected ? expected : null;
}

/** L'état d'un modèle, en un mot. */
export function templateStatus(template) {
    if (template.archived) return { key: 'archived', label: 'Archivé', variant: 'secondary' };
    if (template.active) return { key: 'active', label: 'Proposé au RH', variant: 'success' };

    return { key: 'inactive', label: 'Inactif', variant: 'outline' };
}

const fold = (text) => String(text ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

/** Recherche sur le nom, le type et la description, sans accents ni casse, tous les mots. */
export function matchesSearch(template, needle) {
    const words = fold(needle).split(/\s+/).filter(Boolean);
    if (!words.length) return true;
    const haystack = fold([template.name, template.document_type, template.description, template.creator].join(' '));

    return words.every((word) => haystack.includes(word));
}

/** Une vue d'état garde-t-elle ce modèle ? */
export function inStatusView(template, view) {
    switch (statusViewKey(view)) {
        case 'archives': return Boolean(template.archived);
        case 'actifs': return !template.archived && Boolean(template.active);
        case 'inactifs': return !template.archived && !template.active;
        case 'a-verifier': return !template.archived && expectedContext(template) !== null;
        default: return !template.archived;
    }
}

/** Les modèles affichés : dossier (null = tous), vue d'état, recherche. */
export function filterTemplates(templates, { folder = null, view = 'service', search = '' } = {}) {
    return templates
        .filter((template) => folder === null || familyKey(template.document_type) === folder)
        .filter((template) => inStatusView(template, view))
        .filter((template) => matchesSearch(template, search))
        .sort((a, b) => Number(b.active && !b.archived) - Number(a.active && !a.archived) || String(a.name).localeCompare(String(b.name), 'fr'));
}

/** Les comptes de chaque vue, dans le dossier ouvert (null = tous) : chacun est ce que donnerait un clic. */
export function statusCounts(templates, folder = null) {
    const scoped = templates.filter((template) => folder === null || familyKey(template.document_type) === folder);

    return Object.fromEntries(STATUS_VIEWS.map((view) => [view.key, scoped.filter((template) => inStatusView(template, view.key)).length]));
}

/**
 * Les dossiers : les connus d'abord, dans leur ordre, puis ceux qu'un type libre a créés.
 * Chacun dit combien de modèles il propose au RH et combien sont en service.
 */
export function buildFolders(templates, families = []) {
    const known = families.map((family) => family.key);
    const extra = [...new Set(templates.map((template) => familyKey(template.document_type)))]
        .filter((key) => !known.includes(key))
        .sort();

    return [...known, ...extra].map((key) => {
        const inFolder = templates.filter((template) => familyKey(template.document_type) === key && !template.archived);
        const family = families.find((item) => item.key === key);

        return {
            key,
            label: family?.label ?? key.charAt(0) + key.slice(1).toLowerCase(),
            context: family?.context ?? null,
            custom: !family,
            active: inFolder.filter((template) => template.active).length,
            total: inFolder.length,
            documents: inFolder.reduce((sum, template) => sum + (template.generated_documents_count ?? 0), 0),
        };
    });
}

/** « 3 modèles », « 1 modèle », « Aucun modèle ». */
export const modelCount = (count) => (count ? `${count} modèle${count > 1 ? 's' : ''}` : 'Aucun modèle');

/**
 * Le dossier choisi dans la fiche : un dossier connu, ou « Autre type » écrit
 * à la main (il devient son propre dossier, ADR-208).
 */
export const CUSTOM_FOLDER = '__custom__';

export function folderChoice(documentType, families = []) {
    if (!String(documentType ?? '').trim()) return '';
    const key = familyKey(documentType);

    return families.some((family) => family.key === key) ? key : CUSTOM_FOLDER;
}

/** Ce qui manque encore à la fiche d'un modèle avant de l'enregistrer ; vide = prêt. */
export function missingTemplateFields(form, { pages = [] } = {}) {
    const missing = [];
    if (!String(form.document_type ?? '').trim()) missing.push('le dossier');
    if (!form.data_context) missing.push('les données reprises');
    if (!String(form.name ?? '').trim()) missing.push('le nom');
    const empty = (html) => !String(html ?? '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim() && !/<(img|table|hr)\b/i.test(String(html ?? ''));
    if (pages.length && pages.every((page) => empty(page.content))) missing.push('le texte du modèle');

    return missing;
}
