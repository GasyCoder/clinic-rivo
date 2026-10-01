# Patients — répertoire et dossier patient

Menu **Patients** (adresse `/patients`). Droit : `patients.view`.

## Retrouver un patient

- La recherche du répertoire se lance d'elle-même (nom, prénom, numéro de dossier). La recherche en haut de l'écran fonctionne aussi partout.
- Les cartes en haut filtrent selon ce que le patient attend encore : « Médecine seulement », « Soins seulement », « Pharmacie seulement », « Les 3 services ».
- Les onglets classent par état : Tous, Besoin en cours, En attente de règlement, Aucun passage ouvert.
- Tri A → Z, filtre par initiale, filtre Patients normaux / VIP.
- Export Excel de la liste filtrée : droit `patients.export`.

## Le dossier patient

Ouvrir un patient depuis le répertoire. Le dossier montre l'identité permanente, les allergies et antécédents, et l'onglet **Passages** (chaque venue avec son parcours : Réception, services, Pharmacie, factures, sortie).

- **Dossier médical** (bouton dans l'en-tête ou sur un passage) : la feuille imprimable qui relit ce qui est consigné. Chaque section reste gardée par son droit (diagnostic, traitements, hospitalisation…).
- **Journaux de traitement** : tous les journaux des passages du patient en un seul document (droit `treatment_journal.view`) ; « Télécharger le PDF » passe par l'impression du navigateur.
- La mère d'un bébé né à la clinique voit la carte « Nouveau-nés nés à la clinique ».

## Détail d'un passage

Adresse `/passages/{numéro}` : le parcours complet d'une venue (Réception, orientations, Soins, Médecine, Pharmacie, factures, encaissements, sortie), en lecture seule. Chaque section n'apparaît qu'avec le droit correspondant.

## Patient VIP

Un patient est VIP s'il atteint, sur la période réglée par le Super Administrateur, un nombre de passages **et** un montant encaissé. La catégorie est calculée, jamais saisie à la main. Elle peut donner droit à une remise à la Caisse.

## Modifier ou archiver un dossier

La fiche d'identité se corrige depuis le dossier (droit `patients.update`). Archiver un dossier demande un motif ; il va dans la Corbeille et se restaure avec le droit `patients.restore`.
