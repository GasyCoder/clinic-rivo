<?php

namespace App\Support\Reception;

use App\Models\Patient;

/**
 * Un patient tel que l'écran d'arrivée de la Réception le sélectionne.
 *
 * Une seule forme, que la recherche patient et le choix d'un nouveau-né dans l'arborescence de sa mère
 * (ADR-146) servent l'une comme l'autre : l'écran ne distingue pas d'où vient le patient qu'il reçoit.
 */
final class PatientSearchPayload
{
    /** @return array<string, mixed> */
    public static function make(Patient $patient): array
    {
        return [
            'uuid' => $patient->uuid,
            'patient_number' => $patient->patient_number,
            'patient_type' => $patient->patient_type->value,
            'civility' => $patient->civility?->value,
            'civility_label' => $patient->civility?->label(),
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'birth_date' => $patient->birth_date?->toDateString(),
            'birth_date_is_approximate' => $patient->birth_date_is_approximate,
            'declared_age' => $patient->declared_age,
            'age' => $patient->birth_date?->age ?? $patient->declared_age,
            'sex' => $patient->sex->value,
            'phone' => $patient->phone,
            'email' => $patient->email,
        ];
    }
}
