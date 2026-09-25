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

Le poste de gardiennage (`/guarding`, `guarding.*`) contrôle la sortie des
patients : il constate un départ déjà décidé par la Caisse (ADR-090), il ne
la décide jamais. Ce catalogue de permissions existait depuis l'origine, seedé
au profil GUARD, sans qu'aucune route ne le vérifie — la seule tuile qui
prétendait l'ouvrir pointait en réalité vers le registre des visiteurs
(`visitors.*`, ADR-023), lui-même sans aucune entrée de menu pour Réception
qui en détient pourtant le droit. `RecordExitControlAction` refuse un contrôle
sans sortie administrative prononcée, une sortie « évadé » (déjà partie sans
passer la porte) et un second contrôle du même passage (contrainte d'unicité
sur `episode_id`). Voir ADR-116.

**Paramètres de l'application, propres à chaque site** (ADR-184). `app_settings`
(une ligne par base) porte nom, logo, icône, couleur principale, écriture de
l'Ariary (Ar / Ariary / MGA, position, décimales — aucun montant converti),
tranches d'âge des patients, directeur général et signature, NIF, STAT,
coordonnées et compte bancaire. Une colonne vide laisse `config/rivo.php`
s'appliquer ; `App\Services\Settings\AppSettings` résout et alimente les props
partagées `site.*` (`brand`, `documents`, `iconUrl`, `currency`, `ageBands`),
l'en-tête HTML (favicon, variables CSS de la couleur) et `formatMoney`. Le
portail règle un site par `/api/v1/super-admin/app-settings` (`settings.view` /
`settings.update`, audité) et se règle lui-même dans sa base ;
`/super-admin/settings`. Le logo et l'icône sont servis par `/branding/{kind}`
sans connexion ; la signature n'a aucune adresse publique et entre copiée
(`data:`) dans les documents RH, sur la case « Apposer la signature ». Tranches
d'âge : bébé ≤ 1 an, enfant ≤ 15 ans par défaut ; à la création d'un patient,
bébé ou enfant → profil enfant (champs d'adulte refusés), bébé → date de
naissance exacte exigée, civilité contraire à l'âge refusée
(`PatientAgeRules`, `utilities/patientAge.js`). Amendement du 2026-09-24 :
`app_tagline` (devise, `site.tagline`, page de connexion ; défaut = l'ancienne
phrase en dur) et `search_engines_hidden` (masquée par défaut, `rivo.search_engines.hidden`) :
robots.txt servi par `RobotsTxtController` (`public/robots.txt` retiré), balise
`<meta name="robots">` et en-tête `X-Robots-Tag` sur chaque réponse
(`ApplySearchEngineVisibility`, middleware global). Amendement bis : modèles
d'écran par site — `auth_template` (COVER / SPLIT / CENTERED, enveloppe unique
`Components/Auth/AuthShell.vue`, props `site.authTemplate` et `site.authCoverUrl`),
image de fond (`background`, `/branding/background`), `profile_template`
(SIDEBAR / BANNER, `site.profileTemplate`). « Mon profil » `/profil`
(`ProfileController`) : lecture du compte et des droits effectifs, et
changement de son mot de passe (`ChangeOwnPasswordAction` : ancien exigé,
`SecurePassword`, autres sessions fermées, audit `user.password.change`) ;
nom, email, rôle et droits restent à l'administration (ADR-022).

**Apparence et chargement** (ADR-185). Le thème se choisit Clair / Système / Sombre
(`ThemeModeSwitcher`, `stores/theme.js` : `mode` gardé sur le poste, `resolved`
suit l'appareil pour « Système ») ; `app.blade.php` pose `dark` avant le premier
affichage. Pendant un changement de page (GET sans `preserveState`, ni partiel,
ni préchargement), `AppLayout` montre `PageSkeleton` — forme choisie par
`utilities/pageSkeleton.js` sur l'adresse visée — après 200 ms, l'ancienne page
restant montée et cachée (`usePageLoading`, installé dans `app.js`).

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

**Un socle de rôle ne dit pas à lui seul ce qu'un compte peut faire** (ADR-150). Constat : le socle `RECEPTION` affichait « Demande d'hospitalisation 0/3 » et le compte connecté voyait pourtant le module, les crayons du régime et « Prononcer la sortie ». Vérification en base : le socle n'y était pour rien — `user@rivo.test` (ADR-086) porte 122 `ALLOW` et 166 `DENY` individuels, dont `hospitalization.view`, `hospitalization.update`, `hospital_diet.record` et `medical_discharge.create`, et `DENY > ALLOW > socle` (ADR-033) a fonctionné exactement comme prévu. L'audit montre aussi que le socle a été réenregistré quatre fois depuis le portail le même jour, chaque `sync` remplaçant le socle entier (ADR-064) — c'est ainsi que le `hospitalization.view` de l'ADR-147 a été retiré, décision d'administration non rétablie. Le défaut réel était de présentation : `users_with_exceptions_count` accompagne désormais chaque rôle et l'éditeur de socle l'affiche (« N comptes portent des exceptions individuelles, qui l'emportent sur ce socle — vérifiez dans Exceptions par compte »). Aucune permission, route ni règle de résolution n'est touchée ; un compteur, pas une interdiction.

**Un droit se cherche sous le nom que l'écran lui donne** (ADR-151). « Prononcer la sortie » d'un séjour est gouverné par `medical_discharge.create`, libellé « Prononcer une sortie médicale » et rangé sous « Sortie médicale » : chercher « hospitalisation » ne le trouvait jamais, donc on ne pouvait pas le retirer depuis le portail. Trois libellés nomment désormais les modules où le droit agit réellement (`medical_discharge.create`, `consultations.create`, `diagnoses.create`, élargis par les ADR-113/114/147/148), et la catégorie aussi — le rail se cherche sur le libellé et le code. Le `name` ne change jamais (ADR-101). Migration `2026_10_11_090000`, sans retour arrière.

**Un droit refusé au compte se voit sur la case du socle** (ADR-153, complète ADR-150). Constat : socle enregistré avec `hospitalization.view`, et rien dans le menu du compte — parce qu'il portait un `DENY` individuel, qui l'emporte (ADR-033). Chaque case du socle porte désormais « Refusé à N compte(s) — le cocher ici ne l'ouvrira pas pour lui », calculé dans le navigateur depuis `users`/`permission_overrides` déjà servis (aucune requête, aucun champ serveur), et seulement pour les comptes **du rôle réglé**. Rappel du piège : « Exceptions par compte » a trois états — *Selon le rôle* (aucune ligne), *Autorisé*, *Interdit* ; « Interdit » est un refus actif, pas un retrait, et lever un refus se fait en remettant **« Selon le rôle »**.

**Les permissions reviennent à un défaut explicite, jamais deviné** (ADR-173). « Réinitialiser le rôle » restaure le socle de `RolePermissionSeeder` pour un rôle standard, sans toucher aux exceptions de ses comptes ; un rôle créé depuis le portail n'a pas de faux défaut et ne propose pas l'action. « Réinitialiser le compte » retire toutes ses lignes `user_permissions`, `MANUAL` et `PROFILE` : il hérite alors seulement de son rôle. Son profil professionnel reste affecté, mais aucune recommandation n'est réappliquée silencieusement (ADR-033). Les deux commandes passent par l'API du site, demandent confirmation, revérifient `users.manage` ou `permissions.assign`, et auditent `role.permissions.reset` / `user.permissions.reset`. `User::effectivePermissionNames()` ne change pas.

**Un refus dit ce qui manque, et où le régler** (ADR-154). `403 · Cette action n'est pas autorisée.` ne permettait pas de comprendre qu'un droit coché au socle restait refusé par un `DENY` nominatif (ADR-033). `App\Support\RequiredAbilities` lit les `can:` de la route appariée, garde ce qui manque, et sert deux messages distincts : droit non accordé (→ socle ou exception) ou **refus personnel** (→ « Exceptions par compte », remettre sur « Selon le rôle »). Nommer la permission n'expose rien — l'application le fait déjà (« Non visible avec vos droits (maternity.view) »). Détail d'implémentation qui a coûté une mise au point : `$exceptions->map()` et non `render()`, le handler convertissant l'`AuthorizationException` en `HttpException` **avant** les callbacks de rendu. Vaut pour toutes les routes gardées par `can:` ; aucune autorisation n'est assouplie.

Le portail présente deux écrans distincts : `/super-admin/workspaces/users`
(comptes : identité, rôle, profil, activation) et
`/super-admin/workspaces/roles` (socle des rôles, exceptions individuelles,
référentiel des rôles). Les exceptions d'un compte ont leur propre chemin
d'écriture (`UpdateUserPermissionOverridesAction`) : le formulaire de compte
n'envoie plus `permission_overrides`, et la clé omise laisse les exceptions
intactes plutôt que de les effacer à chaque correction d'un nom. La
résolution reste `DENY individuel > ALLOW individuel > socle du rôle`.

**« Rôles & permissions » est un centre de gestion** (ADR-178, présentation
seulement — aucune route, Policy, API ni règle ne change). Trois onglets :
**Rôles** (liste des rôles en colonne à gauche, socle du rôle choisi à droite ;
créer, renommer, archiver, restaurer et réinitialiser s'y font sur place),
**Exceptions par compte** et **Catalogue des droits**. Les catégories sont
rangées dans 14 modules (`utilities/permissionCategories.js`, repli
« Administration & système » pour une catégorie inconnue) ; chaque
fonctionnalité est une ligne et ses droits tombent dans sept colonnes communes
— Voir | Créer | Modifier | Supprimer | Restaurer | Valider | Exporter —, le
reste gardant son libellé (`buildPermissionModules()`). Chaque fonctionnalité a
son icône. Modules en accordéons **exclusifs** (en ouvrir un referme les autres,
`useExclusiveModules`) avec « N / total » et « Tout sélectionner /
désélectionner », case à trois
états par ligne, recherche multi-mots sur libellé, code et verbe. Cocher remplit
un **brouillon** : une barre collante compte les changements, « Revoir »,
« Annuler », « Enregistrer les modifications ». Les confirmations ne restent
qu'à l'enregistrement de droits sensibles, aux réinitialisations (ADR-173), à
l'archivage (motif) et à l'abandon d'un brouillon (changer de rôle, de compte,
d'onglet, de site ou quitter la page, `useUnsavedChangesGuard`). L'adresse suit
la sélection (`?site=…&vue=comptes&compte=…`, `&role=…`). ADR-150, ADR-153,
ADR-154 et ADR-158 restent affichés. Un rôle n'a pas de description en base :
les rôles livrés ont une phrase fixe (`utilities/roleDescriptions.js`), la
rendre éditable reste à décider.

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

**Réceptionner n'est pas ranger** (ADR-175, amende ADR-097). Une réception
constate ce qui est arrivé — quantité, lot, péremption, remarque — et ne crée
plus aucun mouvement de stock : `goods_receipt_lines` porte `uuid`, `notes`,
`stocked_at` et `stocked_by`, et la marchandise entre au stock par un second
geste (`RecordReceivedStockAction`, `stock.entry`), qui appelle
`RecordStockEntryAction` inchangée. Tant que rien n'est rangé, quantité, lot
et péremption restent corrigibles et mettent à jour la réception **et** la
commande (`PurchaseOrder::refreshReceptionStatus()`) ; après, seule une
correction de stock tracée existe. Une ligne n'entre qu'une fois — elle est
verrouillée et refusée si `stocked_at` est déjà posé. Les lignes reçues avant
cette décision sont marquées entrées à leur date par la migration.

**Aucune date du système ne se saisit** : commande, envoi, réception et
entrée en stock sont datés par le serveur. Les dates réellement externes
(péremption, date de facture, échéance, livraison attendue) restent saisies,
mais par raccourci — aujourd'hui par défaut, « 30 jours », « Sous 1
semaine » — jamais un calendrier vide. La facture fournisseur peut
accompagner la réception (même formulaire, `supplier_invoices.due_date`
facultative) ou attendre : la réception est alors « facture en attente ».

**Un tableau plein, une recherche qui filtre** : la commande d'achat affiche
tout le catalogue du fournisseur avec sa quantité en face, et l'entrée en
stock affiche la marchandise réceptionnée déjà remplie ou, sans commande, le
catalogue entier à cocher. Aucun écran n'attend une recherche pour montrer
ses données. L'entrée en stock est un écran unique à deux onglets, au lieu de
deux formulaires qui redemandaient les mêmes informations.

**Un brouillon de commande part à la corbeille** avec son motif
(`purchase_orders.delete`/`.restore`, `TrashCategory::PurchaseOrder`,
accordées à aucun rôle par défaut) ; une commande envoyée s'annule, elle ne
se jette pas. Plus aucun `confirm()` du navigateur dans ces parcours : une
fenêtre de confirmation nomme ce qui va se passer. Voir ADR-175.

**Réceptionner appartient au socle `PHARMACY`** (ADR-176, amende ADR-098).
`purchase_orders.view`, `goods_receipts.view` et `goods_receipts.create` y
rejoignent le stock : recevoir la marchandise est un acte physique de cette
pharmacie, et sans `purchase_orders.view` elle enregistre une réception sans
jamais trouver la commande à réceptionner. Commander, facturer et tenir le
dossier fournisseur restent accordés nominativement (ADR-098) ; la facture
hors socle laisse simplement la réception « facture en attente » (ADR-175).
**Conséquence signalée** : le montant d'une commande — donc le prix d'achat —
devient lisible par tout compte Pharmacie, l'ADR-174 ne masquant le coût que
sur les écrans de réception et de stock ; étendre ce masque aux commandes
reste à décider.

**« Envoyer la commande » est la validation** — il n'existe aucune étape
séparée : Brouillon → Envoyer (`purchase_orders.submit`) → Commandée →
Réceptionner. « Annuler » reste possible jusqu'à la réception (ADR-097 : un
fournisseur peut ne jamais livrer), mais passe en action discrète à droite,
derrière l'action de l'étape. Une commande sans bouton **nomme le droit
manquant** au lieu de son seul statut, qui se lisait « plus rien à faire »
(ADR-154). Migration `2026_10_26_090000`, à jouer sur chaque site (ADR-064).

**Une commande annulée part à la corbeille** (ADR-176, amende ADR-175) : comme
un brouillon, avec son motif et restaurable (ADR-061) ; son annulation n'est
jamais effacée, et la suppression définitive reste refusée dès qu'un envoi,
une réception ou une facture existe. Une commande vivante s'annule d'abord.
Le geste existe aussi **depuis le portail**, qui n'en avait aucun :
`DELETE /api/v1/super-admin/pharmacy/suppliers/{uuid}/orders/{uuid}` appelle la
même `TrashPurchaseOrderAction`. Droit : `purchase_orders.delete`, distinct de
`cancel`, accordé à aucun rôle par défaut.

**« Commandé » n'est pas « en rupture »** (ADR-176, ADR-098). Un produit entré
au catalogue par une commande s'affichait « En rupture » avant toute
livraison — troisième signalement du propriétaire sur ce point.
`MedicineStockOverviewService` distingue désormais `NEVER_RECEIVED` (aucun lot
n'a jamais existé) de `OUT_OF_STOCK` (des lots ont existé, il n'en reste
rien) ; l'état se lit sur `lots_count`, jamais sur une colonne à tenir à jour.
Un produit jamais reçu **quitte la liste courante** — ni « Tous », ni « En
rupture », ni « Sans prix de vente » — et vit dans l'onglet « Commandés,
jamais reçus ». La carte « Disponibles » devient **« Disponibles sans
alerte »** : elle comptait 0 pendant que trois produits avaient du stock,
comptés sous « Péremption proche » — les catégories restent exclusives
(ADR-119, ADR-120), c'est le libellé qui mentait.

**Ce qu'une livraison réelle fait subir à une commande** (ADR-179, complète
ADR-097/175/176). **La confirmation du fournisseur est une trace** — date,
référence, document, auteur — sur `purchase_orders`, enregistrable dès le
brouillon, corrigeable et retirable : **rien n'en dépend**, une commande sans
elle se réceptionne exactement comme avant (un test le fixe), parce que
beaucoup de fournisseurs ne confirment jamais. Droit `purchase_orders.confirm`,
accordé à aucun rôle du site par défaut (ADR-098) ; la *lire* suit
`purchase_orders.view`. **Elle s'enregistre aussi depuis le portail** — qui
passe les commandes, donc reçoit l'accusé (ADR-098) : sans ce chemin, le droit
accordé au Super Admin n'aurait commandé rien (ADR-101). L'API du site
(`orders/{uuid}/confirmation`, POST et DELETE) appelle l'Action de la clinique
avec un `CatalogActor` distant ; le document part en multipart, reste sur le
site, et le portail ne fait que le relayer au navigateur (`pharmacySupplierFile`,
partagé avec le fichier de catalogue). Constater une rupture ligne à ligne
n'existe qu'au site : c'est un constat de réception (ADR-176). Clôturer toute
la commande existe des deux côtés — c'est une décision d'acheteur.

**Un article jamais livré ne bloque plus la commande à vie** : `purchase_order_lines`
porte `shortage_at/_reason/_by`, et `refreshReceptionStatus()` traite une ligne
en rupture comme soldée. Avant, une seule ligne non livrée laissait la commande
« Partiellement reçue » pour toujours, donc éternellement dans « À
réceptionner », sans aucun moyen d'abandonner un reliquat. Rien n'est effacé :
quantité commandée, prix et reçu restent. La ligne quitte l'écran de réception
(`quantityRemaining()` renvoie 0) et **ne peut plus être reçue** — la recevoir
rouvrirait en silence un reliquat déclaré perdu ; il faut d'abord revenir sur
la rupture, ce qui rouvre la commande. `PurchaseOrderStatus::Closed`
(« Clôturée ») est ajouté parce qu'aucun statut ne disait la vérité : ni
« Reçue » (la commande n'a pas été livrée en entier), ni « Annulée » (elle a
été envoyée, souvent livrée en partie, et peut porter une facture). Elle quitte
« À réceptionner » d'elle-même et les filtres la reprennent sans code nouveau
(`PurchaseOrderStatus::cases()`). Constater une rupture relève de
`goods_receipts.create` (ADR-176 : qui a la livraison sous les yeux voit ce qui
manque) ; clôturer toute la commande de `purchase_orders.cancel` (renoncer à ce
qui reste dû est une décision d'acheteur). Le droit étant le même que pour
annuler, `CancelPurchaseOrderAction` refuse désormais les trois états de
`PurchaseOrderStatus::isClosedOut()` : annuler une commande clôturée dirait
qu'elle n'a jamais eu lieu et la rendrait jetable (ADR-176), alors qu'elle a été
envoyée et souvent livrée en partie. La sortie existe et le message la nomme —
retirer les ruptures rouvre la commande, puis elle s'annule. **Descendre la quantité à 0 décoche
la ligne** et dit « pas dans cette livraison » — elle était bornée à 1 sans que
rien ne l'explique.

**Chez qui d'autre trouver l'article** : `SupplierAlternatives` nomme les
fournisseurs qui proposent **le même médicament** — prix d'achat versionnés et
catalogues actifs —, jamais un équivalent de la même famille : le référentiel
ne porte aucune équivalence thérapeutique, et la deviner serait une
substitution qu'ADR-052 interdit. Le service ne commande rien ; passer commande
reste un geste du dossier fournisseur.

**Un article livré hors commande se constate, il ne réécrit pas la commande** :
`goods_receipt_lines.purchase_order_line_id` devient nullable, la commande
garde ses lignes, son montant et son statut (ADR-098 — une commande envoyée ne
se modifie plus). Le produit se désigne comme à la commande (médicament de la
clinique, ou ligne du catalogue actif de ce fournisseur, qui entre alors au
catalogue sous `medicines.create`) ; son prix est celui que le fournisseur cote
aujourd'hui, sinon celui lu sur le bon de livraison (`stock.cost.record`). Rien
ne le borne en quantité. Il entre au stock comme les autres (ADR-175).

**L'écart de montant à la facture s'affiche, il ne bloque pas** : le montant
proposé reste celui de **ce qui est réellement arrivé** — sur une livraison
partielle c'est lui qui est juste — et ce que la commande engage, avec le
déjà-facturé, est servi à côté pour que l'écart se voie. Le papier du
fournisseur fait foi (ADR-175). Les trois montants exigent `stock.cost.view`
(ADR-174).

**La commande part par la messagerie de la personne** : à l'envoi, et depuis la
page d'une commande (« Envoyer par e-mail », jamais sur un brouillon ni sur une
commande annulée), un brouillon `mailto:` s'ouvre avec l'objet et le corps déjà
écrits — référence, date, livraison souhaitée, un produit par ligne avec son
unité et son code, total, remarque, signature de qui écrit et de son site
(`utilities/supplierOrderMail.js`). RIVO n'envoie rien : un envoi par le serveur
demanderait SMTP par site, expéditeur, pièces jointes et file d'attente, et une
commande « envoyée » ne prouverait toujours pas qu'un e-mail est parti — décision
à part, signalée. Sans adresse au dossier du fournisseur, rien n'est proposé et
la commande part comme avant : l'e-mail accompagne l'envoi, il ne le conditionne
pas. Le numéro garde sa casse — la phrase mettait la référence entière en
minuscule (« notre commande abc-000012 »), alors que c'est l'identifiant que le
fournisseur cite en retour. Aucune permission nouvelle (`purchase_orders.submit`).

**« Entrée sans commande » est retirée** (ADR-182, 2026-09-24 — renverse
l'ADR-179 §7, qui l'avait conservée après analyse pour le don, le stock de départ
et le dépannage d'un confrère ; arbitrage explicite du propriétaire). Plus rien
n'entre au stock hors d'une livraison réceptionnée ; l'article livré hors
commande (ADR-179 §4) reste, puisqu'il fait partie d'une livraison. Un stock de
départ ne se reprend plus que par l'import Excel central (ADR-042).

**L'entrée en stock est un seul écran, donc un seul geste** (ADR-180, amende
ADR-175 ; ADR-182). Ce qui a été réceptionné y arrive rempli et coché
(fournisseur, commande, lot, péremption, quantité), regroupé par livraison, et
rien d'autre : ni catalogue à cocher, ni provenance à saisir. Avant toute
réception l'écran est vide et renvoie aux commandes à réceptionner ; une ligne
rangée le quitte et apparaît dans « Médicaments & stock » — les deux écrans se
suivent. L'accès dit ce qui attend (« Entrée en stock · N à ranger », sur
« Médicaments & stock » et l'accueil Pharmacie, `ReceivedStockQueue::count()`).
`POST /pharmacy/stock/entries/batch` n'accepte que `lines` (lignes de
réception), dans **une transaction** : une ligne refusée n'en laisse entrer
aucune. Les champs de l'ancienne entrée libre (`entries`, `origin`,
`supplier_uuid`, `destination`, `reason`, `received_at`) et le prix d'achat
(`lines.*.unit_purchase_price`, ADR-174) sont **refusés en les nommant**.
Retirés : `POST /pharmacy/stock/entries`, `StoreStockEntryRequest`,
`RecordStockEntriesAction`, `/entries/received`, `StoreReceivedStockRequest`, et
les boutons « Entrée de stock pour … » de chaque produit (ils promettaient une
entrée par produit). Le moteur `RecordStockEntryAction` reste, appelé par
`RecordReceivedStockAction`. Les lots déjà détenus restent proposés à l'entrée
(ADR-176) : la file des réceptions sert `known_lots` par ligne, avec les droits
`stock.lots.view` / `stock.expiration.view`. Les tests qui ont besoin de stock
passent par le vrai chemin (`tests/Feature/Pharmacy/Concerns/ReceivesDeliveries`).
Aucune permission nouvelle (`stock.entry`).

**L'article livré se choisit dans le catalogue du fournisseur, et réceptionner
suffit** (ADR-182, amendement du 2026-09-24). À la réception, l'« article livré
hors commande » propose le catalogue ACTIF du fournisseur, et lui seul
(`ProcurementFormOptions::deliveredCatalogLines`) ; un produit de la clinique
ajouté hors commande doit être vendu par ce fournisseur
(`MedicineSupplier::suppliedMedicineIds()`). Réceptionner une ligne non reprise
fait entrer le produit au catalogue de la clinique avec le seul
`goods_receipts.create` : `CatalogActor::receivingDelivery()` délègue
`medicines.create`, `catalog.items.create` et `medicine_supplier_offers.*`
pour ce geste seulement — ni famille nouvelle, ni prix de vente, et un DENY
individuel l'emporte (amende ADR-024/098 pour la réception ; la commande garde
sa règle). Une ligne déjà rattachée ne crée rien. L'entrée en stock se lit par
livraison (rail à gauche, la plus ancienne d'abord) et par état (cartes « À
ranger », « Nouveaux produits », « Sans prix de vente ») ; « Ranger cette
livraison » n'envoie que les lignes cochées de la livraison choisie. Les dates
de facture fournisseur sont calculées en heure locale (`localToday()`,
`toLocalDateInput()` dans `utilities/date.js`), jamais par `toISOString()`.

**Comparer deux produits que les fournisseurs ne nomment pas pareil** (ADR-181,
complète ADR-098 et ADR-179). Le comparateur ne réunissait deux fournisseurs
sur une ligne que si leurs libellés se normalisaient à l'identique : « ALCOOL
ETHYLIQUE 70% 1L » et « Alcool 1l 70° » restaient deux lignes, deux prix,
aucune comparaison — et commander la ligne du fournisseur aurait créé un
**second produit** avec son propre stock. `ProductLabel::looksLikeSameProduct()`
propose le rapprochement sans jamais le décider : les nombres font le produit
(500 mg ≠ 1 g, 125 ml ≠ 250 ml, 18G ≠ 25G), une négation d'un seul côté écarte
(« non stérile »), les mots doivent s'emboîter (« Gants taille M » ≠ « taille
L »), et « 500mg » vaut « 500 mg ». Elle ne lit ni DCI ni dosage : une ligne de
catalogue n'en porte pas. Le comparateur affiche « À rapprocher · N » (compté
par le serveur), et sur la ligne concernée « C'est le même produit ? » ouvre une
fenêtre qui met les deux libellés côte à côte, montre les prix déjà connus du
produit visé et exige un motif. Une ligne **sans prix** n'est pas proposée :
rattacher est ce qui crée le prix d'achat, et l'action le refuse sans montant.
`POST /api/v1/super-admin/pharmacy/suppliers/{f}/catalogs/{c}/items/{i}/link`
appelle l'Action de la clinique avec un `CatalogActor` distant — le portail
pouvait jusqu'ici **défaire** un rattachement sans jamais en faire un. Aucune
permission nouvelle : `medicine_supplier_offers.create`/`.update`, revues par
l'Action sur l'acteur distant. **Amendement du 2026-09-24** : la règle vérifiait
nombres et mots séparément, chacun dans le sens qui l'arrangeait, et proposait
« Alcool 125ml 70° » ≈ « ALCOOL IODE SALICYLE IMRA 125ML » (70° d'un seul côté,
iodé salicylé de l'autre) ; elle exige désormais qu'un libellé dise tout ce que
dit l'autre, mots **et** nombres dans le même sens. Le comparateur filtre aussi
par couverture — « Chez les deux fournisseurs » (« chez plusieurs » au-delà) ou
« Seulement chez X », chaque produit dans une seule case — et par famille (celle
de la clinique, sinon celle que déclare le fournisseur, écrite comme celle de la
clinique quand elles se ressemblent), la liste rangée par famille. Règles dans
`utilities/supplierComparison.js`.

**Un prix d'achat ne survit pas au retrait de son catalogue** (ADR-183).
Pharmalife restait au comparateur (« 5 produits ») alors que ses catalogues
étaient à la corbeille : c'étaient les prix que ses lignes avaient créés en étant
rattachées, restés « en cours ». `SupplierCatalogPrices` porte la règle pour les
quatre actions (corbeille / restauration d'un catalogue, d'une ligne), qu'utilisent
clinique, portail et Corbeille : retirer **clôt** ces prix (jamais supprimés, comme
« Défaire un rattachement ») ; restaurer rétablit, en nouvelle version au même
montant, le prix d'une ligne encore rattachée si la paire (médicament, fournisseur)
n'a plus de prix en cours et que son dernier prix venait de cette ligne — un prix
fixé depuis n'est jamais écrasé. Migration `2026_10_28_090000` : les prix des
catalogues déjà à la corbeille sont clos à la date du retrait, audités
(`pharmacy.supplier_offer.withdraw`).

**La simulation locale d'approvisionnement est enfin exercée par un test.**
`DevelopmentProcurementSeeder` (ADR-098) n'est appelé par aucun autre seeder,
et rien ne l'exerçait : il ne se parsait plus (virgule manquante), passait un
`User` là où `SetMedicineSupplierOfferAction` et `LinkSupplierCatalogItemAction`
exigent un `CatalogActor` depuis l'ADR-098, et son compte de test avait perdu
`stock.cost.*` depuis l'ADR-174 — donc ne pouvait plus enregistrer un prix
d'achat à la réception, alors qu'il existe pour parcourir toute la chaîne
(ADR-086). Les trois sont réparés et un test lance la simulation.

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

**Un examen paraclinique demandé en consultation est facturé** (ADR-105).
`CreateLabRequestAction` et `CreateImagingRequestAction` ne créaient aucun
`BillableItem` : une NFS ou une échographie partait au service concerné et la
clinique ne comptait jamais ce qu'elle avait fait — alors que le **même**
examen sélectionné à la Réception est facturé depuis l'ADR-068. L'élément
rejoint le compte du patient **à la demande**, chaque ligne portant ce qu'elle
a produit (`billable_item_id`, nullable, jamais rétro-rempli), avec une clé
d'idempotence dérivée de l'UUID de la ligne. Un tarif absent ou un contexte
financier non résolu n'empêche jamais la demande de partir : la
régularisation appartient à la Réception (ADR-054, ADR-072).
`App\Services\Billing\ClinicalActBiller` porte cette règle une seule fois
plutôt qu'une copie par appelant. Retirer une demande (ADR-079) annule ses
`BillableItem` encore `PENDING` ; un élément déjà porté sur une facture n'est
pas détricoté — seule la Réception/Caisse touche un montant facturé.

**Le besoin de l'arrivée n'est ni redemandé, ni refacturé** (ADR-109,
complète ADR-105). Mme R. arrive pour une échographie obstétricale : la
Réception la planifie et la facture (ADR-068), l'en-tête de la consultation
l'affiche — et l'étape Paraclinique présentait un champ de recherche vide,
demandant au médecin de retrouver le même examen au catalogue. Ce n'était pas
qu'une ressaisie : depuis l'ADR-105, créer la demande facture, avec une clé
dérivée de la ligne — donc **un second `BillableItem` de 50 000 Ar pour la
même échographie**. `RecordBillableItemAction` savait déjà ne pas refacturer
une prestation planifiée (ADR-054), mais seulement en l'absence de clé
explicite, et l'ADR-105 en imposait une systématiquement.
`PlannedServiceBilling::unconsumedFor()` répond à la question qui manquait :
la demande **rattache** la prestation de l'arrivée quand elle existe et n'est
pas déjà portée par une autre ligne, et facture normalement sinon — un examen
redemandé après lecture du résultat reste un second acte. `planned_paraclinical`
sert au médecin ce qui reste à transmettre : l'écran le met dans la demande au
montage, le dit (« déjà porté au compte du patient »), et le laisse retirable.
Le serveur décide ce qui reste à transmettre ; rien n'est déduit d'un libellé
(ADR-052), et le médecin reste libre d'ajouter ce qu'il veut, avant comme
après son diagnostic.

**ECG et Échographie sont deux familles distinctes** (ADR-106).
`catalog_items.imaging_modality` (`CARDIOLOGY` | `ULTRASOUND`, nullable) la
porte, réglée au catalogue comme `reception_routing_mode` porte le parcours
Réception — **jamais déduite d'un code** : `HOLTER-ECG` est cardiologique
sans commencer par `ECG-`, et les deux `DOPPLER-*` sont des échographies,
soit trois examens sur vingt mal classés par n'importe quel préfixe
(ADR-052). Un examen non classé apparaît dans un onglet « Non classés »,
visible seulement s'il en existe un ; le ranger d'office le ferait
disparaître sans décision. La migration classe l'existant, le seeder classe
ce qu'il crée — sans lui un site neuf repartirait non classé (ADR-064). La
recherche est bornée à la famille ouverte.

**Transmettre une demande d'examen est un acte signé** (ADR-106). Un clic
envoyait l'ordre au service **et** facturait le patient (ADR-105) sans rien
annoncer. La confirmation nomme chaque examen — confirmer « 2 examens » sans
les voir n'est pas une signature consciente — et porte le nom du médecin. Elle
garde ce qui **ne peut pas** être déduit d'ailleurs : les deux phrases de
conséquence ont été retirées à la demande du propriétaire, elles restent
vraies sans être répétées à chaque envoi. Elle n'est pas fermable au clic
extérieur, et tous les chemins y passent, bouton comme touche Entrée. Elle
rappelle qu'un retrait reste possible tant qu'aucun résultat n'est saisi
(ADR-079).

**Une demande transmise ne retient plus la clôture** (ADR-105, amende
ADR-076). L'ADR-076 interdit de résoudre une étape « en effet de bord d'un
enregistrement » : la règle vise une saisie en cours, pas un ordre déjà parti
au Laboratoire. La création de la demande marque donc l'étape Paraclinique
`COMPLETED` (`completeParaclinicalFromRequest()`, silencieuse si la
consultation n'est plus éditable), et `blockersForClosure()` écarte cette
étape dès qu'une demande active existe — les consultations antérieures
portent des demandes sans étape résolue, et c'est le fait clinique qui
décide, pas l'état d'un écran (ADR-081).

**« Décision & clôture » ne dédouble plus sa navigation, et l'attente n'y
ressemble plus à un verrou** (ADR-106). Les trois sous-étapes portaient une
barre d'onglets `1 · 2 · 3` en tête **et** un pied « Précédent / Suivant » ;
les onglets étant toujours visibles et atteignant n'importe quelle section
d'un clic, le pied refaisait le même travail en suggérant un ordre imposé
qui n'existe pas. Il est retiré ; les onglets restent l'unique pilote de la
sous-étape et aucun n'est condamné par l'état du dossier — c'est l'étape 3
qui refuse, en nommant ce qui manque. Le bandeau « N résultats encore
attendus », lui, devient neutre : `awaitingResults` n'entre dans
`closure_blockers` à aucun moment — il ouvre une simple confirmation — mais
en ambre, juste au-dessus de l'étape de clôture, il se lisait « vous ne
pouvez pas conclure ». Même règle que l'ADR-102 pour un chiffre manquant :
un écran qui fabrique un obstacle là où il n'y en a pas coûte un passage
abandonné.

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

**Un décès prononcé a son registre** (ADR-107, complète ADR-035). Il ne
réapparaissait nulle part : la file Médecine ne montre que les prises en
charge en cours, et le passage rejoignait « Sorties & règlements » comme un
autre. `/deces` (`death_records.view`) liste les passages dont la sortie
médicale porte le type `DECEASED` et fait établir l'acte de constatation
(`death_records.create` — voir et signer sont séparés, comme l'ADR-090
sépare voir la file des sorties et prononcer la sortie). **La décision et
l'acte sont deux faits** : `MedicalDischarge` prononce, `DeathRecord`
constate, avec son propre auteur et sa propre date — celle du serveur.
Les trois valeurs cliniques sont préremplies depuis la sortie puis
corrigeables : le médecin qui constate signe ce qu'il écrit. Un seul acte par
passage, jamais avant le décès prononcé. **Le volet état civil — numéro
d'acte, déclarant, officier — n'existe pas** : le CDC n'en dit rien, et il
n'est pas inventé. Prononcer un décès conduit au registre plutôt qu'à
l'étape de clôture, sans rien changer à ce que la sortie fait (ADR-084,
ADR-096). Le registre n'encaisse rien et ne prononce aucun décès. L'acte suit la feuille papier de la clinique (amendement du 2026-09-18) :
filiation, CNI délivrée le/à et lieu de signature sont figés sur
`death_records` et n'écrivent jamais le dossier patient ; causes et
observations sont en texte riche assaini.

**Hospitalisation** (ADR-113, `/hospitalisation`, `hospitalization.view`).
Transmettre une demande d'hospitalisation **admet** le patient : un
`HospitalStay` s'ouvre, l'orientation est prise en charge et le passage passe
`HOSPITALIZED` — il reste donc hors de « Sorties & règlements » tant qu'il est
au lit. Seule la sortie médicale du médecin termine le séjour
(`DischargeHospitalStayAction`, même `MedicalDischarge` que la consultation,
diagnostic final toujours exigé). Retirer la demande annule le séjour tant que
sa fiche de régime est vide, et est refusé ensuite. La **fiche de régime**
(`hospital_diet_entries`, `hospital_diet.record`, Médecine et Soins) est une
grille jour/heure en texte libre (Thé/Pain, Sosoa/Brochette, Yaourt, Purée,
Observation), corrigeable jamais supprimée, sans aucun montant ; son en-tête
(N° dossier, allergies, tabac, motif) est repris du dossier et elle s'imprime
au format de la feuille papier.

**La demande d'hospitalisation se corrige rubrique par rubrique** (ADR-113, amendement du 2026-09-21) : un crayon par rubrique (motif, diagnostic d'entrée, résumé, traitement, consignes) et un pour la priorité, dans `StayRequestCard` ; une seule rubrique ouverte à la fois. `PUT /hospitalisation/{séjour}/demande` n'écrit plus que les champs envoyés (`sometimes`, ADR-074 « omettre n'efface pas ») : un champ envoyé vide s'efface, la priorité ne s'efface jamais, un envoi vide est refusé. Avant, l'action réécrivait les six champs à chaque envoi.

**La sortie d'hospitalisation reprend les diagnostics déjà consignés, et le séjour porte le sien** (ADR-147, complète ADR-113). Le formulaire exige un diagnostic coché mais n'en recevait aucun : « Aucun diagnostic posé — ajoutez-le dans « 1 · Diagnostic » » renvoyait à un écran qui n'existe qu'en consultation, et **aucune sortie n'était prononçable**. `HospitalizationController::diagnoses()` sert ceux des consultations du passage **et** ceux du séjour, avec leur origine, cochés d'office, uniquement avec `diagnoses.view` (sinon la liste n'est pas servie — jamais servie vide). L'invite devient un `prop` (`diagnosisHint`) : le formulaire est partagé, il ne renvoie plus à l'écran de l'autre module. **Un diagnostic conclu au terme du séjour vit sur le séjour** (`hospital_stay_diagnoses`, `RecordHospitalStayDiagnosisAction`, `POST /hospitalisation/{stay}/diagnostics`, `diagnoses.create`) : la consultation qui a demandé l'hospitalisation est le plus souvent close, et l'ADR-076 refuse toute écriture ordinaire après clôture — ce diagnostic n'en est pas une correction. Append-only (le modèle refuse `update` et `delete`), instantanés de catalogue, doublon refusé, et un séjour terminé n'en accepte plus. **La Réception lit le module** (`hospitalization.view` au socle RECEPTION) : détail, dossier, impression de la fiche ; sortie, séjour, régime, demande et diagnostic restent refusés, chacun par son propre droit — l'écran calculait déjà ses capacités, rien n'a eu à être verrouillé. Migration `2026_10_10_090000`.

**La visite de service : une vraie rencontre pendant le séjour** (ADR-148, construit ce que l'ADR-113 laissait hors périmètre). Un patient hospitalisé est examiné, prescrit, envoyé au laboratoire — rien n'était possible : le séjour ne portait qu'une fiche de régime et la consultation d'origine est close (ADR-076). `StartHospitalVisitAction` (`POST /hospitalisation/{stay}/visites`, `consultations.create`) enchaîne `CreateEpisodeOrientationAction` (`HOSPITALIZATION → MEDICINE`) puis `AcceptMedicineOrientationAction`, comme la Maternité qui renvoie au médecin (ADR-135), et redirige vers l'**assistant Médecine existant** : diagnostic, ordonnance et sa réservation FEFO, analyses, imagerie, ordre de soins, facturation et brouillon serveur — **aucun circuit dupliqué**. L'orientation est prise en charge d'emblée : le patient n'apparaît jamais dans la file d'attente, il est dans un lit. Le statut médical est rétabli à `HOSPITALIZED` après l'acceptation (qui remet « en soins », juste pour une arrivée ordinaire), après un `refresh()` sans lequel Eloquent n'écrirait rien. L'`active_key` rend l'ouverture idempotente — « Reprendre la visite en cours » plutôt qu'une seconde visite —, et un séjour terminé n'en ouvre plus. La Réception (ADR-147) lit le séjour et n'ouvre aucune visite. Le bloc de sortie est par ailleurs regroupé en trois sections encadrées (Décision / Conclusion médicale / Consignes), sans qu'aucun champ, contrat ni règle de validation ne change (ADR-099).

**Une visite de service se conclut « Poursuite de l'hospitalisation »** (ADR-149, complète ADR-148/084). La visite atteignait « Décision & clôture » sans issue : la clôture exige une conduite à tenir transmise, et les six destinations étaient fausses pour un patient au lit — « Hospitalisation » ouvrirait un **second séjour**, « Sortie médicale » prononcerait une sortie sans terminer le séjour, qui resterait `ACTIVE` avec un passage sorti. `ConsultationOrientationType::ContinuedHospitalization` est la septième : **transmise au moment du choix**, sans formulaire, parce qu'elle ne demande rien à personne (même raisonnement qu'`attachPronouncedDischarge`) ; `destinationModule` nul, `permission` `consultations.update`, `legacyDecision` `Hospitalization` (§32). `MedicineDossierPresenter::orientationApplies()` filtre **côté serveur** : hospitalisé → ni Hospitalisation ni Sortie médicale, plus « Poursuite » ; non hospitalisé → les six d'origine. La sortie se prononce depuis la page du séjour, qui seule termine séjour et prise en charge ensemble (ADR-113) — l'écran le dit avec le lien, une option retirée sans explication se lisant comme une fonction manquante. `hospital_stay` est servi à la consultation (avec `hospitalization.view`) : repère « Hospitalisé · chambre » dans l'en-tête condensé et bandeau dans la carte de conduite à tenir. Aucune permission nouvelle ; clôturer une visite ne touche ni le séjour, ni le lit, ni le statut `HOSPITALIZED`.

**Une seule sortie médicale, prononcée dans la consultation** (ADR-156 — renverse ADR-149 et ADR-152, retire le formulaire de sortie du séjour posé par ADR-113). L'invariant « jamais un passage médicalement sorti dont le lit reste occupé » était juste ; la réponse — un second formulaire, sur un autre écran que celui où le médecin travaille — était mauvaise : deux endroits pour un seul acte, visite restée « En cours » après la sortie, et « indiquez la suite de la prise en charge » affiché au-dessus d'une sortie déjà prononcée. `RecordMedicalDischargeAction` **termine désormais le séjour dans la même transaction** (statut, date, auteur, `active_key`, orientation du séjour complétée) au lieu de refuser : l'invariant tient par construction. `orientationApplies()` ne retire plus qu'« Hospitalisation » à un patient hospitalisé (second séjour). Retirés : `DischargeHospitalStayAction`, sa FormRequest, `POST /hospitalisation/{stay}/sortie`, le formulaire de l'écran et la garde ADR-155. `/hospitalisation` ne liste plus que les patients au lit ; **toutes les sorties se suivent à « Sorties & règlements »**, où chaque ligne dit si le passage est passé par un lit (pastille, service, chambre, et lien vers le séjour seulement avec `hospitalization.view`) — retirer la liste des séjours terminés sans la remplacer les aurait perdus. Une **sortie déjà prononcée n'est plus redemandée** : `CompleteConsultationAction::attachPronouncedDischarge()` la rattache comme conduite à tenir (`DISCHARGE`/`SUBMITTED`, liée à la sortie réelle) et l'obstacle disparaît — le bouton « Clôturer » restait gris au-dessus d'une sortie datée et signée, ce qui faisait ressaisir un fait déjà consigné (§17, ADR-107). Rien n'est fabriqué : un passage n'a qu'une sortie médicale ; les étapes du parcours restent à résoudre (ADR-076). Troisième vue à la Réception, **« Sortie médicale prononcée · service pas encore clôturé »** : un passage dont le séjour est terminé mais dont la visite reste ouverte n'est pas réglable (ADR-054/084) et n'apparaissait nulle part — il s'y affiche en lecture seule avec sa raison, et rejoint « À régler » à la clôture. La consultation qui a **demandé** l'hospitalisation ne sort pas le patient — sa conduite à tenir est déjà transmise (ADR-084) : la sortie se prononce dans une visite de service.

**Séjour hospitalier, lot 1** (ADR-161, complète ADR-113/114, amende ADR-156 pour la sortie « Transfert »). Quatre défauts corrigés. **Transfert :** « Transfert effectué » (`ConfirmTransferDepartureAction`) termine le séjour actif — `end_reason = TRANSFER`, `medical_referral_id`, orientation du séjour complétée, emplacement fermé ; avant, le patient restait « au lit ». Un seul circuit pour un patient hospitalisé : la conduite « Référence / transfert » ; la sortie médicale « Transfert » ne lui est plus proposée (`discharge_types` filtré) et est refusée côté serveur tant qu'un séjour est actif. `hospital_stays.end_reason` (`HospitalStayEndReason`) dit comment le séjour s'est terminé, repris depuis la sortie réelle. **Emplacements :** `hospital_stay_movements` (service, lit, `HospitalCareLevel` standard / surveillance continue / réanimation, début, fin, motif) — l'admission ouvre le premier, `MoveHospitalStayAction` ferme et ouvre, toute fin de séjour ferme le dernier ; « Corriger » (crayon) corrige l'emplacement en cours sans déplacer. La clinique a une réanimation interne : une aggravation = mutation, le séjour continue. **Surveillance :** `vital_sign_readings`, relevés datés et signés pendant le séjour, bornes `VitalSignRules`, repères ADR-125 calculés par `HospitalStaySurveillance` ; `care_records` reste le triage. Aucune permission nouvelle. Lot 2 : note quotidienne courte (choisie), plan, médecin référent.

**La sortie d'hospitalisation ajoute un diagnostic sans quitter l'étape, et porte ses repères à côté** (ADR-162, amendement du 2026-09-21). Les diagnostics consignés restent cochés ; « Ajouter un autre diagnostic » (`StayDiagnosisAdd`, écrit une fois, partagé avec la Vue d'ensemble) l'enregistre sur le séjour (`diagnoses.create`, ADR-147) et il arrive coché — plus de renvoi vers « Vue d'ensemble ». Défaut corrigé : `DischargeHospitalStayAction::recordFinalDiagnosisIfNew()` comparait la liste cochée entière (une ligne par diagnostic) à chaque diagnostic connu et enregistrait la liste comme un diagnostic de plus ; chaque ligne est désormais lue seule. À droite du formulaire (≥ 1280 px), `StayExitContext` : le séjour, les allergies, le dernier relevé et « Avant de conclure » (ordonnances actives, examens sans résultat, bloc non terminé, consultations ouvertes) ; une section non servie se tait (ADR-102). Le formulaire garde sa largeur (ADR-132) ; son prop `narrow` réserve les consignes en trois colonnes aux écrans ≥ 1536 px.

**Le transfert depuis le séjour propose les autres sites** (ADR-162, amendement du 2026-09-21). Le champ « Établissement » de l'onglet Sortie est une liste : « À préciser plus tard », les autres sites de la clinique (depuis Ambondromamy : Mampikony, Boriziny), et « Autre établissement… » qui ouvre une saisie libre, alors exigée. `App\Support\ClinicSites::others()` lit `rivo.clinics` et écarte le site courant — une seule source, qui remplace les deux copies du module Transferts et de `MedicineDossierPresenter`. Servie dans `orderOptions.transfer_destinations`, seulement avec `transfer.request`.

**L'onglet Surveillance dit qui relève** (ADR-161, amendement du 2026-09-21). Un relevé est saisi **à la main** au lit du patient : aucun autre circuit ne l'alimente, et la fiche Soins d'arrivée n'y est jamais recopiée. `vitals.create` n'est au socle que de `NURSE` ; `MEDICINE` a `vitals.view` + `vitals.update` (ADR-093), donc lecture et correction seulement. L'écran ne laissait qu'un cadre vide — il nomme désormais qui relève et le droit manquant (`vitalsRecordBlock()`, phrase composée côté serveur où vit le nom de la permission ; `null` quand le relevé est possible), comme l'onglet verrouillé d'ADR-158 et le refus d'ADR-154. Accorder `vitals.create` à `MEDICINE` est une décision du portail (ADR-064), non tranchée.

**Le séjour est le poste de travail du patient hospitalisé** (ADR-162 — retire la visite de service de l'ADR-148, renverse l'ADR-156 sur le lieu de la sortie, amende l'ADR-049). `/hospitalisation/{séjour}` porte un onglet par geste (Vue d'ensemble, Notes du jour, Ordonnances, Examens, Soins, Surveillance, Régime, Bloc, Sortie) et chaque geste appelle l'action existante par sa variante `executeForStay` — mêmes règles FEFO, facturation (ADR-105/109), doublons et orientation (source `HOSPITALIZATION`) ; `StayOrderContext::lock()` exige séjour en cours, passage ouvert et orientation du séjour prise en charge. Les demandes portent `hospital_stay_id` et un `consultation_id` nullable ; `prescriptions.episode_id` existe désormais. `HospitalStayWorkstation` compose la page, section par section selon les droits (jamais servie vide). **Note du jour** : `hospital_stay_notes` S/O/A/P, append-only, `hospital_notes.view` (MEDICINE, NURSE) / `.create` (MEDICINE). **Sortie** : `DischargeHospitalStayAction` (`POST /hospitalisation/{séjour}/sortie`), même `MedicalDischarge` sans consultation, diagnostic final exigé ; `RecordMedicalDischargeAction` la refuse depuis une consultation tant qu'un séjour est actif. **Délivrance au service** : une dispensation `hospital_stay_id` passe Prête à la préparation de sa facture et se délivre sans attendre le règlement ; la facture rejoint « Sorties & règlements » (ADR-090). Un soin demandé depuis le séjour ne décide aucun retour en Médecine. Visites : plus aucune ne s'ouvre, les anciennes restent lisibles.

**Revenir sur un geste du séjour, sans rien effacer** (ADR-163, complète ADR-160/162). Chaque annulation est un changement d'état tracé (auteur, date, motif facultatif), jamais une suppression. **Transfert au bloc** : `CancelSurgeryFromStayAction` (`POST /hospitalisation/{séjour}/bloc/{demande}/annuler`, `surgery.request`) tant que la demande est « À programmer » — arbitrage du propriétaire ; programmée, elle appartient au bloc. La règle vit dans `WithdrawSurgicalRequestAction`, partagée avec la consultation (ADR-084) ; l'orientation du passage vers le bloc n'est annulée que si plus aucune demande ne l'utilise ; une demande venue d'une consultation annule aussi sa conduite « Chirurgie ». **Visite de service** : `CancelHospitalVisitAction` (`POST /hospitalisation/{séjour}/visites/{orientation}/annuler`, `consultations.create`, auteur seul) — consultation « Annulée » (colonnes `cancelled_*` ajoutées), orientation annulée par `EpisodeOrientation::cancelTakenUp()` ; refusée tant qu'elle porte un diagnostic, une ordonnance active, un examen en cours, des soins, une sortie ou une demande prise en charge (`blockers()`), une demande au bloc « À programmer » part avec elle. **Examen** : retirable depuis le séjour (`executeForStay`) ; `ParaclinicalBillingRelease` annule ce que la demande a elle-même facturé, jamais la prestation de la Réception, et le retrait d'**une** demande en consultation annule enfin sa facturation (il l'oubliait, ADR-105). **Transfert externe** : annulable tant que le patient n'est pas parti. **Propositions d'ordonnance** au séjour pour les diagnostics du passage (`stayContext()`, `PrescriptionSuggestions` partagé avec la consultation, origine revérifiée). **Consultations restées ouvertes** listées sur « Vue d'ensemble » et « Sortie », avec ce qui manque et une clôture d'un clic (`CompleteConsultationAction`) ; c'est aussi de là qu'une visite s'annule — la carte « Visites de service » du séjour est retirée (amendement du même jour) : plus aucune visite ne s'ouvre, et une visite close ou annulée se relit sur la page du passage. **Le séjour ne s'annule plus** dès qu'il a eu lieu (`CancelHospitalStayAction::activity()`), ce qui ferme le piège de l'ADR-160. Les visites se désignent par l'UUID de leur orientation (une consultation n'en a pas).

**Services, chambres et lits : un référentiel par site, une occupation qui se lit** (ADR-164, amende ADR-113, complète ADR-161). `hospital_services` (avec son niveau de soins) › `hospital_rooms` › `hospital_beds`, dans la base de chaque site, réglés depuis `admin.rivo.mg` › Services, chambres et lits (`/super-admin/hospital-beds`, `hospital_beds.*`, SUPER_ADMIN du portail) **uniquement par l'API du site** (`/api/v1/super-admin/hospital-beds*`, idempotente, acteur distant audité). Créer une chambre, c'est donner son nombre de lits (Lit 1…N, 30 au plus), renommables, ajoutables, archivables ; un nom archivé se restaure, il ne se recrée pas. **L'occupation n'est jamais saisie** : un lit est occupé parce qu'un séjour `ACTIVE` le porte — `hospital_stays.bed_active_key` (`BED_{id}`, index unique, effacée à toute fin de séjour par le modèle) interdit en base deux séjours en cours sur le même lit, et `HospitalBedAllocator::lockFree()` verrouille le lit et nomme l'occupant dans le refus. « Hors service » est une décision manuelle avec motif ; hors service, archivage d'un lit, d'une chambre ou d'un service sont refusés tant qu'un patient y est. L'admission reste automatique : le séjour s'ouvre « lit à attribuer », puis « Attribuer un lit » (`CorrectHospitalStayLocationAction`, sans mouvement) ou « Changer de lit » (`MoveHospitalStayAction`, nouvel emplacement ADR-161) sur la page du séjour, `hospitalization.update`. L'emplacement reprend le niveau de soins du service. `service` / `room_bed` restent l'instantané lu partout ; `hospital_bed_id` s'y ajoute. Un site **sans aucun lit configuré** garde le texte libre de l'ADR-113 ; dès le premier lit, le texte libre est `prohibited` côté serveur. `/hospitalisation` a un onglet « Plan des lits » (`BedBoard`), visible même sans lit configuré pour dire où les créer, et signale les séjours sans lit. La migration se joue aussi sur la base du portail, qui porte les droits `hospital_beds.*` du Super Admin. Le portail voit l'occupation (numéro de passage, date) **jamais le nom du patient**. Aucune facturation au lit.

**Liste des hospitalisés : sélection multiple, en lecture seulement** (ADR-165, complète ADR-113/161/164). Cocher des patients dans `/hospitalisation` (50 au plus, `uuids[]`, sélection effacée au changement de page ou de recherche) ouvre quatre actions qui **lisent** : Tour de salle (paysage, lit, motif, séjour, allergies, dernier relevé, « Notes de visite » vierge), Fiches de régime et Dossiers médicaux (une par page, les mêmes feuilles que l'impression unitaire), Export Excel. `HospitalStaySelectionController` relit tout côté serveur, écarte les séjours annulés, trie par service, lit puis entrée. **Jamais en lot** : sortie, transfert, bloc, changement de lit, saisie du régime — ce sont des décisions par patient (ADR-161/162/164). Aucune feuille recopiée : `App\Support\Hospitalization\DietSheet` et `DietSheetBody` servent l'impression unitaire et groupée, les dossiers groupés affichent `MedicalRecordPrint` lui-même (gardes par section ADR-116 intactes), le dernier relevé vient de `HospitalStaySurveillance::latestReading()`. Droits : `hospitalization.view` pour ouvrir, `+ patients.view` pour les dossiers, `vitals.view` pour la colonne du relevé (retirée, jamais vide), `hospitalization.export` pour l'export (audité, MEDICINE et NURSE par défaut, pas RECEPTION — migration `2026_10_19_090000`, à jouer aussi sur le portail). Le paysage du tour de salle est une règle `@page` injectée au montage et retirée en quittant la page : une page nommée est ignorée dans les conteneurs flex du layout. Écran en shadcn-vue, icônes par colonne et par action.

**Le patient hospitalisé descend au bloc, et garde son lit** (ADR-160, complète ADR-113/148/159). Le chemin par une visite de service existait, mais une consultation ne porte qu'une conduite à tenir (ADR-084) : si la consultation qui a demandé l'hospitalisation est encore ouverte, la visite la réutilise (`active_key`), et choisir « Chirurgie » y remplace « Hospitalisation » — ce qui **annulait le séjour** (vérifié en test). La décision se prend donc sur le séjour : `RequestSurgeryFromStayAction` (`POST /hospitalisation/{séjour}/bloc`, `surgery.request`) crée `HOSPITALIZATION → SURGERY` et une demande `PENDING` d'origine `HOSPITALIZATION`, sans toucher au séjour (reste `ACTIVE`, passage `HOSPITALIZED`). Intervention choisie, jamais devinée ; service, chambre et diagnostic d'entrée repris ; une même intervention encore ouverte n'est pas redoublée. Rien n'est recopié au bloc : Chirurgie et Anesthésie lisent déjà la fiche Soins (ADR-048) ; `SurgicalStayContext::for()` leur dit qu'un lit attend (bandeau, pastille dans la file, lien avec `hospitalization.view`). La page du séjour a sa carte « Bloc opératoire ». Les « modèles (captures) » des paramètres n'ont pas été fournis : rien n'est inventé. **La liste des hospitalisés le dit aussi** (amendement du 2026-09-21) : sous le nom de chaque patient, « Bloc · À programmer », « Bloc · Programmée », « Au bloc », puis « Opéré » — la demande la plus avancée l'emporte, une annulée n'est jamais lue (`StaySurgeryStatus`, une requête par page) ; une carte « Vers le bloc » compte et filtre (`?filter=bloc`) ; lien vers le dossier du bloc seulement avec `surgery.view`. Libellés partagés avec la page du séjour (`utilities/surgicalRequestStatus.js`).

~~**Une visite de service ouverte retient la sortie**~~ (ADR-155, complète ADR-148/113). Constat : sortie prononcée à 21:28, visite de 20:08 toujours « En cours » — la sortie terminait l'orientation **du séjour** et laissait intacte celle de la visite, qui est une consultation (ADR-148) que seule sa clôture termine (ADR-084) ; le passage gardait donc une orientation active et n'atteignait jamais « Sorties & règlements ». `DischargeHospitalStayAction` refuse désormais tant qu'une visite est ouverte et nomme la suite (« Poursuite de l'hospitalisation », ADR-149) ; l'écran le dit avant le clic et propose « Clôturer la visite en cours ». Clôturer d'office aurait été faux : l'ADR-076 interdit de résoudre une consultation par effet de bord. Règle de **cohérence**, pas de droit (comme ADR-152). Pour les séjours déjà bloqués, `attachPronouncedDischarge` lit la sortie sur le **passage** et non sur la seule consultation — une sortie prononcée depuis le séjour porte le `consultation_id` de la consultation qui a demandé l'hospitalisation (ADR-113), si bien que la visite ne pouvait ni transmettre ni clôturer (même cul-de-sac qu'ADR-107).

**Le droit décide, jamais le rôle** (ADR-152). Aucun rôle n'est codé en dur sur l'Hospitalisation : contrôleur, Actions, FormRequests et presenter passent tous par `$user->can(...)`, et un test accorde à `RECEPTION` le socle complet puis exécute les cinq gestes (chambre/lit, fiche de régime, diagnostic de sortie, visite de service, sortie médicale) — il échoue si quiconque code un rôle en dur. Ce qui reste impossible ne relève pas du droit mais de la cohérence : `RecordMedicalDischargeAction` refuse une sortie prononcée depuis une consultation tant qu'un séjour est `ACTIVE`, parce qu'elle laisserait le séjour ouvert avec un passage médicalement sorti — l'ADR-149 ne faisait que retirer l'option de l'écran, et l'interface n'est jamais la seule garde. Le refus vaut pour un médecin comme pour un compte à qui tout aurait été accordé ; `DischargeHospitalStayAction`, qui termine les deux ensemble, n'est pas concernée.

**Transferts et Pédiatrie** (ADR-114). Une orientation Transfert ou Pédiatrie
n'avait aucun module et restait `PENDING` : le passage ne quittait jamais
`IN_CARE`. `/transferts` (`transfers.view`/`.manage`, Médecine, Soins,
Réception) liste « À transférer » et « Transférés » ; la demande part en un
clic de la consultation, l'établissement se précise dans le module, puis
« Transfert effectué » (`ConfirmTransferDepartureAction`) constate le départ :
orientation terminée, statut médical `TRANSFERRED`, `PENDING_SETTLEMENT`
selon l'ADR-054 — un constat daté, jamais une `MedicalDischarge` fabriquée.
`/pediatrie` (`pediatrics.view`/`.manage`, Médecine) : file, prise en charge,
puis la même sortie médicale que la consultation, rattachée à la
consultation d'origine ; aucune fiche pédiatrique n'est inventée. En
consultation, Maternité/Pédiatrie et Transfert se transmettent en un clic,
le contenu repris du dossier ; la Chirurgie garde le choix de l'intervention
et génère son « Diagnostic / hypothèse » depuis les diagnostics actifs.

**Transmettre une demande de conduite à tenir est un acte signé** (ADR-106) :
l'ordre part au service destinataire **et** l'orientation passe à
`SUBMITTED`, ce qui débloque la clôture (ADR-084). Les quatre destinations
passent par la même confirmation que la demande d'examen et l'ordonnance —
destination nommée, contenu relu, responsabilité nominative, fenêtre non
fermable au clic extérieur.

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

**Un compte rendu d'imagerie enregistré se corrige, sans rien perdre** (ADR-130,
complète ADR-108). La première saisie reste unique ; corriger est un autre acte
(`CorrectImagingResultAction`, `PUT …/imaging-requests/{item}/result`,
`imaging_results.update`, accordée à MEDICINE) qui conserve la version remplacée dans
`imaging_result_revisions` — append-only, avec auteur, date et motif facultatif — et
garde la signature d'origine (`resulted_at/by`) à côté de `corrected_at/by`. Refusé si
rien n'a changé, si le texte est vide, ou avant la première saisie. Possible après la
clôture, comme la saisie. « Demandes d'examens » offre Modifier par examen, un repère
« Corrigé » et un historique des versions.

**« Demandes d'examens » : familles, archivage, boutons compacts** (ADR-131). Chaque
examen porte ses actions à sa ligne — « Saisir » court, puis Voir / Modifier / Imprimer en
icônes nommées ; la colonne Actions garde consultation, archiver et retirer en icônes.
Onglets **Tous / ECG / Échographie / Analyses** (`?type=`, plus « Non classés » s'il en
existe — la famille d'imagerie vient du catalogue, ADR-106), combinés avec les trois vues,
comptes servis par le serveur. **Archiver à la main** : `archived_at/by` sur `lab_requests`
et `imaging_requests`, drapeau réversible, `paraclinical_requests.archive` (MEDICINE) ; on
ne range que ce qui est rendu, jamais du travail à faire.



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

**Une ordonnance ne réclame que ce que le produit porte réellement**
(ADR-110). `dosage` était obligatoire pour tout produit : sur un paquet de
compresses stériles, c'était un champ impossible à remplir honnêtement — une
compresse n'a pas de dose, on en utilise un nombre. La **forme du
référentiel** décide (`MedicineForm::undosedValues()`, une seule source
relue par `isDosed()`), jamais un libellé contenant « compresse » ou
« gants » (ADR-052) : `PARAPHARMACY_CONSUMABLE` rend la dose facultative,
toute autre forme l'exige, et une ligne manuelle (ADR-037) l'exige aussi —
elle ne référence aucune forme, donc rien ne permet de savoir qu'elle ne se
dose pas. La règle est résolue **ligne par ligne** côté serveur
(`Rule::forEach`, une règle `lines.*` ne pouvant pas lire la forme du produit
de sa propre ligne) : masquer le champ dans Vue n'aurait rien protégé d'un
appelant qui poste directement. Un champ vide est enregistré `null`, jamais
une chaîne vide (ADR-077) ; `prescription_lines.dosage` était déjà nullable,
aucune migration.

La **quantité totale** est déduite de la fréquence et de la durée
(`resources/js/utilities/posology.js`, écrit une seule fois pour l'éditeur et
la confirmation de l'ADR-106 — deux formules finiraient par annoncer deux
quantités). Le calcul suppose une unité par prise et l'écrit sous le champ
(`3/jour × 7 jours · une unité par prise`) plutôt que de livrer un chiffre
nu : déduire qu'une dose couvre deux comprimés exigerait de comparer deux
textes libres dont l'unité ne correspond pas toujours. **Il ne propose rien
quand il n'a rien à proposer** — « si besoin » n'a pas de cadence, une
fréquence tapée à la main non plus, et une durée absente n'a rien à
multiplier. La suggestion n'écrase **jamais** une saisie : dès que le médecin
touche la quantité, la ligne est verrouillée — corriger 21 en 30 parce que la
boîte en contient trente est une décision, pas une faute de frappe à
recalculer (même principe que le matériel habituel d'un acte, ADR-072). L'écran
Ordonnance est entièrement en shadcn (amendement ADR-110) : `Select` et
`FormField` dans l'éditeur, « Utiliser N » pour reprendre la quantité
déduite après une correction, et un catalogue qui distingue une ligne déjà
retenue d'une ligne épuisée.

**La consultation propose un diagnostic et une ordonnance** (ADR-111), à
partir des **protocoles écrits par les médecins de la clinique**
(`clinical_protocols`, `/medicine/protocoles`) — jamais d'une règle inventée :
ni le CDC ni le référentiel ne disent quel médicament traite quoi, à quelle
dose. `ClinicalProtocolMatcher` lit le dossier déjà consigné (interrogatoire,
examen, appareils anormaux, âge, sexe, poids, allergies) : il propose les
diagnostics dont les signes évocateurs s'y retrouvent (mots entiers, sans
accents ni casse), puis l'ordonnance type des diagnostics **posés**. Toute
proposition dit pourquoi, tout protocole écarté aussi ; une borne d'âge ou de
poids exclut un patient dont la valeur est inconnue. Les propositions sont
calculées à l'affichage, **jamais enregistrées** ; « Retenir » / « Ajouter »
en font une saisie du médecin, modifiable comme toute autre. Épuisé → non
ajoutable ; allergie recoupée → rouge, hors « Tout ajouter ».
`suggestion_source` + `clinical_protocol_id` tracent l'origine sur
`diagnoses` et `prescription_lines`, revérifiés par le serveur (protocole qui
traite ce diagnostic / prescrit ce médicament). Droits `clinical_protocols.view`
et `.manage`, accordés à `MEDICINE`.

**Une ligne d'ordonnance est relue avant d'être signée** (ADR-128, complète ADR-110).
Un enfant de 4 ans prescrit à 1000 mg d'un injectable, par voie orale, sans qu'aucune
alerte ne se déclenche : `prescriptionChecks.js` relit désormais ce que l'application
sait — voie incompatible avec la forme, fréquence « 2 » sans unité, quantité inférieure à
ce que la posologie consomme (dose ÷ dosage du produit), allergie recoupée (catalogue
rapproché par le serveur, ligne manuelle sur son nom), enfant sans poids, même principe
actif deux fois — et, pour un enfant pesé, la dose en mg/kg. **Il ne dit jamais « dose
trop élevée »** : aucune table de doses maximales n'existe (ni CDC ni référentiel) et il
n'en invente pas ; il avoue qu'aucune dose maximale n'est enregistrée. Aide, jamais un
verrou : la validation n'est pas bloquée, la fenêtre de signature annonce le nombre de
points à relire. `prescription_safety` (âge, poids, conflits d'allergie) est servi par
`MedicineDossierPresenter`. **À fournir par la clinique** : doses maximales par produit,
âge et poids, pour une vraie détection.

**Tout est local : aucune API d'IA, aucun service externe**, aucune donnée ne
quitte le site — précision explicite du propriétaire, imposée par un test qui
interdit toute requête HTTP sortante. En complément des protocoles,
l'algorithme « Pratique de la clinique » (`ClinicPracticeIndex`,
`ClinicPracticeAdvisor`) apprend des consultations conclues : le vocabulaire
qui accompagne chaque diagnostic (pondéré fréquent-ici / rare-ailleurs) et
l'ordonnance habituelle avec sa posologie la plus fréquente. Il ne parle
qu'à partir de 3 cas, affiche sa preuve (« 4/6 consultations »), exclut la
consultation en cours de sa propre preuve, signale un patient hors de la
tranche d'âge déjà traitée, et cède la place à un protocole applicable. Le
motif prérempli par le nom de la prestation est retiré du texte analysé
(`ClinicalNarrative`, lu par les deux moteurs) : ce n'est pas un symptôme.
`suggestion_source` vaut `PROTOCOL` ou `CLINIC_PRACTICE`, toujours revérifié.

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

**Deux fiches papier de la clinique n'avaient aucun équivalent** (ADR-116).
Le « DOSSIER MÉDICAL » s'imprime depuis `/passages/{episode}/dossier-medical`
(`App\Support\Documents\MedicalRecordSheet`, gardé par `patients.view` comme
la page « Détail du passage ») : identité, constantes, allergies, antécédents
familiaux, hospitalisation et diagnostic — chaque case relue depuis sa source
existante, jamais ressaisie, les sections sensibles restant gouvernées par
leur propre permission (`vitals.view`, `patients.medical_history.view`). Le
« DOSSIER MÉDICAL – TRAITEMENT » s'imprime depuis
`/passages/{episode}/journal` (`App\Services\Medicine\TreatmentJournal`,
`treatment_journal.view`/`.record`, MEDICINE et NURSE) : une chronologie
composée à chaque affichage depuis ce qui est déjà consigné (consultation,
actes de soins, ordres de soins, ordonnances, analyses, imagerie, admission,
sortie), complétée de lignes ajoutées à la main pour ce que l'application
n'enregistre pas encore — append-only, visa de l'utilisateur connecté, jamais
un nom saisi. Un passage clos par la sortie administrative (ADR-090) refuse
toute nouvelle ligne. Les trois feuilles papier déjà publiées (ADR-107,
ADR-108, ADR-113) et ces deux nouvelles partagent désormais le même bandeau
et pied de page (`Components/Clinical/PaperSheet.vue`) plutôt que de le
recopier une quatrième fois.

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

**La demande du bloc naît ailleurs** (ADR-159, complète ADR-068/084, amende
ADR-048). Le CDC §16 décrit le déroulé d'une intervention mais **ne dit nulle
part d'où vient la demande** : les deux chemins sont la décision du
propriétaire, signalée comme telle. Le troisième, `/surgery/create`, est retiré
— le bloc exécute une décision prise ailleurs, et un dossier ouvert au bloc
n'avait ni orientation ni décision clinique derrière lui. `GET /surgery/create`
reste valide et redirige vers la file (ADR-081, ADR-104).

```text
Réception   l'acte est la raison de la venue → ReceptionRoutingMode::SurgeryDirect
Médecine    conduite à tenir d'une consultation (ADR-084)
Maternité   césarienne (ADR-067) — le propriétaire en nomme deux, celle-ci existe déjà
```

`CreateReceptionSurgicalRequestAction` suit exactement l'ADR-068 : l'orientation
`RECEPTION → SURGERY` situe le patient, la demande dit **ce qu'on vient opérer**.
Elle arrive `PENDING`, sans chirurgien ni date — c'est le bloc qui programme —,
fige l'instantané du libellé (ADR-024) et est idempotente sur (épisode, acte,
demande non annulée). **37 actes** deviennent sélectionnables : « Autres », la
césarienne, `CONSULT-CHIR` et `PETITE-CHIR` en sont écartés — la migration
`2026_10_13_090000` liste les codes explicitement depuis le référentiel des
interventions, une première version filtrant par module ayant envoyé la
consultation chirurgicale au bloc (ADR-052). **Un acte sans tarif reste
sélectionnable** : l'ADR-031 précise l'ADR-028 — l'absence de prix empêche la
facture, jamais le parcours. Migration pour les sites installés (ADR-064).

**Le module ne décide pas le parcours** : une garde « module SURGERY ⇒
SURGERY_DIRECT » a été écrite puis retirée avant d'être retenue — elle
contredit l'ADR-052 (« une consultation de chirurgien ou un contrôle
postopératoire peut être une prestation ordinaire »). La garde inverse suffit :
`SURGERY_DIRECT` est réservé au module SURGERY.

`surgical_requests.origin` (`SurgicalRequestOrigin`) porte le chemin réel,
**nullable et jamais rétro-rempli** : une demande antérieure affiche « Non
renseignée », la déduire inventerait un fait (ADR-083). Libellé et explication
servis par le serveur. Le bloc ne crée pas une demande, il **corrige** celle
qu'il a reçue (`PUT /surgery/{demande}`, `surgery.update`) : les trois garanties
du catalogue (instantané du libellé, « Autres » à préciser, module respecté) y
sont désormais vérifiées. `surgery.create` ne commande plus rien et le catalogue
des permissions l'affichera « pas encore vérifiée » (ADR-101) ; elle reste au
socle `SURGERY`, sa décoche étant une décision du portail (ADR-064).

Les espaces `/surgery` et `/anesthesia` sont indépendants et respectivement
protégés par `surgery.view` et `anesthesia.view`, mais partagent le même dossier
chirurgical afin de préserver la continuité. Les deux interfaces sont guidées
par étapes. Les interventions sont choisies dans le catalogue `SURGERY` avec
instantané du libellé ; les éléments d'anesthésie suivent une liste contrôlée
avec instantané de leur code, libellé et catégorie. Voir ADR-048.

La Chirurgie ne peut pas encaisser.

**Programmer l'intervention : un principal, des aides, le profil Chirurgien et le planning RH** (ADR-168, complète ADR-048, amende ADR-033). La date se choisit d'abord ; un compte au profil `SURGEON` coche « Moi-même » (il devient principal) et tout compte ayant `surgery.schedule` choisit un, deux, trois chirurgiens (5 aides au plus) — le premier est `surgical_requests.surgeon_id`, les suivants entrent dans l'équipe de bloc comme `SURGEON`, sans table nouvelle. Un chirurgien est un **profil**, jamais un nom de rôle : le rôle SURGERY reçoit `SURGEON`, `OR_NURSE`, `SURGICAL_PARAMEDICAL` (migration `2026_10_22_090000`, aucune permission recommandée, comptes existants laissés « à définir » — sans profil Chirurgien, aucune programmation n'est possible). `App\Services\Surgery\SurgeonRoster` lit la disponibilité sur le planning RH de la fiche Employé reliée au compte (`employees.user_id`, désormais remplie depuis le formulaire Employé, `EmployeeAccountResolver`, un compte = une fiche) : `AVAILABLE` (créneau couvrant l'heure), `OFF_PLANNING` et `INACTIVE_RECORD` (verrouillés, refusés), `UNLINKED` (aucune fiche : accepté, « Non vérifié » — transition signalée). `GET /surgery/{demande}/surgeons?at=` sert la liste ; `ScheduleSurgicalRequestAction` rejuge tout en transaction et synchronise les aides (retrait audité ; omettre la liste la laisse intacte). Le formulaire libre de l'équipe refuse la fonction « Chirurgien » ; l'opérateur se choisit parmi les chirurgiens programmés. Composant : `Components/Surgery/SurgeonScheduler.vue`. **Chaque tuile se corrige** (amendements du 2026-09-22) : un crayon par tuile (date, principal, aides, opérateur) ouvre `ScheduleFieldEditor`. Avant le démarrage, `POST /schedule` (planning RH vérifié) ; au bloc, `AdjustSurgicalTeamDuringInterventionAction` (`POST /surgery/{demande}/team-adjustment`, `surgery.schedule`) corrige un fait à la fois, champ omis inchangé, motif obligatoire, audit `surgery.team.adjust`. Opérateur choisi parmi le principal et les aides ; changer de principal garde l'ancien, s'il est l'opérateur, comme aide.

**Le matériel du bloc passe par le stock de la Pharmacie** (ADR-169, complète ADR-048/072/142). « Consommables » était un texte libre : rien ne sortait du stock ni n'était facturé. L'étape Intervention déclare désormais des produits du stock (`POST /surgery/{demande}/consumable-requests`, `surgery.consumables.create`) par `RequestCareConsumablesAction::executeForSurgery()` — même `CareConsumableRequest` (`source_module = SURGERY`, `surgical_request_id`), même file Pharmacie (« Bloc opératoire »), même sortie FEFO sans attendre le règlement, même facturation ligne par ligne encaissée à la Caisse, même annulation avec motif tant que rien n'est servi. Produits : parapharmacie + matériel configuré pour un acte de Chirurgie (`CareConsumableDirectory::acceptsConfiguredProducts()`, configuré dans Administration › Catalogue) ; le matériel habituel de l'intervention est suggéré, jamais imposé. Aucun montant n'est servi au bloc (ADR-036). La ligne « hors stock » (`surgical_consumables`) reste pour un produit absent du stock : tracée, ni stock ni facture. Composant : `Components/Surgery/SurgicalConsumables.vue`.

**Chirurgie et Anesthésie travaillent en parallèle, avec deux rendez-vous opposables** (ADR-170, complète ADR-048/168). Le CDC §16 ne décrit ni autorisation anesthésique ni checklist : ces règles viennent du propriétaire, signalées comme telles. Rien n'impose à un métier d'attendre que l'autre ait tout fini ; deux transitions seules sont gardées — **l'incision** et **la clôture** — par `App\Services\Surgery\SurgicalReadinessGate`, autorité unique appelée **dans** la transaction, sur un dossier verrouillé : un POST direct rencontre exactement le même refus que l'écran.

**L'autorisation est distincte de la validation du bilan.** `assessment_validated_at` dit que l'évaluation est terminée ; `AnesthesiaClearanceStatus` (`DRAFT`/`CLEARED`/`CLEARED_WITH_CONDITIONS`/`NOT_CLEARED`/`DEFERRED`) dit si le bloc peut avoir lieu ; `validated_at` ferme la fiche entière. `DRAFT` **n'est pas une décision** : tant que personne n'a prononcé, l'incision est retenue (ADR-077, ADR-095). Un refus ou un report **exige son motif**, que le bloc lit dans sa synthèse — jamais masqué. La décision est révisable tant que l'intervention n'a pas commencé, plus après. `paraclinical_data.surgery_authorized` n'est **pas** migré : ce drapeau n'opposait rien, et le convertir attribuerait une autorisation que personne n'a prononcée sous cette forme. `anesthesia_clearance_conditions` : `OPEN` bloque l'incision, `RESOLVED` la libère ; levée par l'anesthésie seule. **Pas de `WAIVED`** — le CDC ne dit pas qui pourrait passer outre la réserve d'un anesthésiste.

**Checklist de sécurité du bloc** (`surgical_safety_checklists`) : `SIGN_IN` (Anesthésie + Équipe de salle), `TIME_OUT` et `SIGN_OUT` (les trois métiers). Chaque rôle confirme **sa propre part**, nominativement et horodatée — un chirurgien ne signe pas pour l'anesthésiste. `completed_at` est **calculé des faits** (`refreshCompletion()`), jamais posé par un clic, et ne bouge plus ensuite. Le contenu vit dans un fichier unique et relu, `App\Support\SurgicalSafetyChecklistItems` : uniquement des vérifications organisationnelles (bon patient, bonne intervention, bon côté, matériel, personne présente), **aucun seuil ni score clinique inventé** ; élargir les items obligatoires est une décision de la clinique, qui se prend là et nulle part ailleurs. Aucune permission nouvelle : `anesthesia.update` pour l'anesthésie, `surgery.preparation.update` pour le chirurgien et l'équipe de salle.

**Bloquant ≠ avertissement.** Ce qui bloque l'incision : dossier annulé, intervention déjà ouverte, feu vert préopératoire non confirmé, aucun anesthésiste affecté, pas de dossier d'anesthésie, évaluation non validée, décision absente/refusée/reportée/échue, condition ouverte, SIGN IN ou TIME OUT incomplets. Ce qui **avertit sans bloquer** : fiche d'entrée au bloc manquante, points facultatifs non cochés — on ne retient jamais un bloc opératoire pour un champ facultatif. Chaque constat porte son propriétaire (`SURGERY`/`ANESTHESIA`/`BLOCK`) : l'écran dit « en attente de l'anesthésiste » au lieu de proposer au chirurgien un geste qui n'est pas le sien.

**Valider le compte rendu ne clôt plus le dossier** : `CompleteSurgicalCaseAction` (`POST /surgery/{demande}/complete`) exige intervention terminée, compte rendu validé, SIGN OUT confirmé, sortie du bloc renseignée et anesthésie finalisée ; idempotente. La permission reste `surgery.report.validate`, celle qui emportait la clôture jusqu'ici (ADR-101). Symétriquement, `ValidateAnesthesiaRecordAction` refuse de fermer la fiche **avant l'incision** : la conduite peropératoire resterait à consigner sur un dossier verrouillé.

**L'autorisation par dossier, au-dessus du RBAC** : `SurgicalCaseActors` répond à « cette personne travaille-t-elle sur ce patient ? » — `anesthesia.update` ouvre le métier, `isAnesthetistOf()` ouvre le dossier, `surgery.update` est une supervision nommée, jamais un effet de bord. `SurgicalRequestPolicy` / `AnesthesiaRecordPolicy` portent ces règles ; les FormRequests de l'incision, de l'anesthésie, de la checklist et de la clôture ne renvoient plus `true`. Huit autres (programmation, sortie de Chirurgie, équipe, sortie du bloc, notes et traitements) renvoient encore `true` et ne sont gardées que par le `can:` de leur route : l'autorisation par dossier ne s'y applique pas encore — signalé, non traité. `performed_by` doit être un chirurgien **de ce dossier** ou la supervision : un compte actif ne suffit plus. Ouvrir le dossier d'anesthésie reste ouvert à `anesthesia.create` sans affectation préalable — l'exiger enfermerait la clinique, puisque avant ce dossier personne n'est encore l'anesthésiste.

**Le serveur décide, l'écran affiche** : `SurgicalReadinessPresenter` sert `readiness` (blocages, avertissements, lignes d'état, décision, checklists, droits de l'acteur) aux deux espaces ; **aucune règle sensible n'est recalculée en JavaScript**. Un bouton désactivé dit toujours pourquoi (`BlockingIssuesAlert`), et la couleur ne porte jamais seule l'information : chaque état a son mot (« Terminé », « À faire », « Bloquant », « Attention ») et son icône. Composants shadcn : `SurgicalReadinessCard`, `BlockingIssuesAlert`, `AnesthesiaClearanceBadge`, `AnesthesiaClearancePanel`, `SurgicalSafetyChecklist`. L'espace Anesthésie gagne une étape **Décision** entre Paraclinique et Conduite. `READY_FOR_INCISION` reste **calculé**, jamais stocké : un statut stocké se désynchronise du fait qu'il prétend décrire.

**Le dossier du bloc suit son workflow** (ADR-048, amendement du 2026-09-22). `resources/js/utilities/surgicalWorkflow.js` est la source unique des étapes (Chirurgie 5, Anesthésie 3) et de la prochaine action, lues sur le statut réel de `SurgicalRequest` avec le droit que le serveur exigera ; `Pages/Surgery/Show.vue` ne recompte rien. Le dossier s'ouvre sur l'étape à faire, une étape qui attend dit pourquoi, et une barre « Prochaine étape » en bas de page ouvre le bon formulaire — ou nomme le droit manquant. Deux pièges sont annoncés : l'intervention ne se corrige qu'« Au bloc » (« Valider le compte rendu » attend l'heure de fin), et la sortie du bloc ne se renseigne plus après la sortie de Chirurgie (proposée d'abord). `ValidateSurgicalReportAction` vérifie le statut avant de valider, dans une transaction : un compte rendu n'est plus enregistré validé sur un dossier qui ne peut pas se clore. Sections : `Components/Surgery/SurgerySection.vue` ; en-tête compact : `EnTeteDossierChirurgical.vue`.

**Les formulaires du bloc et de l'anesthésie s'enregistrent tout seuls** (ADR-048, amendement du 2026-09-22) : `composables/useAutosave.js` envoie la même visite que l'ancien bouton (mêmes route, droits, validation, audit) ~1,5 s après la dernière saisie, avec `preserveState` ; « Enregistrer et continuer » devient « Suivant », qui enregistre ce qui reste puis ouvre la section suivante. `ClinicalSaveStatus` dit l'état réel ; aucun toast (`utilities/autosaveVisits.js`). La consultation Médecine garde son bouton « Enregistrer » (brouillon seulement, ADR-073) ; son bouton principal se nomme « Suivant : <étape> ».

**L'espace Anesthésie se lit comme le bloc** (ADR-048, amendement du 2026-09-22, ADR-099) : les trois étapes partagent `Components/Surgery/AnesthesiaStepHeader.vue` (icône, « étape N sur 3 », état en Badge, avancement des sous-étapes lu sur les mêmes indicateurs que les accordéons) et `ClinicalSubsection.vue` (bloc de champs iconé) ; `ClinicalAccordionSection` accepte un `icon` lucide et marque « Renseigné ». Plus aucun `SurgeryIcon` ni palette codée en dur dans `ConsultationPreAnesthesique`, `ExamenParaclinique`, `ConduiteAnesthesique`. Affichage seulement.

**Réinitialiser un dossier du bloc** (ADR-171, amende ADR-010 pour ce cas) : `ResetSurgicalRequestAction` (`POST /surgery/{demande}/reset`, `surgery.reset`, rôle SURGERY) archive d'abord tout le saisi du bloc et de l'anesthésie dans `surgical_request_resets` (immuable, avec motif, auteur, statut d'avant), le retire, puis remet la demande `PENDING` en gardant intervention demandée, origine et demandeur ; audit `surgery.request.reset`. Refusé si la Pharmacie a déjà servi du matériel du dossier, si la demande est annulée, le passage clos, ou si rien n'est saisi ; une demande de matériel non servie est annulée. Bouton et confirmation (motif + case) : `SurgicalCaseReset`.

**Le « Dossier chirurgical » est généré depuis les données** (ADR-172, complète ADR-048/116/170). Le propriétaire a fourni les quatre pages papier de la clinique ; vérification faite, rien du bloc ni de l'anesthésie n'atteignait le dossier médical, le journal ni le parcours. `App\Support\Documents\SurgicalDossierSheet` **lit** `SurgicalRequest` (fiches d'entrée et de sortie du bloc, traitements, surveillance, complications, checklists) et `AnesthesiaRecord` (consultation, paraclinique, décision ADR-170, conduite) et compose quatre feuilles — une par page, un seul PDF navigateur — servies par `GET /surgery/{demande}/dossier` (`view-surgical-dossier` = `surgery.view` ou `anesthesia.view`), `?feuille=` pour une seule. Chaque feuille est gardée par son droit et servie **restreinte et nommée** sinon ; libellés résolus côté serveur ; case vide = non renseignée. Divergence signalée : la case « Autorisation d'opérer » imprime la décision de l'ADR-170, l'ancien `surgery_authorized` n'est imprimé que s'il a été coché. `TreatmentJournal::surgeryRows()` (`surgery.view`) et la section « Bloc opératoire » de `MedicalRecordSheet` (`surgery.view`, anesthésie avec `anesthesia.view`) complètent le dossier médical. Bouton « Imprimer le dossier » (`SurgicalDossierPrintMenu`) dans les deux espaces. Aucune permission nouvelle, aucune migration.

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
Patient, création d'un Episode unique, choix `SELF`/`MUTUAL`/`STAFF`, puis
confirmation. Le catalogue initial exige aussi un parcours Réception configuré.

**Besoin, prochaine étape, visibilité, prise en charge : cinq notions séparées** (ADR-177,
amende ADR-030/053/068/135/146, remplace ADR-124). Le besoin explique la venue et reste facturé ;
il **n'ouvre plus aucune file** Soins, Médecine ou Maternité. `PlanEpisodeRoutingAction` ne crée
plus que les demandes techniques (analyses → `LabRequest` ; acte du bloc → `SurgicalRequest`) et,
pour une urgence, les deux orientations Soins + Médecine de l'exception ADR-021/056. « Besoin à
préciser » ne force plus les Soins. L'accueil compte six étapes (Besoin, Estimation, Patient,
Passage, Prise en charge, Confirmation) : plus de « Routage » ni de « destination initiale ». À la
Confirmation, une **prochaine étape suggérée** facultative et multiple (`episode_reception_next_steps`,
`ReceptionNextStep`, `SetEpisodeReceptionNextStepsAction`, audit `episode.next_steps.update`) —
indicative, sans aucun effet : ni orientation, ni visibilité ; corrigeable depuis le détail du
passage (`PUT /reception/passages/{uuid}/prochaines-etapes`, `episodes.update`). **Tout passage
ouvert et accueilli est visible** de Soins, Médecine et Maternité par le tableau partagé
`ActiveEpisodeBoard` / `ActivePassageBoard.vue`, en **trois blocs exclusifs** — En attente (par
défaut, chaque patient numéroté par ordre d'arrivée, n° 1 « Prochain », urgence non vue épinglée
sans numéro), En cours chez moi, Terminés chez moi — plus deux filtres (Suggérés pour moi, dans la
file ; Urgences) ; comptes serveur, une ligne par passage sans rien de clinique ni de financier,
gestes donnés par adresses serveur. La **prise en charge**
est un vrai geste (`TakeChargeOfEpisodeAction`, `POST /{care|medicine|maternity}/passages/{uuid}/prendre-en-charge`)
qui accepte l'orientation qui attend ou en crée une ; regarder ne crée ni orientation, ni
consultation, ni fiche. Le n° de file (`ActiveEpisodeBoard::queueNumbers()`) couvre toute la file
d'attente, par ordre d'arrivée à la clinique : c'est le seul calcul de numéro de l'application.
`EpisodeOrientation` reste : elle ne dit plus que les orientations et prises en charge réelles.

**Par où le passage devrait entrer** (ADR-177, amendement du 2026-09-23). `App\Support\EpisodeEntryPath`
lit la suggestion de l'accueil et le parcours du besoin (ADR-030), la suggestion l'emportant.
La Médecine qui prend un patient attendu aux Soins reçoit une fenêtre (`CARE_FIRST`) : « Faire les
soins moi-même » (seulement avec `care.create` + `care.update` + `care.view`, adresse servie par le
serveur), « Consulter quand même » (puis le garde-fou de la file, ADR-121) ou Annuler — rien n'est
refusé. Les Soins devant un patient attendu directement en Médecine (`MEDICINE_ONLY`, rien ne
désignant les Soins) ne reçoivent qu'une information, et `TakeChargeOfEpisodeAction` refuse sous
verrou (`refusalMessage()`). Jamais soumis : l'urgence, une vraie orientation en attente vers ce
service (soin demandé par le médecin), et côté Médecine des Soins déjà en cours ou terminés. La ligne
porte `pathway` (servi à qui pourrait prendre) et un mot sous l'état ; un patient attendu ailleurs
reste visible des Soins, sans adresse, sans n° de file et hors du garde-fou (`status` NONE).
Composant : `EntryPathConfirm.vue`. Aucune permission nouvelle, aucune migration.

**Le panier d'arrivée porte deux rayons** (ADR-104, amende ADR-053, ADR-028
et ADR-049/050) : les désignations/consultations avec leur tarif, et la
Pharmacie avec son prix de vente et son stock disponible. `ReceptionCartKind`
(`SERVICE` | `MEDICINE`) voyage sur chaque ligne ; une ligne sans `kind` est
une prestation, comme l'étaient tous les brouillons antérieurs. Les deux
rayons ne suivent pas le même circuit : une prestation ouvre une file
clinique et rejoint la facture du passage, un médicament réserve du stock en
FEFO et part sur un **ticket Pharmacie distinct** — une facture mixte
partiellement payée rendrait indécidable ce que la Pharmacie peut délivrer
(ADR-049). L'estimation affiche donc deux sous-totaux.

Un passage venu **uniquement pour des médicaments** n'ouvre aucune
orientation : il passe directement en `PENDING_SETTLEMENT` (sinon il
resterait `PENDING_ORIENTATION` indéfiniment, le trou qu'a bouché l'ADR-090)
et l'écran bascule vers `/cash` avec la référence du ticket, qui traverse le
choix du poste de caisse. L'étape « Prise en charge » lui propose alors les
deux seules suites qui ont un sens — « Ajouter une prestation » ou
« Terminer — envoyer à la Caisse » — au lieu d'un choix de couverture qui ne
s'applique pas et qui refusait de calculer sur zéro prestation.
`financial_mode` est résolu à `SELF` s'il est encore `null` : le ticket est
au tarif Sans mutuelle, donc à la charge du patient, et `null` afficherait à
tort « contexte financier à régulariser » (ADR-051). Un mode explicitement
choisi n'est jamais réécrit. La **Caisse** encaisse, la **Pharmacie** délivre :
l'ADR-012 et l'ADR-013 sont intactes.

La **vente comptoir anonyme est retirée** : toute vente de médicament passe
par la Réception, sur un dossier patient et un passage.
`CreateExternalDispenseAction` reste l'unique chemin qui crée une vente, et
`pharmacy_dispenses.patient_id`/`episode_id`, déjà nullable, la rattachent au
passage sans migration. `GET /pharmacy/counter-sales/create` redirige vers
`/reception/patients` ; les ventes déjà enregistrées restent lisibles
(ADR-010). Côté droits, `pharmacy.counter_sales.create` quitte `PHARMACY` et
rejoint `RECEPTION`, avec `medicines.view` et `stock.availability.view` — la
paire de lecture que l'ADR-036 accorde déjà à `MEDICINE`, sans aucun droit de
mutation `stock.*`.

Aucune analyse Laboratoire n'est activée par cette évolution.
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
porte aucun statut de règlement : le CDC n'en définit aucun. **Aucune sortie, de quelque type que ce soit, tant qu'une prestation attend d'être portée sur une facture**
(ADR-090, amendement du 2026-09-20) : le reste à payer ne compte que les factures, et « payé comptant » sur un
compte à zéro laissait partir le passage avec ces montants perdus (cas réel : 70 000 Ar ajoutés après le règlement
de la première facture). La fenêtre propose « Facturer ces prestations »
(`POST /reception/passages/{episode}/facturer-prestations`, `billing.create`, valide si `billing.validate`) ; le
serveur refuse de toute façon. Sur « Sorties & règlements », une **sélection multiple** (50 au plus) propose : sortie « payé comptant » en lot
(comptes réellement soldés), facturation en lot, fiches de sortie groupées et export Excel. L'écran n'envoie que des
UUID ; chaque passage est jugé séparément par l'action qui le juge seul, un refus n'empêche pas les autres et un
rapport nomme la raison (ADR-090, amendement du 2026-09-20 ter). Dette validée et évasion ne se décident jamais en
lot. La dette validée (`debts.authorize`) **et** l'évasion (`debts.record_escape`) sont deux droits que le Super
Administrateur accorde : les deux options restent affichées mais verrouillées sans leur droit, et le serveur refuse
(ADR-090, amendement du 2026-09-20 bis). Une évasion
survenue avant la sortie médicale n'est pas enregistrable — elle exigerait
d'annuler des orientations cliniques actives, règle absente du CDC. Voir
ADR-090.

**La « FICHE DE SORTIE » papier s'imprime une fois la sortie administrative
prononcée** (ADR-116), gardée par `episodes.settlement.view` comme la liste
dont elle part. Le papier ne portait qu'une seule date de sortie et un seul
jeu de cases ; la feuille montre les deux faits que le dossier distingue
depuis longtemps — la sortie **médicale** (ADR-035) et la sortie
**administrative** (ADR-090, CDC §33.3) — plutôt que de forcer l'un dans
l'unique case du papier. Elle porte un QR encodant le numéro de passage
(`episode_number`), contrôlé ensuite au poste de gardiennage
(`/guarding`, `guarding.*`) : `RecordExitControlAction` y constate le
départ physique sans jamais décider une sortie déjà prononcée par la Caisse,
refuse une sortie « évadé » (déjà partie sans passer la porte) et n'accepte
qu'un seul contrôle par passage (contrainte d'unicité). Ce catalogue de
permissions existait depuis l'origine, seedé au profil GUARD (ADR-033), sans
qu'aucun écran ne le vérifie ; c'en est le premier consommateur réel.

La page `/passages/{episode}` (« Détail du passage ») agrège en lecture seule
orientations, fiche Soins, consultations Médecine et facturation d'un même
passage, déjà accessibles séparément par module. Chaque section reste
protégée côté serveur par la permission qui possède réellement la donnée ;
`patients.view`, qui protège la route elle-même, ne suffit à en exposer
aucune. Voir ADR-054.

**Le parcours d'un passage est composé une seule fois** (ADR-117, complète
ADR-054 et ADR-055). Le dossier du patient et le « Détail du passage »
racontaient chacun le parcours à partir des seules orientations : deux
orientations « Soins » se lisaient comme un doublon alors que ce sont deux
demandes distinctes du médecin (`CreateEpisodeOrientationAction` en ouvre une
nouvelle dès que la précédente est terminée), et la suite décidée à chaque
demande — retour en Médecine ou sortie directe — n'apparaissait nulle part,
pas plus que la Réception, la Pharmacie ou la Caisse.
`App\Support\EpisodePathwayTimeline` compose désormais une chronologie unique
(Réception, orientations, Pharmacie, factures, encaissements, sortie) que les
deux écrans affichent sans rien recalculer : `EpisodePathwayList` (détail) et
`EpisodePathwayTrail` (frise du dossier), `episode.pathway` remplaçant
`episode.orientations`. Un même service visité plusieurs fois est numéroté
(« Soins 1 », « Soins 2 ») ; chaque demande de soins affiche d'où elle vient
(`Médecine → Soins`), qui l'a faite, la suite décidée
(`care_orders.requires_return_to_medicine` : intention déclarée, jamais un fait
accompli — la consultation reste ouverte pendant les soins, ADR-088) et ses
actes, lus sur les actes réellement enregistrés. L'étape « Sortie — à prononcer
par la Réception » n'existe que lorsque le passage attend réellement sa sortie
administrative (ADR-090). Chaque section garde la permission qui possède sa
donnée (`pharmacy.view`, `billing.view`, `payments.view`, `care_orders.view`) :
sans le droit, l'étape n'est pas servie — jamais servie vide. **L'ordonnance du
prescripteur est aussi une étape** : un médecin sans `pharmacy.view` voyait son
ordonnance dans le passage mais aucun passage à la Pharmacie au parcours.
Avec `prescriptions.view`, l'ordonnance et la dispensation qu'elle a déclenchée
ne font qu'une étape (« Pharmacie · Ordonnance », `Médecine → Pharmacie`) dont
il lit l'issue — transmise, délivrée, annulée — mais jamais le règlement, qui
reste à la Pharmacie et à la Caisse. Une ordonnance faite de lignes hors
référentiel ne crée aucune dispensation : l'étape s'appelle « Ordonnance » et
le dit, au lieu d'annoncer un passage qui n'a pas eu lieu. La facture et
l'encaissement d'un ticket de la Pharmacie portent la mention « Ticket
Pharmacie ». Aucune permission nouvelle, aucune migration.

**Tous les journaux de traitement d'un patient tiennent dans un seul document**
(ADR-118, complète ADR-116). Le journal « Dossier médical – Traitement » se
lisait un passage à la fois : un patient qui revient a autant de journaux que de
passages, sans moyen de les lire ni de les remettre ensemble.
`/patients/{patient}/journaux-de-traitement` (`patients.view` **et**
`treatment_journal.view`) sert, pour chaque passage, ce que
`TreatmentJournal::rows()` sert déjà à la feuille de ce passage — mêmes sources,
mêmes permissions par source : la page réunit, elle n'élargit rien. Une
couverture (identité et tableau des passages) puis une feuille par passage qui
porte au moins une ligne, chacune sur sa page ; un passage sans ligne est listé
sans occuper une page blanche. La page est en lecture seule. **Le PDF est celui
du navigateur** (ADR-070) : « Télécharger le PDF » ouvre l'impression sous un
titre qui devient le nom du fichier (`utilities/pdfDownload.js`, rétabli à
`afterprint`), une seule impression donnant un seul fichier. Le bouton est dans
l'en-tête du dossier patient, sur l'onglet Passages (avec un « Journal » par
passage) et dans la fenêtre d'un patient de la file Soins ; il n'apparaît
qu'avec `treatment_journal.view`. La grille est un composant partagé
(`TreatmentJournalTable`) entre la feuille d'un passage et ce document.

**La file Soins compte les passages et nomme les demandes** (ADR-118). Elle
annonçait « 2 passages » pour un seul passage à deux demandes de soins : la file
groupait par patient et appelait « passage » ce qui est une orientation.
`App\Support\CareRequestSummary` porte, une fois, ce que le parcours du passage
(ADR-117) et la file disent d'une demande — qui l'a faite, la suite décidée,
les actes lus sur `care_record_procedures` — et les deux écrans l'appellent.
Les actes exigent `care_orders.view`, le routage n'est pas gardé. Plusieurs
demandes peuvent partager une orientation Soins encore active
(`firstOrCreate` sur `active_key`) : elles sont regroupées, jamais indexées une
à une, sinon la première disparaîtrait. Le dossier patient (`Patients/Show.vue`)
est passé à shadcn-vue (ADR-099) ; son en-tête ne répète plus le mot que le
badge de situation dit déjà. Aucune permission nouvelle, aucune migration.

**Le répertoire des patients dit où chacun a encore besoin d'aller** (ADR-119,
complète ADR-117/118). `/patients` disait « passage en cours » sans dire où :
pour savoir qui attendait le médecin, les soins ou la pharmacie, la Réception
ouvrait chaque dossier. `App\Services\Patient\PatientServiceNeeds` calcule, par
patient, l'ensemble des services qu'il attend — Médecine et Soins (orientation
`PENDING`/`IN_PROGRESS` sur un passage `OPEN`), Pharmacie (demande de
dispensation liée à un patient et non terminée, `PharmacyDispenseStatus::openValues()`,
désormais la seule définition, partagée avec la file Pharmacie). **« Seulement »
est une combinaison exacte** : chaque patient est dans une seule case, la somme
des cases est le nombre de patients, « Tous » en est le total. Cinq cartes
(Tous / Médecine seulement / Soins seulement / Pharmacie seulement / Les 3
services) par `QueueCounters` — la carte est le filtre, cliquer une carte
active la referme — et, dessous, les paires (seulement si quelqu'un s'y trouve)
et « Aucun de ces services » en pastilles. Les comptes sont des **facettes** :
chacun est ce que donnerait un clic sur sa case, recherche/type/urgence
inchangés, jamais calculé depuis la page paginée. Le filtre voyage dans
`?need=` (`MEDICINE`, `MEDICINE,CARE`, `NONE`, `ALL`) ; une valeur inconnue ne
filtre rien, pour qu'un signet périmé ne se lise pas « personne n'attend ».
Chaque ligne porte une pastille par service, en attente (ambre) ou pris en
charge (vert), avec « depuis quand » et le passage en infobulle ; la plus
avancée l'emporte quand un patient a plusieurs demandes du même service. **La
Pharmacie est une information de routage** servie avec `patients.view` — ni
médicament, ni quantité, ni montant, ni « à régler » (ADR-013). Deux requêtes
seulement, sur les patients qui ont un besoin, puis les combinaisons en PHP.
Les quatre cartes de chiffres et la colonne « Catégorie » (« Standard » ne
disait rien, ADR-051) quittent l'écran : le résumé devient une ligne d'en-tête
et les urgences un bouton rouge. La recherche se lance d'elle-même après une
courte pause. Aucune permission nouvelle, aucune migration.

Le répertoire se classe par **onglets** (ADR-120) : Tous / Besoin en cours / En
attente de règlement / Aucun passage ouvert — les états du badge de la ligne
(`presenceState`), exclusifs (`?status=open|settlement|none`). Compteurs
calculés par le serveur, chacun ce que donnerait un clic ; les cartes de besoin
suivent l'onglet. Les pastilles « Autres situations » ont été retirées (`?need=`
accepte toujours toutes les combinaisons).

Le répertoire se trie A → Z (`?sort=`), se filtre par initiale (`?letter=`) et s'exporte en Excel tel que filtré
(`patients.export`, ADMINISTRATION, audité — ADR-133). Il distingue **Patients normaux / VIP** : VIP = au moins N
passages ET M Ar encaissés (paiements `COMPLETED`) sur les X derniers mois. Seuils **par site**, réglés depuis
`/super-admin/patient-vip` (`patient_vip.*`) et poussés par l'API du site ; catégorie calculée, jamais stockée ; sans
seuil configuré, personne n'est VIP. Simple repère : aucun tarif, droit ni remise n'en dépend.

**Prendre un patient qui n'est pas le premier demande confirmation, aux Soins
comme en Médecine** (ADR-121) : « Un patient attend avant celui-ci » (ou « Une
urgence… »), avec qui attend devant et depuis quand. On demande, on n'interdit
pas — le serveur ne bloque rien. Ne comptent que les patients encore en attente,
jamais un autre passage du même patient ; une page autre que la première le dit.
`useQueueSkipGuard` + `QueueSkipConfirm` sont la règle unique des deux files.

**Un patient pris en charge par erreur aux Soins se remet en file** (ADR-122) :
« Remettre en file » (`ReleaseCareOrientationAction`, `POST /care/orientations/{o}/release`)
le repasse « en attente » **à sa place** — l'ordre vient de `oriented_at`, jamais
touché. Réservé au soignant qui l'a pris, tant que rien n'est enregistré depuis
(fiche, acte, matériel, « non réalisé ») ; un brouillon non enregistré est écarté.
Audité `care.orientation.release`. Même geste en Médecine (ADR-127).

**Un patient pris en charge par erreur en Médecine se remet en file** (ADR-127) :
`ReleaseMedicineOrientationAction`, `POST /medicine/orientations/{o}/release`, réservé
au médecin qui l'a pris, tant que la consultation ouverte est **restée vierge**
(motif d'office inchangé, aucune saisie, étape, diagnostic, demande, ordonnance,
sortie ni orientation). Elle est alors supprimée — `episode_orientation_id` est unique —
et l'orientation repasse `PENDING` à sa place (`oriented_at`) ; audit
`medicine.orientation.release`. Une seule ligne clinique suffit à refuser : la suite
passe par la clôture et la réouverture (ADR-076, ADR-096).

**La fiche de soins compte cinq étapes** (ADR-123, amende ADR-032) : la
transmission à Médecine — deux champs facultatifs, `diagnostic_note` et
`transmission_reason` — n'a plus son écran ; elle est un bloc de l'étape
« Terminer », affiché seulement si le parcours prévoit une transmission
(`care_transmission_expected`), en **colonne de droite** à côté du récapitulatif,
en **texte riche** assaini côté serveur (`ClinicalRichTextSanitizer`) et lu en HTML
assaini (`transmission_reason_html`) par Médecine, le détail du passage, le dossier
imprimé et Chirurgie.

**La file Soins n'a que deux onglets** (ADR-124, **remplacée par l'ADR-177** : la page Soins lit le tableau partagé des passages) : « À prendre aux Soins » et

**La suite des Soins se choisit à l'étape Terminer** (ADR-166, amende ADR-030/032/123). La désignation d'arrivée **propose**, l'infirmier **décide** : « Suite après les soins » offre « Transmettre au médecin » et « Terminer aux Soins », la suite prévue pré-cochée (« Prévu à l'arrivée »), vide pour un besoin inconnu. `CompleteCareAndOrientToMedicineAction` reçoit `care_outcome` (`MEDICINE`/`FINISH`, absent = suivre le parcours) : la carte prévue se lit seulement (bordure bleue), la carte non prévue demande un motif **dans les deux sens** (amendement du 2026-09-21) : `care_outcome_reason`, gardé sur `episode_orientations.completion_reason`, audité `care.orientation.finish_at_care` ou `care.orientation.send_to_medicine`, repris dans la raison de l'orientation Médecine, relu sur la fiche et dans le parcours du passage avec son sens (`EpisodeOrientation::offPlanOutcome()`) ; un parcours Soins seuls terminé garde son acte obligatoire ; envoyer au médecin un patient prévu aux Soins seuls ouvre la transmission (`expectsMedicalTransmission($episode, $chosen)`). Rien à choisir quand un ordre de soins du médecin est actif (ADR-055) ou que Médecine a déjà le patient (`CareWorkflow::medicineAlreadyInvolved()`, ADR-085). **La consultation prévue reste facturée** (arbitrage du propriétaire) : rien n'est annulé par les Soins (ADR-012). Changer seulement la suite termine par `POST …/complete` sans écrire de fiche vide ; le brouillon (ADR-073) garde le choix. `orient_to_medicine` : vrai = Médecine, faux = suivre le parcours. Aucune permission nouvelle.

**Reprendre la prise en charge Soins d'un collègue** (ADR-167, construit ce que l'ADR-085 laissait hors périmètre). Seul celui qui a pris le patient termine ou transmet (ADR-085) : un patient pris par un autre compte laissait l'infirmière sans aucun choix à l'étape Terminer. `TakeOverCareOrientationAction` (`POST /care/orientations/{o}/take-over`, `care.complete`) fait passer `accepted_by` au compte qui reprend, avec un **motif obligatoire**, audité `care.orientation.take_over` ; permis sur une orientation Soins en cours d'un autre compte et un passage ouvert, refusé sinon. `accepted_at` **n'est jamais réécrit** — la remise en file (ADR-122) compte le travail depuis le début réel des soins ; `taken_over_at`/`taken_over_from`/`takeover_reason` gardent la dernière reprise pour l'écran. La « Suite après les soins » se montre verrouillée au lieu d'être masquée, **sans aucun bandeau** (qui a le patient se lit dans l'en-tête) ; la carte prévue se lit seulement ; la carte non prévue ouvre **l'unique fenêtre** de reprise, « Changer la suite prévue » : un seul motif, puis une case « Reprendre la prise en charge » au bas, jamais cochée d'avance, exigée avec le motif pour confirmer. La suite est ensuite sélectionnée et le motif prérempli, sans rien valider. Garder la suite prévue et reprendre passe par le bouton « Reprendre la prise en charge » du pied de l'étape Terminer, qui ouvre la même fenêtre. Aucune permission nouvelle.
« Orientés · en attente du médecin » (Soins terminés, orientation Médecine encore
en attente). Un patient que le médecin a accueilli, ou qui n'a pas de médecin à
attendre, quitte cette page : il vit dans le module Patients. L'attente se compte
depuis que Médecine a été sollicitée (`doctor.since`), et il porte **le n° d'ordre de
la file Médecine** (`doctor.queue_number`, `EpisodeQueuePresenter::medicineQueueNumbers()`,
calculé sur toute la file et partagé avec Médecine, qui ne numérote plus la seule page
affichée) : un patient n'a jamais deux numéros. `?filter=` n'accepte plus que `active` et
`waiting_doctor`.

**Les constantes se lisent selon l'âge** (ADR-125, étend ADR-038 à 041) :
`VitalSignAgeReference` est la table unique (plages de FC de l'enfant, hypotension
de l'enfant PALS, bornes d'invraisemblance) ; `HeartRateAssessment`,
`BloodPressureAssessment` et `TemperatureAssessment` la lisent, Vue reçoit les
références sans recopier un seuil, et `CareRecordReadModel` passe l'âge aux mêmes
évaluations. Le sexe ne change aucun seuil — la tension et l'IMC de l'enfant
exigeraient des tables de percentiles que l'application n'a pas ; sans âge, aucune
plage n'est devinée. Une valeur critique (`danger`) ou improbable (poids/taille
hors de tout patient de cet âge, IMC <8 ou >70) déclenche un toast, posé (900 ms) et
dédoublonné ; un simple avertissement reste sous son champ. **Chiffres à faire
valider par un médecin de la clinique.**

**Tabac et alcool se lisent aussi selon l'âge** (ADR-126) : « Oui » avant 10 ans est
peu vraisemblable (rouge + toast), chez un mineur c'est à noter (ambre) ; un âge de
110 ans ou plus se signale (bandeau + toast) puisque les repères de constantes en
dépendent. Les seuils vivent dans `VitalSignAgeReference`, servis par
`vitalPlausibility`. Dans Diabète, Tabac et Alcool, « Oui » coché est rouge et
« Non » vert. Seuils à valider par un médecin.

---

**Les trois profils Soins sont toujours affichés** (ADR-158, amende ADR-134) : la barre disparaissait quand un seul espace était accessible, et l'écran se lisait comme une version ancienne du module. L'onglet sans droit est désormais **verrouillé** (cadenas, `aria-disabled`) et nomme la permission qui l'ouvre — même parti pris que les types de sortie de l'ADR-090 et que le refus de l'ADR-154. Le **menu latéral**, lui, ne propose toujours que les espaces accessibles (ADR-115) : une entrée de menu est une promesse de navigation, un onglet est la carte du module où l'on est déjà.

**La file Soins s'ouvre avec `care.create`** (ADR-157) : `care.update` servait deux choses — corriger une fiche depuis la consultation (accordé à MEDICINE, ADR-093) **et** ouvrir la file des infirmières (route, menu, onglet). Un médecin y entrait donc sans être soignant. `care.create` est le droit d'ouvrir une fiche, donc de soigner ; la frontière était déjà posée par l'ADR-093, qui refuse explicitement `care.create` à Médecine. Le médecin garde la lecture (`care.view`) et la correction (`care.update`, par le lien de sa consultation) ; un site qui veut l'inverse coche `care.create` au socle (ADR-064) — aucun rôle n'est codé en dur (ADR-152).

**Soins réunit trois profils** (ADR-134, complète ADR-067/048) : le menu latéral porte une seule entrée mère « Soins » (Infirmière `/care`, Maternité `/maternity`, Anesthésie `/anesthesia`) et les trois pages affichent la même barre d'onglets (`Components/Care/SoinsTabs.vue`). Chaque onglet reste une **vraie page** avec son contrôleur, ses données et sa permission (`care.update`, `maternity.view`, `anesthesia.view`) : un onglet n'apparaît que si le compte peut ouvrir sa page, et la page courante s'affiche toujours. La barre n'est qu'ergonomie, les routes revérifient. Aucune permission nouvelle.

**Les files Maternité et Anesthésie suivent le parcours réel** (ADR-135, complète ADR-134/124 ; pour la Maternité, **amendée par l'ADR-177** : ses vues sont celles du tableau partagé, `MaternityQueue` ne sert plus que les suites — médecin, césarienne — lues sur chaque ligne). Vues **exclusives** dont la somme des comptes est le nombre de dossiers, comptes calculés par le serveur, carte = filtre, valeur inconnue → travail à faire. Maternité (`App\Services\Maternity\MaternityQueue`) : « À prendre » (en attente) / « En cours » (prises en charge) / « Orientées vers Médecine » (Maternité terminée + orientation Médecine source Maternité encore en attente/en cours) / « Terminées » ; chaque ligne porte l'état chez le médecin et la césarienne demandée. `CompleteMaternityOrientationAction` a deux issues explicites — terminer (le passage passe `PENDING_SETTLEMENT` si plus aucun service n'a la patiente, ADR-054) ou terminer et orienter vers Médecine (via `CreateEpisodeOrientationAction`, orientation active réutilisée, message facultatif) ; réservée à `maternity.complete`. Anesthésie (`AnesthesiaCaseStage`) : À évaluer / Transmis à Chirurgie / Au bloc / Terminés, lues sur `SurgicalRequestStatus` et les dates de validation de l'`AnesthesiaRecord`, demandes annulées masquées. Les trois pages partagent `SoinsWorkspaceHeader`. Aucune permission nouvelle, aucune migration.

**Le dossier Maternité s'adapte à l'acte demandé** (ADR-136, complète ADR-067/068/073). Le CDC ne décrit aucun contenu clinique par acte : aucun champ n'est ajouté, aucune section verrouillée. `App\Support\MaternityActProfile` dit par **code d'acte** (jamais par libellé) quelles sections l'acte attend ; la page sert `plannedProcedures` (ce que la Réception a demandé, `done` compris), `actProfile` (sections mises en avant, `expected_newborns` — deux fiches vides pour un accouchement gémellaire) et `recordDraft`. Les actes demandés s'enregistrent en un clic par l'endpoint existant ; « Autres » exige sa précision (serveur et écran). La saisie est conservée côté serveur par compte (`maternity_record_drafts`, routes `/maternity/orientations/{o}/draft`, non auditée, supprimée à l'enregistrement, à l'annulation ou à la fin de prise en charge). Le catalogue Maternité compte 20 actes : Aspirateur bébé, IEC et Nursie (sans définition, à préciser) ajoutés, `FP-INJECTABLE` déplacé du Planning familial, sans changer de code. Un acte ajouté au-delà de la demande n'est pas facturé — règle non définie.

**Les champs du dossier Maternité rappellent des repères pendant la saisie** (ADR-137, complète ADR-136 et applique le principe des ADR-125/126). Une aide au dépistage : un message `info` / `warning` / `danger` sous le champ, rien ne bloque l'enregistrement. Les seuils sont écrits une seule fois dans `App\Support\MaternityReference`, servis à la page (`maternityReference`) et lus par `UpdateMaternityRecordRequest` pour ses bornes ; `utilities/maternityChecks.js` ne recopie aucun chiffre. Repères : poids de naissance (unité en grammes, conversion en kg, faible / très faible / macrosomie), Apgar, terme, hauteur utérine (comparée au terme entre 20 et 36 SA), rythme fœtal, parité ≤ gestité, dates. À partir des dernières règles, le terme estimé (Naegele) et l'âge de la grossesse sont **proposés** — bouton « Utiliser » seulement si le champ est vide. Seuils à faire valider par la clinique ; aucun n'est propre au sexe, à la parité ou à une grossesse gémellaire.

**Les actes Maternité s'enregistrent dans un panier** (ADR-138, amende ADR-136). « Actes disponibles » (boutons + filtre) à gauche, « Panier d'actes » à droite : un acte n'entre qu'une fois, quantité et précision par ligne, « Autres » exige sa précision. `POST /maternity/orientations/{o}/procedures/batch` (`maternity.procedures.manage`) passe chaque ligne par `RecordMaternityProcedureAction` dans une seule transaction — une ligne refusée annule tout et est nommée (`procedures.N.notes`), comme la livraison de stock (ADR-098). Le panier est la section `basket` du brouillon ; l'enregistrer la retire seule (`MaternityRecordDraft::forgetSection`). L'enregistrement direct d'un acte demandé à la Réception reste un clic.

**Soins bébé par nouveau-né, soins mère uniques** (ADR-139, amende ADR-067) : `newborn_data.newborns[i].care_notes` porte les soins de chaque bébé (avec des jumeaux, ils diffèrent) ; `maternal_care_notes` reste une seule note. `baby_care_notes` n'est ni supprimée ni migrée : une ancienne note commune reste affichée (« note générale ») seulement si elle existe, jamais proposée sur un dossier neuf.

**Un acte Maternité enregistré se corrige ou se retire** (ADR-140, amende ADR-138) : `ModifyMaternityProcedureAction` corrige la quantité et la précision (jamais l'acte) ou le retire par Soft Delete avec auteur et motif (`SoftDeletable`) — l'acte quitte la liste, l'audit garde la trace. Le personnel Maternité (`maternity.procedures.manage`) le peut sur les actes d'une collègue, **sauf ceux enregistrés par un médecin**, verrouillés pour lui mais visibles (cadenas) ; l'auteur garde toujours la main. Le verrou lit l'instantané `performed_by_role` pris à l'enregistrement, jamais le rôle actuel ; le rôle classe l'auteur, il n'autorise rien. `can_modify` / `locked_by_physician` viennent du serveur, qui revérifie sur la ligne verrouillée (prise en charge en cours, acte du même passage).

**Un acte Maternité est facturé, et son matériel part à la Pharmacie** (ADR-141, ADR-142). Un acte enregistré par une sage-femme ne produisait aucun élément facturable : seuls ceux de la Réception l'étaient. `RecordMaternityProcedureAction` rattache l'acte à la facturation de l'arrivée quand elle existe (`billing_origin = PLANNED`, `PlannedServiceBilling`) et le facture sinon (`OWN`, `ClinicalActBiller`, clé `maternity_procedure:{uuid}`) ; un échec financier n'empêche jamais l'acte. Retirer un acte annule seulement une facturation `OWN` encore en attente, changer sa quantité la refait, et un acte déjà sur facture ou rattaché à la Réception n'est jamais touché — seule la Caisse corrige un montant facturé. Aucun montant n'est servi à l'écran ; `billing.state` dit « À la Caisse », « Sur facture » ou « Non facturé — à régulariser » (ADR-103). Le matériel utilisé emprunte le circuit des consommables Soins (`CareConsumableRequest`, `source_module = MATERNITY`) dans le même envoi que le panier (`consumables[]`, tout ou rien) : parapharmacie **plus** les produits que l'administration a configurés comme matériel habituel d'un acte Maternité (DIU, implant, injectable — `CareConsumableDirectory::eligibleMedicines`), suggérés à l'ajout de l'acte et jamais imposés ; la Pharmacie sort le stock en FEFO sans attendre le règlement, la Caisse encaisse, et la file affiche l'origine Soins/Maternité. Rien n'est pré-configuré : DIU, implant et Sayana Press doivent exister en Pharmacie, avoir un prix, puis être associés à leur acte.

**La Maternité est une section du dossier médical, bébés compris** (ADR-143, complète ADR-116). Le dossier médical d'un passage (`/passages/{uuid}/dossier-medical`) est un seul document qui relit ce qui est consigné ailleurs : un dossier Maternité séparé aurait dupliqué identité, allergies et constantes. `App\Support\Documents\MaternitySheetSection` relit `maternity_records` (grossesse, prénatal, travail, accouchement, soins de la mère, actes, observations) et sert **un bloc par nouveau-né** — une fiche de bébé jamais remplie n'est pas listée, un Apgar 0 est une valeur. Absente si le passage n'a pas de dossier Maternité ; `{ restricted: true }` sans `maternity.view` (rien ne filtre) ; une case vide reste `null`. Tant qu'un bébé n'est pas un patient, son suivi se lit dans le dossier de sa mère ; le faire patient relié à sa mère reste à décider avec la clinique (numérotation, facturation, Pédiatrie).

**Le nouveau-né devient un patient relié à sa mère** (ADR-144, réalise l'étape 2 de l'ADR-143). Un bouton « Créer le dossier du nouveau-né » sur la fiche du bébé (`maternity.newborn.manage`, pendant ou juste après la prise en charge, passage ouvert) : `CreateNewbornPatientAction` crée le patient — numéro **dérivé de celui de la mère** (`PatientNumberGenerator::newborn()` : `A-26-0009-B1`, rang de naissance, jamais réutilisé même archivé), naissance = date de l'accouchement consigné (refusée sinon, jamais devinée), sexe M ou F (le dossier patient n'a pas d'« indéterminé »), nom saisi (proposé depuis celui de la mère), prénom facultatif. Il ne passe pas par `CreatePatientAction` : la détection de doublons bloquerait des jumeaux ; l'unicité de `patient_newborn_links` (dossier Maternité, `uuid` du bébé) rend le geste idempotent. Les fiches de bébés n'ont pas d'identifiant propre : le serveur leur donne un `uuid` au moment où un patient en dépend, et `SaveMaternityRecordAction` retire tout `uuid` inconnu et **refuse de retirer la fiche d'un bébé relié**. Aucun passage n'est ouvert et rien n'est facturé au nom du bébé : ses soins restent sur le compte de la mère. Le lien se lit dans le dossier Maternité (badge), le dossier patient (`family`, dans les deux sens), le « Détail du passage » (bloc « Nouveau-nés », `maternityBabies`, gardé par `maternity.view`) et le dossier médical du bébé (section « Naissance », sans rien de la mère, gardée par `maternity.view`). Les bébés déjà consignés ne sont pas migrés d'office : chacun reçoit son dossier par le même geste.

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
l'ADR-091). Le **parcours Médecine** est migré dans son entier (2026-09-17) :
les six étapes de l'assistant, le stepper et les vingt-deux composants
cliniques. Seul le **corps des documents imprimés** conserve ses couleurs
codées en dur — il décrit du papier, où `bg-white` est la vérité et non un
défaut de thème ; seule leur barre d'écran a migré. Tout écran neuf ou retouché est écrit avec la couche
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

**Le dossier médical d'un bébé se lit comme celui de sa mère** (ADR-145, complète ADR-144). `GET /patients/{patient}/dossier-medical` (`patients.view`) sert le même document que le dossier médical d'un passage, sans passage : `MedicalRecordSheet::presentForPatient()`. Pour un bébé, un bloc « Naissance — à la clinique » (`MaternitySheetSection::birth()`) reprend mère, date, rang, sexe, poids, Apgar, état et soins — jamais la grossesse, le travail ni l'accouchement de la mère. Des onglets Mère · Bébé 1 · Bébé 2 relient la famille (`MaternitySheetSection::dossiers()`). Un seul composant, `Components/Clinical/NewbornDossiers.vue`, remplace les blocs écrits à la main à la Maternité et au détail du passage ; à la Maternité il est **hors** du `<fieldset disabled>` du dossier terminé, sans quoi la création était grisée. Le sexe (obligatoire, M/F, aucun « indéterminé » inventé) se choisit dans la fenêtre de création quand la fiche ne le porte pas, puis est écrit dans la fiche ; une fiche vide n'est plus refusée en soi. Aucune permission nouvelle, aucune migration.

Le dossier médical d'un bébé est une **feuille de nouveau-né**, pas celle d'un adulte (ADR-145, amendement du 2026-09-20) : identité (date et heure, sexe, lieu de naissance = la clinique, naissance unique ou multiple), mère à joindre (téléphone, adresse), **naissance et accouchement** (mode, terme, complications — lus chez la mère parce qu'ils décrivent la naissance de l'enfant), état à la naissance (poids, Apgar, état, soins) et suivi (allergies, diagnostic). Situation maritale, profession, adresse, tabac, traitements et antécédents familiaux n'y existent pas. Restent chez la mère : gestité, parité, facteurs de risque, travail, délivrance, soins maternels. Ce partage est à faire valider par les sages-femmes.

**Le nouveau-né vit dans le dossier de sa mère** (ADR-146, amende ADR-144 ; **amendée par l'ADR-177** : son dossier patient s'ouvre **depuis la Maternité** — `NewbornDossiers` › « Créer le dossier patient », `POST /maternity/records/{record}/newborns/{uuid}/patient`, `maternity.view` + `newborns.patient.create` — et l'accueil n'a plus de mode « Nouveau-né » ni de routes `/reception/newborns*` ; un bébé né ailleurs est un nouveau patient ordinaire au profil enfant ; le paragraphe qui suit décrit l'état antérieur). Il n'est plus créé patient à l'accouchement : la Maternité le consigne (nom et prénom facultatifs dans sa fiche), et chaque fiche remplie reçoit son identité (`uuid`) **dès l'enregistrement** — c'est elle que la Réception retrouve. Son dossier médical s'ouvre dès sa fiche (`GET /passages/{episode}/nouveau-nes/{uuid}/dossier-medical`, `patients.view` + `newborns.medical_record.view`), même feuille de nouveau-né que s'il était patient, et redirige vers son dossier patient dès qu'il en a un. Au jour de sa consultation, la Réception demande « accouchement chez nous ou ailleurs ? » : chez nous, elle cherche la mère et choisit le bébé dans son arborescence (`GET /reception/newborns?mother=`, `POST /reception/newborns/{record}/{uuid}/patient`, `episodes.create` + `newborns.view`/`newborns.patient.create`) — il devient patient et repart dans le parcours d'arrivée ; ailleurs, c'est un nouveau patient avec une **identité de bébé**, jamais le formulaire d'adulte : nom, naissance/âge, sexe et éventuellement domicile familial ; téléphone, email, profession, pièce d'identité, situation maritale et nombre d'enfants sont masqués et refusés par le serveur. Le parent ou responsable reste le contact de l'Épisode (ADR-034). La Réception ne voit rien de clinique (ni poids, ni Apgar, ni soins). `App\Support\NewbornFiche::displayName()` nomme le bébé une fois pour toutes (« Bébé 2 de RAKOTO » à défaut de prénom). Les règles de l'ADR-144 (numéro dérivé, naissance jamais devinée, sexe exigé, jumeaux, idempotence) sont inchangées, mais la création ne dépend plus d'un passage ouvert. Le bouton « Créer le dossier » de la Maternité est retiré.

**Le profil enfant suit la civilité à la Réception** (ADR-146, amendement du 2026-09-22). Dans « Nouveau Patient », `Enfant fille` et `Enfant garçon` conservent identité, naissance, sexe et domicile familial, mais retirent et font refuser côté serveur les attributs d'adulte : téléphone/email personnels, profession, pièce d'identité, situation maritale et nombre d'enfants. Aucune valeur du parent n'est copiée sur le Patient ; elle reste dans `episodes.emergency_contact_*` pour ce passage (ADR-034).

Les dossiers de bébés ouverts par l'ancien geste de la Maternité et **jamais utilisés** retournent à la fiche de leur mère (migration `2026_10_08_090000`) : le nom rejoint `newborn_data` — l'ancien geste ne l'écrivait que sur le patient —, le dossier patient est retiré et son numéro redevient libre. Un bébé qui a déjà servi (passage, facture, allergie…) reste patient : on ne détruit pas un historique (ADR-010). Le retour est audité `maternity.newborn.patient.revert`. Un bébé **accueilli** reste dans `/patients` comme tout patient ; sa ligne porte « Nouveau-né de RAKOTO Vola » (`newborn_of`, une requête par page) et mène au dossier de sa mère.

Le dossier permanent d'une mère porte une carte « Nouveau-nés nés à la clinique » et sa ligne du répertoire un repère « 1 bébé né ici » (ADR-146, amendement du 2026-09-20 bis). Les bébés sont lus sur les **fiches** (`MotherNewborns::for()`, la même liste que l'arborescence de la Réception), jamais sur les seuls dossiers patients : un bébé y figure dès l'accouchement, avant son premier accueil. Une fiche jamais remplie n'est ni comptée, ni listée. Le nom, le rang et la date sont servis avec `newborns.view` (identité, pas clinique) ; le dossier médical d'un bébé **pas encore patient** exige `newborns.medical_record.view`.

**Le nouveau-né a ses propres droits** (ADR-146, amendement du 2026-09-20 ter). La lecture du dossier d'un bébé reposait sur `maternity.view` — le droit de l'**espace Maternité**, qu'aucun socle de rôle ne porte et que seul le profil sage-femme reçoit (ADR-067) : ni la Réception, ni Médecine, ni Soins ne pouvaient donc ouvrir son dossier depuis celui de sa mère. Trois droits nomment les trois gestes réels : `newborns.view` (l'enfant : nom, rang, sexe, date, s'il est patient — carte du dossier de la mère, repères du répertoire, arborescence Réception, bloc du passage), `newborns.medical_record.view` (son dossier : naissance, poids, Apgar, état, soins, mode d'accouchement — la route, la feuille, le poids affiché et le lien lui-même) et `newborns.patient.create` (en faire un patient à l'accueil). `maternity.view` garde l'espace Maternité et la section Maternité du dossier de la **mère**. Socle : `RECEPTION` reçoit `newborns.view` et `newborns.patient.create` ; `MEDICINE` et `NURSE` y ajoutent `newborns.medical_record.view` — un élargissement volontaire et signalé. `newborns.medical_record.view` n'est **pas** accordée à `RECEPTION` (arbitrage du propriétaire) : le Super Administrateur la coche dans « Rôles & permissions » › **Nouveau-nés** s'il le décide, et la décision est tracée (ADR-064). Sans `newborns.view`, rien de la filiation n'est servi — jamais servi vide, qui se lirait « cette patiente n'a pas accouché ici ». Migration `2026_10_09_090000_create_newborn_permissions`, qui recopie aussi les deux droits sur les comptes détenant déjà `maternity.view` en `ALLOW` ; un `DENY` n'est jamais recopié.

**La feuille « DOSSIER MÉDICAL » ne contourne aucun droit** (ADR-116, amendement du 2026-09-20). Elle affirmait garder ses sections sensibles, mais seules les constantes (`vitals.view`) et les antécédents (`patients.medical_history.view`) l'étaient : un compte de Réception (`patients.view` seul) y lisait le **diagnostic**, les **traitements déclarés** et le **motif d'hospitalisation**, que la page « Détail du passage » lui refuse. Ils sont désormais gardés par `diagnoses.view`, `medical_record.view` et `hospitalization.view`, et la valeur est retirée de la charge utile, pas seulement masquée. Aucune permission nouvelle, et **aucun droit d'impression** : imprimer est le fait du navigateur (ADR-070), un droit « imprimer » ne bloquerait rien. Routes inchangées : `patients.view` pour le dossier d'un passage et d'un patient, `patients.view` + `maternity.view` pour celui d'un bébé pas encore patient.
