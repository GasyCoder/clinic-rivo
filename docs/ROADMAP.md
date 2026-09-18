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
- [x] shadcn-vue comme design system par défaut (ADR-099) ; DashWind conservé en reliquat le temps des migrations
- [x] Marque de l'application unifiée dans la navigation : pastille d'initiales dérivées de `rivo.brand` et enseigne en majuscules, écrites une seule fois pour le bandeau latéral et la barre du haut
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
- [x] ~~Branche Réception vers la Vente comptoir Pharmacie sans panier médicament dupliqué~~ — remplacé (ADR-104)
- [x] Panier d'arrivée à deux rayons : désignations/consultations et Pharmacie, une seule sélection (ADR-104)
- [x] Estimation chiffrant les deux rayons avec leurs sous-totaux séparés, sans rien créer
- [x] Passage « médicaments seuls » : aucune file clinique, `PENDING_SETTLEMENT` et bascule directe à la Caisse avec son ticket
- [x] Vente comptoir anonyme retirée : toute vente de médicament passe par la Réception sur un dossier patient ; `pharmacy.counter_sales.create` déplacée de PHARMACY vers RECEPTION
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
- [x] Sorties & règlements : file des passages en attente de règlement, contrôle du compte (§33.2) et sortie administrative payé comptant / dette validée / évadé (ADR-090)
- [x] Créance immuable créée par une sortie non soldée, jamais effacée par une évasion
- [x] Cartes compteur sur « Sorties & règlements » : passages à régler, sorties prononcées, sorties avec dette et reste à payer — ce dernier réservé à `billing.view`
- [ ] Créances : suivi et règlement ultérieur d'une créance (aucune règle CDC — hors périmètre ADR-090)
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
- [x] Fiche de soins corrigeable après le transfert vers Médecine, par tout compte Soins autorisé et tracée à l'audit ; le transfert lui-même reste unique (ADR-092)
- [x] Alcool déclaré à côté du Tabac, à trois états (non renseigné / non / oui)
- [x] Constantes corrigeables par le médecin depuis la consultation, périmètre borné aux constantes et écrasement tracé à l'audit (ADR-093)
- [x] Alertes de constantes hors bornes présentées comme des alertes actionnables en Médecine, et confirmation explicite avant de clôturer sur une constante critique
- [x] Brouillon serveur étendu aux demandes de « Conduite à tenir » (chirurgie, hospitalisation, transfert), jusque-là perdues à l'actualisation (ADR-073)
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
- [x] Examens paracliniques (analyses, ECG, échographie) facturés dès la demande du médecin, comme à la Réception (ADR-105)
- [x] Un examen déjà demandé à la Réception n'est **jamais refacturé** : la demande du médecin rattache la prestation de l'arrivée au lieu d'en créer une seconde (ADR-109)
- [x] Le besoin de l'arrivée entre de lui-même dans la demande paraclinique, retirable, avec la mention « déjà porté au compte du patient » (ADR-109)
- [x] ECG et Échographie séparés en deux onglets, sur une famille réglée au catalogue (`imaging_modality`) — jamais déduite d'un code (ADR-106)
- [x] Onglet « Non classés » visible uniquement s'il contient un examen : un examen sans famille n'est jamais rangé au hasard
- [x] Transmission d'une demande d'examen confirmée comme une signature : examens nommés un par un et responsabilité nominative (ADR-106)
- [x] Validation d'une ordonnance confirmée comme une signature : chaque ligne relue avec sa posologie composée, mention « Hors référentiel » pour une ligne manuelle (ADR-106)
- [x] Bandeau « Orientation actuelle » retiré de la Prescription : redondant depuis l'ADR-089, et son bouton menait à un écran où la carte n'existe plus
- [x] Pied « Précédent / Suivant » retiré de « Décision & clôture » : les onglets 1 · 2 · 3 sont la seule navigation, et aucun n'est condamné par l'état du dossier (ADR-106)
- [x] Corriger et retirer un diagnostic depuis « Décision & clôture » : les endpoints existaient depuis l'ADR-081, aucun écran ne les appelait plus (ADR-106)
- [x] Obstacle de clôture menant à sa sous-étape (`closure_section`) : « déjà sur place » ne disait pas où agir sur un écran à trois sections
- [x] Bandeau des résultats attendus rendu neutre : l'ambre le faisait lire comme un verrou alors qu'un résultat manquant n'a jamais bloqué la clôture (ADR-105, ADR-106)
- [x] Demande transmise ne retenant plus la clôture : l'étape se résout à l'envoi, et le fait clinique prime sur l'état de l'écran (ADR-105)
- [x] Retrait d'une demande annulant ce qu'elle avait porté au compte du patient, sans toucher un montant déjà facturé
- [x] Règle « facturer sans jamais bloquer l'acte » écrite une seule fois (`ClinicalActBiller`) au lieu d'une copie par appelant
- [x] Décision paraclinique explicite en tête de l'étape Paraclinique : « Non » la déclare non nécessaire et mène au Diagnostic, « Oui » ouvre la sélection (ADR-079)
- [x] Diagnostic conclu dans l'Examen clinique quand il peut l'être ; « Pas maintenant » diffère sans rien bloquer (ADR-080)
- [x] Étape Diagnostic retirée de l'assistant (six étapes) : correction et annulation dans l'examen, historique complet — annulés compris — dans « Contexte clinique » (ADR-081)
- [x] Clôture vérifiant directement l'existence d'un diagnostic actif, au lieu de l'état d'un écran
- [x] Diagnostic final facultatif pour un passage venu seulement pour un examen (ECG, écho, analyse) : la conclusion de l'examen en tient lieu, et le résultat n'est souvent pas revenu à la clôture (ADR-094)
- [x] « Le diagnostic peut-il être posé maintenant ? » posée à Décision & clôture, seule étape que tout patient atteint ; un report est nommé comme tel dans les blocages au lieu de passer pour un oubli (ADR-095)
- [x] « Oui » sans diagnostic ouvre la saisie au lieu d'échouer : le refus serveur renvoyait à un champ que « Pas maintenant » gardait replié (ADR-095)
- [x] Saisie de diagnostic sans distinction hypothèse / final ; les hypothèses déjà enregistrées gardent leur type et restent signalées (ADR-082)
- [x] Voie d'administration sur les lignes d'ordonnance, facultative et jamais rétro-remplie (ADR-083)
- [x] Posologie composée avec ses unités à la saisie ; plus de « Dose 500 / Fréquence 3 » sans contexte
- [x] Dose facultative pour un produit qui ne se dose pas (compresses, gants) : la forme du référentiel décide, jamais le libellé (ADR-110)
- [x] Quantité totale déduite de la fréquence et de la durée, base du calcul écrite sous le champ, jamais imposée sur une quantité déjà corrigée (ADR-110)
- [x] Aucune quantité suggérée quand la posologie n'en implique aucune (« si besoin », fréquence libre, durée absente)
- [x] Navigation précédente pointant vers la dernière étape réellement pertinente, jamais vers une étape « Non nécessaire »
- [x] Demandes d'analyses et d'imagerie annulables (`cancelled_at`), jamais supprimées ; une demande avec résultat n'est jamais retirée
- [x] Conduite à tenir portée par `consultation_orientations` (SELECTED / SUBMITTED / CANCELLED), décidée dès que le médecin en sait assez (ADR-084)
- [x] Parcours à six étapes terminé par une vraie Clôture : vérifier, signaler ce qui manque, valider — jamais redemander la décision
- [x] Formulaire de la destination ouvert immédiatement après le choix, prérempli du dossier : plus aucune double saisie
- [x] Demandes d'hospitalisation et de référence/transfert avec leur table, leur statut et leur document imprimable
- [ ] Module Hospitalisation (admission, lit, séjour, sortie du service) — règles non définies au CDC, demande seule implémentée
- [x] Changement d'orientation traçable : annulation propre tant que la destination n'a pas pris la demande, refus explicite ensuite
- [x] Clôture seule responsable de terminer l'orientation Médecine et de porter la sortie sur l'épisode
- [x] Permission propre à l'espace « Demandes d'examens » (`paraclinical_requests.view`, ADR-100) : l'écran s'ouvrait uniquement avec le droit sur les analyses, refusant un compte qui n'avait que l'imagerie
- [x] Compte rendu d'imagerie saisi depuis « Demandes d'examens » (éditeur riche) et imprimable avec l'en-tête du site (ADR-070)
- [x] Feuilles de compte rendu de la clinique (écho abdomino-pelvienne, écho obstétricale 1er trimestre) insérables dans le compte rendu, choisies par le médecin et jamais déduites du nom de l'examen (ADR-108)
- [ ] Feuille ECG — aucun modèle transmis, rien n'est inventé (ADR-108)
- [x] File Maternité passée à shadcn (ADR-099) : cartes, pastilles d'état et pagination par les primitives partagées ; la prise en charge devient un POST au lieu d'un bouton imbriqué dans un lien-bouton
- [x] Cartes compteur partagées sur les files cliniques (Médecine, Soins, Laboratoire, Demandes d'examens) : la carte est le filtre, et le compte vient du serveur — jamais de la page affichée
- [x] Réouverture tracée d'une consultation clôturée, tant que la Réception n'a pas clos le passage (ADR-096, construit le mécanisme annoncé par l'ADR-076)
- [x] Registre des décès (`/deces`) : un décès prononcé ne réapparaissait nulle part, la file Médecine ne montrant que les prises en charge en cours (ADR-107)
- [x] Acte de constatation de décès : distinct de la sortie qui prononce le décès, un seul par passage, jamais avant lui, imprimable (ADR-107)
- [x] Sortie pour décès ne proposant plus état du patient, traitement de sortie, conseils ni contrôle : des instructions sans destinataire, refusées aussi côté serveur (ADR-107)
- [x] État du patient d'un décès posé par le serveur (« Décédé ») : aucune des cinq options ne convenait, et l'absence aurait été lue comme un oubli
- [x] Sortie médicale confirmée comme une signature, dans ses propres termes pour un décès (ADR-106, ADR-107)
- [ ] Volet état civil de l'acte (numéro, déclarant, officier) — absent du CDC, non inventé (ADR-107)
- [x] Transmission d'une demande de conduite à tenir confirmée comme une signature : destination nommée, contenu relu, responsabilité nominative (ADR-106)
- [x] Derniers contrôles natifs des formulaires cliniques passés à `Select` et `Textarea` (ADR-099) : deux listes et dix-sept zones de texte habillées à la main, aux classes déjà divergentes
- [x] Compte rendu d'imagerie converti en texte dans le préremplissage d'une demande : le HTML de l'éditeur partait tel quel au service d'accueil et à l'impression (ADR-107)
- [x] Libellés des demandes portés par `FormField` (libellé, astérisque, précision, erreur), et résumé clinique à dix rangées au lieu de quatre (ADR-099)
- [x] Parcours Médecine entièrement migré à shadcn (ADR-099) : les 6 étapes de l'assistant, le stepper et les 22 composants cliniques quittent la police d'icônes et les nuances codées en dur, sans changer aucun contrat de props ni aucune règle métier
- [x] Barre d'écran des documents imprimés migrée ; le corps du document garde ses couleurs — il décrit du papier, pas une interface

---

# Phase 3 — Laboratoire

- [x] Catalogue analyses structuré, références par profil et import/export Excel
- [x] File de paillasse filtrable (à analyser / rendues / toutes) avec cartes compteur ; une demande annulée par le médecin (ADR-079) quitte la file au lieu d'y rester à faire
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
- [x] File « Consommables Soins » en shadcn (ADR-099) : compteurs partagés, fenêtre de sortie de stock par la primitive `Dialog`, tokens sémantiques
- [x] Ce que le patient doit pour ce matériel affiché sur la file Pharmacie — montant, facture et statut, en lecture seule (ADR-103)
- [x] Ligne jamais facturée comptée et nommée (`unbilled_lines`) : l'échec de facturation, volontairement non bloquant, n'est plus silencieux (ADR-103)
- [x] Alertes automatiques de seuil minimal et rupture
- [x] Alertes et visibilité des lots proches de la péremption
- [x] Dossier fournisseur façon Drive : catalogues Excel/PDF multiples, historisés, un seul actif à la fois
- [x] Catalogue fournisseur importé (Excel), distinct du catalogue réellement stocké par la clinique
- [x] Liaison catalogue fournisseur ↔ catalogue clinique avec prix d'achat versionné, jamais écrasé, plusieurs fournisseurs simultanés par médicament
- [x] Commandes fournisseur (brouillon, passée, annulée) avec prix figé à la commande
- [x] Réceptions distinctes de la commande, partielles, alimentant les entrées de stock existantes sans jamais recalculer une réception antérieure
- [x] Factures fournisseur liées à la commande/réception, sans impact sur le stock
- [x] Menu latéral comme seule navigation Pharmacie : une vraie page par tâche, chaque entrée filtrée par permission (ADR-098)
- [x] Espace Fournisseurs en dossiers (dossier, catalogues, commandes, factures, prix), consultation au site
- [x] Aperçu d'un catalogue Excel avant import, sans écriture, avec erreurs par ligne
- [x] Ajout au catalogue clinique depuis une ligne fournisseur, rattaché au prix du fournisseur en une transaction
- [x] Fournisseurs et approvisionnement accordés à aucun rôle par défaut, octroi nominatif par le Super Admin
- [x] Calcul unique de la disponibilité d'un lot, partagé par le stock, la Médecine et les alertes
- [x] Tâches Pharmacie sur la Vue d'ensemble, plus de second accueil
- [x] Entrée de stock filtrée par fournisseur, prix fournisseur actuel pré-rempli
- [x] Simulation locale de l'approvisionnement (prix, catalogues, commandes, réceptions, facture)
- [x] Page unique « Médicaments & stock » et page « Achats » à onglets (Commandes, À réceptionner, Réceptions, Factures)
- [x] Entrée de stock par livraison : liste relue et modifiable, enregistrement atomique en une fois
- [x] Inventaire par feuille de comptage imprimable, ajustements seulement sur les écarts
- [x] Étiquettes QR imprimables par sélection de médicaments
- [x] Colonne Actions sur les tableaux Pharmacie et icônes dans le menu
- [x] Modifier un médicament (nouveau prix historisé), désactiver/réactiver avec motif, clinique et portail
- [x] Écran « Stock médicaments » du portail passé à shadcn (ADR-099) : recherche, filtre d'état, cases de sélection, import Excel et pastilles d'état par les primitives partagées, plus de contrôles natifs habillés à la main
- [x] Familles de médicaments passées à shadcn : fenêtre d'archivage par le `Dialog` partagé, champs par `Input`/`Textarea`
- [x] Fiche médicament passée à shadcn (ADR-099) : `MedicineForm` et `MedicineStatusPanel`, partagés par la clinique et le portail, quittent les champs habillés à la main et la fenêtre modale maison
- [x] Familles : renommer, archiver (refusé si médicament actif), restaurer
- [x] Modifier une commande brouillon, une facture fournisseur et la date/remarque d'un catalogue, clinique et portail
- [x] Comparateur de prix fournisseurs par médicament (prix, moins-disant, stock restant), point d'entrée de la commande d'achat
- [x] Commande d'achat composée sur plusieurs fournisseurs : un brouillon par fournisseur, jamais une commande mixte
- [x] Formulaire de commande limité aux produits réellement fournis par le fournisseur
- [x] Facture fournisseur enregistrable comme document global (numéro, date, montant, pièce jointe) ; le détail par produit devient facultatif
- [x] Fournisseurs, catalogues et factures archivés visibles dans la Corbeille, restaurables
- [x] Suppression définitive depuis la seule Corbeille, refusée dès que l'élément a servi (`trash.force_delete`)
- [x] Prix facultatif dans un catalogue fournisseur : un fichier sans tarif s'importe, le prix est exigé au rattachement à un médicament
- [x] Erreur d'import nommant la ligne, la colonne, la valeur lue et la raison ; montant formaté (« 4 500,50 Ar ») accepté
- [x] Téléchargement d'un fichier de catalogue depuis le portail, relayé par l'API du site
- [x] Actions Ouvrir / Modifier / Corbeille / Restaurer sur la liste des fournisseurs du portail
- [x] Provenance d'un produit fournisseur affichée (catalogue d'origine) et distinction proposé / réellement réceptionné
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
- [x] Canevas de documents administratifs (contrat/congé/attestation/certificat/lettre/décision), rédaction libre façon traitement de texte avec import DOCX/PDF, composés par le Super Admin et poussés par site
- [x] Génération de documents par le RH : page 1 (infos RH, pré-remplie) + canevas verbatim, aperçu serveur, snapshot figé
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
- [x] Espace RH : menu latéral en groupe, panneau « à traiter » sur la Vue d'ensemble, accueil et liste des employés refondus
- [x] Présences et congés : chevauchements refusés pour un même employé

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
- [x] Vue consolidée : tableau de bord central alimenté par le rapport de chaque site (ADR-102) — activité, finance, files, pharmacie, personnel, avec courbe, histogramme et diagrammes
- [x] Courbe lisible sur 30 et 90 jours : un libellé de date sur N, compté depuis la fin, et format jj/mm au-delà de la semaine
- [x] Répartitions (encaissements par mode, patients, comptes patients) en colonne à droite de la courbe
- [x] Une donnée absente affichée « — » avec son motif (site injoignable, permission manquante), jamais zéro
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
- [x] Catalogue des permissions administrable depuis le portail (ADR-101) : créer un droit, reformuler son libellé, retirer un nom que rien ne vérifie — le nom lui-même ne change jamais
- [x] Usage réel de chaque permission calculé depuis le code (`PermissionUsageScanner`) : « vérifiée par l'application » ou « pas encore vérifiée », jamais une liste tenue à la main
- [x] Panneaux redimensionnables à la barre (clavier, double-clic, largeur conservée par poste) sur le socle des rôles et les exceptions par compte
- [x] Choix du rôle et du compte en fenêtre cherchable, colonne de gauche rendue aux catégories et filtre propre au rail (ADR-101)
- [x] Écran « Rôles & permissions » à quatre sections annoncées par portée (socle du rôle / exception d'un compte / rôles du site / catalogue), avec compteurs et phrase de portée avant le clic
- [x] Référentiel des rôles administrable depuis le portail (ADR-100) : créer, renommer, archiver avec motif (refusé si des comptes le portent) et restaurer, par site via l'API — le code d'un rôle reste son identité et ne change jamais
- [x] Écrans « Utilisateurs » et « Rôles & permissions » séparés (ADR-100) : les comptes d'un côté, le socle des rôles et les exceptions individuelles de l'autre, sans changer la résolution DENY > ALLOW > socle
- [x] Socle des rôles refondu (shadcn, ADR-099) : rail des rôles et des catégories sans pagination, recherche sur tout le catalogue, écart « accordées / retirées » relisible avant envoi, barre d'enregistrement collante et garde-fou sur le brouillon
- [x] Fournisseurs pharmacie et catalogues gérés depuis le portail par API du site (ADR-098)
- [x] Import Excel des fournisseurs avec aperçu ligne par ligne puis écriture tout ou rien, export Excel par site ou tous sites
- [x] Correction, archivage avec motif (refusé si commande en cours) et restauration d'un fournisseur depuis le portail
- [x] Dossier fournisseur au portail identique à la clinique (catalogues, commandes, factures, produits et prix)
- [x] Commandes (créer, envoyer, annuler) et factures (enregistrer avec document, archiver, restaurer) depuis le portail ; réception réservée au site
- [x] Espace Fournisseurs pharmacie entièrement en shadcn (ADR-099) : index en dossiers avec vue liste, création et import en fenêtres, et les quatorze pages de détail migrées avec leurs composants partagés
- [x] Portail Super Administration entièrement en shadcn (ADR-099) : les 36 écrans et les composants partagés (`PageHeader`, `IconInput`, `Card`, `Breadcrumb`, `EmptyState`, `Explorer*`, `FolderCard`, `FormSection`, `ValidationErrorSummary`) quittent la police d'icônes et la palette DashWind, sans changer aucun contrat de props
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
