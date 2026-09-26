# RIVO — Architecture Decision Records

CDC de référence :

```text
https://github.com/GasyCoder/cdc-clinic-george
```

Ce document contient les décisions techniques actuellement validées.

Un agent IA ne doit jamais modifier silencieusement une décision avec le statut :

```text
ACCEPTED
```

---

# ADR-001 — Deux bases de données

**Status:** ACCEPTED

Les sites Mampikony et Ambondromamy sont indépendants.

Ils possèdent deux bases différentes :

```text
DB_MAMPIKONY
DB_AMBONDROMAMY
```

Aucun accès SQL direct n'est autorisé entre les deux bases.

---

# ADR-002 — Une seule codebase

**Status:** ACCEPTED

Les deux sites utilisent la même codebase Laravel.

```text
Repository application
        │
        ├── Deployment Mampikony
        └── Deployment Ambondromamy
```

Les différences de site sont configurées par environnement.

---

# ADR-003 — Communication inter-sites

**Status:** ACCEPTED

Tous les échanges inter-sites utilisent REST API.

Cela concerne notamment :

```text
patient transfer
stock transfer
patient lookup
authorized medical data
status exchange
other future inter-site operations
```

---

# ADR-004 — Super Administration

**Status:** ACCEPTED

Le Super Admin utilise :

```text
https://admin.rivo.mg
```

Le Super Admin communique avec les deux sites via API.

Aucun accès direct :

```text
admin.rivo.mg → DB_MAMPIKONY
```

ou :

```text
admin.rivo.mg → DB_AMBONDROMAMY
```

n'est autorisé.

---

# ADR-005 — UUID

**Status:** ACCEPTED

Les IDs SQL restent locaux.

Les entités échangées entre sites disposent d'un UUID.

Exemple :

```text
id
uuid
```

`id` :

```text
identifiant local DB
```

`uuid` :

```text
identifiant distribué/inter-site
```

---

# ADR-006 — Rôles

**Status:** SUPERSEDED pour la structure rôles/profils par ADR-033

Les rôles principaux sont :

```text
SUPER_ADMIN
ADMINISTRATION
RECEPTION
MEDICINE
NURSE
LOGISTICS
SUPPORT
MAINTENANCE
SURGERY
PHARMACY
LABORATORY
```

`NURSE` (Infirmier / Sage-femme) a été ajouté sur demande explicite de
l'équipe : le CDC (§9) mentionne "infirmier, sage-femme, soins" comme
profils rattachés au rôle MEDICINE, mais sans rôle RBAC dédié — un
infirmier n'a pas besoin du même périmètre qu'un médecin (diagnostics,
prescriptions), d'où un rôle séparé plutôt qu'un sous-ensemble de
permissions MEDICINE. Permissions couvertes (voir ADR-007bis) : catalogue
"Soins" du CDC §15 (`care.*`, `vitals.*`). ADR-033 précise que le catalogue
"Anesthésie" du CDC §16 ne doit jamais être accordé à tous les comptes NURSE :
il est affecté individuellement aux anesthésistes autorisés. Il n'existe aucun
catalogue de permissions "Maternité"
dans le CDC — non inventé, à définir avec l'équipe le jour où ce module
sera construit.

---

# ADR-007 — Permissions dynamiques

**Status:** ACCEPTED

Les permissions sont configurables.

La sécurité ne doit pas dépendre uniquement d'un rôle codé en dur.

Convention :

```text
resource.action
```

Exemples :

```text
patients.view
patients.create
patients.update
patients.delete
patients.restore
```

---

# ADR-008 — Actions de permissions

**Status:** ACCEPTED

Catalogue de base :

```text
view
create
update
delete
restore
view_deleted

validate
approve
reject
cancel

activate
deactivate

print
export
import

open
close

assign
transfer

archive
unarchive

sync
manage

force_delete
```

Le catalogue peut évoluer.

---

# ADR-009 — Soft Delete

**Status:** ACCEPTED

La permission :

```text
delete
```

réalise normalement un Soft Delete.

Les modèles concernés doivent pouvoir utiliser :

```text
deleted_at
deleted_by
delete_reason
```

Restauration :

```text
restore
```

Suppression physique :

```text
force_delete
```

`force_delete` est extrêmement contrôlé.

---

# ADR-010 — Données critiques

**Status:** ACCEPTED

Les données médicales et financières validées ne doivent pas être détruites normalement.

Préférer :

```text
cancel
correct
reverse
archive
```

Exemples :

```text
payments
validated invoices
validated laboratory results
validated prescriptions
validated stock movements
cash closings
completed transfers
audit logs
```

---

# ADR-011 — Caisse unique

**Status:** ACCEPTED

Chaque site possède une seule caisse fonctionnelle.

```text
Mampikony
→ Réception / Caisse

Ambondromamy
→ Réception / Caisse
```

---

# ADR-012 — Paiements

**Status:** ACCEPTED

Tous les paiements passent exclusivement par :

```text
Réception / Caisse
```

Les autres modules créent ou transmettent uniquement des prestations facturables.

---

# ADR-013 — Pharmacie sans caisse

**Status:** ACCEPTED

La Pharmacie ne peut jamais gérer :

```text
cash
payments
cash collection
financial refund
cash closing
payment receipt
```

Elle gère uniquement son activité métier et les stocks.

---

# ADR-014 — Laboratoire sans caisse

**Status:** ACCEPTED

Le Laboratoire ne réalise aucun encaissement.

Il peut lire le statut financier nécessaire à l'exécution d'une analyse.

---

# ADR-015 — Médecine sans caisse

**Status:** ACCEPTED

La Médecine ne réalise aucun encaissement.

---

# ADR-016 — Chirurgie sans caisse

**Status:** ACCEPTED

La Chirurgie ne réalise aucun encaissement.

---

# ADR-017 — API résiliente

**Status:** ACCEPTED

Les échanges inter-sites critiques doivent prévoir :

```text
UUID
idempotency
request UUID
queue
retry
backoff
timeout
audit
```

Une panne distante ne doit pas corrompre les opérations locales.

---

# ADR-018 — DashWind

**Status:** ACCEPTED

Le design system principal est :

```text
DashWind
```

Stack frontend :

```text
Vue.js
Inertia.js
Tailwind CSS
DashWind
```

Ne pas introduire un deuxième design system sans validation.

---

# ADR-019 — Laravel

**Status:** ACCEPTED

Backend :

```text
Laravel 13
```

La logique métier doit être principalement organisée avec :

```text
Actions
Services
Policies
Form Requests
DTOs
Enums
Jobs
Events
Listeners
```

Les Controllers doivent rester légers.

---

# ADR-020 — CDC

**Status:** ACCEPTED

Le CDC est maintenu séparément :

```text
https://github.com/GasyCoder/cdc-clinic-george
```

Le repository applicatif ne doit pas maintenir une copie divergente du CDC.

En cas de conflit entre une ancienne partie du CDC et une décision récente présente ici :

1. signaler le conflit ;
2. appliquer la décision `ACCEPTED` la plus récente ;
3. ne jamais masquer la divergence documentaire.

---

# ADR-021 — Admission en urgence

**Status:** ACCEPTED (2026-08-19 — exigence explicite de l’équipe)

**Amendement de séquence :** ADR-056 (2026-08-29) impose désormais la
création de l’Épisode avant sa requalification en urgence. Les invariants
ci-dessous restent applicables une fois le passage classé `EMERGENCY`.

L’urgence est une priorité du passage (`episode`), jamais un statut permanent
du patient.

Valeurs initiales :

```text
NORMAL
EMERGENCY
```

Le patient urgent est enregistré avec le même dossier administratif, les mêmes
validations et le même contrôle anti-doublon qu’un patient normal. Pendant que
sa famille complète ce dossier à la Réception, le patient peut partir directement
vers Médecine / Soins.

Un passage `EMERGENCY` :

```text
- est visiblement marqué Urgence ;
- est immédiatement placé au statut administratif ORIENTED ;
- ne dépend d’aucun paiement pour commencer les soins ;
- reste audité comme tout autre passage.
```

Cette décision complète le CDC officiel, qui définit le passage et ses statuts
mais ne précise pas encore le parcours d’admission en urgence.

---

# ADR-022 — Comptes utilisateurs locaux et cycle d’accès

**Status:** ACCEPTED (2026-08-20 — exigence explicite de l’équipe) ; l’interdiction
de suppression physique reçoit une exception étroite et explicite par
**ADR-062** (2026-08-30, même jour que la fonctionnalité Rôles & permissions) —
uniquement pour un compte n’ayant jamais servi. Le reste de cette décision
(désactivation, motif, révocation de session, dernier Super Admin protégé)
reste pleinement en vigueur pour tout compte ayant une activité réelle.

Chaque site opérationnel gère ses propres utilisateurs dans sa propre base.
Un compte local ne devient pas automatiquement utilisable sur un autre site.
Le futur portail Super Administration continue de communiquer avec les sites
uniquement par API, conformément à ADR-004.

Tout compte autorisé à se connecter doit être :

```text
nominatif
actif
rattaché à exactement un rôle valide
protégé par un mot de passe conforme à la politique commune
```

Le rôle fournit les permissions métier par défaut. Les permissions individuelles
`allow` / `deny` sont des exceptions explicites ; `deny` reste prioritaire. Leur
attribution est réservée à `permissions.assign` et auditée. L’attribution ou la
gestion du rôle `SUPER_ADMIN` exige `users.assign_super_admin`.

Un utilisateur est un acteur historique des données cliniques, financières et
d’audit. Par conséquent :

```text
aucune suppression physique d’un utilisateur
désactivation avec date, auteur et motif obligatoires
révocation des sessions lors de la désactivation
interdiction de s’auto-désactiver
interdiction de modifier son propre rôle ou ses propres exceptions
interdiction de désactiver ou rétrograder le dernier Super Admin actif
```

Les seeders standards ne créent aucun compte de connexion ni mot de passe de
démonstration. Le premier vrai Super Admin est provisionné par une commande
sécurisée avec saisie masquée du mot de passe. Les anciens comptes démo sont
désactivés seulement après la création vérifiée de leur remplaçant réel, afin de
ne jamais verrouiller le site.

**Exception explicite de développement (2026-08-23).** À la demande du
propriétaire, `DevelopmentUserSeeder` crée des fixtures locales permettant de
tester chaque rôle et chaque profil professionnel. Il n'est jamais appelé par
`DatabaseSeeder`, refuse toute exécution hors `local` / `testing`, utilise
uniquement le domaine réservé `.test` et réinitialise ses propres comptes à
chaque lancement. Son mot de passe est configurable par
`RIVO_DEVELOPMENT_USERS_PASSWORD`. Cette exception ne permet aucun compte
générique ou mot de passe de test sur un environnement de production.

---

# ADR-023 — Séparation Réception Patient / Réception Visiteur

**Status:** ACCEPTED (2026-08-20 — exigence explicite de l’équipe)

La Réception possède deux parcours opérationnels séparés :

```text
Réception Patient
Réception Visiteur
```

Le parcours Patient conserve la recherche ou création du dossier patient et la
création d’un épisode normal ou urgent.

Le parcours Visiteur est strictement non clinique. Il ne crée jamais :

```text
patient
épisode
facture
paiement
```

Les catégories initiales confirmées sont :

```text
PROFESSIONAL
PATIENT_OR_FAMILY_VISIT
```

Le registre contient le nom complet, le téléphone, la catégorie, l’organisme
pour un professionnel, un patient concerné facultatif pour une visite patient ou
famille, le motif détaillé, l’heure d’entrée automatique et l’heure de sortie.
Une visite professionnelle peut également joindre jusqu’à quatre documents
facultatifs (brochure, carte ou autre support). Les fichiers sont stockés hors
du répertoire public, leurs chemins internes ne sont jamais exposés au frontend
et leur consultation exige `visitors.view`. Les formats initiaux sont JPEG,
PNG, WebP et PDF, avec une limite technique de 5 Mo par fichier. L’interface
n’affiche qu’un aperçu compact accompagné de `+N` lorsqu’il existe plusieurs
documents ; la galerie complète s’ouvre uniquement à la demande.
Les entrées, sorties et corrections sont auditées. Les URLs publiques utilisent
le UUID de la visite ; l’identifiant SQL reste local.

Permissions initiales :

```text
reception.view
visitors.view
visitors.create
visitors.close
```

La saisie appartient à Réception. Les futurs rapports administratifs ou besoins
de gardiennage pourront lire ces données avec des permissions dédiées, sans
déplacer la responsabilité de l’accueil opérationnel.

---

# ADR-024 — Référentiels, tarifs par site, stocks et équipements

**Status:** ACCEPTED (2026-08-20 — exigence explicite de l’équipe)

Les notions suivantes sont trois domaines différents et ne doivent jamais être
confondues dans une unique table de stock :

```text
prestations facturables : consultation, ECG, échographie, analyse, acte
produits stockables      : médicament, consommable médical, fourniture
équipements durables     : échographe, appareil ECG, lit, ordinateur
```

## Référentiel commun

Un référentiel identifie les prestations et produits par UUID, code stable,
libellé, type, module propriétaire, unité, caractère facturable ou stockable et
état actif/archivé. Les détails propres aux médicaments et aux équipements
restent dans des tables spécialisées ; le référentiel commun ne doit pas devenir
une table générique contenant toutes les colonnes métier.

Types initiaux :

```text
SERVICE
MEDICINE
CONSUMABLE
EQUIPMENT
```

## Tarifs locaux et historisés

Les tarifs sont propres à chaque site opérationnel. Un même élément, identifié
par le même UUID distribué, peut donc avoir un tarif différent à Mampikony,
Ambondromamy et Boriziny.

Une modification de tarif ferme la version précédente et crée une nouvelle
version avec date d’effet, auteur et motif. Elle ne modifie jamais les factures
ni les prestations déjà enregistrées. `billable_items` et `invoice_lines`
conservent la description, le tarif unitaire et le total utilisés au moment de
la facturation, même si le référentiel évolue ensuite.

La création, la modification, l’archivage et la restauration du référentiel et
des tarifs nécessitent des permissions granulaires dédiées, attribuées par
défaut uniquement au `SUPER_ADMIN` :

```text
catalog.items.view
catalog.items.create
catalog.items.update
catalog.items.delete
catalog.items.restore
catalog.tariffs.view
catalog.tariffs.create
catalog.tariffs.update
catalog.tariffs.archive
```

Le rôle seul n’est pas une autorisation : Laravel vérifie toujours la permission.
Réception, Médecine, Laboratoire, Pharmacie, Chirurgie et Soins peuvent utiliser
les éléments autorisés du référentiel, mais ne modifient pas les tarifs. La
saisie libre d’un prix à la Réception est interdite : Laravel résout le tarif
actif du site et en conserve un instantané sur la prestation facturable.

Le CDC officiel liste `medicines.create`, `medicines.update`,
`medicines.delete` et `medicines.restore` dans le catalogue Pharmacie. La
présente exigence, plus récente, réserve désormais la gestion du référentiel au
Super Admin. Ces permissions ne seront donc pas accordées par défaut au rôle
`PHARMACY` : celui-ci reçoit `medicines.view` et les permissions opérationnelles
de stock nécessaires. Une exception individuelle future restera possible par le
RBAC dynamique, sans donner le droit de modifier un tarif.

## Responsabilités opérationnelles

Le paramétrage est réservé au Super Admin, mais les opérations physiques restent
réparties conformément au CDC :

```text
SUPER_ADMIN    référentiels, tarifs, catégories, paramètres, vue globale
PHARMACY       lots, péremptions, entrées/sorties, inventaires, délivrances
ADMINISTRATION stock administratif, affectations et suivi des équipements
RECEPTION      sélection des prestations, facturation et encaissement
```

Un équipement durable est suivi individuellement par numéro d’inventaire, numéro
de série, localisation, état, affectations et maintenances. Un consommable est
suivi en quantité par mouvements de stock.

Un mouvement de stock validé, un tarif utilisé ou un équipement sorti du service
ne sont pas physiquement supprimés. Utiliser selon le cas Soft Delete, archivage,
correction, mouvement inverse ou mise hors service, avec motif et audit.

## Super Administration et multi-site

`admin.rivo.mg` ne lit ni n’écrit directement dans les bases locales. Le Super
Admin choisit une cible :

```text
Mampikony
Ambondromamy
Boriziny
Plusieurs sites sélectionnés
```

Une action multi-site envoie la même définition UUID séparément aux APIs
sélectionnées, mais chaque site conserve son propre tarif local et son propre état de
stock. Les commandes distribuées utilisent authentification, autorisation,
request UUID, idempotency key, audit, queue, retry, backoff et timeout. Une panne
d’un site ne doit ni annuler l’écriture réussie sur l’autre ni corrompre son état ;
le portail affiche le résultat par site et permet la reprise contrôlée.

---

# ADR-025 — Trois sites et séparation du portail Super Administration

**Status:** ACCEPTED (2026-08-20 — exigence explicite de l’équipe)

Cette décision étend et remplace, pour le nombre de sites, ADR-001, ADR-002,
ADR-004 et les passages du CDC officiel qui ne décrivent encore que Mampikony
et Ambondromamy. Le troisième site opérationnel confirmé est :

```text
Boriziny
```

L’architecture devient :

```text
Mampikony     -> application + DB_MAMPIKONY
Ambondromamy  -> application + DB_AMBONDROMAMY
Boriziny      -> application + DB_BORIZINY
admin.rivo.mg -> portail central, aucune connexion SQL vers ces trois bases
```

La codebase reste commune. Chaque site possède son déploiement, sa base, ses
utilisateurs locaux, sa caisse unique et ses données opérationnelles. Tout accès
du portail central aux données d’un site passe exclusivement par l’API REST
sécurisée de ce site, avec UUID, autorisation, audit et résilience.

## Portail Super Administration

Un compte du portail central doit posséder la permission :

```text
super_admin.portal.view
```

Elle est attribuée par défaut uniquement au rôle `SUPER_ADMIN`. Les permissions
restent la source d’autorisation ; le nom du rôle ne contourne ni un `DENY`
individuel explicite, ni les règles d’intégrité métier.

Après connexion, le portail présente :

```text
tableau de bord consolidé
Mampikony et ses modules
Ambondromamy et ses modules
Boriziny et ses modules
rapports financiers par site
Administration : RH, logistique, gardiennage
gestion des utilisateurs
gestion des rôles et permissions
paramètres globaux, dont le nom de l’application
audit et supervision API
```

La présence d’un menu central ne constitue pas une autorisation distante. Chaque
API cible valide à nouveau la permission et les règles métier. Une API non encore
configurée est affichée comme indisponible ; le portail ne remplace jamais cette
absence par une lecture directe de base de données.

## Rôle Administration

`ADMINISTRATION` représentait initialement l’ensemble des fonctions
administratives internes :

```text
RH et employés
contrats, présence, congés et planning
logistique et stock administratif
gardiennage et consultation des visiteurs autorisée
rapports RH
```

La gestion des utilisateurs, rôles et permissions n’est plus accordée par
défaut à `ADMINISTRATION`. Elle appartient au `SUPER_ADMIN`; une délégation
exceptionnelle reste possible par permission individuelle auditée.

---

# ADR-026 — Rôles Logistique et Gardien indépendants

**Status:** SUPERSEDED pour Gardien et Maintenance par ADR-033

Cette décision affine le CDC officiel et remplace la partie d’ADR-025 qui
rattachait encore logistique et gardiennage au rôle `ADMINISTRATION`. Trois
responsabilités autonomes sont désormais définies :

```text
ADMINISTRATION  ressources humaines, employés, contrats, présence, congés, planning
LOGISTICS       inventaire, affectation, localisation, état et maintenance des équipements
SUPPORT/GUARD   enregistrement et suivi des entrées/sorties, observations et incidents
```

Chaque rôle possède ses permissions propres. Il ne voit pas les menus des deux
autres responsabilités, sauf délégation individuelle explicite et auditée. Le
`SUPER_ADMIN` conserve la vue de l’ensemble.

Le stock de médicaments, les lots, péremptions, entrées/sorties et inventaires
pharmaceutiques restent exclusivement dans le menu `PHARMACY`. La Logistique
ne gère que les équipements durables et le stock administratif ; elle ne gère
ni médicament, ni délivrance, ni paiement.

Le profil `SUPPORT/GUARD` utilise le registre des entrées et sorties avec des
permissions affectées au compte. Il peut enregistrer
une entrée, ajouter une observation, consulter les personnes présentes et
enregistrer leur sortie. Ce registre ne crée ni patient, ni épisode clinique,
ni facture, ni paiement.

---

# ADR-027 — Séparation stricte des identités centrales et opérationnelles

**Status:** ACCEPTED (2026-08-20 — exigence explicite de l’équipe)

Un compte `SUPER_ADMIN` est une identité exclusivement centrale. Il peut se
connecter uniquement au portail :

```text
admin.rivo.mg
```

Il ne peut jamais ouvrir une session directe sur Mampikony, Ambondromamy ou
Boriziny, même si une permission individuelle lui a été ajoutée. Son accès
global aux informations et commandes des sites passe uniquement par leurs API
REST authentifiées, autorisées et auditées.

Si la même personne doit exercer une fonction opérationnelle dans un site, un
compte local distinct doit être créé dans la base de ce site avec le rôle métier
cohérent :

```text
RECEPTION
ADMINISTRATION
LOGISTICS
SUPPORT
MAINTENANCE
MEDICINE
NURSE
SURGERY
PHARMACY
LABORATORY
```

Le compte local possède ses propres identifiants, son propre rôle et uniquement
les permissions de ce rôle, complétées si nécessaire par une exception
individuelle explicite et auditée. Il ne reçoit jamais le rôle `SUPER_ADMIN`.

Réciproquement, un compte opérationnel ne peut pas ouvrir une session sur
`admin.rivo.mg`, même si la permission `super_admin.portal.view` lui est ajoutée
par erreur. Le portail exige simultanément le rôle `SUPER_ADMIN` et cette
permission. Les sessions déjà ouvertes qui deviennent incohérentes avec le type
de déploiement sont révoquées à la requête suivante.

Les bases des sites peuvent conserver la définition technique du rôle
`SUPER_ADMIN` pour partager le même schéma, mais ce rôle n’y reçoit aucune
permission par défaut, ne peut pas y être attribué et ne peut pas s’y connecter.

---

# ADR-028 — Prestations et choix de règlement à l’arrivée

**Status:** ACCEPTED (2026-08-20 — exigence explicite de l’équipe)

Dans le parcours Réception Patient, la réceptionniste demande la raison de la
venue et sélectionne une ou plusieurs prestations disponibles dans le
référentiel tarifé du site, par exemple consultation, ECG ou échographie.

La sélection à la Réception est limitée aux éléments :

```text
type = SERVICE
billable = true
non archivés
avec un tarif actif
```

Elle ne permet pas de vendre directement un médicament, un consommable ou un
équipement. La Réception voit le tarif et le total prévisionnel, mais ne saisit
jamais le prix. À la confirmation, Laravel relit et verrouille le tarif actif,
puis conserve son instantané dans la prestation et la facture. Le montant envoyé
par le navigateur n’est jamais une source de vérité.

Avant la confirmation, deux choix sont proposés :

```text
PAYER MAINTENANT
PAYER PLUS TARD
```

`PAYER MAINTENANT` exige une caisse ouverte, `payments.create` et un mode de
paiement actif. La confirmation crée et valide la facture, encaisse exactement
son solde, enregistre le mouvement de caisse et génère le reçu de paiement dans
une transaction unique.

`PAYER PLUS TARD` crée et valide la facture avec son solde restant dû. Il ne crée
ni paiement, ni mouvement de caisse, ni reçu. La facture imprimable sert de
document remis au patient jusqu’à l’encaissement. Une facture impayée ne devient
une créance formelle qu’au moment du futur circuit de sortie/dette validée prévu
par le CDC.

Un reçu atteste exclusivement un paiement réellement encaissé. Le système ne
doit jamais produire un « reçu impayé ».

Lorsqu’aucune prestation n’est encore connue, la Réception peut indiquer
« prestation à définir après orientation ». Une urgence peut toujours poursuivre
son admission sans prestation ni paiement préalable, conformément à ADR-021.

Permissions :

```text
episodes.create
billing.create
billing.validate
billing.print
payments.create       seulement pour PAYER MAINTENANT
receipts.view/print   consultation et impression du reçu réel
```

---

# ADR-029 — Passage obligatoire par les Soins avant la Médecine

**Status:** SUPERSEDED by ADR-030 (2026-08-22)

Chaque arrivée crée un épisode. Le dossier patient permanent et les files
opérationnelles des services restent deux choses distinctes : un professionnel
autorisé peut consulter un dossier sans que le patient apparaisse pour autant
dans sa file de prise en charge.

Pour un passage normal, le parcours initial est strictement :

```text
Réception -> En attente aux Soins -> Pris en charge aux Soins
          -> Soins terminés / orienté vers Médecine
          -> En attente en Médecine -> Pris en charge par un médecin
```

La création du passage place automatiquement le patient dans la file Soins.
Elle ne le place pas dans la file Médecine. Seule l’action explicite
`Terminer et orienter vers Médecine`, exécutée par un compte autorisé aux
Soins, crée cette seconde orientation. L’interface ne constitue pas la
protection : les transitions et la destination sont validées par Laravel,
verrouillées en base et auditées.

Une urgence constitue l’exception : dès l’admission, elle est visible
simultanément dans les files Soins et Médecine. La famille peut compléter le
dossier administratif ensuite. Aucune prestation, facture ou paiement ne peut
bloquer cette visibilité ni la prise en charge clinique.

Les désignations déjà connues à l’arrivée restent sélectionnées depuis le
référentiel tarifé et visibles dans les files. Si le besoin est inconnu, la
Réception ne crée ni désignation fictive ni montant : il sera précisé après
l’évaluation clinique. Dans les deux cas, un passage normal reste soumis au
passage initial par les Soins.

Les orientations sont historiques : elles ne sont pas supprimées. Une seule
orientation active par épisode et service cible est permise. Les statuts
opérationnels sont :

```text
PENDING -> IN_PROGRESS -> COMPLETED
```

Permissions utilisées :

```text
care.view
care.update
care.complete
consultations.view
consultations.create
```

---

# ADR-030 — Typologie patient et parcours piloté par les désignations

**Status:** ACCEPTED (2026-08-22 — réunion client du 22/08/2026) ; le parcours d'une
désignation **n'ouvre plus aucune file** depuis l'**ADR-177** (2026-09-23) : il ne propose
plus que la suite des Soins (ADR-166), et la visibilité d'un passage ne dépend plus du besoin.

Cette décision remplace la règle de parcours uniforme décrite par ADR-029.
Elle ne modifie pas l'exception d'urgence : une urgence reste immédiatement
visible aux Soins et en Médecine, sans dépendre de la complétude administrative
ou financière du dossier.

Le dossier patient permanent porte désormais une classification administrative :

```text
STANDARD
MUTUAL
STAFF
```

`STANDARD` contient les informations d'identité, de naissance ou d'âge déclaré,
la situation maritale, le nombre d'enfants, la profession et les coordonnées.
`MUTUAL` ajoute une adhésion à une mutuelle, l'entreprise, la qualité de
bénéficiaire, le matricule et au plus cinq pièces privées. `STAFF` est relié à
un véritable dossier Employé local, distinct du compte de connexion `users`.
La Réception ne reçoit qu'un droit de recherche limité sur ces employés.

La saisie est séparée en blocs courts : type, identité, contact, couverture et
confirmation. Après l'enregistrement administratif, un épisode distinct est
créé et la Réception poursuit sur la sélection des prestations de cet épisode.

Les nouveaux identifiants humains sont :

```text
patient : SITE-YY-NNNN       exemple M-26-0001
passage : PATIENT-NN         exemple M-26-0001-01
```

La séquence patient est annuelle et verrouillée. La séquence passage est propre
au patient et verrouillée. Les UUID restent les identifiants d'URL et d'échange.
Les anciens numéros déjà émis ne sont jamais renumérotés.

Chaque désignation sélectionnable à la Réception possède un parcours serveur,
conservé en snapshot sur la demande clinique de l'épisode :

```text
MEDICINE_DIRECT      ECG, échographie
CARE_THEN_MEDICINE   consultation générale ou évaluation clinique
CARE_ONLY            injection, pansement, prise de tension
```

Une consultation spécialisée dont le besoin est déjà identifié appartient
à `MEDICINE_DIRECT`, comme l'ECG et l'échographie. La consultation générale
reste `CARE_THEN_MEDICINE` afin que les Soins effectuent l'évaluation et les
constantes utiles avant la consultation.

Une prestation connue `MEDICINE_DIRECT` ne passe pas artificiellement par les
Soins. Une prestation `CARE_ONLY` peut se terminer aux Soins. Une prestation
`CARE_THEN_MEDICINE` ne devient visible en Médecine qu'après la fin des Soins.
Un besoin encore inconnu n'est ni une fausse désignation ni un montant : il est
orienté vers les Soins, qui décide explicitement de la suite. Pour plusieurs
prestations normales, toute étape Soins nécessaire est réalisée avant la
transmission vers Médecine afin d'éviter deux files concurrentes pour le même
patient. Le serveur recharge toujours le parcours depuis le référentiel ; Vue
ne peut pas imposer une destination.

Les demandes de prestations et leur parcours clinique sont indépendants de la
facture. Un échec de caisse ou de facturation ne doit jamais effacer une demande
clinique ni une orientation déjà créée.

Pour un patient `STAFF` dont le lien avec un employé actif et son éligibilité RH
sont valides, les prestations de la clinique sont prises en charge à 100 %, hors
opérations au bloc. Les opérations au bloc consomment d'abord le crédit accordé
au personnel ; lorsque le crédit disponible est insuffisant ou épuisé, le solde
devient la part à payer du patient. Le montant de 500 000 MGA cité en réunion est
un exemple : il doit rester configurable par RH / Finance et ne doit jamais être
codé en dur.

Cette prise en charge ne remplace pas le tarif de la prestation par zéro. Le
système conserve le montant brut, puis le répartit entre « prise en charge
personnel » et « part patient ». L'avantage et le crédit bloc sont portés par un
registre immuable RH / Finance (allocation, consommation, annulation/réversion).
La Réception consulte le résultat mais ne modifie jamais ce crédit ; seule la
Réception / Caisse encaisse une éventuelle part patient. Aucun faux paiement,
reçu ou remise arbitraire ne peut simuler l'avantage.

La période du crédit, ses dates d'effet et la liste exacte des actes considérés
comme « bloc » restent des paramètres RH / Finance à valider avant l'activation
de la facturation automatique du personnel. Tant que ces paramètres ne sont pas
configurés, les prestations du personnel sont enregistrées et leur facturation
est mise en attente, sans bloquer le parcours clinique.

---

# ADR-031 — Barèmes séparés Sans mutuelle et Mutuelle

**Status:** ACCEPTED (2026-08-22 — exigence explicite du client)

Le catalogue de chaque site possède deux catégories de tarif brut indépendantes :

```text
STANDARD   affiché comme « Sans mutuelle »
MUTUAL     affiché comme « Mutuelle »
```

Un élément peut posséder simultanément un tarif actif dans chaque catégorie.
Chaque changement ferme uniquement la version active de sa catégorie et crée
une nouvelle version datée, auditée et non destructive. Un tarif Mutuelle ne
remplace jamais silencieusement un tarif Standard, et inversement.

La catégorie est résolue exclusivement par Laravel :

```text
patient STANDARD -> tarif STANDARD
patient MUTUAL   -> tarif MUTUAL, avec couverture mutuelle active
patient STAFF    -> tarif brut STANDARD, puis couverture RH / Finance séparée
```

Vue transmet toujours uniquement l'UUID de la désignation et la quantité. La
demande clinique du passage conserve l'UUID du tarif, sa catégorie, le montant
et la devise. La prestation facturable reprend ce snapshot exact ; un changement
tarifaire ultérieur ne modifie ni le passage, ni la facture, ni l'historique.

Si le tarif de la catégorie attendue ou la couverture mutuelle active manque,
le système ne reprend pas l'autre grille. La demande et l'orientation clinique
restent conservées, notamment en urgence, tandis que la facturation est mise en
attente avec un message explicite. Cette règle précise l'exigence « avec un tarif
actif » de l'ADR-028 : l'absence de prix peut empêcher la facture, jamais le
parcours clinique.

Le document `Prestations et Tarifs_Clinique Saint Georges_AMB_Juin_2023_avec
comparaison.pdf` confirme deux colonnes distinctes et de nombreux écarts. Il est
daté du 25/06/2023, propre à Ambondromamy et contient des doublons, montants
ambigus et tarifs variables. Il n'est donc pas importé ni activé automatiquement
en 2026. Toute reprise exige une validation client par site et par désignation.

La version présente applique un barème Mutuelle commun au site. Une future
convention différente par organisme nécessitera une décision explicite et un
profil tarifaire lié à la mutuelle ; aucune règle de répartition entre part
mutuelle et part patient n'est inventée ici.

Le portail Super Administration présente cet espace par site, mais toute lecture
ou commande distante doit passer par l'API sécurisée du site conformément aux
ADR-003, ADR-004 et ADR-027. Il ne reçoit jamais un accès SQL direct aux bases.

---

# ADR-032 — Fiche de soins infirmiers par passage

**Status:** ACCEPTED (2026-08-23 — remarque explicite du client)

Le rôle `NURSE` utilise une fiche de soins rattachée à l'épisode, jamais au
dossier administratif permanent. Elle regroupe les constantes et observations
du passage : tension artérielle gauche et droite, température, diabète connu,
groupe sanguin, taille, poids, IMC calculé par Laravel, allergie signalée,
tabagisme et, lorsque le parcours continue vers Médecine, éléments de transmission.

L'IMC est accompagné d'un repère de dépistage calculé à partir d'une règle
Laravel centralisée. Pour les adultes de 20 ans ou plus, les seuils OMS retenus
sont : insuffisance pondérale `< 18,5`, corpulence normale `18,5–24,9`,
surpoids `25–29,9`, obésité classe I `30–34,9`, classe II `35–39,9` et classe
III `>= 40`. Avant 20 ans, aucun seuil adulte n'est appliqué : l'interface
demande une interprétation selon l'âge et le sexe avec les courbes de croissance
adaptées. L'alerte reste une aide à l'évaluation, jamais un diagnostic ni une
décision médicale automatique.

Les allergies sont des données permanentes et historisées du dossier patient
(`patient_allergies`), pas un simple texte isolé dans la fiche Soins. Un compte
autorisé peut confirmer une ou plusieurs allergies déjà connues pendant le
passage. Avec `patients.medical_history.manage`, il peut aussi ajouter une
allergie absente en précisant substance, réaction et gravité. La fiche conserve
un snapshot de la sélection du passage afin qu'une correction ultérieure du
dossier permanent ne réécrive pas l'historique clinique.

Un nouveau patient n'ayant encore aucun antécédent doit néanmoins pouvoir être
renseigné sans saisie libre systématique. Un référentiel local distinct
(`allergen_references`) propose donc des allergènes courants classés par famille.
Sélectionner une entrée crée l'allergie correspondante dans le dossier permanent
du patient puis l'inclut dans le snapshot du passage. L'ajout manuel reste
disponible lorsqu'aucune entrée ne convient, mais il n'enrichit jamais
automatiquement le référentiel partagé. Ce référentiel médical n'est pas le
catalogue des prestations et ne porte aucun tarif.

Les actes effectivement réalisés sont historisés de façon append-only avec
l'acte du référentiel, son libellé instantané, la quantité, l'observation,
l'utilisateur et l'heure. « Autres » exige une description. Une correction ne
supprime jamais un acte antérieur ; elle produit une nouvelle trace.

La remarque client fournit les actes Soins mais aucun tarif. Ils sont donc
ajoutés au référentiel sans montant inventé et restent non sélectionnables à la
Réception tant que le Super Admin n'a pas configuré leur tarif et leur parcours.
Les quatre actes de validation déjà tarifés conservent leur prix existant.

La présence de « Diagnostic » et des dates d'hospitalisation sur la fiche papier
ne transfère pas la décision médicale au rôle `NURSE`. La précision client du
23/08/2026 remplace ici l'interprétation initiale : la fiche Soins ne saisit ni
l'entrée ni la sortie d'hospitalisation. L'entrée proviendra automatiquement du
workflow d'admission après décision médicale ; la sortie proviendra de l'action
de sortie médicale. Tant que le module Hospitalisation n'existe pas, ces champs
ne sont pas affichés. Le diagnostic communiqué et les observations de
transmission ne sont affichés que pour `CARE_THEN_MEDICINE`, un besoin inconnu
encore orientable, ou une urgence. Un parcours `CARE_ONLY` se termine aux Soins
et n'affiche donc aucune section « Hospitalisation et transmission ».

Les constantes courantes ne sont pas obligatoires pour chaque acte infirmier.
Pour un parcours `CARE_ONLY` connu, tel qu'un pansement ou une injection isolée,
groupe sanguin, taille, poids et IMC sont facultatifs et repliés par défaut ;
l'infirmier peut les ouvrir s'ils ont réellement été relevés ou si le contexte
clinique le justifie. Ils restent visibles et recommandés en urgence, pour un
besoin encore indéterminé ou lorsque le parcours continue vers Médecine. Cette
recommandation d'interface ne remplace pas l'appréciation clinique et le backend
n'impose pas artificiellement ces quatre valeurs pour enregistrer un acte.

La précision client du 23/08/2026 ajoute au relevé facultatif la tension des
deux bras, la température et le statut « diabète connu ». La tension est
enregistrée en quatre valeurs numériques structurées (systolique/diastolique,
gauche/droite) avec l'unité mmHg ; chaque paire doit être complète et la
systolique supérieure à la diastolique. La température est enregistrée en °C.
Le diabète possède trois états distincts : non renseigné, non et oui. Ces
données restent attachées à l'épisode et protégées par `vitals.*` ; elles ne
deviennent pas obligatoires pour un pansement ou une injection isolée.

Le snapshot d'allergies d'un passage reste historique. Si une allergie qui y
figure n'existe plus parmi les allergies actives du dossier patient, elle est
affichée comme historique, n'est plus renvoyée comme sélection active et ne peut
ni bloquer une sauvegarde ultérieure ni être effacée silencieusement du snapshot.

Le 23/08/2026, le client valide aussi le mode compact de cette fiche. Pour un
acte autonome, toute la section « Constantes et observations » est repliée et
reste facultative. Cela ne signifie pas « aucune allergie » : l'absence de donnée
et une réponse négative demeurent deux états distincts. Les actes configurés à
risque, notamment les injections IM/IV et la perfusion, déclenchent donc une
vérification de sécurité ciblée du statut allergique même si la section complète
reste fermée. La confirmation, l'acte, le soignant et l'heure sont historisés.

Ces exigences appartiennent au référentiel Super Admin par prestation
(`care_requires_allergy_check`, `care_recommends_vitals`) et sont copiées dans
la demande du passage lors de sa planification. Un changement ultérieur du
catalogue ne réécrit ainsi pas rétroactivement le parcours déjà ouvert.

Un parcours `CARE_ONLY` ne peut être terminé sans au moins un acte effectivement
enregistré. Pour un besoin initialement indéterminé, l'équipe choisit soit une
orientation vers Médecine, soit des actes réalisés, soit une clôture sans acte
avec un motif explicite. L'enregistrement des nouveaux actes et la fin de prise
en charge s'effectuent dans une transaction unique afin d'éviter une fiche
enregistrée mais un parcours non terminé, ou l'inverse.

Permissions :

```text
care.view / create / update / complete
vitals.view / create / update
medical_orders.view
```

Le rôle `NURSE` ne reçoit aucune permission `payments.*`, `cash.*` ou
`receipts.*`. Les soins peuvent alimenter ultérieurement les éléments
facturables, mais tout encaissement reste exclusivement à Réception / Caisse.

---

# ADR-033 — Profils professionnels et permissions propres au compte

**Status:** ACCEPTED (2026-08-23 — exigence explicite du client) ; le rôle `SURGERY` reçoit ses
profils `SURGEON`, `OR_NURSE` et `SURGICAL_PARAMEDICAL` par l'**ADR-168** (2026-09-22).

Cette décision amende ADR-006 et ADR-026. Le CDC officiel rattache encore
l'anesthésiste à Chirurgie et le gardien ainsi que la maintenance à
Administration. La demande explicite du propriétaire du 23/08/2026 a priorité :
les rôles opérationnels sont séparés, tandis que les droits concrets restent
dynamiques et attribués par compte.

La classification validée est :

```text
NURSE
  REGISTERED_NURSE   Infirmier / Infirmière
  MIDWIFE            Sage-femme
  ANESTHETIST        Anesthésiste

SUPPORT
  GUARD              Gardien / Gardienne
  CLEANER            Agent d'entretien / Femme de ménage

MAINTENANCE
  IT_TECHNICIAN      Technicien informatique
```

Le rôle représente uniquement le domaine et son socle strictement commun. Le
profil professionnel décrit la fonction principale ; il n'est jamais consulté
par le moteur d'autorisation et ne confère donc aucun droit automatiquement.
Ses permissions recommandées servent uniquement de modèle lors de l'affectation
d'un compte. Le Super Admin ou un gestionnaire explicitement autorisé les copie
en `ALLOW` individuels dans `user_permissions`, puis peut les adapter. Une
permission `DENY` individuelle reste prioritaire sur un `ALLOW` individuel puis
sur le socle du rôle.

Deux comptes ayant le même rôle et le même profil peuvent ainsi posséder des
droits différents sans modifier tous leurs collègues. Modifier ultérieurement
les recommandations d'un profil ne change jamais silencieusement les comptes
existants. Toute affectation de rôle, de profil et de permission individuelle
est auditée.

Chaque ligne de `user_permissions` porte sa provenance : `MANUAL` pour une
décision individuelle et `PROFILE` avec `source_profile_id` pour une
recommandation explicitement appliquée. Les lignes antérieures à cette
traçabilité sont conservées et classées `MANUAL`, car leur origine ne peut pas
être déduite sans risque. Lors d'un changement de profil, toutes les lignes
`PROFILE` de l'ancien profil sont retirées ; les lignes `MANUAL` restent en
place. Les recommandations du nouveau profil ne sont ajoutées qu'après l'action
explicite « Appliquer les permissions recommandées ». Une décision `MANUAL`, y
compris un `DENY`, n'est jamais écrasée par cette application. Cette opération
est transactionnelle et auditée sous `user.profile.permissions.sync`.

La modification directe de `user_permissions` dans phpMyAdmin ou par SQL est
un procédé non supporté : elle contourne validation, provenance, autorisation
et audit. Les adaptations doivent passer par Administration > Utilisateurs ou
par l'API Super Admin autorisée.

Le socle `NURSE` conserve les Soins, constantes, lecture des ordres et accès
clinique minimal au patient. `ANESTHETIST` recommande uniquement les
permissions `anesthesia.*`, mais celles-ci doivent être attribuées au compte
concerné ; elles ne sont plus héritées par toutes les infirmières et
sages-femmes. Le socle `SURGERY` n'accorde aucune permission `anesthesia.*` :
le chirurgien qui doit consulter l'évaluation nécessaire au bloc reçoit
explicitement `anesthesia.view`, sans droit de créer, corriger ou valider la
consultation pré-anesthésique et l'examen paraclinique. Ces actions relèvent des
permissions individuelles `anesthesia.create/update/validate`. Aucun droit
Maternité n'est inventé tant que son module et son catalogue ne sont pas
définis.

`SUPPORT` et `MAINTENANCE` n'ont aucun droit métier global. Le profil `GUARD`
recommande le registre de gardiennage et des visiteurs. `CLEANER` n'ajoute aucun
accès logiciel par défaut. `IT_TECHNICIAN` recommande uniquement la consultation
logistique et le suivi de maintenance des équipements ; les droits plus sensibles
restent une décision individuelle.

L'ancien rôle `GUARD` est obsolète. Lors de la migration, chaque compte concerné
est déplacé vers `SUPPORT/GUARD` et reçoit une copie individuelle de ses anciens
droits afin d'éviter toute perte d'accès. L'ancien rôle est ensuite retiré. Les
comptes `NURSE` existants sans profil restent actifs mais doivent afficher
« Profil métier à définir » jusqu'à leur qualification manuelle ; aucune
qualification infirmier, sage-femme ou anesthésiste n'est déduite arbitrairement.

---

# ADR-034 — Personne à contacter propre au passage

**Status:** ACCEPTED (2026-08-23 — exigence explicite de l'équipe)

Cette décision remplace, sur ce point précis, le CDC Fonctionnel client qui
plaçait la « personne à contacter » dans le dossier administratif permanent
du patient. L'équipe a constaté que la personne effectivement joignable peut
changer d'un passage à l'autre ; un champ unique et permanent ne reflète donc
pas la réalité et peut induire en erreur en cas d'urgence.

La personne à contacter est désormais portée par l'épisode (`episodes.
emergency_contact_name/_phone/_relationship/_email`), jamais par le dossier
patient permanent. Elle est demandée à chaque arrivée — nouveau patient,
patient déjà connu ou personnel de la clinique — et peut différer d'un
passage au suivant sans jamais réécrire un historique. Elle reste facultative
et n'est jamais requise pour démarrer un passage, y compris en urgence.

La migration technique conserve, pour chaque passage encore `OPEN` au moment
du changement, le dernier contact permanent connu comme valeur de départ. Les
passages déjà clos ne sont pas complétés rétroactivement : associer après coup
un contact à un passage terminé reviendrait à inventer une donnée qui n'a
jamais été confirmée pour ce passage précis.

La page Dossier patient (Aperçu) et la page Édition du dossier permanent ne
portent donc plus ce champ. Le dossier patient garde en revanche ses propres
repères médicaux permanents (allergies, antécédents), historisés et enrichis
au fil des passages via la fiche de soins (voir ADR-032) — une distinction
que cette même mise à jour a rendue plus explicite dans l'interface : dossier
permanent d'un côté (onglet Aperçu), fiche de chaque passage de l'autre
(onglet Passages), sans mélanger les deux registres sous un même intitulé
« administratif ».

---

# ADR-035 — Consultation Médecine et sortie médicale

**Status:** ACCEPTED (2026-08-23 — fiches client et exigence explicite du propriétaire)

La file Médecine reste alimentée exclusivement par le parcours clinique de
l'ADR-030 : `MEDICINE_DIRECT`, transmission terminée par les Soins pour
`CARE_THEN_MEDICINE`, orientation explicite d'un besoin inconnu, ou admission
`EMERGENCY`. Consulter le dossier permanent d'un patient ne l'ajoute jamais à
la file Médecine.

La prise en charge d'une orientation Médecine ouvre un dossier de consultation
rattaché à cette orientation et au passage. Le dossier présente sans les
recopier comme de nouvelles données : l'identité, les allergies permanentes,
les prestations demandées, les constantes, actes et transmissions enregistrés
aux Soins. Une orientation ne possède qu'une consultation active ; les
réorientations historiques d'un même passage peuvent produire des consultations
distinctes.

Le médecin peut enregistrer progressivement :

```text
motif de consultation
examen clinique
hypothèses diagnostiques append-only
diagnostic final append-only
prescription et lignes de traitement
décision médicale et observations
```

Les diagnostics déjà consignés ne sont jamais supprimés silencieusement. Une
correction produit une nouvelle entrée identifiée par son auteur et son heure.
Lorsqu'une entrée a été enregistrée par erreur, seul le médecin qui l'a saisie,
et qui dispose de `diagnoses.update`, peut l'annuler. Aucun motif libre n'est
demandé : le système enregistre automatiquement l'auteur et la date de
l'annulation. Cette annulation est une trace séparée, immuable et auditée : la
ligne diagnostique originale reste visible avec son auteur et sa date, et n'est
ni modifiée ni supprimée.
Une prescription active peut être annulée avec un motif audité ; elle n'est pas
supprimée physiquement.

Les examens de laboratoire, ordres de soins, demandes de chirurgie,
hospitalisation et transfert restent des workflows spécialisés. Une simple
valeur dans l'écran Consultation ne doit jamais simuler la création de l'ordre
correspondant. Ils seront reliés à la consultation lorsque leurs modules seront
implémentés.

La sortie médicale est une décision médicale distincte de la sortie
administrative et du règlement. Ses types initiaux, conformes au CDC et aux
fiches remises par le client, sont :

```text
NORMAL
TRANSFER
AT_PATIENT_REQUEST
MEDICAL_DECISION_REFUSAL
DECEASED
```

Elle conserve la date et l'heure, le diagnostic final, l'état du patient, les
prescriptions de sortie, les recommandations, le rendez-vous éventuel et les
observations. Un décès conserve en plus l'heure, le lieu et les causes utiles au
futur certificat de constatation. La sortie complète l'orientation Médecine et
met à jour uniquement le statut médical du passage :

```text
NORMAL / AT_PATIENT_REQUEST / MEDICAL_DECISION_REFUSAL -> MEDICALLY_DISCHARGED
TRANSFER                                                -> TRANSFERRED
DECEASED                                                -> DECEASED
```

Elle ne clôt jamais automatiquement l'épisode global et ne dépend ni d'une
facture, ni d'un paiement, ni d'une caisse ouverte. La sortie administrative
reste la responsabilité de Réception / Caisse.

Les fiches papier partagées le 23/08/2026 couvrent aussi le journal de
traitements, le ticket de contrôle Caisse/Sécurité, le certificat médical et le
certificat de décès. Ces impressions utiliseront les données cliniques validées,
mais constituent des documents séparés : elles ne doivent pas modifier la
consultation ni servir de faux reçu ou de validation administrative.

Permissions initiales :

```text
medical_record.view
consultations.view / create / update
diagnoses.view / create / update
prescriptions.view / create / update / cancel
medical_discharge.create
```

Médecine ne reçoit aucune permission `payments.*`, `cash.*` ou `receipts.*`.

---

# ADR-036 — Prescription adossée au stock Pharmacie par lots

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

La saisie libre d'un médicament dans une nouvelle ordonnance est remplacée par
la sélection d'une fiche active du référentiel Pharmacie. Un médicament étend
un `catalog_item` de type `MEDICINE` dans une table spécialisée qui conserve
notamment la DCI, la forme pharmaceutique et le dosage. Les formes initiales
reprennent le classement transmis par le client : comprimé, injectable,
liquide, sachet, sirop, pommade/crème, suppositoire/ovule, ampoule/collyre/
goutte, parapharmacie/consommable et autre. Cette spécialisation ne fusionne
pas les médicaments, les prestations et les équipements (ADR-024).

Le stock local est porté par des lots avec numéro de lot, quantité physique et
date de péremption. Un lot périmé n'est jamais compté comme disponible. L'écran
Médecine présente seulement la disponibilité agrégée et la prochaine
péremption utile ; les détails de mouvements et toute mutation restent sous la
responsabilité de Pharmacie.

À la validation d'une ordonnance, Laravel refait le contrôle dans une
transaction et verrouille les lots. La quantité est réservée en FEFO
(`First Expired, First Out`) : le lot utilisable qui expire le plus tôt est
réservé en premier. Une réservation évite que deux médecins prescrivent la
même quantité simultanément. Si une seule ligne est insuffisante, toute la
transaction est annulée : aucune ordonnance, ligne ou réservation partielle ne
reste enregistrée.

Une prescription ne réalise pas encore la sortie physique. Seule la délivrance
validée par Pharmacie diminuera `quantity_on_hand` et créera le mouvement de
stock correspondant. L'annulation auditée d'une prescription libère ses
réservations. Un mouvement de stock validé reste immuable ; une correction
passe par un nouveau mouvement inverse ou d'ajustement, jamais par une
modification ou suppression de l'historique.

Permissions minimales :

```text
MEDICINE : medicines.view + stock.availability.view + prescriptions.create
PHARMACY : medicines.view + stock.availability.view + stock.* + pharmacy.dispense
```

Le rôle Médecine n'obtient aucun droit d'entrée, sortie, ajustement,
inventaire ou transfert. Cette évolution ne crée ni paiement ni caisse dans
Médecine ou Pharmacie ; l'encaissement reste exclusivement à Réception / Caisse.

---

# ADR-037 — Médicament manuel hors référentiel, en attente de validation

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

ADR-036 remplace la saisie libre par la sélection du référentiel Pharmacie
pour le cas normal. Elle ne doit cependant jamais bloquer une ordonnance
lorsque le médicament nécessaire est simplement absent du référentiel : le
médecin peut alors ajouter une ligne manuelle (nom libre, posologie,
fréquence, durée, instructions), en plus des lignes normales issues du
référentiel dans la même ordonnance.

Une ligne manuelle :

```text
ne référence aucun catalog_item ni medicine (medicine_id reste null) ;
ne réserve, ne verrouille et ne consomme aucun lot de stock Pharmacie ;
n'affiche et ne permet de saisir aucun prix, comme toute ligne d'ordonnance ;
est marquée en attente de validation dès sa création.
```

Cette attente n'est pas une nouvelle permission. Elle réutilise
`catalog.items.create`, déjà réservée par défaut au Super Admin par
l'ADR-024 et attribuable en exception individuelle auditée (ADR-022) à un
compte opérationnel réel — par exemple Pharmacie ou Administration. Aucune
permission dédiée n'a été créée pour ce seul usage.

Traiter la demande enregistre uniquement l'auteur, la date et une note libre
sur la ligne d'ordonnance ; cela ne crée pas automatiquement un `medicine`,
un `catalog_item` ni un lot de stock. Faire entrer réellement le médicament
au référentiel et au stock reste un acte distinct, déjà couvert par les
écrans et permissions existants (`catalog.items.create`, puis les mouvements
Pharmacie de l'ADR-036) — cette ADR ne les automatise pas.

La ligne manuelle reste modifiable (quantité, nom, posologie) tant que
l'ordonnance est active, sans jamais déclencher de contrôle de stock. Son
annulation suit la même trace d'audit que toute ligne d'ordonnance.

Permissions inchangées :

```text
MEDICINE : aucun ajout — prescriptions.create couvre déjà la ligne manuelle
Validation de la demande : catalog.items.create (ADR-024, ADR-022)
```

---

# ADR-038 — Une seule tension artérielle, ajout de la FC et de la SpO2

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire, amende ADR-032)

ADR-032 exigeait la tension des deux bras (quatre valeurs : systolique et
diastolique, gauche et droite). Cette exigence est remplacée le jour même par
une tension artérielle unique (`blood_pressure_systolic`,
`blood_pressure_diastolic`), à laquelle s'ajoutent deux constantes absentes
d'ADR-032 :

```text
FC   fréquence cardiaque, en battements par minute (heart_rate)
SpO2 saturation en oxygène, en pourcentage (spo2)
```

Ces trois valeurs restent facultatives comme le reste du bloc « Constantes et
observations » d'ADR-032 : replié par défaut pour un acte autonome, jamais
rendu obligatoire pour enregistrer un acte infirmier. Les bornes de validation
suivent la même logique clinique que les champs existants : FC entre 20 et 250
btt/mn, SpO2 entre 0 et 100 %.

La colonne `blood_group` et les autres constantes d'ADR-032 (température,
diabète connu, taille, poids, IMC, allergies) ne changent pas.

---

# ADR-039 — Alerte de fréquence cardiaque basse

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

La fiche Soins affiche immédiatement une alerte de dépistage lorsque la
fréquence cardiaque mesurée est inférieure à 60 bpm. Cette alerte ne bloque
ni l'enregistrement ni la fin des Soins et ne constitue jamais un diagnostic.

Pour un adulte, une valeur de 50 à 59 bpm produit un avertissement orange ;
une valeur inférieure à 50 bpm produit une alerte rouge demandant un nouveau
contrôle et la recherche de signes de mauvaise tolérance. Pour un patient de
moins de 18 ans, toute valeur inférieure à 60 bpm produit une alerte rouge et
rappelle que l'interprétation dépend de l'âge précis et du contexte clinique.
Si l'âge est inconnu, l'alerte reste visible mais demande de renseigner l'âge.

La classification est calculée côté Laravel et le frontend reçoit le même
référentiel pour l'aperçu instantané pendant la saisie. Les seuils s'appuient
sur la plage adulte au repos de l'American Heart Association (60–100 bpm), son
algorithme 2025 de bradycardie adulte (bradyarythmie typiquement sous 50 bpm) et
son algorithme pédiatrique, qui requiert une évaluation urgente sous 60 bpm en
présence d'une mauvaise tolérance cardiopulmonaire.

Références :

```text
https://www.heart.org/en/health-topics/high-blood-pressure/the-facts-about-high-blood-pressure/all-about-heart-rate-pulse
https://www.heart.org/-/media/CPR-Files/CPR-Guidelines-Files/2025-Accessible/Algorithm-ACLS-Bradycardia-LngDscrp-250725-Ed.pdf
https://cpr.heart.org/-/media/CPR-Files/CPR-Guidelines-Files/2025-Accessible/Algorithm-PALS-Bradycardia-LngDscrp-250729-Ed.pdf
```

---

# ADR-040 — Alertes compactes SpO₂ et température

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

Les alertes de constantes ne doivent pas occuper un grand bloc transversal dans
la fiche Soins. La FC, la SpO₂ et la température affichent chacune une seule
ligne compacte directement sous leur champ, avec une bordure de champ assortie.
Le message détaillé reste disponible comme aide contextuelle. L'alerte reste
non bloquante et ne constitue pas un diagnostic.

Pour la SpO₂ :

```text
95–100 %  aucune alerte générale
93–94 %   avertissement orange
≤ 92 %    alerte rouge
```

Ces seuils sont des repères généraux : une maladie respiratoire chronique ou
l'altitude peuvent modifier la valeur habituelle du patient. Toute valeur
signalée doit être recontrôlée et interprétée avec les symptômes.

Pour la température :

```text
< 35 °C       alerte rouge — hypothermie possible
35–35,9 °C   avertissement orange — température basse
36–37,9 °C   aucune alerte générale
38–39,9 °C   avertissement orange — fièvre
≥ 40 °C      alerte rouge — température très élevée
```

La classification est centralisée dans Laravel et le même référentiel est
fourni à Vue pour l'aperçu immédiat pendant la saisie.

Références :

```text
https://medlineplus.gov/lab-tests/pulse-oximetry/
https://medlineplus.gov/ency/article/001982.htm
https://medlineplus.gov/hypothermia.html
```

---

# ADR-041 — Alerte compacte de tension artérielle

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

La fiche Soins classe la tension systolique/diastolique saisie en **mmHg** et
affiche, directement sous le champ, la même alerte compacte et non bloquante
que pour les autres constantes. Cette aide au dépistage ne constitue pas un
diagnostic et demande toujours de recontrôler une mesure signalée.

Pour un adulte :

```text
< 90 systolique ou < 60 diastolique  avertissement orange — TA basse
130–139 ou 80–89                    avertissement orange — TA élevée
≥ 140 ou ≥ 90                       avertissement orange — TA très élevée
> 180 ou > 120                      alerte rouge — TA sévèrement élevée
```

Le format abrégé local `17/12` n'est pas enregistré silencieusement comme une
mesure clinique. L'interface demande explicitement `170/120 mmHg`, afin de ne
pas transformer une valeur ambiguë. Une paire incohérente reste soumise à la
validation Laravel existante.

Références :

```text
https://www.heart.org/en/health-topics/high-blood-pressure/understanding-blood-pressure-readings
https://www.nhs.uk/conditions/low-blood-pressure-hypotension/
```

---

# ADR-042 — Supervision centrale du stock Pharmacie et référentiel d’adresses par site

**Status:** ACCEPTED (2026-08-23 — amendée le même jour par exigence explicite du propriétaire)

La Super Administration expose deux espaces indépendants :

```text
Stock médicaments   vue consolidée, import et export Excel par site
Adresses             référentiel administrable séparément sur chaque site
```

Le stock physique reste exploité au quotidien par la Pharmacie du site. Le
portail central peut lire les médicaments, quantités physiques, réservations,
disponibilités, lots et péremptions et exporter cette photographie en Excel,
avec une ligne par lot.

L'exigence explicite du propriétaire autorise aussi un import Excel central.
Pour ne jamais transformer une quantité ambiguë en ajustement silencieux, chaque
ligne indique obligatoirement une seule opération :

```text
STOCK_INITIAL  crée uniquement un nouveau lot et son mouvement d'ouverture
ENTREE         ajoute une quantité et crée un mouvement d'entrée
```

`STOCK_INITIAL` est refusé si le lot existe déjà. L'import ne propose ni sortie,
ni ajustement, ni inventaire, ni délivrance. Le médicament doit déjà exister et
être actif dans le référentiel du site ; le lot, la péremption, la quantité et
le motif sont obligatoires. L'ensemble du fichier est validé dans une
transaction : une ligne invalide annule tout l'import. Les mouvements créés
restent immuables conformément à l'ADR-036.

Les adresses peuvent être ajoutées, renommées, archivées et restaurées depuis
le portail central, mais la commande est exécutée dans la base du site cible.
Une adresse archivée n’est plus proposée aux nouveaux dossiers ; les patients
et employés déjà liés conservent leur référence historique. Les doublons sont
comparés après normalisation des accents, espaces et majuscules.

Le référentiel d'un site peut également être exporté en Excel et alimenté par
un fichier `.xlsx` dont la colonne obligatoire est `Adresse`. L'import est limité à 1 000 lignes,
normalise les doublons, est atomique, idempotent et audité dans la base du site
cible. Une adresse archivée doit toujours être restaurée explicitement : un
import ne la réactive pas silencieusement.

`admin.rivo.mg` ne se connecte jamais directement aux bases des cliniques. Les
deux modules utilisent des endpoints `/api/v1/super-admin/*` authentifiés avec
un secret propre à chaque site. Toute requête porte un UUID ; toute écriture
porte une clé d’idempotence et conserve l’identité UUID/nom de l’acteur central
dans l’audit local. L’indisponibilité d’un site ne masque ni ne bloque les
résultats des autres sites.

Permissions :

```text
stock.view
stock.import
stock.export
address_entries.view
address_entries.create
address_entries.update
address_entries.archive
address_entries.restore
address_entries.import
address_entries.export
```

---

# ADR-043 — Banc d’API multi-site strictement local

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

Pour ne pas bloquer le développement du portail Super Administration avant le
déploiement des trois domaines cliniques, l’environnement `local` fournit un
banc d’API distribué démarré par :

```text
composer local:apis
```

Le portail conserve son serveur et sa base habituels sur `127.0.0.1:8000`. Les
sites sont servis séparément et ne partagent aucune base :

```text
Mampikony      127.0.0.1:8001   storage/app/local-sites/mampikony.sqlite
Ambondromamy   127.0.0.1:8002   storage/app/local-sites/ambondromamy.sqlite
Boriziny       127.0.0.1:8003   storage/app/local-sites/boriziny.sqlite
```

Chaque processus démarre comme `RIVO_SITE_TYPE=clinic`, avec son identité, son
jeton local et sa base SQLite. Le portail accède aux stocks et aux adresses
uniquement par les endpoints REST sécurisés `/api/v1/super-admin/*`. Il n’existe
donc aucun raccourci SQL entre le portail et les sites, même pendant le
développement.

Les jetons déterministes et les ports par défaut sont exclusivement activés
avec `APP_ENV=local`. En production, les URL et secrets restent obligatoirement
explicites. Les bases sont créées et peuplées à leur première préparation ; un
redémarrage conserve les modifications de test. Leur recréation exige l’option
explicite `--reset` et est refusée tant que l’API concernée tourne.

---

# ADR-044 — Pilotage central des désignations et tarifs par API de site

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

L’espace Super Administration `Désignations & tarifs` n’est plus une maquette.
Il interroge séparément les API de Mampikony, Ambondromamy et Boriziny et permet,
sur le site explicitement choisi :

```text
créer et modifier une désignation
archiver et restaurer une désignation
créer ou remplacer le tarif STANDARD (sans mutuelle)
créer ou remplacer le tarif MUTUAL (mutuelle)
suspendre un tarif actif
consulter l’historique tarifaire
```

Le portail central ne lit et n’écrit jamais directement une base clinique.
Chaque écriture distante porte un UUID de requête, une clé d’idempotence,
l’UUID/nom du Super Administrateur et ses permissions granulaires. L’API cible
réexécute l’autorisation métier et conserve l’identité distante dans les lignes
concernées et l’audit local. Une panne d’un site ne bloque pas les autres.

Les deux catégories tarifaires restent indépendantes. Un tarif mutuelle absent
ne reprend jamais le tarif standard. Tout remplacement clôt la version active
et crée une nouvelle version ; aucun passage ni facture historique n’est
recalculé. L’archivage d’une désignation est un Soft Delete audité.

Le banc local de l’ADR-043 expose ces mêmes endpoints. Les prestations de test
sont ajoutées seulement lorsqu’aucune prestation clinique n’existe encore sur
le site : un redémarrage ne recrée donc pas un tarif suspendu et n’écrase jamais
une décision tarifaire saisie pendant les tests.

---

# ADR-045 — Référentiel des mutuelles et partenaires par site

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

Chaque site clinique possède son propre référentiel `mutual_organizations`.
La Super Administration peut le consulter, créer un organisme, le renommer,
l’archiver et le restaurer exclusivement par l’API du site cible. Les
couvertures patient déjà enregistrées conservent leur relation avec un
organisme archivé ; celui-ci n’est simplement plus proposé pour une nouvelle
couverture.

Les noms sont comparés après normalisation des accents, espaces et majuscules.
Un organisme archivé doit être restauré explicitement et ne peut jamais être
recréé comme doublon. Toutes les écritures sont autorisées par permissions
granulaires, idempotentes, auditées dans la base clinique et portent l’identité
du Super Administrateur distant.

Classification validée :

```text
Sans mutuelle       catégorie tarifaire STANDARD, pas un organisme
Avantage Personnel dispositif RH/Finance du personnel, pas une mutuelle
Funhece, ADEFI, G4S, BOA, BNI, PAMF, ISPG, TFC et les partenaires
« Personnels … »    organismes/partenaires du référentiel
```

Cette décision valide la gestion des organismes, mais ne définit pas une grille
de prix différente pour chacun. Jusqu’à validation d’une convention détaillée,
le tarif `MUTUAL` reste une seule catégorie propre au site et n’utilise jamais
le tarif `STANDARD` comme remplacement silencieux.

Permissions :

```text
mutual_organizations.view
mutual_organizations.create
mutual_organizations.update
mutual_organizations.archive
mutual_organizations.restore
mutual_organizations.import
mutual_organizations.export
```

---

# ADR-046 — Actions multiples sûres dans les référentiels Super Administration

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

Les écrans Adresses, Stock médicaments et Désignations & tarifs acceptent une
sélection multiple limitée à 100 lignes et toujours rattachée à un seul site.
Une sélection ne peut jamais déclencher implicitement la même commande sur
plusieurs cliniques.

Les commandes d'archivage et de restauration multiples concernent uniquement
les adresses, les désignations et les organismes mutualistes. Elles réutilisent
les permissions granulaires existantes, portent une clé d'idempotence, sont
auditées pour chaque entité et s'exécutent dans une transaction locale du site.
La liste complète des UUID doit être compatible avec l'opération : si une seule
ligne est absente, déjà archivée ou déjà active, la commande entière est
refusée sans modification partielle. Un motif commun est obligatoire pour tout
archivage.

Dans le Stock, l'action multiple est volontairement limitée à l'export Excel
des médicaments sélectionnés avec tous leurs lots. Aucun ajustement, mouvement,
inventaire, archivage ou suppression de stock en masse n'est déduit d'une simple
sélection d'interface. Les écritures de stock conservent les processus explicites
et audités définis par les ADR-036 et ADR-042.

---

# ADR-047 — Taux de couverture mutuelle et répartition financière figée

**Status:** ACCEPTED (2026-08-23 — précision explicite du client)

Le montant `MUTUAL` d'une désignation reste le tarif brut contractuel du site.
Chaque organisme actif porte séparément un `coverage_rate` compris entre 0 et
100 %, avec 100 % comme valeur par défaut. Le reste patient est calculé par le
backend :

```text
part mutuelle = tarif brut × taux de couverture
part patient  = tarif brut − part mutuelle
```

Le calcul utilise les unités monétaires entières et un arrondi déterministe. Par
exemple, une prestation de 20 000 MGA couverte à 80 % produit 16 000 MGA de
prise en charge et 4 000 MGA à payer par le patient.

La couverture n'est ni une remise, ni un encaissement, ni un paiement. La
Réception/Caisse reste le seul module autorisé à encaisser la part patient. Une
facture couverte à 100 % est validée avec le statut `COVERED`; aucun paiement et
aucun reçu de paiement ne sont fabriqués. Le document conserve néanmoins le
tarif brut et la part de l'organisme.

Le nom/UUID de l'organisme, son taux, le brut, la prise en charge et le reste
patient sont figés sur la demande du passage, la prestation facturable et les
lignes de facture. Une modification ultérieure du taux ou du tarif ne recalcule
jamais un historique clinique ou financier.

Le portail Super Administration importe et exporte en Excel `.xlsx` :

```text
les deux colonnes tarifaires STANDARD et MUTUAL par désignation et par site
la liste des organismes et leur taux de couverture par site
```

Les imports sont limités, validés intégralement avant application, atomiques,
idempotents et audités dans la base du site cible via `/api/v1`. Un organisme
archivé doit être restauré explicitement. Le portail central ne communique
jamais directement avec la base d'un site.

Permissions complémentaires :

```text
catalog.tariffs.import
catalog.tariffs.export
mutual_organizations.import
mutual_organizations.export
```

---

# ADR-048 — Espaces Chirurgie/Anesthésie, référentiels et rapport financier

**Status:** ACCEPTED (2026-08-23 — exigence explicite du propriétaire)

Chirurgie et Anesthésie disposent de deux espaces de travail distincts :
`/surgery` exige `surgery.view`, tandis que `/anesthesia` exige
`anesthesia.view`. Une permission n'accorde jamais l'autre implicitement. Le
socle du rôle ou une permission individuelle `ALLOW` constitue une autorisation
explicite ; un `DENY` individuel reste prioritaire. Sans `anesthesia.view`, les
données anesthésiques ne sont pas sérialisées dans la page Chirurgie.

La séparation des interfaces ne duplique pas le dossier. Les deux espaces
travaillent sur le même `SurgicalRequest` et son unique `AnesthesiaRecord`, ce
qui garantit la continuité clinique et l'historique. L'interface Anesthésie est
organisée en trois étapes (consultation, paraclinique/décision, conduite
peropératoire) et l'interface Chirurgie en cinq étapes (dossier, préparation,
intervention, sortie de bloc, suivi/clôture).

La fiche `CareRecord` du même épisode reste également la source unique pour les
informations déjà saisies aux Soins. Chirurgie et Anesthésie les reçoivent dans
une projection explicitement en lecture seule, filtrée côté Laravel par
`care.view`, `vitals.view` et `patients.medical_history.view`. La relation brute
n'est pas sérialisée. Le rôle `SURGERY` reçoit ces trois droits de lecture, mais
aucun droit `care.update`, `vitals.update` ou de gestion des antécédents. Un
`DENY` individuel continue à masquer la partie concernée.

Dans ces espaces, tension, température, poids, taille, groupe sanguin, allergies
et actes réalisés aux Soins ne sont donc ni préremplis dans un second formulaire,
ni ressaisis. Les formulaires ne demandent que les observations propres à
l'anesthésie ou au passage au bloc. La consultation et le bilan anesthésiques
sont présentés en accordéons progressifs avec enregistrement entre sous-étapes.
Le « feu vert chirurgical avant bloc » demeure un contrôle organisationnel de
l'équipe chirurgicale et la transition vers le bloc ; il ne valide ni l'état
clinique du patient, ni la décision de l'anesthésiste.

L'intervention prévue est choisie dans le référentiel `CatalogItem` du module
`SURGERY`. Le dossier conserve à la fois la référence et un instantané du
libellé afin qu'une correction future du catalogue ne réécrive jamais
l'historique clinique. Le choix « Autres » exige une précision. Les produits,
matériels et techniques d'anesthésie utilisent également une liste contrôlée ;
chaque enregistrement conserve code, libellé et catégorie en instantané.

Le tableau `Prévu / Réel / Écart / Dette NP` appartient aux rapports financiers,
jamais à l'espace clinique Chirurgie. `Réel` et `Dette NP` doivent provenir des
éléments facturables, factures et paiements de la Réception/Caisse. Aucun
montant, zéro ou dette ne peut être déduit d'un dossier chirurgical seul. Tant
que la création des prestations facturables Chirurgie et l'API de rapport ne
sont pas implémentées, l'interface Finance affiche donc des valeurs
indisponibles (`—`) plutôt que de faux `Ar0`.

## Amendement du 2026-09-22 — le dossier du bloc se lit dans l'ordre de son workflow

Demande du propriétaire : rendre la page d'un dossier chirurgical plus lisible, et
lui faire suivre la logique métier. Le CDC §16 ne décrit que les permissions et
l'absence d'encaissement ; l'ordre ci-dessous est celui des transitions que le
modèle `SurgicalRequest` impose déjà — rien n'est inventé, rien n'est assoupli.

```text
source unique    resources/js/utilities/surgicalWorkflow.js : étapes (Chirurgie 5,
                 Anesthésie 3), prochaine action et droit que le serveur exigera,
                 lus sur le statut réel ; la page ne recompte plus rien
étape à faire    marquée « À faire » ; une étape qui attend dit pourquoi (« Le feu vert
                 se confirme une fois l'intervention programmée ») et reste lisible
ouverture        le dossier s'ouvre sur l'étape de sa prochaine action
barre du bas     « Prochaine étape » : ce qui reste à faire, où qu'on soit dans la page,
                 avec un bouton qui ouvre le bon formulaire ; sans le droit, elle nomme
                 le droit au lieu d'un bouton qui refuserait (ADR-154)
annulée          lisible, plus aucun bouton d'écriture ; le bandeau d'hospitalisation
                 ne dit plus que le patient « remonte à son lit après le bloc »
```

Deux pièges du workflow, que le serveur crée sans les annoncer, sont désormais
signalés à l'écran :

```text
heure de fin     l'intervention ne se corrige qu'« Au bloc » ; la validation du compte
                 rendu clôt l'intervention. « Valider » attend donc l'heure de fin.
sortie du bloc   elle ne se renseigne plus après la sortie de Chirurgie : un dossier
                 « Opéré » sans sortie du bloc la propose d'abord, et la sortie de
                 Chirurgie avertit (sans bloquer)
```

**Défaut corrigé côté serveur.** Valider un compte rendu sur un dossier qui n'était
pas « Au bloc » enregistrait le compte rendu comme validé, puis la clôture de
l'intervention échouait (erreur 500) : un compte rendu que plus personne ne pouvait
corriger, sur un dossier que plus personne ne pouvait clore.
`ValidateSurgicalReportAction` vérifie désormais le statut **avant** de toucher au
compte rendu, dans une transaction, et répond par un message (clé `report`).

**Signalé, non tranché.** Le serveur accepte encore la rédaction d'un compte rendu
avant le démarrage de l'intervention, et la validation d'un compte rendu sans heure
de fin : l'écran ne les propose plus, mais en faire des refus serveur est une règle
métier à décider. Aucune permission, route ni migration nouvelle.

**Complément du même jour — deux colonnes, et l'ordre à l'intérieur des étapes.** Constat
du propriétaire : l'étape « Dossier » montrait en même temps Demande, Programmation, Équipe
de bloc et Synthèse anesthésie, sans ordre, et la page défilait trop. L'espace Chirurgie se
lit désormais en deux colonnes :

```text
colonne principale   le geste de l'étape, et lui seul, dans l'ordre du workflow
  Dossier            Programmation → Équipe de bloc (juste en dessous)
  Préparation        ① Feu vert → ② Entrée au bloc
  Intervention       Intervention, puis consommables
  Suivi & clôture    Compte rendu → Suivi péri/postopératoire → Complications
                     → Sortie de Chirurgie (le geste final)
colonne latérale     le même contexte à chaque étape : Synthèse anesthésie → Demande
                     → Synthèse des Soins
en-tête              le patient hospitalisé en pastille à côté du statut
                     (« Hospitalisé · Salle 1 · Lit 2 », lien vers le séjour, détail au survol)
```

Chaque section porte son état — « Fait », « À faire », ou en attente d'un geste
précédent. L'équipe attend la programmation (les chirurgiens en viennent, ADR-168) ; la
synthèse anesthésie se relit avant le feu vert ; la salle et les consignes suivent la
programmation. Une section en attente se replie sur son titre et ce qu'elle attend
(« Afficher » la déplie quand même) et s'ouvre d'elle-même quand son tour vient. Sur un
écran étroit, le contexte passe sous le geste : l'action reste en premier. L'espace
Anesthésie garde sa page sur une colonne. C'est de l'affichage : le serveur n'est pas
modifié et ne refuse rien de nouveau.

Les deux colonnes sont séparées par une barre verticale que l'on glisse (`ResizableSplit`,
déjà employé pour l'examen clinique et la fiche de soins) : souris, tactile ou clavier,
double-clic pour revenir au réglage par défaut (70 / 30), bornes 50 à 75 %. La largeur
choisie reste sur le poste (`rivo:surgery:context-split`) et n'est jamais envoyée au
serveur. La carte Demande (`SurgicalRequestCard`) donne un repère à chacun de ses faits —
intervention, demandeur et date, transmission — et ne propose d'enregistrer qu'une
correction réelle.

## Amendement du 2026-09-22 — les formulaires du bloc s'enregistrent tout seuls

Demande du propriétaire : « enregistrer » doit être automatique, et chaque « Enregistrer et
continuer » devient « Suivant ». Entrée au bloc, sortie du bloc, consultation pré-anesthésique,
examen paraclinique et conduite anesthésique s'enregistrent environ 1,5 s après la dernière
saisie (`composables/useAutosave.js`).

```text
même chemin     la même route, les mêmes droits, la même validation et le même audit que
                l'ancien bouton : seul le clic disparaît ; en lecture seule, rien ne part
« Suivant »     enregistre immédiatement ce qui reste, puis ouvre la section suivante ;
                un refus y reste et amène le regard sur le champ fautif
statut          « Modifications non enregistrées » / « Enregistrement… » / « Enregistré à HH:MM »
                / « Échec » (+ Réessayer) — « enregistré » seulement après la réponse du serveur
discrétion      aucun toast pour un enregistrement automatique ; la frappe n'est jamais
                interrompue (état de la page conservé, curseur et section inchangés)
quitter         changer d'onglet enregistre ce qui reste
```

« Valider l'évaluation » et « Valider le dossier » restent des gestes explicites : ils attendent que
le dernier enregistrement soit parti. Chaque enregistrement automatique reste une écriture auditée
du dossier : il y en a davantage qu'avec un bouton, espacés par la pause de frappe.

Hors périmètre, signalé : la consultation Médecine garde son bouton « Enregistrer » — elle n'y
enregistre automatiquement qu'un brouillon (ADR-073), et valider une étape reste un geste distinct
(ADR-076). Son bouton principal se nomme désormais « Suivant : <étape> », même effet qu'avant.

## Amendement du 2026-09-22 — l'espace Anesthésie se lit comme le bloc

Demande du propriétaire : « ajouter des UI et UX avec icônes » sur `/anesthesia/{demande}`. Les trois
étapes (consultation pré-anesthésique, examen paraclinique et décision, conduite anesthésique)
quittent les derniers restes DashWind (`SurgeryIcon`, palettes slate/gray et rose/sky/amber/orange
codées en dur) pour les tokens shadcn et les icônes lucide (ADR-099). C'est de l'affichage : aucun
`v-model`, aucune route, aucune validation ni règle serveur ne change.

```text
AnesthesiaStepHeader   en-tête commun aux trois étapes : icône du geste, « Anesthésie · étape N sur 3 »,
                       état (Évaluation validée / Dossier validé / Lecture seule) en Badge, et
                       l'avancement des sous-étapes — barre et liste « fait / à faire », chaque état
                       écrit et non seulement coloré
ClinicalSubsection     un bloc de champs : icône, titre, phrase, emplacement à droite (le total Glasgow)
ClinicalAccordionSection  reçoit `icon` (composant lucide) et une pastille « Renseigné » quand la
                       sous-étape est complète ; les anciens `icon="file-text"` (chaînes ignorées)
                       deviennent de vraies icônes
antécédents médicaux   les cases à cocher natives deviennent des pastilles cochables (case masquée en
                       `sr-only`, coche dessinée) : un « oui » se distingue au premier regard
```

L'avancement affiché par l'en-tête est celui que les sections calculent déjà (`historyComplete`,
`resultsComplete`…) ; rien n'est recalculé. Le rendu n'a pas été vérifié dans un navigateur.

---

# ADR-049 — Parcours Pharmacie, facturation Caisse et stock local

**Status:** ACCEPTED (2026-08-26 — validation explicite du propriétaire)

Les stocks Pharmacie sont indépendants par site. Une ordonnance interne crée
automatiquement une demande de dispensation et réserve les lots en FEFO. Une
vente directe au comptoir peut être créée sans patient ni passage, mais elle
réserve également les lots en FEFO. Dans les deux cas, la Pharmacie prépare un
élément facturable et une facture ; elle n'encaisse jamais.

La Réception/Caisse reste l'unique module autorisé à enregistrer un paiement et
à émettre un reçu. La quantité physique d'un lot ne diminue qu'après paiement
intégral ou prise en charge intégrale. Une délivrance partielle est autorisée :
chaque bon de sortie, allocation de lot et mouvement est conservé, audité et
immuable. Une annulation financière est refusée dès qu'une délivrance physique
a commencé.

Les entrées enregistrent lot, péremption, origine, destination et, lorsque le
droit le permet, fournisseur et prix d'achat. Les ajustements de péremption,
casse/perte et inventaire ne peuvent jamais réduire le stock physique sous les
quantités déjà réservées. Un seuil minimal par médicament crée et résout
automatiquement une alerte locale.

Le paramétrage comprend DCI, forme, dosage, fabricant, code-barres, catégorie,
fournisseurs, statut d'ordonnance, seuil minimal et tarif de vente. L'import de
médicaments est création-only, atomique et rejette tout le fichier si une ligne
ou un code est invalide. Conformément à l'ADR-024, ces écritures de catalogue
requièrent des permissions explicites et ne sont pas accordées au rôle
`PHARMACY` par défaut.

---

# ADR-050 — Ticket Pharmacie et contrôle de référence à la Caisse

**Status:** ACCEPTED (mise à jour 2026-08-28 — exigence explicite du propriétaire)

Toute demande de dispensation facturée, qu'elle provienne d'une ordonnance
interne ou d'une vente comptoir externe, possède un ticket imprimable. Le numéro
unique de facture généré par le backend est la référence automatique de ce
ticket ; aucun code financier libre n'est saisi par la Pharmacie.

Le QR du ticket encode exclusivement cette référence de facture. À
Réception/Caisse, l'agent peut scanner ce QR ou saisir la référence. Pour un
patient interne, le numéro de passage ou le numéro patient permet également de
retrouver les factures Pharmacie concernées. Le contrôle affiche le statut et le
solde avant de proposer l'encaissement.

La vente comptoir ne demande aucun numéro de référence à la Pharmacie, y compris
pour un produit signalé comme nécessitant une ordonnance. Elle utilise
uniquement la référence automatique de facture pour identifier le ticket ; le
backend n'accepte ni ne fabrique une référence médicale d'ordonnance externe.

À la Caisse, le contrôle du ticket Pharmacie est présenté dans un onglet dédié,
au même niveau que les factures à encaisser et les paiements récents. Ouvrir une
URL de contrôle avec une référence sélectionne directement cet onglet. Le mode
de saisie manuelle y est sélectionné par défaut ; l'agent peut ensuite choisir
le scanner QR. Les factures issues de la Pharmacie sont exclues de la liste
générale « Factures à encaisser » et restent regroupées dans cet onglet, quel
que soit leur statut. Sans filtre, les tickets Pharmacie récents sont listés ;
la saisie filtre dynamiquement et partiellement par référence, client, patient
ou passage.

La création d'une vente comptoir ne présente ni action « Retour aux demandes »
ni bouton d'impression autonome. Son pied présente uniquement « Créer et
transmettre à la Caisse ». Cette action ouvre une fenêtre de confirmation avec
le récapitulatif de la vente ; son action finale explicite est « Imprimer et
transmettre à la Caisse ». La confirmation crée la vente et sa référence
officielle, la rend immédiatement visible à la Caisse, lance directement
l'impression du ticket officiel, reste sur la page Vente comptoir, puis vide le
formulaire pour le client suivant. Le nom du client, son téléphone et le
prescripteur externe renseignés sont conservés et imprimés sur le ticket
officiel. La file de dispensation reste compacte : le statut et
les actions sont deux colonnes distinctes, l'ordonnance et ses produits
s'ouvrent dans une fenêtre dédiée par une unique action « Voir ». Le détail
déjà présent dans cette fenêtre constitue le contrôle visuel : aucun
bouton « Aperçu » supplémentaire n'est affiché. « Imprimer le ticket » ouvre
directement le dialogue d'impression depuis cette fenêtre sans naviguer vers la
page du ticket ni changer l'URL visible.

Les identifiants exposés par les opérations Pharmacie sont des UUID : demande,
ligne de dispensation, réservation, bon de sortie, allocation, mouvement de
stock, médicament et lot. Les clés SQL numériques restent strictement internes
aux relations locales. Chaque opération sensible conserve une autorisation
Laravel distincte, notamment `pharmacy.dispense.prepare_invoice`,
`pharmacy.dispense.print`, `pharmacy.dispense`, `stock.entry` et `stock.adjust` ;
les contrôles Vue servent uniquement à l'ergonomie.

Dans l'onglet Caisse consacré aux tickets Pharmacie, le mode Saisir/Scanner et
le champ de référence occupent l'en-tête, au même emplacement que la recherche
des factures à encaisser. Aucun second titre ni texte explicatif n'est affiché.
Les résultats utilisent les mêmes colonnes tabulaires que les factures à
encaisser : client, facture/passage, validation, montants, statut et actions.

Le ticket Pharmacie n'est ni un paiement ni un reçu. Sa consultation et son
impression utilisent `pharmacy.dispense.print`. Seule Réception/Caisse conserve
`payments.*`, `cash.*` et l'émission du reçu après un encaissement réel.

---

# ADR-051 — Identité Patient permanente et couverture financière par Episode

**Status:** ACCEPTED (2026-08-28 — exigence explicite du propriétaire)

Cette décision amende les aspects financiers des ADR-030, ADR-031 et ADR-047 :
le `Patient` représente uniquement l'identité administrative permanente. Le
responsable financier d'une prise en charge appartient au passage concerné. Un
même patient peut donc avoir successivement des épisodes `SELF`, `MUTUAL`,
`STAFF`, puis `SELF`, sans mutation de son identité permanente.

`Patient.patient_type` et `PatientMutualCoverage` sont conservés comme données
legacy pour afficher l'historique et maintenir les anciens écrans pendant leur
transition. Ils ne déterminent plus le tarif d'un nouvel épisode. Aucune donnée
ancienne n'est supprimée et aucune migration massive n'infère un mode depuis
`patient_type`, car cette déduction pourrait falsifier l'historique.
Le flux d'arrivée crée désormais l'identité avec la valeur legacy par défaut
`STANDARD` et inscrit `MUTUAL` ou `STAFF` uniquement sur l'épisode concerné.

`Episode.financial_mode` accepte :

```text
SELF     le patient supporte le tarif STANDARD
MUTUAL   une mutuelle supporte tout ou partie du tarif MUTUAL
STAFF    un régime Personnel, allocation financière définie par l’ADR-052
NULL     urgence ou ancien passage dont le contexte reste à régulariser
```

Cette colonne est indépendante de `financial_status`, qui conserve le statut du
compte financier. La date et l'auteur de configuration sont tracés sur
l'épisode. `MUTUAL` exige une `EpisodeMutualCoverage` reliée à l'unique
`MutualOrganization` existante. Elle fige UUID, nom et taux de couverture de
l'organisme, ainsi que l'employeur, la qualité du bénéficiaire et le matricule.
Les justificatifs privés peuvent être rattachés à cette couverture, cinq au
maximum. Une modification ultérieure de l'organisme ne réécrit jamais cet
instantané.

`STAFF` exige une `EpisodeStaffCoverage` reliée au véritable `Employee`. Elle ne
recopie ni nom, ni prénom, ni fonction, ni service. `PatientStaffLink` reste la
preuve d'identité Patient ↔ Employee, mais ne déclenche jamais automatiquement
le mode `STAFF`. Un employé lié peut choisir `SELF` sur un autre passage. Le
crédit forfaitaire Bloc n'est pas défini par cette ADR ; l’ADR-052 complète
désormais cette responsabilité sans modifier le contexte financier de l’Episode.

`SetEpisodeFinancialContextAction` est l'unique chemin d'écriture pour ces trois
modes. Une fois une `BillableItem` ou une `Invoice` créée, le contexte ne peut
plus être remplacé par cette action. Une correction future exigera une procédure
distincte et auditée. La résolution tarifaire définitive utilise uniquement
l'épisode, son mode et sa couverture. Un tarif `MUTUAL` manquant ne retombe
jamais sur `STANDARD`. Pour un épisode legacy sans mode, seuls les snapshots
financiers déjà présents peuvent être relus ; le type permanent du patient ne
sert pas de fallback.

Avant la création d'un patient, `ReceptionEstimateService` peut calculer une
estimation read-only à partir des prestations `SERVICE`, facturables et
sélectionnables à la Réception, au tarif `STANDARD` courant. Le serveur relit
toujours le tarif : un prix envoyé par le navigateur est ignoré. Une estimation
ne crée aucun patient, épisode, demande, orientation, prestation facturable,
facture ou paiement.

Une urgence conserve son comportement prioritaire : Soins et Médecine sont
ouverts immédiatement même lorsque `financial_mode` est `NULL`. L'absence de
contexte financier ne peut jamais annuler ni bloquer la prise en charge
clinique. Cette fondation n'ajoute ni `LABORATORY_DIRECT`, ni activation des
analyses à la Réception, et ne modifie pas la vente comptoir externe Pharmacie
sans Patient/Episode.

---

# ADR-052 — Couverture Personnel et registre du crédit forfaitaire Bloc

**Status:** ACCEPTED (2026-08-28 — exigence explicite du propriétaire)

Cette décision complète les ADR-030 et ADR-051 sans déplacer la responsabilité
du contexte financier : `EpisodeFinancialMode::STAFF` et
`EpisodeStaffCoverage` indiquent toujours quel `Employee` bénéficie du régime
Personnel pour un passage. Le compte de crédit forfaitaire Bloc appartient à
`Employee`, jamais à `Patient` ni à `Episode`.

Chaque élément facturable du catalogue possède une politique Personnel
explicite :

```text
UNCLASSIFIED              résolution financière en attente
ORDINARY_FULL_COVERAGE    prise en charge Personnel à 100 %
BLOCK_CREDIT              consommation du crédit forfaitaire Bloc
NOT_COVERED               montant intégral à la charge du patient
```

Les lignes historiques restent `UNCLASSIFIED`. Aucune classification ne peut
être déduite du nom, du code, du libellé ou du module. En particulier,
`CatalogModule::SURGERY` ne signifie jamais `BLOCK_CREDIT` : une consultation
de chirurgien ou un contrôle postopératoire peut être une prestation ordinaire,
et un nom contenant « Bloc » ne modifie pas la politique configurée.

Le tarif brut d’un Episode STAFF est le tarif `STANDARD` réel et reste
historisé. La politique répartit ensuite ce brut entre prise en charge Personnel,
crédit Bloc et part patient. Un crédit insuffisant est consommé jusqu’au solde
disponible et le reste demeure à la charge du patient ; un solde épuisé ne
devient jamais négatif. `UNCLASSIFIED` interdit la création financière mais ne
bloque ni la demande clinique, ni l’orientation, ni les Soins, ni la Médecine,
ni une prise en charge chirurgicale urgente.

Le crédit est alloué manuellement par montant configurable. Aucune période,
date d’effet, fréquence, valeur par défaut ou règle de renouvellement n’est
inventée. Le registre immuable conserve les allocations, consommations et
réversions avec employé, montant, type, Episode et prestation éventuels, soldes
avant/après, clé d’idempotence, motif, auteur et date. Une annulation ajoute une
réversion ; elle ne supprime ou ne modifie jamais la consommation initiale.

La consommation est réalisée dans une transaction qui verrouille le dossier
`Employee` avant de relire le dernier solde. Une clé unique par `BillableItem`
rend les doubles clics, retries et relances idempotents. Les snapshots STAFF
sont conservés sur la demande clinique, la prestation facturable, la facture et
sa ligne, en plus du montant brut.

Les permissions `staff_block_credits.view` et
`staff_block_credits.allocate` sont accordées par défaut à
`ADMINISTRATION` (responsabilité RH/Finance). La Réception peut utiliser le
résultat financier et encaisser uniquement la part patient, mais ne peut ni
allouer, ni corriger le solde, ni modifier l’historique, ni créer une réversion
manuelle. Le Super Administrateur central reste soumis à l’architecture API
inter-sites et n’accède jamais directement à une base clinique.

---

# ADR-053 — Expérience Réception progressive pilotée par le besoin

**Status:** ACCEPTED (2026-08-28 — exigence explicite du propriétaire) ; l'étape
« Routage » est retirée par l'**ADR-177** (2026-09-23) : la confirmation n'oriente plus, elle
propose une prochaine étape facultative.

Pour un passage normal, l’accueil ne commence plus par une catégorie
financière du Patient. L’ordre opérationnel est désormais :

```text
BESOIN -> ESTIMATION -> PATIENT -> EPISODE -> MODE FINANCIER
        -> CONFIRMATION DES PRESTATIONS -> ROUTAGE
```

L’écran initial demande « Quel est votre besoin aujourd’hui ? » et charge le
catalogue réel. Une prestation y apparaît uniquement lorsqu’elle est un
`SERVICE` actif, facturable, `reception_selectable` et dotée d’un routage
Réception. La liste et les destinations ne sont jamais codées dans Vue. Cette
décision n’ajoute pas `LABORATORY_DIRECT` et n’active aucune analyse. La
configuration existante du Laboratoire reste inchangée.

« Achat de médicaments uniquement » ne crée pas un panier Pharmacie dans la
Réception. Cette branche renvoie vers la Vente comptoir Pharmacie existante,
qui conserve son fonctionnement sans Patient ni Episode clinique obligatoire.

Avant toute identité, `ReceptionEstimateService` recalcule une estimation au
tarif `STANDARD` à partir des seuls UUID et quantités. Quitter après cette
estimation ne laisse aucun Patient, Episode, demande, orientation,
`BillableItem`, facture ou paiement. Les prix, totaux et répartitions envoyés
par un navigateur sont refusés ou ignorés comme sources de vérité.

Après « Continuer la prise en charge », la Réception recherche ou crée
l’identité permanente avec les validations et la protection anti-doublon
existantes. `RegisterArrivalAction` crée ensuite exactement un Episode pour
toutes les prestations sélectionnées. Le mode `SELF`, `MUTUAL` ou `STAFF` est
choisi seulement après cette création et enregistré exclusivement par
`SetEpisodeFinancialContextAction`.

Dès la création de l’Episode, le navigateur rejoint une URL stable contenant
son UUID (`/reception/passages/{uuid}/prise-en-charge`). La sélection encore
non confirmée est conservée dans un brouillon technique rattaché à l’Episode :
une actualisation reprend donc le même passage et la même étape sans créer un
second Episode. Ce brouillon n’est ni une demande clinique, ni une prestation
facturable, ni une facture ; il est supprimé après la confirmation définitive
du routage.

`MUTUAL` sélectionne un `MutualOrganization` actif existant et ne permet
aucune création d’organisme depuis ce parcours. `STAFF` sélectionne un
`Employee` actif via la projection minimale autorisée et réutilise le lien
d’identité Patient/Employé. La Réception ne reçoit ni historique du registre,
ni allocation, ni réversion, ni mutation du crédit Bloc.

La confirmation présente une projection Laravel du brut, de la couverture, du
reste patient et de la destination initiale. Pour `BLOCK_CREDIT`, cette
projection simule le solde disponible uniquement en mémoire : elle ne crée
aucun `StaffBlockCreditMovement`. La consommation réelle reste dans la création
idempotente du `BillableItem`. `UNCLASSIFIED` ou un tarif manquant laisse la
finance en attente sans empêcher la demande clinique ni le routage.

La confirmation finale réutilise `CompleteEpisodeServicesAction`. Dans cette
expérience progressive, elle prépare une facture à régler ultérieurement et ne
crée aucun paiement ou reçu. L’encaissement demeure exclusivement à
Réception/Caisse. Le parcours d’urgence ne change pas : son Episode conserve
un `financial_mode` nullable et ouvre immédiatement Soins et Médecine.

---

# ADR-054 — Stabilisation Soins/Médecine : facturation des actes, clôture administrative et projection CareRecord partagée

**Status:** ACCEPTED (2026-08-29 — audit Soins/Médecine, validé par le propriétaire)

Cette décision documente la « Phase A » de stabilisation qui a suivi l’audit
Soins/Médecine : elle ne redéfinit aucune règle déjà actée par les ADR-030,
ADR-032, ADR-035, ADR-047, ADR-048 et ADR-051, mais fixe le comportement de
quatre mécanismes déjà implémentés qui n’étaient pas encore consignés ici.

## Trois notions distinctes autour de la fiche Soins

```text
CareRecord              triage / constantes du passage (tension, FC, SpO2,
                         température, IMC, allergies du passage)
CareRecordProcedure     acte Soins réellement exécuté, historisé append-only
BillableItem            prestation à facturer, créée séparément si l’acte
                         est facturable
```

`CareRecord` reste la fiche de triage/constantes définie par l’ADR-032 ; elle
n’est jamais un acte facturable en elle-même. `CareRecordProcedure` est la
trace clinique de ce qui a été réellement fait — elle existe indépendamment
de toute conséquence financière. Un acte facturable ne produit un
`BillableItem` que par un appel explicite à `RecordBillableItemAction`
(`app/Actions/Care/SaveCareRecordAction.php::billProcedureIfPossible()`),
jamais par un effet de bord de l’enregistrement clinique.

## Une erreur financière ne bloque jamais l’acte clinique

`billProcedureIfPossible()` capture toute `ValidationException` levée par
`RecordBillableItemAction` (tarif absent, contexte financier non résolu,
politique Personnel non classifiée…) et l’ignore silencieusement : le
`CareRecordProcedure`, déjà créé avant cet appel dans la même transaction,
n’est jamais annulé. C’est ce mécanisme qui permet à une Urgence — dont
`Episode.financial_mode` reste `NULL` tant que la famille n’a pas complété le
dossier (ADR-021, ADR-051) — d’enregistrer normalement les actes Soins sans
jamais être bloquée par la facturation.

## Idempotence de la facturation d’un acte

Une prestation déjà planifiée à la Réception (`EpisodeServiceRequest`) ne
peut jamais être refacturée deux fois pour le même acte : `RecordBillableItemAction`
dérive une clé d’idempotence déterministe de la demande de service
correspondante et rejoue l’élément déjà créé plutôt que d’en produire un
second. Un acte réalisé au-delà de la demande initiale (acte supplémentaire
non prévu à la Réception) produit en revanche son propre `BillableItem`
`PENDING`, indépendant.

## Rattachement à une facture non encore encaissée

`AttachBillableItemToUnpaidInvoiceAction` rattache un nouvel élément
facturable à une facture existante du même passage uniquement si :

```text
Invoice.status IN (DRAFT, VALIDATED)
ET Invoice.paid_amount = 0
```

Ce test par liste explicite est nécessaire et volontaire : une facture
`COVERED` a elle aussi `paid_amount = 0` (une prise en charge à 100 % ne
fabrique aucun paiement, ADR-047) et serait donc rattachable si le contrôle
portait uniquement sur `paid_amount`. `PARTIALLY_PAID`, `PAID`, `COVERED` et
`CANCELLED` sont donc tous exclus par construction, jamais mutés
silencieusement une fois qu’un encaissement ou un règlement a eu lieu.

## CARE_ONLY final : PENDING_SETTLEMENT

`CompleteCareAndOrientToMedicineAction::settleAdministrativelyIfPathwayComplete()`
fait progresser `Episode.administrative_status` uniquement lorsque le
workflow confirme que le parcours clinique prévu est réellement terminé :

```text
CareWorkflow::completionMode() === Finish
ET Episode.administrative_status === IN_CARE
ET aucune EpisodeOrientation Médecine active (PENDING/IN_PROGRESS) pour ce passage
```

L’orientation Soins passe à `COMPLETED` (inchangé), `Episode.administrative_status`
passe à `PENDING_SETTLEMENT`, `Episode.status` reste `OPEN`. Aucune
`MedicalDischarge` fictive n’est créée, aucune fausse `Consultation` n’est
ouverte : `PENDING_SETTLEMENT` signifie seulement que la suite du passage est
désormais administrative/financière, jamais que le patient est médicalement
sorti.

Cette transition ne s’applique jamais à `CARE_THEN_MEDICINE` (l’orientation
Médecine créée empêche la condition ci-dessus) ni à une Urgence dont
l’orientation Médecine ouverte en parallèle à l’arrivée
(`PlanEpisodeRoutingAction::openInitialQueues()`) est toujours active :
`CareWorkflow::completionMode()` ignore `Episode.priority` et ne suffit donc
jamais seul à décider de cette transition — la vérification explicite de
l’orientation Médecine active est ce qui protège l’Urgence contre une
clôture administrative prématurée.

## Page « Détail du passage »

`EpisodeController::show()` / `Episodes/Show.vue` (`/passages/{episode}`)
agrège en lecture seule ce qui est déjà accessible séparément par module :
orientations, fiche Soins, consultations/diagnostics/prescriptions Médecine,
sortie médicale, facturation. Chaque section reste gardée côté serveur par la
permission qui possède réellement la donnée (`care.view`/`vitals.view` pour
la fiche Soins, `medical_record.view` pour le dossier, `diagnoses.view` et
`prescriptions.view` indépendamment l’un de l’autre pour leurs sous-sections,
`billing.view` pour la facturation) : `patients.view` seul, qui protège la
route, ne suffit à exposer aucune de ces sections.

## Constantes partagées via CareRecordReadModel

`app/Services/Care/CareRecordReadModel.php` est la projection unique des
constantes et de leurs seuils d’alerte (`BmiAssessment`, `BloodPressureAssessment`,
`HeartRateAssessment`, `OxygenSaturationAssessment`, `TemperatureAssessment`,
ADR-038 à ADR-041). Soins, Chirurgie/Anesthésie (ADR-048) et désormais
`MedicineDossierPresenter` la consomment tous les trois ; aucun seuil n’est
recalculé indépendamment dans un module. Comme pour `SURGERY` (ADR-048),
exposer cette projection à `MEDICINE` exige les permissions en lecture seule
`care.view` et `vitals.view` — accordées au rôle par défaut, sans aucun droit
`care.update`/`vitals.update` : Médecine consulte la fiche Soins, ne la
modifie jamais.

## Antécédents patient

L’audit avait signalé `RecordPatientAntecedentAction` comme déjà
implémentée, testée, mais sans route exposée. `PatientController::storeAntecedent()`
(`POST /patients/{patient}/antecedents`, permission `patients.medical_history.manage`)
est ce point d’entrée générique, réutilisable par tout appelant autorisé —
Médecine y ajoute sa propre UI dans `Medicine/Show.vue`. Un antécédent reste
une donnée permanente du `Patient` (§19), jamais un champ de `Consultation` ;
aucune donnée n’est dupliquée entre les deux.

## Hors périmètre

Cette ADR ne couvre aucun des éléments suivants, volontairement non traités
par cette stabilisation : `ConsultationDecision::NursingCare` actionnable,
ordre de soins Médecine → Soins, `surgery.request`, `SurgicalRequest` créée
depuis Médecine, Laboratoire, `LABORATORY_DIRECT`, Hospitalisation,
`MedicalOrder` générique, Maternité, Pédiatrie.

---

# ADR-055 — Ordre de soins Médecine → Soins (CareOrder)

**Status:** ACCEPTED (2026-08-29 — exigence explicite du propriétaire)

Le médecin peut, depuis une Consultation active, demander un ou plusieurs
actes au service Soins. Ce besoin est porté par `CareOrder` (DEMANDE) et
`CareOrderItem`, un modèle dédié — jamais par `EpisodeServiceRequest`, dont le
contrat reste strictement le plan de la Réception à l'arrivée, ni fusionné
avec l'ORIENTATION (`EpisodeOrientation`), l'ACTE RÉALISÉ
(`CareRecordProcedure`) ou la FACTURATION (`BillableItem`).

Un `CareOrderItem` sélectionne un `CatalogItem` actif de type `SERVICE` et de
module `CARE` marqué `clinician_orderable` — une propriété explicite,
distincte de `reception_selectable`/`reception_routing_mode` (propres à la
Réception), jamais déduite du nom ou du code d'un acte. Chaque ligne fige un
instantané (`catalog_item_code_snapshot`, `catalog_item_name_snapshot`) :
une évolution ultérieure du catalogue ne réécrit jamais une demande déjà
faite.

`CreateCareOrderAction` réutilise exclusivement `CreateEpisodeOrientationAction`
pour créer l'orientation Médecine → Soins ; aucune nouvelle file d'attente
n'est introduite. Pour un patient `NORMAL`, l'orientation Médecine active est
terminée avant l'ouverture de Soins, afin qu'une seule orientation clinique
active existe à la fois. Une Urgence conserve sa logique parallèle existante
(Soins et Médecine restent ouverts simultanément, ADR-021).

Le médecin choisit, au moment de la demande, si le patient doit revenir en
Médecine (`requires_return_to_medicine`). Ce choix — jamais redemandé à
l'infirmier — gouverne la complétion : `CompleteCareAndOrientToMedicineAction`
détecte un `CareOrder` actif rattaché à l'orientation Soins et applique alors
sa propre règle plutôt que celle de `CareWorkflow` (qui ne décrit que le plan
d'arrivée, étranger à cette visite). Si `true`, une nouvelle orientation
Soins → Médecine est créée (même Episode, aucun nouvel Episode) ;
`AcceptMedicineOrientationAction`, inchangée, y ouvre alors une nouvelle
Consultation sans jamais réécrire la première. Si `false`, l'épisode passe en
`PENDING_SETTLEMENT` selon la même règle que l'ADR-054, sans sortie médicale
fictive.

`ConsultationDecision::NursingCare` reste une synchronisation, jamais un
déclencheur : seule l'action explicite « Demander un soin », validée côté
serveur, crée un `CareOrder`. Un brouillon ou un changement de `decision` ne
crée jamais d'orientation.

La facturation d'un acte demandé suit exactement le circuit déjà en place
(ADR-054) : c'est l'enregistrement du `CareRecordProcedure` par Soins qui
déclenche `RecordBillableItemAction`, jamais la création du `CareOrder`
elle-même.

Permissions :

```text
care_orders.create   MEDICINE uniquement
care_orders.view     MEDICINE, NURSE
```

`NURSE` ne reçoit jamais `care_orders.create` ni `consultations.*`.

---

# ADR-056 — Urgence décidée après création de l’Épisode

**Status:** ACCEPTED (2026-08-29 — décision explicite de la réunion métier)

Le choix d’urgence intervient uniquement après la création de l’Épisode et de
son UUID. L’ancien indicateur d’arrivée `is_emergency`, envoyé avant que le
passage existe, est supprimé du flux Réception. Une arrivée crée donc d’abord
un Épisode `NORMAL`, puis la Réception peut requalifier ce passage précis
depuis l’étape « Prise en charge ».

L’urgence reste exclusivement une propriété de `Episode.priority`, jamais du
`Patient`. Si un même Patient possède quatre passages, un seul peut être
`EMERGENCY` sans modifier les trois autres. La requalification ne crée ni un
nouveau Patient, ni un nouvel Épisode et ne supprime aucun besoin, contexte
financier, acte ou document déjà enregistré.

La Médecine peut appliquer la même requalification pendant une Consultation
active. Cette transition ne régresse jamais `administrative_status` : un
passage déjà `IN_CARE` reste `IN_CARE` et la Consultation en cours demeure
ouverte. Un passage fermé, annulé ou médicalement sorti ne peut plus être
requalifié.

Après requalification, les files Soins et Médecine sont ouvertes
immédiatement via l’unique `CreateEpisodeOrientationAction`. Son `active_key`
rend l’opération idempotente : une orientation Médecine déjà active est
réutilisée et seule l’orientation Soins manquante est créée. Aucun paiement ni
contexte financier ne conditionne ce déclenchement clinique.

La transition est atomique et auditée sous `episode.mark_emergency`. Elle est
protégée par la permission granulaire du même nom, accordée par défaut à
`RECEPTION` et `MEDICINE`. Cette permission n’accorde aucun droit générique
`episodes.update` à Médecine.

---

# ADR-057 — Supervision centrale des sessions de caisse

**Status:** ACCEPTED (2026-08-30 — exigence explicite du propriétaire)

Le portail Super Administration expose, pour chaque caisse nommée et toujours
via l’API du site opérationnel, une fiche de supervision comprenant la session
active, l’agent qui l’a ouverte, les dates et heures, les mouvements, les
montants attendus/comptés, les écarts et l’historique récent. Aucun accès SQL
direct aux bases des cliniques n’est ajouté.

Le **verrouillage** et la **clôture** sont deux opérations différentes. Un
verrouillage suspend tout nouvel encaissement et toute annulation de paiement,
mais conserve la session financière active et son `active_key` : il ne crée
aucune clôture fictive et n’autorise pas l’ouverture d’une autre caisse. Le
déverrouillage permet la reprise sur la même session.

La clôture centrale est définitive. Elle exige les espèces réellement
comptées et un motif ; le site recalcule lui-même le montant attendu à partir
des mouvements, enregistre l’écart, libère l’unique `active_key` puis clôture
la session. Le portail central ne fournit jamais le montant attendu comme
vérité comptable.

Les trois transitions sont atomiques, idempotentes au niveau API et auditées
sous `cash.lock`, `cash.unlock` et `cash.close`. L’identité UUID du Super
Administrateur est enregistrée comme acteur externe, sans créer d’utilisateur
local. Les permissions dédiées sont `cash_registers.lock`,
`cash_registers.unlock` et `cash_registers.close`, réservées par défaut au
profil `SUPER_ADMIN` du portail central.

---

# ADR-058 — Caisses concurrentes par poste nommé

**Status:** ACCEPTED (2026-08-30 — exigence explicite du propriétaire)

Cette décision amende l’ADR-011 : chaque site ne possède plus nécessairement
une seule caisse fonctionnelle, mais une caisse fonctionnelle **par poste
nommé** lorsque des postes sont configurés (`CashRegister`), et une seule
caisse fonctionnelle site-large lorsqu’aucun poste n’est configuré — le
comportement historique reste inchangé à l’octet près dans ce second cas.

```text
Site sans poste configuré → une seule session de caisse, comme avant
Site avec Caisse 1, Caisse 2 → Caisse 1 et Caisse 2 ouvrables et
                                utilisables simultanément, chacune sa
                                propre session
```

Caisse 1 et Caisse 2 ne s’excluent jamais l’une l’autre : ouvrir l’une
n’empêche plus d’ouvrir l’autre. La seule règle qui subsiste est que le
**même** poste ne peut jamais avoir deux sessions ouvertes à la fois. Cette
décision rejette explicitement l’alternative envisagée d’un verrouillage
« exclusif à l’ouvreur » — n’importe quel utilisateur autorisé
(`payments.create`/`cash.*`) continue d’opérer n’importe quelle session
ouverte, exactement comme aujourd’hui pour la session unique du site ; seul
le périmètre change, du site entier vers le poste précis sur lequel
l’utilisateur travaille.

Le mécanisme réutilise sans migration la colonne `cash_sessions.active_key`
déjà unique et nullable : `CashSession::activeKeyFor()` calcule
`'REGISTER_'.$cashRegister->id` pour un poste nommé, et conserve la valeur
historique `'SINGLE_OPEN_CASH'` en l’absence de poste. Deux postes différents
produisent deux valeurs distinctes et n’entrent donc jamais en collision sur
l’index unique ; le même poste rouvert reproduit la même valeur et déclenche
la même collision qu’avant. Cette même clé pilote désormais aussi la
supervision centrale de l’ADR-057 : verrouiller, déverrouiller ou clôturer à
distance cible le poste précisé, jamais « la » session du site.

Aucun paiement enregistré sans contexte de caisse explicite (facture, compte
patient, arrivée Réception) n’est jamais attribué à l’aveugle à une caisse
ambiguë : `RecordPaymentAction` n’auto-résout la session que si une seule est
ouverte sur le site, et exige sinon que l’appelant précise
`cash_register_uuid`, avec une erreur explicite plutôt qu’une mauvaise
attribution silencieuse.

---

# ADR-059 — Exclusivité locale du titulaire d’une session, détachement central

**Status:** ACCEPTED (2026-08-30 — exigence explicite du propriétaire) ; le
détachement central (`cash_registers.release`) décrit plus bas est
**SUPERSEDED par l’ADR-060** (2026-08-30, même jour) — le reste de cette
décision (exclusivité locale de l’ouvreur) reste pleinement en vigueur.

Cette décision amende l’ADR-058 le jour même : l’alternative « verrouillage
exclusif à l’ouvreur », explicitement rejetée par l’ADR-058, est en fait
retenue. Une session ouverte ne peut plus être utilisée localement — pour
encaisser, clôturer ou annuler un paiement — par un autre compte que celui
qui l’a ouverte (`cash_sessions.opened_by`). Un compte différent qui tente
d’y accéder est bloqué avec un message explicite et orienté vers une autre
caisse disponible, plutôt que de continuer silencieusement à opérer la
session d’un collègue absent.

```text
Florent ouvre Caisse 1 → seul Florent peut y encaisser ou la clôturer
Andry visite /cash/1     → bloqué : « Caisse 1 est utilisée par Florent »
Andry ouvre Caisse 2     → Caisse 2 lui appartient, indépendamment de Caisse 1
```

L’application se fait à trois niveaux, jamais seulement dans l’interface :
`RecordPaymentAction`, `CloseCashSessionAction` et `CancelPaymentAction`
refusent chacun l’action si l’acteur local diffère de `opened_by`, même en
forçant l’UUID de la caisse. Côté UI, le sélecteur de caisse et l’espace de
travail rapportent l’état par poste (`is_mine`) plutôt qu’un simple « ouvert
ou non », afin que le blocage soit visible avant toute tentative.

**Détachement central — l’échappatoire délibérée (SUPERSEDED par l’ADR-060,
retiré le jour même).** Un titulaire absent qui
n’a pas clôturé sa session ne doit pas bloquer indéfiniment une caisse. Le
Super Administrateur dispose de `cash_registers.release`
(`ReleaseCashSessionAction`, action `session/release`) : elle retire le
titulaire (`opened_by` devient `null`) sans clôturer ni modifier les
montants, l’historique ou l’état verrouillé/ouvert de la session. Cette
action est distincte de `cash_registers.close` (ADR-057) — elle ne clôture
rien et n’exige aucun comptage — et distincte d’une réattribution : le
portail central ne choisit jamais explicitement quel compte local reprend la
caisse (ADR-027 tient les comptes centraux à l’écart des rosters locaux).

Une session détachée reste ouverte et devient réclamable : le premier acteur
local qui l’utilise réellement — un encaissement ou une clôture, jamais une
simple consultation — en devient automatiquement le nouveau titulaire,
tracé sous l’action d’audit dédiée `cash.claim`. `cash_sessions.opened_by`
est donc désormais nullable ; les colonnes `released_by`,
`external_released_by_uuid/name`, `released_at` et `release_reason`
suivent le même schéma que le verrouillage central de l’ADR-057.

---

# ADR-060 — Retrait du détachement central : seule la clôture libère une caisse

**Status:** ACCEPTED (2026-08-30 — exigence explicite du propriétaire)

Cette décision retire, le jour même de son introduction, le mécanisme de
« détachement central » de l’ADR-059 (`cash_registers.release`,
`ReleaseCashSessionAction`, action `session/release`). Pour la traçabilité,
aucune action — locale ou centrale — ne doit jamais transférer la garde
d’une caisse encore ouverte à un autre titulaire sans un comptage réel des
espèces. Le détachement permettait précisément l’inverse : faire disparaître
un titulaire sans compter l’argent qu’il avait sous sa garde.

```text
Avant (ADR-059)                         Après (ADR-060)
Florent absent, caisse ouverte          Florent absent, caisse ouverte
→ Super Admin « Détache » (pas de       → Super Admin doit « Clôturer »
  comptage, session reste ouverte,        (comptage obligatoire, écart
  titulaire suivant repris au premier      tracé, session définitivement
  encaissement)                            close)
                                         → N'importe qui peut ensuite ouvrir
                                            une NOUVELLE session normalement
```

L’exclusivité locale de l’ouvreur (ADR-059, première partie) reste
pleinement en vigueur et n’est pas concernée par ce retrait : un compte
différent de l’ouvreur reste bloqué sur `RecordPaymentAction`,
`CloseCashSessionAction` et `CancelPaymentAction`. La seule différence est
qu’il n’existe plus aucune façon de faire cesser cette garde sans
`cash_registers.close` (ADR-057) — jamais de transfert silencieux, jamais
sans comptage.

Retirés : le contrôleur `release()` (API site et portail), le client
`releaseCashRegisterSession()`, les routes `session/release`, l’action
`ReleaseCashSessionAction`, le bouton « Détacher le titulaire » et sa
fenêtre de confirmation, ainsi que la logique de réclamation automatique
(« claim on first use ») dans `RecordPaymentAction` et
`CloseCashSessionAction` — ces deux actions redeviennent une simple
comparaison stricte avec `opened_by`, sans cas particulier pour une valeur
`null`.

Conservés, volontairement, sans retour en arrière risqué sur une base déjà
migrée : la colonne `cash_sessions.opened_by` reste nullable, ainsi que les
colonnes `released_by`, `external_released_by_uuid/name`, `released_at` et
`release_reason` — inertes, jamais réécrites par aucun code depuis cette
décision, mais nécessaires pour lire sans erreur l’historique déjà produit
par le mécanisme retiré (des sessions déjà détachées avant ce retrait
peuvent encore porter `opened_by = null` ; seule une clôture centrale peut
désormais les libérer).

---

# ADR-061 — Corbeille centrale multi-sites et restauration réservée

**Status:** ACCEPTED (2026-08-30 — exigence explicite du propriétaire)

Le portail Super Administration fournit une Corbeille unique qui agrège, via
les API REST des sites et jamais par accès SQL direct, les suppressions
réversibles actuellement prises en charge : Patients, prestations/produits du
catalogue, adresses, organismes mutuels et caisses nommées. La liste expose le
site propriétaire, la catégorie, l’UUID ou la référence métier, la date,
l’acteur et le motif de suppression ; elle est filtrable par site, catégorie,
période et recherche textuelle.

La restauration est unitaire, idempotente et exécutée dans la base du site
propriétaire. L’UUID d’origine est conservé. Le portail exige
`trash.restore`, puis l’API exige à nouveau ce droit **et** la permission de
restauration de la catégorie (`patients.restore`, `catalog.items.restore`,
`address_entries.restore`, `mutual_organizations.restore` ou
`cash_registers.restore`). Ces droits de restauration ne sont plus accordés
par défaut aux rôles opérationnels ; ils appartiennent par défaut uniquement
au `SUPER_ADMIN` central. La vérification reste fondée sur les permissions
dynamiques, sans contournement basé uniquement sur le nom du rôle.

Chaque catégorie appelle sa règle métier existante : contrôles de doublon des
adresses, mutuelles et caisses, attribution distante du catalogue, puis audit
du `restore` avec l’identité UUID du Super Administrateur. Une erreur ou un
conflit sur un site ne modifie aucun autre site.

Cette Corbeille n’est pas un `restore()` générique sur toutes les tables.
Consultations, antécédents, allergies, actes chirurgicaux et autres données
cliniques critiques restent exclus tant qu’un workflow métier explicite de
correction/restauration n’est pas décidé. Les paiements, factures, reçus,
clôtures et autres écritures financières ne sont jamais placés dans cette
Corbeille : ils suivent exclusivement les mécanismes annuler/corriger/inverser
prévus par le CDC.

---

# ADR-062 — Exception étroite à ADR-022 : suppression physique d’un compte jamais utilisé

**Status:** ACCEPTED (2026-08-30 — exigence explicite du propriétaire, après
signalement explicite de la contradiction avec ADR-022)

Cette décision n’abroge pas ADR-022. Un compte utilisateur reste un acteur
historique et la désactivation demeure l’unique action normale. Elle ouvre
une exception unique, volontairement étroite : un Super Administrateur peut
supprimer physiquement un compte qui n’a **strictement jamais servi** —
jamais connecté, jamais l’auteur d’une seule ligne d’audit, où qu’elle soit
dans le site.

```text
Compte jamais connecté ET jamais acteur d’un audit_logs.user_id → suppression possible
Tout autre compte                                              → désactivation uniquement
```

Cette restriction n’est pas arbitraire : `users.id` est référencé en clé
étrangère par des dizaines de tables cliniques, financières et d’audit. La
majorité (`payments`, `receipts`, `billable_items`, `catalog_items`,
`care_records`, les mouvements Pharmacie, …) refuse déjà la suppression au
niveau base de données (`restrictOnDelete`). Mais plusieurs tables cliniques
sensibles (`episodes.created_by`, `diagnoses.recorded_by`,
`patient_antecedents.recorded_by`, …) et surtout `audit_logs.user_id`
lui-même utilisent `nullOnDelete` : sans ce contrôle applicatif, supprimer un
compte ayant une activité réelle effacerait silencieusement l’identité de
l’auteur dans son propre historique d’audit — l’inverse exact de ce
qu’ADR-022 protège. `ForceDeleteUserAction` vérifie donc explicitement
l’absence totale d’activité avant toute suppression, et intercepte en plus
toute violation de contrainte restante comme filet de sécurité — jamais un
`user_id` mis à `null` sur une ligne d’audit ou clinique existante.

La suppression reste soumise aux mêmes garde-fous que la désactivation :
impossible sur soi-même, sur le dernier Super Administrateur actif, ou sur un
compte `SUPER_ADMIN` géré depuis un site opérationnel. Elle est irréversible,
distincte de la désactivation, protégée par la permission dédiée
`users.force_delete` (réservée par défaut au seul rôle `SUPER_ADMIN`, jamais
accordée automatiquement à `ADMINISTRATION`), auditée (`user.force_delete`)
et exécutée exclusivement via l’API du site — jamais un accès direct depuis
le portail central.

Le modèle `User` reste protégé par défaut : sa requête de suppression et
l’évènement `deleting` continuent de lever une exception dans tout le reste
du code. `User::allowPhysicalDeletion()` n’ouvre cette voie que pour la durée
de l’appel sanctionné par `ForceDeleteUserAction`, jamais plus largement.

---

# ADR-063 — Catalogue d’analyses séparé des prestations et références historisées

**Status:** ACCEPTED (2026-08-30 — exigence explicite du propriétaire)

Une analyse prescrivable et éventuellement facturable reste une prestation
`catalog_items` de module `LABORATORY`. Sa structure technique est portée par
`analysis_catalogs` : groupe, sous-analyse, type de résultat, unité, valeurs
prédéfinies, ordre d’affichage et références générale/homme/femme/enfant.
Cette séparation évite de placer des données cliniques variables dans le
référentiel tarifaire ; le prix demeure exclusivement dans
`catalog_tariffs`.

Les références sont choisies selon l’âge et le sexe disponibles dans le
dossier, avec repli vers la référence générale. Elles constituent une aide de
saisie à valider selon la méthode, les réactifs et les unités du laboratoire
du site. Au moment où un résultat est enregistré, les définitions et la
référence présentées sont copiées dans `lab_request_items.reference_snapshot` :
une modification ultérieure du catalogue ne réécrit jamais l’historique d’un
résultat clinique.

La gestion locale exige les permissions granulaires `analysis_catalog.*`.
L’import/export utilise Excel `.xlsx`; l’import est transactionnel, limité,
met à jour par code et annule entièrement l’opération si une ligne est
invalide. Les examens ECG/échographie restent des prestations
`catalog_items` du module `IMAGING`, distinctes des analyses Laboratoire.

---

# ADR-064 — Socle de permissions d’un rôle éditable depuis le portail

**Status:** ACCEPTED (2026-08-30 — exigence explicite du propriétaire)

Jusqu’ici, le socle de permissions d’un rôle (« tout compte `MEDICINE` a par
défaut `consultations.create` ») n’existait que dans le code, sous
`RolePermissionSeeder::GRANTS`, appliqué par `php artisan db:seed`. Modifier
ce socle exigeait donc un déploiement. Cette décision ajoute un second chemin
d’écriture, exclusivement depuis `admin.rivo.mg` : un Super Administrateur
peut désormais cocher ou décocher les permissions par défaut d’un rôle
directement dans l’écran `Rôles & permissions`, par site, sans toucher au
code ni redéployer.

Cette capacité est strictement additive. Elle ne modifie ni ne remplace la
logique déjà construite d’exceptions individuelles `user_permissions`
(`allow`/`deny` par compte, DENY prioritaire) : un compte garde exactement
les mêmes exceptions qu’avant, appliquées ensuite par-dessus le nouveau
socle du rôle, exactement comme aujourd’hui.

```text
Permission effective du compte =
    DENY individuel                                  (le plus prioritaire)
    > ALLOW individuel
    > socle du rôle             <- modifiable ici, désormais aussi par l’UI
```

L’édition est exécutée par site via l’API existante
(`PUT /api/v1/super-admin/roles/{role}/permissions`,
`UpdateRolePermissionsAction`), jamais par accès direct à une base clinique
(ADR-004/025/027). Elle exige la permission `users.manage` (déjà présente au
catalogue, jusque-là inutilisée) et remplace intégralement l’ensemble des
permissions du rôle par la liste transmise (`sync`), à la manière du seeder
lui-même. Le socle `SUPER_ADMIN` reste hors de portée de cet écran : il
continue de recevoir automatiquement toutes les permissions sur le portail
central et aucune sur un site clinique, un mécanisme distinct qu’une édition
manuelle ne doit pas contredire (ADR-025, ADR-027).

Chaque modification est auditée (`role.permissions.update`) avec l’ancien et
le nouveau socle, l’identité UUID/nom du Super Administrateur distant et le
site concerné, selon le mécanisme d’audit déjà utilisé pour la gestion des
comptes (`Auditor::record`, attribution externe automatique pour un acteur
distant).

`RolePermissionSeeder::GRANTS` n’est pas retiré : il reste la source du
socle initial à la création d’un site ou d’un nouveau rôle. Un réexécution de
`php artisan db:seed` après une édition manuelle via cet écran réapplique
cependant intégralement le tableau codé en dur et écrase donc silencieusement
toute personnalisation faite depuis le portail — ce seeder ne doit donc plus
être rejoué sur un site déjà en production après sa mise en place initiale,
sauf pour ajouter un rôle qui n’existe pas encore. Faire cohabiter les deux
sources sans écrasement (par exemple en ne synchronisant que les rôles
absents) reste hors périmètre de cette décision.

---

# ADR-065 — Corbeille locale en lecture, propre à chaque site

**Status:** ACCEPTED (2026-08-30 — exigence explicite du propriétaire, après
constat que `trash.view` accordé à un rôle clinique via ADR-064 ne donnait
accès à rien)

ADR-061 réserve la Corbeille consolidée multi-sites au seul portail
`admin.rivo.mg` ; ADR-025/027 interdisent à un compte opérationnel de s’y
connecter. Une fois ADR-064 en place, accorder `trash.view` à un rôle
clinique (ex. Médecine) n’avait donc aucun effet observable : aucune route ni
entrée de menu ne l’utilisait sur un site. Plutôt que d’interdire cette
combinaison, cette décision lui donne un sens réel, strictement local.

Chaque site expose désormais sa propre page `GET /trash` (menu « Corbeille »,
`trash.view`), scopée à ce site uniquement — jamais multi-site, jamais un
raccourci vers le portail. Elle réutilise tel quel `App\Services\Trash\
TrashDirectory`, déjà partagé avec l’API distante consommée par le portail
(ADR-061) : mêmes catégories (`Patient`, `CatalogItem`, `AddressEntry`,
`MutualOrganization`, `CashRegister`), même exclusion des données cliniques
et financières critiques, même journal d’audit.

Le paramètre qui change est l’acteur : `TrashDirectory::restore()` reçoit ici
`CatalogActor::fromUser($user)` — un utilisateur local authentifié, jamais un
acteur distant. La restauration reste donc soumise à `trash.restore` **et**
à la permission de restauration propre à la catégorie
(`patients.restore`, `catalog.items.restore`, …), non accordées par défaut
aux rôles opérationnels (ADR-061). En pratique, un compte clinique qui reçoit
uniquement `trash.view` — le cas d’usage qui a motivé cette décision — voit
donc une liste strictement en lecture, sans aucun bouton de restauration,
jusqu’à ce qu’une exception individuelle explicite (ADR-022) lui accorde
aussi les droits de restauration nécessaires.

Cette page ne modifie ni ADR-061 (le portail reste la seule vue consolidée
sur plusieurs sites) ni les permissions de restauration déjà réservées au
Super Admin par défaut : elle ajoute uniquement une lecture locale, cohérente
avec ce que `trash.view` laisse maintenant réellement espérer à qui le reçoit
depuis l’éditeur de socle de rôle (ADR-064) ou une exception individuelle.

---

# ADR-066 — Socle RH configurable sans automatisation de paie

**Status:** ACCEPTED (2026-08-29 — exigences explicites du propriétaire)

Le module Ressources humaines appartient à `ADMINISTRATION` conformément aux
ADR-025, ADR-026, ADR-030, ADR-051 et ADR-052. Son premier périmètre couvre les
dossiers Employé, contrats, présences, congés, planning, documents privés,
paramètres et rapports RH. Chaque entité adressable utilise un UUID public ;
les identifiants SQL restent locaux. Cette préparation n'ajoute aucun échange
inter-sites ni accès direct entre bases.

Les départements, fonctions, types de contrat et types d'attestation sont des
référentiels dynamiques. Les valeurs initiales validées par le propriétaire
sont amorcées ; aucun type d'attestation n'est inventé. Le dossier Employé
reprend les colonnes administratives transmises (identité, affectation,
diplôme, niveau, entrée, CIN, adresse, enfants, badge, blouse, contacts, état,
observation). Le détail éventuel des enfants reste une note administrative :
aucune entité Enfant ni règle familiale n'est déduite.

Un contrat conserve uniquement son type, sa référence, ses dates et son
observation. Une présence est une session horodatée avec entrée et sortie
facultative. Un congé conserve les valeurs saisies de durée et de solde comme
snapshots, puis suit les états `PENDING`, `APPROVED`, `REJECTED` ou
`CANCELLED`. Un créneau de planning conserve son employé, département, objet et
intervalle. En l'absence de règles officielles plus précises, le système ne
calcule ni droit acquis, ni retard, ni absence, ni heures supplémentaires et
n'interdit pas automatiquement les chevauchements.

L'import Employé est limité, atomique et réservé à la création : une ligne
invalide annule le fichier entier et aucune archive existante ne peut être
réutilisée. Les exports et impressions relisent les données serveur. Les pièces
RH sont stockées sur le disque privé, rattachées au dossier Employé et
accessibles uniquement par contrôleur autorisé. Contrats, dossiers Employé,
référentiels et pièces utilisent un archivage réversible ; contrats et pièces
refusent toujours la suppression physique. Les congés, présences et plannings
ne proposent aucune suppression faute de règle CDC correspondante.

Les écritures sensibles et les décisions sont auditées par `Auditor::record()`.
Les interfaces utilisent des pages dédiées et des sections intégrées, sans
fenêtre modale pour créer, modifier, décider ou archiver.

Les colonnes bancaires et les formules CNAPS/IRSA transmises ne sont pas
activées. Leur assiette, arrondis, plafonds, période d'application, source
légale et cas particuliers ne sont pas définis dans le CDC. Aucun salaire,
retenue, net ou déclaration n'est donc calculé ni stocké par cette décision.

## Révision de l'espace RH (2026-09-15)

À la demande du propriétaire, l'espace RH est revu pour des utilisateurs peu
à l'aise avec l'informatique, sans ajouter de règle de paie ni de temps de
travail :

```text
Navigation      le menu latéral « Ressources humaines » devient un groupe
                (Tableau de bord, Employés, Contrats, Documents, Présences,
                Congés, Planning, Rapports, Crédit Bloc, Paramètres), chaque
                entrée filtrée par sa permission ; la barre d'onglets HrNav,
                qui doublait ce menu, est supprimée
Tableau de bord le panneau « Ressources humaines — à traiter » apparaît sur la
                Vue d'ensemble pour les comptes RH ; l'accueil RH ne liste plus
                que des écrans RH (Caisses, Diagnostics, Analyses retirés)
Chiffres        HrOverviewService::summary() alimente l'accueil RH et la Vue
                d'ensemble : les deux écrans montrent les mêmes compteurs
```

Deux incohérences de données sont désormais refusées. Ce sont des gardes
d'intégrité, pas des règles de temps de travail ou de droit à congé :

```text
Présences   une session ne peut chevaucher une autre session du même employé,
            ni être créée tant qu'une de ses sessions reste ouverte
Congés      une demande ne peut chevaucher une demande EN ATTENTE ou ACCEPTÉE
            du même employé (sinon les mêmes jours seraient décomptés deux fois)
```

Le chevauchement de créneaux de planning reste autorisé, conformément au
paragraphe ci-dessus.

---

# ADR-067 — Profils paramédicaux et espace Maternité

**Status:** ACCEPTED (2026-09-01 — exigence explicite du propriétaire)

Les profils `REGISTERED_NURSE`, `MIDWIFE` et `ANESTHETIST` restent des profils
professionnels du rôle unique `NURSE`. Ils ne créent ni nouveau rôle, ni
second moteur RBAC, ni affectation de service concurrente. Tous conservent le
socle Soins du rôle `NURSE`; le menu `/care` porte donc toujours le libellé
« Soins », quel que soit le profil.

Les espaces spécialisés sont des menus indépendants, visibles uniquement par
permission : `/maternity`, `/anesthesia` et `/surgery`. Le profil `MIDWIFE`
recommande des exceptions individuelles `ALLOW` pour `maternity.*`; le profil
`ANESTHETIST` recommande `anesthesia.*`. Une permission de lecture Chirurgie
n’accorde jamais implicitement `surgery.intervention.create`.

La Maternité réutilise strictement `Patient`, `Episode` et
`EpisodeOrientation` avec la destination `MATERNITY`. Son dossier structuré et
ses actes réellement effectués sont historisés sur le même épisode. Les actes
sont issus de `CatalogItem(module=MATERNITY)` et jamais codés en dur dans Vue.

Une décision de césarienne en Maternité crée une `SurgicalRequest` et une
orientation `MATERNITY -> SURGERY` sur le même épisode. Elle ne crée aucune
`SurgicalIntervention` : l’intervention demeure exclusivement sous le contrôle
des permissions et du workflow Chirurgie.

---

# ADR-068 — Analyses et actes Maternité sélectionnables à la Réception

**Status:** ACCEPTED (2026-09-01 — exigence explicite du propriétaire) ; un acte Maternité
sélectionné n'ouvre plus d'orientation `RECEPTION -> MATERNITY` depuis l'**ADR-177** — la
demande d'analyses (et la demande du bloc, ADR-159) restent créées.

La sélection initiale de la Réception est étendue aux prestations actives et
facturables des modules `LABORATORY` et `MATERNITY`. Cette décision remplace la
restriction « aucune analyse activée » de l’ADR-053. Les prestations restent
issues du référentiel central : leur tarif doit être configuré avant qu’elles
puissent être ajoutées à l’estimation.

Une analyse sélectionnée crée, sur le même `Episode`, une orientation directe
`RECEPTION -> LABORATORY`, une `LabRequest` sans consultation médicale source et
ses `LabRequestItem` avec snapshots du catalogue. Le Laboratoire enregistre les
résultats et leurs valeurs de référence selon l’ADR-063 ; il n’encaisse jamais.

Un acte Maternité sélectionné crée une orientation directe
`RECEPTION -> MATERNITY` sur le même épisode. Les références de césarienne sont
explicitement exclues de cette sélection : elles restent soumises au workflow
sécurisé Maternité vers Chirurgie défini par l’ADR-067. La facturation et tout
paiement restent exclusivement sous le contrôle Réception / Caisse.

---

# ADR-069 — Modèles de contrat et règles de congé configurables

**Status:** ACCEPTED (2026-09-01 — exigence explicite du propriétaire) ; le volet
« Modèles de contrat » (upload local `.docx`/`.pdf` par RH, fusion Word) est
**SUPERSEDED par ADR-071** (2026-09-11) au profit du canevas Super Admin
d'ADR-070. Le volet « règles de congé configurables » (`LeaveBalanceCalculator`,
snapshots de solde) reste pleinement en vigueur, inchangé.

Cette décision remplace uniquement les limites d’ADR-066 qui réduisaient le
contrat à ses champs structurés et interdisaient le calcul des congés faute de
règle validée. Elle ne change pas l’exclusion de la paie : aucun salaire,
CNAPS, IRSA, retenue ou net n’est ajouté.

Les modèles de contrat deviennent une ressource privée et auditée de
l’Administration/RH, protégée par `contract_templates.*`. Chaque modèle est
rattaché à un type de contrat et reçoit un fichier `.docx` ou `.pdf`. Les
variables autorisées sont cataloguées côté serveur ; `{{salaire}}` n’en fait
pas partie puisqu’aucune donnée salariale officielle n’existe. La fusion
automatique est supportée pour Word `.docx`. Un PDF arbitraire reste un modèle
statique : le système ne prétend pas y remplacer du texte de manière non
fiable. Le contrat conserve le modèle choisi et un snapshot des valeurs de
fusion. Remplacer le fichier d’un modèle déjà utilisé crée une nouvelle
version et archive l’ancienne ; un contrat historique continue donc de pointer
vers le fichier et les valeurs qui lui appartiennent.

Les types de congé et permission deviennent des référentiels configurables.
Leur métadonnée centralise : consommation du solde annuel, quota, maximum par
demande, justificatif obligatoire, validation nécessaire et méthode de
décompte (`CALENDAR_DAYS_INCLUSIVE` ou `WEEKDAYS_INCLUSIVE`). Les valeurs
initiales comprennent notamment le congé annuel à 30 jours, conformément à la
demande du propriétaire ; l’Administration peut ensuite modifier la règle
sans changer le code.

La date de demande est toujours produite par le serveur à la création. Le
client transmet uniquement le demandeur, le type, les dates, le motif et les
informations facultatives. `LeaveBalanceCalculator` est l’unique moteur de
durée et de solde, utilisé par l’aperçu, la création et l’approbation. Le solde
ferme déduit uniquement les demandes approuvées ; l’aperçu prévisionnel déduit
aussi les demandes en attente et la demande préparée. Une demande consommant
le quota ne peut traverser deux années : elle doit être scindée afin de ne pas
attribuer arbitrairement les jours à un exercice. L’approbation recalcule sous
verrou transactionnel avant la décision auditée. Les valeurs de règle, quota,
durée et soldes sont conservées comme snapshots pour que toute modification
future du référentiel ne réécrive pas l’historique.

---

# ADR-070 — Canevas de documents administratifs pilotés par le Super Admin

**Status:** ACCEPTED (2026-09-10 — exigence explicite du propriétaire)

Le CDC officiel ne traite pas la gestion de modèles/canevas de documents
administratifs ; cette décision comble ce vide, sans contredire aucune
décision `ACCEPTED` existante.

## Propriétaire et diffusion

Le canevas (contrat, congé, attestation, certificat, lettre, décision,
autre…) est composé une fois sur le portail central `admin.rivo.mg` par le
`SUPER_ADMIN`, dans un éditeur de texte riche multi-page (TipTap), puis
poussé vers **un site choisi à la fois** via l'API sécurisée du site —
exactement le pattern déjà utilisé pour les désignations/tarifs, les
adresses, les mutuelles et le stock (ADR-042/044/045/047), jamais un accès
direct à une base clinique. Chaque site conserve sa propre copie locale
(`document_templates`), reçue via `/api/v1/super-admin/document-templates*`,
authentifiée par `rivo.site-api`, idempotente (`api.idempotent`) et
attribuée à l'acteur distant via `CatalogActor` comme le reste du domaine
catalogue.

Cette décision **n'abroge pas ADR-069** : les modèles de contrat par upload
`.docx`/`.pdf`, locaux au site et gérés par `ADMINISTRATION`
(`contract_templates.*`), restent en place tels quels. Le canevas éditable
est une **deuxième option**, pas un remplacement.

## Type de document libre, contexte de données fixe

`document_type` est une chaîne libre (`CONTRAT`, `CONGE`, `ATTESTATION`,
`CERTIFICAT`, `LETTRE`, `DECISION`, `AUTRE`…) : ajouter une nouvelle
catégorie de document n'exige donc aucune modification de code. En
revanche, `data_context` (`EMPLOYEE_ONLY`, `EMPLOYEE_AND_CONTRACT`,
`EMPLOYEE_AND_LEAVE`) reste un enum PHP fermé, parce qu'il correspond aux
seules sources de données réellement branchées dans le résolveur ; en
ajouter une nouvelle (ex. une future demande de chirurgie) exige
nécessairement du code et n'a donc rien à gagner à être configurable.

## Versionnement et snapshot figé

Remplacer le contenu d'un canevas déjà utilisé par au moins un document
généré crée une nouvelle version (nouvelle ligne, ancienne archivée avec
motif) au lieu de la modifier en place — même mécanisme que
`SaveEmploymentContractTemplateAction` (ADR-069). Chaque document généré
(`generated_documents`) conserve un instantané figé (`rendered_html_snapshot`,
`resolved_variables_snapshot`, `manual_variables_snapshot`, nom et type du
canevas au moment de la génération) : modifier ensuite l'employé, le contrat
ou le canevas ne change jamais un document déjà produit.

## Variables : jamais d'invention, `{{salaire}}` inclus

Le résolveur (`DocumentVariableResolver`) remplit automatiquement les
variables connues depuis `Employee` et, selon `data_context`, depuis
`EmploymentContract` ou `LeaveRequest`. Toute variable présente dans le
canevas mais absente de ce catalogue — `{{salaire}}` en particulier, RIVO ne
stockant aucune donnée de paie (ADR-066/069) — n'est **jamais déduite ni
inventée** : elle est demandée au RH comme champ de saisie manuelle avant la
génération. L'aperçu reste permissif (affiche les champs manquants en
clair) ; la génération elle-même refuse de produire un document tant qu'une
variable détectée n'a pas de valeur réelle, pour ne jamais livrer une clause
administrative silencieusement vide.

## Limite connue : pas de numérotation de page automatique

La génération finale choisit l'aperçu HTML + impression navigateur
(`window.print()`), sans nouvelle dépendance serveur (pas de PDF généré
côté serveur). Le canevas peut définir des sauts de page contrôlés et un
en-tête/pied de page fixe, mais un compteur de page vivant (« Page X sur Y »)
n'est pas fiable en impression navigateur pure (absence de support des
compteurs `@page` en marge dans les moteurs grand public). À revoir avec une
génération PDF serveur si ce point s'avère bloquant.

## Permissions

```text
document_templates.view / create / update / archive / restore / duplicate
```

accordées par défaut uniquement à `SUPER_ADMIN`, et uniquement sur le
déploiement portail (`site.type === 'admin'`) — même garde que
`RolePermissionSeeder::run()` pour tout le reste du catalogue central.
`document_templates.view` est en outre accordée localement à
`ADMINISTRATION` (lecture seule de la copie synchronisée). La génération
elle-même est protégée par :

```text
generated_documents.view / create / print
```

accordées par défaut à `ADMINISTRATION`. Aucune de ces permissions ne
touche `payments.*`, `cash.*` ou `receipts.*` : cette décision ne crée ni
n'affecte aucun encaissement.

---

# ADR-071 — Retrait de l'upload de modèle de contrat au profit du canevas Super Admin

**Status:** ACCEPTED (2026-09-11 — exigence explicite du propriétaire, après
confusion UI/UX constatée entre les interfaces RH et Super Admin)

ADR-069 (upload local `.docx`/`.pdf` par RH, fusion par `ZipArchive`) et
ADR-070 (canevas composé par le Super Admin, fusion HTML) étaient deux
mécanismes indépendants et non coordonnés, tous deux capables de produire un
contrat rempli pour un même employé. Leur coexistence, retenue explicitement
lors d'ADR-070, s'est avérée une source concrète de confusion : un même
besoin pouvait être satisfait par deux parcours distincts, avec deux
historiques séparés et aucune protection contre une double génération.

## Ce qui est retiré

Uniquement le mécanisme d'upload et de fusion Word/PDF, jamais la fiche
contrat elle-même :

```text
EmploymentContractTemplate (modèle, table, migration)
EmploymentContractTemplateRenderer (fusion ZipArchive)
Save/Archive/RestoreEmploymentContractTemplateAction
EmploymentContractTemplatePolicy
EmploymentContractTemplateDataRequest, ArchiveEmploymentContractTemplateRequest
EmploymentContractTemplateController et ses routes /administration/contract-templates
Administration/ContractTemplates/Index.vue
sur EmploymentContract : contract_template_id, template_variables_snapshot,
    le téléchargement du document Word fusionné
permissions contract_templates.* et contracts.download
```

## Ce qui reste, inchangé

`EmploymentContract` demeure la fiche RH du contrat : type, dates, référence,
observation, création/modification/archivage/restauration/export/impression,
et les compteurs qui en dépendent au tableau de bord RH (local et portail
Super Admin). Rien de cela ne faisait doublon avec ADR-070 — seule la
fusion/génération de document l'était.

## Remplacement

Un contrat fusionné et imprimable pour un employé se produit désormais
exclusivement via le canevas Super Admin (ADR-070), contexte de données
`EMPLOYEE_AND_CONTRACT`, qui résout déjà `{{type_contrat}}`, `{{date_debut}}`,
`{{date_fin}}`, `{{fin_periode_essai}}`, `{{reference_contrat}}`,
`{{date_signature}}` depuis ce même `EmploymentContract` — aucune donnée
supplémentaire à faire migrer, le contexte existait déjà avant cette
décision.

Un modèle de contrat déjà composé dans l'ancien système n'est pas converti
automatiquement : au 2026-09-11, `employment_contract_templates` ne contient
aucune ligne sur aucun site (constaté avant retrait), donc aucune donnée
n'est perdue par cette suppression.

---

# ADR-072 — Consommables Soins et sortie de stock indépendante du règlement

**Status:** ACCEPTED (2026-09-10 — enquête terrain et décisions explicites du
propriétaire)

Cette décision confirme le parcours Soins déjà en place et lui ajoute un
circuit manquant. Elle **amende l'ADR-049** sur un point précis et
volontairement étroit.

## Ce que l'enquête terrain confirme sans changement

Le parcours normal reste celui de l'ADR-030 et de l'ADR-053 : le patient
annonce son besoin à la Réception, puis est orienté vers les Soins selon la
désignation choisie. Un patient venu uniquement pour un pansement reste aux
Soins et ne voit aucun médecin : `CareWorkflow::completionMode()` renvoie
`CareCompletionMode::Finish` pour un parcours `CARE_ONLY`, aucune orientation
Médecine n'est créée et l'épisode passe en `PENDING_SETTLEMENT` selon la règle
de l'ADR-054. Aucune sortie médicale fictive n'est fabriquée. Les constantes
relevées à l'arrivée aux Soins restent celles de l'ADR-032/ADR-038 à ADR-041.

## Consommables déclarés par les Soins

Les Soins peuvent utiliser du matériel sur le patient — sparadrap, coton,
compresses, gants. Ce matériel existe déjà comme produit Pharmacie : un
`Medicine` de forme `MedicineForm::ParapharmacyConsumable`, adossé à un
`catalog_item` de type `MEDICINE` et de module `PHARMACY`, avec lots,
péremptions et stock.

Les Soins ne délivrent jamais un médicament ni une ordonnance. Cette
interdiction n'est pas une convention d'interface : `RequestCareConsumablesAction`
n'accepte que la forme `ParapharmacyConsumable`, et le rôle `NURSE` ne reçoit
aucune permission `prescriptions.*`. Toute autre forme pharmaceutique est
refusée côté serveur avec un message explicite.

Une déclaration crée un `CareConsumableRequest` rattaché à l'épisode, à
l'orientation Soins et à la fiche du passage, avec son numéro `DC-NNNNNN`,
ainsi que ses `CareConsumableRequestLine` portant les instantanés du libellé,
du code et de l'unité. La demande **est** la notification : elle apparaît
immédiatement dans la file « Consommables Soins » de l'espace Pharmacie. Les
Soins n'affichent et ne saisissent jamais un prix, exactement comme une ligne
d'ordonnance (ADR-036).

**Un acte et son matériel sont un seul geste.** La première implémentation
séparait « Actes » et « Consommables » en deux étapes portant chacune son
formulaire et son bouton. Le propriétaire a signalé le 2026-09-10 que ce
découpage ne correspond à rien pour un infirmier — un pansement *est* ses
compresses — et qu'une interface trop découpée n'est pas utilisée. Le défaut
était aussi fonctionnel : le matériel saisi sur le second formulaire était
silencieusement perdu si le soignant validait « Enregistrer l'acte et
terminer » sans avoir cliqué le bouton propre à ce formulaire.

Le matériel est donc déclaré dans la **même soumission** que les actes.
`consumables[]` et `consumable_notes` appartiennent à `UpdateCareRecordRequest`,
et `SaveCareRecordAction` appelle `RequestCareConsumablesAction` dans sa propre
transaction, après avoir créé ou mis à jour la fiche. Les deux chemins
d'enregistrement (`record` et `record-and-complete`) transportent donc le
matériel : il ne peut plus être perdu. Il n'existe aucun endpoint autonome de
déclaration ; seule l'annulation d'une demande existante garde le sien.

## Facturation séparée de l'acte

Décision explicite du propriétaire : le consommable est facturé au patient **en
plus** de l'acte. Chaque ligne produit son propre `BillableItem` par
`RecordBillableItemAction`, au tarif résolu côté serveur, et rejoint la facture
du passage tant que celle-ci n'a reçu aucun encaissement (`DRAFT` ou
`VALIDATED` avec `paid_amount = 0`), selon la règle déjà posée par l'ADR-054.
La clé d'idempotence est dérivée de l'UUID de la ligne : une relance ne
facture jamais deux fois le même consommable.

Une erreur financière — prix de vente non configuré, contexte financier de
l'épisode encore en attente, politique Personnel `UNCLASSIFIED` — n'annule
jamais la déclaration ni la notification à la Pharmacie : le consommable est
déjà sur la plaie du patient. La régularisation appartient à la Réception,
comme pour tout acte clinique.

## Matériel habituel proposé par l'acte (amendement 2026-09-10)

Précision du propriétaire après enquête terrain : chercher chaque consommable
dans la liste complète de la Pharmacie n'est pas réaliste au poste de soins.
Un acte doit proposer de lui-même le matériel cohérent, que l'infirmier
confirme ou corrige.

`care_act_consumables` associe donc un acte de soins (`catalog_items`,
`SERVICE` / `CARE`) à un ou plusieurs `Medicine` de forme
`ParapharmacyConsumable`, avec une quantité par défaut et un ordre
d'affichage. Sélectionner l'acte pré-remplit ces lignes dans le bloc
« Matériel utilisé » ; une prestation déjà planifiée par la Réception les
propose dès l'ouverture de la fiche.

Cette association est **une suggestion de saisie, jamais une règle** :

```text
Configuration    -> ce que l'acte propose habituellement
Déclaration      -> ce que l'infirmier confirme avoir réellement utilisé
Sortie de stock  -> ce que la Pharmacie sort effectivement
```

Rien n'est déduit du nom ni du code d'un acte, conformément à l'ADR-052 : une
association absente laisse simplement le bloc vide, l'interface le dit
explicitement et la saisie manuelle reste disponible. Retirer un acte
retire seulement les suggestions qu'il avait apportées **et que l'infirmier
n'a pas modifiées** — une quantité corrigée est une déclaration réelle et
n'est jamais effacée. La quantité saisie par l'infirmier prévaut toujours sur
la quantité par défaut.

La configuration est du référentiel : elle exige `catalog.items.update`
(ADR-024), se fait depuis Administration › Catalogue, est auditée sous
`catalog.care_act_consumables.update`, et ne crée ni mouvement de stock ni
montant. Vider la liste est légitime — ce sont des paramètres, pas des
enregistrements cliniques, et aucune déclaration passée ne les référence.

## Constantes et actes restent facultatifs et à la demande

Confirmation du propriétaire (2026-09-10), déjà couverte par l'ADR-032 et
l'ADR-030 : un patient venu uniquement pour un pansement n'a pas de
constantes à relever, et les actes sont enregistrés au cas par cas. Le
parcours ne l'impose donc pas. L'étape « Constantes » porte le libellé
`facultatif` lorsque `CareWorkflow::recommendsRoutineVitals()` est faux, et la
fiche s'ouvre directement sur « Actes et matériel » quand ni constantes ni
transmission ne sont attendues et qu'aucune fiche n'existe encore — le
soignant arrive là où se trouve réellement son travail, sans traverser des
étapes vides. Aucun champ de constantes n'est jamais rendu obligatoire.

## Amendement de l'ADR-049 — la sortie de stock n'attend pas le règlement

L'ADR-049 impose que la quantité physique d'un lot ne diminue qu'après
paiement intégral ou prise en charge intégrale. Cette règle protège une
délivrance Pharmacie : le produit reste sur l'étagère jusqu'au règlement.

Elle ne peut pas s'appliquer à un consommable déjà utilisé aux Soins.
Conserver en stock une compresse posée sur une plaie rendrait l'inventaire
sciemment faux. Pour ce circuit, et pour lui seul :

```text
Délivrance Pharmacie (ADR-049)   → facture réglée, PUIS sortie de stock
Consommables Soins (ADR-072)     → sortie de stock à la validation Pharmacie,
                                    règlement de la part patient indépendant
```

`ServeCareConsumablesAction` est donc une action distincte de
`DispenseMedicinesAction`, et `CareConsumableRequest` un modèle distinct de
`PharmacyDispense` : la garde « facture intégralement réglée ou prise en
charge » de la délivrance reste intacte, jamais contournée ni assouplie.
`PharmacyDispenseStatus` n'est pas réutilisé pour la même raison.

L'allocation est FEFO et **n'entame jamais** une quantité déjà réservée pour
une ordonnance ou une vente comptoir. Si le stock enregistré ne couvre pas la
quantité déclarée, l'opération entière est refusée avec un message nommant le
manquant : le pharmacien ajuste d'abord son inventaire (`stock.adjust`). Aucun
solde négatif, aucune sortie partielle silencieuse.

Chaque sortie crée un `PharmacyStockMovement` immuable de type `DISPENSING`
avec origine, destination, motif et `source_key` unique, plus une
`CareConsumableAllocation` reliant la ligne, le lot et le mouvement. Une
seconde sortie partielle sur la même ligne poursuit la séquence de
`source_key` au lieu de la recommencer.

La Pharmacie n'encaisse toujours rien : l'ADR-013 et l'ADR-012 restent
inchangés. Servir une demande Soins ne crée ni paiement, ni reçu, ni
mouvement de caisse.

## Annulation, jamais suppression

Une demande déclarée n'est jamais supprimée (ADR-010). Elle peut être annulée
avec un motif obligatoire **tant qu'aucun lot n'a bougé** : les `BillableItem`
encore `PENDING` sont annulés par `CancelBillableItemAction`, ceux déjà portés
sur une facture ne sont pas détricotés ici — seule la Réception/Caisse touche
un montant facturé. Une fois la demande servie, la correction relève d'un
ajustement de stock audité côté Pharmacie.

## Permissions

```text
care_consumables.view      NURSE, PHARMACY
care_consumables.request   NURSE
care_consumables.cancel    NURSE
care_consumables.serve     PHARMACY
```

`care_consumables.request` est vérifiée deux fois : `UpdateCareRecordRequest`
interdit le champ à un compte qui ne l'a pas, et `SaveCareRecordAction` refuse
la soumission par `AuthorizationException` — l'interface n'est jamais la seule
protection.

`care_consumables.serve` est volontairement distincte de `pharmacy.dispense` :
servir une demande Soins ne délivre pas une ordonnance et n'exige aucune
facture réglée. Consulter la file est séparé de la servir, afin qu'un compte
autorisé puisse suivre la consommation des services sans pouvoir sortir du
stock. Conformément à l'ADR-064, ces attributions figurent dans
`RolePermissionSeeder::GRANTS` pour la création d'un nouveau site, mais un site
déjà en production doit les ajouter sans rejouer ce seeder, qui écraserait les
socles personnalisés depuis le portail.

Les écritures sont auditées sous `care.consumables.request`,
`care.consumables.cancel` et `pharmacy.care_consumables.serve`.

---

# ADR-073 — Saisie en cours conservée côté serveur pour la fiche de soins

**Status:** ACCEPTED (2026-09-10 — exigence explicite du propriétaire)

Constat du propriétaire : tout ce qu'un soignant saisissait dans la fiche de
soins disparaissait après une actualisation de la page. Rien n'était persisté
avant le clic d'enregistrement, alors qu'un relevé de constantes, une
sélection d'actes et une déclaration de matériel peuvent représenter plusieurs
minutes de travail au chevet du patient. L'exigence est explicite : la saisie
doit rester tant que l'utilisateur ne l'annule pas.

`care_record_drafts` conserve donc cette saisie en cours, enregistrée
automatiquement environ une seconde après la dernière frappe. Elle est
restaurée telle quelle au rechargement de la page, avec un bandeau qui indique
son état et propose « Annuler la saisie ».

## Pourquoi côté serveur et non dans le navigateur

`localStorage` aurait été plus simple mais est inacceptable ici. Un poste de
soins est partagé : le stockage navigateur n'est pas cloisonné par compte, si
bien que la saisie non validée d'un soignant serait restituée au suivant, qui
pourrait l'enregistrer sous sa propre identité. L'ADR-032 attribue chaque acte
à la personne qui l'a réalisé ; ce mélange d'identités est exactement ce
qu'il faut empêcher. Des données cliniques resteraient de plus lisibles sur le
poste après la déconnexion.

Le brouillon est donc rattaché à la fois au passage **et** à son auteur
(`unique(episode_orientation_id, created_by)`) :

```text
Infirmier A saisit    -> son brouillon, visible de lui seul
Infirmier B ouvre     -> aucun brouillon restauré, il saisit le sien
```

## Ce que le brouillon n'est pas

Ce n'est jamais une donnée clinique. Il n'est lu par aucun module, n'apparaît
dans aucun dossier, ne produit ni `CareRecord`, ni acte, ni prestation
facturable, ni demande à la Pharmacie. Il conserve volontairement des valeurs
que la validation refuserait — une tension `145/` en cours de frappe doit
survivre à une actualisation.

Il est donc explicitement **disposable** :

```text
enregistrement réel de la fiche -> brouillon supprimé
« Annuler la saisie »           -> brouillon supprimé
```

Un brouillon encore présent est par construction postérieur au dernier
enregistrement : il ne peut jamais écraser des données déjà consignées.

Le brouillon est un instantané **fidèle** de ce que le soignant a à l'écran :
un champ vide doit revenir comme une chaîne vide. Le middleware Laravel
`ConvertEmptyStringsToNull` transformait chaque champ vide en `null`, et ce
`null` restitué cassait les `.trim()` du formulaire — la dernière étape de la
fiche s'affichait blanche. La route du brouillon est donc explicitement
exclue de ce middleware dans `bootstrap/app.php`, et la restauration côté
navigateur conserve en plus le type attendu par le formulaire (`null` devient
`''` pour un champ texte, `[]` pour une liste) : le transport ne décide jamais
des types du formulaire.

Les clés acceptées sont limitées à celles de la fiche et le payload est borné
en taille : un brouillon ne doit pas devenir un canal de stockage arbitraire.
Les champs interdits de la fiche (`hospitalized_at`, `discharged_at`,
`orient_to_medicine`…) sont rejetés ici comme ils le sont à l'enregistrement.

L'écriture exige le même droit que la fiche elle-même — `care.create` ou
`care.update` selon qu'une fiche existe — et uniquement pendant une prise en
charge `IN_PROGRESS`. Contrairement au reste du module, ce modèle n'est
**pas** audité : il est réécrit toutes les quelques secondes pendant la
frappe et noierait le journal d'audit sous des lots de saisie. Ce qui est
audité reste le contenu réellement enregistré, sans changement.

## Étendu à la consultation Médecine (2026-09-10)

Le propriétaire a demandé la même garantie pour la consultation Médecine :
tout ce qui est saisi reste, même après actualisation, et n'est supprimé que
par une annulation explicite.

`consultation_drafts` applique le même contrat que `care_record_drafts` —
rattaché au passage **et** à son auteur, jamais audité, supprimé dès le vrai
enregistrement ou l'annulation, clés bornées et route exclue de
`ConvertEmptyStringsToNull`. Son payload est une **carte de sections** (une
par formulaire de l'assistant : `consultation`, `diagnosis`, `prescription`,
`care_order`, `lab_request`, `imaging_request`, `referral`,
`surgical_referral`, `discharge`), car un même écran en porte plusieurs.

Seules les sections réellement modifiées sont enregistrées : un formulaire
intact stockerait ses valeurs par défaut et ressemblerait à une saisie.

Le mécanisme est désormais partagé par le composable
`resources/js/composables/useFormDraft.js` plutôt que recopié : c'est la
deuxième occurrence du même besoin, et la fiche Soins pourra l'adopter sans
changer son comportement. Les formulaires de correction ouverts en fenêtre
modale (annulation d'un diagnostic, retrait d'une ligne d'ordonnance) ne sont
volontairement pas conservés : ils sont courts, ouverts délibérément, et une
actualisation referme la fenêtre de toute façon.

## Constantes et actes facultatifs

Cette décision confirme aussi, sans la modifier, la règle de l'ADR-032 : un
patient venu seulement pour un pansement n'a aucune constante à relever.
L'étape « Constantes » porte le libellé `facultatif` lorsque
`CareWorkflow::recommendsRoutineVitals()` est faux, et la fiche s'ouvre
directement sur « Actes et matériel » quand ni constantes ni transmission ne
sont attendues et qu'aucune fiche n'existe encore. Aucun champ de constantes
n'est jamais rendu obligatoire.

---

# ADR-074 — Champs du DOSSIER MÉDICAL papier absents de l'application

**Status:** ACCEPTED (2026-09-10 — formulaire papier fourni par le propriétaire)

Le formulaire papier « DOSSIER MÉDICAL » de la clinique a été confronté champ
par champ à la base. La quasi-totalité était déjà couverte : identité,
situation maritale, nombre d'enfants, profession, coordonnées
(`patients`) ; groupe sanguin, taille, poids, IMC, allergies, tabac (fiche
Soins, ADR-032) ; diagnostic (`diagnoses`) ; motif de transmission
(`care_records`). Trois éléments manquaient réellement.

## Traitements actuels

`consultation_current_treatments` enregistre ce que le patient déclare déjà
prendre : nom, posologie, précision, ligne par ligne. Rattaché à la
**Consultation** et non au Patient : c'est ce qui était vrai à cette
rencontre, et cela change d'une visite à l'autre — même raisonnement que la
personne à contacter portée par l'Épisode (ADR-034).

C'est une donnée **déclarative, jamais une prescription** : aucune référence
au référentiel Pharmacie, aucune réservation de lot, aucun prix, exactement
comme une ligne d'ordonnance manuelle (ADR-036, ADR-037). Un patient peut
citer un médicament acheté ailleurs ou absent du référentiel.

Contrairement à un acte réalisé, une déclaration est **corrigible** : la
liste est remplacée à chaque enregistrement, le médecin la réécrivant au fur
et à mesure que l'interrogatoire la précise. Omettre le champ ne l'efface pas
— un enregistrement qui ne porte pas le bloc laisse intact ce qui a été
déclaré.

## Antécédents personnels et familiaux

Le formulaire lit séparément l'histoire du patient et celle de sa famille :
deux lectures cliniques différentes. `patient_antecedents.type`
(`PatientAntecedentType` : `PERSONAL` | `FAMILIAL`) porte la distinction.

Les lignes enregistrées avant cette distinction sont classées `PERSONAL` :
c'est ce que collectait le formulaire sur lequel elles ont été saisies.
Deviner lesquelles étaient familiales aurait inventé un fait clinique. Une
requête sans type vaut donc `PERSONAL`, pour la même raison.

L'ajout reste régi par `patients.medical_history.manage` et passe par
l'unique point d'entrée `PatientController::storeAntecedent()` (ADR-054) ;
un antécédent demeure une donnée permanente du Patient, jamais un champ de
Consultation.

## Lieu de naissance

`patients.birth_place` complète la ligne « Date de Naissance … Lieu » du
formulaire. Nullable : un dossier existant n'a pas à être bloqué faute de
cette information.

## Hors périmètre, volontairement

« Motif d'hospitalisation », « Entrée hospitalisation » et « Sortie » du même
formulaire ne sont pas ajoutés : l'ADR-032 pose que ces champs ne
s'affichent pas avant l'existence du module Hospitalisation, l'entrée devant
provenir du workflow d'admission après décision médicale et la sortie de
l'action de sortie médicale.

La grille d'examen clinique par appareil demandée pour l'étape « Examen
clinique » n'est pas non plus créée : le formulaire papier fourni n'en
contient aucune, et choisir les appareils à examiner est une décision
médicale qui doit venir du document de la clinique, non de l'interface.

---

# ADR-075 — Intention d’orientation préparée pendant la Prescription

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire)

La Consultation Médecine reste composée de l’interrogatoire puis des
constatations de l’examen clinique. Le médecin prépare ensuite la suite du
parcours dans l’étape Prescription, avant de prescrire des médicaments ou des
soins : sortie médicale, hospitalisation, Maternité, Chirurgie, Pédiatrie ou
transfert externe.

Cette sélection est volontairement un geste simple et facultatif. Elle écrit
uniquement `Consultation.decision` et ne crée jamais à elle seule :

```text
EpisodeOrientation
SurgicalRequest
MedicalDischarge
acte de Chirurgie
acte de Maternité
```

La création réelle reste une action explicite de l’étape Décision, avec les
données exigées par le service destinataire. Les actes spécialisés sont
ensuite saisis dans leur module propriétaire ; une Consultation Médecine ne
devient jamais un raccourci pour enregistrer un acte de Chirurgie ou de
Maternité.

Le choix préparatoire possède un endpoint dédié afin de ne pas réécrire le
motif, l’examen clinique ou les traitements actuels. Il exige
`consultations.update`, puis la permission effective de la destination :

```text
Sortie médicale        medical_discharge.create
Hospitalisation        hospitalization.request
Maternité              maternity.request
Chirurgie              surgery.request
Pédiatrie              pediatrics.request
Transfert externe      transfer.request
```

Le filtrage Vue sert uniquement l’ergonomie. Le serveur répète toujours le
contrôle de permission et rejette une décision forgée par le navigateur.

---

# ADR-076 — Machine à états réelle des étapes de Consultation

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire)

Jusqu'ici l'avancement du parcours Médecine n'existait que dans le
navigateur : `Medicine/Show.vue` déduisait « cette étape est terminée » de
la simple présence d'une donnée. Cette déduction ne peut pas distinguer
deux situations cliniquement différentes :

```text
étape que le médecin a jugée non nécessaire  ≠  étape simplement vide
étape ouverte et lue                         ≠  étape réalisée
```

Elle produisait aussi des conclusions fausses : l'étape Prescription
s'affichait terminée dès qu'une ordonnance existait, même sans aucun
diagnostic enregistré.

## L'état appartient au serveur

`consultation_steps` (une ligne par consultation et par étape, contrainte
unique) porte le statut réel de chaque étape :

```text
NOT_STARTED   aucune décision prise — l'absence n'est jamais une décision
IN_PROGRESS   « Enregistrer » : la saisie est conservée, rien n'est validé
COMPLETED     « Enregistrer et continuer » : le médecin valide l'étape
SKIPPED       « Passer cette étape » : jugée non nécessaire pour ce patient
```

Une ligne absente se lit `NOT_STARTED`. `ConsultationStep` (les sept étapes
`dossier`, `consultation`, `examen`, `paraclinique`, `diagnostic`,
`ordonnance`, `decision`) reprend exactement les segments d'URL déjà
autorisés par `medicine.orientations.step` : la route, le statut persisté
et le stepper parlent donc un seul vocabulaire au lieu de trois qui
divergent. `ConsultationWorkflow` est la source unique de ce que la
consultation doit encore ; le stepper n'affiche que ce qu'elle renvoie et
ne recalcule plus rien.

Le statut est une **état**, jamais un journal : la ligne est mise à jour,
et l'auditabilité est portée par la ligne elle-même (`completed_by`,
`completed_at`, `skip_reason`) et par le trait `Auditable`.

## Enregistrer n'est pas valider

`ResolveConsultationStepAction` est le seul chemin vers `COMPLETED` et
`SKIPPED`, et n'est jamais appelée comme effet de bord d'un enregistrement :
sauver du contenu laisse l'étape `IN_PROGRESS`. Une étape ne devient
`COMPLETED` que si son minimum propre est réellement atteint — un
interrogatoire renseigné, des constatations d'examen saisies, un diagnostic
actif, un acte de décision réel. Ré-enregistrer une étape déjà résolue ne
la rétrograde jamais : une correction se revalide explicitement.

Seules `paraclinique` et `ordonnance` sont « passables » : beaucoup de
consultations ne nécessitent ni examen complémentaire ni prescription.
`diagnostic` et `decision` ne le sont pas — les déclarer non nécessaires
laisserait clôturer une rencontre sans conclusion clinique. Le motif d'un
saut est facultatif : « aucun examen complémentaire » est un énoncé
complet, et exiger une phrase pousserait au remplissage.

## Étapes sans objet pour ce patient

Un patient venu uniquement pour une échographie, un ECG ou une analyse n'a
ni interrogatoire ni examen physique à consigner. `isRelevant()` le décide
une fois côté serveur à partir du parcours snapshoté (`MEDICINE_DIRECT` +
module `IMAGING`/`LABORATORY`, ADR-030), jamais dans Vue. Une étape sans
objet n'est jamais exigée à la clôture et n'est jamais présentée comme une
omission — mais elle reste atteignable : la règle est un raccourci, pas un
verrou, et une rencontre qui devient une vraie consultation peut être
documentée.

## Statut de la consultation et lecture seule après clôture

`consultations.status` remplace la déduction « clôturée parce qu'une
`MedicalDischarge` existe », qui confondait une décision possible avec la
seule façon de terminer une rencontre :

```text
DRAFT | IN_PROGRESS | COMPLETED | CANCELLED
```

`CompleteConsultationAction` refuse la clôture tant qu'une étape
**pertinente** n'est pas résolue, et liste précisément lesquelles. Elle
n'exige volontairement ni analyse, ni imagerie, ni prescription, ni
hospitalisation : aucune ne concerne toutes les rencontres, et les exiger
pousserait à fabriquer des actes. Elle est idempotente — un double clic ne
produit pas une seconde clôture et ne déplace pas `completed_at`.

Après clôture, `Consultation::isEditable()` devient faux et tous les
chemins d'écriture ordinaires refusent : `SaveConsultationAction`,
`ResolveConsultationStepAction`, et `MedicineDossierPresenter` qui cesse
d'exposer les capacités d'écriture. Conformément à l'ADR-010, une
correction ultérieure exigera son propre mécanisme tracé ; rien n'est
réécrit silencieusement.

## Migration non destructive

Aucune colonne n'est supprimée ni réécrite. Le backfill ne lit que des
faits déjà enregistrés : une étape dont le contenu existe déjà est marquée
`COMPLETED` afin que le stepper ne prétende pas que le travail passé d'un
médecin n'a pas eu lieu ; une étape sans rien d'enregistré reste
`NOT_STARTED` plutôt qu'inventée comme sautée. L'instant réel et l'auteur
étant inconnus pour ces lignes historiques, `completed_by` reste `null` :
attribuer l'acte à un utilisateur inventerait un acteur.

## Permissions

Aucune permission nouvelle. Valider une étape et clôturer la consultation
relèvent de la même autorité que l'écrire : `consultations.update`, en plus
du module Médecine et d'une orientation `IN_PROGRESS`. Le filtrage Vue sert
uniquement l'ergonomie ; le serveur répète toujours le contrôle.

---

# ADR-077 — Examen clinique semi-structuré par appareil

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire, qui
fournit la liste des appareils)

Cette décision **amende l'ADR-074**, qui refusait explicitement de créer
cette grille :

> « La grille d'examen clinique par appareil demandée pour l'étape "Examen
> clinique" n'est pas non plus créée : le formulaire papier fourni n'en
> contient aucune, et choisir les appareils à examiner est une décision
> médicale qui doit venir du document de la clinique, non de l'interface. »

Le motif du refus était l'absence de liste validée, pas l'inutilité de la
grille. Le propriétaire fournit désormais cette liste explicitement : la
condition qui manquait est levée, et la liste vient bien d'une décision
médicale et non de l'interface. Le reste de l'ADR-074 (traitements actuels,
antécédents personnels/familiaux, lieu de naissance, exclusion des champs
d'hospitalisation) est inchangé.

## Le problème que le texte libre ne peut pas résoudre

`consultations.clinical_exam` conservait tout l'examen dans un seul bloc de
HTML. Trois faits cliniquement différents y étaient indiscernables :

```text
appareil examiné et normal   ≠   appareil examiné avec une anomalie
appareil examiné et normal   ≠   appareil jamais examiné
```

Rien n'était relisible, comptable ni vérifiable, et un lecteur ultérieur ne
pouvait pas savoir si un appareil absent du texte avait été trouvé normal ou
simplement pas regardé.

## Trois états, jamais deux

`clinical_examination_findings.status` porte exactement :

```text
NOT_EXAMINED   le médecin n'a rien dit de cet appareil
NORMAL         examiné, sans anomalie
ABNORMAL       examiné, anomalie constatée — constatations obligatoires
```

La règle centrale : **une absence de saisie n'est jamais un examen normal**.
Aucun appareil n'est pré-coché, la valeur par défaut est `NOT_EXAMINED`, et
un appareil n'affiche `NORMAL` que parce que le médecin l'a explicitement
choisi.

Un appareil `NOT_EXAMINED` **ne stocke aucune ligne** : l'absence est ce qui
porte le sens. `ClinicalExamination::systems()` est le seul endroit qui
décide ce que signifie une ligne absente, et cela ne signifie jamais normal —
la grille renvoyée est toujours complète, les appareils non examinés inclus,
afin que l'écran puisse dire ce qui n'a pas été examiné au lieu de laisser un
vide à interpréter.

`ABNORMAL` sans constatations est refusé côté serveur, deux fois :
`UpdateMedicineClinicalExamRequest` pour le message d'interface, et
`SaveClinicalExaminationAction` parce qu'une Action est atteignable
autrement que par sa FormRequest. Une anomalie sans description apprend au
lecteur que quelque chose ne va pas, sans dire quoi.

`NORMAL` n'exige aucune description et n'en conserve aucune : un texte saisi
avant que le médecin ne retienne « Normal » contredirait le statut stocké à
côté de lui.

## Aucune constante vitale dans ce formulaire

`clinical_examinations` ne porte ni tension, ni fréquence cardiaque, ni
SpO₂, ni température, ni poids, ni taille, ni IMC. Ces valeurs sont
relevées une seule fois par les Soins sur `care_records` et parviennent au
médecin en lecture seule via `CareRecordReadModel` (ADR-054), depuis le
« Contexte clinique ». Les ressaisir ici produirait une seconde version
d'une mesure que personne n'a prise deux fois, et le médecin ne peut ni
modifier ni écraser la valeur historique saisie par les Soins.

Un éventuel recontrôle des constantes devra créer un **nouveau relevé** ;
il ne modifiera jamais la mesure d'origine.

## Les notes libres restent, à leur place

`consultations.clinical_exam` n'est ni supprimée, ni migrée, ni dupliquée :
elle devient le domicile des « Notes cliniques complémentaires », affichées
avec le même éditeur riche qu'avant et toujours relues par la page
« Détail du passage ». Créer une colonne `complementary_notes` sur la
nouvelle table aurait donné deux domiciles à la même donnée et imposé une
migration de contenu.

Ce champ devient **facultatif** : il n'est plus la seule trace de l'examen.
Par conséquent la règle de validation de l'étape change aussi — l'étape
« Examen clinique » n'est plus validable parce qu'un texte est rempli, mais
parce qu'un examen a réellement eu lieu : un état général, un état de
conscience ou au moins un appareil réellement examiné. Une fiche entièrement
vide ne vaut toujours rien.

## Modèle de données

```text
clinical_examinations          un par consultation (contrainte unique)
    general_condition          GOOD | FAIR | ALTERED, nullable
    consciousness_status       NORMAL | ALTERED | OTHER, nullable
    consciousness_details      exigé seulement pour OTHER
    general_observation        facultatif
    examined_by / examined_at

clinical_examination_findings  une ligne par appareil réellement renseigné
    system_code                CARDIOVASCULAR … OTHER
    status                     NORMAL | ABNORMAL (jamais NOT_EXAMINED)
    findings                   obligatoire si ABNORMAL
    sort_order
```

La liste des appareils est un **enum PHP**, pas une table de paramétrage :
c'est un vocabulaire clinique, et en ajouter un change le sens des dossiers
déjà enregistrés — cela relève d'une migration relue, pas d'un écran de
configuration. Les lignes référencent l'appareil par `system_code`, si bien
qu'une réorganisation ultérieure ne réécrit jamais un examen déjà consigné.

Le stockage est relationnel et non JSON, contrairement au précédent
`AnesthesiaRecord.consultation_data` (ADR-048) : celui-ci conserve un texte
libre par appareil, sans distinction non-examiné/normal/anormal — exactement
le défaut corrigé ici — et une ligne par appareil permet l'audit par
`Auditable` ainsi que la relecture d'un statut sans désérialiser un blob.

L'examen est une déclaration **corrigible**, jamais un acte append-only : le
médecin la réécrit au fil de l'examen, et une correction met à jour la ligne
plutôt que d'en empiler une seconde. Qui a examiné et quand vivent sur le
parent ; le trait `Auditable` conserve chaque transition.

## Interface

Un accordéon compact par appareil, **un seul ouvert à la fois** : neuf blocs
dépliés feraient de l'étape un mur de zones de texte. Chaque ligne fermée
porte son statut en badge discret — vert `Normal`, ambre `Anormal`, gris
`Non examiné` — de sorte que l'examen entier se lit sans rien ouvrir.
Choisir « Anormal » ouvre immédiatement la ligne, le champ de constatations
devenant obligatoire.

## Permissions

Aucune permission nouvelle : `consultations.update`, comme le reste de
l'écriture d'une consultation. Le filtrage Vue sert l'ergonomie ; le serveur
répète toujours le contrôle.

---

# ADR-078 — Interrogatoire semi-structuré et information rapportée

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire)

L'étape Interrogatoire tenait dans un unique éditeur riche, « Motif et
histoire clinique ». Le motif de venue y était noyé dans le récit : il
n'était donc exploitable ni dans l'historique des consultations, ni dans un
résumé de dossier, ni dans une recherche. Cette décision sépare ce qui doit
être court et interrogeable de ce qui doit rester narratif, sans transformer
l'étape en formulaire lourd.

## Ce qui devient structuré, et ce qui ne le devient pas

```text
chief_complaint       court, exploitable  — la raison réelle de la venue
symptom_onset         texte libre court   — « depuis 3 jours »
evolution             enum, facultatif    — amélioration/stable/aggravation/fluctuante
reason                éditeur riche       — le récit, inchangé
additional_notes      facultatif          — ce que le récit ne porte pas
```

`reason` est **conservée** comme unique domicile du récit : créer un second
champ narratif aurait dupliqué la même donnée. Seuls le motif et deux
repères en sortent.

`chief_complaint` ne reprend jamais le type de prestation demandée
(« Consultation de médecine générale ») : cette information vient déjà de
l'`EpisodeServiceRequest` et s'affiche dans l'en-tête. Le motif décrit ce
dont le patient se plaint.

Seuls le motif et l'histoire sont exigés, et **uniquement pour valider
l'étape** : « Enregistrer » n'impose rien, afin qu'un travail en cours ne
soit jamais bloqué (ADR-076). L'ancienneté, l'évolution, les traitements et
les informations nouvelles restent tous facultatifs.

## Traitements : trois notions distinctes

```text
patient_treatments                traitement habituel du dossier permanent
consultation_current_treatments   ce que le patient déclare prendre aujourd'hui
prescriptions                     ce que le médecin prescrit (autre étape)
```

`patient_treatments` est créée parce que le dossier n'avait aucun modèle de
traitement chronique : présenter une ancienne ordonnance comme un traitement
habituel aurait affirmé un fait clinique que personne n'a constaté. Elle est
**déclarative** — aucun `catalog_item`, aucun lot, aucun prix, exactement
comme une ligne d'ordonnance manuelle (ADR-036/037).

Les traitements connus sont **affichés avant toute saisie** : on ne demande
jamais de les ressaisir. L'interrogatoire pose seulement la question utile —
« le patient signale-t-il un changement ? » — et, si oui, exige sa
description. La consultation conserve cette déclaration même si le dossier
permanent est corrigé ensuite : l'histoire n'est pas réécrite.

## L'information rapportée ne modifie jamais le dossier en silence

Une allergie, un antécédent ou un traitement habituel révélé pendant
l'entretien est **toujours** conservé sur la consultation
(`reported_allergies`, `reported_antecedents`,
`reported_habitual_treatments` — des instantanés JSON de ce qui a été dit
ce jour-là).

Le porter au dossier permanent est un **second acte explicite** : une case à
cocher par ligne, et la permission `patients.medical_history.manage`. Un
compte qui ne l'a pas peut quand même consigner ce que le patient a dit ;
il reçoit un message qui le lui dit, et la promotion est refusée côté
serveur — jamais seulement masquée dans l'interface. La promotion réutilise
les points d'entrée existants (`RecordPatientAllergyAction`,
`RecordPatientAntecedentAction`, ADR-054) et ignore un doublon déjà présent
au lieu de l'empiler.

## Omettre n'efface pas

Conformément à l'ADR-074, un enregistrement qui ne porte pas un bloc laisse
ce bloc intact : `current_treatments`, `reported_allergies`,
`reported_antecedents` et `reported_habitual_treatments` ne sont réécrits
que lorsqu'ils sont réellement soumis. Les rendre obligatoirement présents
aurait rendu l'endpoint cassant pour tout appelant partiel, sans rien
protéger.

## Ce qui n'entre pas dans cette étape

Aucune constante vitale (Soins, ADR-054), aucune constatation d'examen
physique (ADR-077), aucun diagnostic, aucune prescription. L'interrogatoire
consigne ce que le patient rapporte, avant tout examen.

## Permissions

Aucune permission nouvelle. `consultations.update` pour écrire
l'interrogatoire ; `patients.medical_history.manage` — déjà existante — pour
la seule promotion au dossier permanent.

---

# ADR-079 — Décision paraclinique explicite, en tête de son étape

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire)

**Amendement du même jour :** la question était d'abord posée à la fin de
l'Examen clinique. Le propriétaire a constaté que l'écran d'examen s'en
trouvait chargé — quatre cartes pour un seul geste clinique — et a demandé
son retrait de cet écran. Elle est donc désormais posée **en tête de l'étape
Paraclinique**, c'est-à-dire au début de l'étape qu'elle gouverne, avec son
endpoint dédié `POST /medicine/orientations/{orientation}/complementary-exams`.

Le fond est inchangé : répondre « Non » résout l'étape en `SKIPPED` et mène
au Diagnostic sans faire traverser un écran vide ; répondre « Oui » ouvre la
sélection des examens. Seul l'emplacement de la question a bougé. La
conclusion diagnostique, elle, reste dans l'Examen clinique (ADR-080).

Le parcours imposait une étape Paraclinique à chaque consultation, y compris
lorsqu'aucun examen complémentaire n'était nécessaire — c'est-à-dire le cas
le plus fréquent. Le médecin devait ouvrir un écran, n'y rien faire, puis en
sortir.

La décision « des examens complémentaires sont-ils nécessaires ? » est en
réalité la **conclusion de l'examen clinique** : elle est donc posée là où
elle se prend, et non dans une étape à elle seule.

## Trois états, jamais deux

`clinical_examinations.complementary_exams_required` est un booléen
**nullable** :

```text
null    le médecin n'a pas encore répondu — aucun bouton pré-sélectionné
false   aucun examen nécessaire
true    des examens sont nécessaires
```

Le statut d'étape seul ne pouvait pas porter cette information : il confond
« pas encore décidé » et « oui, mais rien encore commandé ». Une seule
colonne additive suffit ; les demandes elles-mêmes restent intégralement dans
`lab_requests` et `imaging_requests`, jamais recopiées ici.

## Ce que « Non » déclenche

L'étape Paraclinique passe à `SKIPPED` — le statut qui existait déjà et qui
signifie exactement « déclarée non nécessaire » —, avec auteur, date et
motif, puis le médecin est envoyé directement au Diagnostic.

`SKIPPED` n'est ni `NOT_STARTED`, ni « résultat normal », ni « examen
absent ». Le stepper l'écrit : sous Paraclinique il affiche « Non
nécessaire », et « N examens demandés » dans le cas contraire — une note que
le serveur calcule (`ConsultationWorkflow::paraclinicalNote()`), jamais
l'interface.

## Ce que « Oui » déclenche

L'étape reste ouverte et le médecin est conduit vers Paraclinique, où il
sélectionne les examens dans les catalogues **existants** : `catalog_items`
de module `LABORATORY` pour les analyses, `IMAGING` pour l'ECG et
l'échographie (ADR-063). Aucune seconde liste n'est créée, aucun résultat
n'est saisi ni affiché dans l'examen clinique : la carte décide et oriente,
les modules propriétaires gardent la réalisation et les résultats.

## Changer d'avis sans rien perdre

Passer de « Oui » à « Non » alors que des demandes sont parties est
légitime — un médecin peut reconsidérer. Faire disparaître une demande ne
l'est pas.

```text
demande sans résultat   → CANCELLED, avec auteur, date et motif
demande avec résultat   → le changement est REFUSÉ, rien n'est touché
```

Aucune suppression physique (ADR-010) : la ligne, son auteur et son heure
restent en base et dans l'audit ; elle quitte seulement l'écran Paraclinique
et cesse de compter comme demande en attente. Le retrait exige une
confirmation explicite du navigateur **et** est revérifié côté serveur —
l'interface n'est jamais la seule protection.

Jusqu'ici une demande transmise était définitive : il n'existait aucune
annulation. `lab_requests` et `imaging_requests` reçoivent donc
`cancelled_at`, `cancelled_by` et `cancel_reason`, additifs et nullables, et
`displayStatus()` renvoie `CANCELLED` en conséquence.

Le chemin inverse est libre : repasser à « Oui » rouvre simplement l'étape.

## Accéder au Diagnostic sans attendre les résultats

Rien n'exige que tous les résultats soient disponibles pour poser un
diagnostic : un diagnostic provisoire est une pratique clinique normale, et
les diagnostics sont append-only (ADR-035), donc le médecin peut revenir le
préciser. La clôture, elle, continue d'exiger que chaque étape pertinente
soit résolue (ADR-076).

## Permissions

Aucune permission nouvelle. Décider et retirer une demande relèvent de
`consultations.update` ; créer une demande garde `laboratory_orders.create` /
`imaging_orders.create`. Laboratoire et Imagerie conservent la saisie de
leurs résultats.

---

# ADR-080 — Diagnostic conclu dans l'Examen clinique

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire, après
arbitrage sur la portée)

Le diagnostic est la conclusion de ce que le médecin vient de constater. Lui
faire traverser un écran séparé pour l'écrire allongeait le parcours sans
rien apporter dans le cas courant.

## La question, et pourquoi elle a trois issues et non deux

`clinical_examinations.diagnosis_ready` est un booléen **nullable** :

```text
null    pas encore répondu — aucun bouton pré-sélectionné
true    le diagnostic peut être posé maintenant
false   diagnostic différé
```

Le propriétaire demandait initialement de **supprimer** l'étape Diagnostic.
Le conflit a été signalé avant implémentation : l'examen clinique précède
l'arrivée des résultats, si bien qu'un médecin venant de commander une NFS
aurait dû conclure avant de la lire, sans écran pour y revenir une fois
l'étape Examen validée. Cela contredisait l'ADR-035, l'ADR-076 et la règle
métier posée par le propriétaire lui-même (« DIAGNOSTIC = conclusion à partir
de l'interrogatoire + l'examen + les résultats complémentaires lorsqu'ils
existent »).

L'arbitrage retenu conserve l'étape tout en la faisant disparaître du chemin
dans le cas courant :

```text
« Oui »           diagnostic saisi dans l'examen → étape Diagnostic COMPLETED
                  → Prescription. L'écran Diagnostic n'est pas traversé.
« Pas maintenant » étape Diagnostic laissée OUVERTE, stepper « Différé ».
                  Le médecin y revient après ses résultats.
```

Répondre « Oui » sans avoir rien enregistré est refusé côté serveur : une
intention n'est pas un diagnostic.

## Un seul chemin d'écriture

`ClinicalDiagnosisEntry.vue` porte la recherche catalogue, la saisie manuelle
et la bascule Hypothèse/Final. Il poste vers l'endpoint `/diagnoses`
**existant** : même validation, même caractère append-only, même audit
(ADR-035). Un diagnostic saisi depuis l'examen est exactement le même acte que
depuis l'étape dédiée, et n'est jamais réécrit.

L'étape Diagnostic conserve ce que la carte de l'examen ne porte
volontairement pas : la frise chronologique, les corrections et les
annulations. L'examen **enregistre** ; l'étape dédiée **relit et corrige**.

## Permissions

Aucune permission nouvelle : `consultations.update` pour répondre,
`diagnoses.create` pour enregistrer — inchangées.

---

# ADR-081 — Étape Diagnostic retirée de l'assistant

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire, après
l'ADR-080)

L'ADR-080 avait conservé l'étape Diagnostic tout en permettant de conclure
depuis l'Examen clinique. Le propriétaire, l'ayant utilisée, demande de la
retirer : la conclusion étant saisie dans l'examen, l'étape ne portait plus
qu'un écran de relecture. Le parcours passe de sept à six étapes :

```text
Dossier → Interrogatoire → Examen clinique → Paraclinique
        → Prescription → Décision
```

## Ce qui est déplacé, et ce qui ne disparaît pas

La liste des diagnostics actifs vit dans la carte Diagnostic de l'Examen,
avec **correction et annulation** — réservées à l'auteur de la saisie, la
règle de l'ADR-035 étant revérifiée côté serveur.

L'historique complet — **diagnostics annulés compris** — rejoint « Contexte
clinique », la surface de relecture déjà partagée. L'ADR-035 exige qu'une
ligne annulée reste visible avec son auteur et sa date ; elle y figure,
barrée et marquée « Annulé », jamais effacée.

## La garantie de clôture change de support, pas d'existence

Une consultation ne pouvait pas se clore sans que l'étape Diagnostic soit
résolue. L'étape disparaissant, `ConsultationWorkflow::blockersForClosure()`
vérifie désormais **directement** l'existence d'un diagnostic actif :

```text
avant   étape Diagnostic non résolue        → clôture refusée
après   aucun diagnostic actif enregistré   → clôture refusée
```

Le contrôle est plus direct qu'avant : il porte sur le fait clinique plutôt
que sur l'état d'un écran.

## Compatibilité des dossiers déjà enregistrés

Le cas `ConsultationStep::Diagnosis` est **conservé** dans l'enum : des lignes
`consultation_steps` portant `step = 'diagnostic'` existent déjà et doivent
continuer à se lire. Il est seulement exclu de l'assistant par
`isWizardStep()` / `wizardCases()`, que `steps()` et `nextStepAfter()`
consomment — aucune migration destructive, aucune ligne réécrite.

L'URL `/medicine/orientations/{uuid}/diagnostic` reste valide et redirige
vers `/examen` : un signet ou un lien ancien mène à l'écran qui porte
désormais la fonction, jamais à une page disparue.

## Permissions

Inchangées : `diagnoses.create` pour enregistrer, `diagnoses.update` pour
corriger ou annuler — et, comme avant, seul l'auteur de la saisie le peut.

## Amendement du 2026-09-20 — l'exigence est maintenue, retirer le dernier diagnostic le dit

Signalement du propriétaire : après avoir saisi « Angine aiguë » à l'examen clinique, « Décision & clôture »
affichait « Aucun diagnostic encore posé » et le bouton « Clôturer » restait grisé ; il demandait de rendre le
diagnostic **facultatif** à la clôture.

Vérification faite, le diagnostic de l'examen **est** repris à la clôture : c'est le même enregistrement
(`consultation->diagnoses`), lu par `hasActiveDiagnosis()` aux deux endroits, même après un « Pas maintenant »
(ADR-095) — un test le fixe. Dans le cas signalé, le diagnostic avait été **annulé par son auteur** (trace
`cancel`, quelques minutes après sa création) : il n'y avait donc plus de diagnostic actif, et le bouton grisé
disait vrai.

Le propriétaire a **choisi de garder l'exigence** : elle est du CDC §33.1 et de cet ADR, et seul un passage
paraclinique seul en est dispensé (ADR-094). Ce qui change est de l'information, pas une règle :

```text
clôture, section 1   « Déjà consigné (à l'examen clinique ou ici) : rien à ressaisir. Il est requis pour clôturer. »
retrait              les deux confirmations (examen, clôture) préviennent, quand c'est le dernier diagnostic
                     d'une vraie consultation, que la clôture en dépend
```

Aucune permission, route ni règle serveur ne change.

---

# ADR-082 — Un seul type de diagnostic à la saisie

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire)

**Amende l'ADR-035** sur un point précis : celle-ci distinguait
« hypothèses diagnostiques » et « diagnostic final », toutes deux
append-only. Le propriétaire a retiré cette distinction de la saisie : ce que
le médecin enregistre est **un diagnostic**, sans qualificatif à choisir.

Le reste de l'ADR-035 est inchangé — append-only, annulation réservée à
l'auteur, trace immuable, aucune suppression physique.

## Ce qui change, et ce qui ne change pas

```text
saisie        plus de bascule Hypothèse / Diagnostic final
              toute nouvelle ligne est enregistrée FINAL
stockage      DiagnosisType conserve ses deux cas
affichage     un badge « Hypothèse » n'apparaît que sur une ligne qui en est
              réellement une
```

`DiagnosisType::Hypothesis` est **conservé** dans l'enum. Des diagnostics
enregistrés comme hypothèses existent déjà : supprimer le cas les rendrait
illisibles, et les réétiqueter « final » affirmerait une certitude que le
médecin n'a jamais exprimée. Aucune migration de données n'est faite.

C'est aussi pourquoi le badge subsiste pour elles : masquer la distinction
sur une ligne ancienne ferait lire une hypothèse comme un diagnostic
confirmé. Les nouvelles lignes, elles, ne portent aucun badge — il n'y a plus
qu'une sorte de diagnostic à afficher.

## Portée

Le serveur continue d'accepter les deux valeurs : aucun contrat d'API n'est
cassé, et le champ reste validé contre l'enum. Simplement, aucune interface
ne produit plus d'hypothèse.

---

# ADR-083 — Voie d'administration et posologie non ambiguë

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire)

## Le problème n'était pas le stockage

`prescription_lines` portait déjà `dosage`, `frequency`, `duration` et
`instructions` en texte libre. Rien dans la base n'obligeait à écrire
« Dose : 500 / Fréquence : 3 / Durée : — » : c'est **le formulaire** qui le
permettait, en offrant des champs nus où « 500 » seul est une saisie valide.

Le correctif est donc à la saisie, pas au schéma. Un montant est toujours
accompagné de son unité, et ce qui est enregistré est la phrase composée —
« 500 mg », « 3 fois/jour », « 7 jours ». Le médecin voit la ligne telle
qu'elle se lira avant de valider.

## Une lacune clinique réelle : la voie

`prescription_lines.route` est ajoutée (nullable). « 500 mg par voie orale »
et « 500 mg en intraveineuse » ne sont pas la même prescription, et une ligne
qui n'en dit rien laisse deviner la personne qui administre.

Les lignes enregistrées avant cette colonne **ne sont pas rétro-remplies** :
elles n'indiquaient pas de voie, et supposer « orale » inventerait une
instruction clinique que personne n'a donnée. L'écran les affiche « Non
précisée » plutôt qu'un tiret muet qui se lirait « rien à signaler ».

La voie reste facultative : toutes les prescriptions n'ont pas besoin de la
préciser, et l'imposer produirait des valeurs de complaisance.

`UpdatePrescriptionAction` conserve la voie déjà consignée lorsque la
correction ne la porte pas (ADR-074).

## Ce qui ne change pas

Prescrire reste une décision, délivrer reste un mouvement de stock. La
validation d'une ordonnance **réserve** en FEFO comme avant (ADR-036) et ne
décrémente aucune quantité physique ; seule la délivrance Pharmacie le fait.
Une ligne manuelle ne réserve toujours rien (ADR-037). Aucune règle de
délivrance n'est touchée.

## Navigation

Le bouton « précédent » de l'assistant écartait les étapes *sans objet* mais
pas celles que le médecin avait *déclarées non nécessaires* : depuis
Prescription, il proposait encore « ← Examens paracliniques » alors que
l'étape portait « Non nécessaire ». Il pointe désormais vers la dernière
étape réellement pertinente — renvoyer quelqu'un vers une impasse qu'il doit
ressortir n'est pas une navigation.

---

# ADR-084 — La conduite à tenir est une donnée, plus une étape

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire)

Cette décision **amende l'ADR-075** (l'intention d'orientation préparée en
Prescription) et **l'ADR-076** (les étapes du parcours). Elle ne modifie ni
l'ADR-035 (append-only, sortie médicale), ni l'ADR-010, ni l'ADR-055.

## Le trajet que l'on supprime

L'étape « Décision » arrivait en dernier, après la prescription. Un médecin
qui savait dès l'examen clinique que le patient devait aller au bloc devait
pourtant :

```text
Examen  → il sait : Chirurgie
        → Prescription
        → Décision
        → re-sélectionner Chirurgie
        → seulement là, le formulaire de demande
```

La destination était choisie deux fois, et le formulaire n'apparaissait
qu'au bout. C'est ce trajet qui disparaît, pas le formulaire : les demandes
Chirurgie, Sortie et orientation de service existaient déjà et fonctionnaient
— seul leur emplacement était faux.

## Le parcours devient

```text
Dossier → Interrogatoire → Examen → Paraclinique → Prescription → Clôture
```

Six étapes, dont la dernière ne demande plus « quelle est la décision ? » :
elle vérifie, signale ce qui manque, et valide.

## L'orientation, décidée là où elle est connue

`consultation_orientations` porte la conduite à tenir :

```text
SELECTED    le médecin a choisi la destination
SUBMITTED   la demande est réellement partie
CANCELLED   il a changé d'avis — la ligne reste
```

`SELECTED` et `SUBMITTED` ne se confondent pas : un médecin qui a choisi
« Chirurgie » sans remplir la demande n'a rien dit au bloc, et la clôture
refuse sur ce seul motif. Six types seulement (`DISCHARGE`,
`HOSPITALIZATION`, `SURGERY`, `MATERNITY`, `PEDIATRICS`, `REFERRAL`), chacun
gardant la permission qui le gouvernait déjà (ADR-075), revérifiée côté
serveur.

Une seule orientation active par consultation, garantie par `active_key` —
le même verrou nullable-unique que `episode_orientations` et
`cash_sessions`, pas une course entre deux onglets.

`consultations.decision` continue d'être écrite à côté : la page « Détail du
passage », l'API Episode et toutes les consultations antérieures restent
lisibles sans rien apprendre de nouveau.

## Deux tables pour deux demandes qui n'en avaient aucune

Chirurgie avait `surgical_requests`, la sortie `medical_discharges`,
Maternité son propre espace. Hospitalisation et Référence/Transfert
n'avaient rien : leur demande vivait en texte libre dans le `reason` d'une
orientation.

`hospitalization_requests` et `medical_referrals` portent donc ce que le
médecin demande — motif, diagnostic d'entrée, résumé clinique, traitement
prévu, service souhaité, établissement destinataire, traitements déjà
administrés, priorité.

Elles ne modélisent **pas** l'admission ni le transfert eux-mêmes. Qui
admet, dans quel lit, qui clôt le séjour, si le patient est réellement
parti : rien de tout cela n'est défini par le CDC, et l'inventer serait
fabriquer du processus clinique. Leur statut s'arrête donc à `REQUESTED` ou
`CANCELLED`, jamais `ADMITTED`. Le module Hospitalisation reste à construire
(ADR-032, ADR-074) ; ces demandes l'attendront sans rien préjuger.

Aucune colonne « site » : chaque site a sa base (ADR-001, ADR-025), donc le
site est implicite. Hospitaliser ailleurs est une Référence/Transfert, une
autre orientation avec son propre enregistrement.

Maternité et Pédiatrie n'obtiennent pas de table : la première a déjà son
espace qui consomme l'orientation, la seconde n'a aucun workflow défini. Leur
demande reste portée par `EpisodeOrientation`.

## Changer d'avis, jamais effacer

Tant que la consultation n'est pas clôturée, l'orientation reste modifiable.
Le changement annule ce qui était parti — il ne le supprime jamais
(ADR-010) :

```text
demande non prise en charge → CANCELLED, avec auteur, date et motif
demande déjà prise en charge → le changement est REFUSÉ
```

« Déjà prise en charge » signifie : la Chirurgie a programmé, un service a
accepté le patient, ou une sortie a été prononcée. Dans ces cas Médecine ne
dispose plus de ce travail, et le message le dit au lieu de défaire en
silence ce qu'un autre module a commencé. `EpisodeOrientation::cancel()` et
`SurgicalRequestStatus::Cancelled` sont ajoutés pour cela ; une demande
chirurgicale annulée quitte la file du bloc mais reste en base.

Les orientations annulées restent affichées dans l'écran, barrées : changer
de conduite est un fait clinique, pas une erreur à masquer.

## La clôture devient le seul acte qui termine la rencontre

Enregistrer une sortie médicale terminait immédiatement l'orientation
Médecine et faisait basculer les statuts du passage. La consultation
devenait donc lecture seule à l'instant où le médecin remplissait ce
formulaire — impossible de prescrire, d'imprimer ou de relire ensuite.

`RecordMedicalDischargeAction` enregistre désormais la sortie et son
orientation, sans rien terminer. `CompleteConsultationAction` est le seul
endroit qui complète l'orientation Médecine et porte la sortie sur
l'épisode. « Médecine a vu ce patient » signifie maintenant « la rencontre
est terminée », ce qui est ce que cette notion a toujours voulu dire — la
file Soins (ADR-054) s'appuie sur exactement le même fait.

L'étape Clôture est résolue par la clôture elle-même : demander de la valider
puis de clôturer serait le même geste deux fois.

## Décidée aux trois moments où elle peut l'être

La carte « Suite de la prise en charge » n'appartient pas à l'examen
clinique : elle est présente à l'**Interrogatoire**, à l'**Examen** et à la
**Paraclinique** — les trois moments où, cliniquement, la conduite peut
devenir claire.

Ce n'est pas un confort. Un patient venu uniquement pour une analyse ou une
échographie n'a ni interrogatoire ni examen clinique : ces étapes sont sans
objet pour lui (ADR-076). Si la décision ne se prenait qu'à l'examen, ce
médecin-là n'aurait aucun endroit où conclure, et sa consultation ne pourrait
jamais être clôturée. Pour ce même passage, le diagnostic se consigne
également à la Paraclinique — devant le résultat qu'il vient lire — et non à
un examen clinique qui n'a pas eu lieu.

Les messages d'obstacle nomment en conséquence l'écran que *ce* patient peut
réellement utiliser : « consignez-le à l'examen clinique » pour une
consultation ordinaire, « à l'étape Paraclinique » pour un passage
paraclinique seul.

## L'étape Clôture ne redemande rien

Elle affiche la conduite à tenir comme un fait acquis — une ligne, son
statut, et « Modifier » qui ramène à l'étape où la demande a été saisie. Elle
ne réaffiche pas le motif, le diagnostic, les examens ni l'ordonnance :
recopier l'écran précédent n'est pas une vérification. Ce qu'elle montre en
propre, c'est ce qui manque encore et le chemin pour y retourner.

## Jamais deux fois la même saisie

Les formulaires de demande arrivent remplis de ce qui est déjà consigné :
motif, diagnostics actifs, état général et appareils anormaux, résultats
d'analyses et d'imagerie, lignes de l'ordonnance active. Le serveur compose
ce préremplissage une seule fois
(`MedicineDossierPresenter::orientationPrefill()`), et le document imprimé
porte exactement ce que le médecin a validé. Il corrige ; il ne recopie
jamais.

## Compatibilité

`ConsultationStep::Decision` est **conservé** dans l'enum : des lignes
`consultation_steps` portant `step = 'decision'` existent et doivent
continuer à se lire. Il est seulement exclu du parcours par `isWizardStep()`,
exactement comme `Diagnosis` l'a été par l'ADR-081. L'URL
`/medicine/orientations/{uuid}/decision` reste valide et redirige vers
`/cloture`.

Aucune colonne n'est supprimée, aucune donnée réécrite. Une consultation
antérieure sans `consultation_orientation` se lit par son
`consultations.decision`, via `ConsultationOrientationType::fromLegacyDecision()`.

## Permissions

Aucune permission nouvelle. Choisir une orientation relève de
`consultations.update` ; chaque destination garde la sienne
(`surgery.request`, `hospitalization.request`, `transfer.request`,
`maternity.request`, `pediatrics.request`, `medical_discharge.create`). Le
filtrage Vue sert l'ergonomie ; le serveur revérifie toujours.

---

# ADR-085 — Un seul soignant par prise en charge, un seul transfert vers Médecine

**Status:** ACCEPTED (2026-09-13 — exigence explicite du propriétaire)

Constat : n'importe quel compte Soins pouvait compléter la fiche d'un patient
pris en charge par un collègue, ou le transférer vers Médecine alors que
Médecine l'avait déjà. Un même passage pouvait ainsi être envoyé deux fois en
Médecine, et une seconde tentative sur une page périmée produisait une erreur
serveur.

## Le soignant qui prend en charge est le seul à agir

`episode_orientations.accepted_by` désigne la personne qui a pris le patient
en charge. Elle seule peut enregistrer la fiche, sauvegarder un brouillon,
marquer un acte non réalisé, terminer les soins ou transférer vers Médecine.
Un collègue ouvre la fiche **en lecture seule**, avec le nom du soignant
responsable. `CareHandlerGuard` porte la règle ; les actions la vérifient sur
la ligne déjà verrouillée, jamais seulement l'interface. Une ligne ancienne
sans `accepted_by` reste utilisable par tout compte autorisé.

Même logique que l'exclusivité du titulaire d'une caisse (ADR-059).

## Médecine ne reçoit un passage qu'une fois

À la fin des Soins, aucune orientation Médecine n'est créée si le passage en
possède déjà une en attente, en cours ou terminée (urgence ouverte en
parallèle, consultation déjà clôturée, file Soins rouverte). Seule une
orientation Médecine annulée ne compte pas. Le retour explicitement demandé
par le médecin via un ordre de soins (ADR-055) reste un chemin distinct.

## Refus lisibles

Prendre en charge un patient déjà pris, ou agir sur des soins déjà terminés,
renvoie un message qui nomme la personne et la date, jamais une erreur 500.

## Hors périmètre

Aucune reprise d'un patient par un autre soignant (fin de garde, absence)
n'est définie : elle exigera une règle explicite et tracée.

Aucune permission nouvelle.

---

# ADR-086 — Données de développement chargées par `migrate:fresh --seed` en local

**Status:** ACCEPTED (2026-09-13 — exigence explicite du propriétaire)

**Amende l'ADR-022** sur un point : « `DevelopmentUserSeeder` n'est jamais
appelé par `DatabaseSeeder` » et « les seeders standards ne créent aucun
compte de connexion ». Constat du propriétaire : après
`php artisan migrate:fresh --seed`, la base locale était vide de tout ce qu'il
faut pour tester — prestations, tarifs, mutuelles, analyses, stock, caisses,
compte de connexion — alors que les seeders existaient déjà sans être appelés.

## Ce qui change

`DatabaseSeeder` appelle désormais `DevelopmentSeeder`, **uniquement quand
`APP_ENV=local`**. Il enchaîne des seeders idempotents :

```text
clinic  DevelopmentTestAccountSeeder         user@rivo.test
        ClinicalServiceCatalogSeeder         prestations + tarifs Standard / Mutuelle
        DevelopmentMutualOrganizationSeeder  mutuelles et taux de couverture
        DevelopmentParaclinicalCatalogSeeder analyses, ECG, échographies de base
        DevelopmentLegacyAnalysisCatalogSeeder 719 analyses historiques (fixture JSON)
        DevelopmentDiagnosticCatalogSeeder   diagnostics courants
        DevelopmentMedicineStockSeeder       médicaments, lots, fournisseurs, stock
        DevelopmentCashRegisterSeeder        Caisse 1 / Caisse 2
admin   DevelopmentTestAccountSeeder         superadmin@rivo.test
```

Aucun ne crée de patient, passage, facture ni paiement.

## Comptes de test

Un seul compte par déploiement, mot de passe `password`, à la demande du
propriétaire. Il est écrit directement par le seeder et contourne donc la
politique de mot de passe des écrans : c'est précisément pourquoi il reste
confiné au local. Le compte clinique garde le rôle `RECEPTION` et reçoit en
`ALLOW` individuels (ADR-033) les permissions de tous les rôles opérationnels,
plus celles du provisioning des référentiels, afin qu'un seul compte parcoure
tout le circuit et serve d'auteur traçable aux seeders de catalogue.
`DevelopmentUserSeeder` (un compte par rôle) reste disponible à la demande.

Le compte n'est créé **qu'une fois**. S'il existe déjà, le seeder ne touche ni
son rôle, ni son mot de passe, ni ses exceptions : il ajoute seulement les
droits de provisioning manquants, sans jamais écraser une décision existante.
Un `db:seed` relancé sur une base locale ne rend donc jamais un accès retiré
depuis le portail (constat du 2026-09-13 : une première version réécrivait
tous les droits du compte à chaque lancement).

## Catalogue des 719 analyses

`rivo:import-legacy-analyses` lit la base historique `ctb-cover`, absente d'un
poste de développement ordinaire. Son résultat a été exporté une fois dans
`database/seeders/data/legacy_analysis_catalog.json` ; le seeder le rejoue par
code (comparé selon la collation de la base, « UREE » = « Urée »), sans jamais
écraser une analyse existante ni inventer de tarif (ADR-024).

## Plus aucune donnée Pharmacie préremplie (2026-09-17)

Demande du propriétaire : la Pharmacie se saisit avec de vraies données.
`DevelopmentMedicineStockSeeder` et `DevelopmentProcurementSeeder` quittent
donc la liste de `DevelopmentSeeder` — un `migrate:fresh --seed` ne crée plus
ni médicament, ni lot, ni fournisseur, ni commande. Les deux seeders restent
appelables nommément pour qui veut une chaîne d'approvisionnement de
démonstration.

**Retour en arrière, puis rétablissement (2026-09-22).** Le 2026-09-21, le
propriétaire avait demandé l'inverse — que `migrate:fresh --seed` recrée des
médicaments, « de quoi essayer une ordonnance » — et `DevelopmentSeeder`
appelait de nouveau `DevelopmentMedicineStockSeeder`. Le 2026-09-22 il
revient à la règle de cette ADR : la Pharmacie se saisit avec de vraies
données. Le seeder quitte donc `DevelopmentSeeder` **et** le banc local de
l'ADR-043 (`rivo:local-apis` ne peuple plus « stock Pharmacie de test ») ; il
reste appelable nommément. Un test le garde : après `DevelopmentSeeder`,
`medicines`, `medicine_lots` et `medicine_suppliers` sont vides.

Pour vider une base déjà remplie, `php artisan rivo:pharmacy-reset` efface le
domaine entier — fournisseurs, catalogues et leurs fichiers, prix, commandes,
réceptions, factures fournisseur, stock, lots, mouvements, médicaments et
familles — ainsi que ce que la Pharmacie a produit ailleurs : délivrances,
lignes d'ordonnance citant un médicament, prestations facturées de
médicaments. Une facture **déjà encaissée** est conservée avec ses paiements
et signalée : supprimer un encaissement réel n'est pas un ménage de données
(ADR-010, ADR-012). La commande refuse hors `local`/`testing`.

## Amendement du 2026-09-21 — des médicaments d'essai reviennent (ADR-163)

Demande du propriétaire, après un `migrate:fresh` : la recherche de médicament
d'une ordonnance ne trouvait plus rien à essayer. `DevelopmentMedicineStockSeeder`
rejoint de nouveau `DevelopmentSeeder` — **divergence signalée** avec la
décision du 2026-09-17 ci-dessus, que le propriétaire revoit lui-même.
`DevelopmentProcurementSeeder` reste à la demande.

Le seeder change aussi de nature : il **réinitialisait** stock, prix et fiche à
chaque passage (et restaurait un médicament archivé), ce qui, rejoué dans
`migrate:fresh --seed` puis `db:seed`, aurait écrasé des délivrances réelles.
Il ne crée plus que ce qui manque : un médicament déjà présent — modifié,
archivé, entamé — n'est jamais retouché. Vingt produits s'ajoutent aux
dix-huit d'origine pour essayer l'hospitalisation, la Maternité et les Soins
(injectables, solutés, contraception, petit matériel), tous fictifs.
`rivo:pharmacy-reset` reste le moyen de repartir de zéro.

## Garde-fou production

Tous ces seeders refusent de s'exécuter hors `local`/`testing`
(`Database\Seeders\Concerns\LocalOnly`). Le portail local étant lancé avec
`--env=admin`, le garde-fou lit aussi `APP_ENV` ; un déploiement
`APP_ENV=production` est refusé dans tous les cas.

---

# ADR-087 — Retrait des variables `{{code}}` du canevas au profit d'une page 1 saisie par le RH et de l'import DOCX/PDF

**Status:** ACCEPTED (2026-09-12 — exigence explicite du propriétaire)

Le mécanisme `{{variable}}` d'ADR-070 (panneau « Variables disponibles »,
extraction `variables_used` à l'enregistrement, résolution/substitution par
`DocumentVariableResolver`) s'est avéré contraire à l'objectif du
propriétaire : composer un canevas « comme dans Microsoft Word », sans jamais
manipuler de jeton technique. Cette décision retire ce mécanisme et le
remplace par un modèle en deux parties fixes, plus l'import de fichiers
`.docx`/`.pdf` dans l'éditeur.

## Ce qui est superseded d'ADR-070

Le mécanisme `{{variable}}` complet : le panneau d'insertion et son marquage
visuel (`VariableMark`), l'extraction `variables_used` à chaque
enregistrement (`SaveDocumentTemplateAction::extractPlaceholders()`),
`DocumentVariableCatalog`, `DocumentVariableResolver`, la colonne
`document_templates.variables_used`, et les colonnes
`generated_documents.resolved_variables_snapshot`/`manual_variables_snapshot`.
Le paragraphe d'ADR-070 « Variables : jamais d'invention, `{{salaire}}`
inclus » est remplacé par le mécanisme de page 1 ci-dessous — le principe
« jamais d'invention » survit sous une autre forme : un champ requis encore
vide bloque toujours la génération, il n'est simplement plus un code de
variable mais un champ de formulaire nommé.

## Ce qui reste inchangé d'ADR-070

Propriétaire/diffusion Super Admin → site par API sécurisée ; `data_context`
comme enum fermé (gouverne désormais le schéma de champs de page 1 plutôt que
le catalogue de variables) ; versionnement/lineage
(`SaveDocumentTemplateAction` : une modification d'un canevas déjà utilisé
crée toujours une nouvelle version et archive l'ancienne) ; snapshot figé sur
`generated_documents` (renommé `form_data_snapshot`) ; éditeur TipTap
multi-page ; génération par impression navigateur et sa limite connue de
numérotation de page ; permissions `document_templates.*`/
`generated_documents.*`, inchangées.

## Nouveau modèle en deux parties

Chaque document généré est désormais composé de :

```text
Page 1   informations de la personne, pré-remplies automatiquement depuis
         Employee (+ EmploymentContract ou LeaveRequest selon data_context),
         modifiables par le RH avant génération
Page 2+  le canevas du Super Admin, tel qu'il l'a écrit — jamais de
         substitution, aucun code de variable à interpréter
```

`DocumentFormFieldCatalog` (remplace `DocumentVariableCatalog`) déclare, par
`data_context`, la liste fixe des champs de page 1 (clé, libellé français,
type, obligatoire). `DocumentFormDataResolver` (remplace
`DocumentVariableResolver`) construit les valeurs connues (mêmes attributs
Employee/EmploymentContract/LeaveRequest qu'avant, mêmes dates au format
`d/m/Y`), les fusionne avec les valeurs saisies par le RH — qui l'emportent
toujours sur la valeur connue pour la même clé — et rend la page 1 en HTML,
suivie du même séparateur de saut de page que celui utilisé entre les pages
du canevas (`canevas-page-break`), puis du `content_html` du canevas
strictement inchangé. Un champ requis encore vide après fusion bloque la
génération (jamais l'aperçu, qui reste permissif) avec un message listant les
champs manquants.

## Import DOCX/PDF

L'éditeur Super Admin (`Editor.vue`) permet d'importer un fichier `.docx` ou
`.pdf`, qui remplace le contenu de la page active (jamais de découpage
automatique sur plusieurs pages — hors périmètre, non fiable à déduire d'une
structure arbitraire). Le parseur `.docx` (mammoth.js) conserve gras,
italique, souligné, titres, listes, tableaux et alignements du mieux que son
analyse le permet. Le parseur `.pdf` (pdfjs-dist) n'extrait que le texte brut
— la mise en page d'origine d'un PDF n'est jamais fiable à reconstituer côté
navigateur — et l'interface affiche un avertissement explicite invitant le
Super Admin à remettre en forme manuellement. Les deux bibliothèques
s'exécutent entièrement dans le navigateur du Super Admin, chargées à la
demande (import dynamique) uniquement lors d'un import réel : aucune nouvelle
dépendance serveur n'est ajoutée, dans le même esprit que la limite déjà
actée par ADR-070 (« pas de génération PDF serveur »).

**Amendement du 2026-09-15 — une page du fichier = une page du canevas.**
Constat du propriétaire : un fichier d'environ 16 pages arrivait entièrement
dans « Page 1 ». La phrase « jamais de découpage automatique » ci-dessus est
remplacée : le découpage suit désormais les **limites de page que le fichier
porte lui-même**, jamais une structure devinée.

```text
PDF    une page du canevas par page du PDF (texte seul, lignes conservées)
DOCX   coupure sur les sauts de page saisis, « saut de page avant » d'un
       paragraphe, et les fins de page mémorisées par Word lors de son
       dernier enregistrement (w:lastRenderedPageBreak)
```

La page 1 du fichier remplace la page active ; les suivantes sont insérées
juste après elle, dans leur ordre. Une confirmation annonce le nombre de pages
avant tout remplacement. Un `.docx` qui ne porte aucune de ces marques (par
exemple généré par un outil qui ne mémorise pas la mise en page) reste sur une
seule page, avec un message qui le dit : la pagination automatique de Word
dépend des polices et marges et n'est pas reconstituée par estimation. Tout
reste exécuté dans le navigateur (`jszip`, déjà présent via mammoth, devient
une dépendance directe).

---

# ADR-088 — « Demander un soin » ne termine plus la consultation

**Status:** ACCEPTED (2026-09-13 — arbitrage explicite du propriétaire)

**Amende l'ADR-055** sur un point : « pour un patient `NORMAL`, l'orientation
Médecine active est terminée avant l'ouverture de Soins, afin qu'une seule
orientation clinique active existe à la fois ».

## Le conflit constaté

L'ADR-084 a fait de la clôture le **seul** acte qui termine l'orientation
Médecine. `CreateCareOrderAction` la terminait pourtant encore dès la demande
de soins. Cas réel du 2026-09-13 : un médecin demande une échographie puis un
soin ; la consultation reste `IN_PROGRESS` sur une orientation `COMPLETED`. Le
dossier devient lecture seule : impossible de consigner le diagnostic, de
choisir la suite de la prise en charge ou de clôturer, alors même que la
clôture l'exige.

## La règle

La consultation **reste ouverte** pendant que le patient est aux Soins. Le
médecin continue d'y consigner diagnostic, examens, prescription et suite de la
prise en charge, puis clôture quand il a terminé — c'est la clôture qui termine
l'orientation Médecine (ADR-084), quelle que soit la priorité du passage.

Pendant ce temps, le patient figure à la fois dans la file Soins (l'acte
demandé) et dans la consultation Médecine en cours. Ce n'est pas un doublon :
ce sont deux travaux distincts sur le même passage, et l'ADR-085 empêche
toujours tout second transfert vers Médecine.

Conséquences, sans nouveau mécanisme :

```text
retour en Médecine demandé   la consultation est déjà ouverte : aucune
                             nouvelle orientation ni consultation n'est créée
sans retour en Médecine      les Soins terminent leur acte ; le passage ne
                             passe en PENDING_SETTLEMENT qu'une fois la
                             consultation aussi clôturée (ADR-054)
```

## Données existantes

La consultation n°1 du site Ambondromamy (passage `A-26-0001-01`), bloquée par
l'ancien comportement, a été rouverte à la demande du propriétaire : son
orientation Médecine est repassée `IN_PROGRESS`, tracée dans l'audit sous
`episode.orientation.reopen`. La demande de soins envoyée reste valable.

Aucune permission nouvelle.

---

# ADR-089 — Une seule étape pour conclure : « Décision & clôture »

**Status:** ACCEPTED (2026-09-13 — validation explicite du propriétaire)

**Amende l'ADR-084** sur sa section « Décidée aux trois moments où elle peut
l'être », et l'ADR-080 pour le lieu de saisie du diagnostic d'un passage sans
examen clinique.

## Le problème

La carte « Suite de la prise en charge » était présente à l'Interrogatoire, à
l'Examen et à la Paraclinique, et l'étape Clôture se contentait de vérifier.
En pratique, la Clôture listait ce qui manquait — « consignez le diagnostic à
l'étape Paraclinique », « Sortie médicale : à configurer » — et renvoyait le
médecin ailleurs pour le faire. Conclure un passage demandait des allers-retours
entre trois écrans.

## La règle

La dernière étape devient **« Décision & clôture »**. Conclure s'y fait en un
seul écran, de haut en bas :

```text
1 · Diagnostic        liste des diagnostics actifs + saisie
2 · Conduite à tenir  choix de la destination et son formulaire prérempli
3 · Vérification      ce qui manque encore, puis « Clôturer la consultation »
```

La carte de conduite à tenir disparaît de l'Interrogatoire, de l'Examen et de
la Paraclinique : ces étapes redeviennent du recueil. Les messages d'obstacle
nomment désormais cette seule étape.

## Ce qui justifiait trois emplacements, et pourquoi ce n'est plus nécessaire

- **Décider tôt** (patient au bloc dès l'examen) : le parcours n'est pas
  verrouillé ; le médecin ouvre directement « Décision & clôture », transmet la
  demande, et peut revenir prescrire ensuite.
- **Passage paraclinique seul** (échographie, ECG, analyse) : ses étapes sans
  objet sont sautées ; il arrive à la même étape finale, où il pose son
  diagnostic et sa conduite comme tout autre patient.

## Ce qui ne change pas

Le diagnostic peut toujours être posé à l'Examen clinique (ADR-080), avec sa
correction et son annulation par l'auteur (ADR-035/081). Une orientation choisie
mais non transmise bloque toujours la clôture ; changer d'avis annule sans
effacer ; seule la clôture termine l'orientation Médecine (ADR-084, ADR-088).
Aucun endpoint, aucune donnée ni permission ne change : seul le lieu de saisie
dans l'assistant est déplacé.

---

# ADR-090 — Sortie administrative et créance patient

**Status:** ACCEPTED (2026-09-15 — exigence explicite du propriétaire, règles
reprises du CDC §32, §33.2, §33.3 et §34)

## Le trou constaté

Depuis l'ADR-084, clôturer la consultation laisse le passage en
`PENDING_SETTLEMENT` : « le parcours clinique est terminé, la suite est
administrative et financière ». Rien ne consommait cet état. Aucun code
n'écrivait `EpisodeAdministrativeStatus::Discharged`, aucun ne passait
`Episode.status` à `CLOSED`, et aucun écran ne listait ces passages. Un
patient médicalement sorti restait donc indéfiniment « en attente de
règlement », sans que la Réception ait un endroit pour voir qui attendait ni
ce qui restait dû.

L'ADR-021 et l'ADR-035 avaient volontairement laissé ce vide : la sortie
administrative dépend du solde, et Facture/Caisse n'existait pas. Elle
existe désormais.

## Trois sorties, décidées par le solde et non par l'agent

Le CDC §33.3 est explicite et n'a pas eu à être interprété :

```text
reste à payer = 0  -> Sorti — payé comptant
reste à payer > 0  -> Sorti — dette validée   (dérogation autorisée)
                      ou Sorti — évadé        (constat)
```

`RecordAdministrativeExitAction` recalcule le solde **sous verrou** depuis
`EpisodeAccountControl` avant de décider. Un montant affiché par le
navigateur n'est jamais une source de vérité — même règle que le tarif à
l'arrivée (ADR-028) : un paiement ou un acte a pu arriver entre l'affichage
et le clic. Choisir « payé comptant » sur un compte non soldé est refusé ;
créer une dette sur un compte soldé l'est aussi.

`EpisodeAdministrativeStatus` porte donc enfin les trois états nommés par le
CDC §32 (`DISCHARGED_PAID`, `DISCHARGED_DEBT`, `DISCHARGED_ESCAPED`) au lieu
du seul `DISCHARGED`, que son propre commentaire annonçait comme provisoire
« until that module can split it into those three states for real ». Le cas
`DISCHARGED` reste lisible mais n'est plus jamais écrit ; aucune ligne ne le
portait, rien n'est migré.

## La créance n'est pas une facture soldée

`patient_debts` enregistre ce qui reste dû, avec les informations
obligatoires du §33.3 : pour une dette validée, la personne responsable du
paiement, ses coordonnées, l'échéance éventuelle, le commentaire et
l'utilisateur qui a autorisé ; pour une évasion, l'heure estimée du départ,
le dernier service connu et l'utilisateur qui a enregistré le constat. Une
évasion ne nomme aucun responsable et aucun autorisateur : personne ne s'est
engagé à payer, et l'inventer serait fabriquer un fait.

Le modèle utilise `ProtectsFinancialRecord`, jamais Soft Delete : §34.2
règle 9 interdit qu'une évasion efface la créance. Il ne porte volontairement
**aucune** colonne `status`/`settled_at` : le CDC ne définit aucun workflow
de règlement d'une créance, et en inventer un permettrait de marquer une
dette payée sans paiement, sans session de caisse et sans reçu — exactement
ce que l'ADR-012 réserve à Réception/Caisse. Le module Créances (roadmap
Phase 1) portera cette suite quand ses règles seront décidées.

Le solde au moment du départ est figé sur l'épisode
(`administrative_exit_balance`) : §34.2 règle 8 interdit d'assimiler une
sortie avec dette à une facture soldée, donc ce que le patient devait
réellement en franchissant la porte doit rester lisible même si les factures
évoluent ensuite.

## Ce que la sortie ne fait pas

Elle n'encaisse rien. Le bouton « Encaisser » de l'écran renvoie à `/cash` ;
aucune permission `payments.*` ou `cash.*` n'est ajoutée ni requise ici
(ADR-012, ADR-013). Elle ne touche aucune donnée clinique (§34.2 règle 1).
Elle ne prononce pas la sortie médicale et n'en dépend pas non plus : un
parcours `CARE_ONLY` arrive en `PENDING_SETTLEMENT` sans aucune
`MedicalDischarge` (ADR-054) et se clôt ici normalement.

Elle clôt en revanche le passage (`Episode.status = CLOSED`), ce qui rend
inopérants les chemins d'écriture qui exigent déjà un épisode ouvert —
fiche de soins, consommables, routage, requalification en urgence, contexte
financier. C'est l'effet voulu : le passage est terminé. Les paiements sur
ses factures restent possibles, sans quoi une créance ne pourrait jamais
être réglée.

## Prestations non facturées

Une `BillableItem` encore `PENDING` n'est pas une dette : elle n'a pas été
portée sur une facture validée, donc le patient ne la doit pas encore. Elle
n'entre donc pas dans le « reste à payer » du §33.2. Mais sortir le passage
en l'ignorant perdrait ce montant définitivement. L'écran l'affiche donc
séparément et explicitement, et laisse la Réception décider — plutôt que de
bloquer une sortie sur un état que la Caisse peut régulariser en une action.

## Où la sortie peut être prononcée, et où elle ne peut pas

L'action exige `administrative_status === PENDING_SETTLEMENT`. Un passage
encore `IN_CARE` appartient toujours à une file Soins ou Médecine : le
clore depuis la Réception laisserait un service devant un passage sur
lequel plus personne ne peut agir.

Conséquence assumée et signalée : une évasion survenue **pendant** les
soins, avant toute sortie médicale, ne peut pas être enregistrée ici. Elle
exigerait d'annuler les orientations cliniques actives — une règle que le
CDC ne formule nulle part et qui n'est donc pas inventée. À trancher avec
l'équipe si le cas se présente réellement.

## Permissions

```text
episodes.settlement.view      voir la file des passages à régler
episodes.administrative_exit  prononcer la sortie
debts.view                    consulter les créances
debts.authorize               autoriser une sortie avec dette validée
```

Voir et prononcer sont séparés : un compte peut avoir besoin de suivre les
passages en attente sans pouvoir clore un compte patient.

`debts.authorize` n'est **pas** accordée à `RECEPTION` par défaut. Renoncer à
encaisser un solde est la dérogation du §34.1 règle 6 (« sauf dérogation
autorisée et tracée »), réservée à une personne habilitée — `ADMINISTRATION`
par défaut, qui reçoit aussi la file et le droit de prononcer la sortie, sans
quoi aucun rôle ne posséderait les deux droits et la dérogation serait
impossible dès l'installation. Un chef de poste Réception la reçoit par
exception individuelle auditée (ADR-022) ou par l'éditeur de socle de rôle
(ADR-064). Une évasion est un constat et non une dérogation : elle relève
d'`episodes.administrative_exit` seul.

Conformément à l'ADR-064, ces quatre permissions figurent dans
`RolePermissionSeeder::GRANTS` pour la création d'un nouveau site, mais un
site déjà en production doit les ajouter depuis le portail sans rejouer ce
seeder, qui écraserait les socles personnalisés.

## Audit

Chaque sortie est auditée sous `episode.administrative_exit` avec l'ancien et
le nouvel état, le type de sortie, le solde figé, le numéro de créance
éventuel et le motif — obligatoire pour les trois types, pas seulement pour
les dérogations (§34.1 règle 8).

**Amendement du 2026-09-20 — une prestation non facturée interdit la sortie.**
La section « Prestations non facturées » ci-dessus se contentait d'afficher le
montant et de « laisser la Réception décider ». Cas constaté sur `A-26-0009-01` :
une première facture (50 000 Ar) soldée, puis trois prestations ajoutées après son
règlement (NFS, seconde échographie, injection : 70 000 Ar). Une facture payée ne se
modifie plus (ADR-054), donc ces lignes restaient `PENDING`, le reste à payer valait
0, et « Prononcer la sortie » restait actif : cliquer clôturait le passage et
faisait perdre 70 000 Ar. Le propriétaire a demandé de refuser.

```text
règle      aucune sortie, de quelque type que ce soit, tant qu'une prestation est
           PENDING ; refusée par RecordAdministrativeExitAction sous verrou
           (guardNothingUnbilled), avant toute règle de solde
tous types dette validée et évasion comprises : leur montant doit porter sur ce que
           le patient doit réellement, pas sur la seule part déjà facturée. Le
           droit debts.authorize n'y change rien
écran      la fenêtre grise les trois types, désactive « Prononcer la sortie » et
           propose « Facturer ces prestations » ; le serveur revérifie de toute façon
chemin     POST /reception/passages/{episode}/facturer-prestations
           (episodes.administrative_exit + billing.create) : relit les prestations
           en attente côté serveur, crée la facture (CreateInvoiceAction) puis la
           valide si le compte a billing.validate (ValidateInvoiceAction) ;
           sinon elle reste en brouillon et le message le dit. Aucune règle
           nouvelle : deux actions existantes composées
après      le compte affiche le vrai reste à payer ; « payé comptant » n'est
           plus possible tant qu'il reste dû, l'encaissement se fait à la Caisse
           (ADR-012) — ce geste n'encaisse rien
```

**Le chiffre affiché ne doit pas contredire la situation.** Le « reste à payer » du
CDC §33.2 ne somme que les factures ; à zéro, il s'affichait en vert alors que
70 000 Ar attendaient d'être facturés — exact, mais trompeur. L'écran des sorties
affiche donc le **total réellement dû** (factures + prestations non facturées),
en rouge tant que ce total n'est pas nul, avec la part « non facturé » nommée à
côté. C'est un affichage : le serveur ne s'en sert pas pour décider, et
`balance_amount` garde sa définition §33.2.

Divergence avec la première version de l'ADR signalée, non masquée : « laisse la
Réception décider » est remplacé par un refus. Une clinique qui offre parfois une
prestation devra l'annuler (`CancelBillableItemAction`) plutôt que la laisser
`PENDING` : ce qui n'est plus dû n'est plus en attente.

**Amendement du 2026-09-20 (bis) — l'évasion devient un droit accordé, comme la dette.**
Demande du propriétaire : les deux sorties qui laissent une créance — dette validée
et évasion — doivent dépendre d'une autorisation que le Super Administrateur
accorde depuis les permissions. L'ADR disait qu'une évasion « est un constat et non
une dérogation » et relevait d'`episodes.administrative_exit` seul : n'importe quel
compte pouvant prononcer une sortie pouvait donc déclarer un patient évadé et créer
une créance à son nom. Cette phrase est remplacée.

```text
dette validée   debts.authorize          (inchangé)
évasion         debts.record_escape      (nouveau — « Enregistrer une sortie évadé »)
payé comptant   episodes.administrative_exit seul (inchangé)
```

Les deux restent **affichées** dans la fenêtre, verrouillées avec le message
« demandez à un administrateur… (permission …) » : la Réception voit qu'elles
existent et à qui s'adresser. Le serveur refuse de toute façon
(`AuthorizationException`, avant toute écriture). Par défaut : ADMINISTRATION, comme
`debts.authorize` ; la Réception l'obtient par le socle de son rôle ou une exception
individuelle auditée (ADR-022, ADR-064). Enregistrée par migration
(`2026_10_03_090000`), à lancer sur chaque site et sur le portail.

Le motif et le commentaire n'apparaissent qu'**après** le choix d'un type de sortie :
tant qu'aucun n'est choisi, ils n'ont rien à justifier.

**Amendement du 2026-09-20 (ter) — sélection multiple sur « Sorties & règlements ».**
Demande du propriétaire. Quatre actions groupées, sur les passages cochés
(50 au plus par geste, une sélection ne survit pas à un changement d'onglet, de page
ou de recherche) :

```text
Sortie « payé comptant »       comptes réellement soldés seulement (0 Ar ET rien à facturer)
Facturer les prestations       une facture par passage (créée, puis validée si billing.validate)
Imprimer les fiches de sortie  onglet « Sorties prononcées » : un seul document, une fiche par page
Exporter la sélection en Excel lecture seule, auditée ; colonnes financières seulement avec billing.view
```

Règles retenues, aucune nouvelle règle métier :

```text
jugement    chaque passage est traité SÉPARÉMENT par l'action qui le traite seul
            (RecordAdministrativeExitAction, InvoicePendingPrestationsAction) ;
            l'écran n'envoie que des UUID, le serveur rejuge tout
partiel     un passage refusé — compte plus soldé, prestation apparue entre-temps,
            sortie déjà prononcée — n'empêche pas les autres : ce sont des dossiers
            indépendants, pas les lignes d'une même écriture (à la différence des
            référentiels de l'ADR-046, où une ligne fautive annule tout). Un
            rapport dit ce qui est passé et ce qui ne l'est pas, avec la raison
lot         seule la sortie « payé comptant » se prononce en lot : une dette
            validée exige un responsable identifié, une évasion un constat, et
            debts.authorize / debts.record_escape restent des décisions
            nominatives — jamais sur une liste
audit       un audit par passage (actions existantes) plus une ligne de synthèse
            (settlement.bulk_exit, settlement.bulk_invoice, settlement.export)
droits      sortie : episodes.administrative_exit ; facturation : + billing.create ;
            fiches et export : episodes.settlement.view
```

`InvoicePendingPrestationsAction` est extraite du geste « Facturer ces
prestations » de la fenêtre de sortie, désormais partagé avec le lot. Le corps de la
fiche de sortie devient `ExitSlipBody`, partagé entre l'impression d'une fiche et
l'impression groupée : une seule mise en page.

---

# ADR-091 — Migration UI progressive de DashWind vers shadcn-vue

**Status:** ACCEPTED (2026-09-15 — validation explicite du propriétaire)

**Amende l'ADR-018** : DashWind reste la base historique pendant la transition,
mais shadcn-vue devient la couche de composants cible. Les deux ne constituent
pas deux systèmes concurrents durables : la coexistence est temporaire et
organisée écran par écran jusqu'au retrait des usages DashWind remplacés.

## Stratégie

Il n'y a aucun remplacement global ni exécution aveugle de l'initialiseur qui
réécrirait Tailwind ou les styles existants. Une couche isolée sous
`resources/js/Components/Shadcn` porte les primitives sélectionnées et les
tokens sémantiques RIVO (`background`, `card`, `muted`, `primary`,
`destructive`, `border`, `ring`). Les composants sont ajoutés à la demande,
adaptés à l'identité visuelle de la clinique et vérifiés en clair comme tout
autre code du dépôt.

Chaque pilote doit conserver avant tout :

```text
permissions et protections Laravel
routes et contrats Inertia
validation et erreurs serveur
actions et règles métier
accessibilité clavier et focus
mode sombre et affichage responsive
```

Le premier pilote est `Patients/Index.vue` (`/patients`) : statistiques,
recherche, filtres, sélection multiple, vues liste/grille, pagination et
dialogue de Soft Delete. Aucun endpoint, modèle, permission ou comportement
métier patient ne change. Les écrans suivants ne migrent qu'après validation
visuelle et fonctionnelle de ce pilote par le propriétaire.

---

# ADR-092 — Fiche de soins corrigeable après le transfert vers Médecine

**Status:** ACCEPTED (2026-09-15 — arbitrage explicite du propriétaire, après
signalement du conflit avec l'ADR-085)

**Amende l'ADR-085** sur ses deux verrous, et uniquement ceux-là.

## Le constat

Une fois le patient transféré vers Médecine, la fiche devenait entièrement
lecture seule : « Les soins de ce patient ont déjà été terminés. Aucune
nouvelle action n'est possible sur cette prise en charge. » Une température
saisie 32 °C au lieu de 36,2 restait donc fausse dans le dossier, et
parvenait telle quelle au médecin via `CareRecordReadModel` (ADR-054).

L'ADR-085 réservait par ailleurs toute action au soignant qui avait pris le
patient en charge. Celui-ci peut avoir terminé son service : personne ne
pouvait alors corriger.

## Ce qui change

```text
avant   orientation Soins COMPLETED -> aucune écriture
après   orientation Soins COMPLETED -> fiche corrigeable

avant   seul `accepted_by` écrit
après   tout compte Soins autorisé corrige
```

`CareHandlerGuard::ensureEditable()` / `isEditable()` autorisent l'écriture
tant que l'orientation est `IN_PROGRESS` **ou** `COMPLETED`. `PENDING` reste
refusé (prendre d'abord le patient en charge) et `CANCELLED` aussi — il n'y a
alors plus rien à corriger. Le passage doit rester `OPEN` : une fois clos par
la sortie administrative (ADR-090), plus rien ne s'y écrit.

Le périmètre retenu par le propriétaire est **la fiche entière** —
constantes, actes réalisés et matériel — et non les seules constantes. La
conséquence a été signalée avant l'arbitrage et est assumée : un acte
facturable ajouté après le transfert crée son `BillableItem` (ADR-054) et un
consommable déclaré crée sa demande de sortie de stock Pharmacie (ADR-072),
sur un passage déjà transmis. Ces circuits ne sont pas modifiés ; ils
s'exécutent simplement plus tard qu'avant.

## Ce qui ne change pas

Le transfert vers Médecine reste **unique**. `CompleteCareAndOrientToMedicineAction`
conserve `ensureWorkable()`, qui refuse une orientation déjà terminée et
réserve la transition au soignant qui a pris le patient en charge : c'est la
moitié de l'ADR-085 qui garde tout son sens — un même passage ne doit jamais
arriver deux fois dans la file Médecine. Cette garde n'est volontairement pas
dupliquée dans `SaveCareRecordAction`, pour qu'elle ne puisse pas diverger.

Les actes réalisés restent **append-only** (ADR-032) : une correction ajoute
une trace, elle n'efface jamais un acte antérieur.

## Traçabilité

`CareRecord` porte déjà le trait `Auditable`, qui enregistre l'ancienne **et**
la nouvelle valeur à chaque modification. Une correction post-transfert est
donc lisible dans `audit_logs` avec son auteur, sa date et la valeur qu'elle
remplace — c'est ce qui rend cet assouplissement acceptable sans mécanisme
supplémentaire.

Le brouillon (ADR-073) suit la fiche : il reste possible après le transfert et
pour tout compte autorisé, mais demeure rattaché à son auteur
(`unique(episode_orientation_id, created_by)`). Sur un poste partagé, personne
ne récupère la saisie non validée d'un collègue — la raison même pour laquelle
ce brouillon vit côté serveur.

## Hors périmètre

La reprise formelle d'un patient par un autre soignant — changer
`accepted_by`, donc la responsabilité affichée du transfert — n'est toujours
pas définie. L'écran continue d'indiquer qui a pris le patient en charge, et
seul ce compte peut le transférer.

Aucune permission nouvelle : `care.update` et `vitals.update` gouvernent la
correction comme elles gouvernaient déjà la saisie.

---

# ADR-093 — Le médecin corrige les constantes relevées par les Soins

**Status:** ACCEPTED (2026-09-15 — arbitrage explicite du propriétaire, après
signalement du conflit avec les ADR-077, ADR-054 et ADR-092)

**Amende l'ADR-077** (« un recontrôle devra créer un **nouveau relevé** ; il ne
modifiera jamais la mesure d'origine »), **l'ADR-054** (« Médecine consulte la
fiche Soins, ne la modifie jamais ») et **l'ADR-092** (correction réservée aux
comptes Soins).

## Le constat

Sur le passage `A-26-0001-01`, la fiche Soins portait `T° 32 °C` — une erreur
de frappe pour 36,2. Cette valeur parvenait au médecin en lecture seule via
`CareRecordReadModel` (`read_only => true`), déclenchait l'alerte rouge
d'hypothermie de l'ADR-040, et restait fausse dans le dossier tant qu'un
compte Soins ne la rectifiait pas.

L'ADR-092 avait déjà ouvert la correction à « tout compte Soins autorisé »
précisément pour ce cas. Elle ne suffit pas : le médecin a la mesure fausse
sous les yeux pendant la consultation, et le soignant qui l'a saisie peut
avoir fini son service.

## L'alternative écartée, et pourquoi

Deux réponses ont été présentées au propriétaire :

```text
Nouveau relevé Médecine   la mesure Soins reste affichée, horodatée ;
                          le médecin ajoute la sienne à côté.
                          Conforme à l'ADR-077 sans amendement.

Correction de la fiche    le médecin écrase la valeur, comme un infirmier.
                          Amende ADR-077 + ADR-054 + ADR-092.   ← retenu
```

Le propriétaire a retenu la correction. La conséquence est assumée : **la
mesure d'origine quitte l'écran**. Elle n'est pas perdue pour autant —
`CareRecord` porte `Auditable`, qui conserve l'ancienne **et** la nouvelle
valeur avec son auteur et sa date. C'est cette trace, et elle seule, qui rend
l'écrasement acceptable : le 32 °C reste lisible à l'audit, il cesse
seulement d'être présenté comme la mesure du patient.

## Le périmètre : les constantes, et rien d'autre

```text
constantes        corrigeables par Médecine
actes réalisés    non — append-only (ADR-032), et un acte crée un BillableItem
consommables      non — une déclaration sort du stock Pharmacie (ADR-072)
allergies         non — dossier permanent, patients.medical_history.manage
transmission      non — c'est la parole des Soins, pas celle du médecin
```

Cette restriction n'est pas une précaution d'interface. Laisser Médecine
écrire ces quatre-là ferait naître, depuis un écran de consultation, une
prestation à facturer ou une sortie de stock — deux circuits qui
appartiennent à la Réception (ADR-012) et à la Pharmacie (ADR-013). Les
champs concernés sont donc explicitement `prohibited` dans
`CorrectCareRecordVitalsRequest` plutôt que simplement absents des règles :
un payload forgé reçoit une erreur nommée au lieu d'être ignoré en silence.
`CorrectCareRecordVitalsAction` refuse de son côté — l'interface n'est jamais
la seule protection.

## Une seule définition des bornes cliniques

Deux chemins écrivent désormais les mêmes colonnes. `App\Support\VitalSignRules`
porte donc une fois pour toutes les bornes (ADR-032, ADR-038 à ADR-041), leurs
messages, et le calcul de l'IMC ; `UpdateCareRecordRequest` et
`CorrectCareRecordVitalsRequest` le consomment tous les deux. Recopier les
règles aurait laissé une tension refusée à l'infirmier être acceptée au
médecin pour la même mesure. L'IMC reste calculé par Laravel et `bmi` est
`prohibited` dans la requête : le navigateur n'en fournit jamais la valeur.

## Ce qui ne change pas

`Episode.status` doit rester `OPEN` : un passage clos par la sortie
administrative (ADR-090) ne se corrige plus. La consultation doit rester
éditable (ADR-076) : après clôture, cette écriture refuse comme toutes les
autres. Médecine **corrige** une mesure, elle n'en crée jamais une : sans
fiche Soins existante l'action refuse explicitement, parce que signer ici le
premier relevé d'un passage reviendrait à attester un examen que personne n'a
pratiqué.

`read_only` reste `true` dans `CareRecordReadModel` : la projection dans son
ensemble demeure en lecture seule. Seules les constantes s'ouvrent, via le
drapeau `can_correct_vitals`, calculé depuis `vitals.update`. La Chirurgie ne
possède pas cette permission (ADR-048) et conserve donc exactement le
comportement qu'elle avait.

## Permissions

`vitals.update` est ajoutée au socle `MEDICINE`.

**Amendement du même jour.** Le propriétaire a demandé que le médecin puisse
corriger **toutes** les informations saisies par les Soins, pas seulement les
constantes. `care.update` rejoint donc le socle `MEDICINE`, et la
consultation renvoie vers la fiche Soins existante
(`care_record.full_record_url`) au lieu d'en recopier une seconde version :
une fiche dupliquée est une fiche qui finit par diverger, et les règles de
correction post-transfert sont déjà celles de l'ADR-092.

La conséquence, signalée et assumée : un acte facturable ajouté depuis cet
écran crée son `BillableItem` par le circuit habituel (ADR-054). Restent
exclus, et ce n'est pas une omission :

```text
care.create                 une fiche que personne n'a remplie ne se signe
                            pas depuis une consultation
care_consumables.request    déclarer du matériel sort du stock Pharmacie
                            (ADR-072) ; c'est le geste de l'infirmier au
                            chevet, jamais celui du médecin
```

`CorrectCareRecordVitalsAction` garde son périmètre étroit : c'est le chemin
rapide pour rectifier une valeur aberrante sans quitter la consultation. La
fiche complète reste le chemin normal pour tout le reste.

Conformément à l'ADR-064,
cette attribution figure dans `RolePermissionSeeder::GRANTS` pour la création
d'un nouveau site, mais un site déjà en production doit l'ajouter depuis le
portail sans rejouer ce seeder, qui écraserait les socles personnalisés.

---

# ADR-094 — Diagnostic final facultatif pour un passage paraclinique seul

**Status:** ACCEPTED (2026-09-15 — arbitrage explicite du propriétaire, après
signalement du conflit avec le CDC §33.1)

**Amende l'ADR-081** (« la clôture vérifie directement l'existence d'un
diagnostic actif ») et **l'ADR-035** (la sortie médicale conserve « le
diagnostic final »), pour ce seul cas.

## Le constat

Sur le passage `A-26-0002-01`, Mme R. était venue uniquement pour un
électrocardiogramme d'effort. L'écran « Décision & clôture » réclamait deux
choses avant de laisser clore :

```text
Diagnostic : aucun diagnostic enregistré
Conduite à tenir : indiquez la suite de la prise en charge
```

Or **aucun résultat d'ECG n'était encore saisi**. Le système demandait donc
au médecin de consigner la conclusion d'un examen qu'il n'avait pas lu. Le
médecin a choisi « Sortie médicale » deux fois et abandonné deux fois ; le
passage restait `IN_CARE`, indéfiniment en consultation, et n'atteignait
jamais la file de règlement de la Réception.

## La règle

Un passage **paraclinique seul** ne doit aucun diagnostic final. Toute vraie
consultation continue d'en devoir un.

```text
MEDICINE_DIRECT + IMAGING/LABORATORY  -> diagnostic facultatif
tout le reste                          -> diagnostic obligatoire (CDC §33.1)
```

La définition n'est pas nouvelle : `ConsultationWorkflow::isParaclinicalOnly()`
existait déjà pour l'ADR-076, qui rend l'Interrogatoire et l'Examen clinique
« sans objet » pour exactement ces passages. Elle est seulement exposée par
`requiresFinalDiagnosis()`, consommée à la fois par la garde de clôture et
par `StoreMedicalDischargeRequest`. Recopiée, elle aurait dérivé : la sortie
serait partie sur un dossier que la clôture aurait ensuite refusé.

C'est **tout ou rien** : « écho + consultation générale » n'est pas un
passage paraclinique, et le diagnostic y reste exigé.

## Pourquoi cette exception ne contredit pas l'esprit du CDC

Le CDC §33.1 énumère « date et heure, **diagnostic final**, état du patient,
prescriptions de sortie, recommandations, rendez-vous éventuel,
observations ». Il ne traite nulle part de la venue paraclinique isolée —
aucune mention d'ECG ni d'échographie dans tout le document. La divergence
est donc signalée, jamais masquée : pour ce seul cas, l'obligation est levée.

L'ADR-076 pose déjà le principe qui la justifie :

> La clôture n'exige volontairement ni analyse, ni imagerie, ni prescription :
> aucune ne concerne toutes les rencontres, et les exiger **pousserait à
> fabriquer des actes**.

Pour un ECG, la conclusion de l'examen *est* le diagnostic, et elle n'existe
pas encore au moment où le médecin clôture. Un champ obligatoire n'aurait
produit qu'une phrase de complaisance.

## Une absence reste une absence

`medical_discharges.final_diagnosis` devient **nullable**. Y écrire une
chaîne vide rendrait « aucun diagnostic » indiscernable d'« un diagnostic
oublié » — exactement le défaut que l'ADR-077 refuse pour un appareil non
examiné. Aucune ligne existante n'est réécrite : toutes portent un
diagnostic.

`RecordMedicalDischargeAction` ne fabrique donc plus de `Diagnosis` à partir
d'un champ vide. Le médecin garde le droit d'en poser un : facultatif n'est
pas interdit, et un diagnostic saisi sur un passage paraclinique est
enregistré normalement, append-only, comme n'importe quel autre (ADR-035).

## Ce qui ne change pas

La conduite à tenir reste obligatoire : c'est elle qui termine la rencontre
et fait basculer le passage en `PENDING_SETTLEMENT` (ADR-084, ADR-090).
`patient_condition` reste exigé — le CDC §33.1 le demande et, contrairement
au diagnostic, le médecin peut toujours l'observer. Les étapes du parcours
restent à résoudre (ADR-076) : ADR-094 ne lève que le diagnostic.

L'écran reçoit `requires_final_diagnosis` du serveur et cesse d'afficher
« Diagnostic final * » et son bandeau d'avertissement sur un passage où plus
rien ne l'exige. Ce drapeau est un reflet, jamais une décision : le serveur
revérifie toujours, et son absence vaut « exigé ».

Aucune permission nouvelle.

---

# ADR-095 — « Le diagnostic peut-il être posé maintenant ? » rejoint Décision & clôture

**Status:** ACCEPTED (2026-09-15 — exigence explicite du propriétaire)

**Amende l'ADR-080** (la question était posée dans l'Examen clinique) et
**l'ADR-089**, qui avait déjà rassemblé la conclusion sur la dernière étape
sans y déplacer cette question.

## Le constat

La question vivait à l'Examen clinique, alors que le diagnostic se consigne
à « Décision & clôture » depuis l'ADR-089. Deux conséquences :

```text
passage paraclinique seul  -> Examen clinique « sans objet » (ADR-076)
                              => la question n'est jamais posée
consultation ordinaire     -> on répond à un endroit, on conclut à un autre
```

Un patient venu pour un ECG ou une échographie ne pouvait donc ni répondre,
ni expliquer pourquoi sa consultation restait ouverte.

## Ce que la question faisait réellement

Un seul effet vivant : la navigation en fin d'Examen clinique. « Oui »
exigeait un diagnostic puis sautait directement à la Prescription, en
contournant la Paraclinique — c'est-à-dire l'étape qui pose elle-même sa
propre question (ADR-079), à laquelle personne ne répondait alors.

Son second effet était **mort** : la note « Différé » du stepper visait
`ConsultationStep::Diagnosis`, retirée de l'assistant par l'ADR-081, si bien
que le `match` n'était jamais atteint.

## La règle

La question est posée à **« Décision & clôture »**, en tête de la section
Diagnostic — la seule étape que tout patient atteint. Trois états, jamais
deux : `null` tant que le médecin n'a pas répondu, aucun bouton
pré-sélectionné. Une absence de réponse n'est pas un report.

« Pas maintenant » **ne débloque rien**. La clôture continue d'exiger un
diagnostic pour toute vraie consultation (CDC §33.1, ADR-081), et reste
facultative pour un passage paraclinique seul (ADR-094). Ce que la réponse
change, c'est ce que le dossier *dit* :

```text
sans réponse       « Diagnostic : aucun diagnostic enregistré — posez-le… »
« Pas maintenant » « Diagnostic : différé par le médecin — enregistrez-le… »
```

C'est la distinction que ce dossier tient partout ailleurs : un blanc
signifie que personne n'a rien décidé, un report signifie que quelqu'un a
décidé d'attendre (ADR-076, ADR-077). La note « Différé » du stepper est
rattachée à l'étape Clôture et redevient donc visible.

## Un endpoint distinct, pour la même raison que l'ADR-079

`POST /medicine/orientations/{orientation}/diagnostic-timing` porte la
réponse. Répondre à une question ne doit jamais réécrire l'état général, la
conscience ou les appareils examinés — exactement l'argument qui avait sorti
la décision paraclinique du formulaire d'examen. `diagnosis_ready` disparaît
donc de `UpdateMedicineClinicalExamRequest` : un seul chemin d'écriture.

Répondre « Oui » sans avoir rien enregistré reste refusé côté serveur : une
intention n'est pas un diagnostic. `SaveClinicalExaminationAction` continue
de conserver la valeur existante quand la clé est absente (ADR-074), si bien
qu'enregistrer l'examen n'efface jamais un report consigné à la clôture.

**Amendement du 2026-09-17 — « Oui » ouvre la saisie au lieu d'échouer.** Ce
refus serveur reste la règle, mais son message renvoyait à un champ que
l'écran gardait replié : « Pas maintenant » masque volontairement la saisie,
si bien qu'un médecin qui cliquait ensuite « Oui » lisait « Enregistrez le
diagnostic ci-dessous » sans rien avoir en dessous. Une impasse, du même
genre que le bouton d'orientation de l'ADR-106.

Cliquer « Oui » alors qu'aucun diagnostic n'est enregistré n'est pas une
réponse : c'est l'intention d'en poser un. L'écran ouvre donc la saisie et
n'envoie rien, plutôt que d'envoyer une réponse dont il sait qu'elle sera
rejetée ; la ligne « diagnostic différé » cède la place à « enregistrez la
conclusion ci-dessous ». La réponse part réellement une fois le diagnostic
consigné. C'est de l'ergonomie, pas un assouplissement : le serveur refuse
toujours une réponse forgée, et la clôture continue d'exiger un diagnostic
(CDC §33.1, ADR-081) sauf pour un passage paraclinique seul (ADR-094).

## Ce qu'aucune ligne n'invente

La réponse est écrite sur `clinical_examinations`, là où elle vivait déjà —
aucune colonne, aucune table nouvelle. La ligne peut être créée pour un
passage sans examen clinique : elle ne porte alors ni état général, ni
conscience, ni appareil, et `ConsultationWorkflow::hasClinicalExamination()`
— qui teste du contenu réel, jamais l'existence de la ligne — continue de
répondre « aucun examen ». Aucun examen n'est fabriqué.

## Ce qui ne change pas

Le diagnostic peut toujours être **saisi** à l'Examen clinique (ADR-080),
avec sa correction et son annulation réservées à l'auteur (ADR-035, ADR-081).
Seule la question a bougé, pas la saisie. L'Examen clinique mène désormais
toujours à l'étape suivante ; sauter la Paraclinique reste possible, mais
parce qu'elle a été explicitement déclarée non nécessaire (ADR-079).

Aucune permission nouvelle : `consultations.update`, comme le reste de
l'écriture d'une consultation. Le filtrage Vue sert l'ergonomie ; le serveur
revérifie toujours.

---

# ADR-096 — Réouverture tracée d'une consultation clôturée

**Status:** ACCEPTED (2026-09-15 — exigence explicite du propriétaire)

**Construit** le mécanisme que l'ADR-076 annonçait sans le définir :

> Après clôture, `Consultation::isEditable()` devient faux et tous les
> chemins d'écriture ordinaires refusent. Conformément à l'ADR-010, **une
> correction ultérieure exigera son propre mécanisme tracé** ; rien n'est
> réécrit silencieusement.

## Le constat

Un ECG est demandé le matin, la consultation est clôturée, le résultat
arrive l'après-midi. Le médecin saisit son compte rendu depuis « Demandes
d'examens » — mais ne peut plus rien en conclure : le dossier est en lecture
seule. Le compte rendu existe, et la conclusion du dossier l'ignore pour
toujours.

L'ADR-088 documentait déjà une réouverture, mais c'était une **intervention
manuelle** sur un passage bloqué, pas une fonctionnalité.

## Ce que la réouverture défait, et rien d'autre

`ReopenConsultationAction` défait exactement ce que
`CompleteConsultationAction` a fait :

```text
Consultation   COMPLETED → IN_PROGRESS   (completed_at/by remis à null)
Étape Clôture  COMPLETED → NOT_STARTED   (elle est résolue *par* la clôture)
Orientation    COMPLETED → IN_PROGRESS   (le patient revient en file Médecine)
Episode        PENDING_SETTLEMENT → IN_CARE
```

La dernière ligne n'est pas cosmétique : laisser le passage en attente de
règlement le montrerait à la Réception comme prêt à sortir pendant qu'un
médecin y écrit encore. Un statut que la Réception a déjà fait avancer plus
loin n'est jamais ramené en arrière — c'est la garde symétrique de
l'ADR-054.

**Rouvrir n'est pas annuler.** Aucune donnée clinique n'est supprimée : une
sortie médicale déjà prononcée reste prononcée, les diagnostics restent
append-only (ADR-035), le statut médical du passage n'est pas rétabli. Le
médecin complète ; il n'efface pas.

## La limite : tant que la Réception n'a pas clos le passage

`Episode.status` doit rester `OPEN`. Une fois la sortie administrative
prononcée (ADR-090), le compte est soldé, une `PatientDebt` a pu être
enregistrée, et le passage est `CLOSED`. Rouvrir ferait réapparaître un
passage déjà facturé dans une file clinique — et le CDC ne dit nulle part ce
que deviendraient alors la facture et la créance. Cette règle n'est donc pas
inventée : elle est refusée avec un message explicite.

L'alternative « rouvrir même après la sortie administrative » a été
présentée au propriétaire et écartée pour cette raison.

## Motif obligatoire

Contrairement au saut d'une étape (ADR-076), où « aucun examen
complémentaire » se suffit à lui-même, le motif est ici **exigé**. Revenir
sur un dossier médical déjà conclu est exceptionnel : l'audit doit dire
pourquoi, sans quoi la trace ne raconte rien à qui la relira. L'entrée est
enregistrée sous `consultation.reopen` avec l'ancien et le nouvel état, son
auteur et sa date.

## Permission

```text
consultations.reopen    accordée par défaut à MEDICINE
```

Distincte de `consultations.update` : écrire dans une consultation ouverte et
revenir sur une consultation conclue ne sont pas la même autorité, et un site
doit pouvoir accorder l'une sans l'autre. Conformément à l'ADR-064, elle
figure dans `RolePermissionSeeder::GRANTS` pour la création d'un nouveau
site, mais un site déjà en production doit l'ajouter depuis le portail sans
rejouer ce seeder.

`can_reopen_consultation` est calculé **hors** du drapeau `$isActive` du
presenter : une consultation clôturée n'est jamais « active », et exiger
qu'elle le soit rendrait l'action inatteignable.

## Effet de bord corrigé au passage

`recordImagingResult` redirigeait vers l'étape Paraclinique de la
consultation. Depuis « Demandes d'examens » — où le résultat se saisit
souvent après la clôture — cela déposait le médecin sur un dossier en lecture
seule, sans rapport avec ce qu'il faisait. Il revient désormais là d'où il
vient.

---

# ADR-097 — Module Approvisionnement : catalogues fournisseurs, prix d'achat versionné, commandes et réceptions

**Status:** ACCEPTED (2026-09-14 — spécification fonctionnelle détaillée du
propriétaire, § « Commandes, Fournisseurs, Catalogues et Stock Pharmacie »)

Le propriétaire a fourni une spécification complète d'un flux d'achat
Pharmacie manquant : dossier fournisseur façon Drive, catalogue proposé par
chaque fournisseur distinct du catalogue réellement stocké par la clinique,
prix d'achat versionné jamais écrasé, commandes, réceptions partielles et
factures fournisseur. Une section isolée de cette spécification,
« Informations famille », était ambiguë sans lien apparent avec le reste ;
question posée directement au propriétaire, réponse explicite : il s'agit
d'une famille de médicament (ex. « famille Amoxicilline »), donc bien dans
le périmètre de ce module.

## Ce qui existait déjà et n'est pas recréé

Une part significative de l'architecture cible existait déjà avant cette
décision : fournisseurs (`MedicineSupplier`, `medicine_suppliers.*`), lots
FEFO (`MedicineLot`), mouvements de stock immuables portant déjà un prix
d'achat par ligne (`PharmacyStockMovement`, écriture bloquée après création),
entrée de stock (`RecordStockEntryAction`), catalogue clinique avec tarif de
vente versionné (`CatalogItem`/`CatalogTariff`,
`SetCatalogTariffAction`). La règle « ne jamais écraser un prix d'achat
historique » (spec §14) n'exigeait donc aucun nouveau mécanisme : elle est
déjà garantie structurellement par l'immutabilité de
`PharmacyStockMovement` — `ReceiveGoodsAction` s'appuie dessus sans la
réimplémenter.

« Famille de médicament » réutilise `MedicineCategory`/`medicine_categories`
tel quel, seul le libellé change dans l'interface (« Famille de
médicament ») : un second modèle identique aurait été une pure duplication.

## Un nouveau modèle de prix d'achat, distinct du pivot existant

Le pivot `medicine_supplier` (association simple fournisseur ↔ médicament)
possède une clé primaire composite `(medicine_id, medicine_supplier_id)` :
il ne peut physiquement contenir qu'une seule ligne par paire, donc aucun
historique de prix ne peut y être superposé. `medicine_supplier_offers` est
donc un nouveau modèle dédié, qui reprend exactement le mécanisme
fermeture/réouverture de `SetCatalogTariffAction`
(`SetMedicineSupplierOfferAction`) : modifier un prix fournisseur ne mute
jamais la ligne existante, il la ferme (`effective_until`, `active_key`
vidé) et en ouvre une nouvelle (`active_key = 'CURRENT'`). L'unicité porte
sur `(medicine_id, medicine_supplier_id, active_key)`, jamais sur
`medicine_id` seul : plusieurs fournisseurs peuvent donc détenir
simultanément une offre `CURRENT` pour le même médicament, à des prix
différents (spec §5).

Ce prix d'achat fournisseur (`MedicineSupplierOffer.quoted_price`) reste
strictement séparé du prix de vente patient (`CatalogTariff`) : aucun des
deux mécanismes ne lit ni n'écrit l'autre.

## Catalogue fournisseur : proposé, jamais confondu avec le stock clinique

Un fournisseur peut détenir plusieurs fichiers de catalogue
(`supplier_catalogs`, Excel ou PDF), stockés sur le disque privé sous un
chemin scopé par son UUID, avec historique complet et au plus un catalogue
`active_key = 'ACTIVE'` à la fois par fournisseur. Importer un catalogue
Excel (`SupplierCatalogImportService`, colonnes Référence/Médicament/
Présentation/Prix fournisseur) crée des `supplier_catalog_items` — des
lignes brutes, non liées à `Medicine`, exactement comme la spécification
l'exige (spec §3, « ce n'est PAS le stock de la clinique ») : import
atomique, toutes les lignes validées avant toute écriture, échec d'une seule
ligne annule le fichier entier, suivant le patron déjà en place dans
`MedicineCatalogImportService`/`ImportEmployeesAction`.

Un catalogue PDF est stocké et consultable en ligne, jamais analysé :
aucune bibliothèque de parsing PDF n'existe dans `composer.json`, et en
ajouter une pour en extraire ne serait-ce que du texte brut inventerait une
capacité hors du périmètre demandé. Une ligne de catalogue Excel ne rejoint
le catalogue clinique que par un acte explicite de liaison
(`LinkSupplierCatalogItemAction`, `linked_medicine_id`), qui crée alors la
première `MedicineSupplierOffer` versionnée pour ce médicament et ce
fournisseur (spec §5/§6).

## Commande, réception distincte, jamais automatique

`PurchaseOrder` suit `Draft → Ordered → PartiallyReceived/Received` ou
`Cancelled`. Chaque ligne de commande fige son `unit_price` au moment de la
commande (l'offre courante du fournisseur si elle existe, sinon un prix
saisi) : une révision ultérieure du prix fournisseur ne recalcule jamais une
commande déjà passée. Seule une commande `Draft` peut être modifiée ; une
commande `Received` ou `Cancelled` ne peut plus être annulée.

La réception (`ReceiveGoodsAction`) est un acte distinct et jamais
automatique (spec §9) : une commande de 100 unités peut être réceptionnée en
plusieurs fois (80 puis 20), chaque réception appelant directement
`RecordStockEntryAction` — inchangée — pour chaque ligne reçue, ce qui crée
le lot, le mouvement de stock immuable à son propre prix d'achat, et
incrémente `quantity_received` sur la ligne de commande. Le statut de la
commande est recalculé après chaque réception
(`PartiallyReceived`/`Received`) sans jamais rétro-modifier une réception
antérieure. Un prix d'achat saisi à la réception reste gouverné par la
permission `stock.cost.record` déjà existante, exactement comme une entrée
de stock manuelle.

La facture fournisseur (`SupplierInvoice`) est une pure écriture comptable,
optionnellement liée à une commande et/ou une réception : elle ne crée, ne
modifie et ne consulte jamais un mouvement de stock, un lot ou une quantité
— cet impact est déjà entièrement porté par la réception.

## Permissions

Nouvelles permissions, accordées par défaut uniquement au rôle `PHARMACY`
(aucun rôle « Achats » séparé n'est justifié par l'architecture actuelle,
qui rattache déjà `medicine_suppliers.*`/`stock.*` à ce rôle) :

```text
supplier_catalogs.view / create / update / delete / restore
medicine_supplier_offers.view / create / update
purchase_orders.view / create / update / submit / cancel
goods_receipts.view / create
supplier_invoices.view / create / delete / restore
```

Aucune de ces permissions ne touche `payments.*`, `cash.*` ou `receipts.*` :
ce module ne crée et ne modifie aucun encaissement (ADR-013).

---

# ADR-098 — Refonte du module Pharmacie : navigation unique, fournisseurs restreints, redondances supprimées

**Status:** ACCEPTED (2026-09-14 — exigence explicite du propriétaire, après
analyse préalable du code et arbitrages posés directement au propriétaire)

**Amende l'ADR-097** sur l'attribution des permissions d'approvisionnement.
Confirme sans les modifier l'ADR-024 (référentiel réservé), l'ADR-027
(séparation des identités centrales et opérationnelles) et l'ADR-049/072
(circuits de délivrance et de consommables).

Le propriétaire a demandé une refonte réfléchie du module Pharmacie, destinée
à des utilisateurs peu à l'aise avec l'informatique, en exigeant d'analyser
l'existant avant toute modification. Cette décision consigne ce que l'analyse
a établi, les conflits signalés et les choix retenus.

## Ce qui n'était pas une redondance

`catalog_items` et `medicines` forment une spécialisation 1‑1 stricte : aucune
colonne n'est recopiée, et il n'existe aucun concept « produit » ou
« article » concurrent. `medicine_lots.quantity_on_hand` est l'unique source
physique du stock ; `pharmacy_stock_movements.balance_after` est une trace
d'audit, jamais relue pour calculer un solde. Les instantanés (libellés et
prix figés sur une ligne de délivrance ou de commande) sont des gels
d'historique voulus. Rien de tout cela n'est modifié.

Les deux tables de réservation (`medicine_stock_reservations` pour une
ordonnance, `pharmacy_dispense_lot_reservations` pour une délivrance) portent
une notion proche mais deux origines réellement distinctes. Les fusionner
toucherait un circuit FEFO testé pour un bénéfice faible : elles restent
séparées, volontairement.

## Ce qui était redondant, et comment c'est résolu

```text
Deux systèmes de navigation      page unique à faux onglets + pages réelles
                                 → le menu latéral devient la seule navigation
Disponibilité calculée 3 fois    MedicineStockService, Overview, AlertService
                                 → MedicineLot::scopeWithReservedQuantity()
Deux listes de fournisseurs      pivot medicine_supplier / offres versionnées
                                 → l'offre fait foi, le pivot est tenu à jour
Libellés de statut recopiés      tables de libellés dans plusieurs écrans
                                 → libellés servis par le backend, Badge partagé
```

**Navigation.** `Pharmacy/Index.vue` regroupait quatre écrans derrière des
onglets locaux qui ne changeaient pas l'adresse, tandis que l'approvisionnement
utilisait de vraies routes ; le même composant de barre d'onglets se
comportait différemment selon l'écran. La barre d'onglets et la barre
d'approvisionnement sont supprimées. L'entrée « Pharmacie » du menu latéral
devient un groupe dont chaque sous-entrée ouvre une vraie page, filtrée par la
permission de l'écran qu'elle ouvre : Accueil, Vente comptoir, Ordonnances à
délivrer, Consommables Soins, Stock, Médicaments, Commandes, Réceptions,
Factures fournisseurs, Fournisseurs. Chaque page a une adresse propre et le
bouton Précédent du navigateur fonctionne. `PharmacyController` est scindé par
domaine sous `App\Http\Controllers\Pharmacy\`, avec les contrôleurs
d'approvisionnement, et `PharmacyWorkspaceService` sert une méthode par écran
au lieu de charger toutes les données à chaque visite.

**Disponibilité.** La définition de « ce qu'un lot tient encore pour d'autres »
vit désormais à un seul endroit, `MedicineLot::scopeWithReservedQuantity()` et
`reservedQuantity()`. L'alerte de seuil applique la même règle par lot que les
écrans de stock ; les deux formules ne diffèrent que dans un état impossible
(réservé supérieur au physique), que les actions de stock refusent déjà.

**Fournisseurs d'un médicament.** Le pivot `medicine_supplier` était écrit mais
jamais lu pour affichage, en concurrence avec `medicine_supplier_offers`.
L'offre versionnée fait foi ; `SetMedicineSupplierOfferAction` inscrit aussi le
lien simple, pour que les deux ne puissent jamais diverger. Le pivot n'est pas
supprimé : il reste le lien « peut fournir » posé à la création d'un
médicament.

## Conflit signalé : « seul le SuperAdmin voit les fournisseurs »

La demande initiale était inapplicable telle quelle : un compte `SUPER_ADMIN`
ne peut jamais ouvrir de session sur un site clinique
(`DeploymentAccountPolicy`, ADR-027) et n'y reçoit aucune permission. Réservé
au seul Super Admin sur le site, l'espace Fournisseurs n'aurait été visible de
personne. Arbitrages retenus par le propriétaire :

```text
Fournisseurs et catalogues   gérés depuis le portail central, par API du site
Commandes, réceptions,       restent au site clinique : la réception est un
factures                     acte physique (lot et péremption lus sur la boîte)
Explorateur de dossiers      gestion complète au portail ; consultation au site
Droits correspondants        accordés à aucun rôle par défaut
medicines.create             reste réservé (ADR-024 inchangée)
```

## Permissions : accordées à personne par défaut

`medicine_suppliers.*`, `supplier_catalogs.*`, `medicine_supplier_offers.*`,
`purchase_orders.*`, `goods_receipts.*` et `supplier_invoices.*` sont retirées
du socle `PHARMACY`, où l'ADR-097 les avait placées la veille. Le Super Admin
les accorde nominativement, depuis le portail, aux comptes locaux qui font
réellement ce travail (éditeur de socle ADR-064, ou exception individuelle
ADR-022/033). Le menu ne montre une entrée qu'avec sa permission, et chaque
route la vérifie côté serveur.

Retirer ces lignes du seeder ne suffit pas sur une base déjà initialisée : la
migration `2026_09_14_140000_revoke_procurement_permissions_from_pharmacy_role`
les retire du seul rôle `PHARMACY`, sans toucher aux exceptions individuelles.
Conformément à l'ADR-064, `RolePermissionSeeder` ne doit toujours pas être
rejoué sur un site en production. Le compte de test local (ADR-086) reçoit ces
droits nominativement pour pouvoir parcourir toute la chaîne.

Un prix fournisseur exige `.create` la première fois et `.update` ensuite, ce
que tranche `SetMedicineSupplierOfferAction`. Une route ne pouvant exiger
qu'une capacité, la capacité `set-medicine-supplier-offer` (l'une ou l'autre)
protège désormais les deux routes qui en étaient dépourvues.

## Espace Fournisseurs : dossiers, aperçu, ajout au catalogue clinique

Les fournisseurs sont présentés comme des dossiers ; ouvrir un dossier montre
ses sous-dossiers — Catalogues, Commandes, Factures, Produits et prix —, chacun
visible seulement avec sa propre permission. Les catalogues sont listés comme
des fichiers ; les anciens prix restent consultables.

Un catalogue Excel n'est plus lu à l'aveugle. `SupplierCatalogImportService::preview()`
applique exactement les contrôles de `import()` sans rien écrire : colonnes
manquantes nommées, chaque ligne marquée correcte ou accompagnée de ses
erreurs. L'import n'est proposé que si aucune ligne n'est incorrecte, et reste
atomique.

Depuis une ligne de catalogue fournisseur, un compte autorisé peut ajouter le
médicament au catalogue clinique. Création du médicament et rattachement au
prix du fournisseur forment une seule transaction : si le rattachement est
refusé, aucun médicament à moitié créé ne subsiste. Ce bouton exige les mêmes
droits que toute création de médicament (ADR-024).

## Pilotage depuis le portail central

Un Super Admin ne se connecte jamais à un site clinique (ADR-027). Les
dossiers fournisseurs et leurs catalogues sont donc gérés depuis
`admin.rivo.mg` › Fournisseurs pharmacie, site par site, uniquement par l'API
du site (`/api/v1/super-admin/pharmacy/suppliers*`), selon le même principe que
les adresses, les tarifs et les mutuelles (ADR-042/044/045). Le portail peut
créer un fournisseur, ajouter un catalogue, voir l'aperçu d'import, importer,
activer, archiver avec motif et restaurer. Commandes, réceptions et factures
restent sur le site : ce sont des opérations physiques de la pharmacie.

Aucune règle n'est réécrite pour le portail : l'API appelle les mêmes Actions
que la clinique. Ces Actions reçoivent désormais un `CatalogActor`, compte
local ou Super Admin distant ; le site revérifie la permission transmise et
l'audit enregistre l'identité UUID/nom du Super Admin. Un catalogue ajouté
depuis le portail n'a pas d'auteur local : `supplier_catalogs` conserve
`external_created_by_uuid/name`, et `supplier_catalog_items.created_by` devient
nullable.

Le catalogue est le premier fichier transmis du portail vers un site. Il part
en multipart, tel que le Super Admin l'a choisi, jamais converti : la pharmacie
doit pouvoir rouvrir l'original. L'idempotence couvre ce corps comme un corps
JSON. **Amendement du 2026-09-17 :** le fichier se télécharge désormais depuis
le portail, relayé par l'API du site (`/catalogs/{uuid}/download`). Il continue
de résider sur le site — rien n'est copié au centre — mais vérifier un
catalogue avant de l'importer exigeait d'ouvrir le fichier, et le portail ne
le permettait pas.

**Amendement du 2026-09-15 — commandes et factures depuis le portail.** Le
propriétaire revient sur l'arbitrage « commandes, réceptions et factures restent
au site » : le Super Admin peut désormais, depuis le dossier fournisseur d'un
site, créer, envoyer et annuler une commande, et enregistrer (avec son
document), archiver et restaurer une facture. **La réception reste au site**,
choix explicite du propriétaire : c'est la personne qui a la marchandise sous
les yeux qui lit les lots et les péremptions et fait entrer le stock. Le portail
voit l'état des réceptions sans jamais en proposer une.

Rien n'est réécrit pour le portail : l'API du site
(`/pharmacy/suppliers/{uuid}/orders*`, `/invoices*`, `/order-form`,
`/invoice-form`) appelle les mêmes Actions que la clinique, qui reçoivent
désormais un `CatalogActor`. Une commande ou une facture écrite depuis le
portail n'a pas d'auteur local : `purchase_orders` et `supplier_invoices`
conservent `external_created_by/updated_by(/cancelled_by)_uuid/name`, et
`created_by`/`updated_by` deviennent nullables. Le document d'une facture part
en multipart, ses lignes en JSON dans le même envoi. Formulaires et détails de
commande et de facture sont des composants partagés entre les deux espaces.

Une règle d'intégrité est ajoutée au passage, pour la clinique comme pour le
portail : une facture ne peut plus être rattachée à la commande ou à la
réception d'un autre fournisseur.

Le dossier d'un fournisseur se présente au portail comme à la clinique :
coordonnées, puis les sous-dossiers Catalogues, Commandes, Factures, Produits
et prix, chacun ouvert avec sa propre permission et lu par l'API du site
(`/pharmacy/suppliers/{uuid}`, `/orders`, `/invoices`, `/products`). Les
catalogues y restent gérables ; commandes et factures y sont en consultation,
car elles se passent et se réceptionnent à la pharmacie du site. Le résumé d'une
commande, d'une facture et les compteurs du dossier sont produits par
`SupplierPresenter`, utilisé à la fois par la clinique et par l'API : les deux
écrans ne peuvent pas décrire différemment les mêmes données.

## Liste des fournisseurs : import, export, correction, archivage

Le portail exporte en Excel les fournisseurs d'un site ou de tous les sites,
archivés compris, et importe une liste en deux temps. Le portail ne lit que la
forme du fichier ; le site décide de chaque règle, dans
`MedicineSupplierImportService` :

```text
analyser   chaque ligne est classée à créer / à mettre à jour / inchangée /
           à corriger, avec l'ancienne et la nouvelle valeur — rien n'est écrit
confirmer  le site refait la même analyse sous verrou, puis écrit tout ou rien
```

Règles retenues :

```text
le code identifie le fournisseur   jamais modifiable : les imports de médicaments
                                   et de fournisseurs le désignent par ce code
une cellule vide garde la valeur   omettre une colonne n'efface pas un téléphone
un fournisseur archivé             jamais recréé ni ranimé par un fichier :
                                   il se restaure depuis son dossier
un code en double dans le fichier  tout le fichier est refusé
une ligne « Archivé »              ignorée : un export se réimporte sans erreur
```

L'aperçu est conservé dans la session du Super Admin et ne peut être confirmé
qu'une fois ; une page actualisée ne rejoue jamais un import.

Les permissions `medicine_suppliers.update`, `.delete` et `.restore`
existaient sans aucun écran. Le dossier fournisseur du portail permet
désormais de corriger les coordonnées, d'archiver avec motif et de restaurer.
Un archivage est refusé tant qu'une commande est en brouillon ou attend une
réception : sa réception enregistre une entrée de stock contre ce fournisseur,
qu'un fournisseur archivé ne permettrait plus. Un dossier archivé reste
consultable, en lecture seule.

`medicine_suppliers.import` et `medicine_suppliers.export` sont ajoutées. Comme
les droits d'approvisionnement, elles sont enregistrées par migration — et non
seulement dans `PermissionSeeder` — afin qu'une base déjà initialisée les
reçoive, et accordées au seul `SUPER_ADMIN` du portail (ADR-027).

## Une seule page d'accueil

L'ancienne page « Accueil » de la Pharmacie (`/pharmacy`) doublait la Vue
d'ensemble : le pharmacien disposait de deux accueils, dont l'un presque vide.
Les tâches du jour, « À surveiller » et « À recommander » s'affichent
désormais sur la Vue d'ensemble de tout compte ayant `pharmacy.view`, et
l'entrée « Accueil » quitte le menu. `/pharmacy` redirige vers la Vue
d'ensemble, pour que les liens existants restent valides.

## Entrée de stock : les médicaments du fournisseur choisi

Le fournisseur se choisit en premier. « Tous les fournisseurs » affiche tout
le catalogue et n'enregistre aucun fournisseur sur l'entrée ; un fournisseur
choisi réduit la liste aux médicaments qu'il fournit — lien simple ou prix
fournisseur en cours, l'un suffit puisque l'offre tient le lien à jour. Son
prix actuel pré-remplit le prix d'achat, sans jamais remplacer un prix saisi
par le pharmacien, et n'est exposé qu'avec `medicine_supplier_offers.view` et
`stock.cost.record`. Ce filtre est une aide de saisie : le serveur n'invente
aucune règle interdisant d'enregistrer un médicament hors de ce lien.

## Simulation locale

`DevelopmentProcurementSeeder` (ADR-086, local uniquement) simule la chaîne
d'approvisionnement au-dessus du stock de démonstration : prix par
fournisseur avec historique, catalogues (Excel actif, ancien tarif, PDF),
commandes dans chaque état, réceptions partielles et complète, facture
fournisseur. Tout passe par les vraies Actions et ne s'exécute qu'une fois.
Les comptes Pharmacie de test reçoivent nommément les droits
d'approvisionnement, sans jamais écraser une décision existante.

## Fusions retenues après analyse (2026-09-15)

Arbitrages posés au propriétaire après analyse des écrans Stock, Médicaments
et Commandes :

```text
Stock + Médicaments      une page « Médicaments & stock » (/pharmacy/stock)
                         /pharmacy/medicines redirige ; familles et import y sont
Commandes, réceptions,   une page « Achats » à onglets (Commandes, À réceptionner,
factures                 Réceptions, Factures) ; chaque onglet garde son adresse
```

Les deux listes présentaient le même médicament deux fois. La page fusionnée
exige `stock.view` **ou** `medicines.view` (capacité `view-pharmacy-catalog`) ;
un compte qui ne voit que le catalogue reçoit les quantités à `null`, jamais à
zéro, pour ne pas lire « rupture » là où il n'a simplement pas le droit de voir.
« À réceptionner » regroupe les commandes `ORDERED` et `PARTIALLY_RECEIVED` ;
`/pharmacy/purchases` ouvre le premier onglet que le compte peut voir.

**Entrée de stock par livraison.** La livraison (fournisseur, date, provenance,
rangement, motif) est saisie une fois ; les médicaments s'ajoutent un par un
dans une liste relue, modifiable et retirable, puis tout est enregistré en une
seule transaction (`RecordStockEntriesAction`, qui réutilise
`RecordStockEntryAction` ligne par ligne). Une ligne refusée annule toutes les
autres et l'erreur est rattachée à sa ligne. Un même lot du même médicament
deux fois dans la liste est refusé.

**Inventaire par feuille de comptage.** Le pharmacien saisit ce qui est sur
l'étagère ; seuls les lots dont le comptage diffère deviennent des ajustements
`INVENTORY`, validés ensemble avec un motif commun (`RecordInventoryCountAction`,
qui réutilise `AdjustMedicineStockAction`). Un lot conforme ne crée aucun
mouvement. Un comptage inférieur aux quantités réservées reste refusé
(ADR-049). La feuille s'imprime pour le comptage sur papier. Droit : `stock.adjust`.

**Étiquettes QR.** Les médicaments se cochent (ou « Tout sélectionner » sur la
liste filtrée) pour imprimer une planche d'étiquettes. Le QR porte le
code-barres, sinon le code du médicament : la recherche de la vente comptoir
retrouve les deux. L'impression est entièrement côté navigateur et n'écrit rien.

**Colonne Actions.** Les tableaux Pharmacie et les dossiers fournisseurs du
portail portent une colonne « Actions » explicite (Voir, Réceptionner, Lots,
Voir le stock…). Chaque bouton reste soumis à la permission de l'écran qu'il
ouvre ; le Super Admin du portail les voit toutes, sans contournement.

## Consulter un dossier fournisseur (2026-09-15)

Choix du propriétaire : `medicine_suppliers.view` suffit pour consulter tout
le dossier d'un fournisseur en lecture seule — catalogues et leur contenu,
commandes, factures, produits et prix. Chaque sous-dossier reste aussi ouvert
par sa propre permission de consultation. Les listes globales Commandes et
Factures (tous fournisseurs) exigent toujours leur permission ; avec le seul
droit fournisseur, elles ne s'ouvrent que filtrées sur un fournisseur. Toute
écriture (créer, modifier, envoyer, importer, archiver) garde sa permission.

## Corriger ce qui a été enregistré (2026-09-15)

À la demande du propriétaire, les tableaux Médicaments, Familles, Commandes,
Factures fournisseurs et Catalogues proposent Modifier / Archiver / Restaurer,
à la clinique comme au portail (toujours par l'API du site). Rien n'est
supprimé physiquement (ADR-010) :

```text
Médicament        Modifier la fiche (le code ne change jamais)
                  Nouveau prix : motif obligatoire, l'ancien prix reste (ADR-024)
                  « Désactiver » au lieu d'archiver : ordonnances, lots et
                  mouvements continuent de le désigner ; motif conservé
                  Refusé tant que des unités sont réservées
Famille           Renommer, archiver avec motif, restaurer
                  Archivage refusé tant qu'un médicament actif y appartient
Commande          Modifier tant qu'elle est en brouillon ; ensuite on l'annule
Facture           Modifier (nouvelle permission supplier_invoices.update) ;
                  le fournisseur ne change pas, le stock n'est jamais touché,
                  un nouveau document remplace l'ancien seulement à l'enregistrement
Catalogue         Modifier la date et la remarque ; le fichier ne se remplace
                  jamais : un nouveau tarif est un nouveau catalogue
Réceptions, lots, mouvements   jamais modifiables : correction de stock tracée
```

Un fournisseur qui a un prix en cours reste rattaché au médicament même s'il
est décoché, pour que le lien et l'offre ne divergent pas. Les deux gardes
(réservations en cours, médicaments actifs d'une famille) protègent
l'intégrité des données existantes ; elles n'ajoutent aucune règle de
délivrance.

## Préparer un achat : comparer, puis commander (2026-09-17)

Demande du propriétaire, après usage réel du module. Quatre défauts constatés
dans le code, et les règles retenues pour les corriger.

**Le formulaire de commande ignorait le fournisseur.** `ProcurementFormOptions::orderMedicines()`
listait tout le catalogue clinique actif : commander chez un fournisseur
proposait des centaines de produits qu'il ne vend pas. Il n'offre désormais
que ce que ce fournisseur fournit réellement — prix en cours ou lien de son
dossier — et ne retombe sur le catalogue entier que si le fournisseur n'a
encore aucun produit, pour qu'une première commande reste possible avant tout
import de catalogue. Le serveur n'interdit toujours rien ici : il cesse de
deviner.

**Comparer les prix n'était possible qu'en ouvrant les dossiers un par un.**
`/super-admin/pharmacy-suppliers/{site}/commander` réunit, pour chaque
médicament, le prix courant de chaque fournisseur, le moins cher signalé, et
ce que la clinique tient encore en stock. Rien de nouveau n'est stocké :
`SupplierOfferComparison` relit les offres versionnées de l'ADR-097 et les
lots. Le choix reste celui de l'acheteur — un prix plus bas peut venir d'un
fournisseur en rupture ou plus lent, et l'écran ne décide jamais.

**Le comparateur montre aussi les catalogues (2026-09-18).** Il ne lisait
que les prix déjà rattachés à un médicament : un fournisseur dont le
catalogue était importé mais pas encore repris n'y proposait rien, alors que
le formulaire de commande de son dossier listait toutes ses lignes. Les
lignes du catalogue **actif** non encore reprises y figurent désormais,
regroupées par nom (`ProductLabel`) avec le même produit chez les autres
fournisseurs, et marquées « Nouveau ». Commandées, elles entrent au
catalogue de la clinique comme depuis le dossier (voir « Commander une ligne
de catalogue » ci-dessous). Une ligne sans prix se commande avec un prix
saisi par l'acheteur.

**Une commande reste le fait d'un seul fournisseur.** Le propriétaire demande
de sélectionner plusieurs fournisseurs avant de choisir les produits. Une
`PurchaseOrder` appartient pourtant à un fournisseur unique (ADR-097), et la
réception comme la facture pointent cette commande. Le panier peut donc
couvrir plusieurs fournisseurs, mais il part en **une commande par
fournisseur** : trois fournisseurs, trois brouillons. Chaque envoi est une
commande distincte au site — un refus sur l'un n'annule pas les autres, et
l'écran nomme ceux qui sont passés.

**La facture exigeait de ressaisir chaque médicament.** Une facture est
d'abord une pièce comptable : son numéro, sa date, son montant et son
document. Les lignes deviennent facultatives (`total_amount` obligatoire en
leur absence), et le détail reste proposé pour qui le veut. Avec des lignes,
le total est leur somme : un montant saisi ne peut jamais contredire ce qui
est listé en dessous. Le stock n'est toujours pas touché — c'est la réception
qui fait entrer la marchandise, et elle reste au site.

## Un catalogue fournisseur peut arriver sans prix (2026-09-17)

Constat sur un fichier réel : le catalogue Arbiochem, 119 produits, **aucun
prix** — la colonne existe, ses cellules sont vides. L'import refusait donc
le fichier entier, avec un message qui ne disait ni quelle ligne, ni quelle
colonne.

Le prix devient facultatif à l'import (`supplier_catalog_items.supplier_price`
nullable). Un catalogue est d'abord la liste de ce que le fournisseur
propose ; son tarif arrive parfois séparément, ou plus tard. Ce qui exige un
prix, c'est le **rattachement** d'une ligne à un médicament de la clinique :
c'est lui qui crée le prix d'achat versionné (ADR-097), et
`LinkSupplierCatalogItemAction` le refuse explicitement quand la ligne n'en
porte aucun.

Deux corrections d'ergonomie qui vont avec :

```text
montant formaté   « 4 500,50 Ar » est un prix, pas une erreur : espaces
                  insécables, séparateurs et devise sont du formatage
erreur précise    ligne, colonne, valeur lue et raison — « Ligne 15,
                  colonne « Prix fournisseur » (« 100Ar ») : le prix doit
                  être un nombre » plutôt qu'un refus global
```

Le parcours Exporter le canevas → remplir → importer est ainsi refermé : le
canevas exporté porte exactement les quatre colonnes attendues, et un fichier
rempli sans prix s'importe au lieu d'être rejeté.

## Corbeille et suppression définitive (2026-09-17)

`TrashCategory` reçoit `MEDICINE_SUPPLIER`, `SUPPLIER_CATALOG` et
`SUPPLIER_INVOICE` : un fournisseur, un catalogue ou une facture archivés
quittent les listes et se retrouvent, avec leur motif et leur auteur, dans la
Corbeille de l'ADR-061, d'où ils se restaurent par leur permission de
restauration habituelle.

La suppression définitive est ajoutée, volontairement étroite (ADR-010, même
raisonnement que l'ADR-062 pour un compte n'ayant jamais servi) :

```text
permission        trash.force_delete, réservée au SUPER_ADMIN du portail
jamais proposée   depuis une liste : uniquement depuis la Corbeille,
                  après une confirmation qui dit que c'est irréversible
refusée dès que   le modèle est référencé — isForceDeleteProtected()
```

Le garde-fou n'est pas réécrit dans la Corbeille : il vit sur le modèle, où
il servait déjà.

**Amendement du même jour**, après usage : un catalogue bloquait la
suppression du fournisseur, alors qu'il n'appartient qu'à lui. Ce qui bloque
est désormais uniquement l'**histoire** du dossier, et ce qui n'appartient
qu'à lui **part avec lui** :

```text
bloque        commande, facture, lot reçu, mouvement de stock, prix d'achat
part avec     fichiers de catalogue (et leurs lignes, et le fichier sur le
              disque), rattachements « peut fournir »
```

Un catalogue ne se protège donc pas contre la fin de son propre dossier — il
n'aurait plus rien à désigner. Le drapeau qui l'y autorise est posé par la
Corbeille seule, jamais par une requête. Une facture fournisseur, elle,
n'est jamais détruite : c'est une pièce comptable. Ce qui a servi reste dans
la Corbeille, restaurable.

## D'où vient un produit (2026-09-17)

La page « Produits et prix » d'un fournisseur dit désormais, par ligne, le
fichier de catalogue dont elle vient (ou « saisi à la main ») et si la
clinique a déjà réceptionné ce produit, avec ce qu'elle en tient. Un
catalogue fournisseur **propose** ; ce que la pharmacie détient vient d'une
réception.

**Conflit signalé — « seuls les produits réceptionnés entrent au catalogue
pharmacie » (point 15 du propriétaire).** La règle ne peut pas être appliquée
telle quelle : une ligne de commande référence un `Medicine`, donc le produit
doit exister au catalogue clinique **avant** d'être commandé, sinon il est
impossible de le commander. La séparation demandée existe cependant déjà dans
les faits, et l'interface la rend maintenant visible : un médicament jamais
réceptionné n'a ni lot, ni stock, ni prix de vente, et ne peut donc être ni
délivré ni vendu (ADR-036, ADR-049). Le prix d'achat (`MedicineSupplierOffer`)
et le prix de vente (`CatalogTariff`) restent deux mécanismes séparés qui ne
se lisent ni ne s'écrivent l'un l'autre — modifier un prix fournisseur ne
touche jamais un prix de vente.

## Commander une ligne de catalogue (2026-09-18)

Le paragraphe ci-dessus renvoyait la suite au propriétaire : « commander
autrement qu'en désignant un médicament : à trancher ». Le constat qui la
tranche est arrivé le lendemain, sur une base réelle — un fournisseur, un
catalogue de 119 lignes importé, zéro médicament au catalogue clinique :
l'écran « Créer une commande » ne proposait rien du tout. Exigence du
propriétaire : « tous les médicaments du catalogue utilisé doivent
apparaître sur la liste de commande ».

Le formulaire réunit donc les deux sources, annoncées comme telles :

```text
Déjà au catalogue de la clinique     un Medicine, prix fournisseur en tête
Au catalogue du fournisseur          les lignes de son catalogue ACTIF que
                                     la clinique n'a pas encore reprises
```

Seul le catalogue **actif** est proposé : un ancien tarif est de l'histoire,
pas une liste de courses (ADR-097). Lire un formulaire n'écrit rien — une
ligne de catalogue est désignée par son propre UUID
(`supplier_catalog_item_uuid`), jamais par un médicament créé à l'affichage.

**Le produit entre au catalogue au moment où il est commandé**, par
`CreateMedicineFromSupplierCatalogAction`, dans la transaction de la
commande. C'est l'ordre naturel : ce que la clinique va tenir est justement
ce qu'elle a décidé d'acheter. Ce qui est créé se limite à ce que le
fournisseur dit lui-même :

```text
créé        code (sa référence), libellé, présentation comme unité
créé        son prix d'achat versionné, si la ligne en porte un (ADR-097)
non créé    prix de vente — ADR-024 : le prix d'achat n'est pas le prix
            de vente, et personne ne l'a encore décidé
non créé    DCI, forme, dosage, fabricant — un libellé commercial n'est
            pas une DCI ; les déduire inventerait un fait clinique
```

Le médicament est donc `billable` **sans tarif**, un état qu'ADR-031
définit déjà : la facturation attend, rien n'est inventé. Il ne peut par
construction être ni délivré ni vendu tant que le pharmacien n'a pas fixé
ce prix, après la réception — exactement la séparation du point 15, obtenue
sans interdire la commande. `CreateCatalogItemAction` accepte pour cela un
élément facturable sans tarif initial ; tout écran qui nomme un prix en
envoie toujours un, et sa FormRequest continue de l'exiger.

Rattacher la ligne au médicament créé rend l'opération rejouable : la
commander une seconde fois réutilise le produit au lieu d'en créer un
double. Une ligne appartenant à un autre fournisseur est refusée — son UUID
est public, et l'accepter rattacherait un produit à un dossier qui ne l'a
jamais proposé.

**Un même produit chez deux fournisseurs reste un seul produit.** Le
rattachement ci-dessus ne protège que la même ligne de catalogue ; deux
fournisseurs qui proposent chacun « Paracétamol 500 mg » en auraient créé
deux, avec deux stocks, deux historiques et deux prix de vente à tenir. Le
libellé est donc comparé au catalogue de la clinique sans accents, sans
casse et sans espaces doubles — deux fournisseurs écrivent rarement un
produit de la même façon — et le produit trouvé est réutilisé : le second
prix d'achat vient simplement se placer à côté du premier, ce que
`medicine_supplier_offers` permet depuis l'ADR-097.

Un produit **désactivé** n'est en revanche jamais ranimé par une commande :
le désactiver était une décision motivée (voir plus haut), et le remettre en
service en est une autre. La commande est refusée avec un message qui le
dit, plutôt que de créer le doublon que cette règle vient d'écarter.

Une commande écrite depuis le portail n'a pas d'auteur local :
`medicines.created_by` et `medicine_supplier_offers.created_by` deviennent
donc nullables, avec les colonnes `external_*` en regard, selon le patron
déjà appliqué aux commandes et aux factures fournisseur. `LinkSupplierCatalogItemAction`
et `SetMedicineSupplierOfferAction` reçoivent un `CatalogActor` au lieu d'un
`User`, comme le reste du domaine catalogue.

Le produit se choisit dans une **fenêtre cherchable** et non dans une liste
déroulante : un catalogue fournisseur compte couramment plus de cent lignes,
et chaque ligne y annonce son prix fournisseur, sa provenance (catalogue de
la clinique ou du fournisseur) et si elle est déjà dans la commande. La
ligne ajoutée reprend ce prix, qui reste modifiable — c'est le prix de la
commande qui est figé (ADR-097), pas celui du catalogue.

Permissions inchangées : commander reste `purchase_orders.create`, et faire
entrer un produit au catalogue reste `medicines.create` + `catalog.items.create`
(ADR-024) — un compte qui ne les a pas commande normalement ce que la
clinique tient déjà, et se voit refuser la ligne de catalogue côté serveur,
jamais seulement dans l'interface.

## Corriger une ligne de catalogue (2026-09-18)

Audit demandé par le propriétaire, module Pharmacie. Trois constats, et ce
qu'ils ont donné.

**Une ligne de catalogue n'était ni modifiable ni retirable.** Aucune route
n'existait, ni au site ni au portail : un import mal transcrit — colonne
décalée, prix dans la mauvaise unité, libellé tronqué — restait faux pour
toujours. Elle se corrige désormais, et se met à la corbeille avec un motif.
`supplier_catalog_items` reçoit pour cela `deleted_at`/`deleted_by`/
`delete_reason` (ADR-009) ; rien n'est détruit, un prix d'achat peut déjà
pointer dessus.

Aucune permission n'est créée : une ligne appartient à son fichier, donc qui
peut corriger le catalogue peut corriger ses lignes (`supplier_catalogs.update`
/ `.delete` / `.restore`).

**Un rattachement erroné était définitif.** Cas réel constaté en base :
`ALCO-001 « Alcool bleu 25Litre »` rattaché au médicament
« Alcool blanc 25Litre » — deux produits différents, un prix d'achat
enregistré contre le mauvais. Rattacher existait depuis le premier jour,
défaire non. `UnlinkSupplierCatalogItemAction` le permet et **clôt** le prix
que ce rattachement avait créé (`effective_until`, `active_key` libéré),
exactement comme une révision de prix clôt la précédente. Elle ne le supprime
pas : la clinique a réellement cru ce prix pour ce produit, et l'audit doit
continuer de le dire. Droit réutilisé : `medicine_supplier_offers.update`.

**Relire un catalogue effaçait le travail de rattachement.** `import()`
supprimait les lignes puis les recréait : tous les `linked_medicine_id`
disparaissaient silencieusement. La relecture les conserve désormais par
référence fournisseur. Elle reste un remplacement **physique** (`forceDelete`)
et non une mise à la corbeille — une relecture est une nouvelle transcription
du même document, pas cent lignes à conserver dans un bac.

**Règles d'état, et pourquoi elles diffèrent.** La différence ne tient pas à
la technique mais à qui a vu le document :

```text
Ligne de catalogue   transcription d'un document fournisseur
                     → corrigeable librement
Commande d'achat     engagement envoyé à un tiers
                     → corrigeable en brouillon ; ensuite annulation motivée
Facture fournisseur  pièce comptable
                     → corrigeable, jamais détruite
Réception, lot,      fait physique constaté
mouvement            → jamais modifiable ; correction par ajustement tracé
```

Une commande réceptionnée n'a donc ni « Modifier » ni « Supprimer » : aucun
mécanisme de correction post-réception n'existe, et aucun n'est inventé —
c'est l'ajustement de stock qui joue ce rôle.

**Le fournisseur classe son propre tarif (option B, retenue par le
propriétaire).** Le canevas Excel reçoit une cinquième colonne, « Famille ».
Elle n'est **pas** exigée : `HEADERS` reste à quatre colonnes, et les
catalogues déjà distribués — celui d'Arbiochem le premier — s'importent sans
changement. `supplier_catalog_items.family_label` conserve ce que le fichier
dit, **verbatim** et sans clé étrangère : la façon dont un fournisseur nomme
ses catégories n'est pas le référentiel de la clinique.

L'écran des lignes de catalogue affiche cette famille et permet d'y filtrer ;
elle se corrige comme le reste de la ligne.

Quand une ligne devient un médicament de la clinique, la famille déclarée est
rapprochée des familles existantes sans accents ni casse. Si elle manque, elle
est créée — **uniquement par un acteur qui a le droit d'écrire le référentiel**
(`medicine_categories.create`, ADR-024). Sans ce droit, ou sans famille
déclarée, le médicament n'en a simplement pas et le pharmacien la choisit à sa
fiche. Le libellé vient toujours du fichier du fournisseur, jamais d'une
supposition sur le produit.

**Le prix d'achat ne se saisit plus à la commande.** Il s'affiche et
s'applique ; seule la quantité est demandée, et le total suit. Un bouton
« Changer » reste disponible pour un prix négocié sur cette commande-là — il
est alors signalé, et le prix du fournisseur n'est pas écrasé : c'est le prix
de la commande qui est figé (ADR-097), pas le tarif. Lorsque le fournisseur
n'a aucun prix, la ligne le dit et invite à le renseigner dans son catalogue,
où il s'appliquera seul la fois suivante.

**Une colonne d'actions vide dit pourquoi.** Sur la liste des commandes, une
commande qui n'offre ni modification ni annulation affiche son motif — reçue,
annulée, ou déjà envoyée au fournisseur — au lieu de laisser croire à un droit
manquant.

**Une ligne par produit, même quand le catalogue le liste deux fois.**
Constaté le 2026-09-18 : le catalogue Arbiochem porte le même article sous
deux références (`GANT-010` et `GANT-011`, 19 cas sur 121 lignes). La règle
ci-dessus les ramène au même médicament, et la commande heurtait sa contrainte
d'unicité `(purchase_order_id, medicine_id)` avec une erreur SQL. La
validation ne pouvait pas le voir : deux UUID de catalogue distincts ne
révèlent qu'après résolution qu'ils désignent un même produit.

`ResolvesOrderedMedicine` refuse désormais la seconde ligne avec un message
qui nomme le produit, avant toute écriture. Les quantités ne sont pas
fusionnées à la place de l'acheteur : les deux références peuvent porter des
prix différents, et garder l'une plutôt que l'autre est une décision d'achat.

Le formulaire le sait avant l'envoi : chaque produit proposé porte un
`product_group`, calculé par le serveur, et choisir l'une des références
marque les autres « Dans la commande ». La comparaison des libellés vit dans
`App\Support\ProductLabel`, seule source partagée par la création du
médicament et par le formulaire — l'écran ne peut donc pas juger différents
deux libellés que le serveur juge identiques.

Au passage : rattacher une ligne à un médicament qui porte **déjà le même
prix** chez ce fournisseur ne réécrit plus ce prix. Le refaire passait par le
chemin « révision », qui exige `medicine_supplier_offers.update` : un compte
autorisé à rattacher se voyait refuser son propre rattachement (403).

**Vocabulaire.** « Archiver » disparaît de l'interface au profit de
**« Mettre à la corbeille »** (réversible), « Supprimer définitivement »
restant réservé à la Corbeille (ADR-061). Les deux gestes ne portaient pas
le même nom d'un écran à l'autre pour la même action.

**Ce que l'audit a confirmé sans rien changer** : le `SUPER_ADMIN` du portail
détient déjà toutes les permissions du catalogue, `Gate::before`
(`AppServiceProvider.php:43`) reste sans court-circuit par nom de rôle
(ADR-007), et le modèle « un médicament, plusieurs offres fournisseur »
d'ADR-097 n'avait aucune table à créer. Ce qui manquait n'était pas une
autorisation mais, tantôt une action inexistante, tantôt une capacité non
transmise à l'écran.

## Ce qui ne change pas

Aucune règle de délivrance, de réservation FEFO, de facturation Caisse ou de
consommables Soins n'est modifiée : les composants correspondants sont
réorganisés et rendus plus lisibles, leurs données envoyées au serveur restent
identiques. La Pharmacie n'encaisse toujours rien (ADR-013).

---

# ADR-099 — shadcn-vue est le design system par défaut

**Status:** ACCEPTED (2026-09-16 — exigence explicite du propriétaire)

**Remplace l'ADR-018** sur le choix du design system, et **achève
l'ADR-091**, qui n'avait fait de shadcn-vue qu'une cible de migration
progressive tout en laissant DashWind comme base.

## La règle

Toute interface **nouvelle ou modifiée** est écrite avec la couche
`resources/js/Components/Shadcn` et les tokens sémantiques RIVO
(`background`, `card`, `muted`, `primary`, `destructive`, `border`, `ring`,
`accent`, `popover`), les icônes `lucide-vue-next` et `cn()`.

```text
écran neuf              shadcn-vue, sans exception
écran retouché          la partie touchée passe à shadcn-vue
écran non touché        DashWind reste en place jusqu'à sa migration
```

DashWind n'est plus « la base visuelle » : c'est un **reliquat**, conservé
uniquement là où personne n'est encore repassé. Aucun nouveau composant
DashWind (`Components/UI/Icon.vue`, classes `nk-*`, `ni ni-*`, Headless UI)
ne doit être introduit.

## Ce que cela ne change pas

Le remplacement reste progressif, écran par écran, comme l'ADR-091 l'a posé :
aucune réécriture globale, aucune exécution de l'initialiseur shadcn qui
réécrirait Tailwind. Une primitive est ajoutée **à la demande**, adaptée à
l'identité de la clinique et relue comme tout autre code — c'est ainsi que
`Popover` est arrivé pour l'en-tête.

Chaque migration conserve avant tout ce que l'ADR-091 énumère déjà :
permissions et protections Laravel, routes et contrats Inertia, validation et
erreurs serveur, actions et règles métier, accessibilité clavier et focus,
mode sombre et affichage responsive.

## Conflit documentaire signalé

Le CDC officiel et `CLAUDE.md` désignaient encore DashWind comme fondation
(`# UI — Use DashWind as the main UI foundation`). Conformément à l'ADR-020,
la divergence n'est pas masquée : la décision `ACCEPTED` la plus récente
s'applique, et `CLAUDE.md`, `docs/AI_CONTEXT.md` et `docs/ROADMAP.md` sont
mis à jour dans le même mouvement. Le choix d'un design system est une
**décision technique** : son domicile est ce document, que
`docs/CDC_REFERENCE.md` désigne explicitement comme la source des décisions
validées après une version du CDC.

Aucune permission, route, validation ou règle métier n'est touchée par cette
décision.

**Amendement du 2026-09-22 — un sélecteur de date et d'heure.** L'ADR-107 constatait qu'aucune
primitive de date n'existait dans la couche shadcn de RIVO. À la demande du propriétaire, la
programmation du bloc remplace le champ natif `datetime-local` par `Shadcn/DateTimePicker` :
un déclencheur qui affiche la valeur en toutes lettres (« ven. 25 sept. 2026 · 09:30 »), un
calendrier (`Shadcn/Calendar`, primitives `reka-ui`, français, semaine du lundi) et deux
colonnes Heure / Minutes (pas de 5 minutes) dans le même panneau. La valeur échangée reste celle
d'un `datetime-local` (« AAAA-MM-JJTHH:mm », heure locale) : aucun serveur ni formulaire n'a à
changer. Aucune heure n'est inventée — un jour choisi sans heure ne produit pas de valeur.
`@internationalized/date`, déjà installé par `reka-ui`, devient une dépendance directe.

Le même jour, à la demande du propriétaire, **tous** les champs date de l'application passent par
deux composants : `Shadcn/DateTimePicker` (date et heure) et `Shadcn/DatePicker` (date seule,
« AAAA-MM-JJ », le panneau se ferme au choix du jour). 59 champs natifs dans une trentaine
d'écrans (Chirurgie, Maternité, Hospitalisation, Réception, RH, Pharmacie, Corbeille…) ont été
remplacés ; un test échoue si un champ date natif réapparaît. Les deux composants partagent :

```text
valeur        identique au champ natif : aucun serveur ni formulaire modifié
bornes        min / max grisent les jours hors bornes ; le serveur valide toujours
calendrier    listes Mois / Année (une date de naissance ne se cherche pas mois par mois)
libellé       court par défaut (« 25/09/2026 09:30 ») pour tenir dans les cases étroites,
              forme longue au survol ; `format="long"` pour un champ large
formulaires   name (champ caché), readonly, required, size, attributs ARIA transmis
```

**Panneau compact (même jour).** Le propriétaire trouvait le panneau trop gros et le champ
« Date et heure » de la sonde urinaire débordant. Le panneau passe de ~390 × 317 px à
242 × 296 px (date seule : 242 × 255) — pas plus large que le champ qui l'ouvre :

```text
calendrier    cases de 28 px ; Mois / Année en listes natives habillées (`Shadcn/NativeSelect`)
heure         une ligne « Heure [09] : [30] » sous le calendrier, à la place des deux colonnes
              latérales ; un jour choisi sans heure se signale (« Choisissez l'heure »)
Maintenant    remplace « Aujourd'hui » pour la date et l'heure : date et heure courantes à la
              minute, lues au clic — un geste explicite, jamais une heure inventée
```

`NativeSelect` est réservé aux listes courtes d'un panneau déjà ouvert : une liste dans un second
portail le fermerait au premier clic ; `Select` reste la liste de l'application. Elle porte `bg-none` :
le plugin `@tailwindcss/forms` dessine une flèche en image de fond sur tout `<select>`, et sans ce
retrait chaque liste affichait deux flèches (constaté par le propriétaire le jour même).

Le débordement venait de `Select` (`min-w-[176px]`) plus large que sa colonne dans une carte de
~325 px, et la date entière (« 25/09/2026 09:30 ») demande ~170 px. Les cartes Voie veineuse et
Sonde urinaire (Entrée au bloc) se placent désormais par requêtes de conteneur : côte à côte
seulement si chacune garde la date entière, sinon empilées avec leurs champs sur une ligne — même
hauteur, rien de tronqué, à toutes les largeurs de la colonne principale.

---

# ADR-100 — Référentiel des rôles administrable, écrans Comptes et Rôles séparés

**Status:** ACCEPTED (2026-09-16 — exigences explicites du propriétaire)

**Complète l'ADR-064** (le socle d'un rôle éditable depuis le portail) et
**amende l'ADR-033** sur le seul point du lieu où se règlent les exceptions
individuelles. Aucune règle de résolution des droits n'est modifiée :

```text
DENY individuel  >  ALLOW individuel  >  socle du rôle
```

## Le constat

Trois choses ont été signalées le même jour.

**Un écran n'avait aucune permission à lui.** `/medicine/demandes-examens`
réunit les demandes d'analyses **et** d'imagerie, mais sa route n'exigeait
que `laboratory_orders.view`. Un compte n'ayant que l'imagerie recevait un
403 devant un écran que le contrôleur savait pourtant lui servir — il y
filtre déjà chaque section par sa permission.

**Un seul écran portait deux métiers.** `/super-admin/workspaces/roles`
rendait `SuperAdmin/Users/Index.vue` : création de comptes, socle des rôles
et exceptions individuelles au même endroit. Deux gestes de portée très
différente s'y croisaient — créer un compte touche une personne, modifier un
socle touche tous ceux qui exercent le métier.

**Le référentiel des rôles restait figé dans le code.** L'ADR-064 avait sorti
le *socle* d'un rôle de `RolePermissionSeeder`, mais le rôle lui-même vivait
dans `RoleSeeder` : ajouter « Kinésithérapeute » exigeait un déploiement.

## Une permission par écran quand l'écran a deux sources

`paraclinical_requests.view` ouvre l'espace « Demandes d'examens ». Les deux
permissions existantes continuent de gouverner ce qu'on y voit, section par
section :

```text
paraclinical_requests.view   ouvrir l'écran
laboratory_orders.view       y voir les analyses
imaging_orders.view          y voir l'imagerie
```

Une route ne peut exiger qu'une capacité — même raison qui avait produit
`view-pharmacy-catalog` pour la page Médicaments & stock (ADR-098). Sans
aucune des deux permissions de contenu, l'écran s'ouvre et **dit** qu'il
manque un droit : un vide muet se lit « aucune demande », c'est-à-dire du
travail terminé.

L'attribution reproduit l'accès qui existait déjà, jamais plus large : la
migration accorde la nouvelle permission à tout rôle qui possédait l'une des
deux, et à tout compte dont l'accès venait d'un `ALLOW` nominatif. Un `DENY`
n'est pas recopié — retirer les analyses à quelqu'un n'a jamais voulu dire
lui fermer l'écran. Enregistrée par migration et non seulement dans
`PermissionSeeder`, puisqu'un site en production ne rejoue plus ce seeder
(ADR-064).

## Deux écrans, une seule logique

```text
/super-admin/workspaces/users    comptes : identité, rôle, profil, activation
/super-admin/workspaces/roles    socle des rôles, exceptions par compte,
                                 référentiel des rôles
```

Les exceptions individuelles rejoignent les rôles — choix explicite du
propriétaire : tout ce qui est *droit* se règle au même endroit, et la fiche
d'un compte ne porte plus que son identité et son rôle.

Conséquence côté serveur : le formulaire de compte **n'envoie plus**
`permission_overrides`. La clé omise laisse les exceptions intactes
(`UpdateUserAction` teste sa présence, jamais sa valeur) ; envoyée vide, elle
les aurait toutes effacées à chaque correction d'un nom ou d'un e-mail. Les
exceptions ont donc leur propre chemin d'écriture,
`UpdateUserPermissionOverridesAction` et
`PUT /api/v1/super-admin/roles/accounts/{userUuid}/permissions` : l'écran qui
les règle ne modifie ni l'identité ni le rôle, il ne doit pas avoir à les
réexpédier. Le contrat de l'ADR-033 est inchangé — seules les lignes `MANUAL`
sont remplacées, les lignes `PROFILE` ne sont pas touchées par ce chemin.

L'application des permissions recommandées d'un profil n'est plus « préparée »
dans le navigateur : elle reste celle du serveur, qui les applique dès que le
profil change sans jamais écraser une décision individuelle (ADR-033).

L'ancienne URL `/workspaces/roles` reste valide et mène aux rôles.

## Créer un rôle, jamais une permission

Un rôle créé fonctionne immédiatement : on lui coche un socle et on y affecte
des comptes. Une permission, elle, n'a d'effet que si le code la vérifie
quelque part — une permission inventée depuis un écran ne serait qu'un
interrupteur qui ne commande rien, affiché comme s'il protégeait quelque
chose. Le catalogue des permissions reste donc le vocabulaire du code, étendu
par migration quand une fonctionnalité en a besoin.

```text
roles.create    créer un rôle et son socle de départ
roles.update    corriger son libellé
roles.archive   le retirer des affectations possibles
roles.restore   le ramener tel qu'il était
```

Accordées par défaut au seul `SUPER_ADMIN` du portail central. Comme tout le
domaine catalogue, l'écriture passe par l'API du site
(`/api/v1/super-admin/roles*`), qui revérifie la permission et conserve
l'identité UUID/nom de l'acteur distant dans son audit (ADR-042, ADR-098) —
jamais un accès SQL depuis `admin.rivo.mg` (ADR-004, ADR-027).

Le socle d'un rôle créé est **celui réellement transmis**. L'écran peut
proposer « reprendre le socle de MEDICINE », mais ce qui arrive au serveur
est la liste cochée : aucun rôle n'est silencieusement lié à un autre.

## Le code est l'identité d'un rôle

`code` ne change jamais après la création. `RolePermissionSeeder::GRANTS`,
les affectations et l'audit désignent un rôle par ce code, et MEDICINE
désigne le même métier sur les trois sites — un UUID local n'aurait ici
aucun sens inter-site (ADR-005). Le libellé, lui, se corrige librement.

Le format est contraint (`^[A-Z][A-Z0-9_]{1,39}$`, normalisé en majuscules) :
un code avec accent ou espace serait irrattrapable une fois écrit dans un
seeder ou une trace d'audit.

## Archiver, jamais supprimer

`roles` reçoit `deleted_at`, `deleted_by` et `delete_reason` (ADR-009). Un
rôle a porté les droits de tout compte qui l'a exercé : sa suppression
physique effacerait le sens de cet historique.

Deux refus explicites :

```text
rôle encore porté par un compte  → archivage refusé, le nombre est nommé
code déjà pris par un archivé    → création refusée, il faut le restaurer
```

Le premier n'est pas une précaution d'interface. `User::role()` ne renvoie
plus un rôle archivé : l'archiver sous les pieds de ses titulaires les
priverait de tout leur socle d'un coup, sans que rien ne le dise. Les comptes
désactivés comptent aussi — ils peuvent être réactivés. Pour la même raison,
`Rule::exists('roles', 'id')` devient `->whereNull('deleted_at')` partout où
un compte reçoit un rôle.

Un rôle archivé conserve son socle : c'est ce qui rend la restauration
utilisable sans tout reconfigurer.

**Conséquence de déploiement, constatée le jour même.** Rendre `Role` Soft
Delete ajoute `roles.deleted_at is null` à *toutes* les lectures du modèle,
à commencer par `$user->role` dans `HandleInertiaRequests` — c'est-à-dire à
chaque page, pour chaque compte. Une base dont la migration n'est pas jouée
ne rend donc pas un écran cassé : elle rend l'application entière
inaccessible. La migration doit précéder le déploiement du code sur chaque
base, portail compris et, en local, sur les bases SQLite du banc de l'ADR-043
que `composer local:apis` migre au démarrage.

Le rôle `SUPER_ADMIN` n'est ni créé, ni renommé, ni archivé, ni réglé depuis
cet écran : il reçoit automatiquement toutes les permissions sur le portail
et aucune sur un site clinique (ADR-025, ADR-027).

Ces rôles ne rejoignent pas la Corbeille multi-sites de l'ADR-061, dont les
catégories restent celles qu'elle énumère ; ils se restaurent depuis l'écran
des rôles.

Le rôle obsolète `GUARD` (ADR-033) reste, lui, **physiquement retiré** par
`ProfessionalProfileSeeder` : il n'est pas archivé par quelqu'un, il est
obsolète par décision, et le laisser en corbeille occuperait son code en
proposant de le restaurer.

## Audit

`role.create`, `role.update`, `role.archive`, `role.restore`, avec l'ancienne
et la nouvelle valeur, et l'identité de l'acteur — locale ou distante, selon
le mécanisme déjà utilisé pour la gestion des comptes.

---

# ADR-101 — Catalogue des permissions administrable, et usage réel affiché

**Status:** ACCEPTED (2026-09-16 — exigence explicite du propriétaire, qui
revient sur l'arbitrage « rôles oui, permissions non » de l'ADR-100)

**Complète l'ADR-100** et **l'ADR-007** (permissions dynamiques, convention
`resource.action`). Aucune règle de résolution des droits n'est modifiée.

## Le conflit, et pourquoi il se résout ainsi

L'ADR-100 refusait la création de permissions : une permission n'a d'effet
que si le code la vérifie, et une permission inventée depuis un écran n'est
qu'un interrupteur qui ne commande rien — affiché comme s'il protégeait
quelque chose. Le propriétaire a maintenu sa demande.

Le fait technique, lui, ne change pas. La réponse retenue n'est donc pas de
le masquer, mais de le **dire** : le catalogue devient administrable, et
chaque ligne annonce si l'application la vérifie réellement quelque part.

```text
Vérifiée par l'application   une route, une règle serveur ou un écran l'interroge
Pas encore vérifiée          le mot existe, le contrôle n'existe pas encore
```

Créer une permission reste utile et légitime : préparer un droit avant sa
fonctionnalité, nommer un besoin métier, réserver un nom pour un module à
construire. Elle est attribuable immédiatement — elle n'ouvrira simplement
rien d'ici là, et l'écran le répète dans la fenêtre de création.

## L'état est calculé depuis le code, jamais déclaré

`PermissionUsageScanner` répond « ce nom est-il vérifié ? » à partir des
sources elles-mêmes. Une liste tenue à la main aurait divergé au premier
oubli, et un écran qui se trompe sur ce point est pire que pas d'écran du
tout : il affirme qu'un droit protège quelque chose.

Trois sources, parce qu'une permission peut protéger trois choses :

```text
la route      ->middleware('can:patients.view'), lue sur la table des routes
le serveur    $actor->cannot('roles.create'), une Policy, une Action
l'interface   can('stock.view'), le permission: d'une entrée de menu
```

Le balayage ne lit pas la même chose des deux côtés, et c'est délibéré. En
PHP, toute chaîne citée ayant la forme d'un nom compte : `app/` et `routes/`
ne contiennent pas de texte d'exemple. Côté interface, seuls les endroits qui
**interrogent** un droit comptent — `can('…')` et les `permission:` du menu
et des espaces de travail. Sans cette distinction, l'exemple affiché dans le
champ « Nom » de cet écran suffisait à faire passer une permission pour
vérifiée, et à en bloquer le retrait ; le cas a été constaté en test avant
d'être corrigé.

`database/` est hors périmètre, et c'est ce qui rend le balayage utile :
`PermissionSeeder` cite tous les noms du catalogue, et `RolePermissionSeeder`
tous ceux des socles — les inclure aurait répondu « vérifiée » pour tout.

Le résultat est mis en cache cinq minutes, et invalidé à la création comme au
retrait d'une permission.

## Ce que le catalogue permet, et ce qu'il refuse

```text
permissions.create   ajouter un nom au catalogue du site
permissions.update   reformuler son libellé
permissions.delete   retirer un nom que rien ne vérifie et que personne ne détient
```

Accordées par défaut au seul `SUPER_ADMIN` du portail central, et exécutées
par l'API du site comme le reste du domaine catalogue (ADR-004, ADR-027).

**Le nom ne change jamais.** Il est ce que le code écrit en clair
(`can:patients.view`). Le corriger ferait pointer une route, une Policy ou un
écran vers un nom disparu, et le contrôle passerait silencieusement à
« personne n'a ce droit ». Sa forme est contrainte à la convention de
l'ADR-007 et normalisée en minuscules. Seul le libellé — la phrase que lit la
personne qui coche la case — se reformule.

**Le retrait est physique, et étroitement borné.** Le Soft Delete n'a pas été
retenu ici, contrairement aux rôles de l'ADR-100 : une permission archivée
resterait citée par `role_permissions` et `user_permissions` sans apparaître
nulle part, et le socle d'un rôle deviendrait illisible. L'exception suit
donc l'esprit de l'ADR-062 pour un compte jamais utilisé :

```text
accordée à un rôle        -> refus, un socle la désigne
exception sur un compte   -> refus, une décision la désigne
vérifiée par le code      -> refus
```

Le dernier refus est le plus important, et le moins intuitif : supprimer une
permission que `can:` vérifie ne retire pas le contrôle — elle le rend
impossible à satisfaire, et l'écran concerné devient inaccessible à tout le
monde, sans message. L'interface nomme le motif du blocage **avant** le clic
plutôt qu'après l'envoi.

## Un libellé manquant ne fait tomber aucun écran

Constaté le jour même sur `consultations.reopen` : la ligne existait en base
avec son seul nom. Le catalogue triait dessus, `null.localeCompare` levait
une exception au milieu du rendu, et Vue ne s'en relevait pas — la page
entière devenait blanche, pas seulement la ligne fautive.

Trois protections, parce qu'une seule aurait laissé le prochain cas passer :

```text
la donnée    une migration rend leur libellé aux permissions qui n'en ont pas,
             depuis PermissionSeeder::PERMISSIONS, sans rien réécrire d'autre
le transport RbacPresenter ne met jamais un libellé vide sur le fil : à défaut,
             il envoie le nom — c'est ce que le code écrit, et c'est lisible
l'affichage  `permissionLabel()` et `comparePermissions()` sont partagés par
             les quatre écrans ; aucun tri de permission ne lit `.label` nu
la garde     `ErrorBoundary` isole la section affichée : une exception y est
             capturée et affichée, au lieu de figer l'écran entier
```

Cette quatrième protection répond à un défaut distinct de la donnée elle-même.
Vue ne remonte pas d'un rendu interrompu : l'enfant reste à moitié monté, et
chaque changement d'onglet échoue ensuite à le démonter (`vnode is null`,
`subTree of null`). La zone de contenu restait alors vide **sans un mot**, et
l'écran paraissait figé — c'est ce que le propriétaire a constaté. Un défaut
isolé doit se voir et rester isolé ; l'`ErrorBoundary` n'excuse pas le défaut,
il l'empêche de se propager.

## Navigation

L'écran « Rôles & permissions » porte désormais quatre gestes, présentés du
plus large au plus étroit, chacun avec son compte et une phrase qui dit qui
est touché :

```text
Socle des rôles         tous les comptes du rôle, y compris ceux créés plus tard
Exceptions par compte   une seule personne ; DENY individuel > ALLOW > socle
Rôles du site           créer, renommer, archiver un métier
Catalogue des droits    les mots que l'application sait vérifier
```

Une barre de pastilles ne disait ni ce qu'on allait toucher, ni combien. Sur
un écran où un clic peut modifier l'accès de tout un service, la portée
appartient à la navigation, pas à la documentation.

**La colonne de gauche appartient aux catégories.** Elle empilait d'abord la
liste complète des rôles — ou des comptes — puis les quarante catégories :
plus de 900 px à parcourir avant d'atteindre le travail réel, pour un choix
qu'on ne fait qu'une fois par réglage, et sans aucune recherche. Le rôle et
le compte se choisissent désormais dans une fenêtre cherchable, qui montre au
passage ce que chacun porte (droits, profils, comptes titulaires) ; la
colonne ne garde qu'une carte de ce qui est réglé et suit le défilement. Le
rail des catégories reçoit son propre filtre, sur le libellé **et** sur le
code — on connaît parfois l'un sans l'autre.

Les deux panneaux sont enfin séparés par une **barre que l'on glisse**
(`ResizableSplit`, déjà en place sur l'examen clinique) : la largeur utile du
rail dépend du travail en cours, et une grille figée imposait le même
arbitrage à tout le monde. La barre se manie aussi au clavier (flèches,
Home/End), se remet d'un double-clic, et la largeur choisie reste sur le
poste. Elle suit désormais les tokens de l'application plutôt qu'un bleu codé
en dur, ce qui supprime au passage les deux blocs de surcharge du mode
sombre.

**Amendement du 2026-09-20 — le rail des catégories se manie, il ne se subit pas.**
Constat du propriétaire : le rail des catégories était trop petit et difficile à
parcourir. Il tenait dans une fenêtre fixe de 22 rem (quatre lignes visibles,
`text-xs`), et la barre d'enregistrement en masquait le bas. Quatre-vingt-trois
catégories dans ces conditions se parcouraient à la molette, ligne à ligne.

```text
hauteur    le rail prend la hauteur que l'écran lui laisse (calc(100vh - 9rem))
           et défile dedans : la dernière ligne n'est plus sous la barre
lignes     text-sm, py-2.5, compteur en Badge — assez grandes pour être visées
domaines   repliables (chevron, nombre de catégories, repère « modifié » quand
           replié), « Tout replier / Tout déplier » ; une recherche déplie tout,
           puisqu'un résultat caché dans un domaine fermé n'en serait pas un
clavier    flèches, Début et Fin passent d'une catégorie à l'autre ;
           la catégorie ouverte déplie son domaine et reste dans la fenêtre
largeur    30 % par défaut (minimum 22 %), toujours glissable
```

Le composant reste partagé entre le socle des rôles et les exceptions par compte
(`PermissionCategoryNav`) : la navigation ne change pas d'allure selon ce qu'on
règle. Aucune permission, route ni règle de résolution des droits n'est touchée.

---

# ADR-102 — Tableau de bord central alimenté par les rapports de site

**Status:** ACCEPTED (2026-09-16 — exigence explicite du propriétaire)

**Complète l'ADR-025** (le portail et ses espaces) et **applique l'ADR-048**
au tableau de bord : aucune valeur n'est fabriquée, et ce qu'on ne peut pas
lire s'écrit comme tel.

## Le constat

`admin.rivo.mg` affichait « Patients aujourd'hui — », « Passages ouverts — »,
« Recettes du jour — ». Ce n'était pas une mise en forme en attente : aucun
endpoint ne comptait quoi que ce soit. Le portail listait des sites et des
modules, sans un chiffre.

## Un rapport par site, lu par l'API

`SiteReportService` s'exécute **dans** la base du site et renvoie cinq
sections : activité, finance, files cliniques, pharmacie, personnel.
`GET /api/v1/super-admin/reports/overview?days=N` l'expose ; le portail
appelle les trois sites et additionne. Aucune connexion SQL n'est ouverte
depuis le portail (ADR-004, ADR-027), et une panne d'un site n'empêche ni la
page de s'afficher, ni les autres d'être comptés (ADR-017).

La fenêtre est bornée à 7–90 jours, **avant l'appel**. Envoyée telle quelle,
une valeur hors bornes était refusée par chaque site et les trois rapports
revenaient « injoignable » pour une simple faute de saisie — constaté puis
corrigé.

## Une donnée absente n'est pas un zéro

C'est la règle qui gouverne l'écran, et la raison d'être de la forme du
payload :

```text
available: false + reason   permission manquante, ou module inexistant
ok: false + message         site injoignable
```

L'écran écrit alors « — » et nomme le motif. Afficher `0` ferait lire
« aucune recette aujourd'hui » là où il faut lire « je n'ai pas pu compter » :
c'est exactement ce que l'ADR-048 refuse déjà pour le rapport Chirurgie, et
un tableau de bord sert à décider. Les sites hors ligne sont annoncés **en
tête**, pas en note de bas de page : sinon un total partiel se lit comme un
total.

Chaque section est gardée par la permission qui possède réellement la
donnée — `episodes.view`, `billing.view`, `payments.view`, `debts.view`,
`stock.view`, `employees.view`, `patients.view` — en plus de
`super_admin.portal.view` qui ouvre la porte. Aucune permission nouvelle.

## Ce que chaque chiffre compte exactement

Rien n'est extrapolé ni projeté ; chaque valeur sort d'une colonne écrite par
un circuit existant :

```text
facturé      invoices.total_amount, statut ≠ CANCELLED
encaissé     payments.amount, statut = COMPLETED
reste dû     invoices.balance_amount — jamais facturé − encaissé, qu'une
             prise en charge à 100 % rendrait faux (ADR-047)
créances     patient_debts, distinctes d'une facture impayée (ADR-090)
files        episode_orientations hors CANCELLED : une orientation retirée
             a quitté la file et ne représente plus de travail (ADR-079)
péremptions  medicine_lots encore en quantité — un lot périmé n'est jamais
             compté comme disponible (ADR-036)
```

La démographie patient réutilise `ClinicOverviewService::patientDemographics()`
au lieu d'en écrire une seconde : deux façons de compter les mêmes patients
finiraient par afficher deux chiffres sur deux écrans.

## Graphiques sans dépendance

Courbe et histogramme réutilisent `ActivityTrendChart`, déjà en place sur la
Vue d'ensemble clinique — son titre devient un `prop`, le portail affichant
trente jours et plusieurs sites. `DonutChart` et `BarChart` sont ajoutés en
SVG, une centaine de lignes chacun.

Aucune bibliothèque de graphiques n'est introduite : elle amènerait sa propre
palette là où les tokens RIVO doivent décider (ADR-099), et son poids pour
trois formes simples. Le tracé porte `aria-hidden` et **la légende porte les
chiffres** : un graphique dont les valeurs n'existent que dans le dessin est
illisible au lecteur d'écran, et impossible à relire quand on cherche un
montant précis.

## Hors périmètre

Aucun rapport financier détaillé par acte, aucun export, aucune comparaison
entre périodes, aucune projection. Le tableau `Prévu / Réel / Écart / Dette NP`
de l'ADR-048 reste non alimenté : il exige des prestations facturables
Chirurgie qui n'existent pas encore, et l'inventer serait précisément la
faute que cette décision évite.

---

# ADR-103 — Un échec de facturation d'un consommable Soins ne peut plus être silencieux

**Status:** ACCEPTED (2026-09-16 — signalement explicite du propriétaire,
qui constate que l'écran Pharmacie laisse croire que ce matériel est gratuit)

**Complète l'ADR-072** sans en modifier une seule règle métier. Le
consommable était déjà facturé et déjà encaissé exclusivement par la
Réception / Caisse — c'est l'écran qui n'en disait rien.

## Ce qui était vrai, et invisible

`RequestCareConsumablesAction::billLineIfPossible()` crée un `BillableItem`
par ligne, au tarif résolu côté serveur, et le rattache à la facture du
passage tant qu'elle n'a reçu aucun encaissement — exactement la règle de
l'ADR-054, et le §34.1 règle 4 du CDC (« chaque prestation/produit délivré
alimente le compte du patient »).

Rien de tout cela n'apparaissait sur `/pharmacy/care-consumables` : ni
montant, ni numéro de facture, ni statut. Le pharmacien voyait son stock
partir sans aucune contrepartie affichée, et en concluait — légitimement —
que la clinique donnait ce matériel.

## Le vrai défaut : un échec avalé

La facturation est volontairement **non bloquante** (ADR-072) :

```php
} catch (ValidationException) {
    // tarif de vente absent, contexte financier du passage en attente,
    // politique Personnel UNCLASSIFIED…
    return;
}
```

C'est la bonne règle — une compresse déjà posée sur une plaie ne s'annule
pas parce qu'un tarif manque. Mais le `return` était la fin de l'histoire :
la ligne restait sans `billable_item_id`, aucun écran ne le signalait, et le
patient repartait sans que la clinique ait compté ce qu'elle avait consommé.
La file « Sorties & règlements » de l'ADR-090 ne pouvait rien y faire non
plus : elle liste les `BillableItem` encore `PENDING`, et il n'y en avait
aucun à lister.

C'était donc le **seul chemin** par lequel un consommable finissait
réellement gratuit — et il était invisible.

## Cinq états, parce que quatre ne suffisent pas

`CareConsumableDirectory::lineBilling()` distingue :

```text
INVOICED      portée sur une facture du passage
PENDING       chiffrée, en attente d'une facture à encaisser
CANCELLED     la prestation a été annulée après coup
NOT_BILLABLE  le catalogue dit que ce produit n'est pas facturé
NOT_BILLED    personne n'a réussi à la chiffrer — anomalie
```

`NOT_BILLABLE` est une décision de paramétrage ; `NOT_BILLED` est un oubli à
réparer. Les confondre reviendrait à masquer le second derrière le premier —
la même raison qui impose trois états à un appareil examiné (ADR-077) et
`null` à une question non posée (ADR-079, ADR-095).

`summary.unbilled_lines` compte ces lignes sur **l'ensemble** des demandes
projetées, servies comprises : une fois le produit sorti du stock, l'oubli
ne se répare plus tout seul. L'écran leur consacre un compteur et une
section qui nomme la ligne, la raison et qui doit la régulariser.

## Le prix reste invisible au poste de soins

`present()` prend `$withBilling = false` par défaut. `forOrientation()` — la
fiche Soins — ne le passe jamais, si bien que l'ADR-036 (« un soignant ne
voit et ne saisit jamais un montant ») tient à la **valeur par défaut d'un
paramètre**, non à la vigilance de chaque appelant. Un test le vérifie en
sérialisant la projection Soins et en y cherchant le montant.

Côté Pharmacie, le montant est lu et jamais saisi : aucun champ, aucun lien
vers la Caisse, aucune permission `payments.*` ou `cash.*` — l'ADR-013 est
intacte. C'est la même lecture read-only du statut financier que la file de
délivrance expose déjà (ADR-014, ADR-050), et elle n'exige aucune permission
nouvelle.

## Au passage

`InvoiceStatus::label()` et `::isSettled()` : quatre écrans gardaient chacun
leur copie du libellé français d'un statut de facture. Le cinquième le lit
désormais du serveur. Les quatre existants ne sont pas migrés ici — hors
périmètre — mais n'ont plus de raison d'être recopiés.

## Ce qui n'est pas décidé ici

L'ordre reste celui de l'ADR-072 : la sortie de stock n'attend pas le
règlement. Faire attendre le consommable Soins comme une délivrance
d'ordonnance (ADR-049) contredirait la justification explicite du
propriétaire du 2026-09-10 — « conserver en stock une compresse posée sur
une plaie rendrait l'inventaire sciemment faux » — et relève d'une décision
distincte, à prendre en connaissance de cet arbitrage.

Aucune permission, route, validation ou règle métier n'est modifiée par
cette décision.

---

# ADR-104 — Panier d'arrivée à deux rayons, et vente de médicaments prise à la Réception

**Status:** ACCEPTED (2026-09-17 — réunion client du 16/09/2026, arbitrages
explicites du propriétaire)

**Amende l'ADR-053** (« "Achat de médicaments uniquement" ne crée pas un
panier Pharmacie dans la Réception »), **l'ADR-028** (« la sélection à la
Réception est limitée à `type = SERVICE`… elle ne permet pas de vendre
directement un médicament ») et **l'ADR-049/050** (la vente comptoir
fonctionne sans Patient ni Episode). Les trois divergences ont été
signalées au propriétaire avant toute ligne de code, conformément à
l'ADR-020.

## Le parcours demandé

```text
1 · BESOIN       deux rayons, un seul panier
                 ├─ Désignations & consultations (tarif)
                 └─ Pharmacie (prix de vente, stock disponible)
2 · ESTIMATION   « je veux juste le prix » : on s'arrête là, rien n'est créé
3 · PATIENT      identité
4 · ÉPISODE      passage

   panier avec prestations  → parcours clinique habituel
   panier MÉDICAMENTS SEULS → aucune file clinique, bascule à la Caisse
```

## Un panier, deux rayons qui ne suivent pas le même circuit

`ReceptionCartKind` (`SERVICE` | `MEDICINE`) voyage sur chaque ligne du
panier, du navigateur jusqu'à la confirmation. La distinction n'est pas
cosmétique : une prestation ouvre une file clinique et rejoint la facture du
passage ; un médicament réserve du stock en FEFO et part sur un **ticket
Pharmacie distinct**, dont la délivrance attend le règlement (ADR-049).

Une ligne sans `kind` est une prestation. Les brouillons enregistrés avant
cette décision n'en portent pas et ne contenaient que des prestations : le
déduire lit ce qui existait, sans rien inventer (même principe qu'ADR-074 et
ADR-083). Aucune migration de `reception_journey_drafts` : `catalog_lines`
est déjà du JSON.

## Deux factures, jamais une seule

Arbitrage explicite du propriétaire. Les prestations vont sur la facture du
passage, les médicaments sur leur ticket. Une facture mixte partiellement
payée rendrait indécidable ce que la Pharmacie peut délivrer : l'ADR-050
sépare déjà les factures Pharmacie dans leur onglet Caisse pour exactement
cette raison. L'estimation affiche donc **deux sous-totaux** et dit
lesquels se règlent sur quel document.

## Un passage « médicaments seuls » ne reste pas ouvert

Il n'ouvre aucune orientation : personne ne l'attend dans une file. Sans
transition explicite il resterait `PENDING_ORIENTATION` indéfiniment — le
trou exact que l'ADR-090 a bouché pour les passages cliniques.
`CompleteEpisodeServicesAction::settleWithoutClinicalRouting()` le place
donc directement en `PENDING_SETTLEMENT` : il rejoint « Sorties &
règlements », où la Réception le clôt une fois le ticket encaissé.

L'écran bascule ensuite vers `/cash` avec la référence du ticket. Le poste
de caisse se choisit d'abord quand plusieurs sont configurés : la référence
traverse ce choix (`Cash/Index`), sinon l'agent devrait la ressaisir juste
après l'avoir vue.

La Pharmacie n'encaisse toujours rien (ADR-012, ADR-013) : la Réception crée
la vente et son ticket, la **Caisse** encaisse, la **Pharmacie** délivre.

## La vente comptoir anonyme est retirée

Arbitrage explicite du propriétaire : toute vente de médicament passe
désormais par la Réception, sur un dossier patient et un passage. La
conséquence a été posée avant le choix et est assumée — un passant qui veut
une boîte de paracétamol doit désormais avoir un dossier.

```text
Retiré     CounterSaleController, Pharmacy/CounterSales/Create.vue,
           POST /pharmacy/counter-sales,
           PharmacyWorkspaceService::counterSale(),
           l'entrée de menu « Vente comptoir » et ses raccourcis
Conservé   CreateExternalDispenseAction — l'unique chemin qui crée encore
           une vente, appelé depuis la Réception
Conservé   PharmacyDispenseType::External, customer_name/phone,
           external_prescriber : les ventes déjà enregistrées restent
           lisibles telles qu'elles ont été faites (ADR-010)
```

`GET /pharmacy/counter-sales/create` reste une URL valide et redirige vers
`/reception/patients` : un signet mène là où le travail se fait désormais,
jamais à une page disparue (même principe qu'ADR-081 et ADR-084).

`pharmacy_dispenses.patient_id` et `episode_id` étaient **déjà nullable** :
rattacher une vente à un passage n'a demandé aucune migration de schéma.

## Permissions — le droit suit le geste

```text
RECEPTION  + medicines.view, stock.availability.view,
             pharmacy.counter_sales.create
PHARMACY   − pharmacy.counter_sales.create
```

La paire de lecture est exactement celle que l'ADR-036 accorde déjà à
`MEDICINE` pour prescrire : voir le référentiel et la disponibilité, sans
aucun droit de mutation `stock.*`. La Réception ne sort jamais un lot.

Voir le rayon et le vendre sont traités comme un seul droit à l'écran
(`can_sell_medicines`) : proposer une boîte qu'on ne pourra pas transmettre
à la Caisse ne servirait qu'à faire échouer la confirmation **après** la
saisie du dossier patient.

Conformément à l'ADR-064, ces attributions figurent dans
`RolePermissionSeeder::GRANTS` pour la création d'un nouveau site, mais un
site déjà en production les reçoit par la migration
`2026_09_17_090000_move_counter_sale_to_reception`, qui ne touche que les
socles de rôle — jamais les exceptions individuelles (ADR-033).

## Une erreur Pharmacie n'efface jamais le passage

`preparePharmacySale()` capture l'échec (boîte manquante, produit retiré du
référentiel) et le remonte comme avertissement : l'identité et l'épisode
déjà créés ne sont pas annulés, la régularisation appartient à la
Pharmacie. Même principe que l'ADR-054 pour un acte clinique.

## Amendement du 2026-09-17 — le choix est offert à l'étape « Prise en charge »

Constat du propriétaire à l'usage : un patient venu seulement acheter des
médicaments ne veut voir ni médecin ni infirmier, et l'écran le faisait
malgré tout traverser le choix de couverture, la confirmation et le routage.

Pire, c'était une **impasse** : le panier n'envoie que les prestations à
l'aperçu financier (voir plus haut), donc un panier de médicaments seuls
arrivait avec zéro ligne et `ReceptionFinancialPreviewService` refusait —
« Sélectionnez entre une et cinquante prestations ».

L'étape 5 propose désormais, pour ce seul cas, les deux suites qui ont un
sens :

```text
Ajouter une prestation        retour au Besoin — le passage redevient normal
Terminer — envoyer à la Caisse confirme, crée le ticket, ouvre /cash
```

Les cartes Standard/Mutuelle/Personnel/Partenaire et le bouton « Calculer la
prise en charge » disparaissent alors : promettre une couverture qui ne
s'appliquera pas au ticket est pire que ne rien proposer.

`financial_mode` est résolu à `SELF` par
`CompleteEpisodeServicesAction::settleWithoutClinicalRouting()` quand il est
encore `null`. Ce n'est pas une règle nouvelle : le ticket Pharmacie est au
tarif Sans mutuelle par construction, donc le patient en supporte le
montant. Le laisser à `null` afficherait le passage comme « contexte
financier à régulariser » (ADR-051) alors qu'il n'y a rien à régulariser. Un
mode déjà choisi explicitement par la Réception n'est **jamais** réécrit.

Le raccourci poste la confirmation réelle : c'est le serveur qui n'ouvre
aucune file clinique parce qu'il ne trouve aucune prestation, jamais
l'écran qui en décide.

**L'urgence n'est pas proposée sur un achat de médicaments.** Classer un
passage en urgence ouvre les files Soins **et** Médecine (ADR-056) : le
proposer ici enverrait deux équipes chercher quelqu'un venu prendre une
boîte. La carte disparaît donc du même coup que le choix de couverture, et
« Ajouter une prestation » la fait réapparaître — un passage qui redevient
clinique redevient requalifiable.

Ce n'était pas qu'un bouton de trop. `settleWithoutClinicalRouting()`
vérifie désormais qu'aucune orientation `PENDING`/`IN_PROGRESS` n'existe
avant de faire quoi que ce soit : un passage requalifié en urgence par un
autre chemin (Médecine, ADR-056) aurait sinon été déclaré `PENDING_SETTLEMENT`
avec deux files ouvertes, et son `financial_mode` figé à `SELF` alors que
des actes cliniques allaient encore s'y ajouter. Même garde que l'ADR-054
avant de faire avancer un statut administratif. Le ticket Pharmacie, lui,
part dans tous les cas : le patient doit payer ses médicaments.

## Ce qui ne change pas

La réservation FEFO, la règle « le stock ne sort qu'après règlement »
(ADR-049), le ticket et son contrôle à la Caisse (ADR-050), le routage
clinique des prestations (ADR-030, ADR-053), l'estimation read-only qui ne
crée ni Patient, ni Episode, ni facture (ADR-051). Aucune règle de
délivrance n'est assouplie.

---

# ADR-105 — Les examens paracliniques sont facturés et ne retiennent plus la clôture

**Status:** ACCEPTED (2026-09-17 — exigences explicites de la Clinique Saint
Georges, rapportées par le propriétaire)

**Amende l'ADR-076** sur un point précis (une demande transmise résout son
étape) et **complète l'ADR-079** (ce que devient la facturation quand une
demande est retirée). Aucune règle de délivrance, d'encaissement ou de
parcours clinique n'est modifiée.

## Le défaut : l'examen n'était pas facturé du tout

`CreateLabRequestAction` et `CreateImagingRequestAction` créaient la demande,
l'orientation vers le Laboratoire, les instantanés du catalogue — et rien
d'autre. Aucun `BillableItem`, aucune ligne de facture.

Concrètement : un médecin demandait une NFS et une échographie, les deux
partaient au service concerné, le patient sortait, et la clinique ne comptait
jamais ce qu'elle avait fait. Ce n'est pas un défaut d'affichage comme celui
qu'a corrigé l'ADR-103 — l'argent n'existait nulle part.

L'incohérence était d'autant plus nette que le **même examen sélectionné à la
Réception** est facturé depuis l'ADR-068 : `PlanEpisodeRoutingAction` crée son
`BillableItem`. Une analyse valait donc 12 000 Ar à l'accueil et zéro quand
c'est le médecin qui la demandait.

## Facturé à la demande, comme à la Réception

L'examen rejoint le compte du patient **au moment où le médecin l'ajoute**,
pas quand le résultat revient — c'est ce que la clinique demande, et c'est
déjà la règle pour le même acte sélectionné à l'arrivée (ADR-068).

Chaque ligne de demande porte l'élément qu'elle a produit
(`lab_request_items.billable_item_id`, `imaging_request_items.billable_item_id`,
nullable et jamais rétro-rempli) : une annulation retrouve exactement ce
qu'elle doit retirer, sans déduire par le nom ou la date.

La clé d'idempotence dérive de l'UUID de la ligne : une relance ne facture
jamais deux fois le même examen.

## Une erreur financière ne retient jamais la demande

Tarif absent, contexte financier du passage non résolu, politique Personnel
non classifiée : la demande part quand même au Laboratoire et l'élément
facturable n'est simplement pas créé. La régularisation appartient à la
Réception — même règle que l'ADR-054 pour un acte Soins et l'ADR-072 pour un
consommable.

## Une règle écrite une fois, pas trois

`App\Services\Billing\ClinicalActBiller` porte désormais les deux règles que
chaque appelant recopiait : rattacher à la facture du passage tant qu'elle
n'a reçu aucun encaissement, et avaler l'échec financier sans annuler l'acte.
Les écrire une troisième fois les aurait fait diverger — le défaut que
l'ADR-098 relève partout où une même règle vit à plusieurs endroits.

## Retirer une demande retire ce qu'elle a facturé

L'ADR-079 permet d'annuler une demande sans résultat. Elle ne disait rien de
la facturation, qui n'existait pas encore.
`DecideComplementaryExamsAction::releaseBilling()` annule les `BillableItem`
encore `PENDING` de la demande retirée. Un élément déjà porté sur une facture
n'est **pas** détricoté ici : seule la Réception/Caisse touche un montant
facturé (ADR-012, même frontière qu'ADR-072).

## La demande transmise résout son étape

L'ADR-076 interdit de résoudre une étape « en effet de bord d'un
enregistrement ». Cette règle vise une **saisie en cours** : ouvrir un écran
ne doit pas passer pour un travail fait.

Un ordre parti au Laboratoire ou à l'Imagerie n'est pas une saisie en cours.
C'est un acte terminé, dont le médecin ne peut plus rien faire sur cette
étape — et l'écran lui demandait pourtant de la « valider » avant de pouvoir
clôturer. La clinique l'a signalé dans ces termes : ces examens sont déjà
validés et envoyés à la page concernée, ils n'ont pas à retenir le passage.

`ResolveConsultationStepAction::completeParaclinicalFromRequest()` marque donc
l'étape `COMPLETED` à la création de la demande. Silencieuse par
construction : une consultation qui n'est plus éditable, ou une étape déjà
résolue, laisse l'état tel quel — ce chemin complète un dossier, il ne doit
jamais faire échouer la demande clinique qui vient d'aboutir.

`blockersForClosure()` écarte en outre l'étape Paraclinique dès qu'une
demande active existe. Ce n'est pas une double sécurité décorative : les
consultations antérieures à cette décision portent des demandes **sans**
étape résolue, et c'est le fait clinique qui doit décider, pas l'état d'un
écran — le principe qu'a déjà appliqué l'ADR-081 à la clôture.

## Ce qui ne change pas

« Aucun examen nécessaire » continue d'exiger la confirmation explicite du
navigateur et refuse de retirer une demande portant déjà un résultat
(ADR-079). Le Laboratoire et l'Imagerie gardent la saisie de leurs résultats.
La Médecine n'encaisse toujours rien : l'élément facturable rejoint la
facture du passage, et seule la Réception/Caisse encaisse (ADR-012, ADR-015).

Aucune permission nouvelle : `laboratory_orders.create` et
`imaging_orders.create` gouvernent la demande comme avant.

---

# ADR-106 — Famille d'imagerie réglée au catalogue, et signature d'une demande d'examen

**Status:** ACCEPTED (2026-09-17 — exigences explicites du propriétaire)

**Complète l'ADR-105** (les examens paracliniques sont facturés) et
**applique l'ADR-052** (rien ne se déduit d'un code ou d'un libellé) à la
séparation ECG / Échographie.

## ECG et Échographie sont deux familles, pas un préfixe

L'écran présentait un seul onglet « ECG / Échographie ». Les séparer exige de
savoir lequel est lequel — et le code ne le dit pas :

```text
ECG-*     2 examens
ECHO-*   15 examens
???       HOLTER-ECG        un enregistrement cardiaque
          DOPPLER-MI-ART    une échographie
          DOPPLER-MI-VEIN   une échographie
```

Trois examens sur vingt auraient été mal classés ou perdus. Un futur examen
nommé autrement disparaîtrait d'un onglet sans que personne ne le voie.
`catalog_items.imaging_modality` porte donc la famille — `CARDIOLOGY` ou
`ULTRASOUND` — comme `reception_routing_mode` porte le parcours Réception.

**Nullable, et c'est le point.** Un examen que personne n'a classé apparaît
dans un onglet « Non classés », visible seulement s'il en existe un. Le
ranger d'office dans l'une des deux familles le ferait disparaître d'un
onglet sans décision — la faute que l'ADR-077 refuse pour un appareil non
examiné et l'ADR-079 pour une question non posée.

Le pré-classement est **explicite, code par code**, dans la migration comme
dans le seeder : aucun motif, aucun préfixe. La migration classe ce qui
existe sur un site déjà installé ; le seeder classe ce qu'il crée sur un
site neuf — sans lui, vingt examens repartiraient non classés (ADR-064).

La recherche est bornée à la famille ouverte : chercher « écho » dans
l'onglet ECG ne remonte rien, sinon la séparation n'en serait plus une. Les
demandes déjà transmises comptent dans l'onglet de leur famille.

## Transmettre une demande est un acte signé

Un clic envoyait l'ordre au service **et** portait les examens au compte du
patient (ADR-105), sans que rien ne l'annonce. Les deux effets sont
immédiats et engagent le médecin.

La confirmation n'est donc pas une formalité « êtes-vous sûr ? » — elle
énonce ce qui est engagé :

```text
les examens, nommés un par un    confirmer « 2 examens » sans les voir
                                  n'est pas une signature consciente
la demande part au service        qui prendra le patient en charge
le patient est facturé            et encaissé par Réception / Caisse
sous la responsabilité de …       le nom du médecin connecté
```

La fenêtre n'est **pas fermable au clic extérieur** : on y signe, on n'y
passe pas. Tous les chemins y mènent — le bouton comme la touche Entrée dans
un champ — et les deux boutons se désactivent pendant l'envoi.

Elle rappelle enfin qu'un retrait reste possible tant qu'aucun résultat n'a
été saisi (ADR-079) : une signature engageante n'est pas une signature
irréversible, et le dire évite l'hésitation à l'écran.

## Amendement du même jour — la fenêtre est allégée

Le propriétaire a demandé de retirer les deux phrases de conséquence. Elles
restent vraies, elles ne sont plus répétées à chaque envoi : la fenêtre garde
ce qui **ne peut pas** être déduit d'ailleurs — les examens nommés un par un,
et le nom du médecin qui signe. C'est une décision d'ergonomie, pas un
assouplissement : ni la garde serveur, ni le caractère non fermable au clic
extérieur, ni le passage obligé par la confirmation ne changent.

## L'ordonnance se signe aussi

Même raisonnement, même format (2026-09-17) : valider une ordonnance
**réserve les lots en FEFO** (ADR-036) et verrouille la quantité pour ce
patient. Le bouton « Valider et réserver » passe donc par la même
confirmation que les demandes d'examen.

Elle relit chaque ligne avec **sa posologie composée** — « 500 mg · orale ·
matin et soir · 7 jours », exactement ce que la Pharmacie et le patient
liront. C'est le seul intérêt d'une confirmation ici : relire les doses, pas
compter les lignes. La composition est celle de l'éditeur de ligne, jamais
une seconde formule qui finirait par annoncer autre chose que ce qui est
enregistré.

Une ligne manuelle y porte la mention « Hors référentiel » : elle ne réserve
aucun lot (ADR-037), et laisser croire le stock engagé serait faux.

Le nom du médicament est relu depuis la liste par le helper de l'éditeur :
une ligne catalogue ne porte que son UUID, et recopier le libellé dans la
ligne le ferait diverger du référentiel.

## La demande de soins se signe aussi

Même format (2026-09-18, exigence du propriétaire) : « Transmettre aux
Soins » crée l'orientation vers Soins et envoie les actes à l'équipe
(ADR-055). Le bouton comme la touche Entrée ouvrent une confirmation qui
nomme chaque acte avec sa quantité, relit le parcours choisi (retour en
Médecine ou fin aux Soins) et les instructions, porte le nom du médecin et
n'est pas fermable au clic extérieur. Seule cette fenêtre envoie.

## Rappel d'orientation retiré de la Prescription

L'étape Prescription portait encore un bandeau « Orientation actuelle » avec
un bouton « Définir la suite de la prise en charge ». L'ADR-089 a rassemblé
la conduite à tenir sur la seule étape « Décision & clôture » et l'a retirée
de l'Interrogatoire, de l'Examen et de la Paraclinique — ce bandeau-ci avait
survécu.

Il n'était pas seulement redondant : **son bouton menait à l'Examen
clinique**, où `ClinicalOrientationCard` n'existe plus depuis l'ADR-089. Un
médecin qui cliquait « Définir la suite de la prise en charge » arrivait sur
un écran où il n'y avait rien à définir. Le bandeau est retiré ; la conduite
à tenir se décide à « Décision & clôture », et nulle part ailleurs.

## Clôture : la navigation n'est plus dédoublée, et l'attente n'est plus un verrou

Deux remarques du propriétaire le même jour, sur le même écran.

**Le pied « Précédent / Suivant » est retiré.** Les trois sous-étapes de
« Décision & clôture » portaient une barre d'onglets `1 · 2 · 3` en tête
**et** une paire de boutons en pied. Les onglets sont toujours visibles et
atteignent n'importe quelle section d'un clic : le pied refaisait le même
travail, en suggérant de surcroît un ordre imposé qui n'existe pas. Rien
d'autre ne bouge — les onglets restent l'unique pilote de `closureSubStep`,
aucun n'est jamais condamné par l'état du dossier, et c'est toujours
l'étape 3 qui refuse en nommant ce qui manque.

**Un résultat attendu n'a jamais bloqué la clôture, mais il en avait
l'air.** Le propriétaire a signalé rester « coincé à cause de *En attente
de 2 résultats* ». Vérification faite sur le passage concerné, les deux
blocages réels étaient le diagnostic absent et la conduite à tenir non
renseignée (CDC §33.1, ADR-081, ADR-084) ; `awaitingResults` n'entre dans
`closure_blockers` à aucun moment — il ouvre une simple confirmation.

Le défaut était donc de **présentation** : un bandeau ambre, juste au-dessus
de l'étape de clôture, se lit comme un verrou. Il devient neutre et dit ce
qu'il est — « cela n'empêche pas de clôturer. Vous pouvez conclure
maintenant, ou attendre ». C'est la même règle que l'ADR-102 applique aux
chiffres manquants : l'ambre annonce un obstacle, et un écran qui en
fabrique un là où il n'y en a pas coûte au médecin un passage abandonné.

Un test de garde vérifie désormais les deux : que la clôture ne porte plus
de second jeu de boutons, et que le bandeau d'attente n'emprunte ni la
couleur ni le vocabulaire d'un blocage.

## Clôture : corriger un diagnostic, et savoir où agir

**La correction et le retrait d'un diagnostic étaient inatteignables.**
L'ADR-081 les place « dans la carte Diagnostic », et l'ADR-089 a déplacé
cette carte à « Décision & clôture » — la carte a suivi, ses deux gestes
non. `PUT /diagnoses` et `POST /diagnoses/cancel`, leurs FormRequests, leurs
Actions et les drapeaux `can_edit`/`can_cancel` du presenter existaient tous
et n'étaient appelés par aucun écran : une faute de frappe restait dans le
dossier sans rien pour la rectifier.

`ClinicalDiagnosisList.vue` porte donc « Corriger » et « Retirer » par
ligne. Rien n'est réécrit ni effacé (ADR-010, ADR-035) : corriger enregistre
une nouvelle ligne et annule l'ancienne, retirer conserve la ligne d'origine
avec son auteur et sa date, et l'historique complet — annulés compris — reste
dans « Contexte clinique » (ADR-081). Les deux gestes restent réservés à
l'auteur de la saisie : les drapeaux viennent du serveur, qui revoit toujours
la règle, et un autre compte ne voit aucun bouton plutôt qu'un bouton qui
refuserait. Le retrait passe par une fenêtre non fermable au clic extérieur,
jamais par une fenêtre native du navigateur.

**Chaque obstacle mène désormais à sa sous-étape.** `blockersForClosure()`
portait `step => null` pour le diagnostic et la conduite à tenir, au motif
qu'ils se règlent « déjà sur place ». C'était vrai de l'étape, pas de
l'écran : le diagnostic est à la sous-étape 1, la conduite à tenir à la 2,
et cette liste se lit depuis la 3. `closure_section` (1, 2 ou `null` pour un
obstacle qui appartient à une autre étape du parcours) rend le « Y aller »
opérant dans les deux cas — c'est la promesse de l'ADR-084, « ce qui manque
encore **et le chemin pour y retourner** ».

## Ce qui ne change pas

Aucune permission nouvelle : `laboratory_orders.create` et
`imaging_orders.create` gouvernent la demande comme avant. L'endpoint, la
validation et la facturation (ADR-105) sont inchangés — seul le chemin qui y
mène passe désormais par une confirmation.

---

# ADR-107 — Registre des décès et acte de constatation

**Status:** ACCEPTED (2026-09-17 — exigence explicite du propriétaire)

**Complète l'ADR-035**, qui définit déjà la sortie médicale de type `DECEASED`
et conserve « l'heure, le lieu et les causes utiles au futur certificat de
constatation ». Ce futur est construit ici. Aucune règle de l'ADR-035,
l'ADR-084 ou l'ADR-090 n'est modifiée.

## Le trou constaté

Un décès prononcé en consultation ne réapparaissait nulle part. La file
Médecine ne montre que les prises en charge en cours, et le passage rejoignait
« Sorties & règlements » comme n'importe quel autre. Personne n'avait donc
d'écran pour retrouver les patients concernés, ni pour établir le document
que la famille emporte.

## Deux faits, deux enregistrements

```text
MedicalDischarge (DECEASED)  la décision clinique : le décès est prononcé
DeathRecord                   l'acte : un médecin constate, date et signe
```

Les confondre reviendrait à dire qu'un décès prononcé est un acte établi. Ce
sont deux moments, deux signatures et deux dates. `death_records` porte donc
son propre `constated_at`/`constated_by`, et l'heure de constatation
appartient au serveur — elle atteste quand l'acte a été signé, pas quand on a
rempli le formulaire (même règle que la date de demande, ADR-069).

Les trois valeurs cliniques (moment, lieu, causes) sont **préremplies depuis
la sortie médicale puis corrigeables ici** : le médecin qui constate signe ce
qu'il écrit, il ne contresigne pas la saisie d'un autre écran.

## Ce que l'acte ne prétend pas être

Ce n'est pas l'acte d'état civil. Le numéro d'acte, le déclarant et l'officier
d'état civil relèvent de la commune, et le CDC officiel n'en dit **rien** —
aucune mention de décès dans tout le document. Ils ne sont donc pas inventés,
et un test de garde vérifie qu'ils n'apparaissent ni à la saisie, ni à
l'impression. À définir avec l'équipe le jour où ce volet sera réellement
spécifié.

## Un seul acte par passage

Contrainte d'unicité en base, et refus explicite côté serveur. Un second acte
serait un doublon d'état civil, jamais une correction : celle-ci relèvera de
son propre mécanisme tracé (ADR-010), non d'une seconde signature.

L'acte ne peut pas non plus **devancer** le décès : `RecordDeathCertificateAction`
exige une sortie médicale de type `DECEASED` sur ce passage. Signer la
constatation d'un décès que personne n'a prononcé attesterait un fait clinique
inexistant.

## Le médecin est conduit au registre

Prononcer un décès redirige vers `/deces` au lieu de revenir à l'étape de
clôture : le travail restant n'est plus dans le dossier, il est sur l'acte.
Cela ne change **rien** à ce que la sortie fait — la consultation reste
ouverte, la clôture reste le seul acte qui termine l'orientation Médecine
(ADR-084), et une réouverture reste possible (ADR-096). Seule la destination
de la redirection change, et seulement si le compte peut voir le registre.

## Ce que le registre ne fait pas

Il ne prononce aucun décès et n'encaisse rien : aucune permission `payments.*`
ou `cash.*`, aucun lien vers la Caisse (ADR-012, ADR-013). Le passage suit son
cours administratif normal vers « Sorties & règlements » (ADR-090) — un décès
ne solde pas un compte.

## Permissions

```text
death_records.view     consulter le registre et imprimer un acte
death_records.create   établir l'acte de constatation
```

Voir et signer sont séparés, pour la même raison que l'ADR-090 sépare voir la
file des sorties et prononcer la sortie : suivre les passages concernés n'est
pas établir un document médico-légal. Accordées par défaut à `MEDICINE`.

Conformément à l'ADR-064, elles figurent dans `RolePermissionSeeder::GRANTS`
pour la création d'un nouveau site, mais un site déjà en production les reçoit
par la migration `2026_09_20_090000_create_death_records_table`, qui ne touche
que le socle du rôle — jamais les exceptions individuelles (ADR-033).

## Au passage — ce que l'écran de conduite à tenir devait encore

**Transmettre une demande est un acte signé** (ADR-106). Le bouton
« Transmettre la demande » envoyait l'ordre au service destinataire **et**
faisait passer l'orientation à `SUBMITTED`, ce qui débloque la clôture
(ADR-084), sans que rien ne l'annonce. Il passe désormais par la même
confirmation que la demande d'examen et l'ordonnance : la fenêtre nomme la
destination, relit ce qui part — confirmer « une demande » sans la voir ne
serait pas une signature consciente — porte le nom du médecin et n'est pas
fermable au clic extérieur. Les quatre destinations (Chirurgie,
Hospitalisation, Référence, Maternité/Pédiatrie) y passent, et changer
d'orientation reste possible tant que la destination n'a pas pris la demande
en charge.

**Les derniers contrôles habillés à la main passent à shadcn** (ADR-099).
`ClinicalOrientationCard` et `ClinicalDischargeForm` portaient encore deux
`<select>` et dix-sept `<textarea>` natifs, habillés par des chaînes de
classes recopiées — qui avaient déjà divergé entre les deux fichiers. Elles
cèdent la place aux primitives `Select` et `Textarea`, sans changer un seul
`v-model`, une seule route ni une seule règle de validation. « Autre
établissement » reste une option à part entière de la liste : c'est elle qui
ouvre la saisie libre, et la retirer empêcherait de référer hors référentiel.

Les champs de date restent des `datetime-local` natifs dans `Input` /
`IconInput` : aucune primitive de date n'existe dans la couche shadcn de RIVO,
et en fabriquer une pour trois champs amènerait son propre calendrier à
maintenir.

## Le préremplissage recopiait du HTML dans un champ de texte

Signalé par le propriétaire sur « Résumé clinique et examens », qui
affichait :

```text
Échocardiographie : UTERUS<p>• Orientation: Antéversé…</p><p>• Volume</p>…
```

`orientationPrefill()` convertissait déjà `clinical_exam` et `reason` en texte,
mais **pas** `result_value` — précisément le champ qui porte un compte rendu
d'imagerie, saisi en éditeur riche et stocké en HTML (ADR-070). Ce n'était pas
qu'un défaut d'affichage : c'est ce texte-là, balises comprises, qui partait au
service d'accueil et s'imprimait sur le billet d'hospitalisation.

`toPlainText()` était par ailleurs trop étroit — seuls `</p>` et `<br>`
coupaient la ligne, si bien qu'une liste à puces ou des titres se retrouvaient
collés en une phrase. Chaque **ouverture et fermeture** de bloc en produit une
désormais : un compte rendu écrit « UTERUS<p>• Orientation… » sans fermer
avant, et la seule fermeture laissait les deux collés. Les lignes vides
consécutives sont réduites à une : un champ de texte n'a pas d'interlignage à
restituer, et le compte rendu doit se lire ligne à ligne.

Une absence reste une absence : un compte rendu vide renvoie `null`, jamais une
chaîne vide, et l'examen s'affiche « — en attente ». Un résultat de plusieurs
lignes est présenté **sous** son examen plutôt que collé derrière, sinon la
première ligne absorbe le nom et les suivantes flottent sans rattachement.

Les demandes **déjà transmises ne sont pas réécrites** (ADR-010) : leur
`clinical_summary` est ce que le médecin a validé et ce que le service a reçu.

## Les libellés de ces formulaires passent à `FormField`

Vingt champs de `ClinicalOrientationCard` et quatre de `ClinicalDischargeForm`
écrivaient leur libellé à la main, en `text-[11px] font-bold` — ni la taille
ni la graisse du reste de l'application — avec l'astérisque, la précision
« · repris du dossier » et le message d'erreur assemblés à côté. La primitive
partagée porte les quatre, et l'erreur remonte au champ au lieu de flotter
sous lui.

Les cinq intitulés qui coiffent un **groupe de boutons** (type de sortie,
diagnostics cochés, traitement de sortie, conseils, contrôle) gardent leur
`<p>` : ces listes portent déjà leur propre structure, et les envelopper
ajoutait un niveau sans rien résoudre. Leur typographie est en revanche
exactement celle de `FormField`, pour que tous les libellés de l'écran se
lisent de la même façon.

Le résumé clinique passe enfin de quatre à dix rangées : un compte rendu
d'imagerie en fait une dizaine, et il fallait faire défiler un champ pour
relire ce qui part au service.

## Ce qu'une sortie pour décès ne demande pas

Constat du propriétaire : le type de sortie était bien « Décès », et le
formulaire proposait toujours :

```text
État du patient à la sortie *   Guéri · Amélioré · Stable · Non amélioré · Aggravé
Traitement de sortie             · repris de l'ordonnance
Conseils et surveillance         Suivre le traitement jusqu'au bout…
Contrôle                         Dans 3 jours · Dans 7 jours · Dans 15 jours
```

Ce ne sont pas des cases à laisser vides : ce sont des **instructions qui
n'ont pas de destinataire**, et qui s'imprimeraient sur le document remis à la
famille. Les trois dernières disparaissent donc de l'écran, et le serveur les
refuse (`prohibited`) avec un message nommé plutôt que de les ignorer en
silence — l'interface n'est jamais la seule garde (ADR-093). `RecordMedical
DischargeAction` les met à `null` de son côté : une Action est atteignable
autrement que par sa FormRequest.

**L'état du patient, lui, n'est pas absent — il est connu.** Aucune des cinq
options ne convient, mais le type de sortie *est* la réponse. Il est donc
**posé par le serveur** (`EpisodeMedicalStatus::Deceased->label()`, soit
« Décédé ») plutôt que demandé dans une liste qui n'a pas de case juste. Ce
n'est pas une invention : c'est la même information que porte déjà
`MedicalDischargeType::episodeMedicalStatus()`. L'écran l'affiche comme un fait
acquis — « Décédé — porté au dossier par le type de sortie » — au lieu d'un
blanc qui se lirait comme un oubli.

La garde de complétude du bouton cesse en conséquence de réclamer cet état :
le laisser exiger une case que l'écran ne montre plus aurait grisé le bouton
sans dire pourquoi — exactement le défaut qu'avait corrigé l'ADR-094.

**Ce qui ne change pas.** Le diagnostic final garde sa règle : un décès n'en
dispense pas, et un passage paraclinique seul reste le seul cas où il est
facultatif (ADR-094). L'heure, le lieu et les causes du décès restent exigés
(ADR-035). Une sortie ordinaire continue d'exiger l'état du patient
(CDC §33.1).

## La sortie médicale se signe aussi

Prononcer une sortie change le statut médical du passage (ADR-035) et, pour un
décès, ouvre son acte de constatation. Le bouton « Confirmer la sortie
médicale » passe donc par la même confirmation que la demande d'examen,
l'ordonnance et la conduite à tenir (ADR-106) : la fenêtre relit le type, la
date, l'état, le diagnostic et — pour un décès — l'heure, le lieu et les
causes, porte le nom du médecin et n'est pas fermable au clic extérieur.

Elle se nomme dans ses propres termes : « Confirmer le décès » et « Je
confirme le décès ». Un acte de cette portée ne se confirme pas sous un
intitulé générique.

**Amendement du 2026-09-18 — le détail du décès s'établit au registre.**
Demande du propriétaire : le registre des décès existe, la sortie médicale
ne doit plus y faire saisir le détail. Prononcer un décès demande le type, la
date de la décision et le diagnostic final ; **l'heure, le lieu et les causes
du décès ne sont plus exigés à la sortie** — ils restent acceptés s'ils sont
déjà connus, et sont exigés par l'acte de constatation du registre, où ils se
saisissent une seule fois. L'écran de sortie le dit à la place des trois
champs.

**Correctif du même jour.** `prohibited` **remplace** le jeu de règles d'un
champ, il ne s'y ajoute pas : écrite `[$deceased ? 'prohibited' : 'nullable',
'string', 'max:5000']`, la règle laissait `string` s'appliquer au `null` que
le formulaire envoie pour un champ vide — et le médecin lisait « Le champ
discharge prescription doit être une chaîne de caractères » sur un champ qu'on
venait justement de lui retirer de l'écran.

Le test ne l'avait pas vu parce qu'il omettait ces clés. Or un champ masqué
par `v-if` **reste dans l'objet du formulaire** et part à vide : le
`deceasedPayload()` des tests envoie désormais exactement ce que le navigateur
envoie, et il échoue si la règle repasse à sa forme précédente.

## Amendement du 2026-09-18 — l'acte suit la feuille de la clinique

Demande du propriétaire : la clinique a déjà son modèle papier du « Certificat
médical de constatation de décès », et l'acte doit s'y conformer, avec du
texte riche.

La fenêtre de saisie et l'impression reprennent donc les rubriques de la
feuille, dans son ordre : N° de dossier, **Médecin traitant**, **Informations
relatives au défunt** (nom, date et lieu de naissance, sexe, adresse,
filiation, CNI délivrée le / à), date et heure du décès et son lieu, causes du
décès, puis la mention « délivré à la famille du défunt pour servir et valoir
ce que de droit » et les **Signatures** (date, lieu, médecin traitant).
L'impression reproduit le bandeau au logo et les couleurs de la feuille, comme
le compte rendu d'imagerie (ADR-108) ; le titre y est écrit
« CONSTATATION » — la feuille fournie portait « CONSTATION », une coquille.

```text
repris du dossier      nom, date de naissance, sexe, N° de dossier
préremplis du dossier  lieu de naissance, adresse, N° de CNI — corrigeables
saisis sur l'acte      filiation, CNI délivrée le / à, lieu de signature
texte riche            causes du décès (exigées), observations
```

Les nouvelles valeurs sont des colonnes nullables de `death_records` : elles
sont figées sur l'acte signé et **n'écrivent jamais le dossier patient**. Le
médecin qui constate atteste ce qu'on lui a présenté ce jour-là ; corriger
l'identité permanente reste l'affaire de l'écran Patient. Le lieu de
signature vaut le site par défaut. Un acte établi avant cet amendement
s'imprime avec les valeurs du dossier patient pour ce qu'il ne portait pas,
et laisse vide la filiation, qu'il n'a jamais consignée.

Les causes et les observations passent par `ClinicalRichTextSanitizer` (même
liste blanche que l'interrogatoire) ; la longueur et le caractère obligatoire
des causes se vérifient sur le texte, jamais sur les balises : un paragraphe
vide n'est pas une cause. Le volet état civil reste hors périmètre (numéro
d'acte, déclarant, officier) : la feuille de la clinique ne le porte pas
davantage que le CDC.

---

# ADR-108 — Feuilles de compte rendu d'échographie

**Status:** ACCEPTED (2026-09-17 — formulaires papier transmis par le
propriétaire)

**Complète l'ADR-106** (familles d'imagerie) et **applique l'ADR-052** : rien
ne se déduit du nom ni du code d'un examen.

## Le constat

La saisie d'un compte rendu d'imagerie était un éditeur riche vide, avec un
simple texte d'invite « Technique, constatations, conclusion… ». En pratique,
le médecin recopiait ou collait à la main la feuille papier de la clinique —
c'est exactement ce qu'on a retrouvé dans le dossier, balises comprises, quand
le préremplissage d'une demande d'hospitalisation l'a fait remonter.

## Ce qui est transcrit, et ce qui ne l'est pas

`ImagingReportTemplates` porte les deux formulaires fournis :

```text
ECHO_ABDOMINO_PELVIENNE   foie, reins, pancréas, rate, vessie, utérus,
                          ovaires, cul de sac de Douglas, prostate, conclusion
ECHO_OBSTETRICALE_T1      utérus, ovaires, sac ovulaire, embryon,
                          conclusion, observation, N.B.
```

Le canevas ne porte que le **corps clinique**. L'en-tête de la clinique, le
numéro de dossier, l'identité du patient, « Fait le » et « Le médecin
responsable » sont déjà produits par `ClinicalDocumentPrint` à l'impression,
depuis le dossier réel : les remettre dans le canevas les ferait diverger, et
l'ADR-084 pose qu'aucune donnée déjà consignée n'est redemandée. Un test le
vérifie feuille par feuille.

## Le médecin choisit sa feuille

Aucune correspondance automatique avec le libellé de l'examen. « Échographie
pelvienne » et « échographie abdomino-pelvienne » se ressemblent assez pour
qu'une règle sur le nom finisse par insérer la mauvaise, et un compte rendu
commencé sur le mauvais canevas se relit mal — c'est la raison même de
l'ADR-052, et celle qui a déjà imposé une colonne configurée pour la famille
ECG/échographie (ADR-106).

Une feuille **remplace** tout le compte rendu. Sur un champ déjà écrit, c'est
une perte de saisie : l'insertion demande confirmation, et n'écrase jamais en
silence.

## Pourquoi un canevas de texte, et non des champs

Les formulaires de la clinique sont des feuilles à compléter : « Échostructure :
____ », « Bord : ____ Contours : ____ ». Leur contenu est du texte libre, et
il diffère d'un examen à l'autre. Un modèle relationnel à un champ par ligne
figerait dans une migration ce que la clinique corrige sur son traitement de
texte, pour un bénéfice nul — rien n'est calculé ni compté sur ces valeurs.

Le canevas reste donc du HTML restreint, dans la colonne `result_value` qui
existe déjà, et passe par le même `ClinicalRichTextSanitizer` que ce que le
médecin tape. Aucune migration, aucun second chemin d'écriture.

## Divergences signalées, jamais corrigées en silence

Les deux versions du formulaire abdomino-pelvien transmises ne disent pas la
même chose :

```text
« Abdomino-Pelvienne.pdf »   REIN : « Diamètre : »          + rubrique PROSTATE
« Échographie_1.pdf »        REIN : « Diamètre bipariétal » + rubrique N.B.
```

Le diamètre bipariétal est une mesure du crâne fœtal : sur un rein, c'est un
report de la feuille obstétricale. La transcription suit donc la première
version, qui est cohérente et porte la prostate. La seconde divergence (N.B.)
est signalée sans être tranchée.

Le formulaire obstétrical écrit « Retraversé (Fléchi) » là où le terme est
« rétroversé ». Le canevas reprend le formulaire tel qu'il est : corriger la
terminologie d'un document clinique n'appartient pas à l'implémentation.

## Ce qui manque

**Aucune feuille ECG.** Le propriétaire cite l'ECG, mais aucun modèle n'a été
transmis et le CDC n'en décrit pas. Rien n'est inventé : l'examen reste saisi
en texte libre jusqu'à réception de sa feuille.

Aucune permission, route, validation ou règle métier n'est modifiée par cette
décision.

## Amendement du 2026-09-18 — une seule saisie du compte rendu

Question du propriétaire : quelle différence entre le champ « Compte rendu »
de la consultation et « Saisir le résultat » de « Demandes d'examens » ?
Aucune pour les données : les deux écrivent la même colonne par le même
endpoint, et le serveur refuse un second compte rendu sur le même examen
(`RecordImagingResultAction`). Mais l'interface faisait doublon :

```text
deux éditeurs      celui de « Demandes d'examens » portait les feuilles de la
                   clinique et les observations ; celui de la consultation,
                   réduit, n'avait ni l'un ni l'autre
éditeur fantôme    dans la consultation, le formulaire était rattaché par
                   `v-else-if` à la ligne des *notes* : un compte rendu
                   enregistré sans notes affichait un éditeur vide en dessous,
                   dont l'enregistrement aurait forcément échoué
état dit deux fois le badge « Résultats disponibles » et le texte « Compte
                   rendu disponible »
```

La saisie vit désormais dans un seul composant, `ImagingReportDialog`,
ouvert à l'identique depuis les deux écrans — feuilles, observations,
confirmation avant remplacement, fenêtre non fermable au clic extérieur. Un
compte rendu enregistré se **lit** partout et ne s'offre plus à la saisie ;
l'état d'un examen est une icône (sablier en attente, coche enregistré), le
sens restant dit au survol et au lecteur d'écran. Aucune donnée, route ni
permission ne change.

## Amendement du 2026-09-18 — le compte rendu a la forme de la feuille papier

Demande du propriétaire : le résultat affiché et imprimé doit ressembler aux
feuilles « RÉSULTATS D'ÉCHOGRAPHIE » de la clinique, et non à une lettre
générique.

`ImagingReportDocument.vue` reproduit la feuille : bandeau bleu au logo,
titre, N° de dossier, tableau d'identité (nom et prénom, date de naissance,
sexe, adresse), bandeau de l'examen, compte rendu en **deux colonnes**
séparées d'un filet, N.B., « Fait le » et « Le médecin responsable », puis
les coordonnées de la clinique. Le bleu s'imprime (`print-color-adjust`).

```text
une seule source   App\Support\ImagingReportDocument prépare les données ;
                   l'impression et « Voir le résultat » affichent le même
                   composant — ce que le médecin relit est ce que la famille
                   emporte
titre              suit la famille réglée au catalogue (ADR-106) :
                   « Résultats d'échographie », « d'électrocardiogramme »,
                   sinon « d'imagerie » — jamais déduit du libellé
identité           vient du dossier (adresse comprise), jamais du canevas
date déclarée      jamais présentée comme une date de naissance : « N ans
                   (âge déclaré) »
coordonnées        logo, e-mail et téléphone viennent de la configuration du
                   site (`RIVO_DOCUMENT_LOGO_URL`, `RIVO_LEGAL_EMAIL`,
                   `RIVO_LEGAL_PHONE`) — rien n'est écrit en dur
```

Le numéro de passage figure à côté du N° de dossier : la feuille papier ne
l'a pas, mais sans lui un compte rendu ne se rattache plus à sa venue.

## Amendement du 2026-09-19 — quatre feuilles, strictement celles du papier

Demande du propriétaire, avec les modèles Word de la clinique : les feuilles
doivent être **strictement identiques** au papier, rubriques, mots et
répartition en colonnes comprises. Quatre feuilles remplacent les deux
précédentes : abdomino-pelvienne, **pelvienne** (nouvelle), obstétricale
1er trimestre et **obstétricale 2e – 3e trimestre** (nouvelle).

```text
mise en page   le compte rendu se lit en régions séparées par un saut <hr> :
               1re = colonne de gauche, 2e = colonne de droite, suivantes =
               cases pleine largeur (Conclusion, N.B.)
               ImagingReportDocument::regions() les découpe côté serveur ;
               un texte libre n'a qu'une région et garde l'ancien flux
filtre         `hr` rejoint la liste blanche de ClinicalRichTextSanitizer,
               sans attribut : c'est un saut de colonne, pas de la mise en forme
N.B.           il vit dans la feuille, à la place du papier. La case de
               `result_notes` devient « Observations complémentaires » pour ne
               pas doubler un « N.B. » déjà présent
```

Le papier abdomino-pelvien existe en **deux versions**, reprises chacune comme
une feuille distincte : l'une avec un N.B. dans la colonne de droite et
« Diamètre bipariétal » sur les reins, l'autre avec une rubrique PROSTATE à la
place du N.B. et « Diamètre: ». La clinique n'a pas tranché entre elles ; le
libellé « (avec prostate) » ne fait que décrire ce qui les distingue à l'œil.

```text
EMBRYON (T1)   coupée entre les deux colonnes, comme sur le papier
```

**La fenêtre de saisie suit la feuille.** Chaque case du papier a son propre
éditeur — « Colonne de gauche », « Colonne de droite », « Pleine largeur » —
sous une barre d'outils commune ; le `<hr>` n'est plus qu'un détail de
stockage que le médecin ne voit ni ne peut effacer. Le choix de la feuille est
une liste (`Select`) dont « Texte libre (sans colonnes) » est la première
entrée : y revenir garde le texte, seules les colonnes disparaissent. Les
observations complémentaires sont repliées tant qu'elles sont vides.

Le plafond serveur passe de 5 000 à **10 000 caractères** de HTML : une feuille
vierge en consomme déjà 1 500, et un compte rendu obstétrical détaillé
approchait l'ancien plafond. Un compte rendu réduit à ses seuls sauts de colonne
(`<hr>`, cases vides) est désormais refusé comme vide — `<p><br></p>` passait
déjà, à tort.

Les libellés sont repris tels quels, coquilles comprises (« Hyertrophié »,
« Noramale », « Retraversé » en T1 mais « Retroversé » en pelvienne) :
corriger la terminologie d'un document de la clinique n'appartient pas à
l'implémentation. **À valider avec la clinique** avant de les rectifier.

Limite connue : le bandeau bleu affiche le nom de l'examen du catalogue
(« Échographie obstétricale du 1er trimestre »), pas le titre exact de la feuille
choisie (« ÉCHOGRAPHIE OBSTETRICALE (1er TRIMESTRE) »). La feuille utilisée n'est
pas enregistrée avec le compte rendu ; l'y ajouter demanderait une colonne et
un chemin d'écriture de plus.

## Amendement du 2026-09-19 — les médecins du site ajoutent leurs feuilles

Demande du propriétaire : pouvoir ajouter une nouvelle feuille depuis la
fenêtre. Le CDC ne dit pas qui peut le faire ; arbitrage explicite du
propriétaire : **les médecins du site**, en **enregistrant le contenu actuel**.

```text
stockage     imaging_report_templates : propre à chaque site, comme les
             protocoles (ADR-111). Les cinq feuilles papier restent dans le
             code et ne se modifient ni ne se retirent jamais.
droits       imaging_templates.create   accordée à MEDICINE par la migration
             imaging_templates.archive  (ADR-064 : un site en production ne
                                         rejoue plus RolePermissionSeeder)
             Créer et retirer ne sont pas la même autorité.
écran        bouton « + » à côté de la liste, actif seulement sur un texte
             écrit ; « Retirer » n'apparaît que sur une feuille ajoutée
règles       nom obligatoire et unique — feuilles de la clinique comprises,
             sans tenir compte de la casse ; feuille vide refusée ; corps
             assaini comme tout texte clinique
retrait      archivage (Soft Delete, ADR-009), jamais suppression : les
             comptes rendus déjà écrits gardent leur texte, le nom se libère
```

**Le texte devient le modèle tel quel.** Le serveur ne peut pas deviner ce qui
est propre au patient (mesures, constatations, conclusion) : la fenêtre
d'enregistrement le dit en toutes lettres, et propose la feuille à tous les
médecins du site. Un futur besoin d'éditer une feuille déjà ajoutée (renommer,
remplacer son contenu) n'est pas couvert : on l'archive et on la recrée.

Un `watch` de la fenêtre retournait un tableau `[uuid, mode]` : Vue le
considérait changé à chaque re-rendu, même pour le même examen, et remettait le
compte rendu à zéro. Enregistrer une feuille recharge la page et le révélait ;
il surveille désormais une chaîne.

## Amendement du 2026-09-19 — la feuille s'ouvre d'elle-même pour son examen

Demande du propriétaire : ouvrir « Saisir le résultat » d'une échographie doit
créer directement les colonnes de la feuille qui lui correspond. Cela
**assouplit** le premier principe de cet ADR (« le médecin choisit
explicitement ») sans lever l'ADR-052 : la feuille est **pré-appliquée**, le
médecin peut toujours en changer, et le lien examen → feuille est **réglé**,
jamais déduit d'un motif dans le nom ou le code.

```text
réglage du site  imaging_exam_report_templates (catalog_item_id unique,
                 template_key nullable). Nul = « aucune feuille d'office », un
                 choix explicite qui l'emporte sur la liste du code.
liste du code    ImagingReportTemplates::DEFAULT_BY_EXAM_CODE — explicite, par
                 code du catalogue : ECHO-ABD-PEL, ECHO-PEL, ECHO-OBS-T1,
                 ECHO-OBS-T2 et ECHO-OBS-T3. Ni ECHO-ABD, ni ECHO-OBS, ni
                 ECHO-MAMMAIRE : aucune feuille papier n'y correspond sans
                 ambiguïté, donc aucune n'est devinée.
réglage          depuis la fenêtre, avec `imaging_templates.create` : épingle
                 « Proposer cette feuille d'office pour cet examen », et case
                 cochée à la création d'une feuille (même transaction). Le
                 catalogue n'est jamais modifié (ADR-024).
ouverture        en première saisie seulement, et champ vide : rien n'est
                 écrasé. Une correction repart du texte enregistré.
feuille retirée  n'est plus proposée : mieux vaut aucune feuille qu'une feuille
                 disparue.
```

**Aucune feuille papier n'existe pour l'échographie mammaire** (ni pour la
thyroïde, la prostate, la scrotale…). L'automatisme demandé exige donc que la
clinique fournisse ces modèles, ou qu'un médecin en crée un une fois avec « + »
en cochant « Proposer d'office » : toutes les saisies suivantes de cet examen
s'ouvrent alors sur ses colonnes.

## Amendement du 2026-09-19 — aperçu avant d'enregistrer

Un compte rendu signé ne se réécrit pas en silence (ADR-130) : le médecin doit
pouvoir relire ce qui sortira **avant** de l'enregistrer. Le bouton « Aperçu »
de la fenêtre de saisie ouvre le document tel qu'il s'imprimera.

```text
composition   le serveur (POST …/result/preview, ImagingReportDocument::preview),
              avec le même code que l'impression : identité du patient, titre
              selon la famille d'imagerie, colonnes, signature du médecin
              connecté et date du moment. Aucune copie côté navigateur — deux
              mises en page du même document finiraient par diverger.
écriture      aucune : ni compte rendu, ni audit, ni état de l'examen changé
droit         imaging_results.create OU imaging_results.update : l'aperçu sert
              à une première saisie comme à une correction
refus         un compte rendu vide, y compris réduit à des cases vides (<hr>)
sortie        « Retour à la saisie » ou « Enregistrer » depuis l'aperçu ;
              l'aperçu n'est pas obligatoire, il ne remplace pas la saisie
```

Il a révélé un défaut de la feuille : le reset de l'application retire les puces
des listes, que le papier porte. Elles sont rétablies dans le document — pour
l'aperçu comme pour l'impression, qui les perdaient depuis l'origine.

## Amendement du 2026-09-19 — propositions, titre exact et modification d'une feuille

Trois demandes du propriétaire, qui n'a pas de modèle papier pour les autres
échographies : générer les feuilles manquantes, faire porter au bandeau le titre
exact de la feuille, et pouvoir modifier une feuille ajoutée.

**Propositions du système.** Sept feuilles sont écrites faute de modèle :
abdominale, rénale et vésicale, prostatique, mammaire, thyroïdienne, scrotale,
parties molles. Ce sont du contenu médical rédigé par le système : elles ne
sont donc **jamais présentées comme un papier de la clinique**.

```text
marquage      validated = false ; libellé « … (proposition) » ; groupe
              « Propositions à valider » dans la liste ; bandeau d'avertissement
              à l'ouverture : « à faire valider par un médecin de la clinique »
contenu       des rubriques à compléter, jamais une valeur, une norme ni un
              seuil (un test refuse tout chiffre). Les organes déjà présents
              sur un papier de la clinique en reprennent les rubriques.
usage         proposées d'office pour leur examen (ECHO-ABD, -RENAL, -PROSTATE,
              -MAMMAIRE, -THYROIDE, -SCROTALE, -PARTIES-MOLLES) ; un site les
              recopie en feuille à lui (« + ») pour les corriger
sans feuille  ECHO-OBS, ECHO-MORPHO, ECHO-CARD et les ECG : trop ambigus ou
              trop spécialisés pour qu'une trame générique soit prudente
```

**Titre exact du bandeau.** `imaging_request_items.report_sheet_title` en est un
**instantané** : renommer ou retirer la feuille plus tard ne réécrit jamais un
compte rendu signé. Le navigateur n'envoie qu'une clé (`sheet_key`, `FREE` pour
la saisie libre) et **le serveur lit le titre** — il ne dicte jamais l'intitulé
d'un document. Feuille non touchée en correction : le titre reste ; feuille
disparue entre-temps : traitée comme non touchée. Les titres papier sont
repris tels quels, casse comprise (« 1er TRIMESTRE », « 2ème – 3ème ») ; sans
feuille, le bandeau garde le nom de l'examen en capitales.

**Modifier une feuille ajoutée.** `imaging_templates.update` (accordée à
MEDICINE par migration) : renommer, décrire, et remplacer le contenu par le
texte actuel de la fenêtre si la case est cochée. Les comptes rendus déjà écrits
ne bougent jamais ; l'audit garde l'ancienne et la nouvelle valeur. Les feuilles
de la clinique et les propositions ne se modifient pas (elles vivent dans le code).

---

# ADR-109 — Le besoin de l'arrivée n'est ni redemandé au médecin, ni refacturé

**Status:** ACCEPTED (2026-09-18 — signalement explicite du propriétaire)

**Complète l'ADR-105** (les examens paracliniques sont facturés à la demande)
et **applique l'ADR-084** (« jamais deux fois la même saisie ») à l'étape
Paraclinique.

## Le constat du propriétaire

Mme R. arrive pour une **Échographie obstétricale**. La Réception la
sélectionne, l'écran de consultation l'affiche en tête — « Consultation en
cours · Échographie obstétricale ». Puis l'étape Paraclinique présente un
champ de recherche vide et demande au médecin de retrouver le même examen
dans le catalogue.

Le propriétaire l'a posé en ces termes : le besoin est déjà connu, pourquoi
forcer le médecin à le ressaisir.

## Ce que la vérification a trouvé de plus grave

Sur le passage réel `A-26-0009-01` :

```text
demande de la Réception   Échographie obstétricale (module IMAGING)
prestation facturable     Échographie obstétricale — 50 000 Ar — INVOICED
demande d'imagerie        aucune
```

La Réception avait donc **déjà facturé** l'examen (ADR-068), et la facture
était émise. Depuis l'ADR-105, créer la demande d'imagerie facture aussi —
avec une clé d'idempotence dérivée de la ligne de demande. Le médecin qui
suivait la consigne de l'écran produisait donc un **second `BillableItem` de
50 000 Ar pour la même échographie**.

Ce n'était pas un défaut d'ergonomie avec un effet secondaire : c'est le même
défaut. Ressaisir un besoin déjà connu, c'est créer une seconde fois ce qui
existait.

## Pourquoi la garde existante ne s'appliquait pas

`RecordBillableItemAction` sait déjà ne pas refacturer une prestation
planifiée à la Réception (ADR-054) : en l'absence de clé explicite, elle en
dérive une de l'`EpisodeServiceRequest` correspondante et **rejoue** la
prestation déjà créée.

L'ADR-105 imposait une clé à chaque appel — `'imaging_request_item:'.$uuid` —
ce qui court-circuitait exactement cette garde. La clé était juste pour son
propre besoin (une relance ne facture pas deux fois la même ligne) et fausse
pour celui-là.

## La règle

`PlannedServiceBilling::unconsumedFor()` répond à la seule question qui
manquait : *cet examen a-t-il déjà été facturé à l'arrivée, et cette
facturation est-elle encore disponible ?*

```text
prestation planifiée ET facturée, non encore rattachée  -> la demande la rattache
aucune prestation planifiée                             -> la demande facture (ADR-105)
prestation déjà rattachée à une autre ligne             -> la demande facture
prestation annulée                                      -> la demande facture
```

Le troisième cas compte autant que le premier : un médecin qui redemande une
échographie après en avoir lu le résultat demande un **second acte**, et il se
facture. La garde ne transforme jamais « déjà payé une fois » en « gratuit
ensuite ». Un test le vérifie dans les deux sens.

La ligne de demande porte alors le `billable_item_id` de la Réception : la
facturation n'est pas seulement évitée, elle est **rattachée**. Retirer la
demande (ADR-079) retrouve donc exactement ce qu'elle a porté, et n'annule
pas un montant que la Réception a déjà facturé — seule la Réception/Caisse
touche un montant facturé (ADR-012).

## Le besoin connu entre dans la demande

`planned_paraclinical` sert au médecin les examens que la Réception a
planifiés et dont la demande reste à transmettre. L'écran les met dans la
demande en préparation **au montage**, et l'annonce en une ligne :

> Échographie obstétricale — demandé à l'arrivée, déjà facturé.

La première version tenait trois phrases et expliquait aussi qu'on pouvait
retirer la ligne. Le propriétaire l'a fait raccourcir : la ligne est juste
en dessous avec son bouton de retrait, et un bandeau qui explique ce que
l'écran montre déjà se lit comme un avertissement.

Trois précisions qui ne sont pas décoratives :

- **Le serveur décide ce qui reste à transmettre.** Une ligne disparaît dès
  qu'une demande active la porte — ce qui est transmis n'est plus à
  transmettre. Rien n'est déduit d'un libellé : c'est le module du
  `catalog_item` qui classe, comme partout ailleurs (ADR-052).
- **La présélection est faite une fois par examen.** Une ligne que le médecin
  retire à la main ne revient jamais : il peut décider que l'examen demandé à
  l'accueil n'est pas celui qu'il faut.
- **Le médecin reste libre d'ajouter ce qu'il veut**, avant comme après son
  diagnostic. La recherche au catalogue ne disparaît pas ; elle cesse
  seulement d'être le seul chemin.

## Ce qui ne change pas

La facturation à la demande (ADR-105), la confirmation de transmission
(ADR-106), l'annulation d'une demande sans résultat (ADR-079), la résolution
de l'étape à l'envoi (ADR-105). La Médecine n'encaisse toujours rien.

Aucune permission, route ni migration.

---

# ADR-110 — Une ordonnance ne réclame que ce que le produit porte réellement

**Status:** ACCEPTED (2026-09-18 — exigence explicite du propriétaire :
« lorsqu'on donne une ordonnance il faut adapter logique et intelligence,
ex : Compresses stériles pourquoi on a besoin de Dose ? »)

**Complète l'ADR-083** (posologie non ambiguë) et **applique l'ADR-052** : la
forme du référentiel décide, jamais le nom ni le code d'un produit.

## Le constat

L'éditeur de ligne posait les mêmes quatre champs à tout produit du
référentiel Pharmacie, et `dosage` était **obligatoire** côté serveur. Pour un
comprimé, « 500 mg » est la donnée la plus importante de la ligne. Pour un
paquet de compresses stériles, c'est un champ obligatoire **impossible à
remplir honnêtement** : une compresse n'a pas de dose, on en utilise un
nombre. Le médecin n'avait que deux issues — écrire quelque chose de faux, ou
ne pas prescrire le produit.

Deuxième constat du même message : la quantité totale se calculait dans la
tête. « 3 fois/jour pendant 7 jours » vaut 21 unités, et rien ne le disait.

## La dose suit la forme, jamais le libellé

```text
forme du référentiel PARAPHARMACY_CONSUMABLE  -> dose facultative
toute autre forme                              -> dose exigée
ligne manuelle (ADR-037)                       -> dose exigée
```

`MedicineForm::undosedValues()` porte cette liste une seule fois, et
`isDosed()` la relit. Rien n'est déduit d'un nom contenant « compresse » ou
« gants » (ADR-052) : un produit mal classé au référentiel se corrige au
référentiel, il ne se devine pas à la prescription.

Une **ligne manuelle** garde sa dose obligatoire, et ce n'est pas un oubli :
elle ne référence aucune forme, donc rien ne permet de savoir qu'elle ne se
dose pas. L'exiger est la seule réponse qui n'invente rien.

La règle vit côté serveur — `lines.*.dosage` résolue **ligne par ligne** par
`Rule::forEach`, une règle `lines.*` ne pouvant pas lire la forme du produit
de sa propre ligne. Masquer le champ dans Vue n'aurait rien protégé d'un
appelant qui poste directement. `prescription_lines.dosage` était déjà
nullable : aucune migration.

Une absence reste une absence : un champ vide est enregistré `null`, jamais
une chaîne vide (ADR-077). Le champ masqué par `v-if` reste dans l'objet du
formulaire et part à vide — c'est exactement ce que le navigateur envoie, et
c'est ce que le test poste.

## La quantité que la posologie implique

`resources/js/utilities/posology.js` porte le calcul **une seule fois** : le
même module sert l'éditeur de ligne et la fenêtre de confirmation de
l'ADR-106, et deux formules finiraient par annoncer deux quantités pour la
même ordonnance.

```text
3 fois/jour × 7 jours    -> 21     · une unité par prise
matin et soir × 2 semaines -> 28
2 fois/jour, prise unique  -> 2
```

Le calcul suppose **une unité par prise**, et l'écran l'écrit sous le champ
(`3/jour × 7 jours · une unité par prise`) plutôt que de livrer un chiffre
nu. Déduire qu'une dose couvre deux comprimés exigerait de comparer la dose
au dosage du produit — deux textes libres dont l'unité ne correspond pas
toujours, et une erreur de facteur deux sur une quantité réservée n'est pas
une approximation acceptable.

**Il ne propose rien quand il n'a rien à proposer**, et c'est le point :

```text
« si besoin »            aucune cadence — une prise conditionnelle n'en a pas
fréquence tapée à la main  deviner un nombre dans une phrase libre
                           reviendrait à inventer une posologie
durée absente ou absurde   rien à multiplier
```

La suggestion **n'écrase jamais une saisie**. Dès que le médecin touche la
quantité, `_quantity_touched` verrouille la ligne : corriger 21 en 30 parce
que la boîte en contient trente est une décision, pas une faute de frappe à
recalculer. C'est le même principe que le matériel habituel d'un acte de
soins (ADR-072) — une suggestion de saisie, jamais une règle.

## Ce qui ne change pas

Prescrire reste une décision : la validation réserve en FEFO (ADR-036) sans
décrémenter aucune quantité physique, et une ligne manuelle ne réserve
toujours rien (ADR-037). La posologie composée avec ses unités (ADR-083) et
la confirmation de validation (ADR-106) sont inchangées. Aucun prix n'apparaît
sur une ligne d'ordonnance.

Aucune permission, route ni migration nouvelle : `prescriptions.create`
gouverne l'ordonnance comme avant.

## Amendement du 2026-09-18 — l'écran Ordonnance passe à shadcn (ADR-099)

Demande du propriétaire. `PrescriptionLineEditor` portait encore trois
`<select>` natifs (unité de dose, voie, unité de durée) et six libellés
écrits à la main : ils passent à `Select` et `FormField`. L'unité de la
quantité est désormais **attachée au champ** — posée à côté en texte nu,
elle se lisait comme une note. Le catalogue, les lignes en préparation, les
pastilles du tableau et le formulaire de modification suivent les tokens
sémantiques et `Badge`/`Button` partagés.

Deux défauts d'ergonomie corrigés au passage :

```text
catalogue        une ligne déjà retenue et une ligne épuisée étaient toutes
                 deux inertes, sans dire pourquoi → « Dans l'ordonnance »
                 et « Épuisé » sont désormais deux états distincts
quantité         une quantité corrigée à la main ne se recalcule jamais
                 (règle ci-dessus) ; « Utiliser 21 » ramène la suggestion
                 sans la recalculer de tête, par le même chemin que la saisie
```

La ligne de modification d'une ordonnance couvrait sept colonnes sur huit ;
elle couvre désormais le tableau entier. Aucune donnée envoyée au serveur,
aucune validation ni règle de réservation ne change.

Un test de garde vérifie qu'aucun composant n'est utilisé sans import dans
l'écran Médecine et les composants cliniques : le build ne le détecte pas,
et c'est exactement ce qui s'est produit pendant cette migration.

---

# ADR-111 — Diagnostic et ordonnance proposés par les protocoles de la clinique

**Status:** ACCEPTED (2026-09-18 — exigence explicite du propriétaire, après
arbitrage sur la source des propositions)

Demande du propriétaire : le système analyse le dossier (interrogatoire,
examen, diagnostic, âge, sexe…) et **propose** un diagnostic et une
ordonnance, que le médecin peut toujours modifier. « Tout ce qui peut être
automatisé doit l'être, mais rester modifiable par un humain. » Précision du
propriétaire : un algorithme **local**, sur les données de l'application,
sans API d'IA ni service externe.

## Ce qui a été vérifié avant de construire

```text
CDC            aucune mention d'aide à la décision, de proposition ni de protocole
référentiel    médicaments : nom, DCI, forme, dosage — aucune dose par âge ou
               poids, aucune indication, aucune contre-indication
               diagnostics : 30 entrées, aucun lien avec des symptômes
historique     3 ordonnances, 5 diagnostics : rien à apprendre
```

Rien dans le système ne permet donc de **déduire** une ordonnance. Écrire des
règles « diagnostic → médicament → dose » dans le code aurait été inventer de
la médecine — ce que la règle du projet interdit, et ce qui met un patient en
danger. La question a été posée au propriétaire.

## L'arbitrage retenu : un algorithme local, aucune IA externe

Une première question a été mal posée : elle proposait une « IA (Claude) »
comme source possible, et sa réponse a d'abord été lue comme un choix d'IA
externe. Le propriétaire a précisé le 2026-09-18 ce qu'il demandait réellement :
**« intelligent et automatique » veut dire un algorithme qui s'appuie sur les
données locales de l'application, sans aucune API d'IA ni aucun service
externe.**

```text
source 1  les protocoles écrits par les médecins de la clinique
source 2  la pratique de la clinique : ce que ses médecins ont conclu et
          prescrit dans les consultations déjà enregistrées
réseau    aucun — tout est calculé dans la base du site, rien n'en sort
```

Les protocoles passent d'abord : ils sont la décision écrite de la clinique.
La pratique observée ne complète que ce qu'aucun protocole ne couvre. Aucune
donnée patient ne quitte le site : la question de l'envoi « anonymisé » ou
« complet » ne se pose plus. Un test l'impose : les propositions sont
calculées avec toute requête HTTP sortante interdite.

## Ce qui est construit : les protocoles de la clinique

`clinical_protocols` et `clinical_protocol_lines` portent ce qu'un médecin
écrit : le diagnostic traité, ses **signes évocateurs**, la population visée
(âge, sexe, poids), l'ordonnance type et des notes. Le système les applique ;
il n'en invente aucun. Au premier jour la table est vide, et l'écran le dit
plutôt que de ne rien proposer en silence.

`ClinicalProtocolMatcher` lit ce que le dossier contient déjà — motif,
histoire, début, notes, notes d'examen, observation générale, constatations
des seuls appareils **anormaux** (ADR-077), âge au passage, sexe, poids relevé
aux Soins, allergies permanentes et déclarées — et :

```text
diagnostics  proposés quand leurs signes se retrouvent dans le dossier ;
             mot pour mot, sans accents ni casse ; « toux » ne se trouve
             pas dans « touxine » ; déjà posés → pas reproposés
ordonnance   celle des protocoles des diagnostics **posés**, pour ce patient
```

Chaque proposition dit **pourquoi** : les signes retrouvés (« 2/3 signes »).
Chaque protocole écarté dit pourquoi aussi (« Réservé aux 1–14 ans — patient
de 32 ans ») : sans la raison, le médecin conclurait qu'aucun protocole
n'existe. Une borne posée **exclut** un patient dont la valeur est inconnue —
un protocole pédiatrique ne s'applique pas faute de connaître l'âge.

## Proposer n'est pas décider

Les propositions sont **calculées à chaque affichage, jamais enregistrées** :
ce n'est pas un fait clinique. Elles apparaissent là où le médecin conclut —
Examen clinique, Décision & clôture, Prescription.

```text
diagnostic proposé  → « Retenir » l'enregistre ; corrigeable et retirable
                       ensuite comme tout autre (ADR-035, ADR-106)
ligne proposée      → « Ajouter » la met en préparation, préremplie ; le
                       médecin règle dose, durée, quantité, puis valide et
                       signe (ADR-106)
```

Rien n'est validé automatiquement. Deux garde-fous :

```text
produit épuisé          visible, jamais ajoutable
allergie recoupée       en rouge ; exclue de « Tout ajouter » ; ajoutable
                        seulement ligne par ligne, « malgré l'allergie »
```

La comparaison d'allergie est par mots entiers, sans accents, dans les deux
sens. C'est un **signal**, pas une interdiction : le médecin peut avoir ses
raisons, mais il ne le fera pas sans l'avoir vu.

## La trace d'origine ne peut pas être forgée

`diagnoses` et `prescription_lines` reçoivent `suggestion_source` et
`clinical_protocol_id`. Le médecin reste l'auteur ; la trace dit d'où venait
la proposition retenue, **même ajustée**. Le serveur la revérifie :

```text
diagnostic  le protocole doit traiter ce diagnostic
ligne       le protocole doit prescrire ce médicament
```

Sinon, refus explicite plutôt que d'attribuer au protocole ce qu'il n'a jamais
proposé. La même vérification vaut pour la pratique de la clinique (plus bas).

## Rédiger un protocole

`/medicine/protocoles` : liste (actifs, suspendus, archivés), rédaction en
trois temps (diagnostic, patients concernés, ordonnance type) avec le même
éditeur de ligne que l'ordonnance. Corriger un protocole ne réécrit aucun
dossier : il remplace ses lignes, et l'audit (`clinical_protocol.create` /
`.update`) garde l'ancienne et la nouvelle version. L'archivage exige un motif
(Soft Delete, ADR-009) ; un protocole qui a servi est protégé contre la
suppression définitive.

```text
clinical_protocols.view     lire les protocoles
clinical_protocols.manage   les rédiger, les archiver, les restaurer
```

Les deux sont accordées au rôle `MEDICINE` : un protocole est une décision
médicale. Conséquence assumée : tout médecin peut modifier ce qui est proposé
à ses confrères — l'audit le trace, et l'éditeur de socle (ADR-064) peut
réserver `.manage` à un médecin référent.

## La pratique de la clinique : ce que ses médecins ont déjà fait

`ClinicPracticeIndex` apprend des consultations conclues — diagnostics non
annulés, ordonnances actives, 3 000 dernières au plus — et
`ClinicPracticeAdvisor` en tire deux propositions.

```text
diagnostic   les mots de l'interrogatoire et de l'examen qui accompagnent
             un diagnostic ; un mot pèse d'autant plus qu'il est fréquent
             pour ce diagnostic et rare ailleurs (« toux » distingue une
             bronchite, « douleur » presque rien)
ordonnance   les médicaments prescrits pour un diagnostic posé, avec la
             posologie qui revient le plus souvent ; la quantité est laissée
             au calcul de la posologie (ADR-110)
```

Il **rejoue ce que les médecins ont fait**, il n'invente rien — et il le dit :
« Mots retrouvés dans 5 consultations passées : toux (4), fièvre (3) »,
« prescrit dans 4/6 consultations ». Des seuils l'empêchent de parler trop
tôt, et ce sont des règles de statistique, pas de médecine :

```text
MIN_CASES        3     consultations d'un diagnostic avant de le proposer
MIN_OCCURRENCES  2     occurrences d'un mot ou d'un médicament
MIN_SHARE        30 %  des cas d'un diagnostic où le médicament a été prescrit
```

Quatre précautions :

```text
motif prérempli      le nom de la prestation (« Consultation de médecine
                     générale ») est retiré du texte : c'est l'orientation,
                     pas un symptôme — sans cela, « générale » évoquait un
                     diagnostic (constaté en test, puis corrigé)
propre preuve        la consultation en cours est retirée du calcul : elle ne
                     prouve pas ce qu'elle vient d'écrire
tranche d'âge        un patient hors des âges déjà traités est signalé et
                     exclu de « Tout ajouter » — une dose d'adulte ne se
                     transpose pas à un enfant ; âge inconnu, idem
fraîcheur            l'index est reconstruit dès qu'un diagnostic, une
                     annulation ou une ligne d'ordonnance change
```

Au premier jour, l'historique est vide et l'algorithme ne propose rien —
l'écran le dit, avec le nombre de consultations déjà apprises. **Il devient
plus pertinent à mesure que la clinique utilise RIVO**, sans rien configurer.

La trace d'origine vaut pour les deux sources : `suggestion_source` prend
`PROTOCOL` ou `CLINIC_PRACTICE`. Pour la seconde, le serveur revérifie que la
pratique pouvait réellement faire cette proposition — un diagnostic assez
documenté, un médicament réellement et régulièrement prescrit pour l'un des
diagnostics posés. Une trace forgée est refusée.

Le texte du dossier est lu par une seule classe, `ClinicalNarrative`, pour
les deux moteurs : deux lectures finiraient par diverger.

## Au passage — une dose exigée à tort (ADR-110)

La garde qui active « Valider » réclamait encore une dose pour toute ligne :
le serveur acceptait une compresse sans dose, mais le bouton restait grisé.
`hasPosology()` suit désormais la même règle que le serveur. Les unités de
dose et de durée vivent maintenant dans `utilities/posology.js`, partagées par
l'éditeur et le préremplissage.

---

# ADR-112 — Le médecin retire un acte demandé aux Soins

**Status:** ACCEPTED (2026-09-18 — exigence explicite du propriétaire)

**Complète l'ADR-055** (ordre de soins Médecine → Soins), qui ne prévoyait
aucun moyen de revenir sur une demande transmise.

## La règle

Le médecin peut **retirer** un acte demandé tant que la demande est en
cours et que l'acte n'a pas été réalisé (règle élargie par l'amendement
ci-dessous ; la première version s'arrêtait dès que les Soins prenaient le
patient).

```text
orientation Soins PENDING, acte ni réalisé ni marqué  → retrait possible
Soins a pris le patient (IN_PROGRESS)                 → refus nommé, « Non réalisé » côté Soins
consultation clôturée                                 → refus
```

## Retirer n'est pas supprimer

`care_order_items` reçoit `cancelled_at`, `cancelled_by` et `cancel_reason`
(ADR-010). **Aucun motif n'est saisi** (précision du propriétaire, même règle
que l'annulation d'un diagnostic, ADR-035) : le serveur enregistre l'auteur,
la date et un motif fixe (`CancelCareOrderItemAction::DEFAULT_REASON`). Le
geste est une icône corbeille suivie d'une confirmation non fermable au clic
extérieur. La ligne reste visible des deux côtés, barrée et marquée
« Retiré », avec son auteur et l'heure ; l'audit l'enregistre
sous `care_order.item.cancel`. Un acte retiré compte comme résolu : il ne
bloque pas la fin des Soins, ne peut plus être enregistré comme réalisé ni
marqué non réalisé, et peut être **redemandé** — c'est une nouvelle demande.

Quand plus aucun acte de la demande n'est actif, `CareOrderStatus::Cancelled`
est posé et la file Soins se referme (`EpisodeOrientation::cancel()`) —
**seulement** si elle n'existait que pour des ordres de soins : ouverte par
`CreateCareOrderAction` (motif `ORIENTATION_REASON`), passage non urgent, et
aucune autre demande en attente. Une file du plan d'arrivée ou d'une urgence
(ADR-021, ADR-056) n'appartient pas à cette demande et reste ouverte.

## Permissions

Aucune nouvelle : `care_orders.create`. Qui peut demander peut retirer ;
`NURSE` ne le peut pas. Le serveur revérifie chaque condition.

## Amendement du 2026-09-18 — retirable tant que la demande est en cours

Constat du propriétaire : une demande « En cours » dont l'acte était marqué
« Non réalisé » par l'infirmier ne pouvait plus être retirée — la règle
ci-dessus s'arrêtait dès que les Soins avaient pris le patient. Elle devient :

```text
demande en cours (en attente OU prise par les Soins)
  acte non réalisé, même marqué « Non réalisé »       → retrait possible
  acte réalisé, même en partie                         → refus : il a eu lieu
demande terminée ou retirée                            → refus
consultation clôturée                                  → refus
```

Le constat « Non réalisé » de l'infirmier n'est pas effacé : il reste sur la
ligne, à côté du retrait. Quand les Soins ont déjà le patient, retirer tous
les actes ne referme pas leur prise en charge — l'infirmier la termine comme
d'habitude, et la règle « au moins un acte réalisé » ne s'applique plus à une
demande dont le médecin a tout retiré : l'exiger obligerait à inventer un
acte. Tant que personne n'a pris le patient, le comportement ci-dessus est
inchangé (demande annulée, file refermée).

## Écran « Prescription de soins » (2026-09-18)

À la demande du propriétaire, la carte est allégée en deux colonnes
(shadcn, ADR-099) : à gauche la préparation — recherche, actes retenus sur
une ligne chacun (quantité, corbeille), instructions sur deux lignes et
« Après les soins » en sélecteur segmenté ; à droite, en colonne latérale,
les demandes déjà transmises. Seules celles encore à l'œuvre aux Soins y
sont dépliées ; les demandes terminées ou retirées se replient sous
« Historique ». Aucune donnée ni règle ne change.

---

# ADR-113 — Module Hospitalisation et fiche de régime

**Status:** ACCEPTED (2026-09-18 — exigence explicite du propriétaire, qui
fournit la « Fiche de régime » papier de la clinique et tranche les règles
ci-dessous) ; la chambre / le lit en texte libre est **remplacé par le
référentiel des lits de l'ADR-164** dès qu'un site a configuré ses lits

**Complète l'ADR-084**, qui s'arrêtait à la demande d'hospitalisation
(`REQUESTED` / `CANCELLED`) faute de règles d'admission et de sortie, et
**l'ADR-032/ADR-074**, qui annonçaient ce module sans le définir. Le CDC
officiel ne décrit ni admission, ni séjour, ni régime alimentaire ; il
connaît seulement le statut médical « Hospitalisé » (§32). Les règles
ci-dessous viennent donc toutes du propriétaire, question par question.

## Les règles tranchées

```text
admission        automatique : le séjour commence dès que le médecin
                 transmet sa demande d'hospitalisation
fin du séjour    la sortie médicale prononcée par le médecin (CDC §33.1)
fiche de régime  remplie par Médecine et Soins, en texte libre
facturation      aucune : la fiche est un suivi, jamais une prestation
chambre / lit    texte libre facultatif, sans gestion d'occupation des lits
```

## Le séjour, distinct de la demande

`hospital_stays` porte ce que la demande ne peut pas porter : l'heure réelle
d'entrée et son auteur, le service (repris de la demande), la chambre / le
lit, la sortie et la `MedicalDischarge` qui l'a prononcée. La demande reste
une DEMANDE (ADR-084) ; `MedicalRequestStatus` ne reçoit toujours aucun
`ADMITTED` — c'est le séjour qui est admis.

`AdmitHospitalStayAction` s'exécute dans la transaction de
`CreateHospitalizationRequestAction` : l'orientation Médecine →
Hospitalisation est prise en charge au même instant, le passage passe au
statut médical `HOSPITALIZED`, et un `active_key` garantit **un seul séjour
en cours par passage**.

Parce que l'orientation reste active pendant le séjour, clôturer la
consultation laisse le passage `IN_CARE` : un patient au lit n'apparaît pas
dans « Sorties & règlements » (ADR-090) tant que le médecin ne l'a pas fait
sortir.

## Retirer une demande, tant que rien n'a commencé

L'admission étant immédiate, l'orientation n'est jamais « en attente », et
la règle de l'ADR-084 (« refusé dès que la destination a pris la demande »)
aurait interdit tout changement d'avis. Le retrait reste donc possible
**tant que la fiche de régime est vide** : le séjour passe `CANCELLED` (jamais
supprimé, ADR-010), l'orientation est annulée, le statut médical revient à
`IN_CARE`. Dès la première ligne de régime, le patient a réellement séjourné :
le retrait est refusé, et c'est la sortie médicale qui termine le séjour.

## La sortie est la sortie médicale

`DischargeHospitalStayAction` crée la même `MedicalDischarge` qu'une
consultation (ADR-035, ADR-107) — mêmes types, mêmes champs, même
validation (`DischargeHospitalStayRequest` hérite de
`StoreMedicalDischargeRequest`), même formulaire à l'écran avec sa
confirmation signée. Elle est rattachée à la consultation qui a demandé
l'hospitalisation, sans la rouvrir ni la modifier : aucun diagnostic n'y est
ajouté, le diagnostic final vit sur la sortie et y est **toujours exigé** —
un séjour hospitalier n'est jamais un passage paraclinique seul (ADR-094).

Elle termine l'orientation, porte le statut médical du type de sortie, et
fait passer le passage en `PENDING_SETTLEMENT` selon la règle de l'ADR-054.
Un décès prononcé ici rejoint le registre des décès (ADR-107).

## La fiche de régime

Elle reproduit la feuille de la clinique. L'en-tête — N° de dossier, nom,
allergie, tabac, motif d'hospitalisation — est **repris du dossier**, jamais
ressaisi : allergies permanentes du patient, tabac de la fiche Soins du
passage, motif de la demande. Un tabac non renseigné reste non renseigné,
jamais écrit « Non ».

`hospital_diet_entries` porte une ligne par jour et heure, avec les quatre
colonnes « Régime » de la feuille (Thé / Pain, Sosoa / Brochette, Yaourt,
Purée) et une observation, en texte libre. Une ligne vide est refusée. Une
ligne se **corrige** (auteur de la correction conservé, ancienne valeur à
l'audit) mais ne se supprime jamais. Ajouter exige un séjour en cours ;
corriger reste possible tant que le passage est ouvert, comme la fiche Soins
(ADR-092).

L'impression reproduit la feuille papier (bandeau au logo, en-tête, grille,
coordonnées de la clinique), complétée de lignes vides pour rester utilisable
au lit du patient. Impression navigateur, jamais de PDF serveur (ADR-070).

## Reprise des demandes existantes

Les demandes transmises avant ce module attendaient dans une orientation que
personne ne prenait. La migration `admit_existing_hospitalization_requests`
leur applique la règle d'admission telle quelle : admis à l'heure de la
demande, par le médecin qui l'a faite — **seulement** pour les passages
encore ouverts ; rien n'est inventé pour un passage déjà clos.

## Permissions

```text
hospitalization.view     voir l'espace Hospitalisation et la fiche de régime
hospitalization.update   renseigner la chambre / le lit
hospital_diet.record     saisir et corriger la fiche de régime
medical_discharge.create prononcer la sortie (existante, MEDICINE)
```

Les trois nouvelles sont accordées à `MEDICINE` et `NURSE` par la migration
— un site en production ne rejoue plus `RolePermissionSeeder` (ADR-064).
L'espace n'encaisse rien et ne facture aucun repas (ADR-012, ADR-015).

## Hors périmètre

Gestion des lits et de leur occupation, visites de service, prescriptions
propres au séjour, facturation d'un forfait journalier, transfert entre
services : aucune règle n'est définie, rien n'est inventé.

## Amendement du 2026-09-18 — une destination qui a son module se transmet en un clic

Demande du propriétaire : puisque l'Hospitalisation et le registre des décès
existent, la consultation ne doit plus y faire remplir de formulaire. Le
médecin coche la destination et transmet ; le détail se complète dans le
module qui le porte.

```text
Hospitalisation   la carte ne garde que la priorité et « Transmettre la
                  demande ». Motif, diagnostic, résumé et traitement partent
                  repris du dossier (jamais inventés : un motif absent reste
                  vide, `hospitalization_requests.reason` devient nullable)
                  et se complètent sur la page du séjour, section « Demande
                  d'hospitalisation » (`PUT /hospitalisation/{séjour}/demande`,
                  `hospitalization.request` — le contenu médical reste au
                  médecin). Le service se complète avec la chambre / le lit.
Décès             voir l'amendement de l'ADR-107.
Chirurgie         inchangé, choix du propriétaire : l'intervention reste
                  choisie à la transmission — aucune demande « sans
                  intervention » n'arrive au bloc ; le reste se complète dans
                  le module Chirurgie, qui modifiait déjà l'intervention.
Référence         d'abord inchangé ; transmise en un clic depuis l'ADR-114,
                  qui lui donne son module Transferts.
```

La transmission reste un acte signé (ADR-106) : la confirmation relit ce qui
part, repris du dossier.

## Amendement du 2026-09-21 — la demande se corrige rubrique par rubrique

Demande du propriétaire : « ajouter la modification pour chaque petite section au
lieu de tout modifier ». Sur la page du séjour, chaque rubrique de la demande —
motif, diagnostic d'entrée, résumé clinique, traitement prévu, consignes — porte
son crayon, et la priorité le sien dans l'en-tête. Une seule rubrique s'ouvre à
la fois ; le bouton « Modifier » qui rouvrait tout le formulaire disparaît.

```text
serveur   PUT /hospitalisation/{séjour}/demande n'écrit que les champs envoyés :
          un champ absent reste tel quel (omettre n'efface pas, ADR-074), un champ
          envoyé vide s'efface ; la priorité ne s'efface jamais ; un envoi sans
          aucune rubrique est refusé
pourquoi  l'action réécrivait les six champs à chaque envoi : corriger une
          rubrique seule aurait effacé les autres, ou écrasé le motif d'un
          collègue avec une valeur périmée
écran     StayRequestCard ; une rubrique vide facultative ne s'affiche qu'à qui
          peut la compléter (« Non renseigné ») ; Échap annule, Ctrl+Entrée
          enregistre
```

Aucune permission nouvelle : `hospitalization.request`, comme avant.

---

# ADR-114 — Modules Transferts et Pédiatrie, demandes transmises en un clic

**Status:** ACCEPTED (2026-09-18 — exigence explicite du propriétaire, règles
tranchées question par question)

**Complète l'ADR-084** (demandes de conduite à tenir), **l'ADR-113**
(amendement « une destination qui a son module se transmet en un clic ») et
**amende l'ADR-084** sur un point : la Référence/Transfert s'arrêtait à la
demande, faute de règle sur le départ.

## Le trou constaté

Deux destinations de la conduite à tenir n'avaient aucun module. Une
orientation Transfert ou Pédiatrie restait donc `PENDING` indéfiniment : la
clôture de la consultation laissait le passage `IN_CARE`, et il n'atteignait
jamais « Sorties & règlements » (ADR-090). Personne n'avait non plus d'écran
pour retrouver les patients transférés.

## Les règles tranchées

```text
Transfert terminé    bouton « Transfert effectué » : le patient est alors
                     TRANSFERRED et rejoint « Sorties & règlements » ;
                     jusqu'au départ il reste en soins
accès Transferts     Médecine, Réception, Soins
Pédiatrie            module simple : file, prise en charge, sortie médicale ;
                     aucune fiche propre tant que la clinique n'en fournit pas
```

## Transferts (`/transferts`)

`transfers.view` ouvre l'espace, `transfers.manage` complète la demande et
constate le départ — accordées toutes deux à `MEDICINE`, `NURSE` et
`RECEPTION` par la migration `2026_09_24_090000` (ADR-064). Deux onglets :
« À transférer » et « Transférés ».

La demande part **en un clic** depuis la consultation : le motif, le
diagnostic, le résumé et les traitements partent repris du dossier ;
l'établissement destinataire se précise dans le module
(`medical_referrals.facility` et `.reason` deviennent nullables). Une valeur
absente reste absente — la lettre de référence écrit « À préciser », jamais un
établissement deviné.

« Transfert effectué » (`ConfirmTransferDepartureAction`) est un **constat**,
pas une décision : il exige l'établissement, date le départ (jamais dans le
futur), enregistre qui l'a constaté (`departed_at`, `departed_by`,
`departure_notes`), termine l'orientation Transfert, porte le statut médical
`TRANSFERRED` et fait passer le passage en `PENDING_SETTLEMENT` selon la
règle de l'ADR-054 — seulement si plus aucun service n'a le patient. Il ne
fabrique aucune `MedicalDischarge` et n'encaisse rien. La confirmation est
une fenêtre signée, non fermable au clic extérieur (ADR-106).

`MedicalRequestStatus` ne reçoit toujours aucun `DEPARTED` : le départ est un
fait daté sur sa propre colonne, et la demande reste une demande. Un
transfert effectué ou annulé ne se modifie plus. L'arrivée à destination et
l'accusé de réception restent hors périmètre : personne ici ne les observe.

## Pédiatrie (`/pediatrie`)

`pediatrics.view` et `pediatrics.manage`, accordées à `MEDICINE`. La file
liste les orientations `PEDIATRICS`. La prise en charge
(`AcceptPediatricsOrientationAction`) accepte l'orientation ; la sortie
(`DischargePediatricsOrientationAction`) est la même `MedicalDischarge` que la
consultation et l'hospitalisation — mêmes types, mêmes règles, diagnostic
final exigé — rattachée à la consultation qui a orienté l'enfant, sans la
rouvrir. Elle termine l'orientation et fait rejoindre « Sorties &
règlements » selon l'ADR-054. La page relit le motif, les diagnostics de la
consultation et les allergies : rien n'est ressaisi. Aucune fiche
pédiatrique n'est inventée.

## Formulaires de conduite à tenir simplifiés

```text
Chirurgie        l'intervention reste choisie (choix du propriétaire) ; le
                 « Diagnostic / hypothèse » est généré : ce que le médecin a
                 saisi, sinon les diagnostics actifs de la consultation
                 (`CreateSurgicalReferralAction::diagnosticFor`). Sans
                 diagnostic posé, la ligne reste absente — jamais inventée.
Maternité        un clic : le motif et l'indication partent repris du dossier
Pédiatrie        (`reason` nullable côté serveur)
Transfert        un clic : priorité seule, le reste dans Transferts
```

Chaque transmission reste un acte signé (ADR-106) : la confirmation relit ce
qui part.

## Une demande transmise ne se retransmet pas (2026-09-18)

Constat du propriétaire : après « Transmettre la demande », le bouton restait
actif. Le cliquer à nouveau ne corrigeait rien : il créait un **second**
enregistrement — un transfert en double dans la liste, une seconde demande au
bloc, une seconde tentative d'admission — sur la même orientation.

Une demande transmise vers une destination qui a son module (Chirurgie,
Hospitalisation, Transfert, Maternité, Pédiatrie) se **complète dans ce
module**. L'écran remplace donc le formulaire par « Demande transmise » et un
lien vers l'espace concerné (`module_url`, servi seulement avec la
permission de le voir) ; « Modifier » reste là pour changer de destination,
ce qui annule proprement la demande (ADR-084). Le serveur refuse de son côté
toute seconde transmission du même type
(`RecordConsultationOrientationAction::ensureNotAlreadySubmitted()`) —
l'interface n'est jamais la seule garde.

## La demande de transfert se rédige en texte riche (2026-09-18)

Demande du propriétaire. Motif, diagnostic, résumé clinique, traitements,
recommandations et observations passent du champ de texte à l'éditeur riche
de l'interrogatoire (`ClinicalRichTextEditor`) ; l'établissement reste une
ligne simple. Aucune colonne ne change — elles sont déjà `text`.

```text
écriture   HTML assaini par ClinicalRichTextSanitizer, même liste blanche que
           l'interrogatoire ; un éditeur vidé (« <p><br></p> ») redevient vide
longueur   comptée sur le texte lu, jamais sur les balises
lecture    MedicalReferralDocument sert la valeur brute pour l'éditeur et le
           HTML assaini pour la page Transferts et la lettre de référence ;
           la liste n'en montre que le texte
ancien     un texte sans balise (repris du dossier) garde ses lignes, à
           l'écran comme dans l'éditeur, sans ligne vide ajoutée
```

La lettre imprimée rend la mise en forme ; elle n'affiche jamais une balise
en clair.

## Hors périmètre

Transfert d'une sortie médicale de type `TRANSFER` prononcée sans demande :
elle garde son propre circuit (ADR-035) et n'apparaît pas dans le module.
Fiche pédiatrique, courbes de croissance, vaccinations : aucune règle
fournie, rien n'est inventé.

---

# ADR-115 — Menu latéral : rendu serveur fidèle, entrées mères par module

**Status:** ACCEPTED (2026-09-18 — signalement explicite du propriétaire)

## Le défaut constaté

« Parfois je ne peux pas cliquer, parfois deux liens sont actifs » : la
capture montrait des icônes décalées d'une ligne. Reproduit en navigateur
réel : après un rechargement, la ligne « Soins » portait l'icône de Patients
**et pointait vers `/patients`**.

La cause n'était pas le menu mais son rendu. L'application est rendue côté
serveur (Inertia SSR). Le serveur ne voit pas `localStorage` : il rendait
l'ordre recommandé, tandis que le navigateur lisait l'ordre personnel
(« Personnaliser l'ordre ») **pendant le rendu** et produisait une autre
liste. Vue ne répare pas ces écarts d'hydratation en production : une ligne
gardait le libellé d'un module et le lien d'un autre. Le commentaire du code
affirmait « this app has no SSR » — ce n'est plus vrai.

## La règle

Aucune préférence de navigateur n'est lue pendant le rendu : elle s'applique
une fois la page reprise par le navigateur (`onMounted`). La mise en page
étant persistante, cela n'a lieu qu'au chargement complet d'une page.
Corrigé au même titre : la vue liste/grille des Patients, des passages d'un
patient, de l'explorateur et des catalogues fournisseurs, qui présentaient le
même écart dès qu'un compte avait choisi la grille.

## Entrées mères par module

Les menus d'un **même module et d'une même famille d'adresses** sont réunis
sous une entrée mère à liste déroulante (`SIDEBAR_GROUPS`) :

```text
Médecine       File de consultation, Demandes d'examens, Protocoles  (/medicine)
Réception      Accueil & passages, Sorties & règlements              (/reception)
Référentiels   Désignations & tarifs, Catalogue des analyses
```

Rien n'est regroupé sur une ressemblance de nom. Un groupe n'est qu'une
présentation : chaque membre garde sa permission, son lien et ses règles ; un
membre interdit n'est pas listé, et un groupe réduit à un seul membre
s'affiche comme le lien simple de ce membre. La Vue d'ensemble garde une
tuile par module. Un ordre personnel enregistré avant le regroupement est
repris : chaque membre y vaut pour son groupe, à la place donnée.

## Une seule entrée, un seul enfant actif

Un enfant s'allumait par préfixe, indépendamment de ses voisins :
« File de consultation » (`/medicine`) s'allumait avec « Demandes
d'examens ». Les enfants concourent désormais comme les entrées de premier
niveau — la correspondance la plus profonde gagne, par segment d'adresse —
et seulement dans l'entrée qui possède la page. Le portail Super Admin, dont
les modules de site s'adressent par la requête (`?module=`), garde sa règle.

## Icônes

Une icône dit ce que fait le module : Soins → pansement, Transferts →
ambulance, Sorties & règlements → porte de sortie, Décès → registre,
Protocoles → livre validé, Désignations & tarifs → étiquettes. Deux modules
ne partagent jamais la même icône (test existant).

Aucune permission, route ni règle métier n'est modifiée.

---

# ADR-116 — Fiches papier de la clinique : dossier médical, journal de
traitement, fiche de sortie et poste de gardiennage

**Status:** ACCEPTED (2026-09-19 — modèles papier transmis par le
propriétaire : « Dossier médical », « Dossier médical – Traitement »,
« Fiche de sortie » (Réception/Caisse), « Fiche de sortie » (contrôle de
sortie), « Lettre de référence pour le transfert de patient »)

## Le constat

Le propriétaire a transmis cinq modèles papier de la clinique et a demandé
que le système en reprenne la logique. Vérification faite modèle par
modèle :

```text
Dossier médical – Traitement    aucun équivalent — construit ici
Dossier médical                 aucun équivalent — construit ici
Fiche de sortie (Caisse)        aucun équivalent — construit ici
Fiche de sortie (contrôle)      aucun équivalent — construit ici,
                                 le catalogue `guarding.*` existait sans
                                 le moindre écran (ADR-101 l'aurait
                                 signalé « pas encore vérifiée »)
Lettre de référence             déjà couverte : `MedicalReferralPrint.vue`,
                                 le module Transferts (ADR-114)
```

Le cinquième modèle ne demandait donc rien de nouveau ; les quatre premiers,
si.

## Dossier médical – Traitement : deux sources, une seule feuille

La feuille papier a trois colonnes — date et heure, description, visa du
personnel médical — et le propriétaire a confirmé que les deux sources
comptent : ce que l'application enregistre déjà (consultation, actes de
soins, demandes de soins, ordonnances, analyses, imagerie, admission et
sortie), et ce qu'un soignant ajoute à la main pour ce qu'elle n'enregistre
pas encore.

`App\Services\Medicine\TreatmentJournal` compose la chronologie à chaque
affichage à partir de ce qui existe déjà (jamais recopié, une correction de
la source se voit donc ici) et de `treatment_journal_entries`, la seule table
nouvelle du volet automatique/manuel. Chaque source n'apparaît qu'avec la
permission qui la garde déjà ailleurs — `consultations.view`, `care.view`,
`care_orders.view`, `prescriptions.view`, `laboratory_orders.view`,
`imaging_orders.view`, `hospitalization.view`, `medical_record.view` — même
principe de gardiennage section par section que la page « Détail du passage »
(ADR-054).

La ligne manuelle est **append-only** (ADR-032) : jamais de modification ni
de suppression, une erreur se corrige par une nouvelle ligne qui le dit. Le
visa est l'utilisateur connecté, jamais un nom saisi. `treatment_journal.view`
et `treatment_journal.record`, accordées à `MEDICINE` et `NURSE`, gouvernent
respectivement la lecture et l'écriture ; un passage clos par la sortie
administrative (ADR-090) refuse toute nouvelle ligne.

L'écran (`Medicine/TreatmentJournalSheet.vue`) est à la fois le formulaire de
saisie et la feuille imprimable : le panneau d'ajout n'apparaît jamais à
l'impression (`@media print { .tjs-panel { display: none } }`).

## Dossier médical : rien n'est ressaisi

Chaque case de la feuille a déjà son domicile dans le dossier : identité et
coordonnées (`patients`), constantes et tabac (fiche Soins, ADR-032),
allergies et antécédents familiaux (dossier permanent, ADR-074), motif et
dates d'hospitalisation (séjour, ADR-113), diagnostic (consultation,
ADR-035). `App\Support\Documents\MedicalRecordSheet` relit ces sources sans
jamais en dupliquer une, et les sections plus sensibles restent gouvernées
par leur propre permission (`vitals.view`, `patients.medical_history.view`) :
la feuille imprimée n'est pas un moyen de contourner ce que l'ADR-054 protège
déjà ailleurs. Une case que personne n'a remplie reste vide — jamais « Non »
ni « Normal » (ADR-077).

## Fiche de sortie (Réception/Caisse) : deux sorties que le papier confondait

Le papier ne porte qu'une seule date de sortie et un seul jeu de cases
(sortie normale / transfert / sur sa demande / décédé), signées par la
Caisse. Le dossier informatisé distingue depuis longtemps deux faits que ce
papier confondait — la sortie **médicale** (ADR-035, prononcée par le
médecin) et la sortie **administrative** (ADR-090, prononcée par la Caisse
selon le solde, CDC §33.3) — et la feuille imprimée montre les deux plutôt
que de forcer l'un dans l'unique case du papier. Les cases de type de sortie
sont complétées des types que le dossier connaît en plus du papier
(`MEDICAL_DECISION_REFUSAL`) : une divergence signalée à l'écran, jamais
masquée (ADR-020).

Imprimable seulement une fois la sortie administrative prononcée
(`episode.administrative_exit_type` non nul, sinon 404) : rien à signer
avant. La permission est la même que celle de la liste dont elle part,
`episodes.settlement.view` — imprimer ce que l'écran affiche déjà n'ouvre
aucun droit nouveau. Le reste dû et le numéro de créance suivent exactement
ce que l'écran « Sorties & règlements » affiche déjà sans gate supplémentaire
(`administrative_exit_balance`, figé sur l'épisode par l'ADR-090).

Elle porte un QR encodant la référence humaine du passage (`episode_number`),
comme le ticket Pharmacie encode sa référence de facture (ADR-050) — jamais
l'UUID brut, illisible à saisir à la main. C'est ce document que le poste de
gardiennage contrôle ensuite.

## Le poste de gardiennage : un catalogue de permissions enfin consommé

Vérification faite avant d'écrire une ligne : le catalogue `guarding.view`,
`guarding.entries.view/.create/.update/.close`, `guarding.reports.view/.export`
existait dans `PermissionSeeder` et dans le profil `SUPPORT/GUARD`
(ADR-033) depuis le début du projet, mais **aucune route ni aucun écran ne
les vérifiait**. La seule tuile de menu qui prétendait ouvrir « le
Gardiennage » (`guarding.view`) pointait en réalité vers le registre des
visiteurs (`/reception/visitors`), gardé par `visitors.view` — un droit
distinct que seul le profil GUARD complet reçoit en plus. Un compte n'ayant
que `guarding.*` voyait donc la tuile et recevait un 403 en cliquant ; un
compte Réception, qui détient bien `visitors.*` par défaut, n'avait
**aucune** entrée de menu pour y accéder, faute de `guarding.view`. Un défaut
symétrique à celui que l'ADR-100 avait déjà corrigé pour
`paraclinical_requests.view`.

Le contrôle de sortie que le propriétaire demande — la « Signature Service
Sécurité » du ticket de sortie — est le premier consommateur réel de ce
catalogue. `/guarding` (`GuardingController`, `guarding.view`) liste les
passages sortis administrativement (`DISCHARGED_PAID`/`DISCHARGED_DEBT`) sans
contrôle encore enregistré, et ceux déjà contrôlés. Le gardien peut
rechercher par numéro de dossier/de passage/nom, ou scanner le QR de la fiche
de sortie — même mécanisme que le contrôle des tickets Pharmacie à la Caisse
(ADR-050).

**Le gardien ne décide jamais une sortie ; il la constate.**
`RecordExitControlAction` (`guarding.entries.close`) refuse :

```text
aucune sortie administrative encore prononcée   → orienter vers la Réception
sortie « évadé »                                → déjà parti sans passer la
                                                   porte, rien à constater
un contrôle déjà enregistré pour ce passage      → un seul par passage,
                                                   jamais une correction
```

`episode_exit_controls` porte une contrainte d'unicité sur `episode_id` :
techniquement, pas seulement applicativement, un seul contrôle par passage.
Aucun motif n'est exigé (un constat, pas une décision — même principe que le
retrait d'un acte de soins, ADR-112) ; une observation facultative reste
possible. L'action est auditée sous `episode.exit_control`.

Deux nouvelles entrées de menu corrigent le défaut constaté :

```text
Gardiennage   → /guarding (guarding.view) — contrôle de sortie, seul
                consommateur réel du catalogue guarding.*
Visiteurs     → /reception/visitors (visitors.view) — le registre déjà
                existant (ADR-023), enfin atteignable par qui en a le droit
```

La page Gardiennage garde un lien vers le registre des visiteurs pour qui a
aussi ce droit (le profil GUARD complet l'a) ; les deux registres restent
fonctionnellement distincts (ADR-026 : le registre visiteurs ne crée ni
patient, ni épisode, ni facture — le contrôle de sortie ne fait, lui, que
constater un départ déjà décidé par la Caisse).

## Un chrome partagé, pour ne pas recopier une quatrième fois

`ADR-107`, `ADR-108` et `ADR-113` avaient chacune recopié le même bandeau au
logo et la même règle d'impression, sous un préfixe de classe différent
(`dc-*`, `rd-*`, `ds-*`). Une quatrième feuille recopiée de plus aurait été
la redondance que l'ADR-098 corrige ailleurs pour la même raison.
`resources/js/Components/Clinical/PaperSheet.vue` porte désormais le bandeau,
le titre encadré et le pied communs, en classes `ps-*` ; chaque feuille garde
son propre tableau de contenu et ses propres couleurs de section. Les trois
feuilles déjà publiées ne sont pas retouchées : un changement de chrome
partagé n'a rien à leur apporter qui justifierait d'y revenir (ADR-091).

## Ce qui n'est pas inventé

Aucune gestion des lits ou de leur occupation (déjà hors périmètre de
l'ADR-113). Aucun volet état civil sur la fiche de sortie ou le contrôle de
gardiennage (déjà hors périmètre de l'ADR-107 pour la même raison : le CDC
n'en dit rien). Le poste de gardiennage n'encaisse rien et ne prononce
aucune sortie : il constate un fait déjà décidé par la Caisse (ADR-012,
ADR-090).

## Permissions

```text
treatment_journal.view     MEDICINE, NURSE
treatment_journal.record   MEDICINE, NURSE
```

Aucune permission nouvelle pour le dossier médical (`patients.view`, comme la
page « Détail du passage ») ni pour la fiche de sortie Réception/Caisse
(`episodes.settlement.view`, comme la liste dont elle part). Le contrôle de
gardiennage réutilise le catalogue `guarding.*` déjà seedé et déjà recommandé
par le profil GUARD (ADR-033), désormais réellement vérifié par le code —
`PermissionUsageScanner` (ADR-101) le reclasse en conséquence.

Conformément à l'ADR-064, les deux permissions `treatment_journal.*` figurent
dans `RolePermissionSeeder::GRANTS` pour la création d'un nouveau site, mais
un site déjà en production doit les ajouter depuis le portail sans rejouer
ce seeder.


## Amendement du 2026-09-20 — la feuille imprimée ne contourne plus aucun droit

Question du propriétaire : quelles permissions gardent le dossier médical et celui d'un nouveau-né ? La
vérification a trouvé une fuite. L'ADR-116 affirmait que « les sections plus sensibles restent gouvernées
par leur propre permission » — c'était vrai des constantes et des antécédents, faux du reste :

```text
avant   un compte de Réception (patients.view seul) lisait sur la feuille imprimée le diagnostic,
        les traitements déclarés et le motif d'hospitalisation — que la page « Détail du passage »
        lui refuse (ADR-054)
après   diagnostic              -> diagnoses.view
        traitements actuels     -> medical_record.view
        hospitalisation         -> hospitalization.view
```

Une section refusée se nomme (« Non visible avec vos droits »), jamais une case vide qui se lirait « rien
à signaler » (ADR-077). La valeur est retirée de la charge utile, pas seulement masquée à l'écran : la
feuille est sérialisée entière au navigateur. La Réception garde l'identité et peut toujours ouvrir la
feuille — c'est ce qui lui permet d'imprimer la partie administrative.

**Aucune permission nouvelle**, et aucun droit d'impression : imprimer est le fait du navigateur
(ADR-070), donc un droit « imprimer » ne bloquerait rien — qui voit la page peut déjà faire Ctrl+P. Un
interrupteur qui ne commande rien serait pire que pas d'interrupteur (ADR-101). Les routes restent
`patients.view` pour le dossier d'un passage et d'un patient, `patients.view` **et** `maternity.view` pour
le dossier d'un bébé pas encore patient (ADR-146).

---

# ADR-117 — Le parcours d'un passage : une seule chronologie, de la Réception à la sortie

**Status:** ACCEPTED (2026-09-19 — signalement explicite du propriétaire, sur le
passage `A-26-0009-01`)

**Complète l'ADR-054** (la page « Détail du passage ») et **l'ADR-055** (ordre
de soins Médecine → Soins), sans modifier aucune de leurs règles. Aucune
permission nouvelle, aucune migration.

## Le constat

Le dossier d'un patient (sa frise) et le « Détail du passage » (son panneau
« Parcours clinique ») racontaient chacun le parcours à partir des seules
orientations, chacun à sa façon. Le passage `A-26-0009-01` affichait :

```text
Médecine › Laboratoire › Soins › Soins › Transfert
```

Trois défauts, tous nommés par le propriétaire :

```text
« Soins », « Soins »   deux demandes distinctes du médecin se lisaient comme
                       un doublon : rien ne disait qu'il y en avait deux, ni
                       d'où elles venaient, ni ce que chacune contenait
la suite des soins     retour en Médecine ou sortie directe — décidée par le
                       médecin à chaque demande (ADR-055), affichée nulle part
Réception, Pharmacie,  faisaient pourtant partie du parcours du patient et
Caisse                 n'y figuraient pas
```

Le premier n'était pas un bug de données : `CreateEpisodeOrientationAction`
ouvre une nouvelle orientation vers un service dès que la précédente est
terminée, si bien que deux demandes de soins successives donnent légitimement
deux orientations « Soins » (ADR-055, ADR-088). C'est l'affichage qui ne les
distinguait pas.

## Une seule chronologie, composée par Laravel

`App\Support\EpisodePathwayTimeline` compose le parcours une fois ; les deux
écrans l'affichent sans rien recalculer — même principe que
`CareRecordReadModel` (ADR-054) ou `ClinicalActBiller` (ADR-105) : une règle
recopiée à deux endroits finit par diverger, et les deux écrans racontaient
déjà deux histoires différentes.

```text
RECEPTION    l'arrivée, qui l'a enregistrée, ce que le patient venait faire
ORIENTATION  chaque orientation, d'où elle vient (« Médecine → Soins »)
PHARMACY     chaque demande de dispensation liée au passage
INVOICE      chaque facture non annulée, avec son reste à payer
PAYMENT      chaque encaissement, annulés compris (état « annulé »)
EXIT         la sortie prononcée par la Réception (CDC §33.3)
```

Chaque étape sort d'un enregistrement existant : rien n'est déduit ni
inventé. Le seul élément qui n'est pas un fait passé est l'étape « Sortie — à
prononcer par la Réception », servie quand le passage est ouvert, en
`PENDING_SETTLEMENT`, sans sortie prononcée : c'est la réponse à « pourquoi ce
passage est-il encore ouvert ? » lorsque plus aucun service n'a le patient
(ADR-090).

Les étapes sont classées par date, celle qui n'en a pas (la sortie à
prononcer) en dernier ; à horodatage égal, le sens du patient décide (la
Réception avant le service, le service avant la Caisse).

## Deux demandes, deux étapes numérotées

Quand un même service apparaît plusieurs fois, chaque étape porte son rang :
`sequence` / `sequence_total`, et une mention lisible — « 1re demande »,
« 2e demande » pour des soins demandés par le médecin, « 2e orientation » sinon.
La frise écrit « Soins 1 », « Soins 2 ». Un service visité une seule fois garde
son nom tout court. Les rangs s'écrivent « 1re », « 2e » : les exposants
Unicode ne sont pas rendus par toutes les polices.

## La suite décidée par le médecin

`care_orders.requires_return_to_medicine`, choisi à chaque demande (ADR-055),
devient une mention explicite sur l'étape Soins correspondante :

```text
RETURN_TO_MEDICINE   Retour en Médecine prévu après les soins
DIRECT_EXIT          Sortie directe après les soins (sans retour en Médecine)
```

C'est l'**intention déclarée**, jamais un fait accompli : la consultation reste
ouverte pendant les soins et aucune orientation Médecine n'est créée pour un
retour (ADR-088). « Sortie directe » ne dit pas que la sortie administrative
est prononcée — elle reste à la Réception (ADR-090) —, seulement que le patient
ne repasse pas chez le médecin. Le lien entre orientation et demande est
`care_orders.care_orientation_id` ; une orientation Soins sans demande derrière
elle (arrivée, urgence) n'affiche aucune suite : elle n'en a pas.

## Les actes demandés, lus sur ce qui a été fait

Sous chaque demande de soins, ses actes — « Réalisé », « Partiellement
réalisé », « À réaliser », « Non réalisé », « Retiré » — sont lus sur les
`care_record_procedures` réellement enregistrés par les Soins, jamais sur un
drapeau saisi (ADR-032). C'est ce qui répond à « pourquoi deux fois Soins ? » :
sur le cas signalé, la 1re demande portait une *Injection IM* réalisée, la 2e
la même injection, retirée par le médecin (ADR-112) après que les Soins avaient
pris le patient — d'où deux étapes, la seconde terminée sans acte.

## Chaque section garde la permission qui possède sa donnée

```text
Pharmacie        pharmacy.view — ou prescriptions.view pour l'ordonnance
                 (amendement du 2026-09-19, plus bas)
Factures         billing.view
Paiements        billing.view et payments.view
Actes demandés   care_orders.view
```

Sans le droit, l'étape n'est **pas servie** — jamais servie vide, qui se
lirait « rien n'a eu lieu » (ADR-054, ADR-102). Restent visibles à toute
personne qui voit le passage : la Réception, les orientations, qui a demandé
les soins et la suite décidée — de l'information de routage, de même nature que
l'orientation elle-même —, et le type de sortie, que l'écran patient exposait
déjà. Le reste dû à la sortie exige `billing.view`, et le motif de la sortie
n'est pas servi : il peut contenir un commentaire de la Réception qui n'a pas
sa place dans une frise.

## Un nombre constant de requêtes

`forEpisodes()` compose plusieurs passages en une requête par source
(orientations, demandes de soins, besoins, dispensations, factures, paiements,
noms) : le dossier d'un patient en liste souvent plusieurs, et le temps de
l'écran ne doit pas dépendre de son historique. Un test compare le nombre de
requêtes pour un et pour quatre passages. Les noms sont lus en un appel et
servis comme du texte : aucune relation chargée n'est sérialisée avec le
passage.

## Deux écrans, une source

```text
Détail du passage   EpisodePathwayList  — la liste détaillée, en frise verticale
Dossier patient     EpisodePathwayTrail — une pastille par étape, détail en infobulle
```

`episodeSteps()`, la dérivation locale de la frise depuis les seules
orientations, est supprimée ; `episode.orientations` du détail du passage est
remplacé par `episode.pathway`. L'apparence — icône et couleur par état — vit
dans `utilities/episodePathway.js`, partagée : un même état a la même couleur
aux deux endroits. Les deux composants sont écrits en shadcn-vue (ADR-099). Les
libellés d'état des orientations (« Orienté », « En attente »…) sont inchangés.

## Hors périmètre

Le contrôle de gardiennage (ADR-116), le séjour hospitalier (ADR-113) et le
registre des décès (ADR-107) ne sont pas ajoutés à la chronologie : le
propriétaire a nommé la Réception, la Caisse et la Pharmacie. À décider s'ils
doivent y figurer.

## Amendement du 2026-09-19 — l'ordonnance du prescripteur figure au parcours

Constat du propriétaire sur le passage `A-26-0001-01` : l'ordonnance était bien
partie à la Pharmacie (dispensation « délivrée »), mais le parcours ne la
montrait pas. La cause n'était pas une donnée manquante : la Pharmacie n'était
servie qu'avec `pharmacy.view`, et le médecin qui avait prescrit — qui voit son
ordonnance dans le même écran — ne détient pas ce droit.

```text
prescriptions.view   l'étape de ses ordonnances, avec ce que la Pharmacie en
                     a fait : Transmise, Délivrée, Délivrée partiellement, Annulée
pharmacy.view        toutes les dispensations, avec leur état complet
```

**Une ordonnance et la dispensation qu'elle a déclenchée ne font qu'une étape**
(« Pharmacie · Ordonnance », `Médecine → Pharmacie`), jamais deux : c'est un
seul passage à la Pharmacie. L'étape est datée de la prescription et porte la
date de délivrance.

**Le prescripteur lit l'issue, pas le règlement.** Sans `pharmacy.view`, l'état
est celui qui le concerne — transmise, délivrée, annulée. « En attente de
règlement » et « prête à délivrer » sont des états de la Pharmacie et de la
Caisse : ils ne sont pas servis à qui ne les détient pas (ADR-013, ADR-054).
Un achat au comptoir n'étant l'ordonnance de personne, il exige toujours
`pharmacy.view`.

**Une ordonnance qui n'est jamais allée à la Pharmacie le dit.** Une ordonnance
faite uniquement de lignes hors référentiel ne crée aucune demande de
dispensation (`CreateInternalDispenseRequestAction`, ADR-037) : annoncer
« Pharmacie » serait inventer un passage qui n'a pas eu lieu. L'étape s'appelle
alors « Ordonnance », état « Établie », avec la mention « Hors référentiel :
aucune demande de dispensation à la Pharmacie. » Une ordonnance annulée l'est
à l'écran (« Ordonnance annulée ») quelle que soit sa dispensation.

**Le ticket de la Pharmacie se reconnaît à la Caisse.** La Caisse encaisse le
ticket sans détenir le droit de voir la Pharmacie ; la facture — et son
encaissement — portent donc la mention « Ticket Pharmacie », lue sur la
dispensation liée à la facture. Sans elle, une facture réglée après la sortie
ne se rattachait à rien. Cette mention n'ouvre pas l'étape de la Pharmacie à
qui n'en a pas le droit.

Aucune permission nouvelle, aucune migration.


---

# ADR-118 — Tous les journaux de traitement d'un patient en un seul document, et une file Soins qui distingue passages et demandes

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire)

**Complète l'ADR-116** (journal de traitement) et **l'ADR-117** (parcours d'un
passage), sans modifier aucune de leurs règles. Aucune permission nouvelle,
aucune migration.

## Le constat

Le journal « Dossier médical – Traitement » (ADR-116) se lisait un passage à la
fois. Un patient qui revient a autant de journaux que de passages, et rien ne
permettait de les lire ensemble ni de les remettre en un seul fichier.

Dans la file Soins, la fenêtre d'un patient annonçait « 2 passages » pour un
seul passage, `A-26-0009-01`, au numéro écrit deux fois : la file groupait par
patient et appelait « passage » ce qui était une **orientation**. Deux demandes
de soins du même passage (ADR-055) sont deux orientations — jamais deux
passages (ADR-117).

## Un document, pas un nouveau calcul

`/patients/{patient}/journaux-de-traitement`
(`patients.treatment-journals.show`, `PatientTreatmentJournalController`)
exige `patients.view` **et** `treatment_journal.view`. Elle sert, pour chaque
passage du patient, ce que `TreatmentJournal::rows()` sert déjà à la feuille de
ce passage : mêmes sources, mêmes permissions par source (ADR-054, ADR-116). La
page réunit, elle n'élargit rien — un test compare, ligne à ligne, le document
réuni et la feuille d'un passage pour un compte qui n'a que
`treatment_journal.view`.

Les passages sont classés du plus ancien au plus récent (date de début, puis
identifiant), les lignes de chaque journal du plus ancien au plus récent. La
page est en **lecture seule** : une ligne manuelle s'ajoute depuis le journal du
passage, jamais depuis le document réuni.

## La forme du document

```text
Couverture   identité du patient + tableau des passages, avec le nombre de
             lignes de chacun
Feuilles     une par passage qui porte au moins une ligne, chacune sur sa page
```

Un passage sans ligne figure dans la liste (« Aucune ligne ») mais n'occupe pas
une page blanche. La grille « date · description · visa » est un composant
partagé (`TreatmentJournalTable`) : la feuille d'un passage et le document réuni
ne dessinent jamais la même feuille de deux façons. `PaperSheet` reçoit
`showActions` pour que les feuilles suivantes n'aient pas chacune leurs boutons.

## Le PDF est celui du navigateur

Aucun PDF n'est généré côté serveur (ADR-070) : le texte reste sélectionnable
et aucune bibliothèque de plus n'est à déployer sur trois sites. « Télécharger le
PDF » ouvre la fenêtre d'impression, où l'on choisit « Enregistrer au format
PDF » : **une seule impression donne un seul fichier**, avec un saut de page
entre chaque passage.

Le seul contrôle que l'on ait sur le fichier est son nom, que les navigateurs
tirent du titre de la page. `printAsPdf()` le pose le temps de l'impression —
« Journaux de traitement - A-26-0009 - RASOAMIFIDY Malala Odette » — puis le
rend à `afterprint` (`utilities/pdfDownload.js`, les caractères qu'un nom de
fichier ne peut pas porter sont remplacés).

## Où se trouve le bouton

```text
Dossier patient   en-tête « Journaux de traitement » ; onglet Passages
                  « Tous les journaux (PDF) » et « Journal » sur chaque passage
File Soins        pied de la fenêtre d'un patient
```

Il n'apparaît qu'avec `treatment_journal.view` et au moins un passage à réunir :
la Réception, qui ne détient pas ce droit, ne le voit pas.

## La file Soins compte les passages, et nomme les demandes

`App\Support\CareRequestSummary` porte, une fois, ce que le parcours du passage
(ADR-117) et la file Soins disent d'une demande de soins : qui l'a faite, la
suite décidée (« Retour en Médecine prévu » / « Sortie directe après les soins »)
et ses actes, dont l'état est lu sur les `care_record_procedures` réellement
enregistrés. Les deux écrans l'appellent — une règle recopiée finit par
diverger, et le parcours l'avait déjà écrite pour son compte.

La file expose `care_request` sur chaque orientation. Les actes exigent
`care_orders.view` ; le routage — qui a demandé, quelle suite — reste servi à
qui voit la file, comme l'orientation elle-même (ADR-117). Une orientation sans
demande de médecin derrière elle (arrivée, urgence) n'en porte pas et n'invente
aucune suite.

**Plusieurs demandes peuvent partager une orientation Soins encore active** :
`CreateEpisodeOrientationAction` réutilise l'orientation ouverte
(`active_key`). Elles sont donc regroupées par orientation, jamais indexées
une par une — sinon la première disparaîtrait derrière la seconde.

La fenêtre écrit « 1 passage · 2 demandes de soins », chaque demande dans son
rang (« Demande 1 », « Demande 2 »), et la ligne de la file « Voir les 2
demandes ».

## Dossier patient et journaux passés à shadcn-vue (ADR-099)

`Patients/Show.vue` quitte la police d'icônes et la palette DashWind : pastilles
`Badge` et icônes lucide pour les statuts de parcours, de passage et de facture,
`FormField` pour les fenêtres d'encaissement et d'annulation, `Checkbox` pour le
choix des prestations d'une facture. Le comportement, les routes et les
permissions ne changent pas.

Dans l'en-tête, le badge de situation dit déjà « En attente de règlement » : le
parcours n'est plus répété à côté du badge lorsqu'il dit la même chose. Le lien
devient « Pourquoi ce statut ? », vers le détail du passage.

## Hors périmètre

- Un PDF **généré par le serveur** — pour un envoi par courriel ou un
  archivage sans interaction — exigerait une bibliothèque de rendu ; à décider
  si le besoin apparaît.
- Choisir les passages à inclure dans le document : il réunit tout ce que le
  patient a, ce qui est ce qui a été demandé.

---

# ADR-119 — Répertoire des patients : où chacun a encore besoin d'aller

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire)

**Complète l'ADR-117** (le parcours d'un passage) et **l'ADR-118** (la file Soins
compte les passages), sans modifier aucune de leurs règles. Aucune permission
nouvelle, aucune migration.

## Le constat

Le répertoire (`/patients`) disait qu'un patient avait « un passage en cours »
sans dire **où**. Pour savoir s'il attendait le médecin, les soins ou la
pharmacie, la Réception devait ouvrir chaque dossier. Les quatre cartes du haut
(total, passages ouverts, créés ce mois, urgences) ne répondaient pas non plus à
la question qu'elle se pose vingt fois par jour : « qui attend quoi ? ».

Demande du propriétaire : filtrer et compter les patients selon le service dont
ils ont encore besoin — Médecine seulement, Soins seulement, Pharmacie seulement,
tous, et Soins + Médecine + Pharmacie.

## Deux choix d'interprétation, signalés

**« Seulement » est une combinaison exacte.** Chaque patient tombe dans une seule
case : celle de l'ensemble exact des services qu'il attend. « Médecine seulement »
exclut celui qui attend aussi la pharmacie. La somme des cases est donc le nombre
de patients, et « Tous » n'est pas une case de plus mais leur total. Trois
services donnent sept combinaisons non vides, plus « aucun » :

```text
Médecine · Soins · Pharmacie              seul, chacun            (cartes du haut)
Médecine + Soins + Pharmacie              les trois ensemble      (cartes du haut)
Soins + Médecine, Médecine + Pharmacie,
Soins + Pharmacie                         les paires              (pastilles)
Aucun de ces services                     rien à attendre ici     (pastille)
```

La lecture « au moins » (un patient compté dans plusieurs cases) n'a pas été
retenue : elle ferait mentir « seulement », et la somme des cases ne serait plus
le nombre de patients.

**Le besoin de Pharmacie se sert avec `patients.view`.** Le répertoire s'ouvre
avec `patients.view` (Réception, Médecine et Soins par défaut) ; `pharmacy.view`
n'est détenu par défaut que par la Pharmacie. Le besoin de Pharmacie est
pourtant servi avec `patients.view` : c'est une information de **routage** — le
patient doit y passer —, de même nature que l'orientation vers Médecine ou
Soins, que l'ADR-117 sert déjà à toute personne qui voit le passage. Il ne porte
ni médicament, ni quantité, ni montant, ni état de règlement (ADR-013). Sans lui,
la Réception — qui envoie le patient à la pharmacie — ne pourrait pas savoir qu'il
y attend, ce qui est précisément la question posée.

La garder derrière `pharmacy.view` ferait diverger les compteurs d'un compte à
l'autre ; c'est possible si le propriétaire le préfère.

## Ce que « attend ce service » veut dire

```text
MEDICINE  une orientation Médecine PENDING ou IN_PROGRESS, sur un passage OPEN
CARE      une orientation Soins PENDING ou IN_PROGRESS, sur un passage OPEN
PHARMACY  une demande de dispensation liée à un patient, pas encore terminée :
          AWAITING_INVOICE, AWAITING_PAYMENT, READY ou PARTIALLY_DISPENSED
```

Ce sont les définitions des files de ces services. Celle de la Pharmacie vivait
dans `PharmacyPrescriptionQueueService::activeDispenseStatuses()` : elle devient
`PharmacyDispenseStatus::openValues()`, que la file et le répertoire appellent
tous les deux — deux copies de la liste auraient fini par compter deux réalités.

Ne comptent pas : une orientation terminée ou annulée ; l'orientation d'un
passage clos par la sortie administrative (ADR-090) ; une dispensation terminée
ou annulée ; une dispensation sans patient (une vente anonyme antérieure à
l'ADR-104 — sans dossier, il n'y a personne à qui rattacher un besoin).

## Où en est le besoin

```text
Médecine / Soins   en attente (ambre)      l'orientation attend qu'on la prenne
                   pris en charge (vert)   un professionnel l'a acceptée
Pharmacie          neutre ; seule « délivrance partielle » se dit
```

La Pharmacie ne dit jamais « à régler » ni « prête à délivrer » : ce sont des
états financiers, que la distinction de l'ADR-117 entre l'issue d'une ordonnance
et son règlement garde à la Pharmacie et à la Caisse. Le répertoire dit que le
patient doit y passer, jamais où en est son règlement.

Un patient peut avoir plusieurs demandes du même service (deux passages
ouverts, deux ordonnances) : la ligne garde la plus avancée — « en cours »
l'emporte sur « en attente », et à état égal la plus ancienne, celle qui attend
depuis le plus longtemps. Ce que la pastille n'a pas la place de porter — depuis
quand, sur quel passage — est dans son infobulle. « Depuis » ne s'écrit que pour
une heure passée : un décalage d'horloge ne fabrique pas un « depuis » absurde.

## Les compteurs sont des facettes

Chaque compte est ce que donnerait un clic sur sa case, les autres filtres du
répertoire (recherche, type de patient, urgence) inchangés — le filtre du besoin
lui-même étant exclu du calcul. Une case qui annonce 2 et ouvre une liste vide
serait pire que pas de compteur.

Les comptes viennent toujours du serveur. La liste est paginée : un compte tiré
de la page affichée mentirait dès la deuxième page.

La carte est le filtre, comme dans les files de Médecine, de Soins et du
Laboratoire (`QueueCounters`) : cliquer une carte déjà active la referme,
« Tous » efface le filtre.

## Le filtre voyage dans l'adresse

`/patients?need=…` :

```text
MEDICINE | CARE | PHARMACY   un seul service, exactement
MEDICINE,CARE               une combinaison, toujours dans l'ordre canonique
NONE                        aucun de ces trois services
ALL, ou absent              pas de filtre
```

Une valeur inconnue ne filtre rien : un signet périmé montre tous les patients
plutôt qu'une liste vide qui se lirait « personne n'attend » — même principe que
l'ADR-102, où un vide n'est jamais présenté comme un zéro. La clé canonique est
renvoyée telle quelle (`filters.need`) et la pagination la conserve.

L'ordre canonique de l'adresse est Médecine, Soins, Pharmacie. Les libellés se
lisent, eux, dans l'ordre du parcours du patient — les soins précèdent le
médecin, qui précède la pharmacie : « Soins + Médecine ».

## Deux requêtes, pas une par ligne

`App\Services\Patient\PatientServiceNeeds` lit les orientations (jointes à leur
passage) et les dispensations ouvertes. Ces deux requêtes ne portent que sur les
patients qui ont un besoin — ceux qui sont dans la clinique à cet instant, pas
tout le répertoire —, puis les combinaisons se calculent en PHP. Le filtre devient
un `whereIn` sur ces patients, ou un `whereNotIn` pour « aucun ».

Trois sous-requêtes corrélées par ligne de `patients` auraient fait dépendre le
temps de l'écran de la taille de l'historique plutôt que de la salle d'attente.
Si le nombre de patients qui attendent atteignait un jour des milliers, ce calcul
serait à porter en SQL : c'est signalé ici plutôt que caché.

## L'écran

```text
Cartes                 Tous les patients · Médecine seulement · Soins seulement ·
                       Pharmacie seulement · Les 3 services
« Autres situations »  les paires, seulement si quelqu'un s'y trouve (ou si c'est
                       celle qu'on filtre, pour pouvoir la quitter) ; « Aucun de
                       ces services » toujours
Ligne                  « Situation actuelle » : la présence du patient et une
                       pastille par service attendu ; « — » quand il n'attend rien
```

Chaque carte porte une infobulle qui dit ce qu'elle exclut : « seulement » n'est
pas « au moins ».

**Ce qui quitte l'écran.** Les quatre cartes de chiffres : le résumé passe en une
ligne dans l'en-tête du tableau (« 12 dossiers · 10 avec un passage ouvert · 12
créés ce mois »), et les urgences en cours deviennent un bouton rouge dans
l'en-tête, qui applique le filtre d'urgence. Le contrat `summary` du serveur ne
change pas. La colonne « Catégorie » : « Standard » ne disait rien, l'ADR-051 ayant
fait du type de patient une donnée héritée qui ne pilote plus le tarif — seul un
type qui change la prise en charge (Mutuelle, Personnel) reste affiché sur la
ligne.

**Ce qui s'ajoute.** La recherche se lance d'elle-même après une courte pause
(Entrée la lance tout de suite) et les compteurs la suivent. La vue grille
affiche aussi le besoin. Le motif d'archivage (ADR-009) passe par `FormField` et
`Textarea` ; il reste obligatoire.

Le conteneur de défilement du tableau est `relative` : `sr-only` est en position
absolue, et sans ancêtre positionné il échappait au défilement du tableau et
élargissait la page entière (constaté à 1 280 px).

## Ce qui ne change pas

Le répertoire reste protégé par `patients.view`. L'archivage et la restauration,
l'état de présence et le contrat `summary` sont inchangés. La file Pharmacie compte
les mêmes demandes qu'avant : sa liste de statuts a été déplacée, pas modifiée.

## Amendement du 2026-09-19 (ADR-120)

Les pastilles « Autres situations » (paires et « Aucun de ces services ») sont
retirées de l'écran à la demande du propriétaire ; `?need=` continue d'accepter
toutes les combinaisons. Les cartes du haut restent.

## Hors périmètre

- Laboratoire, Imagerie, Maternité, Chirurgie, Hospitalisation, Transferts,
  Pédiatrie et Caisse ne sont pas des cases : la demande nomme trois services. Les
  combinaisons exactes ne survivent pas à un quatrième — trois services donnent
  sept cases, quatre en donnent quinze. Un service de plus demandera une autre
  forme (une sélection de services, une lecture « au moins »), pas une case de
  plus.
- Un seuil d'attente ou une alerte « attend depuis trop longtemps » : aucune règle
  ne le définit ; l'infobulle dit seulement depuis quand.

---

# ADR-120 — Répertoire des patients : des onglets qui classent par état

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire)

**Amende l'ADR-119** sur la présentation seulement : les pastilles « Autres
situations » (paires de services et « Aucun de ces services ») quittent l'écran.
Les cinq cartes de besoin, `?need=` et leurs règles sont inchangés. Aucune
permission nouvelle, aucune migration.

## La demande

Une première version de ce jour ajoutait des filtres d'ancienneté (nouveaux /
anciens) et de besoin (en cours / récent). Le propriétaire les retire : il veut
des **onglets** qui classent chaque patient dans l'état qu'il lit déjà sur sa
ligne. Les filtres `visit` et `activity` n'existent donc plus.

## Les onglets

```text
Tous                     tous les patients
Besoin en cours          un passage est ouvert et pas seulement en attente de
                         règlement ; une urgence ouverte y est toujours
En attente de règlement  le seul passage ouvert n'attend que la sortie de la
                         Réception (ADR-090)
Aucun passage ouvert     aucun passage ouvert
```

Ce sont exactement les états de `presenceState` (`utilities/episodePresence.js`),
le badge de la ligne : l'onglet et le badge ne peuvent pas se contredire. Ils
s'excluent : un patient est dans un seul onglet. Un patient encore en soins sur
un passage et en attente de règlement sur un autre est « en cours », comme sur
sa ligne. « Besoin en cours » nomme l'état : il ne dit pas *où* — c'est le rôle
des cartes de besoin (ADR-119).

## Mécanique

`?status=open|settlement|none` ; une valeur inconnue ne filtre rien, comme
`need`. L'onglet se combine avec la recherche, le type, l'urgence et les cartes
de besoin. Chaque compte est ce que donnerait un clic sur son onglet, tous les
**autres** filtres restant appliqués et le sien seul levé
(`segments.status`) ; les cartes de besoin suivent l'onglet choisi. Les comptes
viennent du serveur, jamais de la page paginée. L'onglet actif apparaît en puce
et « Tout effacer » le retire.

## Ce qui ne change pas

`patients.view` reste la seule garde. Le répertoire lit, il ne décide rien.

---

# ADR-121 — Prendre un patient qui n'est pas le premier : les Soins demandent aussi

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire)

**Complète l'ADR-085** (un seul soignant par prise en charge), sans modifier
aucune de ses règles. Aucune permission nouvelle, aucune migration, aucune règle
serveur.

## Le constat

La file Médecine rappelle déjà, avant de prendre le n° 2, que le n° 1 attend
encore. La file Soins laissait prendre n'importe qui d'un clic, sans un mot :
« Prendre en charge » postait directement.

## La règle

Prendre un patient dont d'autres attendent encore devant lui ouvre une
confirmation : « Un patient attend avant celui-ci » — ou « Une urgence attend
avant ce patient » si l'un d'eux est une urgence —, avec les patients devant et
depuis combien de temps ils attendent. « Prendre celui-ci quand même » confirme,
« Annuler » ne prend personne.

**On demande, on n'interdit pas.** Passer le n° 2 est parfois la bonne décision :
le premier est parti, son dossier est incomplet, une priorité clinique. Aucune
règle du CDC n'impose l'ordre d'arrivée, donc le serveur ne bloque rien ; le
garde-fou évite l'oubli, pas la décision (comme en Médecine).

- Ne comptent « devant » que les patients encore **en attente** : un patient déjà
  pris en charge ne bloque personne.
- Un autre passage du **même patient** n'est pas « un autre patient avant ».
- Sur une page autre que la première, des patients arrivés avant ne sont pas
  affichés : la fenêtre le dit plutôt que de laisser croire qu'il n'y a personne.
- Le premier de la file se prend sans aucune question.

## Une seule règle pour les deux files

La règle vit dans `useQueueSkipGuard` et la fenêtre dans `QueueSkipConfirm` ;
Médecine et Soins les emploient tous deux. La copie qui vivait dans la page
Médecine est retirée : deux copies auraient fini par se contredire. Les trois
boutons « Prendre en charge » des Soins (ligne du tableau, carte mobile, fenêtre
d'un patient à plusieurs passages) passent par le garde-fou ; un test interdit
qu'un seul poste directement.

---

# ADR-122 — Remettre en file un patient pris en charge par erreur (Soins)

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire)

**Complète l'ADR-085** (un seul soignant par prise en charge) et l'ADR-121
(confirmation avant de sauter un patient). Aucune permission nouvelle
(`care.update`), aucune migration.

## Le constat

Un clic au mauvais endroit prenait le patient en charge, et rien ne permettait de
revenir en arrière : le soignant devait garder un patient qu'il n'avait pas
commencé à soigner, ou le terminer sans acte.

## La règle

« Remettre en file » (`ReleaseCareOrientationAction`) fait repasser l'orientation
de « pris en charge » à « en attente » : le patient **retrouve sa place**. La
place vient de `oriented_at`, que la prise en charge n'a jamais modifié ; rien
n'est recalculé. Sur un patient qui était le n° 1, il redevient le n° 1.

Conditions, toutes revérifiées côté serveur sur la ligne verrouillée :

```text
orientation Soins encore IN_PROGRESS
demandée par le soignant qui a pris le patient (accepted_by)
passage OPEN
aucun travail enregistré depuis la prise en charge
```

« Aucun travail » : ni fiche de soins modifiée, ni acte réalisé, ni matériel
déclaré, ni acte marqué « non réalisé » depuis `accepted_at`. Dès qu'un soin
existe, le patient a réellement été soigné et ne se « rend » plus : la suite
passe par les soins terminés (ADR-092). Une saisie non enregistrée n'est qu'un
brouillon jetable (ADR-073) : elle est écartée avec la remise en file.

Le passage repasse « orienté » si la prise en charge l'avait fait passer « en
soins » et que plus aucune orientation n'est en cours sur lui.

## Où

Un bouton « Remettre en file » sur la ligne de la file (tableau et carte mobile)
et sur la fiche, seulement pour le soignant qui a pris le patient ; sur la fiche,
seulement tant qu'aucune fiche n'a été enregistrée. Le serveur décide : un refus
s'affiche en clair. L'action est auditée (`care.orientation.release`, ancienne et
nouvelle valeur).

## Hors périmètre

La Médecine n'a pas d'équivalent : prendre un patient y ouvre une consultation,
qu'il faudrait aussi défaire. À décider séparément.

---

# ADR-123 — La transmission à Médecine rejoint l'étape « Terminer » de la fiche Soins

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire)

**Amende l'ADR-032** sur la présentation seulement : la fiche de soins passe de
six à cinq étapes. Les données, les règles et le serveur ne changent pas.
Aucune permission nouvelle, aucune migration.

## Le constat

L'étape 5 « Transmission » n'était qu'un écran de deux zones de texte
**facultatives** — « Information médicale complémentaire » (ce que le patient a
dit de vive voix) et « Observations / transmission infirmière ». Rien ne l'exigeait
(`nullable`, 3 000 caractères), « Suivant » la passait sans rien saisir, et elle
n'apparaissait que si le patient continue vers Médecine (consultation générale,
besoin à préciser, urgence). Un écran entier pour deux champs vides dans la
plupart des cas.

Ces deux champs restent pourtant utiles : les constantes et les actes arrivent au
médecin par des données structurées, mais c'est le **seul** endroit où le soignant
écrit l'état du patient, sa réaction, la surveillance, les points de vigilance.
Le médecin le lit dans son « Contexte clinique », le détail du passage, le dossier
médical imprimé et le résumé Chirurgie.

## La règle

L'étape disparaît ; ses champs passent **dans « Terminer »**, là où l'infirmier
transmet le patient, dans un bloc « Transmission à Médecine » (facultatif) placé
avant le récapitulatif :

```text
Contexte → Constantes → Allergies → Actes et matériel → Terminer
                                                        └ Transmission à Médecine
```

- Le bloc n'apparaît que si le parcours prévoit une transmission
  (`care_transmission_expected`) : un parcours qui se termine aux Soins n'en
  affiche aucun, comme avant. Le serveur continue de refuser ces champs dans ce cas.
- Le diagnostic déjà posé par un médecin y reste montré en lecture seule.
- Une erreur de validation sur ces champs ramène à « Terminer » (et non plus à
  une étape qui n'existe plus).
- La ligne « Transmission » du récapitulatif disparaît : le champ est modifiable
  juste au-dessus, la répéter en lecture seule ne servirait à rien.

## Ce qui ne change pas

Les noms des champs (`diagnostic_note`, `transmission_reason`), leur stockage, leur
lecture par Médecine, Chirurgie et le dossier imprimé, la restauration du brouillon
(ADR-073) et la règle « au moins un acte réalisé pour un parcours `CARE_ONLY` »
(ADR-032).

## Amendement du même jour — colonne de droite et texte riche

Le bloc ne s'empile plus au-dessus du récapitulatif : « Terminer » est en **deux
colonnes**, le récapitulatif (actes, matériel, allergies, constantes) à gauche et
la **transmission à Médecine à droite**, suivie des points de vigilance. L'étape
et son bouton d'enregistrement tiennent ainsi dans un seul écran, sans défilement
de la page.

Les deux champs deviennent du **texte riche** (`ClinicalRichTextEditor`, gras,
italique, souligné, surlignage, listes), dans des `FormField` shadcn avec le
diagnostic transmis en lecture seule dans une pastille `Badge`.

- **Assaini côté serveur** : `SaveCareRecordAction` passe les deux notes par
  `ClinicalRichTextSanitizer` (même liste blanche que l'interrogatoire) ; un
  éditeur vidé (`<p><br></p>`) redevient une absence, jamais une chaîne de
  balises. La limite serveur passe à 12 000 caractères de HTML ; l'éditeur borne
  toujours le texte lu à 3 000.
- **Lu en HTML assaini partout** : `CareRecord::transmission_reason_html` et
  `diagnostic_note_html` (accesseurs, `displayHtml()`) sont servis à côté de la
  valeur brute, qui reste celle de l'éditeur. Un ancien texte sans balise garde
  ses retours à la ligne. Consommateurs : contexte clinique Médecine, détail du
  passage, dossier patient, dossier médical imprimé et résumé Chirurgie/Anesthésie.

## Amendement du même jour — barre de séparation redimensionnable

Les deux colonnes sont séparées par le `ResizableSplit` déjà employé pour
l'examen clinique et le socle des rôles : on glisse la barre, on la manie au
clavier (flèches, Début/Fin), un double-appui remet le rapport par défaut. Par
défaut 45 % / 55 %, borné à 30–65 % ; il est conservé sur le poste
(`rivo:care:finish-split`) et jamais envoyé au serveur. Sous 54 rem de large, les
panneaux s'empilent et la barre disparaît.

Quand il n'y a rien à mettre à droite (pas de transmission, aucun point de
vigilance), le composant reçoit `single` : le premier panneau prend toute la
largeur, sans barre ni panneau vide.

## Amendement du même jour — points de vigilance épinglés à gauche

Les points de vigilance (constantes hors bornes, allergie à vérifier, matériel
au-delà du stock, actes demandés en attente) quittent la colonne de droite, qui
n'est plus que la transmission. Ils sont **épinglés en bas du panneau de gauche**
(`mt-auto`), sous le récapitulatif, et **repliés en pastilles d'une ligne** — le
titre avant « — » : « TA sévèrement élevée », « FC très basse »…, rouge ou ambre
selon la gravité, le texte complet au survol. « Voir le détail » déplie les
explications complètes.

Aucun repère n'est masqué : seuls leur texte et leur hauteur se réduisent. Ils
restent une aide au dépistage et ne bloquent rien (ADR-039 à ADR-041).

La colonne de droite n'existe donc que si le parcours prévoit une transmission ;
sinon le récapitulatif garde toute la largeur, points de vigilance compris.

---

# ADR-124 — La file Soins ne montre que ce qui reste à faire : deux onglets

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire) ; **remplacée par
l'ADR-177** (2026-09-23) : la page Soins lit le tableau partagé des passages et ses six vues.

**Amende l'ADR-118** (la file Soins) sur ce que la page affiche. Aucune
permission nouvelle, aucune migration.

## Le constat

La page Soins mélangeait ce qui reste à faire et un historique : sous
« Orientés », tous les patients dont les Soins étaient terminés, y compris ceux que
le médecin avait déjà accueillis depuis des jours (76 h d'attente affichée pour un
patient en consultation). Le module Patients porte déjà cette liste, avec l'état de
chacun.

## La règle

La file Soins n'a que **deux onglets**, chacun avec son compteur :

```text
À prendre aux Soins                  orientation Soins en attente ou en cours
Orientés · en attente du médecin     Soins terminés, et Médecine n'a pas encore
                                     accueilli le patient (orientation Médecine
                                     en attente)
```

Dès que le médecin accueille le patient, ou que les Soins se terminent sans
médecin (parcours `CARE_ONLY`), le patient **quitte cette page** : il se retrouve
dans le module Patients (ADR-119). Un patient renvoyé au médecin (nouvelle
orientation Médecine en attente) redevient « en attente du médecin » ; un patient
dont l'orientation Médecine a été annulée n'attend personne.

Le filtre `?filter=` n'accepte plus que `active` et `waiting_doctor` ; toute autre
valeur — l'ancien `oriented`, par exemple — retombe sur la file active, comme une
valeur inconnue.

## Lecture d'une ligne « en attente du médecin »

Le statut dit ce que le patient attend — « En attente du médecin » — et l'attente
se compte **depuis que Médecine a été sollicitée**, pas depuis la fin des Soins
(`doctor.since`, une requête pour toute la page). Du plus ancien au plus récent :
celui qui attend depuis le plus longtemps est en tête, comme dans la file active.
C'est de l'information de routage, de même nature que l'orientation elle-même
(ADR-117) : aucune permission de plus.

## Ce qui ne change pas

Les cartes de priorité (Urgences, Priorité normale) restent des filtres et suivent
l'onglet choisi ; le garde-fou « un patient attend avant celui-ci » (ADR-121) et la
remise en file (ADR-122) ne concernent que la file active.

---

# ADR-125 — Les constantes se lisent selon l'âge ; une valeur critique ou improbable se dit

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire) — **chiffres à
faire valider par un médecin de la clinique**

**Étend les ADR-038 à ADR-041**, qui ne fixaient que des seuils d'adulte. Aucune
permission nouvelle, aucune migration.

## Le constat

Les alertes de constantes ignoraient l'âge presque partout. Sur un enfant de 4 ans,
170/120 s'affichait « TA très élevée » — un niveau d'étape 2 d'adulte — alors que
c'est critique à cet âge ; 78 bpm passait sans un mot alors que la plage usuelle à
4 ans est 80–120 ; aucune fréquence trop rapide n'était jamais signalée, à aucun
âge. Et une valeur absurde (un poids de 45 kg à 4 ans) n'alertait personne.

## Une seule table, servie à l'écran

`App\Support\VitalSignAgeReference` porte tous les chiffres par âge. Les
évaluations serveur (`HeartRateAssessment`, `BloodPressureAssessment`,
`TemperatureAssessment`) la lisent ; Vue reçoit le résultat par les références
qu'elle recevait déjà (`heartRateReference`…) et n'en recopie aucun seuil. Les
projections lues par Médecine et Chirurgie (`CareRecordReadModel`) passent l'âge
aux mêmes évaluations : un écran ne peut pas contredire l'autre.

| Constante | Lecture par âge |
|---|---|
| Fréquence cardiaque | Enfant : plage de l'éveillé selon l'âge — <1 an 100–180, 1–2 ans 98–140, 3–5 ans 80–120, 6–11 ans 75–118, dès 12 ans 60–100. Sous 60 bpm, un mineur est toujours signalé. Adulte : plus rapide que 100 bpm est signalé (marqué au-delà de 125). Écart marqué : moins de 85 % du minimum ou plus de 125 % du maximum. |
| Tension | Hypotension d'un enfant (PALS) : systolique <70 avant 1 an, <70 + 2 × âge de 1 à 10 ans, <90 au-delà. Dès 13 ans, les étapes de l'adulte. Avant 13 ans, pas de classement d'adulte : ≥120/80 = « à comparer aux tables de l'enfant », ≥140/90 = élevée quels que soient âge, sexe et taille. Le seuil sévère (>180/120) reste critique à tout âge. |
| Température | Nourrisson de moins d'un an : ≥38 °C = fièvre à avis médical rapide ; <36,5 °C bas, <36,0 °C hypothermie. Sinon inchangé. |
| SpO₂, IMC | Inchangés (ADR-040, ADR-032). |

Sources : AHA/PALS (fréquence de l'enfant, hypotension), AAP 2017 (âge à partir duquel
les étapes de l'adulte s'appliquent), OMS (température du nourrisson). Ce sont des
**aides au dépistage**, jamais un diagnostic et jamais bloquantes.

## Ce que l'application ne fait pas, et le dit

- **Le sexe ne change aucun seuil ci-dessus** : ces références ne le distinguent
  pas. Il compterait pour la tension de l'enfant (percentiles d'âge, sexe et taille)
  et l'IMC de l'enfant (courbes de croissance), qui exigent des tables complètes que
  l'application ne porte pas : elle affiche « à comparer aux tables » plutôt que
  d'inventer un classement. Le même refus vaut pour la **grossesse**, que ni le sexe
  ni l'âge ne permettent de déduire.
- **Sans âge, aucune plage n'est devinée** : ni fréquence rapide, ni seuil d'enfant.
  L'écran demande de renseigner l'âge (ADR-039).

## Un message qui ne passe pas inaperçu

Un repère hors norme se lit sous son champ, comme avant. Une valeur **critique**
(danger) ou **improbable** déclenche en plus un **message toast**, dans deux cas :

- un repère de tonalité `danger` (TA sévère, FC très basse ou très élevée, SpO₂ très
  basse, hypothermie, fièvre du nourrisson, hypotension de l'enfant, tension saisie
  au mauvais format) ;
- une **invraisemblance** : poids ou taille très au-delà de ce qu'un patient de cet
  âge peut atteindre (`VitalSignAgeReference::plausibility()` : par exemple 30 kg
  et 130 cm avant 5 ans), ou IMC <8 ou >70 — presque sûrement une faute de saisie.
  Ces bornes sont des invraisemblances volontairement larges, pas des références
  cliniques.

Le message part une fois la saisie posée (900 ms, pas à chaque chiffre) et pas deux
fois pour le même repère : recontrôler 170 puis 175 n'en refait pas un. Une valeur
restaurée du brouillon (ADR-073) n'en déclenche pas : elle est déjà affichée sous
son champ. Un avertissement simple (`warning`) n'en fait pas non plus : trop de
messages finissent par n'en faire lire aucun.

## À décider

- La validation des tables ci-dessus par un médecin, avant usage clinique.
- La tension et l'IMC de l'enfant selon les percentiles, si la clinique veut un
  classement chiffré : il faudra fournir les tables (âge, sexe, taille).
- Une durée de vie du nourrisson plus fine que l'année (nouveau-né, moins de
  3 mois) : l'âge n'est connu qu'en années entières.

---

# ADR-126 — Tabac et alcool selon l'âge, âge invraisemblable, « Oui » rouge et « Non » vert

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire) — seuils
d'âge **à faire valider par un médecin de la clinique**

**Complète l'ADR-125** (les constantes se lisent selon l'âge) et l'ADR-032 (tabac,
alcool, diabète : trois états). Aucune permission nouvelle, aucune migration.

## Le constat

Cocher « Tabac : Oui » et « Alcool : Oui » pour un enfant de 4 ans n'alertait
personne : ces champs ne dépendaient pas de l'âge. C'est la même faute de saisie que
« 45 kg à 4 ans » (ADR-125), et elle passait sous silence. Un âge de 118 ans non plus
n'alertait personne, alors que les repères de constantes en dépendent.

## La règle

```text
âge < 10 ans        « Oui » (tabac ou alcool) : peu vraisemblable — vérifiez la saisie
                    → rouge sous le champ ET toast
10 ≤ âge < 18 ans   « Oui » : consommation chez un mineur — à noter et à signaler
                    au médecin → ambre sous le champ, sans toast
âge ≥ 110 ans       c'est l'âge qu'il faut vérifier (date de naissance, âge déclaré)
                    → bandeau rouge en tête des constantes ET toast à l'ouverture
```

Les chiffres vivent avec les autres repères d'âge, dans
`VitalSignAgeReference` (`SUBSTANCE_UNLIKELY_BELOW_AGE`, `VERY_OLD_FROM_AGE`), et
arrivent à l'écran par `vitalPlausibility` ; aucun n'est écrit dans Vue. Comme pour
les constantes, ce sont des aides à la saisie : rien ne bloque l'enregistrement. Le
diabète « Oui » ne déclenche aucune alerte d'âge : il existe à tout âge. Sans âge
connu, on ne devine rien.

Le message toast suit les règles de l'ADR-125 : posé, dédoublonné, réservé au
niveau critique. La consommation d'un mineur reste sous son champ.

## Couleurs

Dans les trois groupes Diabète, Tabac et Alcool, « Oui » coché est **rouge** et
« Non » coché **vert** ; « N/R » reste neutre. Un facteur de risque présent ne se
confond pas avec son absence, et un « Oui » n'est plus discret au milieu de trois
boutons identiques (`yesNoClasses`, une seule règle pour les trois).

## À décider

Les âges de 10 ans (improbable) et de 110 ans (invraisemblable) sont des repères de
saisie, pas des références cliniques : à faire confirmer par un médecin de la
clinique, qui peut souhaiter un autre seuil pour le tabac et l'alcool des mineurs.

## Amendement du même jour — le n° d'ordre reste visible, et c'est celui du médecin

Dans l'onglet « Orientés · en attente du médecin », la colonne N° était vide : un
patient qui attend le médecin n'avait plus de numéro, alors que deux services en
portaient chacun un pour le même patient — de quoi se disputer la priorité.

La ligne affiche désormais **le n° d'ordre de la file Médecine**, celui que le
médecin voit pour ce patient. Il n'y a qu'un numéro par patient :

- `EpisodeQueuePresenter::medicineQueueNumbers()` numérote **toute** la file Médecine
  (orientations en attente, par heure d'arrivée chez le médecin ; une urgence encore
  prioritaire n'en a pas, comme chez lui). Les Soins et la Médecine lisent ce même
  calcul.
- La file Médecine ne numérote plus la seule page affichée : le n° d'un patient ne
  change plus avec un filtre, une recherche ou une page. Avant, chercher un nom le
  faisait passer n° 1.
- L'onglet est classé dans l'ordre de la file Médecine, urgences non encore vues en
  tête : le n° 1 est bien en première ligne.

Un patient à prendre aux Soins garde son n° de la file Soins ; une infobulle dit de
quelle file vient le numéro.


---

# ADR-127 — Remettre en file un patient pris en charge par erreur (Médecine)

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire)

**Complète l'ADR-122**, qui n'avait pas d'équivalent en Médecine : « consultation à
défaire ».

## Le constat

Un clic sur « Prendre en charge » au mauvais endroit — un patient dont ce n'est pas
encore le tour — ouvre une consultation et l'inscrit au nom du médecin. Rien ne
permettait de revenir en arrière.

## La règle

« Remettre en file » (`ReleaseMedicineOrientationAction`,
`POST /medicine/orientations/{o}/release`, `consultations.create`) repasse
l'orientation Médecine `PENDING`, `accepted_by`/`accepted_at` vidés. La place est celle
de `oriented_at`, que la prise en charge n'a jamais touché : le patient retrouve son n°.

```text
qui       le médecin qui a pris le patient, jamais un confrère
quand     orientation IN_PROGRESS, passage OPEN
tant que  la consultation est restée vierge
```

## Vierge, c'est-à-dire

Motif d'office inchangé (`AcceptMedicineOrientationAction::initialReasonFor()`, une
seule source), aucun texte d'interrogatoire ni d'examen, aucun traitement déclaré,
aucun examen structuré, aucune étape, diagnostic, demande d'analyse ou d'imagerie,
ordonnance, sortie, orientation de conduite à tenir ni ordre de soins. Une seule ligne
clinique suffit à refuser : le médecin a réellement commencé, et la suite passe par la
clôture puis la réouverture tracées (ADR-076, ADR-096), jamais par une suppression.

## Ce que l'action supprime, et pourquoi c'est acceptable

La consultation vierge est **supprimée physiquement**, et cette suppression est tracée :
`consultations.episode_orientation_id` est unique (soft-deleted compris), donc une
consultation seulement archivée empêcherait la prochaine prise en charge d'en ouvrir une
propre. Ce n'est pas une donnée médicale — c'est le reste de l'erreur, et la garde
ci-dessus garantit qu'elle ne contient rien. Le brouillon non envoyé (ADR-073) est écarté.

Le passage revient `ORIENTED`, et son statut médical est vidé si aucune autre
consultation ne le justifie. Si un autre service travaille encore sur le passage, rien
n'est touché. Audit : `medicine.orientation.release`, avec l'ancien état.

## Écran

Bouton « Remettre en file » dans la file Médecine, sur la ligne d'un patient pris par
le compte connecté. Le serveur reste juge : si la consultation est commencée, un avis
le dit. Aucune permission nouvelle.


---

# ADR-128 — Relecture d'une ligne d'ordonnance : ce que le système peut vérifier, et ce qu'il avoue ne pas pouvoir

**Status:** ACCEPTED (2026-09-19 — signalement explicite du propriétaire, sur un
enfant de 4 ans prescrit à 1000 mg sans qu'aucune alerte ne se déclenche) —
**seuils de dose à fournir par la clinique**

**Complète l'ADR-110** (une ordonnance ne réclame que ce que le produit porte) et
**l'ADR-111** (les propositions ne s'appuient que sur ce que la clinique a écrit).

## Le constat

Ceftriaxone 1 g injectable, prescrit **1000 mg, voie orale, fréquence « 2 », 10 jours,
quantité 1**, à un enfant de 4 ans. L'écran ne réagissait à rien. Il y avait pourtant
plusieurs choses à dire, et une seule que le système ne pouvait pas dire :

```text
voie orale sur un produit injectable   incohérent, lisible dans la forme du produit
fréquence « 2 »                        ne dit pas par quoi compter
quantité 1 pour 2 × 10 jours           1 flacon de 1 g par prise = 20, arithmétique
enfant de 4 ans                        la dose se rapporte au poids
« la dose est trop élevée pour l'âge » impossible — voir ci-dessous
```

## Ce que le système ne peut pas juger

« Trop élevé pour son âge » exige une **dose maximale par âge ou par poids**. Ni le CDC
ni le référentiel Pharmacie n'en portent (constat déjà posé à l'ADR-111) : les
médicaments n'ont que nom, DCI, forme et dosage. En écrire dans le code serait inventer
de la médecine, et une table fausse est plus dangereuse que pas de table. **Le système
ne dit donc jamais « dose trop élevée » : il dit qu'il ne peut pas le savoir.**

## Ce qu'il relit désormais

Tout se déduit de ce que l'application sait déjà — forme du produit, dosage, voie,
posologie saisie, âge, poids, allergies. `resources/js/utilities/prescriptionChecks.js`
porte les règles une seule fois ; l'éditeur de ligne et la fenêtre de signature lisent
les mêmes alertes.

| Niveau | Alerte |
|---|---|
| rouge | allergie connue recoupée (catalogue rapproché par le serveur, ligne manuelle comparée sur son nom) ; voie incompatible avec la forme — injectable donné par voie non parentérale, comprimé/sachet/sirop donné en IV/IM/SC |
| ambre | injectable sans voie ; fréquence numérique nue (« 2 ») ; quantité inférieure à ce que la posologie consomme, calculée avec le dosage du produit (dose ÷ dosage × prises × jours) ; enfant sans poids relevé ; même principe actif deux fois |
| info | enfant pesé : dose par kilo par prise et par jour, **suivie de l'aveu qu'aucune dose maximale n'est enregistrée** |

Le rapport mg/kg est de l'arithmétique, pas un jugement : le médecin voit « 62,5 mg/kg
par prise · 125 mg/kg/jour » et peut le comparer à sa référence. Un dosage que le
système ne sait pas lire (« 250 mg/5 ml », « 1 % ») ne produit aucun contrôle de
quantité : convertir ce qu'on ne comprend pas donnerait un contrôle faux.

## Une aide, jamais un verrou

Rien ne bloque la validation : la garde qui active « Valider » ne lit pas les alertes
(un test le vérifie). Elles se lisent sous chaque ligne, et la fenêtre de signature
(ADR-106) annonce « N points à relire avant de signer ». La voie incohérente n'est pas
refusée côté serveur : certaines ampoules injectables se donnent réellement par la
bouche, et bloquer serait pire que signaler.

## Amendement du même jour — par toast, pas par bloc

Les alertes occupaient la page sous chaque ligne. Elles se disent désormais par **toast** :
rouge et ambre seulement (12 s et 9 s), une fois la saisie posée (900 ms) et **une seule
fois par constat** — un message ne revient que s'il a disparu puis reparu. L'information
(mg/kg) ne parle pas : elle reste lisible dans la fenêtre de signature, qui garde la liste
complète. Chaque ligne porte seulement une pastille « N à relire » dans son en-tête, dont
l'infobulle relit les messages. Même règle que les constantes (ADR-125).

## Amendement du même jour — « poids non relevé » ne s'affirme qu'à coup sûr

Le message « poids non relevé » est apparu alors que la fiche Soins portait 25 kg depuis
13 h 56 : la page, ouverte avant la mise à jour, n'avait pas reçu le contexte
d'ordonnance, et le code confondait « le serveur dit non relevé » (`null`) et « je ne sais
pas » (`undefined`). Les deux sont désormais distincts : le système ne dit « non relevé »
que si le serveur le dit ; sans contexte, il se replie sur le poids de la fiche Soins déjà
présent dans la page, et se tait s'il n'en a aucun.

## Côté serveur

`MedicineDossierPresenter::prescriptionSafety()` sert `prescription_safety` — âge,
poids relevé aux Soins (`null` quand absent, jamais zéro) et, par médicament du
catalogue, l'allergie qu'il recoupe. Le rapprochement est celui des propositions
(`ClinicalProtocolMatcher::allergyConflict`) : une seule règle. Servi uniquement à qui
peut prescrire et voir le catalogue.

## À décider avec la clinique

Pour qu'une dose trop élevée soit **réellement** détectée, la clinique doit fournir ses
références, par médicament : dose maximale par prise et par jour, en valeur absolue ou
en mg/kg, éventuellement par tranche d'âge, avec la source. Elles seraient saisies dans
l'application (comme les protocoles, ADR-111), jamais écrites dans le code, et la
détection ne s'appliquerait qu'aux produits renseignés — les autres continueraient de
dire « aucune dose maximale enregistrée ». Aucune table n'est inventée en attendant.

Aucune permission nouvelle, aucune migration.


---

# ADR-129 — La lecture du dossier ne retient plus la clôture

**Status:** ACCEPTED (2026-09-19 — signalement explicite du propriétaire)

**Amende l'ADR-076** (« la clôture refuse tant qu'une étape pertinente n'est pas
résolue ») pour la seule étape Dossier.

## Le constat

Le bandeau disait « 1 résultat encore attendu — cela n'empêche pas de clôturer » et
« Clôturer la consultation » restait grisé. Le vrai obstacle, écrit en petit dans le pied
de page, était « Dossier du passage : à valider avant la clôture ». Pas le résultat
attendu — qui n'est pas un obstacle depuis l'ADR-105 — mais une étape que personne ne
pensait à valider, et que rien n'expliquait au médecin.

## La règle

Le dossier est une **étape de lecture** (`ConsultationStep::requiresContent()` est faux
pour lui) : il ne porte aucune saisie, et « valider » n'y enregistre que le fait de l'avoir
lu. Un médecin qui a posé son diagnostic et choisi la suite l'a lu. Le retenir sur ce clic
était une formalité, du même genre que l'examen déjà transmis (ADR-105).

`ConsultationWorkflow::blockersForClosure()` n'inclut donc plus le Dossier. L'étape reste
affichée et validable ; elle ne bloque plus. Les autres étapes gardent leur règle : un
interrogatoire, un examen, une prescription non validés ou non déclarés « non nécessaires »
retiennent toujours la clôture (ADR-076), parce qu'ils portent du contenu clinique.

## À décider

Si le même reproche vient pour l'Interrogatoire ou l'Examen — remplis mais jamais
« validés » —, il faudra décider si le fait clinique (le contenu existe) doit suffire,
comme pour le diagnostic (ADR-081). Rien n'est changé ici : c'est une règle de parcours
clinique, pas une formalité de lecture.

Aucune permission, route ni migration.


---

# ADR-130 — Corriger un compte rendu d'imagerie déjà enregistré

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire)

**Complète l'ADR-108** (le compte rendu d'imagerie) sur un point qu'elle posait comme
définitif : « un compte rendu enregistré n'est jamais réécrit ». Le médecin doit pouvoir
le modifier après l'avoir saisi. **Applique l'ADR-010** : on ne détruit pas une donnée
médicale signée, on la corrige avec trace.

## La règle

La première saisie reste unique (`RecordImagingResultAction` refuse toujours un second
compte rendu). **Corriger est un autre acte** (`CorrectImagingResultAction`,
`PUT …/imaging-requests/{item}/result`, permission `imaging_results.update`) : il
**conserve la version qu'il remplace**, puis écrit la nouvelle.

```text
imaging_request_items    resulted_at/by   signature d'origine — la date du compte rendu
                         corrected_at/by  dernière correction
                         result_value/notes  le texte courant
imaging_result_revisions une ligne par version REMPLACÉE : texte, auteur et date de
                         cette version, qui l'a remplacée, quand, motif facultatif
```

`imaging_result_revisions` est **append-only** : une version remplacée ne se modifie ni
ne se supprime (le modèle le refuse). L'audit (`imaging.result.correct`) garde l'ancienne
et la nouvelle valeur. Le motif est facultatif — une faute de frappe n'en demande pas —
mais servi quand il existe.

## Garde-fous

```text
rien n'a changé        refusé : pas de version fantôme
compte rendu vide      refusé, comme à la saisie ; texte assaini comme à la saisie
pas encore de compte   refusé : c'est « Saisir le résultat », pas une correction
autre passage          refusé : l'examen doit appartenir à cette orientation
sans le droit          403 — écrire un compte rendu et revenir sur un document signé
                       ne sont pas la même autorité (même séparation qu'ADR-096)
```

La correction reste possible **après la clôture** de la consultation, comme la saisie : un
résultat tardif ou une faute repérée ensuite ne doivent pas être impossibles à rectifier.
Elle n'a aucun effet financier ni sur le parcours du passage.

## Écran « Demandes d'examens »

- Un bouton **Modifier** par examen rendu, à côté de Voir le résultat et Imprimer ; la
  fenêtre de saisie s'ouvre préremplie du compte rendu enregistré, avec le motif facultatif
  et un bouton grisé tant que rien n'a changé.
- La lecture (« Voir le résultat ») offre aussi Modifier, et se relit dans les props : après
  une correction elle montre la nouvelle version. Un repère **Corrigé** (qui, quand) apparaît
  dans la liste et la lecture, et un **historique** replié liste les versions remplacées
  avec leur auteur, leur date et leur motif.
- L'impression porte toujours le compte rendu courant.

## À décider

L'espace Paraclinique de la consultation ne propose pas encore Modifier : la correction se
fait depuis « Demandes d'examens ». Rien n'empêche de l'ajouter si le médecin la veut aussi
dans le dossier.

`imaging_results.update` est accordée à `MEDICINE` par la migration (ADR-064).


---

# ADR-131 — « Demandes d'examens » : familles d'examens, archivage à la main, boutons compacts

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire)

**Complète l'ADR-106** (famille d'imagerie réglée au catalogue) et **l'ADR-130** (correction
d'un compte rendu). Aucune règle clinique ni financière n'est modifiée.

## Le constat

Trois défauts sur l'écran : des boutons trop longs (« Saisir le résultat » deux fois de suite
sur une demande de deux examens, sans qu'on sache lequel est lequel) ; « Ouvrir la
consultation » seul sans icône ; pas de moyen de ranger une demande lue — « Archivées » ne
contenait que ce que le temps ou un retrait y mettait — ni de filtrer par famille d'examen.

## Boutons

Chaque **examen** porte ses actions à sa ligne : « Saisir » (court, avec son icône), puis
Voir, Modifier et Imprimer en **icônes**, nommées au survol et pour les lecteurs d'écran
avec le nom de l'examen. Deux examens d'une même demande ne partagent donc plus deux
boutons identiques. Ce qui concerne toute la demande — consultation (icône stéthoscope),
archiver, retirer — reste dans la colonne Actions, en icônes.

## Familles

Un filtre à onglets **Tous · ECG · Échographie · Analyses** (`?type=`) se **combine** avec
les trois vues : changer l'une garde l'autre. Chaque compte est ce que donnerait un clic —
les vues comptent sous la famille choisie, les familles sous la vue choisie — et vient du
serveur. La famille d'imagerie est celle du catalogue (ADR-106), jamais déduite d'un code :
un examen sans famille tombe dans **Non classés**, onglet visible seulement s'il en existe un.
Une demande qui réunit plusieurs familles apparaît sous chacune.

## Archiver

`lab_requests` et `imaging_requests` reçoivent `archived_at/by` : **un drapeau daté et signé,
réversible — rien n'est supprimé**, aucun résultat ni aucune facturation ne bouge. Permission
dédiée `paraclinical_requests.archive` (accordée à MEDICINE par migration, ADR-064) : ranger
change ce que voient les confrères, ce n'est pas la même autorité que lire.

```text
archiver     seulement une demande dont tous les résultats sont rendus et récente :
             ranger du travail à faire le ferait disparaître, un vide muet se lit
             « rien à faire »
sortir       seulement ce qu'on a rangé à la main, tant que le résultat est récent :
             un résultat ancien retourne aussitôt en archive tout seul (les 7 jours
             de l'ADR-076/ADR-079 ne changent pas)
```

Une demande rangée à la main n'est plus « récente », même rendue hier. « Archivées »
contient désormais : les demandes retirées, celles qu'on a rangées, et les résultats anciens.
Audit `paraclinical_request.archive` / `.unarchive`. L'archivage n'agit que sur cet écran : la
file du Laboratoire n'en est pas modifiée.

`POST /medicine/paraclinical-requests/{lab|imaging}/{uuid}/archive|unarchive`, réservé à qui
voit la famille concernée.


---

# ADR-132 — La sortie médicale se place en bas de page, sur toute la largeur

**Status:** ACCEPTED (2026-09-19 — signalement explicite du propriétaire)

**Complète l'ADR-113 et l'ADR-114** (pages Hospitalisation et Pédiatrie), sans changer aucune
règle de sortie.

Le formulaire de sortie médicale est une grille à deux colonnes (diagnostic final, état du
patient, traitement, conseils, contrôle). Placé dans la colonne latérale de 22 à 24 rem, il
s'écrasait et s'affichait mal, surtout sur un écran étroit.

Sur les pages **Hospitalisation** et **Pédiatrie**, la carte de sortie quitte la colonne
latérale : elle occupe désormais **toute la largeur, sous le contenu**. Le bouton « Prononcer
la sortie » est dans l'en-tête de cette carte, à côté du titre ; il déplie le formulaire en
dessous. Une sortie déjà prononcée s'affiche aussi pleine largeur, en quatre colonnes.

`ClinicalSegmentedChoice` (segments « État du patient », etc.) ne rétrécit plus ses boutons :
ils passent à la ligne au lieu d'être tronqués (« Amél… », « Non … ») sur un écran de 420 px.
Le correctif profite à tous les écrans qui l'emploient.

Aucune permission, route ni donnée n'est modifiée.

---

# ADR-133 — Répertoire patients : ordre alphabétique, export Excel et catégorie VIP paramétrée par site

**Status:** ACCEPTED (2026-09-19 — exigence explicite du propriétaire ; règle
tranchée question par question)

**Complète l'ADR-119/120** (répertoire des patients). Le CDC ne définit ni
« patient VIP » ni export du répertoire : les règles ci-dessous viennent
toutes du propriétaire.

## Ordre alphabétique et export

`?sort=recent|name_asc|name_desc` (nom puis prénom) et `?letter=A..Z`
(initiale du nom, une seule lettre ; toute autre valeur ne filtre rien). Les
compteurs des cartes, onglets et catégories suivent la lettre comme les autres
filtres (facettes, ADR-119).

`GET /patients/export` produit un `.xlsx` de **la liste telle que filtrée**
(mêmes paramètres que l'écran, plafond 20 000 lignes). Il exige `patients.view`
**et** `patients.export`, accordée par défaut à `ADMINISTRATION` seulement — le
répertoire contient des données personnelles. Chaque export est audité
(`patient.export`, filtres et nombre de lignes). Aucun PDF, aucune donnée
clinique.

## Patient normal / Patient VIP

Un patient est VIP quand, **sur une fenêtre glissante**, il cumule **les deux** :

```text
au moins N passages non annulés          (started_at dans la fenêtre)
ET au moins M Ar encaissés               (payments COMPLETED sur ses factures,
                                          paid_at dans la fenêtre)
```

`N`, `M` et la durée de la fenêtre (mois) sont des paramètres, **propres à
chaque site** : chaque clinique a son volume et ses tarifs. Ils se règlent
depuis le portail (`/super-admin/patient-vip`, `patient_vip.view` /
`patient_vip.update`, réservées à `SUPER_ADMIN`) et sont poussés à l'API du site
(`/api/v1/super-admin/patient-vip-settings`, idempotente, auditée
`patient_vip.settings.update` avec l'identité UUID/nom de l'acteur central) —
jamais de SQL depuis le portail (ADR-004, ADR-027). Une aperçu (`/preview`)
indique combien de patients seraient VIP avant d'enregistrer.

- **La catégorie est calculée, jamais stockée** : elle suit l'activité réelle,
  aucun patient n'est « marqué » à la main, aucun historique n'est réécrit.
- **Sans seuil configuré ou désactivé, personne n'est VIP** : aucune valeur
  n'est inventée par défaut (le montant est le paramètre que le propriétaire a
  voulu variable).
- « Encaissé » = paiements `COMPLETED` uniquement ; une prise en charge
  mutuelle/personnel ou une facture impayée n'est pas de l'argent encaissé.
- Le seuil est inclusif (`>=`). La comparaison décimale est faite en SQL avec un
  `CAST(... AS DECIMAL(15,2))` : PDO transmet les décimaux comme du texte et
  SQLite ordonne les nombres avant le texte.
- La catégorie est un simple repère (badge « VIP », filtre, compteur) : elle ne
  change **aucun** tarif, droit ni parcours. Aucune remise n'est créée.

Migration `2026_10_02_090000` : table singleton `patient_vip_settings` et les
trois permissions (ADR-064 : un site en production ne rejoue pas les seeders).

---

# ADR-134 — Soins : Infirmière, Maternité et Anesthésie sous une seule entrée, en trois onglets

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire)

**Complète l'ADR-067** (profils paramédicaux et espace Maternité) et **l'ADR-048**
(espaces Anesthésie/Chirurgie), sans modifier aucune règle métier. Aucune
permission nouvelle, aucune route nouvelle, aucune migration.

## Le constat

Le menu latéral listait trois entrées séparées — Soins, Maternité, Anesthésie —
alors que ce sont trois profils du même rôle `NURSE` (Infirmier, Sage-femme,
Anesthésiste, ADR-033/067). Un soignant qui exerce deux de ces métiers passait
d'un écran à l'autre par le menu, sans rien qui dise qu'ils forment une famille.

## La règle

```text
Menu latéral   une entrée mère « Soins » (SIDEBAR_GROUPS, ADR-115) réunissant
               Infirmière (/care), Maternité (/maternity), Anesthésie (/anesthesia)
Sur l'écran    une barre d'onglets Infirmière · Maternité · Anesthésie, en tête
               des trois pages (SoinsTabs)
```

**Chaque onglet reste une vraie page, avec sa propre adresse.** Les trois files
ont des contrôleurs, des données et des permissions distincts ; les charger dans
une seule page Inertia obligerait à tout calculer à chaque visite et à mélanger
trois droits. Le bouton Précédent du navigateur, les liens partagés et les
signets continuent de fonctionner (même principe que l'ADR-098).

## Permissions : l'onglet suit le droit

Un onglet n'apparaît que si le compte peut ouvrir la page qu'il désigne, et la
page où l'on se trouve est toujours affichée :

```text
Infirmière   care.update
Maternité    maternity.view
Anesthésie   anesthesia.view
```

Une seule page accessible → aucune barre (un onglet seul ne servirait à rien) ;
le menu affiche alors le lien simple, comme pour tout groupe à un seul membre
(ADR-115). La barre n'est qu'ergonomie : chaque route revérifie sa permission
côté serveur, et un droit `DENY` individuel reste prioritaire.

## Ce qui ne change pas

Les profils ne donnent toujours aucun droit (ADR-033) : `MIDWIFE` recommande
`maternity.*`, `ANESTHETIST` recommande `anesthesia.*`, copiés explicitement en
exceptions individuelles. `NURSE` ne reçoit toujours pas `anesthesia.*` par
défaut. L'écran Anesthésie passe au passage à shadcn-vue (ADR-099), sans changer
ses données.

---

# ADR-135 — Soins : des files qui suivent le parcours réel (Maternité, Anesthésie)

> Amendée par l'**ADR-177** (2026-09-23) pour la Maternité : ses quatre vues deviennent les
> six vues du tableau partagé ; « Orientées vers Médecine » se lit en pastille sur la ligne.
> L'Anesthésie n'est pas touchée.

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire : « filtre pour
la patiente en cours ou déjà orientée vers le médecin » en Maternité, « orienté vers
Chirurgie ou en cours » en Anesthésie, « tout mettre en logique workflow »)

**Complète l'ADR-134** (les trois espaces sous une seule entrée), **l'ADR-124** (file
Soins à deux onglets), **l'ADR-067** (espace Maternité) et **l'ADR-048** (espace
Anesthésie). Aucune permission nouvelle, aucune migration.

## Le constat

Maternité listait toutes ses orientations dans une seule liste : une patiente déjà
terminée y restait, et rien ne disait si le médecin l'avait reprise. Terminer la prise
en charge ne faisait **rien d'autre** : la patiente quittait la file, son passage
restait « en soins » indéfiniment et n'atteignait jamais « Sorties & règlements » —
le trou que l'ADR-054 a déjà comblé pour les Soins et l'ADR-114 pour la Pédiatrie.
Anesthésie affichait toutes les demandes chirurgicales sans étape : impossible de
distinguer le dossier à évaluer de celui déjà transmis à Chirurgie.

## Règle commune

Les vues sont **exclusives** : un dossier est dans une seule, la somme des comptes est
le nombre de dossiers de la file (comme ADR-119, ADR-120, ADR-124). Les comptes viennent
du serveur, jamais de la page paginée. La carte est le filtre ; une valeur inconnue
retombe sur le travail à faire, jamais sur l'historique. Aucune vue n'est déduite d'un
libellé : chacune se lit sur un fait déjà enregistré.

## Maternité — quatre vues (`App\Services\Maternity\MaternityQueue`)

```text
À prendre             orientation Maternité en attente : personne ne l'a encore prise
En cours              orientation Maternité prise en charge
Orientées vers Médecine  Maternité terminée ET une orientation Médecine, source Maternité,
                      encore en attente ou en cours
Terminées             Maternité terminée, aucune suite en cours
```

**Amendement du même jour.** La première version regroupait « à prendre » et « en cours »
sous le seul libellé « En cours » : une patiente que personne n'avait prise s'y lisait
« En attente » à côté d'une patiente prise en charge, dans un bloc qui disait l'inverse.
Ce sont deux états ; ils ont chacun leur vue. La vue par défaut est « À prendre » ; le n° de
file n'y est affiché que pour elle, une patiente déjà prise n'attendant plus.
`?filter=active` désigne désormais les seules prises en charge en cours.

Dès que le médecin a terminé, la patiente retombe en « Terminées » : le dossier reste
consultable, plus personne ne l'attend. Chaque ligne porte ce qui suit la Maternité :
l'état chez le médecin (« En attente du médecin », « Vue par le médecin » + nom,
« Consultation terminée ») et la césarienne demandée à Chirurgie — deux requêtes pour
toute la page.

## Fin de prise en charge à deux issues

`CompleteMaternityOrientationAction` demande à la sage-femme ce qui vient ensuite :

```text
Terminer                     la Maternité a fini ; si plus aucun service n'a la patiente,
                             le passage passe en PENDING_SETTLEMENT (règle de l'ADR-054)
Terminer et orienter vers    une orientation Médecine, source Maternité, s'ouvre sur le même
Médecine                     passage — via l'unique CreateEpisodeOrientationAction ; le
                             passage reste en soins ; message facultatif pour le médecin
```

L'issue est choisie explicitement (`orient_to_medicine`), jamais devinée. Une orientation
Médecine **déjà active** est réutilisée (`active_key`) : la patiente n'arrive jamais deux
fois dans la file du médecin ; une orientation Médecine **terminée** n'empêche pas d'en
ouvrir une nouvelle — la sage-femme demande explicitement que le médecin revoie la
patiente (même logique que l'ordre de soins, ADR-055). Un passage dont une césarienne ou
une consultation reste ouverte n'est jamais déclaré en attente de règlement, et un statut
déjà avancé par la Réception n'est jamais ramené en arrière.

**Hypothèses signalées.** L'orientation Maternité → Médecine est une règle demandée par le
propriétaire ; le CDC ne la décrit pas. Elle réutilise `maternity.complete` (aucun droit
n'est ajouté) : une sage-femme à qui `maternity.complete` est refusé (DENY) ne peut donc
ni terminer ni orienter.

## Anesthésie — quatre étapes (`App\Enums\AnesthesiaCaseStage`)

Le dossier d'anesthésie est porté par la demande chirurgicale : la file suit le dossier de
l'évaluation au bloc, sur des faits que la Chirurgie et l'anesthésie enregistrent déjà.

```text
À évaluer               évaluation pré-anesthésique pas validée, pas encore au bloc
Transmis à Chirurgie    évaluation validée, Chirurgie pas encore au bloc
Au bloc                 Chirurgie au bloc, dossier anesthésique pas validé
Terminés                intervention terminée, ou dossier anesthésique validé
```

Une demande annulée est masquée de la file. Chaque ligne affiche son étape et ce qu'elle
ajoute (brouillon en cours, date de validation…). La règle SQL (`constrain`) et la règle
ligne à ligne (`of`) sont testées l'une contre l'autre pour ne pas diverger.

## Présentation

Les trois pages partagent `SoinsWorkspaceHeader` (icône, eyebrow, titre, description,
recherche/actions) sous la barre `SoinsTabs`, écrits en shadcn-vue (ADR-099). Les états
vides disent quelle vue est vide et ce qui y apparaîtra.

## Ce qui ne change pas

Aucune règle de prise en charge, de permission ni de facturation. La Maternité n'encaisse
rien (ADR-012) ; la césarienne reste une `SurgicalRequest` et n'est jamais une
intervention créée par Maternité (ADR-067).

---

# ADR-136 — Dossier Maternité adapté à l'acte demandé, et liste d'actes du propriétaire

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire : formulaires Maternité
« plus intelligents et interactifs, pour répondre au besoin et à la rapidité de travail », avec
la liste des actes Maternité de la clinique)

**Complète l'ADR-067** (espace Maternité), **l'ADR-068** (actes Maternité à la Réception),
**l'ADR-073** (saisie conservée côté serveur) et **l'ADR-135** (files Maternité). Aucune
permission nouvelle.

## Ce que le CDC ne dit pas, et qui n'est donc pas inventé

Le CDC ne décrit aucun contenu clinique de la Maternité, ni par acte ni en général. Le
document Google transmis ne contient que la liste des actes. Aucun champ propre à un acte
(type et lot d'un DIU, score d'un accouchement…) n'est donc ajouté : le propriétaire a choisi
d'adapter le dossier **sans nouveau champ**. Si des champs par acte sont voulus, les sages-femmes
doivent en fournir la liste.

## La liste des actes, rapprochée du catalogue

Vingt libellés transmis, seize actes Maternité déjà au catalogue :

```text
déjà présents         Accouchement simple / gémellaire, Consultation prénatale, Insertion / Retrait
                      DIU, Insertion / Retrait Implanon, Césariennes simple / gémellaire,
                      Pansement ombilical, Pèse bébé, Soins bébé, Autres
renommés              « Doppler »        -> « Utilisation Echo Doppler »
                      « Photothérapie »  -> « Utilisation Photothérapie »
ajoutés               Utilisation Aspirateur bébé (MAT-BABY-ASPIRATOR), IEC (MAT-IEC),
                      Nursie (MAT-NURSIE)
déplacé               Syana Press / Dépôt Provera : FP-INJECTABLE quittait le module Planning
                      familial (aucun espace de travail : il n'était proposé nulle part) et
                      rejoint la Maternité, sans changer de code — le code est l'identité stable
                      (ADR-024). Le libellé « Contraceptif injectable (Sayana Press / Depo-Provera) »
                      est conservé.
```

Les trois actes ajoutés sont sélectionnables à la Réception et facturables comme les autres
actes Maternité (choix du propriétaire) ; aucun tarif n'est créé, le Super Admin les fixe.
**« Nursie » n'a pas de définition** : sa description dit qu'elle est « à préciser par la
Maternité », et aucun profil de section ne lui est attaché. « IEC » est l'acronyme
Information, Éducation, Communication.

**Sites existants.** Le catalogue est écrit par le Super Admin (ADR-024) : aucune migration ne
crée de ligne. `2026_10_04_100000_move_injectable_contraceptive_to_maternity` ne fait que déplacer
`FP-INJECTABLE` et renommer les deux libellés encore à leur ancien nom ; un choix d'administrateur
est laissé intact. Nursie, IEC et Utilisation Aspirateur bébé se créent depuis Désignations &
tarifs, sur chaque site (le seeder local les crée). Le seeder n'écrase aucun libellé personnalisé.

## Le dossier s'adapte à l'acte demandé — sans rien interdire

`App\Support\MaternityActProfile` dit, **par code d'acte** (jamais par libellé, ADR-052), quelles
sections du dossier l'acte attend :

```text
Consultation prénatale (+ suivi)   contexte, grossesse & prénatal
Utilisation Echo Doppler           grossesse & prénatal
Accouchement simple / gémellaire   contexte, travail, accouchement, nouveau-né
Soins bébé, Pèse bébé, Pansement ombilical, Aspirateur bébé, Photothérapie   nouveau-né
tout autre acte (DIU, Implanon, injectable, IEC, Nursie, Autres…)   actes seulement
```

La section « Actes & transmission » est toujours mise en avant dès qu'un acte est demandé. C'est
une **aide de navigation, jamais une règle** : toute section que le compte a le droit d'écrire
reste atteignable ; la seule marque visible est un point ambre « attendue par les actes demandés »
sur un onglet non renseigné. Le dossier s'ouvre sur la première section attendue et
vide ; sans acte demandé, comme avant.

Un accouchement gémellaire ouvre **deux fiches de nouveau-né vides** (un simple : une). Jamais
remplies : aucune donnée n'est déduite d'un acte.

## Actes demandés en un clic

`plannedProcedures` relit `episode_service_requests` du module Maternité — ce que la Réception a
réellement demandé —, avec `done` (déjà enregistré) et la quantité. « Enregistrer » poste vers
l'endpoint existant, sans nouveau chemin d'écriture ; les césariennes n'y figurent jamais (elles
partent à Chirurgie, ADR-067). Le formulaire d'acte remplace la liste déroulante par des boutons :
vingt libellés se lisent d'un coup.

**« Autres » exige sa précision.** Le catalogue le décrit « Autre acte de maternité, à préciser » ;
sans description, l'acte ne dit rien à la sage-femme suivante. Refusé côté serveur
(`RecordMaternityProcedureAction`, `requires_note` servi au catalogue) ; l'écran le dit avant l'envoi.
Comme aux Soins (ADR-032), et pour cette seule ligne : les autres actes gardent une précision
facultative.

## Saisie conservée

`maternity_record_drafts` applique l'ADR-073 : la saisie survit à une actualisation, côté serveur,
rattachée au passage **et** à son auteur (une collègue ne récupère jamais la saisie non validée
d'une autre). Trois sections — `record`, `procedure`, `cesarean` —, jamais auditées, supprimées dès
l'enregistrement réel, l'annulation explicite (« Effacer le brouillon ») ou la fin de la prise en
charge. Refusée dès que la prise en charge n'est plus en cours. La route est exclue de
`ConvertEmptyStringsToNull` : un champ vide revient chaîne vide, pas `null`.

## Observation, non traitée ici

Un acte enregistré par la sage-femme **au-delà** de ce que la Réception a demandé ne crée aucun
élément facturable — contrairement aux Soins (ADR-054). Le CDC ne définit pas cette facturation ;
elle n'est pas inventée. À trancher si la clinique veut facturer les actes ajoutés en Maternité.

---

# ADR-137 — Repères rappelés sous les champs du dossier Maternité

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire : un poids de bébé
de 10 kg doit produire un message d'information ; d'autres champs « à titre d'info ») —
**seuils à faire valider par une sage-femme ou un médecin de la clinique**

**Complète l'ADR-136** (dossier adapté à l'acte demandé) et **applique aux champs Maternité le
principe des ADR-125/126** (repères de constantes). Aucune permission nouvelle, aucune migration.

## Le constat

Les champs du dossier n'avaient aucune intelligence : seules des bornes de validité côté
serveur (poids de naissance entre 100 et 8 000 g, Apgar 0-10…) refusaient une saisie **à
l'enregistrement**, avec une erreur brute. Un « 10 » saisi pour 10 kg, ou « 78 », n'était signalé
qu'au moment de sauvegarder — sans dire que le poids se saisit en grammes.

## La règle : une aide, jamais un verrou

Un message s'affiche sous le champ pendant la saisie et dit ce qui mérite un second regard.
Rien n'empêche d'enregistrer (hors les bornes de validité, qui existaient déjà et sont désormais
lues dans la même table). Trois niveaux : `info` (à titre d'information), `warning` (à
recontrôler), `danger` (à signaler). Un champ vide ne dit rien : une absence n'est ni normale ni
anormale (ADR-077).

Les seuils s'écrivent **une seule fois**, dans `App\Support\MaternityReference`, et arrivent à
l'écran par la page (`maternityReference`) : Vue n'en recopie aucun (`utilities/maternityChecks.js`
ne fait que lire une valeur et une référence). `UpdateMaternityRecordRequest` lit les mêmes bornes :
un repère affiché et une borne refusée sont le même chiffre.

## Les repères

```text
poids de naissance   unité en grammes rappelée (« 3200 pour 3,2 kg ») sous 100 g ; conversion
                     « 3 200 g = 3,2 kg » ; < 2 500 faible, < 1 500 très faible, < 1 000
                     extrêmement faible ; ≥ 4 000 macrosomie possible, ≥ 5 000 à vérifier ;
                     > 8 000 g : refus annoncé avant l'envoi
Apgar                0-3 bas, 4-6 modérément bas, 7-10 rassurant
terme (SA)           < 28 grande prématurité, < 37 prématurité, 37-41 à terme, ≥ 42 dépassé
hauteur utérine      comparée au terme entre 20 et 36 SA (règle de McDonald, ± 3 cm) ;
                     aucun message hors de cette fenêtre, ni sans terme
rythme fœtal         110-160 bpm ; < 110 / > 160 à recontrôler ; < 100 / > 180 à signaler
dilatation           10 cm : « dilatation complète »
parité / gestité     la parité ne peut pas dépasser la gestité
dates                dernières règles dans le futur ou de plus de 10 mois ; début du travail
                     ou accouchement dans le futur ; travail postérieur à l'accouchement
nouveau-nés          « N nouveau-nés attendus d'après l'acte demandé » (ADR-136)
```

## Deux propositions calculées, jamais appliquées d'office

À partir des dernières règles :

```text
terme estimé (Naegele)   dernières règles + 280 jours, proposé sous le champ « Terme estimé »
âge de la grossesse      en SA, proposé sous le champ « Terme (semaines) »
```

Un bouton « Utiliser » reprend la valeur — **seulement si le champ est vide** : jamais par-dessus
une saisie. Rien n'est enregistré sans que la sage-femme le décide.

## Ce que le dossier ne fait pas, et le dit

Aucun seuil n'est propre au sexe, à la parité ou à une grossesse gémellaire (les poids y sont
plus bas) : ces nuances exigent des tables que ni le CDC ni l'application ne portent. Un poids
bas y est donc signalé comme partout ailleurs, à titre d'information. Aucune alerte n'est un
diagnostic. Pas de message contextuel (toast) : les repères restent sous leur champ.

Sources : OMS (poids de naissance, prématurité), AAP/ACOG (Apgar), ACOG/NICE (rythme fœtal),
règles de McDonald et de Naegele. **À faire confirmer par la clinique.**

---

# ADR-138 — Les actes Maternité s'enregistrent dans un panier

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire : « mettez sous forme
panier ces actes ; il faut rester toujours UI et UX »)

**Amende l'ADR-136** (formulaire d'acte à l'unité). Aucune permission nouvelle, aucune migration
de schéma.

## Le constat

Le formulaire enregistrait **un acte à la fois** : choisir un bouton, régler la quantité et la
précision, enregistrer, recommencer. Une prise en charge qui enchaîne trois actes (soins bébé,
pansement ombilical, IEC) demandait trois passages complets, sans jamais voir l'ensemble.

## Le panier

L'onglet « Actes & transmission » se lit en deux colonnes :

```text
Actes disponibles   les boutons du catalogue, avec un filtre (accents ignorés) ;
                    un clic ajoute l'acte, un second le retire
Panier d'actes      une ligne par acte : quantité (− / + / saisie) et précision ;
                    « Vider », et « Enregistrer N actes » en pied
```

- Un acte **n'entre qu'une fois** : la quantité porte les répétitions (comme les prestations de la
  Réception). Les boutons − / + ne descendent jamais sous 1.
- Les actes demandés à la Réception s'y ajoutent d'un clic (« Ajouter les N actes demandés »). Le
  bandeau « Demandé à la Réception » garde son enregistrement direct en un clic pour les actes qui
  n'exigent rien ; « Autres » y passe par le panier, où sa précision se saisit.
- **« Autres » exige sa précision** (ADR-136) : la ligne est marquée « Précision obligatoire », le
  bouton d'enregistrement dit combien d'actes restent à préciser.

## Tout, ou rien

`POST /maternity/orientations/{o}/procedures/batch` (`maternity.procedures.manage`) passe chaque
ligne par l'Action à l'unité, **inchangée** (prise en charge active, césarienne refusée, « Autres »
à préciser) — dans une seule transaction. Une ligne refusée annule tout, et le refus est rattaché à
sa ligne (`procedures.N.notes`, avec le nom de l'acte) : la sage-femme la corrige dans son panier.
Même schéma que la livraison de stock de la Pharmacie (ADR-098). Le panier est borné (30 lignes) et
refuse un acte en double. L'endpoint à l'unité reste, pour l'enregistrement direct d'un acte demandé.

## Saisie conservée

Le panier est une section du brouillon (`basket`, ADR-136) : il survit à une actualisation. Un
panier enregistré **retire sa seule section** du brouillon (`MaternityRecordDraft::forgetSection`) :
il ne ressort jamais remettre dans le panier ce qui vient d'être enregistré, et le dossier encore en
saisie est conservé. Un brouillon réduit à rien est supprimé. Un acte retiré du catalogue depuis
n'est pas restauré dans le panier — il ne pourrait pas partir.

## Ce qui ne change pas

Aucune règle d'enregistrement d'un acte, aucun acte ajouté ou retiré du catalogue. Un acte
enregistré au-delà de la demande de la Réception n'est toujours pas facturé (observation de
l'ADR-136).

---

# ADR-139 — Soins bébé par nouveau-né, soins mère uniques

**Status:** ACCEPTED (2026-09-20 — question du propriétaire : avec plusieurs nouveau-nés, les
champs « Soins mère » et « Soins bébé » sont-ils uniques ou propres à chacun ?)

**Amende l'ADR-067** (dossier Maternité) sur un point. Aucune permission nouvelle, aucune migration.

## Le constat

Le dossier portait deux notes uniques pour tout le passage : `maternal_care_notes` et
`baby_care_notes`. Pour la mère, c'est juste — il n'y en a qu'une. Pour le bébé, non : avec des
jumeaux, les soins diffèrent (l'un sous photothérapie, l'autre non), et une seule note les mêlait.

## La règle

```text
Soins mère    une seule note pour le passage (maternal_care_notes), inchangée
Soins bébé    une note PAR nouveau-né : newborn_data.newborns[i].care_notes
```

Le champ vit dans le JSON `newborn_data` déjà existant, à côté du sexe, du poids et de l'Apgar :
aucune colonne, et retirer un nouveau-né retire ses soins avec lui. Un enfant unique garde le libellé
« Soins bébé » ; avec plusieurs, chaque fiche dit « Soins — nouveau-né N ».

## Ce qui est conservé

`baby_care_notes` **n'est ni supprimée ni migrée** : rattacher après coup une note commune à un bébé
précis, sur un dossier de jumeaux, reviendrait à inventer une donnée (ADR-074). Elle reste servie et
affichée — « Soins bébé — note générale » — **seulement si elle existe déjà**, et jamais effacée en
silence ; un dossier neuf ne la propose pas. Un nouveau-né enregistré avant ce champ le reçoit vide.
La règle serveur (`baby_care_notes` interdit sans `maternity.newborn.manage`) est inchangée, et
`care_notes` suit la même permission que le reste de `newborn_data`.

---

# ADR-140 — Corriger ou retirer un acte Maternité enregistré

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire, avec deux arbitrages) —
**amende l'ADR-138** (un acte enregistré était définitif)

Aucune permission nouvelle : `maternity.procedures.manage`, déjà exigée pour enregistrer un acte.

## La règle

Chaque acte de « Actes réalisés » porte, quand le compte peut le modifier, une icône **crayon**
(corriger) et une icône **corbeille** (retirer).

```text
corriger   la quantité et la précision, sur place — jamais l'acte : pour changer d'acte,
           on le retire et on en ajoute un autre. « Autres » garde sa description obligatoire.
retirer    fenêtre de confirmation, puis l'acte quitte la liste (choix du propriétaire) ;
           Soft Delete avec auteur et motif (ADR-009) : rien n'est détruit, l'audit garde
           la trace. L'acte demandé à la Réception redevient « à enregistrer ».
```

Une correction est attribuée à son auteur (`edited_by`, `edited_at`, « corrigé par X ») sans
changer l'auteur d'origine ; l'ancienne et la nouvelle valeur sont à l'audit (`Auditable`).

## Qui peut modifier quoi (arbitrage du propriétaire)

**Tout le personnel Maternité du passage** (permission `maternity.procedures.manage`) peut corriger ou
retirer l'acte d'une collègue, **sauf ceux enregistrés par un médecin**, qui restent intacts pour lui.
L'auteur d'un acte le modifie toujours : un médecin garde la main sur les siens.

Le verrou lit un **instantané** : `performed_by_role` fige le rôle de l'auteur **à l'enregistrement**.
Un compte qui change de rôle ensuite ne déverrouille ni ne verrouille rien d'ancien. Les actes déjà
enregistrés reçoivent le rôle actuel de leur auteur — la seule information disponible. Un acte
verrouillé reste visible, avec un cadenas « Médecin » : on le dit, on ne le masque pas.

Le rôle n'est ici qu'une **classification de l'auteur**, jamais une autorisation : la permission
`maternity.procedures.manage` reste exigée pour tout geste. Aujourd'hui le rôle Médecine n'a que
`maternity.request` : un médecin n'enregistre d'actes Maternité que si ce droit lui est accordé à titre
individuel — la règle protège ce cas-là.

## Garde-fous côté serveur

Revérifiés sur la ligne verrouillée (`ModifyMaternityProcedureAction`), jamais seulement à l'écran :
la prise en charge doit être en cours et le passage ouvert ; l'acte doit appartenir à ce passage
(sinon 404, quel que soit l'identifiant envoyé) ; un acte verrouillé refuse la correction comme le
retrait. `can_modify` et `locked_by_physician` sont servis à l'écran, qui ne montre que les gestes
permis.

## Hors périmètre

Aucune restauration d'un acte retiré n'est proposée (il reste à l'audit) ; ajouter à nouveau l'acte
suffit. ~~Un acte retiré n'est pas refacturé ni défacturé : les actes ajoutés en Maternité ne créent
aucun élément facturable (observation de l'ADR-136).~~ **Amendé par l'ADR-141** : un acte Maternité
alimente désormais le compte du patient, et le retirer défacture ce que la Maternité avait elle-même
porté.

---

# ADR-141 — Un acte Maternité enregistré alimente le compte du patient

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire : « ces actes [doivent être]
facturés, basculer vers la Pharmacie, et le patient paie à la Caisse »)

**Amende l'ADR-136 et l'ADR-140**, qui constataient que les actes Maternité ne créaient aucun élément
facturable. Constat vérifié avant de coder : seuls les actes **sélectionnés par la Réception** étaient
facturés (ADR-068) ; un acte enregistré par une sage-femme — insertion d'implant, soins bébé,
consultation de suivi — ne produisait rien, et le patient repartait sans que la clinique ait compté
ce qu'elle avait fait.

## La règle

Elle est celle de l'acte Soins (ADR-054) et de l'examen paraclinique (ADR-105, ADR-109), écrite par
les mêmes classes (`ClinicalActBiller`, `PlannedServiceBilling`) : aucune règle nouvelle.

```text
tarif           résolu côté serveur (RecordBillableItemAction) — jamais saisi ni vu de la sage-femme
échec financier tarif absent, contexte du passage non résolu, politique Personnel non classifiée :
                l'acte est enregistré quand même ; la Réception régularise (ADR-054)
encaissement    exclusivement Réception / Caisse (ADR-012) ; la Maternité n'encaisse rien
```

## Deux origines, dont dépend la suite

`maternity_procedures.billable_item_id` et `billing_origin` disent **qui** a porté l'acte au compte :

```text
OWN      la Maternité l'a facturé à l'enregistrement (clé d'idempotence maternity_procedure:{uuid})
PLANNED  la Réception l'avait déjà facturé à l'arrivée : l'acte s'y rattache, jamais un doublon
```

Un acte redemandé après un premier déjà rattaché est un second acte, et il se facture
(`PlannedServiceBilling::alreadyLinkedIds` compte désormais les actes Maternité ; un acte retiré
libère la facturation de la Réception).

## Corriger ou retirer un acte facturé (amende ADR-140)

```text
retirer      OWN + en attente  -> la facturation est annulée (CancelBillableItemAction)
             PLANNED           -> jamais touchée : c'est celle de la Réception
             déjà sur facture  -> jamais détricotée ici : seule la Réception/Caisse corrige un montant facturé
quantité     OWN + en attente  -> annulée puis refaite à la nouvelle quantité
             sinon             -> refusée, avec un message qui renvoie vers la Réception
précision    corrigeable librement : aucun effet financier
```

## Rien de rétroactif, rien de silencieux

Les actes enregistrés avant ce circuit n'ont aucun élément facturable et n'en reçoivent pas : les
facturer maintenant serait inventer une décision que personne n'a prise. L'écran signale l'état de
chaque acte **sans montant** (ADR-036) : « À la Caisse », « Facturé à l'arrivée », « Sur facture »,
« Non facturable » — et, pour un acte que personne n'a pu chiffrer, « Non facturé — à régulariser par
la Réception » en alerte (ADR-103 : l'échec non bloquant ne doit jamais être muet). Les nouveaux
actes du référentiel n'ont pas de tarif tant que le Super Admin n'en configure pas : ils s'enregistrent
et se signalent « non facturés » jusque-là.

Aucune permission nouvelle.

---

# ADR-142 — Le matériel utilisé en Maternité rejoint le circuit des consommables Soins

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire, question par question)

**Complète l'ADR-072** (consommables Soins ⇄ Pharmacie) sans le dupliquer : la Maternité utilise la
même demande, la même file Pharmacie, la même sortie de stock FEFO, la même facturation à la
Caisse. Seule la **provenance** se distingue (`care_consumable_requests.source_module`, `CARE` par
défaut ou `MATERNITY`, et `maternity_record_id`). `care_orientation_id` désigne l'orientation du
service demandeur — Soins ou Maternité — sans changer de nom : c'est le lien que la file, la
facturation et la sortie de stock lisent déjà. Le service se lit sur l'orientation, jamais sur une
valeur envoyée.

## Ce que la Maternité peut déclarer, et pourquoi ce n'est pas « tout le stock »

L'ADR-072 réservait les Soins à la parapharmacie : « les Soins ne donnent jamais un médicament ». La
Maternité, elle, **pose** de vrais produits — DIU, implant, injectable contraceptif — qui ne sont pas
de la parapharmacie. Elle n'a pas pour autant accès à tout le stock :

```text
Soins       parapharmacie seulement (inchangé)
Maternité   parapharmacie, PLUS les produits que l'administration a explicitement configurés
            comme matériel habituel d'un acte de la Maternité
```

`CareConsumableDirectory::eligibleMedicines(CatalogModule)` porte cette règle **une seule fois**,
lue par la déclaration, le catalogue de l'écran et la configuration. Configurer un produit pour un
acte Maternité (`care_act_consumables`, `catalog.items.update`, ADR-024) accepte tout produit actif et
stockable de la Pharmacie ; le même écran pour un acte de Soins reste limité à la parapharmacie.
**Rien n'est pré-configuré** (choix du propriétaire) : tant que DIU, implant et Sayana Press n'existent
pas en Pharmacie et ne sont pas associés à leur acte, seule la parapharmacie est déclarable.

## Un seul geste

Le matériel part dans le **même envoi** que le panier d'actes (`procedures/batch`, `consumables[]`,
`consumable_notes`) : comme pour l'ADR-072, un second formulaire ferait perdre le matériel dès qu'on
valide sans l'avoir envoyé. Tout ou rien : une ligne de matériel refusée n'enregistre ni acte, ni
facturation, ni demande. Le matériel peut aussi partir seul (consommables du bébé). Le matériel
habituel d'un acte est **suggéré** quand l'acte entre au panier — jamais une règle : la sage-femme
confirme, corrige ou retire, et retirer un acte ne retire que ce qu'il avait apporté et que personne
n'a corrigé.

## Ce qui ne change pas

Sortie de stock par la Pharmacie sans attendre le règlement (amendement de l'ADR-049 posé par
l'ADR-072 : le produit est déjà posé sur la patiente), FEFO sans jamais entamer une quantité
réservée, annulation avec motif tant qu'aucun lot n'a bougé (jamais de suppression, ADR-010),
facturation séparée de l'acte, prix invisible au poste de soins, jamais de paiement à la Pharmacie
(ADR-013). La file Pharmacie affiche l'origine de chaque demande (Soins / Maternité) et la sortie de
stock la porte dans sa destination et son motif.

## Permissions

Aucune nouvelle : `care_consumables.view/request/cancel` (déjà dans le socle `NURSE`, donc pour
`MIDWIFE`) et `care_consumables.serve` (Pharmacie). Le champ n'est pas ignoré en silence : sans
`care_consumables.request`, l'envoi de matériel est refusé (403) et l'écran ne propose ni catalogue ni
suggestion. Audit : `maternity.consumables.request`.

## À faire par la clinique

Créer en Pharmacie les produits concernés (DIU, implant, Sayana Press / Dépo-Provera…), leur donner
un prix de vente, puis les associer à leur acte depuis Administration › Catalogue (icône « Matériel
habituel »). Configurer aussi un tarif pour les actes Maternité récemment ajoutés.

---

# ADR-143 — La Maternité est une section du dossier médical, bébés compris

**Status:** ACCEPTED (2026-09-20 — arbitrage du propriétaire : un seul dossier médical, pas un
dossier Maternité séparé) — **première étape** ; la seconde (le bébé comme patient) reste à décider

**Complète l'ADR-116** (dossier médical imprimable) et **l'ADR-067** (espace Maternité).

## Le constat

Le dossier médical de la clinique (`/passages/{uuid}/dossier-medical`) ne relisait rien de la
Maternité : grossesse, travail, accouchement, actes et nouveau-nés n'existaient que dans
`maternity_records`, visibles seulement de l'écran Maternité. Deux réponses étaient possibles :
fondre la Maternité dans le dossier médical, ou créer un dossier Maternité à part.

## La règle : un dossier, pas deux

Le dossier médical n'a aucune donnée propre : il **relit** ce qui est consigné ailleurs (ADR-116).
Un second dossier obligerait à recopier identité, allergies et constantes — deux copies qui finissent
par se contredire (même défaut que l'ADR-098 corrige ailleurs). La Maternité devient donc une
**section** de la feuille existante, `App\Support\Documents\MaternitySheetSection`, qui relit
`maternity_records` et ses actes sans rien dupliquer.

```text
absente    le passage n'a aucun dossier Maternité : la section n'existe pas (`maternity` = null)
restreinte le dossier existe, le compte n'a pas `maternity.view` : elle se nomme restreinte,
           sans rien laisser filtrer — jamais servie vide, qui se lirait « rien consigné »
servie     grossesse, prénatal, travail, accouchement, soins de la mère, actes réalisés,
           observations, transmission, et un bloc par bébé
```

`maternity.view` est le droit qui garde déjà l'écran Maternité, lequel sert les mêmes données : la
feuille imprimée n'est pas un moyen de le contourner (ADR-054). Aucune permission nouvelle.

## Les bébés

Chaque nouveau-né a son bloc (sexe, poids de naissance, Apgar, état, soins), numéroté quand ils sont
plusieurs : avec des jumeaux, chacun a son état et ses soins (ADR-139). Une fiche de bébé **jamais
remplie** — ouverte d'office pour des jumeaux (ADR-136) — n'est pas listée : ce n'est pas un
nouveau-né consigné. Un Apgar à 0 est une valeur, pas une absence. L'ancienne note commune
`baby_care_notes` reste affichée seulement si elle existe (ADR-139).

**Tant qu'un bébé n'est pas un patient à part entière, son suivi se lit ici, dans le dossier de sa
mère** : il n'a ni numéro, ni passage, ni allergies, ni facture à son nom.

## Une case vide reste vide

`null` pour tout ce que personne n'a rempli — jamais « Non », jamais « Normal » (ADR-077). Les libellés
(Voie basse, Rompues, Féminin…) sont servis par le serveur : l'écran de la feuille ne recopie aucun code.

## Hors périmètre — étape 2, à décider avec la clinique

**Réalisé par l'ADR-144.** Faire du bébé un **patient relié à sa mère** (geste « Créer le dossier du
nouveau-né » à l'accouchement) était la cible probable dès qu'un bébé passe en Pédiatrie, est hospitalisé ou revient en
consultation. Le CDC n'en dit rien et rien n'est inventé : la numérotation, le patient à qui facturer
les soins du bébé, le lien avec la Pédiatrie et la reprise des bébés déjà saisis en JSON restent à
trancher avant toute implémentation.

---

# ADR-144 — Le nouveau-né devient un patient relié à sa mère

**Status:** ACCEPTED (2026-09-20 — arbitrage du propriétaire sur la numérotation et la facturation ;
les autres règles ont été laissées à mon jugement métier et sont signalées ci-dessous comme telles)

**Réalise la seconde étape de l'ADR-143.** Jusqu'ici un bébé n'était qu'une ligne dans le dossier de sa
mère : ni numéro, ni passage, ni allergies, ni dossier à son nom. Dès qu'il passe en Pédiatrie, est
hospitalisé ou revient en consultation, il en faut un.

## Décidé par le propriétaire

```text
numéro         dérivé de celui de la mère : A-26-0009 -> A-26-0009-B1, -B2…
facturation    les soins du bébé restent sur le compte de la mère
```

Le préfixe `B` distingue un bébé d'un passage (`A-26-0009-01`). Le rang est celui de la naissance, pour
que des jumeaux se lisent dans leur ordre ; s'il est déjà pris — le second jumeau a eu son dossier en
premier —, le plus petit rang libre est choisi, et un numéro n'est jamais réutilisé, y compris celui d'un
dossier archivé. Les passages du bébé se numérotent alors à partir du sien : `A-26-0009-B1-01`.

Rien n'est facturé au nom du bébé : aucune règle de facturation ne change. Ses actes et son matériel
consignés en Maternité continuent d'alimenter le compte de la mère (ADR-141, ADR-142).

## Décidé selon la logique métier — à valider

**Création explicite, jamais automatique.** Un bouton « Créer le dossier du nouveau-né » sur la fiche du
bébé, dans le dossier Maternité. Créer un patient à chaque accouchement fabriquerait des identités que
personne n'a validées, et le nom du bébé n'est souvent pas encore connu. Le geste exige la permission
`maternity.newborn.manage` — celle qui garde déjà la fiche du bébé ; aucune permission nouvelle. Une
permission dédiée reste possible si la clinique veut séparer les deux droits.

**La fiche est celle qui est enregistrée.** Le serveur relit le dossier enregistré, jamais l'écran : le
bouton est désactivé tant que le dossier a des modifications non enregistrées. Possible pendant la prise
en charge **ou juste après** (orientation terminée), tant que le passage est ouvert : les bébés déjà
consignés avant cette fonction peuvent ainsi avoir leur dossier.

**Ce que le dossier reçoit.**

```text
date de naissance   celle de l'accouchement consigné — refusé s'il n'est pas renseigné :
                    la naissance n'est jamais devinée, ni remplacée par « aujourd'hui »
sexe                celui de la fiche, M ou F — refusé sinon, avec un message qui le dit :
                    le dossier patient n'a pas d'état « indéterminé » et le code lit ce champ
                    partout. Un bébé de sexe indéterminé ne peut donc pas encore devenir patient.
nom                 saisi par la sage-femme, proposé depuis le nom de la mère : le bébé ne porte pas
                    toujours celui de sa mère. Le prénom est facultatif (le bébé n'est pas toujours
                    prénommé) ; l'identité se corrige ensuite depuis le dossier patient.
```

**Aucun passage n'est ouvert.** Un dossier n'entre dans aucune file tant que le bébé n'a besoin d'aucun
service ; la Réception lui ouvre un passage le moment venu, comme pour tout patient.

**Pas de reprise en masse des bébés existants.** Ils restent dans le dossier de leur mère, et chacun
peut recevoir son dossier par le même geste. Les migrer d'office créerait des patients sans identité
validée. Aucune donnée n'est réécrite.

## Comment le lien tient

`patient_newborn_links` relie le patient à sa mère, au dossier Maternité et à la fiche du bébé. Il vit
dans sa propre table : l'identité permanente du patient ne change pas. Les fiches de bébés forment un
tableau JSON **sans identifiant propre**, et une position change dès qu'une fiche est retirée : le
serveur donne donc à un bébé un `uuid` **au moment où un patient en dépend**, jamais avant. Enregistrer un
dossier ne change rien tant que personne n'en a besoin.

```text
idempotent   unique(dossier Maternité, uuid du bébé) : un double clic retrouve le dossier
             déjà créé — jamais un second patient pour le même enfant
jumeaux      la détection de doublons n'est PAS utilisée : même nom, même date de naissance,
             elle les bloquerait. L'unicité du lien est ici la garde, et elle est exacte.
```

**À l'enregistrement du dossier Maternité** (`SaveMaternityRecordAction`) : un `uuid` que le dossier ne
connaît pas est retiré — un navigateur n'invente pas d'identité —, et **retirer la fiche d'un bébé qui a
déjà son dossier est refusé**, avec un message qui le dit. L'écran reprend l'identité donnée par le
serveur sans écraser une saisie en cours, et masque « Retirer » pour un bébé relié.

## Ce que chaque écran lit

```text
dossier Maternité   « Dossier M-26-7003-B1 » sur la fiche, lien vers le patient (droit patients.view)
dossier patient     l'en-tête dit « Nouveau-né de … » chez le bébé, « Enfants nés à la clinique » chez
                    la mère — rien de clinique n'y passe, seulement qui est relié à qui
détail du passage   la page que l'on ouvre en premier depuis un dossier : un bloc « Nouveau-nés » qui dit
                    combien de fiches le dossier Maternité porte, lesquelles ont déjà leur dossier patient
                    (lien) et lesquelles n'ont jamais été renseignées, avec un accès au dossier Maternité
                    pour créer les autres. Gardé par `maternity.view` ; absent sans dossier Maternité.
dossier médical     chez le bébé, une section « Naissance » : date et heure, rang, poids, Apgar, état,
                    soins, lus chez sa mère. Rien de ce qui est à la mère ne passe : ni grossesse, ni
                    travail, ni mode d'accouchement. Gardée par `maternity.view` (sinon « non visible »).
                    Chez la mère, chaque bloc de bébé porte le numéro de son dossier.
```

## Hors périmètre

Facturation au nom du bébé pour ses **propres** passages futurs : ils suivent le parcours normal de la
Réception (mode financier choisi à l'arrivée), sans règle propre au bébé. Un sexe indéterminé, l'adoption,
la reconnaissance tardive et la correction d'un lien créé par erreur ne sont pas définis par le CDC et ne
sont pas inventés : un lien n'est jamais supprimé, et sa correction exigera son propre mécanisme tracé.


---

# ADR-145 — Le dossier médical d'un patient se lit sans passage ; un seul composant pour les bébés

**Status:** ACCEPTED (2026-09-20 — signalement explicite du propriétaire : « je n'ai pas trouvé le dossier de
bébé comme modèle dossier médical… il faut plus logique, fusionner ce qui est répétitif, UI/UX pro avec shadcn »)

**Complète l'ADR-144** (le nouveau-né devient un patient) et **l'ADR-143** (Maternité = section du dossier
médical). Aucune permission nouvelle, aucune migration.

## Le constat

Le dossier du bébé existait, mais personne ne le trouvait, pour quatre raisons cumulées :

```text
aucun dossier créé      la fiche du bébé vide, le geste de création jamais fait
bouton grisé            « Créer le dossier » vivait dans un <fieldset disabled> : dès la fin de la prise en
                        charge, tout le formulaire — bouton compris — est désactivé
trois endroits          un bloc à la Maternité, un autre au détail du passage, des lignes au dossier patient,
                        chacun réécrit à la main
pas de dossier médical  le modèle papier « DOSSIER MÉDICAL » n'existait que par passage : un bébé n'a aucun
                        passage
```

## Le dossier médical se lit par patient

`GET /patients/{patient}/dossier-medical` (`patients.medical-record.print`, `patients.view`) sert le même
document que `/passages/{episode}/dossier-medical`, sans passage : `MedicalRecordSheet::presentForPatient()`.
La payload rend `episode` nullable ; les constantes, l’hospitalisation et le diagnostic d'un passage s'y
affichent vides plutôt que devinés. Toute section reste gardée par sa permission (ADR-054, ADR-116).

Pour un bébé, la feuille porte un bloc **« Naissance — à la clinique »** (`MaternitySheetSection::birth()`) :
mère, date et heure, rang, sexe, poids, Apgar, état, soins du bébé. **Rien de clinique de la mère n'y figure**
(grossesse, travail, accouchement) : c'est son dossier, pas celui de l'enfant ; sans `maternity.view` la section
dit « Non visible avec vos droits ».

## Onglets Mère · Bébé 1 · Bébé 2

`MaternitySheetSection::dossiers()` sert la famille : la mère (dossier du passage) et chaque bébé dont le dossier
existe. Un onglet de bébé dont le dossier n'est pas encore créé est inactif et le dit. L'onglet courant est marqué
(`aria-current`), le bouton de retour suit d'où l'on vient (`back`). Les onglets ne s'impriment jamais : ils
passent par un slot écran de `PaperSheet`.

## Un seul composant, hors du formulaire verrouillé

`Components/Clinical/NewbornDossiers.vue` remplace les trois blocs écrits à la main : liste des bébés (sexe,
poids, numéro ou « dossier non créé »), **Dossier médical**, **Dossier patient**, et fenêtre de création. Il est
alimenté par `MaternitySheetSection::forPassage()` et utilisé par la Maternité, le détail du passage ; le dossier
patient garde ses lignes « Nouveau-né de… » / « Enfants nés à la clinique » avec leur lien de dossier médical et
un bouton **Dossier médical** dans l'en-tête.

À la Maternité il est placé **avant** le `<fieldset :disabled="readOnly">` : un dossier terminé est en lecture
seule, mais créer le dossier d'un bébé ne modifie pas la fiche de la mère. Tant que la fiche a des modifications
non enregistrées, la création se dit bloquée (le serveur relit l'enregistré, jamais l'écran).

## Le sexe se choisit à la création

`patients.sex` est obligatoire (M/F) et le CDC ne définit aucun « indéterminé » : il n'est pas inventé. Une fiche
sans sexe **n'est donc plus refusée** ; la fenêtre le demande (`Select`, obligatoire) et le serveur l'**écrit dans
la fiche** pour que les deux ne divergent pas. Si la fiche porte déjà un sexe, la fenêtre l'affiche en lecture
seule et le serveur l'emporte. L'erreur porte la clé `sex`. L'écran de la fiche reprend le sexe écrit, sinon un
enregistrement ultérieur l'effacerait. Une fiche entièrement vide ne bloque plus non plus : seuls la date
d'accouchement et le nom sont exigés, comme avant.

## Hors périmètre

Les soins du bébé restent sur le compte de la mère (ADR-144). Aucun passage n'est ouvert pour lui : ouvrir un
passage au bébé, pour une consultation propre, se fait par la Réception comme pour tout patient.

## Amendement du 2026-09-20 — le dossier médical d'un bébé est une feuille de nouveau-né

Signalement du propriétaire : le dossier du bébé reprenait la feuille d'un adulte — situation maritale,
nombre d'enfants, profession, adresse, téléphone, tabac, traitements actuels, antécédents familiaux — toutes
cases sans objet pour un nouveau-né, alors que ses circonstances de naissance n'y figuraient presque pas.

**Ce qui change.** Quand le patient est un nouveau-né de la clinique (`birth` servi), la feuille est composée
pour lui, dans l'ordre d'un dossier de naissance :

```text
identité               nom, né(e) le (date et heure), sexe, lieu de naissance (la clinique et son site,
                       jamais ressaisi), naissance unique ou multiple (« nº 1 sur 2 »)
mère                   personne à joindre : nom, N° de dossier, téléphone, adresse — le bébé n'a ni
                       téléphone ni adresse à lui
naissance et           mode d'accouchement, terme (semaines), complications
accouchement           (gardés par maternity.view, sinon « non visible avec vos droits »)
état à la naissance    poids, Apgar, état, soins du bébé
suivi                  allergies, diagnostic ; poids/taille et hospitalisation seulement s'il a eu un passage
```

Les rubriques d'un adulte n'existent plus dans la feuille d'un bébé (`v-if="!birth"`) ; la feuille de sa mère
reste inchangée.

**Amende l'ADR-144**, qui écrivait « ni grossesse, ni travail, **ni mode d'accouchement** » : le mode
d'accouchement, le terme et les complications de l'accouchement décrivent la **naissance de l'enfant** et sont
lus par tout dossier de naissance ; ils sont donc servis. Restent chez la mère, et ne passent pas : gestité et
parité, facteurs de risque, contexte obstétrical, surveillance du travail, délivrance, soins de la mère. Ce
partage est un choix de lecture, pas une règle du CDC (qui ne décrit aucun dossier de nouveau-né) : à faire
valider par les sages-femmes. Aucune permission nouvelle.

---

# ADR-146 — Le nouveau-né vit dans le dossier de sa mère, et devient patient à l'accueil

> Amendée par l'**ADR-177** (2026-09-23) : le dossier patient du bébé ne s'ouvre plus à la
> Réception mais **depuis la Maternité** ; l'accueil n'a plus de mode « Nouveau-né ».

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire, qui revient sur la création à
l'accouchement : « il faut le bébé rajouter direct dans la dossier de sa mère […] quand arriver à la
prochaine jour pour consulter le bébé la réception interroger l'accouchement chez nous ou externe »)

**Amende l'ADR-144** sur *quand* et *par qui* le bébé devient patient, et **complète l'ADR-145** (le dossier
médical d'un nouveau-né).

## Ce qui était faux

L'ADR-144 créait le patient **à l'accouchement**, par un bouton de la Maternité. C'était mon choix, pas
celui du propriétaire — il l'a laissé à mon jugement, et il était mauvais : un dossier patient était ouvert
pour un enfant dont personne n'avait encore besoin, et son nom n'est souvent pas connu ce jour-là.

## L'ordre réel

```text
accouchement   le bébé est consigné dans le dossier Maternité de sa mère — et c'est tout
                il a son nom (facultatif), son dossier médical, et aucun dossier patient
le jour de sa  la Réception demande : « accouchement chez nous ou ailleurs ? »
consultation     chez nous  -> elle cherche la mère, voit ses bébés, en choisit un : il devient
                              patient à cet instant, et repart dans le parcours d'arrivée
                 ailleurs   -> enregistrement habituel d'un nouveau patient
```

## Le bébé a son dossier avant d'être patient

Une fiche de nouveau-né reçoit son identité (`uuid`) **dès l'enregistrement du dossier Maternité**, et non
plus au moment où un patient en dépend : c'est elle que la Réception retrouve, et elle doit exister avant.
Une fiche jamais remplie n'en reçoit pas — ce n'est pas un bébé consigné, et elle n'apparaît nulle part.
La migration `2026_10_07_090000` donne la leur aux fiches déjà enregistrées, sans rien toucher d'autre.

`GET /passages/{episode}/nouveau-nes/{uuid}/dossier-medical` (`patients.view` **et** `maternity.view`) sert
son dossier médical, lu depuis sa fiche : même feuille de nouveau-né que s'il était patient (ADR-145), avec
« Pas encore patient — le dossier patient s'ouvre à l'accueil, avec son numéro » à la place du numéro. Dès
qu'il est patient, cette adresse redirige vers son dossier patient : il n'y a **qu'un** dossier par bébé.

Les onglets Mère · Bébé 1 · Bébé 2 relient donc toute la famille, que les bébés soient patients ou non.

## Le nom se saisit à la Maternité

`newborn_data.newborns[].first_name` et `.last_name`, facultatifs. `App\Support\NewbornFiche::displayName()`
décide une fois pour toutes comment on nomme un bébé, pour que la Maternité, le dossier médical et la
Réception ne l'écrivent pas chacun à leur manière :

```text
prénom saisi        « RASOA Faly »          (nom saisi, à défaut celui de la mère)
nom seul            « Bébé 2 — ANDRIANINA »
rien                « Bébé 2 de RAKOTO »    jamais un prénom inventé
```

## Ce que la Réception voit, et ce qu'elle ne voit pas

`GET /reception/newborns?mother={uuid}` (`episodes.create`) liste les bébés consignés d'une mère : nom, rang,
sexe, date de naissance, et le dossier patient s'il existe déjà. **Rien de clinique** — ni poids, ni Apgar, ni
soins : un poste de Réception n'a pas `maternity.view`, et ces données ne lui servent pas à reconnaître
l'enfant. Un test le vérifie.

`POST /reception/newborns/{record}/{newbornUuid}/patient` (`episodes.create` **et** `patients.create`) en fait
un patient. Les règles de l'ADR-144 sont inchangées : numéro dérivé de celui de la mère, naissance jamais
devinée (refus si l'accouchement n'est pas consigné), sexe M ou F exigé (donné ici si la fiche ne le porte
pas, et alors écrit sur la fiche), unicité (dossier Maternité, bébé) contre le doublon, jumeaux acceptés.
Aucun passage n'est ouvert : c'est l'arrivée en cours qui le fait.

La création **ne dépend plus d'aucun passage** : le bébé revient des jours plus tard, celui de sa mère est
clos, et cela n'empêche rien — c'était la limite de l'ADR-144, qui exigeait un passage ouvert.

## Ce qui disparaît

Le bouton « Créer le dossier » de la Maternité, sa route
(`/maternity/orientations/{o}/newborns/{index}/patient`) et la fenêtre de saisie du composant partagé. La
sage-femme consigne le bébé ; elle n'ouvre pas de dossier patient. `maternity.newborn.manage` reste le droit
de la fiche du bébé ; le droit d'en faire un patient est celui de l'accueil.

## Ce qui ne change pas

Les soins du bébé consignés en Maternité restent sur le compte de la mère (ADR-144). Un bébé qui a son
dossier patient ne peut toujours pas être retiré du dossier Maternité. La feuille de nouveau-né est celle de
l'ADR-145. Aucune permission nouvelle.

## Amendement du 2026-09-20 — les dossiers ouverts à l'accouchement retournent à la fiche

Constat du propriétaire : les bébés créés par l'ancien geste de la Maternité étaient toujours dans
`/patients`, alors qu'ils n'avaient jamais servi — ni passage, ni facture, ni la moindre ligne à leur nom.

```text
jamais servi   le nom rejoint la fiche du dossier Maternité, le dossier patient est retiré et son
               numéro redevient libre : le bébé retourne chez sa mère, et redeviendra patient à
               l'accueil avec son -B1 (migration 2026_10_08_090000)
a déjà servi   il reste patient : un passage, une facture ou une allergie ne se détruisent pas
               (ADR-010, même raisonnement que l'ADR-062 pour un compte jamais utilisé)
```

Le nom devait être déplacé, pas seulement le dossier : l'ancien geste ne l'écrivait que sur le patient,
et le retirer l'aurait effacé. Une fiche que la sage-femme a nommée depuis garde le sien. La migration
vérifie chaque table qui désigne un patient — la base refuse de toute façon (`RESTRICT`), mais un refus
brut ne dirait pas laquelle — et `visitor_visits`, dont le `SET NULL` effacerait le lien en silence.
Le retour est audité (`maternity.newborn.patient.revert`) : sans cela l'audit dirait qu'un dossier a été
ouvert, jamais ce qu'il est devenu. Aucun retour arrière : rouvrir ces dossiers recréerait des patients
que personne n'a demandés, avec des numéros qu'un autre bébé a pu recevoir depuis.

**Un bébé accueilli reste dans le répertoire**, comme tout patient : il a son numéro et ses passages, et
on le cherche par son nom. Sa ligne dit seulement de qui il est l'enfant — « Nouveau-né de RAKOTO Vola »,
qui mène au dossier de sa mère —, sinon lui et elle se lisent comme deux dossiers sans rapport. Le masquer
aurait rendu introuvable, par son nom, l'enfant de six mois qui revient en consultation.

## Amendement du 2026-09-20 (bis) — le dossier de la mère montre ses bébés, le répertoire dit lesquelles ont accouché ici

Constat du propriétaire : depuis que le bébé n'est plus patient d'office, le dossier de sa mère ne le montrait
plus — `family.children` ne lisait que les dossiers patients, et un bébé consigné à la Maternité n'en a pas
avant son premier accueil. Il n'apparaissait donc nulle part dans le dossier permanent de sa mère.

```text
dossier de la mère   une carte « Nouveau-nés nés à la clinique » : nom, date de naissance, « Pas encore
                     patient » ou son numéro, et son dossier médical
répertoire           « 1 bébé né ici » sur la ligne de la mère — ce que la Réception cherche à chaque
                     arrivée d'un nouveau-né, sans ouvrir chaque dossier
```

Les bébés sont lus sur les **fiches** (`MotherNewborns::for()`, la même liste que l'arborescence de la
Réception), jamais sur les seuls dossiers patients : une seule définition de « les bébés d'une mère ». Une
fiche ouverte d'office pour des jumeaux et jamais remplie n'en est pas un — elle n'est ni comptée, ni listée.

**Ce qui exige `maternity.view`, et ce qui ne l'exige pas.** Le nom, le rang et la date de naissance d'un
bébé sont son identité, pas une donnée clinique : ils sont servis avec `patients.view`, comme la Réception
les voit déjà dans son arborescence sans ce droit. Le **dossier médical** d'un bébé pas encore patient se lit
sur la fiche du dossier Maternité : sans `maternity.view`, le bébé est nommé mais son dossier n'est pas
proposé — un lien qui mène à un refus vaut moins qu'une absence de lien. Le dossier d'un bébé déjà patient
reste ouvert à `patients.view` : c'est un dossier patient comme un autre.


## Amendement du 2026-09-20 (ter) — le nouveau-né a ses propres droits

Le paragraphe ci-dessus faisait reposer la lecture du dossier d'un bébé sur `maternity.view`. Constat du
propriétaire : **la Réception ne pouvait donc pas ouvrir le dossier d'un nouveau-né depuis celui de sa
mère** — le bouton n'était même pas affiché. La cause n'est pas un oubli d'attribution : `maternity.view`
est le droit de l'**espace Maternité**, qu'aucun socle de rôle ne porte et que seul le profil sage-femme
reçoit en exception individuelle (ADR-067). Ni Médecine, ni Soins, ni la Réception ne le détiennent.

Le raccourci était faux : lire la naissance d'un enfant n'est pas travailler dans le dossier obstétrical de
sa mère. Trois droits nomment désormais les trois gestes réels, identité et clinique séparées :

```text
newborns.view                  l'enfant : nom, rang, sexe, date de naissance, s'il est patient
newborns.medical_record.view   son dossier : naissance, poids, Apgar, état, soins, mode d'accouchement
newborns.patient.create        en faire un patient, à l'accueil
```

Chacun est réellement vérifié par le code (ADR-101) — route, projection serveur et lien affiché : une
permission que rien ne contrôle est un interrupteur qui ne commande rien.

```text
newborns.view                  carte « Nouveau-nés » du dossier de la mère, « Nouveau-né de … » et
                               « N bébés nés ici » au répertoire, arborescence de la Réception,
                               bloc « Nouveau-nés » du détail du passage et du dossier Maternité
newborns.medical_record.view   route /passages/{passage}/nouveau-nes/{bébé}/dossier-medical,
                               bloc « Naissance » de la feuille, poids affiché à côté de l'identité,
                               et le lien lui-même — jamais proposé à qui recevrait un refus
newborns.patient.create        POST /reception/newborns/{dossier}/{bébé}/patient
```

`maternity.view` garde exactement ce qui lui appartient : l'espace Maternité et la section Maternité du
dossier de la **mère** — grossesse, travail, accouchement. Rien n'y est retiré.

**Socle de départ, et ce qui reste une décision.** `RECEPTION` reçoit `newborns.view` et
`newborns.patient.create` — elle accueille l'enfant et doit le retrouver chez sa mère. `MEDICINE` et
`NURSE` reçoivent `newborns.view` et `newborns.medical_record.view` : le médecin qui reçoit un nouveau-né
lit sa naissance, et c'est un élargissement volontaire, signalé plutôt que silencieux — aujourd'hui aucun
des deux ne pouvait ouvrir cette feuille.

`newborns.medical_record.view` n'est **pas** accordée à `RECEPTION` (arbitrage explicite du propriétaire,
2026-09-20) : le poids, l'Apgar et le mode d'accouchement sont cliniques. Le Super Administrateur la coche
dans « Rôles & permissions » › catégorie **Nouveau-nés** s'il le décide, et cette décision est alors tracée
(ADR-064) au lieu d'être prise une fois pour tous les sites.

**Sans le droit, jamais un vide muet.** Sans `newborns.view`, la carte, les repères du répertoire et le
bloc du passage ne sont pas servis du tout — jamais servis vides, qui se liraient « cette patiente n'a pas
accouché ici ». Sans `newborns.medical_record.view`, le bébé reste nommé — son identité n'est pas clinique
— mais son dossier n'est pas proposé, et la feuille ouverte par un autre chemin écrit le droit qui manque.
Le dossier d'un bébé **déjà patient** reste ouvert à `patients.view` : c'est un dossier patient comme un
autre.

La migration `2026_10_09_090000_create_newborn_permissions` enregistre les trois droits et ces
attributions — un site en production ne rejoue plus `RolePermissionSeeder` (ADR-064). Elle recopie aussi
`newborns.view` et `newborns.medical_record.view` sur les comptes qui détenaient déjà `maternity.view` en
exception `ALLOW`, pour ne rien retirer à une sage-femme. Un `DENY` n'est jamais recopié : refuser l'espace
Maternité n'a jamais voulu dire refuser la naissance d'un enfant.

## Amendement du 2026-09-22 — « né ailleurs » n'est pas un formulaire d'adulte

À l'accueil d'un nouveau-né, les deux branches restent explicitement différentes :

```text
né à la clinique   chercher la mère et choisir sa fiche de bébé ; aucun formulaire Patient n'est affiché
né ailleurs        créer le dossier avec l'identité propre au bébé uniquement
```

Le formulaire externe demande nom, prénom, naissance/âge, sexe et, si utile, domicile familial. Il ne demande
ni téléphone ni email propres au bébé, ni civilité, pièce d'identité, profession, situation maritale ou nombre
d'enfants. Le parent ou responsable est saisi comme **contact de l'Épisode** (`emergency_contact_*`), jamais
comme coordonnées permanentes du bébé (ADR-034). `registration_context=EXTERNAL_NEWBORN` est un marqueur de
validation de la requête, pas un attribut du Patient : le serveur refuse ces champs d'adulte même si un navigateur
les envoie directement. Cette adaptation précise « enregistrement d'un nouveau patient » sans modifier le parcours
du bébé né à la clinique ni créer une nouvelle catégorie financière.

La même règle s'applique au formulaire général lorsque la civilité choisie est `Enfant fille` ou
`Enfant garçon` : le serveur conserve la civilité, l'identité, la naissance, le sexe et le domicile familial,
mais refuse téléphone, email, profession, pièce d'identité, situation maritale et nombre d'enfants. Ces valeurs
ne sont pas remplacées par des zéros ni par celles du parent. Le parent ou responsable reste le contact du passage.

---

# ADR-147 — Diagnostic de sortie porté par le séjour, et Réception en lecture sur l'Hospitalisation

**Status:** ACCEPTED (2026-09-20 — deux constats du propriétaire sur le même écran ;
arbitrage explicite sur le domicile du diagnostic)

**Complète l'ADR-113** (module Hospitalisation) sans modifier une seule de ses règles
d'admission ou de sortie.

## Le défaut : la sortie était impossible

Sur le séjour `48d1a300`, le formulaire affichait « Aucun diagnostic posé : ajoutez-le
dans « 1 · Diagnostic » ci-dessus » et laissait « Confirmer la sortie médicale » grisé.
Deux erreurs dans cette seule phrase :

```text
le passage AVAIT 2 diagnostics actifs   la page ne les servait pas au formulaire
« 1 · Diagnostic ci-dessus »            cet écran n'existe qu'en consultation
```

`ClinicalDischargeForm` exige un diagnostic coché (`requires-diagnosis`, ADR-113 : un
séjour n'est jamais un passage paraclinique seul), la page ne lui passait aucun
`diagnoses`, et aucun moyen d'en poser un depuis là. **Aucune sortie d'hospitalisation
n'était donc prononçable.**

## Ce que la sortie reçoit désormais

`HospitalizationController::diagnoses()` sert les diagnostics **déjà consignés** — ceux
des consultations du passage et ceux du séjour — avec leur origine, leur auteur et leur
date. Ils arrivent cochés d'office : rien n'est ressaisi (§17). Un diagnostic annulé par
son auteur (ADR-035) n'en est plus un et n'est pas servi. La liste n'est servie qu'avec
`diagnoses.view` — la feuille n'ouvre pas ce que le reste de l'application garde.

L'invite du formulaire devient un `prop` (`diagnosisHint`) : partagé par la consultation
et le séjour, il ne peut plus renvoyer à l'écran de l'autre.

## Le diagnostic conclu au terme du séjour appartient au séjour

Un `Diagnosis` appartient à une `Consultation` (ADR-035), et celle qui a demandé
l'hospitalisation est le plus souvent **close** bien avant la sortie — sur le cas signalé,
clôturée le 19/09 à 15:22 alors que le séjour courait encore. L'ADR-076 refuse alors toute
écriture ordinaire, et l'ADR-096 n'ouvre la réouverture que pour **corriger** une
rencontre conclue.

Or ce que le médecin conclut après plusieurs jours de séjour n'est pas une correction de
cette consultation : c'est un fait clinique du séjour. Trois issues ont été posées au
propriétaire ; il a retenu celle qui n'amende aucune décision existante :

```text
hospital_stay_diagnoses   le diagnostic de sortie, sur le séjour
                          append-only, avec son auteur et sa date
la consultation close     jamais réécrite — l'ADR-076 reste intacte
```

`RecordHospitalStayDiagnosisAction` exige le même droit que le diagnostic d'une
consultation (`diagnoses.create`) : conclure un séjour n'est pas une autre autorité que
conclure une rencontre. Même règle de catalogue que l'ADR-035 — une entrée du référentiel
avec ses instantanés (code, libellé), ou un libellé saisi, jamais les deux à vide — et le
même refus du doublon. Le modèle refuse la mise à jour et la suppression : une erreur se
corrige par une nouvelle ligne (ADR-010, ADR-035).

**Un séjour terminé n'en accepte plus.** Sa sortie est prononcée, donc sa conclusion est
signée ; la FormRequest et l'Action le refusent toutes les deux — l'interface n'est jamais
la seule garde.

## La Réception lit le module, et n'y écrit rien

`hospitalization.view` rejoint le socle `RECEPTION` : elle ouvre le détail, le dossier du
passage et l'impression de la fiche de régime — ce que l'accueil a réellement à faire.
Elle reçoit 403 sur tout le reste, sans exception :

```text
lit       /hospitalisation, le détail, /regime/impression
refusé    sortie (medical_discharge.create), service et chambre (hospitalization.update),
          fiche de régime (hospital_diet.record), demande (hospitalization.request),
          diagnostic (diagnoses.create)
```

Rien n'a eu à être verrouillé pour cela : l'écran calculait déjà ses capacités droit par
droit et chaque route porte la sienne. Sans `diagnoses.view`, la liste des diagnostics
n'est pas servie du tout — jamais servie vide, qui se lirait « aucun diagnostic posé ».

Un poste d'accueil qui doit faire davantage le reçoit du Super Administrateur depuis
« Rôles & permissions » (ADR-064) ou par exception individuelle auditée (ADR-022) : le
module suit alors le droit accordé, sans autre changement de code.

La migration `2026_10_10_090000_create_hospital_stay_diagnoses` crée la table et accorde
`hospitalization.view` à `RECEPTION` — un site en production ne rejoue plus
`RolePermissionSeeder` (ADR-064).

## Hors périmètre

Aucun diagnostic de séjour n'est repris par les propositions de l'ADR-111 : elles
apprennent des consultations, et étendre leur source est une décision distincte. Un
diagnostic de sortie posé par erreur ne s'annule pas encore — il se corrige par une
nouvelle ligne ; une annulation tracée comme celle de l'ADR-035 reste à décider.

---

# ADR-148 — Visite de service : une vraie rencontre pendant le séjour

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire : « pendant
l'hospitalisation un patient peut voir une consultation encore, des examens, ordonnance…
comment peut tout faire ça dans la page d'hospitalisation ? », arbitrage sur la forme)
; **la visite de service est retirée par l'ADR-162** (2026-09-21) : tout se fait
sur la page du séjour, les visites déjà ouvertes restent lisibles.

**Construit** ce que l'ADR-113 avait explicitement laissé hors périmètre (« visites de
service, prescriptions propres au séjour ») et **complète l'ADR-147**.

## Le trou

Un patient hospitalisé est examiné chaque jour, prescrit, envoyé au laboratoire. Rien de
tout cela n'était possible : le séjour ne portait qu'une fiche de régime, et la
consultation qui a demandé l'hospitalisation est le plus souvent close (ADR-076) — donc en
lecture seule. Le médecin n'avait aucun endroit où travailler.

## Une visite **est** une consultation

Trois formes ont été posées au propriétaire ; il a retenu la rencontre réelle, et c'est
aussi la seule qui n'invente rien :

```text
diagnostic, ordonnance (réservation FEFO), analyses, imagerie, ordre de soins,
facturation, brouillon serveur, droits  →  tout existe déjà, rattaché à une Consultation
```

`StartHospitalVisitAction` n'écrit donc aucun circuit nouveau : elle enchaîne
`CreateEpisodeOrientationAction` (`HOSPITALIZATION → MEDICINE`) puis
`AcceptMedicineOrientationAction`, exactement comme la Maternité qui renvoie au médecin
(ADR-135), et redirige vers l'assistant existant. Aucune table, aucun écran dupliqué.

**Le patient n'apparaît jamais dans la file d'attente.** L'orientation est prise en charge
d'emblée (`IN_PROGRESS`, `accepted_by`) : il est dans un lit, pas dans la salle d'attente.

**Il reste hospitalisé.** `AcceptMedicineOrientationAction` remet le passage « en soins » —
juste pour une arrivée ordinaire, faux ici : le statut médical est rétabli à
`HOSPITALIZED`, qui n'appartient qu'à la sortie médicale (ADR-113). Une visite ne fait pas
descendre du lit. Le `refresh()` qui précède n'est pas cosmétique : l'instance en mémoire
portait encore l'ancienne valeur, donc la réécrire n'aurait produit aucun UPDATE.

**Jamais deux visites ouvertes.** L'`active_key` d'`EpisodeOrientation` rend l'ouverture
idempotente : rouvrir retrouve la visite en cours au lieu d'en créer une seconde. L'écran
propose alors « Reprendre la visite en cours ».

**Un séjour terminé n'en ouvre plus** : sa sortie est prononcée, donc sa conclusion est
signée — refusé par l'Action, jamais seulement par l'écran.

## Permissions

Aucune nouvelle. Ouvrir une visite relève de `consultations.create` — la même autorité que
prendre un patient en charge en Médecine —, la liste de `consultations.view`. La Réception
(ADR-147) lit le séjour et n'ouvre aucune visite ; sans `consultations.view` la liste n'est
pas servie du tout, jamais servie vide.

## Le bloc de sortie est regroupé par sujet

Même écran, second constat du propriétaire : la sortie s'étalait sur une colonne unique où
le regard ne rattachait plus une case à sa question — « Contrôle » occupait à lui seul une
rangée pleine largeur, et la moitié droite restait vide. Le formulaire, partagé par la
consultation, la Pédiatrie et l'Hospitalisation, tient désormais en trois sections
encadrées :

```text
1 · Décision                    type de sortie et sa date, jamais séparés
2 · Conclusion médicale         diagnostic final et état du patient
3 · Consignes remises au patient  traitement, conseils et contrôle sur une rangée
```

Aucun champ n'est retiré, aucune valeur envoyée au serveur ne change, aucune règle de
validation ni de complétude n'est touchée : c'est une mise en page (ADR-099).

## Hors périmètre

Le tour de salle en lot (plusieurs patients d'affilée), la prescription permanente d'un
séjour reconduite chaque jour et la gestion des lits restent non définis par le CDC et ne
sont pas inventés.

---

# ADR-149 — Une visite de service se conclut « poursuite de l'hospitalisation »

**Status:** ACCEPTED (2026-09-20 — signalement explicite du propriétaire : « quand arriver
dans l'étape Décision & clôture, comment peut faire ? le patient déjà en hospitalisation,
donc on clique quoi pour Conduite à tenir ? »)

**Complète l'ADR-148** (la visite de service) et **l'ADR-084** (la conduite à tenir).

## Le cul-de-sac

Une visite ouverte depuis le séjour atteignait « Décision & clôture » et n'avait aucune
issue. La clôture exige une conduite à tenir **transmise** (ADR-084), et les six
destinations proposées étaient toutes fausses pour un patient déjà au lit — deux
dangereusement :

```text
Hospitalisation   ouvrirait un SECOND séjour sur le même passage
Sortie médicale   prononcerait une sortie sans terminer le séjour, qui resterait
                  ACTIVE avec un passage médicalement sorti
Chirurgie, Maternité, Pédiatrie, Référence   possibles, mais rares
Poursuivre l'évaluation                      ne transmet rien, donc ne clôture pas
```

La visite était donc impossible à clôturer. C'est le symétrique du défaut corrigé par
l'ADR-147 : un écran qui réclame une décision dont aucune option n'est juste.

## Une septième conduite, qui dit ce qui se passe vraiment

`ConsultationOrientationType::ContinuedHospitalization` — « Poursuite de
l'hospitalisation » — est la conclusion normale d'un tour de salle : le patient reste dans
son lit.

Elle est **transmise au moment du choix**, sans formulaire. Ce n'est pas un raccourci :
elle ne demande rien à personne, le séjour est déjà ouvert et le patient déjà admis. Le
fait est acquis, exactement comme une sortie déjà prononcée que `attachPronouncedDischarge`
rattache depuis l'origine plutôt que de réclamer une demande qui existe déjà.

```text
destinationModule   null — aucune orientation nouvelle, celle du séjour est ouverte
permission          consultations.update — écrire sa consultation suffit à le décider,
                    rien n'est demandé à un autre service
legacyDecision      ConsultationDecision::Hospitalization — il l'est, et le reste ;
                    aucun lecteur n'a de second vocabulaire à apprendre (§32)
```

## Ce que l'écran propose, et ce qu'il retire

`MedicineDossierPresenter::orientationApplies()` décide **côté serveur**, jamais dans Vue :

```text
patient hospitalisé    ni « Hospitalisation », ni « Sortie médicale »
                       + « Poursuite de l'hospitalisation »
patient non hospitalisé  les six d'origine ; « Poursuite » n'y veut rien dire
```

Retirer « Sortie médicale » n'enlève aucune possibilité : elle se prononce depuis la page
du séjour, et **elle seule** y termine le séjour et la prise en charge ensemble
(`DischargeHospitalStayAction`, ADR-113). L'écran le dit en toutes lettres, avec le lien —
une option retirée sans explication se lit comme une fonction manquante.

## Le patient hospitalisé se signale partout

`hospital_stay` est servi à la consultation (avec `hospitalization.view`) : un repère
« Hospitalisé · chambre » dans l'en-tête condensé, et un bandeau dans la carte de conduite
à tenir. On ne conclut pas une rencontre de la même façon selon que le patient rentre chez
lui ou reste au lit ; l'écran ne doit pas laisser l'oublier.

## Ce qui ne change pas

Aucune permission nouvelle. La clôture continue d'exiger un diagnostic (CDC §33.1,
ADR-081) et une conduite à tenir transmise (ADR-084) ; seule s'ajoute celle qui manquait.
Le séjour, le lit et le statut `HOSPITALIZED` ne sont pas touchés par la clôture d'une
visite — seule la sortie médicale les termine (ADR-113).

---

# ADR-150 — Un socle de rôle ne dit pas à lui seul ce qu'un compte peut faire

**Status:** ACCEPTED (2026-09-20 — signalement du propriétaire : « pourquoi le rôle
RECEPTION a toujours la permission d'éditer et le bouton prononcer la sortie ? »)

**Complète l'ADR-101** (l'écran « Rôles & permissions ») sans modifier une seule règle de
résolution des droits.

## Ce qui a été constaté, et ce que c'était réellement

L'écran montrait « Socle du rôle Réception / Caisse · Demande d'hospitalisation **0/3** »,
aucune case cochée — et le compte connecté voyait pourtant le module, les crayons de la
fiche de régime et « Prononcer la sortie ».

Vérification faite en base : **le socle n'y était pour rien.**

```text
socle RECEPTION           aucune permission hospitalization.* ni medical_discharge.*
compte user@rivo.test     122 ALLOW et 166 DENY individuels, dont hospitalization.view,
                          hospitalization.update, hospital_diet.record,
                          medical_discharge.create
```

C'est le compte de développement de l'ADR-086, créé une fois avec les permissions de tous
les rôles opérationnels pour qu'une seule connexion parcoure tout le circuit. La
résolution a fonctionné exactement comme l'ADR-033 la définit :

```text
DENY individuel  >  ALLOW individuel  >  socle du rôle
```

Un `ALLOW` nominatif l'emporte donc sur un socle vide. Rien n'était cassé.

L'audit montre par ailleurs que le socle `RECEPTION` a été réenregistré quatre fois
depuis le portail le même jour (`role.permissions.update`, rôle 6). Chaque
enregistrement remplace le socle entier (`sync`, ADR-064) : le `hospitalization.view`
que la migration de l'ADR-147 avait accordé a été retiré par l'un d'eux. C'est une
décision d'administration, pas un défaut — elle n'est pas rétablie ici.

## Le vrai défaut était de présentation

L'écran laissait lire « socle à 0 » comme « personne n'y a accès ». Il ne disait nulle
part que, parmi les comptes du rôle, certains portent des exceptions qui l'emportent —
et un accès parfaitement réglementaire passait donc pour un bug.

`users_with_exceptions_count` accompagne désormais chaque rôle, et l'éditeur de socle
l'affiche :

> **1 compte** de ce rôle porte des exceptions individuelles, qui l'emportent sur ce
> socle. Un droit décoché ici peut donc rester ouvert pour lui — vérifiez dans
> « Exceptions par compte ».

C'est la même règle que partout ailleurs dans ce dossier : ce qu'un écran ne dit pas se
lit comme une absence (ADR-102 pour un chiffre manquant, ADR-103 pour une facturation
avalée, ADR-149 pour une option retirée). Un compteur, pas une interdiction : régler le
socle reste libre, et l'écran rappelle seulement qu'il n'est pas seul à décider.

## Ce qui ne change pas

Aucune permission, aucune route, aucune règle de résolution. Le compteur est calculé par
une seule requête agrégée avec les comptes déjà servis — le temps de l'écran ne dépend
pas du nombre de comptes du site.

**Pour retirer réellement ces droits au compte de test**, c'est « Exceptions par compte »
qu'il faut ouvrir, pas le socle. Un vrai compte de Réception, lui, n'a que son socle.

---

# ADR-151 — Un droit se cherche sous le nom que l'écran lui donne

**Status:** ACCEPTED (2026-09-20 — signalement du propriétaire : « je ne vois pas cette
permission dans la catégorie ; normalement on peut retirer ce droit au rôle Réception »)

**Complète l'ADR-101** (le catalogue des permissions) et **l'ADR-150**. Aucune règle de
résolution des droits, aucune route, aucune migration de schéma.

## Le constat

Le bouton « Prononcer la sortie » d'un séjour est gouverné par
`medical_discharge.create` — libellé « Prononcer une sortie médicale », catégorie
« Sortie médicale ». Chercher « hospitalisation » dans le catalogue ne le trouvait donc
**jamais**. Depuis le portail, on voyait le droit à l'œuvre sans pouvoir le retirer.

Ce n'était pas un droit caché : c'était un droit nommé d'après le module qui l'a créé,
pas d'après les écrans où il agit. Trois permissions sont dans ce cas depuis que leur
portée s'est étendue (ADR-113, ADR-114, ADR-147, ADR-148) :

```text
medical_discharge.create   consultation, sortie d'hospitalisation, pédiatrie
consultations.create       file Médecine, et visite de service (ADR-148)
diagnoses.create           consultation, et sortie d'hospitalisation (ADR-147)
```

## Le libellé nomme les modules, le nom ne bouge pas

Seuls les **libellés** changent — le `name` est ce que le code écrit en clair
(`can:medical_discharge.create`) et ne change jamais (ADR-101). Le libellé, lui, est la
phrase que lit la personne qui coche la case : il doit dire où le droit agit réellement.

La catégorie suit la même règle : « Sortie médicale (consultation, hospitalisation,
pédiatrie) ». Le rail se cherche sur le libellé **et** le code (ADR-101), donc
« hospitalisation » y ramène désormais la catégorie comme le droit.

La migration `2026_10_11_090000` applique les trois libellés sur un site déjà installé —
un site en production ne rejoue plus `PermissionSeeder` (ADR-064). Elle n'a pas de
retour arrière : rétablir des libellés qui ne décrivaient qu'un module sur trois
remettrait le droit hors de portée de la recherche.

## Ce que cela ne résout pas, et qui reste vrai

Dans le cas signalé, le droit ne venait **toujours pas** du socle `RECEPTION` — qui n'a
ni `medical_discharge.*` ni `hospitalization.*` — mais des exceptions individuelles du
compte de développement (ADR-150). Le retirer se fait dans « Exceptions par compte ».
Nommer correctement le droit ne change pas qui le détient ; cela rend seulement possible
de le chercher, et donc de le retirer là où il est réellement accordé.

---

# ADR-152 — Le droit décide, jamais le rôle ; la cohérence du dossier décide du reste

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire : « la Réception
peut voir hospitalisation, sortie… si on donne le droit ! ne fixe pas en dur »)

**Complète l'ADR-149** et confirme l'ADR-007 sur le module Hospitalisation.

## Ce que la vérification a établi

Aucun rôle n'est codé en dur dans le module : ni dans le contrôleur, ni dans les Actions,
ni dans les FormRequests, ni dans le presenter. Chaque capacité servie à l'écran est un
`$user->can(...)`, et chaque route porte sa permission.

Un compte de Réception à qui l'on accorde `hospitalization.update`,
`hospital_diet.record`, `diagnoses.create`, `consultations.create` et
`medical_discharge.create` fait donc **exactement** ce que ces droits disent — chambre et
lit, fiche de régime, diagnostic de sortie, visite de service et sortie médicale
comprises. Un test le prouve en accordant ce socle au rôle `RECEPTION` et en exécutant les
cinq gestes ; il échoue si quiconque code un rôle en dur à l'avenir.

## Ce qui reste refusé, et pourquoi ce n'est pas une question de droit

L'ADR-149 retire « Sortie médicale » de la conduite à tenir d'une consultation pendant un
séjour. Ce filtrage était **la seule protection** — c'est-à-dire aucune : l'interface
n'est jamais une garde (ADR-093, ADR-107). Prononcée depuis une consultation, la sortie
créait une `MedicalDischarge` sans terminer le séjour, qui restait `ACTIVE` avec un
passage médicalement sorti.

`RecordMedicalDischargeAction` refuse désormais tant qu'un séjour est actif, avec le
message qui dit où aller :

> Ce patient est hospitalisé : sa sortie se prononce depuis la page du séjour, qui seule
> termine le séjour et la prise en charge ensemble.

Ce refus n'est pas une restriction de droit et ne regarde ni le rôle ni les permissions :
il vaut pour un médecin comme pour un poste d'accueil à qui tout aurait été accordé.
C'est une règle de cohérence du dossier, du même ordre que « un seul séjour actif par
passage » (`active_key`) ou « une seule sortie médicale par passage » — deux refus qui
existaient déjà juste au-dessus dans la même Action.

`DischargeHospitalStayAction` n'est pas concernée : elle écrit sa `MedicalDischarge`
directement et termine les deux ensemble. Le chemin légitime reste ouvert, exactement
comme avant.

## La règle, en une ligne

```text
qui peut faire quoi      les permissions, toujours, et elles seules
ce qui reste impossible  ce qui laisserait le dossier incohérent, pour tout le monde
```

---

# ADR-153 — Un droit refusé au compte se voit sur la case du socle

**Status:** ACCEPTED (2026-09-20 — constat du propriétaire : « déjà coché
`hospitalization.view`, enregistré le socle, mais rien ne s'affiche dans le compte
Réception »)

**Complète l'ADR-150**. Aucune règle de résolution des droits, aucune permission, aucune
route, aucune migration.

## Ce qui s'est réellement passé

L'enregistrement du socle avait parfaitement fonctionné :

```text
socle RECEPTION            hospitalization.view  ✓ accordé
compte user@rivo.test      hospitalization.view  ✗ DENY individuel
```

`DENY individuel > ALLOW individuel > socle du rôle` (ADR-033) : le refus nominatif
l'emporte, et l'entrée de menu reste absente. Rien n'était cassé — mais rien, à l'écran,
ne permettait de le comprendre. La case était cochée, le socle enregistré, et l'effet
nul.

Le piège tient à un détail de l'écran « Exceptions par compte » : il a **trois** états —
*Selon le rôle* (aucune ligne), *Autorisé*, *Interdit*. « Interdit » n'est pas
« retirer l'exception » : c'est un refus actif, qui bat le socle. Un nettoyage fait avec
« Tout interdire » au lieu de « Revenir au socle du rôle » produit exactement ce
résultat.

## Le repère

L'ADR-150 avait ajouté, en tête de l'éditeur, « N comptes de ce rôle portent des
exceptions individuelles ». C'était vrai mais trop vague : cela ne disait pas **quel**
droit, ni **pour qui**.

Chaque case du socle porte désormais son propre repère quand des comptes du rôle la
refusent :

> ⚠ **Refusé à 1 compte** — *le cocher ici ne l'ouvrira pas pour lui.*

Il est calculé dans le navigateur, à partir des comptes et de leurs exceptions que
l'écran reçoit déjà (`users`, `permission_overrides`) : aucune requête, aucun champ
nouveau au serveur. Le repère ne compte que les comptes **du rôle réglé** — un refus
porté par un compte d'un autre rôle ne dit rien de celui-ci.

C'est la même règle que partout dans ce dossier : ce qu'un écran tait se lit comme une
absence d'effet, et fait conclure au défaut (ADR-102, ADR-103, ADR-149, ADR-150).

## Ce que cela ne fait pas

Le repère n'interdit rien et ne modifie aucune exception : régler le socle reste libre,
et lever un refus nominatif se fait là où il a été posé — « Exceptions par compte »,
en remettant le droit à **« Selon le rôle »**, jamais à « Autorisé », pour que le socle
redevienne la source.

---

# ADR-154 — Un refus dit ce qui manque, et où le régler

**Status:** ACCEPTED (2026-09-20 — signalement du propriétaire, après trois échanges sur
le même 403)

**Complète l'ADR-153**. Aucune permission, aucune route, aucune règle de résolution.

## Le constat

`403 · Cette action n'est pas autorisée.` Rien d'autre. Le droit était pourtant coché au
socle du rôle : impossible, depuis cet écran, de comprendre que le compte portait un
`DENY` nominatif qui l'emporte (ADR-033). Le refus était exact et se lisait comme un
défaut de l'application — trois messages pour l'établir.

## Le message

`RequiredAbilities` lit les capacités que la **route réellement appariée** exige par son
`can:` — jamais devinées d'après l'URL — et ne garde que celles qui manquent au compte.
Deux messages, parce que les deux cas ne se règlent pas au même endroit :

```text
droit non accordé      « Il vous manque le droit « … » (nom). Il s'accorde dans
                       Rôles & permissions : au socle du rôle, ou en exception. »
refus nominatif        « Ce droit vous est refusé personnellement : … Le refus
                       individuel l'emporte sur le socle du rôle, même coché. Il se
                       lève dans Exceptions par compte, en remettant le droit sur
                       « Selon le rôle ». »
```

Le second est tout l'intérêt : cocher la case du socle sur un droit refusé
personnellement ne produit rien, et c'est exactement le piège rencontré.

Nommer la permission n'expose rien. L'application le fait déjà partout — « Non visible
avec vos droits (`maternity.view`) » (ADR-116, ADR-145) — et le destinataire est un
professionnel authentifié de la clinique.

## `map()`, et non `render()`

Le handler convertit l'`AuthorizationException` en `HttpException` **avant** de consulter
les callbacks de rendu : un `$exceptions->render(AuthorizationException …)` n'est donc
jamais appelé, et l'a été silencieusement pendant la mise au point. `mapException()`
s'exécute en premier (`Handler::render()`, première ligne) : c'est le seul point où
l'exception est encore reconnaissable.

## Portée

Le message vaut pour **toutes** les routes gardées par `can:` — Pharmacie, Caisse,
Laboratoire, portail — pas seulement l'Hospitalisation. Aucune autorisation n'est
assouplie : ce qui était refusé l'est toujours, il est seulement dit pourquoi.

---

# ADR-155 — Une visite de service ouverte retient la sortie d'hospitalisation

**Status:** ACCEPTED (2026-09-20 — signalement du propriétaire : « j'ai déjà
prononcé la sortie, mais Visites de service reste toujours En cours »)

**Complète l'ADR-148** (la visite de service) et **l'ADR-113** (la sortie
termine le séjour). Aucune permission, aucune route, aucune migration.

## Le constat

Sur le séjour signalé, la sortie était prononcée à 21:28 et la visite de
20:08 affichait toujours « En cours ». Ce n'était pas un défaut d'affichage :

```text
DischargeHospitalStayAction  termine l'orientation DU SÉJOUR
la visite de service         orientation HOSPITALIZATION → MEDICINE, intacte
```

Une visite **est** une consultation (ADR-148), et seule sa clôture termine son
orientation Médecine (ADR-084). Le passage gardait donc une orientation active,
n'atteignait jamais `PENDING_SETTLEMENT`, et ne rejoignait pas « Sorties &
règlements » — le trou que l'ADR-114 et l'ADR-135 ont déjà bouché ailleurs.

## La règle : on refuse, on ne clôture pas à la place

`DischargeHospitalStayAction` refuse tant qu'une visite est ouverte, et nomme
la suite : clôturez-la — conduite à tenir « Poursuite de l'hospitalisation »
(ADR-149) — puis prononcez la sortie. L'écran le dit **avant** le clic et
propose « Clôturer la visite en cours » ; le serveur refuse de toute façon,
l'interface n'est jamais la seule garde.

Clôturer la visite d'office aurait été plus court et faux : la clôture exige un
diagnostic et une conduite à tenir transmise (ADR-084), et l'ADR-076 interdit
de résoudre une consultation « en effet de bord d'un enregistrement ». Une
rencontre se conclut par son médecin, pas par un bouton d'un autre écran.

C'est le même genre de garde que l'ADR-152 : une règle de **cohérence du
dossier**, pas un droit — elle vaut pour un médecin comme pour un compte à qui
tout a été accordé.

## Le chemin de sortie pour les séjours déjà bloqués

Une visite laissée ouverte par une sortie déjà prononcée ne pouvait plus se
clôturer : le patient n'étant plus hospitalisé, « Poursuite de
l'hospitalisation » n'est plus proposée, et « Sortie médicale » restait
`SELECTED` — `attachPronouncedDischarge` ne lisait la sortie que sur **sa**
consultation, alors qu'une sortie prononcée depuis le séjour porte
`consultation_id` de la consultation qui a demandé l'hospitalisation (ADR-113).
Impasse complète : ni transmettre, ni clôturer.

La sortie se lit donc désormais sur le **passage**. Rien n'est fabriqué : un
passage n'a qu'une sortie médicale, et l'orientation est rattachée au fait déjà
enregistré — exactement ce que l'ADR-107 avait posé pour le même cul-de-sac.

## Ce qui ne change pas

La sortie reste prononcée depuis la page du séjour, qui seule termine séjour et
prise en charge ensemble (ADR-113, ADR-152). Une visite reste une consultation
ordinaire, avec ses obstacles de clôture inchangés.

---

# ADR-156 — Une seule sortie médicale, prononcée dans la consultation

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire : « nous
devons avoir une seule sortie […] on n'a pas besoin d'onglet sortie dans la
page du module Hospitalisation »)
; **renversée par l'ADR-162** (2026-09-21) sur le lieu de la sortie : pour un
patient hospitalisé, elle se prononce sur la page du séjour, et là seulement.

**Renverse l'ADR-149** (« Sortie médicale » retirée de la conduite à tenir d'un
patient hospitalisé) et **l'ADR-152** (le serveur refusait une sortie prononcée
depuis une consultation tant que le séjour tournait). **Retire** le formulaire
de sortie du séjour ajouté par l'ADR-113, et avec lui la garde de l'ADR-155.
Ces divergences sont signalées, jamais masquées (ADR-020).

## Pourquoi ces décisions tombent

L'invariant qu'elles protégeaient était juste : **jamais un passage
médicalement sorti dont le lit reste occupé**. La réponse était mauvaise — un
**second formulaire de sortie**, sur un autre écran que celui où le médecin
travaille. Trois défauts en ont découlé, tous constatés par le propriétaire :

```text
deux endroits pour un seul acte   la consultation refusait, le séjour imposait
visite « En cours » après sortie  la sortie du séjour ne terminait pas la visite
cul-de-sac à la clôture           « indiquez la suite » avec la sortie affichée
                                  juste en dessous, déjà prononcée et datée
```

## La règle

Il n'y a qu'une sortie médicale, et le médecin la prononce **là où il
travaille** : dans sa consultation, à « Décision & clôture », conduite à tenir
« Sortie médicale » — hospitalisé ou non. `RecordMedicalDischargeAction`
**termine le séjour dans la même transaction** (statut, date, auteur,
`active_key` libérée, orientation du séjour complétée) au lieu de refuser.
L'invariant est donc tenu par construction, et non par un renvoi vers un autre
écran.

```text
visite de service   examen, ordonnance, analyses
                    → « Poursuite de l'hospitalisation » : le patient reste
                    → « Sortie médicale » : le séjour se termine avec elle
clôture             termine la rencontre et porte le statut médical (ADR-084)
Réception           la sortie administrative, à /reception/sorties (ADR-090)
```

`orientationApplies()` ne retire plus qu'**une** destination à un patient
hospitalisé : « Hospitalisation », qui ouvrirait un second séjour sur le même
passage.

## Ce qui est retiré

```text
DischargeHospitalStayAction, DischargeHospitalStayRequest
HospitalizationController::discharge(), POST /hospitalisation/{stay}/sortie
le formulaire de sortie de Hospitalization/Show.vue
la garde ADR-155 (« une visite ouverte retient la sortie ») — sans objet :
    la sortie EST prononcée dans la visite
```

La page du séjour garde ce qui lui appartient : le dossier, la fiche de régime,
les visites, les diagnostics du séjour (ADR-147, désormais leur propre carte) et
la sortie **en lecture** une fois prononcée. Elle dit où se prononce la sortie et
mène à la visite plutôt que d'en proposer une seconde saisie.

## La consultation qui a demandé l'hospitalisation ne sort pas le patient

Sa conduite à tenir est « Hospitalisation », déjà transmise, et l'ADR-084 refuse
de la retirer dès que le service a pris la demande. Le message le dit. La sortie
se prononce donc dans une **visite de service** — ce qui est exact : ce n'est pas
la même rencontre.

## Le module liste les hospitalisés ; les sorties sont centralisées

L'onglet « Sortis » de `/hospitalisation` était une **seconde liste des
sorties**, pour des séjours que plus personne n'a à traiter : les sorties se
suivent à la Réception (« Sorties & règlements », ADR-090). Il est retiré ; la
page ne compte plus que les patients réellement au lit. Une **recherche nommée**
y retrouve toujours un séjour terminé — sa fiche de régime et son dossier
restent consultables.

Retirer la liste sans la remplacer aurait fait **perdre** ces séjours. Chaque
ligne de « Sorties & règlements » porte donc désormais le passage par un lit :
pastille « Hospitalisé » / « Hospitalisation », service et chambre, et le lien
vers le séjour. Le **fait** est du parcours, de même nature qu'une orientation,
et suit donc la file (ADR-117) ; le **lien** n'est proposé qu'avec
`hospitalization.view` — un lien qui mène à un refus vaut moins qu'une absence
de lien (ADR-146).

## Sorti du lit, pas encore clôturé : visible, et la raison avec

Un passage dont le séjour est terminé mais dont la visite reste ouverte n'est
pas réglable — un service a encore le patient (ADR-054, ADR-084). Il n'avait
pourtant sa place nulle part : absent d'« À régler », absent d'Hospitalisation
depuis que ce module ne liste que les lits occupés. Perdu de vue, donc.

« Sorties & règlements » reçoit une troisième vue, **« Sortie médicale
prononcée · Service pas encore clôturé »** : ces passages s'y affichent, en
lecture seule, avec « En attente de clôture par le service » et le lien vers le
passage. Aucune règle n'est assouplie — ils ne deviennent réglables qu'à la
clôture, et rejoignent alors « À régler » d'eux-mêmes.

## Une sortie prononcée n'est pas redemandée

« Conduite à tenir : indiquez la suite de la prise en charge » s'affichait
au-dessus d'une sortie datée et signée, et le bouton « Clôturer » restait gris.
L'obstacle était formellement vrai — aucune conduite à tenir enregistrée — mais
il faisait **ressaisir un fait déjà consigné**, ce que ce dossier refuse partout
ailleurs (§17, ADR-084).

Une sortie prononcée sur le passage **est** la conduite à tenir de la rencontre,
exactement comme `attachPronouncedDischarge` le pose déjà à la sélection
(ADR-107). `CompleteConsultationAction` la rattache donc elle-même à la clôture
— `DISCHARGE` (ou `REFERRAL` pour un transfert), `SUBMITTED`, liée à la sortie
réelle — et l'obstacle disparaît. Rien n'est fabriqué : un passage n'a qu'une
sortie médicale, et aucune autre destination ne peut conclure un passage déjà
médicalement sorti. Les étapes du parcours, elles, restent à résoudre comme
d'habitude (ADR-076).

## Dossiers hérités

Une visite laissée ouverte par une sortie prononcée sous l'ancien mécanisme se
conclut désormais : `attachPronouncedDischarge` lit la sortie sur le **passage**
et non sur la seule consultation (ADR-155, conservée sur ce point). Aucune
donnée n'est réécrite.

---

# ADR-157 — La file Soins appartient à qui fait les soins

**Status:** ACCEPTED (2026-09-20 — signalement du propriétaire : « un compte de
rôle Médecine peut voir Soins `/care` sans les trois profils ! pourquoi ? »)

**Complète l'ADR-093** (le médecin corrige les constantes et la fiche Soins) et
l'ADR-134 (les trois espaces sous une entrée). Aucune permission nouvelle,
aucune migration.

## Le constat

`care.update` servait **deux choses différentes** :

```text
corriger une fiche depuis la consultation   accordé à MEDICINE (ADR-093)
ouvrir la file des infirmières              la route, le menu et les onglets
```

Donner au médecin le droit de rectifier une température lui ouvrait donc
l'espace de travail d'un autre métier. C'est le défaut que l'ADR-100 avait déjà
corrigé pour « Demandes d'examens » : un écran qui n'a pas de droit à lui finit
adossé au droit d'un voisin.

## La règle

L'espace de travail est celui de **ceux qui font les soins** :

```text
care.create   ouvrir la file /care, l'entrée de menu, l'onglet « Infirmière »
care.update   corriger une fiche existante — depuis la consultation comprise
care.view     la projection en lecture des dossiers Médecine et Chirurgie
```

`care.create` est le droit d'**ouvrir une fiche**, donc de soigner. La frontière
n'est pas inventée pour l'occasion : l'ADR-093 l'avait déjà posée en refusant
explicitement `care.create` à Médecine — « une fiche que personne n'a remplie ne
se signe pas depuis une consultation ». Elle devient seulement la règle
d'entrée de l'espace.

Rien n'est retiré au médecin : il continue de lire la fiche dans son dossier et
de la corriger par le lien de sa consultation (ADR-092, ADR-093). Il ne prend
simplement plus de patient dans une file qui n'est pas la sienne.

Un site qui veut l'inverse coche `care.create` au socle du rôle depuis le
portail (ADR-064) ou en exception individuelle (ADR-022) : la règle reste
dynamique, aucun rôle n'est codé en dur (ADR-152).

---

# ADR-158 — Les trois profils Soins sont montrés, verrouillés plutôt que masqués

**Status:** ACCEPTED (2026-09-20 — signalement du propriétaire : « si on donne
les droits il peut voir Soins avec trois profils […] mais là Médecine voit une
ancienne mise à jour »)

**Amende l'ADR-134** sur un point : « une seule page accessible → aucune
barre ». Aucune permission nouvelle, aucune route, aucune migration.

## Le constat

Un compte sans `maternity.view` ni `anesthesia.view` ouvrait `/care` et n'y
voyait **aucune** barre d'onglets : l'ADR-134 la masquait faute de second
espace accessible. L'écran se lisait alors comme une version antérieure du
module — « où sont les trois profils ? » —, alors que rien n'était cassé.

Masquer une capacité ne dit pas qu'elle n'existe pas : cela laisse croire
qu'elle n'existe plus.

## La règle

Les trois onglets — Infirmière, Maternité, Anesthésie — sont **toujours
affichés**. Celui dont le compte n'a pas le droit est montré verrouillé
(cadenas, `aria-disabled`), avec la permission qui l'ouvre :

> Espace non accessible — demandez le droit « maternity.view » à un
> administrateur.

C'est le parti pris que ce dossier tient déjà ailleurs : les types de sortie
verrouillés mais visibles de l'ADR-090, et le refus qui nomme le droit manquant
de l'ADR-154. Un espace masqué est indistinguable d'un espace supprimé ; un
espace verrouillé dit à qui s'adresser.

Rien n'est ouvert pour autant : le lien n'existe pas, et chaque route revérifie
sa permission côté serveur (ADR-134, ADR-152).

## Ce que cela ne change pas

Le **menu latéral** continue de ne proposer que les espaces réellement
accessibles (ADR-115) : une entrée de menu est une promesse de navigation, un
onglet est la carte du module où l'on se trouve déjà. Un compte Médecine sans
`care.create` ne voit donc toujours pas l'entrée « Soins » (ADR-157) — c'est
une fois dans le module que ses trois profils se montrent.

---

# ADR-159 — La demande du bloc naît à la Réception ou en consultation, jamais au bloc

**Status:** ACCEPTED (2026-09-20 — exigence explicite du propriétaire : « l'action
chirurgien dépend : 1 - choix fait par Réception selon besoin du patient, 2 - choix
par médecin pendant consultation et conduite à tenir »)

**Complète l'ADR-068** (une prestation sélectionnée à la Réception crée son
orientation *et* sa demande opérationnelle) et **l'ADR-084** (la conduite à tenir
du médecin). **Amende l'ADR-048**, qui laissait le bloc ouvrir ses propres
dossiers.

## Ce que le CDC dit, et ce qu'il ne dit pas

Le CDC officiel (§16) décrit le déroulé d'une intervention — demande,
programmation, préopératoire, bloc, compte rendu, suivi — et pose que « la
Chirurgie génère des prestations facturables, sans encaissement ». **Il ne dit
nulle part d'où vient la demande.** Les deux chemins ci-dessous sont donc la
décision du propriétaire, signalée comme telle et non déduite du CDC.

## Le troisième chemin, qui n'aurait pas dû exister

`/surgery/create` permettait au bloc de choisir un patient dans la liste des
passages ouverts et de lui ouvrir un dossier. C'était un troisième point
d'entrée, parallèle aux deux vrais :

```text
Réception   le patient vient pour cet acte : c'est la raison de sa venue
Médecine    le médecin le décide en consultation (conduite à tenir, ADR-084)
bloc        ← retiré : personne n'y décide qu'un patient doit être opéré
```

Le bloc **exécute** une décision prise ailleurs. Lui laisser créer la demande
produisait un dossier sans décision clinique derrière lui, invisible du parcours
du passage et sans orientation : le patient était au bloc sans que rien, dans son
dossier, ne dise pourquoi.

Retiré : `SurgeryController::create()` et `store()`, `POST /surgery`,
`StoreSurgicalRequestRequest`, `Surgery/Create.vue` et le bouton « Nouvelle
demande ». `GET /surgery/create` **reste une URL valide** et redirige vers la
file — un signet mène là où le travail se fait désormais, jamais à une page
disparue (même principe qu'ADR-081, ADR-084 et ADR-104).

## La Réception inscrit un acte du bloc comme elle inscrit une analyse

`ReceptionRoutingMode::SurgeryDirect` rejoint `LaboratoryDirect` et
`MaternityDirect`. Le mécanisme est celui, déjà éprouvé, de l'ADR-068 :
l'orientation `RECEPTION → SURGERY` situe le patient dans le parcours, et
`CreateReceptionSurgicalRequestAction` crée la demande qui dit **ce qu'on vient
opérer** — une orientation seule ne le dirait pas.

```text
statut       PENDING : le bloc reçoit un dossier à programmer
chirurgien   jamais choisi à l'accueil
date         jamais posée à l'accueil
libellé      instantané pris à l'arrivée (ADR-024) — une correction ultérieure
             du catalogue ne réécrit pas la demande
idempotence  (épisode, acte, demande non annulée) : une confirmation rejouée
             n'ouvre jamais deux fois le même dossier au bloc
```

**Trente-sept actes** du référentiel deviennent `reception_selectable` avec ce
parcours. Trois en sont écartés : **« Autres »**, qui n'a ni nom ni prix et ne
peut donc pas être choisi par un accueil, et **la césarienne**, qui reste soumise
au workflow Maternité → Chirurgie (ADR-067). La migration
`2026_10_13_090000` liste les codes **explicitement**, depuis le référentiel des
interventions, plutôt que de prendre tout le module : `CONSULT-CHIR` et
`PETITE-CHIR` appartiennent au même module sans être des actes opératoires, et
une première version qui filtrait par module les avait envoyés au bloc — la faute
exacte que l'ADR-052 interdit. Un site déjà en production reçoit la sélection par
cette migration, `ClinicalServiceCatalogSeeder` ne devant plus être rejoué
(ADR-064).

**Un acte sans tarif reste sélectionnable**, et c'est voulu : l'ADR-031 précise
l'exigence « avec un tarif actif » de l'ADR-028 — « l'absence de prix peut
empêcher la facture, jamais le parcours clinique ». Les actes du bloc arrivent
sans prix (ADR-024 : le tarif appartient au Super Admin) ; la demande et
l'orientation partent, la facturation attend. Configurer ces prix reste à faire,
et rien n'en invente.

## Le module ne décide pas le parcours

Une garde « un acte du module SURGERY proposé à la Réception doit être routé vers
le bloc » a été écrite, puis **retirée avant d'être retenue** : elle contredit
l'ADR-052 — « `CatalogModule::SURGERY` ne signifie jamais `BLOCK_CREDIT` : une
consultation de chirurgien ou un contrôle postopératoire peut être une prestation
ordinaire ». `CONSULT-CHIR` et `PETITE-CHIR` restent donc hors sélection, et rien
n'interdit de les router un jour vers la Médecine.

La garde inverse, elle, demeure et suffit : `SURGERY_DIRECT` est réservé au module
`SURGERY` (`directDestination()`), comme `LABORATORY_DIRECT` au Laboratoire.

## D'où vient chaque demande, écrit sur la demande

`surgical_requests.origin` (`SurgicalRequestOrigin`) porte le chemin réel :

```text
RECEPTION   acte annoncé à l'arrivée
MEDICINE    conduite à tenir d'une consultation (ADR-084)
MATERNITY   césarienne décidée en Maternité (ADR-067)
```

Le propriétaire en a nommé deux ; la troisième existe déjà et n'est pas retirée —
une césarienne ne passe pas par une consultation, et supprimer ce chemin
casserait l'ADR-067.

**Nullable, et jamais rétro-rempli.** Une demande enregistrée avant cette colonne
ne portait aucune origine ; la déduire d'une date ou d'un auteur inventerait un
fait (ADR-083). L'écran affiche « Non renseignée » plutôt qu'un tiret muet qui se
lirait « aucune origine ». Le libellé et sa phrase d'explication sont servis par
le serveur : l'écran ne recopie pas un vocabulaire d'enum.

L'état vide de la file nomme les deux chemins au lieu d'un « aucune demande » qui
laisserait chercher le bouton retiré.

## Ce qui ne change pas

La programmation, le bilan préopératoire, l'équipe, l'intervention, l'anesthésie,
le compte rendu, les complications et la sortie du bloc sont inchangés (ADR-048).
Corriger l'intervention prévue reste possible au bloc (`PUT /surgery/{demande}`,
`surgery.update`) : le bloc ne crée pas une demande, il corrige celle qu'il a
reçue — les trois garanties du catalogue (instantané du libellé, « Autres » à
préciser, module respecté) valent désormais sur ce chemin, et y sont testées. La
Chirurgie n'encaisse toujours rien (ADR-012, ADR-016).

## Signalé, non tranché

`surgery.create` ne commande plus rien : aucune route ne la vérifie, et le
catalogue des permissions l'affichera « pas encore vérifiée » (ADR-101). Elle
reste au socle `SURGERY` — la retirer d'office changerait un socle sans que
personne ne l'ait demandé. Le Super Administrateur la décoche depuis le portail
s'il le décide (ADR-064) ; elle n'est pas supprimée du catalogue, ce que
l'ADR-101 refuserait tant qu'un rôle l'accorde.

Le tableau `Prévu / Réel / Écart / Dette NP` du récapitulatif de la clinique
reste non alimenté : il exige des prestations facturables Chirurgie, que cette
décision ne crée pas (ADR-048 — aucun `Ar0` fabriqué).

Aucune permission nouvelle.

---

# ADR-160 — Le patient hospitalisé descend au bloc, et garde son lit

**Status:** ACCEPTED (2026-09-21 — exigence explicite du propriétaire : « un patient à
l'hospitalisation peut être transféré au bloc […] basculer vers bloc, le patient direct
là-bas, mais toutes ses constantes cliniques et son passage suivent ») ; le transfert
s'annule tant que le bloc ne l'a pas programmé, et le piège de la consultation d'origine
est fermé par l'**ADR-163**.

**Complète l'ADR-113** (le séjour), **l'ADR-148** (la visite de service) et **l'ADR-159**
(d'où vient une demande du bloc). Le CDC ne dit rien du passage d'un patient hospitalisé
au bloc — il connaît le statut « Hospitalisé » (§32) et les droits de la Chirurgie (§16),
jamais leur rencontre : la règle ci-dessous est la décision du propriétaire.

## Ce que la vérification a trouvé

Le chemin existait déjà, par une visite de service : conduite à tenir « Chirurgie ».
Vérifié en test, il fonctionne **si** la consultation qui a demandé l'hospitalisation est
close. Mais si elle est encore ouverte — cas courant —, la visite réutilise cette
consultation (`active_key`, ADR-148), et une consultation ne porte **qu'une** conduite à
tenir (ADR-084) : choisir « Chirurgie » y remplace « Hospitalisation », ce qui annule la
demande **et le séjour** tant que la fiche de régime est vide (ADR-113). Constaté :

```text
séjour après   CANCELLED     le patient perdait son lit
passage        IN_CARE       plus hospitalisé
```

Ce n'est pas un défaut de ces règles — chacune est juste pour ce qu'elle décrit —, c'est
le mauvais outil : descendre au bloc n'est pas changer d'avis sur l'hospitalisation.

## La règle

La décision se prend **sur le séjour**, et ne le touche pas :

```text
Hospitalisation › « Transférer au bloc »   POST /hospitalisation/{séjour}/bloc
séjour           reste ACTIVE — le lit attend le patient
passage          reste HOSPITALIZED
orientation      HOSPITALIZATION → SURGERY, à côté de celle du séjour
demande          SurgicalRequest PENDING, origine HOSPITALIZATION (4e origine de l'ADR-159)
```

`RequestSurgeryFromStayAction` n'appelle aucune consultation. L'intervention est
**choisie**, jamais devinée — aucune demande « sans intervention » n'arrive au bloc
(ADR-114). Ce que le séjour sait déjà part sans ressaisie (§17, ADR-084) : service,
chambre / lit, diagnostic d'entrée ; une valeur absente reste absente. Une même
intervention encore ouverte au bloc n'est pas redoublée : un second clic retrouve la
demande. Un séjour terminé ou un passage clos n'envoie plus personne.

Le transfert est un acte signé (ADR-106) : la fenêtre nomme l'intervention et le
responsable, et ne se ferme pas au clic extérieur.

## Les constantes et le passage suivent — par le dossier, pas par une copie

Rien n'est recopié vers le bloc. Chirurgie et Anesthésie lisent déjà la fiche Soins du
passage — constantes, allergies, actes — en lecture seule (`CareRecordReadModel`,
ADR-048). Ce qui leur manquait, c'était de **savoir qu'un lit attend** :
`SurgicalStayContext::for()` sert le séjour actif (service, chambre, date d'admission) aux
deux espaces, qui travaillent sur le même dossier et le lisent donc au même endroit. La
fiche du bloc porte un bandeau « Patient hospitalisé — il remonte à son lit après le
bloc », la file une pastille « Hospitalisé · chambre ». Le lien vers le séjour n'est servi
qu'avec `hospitalization.view` (ADR-146). Le séjour terminé, le bandeau disparaît.

En retour, la page du séjour porte une carte **« Bloc opératoire »** : chaque demande du
passage, son origine et où elle en est (À programmer, Programmée, Au bloc, Opéré…), avec
le lien vers le dossier du bloc seulement pour qui peut l'ouvrir (`surgery.view`).

## Permissions

Aucune nouvelle. Descendre un patient au bloc est une décision médicale : c'est
`surgery.request`, la même autorité qu'en consultation, jamais un droit propre à
l'hospitalisation. Aucun rôle n'est codé en dur (ADR-152).

## Signalé, non tranché

- **« Les paramètres selon les modèles (captures) »** : aucune capture n'accompagnait la
  demande. Les formulaires du bloc et de l'anesthésie restent ceux de l'ADR-048 (cinq et
  trois étapes, référentiels des interventions et de l'anesthésie). Aucun champ n'est
  inventé ; les modèles papier de la clinique sont attendus pour aller plus loin.
- Le **retour au lit** n'est pas un geste : le séjour n'a jamais cessé, il n'y a rien à
  rouvrir. La sortie du bloc (ADR-048) ne termine pas le séjour ; seule la sortie médicale
  le fait (ADR-156).
- Le piège de la consultation d'origine restée ouverte n'est **pas** modifié : changer sa
  conduite à tenir reste « changer d'avis » (ADR-084, ADR-113). Le transfert depuis le
  séjour le contourne ; à décider s'il faut en plus refuser, depuis une consultation, de
  remplacer une hospitalisation déjà admise. **Traité par l'ADR-163** : dès que le patient
  a réellement séjourné, le séjour ne s'annule plus par ce chemin.

## Amendement du 2026-09-21 — la liste des hospitalisés dit qui va au bloc

Demande du propriétaire : « lorsque le patient bascule ou entre au bloc, il faut le marquer sur
la liste ». Le patient garde son lit pendant le bloc : sur `/hospitalisation`, rien ne
distinguait celui qui attend son intervention, ou qui est déjà en salle, d'un patient au lit.

```text
sous le nom    un repère « Bloc · À programmer », « Bloc · Programmée » (avec la date prévue),
               « Au bloc », puis « Opéré » / « Sorti du bloc » une fois l'intervention faite ;
               l'intervention en dessous, et « +N autre demande au bloc » s'il y en a plusieurs
               — sous le nom, parce que c'est la seule colonne visible sur un téléphone
priorité       la demande la plus avancée l'emporte (en salle > préop. validé > programmée >
               à programmer) ; sans demande en cours, la dernière intervention terminée ;
               une demande annulée n'est jamais lue ; sans passage au bloc, aucun repère
carte          « Vers le bloc » : les séjours en cours dont une demande n'est pas terminée,
               comptés par le serveur ; la carte filtre (?filter=bloc) et se referme d'un
               second clic ; un filtre inconnu ne filtre rien
lien           le repère ouvre le dossier du bloc seulement avec surgery.view — jamais un lien
               vers un refus (ADR-146) ; le fait lui-même est lu comme sur la page du séjour
```

Rien n'est recopié : `App\Support\Hospitalization\StaySurgeryStatus` lit la demande chirurgicale
du passage en une requête par page. Les libellés vivent dans `utilities/surgicalRequestStatus.js`,
lu par la liste et par la page du séjour — la copie locale de la page est retirée ; « Au bloc »
passe en pastille pleine pour ne plus se confondre avec « À programmer ». Aucune permission ni
route nouvelle.

---

# ADR-161 — Séjour hospitalier, lot 1 : départ en transfert, emplacements, surveillance

**Status:** ACCEPTED (2026-09-21 — spécification du workflow d'hospitalisation, arbitrages
explicites du propriétaire : lot 1 = les défauts ; le séjour se termine **au départ** en
cas de transfert ; la clinique dispose d'une **réanimation / surveillance continue**
internes ; la note quotidienne courte est retenue pour le lot 2)

**Complète l'ADR-113**, **l'ADR-114** et **l'ADR-156**. Le CDC ne décrit pas le parcours
d'hospitalisation (il connaît seulement le statut « Hospitalisé », §32) : les règles
ci-dessous viennent de la spécification validée par le propriétaire.

## Quatre défauts vérifiés, corrigés

```text
1  « Transfert effectué » laissait le séjour ACTIVE      le patient restait « au lit »,
                                                         le passage n'atteignait jamais
                                                         « Sorties & règlements »
2  deux circuits de transfert, deux résultats           la sortie « Transfert » terminait le
                                                         séjour à la décision ; le module
                                                         Transferts ne le terminait jamais
3  service et lit écrasés                               aucun historique des emplacements
4  une seule fiche de constantes par passage            aucune surveillance répétée au lit
```

## Transfert : le séjour se termine au départ

Le patient reste au lit, suivi, jusqu'au départ de l'ambulance. `ConfirmTransferDepartureAction`
termine donc le séjour actif : statut `DISCHARGED`, `end_reason = TRANSFER`, date du départ,
auteur du constat, lien vers le transfert (`medical_referral_id`), orientation du séjour
complétée, emplacement fermé. Le passage peut alors rejoindre « Sorties & règlements »
selon la règle de l'ADR-054.

Un seul circuit subsiste pour un patient hospitalisé : la conduite **« Référence /
transfert »**. La sortie médicale de type « Transfert » ne lui est plus proposée, et
`RecordMedicalDischargeAction` la refuse tant qu'un séjour est actif — elle terminait le
séjour alors que le patient attendait encore. **Amende l'ADR-156** sur ce seul type ; les
autres sorties (domicile, contre avis, refus, décès) terminent toujours le séjour avec
elles. Hors séjour, la sortie « Transfert » est inchangée (ADR-114).

## Le séjour dit comment il s'est terminé

`hospital_stays.end_reason` (`HospitalStayEndReason`) : domicile, transfert, à la demande
du patient, refus de la décision médicale, décès. Il est lu sur la sortie médicale quand
il y en a une, posé par le départ constaté sinon. La migration le reprend pour les séjours
déjà terminés, depuis leur sortie réelle ; rien n'est deviné.

## Emplacements : un historique, plus rien ne s'écrase

`hospital_stay_movements` : une ligne par emplacement (service, chambre/lit, niveau de
soins, début, fin, motif, auteur). L'admission ouvre le premier ; « Changer de service /
lit » ferme l'emplacement en cours et en ouvre un autre ; toute fin de séjour (sortie,
départ, annulation) ferme le dernier. Jamais supprimé. Le service et le lit du séjour
restent tenus à jour pour les écrans qui les lisent déjà.

Deux gestes, volontairement distincts :

```text
Corriger (crayon)          compléter la chambre à l'admission, rectifier une faute :
                           l'emplacement en cours est corrigé, l'ancienne valeur à l'audit
Changer de service / lit   un vrai déplacement : nouvel emplacement, motif, historique
```

Une mutation qui ne change rien est refusée. `hospitalization.update`, droit existant.

**Niveau de soins** (`HospitalCareLevel`) : hospitalisation standard, surveillance
continue, réanimation. La clinique dispose des deux derniers en interne (confirmé) : une
**aggravation se traite par une mutation**, le séjour continue ; le transfert externe
reste possible quand le niveau requis dépasse la clinique. Les noms de services restent du
texte libre ; aucun référentiel de lits n'est créé (à décider, lot ultérieur). La liste des
hospitalisés signale un patient en surveillance continue ou en réanimation.

## Surveillance répétée

`vital_sign_readings` : un relevé daté et signé à chaque passage au lit (TA, FC, SpO₂,
température, observation). `care_records` reste le relevé de triage du passage. Les bornes
sont celles de la fiche Soins (`VitalSignRules`, ADR-093) et les repères ceux de l'ADR-125,
calculés par le serveur selon l'âge au passage : aucun seuil n'est recopié à l'écran. Un
relevé s'ajoute seulement pendant un séjour en cours (`vitals.create`) et se corrige tant
que le passage est ouvert (`vitals.update`) — l'auteur d'origine est gardé, l'auteur de la
correction ajouté, l'ancienne valeur à l'audit. Jamais supprimé.

Les paramètres restent ceux de la fiche Soins. Fréquence respiratoire, diurèse, douleur et
glycémie ne sont **pas** ajoutés : ils attendent la feuille de surveillance de la clinique.

### Amendement du 2026-09-21 — l'onglet dit qui relève, et ce qui manque

Signalement du propriétaire : « je ne comprends pas l'étape Surveillance ? est-ce qu'elle
est réalisée par un autre processus ou remplie manuellement ? comment remplir ? »

Rien n'était cassé. Un relevé est **saisi à la main**, au lit du patient : aucun autre
circuit ne l'alimente, et la fiche Soins d'arrivée n'y est jamais recopiée — c'est le
relevé de triage du passage, pas un relevé du séjour. Le compte connecté était un médecin
(`MEDICINE`), dont le socle porte `vitals.view` et `vitals.update` (ADR-093) mais **pas**
`vitals.create`, réservé au socle `NURSE`. L'onglet s'ouvrait donc en lecture, sans
formulaire.

Le défaut était que l'écran **n'en disait rien** : un cadre vide et une phrase qui ne
parle que du passé (« Aucun relevé pendant ce séjour ») se lisent « cette étape ne sert à
rien ici ». C'est exactement ce que ce dossier refuse ailleurs — l'onglet verrouillé qui
nomme son droit (ADR-158), le refus qui dit où l'accorder (ADR-154), le chiffre absent qui
n'est jamais un zéro (ADR-102).

```text
peut relever          le formulaire, comme avant
ne peut pas           « Les relevés sont pris au lit du patient par l'équipe soignante.
                        Ajouter un relevé demande le droit « vitals.create », qui
                        s'accorde dans Rôles & permissions. »
séjour terminé        « La surveillance est close. Les relevés déjà pris restent lisibles. »
```

La phrase est composée par le serveur (`HospitalizationController::vitalsRecordBlock()`),
où vit le nom de la permission ; `null` quand le relevé est possible. Aucune autorisation
n'est assouplie : qui ne pouvait pas relever ne le peut toujours pas.

**Signalé, non tranché.** Faut-il que `MEDICINE` relève lui-même pendant un séjour ? Un
médecin qui examine un patient au lit prend sa tension, et le socle actuel le lui refuse.
Ce n'est pas un défaut de code : `vitals.create` se coche au socle du rôle depuis le
portail (ADR-064), sans déploiement. La décision appartient au propriétaire ; rien n'est
changé d'office.

## Permissions

Aucune nouvelle : `hospitalization.update`, `vitals.view`, `vitals.create`,
`vitals.update`, `transfers.manage` gouvernent déjà ces gestes. Aucun rôle codé en dur.

## Retenu pour le lot 2, non construit ici

Note d'évolution quotidienne courte (S/O/A/P) portée par le séjour, la visite complète
restant réservée aux prescriptions et demandes (choix du propriétaire) ; plan de prise en
charge ; médecin référent du séjour. Restent à décider : traitement hospitalier et sa
délivrance sans attendre le paiement (amenderait l'ADR-049), référentiel des lits,
évasion pendant le séjour, compte rendu d'hospitalisation, facturation du séjour.

---

# ADR-162 — Le séjour est le poste de travail du patient hospitalisé

**Status:** ACCEPTED (2026-09-21 — proposition du propriétaire : « si on met tout dans la
page du séjour […] ne pas aller au parcours du médecin car ce parcours déjà clôturé » ;
trois arbitrages explicites : tout sur la page du séjour, la sortie uniquement là,
délivrance au service sans attendre le paiement) ; ses trois points « non tranchés » sont
traités par l'**ADR-163**.

**Retire la visite de service de l'ADR-148**, **renverse l'ADR-156** sur le lieu de la
sortie d'un patient hospitalisé, **amende l'ADR-049** pour la délivrance au service, et
**réalise la note quotidienne** retenue pour le lot 2 de l'ADR-161. Le CDC ne décrit pas le
séjour (§32 connaît seulement le statut « Hospitalisé ») : ces règles sont celles du
propriétaire.

## Le constat

Un patient au lit est examiné chaque jour, prescrit, envoyé au laboratoire, puis sort. La
visite de service (ADR-148) faisait tout cela en rouvrant l'assistant Médecine — six étapes,
une conduite à tenir à transmettre, une clôture — pour trois lignes du jour. Pire, la
consultation qui a demandé l'hospitalisation est le plus souvent close (ADR-076) : le
médecin passait donc d'un écran à l'autre, et l'ADR-156 plaçait la sortie dans une
consultation alors que tout le reste du séjour vivait ailleurs.

## La règle : une page, des onglets, les mêmes actions

`/hospitalisation/{séjour}` porte un onglet par geste :

```text
Vue d'ensemble   dossier, constantes du passage, dernier relevé, séjour, demande, diagnostics
Notes du jour    S/O/A/P, courte, datée et signée
Ordonnances      prescription, impression, retrait avec motif
Examens          analyses et imagerie, compte rendu d'imagerie
Soins            actes demandés à l'équipe infirmière, retrait d'un acte non réalisé
Surveillance     relevés répétés (ADR-161)
Régime           fiche de régime (ADR-113)
Bloc             passages au bloc (ADR-160), anciennes visites en lecture
Sortie           sortie médicale, demande de transfert
```

**Rien n'est recopié.** Chaque geste appelle l'action qui porte déjà sa règle, par une
variante « depuis le séjour » (`executeForStay`) qui partage la même écriture privée :
réservation FEFO et ligne manuelle (ADR-036/037), facturation à la demande et rattachement
au besoin de l'arrivée (ADR-105/109), refus du doublon, orientation vers le service
(source `HOSPITALIZATION`), retrait d'un acte de soins (ADR-112). Les demandes portent
`hospital_stay_id` et un `consultation_id` désormais **nullable** ; les ordonnances portent
en plus `episode_id`, rempli depuis la consultation pour les anciennes. Les
FormRequests héritent de celles de la consultation : mêmes bornes, mêmes messages.
`StayOrderContext::lock()` exige, sous verrou, un séjour en cours, un passage ouvert et
l'orientation du séjour prise en charge.

Un soin demandé depuis le séjour ne décide aucun retour en Médecine : le patient reste au
lit. Le parcours le dit (« Le patient reste hospitalisé après les soins »), et la fin des
Soins ne fait jamais passer en attente de règlement un passage dont le séjour est actif.

## La note du jour

`hospital_stay_notes` : subjectif, objectif, analyse, plan — au moins une rubrique, datée
et signée par le serveur. **Append-only** : le modèle refuse la mise à jour et la
suppression, une erreur se corrige par une nouvelle note (ADR-010).

```text
hospital_notes.view     lire les notes          MEDICINE, NURSE
hospital_notes.create   écrire la note du jour  MEDICINE
```

Enregistrées par migration (ADR-064). La Réception, qui lit le séjour (ADR-147), ne lit
pas les notes : la section se nomme « non visible avec vos droits », jamais servie vide.

## La sortie : sur la page du séjour, et là seulement

`DischargeHospitalStayAction` (`POST /hospitalisation/{séjour}/sortie`,
`medical_discharge.create`) écrit la même `MedicalDischarge` que la consultation — mêmes
types, mêmes règles de décès (`MedicalDischargeAttributes`, ADR-107) — avec un
`consultation_id` nul : elle n'appartient à aucune rencontre. Le diagnostic final est
**toujours exigé** (un séjour n'est jamais un passage paraclinique seul, ADR-094) ; s'il
est nouveau, il est aussi consigné sur le séjour (ADR-147). Dans la même transaction : le
séjour se termine (motif de fin, emplacement fermé, orientation complétée), le statut
médical est porté, et le passage passe en attente de règlement si plus aucun service n'a
le patient (ADR-054).

`RecordMedicalDischargeAction` **refuse** désormais une sortie prononcée depuis une
consultation tant qu'un séjour est actif, et nomme l'endroit. C'est l'inverse de
l'ADR-156, et c'est voulu : un seul acte, un seul endroit — celui où tout le séjour vit.
« Sortie médicale » et « Hospitalisation » ne sont plus proposées à la conduite à tenir
d'un patient hospitalisé. Le transfert reste une **demande** (ADR-161) : il part de
l'onglet Sortie, et le séjour se termine au départ constaté dans Transferts.

## Délivrance au service : l'ADR-049 amendé pour ce seul cas

Une ordonnance du séjour crée une dispensation `hospital_stay_id` renseigné. Préparée,
elle passe **Prête** au lieu d'attendre le règlement, et se délivre dès qu'une facture non
annulée existe. Le patient est au lit : le médicament ne peut pas attendre la Caisse.

```text
dispensation ordinaire (ADR-049)   facture réglée ou couverte, PUIS délivrance
dispensation au service (ADR-162)  facture préparée, délivrance, règlement ensuite
```

L'argent n'est pas perdu : la facture existe et rejoint « Sorties & règlements », où
aucune sortie administrative n'est possible tant qu'il reste dû ou non facturé (ADR-090).
Annuler un paiement ne remet pas une dispensation au service « en attente de
règlement ». La Pharmacie n'encaisse toujours rien (ADR-013).

## Ce que deviennent les visites

`StartHospitalVisitAction` et `POST /hospitalisation/{séjour}/visites` sont retirés. Les
visites déjà ouvertes restent listées, en lecture, avec leur lien vers la consultation ;
une visite encore ouverte se clôture normalement dans l'assistant.

## Amendement du 2026-09-21 — ajouter un diagnostic depuis la sortie, et les repères à côté

Demande du propriétaire : « nous devons toujours avoir la possibilité d'ajouter un
diagnostic final lors de la sortie d'hospitalisation — on garde ce qui est déjà fait,
mais on peut en rajouter un autre en cas de besoin », et une étape mieux lisible, avec
les autres informations dans une colonne latérale.

**Ajouter sans quitter l'étape.** Les diagnostics déjà consignés (passage et séjour,
ADR-147) restent cochés d'office. Ce qui manque s'ajoute désormais dans la section
« Conclusion médicale » elle-même — « Ajouter un autre diagnostic » — au lieu de renvoyer
vers « Vue d'ensemble › Diagnostics ». Le diagnostic est enregistré **sur le séjour**, par
la même route et le même droit (`diagnoses.create`), et arrive coché : le formulaire
partagé coche déjà tout diagnostic apparu sur la page. La saisie en cours de la sortie
n'est pas perdue — une soumission Inertia garde l'état de la page. Le champ d'ajout est
écrit une seule fois (`StayDiagnosisAdd`), employé par la Vue d'ensemble et par la sortie.

**Défaut corrigé au passage, révélé par cette demande.** Le formulaire compose le
diagnostic final des diagnostics cochés, **une ligne par diagnostic**.
`DischargeHospitalStayAction` comparait pourtant la liste entière à chaque diagnostic
connu — jamais égale dès qu'il y en a deux — et enregistrait donc la liste comme un
diagnostic de plus (« Hypothermie possible⏎Insuffisance pondérale⏎… »). Chaque ligne est
désormais lue pour elle-même : une ligne déjà connue n'est jamais recopiée, une ligne
nouvelle rejoint le séjour seule. La sortie garde la liste telle que le médecin l'a
signée. Aucune ligne concaténée n'existait en base : rien n'a eu à être réparé.

**Les repères à côté du formulaire.** L'ADR-132 avait sorti le formulaire d'une colonne
étroite où il s'écrasait ; il garde donc sa largeur, et c'est une colonne de repères
(`StayExitContext`, 20 rem, à droite à partir de 1280 px, dessous en deçà) qui se lit à
côté :

```text
Le séjour           entré le, jour de séjour, service · chambre, niveau de soins,
                    motif (replié à quatre lignes, « Lire tout le motif »)
Allergies           celles du dossier permanent, en rouge quand il y en a
Dernier relevé      TA, FC, SpO₂, T° et leurs repères (ADR-161, ADR-125)
Avant de conclure   ordonnances actives, examens sans résultat, passages au bloc non
                    terminés, consultations encore ouvertes — chaque point à relire
                    mène à l'onglet qui le porte
```

Rien n'y est ressaisi ni recalculé : tout vient de la page. Une section dont le compte
n'a pas le droit n'est pas servie — elle se tait plutôt que d'afficher un zéro qui se
lirait « rien en cours » (ADR-102). Le formulaire, plus étroit à côté de la colonne, ne
passe ses consignes sur trois colonnes qu'à partir de 1536 px (`narrow`) ; en deçà, deux.
Le libellé « Traitement de sortie » ne porte plus sa précision sur la même ligne — elle
se cassait en quatre — mais dessous.

Aucune permission, route ni règle de sortie ne change.

## Amendement du 2026-09-21 — le transfert propose les autres sites de la clinique

Demande du propriétaire : « pour le transfert vers un autre établissement, il faut
toujours proposer la liste des autres cliniques Saint Georges — si Ambondromamy est
actif, Mampikony et Boriziny sélectionnables — mais on peut saisir un autre
établissement à la main ».

Le champ « Établissement » de l'onglet Sortie était une saisie libre. Il devient une
liste : « À préciser plus tard » (le champ reste facultatif, ADR-114), les **autres**
sites de la clinique, et « Autre établissement… » qui ouvre la saisie libre — exigée
alors, sans quoi la demande ne part pas. C'est le motif que le formulaire de sortie
employait déjà (« Autre établissement »), repris à l'identique.

Les sites viennent de la configuration (`rivo.clinics`), jamais d'une base — chaque site
a la sienne (ADR-001, ADR-025) — et le site courant n'y figure jamais. La liste était
calculée **deux fois** (module Transferts, consultation) ; `App\Support\ClinicSites`
la porte désormais une seule fois pour les trois écrans, séjour compris. Le libellé qui
part sur la demande et la lettre est inchangé (« Clinique Saint Georges — Mampikony »).
La liste n'est servie qu'à qui peut demander un transfert (`transfer.request`).

Aucune règle de transfert ne change : le séjour se termine toujours au départ constaté
(ADR-161), et l'établissement se précise encore dans le module Transferts.

## Signalé, non tranché — traité par l'ADR-163

```text
retirer une analyse ou une imagerie   pas encore depuis le séjour (l'ADR-079 le permet
                                       seulement depuis une consultation)
propositions de l'ADR-111             ne s'appliquent pas encore aux ordonnances du séjour
consultation d'origine encore ouverte le passage n'atteint « Sorties & règlements »
                                       qu'une fois elle aussi clôturée (ADR-084)
```

Aucune règle de facturation nouvelle ; aucun rôle codé en dur (ADR-152).

---

# ADR-163 — Revenir sur un geste du séjour, et les limites de l'ADR-162

**Status:** ACCEPTED (2026-09-21 — exigence explicite du propriétaire : « si patient mis à
la chirurgie on peut toujours revenir ou annuler l'action, et comme ça pour la visite de
service », « traitez ces limites non traitées » ; arbitrage : le transfert au bloc
s'annule **tant que le bloc ne l'a pas programmé**)

**Complète l'ADR-160 et l'ADR-162**, **amende l'ADR-113** (garde d'annulation du séjour)
et **l'ADR-086** (données Pharmacie d'essai). Le CDC ne décrit ni l'annulation d'une
demande chirurgicale ni celle d'une visite : les règles viennent du propriétaire et des
décisions existantes (ADR-084, ADR-079, ADR-127).

## Le principe : annuler, jamais effacer

Chaque geste revient en arrière **par un changement d'état tracé**, jamais par une
suppression (ADR-010) : la ligne reste, avec qui l'a annulée, quand, et le motif —
facultatif (« ouverte par erreur » se suffit ; l'exiger pousserait au remplissage). Rien
de ce qui a déjà été fait par un autre service n'est défait en silence.

```text
transfert au bloc     annulable tant que « À programmer » ; programmé → il appartient au bloc
visite de service     annulable tant qu'elle n'a rien produit qui doive survivre
examen du séjour      retirable tant qu'aucun résultat n'est saisi (règle de l'ADR-079)
transfert externe     annulable tant que le patient n'est pas parti
```

## Transfert au bloc

`WithdrawSurgicalRequestAction` porte **la** règle, désormais partagée par la
consultation qui change de conduite à tenir (ADR-084) et par le séjour
(`CancelSurgeryFromStayAction`, `POST /hospitalisation/{séjour}/bloc/{demande}/annuler`,
`surgery.request` — retirer est la même autorité que demander). Refusé dès que le bloc a
programmé, avec un message qui renvoie vers l'équipe du bloc.

Le patient n'a jamais quitté son lit (ADR-160) : il n'y a rien à « faire revenir ».
`surgical_requests` reçoit `cancelled_at`, `cancelled_by`, `cancellation_reason`.
L'orientation du passage vers le bloc, commune à toutes ses demandes (`active_key` =
passage + destination), n'est annulée que si plus aucune demande ne l'utilise — le
chemin de la consultation l'annulait jusqu'ici même si le séjour avait envoyé une autre
intervention.

Deux origines se retirent d'ici : le séjour lui-même, et la conduite à tenir d'une
consultation du passage (une visite de service d'avant l'ADR-162) — sa conduite
« Chirurgie » est alors annulée avec la demande, et une consultation encore ouverte perd
sa décision. Une demande née à la Réception ou en Maternité se retire là où elle a été
faite.

## Visite de service

`CancelHospitalVisitAction` (`POST /hospitalisation/{séjour}/visites/{orientation}/annuler`,
`consultations.create`) : la consultation passe **« Annulée »** (le statut existait sans
usage ; `consultations` reçoit `cancelled_at`, `cancelled_by`, `cancellation_reason`), son
orientation est annulée, le brouillon est écarté. Ce qui y a été écrit reste lisible.

Elle ne s'annule que tant qu'elle n'a rien produit dont un autre service ou le dossier
dépende — `blockers()` les nomme, à l'écran comme dans le refus :

```text
un diagnostic actif           une ordonnance active        un examen en cours
des soins demandés            une sortie prononcée         une demande déjà prise en charge
```

Une conduite à tenir encore retirable (une demande au bloc « À programmer ») part avec
elle. Sinon, la visite se clôture (« Poursuite de l'hospitalisation », ADR-149). Seul le
médecin qui l'a ouverte l'annule (même règle que l'ADR-127). Le patient reste hospitalisé.

Une consultation n'a pas d'UUID : une visite se désigne par celui de son orientation,
comme dans l'assistant Médecine — la liste des visites s'appuyait jusqu'ici sur une clé `null`.

`EpisodeOrientation::cancelTakenUp()` est la seule sortie d'un état « pris en charge »
vers « annulé », réservée aux gestes qui ont d'abord vérifié, sous verrou, que rien de
durable n'a été produit (annulation du séjour, de la visite).

## Les trois limites de l'ADR-162

**Retirer un examen depuis le séjour.** `CancelParaclinicalRequestAction::executeForStay()`,
même règle qu'en consultation. Au passage, un défaut réel : retirer **une** demande en
consultation la sortait de la file du Laboratoire **sans annuler ce qu'elle avait porté au
compte du patient** — seul le « Non » en bloc appliquait l'ADR-105. `ParaclinicalBillingRelease`
porte désormais la règle pour les trois chemins, en n'annulant que ce que la demande a
**elle-même** facturé (sa clé d'idempotence) : la prestation que la Réception avait facturée
à l'arrivée (ADR-109) n'est jamais annulée par le médecin, et une demande retirée la
**libère** pour un examen redemandé ensuite (`PlannedServiceBilling`), comme un acte
Maternité retiré (ADR-141).

**Propositions d'ordonnance au séjour.** L'ordonnance des protocoles et de la pratique de
la clinique (ADR-111) est proposée sur la page du séjour pour les diagnostics **du
passage** — posés en consultation ou conclus sur le séjour (ADR-147).
`ClinicalProtocolMatcher::stayContext()` lit ce contexte ; `PrescriptionSuggestions`
compose la proposition **une seule fois** pour la consultation et le séjour. L'origine
d'une ligne retenue est revérifiée par le serveur contre ces diagnostics ; une origine
forgée est refusée. Aucun diagnostic n'est proposé sur le séjour : on ne lit pas de texte
libre, on traite ce qui est posé.

**Consultations restées ouvertes.** La page du séjour liste, en tête de « Vue d'ensemble »
et de « Sortie », les consultations du passage encore ouvertes (celle qui a demandé
l'hospitalisation, une visite), avec ce qui manque pour les clôturer
(`ConsultationWorkflow::closureBlockerMessages()`), et les **clôture d'un clic** quand
plus rien ne manque (`POST /hospitalisation/{séjour}/consultations/{orientation}/cloturer`,
`consultations.update`, par `CompleteConsultationAction` — la règle de clôture n'est pas
assouplie, ADR-076). Rien n'est clôturé à la place du médecin.

## La garde d'annulation du séjour

L'ADR-113 n'autorisait plus l'annulation du séjour une fois la fiche de régime commencée.
Depuis l'ADR-162, une note du jour, une ordonnance, un examen, une demande de soins, un
relevé, un diagnostic du séjour, un changement de lit, un passage au bloc ou un transfert
disent la même chose : le patient a réellement séjourné. `CancelHospitalStayAction::activity()`
les nomme, et le séjour ne s'annule plus — il se termine par une sortie médicale. Cela ferme
le piège signalé par l'ADR-160 (changer la conduite à tenir de la consultation d'origine).
Une demande retirée depuis compte encore : elle prouve que le séjour a eu lieu.

## Transfert externe

`CancelStayReferralAction` (`POST /hospitalisation/{séjour}/transfert/{demande}/annuler`,
`transfer.request`) : tant que le patient n'est pas parti, la demande est annulée et son
orientation aussi ; le séjour continue et un nouveau transfert redevient possible.

## Passage libéré

Retirer une visite, une demande au bloc ou un transfert peut laisser un passage sans plus
aucun service qui ait le patient : `EpisodeSettlement::advanceWhenNoServiceLeft()`
applique alors la règle de l'ADR-054 (« Sorties & règlements »). Un patient encore au lit
n'est jamais concerné.

## Données Pharmacie d'essai

Voir l'amendement de l'ADR-086 : 38 médicaments fictifs rechargés par `migrate:fresh --seed`,
créés une seule fois, jamais réécrits.

## Amendement du même jour — plus de carte « Visites de service »

Question du propriétaire : tout se faisant désormais sur la page du séjour, la
carte « Visites de service » a-t-elle encore un objet ? Non. Plus aucune visite
ne s'ouvre (ADR-162), et une visite close ou annulée se relit avec le reste du
dossier sur la page du passage (`/passages/{uuid}`, ADR-054) : la carte ne
faisait que doubler cette lecture.

Elle est retirée. Ce qui ne pouvait pas disparaître avec elle est une visite
**restée ouverte** : tant qu'elle l'est, un service a encore le patient et le
passage n'atteint pas « Sorties & règlements » (ADR-084). Elle paraît donc là
où elle compte — « Consultations encore ouvertes » —, qui porte désormais les
deux issues : la clôturer, ou l'annuler tant qu'elle n'a rien produit. Le
`prop` `visits` et le composant `StayServiceVisits` disparaissent ; l'action,
la route et la règle d'annulation ne changent pas.

## Signalé, non tranché

```text
déprogrammer une intervention   aucun geste du bloc ne l'annule une fois programmée
                                (CDC §16 : aucune permission surgery.cancel)
orientation vers le bloc        jamais prise en charge ni terminée par le module
                                Chirurgie : un passage passé par le bloc n'atteint
                                « Sorties & règlements » qu'une fois elle annulée
plan de prise en charge,        retenus pour le lot 2 de l'ADR-161, sans règle
médecin référent                définie à ce jour
```

Aucune permission nouvelle ; aucun rôle codé en dur (ADR-152).

---

# ADR-164 — Services, chambres et lits : un référentiel par site, une occupation qui se lit

**Status:** ACCEPTED (2026-09-21 — exigence explicite du propriétaire : « gérer la
chambre déjà occupée, le lit déjà occupé par un autre patient, la gestion chambre, lit
avec quantité dans le Super Admin » ; quatre arbitrages : Service › Chambre avec
quantité de lits, attribution **après** l'admission, états Libre / Occupé / Hors
service, niveau de soins porté par le service)

**Amende l'ADR-113** (« chambre / lit : texte libre facultatif, sans gestion
d'occupation des lits ») et **complète l'ADR-161** (historique des emplacements). Le CDC
ne décrit ni lits ni chambres (§32 connaît seulement le statut « Hospitalisé ») : les
règles ci-dessous sont celles du propriétaire.

## Le constat

Le service et la chambre / le lit d'un séjour étaient du texte libre. Rien n'empêchait
d'écrire « Chambre 12 · Lit 1 » pour deux patients à la fois, et personne ne pouvait
répondre à « quels lits sont libres ? » sans parcourir chaque séjour.

## Le référentiel appartient au site, et se règle depuis le portail

```text
hospital_services   nom, niveau de soins (standard / surveillance continue / réanimation)
hospital_rooms      nom, dans un service
hospital_beds       libellé, dans une chambre ; hors service avec motif et auteur
```

Chaque site a le sien, dans sa base (ADR-001). Le Super Administrateur le règle depuis
`admin.rivo.mg` › **Services, chambres et lits** (`/super-admin/hospital-beds`), site par
site, **uniquement par l'API du site** (`/api/v1/super-admin/hospital-beds*`,
authentifiée, idempotente, acteur distant UUID/nom à l'audit — ADR-004, ADR-042). Aucune
connexion SQL depuis le portail.

**Créer une chambre, c'est donner son nombre de lits** : « Chambre 12, 3 lits » crée
Lit 1, Lit 2, Lit 3. On peut ensuite renommer un lit, en ajouter (la numérotation
reprend après le plus haut, archivés compris, pour ne jamais réutiliser un libellé), le
mettre hors service ou l'archiver. Trente lits au plus par chambre — une borne de saisie,
pas une règle clinique.

Les noms se comparent sans accents, espaces ni majuscules. Un nom porté par un élément
archivé ne se recrée pas : il se restaure (même règle que les adresses, ADR-042).

## Occupé : jamais saisi, toujours lu

```text
Libre         aucun séjour en cours sur ce lit, et en service
Occupé        un séjour ACTIVE le porte
Hors service  décision manuelle, avec motif (panne, travaux, désinfection…)
```

L'occupation n'est **jamais** une case que quelqu'un coche : elle se lit sur les séjours.
`hospital_stays.bed_active_key` vaut `BED_{id}` tant que le séjour est actif, `null`
ensuite ; son **index unique** interdit en base que deux séjours en cours occupent le même
lit — même à deux clics simultanés. Le lit est en plus verrouillé avant d'être relu
(`HospitalBedAllocator::lockFree()`), et un lit pris renvoie un message qui nomme
l'occupant plutôt qu'une erreur brute. Toute fin de séjour — sortie médicale, départ en
transfert, annulation — libère le lit sans geste supplémentaire (le modèle efface la clé).

Refusés tant qu'un patient y est : mettre un lit hors service, archiver un lit, une
chambre ou un service. Le message dit d'installer d'abord le patient ailleurs.

## Le patient est installé après l'admission

L'admission reste automatique à la transmission de la demande (ADR-113) : le séjour
s'ouvre **« lit à attribuer »**. Sur la page du séjour, « Attribuer un lit » propose les
seuls lits libres et en service, regroupés par service et chambre
(`CorrectHospitalStayLocationAction`) ; « Changer de lit » déplace le patient et ouvre un
nouvel emplacement dans l'historique de l'ADR-161 (`MoveHospitalStayAction`). Première
attribution et correction ne créent pas de mouvement : ce n'est pas un déplacement.

`/hospitalisation` porte un onglet **Plan des lits** (par service, chaque lit libre,
occupé — avec le patient — ou hors service) et signale les séjours sans lit. Droits
inchangés : `hospitalization.view` pour lire, `hospitalization.update` pour installer ou
déplacer.

## Le niveau de soins suit le service

Un lit de réanimation dit « réanimation » : l'emplacement reprend le niveau de soins du
service au moment où le patient y est installé. Changer le niveau d'un service ne réécrit
pas les emplacements déjà ouverts — il vaut pour les installations suivantes.

## L'historique n'est jamais réécrit

`hospital_stays.service` / `room_bed` et ceux des emplacements restent l'**instantané**
lu par tous les écrans existants (liste, dossier, fiche de régime, dossier médical,
Sorties & règlements, bloc). `hospital_bed_id` s'y ajoute. Renommer un lit plus tard ne
change pas ce qu'un séjour terminé affiche.

## Transition : texte libre jusqu'au premier lit

Un site qui n'a encore configuré **aucun** lit garde le texte libre de l'ADR-113 —
sinon plus personne ne pourrait noter où est le patient le jour du déploiement. Dès le
premier lit configuré, le texte libre est refusé côté serveur (`prohibited`) : on choisit
un lit. Les séjours en cours saisis en texte libre gardent leur texte et reçoivent un lit
quand on les installe.

Rien ne disparaît en silence pendant cette transition (constat du propriétaire le jour même :
« je ne trouve pas ces mises à jour à l'écran »). L'onglet **Plan des lits** reste affiché
sans lit configuré et dit où les lits se créent ; la page du séjour dit pourquoi la chambre
se note encore à la main. Un onglet absent se lit « fonction inexistante » (ADR-158).

## Ce que le portail voit, et ce qu'il ne voit pas

Le portail voit l'occupation de chaque lit — numéro de passage et date d'admission —
**jamais le nom du patient** : il gère des lits, pas des dossiers. Le nom n'est servi
qu'aux écrans de la clinique, à qui peut lire le séjour.

## Permissions

```text
hospital_beds.view / create / update / archive / restore
```

Enregistrées par migration (`2026_10_18_090000_create_hospital_beds_referential`,
ADR-064), accordées au seul `SUPER_ADMIN` du portail (ADR-027) ; l'API du site
revérifie la permission transmise. **La migration se joue aussi sur la base du portail** :
c'est elle qui accorde ces droits au Super Administrateur. Sans elle, l'écran répond
403 « Il vous manque le droit hospital_beds.view » (constaté en local le jour même). Chaque écriture est auditée (`Auditable` sur les trois
modèles, identité externe du Super Administrateur).

## Hors périmètre

Aucune facturation à la nuitée ou au lit (ADR-113 : aucun forfait journalier défini),
aucune réservation d'un lit pour une admission future, aucun nettoyage automatique après
une sortie : « Hors service » sert à dire qu'un lit ne peut pas être donné.

---

# ADR-165 — Liste des hospitalisés : sélection multiple, en lecture seulement

**Status:** ACCEPTED (2026-09-21 — exigence explicite du propriétaire : « mettre les icônes, et
la sélection multiple, toujours en shadcn » ; deux arbitrages : les quatre actions ci-dessous, et
un droit dédié `hospitalization.export` accordé à Médecine et Soins)

**Complète l'ADR-113, l'ADR-161 et l'ADR-164** (liste des hospitalisés) et reprend le schéma de
sélection de l'ADR-090 (amendement ter). Le CDC ne décrit aucune action groupée sur les séjours :
les règles ci-dessous sont celles du propriétaire.

## Ce qu'une sélection permet, et ce qu'elle ne permet jamais

Cocher des patients dans `/hospitalisation` ouvre une barre d'actions. Toutes **lisent** ; aucune
n'écrit dans un dossier :

```text
Tour de salle       une feuille, une ligne par patient : lit, motif, séjour, allergies,
                    dernier relevé, et une colonne « Notes de visite » laissée vide
Fiches de régime    une fiche par patient, une par page — la même que l'impression unitaire
Dossiers médicaux   le dossier médical de chaque passage, un par page — la même feuille
Exporter en Excel   la liste sélectionnée, auditée
```

**Jamais en lot** : sortie médicale, transfert, descente au bloc, changement de lit, saisie de la
fiche de régime. Chacune est une décision clinique sur un patient précis, prise sur la page de son
séjour (ADR-161, ADR-162, ADR-164). À la différence de « Sorties & règlements » (ADR-090), aucune
écriture n'est proposée ici, pas même la plus simple.

## Les règles de la sélection

```text
envoi          l'écran n'envoie que des UUID de séjours (uuids[]), 50 au plus
relecture      le serveur relit tout : un séjour annulé est écarté, un séjour terminé
               reste imprimable (sa fiche de régime et son dossier restent consultables)
ordre          service, puis chambre / lit, puis date d'entrée — l'ordre d'une visite
remise à zéro  la sélection ne survit ni à un changement de page ni à une recherche
```

## Aucune feuille n'est recopiée

La fiche de régime est désormais composée par `App\Support\Hospitalization\DietSheet`, lue par
l'impression unitaire **et** par l'impression groupée ; son corps (`DietSheetBody`) est un seul
composant. Les dossiers médicaux groupés affichent `MedicalRecordPrint` lui-même, une fois par
passage : deux mises en page du même document finiraient par diverger. Le dernier relevé du tour
de salle vient de `HospitalStaySurveillance::latestReading()`, avec les repères déjà calculés par
le serveur (ADR-125). Dans un document groupé, seule la première feuille porte la barre d'actions
et un saut de page sépare les suivantes.

## Chaque feuille garde son droit

```text
ouvrir une impression groupée   hospitalization.view
dossiers médicaux               + patients.view — et, dans chaque feuille, les gardes
                                par section de l'ADR-116 (diagnostic, traitements,
                                hospitalisation, constantes) restent intactes
dernier relevé du tour de salle vitals.view — sans ce droit la colonne est retirée,
                                jamais affichée vide (une case vide se lirait « aucun relevé »)
exporter                        hospitalization.export
```

Imprimer n'a pas de droit propre : c'est le fait du navigateur, et un droit « imprimer » ne
bloquerait rien (ADR-070, ADR-116). L'export, lui, produit un fichier qui sort de l'application :
il est audité (`hospitalization.export`, nombre de lignes et numéros de passage).

## Le tour de salle passe seul en paysage

Huit colonnes, dont un motif en texte libre, ne tiennent pas dans un A4 portrait. Une page nommée
CSS (`@page wardround`) a d'abord été écrite : Chromium l'ignore sur un élément imbriqué dans les
conteneurs flex de la mise en page, et la feuille sortait en portrait (vérifié en générant le PDF).
La règle `@page { size: A4 landscape }` est donc injectée au montage de la page et retirée en la
quittant — le même idiome que la facture et le reçu. Rien n'est lu pendant le rendu serveur, et les
autres feuilles repassent en portrait après une navigation sans rechargement.

## Permission

```text
hospitalization.export   Exporter en Excel la liste des patients hospitalisés
```

Enregistrée par migration (`2026_10_19_090000_create_hospitalization_export_permission`,
ADR-064), accordée aux rôles `MEDICINE` et `NURSE`. **Pas à `RECEPTION`**, qui lit le module
(ADR-147) : une liste de patients hospitalisés est une extraction de données personnelles, et le
Super Administrateur l'accorde depuis « Rôles & permissions » s'il le décide. La migration se joue
sur chaque site et sur le portail, dont l'éditeur de socle doit lister le droit.

## L'écran

Shadcn-vue seulement (ADR-099) : une icône par en-tête de colonne, initiales du patient, pastille
« Urgence », niveau de soins, onglets « Patients hospitalisés » et « Plan des lits » avec leurs
icônes, actions de ligne en icônes nommées (fiche de régime, dossier médical) à côté de « Ouvrir ».
La barre de sélection dit combien de patients sont cochés et se replie sur plusieurs lignes sur un
téléphone ; le tableau défile dans son cadre, jamais la page.

## Hors périmètre

Aucune sélection sur le « Plan des lits », aucune action groupée qui écrive, aucun PDF généré par
le serveur (ADR-118).

---

# ADR-166 — La suite des Soins se choisit à l'étape Terminer

**Status:** ACCEPTED (2026-09-21 — exigence explicite du propriétaire : « à l'étape Terminer,
pouvoir terminer le patient aux Soins même si son besoin est une consultation, et envoyer au
médecin un patient venu seulement pour un soin — un système flexible » ; trois arbitrages : la
consultation prévue reste facturée, un motif est obligatoire, l'ordre de soins du médecin garde
sa suite)

**Amende l'ADR-030** (un parcours `CARE_THEN_MEDICINE` part toujours en Médecine, un parcours
`CARE_ONLY` s'arrête toujours aux Soins), **l'ADR-032** (la transmission n'existe que pour un
parcours qui continue vers Médecine) et **l'ADR-123** (la colonne de transmission suit ce même
parcours). Le CDC ne décrit aucune règle de routage entre Soins et Médecine (§12, §32) : la
règle ci-dessous est celle du propriétaire.

## Le constat

La désignation choisie à l'arrivée décidait seule de la suite. Un patient venu pour une
« Consultation de médecine générale » partait en Médecine même quand le soin avait suffi, et un
patient venu pour une injection ne pouvait pas être montré au médecin sans détour, alors que
c'est l'infirmier qui a le patient sous les yeux.

## La règle

La désignation **propose** la suite, l'infirmier la **décide** :

```text
parcours prévu        pré-sélection        l'infirmier peut choisir
CARE_THEN_MEDICINE    Transmettre          Terminer aux Soins — motif obligatoire
CARE_ONLY             Terminer aux Soins   Transmettre au médecin — sans motif
besoin inconnu        aucune               l'un ou l'autre (règles de l'ADR-032)
```

`CompleteCareAndOrientToMedicineAction` reçoit la suite choisie (`care_outcome` :
`MEDICINE` ou `FINISH`, absente = suivre le parcours) et revérifie tout sous verrou :

```text
Terminer aux Soins un patient attendu en Médecine   motif exigé (care_finish_reason)
Terminer aux Soins un parcours Soins seuls          au moins un acte (ADR-032, inchangé)
Terminer aux Soins un besoin inconnu                un acte ou le motif « aucun acte » (ADR-032)
Transmettre au médecin                              rien d'exigé ; la transmission s'ouvre
```

Envoyer au médecin un patient prévu aux Soins seuls ouvre la transmission : la requête et
`SaveCareRecordAction` l'acceptent dès que la suite choisie est Médecine
(`CareWorkflow::expectsMedicalTransmission($episode, $chosen)`). L'orientation Médecine créée le
dit : « Orientation vers Médecine décidée aux Soins, hors du parcours prévu ».

## Là où il n'y a rien à choisir

```text
ordre de soins d'un médecin   sa suite (retour ou sortie directe) reste celle du médecin (ADR-055)
Médecine a déjà le patient    urgence, consultation en cours ou close : une seule prise en charge
                              Médecine par passage (ADR-085). Terminer le travail Soins ne retire
                              rien au médecin et n'exige aucun motif
```

`CareWorkflow::medicineAlreadyInvolved()` porte ce second cas une seule fois : l'action et
l'écran le lisent tous deux.

## Le motif, et ce qu'il devient

`episode_orientations.completion_reason` (nullable, migration
`2026_10_20_090000_add_completion_reason_to_episode_orientations`) garde le motif d'une fin qui
s'écarte du parcours prévu ; une fin ordinaire le laisse vide. Il est audité
(`care.orientation.finish_at_care`, parcours prévu et motif), relu sur la fiche Soins une fois
terminée, et affiché dans le parcours du passage (ADR-117) : c'est là que la Réception voit, à la
sortie, pourquoi la consultation n'a pas eu lieu.

**La consultation prévue reste facturée** (arbitrage du propriétaire). Rien n'est annulé ni
recalculé par les Soins : seule la Réception/Caisse touche un montant facturé (ADR-012), et
l'infirmier ne voit aucun montant (ADR-036).

Plus aucun service n'a le patient : le passage rejoint « Sorties & règlements » par la règle
générale de l'ADR-054 (`EpisodeSettlement::advanceWhenNoServiceLeft`).

## L'écran

L'étape Terminer s'ouvre sur « Suite après les soins » : deux cartes radio — « Transmettre au
médecin », « Terminer aux Soins » —, la suite prévue cochée et marquée « Prévu à l'arrivée ».
Choisir de terminer aux Soins un patient attendu en Médecine fait apparaître le motif ; le bouton
reste grisé tant qu'il manque. La colonne de transmission suit la suite choisie. Shadcn-vue
seulement (ADR-099).

Changer seulement la suite ne crée jamais de fiche vide : sans rien à enregistrer, l'écran
termine par `POST /care/orientations/{o}/complete` avec la seule suite et son motif ; sinon il
enregistre et termine en une transaction. Le choix et le motif survivent à une actualisation (le
brouillon de l'ADR-073 les accepte).

`orient_to_medicine`, lu par les appelants antérieurs, garde son sens : vrai = Médecine, faux =
suivre le parcours — jamais « terminer ».

## Permissions

Aucune nouvelle : `care.complete`, comme toute fin de prise en charge Soins. Aucun rôle n'est
codé en dur (ADR-152).

## Signalé, non tranché

```text
acte prérempli par l'accueil   envoyé au médecin sans l'avoir réalisé, l'infirmier retire la
                               ligne dans « Actes » ; sinon elle s'enregistre comme réalisée
                               (comportement antérieur, inchangé)
besoin inconnu terminé aux     le passage reste « en soins » : l'ADR-054 ne fait avancer que le
Soins sans Médecine            parcours Soins seuls. Inchangé ici — à décider
```

## Amendement du 2026-09-21 — la suite prévue se lit, toute autre suite demande un motif

Demande du propriétaire, avec deux arbitrages : « le bouton prévu est seulement à titre
d'information, le bouton non prévu est cliquable et demande un motif », **dans les deux sens**.

```text
parcours prévu        carte prévue              carte non prévue
CARE_THEN_MEDICINE    Transmettre — se lit      Terminer aux Soins — motif obligatoire
CARE_ONLY             Terminer — se lit         Transmettre au médecin — motif obligatoire
besoin inconnu        rien de prévu             les deux se choisissent, sans motif
```

La ligne « CARE_ONLY → Transmettre au médecin, sans motif » du tableau ci-dessus est donc
remplacée : envoyer au médecin un patient prévu aux Soins seuls exige aussi un motif, refusé
côté serveur sans lui (audit `care.orientation.send_to_medicine`). Le motif accompagne le
patient — l'orientation Médecine porte « Orientation vers Médecine décidée aux Soins, hors du
parcours prévu — motif : … » — et reste sur `completion_reason`. Le champ s'appelle désormais
`care_outcome_reason` (il portait `care_finish_reason`, qui ne disait qu'un sens).

À l'écran, la carte prévue n'est plus un bouton : elle s'applique d'office, en bordure bleue.
La carte non prévue (« Changer · motif ») est un interrupteur qui ouvre le motif ; « Garder la
suite prévue », ou un second clic, revient au parcours prévu. Le parcours du passage et la fiche
terminée disent dans quel sens la suite a changé (`EpisodeOrientation::offPlanOutcome()`).

---

# ADR-167 — Reprendre la prise en charge Soins d'un collègue

**Status:** ACCEPTED (2026-09-21 — signalement du propriétaire sur A-26-0003 : « je ne vois
pas le bouton » de l'étape Terminer ; arbitrage : reprise avec motif obligatoire, réservée à
`care.complete`)

**Construit ce que l'ADR-085 laissait hors périmètre** (« aucune reprise d'un patient par un
autre soignant n'est définie : elle exigera une règle explicite et tracée ») et que l'ADR-092
reconduisait. **Complète l'ADR-166** : la suite des soins n'est plus masquée à qui ne peut pas
la décider. Le CDC ne décrit pas cette reprise : la règle est celle du propriétaire.

## Le constat

Le patient A-26-0003 avait été pris en charge aux Soins par un compte Médecine (dont le socle
porte `care.create` sur ce site, décision du portail, ADR-064), et l'infirmière qui l'avait
réellement au chevet ouvrait la fiche. L'ADR-085 réservant la fin des Soins à celui qui a pris
le patient, elle ne voyait à l'étape Terminer que « Enregistrer la fiche » : le choix de
l'ADR-166 était masqué, sans un mot. Personne ne pouvait décider la suite tant que ce compte
ne revenait pas.

## La règle

« Reprendre la prise en charge » (`TakeOverCareOrientationAction`,
`POST /care/orientations/{o}/take-over`, `care.complete`) :

```text
permis       orientation Soins EN COURS, passage ouvert, prise en charge d'un autre compte
motif        obligatoire (1 000 caractères au plus), jamais deviné
effet        accepted_by = le compte qui reprend : il décide la suite (ADR-166) ;
             l'ancien soignant ne termine ni ne transmet plus, il corrige encore (ADR-092)
refusé       patient en attente (on le prend dans la file), soins terminés, passage clos,
             reprendre son propre patient
```

`accepted_at` **n'est pas réécrit** : c'est le début réel des soins, et la remise en file
(ADR-122) compte le travail enregistré depuis ce moment. La réécrire laisserait renvoyer en
file un patient déjà soigné par le premier soignant. `taken_over_at`, `taken_over_from` et
`takeover_reason` (migration `2026_10_21_090000`) gardent la dernière reprise pour l'écran ;
l'audit `care.orientation.take_over` garde toutes les reprises avec l'ancien et le nouveau
soignant.

## L'écran

À l'étape Terminer, la « Suite après les soins » se montre **verrouillée** plutôt que masquée
(même parti pris que l'ADR-158) : les deux suites, la prévue marquée, « Cette décision revient
à … », ce que le compte peut encore faire (corriger la fiche, ADR-092, ou la lire seulement) et
le bouton de reprise. C'est le **seul** avertissement de la page (amendement du même jour, à la
demande du propriétaire) : l'ancien bandeau en haut de la fiche est retiré, il répétait le même
message ; la ligne « Pris en charge … par … » reste en haut. Les deux suites verrouillées se
cliquent : elles ouvrent la reprise, et la suite cliquée est sélectionnée dès que la reprise
aboutit (sans rien valider — le bouton de l'étape confirme). La reprise passe par une fenêtre qui nomme
le soignant remplacé, exige le motif et ne se ferme pas sur un clic à côté ; la page reste en
place, la saisie en cours n'est pas perdue, et la suite devient aussitôt choisissable. Après
reprise : « Repris le … à … — motif : … » sous la ligne de prise en charge.

## Permissions

Aucune nouvelle : `care.complete`, le droit de ce que la reprise permet — terminer les soins.
Sans lui, la fiche le dit (« La reprendre demande le droit « care.complete » »). Aucun rôle
codé en dur (ADR-152).

## Hors périmètre

La même reprise en Maternité, en Médecine ou au bloc : non demandée, non construite.

## Amendement du 2026-09-21 — un seul motif pour reprendre et changer de suite

Question du propriétaire : « quelle différence entre cliquer une suite et Reprendre la prise en
charge ? les deux demandent un motif ». Arbitrage : **un seul motif**. Quand le patient est tenu
par un collègue, la carte prévue se lit seulement, et la carte non prévue ouvre une seule
fenêtre, « Changer la suite prévue » : un motif (« Pourquoi changer la suite prévue ? ») qui
trace la reprise **et** justifie le changement. Après la reprise, la suite est sélectionnée et
le motif déjà rempli ; on confirme avec le bouton de l'étape. « Reprendre la prise en charge »
reste le geste pour garder la suite prévue, avec son propre motif de reprise. Pour un besoin
inconnu (rien de prévu), les deux cartes ouvrent la reprise, la suite choisie retenue.

## Amendement du 2026-09-21 (bis) — une seule fenêtre, une case, plus de bandeau

Demande du propriétaire : fusionner les deux fenêtres et retirer le bandeau jaune. Il n'existe
plus qu'**une** fenêtre de reprise, avec **un** motif, suivi au bas d'une case à cocher
« Reprendre la prise en charge », qui explique ce qu'elle entraîne (vous devenez le soignant
responsable ; l'ancien ne termine ni ne transmet plus, il corrige encore la fiche, ADR-092).

```text
la case          jamais cochée d'avance ; le bouton reste grisé tant qu'elle n'est pas
                 cochée ET le motif écrit. C'est le consentement explicite à la reprise :
                 sans reprise, la suite reste la décision du soignant responsable (ADR-085)
titre            « Changer la suite prévue » (carte non prévue) ;
                 « Reprendre la prise en charge » sinon — même fenêtre, même motif
bouton           « Reprendre et changer la suite » / « Reprendre la prise en charge »
```

Le bandeau « Cette décision revient à … » est retiré : qui a le patient se lit déjà dans
l'en-tête (« Pris en charge … par … »). Seul un compte **sans** `care.complete` reçoit encore
une phrase simple, sans cadre, qui nomme le droit manquant (ADR-154).

**Garder la suite prévue et reprendre** (fin de garde, patient pris sur le mauvais compte) :
la carte prévue ne se cliquant pas, ce geste passe par le pied de l'étape Terminer — un bouton
« Reprendre la prise en charge », là où le soignant responsable trouve son bouton de fin, à côté
de « Seul … peut terminer les soins. » Il ouvre **la même fenêtre**. Sans lui, un ordre de soins
en cours ou un passage déjà vu par Médecine (cas où aucune suite ne se choisit) ne pourrait plus
être repris. Le bloc « Suite après les soins » verrouillé n'est affiché que si une suite se
choisit.

---

# ADR-168 — Programmer l'intervention : chirurgien principal, aides, profil Chirurgien et planning RH

**Status:** ACCEPTED (2026-09-22 — exigence explicite du propriétaire : « si c'est le compte
chirurgien lui-même, il coche Moi-même, mais il peut ajouter d'autres ; si c'est un compte Soins
ou Médecine, il sélectionne les disponibles, un, deux, trois… » ; trois arbitrages : un principal
et des aides, disponibilité selon le planning RH, profil métier dans le rôle Chirurgie)
; le lien compte ↔ fiche Employé ne se pose plus depuis le formulaire Employé mais depuis
« Utilisateurs », à la création du compte (**ADR-188**).

**Complète l'ADR-048** (la programmation du bloc), **amende l'ADR-033** (le rôle SURGERY reçoit
ses profils métier) et s'appuie sur **l'ADR-066** (planning RH). Le CDC nomme les fonctions du bloc
(« chirurgien, anesthésiste, infirmier de bloc, paramédical », §9) mais ne dit rien de la
disponibilité ni du nombre de chirurgiens : ces règles sont celles du propriétaire.

## Le constat

« Programmer l'intervention » ne proposait qu'**un** chirurgien, choisi parmi les comptes dont le
**rôle** s'appelle SURGERY — un nom de rôle codé en dur (ADR-152) —, et le serveur acceptait
n'importe quel compte actif. Rien ne disait qui était réellement là à l'heure prévue, et un
chirurgien qui programmait sa propre intervention devait se chercher dans la liste.

## La règle

```text
date et heure      choisies d'abord : la disponibilité en dépend
« Moi-même »       une case, seulement pour un compte au profil Chirurgien ;
                   cochée, elle le met chirurgien principal
les autres         un, deux, trois… (5 aides au plus) parmi les chirurgiens disponibles
principal          le premier choisi (ou « Moi-même ») = surgical_requests.surgeon_id,
                   lu partout ; « Définir principal » en change
aides              les suivants = membres de l'équipe de bloc, fonction « Chirurgien »
                   — aucune table nouvelle
```

`ScheduleSurgicalRequestAction` juge tout côté serveur, dans une transaction : principal et aides
portent le profil, sont disponibles, distincts, et le principal n'est pas aussi aide. Les membres
« Chirurgien » de l'équipe deviennent **exactement** les aides choisis : un aide retiré quitte
l'équipe (retrait audité), l'ancien principal devient aide s'il est coché. Omettre
`assistant_surgeon_ids` laisse les aides intacts (ADR-074) ; une liste vide les retire.

## Un chirurgien est un profil, pas un rôle

Trois profils rejoignent le rôle SURGERY (migration `2026_10_22_090000`, jouée sur chaque site et
sur le portail ; `ProfessionalProfileSeeder` pour un site neuf) :

```text
SURGEON                Chirurgien / Chirurgienne — seul profil programmable comme chirurgien
OR_NURSE               Infirmier / Infirmière de bloc
SURGICAL_PARAMEDICAL   Paramédical du bloc
```

Aucune permission recommandée : le socle SURGERY reste la seule source des droits, et un profil
ne donne jamais de droit (ADR-033). Les comptes SURGERY existants restent **sans profil**
(« Profil métier à définir ») : aucune qualification n'est déduite. Le Super Administrateur la
pose dans Utilisateurs ; tant qu'aucun compte n'a le profil Chirurgien, **aucune intervention ne
peut être programmée**, et l'écran le dit.

## Disponible : lu sur le planning RH, jamais saisi

`SurgeonRoster` lit la fiche Employé reliée au compte (`employees.user_id`) et ses créneaux
(`planning_shifts`) à l'heure programmée :

```text
AVAILABLE        un créneau couvre l'heure programmée         → proposé
OFF_PLANNING     fiche reliée, aucun créneau à cette heure     → verrouillé, refusé
INACTIVE_RECORD  fiche reliée mais inactive ou archivée        → verrouillé, refusé
UNLINKED         aucune fiche reliée : le planning ne dit rien → proposé, « Non vérifié »
```

Un chirurgien indisponible est **montré verrouillé avec sa raison**, pas masqué (même parti pris
que l'ADR-158). Un chirurgien UNLINKED porte le badge « Non vérifié » sur sa ligne ; l'explication
(aucune fiche RH reliée, où la relier) est donnée par un toast, une fois par ouverture du
formulaire (demande du propriétaire, 2026-09-22). L'écran lit la disponibilité sur `GET /surgery/{demande}/surgeons?at=`
(`surgery.schedule`) ; le serveur la rejuge à l'enregistrement.

**UNLINKED est accepté, et c'est une transition signalée.** Aucun site n'a encore relié de fiche
RH à un compte : refuser un chirurgien au planning inconnu bloquerait toute programmation au
déploiement. La règle se durcit d'elle-même à mesure que les RH relient les fiches (même esprit
que l'ADR-164). Refuser aussi UNLINKED est une décision à prendre avec le propriétaire.

## Relier une fiche RH à un compte

`employees.user_id` existait (unique, facultatif) sans qu'aucun écran ne le remplisse. Le
formulaire Employé reçoit « Compte de connexion » (`employees.create` / `employees.update`,
droits existants), résolu par `EmployeeAccountResolver` :

```text
un compte, une fiche      archives comprises : deux fiches donneraient deux plannings
nouveau lien              compte actif seulement ; un lien existant survit à la désactivation
proposés                  comptes actifs non reliés, jamais un compte SUPER_ADMIN
effet                     rend le planning lisible ; ne crée aucun compte, ne donne aucun droit
```

## Équipe de bloc et opérateur

Le formulaire libre de l'équipe ne propose plus la fonction « Chirurgien » et le serveur la
refuse : un chirurgien entre dans l'équipe par la programmation, sans quoi le profil et le
planning seraient contournables. L'opérateur de l'intervention se choisit parmi les chirurgiens
programmés (principal en tête).

**Complément du même jour — chaque fonction a son profil.** Le formulaire de l'équipe listait
tous les comptes actifs, réception comprise, et le serveur acceptait n'importe lequel. Chaque
fonction est désormais tenue par le profil métier qui lui correspond (CDC §9, ADR-033) — la
règle déjà appliquée au choix de l'anesthésiste d'un dossier d'anesthésie :

```text
Anesthésiste        profil ANESTHETIST (rôle Soins)
Infirmier de bloc   profil OR_NURSE (rôle Chirurgie)
Paramédical         profil SURGICAL_PARAMEDICAL (rôle Chirurgie)
Chirurgien          profil SURGEON — par la programmation seulement
```

On choisit la fonction, puis la personne parmi les seuls comptes de ce profil ; une fonction
sans compte le dit (« le Super Administrateur attribue ce profil métier dans Utilisateurs »).
`AssignSurgicalTeamMemberAction` refuse un compte sans le bon profil et une même personne deux
fois à la même fonction — revérifié côté serveur, jamais seulement à l'écran. La
correspondance fonction → profil vit dans `SurgicalTeamFunction::profileCode()` et est servie à
l'écran. La présence au planning RH n'est pas exigée pour l'équipe : à décider.

## Signalé, non tranché

```text
UNLINKED accepté           transition ci-dessus — à confirmer ou à durcir
heure de début seulement   la durée de l'intervention n'est pas connue : un créneau qui finit
                           pendant l'opération ne se voit pas
aucun contrôle de conflit  un chirurgien peut être programmé sur deux interventions à la même heure
après le démarrage         traité par l'amendement du 2026-09-22 ci-dessous
opérateur                  l'écran propose les chirurgiens programmés, le serveur accepte
                           encore tout compte actif
MEDICINE et surgery.schedule   accordé par le portail sur ce site : décision d'administration
```

Aucune permission nouvelle.

## Amendement du 2026-09-22 — au bloc, les aides et l'opérateur s'ajustent encore

Constat du propriétaire : sur un dossier « Au bloc », seules la salle et les consignes se
modifiaient ; un chirurgien arrivé en renfort ne pouvait plus être inscrit, et l'opérateur
réel ne pouvait plus être corrigé (la section Intervention ne corrige que la fin, le résumé
et les notes). Arbitrage du propriétaire, entre « rien », « seulement les aides », « aides
et opérateur » et « tout, date comprise » : **aides et opérateur**.

```text
figés au démarrage   la date programmée (l'heure réelle est le « Début » de l'intervention)
                     et le chirurgien principal — revu par l'amendement suivant
ajustables au bloc   les aides (ajout, retrait) et l'opérateur réel, choisi parmi le
                     principal et les aides — jamais un compte extérieur à l'équipe
conditions           dossier « Au bloc » (IN_PROGRESS) seulement ; profil Chirurgien
                     exigé ; le planning RH n'est pas revérifié (le patient est déjà sur
                     la table) ; retirer l'opérateur des aides exige d'en désigner un autre ;
                     un envoi qui ne change rien est refusé
trace                motif obligatoire, audit surgery.team.adjust avec l'ancienne et la
                     nouvelle équipe
droit                surgery.schedule, la même autorité que programmer
```

`AdjustSurgicalTeamDuringInterventionAction` (`POST /surgery/{demande}/team-adjustment`)
juge tout sous verrou et réutilise la synchronisation des aides de la programmation. À
l'écran, « Ajuster l'équipe opératoire » remplace le bouton de programmation une fois au
bloc (`SurgicalTeamAdjuster`), et une ligne dit ce qui est figé et pourquoi. Après la fin
de l'intervention, plus rien ne se modifie. Aucune permission nouvelle, aucune migration.


## Amendement du même jour — chaque tuile se corrige, date et principal compris

Le propriétaire revient sur l'arbitrage ci-dessus (« date et principal figés au bloc ») :
la date programmée peut changer, et chaque tuile de la programmation porte son crayon.
Décision du propriétaire, appliquée telle quelle.

```text
avant le démarrage   crayon → POST /schedule, un fait à la fois ; le planning RH est
                     vérifié, un chirurgien absent est montré verrouillé avec la raison
au bloc              crayon → POST /team-adjustment, un fait à la fois : date programmée,
                     chirurgien principal, aides, opérateur (tuile de la section
                     Intervention) ; champ omis = inchangé ; motif obligatoire, audité
changer de principal l'ancien principal, s'il est l'opérateur enregistré, reste dans
                     l'équipe comme aide : qui a opéré n'est jamais réécrit
date au bloc         reste la date programmée ; l'heure réelle est le « Début » consigné
salle, consignes     leurs tuiles ouvrent le formulaire de préparation existant
```

Le bouton global « Ajuster l'équipe opératoire » est remplacé par ces crayons
(`ScheduleFieldEditor`). La date programmée se corrige au bloc en écrivant directement le
dossier verrouillé, hors de la transition `SurgicalRequest::schedule()` qui reste fermée
après le démarrage. Après la fin de l'intervention, plus rien ne se modifie. Aucune
permission nouvelle, aucune migration.
---

# ADR-169 — Le matériel du bloc passe par le stock de la Pharmacie

**Status:** ACCEPTED (2026-09-22 — constat du propriétaire : « l'étape Consommables ne se relie pas aux
stocks de la Pharmacie ? » ; quatre arbitrages explicites, un par question)

**Complète l'ADR-048** (l'étape Intervention et ses consommables), **l'ADR-072** (consommables Soins) et
**l'ADR-142** (Maternité). Le CDC §16 dit seulement que la Chirurgie « génère des prestations facturables,
sans encaissement » : les règles ci-dessous sont celles du propriétaire.

## Le constat

« Consommables » était un texte libre (`surgical_consumables` : libellé, quantité, unité). Rien ne sortait
du stock, rien n'était facturé, et la Pharmacie ne savait pas qu'un produit avait été posé au bloc : son
inventaire devenait faux à chaque intervention, et le patient repartait sans que la clinique ait compté ce
qu'elle avait utilisé.

## Les quatre arbitrages

```text
produits      parapharmacie + matériel configuré pour un acte de Chirurgie (fils, drains, champs…),
              comme la Maternité (ADR-142) — jamais tout le stock
facturation   en plus de l'intervention, ligne par ligne, au tarif serveur ; encaissé à la Caisse
stock         sort dès que la Pharmacie sert, sans attendre le règlement (amendement de l'ADR-049
              posé par l'ADR-072 : le produit est déjà posé sur le patient)
hors stock    une ligne libre reste possible pour un produit absent du stock : tracée, ni sortie
              de stock ni facture ; les lignes déjà saisies restent lisibles telles quelles
```

## Un seul circuit, pas un troisième

Le bloc emprunte le circuit existant sans le recopier : même `CareConsumableRequest`, même file
« Consommables Soins » de la Pharmacie, même sortie FEFO (`ServeCareConsumablesAction`) qui n'entame jamais
une quantité réservée, même facturation (`care_consumable:{uuid}`, rattachement à la facture non encaissée
du passage, échec financier jamais bloquant — ADR-054, ADR-103), même annulation avec motif tant que rien
n'a quitté le stock (ADR-010).

```text
source_module          SURGERY
surgical_request_id    le dossier du bloc qui a déclaré (colonne ajoutée, nullable)
care_orientation_id    l'orientation vers le bloc quand le passage en a une — jamais inventée :
                       une demande ouverte au bloc avant l'ADR-159 n'en a pas
libellé et audit       « Bloc opératoire » dans la file et sur le mouvement de stock ;
                       surgery.consumables.request / surgery.consumables.cancel
```

`RequestCareConsumablesAction::executeForSurgery()` n'ajoute qu'un point d'entrée : l'écriture commune
(contrôle des produits, lignes figées, facturation, audit) est partagée par les trois services. Le service
se lit sur le dossier, jamais sur une valeur envoyée. Une demande de chirurgie annulée ou un passage clos
refuse toute déclaration.

## La liste de produits est une décision du référentiel

`CareConsumableDirectory::eligibleMedicines()` porte la règle une seule fois : Soins = parapharmacie ;
Maternité **et** Chirurgie = parapharmacie **plus** les produits configurés comme matériel habituel d'un
de leurs actes (`care_act_consumables`, `acceptsConfiguredProducts()`). La configuration se fait dans
Administration › Catalogue avec `catalog.items.update` (ADR-024) ; un acte de Chirurgie y accepte tout
produit actif et stockable. Rien n'est déduit du nom d'un acte (ADR-052).

Le matériel habituel de l'intervention demandée est **proposé** à l'ouverture du formulaire (« Habituel »),
jamais imposé : l'équipe confirme, corrige ou retire. Il n'est lu que si l'intervention porte un acte du
référentiel de Chirurgie.

## Aucun prix au bloc

La page du dossier sert le catalogue (disponibilité seule), les suggestions et les demandes **sans aucun
montant** (`billing` nul) : le bloc ne voit ni ne saisit de prix (ADR-036). Le montant se lit à la
Pharmacie (ADR-103) et s'encaisse à la Caisse (ADR-012, ADR-016).

## Permissions

Aucune nouvelle : `surgery.consumables.create` (socle `SURGERY`) déclare et annule depuis le dossier ; la
Pharmacie sert avec `care_consumables.serve`. Le serveur revérifie, l'écran ne fait que suivre.

## Ce qui reste à la clinique

Configurer le matériel habituel des actes de Chirurgie et donner un prix de vente aux produits concernés.
Sans configuration, seule la parapharmacie est déclarable ; sans prix, la ligne sort du stock et reste
« non facturée — à régulariser » par la Réception (ADR-103).

La migration `2026_10_23_090000` se joue sur chaque site ; la base du portail n'a pas de file Pharmacie,
mais la jouer partout garde les schémas alignés.

---

# ADR-170 — Chirurgie et Anesthésie en parallèle, deux points de rendez-vous opposables

**Status:** ACCEPTED (2026-09-22 — exigence explicite du propriétaire, après audit
du module Chirurgie/Anesthésie)

**Complète l'ADR-048** (les deux espaces, le dossier partagé) et **l'ADR-168**
(l'équipe de bloc et ses profils). Le CDC §16 décrit le déroulé d'une
intervention et les permissions du module ; **il ne décrit ni autorisation
anesthésique, ni checklist de sécurité**. Les règles ci-dessous sont donc
celles du propriétaire, signalées comme telles et non transcrites du CDC
(ADR-020).

## Le principe : parallèle, avec des rendez-vous

```text
Chirurgie et Anesthésie travaillent EN PARALLÈLE
Rien n'impose à l'une d'attendre que l'autre ait tout terminé
Mais deux transitions sont des points de rendez-vous opposables :
    l'incision   →  les checkpoints de sécurité doivent être réellement faits
    la clôture   →  tout ce qui doit être documenté doit l'être
```

Ce n'est **pas** un assistant linéaire. `SurgicalReadinessGate` est l'autorité
unique de ces deux transitions ; le reste du dossier se remplit dans l'ordre que
l'équipe choisit.

## Sept défauts constatés à l'audit

```text
1  démarrer l'intervention ne vérifiait que le statut du dossier : ni
   anesthésiste, ni autorisation, ni checklist
2  la « décision » d'anesthésie vivait dans paraclinical_data.surgery_authorized,
   un drapeau JSON qu'aucune règle ne lisait
3  performed_by acceptait n'importe quel compte actif
4  aucune checklist de sécurité du bloc
5  valider le compte rendu clôturait le dossier au passage : un seul métier
   clôturait pour tous, SIGN OUT, sortie du bloc et anesthésie non documentées
6  ValidateAnesthesiaRecordAction n'avait aucune condition de phase : la fiche
   pouvait être verrouillée avant même l'incision
7  aucune autorisation par dossier : `anesthesia.update` ouvrait tous les
   dossiers du site, `surgery.intervention.create` tous les blocs
```

## L'autorisation anesthésique, distincte de la validation du bilan

Deux faits, que le code confondait :

```text
assessment_validated_at   l'évaluation pré-anesthésique est terminée et verrouillée
clearance_status          la décision : le bloc peut-il avoir lieu ?
validated_at              la fiche entière est close (conduite au bloc comprise)
```

`AnesthesiaClearanceStatus` : `DRAFT`, `CLEARED`, `CLEARED_WITH_CONDITIONS`,
`NOT_CLEARED`, `DEFERRED`. **`DRAFT` n'est pas une décision** : tant que personne
n'a prononcé, l'incision est retenue — une absence n'est jamais une décision
(ADR-077, ADR-079, ADR-095). Un refus ou un report **exige son motif**, que le
bloc lit dans sa propre synthèse : l'équipe chirurgicale ne peut pas le deviner.

Une décision est **révisable** tant que l'intervention n'a pas commencé (l'état
d'un patient change) ; au bloc, elle ne se reprononce plus. Chaque révision est
auditée avec l'ancienne et la nouvelle valeur.

`paraclinical_data.surgery_authorized` **n'est pas migré** vers ce statut :
c'était un champ de saisie qu'aucune règle n'opposait, et le convertir en
décision attribuerait à un anesthésiste une autorisation qu'il n'a jamais
prononcée sous cette forme.

### Conditions

`anesthesia_clearance_conditions` : deux états seulement, `OPEN` et `RESOLVED`.
Une condition ouverte **bloque l'incision**. Elle se lève par l'anesthésie et
par elle seule — laisser le bloc cocher lui-même les réserves de l'anesthésiste
lui rendrait l'autorisation qu'on venait précisément de ne pas lui donner.

**`WAIVED` n'existe pas**, délibérément : le CDC ne dit pas qui pourrait passer
outre la réserve d'un anesthésiste, et l'inventer créerait une porte de sortie
sans responsable. À décider avec la clinique si le besoin apparaît.

## Checklist de sécurité du bloc

Trois temps — `SIGN_IN` (avant l'induction), `TIME_OUT` (juste avant l'incision),
`SIGN_OUT` (avant que l'équipe quitte la salle) — avec une traçabilité réelle :
chaque rôle requis confirme **sa propre part**, nominativement et horodatée. Un
chirurgien ne signe pas pour l'anesthésiste : c'est précisément ce qu'une
checklist existe pour empêcher.

```text
SIGN_IN    Anesthésie + Équipe de salle   (le chirurgien n'y est pas toujours)
TIME_OUT   Chirurgien + Anesthésie + Équipe de salle
SIGN_OUT   Chirurgien + Anesthésie + Équipe de salle
```

Un temps n'est **jamais déclaré terminé par un clic** : `completed_at` est
calculé des faits — les points obligatoires cochés et chaque rôle ayant confirmé
(`refreshCompletion()`). Une fois posé, il ne bouge plus.

**Le contenu des items n'est pas une règle médicale inventée.**
`App\Support\SurgicalSafetyChecklistItems` est un fichier unique, relu, qui ne
contient que des vérifications organisationnelles — le bon patient, la bonne
intervention, le bon côté, le matériel, la personne présente. Aucun seuil,
aucun score, aucune contre-indication. Les items obligatoires sont
volontairement les quatre ou cinq que personne ne conteste ; **élargir la liste
est une décision de la clinique**, qui se prend dans ce fichier et nulle part
ailleurs.

Aucune permission nouvelle : confirmer suit le métier —
`anesthesia.update` pour l'anesthésie, `surgery.preparation.update` pour le
chirurgien et l'équipe de salle (ADR-101 : une permission que rien ne vérifie
est un interrupteur qui ne commande rien).

## Bloquant ou avertissement — la distinction est la règle centrale

```text
blocker   la transition est refusée par le serveur, y compris en POST direct
warning   l'équipe est prévenue et décide
```

**On ne retient jamais un bloc opératoire pour un champ facultatif.** La fiche
d'entrée au bloc manquante et les points facultatifs non cochés sont des
avertissements ; ils n'ont jamais empêché une incision.

Ce qui bloque l'incision :

```text
dossier annulé · intervention déjà ouverte · feu vert préopératoire non confirmé
aucun anesthésiste affecté · aucun dossier d'anesthésie
évaluation non validée · décision absente, refusée, reportée ou échue
condition d'autorisation encore ouverte
SIGN IN ou TIME OUT : manquant, points requis non cochés, confirmation manquante
```

Chaque constat porte son **propriétaire** (`SURGERY`, `ANESTHESIA`, `BLOCK`) :
l'écran dit « en attente de l'anesthésiste » au lieu de proposer au chirurgien
un geste qui n'est pas le sien.

## Valider le compte rendu ne clôt plus le dossier

`ValidateSurgicalReportAction` n'appelle plus `complete()`.
`CompleteSurgicalCaseAction` est un geste à part, qui exige :

```text
intervention terminée (heure de fin) · compte rendu validé · SIGN OUT confirmé
sortie du bloc renseignée · dossier d'anesthésie finalisé
```

La permission reste `surgery.report.validate` — c'est elle qui emportait la
clôture jusqu'ici ; en inventer une nouvelle retirerait à chaque site une
capacité qu'il possédait. L'action est idempotente : un second clic sur un
dossier clôturé le renvoie tel quel.

Symétriquement, `ValidateAnesthesiaRecordAction` refuse désormais de fermer la
fiche **avant l'incision** : la conduite peropératoire resterait entièrement à
consigner sur un dossier verrouillé.

## L'autorisation par dossier, au-dessus du RBAC

`App\Services\Surgery\SurgicalCaseActors` répond à la question que la
permission ne pose pas : cette personne travaille-t-elle sur **ce** patient ?

```text
anesthesia.update  ouvre le métier
isAnesthetistOf()  ouvre le dossier
surgery.update     supervision — une porte nommée, jamais un effet de bord
```

`SurgicalRequestPolicy` et `AnesthesiaRecordPolicy` portent ces règles ; les
FormRequests concernées ne renvoient plus `true`. Huit autres FormRequests du module
(programmation, sortie de Chirurgie, équipe, sortie du bloc, notes, traitements)
renvoient encore `true` et ne sont gardées que par le `can:` de leur route :
l'autorisation par dossier ne s'y applique pas encore — signalé, non traité. `performed_by` doit être un
chirurgien **de ce dossier** (`surgeon_id`, un membre d'équipe `SURGEON`) ou la
supervision : un compte actif ne suffit plus.

Ouvrir le dossier d'anesthésie fait exception et reste ouvert à
`anesthesia.create` sans affectation préalable — l'exiger enfermerait la
clinique, puisque avant ce dossier personne n'est encore l'anesthésiste. Le
compte qui l'ouvre en devient l'anesthésiste, sauf s'il en désigne
explicitement un autre, que la FormRequest restreint déjà au profil
`ANESTHETIST` (ADR-168).

## Le serveur décide, l'écran affiche

`SurgicalReadinessPresenter` compose `readiness` (blocages, avertissements,
lignes d'état, décision d'anesthésie, checklists, droits de l'acteur). **Aucune
règle sensible n'est recalculée en JavaScript** : deux copies de la même règle
finissent par se contredire, et ici la divergence se paie au bloc — un bouton
actif sur un dossier que le serveur refusera, ou grisé sans qu'on sache
pourquoi.

Un bouton désactivé dit toujours **pourquoi** (`BlockingIssuesAlert`), et la
couleur ne porte jamais seule une information critique : chaque état a son mot
écrit (« Terminé », « À faire », « Bloquant », « Attention ») et son icône.

Composants : `SurgicalReadinessCard`, `BlockingIssuesAlert`,
`AnesthesiaClearanceBadge`, `AnesthesiaClearancePanel`,
`SurgicalSafetyChecklist` — shadcn-vue (ADR-099).

## Ce qui n'est pas fait, et pourquoi

```text
READY_FOR_INCISION comme statut stocké   il est CALCULÉ (blockersForIncision) :
                                         un statut stocké se désynchronise du
                                         fait qu'il prétend décrire
renommer PREOPERATIVE_VALIDATED          aucun gain : le nom est déjà porté par
                                         des lignes, des tests et l'audit
WAIVED sur une condition                 aucun responsable défini par le CDC
migration de surgery_authorized          inventerait une décision non prononcée
cohérence horaire entrée/sortie du bloc  signalée, non tranchée : aucune règle
                                         de la clinique ne dit ce qui doit
                                         précéder quoi à la minute près
```

## Migrations

`2026_10_24_090000_create_anesthesia_clearance` (colonnes nullables sur
`anesthesia_records` + table des conditions) et
`2026_10_24_091000_create_surgical_safety_checklists`. Rétrocompatibles :
aucune donnée supprimée, aucune colonne retirée, tout nullable — un dossier
antérieur se lit `DRAFT`, c'est-à-dire « personne n'a décidé », ce qui est
exact. Aucune permission nouvelle, donc aucune migration de droits.

---

# ADR-171 — Réinitialiser un dossier du bloc saisi à tort, sans rien détruire

**Status:** ACCEPTED (2026-09-22 — exigence explicite du propriétaire : « tout ce qui est saisi peut
être à tort ou en erreur, on peut réinitialiser à zéro, mais toujours avec confirmation » ; arbitrages :
usage réel en production, périmètre = tout le dossier du bloc)

**Amende l'ADR-010** pour ce seul cas, signalé avant l'implémentation : les données cliniques du bloc
(intervention, compte rendu, checklists, anesthésie) sont retirées du dossier. Elles ne sont pas
détruites : elles sont **archivées** avant d'être retirées. Le CDC §15/16 ne liste aucune permission
`surgery.delete/cancel` ; ce geste est la décision du propriétaire.

## Ce que fait « Réinitialiser »

```text
archive      surgical_request_resets : instantané complet de ce qui était saisi (programmation,
             équipe, feu vert, entrée au bloc, checklists et confirmations, intervention, compte
             rendu, sortie du bloc, surveillance, traitements, complications, notes, lignes hors
             stock, dossier d'anesthésie et ses conditions), statut d'avant, motif, auteur, heure ;
             écrite une fois, jamais modifiée ni supprimée (le modèle le refuse)
retrait      ces saisies quittent le dossier ; la demande garde intervention demandée, origine,
             demandeur et notes de demande, et repasse « À programmer » (PENDING)
audit        surgery.request.reset : statut d'avant, nombre d'éléments archivés, UUID de l'archive
```

## Ce qu'il ne défait pas

```text
matériel déjà servi par la Pharmacie   refus : le stock a bougé ; la correction est un ajustement
                                       de stock audité, puis la réinitialisation
matériel demandé, pas encore servi     la demande est annulée, motif repris (règle de l'ADR-169)
demande annulée, passage clos          refus
dossier sans aucune saisie             refus (rien à réinitialiser)
orientation, séjour, facturation       inchangés : ils n'appartiennent pas au dossier du bloc
```

## Confirmation

Bouton « Réinitialiser » dans l'en-tête du dossier (`SurgicalCaseReset`), seulement avec le droit.
La fenêtre liste ce qui sera retiré, exige un motif et une case « Je confirme », et ne se ferme pas
au clic à côté. Le serveur revérifie tout ; l'écran ne décide de rien.

## Permission

`surgery.reset`, accordée au rôle `SURGERY` par la migration `2026_10_25_090000` (ADR-064) — à jouer
sur chaque site et sur le portail. Le Super Administrateur la retire ou la réserve à un compte depuis
« Rôles & permissions ».

## Signalé, non tranché

Aucun écran ne relit encore une archive : elle se lit en base et dans l'audit. Une restauration
depuis l'archive n'est pas proposée.

---

# ADR-172 — Le « Dossier chirurgical » est généré depuis les données, et le bloc figure au dossier médical

**Status:** ACCEPTED (2026-09-22 — modèle papier « Dossier Chirurgical » de la clinique fourni par
le propriétaire, qui a demandé d'appliquer la recommandation)

**Complète l'ADR-048** (les fiches papier du bloc comme vocabulaire), **l'ADR-116** (dossier médical
et journal de traitement) et **l'ADR-170** (autorisation anesthésique). Le CDC §16 ne décrit aucun
document imprimable du bloc : ce qui suit est la décision du propriétaire.

## Le constat

Le propriétaire a fourni les quatre pages papier du « Dossier Chirurgical » — Entrée du patient au
bloc, Sortie du patient au bloc, Consultation pré-anesthésique, Examen paraclinique — et demandé si
les actes du bloc et de l'anesthésie se retrouvaient dans le dossier médical. Vérification faite :
**rien** de ce que la Chirurgie et l'Anesthésie consignent n'atteignait le dossier médical imprimé,
le journal de traitement ni le parcours du passage — seule l'orientation « Chirurgie » y figurait.
Toutes les cases du papier existaient pourtant déjà en base (fiches d'entrée et de sortie du bloc,
traitements, surveillance, dossier d'anesthésie).

## Trois choses, sans nouveau modèle de données

```text
1  un « Dossier chirurgical » imprimable, généré depuis ce qui est saisi
2  le bloc au journal de traitement et au dossier médical
3  « Imprimer le dossier » dans les deux espaces, entier ou une seule feuille
```

Aucune table, aucune colonne : `App\Support\Documents\SurgicalDossierSheet` **lit** le dossier du
bloc et le dossier d'anesthésie et compose quatre feuilles fidèles au papier, une par page, dans un
seul document (`GET /surgery/{demande}/dossier`, page `Surgery/DossierPrint`, `PaperSheet`). Le PDF
est celui du navigateur (ADR-070). `?feuille=entry|exit|consultation|paraclinical` n'en imprime
qu'une ; une valeur inconnue imprime tout, jamais une page vide.

## Chaque feuille garde le droit qui possède sa donnée

```text
route                      surgery.view OU anesthesia.view (capacité view-surgical-dossier)
Entrée / Sortie du bloc    surgery.view
Consultation / Paraclinique  anesthesia.view
en-tête                    anesthésiste avec anesthesia.view ; entrée/sortie d'hospitalisation
                           avec hospitalization.view
```

Une feuille refusée est servie **restreinte et nommée**, jamais vide — un vide se lirait « rien
consigné » (ADR-116). Les libellés (enums, clés JSON, antécédents cochables) sont résolus côté
serveur ; la page n'interprète aucun code. Une case que personne n'a remplie reste vide : jamais
« Non », jamais « Normal » (ADR-077).

## Divergences avec le papier, signalées

```text
« Autorisation d'opérer »   la case du papier imprime la décision de l'ADR-170 (statut, auteur,
                            date, motif, conditions). L'ancien drapeau JSON `surgery_authorized`
                            n'est imprimé que s'il a réellement été coché — jamais converti en
                            décision (ADR-074)
Situation maritale, enfants viennent du dossier patient, pas d'une saisie du bloc
Conduite anesthésique       n'a pas de page papier ; elle est imprimée à la suite de l'Examen
                            paraclinique, dans le dossier d'anesthésie auquel elle appartient
Checklists de sécurité      absentes du papier (ADR-170) ; imprimées avec leurs confirmations
                            réelles, un temps sans confirmation restant une ligne vide
```

## Le bloc au journal et au dossier médical

`TreatmentJournal::surgeryRows()` (gardé par `surgery.view`) ajoute, chacun à son heure réelle :
entrée au bloc, début et fin de l'intervention, sortie du bloc avec l'état de réveil, traitements
préliminaires et postopératoires, complications, sortie de Chirurgie. Une demande annulée n'écrit
rien. `MedicalRecordSheet` reçoit une section « Bloc opératoire » — intervention, dates, chirurgien,
réveil, et, avec `anesthesia.view`, anesthésiste, classe ASA et décision anesthésique — nommée
« non visible avec vos droits » sans `surgery.view`, jamais servie vide.

Aucune permission nouvelle, aucune migration. Rendu vérifié par build et tests, non en navigateur.

---

# ADR-173 — Réinitialiser explicitement un socle ou un compte à ses droits par défaut

**Status:** ACCEPTED (2026-09-22 — exigence explicite du propriétaire : ajouter la
réinitialisation des permissions d'un rôle et d'un compte)

**Complète l'ADR-064** (socle éditable), **l'ADR-100** (socles et exceptions sur un écran
distinct) et **l'ADR-033** (recommandations de profil explicites). La priorité reste :

```text
DENY individuel > ALLOW individuel > socle du rôle
```

Deux gestes distincts sont proposés dans « Rôles & permissions », chacun après une confirmation
qui nomme sa portée :

```text
Réinitialiser le rôle    restaure le socle livré par RolePermissionSeeder pour ce rôle standard ;
                        touche tous les titulaires par héritage, sans modifier leurs exceptions ;
                        audit role.permissions.reset

Réinitialiser le compte supprime toutes les lignes user_permissions de ce compte, MANUAL comme
                        PROFILE ; le profil professionnel reste affecté, mais ses recommandations
                        ne sont pas réappliquées ; le compte hérite seulement du socle de son rôle ;
                        audit user.permissions.reset
```

La réinitialisation d'un compte est volontairement plus large que l'éditeur ordinaire des
exceptions, qui ne remplace que les lignes `MANUAL` (ADR-100). « Par défaut » ne signifie pas
« appliquer le profil recommandé » : une recommandation n'est une permission effective qu'après
l'action explicite prévue par l'ADR-033.

Un rôle créé depuis le portail n'a aucun socle système historique à restaurer. L'action est donc
absente de l'écran et refusée par le serveur pour ce rôle ; vider silencieusement son socle
inventerait un défaut. `SUPPORT` et `MAINTENANCE`, en revanche, sont des rôles standards dont le
socle par défaut est intentionnellement vide : ils restent réinitialisables.

Les deux commandes passent par l'API du site cible, jamais par sa base. Le portail et le site
revérifient respectivement `users.manage` (rôle) et `permissions.assign` (compte). Le rôle
`SUPER_ADMIN` reste protégé. Aucun changement de `User::effectivePermissionNames()`, aucune
permission nouvelle, aucune migration.

---

# ADR-174 — Deux prix : achat confidentiel, vente fixée par la Pharmacie

**Status:** ACCEPTED (2026-09-18 — exigence explicite du propriétaire)

**Amende l'ADR-024** (gestion des tarifs réservée au Super Admin) pour le
seul prix de vente des médicaments, et **l'ADR-097** (prix d'achat saisi à la
réception).

## Le constat

Trois libellés circulaient : « prix unitaire », « prix d'achat » et « prix de
vente ». Vérification faite dans le code, il n'y a que deux prix :

```text
prix d'achat   medicine_supplier_offers.quoted_price   (tarif du fournisseur)
               purchase_order_lines.unit_price          (figé à la commande)
               pharmacy_stock_movements.unit_purchase_price (payé, par lot)
prix de vente  catalog_tariffs (STANDARD)               (payé par le patient)
```

Chaque « prix unitaire » affiché était un prix d'achat par unité. Il est
renommé **« Prix d'achat unitaire »** partout ; aucun troisième prix n'existe.

## Le prix d'achat n'est jamais redemandé

`ReceiveGoodsAction` reprend le prix de la ligne de commande quand la
réception n'en transmet pas. Seul un compte doté de `stock.cost.record` peut
le corriger, si la facture du fournisseur diffère. Le chemin est donc
catalogue → commande (prix figé) → réception → mouvement de stock, sans
ressaisie.

## Le prix d'achat est confidentiel

`stock.cost.view` et `stock.cost.record` quittent le socle `PHARMACY`
(migration `2026_09_18_120000`, sans rejouer le seeder — ADR-064). Les écrans
de réception et de détail du stock masquent colonne et marge sans
`stock.cost.view`, et le serveur n'envoie plus la valeur. Le Super Admin du
portail garde tout ; un compte local la reçoit nominativement.

## La Pharmacie fixe le prix de vente — et lui seul

`medicines.sale_price.update`, accordée à `PHARMACY`, permet de fixer et de
modifier la grille **Standard** d'un élément de type **MEDICINE**, par
`PUT /pharmacy/medicines/{medicine}/sale-price`. `SetCatalogTariffAction`
l'accepte uniquement dans ce cas : un prix de consultation, d'acte ou la
grille Mutuelle restent sous `catalog.tariffs.*` (ADR-024, ADR-031). Accorder
`catalog.tariffs.*` à la Pharmacie lui aurait ouvert tous les tarifs de la
clinique.

Le premier prix se saisit sans motif ; un changement en exige un, l'ancien
restant dans l'historique et les ventes passées inchangées. Aucun
encaissement n'est ajouté à la Pharmacie (ADR-013).

**Aussi à l'entrée de stock (même jour).** Le formulaire « Entrée de stock »
propose le prix de vente sur chaque ligne, prérempli avec le prix actuel :
recevoir un produit et le rendre vendable se font d'un seul geste.
`RecordStockEntriesAction` l'applique dans la même transaction que les
entrées ; un prix inchangé n'est pas réécrit, un prix changé prend le motif
de la livraison, et deux lignes du même médicament à deux prix différents
sont refusées.

Le prix d'achat **n'apparaît plus** sur ce formulaire : il vient de la
commande à la réception (voir plus haut). À la place, un champ facultatif
**« Nom à la pharmacie »** permet de vendre un produit sous un autre nom que
celui du fournisseur. Il renomme le produit du catalogue clinique
(permission `medicines.name.update`, accordée à `PHARMACY` par la migration
`2026_09_18_130000`) ; le libellé du fournisseur reste sur sa ligne de
catalogue, les ventes et factures passées gardent leur instantané, et
l'ancien nom reste lisible à l'audit (`CatalogItem` est `Auditable`).

---

# ADR-175 — Réceptionner n'est pas ranger : dates serveur, écran unique d'entrée en stock

**Status:** ACCEPTED (2026-09-22 — exigences explicites du propriétaire)

**Amende l'ADR-097** sur un point central : « la réception appelle
directement `RecordStockEntryAction` … ce qui crée le lot, le mouvement de
stock immuable et incrémente `quantity_received` ». La réception ne crée plus
aucun mouvement de stock. Complète l'ADR-098 (module Pharmacie) et l'ADR-174
(deux prix) sans modifier l'ADR-012, l'ADR-013 ni l'ADR-049.

## Aucune date du système ne se saisit

Le propriétaire l'a posé en une phrase : les dates sont automatiques. Ce que
le serveur sait, il ne le demande pas.

```text
posé par le serveur      numéro et date de commande, date d'envoi,
                         date de réception, date d'entrée en stock
saisi, parce qu'externe  péremption lue sur la boîte, date de la facture du
                         fournisseur, échéance, livraison attendue
```

Les dates externes elles-mêmes cessent d'être des calendriers à remplir : la
date de facture est celle du jour, avec « Autre date » si le papier en porte
une autre ; l'échéance et la livraison attendue se choisissent en un clic
(« 30 jours », « Sous 1 semaine »). `StockController` ignore désormais toute
`received_at` transmise et date l'entrée lui-même.

## Deux gestes, deux moments

Une livraison arrive, on la contrôle, puis on la range. Les confondre avait
deux conséquences : le stock devenait disponible avant d'être rangé, et une
erreur de lot constatée en rangeant n'était plus corrigible — le mouvement
était déjà immuable (ADR-097, à raison).

```text
Réception (goods_receipts)        ce qui est arrivé : quantité, lot,
                                  péremption, remarque. Aucun mouvement.
Entrée en stock (stock.entry)     le lot est créé, le mouvement immuable
                                  écrit, la marchandise devient disponible.
```

`goods_receipt_lines` porte donc `uuid` (ADR-050 : jamais un identifiant SQL
à l'écran), `notes`, `stocked_at` et `stocked_by`. Une ligne attend tant que
`stocked_at` est nul ; `RecordReceivedStockAction` la verrouille, refuse une
ligne déjà entrée en nommant qui l'a rangée et quand, puis appelle
`RecordStockEntryAction` **inchangée** — mêmes règles de lot, même FEFO, même
prix d'achat, celui de la réception (ADR-174).

Tant que rien n'est entré, la ligne reste corrigible : une quantité changée
au rangement met à jour la ligne de réception **et** la commande, dont le
statut est recalculé (`PurchaseOrder::refreshReceptionStatus()`). Après
l'entrée, plus rien ne se corrige ainsi : c'est un ajustement de stock tracé,
comme avant.

Les lignes reçues **avant** cette décision portent déjà leur mouvement : la
migration les marque entrées à leur date, pour qu'aucune n'entre une seconde
fois.

## La facture accompagne la réception, ou elle attend

Réceptionner se fait en deux étapes : ce qui est arrivé, puis la facture du
fournisseur. La seconde est facultative — une livraison arrive souvent sans
son papier. Sans elle, la réception est « facture en attente », visible comme
telle dans la liste et sur la fiche, et la facture s'enregistre plus tard par
`/pharmacy/receipts/{receipt}/invoice`. Les deux chemins partagent le même
formulaire : deux formulaires auraient fini par diverger.

`supplier_invoices.due_date` est ajoutée, nullable : une échéance est une
donnée du fournisseur, pas une règle inventée. Aucun workflow de paiement
fournisseur n'est créé — la Pharmacie n'encaisse ni ne paie (ADR-013).

Le montant proposé à la saisie est celui de ce qui a été reçu, et seulement
avec `stock.cost.view` : il révèle le coût d'achat (ADR-174). C'est une aide,
jamais une vérité : le papier du fournisseur fait foi.

## Un tableau plein, une recherche qui filtre

Exigence explicite du propriétaire, après usage : un écran qui attend une
recherche avant d'afficher quoi que ce soit cache le travail à faire.

```text
avant   champ de recherche vide → on tape → le produit s'ajoute au panier
après   tout est affiché → on saisit une quantité (ou on coche) sur place,
        la recherche ne fait que filtrer cette liste
```

La commande d'achat affiche donc tout ce que le fournisseur propose, avec sa
quantité en face ; l'entrée en stock affiche la marchandise réceptionnée
déjà remplie, et — pour une entrée sans commande — le catalogue entier de la
pharmacie, à cocher. Une ligne non cochée n'affiche aucun champ : la liste
reste lisible et le navigateur ne rend pas des centaines de champs inutiles.

L'écran d'entrée en stock est **unique** : « Marchandise réceptionnée » et
« Entrée sans commande » sont deux onglets du même tableau, au lieu de deux
formulaires qui redemandaient les mêmes informations. La saisie sans commande
ne pose plus qu'une question — d'où vient la marchandise — qui sert de
provenance et de motif ; le rangement est le stock du site.

## Un brouillon jamais envoyé part à la corbeille

Une commande envoyée a engagé la clinique auprès d'un tiers : elle s'annule
avec un motif, elle ne se jette pas (ADR-098). Un brouillon, lui, n'a rien
engagé.

`purchase_orders` devient Soft Delete (ADR-009) et rejoint la Corbeille
(ADR-061) sous `TrashCategory::PurchaseOrder`, avec son motif obligatoire.
`isForceDeleteProtected()` refuse la suppression définitive dès qu'une
réception, une facture ou un envoi existe.

```text
purchase_orders.delete    mettre un brouillon à la corbeille
purchase_orders.restore   l'en sortir
```

Accordées à aucun rôle par défaut, comme tout l'approvisionnement (ADR-098),
et enregistrées par migration puisqu'un site en production ne rejoue plus
`RolePermissionSeeder` (ADR-064).

## Ce que l'écran dit désormais

```text
« Nouveau produit »     à la réception et à l'entrée : ce produit n'a jamais
                        été reçu ni rangé — c'est là qu'on lui donne son nom
                        à la pharmacie (ADR-174) et son prix de vente
« Facture en attente »  la livraison est enregistrée, son papier non
« N en attente »        ce qui est reçu mais pas encore rangé
```

Aucune fenêtre `confirm()` du navigateur ne subsiste dans ces parcours :
envoyer une commande, valider une réception, entrer du stock, valider un
inventaire, activer un catalogue et jeter un brouillon passent par la même
fenêtre de confirmation de l'application, qui nomme ce qui va se passer.

## Ce qui ne change pas

La délivrance et sa règle « le stock ne sort qu'après règlement » (ADR-049),
les consommables Soins (ADR-072), la réservation FEFO (ADR-036), la
confidentialité du prix d'achat et le prix de vente fixé par la Pharmacie
(ADR-174). La réception reste au site, jamais au portail (ADR-098) : c'est la
personne qui a la marchandise sous les yeux qui lit les lots. La Pharmacie
n'encaisse toujours rien.

---

# ADR-176 — Réceptionner appartient à la Pharmacie ; l'action de l'étape passe devant

**Status:** ACCEPTED (2026-09-22 — deux arbitrages explicites du propriétaire)

**Amende l'ADR-098** sur le socle du rôle `PHARMACY` et **confirme l'ADR-097**
sur l'annulation d'une commande envoyée.

## Le constat

Sur la commande `ABC-000003`, statut « Commandée », la liste des commandes
affichait « Envoyée au fournisseur » en gris **à la place de toute action**, et
le dossier ouvert depuis le portail ne proposait que « Annuler la commande ».
Le propriétaire a posé la question dans ces termes : « pourquoi il dit toujours
annuler même si la commande a été commandée ? où, qui valide cette commande ? »

Trois faits distincts s'y mêlaient, dont un seul était un défaut.

```text
« Envoyer la commande » EST la validation   purchase_orders.submit ; il n'existe
                                            aucune étape de validation séparée
le portail ne réceptionne jamais            ADR-098, arbitrage du propriétaire :
                                            la réception reste au site
aucun bouton « Réceptionner » au site       ← le défaut
```

Vérification faite en base : `pharmacie@rivo.com` (rôle `PHARMACY`) détenait
`purchase_orders.*` en exceptions nominatives mais **pas** `goods_receipts.create`,
retiré du socle par la migration de l'ADR-098. La pharmacie ne pouvait donc pas
réceptionner sa propre livraison, et l'écran nommait l'état de la commande là où
il aurait dû nommer le droit manquant.

## Décision 1 — réceptionner revient au socle `PHARMACY`

```text
au socle   purchase_orders.view, goods_receipts.view, goods_receipts.create
par nom    purchase_orders.create/update/submit/cancel/delete,
           supplier_invoices.*, medicine_suppliers.*, supplier_catalogs.*,
           medicine_supplier_offers.*
```

Recevoir la marchandise est un **acte physique de cette pharmacie** — l'ADR-098
le dit déjà pour justifier que la réception reste au site : « c'est la personne
qui a la marchandise sous les yeux qui lit les lots et les péremptions ».
Décider un achat, payer un fournisseur et tenir son dossier restent une autre
autorité, accordée nominativement.

`purchase_orders.view` accompagne les deux autres et n'est pas un élargissement
gratuit : sans elle, la pharmacie enregistre une réception mais ne trouve aucune
commande à réceptionner — le même cul-de-sac, un cran plus bas.

**Conséquence signalée, non tranchée.** Le montant d'une commande — donc le prix
d'achat — devient lisible par tout compte Pharmacie. L'ADR-174 rend le prix
d'achat confidentiel **sur les écrans de réception et de détail du stock** ;
l'écran d'une commande n'était pas dans son périmètre, et `SupplierPresenter`
ne masque ni `unit_price` ni `total_amount`. Étendre le masque de l'ADR-174 aux
écrans de commande est une décision distincte, à prendre avec le propriétaire.

La **facture fournisseur** reste hors socle : la seconde étape de l'assistant de
réception (ADR-175) n'est alors pas proposée et la réception est « facture en
attente » — un état déjà défini, jamais une impasse.

## Décision 2 — « Annuler » reste, en action discrète

Retirer l'annulation d'une commande déjà envoyée a été envisagé et **écarté par
le propriétaire**. Le motif est celui de l'ADR-097 : un fournisseur peut ne
jamais livrer, et `CancelPurchaseOrderAction` ne refuse que `RECEIVED` et
`CANCELLED`. Sans annulation, une commande non livrée resterait indéfiniment
dans « À réceptionner », sans aucune porte de sortie.

Ce qui change est le **rang**, pas la règle :

```text
avant   [Annuler la commande] [Modifier] [Envoyer] [Réceptionner] [Facture]
après   [Envoyer|Réceptionner] [Modifier] [Facture]              … [Annuler]
```

L'action de l'étape vient en premier — « Envoyer la commande » pour un
brouillon, « Réceptionner » pour une commande partie. « Annuler » passe à
droite, en bouton discret qui ne devient rouge qu'au survol : une sortie de
secours, pas le geste attendu.

## Un écran muet nomme le droit qui manque

`idleReason` affichait l'état de la commande — « Envoyée au fournisseur » — dès
qu'aucune action n'était offerte, ce qui se lit « il n'y a plus rien à faire ».
Quand la commande attend encore sa marchandise et que le compte n'a pas
`goods_receipts.create`, la liste nomme désormais le droit, et le détail porte
le même bandeau. C'est la règle de l'ADR-154 : un refus dit ce qui manque.

## Déploiement

Migration `2026_10_26_090000_grant_reception_permissions_to_pharmacy_role`, à
jouer sur chaque site — un site déjà en production ne rejoue jamais
`RolePermissionSeeder` (ADR-064). Le seeder est complété pour les sites neufs.
Aucune permission nouvelle n'est créée : les trois existaient déjà au catalogue.

## Décision 3 — une commande annulée part à la corbeille

**Amende l'ADR-175** (« un brouillon jamais envoyé peut partir à la
corbeille »). Constat du propriétaire, sur le dossier fournisseur du portail :
une commande annulée n'offrait plus aucune action, la colonne affichant
« Annulée » — elle restait donc dans la liste pour toujours.

```text
brouillon jamais envoyé   corbeille (personne n'a été engagé)
commande ANNULÉE          corbeille (l'engagement est déjà retiré)
envoyée · partiellement reçue · reçue   jamais : on l'annule d'abord
```

Rien n'est détruit (ADR-010) : la ligne, son motif d'annulation et son
historique restent en base, elle se restaure depuis la Corbeille (ADR-061), et
la suppression définitive reste refusée dès qu'un envoi, une réception ou une
facture existe (ADR-175, `isForceDeleteProtected`).

Le geste existe des deux côtés, par le même chemin qu'ailleurs : au site
(`DELETE /pharmacy/purchase-orders/{uuid}`, inchangé) et **depuis le portail**,
qui n'avait aucune suppression — `DELETE /api/v1/super-admin/pharmacy/suppliers/{uuid}/orders/{uuid}`
appelle la même `TrashPurchaseOrderAction`, avec l'identité UUID/nom du Super
Administrateur (ADR-098). Le droit est `purchase_orders.delete`, distinct de
`purchase_orders.cancel` : annuler un engagement et ranger une ligne ne sont
pas la même décision. Il n'appartient à aucun rôle par défaut (ADR-098).

## Décision 4 — « commandé » n'est pas « en rupture »

Troisième fois que le propriétaire soulève le même point (« seuls les produits
réceptionnés entrent au catalogue pharmacie », ADR-098) : commander un produit
le fait apparaître aussitôt dans « Médicaments & stock », **avant toute
livraison**. L'ADR-098 répondait que « l'interface rend la séparation
visible » ; elle ne la rendait pas visible du tout — le produit s'affichait
**« En rupture »**, ce qui veut dire « on le tient d'habitude et il n'y en a
plus ». Un produit jamais reçu n'a jamais été tenu.

La contrainte technique de l'ADR-098 ne change pas : une ligne de commande
référence un `Medicine`, donc le produit doit exister au catalogue **avant**
d'être commandé. C'est son affichage qui est corrigé.

```text
NEVER_RECEIVED   aucun lot n'a jamais existé — commandé, pas encore arrivé
OUT_OF_STOCK     des lots ont existé, il n'en reste rien
```

L'état se lit sur les lots eux-mêmes (`lots_count === 0`), jamais sur une
colonne à tenir à jour : un lot n'existe que parce qu'une entrée en stock l'a
créé. Un produit jamais reçu quitte la liste courante — il n'est plus dans
« Tous », ni dans « En rupture », ni dans « Sans prix de vente » — et vit dans
son onglet **« Commandés, jamais reçus »**, d'où son nom et son prix restent
corrigeables avant l'arrivée. Il y rejoint le stock de lui-même à sa première
entrée.

## Décision 5 — un libellé qui compte 0 alors que le stock existe

Sur le même écran, la carte « Disponibles » affichait **0** pendant que chaque
ligne annonçait « Disponible 1 ». Le compte était juste : les catégories sont
exclusives — leur somme fait le total, comme partout ailleurs (ADR-119,
ADR-120) — et ces trois produits étaient comptés sous « Péremption proche ».
C'est le mot qui mentait.

La carte et l'onglet deviennent **« Disponibles sans alerte »**. L'exclusivité
est conservée : compter « tout ce qui a du stock » aurait fait compter deux
fois un produit dont un lot périme bientôt, et la somme des cartes n'aurait
plus rien voulu dire.

## Décision 6 — la facture accompagne la réception jusqu'au bout

La décision 1 laissait `supplier_invoices.*` hors du socle, en notant que la
réception serait alors « facture en attente ». À l'usage, l'assistant de
l'ADR-175 s'arrêtait à mi-chemin : l'étape 2 n'était jamais proposée, et
personne sur place ne pouvait saisir la facture arrivée dans le carton.

`supplier_invoices.view` et `supplier_invoices.create` rejoignent donc le
socle `PHARMACY` (migration `2026_10_26_100000`). Corriger une facture, la
mettre à la corbeille et la restaurer restent accordés nominativement
(ADR-098) : revenir sur une pièce comptable enregistrée n'est pas la même
autorité que la saisir.

## Décision 7 — le lot et la péremption ne s'inventent pas, mais ne se
retapent pas non plus

Demande du propriétaire : « N° de lot et Péremption automatiser ». **Refusé
pour ce qu'ils sont** — ces deux valeurs sont imprimées sur la boîte, et
l'ADR-175 les classe explicitement parmi les rares saisies légitimes
(« péremption lue sur la boîte »). Les fabriquer rendrait le FEFO faux
(ADR-036) et ferait délivrer un produit périmé en croyant l'inverse ; un
numéro de lot inventé rendrait de plus tout rappel de lot intraçable.

Ce qui est automatisé, parce que c'est un **fait déjà enregistré** et non une
supposition : quand le n° de lot saisi désigne un lot que la pharmacie tient
déjà, sa péremption est reprise, et la liste des lots connus du produit est
proposée à la saisie. À la réception comme à l'entrée en stock.

Ce mécanisme existait à l'entrée en stock et **ne marchait jamais** : `@input`
sur un composant est écouté avant que `v-model` ait posé la nouvelle valeur,
si bien que la recherche portait sur le caractère précédent. Un `nextTick` le
répare. La réception, elle, n'en avait aucun : elle le reçoit
(`known_lots` par ligne, servis par `GoodsReceiptController::create`).

Rien n'est prérempli à l'ouverture de l'écran : proposer d'office le lot d'une
livraison précédente ferait fusionner une nouvelle boîte dans un lot dont la
péremption n'est pas la sienne — exactement l'erreur que ces champs existent
pour éviter.

## Décision 8 — les icônes de la Pharmacie rejoignent celles de Médecine

Demande du propriétaire : les écrans doivent porter des icônes « comme
Hospitalisation, Médecine ». Ces modules sont déjà en `lucide-vue-next`
(ADR-099) ; la Pharmacie, elle, tenait encore à la police d'icônes DashWind
(`Components/UI/Icon.vue`), que l'ADR-099 interdit d'étendre — et la carte
« Commandés, jamais reçus » ajoutée par la décision 4 venait de l'étendre.

Les quinze écrans de la Pharmacie passent donc à lucide : `Médicaments &
stock`, l'entrée en stock, l'inventaire, la file de délivrance, la vente,
l'espace Fournisseurs et ses sous-dossiers, les factures, l'accueil.
Dix-neuf noms manquaient à la table partagée `lib/icons.js` — sans eux,
`lucideIcon()` retombait sur `Inbox`, et l'icône ne disait rien.

Une conversion mécanique, sans changement de comportement : mêmes props,
mêmes routes, mêmes règles. Deux corrections d'affichage l'accompagnent —
`text-lg`, qui dimensionnait une police, ne dimensionne pas un SVG et cède la
place à `h-4 w-4`.

**Reste à faire, signalé plutôt que caché** : environ 65 fichiers ailleurs
dans l'application portent encore la police d'icônes, dont 46 dans
Administration/RH. L'ADR-091 et l'ADR-099 imposent un remplacement écran par
écran, jamais une réécriture globale : ce sera un chantier par module, chacun
relu.

## Ce qui ne change pas

La réception reste au site et jamais au portail (ADR-098), l'ordre réception →
entrée en stock (ADR-175), la confidentialité du prix d'achat là où l'ADR-174
l'a posée, et la Pharmacie n'encaisse toujours rien (ADR-013). Un produit
jamais réceptionné reste sans lot, sans stock et sans prix de vente : il ne
peut être ni délivré ni vendu (ADR-036, ADR-049), ce qui était déjà vrai —
cela se voit désormais.

---

# ADR-177 — Besoin, prochaine étape, visibilité et prise en charge : cinq notions séparées

**Status:** ACCEPTED (2026-09-23 — spécification explicite du propriétaire)

**Amende** l'ADR-030 et l'ADR-053 (le parcours d'une désignation n'ouvre plus de file),
l'ADR-068 (plus d'orientation Maternité à l'arrivée), l'ADR-135 pour la Maternité et
l'ADR-146 (le dossier du bébé s'ouvre depuis la Maternité) ; **remplace** l'ADR-124. Le CDC
décrit l'orientation (§12, §32) sans dire qu'un besoin d'arrivée décide qui voit le patient :
les règles ci-dessous sont celles du propriétaire.

## Le constat

Une désignation choisie à l'accueil faisait trois choses à la fois : elle expliquait la
venue, elle créait une orientation, et donc elle décidait **qui voyait le patient**. Un
patient venu pour une injection n'existait pas pour la Médecine ; une consultation n'existait
pas pour les Soins avant la fin de leur évaluation ; un besoin inconnu partait d'office aux
Soins. L'accueil se faisait passer pour un tri clinique qu'il n'est pas.

## Cinq notions, jamais confondues

```text
besoin              pourquoi le patient vient — catalogue, estimation, tarif, facture : inchangés
prochaine étape     la suggestion de l'accueil — facultative, plusieurs choix, purement indicative
visibilité          tout passage ouvert et accueilli, pour tout service clinique autorisé
prise en charge     un vrai geste, tracé, qui crée ou accepte l'orientation
orientation réelle  transmission, demande d'un médecin, urgence — inchangées
```

## La prochaine étape suggérée

`episode_reception_next_steps` (`episode_id`, `module`, `created_by` ; unique
`episode_next_steps_unique`), enum `ReceptionNextStep` : Soins, Médecine, Maternité,
Laboratoire, Pharmacie, Chirurgie. Elle se coche à la **Confirmation** de l'accueil
(`NextStepPicker`) et se corrige depuis le détail du passage (`EpisodeNextStepsCard`,
`PUT /reception/passages/{uuid}/prochaines-etapes`, `episodes.update`, passage ouvert).
`SetEpisodeReceptionNextStepsAction` normalise, refuse une valeur inconnue, n'écrit rien si
rien ne change et audite `episode.next_steps.update` (ancien et nouveau choix).

```text
aucune case cochée   réponse valide — jamais d'erreur « obligatoire »
plusieurs cases      valides — « Soins, Médecine »
effet                aucun : ni orientation, ni visibilité, ni facturation
```

L'écran le dit : « Cette information est indicative. Elle n'empêche pas les autres services
autorisés de voir le passage. » Aucune ancienne orientation n'est convertie en suggestion.

## Ce que la confirmation de l'accueil crée encore

`PlanEpisodeRoutingAction` conserve l'instantané du besoin (tarif, couverture, parcours) et ne
crée plus que les **demandes techniques** que le patient est venu chercher :

```text
analyses         orientation RECEPTION -> LABORATORY + LabRequest (ADR-068)
acte du bloc     orientation RECEPTION -> SURGERY + SurgicalRequest PENDING (ADR-159)
Soins, Médecine, aucune orientation
Maternité
besoin inconnu   aucune orientation — « besoin à préciser » ne force plus les Soins
```

`ReceptionRoutingMode` reste une propriété du catalogue ; il ne sert plus qu'à vérifier la
cohérence du référentiel, à nommer la demande technique (`technicalRequestModule()`) et à
**proposer** la suite des Soins (ADR-166, `CareWorkflow::completionMode()`). L'étape
« Routage » et la « destination initiale » de l'aperçu financier disparaissent.

## L'urgence : l'exception assumée, inchangée

Une urgence est une décision de prise en charge immédiate, pas une suggestion : Soins et
Médecine reçoivent une vraie orientation d'emblée (ADR-021, ADR-056), par `CreateEpisodeAction`,
`MarkEpisodeEmergencyAction` et le filet idempotent de `PlanEpisodeRoutingAction`. Rien n'est
changé, et c'est écrit comme une exception.

## La visibilité : un tableau partagé des passages

`ActiveEpisodeBoard` sert à Soins (`/care`), Médecine (`/medicine`) et Maternité (`/maternity`)
le même tableau. Un passage est **actif** s'il est ouvert, si son accueil est terminé
(`service_plan_finalized_at`) — ou c'est une urgence, ou une vraie orientation existe — et s'il
n'attend pas seulement la Réception (`PENDING_SETTLEMENT` sans orientation active). Rien de
tout cela ne lit la suggestion.

```text
waiting      En attente — arrivés, pas encore pris en charge ici, chacun son n° de file (par défaut)
in_progress  En cours chez moi
completed    Terminés chez moi — passage encore ouvert

suggested    filtre du bloc « En attente » : l'accueil a suggéré ce service
emergency    filtre transversal : passages actifs en urgence, quel que soit leur état ici
```

Les trois blocs **ne se chevauchent pas** : un passage n'est que dans l'un d'eux, et la somme de
leurs comptes est le nombre de passages vus par ce service (amendement du 2026-09-23, demande du
propriétaire : une vue « Tous les passages » mêlait patients en attente et patients terminés).
Les comptes viennent du serveur ; une vue inconnue ou ancienne (`all`, `requested`) retombe sur
« En attente ».

**Chaque patient en attente a son n° de file**, qu'une vraie orientation l'ait envoyé ici
(« Orienté · en attente ») ou non (« En attente ») : les deux attendent, ils partagent la même
file. `ActiveEpisodeBoard::queueNumbers()` numérote **toute** la file — jamais la page ni le
filtre —, **par ordre d'arrivée à la clinique** (`episodes.started_at`) : le numéro ne recule pas
quand un service transmet le patient. Le n° 1 est affiché « Prochain ». Une urgence que la
Médecine n'a pas encore vue reste épinglée en tête, sans numéro (ADR-021, ADR-124). Un patient
pris en charge ou terminé ne tient plus de place. Le numéro que les Soins lisent pour la Médecine
est celui de la file du médecin. L'attente affichée se compte depuis l'arrivée. Un patient en
attente ici mais pris en charge ailleurs à cet instant porte la pastille « Actuellement :
Soins » : on ne l'appelle pas en plein soin. L'ancien calcul de `EpisodeQueuePresenter`, par
orientation, est retiré — la numérotation n'existe plus qu'à un seul endroit.

Une ligne ne porte que de quoi s'organiser — patient, heure d'arrivée, attente, priorité, besoin
**sans montant**, suggestion (« Aucune suggestion » est un état normal), état chez ce service, où
le patient est ailleurs — et les adresses des gestes permis, calculées côté serveur. **Voir un
passage n'est pas lire son dossier** : aucune constante, aucun diagnostic, aucune allergie, aucun
montant ; le détail reste gardé par ses propres droits. Les colonnes suivent le bloc : n°,
arrivée et suggestion pour la file ; qui et depuis quand pour « En cours » ; quand et la suite
pour « Terminés ». Un passage terminé se relit en un clic : journal de traitement du passage
(`treatment_journal.view`), dossier médical du passage (`patients.view`) et le passage lui-même
(icône du passage, la même que la recherche globale). Chaque adresse n'est servie qu'avec le
droit que sa route exige.
Chaque besoin porte l'icône de son service (la même que dans le menu et les suggestions),
« Besoin à préciser » et « Aucune suggestion » une icône neutre — ce sont des états normaux.
L'état chez ce service porte l'icône de son bloc — horloge « En attente », pouls « Pris en
charge » (sablier « En attente de résultat »), coche « Terminé » — et son libellé reste écrit à
côté : la couleur et l'icône ne portent jamais seules le sens.

Les gestes d'une ligne tiennent **sur une seule ligne**, à la même hauteur : le geste de travail
garde un libellé court (« Prendre », « Ouvrir » / « Consulter » / « Reprendre », « Remettre »),
puis les liens de relecture — journal, dossier, journaux du patient aux Soins, passage — forment
une barre d'icônes groupée, toujours à droite. Chaque icône est nommée au survol et pour les
lecteurs d'écran (`aria-label`) ; le libellé complet d'un geste de travail l'est aussi (« Prendre
en charge », « Remettre en file »). Le tableau tient sans défilement horizontal à 1600 px.

`Components/Clinical/ActivePassageBoard.vue` (shadcn-vue, ADR-099) rend le tableau pour les trois
espaces ; la Soins y ajoute la demande du médecin (ADR-118) et les journaux de traitement, la
Maternité ce qui l'a suivie (médecin, césarienne, ADR-135).

## La prise en charge : un vrai geste

`TakeChargeOfEpisodeAction` (`POST /{care|medicine|maternity}/passages/{uuid}/prendre-en-charge`)
accepte l'orientation qui attend, sinon en crée une (`RECEPTION -> module`, « Pris en charge
depuis les passages en cours ») puis l'accepte par l'action du service — `AcceptCareOrientationAction`,
`AcceptMedicineOrientationAction` (qui ouvre la consultation), `AcceptMaternityOrientationAction`.
Refusé si le passage est clos, s'il n'attend que la Réception, si son accueil n'est pas terminé,
ou si ce service a déjà **terminé** ce passage (une reprise par erreur ne rouvre rien). Déjà pris
par un collègue : le message le nomme, rien n'est doublé.

**Rien n'est créé en regardant** : ni orientation, ni consultation, ni fiche Soins, ni dossier
Maternité, ni dossier d'anesthésie, ni demande chirurgicale. La fiche Soins et le dossier
Maternité naissent toujours à la première saisie.

```text
Soins      care.create + care.update  (ADR-157)
Médecine   consultations.create
Maternité  maternity.update
```

`EpisodeOrientation` n'est pas supprimée : elle ne représente plus que les orientations
réelles et les prises en charge réelles.

## Le nouveau-né

L'accueil n'a plus que « Patient existant » et « Nouveau patient » : `NewbornPicker`, le mode
`newborn`, `EXTERNAL_NEWBORN` et les routes `/reception/newborns*` sont retirés. Un bébé **né à
la clinique** devient patient depuis sa fiche Maternité (`NewbornDossiers` › « Créer le dossier
patient », `POST /maternity/records/{record}/newborns/{uuid}/patient`, `maternity.view` +
`newborns.patient.create`) ; les règles de `CreateNewbornPatientAction` sont inchangées (numéro
dérivé, naissance jamais devinée, sexe exigé, idempotence). Un bébé **né ailleurs** est un
nouveau patient ordinaire, au profil enfant (ADR-146, amendement du 2026-09-22). La migration
`2026_10_27_100000` renomme le droit, l'ajoute aux recommandations du profil sage-femme et le
recopie aux comptes qui détenaient `maternity.newborn.manage` en exception.

## Ce qui ne change pas

Catalogue, estimation, tarifs, facture et Caisse (ADR-012, ADR-028, ADR-031, ADR-051) ; les
vraies orientations cliniques (Soins → Médecine, Médecine → Soins/Chirurgie/Maternité/…) ; la
Pharmacie seule, qui n'ouvre aucune file et rejoint « Sorties & règlements » (ADR-104) ; les
rapports centraux, qui comptent les orientations réelles (ADR-102).

## Signalé, non tranché

```text
PENDING_SETTLEMENT     un passage qui n'attend que la Réception quitte « En attente » (il reste
                       dans « Terminés » du service qui l'a vu) — à confirmer
numéro de file         un patient en soin ailleurs garde son n° ici (pastille « Actuellement »)
Maternité              voit tous les passages actifs, hommes compris : c'est la règle demandée,
                       à confirmer pour la Maternité
reprise après fin      refusée ; revoir un patient déjà terminé passe par une vraie orientation
socle RECEPTION        garde newborns.patient.create, sans effet sans maternity.view ; le retirer
                       est une décision du portail (ADR-064)
Labo, Pharmacie, bloc  leurs files lisent toujours leurs demandes réelles ; une suggestion vers
                       eux s'affiche mais n'a pas de tableau
suite des Soins        le parcours de la désignation propose encore la suite (ADR-166)
Anesthésie             non concernée : elle suit la demande chirurgicale (ADR-135)
```

## Amendement du 2026-09-23 — par où le passage devrait entrer

Demande du propriétaire : un médecin qui prend un patient attendu aux Soins doit en être prévenu,
et les Soins ne doivent pas prendre un patient attendu directement chez le médecin. Deux
arbitrages : la Médecine décide (« Soins ou consulter »), les Soins sont informés et refusés.

`App\Support\EpisodeEntryPath` lit, sans rien de clinique, les deux signaux déjà consignés à
l'accueil — la **suggestion** (prochaine étape) et le **parcours du besoin** (ADR-030). La
suggestion, choisie pour ce passage, l'emporte sur le parcours du catalogue.

```text
Médecine prend un patient attendu aux Soins     CARE_FIRST     fenêtre : « Faire les soins
  (suggestion Soins, ou besoin CARE_ONLY /                      moi-même » (droits Soins requis),
  CARE_THEN_MEDICINE sans suggestion Médecine,                  « Consulter quand même », Annuler.
  ou orientation Soins en attente)                              Le serveur ne refuse rien.
Soins prend un patient attendu en Médecine      MEDICINE_ONLY  fenêtre d'information seule
  (suggestion Médecine ou besoin MEDICINE_DIRECT,               (« Compris ») ; refus serveur dans
  et rien ne désigne les Soins)                                 TakeChargeOfEpisodeAction
jamais soumis au rappel                          urgence (ADR-021) ; vraie orientation en attente
                                                 vers ce service (soin demandé par le médecin) ;
                                                 côté Médecine, Soins déjà en cours ou terminés
```

Le refus aux Soins applique l'ADR-030 — une prestation `MEDICINE_DIRECT` ne passe pas
artificiellement par les Soins — et ne vaut que si **aucun** signal ne désigne les Soins. Il est
revérifié sous verrou dans l'action : l'écran n'est jamais la seule garde. Le message est écrit une
fois (`EpisodeEntryPath::refusalMessage()`).

Sur la ligne du tableau, `pathway` (code, titre, message, raisons, `care_take_charge_url`) n'est
servi qu'à qui pourrait prendre le patient ; un mot sous l'état le dit avant le clic (« Soins
d'abord », « Attendu en Médecine »). Un patient attendu en Médecine reste **visible** des Soins
(la visibilité ne dépend jamais du parcours) mais n'a ni adresse de prise en charge, ni n° de file,
ni place pour le garde-fou « un patient attend avant celui-ci » (`status` NONE) : son bouton est
verrouillé et ouvre l'information. Côté Médecine, « Consulter quand même » rejoint ce garde-fou
(ADR-121) ; « Faire les soins moi-même » prend le patient aux Soins (`care.create`, `care.update`,
`care.view`, ADR-157) et ouvre sa fiche. Composant : `Components/Clinical/EntryPathConfirm.vue`.

Aucune permission nouvelle, aucune migration. Aucune donnée n'est déduite : un besoin inconnu sans
suggestion ne déclenche rien, dans un sens comme dans l'autre.

---

# ADR-178 — « Rôles & permissions » devient un centre de gestion : modules, grille d'actions, un seul enregistrement

**Status:** ACCEPTED (2026-09-24 — exigence explicite du propriétaire : refonte complète de
`/super-admin/workspaces/roles`, jugé trop complexe pour un usage quotidien)

**Amende la présentation** des ADR-100, ADR-101, ADR-150, ADR-153 et ADR-173. **Aucune règle ne
change** : même résolution (`DENY individuel > ALLOW individuel > socle du rôle`, ADR-022/033), mêmes
routes, mêmes Policies, mêmes endpoints de l'API du site, mêmes permissions (`roles.*`,
`users.manage`, `permissions.assign`, `permissions.*`), même audit. Le portail n'écrit toujours dans
aucune base clinique (ADR-004, ADR-027). Aucune migration.

## Le constat

L'écran empilait quatre sections (socle, exceptions, rôles du site, catalogue), choisissait le rôle
dans une fenêtre, et présentait chaque catégorie comme une longue liste de cases sans ordre commun :
pour savoir ce qu'un rôle peut supprimer, il fallait ouvrir quarante listes et y chercher le mot.
Créer ou renommer un rôle ouvrait une fenêtre, chaque droit sensible coché en ouvrait une autre.

## Trois onglets, un geste par onglet

```text
Rôles                   la liste des rôles à gauche, le socle du rôle choisi à droite ;
                        créer, renommer, archiver, restaurer et réinitialiser s'y font sur place
Exceptions par compte   la liste des comptes à gauche, ce que chaque compte a réellement à droite
Catalogue des droits    les mots que l'application vérifie (ADR-101), rangés par module
```

« Rôles du site » n'est plus un onglet : un rôle se crée, se renomme ou s'archive là où on le lit.
La liste des rôles est une **colonne** (recherche, clavier, nombre de comptes et de droits, rôles
archivés repliés, rôle système signalé), plus une fenêtre : sous 1024 px elle s'ouvre dans un panneau
« Changer ». L'adresse suit ce qu'on regarde (`?site=…&vue=comptes&compte=…`, `&role=…`) par une
visite Inertia côté client : une actualisation, un lien partagé ou le retour d'un enregistrement
rouvrent exactement le même rôle ou le même compte.

## Modules, fonctionnalités, actions

Les catégories du code sont rangées dans **14 modules** (Accueil & patients, Soins infirmiers,
Médecine, Hospitalisation, Chirurgie & anesthésie, Maternité, Laboratoire & imagerie, Pharmacie,
Caisse & facturation, Tarifs & mutuelles, Ressources humaines, Logistique & sécurité, Utilisateurs &
accès, Administration & système) — `resources/js/utilities/permissionCategories.js`, une seule table.
Une catégorie inconnue tombe dans « Administration & système » : aucun droit ne disparaît de l'écran
faute d'être rangé. Onze
catégories qui n'avaient pas de libellé en reçoivent un (commandes et factures fournisseurs,
réceptions, catalogues et prix fournisseurs, registre des décès, journal de traitement, fiche de
régime, feuilles d'imagerie, protocoles, transferts). Le `name` d'une permission ne change pas.

Dans un module, chaque fonctionnalité — une ressource : `patients`, mais aussi `surgery.report` ou
`catalog.tariffs` — est une ligne, et ses droits se rangent dans **sept colonnes communes** :

```text
Voir | Créer | Modifier | Supprimer (ou archiver) | Restaurer | Valider (ou approuver) | Exporter
```

Ce qui n'entre dans aucune colonne (imprimer, clôturer, encaisser…) reste sur la ligne, sous son
libellé complet. **Chaque fonctionnalité porte son icône** (Dossier médical, Diagnostics, Ordonnances…) :
la sienne pour une sous-ressource (`PERMISSION_RESOURCE_ICONS`), sinon celle de sa catégorie, sinon
celle de son module — une fonctionnalité ajoutée plus tard n'apparaît jamais sans icône. Une
sous-fonctionnalité (« Compte rendu opératoire » sous « Chirurgie ») est décalée, avec une icône plus
petite ; le catalogue reprend les mêmes icônes. Un test vérifie que chaque catégorie et
sous-ressource du seeder a la sienne. `buildPermissionModules()` place chaque permission du catalogue d'un site ; un test
le vérifie sur les 377 permissions réelles. Sur un écran étroit, la grille devient des pastilles qui
portent leur verbe (requête de conteneur, pas de défilement horizontal).

Chaque module est un accordéon replié qui dit « N / total accordées » et « N modif. ». **Un seul
module est ouvert à la fois** : en ouvrir un referme les autres, et l'en-tête cliqué reste exactement
où il était à l'écran même si le module du dessus se replie (`composables/useExclusiveModules.js`,
mêmes règles dans les trois onglets). Une recherche ouvre d'elle-même les modules qui ont un résultat ;
en ouvrir un à la main referme les autres. Il n'y a plus de « Tout déplier », seulement « Tout
replier » quand un module est ouvert. Ouvert, un module propose « Tout sélectionner » / « Tout
désélectionner » (sur ce qui est affiché), chaque ligne a sa
case à trois états et chaque en-tête de colonne coche la colonne. La recherche porte sur le libellé,
le code, la fonctionnalité et le verbe — tous les mots, dans n'importe quel ordre ; le nom du module
n'y est pas (« supprimer patient » ramenait toutes les suppressions du module). Filtres : toutes,
accordées, non accordées, sensibles, modifiées.

## Un brouillon, un seul enregistrement

Cocher ne part pas au serveur : le socle (ou les exceptions) est un brouillon, relisible, jusqu'à
« Enregistrer les modifications ». Une barre collante dit combien de droits changent (+ accordés,
− retirés), à qui cela s'applique, propose « Revoir » (l'écart rangé par module) et « Annuler », puis
confirme l'enregistrement (« Modifications enregistrées · HH:MM »). Un refus du serveur garde le
brouillon et affiche l'erreur.

**Les confirmations ne restent que là où elles pèsent :**

```text
enregistrer un socle qui accorde des droits sensibles       la liste des droits concernés
enregistrer un compte qui s'autorise des droits sensibles   idem
réinitialiser un rôle (ADR-173)                              ce qui est restauré, exceptions intactes
réinitialiser un compte (ADR-173)                            héritage pur du rôle
archiver un rôle                                             motif obligatoire (5 caractères au moins)
quitter un brouillon (autre rôle, compte, onglet, site, page)  le nombre de modifications perdues
```

Cocher un droit sensible n'ouvre plus de fenêtre à chaque clic : on le confirme une fois, au moment
où il part. Créer un rôle (code proposé depuis le libellé, point de départ vide ou copie d'un socle)
et le renommer se font sur place, sans fenêtre.

## Ce que chaque écran continue de dire

```text
ADR-150   « N comptes de ce rôle portent des exceptions qui l'emportent sur ce socle »
ADR-153   sur chaque case : « Refusé à N comptes — le cocher ici ne l'ouvrira pas pour eux »
ADR-154   un bouton inaccessible nomme le droit qui manque (users.manage, permissions.assign…)
ADR-158   les gestes impossibles restent visibles, désactivés, avec leur raison
            (archiver un rôle encore porté par des comptes : « réaffectez-les d'abord »)
```

Côté compte, une case montre le **résultat** (accès ou non) et sa provenance : pâle quand le rôle
décide, pleine quand une exception décide, rouge quand une interdiction retire l'accès. Elle s'ouvre
sur trois choix — Suivre le rôle, Toujours autoriser, Toujours interdire —, chacun disant ce qu'il
produit pour ce compte. Un module entier se règle d'un choix.

## Signalé, non tranché

Un rôle n'a **aucune description en base**. Les rôles livrés portent une phrase fixe côté écran
(`utilities/roleDescriptions.js`) ; un rôle créé depuis le portail dit seulement qu'il l'a été.
Rendre la description éditable exigerait une colonne, une règle de validation et un champ dans l'API
du site : à décider.

Composants : `RoleDirectory`, `RoleOverviewCard`, `RoleCreatePanel`, `RoleWorkspace`,
`AccountDirectory`, `AccountWorkspace`, `PermissionToolbar`, `PermissionModuleCard`,
`PermissionMatrix`, `PermissionToggle`, `PermissionEffectCell`, `PermissionSaveBar`,
`composables/useUnsavedChangesGuard.js`, et deux primitives shadcn (`DropdownMenu`, état
intermédiaire de `Checkbox`). Retirés : `RoleBaselineEditor`, `UserPermissionOverrides`,
`PermissionCategoryNav`, `PermissionAccessRow`. shadcn-vue seulement (ADR-099).

---

# ADR-179 — Ce qu'une livraison réelle fait subir à une commande

**Status:** ACCEPTED (2026-09-22 — exigences du propriétaire sur le parcours de
réception, quatre arbitrages explicites) ; le §7 (« Entrée sans commande » est
conservée) est **renversé par l'ADR-182** (2026-09-24) : plus rien n'entre au
stock hors d'une livraison réceptionnée. L'article livré hors commande (§4),
qui fait partie d'une livraison, n'est pas concerné.

**Complète l'ADR-097** (commandes et réceptions), **l'ADR-175** (réceptionner
n'est pas ranger) et **l'ADR-176** (réceptionner appartient à la Pharmacie).
Le CDC ne décrit pas l'approvisionnement — l'ADR-097 est née d'une
spécification du propriétaire : les règles ci-dessous viennent toutes de lui,
et sont signalées comme telles.

## Ce que l'inspection a trouvé avant d'écrire

Deux des huit points demandés étaient **déjà en place** :

```text
quantité livrée < commandée   la réception s'ouvre déjà sur `quantity_remaining`,
                              avec des boutons −/+ bornés, et la quantité reste
                              corrigeable à l'entrée en stock (ADR-175)
listes dynamiques             fournisseurs, produits, catalogues et familles
                              viennent tous de la base (ProcurementFormOptions) ;
                              ce qui manquait n'était pas une liste figée mais
                              un concept absent — les statuts de ligne ci-dessous
```

Et un défaut de fond, que personne n'avait nommé : **une ligne jamais livrée
bloquait la commande à vie.** `refreshReceptionStatus()` ne passait `Received`
que si *toutes* les lignes étaient soldées ; un article en rupture définitive
laissait donc la commande éternellement « Partiellement reçue », donc
éternellement dans « À réceptionner », sans aucun moyen d'abandonner un
reliquat.

## 1 · La confirmation du fournisseur est une trace, jamais un passage obligé

Arbitrage du propriétaire, entre un proforma avant l'envoi, un accusé de
réception après, et une trace libre : **la trace libre**.

Certains fournisseurs confirment dispo et prix, d'autres livrent sans rien
annoncer. En faire une étape du workflow aurait imposé à tous ce que seuls
certains pratiquent. `purchase_orders` porte donc `supplier_confirmed_at`, une
référence, une note et un document facultatifs, avec l'auteur (local ou
distant) — et **rien n'en dépend** : une commande sans confirmation se
réceptionne exactement comme avant, ce qu'un test fixe.

Elle s'enregistre aussi sur un **brouillon** : un fournisseur qui confirme
avant que la commande ferme parte est précisément l'un des deux cas décrits.
Seule une commande annulée la refuse. Elle se corrige (un nouveau document
remplace l'ancien, et lui seul) et se retire — l'audit garde ce qu'elle disait.

```text
purchase_orders.confirm   accordée à aucun rôle du site par défaut (ADR-098),
                          au SUPER_ADMIN du portail ; *lire* la confirmation
                          suit `purchase_orders.view`, déjà au socle PHARMACY
```

**Elle s'enregistre aussi depuis le portail**, et c'est la raison pour laquelle
le droit y est accordé : le portail passe les commandes (ADR-098, amendement du
2026-09-15), donc c'est là qu'arrive l'accusé du fournisseur. Sans ce chemin,
`purchase_orders.confirm` aurait été, au portail, un interrupteur qui ne
commande rien — exactement ce que l'ADR-101 refuse. Même patron que le reste :
l'API du site (`orders/{uuid}/confirmation`, POST et DELETE) appelle l'**Action
de la clinique** avec un `CatalogActor` distant, la règle et l'audit restent
ceux du site, et le Super Admin ne devient jamais un auteur local.

Le **document** part en multipart et reste sur le site ; le portail ne fait que
le relayer au navigateur, comme un fichier de catalogue (ADR-098, amendement du
2026-09-17). Sans ce relais, un Super Admin déposerait une pièce qu'il ne
pourrait plus jamais ouvrir. La lecture de la confirmation, elle, n'a jamais eu
besoin de ce chemin : `SupplierPresenter` est partagé, donc les deux écrans
l'affichaient déjà — encore fallait-il que le bloc ne soit pas masqué faute de
lien d'écriture, ce qui était le cas.

Constater une rupture **ligne à ligne** reste au site, et n'a délibérément pas
d'équivalent au portail : c'est un constat de réception, fait avec la livraison
sous les yeux (ADR-176). Clôturer toute la commande, en revanche, est une
décision d'acheteur : elle existe des deux côtés.

## 2 · « Fournisseur similaire » veut dire le même produit, ailleurs

Arbitrage du propriétaire : **le même médicament chez un autre fournisseur**,
jamais un équivalent de la même famille.

Le référentiel ne porte aucune équivalence thérapeutique. Proposer « un autre
produit de la même famille » aurait demandé au système de deviner une
substitution — exactement ce qu'ADR-052 interdit, et avec un risque clinique.
`SupplierAlternatives` ne lit donc que ce que la clinique sait déjà : les prix
d'achat versionnés (ADR-097) et les lignes de catalogue actif rattachées à ce
médicament. Le moins cher d'abord quand le prix est lisible (`stock.cost.view`,
ADR-174), sinon par nom.

Le service **ne commande rien** : il répond « qui d'autre le propose, et à quel
prix ». Passer commande reste un geste à part, depuis le dossier du
fournisseur — un prix plus bas peut venir d'un fournisseur en rupture ou plus
lent, et l'écran ne décide jamais à la place de l'acheteur.

## 3 · Zéro veut dire « pas arrivé », et le reliquat s'abandonne

La quantité était bornée à 1 (`Math.max(1, …)` et `min:1` côté serveur) : le
geste « cet article n'est pas venu » existait — décocher la ligne — mais rien
ne le disait, et il ne distinguait pas « pas cette fois » de « plus jamais ».

```text
descendre à 0   la ligne se décoche d'elle-même, et dit « pas dans cette livraison » ;
                recocher rétablit ce que la commande attend encore
rupture         `purchase_order_lines.shortage_at/_reason/_by` : le reliquat cesse
                d'être attendu. Motif obligatoire. Rien n'est effacé — quantité
                commandée, prix et ce qui a déjà été reçu restent
```

Arbitrage du propriétaire sur le reliquat : **les deux** — ligne par ligne pour
tracer précisément ce qui a manqué, et une clôture globale pour solder d'un
geste une commande qu'on n'attend plus.

Une ligne en rupture disparaît de l'écran de réception (`quantityRemaining()`
renvoie 0) et **ne peut plus être reçue** : la recevoir rouvrirait en silence
un reliquat que quelqu'un a déclaré perdu. Il faut d'abord revenir sur la
rupture, ce qui rouvre la commande.

**`PurchaseOrderStatus::Closed` — « Clôturée »** est ajouté parce qu'aucun
statut existant ne dit la vérité : une commande dont un article est resté en
rupture n'a pas été livrée en entier, et l'écrire « Reçue » mentirait à qui
relira l'historique d'achat ; elle n'est pas « Annulée » non plus — elle a
réellement été envoyée, souvent livrée en partie, et peut porter une facture.
Elle quitte « À réceptionner » d'elle-même (`PurchasesOverview::awaitingGoods()`
ne l'inclut pas) et les filtres de la liste la reprennent sans code nouveau,
puisqu'ils sont construits sur `PurchaseOrderStatus::cases()`.

```text
constater une rupture   goods_receipts.create — c'est la personne qui a la
                        livraison sous les yeux qui voit ce qui manque (ADR-176)
clôturer la commande    purchase_orders.cancel — renoncer à ce qui reste dû est
                        une décision d'acheteur
```

**Clôturer n'est pas annuler, et le serveur doit le tenir.** Le droit étant le
même (`purchase_orders.cancel`), rien n'empêchait d'annuler ensuite la commande
qu'on venait de clôturer — `CancelPurchaseOrderAction` ne refusait que `Reçue`
et `Annulée`, deux statuts écrits avant que `Clôturée` existe. C'était une
sortie de secours qui défaisait la décision : une commande clôturée a réellement
été envoyée, souvent livrée en partie, et peut porter une facture ; l'annuler
dirait qu'elle n'a jamais eu lieu, et la rendrait **jetable** au passage
(ADR-176 : une commande annulée part à la corbeille, un brouillon aussi — mais
pas une commande vivante).

L'Action refuse donc les trois états que `PurchaseOrderStatus::isClosedOut()`
nomme, et le message dit la sortie plutôt que de laisser une impasse : on retire
d'abord les ruptures — la commande redevient attendue — puis on l'annule. Ce
helper, écrit avec cette décision, n'était jusque-là appelé nulle part : une
méthode que rien n'appelle ne protège rien.

## 4 · Un article livré hors commande se constate, il ne réécrit pas la commande

**Divergence signalée avec l'ADR-098**, qui pose que « une commande se modifie
tant qu'elle est en brouillon ; ensuite on l'annule ». Lui ajouter une ligne
après l'envoi changerait l'engagement pris auprès du fournisseur, et
contredirait aussi le point 5 en modifiant le montant de référence.

La réception constate donc ce qui est arrivé : `goods_receipt_lines.purchase_order_line_id`
devient **nullable**, et la ligne ne pointe alors aucune ligne de commande. La
commande garde son montant, ses lignes et son statut. L'écart qui en résulte
est **affiché** à la saisie de la facture, jamais corrigé en silence.

Le produit se désigne comme à la commande (ADR-098) : un médicament du
catalogue clinique, ou une ligne du catalogue actif de **ce** fournisseur — qui
entre alors au catalogue, sous les mêmes droits (`medicines.create`). Son prix
d'achat est celui que le fournisseur cote aujourd'hui, sinon celui que le
pharmacien lit sur le bon de livraison (`stock.cost.record`). Rien ne le borne
en quantité : aucune ligne de commande ne dit ce qui était attendu.

Une ligne hors commande entre au stock comme les autres (ADR-175), sans mettre
à jour une commande qu'elle ne solde pas.

## 5 · Le montant de la facture : avertir, jamais bloquer

Arbitrage du propriétaire, entre avertir, proposer le montant de la commande et
refuser un montant différent : **avertir sans bloquer**.

**Divergence signalée avec la demande initiale**, qui faisait de la commande la
source de référence : le montant proposé reste celui de **ce qui est réellement
arrivé**, parce que sur une livraison partielle c'est lui qui est juste — et
les points 2, 3 et 4 rendent justement les livraisons partielles normales. Ce
que la commande engage et ce qui a déjà été facturé sont servis **à côté**, de
sorte que l'écart se voie sans qu'une valeur fausse soit proposée d'office.

Le papier du fournisseur continue de faire foi (ADR-175) : l'écran explique
d'où l'écart peut venir — livraison partielle, article hors commande, prix
renégocié — et ne refuse rien. Les trois montants ne sont servis qu'avec
`stock.cost.view` (ADR-174).

## 6 · La commande part par la messagerie de la personne, jamais par le serveur

Demande du propriétaire : envoyer la commande au fournisseur par e-mail.

Ce qui est construit est un **brouillon**. À l'envoi, la messagerie de la
personne s'ouvre avec l'objet et le corps déjà écrits, depuis ce qui vient
réellement d'être commandé. Elle relit, complète, envoie depuis sa propre
adresse — et garde la trace dans sa boîte, là où arrivera la réponse du
fournisseur.

RIVO n'envoie donc rien lui-même, et c'est une limite assumée plutôt qu'un
travail à moitié fait : un envoi par le serveur demanderait un SMTP configuré
par site, une adresse d'expédition, une politique de pièces jointes et une file
d'attente — et une commande « envoyée » dans RIVO ne prouverait toujours pas
qu'un e-mail est parti. C'est une décision à part, signalée plus bas.

```text
à l'envoi         le brouillon s'ouvre avec la commande qui vient de partir,
                  jamais avec un formulaire déjà vidé
page d'une        « Envoyer par e-mail » : pour la fois où la messagerie ne
commande          s'est pas ouverte, ou pour relancer un fournisseur ; jamais
                  sur un brouillon ni sur une commande annulée
sans adresse      rien n'est proposé, et la commande part exactement comme
au dossier        avant — l'e-mail accompagne l'envoi, il ne le conditionne pas
```

Le corps reprend ce que le fournisseur doit lire, et rien de plus : référence,
date, livraison souhaitée, un produit par ligne avec sa quantité, son unité et
son code, le montant total, la remarque, puis la signature de qui écrit et de
son site. Une valeur absente disparaît au lieu de laisser une ligne vide
(« Livraison souhaitée : » suivi de rien).

**Le numéro de commande garde sa casse.** La phrase d'introduction mettait la
référence entière en minuscule — « notre commande abc-000012 ». C'est
l'identifiant que le fournisseur citera en retour et que la réception
retrouvera : seul le mot « commande » se met en minuscule, jamais le numéro.
Le défaut se voyait sur le chemin le plus courant, le renvoi depuis la page
d'une commande, où le numéro existe toujours.

Aucune permission nouvelle : envoyer la commande reste `purchase_orders.submit`,
et le brouillon n'est que du texte composé dans le navigateur à partir de ce qui
est déjà à l'écran. Un test exerce ce texte et la règle « sans adresse, aucune
messagerie ne s'ouvre » — c'est ce que le serveur ne peut pas rattraper.

## 7 · « Entrée sans commande » est conservée

Analyse demandée : fait-elle doublon avec le point 4 ? **Non.** Les deux gestes
constatent des faits différents :

```text
point 4                  une livraison d'un fournisseur, dans le cadre d'une
                         livraison qui existe — rattachée à sa réception
entrée sans commande     ce qui n'a aucun fournisseur ni aucune commande :
                         don, stock de départ, dépannage d'un confrère
```

La supprimer ferait rattacher un don à une réception fournisseur, donc
attribuer à ce fournisseur une marchandise qu'il n'a jamais livrée : le
mouvement de stock porterait une origine fausse et les statistiques d'achat
seraient faussées. Elle est **gardée telle quelle**.

## Ce qui ne change pas

Aucune règle de délivrance, de réservation FEFO, de facturation Caisse ou de
consommables Soins. L'ordre réception → entrée en stock (ADR-175) est intact,
la confidentialité du prix d'achat aussi (ADR-174), et la Pharmacie n'encaisse
toujours rien (ADR-013).

## La simulation locale est enfin exercée par un test

`DevelopmentProcurementSeeder` (ADR-098) n'est appelé par aucun autre seeder —
c'est voulu : la Pharmacie se saisit avec de vraies données (ADR-086). Mais
rien ne l'exerçait non plus, et il avait silencieusement pourri sur trois
points, dont deux qui faisaient échouer `db:seed` sur le poste du développeur :

```text
faute de frappe   une virgule manquante dans la liste des permissions : le
                  fichier ne se parsait même plus
signature périmée SetMedicineSupplierOfferAction et LinkSupplierCatalogItemAction
                  reçoivent un CatalogActor depuis l'ADR-098 (2026-09-18) ; le
                  seeder leur passait encore un User — TypeError fatale
droit manquant    l'ADR-174 a sorti `stock.cost.*` de tout rôle. Le compte de
                  test local, qui existe pour parcourir toute la chaîne, ne
                  pouvait donc plus enregistrer un prix d'achat à la réception :
                  les deux droits rejoignent ses permissions de provisionnement,
                  comme l'ADR-098 le prévoit déjà pour l'approvisionnement
```

Un test lance désormais la simulation sur le stock de démonstration et vérifie
les cinq états de commande, l'historique des prix, une réception, une facture
fournisseur, et qu'un second passage ne duplique rien. Le raisonnement de
l'ADR-101 vaut ici aussi : ce que rien n'exerce finit par ne plus marcher, et
personne ne l'apprend avant l'usage.

## Signalé, non tranché

```text
identifiant de ligne   une ligne de commande n'a pas d'UUID : les routes de
                       rupture exposent son id SQL, comme la réception le fait
                       déjà dans son payload. Contraire à l'esprit de l'ADR-050 ;
                       lui donner un UUID est une migration à part
commander depuis la    l'écran nomme les autres fournisseurs d'un article en
fenêtre de rupture     rupture mais ne crée pas le brouillon de commande : le
                       geste reste dans le dossier du fournisseur
délai de livraison     les alternatives affichent le prix, jamais un délai :
                       aucune donnée de délai n'existe au référentiel
envoi par le serveur   RIVO n'envoie aucun e-mail et n'enregistre pas qu'un
                       brouillon a été ouvert : il ne peut pas savoir si le
                       message est réellement parti. Un envoi réel — SMTP par
                       site, expéditeur, pièce jointe, file d'attente, accusé —
                       est une décision à part
```

Aucune règle de facturation nouvelle ; aucun rôle codé en dur (ADR-152).

---

# ADR-180 — L'entrée en stock est un seul écran, donc un seul geste

**Status:** ACCEPTED (2026-09-23 — exigence explicite du propriétaire :
« fusionner une page, pas deux ») ; le groupe « Arrivé hors commande » et la
provenance saisie sont **retirés par l'ADR-182** (2026-09-24). L'écran unique
et l'envoi unique restent.

**Amende l'ADR-175**, qui posait déjà « l'écran d'entrée en stock est unique »
mais laissait **deux onglets** — « Marchandise réceptionnée » et « Entrée sans
commande » — se partager la page, chacun avec son tableau, son formulaire et
son bouton.

## Le constat

Deux grandes cartes en tête de page, dont une seule ouvrait un contenu à la
fois. Le propriétaire y a vu ce qu'elles étaient : deux écrans dans un. Le
défaut n'était pas seulement visuel — une livraison réceptionnée et un don
arrivés le même matin demandaient deux saisies, deux validations, deux
transactions.

## La règle

Un tableau, deux intitulés à la suite, un bouton :

```text
Réceptionné · à ranger    ce que la réception a constaté : fournisseur,
                          commande, lot, péremption et quantité déjà remplis,
                          lignes déjà cochées — on relit, on corrige, on valide
Arrivé hors commande      le catalogue de la pharmacie, décoché : on coche ce
                          qui est arrivé d'un don, d'un stock de départ, du
                          dépannage d'un confrère
```

Une seule recherche filtre les deux. Les pastilles de livraison ne réduisent
que le premier groupe : le catalogue n'appartient à aucune livraison.

**La provenance n'est demandée que lorsqu'elle manque.** Une ligne réceptionnée
porte déjà la sienne — son fournisseur et sa réception. La question « d'où
vient cette marchandise ? » n'apparaît donc qu'à partir du moment où une ligne
hors commande est cochée, et elle n'est exigée que d'elles.

## Un seul envoi, deux règles intactes

`POST /pharmacy/stock/entries/batch` reçoit désormais les deux listes :
`lines` (réception) et `entries` (hors commande), l'une ou l'autre pouvant
manquer, jamais les deux. Le contrôleur les passe aux **Actions existantes** —
`RecordReceivedStockAction` et `RecordStockEntriesAction`, inchangées — dans
une transaction commune :

```text
chacune garde sa règle    une ligne réceptionnée est relue, rangée une seule
                          fois, et solde sa commande ; une ligne libre crée
                          son mouvement sous la provenance saisie
tout ou rien              une ligne refusée n'en laisse entrer aucune, pas même
                          celles de l'autre liste — un test le fixe
```

`POST /pharmacy/stock/entries/received` et `StoreReceivedStockRequest` sont
retirés : leur seul appelant était cet écran, et laisser un second chemin que
l'interface n'emprunte plus est exactement la duplication que l'ADR-098
reproche ailleurs. Leurs règles et leurs messages rejoignent la requête
commune.

## Ce qui disparaît, et pourquoi

```text
le sélecteur de mode      il n'y a plus de mode
le panneau des fournisseurs  remplacé par des pastilles de livraison au-dessus
                          du tableau : un second panneau pour choisir quoi
                          regarder dans un tableau unique refaisait un écran
le filtre par fournisseur il réduisait le catalogue aux produits d'un
du mode manuel            fournisseur. Sur un tableau unique, il aurait
                          contredit les pastilles de livraison : deux filtres
                          fournisseur sur la même liste. La recherche suffit à
                          retrouver un produit ; le fournisseur reste enregistré
                          sur les lignes hors commande, dans leur bloc
la case « tout cocher »   par groupe désormais, jamais pour le tableau entier :
                          cocher d'un geste tout le catalogue de la pharmacie
                          ouvrirait des centaines de lignes à remplir
```

## Ce qui ne change pas

La réception reste distincte du rangement (ADR-175), les dates du système ne
se saisissent pas, le lot et la péremption se lisent sur la boîte, le prix
d'achat vient de la commande et reste confidentiel (ADR-174), le prix de vente
se fixe à l'entrée par qui en a le droit. Aucune permission nouvelle :
`stock.entry`, comme avant.

## Signalé, non tranché

```text
/pharmacy/stock/entries   la route d'entrée unitaire (singulier) subsiste :
(singulier)               aucun écran ne l'emploie, seuls des tests l'exercent.
                          C'est une API supportée, pas un second écran — la
                          retirer est une décision à part
```

---

# ADR-181 — Comparer deux produits que les fournisseurs ne nomment pas pareil

**Status:** ACCEPTED (2026-09-23 — question explicite du propriétaire :
« si le nom du médicament n'est pas le même chez les deux fournisseurs,
comment comparer avant de commander ? »)

**Complète l'ADR-098** (le groupement par libellé normalisé) et **l'ADR-179**
(le comparateur de prix).

## La question était juste, et le défaut réel

Le comparateur réunit deux fournisseurs sur une même ligne **seulement si
leurs libellés se normalisent à l'identique** — accents, casse et ponctuation
ignorés (ADR-098). Dans les données de démonstration, les deux catalogues sont
écrits pareil, et tout paraît comparé. En vrai, un fournisseur écrit
« ALCOOL ETHYLIQUE 70% 1L » là où l'autre écrit « Alcool 1l 70° » : deux
lignes, deux prix, aucune comparaison.

Ce n'était pas seulement gênant. Commander la ligne du fournisseur fait entrer
le produit au catalogue de la clinique (ADR-098) : sans rapprochement, la
clinique se retrouve avec **deux produits** pour un seul article, chacun son
stock, son prix de vente et ses lots — exactement ce que le rapprochement par
libellé existait pour éviter.

## Ce qui existait, et ce qui manquait

Rattacher une ligne de catalogue à un médicament de la clinique existe depuis
l'ADR-097 : le prix du fournisseur devient alors une offre sur ce médicament,
et le comparateur groupe par médicament — les deux prix se retrouvent sur une
ligne. Deux choses manquaient :

```text
personne ne le voyait    le comparateur ne disait jamais que deux de ses
                         lignes désignaient peut-être le même produit
le portail ne pouvait pas  l'API du site exposait « défaire un rattachement »
                         sans exposer « en faire un » : depuis admin.rivo.mg,
                         on pouvait délier, jamais lier
```

## La règle propose, l'acheteur décide

`ProductLabel::looksLikeSameProduct()` porte une règle **prudente**, qui
préfère ne rien proposer à proposer un rapprochement faux — un rapprochement
faux crée un prix d'achat sur le mauvais produit, et le stock suit :

```text
les nombres font le produit    500 mg et 1 g, 125 ml et 250 ml, 18G et 25G ne
                               sont pas le même article : deux jeux de nombres
                               incompatibles écartent le rapprochement
une négation compte            « compresse stérile » et « compresse non
                               stérile » ne se rapprochent jamais
les mots doivent s'emboîter    l'un des libellés dit tout ce que dit l'autre,
                               et parfois davantage — « Alcool 1l 70° » et
                               « ALCOOL ETHYLIQUE 70% 1L » oui ; « Gants
                               taille M » et « Gants taille L » non
« 500mg » vaut « 500 mg »      la même dose écrite de deux façons n'est pas
                               deux produits
```

Elle ne lit ni DCI, ni forme, ni dosage : une ligne de catalogue fournisseur
n'en porte pas — elle n'a qu'un libellé, une présentation et un prix. Elle ne
devine donc rien d'autre que ce qui est écrit, et **ne décide jamais** : elle
pose une question à un humain, qui lit les deux libellés côte à côte.

La règle vit dans `ProductLabel`, avec le groupement exact de l'ADR-098 : les
deux doivent juger les mêmes libellés, sans quoi l'écran proposerait un
rapprochement que la commande refuserait ensuite.

## Ce que l'écran montre

Le comparateur porte un filtre **« À rapprocher · N »**, compté par le serveur.
Sur une ligne qui n'est pas encore au catalogue de la clinique, sous le nom du
produit : « Peut-être déjà au catalogue — « Alcool 1l 70° » — C'est le même
produit ? ». La fenêtre met les deux libellés côte à côte, rappelle qu'un
dosage, un volume ou un calibre différent en fait deux produits, montre les
prix déjà connus pour le produit visé, et exige un motif — conservé avec le
prix d'achat que le rapprochement crée.

**Une ligne sans prix n'est pas proposée au rapprochement.** Rattacher est
précisément ce qui crée le prix d'achat, et l'action le refuse sans montant
(ADR-098) : l'écran le dit et renvoie au catalogue du fournisseur, plutôt que
d'offrir un geste qui échouerait.

Plusieurs fournisseurs peuvent écrire le même libellé : on rattache **une
ligne à la fois**, celle qu'on désigne.

## Le chemin d'écriture, comme le reste du domaine

`POST /api/v1/super-admin/pharmacy/suppliers/{f}/catalogs/{c}/items/{i}/link`
appelle l'**Action de la clinique** (`LinkSupplierCatalogItemAction`,
inchangée) avec un `CatalogActor` distant. La permission est revue par
l'Action, sur l'acteur distant — créer un premier prix et en réviser un ne
sont pas le même droit (`medicine_supplier_offers.create` / `.update`,
capacité `set-medicine-supplier-offer` côté portail) — et le Super
Administrateur ne devient jamais un auteur local. Aucune permission nouvelle.

## Signalé, non tranché

```text
deux lignes de catalogue      deux fournisseurs qui nomment différemment un
qui se ressemblent, et        produit qu'aucun n'a encore fait entrer au
aucune au catalogue           catalogue ne reçoivent aucune proposition : il
                              n'y a rien à quoi les rattacher. Commander l'une
                              la fait entrer au catalogue ; l'autre se
                              rapproche ensuite
trois propositions au plus    au-delà, la liste cesse d'aider. Un produit que
                              la clinique tient sous quatre noms proches
                              n'aura pas tous ses rapprochements proposés
rapprocher en lot             chaque rapprochement est une décision lue ligne
                              à ligne ; rien n'en propose plusieurs d'un geste
```

## Amendement du 2026-09-24 — ce qui se compare, et par famille

Demande du propriétaire : un filtre pour voir ce qui est proposé chez les deux
fournisseurs — « beaucoup de médicaments ne sont pas chez les deux » —, et la
famille, « pour que l'utilisateur n'ait pas à parcourir de A à Z ».

```text
couverture   « Tous » · « Chez les deux fournisseurs » (« chez plusieurs » au-delà
             de deux) · « Seulement chez X », un par fournisseur. Chaque produit
             tombe dans une seule case, la somme fait le total (comme l'ADR-119) ;
             deux références du même fournisseur ne font pas deux fournisseurs
famille      liste « Toutes les familles » avec le compte de chacune, et la liste
             rangée par famille, « Sans famille » en dernier
comptes      chaque compte est ce que donnerait un clic, les autres filtres
             appliqués : une case qui annonce 5 n'ouvre jamais une liste vide
```

La famille est servie par le serveur (`SupplierOfferComparison`) : celle de la
clinique pour un produit qu'elle tient ; sinon celle que le fournisseur déclare
(`family_label`, ADR-098), écrite comme la famille de la clinique quand elles se
ressemblent — la normalisation de la création d'un médicament depuis une ligne.
Une ligne sans famille n'en reçoit pas d'inventée. Les règles de l'écran vivent
dans `utilities/supplierComparison.js`, testées en les exécutant.

**Défaut corrigé dans la règle de rapprochement.** `ProductLabel::looksLikeSameProduct`
vérifiait les nombres et les mots séparément, chacun dans le sens qui
l'arrangeait. « Alcool 125ml 70° » et « ALCOOL IODE SALICYLE IMRA 125ML »
passaient donc : le premier seul dit 70°, le second seul dit iodé salicylé —
deux produits différents. Le rapprochement a été proposé puis confirmé le
2026-09-24 sur la base locale d'Ambondromamy, créant un prix d'achat sur le
mauvais produit. La règle exige désormais ce que cet ADR disait déjà : l'un des
libellés dit tout ce que dit l'autre, mots **et** nombres, dans le même sens. Le
rattachement déjà fait n'est pas défait d'office : c'est une décision de
l'acheteur, qui se défait par « Défaire le rattachement » (ADR-098).

Aucune permission nouvelle, aucune migration.

---

# ADR-182 — Le stock n'entre que depuis une livraison réceptionnée

**Status:** ACCEPTED (2026-09-24 — arbitrage explicite du propriétaire, question
posée le 2026-09-23 : « le retirer complètement »)

**Renverse l'ADR-179 §7** (« Entrée sans commande » est conservée) et **amende
l'ADR-180** (le groupe « Arrivé hors commande »). La divergence est signalée,
jamais masquée (ADR-020) : l'ADR-179 avait analysé ce circuit et l'avait conservé
pour trois cas — don, stock de départ, dépannage d'un confrère.

## Le constat

Deux écrans se ressemblaient à s'y méprendre. « Médicaments & stock » listait les
six produits de la pharmacie ; « Entrée en stock » les listait aussi, sous
« ARRIVÉ HORS COMMANDE · 6 produits » — un intitulé qui affirmait que six produits
étaient arrivés, alors que c'était le catalogue à cocher. Le propriétaire a
demandé la différence entre les deux, puis le retrait du bloc : on ne reçoit pas
de produit hors d'une livraison.

## La règle

```text
Entrée en stock       ce qui a été réceptionné et attend d'être rangé, et rien
                      d'autre ; avant toute réception, l'écran est vide et dit
                      comment faire entrer du stock
Médicaments & stock   ce que la pharmacie tient ; une ligne rangée quitte le
                      premier écran et apparaît ici
```

Les deux écrans se suivent : réceptionner → ranger → tenir. L'accès dit ce qui
attend : « Entrée en stock · N à ranger », sur « Médicaments & stock » et sur
l'accueil Pharmacie (`ReceivedStockQueue::count()`, qui n'était lu nulle part).

## Ce qui est retiré

```text
serveur   POST /pharmacy/stock/entries (entrée unitaire libre), sa requête
          StoreStockEntryRequest, l'action de lot RecordStockEntriesAction, et le
          champ `entries` de l'envoi groupé
écran     le catalogue à cocher, la provenance « D'où vient cette marchandise ? »,
          la case « Stock de départ », le choix du fournisseur
liens     le « + » « Entrée de stock pour … » de chaque produit et le bouton
          « Enregistrer une entrée » de la fiche d'un médicament : ils
          promettaient une entrée par produit (et passaient `?medicine=`, que
          l'écran n'a jamais lu)
```

`POST /pharmacy/stock/entries/batch` n'accepte plus que `lines`, des lignes de
réception. Les champs de l'ancienne entrée libre (`entries`, `origin`,
`supplier_uuid`, `destination`, `reason`, `received_at`) sont **refusés en les
nommant**, pas ignorés : un envoi forgé reçoit une erreur, l'interface n'est
jamais la seule protection. Le prix d'achat (`lines.*.unit_purchase_price`)
l'est aussi : il vient de la réception (ADR-174).

## Ce qui reste

Le moteur `RecordStockEntryAction`, appelé par `RecordReceivedStockAction` :
mêmes règles de lot, même FEFO, même droit `stock.lots.create` pour créer un lot.
L'article livré hors commande (ADR-179 §4) : il fait partie d'une livraison.
L'import Excel central (ADR-042, `MedicineStockImportService`) a son propre
chemin et devient le seul pour reprendre un stock de départ, depuis le portail.
Les lots déjà détenus restent proposés à l'entrée (ADR-176) : la file des
réceptions les sert désormais, avec les droits de lecture du stock
(`stock.lots.view`, et `stock.expiration.view` pour la péremption).

Le tableau n'a plus qu'une nature de ligne ; il se regroupe par livraison
(fournisseur · commande), avec une case « tout cocher » par livraison.

## Signalé, non tranché

```text
don, dépannage d'un confrère   plus aucun chemin local : il faut une commande
                               et sa réception — à décider si le cas se présente
stock de départ                seul l'import central (ADR-042) le permet
```

Aucune permission nouvelle (`stock.entry`), aucune migration.

## Amendement du 2026-09-24 — l'article livré se choisit dans le catalogue du fournisseur, et réceptionner suffit

Demandes du propriétaire, le même jour, question par question.

**1 · La liste est le catalogue du fournisseur.** L'« article livré hors
commande » de la réception propose le catalogue ACTIF du fournisseur qui
livre, et lui seul (`ProcurementFormOptions::deliveredCatalogLines`) — ni le
catalogue de la pharmacie, ni une section « déjà au catalogue de la
clinique ». Une ligne déjà rattachée désigne le produit que la clinique tient
et ne crée rien. Le serveur le garde aussi : un produit de la clinique ajouté
hors commande doit être vendu par ce fournisseur — prix en cours, lien « peut
fournir », ligne de son catalogue actif ou commande déjà passée chez lui
(`MedicineSupplier::suppliedMedicineIds()`, une seule définition).

**2 · Réceptionner suffit — amende l'ADR-024 et l'ADR-098 pour la réception
seulement.** Les lignes non reprises étaient grisées : un compte Pharmacie
n'a pas `medicines.create`. Décision du propriétaire : réceptionner une ligne
du catalogue du fournisseur fait entrer le produit au catalogue de la clinique
avec le seul droit de réceptionner.

```text
délégation   CatalogActor::receivingDelivery(), demandée par la réception et
             nulle part ailleurs : medicines.create, catalog.items.create,
             medicine_supplier_offers.create/update — rien d'autre
ne crée pas  ni famille nouvelle (medicine_categories.create reste exigé),
             ni prix de vente : le produit arrive sans tarif, la Pharmacie le
             fixe à l'entrée en stock (ADR-174)
DENY         un refus individuel sur le droit délégué l'emporte toujours
             (ADR-033) : la ligne est montrée, pas proposée, et le serveur
             répond 403
commande     inchangée : commander une ligne non reprise exige toujours
             medicines.create (ADR-098)
```

**3 · L'entrée en stock se lit par livraison et par état.** Un bouton
« Ajouter un produit livré » y a été essayé puis retiré le jour même, à la
demande du propriétaire : ce qui arrive sur cet écran est déjà livré et
réceptionné, il reste à le rendre vendable. L'écran se lit désormais en deux
axes :

```text
cartes      À ranger · Nouveaux produits (jamais vendus : nom et prix à
            donner) · Sans prix de vente — la carte est le filtre, le compte
            est celui de la livraison choisie ; « sans prix » lit le prix
            enregistré, jamais celui qu'on tape
livraisons  à gauche, la plus ancienne d'abord (fournisseur, commande, date de
            réception, lignes, nouveaux) ; « Toutes » reste possible
validation  « Ranger cette livraison » : seules les lignes cochées de la
            livraison choisie partent ; la confirmation compte ce qui est
            rangé sans prix de vente, donc pas encore vendable
```

**4 · Les dates de facture sont calculées dans le fuseau du poste.**
`toISOString()` donnait la veille entre minuit et 3 h à Madagascar, et les
échéances « 30 jours » tombaient un jour trop tôt en permanence.
`localToday()` / `toLocalDateInput()` (`utilities/date.js`) les remplacent
sur les écrans de facture fournisseur. La date de facture reste « aujourd'hui »
par défaut, « Autre date » seulement si le papier en porte une autre.

Aucune permission nouvelle, aucune migration.

---

# ADR-183 — Un prix d'achat ne survit pas au retrait de son catalogue

**Status:** ACCEPTED (2026-09-24 — constat explicite du propriétaire : « j'ai déjà
supprimé le catalogue de Pharmalife mais il apparaît toujours ; normalement rien
n'apparaît »)

**Complète l'ADR-097** (prix d'achat versionnés), **l'ADR-098** (catalogues à la
corbeille, « Défaire un rattachement ») et **l'ADR-061** (Corbeille).

## Le constat

Les deux catalogues Pharmalife étaient à la corbeille ; « Comparer et commander »
affichait pourtant « Pharamalife · 5 produits ». Ce n'était pas le catalogue :
c'étaient les cinq **prix d'achat** que ses lignes avaient créés en étant
rattachées à des médicaments. Mettre un catalogue à la corbeille ne les touchait
pas : ils restaient « en cours », proposés au comparateur et à la commande pour
un tarif que plus personne ne tenait pour valable.

## La règle

```text
catalogue ou ligne à la corbeille   les prix d'achat qu'ils ont fournis et qui
                                    sont encore en cours sont clos (datés,
                                    libérés) — jamais supprimés (ADR-010)
restauration                        chaque ligne encore rattachée rétablit son
                                    prix, en nouvelle version au même montant,
                                    si la paire (médicament, fournisseur) n'a plus
                                    de prix en cours et que son dernier prix venait
                                    bien de cette ligne
```

C'est la clôture que fait déjà « Défaire un rattachement » (ADR-098). Une ligne
restaurée alors que son catalogue reste à la corbeille ne rétablit rien. Un prix
fixé depuis le retrait n'est jamais écrasé par une restauration. Un prix saisi à
la main, sans catalogue d'origine, n'est pas touché par le retrait d'un catalogue.

`App\Services\Pharmacy\SupplierCatalogPrices` porte la règle une seule fois, pour
les quatre actions — corbeille et restauration d'un catalogue, d'une ligne — que
la clinique, le portail (API du site) et la Corbeille empruntent tous. La clôture
suit le droit de retirer (`supplier_catalogs.delete`), le rétablissement celui de
restaurer (`supplier_catalogs.restore`) : les prix tirés d'un document suivent ce
document.

## Reprise

La migration `2026_10_28_090000` applique la règle aux catalogues et lignes déjà
à la corbeille : leurs prix encore en cours sont clos **à la date du retrait**,
chaque clôture tracée dans l'audit (`pharmacy.supplier_offer.withdraw`).

## Signalé, non tranché

```text
catalogue restauré   il ne redevient pas actif : ses lignes non rattachées ne
                     reviennent au comparateur qu'une fois le catalogue réactivé
                     (comportement antérieur, inchangé)
```

Aucune permission nouvelle.

---

# ADR-184 — Paramètres de l'application, propres à chaque site ; tranches d'âge des patients

**Status:** ACCEPTED (2026-09-24 — exigence explicite du propriétaire, arbitrages question par question)

Le CDC nomme `settings.*` parmi les droits du Super Admin (§18) mais ne décrit aucun paramètre ;
ADR-025 annonçait « paramètres globaux, dont le nom de l'application » sans les construire — l'écran
`/super-admin/workspaces/settings` n'était qu'une coquille. Les règles ci-dessous viennent du
propriétaire. **Amende ADR-146** (le profil enfant ne dépend plus seulement de la civilité) et
**complète ADR-108/113/116** (identité imprimée sur les documents).

## Arbitrages du propriétaire

```text
portée           TOUT par site : chaque site a son nom, logo, icône, couleur, devise,
                 tranches d'âge, identité légale et direction ; le portail a les siens
devise           l'écriture de l'Ariary seulement (Ar / Ariary / MGA, avant ou après,
                 décimales) — aucun montant converti, aucune autre devise
âge              calculé et contrôlé : bébé ou enfant → profil enfant ; un bébé se
                 déclare avec sa date de naissance exacte ; civilité incohérente refusée
signature du DG  sur les documents administratifs RH (attestations, contrats…) ;
                 factures et reçus restent signés par la caisse
```

## Une ligne par base, un repli sur la configuration

`app_settings` (une seule ligne, `AppSetting`) porte : nom de l'application, couleur principale,
logo, icône, écriture de l'Ariary, tranches d'âge (`baby_max_age` = 1, `child_max_age` = 15 par
défaut), directeur général (nom, titre, signature), NIF, STAT, adresse, téléphone, email, banque,
n° de compte. **Une colonne vide laisse la configuration de déploiement s'appliquer**
(`config/rivo.php`) : un site que personne n'a réglé s'affiche exactement comme avant.
`App\Services\Settings\AppSettings` (liaison `scoped`, une lecture par requête) résout chaque valeur ;
une base sans la table retombe sur la configuration au lieu de rendre l'application inaccessible
(constat de l'ADR-100).

Le portail règle un site **uniquement par son API** (`/api/v1/super-admin/app-settings`, fichiers en
multipart sur `/assets/{logo|icon|signature}`), réautorisée par `settings.view` / `settings.update`,
idempotente et auditée avec l'identité du Super Administrateur (`app_settings.update`,
`app_settings.asset.update`, `app_settings.asset.remove`). Le portail se règle lui-même par les mêmes
actions, dans sa propre base (cible « Portail Super Admin »). Les deux permissions existaient au
catalogue sans être vérifiées ; elles le sont désormais et restent au seul `SUPER_ADMIN` du portail.

## Ce que chaque réglage change

```text
nom           titre des onglets, barre latérale, connexion, documents (props partagées `site.brand`)
logo          connexion, factures, reçus, documents (`site.documents.logo_url`), servi par
              /branding/logo?v=… sans connexion, version changée à chaque remplacement
icône         favicon et pastille de la barre latérale (à défaut : les initiales)
couleur       variables --primary, --ring, --accent (clair et sombre) injectées dans l'en-tête ;
              texte blanc ou foncé choisi par contraste WCAG ; les couleurs d'état ne changent pas
Ariary        formatMoney (une seule écriture pour toute l'application) ; « si besoin » n'arrondit
              jamais un prix à centimes
légal         NIF, STAT, adresse, téléphone, email sur les documents ; compte bancaire sur la facture
direction     bloc de signature au bas des documents RH (case « Apposer la signature », cochée
              d'office quand un directeur est réglé)
âges          formulaire « Nouveau patient » (ci-dessous)
```

## Les tranches d'âge dans le formulaire patient

En années révolues : bébé jusqu'à `baby_max_age`, enfant jusqu'à `child_max_age`, adulte au-delà
(`App\Enums\PatientAgeBand`, `App\Support\Patients\PatientAgeRules`, et la même règle à l'écran dans
`utilities/patientAge.js`). À l'arrivée d'un nouveau patient :

```text
bébé ou enfant   profil enfant : téléphone, email, profession, pièce d'identité, situation
                 maritale et enfants non demandés, et refusés par le serveur ; civilité
                 « Enfant fille / garçon » proposée d'office selon le sexe
bébé             la date de naissance exacte est exigée : « 0 an » ne dit pas s'il a trois
                 semaines ou vingt-trois mois
adulte           civilité M. ou Mme ; « Enfant » refusée
```

La civilité proposée suit la tranche et le sexe ; une civilité qui contredit l'âge est **refusée
côté serveur, jamais corrigée en silence**. Seuls les nouveaux patients suivent ces tranches :
aucun dossier existant n'est réécrit, et la modification d'un dossier n'est pas contrainte (signalé).
Les tranches cliniques des constantes (ADR-125) restent distinctes et ne sont pas touchées.

## La signature ne se publie jamais

Le logo et l'icône ont une adresse publique ; la signature n'en a aucune. Elle entre dans un
document RH **copiée** (`data:`) au moment où il est produit : la remplacer ou la retirer ensuite ne
change aucun document déjà remis. Sans nom ni signature réglés, aucun bloc n'est ajouté — jamais un
signataire inventé. SVG refusé pour tous les fichiers (servi tel quel, il pourrait porter un script) ;
logo 1 Mo, icône et signature 512 Ko au plus.

## Écran

`/super-admin/settings` (ancienne adresse redirigée) : choix du site ou du portail, sommaire, six
sections — Identité, Couleurs, Monnaie, Âges des patients, Identité légale, Direction — avec
aperçus en direct (barre latérale, bouton et pastille, montants, frise des âges), une barre
d'enregistrement qui compte les modifications, et la confirmation avant d'abandonner un brouillon ou
de quitter la page. Un fichier se dépose, se relit, puis s'enregistre seul ; le retirer demande
confirmation. Un site injoignable le dit. shadcn-vue seulement (ADR-099).

## Signalé, non tranché

```text
modification d'un dossier   les tranches d'âge ne contraignent que la création
patient existant
autres devises              hors périmètre (arbitrage) : aucune conversion n'est prévue
portail et couleurs         le portail recharge sa page après un réglage pour appliquer
                            couleurs et icône ; un site les applique à sa page suivante
```

Migration `2026_10_28_090000_create_app_settings_table`, à jouer sur chaque site et sur le portail.

## Amendement du 2026-09-24 — devise de l'établissement et moteurs de recherche

Demande du propriétaire : « ajouter devise ou slogan, et une case à cocher pour que l'application ne
soit strictement pas vue par les moteurs de recherche ». La migration n'ayant encore été jouée sur
aucune base, elle est complétée sur place (deux colonnes nullables).

**Devise.** `app_tagline` (150 caractères) remplace la phrase écrite en dur sur la page de connexion
(« Ny fahasalamana no loharanon-karena »), qui devient la valeur par défaut (`rivo.tagline`,
`RIVO_TAGLINE`) : un site que personne n'a réglé s'affiche exactement comme avant. Servie par
`site.tagline` ; sans devise nulle part, la ligne disparaît. Elle n'est **pas** ajoutée aux documents
imprimés : ce serait changer d'office l'en-tête de toutes les factures et de tous les reçus.

**Moteurs de recherche.** `search_engines_hidden`, case cochée = masquée. Vide, la configuration du
déploiement décide (`rivo.search_engines.hidden`, `RIVO_HIDE_FROM_SEARCH_ENGINES`), **masquée par
défaut** : une application clinique n'a rien à montrer à un moteur. C'est un changement par rapport
au comportement d'avant (le `public/robots.txt` de Laravel autorisait tout), signalé ici. Masquée :

```text
robots.txt       servi par Laravel (RobotsTxtController) : « Disallow: / » ; le fichier fixe
                 public/robots.txt est retiré, sinon le serveur web le servirait à sa place
balise           <meta name="robots" content="noindex, nofollow, noarchive, nosnippet,
                 noimageindex"> dans chaque page
en-tête HTTP     X-Robots-Tag, même consigne, sur chaque réponse : images, documents, API,
                 robots.txt (ApplySearchEngineVisibility, middleware global)
```

Décochée, les trois disparaissent et robots.txt redevient celui de Laravel. Limite, dite à l'écran :
une page déjà référencée peut rester visible quelque temps (et un robot qui respecte « Disallow » ne
relit plus la page pour y voir « noindex ») ; le retrait se demande dans l'outil du moteur (Google
Search Console). Les fichiers statiques servis directement par le serveur web (`public/build`,
`public/images`) ne passent pas par Laravel : seul robots.txt les couvre.

Écran : champ « Devise ou slogan » et aperçu de la page de connexion dans Identité ; septième section
« Moteurs de recherche » (case à cocher, état Masquée / Visible, ce qui est appliqué, aperçu du
robots.txt). Mêmes droits (`settings.view` / `settings.update`), même audit. Le sommaire des
sections passe à droite du formulaire.

**Base non migrée.** Lire se passait déjà de la table ; écrire renvoyait l'erreur SQL brute
(« Table 'app_settings' doesn't exist »). `AppSettings::ensureInstalled()` — table et dernière colonne
ajoutée — répond désormais par une phrase qui dit de jouer les migrations (422, clé `site_code`, ou
`file` pour un fichier). Un refus sans
champ — base non migrée, site qui refuse — s'affiche dans la barre d'enregistrement, où il n'était lu
nulle part auparavant.

## Amendement du 2026-09-24 (bis) — modèles d'écran, image de fond et « Mon profil »

Demande du propriétaire : choisir depuis le portail les modèles des pages d'authentification et du
profil utilisateur, rendre l'image de fond de la connexion modifiable, en s'inspirant des modèles
DashWind réécrits en shadcn-vue. Trois arbitrages : **un modèle pour toutes les pages
d'authentification**, **une image de fond par site**, et « Mon profil » **en lecture, plus le
changement de son mot de passe**. Le CDC ne décrit ni ces écrans ni un profil utilisateur.

```text
auth_template      COVER (défaut, l'écran d'avant) · SPLIT (DashWind v1/v3) · CENTERED (DashWind v2)
                   connexion, mot de passe oublié, réinitialisation et activation de compte
auth_background    fichier « background » (JPG/PNG/WEBP, 2 Mo) servi par /branding/background ;
                   sans fichier, l'image du déploiement (rivo.auth_cover_url). CENTERED n'en montre pas
profile_template   SIDEBAR (défaut, DashWind « user-profile-regular ») · BANNER (bandeau et onglets)
```

Une valeur inconnue retombe sur le défaut : un modèle retiré ne casse jamais la connexion. Les pages
passent par une enveloppe unique (`AuthShell`) et quittent DashWind (ADR-099) ; `IdentityPanel` est
retiré. L'aperçu de l'image envoyé au portail est réduit à 640 px (JPEG) par le site, sans quoi la
page du portail porterait plusieurs photos entières. Migration
`2026_10_29_090000_add_screen_templates_to_app_settings`.

**« Mon profil »** (`/profil`, remplace « Bientôt » dans le menu du compte) : identité, rôle, profil
métier, établissement, dernière connexion, et les droits effectifs rangés par les modules de
l'ADR-178 — en lecture. Le nom, l'email, le rôle et les droits restent à l'administration (ADR-022).
Seul le mot de passe se change (`PUT /profil/mot-de-passe`, limité à 6 essais par minute) :
l'ancien est exigé, la politique commune s'applique (`SecurePassword`), le nouveau doit différer ;
les autres sessions du compte sont fermées, celle en cours est gardée, le jeton « se souvenir de
moi » est renouvelé, et l'action est auditée (`user.password.change`) sans jamais écrire le mot de
passe. Aucune permission : c'est son propre compte. La garde « base non migrée » vérifie désormais
aussi la dernière colonne ajoutée.

---

# ADR-185 — Apparence Clair / Système / Sombre et squelette de chargement des pages

**Status:** ACCEPTED (2026-09-24 — exigence explicite du propriétaire)

Présentation seulement : aucune route, permission ni règle métier. shadcn-vue (ADR-099).

## Apparence

Le bouton « Mode sombre » (une bascule clair ↔ sombre) devient un choix à trois options — **Clair**,
**Système**, **Sombre** (`ThemeModeSwitcher`) : dans la barre du haut, trois icônes juste à côté de
la cloche des notifications (demande du propriétaire, même jour) ; sur un téléphone, où la barre
n'a pas la place, dans le menu du compte ; sur les pages de connexion, en pastille d'icônes. « Système » suit le réglage de l'appareil **en direct**
(`usePreferredDark`) : changer le thème de l'ordinateur change celui de RIVO sans recharger.

```text
choix gardé        sur le poste (`rivo:theme:mode`), comme avant ; défaut inchangé : Clair
avant l'affichage  un script de app.blade.php pose la classe `dark` : plus d'éclair de thème clair
                   au chargement d'un poste réglé en sombre
rendu serveur      le choix n'est lu qu'après l'hydratation (`initOnMounted`) : le serveur ne le
                   connaît pas, et un HTML divergent ne serait pas réparé par Vue
```

## Squelette de chargement

Pendant qu'Inertia va chercher la page suivante, la mise en page affiche un squelette à la forme
de la page qui arrive, lue sur l'adresse visée (`skeletonFor`) : tableau de bord, liste, fiche,
formulaire, document imprimable ou réglages. Il couvre toutes les pages de la mise en page
principale, site et portail, sans qu'aucune page n'ait à le déclarer.

```text
quand      seulement un vrai changement de page : visite GET qui ne garde pas l'état de la page.
           Recherche au fil de la frappe, filtre, rechargement partiel, envoi de formulaire,
           préchargement : jamais de squelette
délai      200 ms : une page servie vite s'affiche directement, sans clignotement
page       l'ancienne reste montée, cachée : une visite annulée la rend telle qu'elle était,
           saisie comprise
lecteurs   « Chargement de la page… » (role="status") ; les blocs gris sont masqués
d'écran
```

Limite : la forme est déduite de l'adresse, pas de la page elle-même (Inertia ne connaît le
composant d'arrivée qu'avec la réponse). Une page à l'adresse atypique reçoit la forme « liste ».
Les pages hors mise en page principale (connexion, feuilles plein écran) n'en ont pas.

---

# ADR-186 — Le Super Admin du portail détient réellement toutes les permissions

**Status:** ACCEPTED (2026-09-25 — signalement du propriétaire : « le Super Admin contrôle tout, pourquoi ne voit-il pas certains modules ? »)

**Rend vraie** une règle que les ADR-025, ADR-027 et ADR-064 affirmaient déjà : sur `admin.rivo.mg`, le rôle
`SUPER_ADMIN` reçoit automatiquement toutes les permissions. Aucune règle de résolution ne change
(`DENY individuel > ALLOW individuel > socle du rôle`), aucun rôle n'est contourné par son nom (ADR-007).

## Le constat

Seul `RolePermissionSeeder` accordait tout au Super Admin, et il ne se rejoue plus sur une base en service
(ADR-064). Ce qui est arrivé ensuite n'atteignait donc jamais le portail :

```text
absentes de la base du portail   18   créées seulement par PermissionSeeder
                                      (document_templates.*, generated_documents.*, debts.*,
                                       care_consumables.*, episodes.settlement.view…)
non accordées au SUPER_ADMIN     26   ajoutées par des migrations qui ne pensaient qu'aux rôles
                                      cliniques (hospitalization.*, newborns.*, transfers.*…)
```

Deux effets : des entrées disparaissaient de son menu — « Canevas de documents », un droit RH
(ADR-070) —, et comme le portail transmet ses droits à chaque appel (`X-Rivo-Actor-Permissions`), le site
refusait les commandes correspondantes.

## La règle

`SyncPortalSuperAdminPermissionsAction` s'exécute à la fin de chaque `php artisan migrate`, **même sans
migration en attente** (`MigrationsEnded` et `NoPendingMigrations`) :

```text
portail   crée chaque permission du catalogue (PermissionSeeder) qui manque, puis accorde au
          SUPER_ADMIN toutes les permissions de la base ; audit role.permissions.portal_sync
          (créées, accordées), seulement quand quelque chose change
site      aucun effet : le SUPER_ADMIN n'y reçoit rien (ADR-027)
```

Elle n'ajoute que ce qui manque : elle ne retire rien, ne touche aucun autre rôle ni aucune exception
individuelle — un `DENY` nominatif garde la priorité (ADR-033). Aucune permission nouvelle, aucune
migration : jouer `php artisan migrate` sur le portail suffit à rétablir une base qui a dérivé.

## Signalé, non tranché

Le catalogue complet représente 7,7 Ko dans l'en-tête `X-Rivo-Actor-Permissions`, contre 6,7 Ko avant.
Un nginx réglé par défaut refuse une ligne d'en-tête de plus de 8 Ko (`large_client_header_buffers 4 8k`) :
la marge est faible, et chaque nouvelle permission la réduit. Élargir ce tampon sur les sites, ou transmettre
les droits autrement, est à décider avant la mise en production.

---

# ADR-187 — Le Super Admin gère les Ressources humaines d'un site depuis le portail

**Status:** ACCEPTED (2026-09-25 — arbitrage explicite du propriétaire : « Consulter et gérer », après
signalement de l'écart avec le CDC)

**Corrige un écart avec le CDC** : §18 donne au Super Admin un accès global (`hr.*` compris) et §2 dit que
le portail « consulte et administre » les sites par API. La page RH du portail n'affichait que des compteurs,
et l'accueil RH du site affirmait « le portail central ne voit que ces chiffres, jamais les dossiers » — un
choix d'implémentation (ADR-066), jamais une décision. **Aucune règle RH ne change** : ce sont les écrans, les
droits et les actions du site.

## Un seul espace RH, servi deux fois

```text
routes/hr.php          les 65 routes RH, écrites une fois
  /administration/…            l'espace RH du site, pour ses comptes (inchangé)
  /api/v1/super-admin/hr/…     le même espace, pour le portail, derrière rivo.site-api
/super-admin/sites/{site}/rh/… le relais du portail (SiteHumanResourcesController)
```

Le portail transmet la requête du Super Admin à l'API du site (`SiteHrGateway`), le site exécute ses propres
contrôleurs, et le portail affiche **la même page Vue** avec les données reçues. Jamais d'accès à la base du
site (ADR-004, ADR-027).

## Côté site : un acteur distant, pas un compte

`ActAsRemoteSuperAdmin` fait du Super Admin l'utilisateur de la requête : `RemoteSuperAdmin`, un `User`
**jamais enregistré** (toute écriture le refuse), sans identifiant local, dont les droits sont **ceux que le
portail a transmis** pour cet appel. Il ne se crée que derrière le jeton du site. Les `can:` des routes, les
règles, les FormRequests et les actions RH le traitent comme n'importe quel compte : aucune n'a été réécrite.

C'est un autre mécanisme que le `CatalogActor` de l'ADR-098, choisi parce que les 37 actions RH attendent un
`User` : les convertir une à une aurait réécrit tout le module pour le même résultat. Les deux coexistent.

`ServeHrScreensAsJson` traduit les réponses sans toucher aux contrôleurs : page Inertia → son JSON, redirection
→ `{redirect, status, error}`, erreurs en session → 422, fichier et JSON inchangés. `back()` revient à la page
d'où vient le Super Admin (le portail envoie son chemin en `Referer`).

## Qui a fait quoi

```text
audit             user_id vide, external_actor_uuid/name = le Super Admin (Auditor)
fiche avec auteur external_*_by_uuid/name : congé décidé ou annulé, pièce RH déposée,
                  document généré, mouvement de crédit Bloc (created_by devient facultatif)
écran             « Nom (portail) » quand l'auteur n'est pas un compte du site
```

## Côté portail

```text
écrans autorisés   Administration/Index et les sous-dossiers RH seulement ; tout autre composant → 404
adresses           les liens du site (…/administration/…, …/api/v1/super-admin/hr/…) deviennent
                   /super-admin/sites/{site}/rh/… ; les autres sont laissés tels quels
écritures          une seule tentative, avec clé d'idempotence ; un fichier part en multipart
                   (PUT/DELETE → POST + _method)
aperçu fetch()     la réponse JSON du site, statut compris
site absent        annoncé, rien n'est appelé
droit d'entrée     employees.view ; chaque écran et chaque geste gardent leur propre droit, revérifié
                   par le site
```

Dans les écrans, chaque adresse RH passe par `hrUrl()` (`utilities/hrUrl.js`) : inchangée sur le site,
ramenée à `/super-admin/sites/{site}/rh` sur le portail, qui fournit `hrContext`. La barre `HrPortalBar`
reprend les rubriques du menu RH du site, avec leurs droits, le site en cours et le passage aux autres.
« Établissements › site › Ressources humaines » mène désormais à cet espace ; l'ancienne vitrine redirige.

## La page « Ressources humaines » du portail (amendement du même jour)

Demande du propriétaire : `/super-admin/workspaces/hr` n'était qu'un tableau de chiffres. Elle devient le point
d'entrée RH du portail, en shadcn (ADR-099) :

```text
Vue d'ensemble   chiffres de tous les sites connectés ; « À traiter » : ce qui attend une décision, site
                 par site, chaque ligne ouvrant la liste ; « Comparer les sites » : un tableau (« À
                 traiter » / « Effectif du jour »), chaque chiffre ouvrant sa liste sur le site, « Gérer »
                 par site, total des sites connectés ; une carte par site sur téléphone
Un site          statut, « Gérer les RH », accès direct aux rubriques (celles du menu RH du site, avec
                 leurs droits), chiffres cliquables, effectif par département avec sa part
Confort          le site choisi reste dans l'adresse (`?site=A`, visite côté navigateur, aucun appel aux
                 sites) ; « Actualiser » réinterroge les sites ; un site hors ligne ou non configuré le
                 dit une fois, avec la suite à donner
```

Les compteurs RH (`HrFigures`) deviennent un seul bloc compact en deux groupes, « À traiter » puis « Effectif
du jour », au lieu de sept grandes cartes sur deux rangées inégales : une tuile par chiffre (icône et nombre,
libellé court en entier dessous, phrase complète au survol), un nombre à traiter non nul en ambre, un zéro
discret. Environ 125 px de haut au lieu de 210, sur l'accueil RH du site, son panneau de la vue d'ensemble et
le portail.

Un chiffre masqué par les droits s'écrit « — », jamais 0 (ADR-102). Libellés des chiffres et liste des
rubriques sont écrits une fois (`utilities/hrFigures.js`, `utilities/hrSections.js`) et partagés par l'accueil
RH du site, la barre RH et cette page.

## Amendement du 2026-09-25 — la barre RH du portail ne défile plus

Constat du propriétaire : treize rubriques sur une seule ligne faisaient apparaître une barre de défilement
horizontale. La barre se lit désormais en deux niveaux, **sans rien cacher dans un menu** :

```text
thèmes      Accueil RH · Personnel (4) · Temps de travail (3) · Pilotage (5) — un clic mène à la
            première rubrique du thème
rubriques   celles du thème de la page affichée, juste à côté ; aucune sur l'accueil RH
disposition une seule ligne si la barre fait au moins 77 rem (mesuré : 1 236 px pour Pilotage),
            thèmes puis rubriques l'un sous l'autre sinon (requête de conteneur) ; jamais de
            défilement, pas de séparateur isolé en bout de ligne
```

Les thèmes sont ceux de l'accueil RH, désormais écrits une seule fois (`HR_SECTION_GROUPS`,
`groupHrSections` dans `utilities/hrSections.js`) : chaque rubrique appartient à un thème et un seul, un
thème sans rubrique permise disparaît, et une rubrique ajoutée au menu RH sans thème rejoint le dernier
plutôt que de disparaître. Présentation seulement : ni route, ni droit, ni adresse ne change.

## Signalé, non tranché

- Les écrans RH sont encore en DashWind : seules leurs adresses ont été touchées (ADR-099).
- Les pages imprimées affichent la marque du portail (ADR-184), le nom du site étant, lui, celui du site.
- L'en-tête des droits transmis (ADR-186) grossit avec le catalogue : même réserve.

---

# ADR-188 — Départements et Fonctions en modules ; le compte se relie à sa fiche depuis « Utilisateurs »

**Status:** ACCEPTED (2026-09-25 — exigence explicite du propriétaire : « on va créer modules
Départements, Fonctions, et je pense on doit supprimer Compte de connexion car dans la RH rien à voir,
mais dans la page de création [de compte] avoir le lien RH : Personnel clinique ou Externe ; si
personnel clinique on peut directement sélectionner »)

**Amende l'ADR-168** (le lien compte ↔ fiche se posait depuis le formulaire Employé) et **complète
l'ADR-066** (référentiels RH). Le CDC ne décrit ni la gestion des départements et des fonctions, ni le
rattachement d'un compte à une fiche Employé : les règles ci-dessous sont celles du propriétaire.

## Départements et Fonctions, chacun son module

Deux entrées dans le menu RH, au site comme au portail (ADR-187) : `/administration/departments` et
`/administration/job-titles`. Ils gèrent le **même référentiel** que les Paramètres RH
(`hr_reference_values`, types `DEPARTMENT` et `JOB_TITLE`), avec les **mêmes actions** et les **mêmes
droits** (`hr_settings.*`) — aucune permission nouvelle, aucune migration.

```text
liste        libellé, code, nombre de dossiers employés (actifs / inactifs), ordre, état
compteurs    En service · Actifs · Inactifs · Archivés — chaque carte filtre
créer        libellé ; le code se déduit du libellé (modifiable) ; ordre facultatif
modifier     libellé, code, ordre, « proposé dans les formulaires » (actif)
archiver     motif obligatoire ; les dossiers qui le portent le gardent (ADR-066)
restaurer    depuis le filtre « Archivés » ; un libellé archivé se restaure, il ne se recrée pas
```

Le type se lit sur l'adresse (`HrStructureKind`), jamais sur une valeur envoyée : une fonction ne se
modifie pas par l'adresse des départements (404). Les Paramètres RH ne listent plus ces deux types :
deux écrans pour la même liste finiraient par se contredire ; ils y renvoient. Un formulaire Employé
dont la liste est vide renvoie vers le module.

## Le compte de connexion quitte la fiche Employé

Le champ « Compte de connexion » est retiré du formulaire Employé. Le serveur refuse désormais
`user_uuid` sur une fiche (`prohibited`, message qui dit où le relier) plutôt que de l'ignorer. La
fiche affiche toujours le compte qui la porte, en lecture.

## « Personnel clinique » ou « Externe », à la création du compte

L'assistant de compte du portail et l'écran « Utilisateurs » du site demandent **d'abord** qui
utilisera le compte (`AccountKindPicker`, un seul composant) :

```text
Personnel clinique   choisir la fiche Employé (recherche nom, matricule, fonction) ; son nom et son
                     email sont proposés au compte, sans écraser une saisie
Externe              aucune fiche : aucun planning RH ne vaut pour ce compte
```

```text
création        le choix est obligatoire (account_kind) — jamais une valeur par défaut
modification    omettre le choix laisse le lien tel quel (ADR-074) ; changer de fiche délie
                l'ancienne ; « Externe » délie
une fiche       un seul compte, archives comprises ; une fiche déjà portée est montrée avec le nom
                du compte, et refusée par le serveur
en poste        un nouveau lien exige une fiche active et non archivée ; un lien déjà posé survit
audit           user.employee.link / user.employee.unlink sur le compte
```

« Personnel clinique » ou « Externe » **n'est pas stocké** : il se lit sur le lien
`employees.user_id` (`AccountKind`). Un drapeau séparé pourrait dire « personnel » sans fiche. Le lien
ne donne aucun droit et ne crée aucun compte (ADR-168) ; la disponibilité d'un chirurgien au bloc le
lit toujours de la même façon.

`EmployeeAccountLinker` porte la règle, appelé dans la transaction de `CreateUserAction` et
`UpdateUserAction`. `AccountKindRules` est la même validation pour l'API du site (portail) et l'écran
du site. L'API sert les fiches reliables (`data.employees`) et, pour chaque compte, `account_kind` et
sa fiche ; les listes de comptes affichent « Personnel clinique · matricule » ou « Externe ».

## Ce qui ne change pas

Les comptes déjà reliés depuis une fiche gardent leur lien. Aucune donnée n'est migrée. Les droits de
création de compte (`users.create`, `roles.assign`) et de paramétrage RH (`hr_settings.*`) sont
inchangés.

## Amendement du 2026-09-25 — on cherche la personne d'abord, son nom et son email suivent

Demande du propriétaire : avec « Personnel clinique », le Nom et l'Email ne doivent pas s'afficher d'emblée ;
on cherche d'abord la personne, en auto-complétion, et sa fiche donne le nom et l'email.

```text
recherche     un champ unique (nom, matricule, fonction, service, email), le curseur y est placé dès
              « Personnel clinique » ; accents et casse ignorés, tous les mots dans n'importe quel ordre,
              correspondances surlignées ; clavier ↑ ↓ Entrée Échap, Entrée relie sans soumettre
rapidité      aucun appel par frappe : la recherche porte sur les fiches déjà servies avec le formulaire
              (`utilities/staffSearch.js`) — 6 fiches en aperçu, 8 résultats, « N autres — précisez »
fiche prise   listée après les libres avec « Compte : … », jamais reliable
choisie       une carte « Fiche RH reliée » et « Changer » ; puis Nom et Email apparaissent, repris
              de la fiche (« Repris de la fiche RH — modifiable »)
sans email    la fiche RH n'a pas d'email : le champ reste vide, le curseur y va, et l'écran le dit
changer       une valeur reprise et non retouchée suit la nouvelle fiche ; une saisie à la main n'est
              jamais écrasée ; passer à « Externe » vide ce qui venait d'une fiche
Externe       Nom et Email saisis directement, comme avant ; en modification, ils restent toujours
              visibles (le compte existe déjà)
```

Correction au passage : changer de fiche gardait l'email de la précédente quand la nouvelle n'en avait pas.
Rien ne change côté serveur : ni règle, ni permission, ni donnée.


---

# ADR-189 — Le Super Admin voit toute la Pharmacie d'un site depuis le portail, et en gère l'administratif

**Status:** ACCEPTED (2026-09-25 — constat du propriétaire : « le module Pharmacie n'est pas affiché dans le
Super Admin, alors que le Super Admin peut tout voir » ; arbitrage explicite : « Voir tout, gérer
l'administratif »)

**Étend à la Pharmacie le mécanisme de l'ADR-187** (RH d'un site servies au portail) et **applique
l'ADR-098** : les actes physiques restent au site. Le CDC §18 donne au Super Admin un accès global et §2 dit
que le portail « consulte et administre » les sites par API. Aucune règle de la Pharmacie ne change.

## Le constat

« Établissements › site › Pharmacie » ne menait qu'à une vitrine (description et rubriques en texte). Le
portail avait bien deux espaces Pharmacie — la supervision du stock (ADR-042) et les dossiers fournisseurs
(ADR-098) —, mais aucune ordonnance, aucun consommable, aucune fiche de médicament ni aucune réception d'un
site n'y était lisible.

## L'arbitrage

```text
visible depuis le portail   tous les écrans de la Pharmacie du site : ordonnances, consommables,
                            médicaments & stock, fiches, achats (commandes, réceptions, factures),
                            fournisseurs et leurs catalogues
géré depuis le portail      l'administratif : médicaments, prix de vente, familles, fournisseurs,
                            catalogues, prix d'achat, commandes (confirmer, clôturer), factures
reste au site               les actes physiques, faits par la personne qui a les produits en main
```

## Un seul jeu de routes, servi deux fois

`routes/pharmacy.php` porte les routes de la Pharmacie une seule fois :

```text
/pharmacy/...                            le site, pour ses comptes (inchangé)
/api/v1/super-admin/site-pharmacy/...    la même, pour le portail, derrière le jeton du site
                                         (rivo.remote-actor + rivo.hr-screens, ADR-187)
/super-admin/sites/{site}/pharmacie/...  le relais du portail (SitePharmacyController, pharmacy.view)
```

Le portail transmet la requête à l'API du site et affiche **la même page Vue**. Jamais d'accès à la base du
site (ADR-004, ADR-027). La mécanique de relais est désormais commune aux RH et à la Pharmacie :
`SiteScreenGateway` / `SiteScreenController` (abstraits), déclinés en `SiteHrGateway` et
`SitePharmacyGateway` — l'écran d'arrivée de la Pharmacie est « Médicaments & stock ». Un lien
`?module=PHARMACY` mène à ces écrans, comme `?module=HR` (ADR-187).

## Les actes physiques restent au site, et le site le garantit

Le middleware `rivo.site-only` (`KeepPhysicalActsAtSite`) refuse au Super Admin distant, avec un 403 qui dit
pourquoi, les routes qui changent l'état physique du stock ou remettent un produit :

```text
délivrer et préparer le ticket, imprimer le ticket du patient
servir un consommable
entrer en stock (écran et envoi)
inventaire (écran et envoi), ajustement (écran et envoi)
réceptionner (écran et envoi)
constater ou lever une rupture ligne à ligne (ADR-179 : c'est un constat de réception)
```

Le refus vaut **même avec la permission** : c'est une règle de lieu, pas de droit. Sur le portail, ces
boutons sont **montrés verrouillés** avec leur raison (`SiteOnlyAction`), jamais masqués (ADR-158) ; sur le
site ils sont rendus tels quels, et le pharmacien garde exactement ses écrans. Clôturer toute une commande
reste une décision d'acheteur, possible des deux côtés (ADR-179).

## Qui a fait quoi

Le Super Admin agit comme `RemoteSuperAdmin` (ADR-187), sans compte local. `CatalogActor::fromUser()` le
reconnaît et le représente en acteur distant (UUID, nom, droits transmis) : toutes les actions
administratives de la Pharmacie, qui passaient déjà par `CatalogActor`, l'attribuent donc dans l'audit et
dans leurs colonnes `external_*`. `CreateMedicineProductAction` (ajout d'un médicament, import Excel) garde
désormais cet auteur externe au lieu d'un `created_by` local. Aucun utilisateur n'est créé sur le site.

## Côté écran

Chaque adresse d'un écran Pharmacie passe par `pharmacyUrl()` (`utilities/pharmacyUrl.js`) : inchangée sur
le site, ramenée à `/super-admin/sites/{site}/pharmacie` sur le portail, qui fournit `pharmacyContext`. La
barre `PharmacyPortalBar` reprend les rubriques du menu Pharmacie du site avec leurs droits
(`pharmacySections`, une seule liste), dit de quel site on lit la Pharmacie, permet de passer aux autres et
rappelle que les gestes physiques restent au site. `PharmacyHomePanel` (Vue d'ensemble du site) n'est pas
servi au portail.

## Ce qui ne change pas

Les permissions de chaque écran et de chaque geste, revérifiées par le site ; la Pharmacie n'encaisse rien
(ADR-013) ; la supervision du stock (ADR-042) et les dossiers fournisseurs du portail (ADR-098) restent en
place. Aucune permission nouvelle, aucune migration.

## Signalé, non tranché

- Les dossiers fournisseurs existent désormais **deux fois** au portail : l'espace « Fournisseurs pharmacie »
  de l'ADR-098 (ses propres pages, son API) et ces écrans du site. Les fusionner est une décision à part.
- Le middleware `rivo.hr-screens` sert maintenant les RH **et** la Pharmacie : son nom ne dit plus tout ce
  qu'il fait.
- La réserve de l'ADR-186 sur la taille de l'en-tête des droits transmis vaut ici aussi.

---

# ADR-190 — Adresses email professionnelles : le RH demande, le Super Admin crée chez l'hébergeur

**Status:** ACCEPTED (2026-09-25 — demande du propriétaire, quatre arbitrages explicites)

Le CDC ne décrit aucune adresse email professionnelle : les règles ci-dessous sont celles du propriétaire.
L'hébergeur est o2switch (cPanel) ; le domaine de test est `cbdc.mg`, remplacé par le domaine officiel de la
clinique à son achat — il se règle dans l'environnement, jamais dans le code.

## Les arbitrages

```text
qui crée        le RH du site demande depuis la fiche employé ; le Super Admin crée sur le portail,
                seul à détenir le jeton de l'hébergeur
mot de passe    généré (16 caractères, sans caractère ambigu), montré UNE fois à celui qui crée,
                jamais enregistré ni journalisé dans RIVO
départ          la boîte est suspendue (connexion bloquée, messages gardés), jamais supprimée
fiche RH        adresse proposée prenom.nom@domaine (modifiable, chiffre si homonyme) ; une fois créée,
                elle devient l'email de la fiche, donc celui du compte RIVO (ADR-188)
```

## Pourquoi le jeton ne quitte jamais le portail

Un jeton API cPanel donne le **contrôle complet de l'hébergement** (fichiers, bases, toutes les boîtes) : il
n'est pas limité aux emails. Le poser sur les trois sites cliniques multiplierait l'exposition. Il vit dans le
`.env` du portail (`RIVO_MAIL_HOSTING_URL`, `_USER`, `_TOKEN`), n'est jamais servi à l'écran (seul le nom du
serveur l'est), et aucun site ne parle à l'hébergeur.

## Le circuit

```text
site     RH : fiche employé › « Demander la création »       professional_mailboxes, REQUESTED
portail  Super Admin : « Créer »
           1. relit la demande sur le site (encore en attente ?)
           2. crée la boîte chez l'hébergeur (UAPI Email::add_pop)
           3. retient ce qu'il a créé (professional_mailbox_provisions, sans mot de passe)
           4. l'active sur le site par son API                 ACTIVE, email de la fiche mis à jour
```

L'hébergeur d'abord, le site ensuite : un site ne dit jamais « active » une boîte qui n'existe pas. Si le site
ne confirme pas (panne, délai), le mot de passe est quand même montré — la boîte existe — et « Réessayer »
ne fait que confirmer au site : le registre du portail empêche toute seconde création. Même principe pour la
suspension (`host_suspended_at`) : un nouvel essai ne redemande pas à l'hébergeur ce qu'il a déjà fait.

## Ce qui se passe à chaque état

```text
REQUESTED   demandée ; le RH peut l'annuler ; le Super Admin crée (adresse ajustable) ou refuse (motif)
ACTIVE      en service ; nouveau mot de passe possible (UAPI passwd_pop, montré une fois)
SUSPENDED   connexion, envoi et lecture bloqués chez l'hébergeur (UAPI suspend_login) ; réactivable
            (unsuspend_login), avec l'ancien mot de passe
REJECTED    refusée, avec motif ; CANCELLED annulée par le RH — les deux libèrent l'adresse
```

Une adresse n'est **jamais supprimée** (le modèle le refuse, ADR-010). Une adresse ouverte est unique, et un
employé n'en a qu'une ouverte (`active_key`, `employee_active_key`, index uniques). Un employé qui n'est plus
en poste ne peut pas en recevoir de nouvelle. Au départ, sa boîte encore active apparaît dans la vue
**« À suspendre »** du portail, et la fiche le dit au RH : la suspension reste un geste du Super Admin, puisque
le site n'a pas le jeton.

## Écrans

```text
site      fiche employé : carte « Email professionnel » (shadcn) — proposition, note, état, annulation
          (servie aussi au portail par les écrans RH relayés, ADR-187)
portail   Organisation › Emails professionnels (/super-admin/professional-emails) : Demandes,
          À suspendre, Actives, Suspendues, Historique ; filtre par site, recherche ; créer, refuser,
          suspendre, réactiver, nouveau mot de passe
```

## Permissions

```text
professional_emails.view        voir                              ADMINISTRATION, SUPER_ADMIN
professional_emails.request     demander, annuler sa demande      ADMINISTRATION, SUPER_ADMIN
professional_emails.create      créer chez l'hébergeur            SUPER_ADMIN
professional_emails.reject      refuser une demande               SUPER_ADMIN
professional_emails.deactivate  suspendre                         SUPER_ADMIN
professional_emails.activate    réactiver                         SUPER_ADMIN
professional_emails.update      nouveau mot de passe              SUPER_ADMIN
```

Enregistrées par la migration `2026_10_31_090000` (sites et portail) ; le Super Admin du portail les reçoit par
la synchronisation de l'ADR-186. Chaque transition est auditée sur le site (identité du Super Admin distant),
chaque geste chez l'hébergeur sur le portail (`professional_email.host_create`, `.host_suspend`,
`.host_unsuspend`, `.password_reset`) — jamais avec un mot de passe.

## Signalé, non tranché

- **Accès du portail à l'hébergeur** : o2switch peut exiger que l'adresse IP du serveur du portail soit
  autorisée ; un 401/403 est affiché comme tel. À vérifier au premier essai réel.
- La réponse de l'hébergeur quand on suspend une boîte déjà suspendue n'est pas documentée : c'est pourquoi le
  portail retient ce qu'il a fait plutôt que de rejouer.
- Taille des boîtes : 1024 Mo par défaut (`RIVO_MAIL_HOSTING_QUOTA_MB`), réglage technique à confirmer.
- Adresses non nominatives (contact@, secretariat@) : hors périmètre — ce module relie une adresse à une fiche.
- Changer le domaine plus tard ne renomme aucune boîte existante : ce serait une migration chez l'hébergeur.

## Amendement du même jour — sans outil « jetons API », le mot de passe du compte cPanel

Constat du propriétaire : sur son offre o2switch, la page cPanel « Manage API Tokens » n'affiche que
l'avertissement « The API Tokens feature is experimental », sans bouton de création. La FAQ o2switch réserve
l'outil aux offres Unique Growth, Unique Cloud, Unique Pro et aux serveurs managés.

```text
RIVO_MAIL_HOSTING_TOKEN     de préférence : Authorization: cpanel utilisateur:jeton
RIVO_MAIL_HOSTING_PASSWORD  à défaut : le mot de passe du compte cPanel, par une session (ci-dessous)
les deux                    le jeton l'emporte
```

Le mot de passe est moins sûr qu'un jeton : il ouvre aussi l'interface cPanel et ne se révoque pas séparément.
L'écran du portail le signale et invite à passer au jeton dès que l'offre le permet. Il ne vit, comme le
jeton, que dans le `.env` du portail et n'est jamais servi à l'écran.

**Correction du 2026-09-25 — une session, pas l'authentification Basic.** Premier essai réel sur
`abyssin.o2switch.net` : l'API répond 401 à l'authentification Basic **avec le bon mot de passe**, alors que
la connexion par le navigateur réussit. o2switch n'accepte le mot de passe que par une session. Le client
fait donc ce que fait le navigateur, à chaque appel :

```text
POST /login/?login_only=1            utilisateur et mot de passe dans le corps, jamais dans l'URL
                                     → cookie cpsession et chemin /cpsessNNN
POST /cpsessNNN/execute/Email/...    l'appel UAPI, avec le cookie de session
```

Un mauvais mot de passe (401) ou une double authentification (aucun jeton de session rendu) sont refusés
avant tout appel à l'API, avec un message qui le dit. Le mode jeton n'est pas touché. Vérifié contre le
serveur réel par la lecture seule (« Tester la connexion ») : les boîtes existantes du compte sont lues,
rien n'est créé. **La double authentification ne doit pas être activée sur ce compte** tant que RIVO utilise
le mot de passe : la connexion serait refusée.

**Le même jour — une création trop lente.** Mesuré sur le serveur réel : la connexion cPanel prend de 2 à
9 secondes selon l'heure, la déconnexion de 2 à 8, l'appel lui-même 2. Ouvrir et fermer une session à chaque
opération faisait attendre jusqu'à une vingtaine de secondes. Deux changements, sans toucher aux règles :

```text
session gardée       chiffrée dans le cache, réutilisée par les opérations suivantes pendant
                     RIVO_MAIL_HOSTING_SESSION_MINUTES (10 par défaut) ; 0 = une session par
                     opération, fermée après la réponse
session expirée      cPanel répond 401 sans rien exécuter : une nouvelle est ouverte et l'appel
                     rejoué, une seule fois — une boîte n'est jamais créée deux fois
connexion d'avance   l'ouverture d'une fenêtre qui touchera l'hébergeur (créer, nouvelle adresse,
                     suspendre, réactiver, nouveau mot de passe) ouvre la session pendant qu'on la
                     remplit — POST …/professional-emails/prepare, au portail comme au site, pour
                     qui détient un des droits qui passent par l'hébergeur ; aucune boîte touchée
« Tester »           ouvre toujours une session neuve, pour éprouver vraiment le mot de passe
```

Mesuré : une opération sur une session gardée prend 2 à 3 secondes, au lieu de 5 à 17. Garder la session
ne crée pas de nouveau secret : elle ne vaut que pour ce compte, que le mot de passe du même `.env` ouvre
déjà, et elle est chiffrée avec la clé de l'application.

## Amendement du même jour — « Nouvelle adresse » depuis le portail

Constat du propriétaire : sans demande en attente, le portail n'offrait aucun bouton de création. Le bouton
**« Nouvelle adresse »** laisse le Super Admin choisir lui-même le site et l'employé (recherche sur les employés en
poste sans adresse ouverte, servis par l'API du site avec leur adresse proposée), ajuster l'adresse, puis créer.

La demande est d'abord **enregistrée sur le site** (`POST /api/v1/super-admin/professional-mailboxes`, même règle
et même trace qu'une demande du RH, note « Créée directement depuis le portail »), puis la boîte est créée
exactement comme depuis « Demandes » (un seul chemin de création). Si l'hébergeur refuse, la demande reste en
attente et se reprend depuis « Demandes ». Droits : `professional_emails.create` et `professional_emails.request`.

## Amendement du même jour — le RH qui en a reçu le droit crée lui-même, depuis son site

Demande du propriétaire, arbitrage explicite : « tous les accès pour le Super Admin ; pour le RH, si le Super Admin
lui donne la permission, il peut le faire et le voir ».

**Divergence signalée avec la décision initiale** (« le jeton ne quitte jamais le portail ») : les accès à
l'hébergeur (`RIVO_MAIL_HOSTING_*`) peuvent désormais être posés **aussi dans le `.env` d'un site**. Sans eux, le
site continue de n'offrir que la demande, et la création reste au portail. Avec le mot de passe cPanel (l'offre
o2switch n'ouvrant pas les jetons), c'est l'accès à tout l'hébergement qui est copié sur chaque site qui le
reçoit : à remplacer par un jeton dès que possible.

```text
voir     page RH « Emails professionnels » (/administration/professional-emails, menu Ressources humaines),
         professional_emails.view — la même liste que le portail, limitée au site ; servie aussi au
         portail par les écrans RH relayés (ADR-187)
faire    créer, « Nouvelle adresse », refuser, suspendre, réactiver, nouveau mot de passe — chacun avec son
         droit (.create, .reject, .deactivate, .activate, .update), que le Super Admin accorde au RH depuis
         « Rôles & permissions » ; par défaut le RH ne fait que voir et demander
```

Un seul chemin pour tous : `MailboxProvisioner` (hébergeur d'abord, registre de ce qui a été fait, reprise sans
double création), que le portail emploie par l'API du site (`RemoteMailboxRegistry`) et le site par sa propre base
(`LocalMailboxRegistry`, le même `ProfessionalMailboxWorkflow` qui revérifie chaque droit). Un seul écran aussi
(`ProfessionalEmailsWorkspace`), ouvert sur le portail pour tous les sites ou sur la page RH pour un seul.

## Amendement du 2026-09-25 — l'email d'un employé ne se saisit plus

Constat du propriétaire : la création et l'import d'un employé demandaient un email, alors que l'adresse
professionnelle ne peut être créée qu'une fois l'employé enregistré — et qu'à son activation elle
**remplaçait** l'email de la fiche, faisant disparaître sans avertissement celui qu'on avait saisi. Deux
issues ont été posées (garder un email personnel à côté de l'adresse pro, ou retirer le champ) ; le
propriétaire a choisi de **retirer le champ**.

```text
création        plus de champ Email ; le formulaire dit que l'email est l'adresse professionnelle,
                demandée depuis la fiche une fois l'employé enregistré
modification    l'email s'affiche en lecture seule (« posé par l'adresse professionnelle »)
serveur         `email` est refusé (prohibited, message nommé) à la création comme à la
                modification ; Create/UpdateEmployeeAction ne l'écrivent jamais — une fiche
                modifiée n'efface plus l'adresse pro
import          aucun email lu ; une colonne « Email » est ignorée (un fichier exporté se
                réimporte), une adresse dans « Email/Tél » n'est ni un email ni un téléphone
modèle Excel    sans colonne Email ; l'export, lui, garde l'email (l'adresse pro existante)
```

L'email d'une fiche n'est donc plus posé que par l'activation de l'adresse professionnelle. Conséquences
assumées : un employé qui n'aura jamais d'adresse pro n'a pas d'email dans RIVO, et son compte de connexion
(« Personnel clinique », ADR-188) reçoit un email saisi à la main. Les emails déjà présents sur des fiches
**ne sont pas effacés** (ADR-010) : ils s'affichent en lecture seule et seront remplacés à l'activation d'une
adresse pro. Aucune migration, aucune permission nouvelle. Les tests ne lisent plus les accès réels à
l'hébergeur du poste (`phpunit.xml` les force à vide).

---

# ADR-191 — Thème, réglages avancés et numérotation propres à chaque site

**Status:** ACCEPTED (2026-09-25 — demande explicite du propriétaire : « améliorer UI et UX de la page
`/super-admin/settings` : réduire la taille des cards, ajouter Thèmes, Avancé, couleur en mode sombre et en
mode clair, paramétrer le numéro de patient et de passage, le matricule de l'employé » ; quatre arbitrages
posés question par question)

**Complète l'ADR-184** (paramètres par site) et **l'ADR-185** (Clair / Système / Sombre), **rend réglable
l'ADR-030** (format des numéros de patient et de passage) sans en changer la valeur par défaut. Le CDC ne décrit
ni thème, ni réglage d'affichage, ni format de numéro : les règles ci-dessous sont celles du propriétaire.

## Les arbitrages

```text
thème         « le site, et chacun ajuste » : le Super Admin fixe le thème de chaque site (préréglage,
              couleurs du mode clair et du mode sombre) ; chaque utilisateur garde Clair / Système /
              Sombre et ajuste, dans « Mon profil », la taille du texte, les animations et le contraste
avancé        taille du texte, densité et arrondis, réduire les animations, contraste — les quatre
numéros       préfixe, année (aucune / 2 / 4 chiffres), chiffres, séparateur, remise à 1 chaque année ou
              compteur continu ; passage = numéro patient + rang ; aucun numéro existant réécrit
matricule     proposé automatiquement selon un modèle (EMP-0001 par défaut), modifiable par le RH ;
              à l'import, une ligne sans matricule reçoit le suivant
```

## Thème

`app_settings` reçoit `theme_preset` et, par mode, trois couleurs : accentuation (`primary_color` reste celle
du mode clair, `dark_primary_color`), arrière-plan et avant-plan (`light_*`, `dark_*`). Toutes vides par défaut :
un site non réglé s'affiche exactement comme avant. Les surfaces (cartes, zones atténuées, bordures, texte
secondaire) sont **déduites** de l'arrière-plan et de l'avant-plan (`ThemePalette`) ; l'accent sombre non réglé
est déduit de l'accent clair, comme avant. Seul ce qui est réglé est redéfini dans `<style id="rivo-theme">`.
Les couleurs d'alerte (rouge, ambre, vert) ne changent jamais.

```text
préréglages   RIVO (d'origine), Océan, Forêt, Ardoise, Prune, Ambre, Nuit (ThemePresets) — chaque
              avant-plan contraste d'au moins 7 avec son arrière-plan, vérifié par test ; retoucher une
              couleur fait passer le thème en « Personnalisé »
lisibilité    un avant-plan qui contrasterait à moins de 4,5 (WCAG AA) avec l'arrière-plan du même mode
              est refusé par le serveur (ReadableThemeColors), une couleur vide valant celle d'origine ;
              l'écran le dit avant l'envoi
échange       export en fichier JSON, copie, import (`{"rivo_theme":1,"light":…,"dark":…}`) : rien n'est
              enregistré avant « Enregistrer les paramètres »
aperçu        les deux modes côte à côte, chacun avec ses trois couleurs, son aperçu et son contraste ;
              le calcul de l'écran (utilities/themePalette.js) reproduit celui du serveur, vérifié par test
```

Le fond de page lit désormais `bg-background` (il était codé `bg-gray-50 dark:bg-gray-1000`) : l'arrière-plan
du thème s'applique partout.

## Réglages avancés

`ui_font_size` (14 à 18 px, 16 par défaut), `ui_density` (compacte / normale / aérée), `ui_radius` (droits /
normaux / arrondis), `ui_motion` (système / réduites / complètes), `ui_contrast` (standard / élevé / maximal)
sont les valeurs par défaut du site (`UiOptions`). Ils sont posés sur `<html>` **dès le rendu serveur**
(`data-density`, `data-radius`, `data-motion`, `data-contrast`, `font-size` en %), sans script : aucun éclair au
chargement. Hauteur des champs et boutons (`--control-h`) et arrondis (`--radius-scale`) sont des variables.

**Chacun ajuste pour lui-même** taille du texte, animations et contraste dans « Mon profil » › Apparence
(`PUT /profil/apparence`, aucune permission : c'est son compte). La préférence est gardée **sur le compte**
(`users.ui_preferences`), pas sur le poste : un poste de soins est partagé, et les réglages d'une personne ne
suivent pas la suivante. Vide, la valeur du site s'applique. La densité et les arrondis restent ceux du site.
Le mode Clair / Système / Sombre reste une préférence du poste (ADR-185).

## Numérotation

`AppSettings::patientNumbering()` rend un `PatientNumberFormat` : préfixe (vide = code du site), année
(`none`, `2`, `4`), chiffres (3 à 8), séparateur (`-` `/` `.` `_`), remise à 1 (`yearly` / `never`), chiffres du
rang du passage (2 à 4). **Sans réglage, c'est exactement l'ADR-030** : `A-26-0001`, `A-26-0001-01`,
`A-26-0001-B1`.

```text
compteur continu   la ligne de séquence de clé 0 ; « sans année » l'impose (une remise à 1 annuelle
                   redonnerait les mêmes numéros — refusé par la validation)
jamais redonné     le générateur saute tout numéro qui existe déjà, archives comprises : un ancien format
                   peut avoir produit la même chaîne
jamais réécrit     un réglage ne vaut que pour les numéros à venir
aperçu             le prochain numéro est lu sans être consommé (PatientNumberGenerator::peek())
```

Le rang du passage se relit quel que soit le séparateur réglé (`EpisodeNumberGenerator::sequenceFromNumber`).

## Matricule proposé

`EmployeeNumberAllocator` propose, à la création d'un employé, le matricule suivant le plus grand déjà écrit
selon le modèle du site (préfixe, séparateur, chiffres ; `EMP-0001` par défaut), **archives comprises** : un
matricule n'est jamais redonné. Un matricule écrit autrement ne compte pas. Le RH peut le corriger — c'est une
proposition, seul le caractère unique est exigé — et un matricule laissé vide à la création reçoit la
proposition. À l'import, une ligne sans matricule reçoit le suivant, après ceux que le fichier écrit déjà.

## Écran

`/super-admin/settings` est découpé en une page par module, sur le modèle de la page « Settings » de shadcn/ui
(amendement ci-dessous), sans défilement horizontal jusqu'à 390 px. shadcn-vue seulement (ADR-099). « Mon
profil » garde ses pastilles de choix (`OptionPills`) pour l'apparence personnelle.

## Droits et données

Aucune permission nouvelle : `settings.view` / `settings.update` (ADR-184), revérifiés par l'API du site.
Migrations `2026_11_01_090000_add_theme_and_numbering_to_app_settings` et
`2026_11_01_091000_add_ui_preferences_to_users_table`, colonnes toutes nullables, à jouer sur chaque site et
sur le portail. Une base non migrée le dit au lieu d'une erreur SQL (`AppSettings::ensureInstalled()`).

## Signalé, non tranché

```text
couleurs d'alerte      rouge, ambre, vert fixes ; les rendre réglables est une autre décision
taille du code, marqueurs de différence   vus sur les captures fournies, sans objet dans RIVO
numéros hors patient   factures, reçus, commandes gardent leur format ; les rendre réglables est à décider
```

## Amendement du 2026-09-25 — les paramètres suivent la page « Settings » de shadcn/ui

Constat du propriétaire, le jour même, en quatre temps : dix sujets sans rapport dans un seul formulaire, une
page saturée ; puis un accueil en cartes « éparpillé » ; puis des lignes à deux colonnes « trop coincées » ;
enfin un écran jugé comme un mélange de DashWind et de shadcn. Arbitrage : la page « Settings » de shadcn/ui,
refaite entièrement, sans rien garder des essais précédents (ADR-099).

```text
entrée      /super-admin/settings ouvre directement le premier module (Identité) ; le site choisi
            (?site=) suit la redirection
en-tête     « Paramètres » et sa phrase ; à droite, le site réglé (liste déroulante, avec « non
            configuré » ou « injoignable ») et « Réglé le … par … » ; puis un filet
gauche      le menu des modules : dix liens, celui qui est ouvert sur fond atténué, les autres
            soulignés au survol ; sur un écran étroit, une ligne qui défile
droite      le module ouvert, dans une colonne de lecture (max-w-2xl) : un titre, une phrase, un
            filet, puis ses champs empilés — libellé au-dessus, champ, phrase d'aide dessous —,
            deux ou trois par ligne quand ils vont ensemble ; en bas, « Enregistrer », « Annuler »
            et le nombre de modifications non enregistrées
garde       changer de site ou de module avec des modifications demande confirmation ; un module
            inconnu répond 404
```

Seules les primitives shadcn servent : `Input`, `Select` (chaque choix à plusieurs valeurs), `Tabs` (mode clair
et mode sombre du thème), `RadioGroup` en vignettes (modèles de connexion et de « Mon profil », flèches du
clavier), `Switch` dans un cadre (moteurs de recherche), `Label`, `Separator`. Les pastilles de choix, les champs
à icône et la barre d'enregistrement flottante disparaissent de la page.

**Aucune règle ne change.** Le formulaire porte toujours toutes les valeurs du site : un module n'en modifie que
les siennes et l'enregistrement renvoie le reste tel que le site le porte, par le même `PUT /super-admin/settings`,
la même validation et la même API du site. Une valeur par défaut choisie dans une liste vide le champ, comme
avant : le site n'enregistre que ce qui s'écarte de la configuration. La liste des modules est écrite une fois
(`utilities/settingsSections.js`) et le serveur n'accepte que la même (`AppSettingsController::SECTIONS`,
vérifié par test). Chaque module est un composant (`IdentitySettings`, `ThemeSettings`, `AdvancedSettings`,
`ScreenTemplates`, `NumberingSettings`, `AgeBandSettings`, `CurrencySettings`, `LegalSettings`,
`DirectionSettings`, `SearchVisibilitySettings`) écrit avec deux briques : `SettingsSection` (titre, phrase,
filet) et `SettingsField` (un champ de formulaire). Mêmes droits : `settings.view` pour lire, `settings.update`
pour enregistrer.

**Écrans & modèles** : trois champs, une question chacun — modèle des pages de connexion, image de fond, modèle
de « Mon profil ». Les vignettes sont des schémas teintés de la couleur principale ; la photo n'apparaît que dans
son champ, et la phrase du modèle choisi s'écrit une fois, dessous. Avec le modèle Centré, le champ de l'image dit
qu'elle servira au prochain changement de modèle.

**Complément du même jour — le module dans une carte, le menu à sa droite.** Demande du propriétaire : le
menu des modules passe à droite, avec les icônes, et la page du module dans une carte bordée. Le module ouvert
est une carte (`rounded-xl border bg-card`) : en tête son icône et son groupe, lus sur `settingsSections` et
jamais recopiés ; au pied, collant au bas de l'écran, l'état de la saisie et « Enregistrer ». Le menu est une
carte à droite, sticky, rangée par groupe (Apparence, Patients & personnel, Établissement & documents,
Confidentialité), chaque module avec son icône ; sur un écran étroit, il passe au-dessus du module en une ligne
qui défile et ramène le module ouvert en vue. Présentation seulement : ni route, ni droit, ni règle ne change.

**Complément du même jour — toute la largeur.** Demande du propriétaire : la page occupe toute la largeur de
l'écran (la limite `max-w-6xl` est retirée). Pour que cette largeur serve, la carte du module est un conteneur
(`cq`, petit plugin Tailwind : `cq-2xl:` / `cq-4xl:` / `cq-6xl:` suivent la largeur de la carte, jamais celle de
l'écran, puisqu'elle est bordée par le menu latéral et le menu des modules) : Numérotation, Affichage avancé et
Identité légale passent à trois colonnes, nom et devise côte à côte dans Identité ; les aperçus en liste gardent
une largeur de lecture. Le site réglé se choisit en un clic dans un groupe radio (`SettingsSiteSwitcher`), un
bouton par site et un pour le portail, chacun avec son état (joignable, non configuré, injoignable) ; changer de
site avec des modifications en cours reste confirmé. Ctrl+S (⌘+S sur Mac) enregistre le module — jamais sans
droit ni sur un site injoignable, et jamais la page du navigateur. Aucune route, aucun droit, aucune règle ne
change.

**Complément du même jour — la vignette de chaque thème.** Dans « Thème de départ », chaque thème porte sa
vignette : sa moitié claire et sa moitié sombre, chacune sur son arrière-plan avec sa couleur d'accentuation
(`ThemeSwatch`). « Personnalisé » montre les couleurs réellement réglées, et la vignette du thème choisi est
reprise dans le champ fermé. Le `Select` partagé reçoit pour cela un slot `leading`, placé hors du texte de
l'option que lisent le clavier et le champ fermé ; les autres listes ne changent pas.

**Complément du même jour — un repère par option de l'affichage avancé.** Les listes « Taille du texte »,
« Densité », « Arrondis », « Animations » et « Contraste » portent le même slot `leading` : chaque option montre ce
qu'elle change (`AppearanceOptionIcon`) — « Aa » à la taille choisie, lignes plus ou moins serrées, coins droits ou
arrondis, appareil / pause / animations, disque plus ou moins contrasté. Décoratif, le nom de l'option restant écrit.

**Complément du même jour — des repères dans tous les modules.** Demande du propriétaire, module par module. Les
listes de la Numérotation (année « 26 » / « 2026 », séparateur lui-même, autant de barres que de chiffres, remise
annuelle ou compteur sans fin) et de la Monnaie (« Ar1 » / « 1Ar », « 1 » / « 1,00 ») portent leur vignette, dans le
même carré partagé (`OptionTile`) que l'affichage avancé. Les champs texte d'Identité, d'Identité légale, de Direction,
des Âges et des préfixes de numérotation retrouvent une icône en tête (`IconInput`, primitive shadcn) : cela revient sur
les « champs à icône » retirés plus tôt le même jour, à la demande du propriétaire. Les aperçus en liste portent aussi
la leur (patient, passage, bébé, matricule, montant). Présentation seulement.

**Complément du même jour — « Moteurs de recherche » relu.** L'interrupteur est une carte qui montre l'état qu'il
produit : œil barré et pastille « Masquée des moteurs » en vert, œil et « Visible par les moteurs » en ambre, avec une
phrase qui change selon l'état. Les trois consignes (robots.txt, balise des pages, en-tête HTTP) deviennent trois
cartes, chacune avec son icône et un état appliqué / non appliqué. Les outils de retrait d'une page déjà référencée
(Google Search Console, Bing Webmaster Tools) sont des liens. Ce que le site sert — robots.txt et l'en-tête
X-Robots-Tag — se lit dans deux encadrés côte à côte, chacun avec un bouton « Copier ». Présentation seulement.

---

# ADR-192 — Remises : une par facture, la plus avantageuse, sur la part patient

**Status:** ACCEPTED (2026-09-25 — demande du propriétaire, quatre arbitrages explicites)

Le CDC prévoit les remises sans en fixer les règles : les droits `discounts.view/create/approve` (§12), le
reste à payer = prestations − paiements − « avoirs / remises autorisés » (§33.2), et « toute remise doit avoir
un motif, un utilisateur, une date, et éventuellement une validation hiérarchique » (§34.2 règle 7). Rien sur le
VIP, le personnel, les coupons ni le choix entre pourcentage et montant. `invoices.discount_amount` existait,
toujours à 0 ; aucun des droits n'était enregistré.

## Les arbitrages du propriétaire

```text
cumul            une seule remise par facture : la plus avantageuse pour le patient
base             la part patient, après la mutuelle et la prise en charge Personnel
personnel        la prise en charge de l'ADR-052 ne change pas ; la remise porte sur ce qui reste
                 à sa charge (prestations non couvertes, dépassement du crédit Bloc)
« par utilisateur »  un patient précis
```

**Amende l'ADR-133** (« le statut VIP ne change aucun tarif ; aucune remise n'est créée ») : une remise VIP
réglée par site s'applique désormais. Le statut reste calculé, jamais stocké, et la remise ne vaut que si la
catégorie VIP est active. **Respecte l'ADR-052** (aucune
remise ne simule l'avantage Personnel : il reste une couverture ; la remise vient après) **et l'ADR-047** (la
mutuelle paie toujours sa part contractuelle sur le tarif).

## Quatre sources, une remise

```text
Patient   remise propre à un patient, en vigueur (patient_discounts) — décision de discounts.approve
Personnel patient relié à la fiche d'un employé en poste (PatientStaffLink actif), règle du site
VIP       patient VIP sur ce site (ADR-133), remise réglée avec les seuils VIP
Coupon    code saisi à la Caisse (discount_coupons), valable aujourd'hui, non épuisé, non archivé
```

Chacune est un **pourcentage** (0 < x ≤ 100) ou un **montant** (> 0), à deux décimales
(`DiscountType`, `DiscountRules`). Un montant est plafonné à la part patient : une facture ne devient jamais
négative. `InvoiceDiscountResolver` liste celles auxquelles la facture a droit et retient la plus avantageuse ;
à égalité, patient > personnel > VIP > coupon — un coupon qui n'apporte rien de plus n'est pas consommé. Rien
n'est déduit d'un nom : chaque droit se lit sur un fait enregistré.

## À la Caisse, avant tout paiement

La fenêtre d'encaissement porte un bloc « Remise » (`InvoiceDiscountPanel`) : la meilleure remise, celles qui
sont moins avantageuses, un champ de coupon (« Vérifier »), et « Appliquer ». Le serveur choisit et calcule ;
le navigateur n'envoie qu'un code (`ApplyInvoiceDiscountAction`, `POST /invoices/{facture}/discount`,
`discounts.create`). Une remise ne se pose ou ne se retire (`RemoveInvoiceDiscountAction`, `DELETE`) que sur
une facture brouillon ou à encaisser **qui n'a encore rien reçu** — même règle que l'ajout d'une ligne
(ADR-054). Une seule en vigueur par facture (`invoice_discounts.active_key` unique).

```text
trace       invoice_discounts : source, libellé (le motif), type, valeur, part patient, montant, auteur,
            date ; une remise retirée reste, datée et signée ; le coupon retrouve son usage
totaux      InvoiceDiscountTotals : discount_amount, total_amount = part − remise, balance recalculée
ligne ajoutée  la remise suit la nouvelle part (un % se recalcule, un montant reste plafonné)
à zéro      facture réglée sans paiement : statut COVERED (« Prise en charge »), comme une couverture à
            100 % — aucun paiement ni reçu fabriqué ; retirer la remise la rend « À encaisser »
audit       billing.discount.apply / billing.discount.remove, anciens et nouveaux montants
```

## Remise d'un patient

Depuis son dossier, carte « Remises » : son statut (VIP, personnel) et sa remise propre. L'accorder ou
l'annuler exige `discounts.approve` (`GrantPatientDiscountAction`, `CancelPatientDiscountAction`), avec un motif,
une date de début (aujourd'hui par défaut) et une fin facultative. Jamais supprimée : annulée avec motif ; les
factures qui l'ont reçue gardent leur remise (un instantané). Audit `patient.discount.grant/cancel`.

## Réglages et coupons, depuis le portail

Chaque règle se règle là où vit ce qui la déclenche, par l'API du site ; vide = aucune remise, jamais de
valeur par défaut :

```text
remise VIP        module Patients VIP (/super-admin/patient-vip), avec les seuils qui font un patient VIP :
                  patient_vip_settings.discount_type/value, mêmes droits patient_vip.view/update, même audit
                  patient_vip.settings.update, mêmes règles côté portail et côté site
                  (PatientVipSettingsController::rules()) ; sans effet si la catégorie est désactivée
remise personnel  Paramètres › Remises : app_settings.staff_discount_type/value, enregistrés avec le reste
```

Un premier jet plaçait aussi la remise VIP dans `app_settings` : deux écrans auraient réglé le même patient
VIP, l'un ses seuils, l'autre sa remise. Le module « Remises » n'en montre rien, pas même un rappel : elle vit
dans Patients VIP, et seulement là. Les **coupons** se créent et s'archivent tout
de suite (`/api/v1/super-admin/app-settings/coupons`, `discount_coupons.create/archive`) : code unique pour
toujours, archives comprises ; validité et nombre d'utilisations facultatifs ; acteur central gardé
(`external_*`). Sur la cible « Portail », le module dit de choisir un site : le portail n'émet aucune facture.

## Supprimer un coupon archivé jamais utilisé (amendement du 2026-09-25)

Demande du propriétaire : une corbeille sur les coupons archivés. **Exception étroite** à « rien n'est supprimé »
(ADR-010), sur le modèle de l'ADR-062 pour un compte jamais utilisé :

```text
supprimable   archivé ET jamais servi : uses_count = 0 et aucune remise de facture qui le cite (même retirée)
refusé        encore actif (« Archivez d'abord ce coupon ») ou a servi (« a servi sur N factures : il reste
              archivé ») — la base le refuse aussi (invoice_discounts.discount_coupon_id, restrictOnDelete)
```

`DeleteDiscountCouponAction` verrouille le coupon, revérifie (`DiscountCoupon::deletionBlocker()`), garde dans
l'audit ce qu'il était (`discount_coupon.force_delete`, identité du Super Admin distant) puis le supprime ; son code
redevient libre, puisqu'il n'a jamais désigné aucune remise. Portail : icône corbeille sur chaque coupon archivé,
désactivée avec sa raison quand il a servi, confirmation obligatoire ; `DELETE /api/v1/super-admin/app-settings/coupons/{uuid}`.
Migration `2026_11_03_100000_add_discount_coupon_force_delete_permission`.

## Droits

```text
discounts.view / create      RECEPTION (la Caisse applique en encaissant)
discounts.view / approve     ADMINISTRATION (une remise durable est une dérogation habilitée)
discount_coupons.view / create / archive / force_delete   SUPER_ADMIN du portail (ADR-186)
```

Migration `2026_11_02_090000_create_discounts` (tables, colonnes de `app_settings` et `patient_vip_settings`,
droits), à jouer sur chaque site et sur le portail.

## Crédit du personnel

Il existe déjà : c'est le crédit Bloc du module RH (ADR-052), registre immuable avec allocation manuelle.
Rien n'y change. Sa période et son renouvellement restent à définir (ADR-052).

## Signalé, non tranché

```text
application automatique   la remise se pose à la Caisse, en un clic ; la poser d'office à la création de
                          la facture est à décider
remise libre du caissier  non construite : une remise manuelle au-delà d'un seuil, validée par
                          discounts.approve (§34.2 « validation hiérarchique »), est à décider
rapports                  « facturé » lit total_amount, désormais net de remise ; un total des remises par
                          période n'est pas encore affiché
restauration d'un coupon  non prévue : un coupon archivé ne revient pas, on en crée un autre (ou, s'il n'a
                          jamais servi, on le supprime et on réutilise son code)
```

---

# ADR-193 — Mode maintenance d'un site, avec message personnalisable

**Status:** ACCEPTED (2026-09-25 — demande du propriétaire, trois arbitrages explicites)

Le CDC ne décrit aucun mode maintenance (§18 ne cite que `settings.*` pour le Super Admin) : les règles
ci-dessous sont celles du propriétaire.

## Les arbitrages du propriétaire

```text
qui passe      seuls les comptes qui ont un droit dédié (app_maintenance.bypass), donné à personne par
               défaut : le Super Admin l'accorde, par exemple au technicien informatique qui vérifie le site
quand          maintenant, ou programmée (début et fin) ; bandeau d'avertissement avant le début,
               réouverture automatique à la fin prévue
portée         site par site, depuis Paramètres › Maintenance, comme tous les réglages (ADR-184)
```

## Les sites seulement, jamais le portail

Tous les comptes du portail sont Super Administrateurs (ADR-027) et détiennent toutes les permissions
(ADR-186) : une maintenance du portail ne fermerait rien à personne. La maintenance ne concerne donc que les
trois sites cliniques ; l'action refuse sur le portail et l'écran le dit (cible « Portail »).

## Une fenêtre, lue à chaque requête

`site_maintenances` garde une ligne par maintenance, jamais effacée : titre, message, début, fin prévue
(facultative), qui l'a posée, modifiée, levée, quand et pourquoi. Son état se lit à chaque requête, sans
traitement planifié :

```text
UPCOMING   le début n'est pas atteint ; bandeau dans les 24 heures qui précèdent
ACTIVE     commencée, ni levée ni à sa fin prévue : le site est fermé
ENDED      sa fin prévue est passée : le site a rouvert de lui-même
LIFTED     levée à la main (ou annulée avant son début)
```

Une seule est ouverte à la fois : `SetSiteMaintenanceAction` verrouille celle qui existe et la modifie. « Maintenant »
prend l'heure du serveur, et une maintenance déjà en cours garde son heure de début (on n'en corrige que le
message ou la fin) ; « programmer » une maintenance en cours la reporte — le site rouvre jusqu'au nouveau début,
et la confirmation le dit. `LiftSiteMaintenanceAction` lève (ou annule), motif facultatif. Audit
`app_maintenance.start / schedule / update / lift / cancel`, avec l'identité du Super Admin distant.

## Ce que voit le site

`EnforceSiteMaintenance` (groupe `web`, après `HandleInertiaRequests`) répond par la page `Maintenance` en **503**,
avec `Retry-After` quand la fin est prévue ; un appel qui attend du JSON reçoit du JSON. Restent ouverts :

```text
connexion, déconnexion, mot de passe oublié   le compte autorisé doit pouvoir entrer
logo, icône, robots.txt, /up                  la page de maintenance les utilise
l'API du site (hors groupe web)               le portail garde la main pour lever la maintenance
```

La page montre la marque du site, le titre et le message (texte simple, retours à la ligne gardés, jamais de HTML),
l'heure de retour prévue, « Réessayer », et se recharge d'elle-même à la fin prévue. Un compte connecté sans le droit
y lit qu'il n'a pas accès et peut se déconnecter ; un visiteur voit le lien de connexion réservée. La page de connexion
dit que le site est fermé. Un compte avec le droit travaille normalement, sous un bandeau qui le lui rappelle.

`SiteMaintenanceState` (liaison `scoped`) lit la fenêtre une fois par requête et sert la prop partagée
`site.maintenance` (`ACTIVE` avec `bypassing`, ou `UPCOMING`, sinon `null`). Une base sans la table ne ferme jamais
le site (constat de l'ADR-100).

## Depuis le portail

Paramètres › Exploitation › **Maintenance** : l'état du site, le titre et le message avec leur aperçu (le composant
même de la page du site), « Maintenant » ou « Programmer », début, fin prévue (+30 min, +1 h, +2 h, +4 h, ou sans
fin), « Lever / Annuler la maintenance » avec motif, et l'historique. Les gestes partent tout de suite par l'API du
site (`PUT /api/v1/super-admin/app-settings/maintenance`, `POST …/maintenance/lift`, idempotents), hors du formulaire
commun des paramètres : le module n'a pas de pied « Enregistrer ». Mettre en maintenance maintenant, programmer ou
reporter demande confirmation.

## Droits

```text
app_maintenance.update   mettre, programmer, modifier, lever   SUPER_ADMIN du portail (ADR-186) ; aucun rôle du site
app_maintenance.bypass   utiliser le site pendant sa maintenance   aucun rôle ; accordé nominativement
```

Distincts de `settings.update` : fermer un site n'est pas changer une couleur. Le Super Admin les reçoit comme tous
les autres, à la migration de la base du portail (ADR-186) : tant que `php artisan migrate --env=admin` n'est pas
joué, ces droits n'existent pas dans cette base et le module reste en lecture seule (constaté le 2026-09-25 en
local ; un compte Super Admin désactivé, lui, n'a aucun droit — ADR-022). Un site dont la base n'a pas la table
refuse la commande par une phrase qui le dit ; un site qui ne sert rien de lisible n'est jamais affiché « ouvert ». Distincts aussi du rôle MAINTENANCE
et de `equipment.maintenance.manage` (maintenance des équipements) — d'où le préfixe `app_maintenance`. Migration
`2026_11_03_090000_create_site_maintenances_table`, à jouer sur chaque site et sur le portail.

## Hors périmètre

La maintenance technique de déploiement (`php artisan down`, base arrêtée) reste celle de Laravel : elle ne peut pas
lire un message rangé en base. Une saisie non enregistrée au moment de la fermeture peut être perdue : le bandeau des
24 heures sert à l'éviter ; les brouillons serveur existants (ADR-073) restent.

---

# ADR-194 — RH : fonctions par département, photo 4 × 4, stagiaires et planning de garde

**Status:** ACCEPTED (2026-09-25 — demande explicite du propriétaire, quatre arbitrages : une fonction peut
appartenir à plusieurs départements ; la correspondance de départ est une proposition modifiable ; un stagiaire
est un employé avec un contrat de stage ; un créneau est du service ou une garde, lu en calendrier)

Le CDC nomme les fonctions internes par service (§9) sans dire comment un formulaire les restreint, ni rien des
stagiaires, de la photo ou des gardes : les règles ci-dessous sont celles du propriétaire. **Complète l'ADR-066**
(socle RH), **l'ADR-188** (modules Départements et Fonctions) et **l'ADR-187** (RH servies au portail).

## Une fonction existe dans un ou plusieurs départements

`hr_job_title_departments` relie une fonction aux départements où elle existe. Le dossier employé ne propose
que les fonctions du département choisi — « Gardien » n'apparaît pas au Laboratoire —, groupées « Fonctions de
… » puis « Proposées dans tous les départements ». Changer de département retire une fonction qui n'y existe pas
et le dit.

```text
aucun lien          la fonction reste proposée partout : aucune ne disparaît avant d'avoir été réglée
couple déjà posé    un dossier garde son couple département / fonction, même incohérent, tant qu'on
                    ne change ni l'un ni l'autre (corriger un téléphone ne bloque pas)
serveur             JobTitleDepartmentGuard refuse un couple incohérent, à la saisie comme à l'import
                    Excel — l'écran n'est jamais la seule garde
```

La correspondance se règle dans le **module Fonctions** (ADR-188) : chaque fonction porte ses départements en
pastilles (`JobTitleDepartmentsPicker`), et un département affiche ses fonctions. Omettre `department_uuids`
laisse les liens tels quels ; une liste vide les retire. Un département archivé n'est plus proposé mais reste
sur la fonction qui le porte. Chaque changement est audité à part (`hr_reference.departments.update`, ancienne
et nouvelle liste). La correspondance livrée (`DefaultJobTitleDepartments`, lue sur le CDC §9) n'est qu'une
**proposition** : elle ne relie qu'une fonction encore sans département et n'écrase jamais un réglage de la
clinique. Mêmes droits que le module : `hr_settings.*`.

## Photo d'identité 4 × 4

Le dossier employé a une photo, réglable à chaque étape depuis le bandeau d'identité (glisser-déposer, recadrage
carré dans le navigateur). Le serveur ne se fie pas au recadrage : `EmployeePhotoStore` relit l'image, la recoupe
au carré central, la ramène à 600 px et la réencode en JPEG, ce qui retire les métadonnées (GPS, appareil). Elle
vit sur le disque **privé** et se lit par `GET /administration/employees/{uuid}/photo` (capacité
`view-employee-photo` : qui peut voir un employé, son planning, ses contrats, présences ou congés). JPEG, PNG ou
WebP, 5 Mo, 120 px au moins. Remplacer ou retirer supprime l'ancien fichier ; une création échouée n'en laisse
aucun. La photo paraît dans la liste, la fiche, le planning, les stages et la fiche imprimée (cadre réel 4 × 4 cm,
vide pour la coller à la main). Sur le portail, son adresse est relayée par l'API du site comme toute pièce RH.

## Stagiaires : un employé, un contrat de stage

Un stagiaire est un **dossier employé** (identité, photo, service d'accueil) et un **contrat** dont le type est
marqué « Contrat de stage » dans Paramètres RH (`metadata.internship`, lu sur le type, jamais sur son libellé).
Un tel contrat demande sa **filière** (référentiel `INTERNSHIP_FIELD` : Infirmier, Sage-femme, Laboratoire…,
administrable), et garde école, niveau et encadrant (un employé, jamais le stagiaire lui-même).

```text
Stages               /administration/internships (contracts.view) : en cours, à venir, terminés,
                     tous ; filtre par filière ; recherche ; photo du stagiaire et de l'encadrant
Nouveau stagiaire    le dossier d'abord (employees/create?stagiaire=1), puis le contrat de stage
                     s'ouvre, type déjà choisi — exige contracts.create
annuaire             un repère « Stagiaire » sur qui a un stage en cours
```

Un contrat importé sans filière s'affiche « filière à compléter ». La prise en charge Personnel (ADR-052) n'est
pas modifiée : être stagiaire ne donne aucun droit de couverture.

## Deux plannings, un calendrier

Un créneau est du **service** (planning du personnel) ou une **garde** (`planning_shifts.kind`, `SHIFT` par
défaut). Jour, nuit ou 24 h se lisent sur ses heures, ce n'est pas une catégorie. La page Planning a deux
onglets — « Planning du personnel », « Planning de garde » — et trois vues : **semaine** (une ligne par
personne, une colonne par jour), **mois** et **liste** ; une case vide ouvre la création sur ce jour et ce type.
L'impression et l'export suivent le type choisi. Aucun chevauchement n'est interdit (ADR-066).

## Portail et droits

Tous ces écrans passent par `hrUrl()` et sont servis au portail (ADR-187). Aucune permission nouvelle :
`employees.*`, `contracts.*`, `planning.*`, `hr_settings.*`. Migrations `2026_10_29_090000` à `093000`, à
jouer sur chaque site et sur le portail.

## Signalé, non tranché

```text
fonctions « partout »      une fonction sans département reste proposée partout : c'est voulu
                           pour la transition, à resserrer quand la clinique aura tout réglé
filière à l'import         l'import crée un contrat de stage sans filière (« à compléter »)
gardes et disponibilité    la disponibilité d'un chirurgien au bloc (ADR-168) lit tous les créneaux,
                           service et garde confondus
```

---

# ADR-195 — Messagerie : la boîte professionnelle de son titulaire, lue en direct

**Status:** ACCEPTED (2026-09-25 — demande du propriétaire : « la fonctionnalité Email/Inbox du modèle
DashWind, complète, avec UI et UX », quatre arbitrages explicites)
; **amendé le même jour** : la messagerie dépend des permissions (`webmail.view`, `webmail.open_any`) et
le Super Admin ouvre toute boîte depuis le portail, puis une boîte qui répond en une ou deux secondes au lieu
d'une minute — voir les amendements en fin d'ADR

**Complète l'ADR-190** (adresses email professionnelles) : les adresses existaient, on ne pouvait pas s'en
servir depuis RIVO. Le CDC ne décrit aucune messagerie : les règles ci-dessous sont celles du propriétaire.

## Les arbitrages

```text
quoi            un vrai webmail des adresses pro (ADR-190), en IMAP/SMTP chez l'hébergeur (o2switch)
mot de passe    demandé à l'ouverture de la boîte, gardé chiffré dans la session seulement, jamais en base
qui             le titulaire, sur son site : compte ↔ fiche employé (ADR-188) ↔ adresse ACTIVE ;
                personne d'autre, Super Admin compris
copie locale    aucune : les messages sont lus en direct, rien n'est recopié dans RIVO
```

## Qui ouvre une boîte

`WebmailAccess` : le compte relié à une fiche employé qui a une adresse professionnelle **active**. Aucune
permission ne l'ouvre, et c'est voulu : un droit s'accorde, un lien compte ↔ fiche se prouve. Une adresse
suspendue (ADR-190) ferme la messagerie. Seulement sur un site clinique : les comptes du portail ne sont pas
des employés. Sans boîte, la page « Aucune boîte à ouvrir » dit pourquoi et à qui s'adresser (RH) — jamais un
simple 403 muet. Le menu « Messagerie » n'apparaît qu'au titulaire (prop partagée `webmail.available`).

## Le mot de passe

C'est celui de l'adresse, remis une fois à sa création (ADR-190) — pas celui de RIVO. `WebmailSessionController`
le fait vérifier par le serveur de messagerie lui-même, puis `WebmailSession` le garde **chiffré** dans la session
(`Crypt`, lié à l'UUID et à l'adresse de la boîte). Il n'est jamais en base, jamais dans un journal ni dans
l'audit (`#[\SensitiveParameter]`, exceptions relancées sans la précédente, journaux sans pile d'appels).
Il disparaît à « Fermer ma boîte » et à la déconnexion de RIVO. Un mot de passe changé chez l'hébergeur est
oublié et redemandé. Audit : `webmail.connect`, `webmail.connect_failed`, `webmail.disconnect`.

## Rien n'est copié

`WebmailMailbox` lit et écrit en direct sur le serveur (`ImapMailServer`, webklex/php-imap — PHP 8.4 n'a pas
l'extension imap ; `EsmtpTransport` de Symfony pour l'envoi). Les messages peuvent porter des données de santé :
les garder en double dans RIVO en ferait une seconde source à protéger, à purger et à auditer. Seuls deux
réglages **du compte** sont en base : ses libellés (`webmail_labels`) et ses modèles de message
(`webmail_templates`).

## Ce que fait l'écran

```text
dossiers       Réception, Brouillons, Favoris, Envoyés, Archives, Indésirables, Corbeille, puis les dossiers
               créés ailleurs ; noms français, compteurs de non-lus. Rôle lu sur SPECIAL-USE, sinon sur le nom ;
               un dossier manquant (Archives…) est créé à côté des autres (« INBOX. » chez cPanel)
Favoris        les messages étoilés de Réception, Archives et Envoyés (200 au plus par dossier) — pas un
               dossier du serveur. « Tous les messages » n'est pas proposé : IMAP ne le fournit pas
liste          recherche sur le serveur, filtres Non lus / Favoris / libellé, pagination, sélection multiple ;
               pas d'extrait du corps : le lire marquerait lu et coûterait une lecture par ligne
actions        lu / non lu, favori, archiver, indésirable, remettre en réception, déplacer, libeller,
               corbeille ; « supprimer définitivement » seulement depuis la corbeille et les indésirables,
               après confirmation — audité (`webmail.delete`)
lecture        destinataires détaillés, pièces jointes téléchargées (jamais affichées), message suivant /
               précédent, impression du seul message, « Afficher les images »
rédaction      nouveau, répondre, répondre à tous, transférer (avec les pièces d'origine), brouillon repris ;
               éditeur riche, destinataires en pastilles avec les collègues proposés, Cc / Cci, pièces
               jointes (10 Mo par fichier, 20 Mo au total), modèles ; fermer un message commencé demande
               confirmation, Ctrl+Entrée envoie
libellés       mots-clés IMAP du serveur (`rivo…`), propres au compte, 6 couleurs, 30 au plus
modèles        propres au compte, 50 au plus, corps nettoyé comme un message
collègues      les autres adresses actives du site : nom, fonction, adresse — rien d'autre
```

Une action qui fait sortir le message lu de son dossier ramène à la liste : `return_to`, limité aux chemins
`/messagerie/…` (`WebmailReturn`), jamais une adresse extérieure.

## Un message reçu est rendu sûr, deux fois

`EmailHtmlSanitizer::forDisplay` retire scripts, cadres, formulaires, attributs `on…`, adresses `javascript:`,
styles dangereux ; remplace les images `cid:` par l'image jointe ; **bloque les images distantes** (pistage)
jusqu'à « Afficher les images ». Le corps est ensuite affiché dans un `iframe` isolé (`sandbox` sans
`allow-scripts`, politique de contenu qui n'autorise que les images, aucun référent). Une pièce jointe est
toujours téléchargée (`application/octet-stream`, `nosniff`, `sandbox`), jamais rendue. Ce qu'on écrit ne garde
qu'un jeu fermé de balises (`forSending`), exactement celles que l'éditeur propose.

## L'envoi

Il part de l'adresse du titulaire, authentifié avec son mot de passe, par le serveur d'envoi de l'hébergeur
(465, TLS dès la connexion, chez o2switch — voir l'amendement sur la rapidité). Le serveur relit les destinataires (50 au plus, noms gardés), le corps et les
pièces jointes ; le navigateur n'est jamais cru. La copie va dans Envoyés **avec** la copie cachée, que le
message parti ne porte pas. Une réponse porte `In-Reply-To` / `References` et marque l'original « répondu ».
Un brouillon envoyé est retiré des Brouillons. Un envoi refusé ne met rien dans Envoyés et garde le formulaire.
Audit `webmail.send` : expéditeur, destinataires, objet, nombre de pièces jointes — **jamais le corps**.

## Trois défauts de la bibliothèque, trouvés contre un vrai serveur

Vérifiés contre GreenMail (test d'intégration `ImapMailServerIntegrationTest`, lancé seulement si
`RIVO_WEBMAIL_TEST_SERVER` est défini, jamais contre une boîte réelle) :

```text
mot de passe refusé    un « NO » du serveur au LOGIN arrivait comme une panne : « le serveur ne répond
                       pas » pour qui s'était trompé → `isRefusal()` le reconnaît
objet accentué         sans l'extension imap, le décodeur laissait « =?utf-8?Q?R=C3=A9sultats?= » →
                       décodage des en-têtes par iconv
recherche accentuée    la bibliothèque refuse CHARSET → la recherche est écrite à la main, le texte
                       accentué envoyé en littéral IMAP avec `CHARSET UTF-8`
```

## Configuration

`RIVO_WEBMAIL_IMAP_HOST/PORT/ENCRYPTION`, `RIVO_WEBMAIL_SMTP_HOST/PORT/ENCRYPTION` dans le `.env` de chaque
site (aucun secret : l'hôte seulement) ; vides, l'hôte de `RIVO_MAIL_HOSTING_URL`. Non configurée, la messagerie
le dit au lieu d'une erreur. Migration `2026_11_04_090000_create_webmail_labels_and_templates` (sites et
portail). Aucune permission nouvelle.

## Hors périmètre, signalé

```text
Super Admin            (remplacé par l'amendement ci-dessous) ; boîte partagée (secretariat@) : à décider
recherche sans accent  « Resultats » ne trouve pas « Résultats » : c'est le serveur qui cherche
espace utilisé         affiché si l'hébergeur le donne (QUOTA IMAP), sinon dit
notifications          aucun compteur de non-lus hors de la messagerie : il faudrait se connecter au
                       serveur à chaque page
```

## Amendement du 2026-09-25 — la messagerie dépend des permissions, le Super Admin ouvre toute boîte

Constat du propriétaire : « je ne vois rien de ce qui a été fait ». Rien n'était cassé : sur son site, le
seul compte (`user@rivo.test`) n'était relié à aucune fiche employé, donc l'entrée « Messagerie » restait
masquée pour tout le monde ; et le portail n'en avait aucune. Arbitrage du propriétaire : **le Super Admin
a tous les droits**, et **la messagerie dépend des permissions**. Divergence signalée avec la première
version de cet ADR (« personne d'autre, Super Admin compris ; aucun droit ne l'ouvre »), qui est remplacée.

```text
webmail.view      la messagerie apparaît au menu ; le compte ouvre SA boîte (l'adresse active de la
                  fiche employé reliée, ADR-188/190). Socle : ADMINISTRATION, LOGISTICS, RECEPTION,
                  MEDICINE, NURSE, SURGERY, PHARMACY, LABORATORY — pas SUPPORT ni MAINTENANCE (ADR-033)
webmail.open_any  ouvrir la boîte d'un autre employé : sur un site, celles du site ; depuis le portail,
                  celles de chaque site, listées par son API (jamais sa base, ADR-004). Accordée à aucun
                  rôle d'un site ; le Super Admin la reçoit comme toutes les permissions (ADR-186)
```

**Le mot de passe de la boîte reste exigé**, pour tous : c'est le serveur de messagerie qui le demande, et
RIVO ne le connaît pas (ADR-190). Le Super Admin l'a reçu à la création ou le renouvelle depuis « Emails
professionnels » — le titulaire devra alors utiliser le nouveau.

```text
choix          /messagerie/connexion liste « Ma boîte » et, avec open_any, les adresses ACTIVES (par
               site sur le portail), cherchables ; le serveur relit la boîte choisie à l'instant,
               jamais celle que le navigateur décrit
session        la boîte choisie (identité, site, titulaire) et son mot de passe, chiffrés ensemble ;
               chaque requête revérifie le droit — et, sur un site, que l'adresse est encore active.
               Sur le portail, c'est le serveur de messagerie qui refuse une adresse suspendue
à l'écran      « Boîte d'un employé · site » en permanence dans la colonne de gauche, et
               « Ouvrir une autre boîte » pour qui a open_any
audit          webmail.connect / connect_failed / disconnect / send portent titular, site et
               own_mailbox : ouvrir ou écrire depuis la boîte d'un autre se lit dans l'audit, au nom
               de qui l'a fait. Sur le portail, l'entrée est dans l'audit du portail
sans boîte     la page dit pourquoi : droit manquant (nommé, ADR-154), compte non relié à une fiche,
               fiche sans adresse, adresse demandée ou suspendue — jamais un refus muet
```

Le menu du portail porte « Messagerie » (Organisation, gardé par `webmail.open_any`) ; celui d'un site
l'affiche dès que le compte a `webmail.view` ou `webmail.open_any`. `WebmailBox` remplace le modèle
`ProfessionalMailbox` dans la messagerie : sur le portail, l'adresse vit sur un site. Migration
`2026_11_06_090000_create_webmail_permissions` (sites et portail).

**Signalé.** Envoyer depuis la boîte d'un employé, c'est écrire à sa place : c'est permis avec
`open_any`, et tracé. Lire la boîte d'un employé à son insu n'est dit qu'à celui qui l'ouvre, pas au
titulaire : une notification au titulaire reste à décider. L'audit d'une ouverture faite depuis le portail
n'est pas recopié dans l'audit du site.

## Amendement du 2026-09-25 — une boîte qui répond en une ou deux secondes

Constat du propriétaire : ouvrir un dossier prenait près d'une minute, un message 21 s, un envoi 10 s. Rien
n'était cassé : le serveur est à ~260 ms de Madagascar (o2switch), et la bibliothèque multipliait les
allers-retours — un NOOP de contrôle avant chaque commande, un aller-retour par dossier pour ses compteurs, et
une connexion (~1,1 s : chiffrement et identification) même pour une requête qui ne lisait rien.

```text
aucun NOOP           LeanImapClient : la connexion vit le temps d'une requête HTTP ; une coupure se lit à la
                     commande suivante, comme une panne
connexion au besoin  LazyMailServer : la boîte s'ouvre au premier usage ; un rechargement partiel, un libellé
                     renommé ne la paient plus. Le mot de passe reste vérifié à l'ouverture de la boîte
ensemble             les commandes indépendantes partent d'un seul envoi (pipelining IMAP) : les compteurs de
                     tous les dossiers, marquer puis déplacer ou effacer ; un message ajouté part avec sa
                     commande (LITERAL+) ; un seul FETCH par page de liste ou par message
gardé un moment      arborescence des dossiers (2 min, effacée dès qu'un dossier est créé), espace utilisé
                     (10 min), capacités du serveur (1 jour) ; sur le portail, la liste des boîtes des sites
                     (5 min, pour les contacts — relue à l'instant pour ouvrir une boîte)
fin de requête       LOGOUT sans attendre la réponse ; le QUIT du SMTP après que l'écran a reçu la sienne
envoi                465, TLS dès la connexion, au lieu de 587 STARTTLS (deux allers-retours de moins) ;
                     authentification PLAIN avant LOGIN (un aller-retour contre trois)
écran                après une action, un envoi ou un libellé, seules les parties touchées sont rechargées
```

**Rien n'est copié pour autant** : le cache ne garde que des noms de dossiers et des nombres, sous une clé
formée de l'hôte et de l'adresse — jamais un en-tête, un corps, ni le mot de passe. La règle « Rien n'est
copié » est inchangée.

Mesuré depuis Madagascar sur o2switch : dossier 1,5 s (au lieu de 56 s), message 1,7 s (21 s), envoi 2,7 s
(10 s). Le test d'intégration contre GreenMail (`ImapMailServerIntegrationTest`) exerce le client allégé.
`RIVO_WEBMAIL_SMTP_PORT` / `_ENCRYPTION` gardent 587 STARTTLS possible pour un hébergeur qui n'ouvrirait
pas 465. Aucune permission, aucune migration.

## Amendement du 2026-09-26 — rien n'attend plus : un clic répond tout de suite, l'écran d'ouverture en pleine largeur

Demande du propriétaire : « l'application messagerie doit être rapide, rien de lenteur », et l'écran « Ouvrir
une boîte » en pleine largeur (shadcn). Sa remarque « on utilise Vue.js + TypeScript » : le projet est en Vue 3
et JavaScript, et TypeScript ne change rien à la vitesse — ce qui fait attendre, ce sont les allers-retours
vers le serveur de mail, et un écran qui disparaissait derrière un squelette pleine page à chaque clic.

**Côté serveur : les lectures annoncées partent avec les compteurs.** Chaque page demandait les compteurs des
dossiers, puis sélection + recherche, puis la lecture, chacun attendant le précédent. La page annonce
désormais ce qu'elle lira (`MailServer::plan()`, posé par `WebmailMailbox::expectListing()` /
`expectMessage()` depuis le contrôleur, seulement si la page demande la liste ou le message) :

```text
liste     [compteurs + SELECT + SEARCH] puis FETCH        un aller-retour de moins
message   [compteurs + SELECT + SEARCH + FETCH + STORE \Seen]   un seul, au lieu de quatre
```

Le marquage « lu » part après la lecture : les drapeaux reçus sont ceux d'avant, et le compteur de non-lus se
corrige comme avant. Un brouillon n'est pas marqué lu. Une réponse refusée n'est pas gardée : la méthode qui en
a besoin la redemande et dit l'erreur comme avant. Ce qui est reçu d'avance vaut pour la seule requête en cours
et est oublié à toute modification (marquer, déplacer, supprimer, déposer). Rien n'est copié hors de la requête.

**Côté écran : la page reste en place.** Les liens de la messagerie gardent la page (`preserveState`) et ne
redemandent pas ce qui ne change pas d'un clic à l'autre (boîte, libellés, modèles, collègues, limites, espace —
`WEBMAIL_STATIC_PROPS`). Plus de squelette pleine page (ADR-185) : la colonne des dossiers ne disparaît jamais.

```text
ouvrir un message   son en-tête s'affiche aussitôt, repris de la ligne cliquée ; le corps en squelette
autre dossier       il s'allume aussitôt, sa liste en squelette
même dossier        page, filtre, recherche : la liste reste, atténuée, sous une barre de chargement
retour à la liste   par l'historique du navigateur quand le message a été ouvert depuis elle : la liste
                    réapparaît instantanément, le message s'y montre lu, puis elle est relue en arrière-plan ;
                    les flèches « précédent / suivant » remplacent le message dans l'historique
survol              un dossier ou une page voisine se prépare au survol (préchargement Inertia, 30 s,
                    étiqueté `webmail`), oublié à chaque action, envoi, actualisation ou ouverture de message.
                    Jamais un message : l'ouvrir le marque lu
envoyer             la fenêtre se ferme aussitôt, le message part en arrière-plan (requête JSON), un avis dit
                    « Message envoyé. ». Refusé — adresse, serveur, boîte refermée —, il revient dans la
                    fenêtre, intact, avec la raison ; pendant l'envoi, on n'en commence pas un autre
```

L'envoi d'arrière-plan reçoit du JSON (`WebmailComposeController`, `OpenWebmailMailbox`) : 200 `status`,
422 erreurs, 409 `reconnect` quand la boîte s'est refermée ou que son mot de passe a changé — jamais une page de
connexion prise pour un succès. L'audit `webmail.send` est inchangé.

Mesuré sur un banc local (GreenMail derrière un relais à ~260 ms d'aller-retour, sans chiffrement — les vrais
chiffres chez o2switch sont un peu plus hauts) :

| Geste | Avant | Après |
|---|---|---|
| Ouvrir la boîte → Réception | 3,3 s | 2,8 s |
| Ouvrir un message | 2,3 s sans rien à l'écran | en-tête 0,08 s, contenu 1,35 s |
| Retour à la liste | 2,4 s | 0,06 s |
| Page suivante | 2,0 s | retour 0,08 s, liste 1,4 s |
| Changer de dossier (survolé) | 1,8 s | 1,0 s |
| Envoyer | 3,6 s bloqué | fenêtre fermée 0,3 s, envoi confirmé en arrière-plan |

**L'écran d'ouverture en pleine largeur** (`Webmail/Connect.vue`) : à gauche les boîtes en grille, par site,
cherchables, filtrables par site sur le portail, la sienne en tête ; à droite, fixe, la boîte choisie, son mot
de passe et ce qu'il faut savoir. Choisir une boîte place le curseur dans le mot de passe ; les flèches passent
d'une boîte à l'autre. Sur le portail, la liste des boîtes des sites est lue dans le cache de 5 minutes
(« Actualiser » la relit) ; l'ouverture revérifie toujours la boîte à l'instant (`findOther`).

**Les avis tiennent dans un bouton « ! »** (demande du propriétaire, même jour). Sur cet écran comme dans
« Emails professionnels », plus aucun bandeau : l'avertissement d'audit d'une boîte d'employé, les sites non
listés et les informations (mot de passe, aucune copie, mot de passe oublié) s'ouvrent au clic sur une icône
de l'en-tête (`Shadcn/NoticesButton`). Sa pastille compte les avis et passe à l'ambre dès qu'un avertissement
s'y trouve. Le badge « Boîte d'un employé » reste visible sur la boîte choisie, et l'est toujours en permanence
dans la messagerie ouverte.

**Signalé, non tranché.** Le plancher restant est la connexion elle-même (chiffrement + identification,
~1,1 s depuis Madagascar), payée à chaque clic parce que PHP ne garde rien d'une requête à l'autre. La
supprimer demanderait un service qui garde les connexions ouvertes entre les requêtes (une décision
d'architecture), ou d'héberger RIVO près du serveur de mail. En local, `php artisan serve` n'a qu'un processus :
les préchargements s'y font la queue ; `PHP_CLI_SERVER_WORKERS=4` l'évite (PHP-FPM en production n'est pas
concerné). Aucune permission, aucune migration.


## Amendement du 2026-09-26 (bis) — la boîte de réception se lit d'un coup d'œil, et « Actualiser » se voit

Deux demandes du propriétaire sur `/messagerie/dossier/reception` : le bandeau ambre « Boîte d'un employé ·
Ambondromamy. Ouvertures et envois sont audités à votre nom. » prenait une ligne entière, et les filtres « Tous /
Non lus / Favoris » ne disaient pas combien de messages ils ouvrent. Puis, sur la même page : cliquer « Actualiser »
ne faisait rien bouger.

**Les filtres portent leur compteur**, en pastille rouge, cachée à zéro. Les chiffres viennent du serveur :

```text
Tous      messages du dossier                        STATUS, déjà dans le lot des compteurs
Non lus   non lus du dossier                         STATUS
Favoris   messages étoilés du dossier                UID SEARCH FLAGGED, ajouté au même envoi que la liste
```

`WebmailMailbox::listing()` renvoie `counts {all, unseen, flagged}` ; la recherche des favoris part avec le lot de la
liste (pipelining), sans aller-retour de plus. Pour le dossier « Favoris » réuni et pour une liste en erreur, `counts`
vaut `null` : rien n'est inventé. Marquer lu, étoiler, archiver font bouger les compteurs tout de suite
(`applyActionLocally`, `countsDelta`) ; la réponse du serveur les confirme. Dans la colonne des dossiers, les non-lus
sont eux aussi en rouge ; les brouillons restent neutres (un brouillon n'attend pas d'être lu).

**Le bandeau devient une pastille.** « Boîte d'un employé · site », ambre, avec son œil, reste visible en permanence
dans la carte de la boîte — ouvrir la boîte d'un autre est audité à votre nom, cela doit se voir sans cliquer — et
s'ouvre sur l'explication complète (« Vous lisez et écrivez à la place de … Chaque ouverture et chaque envoi sont
enregistrés dans l'audit à votre nom »). « Ouvrir une autre boîte » et « Fermer ma boîte » deviennent deux icônes de
cette carte. L'en-tête de la liste porte l'icône du dossier ; l'état vide dit ce qui est vide (dossier, filtre,
recherche, libellé) et propose « Afficher tout le dossier » quand un filtre est posé. Sur téléphone, les filtres
perdent leur icône et ne se coupent plus : la case, les trois filtres et « Actualiser » tiennent sur une ligne à
390 px, sans défilement horizontal.

**« Actualiser » tourne, et on le voit.** L'icône de la barre tournait sur `processing` — l'état des actions
groupées, qu'un rechargement ne pose jamais — et celle de l'état vide ne tournait pas du tout. Même quand
l'animation existait ailleurs, une réponse en 80 ms ne laissait voir qu'un tressaillement. Une icône partagée,
`Shadcn/RefreshIcon`, porte désormais la règle une seule fois :

```text
au moins un tour     une réponse rapide montre quand même la rotation : le clic a un effet visible
fin de tour          l'icône s'arrête à 0°, lue sur l'animation (animationiteration), jamais par une horloge
                     qui la couperait quelques degrés trop tôt
filet                une minuterie la libère si aucun tour ne se termine (préférence « animations
                     réduites », ADR-191, qui coupe la rotation)
```

Un tour dure 700 ms, écrit une seule fois (`@keyframes rivo-refresh-turn`). Le bouton reste désactivé pendant la
relecture et porte `aria-busy`. La même icône sert à tous les boutons d'actualisation de l'application : liste et
état vide de la messagerie, « Ouvrir une boîte » du portail, messagerie hors ligne, points d'attention de l'en-tête,
RH du portail (« Actualiser », « Réessayer »), page de maintenance et « Réessayer l'enregistrement » des emails
professionnels. Un test interdit qu'une icône d'actualisation tourne encore hors de ce composant.

Mesuré dans un navigateur : l'icône passe de 0° à 351° puis s'arrête pile à 0° vers 750 ms, sur la barre comme sur
l'état vide. Aucune permission, aucune migration.
