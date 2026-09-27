import { Activity, ArrowRight, CircleCheck, ClipboardList, Clock, Hourglass, ScanLine, Siren } from 'lucide-vue-next';
import { itemQuantity } from './episodePathway.js';
import { nextStepIcon } from './nextSteps.js';

/**
 * ADR-177 — ce que le tableau des passages dit, en mots.
 *
 * Le serveur (`ActiveEpisodeBoard`) décide de tout : quel passage est visible,
 * dans quelle vue, dans quel état chez ce service, et quels gestes le compte
 * peut faire (une adresse absente dit « pas ce geste ici »). Ici ne vivent que
 * les mots et les teintes — jamais une règle.
 *
 * Quatre notions ne se confondent pas :
 *
 * ```text
 * besoin        pourquoi le patient vient (les prestations de l'arrivée)
 * suggestion    la prochaine étape proposée par l'accueil — indicative
 * visibilité    tout passage ouvert et accueilli, pour tout service autorisé
 * prise en      une vraie orientation, créée par un vrai geste
 * charge
 * ```
 */

/** Comment chaque service se nomme dans une phrase. */
export const MODULE_WORDS = {
    CARE: { name: 'Soins', at: 'aux Soins', for: 'les Soins' },
    MEDICINE: { name: 'Médecine', at: 'en Médecine', for: 'la Médecine' },
    MATERNITY: { name: 'Maternité', at: 'à la Maternité', for: 'la Maternité' },
};

export const moduleWords = (module) => MODULE_WORDS[module] ?? { name: module, at: module, for: module };

/**
 * Les blocs du tableau. Les trois premiers ne se chevauchent pas — un passage
 * n'est que dans l'un d'eux, la somme de leurs comptes est le nombre de
 * passages vus ici ; « Urgences » est un filtre transversal. Les comptes
 * viennent du serveur : recalculés depuis la page affichée, ils mentiraient
 * dès la deuxième page.
 */
export const boardTiles = (module, counts = {}, activeView = 'waiting') => {
    const words = moduleWords(module);

    return [
        { value: 'waiting', label: 'En attente', hint: 'Arrivés, n° de file', title: `Arrivés et pas encore pris en charge ${words.at}, par ordre d’arrivée — chacun avec son n° de file`, icon: Clock, tone: 'amber' },
        { value: 'in_progress', label: 'En cours chez moi', hint: `Pris en charge ${words.at}`, title: `Passages pris en charge ${words.at}, pas encore terminés`, icon: Activity, tone: 'primary' },
        { value: 'completed', label: 'Terminés chez moi', hint: 'Passage encore ouvert', title: `Prise en charge terminée ${words.at} ; le passage n’est pas encore clos par la Réception`, icon: CircleCheck, tone: 'emerald' },
        { value: 'emergency', label: 'Urgences', hint: 'Tous états confondus', title: 'Passages en urgence — l’urgence est une propriété du passage, jamais du patient', icon: Siren, tone: 'red' },
    ].map((tile) => ({
        ...tile,
        count: counts[tile.value] ?? 0,
        // « Suggérés pour moi » est un filtre du bloc « En attente » : la carte reste allumée.
        active: activeView === tile.value || (tile.value === 'waiting' && activeView === 'suggested'),
    }));
};

/** Les vues qui lisent la file d'attente, numérotée. */
export const isWaitingView = (view) => view === 'waiting' || view === 'suggested';

/** Le titre et la phrase de chaque bloc, au-dessus de sa liste. */
export const blockHeading = (module, view) => {
    const words = moduleWords(module);

    return {
        waiting: { title: 'Patients en attente', hint: `Arrivés et pas encore pris en charge ${words.at}, par ordre d’arrivée. Le n° 1 est le prochain ; une urgence pas encore vue passe en tête, sans numéro.` },
        suggested: { title: 'Patients en attente — suggérés pour moi', hint: 'L’accueil a suggéré ce service comme prochaine étape. C’est indicatif : les autres patients en attente restent dans la file, avec leur numéro.' },
        in_progress: { title: `Pris en charge ${words.at}`, hint: 'Par ancienneté de la prise en charge. Ouvrez le dossier pour poursuivre ; « Remettre en file » annule une prise en charge par erreur.' },
        completed: { title: `Terminés ${words.at}`, hint: 'Du plus récent au plus ancien. Le passage reste ouvert tant que la Réception ne l’a pas clos : sa suite se lit sur la ligne.' },
        emergency: { title: 'Urgences en cours', hint: 'Chaque passage en urgence, quel que soit son état ici.' },
    }[view] ?? { title: 'Passages', hint: '' };
};

/** Ce que dit une vue vide : chaque vue a son propre « rien à signaler ». */
export const emptyState = (module, view) => {
    const words = moduleWords(module);

    return {
        waiting: {
            title: 'Aucun patient en attente',
            hint: `Un patient apparaît ici dès que son accueil est terminé à la Réception, quelle que soit la prochaine étape suggérée — jusqu’à sa prise en charge ${words.at}.`,
        },
        suggested: {
            title: `Aucun passage suggéré pour ${words.for}`,
            hint: 'C’est un état normal : la suggestion de l’accueil est facultative. Les autres patients en attente restent dans la file.',
        },
        in_progress: {
            title: `Aucun passage pris en charge ${words.at}`,
            hint: 'Un passage pris en charge depuis ce tableau apparaît ici jusqu’à la fin de la prise en charge.',
        },
        completed: {
            title: `Aucun passage terminé ${words.at}`,
            hint: 'Les passages terminés ici restent visibles tant que la Réception ne les a pas clos.',
        },
        emergency: {
            title: 'Aucune urgence en cours',
            hint: 'Un passage requalifié en urgence apparaît ici, dans tous les services autorisés.',
        },
    }[view] ?? { title: 'Aucun passage', hint: '' };
};

/**
 * L'état du passage chez ce service, en pastille. Le libellé vient du
 * serveur ; seule la teinte est choisie ici.
 */
export const stateTone = (row) => {
    const state = row?.module?.state;

    if (state === 'REQUESTED') return 'warning';
    if (state === 'IN_PROGRESS') return row.module.is_waiting_on_results ? 'warning' : 'info';
    if (state === 'COMPLETED') return 'success';

    return 'neutral';
};

/**
 * Par où ce patient devrait entrer, dit d'un mot sous son état (`EpisodeEntryPath`) :
 * la Médecine lit qu'il est attendu aux Soins d'abord, les Soins qu'il est
 * attendu en Médecine. Rien quand aucun rappel n'est servi.
 */
export const entryPathHint = (row) => {
    const code = row?.pathway?.code;

    if (code === 'CARE_FIRST') {
        return { label: 'Soins d’abord', icon: nextStepIcon('CARE'), class: 'text-amber-700 dark:text-amber-300' };
    }

    if (code === 'MEDICINE_ONLY') {
        return { label: 'Attendu en Médecine', icon: nextStepIcon('MEDICINE'), class: 'text-sky-700 dark:text-sky-300' };
    }

    return null;
};

/**
 * L'icône de l'état chez ce service, la même que celle de son bloc : une horloge
 * pour qui attend, le pouls pour qui est pris en charge, une coche pour ce qui est
 * terminé. Le libellé reste écrit à côté : la couleur et l'icône ne portent jamais
 * seules le sens.
 */
export const stateIcon = (row) => {
    const state = row?.module?.state;

    if (state === 'IN_PROGRESS') return row.module.is_waiting_on_results ? Hourglass : Activity;
    if (state === 'COMPLETED') return CircleCheck;

    return Clock;
};

/** « Consultation générale · ECG ×2 · +1 », ou `null` quand rien n'a été déclaré. */
export const needSummary = (row, limit = 2) => {
    const needs = row?.needs ?? [];

    if (needs.length === 0) return null;

    const labels = needs.slice(0, limit)
        .map((need) => [need.description, itemQuantity(need.quantity)].filter(Boolean).join(' '))
        .join(' · ');

    return needs.length > limit ? `${labels} · +${needs.length - limit}` : labels;
};

/**
 * L'icône d'un besoin : celle du service de la prestation — la même que dans
 * le menu et les suggestions, pour qu'un service se reconnaisse au même dessin.
 */
export const needIcon = (module) => {
    if (module === 'IMAGING') return ScanLine;

    const icon = nextStepIcon(module);

    // Un service sans icône propre (Réception…) prend celle d'une prestation.
    return icon === ArrowRight ? ClipboardList : icon;
};

/** Les besoins affichés sur la ligne, et combien restent au-delà. */
export const visibleNeeds = (row, limit = 2) => {
    const needs = row?.needs ?? [];

    return { items: needs.slice(0, limit), more: Math.max(0, needs.length - limit) };
};

/** Où est le patient ailleurs, en une ligne : « Médecine · orienté, en attente · n° 3 ». */
export const elsewhereLabel = (item) => [
    item.label,
    item.state_label,
    item.by,
    item.queue_number ? `n° ${item.queue_number}` : null,
].filter(Boolean).join(' · ');

/**
 * Depuis quand compter : un patient en attente, depuis son arrivée — l'ordre
 * même de la file ; un patient pris en charge, depuis la prise en charge ; un
 * passage terminé, depuis la fin.
 */
export const elapsedFrom = (row) => {
    const state = row?.module?.state;

    if (state === 'IN_PROGRESS') return row.module.accepted_at ?? row.oriented_at;
    if (state === 'COMPLETED') return row.module.completed_at ?? row.oriented_at;

    return row?.episode?.started_at ?? row?.oriented_at ?? null;
};

export const elapsedMinutes = (row, now = Date.now()) => {
    const from = elapsedFrom(row);

    return from ? Math.max(0, Math.round((now - new Date(from).getTime()) / 60000)) : null;
};

export const durationLabel = (minutes) => {
    if (minutes === null || minutes === undefined) return '—';
    if (minutes < 60) return `${minutes} min`;

    const hours = Math.floor(minutes / 60);

    return minutes % 60 === 0 ? `${hours} h` : `${hours} h ${String(minutes % 60).padStart(2, '0')}`;
};

export const elapsedCaption = (row) => ({
    IN_PROGRESS: 'en charge',
    COMPLETED: 'depuis la fin',
}[row?.module?.state] ?? 'd’attente');

/**
 * Une teinte de service, jamais une gravité clinique : seule une attente
 * vire à l'ambre (30 min) puis au rouge (1 h). Un patient pris en charge ou
 * terminé n'attend plus personne.
 */
export const waitTone = (row, now = Date.now()) => {
    const minutes = elapsedMinutes(row, now);

    if (!['NONE', 'REQUESTED'].includes(row?.module?.state) || minutes === null) return 'text-muted-foreground';
    if (minutes >= 60) return 'text-red-600 dark:text-red-300';
    if (minutes >= 30) return 'text-amber-600 dark:text-amber-300';

    return 'text-muted-foreground';
};

export const isEmergency = (row) => row?.episode?.priority === 'EMERGENCY';

/** Les paramètres d'une visite du tableau : « En attente » et une recherche vide ne s'écrivent pas dans l'adresse. */
export const boardParams = (view, query) => ({
    ...(view && view !== 'waiting' ? { view } : {}),
    ...(query ? { q: query } : {}),
});
