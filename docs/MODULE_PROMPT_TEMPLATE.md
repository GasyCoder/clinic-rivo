# RIVO — Prompt standard pour développer un module, un par un

Ce prompt est destiné à être copié/adapté (remplacer `[MODULE]`) à chaque fois
qu'on attaque un nouveau module métier — par n'importe quel développeur de
l'équipe, humain ou IA. Un seul module par invocation. Ne pas paralléliser
plusieurs modules dans une même session : c'est ce qui a permis de tester
chaque brique en conditions réelles et d'attraper de vrais bugs (mismatch
SSR, résolution d'acteur dans l'Auditor, macro de migration, etc.) avant de
passer à la suite.

---

## Prompt à copier

```text
Continue le projet Clinique Saint Georges — Module : [MODULE]

AVANT DE CODER (obligatoire) :
1. Lis CLAUDE.md, AGENTS.md, docs/AI_CONTEXT.md, docs/DECISIONS.md,
   docs/ROADMAP.md, docs/CDC_REFERENCE.md
2. Consulte le CDC officiel : https://github.com/GasyCoder/cdc-clinic-george
   — trouve et lis la section concernant [MODULE]
3. Inspecte l'implémentation existante (modèles, migrations, routes,
   permissions déjà en place) pour ne rien casser ni dupliquer

ÉTAPE ACTUELLE :
Construire uniquement [MODULE] : modèle(s), migration(s), relations avec les
modèles déjà existants (User, Role, Permission, AuditLog), et rien au-delà
de son périmètre. Ne développe pas les modules suivants de la roadmap.

POUR CE MODULE, IDENTIFIE ET APPLIQUE :
- Règles métier exactes du CDC — ne rien inventer. Si ambigu : STOP, signale
  l'ambiguïté, ne devine pas une règle métier.
- Permissions dynamiques nécessaires (format resource.action), à ajouter
  dans le seeder concerné (voir PermissionSeeder::PERMISSIONS existant)
- Règles de validation (Form Requests)
- Ce qui doit être audité, via le service déjà existant
  app/Services/Audit/Auditor.php (méthode record())
- Ce qui doit utiliser Soft Delete, via le trait déjà existant
  app/Models/Concerns/SoftDeletable.php — et si des données de ce module
  sont "critiques" au sens CDC §11 (force_delete à refuser via
  isForceDeleteProtected())
- Si ce module échange des entités via API inter-sites (CDC §5/§6) :
  prévoir un uuid via le trait déjà existant app/Models/Concerns/HasUuid.php
- Architecture Laravel : Controllers légers, logique dans Actions/Services,
  Policies pour l'autorisation, DTOs/Enums si pertinent

CONTRAINTES :
- Ne touche à aucun autre module
- Aucune donnée médicale/financière fictive
- Respecte les décisions déjà ACCEPTED dans docs/DECISIONS.md — si le CDC et
  DECISIONS.md divergent, signale le conflit, ne choisis pas silencieusement

APRÈS IMPLÉMENTATION :
- Ajoute des tests (Feature + Unit selon pertinence) couvrant : règles
  métier, permissions, validation, audit, soft delete
- Exécute : php artisan test
- Exécute : npm run build (si le module touche au frontend)
- Vérifie en conditions réelles (navigateur / tinker), pas seulement les
  tests automatisés

À LA FIN, fournis :
- Fichiers créés / modifiés
- Décisions prises (et pourquoi)
- Permissions ajoutées
- Tests ajoutés + résultat
- Résultat de php artisan test et npm run build
- Ambiguïtés ou conflits CDC / DECISIONS.md signalés, s'il y en a
- Prochain module suggéré selon la roadmap

Ne commit/push rien automatiquement.
```

---

## Pourquoi ce format

- **Un module = un prompt = une session.** Évite le mélange de contexte
  entre modules et permet de tout tester avant d'avancer.
- **Référence explicite aux briques déjà construites** (`Auditor`,
  `SoftDeletable`, `HasUuid`, `PermissionSeeder`) : un nouveau module doit
  les *réutiliser*, jamais les réinventer.
- **"STOP si ambigu"** reprend telle quelle la règle de CLAUDE.md — c'est la
  seule façon d'éviter que deux devs (ou deux sessions IA) inventent chacun
  une règle métier différente pour la même zone grise du CDC.
- **Ordre de lecture imposé** (DECISIONS.md avant le CDC) : reprend
  l'ordre défini dans docs/CDC_REFERENCE.md — les décisions déjà validées
  priment sur une version antérieure du CDC.

## Ordre suggéré des modules (Phase 1, d'après docs/ROADMAP.md)

1. Patient (anti-doublon inclus)
2. Episode de soins / Réception / Orientation
3. Prestations facturables
4. Facture
5. Caisse unique / Paiements / Créances / Reçus
6. Clôture caisse / Rapports

Chaque module de cette liste dépend généralement du précédent — respecter
l'ordre évite les FK vers des tables qui n'existent pas encore.
