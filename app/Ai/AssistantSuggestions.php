<?php

namespace App\Ai;

/**
 * ADR-222 — les questions proposées par l'assistant, module par module.
 *
 * C'est le seul fichier à modifier pour changer ce qui est proposé :
 *
 *   - à l'ouverture de l'assistant, regroupées par module (le module de la page
 *     d'où l'on vient d'abord, puis ceux où le compte a le plus de droits) ;
 *   - sous chaque réponse, en questions de suivi.
 *
 * Règles pour écrire une question :
 *
 *   - la réponse doit se trouver dans la fiche du module
 *     (`resources/ai/clinic-assistant/{module}.md`) — sinon l'assistant dira qu'il
 *     ne sait pas, et la proposition aura fait perdre du temps ;
 *   - une question sur l'usage du logiciel, jamais sur des données (« combien de
 *     patients… ») : l'assistant ne lit aucune donnée ;
 *   - courte, à la première personne ou à l'infinitif, telle qu'on la taperait.
 *
 * Un module absent d'ici ne propose rien ; une question n'est proposée qu'aux
 * comptes qui peuvent ouvrir son module (AssistantKnowledge::accessible).
 */
final class AssistantSuggestions
{
    /** @var array<string, list<string>> clé du module (AssistantKnowledge) => questions, les plus utiles d'abord */
    public const BY_MODULE = [
        // Navigation et compte
        'general' => [
            'Où trouver une fonctionnalité ?',
            'Pourquoi un bouton est grisé ou verrouillé ?',
            'Comment changer mon mot de passe ?',
        ],
        // Réception
        'reception' => [
            'Comment accueillir un nouveau patient ?',
            'Comment classer un passage en urgence ?',
            'Comment vendre seulement des médicaments ?',
            'Qui voit le patient après l’accueil ?',
        ],
        // Sorties & règlements
        'settlement' => [
            'Comment prononcer la sortie administrative ?',
            'Pourquoi un passage n’est pas « À régler » ?',
            'Que faire des prestations non facturées ?',
        ],
        // Caisse
        'cash' => [
            'Comment enregistrer un paiement ?',
            'Comment retrouver une facture ?',
            'Comment encaisser un ticket Pharmacie ?',
            'Comment clôturer la caisse ?',
        ],
        // Patients
        'patients' => [
            'Comment retrouver un patient ?',
            'Comment imprimer le dossier médical ?',
            'Où voir le parcours d’un passage ?',
        ],
        // Soins
        'care' => [
            'Comment prendre un patient en charge ?',
            'Comment transmettre un patient au médecin ?',
            'Comment déclarer le matériel utilisé ?',
            'Comment remettre un patient en file ?',
        ],
        // Médecine — consultation
        'medicine' => [
            'Comment demander une analyse ?',
            'Comment hospitaliser un patient ?',
            'Comment enregistrer un diagnostic ?',
            'Comment terminer la consultation ?',
        ],
        // Laboratoire — la paillasse du technicien
        'laboratory' => [
            'Comment traiter une demande d’analyses ?',
            'Comment envoyer les résultats au médecin ?',
            'Comment renvoyer une analyse à refaire ?',
            'Comment imprimer les étiquettes des tubes ?',
        ],
        // Demandes d'examens, résultats d'analyses et imagerie
        'paraclinical' => [
            'Comment valider un résultat d’analyse reçu ?',
            'Où voir les résultats d’analyse ?',
            'Comment saisir un compte rendu d’échographie ?',
            'Comment remettre les résultats au patient ?',
        ],
        // Hospitalisation
        'hospitalization' => [
            'Comment attribuer un lit ?',
            'Comment prononcer la sortie d’hospitalisation ?',
            'Comment transférer le patient au bloc ?',
            'Comment remplir la fiche de régime ?',
        ],
        // Chirurgie — bloc opératoire
        'surgery' => [
            'Comment programmer une intervention ?',
            'Pourquoi je ne peux pas démarrer l’intervention ?',
            'Comment clôturer une intervention chirurgicale ?',
        ],
        // Anesthésie
        'anesthesia' => [
            'Comment valider l’évaluation pré-anesthésique ?',
            'Comment autoriser le bloc ?',
            'Quand valider le dossier d’anesthésie ?',
        ],
        // Maternité
        'maternity' => [
            'Comment commencer une consultation prénatale ?',
            'Comment enregistrer des actes ?',
            'Comment créer le dossier d’un nouveau-né ?',
        ],
        // Pharmacie
        'pharmacy' => [
            'Comment enregistrer une sortie de pharmacie ?',
            'Comment consulter le stock ?',
            'Où voir les produits presque en rupture ?',
            'Comment réceptionner une livraison ?',
        ],
        // Transferts
        'transfers' => [
            'Comment transférer un patient ?',
            'Comment confirmer le départ du patient ?',
        ],
        // Pédiatrie
        'pediatrics' => [
            'Comment orienter un enfant vers la Pédiatrie ?',
            'Comment faire sortir un enfant ?',
        ],
        // Registre des décès
        'deaths' => [
            'Comment établir un acte de constatation de décès ?',
            'Comment déclarer un décès ?',
        ],
        // Gardiennage et visiteurs
        'security' => [
            'Comment contrôler la sortie d’un patient ?',
            'Comment enregistrer un visiteur ?',
        ],
        // Ressources humaines
        'hr' => [
            'Comment ajouter un employé ?',
            'Comment accepter une demande de congé ?',
            'Comment générer une attestation ?',
            'Comment imprimer les badges ?',
        ],
        // Référentiels, tarifs et partenaires
        'referentials' => [
            'Comment modifier un tarif ?',
            'Comment ajouter un partenaire ?',
            'Où régler les valeurs de référence des analyses ?',
        ],
        // Utilisateurs et accès
        'access' => [
            'Comment créer un compte utilisateur ?',
            'Pourquoi un compte n’a pas accès à un module ?',
        ],
        // Portail Super Administration
        'superadmin' => [
            'Comment régler l’assistant IA ?',
            'Comment modifier les droits d’un rôle ?',
            'Comment créer l’accès d’un nouvel employé ?',
            'Comment mettre un site en maintenance ?',
        ],
    ];

    /** @return list<string> */
    public static function for(string $module): array
    {
        return self::BY_MODULE[$module] ?? [];
    }
}
