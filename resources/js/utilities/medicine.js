/**
 * Comment un médicament se décrit, partout dans la Pharmacie.
 *
 * Le même produit s'annonçait de trois façons différentes selon l'écran :
 * « forme · dosage · DCI · code » dans la liste du stock, « dosage · forme »
 * sur sa vignette, « forme · dosage » à la vente comptoir. Trois lectures du
 * même médicament, et une famille qui n'apparaissait qu'à un seul endroit.
 *
 * L'ordre retenu va du plus lisible au plus technique — ce que le pharmacien
 * reconnaît d'abord, puis ce qui lève un doute. La famille n'en fait pas
 * partie : c'est un classement, pas une identité, et elle s'affiche à part
 * (colonne ou pastille) pour rester filtrable.
 */

/** @returns {string} forme · dosage · DCI · code, sans les valeurs absentes */
export function medicineSubtitle(medicine) {
    return [
        medicine?.form_label,
        medicine?.strength,
        medicine?.generic_name,
        medicine?.code,
    ].filter(Boolean).join(' · ');
}

/** Le nom de la famille, ou null — jamais une chaîne vide à afficher. */
export function medicineFamily(medicine) {
    return medicine?.category?.name || null;
}

/**
 * Sur quoi une recherche porte. La famille en fait partie : taper
 * « antibiotiques » doit ramener ce que ce mot classe, comme le ferait le
 * filtre — sinon les deux chemins ne trouvent pas la même chose.
 */
export function medicineMatches(medicine, needle) {
    const query = String(needle ?? '').trim().toLocaleLowerCase();

    if (query === '') {
        return true;
    }

    return [
        medicine?.name,
        medicine?.generic_name,
        medicine?.code,
        medicine?.barcode,
        medicine?.form_label,
        medicine?.strength,
        medicineFamily(medicine),
    ].filter(Boolean).some((value) => String(value).toLocaleLowerCase().includes(query));
}
