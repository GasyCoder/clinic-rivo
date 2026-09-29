# Examens — Demandes d'examens, Laboratoire et Imagerie

Trois écrans :

- Médecine › **Demandes d'examens** (`/medicine/demandes-examens`, droit `paraclinical_requests.view`) : toutes les analyses et l'imagerie demandées, et leurs résultats.
- **Laboratoire** (`/laboratory`, droit `laboratory_orders.view`) : la paillasse, où le laboratoire saisit les résultats d'analyses.
- Les comptes rendus d'**imagerie** (ECG, échographie) se saisissent depuis Demandes d'examens.

Le Laboratoire et l'Imagerie n'encaissent jamais : les examens sont facturés au patient et payés à la Caisse.

## Où voir les résultats d'analyse ou d'imagerie

- Dans **Demandes d'examens** : onglets de famille Tous / ECG / Échographie / Analyses, et vues « à rendre », « rendues récemment », archivées. « Voir le résultat » ouvre le résultat.
- Dans la consultation, étape **Examens paracliniques**, et dans le détail du passage.
- Les résultats d'une grossesse se relisent aussi dans le dossier Maternité.

## Demander une analyse ou une échographie

La demande naît en consultation (étape Examens paracliniques), à la Réception (au besoin de l'arrivée), sur la page d'un séjour d'hospitalisation (onglet Examens) ou dans le dossier Maternité. Chaque demande transmise est facturée au patient.

## Saisir un résultat d'analyse (Laboratoire)

Dans le Laboratoire, la file « À analyser » liste les analyses en attente. Choisir ou saisir le résultat de chaque analyse puis « Enregistrer » (droit `laboratory_results.create`). Les valeurs de référence proposées dépendent de l'âge et du sexe du patient ; elles sont figées avec le résultat.

## Saisir ou corriger un compte rendu d'imagerie

Dans Demandes d'examens, sur la ligne de l'examen : **« Saisir »**. La fenêtre propose les **feuilles** de compte rendu de la clinique (colonnes du papier) ou le texte libre ; « Aperçu » montre le document tel qu'il s'imprimera. Droits : `imaging_results.create` pour saisir, `imaging_results.update` pour **Modifier** un compte rendu déjà enregistré (l'ancienne version est conservée dans l'historique, avec un motif facultatif). Un médecin autorisé peut ajouter ou modifier une feuille (`imaging_templates.*`).

## Retirer ou archiver une demande

- **Retirer la demande** : possible tant qu'aucun résultat n'est saisi ; la facturation portée par la demande est annulée.
- **Archiver cette demande** (droit `paraclinical_requests.archive`) : ranger une demande dont tous les résultats sont rendus. Rien n'est supprimé ; elle se sort de l'archive.

## Pourquoi un résultat ou un bouton manque

- Sans `laboratory_orders.view` ou `imaging_orders.view`, la section correspondante n'est pas affichée.
- Un examen sans famille configurée apparaît dans l'onglet « Non classés ».
- Une demande retirée n'apparaît plus dans la file du Laboratoire.
