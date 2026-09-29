# Référentiels, tarifs, analyses et partenaires

## Désignations et tarifs

Menu Référentiels › **Désignations & tarifs** (adresse `/administration/catalog`, droit `catalog.items.view`). Les prestations, médicaments et produits ont un code et un libellé. Les **tarifs** (Standard « sans mutuelle » et Mutuelle) sont propres à chaque site et historisés : un changement de prix ne modifie jamais une facture passée. Créer ou modifier un tarif est réservé par défaut au Super Administrateur (`catalog.tariffs.*`). La Réception ne saisit jamais un prix : il est lu dans ce référentiel.

Une prestation sans tarif configuré peut être demandée, mais sa facturation reste en attente jusqu'à ce qu'un tarif existe.

## Catalogue des analyses

Menu Référentiels › **Catalogue des analyses** (`/administration/analyses`, droit `analysis_catalog.view`) : structure des analyses, unités, valeurs de référence par âge et sexe, import et export Excel.

## Partenaires

Menu **Partenaires** (`/partenaires`, droit `partner_organizations.view`) : partenaires médicaux (médecins extérieurs…) et autres partenaires (écoles, organismes). « Nouveau partenaire » ouvre sa fiche. Un partenaire se choisit à l'étape Prise en charge de l'accueil ; il ne couvre aucun montant pour l'instant (tarif Standard).

## Mutuelles

Les organismes de mutuelle et leur taux de couverture se règlent depuis le portail (Tarifs & mutuelles). À l'accueil, la Réception choisit l'organisme à l'étape Prise en charge ; la part patient est calculée automatiquement.
