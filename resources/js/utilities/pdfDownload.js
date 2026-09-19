/**
 * Le PDF d'un document de la clinique est produit par le navigateur : la
 * fenêtre d'impression propose « Enregistrer au format PDF ». Aucun PDF n'est
 * généré côté serveur (ADR-070) — ce qui garde un texte sélectionnable et ne
 * demande aucune dépendance de plus à déployer sur trois sites.
 *
 * Le seul contrôle qu'on ait sur le fichier est son nom : les navigateurs
 * proposent le titre de la page. On le pose donc le temps de l'impression,
 * puis on le rend — le fichier s'appelle « Journaux de traitement - A-26-0001 -
 * BEZARA Florent » plutôt que « Clinique Saint Georges ».
 */

// Ce qu'un nom de fichier ne peut pas porter sur Windows, macOS ou Linux.
// eslint-disable-next-line no-control-regex
const FORBIDDEN = /[\\/:*?"<>|\u0000-\u001f]+/g;

/** Les morceaux qui existent, séparés par « - », sans caractère interdit. */
export const pdfFileName = (...parts) => parts
    .map((part) => String(part ?? '').replace(FORBIDDEN, ' ').replace(/\s+/g, ' ').trim())
    .filter(Boolean)
    .join(' - ')
    .slice(0, 120);

/**
 * Ouvre la fenêtre d'impression sous ce titre, puis restaure celui de la
 * page. `afterprint` plutôt qu'un retour de `print()` : selon le navigateur
 * l'appel bloque jusqu'à la fermeture de la fenêtre, ou rend la main aussitôt.
 */
export function printAsPdf(fileTitle, { win = window, doc = document } = {}) {
    const previous = doc.title;

    const restore = () => {
        doc.title = previous;
        win.removeEventListener('afterprint', restore);
    };

    doc.title = fileTitle;
    win.addEventListener('afterprint', restore);
    win.print();
}
