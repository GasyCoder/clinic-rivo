# Anesthésie — évaluation, décision et conduite

Menu Soins › **Anesthésie** (adresse `/anesthesia`). Droits : `anesthesia.view` pour voir, `anesthesia.create` / `anesthesia.update` / `anesthesia.validate` pour écrire et valider. Ces droits se donnent aux comptes des anesthésistes, pas à toutes les infirmières.

## La file Anesthésie

Quatre vues : **À évaluer**, **Transmis à Chirurgie**, **Au bloc**, **Terminés**. Chaque ligne est une demande du bloc : l'anesthésie et la chirurgie travaillent sur le même dossier.

## Les étapes du dossier d'anesthésie

1. **Consultation pré-anesthésique** — antécédents, examen, en sections repliables qui s'enregistrent toutes seules. « Valider l'évaluation » la verrouille.
2. **Examen paraclinique** — résultats utiles à l'anesthésie.
3. **Décision** — l'autorisation anesthésique : « Autorisé », « Autorisé sous conditions », « Non autorisé » ou « Reporté ». Un refus ou un report exige un motif, que le bloc lit. Les conditions se lèvent ici, par l'anesthésie seule.
4. **Conduite anesthésique** — pendant l'intervention ; « Valider le dossier » ferme la fiche, seulement après l'incision.

Les constantes, allergies et actes des Soins sont lus sans ressaisie.

## Ce que la décision change pour le bloc

Tant qu'aucune décision n'est prononcée, ou si elle est « Non autorisé » / « Reporté », le bloc ne peut pas démarrer l'intervention. « Autorisé sous conditions » bloque tant qu'une condition reste ouverte. La décision se révise tant que l'intervention n'a pas commencé.

## Checklist de sécurité

L'anesthésie confirme **sa** partie des temps SIGN IN, TIME OUT et SIGN OUT de la checklist du bloc. Un chirurgien ne signe pas pour l'anesthésiste.

## Pourquoi je ne peux pas…

- Ouvrir le dossier d'un patient : droit `anesthesia.view` manquant, ou vous n'êtes pas l'anesthésiste affecté (sauf supervision).
- Valider le dossier : l'intervention n'a pas encore commencé.
- Modifier la décision : l'intervention a déjà commencé.
