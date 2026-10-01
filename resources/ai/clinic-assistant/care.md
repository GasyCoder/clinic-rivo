# Soins — tableau des passages et fiche de soins

Menu **Soins** › Infirmière (adresse `/care`). Droits : `care.create` pour ouvrir la file et faire des soins, `care.update` pour corriger une fiche, `vitals.*` pour les constantes. Les onglets Maternité et Anesthésie apparaissent selon vos droits (verrouillés sinon).

## Le tableau des passages

Trois blocs, chacun avec son compteur :

- **En attente** : patients arrivés, pas encore pris en charge aux Soins, numérotés par ordre d'arrivée (le n° 1 est « Prochain »). Filtres « Suggérés pour moi » et « Urgences ».
- **En cours chez moi** : patients que votre service a pris en charge.
- **Terminés chez moi** : patients déjà vus, avec journal et dossier à relire.

« Prendre en charge » sur la ligne du patient. Prendre un patient qui n'est pas le premier demande confirmation. Un patient déjà pris par la Médecine affiche « En cours » : il ne se prend pas une seconde fois.

## Remplir la fiche de soins

La fiche a cinq étapes : **Contexte**, **Constantes**, **Allergies**, **Actes et matériel**, **Terminer**.

- Les constantes (tension, fréquence cardiaque, SpO₂, température, poids, taille) sont facultatives pour un acte isolé ; des alertes s'affichent sous le champ quand une valeur sort des repères (elles ne bloquent rien).
- Dans « Actes et matériel », l'acte et son matériel se déclarent ensemble ; le matériel habituel d'un acte est proposé, à confirmer ou corriger. Le matériel part à la Pharmacie, qui sort le stock.
- La saisie en cours est gardée côté serveur : une actualisation ne la perd pas.

## Terminer les soins : la suite après les soins

À l'étape Terminer, **« Suite après les soins »** propose la suite prévue à l'arrivée :

- **Transmettre au médecin** : le patient rejoint la file Médecine avec votre transmission écrite.
- **Terminer aux Soins** : le patient n'a pas besoin du médecin.

Changer la suite prévue demande un **motif**. Un parcours « soins seuls » exige au moins un acte enregistré.

## Remettre en file ou reprendre un patient

- **« Remettre en file »** : pour un patient pris par erreur, tant que rien n'a été enregistré. Il retrouve sa place.
- **« Reprendre la prise en charge »** : pour terminer le patient d'un collègue (fin de garde…), avec un motif (droit `care.complete`).
- Seul le soignant qui a pris le patient peut le terminer ou le transmettre.

## Pourquoi je ne peux pas terminer ou transmettre

- Le patient est pris en charge par un collègue : le reprendre d'abord.
- Parcours « soins seuls » sans aucun acte enregistré.
- Suite changée sans motif.
- Le médecin a déjà le patient (urgence, consultation en cours) : il n'y a rien à choisir, les soins se terminent simplement.
