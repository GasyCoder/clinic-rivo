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

**Status:** ACCEPTED (2026-08-22 — réunion client du 22/08/2026)

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

**Status:** ACCEPTED (2026-08-23 — exigence explicite du client)

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

**Status:** ACCEPTED (2026-08-28 — exigence explicite du propriétaire)

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

**Status:** ACCEPTED (2026-09-01 — exigence explicite du propriétaire)

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

Pour vider une base déjà remplie, `php artisan rivo:pharmacy-reset` efface le
domaine entier — fournisseurs, catalogues et leurs fichiers, prix, commandes,
réceptions, factures fournisseur, stock, lots, mouvements, médicaments et
familles — ainsi que ce que la Pharmacie a produit ailleurs : délivrances,
lignes d'ordonnance citant un médicament, prestations facturées de
médicaments. Une facture **déjà encaissée** est conservée avec ses paiements
et signalée : supprimer un encaissement réel n'est pas un ménage de données
(ADR-010, ADR-012). La commande refuse hors `local`/`testing`.

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
touche jamais un prix de vente. Interdire réellement la création tant qu'une
réception n'existe pas exigerait de commander autrement qu'en désignant un
médicament : à trancher avec le propriétaire avant toute implémentation.

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
