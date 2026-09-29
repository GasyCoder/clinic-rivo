# RIVO — Assistant IA d'aide au logiciel

Guide d'exploitation. La décision et ses règles sont dans `docs/DECISIONS.md`, ADR-222.

L'assistant répond aux questions sur **l'utilisation de RIVO** : où cliquer, quel écran ouvrir, quel droit
manque, pourquoi un bouton est grisé. Il ne donne aucun conseil médical, ne lit aucun dossier de patient et
ne modifie rien.

---

## 1. Architecture

```text
Navigateur (Vue)
  bouton « Assistant » + panneau latéral         resources/js/Components/Assistant/*
  flux SSE lu par fetch                          resources/js/composables/useAssistantChat.js
        │  POST /assistant/messages  (session RIVO, CSRF, droit ai_assistant.use)
        ▼
Laravel
  AssistantController → AssistantResponder       app/Http/Controllers/Assistant, app/Services/Assistant
    PromptRedactor        masque les identifiants de la question
    AssistantPageContext  rôle, site, page, droits — jamais le contenu de la page
    AssistantKnowledge    aide des modules ouverts au compte (resources/ai/clinic-assistant/*.md)
    AssistantConfiguration  fournisseur, modèle, clé (déchiffrée seulement ici)
        ▼
ClinicAssistant (agent du Laravel AI SDK) + 3 outils en lecture seule
        ▼
Fournisseur réglé : OpenAI, Anthropic, Gemini, OpenRouter, Mistral, DeepSeek, Groq, xAI
```

Le navigateur n'appelle jamais le fournisseur et ne reçoit jamais la clé.

Chaque base (chaque site, et le portail) a ses propres réglages, conversations et compteurs de consommation.
Le portail règle un site uniquement par l'API de ce site.

---

## 2. Mise en place

1. Jouer les migrations sur chaque site **et** sur le portail :

   ```bash
   php artisan migrate --force
   ```

   Elles créent `ai_assistant_settings`, `ai_assistant_usages`, `agent_conversations`,
   `agent_conversation_messages` et les droits `ai_assistant.use`, `ai_settings.view`, `ai_settings.update`.

2. Sur le portail : **Paramètres › Assistant IA**, choisir le site dans l'en-tête.
3. Choisir le **fournisseur**, puis le **modèle** (liste du SDK, ou saisie libre).
4. Coller la **clé d’API** du fournisseur (le lien « Créer une clé chez … » mène à sa console).
5. **Tester la connexion** : une question minuscule (16 tokens au plus) avec les valeurs du formulaire,
   sans rien enregistrer.
6. Cocher **Assistant activé**, régler les limites, **Enregistrer**.
7. Recommencer pour chaque site. Le bouton « Assistant » apparaît alors pour les comptes qui ont
   `ai_assistant.use`.

### Secours par le `.env`

Si rien n'est réglé en base, l'assistant lit le `.env` du déploiement :

```dotenv
RIVO_AI_ENABLED=true
RIVO_AI_PROVIDER=anthropic          # openai, anthropic, gemini, openrouter, mistral, deepseek, groq, xai
RIVO_AI_MODEL=                      # vide : modèle par défaut du SDK pour ce fournisseur
RIVO_AI_MAX_OUTPUT_TOKENS=800
RIVO_AI_TIMEOUT=30
RIVO_AI_RATE_LIMIT_PER_HOUR=20
ANTHROPIC_API_KEY=...               # la variable du SDK pour le fournisseur choisi
```

Ordre : réglage en base → `.env` → « non configuré ». Dès qu'une ligne existe en base, elle l'emporte.

---

## 3. La clé d'API

| Geste | Effet |
|---|---|
| Enregistrer avec le champ clé **vide** | la clé enregistrée est gardée |
| Enregistrer avec une **nouvelle** clé | elle remplace l'ancienne, chiffrée |
| Changer de fournisseur sans nouvelle clé | refusé : l'ancienne clé ne vaut pas pour le nouveau |
| **Retirer la clé** | geste à part, confirmé ; l'assistant retombe sur le `.env` ou devient non configuré |

La clé est chiffrée avec `APP_KEY` : **changer `APP_KEY` rend la clé illisible** — il faut alors la ressaisir.

Elle n'apparaît jamais : ni à l'écran (seulement `••••ABCD`), ni dans une réponse, ni dans l'audit
(« remplacée », « retirée »), ni dans les journaux, ni dans la session après un formulaire refusé.

---

## 4. Droits

| Droit | Donne | Par défaut |
|---|---|---|
| `ai_assistant.use` | le bouton et le panneau | rôles des sites (sauf SUPPORT, MAINTENANCE) ; Super Admin du portail |
| `ai_settings.view` | lire les réglages et la consommation | Super Admin du portail |
| `ai_settings.update` | régler, changer la clé, tester | Super Admin du portail |

Retirer `ai_assistant.use` à un rôle ou à un compte se fait dans « Rôles & permissions ».
L'assistant ne décrit que les modules que le compte peut ouvrir.

---

## 5. Données envoyées au fournisseur

- La question, **nettoyée** : emails, téléphones, numéros de dossier, de passage, de matricule, CIN et
  longues suites de chiffres sont remplacés (`[numéro de dossier]`…). Les **noms propres ne sont pas
  détectés** : l'écran demande de ne pas en écrire.
- Le rôle, le profil métier, le site, l'adresse de la page (identifiants remplacés par `{id}`), l'écran et
  la section ouverte, les droits du compte sur ce module.
- Les sections d'aide utiles, prises dans les seuls modules que le compte ouvre.
- Les 10 derniers messages de la conversation.

Jamais : le contenu d'une page, un nom de patient, un résultat, un diagnostic, une ordonnance.

---

## 6. Coûts et limites

| Réglage | Rôle |
|---|---|
| Longueur d'une réponse | 100 à 4 000 tokens |
| Questions par heure | par compte (limiteur `assistant-ai`) |
| Questions par jour | par compte, facultatif |
| Budget mensuel | tokens de toute la base, facultatif ; atteint, l'assistant se tait jusqu'au mois suivant |

Une question tient en 1 000 caractères. L'agent fait au plus 4 étapes d'outils. Aucun titre de conversation
n'est généré (pas d'appel en plus). La consommation (questions, tokens, comptes, échecs) se lit dans les
réglages ; RIVO ne calcule aucun montant : les prix sont ceux de la console du fournisseur.

---

## 7. Hébergement (o2switch)

- Aucun processus permanent, ni Redis, ni file d'attente, ni WebSocket : une requête PHP classique.
- La réponse arrive en **Server-Sent Events**. L'en-tête `X-Accel-Buffering: no` demande au proxy de ne pas
  la retenir. Si le proxy la retient quand même, la réponse s'affiche d'un bloc à la fin : c'est le seul effet.
- Le serveur doit pouvoir joindre l'API du fournisseur en HTTPS sortant.
- `max_execution_time` doit dépasser le délai réglé (30 s par défaut) ; le délai se règle de 5 à 120 s.

---

## 8. Mettre à jour l'aide

Les réponses viennent de `resources/ai/clinic-assistant/*.md` : une fiche par module, découpée en sections
`## `. Quand un workflow, un bouton ou un droit change, **la fiche change dans la même modification** —
sinon l'assistant décrit l'ancien écran.

Un nouveau module : une fiche, puis son entrée dans `AssistantKnowledge::MODULES` (adresses, droits qui
l'ouvrent, mots-clés, questions proposées). Le test `AssistantKnowledgeTest` vérifie que chaque module a sa
fiche et ses sections.

---

## 9. Dépannage

| Symptôme | Cause probable |
|---|---|
| Pas de bouton « Assistant » | assistant désactivé ou non configuré sur ce site, ou le compte n'a pas `ai_assistant.use` ; l'état est gardé 10 minutes en cache et oublié à chaque enregistrement |
| « La clé d'API du fournisseur est refusée » | clé erronée, révoquée, ou d'un autre fournisseur |
| « Le modèle réglé n'existe pas » | nom de modèle retiré ou mal saisi : en choisir un de la liste |
| « n'a plus de crédit » | compte du fournisseur à recharger |
| « ne répond pas (délai dépassé ou réseau) » | sortie réseau bloquée, fournisseur lent : allonger le délai |
| « Vous avez posé beaucoup de questions » | limite par heure atteinte |
| « limite de N questions par jour » / « budget mensuel » | quotas des réglages |
| Réponse d'un bloc au lieu du fil | le proxy retient le flux (voir § 7) |
| « Les réglages ne sont pas encore installés » | migrations non jouées sur cette base |
| Clé illisible après une restauration | `APP_KEY` changée : ressaisir la clé |

Les échecs sont journalisés par catégorie, code HTTP, fournisseur et modèle — jamais avec la clé ni le
message du fournisseur.

---

## 10. Tests

```bash
php artisan test tests/Feature/Assistant
node --test tests/JavaScript/assistant.test.js
```

Les tests utilisent les faux du SDK (`ClinicAssistant::fake()`, `AssistantConnectionCheck::fake()`) et
interdisent toute requête sortante : aucun ne consomme une vraie API.

---

## 11. Limites actuelles

- Aucune action : l'assistant explique, l'utilisateur agit.
- Un seul fournisseur par base, sans secours automatique.
- Recherche lexicale dans l'aide ; une recherche vectorielle ne se justifiera que si l'aide grandit
  beaucoup (`AssistantKnowledge::search()` est le seul point à remplacer).
- Pas de tableau de consommation consolidé de tous les sites.
