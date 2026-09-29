# Transferts — vers un autre établissement

Menu **Transferts** (adresse `/transferts`). Droits : `transfers.view` pour voir, `transfers.manage` pour compléter la demande et constater le départ. Médecine, Soins et Réception y ont accès par défaut.

## Demander un transfert

- **En consultation** : à Décision & clôture, conduite à tenir « Référence / transfert », choisir l'établissement (un autre site de la clinique, un autre établissement, ou « à préciser »), puis clôturer. Droit `transfer.request`.
- **Patient hospitalisé** : page du séjour, onglet Sortie, « Demander un transfert ».

Le motif, le diagnostic, le résumé et les traitements sont repris du dossier ; rien n'est ressaisi.

## Suivre et constater le départ

Deux onglets : **À transférer** et **Transférés**.

1. Ouvrir la demande et compléter l'établissement destinataire si besoin (texte riche possible). La lettre de référence s'imprime.
2. Au départ du patient : **« Transfert effectué »** (date du départ, jamais dans le futur). Le patient passe au statut « Transféré ».

Si le patient était hospitalisé, son séjour se termine au départ et le lit se libère. Le passage rejoint ensuite Sorties & règlements.

## Annuler un transfert

Tant que le patient n'est pas parti, la demande s'annule (depuis le séjour pour un patient hospitalisé). Après le départ, elle ne se modifie plus.
