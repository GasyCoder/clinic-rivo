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
- [x] Base API `/api/v1` pour la supervision Stock/Adresses/Catalogue Super Admin
- [ ] Queue / Jobs

---

# Phase 1 — Réception / Patients / Caisse

- [ ] Patient
- [x] Identité Patient permanente séparée du mode financier du passage
- [x] Mode financier Episode `SELF` / `MUTUAL` / `STAFF` nullable
- [x] Couvertures Mutuelle et Personnel propres à chaque Episode avec références existantes
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
- [x] Résolution du barème par contexte financier Episode et snapshot sur le passage
- [x] Estimation read-only au tarif Standard avant Patient/Episode
- [x] Expérience progressive Besoin → Estimation → Patient → Episode → mode financier → confirmation → routage
- [x] Prévisualisation financière SELF/MUTUAL/STAFF sans débit anticipé du crédit Bloc
- [x] Branche Réception vers la Vente comptoir Pharmacie sans panier médicament dupliqué
- [ ] Conventions tarifaires spécifiques par organisme mutualiste (si validées)
- [x] Taux de couverture par organisme et répartition figée part mutuelle / part patient
- [x] Import/export Excel des tarifs Standard/Mutuelle et des organismes mutualistes
- [x] Résolution backend du tarif sans saisie libre par Réception
- [x] Politiques Personnel explicites et snapshots brut / couverture / crédit Bloc / patient
- [x] Registre immuable du crédit Bloc Employee avec allocation manuelle, consommation idempotente et réversion
- [x] Sélection des prestations et choix payer maintenant / plus tard à l’arrivée
- [x] Facture imprimable sans faux reçu pour un règlement ultérieur
- [x] Facturation automatique et idempotente d'un acte Soins facturable, sans blocage clinique en cas d'erreur financière
- [x] Rattachement d'un nouvel acte à une facture du même passage non encore encaissée (DRAFT/VALIDATED, paid_amount = 0)
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
- [x] Consultation par orientation Médecine
- [x] Diagnostic historisé par consultation
- [x] Prescription médicale et annulation contrôlée
- [x] Impression de l'ordonnance active avec numéro patient et numéro de passage
- [ ] Constantes
- [ ] Soins
- [x] Fiche de soins NURSE par passage (constantes, IMC, actes et transmission)
- [x] Référentiel initial des actes infirmiers fourni par le client, sans tarifs inventés
- [x] Projection partagée des constantes et alertes (CareRecordReadModel) entre Soins, Médecine et Chirurgie/Anesthésie
- [x] Antécédents patient exposés via un point d'entrée générique, consultables et ajoutables depuis Médecine
- [x] Antécédents distingués personnels / familiaux, traitements actuels déclarés en consultation, lieu de naissance au dossier patient
- [x] Clôture administrative automatique (PENDING_SETTLEMENT) d'un parcours Soins seul réellement terminé, sans sortie médicale fictive
- [x] Page transversale « Détail du passage » en lecture seule, sécurisée section par section côté serveur
- [x] Ordres de soins Médecine → Soins (CareOrder), retour Médecine optionnel sans nouvel Episode
- [x] Consommables déclarés aux Soins, notifiés à la Pharmacie, facturés séparément et sortis du stock sans attendre le règlement
- [x] Matériel habituel configurable par acte de soins, pré-rempli comme suggestion et toujours confirmé par le soignant
- [x] Saisie en cours de la fiche de soins conservée par auteur et restaurée après actualisation
- [ ] Demande laboratoire
- [ ] Demande chirurgie
- [ ] Hospitalisation
- [ ] Transfert médical
- [x] Sortie médicale découplée de la sortie administrative
- [x] Interrogatoire et Examen clinique en deux étapes distinctes du parcours, chacune son enregistrement serveur
- [x] Intention d'orientation préparée en Prescription (sortie, hospitalisation, Maternité, Chirurgie, Pédiatrie, transfert), sans créer le workflow spécialisé
- [x] Statut réel par étape de consultation (`consultation_steps`) : validée, en cours, non nécessaire ou non commencée — jamais déduit d'une donnée présente ni d'un écran ouvert
- [x] Étapes optionnelles explicitement « passées » avec auteur, date et motif facultatif ; étapes sans objet pour le patient ni exigées ni verrouillées
- [x] Statut de consultation `DRAFT/IN_PROGRESS/COMPLETED/CANCELLED`, clôture refusée tant qu'une étape pertinente n'est pas résolue, lecture seule ensuite
- [x] Examen clinique semi-structuré : état général, conscience, neuf appareils à trois états et notes complémentaires facultatives (ADR-077, amende ADR-074)
- [x] `NOT_EXAMINED` par défaut et jamais stocké : une absence de saisie n'est jamais un examen normal ; `ABNORMAL` exige ses constatations
- [x] Aucune constante vitale ressaisie en Médecine : la fiche Soins reste la source unique, en lecture seule
- [x] Interrogatoire semi-structuré : motif principal exploitable séparé du récit, début/durée, évolution et notes complémentaires (ADR-078)
- [x] Traitements habituels du dossier patient (`patient_treatments`) affichés sans ressaisie, avec question de changement déclaré
- [x] Allergies, antécédents et traitements signalés pendant l'entretien conservés sur la consultation ; promotion au dossier permanent explicite et soumise à `patients.medical_history.manage`
- [x] Décision paraclinique explicite en tête de l'étape Paraclinique : « Non » la déclare non nécessaire et mène au Diagnostic, « Oui » ouvre la sélection (ADR-079)
- [x] Diagnostic conclu dans l'Examen clinique quand il peut l'être ; « Pas maintenant » diffère sans rien bloquer (ADR-080)
- [x] Étape Diagnostic retirée de l'assistant (six étapes) : correction et annulation dans l'examen, historique complet — annulés compris — dans « Contexte clinique » (ADR-081)
- [x] Clôture vérifiant directement l'existence d'un diagnostic actif, au lieu de l'état d'un écran
- [x] Saisie de diagnostic sans distinction hypothèse / final ; les hypothèses déjà enregistrées gardent leur type et restent signalées (ADR-082)
- [x] Voie d'administration sur les lignes d'ordonnance, facultative et jamais rétro-remplie (ADR-083)
- [x] Posologie composée avec ses unités à la saisie ; plus de « Dose 500 / Fréquence 3 » sans contexte
- [x] Navigation précédente pointant vers la dernière étape réellement pertinente, jamais vers une étape « Non nécessaire »
- [x] Demandes d'analyses et d'imagerie annulables (`cancelled_at`), jamais supprimées ; une demande avec résultat n'est jamais retirée
- [x] Conduite à tenir portée par `consultation_orientations` (SELECTED / SUBMITTED / CANCELLED), décidée dès que le médecin en sait assez (ADR-084)
- [x] Parcours à six étapes terminé par une vraie Clôture : vérifier, signaler ce qui manque, valider — jamais redemander la décision
- [x] Formulaire de la destination ouvert immédiatement après le choix, prérempli du dossier : plus aucune double saisie
- [x] Demandes d'hospitalisation et de référence/transfert avec leur table, leur statut et leur document imprimable
- [ ] Module Hospitalisation (admission, lit, séjour, sortie du service) — règles non définies au CDC, demande seule implémentée
- [x] Changement d'orientation traçable : annulation propre tant que la destination n'a pas pris la demande, refus explicite ensuite
- [x] Clôture seule responsable de terminer l'orientation Médecine et de porter la sortie sur l'épisode

---

# Phase 3 — Laboratoire

- [x] Catalogue analyses structuré, références par profil et import/export Excel
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

- [x] Fondation médicaments spécialisés liés au référentiel
- [x] Produits, catégories, fournisseurs et import de création Excel/CSV
- [x] DCI et formes pharmaceutiques
- [x] Dosages
- [x] Fondation lots locaux
- [x] Exclusion des lots périmés de la disponibilité
- [x] Disponibilité physique moins réservations actives
- [x] Entrées locales auditées par lot et fournisseur
- [x] Sorties immuables par bon de délivrance
- [x] Inventaires par ajustement au comptage physique
- [x] Ajustements péremption, casse/perte et inventaire
- [x] Prescription Médecine reliée au médicament et réservation FEFO
- [x] Ligne d'ordonnance manuelle hors référentiel, sans stock ni prix, en attente de validation
- [x] Préparation délivrance interne et vente directe comptoir
- [x] Vérification du paiement/prise en charge par la Caisse
- [x] Ticket Pharmacie sans référence manuelle, à référence automatique, et liste Caisse séparée avec contrôle dynamique par QR ou saisie
- [x] Délivrance complète ou partielle en FEFO
- [x] Déstockage uniquement lors de la délivrance autorisée
- [ ] Retours
- [x] File Pharmacie des consommables Soins avec sortie de stock FEFO respectant les réservations
- [x] Alertes automatiques de seuil minimal et rupture
- [x] Alertes et visibilité des lots proches de la péremption
- [ ] Transfert stock
- [ ] Rapports

Règle :

```text
AUCUNE CAISSE DANS LA PHARMACIE
AUCUN PAIEMENT DANS LA PHARMACIE
```

---

# Phase 5 — Chirurgie

- [x] Demande chirurgie
- [x] Programmation
- [x] Référentiel contrôlé des interventions avec choix « Autres » documenté
- [x] Espaces Chirurgie et Anesthésie séparés par permission, dossier partagé
- [x] Parcours guidés par étapes pour Chirurgie et Anesthésie
- [x] Consultation pré-anesthésique et examen paraclinique structurés
- [x] Validation anesthésique séparée, auditée et verrouillée côté backend
- [x] Préparation et validation chirurgicales préopératoires distinctes
- [x] Intervention
- [x] Anesthésie
- [x] Equipe bloc
- [x] Consommables
- [x] Compte rendu et verrouillage après validation
- [x] Complications
- [x] Entrée/sortie du bloc et suivi postopératoire structuré
- [x] Sortie
- [ ] Prestations facturables
- [ ] Rapport financier Chirurgie Prévu/Réel/Écart/Dette NP alimenté par factures et paiements

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
- [x] Employés
- [x] Socle Employé et lien sécurisé avec le dossier patient
- [x] Classification explicite des prestations et registre immuable du crédit bloc
- [ ] Période et renouvellement éventuel du crédit Bloc (règle métier non définie)
- [x] RH
- [x] Contrats
- [x] ~~Modèles de contrat privés Word/PDF, variables serveur et versionnement~~ — retiré (ADR-071), remplacé par le canevas ci-dessous
- [x] Canevas de documents administratifs (contrat/congé/attestation/certificat/lettre/décision) composés par le Super Admin et poussés par site
- [x] Génération de documents par le RH depuis un canevas actif, aperçu serveur, snapshot figé
- [x] Présences
- [x] Congés
- [x] Types de congé configurables, date serveur, durée et soldes automatisés
- [ ] Absences
- [x] Planning
- [ ] Logistique
- [ ] Stock administratif
- [ ] Catalogue des équipements
- [ ] Affectations et localisations des équipements
- [ ] Maintenance et mise hors service des équipements
- [x] Visiteurs (saisie opérationnelle à la Réception ; rapports administratifs à venir)
- [ ] Gardiennage
- [x] Rapports RH

---

# Phase 7 — API inter-sites

- [x] `/api/v1` pour les endpoints Stock/Adresses/Catalogue du portail central
- [x] Authentification API par jeton distinct par site pour ce périmètre
- [ ] Service accounts
- [ ] Permissions API
- [ ] UUID
- [x] Request UUID sur les endpoints Stock/Adresses
- [x] Idempotency sur les commandes Adresses
- [ ] Queue
- [x] Retry et isolation des pannes pour le client central Stock/Adresses
- [ ] Backoff
- [x] Timeout configurable pour le client central Stock/Adresses
- [x] Banc local distribué Mampikony/Ambondromamy/Boriziny avec une base SQLite isolée par API
- [ ] Journal API
- [ ] Recherche patient distante
- [ ] Transfert patient
- [ ] Réception transfert
- [ ] Accusé réception
- [ ] Transfert stock
- [x] Pilotage du référentiel et des tarifs de chaque site par UUID via API
- [x] Idempotence des commandes distantes de catalogue
- [x] Gestion des mutuelles et partenaires de chaque site par UUID via API
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
- [x] Fiche de supervision des caisses par site avec mouvements, historique, verrouillage réversible et clôture centrale auditée par API
- [ ] Finance
- [ ] Laboratoire
- [ ] Pharmacie
- [x] Supervision consolidée des stocks, lots et péremptions avec import/export Excel audité par site
- [x] Référentiels et tarifs propres à chaque site
- [x] Navigation Super Admin vers les désignations et deux grilles par site
- [x] Référentiel d’adresses par site : CRUD logique et import/export Excel via API
- [x] Commandes distantes de tarifs via API sécurisée des sites
- [x] Référentiel des mutuelles et partenaires par site avec archivage/restauration audités
- [x] Taux de couverture par organisme (100 % par défaut) et import/export Excel via API
- [x] Répartition financière brute / mutuelle / patient historisée sur les factures
- [x] Sélection multiple par site : export ciblé Stock/Adresses et archivage/restauration atomiques des référentiels
- [x] Éditeur de canevas de documents (TipTap) : création, modification versionnée, duplication, activation, archivage/restauration par site
- [ ] Conventions tarifaires spécifiques par organisme mutualiste
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
