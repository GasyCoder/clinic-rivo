/**
 * ADR-214 — un code-barres Code 128 (jeu B) pour les étiquettes des tubes.
 *
 * Code 128 est lu par tous les lecteurs de code-barres, y compris les lecteurs
 * 1D des postes du laboratoire. Le jeu B couvre les caractères imprimables
 * (lettres, chiffres, tiret) : c'est tout ce qu'un code de tube porte
 * (A-L26-00042-1). Aucun dessin côté serveur : le motif se calcule ici et se
 * trace en SVG, net à l'écran comme à l'impression.
 */

// Largeurs barre/espace de chaque symbole 0 à 105 (6 éléments, 11 modules), puis l'arrêt (7 éléments, 13 modules).
const PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232',
];
const STOP = '2331112';
const START_B = 104;

/** Le texte est-il encodable en jeu B ? (caractères ASCII 32 à 126) */
export const code128Encodable = (text) => typeof text === 'string' && text.length > 0 && [...text].every((char) => {
    const code = char.charCodeAt(0);
    return code >= 32 && code <= 126;
});

/** Les valeurs des symboles : départ B, données, clé de contrôle (modulo 103). */
export const code128Values = (text) => {
    if (!code128Encodable(text)) {
        throw new Error('Code 128 B : seulement des caractères imprimables.');
    }
    const data = [...text].map((char) => char.charCodeAt(0) - 32);
    const checksum = data.reduce((sum, value, index) => sum + value * (index + 1), START_B) % 103;

    return [START_B, ...data, checksum];
};

/**
 * Les modules du code, barres à 1 et espaces à 0, sans zone de silence : à
 * l'appelant de laisser dix modules blancs de chaque côté.
 */
export const code128Modules = (text) => {
    const widths = [...code128Values(text).map((value) => PATTERNS[value]), STOP].join('');
    const modules = [];
    [...widths].forEach((width, index) => {
        for (let i = 0; i < Number(width); i++) modules.push(index % 2 === 0 ? 1 : 0);
    });

    return modules;
};

/** Les barres en rectangles `{ x, width }`, en modules — prêtes pour un SVG. */
export const code128Bars = (text) => {
    const modules = code128Modules(text);
    const bars = [];
    let start = null;
    modules.forEach((module, index) => {
        if (module === 1 && start === null) start = index;
        if (module === 0 && start !== null) {
            bars.push({ x: start, width: index - start });
            start = null;
        }
    });
    if (start !== null) bars.push({ x: start, width: modules.length - start });

    return { bars, width: modules.length };
};

export const CODE128_PATTERNS = PATTERNS;
