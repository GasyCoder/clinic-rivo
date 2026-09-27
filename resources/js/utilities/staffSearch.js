/**
 * ADR-188 — retrouver la fiche employé d'un compte « Personnel clinique ».
 *
 * La recherche se fait dans le navigateur, sur les fiches que le site sert
 * déjà avec le formulaire : aucun appel réseau par frappe, la liste répond au
 * premier caractère. Sans dépendance, pour être testée seule.
 */
const COMBINING_MARKS = /[\u0300-\u036f]/g;

/** « Zéphyr » et « ZEPHYR » se cherchent de la même façon. */
export const normalizeText = (value) => String(value ?? '').normalize('NFD').replace(COMBINING_MARKS, '').toLowerCase();

export const searchTerms = (query) => normalizeText(query).split(/\s+/).filter(Boolean);

const haystack = (employee) => normalizeText(
    [employee.name, employee.employee_number, employee.job_title, employee.department, employee.email].filter(Boolean).join(' '),
);

/**
 * Plus la correspondance est franche, plus la fiche remonte : le matricule
 * exact d'abord, puis un nom qui commence par ce qu'on tape, puis le reste.
 */
const rank = (employee, terms) => {
    if (! terms.length) return 0;

    const [first] = terms;
    if (normalizeText(employee.employee_number) === first) return 0;
    if (normalizeText(employee.name).split(/\s+/).some((word) => word.startsWith(first))) return 1;

    return 2;
};

/**
 * Les fiches qui contiennent tous les mots tapés, dans n'importe quel ordre.
 * Une fiche déjà reliée à un autre compte reste listée — pour qu'on sache
 * pourquoi elle n'est pas choisissable —, mais après les fiches libres.
 *
 * @returns {{ results: Array, total: number }}
 */
export const searchStaff = (employees, query, { isTaken = () => false, limit = 8 } = {}) => {
    const terms = searchTerms(query);
    const matches = (employees ?? [])
        .map((employee, index) => ({ employee, index }))
        .filter(({ employee }) => terms.every((term) => haystack(employee).includes(term)));

    const results = matches
        .map((entry) => ({ ...entry, taken: Boolean(isTaken(entry.employee)), rank: rank(entry.employee, terms) }))
        .sort((left, right) => Number(left.taken) - Number(right.taken) || left.rank - right.rank || left.index - right.index)
        .slice(0, limit)
        .map(({ employee }) => employee);

    return { results, total: matches.length };
};

/**
 * Le texte découpé en morceaux, ceux qui correspondent à la recherche
 * marqués : « Zéphyr » cherché avec « zep » rend [Zép][hyr]. Les accents
 * sont ignorés pour comparer, jamais retirés du texte affiché.
 *
 * @returns {Array<{ text: string, match: boolean }>}
 */
export const highlight = (text, query) => {
    const source = String(text ?? '');
    const terms = searchTerms(query);
    if (! terms.length || source === '') return [{ text: source, match: false }];

    const characters = Array.from(source);
    let normalized = '';
    const owner = [];
    characters.forEach((character, index) => {
        const folded = normalizeText(character);
        normalized += folded;
        for (let position = 0; position < folded.length; position += 1) owner.push(index);
    });

    const marked = new Array(characters.length).fill(false);
    for (const term of terms) {
        let from = normalized.indexOf(term);
        while (from !== -1) {
            for (let position = from; position < from + term.length; position += 1) marked[owner[position]] = true;
            from = normalized.indexOf(term, from + term.length);
        }
    }

    return characters.reduce((segments, character, index) => {
        const last = segments[segments.length - 1];
        if (last && last.match === marked[index]) last.text += character;
        else segments.push({ text: character, match: marked[index] });

        return segments;
    }, []);
};
