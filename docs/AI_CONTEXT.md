# RIVO — AI CONTEXT

## Project

RIVO est l'application de gestion de la Clinique Saint Georges.

Sites :

```text
Mampikony
Ambondromamy
```

CDC officiel :

```text
https://github.com/GasyCoder/cdc-clinic-george
```

---

# Stack

```text
Laravel 13
Vue.js
Inertia.js
Tailwind CSS
DashWind
MySQL / MariaDB
REST API
Laravel Queue / Jobs
```

---

# Architecture générale

Les deux établissements sont indépendants.

```text
clinique-m.rivo.mg
        │
        ▼
Laravel
        │
        ▼
DB_MAMPIKONY
```

```text
clinique-a.rivo.mg
        │
        ▼
Laravel
        │
        ▼
DB_AMBONDROMAMY
```

Une seule codebase.

Deux déploiements.

Deux bases indépendantes.

Aucun accès SQL direct entre les bases.

---

# API

Tous les échanges inter-sites passent par API REST.

Exemples :

```text
patient transfer
stock transfer
patient lookup
medical data exchange
remote status
Super Admin
reports
statistics
```

Les entités distribuées utilisent des UUID.

Pour les opérations critiques prévoir :

```text
UUID
request UUID
idempotency
queue
retry
backoff
timeout
audit
```

---

# Super Admin

Domaine :

```text
https://admin.rivo.mg
```

Architecture :

```text
admin.rivo.mg
      │
      ├── API Mampikony
      │       └── DB_MAMPIKONY
      │
      └── API Ambondromamy
              └── DB_AMBONDROMAMY
```

Le Super Admin n'accède jamais directement aux bases de données locales.

---

# Rôles principaux

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

Les rôles représentent des domaines principaux.

Les permissions déterminent réellement les actions autorisées.

Les utilisateurs sont locaux à chaque base/site. Un compte actif doit posséder
un rôle valide. Les comptes ne sont jamais supprimés physiquement : ils sont
désactivés avec motif, auteur et audit, puis leurs sessions sont révoquées.
Les seeders ne doivent créer aucun compte ou mot de passe de démonstration.
Voir ADR-022.

---

# Permissions

Les permissions sont dynamiques.

Convention :

```text
resource.action
```

ou si nécessaire :

```text
module.resource.action
```

Exemples :

```text
patients.view
patients.create
patients.update
patients.delete
patients.restore

laboratory.results.validate

pharmacy.stock.transfer

billing.invoices.validate
```

Actions possibles :

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

---

# Soft Delete

Règle :

```text
delete = Soft Delete
```

Prévoir selon les modèles :

```text
deleted_at
deleted_by
delete_reason
```

`force_delete` est exceptionnel.

Les données critiques doivent généralement utiliser :

```text
cancel
correct
reverse
archive
```

---

# Audit

Les opérations sensibles sont journalisées.

Exemples :

```text
create
update
delete
restore
validate
approve
reject
cancel
payment
refund
stock adjustment
stock transfer
laboratory validation
role assignment
permission assignment
API transaction
```

---

# Règle financière critique

Chaque site possède UNE seule caisse fonctionnelle.

```text
Réception / Caisse
```

est l'unique autorité d'encaissement.

Tous les paiements doivent être effectués dans ce module.

---

# Pharmacie

La Pharmacie gère :

```text
prescriptions
medicines
dispensing
stocks
lots
expiration
inventory
returns
stock transfers
```

La Pharmacie ne gère jamais :

```text
cash
payment
collection
financial refund
cash closing
payment receipt
```

---

# Laboratoire

Le Laboratoire gère :

```text
analysis requests
samples
analysis
results
result validation
printing
reports
```

Le Laboratoire peut consulter un statut financier lorsque nécessaire.

Il ne peut jamais créer ou encaisser un paiement.

---

# Médecine

La Médecine gère notamment :

```text
medical record
consultations
diagnoses
prescriptions
care orders
laboratory requests
surgery requests
medical decisions
medical discharge
```

La Médecine ne peut pas encaisser.

---

# Chirurgie

La Chirurgie gère notamment :

```text
surgical request
planning
pre-operative
intervention
anesthesia
consumables
surgical report
post-operative
medical discharge
```

La Chirurgie ne peut pas encaisser.

---

# Réception / Caisse

Le module gère notamment :

```text
patient administrative identity
episodes
reception
orientation
appointments
visitor entries and departures
up to four private professional visitor attachments (JPEG, PNG, WebP or PDF)

billable items
invoices
payments
receipts
debts
refunds
discounts
cash opening
cash closing
financial reports
```

---

# UI

Template :

```text
DashWind
```

Architecture :

```text
Laravel
   │
   ▼
Inertia
   │
   ▼
Vue.js
   │
   ▼
DashWind
   │
   ▼
Tailwind CSS
```

DashWind constitue la base visuelle.

Ne pas introduire un autre design system sans validation.

---

# Backend architecture

Préférer :

```text
app/
├── Actions/
├── DTOs/
├── Enums/
├── Events/
├── Jobs/
├── Models/
├── Policies/
├── Services/
└── Http/
    ├── Controllers/
    ├── Middleware/
    ├── Requests/
    └── Resources/
```

Les Controllers doivent rester légers.

La logique métier doit être côté Laravel.

Ne jamais mettre une règle métier critique uniquement dans Vue.

---

# Source officielle

Toujours consulter :

```text
https://github.com/GasyCoder/cdc-clinic-george
```

Avant toute implémentation importante.

Lire également :

```text
docs/CDC_REFERENCE.md
docs/DECISIONS.md
docs/ROADMAP.md
```
