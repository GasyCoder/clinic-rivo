# Demandes d'examens, résultats d'analyses et imagerie

Trois écrans :

- Médecine › **Demandes d'examens** (`/medicine/demandes-examens`, droit `paraclinical_requests.view`) : les analyses et l'imagerie demandées, leurs résultats, et la validation des résultats d'analyses reçus du laboratoire.
- Réception › **Résultats à remettre** (`/reception/resultats-analyses`, droit `laboratory_results.validated_view`) : les résultats d'analyses **validés** par le médecin, à imprimer et remettre au patient.
- Les comptes rendus d'**imagerie** (ECG, échographie) se saisissent depuis Demandes d'examens.

La saisie des résultats d'analyses se fait au **Laboratoire**, par le technicien (voir la fiche Laboratoire). Les examens sont facturés au patient et réglés à la Caisse : ni le Laboratoire ni l'Imagerie n'encaissent.

## Demander une analyse ou une échographie

La demande naît en consultation (étape **Examens paracliniques**), à la Réception (au besoin de l'arrivée), sur la page d'un séjour d'hospitalisation (onglet Examens) ou dans le dossier Maternité. Chaque demande transmise est facturée au patient ; un examen déjà facturé à la Réception n'est pas refacturé.

## Le parcours d'un résultat d'analyse

1. Le technicien saisit, **termine**, puis **envoie au médecin** les résultats.
2. Le médecin destinataire est prévenu (cloche). Le résultat apparaît « Terminé · à valider ».
3. Le médecin relit et **valide** : le résultat devient « Résultats validés ».
4. La Réception le voit alors dans **Résultats à remettre**.

Une analyse envoyée à « Aucun médecin — patient externe » est validée dès son envoi.

## Valider un résultat reçu (médecin)

1. Ouvrir **Demandes d'examens** : la vue **À valider** liste les résultats reçus.
2. Sur la ligne, **Vérifier et valider** ouvre la feuille des résultats et son compte rendu PDF.
3. **Valider** une analyse, ou **Tout valider** ; une confirmation est demandée.

Droit : `laboratory_results.approve`. Si un résultat semble faux : **Demander à refaire**, avec un motif (droit `laboratory_results.return`) ; le technicien est prévenu, puis le résultat corrigé revient à valider.

## Résultat adressé à un autre médecin

Un résultat adressé à un confrère s'affiche fermé (« adressé à … »). **Ouvrir les résultats** demande une confirmation : l'ouverture est enregistrée dans l'audit, au nom de qui l'ouvre. Le destinataire, le prescripteur et le laboratoire lisent sans confirmation.

## Remettre les résultats au patient (Réception)

Dans **Résultats à remettre** : vues **Tous**, **Complets** (toutes les analyses validées) et **Partiels** (d'autres résultats attendus), avec une recherche. **Compte rendu** ouvre le PDF, avec **Imprimer**, **Télécharger** et **Ouvrir**. Seuls les résultats validés y figurent.

## Où relire un résultat

- Dans **Demandes d'examens** : onglets Tous / ECG / Échographie / Analyses, et vues À valider, Actives, Rendues récemment, Archivées.
- Dans la consultation (étape Examens paracliniques), le détail du passage, et le dossier Maternité pour une grossesse.
- Le médecin ne voit un résultat d'analyse qu'une fois envoyé par le laboratoire.

## Saisir ou corriger un compte rendu d'imagerie

Dans Demandes d'examens, sur la ligne de l'examen : **Saisir**. La fenêtre propose les **feuilles** de compte rendu de la clinique ou le texte libre ; **Aperçu** montre le document tel qu'il s'imprimera. Droits : `imaging_results.create` pour saisir, `imaging_results.update` pour **Modifier** un compte rendu enregistré (l'ancienne version reste dans l'historique). Un médecin autorisé ajoute ou modifie une feuille (`imaging_templates.*`).

## Retirer ou archiver une demande

- **Retirer la demande** : possible tant qu'aucun résultat n'est saisi ; la facturation portée par la demande est annulée.
- **Archiver** (droit `paraclinical_requests.archive`) : ranger une demande dont tous les résultats sont rendus ; elle se sort de l'archive. Une demande qui attend sa validation ne s'archive pas.

## Pourquoi un résultat ou un bouton manque

- Sans `laboratory_orders.view` ou `imaging_orders.view`, la section correspondante n'est pas affichée.
- Sans `laboratory_results.approve`, « Valider » est remplacé par le nom du droit à demander.
- Un résultat que le laboratoire n'a pas encore envoyé n'apparaît pas chez le médecin.
- Un examen sans famille configurée apparaît dans l'onglet « Non classés ».
