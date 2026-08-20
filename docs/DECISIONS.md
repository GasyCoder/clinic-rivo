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

**Status:** ACCEPTED (amendé 2026-08-19 — ajout de NURSE)

Les rôles principaux sont :

```text
SUPER_ADMIN
ADMINISTRATION
RECEPTION
MEDICINE
NURSE
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
"Soins" du CDC §15 (`care.*`, `vitals.*`) et catalogue "Anesthésie" du CDC
§16 (`anesthesia.*`, normalement rattaché à SURGERY mais explicitement
demandé ici aussi). Il n'existe aucun catalogue de permissions "Maternité"
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

**Status:** ACCEPTED (2026-08-20 — exigence explicite de l’équipe)

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
