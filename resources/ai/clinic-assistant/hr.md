# Ressources humaines

Menu **Ressources humaines** (adresse `/administration`), avec ses rubriques : Accueil RH, Employés, Contrats, Stages, Documents, Accès du personnel, Emails professionnels, Présences, Congés, Planning, Rapports, Crédit Bloc, Bonus, Paie du mois, Départements, Fonctions, Banques, Paramètres. Chaque rubrique suit son droit (`employees.*`, `contracts.*`, `leave.*`, `attendance.*`, `planning.*`, `hr_settings.*`…). La paie du mois calcule brut, retenues et net ; le virement se fait hors RIVO.

## Ajouter un employé

Employés › **« Nouvel employé »** : l'étape Identité (nom, prénoms, naissance, photo facultative ; matricule proposé), puis « Continuer » crée le dossier. Les étapes suivantes (Contact, Poste, Compléments, Rémunération, Avantages, Banque, Récapitulatif) **s'enregistrent toutes seules**, sans bouton Enregistrer. La fonction proposée dépend du département choisi. L'email de l'employé est son adresse professionnelle, créée ensuite.

## Stagiaires

« Nouveau stagiaire » : dossier puis contrat de stage (filière, école, encadrant). Un stagiaire n'apparaît pas parmi les employés mais dans **Stages**.

## Contrats et documents

- Contrats › « Nouveau contrat ». « Imprimer » un contrat ouvre son document s'il existe, sinon la génération.
- Documents › **« Générer un document »** : choisir un canevas (contrat, congé, attestation…), la personne, compléter la page 1, puis générer. Un document ne se réécrit pas : « Modifier » crée une nouvelle version et archive l'ancienne.

## Congés, présences et planning

- Congés › « Demande de congé », puis « Accepter » ou « Refuser ». Le solde se calcule selon le type de congé.
- Présences : onglet « Aujourd'hui » (présents, attendus, en congé), entrée et sortie en un clic.
- Planning : planning du personnel et planning de garde, en semaine, mois ou liste.

## Accès du personnel et emails professionnels

Le compte RIVO et l'adresse email d'un nouvel employé sont créés par le Super Administrateur (« Accès du personnel »). Le RH reçoit une annonce, remet le message ou la fiche imprimée (QR code) à l'employé, qui choisit son mot de passe à sa première connexion. Un délai dépassé se rouvre d'un clic.

## Badges

Employés ou Stages › « Badges » : imprimer les badges de la liste filtrée ou des dossiers cochés (« Imprimer leurs badges »). Le modèle se règle dans les Paramètres du portail.

## Bonus

Rubrique Bonus : onglet « Avantages des médecins » — « Saisir des avantages » : les médecins à gauche, leurs avantages (article et montant, ex. ECHO 50 000 Ar) à droite, plusieurs à la fois, un seul « Enregistrer ». Onglet « Bonus du mois » : catégories (mesure, seuil mensuel, montant), validation par le RH, puis « versé ».

## Paie du mois

Rubrique **Paie du mois** (`/administration/paie`, droit `salary_payments.view`) : pour chaque salarié, brut (salaire + avantages) − retenues légales (CNAPS, organisme médical, IRSA) − retenues de dettes = **net à verser**, avec le mode de paiement repris de la fiche (virement, Mobile Money, espèces). « Marquer payé » (un salarié, ou cocher plusieurs puis « Marquer payées ») fige la paie (`salary_payments.pay`) ; le virement se fait hors RIVO. « Bulletin » imprime le bulletin de paie ; « Journal de paie » et « Liste de virement » exportent en Excel (`salary_payments.export`).

Les taux (CNAPS, organisme médical, tranches IRSA, minimum, réduction par enfant, plafonds) se règlent dans **Paie du mois › Paramètres** (`salary_settings.update`). Les retenues légales ne s'appliquent qu'une fois activées ; la « Simulation d'un bulletin » montre le calcul avant d'enregistrer. Une paie déjà payée garde ses taux.
