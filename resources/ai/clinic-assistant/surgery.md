# Chirurgie — le dossier du bloc opératoire

Menu Soins/Chirurgie › **Chirurgie** (adresse `/surgery`). Droit : `surgery.view` pour voir ; chaque geste a son droit (`surgery.schedule`, `surgery.preparation.update`, `surgery.intervention.create`, `surgery.report.validate`…). La Chirurgie n'encaisse rien.

## D'où vient une demande du bloc

Le bloc ne crée pas de dossier lui-même. Une demande arrive :

- de la **Réception**, quand l'acte opératoire est la raison de la venue ;
- de la **Médecine**, conduite à tenir « Chirurgie » à Décision & clôture (l'intervention doit être choisie) ;
- d'un **séjour d'hospitalisation**, onglet Bloc › « Transférer au bloc » (le patient garde son lit) ;
- de la **Maternité**, pour une césarienne.

La file du bloc a quatre vues : À programmer, Programmées, Au bloc, Terminées.

## Les étapes du dossier

Le dossier suit cinq étapes ; la barre « Prochaine étape » en bas de page dit ce qui reste à faire et ouvre le bon formulaire :

1. **Dossier** — « Programmer l'intervention » (date et heure, chirurgien principal, aides) puis l'équipe de bloc.
2. **Préparation** — « Confirmer le feu vert », puis « Entrée au bloc ».
3. **Intervention** — « Démarrer l'intervention », heure de fin, consommables (demandés à la Pharmacie).
4. **Sortie du bloc** — état de réveil et sortie du bloc.
5. **Suivi & clôture** — compte rendu, suivi, complications, « Sortie de Chirurgie », puis « Clôturer le dossier ».

Les formulaires du bloc s'enregistrent tout seuls ; « Suivant » passe à la section suivante.

## Programmer une intervention

« Programmer l'intervention » : choisir d'abord la date et l'heure, puis le chirurgien principal (« Moi-même » pour un compte au profil Chirurgien) et les aides. Les chirurgiens absents du planning RH à cette heure sont verrouillés avec la raison. Sans aucun compte au profil Chirurgien, aucune intervention ne peut être programmée. Chaque case de la programmation se corrige ensuite avec son crayon (au bloc : motif obligatoire).

## Démarrer l'intervention : ce qui doit être prêt

L'incision est refusée tant que :

- le feu vert préopératoire n'est pas confirmé ;
- aucun anesthésiste n'est affecté, l'évaluation pré-anesthésique n'est pas validée, ou la **décision d'anesthésie** n'est pas « Autorisé » (ou « Autorisé sous conditions » avec toutes les conditions levées) ;
- les temps **SIGN IN** et **TIME OUT** de la checklist de sécurité ne sont pas confirmés par chaque métier.

L'écran liste ce qui bloque et à quel métier c'est (« en attente de l'anesthésiste »). Une fiche d'entrée au bloc incomplète avertit sans bloquer.

## Clôturer l'intervention chirurgicale

« Valider le compte rendu » ne clôt pas le dossier. **« Clôturer le dossier »** (droit `surgery.report.validate`) exige :

- l'intervention terminée (heure de fin saisie) ;
- le compte rendu validé ;
- le temps **SIGN OUT** confirmé ;
- la sortie du bloc renseignée ;
- le dossier d'anesthésie finalisé (« Valider le dossier » côté Anesthésie).

## Consommables du bloc

Le matériel utilisé se déclare depuis l'étape Intervention (droit `surgery.consumables.create`) : produits de la Pharmacie, suggérés selon l'intervention. La demande part à la Pharmacie, qui sort le stock ; le patient paie à la Caisse. Un produit absent du stock se note en ligne « hors stock ».

## Imprimer ou réinitialiser le dossier

- **« Imprimer le dossier »** : le dossier chirurgical (entrée au bloc, sortie du bloc, consultation pré-anesthésique, examen paraclinique), entier ou une seule feuille.
- **« Réinitialiser »** (droit `surgery.reset`) : pour un dossier saisi à tort. Tout est archivé avant d'être retiré, un motif est obligatoire, et c'est refusé si la Pharmacie a déjà servi du matériel.

## Pourquoi je ne peux pas…

- Programmer : droit `surgery.schedule` manquant, ou aucun chirurgien disponible à cette heure.
- Démarrer : voir « ce qui doit être prêt » ; la liste des blocages est affichée sur le dossier.
- Clôturer : une des conditions de clôture manque (souvent l'heure de fin, le SIGN OUT ou la finalisation de l'anesthésie).
- Corriger une programmation au bloc : un motif est exigé.
