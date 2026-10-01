# Réception — accueillir un patient

Module Réception (menu Réception › Accueil & passages). Droits utiles : `reception.view` pour voir, `episodes.create` pour accueillir un patient. La Réception enregistre l'arrivée et prépare la facture ; l'encaissement se fait à la Caisse.

## Accueillir un patient : les six étapes

Bouton **« Nouvelle prise en charge »** (adresse `/reception/patients`). L'accueil avance en six étapes :

1. **Besoin** — ce que le patient vient faire. Deux rayons dans un même panier : « Désignations & consultations » (prestations tarifées) et « Pharmacie » (médicaments en stock). Un besoin inconnu peut rester « à préciser ».
2. **Estimation** — le montant prévu, au tarif Standard. Rien n'est encore créé : on peut s'arrêter ici si le patient voulait seulement le prix.
3. **Patient** — retrouver ou créer l'identité : « Patient existant » (recherche), « Personnel & stagiaires » (reprise depuis le dossier RH, sans ressaisie) ou « Nouveau patient ». Un doublon probable est signalé : choisir « C'est la même personne » plutôt que de créer un second dossier.
4. **Passage** — le passage est créé avec son numéro ; la personne à prévenir se saisit ici, pour ce passage.
5. **Prise en charge** — Standard (sans mutuelle), Mutuelle (choisir l'organisme), Personnel (dossier relié à un employé en poste) ou Partenaire. C'est aussi là qu'on classe le passage en urgence.
6. **Confirmation** — relire les prestations et, si on le souhaite, cocher une **prochaine étape suggérée** (Soins, Médecine, Maternité, Laboratoire…). Cette suggestion est indicative : elle n'empêche aucun service de voir le passage.

Après confirmation, la facture est préparée ; le patient règle à la **Caisse**.

## Classer un passage en urgence

À l'étape Prise en charge, le bouton **« Classer ce passage en urgence »** (droit `episodes.mark_emergency`), puis « Confirmer l'urgence ». L'urgence concerne ce passage seulement, jamais le dossier du patient. Les files Soins et Médecine sont ouvertes immédiatement, et aucun paiement n'est exigé avant les soins. Le médecin peut aussi classer le passage en urgence pendant la consultation.

## Nouveau patient, enfant ou bébé

- Pour un enfant, choisir la civilité « Enfant fille » ou « Enfant garçon » : téléphone, email, profession et pièce d'identité ne sont alors pas demandés ; le parent se saisit comme personne à prévenir du passage.
- Un bébé demande sa date de naissance exacte.
- Un bébé **né à la clinique** ne se crée pas à l'accueil : son dossier patient s'ouvre depuis la fiche du nouveau-né, dans le dossier Maternité de sa mère. Un bébé né ailleurs est un nouveau patient ordinaire.
- À la création d'un nouveau dossier, on peut noter qui a recommandé la clinique (membre du personnel, partenaire ou autre personne).

## Venir seulement pour des médicaments

Mettre seulement des médicaments dans le panier (rayon Pharmacie). Le passage n'ouvre aucune file clinique : à l'étape Prise en charge, choisir « Terminer — envoyer à la Caisse ». Un ticket Pharmacie est créé ; le patient paie à la Caisse, puis la Pharmacie délivre. Il n'existe plus de vente anonyme au comptoir : toute vente passe par un dossier patient.

## Qui voit le patient après l'accueil

Tout passage ouvert et accueilli est visible des Soins, de la Médecine et de la Maternité, dans leur tableau des passages. La prochaine étape suggérée ne décide rien : un service **prend le patient en charge** par un geste explicite. Les analyses et les actes du bloc sélectionnés au besoin créent directement leur demande au Laboratoire ou au Bloc.

## Corriger une suggestion ou retrouver un passage

- La prochaine étape suggérée se corrige depuis le détail du passage (droit `episodes.update`).
- Les passages se retrouvent dans Réception › Accueil & passages, ou par la recherche en haut de l'écran.

## Recommandations et cadeau

Menu Réception › **Recommandations** : qui a recommandé la clinique à un nouveau patient, avec un filtre « Cadeau à remettre ». « Remettre le cadeau » le marque remis (une seule fois). Le cadeau n'est ni une facture ni un paiement.
