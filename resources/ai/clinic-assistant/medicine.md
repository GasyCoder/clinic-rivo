# Médecine — file et consultation

Menu **Médecine** › File de consultation (adresse `/medicine`). Droits : `consultations.view` pour voir, `consultations.create` pour prendre un patient, `consultations.update` pour écrire dans une consultation.

## La file Médecine

Même tableau que les Soins : **En attente** (numérotés par arrivée, « Prochain »), **En cours chez moi**, **Terminés chez moi**, filtres « Suggérés pour moi » et « Urgences ».

- « Prendre en charge » ouvre la consultation. Si le patient était attendu aux Soins, une fenêtre propose « Faire les soins moi-même » ou « Consulter quand même ».
- **« Aux Soins »** envoie le patient aux Soins avec une consigne, avant, pendant ou après la consultation ; « Annuler l'envoi » tant que les Soins ne l'ont pas pris.
- **« Remettre en file »** : patient pris par erreur, tant que la consultation est restée vierge.

## Les étapes de la consultation

1. **Dossier du passage** — lecture : identité, allergies, demandes de la Réception, constantes et actes des Soins.
2. **Interrogatoire** — motif principal, histoire, traitements déclarés.
3. **Examen clinique** — état général, conscience, appareils (Normal / Anormal / Non examiné), notes. Un diagnostic peut déjà s'y enregistrer.
4. **Examens paracliniques** — analyses et imagerie.
5. **Prescription** — ordonnance.
6. **Décision & clôture** — diagnostic et conduite à tenir, puis clôture.

« Enregistrer » garde la saisie ; les étapes restent atteignables dans l'ordre que vous voulez.

## Demander une analyse, un ECG ou une échographie

Étape **Examens paracliniques** : répondre « Oui » à « des examens complémentaires sont-ils nécessaires ? », choisir les examens dans le catalogue, puis transmettre (confirmation signée). La demande part au Laboratoire ou à l'Imagerie et est facturée au patient. Un examen déjà demandé à la Réception est repris sans être refacturé. Une demande sans résultat peut être retirée. Droits : `laboratory_orders.create`, `imaging_orders.create`.

## Enregistrer un diagnostic

À l'Examen clinique ou à Décision & clôture : chercher dans le catalogue des diagnostics ou saisir librement, puis enregistrer. Seul l'auteur peut corriger ou retirer son diagnostic (droit `diagnoses.update`) ; rien n'est effacé, la correction reste tracée. Des diagnostics peuvent être **proposés** d'après les protocoles de la clinique : « Retenir » les enregistre.

## Faire une ordonnance

Étape **Prescription** : choisir un médicament du référentiel Pharmacie (la disponibilité s'affiche), régler dose, voie, fréquence et durée ; la quantité est suggérée. Un médicament absent du référentiel s'ajoute en ligne manuelle. « Valider et réserver » réserve le stock ; la délivrance se fait à la Pharmacie après règlement à la Caisse. Des points de relecture (voie, quantité, allergie) s'affichent sans bloquer. Droit : `prescriptions.create`. L'assistant n'aide jamais à choisir un traitement ou une dose : cela relève du prescripteur.

## Demander un soin aux Soins

Depuis la consultation, carte « Prescription de soins » : choisir un ou plusieurs actes puis « Transmettre aux Soins » (ordre de soins, droit `care_orders.create`), en précisant si le patient doit revenir en Médecine. Un acte non réalisé peut être retiré tant que la demande est en cours.

## Clôturer la consultation (Décision & clôture)

La **seule condition** pour clôturer est de choisir une **conduite à tenir** :

- Sortie médicale (normale, à la demande du patient, refus, décès) ;
- Hospitalisation ;
- Chirurgie (l'intervention doit être choisie) ;
- Transfert vers un autre établissement ;
- Maternité, Pédiatrie ;
- Poursuite de l'hospitalisation (pour un patient déjà hospitalisé).

Le diagnostic est facultatif pour clôturer. Le bouton dit ce qu'il fait (« Transmettre et clôturer », « Je prononce la sortie et je clôture »…) ; une seule confirmation signée. Après la clôture, la consultation est en lecture seule.

## Hospitaliser un patient

Choisir la conduite à tenir **Hospitalisation** à Décision & clôture, puis clôturer : la demande part au service et le patient est **admis automatiquement** (droit `hospitalization.request`). Le séjour se suit ensuite dans le module Hospitalisation, où l'on attribue un lit.

## Pourquoi je ne peux pas clôturer la consultation

- Aucune conduite à tenir choisie.
- Chirurgie choisie sans intervention.
- Une conduite déjà transmise ne se remplace pas en silence : « Changer de conduite » l'annule d'abord.
- Patient hospitalisé : la sortie se prononce depuis la page du séjour, pas depuis la consultation.
- Consultation déjà clôturée (lecture seule), ou droit `consultations.update` manquant.

## Rouvrir une consultation clôturée

Avec le droit `consultations.reopen`, tant que la Réception n'a pas clos le passage : « Rouvrir la consultation » avec un motif obligatoire. Rien n'est effacé ; la réouverture est tracée.

## Protocoles de la clinique

Menu Médecine › **Protocoles** (`clinical_protocols.view`) : diagnostics, signes évocateurs et ordonnances types rédigés par les médecins de la clinique. Ils servent aux propositions de diagnostic et d'ordonnance, toujours modifiables.
