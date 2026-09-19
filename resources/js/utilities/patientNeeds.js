import { Bandage, Layers, Pill, Stethoscope, UsersRound } from 'lucide-vue-next';

/**
 * Où chaque patient a encore besoin d'aller (ADR-119).
 *
 * Le contenu — quels patients attendent quel service, et combien par
 * combinaison — est calculé par Laravel (`PatientServiceNeeds`) : ici ne vit
 * que la façon de le dire. Les icônes sont celles du menu latéral, pour qu'un
 * service se reconnaisse partout au même dessin.
 *
 * Chaque patient tombe dans **une seule** combinaison : « Médecine seulement »
 * exclut celui qui attend aussi la pharmacie. La somme des cases est donc le
 * nombre de patients.
 */
export const NEED_SERVICES = {
    MEDICINE: { label: 'Médecine', icon: Stethoscope },
    CARE: { label: 'Soins', icon: Bandage },
    PHARMACY: { label: 'Pharmacie', icon: Pill },
};

/** L'ordre du parcours d'un patient : les soins précèdent le médecin, qui précède la pharmacie. */
const FLOW_ORDER = ['CARE', 'MEDICINE', 'PHARMACY'];

export const NEED_ALL = 'ALL';
export const NEED_NONE = 'NONE';
export const NEED_EVERYTHING = 'MEDICINE,CARE,PHARMACY';

/**
 * « Soins + Médecine », « Médecine seulement », « Aucun de ces services ».
 *
 * @param {string[]} services
 */
export const needCombinationLabel = (services) => {
    if (services.length === 0) {
        return 'Aucun de ces services';
    }

    if (services.length === 1) {
        return `${NEED_SERVICES[services[0]].label} seulement`;
    }

    return FLOW_ORDER
        .filter((service) => services.includes(service))
        .map((service) => NEED_SERVICES[service].label)
        .join(' + ');
};

/**
 * Les cinq cases du haut de la liste : tous, chaque service seul, et les trois
 * à la fois. Ce sont les questions que la Réception se pose le plus.
 */
const TILES = {
    [NEED_ALL]: {
        label: 'Tous les patients',
        hint: 'Tous les dossiers',
        title: 'Tous les patients, quel que soit leur besoin',
        icon: UsersRound,
        tone: 'neutral',
    },
    MEDICINE: {
        label: 'Médecine seulement',
        hint: 'Attend le médecin',
        title: 'Patients qui n’attendent que le médecin — ni les soins, ni la pharmacie',
        icon: NEED_SERVICES.MEDICINE.icon,
        tone: 'primary',
    },
    CARE: {
        label: 'Soins seulement',
        hint: 'Attend les soins',
        title: 'Patients qui n’attendent que les soins — ni le médecin, ni la pharmacie',
        icon: NEED_SERVICES.CARE.icon,
        tone: 'sky',
    },
    PHARMACY: {
        label: 'Pharmacie seulement',
        hint: 'Attend la pharmacie',
        title: 'Patients qui n’attendent que la pharmacie — ni le médecin, ni les soins',
        icon: NEED_SERVICES.PHARMACY.icon,
        tone: 'emerald',
    },
    [NEED_EVERYTHING]: {
        label: 'Les 3 services',
        hint: 'Soins, médecin, pharmacie',
        title: 'Patients qui attendent à la fois les soins, le médecin et la pharmacie',
        icon: Layers,
        tone: 'amber',
    },
};

const countOf = (facets, key) => facets.find((facet) => facet.key === key)?.count ?? 0;

/**
 * Les cases-compteurs pour `QueueCounters` : la carte est le filtre, et le
 * compte vient du serveur — il annonce ce que donnerait un clic sur la carte,
 * les autres filtres inchangés.
 *
 * @param {Array<{ key: string, count: number }>} facets
 * @param {string | null} selected La combinaison filtrée, `null` pour tous.
 */
export const needTiles = (facets, selected) => {
    const current = selected ?? NEED_ALL;

    return Object.entries(TILES).map(([key, tile]) => ({
        value: key,
        ...tile,
        count: countOf(facets, key),
        active: key === current,
    }));
};

/** Le libellé de la combinaison filtrée, pour la pastille de filtre actif. */
export const needFilterLabel = (key) => {
    if (key === NEED_NONE) {
        return needCombinationLabel([]);
    }

    if (key === NEED_EVERYTHING) {
        return 'Les 3 services';
    }

    return needCombinationLabel(String(key).split(','));
};

const STATE_TEXT = {
    MEDICINE: { PENDING: 'en attente', IN_PROGRESS: 'en consultation' },
    CARE: { PENDING: 'en attente', IN_PROGRESS: 'pris en charge' },
    // Un besoin auprès de la pharmacie ne dit pas s'il attend la facture, le
    // règlement ou la délivrance : cet état-là est financier, on ne le sert pas.
    PHARMACY: { PENDING: '', PARTIAL: 'délivrance partielle' },
};

/** Ce qu'on ajoute au nom du service sur la ligne d'un patient : « en attente ». */
export const needStateText = (need) => STATE_TEXT[need.service]?.[need.state] ?? '';

/**
 * La couleur d'une pastille : en attente (ambre), quelqu'un s'en occupe
 * maintenant (vert), la pharmacie (neutre).
 */
export const needVariant = (need) => {
    if (need.service === 'PHARMACY') {
        return 'secondary';
    }

    return need.state === 'IN_PROGRESS' ? 'success' : 'warning';
};

/**
 * L'infobulle d'une pastille : le détail que la pastille n'a pas la place de
 * porter — depuis quand, et sur quel passage.
 *
 * @param {{ service: string, state: string, episode_number?: string|null }} need
 * @param {string|null} [sinceText] Déjà formaté (« il y a 12 minutes »).
 */
export const needTitle = (need, sinceText = '') => {
    const since = String(sinceText ?? '');
    const service = NEED_SERVICES[need.service]?.label ?? need.service;
    const text = needStateText(need) || (need.service === 'PHARMACY' ? 'médicaments à retirer' : '');
    // « il y a 12 minutes » se lit « depuis 12 minutes » ; une heure dans le
    // futur (horloges décalées) ne se lit pas « depuis », on l'omet.
    const elapsed = since.startsWith('il y a ') ? `depuis ${since.slice('il y a '.length)}` : '';
    const details = [text, elapsed, need.episode_number && `passage ${need.episode_number}`]
        .filter(Boolean)
        .join(' · ');

    return details ? `${service} — ${details}` : service;
};

/**
 * ADR-120 — les onglets du répertoire classent les patients dans les trois
 * états que la ligne affiche déjà (`presenceState`) : un seul vocabulaire.
 * Les comptes viennent du serveur (`segments.status`), chacun étant ce que
 * donnerait un clic sur son onglet, les autres filtres inchangés.
 */
export const STATUS_TABS = [
    { value: null, key: 'all', label: 'Tous', hint: 'Tous les patients' },
    { value: 'open', key: 'open', label: 'Besoin en cours', hint: 'Un passage est ouvert : le patient est pris en charge ou attend un service' },
    { value: 'settlement', key: 'settlement', label: 'En attente de règlement', hint: 'La partie clinique est terminée : la Réception doit prononcer la sortie' },
    { value: 'none', key: 'none', label: 'Aucun passage ouvert', hint: 'Aucun passage ouvert en ce moment' },
];

export const statusTabs = (segments, selected) => STATUS_TABS.map((tab) => ({
    ...tab,
    count: segments?.status?.[tab.key] ?? 0,
    active: (selected ?? null) === tab.value,
}));

/** La pastille de filtre actif d'un onglet ; « Tous » n'en a pas. */
export const statusFilterLabel = (value) => STATUS_TABS.find((tab) => tab.value === value && value !== null)?.label ?? null;
