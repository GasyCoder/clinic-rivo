# Gardiennage et visiteurs

## Contrôler la sortie d'un patient (Gardiennage)

Menu **Gardiennage** (adresse `/guarding`). Droits : `guarding.view` pour voir, `guarding.entries.close` pour enregistrer un contrôle.

- La liste montre les patients dont la **sortie administrative** est prononcée (payé comptant ou dette validée) et pas encore contrôlés.
- Rechercher par nom ou numéro, ou **scanner le QR code** de la fiche de sortie.
- Enregistrer le contrôle, avec une observation facultative.

Le gardien constate une sortie décidée par la Caisse ; il ne la décide jamais. Un passage sans sortie administrative est renvoyé vers la Réception. Un seul contrôle par passage.

## Registre des visiteurs

Menu **Visiteurs** (adresse `/reception/visitors`). Droits : `visitors.view`, `visitors.create`, `visitors.close`.

- « Enregistrer l'entrée » : nom, téléphone, catégorie (professionnel ou visite patient / famille), organisme ou patient visité, motif. Un professionnel peut joindre jusqu'à quatre documents (JPEG, PNG, WebP, PDF, 5 Mo chacun).
- « Enregistrer la sortie » quand la personne part.

Le registre des visiteurs ne crée jamais de patient, de passage, de facture ni de paiement.
