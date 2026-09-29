# Sorties & règlements — sortie administrative

Menu Réception › **Sorties & règlements** (adresse `/reception/sorties`). Droits : `episodes.settlement.view` pour voir, `episodes.administrative_exit` pour prononcer une sortie. La sortie administrative est distincte de la sortie médicale : le médecin prononce la sortie médicale, la Réception clôt ensuite le passage selon son compte.

## Quand un passage arrive ici

Un passage arrive dans l'onglet « À régler » quand plus aucun service n'a le patient : consultation clôturée, soins terminés, séjour terminé, transfert effectué. Un passage encore pris en charge par un service (consultation ouverte, séjour en cours) n'y est pas ; il apparaît dans la vue « Sortie médicale prononcée · service pas encore clôturé » si la sortie médicale est prononcée mais la consultation pas encore clôturée.

## Prononcer la sortie administrative

Sur la ligne du passage, **« Prononcer la sortie »**. Le type de sortie dépend du solde, recalculé par le serveur :

- **Payé comptant** : le compte est soldé (reste à payer nul, rien à facturer).
- **Dette validée** : il reste un montant dû ; une personne responsable du paiement est identifiée. Demande le droit `debts.authorize`.
- **Évadé** : le patient est parti sans régler ; le constat crée une créance. Demande le droit `debts.record_escape`.

Un motif est obligatoire. La sortie clôt le passage : plus rien ne s'y modifie ensuite. Elle n'encaisse rien : l'encaissement se fait à la Caisse.

## Pourquoi je ne peux pas prononcer la sortie

- **Prestations non facturées** : aucune sortie n'est possible tant qu'une prestation attend d'être portée sur une facture. Utiliser « Facturer ces prestations » dans la fenêtre de sortie, puis encaisser à la Caisse.
- **Reste à payer** : « payé comptant » exige un compte soldé ; sinon encaisser à la Caisse, ou choisir dette validée / évadé si vous en avez le droit.
- **Droits** : dette validée et évasion sont verrouillées sans `debts.authorize` / `debts.record_escape` ; demandez-les à un administrateur.
- **Un service a encore le patient** : le passage n'est pas encore « À régler ».

## Actions groupées et fiche de sortie

- Cocher plusieurs passages permet : sortie « payé comptant » en lot (comptes soldés seulement), facturation en lot, impression des fiches de sortie, export Excel. Un passage refusé n'empêche pas les autres ; un rapport donne la raison.
- Après la sortie, la **fiche de sortie** s'imprime (onglet « Sorties prononcées ») ; son QR code est contrôlé au poste de gardiennage.
