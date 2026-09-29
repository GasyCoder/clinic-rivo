# Portail Super Administration

Le portail (admin.rivo.mg) règle les trois sites à distance, **uniquement par leur API** : il n'ouvre jamais leur base. Un compte Super Administrateur ne se connecte qu'au portail ; pour travailler sur un site, il faut un compte local distinct.

## Paramètres

Menu **Paramètres** (`/super-admin/settings`) : choisir la cible (un site ou le portail), puis le module dans le menu de droite : Identité, Thème, Affichage avancé, Écrans & modèles, Numérotation, Âges des patients, Badge du personnel, Monnaie, Remises, Identité légale, Direction, Moteurs de recherche, Maintenance, **Assistant IA**. Enregistrer avec le bouton en pied de carte (ou Ctrl+S). Droits : `settings.view`, `settings.update`.

## Régler l'assistant IA

Paramètres › **Assistant IA**, pour chaque site :

1. Activer l'assistant.
2. Choisir le fournisseur (OpenAI, Anthropic, Gemini, OpenRouter…) et le modèle (liste proposée, ou saisie libre).
3. Coller la clé API : elle est chiffrée et ne s'affiche plus ensuite que masquée (••••1234). Enregistrer sans toucher à la clé la garde.
4. « Tester la connexion » fait un appel minimal au fournisseur.
5. Régler les limites : tokens de réponse, délai, questions par heure et par utilisateur, quota journalier, budget de tokens mensuel.

La consommation du jour et du mois s'affiche dans le même module. Droits : `ai_settings.view`, `ai_settings.update`.

## Utilisateurs et accès du personnel

- Menu **Utilisateurs** › Comptes : tous les comptes d'un site, rôle, désactivation, comptes externes.
- Menu Utilisateurs › **Accès du personnel** : créer d'un geste l'adresse professionnelle et le compte RIVO des nouveaux employés, puis envoyer l'annonce au RH du site.
- **Rôles & permissions** : socle de chaque rôle, exceptions par compte, rôles du site, catalogue des droits. Un brouillon s'enregistre d'un seul geste.

## Autres espaces

Établissements (RH et Pharmacie d'un site vus depuis le portail), Tarifs & mutuelles, Stock médicaments, Fournisseurs pharmacie, Services, chambres et lits, Patients VIP, Emails professionnels, Messagerie, Corbeille multi-sites, Maintenance d'un site. Les gestes physiques (délivrer, réceptionner, inventaire) restent réservés au site.
