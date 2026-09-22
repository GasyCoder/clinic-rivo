# Pharmacie — du fournisseur au comptoir

Cette page décrit, dans l'ordre, ce qu'il faut faire pour qu'un produit
arrive chez un fournisseur et finisse vendu au comptoir. Chaque étape dit
**qui** la fait, **où**, et **ce qu'elle produit**.

Deux principes traversent tout le parcours :

- **le prix d'achat et le prix de vente ne sont jamais le même chiffre**, et
  le second n'est jamais deviné à partir du premier ;
- **recevoir une marchandise et la ranger sont deux gestes différents** — on
  constate d'abord ce qui est arrivé, on le fait entrer au stock ensuite.

---

## Vue d'ensemble

```text
1  Dossier fournisseur      portail          qui nous vend
2  Catalogue du fournisseur portail          ce qu'il propose
3  Commande d'achat         portail ou site  ce qu'on décide d'acheter
4  Réception                site             ce qui est réellement arrivé
5  Entrée en stock          site             la marchandise devient disponible
6  Prix de vente            site (Pharmacie) à combien on la vend
7  Vente                    Réception        le patient repart avec
```

Les étapes 1 à 3 peuvent se faire depuis `admin.rivo.mg`. **Les étapes 4 et
5 restent au site** : c'est la personne qui a les cartons devant elle qui lit
les numéros de lot et les dates de péremption, et qui range.

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
un nouveau catalogue, jamais un fichier remplacé : l'ancien reste lisible.

---

## 3 · Passer une commande d'achat

**Où** : Pharmacie › Achats › *Nouvelle commande* (site), ou dossier du
fournisseur › Commandes (portail). Le comparateur du portail montre, pour un
même produit, le prix de chaque fournisseur et ce qu'il en reste en stock.

Le formulaire propose **ce que ce fournisseur vend** : les produits déjà au
catalogue de la clinique, et les lignes de son catalogue actif que la
clinique n'a pas encore reprises. Seule la quantité est à saisir — le prix
d'achat s'applique tout seul ; un prix négocié se saisit avec « Changer », et
il ne remplace pas le tarif du fournisseur.

Une commande de catalogue **fait entrer le produit au catalogue de la
clinique** : c'est l'ordre naturel, on achète ce qu'on va tenir. Ce produit
n'a alors ni lot, ni stock, ni prix de vente, et il apparaît dans l'onglet
**« Commandés, jamais reçus »** de *Médicaments & stock* — jamais parmi les
ruptures : on ne l'a jamais eu.

```text
Brouillon   se corrige, ou part à la corbeille
Envoyer     = VALIDER la commande. Il n'y a pas d'autre validation.
Commandée   s'annule (motif obligatoire), ne se modifie plus
Annulée     part à la corbeille, restaurable
```

Une commande **envoyée ne se jette pas** : elle a engagé la clinique auprès
d'un tiers. On l'annule d'abord, avec un motif ; elle peut ensuite rejoindre
la corbeille, d'où elle se restaure. Son annulation n'est jamais effacée.

---

## 4 · Réceptionner la marchandise

**Où, uniquement au site** : Pharmacie › Achats › À réceptionner ›
*Réceptionner*.

C'est un **constat** : ce qui est réellement arrivé. Pour chaque produit, la
quantité reçue, le **numéro de lot** et la **date de péremption** lus sur la
boîte, et une remarque au besoin. Décochez un produit qui n'est pas dans la
livraison : il restera attendu sur la commande.

Le **prix d'achat unitaire** n'est pas redemandé : c'est celui de la
commande. Seul un compte autorisé à voir les coûts peut le corriger si la
facture diffère. Un produit jamais reçu porte la pastille **« Nouveau
produit »**, et c'est là qu'on lui donne son nom à la pharmacie.

**Le lot et la péremption ne s'inventent pas** : ils sont imprimés sur la
boîte. Les fabriquer fausserait le FEFO et rendrait un rappel de lot
intraçable. Seule aide possible, et elle est automatique : si le numéro
saisi désigne un lot que la pharmacie tient déjà, **sa péremption se remplit
seule** et les lots connus du produit sont proposés pendant la frappe.

L'assistant a deux étapes : ce qui est arrivé, puis **la facture du
fournisseur**. La seconde est facultative — beaucoup de livraisons arrivent
sans leur papier. Sans elle, la réception est marquée « facture en attente »
et la facture se saisit plus tard, au même formulaire.

**Une réception ne crée aucun mouvement de stock.** La marchandise est
constatée, pas encore rangée.

---

## 5 · Entrer la marchandise au stock

**Où** : Pharmacie › Médicaments & stock › *Enregistrer une entrée*, ou le
bouton **« Entrer en stock »** de la réception.

C'est ce geste qui crée les **lots** et les **mouvements de stock**, et qui
rend la marchandise disponible. L'écran arrive **déjà rempli** de ce qui a
été réceptionné : relisez, corrigez si besoin, validez. La date d'entrée et
le rangement sont enregistrés automatiquement.

Tant que rien n'est rangé, la quantité, le lot et la péremption **restent
corrigibles** — la correction met à jour la réception et la commande. Une
fois l'entrée validée, le mouvement est définitif : une erreur se corrige
par un **ajustement** tracé, jamais en réécrivant l'historique. Une ligne
n'entre qu'une seule fois.

Le même écran porte un second onglet, **« Entrée sans commande »** : un don,
un stock de départ à la mise en service, un dépannage d'un confrère. On y
coche dans le catalogue de la pharmacie, et la provenance est obligatoire.

> **Deux écrans, deux choses.** `/pharmacy/receipts/{…}` est **la
> réception** : ce qui est arrivé, avec son état d'entrée en stock et sa
> facture. `/pharmacy/stock/entries/create` est **le rangement** : ce qui
> fait entrer la marchandise. Ce n'est pas une redondance — entre les deux,
> la livraison est constatée mais pas encore disponible.

---

## 6 · Fixer le prix de vente

**Où** : Pharmacie › Médicaments & stock, bouton **« Prix »** de la ligne
(ou le portail › Stock médicaments). Il se fixe aussi directement sur chaque
ligne de l'entrée en stock — recevoir et rendre vendable en un seul geste.

C'est l'étape que l'on oublie. Tant qu'un produit n'a pas de prix de vente :

```text
il ne peut pas être vendu à la Réception
il n'apparaît pas à la délivrance d'ordonnance
```

La page *Médicaments & stock* le dit : un bandeau compte les produits
concernés et un filtre **« Sans prix de vente »** les isole.

Profitez-en pour renseigner la **famille**, la DCI, la forme et le dosage :
un produit créé depuis un catalogue fournisseur n'a que le libellé du
fournisseur, parce que déduire une DCI d'un nom commercial serait inventer
une information clinique.

> **Droits (ADR-174).** Le compte Pharmacie fixe et modifie le prix de vente
> de ses médicaments (`medicines.sale_price.update`) — le premier sans motif,
> un changement avec motif. Il ne touche ni les tarifs des consultations et
> actes, ni la grille Mutuelle. Le **prix d'achat**, lui, est confidentiel :
> seuls le Super Admin et les comptes à qui il accorde `stock.cost.view` le
> voient.

---

## 7 · Vendre

**Où** : à la Réception, sur le dossier patient et le passage (ADR-104). La
vente comptoir anonyme de la Pharmacie a été retirée.

N'y apparaissent que les produits **actifs, en stock et dotés d'un prix de
vente**. La Pharmacie délivre ensuite, une fois le ticket réglé ou pris en
charge.

**La Pharmacie n'encaisse jamais.** Le patient paie à Réception / Caisse.

---

## Ce que lit la page « Médicaments & stock »

```text
Disponibles sans alerte  en stock, rien à signaler
Péremption proche        en stock, mais un lot approche de sa date
En rupture               on l'a tenu, il n'en reste rien
Commandés, jamais reçus  commandé, jamais arrivé — hors de la liste courante
Inactifs                 désactivé avec un motif
```

Les catégories sont **exclusives** : leur somme fait le total. Un produit
dont un lot périme bientôt est disponible, mais il est compté sous
« Péremption proche » — d'où le libellé « Disponibles **sans alerte** ».

---

## Qui a le droit de quoi

```text
socle PHARMACY     stock, lots, inventaire, ajustements, délivrance,
                   consommables Soins, prix de vente et nom des médicaments,
                   voir les commandes, RÉCEPTIONNER, saisir la facture
accordé par nom    créer et envoyer une commande, l'annuler, la mettre à la
                   corbeille, corriger une facture, tenir le dossier
                   fournisseur et ses catalogues, voir les prix d'achat
```

Recevoir la marchandise est le travail de la pharmacie : ces droits sont dans
son socle. Décider un achat, payer un fournisseur et tenir son dossier sont
d'autres responsabilités, que le Super Administrateur accorde nominativement
depuis le portail (ADR-098, ADR-176).

---

## Supprimer, archiver : ce n'est pas la même chose

```text
Mettre à la corbeille    réversible. L'élément quitte les listes, part à la
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

**Qui valide une commande ?**
Personne de plus : **« Envoyer la commande » est la validation**. Après quoi
l'étape suivante est la réception.

**Le bouton « Réceptionner » n'apparaît pas**
Il manque le droit `goods_receipts.create` à ce compte ; l'écran le dit à la
place du bouton. Il s'accorde depuis le portail › Rôles & permissions.

**Un produit apparaît dans le stock alors qu'il n'est pas livré**
Il a été créé par une commande (étape 3). Il est dans l'onglet
« Commandés, jamais reçus », sans lot, sans stock et sans prix : il ne peut
être ni délivré ni vendu.

**L'écran d'entrée en stock me montre tout le catalogue**
C'est l'onglet « Entrée sans commande ». Si vous arrivez d'une livraison déjà
rangée, l'écran vous le dit et bascule sur cet onglet — il n'y a plus rien à
faire entrer pour cette commande.

**Un médicament en stock n'est pas proposé à la vente**
Il n'a pas de prix de vente (étape 6), ou il est épuisé.

**Famille et Prix de vente affichent « — »**
Le prix de vente n'est jamais déduit du prix d'achat : il se fixe à l'étape 6.
La famille est reprise du catalogue lorsque le fournisseur l'a déclarée ;
sinon elle se choisit à la fiche du médicament.

**Un produit apparaît en double**
Cela ne devrait plus arriver depuis qu'un même libellé est réutilisé d'un
fournisseur à l'autre. Si deux fiches subsistent d'avant, désactivez celle
qui n'a pas de stock plutôt que de la supprimer.

**Tout effacer pour recommencer un test**
`php artisan rivo:pharmacy-reset` vide le domaine Pharmacie entier
(fournisseurs, catalogues, commandes, réceptions, factures, stock, lots,
mouvements, médicaments). Il refuse de s'exécuter ailleurs qu'en local et
conserve une facture déjà encaissée.
