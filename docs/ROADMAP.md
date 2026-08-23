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
- [x] Rôles autonomes Administration/RH et Logistique
- [x] Profils professionnels NURSE, SUPPORT et MAINTENANCE sans droits implicites
- [x] Permissions supplémentaires affectées individuellement par compte
- [x] Séparation stricte des comptes Super Admin et des comptes opérationnels
- [ ] Soft Delete
- [ ] Audit
- [ ] UUID
- [ ] Base API `/api/v1`
- [ ] Queue / Jobs

---

# Phase 1 — Réception / Patients / Caisse

- [ ] Patient
- [x] Typologie patient Standard / Mutuelle / Personnel
- [x] Profil administratif enrichi (situation maritale, enfants, profession, adresse référencée)
- [x] Couverture mutuelle et cinq justificatifs privés maximum
- [x] Lien patient-personnel vers un véritable dossier Employé
- [ ] Identification patient
- [ ] Recherche patient
- [ ] Détection des doublons
- [ ] Episode de soins
- [x] Numéro patient annuel `SITE-YY-NNNN`
- [x] Numéro de passage ordinal `PATIENT-NN`
- [ ] Réception
- [x] Réception visiteur (entrées, sorties, motifs, patient facultatif et pièces jointes privées)
- [ ] Orientation
- [x] Parcours Réception piloté par la désignation (Médecine directe / Soins puis Médecine / Soins seuls)
- [x] Besoin inconnu sans désignation ni montant fictif
- [ ] Rendez-vous
- [ ] Prestations facturables
- [x] Référentiel des prestations et produits facturables
- [x] Tarifs historisés propres à chaque site
- [x] Barèmes historisés séparés Sans mutuelle / Mutuelle
- [x] Résolution du barème par type patient et snapshot sur le passage
- [ ] Conventions tarifaires spécifiques par organisme mutualiste (si validées)
- [ ] Répartition contractuelle part mutuelle / part patient
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
- [x] Fiche de soins NURSE par passage (constantes, IMC, actes et transmission)
- [x] Référentiel initial des actes infirmiers fourni par le client, sans tarifs inventés
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
- [x] Socle Employé et lien sécurisé avec le dossier patient
- [ ] Règles d'éligibilité du personnel et registre immuable du crédit bloc
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
- [x] Navigation Super Admin vers les désignations et deux grilles par site
- [ ] Commandes distantes de tarifs via API sécurisée des sites
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
