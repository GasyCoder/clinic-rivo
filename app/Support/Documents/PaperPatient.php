<?php

namespace App\Support\Documents;

use App\Models\Patient;

/**
 * ADR-116 — l'identité du patient telle que les fiches papier la montrent.
 *
 * Une seule projection pour toutes les feuilles de la clinique (dossier
 * médical, journal de traitement, fiche de sortie, lettre de référence) :
 * deux feuilles ne doivent jamais écrire le même patient de deux façons.
 * Une valeur absente reste absente — la case reste vide, jamais devinée.
 */
final class PaperPatient
{
    /** @return array<string, mixed> */
    public static function present(?Patient $patient): array
    {
        if ($patient === null) {
            return ['patient_number' => null, 'name' => null];
        }

        $address = collect([$patient->address, $patient->addressEntry?->label])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->unique()
            ->implode(', ');

        return [
            'patient_number' => $patient->patient_number,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'name' => trim(($patient->last_name ?? '').' '.($patient->first_name ?? '')),
            'birth_date' => $patient->birth_date?->toDateString(),
            'birth_date_is_approximate' => (bool) $patient->birth_date_is_approximate,
            'age' => $patient->birth_date?->age ?? $patient->declared_age,
            'birth_place' => $patient->birth_place,
            'sex' => $patient->sex?->value,
            'marital_status' => $patient->marital_status?->label(),
            'children_count' => $patient->children_count,
            'profession' => $patient->profession,
            'address' => $address === '' ? null : $address,
            'phone' => $patient->phone,
        ];
    }
}
