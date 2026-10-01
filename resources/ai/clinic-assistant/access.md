# Utilisateurs et accès du site

Menu **Utilisateurs & accès** (adresse `/administration/users`, droit `users.view`).

## Créer ou modifier un compte

« Nouvel utilisateur » : choisir d'abord **Personnel clinique** (rechercher la fiche RH de la personne : nom et email en sont repris) ou **Externe** (une personne sans fiche RH), puis le rôle et, si le rôle en a, le profil métier. Droits : `users.create`, `roles.assign`.

Un compte n'est jamais supprimé s'il a servi : il se **désactive** avec un motif, ce qui ferme ses sessions. On ne peut ni se désactiver soi-même, ni modifier son propre rôle.

## Rôles, profils et droits

- Le **rôle** (Réception, Médecine, Soins, Pharmacie…) donne un socle de droits.
- Le **profil métier** (infirmier, sage-femme, anesthésiste, chirurgien…) décrit la fonction ; ses droits recommandés s'appliquent explicitement au compte.
- Des **exceptions individuelles** autorisent ou refusent un droit à un compte ; un refus l'emporte toujours.

Le socle des rôles et les exceptions se règlent dans « Rôles & permissions », sur le portail Super Administration.
