<?php

namespace App\Support;

use App\Enums\SurgicalChecklistPhase;

/**
 * ADR-170 — le contenu des trois temps de la checklist du bloc.
 *
 * **Ce sont des points de vérification à faire valider par la clinique**, pas
 * une règle médicale transcrite : le CDC §16 ne décrit aucune checklist et
 * aucun protocole n'a été transmis. Chaque item est donc une *vérification
 * organisationnelle* — identité, intervention, côté, matériel, personne
 * présente — jamais un seuil ni un jugement clinique (aucune valeur de
 * constante, aucun score, aucune contre-indication n'est inventée ici).
 *
 * Deux natures d'items, et la distinction est le cœur de la décision :
 *
 * ```text
 * required = true   sans lui, le temps ne peut pas être déclaré terminé
 * required = false  utile à cocher, jamais bloquant (ADR-170 : on ne bloque
 *                   pas un bloc opératoire pour un champ facultatif)
 * ```
 *
 * Les items obligatoires retenus sont volontairement les quatre ou cinq que
 * personne ne conteste (le bon patient, la bonne intervention, le bon côté,
 * l'anesthésiste présent). Élargir cette liste est une décision de la clinique,
 * qui se prend ici, dans un seul fichier relu — jamais dispersée dans les
 * écrans.
 */
final class SurgicalSafetyChecklistItems
{
    /**
     * @return array<int, array{key: string, label: string, required: bool, hint?: string}>
     */
    public static function for(SurgicalChecklistPhase $phase): array
    {
        return match ($phase) {
            SurgicalChecklistPhase::SignIn => [
                ['key' => 'patient_identity', 'label' => 'Identité du patient vérifiée avec lui ou sa famille', 'required' => true],
                ['key' => 'procedure_confirmed', 'label' => 'Intervention prévue confirmée', 'required' => true],
                ['key' => 'site_marked', 'label' => 'Côté / site vérifié, ou sans objet pour cette intervention', 'required' => true],
                ['key' => 'consent', 'label' => 'Document de consentement présent au dossier', 'required' => false],
                ['key' => 'anesthesia_equipment', 'label' => 'Matériel et médicaments d’anesthésie vérifiés', 'required' => true],
                ['key' => 'monitoring', 'label' => 'Monitorage en place et fonctionnel', 'required' => true],
                ['key' => 'allergy_known', 'label' => 'Statut allergique connu de l’équipe', 'required' => false, 'hint' => 'Les allergies du dossier restent consultables dans la synthèse des Soins.'],
                ['key' => 'airway_risk', 'label' => 'Risque d’intubation difficile évalué', 'required' => false],
                ['key' => 'bleeding_risk', 'label' => 'Risque hémorragique évalué', 'required' => false],
                ['key' => 'venous_access', 'label' => 'Voie veineuse posée', 'required' => false],
            ],
            SurgicalChecklistPhase::TimeOut => [
                ['key' => 'team_introduced', 'label' => 'Chaque membre de l’équipe s’est présenté par son nom et sa fonction', 'required' => false],
                ['key' => 'patient_procedure_site', 'label' => 'Patient, intervention et site confirmés à voix haute', 'required' => true],
                ['key' => 'surgeon_critical_steps', 'label' => 'Le chirurgien a annoncé les temps critiques et la durée prévue', 'required' => false],
                ['key' => 'anesthesia_concerns', 'label' => 'L’anesthésiste a annoncé les points de vigilance', 'required' => false],
                ['key' => 'nursing_sterility', 'label' => 'Stérilité et matériel confirmés par l’équipe de salle', 'required' => true],
                ['key' => 'imaging_available', 'label' => 'Imagerie nécessaire disponible, ou sans objet', 'required' => false],
            ],
            SurgicalChecklistPhase::SignOut => [
                ['key' => 'procedure_recorded', 'label' => 'Intervention réellement réalisée annoncée à l’équipe', 'required' => true],
                ['key' => 'counts_correct', 'label' => 'Comptage des compresses et instruments exact', 'required' => true],
                ['key' => 'specimen_labelled', 'label' => 'Prélèvements étiquetés, ou sans objet', 'required' => false],
                ['key' => 'equipment_issues', 'label' => 'Problèmes de matériel signalés, le cas échéant', 'required' => false],
                ['key' => 'recovery_plan', 'label' => 'Consignes de réveil et de surveillance transmises', 'required' => true],
            ],
        };
    }

    /** @return array<int, string> */
    public static function requiredKeys(SurgicalChecklistPhase $phase): array
    {
        return array_values(array_map(
            fn (array $item) => $item['key'],
            array_filter(self::for($phase), fn (array $item) => $item['required']),
        ));
    }

    /** @return array<int, string> */
    public static function keys(SurgicalChecklistPhase $phase): array
    {
        return array_column(self::for($phase), 'key');
    }

    /**
     * Les items d'un temps, avec ce qui est déjà coché — servi à l'écran pour
     * qu'il n'ait aucune liste à recopier.
     *
     * @param  array<string, bool>|null  $checked
     * @return array<int, array<string, mixed>>
     */
    public static function present(SurgicalChecklistPhase $phase, ?array $checked): array
    {
        return array_map(fn (array $item) => $item + [
            'checked' => (bool) ($checked[$item['key']] ?? false),
            'hint' => $item['hint'] ?? null,
        ], self::for($phase));
    }
}
