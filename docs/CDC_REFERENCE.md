# RIVO — Référence du Cahier des Charges

## Objectif

Ce fichier indique aux développeurs humains et aux agents IA où trouver le Cahier des Charges officiel du projet RIVO / Clinique Saint Georges.

Le CDC n'est pas dupliqué dans ce repository applicatif.

Il est maintenu séparément afin d'éviter plusieurs versions contradictoires du même document.

---

# Repository officiel du CDC

```text
https://github.com/GasyCoder/cdc-clinic-george
```

Repository GitHub :

```text
GasyCoder/cdc-clinic-george
```

---

# Règle obligatoire pour les agents IA

Avant toute modification concernant la logique métier, consulter le CDC.

Cela concerne notamment :

- patients ;
- épisodes de soins ;
- réception ;
- caisse ;
- facturation ;
- paiements ;
- médecine ;
- soins ;
- laboratoire ;
- pharmacie ;
- stocks ;
- chirurgie ;
- hospitalisation ;
- transferts ;
- utilisateurs ;
- rôles ;
- permissions ;
- administration ;
- RH ;
- API ;
- Super Administration ;
- rapports ;
- audit.

---

# Ordre de lecture obligatoire

Avant d'implémenter une fonctionnalité métier :

```text
1. docs/DECISIONS.md
        ↓
2. CDC GitHub
        ↓
3. docs/AI_CONTEXT.md
        ↓
4. Code existant
```

CDC :

```text
https://github.com/GasyCoder/cdc-clinic-george
```

---

# Pourquoi DECISIONS.md est lu avant le CDC

Le repository CDC contient l'historique du Cahier des Charges.

Certaines décisions techniques peuvent avoir été validées après une version précédente du CDC.

Ces décisions récentes sont consignées dans :

```text
docs/DECISIONS.md
```

Si le CDC et `DECISIONS.md` sont différents :

```text
DECISIONS.md
```

représente la décision technique actuellement validée.

L'agent ne doit cependant jamais masquer le conflit.

Il doit signaler :

```text
- règle présente dans le CDC ;
- décision présente dans DECISIONS.md ;
- impact sur l'implémentation.
```

---

# Accès au CDC

Si l'agent dispose d'un accès GitHub ou Internet :

1. ouvrir le repository ;
2. lire le README et les documents pertinents ;
3. rechercher la section concernant le module demandé ;
4. vérifier les règles métier avant de coder.

Repository :

```text
https://github.com/GasyCoder/cdc-clinic-george
```

---

# Si le CDC n'est pas accessible

Si l'environnement de l'agent ne permet pas d'accéder au repository :

NE PAS inventer les règles métier.

L'agent doit indiquer clairement :

```text
Le CDC externe n'est pas accessible depuis mon environnement.
```

Puis il doit :

1. utiliser uniquement les règles présentes dans `docs/DECISIONS.md` et `docs/AI_CONTEXT.md` ;
2. signaler les informations manquantes ;
3. demander le contenu nécessaire avant toute décision métier incertaine.

---

# Interdiction

Ne jamais :

```text
- remplacer silencieusement une règle du CDC ;
- inventer une règle métier ;
- modifier l'architecture validée sans signalement ;
- considérer le code existant comme plus fiable que la documentation ;
- créer une deuxième source de vérité du CDC.
```

---

# Source officielle

```text
CDC_TECHNIQUE_URL=https://github.com/GasyCoder/cdc-clinic-george
```
