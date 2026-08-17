# CAHIER DES CHARGES TECHNIQUE

## RIVO — Digitalisation de la Clinique Saint Georges

**Version : 3.0**

**Statut : Document officiel de référence pour le développement**

## Sites

- Clinique Saint Georges Mampikony
- Clinique Saint Georges Ambondromamy

## Domaines

- `cliniquesaintgeorges.mg`
- `clinique-m.rivo.mg`
- `clinique-a.rivo.mg`
- `admin.rivo.mg`

## Stack

- Laravel 13
- Vue.js
- Inertia.js
- Tailwind CSS
- DashWind
- MySQL / MariaDB
- REST API

---

# 1. Architecture générale

Les deux sites de la Clinique Saint Georges sont considérés comme indépendants.

Chaque site possède sa propre base de données.

```text
MAMPIKONY
clinique-m.rivo.mg
        |
        v
Laravel
        |
        v
DB_MAMPIKONY


AMBONDROMAMY
clinique-a.rivo.mg
        |
        v
Laravel
        |
        v
DB_AMBONDROMAMY
