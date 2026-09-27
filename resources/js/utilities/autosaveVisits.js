/**
 * Compte les visites Inertia lancées par un enregistrement automatique.
 *
 * Le conteneur de toasts écoute tous les succès et toutes les erreurs du
 * routeur : sans ce compteur, chaque pause de frappe afficherait « Fiche
 * enregistrée » ou « Veuillez corriger… ». Un enregistrement automatique se
 * signale par son statut discret, près du formulaire, jamais par un toast.
 */
let inFlight = 0;

export const beginAutosaveVisit = () => { inFlight += 1; };
export const endAutosaveVisit = () => { inFlight = Math.max(0, inFlight - 1); };
export const isAutosaveVisit = () => inFlight > 0;
