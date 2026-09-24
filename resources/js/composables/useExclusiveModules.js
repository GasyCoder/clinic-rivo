import { nextTick } from 'vue';

/**
 * Un seul module ouvert à la fois (ADR-178).
 *
 * Ouvrir un module referme les autres : on règle un service à la fois, et la
 * page ne s'allonge jamais de quatre grilles dépliées. Pendant une recherche,
 * chaque module qui contient un résultat s'ouvre de lui-même — un résultat
 * caché n'en serait pas un —, mais en ouvrir un à la main referme les autres,
 * comme hors recherche.
 *
 * Refermer le module du dessus fait remonter tout ce qui suit : l'en-tête sur
 * lequel on vient de cliquer resterait alors hors de vue. `keepHeaderInPlace`
 * le garde à l'endroit exact où on l'a cliqué.
 */

/** L'en-tête d'un module, tel que `PermissionModuleCard` le rend. */
const moduleHeader = (key) => (typeof document === 'undefined'
    ? null
    : document.querySelector(`[aria-controls="permission-module-${key}"]`));

export const keepHeaderInPlace = async (key, change) => {
    const before = moduleHeader(key)?.getBoundingClientRect().top;

    change();

    if (before === undefined) return;

    await nextTick();

    const after = moduleHeader(key)?.getBoundingClientRect().top;

    if (after !== undefined && Math.abs(after - before) > 1) window.scrollBy({ top: after - before });
};

/**
 * Le nouvel état après un clic sur l'en-tête d'un module.
 *
 * @param {string}   key        le module cliqué
 * @param {boolean}  opening    il était fermé
 * @param {boolean}  filtering  une recherche ou un filtre est actif
 * @param {string[]} shownKeys  les modules affichés (ceux qui ont un résultat)
 * @param {Set}      collapsed  pendant une recherche : les modules refermés à la main
 * @returns {{ expanded: string[] } | { collapsed: Set<string> }}
 */
export const nextModuleState = ({ key, opening, filtering, shownKeys, collapsed }) => {
    if (! filtering) return { expanded: opening ? [key] : [] };

    if (opening) return { collapsed: new Set(shownKeys.filter((shown) => shown !== key)) };

    return { collapsed: new Set([...collapsed, key]) };
};
