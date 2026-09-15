<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Les bornes cliniques des constantes, à un seul endroit.
 *
 * Depuis l'ADR-093 deux chemins écrivent ces mêmes colonnes : la fiche Soins
 * (`UpdateCareRecordRequest`) et la correction Médecine
 * (`CorrectCareRecordVitalsRequest`). Recopier les règles aurait laissé les
 * deux diverger silencieusement — une température refusée d'un côté et
 * acceptée de l'autre pour la même mesure.
 *
 * Ce socle ne porte que les constantes (ADR-032, ADR-038 à ADR-041). Les
 * allergies, les actes, le matériel et la transmission restent propres à la
 * fiche Soins : Médecine ne les écrit jamais.
 */
class VitalSignRules
{
    /** Les seules colonnes que les deux chemins ont en commun. */
    public const FIELDS = [
        'blood_group',
        'blood_pressure_systolic', 'blood_pressure_diastolic',
        'heart_rate', 'spo2',
        'temperature_celsius', 'known_diabetes', 'diabetes_note',
        'height_cm', 'weight_kg', 'smoker', 'alcohol',
    ];

    /**
     * @param  bool  $knownDiabetes  la réponse « diabète connu » du formulaire :
     *                               la note n'a de sens que sous un Oui explicite.
     * @return array<string, array<int, mixed>>
     */
    public static function rules(bool $knownDiabetes): array
    {
        return [
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'blood_pressure_systolic' => [
                'nullable', 'integer', 'min:40', 'max:300',
                'required_with:blood_pressure_diastolic',
                'gt:blood_pressure_diastolic',
            ],
            'blood_pressure_diastolic' => [
                'nullable', 'integer', 'min:20', 'max:200',
                'required_with:blood_pressure_systolic',
                'lt:blood_pressure_systolic',
            ],
            'heart_rate' => ['nullable', 'integer', 'min:20', 'max:250'],
            'spo2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'temperature_celsius' => ['nullable', 'numeric', 'min:25', 'max:45', 'decimal:0,2'],
            'known_diabetes' => ['nullable', 'boolean'],
            'diabetes_note' => [
                Rule::prohibitedIf(! $knownDiabetes),
                'nullable', 'string', 'max:1000',
            ],
            'height_cm' => ['nullable', 'numeric', 'min:20', 'max:250', 'decimal:0,2'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:500', 'decimal:0,2'],
            'smoker' => ['nullable', 'boolean'],
            'alcohol' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'blood_pressure_systolic.required_with' => 'Renseignez la pression systolique.',
            'blood_pressure_diastolic.required_with' => 'Renseignez la pression diastolique.',
            'blood_pressure_systolic.gt' => 'La pression systolique doit être supérieure à la pression diastolique.',
            'blood_pressure_diastolic.lt' => 'La pression diastolique doit être inférieure à la pression systolique.',
            'temperature_celsius.min' => 'La température doit être comprise entre 25 et 45 °C.',
            'temperature_celsius.max' => 'La température doit être comprise entre 25 et 45 °C.',
            'blood_pressure_systolic.min' => 'La pression systolique doit être comprise entre 40 et 300 mmHg.',
            'blood_pressure_systolic.max' => 'La pression systolique doit être comprise entre 40 et 300 mmHg.',
            'blood_pressure_diastolic.min' => 'La pression diastolique doit être comprise entre 20 et 200 mmHg.',
            'blood_pressure_diastolic.max' => 'La pression diastolique doit être comprise entre 20 et 200 mmHg.',
            'heart_rate.min' => 'La fréquence cardiaque doit être comprise entre 20 et 250 btt/mn.',
            'heart_rate.max' => 'La fréquence cardiaque doit être comprise entre 20 et 250 btt/mn.',
            'spo2.min' => 'La SpO2 doit être comprise entre 0 et 100 %.',
            'spo2.max' => 'La SpO2 doit être comprise entre 0 et 100 %.',
            'height_cm.min' => 'La taille doit être comprise entre 20 et 250 cm.',
            'height_cm.max' => 'La taille doit être comprise entre 20 et 250 cm.',
            'weight_kg.min' => 'Le poids doit être compris entre 0,1 et 500 kg.',
            'weight_kg.max' => 'Le poids doit être compris entre 0,1 et 500 kg.',
        ];
    }

    /**
     * L'IMC est calculé par Laravel, jamais envoyé par le navigateur (ADR-032).
     *
     * Partagé pour la même raison que les bornes : la fiche Soins et la
     * correction Médecine écrivent la même colonne et doivent produire la
     * même valeur pour une taille et un poids donnés.
     */
    public static function bmi(int|float|string|null $height, int|float|string|null $weight): ?string
    {
        if ($height === null || $weight === null || (float) $height <= 0 || (float) $weight <= 0) {
            return null;
        }

        $heightInMeters = (float) $height / 100;

        return number_format((float) $weight / ($heightInMeters ** 2), 2, '.', '');
    }
}
