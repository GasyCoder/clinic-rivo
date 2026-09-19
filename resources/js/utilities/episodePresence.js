/**
 * Quatre états mutuellement exclusifs, du plus au moins urgent — la même
 * lecture partout où l'on affiche « ce patient a-t-il un passage ouvert
 * maintenant ? » : le répertoire des patients (une ligne par patient) et le
 * dossier d'un patient (son propre en-tête). Une seule définition évite que
 * les deux écrans finissent par employer des mots ou des couleurs
 * différents pour le même fait — précisément le défaut signalé le
 * 2026-09-19 : le répertoire affichait « Passage en cours » en orange, le
 * dossier du même patient ne reprenait ce mot nulle part.
 *
 * « Passage en cours » ne dit qu'une chose : un passage est ouvert — jamais
 * une appréciation clinique du patient. Il couvrait aussi le passage dont
 * Médecine avait déjà conclu, si bien qu'un médecin venant de clôturer
 * relisait ici « en cours » et croyait à une contradiction. Ce cas porte
 * désormais son propre libellé : la partie clinique est finie, c'est la
 * Réception qui doit prononcer la sortie.
 *
 * Un patient peut avoir plusieurs passages : celui encore en soins prime sur
 * celui qui n'attend qu'un règlement.
 *
 * @param {{ activeEmergencyCount?: number, openCount?: number, settlementCount?: number }} counts
 */
export const presenceState = (counts) => {
    const activeEmergencyCount = counts.activeEmergencyCount ?? 0;
    const openCount = counts.openCount ?? 0;
    const settlementCount = counts.settlementCount ?? 0;

    if (activeEmergencyCount > 0) {
        return {
            label: 'Urgence',
            variant: 'destructive',
            dot: 'bg-red-500',
        };
    }

    if (openCount - settlementCount > 0) {
        return {
            label: 'Passage en cours',
            variant: 'warning',
            dot: 'bg-amber-500',
        };
    }

    if (settlementCount > 0) {
        return {
            label: 'En attente de règlement',
            variant: 'secondary',
            dot: 'bg-sky-500',
        };
    }

    return {
        label: 'Aucun passage ouvert',
        variant: 'outline',
        dot: 'bg-slate-300 dark:bg-slate-600',
    };
};

/** Les trois compteurs à partir de la liste complète des passages d'UN patient (dossier). */
export const presenceCountsFromEpisodes = (episodes) => ({
    activeEmergencyCount: episodes.filter((episode) => episode.status === 'OPEN' && episode.priority === 'EMERGENCY').length,
    openCount: episodes.filter((episode) => episode.status === 'OPEN').length,
    settlementCount: episodes.filter((episode) => episode.status === 'OPEN' && episode.administrative_status === 'PENDING_SETTLEMENT').length,
});
