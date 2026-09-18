# Pharmacie — du fournisseur au comptoir

Cette page décrit, dans l'ordre, ce qu'il faut faire pour qu'un produit
arrive chez un fournisseur et finisse vendu au comptoir. Chaque étape dit
**qui** la fait, **où**, et **ce qu'elle produit**.

Un principe traverse tout le parcours : **le prix d'achat et le prix de
vente ne sont jamais le même chiffre**, et le second n'est jamais deviné à
partir du premier.

---

## Vue d'ensemble

```text
1  Dossier fournisseur          portail        qui nous vend
2  Catalogue du fournisseur     portail        ce qu'il propose
3  Commande d'achat             portail ou site ce qu'on décide d'acheter
4  Réception                    site           ce qui est réellement arrivé
5  Prix de vente                site (Pharmacie) à combien on le vend
6  Vente                         Réception      le patient repart avec
```

Les étapes 1 à 3 peuvent se faire depuis `admin.rivo.mg`. **La réception
reste au site** : c'est la personne qui a les cartons devant elle qui lit
les numéros de lot et les dates de péremption.

---

## 1 · Créer le dossier fournisseur

**Où** : portail › Fournisseurs pharmacie › *Nouveau fournisseur*.

Un code, un nom, et les coordonnées. **Le code ne change plus ensuite** :
c'est lui qui identifie le fournisseur dans les imports.

Le dossier contient quatre sous-dossiers : Catalogues, Commandes, Factures,
Produits et prix.

---

## 2 · Importer son catalogue

**Où** : dossier du fournisseur › Catalogues › *Ajouter un catalogue*.

Le fichier Excel porte cinq colonnes : `Référence`, `Médicament`,
`Présentation`, `Famille`, `Prix fournisseur`. Le modèle se télécharge depuis
le même écran — un fichier exporté puis rempli se réimporte tel quel.

**La famille et le prix sont facultatifs.** Beaucoup de catalogues arrivent
sans tarif ni classification ; le fichier s'importe quand même. La famille
déclarée sert à lire le catalogue par groupe, et sera proposée au produit
lorsqu'il entrera au catalogue de la clinique.

Avant d'écrire quoi que ce soit, un **aperçu** montre chaque ligne et, le cas
échéant, ce qui cloche : le numéro de ligne, la colonne, la valeur lue et la
raison. L'import ne part que si tout est correct, et il part en une seule
fois — une ligne refusée annule le fichier entier.

Un fournisseur n'a **qu'un catalogue actif** à la fois. Un nouveau tarif est
un nouveau catalogue, pas un remplacement : l'ancien reste consultable.

---

## 3 · Passer une commande d'achat

**Où** : dossier du fournisseur › Commandes › *Passer une commande*.
Au site : Pharmacie › Achats › Commandes.

« Choisir un médicament » ouvre une fenêtre de recherche qui réunit deux
listes, annoncées comme telles :

```text
Déjà au catalogue de la clinique   ce qu'on lui a déjà acheté
Au catalogue du fournisseur        les lignes de son catalogue actif
```

**Vous n'avez pas à créer le produit avant de le commander.** Choisir une
ligne de catalogue suffit : le produit entre au catalogue de la clinique au
moment où la commande est enregistrée, avec le prix d'achat du fournisseur —
**et sans prix de vente**, que personne n'a encore décidé.

Le même produit proposé par deux fournisseurs n'est jamais créé deux fois :
le second prix d'achat vient se placer à côté du premier.

Pour comparer avant de choisir : portail › Fournisseurs pharmacie ›
*Comparer les prix*. Le panier peut couvrir plusieurs fournisseurs ; il part
alors en **une commande par fournisseur**, jamais en une commande mixte.

La commande naît en **brouillon** : elle n'engage rien et ne touche pas le
stock. Vérifiez-la, puis *Envoyer au fournisseur*.

```text
Brouillon   se modifie librement
Passée      ne se modifie plus ; elle s'annule avec un motif
Reçue       ne s'annule plus : la marchandise est entrée
```

Une commande n'est jamais supprimée. Annulée, elle reste visible avec son
motif.

---

## 4 · Réceptionner la marchandise

**Où, uniquement au site** : Pharmacie › Achats › À réceptionner ›
*Réceptionner*.

Pour chaque produit livré : la quantité reçue, le **numéro de lot** et la
**date de péremption** lus sur la boîte. Le **prix d'achat unitaire** n'est
pas redemandé : c'est celui de la commande. Seul un compte autorisé à voir
les coûts peut le corriger si la facture diffère.

Une commande de 100 peut être reçue en plusieurs fois (80 puis 20). Chaque
réception crée les lots et les mouvements de stock correspondants, et ces
mouvements sont définitifs : une erreur se corrige par un **ajustement**
tracé, jamais en réécrivant l'historique.

Le stock existe désormais. Le produit n'est pour autant **pas encore
vendable**.

---

## 5 · Fixer le prix de vente

**Où** : Pharmacie › Médicaments & stock, bouton **« Prix »** de la ligne
(ou le portail › Stock médicaments).

C'est l'étape que l'on oublie. Tant
qu'un produit n'a pas de prix de vente :

```text
il ne peut pas être vendu à la Réception
il n'apparaît pas à la délivrance d'ordonnance
```

La page *Médicaments & stock* le dit : un bandeau compte les produits
concernés et un filtre **« Sans prix de vente »** les isole. Le prix peut
aussi se fixer à l'entrée de stock, sur chaque ligne de la livraison.

Profitez-en pour renseigner la **famille**, la DCI, la forme et le dosage :
un produit créé depuis un catalogue fournisseur n'a que le libellé du
fournisseur, parce que déduire une DCI d'un nom commercial serait inventer
une information clinique.

> **Droits (ADR-112).** Le compte Pharmacie fixe et modifie le prix de vente
> de ses médicaments (`medicines.sale_price.update`) — le premier sans motif,
> un changement avec motif. Il ne touche ni les tarifs des consultations et
> actes, ni la grille Mutuelle. Le **prix d'achat**, lui, est confidentiel :
> la Pharmacie ne le voit pas, seuls le Super Admin et les comptes à qui il
> accorde `stock.cost.view` le voient.

---

## 6 · Vendre

**Où** : à la Réception, sur le dossier patient et le passage (ADR-104). La
vente comptoir anonyme de la Pharmacie a été retirée.

N'y apparaissent que les produits **actifs, en stock et dotés d'un prix de
vente**. La Pharmacie délivre ensuite, une fois le ticket réglé ou pris en
charge.

**La Pharmacie n'encaisse jamais.** Le patient paie à Réception / Caisse.

---

## Supprimer, archiver : ce n'est pas la même chose

```text
Mettre à la corbeille   réversible. L'élément quitte les listes, part à la
                        Corbeille avec son motif, et se restaure.
Supprimer définitivement irréversible. Proposé uniquement depuis la
                        Corbeille, et refusé dès que l'élément a servi.
```

Un fournisseur qui a une commande, une facture, un lot reçu ou un mouvement
de stock **ne peut plus être supprimé définitivement** : ce serait effacer de
l'histoire. Ses fichiers de catalogue, eux, partent avec lui — ils
n'appartiennent qu'à ce dossier.

Ce qui n'est jamais ni archivé ni supprimé : les réceptions, les lots et les
mouvements de stock. Une correction passe par un ajustement tracé.

---

## Questions fréquentes

**« Aucun médicament à commander »**
Le fournisseur n'a aucun produit au catalogue de la clinique *et* son
catalogue n'est pas importé. Importez son catalogue (étape 2).

**Un médicament en stock n'est pas proposé à la vente**
Les produits en stock n'ont pas de prix de vente (étape 5), ou ils sont
épuisés.

**Famille et Prix de vente affichent « — »**
Le prix de vente n'est jamais déduit du prix d'achat : il se fixe à l'étape 5.
La famille, elle, est reprise du catalogue lorsque le fournisseur l'a
déclarée dans sa colonne « Famille » ; sinon elle se choisit à la fiche du
médicament.

**Un produit apparaît en double**
Cela ne devrait plus arriver depuis qu'un même libellé est réutilisé d'un
fournisseur à l'autre. Si deux fiches subsistent d'avant, désactivez celle
qui n'a pas de stock plutôt que de la supprimer.
