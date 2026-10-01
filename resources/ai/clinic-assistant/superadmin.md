# Portail Super Administration

Le portail (admin.rivo.mg) règle les trois sites à distance, **uniquement par leur API** : il n'ouvre jamais leur base. Un compte Super Administrateur ne se connecte qu'au portail ; pour travailler sur un site, il faut un compte local distinct.

## Paramètres

Menu **Paramètres** (`/super-admin/settings`) : choisir la cible (un site ou le portail), puis le module dans le menu de droite : Identité, Thème, Affichage avancé, Écrans & modèles, Numérotation, Âges des patients, Badge du personnel, Monnaie, Remises, Identité légale, Direction, Moteurs de recherche, Maintenance, **Assistant IA**. Enregistrer avec le bouton en pied de carte (ou Ctrl+S). Droits : `settings.view`, `settings.update`.

## Régler l'assistant IA

Paramètres › **Assistant IA**, pour chaque site :

1. Activer l'assistant.
2. Choisir le fournisseur — **GasyCoder AI** par défaut, ou OpenAI, Anthropic, Gemini, OpenRouter… — et le modèle (liste proposée, ou saisie libre). GasyCoder AI demande aussi son adresse d'API, réglée sur le serveur (`GASYCODER_AI_URL`) : l'écran dit si elle manque.
3. Coller la clé API : elle est chiffrée et ne s'affiche plus ensuite que masquée (••••1234). Enregistrer sans toucher à la clé la garde.
4. « Tester la connexion » fait un appel minimal au fournisseur.
5. Régler les limites : tokens de réponse, délai, questions par heure et par utilisateur, quota journalier, budget de tokens mensuel.

La consommation du jour et du mois s'affiche dans le même module. Droits : `ai_settings.view`, `ai_settings.update`.

## Utilisateurs et accès du personnel

- Menu **Utilisateurs** › Comptes : tous les comptes d'un site, rôle, désactivation, comptes externes.
- Menu Utilisateurs › **Accès du personnel** : créer d'un geste l'adresse professionnelle et le compte RIVO des nouveaux employés, puis envoyer l'annonce au RH du site.
- **Rôles & permissions** : socle de chaque rôle, exceptions par compte, rôles du site, catalogue des droits. Un brouillon s'enregistre d'un seul geste.

## Établissements

Menu **Établissements** › un site : Vue du site, Réception, Caisse, Patients, Médecine, Soins, Chirurgie, Rapports. Chaque page lit le rapport du site par son API, sur 7, 30 ou 90 jours (boutons en haut à droite), et « Actualiser » le relit. Si le site ne répond pas, la page le dit et n'affiche aucun chiffre ; une donnée que les droits masquent s'affiche « — » avec son motif, jamais 0. Ces pages se lisent seulement : l'encaissement reste à la Caisse du site.

## Espaces par module

Un module n'a qu'une seule entrée dans le menu. On choisit d'abord le site, puis on travaille dans ses vrais écrans :

- **Laboratoire** › Laboratoires des sites (`/super-admin/laboratory`) ;
- **Pharmacie & stocks** › Pharmacies des sites (`/super-admin/pharmacy`), Stock médicaments, Fournisseurs pharmacie ;
- **Organisation** › Ressources humaines, Emails professionnels, Messagerie, Logistique & équipements, Gardiennage ;
- **Référentiels** › Tarifs & mutuelles, Modèles de documents, Catalogue des analyses, Adresses & localités, Services, chambres & lits, Patients VIP, Partenaires.

Les gestes physiques (délivrer, réceptionner, inventaire, saisir ou valider un résultat d'analyse) restent réservés au site : le portail les montre verrouillés.

## Autres espaces

Finances (Caisses des sites, Modes de paiement, Rapports financiers, Dettes du personnel), Corbeille multi-sites, Audit & APIs, Maintenance d'un site (Paramètres › Maintenance).

## Modèles de documents

Référentiels › **Modèles de documents** (`/super-admin/workspaces/document-templates`, droit `document_templates.view`) : les modèles (contrat, congé, attestation…) que le RH de chaque site utilise dans « Générer un document ». Choisir le site, puis un dossier à gauche ; « Liste » ou « Grille » ; filtres En service, Proposés au RH, Inactifs, À vérifier, Archivés. Cliquer un modèle ouvre sa fiche avec l'aperçu du texte. « Nouveau modèle » : 1 · Dossier, 2 · Données reprises (la page 1 que le RH verra), 3 · Nom et « Proposé au RH », 4 · Pages, puis le texte (import Word ou PDF possible) et « Créer le modèle ». Enregistrer un modèle déjà utilisé crée une nouvelle version ; « Versions » permet d'y revenir. Archiver demande un motif.
