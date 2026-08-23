# RIVO — AI CONTEXT

## Project

RIVO est l'application de gestion de la Clinique Saint Georges.

Sites opérationnels :

```text
Mampikony
Ambondromamy
Boriziny
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

Les trois établissements sont indépendants.

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

```text
clinique-b.rivo.mg
        │
        ▼
Laravel
        │
        ▼
DB_BORIZINY
```

Une seule codebase.

Trois déploiements.

Trois bases indépendantes.

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
      ├── API Ambondromamy
              └── DB_AMBONDROMAMY
      └── API Boriziny
              └── DB_BORIZINY
```

Le Super Admin n'accède jamais directement aux bases de données locales.

## Référentiels, tarifs et stocks

Les prestations, produits stockables et équipements durables sont des domaines
distincts :

```text
prestations : consultation, ECG, échographie, analyse, acte
stocks       : médicaments, consommables médicaux, fournitures
équipements  : actifs identifiés, affectés et maintenus individuellement
```

Le référentiel utilise des UUID distribués. Les tarifs sont historisés et propres
à chaque site ; une modification ne change jamais une facturation antérieure.
Le paramétrage du référentiel et des tarifs est attribué par défaut uniquement au
`SUPER_ADMIN`, via permissions granulaires `catalog.items.*` et
`catalog.tariffs.*`. Les modules opérationnels utilisent le catalogue sans
modifier les tarifs.

Pharmacie réalise les mouvements de médicaments et consommables autorisés.
Administration réalise le stock administratif et le suivi des équipements.
Réception sélectionne les prestations et demeure l’unique module d’encaissement.
Elle ne saisit pas de prix libre : le backend résout le tarif actif et conserve
le montant historique sur la prestation facturable et la facture.

Depuis `admin.rivo.mg`, une action multi-site appelle séparément les APIs de
Mampikony, Ambondromamy et Boriziny avec UUID, idempotence, audit et reprise sur
échec partiel. Aucun accès SQL inter-site n’est autorisé. Voir ADR-024.

Le portail Super Administration possède une navigation distincte des sites
opérationnels. Il présente le tableau de bord consolidé, chaque site et ses
modules, les rapports financiers, les espaces Administration, utilisateurs,
rôles/permissions, paramètres et audit. Son accès exige
`super_admin.portal.view`. Voir ADR-025.

Un compte `SUPER_ADMIN` est exclusivement central et ne peut jamais se connecter
directement à un site. Une personne qui exerce aussi une fonction opérationnelle
doit posséder un compte local distinct avec le rôle métier correspondant. Un
compte opérationnel ne peut réciproquement pas se connecter au portail central,
même avec une permission ajoutée par erreur. Voir ADR-027.

Les responsabilités administratives sont séparées : `ADMINISTRATION` couvre les
RH, contrats, présences, congés, planning et rapports RH ; `LOGISTICS` couvre
l’inventaire et le suivi des équipements ainsi que le stock administratif.
`SUPPORT` classe notamment les profils Gardien et Agent d'entretien ;
`MAINTENANCE` classe notamment le profil Technicien informatique. Leurs tâches
spécifiques sont affectées au compte, jamais globalement au rôle. Aucun de ces
rôles ne gère les utilisateurs, rôles ou permissions par défaut. Voir ADR-033.

---

# Rôles principaux

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

Les rôles représentent des domaines principaux.

Les permissions déterminent réellement les actions autorisées.

Pour `NURSE`, `SUPPORT` et `MAINTENANCE`, un profil professionnel qualifie le
métier principal sans donner directement de permission. Les recommandations du
profil sont copiées explicitement comme permissions individuelles, modifiables
compte par compte. Voir ADR-033.

Les utilisateurs sont locaux à chaque base/site. Un compte actif doit posséder
un rôle valide. Les comptes ne sont jamais supprimés physiquement : ils sont
désactivés avec motif, auteur et audit, puis leurs sessions sont révoquées.
Les seeders standards ne doivent créer aucun compte ou mot de passe de
démonstration. L'unique exception est `DevelopmentUserSeeder`, explicitement
réservé à `local/testing`, jamais appelé par `DatabaseSeeder` et refusé en
production. Voir ADR-022.

La compatibilité compte/déploiement est contrôlée à la connexion et sur chaque
session : `SUPER_ADMIN` uniquement sur `admin`, tout rôle opérationnel uniquement
sur `clinic`. Le rôle et la permission sont tous deux nécessaires au portail.

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

À l’arrivée, Réception peut sélectionner les prestations `SERVICE`. Laravel
résout le barème `STANDARD` ou `MUTUAL` selon le type du patient et crée son
instantané ; le navigateur ne fournit jamais un prix fiable. Les deux grilles
sont historisées indépendamment. Un tarif mutuelle manquant ne reprend jamais
le tarif standard : la demande clinique et l’orientation sont conservées, mais
la facturation reste en attente. `PAYER PLUS TARD` produit une facture
validée avec solde dû ; `PAYER MAINTENANT` exige une caisse ouverte et produit
facture, paiement, mouvement de caisse et reçu. Aucun reçu n’existe sans
encaissement réel. Une urgence ne dépend jamais de cette sélection ou du
paiement. Voir ADR-028 et ADR-031.

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

# Décision client du 22/08/2026 — accueil patient

ADR-030 remplace le parcours uniforme de l'ADR-029 : le type administratif du
patient est `STANDARD`, `MUTUAL` ou `STAFF`, puis les désignations configurées
pilotent le parcours clinique (`MEDICINE_DIRECT`, `CARE_THEN_MEDICINE` ou
`CARE_ONLY`). L'urgence reste visible immédiatement aux Soins et en Médecine.

Les nouveaux numéros humains sont annuels pour le patient (`M-26-0001`) et
ordinaux par patient pour les passages (`M-26-0001-01`). Les UUID restent les
identifiants publics. Le dossier Employé est distinct de `users`; la Réception
ne fait qu'une recherche minimale et un lien patient-employé. Les pièces de
mutuelle sont privées et limitées à cinq.

La demande clinique doit être conservée indépendamment de la facturation. Pour
un employé actif et éligible, les prestations sont prises en charge à 100 % hors
bloc ; les actes du bloc consomment un crédit configurable et l'excédent reste à
la charge du patient. Le montant brut demeure historisé : la couverture/crédit
RH/Finance ne peut jamais être simulé par un tarif nul, une remise arbitraire ou
un faux paiement. Tant que la période du crédit et le périmètre exact des actes
du bloc ne sont pas configurés, la facturation `STAFF` reste en attente sans
bloquer le parcours clinique.

Les tarifs `STANDARD` (« Sans mutuelle ») et `MUTUAL` sont des montants bruts
propres à chaque site. `STAFF` n'est pas une grille tarifaire : son avantage est
calculé séparément. Le PDF Ambondromamy de juin 2023 confirme les deux grilles,
mais reste une référence historique non importée automatiquement car plusieurs
lignes sont ambiguës ou variables. La part payée par une mutuelle et la part du
patient ne sont pas encore définies par le client.

Le 23/08/2026, le client a validé une fiche de soins `NURSE` par passage :
constantes, IMC calculé, observations, transmission conditionnelle
et historique append-only des actes réellement réalisés. Les actes fournis sans
prix entrent dans le référentiel sans faux tarif. Le « diagnostic communiqué »
reste une information de transmission et ne remplace jamais le diagnostic de
Médecine. Les allergies confirmées sont sélectionnées depuis le dossier
permanent ; une nouvelle allergie peut y être ajoutée avec la permission
`patients.medical_history.manage`, et la fiche conserve le snapshot du passage.
Pour un patient sans allergie déjà connue, le personnel autorisé peut sélectionner
un allergène courant depuis `allergen_references` ; ce choix alimente le dossier
permanent. La saisie manuelle reste le recours lorsqu'il n'existe pas dans le
référentiel et ne modifie pas automatiquement ce référentiel partagé.
La fiche Soins ne décide ni l'hospitalisation ni la sortie. Les dates d'entrée
et de sortie seront alimentées par les futurs workflows Hospitalisation et
Sortie médicale, et restent absentes de l'écran Soins jusque-là. La transmission
vers Médecine est visible pour `CARE_THEN_MEDICINE`, le besoin inconnu et
l'urgence ; elle est masquée pour `CARE_ONLY`. Voir ADR-032.
Pour un acte autonome `CARE_ONLY` (par exemple un pansement), groupe sanguin,
taille, poids et IMC sont facultatifs et repliés par défaut. Ils restent
recommandés pour l'urgence, le besoin inconnu et un parcours continuant vers
Médecine, sans devenir des champs obligatoires artificiels. Un ancien élément du
snapshot d'allergies qui n'existe plus dans le dossier actif demeure consultable
comme historique, mais n'est jamais resoumis comme sélection active et ne bloque
plus l'enregistrement de la fiche.
La section complète « Constantes et observations » est facultative et repliée
pour un acte autonome. Les actes marqués dans le référentiel comme nécessitant
une vérification allergique (injections IM/IV et perfusion dans le jeu initial)
affichent seulement ce contrôle de sécurité ciblé ; sa confirmation est tracée
avec l'acte. Les règles `care_requires_allergy_check` et
`care_recommends_vitals` sont configurées par prestation puis figées dans la
demande du passage. `CARE_ONLY` exige au moins un acte enregistré avant la fin.
Un besoin indéterminé sans acte exige soit une orientation Médecine, soit un
motif explicite. L'action UI « Enregistrer l'acte et terminer » est atomique côté
Laravel.
Le relevé facultatif inclut aussi la tension systolique/diastolique des bras
gauche et droit en mmHg, la température en °C et le statut « diabète connu » à
trois états (non renseigné, non, oui). Une paire de tension est toujours complète
et cohérente. Ces données appartiennent au passage et nécessitent `vitals.*` ;
elles ne sont pas rendues obligatoires pour chaque acte.

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
