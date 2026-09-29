# Pharmacie — délivrance, stock et achats

Menu **Pharmacie** (entrée mère). Sous-menus selon vos droits : Ordonnances à délivrer, Consommables Soins, Médicaments & stock, Achats, Fournisseurs. La Pharmacie n'encaisse **jamais** : le patient paie à la Caisse, puis la Pharmacie délivre.

## Délivrer une ordonnance (sortie de pharmacie)

Pharmacie › **Ordonnances à délivrer** (`/pharmacy/dispenses`). Une ordonnance validée par un médecin (ou une vente préparée à la Réception) arrive ici avec ses lots réservés.

1. « Voir » ouvre l'ordonnance et ses produits.
2. **« Préparer le ticket »** crée la facture ; le ticket imprimé porte un QR code (droit `pharmacy.dispense.prepare_invoice`).
3. Le patient paie le ticket à la **Caisse**.
4. **« Délivrer »** sort le stock, lot par lot, du plus proche de la péremption au plus lointain (droit `pharmacy.dispense`). Une délivrance partielle est possible.

La délivrance reste bloquée tant que la facture n'est pas réglée, sauf pour un patient hospitalisé (délivrance au service).

## Servir le matériel des Soins, de la Maternité ou du bloc

Pharmacie › **Consommables Soins** : le matériel déclaré par les soignants. « Servir et sortir le stock » (droit `care_consumables.serve`) sort les lots sans attendre le règlement, car le matériel est déjà utilisé. L'écran montre si chaque ligne est facturée ; une ligne « non facturée » est à régulariser par la Réception.

## Consulter le stock

Pharmacie › **Médicaments & stock** (`/pharmacy/stock`). Filtres par état : Disponibles sans alerte, **Rupture**, **Péremption proche**, Sans prix de vente, Commandés, jamais reçus. Chaque médicament ouvre sa fiche : lots, péremptions, mouvements. Droits : `stock.view`, `medicines.view`.

## Voir les produits presque en rupture

- La **Vue d'ensemble** de la Pharmacie a deux cartes : « À surveiller » (ruptures, péremptions proches…) et « À recommander » (produits passés sous leur **seuil minimal**).
- Le seuil minimal se règle sur la fiche du médicament ; l'alerte apparaît et disparaît d'elle-même.
- Dans Médicaments & stock, le filtre « Rupture » liste les produits épuisés.

## Commander et recevoir des médicaments

Pharmacie › **Achats** :

1. Créer une commande pour un fournisseur (ou « Comparer et commander » depuis le portail), puis « Envoyer la commande ».
2. À la livraison, **« Réceptionner »** : quantité, n° de lot et péremption lus sur la boîte. Un article non livré peut être signalé en rupture.
3. **Entrée en stock** : « Ranger cette livraison » rend la marchandise disponible. C'est là qu'on donne le prix de vente d'un nouveau produit.

La facture du fournisseur se saisit avec la réception ou plus tard. Ces droits (`purchase_orders.*`, `goods_receipts.*`, `supplier_invoices.*`) sont accordés au compte par l'administrateur.

## Inventaire et corrections de stock

« Inventaire » (droit `stock.adjust`) : saisir ce qui est sur l'étagère ; seuls les écarts créent des ajustements, avec un motif. Un mouvement de stock validé ne se modifie jamais : on le corrige par un ajustement tracé.

## Pourquoi je ne peux pas délivrer ou entrer en stock

- La facture n'est pas encore réglée à la Caisse.
- Stock insuffisant, ou lots réservés pour d'autres ordonnances.
- Rien n'entre au stock hors d'une livraison réceptionnée : il faut une commande et sa réception.
- Droit manquant (`pharmacy.dispense`, `stock.entry`, `stock.adjust`…).
- Depuis le portail, les gestes physiques (délivrer, réceptionner, inventaire) restent réservés au site.
