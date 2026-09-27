/**
 * Les initiales de l'enseigne : « Clinique Saint Georges » → « CSG ».
 *
 * Le logo officiel est un bloc horizontal (emblème + nom + devise) : lisible
 * sur un document ou une page de connexion, illisible dans les 32 px d'une
 * barre latérale. La pastille porte donc les initiales du nom réellement
 * configuré (`rivo.brand`) — jamais un sigle écrit en dur, qui mentirait dès
 * qu'un site change d'enseigne.
 *
 * Bornée à trois lettres : au-delà, la pastille déborderait au lieu de
 * rétrécir le texte jusqu'à l'illisible.
 */
export const monogramOf = (brand) => (brand ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .map((word) => word[0])
    .join('')
    .toUpperCase()
    .slice(0, 3);
