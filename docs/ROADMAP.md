# RIVO — Development Roadmap

CDC :

```text
https://github.com/GasyCoder/cdc-clinic-george
```

---

# Phase 0 — Fondation

- [x] Laravel 13 installé
- [x] Documentation projet
- [x] Repository GitHub application
- [x] Configuration Git
- [x] Vue.js
- [x] Inertia.js
- [x] Configuration frontend
- [x] Configuration base locale
- [ ] DashWind
- [x] Authentification locale avec comptes actifs et rôle obligatoire
- [x] RBAC dynamique
- [x] Permissions et exceptions individuelles auditées
- [x] Rôles autonomes Administration/RH, Logistique et Gardien
- [x] Séparation stricte des comptes Super Admin et des comptes opérationnels
- [ ] Soft Delete
- [ ] Audit
- [ ] UUID
- [ ] Base API `/api/v1`
- [ ] Queue / Jobs

---

# Phase 1 — Réception / Patients / Caisse

- [ ] Patient
- [ ] Identification patient
- [ ] Recherche patient
- [ ] Détection des doublons
- [ ] Episode de soins
- [ ] Réception
- [x] Réception visiteur (entrées, sorties, motifs, patient facultatif et pièces jointes privées)
- [ ] Orientation
- [ ] Rendez-vous
- [ ] Prestations facturables
- [x] Référentiel des prestations et produits facturables
- [x] Tarifs historisés propres à chaque site
- [x] Résolution backend du tarif sans saisie libre par Réception
- [x] Sélection des prestations et choix payer maintenant / plus tard à l’arrivée
- [x] Facture imprimable sans faux reçu pour un règlement ultérieur
- [ ] Factures
- [ ] Facture lignes
- [ ] Caisse unique
- [ ] Paiements
- [ ] Paiements partiels
- [ ] Créances
- [ ] Remboursements autorisés
- [ ] Remises autorisées
- [ ] Reçus
- [ ] Ouverture caisse
- [ ] Clôture caisse
- [ ] Rapport caisse

---

# Phase 2 — Médecine / Soins

- [ ] Dossier médical
- [ ] Consultation
- [ ] Diagnostic
- [ ] Prescription
- [ ] Constantes
- [ ] Soins
- [ ] Ordres de soins
- [ ] Demande laboratoire
- [ ] Demande chirurgie
- [ ] Hospitalisation
- [ ] Transfert médical
- [ ] Sortie médicale

---

# Phase 3 — Laboratoire

- [ ] Catalogue analyses
- [ ] Demande analyse
- [ ] Analyse interne
- [ ] Analyse externe
- [ ] Vérification statut paiement
- [ ] Prélèvement
- [ ] Echantillon
- [ ] Analyse
- [ ] Saisie résultat
- [ ] Validation résultat
- [ ] Correction contrôlée
- [ ] Résultat critique
- [ ] Impression
- [ ] Export
- [ ] Rapport

Règle :

```text
AUCUN ENCAISSEMENT DANS LE LABORATOIRE
```

---

# Phase 4 — Pharmacie / Stocks

- [ ] Médicaments
- [ ] Produits
- [ ] DCI
- [ ] Dosages
- [ ] Lots
- [ ] Péremptions
- [ ] Stocks
- [ ] Entrées
- [ ] Sorties
- [ ] Inventaires
- [ ] Ajustements
- [ ] Prescription reçue
- [ ] Préparation délivrance
- [ ] Vérification statut financier si nécessaire
- [ ] Délivrance
- [ ] Déstockage
- [ ] Retours
- [ ] Alertes stock
- [ ] Alertes péremption
- [ ] Transfert stock
- [ ] Rapports

Règle :

```text
AUCUNE CAISSE DANS LA PHARMACIE
AUCUN PAIEMENT DANS LA PHARMACIE
```

---

# Phase 5 — Chirurgie

- [ ] Demande chirurgie
- [ ] Programmation
- [ ] Préopératoire
- [ ] Validation préopératoire
- [ ] Intervention
- [ ] Anesthésie
- [ ] Equipe bloc
- [ ] Consommables
- [ ] Compte rendu
- [ ] Complications
- [ ] Postopératoire
- [ ] Sortie
- [ ] Prestations facturables

Règle :

```text
AUCUN ENCAISSEMENT DANS LA CHIRURGIE
```

---

# Phase 6 — Administration

- [x] Utilisateurs locaux
- [x] Attribution des rôles
- [x] Activation / désactivation des comptes
- [x] Exceptions de permissions individuelles
- [ ] Employés
- [ ] RH
- [ ] Contrats
- [ ] Présences
- [ ] Congés
- [ ] Absences
- [ ] Planning
- [ ] Logistique
- [ ] Stock administratif
- [ ] Catalogue des équipements
- [ ] Affectations et localisations des équipements
- [ ] Maintenance et mise hors service des équipements
- [x] Visiteurs (saisie opérationnelle à la Réception ; rapports administratifs à venir)
- [ ] Gardiennage
- [ ] Rapports RH

---

# Phase 7 — API inter-sites

- [ ] `/api/v1`
- [ ] Authentification API
- [ ] Service accounts
- [ ] Permissions API
- [ ] UUID
- [ ] Request UUID
- [ ] Idempotency
- [ ] Queue
- [ ] Retry
- [ ] Backoff
- [ ] Timeout
- [ ] Journal API
- [ ] Recherche patient distante
- [ ] Transfert patient
- [ ] Réception transfert
- [ ] Accusé réception
- [ ] Transfert stock
- [ ] Synchronisation référentiel et tarifs par UUID
- [ ] Idempotence des commandes de catalogue multi-site
- [ ] Autres échanges métier

---

# Phase 8 — Super Administration

Domaine :

```text
admin.rivo.mg
```

- [ ] Auth Super Admin
- [ ] API Mampikony
- [ ] API Ambondromamy
- [ ] API Boriziny
- [ ] Vue Mampikony
- [ ] Vue Ambondromamy
- [ ] Vue Boriziny
- [ ] Vue consolidée
- [ ] Patients
- [ ] Activités
- [ ] Caisse
- [ ] Finance
- [ ] Laboratoire
- [ ] Pharmacie
- [ ] Stocks
- [ ] Référentiels et tarifs propres à chaque site
- [ ] Action « appliquer aux deux sites »
- [ ] Résultat et reprise séparés en cas d’échec partiel
- [ ] Chirurgie
- [ ] Utilisateurs
- [ ] Rôles
- [ ] Permissions
- [ ] Audit
- [ ] Rapports
- [ ] Gestion indisponibilité d'une API

---

# Phase 9 — Qualité et Production

- [ ] Tests unitaires
- [ ] Tests fonctionnels
- [ ] Tests permissions
- [ ] Tests sécurité
- [ ] Tests API
- [ ] Tests idempotence
- [ ] Tests Soft Delete
- [ ] Tests Audit
- [ ] Tests de charge
- [ ] Sauvegardes
- [ ] Tests restauration
- [ ] Monitoring
- [ ] Logs
- [ ] Production Mampikony
- [ ] Production Ambondromamy
- [ ] Production Boriziny
- [ ] Production Admin
- [ ] Recette client

---

# Règle

Une phase métier ne doit pas être considérée terminée uniquement parce que son interface fonctionne.

Pour chaque fonctionnalité vérifier :

```text
Business rules
Validation
Authorization
Permissions
Audit
Soft Delete
Tests
API impact
Failure handling
```
