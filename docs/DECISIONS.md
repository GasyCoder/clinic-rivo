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
