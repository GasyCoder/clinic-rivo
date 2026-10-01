# Maternité — consultations prénatales et accouchement

Menu Soins › **Maternité** (adresse `/maternity`). Droits : `maternity.view` pour voir, `maternity.update` pour prendre en charge et écrire, `maternity.procedures.manage` pour les actes. La Maternité n'encaisse rien.

## La file Maternité

Même tableau que les Soins (En attente, En cours chez moi, Terminés chez moi), avec les onglets de parcours **Tous / Consultations / Accouchements**. « Prendre en charge » demande de choisir le parcours.

## Choisir le parcours et la grossesse

- Deux parcours : **Consultation prénatale** et **Accouchement**. La suggestion de la Réception présélectionne seulement.
- Chaque consultation se rattache à une **grossesse** : « Continuer cette grossesse » si une grossesse est en cours, sinon en créer une. Le terme et la date prévue d'accouchement sont calculés à partir des dernières règles ; « Corriger la datation » est tracé.

## Consultation prénatale

Étapes : Vue d'ensemble, Interrogatoire, Examen, Paraclinique, Ordonnance, Synthèse, Rendez-vous. Le dossier s'enregistre de lui-même. Les examens se demandent à l'étape Paraclinique (droits d'examen requis), une ordonnance à l'étape Ordonnance (droits de prescription, recommandés au profil sage-femme). Un prochain rendez-vous peut être prévu. Bouton final : **« Terminer la consultation »**.

## Accouchement

Étapes : Admission, Travail, Surveillance, Accouchement, Nouveau-né, Ordonnance, Transmission. L'heure d'accouchement consignée clôt la grossesse. Une césarienne décidée ici part au bloc comme demande chirurgicale. Bouton final : **« Clôturer l'accouchement »**.

## Enregistrer des actes (panier d'actes)

Dans « Actes & transmission » : cliquer les actes pour les mettre dans le **Panier d'actes**, régler quantité et précision (« Autres » exige une précision), ajouter le matériel utilisé, puis enregistrer tout le panier. Les actes sont facturés au patient et payés à la Caisse. Un acte se corrige ou se retire (crayon, corbeille), sauf celui d'un médecin.

## Nouveau-né

Chaque bébé a sa fiche (sexe, poids, Apgar, soins). Son **dossier patient** s'ouvre depuis cette fiche avec « Créer le dossier patient » (droit `newborns.patient.create`) : son numéro dérive de celui de la mère. Il n'est pas créé à l'accueil.

## Terminer la prise en charge

À la fin, choisir **terminer** (la patiente quitte la Maternité) ou **terminer et orienter vers Médecine**. Un dossier terminé est en lecture seule.
