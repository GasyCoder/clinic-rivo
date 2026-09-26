/**
 * ADR-199 — les dossiers de documents, côté écran : la teinte de chaque dossier
 * connu. Leur liste, leur libellé et la règle « type → dossier » vivent côté
 * serveur (App\Support\Documents\DocumentFamily) ; un dossier inconnu (type
 * libre) prend la teinte neutre.
 */
export const DOCUMENT_FAMILY_TONES = {
    CONTRAT: 'sky',
    CONGE: 'emerald',
    ATTESTATION: 'violet',
    CERTIFICAT: 'amber',
    LETTRE: 'primary',
    DECISION: 'amber',
    AUTRE: 'slate',
};

export const familyTone = (key) => DOCUMENT_FAMILY_TONES[key] ?? 'slate';

/** Le dossier d'un type, comme le serveur le calcule : sans accents, en capitales. */
export const familyKey = (type) => {
    const key = String(type ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().trim().replace(/\s+/g, ' ');

    return key === '' ? 'AUTRE' : key;
};

const plural = (count, word) => `${count} ${word}${count > 1 ? 's' : ''}`;

/**
 * Ce qu'un dossier contient, en deux lignes courtes (une tuile fait ~150 px) :
 * ses canevas, puis ses documents.
 */
export const folderSummary = (folder) => (folder.templates ? `${folder.templates} canevas` : 'Aucun canevas');
export const folderDocuments = (folder) => {
    if (! folder.documents && ! folder.archived) return 'Aucun document';

    return folder.archived ? `${folder.documents} doc. · ${folder.archived} arch.` : plural(folder.documents, 'document');
};
