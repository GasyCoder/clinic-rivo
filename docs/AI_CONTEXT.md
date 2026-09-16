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

La Super Administration importe et exporte les adresses et le stock pharmacie
au format Excel `.xlsx`. Elle pilote aussi les désignations et les tarifs
`STANDARD`/`MUTUAL` de chaque site exclusivement par les API
`/api/v1/super-admin/catalog*`. L’API du site réautorise la permission précise,
historise les prix et attribue l’action à l’acteur central UUID/nom. L'import
d'adresses normalise les doublons. L'import
de stock accepte seulement `STOCK_INITIAL` pour un lot nouveau ou `ENTREE` pour
ajouter une quantité ; chaque ligne crée dans le site cible un mouvement
immuable, transactionnel, idempotent et audité avec l'identité de l'acteur
central. Aucun ajustement, sortie ou délivrance n'est créé par cet import. Voir
ADR-042.

Le tableau de bord central est alimenté par le rapport que chaque site sert
sur `/api/v1/super-admin/reports/overview` (ADR-102) : activité, finance,
files cliniques, pharmacie et personnel, chacun gardé par la permission qui
possède la donnée. `SiteReportService` s'exécute dans la base du site ; le
portail additionne les trois réponses et n'ouvre jamais de connexion SQL.
**Une donnée absente n'est jamais un zéro** : une section refusée revient
`available: false` avec son motif, un site injoignable `ok: false`, et
l'écran écrit « — » en nommant la cause — afficher `0` ferait décider sur un
chiffre faux (ADR-048). Aucun montant n'est extrapolé : le reste dû se lit
sur `invoices.balance_amount` et jamais sur facturé − encaissé, qu'une prise
en charge à 100 % rendrait faux (ADR-047).

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

Le socle RH opérationnel utilise des UUID publics et des référentiels locaux
configurables pour les départements, fonctions, types de contrat et types
d'attestation. Les formulaires Employé, Contrat, Présence, Congé et Planning
sont des pages dédiées sans modale. L'import Employé est atomique ; exports,
impressions et pièces privées sont autorisés par permissions distinctes. Les
congés suivent un état audité sans calcul automatique de droits. Aucun calcul
de paie, CNAPS ou IRSA n'est activé faute de règles officielles suffisantes.
Voir ADR-066.

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

Le référentiel des rôles d'un site est administrable depuis le portail
(ADR-100) : `roles.create`, `roles.update`, `roles.archive` et
`roles.restore`, réservées au `SUPER_ADMIN` central, passent par l'API du
site qui revérifie la permission et audite l'acteur distant. Le `code` d'un
rôle est son identité et ne change jamais — `RolePermissionSeeder::GRANTS`,
les affectations et l'audit le désignent par ce code. `roles` est Soft Delete
(`deleted_at`/`deleted_by`/`delete_reason`) : un rôle encore porté par un
compte ne s'archive pas — `User::role()` ne renvoie plus un rôle archivé, et
ses titulaires perdraient tout leur socle — et un code pris par un rôle
archivé se restaure au lieu d'être recréé. **Aucune permission ne se crée
depuis un écran** : elle n'a d'effet que si le code la vérifie, et une
permission inventée ne serait qu'un interrupteur qui ne commande rien.

Le **catalogue des permissions** d'un site est lui aussi administrable
depuis le portail (ADR-101) : `permissions.create`, `permissions.update` et
`permissions.delete`, réservées au `SUPER_ADMIN` central. Le `name` d'une
permission ne change jamais — le code l'écrit en clair (`can:patients.view`)
— et son retrait est physique, refusé dès qu'un rôle l'accorde, qu'un compte
porte une exception dessus, ou que le code la vérifie quelque part : la
supprimer ne retirerait pas le contrôle, elle le rendrait impossible à
satisfaire. `PermissionUsageScanner` calcule cet usage **depuis les sources**
(routes `can:`, appels serveur, `can()` et `permission:` des écrans ; jamais
`database/`, qui cite tous les noms) et l'écran affiche « vérifiée par
l'application » ou « pas encore vérifiée » : créer une permission crée le
mot, pas le contrôle.

Le portail présente deux écrans distincts : `/super-admin/workspaces/users`
(comptes : identité, rôle, profil, activation) et
`/super-admin/workspaces/roles` (socle des rôles, exceptions individuelles,
référentiel des rôles). Les exceptions d'un compte ont leur propre chemin
d'écriture (`UpdateUserPermissionOverridesAction`) : le formulaire de compte
n'envoie plus `permission_overrides`, et la clé omise laisse les exceptions
intactes plutôt que de les effacer à chaque correction d'un nom. La
résolution reste `DENY individuel > ALLOW individuel > socle du rôle`.

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

La Super Administration supervise les caisses uniquement via l’API de chaque
site (ADR-057). Sa fiche affiche l’agent d’ouverture, les heures, les
mouvements, les totaux, le comptage et l’écart. Un verrouillage central laisse
la session active et unique mais bloque tout encaissement ; il est réversible.
Une clôture centrale est définitive, exige les espèces réellement comptées et
un motif, puis laisse le site recalculer lui-même le montant attendu. Les
acteurs centraux sont attribués par UUID externe, jamais par un compte local.

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

Les Soins déclarent les consommables réellement utilisés sur le patient
(sparadrap, coton, compresses, gants) dans la **même étape et la même
soumission que les actes** : un pansement et ses compresses sont un seul geste
pour un infirmier, et un formulaire séparé faisait perdre la saisie lorsque la
prise en charge était terminée directement. Ce sont des produits Pharmacie de forme
`ParapharmacyConsumable` : les Soins ne délivrent jamais un médicament ni une
ordonnance, restriction appliquée côté serveur et non par l'interface. La
déclaration constitue la notification : elle apparaît immédiatement dans la
file « Consommables Soins » de l'espace Pharmacie. La sortie de stock est
enregistrée par la Pharmacie, en FEFO, sans jamais entamer une quantité déjà
réservée, et **sans attendre le règlement** — le consommable est déjà utilisé,
ce qui amende l'ADR-049 pour ce seul circuit. La part patient est facturée
séparément de l'acte sur la facture du passage et encaissée exclusivement par
Réception/Caisse. Une demande est annulée avec motif tant qu'aucun lot n'a
bougé, jamais supprimée. Un acte de soins peut porter son matériel habituel
(`care_act_consumables`, configuré avec `catalog.items.update` depuis
Administration › Catalogue) : sélectionner l'acte pré-remplit ces
consommables, que l'infirmier confirme, corrige ou retire. C'est une
suggestion de saisie, jamais une règle — rien n'est déduit du nom ou du code
d'un acte, et une quantité corrigée par le soignant n'est jamais réécrite.
Voir ADR-072.

**Ce matériel est facturé, et l'écran doit le dire** (ADR-103). Il l'était
depuis l'origine — un `BillableItem` par ligne, au tarif serveur, sur la
facture du passage — mais `/pharmacy/care-consumables` n'affichait ni
montant, ni facture, ni statut : le pharmacien voyait son stock partir sans
contrepartie et en concluait que la clinique donnait ce matériel. La file
expose désormais, en lecture seule, ce que le passage doit et la facture qui
le porte. Surtout, elle nomme le **seul cas où un consommable finit
réellement gratuit** : la facturation est volontairement non bloquante
(ADR-072 — une compresse déjà posée ne s'annule pas parce qu'un tarif
manque), mais l'échec était avalé en silence et aucun écran ne le signalait
ensuite. `lineBilling()` distingue cinq états — `INVOICED`, `PENDING`,
`CANCELLED`, `NOT_BILLABLE` (décision de paramétrage) et `NOT_BILLED`
(anomalie) — et `summary.unbilled_lines` leur donne un compteur et une
section qui nomme la ligne, la raison et qui doit la régulariser. Le prix
reste invisible au poste de soins : `present()` prend `$withBilling = false`
par défaut et la fiche Soins ne le passe jamais, si bien que l'ADR-036 tient
à la valeur par défaut d'un paramètre, non à la vigilance de chaque
appelant. La Pharmacie lit, n'encaisse rien et ne propose aucun paiement
(ADR-012, ADR-013) ; aucune permission nouvelle.

Une ordonnance Médecine sélectionne un médicament actif du référentiel
Pharmacie. La disponibilité est calculée sur les lots actifs non périmés, moins
les réservations actives. La validation réserve transactionnellement la
quantité en FEFO et échoue intégralement si le stock est insuffisant. Elle ne
déstocke pas : seule la future délivrance Pharmacie réalise la sortie physique.
L'annulation d'une ordonnance libère la réservation. Médecine ne reçoit que
`stock.availability.view`, jamais les droits de mutation `stock.*`. Voir
ADR-036.

Chaque demande de dispensation facturée possède un ticket Pharmacie. Son numéro
de facture est la référence automatique encodée dans le QR. La Caisse peut
scanner le QR ou saisir cette référence ; pour un patient interne, elle peut
aussi rechercher le numéro de passage ou le numéro patient. Ce contrôle ne crée
aucun paiement. Le ticket n'est jamais un reçu et son impression relève de
`pharmacy.dispense.print`; l'encaissement et le reçu restent exclusivement à
Réception/Caisse. La vente comptoir n'affiche et n'accepte aucun numéro de
référence manuel : le numéro de facture backend est l'unique référence du
ticket, y compris pour un produit signalé comme nécessitant une ordonnance. Le
contrôle se trouve dans un onglet Caisse dédié avec la saisie manuelle
sélectionnée par défaut. La file de dispensation sépare le statut des actions et
n'affiche le détail des produits que dans la fenêtre ouverte par l'action Voir.
La vente comptoir affiche uniquement « Créer et transmettre à la Caisse » : il
n'existe aucun bouton d'impression autonome sur l'écran principal. Cette action
ouvre une fenêtre de confirmation soignée avec le récapitulatif de la vente.
Son bouton final, « Imprimer et transmettre à la Caisse », crée et transmet la
vente, reste sur la page, imprime directement le ticket officiel, puis vide le
formulaire pour le client suivant. Le nom, le téléphone et le prescripteur
externe facultatifs apparaissent sur le ticket officiel lorsqu'ils sont
renseignés. Les actions de la file distinguent l'ordonnance,
les opérations métier et les opérations documentaires : une unique action
« Voir » ouvre le détail. Il n'existe plus d'action « Aperçu » supplémentaire ;
le ticket s'imprime directement depuis cette fenêtre, sans navigation ni
changement de l'URL visible. Les identifiants exposés par les opérations
Pharmacie (demande, ligne, réservation, bon, allocation, mouvement, médicament
et lot) sont des UUID ; les IDs SQL restent internes. Préparation, impression,
délivrance, entrée et ajustement gardent chacun leur permission Laravel. Dans
l'onglet Caisse, la saisie/le scan et le champ de contrôle sont placés dans
l'en-tête ; les résultats reprennent le tableau des factures à encaisser. Les
factures Pharmacie sont séparées de la liste générale, sont affichées par défaut
dans cet onglet et peuvent être filtrées dynamiquement par référence, client,
patient ou passage. Voir ADR-050.

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

Interrogatoire et Examen clinique sont deux étapes réelles et séparément
enregistrées du parcours Consultation (`SaveConsultationAction::saveInterview()`/
`saveClinicalExam()`), chacune sa propre permission de lecture d'écran et sa
propre requête PUT — jamais un écran unique partageant un seul enregistrement,
pour qu'une saisie tardive de l'un ne puisse jamais écraser l'autre resté
ouvert dans un onglet distinct. Le médecin prépare ensuite, à l'étape
Prescription, une intention d'orientation facultative parmi les six décisions
de référence (sortie médicale, hospitalisation, Maternité, Chirurgie,
Pédiatrie, transfert externe) : ce geste écrit uniquement `Consultation.decision`
et ne crée jamais lui-même l'orientation, la `SurgicalRequest` ou la
`MedicalDischarge` réelles, qui restent une action explicite de l'étape
Décision dans le module destinataire. Voir ADR-075.

L'avancement du parcours appartient au serveur, jamais au navigateur :
`consultation_steps` porte le statut réel de chacune des sept étapes
(`NOT_STARTED`, `IN_PROGRESS`, `COMPLETED`, `SKIPPED`) et
`ConsultationWorkflow` en est la source unique — le stepper n'affiche que ce
qu'elle renvoie. Enregistrer n'est pas valider : « Enregistrer » laisse
l'étape `IN_PROGRESS`, « Enregistrer et continuer » la valide, « Passer cette
étape » la déclare non nécessaire avec auteur, date et motif facultatif. Une
étape n'est jamais affichée terminée parce qu'elle a été ouverte, et
`ResolveConsultationStepAction` refuse de valider une étape dont le minimum
propre n'est pas atteint. Seules Paraclinique et Prescription sont passables.
Pour un passage paraclinique seul (`MEDICINE_DIRECT` + `IMAGING`/`LABORATORY`),
Interrogatoire et Examen clinique sont sans objet : ni exigés à la clôture, ni
verrouillés. `consultations.status` (`DRAFT`/`IN_PROGRESS`/`COMPLETED`/
`CANCELLED`) remplace la déduction « clôturée parce qu'une `MedicalDischarge`
existe » ; `CompleteConsultationAction` est idempotente, refuse la clôture tant
qu'une étape pertinente reste non résolue et n'exige jamais une analyse, une
imagerie, une prescription ou une hospitalisation. Après clôture, tous les
chemins d'écriture ordinaires refusent (ADR-010). Aucune permission nouvelle :
`consultations.update` couvre la validation comme la clôture. Voir ADR-076.

La décision « des examens complémentaires sont-ils nécessaires ? » est posée en
tête de l'étape Paraclinique qu'elle gouverne (ADR-079), par son endpoint dédié
`POST /medicine/orientations/{orientation}/complementary-exams` :
`clinical_examinations.complementary_exams_required` est un booléen nullable —
null = pas encore répondu, aucun bouton pré-sélectionné. « Non » résout l'étape en `SKIPPED` avec auteur, date et motif, puis conduit
au Diagnostic sans faire traverser un écran vide ; le stepper affiche « Non nécessaire », qui n'est ni « non
commencé », ni « résultat normal », ni « examen absent ». « Oui » conduit à
Paraclinique, où les examens se choisissent dans les catalogues existants
(`LABORATORY` pour les analyses, `IMAGING` pour ECG et échographie, ADR-063) —
aucune seconde liste, aucun résultat saisi ni affiché dans l'examen clinique.
Repasser à « Non » alors que des demandes sont parties les annule
(`cancelled_at`/`cancelled_by`/`cancel_reason` sur `lab_requests` et
`imaging_requests`, `displayStatus()` renvoie `CANCELLED`) après confirmation
explicite revérifiée côté serveur ; une demande portant déjà un résultat n'est
jamais retirée et le changement est refusé. Rien n'est jamais supprimé
physiquement (ADR-010). Un diagnostic provisoire reste possible sans attendre
les résultats.

Le diagnostic se conclut dans l'Examen clinique (ADR-080) :
`clinical_examinations.diagnosis_ready` est un booléen nullable — « Oui » saisit
le diagnostic sur place et mène à la Prescription ; « Pas maintenant » diffère
sans rien bloquer, afin qu'un médecin en attente de résultats ne soit jamais
poussé à conclure avant de les lire. Répondre « Oui » sans avoir rien enregistré
est refusé côté serveur. `ClinicalDiagnosisEntry.vue` poste vers l'endpoint
`/diagnoses` existant : même validation, même append-only, même audit (ADR-035).

« Le diagnostic peut-il être posé maintenant ? » est posée à **Décision &
clôture** (ADR-095, amende ADR-080), en tête de la section Diagnostic — la
seule étape que tout patient atteint. Elle vivait à l'Examen clinique, qui
est « sans objet » pour un passage paraclinique seul (ADR-076) : ce patient
ne pouvait donc ni répondre, ni expliquer pourquoi sa consultation restait
ouverte. Trois états, jamais deux : `null` tant que le médecin n'a pas
répondu, aucun bouton pré-sélectionné. « Pas maintenant » ne débloque rien —
la clôture exige toujours un diagnostic (CDC §33.1, ADR-081), sauf passage
paraclinique (ADR-094) — mais le blocage dit « différé par le médecin » au
lieu de « aucun diagnostic enregistré », et le stepper affiche « Diagnostic
différé » sous l'étape Clôture. Un blanc signifie que personne n'a décidé,
un report que quelqu'un a décidé d'attendre. L'endpoint dédié
`POST /medicine/orientations/{orientation}/diagnostic-timing` porte la
réponse, pour la même raison que l'ADR-079 : répondre à une question ne doit
jamais réécrire l'état général, la conscience ou les appareils examinés ;
`diagnosis_ready` quitte donc `UpdateMedicineClinicalExamRequest`. Répondre
« Oui » sans rien avoir enregistré reste refusé côté serveur. La **saisie**
du diagnostic reste disponible à l'Examen clinique (ADR-080) ; seule la
question a bougé. L'Examen clinique mène désormais toujours à l'étape
suivante — le raccourci qui sautait la Paraclinique la contournait sans que
personne réponde à sa propre question (ADR-079).

La conduite à tenir est une **donnée**, plus une étape (ADR-084, amende
ADR-075 et ADR-076). `consultation_orientations` la porte avec son statut —
`SELECTED` (destination choisie), `SUBMITTED` (demande réellement partie),
`CANCELLED` (changement d'avis) — et une seule est active par consultation,
garantie par `active_key`. Elle se décide en un seul endroit (ADR-089) : la dernière étape,
**« Décision & clôture »**, réunit de haut en bas le diagnostic, la conduite à
tenir avec son formulaire prérempli, puis la vérification et la clôture. Le
parcours devient Dossier → Interrogatoire → Examen → Paraclinique →
Prescription → Décision & clôture ; les étapes précédentes sont du recueil et
ne portent plus la carte « Suite de la prise en charge ». Décider tôt reste
possible : le parcours n'est pas verrouillé. Choisir n'est pas transmettre : la clôture refuse une
orientation restée « à configurer ». Hospitalisation et Référence/Transfert
ont désormais leur demande (`hospitalization_requests`, `medical_referrals`,
statut `REQUESTED`/`CANCELLED` seulement) avec leur document imprimable ;
elles ne modélisent jamais l'admission ni le transfert eux-mêmes, faute de
règles définies. Maternité et Pédiatrie gardent l'orientation de service.
Changer d'orientation annule ce qui était parti sans jamais l'effacer, et est
refusé dès que la destination a pris la demande en charge
(`EpisodeOrientation::cancel()`, `SurgicalRequestStatus::Cancelled`).
Enregistrer une sortie médicale ne termine plus la rencontre :
`CompleteConsultationAction` est le seul acte qui complète l'orientation
Médecine et porte la sortie sur l'épisode. Les formulaires arrivent
préremplis du motif, des diagnostics, de l'examen, des résultats et de
l'ordonnance — aucune donnée n'est jamais redemandée (§17).
`ConsultationStep::Decision` reste dans l'enum pour les lignes déjà
enregistrées et `/decision` redirige vers `/cloture` ;
`consultations.decision` continue d'être écrite pour tous les lecteurs
existants. Aucune permission nouvelle.

Un passage venu **uniquement pour un examen** (ECG, échographie, analyse :
`MEDICINE_DIRECT` + module `IMAGING`/`LABORATORY`) ne doit **aucun diagnostic
final** (ADR-094, amende ADR-081 et ADR-035). Le cas constaté : le médecin
devait consigner la conclusion d'un ECG dont le résultat n'était pas encore
saisi, si bien que le passage restait indéfiniment `IN_CARE` et n'atteignait
jamais la file de règlement. `ConsultationWorkflow::requiresFinalDiagnosis()`
porte la règle une seule fois — définie par le `isParaclinicalOnly()` qui
rendait déjà Interrogatoire et Examen « sans objet » (ADR-076) — et la garde
de clôture comme `StoreMedicalDischargeRequest` la consultent toutes les
deux ; recopiée, la sortie serait partie sur un dossier que la clôture aurait
ensuite refusé. C'est tout ou rien : « écho + consultation » n'est pas un
passage paraclinique et le diagnostic y reste exigé.
`medical_discharges.final_diagnosis` devient nullable et
`RecordMedicalDischargeAction` ne fabrique plus de `Diagnosis` vide : une
absence reste une absence (ADR-077). Le médecin garde le droit d'en poser un.
La conduite à tenir, `patient_condition` et la résolution des étapes restent
obligatoires — ADR-094 ne lève que le diagnostic. Le CDC §33.1 ne traite
nulle part la venue paraclinique isolée ; la divergence est signalée, jamais
masquée.

Une consultation clôturée peut être **rouverte**, avec motif obligatoire et
trace d'audit (`consultation.reopen`), tant que la Réception n'a pas clos le
passage (ADR-096 — construit le mécanisme que l'ADR-076 annonçait). Le cas :
un ECG demandé le matin, la consultation clôturée, le résultat qui arrive
l'après-midi. `ReopenConsultationAction` défait exactement ce que
`CompleteConsultationAction` a fait — consultation et orientation reviennent
`IN_PROGRESS`, l'étape Clôture redevient `NOT_STARTED`, l'épisode repasse de
`PENDING_SETTLEMENT` à `IN_CARE` pour quitter la file « Sorties & règlements »
pendant qu'un médecin y écrit encore. **Rouvrir n'est pas annuler** : aucune
donnée clinique n'est supprimée, une sortie médicale prononcée le reste, les
diagnostics restent append-only. Refusé dès que `Episode.status` est `CLOSED`
(ADR-090) : le compte est soldé, une créance a pu naître, et le CDC ne dit
nulle part ce qu'elles deviendraient. Permission dédiée
`consultations.reopen`, accordée à `MEDICINE` — écrire dans une consultation
ouverte et revenir sur une consultation conclue ne sont pas la même autorité.

L'espace **« Demandes d'examens »** (`/medicine/demandes-examens`) a sa
propre permission d'accès, `paraclinical_requests.view` (ADR-100) : il réunit
analyses et imagerie, et sa route n'exigeait que `laboratory_orders.view` —
un compte n'ayant que l'imagerie recevait un 403 devant un écran que le
contrôleur savait lui servir. `laboratory_orders.view` et
`imaging_orders.view` continuent de gouverner ce qui s'y affiche, section par
section ; sans aucune des deux, l'écran le dit au lieu de présenter une liste
vide qui se lirait « aucune demande ».

Le compte rendu d'un examen d'imagerie se saisit depuis **« Demandes
d'examens »** (`/medicine/demandes-examens`), en éditeur riche assaini par
`ClinicalRichTextSanitizer`, via l'endpoint existant de la consultation —
aucun second chemin d'écriture. L'imagerie seulement : un résultat d'analyse
appartient au Laboratoire (`laboratory_results.create`). La saisie reste
possible **après** la clôture, sinon un résultat tardif serait impossible à
consigner. Le compte rendu est imprimable (`Medicine/ImagingReportPrint`),
avec l'identité du patient et l'en-tête du site issu de `page.props.site` —
impression navigateur, jamais de PDF serveur (ADR-070).

L'étape Diagnostic a été retirée de l'assistant (ADR-081) : le parcours compte
six étapes. Correction et annulation vivent dans la carte Diagnostic de
l'examen, réservées à l'auteur de la saisie ; l'historique complet — diagnostics
annulés compris, barrés et marqués « Annulé » — est dans « Contexte clinique ».
Le cas `ConsultationStep::Diagnosis` reste dans l'enum pour que les lignes
`consultation_steps` déjà enregistrées se lisent encore, mais `isWizardStep()` /
`wizardCases()` l'excluent du parcours ; `/diagnostic` redirige vers `/examen`.
La clôture ne vérifie plus l'état d'un écran mais **directement** l'existence
d'un diagnostic actif : la garantie a changé de support, pas disparu.

Une ligne d'ordonnance porte sa voie d'administration (`prescription_lines.route`,
ADR-083) : « 500 mg orale » n'est pas « 500 mg IV ». Facultative, jamais
rétro-remplie — les lignes antérieures s'affichent « Non précisée » plutôt qu'un
tiret muet — et conservée par `UpdatePrescriptionAction` quand une correction ne
la porte pas. La posologie est composée avec ses unités dès la saisie et servie
déjà assemblée par le presenter (`posology`), si bien qu'aucun écran n'a à
deviner l'unité d'un nombre nu. Prescrire reste une décision : la validation
réserve en FEFO (ADR-036) sans décrémenter aucune quantité physique, seule la
délivrance Pharmacie le fait.

La saisie ne distingue plus hypothèse et diagnostic final (ADR-082, amende
ADR-035) : toute nouvelle ligne est enregistrée `FINAL`. `DiagnosisType`
conserve ses deux cas — des hypothèses existent déjà en base, les supprimer les
rendrait illisibles et les réétiqueter affirmerait une certitude jamais
exprimée. Un badge « Hypothèse » n'apparaît donc que sur une ligne qui en est
réellement une ; les nouvelles n'en portent aucun. Le serveur accepte toujours
les deux valeurs, mais aucune interface ne produit plus d'hypothèse.

L'étape Interrogatoire est semi-structurée (ADR-078) : `chief_complaint`
porte la raison réelle de la venue, courte et exploitable dans l'historique,
les résumés et la recherche — jamais le type de prestation demandée, qui
vient déjà de l'`EpisodeServiceRequest`. `reason` reste l'unique domicile du
récit ; `symptom_onset`, `evolution` et `additional_notes` complètent sans
l'absorber. Seuls le motif et l'histoire sont exigés, et uniquement pour
valider l'étape : « Enregistrer » n'impose rien. `patient_treatments` porte
les traitements habituels du dossier permanent — déclaratifs, sans
`catalog_item`, lot ni prix — affichés sans ressaisie, l'entretien ne posant
que la question du changement déclaré. Ce que le patient révèle pendant
l'entretien (`reported_allergies`, `reported_antecedents`,
`reported_habitual_treatments`) est toujours conservé sur la consultation ;
le porter au dossier permanent est un second acte explicite par case à
cocher, soumis à `patients.medical_history.manage` et refusé côté serveur
sans elle. Omettre un bloc ne l'efface jamais (ADR-074). Aucune constante
vitale, aucune constatation d'examen, aucun diagnostic ni prescription
n'entre dans cette étape.

L'étape Examen clinique est semi-structurée (ADR-077, amende ADR-074) :
`clinical_examinations` porte l'état général, la conscience et une
observation facultative ; `clinical_examination_findings` porte un statut par
appareil parmi neuf (`CARDIOVASCULAR` … `OTHER`). Trois états seulement :
`NOT_EXAMINED`, `NORMAL`, `ABNORMAL`. Rien n'est pré-coché, `NOT_EXAMINED`
est la valeur par défaut et **ne stocke aucune ligne** — une absence de
saisie n'est jamais un examen normal, et `ClinicalExamination::systems()` est
le seul endroit qui décide ce que signifie une ligne absente. `ABNORMAL`
exige ses constatations, vérifiées dans la FormRequest **et** dans
`SaveClinicalExaminationAction` ; `NORMAL` n'en demande ni n'en conserve
aucune. Aucune constante vitale n'est ressaisie ici : tension, FC, SpO₂,
température, poids, taille et IMC restent relevées une seule fois par les
Soins et lues en lecture seule via `CareRecordReadModel` (ADR-054) depuis
« Contexte clinique » — un recontrôle éventuel devra créer un nouveau relevé,
jamais modifier la mesure d'origine. `consultations.clinical_exam` n'est ni
supprimée ni dupliquée : elle devient les « Notes cliniques
complémentaires », désormais facultatives. L'étape se valide donc sur un
examen réellement réalisé (état général, conscience ou au moins un appareil
examiné), jamais sur le fait qu'un texte est rempli.

Avec `episodes.mark_emergency`, Médecine peut requalifier l'Épisode de la
Consultation active en urgence. L'action partagée avec Réception conserve la
Consultation et tout état `IN_CARE`, ouvre de façon idempotente les files Soins
et Médecine, et n'écrit jamais l'urgence sur le Patient. Voir ADR-056.

Les constantes et alertes de la fiche Soins (tension, FC, SpO2, température,
IMC) affichées en Médecine proviennent de `CareRecordReadModel`, la même
projection que Soins et Chirurgie/Anesthésie (ADR-048, ADR-054) : aucun seuil
n'est recalculé dans `MedicineDossierPresenter`. Voir cette projection exige
les permissions en lecture seule `care.view`/`vitals.view`, accordées par
défaut à `MEDICINE` sans aucun droit `care.update`/`vitals.update`.

**Maternité lit la même projection** (2026-09-16). C'était le seul module
clinique à ne pas la consommer : une sage-femme ouvrait le dossier sans
voir la tension, la température ni les allergies déjà consignées au même
passage, et n'avait aucun moyen de les retrouver sans quitter l'écran.
`MaternityController::show()` sert donc `careRecord` (même
`CareRecordReadModel`, mêmes gardes serveur `care.view`/`vitals.view`/
`patients.medical_history.view`), `allergies` et `careRecordUrl` ; l'écran
les affiche avec le `VitalSignsStrip` de la consultation. Rien n'est
ressaisi ni recalculé : corriger une constante renvoie à la fiche Soins qui
la porte (ADR-092, ADR-093). Le rôle `NURSE` — celui des sages-femmes
(ADR-067) — détient déjà ces droits de lecture : aucune permission nouvelle.
Un passage arrivé directement de la Réception en Maternité (ADR-068) n'a pas
de fiche Soins : l'écran l'écrit, plutôt que d'afficher des tirets qui se
liraient « normal ».

La saisie en cours de la fiche de soins est conservée côté serveur
(`care_record_drafts`), enregistrée automatiquement et restaurée après une
actualisation. Elle est rattachée au passage **et** à son auteur : sur un
poste partagé, un soignant ne récupère jamais la saisie non validée d'un
collègue. Elle n'est ni auditée, ni lue par un module, et disparaît dès
l'enregistrement réel ou l'annulation explicite. Voir ADR-073.

La fiche de soins reste **corrigeable après le transfert vers Médecine**, et
par tout compte Soins autorisé (ADR-092, amende l'ADR-085) : une température
saisie 32 °C au lieu de 36,2 doit pouvoir être rectifiée même quand le
soignant qui a pris le patient en charge a fini son service.
`CareHandlerGuard::isEditable()` autorise l'écriture tant que l'orientation
est `IN_PROGRESS` ou `COMPLETED` et que le passage reste `OPEN` ; `PENDING` et
`CANCELLED` restent refusés. Le périmètre couvre la fiche entière — un acte
ajouté après le transfert crée donc son `BillableItem` et un consommable sa
demande Pharmacie, plus tard qu'avant mais par les mêmes circuits. Le
**transfert vers Médecine reste unique et réservé au soignant qui a pris le
patient en charge** : `CompleteCareAndOrientToMedicineAction` conserve
`ensureWorkable()`. Chaque correction est tracée (`Auditable`, ancienne et
nouvelle valeur). Le brouillon suit la fiche sans jamais être partagé entre
comptes (ADR-073). Voir ADR-092.

Le médecin **corrige les constantes** relevées par les Soins (ADR-093,
amende ADR-077/054/092) : une température saisie 32 °C au lieu de 36,2 lui
parvenait en lecture seule et restait fausse dans le dossier.
`vitals.update` est ajoutée au socle `MEDICINE` ; `care.update` ne l'est
pas, et c'est ce qui borne le périmètre — les constantes seules. Les actes,
le matériel, les allergies et la transmission sont explicitement
`prohibited` dans `CorrectCareRecordVitalsRequest` et refusés par
`CorrectCareRecordVitalsAction` : les autoriser ferait naître, depuis un
écran de consultation, une prestation à facturer (ADR-054) ou une sortie de
stock Pharmacie (ADR-072). L'écrasement est réel — la mesure d'origine
quitte l'écran — mais jamais silencieux : `CareRecord` est `Auditable` et
conserve l'ancienne comme la nouvelle valeur avec son auteur et sa date.
`App\Support\VitalSignRules` porte une seule fois les bornes cliniques,
leurs messages et le calcul de l'IMC, consommés par la fiche Soins **et**
par la correction Médecine : une tension refusée à l'infirmier ne peut pas
être acceptée au médecin. L'épisode doit rester `OPEN` et la consultation
éditable ; sans fiche Soins existante la correction refuse, Médecine
corrigeant une mesure sans jamais en signer une. `read_only` reste `true`
dans `CareRecordReadModel` : seul le drapeau `can_correct_vitals`, calculé
depuis `vitals.update`, ouvre les constantes — la Chirurgie ne possède pas
cette permission (ADR-048) et son comportement est inchangé. Voir ADR-093.

Un patient venu uniquement pour un soin (`CARE_ONLY`, par exemple un
pansement) reste aux Soins et ne voit aucun médecin : aucune orientation
Médecine n'est créée et l'épisode passe en `PENDING_SETTLEMENT`. Les Soins
peuvent y déclarer les consommables utilisés, transmis à la Pharmacie pour la
sortie de stock et facturés séparément par Réception/Caisse ; ils ne
prescrivent jamais. Voir ADR-072.

Un antécédent permanent s'ajoute depuis Médecine (avec
`patients.medical_history.manage`) via l'unique point d'entrée générique
`PatientController::storeAntecedent()`, partagé par tout module autorisé —
jamais un champ de `Consultation`. Voir ADR-054.

Une orientation Soins réellement terminée sans transmission Médecine
restante (`CareCompletionMode::Finish`, aucune orientation Médecine active)
fait passer `Episode.administrative_status` à `PENDING_SETTLEMENT` : le
parcours clinique est terminé, la suite est administrative/financière.
Aucune `MedicalDischarge` n'est jamais fabriquée par ce mécanisme, et une
Urgence dont l'orientation Médecine reste active n'est jamais close ainsi.
Voir ADR-054.

Depuis une Consultation active, le médecin peut demander un ou plusieurs
actes à Soins via `CareOrder`/`CareOrderItem` (`care_orders.create`) — un
modèle dédié, distinct d'`EpisodeServiceRequest` (plan Réception uniquement).
Un `CareOrderItem` sélectionne un `CatalogItem` `SERVICE`/`CARE` explicitement
`clinician_orderable`, jamais déduit du nom/code. La création réutilise
uniquement `CreateEpisodeOrientationAction` ; pour un passage `NORMAL`,
l'orientation Médecine active est terminée avant l'ouverture de Soins (une
seule orientation clinique active à la fois), tandis qu'une Urgence conserve
ses files parallèles. `requires_return_to_medicine`, choisi par le médecin à
la demande, gouverne ensuite la complétion Soins : retour vers une nouvelle
Consultation Médecine (la première n'est jamais réécrite) ou
`PENDING_SETTLEMENT` selon la même règle que l'ADR-054. La facturation d'un
acte demandé suit le circuit existant, déclenchée par l'enregistrement du
`CareRecordProcedure`, jamais par le `CareOrder` lui-même. Voir ADR-055.

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

Les espaces `/surgery` et `/anesthesia` sont indépendants et respectivement
protégés par `surgery.view` et `anesthesia.view`, mais partagent le même dossier
chirurgical afin de préserver la continuité. Les deux interfaces sont guidées
par étapes. Les interventions sont choisies dans le catalogue `SURGERY` avec
instantané du libellé ; les éléments d'anesthésie suivent une liste contrôlée
avec instantané de leur code, libellé et catégorie. Voir ADR-048.

La Chirurgie ne peut pas encaisser.

Le rapport par acte `Prévu / Réel / Écart / Dette NP` appartient à Finance et
ne peut être alimenté qu'à partir des prestations facturables, factures et
paiements de Réception/Caisse. Tant que ce circuit n'est pas relié, aucune
valeur `Ar0` ne doit être fabriquée depuis les dossiers cliniques.

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
résout le barème `STANDARD` ou `MUTUAL` selon le contexte financier de l'Episode
et crée son instantané ; le navigateur ne fournit jamais un prix fiable. Les
deux grilles sont historisées indépendamment. Un tarif mutuelle manquant ne
reprend jamais le tarif standard : la demande clinique et l’orientation sont
conservées, mais la facturation reste en attente. `PAYER PLUS TARD` produit une facture
validée avec solde dû ; `PAYER MAINTENANT` exige une caisse ouverte et produit
facture, paiement, mouvement de caisse et reçu. Aucun reçu n’existe sans
encaissement réel. Une urgence ne dépend jamais de cette sélection ou du
paiement. Voir ADR-028 et ADR-031.

Le `Patient` est désormais une identité permanente. La prise en charge
financière est configurée indépendamment sur chaque `Episode` avec `SELF`,
`MUTUAL`, `STAFF` ou temporairement `NULL`. `MUTUAL` référence une
`EpisodeMutualCoverage` qui réutilise `MutualOrganization` et en fige UUID, nom
et taux. `STAFF` référence une `EpisodeStaffCoverage` et le véritable
`Employee`, sans dupliquer ses données RH. `PatientStaffLink` confirme
l'identité mais ne choisit jamais automatiquement le régime financier.

`Patient.patient_type` et `PatientMutualCoverage` sont legacy : ils restent
consultables pour les anciens dossiers mais ne pilotent plus la tarification
d'un nouveau passage. Un épisode sans mode ne reçoit aucun fallback silencieux
depuis le Patient ; seuls ses snapshots historiques existants peuvent être
relus. Après création d'une prestation facturable ou d'une facture, le contexte
ne peut plus être remplacé par le flux normal. Voir ADR-051.
Le flux d'arrivée laisse `patient_type` à sa valeur legacy par défaut `STANDARD`
pour les nouvelles identités et porte le choix `MUTUAL` ou `STAFF` sur l'Episode.

L'estimation préalable est read-only, sans Patient ni Episode, et relit
uniquement le tarif `STANDARD` courant des prestations `SERVICE` facturables et
sélectionnables. Elle ne crée aucune donnée métier et ignore tout prix transmis
par le navigateur. Elle ne constitue jamais une facture.

Depuis ADR-053, le parcours normal de Réception commence par le besoin et non
par le financement : besoin, estimation temporaire, recherche/création du
Patient, création d'un Episode unique, choix `SELF`/`MUTUAL`/`STAFF`,
confirmation puis routage. Le catalogue initial exige aussi un routage
Réception configuré. La branche « Achat de médicaments uniquement » renvoie à
la Vente comptoir Pharmacie existante et ne duplique ni médicaments ni panier
dans Réception. Aucune analyse Laboratoire n'est activée par cette évolution.
Après création, l’URL de prise en charge contient l’UUID de l’Episode et un
brouillon serveur temporaire restaure la sélection après actualisation. Ce
brouillon est supprimé dès la confirmation et ne constitue aucune demande ou
écriture financière.

Après le choix du mode, `ReceptionFinancialPreviewService` recalcule côté
Laravel les montants brut, couvert et patient. La projection STAFF peut simuler
le crédit Bloc disponible, mais ne crée jamais de mouvement ; seule la création
réelle du `BillableItem` consomme le registre de façon idempotente. La projection
Employé fournie à Réception reste minimale et n'expose aucun historique de
crédit. La confirmation progressive utilise le règlement ultérieur : elle peut
créer une facture, jamais un paiement ou un reçu sans une action de Caisse.
Voir ADR-053.

Un acte réellement réalisé aux Soins et facturable produit son propre
`BillableItem` via `RecordBillableItemAction`, idempotent par rapport à la
demande de service planifiée à la Réception (pas de double facturation d'un
même acte prévu). Une erreur financière (tarif absent, contexte non résolu)
n'annule jamais l'acte clinique déjà enregistré. Un nouvel élément rejoint
automatiquement une facture existante du même passage tant qu'elle n'a reçu
aucun encaissement (`DRAFT` ou `VALIDATED` avec `paid_amount = 0`) ; une
facture `PARTIALLY_PAID`, `PAID`, `COVERED` ou `CANCELLED` n'est jamais
modifiée silencieusement. Voir ADR-054.

Après la clôture de la consultation, le passage reste en
`PENDING_SETTLEMENT` et attend la décision administrative de Réception
(CDC §33.3, ADR-090). `/reception/sorties` (« Sorties & règlements »,
`episodes.settlement.view`) liste ces passages avec le contrôle du compte
du §33.2 — total facturé, payé, reste à payer, et les prestations encore
non facturées signalées à part. Le type de sortie est décidé par le solde,
recalculé sous verrou côté serveur : solde nul → « payé comptant » ; solde
restant → « dette validée » (dérogation, `debts.authorize`, non accordée à
RECEPTION par défaut) ou « évadé » (constat). La sortie clôt le passage
(`Episode.status = CLOSED`), n'encaisse jamais rien — l'encaissement reste
à `/cash` (ADR-012) — et ne touche aucune donnée clinique. Un solde
restant crée une `PatientDebt` immuable portant les informations
obligatoires du §33.3 ; une évasion n'efface jamais la créance (§34.2
règle 9) et ne nomme ni responsable ni autorisateur. `patient_debts` ne
porte aucun statut de règlement : le CDC n'en définit aucun. Une évasion
survenue avant la sortie médicale n'est pas enregistrable — elle exigerait
d'annuler des orientations cliniques actives, règle absente du CDC. Voir
ADR-090.

La page `/passages/{episode}` (« Détail du passage ») agrège en lecture seule
orientations, fiche Soins, consultations Médecine et facturation d'un même
passage, déjà accessibles séparément par module. Chaque section reste
protégée côté serveur par la permission qui possède réellement la donnée ;
`patients.view`, qui protège la route elle-même, ne suffit à en exposer
aucune. Voir ADR-054.

---

# UI

Design system :

```text
shadcn-vue (reka-ui + Tailwind)
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
shadcn-vue
   │
   ▼
Tailwind CSS
```

shadcn-vue **est** le design system (ADR-099, remplace l'ADR-018 et achève
l'ADR-091). Tout écran neuf ou retouché est écrit avec la couche
`resources/js/Components/Shadcn`, les tokens sémantiques RIVO, les icônes
`lucide-vue-next` et `cn()`. DashWind n'est plus la base : c'est un reliquat,
conservé uniquement là où personne n'est encore repassé, et aucun nouveau
composant DashWind (`Components/UI/Icon.vue`, classes `nk-*`, `ni ni-*`,
Headless UI) ne doit être introduit. Le remplacement reste progressif, écran
par écran, et une primitive est ajoutée à la demande — jamais par exécution
de l'initialiseur, qui réécrirait Tailwind. Aucune autorisation ni règle
métier n'est modifiée par cette migration.

---

# Décision client du 22/08/2026 — accueil patient

ADR-030 remplace le parcours uniforme de l'ADR-029. Depuis ADR-051 et ADR-053, les anciennes
catégories permanentes sont legacy : le mode financier est choisi par Episode
(`SELF`, `MUTUAL`, `STAFF` ou temporairement `NULL`) après la création du
passage, puis les désignations
configurées pilotent le parcours clinique (`MEDICINE_DIRECT`,
`CARE_THEN_MEDICINE` ou
`CARE_ONLY`). Depuis ADR-056, l'urgence est décidée seulement après la création
de l'Épisode : Réception peut requalifier le passage à l'étape Prise en charge,
et Médecine pendant une Consultation active. Seul cet Épisode devient
`EMERGENCY`; le Patient et ses autres passages restent inchangés. Une fois
requalifié, il est visible immédiatement aux Soins et en Médecine.
Une consultation spécialisée déjà identifiée est `MEDICINE_DIRECT`; la
consultation générale reste `CARE_THEN_MEDICINE`.

Les nouveaux numéros humains sont annuels pour le patient (`M-26-0001`) et
ordinaux par patient pour les passages (`M-26-0001-01`). Les UUID restent les
identifiants publics. Le dossier Employé est distinct de `users`; la Réception
ne fait qu'une recherche minimale et un lien patient-employé. Les pièces de
mutuelle sont privées et limitées à cinq.

La demande clinique doit être conservée indépendamment de la facturation. Pour
un Episode STAFF lié à un employé actif et éligible, chaque ligne du catalogue
porte une politique Personnel explicite : `ORDINARY_FULL_COVERAGE`,
`BLOCK_CREDIT`, `NOT_COVERED` ou `UNCLASSIFIED`. Le module `SURGERY`, le code et
le libellé ne permettent jamais de déduire la politique. Les prestations
ordinaires éligibles sont couvertes à 100 % ; `BLOCK_CREDIT` consomme le crédit
manuel disponible de l’Employee et laisse l’excédent au patient ;
`NOT_COVERED` laisse le brut au patient. `UNCLASSIFIED` maintient uniquement la
résolution financière en attente, sans bloquer le parcours clinique. Le montant
brut demeure historisé : la couverture/crédit RH/Finance ne peut jamais être
simulé par un tarif nul, une remise arbitraire ou un faux paiement.

Le crédit Bloc est un registre immuable attaché à `Employee` : allocations,
consommations et réversions conservent leurs soldes avant/après, références,
clé d’idempotence, motif, auteur et date. Le dossier Employee est verrouillé en
transaction avant toute consommation ; un `BillableItem` ne peut débiter qu’une
fois. Une annulation ajoute une réversion et ne supprime jamais l’historique.
L’allocation est exclusivement manuelle et configurable par un utilisateur
Administration/RH autorisé. Aucune période ou règle de renouvellement automatique
n’est définie. Voir ADR-052.

Les tarifs `STANDARD` (« Sans mutuelle ») et `MUTUAL` sont des montants bruts
propres à chaque site. `STAFF` n'est pas une grille tarifaire : son avantage est
calculé séparément. Le PDF Ambondromamy de juin 2023 confirme les deux grilles,
mais reste une référence historique non importée automatiquement car plusieurs
lignes sont ambiguës ou variables.

Le 23/08/2026, le client a précisé que le taux de prise en charge dépend de
l'organisme : la majorité couvre 100 %, tandis que certaines conventions
couvrent par exemple 80 % et laissent 20 % au patient. Le tarif `MUTUAL` reste
le montant brut commun au site ; le taux appartient à `mutual_organizations`.
La Réception/Caisse n'encaisse que la part patient. Une couverture à 100 %
valide la facture comme prise en charge, sans paiement ni reçu fictif. Les parts
brute, mutuelle et patient sont figées sur le passage, la prestation facturable
et la facture afin qu'une modification future de convention ne recalcule jamais
l'historique. Voir ADR-047.

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
Le relevé facultatif inclut une tension systolique/diastolique unique en mmHg,
la fréquence cardiaque, la SpO2, la température en °C et le statut « diabète
connu » à trois états (non renseigné, non, oui). La paire de tension est
toujours complète et cohérente. Ces données appartiennent au passage et
nécessitent `vitals.*` ; elles ne sont pas rendues obligatoires pour chaque
acte. Une FC inférieure à 60 bpm déclenche une alerte de dépistage non
bloquante. Pour l'adulte elle devient rouge sous 50 bpm ; pour un mineur toute
valeur sous 60 bpm est rouge et doit être interprétée selon l'âge et la
tolérance clinique. La SpO₂ est signalée en orange de 93 à 94 % et en rouge
à 92 % ou moins. La température est signalée en orange de 35 à 35,9 °C ou de
38 à 39,9 °C, puis en rouge sous 35 °C ou à partir de 40 °C. Ces alertes
restent non bloquantes et s'affichent sous forme d'une ligne compacte sous
chaque champ. La tension utilise le même rendu : basse sous 90/60, élevée dès
130/80, très élevée dès 140/90 et rouge au-dessus de 180/120. Elle doit être
saisie en mmHg complet (`170/120`, pas `17/12`). Voir ADR-038 à ADR-041.

Le parcours Médecine est porté par l'orientation du passage. Sa prise en charge
ouvre une consultation qui réunit les demandes de Réception et la transmission
Soins, puis historise motif, examen clinique, hypothèses, diagnostic final et
prescriptions. Les diagnostics sont append-only ; seul l'auteur d'une saisie
erronée peut l'annuler. Le système conserve une trace séparée avec auteur et
date, sans demander de motif libre et sans modifier ni supprimer le diagnostic
original. Une prescription est annulée avec motif plutôt
que supprimée. La sortie médicale possède ses propres types et
complète l'orientation Médecine, mais ne clôt jamais le passage administratif et
ne dépend jamais du solde du patient. Les demandes Labo, Soins, Chirurgie,
Hospitalisation et Transfert ne peuvent être simulées par une simple sélection :
elles exigent leurs workflows dédiés. Voir ADR-035.

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

En développement local, la Super Administration reste sur `:8000` et les API
cliniques isolées se lancent avec `composer local:apis` sur les ports 8001 à
8003. Chacune utilise sa propre base SQLite sous `storage/app/local-sites/`.
Ne jamais remplacer ce banc local par une connexion directe à la base du
portail ; voir ADR-043.

Les organismes de mutuelle et partenaires sont administrés séparément dans
chaque base clinique via l’API du site et `mutual_organizations` (ADR-045).
« Sans mutuelle » désigne la grille tarifaire `STANDARD`, et « Avantage
Personnel » relève du dispositif RH/Finance : aucun des deux ne doit être créé
comme organisme. La grille `MUTUAL` reste commune au site tant que le client
n’a pas validé une convention tarifaire distincte pour chaque organisme. Chaque
organisme porte en revanche son taux de couverture contractuel, 100 % par
défaut, importable et exportable en Excel avec son reste patient calculé. Les
deux grilles tarifaires `STANDARD` et `MUTUAL` sont elles aussi importables et
exportables en Excel par site. Voir ADR-047.

Les sélections multiples du portail central restent limitées à 100 UUID d’un
seul site. Adresses, désignations et organismes autorisent un archivage ou une
restauration atomique, idempotente et auditée. Le Stock autorise seulement
l’export Excel ciblé avec tous les lots ; une sélection UI ne crée jamais un
mouvement ni un ajustement de quantité. Voir ADR-046.

Lire également :

```text
docs/CDC_REFERENCE.md
docs/DECISIONS.md
docs/ROADMAP.md
```
