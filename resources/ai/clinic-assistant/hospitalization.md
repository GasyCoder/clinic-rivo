# Hospitalisation — séjour, lit et sortie

Menu **Hospitalisation** (`/hospitalisation`). Droits : `hospitalization.view` pour voir, `hospitalization.update` pour le lit et le service. Le module liste les patients **au lit** ; les séjours terminés se retrouvent par la recherche nommée et dans Sorties & règlements.

## Hospitaliser un patient

L'hospitalisation se décide **en consultation** : à Décision & clôture, conduite à tenir « Hospitalisation », puis clôturer. Le patient est **admis automatiquement** : le séjour s'ouvre, avec le motif et le diagnostic repris de la consultation. Il n'y a pas de formulaire d'admission séparé.

## Attribuer ou changer un lit

Sur la page du séjour : **« Attribuer un lit »** propose seulement les lits libres et en service ; **« Changer de lit »** déplace le patient (motif, historique conservé). Un lit occupé ne peut pas être donné à un autre patient. Si le site n'a encore configuré aucun lit, la chambre se note en texte libre. Le plan des lits est dans l'onglet « Plan des lits » de la liste.

## Travailler sur la page du séjour

La page du séjour est le poste de travail du patient hospitalisé. Ses onglets :

- **Vue d'ensemble** : séjour, demande d'hospitalisation, diagnostics, consultations restées ouvertes.
- **Notes du jour** : note S/O/A/P datée et signée (droit `hospital_notes.create`) ; une erreur se corrige par une nouvelle note.
- **Ordonnances** : prescrire pendant le séjour ; la délivrance au service n'attend pas le règlement.
- **Examens** : demander une analyse ou une imagerie.
- **Soins** : demander des actes aux Soins.
- **Surveillance** : relevés de constantes au lit (droit `vitals.create`).
- **Régime** : fiche de régime jour par jour (droit `hospital_diet.record`), imprimable.
- **Bloc** : transférer au bloc et suivre les passages au bloc.
- **Sortie** : prononcer la sortie ou demander un transfert.

## Prononcer la sortie d'hospitalisation

Onglet **Sortie** de la page du séjour (droit `medical_discharge.create`) : type de sortie, diagnostic final (les diagnostics consignés sont cochés ; « Ajouter un autre diagnostic » est possible sur place), consignes. La sortie d'un patient hospitalisé se prononce **uniquement** ici, pas depuis une consultation. Elle termine le séjour et libère le lit ; le passage rejoint ensuite Sorties & règlements.

## Transférer vers le bloc ou vers un autre établissement

- **Bloc** : onglet Bloc › « Transférer au bloc », choisir l'intervention. Le patient garde son lit. La demande s'annule tant que le bloc ne l'a pas programmée.
- **Autre établissement** : onglet Sortie, « Demander un transfert » (autres sites de la clinique, ou « Autre établissement… » en saisie libre). Le séjour se termine au départ constaté dans le module Transferts.

## Actions groupées sur la liste

Cocher des patients permet d'imprimer le tour de salle, les fiches de régime et les dossiers médicaux, ou d'exporter en Excel (droit `hospitalization.export`). Aucune action clinique ne se fait en lot.

## Pourquoi je ne peux pas faire la sortie ou annuler le séjour

- Droit `medical_discharge.create` manquant.
- Une sortie médicale a déjà été prononcée pour ce passage.
- Un transfert vers un autre établissement n'est pas un type de sortie : il se demande avec « Demander un transfert », et le séjour se termine au départ du patient, constaté dans le module Transferts.
- Un séjour qui a réellement eu lieu (note, ordonnance, relevé…) ne s'annule plus : il se termine par une sortie.

## Après la sortie : pourquoi le passage n'est pas encore « À régler »

Une consultation du passage restée ouverte (celle qui a demandé l'hospitalisation, par exemple) retient le passage : la page du séjour la liste avec ce qui manque, et la clôture d'un clic quand plus rien ne manque. Le passage rejoint ensuite Sorties & règlements.
