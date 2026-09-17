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
- [x] Sélecteur de date et d'heure shadcn (`Shadcn/DateTimePicker`, calendrier `reka-ui` en français, colonnes Heure / Minutes), d'abord sur la programmation du bloc ; même valeur qu'un `datetime-local` (ADR-099)
- [x] `Shadcn/DatePicker` (date seule) et migration de tous les champs date natifs de l'application (59) vers `DatePicker` / `DateTimePicker` ; test garde-fou contre leur retour (ADR-099)
- [x] Sélecteur de date compact (242 × 296 px au lieu de ~390 × 317) : cases de 28 px, ligne Heure : Minutes sous le calendrier, bouton « Maintenant » ; cartes Voie veineuse / Sonde urinaire placées par requêtes de conteneur, plus aucun champ qui déborde ni date tronquée (ADR-099)
- [x] Menu latéral fidèle au rendu serveur : l'ordre personnel et les vues liste/grille ne sont plus lus pendant le rendu, plus aucune ligne portant le libellé d'un module et le lien d'un autre (ADR-115)
- [x] Entrées mères par module (Médecine, Réception, Référentiels) ; un seul enfant actif à la fois ; icônes revues (ADR-115)
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
- [x] Répertoire patients : où chacun a encore besoin d'aller — Médecine, Soins, Pharmacie en combinaisons exactes (« seulement »), comptes-filtres qui suivent la recherche, pastille par service sur chaque ligne (ADR-119)
- [x] Besoin de Pharmacie servi comme information de routage, sans médicament, quantité, montant ni état de règlement
- [x] Répertoire patients passé à shadcn-vue (ADR-099) : compteurs partagés avec les files, colonne « Situation actuelle » à la place de « Catégorie », recherche lancée d'elle-même
- [x] Répertoire patients classé par onglets — Tous / Besoin en cours / En attente de règlement / Aucun passage ouvert — comptes du serveur, combinables avec les autres filtres (ADR-120)
- [x] Répertoire patients : tri A → Z / Z → A, filtre par initiale (A–Z) et export Excel de la liste filtrée (`patients.export`, audité) (ADR-133)
- [x] Patients normaux / VIP : VIP = passages ET montant encaissé sur une fenêtre glissante, seuils propres à chaque site réglés depuis le portail par l'API, catégorie calculée jamais stockée (ADR-133)
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
- [x] Un acte du bloc peut être la raison de la venue : la Réception l'inscrit et le bloc reçoit sa demande à programmer (ADR-159) ; sans tarif configuré, la demande part et la facturation attend (ADR-031)
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
- [x] Sortie refusée tant qu'une prestation n'est portée sur aucune facture (tous types), avec « Facturer ces prestations » dans la fenêtre : plus aucun montant ne se perd à la sortie (ADR-090, amendement du 2026-09-20)
- [x] Sélection multiple sur « Sorties & règlements » : sortie « payé comptant » en lot (comptes soldés), facturation en lot, fiches de sortie groupées (un PDF, une fiche par page) et export Excel de la sélection ; chaque passage jugé séparément, rapport des refus (ADR-090, amendement du 2026-09-20 ter)
- [x] Cartes compteur sur « Sorties & règlements » : passages à régler, sorties prononcées, sorties avec dette et reste à payer — ce dernier réservé à `billing.view`
- [x] Fiche de sortie imprimable après la sortie administrative, distinguant sortie médicale et sortie administrative (le papier les confondait), avec QR pour le contrôle de gardiennage (ADR-116)
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
- [x] Soins réunit Infirmière, Maternité et Anesthésie : une entrée mère dans le menu et une barre d'onglets sur chacune des trois pages, chaque onglet gardant son adresse et sa permission (ADR-134)
- [x] File Maternité en quatre vues exclusives — À prendre / En cours / Orientées vers Médecine / Terminées — comptes du serveur, badges de suivi (médecin, césarienne) sur chaque ligne (ADR-135)
- [x] Fin de prise en charge Maternité à deux issues (terminer, ou terminer et orienter vers Médecine avec message) ; un passage sans service restant passe en attente de règlement, comme après les Soins (ADR-135)
- [x] File Anesthésie en quatre étapes exclusives — À évaluer / Transmis à Chirurgie / Au bloc / Terminés — lues sur les faits existants de la demande chirurgicale, demandes annulées masquées (ADR-135)
- [x] En-tête partagé des trois espaces Soins (`SoinsWorkspaceHeader`), tableaux et états vides en shadcn-vue (ADR-135)
- [x] Liste d'actes Maternité de la clinique rapprochée du catalogue : Utilisation Aspirateur bébé, IEC et Nursie ajoutés, Syana Press / Dépôt Provera déplacé du Planning familial, Doppler et Photothérapie renommés (ADR-136)
- [x] Dossier Maternité adapté à l'acte demandé à la Réception : sections attendues signalées et ouvertes d'office, deux fiches de nouveau-né vides pour un accouchement gémellaire, rien n'est verrouillé ni ajouté comme champ (ADR-136)
- [x] Actes demandés à la Réception enregistrables en un clic ; choix des actes en boutons ; « Autres » exige sa précision (ADR-136)
- [x] Acte Maternité corrigeable (quantité, précision) ou retirable (crayon, corbeille) par le personnel, sauf l'acte d'un médecin qui reste intact ; retrait tracé, l'acte quitte la liste (ADR-140)
- [x] Acte Maternité enregistré facturé au tarif serveur, rattaché à la facturation de la Réception quand elle existe déjà ; retirer ou changer la quantité défacture ce que la Maternité avait porté, jamais une facture (ADR-141)
- [x] État de facturation de chaque acte affiché sans montant — « À la Caisse », « Sur facture », « Non facturé — à régulariser » (ADR-141, ADR-103)
- [x] Matériel utilisé en Maternité transmis à la Pharmacie par le circuit des consommables Soins, dans le même geste que les actes, avec matériel habituel suggéré et origine Soins/Maternité dans la file (ADR-142)
- [x] Matériel habituel configurable pour les actes Maternité avec tout produit stockable (DIU, implant, injectable), les Soins restant limités à la parapharmacie (ADR-142)
- [ ] Créer en Pharmacie DIU / implant / Sayana Press, les prix de vente, et les associer aux actes Maternité — configuration de la clinique (ADR-142)
- [x] Dossier médical : la Maternité en est une section (grossesse, prénatal, travail, accouchement, actes) et chaque bébé y a son bloc, gardée par `maternity.view` ; un dossier, pas deux (ADR-143)
- [x] Le bébé devient un patient relié à sa mère par un geste explicite : numéro dérivé (A-26-0009-B1), naissance jamais devinée, jumeaux acceptés, geste idempotent, aucun passage ouvert, soins du bébé sur le compte de la mère (ADR-144)
- [x] Lien mère–bébé lu dans les deux sens (dossier Maternité, dossier patient, dossier médical) sans rien de clinique de la mère chez le bébé ; retirer la fiche d'un bébé relié est refusé (ADR-144)
- [x] Dossier médical lisible par patient, sans passage (`/patients/{patient}/dossier-medical`) : le modèle papier existe enfin pour un bébé, avec un bloc « Naissance » et rien de clinique de la mère (ADR-145)
- [x] Onglets Mère · Bébé 1 · Bébé 2 sur le dossier médical, et un seul composant `NewbornDossiers` (Maternité, détail du passage) au lieu de trois blocs écrits à la main, hors du formulaire verrouillé (ADR-145)
- [x] Sexe du bébé choisi dans la fenêtre de création quand la fiche ne le porte pas, puis écrit dans la fiche (ADR-145)
- [x] Le bébé vit dans le dossier de sa mère et ne devient patient qu'à l'accueil : la Réception demande « accouchement chez nous ou ailleurs ? », cherche la mère, choisit le bébé dans son arborescence (ADR-146)
- [x] Arrivée nouveau-né cohérente : né ici = recherche de la mère seulement ; né ailleurs = identité minimale du bébé, champs d'adulte refusés, parent/responsable porté par le passage (ADR-146, amendement du 2026-09-22)
- [x] Profil enfant piloté par « Enfant fille / garçon » : identité et domicile familial seulement ; champs d'adulte masqués et refusés, contact parent porté par le passage (ADR-146, amendement du 2026-09-22)
- [x] Nom et prénom du bébé saisis dans sa fiche Maternité (facultatifs) ; un bébé non prénommé se dit « Bébé 2 de RAKOTO », jamais un prénom inventé (ADR-146)
- [x] Dossier médical d'un bébé lisible dès sa fiche, avant tout dossier patient (`/passages/{episode}/nouveau-nes/{uuid}/dossier-medical`), qui redirige vers son dossier patient dès qu'il en a un (ADR-146)
- [x] Geste de création retiré de la Maternité : la sage-femme consigne le bébé, l'accueil ouvre son dossier patient (ADR-146)
- [x] Dossiers de bébés ouverts par l'ancien geste et jamais utilisés rendus à la fiche de leur mère, nom compris, numéro libéré ; ceux qui ont servi restent patients (ADR-146, amendement du 2026-09-20)
- [x] Ligne « Nouveau-né de RAKOTO Vola » dans le répertoire, qui mène au dossier de la mère : un bébé accueilli reste un patient qu'on cherche par son nom (ADR-146)
- [x] Carte « Nouveau-nés nés à la clinique » dans le dossier de la mère, lue sur les fiches : un bébé y figure dès l'accouchement, avant tout dossier patient (ADR-146, amendement du 2026-09-20 bis)
- [x] Repère « 1 bébé né ici » sur la ligne d'une mère dans le répertoire, ce que la Réception cherche à chaque arrivée d'un nouveau-né (ADR-146)
- [x] Droits propres au nouveau-né — `newborns.view`, `newborns.medical_record.view`, `newborns.patient.create` — à la place de `maternity.view`, que ni la Réception ni Médecine ne détiennent (ADR-146, amendement du 2026-09-20 ter)
- [x] Catégorie « Nouveau-nés » dans « Rôles & permissions » : le droit d'ouvrir le dossier d'un bébé se coche depuis le portail, il n'est pas accordé d'office à la Réception (ADR-064)
- [x] Dossier médical d'un bébé composé comme une feuille de nouveau-né — identité, mère à joindre, naissance et accouchement, état à la naissance — sans situation maritale, profession, adresse, tabac ni antécédents d'adulte (ADR-145, amendement du 2026-09-20)
- [ ] Validation par les sages-femmes du partage mère / bébé des données d'accouchement (mode, terme, complications côté bébé ; gestité, travail, délivrance côté mère) — le CDC ne décrit aucun dossier de nouveau-né (ADR-145)
- [ ] Bébé de sexe indéterminé comme patient, correction d'un lien créé par erreur, facturation propre au bébé pour ses futurs passages — règles non définies par le CDC (ADR-144)
- [x] Soins bébé notés par nouveau-né (jumeaux : chacun ses soins), soins mère uniques ; ancienne note commune conservée si elle existe (ADR-139)
- [x] Actes Maternité enregistrés dans un panier : ajout d'un clic, quantité et précision par ligne, enregistrement de tout le panier d'un geste (tout ou rien), actes demandés ajoutables en bloc (ADR-138)
- [x] Saisie du dossier Maternité conservée côté serveur par compte, restaurée après une actualisation (ADR-136, comme ADR-073)
- [x] Repères sous les champs du dossier Maternité : poids de naissance (unité, conversion kg, faible/élevé), Apgar, terme, hauteur utérine, rythme fœtal, parité, dates ; terme estimé et âge de la grossesse proposés depuis les dernières règles, jamais appliqués d'office (ADR-137)
- [ ] Validation des seuils Maternité par une sage-femme ou un médecin de la clinique (ADR-137)
- [ ] Champs cliniques propres à chaque acte Maternité — à fournir par les sages-femmes, rien n'est inventé (ADR-136)
- [ ] Facturation d'un acte ajouté en Maternité au-delà de la demande de la Réception — règle à définir (ADR-136)
- [x] Référentiel initial des actes infirmiers fourni par le client, sans tarifs inventés
- [x] Projection partagée des constantes et alertes (CareRecordReadModel) entre Soins, Médecine et Chirurgie/Anesthésie
- [x] Antécédents patient exposés via un point d'entrée générique, consultables et ajoutables depuis Médecine
- [x] Antécédents distingués personnels / familiaux, traitements actuels déclarés en consultation, lieu de naissance au dossier patient
- [x] Clôture administrative automatique (PENDING_SETTLEMENT) d'un parcours Soins seul réellement terminé, sans sortie médicale fictive
- [x] Page transversale « Détail du passage » en lecture seule, sécurisée section par section côté serveur
- [x] Parcours d'un passage composé une seule fois (`EpisodePathwayTimeline`) : Réception, orientations, Pharmacie, factures, encaissements et sortie, affichés à l'identique par le détail du passage et par la frise du dossier patient (ADR-117)
- [x] Deux demandes vers le même service numérotées (« Soins 1 », « Soins 2 ») au lieu d'un doublon apparent ; chaque demande de soins dit d'où elle vient, qui l'a faite et la suite décidée par le médecin (retour en Médecine ou sortie directe)
- [x] Actes demandés au sein de chaque demande de soins, lus sur les actes réellement enregistrés (réalisé, à réaliser, non réalisé, retiré)
- [x] Étape « Sortie — à prononcer par la Réception » quand le passage n'attend plus que son règlement : la réponse à « pourquoi ce passage est-il encore ouvert ? »
- [x] L'ordonnance du prescripteur figure au parcours (« Pharmacie · Ordonnance ») avec son issue — transmise, délivrée, annulée — sans révéler l'état de règlement de la Pharmacie ; une ordonnance hors référentiel s'annonce comme telle ; facture et encaissement d'un ticket Pharmacie portent la mention « Ticket Pharmacie » (ADR-117)
- [x] Dossier médical imprimable, reprenant identité, constantes, allergies, antécédents familiaux, hospitalisation et diagnostic déjà consignés — rien n'est ressaisi, les sections sensibles restent gardées par leur permission (ADR-116)
- [x] Fuite corrigée : le diagnostic, les traitements et le motif d'hospitalisation de la feuille imprimée sont gardés par `diagnoses.view`, `medical_record.view` et `hospitalization.view` — la Réception les lisait avec `patients.view` seul (ADR-116, amendement du 2026-09-20)
- [x] Journal de traitement (« Dossier médical – Traitement ») : chronologie automatique lue depuis ce qui est déjà enregistré, complétée de lignes manuelles append-only pour ce que l'application ne sait pas encore (ADR-116)
- [x] Tous les journaux de traitement d'un patient réunis en un seul document (couverture puis une feuille par passage, une page chacune), lisible à l'écran et enregistrable en un seul PDF par l'impression du navigateur, sous un nom de fichier explicite (ADR-118)
- [x] Bouton « Journaux de traitement » dans l'en-tête du dossier patient, sur l'onglet Passages (avec « Journal » par passage) et dans la fenêtre d'un patient de la file Soins, réservé à `treatment_journal.view` (ADR-118)
- [x] File Soins : « 1 passage · 2 demandes de soins » au lieu de « 2 passages » ; chaque demande dit qui l'a faite, la suite décidée et ses actes, par une règle partagée avec le parcours du passage (`CareRequestSummary`, ADR-118)
- [x] Dossier patient passé à shadcn-vue (ADR-099) : statuts en pastilles et icônes lucide, `FormField` et `Checkbox`, plus aucune classe de la police d'icônes
- [ ] PDF généré par le serveur (envoi par courriel, archivage sans interaction) — exigerait une bibliothèque de rendu, à décider si le besoin apparaît (ADR-118)
- [x] Tabac et alcool lus selon l'âge (« Oui » improbable avant 10 ans, à signaler chez un mineur), âge invraisemblable signalé, « Oui » rouge / « Non » vert (ADR-126)
- [x] Constantes lues selon l'âge — FC, tension, température du nourrisson —, table unique servie à l'écran, et toast sur une valeur critique ou improbable ; tables à valider par un médecin (ADR-125)
- [ ] Tension et IMC de l'enfant par percentiles (âge, sexe, taille) — tables à fournir par la clinique (ADR-125)
- [x] Fiche de soins à cinq étapes : la transmission à Médecine, facultative, rejoint « Terminer » au lieu d'avoir son propre écran (ADR-123)
- [x] Patient pris en charge par erreur aux Soins : « Remettre en file » le replace à sa place tant qu'aucun soin n'est enregistré, audité (ADR-122)
- [x] Patient pris en charge par erreur en Médecine : « Remettre en file » le replace à sa place tant que la consultation est vierge, audité (ADR-127)
- [x] Les trois profils Soins (Infirmière, Maternité, Anesthésie) sont toujours affichés : celui sans droit est verrouillé et nomme la permission, au lieu d'être masqué — la barre disparue faisait lire l'écran comme une version ancienne (ADR-158)
- [x] La file Soins s'ouvre avec `care.create` (faire les soins), plus avec `care.update` : un compte Médecine, qui l'a pour corriger une fiche (ADR-093), entrait dans l'espace des infirmières (ADR-157)
- [x] La suite des Soins se choisit à l'étape Terminer : un patient attendu en Médecine se termine aux Soins avec un motif obligatoire (tracé, visible dans le parcours), un patient prévu aux Soins seuls s'envoie au médecin avec sa transmission ; la consultation prévue reste facturée (ADR-166)
- [ ] Besoin inconnu terminé aux Soins sans Médecine : le passage reste « en soins » (ADR-054 ne vaut que pour Soins seuls) — à décider (ADR-166)
- [x] Reprendre la prise en charge Soins d'un collègue, motif obligatoire et tracé (`care.complete`) ; la suite des soins se montre verrouillée avec le nom de qui la décide, au lieu d'être masquée (ADR-167)
- [x] Suite prévue affichée seulement (bordure bleue) ; la suite non prévue demande un motif dans les deux sens, et un seul motif suffit quand il faut aussi reprendre le patient (ADR-166, ADR-167, amendements du 2026-09-21)
- [x] Une seule fenêtre de reprise : un motif, puis la case « Reprendre la prise en charge » ; bandeau jaune retiré, reprise sans changer la suite depuis le pied de l'étape Terminer (ADR-167, amendement bis du 2026-09-21)
- [x] File Soins réduite à deux onglets — À prendre aux Soins / Orientés en attente du médecin : les patients déjà accueillis par le médecin quittent la page pour le module Patients (ADR-124)
- [x] File Soins : prendre un patient qui n'est pas le premier demande confirmation, comme en Médecine — règle et fenêtre partagées, rien n'est bloqué (ADR-121)
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
- [x] Hospitalisation (ADR-113)
- [x] Transfert médical : module Transferts, demande en un clic, établissement complété dans le module, « Transfert effectué » (ADR-114)
- [ ] Arrivée et accusé de réception d'un transfert — personne ici ne les observe (ADR-114)
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
- [x] Diagnostic déjà posé à l'examen rappelé à la clôture (« rien à ressaisir ») ; retirer le dernier diagnostic d'une vraie consultation prévient que la clôture en dépend — l'exigence du CDC §33.1 est maintenue (ADR-081, amendement du 2026-09-20)
- [x] Diagnostic final facultatif pour un passage venu seulement pour un examen (ECG, écho, analyse) : la conclusion de l'examen en tient lieu, et le résultat n'est souvent pas revenu à la clôture (ADR-094)
- [x] « Le diagnostic peut-il être posé maintenant ? » posée à Décision & clôture, seule étape que tout patient atteint ; un report est nommé comme tel dans les blocages au lieu de passer pour un oubli (ADR-095)
- [x] « Oui » sans diagnostic ouvre la saisie au lieu d'échouer : le refus serveur renvoyait à un champ que « Pas maintenant » gardait replié (ADR-095)
- [x] Saisie de diagnostic sans distinction hypothèse / final ; les hypothèses déjà enregistrées gardent leur type et restent signalées (ADR-082)
- [x] Voie d'administration sur les lignes d'ordonnance, facultative et jamais rétro-remplie (ADR-083)
- [x] Posologie composée avec ses unités à la saisie ; plus de « Dose 500 / Fréquence 3 » sans contexte
- [x] Dose facultative pour un produit qui ne se dose pas (compresses, gants) : la forme du référentiel décide, jamais le libellé (ADR-110)
- [x] Ligne d'ordonnance relue avant signature : voie incompatible avec la forme, fréquence sans unité, quantité insuffisante, allergie, enfant sans poids, doublon de principe actif, dose en mg/kg — sans jamais bloquer ni prétendre juger une dose (ADR-128)
- [ ] Doses maximales par produit, âge et poids — à fournir par la clinique pour détecter réellement une dose trop élevée (ADR-128)
- [x] Quantité totale déduite de la fréquence et de la durée, base du calcul écrite sous le champ, jamais imposée sur une quantité déjà corrigée (ADR-110)
- [x] Aucune quantité suggérée quand la posologie n'en implique aucune (« si besoin », fréquence libre, durée absente)
- [x] Écran Ordonnance passé à shadcn (ADR-099) : `Select`/`FormField` dans l'éditeur de ligne, unité attachée à la quantité, catalogue distinguant « Dans l'ordonnance » et « Épuisé », reprise de la quantité déduite en un clic (amendement ADR-110)
- [x] Protocoles thérapeutiques de la clinique (`/medicine/protocoles`) : signes évocateurs, population (âge, sexe, poids), ordonnance type — rédigés par les médecins, jamais inventés (ADR-111)
- [x] Diagnostics proposés à l'Examen clinique et à Décision & clôture, avec les signes retrouvés ; « Retenir » les enregistre, toujours corrigeables (ADR-111)
- [x] Ordonnance proposée à la Prescription pour les diagnostics posés : lignes préremplies et modifiables, épuisés non ajoutables, allergies en rouge et exclues de « Tout ajouter » (ADR-111)
- [x] Origine de chaque diagnostic et ligne retenus tracée (`suggestion_source`, `clinical_protocol_id`) et revérifiée par le serveur (ADR-111)
- [x] Algorithme local « Pratique de la clinique » : diagnostics appris du vocabulaire des consultations passées, ordonnance habituelle et sa posologie la plus fréquente, sans aucun service externe (ADR-111)
- [x] Seuils de prudence (3 cas, 2 occurrences, 30 %), consultation en cours exclue de sa propre preuve, patient hors de la tranche d'âge déjà traitée signalé (ADR-111)
- [x] Motif prérempli par le nom de la prestation retiré du texte analysé : ce n'est pas un symptôme (ADR-111)
- [x] Navigation précédente pointant vers la dernière étape réellement pertinente, jamais vers une étape « Non nécessaire »
- [x] Demandes d'analyses et d'imagerie annulables (`cancelled_at`), jamais supprimées ; une demande avec résultat n'est jamais retirée
- [x] Conduite à tenir portée par `consultation_orientations` (SELECTED / SUBMITTED / CANCELLED), décidée dès que le médecin en sait assez (ADR-084)
- [x] Parcours à six étapes terminé par une vraie Clôture : vérifier, signaler ce qui manque, valider — jamais redemander la décision
- [x] Formulaire de la destination ouvert immédiatement après le choix, prérempli du dossier : plus aucune double saisie
- [x] Demandes d'hospitalisation et de référence/transfert avec leur table, leur statut et leur document imprimable
- [x] Module Hospitalisation (ADR-113) : admission automatique à la demande du médecin, séjour, chambre/lit en texte libre, sortie par la sortie médicale
- [x] Fiche de régime par séjour : grille jour/heure en texte libre, remplie par Médecine et Soins, en-tête repris du dossier, imprimable au format papier, sans facturation
- [x] Sortie d'hospitalisation alimentée par les diagnostics déjà consignés (passage et séjour), cochés d'office : la sortie était impossible, le formulaire n'en recevait aucun (ADR-147)
- [x] Diagnostic conclu au terme du séjour enregistré sur le séjour (`hospital_stay_diagnoses`), append-only : la consultation qui a demandé l'hospitalisation est close et n'est jamais réécrite (ADR-147, ADR-076)
- [x] Réception en lecture sur l'Hospitalisation (`hospitalization.view`) : détail, dossier et impression de la fiche ; sortie, séjour, régime, demande et diagnostic restent refusés (ADR-147)
- [ ] Annulation tracée d'un diagnostic de sortie, et reprise de ces diagnostics par les propositions de l'ADR-111 — à décider (ADR-147)
- [x] Visite de service : chaque visite ouvre une vraie consultation rattachée au séjour (diagnostic, ordonnance, analyses, imagerie, ordre de soins), sans dupliquer un seul circuit ; le patient n'entre jamais dans la file d'attente et reste hospitalisé (ADR-148)
- [x] Bloc de sortie regroupé en trois sections encadrées — Décision / Conclusion médicale / Consignes — au lieu d'une colonne étalée dont la moitié restait vide (ADR-148, ADR-099)
- [x] Conduite à tenir « Poursuite de l'hospitalisation » : une visite de service se clôture enfin — « Sortie médicale » et « Hospitalisation » sont retirées pour un patient au lit (second séjour, sortie sans fin de séjour), et l'écran dit où la sortie se prononce (ADR-149)
- [x] Repère « Hospitalisé · chambre » dans l'en-tête de la consultation et dans la carte de conduite à tenir (ADR-149)
- [x] Aucun rôle codé en dur sur l'Hospitalisation : un compte de Réception à qui l'on accorde les droits fait tout ce qu'ils permettent — séjour, régime, diagnostic, visite et sortie ; un test le prouve et interdit toute régression (ADR-152)
- [x] Sortie médicale refusée **côté serveur** depuis une consultation tant qu'un séjour est actif : l'écran le retirait déjà, mais l'interface n'est jamais la seule garde — règle de cohérence, jamais de droit (ADR-152)
- [x] Une sortie déjà prononcée n'est plus redemandée : la clôture la rattache comme conduite à tenir (DISCHARGE/SUBMITTED), le bouton « Clôturer » ne reste plus gris au-dessus d'un fait daté et signé (ADR-156, ADR-107)
- [x] Vue « Sortie médicale prononcée · service pas encore clôturé » à la Réception : un passage sorti du lit mais dont la consultation reste ouverte n'était visible nulle part (ADR-156)
- [x] Toutes les sorties se suivent à « Sorties & règlements » : chaque ligne dit si le passage est passé par un lit (service, chambre, lien vers le séjour avec `hospitalization.view`) — retirer la liste des séjours terminés sans la remplacer les aurait perdus (ADR-156)
- [x] `/hospitalisation` ne liste plus que les patients au lit : l'onglet « Sortis » doublait « Sorties & règlements » ; une recherche nommée retrouve un séjour terminé (ADR-156)
- [x] Une seule sortie médicale, prononcée dans la consultation (« Décision & clôture » › Sortie médicale) : elle termine le séjour dans la même transaction, et le second formulaire du module Hospitalisation est retiré (ADR-156, renverse ADR-149/152)
- [x] ~~Une visite de service ouverte retient la sortie d'hospitalisation~~ — sans objet (ADR-156) : la sortie est prononcée dans la visite : elle restait « En cours » après la sortie, gardait une orientation Médecine active et empêchait le passage d'atteindre « Sorties & règlements » (ADR-155)
- [x] Une visite laissée ouverte par une sortie déjà prononcée se clôture enfin : la sortie se lit sur le passage, plus seulement sur sa consultation (ADR-155, ADR-107)
- [x] Transférer au bloc depuis le séjour : le patient garde son lit (séjour ACTIVE, passage HOSPITALIZED), demande PENDING d'origine « Hospitalisation », intervention choisie, service/chambre/diagnostic d'entrée repris sans ressaisie (ADR-160)
- [x] Défaut évité : passer par la consultation d'origine encore ouverte annulait le séjour — le patient perdait son lit (ADR-160)
- [x] Le bloc et l'anesthésie savent qu'un lit attend : bandeau sur la fiche, pastille dans la file, lien vers le séjour avec `hospitalization.view` ; la page du séjour suit ses passages au bloc (ADR-160)
- [x] La liste des hospitalisés marque qui va au bloc, qui y est et qui en revient (sous le nom, visible sur téléphone), avec une carte « Vers le bloc » qui filtre ; libellés partagés avec la page du séjour (ADR-160, amendement du 2026-09-21)
- [ ] Paramètres du bloc / de l'anesthésie « selon les modèles » — captures attendues de la clinique (ADR-160)
- [x] Transfert externe : le séjour se termine au départ du patient, constaté dans Transferts ; un seul circuit pour un patient au lit — la sortie « Transfert » ne lui est plus proposée (ADR-161)
- [x] Motif de fin de chaque séjour (domicile, transfert, à la demande, refus, décès), repris pour les séjours déjà terminés depuis leur sortie réelle (ADR-161)
- [x] Emplacements du séjour : historique service / lit / niveau de soins, « Changer de service / lit » distinct de « Corriger » ; mutation en réanimation ou surveillance continue sans quitter le séjour (ADR-161)
- [x] Surveillance répétée des constantes pendant le séjour, bornes et repères de la fiche Soins, relevés corrigeables et tracés (ADR-161)
- [x] L'onglet Surveillance nomme qui relève et le droit manquant au lieu d'un cadre vide ; un relevé est saisi à la main, jamais alimenté par un autre circuit (ADR-161, amendement du 2026-09-21)
- [ ] `vitals.create` au socle `MEDICINE` — un médecin qui examine au lit prend la tension ; se coche depuis le portail (ADR-064), décision du propriétaire
- [x] Le séjour, poste de travail du patient hospitalisé : onglets Vue d'ensemble / Notes / Ordonnances / Examens / Soins / Surveillance / Régime / Bloc / Sortie, chaque geste par l'action existante (`executeForStay`), sans rouvrir de consultation (ADR-162)
- [x] Note du jour S/O/A/P, append-only, droits `hospital_notes.view` / `.create` (ADR-162)
- [x] Ordonnance du séjour délivrée au service sans attendre le règlement ; la facture rejoint « Sorties & règlements » (ADR-162, amende ADR-049)
- [x] Sortie médicale d'un patient hospitalisé prononcée sur la page du séjour, et là seulement ; refusée depuis une consultation tant que le séjour est actif (ADR-162, renverse ADR-156)
- [x] Ajouter un diagnostic depuis l'étape Sortie, sans renvoi vers un autre onglet : les diagnostics consignés restent cochés, le nouveau arrive coché (ADR-162, amendement du 2026-09-21)
- [x] Diagnostic final multi-lignes lu ligne par ligne à la sortie : la liste cochée n'est plus enregistrée comme un diagnostic de plus (ADR-162, amendement du 2026-09-21)
- [x] Étape Sortie à deux colonnes : formulaire pleine largeur et colonne « Repères du séjour » — séjour, allergies, dernier relevé, avant de conclure (ADR-162, amendement du 2026-09-21)
- [x] Transfert depuis le séjour : les autres sites de la clinique proposés dans une liste, « Autre établissement… » pour une saisie libre ; liste des sites calculée une seule fois (`ClinicSites`) (ADR-162, amendement du 2026-09-21)
- [x] Bande des constantes (consultation, séjour, Maternité) ramenée à une seule ligne : 48 px au lieu de ~150 sur ordinateur, 146 au lieu de ~250 sur téléphone ; flèche, libellé écrit et aria-label conservés, le décompte des anomalies ouvre le détail
- [x] En-tête clinique (consultation, Soins, Maternité) en deux lignes serrées : identité et actions, puis repères et orientation sur une ligne — 98 px au lieu de ~190 sur ordinateur ; « Dr Dr. » corrigé quand le nom porte déjà son titre
- [x] Vue d'ensemble du séjour : cartes « Séjour » et « Demande d'hospitalisation » refondues en shadcn avec icônes (repères en tuiles, jour de séjour, priorité en pastille, rubriques repliables mesurées à l'écran), extraites en `StayLocationCard` / `StayRequestCard`
- [x] Demande d'hospitalisation corrigée rubrique par rubrique (un crayon par rubrique et pour la priorité) ; le serveur n'écrit que les champs envoyés, plus jamais les six à la fois (ADR-113, amendement du 2026-09-21)
- [x] Carte « Diagnostics » du séjour placée sous « Séjour » et refondue (`StayDiagnosesCard`) : compteur, origine Consultation / Séjour, auteur et date iconés, ajout en pied de carte
- [x] Visite de service retirée ; les visites déjà ouvertes restent lisibles (ADR-162)
- [x] Retirer une analyse / une imagerie depuis le séjour, avec la facturation qu'elle avait portée (ADR-163)
- [x] Propositions d'ordonnance (ADR-111) pour le séjour, sur les diagnostics du passage, origine revérifiée (ADR-163)
- [x] Annuler un transfert au bloc depuis le séjour tant que le bloc ne l'a pas programmé ; la conduite « Chirurgie » d'une consultation suit (ADR-163)
- [x] Annuler une visite de service restée ouverte, sans l'effacer, tant qu'elle n'a rien produit ; sinon la clôturer (ADR-163)
- [x] Carte « Visites de service » retirée du séjour : une visite ouverte paraît parmi les consultations à conclure, une visite close se relit sur la page du passage (ADR-163)
- [x] Annuler un transfert externe demandé depuis le séjour tant que le patient n'est pas parti (ADR-163)
- [x] Consultations du passage restées ouvertes signalées sur le séjour, avec ce qui manque et une clôture d'un clic (ADR-163)
- [x] Le séjour ne s'annule plus dès qu'il a eu lieu (note, ordonnance, examen, relevé, bloc…) : piège de l'ADR-160 fermé (ADR-163)
- [x] Retirer une demande en consultation annule enfin ce qu'elle avait facturé ; la prestation de la Réception n'est jamais annulée et se libère (ADR-163)
- [ ] Déprogrammer une intervention côté bloc, et prise en charge / fin de l'orientation vers le bloc — non définis (ADR-163)
- [ ] Lot 2 (reste) : plan de prise en charge, médecin référent (ADR-161)
- [ ] À décider : traitement hospitalier et délivrance sans attendre le paiement, référentiel des lits, évasion pendant le séjour, compte rendu d'hospitalisation, facturation du séjour, paramètres de surveillance supplémentaires (ADR-161)
- [x] Services, chambres et lits par site, réglés depuis le portail par l'API du site : chambre créée avec son nombre de lits, lits renommables, ajoutables, hors service avec motif, archivables (ADR-164)
- [x] Un lit occupé ne peut pas être donné à un autre patient : occupation lue sur les séjours en cours, garantie par un index unique en base et un verrou, refus qui nomme l'occupant ; lit libéré à toute fin de séjour (ADR-164)
- [x] Séjour ouvert « lit à attribuer », puis « Attribuer un lit » / « Changer de lit » parmi les lits libres ; niveau de soins repris du service ; onglet « Plan des lits » dans `/hospitalisation` (ADR-164)
- [x] Texte libre de l'ADR-113 conservé tant qu'un site n'a configuré aucun lit, refusé côté serveur ensuite ; instantanés `service` / `room_bed` jamais réécrits (ADR-164)
- [x] Liste des hospitalisés en shadcn avec icônes et sélection multiple (50 au plus) : tour de salle en paysage, fiches de régime et dossiers médicaux groupés (une feuille par page, les mêmes que l'impression unitaire), export Excel audité — jamais d'action clinique en lot (ADR-165)
- [x] Droit dédié `hospitalization.export`, accordé à Médecine et Soins, pas à la Réception (ADR-165)
- [ ] Configurer les services, chambres et lits de chaque site en production depuis le portail — tant qu'aucun n'existe, le site reste en texte libre (ADR-164)
- [ ] Tour de salle en lot, prescription permanente reconduite, forfait journalier, réservation d'un lit pour une admission future — aucune règle définie (ADR-113, ADR-148, ADR-164)
- [x] Module Pédiatrie simple (ADR-114) : file, prise en charge, sortie médicale rattachée à la consultation d'origine
- [ ] Fiche pédiatrique — aucune fournie par la clinique, rien n'est inventé (ADR-114)
- [x] Conduite à tenir vers un module transmise en un clic (Maternité, Pédiatrie, Transfert) ; Chirurgie : intervention choisie, diagnostic/hypothèse généré du dossier (ADR-114)
- [x] Changement d'orientation traçable : annulation propre tant que la destination n'a pas pris la demande, refus explicite ensuite
- [x] Clôture seule responsable de terminer l'orientation Médecine et de porter la sortie sur l'épisode
- [x] Permission propre à l'espace « Demandes d'examens » (`paraclinical_requests.view`, ADR-100) : l'écran s'ouvrait uniquement avec le droit sur les analyses, refusant un compte qui n'avait que l'imagerie
- [x] Compte rendu d'imagerie saisi depuis « Demandes d'examens » (éditeur riche) et imprimable avec l'en-tête du site (ADR-070)
- [x] Feuilles de compte rendu de la clinique (écho abdomino-pelvienne, écho obstétricale 1er trimestre) insérables dans le compte rendu, choisies par le médecin et jamais déduites du nom de l'examen (ADR-108)
- [ ] Feuille ECG — aucun modèle transmis, rien n'est inventé (ADR-108)
- [x] Cinq feuilles d'échographie strictement identiques au papier : abdomino-pelvienne (deux versions : N.B. ou Prostate), pelvienne, obstétricale 1er trimestre et 2e–3e trimestre, en deux colonnes avec cases Conclusion / N.B. pleine largeur (saut `<hr>`, amendement ADR-108)
- [x] Fenêtre de compte rendu d'imagerie refondue (shadcn) : liste de feuilles, un éditeur par case du papier, barre d'outils commune, observations repliées, compteur du plafond serveur (ADR-108)
- [x] Feuilles d'échographie ajoutées par les médecins du site : « + » depuis la fenêtre de compte rendu, enregistre le contenu actuel, retrait par archivage, droits `imaging_templates.*` (amendement ADR-108)
- [x] La feuille d'un examen s'ouvre d'elle-même à la première saisie (réglée par site, liste explicite par code pour les feuilles papier, jamais déduite d'un nom) ; épingle pour la régler ou la retirer (amendement ADR-108)
- [x] Aperçu du compte rendu avant de l'enregistrer : document composé par le serveur, identique à l'impression, sans rien écrire (amendement ADR-108) ; puces des listes rétablies à l'écran et à l'impression
- [x] Sept propositions de feuilles pour les échographies sans modèle papier (abdominale, rénale, prostatique, mammaire, thyroïdienne, scrotale, parties molles), marquées « à valider », sans aucune valeur ni norme
- [ ] Validation médicale des propositions par la clinique, ou modèles papier réels pour les remplacer (ADR-108)
- [x] Modifier une feuille ajoutée : renommer et remplacer son contenu, comptes rendus déjà écrits intacts (`imaging_templates.update`)
- [x] Titre exact de la feuille dans le bandeau, figé sur le compte rendu (instantané lu côté serveur) (ADR-108)
- [x] Compte rendu d'imagerie corrigeable après saisie : la version remplacée est conservée (auteur, date, motif), la signature d'origine ne bouge pas, « Corrigé » et historique visibles dans « Demandes d'examens » (ADR-130)
- [x] « Demandes d'examens » : onglets ECG / Échographie / Analyses combinés avec les vues, boutons compacts (actions par examen, icônes nommées, consultation avec son icône), archivage à la main réversible (ADR-131)
- [ ] Modifier un compte rendu depuis l'étape Paraclinique de la consultation — à décider (ADR-130)
- [x] Une seule saisie du compte rendu d'imagerie (`ImagingReportDialog`), identique depuis la consultation et « Demandes d'examens » ; plus d'éditeur vide sous un compte rendu déjà enregistré (amendement ADR-108)
- [x] Compte rendu d'imagerie au format de la feuille papier de la clinique (logo, N° de dossier, identité, deux colonnes, N.B., signature), identique à l'écran et à l'impression (amendement ADR-108)
- [x] File Maternité passée à shadcn (ADR-099) : cartes, pastilles d'état et pagination par les primitives partagées ; la prise en charge devient un POST au lieu d'un bouton imbriqué dans un lien-bouton
- [x] Cartes compteur partagées sur les files cliniques (Médecine, Soins, Laboratoire, Demandes d'examens) : la carte est le filtre, et le compte vient du serveur — jamais de la page affichée
- [x] Réouverture tracée d'une consultation clôturée, tant que la Réception n'a pas clos le passage (ADR-096, construit le mécanisme annoncé par l'ADR-076)
- [x] Registre des décès (`/deces`) : un décès prononcé ne réapparaissait nulle part, la file Médecine ne montrant que les prises en charge en cours (ADR-107)
- [x] Acte de constatation de décès : distinct de la sortie qui prononce le décès, un seul par passage, jamais avant lui, imprimable (ADR-107)
- [x] Sortie pour décès ne proposant plus état du patient, traitement de sortie, conseils ni contrôle : des instructions sans destinataire, refusées aussi côté serveur (ADR-107)
- [x] État du patient d'un décès posé par le serveur (« Décédé ») : aucune des cinq options ne convenait, et l'absence aurait été lue comme un oubli
- [x] Sortie médicale confirmée comme une signature, dans ses propres termes pour un décès (ADR-106, ADR-107)
- [x] Acte de constatation de décès conforme à la feuille papier de la clinique (médecin traitant, défunt, filiation, CNI, signatures), causes et observations en texte riche, sans jamais modifier le dossier patient (amendement ADR-107)
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
- [x] La file des consommables reçoit aussi le matériel du bloc, origine « Bloc opératoire » sur la demande et le mouvement de stock (ADR-169)
- [x] Ce que le patient doit pour ce matériel affiché sur la file Pharmacie — montant, facture et statut, en lecture seule (ADR-103)
- [x] Ligne jamais facturée comptée et nommée (`unbilled_lines`) : l'échec de facturation, volontairement non bloquant, n'est plus silencieux (ADR-103)
- [x] Définition des demandes de dispensation ouvertes écrite une seule fois (`PharmacyDispenseStatus::openValues()`), partagée par la file Pharmacie et le répertoire patients (ADR-119)
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
- [x] Médicaments d'essai de nouveau chargés par `migrate:fresh --seed` (38 fictifs : injectables, solutés, contraception, matériel), créés une seule fois et jamais réécrits (ADR-086, ADR-163)
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
- [x] Lignes du catalogue fournisseur actif commandables directement : le produit entre au catalogue de la clinique à la commande, sans prix de vente (fixé après réception)
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
- [x] Onze actes du récapitulatif « Revenus » de la clinique ajoutés au référentiel (abcès, ectopie testiculaire, furoncles, hernie inguinale et inguino-scrotale, invagination, kyste sous-cutané, plaie linéaire, torsion du cordon, volvulus, cystostomie de dérivation) — sans tarif : le prix appartient au Super Admin (ADR-024)
- [x] Chirurgie · la file se lit comme les autres espaces : en-tête `SoinsWorkspaceHeader`, quatre vues exclusives en cartes-filtres (À programmer / Programmées / Au bloc / Terminées) comptées par le serveur, tableau et pastilles shadcn (ADR-099, ADR-135)
- [x] La demande du bloc naît à la Réception (acte = raison de la venue) ou en consultation (conduite à tenir) ; le bloc ne crée plus de dossier — `/surgery/create` retiré et redirigé vers la file (ADR-159)
- [x] `SURGERY_DIRECT` : un acte du bloc sélectionné à l'arrivée ouvre l'orientation `RECEPTION → SURGERY` **et** sa demande `PENDING`, idempotente, sur le modèle des analyses (ADR-068)
- [x] 37 actes rendus sélectionnables à la Réception — « Autres », la césarienne (ADR-067), la consultation chirurgicale et la petite chirurgie écartées ; migration listant les codes explicitement, jamais tout le module (ADR-052, ADR-064)
- [x] Origine de chaque demande affichée et tracée (`surgical_requests.origin` : Réception / Médecine / Maternité), nullable et jamais rétro-remplie ; état vide expliquant les deux chemins (ADR-159)
- [x] Les trois garanties du catalogue (instantané du libellé, « Autres » à préciser, module respecté) déplacées sur la correction au bloc (`PUT /surgery/{demande}`) et testées là
- [x] Chirurgie · Show.vue refondu en assistant clinique shadcn à 5 étapes ; les fiches papier entrée, pré-anesthésie, paraclinique et sortie restent le vocabulaire métier, avec identité/âge cohérents et synthèse Soins sans ressaisie
- [x] Dossier du bloc lu dans l'ordre de son workflow : étape à faire marquée, étapes en attente qui disent pourquoi, barre « Prochaine étape » qui ouvre le bon formulaire, en-tête compact, sections shadcn (ADR-048, amendement du 2026-09-22)
- [x] Valider un compte rendu hors du bloc ne laisse plus un compte rendu à moitié validé (erreur 500 corrigée) ; « Valider » attend l'heure de fin, la sortie du bloc est proposée avant la sortie de Chirurgie (ADR-048)
- [x] Dossier du bloc en deux colonnes : le geste de l'étape à gauche (Programmation → Équipe ; Feu vert → Entrée au bloc ; Intervention ; Compte rendu → Suivi → Complications → Sortie), le contexte à droite (Anesthésie, Demande, Soins), le patient hospitalisé en pastille à côté du statut ; états Fait / À faire / en attente, sections en attente repliées (ADR-048)
- [x] Formulaires du bloc et de l'anesthésie enregistrés automatiquement, « Enregistrer et continuer » remplacé par « Suivant » ; statut d'enregistrement visible, aucun toast (ADR-048, amendement du 2026-09-22)
- [x] Espace Anesthésie refondu en shadcn (ADR-099) : en-tête d'étape commun avec icône, position « étape N sur 3 » et avancement des sous-étapes, blocs de champs iconés, antécédents en pastilles cochables ; aucune donnée ni règle modifiée (ADR-048, amendement du 2026-09-22)
- [ ] Enregistrement automatique réel de la consultation Médecine (aujourd'hui brouillon seulement, ADR-073) — à décider
- [x] Réinitialiser un dossier du bloc saisi à tort : tout archivé avant retrait, demande remise « À programmer », motif et confirmation obligatoires, refusé si la Pharmacie a déjà servi du matériel (`surgery.reset`, ADR-171)
- [ ] Relire / restaurer une archive de réinitialisation depuis l'écran (ADR-171)
- [x] « Dossier chirurgical » imprimable généré depuis les données — quatre feuilles fidèles au papier (Entrée au bloc, Sortie du bloc, Consultation pré-anesthésique, Examen paraclinique), une par page, entier ou une seule feuille, chaque feuille gardée par son droit ; « Imprimer le dossier » dans les deux espaces (ADR-172)
- [x] Le bloc figure au journal de traitement (entrée, intervention, sortie, traitements, complications) et au dossier médical (section « Bloc opératoire », anesthésie avec `anesthesia.view`) (ADR-172)
- [ ] Refus serveur d'un compte rendu rédigé avant l'intervention ou validé sans heure de fin — règle à décider (ADR-048)
- [x] Chirurgie et Anesthésie en parallèle, avec deux rendez-vous opposables : `SurgicalReadinessGate` garde l'incision et la clôture, appelé sous verrou dans la transaction — un POST direct est refusé comme l'écran (ADR-170)
- [x] Autorisation anesthésique distincte de la validation du bilan (`AnesthesiaClearanceStatus` : Autorisé / sous conditions / non autorisé / reporté) ; `DRAFT` n'est pas une décision et retient l'incision ; motif obligatoire pour un refus ou un report, lu par le bloc (ADR-170)
- [x] Conditions d'une autorisation sous conditions (`OPEN` / `RESOLVED`) : une condition ouverte bloque l'incision, et se lève par l'anesthésie seule (ADR-170)
- [x] Checklist de sécurité du bloc en trois temps (SIGN IN / TIME OUT / SIGN OUT) avec confirmation nominative par métier ; `completed_at` calculé des faits, jamais posé par un clic (ADR-170)
- [x] Bloquant ≠ avertissement : la fiche d'entrée au bloc et les points facultatifs avertissent sans jamais retenir une incision (ADR-170)
- [x] Valider le compte rendu ne clôt plus le dossier : `CompleteSurgicalCaseAction` exige intervention terminée, compte rendu validé, SIGN OUT, sortie du bloc et anesthésie finalisée (ADR-170)
- [x] Dossier d'anesthésie non verrouillable avant l'incision : la conduite peropératoire doit pouvoir y être consignée (ADR-170)
- [x] Autorisation par dossier au-dessus du RBAC (`SurgicalCaseActors`, `SurgicalRequestPolicy`, `AnesthesiaRecordPolicy`) ; plus aucun `authorize(): true` sur l'incision, l'anesthésie, la checklist et la clôture ; `performed_by` restreint aux chirurgiens du dossier (ADR-170)
- [x] `readiness` composé par le serveur (`SurgicalReadinessPresenter`) : aucune règle sensible recalculée en JavaScript ; un bouton désactivé dit pourquoi et nomme le métier attendu (ADR-170)
- [x] Étape « Décision » dans l'espace Anesthésie, entre Paraclinique et Conduite (ADR-170)
- [ ] Faire valider par la clinique le contenu des trois temps de la checklist (`SurgicalSafetyChecklistItems`) : items obligatoires et facultatifs — point de départ, jamais une règle médicale transcrite (ADR-170)
- [ ] Autorisation par dossier pour les huit FormRequests restées à `authorize(): true` (programmation, sortie de Chirurgie, équipe, sortie du bloc, notes, traitements) — gardées aujourd'hui par le seul `can:` de route (ADR-170)
- [ ] `WAIVED` sur une condition d'autorisation (qui pourrait passer outre la réserve d'un anesthésiste ?) et cohérence horaire entrée/sortie du bloc — non définis par le CDC, non inventés (ADR-170)
- [x] Programmation à plusieurs chirurgiens : « Moi-même » pour un compte au profil Chirurgien, principal + aides (équipe de bloc), disponibilité lue sur le planning RH à l'heure choisie, indisponibles montrés verrouillés (ADR-168)
- [x] Profils métier du rôle Chirurgie — Chirurgien, Infirmier de bloc, Paramédical — sans droit recommandé ; seul le profil Chirurgien est programmable (ADR-168)
- [x] Équipe de bloc : chaque fonction ne propose que les comptes de son profil métier (Anesthésiste, Infirmier de bloc, Paramédical), refus serveur sinon, pas de doublon (ADR-168)
- [ ] Attribuer le profil Chirurgien aux comptes SURGERY de chaque site depuis le portail — sans lui, aucune intervention ne se programme (ADR-168)
- [x] Programmation corrigeable tuile par tuile (crayon) : date, chirurgien principal, aides et opérateur ; au bloc avec motif obligatoire et audit, l'ancien principal restant aide s'il a opéré (ADR-168, amendements du 2026-09-22)
- [ ] À décider : refuser aussi un chirurgien sans fiche RH reliée, contrôle de conflit d'horaire, durée d'intervention (ADR-168)
- [ ] Pédiatrie · Index et Show à passer à shadcn
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
- [x] Consommables du bloc reliés au stock Pharmacie : produits de parapharmacie et matériel configuré pour l'acte, demande dans la file Pharmacie, sortie FEFO sans attendre le règlement, facturés en plus de l'intervention ; ligne « hors stock » conservée (ADR-169)
- [ ] Configurer le matériel habituel des actes de Chirurgie et le prix de vente des produits concernés — configuration de la clinique (ADR-169)
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
- [x] Visiteurs : entrée de menu dédiée (`visitors.view`) — la seule existante pointait vers cette page sous `guarding.view`, un droit que Réception n'a jamais reçu (ADR-116)
- [x] Gardiennage : poste de contrôle de sortie (`/guarding`), premier consommateur réel du catalogue `guarding.*` seedé depuis l'origine sans aucun écran (ADR-116)
- [x] Le gardien constate une sortie déjà prononcée par la Caisse, ne la décide jamais ; un seul contrôle par passage, aucun motif exigé
- [ ] Journal et rapports de gardiennage (`guarding.reports.*`) — catalogue seedé, écran non construit
- [x] Rapports RH
- [x] Espace RH : menu latéral en groupe, panneau « à traiter » sur la Vue d'ensemble, accueil et liste des employés refondus
- [x] Présences et congés : chevauchements refusés pour un même employé
- [x] Fiche Employé reliée au compte de connexion (un compte, une fiche) : son planning RH dit quand la personne est disponible, sans créer de compte ni donner de droit (ADR-168)

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
- [x] Les droits qui agissent dans plusieurs modules le disent dans leur libellé et leur catégorie : chercher « hospitalisation » trouve enfin `medical_discharge.create`, qui gouverne « Prononcer la sortie » d'un séjour (ADR-151)
- [x] Un 403 nomme le droit manquant et où l'accorder ; s'il s'agit d'un refus nominatif, il le dit et renvoie vers « Exceptions par compte » — vaut pour toutes les routes gardées par `can:` (ADR-154)
- [x] Chaque case du socle porte « Refusé à N comptes » quand des comptes du rôle la refusent individuellement : cocher un droit sans effet visible ne se lit plus comme un défaut (ADR-153, ADR-033)
- [x] L'éditeur de socle signale les comptes du rôle qui portent des exceptions individuelles : un socle à zéro ne se lit plus « personne n'y a accès » alors qu'un ALLOW nominatif l'emporte (ADR-150, ADR-033)
- [x] Rail des catégories de permissions refondu (shadcn) : hauteur qui suit l'écran, lignes plus grandes, domaines repliables, navigation au clavier, largeur 30 % par défaut (ADR-101, amendement du 2026-09-20)
- [x] Écran « Rôles & permissions » à quatre sections annoncées par portée (socle du rôle / exception d'un compte / rôles du site / catalogue), avec compteurs et phrase de portée avant le clic
- [x] Référentiel des rôles administrable depuis le portail (ADR-100) : créer, renommer, archiver avec motif (refusé si des comptes le portent) et restaurer, par site via l'API — le code d'un rôle reste son identité et ne change jamais
- [x] Écrans « Utilisateurs » et « Rôles & permissions » séparés (ADR-100) : les comptes d'un côté, le socle des rôles et les exceptions individuelles de l'autre, sans changer la résolution DENY > ALLOW > socle
- [x] Socle des rôles refondu (shadcn, ADR-099) : rail des rôles et des catégories sans pagination, recherche sur tout le catalogue, écart « accordées / retirées » relisible avant envoi, barre d'enregistrement collante et garde-fou sur le brouillon
- [x] Réinitialisation confirmée des droits (ADR-173) : rôle standard vers son socle livré, sans toucher aux exceptions ; compte vers l'héritage pur de son rôle, sans réappliquer silencieusement les recommandations du profil ; API et audit distincts
- [x] Fournisseurs pharmacie et catalogues gérés depuis le portail par API du site (ADR-098)
- [x] Import Excel des fournisseurs avec aperçu ligne par ligne puis écriture tout ou rien, export Excel par site ou tous sites
- [x] Correction, archivage avec motif (refusé si commande en cours) et restauration d'un fournisseur depuis le portail
- [x] Dossier fournisseur au portail identique à la clinique (catalogues, commandes, factures, produits et prix)
- [x] Commandes (créer, envoyer, annuler) et factures (enregistrer avec document, archiver, restaurer) depuis le portail ; réception réservée au site
- [x] Services, chambres et lits de chaque site : écran portail `/super-admin/hospital-beds`, par l'API du site, sans jamais afficher le nom d'un patient (ADR-164)
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
