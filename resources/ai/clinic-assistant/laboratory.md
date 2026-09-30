# Laboratoire — la paillasse du technicien

Le groupe **Laboratoire** du menu réunit : **Paillasse** (`/laboratory`), **Feuille de paillasse**, **Rapports du laboratoire**, **Prélèvements & tubes** et **Germes & antibiotiques**. Ouvrir le Laboratoire demande le droit `laboratory_results.view`. Le Laboratoire n'encaisse rien et n'affiche aucun montant : les analyses se règlent à la Caisse.

## La file des demandes

Vues : **À traiter** (à commencer ou en cours), **À refaire**, **Terminées** (à envoyer au médecin), **Envoyées**, **Toutes**, **Archivées**. Chaque ligne a un seul bouton : **Traiter** (pas commencée), **Continuer** (en cours), **Reprendre** (à refaire), **Envoyer** (terminée) ou **Voir**. La recherche retrouve une demande par le patient ou le numéro de laboratoire ; scanner l'étiquette d'un tube l'ouvre.

## Traiter une demande

1. Sur la ligne, cliquer **Traiter** (ou, dans la demande, **Commencer le traitement**, en ajoutant les tubes si on le souhaite). La demande reçoit son numéro de laboratoire. Droit : `laboratory_results.create` ou `laboratory_orders.receive`.
2. Le règlement s'affiche pour information (« À régler à la Caisse ») : il **n'empêche pas** l'analyse. Urgence et patient hospitalisé restent signalés.

## Saisir les résultats

- À gauche, **Tâche(s) à traiter** : une carte par analyse (À faire, En cours, Terminée, Envoyée, À refaire). À droite, l'analyse ouverte : une carte par ligne, avec la norme, l'antériorité, le **Résultat** et l'**Interprétation** (Auto, Normal, Patho).
- La saisie **s'enregistre toute seule**. Pour un nombre, le champ dit « Dans la norme », « Au-dessus de la norme » ou « Valeur critique » ; **Entrée** passe à la valeur suivante.
- Culture : « Présence de germe(s) » nomme 1 à 6 germes ; chaque germe ouvre son **antibiogramme** (Sensible / Intermédiaire / Résistant, diamètre facultatif). Score de Nugent : les trois sous-scores.
- **Conclusion partielle** : sous une ligne ou un groupe, **Ajouter une conclusion**, puis **Valider** ou **Annuler** ; elle se modifie ou se supprime ensuite. La **conclusion générale**, facultative, s'imprime sous tous les résultats.
- Résultat critique : marqué d'office quand une borne critique est réglée au catalogue ; sinon **Signaler ce résultat comme critique** (droit `laboratory_results.flag_critical`) ; **Retirer le signalement critique** l'enlève.

## Terminer, puis envoyer au médecin

1. Au pied de la saisie, **Terminer l'analyse** (droit `laboratory_results.create`) : l'analyse passe « Terminée » et la suivante s'ouvre. Refusé si rien n'est saisi, si une culture positive n'a aucun germe, ou si le Nugent est incomplet.
2. En haut de la page, **Envoyer au médecin** (droit `laboratory_results.validate`) : les analyses terminées sont cochées (**Tout envoyer**) ; on en décoche pour n'en envoyer qu'une partie. Une analyse non terminée ne part jamais.
3. Choisir les destinataires : le prescripteur est proposé ; on peut en cocher un, plusieurs, **Tous les médecins**, ou **Aucun médecin — patient externe**. Chaque médecin est prévenu, puis **valide** le résultat de son côté ; pour un patient externe, l'envoi vaut validation.

Une analyse envoyée ne se modifie plus.

## Corriger une analyse

Le bouton **Autres actions**, au pied de l'analyse :

- **Rouvrir la saisie** : une analyse terminée mais pas encore envoyée, sans motif.
- **Renvoyer à refaire** : une analyse terminée ou envoyée, avec un motif (droit `laboratory_results.return`). Les médecins destinataires sont prévenus ; on corrige puis on renvoie.
- **Confier à un laboratoire extérieur** (droit `laboratory_orders.send_out`) : le **Bon d'envoi** s'imprime ; au retour, le résultat se saisit ici puis s'envoie au médecin avec « réalisée par » ce laboratoire. **Faire ici** annule tant qu'aucun résultat n'est rendu.
- **Réinitialiser la saisie** : efface résultats, antibiogrammes et conclusions partielles d'une analyse pas encore envoyée, après confirmation.

## Prélèvements et étiquettes

- Dans la demande, **Ajouter un prélèvement** (droit `laboratory_samples.create`) ; un tube se déclare **Non conforme** avec un motif (`laboratory_samples.update`), sans être supprimé.
- **Étiquettes** : code-barres par tube, sur rouleau 50 × 25 mm ou planche A4 de 24.
- **Prélèvements & tubes** (`/laboratory/prelevements`, droits `lab_sample_types.*`) et **Germes & antibiotiques** (`/laboratory/microbiologie`, droits `lab_microbiology.*`) : les référentiels du site, avec **Importer le référentiel de départ**.

## Compte rendu, feuille de paillasse, historique, rapports

- **Compte rendu PDF** (dans la demande) : il porte « Document provisoire » tant qu'une analyse n'est pas envoyée ; il se télécharge ou s'imprime.
- **Feuille de paillasse** : ce qui reste à faire ici, par discipline, urgences d'abord, imprimable.
- **Historique du patient** : les valeurs de chaque paramètre, demande par demande.
- **Rapports du laboratoire** (droit `laboratory_reports.view`, export `laboratory_reports.export`) : activité, délais, disciplines.

## Archiver, corriger ou mettre à la corbeille une demande

- **Archiver** une demande dont tout est envoyé (droit `laboratory_orders.archive`) ; **Désarchiver** la remet dans la file.
- Corriger (droit `laboratory_orders.update`) : **Ajouter une analyse**, **Retirer l'analyse** (motif, jamais après un envoi, jamais la dernière), **Renseignements cliniques**.
- **Mettre à la corbeille** une demande saisie à tort (droit `laboratory_orders.delete`, motif), jamais après un envoi au médecin ; elle se restaure depuis la Corbeille.
- Des cases permettent d'archiver, désarchiver ou mettre à la corbeille plusieurs demandes (50 au plus) ; chaque demande est jugée seule.

## Pourquoi un bouton manque ou est verrouillé

- Sans le droit, le bouton reste visible, verrouillé, et nomme le droit à demander à l'administrateur.
- Depuis le portail Super Administration, le Laboratoire d'un site se lit, mais les gestes sur une demande restent au site.
- Une analyse envoyée ne se corrige que par **Renvoyer à refaire**.
