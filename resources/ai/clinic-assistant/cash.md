# Caisse — encaisser, reçus, tickets Pharmacie

Menu **Caisse** (adresse `/cash`). La Caisse de la Réception est le **seul** endroit où l'on encaisse : ni la Pharmacie, ni le Laboratoire, ni la Médecine, ni la Chirurgie n'encaissent. Droits utiles : `cash.view`, `payments.create`, `billing.view`.

## Ouvrir la caisse (session)

1. Choisir le **poste de caisse** si plusieurs sont configurés (Caisse 1, Caisse 2…).
2. « Ouvrir une session » avec le fond de caisse.
3. Une session appartient à la personne qui l'a ouverte : un autre compte voit « Cette caisse est utilisée par une autre personne » et doit choisir une autre caisse. Seule une clôture libère la caisse.

## Encaisser une facture

Onglet « À encaisser » : retrouver la facture (numéro, patient ou passage), puis **« Encaisser »**. Choisir le mode de paiement et le montant ; un paiement partiel est possible. Un **reçu** est produit pour chaque paiement réel ; une facture non payée n'a jamais de reçu.

## Encaisser un ticket Pharmacie

Onglet **« Tickets Pharmacie »** : saisir la référence imprimée sur le ticket (numéro de facture) ou scanner son QR code, vérifier le montant puis encaisser. Après paiement, la Pharmacie peut délivrer.

## Appliquer une remise

Dans la fenêtre d'encaissement, le bloc « Remise » propose la remise la plus avantageuse à laquelle le patient a droit (VIP, personnel, remise accordée au patient) ou un **coupon**. Une remise se pose **avant tout paiement** sur la facture (droit `discounts.create`).

## Clôturer la caisse

« Clôturer la caisse » demande les espèces réellement comptées ; l'écart avec le montant attendu est enregistré. Après clôture, une nouvelle session peut être ouverte.

## Pourquoi je ne peux pas encaisser

- Aucune session ouverte sur votre poste, ou session ouverte par une autre personne.
- Caisse verrouillée à distance par le Super Administrateur.
- Droit `payments.create` manquant.
- Facture annulée, déjà réglée ou prise en charge à 100 % (statut « Prise en charge ») : il n'y a rien à encaisser.
