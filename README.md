# RIVO — Clinique Saint Georges

Application web de gestion clinique développée pour la Clinique Saint Georges.

Le système couvre deux établissements indépendants :

- Clinique Saint Georges - Mampikony
- Clinique Saint Georges - Ambondromamy

---

## Stack cible

- Laravel 13
- Vue.js
- Inertia.js
- Tailwind CSS
- DashWind Admin Dashboard
- MySQL / MariaDB
- REST API JSON
- Laravel Queue / Jobs

## État actuel du projet

- [x] Laravel 13
- [x] MySQL / MariaDB configuré dans `.env.example`
- [x] Tailwind CSS / Vite
- [ ] Vue.js
- [ ] Inertia.js
- [ ] DashWind
- [ ] Authentification
- [ ] RBAC
- [ ] API inter-sites

---

## Domaines

### Site internet public

```text
https://cliniquesaintgeorges.mg
```

### Application Mampikony

```text
https://clinique-m.rivo.mg
```

### Application Ambondromamy

```text
https://clinique-a.rivo.mg
```

### Super Administration

```text
https://admin.rivo.mg
```

---

## Architecture

Les deux sites sont indépendants.

Chaque site possède sa propre base de données.

```text
MAMPIKONY
clinique-m.rivo.mg
        │
        ▼
Laravel
        │
        ▼
DB_MAMPIKONY
```

```text
AMBONDROMAMY
clinique-a.rivo.mg
        │
        ▼
Laravel
        │
        ▼
DB_AMBONDROMAMY
```

Une seule codebase Laravel est maintenue.

Les deux sites utilisent les mêmes migrations et la même logique applicative.

---

## Communication inter-sites

Aucune connexion SQL directe n'est autorisée entre les deux bases de données.

Tous les échanges entre Mampikony et Ambondromamy utilisent une API REST sécurisée.

Exemples :

- transfert patient ;
- transfert de stock ;
- recherche patient inter-site ;
- échange d'informations autorisées ;
- suivi de transfert ;
- supervision globale.

---

## Super Administration

Le portail central est :

```text
https://admin.rivo.mg
```

Le Super Administrateur communique avec les deux établissements exclusivement via leurs API.

```text
                        admin.rivo.mg
                             │
                    ┌────────┴────────┐
                    │                 │
                    ▼                 ▼
             API Mampikony     API Ambondromamy
                    │                 │
                    ▼                 ▼
                DB_MAMP           DB_AMBO
```

Le Super Admin ne doit jamais accéder directement aux bases de données métiers.

---

# Cahier des Charges Technique

Le Cahier des Charges officiel est maintenu dans un repository GitHub séparé :

```text
https://github.com/GasyCoder/cdc-clinic-george
```

Repository :

```text
GasyCoder/cdc-clinic-george
```

Avant toute implémentation d'une fonctionnalité métier, consulter le CDC.

Les règles locales complémentaires et les décisions techniques validées sont disponibles dans :

```text
docs/CDC_REFERENCE.md
docs/AI_CONTEXT.md
docs/DECISIONS.md
docs/ROADMAP.md
```

---

# Agents IA

## Codex

Les instructions principales de Codex sont définies dans :

```text
AGENTS.md
```

Skill métier :

```text
.agents/skills/clinic-cdc/SKILL.md
```

## Claude Code

Les instructions principales de Claude Code sont définies dans :

```text
CLAUDE.md
```

---

# Règles métier critiques

## Bases de données

```text
Mampikony     → DB indépendante
Ambondromamy  → DB indépendante
```

Aucune communication SQL directe entre elles.

---

## Caisse

Il existe une seule caisse fonctionnelle par site.

Tous les paiements passent exclusivement par :

```text
Réception / Caisse
```

Les modules suivants n'effectuent aucun encaissement :

```text
Pharmacie
Laboratoire
Médecine
Chirurgie
Administration
```

---

## Pharmacie

La Pharmacie gère :

- prescriptions ;
- médicaments ;
- délivrances ;
- stocks ;
- lots ;
- péremptions ;
- inventaires ;
- transferts de stock.

La Pharmacie ne gère jamais :

- caisse ;
- paiement ;
- encaissement ;
- remboursement financier ;
- clôture de caisse ;
- reçu de paiement.

---

## Permissions

Le système utilise un RBAC dynamique.

Rôles principaux :

```text
SUPER_ADMIN
ADMINISTRATION
RECEPTION
MEDICINE
SURGERY
PHARMACY
LABORATORY
```

Convention de permission :

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

laboratory.results.validate

pharmacy.stock.transfer

payments.create
```

---

## Suppression

La suppression normale utilise :

```text
Soft Delete
```

Actions possibles :

```text
delete
restore
view_deleted
force_delete
```

`force_delete` est exceptionnel.

Les données médicales et financières critiques doivent généralement utiliser :

```text
cancel
correct
reverse
archive
```

plutôt qu'une suppression physique.

---

# UI / UX

Le template officiel est :

```text
DashWind
```

DashWind constitue la base UI de l'application.

Frontend :

```text
Vue.js
+
Inertia.js
+
DashWind
+
Tailwind CSS
```

Ne pas introduire un second design system sans validation.

---

# Documentation

```text
README.md
→ présentation générale

AGENTS.md
→ règles Codex

CLAUDE.md
→ règles Claude Code

docs/CDC_REFERENCE.md
→ référence vers le CDC officiel

docs/AI_CONTEXT.md
→ contexte condensé pour les agents IA

docs/DECISIONS.md
→ décisions techniques validées

docs/ROADMAP.md
→ progression du développement

.agents/skills/clinic-cdc/SKILL.md
→ workflow Codex pour les fonctionnalités métier
```

---

# Développement local

Installation des dépendances :

```bash
composer install
npm install
```

Configuration :

```bash
cp .env.example .env
php artisan key:generate
```

Migration :

```bash
php artisan migrate
```

Développement :

```bash
composer run dev
```

Tests :

```bash
php artisan test
```

---

# Source de vérité

Pour toute règle métier :

1. consulter les décisions validées dans `docs/DECISIONS.md` ;
2. consulter le CDC officiel ;
3. consulter le contexte dans `docs/AI_CONTEXT.md` ;
4. analyser le code existant.

CDC officiel :

```text
https://github.com/GasyCoder/cdc-clinic-george
```

Ne jamais inventer une règle métier lorsque le CDC ou les décisions sont ambigus.
